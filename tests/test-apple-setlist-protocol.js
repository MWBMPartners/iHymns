/**
 * tests/test-apple-setlist-protocol.js - the Apple app speaks set-list sync protocol 2 everywhere it syncs
 * (account rewrite PR 3b, step I1, epic #2180)
 *
 * ELI5
 * ----
 * The server has two ways of syncing set lists. The old way treats "missing from the list" as "deleted",
 * quietly cuts a person's lists at 50, and lets two devices overwrite each other. The new way (protocol 2)
 * deletes only what is named in a `deleted` list, has no cut-off, remembers deletions, and uses a `since`
 * time stamp to refuse overwriting newer work. The server picks the way from whether the request carries a
 * `deleted` key at all. So if even ONE place in the app syncs without `deleted` and `since`, that place
 * silently gets the old, dangerous behaviour. This guard finds every place the app syncs set lists and
 * checks each one passes both, and that the answer's tombstones and conflicts are read.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * Nothing is typed as a file list: the Swift files come from walking `appApple/` (non-test code only), with
 * comments removed first (a comment saying "since:" must not satisfy a check). The server's side is read
 * from `api.php` too, so the wire keys the app sends and reads are proven to be keys the server really
 * reads and emits (a renamed server key turns this red instead of being a silent no-op).
 *
 * Checks:
 *   1. Every call to `syncSetlists(` / `setlistsSync(` in app code (outside their own declarations) passes
 *      BOTH `since:` and `deleted:`, and `deleted:` is never literally `nil`.
 *   2. Every `mode: .replace` in app code sits inside one of those calls (so no other route can sync).
 *   3. The request body type carries `since` and `deleted`, and the endpoint hands both to it.
 *   4. The one real caller builds `deleted` from the pending queue (`pendingDeletedSetlistIds`).
 *   5. The answer's `tombstones`, `conflicts` and `syncedAt` are decoded and the view model uses them.
 *   6. The server reads `since` and `deleted` and answers `syncedAt`, `tombstones`, `conflicts`.
 *   7. Self-tests: a mutated copy of the real source is judged red by the same functions.
 *
 * Mutations tried when this was written (each turned it red, then was restored): removing `since:` from the
 * view model's call; replacing `deleted: deleted` with `deleted: nil`; deleting `deleted` from the request
 * body struct; renaming the server's `$body['deleted']` read.
 *
 * Usage: node tests/test-apple-setlist-protocol.js
 */
import { appSwiftFiles, read, stripSwift, callArguments, selfTest, REPO } from './lib/swift-source.js';
import fs from 'node:fs';
import path from 'node:path';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 500) : ''));
}

/** Judge one file's stripped code: returns a list of problems. PURE, so the self-tests can call it. */
function judgeSyncCalls(file, code) {
    const problems = [];
    const calls = [...callArguments(code, 'syncSetlists'), ...callArguments(code, 'setlistsSync')];
    for (const c of calls) {
        if (!/\bsince\s*:/.test(c.args)) problems.push(file + ': a set-list sync call has no `since:` -> ' + c.args.replace(/\s+/g, ' ').slice(0, 120));
        if (!/\bdeleted\s*:/.test(c.args)) problems.push(file + ': a set-list sync call has no `deleted:` -> ' + c.args.replace(/\s+/g, ' ').slice(0, 120));
        if (/\bdeleted\s*:\s*nil\b/.test(c.args)) problems.push(file + ': a set-list sync call passes `deleted: nil` (that is protocol 1)');
    }
    // every `mode: .replace` must sit inside one of the calls above
    const replaceAt = [];
    const re = /\bmode\s*:\s*\.replace\b/g;
    let m;
    while ((m = re.exec(code)) !== null) replaceAt.push(m.index);
    for (const at of replaceAt) {
        const inside = calls.some((c) => at > c.index && at < c.index + c.args.length + 40);
        if (!inside) problems.push(file + ': a `mode: .replace` outside any checked sync call');
    }
    return { problems, callCount: calls.length };
}

const files = appSwiftFiles();
const stripped = new Map(files.map((f) => [f, stripSwift(read(f), { blankStrings: true })]));

/* ---- 1 and 2: every call site ---- */
let totalCalls = 0;
const allProblems = [];
for (const [f, code] of stripped) {
    const r = judgeSyncCalls(f, code);
    totalCalls += r.callCount;
    allProblems.push(...r.problems);
}
check('there is at least one real set-list sync call to judge (' + totalCalls + ' found)', totalCalls >= 1);
check('every set-list sync call passes since: and deleted: (never nil), and no other `mode: .replace` exists', allProblems.length === 0, allProblems.join('\n        '));

