<?php

declare(strict_types=1);

/**
 * iHymns - the profile sync core: what is kept, what is refused, who wins (PR 2b step 2b-2, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * Small personal choices (a theme, a key for one song, recent songbooks) are saved as one
 * row each. A device sends only what it changed; the server keeps what passes the one
 * registry, refuses the rest with a reason, and numbers each accepted change so other
 * devices can ask "what happened after number N?". This test calls the real code with real
 * and awkward values, and (when a database is available) runs it against a real database
 * with two connections and two separate processes at the same moment.
 *
 * Detailed (rule #34)
 * -------------------
 *  1. The PURE plan (profileSyncPlan) is called with a truth table: later change wins, an
 *     equal value keeps its version (even when the keys are in another order), a delete leaves
 *     a marker and reaches another cursor, an unknown key is refused while the rest are stored,
 *     a device-only key is refused, the cap refuses a NEW item and never evicts, the same item
 *     changed twice in one request keeps the last, version numbers rise by one per accepted
 *     change in arrival order. A final check asserts every refusal reason the code can give
 *     is produced by some case here, so adding a reason without a test fails.
 *  2. The DATABASE half (IHYMNS_TEST_DSN; a SKIP is printed loudly, never a silent pass):
 *     the table is copied out of schema.sql, then: before the table exists nothing throws;
 *     apply and pull round trip; paging; a delete reaches an old cursor; a cursor that is too
 *     high resets; internal rows are never returned; the cap on real rows; the person row lock
 *     really makes a second writer wait (a separate process is held back while the lock is held);
 *     two processes writing 40 items each at once lose nothing and never share a version.
 *
 * Mutation proofs are recorded in the 2b-2 commit: `>` changed to `>=` on the pull cursor, the
 * lock removed from profileSyncApply(), a device-only key accepted - each turns this red.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;user=root;pass=" php tests/php/test-profile-sync-core.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/profile_sync.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

echo "the pure plan\n";
$set = fn(string $s, string $k, mixed $v) => ['section' => $s, 'key' => $k, 'op' => 'set', 'value' => $v];
$del = fn(string $s, string $k) => ['section' => $s, 'key' => $k, 'op' => 'delete'];
$live = fn(mixed $v) => ['deleted' => false, 'value' => $v];
$marker = ['deleted' => true, 'value' => null];
$P = fn(string $s, string $k) => profileSyncPairKey($s, $k);
$reasonsSeen = [];
$note = function (array $plan) use (&$reasonsSeen): void { foreach ($plan['rejected'] as $r) { $reasonsSeen[$r['reason']] = true; } };

$p = profileSyncPlan([], [$set('appearance', 'theme', 'dark')], 10, 'web', []);
chk('a new valid item is written with the next version', count($p['writes']) === 1 && $p['writes'][0]['version'] === 11 && $p['writes'][0]['json'] === '"dark"' && $p['applied'] === 1 && $p['maxVersion'] === 11);
chk('...and records who wrote it (an unknown platform is stored as nothing)', $p['writes'][0]['platform'] === 'web' && profileSyncPlan([], [$set('appearance', 'theme', 'dark')], 0, 'toaster', [])['writes'][0]['platform'] === null);

$p = profileSyncPlan([], [$set('appearance', 'theme', 'dark'), $set('appearance', 'theme', 'light')], 0, null, []);
chk('the later change to the same item wins (one write, the last value) and consumes its own version', count($p['writes']) === 1 && $p['writes'][0]['json'] === '"light"' && $p['writes'][0]['version'] === 2 && $p['applied'] === 2);

$p = profileSyncPlan([$P('appearance', 'theme') => $live('dark')], [$set('appearance', 'theme', 'dark')], 7, null, []);
chk('an equal value writes nothing and keeps its version', $p['writes'] === [] && $p['unchanged'] === 1 && $p['applied'] === 0 && $p['maxVersion'] === 7);

$p = profileSyncPlan([$P('reading', 'display') => $live(['fontSize' => 1.5, 'lineSpacing' => 'normal'])], [$set('reading', 'display', ['lineSpacing' => 'normal', 'fontSize' => 1.5])], 3, null, []);
chk('the same object with its keys in another order is NOT a change', $p['writes'] === [] && $p['unchanged'] === 1);
$p = profileSyncPlan([$P('reading', 'display') => $live(['fontSize' => 2.0])], [$set('reading', 'display', ['fontSize' => 2])], 3, null, []);
chk('2.0 and 2 are the same number', $p['writes'] === []);
$p = profileSyncPlan([$P('reading', 'display') => $live(['fontSize' => 2.0])], [$set('reading', 'display', ['fontSize' => 2.5])], 3, null, []);
chk('a really different value IS a change', count($p['writes']) === 1 && $p['writes'][0]['version'] === 4);
$p = profileSyncPlan([$P('appearance', 'theme') => $marker], [$set('appearance', 'theme', 'dark')], 5, null, []);
chk('setting an item that has a delete marker brings it back with a new version', count($p['writes']) === 1 && $p['writes'][0]['deleted'] === false && $p['writes'][0]['version'] === 6);
$p = profileSyncPlan([$P('songPrefs', 'MP-1') => $live(['capo' => 2])], [$set('songPrefs', 'mp-1', ['capo' => 2])], 5, null, []);
chk('the table ignores letter case, so MP-1 and mp-1 are the same item', $p['writes'] === [] && $p['unchanged'] === 1);

$p = profileSyncPlan([$P('appearance', 'theme') => $live('dark')], [$del('appearance', 'theme')], 5, 'apple', []);
chk('a delete of a live item writes a marker with a new version and no value', count($p['writes']) === 1 && $p['writes'][0]['deleted'] === true && $p['writes'][0]['json'] === null && $p['writes'][0]['version'] === 6);
$p = profileSyncPlan([$P('appearance', 'theme') => $marker], [$del('appearance', 'theme')], 5, null, []);
chk('deleting what is already deleted changes nothing', $p['writes'] === [] && $p['unchanged'] === 1);
$p = profileSyncPlan([], [$del('appearance', 'theme')], 5, null, []);
chk('deleting what never existed changes nothing', $p['writes'] === [] && $p['unchanged'] === 1);
$p = profileSyncPlan([$P('appearance', 'theme') => $live('dark')], [$del('appearance', 'theme'), $set('appearance', 'theme', 'light')], 5, null, []);
chk('delete then set in one request ends as the set', count($p['writes']) === 1 && $p['writes'][0]['deleted'] === false && $p['writes'][0]['json'] === '"light"');

/* refusals */
$mixed = [
    $set('appearance', 'theme', 'dark'),
    $set('appearance', 'nope', 'x'),
    $set('_meta', 'webShelfHash', 'abc'),
    $set('device', 'midiPedal', true),
    $set('content', 'favorites', ['CP-1']),
    $set('appearance', 'theme', 'solarized'),
    $set('appearance', 'theme', null),
    ['section' => 'appearance', 'key' => 'theme', 'op' => 'merge', 'value' => 'dark'],
    $set('songPrefs', 'not a song', ['capo' => 1]),
    $set('songPrefs', '*', ['capo' => 1]),
    $set('appearance', 'theme', str_repeat('x', PROFILE_SYNC_MAX_VALUE_BYTES + 1)),
    'not even an object',
    ['section' => 7, 'key' => 'theme', 'op' => 'set', 'value' => 'dark'],
    $set('library', 'defaultSongbook', 'MP'),
];
$p = profileSyncPlan([], $mixed, 0, null, []);
$note($p);
$byKey = [];
foreach ($p['rejected'] as $r) { $byKey[$r['section'] . '.' . $r['key'] . '#' . $r['reason']] = true; }
chk('an unknown key is refused while the valid ones in the same request are stored', count($p['writes']) === 2 && $p['writes'][0]['json'] === '"dark"' && $p['writes'][1]['json'] === '"MP"');
chk('unknown_key: an unknown key, the internal _meta section, a missing section, a non-object change', isset($byKey['appearance.nope#unknown_key']) && isset($byKey['_meta.webShelfHash#unknown_key']) && isset($byKey['.theme#unknown_key']));
chk('device_only: a device-scope key is refused', isset($byKey['device.midiPedal#device_only']));
chk('not_profile_item: favourites have their own sync', isset($byKey['content.favorites#not_profile_item']));
chk('invalid_value: a value not in the list, null, an unknown operation', isset($byKey['appearance.theme#invalid_value']) && count(array_filter($p['rejected'], fn($r) => $r['reason'] === 'invalid_value')) === 3);
chk('invalid_item_key: a map key that does not match its pattern, and the literal "*"', isset($byKey['songPrefs.not a song#invalid_item_key']) && isset($byKey['songPrefs.*#invalid_item_key']));
chk('value_too_large: over 4 KiB is refused, never cut', count(array_filter($p['rejected'], fn($r) => $r['reason'] === 'value_too_large')) === 1);
chk('every refusal names the section and key it was about, trimmed', count(array_filter($p['rejected'], fn($r) => isset($r['section'], $r['key'], $r['reason']))) === count($p['rejected']));

