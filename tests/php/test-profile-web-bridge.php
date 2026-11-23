<?php

declare(strict_types=1);

/**
 * iHymns - the old settings box and the profile stay in step, and neither ever deletes the other's data
 * (PR 2b step 2b-4, epic #2180)
 * ============================================================================
 *
 * ELI5
 * ----
 * Until the new code is on every site, the web's display settings live in two places: the old shelf
 * `ihymns.web` in the account's settings box (what the live site and old apps read and write) and the new
 * profile. Two small rules keep them telling the same story: a change old code made to the shelf is copied into
 * the profile the next time anyone asks (and a key that is MISSING from the shelf never deletes anything), and a
 * change made through the profile is copied onto the shelf straight away (and is not mistaken for old code's change
 * and bounced back). This test calls the real code with a real database.
 *
 * Detailed (rule #34)
 * -------------------
 *  - PURE: the text <-> value converters (every type, '1' and 'true', garbage refused, a round trip for every
 *    entry that can travel, derived from the registry), the shelf fingerprint (ignores keys the bridge does not
 *    own, ignores order), the shelf-to-changes step (never a delete), the shelf-with-writes step (a delete becomes
 *    the empty text; a non-bridge entry is never written).
 *  - DATABASE: a change made by old code appears in the next read; a second read does nothing (no lock, no new
 *    version); a copy does not bounce back; a missing key never deletes; a bad value is skipped; the other
 *    shelves of the box (iLyricsDB, list sorts) survive; the box is written only through the locked writer.
 * Mutation proof (in the 2b-4 commit): let a missing shelf key produce a delete -> red.
 *
 *   IHYMNS_TEST_DSN="host=127.0.0.1;user=root;pass=" php tests/php/test-profile-web-bridge.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/profile_sync.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : ' - ' . substr($detail, 0, 500)) . "\n";
    if (!$cond) { $fail++; }
}

echo "the converters\n";
$E = fn(string $s, string $k) => profileRegistryEntry($s, $k);
chk('a true/false value is read from "true" or "1" and false from "false" or "0"; anything else is refused',
    profileWebDecode($E('appearance', 'reduceMotion'), 'true') === true && profileWebDecode($E('appearance', 'reduceMotion'), '1') === true
    && profileWebDecode($E('appearance', 'reduceMotion'), 'false') === false && profileWebDecode($E('appearance', 'reduceMotion'), '0') === false
    && profileWebDecode($E('appearance', 'reduceMotion'), 'TRUE') === null && profileWebDecode($E('appearance', 'reduceMotion'), 'yes') === null && profileWebDecode($E('appearance', 'reduceMotion'), '') === null);
chk('a true/false value is written as "true" or "false"', profileWebEncode($E('appearance', 'reduceMotion'), true) === 'true' && profileWebEncode($E('appearance', 'reduceMotion'), false) === 'false');
chk('a whole number is read as a number and checked against the range and step',
    profileWebDecode($E('appearance', 'fontSize'), '18') === 18 && profileWebDecode($E('appearance', 'fontSize'), '17') === null && profileWebDecode($E('appearance', 'fontSize'), '30') === null
    && profileWebDecode($E('appearance', 'fontSize'), '18.0') === null && profileWebDecode($E('appearance', 'fontSize'), 'abc') === null);
chk('a choice must be one of the listed ones', profileWebDecode($E('appearance', 'theme'), 'dark') === 'dark' && profileWebDecode($E('appearance', 'theme'), 'solarized') === null && profileWebDecode($E('appearance', 'theme'), '') === null);
chk('an object is read from JSON and checked key by key; a list is not an object and an object is not a list',
    profileWebDecode($E('reading', 'display'), '{"fontSize":1.5}') === ['fontSize' => 1.5] && profileWebDecode($E('reading', 'display'), '{"fontSize":99}') === null
    && profileWebDecode($E('reading', 'display'), '[]') === null && profileWebDecode($E('reading', 'display'), '{}') === []
    && profileWebDecode($E('library', 'recentSongbooks'), '["MP","SDAH"]') === ['MP', 'SDAH'] && profileWebDecode($E('library', 'recentSongbooks'), '{}') === null);
chk('an object is written as compact JSON, an empty one as {}', profileWebEncode($E('reading', 'display'), ['fontSize' => 1.5]) === '{"fontSize":1.5}' && profileWebEncode($E('reading', 'display'), []) === '{}');
/* round trip for every entry that can travel, from the registry (rule #34) */
$bridge = profileWebEntries();
chk('the bridge covers exactly the display settings that travel in the shared cookie (' . count($bridge) . ')', count($bridge) >= 14 && count(array_filter(profileRegistry(), fn($e) => $e['cookie'])) === count($bridge));
$bad = [];
foreach ($bridge as $legacy => $e) {
    $sample = match ($e['type']) {
        'bool' => true, 'int' => $e['min'], 'float' => (float)$e['min'], 'enum' => $e['allowed'][0], 'string' => 'MP',
        'json' => ['lineSpacing' => 'normal'], default => null,
    };
    $back = profileWebDecode($e, profileWebEncode($e, $sample));
    if ($back !== $sample) { $bad[] = $e['id']; }
}
chk('write then read gives the same value back for every entry that travels', $bad === [], implode(',', $bad));

