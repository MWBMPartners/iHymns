<?php
/**
 * iHymns — Interchange-JSON Importer Test (#1633)
 *
 * Exercises the PURE parser surface of the iHymns interchange importer:
 * `_bulkImport_parseIHymnsJson()` and its routing sniff
 * `_bulkImport_looksLikeIHymnsJson()`. No database is touched — the save path
 * (`_bulkImport_saveSong()` → `lyricLinesWriteComponents()`) needs MySQL and is
 * exercised by the shared importer suites, so this file asserts the contract
 * BETWEEN the two: that the dicts the parser emits are the shape the saver
 * consumes.
 *
 *   php tests/php/test-ihymns-json-import.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

/* ELI5: load the real importer file and call its functions directly.
 *
 * Detail — the same loader the sibling parser suites use
 * (tests/php/test-videopsalm-parser.php, test-chordpro-parser.php). Requiring
 * the real module rather than scraping source text is what makes these tests
 * survive the next refactor: the public entry points are the contract, a
 * source-text scrape re-breaks whenever anything is reordered (#1575).
 *
 * Safe to require directly: song_importers.php is a pure-function include whose
 * only top-level side effect is a direct-access guard keyed on
 * $_SERVER['SCRIPT_FILENAME'] — under the CLI that is THIS test file, not the
 * include, so the guard passes. No parser touches the DB at include time.
 * https://www.php.net/manual/en/reserved.variables.server.php */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

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
/** Assert an error string is non-empty AND mentions $needle — a real message,
 *  not just "false". A parser that fails silently is the bug #1633 guards against. */
function assertErrorMentions(?string $err, string $needle, string $label): void
{
    global $passed, $failed;
    if (is_string($err) && $err !== '' && stripos($err, $needle) !== false) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        echo "        expected an error mentioning: " . var_export($needle, true) . "\n";
        echo "        actual error:                 " . var_export($err, true) . "\n";
        $failed++;
    }
}

/* -------------------------------------------------------------------- */
/* Document builders — a valid baseline every negative test mutates.     */
/* -------------------------------------------------------------------- */

/** The minimum document the schema calls valid: full meta, one songbook, one song. */
function baselineDoc(): array
{
    return [
        'meta' => [
            'generatedAt'      => '2026-01-01T00:00:00.000Z',
            'generatorVersion' => '1.0.0',
            'totalSongs'       => 1,
            'totalSongbooks'   => 1,
        ],
        'songbooks' => [
            ['id' => 'TF', 'name' => 'Testing Fixture Hymnal', 'songCount' => 1],
        ],
        'songs' => [
            [
                'id'                 => 'TF-0001',
                'number'             => 1,
                'title'              => 'Rivers of Wonder',
                'songbook'           => 'TF',
                'songbookName'       => 'Testing Fixture Hymnal',
                'language'           => 'en',
                'writers'            => ['A. Fictional Author'],
                'composers'          => ['A. Fictional Composer'],
                'copyright'          => '',
                'ccli'               => '',
                'verified'           => true,
                'lyricsPublicDomain' => true,
                'musicPublicDomain'  => true,
                'hasAudio'           => false,
                'hasSheetMusic'      => false,
                'components'         => [
                    ['type' => 'verse',  'number' => 1,    'lines' => ['Made-up river, made-up shore,', 'invented tide forevermore,']],
                    ['type' => 'chorus', 'number' => null, 'lines' => ['A chorus line for the test']],
                ],
            ],
        ],
    ];
}

/** Encode a doc (or an already-encoded string) for the parser. */
function enc(array $doc): string
{
    return (string)json_encode($doc, JSON_UNESCAPED_UNICODE);
}

/** Run the parser over a doc array and return the triple. */
function parseDoc(array $doc): array
{
    return _bulkImport_parseIHymnsJson(enc($doc), 'test.json');
}