/* the cap */
$p = profileSyncPlan([], [$set('songPrefs', 'MP-9', ['capo' => 1])], 0, null, ['songPrefs' => 2000]);
$note($p);
chk('cap_reached: a NEW item in a full map is refused and nothing is evicted', $p['writes'] === [] && ($p['rejected'][0]['reason'] ?? '') === 'cap_reached');
$p = profileSyncPlan([$P('songPrefs', 'MP-1') => $live(['capo' => 1])], [$set('songPrefs', 'MP-1', ['capo' => 4])], 0, null, ['songPrefs' => 2000]);
chk('...but changing an item that is already there still works', count($p['writes']) === 1 && $p['rejected'] === []);
$p = profileSyncPlan([$P('songPrefs', 'MP-1') => $live(['capo' => 1])], [$del('songPrefs', 'MP-1'), $set('songPrefs', 'MP-2', ['capo' => 1])], 0, null, ['songPrefs' => 2000]);
chk('a delete in the same request makes room for a new item (counted in order)', count($p['writes']) === 2 && $p['rejected'] === []);
$p = profileSyncPlan([], [$set('songPrefs', 'MP-1', ['capo' => 1]), $set('songPrefs', 'MP-2', ['capo' => 1]), $set('songPrefs', 'MP-3', ['capo' => 1])], 0, null, ['songPrefs' => 1998]);
chk('the cap is counted as items are added in one request (the third is refused)', count($p['writes']) === 2 && ($p['rejected'][0]['key'] ?? '') === 'MP-3');
$p = profileSyncPlan([$P('songPrefs', 'MP-1') => $marker], [$set('songPrefs', 'MP-1', ['capo' => 1])], 0, null, ['songPrefs' => 2000]);
chk('bringing a deleted item back counts as a new live item (the cap applies)', $p['writes'] === [] && ($p['rejected'][0]['reason'] ?? '') === 'cap_reached');

