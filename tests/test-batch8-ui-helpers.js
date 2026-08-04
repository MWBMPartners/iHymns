/**
 * iHymns — the #1671 Batch-8 UI modules BEHAVE correctly
 * ======================================================
 *
 * ELI5: the three new screens (signed-in devices, your song requests, the song
 * key badge) each contain a handful of small decision-making functions. This
 * file IMPORTS those functions, CALLS them, and checks the answers.
 *
 * WHY IT CALLS RATHER THAN READS
 * ------------------------------
 * Every property asserted here lives in a pure exported function, so it has a
 * runtime handle and the guard uses it. Reading the source for the right words
 * would assert vocabulary, and vocabulary survives any mutation that keeps the
 * words — the lesson `tests/php/test-transaction-fatal.php` was written to
 * record. The one thing here that genuinely has NO runtime handle is the
 * agreement between the editor's key dropdown and the SERVER's key regex, and
 * that is handled below by re-reading the server's own literal each run rather
 * than by copying it (see "CROSS-FILE AGREEMENT").
 *
 * These modules import `js/utils/api-client.js` and `js/constants.js`, neither
 * of which touches a browser global at module scope, so a plain `import()`
 * under Node works with no DOM stubs at all. Only the DOM-wiring functions
 * reach for `document`/`window`, and this file does not call those.
 *
 * USAGE:
 *   node tests/test-batch8-ui-helpers.js
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');
const MODULES    = path.join(ROOT, 'appWeb', 'public_html', 'js', 'modules');
const V2         = path.join(ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'v2');
const SONG_KEY_PHP = path.join(ROOT, 'appWeb', 'public_html', 'includes', 'song_key.php');

let failed = 0;
function ok(label, cond, detail = '') {
    console.log((cond ? '  ✅ ' : '  ❌ ') + label);
    if (!cond) {
        failed++;
        if (detail) console.log('       ' + detail);
    }
}

const load = (dir, file) => import(pathToFileURL(path.join(dir, file)).href);

const datetime = await load(path.join(ROOT, 'appWeb', 'public_html', 'js', 'utils'), 'datetime.js');
const devices  = await load(MODULES, 'devices.js');
const requests = await load(MODULES, 'my-song-requests.js');
const songKey  = await load(MODULES, 'song-key.js');
const keyPanel = await load(V2, 'song-key-panel.js');

console.log('\nshared — js/utils/datetime.js\n');

/* ======================================================================
 * THESE ASSERT THE NORMALISED STRING, NOT THE PARSED NUMBER — and that is
 * the whole point.
 *
 * A first draft of this suite asserted only the parsed epoch, and a mutation
 * that DELETED the space-to-`T` normalisation entirely stayed GREEN: V8
 * happily parses `2026-07-28 12:00:00Z`, so the number came out right in the
 * one engine the test runs in, while Safari and older engines would have got
 * NaN and shown no age at all. Asserting the intermediate string is what gives
 * the property a runtime handle in an engine that cannot otherwise show the
 * difference.
 * ====================================================================== */
ok('a MySQL DATETIME gets a T separator AND an explicit Z',
   datetime.normaliseServerTimestamp('2026-07-28 09:00:00') === '2026-07-28T09:00:00Z',
   'got ' + JSON.stringify(datetime.normaliseServerTimestamp('2026-07-28 09:00:00'))
   + ' — a zone-less string is read as LOCAL time by an engine that accepts it '
   + 'at all, which shifts every age by the reader\'s UTC offset');
ok('a string already carrying Z is left alone',
   datetime.normaliseServerTimestamp('2026-07-28T09:00:00Z') === '2026-07-28T09:00:00Z');
ok('an explicit offset is NOT clobbered with a second zone',
   datetime.normaliseServerTimestamp('2026-07-31T10:00:00+02:00') === '2026-07-31T10:00:00+02:00',
   'appending Z here would silently move the instant by the offset');
ok('a compact offset (+0200) is also recognised as zoned',
   datetime.normaliseServerTimestamp('2026-07-31T10:00:00+0200') === '2026-07-31T10:00:00+0200');
for (const bad of ['', '   ', null, undefined, 42, {}, []]) {
    ok('normaliseServerTimestamp returns null for ' + JSON.stringify(bad),
       datetime.normaliseServerTimestamp(bad) === null);
}
ok('serverTimestampToMs returns null (not NaN) for junk',
   datetime.serverTimestampToMs('not a date') === null,
   'NaN leaks "Invalid Date" onto the page because every comparison with it is false');