/* ==================================================================== */
echo "1. A minimal valid interchange document parses to the expected song dicts\n";
/* ==================================================================== */

[$books, $songs, $err] = parseDoc(baselineDoc());

assertEq($err, null,                                    'valid document → no error');
assertTrue(is_array($books),                            'songbook list returned');
assertTrue(is_array($songs),                            'song list returned');
assertEq(count($books), 1,                              'one songbook parsed');
assertEq($books[0]['abbrev'], 'TF',                     'songbook abbrev from "id"');
assertEq($books[0]['name'], 'Testing Fixture Hymnal',   'songbook name');
assertEq($books[0]['language'], null,                   'no songbook language → null (upsert decides)');

assertEq(count($songs), 1,                              'one song parsed');
$s = $songs[0];
assertEq($s['id'],           'TF-0001',                 'song id preserved verbatim');
assertEq($s['number'],       1,                         'song number');
assertEq($s['title'],        'Rivers of Wonder',        'song title');
assertEq($s['songbook'],     'TF',                      'song songbook abbr');
assertEq($s['songbookName'], 'Testing Fixture Hymnal',  'song songbookName');
assertEq($s['language'],     'en',                      'song language');
assertEq($s['writers'],      ['A. Fictional Author'],   'writers passed through');
assertEq($s['composers'],    ['A. Fictional Composer'], 'composers passed through');
assertEq($s['verified'],            1,                  'verified true → 1 (int, DB-shaped)');
assertEq($s['lyricsPublicDomain'],  1,                  'lyricsPublicDomain true → 1');
assertEq($s['musicPublicDomain'],   1,                  'musicPublicDomain true → 1');
assertEq($s['hasAudio'],            0,                  'hasAudio false → 0');
assertEq($s['hasSheetMusic'],       0,                  'hasSheetMusic false → 0');

/* ==================================================================== */
echo "\n2. Components map to the shape _bulkImport_saveSong() consumes\n";
/* ==================================================================== */

/* ELI5: check each part of the song came out with the right label, number and lines.
   Detail — saveSong() reads $song['components'][n]['type'|'number'|'lines'] to build
   lyrics_text and then hands the SAME array to lyricLinesWriteComponents(), which
   reads 'type', 'number', 'lines', 'language', 'chords', 'notes', 'languages'. These
   assertions pin the three required keys plus the per-line language rename. */
assertEq(count($s['components']), 2,                    'two components');
assertEq($s['components'][0]['type'],   'verse',        'component 0 type');
assertEq($s['components'][0]['number'], 1,              'component 0 number');
assertEq($s['components'][0]['lines'],
    ['Made-up river, made-up shore,', 'invented tide forevermore,'],
                                                        'component 0 lines');
assertEq($s['components'][1]['type'],   'chorus',       'component 1 type');
assertEq($s['components'][1]['number'], 0,              'null component number → 0 (writer folds to NULL)');
assertEq(array_keys($s['components'][0]), ['type', 'number', 'lines'],
                                                        'component keys are exactly what the writer reads');

/* Every key saveSong() dereferences without a ?? default must be present. */
foreach (['id', 'title', 'number', 'songbook', 'songbookName', 'language', 'components'] as $k) {
    assertTrue(array_key_exists($k, $s),                "saveSong-required key \"$k\" present");
}

/* Per-line language: the interchange calls it lineLanguages, the writer wants languages. */
$doc = baselineDoc();
$doc['songs'][0]['components'][0]['lineLanguages'] = ['en', 'fr'];
[$b2, $sg2, $e2] = parseDoc($doc);
assertEq($e2, null,                                     'lineLanguages document parses');
assertEq($sg2[0]['components'][0]['languages'] ?? null, ['en', 'fr'],
                                                        'lineLanguages → "languages" (the writer\'s key)');
assertTrue(!array_key_exists('lineLanguages', $sg2[0]['components'][0]),
                                                        'the interchange spelling is not left behind');

