<?php

declare(strict_types=1);

/**
 * iHymns — OpenLyrics importer enrichment test (#1130)
 *
 * Verifies the importer no longer FLATTENS OpenLyrics enrichment: inline
 * <chord> → per-line chords, <comment> → per-line notes, and a translated
 * <verse lang="…"> → the component's language. Pure-parser surface (no DB).
 *
 *   php tests/php/test-openlyrics-parser.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

$passed = 0; $failed = 0;
function aEq($actual, $expected, string $label): void
{
    global $passed, $failed;
    if ($actual === $expected) { echo "  PASS  $label\n"; $passed++; }
    else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}
function aTrue($cond, string $label): void { aEq((bool)$cond, true, $label); }

/* Fixture: inline chords (0.8 name= and 0.9 root/structure/bass), a per-line
   <comment>, and an en + de translation verse pair. */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song" version="0.9">
 <properties>
  <titles><title>Amazing Grace</title></titles>
  <authors><author>John Newton</author></authors>
  <copyright>Public Domain</copyright>
  <ccliNo>22025</ccliNo>
 </properties>
 <lyrics>
  <verse name="v1" lang="en">
   <lines><chord name="G"/>Amazing <chord name="C"/>grace<br/>how sweet the <chord root="D" structure="7" bass="F#"/>sound<comment>softly</comment></lines>
  </verse>
  <verse name="v1" lang="de">
   <lines>Erstaunliche Gnade<br/>wie süßer Klang</lines>
  </verse>
 </lyrics>
</song>
XML;

[$song, $err] = _bulkImport_parseOpenLyrics($xml);
aEq($err, null, 'parses without error');
aEq($song['title'] ?? null, 'Amazing Grace', 'title');
aEq($song['ccli'] ?? null, '22025', 'ccliNo');
aEq(count($song['components'] ?? []), 2, 'two components (en + de verse)');
aEq($song['altTitles'] ?? null, [], 'single-<title> song carries no alternative titles (#1669)');

$c0 = $song['components'][0] ?? [];
aEq($c0['lines'] ?? null, ['Amazing grace', 'how sweet the sound'], 'verse 1 clean lyrics');
aEq($c0['chords'] ?? null, ['G C', 'D7/F#'], 'inline chords captured per line (name= and root/structure/bass)');
aEq($c0['notes'] ?? null, ['', 'softly'], 'per-line <comment> → note (parallel to lines)');
aEq($c0['language'] ?? null, 'en', 'verse language captured');

$c1 = $song['components'][1] ?? [];
aEq($c1['lines'] ?? null, ['Erstaunliche Gnade', 'wie süßer Klang'], 'translation verse lyrics (UTF-8 preserved)');
aEq($c1['language'] ?? null, 'de', 'translation verse language = de');
aTrue(!array_key_exists('chords', $c1), 'chordless verse omits chords key (byte-identical to pre-#1130)');
aTrue(!array_key_exists('notes', $c1), 'noteless verse omits notes key');

/* A wholly plain OpenLyrics song must produce NO enrichment keys. */
$plain = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Plain</title></titles></properties>
<lyrics><verse name="v1"><lines>Line one<br/>Line two</lines></verse></lyrics></song>
XML;
[$ps] = _bulkImport_parseOpenLyrics($plain);
$pc = $ps['components'][0] ?? [];
aEq($pc['lines'] ?? null, ['Line one', 'Line two'], 'plain song lyrics');
aTrue(!array_key_exists('chords', $pc) && !array_key_exists('notes', $pc) && !array_key_exists('language', $pc),
    'plain verse carries no enrichment keys');

/* #1669 — an OpenLyrics song with several <title> elements: the first
   non-empty is the main title; each remaining DISTINCT non-empty one becomes
   an alternative title, carrying its optional lang attribute. An empty
   <title> is ignored, and a repeat of the main title (any case) is dropped. */
$multi = <<<XML
<?xml version="1.0"?>
<song><properties><titles>
  <title>Amazing Grace</title>
  <title lang="es">Sublime Gracia</title>
  <title>Faith's Review and Expectation</title>
  <title></title>
  <title>amazing grace</title>
  <title>Sublime Gracia</title>
</titles></properties>
<lyrics><verse name="v1"><lines>Line one</lines></verse></lyrics></song>
XML;
[$ms, $mErr] = _bulkImport_parseOpenLyrics($multi);
aEq($mErr, null, 'multi-title song parses without error');
aEq($ms['title'] ?? null, 'Amazing Grace', 'first non-empty <title> is the MAIN title');
aEq($ms['altTitles'] ?? null, [
    ['title' => 'Sublime Gracia', 'language' => 'es'],
    ['title' => "Faith's Review and Expectation", 'language' => ''],
], 'remaining distinct non-empty <title>s become alt titles (empty skipped, main-dup dropped, case-insensitive alt-dup dropped, lang carried)');

if ($failed === 0) { echo "\nAll OpenLyrics enrichment assertions passed ($passed).\n"; exit(0); }
fwrite(STDERR, "\n$failed assertion(s) failed.\n");
exit(1);
