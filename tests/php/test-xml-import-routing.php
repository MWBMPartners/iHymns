<?php

declare(strict_types=1);

/**
 * iHymns — Single-file XML import auto-router test (#882)
 *
 * ELI5: this checks that when someone uploads ONE .xml song file and picks
 * "auto-detect", the app correctly guesses whether it's an OpenLyrics file
 * or an OpenSong file — and that if BOTH guesses turn out wrong, it says so
 * clearly instead of blaming the wrong format.
 *
 * Detail — before #882's fix, every single .xml upload was hard-routed to
 * the OpenLyrics parser alone (_bulkImport_processOpenLp()), so a real
 * OpenSong document always failed with OpenLyrics' own "no <title> element"
 * error — misleading, because OpenSong DOES have a <title>, just not where
 * OpenLyrics looks for one (properties/titles/title vs. the XML root).
 * _bulkImport_processXmlAuto() (song_importers.php) fixes this by picking a
 * PRIMARY parser via the ONE shared discriminator
 * _bulkImport_looksLikeOpenLyrics() — the same function the ZIP-import loop
 * already uses to sort .xml archive entries, so a single-file decision and
 * a ZIP-entry decision can never disagree (CLAUDE.md rule #35) — and, on a
 * primary parse failure, trying the OTHER parser once before giving up.
 *
 * ⚠️ THIS SUITE IS PARTLY DB-DEPENDENT. An earlier version of this doc-block
 * claimed it was "DELIBERATELY DB-FREE" because "`$db = getDbMysqli()` in
 * _bulkImport_processOpenLp()/_bulkImport_processOpenSong() comes AFTER the
 * parse-failure early return". **That is true of processOpenLp and FALSE of
 * processOpenSong** — a symmetry that was asserted rather than checked.
 *
 *   _bulkImport_processOpenLp():    parse -> early-return on failure -> getDbMysqli()
 *   _bulkImport_processOpenSong():  getDbMysqli() -> parse -> early-return on failure
 *
 * processOpenSong connects first because it must call
 * _bulkImport_nextSongNumberFor($db, …) to obtain `$autoNumber`, which is a
 * PARSE ARGUMENT (parseOpenSong's 5th parameter) — so the connection is not
 * gratuitous, it is a genuine data dependency of the parse itself. The
 * asymmetry is tracked separately; restructuring it is not this test's job.
 *
 * Consequence: the "both formats failed" assertions below DO open a
 * connection, and this suite fataled with `mysqli_sql_exception: Connection
 * refused` the first time it ran on a machine whose MySQL was down. It never
 * failed in CI because .github/workflows/test.yml supplies a `mariadb:11`
 * service — which is exactly why a false "no DB needed" claim could survive:
 * the only person it misleads is someone running the suite locally.
 *
 * The pure routing assertions (_bulkImport_xmlAutoPrimary()) ARE DB-free and
 * always run. The process-level assertions are skipped, loudly, when no
 * database is reachable. The SUCCESS path (a song actually landing in
 * tblSongs) is verified behaviourally over HTTP per the #882 plan, not here.
 *
 *   php tests/php/test-xml-import-routing.php
 *
 * Exit status 0 = all pass, 1 = at least one assertion failed.
 */

/* song_importers.php is a pure-function include: its only top-level side
 * effect is a direct-access guard keyed on SCRIPT_FILENAME (which, under
 * CLI, is THIS test file, not the include — so the guard passes), and the
 * parser functions touch no DB at include time. Safe to require directly —
 * same pattern as test-opensong-parser.php / test-openlyrics-parser.php /
 * test-chordpro-parser.php (see their doc-blocks for why this beats
 * scraping source text, #1575). */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_importers.php';

$passed = 0;
$failed = 0;
function aEq($actual, $expected, string $label): void
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
function aTrue($cond, string $label): void
{
    aEq((bool)$cond, true, $label);
}
function aStrContains(string $haystack, string $needle, string $label): void
{
    aTrue(str_contains($haystack, $needle), $label . " (looking for \"$needle\" in \"$haystack\")");
}

/* -------------------------------------------------------------------- */
/* 1. Every OpenSong fixture on disk routes to 'opensong'.               */
/*    Globbed (not a typed list) — rule #34: a new fixture dropped into  */
/*    fixtures/opensong/ is covered automatically, no test edit needed.  */
/* -------------------------------------------------------------------- */
$opensongFixtures = glob(__DIR__ . '/fixtures/opensong/*.xml') ?: [];
aTrue(count($opensongFixtures) >= 3, 'at least 3 OpenSong fixtures found on disk (vacuity check)');
foreach ($opensongFixtures as $path) {
    $body = (string)file_get_contents($path);
    aEq(_bulkImport_xmlAutoPrimary($body), 'opensong', 'routes to opensong: ' . basename($path));
}

