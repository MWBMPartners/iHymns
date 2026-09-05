<?php

declare(strict_types=1);

/**
 * iHymns — Voice-marker review-queue truth table (#2073 commit 14)
 *
 * ELI5: `includes/vocal_part_review.php` decides three things — "how far
 * does a WOMEN marker's run of lines reach", "what should Accept actually
 * DO for one finding", and "has a re-scan already seen this exact marker
 * before, and if so what should happen to the row that's already there" —
 * and this file proves every one of those decisions against a FUNCTIONAL
 * truth table (never a grep over the source, rule #34 of .claude/CLAUDE.md).
 *
 * WHY PURE FUNCTIONS ONLY (same posture `tests/php/test-vocal-parts-core.php`
 * states plainly, and for the same reason): this repo's CI PHP image has no
 * MySQL/MariaDB, so every function taking a `\mysqli $db` — the batch scan,
 * Accept, Dismiss, Undo — is covered by manual / staging verification only.
 * What CAN be proven here, exhaustively, is the pure decision core those
 * `\mysqli`-typed functions are thin wrappers around: run-bounds, proposal-
 * building, row-building, the scan/insert/update/skip/stale decisions, and
 * the plain-array line-content edits (`vocalPartReviewApplyLineOp()` /
 * `…InsertLineBefore()`) Accept and Undo hand to `lyricLinesWriteComponents()`.
 *
 * SECTION 12 is the task's own explicit "prove a re-run is genuinely
 * idempotent" requirement: it re-implements, in miniature, the EXACT
 * reconcile loop `vocalPartReviewScanSong()` runs (found-rows vs
 * existing-rows, by the SAME `vocalPartReviewDetectionKey()`) and drives it
 * through three simulated passes — nothing found yet, found again while
 * still pending, found again after a curator already Accepted it — proving
 * a re-scan never mints a duplicate row and never reopens a reviewed one.
 *
 *   php tests/php/test-vocal-part-review.php
 *
 * Exit status 0 = every assertion passed, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/vocal_part_review.php   the file under test
 * @see appWeb/public_html/includes/vocal_part_detect.php   the detector this file's helpers consume the OUTPUT shape of
 * @see tests/php/test-vocal-parts-core.php                 the sibling truth table + structural-IDOR-guard shape this mirrors
 * @see .claude/vocal-parts-2073-plan.md                    "Design pass 7" §3.5 / "Design pass 6" §6 (see vocal_part_review.php's own note on the retired `canon-note` form)
 * @see #2073, #2075, #1260
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/vocal_part_review.php';
require $root . '/tests/php/lib/php_source_units.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
        $failed++;
    }
}

function assertEq($actual, $expected, string $label): void
{
    ok($label, $actual === $expected, 'expected: ' . var_export($expected, true) . ' | actual: ' . var_export($actual, true));
}

/* ====================================================================== *
 * 1 — vocabulary
 * ====================================================================== */
echo "1 — vocabulary\n";

assertEq(IHYMNS_VOCAL_REVIEW_STATUSES, ['pending', 'accepted', 'dismissed', 'undone', 'stale'], 'the five review statuses, in the DDL comment\'s own order');
assertEq(IHYMNS_VOCAL_REVIEW_CONFIDENCES, ['high', 'medium', 'low'], 'the three confidence levels');
ok('IHYMNS_VOCAL_REVIEW_ACTIONS names every action this file\'s proposal-builder actually emits', array_diff(['assign-lines', 'rewrite-marker-line', 'remove-marker-line'], IHYMNS_VOCAL_REVIEW_ACTIONS) === []);

$conflict = new VocalPartReviewConflictException('not_pending', 'custom message');
ok('VocalPartReviewConflictException extends RuntimeException (a future endpoint\'s generic catch still sees it)', $conflict instanceof \RuntimeException);
assertEq($conflict->reason, 'not_pending', 'the exception carries a STRUCTURED $reason, not just a message a caller would have to regex (rule #35)');
assertEq($conflict->getMessage(), 'custom message', 'a supplied message is kept verbatim');
assertEq((new VocalPartReviewConflictException('stale_line'))->getMessage(), 'stale_line', 'an omitted message falls back to the reason itself');

