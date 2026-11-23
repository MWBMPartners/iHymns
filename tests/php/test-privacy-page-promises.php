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
chk('...and sign-out really uses it', strpos($ua, 'clearPersonalDataAtSignOut(localStorage)') !== false);

exit($fail === 0 ? 0 : 1);
