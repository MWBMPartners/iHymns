<?php
/**
 * iHymns — Sign in with Apple: RS256 verify core, attacked (#1402)
 *
 * Exercises includes/apple_siwa.php's PURE crypto functions
 * (appleSiwaDecodeJwtParts, appleSiwaJwkToPem, appleSiwaVerifyIdentityToken)
 * with a LOCALLY-GENERATED test RSA keypair — never a committed key, never a
 * network call, never a database. Builds a JWKS from the public half and
 * mints tokens with a tiny local signer, then throws every adversarial shape
 * at appleSiwaVerifyIdentityToken() the plan's §2.2/§6.1 checklist calls for:
 * algorithm confusion (alg:none, alg:HS256-with-public-key-as-secret),
 * signature tampering, wrong signing key, unknown/missing kid, iss/aud
 * mismatches (incl. the array-aud spec-tolerant form), expired/future-dated
 * tokens (incl. the 60s leeway boundary), missing/mismatched nonce, and
 * malformed JWS shapes (segment count, non-base64url, oversized).
 *
 *   php tests/php/test-apple-siwa-verify.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/apple_siwa.php';

$failures = 0;
$passed = 0;

function _tsivAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/* ---------------------------------------------------------------------- *
 * Test fixture: a fresh RSA-2048 keypair (the "real" Apple key) + a SECOND
 * unrelated keypair (the "wrong" key, for the signed-with-wrong-key case).
 * ---------------------------------------------------------------------- */
function _tsivB64Url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function _tsivB64UrlDecode(string $s): string
{
    $t = strtr($s, '-_', '+/');
    $pad = (4 - (strlen($t) % 4)) % 4;
    $decoded = base64_decode($t . str_repeat('=', $pad));
    return $decoded === false ? '' : $decoded;
}

$realKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($realKey === false || $wrongKey === false) {
    fwrite(STDERR, "FATAL: could not generate test RSA keypairs (openssl_pkey_new failed).\n");
    exit(1);
}
$realDetails = openssl_pkey_get_details($realKey);
$KID = 'test-kid-2026';
$jwks = [
    'keys' => [
        [
            'kty' => 'RSA',
            'kid' => $KID,
            'n'   => _tsivB64Url($realDetails['rsa']['n']),
            'e'   => _tsivB64Url($realDetails['rsa']['e']),
        ],
    ],
];
$AUD = 'app.ihymns';
$NOW = time();
$RAW_NONCE = bin2hex(random_bytes(16));
$NONCE_CLAIM = hash('sha256', $RAW_NONCE);

/**
 * Mint a signed JWT from arbitrary header/payload overrides, signed with
 * $signingKey (defaults to the real key). Pass $rawSignature to bypass
 * signing entirely (for malformed-signature cases).
 */
function _tsivMint(array $header, array $payload, $signingKey, ?string $rawSignatureOverride = null): string
{
    $h64 = _tsivB64Url((string)json_encode($header));
    $p64 = _tsivB64Url((string)json_encode($payload));
    $signingInput = "$h64.$p64";
    if ($rawSignatureOverride !== null) {
        return $signingInput . '.' . _tsivB64Url($rawSignatureOverride);
    }
    openssl_sign($signingInput, $sig, $signingKey, OPENSSL_ALGO_SHA256);
    return $signingInput . '.' . _tsivB64Url($sig);
}

function _tsivBaseHeader(string $kid = ''): array
{
    global $KID;
    return ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid !== '' ? $kid : $KID];
}

function _tsivBasePayload(): array
{
    global $AUD, $NOW, $NONCE_CLAIM;
    return [
        'iss'            => 'https://appleid.apple.com',
        'aud'            => $AUD,
        'exp'            => $NOW + 3600,
        'iat'            => $NOW,
        'sub'            => '001234.abcdef0123456789abcdef0123456789.5678',
        'nonce'          => $NONCE_CLAIM,
        'email'          => 'user@example.com',
        'email_verified' => 'true',
    ];
}

/* ---------------------------------------------------------------------- *
 * 1. Happy path — claims round-trip.
 * ---------------------------------------------------------------------- */
$happyJwt = _tsivMint(_tsivBaseHeader(), _tsivBasePayload(), $realKey);
$r = appleSiwaVerifyIdentityToken($happyJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === true, 'happy path verifies');
_tsivAssert(($r['claims']['sub'] ?? null) === '001234.abcdef0123456789abcdef0123456789.5678', 'happy path claims round-trip');

/* ---------------------------------------------------------------------- *
 * 2. Tampered payload — flip the sub after signing (signature no longer matches).
 * ---------------------------------------------------------------------- */
