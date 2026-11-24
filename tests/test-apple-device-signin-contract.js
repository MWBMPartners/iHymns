/**
 * tests/test-apple-device-signin-contract.js - the Apple app's device sign-in calls match what the server really
 * offers and reads (account rewrite PR 3b, step I2b, epic #2180)
 *
 * ELI5
 * ----
 * The app asks the server five new things (swap the old shared sign-in, make a key, use a key, turn keys off, sign
 * out everywhere) and adds device details to ordinary sign-ins. If the app and the server disagree on a name, an
 * action, or what a refusal means, nothing crashes: the swap just never happens and the person stays on the old
 * shared sign-in. This guard compares the two sides from the real source, so a rename on either side turns the
 * build red instead of quietly turning the feature off (rule #35).
 *
 * Detailed (rule #34)
 * -------------------
 * Nothing is typed as a list of calls. From the Swift side (comments removed, files found from the tree) it reads
 * every `Endpoint(action: "...")` in the device-sign-in endpoint file and every field name in the private
 * request-body structs; from the PHP side it reads the `case '...':` labels in api.php and the request keys the
 * server looks up. It then checks:
 *   1. every action the app calls exists as a `case` in api.php;
 *   2. every device endpoint sets `capturesRefusalReason: true` (the app branches on the reason word);
 *   3. who must be signed in matches the server: the key redeem sends NO bearer (the server never asks for one),
 *      the swap, key create, key revoke and sign-out-everywhere all do;
 *   4. every body field the app sends is a key the server reads (`deviceInstallId`, `platform`, `deviceName`,
 *      `appVersion`, `createEnrolKey`, `enrolKey`, `keepCurrent`), for these calls AND for the ordinary sign-in
 *      bodies, which carry the same four device keys;
 *   5. the answer keys the app decodes are keys the server emits;
 *   6. self-tests: a deliberately renamed action, a dropped flag and a renamed body key are each reported.
 *
 * Mutations tried when this was written (each turned it red, then was restored): renaming an action in the Swift
 * endpoint file; removing `capturesRefusalReason: true` from one endpoint; renaming `deviceInstallId` in a Swift
 * body struct; renaming `'createEnrolKey'` in device_signin.php.
 *
 * Usage: node tests/test-apple-device-signin-contract.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { appSwiftFiles, read, stripSwift, callArguments, selfTest, REPO } from './lib/swift-source.js';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

const PUB = path.join(REPO, 'appWeb', 'public_html');
const phpRead = (rel) => fs.readFileSync(path.join(PUB, rel), 'utf8');

/** What the app side says, from the endpoint file's stripped code. PURE. */
function swiftSide(code) {
    const endpoints = [];
    for (const c of callArguments(code, 'Endpoint')) {
        const action = (/\baction\s*:\s*"([^"]+)"/.exec(c.args) || [])[1];
        if (!action) continue;
        endpoints.push({
            action,
            requiresAuth: /\brequiresAuth\s*:\s*true\b/.test(c.args),
            capturesReason: /\bcapturesRefusalReason\s*:\s*true\b/.test(c.args),
        });
    }
    // field names of every private body struct in the file
    const bodyFields = new Set();
    for (const m of code.matchAll(/private\s+struct\s+\w+RequestBody\s*:\s*Encodable\s*\{([\s\S]*?)\n\}/g)) {
        for (const f of m[1].matchAll(/^\s*let\s+([A-Za-z_][A-Za-z0-9_]*)\s*:/gm)) bodyFields.add(f[1]);
    }
    return { endpoints, bodyFields };
}

