/**
 * iHymns — Revision diff view unit tests (#1628 item 4)
 *
 * Exercises the pure builder `diffSnapshots(before, after)` exposed by
 *   appWeb/public_html/manage/editor/v2/revisions-tab.js
 *
 * Scope: ONLY the pure diff function. mountRevisionsTab() (the DOM-building
 * half of that same file) touches `document`/`window`, which don't exist
 * under Node — diffSnapshots() was extracted specifically so this file can
 * exercise the comparison logic without a browser (CLAUDE.md rule #34: a
 * behavioural guard over the real function, not a source-text scan).
 *
 * The server side of this feature (api2.php's `revision_get` before-snapshot
 * resolution ladder: previousData -> priorRevision -> none) is guarded
 * separately by tests/php/test-editor-api2-contract.php, since PHP source
 * isn't reachable from a Node process.
 *
 *   USAGE:  node tests/test-revision-diff.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */
import assert from 'node:assert/strict';

import { diffSnapshots } from '../appWeb/public_html/manage/editor/v2/revisions-tab.js';

let passed = 0, failed = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}: ${err.message}`); }
}

/* -------------------------------------------------------------------------
 * Fixtures — the v2 full-snapshot shape ({song, components, credits, tags,
 * links}) ed2_buildSongSnapshot() produces, mirrored here by hand so this
 * file has no dependency on a running server. Field names match
 * lyricLinesEditableComponents()'s component shape (type/number/label/
 * language/lines) and loadExternalLinksForRow()'s link shape (url).
 * ---------------------------------------------------------------------- */

function baseSnapshot() {
    return {
        song: { Title: 'Amazing Grace', Number: 1, Verified: 1 },
        components: [
            { id: 1, type: 'verse', number: 1, label: null, language: null, lines: ['Amazing grace how sweet the sound', 'That saved a wretch like me'] },
            { id: 2, type: 'chorus', number: 1, label: null, language: null, lines: ['Praise God'] },
        ],
        credits: {
            writers: [{ id: 10, name: 'John Newton' }],
        },
        tags: [{ id: 5, name: 'Grace' }],
        links: [{ typeId: 1, url: 'https://example.com/a', note: '', verified: 0, sortOrder: 0 }],
    };
}

function clone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

/* ========================================================================
 * 1. Scalar change is detected
 * ===================================================================== */
test('a scalar change (Title) is detected', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.song.Title = 'Amazing Grace (New Britain)';

    const diff = diffSnapshots(before, after);
    assert.equal(diff.hasChanges, true);
    assert.equal(diff.legacy, false);
    const titleChange = diff.scalars.find((s) => s.key === 'Title');
    assert.ok(titleChange, 'expected a scalar entry for Title');
    assert.equal(titleChange.before, 'Amazing Grace');
    assert.equal(titleChange.after, 'Amazing Grace (New Britain)');
});

test('an unchanged scalar does NOT appear in the diff', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.song.Title = 'Changed Title';
    const diff = diffSnapshots(before, after);
    assert.equal(diff.scalars.some((s) => s.key === 'Number'), false);
});

/* ========================================================================
 * 2. Component add/remove/text-change BY POSITION
 * ===================================================================== */
test('a component added at the end (by position) is detected', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.components.push({ id: 3, type: 'bridge', number: 1, label: null, language: null, lines: ['New bridge line'] });

    const diff = diffSnapshots(before, after);
    assert.equal(diff.hasChanges, true);
    assert.equal(diff.components.added.length, 1);
    assert.equal(diff.components.added[0].index, 2);
    assert.equal(diff.components.added[0].type, 'bridge');
    assert.equal(diff.components.added[0].lineCount, 1);
    assert.equal(diff.components.removed.length, 0);
    assert.equal(diff.components.changed.length, 0);
});

test('a component removed from the end (by position) is detected', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.components.pop(); // drop the chorus

    const diff = diffSnapshots(before, after);
    assert.equal(diff.hasChanges, true);
    assert.equal(diff.components.removed.length, 1);
    assert.equal(diff.components.removed[0].index, 1);
    assert.equal(diff.components.removed[0].type, 'chorus');
    assert.equal(diff.components.added.length, 0);
    assert.equal(diff.components.changed.length, 0);
});

test('a component text change at the same position is detected (line count + first differing line)', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.components[0].lines[0] = 'Amazing grace how great the sound';

    const diff = diffSnapshots(before, after);
    assert.equal(diff.hasChanges, true);
    assert.equal(diff.components.changed.length, 1);
    const changed = diff.components.changed[0];
    assert.equal(changed.index, 0);
    assert.equal(changed.linesChanged, 1);
    assert.ok(changed.firstDiff, 'expected a firstDiff entry');
    assert.equal(changed.firstDiff.lineIndex, 0);
    assert.equal(changed.firstDiff.before, 'Amazing grace how sweet the sound');
    assert.equal(changed.firstDiff.after, 'Amazing grace how great the sound');
    assert.equal(diff.components.added.length, 0);
    assert.equal(diff.components.removed.length, 0);
});

test('a component field change (type) with no line change is still reported as changed', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.components[1].type = 'chorus2'; // same lines, different type at the same position

    const diff = diffSnapshots(before, after);
    assert.equal(diff.components.changed.length, 1);
    assert.equal(diff.components.changed[0].linesChanged, 0);
    assert.deepEqual(diff.components.changed[0].fieldsChanged, ['type']);
});

/* ========================================================================
 * 3. Credits / tags / links — added/removed name sets
 * ===================================================================== */
test('credits/tags/links added-removed name sets are detected', () => {
    const before = baseSnapshot();
    const after = clone(before);
    after.credits.writers.push({ id: 11, name: 'Second Writer' });
    after.tags = [];
    after.links.push({ typeId: 2, url: 'https://example.com/b', note: '', verified: 0, sortOrder: 1 });

    const diff = diffSnapshots(before, after);
    assert.deepEqual(diff.credits.writers, { added: ['Second Writer'], removed: [] });
    assert.deepEqual(diff.tags, { added: [], removed: ['Grace'] });
    assert.deepEqual(diff.links, { added: ['https://example.com/b'], removed: [] });
});

/* ========================================================================
 * 4. Identical snapshots -> empty diff
 * ===================================================================== */
test('identical snapshots produce an empty diff', () => {
    const before = baseSnapshot();
    const after = clone(before); // deep-equal but a different object graph
    const diff = diffSnapshots(before, after);
    assert.equal(diff.hasChanges, false);
    assert.equal(diff.legacy, false);
    assert.deepEqual(diff.scalars, []);
    assert.deepEqual(diff.components, { added: [], removed: [], changed: [] });
    assert.deepEqual(diff.credits, {});
    assert.deepEqual(diff.tags, { added: [], removed: [] });
    assert.deepEqual(diff.links, { added: [], removed: [] });
});

/* ========================================================================
 * 5. A legacy bare-row vs v2-shape pair does NOT throw
 * ===================================================================== */
test('a legacy bare-tblSongs-row snapshot vs a v2-shape snapshot does not throw, and is flagged legacy', () => {
    // The pre-#1743 v1 shape: the row itself, no `song`/`components` siblings.
    const legacyBefore = { SongId: 'MP-1', Title: 'Old Title', Number: 1, Verified: 0 };
    const v2After = baseSnapshot();
    v2After.song.Title = 'New Title';

    let diff;
    assert.doesNotThrow(() => { diff = diffSnapshots(legacyBefore, v2After); });
    assert.equal(diff.legacy, true);
    // Scalars still line up (both sides use the same Uppercase tblSongs column names).
    const titleChange = diff.scalars.find((s) => s.key === 'Title');
    assert.ok(titleChange, 'expected the scalar compare to still work across the legacy/v2 pair');
    assert.equal(titleChange.before, 'Old Title');
    assert.equal(titleChange.after, 'New Title');
    // Structured sections never get invented for the side that lacks them.
    assert.deepEqual(diff.components, { added: [], removed: [], changed: [] });
    assert.deepEqual(diff.credits, {});
});

test('two legacy bare-row snapshots (both non-v2) do not throw and compare scalars only', () => {
    const before = { SongId: 'MP-1', Title: 'A' };
    const after = { SongId: 'MP-1', Title: 'B' };
    let diff;
    assert.doesNotThrow(() => { diff = diffSnapshots(before, after); });
    assert.equal(diff.legacy, true);
    assert.equal(diff.hasChanges, true);
});

test('null/undefined snapshots do not throw', () => {
    assert.doesNotThrow(() => diffSnapshots(null, baseSnapshot()));
    assert.doesNotThrow(() => diffSnapshots(undefined, undefined));
    const diff = diffSnapshots(null, null);
    assert.equal(diff.legacy, true);
    assert.equal(diff.hasChanges, false);
});

console.log(`\nRevision diff view: ${passed} passed, ${failed} failed`);
if (failed) { failures.forEach(f => console.log(`  - ${f.name}: ${f.error}`)); process.exit(1); }
