<?php

declare(strict_types=1);

/**
 * device_signin.php - the actions behind "one sign-in per device"
 * ============================================================================
 * Account/profile rewrite, PR 3 step H5 (epic #2180, plan
 * `.claude/account-profile-pr3-plan.md` sections 2 and 3, design file
 * `.claude/analysis/per-device-sign-in-design.md`).
 *
 * ELI5
 * ----
 * Until now every Apple device a person owns shared one sign-in. This file holds the rules for
 * giving each device its own:
 *  - ENROL: a device that holds the shared sign-in asks for its own. It gets a new sign-in (and,
 *    unless it says no, an automatic sign-in key to keep in iCloud Keychain). The shared sign-in is
 *    NOT touched, so older copies of the app keep working.
 *  - KEY CREATE: a device that signed in by hand (so it holds only its own sign-in) can still make the
 *    automatic sign-in key.
 *  - REDEEM: a brand-new device with no sign-in hands over the key and gets its own sign-in.
 *  - REVOKE KEYS: turn automatic sign-in off.
 *  - SIGN OUT EVERYWHERE.
 * None of these ever sets a browser cookie.
 *
 * Detailed
 * --------
 * Each function takes an open connection and the decoded request and RETURNS the answer as
 * `['status' => int, 'body' => array, 'userId' => ?int]` instead of sending it, so a test can call it
 * without api.php (which routes the moment it is loaded). api.php's cases are thin: they check the
 * method, the same-origin header, the sign-in, the per-site switch and the rate limits, then call
 * here and `sendJson()` the answer. The status codes and `reason` words are the contract (rule #35):
 *
 *   enrol:   200; 400 invalid_install_id | invalid_platform; 403 install_mismatch |
 *            not_a_shared_app_sign_in | install_signed_out; 409 already_enrolled | device_signin_not_ready
 *   key:     200; 403 device_token_required | enrol_key_platform_unsupported; 409 not_ready
 *   redeem:  200; 400 malformed_enrol_key | invalid_install_id | invalid_platform;
 *            401 enrol_key_invalid (ONE answer for unknown, expired, cancelled, wrong platform,
 *            switched-off account AND an install that was signed out: the caller cannot tell which);
 *            409 not_ready; 429
 *
 * The owner's rule (2026-11-23, plan question B): an install whose sign-in ended (signed out, or 30
 * days unused) must be signed in again by hand. The server enforces it with the ended markers that
 * api_tokens.php keeps (apiTokenInstallSignInEnded()): redeem answers `enrol_key_invalid`, and enrol
 * answers `install_signed_out`, for such an install. A manual sign-in on the install replaces the marker.
 *
 * A key's plaintext is returned ONLY by the call that makes it; nothing here ever stores or returns it
 * again. A token or key is never written to a log (callers log a short hash prefix only).
 *
 * @see includes/account_enrol_keys.php  the key core
 * @see includes/api_tokens.php          apiTokenMint(), apiTokenRevokeAllForUser(), the ended markers
 * @see tests/php/test-device-signin-core.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'api_tokens.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_enrol_keys.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'device_signin_gate.php';

/** Build one answer. */
function deviceSigninAnswer(int $status, array $body, ?int $userId = null): array
{
    return ['status' => $status, 'body' => $body, 'userId' => $userId];
}

/** A refusal with a stable `reason` word (clients branch on the status and this word, never the sentence). */
function deviceSigninRefusal(int $status, string $reason, string $sentence): array
{
    return deviceSigninAnswer($status, ['error' => $sentence, 'reason' => $reason]);
}

/**
 * Are the database objects these actions need all there? (the install-id columns, the device-detail
 * columns, and the key table). Checked again here, behind api.php's own check, as the last line of defence.
 */
function deviceSigninCoreReady(\mysqli $db): bool
{
    return apiTokensFoundationColumnsExist($db) && apiTokensDeviceMetaColumnsExist($db) && accountEnrolKeysReady($db);
}

/**
 * Read and check the device details a request must carry: the install id and the platform, plus the
 * optional name and app version. PURE.
 *
 * @return array{ok:true, meta:array, installId:string, platform:string}|array{ok:false, status:int, reason:string}
 */
function deviceSigninParseDevice(mixed $body): array
{
    $meta = apiTokenDeviceMetaFromBody($body);
    if ($meta['deviceInstallId'] === null) {
        return ['ok' => false, 'status' => 400, 'reason' => 'invalid_install_id'];
    }
    /* A device sign-in is for a native app: the web (a browser) never swaps or redeems one. */
    if ($meta['platform'] === null || $meta['platform'] === 'web') {
        return ['ok' => false, 'status' => 400, 'reason' => 'invalid_platform'];
    }
    return ['ok' => true, 'meta' => $meta, 'installId' => $meta['deviceInstallId'], 'platform' => $meta['platform']];
}

