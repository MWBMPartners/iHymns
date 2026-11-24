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
 *  Part 3 (H5): the five actions, every row of the status table, the shared sign-in survives a swap,
 *          the owner's rule (a signed-out or run-out install cannot sign itself back in), api.php wiring.
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
require_once $web . '/includes/device_signin.php';
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


/* ====================================================================== part 3 (H5) */
echo "part 3 (H5): the actions\n";
$db->query('DELETE FROM tblApiTokens'); $db->query('DELETE FROM tblAccountEnrolKeys'); $db->query('DELETE FROM tblApnsTokens');
$u1 = ['Id' => 1]; $u2 = ['Id' => 2];
$live = fn(int $u) => (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE UserId = $u AND ExpiresAt > UTC_TIMESTAMP()")->fetch_row()[0];
$rowOf = fn(string $raw) => $db->query("SELECT * FROM tblApiTokens WHERE Token = '" . hash('sha256', $raw) . "'")->fetch_assoc();
$dev = fn(string $install, array $extra = []) => array_merge(['deviceInstallId' => $install, 'platform' => 'apple', 'deviceName' => 'iPhone', 'appVersion' => '1.2.3'], $extra);

/* --- enrol --- */
$shared = apiTokenMint($db, 1, null, 'password');                     /* the shared older-app sign-in: no install id, no platform */
$sharedHash = hash('sha256', $shared);
$e1 = deviceSigninEnrol($db, $u1, $sharedHash, $dev('install-ENROL-0001'));
chk('enrol 200: a new sign-in, a device id, and (by default) an automatic sign-in key', $e1['status'] === 200 && preg_match('/^[a-f0-9]{64}$/', (string)($e1['body']['token'] ?? '')) === 1 && strlen((string)($e1['body']['device']['id'] ?? '')) === 16 && accountEnrolKeyShapeOk($e1['body']['enrolKey'] ?? null) && isset($e1['body']['enrolKeyExpiresAt']), json_encode($e1['body']));
$r1 = $rowOf($e1['body']['token']);
chk('the new sign-in is tied to the install, how-made is "enrol", and carries the platform and name', $r1['DeviceInstallId'] === 'install-ENROL-0001' && $r1['IssuedVia'] === 'enrol' && $r1['Platform'] === 'apple' && $r1['DeviceName'] === 'iPhone');
chk('the shared sign-in STILL works after the swap (older app copies keep working)', $rowOf($shared) !== null && strtotime($rowOf($shared)['ExpiresAt'] . ' UTC') > time() && $live(1) === 2);
chk('the key is stored only as a hash', (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE KeyHash = \'' . hash('sha256', $e1['body']['enrolKey']) . '\'')->fetch_row()[0] === 1);
$eNoKey = deviceSigninEnrol($db, $u1, $sharedHash, $dev('install-ENROL-0002') + ['createEnrolKey' => false]);
chk('createEnrolKey:false gives a sign-in and no key', $eNoKey['status'] === 200 && !isset($eNoKey['body']['enrolKey']));
chk('400 invalid_install_id (missing, short, wrong type)', deviceSigninEnrol($db, $u1, $sharedHash, ['platform' => 'apple'])['body']['reason'] === 'invalid_install_id' && deviceSigninEnrol($db, $u1, $sharedHash, $dev('short'))['status'] === 400 && deviceSigninEnrol($db, $u1, $sharedHash, $dev('x') + ['deviceInstallId' => 123456789])['status'] === 400);
chk('400 invalid_platform (missing, web, nonsense)', deviceSigninEnrol($db, $u1, $sharedHash, ['deviceInstallId' => 'install-ENROL-0003'])['body']['reason'] === 'invalid_platform' && deviceSigninEnrol($db, $u1, $sharedHash, $dev('install-ENROL-0003', ['platform' => 'web']))['body']['reason'] === 'invalid_platform' && deviceSigninEnrol($db, $u1, $sharedHash, $dev('install-ENROL-0003', ['platform' => 'toaster']))['status'] === 400);
$ownHash = hash('sha256', $e1['body']['token']);
$again = deviceSigninEnrol($db, $u1, $ownHash, $dev('install-ENROL-0001'));
chk('409 already_enrolled: a device that already has its own sign-in asks again', $again['status'] === 409 && $again['body']['reason'] === 'already_enrolled');
$mis = deviceSigninEnrol($db, $u1, $ownHash, $dev('install-OTHER-0009'));
chk('403 install_mismatch: a token for one install may not make a token for another', $mis['status'] === 403 && $mis['body']['reason'] === 'install_mismatch');
$webTok = apiTokenMint($db, 1, ['platform' => 'web', 'deviceName' => 'Chrome'], 'password');
$xs  = apiTokenMint($db, 1, null, 'cross_surface');
chk('403 not_a_shared_app_sign_in: a browser sign-in and the admin cross-surface sign-in cannot be swapped',
    deviceSigninEnrol($db, $u1, hash('sha256', $webTok), $dev('install-ENROL-0004'))['body']['reason'] === 'not_a_shared_app_sign_in'
    && deviceSigninEnrol($db, $u1, hash('sha256', $xs), $dev('install-ENROL-0004'))['status'] === 403);
$liveBefore = $live(1);
chk('401 when the caller\'s sign-in is not live', deviceSigninEnrol($db, $u1, hash('sha256', 'not-a-live-token'), $dev('install-ENROL-0005'))['status'] === 401);
chk('a refused enrol makes no sign-in', $live(1) === $liveBefore);

/* the owner's rule: a signed-out install may not be enrolled again */
apiTokenSignOutHashes($db, 1, [$ownHash]);
$so = deviceSigninEnrol($db, $u1, $sharedHash, $dev('install-ENROL-0001'));
chk('403 install_signed_out: an install that was signed out cannot be enrolled again from the shared sign-in', $so['status'] === 403 && $so['body']['reason'] === 'install_signed_out');

/* --- key create --- */
$d1 = apiTokenMint($db, 1, $dev('install-KEYS-0001'), 'password');
$kc = deviceSigninKeyCreate($db, $u1, hash('sha256', $d1), []);
chk('key create 200 from a device\'s own sign-in: the key (once) and its end date', $kc['status'] === 200 && accountEnrolKeyShapeOk($kc['body']['enrolKey'] ?? null) && isset($kc['body']['expiresAt']));
$kcShared = deviceSigninKeyCreate($db, $u1, $sharedHash, []);
chk('403 device_token_required: the shared sign-in cannot make a key', $kcShared['status'] === 403 && $kcShared['body']['reason'] === 'device_token_required');
$dAnd = apiTokenMint($db, 1, $dev('install-KEYS-0002', ['platform' => 'android']), 'password');
$kcAnd = deviceSigninKeyCreate($db, $u1, hash('sha256', $dAnd), []);
chk('403 enrol_key_platform_unsupported: only Apple holds keys today', $kcAnd['status'] === 403 && $kcAnd['body']['reason'] === 'enrol_key_platform_unsupported');
chk('401 for a dead sign-in', deviceSigninKeyCreate($db, $u1, hash('sha256', 'nope'), [])['status'] === 401);

/* --- redeem --- */
$key = $kc['body']['enrolKey'];
$expBefore = $db->query('SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = \'' . hash('sha256', $key) . '\'')->fetch_row()[0];
$rd = deviceSigninRedeem($db, $dev('install-REDEEM-001') + ['enrolKey' => $key]);
chk('redeem 200: a new sign-in for the person the key belongs to, and NO new key', $rd['status'] === 200 && $rd['userId'] === 1 && preg_match('/^[a-f0-9]{64}$/', (string)$rd['body']['token']) === 1 && !isset($rd['body']['enrolKey']), json_encode($rd['body']));
$rr = $rowOf($rd['body']['token']);
chk('the redeemed sign-in is tied to the install and marked "enrol"', $rr['DeviceInstallId'] === 'install-REDEEM-001' && $rr['IssuedVia'] === 'enrol' && $rr['UserId'] == 1);
$rd2 = deviceSigninRedeem($db, $dev('install-REDEEM-001') + ['enrolKey' => $key]);
chk('redeeming again on the SAME install replaces its sign-in (one row for the install)', $rd2['status'] === 200 && (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE UserId = 1 AND DeviceInstallId = 'install-REDEEM-001' AND ExpiresAt > UTC_TIMESTAMP()")->fetch_row()[0] === 1 && $rowOf($rd['body']['token']) === null);
chk('a key redeemed for person 1 never gives a sign-in to person 2', (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE UserId = 2")->fetch_row()[0] === 0);
chk('400 malformed_enrol_key (wrong shape, a plain bearer token, empty)', deviceSigninRedeem($db, $dev('install-REDEEM-002') + ['enrolKey' => 'ihek1_short'])['body']['reason'] === 'malformed_enrol_key' && deviceSigninRedeem($db, $dev('install-REDEEM-002') + ['enrolKey' => $shared])['status'] === 400 && deviceSigninRedeem($db, $dev('install-REDEEM-002'))['status'] === 400);
chk('400 invalid_install_id and invalid_platform on redeem', deviceSigninRedeem($db, ['enrolKey' => $key, 'platform' => 'apple'])['body']['reason'] === 'invalid_install_id' && deviceSigninRedeem($db, ['enrolKey' => $key, 'deviceInstallId' => 'install-REDEEM-002'])['body']['reason'] === 'invalid_platform' && deviceSigninRedeem($db, $dev('install-REDEEM-002', ['platform' => 'web']) + ['enrolKey' => $key])['status'] === 400);
$one = 'enrol_key_invalid';
$bad = [];
$bad['unknown']   = deviceSigninRedeem($db, $dev('install-REDEEM-003') + ['enrolKey' => 'ihek1_' . str_repeat('1', 64)]);
$bad['platform']  = deviceSigninRedeem($db, $dev('install-REDEEM-003', ['platform' => 'android']) + ['enrolKey' => $key]);
$hk = hash('sha256', $key);
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE KeyHash = '$hk'");
$bad['expired']   = deviceSigninRedeem($db, $dev('install-REDEEM-003') + ['enrolKey' => $key]);
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 100 DAY), RevokedAt = UTC_TIMESTAMP() WHERE KeyHash = '$hk'");
$bad['cancelled'] = deviceSigninRedeem($db, $dev('install-REDEEM-003') + ['enrolKey' => $key]);
$db->query("UPDATE tblAccountEnrolKeys SET RevokedAt = NULL WHERE KeyHash = '$hk'");
$db->query('UPDATE tblUsers SET IsActive = 0 WHERE Id = 1');
$bad['inactive']  = deviceSigninRedeem($db, $dev('install-REDEEM-003') + ['enrolKey' => $key]);
$db->query('UPDATE tblUsers SET IsActive = 1 WHERE Id = 1');
$allSame = true; foreach ($bad as $n => $b) { if ($b['status'] !== 401 || $b['body'] !== $bad['unknown']['body'] || ($b['body']['reason'] ?? '') !== $one) { $allSame = false; echo "      ($n answered " . json_encode($b) . ")\n"; } }
chk('401 enrol_key_invalid is ONE answer for unknown, wrong platform, expired, cancelled and switched-off account (identical bodies)', $allSame && count($bad) === 5);
chk('no refusal made a sign-in for the install it asked about', (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE DeviceInstallId = 'install-REDEEM-003'")->fetch_row()[0] === 0);

/* the owner's rule on redeem */
$key2 = deviceSigninKeyCreate($db, $u1, hash('sha256', $d1), [])['body']['enrolKey'];
$hk2 = hash('sha256', $key2);
$rdA = deviceSigninRedeem($db, $dev('install-DEAD-0001') + ['enrolKey' => $key2]);
chk('a fresh install redeems fine', $rdA['status'] === 200);
$exp1 = $db->query("SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = '$hk2'")->fetch_row()[0];
apiTokenSignOutHashes($db, 1, [hash('sha256', $rdA['body']['token'])]);
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) WHERE KeyHash = '$hk2'");
$expMid = $db->query("SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = '$hk2'")->fetch_row()[0];
$deadTry = deviceSigninRedeem($db, $dev('install-DEAD-0001') + ['enrolKey' => $key2]);
chk('an install that was SIGNED OUT cannot sign itself back in with the key: the same one 401 enrol_key_invalid', $deadTry['status'] === 401 && $deadTry['body'] === $bad['unknown']['body']);
chk('...and the refusal did not extend the key', $db->query("SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = '$hk2'")->fetch_row()[0] === $expMid);
/* 30 days unused: the row simply ran out (the ended stamp is what the sweep leaves) */
$ranOut = apiTokenMint($db, 1, $dev('install-DEAD-0002'), 'password');
$db->query("UPDATE tblApiTokens SET ExpiresAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE Token = '" . hash('sha256', $ranOut) . "'");
chk('an install whose sign-in RAN OUT (30 days unused) is refused the same way', deviceSigninRedeem($db, $dev('install-DEAD-0002') + ['enrolKey' => $key2])['status'] === 401);
chk('a different install, and a different person\'s ended install, are not blocked by it', deviceSigninRedeem($db, $dev('install-DEAD-0003') + ['enrolKey' => $key2])['status'] === 200);
$db->query("UPDATE tblApiTokens SET UserId = 2 WHERE Token = '" . hash('sha256', $ranOut) . "'");
chk('...(person 2\'s ended row for that install does not stop person 1)', deviceSigninRedeem($db, $dev('install-DEAD-0002') + ['enrolKey' => $key2])['status'] === 200);
/* a manual sign-in clears the block */
$dead4 = apiTokenMint($db, 1, $dev('install-DEAD-0004'), 'password');
apiTokenSignOutHashes($db, 1, [hash('sha256', $dead4)]);
$blocked = deviceSigninRedeem($db, $dev('install-DEAD-0004') + ['enrolKey' => $key2])['status'] === 401;
apiTokenMint($db, 1, $dev('install-DEAD-0004'), 'password');   /* the person signs in by hand on that install */
chk('a manual sign-in on a signed-out install clears the block (it replaced the marker)', $blocked && !apiTokenInstallSignInEnded($db, 1, 'install-DEAD-0004') && deviceSigninRedeem($db, $dev('install-DEAD-0004') + ['enrolKey' => $key2])['status'] === 200);

/* rate limit hooks */
$expPre = $db->query("SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = '$hk2'")->fetch_row()[0];
$denied = deviceSigninRedeem($db, $dev('install-RATE-00001') + ['enrolKey' => $key2], ['allowPerson' => fn(int $u) => false]);
chk('429 when the per-person limit says no, with no sign-in made and the key not extended', $denied['status'] === 429 && (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE DeviceInstallId = 'install-RATE-00001'")->fetch_row()[0] === 0 && $db->query("SELECT ExpiresAt FROM tblAccountEnrolKeys WHERE KeyHash = '$hk2'")->fetch_row()[0] === $expPre);
$recorded = [];
deviceSigninRedeem($db, $dev('install-RATE-00002') + ['enrolKey' => $key2], ['allowPerson' => fn(int $u) => true, 'recordPerson' => function (int $u) use (&$recorded) { $recorded[] = $u; }]);
chk('the per-person hit is recorded only for a sign-in actually made, for the right person', $recorded === [1]);

/* a password reset kills the key (the one door) */
$key3 = deviceSigninKeyCreate($db, $u1, hash('sha256', $d1), [])['body']['enrolKey'];
chk('before the reset the key works', deviceSigninRedeem($db, $dev('install-RESET-0001') + ['enrolKey' => $key3])['status'] === 200);
apiTokenRevokeAllForUser($db, 1, null, 'password_reset');
chk('after a password reset (all sign-ins ended) the key stops working', deviceSigninRedeem($db, $dev('install-RESET-0002') + ['enrolKey' => $key3])['status'] === 401 && deviceSigninRedeem($db, $dev('install-RESET-0002') + ['enrolKey' => $key2])['status'] === 401);
chk('the reset left no live sign-in for her', $live(1) === 0);

/* revoke keys, sign out everywhere */
$kk = deviceSigninKeyCreate($db, $u2, hash('sha256', apiTokenMint($db, 2, $dev('install-TWO-00001'), 'password')), []);
$rv = deviceSigninKeysRevoke($db, 2);
chk('"turn off automatic sign-in" cancels the person\'s keys and says how many', $rv['status'] === 200 && $rv['body'] === ['revoked' => 1] && deviceSigninRedeem($db, $dev('install-TWO-00002') + ['enrolKey' => $kk['body']['enrolKey']])['status'] === 401);
$so1 = hash('sha256', apiTokenMint($db, 2, $dev('install-TWO-00003'), 'password'));
$so2 = hash('sha256', apiTokenMint($db, 2, null, 'password'));
accountEnrolKeyMint($db, 2, 'apple');
$se = deviceSigninSignOutEverywhere($db, 2, $so1, true);
chk('sign out everywhere keeping the current one: others ended, keys cancelled, the caller still live', $se['status'] === 200 && $se['body']['keptCurrent'] === true && $se['body']['enrolKeysRevoked'] === 1 && $se['body']['tokensRevoked'] >= 2 && (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE Token = '$so1' AND ExpiresAt > UTC_TIMESTAMP()")->fetch_row()[0] === 1 && $live(2) === 1, json_encode($se['body']));
$se2 = deviceSigninSignOutEverywhere($db, 2, $so1, false);
chk('sign out everywhere keeping none ends the caller too', $se2['body']['keptCurrent'] === false && $live(2) === 0);

/* not ready (a database without the foundation objects) */
$db2name = 'ihymns_t3a_empty';
$db->query("DROP DATABASE IF EXISTS `{$db2name}`");
$db->query("CREATE DATABASE `{$db2name}`");
$empty = new mysqli($hh, $us, $pw, $db2name, $pt);
chk('409 device_signin_not_ready on a database without the foundation objects (enrol, key create, redeem)',
    deviceSigninEnrol($empty, $u1, $sharedHash, $dev('install-NOTREADY-1'))['body']['reason'] === 'device_signin_not_ready'
    && deviceSigninKeyCreate($empty, $u1, $sharedHash, [])['status'] === 409
    && deviceSigninRedeem($empty, $dev('install-NOTREADY-1') + ['enrolKey' => $key])['status'] === 409);
$empty->close();
$db->query("DROP DATABASE IF EXISTS `{$db2name}`");

/* never the cookie: the header-only reader */
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$_COOKIE['ihymns_auth'] = str_repeat('a', 64);
chk('apiTokenBearerHeaderValue() ignores the browser cookie', apiTokenBearerHeaderValue() === null);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('b', 64);
chk('...reads a well-formed header, and refuses a key or junk', apiTokenBearerHeaderValue() === str_repeat('b', 64));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ihek1_' . str_repeat('b', 64);
chk('...a key in the header is not a bearer token', apiTokenBearerHeaderValue() === null);
unset($_SERVER['HTTP_AUTHORIZATION'], $_COOKIE['ihymns_auth']);

/* api.php wiring, derived from the tree */
$apiUnits2 = phpSourceUnits(code($web . '/api.php'));
$cases = ['auth_device_enrol', 'auth_enrol_key_create', 'auth_enrol_key_redeem', 'auth_enrol_keys_revoke', 'auth_signout_everywhere'];
$wire = [];
foreach ($cases as $c) { $wire[$c] = $apiUnits2["case '$c'"] ?? null; }
chk('all five cases exist in api.php', count(array_filter($wire)) === 5);
$wiring = [];
foreach ($wire as $c => $u) {
    if ($u === null) { continue; }
    $codeV = $u['code']; $strV = implode(' ', $u['strings']);
    if (!str_contains($codeV, 'validateCsrfRequest(null)')) { $wiring[] = "$c: no validateCsrfRequest(null)"; }
    if (!str_contains($codeV, "'POST'")) { $wiring[] = "$c: no POST check"; }
    if (str_contains($codeV, 'setAuthTokenCookie(')) { $wiring[] = "$c: sets the cookie"; }
    if (!str_contains($codeV, 'checkRateLimit(') || !str_contains($codeV, 'recordRateLimitHit(')) { $wiring[] = "$c: rate limit not paired"; }
    if (!str_contains($codeV, 'logActivity(')) { $wiring[] = "$c: no log line"; }
}
chk('every action checks POST, the same-origin header, has a paired rate limit, logs, and never sets the sign-in cookie', $wiring === [], implode('; ', $wiring));
foreach (['auth_device_enrol', 'auth_enrol_key_create'] as $c) {
    $cv = $wire[$c]['code'] ?? '';
    $pH = strpos($cv, 'apiTokenBearerHeaderValue('); $pA = strpos($cv, 'getAuthenticatedUser(');
    chk("$c requires the Authorization HEADER (answers 400 before it even looks at a cookie sign-in)", $pH !== false && $pA !== false && $pH < $pA && str_contains(implode(' ', $wire[$c]['strings']), 'bearer_required'));
}
chk('auth_enrol_key_redeem asks for no sign-in', !str_contains($wire['auth_enrol_key_redeem']['code'] ?? '', 'getAuthenticatedUser('));
chk('enrol, key create and redeem ask the per-site switch and the readiness check; revoke keys and sign out everywhere never do (signing out is never switched off)',
    str_contains($wire['auth_device_enrol']['code'], 'deviceSigninEnabledHere()') && str_contains($wire['auth_device_enrol']['code'], 'deviceSigninActiveHere(')
    && str_contains($wire['auth_enrol_key_create']['code'], 'deviceSigninEnabledHere()') && str_contains($wire['auth_enrol_key_redeem']['code'], 'deviceSigninEnabledHere()')
    && !str_contains($wire['auth_enrol_keys_revoke']['code'], 'deviceSigninEnabledHere') && !str_contains($wire['auth_signout_everywhere']['code'], 'deviceSigninEnabledHere'));
$leak = [];
foreach ($wire as $c => $u) {
    if ($u === null) { continue; }
    if (preg_match_all('/logActivity\(([^;]*);/s', $u['code'], $mm)) {
        foreach ($mm[1] as $args) {
            $a = str_replace("isset(\$out['enrolKey'])", '', $args);
            if (preg_match('/\$dsHeader|\$token\b|\[.token.\]|\[.enrolKey.\]|\$key\b/', $a)) { $leak[] = $c; }
        }
    }
}
chk('no log line carries a token or a key (only a short device tag, a count or a reason word)', $leak === [], implode(', ', $leak));

/* ---- the housekeeping sweep keeps a device's "signed out" note (owner's rule needs it) ---- */
echo "part 3b (H5/H7): the sweep\n";
$db->query("DELETE FROM tblApiTokens");
$db->query("DELETE FROM tblAccountEnrolKeys");
$ago = fn(int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);
$sh = fn(string $t) => hash('sha256', $t);
foreach ([
    ['sw-shared-old',   null,                 $ago(1)],     /* shared sign-in, expired yesterday: goes */
    ['sw-shared-live',  null,                 gmdate('Y-m-d H:i:s', time() + 86400)],
    ['sw-dev-recent',   'install-SWEEP-0001', $ago(40)],    /* device sign-in ended 40 days ago: kept (the note) */
    ['sw-dev-ancient',  'install-SWEEP-0002', $ago(200)],   /* ended 200 days ago: goes */
] as [$t, $inst, $exp]) {
    $h = $sh($t);
    $st = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt, DeviceInstallId) VALUES (?, 1, ?, ?)');
    $st->bind_param('sss', $h, $exp, $inst);
    $st->execute();
}
$left = array_column($db->query("SELECT Token FROM tblApiTokens")->fetch_all(MYSQLI_ASSOC), 'Token');
$n = apiTokenSweepExpired($db);
$after = array_column($db->query("SELECT Token FROM tblApiTokens")->fetch_all(MYSQLI_ASSOC), 'Token');
chk('the sweep deletes an expired shared sign-in and a device note older than the keep window, and reports 2', $n === 2 && !in_array($sh('sw-shared-old'), $after, true) && !in_array($sh('sw-dev-ancient'), $after, true));
chk('...but keeps a live sign-in and a recent device note', in_array($sh('sw-shared-live'), $after, true) && in_array($sh('sw-dev-recent'), $after, true));
chk('...so an install that ran out 40 days ago still cannot redeem its key', apiTokenInstallSignInEnded($db, 1, 'install-SWEEP-0001'));
$k = accountEnrolKeyMint($db, 1, 'apple');   /* live */
$k2 = accountEnrolKeyMint($db, 1, 'apple');
$db->query("UPDATE tblAccountEnrolKeys SET ExpiresAt = '" . $ago(31) . "' WHERE KeyHash = '" . accountEnrolKeyHash($k['key']) . "'");
$k3 = accountEnrolKeyMint($db, 1, 'apple');
$db->query("UPDATE tblAccountEnrolKeys SET RevokedAt = '" . $ago(31) . "' WHERE KeyHash = '" . accountEnrolKeyHash($k3['key']) . "'");
$k4 = accountEnrolKeyMint($db, 1, 'apple');
$db->query("UPDATE tblAccountEnrolKeys SET RevokedAt = '" . $ago(2) . "' WHERE KeyHash = '" . accountEnrolKeyHash($k4['key']) . "'");
chk('the key sweep deletes keys ended over 30 days ago (ran out or cancelled) and nothing else', accountEnrolKeysSweep($db) === 2
    && (int)$db->query('SELECT COUNT(*) FROM tblAccountEnrolKeys')->fetch_row()[0] === 2);
$cleanup = code($repo . '/appWeb/.sql/cleanup.php');
chk('the standalone cleanup script uses the same keep windows and the same rule (180 days for a device note, 30 for keys)',
    str_contains($cleanup, '180 * 86400') && API_TOKEN_ENDED_KEEP_DAYS === 180 && str_contains($cleanup, 'DeviceInstallId IS NOT NULL AND ExpiresAt < ?')
    && str_contains($cleanup, 'time() - 30 * 86400') && str_contains(code($web . '/includes/account_enrol_keys.php'), 'time() - 30 * 86400'));
chk('the admin sweep action in api.php goes through the shared sweep, not a bare delete', str_contains($api, 'apiTokenSweepExpired($db)') && !preg_match('/DELETE FROM tblApiTokens WHERE ExpiresAt/', $api));

$db->query("DROP DATABASE IF EXISTS `{$name}`");
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
