<?php

declare(strict_types=1);

/**
 * iHymns — Set-list sync: a missing `expiresAt` keeps the stored expiry (#2207)
 *
 * ELI5
 * ----
 * The website always tells the server a set list's expiry date (or "none"). The
 * Apple app has no such field, so it says nothing about it. Before this fix,
 * saying nothing was read as "no expiry" and wiped a date set on the web. The
 * rule now: the key present (even as null) = "this is the truth" (null clears);
 * the key missing = "I know nothing about expiry" = keep what is stored. This is
 * the same rule `plan` already follows on this endpoint.
 *
 * WHAT IS REAL HERE
 * -----------------
 * The four SQL statements are lifted out of the real `user_setlists_sync`
 * handler in api.php (never retyped here, rule #34), prepared and run against a
 * real database, so a change to the SQL changes what this test sees. The
 * "which statement is chosen" decision lives inline in that handler and cannot
 * be called alone, so it is checked from the source (comment-stripped). The
 * "unchanged row" compare is the real function from includes/user_sync.php.
 *
 * A database is needed for part B: set IHYMNS_TEST_DSN (host=..;port=..;user=..;pass=..).
 * Without one the database part prints a loud SKIP (a gap, not a pass).
 *
 *   php tests/php/test-setlist-expiry-preserve.php
 *
 * Exit 0 = clean, 1 = at least one check failed.
 *
 * @see https://www.php.net/manual/en/function.array-key-exists.php
 */

$repoRoot = dirname(__DIR__, 2);
$web      = $repoRoot . '/appWeb/public_html';
require_once $web . '/includes/user_sync.php';

$fail = 0; $pass = 0;
function chk(string $label, bool $cond): void
{
    global $fail, $pass;
    if ($cond) { $pass++; echo "  ok    $label\n"; return; }
    $fail++; echo "  FAIL  $label\n";
}

/* Comment-stripped source, so prose can never satisfy a check. */
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            $out .= ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) ? "\n" : $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$api = stripComments((string)file_get_contents($web . '/api.php'));
$start = strpos($api, "case 'user_setlists_sync':");
chk('the user_setlists_sync handler was found', $start !== false);
$next = strpos($api, "\n        case '", $start + 10);
$body = substr($api, $start, ($next !== false ? $next : strlen($api)) - $start);

echo "A. the handler's decision, from source\n";
chk('expiry is "stated" by array_key_exists(\'expiresAt\'), never isset()',
    (bool)preg_match('/\$expiryStated\s*=\s*\$expiryReady\s*&&\s*array_key_exists\(\s*\'expiresAt\'\s*,\s*\$list\s*\)/', $body));
chk('the unchanged-row compare is told about "stated", not just "column exists"',
    (bool)preg_match('/userSyncSetlistRowUnchanged\(\s*\$srv,\s*\$name,\s*\$songsJson,\s*\$expiryStated\b/', $body));

/* Pull each statement's SQL out of the real handler. */
function grab(string $body, string $var): array
{
    preg_match_all('/\$' . $var . ' = \$db->prepare\(\s*\'([^\']*)\'\s*\)/', $body, $m);
    return $m[1];
}
$plain    = grab($body, 'upsert');
$planned  = grab($body, 'upsertPlan');
$keep     = grab($body, 'upsertKeepExpiry');
$planKeep = grab($body, 'upsertPlanKeepExpiry');
$sqlExp       = current(array_filter($plain, fn($s) => str_contains($s, 'ExpiresAt')));
$sqlPlanExp   = current(array_filter($planned, fn($s) => str_contains($s, 'ExpiresAt')));
chk('all four expiry-aware statements were found', $sqlExp && $sqlPlanExp && count($keep) === 1 && count($planKeep) === 1);
$sqlKeep     = $keep[0] ?? '';
$sqlPlanKeep = $planKeep[0] ?? '';
chk('the keep-expiry twins never name ExpiresAt (so they cannot touch it)',
    $sqlKeep !== '' && $sqlPlanKeep !== ''
    && !str_contains($sqlKeep, 'ExpiresAt') && !str_contains($sqlPlanKeep, 'ExpiresAt'));
chk('the plan twin still writes SlotsJson', str_contains($sqlPlanKeep, 'SlotsJson = VALUES(SlotsJson)'));
chk('no COALESCE anywhere in the expiry handling (it would make an expiry impossible to clear)',
    !str_contains($sqlKeep . $sqlPlanKeep . $sqlExp . $sqlPlanExp, 'COALESCE'));

echo "A2. unchanged-row compare treats an absent key as no opinion\n";
$srv = ['Name' => 'N', 'SongsJson' => '[]', 'ExpiresAt' => '2030-01-01 00:00:00'];
chk('stored expiry + client silent about it = unchanged (no false conflict)',
    userSyncSetlistRowUnchanged($srv, 'N', '[]', false, null, false, null) === true);
