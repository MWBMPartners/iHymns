<?php

declare(strict_types=1);

/**
 * iHymns - a push registration belongs to the sign-in that made it (account/profile rewrite PR 2a,
 * step 2a-8, G4, epic #2180)
 *
 * ELI5
 * ----
 * A person signs in on their iPhone, an iPad and a browser. Signing out of the iPad should stop the
 * iPad's notifications and leave the iPhone's alone. To do that, each push registration now remembers
 * which sign-in it was made under (a hash, never the sign-in token itself), and signing a session out
 * removes only the registrations tied to it. Registrations made before this (tied to no sign-in) keep
 * the older rule: an Apple session removes them, any other does not. This is on only for sites where
 * the new account behaviour is switched on, and only once the foundation migration has added the
 * column; otherwise sign-out behaves exactly as it did.
 *
 * Detailed (rule #34)
 * -------------------
 * Tree-derived: every SQL statement under public_html that deletes from tblApnsTokens by person only
 * (`WHERE UserId = ?` and nothing more) is found; there must be exactly ONE, inside
 * apnsDeleteForSessions() (the legacy rule). A second one anywhere is a blanket delete and fails.
 * Mutation proofs are in the 2a-8 commit message.
 *
 *   php tests/php/test-apns-session-tie.php
 *   IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=" php tests/php/test-apns-session-tie.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $repo . '/tests/php/lib/php_source_units.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

echo "no blanket delete anywhere else (derived from the tree)\n";
$blanket = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    if (stripos($src, 'tblApnsTokens') === false) { continue; }
    $rel = substr($f->getPathname(), strlen($web) + 1);
    foreach (phpSourceUnits($src) as $unit => $u) {
        foreach ($u['strings'] as $q) {
            if (preg_match('/DELETE\s+FROM\s+tblApnsTokens\s+WHERE\s+UserId\s*=\s*\?\s*$/i', trim($q))) {
                $blanket[] = $rel . '#' . $unit;
            }
        }
    }
}
sort($blanket);
chk('exactly two delete-everything-for-a-person statements exist: the legacy rule inside apnsDeleteForSessions() and the sign-out-everywhere door apiTokenRevokeAllForUser() (PR 3 H4, which ends every sign-in at once)',
    $blanket === ['includes/api_tokens.php#apiTokenRevokeAllForUser', 'includes/apns.php#apnsDeleteForSessions'], implode('; ', $blanket));

echo "the callers\n";
$api = phpSourceUnits((string)file_get_contents($web . '/api.php'));
foreach (['auth_logout', 'device_signout'] as $case) {
    $c = $api["case '$case'"]['code'] ?? '';
    chk("$case ends push registrations only through apnsDeleteForSessions()", str_contains($c, 'apnsDeleteForSessions(') && !str_contains(implode(' ', $api["case '$case'"]['strings'] ?? []), 'DELETE FROM tblApnsTokens'));
    chk("$case passes the per-session rule when EITHER switch is on (apnsPerSessionHere())", (bool)preg_match('/apnsDeleteForSessions\([^;]*apnsPerSessionHere\(\)\s*\)/', $c));
    chk("$case still passes the A6 answer (apiTokenSessionOwnsApplePush) for registrations tied to no session", (bool)preg_match('/apnsDeleteForSessions\([^;]*apiTokenSessionOwnsApplePush\(/', $c));
}
$ds = $api["case 'device_signout'"]['code'] ?? '';
$pRead = strpos($ds, 'apiTokenHashesForDevicePrefix(');
$pDel  = strpos($ds, 'DELETE FROM tblApiTokens');
$pDel  = $pDel === false ? strpos(implode(' ', $api["case 'device_signout'"]['strings'] ?? []), 'DELETE FROM tblApiTokens') : $pDel;
$dsStr = implode("\n", $api["case 'device_signout'"]['strings'] ?? []);
chk('device_signout reads the full hashes for its prefix BEFORE it deletes the sign-in rows', $pRead !== false && str_contains($dsStr, 'DELETE FROM tblApiTokens') && $pRead < strpos($ds, '@SQL:DELETE:tblApiTokens@'), 'read at ' . var_export($pRead, true));
$ar = $api["case 'apns_register'"]['code'] ?? '';
chk('apns_register stores the session hash only when either switch is on (apnsPerSessionHere()), a session token and the column there', (bool)preg_match('/\$apnsSessionHash\s*!==\s*null\s*&&\s*apnsPerSessionHere\(\)\s*&&\s*apnsTokenHashReady\(\$db\)/', $ar));
chk('...and updates it when an existing registration is re-registered', str_contains(implode(' ', $api["case 'apns_register'"]['strings'] ?? []), 'ApiTokenHash = VALUES(ApiTokenHash)'));
chk('...and the older statement forgets a previous tie (ApiTokenHash = NULL) whenever the column exists, so a re-registration cannot stay tied to another session',
    (bool)preg_match('/\$forgetTie\s*=\s*apnsTokenHashReady\(\$db\)\s*\?/', $ar) && str_contains(implode(' ', $api["case 'apns_register'"]['strings'] ?? []), ', ApiTokenHash = NULL'));
chk('...and still has the old statement for everything else', str_contains(implode(' ', $api["case 'apns_register'"]['strings'] ?? []), 'INSERT INTO tblApnsTokens (Kind, UserId, SessionId, PushToken, ApnsEnv, ExpiresAt)'));
chk('the hash is of the session token, the same value the sign-in table stores', (bool)preg_match("/\\\$apnsSessionHash\s*=\s*\(.*?\)\s*\?\s*hash\(\s*'sha256'\s*,\s*\\\$apnsBearer\s*\)/", $ar));


/* ---- PR 3 H2: ONE answer to "tie push to one sign-in?", asked everywhere, derived from the tree ---- */
echo "one per-session answer, found from the tree\n";
$calls = []; $inserts = []; $badCalls = []; $badInserts = []; $oldSwitch = [];
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it2 as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    if (stripos($src, 'apnsDeleteForSessions') === false && stripos($src, 'tblApnsTokens') === false) { continue; }
    $rel = substr($f->getPathname(), strlen($web) + 1);
    foreach (phpSourceUnits($src) as $unit => $u) {
        if ($unit === 'apnsDeleteForSessions') { continue; }
        if (preg_match_all('/apnsDeleteForSessions\(([^;]*);/s', $u['code'], $mm)) {
            foreach ($mm[0] as $callText) {
                $calls[] = "$rel#$unit";
                if (!str_contains($callText, 'apnsPerSessionHere()')) { $badCalls[] = "$rel#$unit"; }
            }
        }
        foreach ($u['strings'] as $q) {
            if (preg_match('/INSERT\s+INTO\s+tblApnsTokens\b[^;]*ApiTokenHash/is', $q)) {
                $inserts[] = "$rel#$unit";
                if (!str_contains($u['code'], 'apnsPerSessionHere()')) { $badInserts[] = "$rel#$unit"; }
            }
        }
        if (preg_match('/\bprofileSyncEnabledHere\(\)/', $u['code']) && (str_contains($u['code'], 'apnsDeleteForSessions(') || preg_match('/ApiTokenHash/', implode(' ', $u['strings'])))) {
            $oldSwitch[] = "$rel#$unit";
        }
    }
}
chk('the scan found the callers and the tied insert (' . count($calls) . ' delete calls, ' . count($inserts) . ' tied inserts)', count($calls) >= 2 && count($inserts) >= 1);
chk('every apnsDeleteForSessions() call passes apnsPerSessionHere(), none a single switch', $badCalls === [], implode('; ', $badCalls));
chk('every insert that writes ApiTokenHash sits in a function/case that asks apnsPerSessionHere()', $badInserts === [], implode('; ', $badInserts));
chk('no push-tie site still asks profileSyncEnabledHere() on its own', $oldSwitch === [], implode('; ', $oldSwitch));
require_once $web . '/includes/apns.php';
$yes = static fn(string $k, string $d): string => 'alpha';
$no  = static fn(string $k, string $d): string => 'none';
$boomR = static function (string $k, string $d): string { throw new \RuntimeException('database down'); };
chk('truth table: sync only, device only, both, neither', apnsPerSessionHere($yes, $no, 'alpha') && apnsPerSessionHere($no, $yes, 'alpha') && apnsPerSessionHere($yes, $yes, 'alpha') && !apnsPerSessionHere($no, $no, 'alpha'));
chk('...a switch ticked for another channel does not count', !apnsPerSessionHere($yes, $yes, 'production'));
chk('...an unreadable switch is "no" (the previous rule applies)', !apnsPerSessionHere($boomR, $boomR, 'alpha') && apnsPerSessionHere($boomR, $yes, 'alpha'));