ok('serverTimestampToMs reads a MySQL DATETIME as UTC',
   datetime.serverTimestampToMs('2026-07-28 09:00:00') === Date.UTC(2026, 6, 28, 9, 0, 0));

console.log('\n#1671 F1 — devices.js\n');

/* ---- deviceLabel: a row with nothing volunteered must still be nameable ---
 * DeviceName / Platform / AppVersion shipped live-dormant in #1511 and are all
 * nullable; the web PWA's own token has all three NULL, so the fallback chain
 * is the NORMAL path here, not an edge case. An empty label would render a row
 * the user cannot tell apart from any other. */
ok('deviceLabel prefers the volunteered name',
   devices.deviceLabel({ deviceName: "Lance's iPhone", platform: 'ios' }) === "Lance's iPhone");
ok('deviceLabel falls back to the platform',
   devices.deviceLabel({ deviceName: null, platform: 'iPadOS' }) === 'iPadOS device');
ok('deviceLabel never returns empty for a bare row',
   devices.deviceLabel({}) === 'Unnamed device');
ok('deviceLabel never returns empty for null',
   devices.deviceLabel(null) === 'Unnamed device');
ok('deviceLabel ignores a whitespace-only name',
   devices.deviceLabel({ deviceName: '   ', platform: 'web' }) === 'web device');

/* ---- relativeTime: the two timestamp shapes the server actually sends ----
 * LastSeenAt / CreatedAt arrive as MySQL 'YYYY-MM-DD HH:MM:SS' (no zone),
 * ExpiresAt as ISO-8601 with a Z. `Date.parse` of the former is
 * implementation-defined, which is why the helper normalises rather than
 * trusting it. An unreadable value must yield '' — "NaN days ago" on screen is
 * worse than no age at all. */
const NOW = Date.parse('2026-07-31T12:00:00Z');
ok('relativeTime parses a MySQL DATETIME (space separator, no zone)',
   devices.relativeTime('2026-07-28 12:00:00', NOW) === '3 days ago',
   'got ' + JSON.stringify(devices.relativeTime('2026-07-28 12:00:00', NOW)));
ok('relativeTime parses an ISO-8601 Z timestamp',
   devices.relativeTime('2026-07-31T09:00:00Z', NOW) === '3 hours ago');
ok('relativeTime respects an explicit offset rather than re-appending Z',
   devices.relativeTime('2026-07-31T10:00:00+02:00', NOW) === '4 hours ago',
   'got ' + JSON.stringify(devices.relativeTime('2026-07-31T10:00:00+02:00', NOW)));
ok('relativeTime says "just now" under 90 seconds',
   devices.relativeTime('2026-07-31 11:59:30', NOW) === 'just now');
ok('a FUTURE timestamp (clock skew) is "just now", never a negative age',
   devices.relativeTime('2026-08-05 00:00:00', NOW) === 'just now',
   'a negative age is caught by the same `< 90` arm — there is deliberately no '
   + 'separate `< 0` branch, because one was provably unreachable');
ok('singular is used at exactly one unit',
   devices.relativeTime('2026-07-30 12:00:00', NOW) === '1 day ago');
for (const bad of ['', '   ', 'not a date', null, undefined, 42, {}]) {
    ok('relativeTime returns "" for ' + JSON.stringify(bad),
       devices.relativeTime(bad, NOW) === '');
}

/* ---- deviceSecondaryLine ------------------------------------------------ */
ok('deviceSecondaryLine joins platform, version and age',
   devices.deviceSecondaryLine(
       { platform: 'android', appVersion: '2.1.0', lastSeenAt: '2026-07-31 09:00:00' }, NOW
   ) === 'android · v2.1.0 · 3 hours ago');
ok('deviceSecondaryLine falls back to createdAt when lastSeenAt is null',
   devices.deviceSecondaryLine(
       { platform: null, appVersion: null, lastSeenAt: null, createdAt: '2026-07-30 12:00:00' }, NOW
   ) === '1 day ago',
   'lastSeenAt is NULL until a client is seen after the #1511 migration ran');
ok('deviceSecondaryLine returns "" when there is nothing to say',
   devices.deviceSecondaryLine({}, NOW) === '');

/* ---- signOutMessageForStatus: rule #35, branch on the NUMBER ------------
 * The point of this function is that it is decided by status, so the assertion
 * that matters is that different statuses give DIFFERENT answers — a version
 * that ignored the status and always echoed the server's sentence would pass
 * any single-case check. */
const statuses = [401, 403, 404, 429, 500];
const messages = statuses.map((s) => devices.signOutMessageForStatus(s, 'Server prose.'));
ok('every handled status produces a distinct message',
   new Set(messages).size === statuses.length,
   JSON.stringify(messages));