/** Judge the two sides. PURE: the self-tests feed it altered copies. */
function judge(swift, php) {
    const problems = [];
    if (swift.endpoints.length < 5) problems.push('expected at least 5 device endpoints, found ' + swift.endpoints.length);
    for (const e of swift.endpoints) {
        if (!php.cases.has(e.action)) problems.push('the app calls "' + e.action + '" but api.php has no such case');
        if (!e.capturesReason) problems.push('"' + e.action + '" does not set capturesRefusalReason: true');
    }
    const byAction = new Map(swift.endpoints.map((e) => [e.action, e]));
    const mustBeSignedIn = ['auth_device_enrol', 'auth_enrol_key_create', 'auth_enrol_keys_revoke', 'auth_signout_everywhere'];
    for (const a of mustBeSignedIn) {
        if (byAction.has(a) && !byAction.get(a).requiresAuth) problems.push('"' + a + '" must send the bearer sign-in (the server requires one)');
    }
    if (byAction.has('auth_enrol_key_redeem') && byAction.get('auth_enrol_key_redeem').requiresAuth) {
        problems.push('"auth_enrol_key_redeem" must NOT send a bearer (the key is the proof; the device has no sign-in)');
    }
    for (const f of swift.bodyFields) {
        if (!php.requestKeys.has(f)) problems.push('the app sends body field "' + f + '" that the server never reads');
    }
    for (const k of php.answerKeysTheAppDecodes) {
        if (!php.answerKeys.has(k)) problems.push('the app decodes answer key "' + k + '" that the server never emits');
    }
    return problems;
}

/* ---- the PHP side, from the real files ---- */
const api = phpRead('api.php');
const deviceSignin = phpRead('includes/device_signin.php');
const apiTokens = phpRead('includes/api_tokens.php');
const cases = new Set([...api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/g)].map((m) => m[1]));
/* Where the server reads request keys: the device-sign-in core, the shared device-details reader, and the one api.php
   case that reads `keepCurrent`. Deliberately NOT the whole of api.php, where common words like `platform` appear everywhere. */
const signoutCase = (() => { const i = api.indexOf("case 'auth_signout_everywhere'"); return i < 0 ? '' : api.slice(i, i + 2500); })();
const keyUniverse = deviceSignin + apiTokens + signoutCase;
const requestKeys = new Set();
for (const k of ['deviceInstallId', 'platform', 'deviceName', 'appVersion', 'createEnrolKey', 'enrolKey', 'keepCurrent']) {
    // the server must look the key up by name, as `['key']` or `"key"` in a read
    if (new RegExp("\\['" + k + "'\\]|\"" + k + "\"").test(keyUniverse)) requestKeys.add(k);
}
// answer keys: those the server puts in its replies
const answerKeys = new Set();
for (const k of ['token', 'user', 'device', 'expiresAt', 'enrolKey', 'enrolKeyExpiresAt', 'revoked', 'tokensRevoked', 'enrolKeysRevoked', 'keptCurrent']) {
    if (new RegExp("'" + k + "'\\s*=>").test(deviceSignin) || new RegExp("\\['" + k + "'\\]\\s*=").test(deviceSignin)) answerKeys.add(k);
}
// `token` and `user` come from the shared login-answer builder, apiAuthSuccessPayload(), which the enrol and redeem cases call
const payloadFn = (() => { const i = api.indexOf('function apiAuthSuccessPayload'); return i < 0 ? '' : api.slice(i, i + 800); })();
for (const k of ['token', 'user']) {
    if (new RegExp("'" + k + "'\\s*=>").test(payloadFn)) answerKeys.add(k);
}
// the answer keys the app's decoders read (from the decoding file's stripped code)
const files = appSwiftFiles();
const endpointFile = files.find((f) => f.endsWith('IHAPI/AuthDeviceEndpoints.swift'));
const decodingFile = files.find((f) => f.endsWith('IHAPI/AuthDeviceDecoding.swift'));
check('found the device endpoint and decoding files in the tree', !!endpointFile && !!decodingFile);
const endpointCode = endpointFile ? stripSwift(read(endpointFile), { blankStrings: false }) : '';
const decodingCode = decodingFile ? stripSwift(read(decodingFile), { blankStrings: true }) : '';
const decoded = new Set();
for (const m of decodingCode.matchAll(/^\s*let\s+([A-Za-z_][A-Za-z0-9_]*)\s*:/gm)) decoded.add(m[1]);
const decodedKeys = ['token', 'user', 'device', 'expiresAt', 'enrolKey', 'enrolKeyExpiresAt', 'revoked', 'tokensRevoked', 'enrolKeysRevoked', 'keptCurrent']
    .filter((k) => decoded.has(k) || ['token', 'user'].includes(k)); // token/user are decoded by AuthSession itself

