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

/* ==================================================================== */
/* lyricLinesBuildDesiredFromComponents (#1235 P4/C5 write inversion)     */
/* The PURE payload→desired-lines builder. Must produce the SAME shape    */
/* lyricLinesBuildDesired() derives from LinesJson, so lyricLinesApplyDesired */
/* (the diff) behaves identically on the legacy + cutover paths.          */
/* ==================================================================== */
/* Slug resolver stub: 'verse'/'chorus' map to themselves, else null. */
$slug = static fn(?string $t): ?string => in_array($t, ['verse', 'chorus'], true) ? $t : null;

/* One verse, two lines, no chords/notes/languages → inherits component language.
   #2072 — no notesProvided/chordsProvided key in the input here (a caller from
   before this fix, or a hand-rolled test fixture): the PURE function defaults
   both to `true` ("provided") so this stays TODAY's behaviour — every desired
   line gets `_preserve => ['Note' => false, 'ChordsJson' => false]`, i.e.
   "nothing to preserve, the values above are authoritative" — see the
   dedicated notesProvided/chordsProvided=false block further down for the
   preserve-TRIGGERING case. */
assertEq(
    lyricLinesBuildDesiredFromComponents([
        ['cid' => 5, 'type' => 'verse', 'number' => 1, 'language' => 'en',
         'lines' => ['Amazing grace', 'how sweet the sound'],
         'chords' => null, 'notes' => null, 'validatedLangs' => null],
    ], $slug),
    [
        ['ComponentId' => 5, 'PartType' => 'verse', 'PartTypeSlug' => 'verse', 'PartNumber' => 1,
         'SortOrder' => 0, 'LineText' => 'Amazing grace', 'ChordsJson' => null, 'Note' => null,
         'LanguageCode' => 'en', 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
        ['ComponentId' => 5, 'PartType' => 'verse', 'PartTypeSlug' => 'verse', 'PartNumber' => 1,
         'SortOrder' => 1, 'LineText' => 'how sweet the sound', 'ChordsJson' => null, 'Note' => null,
         'LanguageCode' => 'en', 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
    ],
    'fromComponents: simple verse, lang inherited, contiguous SortOrder'
);

/* Per-line chords (parallel array) re-encoded as JSON per line; absent slot → null. */
assertEq(
    lyricLinesBuildDesiredFromComponents([
        ['cid' => 9, 'type' => 'chorus', 'number' => 0, 'language' => null,
         'lines' => ['Praise him', 'Praise him'],
         'chords' => [['C', 'G'], null], 'notes' => [null, 'softly'], 'validatedLangs' => null],
    ], $slug),
    [
        ['ComponentId' => 9, 'PartType' => 'chorus', 'PartTypeSlug' => 'chorus', 'PartNumber' => null,
         'SortOrder' => 0, 'LineText' => 'Praise him', 'ChordsJson' => '["C","G"]', 'Note' => null,
         'LanguageCode' => null, 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
        ['ComponentId' => 9, 'PartType' => 'chorus', 'PartTypeSlug' => 'chorus', 'PartNumber' => null,
         'SortOrder' => 1, 'LineText' => 'Praise him', 'ChordsJson' => null, 'Note' => 'softly',
         'LanguageCode' => null, 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
    ],
    'fromComponents: per-line chords + notes; number 0 → PartNumber null'
);

/* Per-line language OVERRIDE wins over the component default; blank line → instrumental. */
assertEq(
    lyricLinesBuildDesiredFromComponents([
        ['cid' => 2, 'type' => 'verse', 'number' => 2, 'language' => 'en',
         'lines' => ['Gloria', '', 'in excelsis'],
         'chords' => null, 'notes' => null, 'validatedLangs' => ['la', null, 'la']],
    ], $slug),
    [
        ['ComponentId' => 2, 'PartType' => 'verse', 'PartTypeSlug' => 'verse', 'PartNumber' => 2,
         'SortOrder' => 0, 'LineText' => 'Gloria', 'ChordsJson' => null, 'Note' => null,
         'LanguageCode' => 'la', 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
        ['ComponentId' => 2, 'PartType' => 'verse', 'PartTypeSlug' => 'verse', 'PartNumber' => 2,
         'SortOrder' => 1, 'LineText' => '', 'ChordsJson' => null, 'Note' => null,
         'LanguageCode' => 'en', 'IsInstrumental' => 1,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
        ['ComponentId' => 2, 'PartType' => 'verse', 'PartTypeSlug' => 'verse', 'PartNumber' => 2,
         'SortOrder' => 2, 'LineText' => 'in excelsis', 'ChordsJson' => null, 'Note' => null,
         'LanguageCode' => 'la', 'IsInstrumental' => 0,
         '_preserve' => ['Note' => false, 'ChordsJson' => false]],
    ],
    'fromComponents: per-line language override + blank-line instrumental'
);

/* SortOrder is GLOBAL across components; an unknown type yields a null slug (rule #20). */
assertEq(
    array_map(static fn($d) => [$d['SortOrder'], $d['PartType'], $d['PartTypeSlug'], $d['ComponentId']],
        lyricLinesBuildDesiredFromComponents([
            ['cid' => 1, 'type' => 'verse', 'number' => 1, 'language' => null, 'lines' => ['a', 'b'],
             'chords' => null, 'notes' => null, 'validatedLangs' => null],
            ['cid' => 2, 'type' => 'prechorus', 'number' => 0, 'language' => null, 'lines' => ['c'],
             'chords' => null, 'notes' => null, 'validatedLangs' => null],
        ], $slug)),
    [[0, 'verse', 'verse', 1], [1, 'verse', 'verse', 1], [2, 'prechorus', null, 2]],
    'fromComponents: global SortOrder spans components; unknown type → null slug'
);

/* ==================================================================== */
/* #2072 — `_preserve` flag computation: notesProvided/chordsProvided     */
/* (array_key_exists at the lyricLinesWriteComponents() normalisation      */
/* layer) flow through as `_preserve => ['Note' => !notesProvided,         */
/* 'ChordsJson' => !chordsProvided]` on every desired line of that         */
/* component. This is the WRITER-LEVEL half of the general per-line        */
/* preserve-on-omit mechanism; lyricLinesMergePreserved() (tested below)   */
/* is what actually SPENDS these flags.                                   */
/* ==================================================================== */

/* A component whose payload never mentioned `notes` at all (notesProvided
   false) → every one of its desired lines must carry `_preserve.Note = true`
   ("reclaim whatever is stored"), regardless of the line's own Note value. */
assertEq(
    array_column(
        lyricLinesBuildDesiredFromComponents([
            ['cid' => 30, 'type' => 'verse', 'number' => 1, 'language' => null,
             'lines' => ['a', 'b'], 'chords' => null, 'notes' => null,
             'validatedLangs' => null, 'notesProvided' => false, 'chordsProvided' => true],
        ], $slug),
        '_preserve'
    ),
    [['Note' => true, 'ChordsJson' => false], ['Note' => true, 'ChordsJson' => false]],
    '_preserve: notesProvided=false -> Note preserved, chords authoritative'
);

/* The mirror image — chordsProvided false, notesProvided true. */
assertEq(
    array_column(
        lyricLinesBuildDesiredFromComponents([
            ['cid' => 31, 'type' => 'verse', 'number' => 1, 'language' => null,
             'lines' => ['a'], 'chords' => null, 'notes' => null,
             'validatedLangs' => null, 'notesProvided' => true, 'chordsProvided' => false],
        ], $slug),
        '_preserve'
    ),
    [['Note' => false, 'ChordsJson' => true]],
    '_preserve: chordsProvided=false -> ChordsJson preserved, notes authoritative'
);

/* Both explicitly provided (even though both values are null — an explicit
   "clear both") → neither field is preserved; the null values are authoritative. */
assertEq(
    array_column(
        lyricLinesBuildDesiredFromComponents([
            ['cid' => 32, 'type' => 'verse', 'number' => 1, 'language' => null,
             'lines' => ['a'], 'chords' => null, 'notes' => null,
             'validatedLangs' => null, 'notesProvided' => true, 'chordsProvided' => true],
        ], $slug),
        '_preserve'
    ),
    [['Note' => false, 'ChordsJson' => false]],
    '_preserve: both explicitly provided (even as null) -> neither preserved'
);

/* ==================================================================== */
/* lyricLinesMergePreserved (#2072) — the PURE per-line preserve-on-omit  */
/* merge that lyricLinesApplyDesired() spends for every MATCHED (UPDATE)  */
/* line, before the dirty-check or the UPDATE bind sees the row.          */
/* ==================================================================== */

/* No existing row (an INSERT, or a matchId this call can't resolve) — $desired
   is returned COMPLETELY unchanged, `_preserve` and all. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => true, 'ChordsJson' => true]],
        null
    ),
    ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => true, 'ChordsJson' => true]],
    'mergePreserved: null existingRow -> $desired unchanged (nothing to reclaim)'
);

/* No `_preserve` key at all (the legacy lyricLinesBuildDesired() shape) —
   unchanged even though a real existing row is available, so the legacy
   backfill/re-projection path stays byte-identical to before #2072. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => null, 'ChordsJson' => null],
        ['Note' => 'stored note', 'ChordsJson' => '"C"']
    ),
    ['Note' => null, 'ChordsJson' => null],
    'mergePreserved: no _preserve key (legacy shape) -> unchanged'
);

/* Note preserved (omitted), ChordsJson authoritative (provided) — the exact
   #2072 scenario: an importer/funnel that never learned about `notes` must not
   wipe a stored note when it saves a genuine chord change. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => null, 'ChordsJson' => '"G"', '_preserve' => ['Note' => true, 'ChordsJson' => false]],
        ['Note' => 'sing softly', 'ChordsJson' => '"C"']
    ),
    ['Note' => 'sing softly', 'ChordsJson' => '"G"', '_preserve' => ['Note' => true, 'ChordsJson' => false]],
    'mergePreserved: Note reclaimed from storage, ChordsJson stays the new authoritative value'
);

/* An EXPLICIT null (Note provided as null, i.e. `_preserve.Note = false`) must
   genuinely CLEAR the stored value, not reclaim it — this is the whole point
   of computing the flag with array_key_exists rather than isset. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => false, 'ChordsJson' => false]],
        ['Note' => 'sing softly', 'ChordsJson' => '"C"']
    ),
    ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => false, 'ChordsJson' => false]],
    'mergePreserved: explicit null (not preserved) genuinely clears, never reclaims'
);

/* Both preserved at once, and the existing row's own value is itself null
   (nothing to reclaim) — resolves to null, not a PHP notice. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => 'ignored', 'ChordsJson' => 'ignored', '_preserve' => ['Note' => true, 'ChordsJson' => true]],
        ['Note' => null, 'ChordsJson' => null]
    ),
    ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => true, 'ChordsJson' => true]],
    'mergePreserved: both preserved, stored values are null -> resolves to null cleanly'
);

/* An existing row missing the Note/ChordsJson keys entirely (defensive —
   should never happen in practice, since lyricLinesApplyDesired()'s own SELECT
   always names both columns) still resolves to null rather than a PHP notice. */
assertEq(
    lyricLinesMergePreserved(
        ['Note' => 'ignored', 'ChordsJson' => 'ignored', '_preserve' => ['Note' => true, 'ChordsJson' => true]],
        ['SomeOtherColumn' => 'x']
    ),
    ['Note' => null, 'ChordsJson' => null, '_preserve' => ['Note' => true, 'ChordsJson' => true]],
    'mergePreserved: existing row missing Note/ChordsJson keys -> defaults to null, no notice'
);

/* ==================================================================== */
/* #2072 STRUCTURAL guard (rule #34) — lyricLinesApplyDesired() must       */
/* actually CALL lyricLinesMergePreserved() for a matched line, not just    */
/* have the helper sitting unused nearby. Mutation-proven: comment out the */
/* call below and this assertion goes red (verified during development,   */
/* see the session report).                                               */
/* ==================================================================== */
$syncSrc  = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_sync.php'
);
$syncCode = preg_replace('#/\*[\s\S]*?\*/#', '', $syncSrc) ?? $syncSrc;   // strip block comments (prose mentions the name too)

$applyFnStart = strpos($syncCode, 'function lyricLinesApplyDesired(');
$applyFnBody  = '';
if ($applyFnStart !== false) {
    $nextFn      = strpos($syncCode, "\nfunction ", $applyFnStart + 10);
    $applyFnBody = substr($syncCode, $applyFnStart, $nextFn === false ? null : $nextFn - $applyFnStart);
}
assertEq($applyFnBody !== '', true, 'lyricLinesApplyDesired() found in the ONE write path');
assertEq(
    str_contains($applyFnBody, 'lyricLinesMergePreserved('),
    true,
    '#2072: lyricLinesApplyDesired() actually calls lyricLinesMergePreserved() for a matched line'
);

/* ==================================================================== */
/* #2072 STRUCTURAL guard — the two *Provided flags MUST be computed with */
/* array_key_exists, never isset(). isset() treats an explicit `null` the  */
/* same as "absent", which would make "send notes:null to clear the note"  */
/* silently degrade back into "preserve" — the exact regression this fix   */
/* exists to prevent. Mutation-proven: change either to isset($c[...]) and */
/* this assertion goes red (verified during development, see the session  */
/* report).                                                                */
/* ==================================================================== */
$writeFnStart = strpos($syncCode, 'function lyricLinesWriteComponents(');
$writeFnBody  = '';
if ($writeFnStart !== false) {
    $nextFn3     = strpos($syncCode, "\nfunction ", $writeFnStart + 10);
    $writeFnBody = substr($syncCode, $writeFnStart, $nextFn3 === false ? null : $nextFn3 - $writeFnStart);
}
assertEq($writeFnBody !== '', true, 'lyricLinesWriteComponents() found in the ONE write path');
assertEq(
    (bool)preg_match("/'notesProvided'\\s*=>\\s*array_key_exists\\(\\s*'notes'\\s*,\\s*\\\$c\\s*\\)/", $writeFnBody),
    true,
    "#2072: notesProvided is computed with array_key_exists — an explicit null must still count " .
    "as \"provided\" so it can genuinely CLEAR the note, not silently degrade into \"preserve\""
);
assertEq(
    (bool)preg_match("/'chordsProvided'\\s*=>\\s*array_key_exists\\(\\s*'chords'\\s*,\\s*\\\$c\\s*\\)/", $writeFnBody),
    true,
    '#2072: chordsProvided is computed with array_key_exists (same reasoning as notesProvided)'
);

/* ==================================================================== */
/* #2072 REVIEW FOLLOW-UP — the writer-level merge above is identity-based */
/* (matched by content-diff, not position), so it is correct by             */
/* construction. What was NOT correct, before this follow-up, was the       */
/* CALLER side: save_song_core.php / api2.php's component_upsert /          */
/* components_replace each hand-carried an omitted `notes` value by         */
/* POSITION or by a (type, number, lineCount) key — which OVERRODE the      */
/* writer's identity-based preserve with a guessed value, and could do so   */
/* WRONGLY. The tests below prove two things: (1) the CORE mechanism        */
/* (lyricLinesDiff + lyricLinesMergePreserved, exactly as                  */
/* lyricLinesApplyDesired() calls them) gives the RIGHT answer for the      */
/* scenarios the caller-level carries got wrong, using a small test-only    */
/* simulation of the read-only match+merge half of                         */
/* lyricLinesApplyDesired() — the DB write half is deliberately not         */
/* reproduced, only the two pure functions that decide VALUES; and (2) the  */
/* callers were actually fixed to OMIT the key rather than hand-carry one,  */
/* via source-level structural guards (this test has no DB, so it cannot    */
/* run a real save — see the session report for why that is an honest      */
/* limit, not a shortcut).                                                 */
/* ==================================================================== */

/**
 * Test-only simulation of the MATCH + MERGE half of lyricLinesApplyDesired()
 * — deliberately calls the SAME production functions the real writer calls
 * (lyricLinesDiff, lyricLinesMergePreserved), not a re-implementation, so a
 * regression in either production function fails this test too.
 *
 * @param list<array<string,mixed>> $existing  tblLyricLines-shaped rows
 * @param list<array<string,mixed>> $desired   lyricLinesBuildDesiredFromComponents() output
 * @return list<array<string,mixed>>
 */
function ihymnsTestSimulateMergedDesired(array $existing, array $desired): array
{
    $plan = lyricLinesDiff($existing, $desired);
    $existingById = [];
    foreach ($existing as $e) { $existingById[(int)$e['Id']] = $e; }
    $out = [];
    foreach ($desired as $di => $d) {
        $matchId = $plan['matchedIds'][$di];
        if ($matchId !== null) {
            $d = lyricLinesMergePreserved($d, $existingById[$matchId] ?? null);
        }
        $out[] = $d;
    }
    return $out;
}

/* --- Finding 1 (first half): omit notes + ADD a line -> surviving matched
       lines keep their notes. Before the caller-level fix, save_song_core.php's
       $carryNotes FIFO was keyed by "type\x1fnumber\x1flineCount" — adding a
       line changes the line count, the key lookup misses, and the omitted
       notes became an explicit `null` that wiped line 1's note even though
       line 1's own content never changed. --- */
$existingAdd = [
    ['Id' => 1, 'ComponentId' => 100, 'PartType' => 'verse', 'PartNumber' => 1, 'SortOrder' => 0,
     'LineText' => 'Amazing grace', 'ChordsJson' => null, 'Note' => 'sing softly',
     'LanguageCode' => null, 'IsInstrumental' => 0],
    ['Id' => 2, 'ComponentId' => 100, 'PartType' => 'verse', 'PartNumber' => 1, 'SortOrder' => 1,
     'LineText' => 'how sweet the sound', 'ChordsJson' => null, 'Note' => null,
     'LanguageCode' => null, 'IsInstrumental' => 0],
];
$normAdd = [
    ['cid' => 100, 'type' => 'verse', 'number' => 1, 'language' => null,
     'lines' => ['Amazing grace', 'how sweet the sound', 'that saved a wretch like me'],
     'chords' => null, 'notes' => null, 'validatedLangs' => null,
     'notesProvided' => false, 'chordsProvided' => true],
];
$mergedAdd = ihymnsTestSimulateMergedDesired($existingAdd, lyricLinesBuildDesiredFromComponents($normAdd, $slug));
assertEq(count($mergedAdd), 3, '#2072 finding 1 (add a line): 3 desired lines (2 matched + 1 new)');
assertEq($mergedAdd[0]['Note'], 'sing softly',
    '#2072 finding 1 (add a line): surviving matched line 1 KEEPS its note despite the component growing by one line');
assertEq($mergedAdd[1]['Note'], null,
    '#2072 finding 1 (add a line): surviving matched line 2 stays null (it never had a note) — not corrupted either way');
assertEq($mergedAdd[2]['Note'], null,
    '#2072 finding 1 (add a line): the brand-new third line has no existing row to reclaim from — null, not an error');

/* --- Finding 1 (second half): omit notes + REORDER lines -> the note
       follows the LINE (matched by content), not the array index. A
       position/key-based carry would have left "sing softly" sitting at
       index 0 regardless of which line moved there. --- */
$existingReorder = [
    ['Id' => 1, 'ComponentId' => 100, 'PartType' => 'verse', 'PartNumber' => 1, 'SortOrder' => 0,
     'LineText' => 'Amazing grace', 'ChordsJson' => null, 'Note' => 'sing softly',
     'LanguageCode' => null, 'IsInstrumental' => 0],
    ['Id' => 2, 'ComponentId' => 100, 'PartType' => 'verse', 'PartNumber' => 1, 'SortOrder' => 1,
     'LineText' => 'how sweet the sound', 'ChordsJson' => null, 'Note' => null,
     'LanguageCode' => null, 'IsInstrumental' => 0],
];
$normReorder = [
    ['cid' => 100, 'type' => 'verse', 'number' => 1, 'language' => null,
     'lines' => ['how sweet the sound', 'Amazing grace'],   // SWAPPED order
     'chords' => null, 'notes' => null, 'validatedLangs' => null,
     'notesProvided' => false, 'chordsProvided' => true],
];
$mergedReorder = ihymnsTestSimulateMergedDesired($existingReorder, lyricLinesBuildDesiredFromComponents($normReorder, $slug));
assertEq($mergedReorder[0]['Note'], null,
    "#2072 finding 1 (reorder): 'how sweet the sound' (no note) is now at index 0 and correctly has no note");
assertEq($mergedReorder[1]['Note'], 'sing softly',
    "#2072 finding 1 (reorder): 'Amazing grace' carried its note to its NEW index 1 — the note followed the LINE, not the index");

/* --- Finding 3: two components sharing a (type, number) bucket — a chorus
       reprised verbatim later in the song. The FIRST occurrence explicitly
       changes its own note; the SECOND says nothing about notes at all. The
       old FIFO-by-key carry queued both occurrences' OLD notes together and
       handed the SECOND incoming component whichever queue entry the FIRST
       incoming component didn't consume — i.e. the FIRST occurrence's OLD
       note, not the SECOND's. The identity-based (content-matched) mechanism
       must give the second occurrence back its OWN prior value, not the
       first's. --- */
$existingShared = [
    ['Id' => 201, 'ComponentId' => 10, 'PartType' => 'chorus', 'PartNumber' => null, 'SortOrder' => 0,
     'LineText' => 'Praise the Lord', 'ChordsJson' => null, 'Note' => 'was quiet',
     'LanguageCode' => null, 'IsInstrumental' => 0],
    ['Id' => 202, 'ComponentId' => 10, 'PartType' => 'chorus', 'PartNumber' => null, 'SortOrder' => 1,
     'LineText' => 'Alleluia', 'ChordsJson' => null, 'Note' => null,
     'LanguageCode' => null, 'IsInstrumental' => 0],
    ['Id' => 203, 'ComponentId' => 11, 'PartType' => 'chorus', 'PartNumber' => null, 'SortOrder' => 2,
     'LineText' => 'Praise the Lord', 'ChordsJson' => null, 'Note' => 'echo',
     'LanguageCode' => null, 'IsInstrumental' => 0],
    ['Id' => 204, 'ComponentId' => 11, 'PartType' => 'chorus', 'PartNumber' => null, 'SortOrder' => 3,
     'LineText' => 'Alleluia', 'ChordsJson' => null, 'Note' => null,
     'LanguageCode' => null, 'IsInstrumental' => 0],
];
$normShared = [
    /* First occurrence — EXPLICITLY provides notes (a genuine change). */
    ['cid' => 10, 'type' => 'chorus', 'number' => 0, 'language' => null,
     'lines' => ['Praise the Lord', 'Alleluia'], 'chords' => null, 'notes' => ['forte', null],
     'validatedLangs' => null, 'notesProvided' => true, 'chordsProvided' => true],
    /* Reprise — says NOTHING about notes. */
    ['cid' => 11, 'type' => 'chorus', 'number' => 0, 'language' => null,
     'lines' => ['Praise the Lord', 'Alleluia'], 'chords' => null, 'notes' => null,
     'validatedLangs' => null, 'notesProvided' => false, 'chordsProvided' => true],
];
$mergedShared = ihymnsTestSimulateMergedDesired($existingShared, lyricLinesBuildDesiredFromComponents($normShared, $slug));
assertEq($mergedShared[0]['Note'], 'forte',
    '#2072 finding 3: first occurrence — explicit note wins over its own old value');
assertEq($mergedShared[1]['Note'], null,
    '#2072 finding 3: first occurrence, second line — explicit null in the provided array, stays null');
assertEq($mergedShared[2]['Note'], 'echo',
    "#2072 finding 3: SECOND occurrence (omitted notes) reclaims its OWN prior value ('echo') — " .
    "NOT the first occurrence's old note ('was quiet'). A regression here means a reprised chorus " .
    "would silently inherit its first occurrence's note."
);
assertEq($mergedShared[3]['Note'], null,
    '#2072 finding 3: second occurrence, second line — also correctly null, not cross-contaminated');

/* ==================================================================== */
/* #2072 finding 4 — the shadow-JSON write. lyricLinesShadowCellsToJson()   */
/* is the PURE "any non-null cell -> encode; else null" rule shared by      */
/* BOTH the original (pre-merge) shadow write in                           */
/* lyricLinesUpsertComponents() and the POST-MERGE resync in                */
/* lyricLinesResyncChordsNotesShadow() — extracted specifically so the two  */
/* can never encode the same cell array two different ways.                */
/* ==================================================================== */
assertEq(lyricLinesShadowCellsToJson([null, null, null]), null,
    'shadowCellsToJson: every cell null -> null (nothing worth storing)');
assertEq(lyricLinesShadowCellsToJson([]), null,
    'shadowCellsToJson: empty array -> null');
assertEq(lyricLinesShadowCellsToJson([null, 'sing softly', null]), json_encode([null, 'sing softly', null], JSON_UNESCAPED_UNICODE),
    'shadowCellsToJson: one non-null cell -> the WHOLE array JSON-encoded, nulls and all (null-padded shape preserved)');
assertEq(lyricLinesShadowCellsToJson([['C', 'G'], null]), json_encode([['C', 'G'], null], JSON_UNESCAPED_UNICODE),
    'shadowCellsToJson: works for chord cells (arrays) the same as note cells (strings)');

/* --- STRUCTURAL guards (rule #34): the resync step must exist, must be     */
/* wired in AFTER lyricLinesApplyDesired() (not before — the whole point is  */
/* the resync sees the MERGED values), must read the correction from the     */
/* AUTHORITATIVE tblLyricLines (never from the pre-merge $norm payload), and */
/* both shadow-writing call sites must funnel through the ONE shared         */
/* lyricLinesShadowCellsToJson() encoder rather than re-deriving the         */
/* any-null-collapse rule twice. --- */
assertEq(
    str_contains($syncCode, 'function lyricLinesResyncChordsNotesShadow('),
    true,
    '#2072 finding 4: lyricLinesResyncChordsNotesShadow() exists'
);
$writeCompsCallOrder = [
    'apply'  => strpos($writeFnBody, 'lyricLinesApplyDesired('),
    'resync' => strpos($writeFnBody, 'lyricLinesResyncChordsNotesShadow('),
];
assertEq($writeCompsCallOrder['apply'] !== false, true, '#2072 finding 4: lyricLinesWriteComponents() calls lyricLinesApplyDesired()');
assertEq($writeCompsCallOrder['resync'] !== false, true, '#2072 finding 4: lyricLinesWriteComponents() calls lyricLinesResyncChordsNotesShadow()');
assertEq(
    $writeCompsCallOrder['resync'] > $writeCompsCallOrder['apply'],
    true,
    '#2072 finding 4: the resync call comes AFTER lyricLinesApplyDesired() — fixing the shadow before the ' .
    'merge has run would just encode another guess, not the merged truth'
);

$resyncFnStart = strpos($syncCode, 'function lyricLinesResyncChordsNotesShadow(');
$resyncFnBody  = '';
if ($resyncFnStart !== false) {
    $nextFn4      = strpos($syncCode, "\nfunction ", $resyncFnStart + 10);
    $resyncFnBody = substr($syncCode, $resyncFnStart, $nextFn4 === false ? null : $nextFn4 - $resyncFnStart);
}
assertEq($resyncFnBody !== '', true, 'lyricLinesResyncChordsNotesShadow() body found');
assertEq(
    str_contains($resyncFnBody, 'FROM tblLyricLines'),
    true,
    '#2072 finding 4: the resync reads its correction FROM tblLyricLines (the now-authoritative, ' .
    'post-merge store) — reading from $norm/$c[\'notes\'] again would just repeat the same stale guess'
);
assertEq(
    str_contains($resyncFnBody, 'lyricLinesShadowCellsToJson('),
    true,
    '#2072 finding 4: the resync encodes via the SAME shared lyricLinesShadowCellsToJson() the ' .
    'original shadow write uses — one encoding rule, not two that could drift apart'
);
$upsertFnStart = strpos($syncCode, 'function lyricLinesUpsertComponents(');
$upsertFnBody  = '';
if ($upsertFnStart !== false) {
    $nextFn5      = strpos($syncCode, "\nfunction ", $upsertFnStart + 10);
    $upsertFnBody = substr($syncCode, $upsertFnStart, $nextFn5 === false ? null : $nextFn5 - $upsertFnStart);
}
assertEq(
    str_contains($upsertFnBody, 'lyricLinesShadowCellsToJson('),
    true,
    '#2072 finding 4: the ORIGINAL (pre-merge) shadow write also goes through lyricLinesShadowCellsToJson()'
);

/* ==================================================================== */
/* #2072 REVIEW FOLLOW-UP — caller-level structural guards. The writer's    */
/* identity-based merge only protects a caller that actually OMITS the key  */
/* it has nothing to say about. These guards prove each of the three        */
/* funnels was changed to do that, and that the specific defect shapes the  */
/* review named (positional carry, positional target-preserve, isset()-vs-  */
/* array_key_exists on the explicit-null path) are gone from the source —   */
/* not merely that SOME code runs, since none of this can be exercised      */
/* end-to-end without a live DB in this environment.                       */
/* ==================================================================== */
$saveCoreSrc  = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/save_song_core.php');
$saveCoreCode = preg_replace('#/\*[\s\S]*?\*/#', '', $saveCoreSrc) ?? $saveCoreSrc;

assertEq(str_contains($saveCoreCode, 'carryNotes'), false,
    '#2072 caller guard (save_song_core.php): the $carryNotes FIFO is GONE, not merely unused');
assertEq(
    (bool)preg_match("/array_key_exists\\('notes',\\s*\\\$comp\\)/", $saveCoreCode),
    true,
    '#2072 caller guard (save_song_core.php): notes is included on $writeComps[] only when ' .
    'array_key_exists(\'notes\', $comp) — key-present-only, no hand-carried fallback'
);

$api2Src  = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/api2.php');
$api2Code = preg_replace('#/\*[\s\S]*?\*/#', '', $api2Src) ?? $api2Src;

$cuStart = strpos($api2Code, "case 'component_upsert':");
$cuEnd   = strpos($api2Code, "case 'component_delete':", $cuStart === false ? 0 : $cuStart);
$cuBlock = ($cuStart !== false && $cuEnd !== false) ? substr($api2Code, $cuStart, $cuEnd - $cuStart) : '';
assertEq($cuBlock !== '', true, "component_upsert handler found in api2.php");
assertEq(
    (bool)preg_match("/\\\$hasNotes\\s*=\\s*array_key_exists\\('notes',\\s*\\\$comp\\);/", $cuBlock),
    true,
    '#2072 caller guard (component_upsert): $hasNotes is array_key_exists-based, so an explicit ' .
    'null still counts as "provided" and can genuinely clear a stored note'
);
assertEq(
    str_contains($cuBlock, '!$hasNotes'),
    false,
    '#2072 caller guard (component_upsert): NO "!$hasNotes -> $entry[\'notes\'] = $c[\'notes\']" ' .
    'positional target-preserve — that line copied the OLD component\'s notes array onto whatever ' .
    'NEW lines this request sent, misaligning the moment lines were added/removed/reordered WITHIN ' .
    'this one component. Omitting the key instead lets the writer\'s identity-based preserve do it right.'
);

$crStart = strpos($api2Code, "case 'components_replace':");
$crEnd   = strpos($api2Code, "case 'import_file':", $crStart === false ? 0 : $crStart);
$crBlock = ($crStart !== false && $crEnd !== false) ? substr($api2Code, $crStart, $crEnd - $crStart) : '';
assertEq($crBlock !== '', true, "components_replace handler found in api2.php");
assertEq(str_contains($crBlock, "'n' =>"), false,
    "#2072 caller guard (components_replace): the carry tuple no longer has an 'n' (notes) slot");
assertEq(str_contains($crBlock, "carried['n']"), false,
    "#2072 caller guard (components_replace): nothing reads a carried 'n' value any more");
assertEq(
    (bool)preg_match("/array_key_exists\\('notes',\\s*\\\$comp\\)/", $crBlock),
    true,
    '#2072 caller guard (components_replace): notes is included on the incoming entry only when ' .
    'array_key_exists(\'notes\', $comp) — key-present-only, no FIFO carry'
);

/* -------------------------------------------------------------------- */
echo "\n";
echo "  ----------------------------------------\n";
echo "  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