/* lineIds belong to the EXPORTING install's tblLyricLines PKs — they must be dropped. */
$doc = baselineDoc();
$doc['songs'][0]['components'][0]['lineIds'] = [98765, 98766];
[$b3, $sg3, $e3] = parseDoc($doc);
assertEq($e3, null,                                     'lineIds document parses');
assertTrue(!array_key_exists('lineIds', $sg3[0]['components'][0]),
                                                        'lineIds dropped (foreign PKs must not be honoured)');

/* arrangement passes through for _sanitiseArrangement() to validate downstream. */
$doc = baselineDoc();
$doc['songs'][0]['arrangement'] = [0, 1, 0];
[$b4, $sg4, $e4] = parseDoc($doc);
assertEq($e4, null,                                     'arrangement document parses');
assertEq($sg4[0]['arrangement'] ?? null, [0, 1, 0],     'arrangement passed through verbatim');

/* #1912 — alternativeTitles maps to the "altTitles" shape the shared
   saveSong write loop (:812-826, #1669 C9) consumes. ELI5: a song that
   lists other titles it's known by should come out ready for the
   alt-titles writer; a song that lists none should come out with an
   empty array, not a missing key (saveSong's own `?? []` would tolerate
   either, but the write loop's doc-block promises a present-and-empty
   array, so that's the contract pinned here).
   Detail — also proves the parser's own tolerance: a whitespace-only
   title, an entry with no "title" key, and a non-array entry are each
   silently dropped rather than failing the whole song, mirroring the
   write loop's own per-entry tolerance instead of imposing a stricter
   one (matching the plan's "skip malformed entries" instruction). */
$doc = baselineDoc();
$doc['songs'][0]['alternativeTitles'] = [
    ['title' => 'A Beautiful Morning', 'language' => 'en-GB', 'note' => 'first line'],
    ['title' => '  Padded Title  '],                    // language/note absent → trimmed, nulled
    ['title' => '   '],                                 // whitespace-only title → dropped
    ['not-title-key' => 'x'],                            // array without a "title" key → dropped
    'not even an array entry',                           // scalar entry → dropped
];
[$b5, $sg5, $e5] = parseDoc($doc);
assertEq($e5, null,                                     'alternativeTitles document parses');
assertEq($sg5[0]['altTitles'] ?? null, [
    ['title' => 'A Beautiful Morning', 'language' => 'en-GB', 'note' => 'first line'],
    ['title' => 'Padded Title', 'language' => null, 'note' => null],
],                                                       'alternativeTitles → altTitles, malformed/blank entries skipped');
assertTrue(!array_key_exists('alternativeTitles', $sg5[0]),
                                                        'the interchange spelling is not left behind');

$doc = baselineDoc();          // no "alternativeTitles" key at all — an export predating #1912
[$b6, $sg6, $e6] = parseDoc($doc);
assertEq($e6, null,                                     'document without alternativeTitles parses (old export)');
assertEq($sg6[0]['altTitles'] ?? null, [],              'missing alternativeTitles key → altTitles === [] (never absent)');

/* ==================================================================== */
echo "\n3. Missing required fields produce a clear error, not a partial parse\n";
/* ==================================================================== */

/* ELI5: knock out one required field at a time and check we get a real sentence
   back and NO songs at all.
   Detail — #1633 asks for up-front validation: "a structural error should say
   what is wrong, not produce a partial import". Because the parser is pure and
   runs entirely before the caller opens a transaction, returning null songs here
   is what makes the failure atomic at the DB level. */

foreach (['meta', 'songbooks', 'songs'] as $key) {
    $doc = baselineDoc();
    unset($doc[$key]);
    [$bb, $ss, $ee] = parseDoc($doc);
    assertEq($ss, null,                                 "missing top-level \"$key\" → no songs (not partial)");
    assertErrorMentions($ee, $key,                      "missing top-level \"$key\" → error names the key");
}

