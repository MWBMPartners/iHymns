<?php

declare(strict_types=1);

/**
 * iHymns — Sign in with Apple: crypto + protocol helper (#1402)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS IS:
 * Everything Apple-crypto lives in this ONE module (modularity rule) so
 * `?action=auth_apple` and `?action=account_delete` (appWeb/public_html/api.php)
 * both consume it instead of forking the logic. No JWT library exists in this
 * repo and none is being added — there is no composer/vendor pipeline, and a
 * vendored library is a bigger review surface than ~200 lines of hand-rolled
 * `openssl_*` glue. The functions here are deliberately split into a PURE core
 * (no DB, no network — testable with plain assertions) and an I/O shell (curl
 * to Apple, DB reads/writes) so tests/php/test-apple-siwa-verify.php and
 * tests/php/test-apple-client-secret.php can attack the crypto with tampered
 * inputs without any live infrastructure.
 *
 * THE TWO CRYPTO OPERATIONS:
 *
 *   1. VERIFY (RS256) — Apple signs identity tokens with an RSA key; the
 *      public half is published as a JWKS at https://appleid.apple.com/auth/keys.
 *      appleSiwaVerifyIdentityToken() is the ONE place that decides whether an
 *      identity token is genuinely from Apple, for THIS app, unexpired, and
 *      carrying the nonce we handed out. See its docblock for the full
 *      adversarial checklist (algorithm confusion, aud confusion, replay, …).
 *
 *   2. SIGN (ES256) — outbound calls to Apple's OWN token/revoke endpoints
 *      require us to prove WE are the app.ihymns backend, via a short-lived
 *      JWT ("client_secret") signed with the owner-provisioned `.p8` private
 *      key (an EC P-256 key Apple issues per Developer account). This is
 *      ONLY needed for the refresh-token exchange (auth_apple, best-effort)
 *      and the Apple-side revoke (account_delete, best-effort) — RS256
 *      identity-token VERIFICATION needs no secret at all, so sign-in works
 *      even before the owner has pasted the `.p8` key.
 *
 * SECURITY INVARIANTS (do not weaken these when touching this file):
 *   - TLS verification is ALWAYS on for outbound Apple calls
 *     (CURLOPT_SSL_VERIFYPEER true, CURLOPT_SSL_VERIFYHOST 2) — the transport
 *     IS the trust anchor for the hardcoded https://appleid.apple.com origin.
 *   - Outbound calls never follow redirects off-host
 *     (CURLOPT_FOLLOWLOCATION false, CURLOPT_MAXREDIRS 0) and are pinned to
 *     the https:// scheme (CURLOPT_PROTOCOLS / CURLOPT_REDIR_PROTOCOLS).
 *   - The verify path is PINNED to RS256 by code — the JWT header's `alg` is
 *     checked as a reject-gate only; it never selects which openssl function
 *     runs. This is the standard defence against "alg: none" / "alg: HS256
 *     with the RSA public key as the HMAC secret" algorithm-confusion attacks.
 *   - Nonce consumption (appleSiwaConsumeNonce) is a single atomic INSERT
 *     against a UNIQUE key — race-safe against two concurrent replays of the
 *     same stolen token.
 *   - Nothing in this file ever calls error_log()/logActivity() with a raw
 *     identityToken, RefreshToken, or nonce value — tests/php/
 *     test-apple-siwa-sources.php statically guards this.
 *
 * @link https://developer.apple.com/documentation/sign_in_with_apple/generate_and_validate_tokens  Apple's token-validation guide
 * @link https://developer.apple.com/documentation/sign_in_with_apple/revoke_tokens                 Apple's /auth/revoke docs
 * @link https://www.rfc-editor.org/rfc/rfc7517  JSON Web Key (JWK) — the JWKS shape parsed here
 * @link https://www.rfc-editor.org/rfc/rfc7518#section-3.3  JWS RSA (RS256) signing
 * @link https://www.rfc-editor.org/rfc/rfc7518#section-3.4  JWS ECDSA (ES256) signing + the raw r‖s encoding
 * @link https://www.php.net/manual/en/function.openssl-verify.php
 * @link https://www.php.net/manual/en/function.openssl-sign.php
 * @see appWeb/public_html/api.php  ?action=auth_apple / ?action=account_delete — the two consumers
 * @see .claude/apple-backend-auth-plan.md  §2.0 / §2.4 / §3.4 — the design this file implements
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** The app's bundle id — public by definition (embedded in every shipped IPA/App
 *  Bundle Identifier), identical across every Apple platform target. NOT a secret
 *  and NOT owner-variable, so it is code, not tblAppSettings config. Doubles as
 *  the ES256 client_secret's `sub` claim (§3.4). */
const IHYMNS_SIWA_CLIENT_ID = 'app.ihymns';

/**
 * The single Apple-registered "return URL" path for the WEB (browser popup)
 * Sign in with Apple flow (#1470 W1). MUST be byte-identical to:
 *   (a) the Return URL registered against the `apple_siwa_services_id`
 *       Services ID in Apple's Developer portal — for EVERY docroot
 *       hostname (dev./beta./www./bare-apex `ihymns.app`); and
 *   (b) the `redirectURI` the W2 front-end's `AppleID.auth.init({...})`
 *       call supplies (`js/modules/apple-signin.js`, not built by this W1
 *       backend slice) when it initiates the popup flow.
 * Apple's `/auth/token` code-exchange REJECTS the request (400
 * "invalid_grant") on ANY mismatch between the redirect_uri implied by the
 * original `/auth/authorize` popup call and the one resent here — so a
 * change to this constant is a breaking change that must be mirrored in
 * BOTH Apple's portal and the front-end in the same deploy.
 *
 * '/' (the origin root) rather than a dedicated `/auth/apple/callback`-style
 * path: POPUP mode (plan §4) means Apple's redirect happens INSIDE the
 * popup/iframe, never the top-level page, so there is no server-rendered
 * "callback page" to route to — the popup's own JS reads the result and
 * closes itself. A future REDIRECT-mode fallback (plan §4/W4) would need a
 * real routed path here instead of the bare root.
 */
