<?php
/**
 * iHymns — line-first assembler unit test (#1235 P4 / C1)
 *
 * Exercises the PURE assembly core lyricLinesAssembleFromRows() in
 * appWeb/public_html/includes/lyric_lines_read.php — the function that rebuilds a
 * song's component-shaped array from authoritative tblLyricLines rows. The output
 * shape must match SongData::_getComponents() byte-for-byte (key order included), so
 * these assertions pin that contract. No DB / HTTP.
 *
 *   php tests/php/test-lyric-lines-read.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_read.php';

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

/* Row factory matching lyricLinesAssembleFromRows() input shape. */
function row(int $lineId, ?int $cid, string $text, array $o = []): array
{
    return [
        'line_id'       => $lineId,
        'cid'           => $cid,
        'text'          => $text,
        'line_lang'     => $o['line_lang']     ?? null,
        'line_chords'   => $o['line_chords']   ?? null,
        'comp_type'     => array_key_exists('comp_type', $o)   ? $o['comp_type']   : 'verse',
        'comp_number'   => array_key_exists('comp_number', $o) ? $o['comp_number'] : 1,
        'comp_lang'     => $o['comp_lang']     ?? null,
        'line_parttype' => $o['line_parttype'] ?? null,
        'line_partnum'  => $o['line_partnum']  ?? null,
        /* #1860 Phase 5 §2.5 — optional comp_label (sparse public/export 'label'
           key, SD3). Defaults to null so cases 1-8 above are untouched: with
           comp_label absent-or-null, lyricLinesAssembleFromRows() never adds a
           'label' key, so every existing fixture's exact expected array stays
           correct byte-for-byte — that this file needed NO edits to those
           fixtures is itself the sparse-emit proof SD3 promises. */
        'comp_label'    => array_key_exists('comp_label', $o) ? $o['comp_label'] : null,
    ];
}

/* ==================================================================== */
/* 1 — two verses: grouped by ComponentId, lineIds present, no chords/langs */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 10, 'a', ['comp_number' => 1]), row(2, 10, 'b', ['comp_number' => 1]),
        row(3, 11, 'c', ['comp_number' => 2]), row(4, 11, 'd', ['comp_number' => 2]),
    ]),
    [
        ['type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
        ['type' => 'verse', 'number' => 2, 'lines' => ['c', 'd'], 'chords' => null, 'language' => null, 'lineIds' => [3, 4]],
    ],
    'two verses grouped by ComponentId, byte-shape matches _getComponents'
);

/* ==================================================================== */
/* 2 — repeated identical refrain: distinct components, NOT merged by text */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 20, 'Glory', ['comp_type' => 'refrain', 'comp_number' => 0]),
        row(2, 21, 'Glory', ['comp_type' => 'refrain', 'comp_number' => 0]),
    ]),
    [
        ['type' => 'refrain', 'number' => 0, 'lines' => ['Glory'], 'chords' => null, 'language' => null, 'lineIds' => [1]],
        ['type' => 'refrain', 'number' => 0, 'lines' => ['Glory'], 'chords' => null, 'language' => null, 'lineIds' => [2]],
    ],
    'repeated refrain stays two components (grouped by cid, not text)'
);

/* ==================================================================== */
/* 3 — per-line language override emits sparse lineLanguages              */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 30, 'hello',   ['comp_lang' => 'en', 'line_lang' => 'en']),
        row(2, 30, 'bonjour', ['comp_lang' => 'en', 'line_lang' => 'fr']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['hello', 'bonjour'],
        'chords' => null, 'language' => 'en', 'lineIds' => [1, 2], 'lineLanguages' => ['en', 'fr'],
    ]],
    'per-line override -> lineLanguages emitted'
);

/* ==================================================================== */
/* 4 — all lines inherit the component language: NO lineLanguages key     */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 40, 'x', ['comp_lang' => 'ko', 'line_lang' => 'ko']),
        row(2, 40, 'y', ['comp_lang' => 'ko', 'line_lang' => 'ko']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['x', 'y'],
        'chords' => null, 'language' => 'ko', 'lineIds' => [1, 2],
    ]],
    'inherited language -> no lineLanguages key (sparse)'
);

/* a null line-language against a non-null component language IS a difference */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 41, 'a', ['comp_lang' => 'en', 'line_lang' => 'en']),
        row(2, 41, 'b', ['comp_lang' => 'en', 'line_lang' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'],
        'chords' => null, 'language' => 'en', 'lineIds' => [1, 2], 'lineLanguages' => ['en', null],
    ]],
    'null line-lang vs non-null comp-lang -> lineLanguages emitted'
);