/* All three missing at once → one message listing all three. */
[$bb, $ss, $ee] = _bulkImport_parseIHymnsJson('{}', 'test.json');
assertEq($ss, null,                                     'empty object → no songs');
assertErrorMentions($ee, 'meta',                        'empty object → error names meta');
assertErrorMentions($ee, 'songbooks',                   'empty object → error names songbooks');
assertErrorMentions($ee, 'songs',                       'empty object → error names songs');

foreach (['generatedAt', 'generatorVersion', 'totalSongs', 'totalSongbooks'] as $key) {
    $doc = baselineDoc();
    unset($doc['meta'][$key]);
    [$bb, $ss, $ee] = parseDoc($doc);
    assertEq($ss, null,                                 "missing meta.$key → no songs");
    assertErrorMentions($ee, $key,                      "missing meta.$key → error names the key");
}

foreach (['id', 'name', 'songCount'] as $key) {
    $doc = baselineDoc();
    unset($doc['songbooks'][0][$key]);
    [$bb, $ss, $ee] = parseDoc($doc);
    assertEq($ss, null,                                 "missing songbooks[0].$key → no songs");
    assertErrorMentions($ee, $key,                      "missing songbooks[0].$key → error names the key");
}

$songRequired = [
    'id', 'number', 'title', 'songbook', 'songbookName', 'language',
    'writers', 'composers', 'copyright', 'ccli', 'verified',
    'lyricsPublicDomain', 'musicPublicDomain', 'hasAudio',
    'hasSheetMusic', 'components',
];
foreach ($songRequired as $key) {
    $doc = baselineDoc();
    unset($doc['songs'][0][$key]);
    [$bb, $ss, $ee] = parseDoc($doc);
    assertEq($ss, null,                                 "missing songs[0].$key → no songs");
    assertErrorMentions($ee, $key,                      "missing songs[0].$key → error names the key");
}

foreach (['type', 'number', 'lines'] as $key) {
    $doc = baselineDoc();
    unset($doc['songs'][0]['components'][0][$key]);
    [$bb, $ss, $ee] = parseDoc($doc);
    assertEq($ss, null,                                 "missing component.$key → no songs");
    assertErrorMentions($ee, $key,                      "missing component.$key → error names the key");
}

/* Structural / value errors, each naming the offending song so a 5,000-song
   file stays debuggable. */
$doc = baselineDoc();
$doc['songs'][0]['id'] = 'not a song id';
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'malformed SongId → no songs');
assertErrorMentions($ee, 'not a song id',               'malformed SongId → error quotes the bad id');

$doc = baselineDoc();
$doc['songs'][0]['songbook'] = 'ZZ';         // id prefix TF no longer matches
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'id prefix / songbook mismatch → no songs');
assertErrorMentions($ee, 'must match',                  'prefix mismatch → explains the rule');

$doc = baselineDoc();
$doc['songs'][0]['id']       = 'ZZ-0001';
$doc['songs'][0]['songbook'] = 'ZZ';         // consistent, but ZZ is not declared
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'undeclared songbook → no songs');
assertErrorMentions($ee, 'not declared',                'undeclared songbook → says so');

$doc = baselineDoc();
$doc['songs'][] = $doc['songs'][0];          // exact duplicate id
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'duplicate SongId in one file → no songs');
assertErrorMentions($ee, 'repeats song id',             'duplicate SongId → error says which');

$doc = baselineDoc();
$doc['songs'][0]['title'] = '   ';
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'blank title → no songs');
assertErrorMentions($ee, 'empty title',                 'blank title → explicit reason');

$doc = baselineDoc();
$doc['songs'][0]['number'] = 0;
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'number 0 → no songs');
assertErrorMentions($ee, 'positive integer',            'number 0 → explicit reason');

$doc = baselineDoc();
$doc['songs'][0]['components'] = [];
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'no components → no songs');
assertErrorMentions($ee, 'no components',               'no components → explicit reason');

