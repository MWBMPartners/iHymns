<?php

declare(strict_types=1);

/**
 * iHymns — Per-channel switch for the cross-site settings sync (#2143)
 * ============================================================================
 *
 * ELI5
 * ----
 * iHymns has three copies of the site (live, beta and dev). A visitor can
 * change a setting such as the theme or the text size on one copy, and it
 * would be kind if the other copies noticed. The browser side of that is
 * `js/modules/settings-sync.js` (a small shared cookie). THIS file is only the
 * on/off switch for it: "is the settings sync turned on for THIS copy of the
 * site right now?". It is OFF everywhere until an admin ticks a channel on
 * /manage/configuration, so shipping the code changes nothing for anyone.
 *
 * DETAILED
 * ----------------------------------------------------------------------------
 * The shape is a copy of `includes/search_visibility.php` (rule #22, reuse an
 * existing storage convention rather than inventing a third): one
 * `tblAppSettings` row holds a comma-separated list of the channels it is ON
 * for, shared by all three docroots (they share one MySQL database), parsed by
 * the ONE shared fold `ihymns_parse_channels_csv()` and checked against
 * `ihymns_environment()` (the docroot this code runs from, never the request's
 * Host header, which can be forged).
 *
 * Two deliberate differences from search visibility:
 *   - the default when no row exists is `'none'` (OFF everywhere), not
 *     'production'. This is a new feature, so it must be dormant until an
 *     admin chooses to switch it on;
 *   - ticking no channel stores the literal `'none'`, never an empty string,
 *     because `setAppSetting()` treats '' as "unset". 'none' is not a real
 *     channel word, so the parser yields an empty list on its own.
 *
 * FAILS CLOSED: if the setting cannot be read for ANY reason (a database
 * outage, an un-migrated install), the answer is "off". The old cookie sync
 * then carries on exactly as it did before this feature existed.
 *
 * NOT A SECRET — never registered in secretSettingKeys().
 *
 * @see appWeb/public_html/includes/search_visibility.php  the shape copied here
 * @see appWeb/public_html/includes/environment.php        ihymns_environment(), ihymns_parse_channels_csv()
 * @see appWeb/public_html/index.php                       sends the answer to the browser
 * @see appWeb/public_html/manage/configuration.php        the admin card and save handler
 * @see .claude/settings-sync-2143-plan.md                 the full plan
 * @link .claude/CLAUDE.md rule #35   "cross-file agreement needs a mechanism, not a comment"
 */

/* Direct access blocked, matching the house convention for includes/*.php. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php'; /* ihymns_environment(), ihymns_parse_channels_csv() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';    /* getAppSetting() needs it available */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php'; /* getAppSetting(): returns the default on ANY error */

/** The settings key, defined ONCE (rule #35) so the admin page and the reader never re-type it. */
const CROSS_SITE_SYNC_SETTING_KEY = 'cross_site_sync_channels';

/** Default when no row exists: OFF on every channel. */
const CROSS_SITE_SYNC_DEFAULT = 'none';

/**
 * PURE core: does the given list switch the sync ON for this channel?
 * No database, no superglobals, so it is safe to call from a test table.
 *
 * ELI5: "is the word for this channel somewhere in this comma list?".
 * `'none'` (and an empty string) is not a channel word, so it never matches.
 *
 * @param string $csv     Raw setting value (comma or whitespace separated).
 * @param string $channel Normally `ihymns_environment()`: 'alpha', 'beta' or 'production'.
 * @return bool True when $channel appears in the parsed list.
 */
function crossSiteSyncAllows(string $csv, string $channel): bool
{
    return in_array(strtolower(trim($channel)), ihymns_parse_channels_csv($csv), true);
}

/**
 * ELI5: is the settings sync switched on for THIS copy of the site right now?
 *
 * Remembered for the rest of the request, because the setting cannot change
 * mid-request. Fails closed (returns false) on any error.
 *
 * @return bool True when the browser should use the cross-site settings sync.
 */
function crossSiteSyncEnabledHere(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    try {
        $csv = (string)(getAppSetting(CROSS_SITE_SYNC_SETTING_KEY, CROSS_SITE_SYNC_DEFAULT) ?? CROSS_SITE_SYNC_DEFAULT);
        return $enabled = crossSiteSyncAllows($csv, ihymns_environment());
    } catch (\Throwable $e) {
        return $enabled = false;
    }
}
