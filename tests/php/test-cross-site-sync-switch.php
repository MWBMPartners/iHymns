<?php

declare(strict_types=1);

/**
 * tests/php/test-cross-site-sync-switch.php — the per-channel switch for the settings sync (#2143)
 *
 * ELI5: the cross-site settings sync is OFF everywhere until an admin switches
 * a channel on. This test makes sure that really is true, and stays true:
 * nothing is on by default, an empty or junk list means off, the setting's name
 * is written in exactly one file (so nothing can quietly disagree about it),
 * the page tells the browser "on" only through the one switch function, and the
 * admin card saves through the same safe route as the search-visibility card.
 *
 * Covers:
 *   1. crossSiteSyncAllows(): a truth table (none, empty, junk, one channel,
 *      mixed case and spaces, all three).
 *   2. The default is 'none' (off) and the stored key is the expected one.
 *   3. crossSiteSyncEnabledHere() is false when nothing is stored (and so fails
 *      closed): this test runs with no database row.
 *   4. The quoted settings key appears in exactly one file under appWeb/
 *      (rule #35: one definition, never retyped).
 *   5. index.php sends `crossSiteSync.enabled` only from crossSiteSyncEnabledHere()
 *      and the old `storageBridgeUrl` is gone.
 *   6. configuration.php has the save_cross_site_sync handler, built on the
 *      constant, restricted to the three real channels, storing 'none' for none,
 *      and the page is still gated by the manage_configuration permission and a
 *      CSRF check before any action runs.
 *   7. The API-coverage guard lists the action as web-only (rule #48).
 *
 * Mutation-proven (rule #34): change the default to 'production' (rows 2 and 3
 * fail); retype the key in configuration.php (row 4 fails); hard-code
 * `'enabled' => true` in index.php (row 5 fails); remove the channel check from
 * the handler (row 6 fails). The current tree is green.
 *
 *   php tests/php/test-cross-site-sync-switch.php
 *
 * @see appWeb/public_html/includes/cross_site_sync.php
 * @see tests/php/test-search-visibility.php   the sibling guard this mirrors
 * @see .claude/settings-sync-2143-plan.md     (section 5, guard G5)
 */

$repo      = dirname(__DIR__, 2);
$webRoot   = $repo . '/appWeb/public_html';
$syncFile  = $webRoot . '/includes/cross_site_sync.php';
$indexFile = $webRoot . '/index.php';
$confFile  = $webRoot . '/manage/configuration.php';
$covFile   = $repo . '/tests/php/test-manage-action-api-coverage.php';

$passed = 0;
$failed = 0;