const APPLE_SIWA_WEB_RETURN_PATH = '/';

/** DER encoding of AlgorithmIdentifier { rsaEncryption, NULL } — the fixed prefix
 *  every RSA SubjectPublicKeyInfo carries. OID 1.2.840.113549.1.1.1. */
const _APPLE_SIWA_RSA_ALGID_DER = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";

/* =============================================================================
 * PURE HELPERS — base64url + ASN.1 DER (no DB, no network)
 * ========================================================================== */

/**
 * Strict base64url decode (RFC 4648 §5): reject standard-alphabet characters
 * (`+`, `/`) and explicit padding (`=`) in the INPUT — a JWT segment is never
 * padded and never uses the standard alphabet, so tolerating either would
 * accept malformed input a conforming Apple token could never produce.
 *
 * ELI5: turns the URL-safe scrambled text back into raw bytes, but only if it
 * was scrambled the RIGHT way — anything that looks even slightly off is
 * rejected rather than guessed at.
 *
 * @param string $segment One dot-separated JWT segment (or a JWK n/e value).
 * @return string|null Raw decoded bytes, or null on ANY malformation.
 */
function _appleSiwaB64UrlDecodeStrict(string $segment): ?string
{
    if ($segment === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
        return null;
    }
    $translated = strtr($segment, '-_', '+/');
    $padLen = (4 - (strlen($translated) % 4)) % 4;
    $padded = $translated . str_repeat('=', $padLen);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? null : $decoded;
}

/** Base64url-ENCODE (no padding) — the JWS-signing counterpart of the strict decoder above. */
function _appleSiwaB64UrlEncode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** DER length octet(s) for a value of byte-length $len (short- or long-form per X.690). */
function _appleSiwaDerLength(int $len): string
{
    if ($len < 0x80) {
        return chr($len);
    }
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xFF) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/**
 * DER INTEGER for an unsigned big-endian byte string. Strips any existing
 * leading zero bytes then re-adds EXACTLY one iff the high bit of the first
 * remaining byte is set — DER integers are signed two's-complement, so a
 * value whose top bit is 1 needs a 0x00 guard byte to stay positive. This is
 * "the leading-zero guard" the plan calls out for the JWK n/e → DER step.
 */
function _appleSiwaDerInteger(string $raw): string
{
    $raw = ltrim($raw, "\x00");
    if ($raw === '') {
        $raw = "\x00";
    }
    if ((ord($raw[0]) & 0x80) !== 0) {
        $raw = "\x00" . $raw;
    }
    return "\x02" . _appleSiwaDerLength(strlen($raw)) . $raw;
}

/** DER SEQUENCE wrapping an already-encoded body. */
function _appleSiwaDerSequence(string $body): string
{
    return "\x30" . _appleSiwaDerLength(strlen($body)) . $body;
}

/** DER BIT STRING with zero unused bits — used to wrap the RSAPublicKey inside SubjectPublicKeyInfo. */
function _appleSiwaDerBitString(string $raw): string
{
    return "\x03" . _appleSiwaDerLength(strlen($raw) + 1) . "\x00" . $raw;
}

/**
 * Read one DER length field starting at $offset.
 *
 * @return array{0:?int,1:int} [byte length, next offset] — length is null on malformation.
 */
function _appleSiwaDerReadLength(string $der, int $offset): array
{
    $len = strlen($der);
    if ($offset >= $len) {
        return [null, $offset];
    }
    $first = ord($der[$offset]);
    $offset++;
    if (($first & 0x80) === 0) {
        return [$first, $offset];
    }
    $numBytes = $first & 0x7F;
    if ($numBytes === 0 || $numBytes > 4 || $offset + $numBytes > $len) {
        return [null, $offset];
    }
    $val = 0;
    for ($i = 0; $i < $numBytes; $i++) {
        $val = ($val << 8) | ord($der[$offset + $i]);
    }
    return [$val, $offset + $numBytes];
}

/**
 * Parse a DER `SEQUENCE { INTEGER r, INTEGER s }` (what `openssl_sign()`
 * returns for an ECDSA key) into the RAW `r‖s` concatenation JOSE/ES256
 * requires — 2×$partLen bytes, each integer left-padded with zero bytes and
 * stripped of its DER sign-guard byte. This is "the DER→JOSE trap" the plan
 * calls out: skipping this step produces a signature `openssl_verify()` can
 * check but no JOSE-conformant verifier (incl. Apple's own) can.
 *
 * @return string|null Exactly 2*$partLen raw bytes, or null on malformed DER
 *                       or an integer wider than $partLen bytes.
 */
