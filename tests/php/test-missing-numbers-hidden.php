<?php

declare(strict_types=1);

/**
 * iHymns — Missing Numbers "held by hidden songs" classification test (#1829)
 *
 * `/manage/missing-numbers` counts a soft-deleted (hidden) song's number as
 * PRESENT so the slot is never wrongly offered as a gap (#1694 — the
 * (SongbookAbbr, Number) index is non-unique; if a hidden song's number read as
 * "missing", a curator would fill it and restoring the original would mint a
 * duplicate number). #1829 keeps that behaviour but ALSO surfaces the numbers
 * whose ONLY song is hidden, so a curator can restore or purge them.
 *
 * SongData::getMissingSongNumbers() now returns `hiddenHeld` (sorted numbers
 * occupied ONLY by soft-deleted song(s)) + `hiddenHeldCount`, alongside the
 * unchanged `missing` / `maxNumber` / `totalExisting`.
 *
 * PART A (always): source scan — the classification is wired through the ONE
 * gated soft-delete predicate `songVisibleSql()` (never a raw, un-migrated-
 * unsafe `IsDeleted` reference — the #1228 STRICT-mode lesson), returns the new
 * keys, and flags a number ONLY when every song on it is deleted (`$live === 0`).
 *
 * PART B (DB-reachable, else SKIP): insert a scratch songbook + songs inside a
 * transaction and assert the real query classifies them correctly, then roll
 * back (never persists). Covers: all-deleted → flagged; live → not; MIXED
 * (one live + one deleted on the same number) → NOT flagged (a live song keeps
 * the slot present); empty slot → a gap.
 *
 *   php tests/php/test-missing-numbers-hidden.php
 *   IHYMNS_TEST_DSN='host=127.0.0.1;user=root;pass=;dbname=ihymns_live' php tests/php/test-missing-numbers-hidden.php
 *
 * Exit 0 = pass (Part B may SKIP if no DB), 1 = failure.
 */

$repoRoot = dirname(__DIR__, 2);
$songDataFile = $repoRoot . '/appWeb/public_html/includes/SongData.php';

$failures = [];

/* =========================================================================
 * PART A — source scan (always runs; no DB needed)
 * ========================================================================= */
$src = file_get_contents($songDataFile);
if ($src === false) {
    fwrite(STDERR, "FAIL: cannot read {$songDataFile}\n");
    exit(1);
}

/* Slice out just the getMissingSongNumbers() method body so the assertions
   can't be satisfied by unrelated code elsewhere in the ~4k-line file. */
$start = strpos($src, 'function getMissingSongNumbers');
if ($start === false) {
    fwrite(STDERR, "FAIL: getMissingSongNumbers() not found in SongData.php\n");
    exit(1);
}
$next   = strpos($src, "\n    public function ", $start + 1);
$nextP  = strpos($src, "\n    private function ", $start + 1);
if ($nextP !== false && ($next === false || $nextP < $next)) { $next = $nextP; }
$method = substr($src, $start, ($next === false ? strlen($src) : $next) - $start);

if (strpos($method, 'songVisibleSql(') === false) {
    $failures[] = 'PART A: getMissingSongNumbers() must classify live-vs-hidden through the gated '
                . 'songVisibleSql() predicate (degrades to 1=1 on an un-migrated docroot), not a raw IsDeleted reference.';
}
foreach (['hiddenHeld', 'hiddenHeldCount'] as $key) {
    if (strpos($method, "'{$key}'") === false) {
        $failures[] = "PART A: getMissingSongNumbers() must return the '{$key}' key (#1829).";
    }
}
/* A number is flagged ONLY when NO song on it is live — the mixed-slot guard. */
if (strpos($method, '$live === 0') === false) {
    $failures[] = 'PART A: the hidden-held classification must require $live === 0 (every song on the '
                . 'number soft-deleted) — a number with even one live song must stay present, never flagged.';
}

/* =========================================================================
 * PART B — behavioural (runs when a DB is reachable, else SKIP)
 * ========================================================================= */
$behaviouralRan = false;

/* Prefer IHYMNS_TEST_DSN (CI) — define DB_* from it BEFORE db_mysql.php loads,
   so its own credentials-file loader stands down. With no DSN, requiring
   db_mysql.php loads appWeb/.auth/db_credentials.php from its canonical path
   (local dev) and defines DB_* for us — so this test never hardcodes that path. */
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
if ($dsn !== '') {
    foreach (explode(';', $dsn) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'host' && !defined('DB_HOST'))               { define('DB_HOST', $v); }
        if ($k === 'user' && !defined('DB_USER'))               { define('DB_USER', $v); }
        if ($k === 'pass' && !defined('DB_PASS'))               { define('DB_PASS', $v); }
        if ($k === 'port' && !defined('DB_PORT'))               { define('DB_PORT', $v); }
        if (($k === 'dbname' || $k === 'db') && !defined('DB_NAME')) { define('DB_NAME', $v); }
    }
}

