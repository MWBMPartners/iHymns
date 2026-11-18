<?php

declare(strict_types=1);

/**
 * channel_switch.php - the ONE shape of a "per-channel on/off switch"
 * ============================================================================
 * Account/profile rewrite step C1 (epic #2180). Extracted because this exact
 * shape had reached its third copy (search visibility, the cross-site settings
 * sync, and now the account-writes switch), and rule #22 says the third copy is
 * an extraction, not another copy.
 *
 * ELI5
 * ----
 * iHymns runs as three copies of the site (live "production", "beta" and dev
 * "alpha") that share ONE database. Some switches need to be on for some copies
 * and off for others. Each switch is one row in `tblAppSettings` holding a
 * comma list of the channels it is ON for, for example `production,beta`.
 * This file answers two questions about such a row:
 *   - channelSwitchAllows(): is this channel's name in that list? (pure)
 *   - channelSwitchEnabledHere(): is the switch on for the copy of the site that
 *     is running right now? (reads the row STRICTLY, and says what to do if it
 *     genuinely cannot)
 *
 * Detailed
 * --------
 * The list is parsed by the shared fold `ihymns_parse_channels_csv()` (it keeps
 * only the three real channel words, any case, comma or space separated, and
 * silently drops anything else; `'none'` and an empty string therefore mean "no
 * channel"). The running channel is `ihymns_environment()` (the docroot this
 * code runs from, never the request's Host header, which can be forged).
 *
 * Memory: the answer is remembered for the rest of the request, per setting
 * key, because a setting cannot change mid-request. A caller (a test) that
 * passes its own reader or channel bypasses the memory, so a test can ask the
 * same question twice with different answers.
 *
 * The read is STRICT (`getAppSettingStrict()`): a missing row gives the default,
 * but a database or decryption failure THROWS and so reaches `$onError`. (The
 * everyday `getAppSetting()` swallows every error and returns the default, which
 * would make `$onError` unreachable, and a broken database would then look like
 * "switch on everywhere".)
 *
 * What to do when the setting cannot be read is the CALLER'S decision, passed as
 * `$onError`: a plain bool, or a function that is given the channel and returns
 * a bool. A brand-new dormant feature fails closed (`false`); the account-writes
 * switch fails open on production and closed elsewhere (see
 * account_write_gate.php).
 *
 * @see includes/environment.php          ihymns_environment(), ihymns_parse_channels_csv()
 * @see includes/cross_site_sync.php      a consumer (unchanged in behaviour)
 * @see includes/account_write_gate.php   a consumer (new)
 * @see tests/php/test-channel-switch.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php'; /* ihymns_environment(), ihymns_parse_channels_csv() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';    /* getAppSettingStrict() needs it available */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php'; /* getAppSettingStrict(): throws on a real read failure */

/**
 * PURE: does this comma list switch the thing ON for this channel?
 *
 * ELI5: "is the word for this channel somewhere in the list?" `'none'` and an
 * empty string are not channel words, so they never match.
 *
 * @param string $csv     raw setting value (comma or whitespace separated)
 * @param string $channel normally ihymns_environment(): 'alpha', 'beta' or 'production'
 */
function channelSwitchAllows(string $csv, string $channel): bool
{
    return in_array(strtolower(trim($channel)), ihymns_parse_channels_csv($csv), true);
}

/**
 * Is the switch stored under `$key` ON for THIS copy of the site right now?
 *
 * @param string        $key      the tblAppSettings key
 * @param string        $default  the stored text assumed when no row exists
 * @param bool|callable $onError  answer if the row cannot be read: a bool, or a
 *                                function (string $channel): bool
 * @param ?callable     $reader   test hook: function (string $key, string $default): string,
 *                                which may throw. Default reads getAppSettingStrict().
 * @param ?string       $channel  test hook: the channel to ask about. Default ihymns_environment().
 */
function channelSwitchEnabledHere(string $key, string $default, bool|callable $onError, ?callable $reader = null, ?string $channel = null): bool
{
    static $memo = [];
    $useMemo = ($reader === null && $channel === null);
    if ($useMemo && array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    $ch = $channel ?? ihymns_environment();
    try {
        $csv = $reader !== null
            ? (string)$reader($key, $default)
            : (string)(getAppSettingStrict($key, $default) ?? $default);
        $answer = channelSwitchAllows($csv, $ch);
    } catch (\Throwable $_e) {
        $answer = is_callable($onError) ? (bool)$onError($ch) : $onError;
    }
    if ($useMemo) {
        $memo[$key] = $answer;
    }
    return $answer;
}
