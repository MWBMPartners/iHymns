<?php

declare(strict_types=1);

/**
 * test-search-fold.php — diacritic/apostrophe-folded search (#1039 Part A)
 * ========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Proves the three load-bearing facts of the search-fold feature, in three
 * deliberately-different halves (the #1701/#1708 lesson: a static model, a live
 * load, and a tree-derived guard each catch a class the others cannot):
 *
 *   1. FUNCTIONAL fold truth table (no DB) — ihymns_search_fold() folds the
 *      apostrophe-elision and (iconv-permitting) accent / special-letter classes
 *      and is idempotent. Accent/special assertions are capability-gated so the
 *      suite is portable across iconv hosts (the test-song-similarity.php
 *      precedent), while the apostrophe class — which does NOT depend on iconv
 *      transliteration (the punctuation strip removes ’ regardless) — is
 *      asserted unconditionally.
 *
 *   2. LIVE half (the test-schema-installs.php pattern; SKIPS loudly when no
 *      MySQL/MariaDB is reachable) — a scratch table mirroring the two new
 *      FULLTEXT indexes, seeded with "Miłość" / "aren’t" / "Noël" fixtures whose
 *      folded columns are computed by the REAL ihymns_search_fold(), asserting
 *      the §1 truth table EXECUTABLE: the folded arm matches +milosc* / +arent*
 *      that the raw arm misses, and the raw arm still covers the é-class.
 *
 *   3. TREE-DERIVED funnel guard (rule #34) — every file under
 *      appWeb/public_html that WRITES tblSongs.LyricsText must call
 *      searchFoldSyncSong(). The write-set is DERIVED from the source (four
 *      precise write regexes over comment-stripped code), never a typed list, so
 *      a NEW LyricsText funnel that forgets the fold sync fails the build.
 *      Mutation-proven: delete the call in any one funnel → this goes RED.
 *
 * Plus a narrow structural check that _runFulltextSearch carries the gated
 * folded MATCH() arm (generous window — the #34 lesson about brittle regexes).
 *
 *   php tests/php/test-search-fold.php
 *
 * CONNECTION (live half): set IHYMNS_TEST_DSN (e.g. host=127.0.0.1;user=root;pass=)
 * or rely on a local socket as root. Nothing here touches an application
 * database — it creates and drops its own ihymns_fold_probe_*.
 *
 * @see appWeb/public_html/includes/title_normalize.php  ihymns_search_fold()
 * @see appWeb/public_html/includes/search_fold.php       searchFoldSyncSong()
 * @see appWeb/public_html/includes/SongData.php          _runFulltextSearch dual-arm
 * @link https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html
 */

$root = dirname(__DIR__, 2);

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}
function skip(string $label): void { echo "  SKIP  " . $label . "\n"; }

/* ====================================================================== *
 * HALF 1 — functional fold truth table (no DB)
 * ====================================================================== */
echo "Half 1 — ihymns_search_fold() truth table\n";

require_once $root . '/appWeb/public_html/includes/title_normalize.php';
ok('ihymns_search_fold() is defined', function_exists('ihymns_search_fold'));
require_once $root . '/appWeb/public_html/includes/search_fold.php';
ok('searchFoldReady() + searchFoldSyncSong() are defined',
   function_exists('searchFoldReady') && function_exists('searchFoldSyncSong'));

/* Apostrophe-elision — iconv-INDEPENDENT (the [^\p{L}\p{N}\s] strip removes ’
   whether or not the host transliterates it), so assert unconditionally. */
ok('curly-apostrophe elision:  aren’t → arent',
   ihymns_search_fold('aren’t') === 'arent',
   'got: ' . ihymns_search_fold('aren’t'));
ok('leading curly apostrophe:  ’Tis → tis',
   ihymns_search_fold('’Tis') === 'tis',
   'got: ' . ihymns_search_fold('’Tis'));
ok('straight apostrophe elision:  Ris\'n → risn',
   ihymns_search_fold("Ris'n") === 'risn',
   'got: ' . ihymns_search_fold("Ris'n"));