/** A short, log-safe tag for a sign-in (the first characters of its stored hash, never the token). */
function deviceSigninLogTag(string $tokenHash): string
{
    return apiTokenDeviceId($tokenHash);
}

/**
 * ENROL: swap the shared (older-app) sign-in for this device's own.
 *
 * Rules, in order:
 *  - the request must be for a real install and a native platform (400);
 *  - the caller's sign-in must be a shared app sign-in: not a browser one and not the cross-surface
 *    one made by the admin pages (403 not_a_shared_app_sign_in);
 *  - a caller already tied to THIS install is already enrolled (409); tied to ANOTHER install: refused
 *    (403 install_mismatch), because a token for one install must never make a token for another;
 *  - an install that was signed out is refused (403 install_signed_out);
 *  - then it mints a new sign-in (`IssuedVia = 'enrol'`) and, unless the request said
 *    `createEnrolKey: false`, an automatic sign-in key for platforms that may hold one. The caller's
 *    own sign-in is NEVER revoked (older app copies still use it).
 *
 * @param array $user       the signed-in person (needs `Id`)
 * @param string $callerHash sha256 of the sign-in token the request carried (the caller's row)
 */
function deviceSigninEnrol(\mysqli $db, array $user, string $callerHash, array $body): array
{
    if (!apiTokensFoundationColumnsExist($db) || !deviceSigninCoreReady($db)) {   /* (the first check is named here so the ready-check guard sees it before any SQL on the new columns) */
        return deviceSigninAnswer(409, deviceSigninNotReadyBody());
    }
    $uid = (int)$user['Id'];
    $dev = deviceSigninParseDevice($body);
    if (!$dev['ok']) {
        return deviceSigninRefusal($dev['status'], $dev['reason'], $dev['reason'] === 'invalid_install_id' ? 'A valid install id is required.' : 'A valid app platform is required.');
    }
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare('SELECT Platform, IssuedVia, DeviceInstallId FROM tblApiTokens WHERE Token = ? AND UserId = ? AND ExpiresAt > ? LIMIT 1');
    $st->bind_param('sis', $callerHash, $uid, $now);
    $st->execute();
    $caller = $st->get_result()->fetch_assoc();
    $st->close();
    if ($caller === null) {
        return deviceSigninRefusal(401, 'token_dead', 'Not authenticated.');
    }
    if ((string)$caller['Platform'] === 'web' || (string)$caller['IssuedVia'] === 'cross_surface') {
        return deviceSigninRefusal(403, 'not_a_shared_app_sign_in', 'This sign-in cannot be swapped.');
    }
    if ($caller['DeviceInstallId'] !== null) {
        return $caller['DeviceInstallId'] === $dev['installId']
            ? deviceSigninRefusal(409, 'already_enrolled', 'This device already has its own sign-in.')
            : deviceSigninRefusal(403, 'install_mismatch', 'This sign-in belongs to a different install.');
    }
    if (apiTokenInstallSignInEnded($db, $uid, $dev['installId'])) {
        return deviceSigninRefusal(403, 'install_signed_out', 'This device was signed out; sign in again.');
    }

    $makeKey = ($body['createEnrolKey'] ?? true) !== false && in_array($dev['platform'], ACCOUNT_ENROL_KEY_PLATFORMS, true);
    $db->begin_transaction();
    try {
        $exp   = null;
        $token = apiTokenMint($db, $uid, $dev['meta'], 'enrol', $exp, $dev['installId']);
        $out = [
            'token'     => $token,
            'device'    => ['id' => apiTokenDeviceId(hash('sha256', $token))],
            'expiresAt' => gmdate('Y-m-d H:i:s', (int)$exp),
        ];
        if ($makeKey) {
            $k = accountEnrolKeyMint($db, $uid, $dev['platform']);
            $out['enrolKey'] = $k['key'];
            $out['enrolKeyExpiresAt'] = $k['expiresAt'];
        }
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }
    return deviceSigninAnswer(200, $out, $uid);
}

/**
 * KEY CREATE: make (or add) the automatic sign-in key from a device that already has its own sign-in.
 * The caller's sign-in must be tied to an install (403 device_token_required: the shared sign-in
 * cannot make a key, or every old copy of the app could) and be on a platform that may hold keys
 * (403 enrol_key_platform_unsupported). Returns the key ONCE.
 */
