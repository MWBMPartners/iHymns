/**
 * tests/test-apple-sign-out-guard.js - the Apple app asks before a sign-out loses unsent changes
 * (account rewrite PR 3b, step I5, epic #2180)
 *
 * ELI5
 * ----
 * Signing out clears the favourites, set lists and settings kept on the device. If some change never reached the
 * account, that clearing would lose it for good. So (when the site has the new account sync on) the app first
 * tries to send everything waiting, counts what is still unsent, and refuses to clear until the person has been
 * asked. Three things quietly break this: a screen that signs out without going through the check; a new queue
 * of unsent changes that nobody remembered to count; and a sign-out that clears a store the check never looked at.
 * This guard reads the Swift source and turns each into a red build.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * Files are found from the tree and comments removed. Nothing is a typed list of queues: the list of "unsent
 * change" queues is DERIVED from the offline store (every `func pending...Ops()`), and the list of things a
 * sign-out clears is derived from its `clear...` calls. Checks:
 *   1. every queue the offline store has (`pending<Name>Ops()` in IHPersistence) is counted in `prepareSignOut()`;
 *   2. `prepareSignOut()` returns `.none` unless the account sync is on (`appStatus?.profileSync == true`) and
 *      signed in, flushes each queue it counts, and syncs the shared settings, all before it counts;
 *   3. the view model's `signOut(` and `signOutEverywhere(` both call `ensureNothingUnsent(` BEFORE the session
 *      controller, and `ensureNothingUnsent` skips only for an explicit `discardUnsent` flag;
 *   4. no screen calls the session controller's sign-out directly: only the view model does;
 *   5. every offline-store `clear...()` for personal data (favourites, set lists) that `resetLocalUserState`
 *      calls is one of the stores whose queue is counted, and the shared-settings notebook is forgotten there;
 *   6. self-tests: an uncounted queue, a missing early return, a check placed after the sign-out, and a screen
 *      that bypasses the view model are each reported.
 *
 * Mutations tried when this was written (each turned it red, then was restored): removing the setlist count
 * from `prepareSignOut`; moving `ensureNothingUnsent` after the controller call in `signOut`; removing the
 * `profileSync == true` early return; calling `sessionController.signOut()` from a view.
 *
 * Usage: node tests/test-apple-sign-out-guard.js
 */
import { appSwiftFiles, read, stripSwift, callArguments, braceBlock, selfTest } from './lib/swift-source.js';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

const files = appSwiftFiles();
const find = (s) => files.find((f) => f.endsWith(s));
const code = new Map(files.map((f) => [f, stripSwift(read(f), { blankStrings: true })]));

/** The body of `func name(` in `src` (first match), or null. */
function funcBody(src, name) {
    const m = new RegExp('func\\s+' + name + '\\s*\\(').exec(src);
    if (!m) return null;
    let depth = 1; let i = m.index + m[0].length;
    while (i < src.length && depth > 0) { if (src[i] === '(') depth++; else if (src[i] === ')') depth--; i++; }
    const open = src.indexOf('{', i);
    if (open < 0) return null;
    const b = braceBlock(src, open);
    return b ? b.text : null;
}

