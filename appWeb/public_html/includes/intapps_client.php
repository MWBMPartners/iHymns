<?php

declare(strict_types=1);

/**
 * iHymns — MWBM-IntAppsAPI gateway client (Epic #1725, this module #1729/#1730)
 *
 * ELI5: this file talks to a separate MWBM website ("the gateway") that can
 * flip small on/off switches for iHymns without a code deploy. It only ever
 * asks the gateway a question and remembers the last good answer — if the
 * gateway is slow, lying, or simply doesn't exist yet, iHymns behaves exactly
 * as if this file were never written.
 *
 * DETAIL:
 * -------
 * Server-proxied (never browser-facing — rule #31/§8 of the design doc: a
 * browser never gets the API key/HMAC secret), cache-first, fail-open feature
 * flag client. One shared module, loadable side-effect-free: requiring this
 * file opens no DB connection and makes no HTTP call — it only declares
 * functions and constants (mirrors the discipline already established by
 * `includes/apple_siwa.php` and `includes/song_relocate.php`).
 *
 * DORMANT BY DEFAULT (rule: gateway flags are operational kill switches,
 * never a second source of truth for anything `TIER_CAPS`/`checkTierAccess`/
 * `contentGatingApply`/`gatingRulesApply`/`checkContentAccess` already
 * answers — see `tests/php/test-intapps-flag-boundary.php`, #1731). Every
 * function here degrades to the caller's compiled-in `$default` the instant
 * ANY of the following is true: `intappsapi_enabled_channels` doesn't list
 * the current channel, the credential set is incomplete, `tblIntAppsSync`
 * does not exist yet (D1 — see below), the gateway is unreachable/slow/
 * lying, or the cache is simply cold. Nothing here ever throws into a page
 * render.
 *
 * ⚠️ TIMING (delta §0, wave4-prelaunch-plan.md): Issue A / #1726 (owner-only
 * gateway liveness + credential registration) gates ENABLEMENT only, not
 * landing this module. This file, its tables and its admin surfaces are safe
 * to ship and merge before #1726 closes — they are provably inert until an
 * admin (a) pastes real credentials on /manage/configuration AND (b) lists a
 * channel in the allow-list, which cannot happen before #1726 hands over
 * real credentials to paste.
 *
 * ⚠️ D1 — THE PRE-LAUNCH BLOCKER (wave4-prelaunch-plan.md §5 "stress test",
 * defect D1). Migrations on this codebase are WEB-RUN, not auto-applied on
 * deploy, and the enablement flip (`intappsapi_enabled_channels`) is a
 * `tblAppSettings` row on a MySQL database SHARED by all three docroots. So
 * "a channel is enabled but that install never ran the migration card" is a
 * normal, expected state, not a corner case — and if the snapshot read below
 * were to hit `tblIntAppsSync` directly, mysqli's STRICT reporting mode
 * (`includes/db_mysql.php`) would make a missing-table SELECT THROW
 * `mysqli_sql_exception` (error 1146) straight through `app_status` and the
 * home-page consumer — a public 500 on the highest-traffic page in the app.
 * EVERY `tblIntAppsSync` access below is therefore wrapped in try/catch
 * around `\mysqli_sql_exception` (belt) on top of the `_intappsTableExists()`
 * INFORMATION_SCHEMA pre-check (braces) — see `_intappsTableExists()`,
 * `intappsCachedScope()`, `intappsRefreshIfDue()`, `intappsForceRefresh()`
 * and `_intappsCommitFetchResult()`. `tests/php/test-intapps-client.php`
 * pins this with `ClaimProbeFailingMysqli` (a double whose every `prepare()`
 * throws 1146, `tests/php/lib/mysqli_doubles.php`) standing in for "table
 * absent while the module is enabled", mutation-tested by temporarily
 * removing the catch and watching it go red.
 *
 * THE SIGNER (non-negotiable, verified against the gateway's own source,
 * `web/src/Helpers/HmacValidator.php:24-55` in the `mwbm-intappsapi` repo,
 * commit range verified this session): the canonical string is
 *
 *     rawBody . '.' . unixTimestampAsDecimalDigits
 *
 * hex-HMAC-SHA256'd with the shared secret. The gateway's own FIVE bundled
 * client examples sign `METHOD|PATH|TIMESTAMP|BODY` instead — ALL FIVE ARE
 * WRONG (filed upstream as MWBM-intAppsAPI#120; `intappsSign()` below is
 * implemented against the source, never the examples). An empty POST body
 * signs as `'.' . $timestamp` — `tests/php/test-intapps-hmac.php` asserts
 * this case explicitly, plus that the examples' wrong string is REJECTED by
 * a verifier ported line-for-line from the same source
 * (`tests/php/fixtures/intapps-stub-gateway.php`, a later commit in this
 * batch).
 *
 * PHASE 1 IS GET-ONLY. `intappsRequest()` supports POST/PATCH/DELETE for
 * forward-compatibility (the signer needs exercising for a later write-scope
 * follow-up), but nothing in this batch ever calls it with a non-GET method.
 * No write-scoped consumer may trust `intappsSign()` against the REAL
 * gateway until one live signed POST has been verified there (see the
 * "CANNOT be verified here" list in this batch's final commit).
 *
 * @see .claude/intappsapi-integration-plan.md §4 (full design: DDL,
 *      function signatures, cache/fail-open contract)
 * @see .claude/wave4-prelaunch-plan.md §4/§5/§6 Block D (pre-launch delta,
 *      the stress test that found D1, the commit plan)
 * @link https://www.php.net/manual/en/book.curl.php
 * @link https://www.php.net/manual/en/function.hash-hmac.php
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'environment.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';   /* getAppSetting()/setAppSetting() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto.php'; /* secretSettingKeys() registration + transparent decrypt inside getAppSetting() */

