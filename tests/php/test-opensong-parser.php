<?php
/**
 * iHymns — OpenSong Importer Smoke Test (#882)
 *
 * Exercises _bulkImport_parseOpenSong() / _bulkImport_parseOpenSongLyrics()
 * against representative fixtures. Doesn't touch the database — only the
 * pure-parser surface.
 *
 *   php tests/php/test-opensong-parser.php
 *
 * Exit status 0 = all tests pass, 1 = at least one assertion failed.
 */

declare(strict_types=1);

/* ELI5: we just load the file the importer actually lives in, then call it.
 *
 * Detail — this test USED to read manage/editor/api.php as TEXT, search it for
 * the literal comment banner "OPENSONG PARSER (#882)", copy everything from
 * there to EOF into a temp file and require THAT. #1200 Phase 4b moved the
 * bulk-import parsers out of api.php into this shared include so api.php and
 * api2.php stop forking one copy each (CLAUDE.md modularity rule), the banner
 * went with them, `strpos()` returned false, and the test exited 1 before
 * running a single assertion — a permanently-red test that asserted nothing
 * about the parser. #1575.
 *
 * Requiring the real module instead of scraping source text is also what makes
 * this test survive the NEXT move: a source-text scrape re-breaks whenever
 * anything is reordered, whereas the public entry point
 * `_bulkImport_parseOpenSong()` is the contract callers actually depend on.
 * This mirrors the loader the newer sibling tests already use
 * (tests/php/test-chordpro-parser.php, tests/php/test-openlyrics-parser.php).
 *
 * Safe to require directly: song_importers.php is a pure-function include. Its
 * only top-level side effect is a direct-access guard keyed on
 * $_SERVER['SCRIPT_FILENAME'] — under the CLI that is THIS test file, not the
 * include, so the guard passes — and no parser touches the DB at include time.
 * See https://www.php.net/manual/en/reserved.variables.server.php */
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

/**
 * _bulkImport_parseOpenSong()'s 5th parameter is a lazy auto-number
 * PROVIDER (#1740), not a plain int — invoked only when the document
 * carries neither <hymn_number> nor a filename-hint number. Returns a
 * closure that always yields $n, for fixtures where a number IS expected
 * to be requested.
 */
function autoNumberOf(int $n): callable
{
    return static fn (): int => $n;
}

/**
 * A provider that fails the test loudly if invoked at all — used to prove
 * (not just assert) that a document which already has its own number
 * (or fails to parse before number-resolution) never asks for an
 * auto-number, mirroring the real caller's "never opens a DB connection
 * it doesn't need" contract (#1740).
 */
function autoNumberMustNotBeCalled(): callable
{
    return static function (): int {
        throw new \RuntimeException('auto-number provider invoked when it should not have been');
    };
}

$fixtureDir = __DIR__ . '/fixtures/opensong';

/* -------------------------------------------------------------------- */
/* Fixture 1: typical hymn — verses, chorus, chord rows, comments.       */
/* -------------------------------------------------------------------- */
echo "fixture: be-thou-my-vision.xml\n";
$body  = file_get_contents("$fixtureDir/be-thou-my-vision.xml");
/* Has its own <hymn_number> — the auto-number provider must never fire (#1740). */
[$song, $err] = _bulkImport_parseOpenSong($body, 'TST', 'Test Hymnal', 0, autoNumberMustNotBeCalled());
assertEq($err,                    null,                  'no parse error');
assertTrue(is_array($song),                              'song dict returned');
assertEq($song['title'],          'Be Thou My Vision',   'title');
assertEq($song['number'],         123,                   'number from <hymn_number>');
assertEq($song['id'],             'TST-0123',            'songId format');
assertEq($song['songbook'],       'TST',                 'songbook abbr');
assertEq($song['copyright'],      'Public Domain',       'copyright');
assertEq($song['ccli'],           '30639',               'ccli');
assertEq($song['writers'],        ['Mary E. Byrne', 'Eleanor H. Hull'], 'writers split on /');
assertEq(count($song['components']), 3,                  'three components (V1, V2, C)');
assertEq($song['components'][0]['type'],   'verse',      'first component type');
assertEq($song['components'][0]['number'], 1,            'first component number');
assertEq($song['components'][2]['type'],   'chorus',     'third component type');
/* Lyric content — chord rows dropped, comment row dropped, leading
   space stripped from lyric rows. */
