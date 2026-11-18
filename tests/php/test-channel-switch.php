<?php

declare(strict_types=1);

/**
 * iHymns - the shared per-channel switch and the account-writes switch (C1, epic #2180)
 *
 * ELI5
 * ----
 * Two helpers decide "is this switch on for this copy of the site?":
 * channelSwitchAllows() (is the channel's name in the list?) and
 * channelSwitchEnabledHere() (read the list, and say what to do if it cannot be
 * read). The account-writes switch is built on them with its own rules: ON for
 * all three sites if nothing is stored, and if it cannot be read, ON for
 * production and OFF for alpha and beta. This file CALLS them with every
 * interesting list and checks the answers, then checks the admin card is wired
 * safely.
 *
 * Detailed (rule #34): the setting key is searched for across the whole tree (it
 * must appear in exactly one file); the handler is read with comments removed;
 * and cross_site_sync.php is checked to still delegate rather than carry its own
 * copy. Mutation proofs are in the C1 commit: making an unreadable switch
 * refuse production, changing the default to 'none', or removing the typed
 * confirmation each turns this red.
 *
 *   php tests/php/test-channel-switch.php
 */

$repo = dirname(__DIR__, 2);
$web = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/channel_switch.php';
require_once $web . '/includes/account_write_gate.php';
require_once $web . '/includes/cross_site_sync.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}
function code(string $src): string
{
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { $o .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1]; } else { $o .= $t; }
    }
    return $o;
}

/* ---- channelSwitchAllows ---- */
echo "channelSwitchAllows()\n";
chk('"none" and "" are off everywhere', !channelSwitchAllows('none', 'production') && !channelSwitchAllows('', 'alpha'));
chk('junk words are off', !channelSwitchAllows('yes,all,foo', 'beta'));
chk('one channel is on for that channel only', channelSwitchAllows('beta', 'beta') && !channelSwitchAllows('beta', 'alpha') && !channelSwitchAllows('beta', 'production'));
chk('case and spaces do not matter', channelSwitchAllows(' Alpha , BETA ', 'beta') && channelSwitchAllows('PRODUCTION', 'production'));
chk('the channel itself is compared case-insensitively', channelSwitchAllows('production', ' Production '));
chk('all three', channelSwitchAllows('production,beta,alpha', 'alpha'));

/* ---- channelSwitchEnabledHere ---- */
echo "channelSwitchEnabledHere()\n";
$ok = fn(string $csv) => static fn(string $k, string $d): string => $csv;
$boom = static function (string $k, string $d): string { throw new \RuntimeException('database down'); };
chk('reads the list and answers for the given channel', channelSwitchEnabledHere('k1', 'none', false, $ok('beta,alpha'), 'alpha') === true
    && channelSwitchEnabledHere('k1', 'none', false, $ok('beta,alpha'), 'production') === false);
chk('passes the key and default to the reader', channelSwitchEnabledHere('mykey', 'dflt', false, static fn($k, $d) => $k === 'mykey' && $d === 'dflt' ? 'alpha' : 'none', 'alpha') === true);
chk('a failing read with onError=false is off', channelSwitchEnabledHere('k2', 'none', false, $boom, 'production') === false);
chk('a failing read with onError=true is on', channelSwitchEnabledHere('k2', 'none', true, $boom, 'alpha') === true);
chk('a failing read can ask a function, given the channel', channelSwitchEnabledHere('k2', 'none', static fn(string $ch): bool => $ch === 'beta', $boom, 'beta') === true
    && channelSwitchEnabledHere('k2', 'none', static fn(string $ch): bool => $ch === 'beta', $boom, 'alpha') === false);

/* ---- the REAL reader path (rule #34: a test must not only inject a reader the real one never has) ---- */
echo "the real reader\n";
/* Fake handles (duck-typed: getAppSettingStrict() only needs prepare()). */
class IhymnsFailingDb { public function prepare(string $q) { throw new mysqli_sql_exception('database down'); } }
class IhymnsEmptyResult { public function fetch_row() { return null; } }
class IhymnsEmptyStmt { public function bind_param(...$a) { return true; } public function execute() { return true; } public function get_result() { return new IhymnsEmptyResult(); } public function close() {} }
class IhymnsEmptyDb { public function prepare(string $q) { return new IhymnsEmptyStmt(); } }