function deviceSigninKeyCreate(\mysqli $db, array $user, string $callerHash, array $body): array
{
    if (!apiTokensFoundationColumnsExist($db) || !deviceSigninCoreReady($db)) {   /* (the first check is named here so the ready-check guard sees it before any SQL on the new columns) */
        return deviceSigninAnswer(409, deviceSigninNotReadyBody());
    }
    $uid = (int)$user['Id'];
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare('SELECT Platform, DeviceInstallId FROM tblApiTokens WHERE Token = ? AND UserId = ? AND ExpiresAt > ? LIMIT 1');
    $st->bind_param('sis', $callerHash, $uid, $now);
    $st->execute();
    $caller = $st->get_result()->fetch_assoc();
    $st->close();
    if ($caller === null) {
        return deviceSigninRefusal(401, 'token_dead', 'Not authenticated.');
    }
    if ($caller['DeviceInstallId'] === null) {
        return deviceSigninRefusal(403, 'device_token_required', 'Only a device\'s own sign-in can make this.');
    }
    $platform = (string)($caller['Platform'] ?? '');
    if (!in_array($platform, ACCOUNT_ENROL_KEY_PLATFORMS, true)) {
        return deviceSigninRefusal(403, 'enrol_key_platform_unsupported', 'This kind of app cannot hold an automatic sign-in key.');
    }
    $db->begin_transaction();
    try {
        $k = accountEnrolKeyMint($db, $uid, $platform);
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }
    return deviceSigninAnswer(200, ['enrolKey' => $k['key'], 'expiresAt' => $k['expiresAt']], $uid);
}

/**
 * REDEEM: a device with no sign-in hands over the automatic sign-in key and gets its own sign-in.
 * No sign-in is needed (the key IS the proof). ONE refusal answer (401 `enrol_key_invalid`) for every
 * unusable key, including a key for a person whose account is off and an install that was signed out.
 * The key is extended only when a sign-in is actually made (a refusal rolls the extension back).
 *
 * @param array{allowPerson?:callable(int):bool, recordPerson?:callable(int):void} $hooks
 *        per-person rate limit hooks, run once the key has named a person (api.php supplies them)
 */
function deviceSigninRedeem(\mysqli $db, array $body, array $hooks = []): array
{
    if (!deviceSigninCoreReady($db)) {
        return deviceSigninAnswer(409, deviceSigninNotReadyBody());
    }
    $key = $body['enrolKey'] ?? null;
    if (!accountEnrolKeyShapeOk($key)) {
        return deviceSigninRefusal(400, 'malformed_enrol_key', 'That is not an automatic sign-in key.');
    }
    $dev = deviceSigninParseDevice($body);
    if (!$dev['ok']) {
        return deviceSigninRefusal($dev['status'], $dev['reason'], $dev['reason'] === 'invalid_install_id' ? 'A valid install id is required.' : 'A valid app platform is required.');
    }
    $refuse = static fn(): array => deviceSigninRefusal(401, 'enrol_key_invalid', 'That automatic sign-in key cannot be used.');
    $db->begin_transaction();
    try {
        $uid = accountEnrolKeyRedeemLocked($db, $key, $dev['platform']);
        if ($uid === null) {
            $db->rollback();
            return $refuse();
        }
        if (isset($hooks['allowPerson']) && !$hooks['allowPerson']($uid)) {
            $db->rollback();
            return deviceSigninRefusal(429, 'rate_limited', 'Too many requests. Please try again later.');
        }
        if (apiTokenInstallSignInEnded($db, $uid, $dev['installId'])) {
            $db->rollback();   /* also undoes the key's extension */
            return $refuse();
        }
        $exp   = null;
        $token = apiTokenMint($db, $uid, $dev['meta'], 'enrol', $exp, $dev['installId']);
        $db->commit();
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_ignore) { /* best-effort */ }
        throw $e;
    }
    if (isset($hooks['recordPerson'])) {
        $hooks['recordPerson']($uid);
    }
    return deviceSigninAnswer(200, [
        'token'     => $token,
        'device'    => ['id' => apiTokenDeviceId(hash('sha256', $token))],
        'expiresAt' => gmdate('Y-m-d H:i:s', (int)$exp),
    ], $uid);
}

/** REVOKE KEYS ("turn off automatic sign-in"): cancel every key the person holds. Never switched off. */
function deviceSigninKeysRevoke(\mysqli $db, int $userId): array
{
    $n = accountEnrolKeysReady($db) ? accountEnrolKeyRevokeAll($db, $userId, null) : 0;
    return deviceSigninAnswer(200, ['revoked' => $n], $userId);
}

/**
 * SIGN OUT EVERYWHERE: end every sign-in (keeping the caller's own when `$keepCurrent`), cancel every
 * key and remove the Apple push registrations, all through the one door. Never switched off.
 */
function deviceSigninSignOutEverywhere(\mysqli $db, int $userId, ?string $callerHash, bool $keepCurrent): array
{
    $keep = ($keepCurrent && $callerHash !== null) ? $callerHash : null;
    $r = apiTokenRevokeAllForUser($db, $userId, $keep, 'signout_everywhere');
    return deviceSigninAnswer(200, [
        'tokensRevoked'     => $r['tokens'],
        'enrolKeysRevoked'  => $r['enrolKeys'],
        'keptCurrent'       => $keep !== null,
    ], $userId);
}