/* ==================================================================== */
/* 5 — chords reconstructed as the parallel array (null-padded)           */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 50, 'a', ['line_chords' => json_encode(['C', 'Am'])]),
        row(2, 50, 'b', ['line_chords' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a', 'b'],
        'chords' => [['C', 'Am'], null], 'language' => null, 'lineIds' => [1, 2],
    ]],
    'per-line chords recomposed into the parallel array'
);

/* ==================================================================== */
/* 6 — componentless (ComponentId NULL): group by PartType + PartNumber   */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, null, 'a', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'verse', 'line_partnum' => 1]),
        row(2, null, 'b', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'verse', 'line_partnum' => 1]),
        row(3, null, 'c', ['comp_type' => null, 'comp_number' => null, 'line_parttype' => 'chorus', 'line_partnum' => 0]),
    ]),
    [
        ['type' => 'verse',  'number' => 1, 'lines' => ['a', 'b'], 'chords' => null, 'language' => null, 'lineIds' => [1, 2]],
        ['type' => 'chorus', 'number' => 0, 'lines' => ['c'],      'chords' => null, 'language' => null, 'lineIds' => [3]],
    ],
    'componentless lines group by part identity'
);

/* ==================================================================== */
/* 7 — empty input -> empty list                                          */
/* ==================================================================== */
assertEq(lyricLinesAssembleFromRows([]), [], 'no rows -> []');

/* ==================================================================== */
/* 8 — adjacent same-type different-number verses are NOT merged          */
/* ==================================================================== */
assertEq(
    count(lyricLinesAssembleFromRows([
        row(1, 70, 'a', ['comp_number' => 1]),
        row(2, 71, 'b', ['comp_number' => 1]),   // a SECOND verse-1 (CP-0110 twin-verse case), distinct cid
    ])),
    2,
    'twin verse-1 components (distinct cid) stay separate'
);

/* ==================================================================== */
/* 9 — #1860 Phase 5 §2.5: comp_label present -> sparse 'label' emitted   */
/*     (placed right after 'language', before 'lineIds' — see the        */
/*     $flush ordering in lyric_lines_read.php)                          */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 80, 'Kyrie eleison', ['comp_type' => 'other', 'comp_number' => 0, 'comp_label' => 'Kyrie']),
    ]),
    [[
        'type' => 'other', 'number' => 0, 'lines' => ['Kyrie eleison'],
        'chords' => null, 'language' => null, 'label' => 'Kyrie', 'lineIds' => [1],
    ]],
    "#1860 Phase 5 - comp_label present -> assembled component carries 'label' => 'Kyrie'"
);

/* ==================================================================== */
/* 10 — #1860 Phase 5 §2.5: comp_label null/'' -> NO 'label' key          */
/*      (strict === on the FULL array is what proves sparseness — a      */
/*      loose == would not catch an extra null-valued key)               */
/* ==================================================================== */
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 81, 'a', ['comp_label' => null]),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a'],
        'chords' => null, 'language' => null, 'lineIds' => [1],
    ]],
    '#1860 Phase 5 - null comp_label -> no label key (sparse, byte-identical to pre-Phase-5 shape)'
);
assertEq(
    lyricLinesAssembleFromRows([
        row(1, 82, 'a', ['comp_label' => '']),
    ]),
    [[
        'type' => 'verse', 'number' => 1, 'lines' => ['a'],
        'chords' => null, 'language' => null, 'lineIds' => [1],
    ]],
    "#1860 Phase 5 - empty-string comp_label -> no label key (sparse)"
);

/* ==================================================================== */
/* 11 — the EDITOR shape carries lineIds too (#1627)                      */
/*                                                                        */
/* lyricLinesEditableComponents() takes a live \mysqli, so unlike          */
/* lyricLinesAssembleFromRows() above it cannot be exercised as a pure     */
/* function here. This is therefore a SOURCE assertion, and it is worth    */
/* having despite that weakness because of what the omission cost:         */
/*                                                                        */
/* The assembler shape has always emitted `lineIds` (which is why every    */
/* case above asserts them), and v1's editor reads that shape. The EDITOR  */
/* shape — the one api2 and the whole v2 editor speak — dropped the        */
/* `line_id` its own query already selected. So per-line translations and  */
/* annotations (#1088) had nothing to anchor to in v2: the tables, the     */
/* read path and api2's own endpoints all existed, with no way for the     */
/* v2 UI to put data into them. Silent, and invisible from either side.    */
/*                                                                        */
/* Rule #21: enrichment anchors on tblLyricLines.Id, never a LinesJson     */
/* index — an index shifts the moment a line is inserted and the           */
/* annotation drifts onto a different line with no error.                  */
/* ==================================================================== */
$readerSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/lyric_lines_read.php'
);
/* Strip block comments — the note above the code quotes `lineIds` while
   explaining it, and prose must not satisfy an assertion about code. */