function _appleSiwaDerEcdsaToRaw(string $der, int $partLen): ?string
{
    $len = strlen($der);
    if ($len < 8 || ord($der[0] ?? "\x00") !== 0x30) {
        return null;
    }
    $offset = 1;
    [$seqLen, $offset] = _appleSiwaDerReadLength($der, $offset);
    if ($seqLen === null) {
        return null;
    }

    if ($offset >= $len || ord($der[$offset]) !== 0x02) {
        return null;
    }
    $offset++;
    [$rLen, $offset] = _appleSiwaDerReadLength($der, $offset);
    if ($rLen === null || $offset + $rLen > $len) {
        return null;
    }
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    if ($offset >= $len || ord($der[$offset]) !== 0x02) {
        return null;
    }
    $offset++;
    [$sLen, $offset] = _appleSiwaDerReadLength($der, $offset);
    if ($sLen === null || $offset + $sLen > $len) {
        return null;
    }
    $s = substr($der, $offset, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    if ($r === '' || $s === '' || strlen($r) > $partLen || strlen($s) > $partLen) {
        return null;
    }

    return str_pad($r, $partLen, "\x00", STR_PAD_LEFT) . str_pad($s, $partLen, "\x00", STR_PAD_LEFT);
}

/* =============================================================================
 * JWT decode + JWK→PEM + RS256 verify (all PURE — no DB, no network)
 * ========================================================================== */

/**
 * Split a compact JWS (`header.payload.signature`) into its parsed parts.
 * Rejects ANY malformation rather than guessing — a JWT arriving here is
 * fully attacker-controlled request input.
 *
 * @param string $jwt Raw compact JWS from the client's `identityToken` field.
 * @return array{header:array,payload:array,signature:string,signingInput:string}|null
 *               Null on: segment count ≠ 3, either header/payload segment
 *               failing strict base64url decode, either decoding to non-JSON
 *               or non-object JSON, or the whole token exceeding 8192 bytes
 *               (Apple's real identity tokens run well under 2 KB — an
 *               oversized token is either a bug or an abuse probe).
 */
function appleSiwaDecodeJwtParts(string $jwt): ?array
{
    if ($jwt === '' || strlen($jwt) > 8192) {
        return null;
    }
    $segments = explode('.', $jwt);
    if (count($segments) !== 3) {
        return null;
    }
    [$h64, $p64, $s64] = $segments;

    $headerRaw  = _appleSiwaB64UrlDecodeStrict($h64);
    $payloadRaw = _appleSiwaB64UrlDecodeStrict($p64);
    $sigRaw     = _appleSiwaB64UrlDecodeStrict($s64);
    if ($headerRaw === null || $payloadRaw === null || $sigRaw === null || $sigRaw === '') {
        return null;
    }

    $header  = json_decode($headerRaw, true);
    $payload = json_decode($payloadRaw, true);
    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    return [
        'header'       => $header,
        'payload'      => $payload,
        'signature'    => $sigRaw,
        'signingInput' => $h64 . '.' . $p64,
    ];
}

/**
 * Convert an RSA JWK (`{kty:"RSA", n, e}`, as published in Apple's JWKS) into
 * a PEM SubjectPublicKeyInfo `openssl_verify()` can consume directly — PHP's
 * openssl extension has no native JWK support, so the DER is hand-built:
 * `RSAPublicKey ::= SEQUENCE { INTEGER n, INTEGER e }`, wrapped in
 * `SubjectPublicKeyInfo ::= SEQUENCE { AlgorithmIdentifier, BIT STRING
 * RSAPublicKey }` per RFC 5280 / RFC 3447 Appendix A.1.1.
 *
 * @param array $jwk One entry from Apple's `/auth/keys` `keys` array.
 * @return string|null PEM text (with BEGIN/END PUBLIC KEY markers), or null
 *                       when `kty` isn't `RSA` or `n`/`e` are missing/malformed.
 */
function appleSiwaJwkToPem(array $jwk): ?string
{
    if (($jwk['kty'] ?? null) !== 'RSA') {
        return null;
    }
    $nB64 = $jwk['n'] ?? null;
    $eB64 = $jwk['e'] ?? null;
    if (!is_string($nB64) || !is_string($eB64) || $nB64 === '' || $eB64 === '') {
        return null;
    }

    $n = _appleSiwaB64UrlDecodeStrict($nB64);
    $e = _appleSiwaB64UrlDecodeStrict($eB64);
    if ($n === null || $e === null || $n === '' || $e === '') {
        return null;
    }

    $rsaPublicKey = _appleSiwaDerSequence(_appleSiwaDerInteger($n) . _appleSiwaDerInteger($e));
    $bitString    = _appleSiwaDerBitString($rsaPublicKey);
    $spki         = _appleSiwaDerSequence(_APPLE_SIWA_RSA_ALGID_DER . $bitString);

    $b64   = base64_encode($spki);
    $lines = trim(chunk_split($b64, 64, "\n"));
    return "-----BEGIN PUBLIC KEY-----\n{$lines}\n-----END PUBLIC KEY-----\n";
}

/**
 * THE verifier — decides whether an Apple identity token is genuine, for
 * THIS app, unexpired, and carries the nonce we issued. Adversarial checks,
 * in order (the first failure short-circuits):
 *
 *   1. Well-formed JWS (appleSiwaDecodeJwtParts).
 *   2. Header `typ` (if present) is `JWT`.
 *   3. Header `alg` is EXACTLY `RS256` — a pure reject-gate. This function
 *      NEVER branches its verification method on the header; it always
 *      calls openssl_verify() with SHA-256/RSA. That is what stops
 *      "alg: none" (no signature required) and "alg: HS256 with the RSA
 *      public key re-used as an HMAC secret" (algorithm-confusion) attacks —
 *      both are rejected here before any signature check runs, and even if
 *      they weren't, this function has no HMAC/none code path to fall into.
 *   4. `kid` present, non-empty, ≤ 64 chars, and resolves to a key in the
 *      caller-supplied $jwks (a plain array lookup — kid is NEVER used to
 *      build a URL, file path, or SQL fragment).
 *   5. RSA signature verifies against that key's PEM (appleSiwaJwkToPem).
 *   6. `iss === 'https://appleid.apple.com'` (strict `===`).
 *   7. `aud` equals $expectedAud, either as a bare string or as an array
 *      containing it (Apple's spec allows either shape) — any other value
 *      rejects. This is the aud-confusion kill: an otherwise-valid Apple
 *      token minted for a DIFFERENT developer's app can never pass here.
 *   8. `exp` numeric and `> $now - 60` (60s clock-skew leeway).
 *   9. `iat` numeric and `< $now + 60`.
 *  10. `sub` a non-empty string ≤ 128 chars.
 *  11. `nonce` claim present and `hash_equals()`-compares to
 *      `sha256($rawNonce)`. A token with NO nonce claim is rejected outright
 *      (our client always sends one) — accepting nonce-less tokens would
 *      reopen replay via identity tokens harvested from other Apple-SDK
 *      contexts that don't set a nonce.
 *
 * Nonce CONSUMPTION (the anti-replay ledger write) deliberately happens
 * OUTSIDE this function, in the caller, AFTER it returns ok — see
 * appleSiwaConsumeNonce()'s docblock for why (burn-order matters).
 *
 * @param string $jwt         Raw compact JWS (client's `identityToken`).
 * @param array  $jwks        `{"keys": [...]}` — Apple's published key set (cached/fetched by the caller).
 * @param string $expectedAud Our client id (IHYMNS_SIWA_CLIENT_ID in production).
 * @param string $rawNonce    The RAW (unhashed) nonce the client says it used.
 * @param int    $now         Current unix time (injected for testability).
 * @return array{ok:true,claims:array}|array{ok:false,reason:string}
 *               `reason` is an internal diagnostic code — the HTTP layer
 *               MUST map every failure to the SAME generic 401 message
 *               (no replay/enumeration oracle); `reason` is safe to log to
 *               tblActivityLog (it is a fixed short code, never token content).
 */
function appleSiwaVerifyIdentityToken(string $jwt, array $jwks, string $expectedAud, string $rawNonce, int $now): array
{
    $parts = appleSiwaDecodeJwtParts($jwt);
    if ($parts === null) {
        return ['ok' => false, 'reason' => 'malformed_jwt'];
    }
    $header  = $parts['header'];
    $payload = $parts['payload'];

    $typ = $header['typ'] ?? null;
    if ($typ !== null && (!is_string($typ) || strtoupper($typ) !== 'JWT')) {
        return ['ok' => false, 'reason' => 'bad_typ'];
    }

    /* Pinned reject-gate — see docblock point 3. Never used to select a verify function. */
    if (($header['alg'] ?? null) !== 'RS256') {
        return ['ok' => false, 'reason' => 'bad_alg'];
    }

    $kid = $header['kid'] ?? null;
    if (!is_string($kid) || $kid === '' || strlen($kid) > 64) {
        return ['ok' => false, 'reason' => 'bad_kid'];
    }

    $jwk = null;
    foreach (($jwks['keys'] ?? []) as $candidate) {
        if (is_array($candidate) && ($candidate['kid'] ?? null) === $kid) {
            $jwk = $candidate;
            break;
        }
    }
    if ($jwk === null) {
        return ['ok' => false, 'reason' => 'unknown_kid'];
    }

    $pem = appleSiwaJwkToPem($jwk);
    if ($pem === null) {
        return ['ok' => false, 'reason' => 'bad_jwk'];
    }

    if (openssl_verify($parts['signingInput'], $parts['signature'], $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'reason' => 'bad_signature'];
    }

    if (($payload['iss'] ?? null) !== 'https://appleid.apple.com') {
        return ['ok' => false, 'reason' => 'bad_iss'];
    }

    $aud = $payload['aud'] ?? null;
    $audOk = (is_string($aud) && $aud === $expectedAud)
          || (is_array($aud) && in_array($expectedAud, $aud, true));
    if (!$audOk) {
        return ['ok' => false, 'reason' => 'bad_aud'];
    }

    $exp = $payload['exp'] ?? null;
    if (!is_numeric($exp) || (int)$exp <= ($now - 60)) {
        return ['ok' => false, 'reason' => 'expired'];
    }

    $iat = $payload['iat'] ?? null;
    if (!is_numeric($iat) || (int)$iat >= ($now + 60)) {
        return ['ok' => false, 'reason' => 'future_iat'];
    }

    $sub = $payload['sub'] ?? null;
    if (!is_string($sub) || $sub === '' || strlen($sub) > 128) {
        return ['ok' => false, 'reason' => 'bad_sub'];
    }

    $nonceClaim = $payload['nonce'] ?? null;
    if (!is_string($nonceClaim) || $nonceClaim === '') {
        return ['ok' => false, 'reason' => 'missing_nonce'];
    }
    if (!hash_equals($nonceClaim, hash('sha256', $rawNonce))) {
        return ['ok' => false, 'reason' => 'nonce_mismatch'];
    }

    return ['ok' => true, 'claims' => $payload];
}

/* =============================================================================
 * ES256 client_secret minting (PURE — no DB, no network; consumes an
 * already-loaded PEM string, never reads tblAppSettings itself)
 * ========================================================================== */

/**
 * Mint an Apple `client_secret` JWT (ES256) for server-to-server calls
 * (`/auth/token` code exchange, `/auth/revoke`) — see Apple's "Generate and
 * validate tokens" guide. Per-call, 5-minute expiry: nothing is stored,
 * nothing to leak if a log captures it, no rotation bookkeeping needed.
 *
 * @param string $teamId    Apple Developer Team ID (10 chars) — the JWT `iss`.
 * @param string $keyId     The SIWA key's Key ID (10 chars) — the JWS header `kid`.
 * @param string $clientId  Our bundle id (IHYMNS_SIWA_CLIENT_ID) — the JWT `sub`.
 * @param string $p8Pem     The owner-provisioned `.p8` PRIVATE KEY PEM (EC P-256, PKCS#8).
 * @param int    $now       Current unix time (injected for testability).
 * @return string|null Compact JWS, or null when the PEM doesn't parse or isn't an EC P-256 key.
 */
function appleSiwaBuildClientSecret(string $teamId, string $keyId, string $clientId, string $p8Pem, int $now): ?string
{
    if ($teamId === '' || $keyId === '' || $clientId === '' || trim($p8Pem) === '') {
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
    /* P-256 is reported as either name depending on the OpenSSL build. */
    if ($curveName !== 'prime256v1' && $curveName !== 'secp256r1') {
        return null;
    }

    $header = ['alg' => 'ES256', 'kid' => $keyId];
    $claims = [
        'iss' => $teamId,
        'iat' => $now,
        'exp' => $now + 300,
        'aud' => 'https://appleid.apple.com',
        'sub' => $clientId,
    ];

    $h64 = _appleSiwaB64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $p64 = _appleSiwaB64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = "{$h64}.{$p64}";

    $derSig = '';
    if (!openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    /* P-256 → 32-byte r, 32-byte s (see _appleSiwaDerEcdsaToRaw's docblock — "the DER→JOSE trap"). */
    $rawSig = _appleSiwaDerEcdsaToRaw($derSig, 32);
    if ($rawSig === null) {
        return null;
    }

    return $signingInput . '.' . _appleSiwaB64UrlEncode($rawSig);
}

/* =============================================================================
 * WEB SIWA — dormancy gate + redirect-URI helpers (#1470 W1)
 *
 * Everything below is settings/environment plumbing, NOT crypto — it decides
 * (a) whether ?action=auth_apple should even ENTERTAIN `platform=web` on
 * THIS deploy channel, and (b) what redirect_uri a web code exchange must
 * present. Both are consumed by api.php's `auth_apple` AND `app_status`
 * cases (the latter needs `appleWebLoginEnabledForChannel()` to decide
 * whether to advertise a web sign-in button at all) — living here keeps
 * every Apple-SIWA-specific decision in the ONE module (this file's
 * top-of-file docblock), rather than forking a second copy into a generic
 * settings/config include.
 *
 * DORMANCY: with `apple_siwa_services_id` unset (the shipped default), both
 * helpers below are inert — appleWebLoginEnabledForChannel() short-circuits
 * to false via its PURE core before ever inspecting the channel allow-list,
 * so `platform=web` 503s exactly like today's un-migrated-table gate.
 * ========================================================================== */

/**
 * Is Sign in with Apple for WEB enabled on the CURRENT deploy channel?
 * Requires BOTH:
 *   1. `apple_siwa_services_id` (manage/configuration.php) is a non-empty,
 *      admin-configured Services ID — the web `aud` ?action=auth_apple will
 *      check identity tokens against.
 *   2. The current channel (ihymns_environment(): 'alpha' | 'beta' |
 *      'production') is covered by the `apple_web_login_enabled`
 *      comma-separated allow-list setting (or that setting is the literal
 *      `all`).
 * All three docroots SHARE ONE DATABASE (rule #1233/#1352 precedent), so a
 * single global on/off flag would flip every channel at once the moment an
 * admin saves a Services ID — the channel allow-list is what lets the
 * owner soak web sign-in on `alpha` alone before widening it.
 *
 * Delegates entirely to the PURE _appleWebLoginEnabledCore() so
 * tests/php/test-apple-siwa-web.php can exercise the full allow-list truth
 * table without a database.
 *
 * @return bool
 */
function appleWebLoginEnabledForChannel(): bool
{
    $servicesId = function_exists('getAppSetting') ? (string)(getAppSetting('apple_siwa_services_id', '') ?? '') : '';
    $allowList  = function_exists('getAppSetting') ? (string)(getAppSetting('apple_web_login_enabled', '') ?? '') : '';
    $channel    = function_exists('ihymns_environment') ? ihymns_environment() : 'production';
    return _appleWebLoginEnabledCore($servicesId, $allowList, $channel);
}

/**
 * PURE core of appleWebLoginEnabledForChannel() — no DB, no superglobals, no
 * `ihymns_environment()` call; every input is an explicit parameter so the
 * full truth table (no services id / empty allow-list / channel not listed /
 * channel listed / `all` / mixed-case + whitespace tokens) is assertable
 * without any environment setup.
 *
 * @param string $servicesId The raw `apple_siwa_services_id` setting value.
 * @param string $allowList  The raw `apple_web_login_enabled` setting value —
 *                              a comma-separated list of channel tokens, or
 *                              the single literal `all`. Empty = disabled
 *                              everywhere (the shipped default).
 * @param string $channel    The CURRENT deploy channel (ihymns_environment()'s
 *                              return value: 'alpha' | 'beta' | 'production').
 * @return bool
 */
function _appleWebLoginEnabledCore(string $servicesId, string $allowList, string $channel): bool
{
    if (trim($servicesId) === '') {
        return false;
    }
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
 * The web redirect_uri for THIS request's docroot — 'https://' . $host .
 * APPLE_SIWA_WEB_RETURN_PATH, where $host is $_SERVER['HTTP_HOST'] validated
 * against OUR OWN domain first. Returns null (never a guess, never a
 * fallback host) when the Host fails validation, so the caller's code
 * exchange degrades to "skip the exchange" (best-effort — see
 * appleSiwaExchangeCode()'s docblock) rather than ever sending Apple a
 * redirect_uri built from untrusted input.
 *
 * SECURITY (CWE-640, Host-header injection): $_SERVER['HTTP_HOST'] is
 * attacker-controlled on the wire. The validation lives in the PURE
 * _appleSiwaWebHostAllowed() below so it is unit-testable without faking a
 * request; this wrapper is the only place that reads the superglobal.
 *
 * @return string|null
 */
function appleSiwaWebRedirectUri(): ?string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!_appleSiwaWebHostAllowed($host)) {
        return null;
    }
    return 'https://' . $host . APPLE_SIWA_WEB_RETURN_PATH;
}

/**
 * PURE host validator for appleSiwaWebRedirectUri(). Accepts exactly
 * `ihymns.app` or any subdomain of it (`*.ihymns.app`) — a SUFFIX check
 * rather than includes/config.php's appCanonicalHost() closed 4-entry list,
 * deliberately chosen here because iHymns owns the DNS for the whole
 * `ihymns.app` zone, so nobody else can ever stand up a subdomain that
 * matches this suffix; the closed list would otherwise need a code change
 * for every new docroot subdomain the owner adds. The companion charset
 * gate rejects anything that isn't a bare hostname (no scheme/port/path/
 * userinfo/CR-LF) BEFORE the suffix check runs, so a crafted Host header
 * like `evil.com` can never smuggle a `.ihymns.app`-ending suffix past a
 * naive `str_ends_with()` call via characters the charset gate excludes.
 *
 * @param string $host Raw candidate host (already lower/trim-normalised inside).
 * @return bool
 */
function _appleSiwaWebHostAllowed(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
        return false;
    }
    return $host === 'ihymns.app' || str_ends_with($host, '.ihymns.app');
}

/* =============================================================================
 * I/O SHELL — curl + DB (kept thin; all crypto decisions live in the pure
 * functions above so these are easy to eyeball for "does this call the right
 * pure function with the right inputs")
 * ========================================================================== */

/**
 * GET Apple's published JWKS. Hardcoded HTTPS origin, TLS verification ON,
 * no redirects followed — the transport IS the trust anchor (no cert pinning
 * beyond that, matching the deliberate no-pinning stance on shared hosting).
 * Validates response SHAPE before returning so a malformed/partial body can
 * never propagate into the cache (appleSiwaJwksCached never clobbers a good
 * cache with a bad fetch — the null return here is what lets it detect "bad").
 *
 * @return array{keys:array}|null Null on any transport/parse/shape failure.
 */
function appleSiwaFetchJwks(): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://appleid.apple.com/auth/keys');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_MAXREDIRS       => 0,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT       => 'iHymns-AppleSIWA/1.0',
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys']) || !$decoded['keys']) {
        return null;
    }
    foreach ($decoded['keys'] as $k) {
        if (!is_array($k) || ($k['kty'] ?? null) !== 'RSA'
            || empty($k['kid']) || empty($k['n']) || empty($k['e'])) {
            return null; /* Never partially trust a malformed body. */
        }
    }
    return $decoded;
}