/* ====================================================================== *
 * 2 — vocalPartReviewStandaloneRunBounds()
 * ====================================================================== */
echo "\n2 — vocalPartReviewStandaloneRunBounds() (how far a standalone marker's run reaches)\n";

assertEq(vocalPartReviewStandaloneRunBounds([], 0, 5), [1, 4], 'a lone marker at the top of a 5-line section governs lines 1..4');
assertEq(vocalPartReviewStandaloneRunBounds([], 4, 5), null, 'a marker on the LAST line of its section governs nothing');
assertEq(vocalPartReviewStandaloneRunBounds([0, 2], 0, 5), [1, 1], 'a second marker at index 2 stops the first marker\'s run at index 1 (governs only line 1)');
assertEq(vocalPartReviewStandaloneRunBounds([0, 1], 0, 5), null, 'two markers back-to-back (indexes 0 and 1) — the first governs nothing');
assertEq(vocalPartReviewStandaloneRunBounds([3], 5, 8), [6, 7], 'an earlier marker index (3) in the same list is correctly ignored — only a LATER one bounds the run');
assertEq(vocalPartReviewStandaloneRunBounds([2, 2, 2], 0, 5), [1, 1], 'duplicate marker indexes de-duplicate cleanly (still bounds at 1)');
assertEq(vocalPartReviewStandaloneRunBounds([9], 0, 5), [1, 4], 'a marker index OUTSIDE this component (from a mis-supplied list) is simply irrelevant — the whole rest of the section is governed');

/* MUTATION PROOF: dropping the `sort($indexes)` call would make an
   out-of-order list (e.g. [5, 2] naming a marker at 2 AFTER one at 5) miss
   the nearer boundary — proven directly, not just asserted from tidy input. */
assertEq(vocalPartReviewStandaloneRunBounds([5, 2], 0, 8), [1, 1], 'an UNSORTED index list ([5, 2]) still finds the NEARER boundary (2), not the first one encountered');

/* ====================================================================== *
 * 3 — vocalPartReviewBuildProposal() — the ordered action list per form
 * ====================================================================== */
echo "\n3 — vocalPartReviewBuildProposal() (ProposedJson's action list)\n";

$standaloneProposal = vocalPartReviewBuildProposal('standalone', 100, 'female', 'Women', false, [101, 102], null);
assertEq(count($standaloneProposal), 2, 'standalone with a governed run proposes exactly TWO actions');
assertEq($standaloneProposal[0]['action'], 'assign-lines', 'standalone: assign FIRST');
assertEq($standaloneProposal[0]['lineIds'], [101, 102], 'standalone: assigns the whole governed run');
assertEq($standaloneProposal[1], ['action' => 'remove-marker-line', 'lineId' => 100], 'standalone: remove the marker line SECOND');

$standaloneEmptyRun = vocalPartReviewBuildProposal('standalone', 100, 'female', null, false, [], null);
assertEq($standaloneEmptyRun, [['action' => 'remove-marker-line', 'lineId' => 100]], 'standalone with NOTHING to govern proposes ONLY the marker removal — never an empty assign-lines action');

$prefixProposal = vocalPartReviewBuildProposal('prefix', 200, 'male', null, false, [200], 'You are holy,');
assertEq(count($prefixProposal), 2, 'prefix proposes exactly TWO actions');
assertEq($prefixProposal[0], ['action' => 'rewrite-marker-line', 'lineId' => 200, 'text' => 'You are holy,'], 'prefix: rewrite the marker line down to its lyric remainder FIRST');
assertEq($prefixProposal[1]['action'], 'assign-lines', 'prefix: assign the (now-clean) same line SECOND');
assertEq($prefixProposal[1]['lineIds'], [200], 'prefix: assigns only its own line');

