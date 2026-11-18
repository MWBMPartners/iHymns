<?php

declare(strict_types=1);

/**
 * account_write_gate.php - the per-channel switch for writing into real accounts
 * ============================================================================
 * Account/profile rewrite steps C1 + C2 (epic #2180, decision O3).
 *
 * ELI5
 * ----
 * iHymns has three copies of the site (live, beta and dev), but they all share
 * ONE database of accounts, so a person's favourites, set lists and settings
 * are the same everywhere (that is deliberate: one account everywhere). The
 * risk is a test copy with a bug writing garbage into REAL people's accounts.
 * This is the emergency brake: an admin can untick a channel on
 * /manage/configuration and that copy then refuses to change anyone's personal
 * data. Reading still works, and signing in or out still works.
 *
 * Detailed
 * --------
 *  - Setting: `account_writes_channels`, a comma list of the channels that MAY
 *    write personal data (the shared channel-switch shape, includes/
 *    channel_switch.php). Written ONCE here (rule #35) so the admin page and the
 *    reader never retype it.
 *  - DEFAULT with no row: ALL THREE channels. One shared account is the
 *    decision, so deploying this changes nothing until someone unticks.
 *  - IF IT CANNOT BE READ (database trouble): production is allowed, alpha and
 *    beta are refused. Production keeps working for real people; a test copy
 *    that cannot even read its own switch must not write into real accounts.
 *  - Nothing ticked stores the text `none` (never an empty string, because
 *    setAppSetting() reads '' as "unset").
 *  - When the switch is OFF for this channel a personal-data write answers
 *    `409` with `reason: account_writes_off_here` (a client branches on the
 *    status and the reason code, never the sentence: rule #35). Clients keep the
 *    change queued on the device and tell the person quietly.
 *  - NOT gated: signing in, signing out, registering. Those are about getting in
 *    and out, not about changing what an account holds.
 *
 * @see includes/channel_switch.php
 * @see tests/php/test-channel-switch.php
 * @see tests/php/test-account-write-gate.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'channel_switch.php';

/** The settings key, defined ONCE (rule #35). */
const ACCOUNT_WRITES_SETTING_KEY = 'account_writes_channels';

/** Default when no row exists: ALL three channels may write (one shared account). */
const ACCOUNT_WRITES_DEFAULT = 'production,beta,alpha';

/** The machine-readable reason a refused write carries. Clients branch on this and the 409 status. */
const ACCOUNT_WRITES_OFF_REASON = 'account_writes_off_here';

/** The words an admin must type to switch PRODUCTION off (compared case-insensitively). */
const ACCOUNT_WRITES_PRODUCTION_OFF_PHRASE = 'turn off production';

/**
 * PURE: does this comma list let the given channel write personal data?
 * Same fold as every other per-channel switch.
 */
function accountWritesAllows(string $csv, string $channel): bool
{
    return channelSwitchAllows($csv, $channel);
}

/**
 * PURE: what to answer when the switch cannot be read. Production keeps working;
 * every other channel is refused (see the file header).
 */
function accountWritesOnReadError(string $channel): bool
{
    return strtolower(trim($channel)) === 'production';
}

/**
 * May THIS copy of the site change personal data right now?
 *
 * @param ?callable $reader  test hook (see channelSwitchEnabledHere())
 * @param ?string   $channel test hook
 */
function accountWritesAllowedHere(?callable $reader = null, ?string $channel = null): bool
{
    return channelSwitchEnabledHere(
        ACCOUNT_WRITES_SETTING_KEY,
        ACCOUNT_WRITES_DEFAULT,
        'accountWritesOnReadError',
        $reader,
        $channel
    );
}

/** The JSON body of a refused write. */
function accountWritesRefusalBody(): array
{
    return [
        'error'  => 'Saving to your account is paused on this site.',
        'reason' => ACCOUNT_WRITES_OFF_REASON,
    ];
}

/**
 * The one call every personal-data write makes first. When the switch is off for
 * this channel it sends the 409 refusal and returns true (the caller then
 * `break`s). When on, it does nothing and returns false.
 *
 *     case 'favorites_remove':
 *         if (accountWritesRefuseIfOff()) { break; }
 *
 * Needs sendJson() from api.php; outside the API it only reports the answer.
 */
function accountWritesRefuseIfOff(): bool
{
    if (accountWritesAllowedHere()) {
        return false;
    }
    if (function_exists('sendJson')) {
        sendJson(accountWritesRefusalBody(), 409);
    }
    return true;
}

/**
 * PURE: is this decoded sync request a plain "merge" - the kind that only
 * pulls the person's saved data down onto a device?
 *
 * ELI5: when a site is paused for saving, a signed-in person on a NEW device
 * should still SEE what is already in their account. The first sync after
 * sign-in is a "merge" (it only adds), so on a paused site it is allowed to
 * run as a read: it hands back what the account holds and changes nothing.
 * Anything else (a "replace", which can delete) stays refused.
 *
 * `mode` missing counts as merge, because that is what every sync handler
 * already does with it. Anything that is not exactly 'merge' (or missing) is
 * not a merge read.
 *
 * @param mixed $body the json_decode()d request body
 */
function accountWritesIsMergeRead(mixed $body): bool
{
    if (!is_array($body)) {
        return false;
    }
    $mode = $body['mode'] ?? 'merge';
    return $mode === 'merge';
}

/**
 * The gate for the three sync actions (favourites, set lists, custom tags):
 * like accountWritesRefuseIfOff(), except that on a paused site a plain
 * "merge" is let through as a READ-ONLY request.
 *
 * Returns true when it refused (sent the 409; the caller then `break`s).
 * Otherwise returns false and sets `$readOnly` by reference: true means the
 * site is paused and the caller MUST NOT write anything (no inserts, no
 * deletes, no new "synced at" mark); false means writes are allowed as usual.
 *
 *     case 'favorites_sync':
 *         $readOnly = false;
 *         if (accountWritesRefuseUnlessMergeRead($readOnly)) { break; }
 *
 * The body is only read when the site is paused, so the normal path costs
 * nothing extra.
 */
function accountWritesRefuseUnlessMergeRead(bool &$readOnly): bool
{
    $readOnly = false;
    if (accountWritesAllowedHere()) {
        return false;
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (accountWritesIsMergeRead($body)) {
        $readOnly = true;
        return false;
    }
    if (function_exists('sendJson')) {
        sendJson(accountWritesRefusalBody(), 409);
    }
    return true;
}