/** Write the `apple_jwks_cache` row (same INSERT…ON DUPLICATE KEY UPDATE shape as
 *  manage/configuration.php's $saveSetting closure — see plan §2.3). Best-effort:
 *  a write failure here must never block sign-in, so it swallows \Throwable. */
function _appleSiwaJwksCacheWrite(\mysqli $db, array $cache): void
{
    $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }
    try {
        $key = 'apple_jwks_cache';
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        $stmt->bind_param('ss', $key, $encoded);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $_e) {
        /* Best-effort cache write — verification still works against the
           in-memory $cache/$fetched this request; only the NEXT request
           re-fetches instead of reading a persisted cache. */
    }
}

/**
 * Read-through JWKS cache in `tblAppSettings.apple_jwks_cache`. No APCu or
 * filesystem cache is dependable on shared DreamHost (three docroots, no
 * shared local disk guarantee), so the shared DB is the cache (plan §2.3):
 *
 *   1. Read the cached `{fetchedAt, keys}` row.
 *   2. Fresh (< 24h) AND contains $needKid → return the cache as-is.
 *   3. Stale, OR $needKid missing from a fresh cache (= Apple key rotation
 *      signal — Apple serves multiple concurrent keys and rotates
 *      unannounced) → refetch, but AT MOST ONCE PER 5 MINUTES globally
 *      (compares `fetchedAt`) — caps "attacker hammers us with made-up kids"
 *      DoS-Apple/DoS-ourselves. An unknown kid inside the 5-minute floor is
 *      just a plain miss (caller's verify step reports `unknown_kid` → 401),
 *      no fetch attempted.
 *   4. A bad/empty refetch NEVER clobbers a good cache's `keys` — only
 *      `fetchedAt` is bumped (still respecting the 5-min floor on the NEXT
 *      call) so a transient Apple outage degrades to "keep verifying against
 *      the last-known-good key set" rather than losing it.
 *
 * @param \mysqli     $db      Live connection (caller already existence-probed the tables — §1.3).
 * @param string|null $needKid The `kid` the current verification needs, or null to just want ANY fresh cache.
 * @return array{fetchedAt:int,keys:array}|null Null only when there is truly
 *               nothing usable (no cache ever written AND the fetch failed).
 */
