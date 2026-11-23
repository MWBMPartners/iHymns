<?php

declare(strict_types=1);

/**
 * iHymns - only real views from the live site count (account/profile rewrite PR 2a, step 2a-6, epic #2180)
 *
 * ELI5
 * ----
 * "Most popular" and the licence (CCLI) report count rows in the song-view history. Those rows used to
 * include views from the test copies of the site and old views a person uploaded later from a device
 * list, so the numbers were inflated. Now every new view is stamped with where it came from (a real
 * view or an upload, which copy of the site, which kind of app), and the two counting queries only
 * count real views from the live site. All of this is switched on per site (off by default) and only
 * works once the foundation migration has added the columns; until then every row counts, as before.
 *
 * This test checks the stamp, the one SQL fragment, which queries must use it (derived from the tree,
 * never typed), the rate limits (one place to change them), and - with a real database - that the
 * fragment really counts only the right rows.
 *
 * Detailed (rule #34)
 * -------------------
 * The "which queries must use it" check lists EVERY SQL statement under public_html that reads or
 * writes tblSongHistory (via the shared tests/php/lib/php_source_units.php, per function / per api.php
 * case) and requires each one to be classified: either it COUNTS views for popularity or licence
 * reporting (and must call songHistoryCountableSql()), or it is deliberately exempt with a stated
 * reason. A new query on that table fails until someone decides which it is, and a stale entry fails.
 * Mutation proofs are in the 2a-6 commit message (removing the helper from ccli_report.php turns this
 * red; so does removing it from popular_songs).
 *
 *   php tests/php/test-song-history-provenance.php
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" php tests/php/test-song-history-provenance.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $repo . '/tests/php/lib/php_source_units.php';
require_once $web . '/includes/song_history_provenance.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/* ---- the stamp ---- */
echo "the stamp on a new view\n";
$v = songHistoryProvenanceValues(['platform' => 'Apple']);
chk('a real view is stamped "view"', $v['source'] === 'view');
chk('the channel is the copy of the site that recorded it', $v['channel'] === ihymns_environment());
chk('the platform is taken from the app, folded to lower case, when it is a known one', $v['platform'] === 'apple');
chk('web and android are accepted too', songHistoryProvenanceValues(['platform' => 'web'])['platform'] === 'web' && songHistoryProvenanceValues(['platform' => 'android'])['platform'] === 'android');
chk('an unknown platform is dropped (never stored)', songHistoryProvenanceValues(['platform' => 'toaster'])['platform'] === null && songHistoryProvenanceValues(['platform' => "web'; DROP"])['platform'] === null);
chk('no platform, a non-string platform, or no body at all gives null', songHistoryProvenanceValues([])['platform'] === null && songHistoryProvenanceValues(['platform' => ['web']])['platform'] === null && songHistoryProvenanceValues(null)['platform'] === null);
chk('the platform list is exactly the ones the schema comment names', SONG_HISTORY_PLATFORMS === ['web', 'apple', 'android']);

/* ---- the fragment ---- */
echo "the counting condition\n";
chk('switch off: every row counts (1=1), whatever the columns', songHistoryCountableSqlFor(false, true) === '1=1' && songHistoryCountableSqlFor(false, false) === '1=1');
chk('switch on but the columns are not there yet: every row counts', songHistoryCountableSqlFor(true, false) === '1=1');
chk('both on: only real views from the live site (or an old row with no site)', songHistoryCountableSqlFor(true, true) === "Source = 'view' AND (Channel = 'production' OR Channel IS NULL)");
chk('with a table alias the columns carry it', songHistoryCountableSqlFor(true, true, 'h') === "h.Source = 'view' AND (h.Channel = 'production' OR h.Channel IS NULL)");
chk('a bad alias can never reach SQL', songHistoryCountableSqlFor(true, true, "h; DROP TABLE x") === '1=1' && songHistoryCountableSqlFor(true, true, '1h') === '1=1');
$noDb = songHistoryCountableSqlFor(true, true, 'h');
chk('the fragment is built from fixed words only (no placeholder, no variable)', !str_contains($noDb, '?') && !str_contains($noDb, '$'));

