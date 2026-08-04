<?php

declare(strict_types=1);

/**
 * iHymns — MWBM-IntAppsAPI local stub gateway (#1725/#1731 — the stub child)
 *
 * ELI5: a tiny fake copy of the real MWBM gateway server, running on
 * 127.0.0.1 for tests only. It checks requests the SAME way the real one
 * does (same three auth factors, same HMAC signature rule) so testing
 * against it actually proves something about the real signer — without
 * ever touching the real gateway, which this sandboxed container cannot
 * reach anyway.
 *
 * DETAIL:
 * -------
 * ⚠️ THIS FILE MUST NEVER MOVE OUT OF `tests/php/fixtures/`. Two
 * independent reasons, both load-bearing:
 *   1. `tools/run-php-tests.php` globs `tests/php/*.php` NON-recursively
 *      (its own docblock says so explicitly) — every `.php` file directly
 *      under `tests/php/` is executed as a TEST SUITE. `fixtures/` and
 *      `lib/` are sibling directories the glob never descends into (both
 *      already host non-suite PHP — `fixtures/orphan-allowlist.php`,
 *      `lib/mysqli_doubles.php`). Moving this file up a directory turns it
 *      into a "test" that runs to completion and exits 0 having asserted
 *      nothing, which is worse than a missing test (rule #34's
 *      under-reporting-is-worse-than-nothing lesson).
 *   2. `.github/workflows/deploy.yml` mirrors exactly five directories —
 *      `appWeb/public_html/`, `appWeb/private_html/`, `appWeb/data_share/`,
 *      `appWeb/.sql/`, `appWeb/.auth/` — and nothing outside `appWeb/` is
 *      ever uploaded. `tests/**` only triggers `test.yml`, never deploy.
 *      This is why the fake credentials below can be obviously-fake
 *      constants committed to the repo: this file structurally cannot
 *      reach a live environment.
 *
 * PORTED, NOT RE-IMAGINED. Every auth decision below is a line-for-line
 * port of the gateway's OWN source, pinned at commit `6816ed880c8b37b58
 * 14a6a5321c7992d0ee6c007` (verified against `/workspace/mwbm-intappsapi`
 * this session — `git log -1` on both source files returns exactly this
 * SHA):
 *   - Auth factors 1-3: `web/src/Middleware/AuthMiddleware.php:37-86`
 *     (X-App-ID equality against the registered UUID; a User-Agent prefix
 *     check via `str_starts_with()`, skipped only when the configured
 *     prefix is empty; X-API-Key via `password_verify()` against a bcrypt
 *     hash computed from the fixed plaintext key at startup).
 *   - HMAC on POST/PATCH/DELETE: `AuthMiddleware.php:104-121` +
 *     `web/src/Helpers/HmacValidator.php:24-55` — both `X-Timestamp` and
 *     `X-Signature` required; `ctype_digit($timestamp)`; `abs(time() -
 *     $ts) > 300` rejects; the canonical string is
 *     `$requestBody . '.' . $timestamp`, `hash_hmac('sha256', ...)`,
 *     `hash_equals()`. GETs are never signature-checked, matching the
 *     server exactly.
 *   - Generic, undifferentiated 403 on ANY failure:
 *     `{"success":false,"error":{"code":"ACCESS_DENIED","message":"Access
 *     denied"}}` — the server never says WHICH check failed, so this stub
 *     doesn't either; that opacity is itself part of what
 *     `intapps_client.php`'s opaque-403 handling must cope with.
 *
 * N2 (accepted residual, recorded here and in DEV_NOTES.md): this stub is
 * certified against gateway commit `6816ed8` only. If the deployed
 * gateway has since diverged from that commit, this stub's green result
 * says nothing about the live server. No cross-repo CI can enforce this;
 * `Issue A` (#1726)'s acceptance criteria include recording the live
 * `GET /v1/status` version + confirming `HmacValidator.php` is unchanged
 * since this SHA before any real enablement.
 *
 * FIXED, OBVIOUSLY-FAKE CREDENTIALS (never real, never committed
 * anywhere that matters — see reason 2 above):
 *   app_uuid    = '11111111-1111-1111-1111-111111111111'
 *   ua_prefix   = 'iHymns/'
 *   api_key     = 'stub-api-key-0123456789abcdef'
 *   hmac_secret = 64 lowercase 'a' characters
 *
 * SCENARIOS (via `?scenario=` query param on `GET /v1/features/{slug}`,
 * or an `X-Stub-Scenario` header — query param wins if both given; falls
 * back to reading a scenario-control FILE so a long-running `php -S`
 * process can be steered between requests without new headers, which the
 * production `intappsRequest()` deliberately never grows just for tests):
 *   good              — a well-formed features list (web.sotd_card, on)
 *   data-null         — {"success":true,"data":null}                 (remedy-4 trap)
 *   missing-features  — {"success":true,"data":{"count":0}}          (remedy-4 trap)
 *   oversized         — a >256 KiB body                              (remedy-3 trap)
 *   http-403          — forced ACCESS_DENIED regardless of auth
 *   hang              — sleep(10) before answering (client times out at 3s)
 *   malformed         — HTTP 200, body is not JSON at all
 *
 * Every request is appended to an access-log file (path from the
 * `INTAPPS_STUB_LOG_FILE` env var) so a test can assert "the stub was
 * actually hit" — the positive-control discipline stress-test defect D12
 * exists for (a fail-open assertion that never exercised the network
 * would trivially "pass").
 *
 * @requires PHP 8.1+ built-in server: `php -S 127.0.0.1:<port> tests/php/fixtures/intapps-stub-gateway.php`
 * @link https://www.php.net/manual/en/features.commandline.webserver.php
 */

