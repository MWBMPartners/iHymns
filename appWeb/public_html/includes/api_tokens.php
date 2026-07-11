<?php

declare(strict_types=1);

/**
 * iHymns — API token device metadata + manage-devices helpers (#1409, Apple
 * Phase-2 PR-12)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: every "device" signed into iHymns (a phone, a TV, a browser tab) is
 * really just one row in `tblApiTokens`. This file lets that row ALSO
 * remember a friendly name / which platform / which app version / when it
 * was last used — so a "your devices" screen can list them and let someone
 * sign one out remotely, without ever showing the real secret bearer token
 * back to anyone (including the person asking).
 *
 * DETAILED: `tblApiTokens.DeviceName/Platform/AppVersion/LastSeenAt` shipped
 * live-dormant in #1511 (`appWeb/.sql/schema.sql` +
 * `migrate-apple-phase2-live-schema.php`) — this module is the FIRST
 * consumer. Every read/write here is
 * apiTokensDeviceMetaColumnsExist()-gated so an un-migrated docroot degrades
 * to "skip silently" (writers) or an empty result (readers) instead of a
 * STRICT-mode `mysqli_sql_exception` on a missing column (CLAUDE.md rule
 * #19/#28).
 *
 * DEVICE IDENTIFIER — WHY A HASH PREFIX, NEVER THE RAW TOKEN:
 * `tblApiTokens`'s PRIMARY KEY is the token's OWN sha256 hash (`Token`) —
 * there is no separate surrogate id column, and adding one would re-open a
 * table this codebase deliberately keeps as a one-pass shipped shape (rule
 * #20). A "device id" handed to the CLIENT is therefore a TRUNCATED PREFIX
 * of that hash (`API_TOKEN_DEVICE_ID_LENGTH` hex chars — 64 bits at the
 * default 16, astronomically collision-safe within one user's own handful
 * of tokens; even a same-user collision would only ever let them sign out
 * one of their OWN other devices, never cross a user boundary, because
 * every lookup this module does is ALSO scoped to `UserId = ?`). This is
 * NOT the raw bearer token: a hash cannot be reversed to recover it (SHA-256
 * preimage resistance), and presenting the hash back as if it WERE a bearer
 * credential fails outright — `getAuthenticatedUser()` re-hashes whatever is
 * presented in `Authorization: Bearer` and compares against the STORED
 * hash, so handing back the hash itself would need to be hashed AGAIN to
 * authenticate, which never matches. Never widen any response to the full
 * 64-char hash NOR to the raw token.
 *
 * @link https://www.php.net/manual/en/function.hash.php  hash('sha256', …)
 * @see includes/device_code.php  the sibling #1407 module this one mirrors
 *      the memoised-probe / dormancy conventions of
 */

/** How many leading hex chars of a token's sha256 identify it to the
 *  client — see the file header for the collision-safety reasoning. Chosen
 *  slightly above the existing `token_prefix` AUDIT-LOG convention (12
 *  chars, e.g. api.php's `logActivity(..., ['token_prefix' => substr(hash(...), 0, 12)])`
 *  calls) because THIS value doubles as a lookup key (device_signout), not
 *  just a human-readable debug label. */
const API_TOKEN_DEVICE_ID_LENGTH = 16;

/** Closed vocabulary for the OPTIONAL client-declared `platform` field —
 *  VARCHAR, app-validated here, never an ENUM (CLAUDE.md rule #20). Mirrors
 *  `ANALYTICS_INGEST_ALLOWED_PLATFORMS` (includes/analytics_ingest.php) so a
 *  device's `platform` value reads consistently with the analytics
 *  ingestion vocabulary used elsewhere in the app — not re-derived. */
const API_TOKEN_DEVICE_PLATFORMS = ['apple', 'android', 'web'];

/**
 * Memoised INFORMATION_SCHEMA existence probe — true only when ALL FOUR
 * #1409 columns are present. A partial apply (e.g. an interrupted migration
 * run) must never look "ready": this checks a COUNT(*) across all four
 * names rather than probing just one (mirrors the multi-object OR-probe
 * convention CLAUDE.md rule #19 describes for a migration registry probe,
 * applied here as an AND-across-columns check since every consumer in this
 * module needs the FULL set to behave correctly).
 */