function appleSiwaJwksCached(\mysqli $db, ?string $needKid): ?array
{
    $now   = time();
    $cache = null;

    $key = 'apple_jwks_cache';
    $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    if ($row !== null) {
        $decoded = json_decode((string)$row[0], true);
        if (is_array($decoded) && isset($decoded['fetchedAt'], $decoded['keys']) && is_array($decoded['keys'])) {
            $cache = $decoded;
        }
    }

    $fetchedAt  = $cache !== null ? (int)$cache['fetchedAt'] : 0;
    $ageSeconds = $now - $fetchedAt;
    $isFresh    = $cache !== null && $ageSeconds < 86400; /* < 24h */

    $hasNeededKid = false;
    if ($isFresh) {
        if ($needKid === null) {
            $hasNeededKid = true;
        } else {
            foreach ($cache['keys'] as $k) {
                if (is_array($k) && ($k['kid'] ?? null) === $needKid) {
                    $hasNeededKid = true;
                    break;
                }
            }
        }
    }
    if ($isFresh && $hasNeededKid) {
        return $cache;
    }

    /* Refetch floor: at most once per 5 minutes globally, whether triggered
       by staleness or a kid miss. */
    $canRefetch = ($cache === null) || ($ageSeconds >= 300);
    if (!$canRefetch) {
        return $cache; /* May be null, stale, or missing $needKid — caller's verify reports the exact reason. */
    }

    $fetched = appleSiwaFetchJwks();
    if ($fetched === null) {
        if ($cache !== null) {
            /* Bump fetchedAt only — keep serving the last-known-good keys (point 4 above). */
            $cache['fetchedAt'] = $now;
            _appleSiwaJwksCacheWrite($db, $cache);
            return $cache;
        }
        /* No prior cache at all — record a timestamped empty miss so the 5-min
           floor still applies on the next call (prevents fetch-hammering). */
        $empty = ['fetchedAt' => $now, 'keys' => []];
        _appleSiwaJwksCacheWrite($db, $empty);
        return null;
    }

    $newCache = ['fetchedAt' => $now, 'keys' => $fetched['keys']];
    _appleSiwaJwksCacheWrite($db, $newCache);
    return $newCache;
}