echo "the shelf\n";
$h1 = profileWebShelfHash(['ihymns_theme' => 'dark', 'ihymns_fontSize' => '18']);
chk('the fingerprint ignores keys the bridge does not own and the order', $h1 === profileWebShelfHash(['ihymns_fontSize' => '18', 'zzz_other' => 'x', 'ihymns_theme' => 'dark']));
chk('...and changes when a value changes, appears or disappears', $h1 !== profileWebShelfHash(['ihymns_theme' => 'light', 'ihymns_fontSize' => '18']) && $h1 !== profileWebShelfHash(['ihymns_theme' => 'dark']) && $h1 !== profileWebShelfHash(['ihymns_theme' => 'dark', 'ihymns_fontSize' => '18', 'ihymns_reduceMotion' => 'true']));
chk('a shelf of non-text values (an array) does not break the fingerprint', is_string(profileWebShelfHash(['ihymns_theme' => ['x']])));
$ch = profileWebShelfToChanges(['ihymns_theme' => 'dark', 'ihymns_fontSize' => '17', 'ihymns_reduceMotion' => 'true', 'ihymns_display' => '{"fontSize":9}', 'ihymns_transition' => '', 'unrelated' => 'x']);
chk('a shelf stands for a set per VALID value; a bad, empty or unrelated one yields nothing, and there is never a delete',
    array_column($ch, 'key') === ['theme', 'reduceMotion'] && count(array_filter($ch, fn($c) => $c['op'] !== 'set')) === 0);
chk('a shelf with nothing in it yields no changes at all (a missing key is not a delete)', profileWebShelfToChanges([]) === []);
$w = profileWebShelfWithWrites(['ihymns_theme' => 'light'], [
    ['section' => 'appearance', 'key' => 'theme', 'json' => '"dark"', 'deleted' => false],
    ['section' => 'appearance', 'key' => 'fontSize', 'json' => '22', 'deleted' => false],
    ['section' => 'chords', 'key' => 'notation', 'json' => '"nashville"', 'deleted' => false],
    ['section' => 'reading', 'key' => 'display', 'json' => null, 'deleted' => true],
]);
chk('accepted writes land on the shelf as text; a non-bridge entry is never copied; a delete becomes the empty text',
    $w['changed'] === true && $w['shelf'] === ['ihymns_theme' => 'dark', 'ihymns_fontSize' => '22', 'ihymns_display' => ''], json_encode($w['shelf']));
$w2 = profileWebShelfWithWrites(['ihymns_theme' => 'dark'], [['section' => 'appearance', 'key' => 'theme', 'json' => '"dark"', 'deleted' => false]]);
chk('writing what is already there changes nothing', $w2['changed'] === false);

$skipShelf = ['ihymns_theme' => 'dark', 'ihymns_fontSize' => '20'];
chk('a shelf value still exactly as it was when a copy onto it was refused is stale and stands for nothing; a different value or another key still does',
    array_column(profileWebShelfToChanges($skipShelf, ['ihymns_theme' => 'dark']), 'key') === ['fontSize']
    && array_column(profileWebShelfToChanges(['ihymns_theme' => 'light', 'ihymns_fontSize' => '20'], ['ihymns_theme' => 'dark']), 'key') === ['theme', 'fontSize']
    && array_column(profileWebShelfToChanges($skipShelf, ['ihymns_theme' => null]), 'key') === ['theme', 'fontSize']);
