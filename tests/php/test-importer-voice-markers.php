<?php

declare(strict_types=1);

/**
 * iHymns — Four importers stop fabricating fake 'refrain' sections from
 * voice markers, and stop discarding the word either way (#2075)
 *
 * ELI5: this proves the actual bug in issue #2075 is fixed, in all four
 * places it happened — a text file, an OpenSong file, a VideoPsalm
 * songbook, and an OpenLyrics file each used to turn an unrecognised
 * marker word ("WOMEN", "MEN", "ALL", or a genuine foreign-language
 * section name) into an anonymous `refrain` component with the WORD
 * ITSELF thrown away. After this fix: a plain-text VOICE cue is never
 * turned into a fake section at all (the marker line becomes an ordinary
 * continuing lyric line — the SAME rule Paste & Reflow already follows
 * for the same situation), and a genuinely unrecognised word still gets
 * SOME structural home but keeps its own text as a display `label`
 * instead of vanishing (the pattern the ProPresenter-7 importer already
 * used before this fix, rule #45 of .claude/CLAUDE.md).
 *
 * TWO KINDS OF PROOF, per this task's own instructions:
 *   1. STRUCTURAL — every one of the four sites named in #2075's own
 *      issue text (`song_importers.php` at the historical line numbers
 *      252 / 2165 / 2425 / 3076) is DERIVED from the tree (by extracting
 *      each named function's own source span) and asserted to route
 *      through the ONE shared classifier, `_bulkImport_classifyMarker()`
 *      — never a hand-typed "yes it's fixed" note with nothing checking
 *      it (rule #34's "tree-derived, not a typed list").
 *   2. FUNCTIONAL — the actual parser functions are called with real
 *      voice-marker input and the resulting component array is asserted
 *      to (a) never fabricate an extra `refrain #0` component from a
 *      voice cue, (b) never lose the marker word, and (c) leave a
 *      marker-free fixture byte-for-byte exactly as it always parsed.
 *
 *   php tests/php/test-importer-voice-markers.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failed.
 *
 * @see appWeb/public_html/includes/song_importers.php   the file under test
 * @see appWeb/public_html/includes/vocal_part_detect.php the shared detector this fix consumes
 * @see https://github.com/MWBMPartners/iHymns/issues/2075  the bug this file proves fixed
 * @see .claude/vocal-parts-2073-plan.md                  the plan of record ("Design pass 7" §10, commit 10)
 */

$root = dirname(__DIR__, 2);
require $root . '/appWeb/public_html/includes/song_importers.php';

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

/**
 * Pull ONE top-level `function <name>(...) { ... }` body out of the
 * importer source by brace-counting from the `function <name>(` token to
 * its matching close brace — the same "derive the span, don't hand-copy
 * it" approach `test-lyrics-version-resolver.php` and several other
 * guards in this tree already use for exactly this reason (rule #34: a
 * check must be tree-derived, not a string typed once and never
 * re-verified against the real file).
 */