$doc = baselineDoc();
$doc['songs'][0]['components'][0]['lines'] = [];
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'component with no lines → no songs');
assertErrorMentions($ee, 'no lines',                    'no lines → explicit reason');

$doc = baselineDoc();
$doc['songs'][0]['components'][0]['type'] = 'chourus';   // typo
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'unknown component type → no songs');
assertErrorMentions($ee, 'chourus',                     'unknown type → error quotes the typo');
assertErrorMentions($ee, 'expected one of',             'unknown type → lists the valid set');

$doc = baselineDoc();
$doc['songbooks'][0]['id'] = 'WAY-TOO-LONG-ABBR';
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'invalid songbook id charset/length → no songs');
assertErrorMentions($ee, 'SongId prefix',               'invalid songbook id → explains why it matters');

$doc = baselineDoc();
$doc['songs'] = [];
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ss, null,                                     'zero songs → treated as an error, not a silent no-op');
assertErrorMentions($ee, 'no songs',                    'zero songs → explicit reason');

/* Malformed JSON is a real message, not a bare false. */
[$bb, $ss, $ee] = _bulkImport_parseIHymnsJson('{"meta": ', 'broken.json');
assertEq($ss, null,                                     'truncated JSON → no songs');
assertErrorMentions($ee, 'invalid JSON',                'truncated JSON → "invalid JSON" + json_last_error_msg');

/* ==================================================================== */
echo "\n4. Unknown extra fields are tolerated (forward compatibility)\n";
/* ==================================================================== */

/* ELI5: a newer exporter may add fields we have never heard of; we ignore them
   instead of refusing the file.
   Detail — the schema says additionalProperties:false, but being STRICTER on
   INPUT than on output would make every future field addition a breaking change
   for older installs. We are deliberately lenient in the direction that cannot
   lose data. */
$doc = baselineDoc();
$doc['futureTopLevelKey']                     = ['anything' => true];
$doc['meta']['futureMetaKey']                 = 'x';
$doc['songbooks'][0]['futureBookKey']         = 42;
$doc['songs'][0]['futureSongKey']             = ['nested' => ['deep' => 1]];
$doc['songs'][0]['components'][0]['futureCompKey'] = 'y';
[$bb, $ss, $ee] = parseDoc($doc);
assertEq($ee, null,                                     'unknown fields at every level → still parses');
assertEq(count($ss), 1,                                 'unknown fields → song still produced');
assertEq($ss[0]['title'], 'Rivers of Wonder',           'unknown fields → known fields unaffected');
assertTrue(!array_key_exists('futureSongKey', $ss[0]),  'unknown song field not smuggled into the dict');
assertTrue(!array_key_exists('futureCompKey', $ss[0]['components'][0]),
                                                        'unknown component field not smuggled through');

/* The real repo fixture must parse — it is the format's worked example. */
$fixture = dirname(__DIR__, 2) . '/tests/fixtures/synthetic-songs.json';
assertTrue(is_file($fixture),                           'tests/fixtures/synthetic-songs.json exists');
[$fb, $fs, $fe] = _bulkImport_parseIHymnsJson((string)file_get_contents($fixture), 'synthetic-songs.json');
assertEq($fe, null,                                     'the repo interchange fixture parses cleanly');
assertEq(count($fb), 2,                                 'fixture: two songbooks');
assertEq(count($fs), 5,                                 'fixture: five songs');
assertEq($fs[0]['id'], 'TF-0001',                       'fixture: first song id');

/* A BOM must not break the parse (Windows editors add one). */
[$bb, $ss, $ee] = _bulkImport_parseIHymnsJson("\xEF\xBB\xBF" . enc(baselineDoc()), 'bom.json');
assertEq($ee, null,                                     'UTF-8 BOM tolerated');
assertEq(count($ss), 1,                                 'BOM document still yields the song');

