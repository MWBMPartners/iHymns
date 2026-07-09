<?php

/**
 * iHymns — Secret encryption-at-rest ADMIN / MIGRATION layer, DB-FREE surface
 * (#1466 review finding 8)
 *
 * ELI5: `test-secret-crypto.php` already proves the lock-and-key machine
 * itself works. This file proves the small DB-FREE part of the TOOLBOX built
 * on top of it (`includes/secret_crypto_admin.php`) still has the constants
 * and helper it's supposed to, and hasn't quietly renamed or dropped one of
 * its public functions.
 *
 * SCOPE / WHAT THIS DOES NOT COVER (read this before assuming full coverage):
 * `.github/workflows/test.yml` runs NO MySQL service container — every test
 * step here is DB-free by construction. `secret_crypto_admin.php`'s
 * `\mysqli`-taking functions — `secretInventory()`, `secretSentinelStatus()`,
 * `secretEncryptInPlace()`, `secretRotateReencrypt()`, `secretKeyBeaconWrite()`,
 * `secretKeyBeacons()`, `secretRotationParity()` — read/write `tblAppSettings`
 * and therefore CANNOT be exercised in CI. Their behaviour is covered by
 * manual verification against the shared alpha/beta/production database (see
 * `.claude/secret-encryption-strategy.md` §7/§8/§10b), NOT by this test. What
 * IS asserted below, and CAN be checked without a database:
 *   - the six module constants keep their exact documented values (a typo'd
 *     literal would silently desync the admin panel / migration cards from
 *     the settings rows they read/write by name)
 *   - `_secretAdminEnvelopeKeyid()` — the envelope-keyid parser rotation uses
 *     to decide "already under the active key?" — reads a REAL envelope
 *     produced by the P1 engine, a hand-built envelope string, and rejects
 *     malformed input, all without touching a DB
 *   - `_secretCryptoParseEnvelope()` (the ONE shared envelope parser in
 *     `secret_crypto.php` that `_secretAdminEnvelopeKeyid()` now delegates to,
 *     per the #1466 review de-duplication) keeps its documented 4-field
 *     contract
 *   - every public admin-layer function name still exists (`function_exists`),
 *     so a rename/removal is caught here even though its BEHAVIOUR can't be
 *     exercised without a live `\mysqli`
 *
 *   php tests/php/test-secret-crypto-admin.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/secret_crypto_admin.php';

$failures = 0;
$passed = 0;

function _scaAssert(bool $cond, string $label): void
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

/* ---- 1. Constants keep their exact documented values ------------------- */
_scaAssert(SECRET_ENC_ACTIVE_KEY === 'secret_encryption_active',
    'SECRET_ENC_ACTIVE_KEY === "secret_encryption_active"');
_scaAssert(SECRET_SENTINEL_KEY === 'secret_encryption_sentinel',
    'SECRET_SENTINEL_KEY === "secret_encryption_sentinel"');
_scaAssert(SECRET_SENTINEL_PLAINTEXT === 'ihymns-secret-encryption-sentinel-v1',
    'SECRET_SENTINEL_PLAINTEXT === "ihymns-secret-encryption-sentinel-v1"');
_scaAssert(SECRET_KEY_BEACON_PREFIX === 'secret_key_beacon_',
    'SECRET_KEY_BEACON_PREFIX === "secret_key_beacon_"');
_scaAssert(SECRET_KEY_BEACON_ENVS === ['alpha', 'beta', 'production'],
    'SECRET_KEY_BEACON_ENVS === [\'alpha\', \'beta\', \'production\']');
_scaAssert(SECRET_KEY_BEACON_FRESH_SECONDS === 86400,
    'SECRET_KEY_BEACON_FRESH_SECONDS === 86400 (24h)');

/* ---- 2. _secretAdminEnvelopeKeyid() ------------------------------------ */

/* A REAL envelope built via the P1 engine's test-injection hook (no DB
   involved — secretCryptoInjectKeyset() bypasses the .auth/ file read). */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('ab', 32)]]);
$env = secretEncrypt('x');
_scaAssert(_secretAdminEnvelopeKeyid($env) === 'k1',
    '_secretAdminEnvelopeKeyid() reads the keyid out of a real engine-produced envelope');

/* A hand-built envelope string — direct parse, no encrypt/decrypt involved. */
_scaAssert(_secretAdminEnvelopeKeyid('enc:v1:sb:kXYZ:AAAA:BBBB') === 'kXYZ',
    '_secretAdminEnvelopeKeyid() parses a hand-built enc:v1 envelope string');

/* Non-envelope / malformed input → null (never guesses, never throws). */
_scaAssert(_secretAdminEnvelopeKeyid('legacy-plaintext') === null,
    '_secretAdminEnvelopeKeyid() returns null for legacy plaintext (no enc: prefix)');
_scaAssert(_secretAdminEnvelopeKeyid('enc:v1:onlytwo') === null,
    '_secretAdminEnvelopeKeyid() returns null for a malformed (too-few-field) envelope');

/* ---- 3. _secretCryptoParseEnvelope() — the shared engine parser -------- */
_scaAssert(_secretCryptoParseEnvelope('enc:v1:sb:k1:AAAA:BBBB') === ['sb', 'k1', 'AAAA', 'BBBB'],
    '_secretCryptoParseEnvelope() splits a well-formed envelope into [alg, keyid, nonce, ciphertext]');
_scaAssert(_secretCryptoParseEnvelope('legacy') === null,
    '_secretCryptoParseEnvelope() returns null for a non-enc: value');
_scaAssert(_secretCryptoParseEnvelope('enc:v1:sb:k1:onlythree') === null,
    '_secretCryptoParseEnvelope() returns null for a too-few-fields envelope');

/* ---- 4. Function existence — catches a silent rename/removal ----------- */
$expectedFunctions = [
    'secretEncryptionActive',
    'secretSentinelEnsure',
    'secretSentinelStatus',
    'secretInventory',
    'secretEncryptInPlace',
    'secretRotateReencrypt',
    'secretKeyBeaconWrite',
    'secretKeyBeacons',
    'secretRotationParity',
    'secretDbFilePrivilegeStatus',
];
foreach ($expectedFunctions as $fn) {
    _scaAssert(function_exists($fn), "function {$fn}() is defined (DB-taking; behaviour untested here)");
}

/* ----------------------------------------------------------------------- */
echo "\n";
echo "secret-crypto-admin: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