const INTAPPS_STUB_APP_UUID    = '11111111-1111-1111-1111-111111111111';
const INTAPPS_STUB_UA_PREFIX   = 'iHymns/';
const INTAPPS_STUB_APP_SLUG    = 'ihymns';
const INTAPPS_STUB_API_KEY     = 'stub-api-key-0123456789abcdef';
const INTAPPS_STUB_HMAC_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; /* 64 'a's, matches the docblock above */
const INTAPPS_STUB_MAX_AGE_SECONDS = 300; /* Constants::HMAC_MAX_AGE in the real gateway */

/* ELI5: write one line to the access log so a test can prove this stub was
 * actually contacted, not just that the client "would have" contacted it.
 * WHY: the D12 positive-control discipline — a fail-open assertion that
 * never sent a byte over the wire would pass trivially and prove nothing. */
function _intappsStub_log(string $method, string $path, string $scenario, int $statusSent): void
{
    $logFile = getenv('INTAPPS_STUB_LOG_FILE');
    if ($logFile === false || $logFile === '') {
        return;
    }
    $line = sprintf(
        "%s\t%s\t%s\tscenario=%s\tstatus=%d\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        $method,
        $path,
        $scenario,
        $statusSent
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* ELI5: which canned answer should /v1/features/... give this time?
 * WHY: lets a long-running `php -S` process be steered between requests
 * from a REAL DATA SOURCE (a file, or the request itself) rather than
 * inventing a header the production client would then need to support. */
function _intappsStub_scenario(): string
{
    if (isset($_GET['scenario']) && is_string($_GET['scenario'])) {
        return $_GET['scenario'];
    }
    $headerScenario = $_SERVER['HTTP_X_STUB_SCENARIO'] ?? null;
    if (is_string($headerScenario) && $headerScenario !== '') {
        return $headerScenario;
    }
    $scenarioFile = getenv('INTAPPS_STUB_SCENARIO_FILE');
    if ($scenarioFile !== false && $scenarioFile !== '' && is_readable($scenarioFile)) {
        $fromFile = trim((string)file_get_contents($scenarioFile));
        if ($fromFile !== '') {
            return $fromFile;
        }
    }
    return 'good';
}

/* ELI5: read every header the request sent, lower-cased, the way
 * `Request::getHeader()` does in the real gateway.
 * WHY: PHP's built-in server exposes headers as HTTP_X_FOO in $_SERVER;
 * normalising once here keeps the rest of this file readable. */
function _intappsStub_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $val = $_SERVER[$key] ?? null;
    return is_string($val) ? $val : null;
}

/* ELI5: send the SAME generic 403 the real gateway sends on ANY auth
 * failure, no matter which check actually failed.
 * WHY: `ApiException::accessDenied()`'s envelope, `AuthMiddleware.php`
 * throughout — deliberately undifferentiated so the client's opaque-403
 * handling is exercised exactly as it would be against production. */
function _intappsStub_denyAndExit(string $method, string $path, string $scenario): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Access denied']]);
    _intappsStub_log($method, $path, $scenario, 403);
    exit;
}

/* ELI5: port of HmacValidator::validate() — is this signature correct for
 * this body + timestamp?
 * WHY: the ONE thing this whole fixture exists to prove: iHymns' signer
 * (`intappsSign()`) must be accepted by a verifier that reads the
 * canonical string EXACTLY the way the real gateway does, INCLUDING
 * rejecting the vendor's own five wrong bundled examples
 * (MWBM-intAppsAPI#120) if they were ever pasted in by mistake.
 */
function _intappsStub_hmacValid(string $signature, string $timestamp, string $rawBody, string $secret): bool
{
    if (!ctype_digit($timestamp)) {
        return false;
    }
    $ts  = (int)$timestamp;
    $now = time();
    if (abs($now - $ts) > INTAPPS_STUB_MAX_AGE_SECONDS) {
        return false;
    }
    /* THE canonical string — HmacValidator.php:50. NEVER the examples'
       METHOD|PATH|TIMESTAMP|BODY (MWBM-intAppsAPI#120). */
    $payload  = $rawBody . '.' . $timestamp;
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

/* ------------------------------------------------------------------- *
 *  ROUTING
 * ------------------------------------------------------------------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rawBody = (string)file_get_contents('php://input');
$scenario = _intappsStub_scenario();

header('Content-Type: application/json; charset=UTF-8');

/* Unauthenticated endpoints — the real gateway serves /v1/status without
   the 3-factor gate (it's a liveness probe). Mirrored here for parity;
   the e2e suite doesn't rely on it being unauthenticated, only that it
   answers 200 with a well-formed envelope. */
if ($method === 'GET' && $path === '/v1/status') {
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => ['status' => 'ok', 'version' => 'stub-6816ed8']]);
    _intappsStub_log($method, $path, $scenario, 200);
    exit;
}

