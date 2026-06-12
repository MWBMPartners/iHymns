<?php
/**
 * iHymns — Id-preserving lyric-line diff unit test (#1235 P2b)
 *
 * Exercises the PURE matching helpers in
 * appWeb/public_html/includes/lyric_lines_sync.php that the projector uses to
 * preserve a line's Id (and its FK'd timing / translations / annotations) across
 * an edit, instead of the old delete-all + reinsert. No DB / HTTP.
 *
 *   php tests/php/test-lyric-lines-diff.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_sync.php';

/* -------------------------------------------------------------------- */
/* Test runner — one-line PASS/FAIL per assertion.                       */
/* -------------------------------------------------------------------- */
$passed = 0;
$failed = 0;
function assertEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function assertTrue($actual, string $label): void
{
    assertEq((bool)$actual, true, $label);
}

/* Builders — terse row factories matching the diff's expected shapes. */
function ex(int $id, string $text, ?string $part = 'verse', $num = 1): array
{
    return ['Id' => $id, 'PartType' => $part, 'PartNumber' => $num, 'LineText' => $text];
}
function de(string $text, ?string $part = 'verse', ?int $num = 1): array
{
    return ['PartType' => $part, 'PartNumber' => $num, 'LineText' => $text];
}

/* ==================================================================== */
/* lyricLinesBucketKey                                                    */
/* ==================================================================== */
assertEq(lyricLinesBucketKey(['PartType' => 'verse', 'PartNumber' => 1]), "verse\x1f1",
    'bucketKey: verse 1');
assertEq(lyricLinesBucketKey(['PartType' => 'chorus', 'PartNumber' => null]), "chorus\x1f",
    'bucketKey: NULL number collapses to empty');
assertEq(lyricLinesBucketKey(['PartType' => 'chorus', 'PartNumber' => 0]), "chorus\x1f",
    'bucketKey: 0 number (lone chorus) collapses to empty (matches projector NULL)');
assertTrue(lyricLinesBucketKey(de('x', 'verse', 1)) !== lyricLinesBucketKey(de('x', 'verse', 2)),
    'bucketKey: verse 1 != verse 2');
assertTrue(lyricLinesBucketKey(de('x', 'verse', 1)) !== lyricLinesBucketKey(de('x', 'chorus', 1)),
    'bucketKey: verse 1 != chorus 1');

/* ==================================================================== */
/* lyricLinesSimilarity                                                   */
/* ==================================================================== */
assertEq(lyricLinesSimilarity('abc', 'abc'), 1.0, 'similarity: identical = 1.0');
assertEq(lyricLinesSimilarity('abc', ''), 0.0, 'similarity: empty = 0.0');
assertTrue(lyricLinesSimilarity('Amazing grace how sweet', 'Amazing grace, how sweet') >= 0.5,
    'similarity: a comma typo stays above the 0.5 floor');
assertTrue(lyricLinesSimilarity('Be thou my vision', 'Were the whole realm') < 0.5,
    'similarity: unrelated lines fall below the floor');
/* Code-point (not byte) distance: a one-character CJK / accented typo must score
   like a Latin one so #1088 non-Latin lines keep their enrichment on a fix. */
assertTrue(lyricLinesSimilarity('主が私の', '主が私は') >= 0.5,
    'similarity: one-codepoint CJK edit stays above floor (code-point metric)');
assertTrue(lyricLinesSimilarity('café au lait', 'cafe au lait') >= 0.5,
    'similarity: accented one-char edit scores high (not byte-penalised)');

/* ==================================================================== */
/* lyricLinesDiff — core scenarios                                       */
/* ==================================================================== */

/* 1. Unchanged re-projection: every line matched in order, no deletes. */
$d = lyricLinesDiff(
    [ex(10, 'Line A'), ex(11, 'Line B'), ex(12, 'Line C')],
    [de('Line A'), de('Line B'), de('Line C')]
);
assertEq($d['matchedIds'], [10, 11, 12], 'unchanged: all Ids preserved in order');
assertEq($d['deleteIds'], [], 'unchanged: nothing deleted');

/* 2. Typo fix in one line: that line keeps its Id via the fuzzy pass. */
$d = lyricLinesDiff(
    [ex(10, 'Amazing grace how sweet'), ex(11, 'That saved a wretch like me')],
    [de('Amazing grace, how sweet'),    de('That saved a wretch like me')]
);
assertEq($d['matchedIds'], [10, 11], 'typo fix: edited line keeps Id (fuzzy), other exact');
assertEq($d['deleteIds'], [], 'typo fix: no deletions');

