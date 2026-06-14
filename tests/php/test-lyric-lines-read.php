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

echo "\n  ----------------------------------------\n";
echo "  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
