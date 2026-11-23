<?php

declare(strict_types=1);

/**
 * profile_sync_gate.php - the per-channel switch for the new account behaviour
 * ============================================================================
 * Account/profile rewrite, PR 2a step 2a-1 (epic #2180, plan
 * `.claude/account-profile-pr2-plan.md` section 1, "The switch").
 *
 * ELI5
 * ----
 * The second round of account work adds new behaviour (favourites that stay
 * removed on every device, view counts that only count real views, and more
 * to come). All three copies of the site (live, beta and dev) share ONE
 * database, so new behaviour on one copy touches real people's accounts.
 * This file is the on/off switch for the new behaviour, one tick per copy of
 * the site. It starts OFF everywhere, so deploying the code changes nothing
 * until an admin ticks a channel on /manage/configuration. Un-ticking a
 * channel puts the previous behaviour back on that copy straight away.
 *
 * Detailed
 * --------
 *  - Setting: `profile_sync_channels`, a comma list of the channels the new
 *    behaviour is ON for (the shared shape in includes/channel_switch.php).
 *    The key and the default live here ONCE (rule #35).
 *  - DEFAULT with no row: `'none'` (off everywhere). The opposite of the
 *    account-writes brake, which defaults to ON: this one is a NEW feature, so
 *    it must be dormant until someone chooses.
 *  - IF IT CANNOT BE READ: the answer is "off" (fails closed). The previous
 *    behaviour then carries on exactly as it did.
 *  - Nothing ticked stores the text `none` (never an empty string, because
 *    setAppSetting() reads '' as "unset").
 *  - A refusal is a `409` carrying a `reason` code. A client branches on the
 *    status and the reason, never on the sentence (rule #35).
 *  - NOT a secret, so it is never registered in secretSettingKeys().
 *
 * @see includes/channel_switch.php      the shared switch shape
 * @see includes/account_write_gate.php  the shape this file is modelled on
 * @see includes/profile_sync.php        profileItemsReady(), the "has the new table been created" check
 * @see tests/php/test-channel-switch.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'channel_switch.php';

/** The settings key, defined ONCE (rule #35). */
const PROFILE_SYNC_SETTING_KEY = 'profile_sync_channels';

/** Default when no row exists: OFF on every channel. */
const PROFILE_SYNC_DEFAULT = 'none';

/** Reason code: the switch is off on this site. */
const PROFILE_SYNC_DISABLED_REASON = 'profile_sync_disabled';

/** Reason code: the switch is on, but the database objects it needs have not been created yet. */
const PROFILE_SYNC_NOT_READY_REASON = 'profile_sync_not_ready';

/**
 * PURE: does this comma list switch the new behaviour ON for the given channel?
 *
 * @param string $csv     raw setting value
 * @param string $channel 'alpha', 'beta' or 'production'
 */
function profileSyncAllows(string $csv, string $channel): bool
{
    return channelSwitchAllows($csv, $channel);
}

/**
 * Is the new account behaviour switched on for THIS copy of the site right now?
 * Fails closed: any trouble reading the setting means "off".
 *
 * @param ?callable $reader  test hook (see channelSwitchEnabledHere())
 * @param ?string   $channel test hook
 */
function profileSyncEnabledHere(?callable $reader = null, ?string $channel = null): bool
{
    return channelSwitchEnabledHere(PROFILE_SYNC_SETTING_KEY, PROFILE_SYNC_DEFAULT, false, $reader, $channel);
}

/** The JSON body of a refusal because the switch is off here. */
function profileSyncDisabledBody(): array
{
    return [
        'error'  => 'This is not switched on for this site yet.',
        'reason' => PROFILE_SYNC_DISABLED_REASON,
    ];
}