const php = { cases, requestKeys, answerKeys, answerKeysTheAppDecodes: decodedKeys };
const swift = swiftSide(endpointCode);

check('the Swift side lists the five device actions (' + swift.endpoints.map((e) => e.action).join(', ') + ')', swift.endpoints.length === 5);
check('the Swift bodies carry device fields (' + [...swift.bodyFields].join(', ') + ')', swift.bodyFields.size >= 6);
check('the PHP side exposes the request keys the app sends (' + [...requestKeys].join(', ') + ')', requestKeys.size === 7);
check('the PHP side emits the answer keys the app decodes', decodedKeys.every((k) => answerKeys.has(k)), decodedKeys.filter((k) => !answerKeys.has(k)).join(','));

const real = judge(swift, php);
check('the app and the server agree on every device sign-in call', real.length === 0, real.join('\n        '));

/* ---- the ordinary sign-in bodies carry the same device keys ---- */
const authEndpointsFile = files.find((f) => f.endsWith('IHAPI/AuthEndpoints.swift'));
const authCode = authEndpointsFile ? stripSwift(read(authEndpointsFile)) : '';
const signInFields = new Set();
for (const m of authCode.matchAll(/private\s+struct\s+(AuthLoginRequestBody|AuthEmailLoginVerifyRequestBody)\s*:\s*Encodable\s*\{([\s\S]*?)\n\}/g)) {
    for (const f of m[2].matchAll(/^\s*let\s+([A-Za-z_][A-Za-z0-9_]*)\s*:/gm)) signInFields.add(f[1]);
}
for (const k of ['deviceInstallId', 'platform', 'deviceName', 'appVersion']) {
    check('the sign-in bodies carry "' + k + '" and the server reads it', signInFields.has(k) && requestKeys.has(k));
}

/* ---- self-tests ---- */
const asSwift = (o) => ({ endpoints: o.endpoints, bodyFields: new Set(o.bodyFields) });
check('self-test: a renamed action is caught',
    judge(asSwift({ endpoints: swift.endpoints.map((e, i) => (i === 0 ? { ...e, action: e.action + '_x' } : e)), bodyFields: [...swift.bodyFields] }), php).length > 0);
check('self-test: a dropped capturesRefusalReason is caught',
    judge(asSwift({ endpoints: swift.endpoints.map((e, i) => (i === 1 ? { ...e, capturesReason: false } : e)), bodyFields: [...swift.bodyFields] }), php).length > 0);
check('self-test: a bearer on the key redeem is caught',
    judge(asSwift({ endpoints: swift.endpoints.map((e) => (e.action === 'auth_enrol_key_redeem' ? { ...e, requiresAuth: true } : e)), bodyFields: [...swift.bodyFields] }), php).length > 0);
check('self-test: a missing bearer on the swap is caught',
    judge(asSwift({ endpoints: swift.endpoints.map((e) => (e.action === 'auth_device_enrol' ? { ...e, requiresAuth: false } : e)), bodyFields: [...swift.bodyFields] }), php).length > 0);
check('self-test: a renamed body field is caught',
    judge(asSwift({ endpoints: swift.endpoints, bodyFields: [...swift.bodyFields].map((f) => (f === 'deviceInstallId' ? 'installId' : f)) }), php).length > 0);
check('self-test: a key the server stops reading is caught',
    judge(swift, { ...php, requestKeys: new Set([...requestKeys].filter((k) => k !== 'createEnrolKey')) }).length > 0);
check('self-test: an action the server lacks is caught',
    judge(swift, { ...php, cases: new Set([...cases].filter((c) => c !== 'auth_signout_everywhere')) }).length > 0);
check('self-test: a comment naming an action does not count as a call', (() => {
    const s = swiftSide(stripSwift('// Endpoint(action: "auth_nothing", requiresAuth: true)\nlet x = 1'));
    return s.endpoints.length === 0;
})());

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