chk('the stale list keeps an entry only while the shelf still holds that exact text',
    profileWebShelfSkipPrune(['ihymns_theme' => 'dark'], ['ihymns_theme' => 'dark', 'ihymns_fontSize' => '20']) === ['ihymns_theme' => 'dark']
    && profileWebShelfSkipPrune(['ihymns_theme' => 'light'], ['ihymns_theme' => 'dark']) === [] && profileWebShelfSkipPrune([], ['ihymns_theme' => null]) === ['ihymns_theme' => null]);
chk('the copy reports which old keys it changed', profileWebShelfWithWrites(['ihymns_theme' => 'light'], [['section' => 'appearance', 'key' => 'theme', 'json' => '"dark"', 'deleted' => false], ['section' => 'appearance', 'key' => 'fontSize', 'json' => '22', 'deleted' => false]])['keys'] === ['ihymns_theme', 'ihymns_fontSize']);

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
    echo "  SKIP  no database - the bridge was NOT exercised. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
    exit($fail === 0 ? 0 : 1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'ihymns_t2b4_bridge';
$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->set_charset('utf8mb4');
$schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
try {
    $db->query('CREATE TABLE tblUsers (Id INT UNSIGNED NOT NULL PRIMARY KEY, Settings JSON NULL, UpdatedAt DATETIME NULL) ENGINE=InnoDB');
    $db->query('INSERT INTO tblUsers (Id) VALUES (1), (2), (3)');
    preg_match('/CREATE TABLE IF NOT EXISTS tblUserProfileItems \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $mt);
    $db->query($mt[0]);

    $box = fn(int $uid) => json_decode((string)$db->query("SELECT Settings FROM tblUsers WHERE Id = {$uid}")->fetch_row()[0], true) ?: [];
    $shelf = fn(int $uid) => $box($uid)['ihymns.web'] ?? [];
    /** What "old code" does: replace the whole ihymns.web shelf through the one locked writer (as the old settings page does). */
    $oldCodeWrites = function (int $uid, array $newShelf) use ($db): void {
        userSettingsMutate($db, $uid, static fn(array $c) => userSettingsMergeNamespace($c, 'ihymns.web', $newShelf));
    };
    $maxVersion = fn(int $uid) => (int)$db->query("SELECT COALESCE(MAX(Version),0) FROM tblUserProfileItems WHERE UserId = {$uid}")->fetch_row()[0];
    $items = function (int $uid) use ($db): array {
        $o = [];
        foreach ($db->query("SELECT Section, ItemKey, ValueJson, DeletedAt, Version FROM tblUserProfileItems WHERE UserId = {$uid} AND Section <> '_meta' ORDER BY Version")->fetch_all(MYSQLI_ASSOC) as $r) {
            $o[$r['Section'] . '.' . $r['ItemKey']] = ['v' => $r['ValueJson'] === null ? null : json_decode($r['ValueJson'], true), 'deleted' => $r['DeletedAt'] !== null, 'version' => (int)$r['Version']];
        }
        return $o;
    };

    /* a person whose box already holds an old shelf, plus other shelves that must survive */
    $db->query("UPDATE tblUsers SET Settings = " . "'" . $db->real_escape_string(json_encode([
        'ihymns.web' => ['ihymns_theme' => 'dark', 'ihymns_fontSize' => '20', 'ihymns_reduceMotion' => 'true', 'ihymns_display' => '{"fontSize":1.5}', 'ihymns_transition' => 'zzz', 'ihymns_default_songbook' => 'MP-1'],
        'ilyricsdb'  => ['mode' => 'edit'], 'list_sorts' => ['songs' => 'title'], 'ihymns.push' => ['on' => true],
    ])) . "' WHERE Id = 1");

    /* ---- old shelf -> profile ---- */
    $looked = profileSyncReconcileIfChanged($db, 1);
    $it = $items(1);
    chk('the first read brings in the old shelf: every VALID value, with real types', $looked === true && ($it['appearance.theme']['v'] ?? null) === 'dark' && ($it['appearance.fontSize']['v'] ?? null) === 20 && ($it['appearance.reduceMotion']['v'] ?? null) === true && ($it['reading.display']['v'] ?? null) === ['fontSize' => 1.5], json_encode($it));
    chk('...and skips the invalid ones (an unknown transition, a songbook that is not an abbreviation)', !isset($it['appearance.transition']) && !isset($it['library.defaultSongbook']));
    chk('...recording a fingerprint that a pull never returns', profileSyncMetaRead($db, 1, PROFILE_SYNC_WEB_HASH_KEY) === profileWebShelfHash($shelf(1)) && !in_array('_meta', array_column(profileSyncPull($db, 1, 0)['changes'], 'section'), true));
    $v0 = $maxVersion(1);
    chk('a second read does nothing (the fingerprint matches: no lock, no new version)', profileSyncReconcileIfChanged($db, 1) === false && $maxVersion(1) === $v0);
    chk('the other shelves of the box are untouched', $box(1)['ilyricsdb'] === ['mode' => 'edit'] && $box(1)['list_sorts'] === ['songs' => 'title'] && $box(1)['ihymns.push'] === ['on' => true]);

    /* old code changes ONE value (and sends its whole shelf, as it always does) */
    $oldCodeWrites(1, ['ihymns_theme' => 'light', 'ihymns_fontSize' => '20', 'ihymns_reduceMotion' => 'true', 'ihymns_display' => '{"fontSize":1.5}']);
    profileSyncReconcileIfChanged($db, 1);
    $it2 = $items(1);
    chk('a change made by old code appears in the next read, and ONLY the changed item gets a new version',
        $it2['appearance.theme']['v'] === 'light' && $it2['appearance.theme']['version'] > $v0 && $it2['appearance.fontSize']['version'] === $it['appearance.fontSize']['version'] && $it2['reading.display']['version'] === $it['reading.display']['version'], json_encode($it2));

    /* a key MISSING from the shelf never deletes */
    $oldCodeWrites(1, ['ihymns_theme' => 'light']);          /* an old app that only has a theme */
    profileSyncReconcileIfChanged($db, 1);
    $it3 = $items(1);
    chk('a key missing from the shelf deletes nothing in the profile', ($it3['appearance.fontSize']['deleted'] ?? true) === false && ($it3['reading.display']['deleted'] ?? true) === false && ($it3['appearance.reduceMotion']['v'] ?? null) === true);
    $oldCodeWrites(1, []);
    profileSyncReconcileIfChanged($db, 1);
    chk('...not even when the whole shelf is emptied', count(array_filter($items(1), fn($x) => $x['deleted'])) === 0);

    /* ---- profile -> old shelf ---- */
    $r = profileSyncApply($db, 1, [
        ['section' => 'appearance', 'key' => 'theme', 'op' => 'set', 'value' => 'high-contrast'],
        ['section' => 'appearance', 'key' => 'fontSize', 'op' => 'set', 'value' => 22],
        ['section' => 'chords', 'key' => 'notation', 'op' => 'set', 'value' => 'nashville'],
        ['section' => 'appearance', 'key' => 'reduceMotion', 'op' => 'delete'],
    ], 'web');
    $sh = $shelf(1);
    chk('an accepted display setting is copied onto the old shelf as text; a delete becomes the empty text; a chord setting is not copied',
        $r['applied'] === 4 && ($sh['ihymns_theme'] ?? null) === 'high-contrast' && ($sh['ihymns_fontSize'] ?? null) === '22' && ($sh['ihymns_reduceMotion'] ?? null) === '' && !isset($sh['ihymns_chord_notation']), json_encode($sh));
    chk('the other shelves of the box survived the copy', $box(1)['ilyricsdb'] === ['mode' => 'edit'] && $box(1)['list_sorts'] === ['songs' => 'title']);
    chk('the fingerprint was refreshed, so the copy is not mistaken for a change by old code', profileSyncMetaRead($db, 1, PROFILE_SYNC_WEB_HASH_KEY) === profileWebShelfHash($sh));
    $vAfter = $maxVersion(1); $before = $items(1);
    $looked = profileSyncReconcileIfChanged($db, 1);
    chk('...and the next read finds nothing to do: the copy does not bounce back', $looked === false && $maxVersion(1) === $vAfter && $items(1) == $before);
    $r2 = profileSyncApply($db, 1, [], 'web');
    chk('even a plain save with nothing to change brings in nothing', $r2['applied'] === 0 && $maxVersion(1) === $vAfter);
    $it4 = $items(1);
    chk('the delete stays a delete: the empty text on the shelf is not read back as a value', $it4['appearance.reduceMotion']['deleted'] === true);

    /* an old app that changes the shelf BETWEEN two saves is brought in first, then the new change wins */
    $oldCodeWrites(1, ['ihymns_theme' => 'dark', 'ihymns_fontSize' => '22']);
    profileSyncApply($db, 1, [['section' => 'appearance', 'key' => 'theme', 'op' => 'set', 'value' => 'light']], 'web');
    $it5 = $items(1);
    chk('old code wrote theme=dark, then a new change theme=light arrived: the later arrival (light) wins, in the profile and on the shelf', $it5['appearance.theme']['v'] === 'light' && ($shelf(1)['ihymns_theme'] ?? null) === 'light');

    /* a copy that would make the shelf too big is refused, not truncated */
    $big = ['ihymns_display' => '{"fontSize":1.5}'];
    for ($i = 0; $i < 1300; $i++) { $big['pad' . $i] = str_repeat('x', 12); }
    $db->query("UPDATE tblUsers SET Settings = '" . $db->real_escape_string(json_encode(['ihymns.web' => $big])) . "' WHERE Id = 2");
    $before2 = $shelf(2);
    $r3 = profileSyncApply($db, 2, [['section' => 'appearance', 'key' => 'theme', 'op' => 'set', 'value' => 'dark']], 'web');
    chk('a shelf that would go over its size limit is left exactly as it was (the profile still saves)', $r3['applied'] >= 1 && $shelf(2) === $before2 && !isset($shelf(2)['ihymns_theme']));

    /* a refused copy must not let the stale shelf value come back over the newer profile value (2b review fix 10) */
    $fill = function (array $base): array {            /* pad the shelf to exactly the limit: the biggest padding the box accepts */
        for ($len = USER_SETTINGS_NS_MAX_BYTES; $len > 0; $len--) {
            $try = $base + ['pad' => str_repeat('x', $len)];
            if (userSettingsRejectReason([], PROFILE_SYNC_WEB_NAMESPACE, $try) === null) { return $try; }
        }
        return $base;
    };
    $full = $fill(['ihymns_theme' => 'dark', 'ihymns_fontSize' => '20']);
    $db->query("UPDATE tblUsers SET Settings = '" . $db->real_escape_string(json_encode(['ihymns.web' => $full])) . "' WHERE Id = 3");
    profileSyncApply($db, 3, [], 'web');               /* the first look: brings the shelf's theme dark and size 20 in */
    $r4 = profileSyncApply($db, 3, [['section' => 'appearance', 'key' => 'theme', 'op' => 'set', 'value' => 'high-contrast']], 'web');
    chk('with the box full, the new theme is saved in the profile but not copied to the shelf', $r4['applied'] === 1 && $items(3)['appearance.theme']['v'] === 'high-contrast' && $shelf(3)['ihymns_theme'] === 'dark');
    chk('...and the refused copy is noted: the shelf\'s stale theme text is recorded', (profileSyncMetaRead($db, 3, PROFILE_SYNC_WEB_SKIP_KEY)['ihymns_theme'] ?? null) === 'dark');
    $v3 = $maxVersion(3);
    $oldCodeWrites(3, ['ihymns_fontSize' => '22'] + $full);   /* an old app changes ANOTHER key (and sends its whole shelf, with the stale theme) */
    profileSyncReconcileIfChanged($db, 3);
    $i3 = $items(3);
    chk('the stale theme on the shelf does NOT come back over the newer profile theme, while the other change IS brought in', $i3['appearance.theme']['v'] === 'high-contrast' && $i3['appearance.fontSize']['v'] === 22, json_encode($i3));
    $oldCodeWrites(3, ['ihymns_theme' => 'light', 'ihymns_fontSize' => '22'] + $full);   /* now old code really chooses a theme */
    profileSyncReconcileIfChanged($db, 3);
    chk('but when old code writes a DIFFERENT theme, that is the person\'s choice and is brought in; the note is dropped', $items(3)['appearance.theme']['v'] === 'light' && (profileSyncMetaRead($db, 3, PROFILE_SYNC_WEB_SKIP_KEY) ?? []) === []);

    /* the writer rule: no direct write to the box in the bridge */
    $src = (string)file_get_contents($web . '/includes/profile_sync.php');
    $code = ''; foreach (token_get_all($src) as $t) { if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $code .= ' ' . $t[1]; } }
    chk('the bridge never writes the box itself (only through userSettingsMutateLocked)', !preg_match('/UPDATE\s+tblUsers/i', $code) && str_contains($src, 'userSettingsMutateLocked('));
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
}

exit($fail === 0 ? 0 : 1);
