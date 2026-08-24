/**
 * iHymns — Per-field revision BLAME unit tests (#1122)
 *
 * Exercises the pure builders `canonicalScalarsOf()` + `blameFromSnapshots()`
 * exposed by appWeb/public_html/manage/editor/v2/revisions-tab.js — the
 * whole-history walk that answers "who last changed this field, and when".
 *
 * Scope: ONLY the pure functions (no DOM), the sibling of
 * tests/test-revision-diff.js. The server bulk read (api2.php
 * `revision_snapshots`) is guarded by tests/php/test-editor-api2-contract.php.
 *
 * THE HAZARD under test: three snapshot shapes coexist in
 * tblSongRevisions.NewData — the v2 full snapshot ({song:{Uppercase…},…}), a
 * bare tblSongs row (Uppercase), and the old editor-payload shape (lowercase
 * keys). Blame must fold all three to one canonical column key set, so a
 * `title` from an old payload and a `Title` from a v2 snapshot are the SAME
 * field — and must distinguish a field ABSENT in an older shape from a field
 * CLEARED (present -> empty).
 *
 *   USAGE:  node tests/test-revision-blame.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */
import assert from 'node:assert/strict';

import { canonicalScalarsOf, blameFromSnapshots } from '../appWeb/public_html/manage/editor/v2/revisions-tab.js';