/* ---- which queries must use it (derived from the tree) ---- */
echo "every query on the view history is classified\n";
$MUST_FILTER = [
    'api.php#case \'popular_songs\''                       => 'the home "most popular" card counts views as popularity',
    'includes/ccli_report.php#ccliReportSystemRows'        => 'the CCLI report counts views for the licence return',
];
$EXEMPT = [
    'api.php#case \'song_view\''                           => 'WRITES the row (stamped here)',
    'api.php#case \'song_history_backfill\''               => 'WRITES uploaded rows (stamped as uploads here)',
    'api.php#case \'song_history\''                        => 'the person\'s own recently-viewed list, not a count',
    'api.php#case \'song_history_clear\''                  => 'removes the person\'s own rows',
    'includes/my_most_viewed.php#myMostViewedRows'        => 'a person\'s OWN most-viewed list: one account everywhere, so it counts every source and site (owner decision 4)',
    'manage/index.php#(file scope)'                        => 'admin dashboard traffic number: counts everything (owner decision 11)',
    'manage/analytics.php#(file scope)'                    => 'admin analytics counts everything (owner decision 11)',
    'includes/analytics_ingest.php#analyticsTopSongs'      => 'admin analytics counts everything (owner decision 11)',
    'includes/analytics_ingest.php#analyticsTopBooks'      => 'admin analytics counts everything (owner decision 11)',
    'includes/song_history_provenance.php#songHistoryProvenanceReady' => 'looks in INFORMATION_SCHEMA, not at the rows',
];
$found = [];
$mustCall = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    if (stripos($src, 'tblSongHistory') === false) { continue; }
    $rel = substr($f->getPathname(), strlen($web) + 1);
    foreach (phpSourceUnits($src) as $name => $u) {
        foreach ($u['strings'] as $q) {
            if (stripos($q, 'tblSongHistory') !== false && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/', $q)) {
                $found[$rel . '#' . $name] = true;
                $mustCall[$rel . '#' . $name] = str_contains($u['code'], 'songHistoryCountableSql(');
                break;
            }
        }
    }
}
$known = array_merge(array_keys($MUST_FILTER), array_keys($EXEMPT));
$unclassified = array_values(array_diff(array_keys($found), $known));
$stale = array_values(array_diff($known, array_keys($found)));
chk('every function or case that touches the view history is classified (' . count($found) . ' found)', $unclassified === [], 'unclassified: ' . implode('; ', $unclassified));
chk('no classification names something that is no longer there', $stale === [], 'stale: ' . implode('; ', $stale));
foreach ($MUST_FILTER as $unit => $why) {
    chk("$unit calls songHistoryCountableSql() ($why)", ($mustCall[$unit] ?? false) === true);
}
foreach ($EXEMPT as $unit => $why) {
    chk("$unit is exempt and does NOT filter, on purpose ($why)", ($mustCall[$unit] ?? null) === false);
}

/* ---- the handlers (comment-stripped, per case) ---- */
echo "the handlers\n";
$units = phpSourceUnits((string)file_get_contents($web . '/api.php'));
$view = $units["case 'song_view'"]['code'] ?? '';
$back = $units["case 'song_history_backfill'"]['code'] ?? '';
chk('song_view is rate limited behind the switch, using the constants (one place to change)',
    (bool)preg_match('/profileSyncEnabledHere\(\)/', $view) && (bool)preg_match("/enforceReadRateLimit\(\s*'song_view'\s*,\s*SONG_VIEW_RATE_PER_MINUTE\s*,\s*SONG_VIEW_RATE_PER_DAY\s*,/", $view));
chk('song_view limits per signed-in PERSON, else per address (the key override is built from the person\'s id)', (bool)preg_match('/SONG_VIEW_RATE_PER_DAY,\s*\$authUser\s*\?\s*\'@STR@\'\s*\.\s*\.?\s*\(int\)\s*\$authUser\[\'Id\'\]\s*:\s*\'@STR@\'\s*\)/', $view));
chk('song_view has no number typed in (60 / 3000 appear only in the constants)', !preg_match('/enforceReadRateLimit\(\s*\'song_view\'\s*,\s*\d/', $view));
chk('song_view stamps the row only when the columns exist', (bool)preg_match('/songHistoryProvenanceReady\(\$db\)/', $view) && str_contains($view, 'songHistoryProvenanceValues('));
chk('song_view still has the plain insert for the switch-off / not-migrated case', (bool)preg_match("/INSERT INTO tblSongHistory \(SongId, UserId\) VALUES/", implode(' ', $units["case 'song_view'"]['strings'] ?? [])));
chk('backfill is limited behind the switch with the constant, check and record paired (#1636)',
    (bool)preg_match("/checkRateLimit\(\s*'song_history_backfill'\s*,\s*\\\$backfillIp\s*,\s*SONG_BACKFILL_RATE_PER_HOUR\s*,\s*3600\s*,\s*true\s*,\s*\\\$userId\s*\)/", $back)
    && (bool)preg_match("/recordRateLimitHit\(\s*'song_history_backfill'\s*,\s*rateLimitKey\(/", $back));