/**
 * Consume a sign-in nonce — the anti-replay gate. Opportunistically prunes
 * expired rows first (in-band; no cron on the shared host), then attempts an
 * atomic `INSERT` against `tblAuthNonces`' `UNIQUE (Purpose, NonceHash)` key.
 * A duplicate-key error (MySQL errno 1062) means this exact (purpose, nonce)
 * pair was already consumed — i.e. a REPLAY — and this call reports `false`.
 *
 * WHY consumption is a caller-driven, POST-verification step (not folded
 * into appleSiwaVerifyIdentityToken): consuming BEFORE full verification
 * would let an attacker burn a VICTIM's in-flight nonce by submitting a
 * garbage-signature token that merely carries the same nonce value — a
 * denial-of-service on the victim's real sign-in. Verify fully first, THEN
 * consume; only a token that already passed every other check gets to
 * "spend" the nonce.
 *
 * @param \mysqli $db        Live connection.
 * @param string  $purpose   `siwa_login` | `siwa_reauth` | … (VARCHAR vocabulary, rule #20).
 * @param string  $rawNonce  The RAW (unhashed) nonce — only its sha256 hash is ever stored.
 * @return bool True = consumed (first use, proceed). False = replay, reject.
 * @throws \mysqli_sql_exception Any DB error OTHER than a 1062 duplicate-key
 *               (mysqli runs under MYSQLI_REPORT_STRICT project-wide — a
 *               genuine failure must surface, not be silently swallowed).
 */