/* ASCII passthrough + idempotence — always portable. */
ok('ASCII passthrough:  Amazing Grace → amazing grace',
   ihymns_search_fold('Amazing Grace') === 'amazing grace');
ok('empty string folds to empty', ihymns_search_fold('') === '');
foreach (['Miłość', 'Noël', 'aren’t', 'The First Noël', ''] as $probe) {
    $once = ihymns_search_fold($probe);
    ok("idempotent: fold(fold(" . ($probe === '' ? '∅' : $probe) . ")) === fold(…)",
       ihymns_search_fold($once) === $once);
}

/* Accent class — iconv ASCII//TRANSLIT dependent; gate on a host capability
   probe (Noël→noel) so a non-glibc iconv SKIPS rather than falsely fails. */
$accentCapable = ihymns_search_fold('Noël') === 'noel';
if ($accentCapable) {
    ok('accent fold:  Noël → noel', ihymns_search_fold('Noël') === 'noel');
    ok('accent fold:  José → jose', ihymns_search_fold('José') === 'jose',
       'got: ' . ihymns_search_fold('José'));
} else {
    skip('accent class (Noël/José) — this host\'s iconv does not ASCII//TRANSLIT accents');
}

/* Special-letter class (ł ø …) — a distinct iconv capability; gate separately
   (Miłość→milosc), as some hosts fold accents but not these. */
$specialCapable = ihymns_search_fold('Miłość') === 'milosc';
if ($specialCapable) {
    ok('special-letter fold:  Miłość → milosc', ihymns_search_fold('Miłość') === 'milosc');
    ok('special-letter fold:  Bjørn → bjorn', ihymns_search_fold('Bjørn') === 'bjorn',
       'got: ' . ihymns_search_fold('Bjørn'));
} else {
    skip('special-letter class (Miłość/Bjørn) — this host\'s iconv does not fold ł/ø');
}

/* ====================================================================== *
 * HALF 3 (before the DB half so it always runs) — tree-derived funnel guard
 * ====================================================================== */
echo "\nHalf 3 — every tblSongs.LyricsText write funnel calls searchFoldSyncSong()\n";

/** Strip PHP comments so a comment mentioning a write pattern can never add a
 *  phantom funnel (rule #34), and a string literal like '#1039' is untouched. */
function fold_stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/* The FOUR precise write signatures that, together, derive EXACTLY the set of
   files performing a tblSongs INSERT/UPDATE that writes the LyricsText column —
   and match NEITHER a read (`s.LyricsText`, `$s['LyricsText']`) NOR the folded
   mirror (`LyricsTextFolded = ?`). Verified against the tree on 2026-08-18:
     (a) upsert tail          LyricsText = VALUES(
     (b) SET LyricsText = ?   (editor api2 rebuild, editor api restore)
     (c) literal INSERT col   , LyricsText )        (lyrics_ingest)
     (d) $cols array member   , 'LyricsText'        (song_importers) */
$writeRegexes = [
    '/LyricsText\s*=\s*VALUES\s*\(/',
    '/LyricsText\s*=\s*\?/',
    '/,\s*LyricsText\s*\)/',
    "/,\s*'LyricsText'/",
];

$docroot = $root . '/appWeb/public_html';
$writeFiles = [];
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $scanned++;
    $code = fold_stripComments((string)file_get_contents($f->getPathname()));
    foreach ($writeRegexes as $re) {
        if (preg_match($re, $code)) { $writeFiles[$f->getPathname()] = true; break; }
    }
}
$writeFiles = array_keys($writeFiles);
sort($writeFiles);

ok('the docroot walk actually scanned files (parser not broken)', $scanned > 200,
   "only scanned $scanned .php files");

/* Derived, not typed: the set is expected to be these 5 funnels — but the guard
   proves membership by DERIVATION and only names them here for a readable
   failure. A NEW funnel joining the set is caught by the per-file call check
   below regardless of this expectation. */