/* ---- pure shape of the hashes helper ---- */
require_once $web . '/includes/api_tokens.php';
chk('a device id that is not lowercase hex gives no hashes (nothing reaches SQL)', (function () {
    $db = new class extends mysqli { public function __construct() {} };
    return apiTokenHashesForDevicePrefix($db, 1, "ab'; DROP") === [] && apiTokenHashesForDevicePrefix($db, 1, 'XYZ') === [] && apiTokenHashesForDevicePrefix($db, 1, 'ab') === [];
})());

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
    $name = 'ihymns_t2a8_apns';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        $db->query('CREATE TABLE tblUsers (Id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $db->query('INSERT INTO tblUsers (Id) VALUES (1), (2)');
        $db->query('CREATE TABLE tblApiTokens (Token VARCHAR(64) NOT NULL PRIMARY KEY, UserId INT UNSIGNED NOT NULL, ExpiresAt DATETIME NOT NULL) ENGINE=InnoDB');
        $mk = function (bool $withHash) use ($db): void {
            $db->query('DROP TABLE IF EXISTS tblApnsTokens');
            $db->query('CREATE TABLE tblApnsTokens (Id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, Kind VARCHAR(20) NOT NULL DEFAULT \'device\', UserId INT UNSIGNED NULL, SessionId INT UNSIGNED NULL, PushToken VARBINARY(255) NOT NULL, ApnsEnv VARCHAR(20) NOT NULL DEFAULT \'production\', ExpiresAt DATETIME NULL'
                . ($withHash ? ', ApiTokenHash VARCHAR(64) NULL DEFAULT NULL' : '') . ', UNIQUE KEY uq_PushToken (PushToken)) ENGINE=InnoDB');
        };
        $h1 = str_repeat('a', 64); $h2 = str_repeat('b', 64); $h3 = str_repeat('c', 64);
        /* a fresh process each time (the ready-checks remember their answer) */
        $run = static function (bool $withHash, string $php) use ($web, $hh, $us, $pw, $name, $pt) {
            return shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
                '$_SERVER["SCRIPT_FILENAME"]="t"; require ' . var_export($web . '/includes/apns.php', true) . ';'
                . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
                . '$d = new mysqli(' . var_export($hh, true) . ',' . var_export($us, true) . ',' . var_export($pw, true) . ',' . var_export($name, true) . ',' . (int)$pt . ');'
                . $php
            ));
        };
        $seed = function () use ($db, $h1, $h2): void {
            $db->query("INSERT INTO tblApnsTokens (UserId, PushToken, ApiTokenHash) VALUES
                (1, 'tok-iphone', '$h1'), (1, 'tok-ipad', '$h2'), (1, 'tok-old', NULL), (2, 'tok-other', '$h1'), (2, 'tok-other-old', NULL)");
        };
        $left = static fn(mysqli $d): string => implode(',', array_column($d->query('SELECT PushToken FROM tblApnsTokens ORDER BY Id')->fetch_all(MYSQLI_ASSOC), 'PushToken'));

        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], false, true);");
        chk('per session, a non-Apple session: only its own registration goes (the iPad, the old one and other people stay)', $left($db) === 'tok-ipad,tok-old,tok-other,tok-other-old', $left($db));

        /* The iPad's sign-in is still live; the person's other sign-ins and an expired one are set up for the orphan checks below. */
        $db->query("INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES ('$h2', 1, '2099-01-01 00:00:00'), ('$h3', 1, '2001-01-01 00:00:00')");
        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], true, true);");
        chk('per session, an Apple session: its own registration and the old untied ones go; the iPad (a live sign-in) and other people stay', $left($db) === 'tok-ipad,tok-other,tok-other-old', $left($db));

        /* Review fix 4: a registration whose sign-in is gone (password change, expiry sweep) is cleared by the next Apple sign-out. */
        $mk(true); $seed();
        $db->query("INSERT INTO tblApnsTokens (UserId, PushToken, ApiTokenHash) VALUES (1, 'tok-gone', '" . str_repeat('d', 64) . "'), (1, 'tok-expired', '$h3')");
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], true, true);");
        chk('an Apple sign-out also clears registrations whose sign-in no longer exists or has expired; a live one and other people stay', $left($db) === 'tok-ipad,tok-other,tok-other-old', $left($db));
        $mk(true); $seed();
        $db->query("INSERT INTO tblApnsTokens (UserId, PushToken, ApiTokenHash) VALUES (1, 'tok-gone', '" . str_repeat('d', 64) . "')");
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], false, true);");
        chk('a NON-Apple sign-out does not sweep orphans (only Apple ones do, as in A6)', str_contains($left($db), 'tok-gone'), $left($db));

        $mk(true); $seed();
        $n = $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1', '$h2'], false, true);");
        chk('two sessions ended together remove both registrations', $left($db) === 'tok-old,tok-other,tok-other-old' && trim((string)$n) === '2', $left($db) . ' / ' . var_export($n, true));

        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h3'], false, true);");
        chk('a session with no registration removes nothing', $left($db) === 'tok-iphone,tok-ipad,tok-old,tok-other,tok-other-old', $left($db));

        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['not-a-hash', \"x' OR '1'='1\"], false, true);");
        chk('anything that is not a hash is ignored (nothing reaches SQL)', $left($db) === 'tok-iphone,tok-ipad,tok-old,tok-other,tok-other-old', $left($db));

        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], true, false);");
        chk('switch off, Apple session: the previous rule (all of the person\'s registrations go, nobody else\'s)', $left($db) === 'tok-other,tok-other-old', $left($db));

        $mk(true); $seed();
        $run(true, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], false, false);");
        chk('switch off, non-Apple session: nothing goes (A6)', $left($db) === 'tok-iphone,tok-ipad,tok-old,tok-other,tok-other-old', $left($db));

        $mk(false);
        $db->query("INSERT INTO tblApnsTokens (UserId, PushToken) VALUES (1, 'a1'), (1, 'a2'), (2, 'b1')");
        $out = $run(false, "echo apnsDeleteForSessions(\$d, 1, ['$h1'], true, true);");
        chk('switch on but the column is not there yet: the previous rule applies and nothing throws', $left($db) === 'b1' && trim((string)$out) === '2', $left($db) . ' / ' . var_export($out, true));

        /* the full-hash helper */
        $db->query("INSERT IGNORE INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES ('$h1', 1, '2099-01-01 00:00:00'), ('$h2', 1, '2099-01-01 00:00:00'), ('" . str_repeat('a', 63) . "d', 2, '2099-01-01 00:00:00')");
        $hs = apiTokenHashesForDevicePrefix($db, 1, substr($h1, 0, 16));
        chk('the full hash for a device prefix is read, for that person only', $hs === [$h1], json_encode($hs));
        chk('another person\'s sign-in with the same prefix is never returned', apiTokenHashesForDevicePrefix($db, 2, substr($h1, 0, 16)) === [str_repeat('a', 63) . 'd']);
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

exit($fail === 0 ? 0 : 1);