$readerCode = preg_replace('#/\*[\s\S]*?\*/#', '', $readerSrc) ?? $readerSrc;

$editableFn = '';
$fnStart = strpos($readerCode, 'function lyricLinesEditableComponents');
if ($fnStart !== false) {
    $nextFn = strpos($readerCode, "\nfunction ", $fnStart + 10);
    $editableFn = substr($readerCode, $fnStart, $nextFn === false ? null : $nextFn - $fnStart);
}

assertEq($editableFn !== '', true, 'lyricLinesEditableComponents() found in the ONE read path');
assertEq(
    str_contains($editableFn, "'lineIds'"),
    true,
    "editor shape emits 'lineIds' — without it v2 has no anchor for #1088 enrichment"
);
assertEq(
    str_contains($editableFn, "'line_id'"),
    true,
    'editor shape reads the line_id its own query selects (ll.Id AS line_id)'
);
assertEq(
    str_contains($editableFn, "'label'"),
    true,
    "#1860 Phase 5 §2.3 — editor shape emits 'label' (REQ 3b, always-present)"
);
assertEq(
    str_contains($editableFn, "'sourceWorkId'"),
    true,
    "#1860 Phase 5 §2.3 — editor shape emits 'sourceWorkId' (REQ 2, always-present)"
);

/* ==================================================================== */
/* #2072 — the EDITOR shape now ALSO carries `notes` (always-present,     */
/* mirroring `chords`), so a per-line note that is WRITTEN (the OpenLyrics */
/* importer) can also be READ BACK — before this fix, tblLyricLines.Note   */
/* was write-only and the next whole-song save silently destroyed it.     */
/* Same "source assertion" caveat as the block above: lyricLinesEditable-  */
/* Components() takes a live \mysqli, so it can't be exercised as a pure   */
/* function here.                                                         */
/* ==================================================================== */
assertEq(
    str_contains($editableFn, "'notes'"),
    true,
    "#2072 — editor shape emits 'notes' (always-present, mirroring 'chords')"
);
assertEq(
    str_contains($editableFn, 'line_note'),
    true,
    '#2072 — editor shape reads line_note (ll.Note AS line_note from lyricLinesFetchPrimary())'
);

/* ==================================================================== */
/* #2072 — PROVE the PUBLIC/export shape is untouched. lyricLinesFetchPrimary() */
/* now selects `ll.Note AS line_note` (needed by the EDITOR shape above), but  */
/* the PUBLIC assembler lyricLinesAssembleFromRows() — the function every one   */
/* of the pure-function tests above exercises, and the one                     */
/* tools/export-fidelity-snapshot.php hashes per song — must NOT read that key: */
/* it is hashed byte-for-byte across ~16,083 songs and compared with strict     */
/* `===` by this very file's cases 1-10 above, so a new always-present key      */
/* there would silently change every one of those hashes. This is the          */
/* MUTATION-PROVABLE version of that promise: teach the assembler to read       */
/* `line_note` (e.g. by sparsely emitting a public `notes` key) and this        */
/* assertion goes red immediately, without needing a live DB or the fidelity    */
/* snapshot tool to catch it.                                                   */
/* ==================================================================== */
$assembleFn = '';
$assembleFnStart = strpos($readerCode, 'function lyricLinesAssembleFromRows');
if ($assembleFnStart !== false) {
    $nextFn2    = strpos($readerCode, "\nfunction ", $assembleFnStart + 10);
    $assembleFn = substr($readerCode, $assembleFnStart, $nextFn2 === false ? null : $nextFn2 - $assembleFnStart);
}
assertEq($assembleFn !== '', true, 'lyricLinesAssembleFromRows() found in the ONE read path');
assertEq(
    str_contains($assembleFn, 'line_note'),
    false,
    "#2072 — the PUBLIC shape (lyricLinesAssembleFromRows) must NOT read line_note; " .
    "'notes' is an EDITOR-shape-only addition (pass 7 C7) so the hashed public shape stays byte-identical"
);
assertEq(
    str_contains($assembleFn, "'notes'"),
    false,
    "#2072 — the PUBLIC shape must NOT emit a 'notes' key (would change ~16k stored fidelity hashes)"
);

echo "\n  ----------------------------------------\n";
echo "  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