function extractFunctionBody(string $source, string $functionName): ?string
{
    $needle = 'function ' . $functionName . '(';
    $start  = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $braceOpen = strpos($source, '{', $start);
    if ($braceOpen === false) {
        return null;
    }
    $depth = 0;
    $len   = strlen($source);
    for ($i = $braceOpen; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

$source = (string)file_get_contents($root . '/appWeb/public_html/includes/song_importers.php');
ok('the importer source file was actually read (guard against a silently-empty scan)', strlen($source) > 10000);

/* ====================================================================== *
 * 1 — STRUCTURAL: each of the four #2075 sites routes through the ONE
 *     shared classifier, derived from the real function bodies
 * ====================================================================== */
echo "1 — structural: all four sites call the shared classifier\n";

/* #2075's own issue text names these EXACT four call sites by their
   historical line numbers (252 / 2165 / 2425 / 3076) — the four function
   NAMES below are what actually own each of those spots today (verified
   by grepping the live file at the start of this task); deriving the
   body from the name, rather than re-typing the historical line numbers
   here, means this check keeps working even after the file grows or
   shrinks above these functions. */
$siteFunctions = [
    '.txt (_bulkImport_parseTxt)'                        => '_bulkImport_parseTxt',
    'OpenSong (_bulkImport_parseOpenSongLyrics)'          => '_bulkImport_parseOpenSongLyrics',
    'VideoPsalm (_bulkImport_parseVideoPsalmSongbook)'    => '_bulkImport_parseVideoPsalmSongbook',
    'OpenLyrics verse-name (_bulkImport_openLyricsVerseType)' => '_bulkImport_openLyricsVerseType',
];
foreach ($siteFunctions as $label => $fn) {
    $body = extractFunctionBody($source, $fn);
    ok("$label was found in the source at all", $body !== null);
    if ($body !== null) {
        ok("$label calls _bulkImport_classifyMarker()", str_contains($body, '_bulkImport_classifyMarker('));
    }
}

/* The three format-specific "known word" maps were extracted into their
   own getter functions (`_bulkImport_sectionWordMap()`,
   `_bulkImport_openSongLetterMap()`, `_bulkImport_videopsalmLetterMap()`,
   `_bulkImport_openLyricsLetterMap()`) SPECIFICALLY so the classifier and
   the original `_bulkImport_..._ComponentTypeFor()` lookup share ONE
   list each (rule #22/#35) — assert all four getters exist and that each
   original `...ComponentTypeFor()`/`...VerseType()` function's own body
   calls its OWN getter (never hand-copies the same words a second time). */
$mapPairs = [
    ['_bulkImport_componentTypeFor', '_bulkImport_sectionWordMap'],
    ['_bulkImport_openSongComponentTypeFor', '_bulkImport_openSongLetterMap'],
    ['_bulkImport_videopsalmComponentTypeFor', '_bulkImport_videopsalmLetterMap'],
];
foreach ($mapPairs as [$typeForFn, $mapFn]) {
    ok("$mapFn() is a real function", function_exists($mapFn));
    $body = extractFunctionBody($source, $typeForFn);
    ok("$typeForFn() itself still calls $mapFn() (pure extraction, no behaviour change)", $body !== null && str_contains($body, $mapFn . '('));
}
ok('_bulkImport_openLyricsLetterMap() is a real function', function_exists('_bulkImport_openLyricsLetterMap'));

/* MUTATION-PROOF STRUCTURAL FLOOR: prove the site-detection itself isn't
   vacuously true — a function name this file never heard of must NOT be
   reported as "found". */
ok(
    'MUTATION: a made-up function name is correctly reported as NOT found (the extractor is not vacuously true)',
    extractFunctionBody($source, '_bulkImport_thisFunctionDoesNotExist') === null
);

/* ====================================================================== *
 * 2 — .txt importer (_bulkImport_parseTxt) — #2075's own worked example
 * ====================================================================== */
echo "\n2 — .txt importer\n";

/* #2075's own issue text, verbatim shape: three voice-cue headers, each
   followed by lyric lines. Before this fix: THREE separate anonymous
   "refrain #0" components, with "WOMEN"/"MEN"/"ALL" all discarded. */
$txtBody = "Test Song\n\nWOMEN\nHe who dwells, he who dwells\n\nMEN\nhe who dwells, he who dwells\n\nALL\nAnd I'll say of the Lord\n";
[$txtSong, $txtErr] = _bulkImport_parseTxt($txtBody, 'AB', 'Test Book', 1);
ok('.txt WOMEN/MEN/ALL parses without error', $txtErr === null && $txtSong !== null);
$txtComponents = $txtSong['components'] ?? [];
assertEq(count($txtComponents), 1, '.txt: WOMEN/MEN/ALL no longer fabricates THREE separate "refrain #0" components — only the ONE real section survives');
$txtAllLines = $txtComponents[0]['lines'] ?? [];
ok(
    '.txt: every one of the three voice words survives SOMEWHERE in the merged component (nothing silently discarded)',
    in_array('WOMEN', $txtAllLines, true) && in_array('MEN', $txtAllLines, true) && in_array('ALL', $txtAllLines, true),
    'lines: ' . var_export($txtAllLines, true)
);
ok(
    '.txt: every one of the six original lyric lines survives, in document order',
    $txtAllLines === ['WOMEN', 'He who dwells, he who dwells', 'MEN', 'he who dwells, he who dwells', 'ALL', "And I'll say of the Lord"]
);

/* A genuinely unrecognised (not a section word, not a voice cue either)
   marker still needs a structural home in this format, but now KEEPS its
   own text as a label instead of vanishing (rule #45's ProPresenter-7
   pattern). */
[$txtSong2, $txtErr2] = _bulkImport_parseTxt("Foreign Song\n\nCoro\nUn line de la cancion\n", 'AB', 'Test Book', 2);
ok('.txt: a genuinely unknown word parses without error', $txtErr2 === null && $txtSong2 !== null);
$comp2 = ($txtSong2['components'] ?? [])[0] ?? [];
assertEq($comp2['type'] ?? null, 'refrain', '.txt: an unrecognised, non-voice word keeps the pre-#2075 \'refrain\' fallback type');
assertEq($comp2['label'] ?? null, 'Coro', '.txt: the unrecognised word survives as a display label instead of being discarded');
assertEq($comp2['lines'] ?? null, ['Un line de la cancion'], '.txt: the label case never mixes the marker text into the lyric lines');

/* Marker-free fixture — a known structural word plus a numbered verse —
   must stay BYTE-IDENTICAL to what this importer has always produced. */
$txtPlain = "Amazing Grace\n\n1\nAmazing grace how sweet the sound\nThat saved a wretch like me\n\nRefrain\nI once was lost but now am found\n";
[$txtPlainSong, $txtPlainErr] = _bulkImport_parseTxt($txtPlain, 'AB', 'Test Book', 3);
ok('.txt: marker-free fixture parses without error', $txtPlainErr === null);
assertEq($txtPlainSong['components'] ?? null, [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Amazing grace how sweet the sound', 'That saved a wretch like me']],
    ['type' => 'refrain', 'number' => 0, 'lines' => ['I once was lost but now am found']],
], '.txt: a file with NO voice markers at all stays byte-for-byte identical to the pre-#2075 shape (no stray "label" key, no merging)');

/* A voice cue that opens the VERY FIRST block (nothing to merge into
   yet) must still land somewhere — an unlabelled verse — rather than
   crashing or losing the line. */
[$txtFirst, $txtFirstErr] = _bulkImport_parseTxt("First Block Song\n\nWOMEN\nHe who dwells\n", 'AB', 'Test Book', 4);
ok('.txt: a voice cue as the VERY FIRST block parses without error', $txtFirstErr === null);
assertEq(
    $txtFirst['components'] ?? null,
    [['type' => 'verse', 'number' => 0, 'lines' => ['WOMEN', 'He who dwells']]],
    '.txt: with nothing to merge into, the cue still lands as an ordinary lyric line of a fresh verse — never lost, never a labelled fake section'
);

/* ====================================================================== *
 * 3 — OpenSong importer (_bulkImport_parseOpenSongLyrics)
 * ====================================================================== */
echo "\n3 — OpenSong importer\n";

$osComponents = _bulkImport_parseOpenSongLyrics("[V1]\n Line one\n Line two\n\n[Women]\n Women line\n");
assertEq(count($osComponents), 2, 'OpenSong: a bracketed voice tag ("[Women]") still becomes its own component (this format keeps the "label" strategy — see this file\'s own doc-block for why merging is NOT attempted here)');
assertEq($osComponents[1]['type'] ?? null, 'refrain', 'OpenSong: the unrecognised bracket tag keeps the pre-#2075 \'refrain\' fallback');
assertEq($osComponents[1]['label'] ?? null, 'Women', 'OpenSong: the bracket tag\'s own text ("Women") survives as a label instead of being discarded');
assertEq($osComponents[1]['lines'] ?? null, ['Women line'], 'OpenSong: the lyric line under the tag is untouched');

/* Marker-free fixture stays byte-identical. */
$osPlain = _bulkImport_parseOpenSongLyrics("[V1]\n Line one\n\n[C]\n Chorus line\n");
assertEq($osPlain, [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Line one']],
    ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line']],
], 'OpenSong: a file with only known letters ([V1]/[C]) stays byte-for-byte identical — no stray "label" key appears');

/* ====================================================================== *
 * 4 — VideoPsalm importer (_bulkImport_parseVideoPsalmSongbook)
 * ====================================================================== */
echo "\n4 — VideoPsalm importer\n";

$vpJsonWithVoice = json_encode([
    'Text' => 'VP Book',
    'Songs' => [[
        'Text' => 'VP Song', 'Number' => 1,
        'Verses' => [
            ['Tag' => 'V1', 'Text' => 'Verse one line'],
            ['Tag' => 'Women', 'Text' => 'Women line'],
        ],
    ]],
]);
[$vpBook, $vpSongs, $vpErr] = _bulkImport_parseVideoPsalmSongbook($vpJsonWithVoice, null);
ok('VideoPsalm: a Tag="Women" song parses without error', $vpErr === null && $vpSongs !== null);
$vpComponents = $vpSongs[0]['components'] ?? [];
assertEq(count($vpComponents), 2, 'VideoPsalm: a Tag="Women" verse still becomes its own component (label strategy, same reasoning as OpenSong)');
assertEq($vpComponents[1]['type'] ?? null, 'refrain', 'VideoPsalm: an unrecognised Tag keeps the pre-#2075 \'refrain\' fallback');
assertEq($vpComponents[1]['label'] ?? null, 'Women', 'VideoPsalm: the Tag text survives as a label instead of being discarded');
assertEq($vpComponents[1]['lines'] ?? null, ['Women line'], 'VideoPsalm: the verse\'s own Text is untouched');

/* Marker-free fixture stays byte-identical. */
$vpJsonPlain = json_encode([
    'Text' => 'VP Book',
    'Songs' => [[
        'Text' => 'VP Song', 'Number' => 1,
        'Verses' => [
            ['Tag' => 'V1', 'Text' => 'Verse one line'],
            ['Tag' => 'C', 'Text' => 'Chorus line'],
        ],
    ]],
]);
[, $vpPlainSongs] = _bulkImport_parseVideoPsalmSongbook($vpJsonPlain, null);
assertEq($vpPlainSongs[0]['components'] ?? null, [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Verse one line']],
    ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line']],
], 'VideoPsalm: a song with only known Tags (V1/C) stays byte-for-byte identical — no stray "label" key');

/* A bare, untagged verse (Tag === "") is a distinct code path from an
   UNRECOGNISED tag — must still behave exactly as before (plain verse,
   no label ever attached to an untagged verse). */
$vpJsonBare = json_encode([
    'Text' => 'VP Book',
    'Songs' => [['Text' => 'VP Song', 'Number' => 1, 'Verses' => [['Tag' => '', 'Text' => 'Untagged line']]]],
]);
[, $vpBareSongs] = _bulkImport_parseVideoPsalmSongbook($vpJsonBare, null);
assertEq($vpBareSongs[0]['components'] ?? null, [['type' => 'verse', 'number' => 0, 'lines' => ['Untagged line']]], 'VideoPsalm: an untagged (Tag="") verse is unaffected by the #2075 fix');

/* ====================================================================== *
 * 5 — OpenLyrics importer (_bulkImport_openLyricsVerseType / _bulkImport_parseOpenLyrics)
 * ====================================================================== */
echo "\n5 — OpenLyrics importer\n";

assertEq(_bulkImport_openLyricsVerseType('v1'), ['verse', 1, null], 'OpenLyrics: a known letter+number keeps its EXACT pre-#2075 two-value meaning (label is null)');
assertEq(_bulkImport_openLyricsVerseType('c'), ['chorus', 0, null], 'OpenLyrics: a known bare letter is unaffected');
$womenType = _bulkImport_openLyricsVerseType('women');
assertEq($womenType[0], 'refrain', 'OpenLyrics: an unrecognised verse name keeps the pre-#2075 \'refrain\' fallback type');
assertEq($womenType[2], 'women', 'OpenLyrics: the raw verse name survives as the third tuple element instead of being discarded');

$olWithVoice = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Test OL Song</title></titles></properties>
  <lyrics>
    <verse name="v1"><lines>Line one</lines></verse>
    <verse name="women"><lines>Women line</lines></verse>
  </lyrics>
</song>
XML;
[$olParsed, $olErr] = _bulkImport_parseOpenLyrics($olWithVoice);
ok('OpenLyrics: a verse name="women" document parses without error', $olErr === null && $olParsed !== null);
$olComponents = $olParsed['components'] ?? [];
assertEq(count($olComponents), 2, 'OpenLyrics: an unrecognised verse name still becomes its own component (label strategy)');
assertEq($olComponents[1]['label'] ?? null, 'women', 'OpenLyrics: the verse name survives as a label on the assembled component');
ok(
    'OpenLyrics: a warnings[] note records the label fallback for curator visibility',
    in_array('verse name "women" was not recognised — kept as a label', $olParsed['warnings'] ?? [], true)
);

/* Marker-free fixture stays byte-identical (including the warnings[] key
   staying an EMPTY array, not gaining a stray entry). */
$olPlain = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties><titles><title>Plain OL Song</title></titles></properties>
  <lyrics>
    <verse name="v1"><lines>Line one</lines></verse>
    <verse name="c"><lines>Chorus line</lines></verse>
  </lyrics>
</song>
XML;
[$olPlainParsed] = _bulkImport_parseOpenLyrics($olPlain);
assertEq($olPlainParsed['components'] ?? null, [
    ['type' => 'verse', 'number' => 1, 'lines' => ['Line one']],
    ['type' => 'chorus', 'number' => 0, 'lines' => ['Chorus line']],
], 'OpenLyrics: a document with only known verse names stays byte-for-byte identical — no stray "label" key');
assertEq($olPlainParsed['warnings'] ?? null, [], 'OpenLyrics: no unrecognised name at all -> warnings[] stays empty, exactly as before this fix');

/* <verseOrder> resolution must keep resolving against the RAW verse name
   (never through the lossy [type,num] pair) even for an unrecognised
   name — this fix must not touch that #2062 behaviour at all. */
$olWithOrder = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song">
  <properties>
    <titles><title>Order OL Song</title></titles>
    <verseOrder>women v1</verseOrder>
  </properties>
  <lyrics>
    <verse name="v1"><lines>Line one</lines></verse>
    <verse name="women"><lines>Women line</lines></verse>
  </lyrics>
</song>
XML;
[$olOrderParsed] = _bulkImport_parseOpenLyrics($olWithOrder);
assertEq($olOrderParsed['arrangement'] ?? null, [1, 0], 'OpenLyrics: <verseOrder> still resolves an unrecognised-but-labelled verse name correctly (component 1 = "women", played before component 0 = "v1") — #2075 does not disturb #2062');

/* ====================================================================== */
echo "\n";
if ($failed > 0) {
    echo "$failed assertion(s) failed ($passed passed).\n";
    exit(1);
}
echo "All $passed importer-voice-marker assertions passed.\n";