/* 3. Insert a new line in the middle: new line = null, others matched. */
$d = lyricLinesDiff(
    [ex(10, 'Line A'), ex(11, 'Line C')],
    [de('Line A'), de('Line B'), de('Line C')]
);
assertEq($d['matchedIds'], [10, null, 11], 'insert: new middle line is null (INSERT)');
assertEq($d['deleteIds'], [], 'insert: nothing deleted');

/* 4. Delete a line: its Id is a deletion, survivors keep Ids. */
$d = lyricLinesDiff(
    [ex(10, 'Line A'), ex(11, 'Line B'), ex(12, 'Line C')],
    [de('Line A'), de('Line C')]
);
assertEq($d['matchedIds'], [10, 12], 'delete: survivors keep Ids');
assertEq($d['deleteIds'], [11], 'delete: removed line Id reported');

/* 5. Reorder distinct lines within a part: all Ids preserved (no churn). */
$d = lyricLinesDiff(
    [ex(10, 'Line A'), ex(11, 'Line B')],
    [de('Line B'), de('Line A')]
);
assertEq($d['matchedIds'], [11, 10], 'reorder: Ids follow their text, no delete/insert');
assertEq($d['deleteIds'], [], 'reorder: nothing deleted');

/* 6. Unchanged line moved between parts: kept via the cross-part exact pass. */
$d = lyricLinesDiff(
    [ex(10, 'Hallelujah', 'verse', 1)],
    [de('Hallelujah', 'chorus', null)]
);
assertEq($d['matchedIds'], [10], 'cross-part move: Id preserved (pass 2)');
assertEq($d['deleteIds'], [], 'cross-part move: nothing deleted');

/* 7. Repeated identical lines map 1:1 in order (refrain). */
$d = lyricLinesDiff(
    [ex(10, 'Hallelujah', 'chorus', null), ex(11, 'Hallelujah', 'chorus', null)],
    [de('Hallelujah', 'chorus', null),     de('Hallelujah', 'chorus', null)]
);
assertEq($d['matchedIds'], [10, 11], 'duplicates: positional 1:1 mapping');
assertEq($d['deleteIds'], [], 'duplicates: nothing deleted');

/* 8. Fresh projection (no existing rows): every line is an INSERT. */
$d = lyricLinesDiff([], [de('Line A'), de('Line B')]);
assertEq($d['matchedIds'], [null, null], 'fresh: all lines INSERT');
assertEq($d['deleteIds'], [], 'fresh: nothing to delete');

/* 9. Whole replacement (legacy save_song re-text): old all deleted, new all inserted. */
$d = lyricLinesDiff(
    [ex(10, 'Old one'), ex(11, 'Old two')],
    [de('Brand new alpha'), de('Brand new beta')]
);
assertEq($d['matchedIds'], [null, null], 'replace: dissimilar lines do not mis-pair');
assertEq($d['deleteIds'], [10, 11], 'replace: all old Ids deleted');

/* 10. Same part, fuzzy must NOT cross the part boundary. */
$d = lyricLinesDiff(
    [ex(10, 'Amazing grace how sweet', 'verse', 1)],
    [de('Amazing grace, how sweet', 'verse', 2)]
);
assertEq($d['matchedIds'], [null], 'fuzzy: different verse number is NOT a fuzzy match');
assertEq($d['deleteIds'], [10], 'fuzzy: the v1 line is deleted, the v2 line inserted');

/* 11. A blank (instrumental) line never fuzzy-matches a non-blank one. */
$d = lyricLinesDiff(
    [ex(10, '', 'verse', 1)],
    [de('Now there are words', 'verse', 1)]
);
assertEq($d['matchedIds'], [null], 'blank: empty existing line is not fuzzed onto text');
assertEq($d['deleteIds'], [10], 'blank: the empty line is deleted');

/* 12. Greedy pass-3 contention: two fuzzy desired lines, one existing line in the
      bucket — exactly one wins the Id, the other is a fresh INSERT (no double-claim). */
