<?php

declare(strict_types=1);

/**
 * iHymns — public/PWA API v2 response-envelope contract (#1201 / #1761)
 *
 * ELI5
 * ----
 * A v2 client asks for every API answer in one tidy shape. This proves the
 * SERVER produces that shape, and that all three surfaces that must agree on it
 * — the PHP server, the web PWA client, and the Apple client — are actually
 * wired to it (not just documented to be).
 *
 * WHY THIS EXISTS (rule #35 — documentation is not a mechanism)
 * ------------------------------------------------------------
 * #1201's own issue comment asked for "a contract test alongside the spec",
 * citing #1677: `api-docs.yaml` documented the `X-Requested-With` requirement
 * correctly for the entire period its own client violated it. A doc is not a
 * mechanism. So this file:
 *   1. Unit-tests the PURE envelope decision (`apiEnvelopeWrap` /
 *      `apiEnvelopeError` / `apiContractVersion`) — success wrap, error wrap,
 *      version negotiation, key preservation, and the v1 byte-identical
 *      back-compat that keeps deployed/cached clients safe.
 *   2. Asserts, by reading the source of ALL THREE surfaces, that each is
 *      actually wired to the envelope: the server's `sendJson()` funnels
 *      through `apiEnvelopeWrap()`; the web `apiFetchJson()` SENDS
 *      `X-API-Version` and UNWRAPS `{ok,data}` / throws on `{ok:false,error}`;
 *      the Apple `APIClient` SENDS the header and its transport UNWRAPS via
 *      `unwrapEnvelope`. If any surface silently drops its half of the
 *      contract, this goes red.
 *
 *   php tests/php/test-api-envelope.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

require_once $pub . '/includes/api_envelope.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

echo "\nAPI v2 response-envelope contract (#1201/#1761)\n\n";

/* ---- 1. apiEnvelopeWrap — the pure decision -------------------------------- */

// v1 is byte-identical passthrough (the back-compat that protects deployed +
// offline-cached clients). Both success and error statuses.
ok('v1 success returns the bare payload unchanged',
    apiEnvelopeWrap(['songs' => [1, 2]], 200, 1) === ['songs' => [1, 2]]);
ok('v1 error returns the bare payload unchanged',
    apiEnvelopeWrap(['error' => 'nope'], 404, 1) === ['error' => 'nope']);

// v2 success → { ok:true, data:<payload> }
$wrapped = apiEnvelopeWrap(['songs' => [1, 2]], 200, 2);
ok('v2 success wraps as { ok:true, data:<payload> }',
    $wrapped === ['ok' => true, 'data' => ['songs' => [1, 2]]]);

// v2 error → { ok:false, error:{ code, message } } with a status-derived code
$err = apiEnvelopeWrap(['error' => 'Song not found.'], 404, 2);
ok('v2 error wraps as { ok:false, error:{ code, message } }',
    ($err['ok'] ?? null) === false
    && ($err['error']['code'] ?? null) === 'http_404'
    && ($err['error']['message'] ?? null) === 'Song not found.');

// v2 error honours an explicit `code` when the caller supplied one
$errCoded = apiEnvelopeWrap(['error' => 'bad', 'code' => 'song_gone'], 410, 2);
ok('v2 error uses the caller-supplied code over the status default',
    ($errCoded['error']['code'] ?? null) === 'song_gone');

// v2 error preserves every OTHER legacy key inside `error` (nothing lost)
$errExtra = apiEnvelopeWrap(['error' => 'Song removed.', 'gone' => true], 410, 2);
ok('v2 error preserves extra legacy keys (e.g. gone) inside error',
    ($errExtra['error']['gone'] ?? null) === true
    && ($errExtra['error']['message'] ?? null) === 'Song removed.');

// A 400+ status is what selects the error shape (not the presence of an
// 'error' key) — a success payload that happens to contain 'error' at 200
// still wraps as success.
$succWithErrorKey = apiEnvelopeWrap(['error' => 0, 'result' => 'ok'], 200, 2);
ok('status < 400 always wraps as success even if payload has an error key',
    ($succWithErrorKey['ok'] ?? null) === true
    && ($succWithErrorKey['data'] ?? null) === ['error' => 0, 'result' => 'ok']);

/* ---- 2. apiContractVersion — version negotiation --------------------------- */

$restore = [$_SERVER['HTTP_X_API_VERSION'] ?? null, $_GET];

