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

    $deviceName = apiTokenCleanDeviceName($body['deviceName'] ?? null);

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
 * Trim + width-cap a client-supplied device name to the column's VARCHAR(120),
 * or null when it is absent/blank/non-string. PURE — the ONE place a device
 * name is normalised, shared by the sign-in body parser (above) and the
 * `device_rename` write path (api.php), so the two can never disagree on what
 * "" or an over-long name means (rule #35 — a mechanism, not two copies).
 *
 * @param mixed $raw
 * @return ?string A non-empty string ≤120 code points, or null (blank = clear).
 * @link https://www.php.net/manual/en/function.mb-substr.php
 */
function apiTokenCleanDeviceName(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $trimmed = trim($raw);
    return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
}

/**
 * Derive a FRIENDLY, display-only device label from a browser `User-Agent`
 * string — e.g. "Chrome on Windows", "Safari on iPhone" — or null when the UA
 * is not a recognised web browser (a native app with a bespoke UA, a bot, a
 * script). #1975: this is what stops a fresh WEB sign-in showing as "Unnamed
 * device" — the label is derived SERVER-SIDE from the request UA at token-issue
 * time, so it covers every web sign-in path in one place with no client change
 * and no schema column (the design chosen over a per-sign-in client field,
 * which would half-ship the moment one of the ~6 mint sites forgot it — rule
 * #33/#35). PURE (no superglobals) so it is a unit-testable truth table.
 *
 * Returns null — rather than guessing "web" — for an UNRECOGNISED UA, so a
 * native/other client that sent no `platform` is left exactly as it was
 * (Unnamed) instead of being mislabelled a browser; a native client that sends
 * its own name/platform overrides this entirely (see apiTokenWebDeviceFallback).
 *
 * The browser checks are ORDER-SENSITIVE: Chrome/Edge/Opera/Samsung UAs all
 * contain the literal "Safari/", and Edge/Opera contain "Chrome/", so the more
 * specific tokens are tested first. Display-only + escaped on render, so this
 * is never a security or auth decision — a wrong guess is only a cosmetic label.
 *
 * @param string $ua The raw `User-Agent` header.
 * @return ?string "Browser on OS", "Browser", or null when unrecognised.
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/User-Agent
 */
function apiTokenBrowserLabelFromUA(string $ua): ?string
{
    if ($ua === '') {
        return null;
    }

    /* OS family (best-effort; null when none matches). */
    $os = null;
    if (stripos($ua, 'iPhone') !== false)                                     { $os = 'iPhone'; }
    elseif (stripos($ua, 'iPad') !== false)                                   { $os = 'iPad'; }
    elseif (stripos($ua, 'Android') !== false)                                { $os = 'Android'; }
    elseif (stripos($ua, 'CrOS') !== false)                                   { $os = 'ChromeOS'; }
    elseif (stripos($ua, 'Windows NT') !== false)                             { $os = 'Windows'; }
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) { $os = 'Mac'; }
    elseif (stripos($ua, 'Linux') !== false)                                  { $os = 'Linux'; }

    /* Browser (order matters — see the doc-block). */
    $browser = null;
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgiOS') !== false || stripos($ua, 'EdgA') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'SamsungBrowser') !== false) {
        $browser = 'Samsung Internet';
    } elseif (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $browser = 'Safari';
    }

    if ($browser === null) {
        return null; /* not a recognised browser → caller must NOT claim 'web' */
    }
    return $os !== null ? ($browser . ' on ' . $os) : $browser;
}

/**
 * Apply the #1975 web auto-name fallback to a device-metadata pair. When the
 * client declared NO platform AND the request UA is a recognised browser, fill
 * in `platform = 'web'` and a friendly name derived from the UA; otherwise
 * return the inputs unchanged. PURE (UA passed in) so it is unit-testable.
 *
 * Precedence: an explicit client `platform` (native app, or a web client that
 * sent one) is ALWAYS respected — the fallback only ever fills a gap, never
 * overrides. An unrecognised UA leaves the pair untouched (stays Unnamed rather
 * than a wrong "web" guess).
 *
 * @return array{deviceName:?string,platform:?string}
 */
function apiTokenWebDeviceFallback(?string $deviceName, ?string $platform, string $ua): array
{
    if ($platform !== null) {
        return ['deviceName' => $deviceName, 'platform' => $platform];
    }
    $label = apiTokenBrowserLabelFromUA($ua);
    if ($label === null) {
        return ['deviceName' => $deviceName, 'platform' => null];
    }
    return ['deviceName' => $deviceName ?? $label, 'platform' => 'web'];
}

