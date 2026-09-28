<?php

declare(strict_types=1);

/**
 * iHymns — the songbook-language card only fills songs that have NO
 * language, lists the rest, records every fill, and runs only when a curator
 * confirms it (#2137 reviews, #2132)
 *
 * ELI5: a migration card gives songs their songbook's language. Its first
 * version ran with every upgrade and replaced "not known" (`und`) and "no
 * language" (`zxx`) with a guess; its second still turned `yue` and
 * `cmn-Hans` songs in a Chinese (`zh`) book into `zh`, and a German song in a
 * Croatian book into Croatian, with no record. This test proves, against a
 * real database, that it now only fills EMPTY languages, leaves every song
 * that has any value alone (listing the ones that differ from their songbook
 * for a curator), writes one activity-log row per fill, changes nothing
 * unless confirmed, and is kept out of "Apply all".
 *
 * CHECKS
 * ------
 * Part A (no database) — read from the real files, never typed in:
 *   - the registry entry is `'manual' => true` and `'dryRunnable' => true`,
 *     and its text says it only fills songs with no language;
 *   - setup-database.php derives its manual list from that flag and both
 *     "Apply all" paths skip manual cards;
 *   - migrateBackfillSongLanguageConfirmed(): only `--confirm` on the command
 *     line, or `confirm=1` on the web, confirms.
 * Part B (a real database; skipped, loudly, when none is reachable):
 *   - the reviewer's set: a `zh` book with `yue`, `cmn-Hans` and an empty
 *     song, an `hr` book with `de` and an empty song — only the two empty
 *     songs are filled; `yue`, `cmn-Hans` and `de` are untouched and listed
 *     for review; the earlier set's `und`, `zxx`, `mul`, `mis`, `qaa`,
 *     `x-hymnal`, `i-default` and a malformed value are untouched too;
 *   - the dry run changes nothing and lists every song it would fill;
 *   - the confirmed run writes one activity-log row per filled song
 *     (from "", to the songbook's language, the note), and nothing else;
 *   - the probe says "pending" before and "done" after;
 *   - a second confirmed run changes nothing;
 *   - the script itself, run as a command-line process against the database:
 *     without --confirm nothing changes; with it, the fills happen.
 *
 * Mutation-proven (see the commit body): putting back the rewrite branch,
 * `$apply = true;` in the script, and removing 'manual' => true each turn
 * checks red.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" (the
 * same variable test-schema-installs.php reads). A throwaway database named
 * ihymns_t2137_backfill is created and dropped.
 *
 *   php tests/php/test-song-language-backfill.php
 */

$repoRoot = dirname(__DIR__, 2);
$script   = $repoRoot . '/appWeb/.sql/migrate-backfill-song-language-from-songbook.php';
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};

echo "tests/php/test-song-language-backfill.php — the songbook-language card only fills empty languages\n";

/* ---------------------------------------------------------------- Part A */
echo "\nPart A — the card is manual, fill-only, and dry-run unless confirmed\n";

/* Real probe helper, used by the live probe call in Part B (the registry's
   probes call these; setup-database.php defines them for real). */
function _migProbe_columnExists(\mysqli $db, string $table, string $column): bool
{
    $s = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $s->bind_param('ss', $table, $column);
    $s->execute();
    $ok = $s->get_result()->fetch_row() !== null;
    $s->close();
    return $ok;
}
function _migProbe_tableExists(\mysqli $db, string $t): bool { return false; }
function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
$hasCredentials = true;
$_SERVER['SCRIPT_FILENAME'] = '/different.php';   // the registry refuses direct access; this is a require
$MIGRATIONS = require $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';
$entry = $MIGRATIONS['backfill-song-language-from-songbook'] ?? null;
$body  = (string)($entry['card']['body'] ?? '');
$check('the backfill card is in the migration registry', is_array($entry));
$check("the card is 'manual' => true (never run by \"Apply all\" or the setup wizard)", ($entry['manual'] ?? null) === true);
$check("the card is 'dryRunnable' => true (a web run without confirm only reports)", ($entry['dryRunnable'] ?? null) === true);
$check('the card says it only fills songs with no language, never changes one that has a value, and lists the rest',
    str_contains($body, 'no language at all') && str_contains($body, 'never')
    && str_contains($body, 'changes a song that already has any value') && str_contains($body, 'listed')
    && str_contains($body . (string)($entry['card']['title'] ?? ''), 'curator'));

$setupSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/manage/setup-database.php');
$check('setup-database.php builds its manual list from the registry\'s flag',
    preg_match('/if \(!empty\(\$_entry\[\'manual\'\]\)\)\s*\{\s*\$migrationManual\[\$_slug\] = true;/', $setupSrc) === 1);
$check('the server-side "Apply all" loop skips every manual card',
    preg_match('/foreach \(\(\$proceedBulk \? \$migrationOrder : \[\]\) as \$migAction\) \{.*?if \(!empty\(\$migrationManual\[\$migAction\]\)\) \{.*?continue;/s', $setupSrc) === 1);
$check('the list the page\'s bulk runner walks (and the pending counter) leaves manual cards out',
    substr_count($setupSrc, 'isset($migrationCards[$slug]) && empty($migrationManual[$slug])') >= 2);

define('IHYMNS_MIGRATION_NO_AUTORUN', true);
require_once $script;
$confirmCases = [
    ['command line, no arguments',            true,  [],                     [],                     false],
    ['command line, --confirm',               true,  ['x.php', '--confirm'], [],                     true],
    ['command line, --confirm=1 (not it)',    true,  ['x.php', '--confirm=1'], [],                   false],
    ['command line, confirm=1 in $_GET only', true,  ['x.php'],              ['confirm' => '1'],     false],
    ['web, no confirm',                       false, [],                     [],                     false],
    ['web, confirm=1',                        false, [],                     ['confirm' => '1'],     true],
    ['web, confirm=yes',                      false, [],                     ['confirm' => 'yes'],   false],
    ['web, --confirm in argv only',           false, ['x.php', '--confirm'], [],                     false],
];
foreach ($confirmCases as [$label, $isCli, $argv, $get, $want]) {
    $check("confirmed? {$label} → " . ($want ? 'yes' : 'no (dry run)'),
        migrateBackfillSongLanguageConfirmed($isCli, $argv, $get) === $want);
}
$scriptSrc = (string)file_get_contents($script);
$check('the script decides with that function (so the test above is the rule it runs by)',
    preg_match('/\$apply = migrateBackfillSongLanguageConfirmed\(/', $scriptSrc) === 1);

/* ---------------------------------------------------------------- Part B */
echo "\nPart B — against a real database\n";

