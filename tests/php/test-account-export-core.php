<?php

declare(strict_types=1);

/**
 * iHymns - "download my data" gives a person their own data and never a secret (PR 2c-1, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * This test builds a real copy of the database from schema.sql, puts two people's saved things
 * in it (including secrets such as a password hash and a push key), asks for the first person's
 * file, and checks: all of their things are there, none of the other person's, and not one secret.
 *
 * Detailed (rule #34)
 *  1. PURE: the column rule (refuses secrets, allows ordinary names), the plan (exports links,
 *     excludes the reviewed ones, orders the bulky tables last), the table-name rule.
 *  2. A REAL DATABASE (IHYMNS_TEST_DSN; a SKIP is printed loudly, never a silent pass): the full
 *     schema is loaded; person 1 and person 2 have rows in several tables; the file for person 1
 *     holds person 1's rows, none of person 2's, none of the secret values, the account row without
 *     its password hash, the settings opened up as real JSON, the internal `_meta` profile rows left
 *     out, a table cut at the row limit says `truncated`, a table that does not exist on an
 *     un-migrated install is skipped quietly, and an excluded table's rows are not in the file.
 *
 * Mutation proofs are in the 2c-1 commit: the deny list emptied, the WHERE on the person's id
 * removed, and the `_meta` filter removed each turn this red.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3307;user=root;pass=" php tests/php/test-account-export-core.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/account_export.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

echo "the pure rules\n";
chk('ordinary column names are allowed', accountExportColumnAllowed('SongId') && accountExportColumnAllowed('Title') && accountExportColumnAllowed('ValueJson'));
chk('secret column names are refused, in any letter case', !accountExportColumnAllowed('PasswordHash') && !accountExportColumnAllowed('passwordhash') && !accountExportColumnAllowed('TOKEN') && !accountExportColumnAllowed('P256dhKey'));
chk('the table name rule', accountExportNameSafe('tblUserFavorites') && !accountExportNameSafe('tbl`x') && !accountExportNameSafe('a b') && !accountExportNameSafe('') && !accountExportNameSafe("tbl\n"));
$p = accountExportPlan([
    ['table' => 'tblActivityLog', 'column' => 'UserId'], ['table' => 'tblUserFavorites', 'column' => 'UserId'],
    ['table' => 'tblPasswordResetTokens', 'column' => 'UserId'], ['table' => 'tblUserFavorites', 'column' => 'userid'],
]);
$ex = array_map(fn($x) => $x['table'], $p['export']);
chk('a linked table is exported, a reviewed exclusion is left out with its reason, a repeat is ignored',
    in_array('tblUserFavorites', $ex, true) && !in_array('tblPasswordResetTokens', $ex, true) && count(array_filter($ex, fn($t) => $t === 'tblUserFavorites')) === 1
    && count(array_filter($p['excluded'], fn($e) => $e['table'] === 'tblPasswordResetTokens' && $e['reason'] !== '')) === 1);
chk('the hand-listed no-link column is exported and the sign-in attempt log is not', in_array('tblBulkImportJobs', $ex, true) && !in_array('tblLoginAttempts', $ex, true));
chk('bulky tables come last', array_search('tblActivityLog', $ex, true) > array_search('tblUserFavorites', $ex, true));

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
    exit($fail === 0 ? 0 : 1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'ihymns_t2c1_export';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
try {
    /* The real schema, as the CI database sees it. */
    $sql = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
    $db->multi_query($sql);
    do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());

    $db->query('SET FOREIGN_KEY_CHECKS = 0');   /* test rows need no songs behind them */
    $db->query("INSERT INTO tblUsers (Id, Username, Email, PasswordHash, DisplayName, Settings, PreferredLanguagesJson) VALUES
        (1, 'ann', 'ann@example.test', 'HASH-OF-ANN-SECRET', 'Ann', '{\"ihymns.web\":{\"ihymns_theme\":\"dark\"}}', '[\"en\"]'),
        (2, 'bob', 'bob@example.test', 'HASH-OF-BOB-SECRET', 'Bob', NULL, NULL)");
    $db->query("INSERT INTO tblUserFavorites (UserId, SongId) VALUES (1, 'MP-1'), (1, 'MP-2'), (2, 'MP-BOBS-ONLY')");
    $db->query("INSERT INTO tblUserCustomTags (UserId, Tag) VALUES (1, 'Sunday'), (2, 'BobTag')");
    $db->query("INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt) VALUES
        (1, 'appearance', 'theme', '\"dark\"', 3, NOW(3)), (1, '_meta', 'versionCounter', '3', 3, NOW(3)), (2, 'appearance', 'theme', '\"BOB-LIGHT\"', 1, NOW(3))");
    $db->query("INSERT INTO tblApiTokens (Token, UserId, DeviceName, ExpiresAt) VALUES ('TOKEN-SECRET-ANN', 1, 'Ann phone', DATE_ADD(NOW(), INTERVAL 1 DAY)), ('TOKEN-SECRET-BOB', 2, 'Bob phone', DATE_ADD(NOW(), INTERVAL 1 DAY))");
    $db->query("INSERT INTO tblPushSubscriptions (UserId, Endpoint, P256dhKey, AuthKey) VALUES (1, 'https://push.example/ENDPOINT-SECRET', 'P256-SECRET', 'AUTH-SECRET')");
    $db->query("INSERT INTO tblUserAuthProviders (UserId, Provider, Subject, RefreshToken) VALUES (1, 'apple', 'apple-subject-ann', 'REFRESH-SECRET')");
    $db->query("INSERT INTO tblEmailLoginTokens (UserId, Email, Token, Code, ExpiresAt) VALUES (1, 'ann@example.test', 'LOGINTOKEN-SECRET', '123456', DATE_ADD(NOW(), INTERVAL 1 DAY))");
    $db->query("INSERT INTO tblActivityLog (UserId, Action) VALUES (1, 'auth.login'), (2, 'auth.login')");
    $db->query("INSERT INTO tblBulkImportJobs (UserId, Filename, TempPath, SizeBytes) VALUES (1, 'a.zip', '/tmp/x', 5), (2, 'b.zip', '/tmp/y', 6)");
    $db->query("INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES ('203.0.113.9', 'ann', 0)");

    $text = accountExportText($db, 1);
    $out  = json_decode($text, true);
    $has = fn(string $needle) => strpos($text, $needle) !== false;

    chk('the file names its format and version', $out['format'] === ACCOUNT_EXPORT_FORMAT && $out['formatVersion'] === ACCOUNT_EXPORT_FORMAT_VERSION && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $out['exportedAt']) === 1);
    chk('the account details are there, with no password hash', ($out['account']['Username'] ?? null) === 'ann' && ($out['account']['Email'] ?? null) === 'ann@example.test' && !array_key_exists('PasswordHash', $out['account']));
    chk('the settings and language choice are opened up as real JSON, not text', ($out['account']['Settings']['ihymns.web']['ihymns_theme'] ?? null) === 'dark' && ($out['account']['PreferredLanguagesJson'] ?? null) === ['en']);
    $fav = array_column($out['tables']['tblUserFavorites.UserId']['rows'] ?? [], 'SongId');
    sort($fav);
    chk('their favourites are there and only theirs', $fav === ['MP-1', 'MP-2'], json_encode($fav));
    chk('their custom tags are there and only theirs', array_column($out['tables']['tblUserCustomTags.UserId']['rows'] ?? [], 'Tag') === ['Sunday']);
    $items = array_map(fn($r) => $r['Section'] . '.' . $r['ItemKey'], $out['tables']['tblUserProfileItems.UserId']['rows'] ?? []);
    chk('their profile items are there, the internal bookkeeping rows are not, and nobody else\'s are', $items === ['appearance.theme'], json_encode($items));
    chk('a table with no link on its own (the bulk import jobs) is read by its person-id column', array_column($out['tables']['tblBulkImportJobs.UserId']['rows'] ?? [], 'Filename') === ['a.zip']);
    chk('the audit log shows only their own rows', count($out['tables']['tblActivityLog.UserId']['rows'] ?? []) === 1);
    chk('their devices are listed (a token row with the secret column left out)', ($out['tables']['tblApiTokens.UserId']['rows'][0]['DeviceName'] ?? null) === 'Ann phone' && !array_key_exists('Token', $out['tables']['tblApiTokens.UserId']['rows'][0]));
    chk('the push registration is listed with none of its keys or address', isset($out['tables']['tblPushSubscriptions.UserId']) && array_diff(['Endpoint', 'P256dhKey', 'AuthKey'], $out['tables']['tblPushSubscriptions.UserId']['omittedColumns']) === []);
    chk('the linked sign-in keeps its provider and subject but not its refresh token', ($out['tables']['tblUserAuthProviders.UserId']['rows'][0]['Subject'] ?? null) === 'apple-subject-ann' && !array_key_exists('RefreshToken', $out['tables']['tblUserAuthProviders.UserId']['rows'][0]));
    chk('an excluded table (short-lived sign-in codes) is not in the file, and the file says why', !isset($out['tables']['tblEmailLoginTokens.UserId'])
        && count(array_filter($out['notIncluded'], fn($e) => $e['table'] === 'tblEmailLoginTokens' && $e['reason'] !== '')) === 1);
    chk('the sign-in attempt log is not in the file, and the file says why', count(array_filter($out['notIncluded'], fn($e) => $e['table'] === 'tblLoginAttempts' && $e['reason'] !== '')) === 1);
    foreach (['HASH-OF-ANN-SECRET', 'TOKEN-SECRET-ANN', 'ENDPOINT-SECRET', 'P256-SECRET', 'AUTH-SECRET', 'REFRESH-SECRET', 'LOGINTOKEN-SECRET'] as $secret) {
        chk("the secret value $secret appears nowhere in the file", !$has($secret));
    }
    foreach (['MP-BOBS-ONLY', 'BobTag', 'BOB-LIGHT', 'bob@example.test', 'HASH-OF-BOB-SECRET', 'TOKEN-SECRET-BOB', 'b.zip'] as $other) {
        chk("the other person's value $other appears nowhere in the file", !$has($other));
    }
    chk('the file is valid JSON that reads back', json_decode($text, true) !== null && json_last_error() === JSON_ERROR_NONE);
    chk('an empty table is not listed (the file stays readable)', !isset($out['tables']['tblUserSetlists.UserId']) && !isset($out['tables']['tblSongHistory.UserId']));

    /* The limit: one more than the cap is stored; the file holds the cap and says it was cut short. */
    $values = [];
    for ($i = 1; $i <= ACCOUNT_EXPORT_MAX_ROWS + 5; $i++) { $values[] = "(1, 'T{$i}')"; if (count($values) === 2000) { $db->query('INSERT INTO tblUserCustomTags (UserId, Tag) VALUES ' . implode(',', $values)); $values = []; } }
    if ($values) { $db->query('INSERT INTO tblUserCustomTags (UserId, Tag) VALUES ' . implode(',', $values)); }
    $big = accountExportBuild($db, 1)['tables']['tblUserCustomTags.UserId'];
    chk('a table over the row limit holds exactly the limit and says truncated', count($big['rows']) === ACCOUNT_EXPORT_MAX_ROWS && $big['truncated'] === true, count($big['rows']) . ' / ' . var_export($big['truncated'], true));
    $db->query('DELETE FROM tblUserCustomTags WHERE UserId = 1 AND Tag LIKE \'T%\'');

    /* A LARGE account, inside a small memory budget (PR 2c review). The first version loaded every row of a table before
       checking any limit and then built a second, pretty-printed copy: on a 128 MB host a heavy account ran PHP out of memory,
       which is a fatal error nothing can catch. 1,500 song revisions of ~80 KB each are ~120 MB of text in the database; the
       file is built in a child PHP process whose memory_limit is 64M, so the old code dies and the new one finishes. */
    $db->query("INSERT INTO tblSongRevisions (SongId, UserId, Action, PreviousData, NewData)
                SELECT CONCAT('MP-', seq), 1, 'edit', JSON_OBJECT('t', REPEAT('x', 40000)), JSON_OBJECT('t', REPEAT('y', 40000)) FROM seq_1_to_1500");
    $db->query("INSERT INTO tblActivityLog (UserId, Action, Details) SELECT 1, 'song.edit', JSON_OBJECT('t', REPEAT('z', 20000)) FROM seq_1_to_3000");
    $outFile = tempnam(sys_get_temp_dir(), 'ihymns-export-');
    $child = <<<'PHPCHILD'
        $_SERVER['SCRIPT_FILENAME'] = 'child';
        require getenv('EXPORT_WEB') . '/includes/account_export.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(getenv('EXPORT_HOST'), getenv('EXPORT_USER'), getenv('EXPORT_PASS'), getenv('EXPORT_DB'), (int)getenv('EXPORT_PORT'));
        $db->set_charset('utf8mb4');
        $out = fopen(getenv('EXPORT_OUT'), 'wb');
        accountExportStream($db, 1, $out);
        fclose($out);
        fwrite(STDOUT, 'peak=' . memory_get_peak_usage(true));
PHPCHILD;
    $env = ['EXPORT_WEB' => $web, 'EXPORT_HOST' => $hh, 'EXPORT_USER' => $us, 'EXPORT_PASS' => $pw, 'EXPORT_DB' => $name, 'EXPORT_PORT' => (string)$pt, 'EXPORT_OUT' => $outFile, 'PATH' => getenv('PATH') ?: ''];
    $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    $peak = preg_match('/peak=(\d+)/', $stdout, $mm) ? (int)$mm[1] : -1;
    chk('a large account is exported inside a 64 MB memory limit (the child process finishes)', $code === 0 && $peak > 0, 'exit ' . $code . ' ' . trim(substr($stderr . $stdout, 0, 200)));
    chk('...and its peak memory stays far under the limit (' . round($peak / 1048576, 1) . ' MB)', $peak > 0 && $peak < 40 * 1048576);
    $bigText = (string)file_get_contents($outFile);
    $bigOut  = json_decode($bigText, true);
    chk('...the file is valid JSON of a sane size (under the 32 MiB total)', is_array($bigOut) && strlen($bigText) <= ACCOUNT_EXPORT_MAX_TOTAL_BYTES + 65536, strlen($bigText) . ' bytes');
    $rev = $bigOut['tables']['tblSongRevisions.UserId'] ?? null;
    chk('...the revisions table is cut at its byte limit and says truncated', $rev !== null && $rev['truncated'] === true && count($rev['rows']) > 10 && count($rev['rows']) < 1000, json_encode($rev === null ? null : count($rev['rows'])));
    chk('...the favourites still come through whole (the bulky tables are read last)', array_column($bigOut['tables']['tblUserFavorites.UserId']['rows'] ?? [], 'SongId') === ['MP-1', 'MP-2']);
    $log = $bigOut['tables']['tblActivityLog.UserId'] ?? null;
    chk('...the audit log is held to its own lower row limit', $log !== null && count($log['rows']) <= accountExportRowCap('tblActivityLog') && $log['truncated'] === true, json_encode($log === null ? null : count($log['rows'])));
    @unlink($outFile);
    $db->query('DELETE FROM tblSongRevisions'); $db->query("DELETE FROM tblActivityLog WHERE Action = 'song.edit'");

    /* A table that does not exist yet (an install that has not run a migration) is skipped, not an error. */
    $db->query('DROP TABLE tblBulkImportJobs');
    $after = accountExportBuild($db, 1);
    chk('a missing table is skipped quietly', !isset($after['tables']['tblBulkImportJobs.UserId']) && isset($after['tables']['tblUserFavorites.UserId']));

    /* A person who holds nothing still gets a valid file. */
    $db->query("INSERT INTO tblUsers (Id, Username, Email) VALUES (3, 'cat', '')");
    $empty = accountExportBuild($db, 3);
    chk('a person with nothing saved gets a valid, nearly empty file', ($empty['account']['Username'] ?? null) === 'cat' && $empty['tables'] === []);

    /* A bad id is refused outright. */
    $threw = false;
    try { accountExportBuild($db, 0); } catch (\InvalidArgumentException $e) { $threw = true; }
    chk('a zero id is refused', $threw);
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
}
exit($fail === 0 ? 0 : 1);