/* -------------------------------------------------------------------------
 * SETTINGS KEYS — defined ONCE here so /manage/configuration.php and
 * /manage/intapps-status.php never re-type a literal and drift apart
 * (rule #35 — cross-file agreement needs a mechanism, not a comment).
 * ---------------------------------------------------------------------- */
const INTAPPS_SETTING_ENABLED_CHANNELS = 'intappsapi_enabled_channels';
const INTAPPS_SETTING_BASE_URL         = 'intappsapi_base_url';
const INTAPPS_SETTING_APP_UUID         = 'intappsapi_app_uuid';
const INTAPPS_SETTING_APP_SLUG         = 'intappsapi_app_slug';
const INTAPPS_SETTING_API_KEY          = 'intappsapi_api_key';
const INTAPPS_SETTING_HMAC_SECRET      = 'intappsapi_hmac_secret';
/* D1-labelled loopback carve-out (wave4-prelaunch-plan.md §3e — that
 * document's OWN internal "D1" label, distinct from this file's D1 above;
 * the naming collision is between two source docs, called out here so a
 * reader never conflates "the missing-table blocker" with "the loopback
 * knob"). '1' only on a local/test install so the stub gateway fixture
 * (`tests/php/fixtures/intapps-stub-gateway.php`, `http://127.0.0.1:<port>`)
 * is reachable at all — the gateway itself is only ever `https://`. */
const INTAPPS_SETTING_ALLOW_LOOPBACK   = 'intappsapi_allow_loopback';

const INTAPPS_DEFAULT_BASE_URL   = 'https://api.mwbmpartners.ltd';
const INTAPPS_DEFAULT_APP_SLUG   = 'ihymns';

/* Cache freshness + backoff constants (design §2) — code constants, not
 * settings rows: fewer knobs, no way to misconfigure the fail-open bound. */
const INTAPPS_TTL_SECONDS          = 300;   /* matches the gateway's own Redis TTL */
const INTAPPS_LOCK_SECONDS         = 10;    /* single-flight lock hold time */
const INTAPPS_BACKOFF_BASE_SECONDS = 300;   /* LEAST(300 * 2^failures, 3600) */
const INTAPPS_BACKOFF_CAP_SECONDS  = 3600;
/* Remedy 3 (stress test) — a misbehaving/compromised gateway must not be
 * able to drive shared-hosting memory exhaustion (the #929 OOM class) or
 * ship an arbitrarily large `metadata` blob out to every anonymous
 * app_status caller. 256 KiB is generously larger than any plausible
 * feature-flag list. */
const INTAPPS_MAX_RESPONSE_BYTES = 262144;
const INTAPPS_CURL_CONNECT_TIMEOUT = 2; /* matches the house band, ip_geolocation.php:229 */
const INTAPPS_CURL_TIMEOUT         = 3;

/**
 * ELI5: is the gateway integration switched on for THIS server right now?
 * WHY: the master dormancy gate. True only when an admin has (a) listed the
 * current channel in the allow-list AND (b) saved a complete credential
 * set. Memoized per request (mirrors `ihymns_environment()`'s own static
 * cache) — cheap to call from a hot path like `app_status`.
 */
function intappsEnabled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $allowList = (string)(getAppSetting(INTAPPS_SETTING_ENABLED_CHANNELS, '') ?? '');
    if (!_intappsChannelAllowedCore($allowList, _intappsChannel())) {
        return $cached = false;
    }
    return $cached = (intappsConfig() !== null);
}

/**
 * ELI5: which of the three iHymns copies (alpha/beta/production) is this?
 * WHY: thin wrapper so the rest of this file has ONE call site to change if
 * the channel source ever moves — mirrors `apple_siwa.php`'s own
 * `appleWebLoginEnabledForChannel()` convention of calling
 * `ihymns_environment()` directly rather than through the heavier
 * `service_mode.php` (whose `serviceMode_channel()` is just a wrapper
 * around the same call).
 */
function _intappsChannel(): string
{
    return function_exists('ihymns_environment') ? ihymns_environment() : 'production';
}

/**
 * ELI5: does this comma-separated list contain the current channel, or the
 * word "all"?
 * WHY: PURE core of the allow-list check (no DB, no superglobal), so the
 * full truth table (empty list / one token / "all" / mixed case / stray
 * whitespace) is assertable without any environment setup — the identical
 * shape to `apple_siwa.php::_appleWebLoginEnabledCore()`, the established
 * per-channel-canary precedent this integration deliberately reuses
 * (stress-test remedy 2) rather than inventing a second allow-list scheme.
 *
 * @param string $allowList Raw `intappsapi_enabled_channels` setting value.
 * @param string $channel   The CURRENT channel (`ihymns_environment()`'s
 *                           return value).
 */