/* ---- 3: the request body and the endpoint ---- */
const endpointsFile = files.find((f) => f.endsWith('IHAPI/SetlistsEndpoints.swift'));
const endpoints = endpointsFile ? stripped.get(endpointsFile) : '';
const bodyStruct = (endpoints.match(/struct\s+SetlistsSyncRequestBody[\s\S]*?\n\}/) || [''])[0];
check('the request body struct declares `since` and `deleted`', /let\s+since\s*:\s*String\?/.test(bodyStruct) && /let\s+deleted\s*:\s*\[String\]\?/.test(bodyStruct), bodyStruct);
const bodyBuild = callArguments(endpoints, 'SetlistsSyncRequestBody');
check('the endpoint hands `since` and `deleted` to the body', bodyBuild.length === 1 && /since\s*:\s*since/.test(bodyBuild[0].args) && /deleted\s*:\s*deleted/.test(bodyBuild[0].args), JSON.stringify(bodyBuild));

/* ---- 4: where deleted comes from ---- */
const setlistsVmFile = files.find((f) => f.endsWith('IHFeatures/AppRootViewModel+Setlists.swift'));
const setlistsVm = setlistsVmFile ? stripped.get(setlistsVmFile) : '';
check('the view model builds `deleted` from the pending queue', /pendingDeletedSetlistIds\s*\(/.test(setlistsVm) && /deleted\s*:\s*deleted/.test(setlistsVm));
check('the view model quotes the saved `syncedAt` as `since`', /syncedAt\s*\(\s*forUser/.test(setlistsVm) && /since\s*:\s*since/.test(setlistsVm));
const pendingOk = (() => {
    const f = files.find((x) => x.endsWith('IHPersistence/OfflineStore+Setlists.swift'));
    const code = f ? stripped.get(f) : '';
    return /func\s+pendingDeletedSetlistIds/.test(code) && /PendingSetlistOperationKind\.delete\.rawValue/.test(code);
})();
check('the queue reader returns only the delete rows', pendingOk);

/* ---- 5: the answer ---- */
const decodingFile = files.find((f) => f.endsWith('IHAPI/SetlistsDecoding.swift'));
const decoding = decodingFile ? read(decodingFile) : '';
const decodingCode = decodingFile ? stripSwift(decoding, { blankStrings: true }) : '';
check('the decoder reads syncedAt, tombstones and conflicts', ['syncedAt', 'tombstones', 'conflicts'].every((k) => new RegExp('\\b' + k + '\\b').test(decodingCode)));
check('the view model absorbs tombstones, conflicts and syncedAt', ['tombstones', 'conflicts', 'syncedAt'].every((k) => new RegExp('\\b' + k + '\\b').test(setlistsVm)));

/* ---- 6: the server really reads and answers those keys ---- */
const api = fs.readFileSync(path.join(REPO, 'appWeb', 'public_html', 'api.php'), 'utf8');
const startAt = api.indexOf("case 'user_setlists_sync':");
const endAt = api.indexOf('User app settings', startAt);
const slice = startAt > 0 && endAt > startAt ? api.slice(startAt, endAt) : '';
check('located the server handler for user_setlists_sync', slice.length > 1000);
check("the server reads $body['since'] and $body['deleted']", /\$body\['since'\]/.test(slice) && /\$body\['deleted'\]/.test(slice));
check("the server answers 'syncedAt', 'tombstones' and 'conflicts'", ["'syncedAt'", "'tombstones'", "'conflicts'"].every((k) => slice.includes(k)));

/* ---- 7: mutation self-tests on in-memory copies of the real source ---- */
const goodCall = callArguments(setlistsVm, 'syncSetlists');
check('self-test: the real view model call has args to mutate', goodCall.length >= 1);
if (setlistsVmFile) {
    const real = read(setlistsVmFile);
    const strip = (s) => stripSwift(s, { blankStrings: true });
    const noSince = real.replace(/since:\s*since,\s*/, '');
    check('self-test: dropping `since:` is caught', real !== noSince && judgeSyncCalls('mutant', strip(noSince)).problems.length > 0);
    const nilDeleted = real.replace(/deleted:\s*deleted\b/, 'deleted: nil');
    check('self-test: `deleted: nil` is caught', real !== nilDeleted && judgeSyncCalls('mutant', strip(nilDeleted)).problems.length > 0);
    const stray = real + '\nfunc strayPush(api: X) async { _ = try? await api.somethingElse(mode: .replace) }\n';
    check('self-test: a stray `mode: .replace` is caught', judgeSyncCalls('mutant', strip(stray)).problems.length > 0);
    const inComment = real.replace('offlineStore.pendingDeletedSetlistIds()', '/* offlineStore.pendingDeletedSetlistIds() */ []');
    check('self-test: a mention in a comment does not count', real !== inComment && !/pendingDeletedSetlistIds\s*\(/.test(strip(inComment)));
}

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
