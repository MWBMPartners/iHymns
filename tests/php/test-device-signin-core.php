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
 *  Part 2 (H3): the automatic sign-in key core (made once, stored only as a hash, capped, refused for
 *          every unusable case, never a sign-in token).
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
require_once $web . '/includes/account_enrol_keys.php';
require_once $repo . '/tests/php/lib/php_source_units.php';

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


/* ====================================================================== part 2 (H3) */
echo "part 2 (H3): the automatic sign-in key core\n";
chk('the enrol-key table exists in this database', accountEnrolKeysReady($db));
$m1 = accountEnrolKeyMint($db, 1, 'apple');
chk('a key has the agreed shape (prefix + 64 hex)', accountEnrolKeyShapeOk($m1['key']) && str_starts_with($m1['key'], 'ihek1_') && strlen($m1['key']) === 70);
chk('a key can never pass the normal sign-in token check', preg_match('/^Bearer\s+([a-f0-9]{64})$/i', 'Bearer ' . $m1['key']) !== 1);
$stored = $db->query('SELECT KeyHash FROM tblAccountEnrolKeys')->fetch_all(MYSQLI_NUM);
$allText = json_encode($db->query('SELECT * FROM tblAccountEnrolKeys')->fetch_all(MYSQLI_ASSOC));
chk('only the sha256 is stored: the raw key is nowhere in the table', count($stored) === 1 && $stored[0][0] === hash('sha256', $m1['key']) && strpos((string)$allText, $m1['key']) === false && strpos((string)$allText, substr($m1['key'], 6)) === false);
chk('it lasts about 180 days', abs(strtotime($m1['expiresAt'] . ' UTC') - (time() + 180 * 86400)) <= 5);
chk('an unsupported platform is refused when making one', (function () use ($db) { try { accountEnrolKeyMint($db, 1, 'web'); return false; } catch (\InvalidArgumentException $e) { return true; } })());