$d = lyricLinesDiff(
    [ex(10, 'Amazing grace how sweet the sound')],
    [de('Amazing grace, how sweet the sound'), de('Amazing grace how sweet the round')]
);
$claimed = array_filter($d['matchedIds'], static fn($x) => $x === 10);
assertEq(count($claimed), 1, 'contention: existing Id claimed by exactly one desired line');
assertEq(in_array(null, $d['matchedIds'], true), true, 'contention: the loser is an INSERT');
assertEq($d['deleteIds'], [], 'contention: existing line is reused, not deleted');

/* 13. Blank/instrumental lines: same-part blanks pair in pass 1 BEFORE any leftover
      goes cross-part — the path most likely to misplace #141 timing. */
$d = lyricLinesDiff(
    [ex(10, '', 'verse', 1), ex(11, '', 'chorus', null)],
    [de('', 'verse', 1), de('', 'chorus', null)]
);
assertEq($d['matchedIds'], [10, 11], 'blanks: each blank pairs within its own part (pass 1)');
assertEq($d['deleteIds'], [], 'blanks: no cross-part blank misplacement');

/* 14. Split a refrain: one existing identical line, two identical desired lines —
      one matches, one inserts (no double-claim of the single Id). */
$d = lyricLinesDiff(
    [ex(10, 'Hallelujah', 'chorus', null)],
    [de('Hallelujah', 'chorus', null), de('Hallelujah', 'chorus', null)]
);
assertEq($d['matchedIds'], [10, null], 'split-refrain: first identical matches, second inserts');
assertEq($d['deleteIds'], [], 'split-refrain: nothing deleted');

/* ==================================================================== */
/* lyricLinesRowClean — dirty-check                                       */
/* ==================================================================== */
$row = [
    'ComponentId' => 5, 'PartType' => 'verse', 'PartNumber' => 2, 'SortOrder' => 7,
    'LineText' => 'Holy holy holy', 'ChordsJson' => '"C"', 'Note' => null,
    'LanguageCode' => 'en', 'IsInstrumental' => 0,
];
$des = [
    'ComponentId' => 5, 'PartType' => 'verse', 'PartNumber' => 2, 'SortOrder' => 7,
    'LineText' => 'Holy holy holy', 'ChordsJson' => '"C"', 'Note' => null,
    'LanguageCode' => 'en', 'IsInstrumental' => 0,
];
assertTrue(lyricLinesRowClean($row, $des), 'rowClean: identical row+desired = clean (skip UPDATE)');
assertEq(lyricLinesRowClean(null, $des), false, 'rowClean: null existing = dirty');
assertEq(lyricLinesRowClean($row, ['ComponentId' => 5, 'PartType' => 'verse', 'PartNumber' => 2,
    'SortOrder' => 8, 'LineText' => 'Holy holy holy', 'ChordsJson' => '"C"', 'Note' => null,
    'LanguageCode' => 'en', 'IsInstrumental' => 0]), false, 'rowClean: SortOrder change = dirty');
assertEq(lyricLinesRowClean($row, ['ComponentId' => 5, 'PartType' => 'verse', 'PartNumber' => 2,
    'SortOrder' => 7, 'LineText' => 'Holy, holy, holy', 'ChordsJson' => '"C"', 'Note' => null,
    'LanguageCode' => 'en', 'IsInstrumental' => 0]), false, 'rowClean: text change = dirty');
/* MySQL may re-format stored JSON — semantic, not byte, comparison. */
$rowSpaced = $row; $rowSpaced['ChordsJson'] = '["C", "G"]';
$desCompact = $des; $desCompact['ChordsJson'] = '["C","G"]';
assertTrue(lyricLinesRowClean($rowSpaced, $desCompact),
    'rowClean: chords differing only by JSON whitespace are clean');
$desChord = $des; $desChord['ChordsJson'] = '"G"';
assertEq(lyricLinesRowClean($row, $desChord), false, 'rowClean: real chord change = dirty');
assertEq(lyricLinesRowClean($row, ['ComponentId' => 5, 'PartType' => 'verse', 'PartNumber' => 2,
    'SortOrder' => 7, 'LineText' => 'Holy holy holy', 'ChordsJson' => '"C"', 'Note' => 'sing softly',
    'LanguageCode' => 'en', 'IsInstrumental' => 0]), false, 'rowClean: note added = dirty');

/* -------------------------------------------------------------------- */
echo "\n";
echo "  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
