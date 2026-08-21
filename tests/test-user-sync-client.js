/**
 * iHymns — user-data sync client contract (#1649)
 *
 * The server-side half of #1649 (tests/php/test-user-sync-guard.php) stops a
 * CAPPED sync payload deleting the rows it had to leave out. This test guards
 * the CLIENT half: supplying the `since` watermark, absorbing every response,
 * and never advancing the watermark without keeping the data.
 *
 * THE INVARIANT THIS EXISTS TO PROTECT:
 *   A watermark asserts "I have seen everything the server held up to time T".
 *   The server believes it — on the next sync it treats rows older than T as
 *   safe to delete. So advancing the watermark WITHOUT merging the returned
 *   list in is not a cosmetic slip: it marks rows the client never absorbed as
 *   "seen", and its next payload (which lacks them) gets them deleted one
 *   cycle later. That is the same permanent data loss #1649 closes, merely
 *   deferred by one round-trip — and it would be invisible in manual testing,
 *   because the damage lands on the sync AFTER the one you are watching.
 *
 * These modules pull in the whole app graph (DOM, localStorage, Bootstrap,
 * the offline queue), so — following tests/test-setlist-playback.js and
 * tests/test-event-names.js — this asserts against the SOURCE rather than
 * importing. Structural checks are the right tool here anyway: the invariant
 * is "this write happens in exactly one place", which is a property of the
 * code's shape, not of any single execution.
 *
 *   node tests/test-user-sync-client.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const JS = (...p) => join(__dirname, '..', 'appWeb', 'public_html', 'js', ...p);

const userAuthSrc  = readFileSync(JS('modules', 'user-auth.js'), 'utf8');
const setlistSrc   = readFileSync(JS('modules', 'setlist.js'), 'utf8');
const favoritesSrc = readFileSync(JS('modules', 'favorites.js'), 'utf8');
const constantsSrc = readFileSync(JS('constants.js'), 'utf8');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/**
 * Extract one class-method body by balanced-brace scan, so an assertion can be
 * scoped to "inside THIS method" rather than "somewhere in the file" — which
 * is the whole point when the property under test is *where* a write happens.
 */
function methodBody(src, name) {
    const re = new RegExp(`(?:async\\s+)?${name}\\s*\\([^)]*\\)\\s*\\{`);
    const m = re.exec(src);
    if (!m) return null;
    const open = src.indexOf('{', m.index + m[0].length - 1);
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) return src.slice(open, i + 1);
        }
    }
    return null;
}

/** Strip line + block comments so a check for a banned CALL isn't tripped by prose about it. */
const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

/** Count non-overlapping occurrences of a literal. */
const countOf = (hay, needle) => hay.split(needle).length - 1;

/* ---------------------------------------------------------------------- */
/* 1. The watermark keys live in constants.js (repo convention)            */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 1 — watermark storage keys are declared centrally:');

check('STORAGE_SETLISTS_SYNCED_AT is exported from constants.js',
    /export const STORAGE_SETLISTS_SYNCED_AT\s*=\s*'ihymns_setlists_synced_at'/.test(constantsSrc));
check('STORAGE_FAVORITES_SYNCED_AT is exported from constants.js',
    /export const STORAGE_FAVORITES_SYNCED_AT\s*=\s*'ihymns_favorites_synced_at'/.test(constantsSrc));
check('user-auth.js imports both keys rather than inlining the strings',
    /STORAGE_SETLISTS_SYNCED_AT/.test(userAuthSrc) && /STORAGE_FAVORITES_SYNCED_AT/.test(userAuthSrc));

/* The raw key strings must not be re-typed anywhere outside constants.js —
   a second spelling is the silent-divergence class of #1581. */
for (const [file, src] of [['user-auth.js', userAuthSrc], ['setlist.js', setlistSrc], ['favorites.js', favoritesSrc]]) {
    check(`${file} does not hard-code the raw 'ihymns_*_synced_at' key strings`,
        !/'ihymns_(setlists|favorites)_synced_at'/.test(stripComments(src)));
}