/* versions in arrival order */
$p = profileSyncPlan([], [$set('appearance', 'theme', 'dark'), $set('songPrefs', 'MP-1', ['capo' => 1]), $set('appearance', 'reduceMotion', true), $set('appearance', 'theme', 'bad')], 40, null, []);
chk('versions rise by one per accepted change, in the order the changes arrived; a refused change uses none',
    array_column($p['writes'], 'version') === [41, 42, 43] && $p['maxVersion'] === 43);
chk('an empty list of changes does nothing', profileSyncPlan([], [], 9, null, [])['maxVersion'] === 9);

/* the stored text */
chk('an empty object is stored as {} and an empty list as []',
    profileSyncEncodeValue(profileRegistryEntry('reading', 'display'), []) === '{}' && profileSyncEncodeValue(profileRegistryEntry('library', 'recentSongbooks'), []) === '[]'
    && profileSyncEncodeValue(profileRegistryEntry('songPrefs', 'MP-1'), []) === '{}');
chk('non-ASCII text is stored as it is (not \\u escaped)', profileSyncEncodeValue(profileRegistryEntry('history', 'searches'), ['café']) === '["café"]');

/* every reason the code can give has a case above (rule #34: a new reason without a test goes red) */
foreach (profileSyncPlan([], $mixed, 0, null, [])['rejected'] as $r) { $reasonsSeen[$r['reason']] = true; }
$missing = array_diff(PROFILE_SYNC_REJECT_REASONS, array_keys($reasonsSeen));
chk('every refusal reason in PROFILE_SYNC_REJECT_REASONS is produced by a case here', $missing === [], implode(',', $missing));
$src = (string)file_get_contents($web . '/includes/profile_sync.php');
preg_match_all("/\\\$reject\('([a-z_]+)'\)/", $src, $mr);
chk('...and the plan uses no reason that is not on the list', array_diff(array_unique($mr[1]), PROFILE_SYNC_REJECT_REASONS) === [], implode(',', array_diff(array_unique($mr[1]), PROFILE_SYNC_REJECT_REASONS)));