chk('stored expiry + client says null (stated) = changed (a clear is a real edit)',
    userSyncSetlistRowUnchanged($srv, 'N', '[]', true, null, false, null) === false);
chk('stored expiry + same value stated = unchanged',
    userSyncSetlistRowUnchanged($srv, 'N', '[]', true, '2030-01-01 00:00:00', false, null) === true);

echo "B. a real database\n";
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
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'ihymns_t2207_expiry';
$db->query("DROP DATABASE IF EXISTS `$name`");
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
try {
    /* The real table shape, taken from schema.sql; only the link to tblUsers is
       dropped so the test needs no users table. */
    $schema = (string)file_get_contents($repoRoot . '/appWeb/.sql/schema.sql');
    preg_match('/CREATE TABLE IF NOT EXISTS tblUserSetlists \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $m);
    chk('tblUserSetlists DDL found in schema.sql', !empty($m[0]));
    $ddl = preg_replace('/,\s*CONSTRAINT fk_Setlists_User.*?ON UPDATE CASCADE/s', '', $m[0]);
    $db->query($ddl);

    $plan = '{"templateId":1,"templateName":"T","slots":[]}';
    $now  = '2026-11-24 10:00:00';
    $uid  = 7;
    /* Run the SAME choice the handler makes, using the lifted statements. */
    $write = function (string $id, string $nm, array $list) use ($db, $uid, $now, $sqlExp, $sqlPlanExp, $sqlKeep, $sqlPlanKeep, $plan): void {
        $stated = array_key_exists('expiresAt', $list);
        $planSt = array_key_exists('plan', $list);
        $exp    = userSyncParseTimestamp($list['expiresAt'] ?? null);
        $songs  = '[]';
        if ($planSt) {
            $st = $db->prepare($stated ? $sqlPlanExp : $sqlPlanKeep);
            if ($stated) { $st->bind_param('isssssss', $uid, $id, $nm, $songs, $now, $now, $exp, $plan); }
            else         { $st->bind_param('issssss',  $uid, $id, $nm, $songs, $now, $now, $plan); }
        } else {
            $st = $db->prepare($stated ? $sqlExp : $sqlKeep);
            if ($stated) { $st->bind_param('issssss', $uid, $id, $nm, $songs, $now, $now, $exp); }
            else         { $st->bind_param('isssss',  $uid, $id, $nm, $songs, $now, $now); }
        }
        $st->execute(); $st->close();
    };
    $row = function (string $id) use ($db, $uid): array {
        $r = $db->query("SELECT Name, ExpiresAt, SlotsJson FROM tblUserSetlists WHERE UserId = $uid AND SetlistId = '$id'");
        return $r->fetch_assoc() ?: [];
    };

    $write('a', 'one', ['expiresAt' => '2030-05-06 07:08:09']);
    chk('a stated value sets the expiry', ($row('a')['ExpiresAt'] ?? null) === '2030-05-06 07:08:09');
    $write('a', 'renamed', []);
    $r = $row('a');
    chk('absent key (update) keeps the stored expiry', ($r['ExpiresAt'] ?? null) === '2030-05-06 07:08:09');
    chk('...while the name still updates', ($r['Name'] ?? '') === 'renamed');
    $write('a', 'renamed', ['expiresAt' => null]);
    chk('key present as null clears the expiry', array_key_exists('ExpiresAt', $row('a')) && $row('a')['ExpiresAt'] === null);
    $write('a', 'renamed', ['expiresAt' => '2031-01-02 03:04:05']);
    chk('a value sets it again', ($row('a')['ExpiresAt'] ?? null) === '2031-01-02 03:04:05');

    $write('b', 'fresh', []);
    chk('a brand-new row with no key gets NULL', array_key_exists('ExpiresAt', $row('b')) && $row('b')['ExpiresAt'] === null);

    $write('c', 'planned', ['expiresAt' => '2032-02-02 02:02:02', 'plan' => []]);
    chk('plan + stated expiry: both stored',
        ($row('c')['ExpiresAt'] ?? null) === '2032-02-02 02:02:02' && ($row('c')['SlotsJson'] ?? null) !== null);
    $write('c', 'planned2', ['plan' => []]);
    $r = $row('c');
    chk('plan variant, absent expiry key: stored expiry kept', ($r['ExpiresAt'] ?? null) === '2032-02-02 02:02:02');
    chk('plan variant, absent expiry key: plan still written', ($r['SlotsJson'] ?? null) !== null);
    $write('c', 'planned2', ['plan' => [], 'expiresAt' => null]);
    chk('plan variant, key present as null: expiry cleared', array_key_exists('ExpiresAt', $row('c')) && $row('c')['ExpiresAt'] === null);
    $write('d', 'newplanned', ['plan' => []]);
    chk('plan variant, new row, no expiry key: NULL', array_key_exists('ExpiresAt', $row('d')) && $row('d')['ExpiresAt'] === null);
} finally {
    $db->query("DROP DATABASE IF EXISTS `$name`");
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