function cs(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

/** Blank out comments, keeping offsets, so a word MENTIONED in a comment is not mistaken for code. */
function csStrip(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            $out .= ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) ? preg_replace('/[^\n]/', ' ', $tok[1]) : $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

foreach ([$syncFile, $indexFile, $confFile, $covFile] as $f) {
    if (!file_exists($f)) {
        fwrite(STDERR, "FAIL: required file missing: {$f}\n");
        exit(1);
    }
}

require_once $syncFile;

/* ---- 1. the truth table ---- */
echo "1. crossSiteSyncAllows() truth table\n";
cs('"none" is off for every channel', !crossSiteSyncAllows('none', 'alpha') && !crossSiteSyncAllows('none', 'beta') && !crossSiteSyncAllows('none', 'production'));
cs('an empty string is off', !crossSiteSyncAllows('', 'production'));
cs('junk words are off', !crossSiteSyncAllows('foo,bar,all,yes', 'alpha'));
cs('one channel is on for that channel only', crossSiteSyncAllows('alpha', 'alpha') && !crossSiteSyncAllows('alpha', 'beta') && !crossSiteSyncAllows('alpha', 'production'));
cs('mixed case and spaces still match', crossSiteSyncAllows(' Alpha , BETA ', 'beta') && crossSiteSyncAllows(' Alpha , BETA ', 'alpha') && !crossSiteSyncAllows(' Alpha , BETA ', 'production'));
cs('all three spelled out is on for all three', crossSiteSyncAllows('production,beta,alpha', 'production') && crossSiteSyncAllows('production,beta,alpha', 'beta') && crossSiteSyncAllows('production,beta,alpha', 'alpha'));
cs('the channel is compared case-insensitively', crossSiteSyncAllows('production', 'PRODUCTION'));

/* ---- 2 + 3. default off, fails closed ---- */
echo "2. Default and key\n";
cs('the default when nothing is stored is "none" (off)', CROSS_SITE_SYNC_DEFAULT === 'none');
cs('the stored key is cross_site_sync_channels', CROSS_SITE_SYNC_SETTING_KEY === 'cross_site_sync_channels');
echo "3. Fails closed\n";
cs('with no stored value (and no database in this test) the sync is OFF here', crossSiteSyncEnabledHere() === false);

/* ---- 4. one definition of the key ---- */
echo "4. The key is written once\n";
$files = array_filter(explode("\0", (string)shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z -- appWeb')));
$holders = [];
foreach ($files as $rel) {
    if (!preg_match('/\.(php|js|html)$/', $rel) || str_contains($rel, 'private_html/lib/')) { continue; }
    $text = (string)@file_get_contents($repo . '/' . $rel);
    if (preg_match('/[\'"]cross_site_sync_channels[\'"]/', $text)) { $holders[] = $rel; }
}
cs('the quoted key appears in exactly one file under appWeb/ (' . implode(', ', $holders) . ')',
    $holders === ['appWeb/public_html/includes/cross_site_sync.php']);

/* ---- 5. index.php ---- */
echo "5. index.php\n";
$index = csStrip((string)file_get_contents($indexFile));
cs('index.php requires the switch file', (bool)preg_match('/includes[\'"\s.\w\/]*cross_site_sync\.php/', $index));
cs('it sends crossSiteSync.enabled only from crossSiteSyncEnabledHere()',
    (bool)preg_match('/[\'"]crossSiteSync[\'"]\s*=>\s*\[\s*[\'"]enabled[\'"]\s*=>\s*crossSiteSyncEnabledHere\(\)/', $index));
cs('the old storageBridgeUrl is gone', !str_contains($index, 'storageBridgeUrl'));

/* ---- 6. the admin handler ---- */
echo "6. The admin handler\n";
$conf = csStrip((string)file_get_contents($confFile));
$hStart = strpos($conf, "\$action === 'save_cross_site_sync'");
cs('configuration.php has a save_cross_site_sync handler', $hStart !== false);
$handler = $hStart === false ? '' : substr($conf, $hStart, 2600);
cs('it saves through the constant, never a retyped key', str_contains($handler, 'CROSS_SITE_SYNC_SETTING_KEY'));
cs('it only accepts the three real channels', (bool)preg_match('/\$validSyncChans\s*=\s*\[\s*[\'"]alpha[\'"]\s*,\s*[\'"]beta[\'"]\s*,\s*[\'"]production[\'"]\s*\]/', $handler)
    && str_contains($handler, 'in_array($c, $validSyncChans, true)'));
cs('an empty tick-set stores "none", never an empty string', (bool)preg_match('/\$syncChansOut\s*===\s*\[\]\s*\?\s*[\'"]none[\'"]/', $handler));
cs('the page is gated by manage_configuration before any action', (bool)preg_match('/userHasEntitlement\(\s*[\'"]manage_configuration[\'"]/', $conf)
    && strpos($conf, "userHasEntitlement('manage_configuration'") < $hStart);
cs('the page checks CSRF before running the classic actions', (bool)preg_match('/validateCsrf(Request)?\(/', substr($conf, 0, $hStart)));
cs('the card posts to the same action', str_contains($conf, 'value="save_cross_site_sync"'));

/* ---- 7. API-coverage guard ---- */
echo "7. API coverage mapping\n";
cs('the coverage guard maps save_cross_site_sync as web-only', (bool)preg_match('/[\'"]save_cross_site_sync[\'"]\s*=>\s*[\'"]web_only:/', (string)file_get_contents($covFile)));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