$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $port = 3306; $user = 'root'; $pass = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $host = $v; }
    if ($k === 'port') { $port = (int)$v; }
    if ($k === 'user') { $user = $v; }
    if ($k === 'pass') { $pass = $v; }
}
$db = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $user, $pass, '', $port);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}
if ($db === null) {
    echo "  SKIP  no database — Part B did NOT run. Set IHYMNS_TEST_DSN to run it; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_backfill';
    $prepend = null;
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        $setUp = static function () use ($db): void {
            $db->query('DROP TABLE IF EXISTS tblSongs, tblSongbooks, tblActivityLog');
            $db->query('CREATE TABLE tblSongbooks (Abbreviation VARCHAR(10) NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL)');
            $db->query('CREATE TABLE tblSongs (SongId VARCHAR(20) NOT NULL PRIMARY KEY, SongbookAbbr VARCHAR(10) NOT NULL, Language VARCHAR(35) NULL)');
            /* The columns logActivity() and the card write; the rest of the
               real table all have defaults. */
            $db->query("CREATE TABLE tblActivityLog (Id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserId INT UNSIGNED NULL,
                Action VARCHAR(50) NOT NULL, EntityType VARCHAR(50) NOT NULL DEFAULT '', EntityId VARCHAR(50) NOT NULL DEFAULT '',
                Result ENUM('success','failure','error') NOT NULL DEFAULT 'success', Details JSON NULL,
                CreatedAt TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))");
            $db->query("INSERT INTO tblSongbooks VALUES ('ZH','zh'), ('HR','hr'), ('HAC','hr'), ('MULTI','mul'), ('NONE',NULL), ('BAD','Croatian')");
            $rows = [
                /* the reviewer's set */
                ['ZH-1', 'ZH', 'yue'], ['ZH-2', 'ZH', 'cmn-Hans'], ['ZH-3', 'ZH', ''],
                ['HR-1', 'HR', 'de'],  ['HR-2', 'HR', null],
                /* the earlier set */
                ['HAC-1', 'HAC', 'en'], ['HAC-2', 'HAC', 'hr-HR'],
                ['HAC-3', 'HAC', 'und'], ['HAC-4', 'HAC', 'zxx'], ['HAC-5', 'HAC', 'mul'], ['HAC-6', 'HAC', 'mis'],
                ['HAC-7', 'HAC', 'qaa'], ['HAC-8', 'HAC', 'x-hymnal'], ['HAC-9', 'HAC', 'i-default'], ['HAC-10', 'HAC', 'English'],
                ['MULTI-1', 'MULTI', ''], ['NONE-1', 'NONE', ''], ['BAD-1', 'BAD', ''],
            ];
            $ins = $db->prepare('INSERT INTO tblSongs (SongId, SongbookAbbr, Language) VALUES (?, ?, ?)');
            foreach ($rows as [$id, $book, $lang]) { $ins->bind_param('sss', $id, $book, $lang); $ins->execute(); }
            $ins->close();
        };
        $snapshot = static function () use ($db): array {
            $out = [];
            $r = $db->query('SELECT SongId, Language FROM tblSongs ORDER BY SongId');
            while ($row = $r->fetch_row()) { $out[$row[0]] = $row[1]; }
            return $out;
        };
        $logRows = static function () use ($db): array {
            $r = $db->query("SELECT Action, EntityType, EntityId, Details FROM tblActivityLog ORDER BY EntityId");
            return $r->fetch_all(MYSQLI_ASSOC);
        };
        $setUp();
        $before = $snapshot();
        $lines = [];
        $collect = static function (string $l) use (&$lines): void { $lines[] = $l; };

        $probe = $entry['probe'];
        $check('probe: pending before the run (three songs with no language)', $probe($db) === true);

        $dry = migrateBackfillSongLanguageFromSongbook($db, false, $collect);
        $dryText = implode("\n", $lines);
        $check('a dry run changes nothing and writes no activity-log row', $snapshot() === $before && $logRows() === []);
        $check('a dry run lists every song it would fill, "none" → the songbook\'s language: exactly ZH-3 → zh and HR-2 → hr',
            array_map(static fn($f) => $f['songId'] . '→' . $f['to'], $dry['filled']) === ['HR-2→hr', 'ZH-3→zh']
            && str_contains($dryText, '[would fill] ZH-3 (ZH): none → zh') && str_contains($dryText, '[would fill] HR-2 (HR): none → hr'),
            json_encode($dry['filled']));
        $check('a dry run lists yue, cmn-Hans and de (and the other differing values) for review, not changed',
            str_contains($dryText, '[review by hand, not changed] ZH-1: "yue" differs from songbook ZH (zh)')
            && str_contains($dryText, '[review by hand, not changed] ZH-2: "cmn-Hans"')
            && str_contains($dryText, '[review by hand, not changed] HR-1: "de" differs from songbook HR (hr)'));

        $lines = [];
        $res = migrateBackfillSongLanguageFromSongbook($db, true, $collect);
        $after = $snapshot();
        $check('a confirmed run fills ONLY the songs with no language (ZH-3 → zh, HR-2 → hr)',
            $after['ZH-3'] === 'zh' && $after['HR-2'] === 'hr'
            && array_diff_assoc($after, $before) === ['HR-2' => 'hr', 'ZH-3' => 'zh'],
            json_encode(array_diff_assoc($after, $before)));
        foreach (['ZH-1', 'ZH-2', 'HR-1', 'HAC-1', 'HAC-2', 'HAC-3', 'HAC-4', 'HAC-5', 'HAC-6', 'HAC-7', 'HAC-8', 'HAC-9', 'HAC-10'] as $id) {
            $check("{$id} (" . var_export($before[$id], true) . ') is untouched', $after[$id] === $before[$id], 'now ' . var_export($after[$id], true));
        }
        foreach (['MULTI-1', 'NONE-1', 'BAD-1'] as $id) {
            $check("{$id}: a songbook without one ordinary language fills none of its songs", $after[$id] === $before[$id]);
        }
        $check('the confirmed run\'s report lists the songs left for review, too',
            str_contains(implode("\n", $lines), '[review by hand, not changed] HR-1: "de"')
            && count($res['differing']) === 12, (string)count($res['differing']));
        $log = $logRows();
        $details = array_map(static fn(array $r): array => json_decode((string)$r['Details'], true), $log);
        $check('one activity-log row per filled song, and no other',
            array_column($log, 'EntityId') === ['HR-2', 'ZH-3']
            && array_unique(array_column($log, 'Action')) === ['migration.song_language_backfill']
            && array_unique(array_column($log, 'EntityType')) === ['song'], json_encode($log));
        $check('each row says old → new and "set from songbook by the backfill card"',
            $details[0]['from'] === null && $details[0]['to'] === 'hr' && $details[1]['from'] === '' && $details[1]['to'] === 'zh'
            && $details[0]['note'] === 'set from songbook by the backfill card' && $details[1]['songbook'] === 'ZH',
            json_encode($details));
        $check('probe: done after the run, although yue, de, und… still differ from their songbook', $probe($db) === false);

        $again = migrateBackfillSongLanguageFromSongbook($db, true, $collect);
        $check('a second confirmed run changes nothing and logs nothing more',
            $snapshot() === $after && $again['filled'] === [] && count($logRows()) === 2);

        /* ---- the script itself, as a command-line process ---- */
        $setUp();
        $prepend = tempnam(sys_get_temp_dir(), 'ihymns-backfill-db-');
        file_put_contents($prepend, '<?php define("DB_HOST", ' . var_export($host, true) . '); define("DB_PORT", ' . $port
            . '); define("DB_USER", ' . var_export($user, true) . '); define("DB_PASS", ' . var_export($pass, true)
            . '); define("DB_NAME", ' . var_export($name, true) . '); define("DB_CHARSET", "utf8mb4"); define("DB_PREFIX", "");');
        $runCli = static function (array $args) use ($script, $prepend): array {
            $proc = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, $script], $args),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($proc), $out];
        };
        $fresh = $snapshot();
        [$code, $out] = $runCli([]);
        $check('the script run WITHOUT --confirm changes nothing (and says it is a dry run)',
            $code === 0 && $snapshot() === $fresh && $logRows() === [] && str_contains($out, 'DRY RUN'), "exit {$code}: " . trim($out));
        [$code, $out] = $runCli(['--confirm']);
        $cliAfter = $snapshot();
        $check('the script run WITH --confirm fills ZH-3 and HR-2 and nothing else',
            $code === 0 && array_diff_assoc($cliAfter, $fresh) === ['HR-2' => 'hr', 'ZH-3' => 'zh'] && count($logRows()) === 2,
            "exit {$code}: " . json_encode(array_diff_assoc($cliAfter, $fresh)));
    } finally {
        if ($prepend !== null) { @unlink($prepend); }
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