/* ==================================================================== */
echo "\n5. The sniff: iHymns is detected, VideoPsalm is NOT (the regression guard)\n";
/* ==================================================================== */

/* ELI5: both formats end in .json, so this check decides which one a file is.
   Detail — this is the assertion that matters most. VideoPsalm owned `.json`
   first; a sniff that steals its files silently breaks a working import path.
   The guard is asymmetric: we only claim a file when ALL THREE iHymns keys are
   present AND VideoPsalm's capital-S "Songs" key is absent. */

assertTrue(_bulkImport_looksLikeIHymnsJson(enc(baselineDoc())),
                                                        'iHymns interchange document → detected');
assertTrue(_bulkImport_looksLikeIHymnsJson((string)file_get_contents($fixture)),
                                                        'the repo fixture → detected');

/* A representative VideoPsalm songbook: {"Text": …, "Songs": [ {Text, Verses} ]}. */
$videoPsalm = enc([
    'Text'  => 'Sample VideoPsalm Hymnal',
    'Songs' => [
        ['Text' => 'Be Thou My Vision', 'Number' => 1, 'Copyright' => 'Public Domain',
         'Verses' => [['Tag' => 'V1', 'Text' => "Be Thou my vision\nO Lord of my heart"]]],
    ],
]);
assertEq(_bulkImport_looksLikeIHymnsJson($videoPsalm), false,
                                                        'VideoPsalm songbook → NOT claimed (regression guard)');

/* VideoPsalm sanity: the real parser still accepts what we declined. */
[$vpBook, $vpSongs, $vpErr] = _bulkImport_parseVideoPsalmSongbook($videoPsalm, 'sample [VPS].json');
assertEq($vpErr, null,                                  'the declined file still parses as VideoPsalm');
assertEq(count($vpSongs), 1,                            'VideoPsalm parse unaffected by #1633');

/* Adversarial: a VideoPsalm file whose LYRICS contain the words we sniff for.
   The regex requires "key": so prose can never trigger it, and the "Songs" key
   short-circuits regardless. */
$vpTricky = enc([
    'Text'  => 'Tricky Book',
    'Songs' => [
        ['Text' => 'A Song About meta songbooks songs', 'Number' => 1,
         'Verses' => [['Tag' => 'V1', 'Text' => 'the word meta and songbooks and songs appear here']]],
    ],
]);
assertEq(_bulkImport_looksLikeIHymnsJson($vpTricky), false,
                                                        'VideoPsalm with our keywords in the LYRICS → still not claimed');

/* Partial shapes must NOT be claimed — conservative means falling through. */
assertEq(_bulkImport_looksLikeIHymnsJson('{"songs": []}'), false,
                                                        'only "songs" → not claimed');
assertEq(_bulkImport_looksLikeIHymnsJson('{"meta": {}, "songs": []}'), false,
                                                        '"meta" + "songs" but no "songbooks" → not claimed');
assertEq(_bulkImport_looksLikeIHymnsJson('{"meta": {}, "songbooks": []}'), false,
                                                        '"meta" + "songbooks" but no "songs" → not claimed');
assertEq(_bulkImport_looksLikeIHymnsJson('not json at all'), false,
                                                        'non-JSON text → not claimed');
assertEq(_bulkImport_looksLikeIHymnsJson(''), false,     'empty body → not claimed');

/* Key ORDER must not matter — JSON objects are unordered, and a document that
   puts the huge songs array first would push meta past any head-slice window. */
$reordered = '{"songs":[],"songbooks":[],"meta":{}}';
assertTrue(_bulkImport_looksLikeIHymnsJson($reordered),
                                                        'keys in any order → still detected (whole body scanned)');

/* ==================================================================== */
echo "\n6. The memory ceiling is enforced BEFORE json_decode()\n";
/* ==================================================================== */

/* ELI5: refuse a file bigger than we have measured to be safe, and say why.
   Detail — json_decode() is not streaming; the cap is the only real bound. The
   check must precede the decode or it bounds nothing. A body one byte over the
   limit is built from a repeated character so the test costs no real memory. */