/* -------------------------------------------------------------------- */
/* 2. A real OpenLyrics document routes to 'openlp'. Inline, matching    */
/*    the actual OpenLyrics schema (properties/songbooks/songbook, not   */
/*    a bare <songbook> — the schema the parser itself expects), mirror  */
/*    of test-openlyrics-parser.php's fixture style.                     */
/* -------------------------------------------------------------------- */
$openLyricsXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<song xmlns="http://openlyrics.info/namespace/2009/song" version="0.9">
 <properties>
  <titles><title>Amazing Grace</title></titles>
  <authors><author>John Newton</author></authors>
  <songbooks><songbook name="Probe882 Book" entry="7"/></songbooks>
 </properties>
 <lyrics>
  <verse name="v1"><lines>Amazing grace, how sweet the sound<br/>That saved a wretch like me</lines></verse>
 </lyrics>
</song>
XML;
aEq(_bulkImport_xmlAutoPrimary($openLyricsXml), 'openlp', 'routes to openlp: inline OpenLyrics fixture');

/* A namespace-less OpenLyrics export with named verses still sniffs true —
   the discriminator's structural fallback (<verse name=...> + <lines>)
   doesn't require the xmlns to be present. */
$openLyricsNoNs = <<<XML
<?xml version="1.0"?>
<song><properties><titles><title>Plain</title></titles></properties>
<lyrics><verse name="v1"><lines>Line one<br/>Line two</lines></verse></lyrics></song>
XML;
aEq(_bulkImport_xmlAutoPrimary($openLyricsNoNs), 'openlp', 'routes to openlp: namespace-less OpenLyrics export');

/* -------------------------------------------------------------------- */
/* 3. Both-formats-fail path: a document that is neither dialect names   */
/*    BOTH formats and BOTH real per-format reasons in one error — never */
/*    a misleading single-format error. Parse-only (no DB — see header). */
/* -------------------------------------------------------------------- */
/* Is a database actually reachable? ATTEMPT A CONNECTION — do not test for a
   socket file. A socket inode outlives the server that created it, so
   file_exists() reports "yes" against a dead mysqld and the skip guard fails
   to skip. (That exact mistake was made and fixed in
   test-delete-songs-rewiden.php earlier on this branch; repeating it here
   would be a second instance of the same bug in a guard.) */
$dbReachable = (static function (): bool {
    try {
        /* getDbMysqli() is the project's ONE connection helper (rule #5) and is
           already reachable here via song_importers.php's include chain. Under
           MYSQLI_REPORT_STRICT a failed connect THROWS rather than returning
           false, so the try/catch IS the probe. */
        $probe = getDbMysqli();
        return $probe instanceof mysqli;
    } catch (\Throwable) {
        return false;
    }
})();

if (!$dbReachable) {
    echo "\n  ⚠️  SKIPPED: the process-level assertions need a database.\n";
    echo "      They are NOT DB-free — see this file's header: processOpenSong\n";
    echo "      connects before it parses, because \$autoNumber is a parse\n";
    echo "      argument. Start MySQL/MariaDB to run them.\n";
    echo "      The pure routing assertions above DID run and are reported below.\n";
    if ($failed === 0) {
        echo "\nAll DB-free XML import routing assertions passed ($passed); "
           . "process-level assertions skipped.\n";
        exit(0);
    }
    fwrite(STDERR, "\n$failed assertion(s) failed.\n");
    exit(1);
}

$neither = '<notasong/>';
$autoResult = _bulkImport_processXmlAuto($neither, 'not-a-song.xml');
aEq($autoResult['ok'] ?? true, false, 'both-fail: ok is false');
aStrContains((string)($autoResult['error'] ?? ''), 'OpenLyrics', 'both-fail: error names OpenLyrics');
aStrContains((string)($autoResult['error'] ?? ''), 'OpenSong', 'both-fail: error names OpenSong');
aTrue(array_key_exists('format', $autoResult) && $autoResult['format'] === null, 'both-fail: no format resolved');
aEq($autoResult['songs_created'] ?? -1, 0, 'both-fail: no songs created');

/* The repo's own malformed OpenSong fixture (unclosed <title>, invalid
   XML) sniffs as OpenSong (the cheap regex sniff never fully parses) but
   fails BOTH parsers once actually tried — same combined-error shape. */
$malformedPath = __DIR__ . '/fixtures/opensong/malformed.xml';
$malformedBody = (string)file_get_contents($malformedPath);
aEq(_bulkImport_xmlAutoPrimary($malformedBody), 'opensong', 'malformed fixture still sniffs as opensong (regex-only sniff)');
$malformedResult = _bulkImport_processXmlAuto($malformedBody, 'malformed.xml');
aEq($malformedResult['ok'] ?? true, false, 'malformed fixture: both parsers reject it, ok is false');
aStrContains((string)($malformedResult['error'] ?? ''), 'OpenLyrics', 'malformed fixture: error names OpenLyrics');
aStrContains((string)($malformedResult['error'] ?? ''), 'OpenSong', 'malformed fixture: error names OpenSong');

if ($failed === 0) {
    echo "\nAll XML import routing assertions passed ($passed).\n";
    exit(0);
}
fwrite(STDERR, "\n$failed assertion(s) failed.\n");
exit(1);
