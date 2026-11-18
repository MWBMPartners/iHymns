<?php

declare(strict_types=1);

/**
 * iHymns - the profile registry is well-formed and keeps device data on the device (C4, epic #2180)
 *
 * ELI5
 * ----
 * includes/profile_registry.php is the one list of every personal setting and its
 * rules. This guard checks the list is internally honest: every word it uses is
 * from the allowed vocabulary, every default is itself a valid value, a setting
 * that must stay on the device is not secretly wired to an account store, things
 * that travel in the shared cookie are web account settings, and only the saved
 * content kinds are cleared at sign-out. It also CALLS the validator with good and
 * bad values.
 *
 * Detailed (rule #34): nothing here lists the entries. Every check loops over
 * whatever the registry holds, so a new entry is checked the moment it exists. The
 * content tables the registry names are checked against schema.sql. Mutation proofs
 * are in the C4 commit: giving the MIDI pedal account scope, adding an entry with
 * an unknown platform, and a default that is not valid each turn this red.
 *
 *   php tests/php/test-profile-registry.php
 */

$repo = dirname(__DIR__, 2);
require_once $repo . '/appWeb/public_html/includes/profile_registry.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

$reg = profileRegistry();
chk('the registry holds entries (' . count($reg) . ')', count($reg) >= 30);
chk('the schema version is a positive whole number', profileRegistryVersion() >= 1);