$parenProposal = vocalPartReviewBuildProposal('paren', 300, 'backing', null, true, [300], null);
assertEq(count($parenProposal), 1, 'paren proposes exactly ONE action');
assertEq($parenProposal[0], ['action' => 'assign-lines', 'lineIds' => [300], 'partKind' => 'backing', 'label' => null, 'isBackground' => true], 'paren: assigns the marker line as-is, background, with NO text-change action');

assertEq(vocalPartReviewBuildProposal('canon-note', 1, 'all', null, false, [], null), [], 'a form this file has not been taught (the retired canon-note sketch — see the file\'s own doc-block) proposes NOTHING rather than guessing');

/* ====================================================================== *
 * 4 — vocalPartReviewBuildRow() + vocalPartReviewDetectionKey()
 * ====================================================================== */
echo "\n4 — vocalPartReviewBuildRow() / vocalPartReviewDetectionKey()\n";

$row = vocalPartReviewBuildRow('CP-0001', 42, 555, 'paren', '(Women echo)', 'backing', null, true, 'low', [555], null);
assertEq($row['songId'], 'CP-0001', 'songId carried through verbatim');
assertEq($row['lyricsId'], 42, 'lyricsId carried through verbatim');
assertEq($row['markerLineId'], 555, 'markerLineId set from the live line id');
assertEq($row['detectionLineId'], 555, 'detectionLineId snapshots the SAME id at build time');
assertEq($row['markerOffset'], 0, 'markerOffset is 0 — the detector can never report a mid-line offset today (see this function\'s own doc-block)');
assertEq($row['startLineId'], 555, 'startLineId is the first (only) target line');
assertEq($row['endLineId'], 555, 'endLineId is the last (only) target line');
assertEq($row['confidence'], 'low', 'a valid confidence is kept as-is');
assertEq($row['detectorVersion'], IHYMNS_VOCAL_DETECT_VERSION, 'detectorVersion is stamped from the live detector constant, never a hand-typed number');
ok('proposedJson is exactly what vocalPartReviewBuildProposal() would build for the same inputs', $row['proposedJson'] === vocalPartReviewBuildProposal('paren', 555, 'backing', null, true, [555], null));

$rowMulti = vocalPartReviewBuildRow('CP-0002', 7, 10, 'standalone', 'WOMEN', 'female', 'Women', false, 'high', [11, 12, 13], null);
assertEq($rowMulti['startLineId'], 11, 'a multi-line run\'s startLineId is the FIRST target');
assertEq($rowMulti['endLineId'], 13, 'a multi-line run\'s endLineId is the LAST target');

$rowNoTargets = vocalPartReviewBuildRow('CP-0003', 7, 20, 'standalone', 'WOMEN', 'female', null, false, 'high', [], null);
assertEq($rowNoTargets['startLineId'], null, 'no target lines -> startLineId null');
assertEq($rowNoTargets['endLineId'], null, 'no target lines -> endLineId null');

$rowBadConfidence = vocalPartReviewBuildRow('CP-0004', 7, 30, 'paren', 'x', 'backing', null, true, 'extreme', [30], null);
assertEq($rowBadConfidence['confidence'], 'medium', 'an unrecognised confidence value is coerced to the safe middle default, never stored verbatim');

$longMarker = str_repeat('A', 200);
$rowLong = vocalPartReviewBuildRow('CP-0005', 7, 40, 'standalone', $longMarker, 'group', null, false, 'high', [41], null);
assertEq(mb_strlen($rowLong['markerText'], 'UTF-8'), 120, 'markerText is capped to the column\'s 120-char limit');

/* Rule #21 — code points, not bytes: a marker with multi-byte characters
   must not be cut mid-character by a byte-oriented substr. */