assertEq(_BULK_IMPORT_IHYMNS_MAX_BYTES, 8 * 1024 * 1024, 'the documented 8 MiB cap is the constant');
$oversized = str_repeat('x', _BULK_IMPORT_IHYMNS_MAX_BYTES + 1);
[$bb, $ss, $ee] = _bulkImport_parseIHymnsJson($oversized, 'huge.json');
assertEq($ss, null,                                     'oversized body → refused');
assertErrorMentions($ee, 'too large',                   'oversized body → says it is too large');
assertErrorMentions($ee, 'ZIP',                         'oversized body → points at the streaming alternative');
unset($oversized);

/* A body exactly AT the limit is not refused by the size gate (it fails later,
   on JSON validity) — proving the boundary is inclusive, not off-by-one. */
[$bb, $ss, $ee] = _bulkImport_parseIHymnsJson(str_repeat('x', _BULK_IMPORT_IHYMNS_MAX_BYTES), 'exact.json');
assertErrorMentions($ee, 'invalid JSON',                'exactly-at-limit body passes the size gate (fails as bad JSON)');

/* ==================================================================== */
echo "\n7. api2.php wires the format in (structural assertions)\n";
/* ==================================================================== */

/* ELI5: check the endpoint actually lists the new format, so the parser cannot
   be reachable-in-theory only.
   Detail — a source-text assertion is the pragmatic option: api2.php cannot be
   required here (it is a request-scoped endpoint that authenticates and exits).
   These three checks are exactly the wiring #1633 requires — the format in
   $bodyFormats, a match arm calling the processor, and the .json content sniff
   in auto-detection. */
$api2 = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/api2.php');
assertTrue(str_contains($api2, "'videopsalm', 'ihymns'"),
                                                        'api2: "ihymns" is in $bodyFormats');
assertTrue((bool)preg_match("/'ihymns'\s*=>\s*_bulkImport_processIHymnsJson\(/", $api2),
                                                        'api2: match arm dispatches to _bulkImport_processIHymnsJson()');
assertTrue(str_contains($api2, '_bulkImport_looksLikeIHymnsJson('),
                                                        'api2: auto-detect content-sniffs .json');
assertTrue((bool)preg_match("/if\s*\(\s*\\\$format\s*===\s*'videopsalm'\s*\)/", $api2),
                                                        'api2: the sniff only ever re-routes the videopsalm branch');
assertTrue(str_contains($api2, "'json'  => 'videopsalm'"),
                                                        'api2: .json still defaults to videopsalm (sniff is the exception)');

$import2 = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/import2.php');
assertTrue(str_contains($import2, "'ihymns'"),          'import2 UI: the format is explicitly pickable');

/* The processor must honour the shared summary contract the progress UI reads. */
$src = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php');
$proc = strstr($src, 'function _bulkImport_processIHymnsJson');
foreach (['songbooks_created', 'songbooks_existing', 'songs_created',
          'songs_skipped_existing', 'songs_failed', 'parsed_by_format', 'errors'] as $key) {
    assertTrue(is_string($proc) && str_contains($proc, "'" . $key . "'"),
                                                        "process wrapper emits summary key \"$key\"");
}
/* Lyrics must reach the DB only via the shared writer (rule #25) — the processor
   delegates to _bulkImport_saveSong and never touches components tables itself. */
assertTrue(is_string($proc) && str_contains($proc, '_bulkImport_saveSong('),
                                                        'process wrapper saves via the shared saver');
assertEq(is_string($proc) ? (int)preg_match('/tblSongComponents|tblLyricLines|LinesJson/', $proc) : 1, 0,
                                                        'process wrapper never writes lyric tables directly (rule #25)');

echo "\n" . ($failed === 0 ? "ALL PASS" : "FAILURES") . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