$FIELDS = ['id', 'section', 'key', 'type', 'default', 'scope', 'platforms', 'storage', 'sync', 'cap', 'export', 'erase', 'claim', 'clearAtSignOut', 'cookie', 'legacyWebKey', 'since'];
$schema = (string)file_get_contents($repo . '/appWeb/.sql/schema.sql');
$legacySeen = [];
$bad = [];
foreach ($reg as $id => $e) {
    $why = [];
    foreach ($FIELDS as $f) { if (!array_key_exists($f, $e)) { $why[] = "missing field $f"; } }
    if (($e['id'] ?? '') !== $id || $id !== ($e['section'] ?? '') . '.' . ($e['key'] ?? '')) { $why[] = 'id is not section.key'; }
    if (!preg_match('/^[a-z][A-Za-z0-9]*$/', (string)($e['section'] ?? '')) || !preg_match('/^[a-z][A-Za-z0-9]*$/', (string)($e['key'] ?? ''))) { $why[] = 'section/key naming'; }
    if (!in_array($e['type'] ?? '', PROFILE_TYPES, true)) { $why[] = 'unknown type'; }
    if (!in_array($e['scope'] ?? '', PROFILE_SCOPES, true)) { $why[] = 'unknown scope'; }
    if (!in_array($e['storage'] ?? '', PROFILE_STORAGES, true)) { $why[] = 'unknown storage'; }
    if (!in_array($e['sync'] ?? '', PROFILE_SYNCS, true)) { $why[] = 'unknown sync'; }
    if (!in_array($e['erase'] ?? '', PROFILE_ERASES, true)) { $why[] = 'unknown erase'; }
    $pl = (array)($e['platforms'] ?? []);
    if ($pl === [] || ($pl !== ['all'] && array_diff($pl, PROFILE_PLATFORMS) !== [])) { $why[] = 'unknown platform'; }
    if (!is_int($e['since'] ?? null) || $e['since'] < 1 || $e['since'] > profileRegistryVersion()) { $why[] = 'since out of range'; }
    foreach (['export', 'claim', 'clearAtSignOut', 'cookie'] as $b) { if (!is_bool($e[$b] ?? null)) { $why[] = "$b is not a bool"; } }
    if ($e['legacyWebKey'] !== null) {
        if (isset($legacySeen[$e['legacyWebKey']])) { $why[] = 'legacyWebKey shared with ' . $legacySeen[$e['legacyWebKey']]; }
        $legacySeen[$e['legacyWebKey']] = $id;
    }
    /* constraints */
    switch ($e['type'] ?? '') {
        case 'enum': if (empty($e['allowed']) || count($e['allowed']) !== count(array_unique($e['allowed']))) { $why[] = 'enum needs unique allowed[]'; } break;
        case 'int': case 'float': if (!isset($e['min'], $e['max']) || $e['min'] > $e['max']) { $why[] = 'min/max'; } break;
        case 'list': if (!isset($e['item']['type'])) { $why[] = 'list needs item'; } break;
    }
    /* default valid */
    if (($e['default'] ?? null) !== null && !profileValidateSpec($e, $e['default'])) { $why[] = 'default is not a valid value'; }
    /* scope rules */
    if ($e['scope'] === 'device') {
        foreach (['storage' => 'none', 'sync' => 'none', 'erase' => 'none'] as $f => $v) { if ($e[$f] !== $v) { $why[] = "device entry has $f={$e[$f]}"; } }
        foreach (['export', 'claim', 'clearAtSignOut', 'cookie'] as $b) { if ($e[$b] !== false) { $why[] = "device entry has $b=true"; } }
    } else {
        if ($e['storage'] === 'none' || $e['sync'] === 'none') { $why[] = 'account entry has no storage or sync'; }
    }
    if ($e['sync'] === 'profile_sync' && $e['storage'] !== 'profile_items') { $why[] = 'profile_sync entry not stored in profile_items'; }
    if ($e['sync'] === 'content_sync' && !str_starts_with($e['storage'], 'table:')) { $why[] = 'content_sync entry not stored in a table'; }
    if (str_starts_with($e['storage'], 'table:')) {
        $tbl = substr($e['storage'], 6);
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($tbl, '/') . ' \(/', $schema)) { $why[] = "table $tbl not in schema.sql"; }
    }
    /* the cookie rule (#52): only web account settings, with an old web key, can travel */
    if ($e['cookie'] === true) {
        if ($e['scope'] !== 'account' || !in_array('web', $pl, true) && $pl !== ['all'] || $e['legacyWebKey'] === null) { $why[] = 'cookie entry must be a web account setting with a legacyWebKey'; }
        if ($e['section'] === 'content' || $e['section'] === 'device') { $why[] = 'content/device must never travel in the cookie'; }
    }
    /* sign-out clearing: saved content only, never a display setting */
    if ($e['clearAtSignOut'] === true && !($e['scope'] === 'account' && str_starts_with($e['storage'], 'table:'))) { $why[] = 'only saved-content kinds clear at sign-out'; }
    if ($why) { $bad[$id] = implode('; ', $why); }
}
chk('every entry is well-formed (vocabulary, ids, defaults, scope rules, cookie and sign-out rules)', $bad === [], json_encode($bad));

/* the content kinds that must clear at sign-out (decision O2) */
$clears = array_keys(array_filter($reg, fn($e) => $e['clearAtSignOut']));
sort($clears);
chk('sign-out clears exactly the saved content kinds: favourites, set lists, custom tags, history',
    $clears === ['content.customTags', 'content.favorites', 'content.history', 'content.setlists'], implode(',', $clears));
chk('no display setting is cleared at sign-out', count(array_filter($reg, fn($e) => $e['clearAtSignOut'] && $e['section'] !== 'content')) === 0);

/* the decision O5 setting */
$mv = profileRegistryEntry('home', 'mostViewedPeriod');
chk('home.mostViewedPeriod exists: 30d|90d|12m|all, default 12m, account scope, all platforms',
    $mv !== null && $mv['allowed'] === ['30d', '90d', '12m', 'all'] && $mv['default'] === '12m' && $mv['scope'] === 'account' && $mv['platforms'] === ['all']);