/* ---------------------------------------------------------------------- */
/* 2. Both sync requests actually SEND the watermark                       */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 2 — the request bodies carry `since`:');

const syncSetlistsBody  = methodBody(userAuthSrc, 'syncSetlists');
const syncFavoritesBody = methodBody(userAuthSrc, 'syncFavorites');

check('syncSetlists() is present', syncSetlistsBody !== null);
check('syncFavorites() is present', syncFavoritesBody !== null);

check('syncSetlists() sends a `since` field read from STORAGE_SETLISTS_SYNCED_AT',
    !!syncSetlistsBody && /since:/.test(syncSetlistsBody)
    && /STORAGE_SETLISTS_SYNCED_AT/.test(syncSetlistsBody));
check('syncFavorites() sends a `since` field read from STORAGE_FAVORITES_SYNCED_AT',
    !!syncFavoritesBody && /since:/.test(syncFavoritesBody)
    && /STORAGE_FAVORITES_SYNCED_AT/.test(syncFavoritesBody));

/* The responses grew from a bare array to {list, syncedAt, truncated}; a
   caller still treating them as arrays would silently stop absorbing. */
check('syncSetlists() returns the syncedAt + truncated metadata, not a bare array',
    !!syncSetlistsBody && /syncedAt:/.test(syncSetlistsBody) && /truncated:/.test(syncSetlistsBody));
check('syncFavorites() returns the syncedAt + truncated metadata, not a bare array',
    !!syncFavoritesBody && /syncedAt:/.test(syncFavoritesBody) && /truncated:/.test(syncFavoritesBody));

