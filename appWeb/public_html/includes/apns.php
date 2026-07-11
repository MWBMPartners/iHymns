<?php

declare(strict_types=1);

/**
 * iHymns — APNs bridge: ES256 provider JWT + push-token registration (#1410,
 * Apple Phase-2 PR-13) — DORMANT plumbing, no key provisioned yet
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: this is the machinery a FUTURE feature (e.g. "your co-leader just
 * requested control", "the setlist changed") would use to knock on Apple's
 * door and say "please buzz this specific phone/watch". It does two jobs:
 * (1) remember WHICH devices/Live Activities want to be buzzed
 * (`apns_register`/`apns_unregister`, backed by `tblApnsTokens`), and (2)
 * know HOW to prove to Apple's push server that the knock is really from
 * iHymns (`apnsSend()`, a signed token + an HTTPS/2 request). Nothing in
 * THIS change actually knocks on anyone's door — no code path here is
 * called by any live feature yet, and even if it were, step (2) refuses to
 * run at all until the owner pastes a real Apple-issued key.
 *
 * DETAILED: `tblApnsTokens` shipped live-dormant in #1511
 * (`appWeb/.sql/schema.sql` + `migrate-apple-phase2-live-schema.php`) —
 * this module is the FIRST consumer. Every read/write here is
 * apnsTokensTableExists()-gated so an un-migrated docroot degrades to a
 * clean "not available yet" response instead of a STRICT-mode
 * `mysqli_sql_exception` on a missing table (CLAUDE.md rule #19/#28).
 *
 * THE TWO CRYPTO/TRANSPORT OPERATIONS:
 *   1. SIGN (ES256) — Apple's token-based APNs provider API requires every
 *      push request to carry a short-lived JWT ("provider authentication
 *      token") signed with an Apple-issued EC P-256 "APNs Auth Key" (a
 *      DIFFERENT `.p8` file from the Sign-in-with-Apple key, though both
 *      belong to the same Apple Developer Team). `_apnsBuildJwt()` is the
 *      PURE core (no DB, no network — testable with a locally-generated
 *      test keypair, see tests/php/test-apns-jwt.php) and DELIBERATELY
 *      REUSES `apple_siwa.php`'s already-battle-tested base64url +
 *      DER→JOSE-signature helpers (`_appleSiwaB64UrlEncode()` /
 *      `_appleSiwaDerEcdsaToRaw()`) rather than re-forking that intricate
 *      conversion a second time (CLAUDE.md modularity rule) — see
 *      `.claude/apple-backend-auth-plan.md` §3.4 for why the DER↔JOSE
 *      boundary is the trap worth sharing code across, not duplicating.
 *      The EC-P-256-key-parse-and-validate guard itself IS still a small,
 *      separately-duplicated ~10 lines (see `_apnsBuildJwt()`'s own
 *      docblock) — a deliberate, documented trade-off: it is the
 *      MECHANICAL half of the job (not the crypto-subtle half, which IS
 *      shared), and keeping it local means this dormant, never-yet-called
 *      module carries ZERO risk of regressing the actively-used SIWA
 *      client_secret path.
 *   2. TRANSPORT (HTTP/2) — Apple's APNs REQUIRES HTTP/2; `apnsSend()`
 *      explicitly probes `curl_version()`'s feature flags and refuses to
 *      attempt a request at all (`http2_unsupported`) rather than silently
 *      falling back to HTTP/1.1, which Apple's production gateway does not
 *      accept. Shared-hosting curl builds vary — this has NOT been
 *      exercised against a live Apple endpoint from this project's actual
 *      DreamHost PHP build; flagged explicitly as an open risk rather than
 *      assumed to work (see the PR description / handoff notes).
 *
 * `.p8` CUSTODY — CORRECTED FROM "outside the docroot in `.auth/`" TO
 * "MIRROR WHAT SIWA ACTUALLY DOES":
 * The SIWA `.p8` (includes/apple_siwa.php) is NOT filesystem-custodied — it
 * lives in `tblAppSettings` (key `apple_siwa_private_key`), encrypted at
 * rest by `includes/secret_crypto.php` (#1466/#1469) and read transparently
 * via `getAppSetting()`. `appWeb/.auth/` holds only the INFRASTRUCTURE
 * bootstrap secrets that must exist before any database read is even
 * possible (`db_credentials.php`, the secret-encryption `secrets_master_
 * key.php`) — never an application-level secret like a provider `.p8`. This
 * matters operationally: the project's operator is WEB-ONLY on shared
 * DreamHost hosting (no CLI/SFTP in the normal workflow) — a `.auth/`
 * FILE-based key would need filesystem access the owner does not routinely
 * have, whereas `tblAppSettings` is reachable from the SAME
 * Global-Admin web form the SIWA key already uses. `apnsPrivateKeyPem()`
 * below therefore checks the setting FIRST (the real, provisionable-today
 * path once a future admin-UI card exists — out of THIS change's scope,
 * see the file's bottom note) and falls back to an OPTIONAL
 * `appWeb/.auth/apns_private_key.p8` file second, satisfying the literal
 * "setting-or-file" framing while keeping the setting path primary. The new
 * setting key (`apple_apns_private_key`) is added to `secretSettingKeys()`
 * (includes/secret_crypto.php) in the SAME change, so whenever an admin-UI
 * card DOES get built, the value is encrypted-at-rest from day one — this
 * is a zero-risk, purely-additive line (an absent/never-saved setting key
 * is a documented no-op for every consumer of that list, see
 * `secretInventory()`'s docblock).
 *
 * SECURITY (mirrors device_code.php's #1407 conventions, rule #26):
 *   - `tblApnsTokens` has NO `Channel` column of its own (the shipped #1511
 *     DDL) — a Live-Activity token's session-scoping is CARRIED
 *     TRANSITIVELY through its `SessionId` FK, so `apns_register` resolves
 *     (and channel-filters) the referenced `tblLiveFollowSessions` row
 *     itself when `Kind = 'liveActivity'` rather than needing a redundant
 *     column on this table.
 *   - The raw push token is stored as `VARBINARY` (matches the shipped DDL
 *     comment "Raw APNs device/activity push token bytes") — the client
 *     sends/receives it hex-encoded; `hex2bin()`/`bin2hex()` convert at the
 *     boundary.
 *   - `apnsSend()` NEVER logs a push token (raw, hex, or hashed) — only
 *     `Kind`/`ApnsEnv`/`SessionId`/HTTP status/APNs `reason` code, all
 *     fixed-vocabulary or numeric, never the token itself.
 *
 * BREADCRUMBS (observability §3.2 convention, #1512): the api.php case
 * blocks that call into this module log `apns.token.register` /
 * `.unregister` — IDs + fixed vocabulary ONLY (`kind`, `apns_env`,
 * `session_id`), NEVER the push token in any form.
 *
 * @link https://developer.apple.com/documentation/usernotifications/establishing-a-token-based-connection-to-apns  APNs provider token
 * @link https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns          APNs HTTP/2 request shape
 * @link https://developer.apple.com/documentation/activitykit/starting-and-updating-live-activities-with-activitykit-push-notifications  Live Activity push topic suffix
 * @link https://www.php.net/manual/en/function.curl-version.php    curl_version() feature flags (HTTP/2 probe)
 * @link https://www.php.net/manual/en/function.openssl-sign.php
 * @see includes/apple_siwa.php  the SIWA module this one's ES256/base64url helpers are borrowed from
 * @see includes/device_code.php the sibling #1407 module this one mirrors the dormancy conventions of
 *
 * NOT IN SCOPE FOR THIS CHANGE (deliberately, per the #1410 task brief):
 * an admin-UI card on manage/configuration.php to actually PASTE the APNs
 * Key ID / `.p8` (mirroring the SIWA card, #1402 task F) — until that
 * exists, `getAppSetting('apple_apns_key_id'|'apple_apns_private_key', '')`
 * always reads empty, so `apnsConfigured()` is always false and `apnsSend()`
 * is a guaranteed `not_configured` no-op regardless of anything else in
 * this file.
 */

