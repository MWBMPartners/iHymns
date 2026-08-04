<?php

declare(strict_types=1);

/**
 * iHymns — public/PWA API response envelope (v2 contract) — #1201 / #1761
 * ======================================================================
 *
 * ELI5
 * ----
 * Clients can ask (with one header) for every API answer to come back in the
 * SAME tidy shape: `{ ok: true, data: … }` when it worked, or
 * `{ ok: false, error: { code, message } }` when it didn't. Old apps that
 * don't ask keep getting exactly what they always got — nothing breaks.
 *
 * WHY THIS EXISTS
 * ---------------
 * The public `api.php` grew ~240 actions whose success payloads were each
 * their own ad-hoc shape (a bare array here, `{songs:…}` there) and whose
 * errors were `{error:"prose"}` a client could only branch on by string-
 * matching (#1677: branch on STATUS, not prose). #1201/#1761 unifies this
 * behind ONE mechanism (rule #35 — not per-endpoint drift):
 *
 *   - **v2 success** → `{ ok:true,  data:<the original payload> }`
 *   - **v2 error**   → `{ ok:false, error:{ code, message, …preserved } }`
 *                      with a meaningful HTTP status (`code` is a stable
 *                      machine string; `message` is the human sentence)
 *
 * v2 is **opt-in** via the `X-API-Version: 2` request header (`?v=2` for
 * curl / Swagger "Try it out"). Absent / any other value = legacy v1 bare
 * payloads, BYTE-FOR-BYTE — so already-deployed and OFFLINE-CACHED web/native
 * clients are completely unaffected. This is a versioned migration, never a
 * flag day. The web PWA (`apiFetchJson`) and the Apple client (`APIClient`
 * transport) send the header and transparently UNWRAP the envelope back to the
 * payload each call site already expects, so NO call site changed.
 *
 * These are pure helpers, extracted from `api.php` so the contract is unit-
 * testable WITHOUT running the api.php dispatcher (rule #35 — documentation is
 * not a mechanism; the mechanism is `tests/php/test-api-envelope.php`).
 * `sendJson()` (still in api.php, because it writes headers + echoes) applies
 * `apiEnvelopeWrap()` at its single chokepoint for all ~971 call sites.
 */

/**
 * Resolve the API contract version the client asked for.
 *
 * Source order: the `X-API-Version` request header (canonical — what the web
 * `apiFetchJson` and the Apple `APIClient` send), then a `?v=` / `?api_version=`
 * query param (non-browser callers). Only the exact value `"2"` selects v2;
 * everything else — `"1"`, absent, garbage — is v1.
 *
 * @return int 2 when the client opted into v2, else 1.
 */
function apiContractVersion(): int
{
    $v = (string)($_SERVER['HTTP_X_API_VERSION'] ?? '');
    if (trim($v) === '') {
        $v = (string)($_GET['v'] ?? $_GET['api_version'] ?? '');
    }
    return trim($v) === '2' ? 2 : 1;
}

/**
 * Normalise a legacy error payload into the v2 uniform error envelope:
 * `{ ok:false, error:{ code, message, …preserved } }`.
 *
 * `code` is a stable string a client can `switch` on — the caller's explicit
 * `code` if it passed one, else `http_<status>` derived from the HTTP status,
 * so status and code always agree. The human `message` comes from the legacy
 * `error`/`message` key. Every OTHER key the v1 error carried (`gone`,
 * `request_id`, `redirect_to`, …) is PRESERVED inside `error`, so v2 loses
 * nothing v1 exposed.
 *
 * @param array $data       The legacy payload passed to sendJson().
 * @param int   $statusCode The HTTP status (expected >= 400).
 * @return array The `{ ok:false, error:{…} }` envelope.
 */
function apiEnvelopeError(array $data, int $statusCode): array
{
    $message = '';
    if (isset($data['error']) && is_string($data['error'])) {
        $message = $data['error'];
    } elseif (isset($data['message']) && is_string($data['message'])) {
        $message = $data['message'];
    }
    $code = (isset($data['code']) && is_string($data['code']) && $data['code'] !== '')
        ? $data['code']
        : ('http_' . $statusCode);

    $error = ['code' => $code, 'message' => $message];
    foreach ($data as $k => $v) {
        if ($k === 'error' || $k === 'message' || $k === 'code') { continue; }
        $error[$k] = $v; // preserve gone / request_id / redirect_to / …
    }
    return ['ok' => false, 'error' => $error];
}

/**
 * The pure envelope decision `sendJson()` applies at its chokepoint.
 *
 * - v1 (`$version < 2`): returns `$data` UNCHANGED (byte-identical legacy).
 * - v2 + `$statusCode >= 400`: the `{ ok:false, error:{…} }` shape.
 * - v2 + success: `{ ok:true, data:$data }`.
 *
 * @param array $data
 * @param int   $statusCode
 * @param int   $version    Result of apiContractVersion().
 * @return array The final structure to json_encode.
 */
function apiEnvelopeWrap(array $data, int $statusCode, int $version): array
{
    if ($version < 2) {
        return $data;
    }
    return $statusCode >= 400
        ? apiEnvelopeError($data, $statusCode)
        : ['ok' => true, 'data' => $data];
}