ok('401 talks about signing in, not about permissions',
   /sign in/i.test(devices.signOutMessageForStatus(401)));
ok('404 reads as already-done, not as an error',
   /already signed out/i.test(devices.signOutMessageForStatus(404)));
ok('429 mentions waiting',
   /wait|too many/i.test(devices.signOutMessageForStatus(429)));
ok('a handled status IGNORES the server prose (the status is the contract)',
   devices.signOutMessageForStatus(404, 'Unknown device.') ===
   devices.signOutMessageForStatus(404, ''),
   'if these differ, the client is being steered by a sentence somebody can reword');
ok('an UNHANDLED status prefers the server prose, which knows more than we do',
   devices.signOutMessageForStatus(418, 'I am a teapot.') === 'I am a teapot.');
ok('…and falls back to a message naming the status when there is no prose',
   devices.signOutMessageForStatus(418).includes('418'));
ok('a non-string serverMessage cannot crash the chooser',
   typeof devices.signOutMessageForStatus(500, null) === 'string');

/* ---- withoutDevice / hasOtherDevices ------------------------------------ */
const three = [{ id: 'a' }, { id: 'b', isCurrent: true }, { id: 'c' }];
ok('withoutDevice removes exactly the named row',
   JSON.stringify(devices.withoutDevice(three, 'a')) === JSON.stringify([three[1], three[2]]));
ok('withoutDevice returns a NEW array (never mutates the caller\'s)',
   devices.withoutDevice(three, 'a') !== three && three.length === 3);
ok('withoutDevice is a no-op for an unknown id',
   devices.withoutDevice(three, 'zzz').length === 3);
ok('withoutDevice tolerates a non-array',
   Array.isArray(devices.withoutDevice(null, 'a')) && devices.withoutDevice(null, 'a').length === 0);
ok('hasOtherDevices is true when a non-current row exists',
   devices.hasOtherDevices(three) === true);
ok('hasOtherDevices is false when only the current device is signed in',
   devices.hasOtherDevices([{ id: 'b', isCurrent: true }]) === false);
ok('hasOtherDevices is false for an empty list',
   devices.hasOtherDevices([]) === false);

console.log('\n#1671 F2 — my-song-requests.js\n');

/* ---- statusPresentation: rule #20, the vocabulary can GROW --------------
 * `tblSongRequests.Status` is a VARCHAR precisely so a new state does not need
 * an ALTER. So an unknown status must render as ITSELF — dropping it would
 * leave a row with no state, which reads as a bug rather than as a new state. */
for (const [status, label] of [
    ['pending', 'Pending'], ['reviewed', 'Reviewed'],
    ['added', 'Added'], ['declined', 'Declined'],
]) {
    const p = requests.statusPresentation(status);
    ok(`status "${status}" is known and labelled "${label}"`,
       p.known === true && p.label === label);
}
ok('the four known statuses have four DISTINCT badge classes',
   new Set(['pending', 'reviewed', 'added', 'declined']
       .map((s) => requests.statusPresentation(s).cls)).size === 4);
const grown = requests.statusPresentation('escalated');
ok('an UNKNOWN status still renders, labelled with the server\'s own word',
   grown.known === false && grown.label === 'Escalated',
   'the vocabulary is a growable VARCHAR (rule #20) — dropping a new value would hide the row\'s state');
ok('an empty status renders as "Unknown", never an empty badge',
   requests.statusPresentation('').label === 'Unknown');
ok('a null status cannot crash the renderer',
   requests.statusPresentation(null).label === 'Unknown');
ok('status matching is case-insensitive',
   requests.statusPresentation('PENDING').known === true);

/* ---- statusExplanation -------------------------------------------------- */
ok('every KNOWN status explains what happens next',
   ['pending', 'reviewed', 'added', 'declined']
       .every((s) => requests.statusExplanation(s, null) !== ''));
ok('the four explanations are distinct',
   new Set(['pending', 'reviewed', 'added', 'declined']
       .map((s) => requests.statusExplanation(s, null))).size === 4);
ok('an unknown status explains nothing rather than guessing',
   requests.statusExplanation('escalated', null) === '');
ok('"added" reads differently once a song id is attached',
   requests.statusExplanation('added', 'MP-1008') !== requests.statusExplanation('added', ''));

/* ---- requestSubtitle / formatDate --------------------------------------- */
/* The rendered date is locale-dependent (`toLocaleDateString`), so the
   assertion pins the STRUCTURE and requires the date part to be non-empty —
   rather than an `||` of two shapes, which would be satisfied by almost
   anything and is the "assertion that cannot fail" trap. */