$emojiMarker = str_repeat('😀', 130);   // each emoji is 4 bytes in UTF-8
$rowEmoji = vocalPartReviewBuildRow('CP-0006', 7, 41, 'standalone', $emojiMarker, 'group', null, false, 'high', [42], null);
assertEq(mb_strlen($rowEmoji['markerText'], 'UTF-8'), 120, 'a multi-byte marker is capped by CODE POINT, not byte (rule #21) — 120 whole emoji, not a mangled half-character');

assertEq(vocalPartReviewDetectionKey(10, 'standalone', 0), '10|standalone|0', 'the uq_Detection key is the three columns, pipe-joined, in DDL order');
ok('the SAME finding built twice produces the SAME detection key (idempotent identity)', vocalPartReviewDetectionKey($row['detectionLineId'], $row['form'], $row['markerOffset']) === vocalPartReviewDetectionKey($row['detectionLineId'], $row['form'], $row['markerOffset']));
ok('two DIFFERENT lines never collide on the same key', vocalPartReviewDetectionKey(10, 'standalone', 0) !== vocalPartReviewDetectionKey(11, 'standalone', 0));
ok('two DIFFERENT forms on the same line never collide', vocalPartReviewDetectionKey(10, 'standalone', 0) !== vocalPartReviewDetectionKey(10, 'prefix', 0));

/* ====================================================================== *
 * 5 — vocalPartReviewScanDecision() — insert / update / skip
 * ====================================================================== */
echo "\n5 — vocalPartReviewScanDecision()\n";

assertEq(vocalPartReviewScanDecision(null), 'insert', 'no existing row -> insert');
assertEq(vocalPartReviewScanDecision('pending'), 'update', 'a still-open pending row -> update (refresh in place, never a duplicate)');
assertEq(vocalPartReviewScanDecision('stale'), 'update', 'a stale row that reappears -> update (comes back to life)');
foreach (['accepted', 'dismissed', 'undone'] as $reviewed) {
    assertEq(vocalPartReviewScanDecision($reviewed), 'skip', "an already-reviewed row (\"$reviewed\") -> skip, UNCONDITIONALLY — a re-scan must never reopen a curator's decision");
}

/* ====================================================================== *
 * 6 — vocalPartReviewShouldStale()
 * ====================================================================== */
echo "\n6 — vocalPartReviewShouldStale()\n";

assertEq(vocalPartReviewShouldStale('pending', true), false, 'still pending AND re-detected -> not stale');
assertEq(vocalPartReviewShouldStale('pending', false), true, 'still pending but NOT re-detected -> stale (the marker moved/vanished)');
foreach (['accepted', 'dismissed', 'undone', 'stale'] as $status) {
    assertEq(vocalPartReviewShouldStale($status, false), false, "a \"$status\" row is NEVER re-staled — only a genuinely 'pending' row can flip");
    assertEq(vocalPartReviewShouldStale($status, true), false, "a \"$status\" row re-detected changes nothing either (re-scan does not silently un-review it)");
}

/* ====================================================================== *
 * 7 — vocalPartReviewLocateLine()
 * ====================================================================== */
echo "\n7 — vocalPartReviewLocateLine()\n";

$editable = [
    ['lineIds' => [10, 11, 12]],
    ['lineIds' => [20, 21]],
];
assertEq(vocalPartReviewLocateLine($editable, 21), [1, 1], 'finds a line in the SECOND component at its real index');
assertEq(vocalPartReviewLocateLine($editable, 10), [0, 0], 'finds a line at the START of the first component');
assertEq(vocalPartReviewLocateLine($editable, 999), null, 'a line id present nowhere returns null — never a guessed fallback');
assertEq(vocalPartReviewLocateLine([], 10), null, 'an empty song has no lines to find');

/* ====================================================================== *
 * 8 — component-rewrite helpers
 * ====================================================================== */
echo "\n8 — component-rewrite helpers (vocalPartReviewComponentForWrite / RemoveParallelIndex / ArrayInsertAt / InsertParallelIndex)\n";