/* Reuses _appleSiwaB64UrlEncode() / _appleSiwaDerEcdsaToRaw() — see the
   file-header note above on why the ES256 JOSE-encoding step is shared
   rather than re-forked. apple_siwa.php has no hard DB dependency at
   load time, so requiring it here keeps this module (and its CLI test)
   standalone-loadable too. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'apple_siwa.php';

/** Closed vocabulary for `tblApnsTokens.ApnsEnv` — VARCHAR, app-validated,
 *  never an ENUM (rule #20); matches the shipped column COMMENT exactly. */
const APNS_ENVIRONMENTS = ['sandbox', 'production'];

/** Closed vocabulary for `tblApnsTokens.Kind` — VARCHAR, app-validated,
 *  never an ENUM (rule #20); matches the shipped column COMMENT exactly. */
const APNS_KINDS = ['device', 'liveActivity'];

/** Apple's fixed Live-Activity push topic suffix (ActivityKit docs, linked
 *  above) — appended to the bundle id ONLY for Kind='liveActivity' sends. */
const APNS_LIVE_ACTIVITY_TOPIC_SUFFIX = '.push-type.liveactivity';

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblApnsTokens`. Mirrors
 * `authDeviceCodesTableExists()` (device_code.php) / `sessionControlTokensTableExists()`
 * (session_control_token.php) — one memoised probe per module, per the
 * established per-module dormancy-gate convention (CLAUDE.md rule #19/#28).
 */
function apnsTokensTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblApnsTokens' LIMIT 1"
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
 * Resolve the APNs Auth Key `.p8` PEM contents — DB setting first (the
 * real, web-provisionable-today path, mirrors SIWA), an optional
 * filesystem file second. See the file header's "`.p8` CUSTODY" section for
 * the full reasoning. Returns '' when neither source has a value — NEVER
 * throws, NEVER guesses.
 */
function apnsPrivateKeyPem(): string
{
    $fromSetting = function_exists('getAppSetting')
        ? trim((string)(getAppSetting('apple_apns_private_key', '') ?? ''))
        : '';
    if ($fromSetting !== '') {
        return $fromSetting;
    }

    /* Optional file-based override for an operator who prefers filesystem
       custody over the web Global-Admin form — mirrors the appWeb/.auth/
       bootstrap-file location convention (db_credentials.php,
       secrets_master_key.php) WITHOUT being wired into the installer: no
       .example file is shipped for this path, and nothing requires it to
       exist. Purely a secondary manual option. */
    $keyFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'apns_private_key.p8';
    if (is_file($keyFile) && is_readable($keyFile)) {
        $contents = @file_get_contents($keyFile);
        if (is_string($contents) && trim($contents) !== '') {
            return trim($contents);
        }
    }

    return '';
}

/**
 * The three credentials `_apnsBuildJwt()` needs. `apple_team_id` is REUSED
 * from the existing SIWA setting (includes/apple_siwa.php / api.php's
 * auth_apple handler already read it) — the Apple Developer Team ID is the
 * SAME value regardless of which `.p8` it signs for, so this deliberately
 * does NOT add a second, redundant team-id setting. `apple_apns_key_id` is
 * new (not secret — like `apple_siwa_key_id`, a Key ID is just a public
 * identifier, meaningless without the paired private key).
 *
 * @return array{teamId:string,keyId:string,p8Pem:string}
 */
function apnsCredentials(): array
{
    $teamId = function_exists('getAppSetting')
        ? trim((string)(getAppSetting('apple_team_id', '') ?? ''))
        : '';
    $keyId = function_exists('getAppSetting')
        ? trim((string)(getAppSetting('apple_apns_key_id', '') ?? ''))
        : '';
    return ['teamId' => $teamId, 'keyId' => $keyId, 'p8Pem' => apnsPrivateKeyPem()];
}

/**
 * Is the APNs bridge provisioned AND does the stored key actually parse as
 * a usable EC P-256 private key? A cheap "is this feature dormant or live"
 * check for callers that want to short-circuit before doing anything else
 * (e.g. a future admin status panel, mirroring `$appleSiwaPrivateKeySet`'s
 * role in manage/configuration.php).
 */
function apnsConfigured(): bool
{
    $creds = apnsCredentials();
    if ($creds['teamId'] === '' || $creds['keyId'] === '' || $creds['p8Pem'] === '') {
        return false;
    }
    return _apnsBuildJwt($creds['teamId'], $creds['keyId'], $creds['p8Pem'], time()) !== null;
}

/**
 * Mint an APNs provider-authentication JWT (ES256) — see Apple's
 * "Establishing a token-based connection to APNs" guide (linked in the file
 * header). PURE apart from the `openssl_*` calls (no DB, no network, no
 * superglobals) — every input is an explicit parameter, so
 * tests/php/test-apns-jwt.php exercises this with a LOCALLY-GENERATED test
 * keypair, never a real Apple-issued key.
 *
 * SHAPE (deliberately DIFFERENT from `appleSiwaBuildClientSecret()`'s SIWA
 * client_secret JWT, which this is a cousin of, not a copy): the header is
 * `{alg:'ES256', kid:<APNs Key ID>}`; the claims are JUST
 * `{iss:<Team ID>, iat:<now>}` — APNs provider tokens carry NO `exp`/`aud`/
 * `sub` (Apple's guide: a token is valid up to 1 hour from `iat`; Apple
 * recommends reusing/refreshing at most once per 20 minutes and at least
 * once per hour, not minting a fresh one per push).
 *
 * KEY-VALIDATION DUPLICATION (documented trade-off, see this file's header
 * "SIGN (ES256)" section): the EC-P-256-parse-and-verify block below is
 * mechanically identical to `appleSiwaBuildClientSecret()`'s own guard —
 * duplicated here rather than extracted into a THIRD shared helper, so this
 * brand-new, never-yet-called module carries zero edit risk to the
 * actively-used SIWA sign-in path. The genuinely intricate, security-
 * sensitive part (base64url + DER→JOSE signature conversion) IS shared —
 * see the two `_appleSiwa*()` calls below.
 *
 * @param string $teamId Apple Developer Team ID (10 chars) — the JWT `iss`.
 * @param string $keyId  The APNs Auth Key's Key ID (10 chars) — the JWS header `kid`.
 * @param string $p8Pem  The owner-provisioned APNs `.p8` PRIVATE KEY PEM (EC P-256, PKCS#8).
 * @param int    $now    Current unix time (injected for testability).
 * @return string|null Compact JWS, or null when the PEM doesn't parse or isn't an EC P-256 key.
 */
function _apnsBuildJwt(string $teamId, string $keyId, string $p8Pem, int $now): ?string
{
    if ($teamId === '' || $keyId === '' || trim($p8Pem) === '') {
        return null;
    }

    $privKey = @openssl_pkey_get_private($p8Pem);
    if ($privKey === false) {
        return null;
    }
    $details = @openssl_pkey_get_details($privKey);
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
        return null;
    }
    $curveName = $details['ec']['curve_name'] ?? null;
    /* P-256 is reported as either name depending on the OpenSSL build
       (same two-name check as appleSiwaBuildClientSecret()). */
    if ($curveName !== 'prime256v1' && $curveName !== 'secp256r1') {
        return null;
    }

    $header = ['alg' => 'ES256', 'kid' => $keyId];
    $claims = ['iss' => $teamId, 'iat' => $now];

    $h64 = _appleSiwaB64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $p64 = _appleSiwaB64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = "{$h64}.{$p64}";

    $derSig = '';
    if (!openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    /* P-256 → 32-byte r, 32-byte s (shared helper — see this function's
       docblock for why this call, unlike the key-validation block above,
       is REUSED rather than duplicated). */
    $rawSig = _appleSiwaDerEcdsaToRaw($derSig, 32);
    if ($rawSig === null) {
        return null;
    }

    return $signingInput . '.' . _appleSiwaB64UrlEncode($rawSig);
}

/** Which APNs gateway host a given environment sends to. */
function apnsEndpointHost(string $apnsEnv): string
{
    return $apnsEnv === 'sandbox' ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
}

/**
 * Send ONE push notification via Apple's HTTP/2 token-based APNs API.
 * DORMANT by construction: returns `not_configured` immediately (no
 * network call, no DB write) unless `apnsCredentials()` resolves a usable
 * key — which, absent an admin-UI card to provision one (see file header),
 * is ALWAYS the case on every docroot today. Nothing in this codebase calls
 * this function yet; it exists as plumbing for a future feature to call.
 *
 * ELI5: "try to buzz this one device/Live Activity, and tell me plainly
 * what happened" — configured-but-Apple-said-no, not-configured-at-all,
 * this-phone's-HTTP/2-can't-even-try, or genuinely sent.
 *
 * HTTP/2 REQUIREMENT: Apple's APNs REQUIRES HTTP/2 — this explicitly probes
 * `curl_version()`'s feature bitmask and refuses to attempt the request at
 * all (`http2_unsupported`) rather than silently falling back to HTTP/1.1,
 * which Apple's gateway does not accept. THIS HAS NOT BEEN VERIFIED against
 * a live Apple endpoint from this project's actual shared-hosting curl
 * build — flagged as an open risk, not assumed to work.
 *
 * 410 / Unregistered / BadDeviceToken PRUNING: per Apple's own operational
 * guidance, a token APNs reports as permanently dead is deleted from
 * `tblApnsTokens` so a future caller doesn't keep re-sending to it.
 *
 * @param string      $pushTokenHex Lowercase hex-encoded push token (the
 *                                  SAME shape `apns_register` stores).
 * @param string      $apnsEnv      'sandbox' | 'production'.
 * @param string      $kind         'device' | 'liveActivity' — selects the
 *                                  `apns-topic` suffix + `apns-push-type`.
 * @param array       $payload      The APNs JSON payload (an `aps` dict at
 *                                  minimum) — caller's responsibility to
 *                                  shape correctly; this function does not
 *                                  validate its contents.
 * @param string|null $collapseId   Optional `apns-collapse-id` header value.
 * @return array{ok:bool,status:string,httpStatus?:int,reason?:?string}
 */
function apnsSend(\mysqli $db, string $pushTokenHex, string $apnsEnv, string $kind, array $payload, ?string $collapseId = null): array
{
    if (!in_array($apnsEnv, APNS_ENVIRONMENTS, true)) {
        $apnsEnv = 'production';
    }
    if (!in_array($kind, APNS_KINDS, true)) {
        $kind = 'device';
    }
    $pushTokenHex = strtolower($pushTokenHex);
    if (!preg_match('/^[a-f0-9]{32,200}$/', $pushTokenHex) || strlen($pushTokenHex) % 2 !== 0) {
        return ['ok' => false, 'status' => 'invalid_token'];
    }

    $creds = apnsCredentials();
    if ($creds['teamId'] === '' || $creds['keyId'] === '' || $creds['p8Pem'] === '') {
        return ['ok' => false, 'status' => 'not_configured'];
    }
    $jwt = _apnsBuildJwt($creds['teamId'], $creds['keyId'], $creds['p8Pem'], time());
    if ($jwt === null) {
        return ['ok' => false, 'status' => 'not_configured', 'reason' => 'key_unparseable'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 'transport_unavailable', 'reason' => 'curl_missing'];
    }
    $curlFeatures = curl_version();
    $http2Supported = is_array($curlFeatures)
        && defined('CURL_VERSION_HTTP2')
        && (((int)($curlFeatures['features'] ?? 0)) & CURL_VERSION_HTTP2) !== 0;
    if (!$http2Supported) {
        /* See this function's docblock — never silently attempt HTTP/1.1
           against an HTTP/2-only endpoint. */
        return ['ok' => false, 'status' => 'http2_unsupported'];
    }

    $topic = IHYMNS_SIWA_CLIENT_ID . ($kind === 'liveActivity' ? APNS_LIVE_ACTIVITY_TOPIC_SUFFIX : '');
    $url = 'https://' . apnsEndpointHost($apnsEnv) . '/3/device/' . $pushTokenHex;

    $headers = [
        'authorization: bearer ' . $jwt,
        'apns-topic: ' . $topic,
        'apns-push-type: ' . ($kind === 'liveActivity' ? 'liveactivity' : 'alert'),
        'apns-priority: 10',
        'content-type: application/json',
    ];
    if ($collapseId !== null && $collapseId !== '') {
        $headers[] = 'apns-collapse-id: ' . substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $collapseId) ?? '', 0, 64);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_2_0,
        CURLOPT_RETURNTRANSFER  => true,
        /* TLS verification is ALWAYS on for this outbound call — same
           non-negotiable invariant apple_siwa.php documents for its own
           outbound Apple calls. */
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_MAXREDIRS       => 0,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 8,
        CURLOPT_TIMEOUT         => 15,
    ]);
    $respBody   = curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $respBody === false) {
        return ['ok' => false, 'status' => 'transport_error', 'reason' => 'curl_errno_' . $errno];
    }

    if ($httpStatus === 200) {
        return ['ok' => true, 'status' => 'sent'];
    }

    $reason = null;
    $decoded = json_decode((string)$respBody, true);
    if (is_array($decoded) && isset($decoded['reason']) && is_string($decoded['reason'])) {
        $reason = $decoded['reason'];
    }

    if ($httpStatus === 410 || $reason === 'Unregistered' || $reason === 'BadDeviceToken') {
        _apnsPruneToken($db, $pushTokenHex);
        return ['ok' => false, 'status' => 'pruned', 'httpStatus' => $httpStatus, 'reason' => $reason];
    }

    return ['ok' => false, 'status' => 'apns_error', 'httpStatus' => $httpStatus, 'reason' => $reason];
}

/**
 * Best-effort delete of a push token APNs has told us will never work
 * again (410 / `Unregistered` / `BadDeviceToken`). Never throws — a prune
 * failure must not turn an already-reported send failure into an uncaught
 * exception.
 */
function _apnsPruneToken(\mysqli $db, string $pushTokenHex): void
{
    if (!apnsTokensTableExists($db)) {
        return;
    }
    $bin = @hex2bin($pushTokenHex);
    if ($bin === false) {
        return;
    }
    try {
        $del = $db->prepare('DELETE FROM tblApnsTokens WHERE PushToken = ?');
        $del->bind_param('s', $bin);
        $del->execute();
        $del->close();
    } catch (\Throwable $e) {
        error_log('[apns/pruneToken] ' . $e->getMessage());
    }
}
