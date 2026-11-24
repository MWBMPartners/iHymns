/**
 * tests/test-web-signin-platform.js - every browser sign-in says "I am the web" (PR 3a security review, finding 1)
 * ===============================================================================================================
 *
 * ELI5
 * ----
 * The server hands a device its OWN sign-in only to an app that holds the shared older-app sign-in. A browser's
 * sign-in must never pass for that. The server tells them apart by the `platform` word stored with the sign-in, so
 * every place the website signs someone in has to send `platform: 'web'`. If one forgets, that browser's sign-in would
 * be stored with no platform, which is exactly what the shared older-app sign-in looks like. This guard finds every
 * such place from the tree and fails if one leaves the word out.
 *
 * Detailed (rule #34: derived from the tree, then proven able to fail; rule #35: not a comment asking for agreement)
 *  1. The sign-in actions are FOUND in api.php: every `case 'auth_...':` (only auth_ names: the last case in the
 *     switch otherwise reads on into helper functions) whose body makes a sign-in
 *     (`apiTokenMint(` or `completeEmailLogin(`). The TV pairing poll is excluded by name: it is a native TV, not a
 *     browser, so it never sends `web`.
 *  2. Every call of one of those actions anywhere under js/ (comments removed) is located. The function it sits in is
 *     read, and it must send `platform: 'web'` or spread `WEB_DEVICE_DETAILS`.
 *  3. A floor: login, register, the emailed link and code (three calls) and Apple-on-web must all be found, so a scanner
 *     that quietly finds nothing cannot pass.
 *  4. The constant itself still says 'web', and the server still accepts it as a platform word.
 *  MUTATIONS (recorded in the fix commit): delete the spread from login(), from the emailed-code call, or from the
 *  router's link handler -> red; empty WEB_DEVICE_DETAILS -> red.
 *
 * Usage: node tests/test-web-signin-platform.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUB = path.join(__dirname, '..', 'appWeb', 'public_html');
let failures = 0;
const check = (label, cond, detail = '') => {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + label + (cond || !detail ? '' : ' - ' + detail));
    if (!cond) failures++;
};

/** Remove comments so a sentence that merely mentions an action name is not a call. Keeps string contents. */
function stripComments(src) {
    let out = '';
    let i = 0;
    let quote = null;
    while (i < src.length) {
        const c = src[i];
        const n = src[i + 1];
        if (quote) {
            out += c;
            if (c === '\\') { out += n ?? ''; i += 2; continue; }
            if (c === quote) quote = null;
            i++;
            continue;
        }
        if (c === '/' && n === '/') { while (i < src.length && src[i] !== '\n') i++; continue; }
        if (c === '/' && n === '*') { i += 2; while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) { if (src[i] === '\n') out += '\n'; i++; } i += 2; continue; }
        if (c === '"' || c === "'" || c === '`') quote = c;
        out += c;
        i++;
    }
    return out;
}

function jsFiles(dir) {
    const out = [];
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) { if (e.name !== 'vendor') out.push(...jsFiles(p)); }
        else if (e.name.endsWith('.js')) out.push(p);
    }
    return out;
}

/* 1. the sign-in actions, found in api.php */
const api = fs.readFileSync(path.join(PUB, 'api.php'), 'utf8');
const caseRe = /\n\s*case '([a-z0-9_]+)':/g;
const marks = [];
let m;
while ((m = caseRe.exec(api)) !== null) marks.push({ name: m[1], at: m.index });
const mintingActions = new Set();
for (let i = 0; i < marks.length; i++) {
    const body = api.slice(marks[i].at, i + 1 < marks.length ? marks[i + 1].at : api.length);
    if (/^auth_/.test(marks[i].name) && /apiTokenMint\(|completeEmailLogin\(/.test(body)) mintingActions.add(marks[i].name);
}
const NOT_A_BROWSER = new Set(['auth_device_code_poll', 'auth_device_enrol', 'auth_enrol_key_redeem']);
const browserActions = [...mintingActions].filter((a) => !NOT_A_BROWSER.has(a));
console.log('1. sign-in actions found in api.php: ' + [...mintingActions].sort().join(', '));
for (const need of ['auth_login', 'auth_register', 'auth_apple', 'auth_email_login_verify']) {
    check('api.php: ' + need + ' is found as an action that makes a sign-in', mintingActions.has(need));
}

/* 2. every call from the website */
console.log('2. every browser call to those actions sends platform "web"');
const callRe = new RegExp('action=(' + browserActions.join('|') + ')\\b', 'g');
let found = 0;
const foundBy = {};
for (const file of jsFiles(path.join(PUB, 'js'))) {
    const src = stripComments(fs.readFileSync(file, 'utf8'));
    let c;
    callRe.lastIndex = 0;
    while ((c = callRe.exec(src)) !== null) {
        found++;
        foundBy[c[1]] = (foundBy[c[1]] || 0) + 1;
        /* the function this call sits in: from the nearest line that opens one, to a little past the call */
        const before = src.slice(0, c.index);
        const opens = [...before.matchAll(/\n[ \t]*(?:export\s+)?(?:async\s+)?(?:function\s+)?(?!if\b|for\b|while\b|switch\b|catch\b|else\b)[_A-Za-z$][\w$]*\s*\([^)\n]*\)\s*\{[ \t]*(?=\n)/g)];
        const start = opens.length ? opens[opens.length - 1].index : Math.max(0, c.index - 2500);
        const slice = src.slice(start, c.index + 900);
        const ok = /\.\.\.WEB_DEVICE_DETAILS/.test(slice) || /platform:\s*'web'/.test(slice);
        const line = before.split('\n').length;
        check(path.relative(PUB, file) + ':' + line + ' (' + c[1] + ') sends platform web', ok);
    }
}

/* 3. floor */
check('floor: login and register are each called from the website', foundBy.auth_login >= 1 && foundBy.auth_register >= 1, JSON.stringify(foundBy));
check('floor: the emailed link and code are called from three places', (foundBy.auth_email_login_verify || 0) >= 3, JSON.stringify(foundBy));
check('floor: Apple on the web is called', (foundBy.auth_apple || 0) >= 1, JSON.stringify(foundBy));

/* 4. the constant and the server's accepted words */
const constants = fs.readFileSync(path.join(PUB, 'js', 'constants.js'), 'utf8');
check("WEB_DEVICE_DETAILS is exactly { platform: 'web' }", /WEB_DEVICE_DETAILS\s*=\s*Object\.freeze\(\{\s*platform:\s*'web'\s*\}\)/.test(constants));
check("the server accepts 'web' as a platform word", /API_TOKEN_DEVICE_PLATFORMS\s*=\s*\[[^\]]*'web'/.test(fs.readFileSync(path.join(PUB, 'includes', 'api_tokens.php'), 'utf8')));

console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
process.exit(failures === 0 ? 0 : 1);
