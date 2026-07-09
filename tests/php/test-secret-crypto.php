<?php

/**
 * iHymns — Secret encryption-at-rest engine round-trip guard (#1466)
 *
 * Exercises includes/secret_crypto.php WITHOUT touching the real
 * appWeb/.auth/secrets_master_key.php — it injects test keysets via the
 * secretCryptoInjectKeyset() hook. Asserts the load-bearing crypto
 * properties so a regression in the envelope/format/fail-safe behaviour is
 * caught at CI time, before it can corrupt or expose a real secret:
 *
 *   - encrypt → enc:v1 envelope → decrypt round-trips (default cipher)
 *   - the OpenSSL AES-256-GCM decrypt branch round-trips (crafted envelope)
 *   - legacy plaintext (no `enc:` prefix) passes through UNCHANGED (the
 *     migration bridge) and null → null
 *   - a tampered ciphertext decrypts to null (AEAD authentication), never a
 *     wrong plaintext
 *   - a value encrypted under a keyid absent from the current keyset → null
 *     (fail-safe "unset", the per-docroot key-mismatch case)
 *   - rotation overlap: a value encrypted under an OLD keyid still decrypts
 *     while that keyid is retained, even after `active` moves to a new key
 *   - no-key state: ready() false, encrypt() throws, plaintext still passes
 *     through, envelope → null
 *   - fingerprint is non-secret, stable, per-key, and never leaks the key
 *   - empty + large multi-line values (the Gmail SA JSON shape) round-trip
 *
 *   php tests/php/test-secret-crypto.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/secret_crypto.php';

$failures = 0;
$passed = 0;

function _scAssert(bool $cond, string $label): void
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

/* Two deterministic 32-byte test keys (64 hex chars each). */
$K1 = str_repeat('a1', 32);
$K2 = str_repeat('b2', 32);

/* ---- 1. Round-trip under the default (preferred) cipher ---------------- */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => $K1]]);
_scAssert(secretCryptoReady() === true, 'ready() true when a key is loaded');

$plain = 'super-secret-smtp-password!£€\n"quoted"';
$env = secretEncrypt($plain);
_scAssert(str_starts_with($env, 'enc:v1:'), 'encrypt() emits an enc:v1 envelope');
_scAssert(secretIsEncrypted($env) === true, 'secretIsEncrypted() true for an envelope');
_scAssert(secretDecrypt($env) === $plain, 'round-trip decrypt equals the original');

/* envelope shape: enc:v1:<alg>:<keyid>:<nonce>:<ct> — 6 colon fields */
$fields = explode(':', $env);
_scAssert(count($fields) === 6, 'envelope has 6 colon-separated fields');
_scAssert($fields[0] === 'enc' && $fields[1] === 'v1', 'envelope prefix is enc:v1');
_scAssert(in_array($fields[2], ['sb', 'gcm'], true), 'envelope names a known algorithm');
_scAssert($fields[3] === 'k1', 'envelope records the active keyid');

/* ---- 2. Legacy plaintext passthrough + null --------------------------- */
_scAssert(secretDecrypt('legacy-plaintext-value') === 'legacy-plaintext-value',
    'legacy plaintext (no enc: prefix) passes through unchanged');
_scAssert(secretDecrypt(null) === null, 'null decrypts to null');
_scAssert(secretIsEncrypted('legacy-plaintext-value') === false,
    'secretIsEncrypted() false for legacy plaintext');
_scAssert(secretIsEncrypted(null) === false, 'secretIsEncrypted() false for null');

/* ---- 3. Empty + large multi-line values round-trip -------------------- */
_scAssert(secretDecrypt(secretEncrypt('')) === '', 'empty string round-trips');
$bigJson = json_encode(['type' => 'service_account', 'key' => str_repeat("line\n", 500)]);
_scAssert(secretDecrypt(secretEncrypt($bigJson)) === $bigJson,
    'large multi-line JSON (SA-key shape) round-trips');

/* ---- 4. Tamper detection (AEAD) --------------------------------------- */
$env2 = secretEncrypt('tamper-me');
/* Corrupt the FIRST base64url char of the ciphertext field (not the last).
   Flipping the LAST char is flaky: base64url's final char often encodes only
   the low 2/4 bits of the last byte, so a single-char change there can decode
   to the SAME bytes (~25% of the time) and the AEAD tag still verifies. The
   first ciphertext char always maps to the high 6 bits of ciphertext byte 0,
   so changing it to a different base64url char is GUARANTEED to alter a real
   byte → deterministic AEAD failure → null. */
