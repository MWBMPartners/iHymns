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
 * Part C (#2137 review round 4, a real database):
 *   - "no language" means NULL or only spaces, tabs and line breaks
 *     (mediaLanguageIsBlank()): those songs are filled; a no-break or
 *     zero-width space is a value, listed and left alone;
 *   - the card's "pending" check agrees with the card itself: a tab-only
 *     song makes it pending, a no-break-space song does not, and after a run
 *     it is done — checked against the card's own dry run on each data set;
 *   - who ran it: a web run records the signed-in user (UserId) and
 *     ranBy "web", a command-line run ranBy "command line" — both through
 *     the function and through the real script (the web one as
 *     /manage/setup-database runs it); a web run with nobody signed in
 *     changes nothing;
 *   - a fill and its log row are one transaction: when the log refuses a row
 *     part-way through, no fill survives;
 *   - another person's change made while the card runs is never overwritten
 *     (fixtures/backfill-other-session.php). On MariaDB 11.8 with snapshot
 *     isolation on, the whole run fails and rolls back; with it off, and on
 *     MySQL, that one song is skipped. Both are checked where they occur.
 *
 * Mutation-proven (see the commit bodies): putting back the rewrite branch,
 * `$apply = true;` in the script, and removing 'manual' => true each turn
 * checks red. Round 4: dropping the `Language <=> ?` guard, treating only ''
 * as blank, dropping the transaction, the probe's old SQL TRIM() test, the
 * probe without the PHP blank test, not writing UserId, and not writing
 * ranBy each turn checks red.
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
/* #2137 review round 4 — who ran it. */
$check('who ran it? command line → no user, "command line"',
    migrateBackfillSongLanguageActor(true, ['id' => 5]) === ['userId' => null, 'ranBy' => 'command line']);
$check('who ran it? web, signed in as user 7 → user 7, "web"',
    migrateBackfillSongLanguageActor(false, ['id' => 7, 'role' => 'global_admin']) === ['userId' => 7, 'ranBy' => 'web']);
$check('who ran it? web, nobody signed in → no user, "web" (a confirmed run then refuses — Part C)',
    migrateBackfillSongLanguageActor(false, null) === ['userId' => null, 'ranBy' => 'web']);
$check('the script asks that function, with the signed-in user on the web',
    str_contains($scriptSrc, "migrateBackfillSongLanguageActor(\$isCli, (!\$isCli && function_exists('getCurrentUser')) ? getCurrentUser() : null)"));
$check('"no language" is NULL or only spaces, tabs and line breaks — not a no-break or zero-width space',
    mediaLanguageIsBlank(null) && mediaLanguageIsBlank('') && mediaLanguageIsBlank(" \t\r\n ") && mediaLanguageIsBlank("\t")
    && !mediaLanguageIsBlank("\u{00A0}") && !mediaLanguageIsBlank("\u{200B}") && !mediaLanguageIsBlank("\x0B") && !mediaLanguageIsBlank('en'));

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
        $cliActor = ['userId' => null, 'ranBy' => 'command line'];
        $check('probe: pending before the run (three songs with no language)', $probe($db) === true);

        $dry = migrateBackfillSongLanguageFromSongbook($db, false, $collect, $cliActor);
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
        $res = migrateBackfillSongLanguageFromSongbook($db, true, $collect, $cliActor);
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
        $userIds = array_column($db->query('SELECT UserId FROM tblActivityLog ORDER BY EntityId')->fetch_all(MYSQLI_ASSOC), 'UserId');
        $check('…and who ran it: "command line", with no user',
            array_column($details, 'ranBy') === ['command line', 'command line'] && $userIds === [null, null],
            json_encode([$details, $userIds]));
        $check('probe: done after the run, although yue, de, und… still differ from their songbook', $probe($db) === false);

        $again = migrateBackfillSongLanguageFromSongbook($db, true, $collect, $cliActor);
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

        /* ============================================================ Part C */
        echo "\nPart C — #2137 review round 4\n";
        $webActor = static fn(int $id): array => ['userId' => $id, 'ranBy' => 'web'];
        $noOut = static function (string $_l): void {};
        $userIdsNow = static fn(): array => array_column(
            $db->query('SELECT UserId FROM tblActivityLog ORDER BY EntityId')->fetch_all(MYSQLI_ASSOC), 'UserId');

        /* --- blank means NULL or only the rule's four trim characters (B6),
               and the "pending" check agrees with the card (item 4) --- */
        $loadSongs = static function (array $songs) use ($db): void {
            $db->query('DELETE FROM tblSongs');
            $db->query('DELETE FROM tblActivityLog');
            $ins = $db->prepare('INSERT INTO tblSongs (SongId, SongbookAbbr, Language) VALUES (?, ?, ?)');
            foreach ($songs as [$id, $book, $lang]) { $ins->bind_param('sss', $id, $book, $lang); $ins->execute(); }
            $ins->close();
        };
        $setUp();
        $spaced = [['HR-SP', 'HR', '   '], ['HR-TAB', 'HR', "\t"], ['HR-CRLF', 'HR', "\r\n"], ['HR-MIX', 'HR', " \t\n "],
                   ['HR-NBSP', 'HR', "\u{00A0}"], ['HR-ZWSP', 'HR', "\u{200B}"], ['HR-VT', 'HR', "\x0B"], ['HR-OK', 'HR', 'hr']];
        $loadSongs($spaced);
        $before = $snapshot();
        $check('probe: pending when songs hold only spaces, tabs or line breaks', $probe($db) === true);
        $res = migrateBackfillSongLanguageFromSongbook($db, true, $noOut, $cliActor);
        $after = $snapshot();
        $check('songs holding only spaces, tabs or line breaks are filled (they have no language)',
            array_diff_assoc($after, $before) === ['HR-CRLF' => 'hr', 'HR-MIX' => 'hr', 'HR-SP' => 'hr', 'HR-TAB' => 'hr'],
            json_encode(array_diff_assoc($after, $before), JSON_UNESCAPED_UNICODE));
        $check('a no-break space, a zero-width space and a vertical tab are values: listed for review, not filled',
            $after['HR-NBSP'] === "\u{00A0}" && $after['HR-ZWSP'] === "\u{200B}" && $after['HR-VT'] === "\x0B"
            && count(array_filter($res['differing'], static fn(array $d): bool => in_array($d['songId'], ['HR-NBSP', 'HR-ZWSP', 'HR-VT'], true))) === 3);
        $check('probe: done after the run, although the no-break-space song is still there', $probe($db) === false);

        /* The probe against the card's own dry run, one data set at a time. */
        $agreement = [];
        foreach ([
            'only a tab-only song'          => [['HR-TAB', 'HR', "\t"], ['HR-OK', 'HR', 'hr']],
            'only a no-break-space song'    => [['HR-NBSP', 'HR', "\u{00A0}"], ['HR-OK', 'HR', 'hr']],
            'only a zero-width-space song'  => [['HR-ZWSP', 'HR', "\u{200B}"]],
            'only a line-break song'        => [['HR-LF', 'HR', "\n"]],
            'a blank song in a mul book'    => [['MULTI-X', 'MULTI', "\t"], ['HR-OK', 'HR', 'hr']],
            'nothing blank'                 => [['HR-OK', 'HR', 'hr'], ['ZH-Y', 'ZH', 'yue']],
        ] as $label => $songs) {
            $loadSongs($songs);
            $dryFill = migrateBackfillSongLanguageFromSongbook($db, false, $noOut, $cliActor)['filled'];
            $agreement[$label] = [$probe($db), $dryFill !== []];
        }
        $check('the "pending" check says yes exactly when the card\'s own dry run would fill something (tab-only: yes;'
            . ' no-break space: no; zero-width space: no; line break: yes; a blank song in a mul book: no; nothing blank: no)',
            array_map(static fn(array $a): bool => $a[0] === $a[1], $agreement) === array_fill_keys(array_keys($agreement), true)
            && array_column($agreement, 0) === [true, false, false, true, false, false],
            json_encode($agreement));

        /* --- who ran it (item 8(ii)) --- */
        $setUp();
        migrateBackfillSongLanguageFromSongbook($db, true, $noOut, $webActor(42));
        $webDetails = array_map(static fn(array $r): array => json_decode((string)$r['Details'], true), $logRows());
        $check('a web run records the signed-in user (UserId 42) and ranBy "web" on every row',
            $userIdsNow() === ['42', '42'] && array_column($webDetails, 'ranBy') === ['web', 'web'],
            json_encode([$userIdsNow(), $webDetails]));
        $setUp();
        $fresh = $snapshot();
        $refused = null;
        try {
            migrateBackfillSongLanguageFromSongbook($db, true, $noOut, ['userId' => null, 'ranBy' => 'web']);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $check('a confirmed web run with nobody signed in changes nothing, and says why',
            $refused !== null && str_contains($refused, 'Could not tell who is running this card') && $snapshot() === $fresh && $logRows() === [],
            (string)$refused);

        /* The real script as /manage/setup-database runs it: that page defines
           IHYMNS_SETUP_DASHBOARD, has signed the user in (getCurrentUser()),
           and passes confirm=1 on the query string. */
        $asDashboard = static function (?int $signedInId) use ($script, $prepend): array {
            $wrap = tempnam(sys_get_temp_dir(), 'ihymns-backfill-web-');
            file_put_contents($wrap, '<?php define("IHYMNS_SETUP_DASHBOARD", true);'
                . ' function getCurrentUser(): ?array { return ' . ($signedInId === null ? 'null' : "['id' => {$signedInId}]") . '; }'
                . ' $_GET["confirm"] = "1"; require ' . var_export($script, true) . ';');
            $proc = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, $wrap], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            @unlink($wrap);
            return [$code, $out];
        };
        $setUp();
        [$code, $out] = $asDashboard(7);
        $dashDetails = array_map(static fn(array $r): array => json_decode((string)$r['Details'], true), $logRows());
        $check('the real script, run the way /manage/setup-database runs it, records user 7 and ranBy "web"',
            $code === 0 && $userIdsNow() === ['7', '7'] && array_column($dashDetails, 'ranBy') === ['web', 'web'],
            "exit {$code}: " . trim($out) . ' ' . json_encode($userIdsNow()));
        $setUp();
        $fresh = $snapshot();
        [$code, $out] = $asDashboard(null);
        $check('…and with nobody signed in it changes nothing and says why',
            $snapshot() === $fresh && $logRows() === [] && str_contains($out, 'Could not tell who is running this card'), trim($out));
        $setUp();
        [$code, $out] = $runCli(['--confirm']);
        $cliDetails = array_map(static fn(array $r): array => json_decode((string)$r['Details'], true), $logRows());
        $check('the real script from the command line records no user and ranBy "command line"',
            $code === 0 && $userIdsNow() === [null, null] && array_column($cliDetails, 'ranBy') === ['command line', 'command line'],
            "exit {$code}: " . json_encode([$userIdsNow(), $cliDetails]));

        /* --- a fill and its log row are one transaction (B7) --- */
        $setUp();
        $fresh = $snapshot();
        $db->query("CREATE TRIGGER trg_t2137_refuse_log BEFORE INSERT ON tblActivityLog FOR EACH ROW
                    BEGIN IF NEW.EntityId = 'ZH-3' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test: the log refuses this row'; END IF; END");
        $threw = null;
        try {
            migrateBackfillSongLanguageFromSongbook($db, true, $noOut, $cliActor);
        } catch (\Throwable $e) {
            $threw = $e->getMessage();
        }
        $check('when the log refuses a row part-way through (ZH-3, after HR-2 was filled and logged), no fill survives',
            $threw !== null && str_contains($threw, 'the log refuses this row') && $snapshot() === $fresh && $logRows() === [],
            (string)$threw . ' ' . json_encode(array_diff_assoc($snapshot(), $fresh)));

        /* --- another person's change is never overwritten (B5, item 8(i)) --- */
        $helper = __DIR__ . '/fixtures/backfill-other-session.php';
        $snapshotVar = $db->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch_row();
        $modes = ['as this server is configured' => null];
        if ($snapshotVar !== null && strtoupper((string)$snapshotVar[1]) === 'ON') {
            $modes['with snapshot isolation switched off for the session'] = 'OFF';
        }
        foreach ($modes as $modeLabel => $sessionSetting) {
            $setUp();
            if ($sessionSetting !== null) { $db->query("SET SESSION innodb_snapshot_isolation = {$sessionSetting}"); }
            $other = proc_open([PHP_BINARY, $helper, $host, (string)$port, $user, $pass, $name, 'HR-2', 'fr', '1500'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $otherPipes);
            $ready = trim((string)fgets($otherPipes[1]));
            $threw = null;
            try {
                migrateBackfillSongLanguageFromSongbook($db, true, $noOut, $cliActor);
            } catch (\Throwable $e) {
                $threw = $e->getMessage();
            }
            $otherRest = (string)stream_get_contents($otherPipes[1]) . (string)stream_get_contents($otherPipes[2]);
            fclose($otherPipes[1]);
            fclose($otherPipes[2]);
            $otherCode = proc_close($other);
            if ($sessionSetting !== null) { $db->query('SET SESSION innodb_snapshot_isolation = DEFAULT'); }
            $now = $snapshot();
            $logged = array_column($logRows(), 'EntityId');
            $skipped = $threw === null && $now['ZH-3'] === 'zh' && $logged === ['ZH-3'];
            $rolledBack = $threw !== null && str_contains($threw, 'Record has changed since last read')
                && $now['ZH-3'] === '' && $logged === [];
            $check("another person's change during a run is never overwritten ({$modeLabel}): HR-2 keeps their `fr`, no log row"
                . ' claims HR-2, and the run either skipped that song or stopped and rolled back — here it '
                . ($skipped ? 'skipped that song' : ($rolledBack ? 'stopped and rolled back' : 'did neither')),
                $ready === 'locked' && $otherCode === 0 && str_contains($otherRest, 'committed')
                && $now['HR-2'] === 'fr' && !in_array('HR-2', $logged, true) && ($skipped || $rolledBack),
                json_encode(['ready' => $ready, 'threw' => $threw, 'HR-2' => $now['HR-2'], 'ZH-3' => $now['ZH-3'], 'logged' => $logged, 'other' => trim($otherRest)]));
        }
    } finally {
        if ($prepend !== null) { @unlink($prepend); }
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