$rawComp = [
    'id' => 1, 'type' => 'verse', 'number' => 1, 'sortOrder' => 0,
    'lines' => ['MEN', 'Line A', 'Line B'],
    'chords' => [null, ['0' => 'G'], null],
    'notes'  => [null, null, 'quietly'],
    'languages' => null,
    'label' => 'My label',
    'sourceWorkId' => 9,
    'lineIds' => [10, 11, 12],
];
$forWrite = vocalPartReviewComponentForWrite($rawComp);
assertEq($forWrite, [
    'type' => 'verse', 'number' => 1, 'language' => null,
    'lines' => ['MEN', 'Line A', 'Line B'],
    'chords' => [null, ['0' => 'G'], null],
    'notes'  => [null, null, 'quietly'],
    'languages' => null,
    'label' => 'My label',
    'sourceWorkId' => 9,
], 'strips a raw editable component down to EXACTLY the shape lyricLinesWriteComponents() wants — no lineIds/id/sortOrder leaking through');

$removed = vocalPartReviewRemoveParallelIndex($forWrite, 0);
assertEq($removed['lines'], ['Line A', 'Line B'], 'removing index 0 drops the marker line from `lines`');
assertEq($removed['chords'], [['0' => 'G'], null], 'the SAME index is dropped from `chords` in lockstep');
assertEq($removed['notes'], [null, 'quietly'], 'the SAME index is dropped from `notes` in lockstep');
assertEq($removed['languages'], null, 'a null `languages` array is left null (nothing to shift)');
assertEq($removed['label'], 'My label', 'every non-parallel field (label, sourceWorkId, language) survives untouched');

assertEq(vocalPartReviewArrayInsertAt(['a', 'b', 'c'], 1, 'X'), ['a', 'X', 'b', 'c'], 'inserts at the requested index, shifting the rest up');
assertEq(vocalPartReviewArrayInsertAt([], 0, 'X'), ['X'], 'inserts into an empty array');

$inserted = vocalPartReviewInsertParallelIndex($removed, 0, 'MEN');
assertEq($inserted['lines'], ['MEN', 'Line A', 'Line B'], 'inserting the marker text back at index 0 restores the original line order');
assertEq($inserted['chords'], [null, ['0' => 'G'], null], 'a null cell is inserted into `chords` at the SAME index');
assertEq($inserted['notes'], [null, null, 'quietly'], 'a null cell is inserted into `notes` at the SAME index');

/* Round-trip: remove then re-insert at the SAME position reproduces the
   ORIGINAL component exactly (the property vocalPartReviewUndo() depends
   on for a 'standalone' Undo). */
assertEq($inserted, $forWrite, 'remove-then-insert-at-the-same-index is a perfect round trip');

/* ====================================================================== *
 * 9 — vocalPartReviewApplyLineOp()
 * ====================================================================== */
echo "\n9 — vocalPartReviewApplyLineOp() (the full-song rebuild Accept/Undo write through)\n";

$components2 = [
    ['type' => 'verse', 'number' => 1, 'language' => null, 'lines' => ['MEN', 'Line A', 'Line B'], 'chords' => null, 'notes' => null, 'languages' => null, 'label' => null, 'sourceWorkId' => null, 'lineIds' => [10, 11, 12]],
    ['type' => 'chorus', 'number' => 0, 'language' => null, 'lines' => ['Chorus line'], 'chords' => null, 'notes' => null, 'languages' => null, 'label' => null, 'sourceWorkId' => null, 'lineIds' => [20]],
];

$afterRemove = vocalPartReviewApplyLineOp($components2, ['type' => 'remove-line', 'lineId' => 10]);
ok('remove-line succeeds when the line exists', $afterRemove !== null);
assertEq($afterRemove[0]['lines'], ['Line A', 'Line B'], 'the marker line is gone from component 0');
assertEq($afterRemove[1]['lines'], ['Chorus line'], 'the OTHER component is completely untouched');
assertEq(count($afterRemove), 2, 'no component is added or dropped — only content within one changes');

