<?php

declare(strict_types=1);

/**
 * iHymns - one door to "end everything" for a person's sign-ins (PR 3 step H4, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * There used to be four places that deleted ALL of a person's sign-ins by hand. None of them knew
 * about the automatic sign-in keys, so a key saved in iCloud would have survived a password reset.
 * Now one function (apiTokenRevokeAllForUser) does it, and this test reads every PHP file and fails
 * if any other place does the same job, or writes to the key table at all.
 *
 * Detailed (rule #34, derived from the tree, not from a typed list of files)
 *  1. Every SQL string under appWeb/ that writes to tblApiTokens and filters by the person (`UserId`)
 *     WITHOUT naming the sign-ins it means (`Token = ?`, `Token LIKE`, `Token IN (`) is a "bulk" write.
 *     A bulk write is allowed only inside apiTokenRevokeAllForUser(). The expiry sweep
 *     (`WHERE ExpiresAt < ?`, no person) is not bulk.
 *  2. Every SQL string that writes to tblAccountEnrolKeys must sit in includes/account_enrol_keys.php.
 *  3. The four old places and the new "sign out everywhere" action are found from the tree and must
 *     each call apiTokenRevokeAllForUser().
 *  4. The finder itself is shown to catch a bulk delete and to let an explicit one through.
 *  5. A real database run of the door (IHYMNS_TEST_DSN; SKIP printed loudly without it).
 *
 * Mutation proofs are in the H4 commit: a raw delete put back in auth_change_password, and the key
 * cancellation removed from the door, each turn this red.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3307;user=root;pass=" php tests/php/test-token-revoke-one-door.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $repo . '/tests/php/lib/php_source_units.php';
require_once $web . '/includes/api_tokens.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

/** Is this SQL a write to tblApiTokens that picks the person but not particular sign-ins? */
function isBulkTokenWrite(string $q): bool
{
    if (!preg_match('/^\s*(DELETE\s+FROM|UPDATE)\s+tblApiTokens\b/i', $q)) { return false; }
    if (!preg_match('/\bUserId\b/i', $q)) { return false; }
    $explicit = preg_match('/\bToken\s*=\s*\?/i', $q) || preg_match('/\bToken\s+LIKE\b/i', $q) || preg_match('/\bToken\s+IN\s*\(/i', $q);
    return !$explicit;
}
function isKeyTableWrite(string $q): bool
{
    return (bool)preg_match('/^\s*(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+tblAccountEnrolKeys\b/i', $q);
}

echo "the finder itself\n";
chk('a delete of all of a person\'s sign-ins is bulk', isBulkTokenWrite('DELETE FROM tblApiTokens WHERE UserId = ?'));
chk('a "keep this one" delete is bulk (it names Token only to exclude)', isBulkTokenWrite('DELETE FROM tblApiTokens WHERE UserId = ? AND Token != ?') && isBulkTokenWrite('DELETE FROM tblApiTokens WHERE UserId = ? AND Token <> ?'));
chk('an update that ends all of a person\'s sign-ins is bulk', isBulkTokenWrite('UPDATE tblApiTokens SET ExpiresAt = ? WHERE UserId = ?'));
chk('a delete by one token, by a prefix or by a list is not bulk',
    !isBulkTokenWrite('DELETE FROM tblApiTokens WHERE Token = ?') && !isBulkTokenWrite('DELETE FROM tblApiTokens WHERE UserId = ? AND Token LIKE CONCAT(?, "%")')
    && !isBulkTokenWrite('DELETE FROM tblApiTokens WHERE UserId = ? AND Token IN (?,?)'));
chk('the expiry sweep (no person) is not bulk', !isBulkTokenWrite('DELETE FROM tblApiTokens WHERE ExpiresAt < ?'));
chk('a read is not a write', !isBulkTokenWrite('SELECT Token FROM tblApiTokens WHERE UserId = ?') && !isKeyTableWrite('SELECT * FROM tblAccountEnrolKeys WHERE UserId = ?'));
chk('key-table writes of every kind are seen', isKeyTableWrite('INSERT INTO tblAccountEnrolKeys (KeyHash) VALUES (?)') && isKeyTableWrite('UPDATE tblAccountEnrolKeys SET RevokedAt = ?') && isKeyTableWrite('DELETE FROM tblAccountEnrolKeys WHERE UserId = ?'));

