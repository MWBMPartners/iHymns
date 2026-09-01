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

/* #2062 Part A — <properties><verseOrder> resolved into the `arrangement`
   key. No verseOrder in the file above (the en+de fixture) means no key at
   all — confirms the new code path is a no-op on every pre-#2062 fixture. */
aTrue(!array_key_exists('arrangement', $song), 'no <verseOrder> element -> no arrangement key (byte-identical to pre-#2062)');
aEq($song['warnings'] ?? null, [], 'no <verseOrder> -> warnings stays an empty array');

/* Chorus-between-verses: the textbook verseOrder shape ("v1 c v2 c") maps to
   repeated indices — repeats are legal and the whole point of an
   arrangement (arrangementSanitise() permits them). */
$order1 = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Order Test 1</title></titles>
<verseOrder>v1 c v2 c</verseOrder></properties>
<lyrics>
 <verse name="v1"><lines>Verse one</lines></verse>
 <verse name="c"><lines>Chorus</lines></verse>
 <verse name="v2"><lines>Verse two</lines></verse>
</lyrics></song>
XML;
[$o1] = _bulkImport_parseOpenLyrics($order1);
aEq($o1['arrangement'] ?? null, [0, 1, 2, 1], 'chorus-between-verses verseOrder -> [0,1,2,1]');
aEq($o1['warnings'] ?? null, [], 'every token resolved -> no warnings');

/* Expand-all on a duplicate verse name (D1 default, #2062): a token naming
   SEVERAL same-name components (the real en+de translation-pair shape, as in
   the fixture at the top of this file) must expand to ALL of them in
   document order, not just the first — the public render excludes anything
   not listed, so first-occurrence-wins would silently HIDE the second
   translation verse. Mixed with a repeat ("v1" used twice) and a unique
   token ("c") in one verseOrder so the assertion also exercises repeats. */
$order2 = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Order Test 2</title></titles>
<verseOrder>v1 c v1</verseOrder></properties>
<lyrics>
 <verse name="v1" lang="en"><lines>English</lines></verse>
 <verse name="v1" lang="de"><lines>German</lines></verse>
 <verse name="c"><lines>Chorus</lines></verse>
</lyrics></song>
XML;
[$o2] = _bulkImport_parseOpenLyrics($order2);
aEq($o2['arrangement'] ?? null, [0, 1, 2, 0, 1], 'duplicate-name token expands to ALL same-name indices, each occurrence (en+de x2 + chorus)');
/* MUTATION PROOF 1 (rule #34): swapping the expand-all `foreach
   ($nameToIndices[$key] as $idx) { $resolved[] = $idx; }` for
   first-occurrence-wins (`$resolved[] = $nameToIndices[$key][0];`) turns
   this expectation into [0,2,0] — a different, shorter array — and this
   assertion goes red. Actually applied to the source and run before
   committing (this assertion failed exactly as predicted); the source was
   then restored to expand-all. */

/* Unknown token: skipped (never all-or-nothing), and recorded as a warning.
   The other two tokens in the same verseOrder still resolve normally. */
$order3 = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Order Test 3</title></titles>
<verseOrder>v1 x c</verseOrder></properties>
<lyrics>
 <verse name="v1"><lines>Verse one</lines></verse>
 <verse name="c"><lines>Chorus</lines></verse>
 <verse name="v2"><lines>Verse two</lines></verse>
</lyrics></song>
XML;
[$o3] = _bulkImport_parseOpenLyrics($order3);
aEq($o3['arrangement'] ?? null, [0, 1], 'unknown verseOrder token is skipped, not all-or-nothing');
aEq(count($o3['warnings'] ?? []), 1, 'the skipped token produced exactly one warning');
aTrue(str_contains($o3['warnings'][0] ?? '', 'x'), 'the warning names the unresolved token');

/* Identity suppression (load-bearing, #2062): a verseOrder that names every
   component in its natural document order must NOT stamp an arrangement at
   all — iHymns' own OpenLyrics exporter always emits a natural-order
   <verseOrder> (format-export.js), so without this suppression every
   re-imported iHymns export would carry a redundant, no-op arrangement. */
$order4 = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Order Test 4</title></titles>
<verseOrder>v1 c v2</verseOrder></properties>
<lyrics>
 <verse name="v1"><lines>Verse one</lines></verse>
 <verse name="c"><lines>Chorus</lines></verse>
 <verse name="v2"><lines>Verse two</lines></verse>
</lyrics></song>
XML;
[$o4] = _bulkImport_parseOpenLyrics($order4);
aTrue(!array_key_exists('arrangement', $o4), 'natural-order verseOrder -> NO arrangement key (identity suppression)');
/* MUTATION PROOF 2 (rule #34): deleting the `if ($resolved !== $identity)`
   guard (always assigning `$arrangement = $resolved`) turns this expectation
   from ABSENT into a present [0,1,2] key, and this assertion goes red.
   Actually applied to the source and run before committing (this assertion
   failed exactly as predicted); the guard was then restored. */

if ($failed === 0) { echo "\nAll OpenLyrics enrichment assertions passed ($passed).\n"; exit(0); }
fwrite(STDERR, "\n$failed assertion(s) failed.\n");
exit(1);