function _intappsChannelAllowedCore(string $allowList, string $channel): bool
{
    $tokens = array_filter(array_map(
        static fn(string $t): string => strtolower(trim($t)),
        explode(',', $allowList)
    ), static fn(string $t): bool => $t !== '');
    if (!$tokens) {
        return false;
    }
    $channel = strtolower(trim($channel));
    foreach ($tokens as $token) {
        if ($token === 'all' || $token === $channel) {
            return true;
        }
    }
    return false;
}

/**
 * ELI5: gather the gateway credentials, or say "not fully set up yet".
 * WHY: single resolution point so every caller sees the SAME complete-or-
 * null answer. Secrets arrive already decrypted — `getAppSetting()`
 * transparently decrypts any `enc:v1:` envelope once
 * `intappsapi_api_key`/`intappsapi_hmac_secret` are registered in
 * `secretSettingKeys()` (`includes/secret_crypto.php`), so this function
 * never touches the encryption layer directly. Memoized per request.
 *
 * @return array{base_url:string,app_uuid:string,app_slug:string,api_key:string,hmac_secret:string,user_agent:string}|null
 */
function intappsConfig(): ?array
{
    static $cached = false; /* false = not yet resolved this request; null = resolved-and-incomplete */
    if ($cached !== false) {
        return $cached;
    }

    $baseUrl    = trim((string)(getAppSetting(INTAPPS_SETTING_BASE_URL, INTAPPS_DEFAULT_BASE_URL) ?? ''));
    $appUuid    = trim((string)(getAppSetting(INTAPPS_SETTING_APP_UUID, '') ?? ''));
    $appSlug    = trim((string)(getAppSetting(INTAPPS_SETTING_APP_SLUG, INTAPPS_DEFAULT_APP_SLUG) ?? ''));
    $apiKey     = trim((string)(getAppSetting(INTAPPS_SETTING_API_KEY, '') ?? ''));
    $hmacSecret = trim((string)(getAppSetting(INTAPPS_SETTING_HMAC_SECRET, '') ?? ''));

    if ($baseUrl === '' || $appUuid === '' || $appSlug === '' || $apiKey === '' || $hmacSecret === '') {
        return $cached = null;
    }

    return $cached = [
        'base_url'    => $baseUrl,
        'app_uuid'    => $appUuid,
        'app_slug'    => $appSlug,
        'api_key'     => $apiKey,
        'hmac_secret' => $hmacSecret,
        /* MUST start with exactly "iHymns/" — the gateway does a plain
           str_starts_with() against the app's registered user_agent_prefix
           (AuthMiddleware.php:52-59), which #1726's registration step sets
           to "iHymns/". */
        'user_agent'  => 'iHymns/1.0 (' . _intappsChannel() . ')',
    ];
}

/**
 * ELI5: turn a message + a timestamp into a secret-signed fingerprint.
 * WHY: PURE signer — the ONLY place the canonical string is built, so a
 * fixed-vector unit test (`tests/php/test-intapps-hmac.php`) can pin it
 * without any DB/network. Implemented against the gateway's OWN source
 * (`HmacValidator.php:50-51`: `$payload = $requestBody . '.' . $timestamp;
 * hash_hmac('sha256', $payload, $secret)`), never its five bundled
 * examples, all of which sign `METHOD|PATH|TIMESTAMP|BODY` instead
 * (MWBM-intAppsAPI#120). For an empty body the payload is simply
 * `'.' . $unixTimestamp` — asserted explicitly in the fixed-vector test.
 *
 * @param string $rawBody        Exact bytes of the request body (''
 *                                 for a GET / empty POST).
 * @param int    $unixTimestamp  Whole seconds since the epoch.
 * @param string $hmacSecret     The shared secret for this app.
 * @return string Lowercase hex HMAC-SHA256 digest.
 */
function intappsSign(string $rawBody, int $unixTimestamp, string $hmacSecret): string
{
    $payload = $rawBody . '.' . $unixTimestamp;
    return hash_hmac('sha256', $payload, $hmacSecret);
}

/**
 * ELI5: is this gateway URL safe to actually connect to?
 * WHY: SSRF hardening (stress-test remedy 10) as a PURE function so the
 * truth table is unit-testable: `https://` is always allowed; `http://` is
 * allowed ONLY when the loopback knob is on AND the host is literally
 * `127.0.0.1` / `::1` / `localhost` (the local stub-gateway fixture is the
 * one legitimate reason this carve-out exists — it can never reach a real
 * host on the public internet). Anything else — a non-loopback `http://`,
 * an unparseable URL, a scheme that isn't http/https — is refused. The
 * returned URL is rebuilt from ONLY the parsed scheme/host/port + the
 * caller's fixed `$path` (never the raw `$baseUrl` string re-concatenated
 * with anything attacker- or admin-typo-influenced), which is what makes
 * the auth headers "host-bound" in `intappsRequest()`: the URL actually
 * dialled can only ever be the configured host, on the configured scheme.
 *
 * @return array{0:string,1:string}|null [$fullUrl, $host] or null if refused.
 */