function apiTokensDeviceMetaColumnsExist(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblApiTokens'
                AND COLUMN_NAME IN ('DeviceName', 'Platform', 'AppVersion', 'LastSeenAt')"
        );
        $st->execute();
        $count = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        $exists = ($count === 4);
    } catch (\Throwable $_e) {
        $exists = false; /* un-migrated install → treat as absent → callers degrade */
    }
    return $exists;
}

/**
 * Clean an OPTIONAL client-declared device-metadata triple from a decoded
 * sign-in request body. Every field is genuinely optional — an existing
 * native client that never sends them (every caller today) must sign in
 * byte-identically to before this addition, so malformed/absent input
 * degrades to null, NEVER a 400 on the sign-in itself. PURE (no DB, no
 * superglobals) — safe to unit test without a request.
 *
 * @param mixed $body The decoded JSON request body (or a non-array, e.g.
 *                     when json_decode() failed).
 * @return array{deviceName:?string,platform:?string,appVersion:?string}
 */
function apiTokenDeviceMetaFromBody(mixed $body): array
{
    if (!is_array($body)) {
        $body = [];
    }

    $deviceName = null;
    if (isset($body['deviceName']) && is_string($body['deviceName'])) {
        $trimmed = trim($body['deviceName']);
        $deviceName = $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
    }

    $platform = null;
    if (isset($body['platform']) && is_string($body['platform'])) {
        $candidate = strtolower(trim($body['platform']));
        $platform = in_array($candidate, API_TOKEN_DEVICE_PLATFORMS, true) ? $candidate : null;
    }

    $appVersion = null;
    if (isset($body['appVersion']) && is_string($body['appVersion'])) {
        $trimmed = trim($body['appVersion']);
        /* Loose version-string shape (digits/dots/dashes/letters, e.g.
           "1.4.2" or "1.4.2-beta.1") capped at the column's own VARCHAR(20)
           width — anything wildly outside that shape is dropped rather than
           truncated/garbled into the column. */
        $appVersion = ($trimmed !== '' && preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $trimmed)) ? $trimmed : null;
    }

    return ['deviceName' => $deviceName, 'platform' => $platform, 'appVersion' => $appVersion];
}

/**
 * Persist an OPTIONAL device-metadata triple against the token row a
 * sign-in flow (auth_login / auth_apple / completeEmailLogin) JUST
 * inserted. No-ops silently — never throws, never blocks sign-in — both
 * when the columns aren't migrated yet AND when every field was null: a
 * sign-in must NEVER fail because of this best-effort metadata write, which
 * is why every caller invokes this AFTER its own token INSERT has already
 * committed (mirrors how `setAuthTokenCookie()` is called post-insert too).
 *
 * @param string $tokenHash sha256 hex of the just-minted raw token — the
 *                           SAME value already written to
 *                           `tblApiTokens.Token` by the caller.
 */
function apiTokenDeviceMetaStore(\mysqli $db, string $tokenHash, ?string $deviceName, ?string $platform, ?string $appVersion): void
{
    if ($deviceName === null && $platform === null && $appVersion === null) {
        return;
    }
    if (!apiTokensDeviceMetaColumnsExist($db)) {
        return;
    }
    try {
        $stmt = $db->prepare(
            'UPDATE tblApiTokens SET DeviceName = ?, Platform = ?, AppVersion = ? WHERE Token = ?'
        );
        $stmt->bind_param('ssss', $deviceName, $platform, $appVersion, $tokenHash);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[api_tokens/deviceMetaStore] ' . $e->getMessage());
    }
}

/**
 * Truncated, display-and-lookup-safe identifier for a token row — see the
 * file header for why this is safe to hand to the client (never the raw
 * token, never the full hash).
 */
function apiTokenDeviceId(string $tokenHash): string
{
    return substr($tokenHash, 0, API_TOKEN_DEVICE_ID_LENGTH);
}