$expected = [
    $docroot . '/includes/lyrics_ingest.php',
    $docroot . '/includes/song_importers.php',
    $docroot . '/manage/editor/api.php',
    $docroot . '/manage/editor/api2.php',
    $docroot . '/manage/editor/save_song_core.php',
];
sort($expected);
ok('the derived LyricsText write-set is the 5 known funnels (no more, no fewer)',
   $writeFiles === $expected,
   "derived:\n          " . implode("\n          ", array_map(fn($p) => str_replace($root . '/', '', $p), $writeFiles))
   . "\n        expected:\n          " . implode("\n          ", array_map(fn($p) => str_replace($root . '/', '', $p), $expected)));

/* THE load-bearing assertion: every derived funnel calls searchFoldSyncSong().
   Mutation-proof — remove any one call and this line goes RED. */
foreach ($writeFiles as $f) {
    $code = fold_stripComments((string)file_get_contents($f));
    ok('calls searchFoldSyncSong():  ' . str_replace($root . '/', '', $f),
       preg_match('/searchFoldSyncSong\s*\(/', $code) === 1);
}

/* ---- narrow structural check: the read path carries the gated folded arm ---- */
echo "\nStructural — _runFulltextSearch dual-arm + gate present\n";
$songDataSrc = fold_stripComments((string)file_get_contents($docroot . '/includes/SongData.php'));
ok('SongData defines the folded-index readiness gate _searchFoldReady()',
   preg_match('/function\s+_searchFoldReady\s*\(/', $songDataSrc) === 1);
ok('_runFulltextSearch OR-s a second folded MATCH() arm into the WHERE',
   /* generous window: a MATCH(...) AGAINST(... BOOLEAN ...) OR MATCH( ... */
   preg_match('/AGAINST\(\?\s*IN\s*BOOLEAN\s*MODE\)\s*OR\s*MATCH\(/', $songDataSrc) === 1);
ok('_runFulltextSearch SUMS the folded relevance score',
   preg_match('/AGAINST\(\?\s*IN\s*BOOLEAN\s*MODE\)\s*\+\s*MATCH\(/', $songDataSrc) === 1);
ok('the folded MATCH() names the folded columns (NormalizedTitle / LyricsTextFolded)',
   strpos($songDataSrc, 's.NormalizedTitle, s.LyricsTextFolded') !== false
   && strpos($songDataSrc, "'ft_NormTitleLyricsFolded'") !== false);

/* ====================================================================== *
 * HALF 2 — LIVE FULLTEXT dual-arm (SKIPS loudly when no server)
 * ====================================================================== */
echo "\nHalf 2 — LIVE folded FULLTEXT arm (scratch DB)\n";

$dsn  = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null;
if ($dsn !== '') {
    foreach (explode(';', $dsn) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'host')   { $host = $v; }
        if ($k === 'user')   { $user = $v; }
        if ($k === 'pass')   { $pass = $v; }
        if ($k === 'socket') { $sock = $v; }
    }
} elseif (file_exists('/var/run/mysqld/mysqld.sock')) {
    $sock = '/var/run/mysqld/mysqld.sock';
}

$db = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);   // probing: a failed connect is an answer, not a fatal
    $db = $sock !== null
        ? @new mysqli(null, $user, $pass, '', 0, $sock)
        : @new mysqli($host, $user, $pass);
    if ($db->connect_errno) { $db = null; }
} catch (\Throwable $e) {
    $db = null;
}

