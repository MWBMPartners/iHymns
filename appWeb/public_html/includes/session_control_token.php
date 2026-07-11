<?php

declare(strict_types=1);

/**
 * iHymns — Session control tokens (#1408, Apple Phase-2 PR-9)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: normally only the person actually signed in as a live/service
 * session's operator can drive the screen (pick the next song, blank it,
 * nudge the current line). This lets that person hand a SEPARATE, throwaway
 * key to a co-leader's device for "just THIS one session" — the co-leader
 * never sees the operator's username/password, and the key can be switched
 * off instantly the moment it's no longer needed (e.g. at the end of the
 * service, or if the device is lost).
 *
 * DETAILED: `tblSessionControlTokens` shipped live-dormant in #1511
 * (`appWeb/.sql/schema.sql` + `migrate-apple-phase2-live-schema.php`) — this
 * module is the FIRST consumer. Every read/write here is
 * sessionControlTokensTableExists()-gated so an un-migrated docroot
 * degrades to a clean "not available yet" response instead of a
 * STRICT-mode `mysqli_sql_exception` on a missing table (CLAUDE.md rule
 * #19/#28 dormancy convention; the #1228 "a false-check is dead code, a
 * missing table THROWS" lesson).
 *
 * SCOPE: today the ONLY consumer is `?action=service_broadcast` (api.php) —
 * a minted token grants `Scope='broadcast'` against exactly ONE
 * `SessionKind='service'` `tblLiveFollowSessions` row (the same row
 * `service_broadcast` itself operates on). `Scope` is VARCHAR, app-validated
 * against SESSION_CONTROL_TOKEN_SCOPES below (CLAUDE.md rule #20 — never an
 * ENUM), so a future read-only `'view'` scope needs no schema change.
 *
 * SECURITY (mirrors `includes/device_code.php`'s #1407 conventions, rule
 * #26 — the 3-docroot shared-DB env discriminator):
 *   - CHANNEL-SCOPED: every mint/validate/revoke filters
 *     `Channel = serviceMode_channel()` so an alpha-minted token can never
 *     control a beta/production session.
 *   - The raw token is NEVER stored — only its SHA-256 hex (`TokenHash`),
 *     the same discipline as `tblApiTokens.Token` /
 *     `tblAuthDeviceCodes.DeviceCodeHash`.
 *   - Revoke is a bulk "stop delegation on this session" action (every
 *     still-live token for that `SessionId`), not a per-token lookup — the
 *     operator never needs to hold/store an opaque token id to switch
 *     delegated access off, and nothing token-shaped is ever echoed back
 *     to the operator after mint (only the raw token itself, exactly once,
 *     in the mint response).
 *
 * BREADCRUMBS (observability §3.2 convention, #1512): the api.php case
 * blocks that call into this module log `service.control_token.mint` /
 * `.revoke` — IDs ONLY (`session_id`, `channel`), NEVER the raw token or a
 * hash of it (mirrors `device_code.php`'s discipline for the same reason —
 * this is a short-lived bearer-style secret, not a stable identifier; even
 * logging a hash prefix narrows an attacker's guess space for zero audit
 * value).
 *
 * @link https://www.php.net/manual/en/function.hash.php            hash('sha256', …)
 * @link https://www.php.net/manual/en/function.random-bytes.php    random_bytes()
 * @link https://www.php.net/manual/en/mysqli-stmt.bind-param.php   bind_param()
 * @see includes/service_mode.php   serviceMode_channel() — the channel discriminator reused here
 * @see includes/device_code.php    the sibling #1407 module this one's shape mirrors
 */

/* Reuses serviceMode_channel() (the 3-docroot env discriminator) — see the
   file-header note above on why every Phase-2 auth module shares this one
   source instead of re-deriving the channel itself. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';

/** Closed vocabulary for `tblSessionControlTokens.Scope` — VARCHAR,
 *  app-validated here, never an ENUM (CLAUDE.md rule #20: an ENUM value-add
 *  is itself a migration). Only `broadcast` has a consumer today; a future
 *  read-only `view` scope (e.g. a "watch along, can't drive" delegate) fits
 *  the existing `VARCHAR(40)` column unchanged. */