$threw = false;
try { getAppSettingStrict('whatever', 'd', new IhymnsFailingDb()); } catch (\Throwable $_e) { $threw = true; }
chk('getAppSettingStrict() THROWS when a query fails', $threw);
chk('a genuinely missing row returns the default (not an error)', getAppSettingStrict('nope', 'production,beta,alpha', new IhymnsEmptyDb()) === 'production,beta,alpha');

/* No credentials in this test run, so the REAL getDbMysqli() throws: the real default path, not an injected reader. */
$threw = false;
try { getAppSettingStrict('whatever', 'd'); } catch (\Throwable $_e) { $threw = true; }
chk('on the real path, an unreachable database THROWS', $threw);
chk('getAppSetting() (the everyday one) swallows the same failure and returns the default - why the switch must not use it',
    getAppSetting('whatever_k', 'd') === 'd');
chk('no injected reader: an unreadable switch reaches onError (false)', channelSwitchEnabledHere('real_k1', 'none', false, null, 'alpha') === false);
chk('no injected reader: an unreadable switch reaches onError (true)', channelSwitchEnabledHere('real_k2', 'none', true, null, 'alpha') === true);
chk('account writes, real reader, unreadable database: alpha REFUSED', channelSwitchEnabledHere('real_k3', ACCOUNT_WRITES_DEFAULT, 'accountWritesOnReadError', null, 'alpha') === false);
chk('account writes, real reader, unreadable database: production ALLOWED', channelSwitchEnabledHere('real_k4', ACCOUNT_WRITES_DEFAULT, 'accountWritesOnReadError', null, 'production') === true);
chk('the switch reads through the strict reader, not getAppSetting()',
    str_contains(code((string)file_get_contents($web . '/includes/channel_switch.php')), 'getAppSettingStrict($key, $default)')
    && !str_contains(code((string)file_get_contents($web . '/includes/channel_switch.php')), 'getAppSetting('));

/* ---- saving a setting must not leave the page showing the old remembered value (review finding 5) ---- */
echo "the settings memory\n";
class IhymnsWriteDb extends mysqli
{
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query) { return new IhymnsWriteStmt(); }
}
class IhymnsWriteStmt { public function bind_param(...$a) { return true; } public function execute() { return true; } public function close() {} }
/* getAppSetting() cannot reach a database here, so it remembers the DEFAULT it was given, exactly like a remembered old value. */
chk('before the save, the remembered value is the old one', getAppSetting('mem_k', 'old') === 'old');
setAppSetting(new IhymnsWriteDb(), 'mem_k', 'new');
$memAfter = appSettingMemory();
chk('setAppSetting() forgets the key it just wrote (so the next read is fresh, not the remembered old value)', !array_key_exists('mem_k', $memAfter));
chk('...and only that key', array_key_exists('secret_encryption_active', $memAfter));
$confSrc = code((string)file_get_contents($web . '/manage/configuration.php'));
$hAt = strpos($confSrc, "\$action === 'save_account_writes'");
$cardAt = strpos($confSrc, '$accountWritesChannels = ihymns_parse_channels_csv(');
chk('the account-writes card reads its state AFTER the save handler runs', $hAt !== false && $cardAt !== false && $hAt < $cardAt);
chk('the card reads through getAppSetting() (which the save now refreshes), not a value captured before the save',
    $cardAt !== false && str_contains(substr($confSrc, $cardAt, 260), 'getAppSetting(ACCOUNT_WRITES_SETTING_KEY'));
chk('the save goes through setAppSetting() (the function that refreshes the memory)',
    (bool)preg_match('/\$saveSetting\s*=\s*static function[^{]*\{\s*setAppSetting\(/', $confSrc));

/* ---- cross_site_sync still behaves, by delegation ---- */
echo "cross_site_sync.php delegates\n";
$cs = code((string)file_get_contents($web . '/includes/cross_site_sync.php'));
chk('crossSiteSyncAllows() calls channelSwitchAllows()', str_contains($cs, 'channelSwitchAllows($csv, $channel)'));
chk('crossSiteSyncEnabledHere() calls channelSwitchEnabledHere() and fails closed', (bool)preg_match('/channelSwitchEnabledHere\(\s*CROSS_SITE_SYNC_SETTING_KEY\s*,\s*CROSS_SITE_SYNC_DEFAULT\s*,\s*false\s*\)/', $cs));
chk('it no longer carries its own copy of the channel list parse', !str_contains($cs, 'ihymns_parse_channels_csv('));

