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
 * this file's own checks are DB-free by construction — every assertion below
 * runs with no `\mysqli` connection at all. (⚠️ this paragraph previously
 * claimed "`.github/workflows/test.yml` runs NO MySQL service container" —
 * that went stale at #1708: CI has provisioned a real `mariadb:11` service
 * since then, see `test.yml`'s `lint`/`browser-smoke` jobs and
 * `tests/php/test-schema-installs.php`'s LIVE half. This file simply never
 * asked for it — see `tests/php/test-secret-crypto-rewrap.php` for the sibling
 * guard that DOES exercise `_secretAdminRewrapTableSecrets()` against a live
 * database when `IHYMNS_TEST_DSN` is set, and SKIPS LOUDLY otherwise, #1701.)
 * `secret_crypto_admin.php`'s `\mysqli`-taking functions — `secretInventory()`,
 * `secretSentinelStatus()`, `secretEncryptInPlace()`, `secretRotateReencrypt()`,
 * `secretKeyBeaconWrite()`, `secretKeyBeacons()`, `secretRotationParity()`,
 * `secretTableSecretInventory()`, `_secretAdminRewrapTableSecrets()`,
 * `_secretAdminTableExists()` — read/write `tblAppSettings`/
 * `tblWebhookSubscriptions` and are NOT exercised here; their behaviour is
 * covered by manual verification against the shared alpha/beta/production
 * database (see `.claude/secret-encryption-strategy.md` §7/§8/§10b) plus, for
 * the #1989 table-secret rewrap specifically, `test-secret-crypto-rewrap.php`'s
 * live-DB half. What IS asserted below, and CAN be checked without a database:
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
    /* #1989 — table-held secret rewrap (tblWebhookSubscriptions.Secret/
       SecretPrevious); the PURE decision + registry get their own exhaustive
       truth table in test-secret-crypto-rewrap.php, this is just the
       rename/removal tripwire the rest of this list already is. */
    'secretTableSecretColumns',
    'secretTableRewrapDecision',
    'secretTableSecretInventory',
    '_secretAdminRewrapTableSecrets',
    '_secretAdminTableExists',
];
/* Two of the #1989 additions are PURE (no \mysqli param at all) — their
   behaviour IS exercised, exhaustively, by test-secret-crypto-rewrap.php's
   truth table, so labelling them "DB-taking; behaviour untested" here would
   overclaim. Everything else in the list above genuinely takes \mysqli and
   is existence-checked only, as the label says. */
$pureFunctions = ['secretTableSecretColumns', 'secretTableRewrapDecision'];
foreach ($expectedFunctions as $fn) {
    $label = in_array($fn, $pureFunctions, true)
        ? "function {$fn}() is defined (pure/DB-free — behaviour truth-tabled in test-secret-crypto-rewrap.php)"
        : "function {$fn}() is defined (DB-taking; behaviour untested here)";
    _scaAssert(function_exists($fn), $label);
}

/* ----------------------------------------------------------------------- */
echo "\n";
echo "secret-crypto-admin: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