echo "every bulk write, found from the tree\n";
$bulk = []; $keyWrites = []; $doorCallers = []; $scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/appWeb', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = $f->getPathname();
    if (!$f->isFile() || substr($path, -4) !== '.php' || str_contains($path, 'private_html/lib/')) { continue; }
    $src = (string)file_get_contents($path);
    $scanned++;
    $touches = stripos($src, 'tblApiTokens') !== false || stripos($src, 'tblAccountEnrolKeys') !== false || strpos($src, 'apiTokenRevokeAllForUser') !== false;
    if (!$touches) { continue; }
    $rel = substr($path, strlen($repo) + 1);
    foreach (phpSourceUnits($src) as $unit => $u) {
        foreach ($u['sqlOnly'] as $q) {
            if (isBulkTokenWrite($q)) { $bulk[] = "$rel#$unit"; }
            if (isKeyTableWrite($q))  { $keyWrites[] = "$rel#$unit"; }
        }
        if ($unit !== 'apiTokenRevokeAllForUser' && $unit !== '(file scope)' && str_contains($u['code'], 'apiTokenRevokeAllForUser(')) { $doorCallers[] = "$rel#$unit"; }
    }
}
chk("the scan covered the tree ($scanned PHP files)", $scanned > 300);
chk('every bulk token write is inside apiTokenRevokeAllForUser() (found: ' . implode(', ', array_unique($bulk)) . ')',
    $bulk !== [] && array_unique($bulk) === array_unique(array_filter($bulk, fn($b) => $b === 'appWeb/public_html/includes/api_tokens.php#apiTokenRevokeAllForUser')));