/**
 * Persist an OPTIONAL device-metadata triple against the token row a
 * sign-in flow (auth_login / auth_apple / completeEmailLogin / auth_register)
 * JUST inserted. No-ops silently — never throws, never blocks sign-in — both
 * when the columns aren't migrated yet AND when every field is null: a
 * sign-in must NEVER fail because of this best-effort metadata write, which
 * is why every caller invokes this AFTER its own token INSERT has already
 * committed (mirrors how `setAuthTokenCookie()` is called post-insert too).
 *
 * #1975: applies the web auto-name fallback (apiTokenWebDeviceFallback) reading
 * the request `User-Agent` FIRST, so a web sign-in that volunteered nothing is
 * still stored with `platform='web'` + a friendly name instead of falling
 * through to "Unnamed device". Doing it here — the ONE function every sign-in
 * mint funnels through — means no per-mint client change can be forgotten.
 *
 * @param string $tokenHash sha256 hex of the just-minted raw token — the
 *                           SAME value already written to
 *                           `tblApiTokens.Token` by the caller.
 */
function apiTokenDeviceMetaStore(\mysqli $db, string $tokenHash, ?string $deviceName, ?string $platform, ?string $appVersion): void
{
    /* #1975 — web auto-name fallback from the request UA, applied BEFORE the
       all-null short-circuit so a UA-derived web label is not discarded. */
    $ua = (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']))
        ? $_SERVER['HTTP_USER_AGENT'] : '';
    $fb = apiTokenWebDeviceFallback($deviceName, $platform, $ua);
    $deviceName = $fb['deviceName'];
    $platform   = $fb['platform'];

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

/**
 * Resolve an `Authorization: Bearer <token>` header (native curator apps —
 * `.claude/api-coverage-2026-08-28.md` §3 X1/X3, Batch 2) into a user row,
 * or null when no Bearer header is present or the token doesn't verify.
 *
 * ELI5: the /manage/ web pages prove who you are with a browser cookie; a
 * native app has no cookie jar, so it proves who it is with a bearer token
 * instead — this is the ONE function that checks what such a token means,
 * so every endpoint that wants to accept one calls this instead of
 * re-typing the query.
 *
 * DETAILED — THE ONE VERIFICATION CORE (CLAUDE.md rule #22): mirrors the
 * PUBLIC `api.php`'s `getAuthBearerToken()` regex (`/^Bearer\s+([a-f0-9]{64})$/i`,
 * header OR `REDIRECT_HTTP_AUTHORIZATION` for CGI/FastCGI setups that don't
 * forward `Authorization` under its own name) and `getAuthenticatedUser()`'s
 * verification query — SAME table (`tblApiTokens` JOINed to `tblUsers`), SAME
 * sha256-hash-then-compare, SAME `ExpiresAt > now` + `IsActive = 1` checks.
 * `api.php` is a dispatcher script that routes on `$_GET['action']` the
 * instant it is loaded, so nothing may `require` it as a library to reuse
 * that logic directly — this function is the shared extraction every OTHER
 * Bearer-capable endpoint (`manage/editor/api2.php`, the legacy
 * `manage/editor/api.php`, `manage/places-api.php`) delegates to instead of
 * each forking its own copy of the same SQL, which is exactly the "shared
 * module" the modularity rule (CLAUDE.md, top of file) requires once a
 * second consumer appears.
 *
 * Returned in the SAME lowercase-key shape `manage/includes/auth.php`'s
 * `getCurrentUser()` returns (`id`/`username`/`display_name`/`role`/
 * `email`), so a caller can drop the result straight into `$currentUser` and
 * every `hasRole()`/`userHasEntitlement()` call downstream keeps working
 * completely unchanged regardless of which auth path populated it.
 *
 * Deliberately STATELESS — does not touch `$_SESSION` (unlike the cookie
 * path's `adoptApiTokenSession()`) and does not slide the token's expiry
 * (unlike `getAuthenticatedUser()`'s own `slideAuthTokenExpiry()`, which
 * lives in the dispatcher for the same "not a library" reason above): a
 * Bearer request must never mutate ambient state a concurrent
 * cookie-authenticated request on the same server process could observe. A
 * Bearer client's token still gets its expiry slid whenever it calls the
 * public `api.php` (sign-in, song reads, …), which every such client
 * already does — so this is a scope-minimal omission, not a functional gap.
 *
 * @param \mysqli $db An already-open connection (getDbMysqli()).
 * @return array{id:int,username:string,display_name:?string,role:string,email:?string}|null
 * @link https://www.php.net/manual/en/function.hash-equals.php  (token compare pattern this mirrors — sha256 lookup, not a per-request hash_equals, matching getAuthenticatedUser()'s own approach)
 */
function apiTokenResolveBearerUser(\mysqli $db): ?array
{
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $hdr, $m)) {
        return null;
    }
    $token = $m[1];

    try {
        $hashedToken = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'SELECT u.Id AS id, u.Username AS username, u.DisplayName AS display_name,
                    u.Role AS role, u.Email AS email
               FROM tblApiTokens t
               JOIN tblUsers u ON u.Id = t.UserId
              WHERE t.Token = ? AND t.ExpiresAt > ? AND u.IsActive = 1
              LIMIT 1'
        );
        $stmt->bind_param('ss', $hashedToken, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[api_tokens/resolveBearerUser] ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    return $row;
}