/* Repo rule #31 — same-origin requests go through apiFetch, never bare fetch. */
check('both sync calls still use apiFetch (never a bare fetch)',
    !!syncSetlistsBody && !!syncFavoritesBody
    && /apiFetch\(/.test(syncSetlistsBody) && /apiFetch\(/.test(syncFavoritesBody)
    && !/[^.\w]fetch\(/.test(stripComments(syncSetlistsBody))
    && !/[^.\w]fetch\(/.test(stripComments(syncFavoritesBody)));

/* ---------------------------------------------------------------------- */
/* 3. THE LOAD-BEARING INVARIANT — the watermark advances ONLY on absorb   */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 3 — the watermark is written in exactly one chokepoint, called only by the absorb helpers:');

const setSyncedAtBody   = methodBody(userAuthSrc, '_setSyncedAt');
const absorbSetlistBody = methodBody(userAuthSrc, '_absorbSetlistSync');
const absorbFavBody     = methodBody(userAuthSrc, '_absorbFavoritesSync');

check('_setSyncedAt() exists (the single write chokepoint)', setSyncedAtBody !== null);
check('_absorbSetlistSync() exists', absorbSetlistBody !== null);
check('_absorbFavoritesSync() exists', absorbFavBody !== null);

/* _setSyncedAt is the only place a watermark is written. (user-auth.js has
   other, unrelated localStorage.setItem calls — the auth token and the cached
   user — so the assertion is scoped to the watermark KEYS, not to setItem in
   general.) */
const authNoComments = stripComments(userAuthSrc);
const setItemCallsInChokepoint = setSyncedAtBody ? countOf(stripComments(setSyncedAtBody), 'localStorage.setItem') : 0;

check('_setSyncedAt() performs the localStorage write',
    setItemCallsInChokepoint === 1,
    `found ${setItemCallsInChokepoint} inside _setSyncedAt`);

/* No setItem names a watermark constant directly — every write must go through
   the chokepoint, which is what keeps "advance only after absorbing" true. */
check('no localStorage.setItem writes a watermark key directly (all go via _setSyncedAt)',
    !/localStorage\.setItem\(\s*STORAGE_(SETLISTS|FAVORITES)_SYNCED_AT/.test(authNoComments));

/* And no OTHER module writes them at all. */
for (const [file, src] of [['setlist.js', setlistSrc], ['favorites.js', favoritesSrc]]) {
    check(`${file} never writes a watermark key itself`,
        !/STORAGE_(SETLISTS|FAVORITES)_SYNCED_AT/.test(stripComments(src)));
}

/* _setSyncedAt is invoked exactly twice — once per absorb helper — and each of
   those calls is inside the corresponding helper. */
const setSyncedAtCalls = countOf(authNoComments, '_setSyncedAt(') - 1; /* minus the definition */
check('_setSyncedAt() is called exactly twice in the file (once per store)',
    setSyncedAtCalls === 2,
    `found ${setSyncedAtCalls} call(s)`);

check('_absorbSetlistSync() is the one that advances the SETLISTS watermark',
    !!absorbSetlistBody && /_setSyncedAt\(\s*STORAGE_SETLISTS_SYNCED_AT/.test(absorbSetlistBody));
check('_absorbFavoritesSync() is the one that advances the FAVOURITES watermark',
    !!absorbFavBody && /_setSyncedAt\(\s*STORAGE_FAVORITES_SYNCED_AT/.test(absorbFavBody));

/* …and each helper ALSO persists the returned list. A helper that advanced the
   watermark but skipped the save would be exactly the deferred-loss bug. */
check('_absorbSetlistSync() also merges + persists the returned list (union then saveAll)',
    !!absorbSetlistBody && /_unionSetlists\(/.test(absorbSetlistBody) && /saveAll\(/.test(absorbSetlistBody));
check('_absorbFavoritesSync() also merges + persists the returned list (merge then saveAll)',
    !!absorbFavBody && /_mergeFavorites\(/.test(absorbFavBody) && /saveAll\(/.test(absorbFavBody));

/* Ordering: the save must come BEFORE the watermark advance in the source. */
for (const [label, body] of [['_absorbSetlistSync', absorbSetlistBody], ['_absorbFavoritesSync', absorbFavBody]]) {
    if (!body) { check(`${label} orders saveAll BEFORE _setSyncedAt`, false, 'body not found'); continue; }
    check(`${label} orders saveAll() BEFORE _setSyncedAt()`,
        body.indexOf('saveAll(') < body.indexOf('_setSyncedAt('));
}

/* ---------------------------------------------------------------------- */
/* 4. Watermarks are dropped on any auth transition                        */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 4 — a different account never inherits a watermark:');

const resetBody = methodBody(userAuthSrc, '_resetUserDataSyncState');
check('_resetUserDataSyncState() exists', resetBody !== null);
check('_resetUserDataSyncState() removes the SETLISTS watermark',
    !!resetBody && /removeItem\(\s*STORAGE_SETLISTS_SYNCED_AT\s*\)/.test(resetBody));
check('_resetUserDataSyncState() removes the FAVOURITES watermark',
    !!resetBody && /removeItem\(\s*STORAGE_FAVORITES_SYNCED_AT\s*\)/.test(resetBody));

/* ---------------------------------------------------------------------- */
/* 5. The offline drains absorb instead of overwriting local state         */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 5 — the offline drains no longer clobber the local cache:');

const bindDrainsBody = methodBody(userAuthSrc, 'bindOfflineDrains');
check('bindOfflineDrains() exists', bindDrainsBody !== null);

const drains = bindDrainsBody ? stripComments(bindDrainsBody) : '';
check('the drains route through the absorb helpers',
    /_absorbSetlistSync\(/.test(drains) && /_absorbFavoritesSync\(/.test(drains));

/* The exact regression: saveAll(merged…) overwrote LOCAL storage with the
   server's (possibly truncated) list, turning partial loss into total loss. */
check('no `saveAll(merged` overwrite survives in the drains (the amplifier bug)',
    !/saveAll\(\s*merged/.test(drains));
check('no `saveAll(merged` overwrite survives anywhere in user-auth.js',
    !/saveAll\(\s*merged/.test(authNoComments));

/* ---------------------------------------------------------------------- */
/* 6. Stale-tab refresh — visibilitychange + a 30-minute TTL               */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 6 — a long-backgrounded tab re-reconciles before it can push:');

check('a visibilitychange listener is registered',
    /addEventListener\(\s*'visibilitychange'/.test(userAuthSrc));
check('it only acts when the page actually becomes visible',
    /visibilityState\s*!==\s*'visible'/.test(userAuthSrc));
check('a 30-minute TTL gates the re-reconcile',
    /30\s*\*\s*60\s*\*\s*1000/.test(userAuthSrc));
check('the latch timestamp _userDataSyncedAt is recorded when the latch is set',
    /_userDataSyncedAt\s*=\s*Date\.now\(\)/.test(userAuthSrc));
check('the re-reconcile clears the latch and calls triggerUserDataSync()',
    /_userDataSynced\s*=\s*false;[\s\S]{0,120}triggerUserDataSync\(\)/.test(authNoComments));

/* _syncReady must NOT be disarmed here — re-arming it would silently drop an
   edit made in the window before the new reconcile lands. */
const visListener = (() => {
    const i = userAuthSrc.indexOf("addEventListener('visibilitychange'");
    return i < 0 ? '' : userAuthSrc.slice(i, i + 900);
})();
check('the visibilitychange handler does NOT disarm _syncReady',
    !/_syncReady\s*=\s*false/.test(visListener));

/* ---------------------------------------------------------------------- */
/* 7. Per-edit pushes absorb; shareSetlist is gated                        */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 7 — every caller absorbs, and shareSetlist no longer bypasses the gate:');

const setlistSchedule = methodBody(setlistSrc, '_scheduleSync');
const favSchedule     = methodBody(favoritesSrc, '_scheduleSync');

check('setlist.js _scheduleSync() absorbs its sync result',
    !!setlistSchedule && /_absorbSetlistSync\(/.test(setlistSchedule));
check('favorites.js _scheduleSync() absorbs its sync result',
    !!favSchedule && /_absorbFavoritesSync\(/.test(favSchedule));

/* Awaiting matters: absorbing a Promise object instead of a result is a
   silent no-op of exactly the kind rule #30 warns about. */
check('setlist.js _scheduleSync() awaits the sync before absorbing',
    !!setlistSchedule && /await\s+this\.app\.userAuth\.syncSetlists\(/.test(setlistSchedule));
check('favorites.js _scheduleSync() awaits the sync before absorbing',
    !!favSchedule && /await\s+this\.app\.userAuth\.syncFavorites\(/.test(favSchedule));

const shareBody = methodBody(setlistSrc, 'shareSetlist');
check('shareSetlist() exists', shareBody !== null);

/* It used to send a HARDCODED 'replace', bypassing the _syncReady gate — so a
   share before the first reconcile pushed a possibly-empty list as truth. */
const shareNoComments = shareBody ? stripComments(shareBody) : '';
check('shareSetlist() gates its mode on _syncReady (no bare `replace` literal)',
    !!shareBody && /_syncReady\s*\?\s*'replace'\s*:\s*'merge'/.test(shareNoComments));
check("shareSetlist() contains no ungated 'replace' literal",
    !!shareBody && countOf(shareNoComments, "'replace'") === 1);
check('shareSetlist() absorbs its sync result too',
    !!shareBody && /_absorbSetlistSync\(/.test(shareNoComments));

/* ---------------------------------------------------------------------- */
/* 8. Truncation is surfaced to the user, once, from one place             */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 8 — an over-cap collection warns the user instead of failing silently:');

const warnBody = methodBody(userAuthSrc, '_warnTruncated');
check('_warnTruncated() exists', warnBody !== null);
check('_warnTruncated() shows a toast', !!warnBody && /showToast/.test(warnBody));
check('_warnTruncated() is throttled by a per-instance latch (one toast per session)',
    !!warnBody && /this\[flagName\]/.test(warnBody));
check('the warning fires from inside the sync methods, so every caller is covered',
    !!syncSetlistsBody && /_warnTruncated\(/.test(syncSetlistsBody)
    && !!syncFavoritesBody && /_warnTruncated\(/.test(syncFavoritesBody));
check('the two stores use distinct warn latches',
    /_setlistCapWarned/.test(userAuthSrc) && /_favCapWarned/.test(userAuthSrc));

/* ---------------------------------------------------------------------- */
/* 9. #1661 — the client half of sync protocol 2                          */
/* ---------------------------------------------------------------------- */

console.log('\nAssertion 9 — deletion is announced explicitly, and tombstones are applied:');

const setlistNoComments = stripComments(setlistSrc);
const authNoComments9   = stripComments(userAuthSrc);

/* The pending-delete queue key lives in constants.js like every other one. */
check('STORAGE_SETLISTS_DELETED is exported from constants.js',
    /export const STORAGE_SETLISTS_DELETED\s*=\s*'ihymns_setlists_deleted'/.test(constantsSrc));
check('setlist.js imports the key rather than re-typing the string',
    /STORAGE_SETLISTS_DELETED/.test(setlistSrc)
    && !/'ihymns_setlists_deleted'/.test(setlistNoComments));

/* THE PROTOCOL MARKER. `deleted` must be sent on EVERY sync — the server
   detects protocol 2 by the key's PRESENCE, so making it conditional on the
   queue being non-empty would silently drop the client back to legacy
   absence-based deletion on every sync that happened not to delete anything.
   That is the bug, wearing the costume of an optimisation. */
check('syncSetlists() sends a `deleted` field',
    !!syncSetlistsBody && /\bdeleted,/.test(stripComments(syncSetlistsBody)));
check('the `deleted` field is UNCONDITIONAL (no spread-if, unlike `since`)',
    !!syncSetlistsBody
    && !/\.\.\.\([^)]*deleted/.test(stripComments(syncSetlistsBody))
    && !/deleted\.length\s*>\s*0\s*\?/.test(stripComments(syncSetlistsBody)));

/* Captured before the request, cleared after — and only the ids sent, so a
   deletion made while the request was in flight survives to be re-announced. */
check('syncSetlists() reads the queue via getPendingDeletes()',
    !!syncSetlistsBody && /getPendingDeletes\?\.\(\)|getPendingDeletes\(\)/.test(syncSetlistsBody));
check('syncSetlists() clears ONLY the ids it sent, after a successful response',
    !!syncSetlistsBody && /clearPendingDeletes\?\.\(\s*deleted\s*\)|clearPendingDeletes\(\s*deleted\s*\)/.test(syncSetlistsBody));
check('the clear happens AFTER the response is parsed, not before the request',
    !!syncSetlistsBody
    && syncSetlistsBody.indexOf('await res.json()') < syncSetlistsBody.indexOf('clearPendingDeletes'));

/* delete() must QUEUE before it saves — saveAll() schedules the sync, so a
   queue write afterwards can lose a race with the debounce on a slow device
   and publish a payload that deletes nothing. */
const deleteBody = methodBody(setlistSrc, 'delete');
check('setlist.js delete() exists', deleteBody !== null);
check('delete() queues the id for explicit announcement',
    !!deleteBody && /_queuePendingDelete\(/.test(deleteBody));
check('delete() queues BEFORE saveAll() (saveAll schedules the sync)',
    !!deleteBody && deleteBody.indexOf('_queuePendingDelete(') < deleteBody.indexOf('saveAll('));

/* The queue is persisted, not in-memory: an offline delete, or one made a
   moment before the tab closes, must still be announced. */
check('the pending-delete queue is persisted to localStorage',
    /localStorage\.setItem\(\s*STORAGE_SETLISTS_DELETED/.test(setlistNoComments));

/* Tombstones from the server prune the local cache — and the ORDER matters:
   _unionSetlists lets the local copy win for a shared id, so a set list
   deleted on another device would survive its own tombstone if it were still
   present at union time. */
check('setlist.js exposes applyTombstones()', /applyTombstones\(/.test(setlistSrc));
check('_absorbSetlistSync() applies tombstones',
    !!absorbSetlistBody && /applyTombstones\(/.test(absorbSetlistBody));
/* ⚠️ Expressed as DATA FLOW, not source order. The obvious version —
   "applyTombstones appears before _unionSetlists" — passed mutation M24, in
   which the prune WRAPPED the union: `applyTombstones(_unionSetlists(…))`
   still puts applyTombstones at the lower index while doing the wrong thing.
   The property that actually matters is that the union CONSUMES the pruned
   list, so that is what is asserted. */
check('_absorbSetlistSync() prunes BEFORE the union (the union consumes the pruned list)',
    !!absorbSetlistBody
    && /const\s+pruned\s*=\s*this\.app\.setList\.applyTombstones\(/.test(absorbSetlistBody)
    && /_unionSetlists\(\s*pruned\s*,/.test(absorbSetlistBody));
check('_absorbSetlistSync() does NOT wrap the union in the prune (local-wins would resurrect)',
    !!absorbSetlistBody
    && !/applyTombstones\(\s*(this\.)?_unionSetlists\(/.test(stripComments(absorbSetlistBody)));
check('syncSetlists() surfaces the tombstones on its result object',
    !!syncSetlistsBody && /tombstones:/.test(syncSetlistsBody));

/* The cap removal's replacement guard is a REJECTION, and the user is told —
   there is no partial success here to mistake for one. */
check('syncSetlists() handles a 413 (over-size body) distinctly',
    !!syncSetlistsBody && /res\.status\s*===\s*413/.test(syncSetlistsBody));
/* #1662 — window widened 400 → 1200. The 413 branch grew a reason-switch
   (too_many_songs names the offending list from the response body, vs the
   generic body_too_large message), so the first showToast now sits further
   from the `413` token. Still a LOCAL window — it proves the 413 branch reaches
   a toast, not silence — just sized to the real (correct) code rather than to
   the old single-message branch (rule #34: widen a guard that fails on correct
   code, don't delete it). */
check('the 413 branch tells the user rather than failing silently',
    !!syncSetlistsBody && /413[\s\S]{0,1200}?showToast/.test(stripComments(syncSetlistsBody)));

/* Failure KINDS are distinguished by STATUS, never by matching the server's
   prose (rule #35 — reword a server sentence and the UI degrades silently).
   Banning the PHRASE outright would flag the legitimate user-facing toast
   copy, and a guard that fails on correct code gets weakened or deleted
   rather than fixed (rule #34) — so what is banned is the ACT of inspecting
   the server's message to decide what happened. */
check('failure kinds are branched on res.status, never on the server error TEXT',
    !!syncSetlistsBody
    && /res\.status\s*===\s*\d{3}/.test(syncSetlistsBody)
    && !/(data|json|body)\??\.(error|message)\s*(\.(includes|match|startsWith|indexOf|test)\(|[=!]==?\s*['"`])/
        .test(stripComments(syncSetlistsBody)));

/* Expiry: captured as an absolute UTC instant, rendered from the stored
   value, and never decided locally — the server owns the conversion. */
check('setlist.js has an expiry picker', /showExpiryDialog\s*\(/.test(setlistSrc));
check('setlist.js renders an expiry badge', /expiryBadge\s*\(/.test(setlistSrc));
check('setExpiry() persists through the normal saveAll() path (so it syncs)',
    (() => { const b = methodBody(setlistSrc, 'setExpiry'); return !!b && /saveAll\(/.test(b); })());
check('the expiry is converted to UTC before it leaves the client',
    (() => { const b = methodBody(setlistSrc, 'showExpiryDialog'); return !!b && /toISOString\(\)/.test(b); })());
/* A bare `new Date('YYYY-MM-DD HH:MM:SS')` is not a defined format and is
   parsed as LOCAL time by some engines — an expiry silently off by the
   viewer's offset. The parser must be explicit about the value being UTC. */
check("stored expiries are parsed explicitly as UTC (not a bare `new Date(' ')`)",
    (() => { const b = methodBody(setlistSrc, '_parseExpiry'); return !!b && /\+\s*'Z'/.test(b); })());

/* The client must not second-guess the server on whether something expired. */
check('the client never deletes a set list locally on expiry (the server converts it)',
    !/expired[\s\S]{0,120}?this\.delete\(/i.test(setlistNoComments));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll user-sync client assertions passed.');
