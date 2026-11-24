<?php

declare(strict_types=1);

/**
 * iHymns - the privacy page says what the account features really do (PR 2c-4, epic #2180)
 *
 * ELI5
 * ----
 * The privacy page is a written promise. The buttons it points to must exist under the names it
 * uses, the choices it calls "off unless you turn them on" must really be off, and it must stay in
 * plain English (no table names, no file names, no code words). This guard reads the page, the
 * Settings page and the one settings registry and checks those match, so renaming a button or
 * changing a default fails here instead of leaving the page quietly wrong (rule #35).
 *
 * Detailed (rule #34)
 *  1. BUTTON NAMES. The labels of the "Your data" card's buttons and the searches row are read from
 *     includes/pages/settings.php (not typed here) and must each appear, word for word, in the
 *     privacy page, and the page must say where to find them (Settings, Your data).
 *  2. DEFAULTS. The registry says whether keeping recent searches in the account is off by default
 *     and how many are kept; the page must say "off" and the same number.
 *  3. NOTHING "PLANNED". The page must not call web deletion or export planned or future.
 *  4. PLAIN ENGLISH. No internal names: table names (`tbl...`), action names, file names, storage
 *     words, or the registry's own ids. (Cookie names the page has always shown to people are allowed.)
 *  5. THE DATE. A "Last updated: D Month YYYY" line is present and not in the future.
 *  6. THE ONE PLACE EACH PROMISE IS KEPT. Export and delete are real: the api.php cases exist and
 *     the web module that calls them exists. Sign-out clearing is real: the clearing function exists.
 *
 * Mutation proofs are in the 2c-4 commit: a button renamed in settings.php, the word "planned" put
 * back, a table name pasted into the page, and the default flipped each turn this red.
 *
 *   php tests/php/test-privacy-page-promises.php
 */

$repo = dirname(__DIR__, 2);
$web  = $repo . '/appWeb/public_html';
$_SERVER['SCRIPT_FILENAME'] = 'test';
require_once $web . '/includes/profile_registry.php';

$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}

$pagePath     = getenv('IHYMNS_PRIVACY_GUARD_PAGE') ?: $web . '/includes/pages/privacy.php';
$settingsPath = getenv('IHYMNS_PRIVACY_GUARD_SETTINGS') ?: $web . '/includes/pages/settings.php';
$page     = (string)file_get_contents($pagePath);
$settings = (string)file_get_contents($settingsPath);
/* The words a reader sees: tags removed, entities decoded, spaces folded. */
$text = trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)preg_replace('/<\?=.*?\?>/s', 'iHymns', $page)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

