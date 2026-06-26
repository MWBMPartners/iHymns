<?php
/**
 * iHymns — ChordPro Importer Smoke Test (#1264)
 *
 * Exercises _bulkImport_parseChordPro() + its helpers against representative
 * fixtures. Pure-parser surface only — no DB, no HTTP.
 *
 *   php tests/php/test-chordpro-parser.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

/* song_importers.php is a pure-function include: its only top-level side effect
   is a direct-access guard keyed on SCRIPT_FILENAME (which, under CLI, is THIS
   test file, not the include — so the guard passes), and the parser functions
   touch no DB at include time. So we can require it directly. */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

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
function assertTrue($actual, string $label): void { assertEq((bool)$actual, true, $label); }

/* -------------------------------------------------------------------- */
/* Fixture 1: explicit {start_of_*} sections + inline [chord]s + headers. */
/* -------------------------------------------------------------------- */
echo "Fixture 1 — start_of_* sections + inline chords + directives:\n";
$cp1 = <<<CHO
{title: Amazing Grace}
{artist: John Newton}
{ccli: CCLI 22025}
{copyright: Public Domain}

{start_of_verse}
[G]Amazing [G7]grace how [C]sweet the [G]sound
That [G]saved a [D]wretch like [G]me
{end_of_verse}

{start_of_chorus}
[C]Praise the [G]Lord
{end_of_chorus}
CHO;
[$song, $err] = _bulkImport_parseChordPro($cp1, 'TEST', 'Test Book', 42);
assertEq($err, null, 'fixture 1 parses without error');
assertEq($song['title'] ?? null, 'Amazing Grace', 'title from {title}');
assertEq($song['ccli'] ?? null, '22025', '{ccli} reduced to digits');
assertEq($song['copyright'] ?? null, 'Public Domain', '{copyright} captured');
assertEq($song['composers'] ?? null, ['John Newton'], '{artist} → composers');
assertEq($song['id'] ?? null, 'TEST-0042', 'SongId = <ABBR>-<padded#>');
assertEq(count($song['components'] ?? []), 2, 'two components (verse + chorus)');
assertEq($song['components'][0]['type'] ?? null, 'verse', 'comp 0 is a verse');
assertEq($song['components'][0]['number'] ?? null, 1, 'verse auto-numbered 1');
assertEq(
    $song['components'][0]['lines'] ?? null,
    ['Amazing grace how sweet the sound', 'That saved a wretch like me'],
    'inline [chord] markers stripped → clean lyrics'
);
assertEq($song['components'][1]['type'] ?? null, 'chorus', 'comp 1 is a chorus');
assertEq($song['components'][1]['lines'] ?? null, ['Praise the Lord'], 'chorus lyric stripped');

/* #1126 — chords RETAINED parallel to lines (space-separated symbols per line). */
assertEq(
    $song['components'][0]['chords'] ?? null,
    ['G G7 C G', 'G D G'],
    'verse chords captured parallel to lines (#1126)'
);
assertEq(
    $song['components'][1]['chords'] ?? null,
    ['C G'],
    'chorus chords captured (#1126)'
);
/* A component with NO chords must NOT carry a `chords` key (byte-identical to pre-#1126). */
$cpNoChords = "{title: Plain}\n{start_of_verse}\nNo chords here\nNor here\n{end_of_verse}";
[$songNC] = _bulkImport_parseChordPro($cpNoChords, 'TEST', 'Test Book', 1);
assertTrue(!array_key_exists('chords', $songNC['components'][0] ?? []), 'chordless component omits the chords key (#1126)');
/* The split helper directly. */
$split = _bulkImport_chordProSplitLine('[G]Amazing [C7]grace [D/F#]how');
assertEq($split['lyric'], 'Amazing grace how', 'splitLine strips to clean lyric');
assertEq($split['chords'], 'G C7 D/F#', 'splitLine joins symbols (compound chords preserved)');
$splitNone = _bulkImport_chordProSplitLine('Just words');
assertEq($splitNone['chords'], '', 'splitLine returns empty chords for a plain line');

/* -------------------------------------------------------------------- */
/* Fixture 2: {comment:} section labels + blank-line breaks (OnSong /     */
/* WorshipTools hand-copied shape) + a chord-only line.                   */
/* -------------------------------------------------------------------- */
echo "Fixture 2 — {comment:} labels + blank-line sections + chord-only line:\n";
$cp2 = <<<CHO
{title: Test Song}
{comment: Verse 1}
[C]Line one
[G]Line two

{comment: Chorus}
[C] [G] [D]
[F]Chorus line
CHO;
[$song2, $err2] = _bulkImport_parseChordPro($cp2, 'TEST', 'Test Book', 7);
assertEq($err2, null, 'fixture 2 parses without error');
assertEq(count($song2['components'] ?? []), 2, 'two labelled components');
assertEq($song2['components'][0]['type'] ?? null, 'verse', '{comment: Verse 1} → verse');
assertEq($song2['components'][0]['number'] ?? null, 1, 'verse number from label');
assertEq($song2['components'][0]['lines'] ?? null, ['Line one', 'Line two'], 'verse lyrics');
assertEq($song2['components'][1]['type'] ?? null, 'chorus', '{comment: Chorus} → chorus');
assertEq(
    $song2['components'][1]['lines'] ?? null,
    ['Chorus line'],
    'chord-only line ("[C] [G] [D]") dropped; lyric line kept'
);

/* -------------------------------------------------------------------- */
/* Fixture 3: rejection — no {title}.                                     */
/* -------------------------------------------------------------------- */
echo "Fixture 3 — rejects a document with no {title}:\n";
[$song3, $err3] = _bulkImport_parseChordPro("{artist: Nobody}\n[C]Just a line\n", 'TEST', 'Test Book', 1);
assertEq($song3, null, 'no {title} → null song');
assertEq($err3, 'no {title} directive', 'no {title} → explicit reason');

/* -------------------------------------------------------------------- */
/* Helper unit: section-label → {type, number}.                          */
/* -------------------------------------------------------------------- */
echo "Helper — _bulkImport_chordProSectionFromLabel():\n";
assertEq(_bulkImport_chordProSectionFromLabel('Verse 2'), ['type' => 'verse', 'number' => 2], 'label "Verse 2"');
assertEq(_bulkImport_chordProSectionFromLabel('Chorus'),  ['type' => 'chorus', 'number' => 0], 'label "Chorus"');
assertEq(_bulkImport_chordProSectionFromLabel('Bridge'),  ['type' => 'bridge', 'number' => 0], 'label "Bridge"');

echo "\n" . ($failed === 0 ? "ALL PASS" : "FAILURES") . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