require_once $repoRoot . '/appWeb/public_html/includes/db_mysql.php';
require_once $songDataFile;

if (defined('DB_NAME') && DB_NAME !== '') {
    try {
        $db = getDbMysqli();
        $hasSongs = (bool)$db->query("SHOW TABLES LIKE 'tblSongs'")->num_rows;
    } catch (\Throwable $e) {
        $db = null; $hasSongs = false;
    }

    if ($db !== null && $hasSongs) {
        $abbr = 'ZZMN';   // scratch songbook, rolled back below
        $db->begin_transaction();
        try {
            $db->query("DELETE FROM tblSongs WHERE SongbookAbbr = '{$abbr}'");
            $db->query("DELETE FROM tblSongbooks WHERE Abbreviation = '{$abbr}'");

            $bk = $db->prepare("INSERT INTO tblSongbooks (Abbreviation, Name) VALUES (?, ?)");
            $nm = 'ZZ Missing-Numbers Test';
            $bk->bind_param('ss', $abbr, $nm);
            $bk->execute();
            $bk->close();

            /* (SongId, Number, IsDeleted). Fixture layout:
                 #1  live                       -> present
                 #2  deleted only               -> HIDDEN-HELD
                 #3  (no song)                  -> gap
                 #4  live + deleted (mixed)     -> present (a live song keeps it)
                 #6  live                       -> present  (#5 therefore a gap) */
            $rows = [
                ['ZZMN-1',  1, 0],
                ['ZZMN-2',  2, 1],
                ['ZZMN-4a', 4, 0],
                ['ZZMN-4b', 4, 1],
                ['ZZMN-6',  6, 0],
            ];
            $ins = $db->prepare("INSERT INTO tblSongs (SongId, Title, SongbookAbbr, Number, IsDeleted) VALUES (?, ?, ?, ?, ?)");
            foreach ($rows as [$sid, $num, $del]) {
                $title = 'Fixture ' . $sid;
                $ins->bind_param('sssii', $sid, $title, $abbr, $num, $del);
                $ins->execute();
            }
            $ins->close();

            $res = SongData::forAdmin()->getMissingSongNumbers($abbr);

            $hidden = array_map('intval', $res['hiddenHeld'] ?? []);
            $missing = array_map('intval', $res['missing'] ?? []);

            if ($hidden !== [2]) {
                $failures[] = 'PART B: hiddenHeld must be [2] (only #2 has all songs deleted; #4 has a live '
                            . 'song so is NOT flagged), got ' . json_encode($hidden) . '.';
            }
            if ((int)($res['hiddenHeldCount'] ?? -1) !== 1) {
                $failures[] = 'PART B: hiddenHeldCount must be 1, got ' . var_export($res['hiddenHeldCount'] ?? null, true) . '.';
            }
            if ($missing !== [3, 5]) {
                $failures[] = 'PART B: missing must be [3,5] (empty slots), got ' . json_encode($missing) . '.';
            }
            if (in_array(4, $hidden, true)) {
                $failures[] = 'PART B: #4 (one live + one deleted) must NOT be hidden-held — a live song keeps the slot present.';
            }
            if (in_array(2, $missing, true)) {
                $failures[] = 'PART B: #2 is occupied by a hidden song and must NOT be reported as missing (that is the #1694 trap).';
            }
            if ((int)($res['totalExisting'] ?? 0) !== 5) {
                $failures[] = 'PART B: totalExisting must count ROWS (5), got ' . var_export($res['totalExisting'] ?? null, true) . '.';
            }
            $behaviouralRan = true;
        } finally {
            $db->rollback();   // never persist the fixtures
        }
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */
if ($failures) {
    fwrite(STDERR, "FAIL: Missing Numbers hidden-held classification (#1829):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}

echo 'PASS: getMissingSongNumbers() classifies hidden-held numbers via the gated predicate';
echo $behaviouralRan
    ? ", and the behavioural check confirmed it against a live DB (all-deleted #2 flagged, mixed #4 not, gaps #3/#5 missing, rows counted).\n"
    : " (source scan only — Part B SKIPPED: no reachable database with dbname; set IHYMNS_TEST_DSN to exercise it).\n";
exit(0);