let passed = 0, failed = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}: ${err.message}`); }
}

/* The served field map (a representative subset of ED2_META_FIELDS). */
const FIELD_MAP = {
    title: 'Title', number: 'Number', copyright: 'Copyright', ccli: 'Ccli',
    subtitle: 'Subtitle', verified: 'Verified', songbook: 'SongbookAbbr', hasAudio: 'HasAudio',
};
const NO_ROLLBACK = ['songbook', 'hasAudio', 'hasSheetMusic'];

/* Shape builders. */
const v2 = (song, extra) => Object.assign({ song: song, components: [], credits: {}, tags: [], links: [] }, extra || {});
const bareRow = (cols) => Object.assign({}, cols);                 // Uppercase, no .song/.components
const payload = (fields) => Object.assign({ components: [] }, fields); // lowercase keys, top-level components
const row = (id, username, newData, userId) => ({ id, action: 'edit', createdAt: '2026-01-0' + id + ' 00:00:00', userId: (userId ?? id), username, newData });
const blameByKey = (arr) => Object.fromEntries(arr.map((e) => [e.key, e]));

console.log('1 — canonicalScalarsOf folds all three shapes to canonical Column keys');
test('v2 shape reads .song columns', () => {
    const c = canonicalScalarsOf(v2({ Title: 'Grace', Number: 5 }), FIELD_MAP);
    assert.equal(c.Title.value, 'Grace');
    assert.equal(c.Number.value, 5);
    assert.ok(c.Title.present === true);
});
test('bare tblSongs row reads its own Uppercase columns', () => {
    const c = canonicalScalarsOf(bareRow({ Title: 'Grace', Copyright: '(c) 2020' }), FIELD_MAP);
    assert.equal(c.Title.value, 'Grace');
    assert.equal(c.Copyright.value, '(c) 2020');
});
test('payload shape folds lowercase keys to canonical columns', () => {
    const c = canonicalScalarsOf(payload({ title: 'Grace', copyright: '(c) 2019' }), FIELD_MAP);
    assert.equal(c.Title.value, 'Grace');       // title -> Title
    assert.equal(c.Copyright.value, '(c) 2019'); // copyright -> Copyright
});
test('a field absent in a shape is OMITTED (not defaulted)', () => {
    const c = canonicalScalarsOf(payload({ title: 'Grace' }), FIELD_MAP);
    assert.ok(!('Subtitle' in c), 'Subtitle must be absent, not null');
    assert.ok(!('Copyright' in c));
});
test('derived/noise columns are dropped (not in fieldMap)', () => {
    const c = canonicalScalarsOf(v2({ Title: 'Grace', UpdatedAt: '2026-01-01', NormalizedTitle: 'grace' }), FIELD_MAP);
    assert.ok(!('UpdatedAt' in c) && !('NormalizedTitle' in c));
});

console.log('\n2 — last-writer attribution across a scalar change');
test('the revision that changed a field is the one blamed (newest wins)', () => {
    const rows = [
        row(3, 'carol', v2({ Title: 'Grace', Copyright: '(c) 2021' })),   // newest — changed Copyright
        row(2, 'bob',   v2({ Title: 'Grace', Copyright: '(c) 2020' })),   // changed Title? no — set Title
        row(1, 'alice', v2({ Title: 'Amazing', Copyright: '(c) 2020' })), // oldest
    ];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Copyright.verdict, 'changed');
    assert.equal(b.Copyright.last.username, 'carol');
    assert.equal(b.Copyright.last.revisionId, 3);
    assert.equal(b.Copyright.last.before, '(c) 2020');
    assert.equal(b.Copyright.last.after, '(c) 2021');
    // Title changed Amazing->Grace at rev2 (bob), unchanged since
    assert.equal(b.Title.verdict, 'changed');
    assert.equal(b.Title.last.username, 'bob');
    assert.equal(b.Title.currentValue, 'Grace');
});

console.log('\n3 — shape boundary folds: an unchanged value across payload<->v2 is NOT a change');
test('title unchanged across a payload->v2 boundary is firstRecorded, not changed', () => {
    const rows = [
        row(2, 'bob',   v2({ Title: 'Grace', Copyright: '(c) 2020' })), // newest, v2
        row(1, 'alice', payload({ title: 'Grace', copyright: '(c) 2019' })), // oldest, payload
    ];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Title.verdict, 'firstRecorded'); // same value both sides -> never "changed"
    assert.equal(b.Title.firstRecorded.revisionId, 1);
    assert.equal(b.Copyright.verdict, 'changed');   // (c) 2019 -> (c) 2020
    assert.equal(b.Copyright.last.username, 'bob');
});

console.log('\n4 — absent != cleared');
test('absent-in-older-shape -> present is firstRecorded, not a change', () => {
    const rows = [
        row(2, 'bob',   v2({ Title: 'Grace', Subtitle: 'A hymn' })), // Subtitle appears
        row(1, 'alice', payload({ title: 'Grace' })),               // no subtitle key at all
    ];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Subtitle.verdict, 'firstRecorded');
    assert.equal(b.Subtitle.firstRecorded.revisionId, 2);
});
test('a present->empty CLEAR is a real change, blamed', () => {
    const rows = [
        row(2, 'bob',   v2({ Title: 'Grace', Copyright: '' })),          // cleared
        row(1, 'alice', v2({ Title: 'Grace', Copyright: '(c) 2019' })),
    ];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Copyright.verdict, 'changed');
    assert.equal(b.Copyright.last.before, '(c) 2019');
    assert.equal(b.Copyright.last.after, '');
});

console.log('\n5 — null newData rows are bridged, never invent a change');
test('an undecodable (null) row between two real ones is skipped', () => {
    const rows = [
        row(3, 'carol', v2({ Title: 'Grace 3' })),
        row(2, 'bob',   null),                        // undecodable
        row(1, 'alice', v2({ Title: 'Grace 1' })),
    ];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Title.verdict, 'changed');
    assert.equal(b.Title.last.username, 'carol'); // bridged over bob's null row
    assert.equal(b.Title.currentValue, 'Grace 3');
});

console.log('\n6 — unchangedInWindow: present since base, never changed -> no author claimed');
test('a field present in base and never touched is unchangedInWindow', () => {
    const rows = [
        row(2, 'bob',   v2({ Title: 'New', Verified: 1 })),
        row(1, 'alice', v2({ Title: 'Old', Verified: 1 })),
    ];
    const base = v2({ Title: 'Old', Verified: 1 });
    const b = blameByKey(blameFromSnapshots(rows, base, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Verified.verdict, 'unchangedInWindow');
    assert.equal(b.Verified.last, null);
    assert.equal(b.Verified.firstRecorded, null);
    assert.equal(b.Title.verdict, 'changed'); // Old(base) -> ... -> New(rev2)
});

console.log('\n7 — edge cases');
test('zero revisions -> empty blame', () => {
    assert.deepEqual(blameFromSnapshots([], null, FIELD_MAP, NO_ROLLBACK), []);
});
test('only base, no attributable rows -> empty blame', () => {
    assert.deepEqual(blameFromSnapshots([], v2({ Title: 'X' }), FIELD_MAP, NO_ROLLBACK), []);
});
test('a single revision -> every present field is firstRecorded at it', () => {
    const b = blameByKey(blameFromSnapshots([row(1, 'alice', v2({ Title: 'Grace' }))], null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.Title.verdict, 'firstRecorded');
    assert.equal(b.Title.firstRecorded.revisionId, 1);
});

console.log('\n8 — canRevert honours noRollback');
test('songbook / hasAudio are not revertable; a normal field is', () => {
    const rows = [row(1, 'alice', v2({ Title: 'Grace', SongbookAbbr: 'MP', HasAudio: 1 }))];
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.equal(b.SongbookAbbr.canRevert, false);
    assert.equal(b.HasAudio.canRevert, false);
    assert.equal(b.Title.canRevert, true);
    assert.equal(b.Title.field, 'title'); // inverted from the fieldMap
});
test('only currently-present fields get a blame row', () => {
    const rows = [row(1, 'alice', v2({ Title: 'Grace' }))]; // no Copyright now
    const b = blameByKey(blameFromSnapshots(rows, null, FIELD_MAP, NO_ROLLBACK));
    assert.ok('Title' in b && !('Copyright' in b));
});

console.log('');
if (failed > 0) {
    console.error(`FAILED: ${failed} assertion(s) failed.`);
    failures.forEach((f) => console.error(`  - ${f.name}: ${f.error}`));
    process.exit(1);
}
console.log(`All ${passed} revision-blame assertions passed.`);
process.exit(0);
