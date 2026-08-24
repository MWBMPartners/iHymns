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

/* ---------------------------------------------------------------------- */
console.log(failures === 0
    ? '\nAll CAPTCHA client-wiring assertions passed.'
    : `\n${failures} assertion(s) failed.`);
assert.equal(failures, 0);