if ($db === null) {
    skip('no MySQL/MariaDB reachable — the LIVE folded-FULLTEXT half did not run.');
    echo "        Half 1 (fold) + Half 3 (funnel guard) above are conclusive on their own;\n";
    echo "        set IHYMNS_TEST_DSN or install a server in CI to also prove the SQL arm.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $probe = 'ihymns_fold_probe_' . substr(hash('sha256', (string)getmypid()), 0, 8);
    try {
        $db->query("DROP DATABASE IF EXISTS `$probe`");
        $db->query("CREATE DATABASE `$probe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->select_db($probe);

        /* A scratch table mirroring the two FULLTEXT index shapes: the RAW arm
           (Title, LyricsText) and the FOLDED arm (NormalizedTitle,
           LyricsTextFolded), same InnoDB + utf8mb4_unicode_ci as prod. */
        $db->query(
            "CREATE TABLE tblFoldProbe (
                Id               INT AUTO_INCREMENT PRIMARY KEY,
                Title            VARCHAR(500) NOT NULL,
                LyricsText       MEDIUMTEXT   NOT NULL,
                NormalizedTitle  VARCHAR(500) NOT NULL DEFAULT '',
                LyricsTextFolded MEDIUMTEXT   NULL DEFAULT NULL,
                FULLTEXT ft_raw    (Title, LyricsText),
                FULLTEXT ft_folded (NormalizedTitle, LyricsTextFolded)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        /* Fixtures — folded columns computed by the REAL fold, exactly as the
           write funnels do. */
        $fixtures = [
            ['Miłość Boża',      'Miłość Boża jest wielka'],       // special-letter class
            ['When I Survey',    'and aren’t we all in need'],      // apostrophe-elision in lyrics
            ['The First Noël',   'The First Noël the angels sing'], // accent class
        ];
        $ins = $db->prepare('INSERT INTO tblFoldProbe (Title, LyricsText, NormalizedTitle, LyricsTextFolded) VALUES (?, ?, ?, ?)');
        foreach ($fixtures as [$t, $ly]) {
            $nt = ihymns_search_fold($t);
            $lf = ihymns_search_fold($ly);
            $ins->bind_param('ssss', $t, $ly, $nt, $lf);
            $ins->execute();
        }
        $ins->close();

        /* Helper: how many rows match a BOOLEAN-mode expression on a column set. */
        $matchCount = function (string $cols, string $expr) use ($db): int {
            $sql = "SELECT COUNT(*) AS n FROM tblFoldProbe WHERE MATCH($cols) AGAINST(? IN BOOLEAN MODE)";
            $st = $db->prepare($sql);
            $st->bind_param('s', $expr);
            $st->execute();
            $n = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);
            $st->close();
            return $n;
        };

        $rawCols    = 'Title, LyricsText';
        $foldedCols = 'NormalizedTitle, LyricsTextFolded';

        /* The é-class already works on the RAW arm via the utf8mb4 collation —
           this is the §1 finding "accents are (mostly) already covered". */
        ok('RAW arm matches +noe* (é already collation-insensitive)',
           $matchCount($rawCols, '+noe*') >= 1);

        /* Only assert the special-letter GAP + its FOLDED closure when the host
           iconv actually folds ł (the fixture's fold is 'milosc' only then). */
        if ($specialCapable) {
            ok('RAW arm MISSES +milosc* (the special-letter gap #1039 closes)',
               $matchCount($rawCols, '+milosc*') === 0);
            ok('FOLDED arm MATCHES +milosc* (gap closed)',
               $matchCount($foldedCols, '+milosc*') >= 1);
        } else {
            skip('special-letter live arm — host iconv does not fold ł');
        }

        /* Apostrophe-elision is iconv-independent, so always assert both arms. */
        ok('RAW arm MISSES +arent* (apostrophe-elision gap)',
           $matchCount($rawCols, '+arent*') === 0);
        ok('FOLDED arm MATCHES +arent* (gap closed)',
           $matchCount($foldedCols, '+arent*') >= 1);

        /* The dual-arm OR is the union — never fewer than either arm alone. */
        $dualNoel = (function () use ($db) {
            $sql = "SELECT COUNT(*) AS n FROM tblFoldProbe
                     WHERE MATCH(Title, LyricsText) AGAINST(? IN BOOLEAN MODE)
                        OR MATCH(NormalizedTitle, LyricsTextFolded) AGAINST(? IN BOOLEAN MODE)";
            $st = $db->prepare($sql);
            $a = '+noe*'; $b = ihymns_search_fold('noel');
            $st->bind_param('ss', $a, $b);
            $st->execute();
            $n = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);
            $st->close();
            return $n;
        })();
        ok('DUAL-ARM (raw OR folded) matches the Noël fixture', $dualNoel >= 1);
    } catch (\Throwable $e) {
        ok('LIVE folded-FULLTEXT half executed without error', false, $e->getMessage());
    } finally {
        try { $db->query("DROP DATABASE IF EXISTS `$probe`"); } catch (\Throwable $e) { /* best effort */ }
        $db->close();
    }
}

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All search-fold assertions passed.\n";