const subtitle = requests.requestSubtitle({
    songbook: 'Mission Praise', songNumber: '1008', createdAt: '2026-07-28 09:00:00',
});
ok('requestSubtitle joins songbook, number and submitted date, in that order',
   subtitle.startsWith('Mission Praise · no. 1008 · submitted ')
   && subtitle.length > 'Mission Praise · no. 1008 · submitted '.length,
   'got ' + JSON.stringify(subtitle));
ok('requestSubtitle omits absent parts rather than leaving separators',
   requests.requestSubtitle({ songbook: '', songNumber: '', createdAt: null }) === '');
ok('requestSubtitle ignores whitespace-only fields',
   requests.requestSubtitle({ songbook: '  ', songNumber: '  ', createdAt: '' }) === '');
ok('formatDate returns "" for an unreadable value, never "Invalid Date"',
   requests.formatDate('not a date') === '' && requests.formatDate(null) === '');
ok('formatDate reads a MySQL TIMESTAMP',
   requests.formatDate('2026-07-28 09:00:00') !== '');

/* ---- loadMessageForStatus ----------------------------------------------- */
const loadMsgs = [401, 429, 503, 500].map(requests.loadMessageForStatus);
ok('every handled load status produces a distinct message',
   new Set(loadMsgs).size === 4, JSON.stringify(loadMsgs));
ok('401 invites a sign-in rather than reporting a failure',
   /sign in/i.test(requests.loadMessageForStatus(401)));

console.log('\n#1671 F3 — song-key.js (public song page)\n');

ok('keyDetailLine joins tempo and time signature',
   songKey.keyDetailLine({ tempo: 120, timeSignature: '4/4' }) === '120 BPM · 4/4');
ok('keyDetailLine omits a null tempo',
   songKey.keyDetailLine({ tempo: null, timeSignature: '3/4' }) === '3/4');
ok('keyDetailLine omits an EMPTY time signature (the NOT NULL column\'s "unset")',
   songKey.keyDetailLine({ tempo: 96, timeSignature: '' }) === '96 BPM',
   'the server stores "" for unspecified because TimeSignature is NOT NULL — '
   + 'rendering it would print a stray separator');
ok('keyDetailLine returns "" when both are absent',
   songKey.keyDetailLine({ tempo: null, timeSignature: '' }) === '');
ok('keyDetailLine tolerates a missing payload',
   songKey.keyDetailLine(undefined) === '');
ok('keyDetailLine ignores a zero tempo',
   songKey.keyDetailLine({ tempo: 0, timeSignature: '' }) === '');
ok('keyDetailLine ignores a non-numeric tempo rather than printing it',
   songKey.keyDetailLine({ tempo: '120', timeSignature: '' }) === '');

ok('hasDisplayableKey is true for a real key',
   songKey.hasDisplayableKey({ originalKey: 'G' }) === true);
ok('hasDisplayableKey is FALSE for a row with an empty OriginalKey',
   songKey.hasDisplayableKey({ originalKey: '' }) === false,
   'the column is NOT NULL DEFAULT "", so an empty row is possible and must not '
   + 'render an empty badge');
ok('hasDisplayableKey is false for null / undefined',
   songKey.hasDisplayableKey(null) === false && songKey.hasDisplayableKey(undefined) === false);

console.log('\n#1671 F3 — song-key-panel.js (v2 editor)\n');

const keyOptions = keyPanel.buildKeyOptions();
ok('the key dropdown offers a substantial list',
   Array.isArray(keyOptions) && keyOptions.length === 42,
   '7 roots x 3 accidentals x 2 qualities = 42; got ' + keyOptions.length);
ok('the list has no duplicates',
   new Set(keyOptions).size === keyOptions.length);
ok('the list contains the flats transpose.js knows about',
   ['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb'].every((k) => keyOptions.includes(k)));
ok('…and their minors',
   ['Bbm', 'Ebm', 'Dm', 'Gm', 'Cm', 'Fm'].every((k) => keyOptions.includes(k)));

/* ======================================================================
 * CROSS-FILE AGREEMENT — the one thing here with no runtime handle
 *
 * The editor's dropdown (JS) and `songKeyNormaliseKey()` (PHP) must accept the
 * same set, and nothing in either language can call the other. A comment
 * saying "keep these in sync" is the failure, not the fix (rule #35).
 *
 * The mechanism: RE-READ the server's own regex literal out of song_key.php on
 * every run and test each offered option against it. If somebody tightens the
 * server, this picks up the NEW regex and the offending options go red — it
 * cannot go stale, because it never holds a copy. If the literal cannot be
 * found at all the test FAILS LOUDLY rather than skipping: a check that
 * quietly stops checking is exactly the "wrong-but-green scanner" this
 * codebase keeps rediscovering.
 * ====================================================================== */