const SESSION_CONTROL_TOKEN_SCOPES = ['broadcast'];

/**
 * Fallback short TTL (seconds) for a freshly-minted control token. The
 * ACTUAL expiry is `LEAST(session end, now + this)` (computed SQL-side in
 * sessionControlToken_mint()) — this constant only matters when it is
 * SHORTER than the session's own remaining lifetime, so a delegated device
 * is never implicitly trusted for the FULL length of a long session. 2
 * hours comfortably covers "hand the phone to a co-leader for the second
 * half of a service" without matching the full 4h hard ceiling
 * (`SERVICE_MODE_HARD_CEILING_HOURS`, service_mode.php) — an operator who
 * needs longer simply mints again (cheap, revocable, no re-auth required).
 */
const SESSION_CONTROL_TOKEN_TTL_SECONDS = 7200;

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblSessionControlTokens`.
 * Mirrors `authDeviceCodesTableExists()` (device_code.php) — see that
 * function's docblock for why this codebase keeps one memoised probe per
 * module rather than a single shared generic `tableExists()` helper (each
 * module degrades independently, and the probe result is cheap to compute
 * once per request and reuse).
 */
function sessionControlTokensTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSessionControlTokens' LIMIT 1"
        );
        $st->execute();
        $exists = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $_e) {
        $exists = false; /* un-migrated install → treat as absent → caller 503s */
    }
    return $exists;
}

/**
 * Resolve whether $userId (with $role) may operate the given live/service
 * session — mint a control token for it, revoke one, or (existing,
 * unchanged behaviour) broadcast to it directly.
 *
 * ELI5: "is this person allowed to drive THIS session's screen?" — yes if
 * they're a global admin/admin, the person who started the session, or an
 * admin/owner of the organisation the session belongs to.
 *
 * DETAILED: extracted here so the NEW #1408 mint/revoke endpoints reuse ONE
 * copy instead of adding a 4th/5th inline copy of the check already
 * duplicated across api.php's `service_session_start` /
 * `service_code_rotate|current|end` / `service_broadcast` handlers
 * (CLAUDE.md's non-negotiable modularity rule). Those three PRE-EXISTING
 * inline copies are deliberately left untouched by this change — unifying
 * them retroactively is a separate, lower-risk-when-isolated follow-up, not
 * a behaviour change bundled into this addition.
 *
 * @param int    $userId            The authenticated caller.
 * @param string $role              The caller's tblUsers.Role.
 * @param int    $sessionOrgId      The session's OrgId (0/absent → never
 *                                   matches an org-admin grant, only the
 *                                   global-admin/host checks can pass).
 * @param int    $sessionHostUserId The session's HostUserId.
 */
function serviceMode_userCanOperate(int $userId, string $role, int $sessionOrgId, int $sessionHostUserId): bool
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'entitlements.php';
    return ($role === 'global_admin' || $role === 'admin')
        || $sessionHostUserId === $userId
        || in_array($sessionOrgId, userIsOrgAdminOf($userId), true);
}

/**
 * Mint + persist a fresh session control token scoped to $channel + one
 * $sessionId. Returns the RAW token (the caller hands this to the operator
 * ONCE — it is never stored, never re-derivable, never logged) or null on a
 * genuine failure.
 *
 * $sessionExpiresAtUtc is the session's OWN `ExpiresAt`
 * ('Y-m-d H:i:s'-shaped UTC string, as already read by the caller from
 * `tblLiveFollowSessions`) — the token's `ExpiresAt` is computed SQL-side as
 * `LEAST(that, now + SESSION_CONTROL_TOKEN_TTL_SECONDS)` so it can never
 * drift from the session's real end via a PHP-vs-SQL clock comparison
 * (mirrors `device_code.php`'s documented "compute expiry SQL-side"
 * discipline).
 *
 * @return string|null Raw token (64 lowercase hex chars — the same shape as
 *                      `tblApiTokens`/`tblAuthDeviceCodes` raw tokens), or
 *                      null when the INSERT itself failed.
 */
function sessionControlToken_mint(\mysqli $db, int $sessionId, string $channel, string $sessionExpiresAtUtc, string $scope = 'broadcast'): ?string
{
    if (!in_array($scope, SESSION_CONTROL_TOKEN_SCOPES, true)) {
        $scope = 'broadcast';
    }
    /* 32 random bytes = 64 hex chars — the same raw-token entropy/shape as
       tblApiTokens.Token and tblAuthDeviceCodes' device_code (no retry loop
       needed for a UNIQUE collision the way the 6-char human join codes
       need one; a 256-bit random value colliding is not a practical
       concern). */
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $ttl   = SESSION_CONTROL_TOKEN_TTL_SECONDS;

    try {
        $ins = $db->prepare(
            "INSERT INTO tblSessionControlTokens (TokenHash, SessionId, Channel, Scope, IssuedAt, ExpiresAt)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), LEAST(?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)))"
        );
        $ins->bind_param('sisssi', $hash, $sessionId, $channel, $scope, $sessionExpiresAtUtc, $ttl);
        $ins->execute();
        $ins->close();
    } catch (\Throwable $e) {
        error_log('[session_control_token/mint] ' . $e->getMessage());
        return null;
    }
    return $token;
}

/**
 * Validate a presented raw control token against ONE session + channel +
 * scope. Returns true only when the token's hash matches an UNREVOKED,
 * UNEXPIRED row for exactly that `SessionId` + `Channel` + `Scope` — every
 * clause lives in the SQL `WHERE` (nothing is re-checked in PHP afterwards),
 * so this is a single indexed round trip and never a check-then-use gap.
 */
function sessionControlToken_validate(\mysqli $db, string $rawToken, int $sessionId, string $channel, string $scope = 'broadcast'): bool
{
    if ($rawToken === '' || $sessionId <= 0) {
        return false;
    }
    $hash = hash('sha256', $rawToken);
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM tblSessionControlTokens
              WHERE TokenHash = ? AND SessionId = ? AND Channel = ? AND Scope = ?
                AND RevokedAt IS NULL AND ExpiresAt > UTC_TIMESTAMP()
              LIMIT 1"
        );
        $stmt->bind_param('siss', $hash, $sessionId, $channel, $scope);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $ok;
    } catch (\Throwable $e) {
        error_log('[session_control_token/validate] ' . $e->getMessage());
        return false;
    }
}

/**
 * Revoke EVERY still-live control token for one session (channel-scoped) —
 * "stop anyone using a delegated token to control this session", not a
 * per-token action. See the file header for why there is no per-token
 * revoke: the operator never holds an id to target one specifically (mint
 * hands back the raw token exactly once and nothing token-shaped is stored
 * server-side for later reference).
 *
 * @return int Number of rows revoked (0 = nothing was live to begin with —
 *             not an error, just "already clean").
 */
function sessionControlToken_revokeAllForSession(\mysqli $db, int $sessionId, string $channel): int
{
    try {
        $upd = $db->prepare(
            'UPDATE tblSessionControlTokens SET RevokedAt = UTC_TIMESTAMP()
              WHERE SessionId = ? AND Channel = ? AND RevokedAt IS NULL'
        );
        $upd->bind_param('is', $sessionId, $channel);
        $upd->execute();
        $count = $upd->affected_rows;
        $upd->close();
        return max(0, (int)$count);
    } catch (\Throwable $e) {
        error_log('[session_control_token/revoke] ' . $e->getMessage());
        return 0;
    }
}
