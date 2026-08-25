/**
 * tests/test-captcha-client.js — CAPTCHA client-wiring guard (#947 / #340)
 *
 * PURPOSE
 * ELI5: makes sure the browser side of the human-check is wired the ONE safe
 * way — the token key matches the server's, only the shared module ever loads a
 * provider or knows its site key, and every form that handles a "please solve
 * the challenge" reply decides by the STATUS CODE + machine reason, never by
 * reading the server's English sentence.
 *
 * DETAIL — asserts (tree-derived + mutation-proven, rule #34/#35):
 *   1. PHP<->JS body-key lockstep: IHYMNS_CAPTCHA_BODY_KEY in
 *      includes/captcha.php === CAPTCHA_BODY_KEY in js/modules/captcha-widget.js.
 *      (Mutation: rename the JS key -> red.)
 *   2. captcha-widget.js is the SOLE owner of provider detail: it is the only
 *      JS file that reads the server-fed provider fields (scriptUrl /
 *      renderGlobal / siteKey) and injects the provider <script>; and it hard-
 *      codes NO provider hostname or site key (everything arrives from the
 *      server emit — rule #35: the response is the contract).
 *   3. Every consumer (derived from the tree: files importing
 *      captcha-widget.js) branches on isCaptchaRefusal(status,data) — never a
 *      regex / .includes() on the server's error prose — and names no provider
 *      hostname / site key.
 *   4. The widget-failure HINT (the provider-outage fallback) is wired the one
 *      safe way: sent through the shared apiFetch (rule #31, never bare fetch),
 *      fired from the module's failure paths, and — the load-bearing part —
 *      DECISION-INERT on the client too. Nothing awaits it, nothing reads its
 *      body, and no mount/allow path branches on it. A client that could act on
 *      the reply would be one refactor away from a client that decides whether
 *      a challenge is required, which is the universal bypass the server-side
 *      design exists to make impossible. captcha-widget.js must also be the
 *      ONLY js file that knows this action exists (tree-derived).
 *
 *   node tests/test-captcha-client.js
 *
 * Exit 0 = wired correctly, 1 = drift.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.join(__dirname, '..');
const PUB = path.join(REPO, 'appWeb', 'public_html');
const MODULE_REL = 'js/modules/captcha-widget.js';

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  ✓ ' + name); return; }
    failures++;
    console.error('  ✗ ' + name + (detail ? '\n      ' + detail : ''));
}
const read = (p) => (fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '');

/** Strip // line and /* *\/ block comments from JS so a comment can't satisfy
 *  or trip a source assertion. */
function stripJsComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');   // keep "://" in URLs intact
}

/** Recursively list *.js under a dir. */
function listJs(dir) {
    const out = [];
    if (!fs.existsSync(dir)) return out;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) out.push(...listJs(p));
        else if (e.isFile() && e.name.endsWith('.js')) out.push(p);
    }
    return out;
}

/* ---- 1. PHP <-> JS body-key lockstep ---------------------------------- */
const phpSrc = read(path.join(PUB, 'includes/captcha.php'));
const jsSrc  = read(path.join(PUB, MODULE_REL));

const phpKeyM = phpSrc.match(/const\s+IHYMNS_CAPTCHA_BODY_KEY\s*=\s*'([^']+)'/);
const jsKeyM  = jsSrc.match(/export\s+const\s+CAPTCHA_BODY_KEY\s*=\s*'([^']+)'/);
check('1.1 includes/captcha.php defines IHYMNS_CAPTCHA_BODY_KEY', !!phpKeyM);
check('1.2 captcha-widget.js defines CAPTCHA_BODY_KEY', !!jsKeyM);
check('1.3 the PHP and JS body keys are identical (lockstep)',
    !!phpKeyM && !!jsKeyM && phpKeyM[1] === jsKeyM[1],
    phpKeyM && jsKeyM ? `php=${phpKeyM[1]} js=${jsKeyM[1]}` : 'one side missing');

/* Also lockstep the reason string (both branch on it). */
const phpReasonM = phpSrc.match(/const\s+IHYMNS_CAPTCHA_REASON\s*=\s*'([^']+)'/);
const jsReasonM  = jsSrc.match(/export\s+const\s+CAPTCHA_REASON\s*=\s*'([^']+)'/);
check('1.4 the PHP and JS refusal reason are identical (lockstep)',
    !!phpReasonM && !!jsReasonM && phpReasonM[1] === jsReasonM[1],
    phpReasonM && jsReasonM ? `php=${phpReasonM[1]} js=${jsReasonM[1]}` : 'one side missing');