const phpSrc = fs.readFileSync(SONG_KEY_PHP, 'utf8');
const m = phpSrc.match(/preg_match\('\/\\A(\(\[A-Ga-g\]\)\(\[#b\]\?\)\(\[Mm\]\?\))\\z\/'/);
ok('the server key regex literal was located in includes/song_key.php',
   !!m,
   'song_key.php no longer contains the expected preg_match literal. Either the '
   + 'server changed shape (update this extraction deliberately) or this check '
   + 'has silently stopped checking — which is worse than not having it.');
if (m) {
    /* Translate the PHP character classes to a JS RegExp. `\A`/`\z` become
       `^`/`$` on a non-multiline JS regex, which anchor the whole string. */
    const serverRe = new RegExp('^' + m[1] + '$');
    const rejected = keyOptions.filter((k) => !serverRe.test(k));
    ok('EVERY key the editor offers is accepted by the server regex',
       rejected.length === 0,
       'the server would answer 400 for: ' + rejected.join(', '));
    /* And the reverse direction, so the dropdown is not silently narrower than
       the server: a curator should not have to hand-edit SQL to set a key the
       API would take. */
    const serverAccepts = [];
    for (const root of 'ABCDEFG') {
        for (const acc of ['', '#', 'b']) {
            for (const q of ['', 'm']) {
                const k = root + acc + q;
                if (serverRe.test(k)) serverAccepts.push(k);
            }
        }
    }
    const missing = serverAccepts.filter((k) => !keyOptions.includes(k));
    ok('…and the editor offers every key the server accepts (no silent gap)',
       missing.length === 0,
       'the API would take these but no curator can choose them: ' + missing.join(', '));
}

/* Time signatures must satisfy the server's own rule too — same mechanism. */
const tsMatch = phpSrc.match(/preg_match\('\/\\A(\\d\{1,2\}\\\/\\d\{1,2\})\\z\/'/);
ok('the server time-signature regex literal was located',
   !!tsMatch,
   'extraction failed — see the note above; do not let this silently skip');
if (tsMatch) {
    const tsRe = new RegExp('^' + tsMatch[1].replace(/\\\//g, '/') + '$');
    const badTs = keyPanel.TIME_SIGNATURES.filter(
        (t) => !tsRe.test(t) || Number(t.split('/')[0]) < 1 || Number(t.split('/')[1]) < 1
    );
    ok('EVERY time signature the editor offers is accepted by the server',
       badTs.length === 0, 'server would 400 for: ' + badTs.join(', '));
    ok('the time-signature list has no duplicates',
       new Set(keyPanel.TIME_SIGNATURES).size === keyPanel.TIME_SIGNATURES.length);
}

/* ---- saveMessageForStatus ----------------------------------------------- */
const panelStatuses = [400, 401, 403, 404, 429];
const panelMsgs = panelStatuses.map((s) => keyPanel.saveMessageForStatus(s, ''));
ok('every handled save status produces a distinct message',
   new Set(panelMsgs).size === panelStatuses.length, JSON.stringify(panelMsgs));
ok('401 explains the /manage-session-vs-app-API-cookie split, not "access denied"',
   /sign in again/i.test(keyPanel.saveMessageForStatus(401)),
   'a /manage sign-in mints the ihymns_auth cookie BEST-EFFORT, so a valid admin '
   + 'session with no cookie is a real state and needs its own sentence');
ok('403 names the entitlement',
   /edit_songs/.test(keyPanel.saveMessageForStatus(403)));
ok('400 PREFERS the server prose — it names the offending field',
   keyPanel.saveMessageForStatus(400, 'tempo must be a whole number…')
   === 'tempo must be a whole number…');
ok('…and still says something useful when the server sent no prose',
   keyPanel.saveMessageForStatus(400, '') !== '');
ok('an undefined status (a thrown non-HTTP error) still yields a string',
   typeof keyPanel.saveMessageForStatus(undefined, '') === 'string'
   && keyPanel.saveMessageForStatus(undefined, '') !== '');

console.log('');
if (failed === 0) {
    console.log('All Batch-8 UI helper assertions passed.');
    process.exit(0);
}
console.error(`${failed} assertion(s) failed.`);
process.exit(1);