chk('backfill stamps uploads as "backfill", never "view"', str_contains(implode(' ', $units["case 'song_history_backfill'"]['strings'] ?? []), "'backfill'") && !preg_match("/Source.{0,40}'view'/", implode(' ', $units["case 'song_history_backfill'"]['strings'] ?? [])));
chk('the limits are the owner\'s numbers: 60 a minute, 3,000 a day, 10 an hour', SONG_VIEW_RATE_PER_MINUTE === 60 && SONG_VIEW_RATE_PER_DAY === 3000 && SONG_BACKFILL_RATE_PER_HOUR === 10);
chk('the web app says which kind of app recorded a view and an upload',
    str_contains((string)file_get_contents($web . '/js/modules/router.js'), "song_id: songId, platform: 'web'")
    && str_contains((string)file_get_contents($web . '/js/modules/history.js'), "platform: 'web'"));

/* ---- a real database ---- */
echo "a real database\n";
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$h = '127.0.0.1'; $pt = 3306; $us = 'root'; $pw = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $val] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $h = $val; } if ($k === 'port') { $pt = (int)$val; } if ($k === 'user') { $us = $val; } if ($k === 'pass') { $pw = $val; }
}
$db = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($h, $us, $pw, '', $pt);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $_e) { $db = null; }
}
if ($db === null) {
    echo "  SKIP  no database - the real-rows checks did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2a6_history';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        /* Before the columns exist: the ready-check says no (a fresh process, so it is not remembered). */
        $db->query('CREATE TABLE tblSongHistory (Id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserId INT UNSIGNED NULL, SongId VARCHAR(20) NOT NULL, ViewedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $before = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
            '$_SERVER["SCRIPT_FILENAME"]="t"; require ' . var_export($web . '/includes/song_history_provenance.php', true) . ';'
            . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
            . '$d = new mysqli(' . var_export($h, true) . ',' . var_export($us, true) . ',' . var_export($pw, true) . ',' . var_export($name, true) . ',' . (int)$pt . ');'
            . 'echo json_encode([songHistoryProvenanceReady($d), songHistoryCountableSql($d, "h")]);'
        ));
        chk('before the columns exist the ready-check says no and the fragment is 1=1: ' . var_export($before, true), $before === '[false,"1=1"]');
        /* The three columns, copied from schema.sql so the test cannot drift from it. */
        $schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS tblSongHistory \(.*?\) ENGINE=InnoDB/s', $schema, $blk);
        $okCols = preg_match_all('/^\s*(Source VARCHAR\(20\)[^\n]*|Channel VARCHAR\(16\)[^\n]*|Platform VARCHAR\(20\)[^\n]*),\s*$/m', $blk[0] ?? '', $cm);
        chk('the three new columns are found in schema.sql', $okCols === 3, (string)$okCols);
        foreach ($cm[1] as $col) {
            $db->query('ALTER TABLE tblSongHistory ADD COLUMN ' . $col);
        }
        chk('with all three columns the ready-check says yes', songHistoryProvenanceReady($db) === true);
        $db->query("INSERT INTO tblSongHistory (SongId, Source, Channel, Platform) VALUES
            ('A-1','view','production','web'),      -- counts
            ('A-1','view',NULL,NULL),               -- an old row from before the columns: counts
            ('A-1','view','alpha','web'),           -- recorded by the dev site: does not count
            ('A-1','view','beta','apple'),          -- recorded by beta: does not count
            ('A-1','backfill','production','web'),  -- an upload: does not count
            ('A-2','view','production','apple')");  /* counts, other song */
        $sql = songHistoryCountableSqlFor(true, true, 'h');
        $n = (int)$db->query("SELECT COUNT(*) FROM tblSongHistory h WHERE $sql")->fetch_row()[0];
        chk('only the real views from the live site (or with no site recorded) are counted: 3 of 6', $n === 3, (string)$n);
        $nAll = (int)$db->query('SELECT COUNT(*) FROM tblSongHistory h WHERE ' . songHistoryCountableSqlFor(false, true, 'h'))->fetch_row()[0];
        chk('with the switch off every row counts: 6 of 6', $nAll === 6, (string)$nAll);
        $bySong = $db->query("SELECT SongId, COUNT(*) c FROM tblSongHistory h WHERE $sql GROUP BY SongId ORDER BY SongId")->fetch_all(MYSQLI_ASSOC);
        chk('it works inside a grouped popularity query: A-1 has 2, A-2 has 1', $bySong === [['SongId' => 'A-1', 'c' => '2'], ['SongId' => 'A-2', 'c' => '1']], json_encode($bySong));
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

exit($fail === 0 ? 0 : 1);
