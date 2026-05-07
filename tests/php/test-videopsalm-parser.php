<?php
/**
 * iHymns — VideoPsalm Importer Smoke Test (#883)
 *
 * Exercises _bulkImport_parseVideoPsalmSongbook() and the helper
 * functions it depends on against representative fixtures. Doesn't
 * touch the database — only the pure-parser surface.
 *
 *   php tests/php/test-videopsalm-parser.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

/* The parser helpers live at the bottom of the editor's API file but
   require none of its DB / HTTP machinery to function. We isolate the
   helper definitions by extracting them into a temp file the test
   includes — the alternative (require api.php) would emit headers and
   short-circuit the response handler at the top of the file. */
$apiPath = dirname(__DIR__, 2) . '/appWeb/public_html/manage/editor/api.php';
$src     = file_get_contents($apiPath);
if ($src === false) {
    fwrite(STDERR, "could not read api.php\n");
    exit(1);
}

/* Pull out only the OpenSong + VideoPsalm helpers + their
   dependencies. The block we care about is delimited by the
   OPENSONG PARSER comment header and runs to EOF (the VideoPsalm
   block lives immediately after OpenSong). */
$marker = '/* ===========================================================================' . "\n"
        . ' * OPENSONG PARSER (#882)';
$pos = strpos($src, $marker);
if ($pos === false) {
    fwrite(STDERR, "OPENSONG PARSER block not found in api.php\n");
    exit(1);
}
$tmp = tempnam(sys_get_temp_dir(), 'ihymns-videopsalm-');
file_put_contents($tmp, "<?php\n" . substr($src, $pos));
require $tmp;
@unlink($tmp);

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

$fixtureDir = __DIR__ . '/fixtures/videopsalm';

/* -------------------------------------------------------------------- */
/* Fixture 1: typical multi-song songbook with edge-case rows.           */
/* -------------------------------------------------------------------- */
echo "fixture: sample-hymnal [VPS].json\n";
$body = file_get_contents("$fixtureDir/sample-hymnal [VPS].json");
[$songbook, $songs, $err] = _bulkImport_parseVideoPsalmSongbook($body, 'sample-hymnal [VPS].json');

assertEq($err,                 null,                          'no fatal parse error');
assertTrue(is_array($songbook),                               'songbook descriptor returned');
assertEq($songbook['name'],    'Sample VideoPsalm Hymnal',    'songbook name from top-level "Text"');
assertEq($songbook['abbrev'],  'VPS',                         'abbreviation from filename "[VPS]" hint');

/* Three songs survive parsing: hymns 1, 2, 3.
   Hymn 4 is skipped (empty Verses[]) and hymn 5 is skipped (no title). */
assertEq(count($songs), 3,                                    'three songs survive parsing');
assertEq(count($songbook['parseErrors']), 2,                  'two per-song parse errors recorded');

/* Per-song parse errors carry the right reasons. */
$reasons = array_map(static fn($e) => $e['error'], $songbook['parseErrors']);
assertTrue(in_array('no usable Verses[] entries', $reasons, true),
                                                              'empty Verses → "no usable Verses[] entries"');
assertTrue(in_array('song has no Text/title', $reasons, true),
                                                              'no title → "song has no Text/title"');

/* Hymn 1 — full happy path. */
$h1 = $songs[0];
assertEq($h1['title'],        'Be Thou My Vision',            'hymn 1 title');
assertEq($h1['number'],       1,                              'hymn 1 number');
assertEq($h1['id'],           'VPS-0001',                     'hymn 1 SongId format');
assertEq($h1['songbook'],     'VPS',                          'hymn 1 songbook abbr');
assertEq($h1['songbookName'], 'Sample VideoPsalm Hymnal',     'hymn 1 songbook name');
assertEq($h1['copyright'],    'Public Domain',                'hymn 1 copyright');
assertEq($h1['ccli'],         '30639',                        'hymn 1 CCLI');
assertEq($h1['writers'],      ['Mary E. Byrne', 'Eleanor H. Hull'],
                                                              'hymn 1 writers split on /');
assertEq(count($h1['components']), 3,                         'hymn 1: V1, C, V2 → 3 components');
assertEq($h1['components'][0]['type'],   'verse',             'component 0 type → verse (V1)');
assertEq($h1['components'][0]['number'], 1,                   'component 0 number');
assertEq($h1['components'][0]['lines'][0], 'Be Thou my vision, O Lord of my heart',
                                                              'component 0 line 0 (\\n split)');
assertEq($h1['components'][0]['lines'][1], 'Naught be all else to me, save that Thou art',
                                                              'component 0 line 1');
assertEq($h1['components'][1]['type'],   'chorus',            'component 1 type → chorus (C)');
assertEq($h1['components'][2]['type'],   'verse',             'component 2 type → verse (V2)');
assertEq($h1['components'][2]['number'], 2,                   'component 2 number');

/* Hymn 2 — anonymous, missing Author entirely + empty CCLI. */
$h2 = $songs[1];
assertEq($h2['title'],     'Anonymous Hymn',                  'hymn 2 title');
assertEq($h2['writers'],   [],                                'hymn 2: missing Author → empty writers[]');
assertEq($h2['ccli'],      '',                                'hymn 2: empty CCLI string preserved');
assertEq($h2['copyright'], 'Public Domain',                   'hymn 2 copyright');

