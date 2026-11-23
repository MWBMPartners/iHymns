<?php

declare(strict_types=1);

/**
 * iHymns - one sign-in per device: the server rules (PR 3, phase H, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * This test builds a real copy of the database from schema.sql and checks the rules that
 * give every device its own sign-in: signing in again on the same install replaces that
 * install's old sign-in (and nobody else's), the secret "automatic sign-in key" can only be
 * swapped for a device sign-in, and every "sign everything out" really does end everything.
 *
 * Detailed (rule #34). Grows one part per step; each part is proven by a mutation in its commit.
 *  Part 1 (H1): the install id travels with every sign-in route, and one device keeps one row.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3307;user=root;pass=" php tests/php/test-device-signin-core.php
 *
 * Without IHYMNS_TEST_DSN the database parts print SKIP loudly (a gap, never a quiet pass).
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/api_tokens.php';
require_once $web . '/includes/apns.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}
function code(string $path): string { return (string)file_get_contents($path); }

echo "part 1 (H1): the install id travels with every sign-in route\n";
$api  = code($web . '/api.php');
$auth = code($web . '/manage/includes/auth.php');
chk('the body reader returns the install id (and drops a malformed one)',
    apiTokenDeviceMetaFromBody(['deviceInstallId' => 'abcdef12-3456'])['deviceInstallId'] === 'abcdef12-3456'
    && apiTokenDeviceMetaFromBody(['deviceInstallId' => 'short'])['deviceInstallId'] === null
    && apiTokenDeviceMetaFromBody(['deviceInstallId' => str_repeat('a', 65)])['deviceInstallId'] === null
    && apiTokenDeviceMetaFromBody(['deviceInstallId' => ['x']])['deviceInstallId'] === null
    && apiTokenDeviceMetaFromBody('nonsense')['deviceInstallId'] === null);
chk('the emailed-link route passes the install id through completeEmailLogin into the mint',
    preg_match('/completeEmailLogin\(\$verified\[.email.\][^;]*\$deviceMeta\[.deviceInstallId.\]\)/', $api) === 1
    && strpos($auth, '_completeEmailLoginTxn($db, $email, $userId, $installId)') !== false
    && strpos($auth, "'email', installId: \$installId") !== false);
chk('the TV pairing route passes the install id (and only that) to the mint',
    preg_match("/apiTokenMint\(\\\$db, \(int\)\\\$userId, null, 'device_code', \\\$expiresAtTs, apiTokenCleanInstallId\(/", $api) === 1);

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
$name = 'ihymns_t3a_device_signin';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
$sql = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
$db->multi_query($sql);
do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
$db->query("INSERT INTO tblUsers (Id, Username, Email, PasswordHash, DisplayName) VALUES
    (1, 'ann', 'ann@example.test', 'x', 'Ann'), (2, 'bob', 'bob@example.test', 'x', 'Bob')");

function hashes(\mysqli $db, int $uid): array
{
    $r = $db->query("SELECT Token FROM tblApiTokens WHERE UserId = $uid ORDER BY Token");
    return array_map(fn($x) => $x[0], $r->fetch_all(MYSQLI_NUM));
}
function pushHash(\mysqli $db, string $tok): ?string
{
    $r = $db->query("SELECT ApiTokenHash FROM tblApnsTokens WHERE PushToken = '$tok'");
    return $r->fetch_row()[0] ?? null;
}

chk('the foundation columns exist in this database', apiTokensFoundationColumnsExist($db) && apnsTokenHashReady($db));

/* The older, shared sign-in: no install id (the legacy app token). */
$legacy = apiTokenMint($db, 1, null, 'password');
$legacyHash = hash('sha256', $legacy);
/* Another person who happens to hold the SAME install id. */
$bobTok = apiTokenMint($db, 2, ['deviceInstallId' => 'install-AAAA-1111'], 'password');
$bobHash = hash('sha256', $bobTok);
/* Ann's two installs. */
$a1 = apiTokenMint($db, 1, ['deviceInstallId' => 'install-AAAA-1111'], 'password');
$a1h = hash('sha256', $a1);
$b1 = apiTokenMint($db, 1, ['deviceInstallId' => 'install-BBBB-2222'], 'password');
$b1h = hash('sha256', $b1);
$db->query("INSERT INTO tblApnsTokens (UserId, PushToken, ApiTokenHash) VALUES (1, 'push-a1', '$a1h'), (1, 'push-b1', '$b1h'), (2, 'push-bob', '$bobHash')");

chk('before the replacement every sign-in is present', count(hashes($db, 1)) === 3 && count(hashes($db, 2)) === 1);

/* Ann signs in again on install AAAA through the explicit argument (the emailed-link route's shape). */
$a2 = apiTokenMint($db, 1, null, 'email', installId: 'install-AAAA-1111');
$a2h = hash('sha256', $a2);
$mine = hashes($db, 1);
chk('the same install\'s older sign-in is replaced by the new one', !in_array($a1h, $mine, true) && in_array($a2h, $mine, true));
chk('a different install of the same person is kept', in_array($b1h, $mine, true));
chk('the shared legacy sign-in (no install id) is kept', in_array($legacyHash, $mine, true));
chk('another person holding the same install id is untouched', hashes($db, 2) === [$bobHash]);
chk('the replaced sign-in\'s push registration moved to the new sign-in', pushHash($db, 'push-a1') === $a2h, (string)pushHash($db, 'push-a1'));
chk('other push registrations did not move', pushHash($db, 'push-b1') === $b1h && pushHash($db, 'push-bob') === $bobHash);

/* The install id inside the details array works the same way as the explicit argument. */
$a3 = apiTokenMint($db, 1, ['deviceInstallId' => 'install-AAAA-1111', 'platform' => 'apple'], 'password');
chk('an install id inside the device details also replaces the old row', !in_array($a2h, hashes($db, 1), true) && in_array(hash('sha256', $a3), hashes($db, 1), true));
/* A malformed install id is dropped, never a failed sign-in and never a replacement. */
$before = count(hashes($db, 1));
apiTokenMint($db, 1, ['deviceInstallId' => 'bad id!'], 'password');
chk('a malformed install id is ignored (one more row, nothing deleted)', count(hashes($db, 1)) === $before + 1);

$db->query("DROP DATABASE IF EXISTS `{$name}`");
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
