<?php

declare(strict_types=1);

/**
 * iHymns — RFC 8628 OAuth Device Authorization Grant helpers (#1407)
 *
 * PURPOSE:
 * ELI5: this is the "type this code shown on your TV into your phone's
 * browser" sign-in flow. A limited-input client (tvOS today; any future
 * limited-input device tomorrow) can't type a username/password. Instead it
 * asks the server for a random secret ("device_code", never shown) plus a
 * short human-friendly code ("user_code", e.g. "ABCD-EFGH") that IS shown
 * on-screen. The user opens iHymns on a phone/laptop they're already signed
 * into, types the short code at /link, and approves. Meanwhile the TV keeps
 * asking "am I approved yet?" (polling) until the answer is yes — at which
 * point it gets a normal iHymns bearer token, exactly as if it had typed a
 * password itself.
 *
 * DETAILED — RFC 8628 (OAuth 2.0 Device Authorization Grant):
 * @link https://www.rfc-editor.org/rfc/rfc8628
 *   §3.1 the user_code the human enters; §3.2 the device_code + the full
 *   `device_authorization_response` shape (device_code, user_code,
 *   verification_uri, verification_uri_complete, expires_in, interval);
 *   §3.4 the token exchange on approval; §3.5 the `slow_down` /
 *   `authorization_pending` / `access_denied` / `expired_token` polling error
 *   vocabulary this module's callers in api.php reproduce.
 *
 * SCHEMA: this module talks to `tblAuthDeviceCodes`, which shipped
 * live-dormant in #1511 (`appWeb/.sql/schema.sql` +
 * `migrate-apple-phase2-live-schema.php`) — this PR (#1407) is the FIRST
 * consumer. Every read/write in here (and in the api.php case blocks that
 * call it) is `authDeviceCodesTableExists()`-gated so a docroot where the
 * migration card hasn't been run yet degrades to a clean 503 instead of a
 * white-screening STRICT-mode mysqli exception (CLAUDE.md rule #19 dormancy
 * + the #1228 "false-check is dead code, a missing table THROWS" lesson).
 *
 * SECURITY (mirrors Service Mode's #1335/#1406 conventions, rule #26):
 *   - CHANNEL-SCOPED: every query filters `Channel = serviceMode_channel()`
 *     so an alpha-minted device_code/user_code can never be approved (or
 *     exchanged) against beta/production — the 3-docroot shared-DB class of
 *     bug this rule exists to prevent.
 *   - The raw `device_code` is NEVER stored — only its SHA-256 hex
 *     (`DeviceCodeHash`). Same discipline as `tblPasswordResetTokens` /
 *     `tblApiTokens.Token`.
 *   - `user_code` reuses `serviceMode_generateCode()` (Service Mode's
 *     Crockford base32 join-code minter, #1335) rather than forking a SECOND
 *     base32 alphabet — CLAUDE.md's modularity rule: one alphabet, one
 *     minter, reused.
 *   - Rate limiting is deliberately asymmetric by endpoint (rule #26 — never
 *     a blanket per-IP cap where a shared identity exists to key on
 *     instead): the mint endpoint (no identity yet) is per-IP; the poll
 *     endpoint is keyed on the device_code's OWN hash (never per-IP — many
 *     limited-input devices can legitimately share one IP, e.g. a church's
 *     media room); the /link approve/deny/lookup calls are keyed on the
 *     AUTHENTICATED user id (never per-IP, for the same reason).
 *
 * BREADCRUMBS (observability §3.2 convention, #1512): `auth.device_code.
 * request` / `.approve` / `.deny` / `.consume` are logged by the api.php
 * case blocks that call into this module — IDs ONLY, never the device_code,
 * the user_code, or a digest of either (unlike, say, `auth.login`'s
 * `token_prefix`, a device_code/user_code is a short-lived SHARED SECRET a
 * human is meant to read off a screen and type elsewhere; logging even a
 * hash narrows the guess space for an attacker who can see the audit log).
 * Polls are NEVER logged (mirrors `service_poll` — logging every ~5s poll
 * from a waiting TV would flood the table for zero audit value).
 *
 * @link https://www.php.net/manual/en/function.hash.php            hash('sha256', …)
 * @link https://www.php.net/manual/en/function.random-bytes.php    random_bytes()
 * @link https://www.php.net/manual/en/mysqli-stmt.bind-param.php   bind_param()
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Reuses serviceMode_channel() (the 3-docroot env discriminator) and
   serviceMode_generateCode() (the Crockford base32 minter) — see the
   file-header note above on why a second alphabet/minter is never forked. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';

/**
 * RFC 8628 §3.2 `expires_in` — how long a freshly-minted device_code/
 * user_code pair stays exchangeable. Short-lived by design: a code sitting
 * unclaimed on a TV screen for hours would be an unnecessarily long window
 * for someone else in the room to type it in.
 */