$ctStart  = strrpos($env2, ':') + 1;                 // first char of the base64url ciphertext field
$repl     = $env2[$ctStart] === 'A' ? 'B' : 'A';     // always a different base64url char
$tampered = substr($env2, 0, $ctStart) . $repl . substr($env2, $ctStart + 1);
_scAssert($tampered !== $env2 && secretDecrypt($tampered) === null,
    'a tampered ciphertext decrypts to null (never a wrong plaintext)');
/* Garbage envelope / wrong field count → null. */
_scAssert(secretDecrypt('enc:v1:sb:k1:onlythree') === null,
    'malformed envelope (too few fields) → null');
_scAssert(secretDecrypt('enc:v1:xx:k1:AAAA:AAAA') === null,
    'unknown algorithm → null');

/* ---- 5. GCM decrypt branch (crafted envelope, exercised even when the
 *         default cipher is libsodium) --------------------------------- */
$rawKey = hex2bin($K1);
$nonce = random_bytes(12);
$tag = '';
$gcmCt = openssl_encrypt('hello-gcm', 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
$gcmEnv = 'enc:v1:gcm:k1:' . _secretCryptoB64uEncode($nonce) . ':' . _secretCryptoB64uEncode($gcmCt . $tag);
_scAssert(secretDecrypt($gcmEnv) === 'hello-gcm', 'AES-256-GCM envelope decrypts correctly');
/* A GCM envelope with a corrupted tag → null. */
$badGcm = 'enc:v1:gcm:k1:' . _secretCryptoB64uEncode($nonce) . ':' . _secretCryptoB64uEncode($gcmCt . strrev($tag));
_scAssert(secretDecrypt($badGcm) === null, 'AES-256-GCM with a bad tag → null');

/* ---- 6. Key mismatch: keyid absent → null (fail-safe) ----------------- */
$envUnderK1 = secretEncrypt('only-k1-can-read');
secretCryptoInjectKeyset(['active' => 'k2', 'keys' => ['k2' => $K2]]); // k1 gone
_scAssert(secretDecrypt($envUnderK1) === null,
    'value encrypted under an absent keyid → null (per-docroot mismatch case)');

/* ---- 7. Rotation overlap: old keyid retained still decrypts ------------ */
secretCryptoInjectKeyset(['active' => 'k2', 'keys' => ['k1' => $K1, 'k2' => $K2]]);
_scAssert(secretDecrypt($envUnderK1) === 'only-k1-can-read',
    'rotation overlap: old-keyid ciphertext decrypts while the old key is retained');
$envUnderK2 = secretEncrypt('new-key-value');
_scAssert(explode(':', $envUnderK2)[3] === 'k2', 'new encryptions use the active (rotated) keyid');
_scAssert(secretDecrypt($envUnderK2) === 'new-key-value', 'new-keyid value round-trips');

/* ---- 8. Fingerprint: non-secret, stable, per-key ---------------------- */
$fpK1 = secretKeyFingerprint('k1');
$fpK2 = secretKeyFingerprint('k2');
_scAssert(is_string($fpK1) && preg_match('/^[0-9a-f]{12}$/', $fpK1) === 1,
    'fingerprint is 12 hex chars');
_scAssert($fpK1 !== $fpK2, 'fingerprints differ per key');
_scAssert($fpK1 === secretKeyFingerprint('k1'), 'fingerprint is stable');
_scAssert(strpos($K1, $fpK1) === false, 'fingerprint is not a substring of the key (no leak)');
_scAssert(secretActiveKeyId() === 'k2', 'active keyid reported correctly');
_scAssert(secretAllKeyIds() === ['k1', 'k2'] || secretAllKeyIds() === ['k2', 'k1'],
    'all keyids reported');

/* ---- 9. Malformed keyset → treated as no key -------------------------- */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => 'too-short']]);
_scAssert(secretCryptoReady() === false, 'a bad-length key is rejected → not ready');