/* ---- the account-writes rules ---- */
echo "account writes\n";
chk('the key and default are the agreed ones', ACCOUNT_WRITES_SETTING_KEY === 'account_writes_channels' && ACCOUNT_WRITES_DEFAULT === 'production,beta,alpha');
chk('default with no row: all three channels may write',
    accountWritesAllowedHere($ok(ACCOUNT_WRITES_DEFAULT), 'production') && accountWritesAllowedHere($ok(ACCOUNT_WRITES_DEFAULT), 'beta') && accountWritesAllowedHere($ok(ACCOUNT_WRITES_DEFAULT), 'alpha'));
chk('no row at all (the reader returns the default it is given) lets every channel write',
    accountWritesAllowedHere(static fn($k, $d) => $d, 'alpha') === true);
chk('ticking only production refuses beta and alpha', accountWritesAllowedHere($ok('production'), 'production') && !accountWritesAllowedHere($ok('production'), 'beta') && !accountWritesAllowedHere($ok('production'), 'alpha'));
chk('"none" (nothing ticked) refuses every channel', !accountWritesAllowedHere($ok('none'), 'production') && !accountWritesAllowedHere($ok('none'), 'alpha'));
chk('unreadable: production is ALLOWED', accountWritesAllowedHere($boom, 'production') === true);
chk('unreadable: beta is REFUSED', accountWritesAllowedHere($boom, 'beta') === false);
chk('unreadable: alpha is REFUSED', accountWritesAllowedHere($boom, 'alpha') === false);
chk('the refusal carries the agreed reason code', accountWritesRefusalBody()['reason'] === 'account_writes_off_here' && ACCOUNT_WRITES_OFF_REASON === 'account_writes_off_here');

/* ---- the key is written once ---- */
echo "the key is written once\n";
$holders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/appWeb', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($repo) + 1);
    if (!$f->isFile() || !preg_match('/\.(php|js|html)$/', $rel) || str_contains($rel, 'private_html/lib/')) { continue; }
    if (preg_match('/[\'"]account_writes_channels[\'"]/', (string)@file_get_contents($f->getPathname()))) { $holders[] = $rel; }
}
chk('the quoted key appears in exactly one file under appWeb/ (' . implode(', ', $holders) . ')', $holders === ['appWeb/public_html/includes/account_write_gate.php']);

/* ---- the admin card ---- */
echo "the admin card\n";
$conf = code((string)file_get_contents($web . '/manage/configuration.php'));
$h = strpos($conf, "\$action === 'save_account_writes'");
chk('configuration.php has a save_account_writes handler', $h !== false);
$handler = $h === false ? '' : substr($conf, $h, 4200);
chk('it saves through the constant, never a retyped key', str_contains($handler, 'ACCOUNT_WRITES_SETTING_KEY'));
chk('it only accepts the three real channels', (bool)preg_match('/\$validAwChans\s*=\s*\[\s*[\'"]alpha[\'"]\s*,\s*[\'"]beta[\'"]\s*,\s*[\'"]production[\'"]\s*\]/', $handler) && str_contains($handler, 'in_array($c, $validAwChans, true)'));
chk('nothing ticked stores "none", never an empty string', (bool)preg_match('/\$awChansOut\s*===\s*\[\]\s*\?\s*[\'"]none[\'"]/', $handler));
chk('switching production off needs the typed phrase, checked BEFORE the save',
    str_contains($handler, '$awTurningProductionOff') && str_contains($handler, 'ACCOUNT_WRITES_PRODUCTION_OFF_PHRASE')
    && strpos($handler, '$awTyped !== ACCOUNT_WRITES_PRODUCTION_OFF_PHRASE') !== false
    && strpos($handler, '$saveSetting(') !== false
    && strpos($handler, '$awTyped !== ACCOUNT_WRITES_PRODUCTION_OFF_PHRASE') < strpos($handler, '$saveSetting('));
chk('the page is gated by manage_configuration and CSRF before any action', (bool)preg_match('/userHasEntitlement\(\s*[\'"]manage_configuration[\'"]/', $conf)
    && strpos($conf, "userHasEntitlement('manage_configuration'") < (int)$h && (bool)preg_match('/validateCsrf(Request)?\(/', substr($conf, 0, (int)$h)));
chk('the card posts to the same action', str_contains($conf, 'value="save_account_writes"'));
chk('the API-coverage guard lists it as web-only', (bool)preg_match('/[\'"]save_account_writes[\'"]\s*=>\s*[\'"]web_only:/', (string)file_get_contents($repo . '/tests/php/test-manage-action-api-coverage.php')));

exit($fail === 0 ? 0 : 1);