assertEq($song['components'][0]['lines'][0], 'Be Thou my vision, O Lord of my heart', 'V1 line 1');
assertEq($song['components'][0]['lines'][1], 'Naught be all else to me, save that Thou art', 'V1 line 2');
assertEq(count($song['components'][0]['lines']), 2,      'V1 has exactly 2 lyric lines (chord row stripped)');

/* -------------------------------------------------------------------- */
/* Fixture 2: no <hymn_number>, no filename hint → auto-increment seed.  */
/* -------------------------------------------------------------------- */
echo "fixture: no-number.xml\n";
$body  = file_get_contents("$fixtureDir/no-number.xml");
/* No <hymn_number>, no hint — the provider MUST fire and its value is used (#1740). */
[$song, $err] = _bulkImport_parseOpenSong($body, 'TST', 'Test Hymnal', 0, autoNumberOf(42));
assertEq($err,             null,                'no parse error');
assertEq($song['number'],  42,                  'number falls back to auto-increment');
assertEq($song['id'],      'TST-0042',          'songId uses auto-increment');
assertEq($song['writers'], ['Anon'],            'single writer, no split');

/* -------------------------------------------------------------------- */
/* Fixture 3: malformed XML — graceful parse error.                      */
/* -------------------------------------------------------------------- */
echo "fixture: malformed.xml\n";
$body  = file_get_contents("$fixtureDir/malformed.xml");
/* Rejected before number-resolution is ever reached — the provider must
   never fire. This is the behavioural proof for #1740: an unparseable
   OpenSong document never asks for an auto-number (and therefore, in the
   real caller, never opens a DB connection). */
[$song, $err] = _bulkImport_parseOpenSong($body, 'TST', 'Test Hymnal', 0, autoNumberMustNotBeCalled());
assertEq($song,            null,                'malformed XML returns null song');
assertTrue(is_string($err) && str_starts_with($err, 'invalid XML'), 'error message starts with "invalid XML"');

/* -------------------------------------------------------------------- */
/* Fixture 4: filename hint when no <hymn_number>.                       */
/* -------------------------------------------------------------------- */
echo "filename-hint precedence:\n";
$body  = file_get_contents("$fixtureDir/no-number.xml");
/* Filename hint present — the provider must never fire (#1740). */
[$song, $err] = _bulkImport_parseOpenSong($body, 'TST', 'Test Hymnal', 17, autoNumberMustNotBeCalled());
assertEq($song['number'],  17,                  'filename hint beats auto-increment when XML has no number');

/* -------------------------------------------------------------------- */
/* Fixture 5: <hymn_number> always wins over hint + auto-increment.      */
/* -------------------------------------------------------------------- */
echo "<hymn_number> precedence:\n";
$body  = file_get_contents("$fixtureDir/be-thou-my-vision.xml");
/* <hymn_number> present — the provider must never fire (#1740). */
[$song, $err] = _bulkImport_parseOpenSong($body, 'TST', 'Test Hymnal', 17, autoNumberMustNotBeCalled());
assertEq($song['number'],  123,                 '<hymn_number> wins over hint and auto-increment');

/* -------------------------------------------------------------------- */
/* Component-type map.                                                   */
/* -------------------------------------------------------------------- */
echo "component-type mapping:\n";
assertEq(_bulkImport_openSongComponentTypeFor('V'), 'verse',      'V → verse');
assertEq(_bulkImport_openSongComponentTypeFor('C'), 'chorus',     'C → chorus');
assertEq(_bulkImport_openSongComponentTypeFor('B'), 'bridge',     'B → bridge');
assertEq(_bulkImport_openSongComponentTypeFor('P'), 'pre-chorus', 'P → pre-chorus');
assertEq(_bulkImport_openSongComponentTypeFor('T'), 'outro',      'T → outro (Tag)');
assertEq(_bulkImport_openSongComponentTypeFor('I'), 'intro',      'I → intro');
assertEq(_bulkImport_openSongComponentTypeFor('Z'), 'refrain',    'unknown letter → refrain fallback');

echo "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