/* ---- 9b. Envelope-unsafe keyid (contains ':') is rejected ------------- */
/* A keyid with a ':' would mis-split the enc:v1:<alg>:<keyid>:… envelope and
   yield permanently undecryptable ciphertext, so the keyset normaliser drops it
   (#1466 review). With no other key present, the engine reports not-ready. */
secretCryptoInjectKeyset(['active' => 'k1:2026', 'keys' => ['k1:2026' => $K1]]);
_scAssert(secretCryptoReady() === false, 'a keyid containing ":" is rejected (envelope-unsafe)');
/* A valid sibling key survives while the unsafe keyid is dropped. */
secretCryptoInjectKeyset(['active' => 'good1', 'keys' => ['bad:id' => $K1, 'good1' => $K2]]);
_scAssert(secretAllKeyIds() === ['good1'], 'only the envelope-safe keyid survives normalisation');

/* ---- 9c. Placeholder / low-entropy keys are rejected (#1466 finding 1) -- */
/* A 64-hex value that is a single nibble repeated 64× (all-zeros, all-f, …) is
   "the right shape" but a WORLD-KNOWN placeholder — the tracked example file
   ships k1 = 0000…0000. The normaliser drops it, so a mis-provisioned placeholder
   degrades to "no key" (dormant) rather than encrypting under a guessable key. */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('0', 64)]]);
_scAssert(secretCryptoReady() === false, 'all-zeros placeholder key is rejected → not ready');
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('f', 64)]]);
_scAssert(secretCryptoReady() === false, 'all-f placeholder key is rejected → not ready');
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('A', 64)]]);
_scAssert(secretCryptoReady() === false, 'all-same-nibble (uppercase) placeholder key is rejected → not ready');
/* A real key survives while the placeholder sibling is dropped. */
secretCryptoInjectKeyset(['active' => 'k1', 'keys' => ['k1' => str_repeat('0', 64), 'k2' => $K2]]);
_scAssert(secretAllKeyIds() === ['k2'], 'only the real key survives; the placeholder is dropped');

/* ---- 10. No-key state ------------------------------------------------- */
secretCryptoInjectKeyset(null);
_scAssert(secretCryptoReady() === false, 'ready() false with no key');
_scAssert(secretKeyFingerprint() === null, 'fingerprint null with no key');
_scAssert(secretDecrypt('legacy-plaintext') === 'legacy-plaintext',
    'legacy plaintext still passes through with no key (migration-safe)');
_scAssert(secretDecrypt($envUnderK1) === null, 'envelope → null with no key (fail-safe dormant)');
$threw = false;
try {
    secretEncrypt('x');
} catch (\RuntimeException $e) {
    $threw = true;
}
_scAssert($threw === true, 'encrypt() throws with no key (never silently stores plaintext)');

/* ---- 11. Registry sanity + drift guard -------------------------------- */
$registry = secretSettingKeys();
_scAssert(in_array('apple_siwa_private_key', $registry, true),
    'SIWA private key is in the secret-settings registry');
_scAssert(isSecretSettingKey('email_smtp_pass') && !isSecretSettingKey('email_smtp_host'),
    'isSecretSettingKey() distinguishes secret from non-secret keys');

/* Every secret-flagged (password/textarea + true) field in configuration.php's
   settings-definition array MUST be in secretSettingKeys(), or it would be
   saved as plaintext. Fails loudly if a new email/API secret is added there
   without being registered for encryption. */
$configFile = dirname(__DIR__, 2) . '/appWeb/public_html/manage/configuration.php';
$configSrc = is_readable($configFile) ? (string)file_get_contents($configFile) : '';
_scAssert($configSrc !== '', 'configuration.php is readable for the drift scan');
$missing = [];
if (preg_match_all(
    "/'([a-z0-9_]+)'\\s*=>\\s*\\[[^\\]]*'(?:password|textarea)'\\s*,\\s*true\\b/i",
    $configSrc,
    $m
)) {
    foreach (array_unique($m[1]) as $cfgKey) {
        if (!in_array($cfgKey, $registry, true)) {
            $missing[] = $cfgKey;
        }
    }
}
_scAssert(
    $missing === [],
    'every secret-flagged config field is registered for encryption'
        . ($missing !== [] ? ' — MISSING from secretSettingKeys(): ' . implode(', ', $missing) : '')
);

/* ----------------------------------------------------------------------- */
echo "\n";
echo "secret-crypto: {$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
