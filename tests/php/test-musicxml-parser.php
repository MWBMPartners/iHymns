<?php
/**
 * iHymns — MusicXML Importer Smoke Test (#1096)
 *
 * Exercises MusicXmlImporter::parseString() against representative
 * fixtures. Doesn't touch the database — only the pure-parser surface.
 *
 *   php tests/php/test-musicxml-parser.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/appWeb/public_html/includes/MusicXmlImporter.php';

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

/* -------------------------------------------------------------------- */
/* 1. A two-verse score with syllabified words + an in-measure system   */
/*    break (the spec-correct place for <print new-system>).            */
/* -------------------------------------------------------------------- */
$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0">
  <work><work-title>Amazing Grace</work-title></work>
  <identification>
    <creator type="composer">Traditional</creator>
    <creator type="lyricist">John Newton</creator>
  </identification>
  <part-list><score-part id="P1"><part-name>Voice</part-name></score-part></part-list>
  <part id="P1">
    <measure number="1">
      <attributes>
        <key><fifths>-1</fifths><mode>minor</mode></key>
        <time><beats>4</beats><beat-type>4</beat-type></time>
      </attributes>
      <note><lyric number="1"><syllabic>begin</syllabic><text>A</text></lyric></note>
      <note><lyric number="1"><syllabic>middle</syllabic><text>ma</text></lyric></note>
      <note><lyric number="1"><syllabic>end</syllabic><text>zing</text></lyric></note>
      <note><lyric number="1"><syllabic>single</syllabic><text>grace</text></lyric>
            <lyric number="2"><syllabic>single</syllabic><text>How</text></lyric></note>
    </measure>
    <measure number="2">
      <print new-system="yes"/>
      <note><lyric number="1"><syllabic>single</syllabic><text>how</text></lyric>
            <lyric number="2"><syllabic>single</syllabic><text>sweet</text></lyric></note>
      <note><lyric number="1"><syllabic>single</syllabic><text>sweet</text></lyric></note>
    </measure>
  </part>
</score-partwise>
XML;

$r = MusicXmlImporter::parseString($xml);
assertEq($r['ok'], true, 'valid score parses ok');
assertEq($r['title'], 'Amazing Grace', 'title from <work-title>');
assertEq($r['composer'], 'Traditional', 'composer from creator[type=composer]');
assertEq($r['lyricist'], 'John Newton', 'lyricist from creator[type=lyricist]');
assertEq($r['keySignature'], 'Dm', 'fifths=-1 + mode=minor -> Dm');
assertEq($r['timeSignature'], '4/4', 'time 4/4');
assertEq(count($r['verses']), 2, 'two verses detected');
assertEq($r['verses'][0]['number'], '1', 'first verse is number 1');
assertEq($r['verses'][0]['lines'][0], 'Amazing grace', 'syllables join: begin+middle+end -> "Amazing"');
assertEq($r['verses'][0]['lines'][1], 'how sweet', 'in-measure system break splits the line');
assertEq($r['warnings'], [], 'no warnings when a break is present');

/* -------------------------------------------------------------------- */
/* 2. Key signature mapping — major sharps.                             */
/* -------------------------------------------------------------------- */
$mkKey = static function (int $fifths, string $mode): string {
    $x = <<<XML
<score-partwise version="4.0"><part-list><score-part id="P1"/></part-list>
<part id="P1"><measure number="1"><attributes>
<key><fifths>$fifths</fifths><mode>$mode</mode></key></attributes></measure></part></score-partwise>
XML;
    return (string)MusicXmlImporter::parseString($x)['keySignature'];
};
assertEq($mkKey(0, 'major'), 'C',  'fifths 0 major -> C');
assertEq($mkKey(1, 'major'), 'G',  'fifths 1 major -> G');
assertEq($mkKey(2, 'major'), 'D',  'fifths 2 major -> D');
assertEq($mkKey(-3, 'major'), 'Eb', 'fifths -3 major -> Eb');
assertEq($mkKey(7, 'major'), 'C#', 'fifths 7 major -> C#');
assertEq($mkKey(0, 'minor'), 'Am', 'fifths 0 minor -> Am');

/* -------------------------------------------------------------------- */
/* 3. Best-effort: no break hints -> single line per verse + warning.   */
/* -------------------------------------------------------------------- */
$noBreak = <<<'XML'
<score-partwise version="4.0"><part-list><score-part id="P1"/></part-list>
<part id="P1"><measure number="1">
<note><lyric number="1"><syllabic>single</syllabic><text>one</text></lyric></note>
<note><lyric number="1"><syllabic>single</syllabic><text>two</text></lyric></note>
</measure></part></score-partwise>
XML;
$nb = MusicXmlImporter::parseString($noBreak);
assertEq($nb['verses'][0]['lines'], ['one two'], 'no break -> one block line');
assertEq(count($nb['warnings']) >= 1, true, 'no-break path warns');

/* -------------------------------------------------------------------- */
/* 4. Error handling.                                                    */
/* -------------------------------------------------------------------- */
assertEq(MusicXmlImporter::parseString('<html><body>x</body></html>')['ok'], false, 'non-MusicXML rejected');
assertEq(MusicXmlImporter::parseString('not xml at all')['ok'], false, 'malformed XML rejected');

/* Melody-only (no lyrics) still extracts metadata, reports low confidence. */
$melody = <<<'XML'
<score-partwise version="4.0"><part-list><score-part id="P1"/></part-list>
<part id="P1"><measure number="1"><attributes><time><beats>3</beats><beat-type>4</beat-type></time></attributes>
<note><pitch><step>C</step><octave>4</octave></pitch></note></measure></part></score-partwise>
XML;
$m = MusicXmlImporter::parseString($melody);
assertEq($m['ok'], true, 'melody-only parses ok');
assertEq($m['verses'], [], 'melody-only has no verses');
assertEq($m['confidence'], 'low', 'melody-only is low confidence');
assertEq($m['timeSignature'], '3/4', 'melody-only still extracts meter');

/* -------------------------------------------------------------------- */
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