/* ---------------------------------------------------------------------------------------------- */
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
$name = 'ihymns_t2b2_core';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
$connect = function () use ($hh, $us, $pw, $name, $pt): mysqli {
    $c = new mysqli($hh, $us, $pw, $name, $pt);
    $c->set_charset('utf8mb4');
    return $c;
};
/** Run PHP in a fresh process that has the core loaded and a connection in $d; returns [process, pipes]. */
$spawn = function (string $php) use ($web, $hh, $us, $pw, $name, $pt) {
    $code = '$_SERVER["SCRIPT_FILENAME"]="t"; require ' . var_export($web . '/includes/profile_sync.php', true) . ';'
        . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
        . '$d = new mysqli(' . var_export($hh, true) . ',' . var_export($us, true) . ',' . var_export($pw, true) . ',' . var_export($name, true) . ',' . (int)$pt . '); $d->set_charset("utf8mb4");'
        . $php;
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$proc, $pipes];
};
$finish = function (array $h): string {
    [$proc, $pipes] = $h;
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);
    return trim($out);
};
try {
    $db->query('CREATE TABLE tblUsers (Id INT UNSIGNED NOT NULL PRIMARY KEY, Settings JSON NULL) ENGINE=InnoDB');
    $db->query('INSERT INTO tblUsers (Id) VALUES (1), (2)');

    /* before the table exists: nothing throws, nothing is stored */
    $out = $finish($spawn('echo json_encode([profileSyncApply($d, 1, [["section"=>"appearance","key"=>"theme","op"=>"set","value"=>"dark"]], "web")["ready"], profileSyncPull($d, 1, 0)["ready"], profileSyncPull($d, 1, 5)["cursor"]]);'));
    chk('before the table exists, apply and pull quietly say "not ready" and throw nothing', $out === '[false,false,5]', $out);

    $schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
    chk('the table is found in schema.sql', (bool)preg_match('/CREATE TABLE IF NOT EXISTS tblUserProfileItems \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $mm));
    $db->query($mm[0]);
    require_once $web . '/includes/user_sync.php';

    chk('with the table there, the ready-check says yes', profileItemsReady($db) === true);
    $r = profileSyncApply($db, 1, [$set('appearance', 'theme', 'dark'), $set('songPrefs', 'MP-1', ['transpose' => 2, 'capo' => 1, 'octave' => 0]), $set('nope', 'x', 1), $set('appearance', 'reduceMotion', true)], 'web', 'alpha');
    chk('apply stores the good changes and reports the refused one', $r['applied'] === 3 && count($r['rejected']) === 1 && $r['rejected'][0]['reason'] === 'unknown_key' && $r['maxVersion'] === 3);
    $rows = $db->query('SELECT Section, ItemKey, ValueJson, Version, DeletedAt, WriterChannel, WriterPlatform FROM tblUserProfileItems WHERE UserId = 1 ORDER BY Version')->fetch_all(MYSQLI_ASSOC);
    chk('three rows with versions 1, 2, 3 in arrival order, the writer recorded', array_column($rows, 'Version') === ['1', '2', '3'] && $rows[0]['WriterChannel'] === 'alpha' && $rows[0]['WriterPlatform'] === 'web' && $rows[1]['ItemKey'] === 'MP-1');

    $r2 = profileSyncApply($db, 1, [$set('appearance', 'theme', 'dark')], 'web');
    $v = (int)$db->query("SELECT Version FROM tblUserProfileItems WHERE UserId = 1 AND ItemKey = 'theme'")->fetch_row()[0];
    chk('sending the same value again changes nothing (the version stays 1, no new version is used)', $r2['applied'] === 0 && $r2['unchanged'] === 1 && $v === 1 && (int)$db->query('SELECT MAX(Version) FROM tblUserProfileItems WHERE UserId = 1')->fetch_row()[0] === 3);
    $r2 = profileSyncApply($db, 1, [$set('songPrefs', 'MP-1', ['octave' => 0, 'capo' => 1, 'transpose' => 2])], 'web');
    chk('the stored text is compared by meaning, so the same object with keys in a different order is not a change', $r2['applied'] === 0);

    $pull = profileSyncPull($db, 1, 0);
    chk('a pull from 0 returns every change in version order with the decoded value', array_column($pull['changes'], 'version') === [1, 2, 3] && $pull['changes'][0]['value'] === 'dark' && $pull['changes'][1]['value'] === ['transpose' => 2, 'capo' => 1, 'octave' => 0] && $pull['cursor'] === 3 && $pull['more'] === false && $pull['resetCursor'] === false);
    $pull = profileSyncPull($db, 1, 2);
    chk('a pull from 2 returns only version 3 (strictly above the cursor)', count($pull['changes']) === 1 && $pull['changes'][0]['version'] === 3);
    $pull = profileSyncPull($db, 1, 3);
    chk('a pull from the newest returns nothing and the cursor stays', $pull['changes'] === [] && $pull['cursor'] === 3);

    /* delete reaches another cursor */
    profileSyncApply($db, 1, [$del('songPrefs', 'MP-1')], 'apple');
    $pull = profileSyncPull($db, 1, 3);
    chk('a delete reaches a device that was at the old cursor, as a marker with no value', count($pull['changes']) === 1 && $pull['changes'][0]['deleted'] === true && $pull['changes'][0]['value'] === null && $pull['changes'][0]['version'] === 4 && $pull['cursor'] === 4);
    profileSyncApply($db, 1, [$set('songPrefs', 'MP-1', ['capo' => 5])], 'web');
    $row = $db->query("SELECT DeletedAt, ValueJson, Version FROM tblUserProfileItems WHERE UserId = 1 AND ItemKey = 'MP-1'")->fetch_row();
    chk('setting it again clears the marker and takes the next version', $row[0] === null && (int)$row[2] === 5 && json_decode((string)$row[1], true) === ['capo' => 5]);

    /* paging */
    $pg = profileSyncPull($db, 1, 0, 2);
    chk('a page of 2 says there is more and stops at the second row', count($pg['changes']) === 2 && $pg['more'] === true && $pg['cursor'] === array_slice($pg['changes'], -1)[0]['version']);
    $pg2 = profileSyncPull($db, 1, $pg['cursor'], 2);
    chk('the next page continues exactly after it (nothing repeated, nothing skipped) and ends', count($pg2['changes']) === 1 && $pg2['more'] === false && $pg2['changes'][0]['version'] === 5 && array_column($pg['changes'], 'version') === [1, 3]);
    chk('the pages together are every live or deleted change above 0, once each', count($pg['changes']) + count($pg2['changes']) === 3);

    /* a cursor above the highest version */
    $hi = profileSyncPull($db, 1, 999);
    chk('a cursor that is too high is reset: the whole list comes back and resetCursor is true', $hi['resetCursor'] === true && count($hi['changes']) === 3 && $hi['cursor'] === 5);

    /* internal rows are never returned and consume no version */
    $db->query("INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt) VALUES (1, '_meta', 'webShelfHash', '\"abc\"', 0, UTC_TIMESTAMP(3))");
    $db->query("INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt) VALUES (1, '_meta', 'sneaky', '\"x\"', 999, UTC_TIMESTAMP(3))");
    $all = profileSyncPull($db, 1, 0);
    chk('the _meta section is never returned, even when a row there has a high version', !in_array('_meta', array_column($all['changes'], 'section'), true));
    $db->query("DELETE FROM tblUserProfileItems WHERE Section = '_meta' AND ItemKey = 'sneaky'");

    /* people are separate */
    profileSyncApply($db, 2, [$set('appearance', 'theme', 'light')], 'web');
    chk('another person has their own counter starting from 1, and sees only their own items', profileSyncPull($db, 2, 0)['changes'][0]['version'] === 1 && count(profileSyncPull($db, 2, 0)['changes']) === 1 && count(profileSyncPull($db, 1, 0)['changes']) === 3);

    /* the cap, on real rows */
    $db->query('DELETE FROM tblUserProfileItems WHERE UserId = 2');
    $vals = [];
    for ($i = 1; $i <= 2000; $i++) { $vals[] = "(2, 'songPrefs', 'MP-{$i}', '{\"capo\":1}', {$i}, UTC_TIMESTAMP(3))"; }
    $db->query('INSERT INTO tblUserProfileItems (UserId, Section, ItemKey, ValueJson, Version, UpdatedAt) VALUES ' . implode(',', $vals));
    $r = profileSyncApply($db, 2, [$set('songPrefs', 'MP-9999', ['capo' => 2]), $set('songPrefs', 'MP-5', ['capo' => 4])], 'web');
    $n = (int)$db->query("SELECT COUNT(*) FROM tblUserProfileItems WHERE UserId = 2 AND Section = 'songPrefs' AND DeletedAt IS NULL")->fetch_row()[0];
    chk('with 2000 real rows, a new item is refused (cap_reached), an existing one still changes, and nothing was evicted',
        $n === 2000 && ($r['rejected'][0]['reason'] ?? '') === 'cap_reached' && $r['applied'] === 1 && (int)$db->query("SELECT COUNT(*) FROM tblUserProfileItems WHERE ItemKey = 'MP-9999'")->fetch_row()[0] === 0);
    $db->query('DELETE FROM tblUserProfileItems WHERE UserId = 2');

    /* too many changes in one batch is a programming error the endpoint prevents */
    $threw = false;
    try { profileSyncApply($db, 1, array_fill(0, PROFILE_SYNC_MAX_CHANGES + 1, $set('appearance', 'theme', 'dark')), 'web'); } catch (\InvalidArgumentException $_e) { $threw = true; }
    chk('more than 200 changes in one batch is refused outright (and the transaction was rolled back)', $threw && !dbTransactionIsOpen($db));

    /* The person row lock really makes a second writer wait. The table's own link to tblUsers would make an
       insert wait on a held user-row lock all by itself, hiding a missing lock, so the link is dropped for the
       two concurrency checks below: only OUR lock can hold the writer back now. */
    $db->query('ALTER TABLE tblUserProfileItems DROP FOREIGN KEY fk_ProfileItems_User');
    $holder = $connect();
    $holder->begin_transaction();
    userSyncLockPerson($holder, 1);
    $child = $spawn('echo json_encode(profileSyncApply($d, 1, [["section"=>"appearance","key"=>"transition","op"=>"set","value"=>"fade"]], "web")["applied"]);');
    usleep(1200000);
    $stillRunning = proc_get_status($child[0])['running'];
    $holder->commit();
    $out = $finish($child);
    chk('while another writer holds the person\'s row lock, a second writer is held back, then runs once it is released', $stillRunning === true && $out === '1', 'running=' . var_export($stillRunning, true) . ' out=' . $out);

    /* two processes at the same moment lose nothing and never share a version */
    $before = (int)$db->query('SELECT MAX(Version) FROM tblUserProfileItems WHERE UserId = 1')->fetch_row()[0];
    $mk = static function (string $tag): string {
        return '$ch = []; for ($i = 1; $i <= 40; $i++) { $ch[] = ["section"=>"songPrefs","key"=>"' . $tag . '-" . $i,"op"=>"set","value"=>["capo"=>($i % 11) + 1]]; }'
            . '$r = profileSyncApply($d, 1, $ch, "web"); echo json_encode([$r["applied"], count($r["rejected"])]);';
    };
    $c1 = $spawn($mk('AA')); $c2 = $spawn($mk('BB'));
    $o1 = $finish($c1); $o2 = $finish($c2);
    $versions = array_map('intval', array_column($db->query("SELECT Version FROM tblUserProfileItems WHERE UserId = 1 AND (ItemKey LIKE 'AA-%' OR ItemKey LIKE 'BB-%')")->fetch_all(MYSQLI_ASSOC), 'Version'));
    sort($versions);
    chk('two writers at once both stored all 40 of their items', $o1 === '[40,0]' && $o2 === '[40,0]' && count($versions) === 80, $o1 . ' ' . $o2 . ' rows=' . count($versions));
    chk('...and every version is different (no two changes share a number) and they run on from where the counter was', count(array_unique($versions)) === 80 && $versions[0] === $before + 1 && $versions[79] === $before + 80);
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
}

exit($fail === 0 ? 0 : 1);