function _intappsResolveUrl(string $baseUrl, string $path, bool $allowLoopback): ?array
{
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host   = strtolower((string)$parts['host']);
    $isLoopbackHost = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

    if ($scheme === 'https') {
        /* always allowed */
    } elseif ($scheme === 'http' && $allowLoopback && $isLoopbackHost) {
        /* the local/test-only carve-out */
    } else {
        return null;
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return [$scheme . '://' . $host . $port . $path, $host];
}

/**
 * ELI5: does this decoded gateway answer look like what we expect for this
 * scope?
 * WHY: stress-test remedy 4 — a WELL-FORMED envelope (`success:true`) whose
 * `data` shape is still wrong (`{"data":null}`, or `{"data":{}}` with no
 * `features` array) must NOT overwrite the last-known-good cache. Without
 * this check the fail-open contract has a hole: the admin status page would
 * show a fresh green fetch while every consumer silently fell back to
 * defaults — a lying dashboard. For the `features` scope specifically, each
 * element must carry at least `feature_key` + `enabled` (the two keys
 * `intappsFlag()`/`intappsFlagMeta()` read). Reserved scopes
 * (`updates`/`notifications`/`status`) have no consumer yet, so any
 * well-formed array is accepted until a real shape is defined for them —
 * narrowing that later is additive, never a second migration.
 */
function _intappsShapeValid(string $scope, mixed $data): bool
{
    if (!is_array($data)) {
        return false;
    }
    if ($scope === 'features') {
        if (!is_array($data['features'] ?? null)) {
            return false;
        }
        foreach ($data['features'] as $f) {
            if (!is_array($f) || !array_key_exists('feature_key', $f) || !array_key_exists('enabled', $f)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * ELI5: does the `tblIntAppsSync` table actually exist on THIS database
 * right now?
 * WHY: the D1 pre-check (see file header). Every caller that is about to
 * touch the table asks this FIRST, so an un-migrated docroot degrades
 * before ever issuing a statement mysqli-STRICT would throw on. Memoized
 * per request; fails closed (false) on ANY error, including a connection
 * problem the INFORMATION_SCHEMA probe itself hits.
 */
function _intappsTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblIntAppsSync' LIMIT 1"
        );
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $cached = $exists;
    } catch (\Throwable $e) {
        return $cached = false;
    }
}

/**
 * ELI5: read one (Scope, Channel, AppSlug) row, falling back to the shared
 * "" channel row.
 * WHY: `intappsCachedScope()`'s two-step read (design §2: "read the
 * exact-channel row first, fall back to the '' row"), extracted so both
 * steps share the identical bound-param SELECT.
 */
function _intappsReadRow(\mysqli $db, string $scope, string $channel, string $appSlug): ?array
{
    $stmt = $db->prepare(
        'SELECT PayloadJson, FetchedAt, AttemptedAt, RefreshLockedUntil,
                LastHttpStatus, LastErrorCode, ConsecutiveFailures
           FROM tblIntAppsSync
          WHERE Scope = ? AND Channel = ? AND AppSlug = ?
          LIMIT 1'
    );
    $stmt->bind_param('sss', $scope, $channel, $appSlug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * ELI5: read whatever we last successfully learned for this scope, without
 * ever calling the gateway.
 * WHY: the cache-only read every consumer function (`intappsFlag()`,
 * `intappsFlagMeta()`, the admin status page) goes through. NEVER performs
 * HTTP. Degrades to `{payload:null, fetchedAt:null, stale:true}` on: module
 * disabled, table absent (D1), any mysqli error, or a genuinely cold row.
 *
 * @return array{payload:?array,fetchedAt:?string,stale:bool}
 */
function intappsCachedScope(\mysqli $db, string $scope): array
{
    $cold = ['payload' => null, 'fetchedAt' => null, 'stale' => true];
    if (!intappsEnabled()) {
        return $cold;
    }
    try {
        if (!_intappsTableExists($db)) {
            return $cold; /* D1 degrade */
        }
        $channel = _intappsChannel();
        $appSlug = (string)(intappsConfig()['app_slug'] ?? INTAPPS_DEFAULT_APP_SLUG);

        $row = _intappsReadRow($db, $scope, $channel, $appSlug);
        if ($row === null && $channel !== '') {
            $row = _intappsReadRow($db, $scope, '', $appSlug); /* shared-row fallback */
        }
        if ($row === null || $row['PayloadJson'] === null) {
            return $cold;
        }
        $payload = json_decode((string)$row['PayloadJson'], true);
        $fetchedAt = $row['FetchedAt'] !== null ? (string)$row['FetchedAt'] : null;
        $stale = ($fetchedAt === null)
            || (strtotime($fetchedAt . ' UTC') < (time() - INTAPPS_TTL_SECONDS));

        return [
            'payload'   => is_array($payload) ? $payload : null,
            'fetchedAt' => $fetchedAt,
            'stale'     => $stale,
        ];
    } catch (\mysqli_sql_exception $e) {
        return $cold; /* D1 degrade — table vanished mid-request, or another schema error */
    }
}

/**
 * ELI5: the raw bookkeeping row for one scope, for the admin status page
 * only (fetchedAt / attempt / lock / last status+code / failure streak).
 * WHY: separated from `intappsCachedScope()` because consumers never need
 * the bookkeeping columns, only the admin diagnostic page does — keeping
 * the consumer-facing return shape narrow. Same D1/degrade discipline.
 *
 * @return array{payload:?array,fetchedAt:?string,attemptedAt:?string,refreshLockedUntil:?string,lastHttpStatus:?int,lastErrorCode:?string,consecutiveFailures:int}|null
 */
function intappsSyncRow(\mysqli $db, string $scope = 'features'): ?array
{
    if (!intappsEnabled()) {
        return null;
    }
    try {
        if (!_intappsTableExists($db)) {
            return null; /* D1 degrade */
        }
        $channel = _intappsChannel();
        $appSlug = (string)(intappsConfig()['app_slug'] ?? INTAPPS_DEFAULT_APP_SLUG);
        $row = _intappsReadRow($db, $scope, $channel, $appSlug) ?? _intappsReadRow($db, $scope, '', $appSlug);
        if ($row === null) {
            return null;
        }
        $payload = $row['PayloadJson'] !== null ? json_decode((string)$row['PayloadJson'], true) : null;
        return [
            'payload'             => is_array($payload) ? $payload : null,
            'fetchedAt'           => $row['FetchedAt'],
            'attemptedAt'         => $row['AttemptedAt'],
            'refreshLockedUntil'  => $row['RefreshLockedUntil'],
            'lastHttpStatus'      => $row['LastHttpStatus'] !== null ? (int)$row['LastHttpStatus'] : null,
            'lastErrorCode'       => $row['LastErrorCode'],
            'consecutiveFailures' => (int)$row['ConsecutiveFailures'],
        ];
    } catch (\mysqli_sql_exception $e) {
        return null; /* D1 degrade */
    }
}

/**
 * ELI5: is the "test on a local machine only" http:// exception switched
 * on?
 * WHY: reads the knob described at `INTAPPS_SETTING_ALLOW_LOOPBACK`'s
 * declaration above.
 */
function _intappsAllowLoopback(): bool
{
    return (string)(getAppSetting(INTAPPS_SETTING_ALLOW_LOOPBACK, '0') ?? '0') === '1';
}

/**
 * ELI5: actually ring the gateway once and report back, in plain language,
 * what happened — never by throwing.
 * WHY: the ONE HTTP round trip. Every failure mode (disabled, no curl
 * extension, URL refused, transport error, oversized body, malformed
 * envelope, non-2xx/`success:false`) is a normal RETURN VALUE, never an
 * exception — callers (the single-flight winner in `intappsRefreshIfDue()`,
 * or the admin "Test connection" button) branch on `ok`/`transport`, never
 * on a try/catch. `CURLOPT_FOLLOWLOCATION` is hard OFF and the URL is
 * rebuilt host-bound by `_intappsResolveUrl()` (remedy 10) — a compromised
 * or misconfigured gateway can redirect nothing and can never make this
 * function contact a third host. The response body is read through a
 * write-callback that ABORTS the transfer once it exceeds
 * `INTAPPS_MAX_RESPONSE_BYTES` (remedy 3) rather than buffering an
 * unbounded body and checking its length afterwards — the cap is real, not
 * advisory.
 *
 * @param string      $method  'GET' | 'POST' | 'PATCH' | 'DELETE'. Only GET
 *                               is exercised anywhere in this batch (phase 1);
 *                               POST/PATCH/DELETE sign per `intappsSign()`.
 * @param string      $path    e.g. '/v1/features/ihymns' — leading slash,
 *                               no base URL (that comes from config).
 * @param string|null $rawBody Exact bytes to send AND sign; null for GET.
 * @return array{transport:string,httpStatus:?int,ok:bool,data:?array,errorCode:?string,errorMessage:?string,durationMs:float}
 */
function intappsRequest(string $method, string $path, ?string $rawBody = null): array
{
    $start = microtime(true);
    $result = [
        'transport'    => 'disabled',
        'httpStatus'   => null,
        'ok'           => false,
        'data'         => null,
        'errorCode'    => null,
        'errorMessage' => null,
        'durationMs'   => 0.0,
    ];

    if (!intappsEnabled()) {
        return $result;
    }
    $config = intappsConfig();
    if ($config === null) {
        return $result; /* enabled-by-channel but credentials incomplete — treat as disabled */
    }
    if (!function_exists('curl_init')) {
        $result['transport']    = 'unreachable';
        $result['errorMessage'] = 'curl extension not available';
        $result['durationMs']   = round((microtime(true) - $start) * 1000, 2);
        return $result;
    }

    $resolved = _intappsResolveUrl($config['base_url'], $path, _intappsAllowLoopback());
    if ($resolved === null) {
        $result['transport']    = 'unreachable';
        $result['errorMessage'] = 'base_url scheme/host not permitted';
        $result['durationMs']   = round((microtime(true) - $start) * 1000, 2);
        return $result;
    }
    [$url] = $resolved;

    $method = strtoupper($method);
    $isMutating = in_array($method, ['POST', 'PATCH', 'DELETE'], true);
    $body = $rawBody ?? '';

    $headers = [
        'X-App-ID: ' . $config['app_uuid'],
        'X-API-Key: ' . $config['api_key'],
        'Accept: application/json',
    ];
    if ($isMutating) {
        /* Signing rule per the gateway's OWN source (see intappsSign()'s
           docblock) — phase 1 never exercises this branch in production
           traffic, but the signer must be correct the day it is. */
        $ts = time();
        $headers[] = 'X-Timestamp: ' . $ts;
        $headers[] = 'X-Signature: ' . intappsSign($body, $ts, $config['hmac_secret']);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init();
    if ($ch === false) {
        $result['transport']    = 'unreachable';
        $result['errorMessage'] = 'curl_init() failed';
        $result['durationMs']   = round((microtime(true) - $start) * 1000, 2);
        return $result;
    }

    $buf = '';
    $overCap = false;
    $cap = INTAPPS_MAX_RESPONSE_BYTES;
    /* Remedy 3 — abort mid-transfer the moment the buffer exceeds the cap,
       so an oversized/hostile body is never fully pulled into memory. */
    $writeFn = static function ($handle, string $chunk) use (&$buf, &$overCap, $cap): int {
        $buf .= $chunk;
        if (strlen($buf) > $cap) {
            $overCap = true;
            return -1; /* signals curl to abort the transfer (CURLE_WRITE_ERROR) */
        }
        return strlen($chunk);
    };

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => $config['user_agent'],
        CURLOPT_CONNECTTIMEOUT => INTAPPS_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => INTAPPS_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, /* remedy 10 — never follow a redirect */
        CURLOPT_PROTOCOLS      => str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_MAXFILESIZE    => $cap,  /* best-effort (Content-Length-aware); the write-callback is the real bound */
        CURLOPT_WRITEFUNCTION  => $writeFn,
    ]);
    if ($isMutating) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_exec($ch);
    $errno      = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result['durationMs'] = round((microtime(true) - $start) * 1000, 2);

    /* Two independent triggers can report an oversized body, and curl's
       OWN CURLOPT_MAXFILESIZE enforcement (errno 63, CURLE_FILESIZE_EXCEEDED)
       fires FIRST in practice — verified empirically: for a response with a
       declared Content-Length over the cap, libcurl aborts the transfer via
       its own accounting the moment the cap is reached, before the
       write-callback's manual `$overCap` flag (set from INSIDE that same
       callback) is ever consulted here. Both paths must map to the SAME
       'OVERSIZED' outcome — treating only one of them as oversized would
       silently reclassify most real oversized responses as generic
       'unreachable' with no errorCode at all. */
    if ($overCap || $errno === CURLE_FILESIZE_EXCEEDED) {
        $result['transport']    = 'answered';
        $result['httpStatus']   = $httpStatus > 0 ? $httpStatus : null;
        $result['errorCode']    = 'OVERSIZED';
        $result['errorMessage'] = 'response exceeded ' . $cap . ' bytes';
        return $result;
    }
    if ($errno !== 0) {
        $result['transport']    = 'unreachable';
        $result['errorMessage'] = 'curl error ' . $errno . ': ' . curl_strerror($errno);
        return $result;
    }

    $result['transport']  = 'answered';
    $result['httpStatus'] = $httpStatus;

    $decoded = json_decode($buf, true);
    if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
        $result['errorCode']    = 'MALFORMED';
        $result['errorMessage'] = 'response body is not a well-formed envelope';
        return $result;
    }

    $success = $decoded['success'] === true;
    if ($httpStatus >= 200 && $httpStatus < 300 && $success) {
        $result['ok']   = true;
        $result['data'] = is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
        return $result;
    }

    $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    $result['errorCode']    = isset($err['code']) ? (string)$err['code'] : null;
    $result['errorMessage'] = isset($err['message']) ? (string)$err['message'] : null;
    return $result;
}

/**
 * ELI5: after a fetch attempt, write down what happened — but NEVER erase
 * the last good answer just because this attempt failed. Also let go of
 * the single-flight lock immediately, rather than making the next caller
 * wait out its full hold time.
 * WHY: the fail-open invariant lives here in one place. A shape-invalid or
 * unsuccessful `$result` bumps `ConsecutiveFailures` and records the
 * status/error code; it NEVER touches `PayloadJson`/`FetchedAt`. Only a
 * genuinely successful, shape-valid result replaces the snapshot and resets
 * the failure streak. D1-guarded like every other table access here.
 * `RefreshLockedUntil = NULL` on BOTH branches releases the lock the moment
 * the attempt (success or failure) finishes: `INTAPPS_LOCK_SECONDS` bounds
 * the WORST case (a fetch that never returns — curl's own timeout caps
 * that at `INTAPPS_CURL_TIMEOUT`, well under the lock's 10s), not the
 * NORMAL case. Leaving the lock held for the full 10s after a fetch that
 * completed in milliseconds would make `intappsForceRefresh()` ("Refresh
 * now") report "another refresh in flight" for up to 10 seconds after an
 * operator's own PREVIOUS click already finished — a self-inflicted false
 * busy signal, not genuine contention.
 */
function _intappsCommitFetchResult(\mysqli $db, string $scope, string $channel, string $appSlug, array $result): void
{
    try {
        $status = $result['httpStatus'];
        if ($result['ok'] && _intappsShapeValid($scope, $result['data'])) {
            $json = json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $db->prepare(
                'UPDATE tblIntAppsSync
                    SET PayloadJson = ?, FetchedAt = UTC_TIMESTAMP(),
                        LastHttpStatus = ?, LastErrorCode = NULL, ConsecutiveFailures = 0,
                        RefreshLockedUntil = NULL
                  WHERE Scope = ? AND Channel = ? AND AppSlug = ?'
            );
            $stmt->bind_param('sisss', $json, $status, $scope, $channel, $appSlug);
            $stmt->execute();
            $stmt->close();
            return;
        }

        /* Failure OR shape-invalid ("looks alive, isn't" — remedy 4): bump
           the streak, record status/code, leave PayloadJson/FetchedAt alone. */
        $errorCode = $result['ok'] ? 'BAD_SHAPE' : $result['errorCode'];
        $stmt = $db->prepare(
            'UPDATE tblIntAppsSync
                SET LastHttpStatus = ?, LastErrorCode = ?, ConsecutiveFailures = ConsecutiveFailures + 1,
                    RefreshLockedUntil = NULL
              WHERE Scope = ? AND Channel = ? AND AppSlug = ?'
        );
        $stmt->bind_param('issss', $status, $errorCode, $scope, $channel, $appSlug);
        $stmt->execute();
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        /* D1 degrade — the table vanished between the lock and the commit,
           or some other schema error. There is nothing to record. */
    }
}

/**
 * ELI5: "is it time to ask the gateway again, and if so, am I the one who
 * gets to?" — then do it, quietly, and never make the caller wait.
 * WHY: single-flight refresh (design §2). THE BLOCKER FIX (stress-test
 * defect 1): the seed `INSERT ... ON DUPLICATE KEY UPDATE Id = Id` runs
 * BEFORE the conditional lock UPDATE. On a cold table (freshly migrated,
 * zero rows) a bare conditional UPDATE matches NOTHING — `affected_rows`
 * is 0 for every caller, forever, and the cache never populates. Seeding
 * the row first (race-safe via the UNIQUE key — a second concurrent INSERT
 * just no-ops) guarantees a row exists for the UPDATE to ever win.
 * `affected_rows === 1` on the UPDATE is the single-flight winner; every
 * other concurrent/subsequent caller returns instantly having done nothing.
 * Safe to call from ANY request path — bounded by the curl timeout
 * (`INTAPPS_CURL_TIMEOUT`) and, per caller convention, invoked from
 * `api.php`'s `app_status` handler AFTER the response has already been
 * sent (never on the critical path — see that file's own comment).
 */
function intappsRefreshIfDue(\mysqli $db, string $scope = 'features'): void
{
    if (!intappsEnabled()) {
        return;
    }
    $config = intappsConfig();
    if ($config === null) {
        return;
    }
    $channel = _intappsChannel();
    $appSlug = (string)$config['app_slug'];

    try {
        if (!_intappsTableExists($db)) {
            return; /* D1 degrade — nothing to seed or lock */
        }

        /* 1. SEED — the lock row must exist before a conditional UPDATE can
           ever win (the fix for the BLOCKER above). */
        $seed = $db->prepare(
            'INSERT INTO tblIntAppsSync (Scope, Channel, AppSlug)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE Id = Id'
        );
        $seed->bind_param('sss', $scope, $channel, $appSlug);
        $seed->execute();
        $seed->close();

        /* 2. LOCK — the atomic single-flight conditional UPDATE. Eligible
           only when: not currently locked, stale beyond the TTL, AND past
           the exponential backoff window since the last attempt. */
        $lock = $db->prepare(
            'UPDATE tblIntAppsSync
                SET RefreshLockedUntil = UTC_TIMESTAMP() + INTERVAL ' . INTAPPS_LOCK_SECONDS . ' SECOND,
                    AttemptedAt        = UTC_TIMESTAMP()
              WHERE Scope = ? AND Channel = ? AND AppSlug = ?
                AND (RefreshLockedUntil IS NULL OR RefreshLockedUntil < UTC_TIMESTAMP())
                AND (FetchedAt IS NULL OR FetchedAt < UTC_TIMESTAMP() - INTERVAL ' . INTAPPS_TTL_SECONDS . ' SECOND)
                AND (AttemptedAt IS NULL OR AttemptedAt < UTC_TIMESTAMP()
                     - INTERVAL LEAST(' . INTAPPS_BACKOFF_BASE_SECONDS . ' * POW(2, ConsecutiveFailures), '
                     . INTAPPS_BACKOFF_CAP_SECONDS . ') SECOND)'
        );
        $lock->bind_param('sss', $scope, $channel, $appSlug);
        $lock->execute();
        $won = $lock->affected_rows === 1;
        $lock->close();
        if (!$won) {
            return;
        }
    } catch (\mysqli_sql_exception $e) {
        return; /* D1 degrade */
    }

    /* 3. FETCH (winner only) + commit. Never throws — intappsRequest()
       itself never throws, and the commit is its own try/catch above. */
    $result = intappsRequest('GET', '/v1/features/' . rawurlencode($appSlug));
    _intappsCommitFetchResult($db, $scope, $channel, $appSlug, $result);
}

/**
 * ELI5: the "Refresh now" button on the admin status page — try right now,
 * ignoring the normal wait-300-seconds / back-off-after-failures rules, but
 * still refuse to run TWO refreshes at once.
 * WHY: stress-test remedy 7. Reusing `intappsRefreshIfDue()`'s backoff-aware
 * UPDATE would make the button a SILENT no-op during a failure streak — an
 * operator debugging a 403 clicks Refresh, zero rows match the backoff
 * clause, nothing happens, no error, on the one page meant to explain what's
 * wrong. This function's lock UPDATE honours ONLY `RefreshLockedUntil`
 * (never `FetchedAt`/`AttemptedAt`/backoff), and ALWAYS returns either the
 * typed `intappsRequest()` result or an explicit "already refreshing"
 * signal — never a silent nothing.
 *
 * @return array{transport:string,httpStatus:?int,ok:bool,data:?array,errorCode:?string,errorMessage:?string,durationMs:float}
 *         `transport` gains a fourth value here, `'locked'`, meaning
 *         another refresh is currently in flight for this scope.
 */
function intappsForceRefresh(\mysqli $db, string $scope = 'features'): array
{
    $blocked = [
        'transport' => 'disabled', 'httpStatus' => null, 'ok' => false,
        'data' => null, 'errorCode' => null, 'errorMessage' => null, 'durationMs' => 0.0,
    ];
    if (!intappsEnabled()) {
        return $blocked;
    }
    $config = intappsConfig();
    if ($config === null) {
        return $blocked;
    }
    $channel = _intappsChannel();
    $appSlug = (string)$config['app_slug'];

    try {
        if (!_intappsTableExists($db)) {
            $blocked['transport']    = 'unreachable';
            $blocked['errorMessage'] = 'tblIntAppsSync does not exist -- run the migration card on Database Setup';
            return $blocked;
        }

        $seed = $db->prepare(
            'INSERT INTO tblIntAppsSync (Scope, Channel, AppSlug)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE Id = Id'
        );
        $seed->bind_param('sss', $scope, $channel, $appSlug);
        $seed->execute();
        $seed->close();

        $lock = $db->prepare(
            'UPDATE tblIntAppsSync
                SET RefreshLockedUntil = UTC_TIMESTAMP() + INTERVAL ' . INTAPPS_LOCK_SECONDS . ' SECOND,
                    AttemptedAt        = UTC_TIMESTAMP()
              WHERE Scope = ? AND Channel = ? AND AppSlug = ?
                AND (RefreshLockedUntil IS NULL OR RefreshLockedUntil < UTC_TIMESTAMP())'
        );
        $lock->bind_param('sss', $scope, $channel, $appSlug);
        $lock->execute();
        $won = $lock->affected_rows === 1;
        $lock->close();
        if (!$won) {
            return [
                'transport' => 'locked', 'httpStatus' => null, 'ok' => false,
                'data' => null, 'errorCode' => null,
                'errorMessage' => 'another refresh is already in flight for this scope',
                'durationMs' => 0.0,
            ];
        }
    } catch (\mysqli_sql_exception $e) {
        $blocked['transport']    = 'unreachable';
        $blocked['errorMessage'] = 'database error acquiring the refresh lock';
        return $blocked;
    }

    $result = intappsRequest('GET', '/v1/features/' . rawurlencode($appSlug));
    _intappsCommitFetchResult($db, $scope, $channel, $appSlug, $result);
    return $result;
}

/**
 * ELI5: "should this cosmetic thing be on, going by the gateway — or by
 * what iHymns has always done if the gateway has no opinion?"
 * WHY: the ONE consumer-facing yes/no API. Cache-only (delegates to
 * `intappsCachedScope()` — never blocks on HTTP). `$default` is the
 * compiled-in behaviour iHymns has today, so a typo'd key, a dormant
 * module, a cold cache, or a gateway that has simply never heard of this
 * key all resolve to EXACTLY today's behaviour — never a guess.
 */
function intappsFlag(\mysqli $db, string $featureKey, bool $default): bool
{
    try {
        $cached = intappsCachedScope($db, 'features');
    } catch (\Throwable $e) {
        return $default;
    }
    $features = $cached['payload']['features'] ?? null;
    if (!is_array($features)) {
        return $default;
    }
    foreach ($features as $f) {
        if (is_array($f) && (($f['feature_key'] ?? null) === $featureKey)) {
            return array_key_exists('enabled', $f) ? (bool)$f['enabled'] : $default;
        }
    }
    return $default;
}

/**
 * ELI5: the full detail behind one flag (its label, any extra metadata, when
 * it last flipped) — or nothing at all if the gateway has never mentioned
 * it.
 * WHY: for the admin status page's key-manifest diff and any future
 * consumer that wants more than a bare bool. Cache-only, same discipline as
 * `intappsFlag()`.
 *
 * @return array{enabled:bool,label:?string,metadata:?array,enabled_at:?string,disabled_at:?string}|null
 */
function intappsFlagMeta(\mysqli $db, string $featureKey): ?array
{
    try {
        $cached = intappsCachedScope($db, 'features');
    } catch (\Throwable $e) {
        return null;
    }
    $features = $cached['payload']['features'] ?? null;
    if (!is_array($features)) {
        return null;
    }
    foreach ($features as $f) {
        if (is_array($f) && (($f['feature_key'] ?? null) === $featureKey)) {
            return [
                'enabled'     => (bool)($f['enabled'] ?? false),
                'label'       => isset($f['label']) ? (string)$f['label'] : null,
                'metadata'    => is_array($f['metadata'] ?? null) ? $f['metadata'] : null,
                'enabled_at'  => isset($f['enabled_at']) ? (string)$f['enabled_at'] : null,
                'disabled_at' => isset($f['disabled_at']) ? (string)$f['disabled_at'] : null,
            ];
        }
    }
    return null;
}