/* ---- Factor 1: App ID (AuthMiddleware.php:37-41) ---- */
$appId = _intappsStub_header('X-App-Id');
if ($appId === null || $appId === '' || $appId !== INTAPPS_STUB_APP_UUID) {
    _intappsStub_denyAndExit($method, $path, $scenario);
}

/* ---- Factor 2: User-Agent prefix (AuthMiddleware.php:52-59) ---- */
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (!str_starts_with($ua, INTAPPS_STUB_UA_PREFIX)) {
    _intappsStub_denyAndExit($method, $path, $scenario);
}

/* ---- Factor 3: API key, bcrypt-verified (AuthMiddleware.php:61-86) ----
   The hash is computed fresh each request from the fixed plaintext —
   deliberately not cached to a file, so this fixture has no setup step
   beyond `php -S`. bcrypt is slow by design but this is test-only. */
$apiKey = _intappsStub_header('X-Api-Key');
/* Recomputed every request — `php -S`'s router executes this file fresh
   per request (no persistent process state to cache into), so a `static`
   here would be misleading rather than an optimisation. bcrypt cost is a
   non-issue at test-suite request volumes. */
$apiKeyHash = password_hash(INTAPPS_STUB_API_KEY, PASSWORD_BCRYPT);
if ($apiKey === null || $apiKey === '' || !password_verify($apiKey, $apiKeyHash)) {
    _intappsStub_denyAndExit($method, $path, $scenario);
}

/* ---- ResolvesApp slug-equality 403 ----
   The real gateway resolves {slug} in the path to a registered app and
   403s a mismatch. Mirrored for the /v1/features/{slug} family. */
if (str_starts_with($path, '/v1/features/')) {
    $slugPart = substr($path, strlen('/v1/features/'));
    $slugPart = rtrim($slugPart, '/');
    $slugPart = explode('/', $slugPart)[0]; /* strip a trailing /batch */
    if ($slugPart !== INTAPPS_STUB_APP_SLUG) {
        _intappsStub_denyAndExit($method, $path, $scenario);
    }
}

/* ---- HMAC validation for mutating requests (AuthMiddleware.php:103-122) ---- */
if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    $signature = _intappsStub_header('X-Signature');
    $timestamp = _intappsStub_header('X-Timestamp');
    if ($signature === null || $timestamp === null) {
        _intappsStub_denyAndExit($method, $path, $scenario);
    }
    if (!_intappsStub_hmacValid($signature, $timestamp, $rawBody, INTAPPS_STUB_HMAC_SECRET)) {
        _intappsStub_denyAndExit($method, $path, $scenario);
    }
}

/* Authenticated past this point. Scenario `http-403` forces a denial even
   though every factor above passed — simulates a revoked app / wrong
   scope discovered downstream of the generic middleware. */
if ($scenario === 'http-403') {
    _intappsStub_denyAndExit($method, $path, $scenario);
}

if (!str_starts_with($path, '/v1/features/')) {
    if ($method === 'GET' && $path === '/v1/heartbeat') {
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => ['status' => 'ok']]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown stub route']]);
    _intappsStub_log($method, $path, $scenario, 404);
    exit;
}

/* ---- /v1/features/{slug}[/batch] — the scenario switch ---- */
switch ($scenario) {
    case 'data-null':
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => null]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;

    case 'missing-features':
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => ['count' => 0]]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;

    case 'oversized':
        http_response_code(200);
        /* Comfortably over intapps_client.php's 262144-byte cap. */
        echo json_encode([
            'success' => true,
            'data' => [
                'features' => [[
                    'feature_key' => 'web.sotd_card',
                    'enabled'     => true,
                    'metadata'    => ['padding' => str_repeat('x', 300000)],
                ]],
            ],
        ]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;

    case 'hang':
        sleep(10); /* client's CURLOPT_TIMEOUT is 3s — this always wins the race */
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => ['features' => []]]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;

    case 'malformed':
        http_response_code(200);
        header('Content-Type: text/plain');
        echo 'not json at all {[';
        _intappsStub_log($method, $path, $scenario, 200);
        exit;

    case 'good':
    default:
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'features' => [[
                    'feature_key' => 'web.sotd_card',
                    'enabled'     => true,
                    'label'       => 'Song of the Day card',
                    'metadata'    => null,
                    'enabled_at'  => '2026-01-01T00:00:00Z',
                    'disabled_at' => null,
                ]],
            ],
        ]);
        _intappsStub_log($method, $path, $scenario, 200);
        exit;
}