const AUTH_DEVICE_CODE_TTL_SECONDS = 300;

/**
 * RFC 8628 §3.2/§3.5 `interval` — the minimum seconds a client MUST wait
 * between polls. A poll faster than this gets `slow_down` (§3.5) instead of
 * the real answer. 5s matches every major device-flow implementation's
 * default (Google, GitHub, Microsoft) and keeps the poll endpoint cheap.
 */
const AUTH_DEVICE_CODE_POLL_INTERVAL_SECONDS = 5;

/**
 * Closed vocabulary for `tblAuthDeviceCodes.Status` — VARCHAR, app-validated
 * here, never an ENUM (CLAUDE.md rule #20: an ENUM value-add is itself a
 * migration). The shipped migration's column COMMENT documents four values
 * (`pending | approved | denied | expired`, written before this PR existed);
 * `consumed` is this PR's addition — the single-use post-exchange terminal
 * state (RFC 8628 device_codes are one-shot: once successfully exchanged for
 * a bearer token, a replay of the SAME device_code must never mint a second
 * token). It fits the column's existing `VARCHAR(20)` unchanged, so no
 * schema touch was needed to add it — exactly the point of VARCHAR-not-ENUM.
 */
const AUTH_DEVICE_CODE_STATUSES = ['pending', 'approved', 'denied', 'expired', 'consumed'];

/** Brute-force guard (rule: "guard against brute-forcing the user_code") —
 *  shared budget across the /link lookup + approve + deny calls, keyed per
 *  AUTHENTICATED user (never per-IP — a shared church office IP must not
 *  throttle every signed-in curator at once). */