$payload = _tsivBasePayload();
$h64 = _tsivB64Url((string)json_encode(_tsivBaseHeader()));
$p64good = _tsivB64Url((string)json_encode($payload));
openssl_sign("$h64.$p64good", $sig, $realKey, OPENSSL_ALGO_SHA256);
$s64 = _tsivB64Url($sig);
$tamperedPayload = $payload;
$tamperedPayload['sub'] = 'attacker-controlled-sub';
$p64bad = _tsivB64Url((string)json_encode($tamperedPayload));
$tamperedJwt = "$h64.$p64bad.$s64";
$r = appleSiwaVerifyIdentityToken($tamperedJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_signature', 'tampered payload rejected (bad_signature)');

/* ---------------------------------------------------------------------- *
 * 3. Signature from the WRONG key.
 * ---------------------------------------------------------------------- */
$wrongKeyJwt = _tsivMint(_tsivBaseHeader(), _tsivBasePayload(), $wrongKey);
$r = appleSiwaVerifyIdentityToken($wrongKeyJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_signature', 'wrong signing key rejected (bad_signature)');

/* ---------------------------------------------------------------------- *
 * 4. alg: none — the header claims no signature is required. Rejected as a
 *    reject-gate BEFORE any signature check runs (docblock point 3).
 * ---------------------------------------------------------------------- */
$noneHeader = ['alg' => 'none', 'typ' => 'JWT', 'kid' => $KID];
$noneH64 = _tsivB64Url((string)json_encode($noneHeader));
$noneP64 = _tsivB64Url((string)json_encode(_tsivBasePayload()));
$noneJwt = "$noneH64.$noneP64.";  /* Empty signature segment — "no sig required" */
$r = appleSiwaVerifyIdentityToken($noneJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false, 'alg:none rejected');

/* ---------------------------------------------------------------------- *
 * 5. alg: HS256 with the RSA public-key material re-used as an HMAC secret
 *    (the classic algorithm-confusion attack). appleSiwaVerifyIdentityToken
 *    NEVER branches its verify method on the header — it always calls
 *    openssl_verify() — so this is rejected purely by the alg reject-gate.
 * ---------------------------------------------------------------------- */
$hsHeader = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $KID];
$hsH64 = _tsivB64Url((string)json_encode($hsHeader));
$hsP64 = _tsivB64Url((string)json_encode(_tsivBasePayload()));
$fakePubPem = $realDetails['key']; /* The "public key as HMAC secret" attempt */
$hmacSig = hash_hmac('sha256', "$hsH64.$hsP64", $fakePubPem, true);
$hsJwt = "$hsH64.$hsP64." . _tsivB64Url($hmacSig);
$r = appleSiwaVerifyIdentityToken($hsJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_alg', 'alg:HS256 (pubkey-as-HMAC-secret confusion) rejected (bad_alg)');

/* ---------------------------------------------------------------------- *
 * 6. Missing / unknown kid.
 * ---------------------------------------------------------------------- */
$noKidHeader = ['alg' => 'RS256', 'typ' => 'JWT'];
$noKidJwt = _tsivMint($noKidHeader, _tsivBasePayload(), $realKey);
$r = appleSiwaVerifyIdentityToken($noKidJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_kid', 'missing kid rejected (bad_kid)');

$unknownKidJwt = _tsivMint(_tsivBaseHeader('does-not-exist-in-jwks'), _tsivBasePayload(), $realKey);
$r = appleSiwaVerifyIdentityToken($unknownKidJwt, $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'unknown_kid', 'unknown kid rejected (unknown_kid)');

/* ---------------------------------------------------------------------- *
 * 7. Wrong iss.
 * ---------------------------------------------------------------------- */
$badIssPayload = _tsivBasePayload();
$badIssPayload['iss'] = 'https://evil.example.com';
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $badIssPayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_iss', 'wrong iss rejected (bad_iss)');

/* ---------------------------------------------------------------------- *
 * 8. Wrong aud (another developer's bundle id) — the aud-confusion kill.
 * ---------------------------------------------------------------------- */
$r = appleSiwaVerifyIdentityToken($happyJwt, $jwks, 'com.someoneelse.app', $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_aud', 'wrong aud rejected (bad_aud)');

/* aud as an array CONTAINING our id — accepted (Apple's spec-tolerant shape). */
$arrayAudPayload = _tsivBasePayload();
$arrayAudPayload['aud'] = ['some.other.app', $AUD];
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $arrayAudPayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === true, 'aud as array containing our id accepted');

/* aud as an array NOT containing our id — rejected. */
$arrayAudPayload2 = _tsivBasePayload();
$arrayAudPayload2['aud'] = ['some.other.app', 'yet.another.app'];
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $arrayAudPayload2, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'bad_aud', 'aud as array NOT containing our id rejected');

/* ---------------------------------------------------------------------- *
 * 9. Expired exp — and inside-60s-leeway accepted.
 * ---------------------------------------------------------------------- */
$expiredPayload = _tsivBasePayload();
$expiredPayload['exp'] = $NOW - 1000;
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $expiredPayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'expired', 'expired exp rejected');

$leewayPayload = _tsivBasePayload();
$leewayPayload['exp'] = $NOW - 30; /* 30s ago — within the 60s leeway */
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $leewayPayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === true, 'exp inside 60s clock-skew leeway accepted');

