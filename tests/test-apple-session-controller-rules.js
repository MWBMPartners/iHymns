/**
 * tests/test-apple-session-controller-rules.js - the rules the Apple session controller must never break
 * (account rewrite PR 3b, step I2c, epic #2180)
 *
 * ELI5
 * ----
 * The Apple session controller decides, at launch and at sign-out, whether a device may be signed in. Four
 * of its rules are easy to break by accident and impossible to see in a normal test run, because every one
 * of them "works" until a real phone is lost or a second device signs out:
 *   1. "Signed out here" must be written BEFORE the token is deleted. Written after, a crash in between
 *      leaves a device with no token and no mark, which signs itself straight back in.
 *   2. Signing out of the older SHARED sign-in must make no server call. Revoking it signs out every other
 *      Apple device the person owns.
 *   3. The shared sign-in must never be copied into any store (it would then outlive its owner's sign-out).
 *   4. The app must decide what a refusal means from the server's status AND its machine reason word, and
 *      those words must be words the server really sends. A typed-in string, or a renamed server word,
 *      would turn "stay signed out" into "sign in anyway" with nothing visibly wrong.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * Files come from walking the tree (non-test Swift only); comments are removed first; function bodies are
 * cut out of the code by brace matching, not by line numbers. The reason words and their statuses are read
 * from `includes/device_signin.php` (every `deviceSigninRefusal(<status>, '<word>', ...)`), never typed here.
 * Checks:
 *   1. In every function (in the session controller files) that both calls `markSignedOutHere` /
 *      `setSignedOutHere(true)` and deletes the device token (`tokenStore.delete`), the mark comes first.
 *   2. `signOut`'s `if usingLegacyToken { ... }` block comes before the server revoke and itself contains
 *      no `apiClient.auth...` call.
 *   3. No `.save(` call anywhere in app code takes the shared token. The names that hold the shared token are
 *      FOUND, not typed: whatever is bound from `legacyReader.load()`, plus any local copy of it and any
 *      function parameter it is passed into (followed through calls until nothing new turns up), so renaming
 *      the variable cannot slip past the rule.
 *   4. Every `DeviceSignInReason` raw value is a reason word in device_signin.php, and every place the app
 *      compares a refusal against a reason (`status == N && message == DeviceSignInReason.x.rawValue`) uses
 *      the status the server gives that word; no app code compares an error `message` against a bare string.
 *   5. Self-tests: each rule is run on a deliberately broken copy and must report a problem.
 *
 * (Second pass: rule 3 no longer depends on the variable being called `legacy`; the "mark" order mutation now
 * targets the rewritten signOut, and an unmarked delete anywhere is caught.)
 *
 * Mutations tried when this was written (each turned it red, then was restored): in signOut, deleting the
 * token before marking signed out; adding an `authLogout` call to the legacy-mode block; saving the shared
 * token to the device store; renaming `install_signed_out` in the Swift enum; changing the status the
 * Swift branch expects.
 *
 * Usage: node tests/test-apple-session-controller-rules.js
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

/** The text between the braces that open at `openIndex` (the `{`), balanced. */
function braceBlock(code, openIndex) {
    let depth = 0;
    for (let i = openIndex; i < code.length; i++) {
        if (code[i] === '{') depth++;
        else if (code[i] === '}') { depth--; if (depth === 0) return code.slice(openIndex + 1, i); }
    }
    return '';
}

/** The positions of the `{` characters still open at `index` within `body`. */
function enclosing(body, index) {
    const stack = [];
    for (let i = 0; i < index && i < body.length; i++) {
        if (body[i] === '{') stack.push(i);
        else if (body[i] === '}') stack.pop();
    }
    return stack;
}