chk('the scan sees the door\'s own bulk write (so an empty result would be a broken scan)', in_array('appWeb/public_html/includes/api_tokens.php#apiTokenRevokeAllForUser', $bulk, true));
$keyOutside = array_values(array_filter(array_unique($keyWrites), fn($k) => !str_starts_with($k, 'appWeb/public_html/includes/account_enrol_keys.php#')));
chk('every write to the key table is inside includes/account_enrol_keys.php (outside: ' . implode(', ', $keyOutside) . ')', $keyOutside === [] && $keyWrites !== []);
$needers = ['api.php#case \'auth_change_password\'', 'manage/includes/auth.php#resetPassword', 'manage/includes/auth.php#changeUserPassword', 'manage/includes/auth.php#setUserActive'];
$calls = implode('|', $doorCallers);
$missing = [];
foreach ($needers as $n) { if (!str_contains($calls, str_replace(["\\'"], ["'"], $n)) && !preg_match('/' . preg_quote(explode('#', $n)[0], '/') . '#.*' . preg_quote(explode('#', $n)[1], '/') . '/', $calls)) { $missing[] = $n; } }
chk('the four old places call the door (' . count($doorCallers) . ' callers found)', count($doorCallers) >= 4 && $missing === [], implode(' | ', $missing) . ' :: ' . $calls);

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
echo "a real database\n";
if ($db === null) {
    echo "  SKIP  no database - the real-rows checks did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
    exit($fail === 0 ? 0 : 1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'ihymns_t3a_revoke_door';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
$db->multi_query((string)file_get_contents($repo . '/appWeb/.sql/schema.sql'));
do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
$db->query("INSERT INTO tblUsers (Id, Username, Email, PasswordHash, DisplayName) VALUES (1, 'ann', 'a@example.test', 'x', 'Ann'), (2, 'bob', 'b@example.test', 'x', 'Bob')");

$legacy = hash('sha256', apiTokenMint($db, 1, null, 'password'));
$devA   = hash('sha256', apiTokenMint($db, 1, ['deviceInstallId' => 'install-AAAA-1111', 'deviceName' => 'Phone', 'platform' => 'apple', 'appVersion' => '1.0'], 'password'));
$devB   = hash('sha256', apiTokenMint($db, 1, ['deviceInstallId' => 'install-BBBB-2222', 'deviceName' => 'Pad', 'platform' => 'apple', 'appVersion' => '1.0'], 'password'));
$bobTok = hash('sha256', apiTokenMint($db, 2, ['deviceInstallId' => 'install-AAAA-1111'], 'password'));
accountEnrolKeyMint($db, 1, 'apple'); accountEnrolKeyMint($db, 1, 'apple'); accountEnrolKeyMint($db, 2, 'apple');
$db->query("INSERT INTO tblApnsTokens (UserId, PushToken, ApiTokenHash) VALUES (1, 'pa', '$devA'), (1, 'pb', '$devB'), (1, 'pnull', NULL), (2, 'pbob', '$bobTok')");
$rows = fn(int $u) => (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE UserId = $u")->fetch_row()[0];
$live = fn(int $u) => (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE UserId = $u AND ExpiresAt > UTC_TIMESTAMP()")->fetch_row()[0];
$push = fn(int $u) => array_column($db->query("SELECT PushToken FROM tblApnsTokens WHERE UserId = $u ORDER BY PushToken")->fetch_all(MYSQLI_ASSOC), 'PushToken');
$liveKeys = fn(int $u) => (int)$db->query("SELECT COUNT(*) FROM tblAccountEnrolKeys WHERE UserId = $u AND RevokedAt IS NULL")->fetch_row()[0];

$r1 = apiTokenRevokeAllForUser($db, 1, $devB, 'test_keep_one');
chk('keeping one: reports 2 sign-ins ended (the shared one and the other device), 2 keys, 1 push row', $r1 === ['tokens' => 2, 'enrolKeys' => 2, 'push' => 1], json_encode($r1));
chk('the kept sign-in is still live; nothing else of hers is', $live(1) === 1 && (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE Token = '$devB' AND ExpiresAt > UTC_TIMESTAMP()")->fetch_row()[0] === 1);
chk('the shared sign-in (no install id) was deleted; the other device\'s was kept as an ended marker with its name blanked',
    (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE Token = '$legacy'")->fetch_row()[0] === 0
    && ($mrow = $db->query("SELECT DeviceName, AppVersion, DeviceInstallId, ExpiresAt FROM tblApiTokens WHERE Token = '$devA'")->fetch_assoc()) && $mrow['DeviceName'] === null && $mrow['AppVersion'] === null && $mrow['DeviceInstallId'] === 'install-AAAA-1111' && strtotime($mrow['ExpiresAt'] . ' UTC') < time());
chk('her keys are all cancelled even though a sign-in was kept; his are not', $liveKeys(1) === 0 && $liveKeys(2) === 1);
chk('push: the other device\'s row went, the kept device\'s and the untied row stayed, nobody else\'s touched', $push(1) === ['pb', 'pnull'] && $push(2) === ['pbob'], json_encode($push(1)));
chk('another person\'s sign-in, even on the same install id, is untouched', $live(2) === 1);
chk('an ended install is reported as signed out; the kept one and a new install are not', apiTokenInstallSignInEnded($db, 1, 'install-AAAA-1111') && !apiTokenInstallSignInEnded($db, 1, 'install-BBBB-2222') && !apiTokenInstallSignInEnded($db, 1, 'install-NEW9-9999'));
chk('a malformed install id is never "ended"', !apiTokenInstallSignInEnded($db, 1, 'bad id!'));

$r2 = apiTokenRevokeAllForUser($db, 1, null, 'test_keep_none');
chk('keeping nothing: the last live sign-in is ended, and every push row of hers goes', $r2['tokens'] === 1 && $live(1) === 0 && $push(1) === [] && $push(2) === ['pbob'], json_encode($r2));
chk('both of her devices are now ended markers, not deleted', $rows(1) === 2 && apiTokenInstallSignInEnded($db, 1, 'install-BBBB-2222'));
chk('running it again ends nothing and does not fail', apiTokenRevokeAllForUser($db, 1, null, 'again') === ['tokens' => 0, 'enrolKeys' => 0, 'push' => 0]);

/* A new sign-in on the install replaces the marker and clears "signed out". */
apiTokenMint($db, 1, ['deviceInstallId' => 'install-AAAA-1111'], 'password');
chk('a new sign-in on the same install replaces its ended marker and clears the block', !apiTokenInstallSignInEnded($db, 1, 'install-AAAA-1111') && $rows(1) === 2 && $live(1) === 1);

/* Signing one device out (the same door shape). */
$devC = hash('sha256', apiTokenMint($db, 1, ['deviceInstallId' => 'install-CCCC-3333'], 'password'));
$legacy2 = hash('sha256', apiTokenMint($db, 1, null, 'password'));
chk('signing out hashes: an install-bound one is ended and remembered, a shared one deleted, someone else\'s hash ignored',
    apiTokenSignOutHashes($db, 1, [$devC, $legacy2, $bobTok, 'not-a-hash']) === 2
    && apiTokenInstallSignInEnded($db, 1, 'install-CCCC-3333') && (int)$db->query("SELECT COUNT(*) FROM tblApiTokens WHERE Token = '$legacy2'")->fetch_row()[0] === 0 && $live(2) === 1);

/* A rolled-back failure leaves everything as it was. */
$hBefore = $live(1);
$brokenDb = new class($hh, $us, $pw, $name, $pt) extends mysqli {
    public int $calls = 0;
    #[\ReturnTypeWillChange]
    public function prepare(string $query) { if (stripos($query, 'UPDATE tblAccountEnrolKeys') !== false) { throw new mysqli_sql_exception('boom'); } return parent::prepare($query); }
};
$brokenDb->set_charset('utf8mb4');
$threw = false;
try { apiTokenRevokeAllForUser($brokenDb, 1, null, 'will_fail'); } catch (\Throwable $e) { $threw = true; }
chk('a failure part-way rolls the whole thing back (the sign-in is not left half-ended) and is passed on', $threw && $live(1) === $hBefore);

$db->query("DROP DATABASE IF EXISTS `{$name}`");
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