/* ---------------------------------------------------------------------- *
 * 10. Future-dated iat.
 * ---------------------------------------------------------------------- */
$futureIatPayload = _tsivBasePayload();
$futureIatPayload['iat'] = $NOW + 1000;
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $futureIatPayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'future_iat', 'future-dated iat rejected');

/* ---------------------------------------------------------------------- *
 * 11. Missing nonce claim / nonce hash mismatch.
 * ---------------------------------------------------------------------- */
$noNoncePayload = _tsivBasePayload();
unset($noNoncePayload['nonce']);
$r = appleSiwaVerifyIdentityToken(_tsivMint(_tsivBaseHeader(), $noNoncePayload, $realKey), $jwks, $AUD, $RAW_NONCE, $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'missing_nonce', 'missing nonce claim rejected');

$r = appleSiwaVerifyIdentityToken($happyJwt, $jwks, $AUD, 'a-completely-different-raw-nonce', $NOW);
_tsivAssert($r['ok'] === false && $r['reason'] === 'nonce_mismatch', 'nonce hash mismatch rejected');

/* ---------------------------------------------------------------------- *
 * 12. Malformed JWS shapes.
 * ---------------------------------------------------------------------- */
_tsivAssert(appleSiwaDecodeJwtParts("$h64.$p64good") === null, '2-segment JWT rejected');
_tsivAssert(appleSiwaDecodeJwtParts("$h64.$p64good.$s64.extra") === null, '4-segment JWT rejected');
_tsivAssert(appleSiwaDecodeJwtParts("$h64.not+valid/base64=.$s64") === null, 'non-base64url characters rejected');
_tsivAssert(appleSiwaDecodeJwtParts(str_repeat('A', 9000) . '.b.c') === null, 'oversized token (>8192 bytes) rejected');
_tsivAssert(appleSiwaDecodeJwtParts('') === null, 'empty string rejected');
_tsivAssert(appleSiwaDecodeJwtParts('not.json.here') === null, 'non-JSON segments rejected');

/* ---------------------------------------------------------------------- *
 * 13. appleSiwaJwkToPem — output is loadable by openssl_pkey_get_public and
 *     verifies a signature made with the private half.
 * ---------------------------------------------------------------------- */
$pem = appleSiwaJwkToPem($jwks['keys'][0]);
_tsivAssert(is_string($pem) && str_contains($pem, '-----BEGIN PUBLIC KEY-----'), 'appleSiwaJwkToPem produces PEM text');
$pubKeyResource = @openssl_pkey_get_public((string)$pem);
_tsivAssert($pubKeyResource !== false, 'appleSiwaJwkToPem output loads via openssl_pkey_get_public');
$testMsg = 'iHymns SIWA test message';
openssl_sign($testMsg, $testSig, $realKey, OPENSSL_ALGO_SHA256);
_tsivAssert(openssl_verify($testMsg, $testSig, (string)$pem, OPENSSL_ALGO_SHA256) === 1, 'appleSiwaJwkToPem PEM verifies a signature made with the matching private key');
_tsivAssert(appleSiwaJwkToPem(['kty' => 'oct', 'n' => 'x', 'e' => 'y']) === null, 'appleSiwaJwkToPem rejects non-RSA kty');
_tsivAssert(appleSiwaJwkToPem(['kty' => 'RSA']) === null, 'appleSiwaJwkToPem rejects missing n/e');

/* ---------------------------------------------------------------------- *
 * 14. Strict base64url decoder — rejects standard-alphabet chars / padding.
 *     The leading underscore signals "internal" by house convention, but
 *     PHP has no true private-function scope — it's a plain global function,
 *     directly callable from this test.
 * ---------------------------------------------------------------------- */
_tsivAssert(_appleSiwaB64UrlDecodeStrict('SGVsbG8') === 'Hello', 'strict b64url decoder accepts a valid segment');
_tsivAssert(_appleSiwaB64UrlDecodeStrict('SGVsbG8=') === null, 'strict b64url decoder rejects explicit padding');
_tsivAssert(_appleSiwaB64UrlDecodeStrict('has+plus') === null, 'strict b64url decoder rejects the standard-alphabet "+" character');
_tsivAssert(_appleSiwaB64UrlDecodeStrict('') === null, 'strict b64url decoder rejects an empty segment');

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