/* ---- 2. captcha-widget.js is the sole owner of provider detail -------- */
const jsNoComments = stripJsComments(jsSrc);
/* No provider hostname or an obvious hardcoded site key in the module — it
   reads scriptUrl / siteKey / renderGlobal from the server emit. */
const providerHosts = /challenges\.cloudflare\.com|hcaptcha\.com|recaptcha\/api|www\.gstatic\.com/;
check('2.1 captcha-widget.js hardcodes no provider hostname',
    !providerHosts.test(jsNoComments));
check('2.2 captcha-widget.js reads the provider script URL from config (_config.scriptUrl)',
    jsNoComments.includes('_config.scriptUrl'));
check('2.3 captcha-widget.js injects the provider <script> (the one loader)',
    /createElement\(\s*['"]script['"]\s*\)/.test(jsNoComments));

/* No OTHER js file may read the server-fed provider fields — those live only in
   the module. Consumers reference form KEYS + the module's exports, nothing
   more. */
const allJs = listJs(path.join(PUB, 'js'));
const providerFieldLeaks = [];
for (const f of allJs) {
    const rel = path.relative(PUB, f);
    if (rel === MODULE_REL) continue;
    const s = stripJsComments(read(f));
    if (/\.scriptUrl\b|\.renderGlobal\b|\.siteKey\b/.test(s)) providerFieldLeaks.push(rel);
}
check('2.4 no JS file outside captcha-widget.js reads the provider config fields',
    providerFieldLeaks.length === 0, providerFieldLeaks.join(', '));

/* ---- 3. Consumers branch on status+reason, never prose ---------------- */
const consumers = allJs.filter((f) => {
    const rel = path.relative(PUB, f);
    if (rel === MODULE_REL) return false;
    return /from\s+['"][^'"]*captcha-widget\.js['"]/.test(read(f));
});
check('3.1 at least one consumer imports captcha-widget.js', consumers.length >= 1,
    `found ${consumers.length}`);

const proseMatchers = [
    /['"`][^'"`]*verification challenge[^'"`]*['"`]\s*\)?\s*\.(includes|match|indexOf|test)/i,
    /\.(includes|indexOf|match)\(\s*['"`][^'"`]*captcha[^'"`]*['"`]/i,
];
for (const f of consumers) {
    const rel = path.relative(PUB, f);
    const s = stripJsComments(read(f));
    /* If it references a captcha refusal at all, it must go through the shared
       helper (status + machine reason), not the prose. */
    const usesHelper = s.includes('isCaptchaRefusal(');
    const inlineStatusReason = /status\s*===?\s*403[\s\S]{0,80}reason/.test(s);
    check(`3.2 [${rel}] refusal detection uses isCaptchaRefusal(), not an inline status+reason copy`,
        !inlineStatusReason || usesHelper,
        'inline `status===403 && ...reason` found without the shared helper');
    const prose = proseMatchers.some((re) => re.test(s));
    check(`3.3 [${rel}] does not prose-match the server captcha error`, !prose);
    check(`3.4 [${rel}] names no provider hostname`, !providerHosts.test(s));
}

/* ---- 4. The outage widget-failure hint is wired, and inert ------------- */
/* The action name is DERIVED from the PHP constant, so renaming it on either
   side fails here rather than silently sending hints nothing handles (rule #35:
   two files that must agree need a mechanism, not a comment). */
const phpHintM = phpSrc.match(/const\s+CAPTCHA_RATE_ACTION_HINT\s*=\s*'([^']+)'/);
check('4.1 includes/captcha.php declares CAPTCHA_RATE_ACTION_HINT', !!phpHintM);
const HINT_ACTION = phpHintM ? phpHintM[1] : 'captcha_widget_health';

/* The server case must exist for the action the client sends. */
const apiSrc = read(path.join(PUB, 'api.php'));
check('4.2 api.php handles the exact hint action the client sends',
    apiSrc.includes(`case '${HINT_ACTION}':`), `looked for case '${HINT_ACTION}':`);

/* ⚠️ BOUNDED, not a substring test. The first draft used
   `.includes('action=' + HINT_ACTION)` and stayed GREEN while the client sent
   `action=captcha_widget_healthz` — because the correct name is a PREFIX of the
   typo'd one, so the drift this assertion exists to catch satisfied it. Caught
   only by mutation-testing it (rule #34: a scanner that under-reports is worse
   than none, because its tick is read as coverage). The trailing boundary is
   what makes it real. */
const hintActionRe = new RegExp(`action=${HINT_ACTION}(?![A-Za-z0-9_-])`);
check('4.3 captcha-widget.js sends the hint to that exact action (bounded match)',
    hintActionRe.test(jsNoComments), `looked for action=${HINT_ACTION} with a word boundary`);
check('4.4 the hint goes through the shared apiFetch, not bare fetch (rule #31)',
    /import\s*\{[^}]*\bapiFetch\b[^}]*\}\s*from\s*['"][^'"]*api-client\.js['"]/.test(jsNoComments)
    && /apiFetch\(\s*['"`][^'"`]*action=/.test(jsNoComments));
check('4.5 captcha-widget.js does not call bare fetch( for the hint',
    !/(^|[^.\w])fetch\s*\(/.test(jsNoComments.replace(/apiFetch\s*\(/g, 'X(')));

/* DECISION-INERTNESS on the client. The hint call expression must not be
   awaited and must not have a .then() that could feed a value back into mount
   or submit logic. Scoped narrowly to the call expression itself — rule #34
   warns that a guard blunt enough to flag correct code gets weakened or
   deleted, and `.catch(` on the same expression is not only allowed but
   required (an unhandled rejection would surface as a console error on the very
   path the user is already having trouble with). */
const hintCallM = jsNoComments.match(/(await\s+)?apiFetch\(\s*['"`][^'"`]*action=[^'"`]*['"`][\s\S]{0,400}?\)\s*(\.[a-zA-Z]+\([^)]*\)\s*)*;/);
check('4.6 the hint call expression is locatable for the inertness scan', !!hintCallM);
if (hintCallM) {
    const expr = hintCallM[0];
    check('4.7 the hint is never awaited (it must not delay or block the form)',
        !/\bawait\b/.test(expr), expr.slice(0, 120));
    check('4.8 the hint reply is never read (.then/.json are absent — fire and forget)',
        !/\.then\s*\(/.test(expr) && !/\.json\s*\(/.test(expr), expr.slice(0, 120));
}
/* No assignment of the hint's result to anything — a stored promise/response is
   the first step towards branching on it. */
check('4.9 the hint result is not assigned to a variable',
    !/(?:const|let|var)\s+\w+\s*=\s*(?:await\s+)?apiFetch\(\s*['"`][^'"`]*action=/.test(jsNoComments)
    && !/return\s+(?:await\s+)?apiFetch\(\s*['"`][^'"`]*action=/.test(jsNoComments));

/* Tree-derived: captcha-widget.js is the ONLY js file that knows this action
   exists. A second sender would be a second, unreviewed telemetry path. */
const hintActionAnywhereRe = new RegExp(`${HINT_ACTION}(?![A-Za-z0-9_-])`);
const hintSenders = allJs.filter((f) => hintActionAnywhereRe.test(stripJsComments(read(f))));
const hintSenderRels = hintSenders.map((f) => path.relative(PUB, f));
check('4.10 captcha-widget.js is the ONLY js file naming the hint action',
    hintSenderRels.length === 1 && hintSenderRels[0] === MODULE_REL,
    hintSenderRels.join(', '));

/* The hint must fire from the module's FAILURE paths, not from a success path
   (which would make it constant background noise and drown the real signal).
   Derived structurally: the reporter is called only inside catch blocks or
   immediately before a `return null` bail-out in mountCaptcha. */
const mountM = jsNoComments.match(/export\s+async\s+function\s+mountCaptcha\s*\([\s\S]*?\n\}/);
check('4.11 mountCaptcha body is locatable', !!mountM);
if (mountM) {
    const mount = mountM[0];
    const reporterName = (jsNoComments.match(/function\s+(_report\w+)\s*\(/) || [])[1];
    check('4.12 a dedicated failure-reporter helper exists (one place sends the hint)', !!reporterName);
    if (reporterName) {
        const calls = mount.split(`${reporterName}(`).length - 1;
        check('4.13 mountCaptcha reports from every failure path (script load / missing global / render throw)',
            calls === 3, `found ${calls} call(s), expected 3`);
        /* Each call must be followed by a bail-out — never by continuing to
           treat the widget as mounted. */
        const followedByBail = mount
            .split(`${reporterName}(`)
            .slice(1)
            .every((seg) => /^[^;]*;\s*(?:hostEl\.innerHTML\s*=\s*'';\s*)?return null;/.test(seg));
        check('4.14 every hint call is immediately followed by returning null (no token, server decides)',
            followedByBail);
    }
}

/* ---------------------------------------------------------------------- */
console.log(failures === 0
    ? '\nAll CAPTCHA client-wiring assertions passed.'
    : `\n${failures} assertion(s) failed.`);
assert.equal(failures, 0);
