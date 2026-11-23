<?php

declare(strict_types=1);

/**
 * iHymns - "Your most viewed" (account/profile rewrite PR 2a, step 2a-7, epic #2180, issue #2175)
 *
 * ELI5
 * ----
 * A signed-in person can see the songs THEY open most often, over 30 days, 90 days, 12 months or all
 * time. The one thing that must never go wrong: the list must only ever count THAT person's views,
 * never anybody else's. This test checks the periods, the default, the order of the refusals (not
 * signed in, switch off, bad period, too many requests), that the language filter is applied and the
 * list is cut to 20, and - against a real database - that one person's list never contains another
 * person's views, counts every kind of own view, and respects each period's window.
 *
 * Mutation proof (rule #34), done in the 2a-7 commit: taking `h.UserId = ?` out of the query turns
 * the database checks and the structural check red.
 *
 *   php tests/php/test-my-most-viewed.php
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" php tests/php/test-my-most-viewed.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $repo . '/tests/php/lib/php_source_units.php';
require_once $web . '/includes/my_most_viewed.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

echo "the periods\n";
chk('the periods are exactly 30d, 90d, 12m and all', array_keys(MY_MOST_VIEWED_PERIODS) === ['30d', '90d', '12m', 'all']);
chk('and match the registry entry\'s allowed values (one list, never two)', MY_MOST_VIEWED_PERIODS !== [] && array_keys(MY_MOST_VIEWED_PERIODS) === profileRegistryEntry('home', 'mostViewedPeriod')['allowed']);
chk('the default is the registry default (12m)', MY_MOST_VIEWED_DEFAULT_PERIOD === profileRegistryEntry('home', 'mostViewedPeriod')['default'] && MY_MOST_VIEWED_DEFAULT_PERIOD === '12m');
chk('a known period is valid', myMostViewedPeriodValid('30d') && myMostViewedPeriodValid('all'));
chk('anything else is not (case, spaces, numbers, arrays, null)', !myMostViewedPeriodValid('30D') && !myMostViewedPeriodValid(' 30d') && !myMostViewedPeriodValid('week') && !myMostViewedPeriodValid(30) && !myMostViewedPeriodValid(['30d']) && !myMostViewedPeriodValid(null) && !myMostViewedPeriodValid(''));
chk('the owner\'s numbers: query takes 60, keeps 20, 60 reads a minute', MY_MOST_VIEWED_QUERY_LIMIT === 60 && MY_MOST_VIEWED_KEEP === 20 && MY_MOST_VIEWED_RATE_PER_MINUTE === 60);

echo "the handler\n";
$units = phpSourceUnits((string)file_get_contents($web . '/api.php'));
$h = $units["case 'my_most_viewed'"]['code'] ?? '';
chk('the case exists', $h !== '');
$at = static fn(string $needle): int|false => strpos($h, $needle);
$steps = [
    "REQUEST_METHOD'] !== 'GET'"      => 'only GET (405 otherwise)',
    'getAuthenticatedUser()'          => 'sign-in checked (401)',
    'profileSyncEnabledHere()'        => 'the switch checked (409)',
    'enforceReadRateLimit('           => 'the per-person limit',
    'myMostViewedPeriodValid('        => 'the period checked (400)',
    'myMostViewedRows('               => 'the query',
    'makeLanguageFilterPredicate('    => 'the language filter',
];
$last = -1; $order = true; $why = [];
foreach ($steps as $needle => $label) {
    $p = $at($needle);
    if ($p === false) { $order = false; $why[] = "missing: $label"; continue; }
    if ($p < $last) { $order = false; $why[] = "out of order: $label"; }
    $last = $p;
}
chk('refusals come in order: method, sign-in, switch, limit, period, then the query and the language filter', $order, implode('; ', $why));
chk('the rate limit is per signed-in person and uses the constant', (bool)preg_match("/enforceReadRateLimit\(\s*'my_most_viewed'\s*,\s*MY_MOST_VIEWED_RATE_PER_MINUTE\s*,\s*0\s*,\s*'@STR@'\s*\.\s*\\\$mvUserId\s*\)/", $h));
chk('the list is cut to the constant AFTER the language filter', (bool)preg_match('/array_slice\(\s*array_values\(\s*array_filter\(\s*\$mvSongs\s*,\s*\$_langPred\s*\)\s*\)\s*,\s*0\s*,\s*MY_MOST_VIEWED_KEEP\s*\)/', $h));
chk('the query is run for the SIGNED-IN person only (never an id from the request)', (bool)preg_match('/myMostViewedRows\(\s*\$db\s*,\s*\$mvUserId\s*,/', $h) && !preg_match('/\$_(GET|POST|REQUEST)\[\s*\'(user|uid|userId|user_id)\'/', $h));
chk('a 409 carries the shared disabled body, a 400 the invalid_period reason', str_contains($h, 'profileSyncDisabledBody()') && str_contains($h, "'invalid_period'"));

echo "the query\n";
$coreUnits = phpSourceUnits((string)file_get_contents($web . '/includes/my_most_viewed.php'));
$rows = $coreUnits['myMostViewedRows'] ?? ['sqlOnly' => [], 'code' => ''];
$sql = implode(' ', $rows['strings'] ?? []);
chk('every row is filtered by the person (h.UserId = ?)', str_contains($sql, 'h.UserId = ?'));
chk('it counts every kind of own view: no Source or Channel filter', !preg_match('/\b(Source|Channel)\b/', $sql));
chk('hidden songs and disabled songbooks are left out', str_contains($rows['code'], 'songVisibleSql($db') && str_contains($rows['code'], 'songServableSql($db'));
chk('it does NOT use the public popularity condition (that would hide own test-site views)', !str_contains($rows['code'], 'songHistoryCountableSql'));
chk('the day count is a bound value, not text in the SQL', str_contains($sql, 'INTERVAL ? DAY') && !preg_match('/INTERVAL\s+\d/', $sql));
chk('this view is in the classification of tblSongHistory queries as an exempt one (the 2a-6 test lists it)', str_contains((string)file_get_contents($repo . '/tests/php/test-song-history-provenance.php'), 'includes/my_most_viewed.php#myMostViewedRows'));
chk('the home card asks for it and stays hidden until a 200 comes back', str_contains((string)file_get_contents($web . '/js/modules/home-page.js'), "action=my_most_viewed")
    && str_contains((string)file_get_contents($web . '/includes/pages/home.php'), 'id="my-most-viewed-section" style="display:none"'));
chk('the period picker has a label', (bool)preg_match('/<label for="my-most-viewed-period"/', (string)file_get_contents($web . '/includes/pages/home.php')));

echo "a real database\n";
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$hh = '127.0.0.1'; $pt = 3306; $us = 'root'; $pw = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $val] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $hh = $val; } if ($k === 'port') { $pt = (int)$val; } if ($k === 'user') { $us = $val; } if ($k === 'pass') { $pw = $val; }
}
$db = null;
if ($dsn !== '') {
    try { mysqli_report(MYSQLI_REPORT_OFF); $db = @new mysqli($hh, $us, $pw, '', $pt); if ($db->connect_errno) { $db = null; } } catch (\Throwable $_e) { $db = null; }
}
if ($db === null) {
    echo "  SKIP  no database - the real-rows checks did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2a7_mostviewed';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->set_charset('utf8mb4');
    try {
        $db->query('CREATE TABLE tblUsers (Id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $db->query('INSERT INTO tblUsers (Id) VALUES (1), (2)');
        $db->query('CREATE TABLE tblSongbooks (Abbreviation VARCHAR(10) PRIMARY KEY, Name VARCHAR(100) NULL) ENGINE=InnoDB');
        $db->query("INSERT INTO tblSongbooks VALUES ('CP','Christian Praise')");
        $db->query('CREATE TABLE tblSongs (SongId VARCHAR(20) PRIMARY KEY, Title VARCHAR(200) NOT NULL, Number INT NULL, SongbookAbbr VARCHAR(10) NOT NULL, Language VARCHAR(35) NULL) ENGINE=InnoDB');
        $db->query("INSERT INTO tblSongs VALUES ('CP-1','One',1,'CP','en'),('CP-2','Two',2,'CP','en'),('CP-3','Three',3,'CP','en')");
        $schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
        $db->query('CREATE TABLE tblSongHistory (Id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserId INT UNSIGNED NULL, SongId VARCHAR(20) NOT NULL, ViewedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, Source VARCHAR(20) NOT NULL DEFAULT \'view\', Channel VARCHAR(16) NULL, Platform VARCHAR(20) NULL, INDEX idx_User (UserId)) ENGINE=InnoDB');
        /* person 1: CP-1 x3 (one an upload, one from the dev site), CP-2 x1 (45 days ago), CP-3 x1 (200 days ago).
           person 2: CP-2 x5 and an anonymous CP-3 view - none of which may ever show up for person 1. */
        $db->query("INSERT INTO tblSongHistory (UserId, SongId, ViewedAt, Source, Channel) VALUES
            (1,'CP-1', NOW(), 'view', 'production'),
            (1,'CP-1', NOW(), 'backfill', 'production'),
            (1,'CP-1', NOW(), 'view', 'alpha'),
            (1,'CP-2', DATE_SUB(NOW(), INTERVAL 45 DAY), 'view', 'production'),
            (1,'CP-3', DATE_SUB(NOW(), INTERVAL 200 DAY), 'view', NULL),
            (2,'CP-2', NOW(), 'view', 'production'), (2,'CP-2', NOW(), 'view', 'production'), (2,'CP-2', NOW(), 'view', 'production'),
            (2,'CP-2', NOW(), 'view', 'production'), (2,'CP-2', NOW(), 'view', 'production'),
            (NULL,'CP-3', NOW(), 'view', 'production')");
        $as = static fn(array $r): array => array_map(static fn($x) => $x['songId'] . ':' . $x['views'], $r);
        chk('30d: only CP-1, counting ALL of its 3 views (upload and dev-site views included)', $as(myMostViewedRows($db, 1, '30d')) === ['CP-1:3'], json_encode($as(myMostViewedRows($db, 1, '30d'))));
        chk('90d adds CP-2 (45 days ago) but not CP-3 (200 days ago)', $as(myMostViewedRows($db, 1, '90d')) === ['CP-1:3', 'CP-2:1'], json_encode($as(myMostViewedRows($db, 1, '90d'))));
        chk('12m reaches 200 days back', $as(myMostViewedRows($db, 1, '12m')) === ['CP-1:3', 'CP-2:1', 'CP-3:1'], json_encode($as(myMostViewedRows($db, 1, '12m'))));
        chk('all is the same here and has no date limit', $as(myMostViewedRows($db, 1, 'all')) === ['CP-1:3', 'CP-2:1', 'CP-3:1']);
        chk('person 2 sees only their own 5 views of CP-2, none of person 1\'s and not the anonymous one', $as(myMostViewedRows($db, 2, 'all')) === ['CP-2:5'], json_encode($as(myMostViewedRows($db, 2, 'all'))));
        chk('a person with no views gets an empty list', myMostViewedRows($db, 99, 'all') === []);
        chk('every row has the shape the API promises', array_keys(myMostViewedRows($db, 1, 'all')[0]) === ['songId', 'title', 'number', 'songbook', 'songbookName', 'language', 'views'] && myMostViewedRows($db, 1, 'all')[0]['songbookName'] === 'Christian Praise');
        for ($i = 0; $i < 70; $i++) {
            $db->query("INSERT INTO tblSongs VALUES ('X-$i','S$i',$i,'CP','en')");
            $db->query("INSERT INTO tblSongHistory (UserId, SongId) VALUES (1,'X-$i')");
        }
        chk('the query takes at most 60 rows', count(myMostViewedRows($db, 1, 'all')) === 60);

        /* the default period: from the saved profile item, else 12m */
        $db->query('CREATE TABLE tblUserProfileItems (UserId INT UNSIGNED NOT NULL, Section VARCHAR(40) NOT NULL, ItemKey VARCHAR(191) NOT NULL, ValueJson JSON NULL, Version BIGINT UNSIGNED NOT NULL, UpdatedAt DATETIME(3) NOT NULL, DeletedAt DATETIME(3) NULL, WriterChannel VARCHAR(16) NULL, WriterPlatform VARCHAR(20) NULL, PRIMARY KEY (UserId, Section, ItemKey)) ENGINE=InnoDB');
        chk('no saved choice: 12m', myMostViewedDefaultPeriod($db, 1) === '12m');
        $db->query("INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt) VALUES (1,'home','mostViewedPeriod','\"90d\"',1,NOW(3)), (2,'home','mostViewedPeriod','\"nonsense\"',1,NOW(3))");
        chk('a saved choice is used (and only for that person)', myMostViewedDefaultPeriod($db, 1) === '90d' && myMostViewedDefaultPeriod($db, 99) === '12m');
        chk('a saved value that is not a known period is ignored', myMostViewedDefaultPeriod($db, 2) === '12m');
        $db->query("UPDATE tblUserProfileItems SET DeletedAt = NOW(3) WHERE UserId = 1");
        chk('a removed choice (delete marker) is ignored', myMostViewedDefaultPeriod($db, 1) === '12m');

        /* table absent: a fresh process asks and gets the default, with no error */
        $db->query('DROP TABLE tblUserProfileItems');
        $absent = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
            '$_SERVER["SCRIPT_FILENAME"]="t"; require ' . var_export($web . '/includes/my_most_viewed.php', true) . ';'
            . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
            . '$d = new mysqli(' . var_export($hh, true) . ',' . var_export($us, true) . ',' . var_export($pw, true) . ',' . var_export($name, true) . ',' . (int)$pt . ');'
            . 'echo json_encode([myMostViewedDefaultPeriod($d, 1), profileItemRead($d, 1, "home", "mostViewedPeriod")]);'
        ));
        chk('before the new table exists: the default is 12m and nothing throws: ' . var_export($absent, true), $absent === '["12m",null]');
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

exit($fail === 0 ? 0 : 1);