/* Hymn 3 — no Copyright field, multi-credit Author with mixed delimiters. */
$h3 = $songs[2];
assertEq($h3['title'],     'Untitled No-Copyright Hymn',      'hymn 3 title');
assertEq($h3['copyright'], '',                                'hymn 3: missing Copyright → empty string');
assertEq($h3['writers'],   ['John Doe', 'Jane Roe', 'Sam Smith'],
                                                              'hymn 3 writers split on & and ,');
assertEq(count($h3['components']), 2,                         'hymn 3: V1 + B → 2 components');
assertEq($h3['components'][1]['type'], 'bridge',              'hymn 3 component 1 type → bridge (B)');

/* -------------------------------------------------------------------- */
/* Fixture 2: minimal songbook — no Text metadata, no Number, abbrev     */
/* must be derived from the title (no filename hint passed).             */
/* -------------------------------------------------------------------- */
echo "fixture: no-metadata.json (no abbrev hint)\n";
$body = file_get_contents("$fixtureDir/no-metadata.json");
[$songbook, $songs, $err] = _bulkImport_parseVideoPsalmSongbook($body, null);
assertEq($err,                 null,                          'no fatal parse error');
assertEq($songbook['name'],    'VideoPsalm Songbook',         'fallback songbook name');
assertEq($songbook['abbrev'],  'VS',                          'abbrev from initials of fallback name');
assertEq(count($songs),        1,                             'one song parsed');
assertEq($songs[0]['number'],  1,                             'missing Number → 1-based index fallback');
assertEq($songs[0]['id'],      'VS-0001',                     'SongId uses derived abbrev + index');

/* -------------------------------------------------------------------- */
/* Fixture 3: malformed JSON — graceful parse error.                     */
/* -------------------------------------------------------------------- */
echo "fixture: malformed.json\n";
$body = file_get_contents("$fixtureDir/malformed.json");
[$songbook, $songs, $err] = _bulkImport_parseVideoPsalmSongbook($body, 'malformed.json');
assertEq($songbook,        null,                              'malformed JSON → null songbook');
assertEq($songs,           null,                              'malformed JSON → null songs');
assertTrue(is_string($err) && str_starts_with($err, 'invalid JSON'),
                                                              'error message starts with "invalid JSON"');

/* -------------------------------------------------------------------- */
/* Wrong shape: valid JSON but no "Songs" key (e.g. an iHymns corpus     */
/* that should NOT have been routed here in the first place).            */
/* -------------------------------------------------------------------- */
echo "wrong-shape JSON (lowercase songs[]):\n";
[$songbook, $songs, $err] = _bulkImport_parseVideoPsalmSongbook(
    '{"songs":[],"songbooks":[]}',
    'corpus.json'
);
assertEq($songbook, null,                                     'iHymns corpus shape → null songbook');
assertEq($songs,    null,                                     'iHymns corpus shape → null songs');
assertEq($err,      'JSON has no top-level "Songs" array',    'error names the missing key');

/* -------------------------------------------------------------------- */
/* Component-type map — Tag letter → component type.                     */
/* -------------------------------------------------------------------- */
echo "component-type mapping:\n";
assertEq(_bulkImport_videopsalmComponentTypeFor('V1'), 'verse',      'V1 → verse');
assertEq(_bulkImport_videopsalmComponentTypeFor('V'),  'verse',      'V → verse');
assertEq(_bulkImport_videopsalmComponentTypeFor('C'),  'chorus',     'C → chorus');
assertEq(_bulkImport_videopsalmComponentTypeFor('B2'), 'bridge',     'B2 → bridge');
assertEq(_bulkImport_videopsalmComponentTypeFor('P'),  'pre-chorus', 'P → pre-chorus');
assertEq(_bulkImport_videopsalmComponentTypeFor('T'),  'outro',      'T → outro');
assertEq(_bulkImport_videopsalmComponentTypeFor('E'),  'outro',      'E → outro');
assertEq(_bulkImport_videopsalmComponentTypeFor('I'),  'intro',      'I → intro');
assertEq(_bulkImport_videopsalmComponentTypeFor('Z'),  'refrain',    'unknown letter → refrain fallback');

/* -------------------------------------------------------------------- */
/* Abbrev-from-hint resolution.                                          */
/* -------------------------------------------------------------------- */
echo "abbrev-from-hint resolution:\n";
assertEq(_bulkImport_videopsalmAbbrevFromHint('foo/Sample [SH].json', 'Whatever'),
                                                              'SH', 'bracketed token wins, even with folder prefix');
assertEq(_bulkImport_videopsalmAbbrevFromHint('CIS.json',  'Christ in Song'),
                                                              'CIS', 'bare alphanum filename → uppercased abbrev');
assertEq(_bulkImport_videopsalmAbbrevFromHint(null,        'Christ In Song Revised'),
                                                              'CISR', 'no hint → initials of title words');
assertEq(_bulkImport_videopsalmAbbrevFromHint('',          ''),
                                                              'VP', 'empty title + empty hint → "VP" fallback');

echo "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