/* Using a key */
$db->begin_transaction();
$uidFromKey = accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple');
$db->commit();
chk('a good key gives back its person', $uidFromKey === 1);
$row = $db->query('SELECT LastUsedAt, ExpiresAt FROM tblAccountEnrolKeys')->fetch_row();
chk('using it records the use and keeps it at about 180 days from now', $row[0] !== null && abs(strtotime($row[1] . ' UTC') - (time() + 180 * 86400)) <= 5);
$db->begin_transaction();
chk('a key on the wrong platform is refused', accountEnrolKeyRedeemLocked($db, $m1['key'], 'android') === null);
chk('a wrongly shaped key, an empty one and a non-string are refused', accountEnrolKeyRedeemLocked($db, 'ihek1_short', 'apple') === null && accountEnrolKeyRedeemLocked($db, '', 'apple') === null && accountEnrolKeyRedeemLocked($db, 12345, 'apple') === null && accountEnrolKeyRedeemLocked($db, substr($m1['key'], 6), 'apple') === null);
chk('an unknown key of the right shape is refused', accountEnrolKeyRedeemLocked($db, 'ihek1_' . str_repeat('0', 64), 'apple') === null);
$db->commit();
$hk = hash('sha256', $m1['key']);
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE KeyHash = '$hk'");
$db->begin_transaction();
chk('an expired key is refused', accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple') === null);
$db->commit();
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), RevokedAt = UTC_TIMESTAMP() WHERE KeyHash = '$hk'");
$db->begin_transaction();
chk('a cancelled key is refused', accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple') === null);
$db->commit();
$db->query("UPDATE tblAccountEnrolKeys SET RevokedAt = NULL WHERE KeyHash = '$hk'");
$db->query('UPDATE tblUsers SET IsActive = 0 WHERE Id = 1');
$db->begin_transaction();
chk('a key of a switched-off account is refused', accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple') === null);
$db->commit();
$db->query('UPDATE tblUsers SET IsActive = 1 WHERE Id = 1');
$db->begin_transaction();
chk('...and works again once the account is back (nothing else was changed)', accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple') === 1);
$db->commit();

/* The cap: five live keys per person and platform; the oldest go first. */
$mk = [];
$db->query("UPDATE tblAccountEnrolKeys SET CreatedAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 300 MINUTE) WHERE KeyHash = '$hk'");   /* the first key is the oldest */
for ($i = 0; $i < 6; $i++) {
    $mk[$i] = accountEnrolKeyMint($db, 1, 'apple')['key'];
    $db->query("UPDATE tblAccountEnrolKeys SET CreatedAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . (100 - $i) . " MINUTE) WHERE KeyHash = '" . hash('sha256', $mk[$i]) . "'");
}
$liveN = (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE UserId = 1 AND RevokedAt IS NULL')->fetch_row()[0];
chk('never more than 5 live keys for a person and platform', $liveN === 5, (string)$liveN);
$db->begin_transaction();
$newestKept = accountEnrolKeyRedeemLocked($db, $mk[5], 'apple');
$oldestGone = accountEnrolKeyRedeemLocked($db, $m1['key'], 'apple');
$db->commit();
chk('the newest key still works and the very first one was the one dropped', $newestKept === 1 && $oldestGone === null);
$bobKey = accountEnrolKeyMint($db, 2, 'apple');
chk('another person\'s keys are separate (no cap spill, no removal)', (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE UserId = 2')->fetch_row()[0] === 1 && (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE UserId = 1 AND RevokedAt IS NULL')->fetch_row()[0] === 5);

/* Cancelling */
chk('cancelling reports how many live keys it cancelled and keeps the rows', accountEnrolKeyRevokeAll($db, 1, 'apple') === 5 && (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE UserId = 1')->fetch_row()[0] >= 5);
$db->begin_transaction();
chk('after cancelling, none of person 1\'s keys works; person 2\'s does', accountEnrolKeyRedeemLocked($db, $mk[5], 'apple') === null && accountEnrolKeyRedeemLocked($db, $bobKey['key'], 'apple') === 2);
$db->commit();
chk('cancelling for a person with no live keys cancels nothing', accountEnrolKeyRevokeAll($db, 1) === 0);

/* The list shows dates and platform, never a key or hash. */
$sum = accountEnrolKeysSummary($db, 2);
$sumText = json_encode($sum);
chk('the summary has platform and dates only', count($sum) === 1 && array_keys($sum[0]) === ['platform', 'createdAt', 'lastUsedAt', 'expiresAt'] && strpos((string)$sumText, hash('sha256', $bobKey['key'])) === false && strpos((string)$sumText, $bobKey['key']) === false);

/* A key is never a sign-in token. */
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bobKey['key'];
chk('presented as a Bearer sign-in, a key is refused by the shared resolver', apiTokenResolveBearerUser($db) === null);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . hash('sha256', $bobKey['key']);
chk('...and so is its stored hash', apiTokenResolveBearerUser($db) === null);
unset($_SERVER['HTTP_AUTHORIZATION']);

/* The three sign-in checkers never read the key table (derived from the tree). */
$apiUnits  = phpSourceUnits(code($web . '/api.php'));
$tokUnits  = phpSourceUnits(code($web . '/includes/api_tokens.php'));
$authUnits = phpSourceUnits(code($web . '/manage/includes/auth.php'));
$named = ['getAuthBearerToken' => $apiUnits, 'getAuthenticatedUser' => $apiUnits, 'apiTokenResolveBearerUser' => $tokUnits];
foreach ($named as $fnName => $units) {
    $u = $units[$fnName] ?? null;
    chk("$fnName exists (the guard looks at something)", $u !== null);
    chk("$fnName never names the key table", $u !== null && stripos($u['code'] . ' ' . implode(' ', $u['strings']), 'tblAccountEnrolKeys') === false);
}
$readers = 0;
foreach ([$web . '/includes/api_tokens.php', $web . '/manage/includes/auth.php', $web . '/api.php'] as $f) {
    foreach (phpSourceUnits(code($f)) as $un => $u) {
        $isCheck = in_array($un, ['getAuthBearerToken', 'getAuthenticatedUser', 'apiTokenResolveBearerUser'], true);
        if ($isCheck) { $readers++; }
    }
}
chk('all three sign-in readers were found in the tree', $readers >= 3);
/* Nothing outside the key core and the revoke door names the key table in SQL (the H4 guard widens this). */
$naming = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && strpos((string)file_get_contents($f->getPathname()), 'tblAccountEnrolKeys') !== false) {
        $naming[] = substr($f->getPathname(), strlen($web) + 1);
    }
}
sort($naming);
$notCore = fn($n) => !str_starts_with($n, 'manage/setup') && !str_starts_with($n, 'manage/includes/migration') && !str_starts_with($n, 'includes/account_export') && !str_starts_with($n, 'includes/account_lifecycle');
chk('the key table is named in code only by its core (the migration list, export and erasure maps excluded), listing: ' . implode(', ', $naming),
    array_values(array_filter($naming, $notCore)) === ['includes/account_enrol_keys.php']);

$db->query("DROP DATABASE IF EXISTS `{$name}`");
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