/** Every `func name(...) ... { body }` in `code`: [{name, body, start}]. */
function functions(code) {
    const out = [];
    const re = /\bfunc\s+([A-Za-z_][A-Za-z0-9_]*)\s*(<[^>]*>)?\s*\(/g;
    let m;
    while ((m = re.exec(code)) !== null) {
        // find the opening brace of the body: the first `{` after the closing paren of the parameters
        let depth = 1;
        let i = m.index + m[0].length;
        while (i < code.length && depth > 0) { if (code[i] === '(') depth++; else if (code[i] === ')') depth--; i++; }
        const brace = code.indexOf('{', i);
        const semi = code.indexOf(';', i);
        if (brace < 0 || (semi >= 0 && semi < brace)) continue; // a protocol requirement, no body
        out.push({ name: m[1], body: braceBlock(code, brace), start: m.index });
    }
    return out;
}

/** Split `text` on commas that are not inside (), [] or {}. */
function splitTopLevel(text) {
    const parts = []; let depth = 0; let cur = '';
    for (const ch of text) {
        if ('([{'.includes(ch)) depth++;
        else if (')]}'.includes(ch)) depth--;
        if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; } else cur += ch;
    }
    if (cur.trim()) parts.push(cur);
    return parts;
}

/**
 * Every name, in ONE file, that can hold the shared (older-app) sign-in: bound from `legacyReader.load()`, copied
 * to another local, or received as a parameter by a function of the same file that is called with one of these
 * names. Found from the code. (Per file on purpose: a name like `token` is everywhere, so following the shared
 * token into other files' functions would flag every ordinary token. The shared token is only ever read, and
 * handed on, inside the launch file; the reader file check in the custody guard covers where it is read.)
 */