/* ---- the validator, called ---- */
chk('theme: a listed value is valid, an unlisted one is not', profileRegistryValidate('appearance', 'theme', 'dark') && !profileRegistryValidate('appearance', 'theme', 'solarized'));
chk('theme: a non-string is not valid', !profileRegistryValidate('appearance', 'theme', 1) && !profileRegistryValidate('appearance', 'theme', null));
chk('fontSize: 14 and 28 and the steps between are valid; 15, 13 and 30 are not',
    profileRegistryValidate('appearance', 'fontSize', 14) && profileRegistryValidate('appearance', 'fontSize', 28) && profileRegistryValidate('appearance', 'fontSize', 20)
    && !profileRegistryValidate('appearance', 'fontSize', 15) && !profileRegistryValidate('appearance', 'fontSize', 13) && !profileRegistryValidate('appearance', 'fontSize', 30)
    && !profileRegistryValidate('appearance', 'fontSize', '18'));
chk('bool: real booleans only (the text "true" is refused)', profileRegistryValidate('appearance', 'reduceMotion', true) && !profileRegistryValidate('appearance', 'reduceMotion', 'true'));
chk('defaultSongbook: 1 to 10 letters or digits, nothing else (including a trailing newline)',
    profileRegistryValidate('library', 'defaultSongbook', 'MP') && !profileRegistryValidate('library', 'defaultSongbook', 'MP-1')
    && !profileRegistryValidate('library', 'defaultSongbook', "MP\n") && !profileRegistryValidate('library', 'defaultSongbook', '') && !profileRegistryValidate('library', 'defaultSongbook', 'ABCDEFGHIJK'));
chk('idleTimeoutMins: 5 to 240', profileRegistryValidate('live', 'idleTimeoutMins', 5) && profileRegistryValidate('live', 'idleTimeoutMins', 240) && !profileRegistryValidate('live', 'idleTimeoutMins', 4) && !profileRegistryValidate('live', 'idleTimeoutMins', 241));
chk('display: only the five known keys, each in range',
    profileRegistryValidate('reading', 'display', ['fontSize' => 1.5, 'lineSpacing' => 'normal'])
    && !profileRegistryValidate('reading', 'display', ['fontSize' => 9])
    && !profileRegistryValidate('reading', 'display', ['mystery' => true])
    && !profileRegistryValidate('reading', 'display', ['lineSpacing' => 'tight'])
    && !profileRegistryValidate('reading', 'display', ['a', 'b']));
chk('cvd: a real mode is valid; "none" is not (no value is a delete, not a set)', profileRegistryValidate('appearance', 'cvd', 'protanopia') && !profileRegistryValidate('appearance', 'cvd', 'none'));
chk('an unknown section or key is never valid', !profileRegistryValidate('nope', 'nothing', 'x') && profileRegistryEntry('appearance', 'nothing') === null);
chk('a list respects its item type and cap', profileRegistryValidate('content', 'favorites', ['MP-1', 'MP-2']) && !profileRegistryValidate('content', 'favorites', [1]) && !profileRegistryValidate('content', 'favorites', ['a' => 'b']) && !profileRegistryValidate('content', 'favorites', array_fill(0, 501, 'MP-1')));

/* ---- the public projection ---- */
$pub = profileRegistryPublic();
chk('the public projection has the version and every entry', $pub['schemaVersion'] === profileRegistryVersion() && count($pub['entries']) === count($reg));
$leak = [];
foreach ($pub['entries'] as $e) { foreach (PROFILE_SERVER_ONLY_FIELDS as $f) { if (array_key_exists($f, $e)) { $leak[] = $e['id'] . '.' . $f; } } }
chk('no server-only field (storage, export, erase) reaches the public projection', $leak === [], implode(',', $leak));
chk('the public projection keeps every client-facing field', count(array_filter($pub['entries'], fn($e) => !array_key_exists('clearAtSignOut', $e) || !array_key_exists('cookie', $e) || !array_key_exists('scope', $e))) === 0);
chk('the public projection encodes to JSON', is_string(json_encode($pub)));

exit($fail === 0 ? 0 : 1);