$_SERVER['HTTP_X_API_VERSION'] = '2'; $_GET = [];
ok("X-API-Version: 2 selects v2", apiContractVersion() === 2);

$_SERVER['HTTP_X_API_VERSION'] = '1'; $_GET = [];
ok("X-API-Version: 1 stays v1", apiContractVersion() === 1);

$_SERVER['HTTP_X_API_VERSION'] = 'garbage'; $_GET = [];
ok('an unrecognised version header stays v1 (fail-safe default)', apiContractVersion() === 1);

unset($_SERVER['HTTP_X_API_VERSION']); $_GET = [];
ok('absent version header stays v1 (deployed clients unaffected)', apiContractVersion() === 1);

unset($_SERVER['HTTP_X_API_VERSION']); $_GET = ['v' => '2'];
ok('?v=2 query fallback selects v2 (curl / Swagger)', apiContractVersion() === 2);

// restore globals
if ($restore[0] === null) { unset($_SERVER['HTTP_X_API_VERSION']); }
else { $_SERVER['HTTP_X_API_VERSION'] = $restore[0]; }
$_GET = $restore[1];

/* ---- 3. all three surfaces are WIRED to the contract (rule #35) ------------ */

$stripPhp = static function (string $src): string {
    // Drop comments so a `#`/`//`-mentioned token can't satisfy a "wired" check.
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
};

// (a) SERVER: sendJson() must funnel through apiEnvelopeWrap() — the single
//     chokepoint that carries the envelope for all ~971 call sites.
$apiPhp = $stripPhp((string)file_get_contents($pub . '/api.php'));
ok('server: sendJson() funnels through apiEnvelopeWrap() (the one chokepoint)',
    (bool)preg_match('/function sendJson\b.*?apiEnvelopeWrap\s*\(/s', $apiPhp));
ok('server: api.php requires includes/api_envelope.php',
    str_contains($apiPhp, "api_envelope.php'"));

// (b) WEB PWA: apiFetchJson() must SEND the header AND UNWRAP the envelope.
//     Comments are JS, not PHP — strip line/block comments crudely for the
//     "wired" check so a commented mention can't satisfy it.
$jsRaw = (string)file_get_contents($pub . '/js/utils/api-client.js');
$js = preg_replace('#/\*.*?\*/#s', '', $jsRaw) ?? $jsRaw;         // block comments
$js = preg_replace('#(?<!:)//[^\n]*#', '', $js) ?? $js;          // line comments (naive; ok for this check)
ok('web: apiFetchJson sends X-API-Version',
    (bool)preg_match("/set\\(\\s*['\"]X-API-Version['\"]\\s*,\\s*['\"]2['\"]/", $js));
ok('web: apiFetchJson unwraps the success envelope (body.ok / body.data)',
    str_contains($js, 'body.ok') && str_contains($js, 'body.data'));
ok('web: apiFetchJson surfaces envelope errors with a status a caller can branch on',
    str_contains($js, 'body.error') && preg_match('/\.status\s*=/', $js) === 1);

// (c) APPLE: the client must SEND the header, and its transport must UNWRAP.
$swiftHeader = (string)file_get_contents(
    $root . '/appApple/Packages/iHymnsKit/Sources/IHAPI/APIClient.swift');
$swiftNet = (string)file_get_contents(
    $root . '/appApple/Packages/iHymnsKit/Sources/IHAPI/APIClient+Networking.swift');
ok('apple: APIClient sends X-API-Version: 2',
    (bool)preg_match('/setValue\("2",\s*forHTTPHeaderField:\s*"X-API-Version"\)/', $swiftHeader));
ok('apple: transport defines unwrapEnvelope(...)',
    (bool)preg_match('/func\s+unwrapEnvelope\s*\(/', $swiftNet));
ok('apple: performOnce returns through unwrapEnvelope (not raw data)',
    str_contains($swiftNet, 'return Self.unwrapEnvelope(data)'));

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nThe v2 envelope contract spans THREE surfaces that must agree with a\n";
    echo "mechanism, not a comment (rule #35): the PHP server wrap, the web PWA\n";
    echo "unwrap, and the Apple transport unwrap. If one half ships without the\n";
    echo "other, v2 clients get a shape they cannot read — the silent-contract-gap\n";
    echo "class this file exists to prevent.\n";
    exit(1);
}
echo "\nAll envelope-contract assertions passed.\n";