function appleSiwaConsumeNonce(\mysqli $db, string $purpose, string $rawNonce): bool
{
    try {
        /* Best-effort prune — a failure here must never block a legitimate sign-in. */
        $db->query('DELETE FROM tblAuthNonces WHERE ExpiresAt < UTC_TIMESTAMP() LIMIT 200');
    } catch (\Throwable $_e) {
        // no-op — pruning is opportunistic housekeeping, not correctness-critical
    }

    $nonceHash = hash('sha256', $rawNonce);
    /* 15 minutes — longer than any Apple identity-token exp window (#1066 TTL convention: DATETIME not TIMESTAMP). */
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 900);

    try {
        $stmt = $db->prepare('INSERT INTO tblAuthNonces (Purpose, NonceHash, ExpiresAt) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $purpose, $nonceHash, $expiresAt);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            return false; /* Duplicate key = already consumed = replay. */
        }
        throw $e;
    }
}

/**
 * PURE builder for appleSiwaExchangeCode()'s `application/x-www-form-urlencoded`
 * POST body — split out of that function (#1470 W1) so
 * tests/php/test-apple-siwa-web.php can assert the EXACT presence/absence of
 * `redirect_uri` WITHOUT a network call (appleSiwaExchangeCode() itself always
 * hits `https://appleid.apple.com/auth/token`, which CI must never do).
 *
 * Apple's `/auth/token` endpoint REJECTS the whole request (400
 * "invalid_request") if `redirect_uri` is PRESENT but empty — native's
 * ASAuthorization flow never had a redirect_uri to begin with, so the key is
 * OMITTED ENTIRELY (never sent as `redirect_uri=`) whenever $redirectUri is
 * null or ''. That keeps every existing (native) caller's body
 * BYTE-IDENTICAL to before this function existed.
 *
 * @param string      $code         The `authorizationCode` from the client's SIWA credential.
 * @param string      $clientSecret ES256 JWT from appleSiwaBuildClientSecret().
 * @param string      $clientId     IHYMNS_SIWA_CLIENT_ID (native) or the Services ID (web).
 * @param string|null $redirectUri  Web only — appleSiwaWebRedirectUri()'s result. Null/'' → omitted.
 * @return string application/x-www-form-urlencoded body.
 */