echo "1. the buttons the page points to\n";
$labels = [];
if (preg_match('/id="account-export-btn".*?<\/i>\s*(.*?)\s*<\/button>/s', $settings, $m)) { $labels['download'] = trim($m[1]); }
if (preg_match('/id="account-delete-open".*?<\/i>\s*(.*?)\s*<\/button>/s', $settings, $m)) { $labels['delete'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{2026}."); }
if (preg_match('/<strong>(Keep my recent searches in my account)<\/strong>/', $settings, $m)) { $labels['searches'] = $m[1]; }
chk('the three labels were found in settings.php', count($labels) === 3, json_encode($labels));
foreach ($labels as $name => $label) {
    chk("the privacy page uses the $name label \"$label\" word for word", $label !== '' && stripos($text, $label) !== false);
}
chk('the page says where to find the data tools (Settings, Your data)', preg_match('/Settings\s*>\s*Your data/', $text) === 1);
$cardTitle = preg_match('/id="settings-account-data-card".*?<\/i>\s*(Your data)\s*<\/h2>/s', $settings, $m) ? $m[1] : '';
chk('"Your data" is really the title of that Settings card', $cardTitle === 'Your data');

echo "2. defaults and numbers\n";
$reg = profileRegistry();
$opt = $reg['privacy.syncSearchHistory'] ?? null;
$list = $reg['history.searches'] ?? null;
chk('the registry says recent-search syncing is OFF by default', $opt !== null && $opt['default'] === false);
chk('the page says it is off unless the person turns it on', stripos($text, 'off unless you turn on') !== false);
chk('the page names the same number of searches the registry keeps (' . ($list['maxItems'] ?? '?') . ')', $list !== null && stripos($text, 'last ' . $list['maxItems'] . ' searches') !== false);

echo "3. nothing real is called planned\n";
chk('the page does not call web deletion or export "planned" or "coming"', preg_match('/\b(planned|coming soon|will be added|in future)\b/i', $text) === 0);
chk('the page says deletion is possible on the website', preg_match('/on the\s+website.*Delete my account/i', $text) === 1);

echo "4. plain English, no internals\n";
/* Only names that can only be internals. Words the page has always used for people (API key, hash, JSON) are not banned: a guard that fails on correct text gets weakened. */
$banned = '/\btbl[A-Z]\w*|profile_sync|account_export|account_delete|\baction=|localStorage|sessionStorage|IndexedDB|\.php\b|\bSELECT\b|\bUserId\b|ihymns_(?!auth\b|prefs\b|sync\b)\w+|\bflushNow|\bclearPersonalData/';
preg_match_all($banned, $text, $bad);
chk('no table, action, file or storage names in the page text', $bad[0] === [], implode(', ', array_unique($bad[0])));

echo "5. the date\n";
$dateOk = preg_match('/Last updated: (\d{1,2}) (January|February|March|April|May|June|July|August|September|October|November|December) (\d{4})/', $text, $dm) === 1;
chk('there is a "Last updated" line', $dateOk);
if ($dateOk) { chk('...and it is not in the future', strtotime($dm[1] . ' ' . $dm[2] . ' ' . $dm[3] . ' UTC') <= time() + 86400, $dm[0]); }

echo "6. the promises are real\n";
$api = (string)file_get_contents($web . '/api.php');
chk("api.php has the export and delete cases the page talks about", strpos($api, "case 'account_export'") !== false && strpos($api, "case 'account_delete'") !== false);
$mod = (string)file_get_contents($web . '/js/modules/account-data.js');
chk('the web module that calls them exists and calls both', strpos($mod, 'account_export') !== false && strpos($mod, 'account_delete') !== false);
$own = (string)file_get_contents($web . '/js/modules/account-data-owner.js');
chk('the sign-out clearing the page describes exists', strpos($own, 'export function clearPersonalDataAtSignOut') !== false);
$ua = (string)file_get_contents($web . '/js/modules/user-auth.js');
/* PR 2c review: "somewhere in the file" is not enough (it was also called from the account-deleted path). The call must sit
   inside the sign-out itself: logout() and the _logoutOnce() it hands over to. */
$jsBody = static function (string $src, string $name): string {
    if (preg_match('/^\s*(?:async\s+)?' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/m', $src, $mm, PREG_OFFSET_CAPTURE) !== 1) { return ''; }
    $o = $mm[0][1] + strlen($mm[0][0]) - 1; $depth = 0; $n = strlen($src);
    for ($i = $o; $i < $n; $i++) { if ($src[$i] === '{') { $depth++; } elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { return substr($src, $o, $i - $o + 1); } } }
    return '';
};
$strip = static fn(string $js): string => (string)preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"`])//[^\n]*#'], '', $js);
$logout = $strip($jsBody($ua, 'logout')) . $strip($jsBody($ua, '_logoutOnce'));
chk('...and sign-out really uses it: the clearing is called inside logout() / _logoutOnce(), not just somewhere in the file', $logout !== '' && strpos($logout, 'clearPersonalDataAtSignOut(localStorage)') !== false);
chk('...and the same sign-out deletes the set-aside copy', strpos($logout, 'removeStash(localStorage') !== false);

echo "7. the promises are worded so they stay true everywhere\n";
/* PR 2c review: three sentences were true only in some places (the typed word in the app, sign-out clearing on every site, "anything you save is kept" even while saving is paused). */
chk('the page does not say the iPhone/iPad/Apple TV app asks you to type DELETE (the app has no such step)',
    stripos($text, 'and type DELETE') === false && preg_match('/on the website you also type the word DELETE/i', $text) === 1);
$swift = (string)file_get_contents($repo . '/appApple/Packages/iHymnsKit/Sources/IHFeatures/AccountDeleteView.swift');
chk('...and the app\'s delete screen really has no typed-word step (if it gains one, this wording can change)', $swift !== '' && preg_match('/TextField\(\s*"[^"]*(DELETE|confirm)/i', $swift) === 0);
chk('the sign-out clearing is described as applying once saving to your account is switched on for the site (not on every site)', preg_match('/switched on for the site you are using, signing out on purpose also clears/i', $text) === 1 && stripos($text, 'leaves your lists in the browser, as it always did') !== false);
chk('"anything you save is kept in that one account" carries the paused-site exception', preg_match('/anything you save while signed in on one is kept in that one account\. \(If saving to accounts has been paused/i', $text) === 1);
chk('the page says a set-aside copy is deleted when you sign out on purpose', stripos($text, 'that set-aside copy is deleted as well') !== false);
$set = (string)file_get_contents($settingsPath);
chk('the Settings page comment does not claim the server re-checks the typed word for everybody', stripos($set, 're-checks both') === false && stripos($set, 'The typed word is checked HERE') !== false);
$yaml = (string)file_get_contents($web . '/api-docs.yaml');
chk('the OpenAPI limits sentence says the limits are per table', preg_match('/Limits, per table: at most 10,000 rows.*?and 32 MiB in all for the file/s', $yaml) === 1);

echo "8. the signed-in devices paragraph (PR 3, H7)\n";
/* Each sentence below is tied to the thing that makes it true. A reworded button, a changed lifetime or a switch that is no longer
   off by default fails here instead of leaving the page quietly wrong. */
require_once $web . '/includes/api_tokens.php';
require_once $web . '/includes/device_signin_gate.php';
require_once $repo . '/tests/php/lib/php_source_units.php';
$devJs = (string)file_get_contents($web . '/js/modules/devices.js');
$btn = [];
foreach (['SIGN_OUT_EVERYWHERE_LABEL', 'TURN_OFF_AUTO_SIGN_IN_LABEL'] as $const) {
    if (preg_match("/export const $const = '([^']+)';/", $devJs, $cm)) { $btn[$const] = $cm[1]; }
}
chk('both button words were found in devices.js', count($btn) === 2, json_encode($btn));
foreach ($btn as $const => $label) {
    chk("the privacy page uses the button word \"$label\" ($const) word for word", stripos($text, $label) !== false);
    chk("...and the Settings page really has a button with that word", preg_match('/<button[^>]*>\s*(?:<i[^>]*><\/i>\s*)?' . preg_quote($label, '/') . '\s*<\/button>/', $settings) === 1);
}
$devTitle = preg_match('/id="settings-devices-card".*?<\/i>\s*(Signed-in devices)\s*<\/h2>/s', $settings, $dt) ? $dt[1] : '';
chk('"Signed-in devices" is really the title of the Settings card the page sends people to, and the page says it', $devTitle === 'Signed-in devices' && stripos($text, 'under ' . $devTitle) !== false);
$days = (int)(API_TOKEN_LIFETIME_SECONDS / 86400);
chk("the page says a device's sign-in ends after $days days unused, the real lifetime", preg_match('/after ' . $days . ' days without being used/', $text) === 1);
chk('...and the lifetime is also the figure the page says for an unused key (' . ACCOUNT_ENROL_KEY_LIFETIME_DAYS . ' days from last use)', preg_match('/lasts ' . ACCOUNT_ENROL_KEY_LIFETIME_DAYS . ' days from the last time it was used/', $text) === 1);
chk('the page says this is "switched on for the site" and the switch really is off unless an admin ticks a channel', stripos($text, 'Once this is switched on for the site') !== false && DEVICE_SIGNIN_DEFAULT === 'none');
$authSrc = (string)file_get_contents($web . '/manage/includes/auth.php');
$units = phpSourceUnits($authSrc);
foreach (['resetPassword', 'changeUserPassword', 'setUserActive'] as $fn) {
    chk("the page says the key ends when the password is changed, reset or the account switched off: $fn() goes through the one door", isset($units[$fn]) && str_contains($units[$fn]['code'], 'apiTokenRevokeAllForUser('));
}
$apiTokensSrc = (string)file_get_contents($web . '/includes/api_tokens.php');
$doorU = phpSourceUnits($apiTokensSrc)['apiTokenRevokeAllForUser']['code'] ?? '';
chk('...and the one door cancels the automatic sign-in keys', str_contains($doorU, 'accountEnrolKeyRevokeAll('));
$ds = (string)file_get_contents($web . '/includes/device_signin.php');
chk('the page says a signed-out or run-out device does not sign itself back in: redeem checks for an ended sign-in', str_contains($ds, 'apiTokenInstallSignInEnded('));
chk('the page says the key can only give a device a sign-in of its own: a key is never accepted as a bearer sign-in (the key core is not named by the readers)', !str_contains($apiTokensSrc, 'tblAccountEnrolKeys'));

/* PR 3a security review (findings 3 and 4): the page now promises that signing out ONE device cancels the key. Tie that sentence to the code. */
$flat = static fn(string $t): string => (string)preg_replace('/\s+/', ' ', strip_tags($t));
$textFlat = $flat($text);
chk('the page says signing out any one device ends the key, and that the other devices stay signed in',
    stripos($textFlat, 'Signing out any one of your devices') !== false && stripos($textFlat, 'The devices you did not sign out stay signed in') !== false);
chk('the page says whoever holds a live key could get a sign-in for the account, and that Turn off / Sign out everywhere end it',
    stripos($textFlat, 'Whoever holds a live key could get a sign-in for your account') !== false && stripos($textFlat, 'can end it with Turn off') !== false && stripos($textFlat, 'Signing out any one of your devices, signing out everywhere') !== false);
$signOutDoor = phpSourceUnits($apiTokensSrc)['apiTokenSignOutHashes']['code'] ?? '';
chk('...and that sentence is TRUE: ending a device\'s own sign-in (the one sign-out door) cancels the keys, for every route that signs one device out',
    str_contains($signOutDoor, 'accountEnrolKeyRevokeAll(') && str_contains($signOutDoor, '$endedDeviceSignIns > 0')
    && substr_count((string)file_get_contents($web . '/api.php'), 'apiTokenSignOutHashes(') >= 2);
$apiSrc2 = (string)file_get_contents($web . '/api.php');
chk('...and Turn off really calls the key revoke (auth_enrol_keys_revoke) and Sign out everywhere goes through the one revoke door',
    str_contains(substr($apiSrc2, (int)strpos($apiSrc2, "case 'auth_enrol_keys_revoke':"), 1500), 'deviceSigninKeysRevoke(')
    && str_contains((string)file_get_contents($web . '/includes/device_signin.php'), 'apiTokenRevokeAllForUser('));
chk('the Settings keys row and the sign-out question say the same thing (the key is cancelled; other devices stay signed in)',
    stripos((string)file_get_contents($web . '/includes/pages/settings.php'), 'Signing out any one of your devices cancels the current key') !== false
    && stripos($devJs, 'This also cancels the automatic sign-in key') !== false && stripos($devJs, 'Your other devices stay signed in') !== false);
$yamlFlat = (string)preg_replace('/\s+/', ' ', (string)file_get_contents($web . '/api-docs.yaml'));
chk('the OpenAPI says the same, on both single-device sign-out routes and on redeem', substr_count($yamlFlat, 'automatic sign-in keys are cancelled too') >= 2
    && stripos($yamlFlat, 'Whoever holds a LIVE key could get a sign-in for the account') !== false);

exit($fail === 0 ? 0 : 1);