const AUTH_DEVICE_CODE_LINK_ATTEMPT_MAX            = 10;
const AUTH_DEVICE_CODE_LINK_ATTEMPT_WINDOW_SECONDS = 600;

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblAuthDeviceCodes`.
 *
 * ELI5: "does the pairing table exist yet? remember the answer so we only
 * ask once per request."
 *
 * DETAILED: mirrors the established per-module probe pattern
 * (`_readRateLimitTableExists()`, `serviceMode_presenceRoleColumnExists()`,
 * `creditPersonMembersTableExists()`, …) rather than a shared generic
 * `tableExists()` — this codebase deliberately keeps each probe local +
 * memoised per module (CLAUDE.md #19/#28 dormancy convention) so every
 * caller degrades independently. mysqli runs under
 * `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` (`db_mysql.php`), so a bare
 * query against a missing table THROWS (the #1228 lesson) — this returns
 * `false` on ANY error, never lets that exception escape.
 */
function authDeviceCodesTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAuthDeviceCodes' LIMIT 1"
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
 * Generate a fresh RFC 8628 §3.1 `user_code` — 8 Crockford base32 characters
 * (reusing `serviceMode_generateCode()`, #1335 — no ambiguous glyphs, so a
 * human reading it off a TV never confuses 0/O or 1/I/L), formatted with a
 * separator (`ABCD-EFGH`) purely for on-screen/typing readability. The
 * separator is stripped again by `deviceCode_normaliseUserCode()` before any
 * DB lookup, so storage stays a single flat 9-char string
 * (`tblAuthDeviceCodes.UserCode VARCHAR(12)` comfortably fits it).
 *
 * PURE apart from its call into `random_int()` (via serviceMode_generateCode)
 * — no DB, no superglobals.
 */
function deviceCode_generateUserCode(): string
{
    $raw = serviceMode_generateCode(8);
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
}

/**
 * Normalise a user-submitted `user_code` (from the /link form, or a
 * `?user_code=` deep-link query param) into the canonical stored shape, or
 * `''` when the input can't possibly be a valid code.
 *
 * ELI5: "however the human typed/pasted it — lowercase, no dash, extra
 * spaces — turn it into the exact shape we saved in the database, or say
 * 'nope' if it's not even the right length/alphabet."
 *
 * DETAILED: strips everything except letters/digits (mirrors
 * `service_join`'s own code-cleaning: `strtoupper(preg_replace('/[^A-Za-z0-
 * 9]/', '', …))`), then rejects anything that isn't EXACTLY 8 characters
 * drawn from `SERVICE_MODE_CODE_ALPHABET` (Service Mode's own Crockford
 * base32 set, service_mode.php) — a length/alphabet mismatch can never match
 * a row we minted, so failing fast here saves a wasted DB round-trip and
 * gives a consistent opaque-shaped rejection alongside the "not found" case
 * the caller returns on a genuine miss.
 *
 * PURE — no DB, no superglobals.
 *
 * @param mixed $raw Untrusted input (`$_GET['user_code']` / decoded JSON body field).
 * @return string The canonical `XXXX-XXXX` form, or `''` if invalid.
 */
function deviceCode_normaliseUserCode(mixed $raw): string
{
    /* Reject non-string input up front — a `?user_code[]=` query-string
       trick or a JSON body sending a number/array/bool must degrade to the
       same "invalid" result as any other malformed code, WITHOUT emitting a
       PHP "Array to string conversion" warning by (string)-casting an
       array. */
    if (!is_string($raw)) {
        return '';
    }
    $stripped = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';
    $upper    = strtoupper($stripped);
    if (strlen($upper) !== 8) {
        return '';
    }
    if (preg_match('/[^' . preg_quote(SERVICE_MODE_CODE_ALPHABET, '/') . ']/', $upper)) {
        return '';
    }
    return substr($upper, 0, 4) . '-' . substr($upper, 4, 4);
}

/**
 * Mint + persist a fresh device_code/user_code pair scoped to `$channel`
 * (rule #26). Returns the RAW device_code (the caller hands this to the
 * requesting client — it is NEVER stored) alongside the human user_code.
 *
 * DETAILED: `DeviceCodeHash` and `UserCode` both carry a UNIQUE constraint
 * (the shipped DDL, migrate-apple-phase2-live-schema.php). At this
 * entropy/alphabet size a collision is astronomically unlikely, but this
 * mirrors the established retry-on-1062 shape (`serviceMode_mintCode()`,
 * `service_session_start`'s SessionCode insert) rather than assuming success
 * — six attempts, bind-once-mutate-in-loop (the bound PHP variables are
 * re-read by reference on each `execute()`, so `bind_param()` is called
 * exactly once outside the loop).
 *
 * @return array{0:?string,1:?string} [deviceCode, userCode], or [null, null]
 *                                     if every retry collided (caller 503s).
 */
function deviceCode_mint(\mysqli $db, string $channel): array
{
    $ttl        = AUTH_DEVICE_CODE_TTL_SECONDS;
    $hash       = '';
    $userCode   = '';
    $ins = $db->prepare(
        "INSERT INTO tblAuthDeviceCodes (DeviceCodeHash, UserCode, Status, Channel, ExpiresAt)
         VALUES (?, ?, 'pending', ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))"
    );
    $ins->bind_param('sssi', $hash, $userCode, $channel, $ttl);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $deviceCode = bin2hex(random_bytes(32)); /* 64 hex chars — matches tblApiTokens' own raw-token shape */
        $hash       = hash('sha256', $deviceCode);
        $userCode   = deviceCode_generateUserCode();
        try {
            $ins->execute();
            $ins->close();
            return [$deviceCode, $userCode];
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {
                $ins->close();
                throw $e;
            }
            /* UNIQUE collision on DeviceCodeHash or UserCode — retry with fresh values. */
        }
    }
    $ins->close();
    return [null, null];
}