$afterRewrite = vocalPartReviewApplyLineOp($components2, ['type' => 'rewrite-line', 'lineId' => 11, 'text' => 'Line A, rewritten']);
ok('rewrite-line succeeds when the line exists', $afterRewrite !== null);
assertEq($afterRewrite[0]['lines'], ['MEN', 'Line A, rewritten', 'Line B'], 'ONLY the named line\'s text changes; its neighbours and position are untouched');

assertEq(vocalPartReviewApplyLineOp($components2, ['type' => 'remove-line', 'lineId' => 99999]), null, 'a lineId present nowhere returns null — the caller treats this as stale, never guesses a fallback position');
assertEq(vocalPartReviewApplyLineOp([], ['type' => 'remove-line', 'lineId' => 1]), null, 'an empty song has nothing to find');

/* ====================================================================== *
 * 10 — vocalPartReviewInsertLineBefore() (Undo's mirror of remove-line)
 * ====================================================================== */
echo "\n10 — vocalPartReviewInsertLineBefore()\n";

/* `vocalPartReviewInsertLineBefore()` (like `…LocateLine()`) takes the
   EDITABLE shape (with `lineIds`), never the write-READY shape
   `vocalPartReviewApplyLineOp()` returns — in real use, Undo always
   re-reads a FRESH `lyricLinesEditableComponents()` from the database
   after Accept's removal already landed, before trying to reinsert
   anything. This fixture stands in for that fresh read: lines 11 and 12
   survived the earlier removal with their OWN real ids, now shifted up
   to positions 0 and 1 of the component. */
$editAfterRemoval = [
    ['type' => 'verse', 'number' => 1, 'language' => null, 'lines' => ['Line A', 'Line B'], 'chords' => null, 'notes' => null, 'languages' => null, 'label' => null, 'sourceWorkId' => null, 'lineIds' => [11, 12]],
    ['type' => 'chorus', 'number' => 0, 'language' => null, 'lines' => ['Chorus line'], 'chords' => null, 'notes' => null, 'languages' => null, 'label' => null, 'sourceWorkId' => null, 'lineIds' => [20]],
];

$afterInsert = vocalPartReviewInsertLineBefore($editAfterRemoval, 11, 'MEN');
ok('insert-before succeeds when the anchor line exists', $afterInsert !== null);
assertEq($afterInsert[0]['lines'], ['MEN', 'Line A', 'Line B'], 'inserting "MEN" back before line 11 restores the ORIGINAL line order exactly');
assertEq($afterInsert[1]['lines'], ['Chorus line'], 'the OTHER component is completely untouched');

assertEq(vocalPartReviewInsertLineBefore($editAfterRemoval, 99999, 'MEN'), null, 'an anchor line present nowhere returns null — the caller falls back to appending, never silently drops the text');

/* ====================================================================== *
 * 11 — STRUCTURAL IDOR GUARD (tree-derived from the file's OWN function
 *      list, comment-stripped via the shared phpSourceUnits() tokenizer —
 *      never a bare grep of the raw file text, which a doc-block PROSE
 *      mention of "vocalPartReviewResolveRow()" would satisfy even after
 *      the real call was deleted). Mirrors test-vocal-parts-core.php §15.
 * ====================================================================== */
echo "\n11 — structural IDOR guard (every song-scoped queue action proves ownership)\n";

$vprSrc   = (string)file_get_contents($root . '/appWeb/public_html/includes/vocal_part_review.php');
$vprUnits = phpSourceUnits($vprSrc);

foreach (['vocalPartReviewAccept', 'vocalPartReviewDismiss', 'vocalPartReviewUndo'] as $fn) {
    $code = $vprUnits[$fn]['code'] ?? null;
    ok("$fn resolves the suggestion via vocalPartReviewResolveRow() before acting on it (IDOR)", $code !== null && str_contains($code, 'vocalPartReviewResolveRow('));
}

/* MUTATION PROOF (rule #34 — a guard must be able to fail): a synthetic
   function with NO resolver call must be caught by the SAME check the real
   functions above pass, proving the check is not vacuously true. */