function _appleSiwaExchangeCodeBody(string $code, string $clientSecret, string $clientId, ?string $redirectUri): string
{
    $params = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ];
    if ($redirectUri !== null && $redirectUri !== '') {
        $params['redirect_uri'] = $redirectUri;
    }
    return http_build_query($params);
}

/**
 * Exchange an authorization code for Apple tokens (incl. a `refresh_token`)
 * at `POST /auth/token`. Only useful within ~5 minutes of the original
 * authorization (Apple's window) — called right after auth_apple's mapping
 * transaction commits, purely to capture a refresh token that account_delete
 * can later hand to appleSiwaRevokeToken(). Best-effort by design: every
 * failure here is non-fatal to sign-in (caller logs an outcome, never blocks).
 *
 * @param string      $code         The `authorizationCode` from the client's SIWA credential.
 * @param string      $clientSecret ES256 JWT from appleSiwaBuildClientSecret().
 * @param string      $clientId     IHYMNS_SIWA_CLIENT_ID (native) or the Services ID (web).
 * @param string|null $redirectUri  #1470 W1 — Apple REQUIRES `redirect_uri` on a web code
 *                                    exchange (native's ASAuthorization sends none, so this
 *                                    stays null for every native call). Only included in the
 *                                    outbound body when non-null/non-empty — see
 *                                    _appleSiwaExchangeCodeBody()'s docblock for why a
 *                                    present-but-empty value is never sent either.
 * @return array|null Decoded JSON body (contains `refresh_token`) on HTTP 200 with a token present, else null.
 */
function appleSiwaExchangeCode(string $code, string $clientSecret, string $clientId, ?string $redirectUri = null): ?array
{
    if ($code === '' || $clientSecret === '' || !function_exists('curl_init')) {
        return null;
    }

    $body = _appleSiwaExchangeCodeBody($code, $clientSecret, $clientId, $redirectUri);

    $decoded = _appleSiwaPostForm('https://appleid.apple.com/auth/token', $body);
    if (!is_array($decoded) || empty($decoded['refresh_token']) || !is_string($decoded['refresh_token'])) {
        return null;
    }
    return $decoded;
}

/**
 * Revoke a previously-issued Apple token at `POST /auth/revoke` — required by
 * App Review's account-deletion policy (a user who deletes their iHymns
 * account must also have their Sign-in-with-Apple grant revoked, not just
 * their local tblApiTokens). Best-effort: the caller (account_delete, §3.3A)
 * NEVER blocks deletion on this returning false — the user's right to delete
 * wins over a best-effort courtesy call to a third party.
 *
 * @param string $token         The refresh (or access) token to revoke.
 * @param string $tokenTypeHint `refresh_token` | `access_token`.
 * @param string $clientSecret  ES256 JWT from appleSiwaBuildClientSecret().
 * @param string $clientId      IHYMNS_SIWA_CLIENT_ID.
 * @return bool True only on a genuine HTTP 200 from Apple.
 */
function appleSiwaRevokeToken(string $token, string $tokenTypeHint, string $clientSecret, string $clientId): bool
{
    if ($token === '' || $clientSecret === '' || !function_exists('curl_init')) {
        return false;
    }

    $body = http_build_query([
        'token'           => $token,
        'token_type_hint' => $tokenTypeHint,
        'client_id'       => $clientId,
        'client_secret'   => $clientSecret,
    ]);

    return _appleSiwaPostForm('https://appleid.apple.com/auth/revoke', $body, true) !== null;
}

/**
 * Shared POST helper for the two Apple form-encoded endpoints above. Same
 * TLS/redirect/timeout posture as appleSiwaFetchJwks() and the
 * EmailService.php curl precedent (CURLOPT_TIMEOUT 15 / CONNECTTIMEOUT 5) —
 * the two callers here use a tighter 10s budget since they run inline in a
 * request the caller has already promised is "best-effort, non-blocking".
 *
 * @param string $url         Hardcoded https://appleid.apple.com/... endpoint.
 * @param string $formBody    application/x-www-form-urlencoded body.
 * @param bool   $successOnly When true, return `[]` (a truthy non-null sentinel)
 *                              instead of the decoded body on HTTP 200 with an
 *                              empty/non-JSON body — /auth/revoke replies 200
 *                              with an empty body on success.
 * @return array|null Decoded JSON body (or `[]` per $successOnly) on HTTP 200, else null.
 */
function _appleSiwaPostForm(string $url, string $formBody, bool $successOnly = false): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST             => true,
        CURLOPT_POSTFIELDS       => $formBody,
        CURLOPT_HTTPHEADER       => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_TIMEOUT          => 10,
        CURLOPT_CONNECTTIMEOUT   => 5,
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_SSL_VERIFYHOST   => 2,
        CURLOPT_FOLLOWLOCATION   => false,
        CURLOPT_MAXREDIRS        => 0,
        CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT        => 'iHymns-AppleSIWA/1.0',
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        return null;
    }
    if ($successOnly) {
        return [];
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
}