/** Judge the sources. PURE. */
function judge(src) {
    const problems = [];
    // 1. queues derived from the store
    const queues = [...src.store.matchAll(/func\s+(pending[A-Za-z]*Ops)\s*\(/g)].map((m) => m[1]);
    if (queues.length < 2) problems.push('expected at least 2 pending...Ops queues in the offline store, found ' + queues.length);
    const prepare = funcBody(src.check, 'prepareSignOut');
    if (prepare === null) return problems.concat('prepareSignOut() not found');
    for (const q of queues) {
        if (!new RegExp('\\b' + q + '\\s*\\(').test(prepare)) problems.push('prepareSignOut() does not count the "' + q + '" queue');
    }
    // 2. early return, flush before count
    const earlyAt = prepare.search(/guard\s+appStatus\?\.profileSync\s*==\s*true[^\n]*else\s*\{\s*return\s*\.none\s*\}/);
    if (earlyAt < 0) problems.push('prepareSignOut() must return .none unless appStatus?.profileSync == true (and signed in)');
    const flushes = [...prepare.matchAll(/\b(flushPending[A-Za-z]*|syncProfileSettings)\s*\(/g)];
    const firstCount = Math.min(...queues.map((q) => { const i = prepare.search(new RegExp('\\b' + q + '\\s*\\(')); return i < 0 ? Infinity : i; }));
    if (flushes.length < queues.length + 1) problems.push('prepareSignOut() must flush every queue and sync the shared settings (found ' + flushes.length + ' flush/sync calls)');
    if (flushes.some((m) => m.index > firstCount)) problems.push('prepareSignOut() flushes after it has started counting');
    // 3. the view model's sign-outs go through the check, before the controller
    for (const [name, controllerCall] of [['signOut', 'sessionController.signOut'], ['signOutEverywhere', 'sessionController.signOutEverywhere']]) {
        const body = funcBody(src.vmAll, name);
        if (body === null) { problems.push('the view model has no ' + name + '()'); continue; }
        const checkAt = body.search(/ensureNothingUnsent\s*\(/);
        const ctlAt = body.search(new RegExp(controllerCall.replace('.', '\\.') + '\\s*\\('));
        if (checkAt < 0 || ctlAt < 0 || checkAt > ctlAt) problems.push(name + '() must call ensureNothingUnsent( before ' + controllerCall + '(');
    }
    const ensure = funcBody(src.check, 'ensureNothingUnsent');
    if (ensure === null || !/guard\s+!discardUnsent\s+else\s*\{\s*return\s*\}/.test(ensure)) problems.push('ensureNothingUnsent must skip the check only for an explicit discardUnsent');
    // 4. no screen signs out directly
    for (const [f, c] of src.all) {
        if (f.includes('AppRootViewModel') || f.includes('/IHAuth/')) continue;
        if (/sessionController\s*\.\s*(signOut|signOutEverywhere)\s*\(/.test(c)) problems.push(f.split('/').pop() + ' signs out through the session controller, bypassing the unsent-changes check');
    }
    // 5. what the reset clears is what the check counts
    const reset = funcBody(src.authVm, 'resetLocalUserState');
    if (reset === null) problems.push('resetLocalUserState() not found');
    else {
        const clears = [...reset.matchAll(/offlineStore\.(clear[A-Za-z]*)\s*\(/g)].map((m) => m[1]);
        const countedStores = queues.map((q) => q.replace(/^pending/, '').replace(/Ops$/, ''));   // Favorite, Setlist
        for (const c of clears) {
            const noun = c.replace(/^clear/, '').replace(/s$/, '');                              // Favorites -> Favorite
            if (!countedStores.includes(noun)) problems.push('resetLocalUserState() clears "' + c + '" but no pending queue for it is counted');
        }
        for (const n of countedStores) {
            if (!clears.some((c) => c.replace(/^clear/, '').replace(/s$/, '') === n)) problems.push('a queue for "' + n + '" is counted but resetLocalUserState() does not clear that store');
        }
        if (!/profileSync\.forget\s*\(/.test(reset)) problems.push('resetLocalUserState() must forget the shared-settings notebook (profileSync.forget)');
    }
    return problems;
}

const checkFile = find('IHFeatures/AppRootViewModel+SignOutCheck.swift');
const storeFiles = files.filter((f) => /IHPersistence\/OfflineStore[^/]*\.swift$/.test(f));
const authFile = find('IHFeatures/AppRootViewModel+Auth.swift');
const vmFiles = files.filter((f) => /IHFeatures\/AppRootViewModel[^/]*\.swift$/.test(f));
check('found the sign-out check, the offline store, and the auth view model files', !!checkFile && storeFiles.length >= 2 && !!authFile);

const sources = {
    check: code.get(checkFile),
    store: storeFiles.map((f) => code.get(f)).join('\n'),
    vmAll: vmFiles.map((f) => code.get(f)).join('\n'),
    authVm: code.get(authFile),
    all: code,
};
const real = judge(sources);
check('the sign-out check holds on the real source', real.length === 0, real.join('\n        '));

/* ---- self-tests ---- */
const mut = (src, from, to) => { const out = src.replace(from, to); if (out === src) throw new Error('self-test mutation changed nothing: ' + from); return out; };
const with_ = (key, fn) => ({ ...sources, [key]: fn(sources[key]) });
check('self-test: an uncounted queue is caught', judge(with_('check', (c) => mut(c, /\(try\? await offlineStore\.pendingSetlistOps\(\)\.count\) \?\? 0/, '0'))).length > 0);
check('self-test: a missing early return is caught', judge(with_('check', (c) => mut(c, /guard appStatus\?\.profileSync == true[^\n]*\n/, '\n'))).length > 0);
check('self-test: a check placed after the controller call is caught', judge(with_('vmAll', (c) => mut(c, /try await ensureNothingUnsent\(discardUnsent: discardUnsent\)\n(\s*)try await sessionController\.signOut\(\)/, 'try await sessionController.signOut()\n$1try await ensureNothingUnsent(discardUnsent: discardUnsent)'))).length > 0);
check('self-test: a screen that signs out through the controller is caught', (() => {
    const all = new Map(code); all.set('IHFeatures/SomeView.swift', 'func tapped() async { try? await viewModel.sessionController.signOut() }');
    return judge({ ...sources, all }).length > 0;
})());
check('self-test: a store cleared but never counted is caught', judge(with_('authVm', (c) => mut(c, /try\? await offlineStore\.clearSetlists\(\)/, 'try? await offlineStore.clearSetlists()\n        try? await offlineStore.clearSavedSongs()'))).length > 0);
check('self-test: a discard that skips the check by default is caught', judge(with_('check', (c) => mut(c, /guard !discardUnsent else \{ return \}/, 'guard false else { return }'))).length > 0);
check('self-test: a new queue nobody counts is caught', judge(with_('store', (s) => s + '\npublic func pendingTagOps() async throws -> [Int] { [] }\n')).length > 0);

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