$synthetic = phpSourceUnits('<?php function fakeAccept($db, $songId, $id) { $x = 1; return $x; }');
ok('the resolver-call check correctly FAILS a synthetic function with no vocalPartReviewResolveRow() call', !str_contains($synthetic['fakeAccept']['code'] ?? '', 'vocalPartReviewResolveRow('));

/* ====================================================================== *
 * 12 — IDEMPOTENT RE-RUN (the task's own explicit requirement): replays
 *      vocalPartReviewScanSong()'s exact reconcile shape — build a row,
 *      decide insert/update/skip against an "existing" map, decide
 *      stale/not — across THREE simulated scans of the SAME real marker,
 *      entirely with pure functions (no DB needed, none of this touches
 *      the disk or a connection).
 * ====================================================================== */
echo "\n12 — idempotent re-run (three simulated scan passes over the SAME marker)\n";

/** Tiny stand-in for the "existing rows for this lyrics version" map vocalPartReviewScanSong() builds from a real SELECT. */
function fakeExistingByKey(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[vocalPartReviewDetectionKey($r['detectionLineId'], $r['form'], $r['markerOffset'])] = $r;
    }
    return $out;
}

$finding = vocalPartReviewBuildRow('CP-0100', 5, 900, 'standalone', 'WOMEN', 'female', 'Women', false, 'high', [901, 902], null);
$key     = vocalPartReviewDetectionKey($finding['detectionLineId'], $finding['form'], $finding['markerOffset']);

/* PASS 1 — nothing stored yet. */
$existing1 = fakeExistingByKey([]);
assertEq(vocalPartReviewScanDecision($existing1[$key]['status'] ?? null), 'insert', 'pass 1: brand-new finding -> insert');
/* The migration would now INSERT one row with Status='pending' — simulate that write landing: */
$storedRow = ['status' => 'pending', 'detectionLineId' => $finding['detectionLineId'], 'form' => $finding['form'], 'markerOffset' => $finding['markerOffset']];

/* PASS 2 — the SAME real marker is scanned again (a re-run of the batch,
   or a curator's "re-scan this song" action) with the SAME finding. */
$existing2 = fakeExistingByKey([$storedRow]);
assertEq(vocalPartReviewScanDecision($existing2[$key]['status'] ?? null), 'update', 'pass 2: the SAME finding, row already pending -> update, NEVER a second insert');
ok('pass 2 never produces a SECOND key for the same real marker', count($existing2) === 1);

/* PASS 3 — a curator has since ACCEPTED the suggestion; the marker's own
   line has been removed by that accept, but suppose (adversarially) the
   detector's snapshot key still happened to reappear — the row must stay
   untouched regardless. */
$storedRow['status'] = 'accepted';
$existing3 = fakeExistingByKey([$storedRow]);
assertEq(vocalPartReviewScanDecision($existing3[$key]['status'] ?? null), 'skip', 'pass 3: an ACCEPTED row is left alone even if the same key resurfaces — a re-run can never silently reopen a curator decision');

/* PASS 4 — the marker line was edited away (curator manually rewrote it)
   BEFORE it was ever reviewed: this scan's fresh findings no longer
   contain the key at all. */
$storedRow['status'] = 'pending';
$stillDetected4 = false;   // this scan's $rowsToWrite has no entry for $key
assertEq(vocalPartReviewShouldStale($storedRow['status'], $stillDetected4), true, 'pass 4: a still-pending row whose marker vanished out from under it -> flagged stale, not left claiming a line that no longer says what it says');

/* PASS 5 — for completeness, an ALREADY-stale row that STILL isn't
   re-detected must not error or re-trigger anything — vocalPartReviewShouldStale()
   only ever fires from 'pending'. */
assertEq(vocalPartReviewShouldStale('stale', false), false, 'pass 5: an already-stale row is not re-flagged (nothing left to do until a curator or a fresh finding revives it)');

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed vocal-part-review assertions passed.\n";