function sharedTokenNames(code) {
    const tainted = new Set();
    for (const m of code.matchAll(/\b(?:let|var)\s+([A-Za-z_]\w*)\s*=\s*(?:try\??\s+)?(?:await\s+)?[\w.]*legacyReader\s*\.\s*load\s*\(/g)) tainted.add(m[1]);
    if (tainted.size === 0) return tainted;
    const decls = [];
    for (const m of code.matchAll(/\bfunc\s+([A-Za-z_]\w*)\s*(?:<[^>]*>)?\s*\(/g)) {
        let depth = 1; let i = m.index + m[0].length; const start = i;
        while (i < code.length && depth > 0) { if (code[i] === '(') depth++; else if (code[i] === ')') depth--; i++; }
        const names = splitTopLevel(code.slice(start, i - 1)).map((p) => (/^\s*(?:[A-Za-z_]\w*\s+)?([A-Za-z_]\w*)\s*:/.exec(p) || [])[1] ?? null);
        decls.push({ name: m[1], names });
    }
    const has = (text) => [...tainted].some((n) => new RegExp('\\b' + n + '\\b').test(text));
    for (let changed = true; changed;) {
        changed = false;
        for (const m of code.matchAll(/\b(?:let|var)\s+([A-Za-z_]\w*)\s*=\s*([^\n]+)/g)) {
            if (!tainted.has(m[1]) && has(m[2])) { tainted.add(m[1]); changed = true; }
        }
        for (const d of decls) {
            for (const c of callArguments(code, d.name)) {
                splitTopLevel(c.args).forEach((arg, i) => {
                    const pn = d.names[i];
                    if (pn && has(arg) && !tainted.has(pn)) { tainted.add(pn); changed = true; }
                });
            }
        }
    }
    return tainted;
}

/** Judge a map of {file: strippedCode (strings kept)} against the server's reason table. PURE. */
function judge(map, serverReasons) {
    const problems = [];
    const controllerFiles = [...map].filter(([f]) => /\/IHAuth\/SessionController[^/]*\.swift$/.test(f));
    if (controllerFiles.length < 2) problems.push('expected SessionController.swift and its device sign-in extension, found ' + controllerFiles.length);

    // ---- rule 1: the "signed out here" mark comes before every token delete, on the same path ----
    let functionsJudged = 0;
    for (const [f, code] of controllerFiles) {
        for (const fn of functions(code)) {
            const deletes = [...fn.body.matchAll(/\btokenStore\.delete\s*\(/g)].map((m) => m.index);
            const marks = [...fn.body.matchAll(/\bmarkSignedOutHere\s*\(|\bsetSignedOutHere\s*\(\s*true\s*\)/g)].map((m) => m.index);
            if (deletes.length === 0) continue;
            if (marks.length > 0) functionsJudged++;
            for (const d of deletes) {
                // a mark counts only if it is on the SAME path as the delete: every block it sits in is still open at the delete
                const open = enclosing(fn.body, d);
                const covered = marks.some((m) => m < d && enclosing(fn.body, m).every((b) => open.includes(b)));
                if (!covered) problems.push(f.split('/').pop() + ' ' + fn.name + '(): a token delete is not preceded by "signed out here" on its path');
            }
        }
    }
    if (functionsJudged < 4) problems.push('expected at least 4 functions that mark signed-out-here and delete the token, judged ' + functionsJudged);

    // ---- rule 2: signOut's legacy block makes no server call ----
    const core = controllerFiles.find(([f]) => f.endsWith('/SessionController.swift'));
    if (core) {
        const signOut = functions(core[1]).find((fn) => fn.name === 'signOut');
        if (!signOut) problems.push('signOut() not found');
        else {
            const at = signOut.body.search(/if\s+usingLegacyToken\s*\{/);
            if (at < 0) problems.push('signOut() has no `if usingLegacyToken` branch');
            else {
                const open = signOut.body.indexOf('{', at);
                const block = braceBlock(signOut.body, open);
                if (/apiClient\s*\.\s*auth/.test(block)) problems.push('signOut() calls the server while in legacy mode');
                const revoke = signOut.body.search(/apiClient\.authLogout\s*\(/);
                if (revoke >= 0 && at > revoke) problems.push('signOut() reaches the server revoke before the legacy-mode branch');
            }
        }
    }

    // ---- rule 3: the shared token is never saved anywhere ----
    let readerFiles = 0;
    for (const [f, code] of map) {
        const sharedNames = sharedTokenNames(code);
        if (sharedNames.size === 0) continue;
        readerFiles++;
        for (const c of callArguments(code, 'save')) {
            const hit = [...sharedNames].find((n) => new RegExp('\\b' + n + '\\b').test(c.args));
            if (hit) problems.push(f.split('/').pop() + ': saves the shared (legacy) token (`' + hit + '`) into a store');
        }
    }
    if (readerFiles === 0) problems.push('could not find where the shared sign-in is read (legacyReader.load()), so rule 3 cannot be judged');

    // ---- rule 4: reason words ----
    const enumFile = [...map].find(([, code]) => /\benum\s+DeviceSignInReason\b/.test(code));
    const rawValues = new Map(); // case name -> raw value
    if (!enumFile) problems.push('DeviceSignInReason enum not found');
    else {
        for (const m of enumFile[1].matchAll(/\bcase\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"([^"]+)"/g)) rawValues.set(m[1], m[2]);
        if (rawValues.size === 0) problems.push('DeviceSignInReason has no cases');
    }
    for (const [name, word] of rawValues) {
        if (!serverReasons.has(word)) problems.push('DeviceSignInReason.' + name + ' = "' + word + '" is not a reason word the server sends');
    }
    let comparisons = 0;
    for (const [f, code] of map) {
        for (const m of code.matchAll(/status\s*==\s*(\d+)\s*&&\s*message\s*==\s*DeviceSignInReason\.([A-Za-z_][A-Za-z0-9_]*)\.rawValue/g)) {
            comparisons++;
            const word = rawValues.get(m[2]);
            const wantStatus = word ? serverReasons.get(word) : undefined;
            if (word === undefined) problems.push(f.split('/').pop() + ': compares against unknown reason DeviceSignInReason.' + m[2]);
            else if (wantStatus !== undefined && String(wantStatus) !== m[1]) problems.push(f.split('/').pop() + ': expects status ' + m[1] + ' for "' + word + '" but the server answers ' + wantStatus);
        }
        if (/\bmessage\s*==\s*"/.test(code) || /"\s*==\s*message\b/.test(code)) problems.push(f.split('/').pop() + ': compares an error message against a bare string (use DeviceSignInReason)');
    }
    if (comparisons < 1) problems.push('expected at least one status-plus-reason comparison, found ' + comparisons);
    return problems;
}

/* ---- the server's reason words and their statuses, from the real file ---- */
const deviceSignin = fs.readFileSync(path.join(REPO, 'appWeb', 'public_html', 'includes', 'device_signin.php'), 'utf8');
const serverReasons = new Map();
for (const m of deviceSignin.matchAll(/deviceSigninRefusal\(\s*(\d{3})\s*,\s*'([a-z0-9_]+)'/g)) serverReasons.set(m[2], Number(m[1]));
check('read the server reason table (' + serverReasons.size + ' words)', serverReasons.size >= 8 && serverReasons.get('install_signed_out') === 403);

const files = appSwiftFiles();
const map = new Map(files.map((f) => [f, stripSwift(read(f))]));
const real = judge(map, serverReasons);
check('every controller rule holds on the real source', real.length === 0, real.join('\n        '));

/* ---- self-tests on broken copies ---- */
function mutated(rel, fn) {
    const m = new Map(map);
    const key = [...m.keys()].find((k) => k.endsWith(rel));
    if (!key) throw new Error('self-test target missing: ' + rel);
    const before = m.get(key);
    const after = fn(before);
    if (after === before) throw new Error('self-test mutation changed nothing in ' + rel);
    m.set(key, after);
    return m;
}
check('self-test: deleting the token before marking signed out is caught', judge(mutated('IHAuth/SessionController.swift', (c) =>
    c.replace(/try await markSignedOutHere\(\)\s*\n\s*var tokenLeftBehind = false/, 'do { try await tokenStore.delete() } catch {}\n        try await markSignedOutHere()\n        var tokenLeftBehind = false')), serverReasons).length > 0);
check('self-test: dropping the mark from the own-sign-in sign-out is caught', judge(mutated('IHAuth/SessionController.swift', (c) =>
    c.replace(/\n {8}try await markSignedOutHere\(\)\n/, '\n')), serverReasons).length > 0);
check('self-test: dropping the mark from sign-out-everywhere is caught', judge(mutated('IHAuth/SessionController+DeviceSignIn.swift', (c) =>
    c.replace(/\n {8}try await markSignedOutHere\(\)\n/, '\n')), serverReasons).length > 0);
check('self-test: a server call inside the legacy-mode sign-out block is caught', judge(mutated('IHAuth/SessionController.swift', (c) =>
    c.replace(/if usingLegacyToken \{/, 'if usingLegacyToken {\n            try? await apiClient.authLogout()')), serverReasons).length > 0);
check('self-test: saving the shared token is caught', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c + '\nfunc oops(reader: LegacyTokenReading, tokenStore: any TokenStoring) async { let sh = try? await reader.legacyReader.load(); try? await tokenStore.save(sh) }\n'), serverReasons).length > 0);
check('self-test: saving the shared token is caught even when the variable has another name', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c.replace(/\blegacy\b/g, 'sharedTok').replace('usingLegacyToken = true\n', 'usingLegacyToken = true\n        try? await tokenStore.save(sharedTok)\n')), serverReasons).length > 0);
check('self-test: a renamed variable alone does not trip rule 3 (no false alarm)', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c.replace(/\blegacy\b/g, 'sharedTok')), serverReasons).length === 0);
check('self-test: the shared token copied into a new local and saved is caught', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c.replace('usingLegacyToken = true\n', 'usingLegacyToken = true\n        let copy = legacy\n        try? await tokenStore.save(copy)\n')), serverReasons).length > 0);
check('self-test: a renamed reason word in the enum is caught', judge(mutated('IHAuth/DeviceSignInReason.swift', (c) =>
    c.replace('"install_signed_out"', '"install_signed_gone"')), serverReasons).length > 0);
check('self-test: a wrong expected status is caught', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c.replace('status == 403 && message ==', 'status == 409 && message ==')), serverReasons).length > 0);
check('self-test: matching an error message against a typed-in string is caught', judge(mutated('IHAuth/SessionController+Launch.swift', (c) =>
    c + '\nfunc oops2(message: String?) -> Bool { message == "install_signed_out" }\n'), serverReasons).length > 0);
check('self-test: a server that stops sending the word is caught', judge(map, new Map([...serverReasons].filter(([w]) => w !== 'install_signed_out'))).length > 0);
check('self-test: a comment that mentions a server call in the legacy block does not trip the rule', (() => {
    const raw = read(files.find((f) => f.endsWith('IHAuth/SessionController.swift')));
    const withComment = raw.replace(/if usingLegacyToken \{/, 'if usingLegacyToken {\n            // never call apiClient.authLogout() here\n');
    if (withComment === raw) throw new Error('self-test mutation changed nothing');
    const m = new Map(map);
    m.set(files.find((f) => f.endsWith('IHAuth/SessionController.swift')), stripSwift(withComment));
    return judge(m, serverReasons).length === 0;
})());

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
