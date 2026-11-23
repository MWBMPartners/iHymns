<?php

declare(strict_types=1);

/**
 * device_signin_gate.php - the per-channel switch for "one sign-in per device"
 * ============================================================================
 * Account/profile rewrite, PR 3 step H2 (epic #2180, plan
 * `.claude/account-profile-pr3-plan.md` sections 2 and 3). A copy of the
 * shape of includes/profile_sync_gate.php.
 *
 * ELI5
 * ----
 * Until now every Apple device a person owns has shared ONE sign-in. The new
 * behaviour gives each device its own sign-in, so one device can be signed out
 * without touching the others. All three copies of the site (live, beta, dev)
 * share ONE database, so this is a switch per copy of the site, and it starts
 * OFF everywhere: deploying the code changes nothing until an admin ticks a
 * channel on /manage/configuration. Un-ticking a channel stops the swaps and the
 * automatic sign-in keys on that site straight away (apps simply carry on with
 * the shared sign-in they already have).
 *
 * Detailed
 * --------
 *  - Setting: `device_signin_channels`, a comma list of the channels that are ON
 *    (the shared shape in includes/channel_switch.php). The key and the default
 *    live here ONCE (rule #35).
 *  - DEFAULT with no row: `'none'` (off everywhere). It is a new feature, so it
 *    stays dormant until someone chooses.
 *  - IF IT CANNOT BE READ: the answer is "off" (fails closed).
 *  - Nothing ticked stores the text `none` (never an empty string, because
 *    setAppSetting() reads '' as "unset").
 *  - A refusal is a `409` carrying a `reason` code. A client branches on the
 *    status and the reason, never on the sentence (rule #35).
 *  - What it gates: swapping the shared sign-in for a device's own, creating or
 *    swapping an automatic sign-in key, and the web "Sign out everywhere"
 *    button. What it does NOT gate: recording the install id at sign-in (only
 *    new apps send one) and the "sign out everywhere" server action itself
 *    (signing out is never switched off).
 *  - NOT a secret, so it is never registered in secretSettingKeys().
 *
 * @see includes/channel_switch.php      the shared switch shape
 * @see includes/profile_sync_gate.php   the file this one is modelled on
 * @see tests/php/test-channel-switch.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'channel_switch.php';

/** The settings key, defined ONCE (rule #35). */
const DEVICE_SIGNIN_SETTING_KEY = 'device_signin_channels';

/** Default when no row exists: OFF on every channel. */
const DEVICE_SIGNIN_DEFAULT = 'none';

/** Reason code: the switch is off on this site. */
const DEVICE_SIGNIN_DISABLED_REASON = 'device_signin_disabled';

/** Reason code: the switch is on, but the database objects it needs have not been created yet. */
const DEVICE_SIGNIN_NOT_READY_REASON = 'device_signin_not_ready';

/**
 * PURE: does this comma list switch device sign-in ON for the given channel?
 *
 * @param string $csv     raw setting value
 * @param string $channel 'alpha', 'beta' or 'production'
 */
function deviceSigninAllows(string $csv, string $channel): bool
{
    return channelSwitchAllows($csv, $channel);
}

/**
 * Is "one sign-in per device" switched on for THIS copy of the site right now?
 * Fails closed: any trouble reading the setting means "off".
 *
 * @param ?callable $reader  test hook (see channelSwitchEnabledHere())
 * @param ?string   $channel test hook
 */
function deviceSigninEnabledHere(?callable $reader = null, ?string $channel = null): bool
{
    return channelSwitchEnabledHere(DEVICE_SIGNIN_SETTING_KEY, DEVICE_SIGNIN_DEFAULT, false, $reader, $channel);
}

/** The JSON body of a refusal because the switch is off here. */
function deviceSigninDisabledBody(): array
{
    return [
        'error'  => 'This is not switched on for this site yet.',
        'reason' => DEVICE_SIGNIN_DISABLED_REASON,
    ];
}

/** The JSON body of a refusal because the switch is on but the database is not ready. */
function deviceSigninNotReadyBody(): array
{
    return [
        'error'  => 'This site is not ready for this yet.',
        'reason' => DEVICE_SIGNIN_NOT_READY_REASON,
    ];
}

/**
 * Should THIS site run the device sign-in actions right now? True only when the channel switch
 * is ticked AND the database objects exist (the install-id columns and the enrol-key table).
 *
 * ELI5: both lights must be green. The switch is checked first, so while it is off (the default)
 * no database question is asked at all. Any database trouble counts as "not active".
 *
 * Detailed: the readiness answers come from api_tokens.php (`apiTokensFoundationColumnsExist()`)
 * and account_enrol_keys.php (`accountEnrolKeysReady()`), loaded here only when the switch is on,
 * so a site with the switch off does no extra work.
 *
 * @param ?\mysqli $db optional connection; one is opened only if the switch is on
 */
function deviceSigninActiveHere(?\mysqli $db = null): bool
{
    if (!deviceSigninEnabledHere()) {
        return false;
    }
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'api_tokens.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_enrol_keys.php';
        $db = $db ?? getDbMysqli();
        return apiTokensFoundationColumnsExist($db) && accountEnrolKeysReady($db);
    } catch (\Throwable $_e) {
        return false;
    }
}
