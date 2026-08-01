<?php

declare(strict_types=1);

/**
 * iHymns — Secret encryption-at-rest engine (#1466)
 * ============================================================================
 * ELI5: The app keeps some real secrets in the database (email passwords/API
 * keys, the Sign-in-with-Apple .p8). This file locks each of those with a
 * master key that lives in a file OUTSIDE the website (appWeb/.auth/), so a
 * stolen database dump is just gibberish without that key file.
 *
 * DETAILED / WHY:
 * The three iHymns docroots (dev/beta/prod) share ONE MySQL database, and that
 * DB — plus every backup of it — historically held these secrets in PLAINTEXT.
 * A dump, a SQL-injection in any (possibly stale) docroot, or a leaked backup
 * exposed them all. This engine encrypts each flagged secret at rest so
 * disclosure now needs TWO primitives: a DB read AND a filesystem read of the
 * master key (`appWeb/.auth/secrets_master_key.php`, a sibling of the docroot,
 * never web-served, never in git). It does NOT protect a compromised runtime
 * (RCE on any docroot reads both) — see `.claude/secret-encryption-strategy.md`
 * §2 for the honest threat model.
 *
 * STORAGE ENVELOPE (self-describing so decryption is always deterministic):
 *   enc:v1:<alg>:<keyid>:<base64url nonce>:<base64url ciphertext+tag>
 *     v1     — envelope version
 *     <alg>  — 'sb' = libsodium crypto_secretbox (preferred, AEAD)
 *              'gcm' = OpenSSL AES-256-GCM (fallback)
 *     <keyid>— which master key encrypted it (enables overlapped rotation)
 *     nonce  — per-value random (24 bytes for sb, 12 for gcm)
 * A value WITHOUT the `enc:` prefix is treated as LEGACY PLAINTEXT and returned
 * unchanged — this is the migration bridge: readers ship first and keep working
 * against un-encrypted values until the one-shot encrypt-in-place card runs.
 *
 * Refs: libsodium secretbox https://doc.libsodium.org/secret-key_cryptography/secretbox
 *       PHP sodium https://www.php.net/manual/en/book.sodium.php
 *       AES-GCM https://www.php.net/manual/en/function.openssl-encrypt.php
 *
 * ONE engine, no forking (repo modularity rule). Readers call secretDecrypt();
 * the config save path calls secretEncrypt(); the migration/rotation card and
 * the /manage health panel use the fingerprint + status helpers.
 */

/* =========================================================================
 * DIRECT ACCESS PREVENTION — included only, never hit directly.
 * ========================================================================= */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Envelope marker. strncmp against this is how we tell an encrypted value from
   a legacy plaintext one. Bumping the version means adding a v2 branch in
   secretDecrypt() while keeping the v1 branch for already-stored data. */
const SECRET_ENC_PREFIX = 'enc:v1:';

/* =========================================================================
 * MASTER KEY LOADING (memoized module state)
 * ========================================================================= */

/**
 * Absolute path to the master key file. Mirrors db_mysql.php's resolution
 * (dirname(__DIR__, 2) → appWeb/) so the key sits in the SAME appWeb/.auth/
 * the operator provisioned + the deploy seeds. Sibling of public_html/, so it
 * is outside everything Apache serves.
 */
function secretCryptoKeyFilePath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth'
        . DIRECTORY_SEPARATOR . 'secrets_master_key.php';
}

/**
 * Private, by-reference module state so both the file loader and the test
 * injector write to the SAME memoized cache. Not part of the public API.
 * @return array{loaded:bool,keyset:?array}
 * @internal
 */
function &_secretCryptoState(): array
{
    static $state = ['loaded' => false, 'keyset' => null];
    return $state;
}

/**
 * Validate + normalise a raw keyset array into ['active'=>keyid,'keys'=>[keyid=>hex]].
 * Drops any key that isn't exactly 64 hex chars (= 32 bytes). Returns null if
 * nothing usable survives, so a malformed file degrades to "no key" (dormant),
 * never to a wrong-length key that would throw inside the cipher.
 * @internal
 */
function _secretCryptoNormalise(mixed $raw): ?array
{
    if (!is_array($raw) || !isset($raw['keys']) || !is_array($raw['keys'])) {
        return null;
    }
    $keys = [];
    foreach ($raw['keys'] as $keyid => $hex) {
        /* Note: a purely-numeric keyid (e.g. '2026') is cast to int by PHP array
           semantics, so is_string() rejects it — keep keyids non-numeric like the
           documented 'k1'/'k2' convention. */
        if (!is_string($keyid) || $keyid === '' || !is_string($hex)) {
            continue;
        }
        /* Keyid must be ENVELOPE-SAFE: it is stored verbatim in the
           enc:v1:<alg>:<keyid>:<nonce>:<ct> envelope and recovered by a colon
           split (explode(':', …, 4) in secretDecrypt), so it MUST contain no ':'.
           Restrict to a sane alnum/-/_ set (≤64 chars). A keyid like 'k1:2026'
           would otherwise encrypt fine but produce PERMANENTLY UNDECRYPTABLE
           ciphertext, because the parser would mis-split the envelope (#1466 review). */
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $keyid) !== 1) {
            continue;
        }
        /* Exactly 32 bytes, hex — the length both AES-256 and secretbox need. */
        if (preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            continue;
        }
        /* Reject obvious PLACEHOLDER / low-entropy keys: any single hex nibble
           repeated 64× (all-zeros 0000…, all-ones 1111…, all-f ffff…, etc.).
           The tracked example file ships k1 = 0000…0000, and such a value is
           "the right shape", so it would otherwise pass secretCryptoReady() AND
           the cross-docroot fingerprint gate (which proves key SAMENESS, never
           SECRECY) — silently encrypting every secret under a WORLD-KNOWN key
           with a green health panel. Skipping it degrades to "no key" (dormant),
           a loud fail-safe. A real CSPRNG key collides with this pattern with
           probability 16·16⁻⁶⁴, i.e. never. (#1466 third-pass review, finding 1.) */
        if (preg_match('/^([0-9a-fA-F])\1{63}$/', $hex) === 1) {
            continue;
        }
        $keys[$keyid] = strtolower($hex);
    }
    if ($keys === []) {
        return null;
    }
    $active = $raw['active'] ?? null;
    /* If 'active' is missing or points at a dropped/absent key, fall back to the
       last defined key so encryption still has a deterministic target rather
       than throwing. Decryption never depends on 'active' (it reads the keyid
       out of the envelope), so this only affects NEW writes. */
    if (!is_string($active) || !isset($keys[$active])) {
        $active = array_key_last($keys);
    }
    return ['active' => $active, 'keys' => $keys];
}

/**
 * Load + memoise the keyset from the .auth/ file. Returns null on any problem
 * (missing file, unreadable, malformed) so callers degrade to "no key".
 * @internal
 */
function _secretCryptoLoadFromFile(): ?array
{
    $file = secretCryptoKeyFilePath();
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }
    try {
        /* The file `return`s its array. Wrapped in try/catch for any runtime
           Throwable; a hard parse error in the file is the same fatal-risk
           db_credentials.php already carries and is accepted for these
           hand-placed .auth/ files. */
        $raw = require $file;
    } catch (\Throwable $e) {
        return null;
    }
    return _secretCryptoNormalise($raw);
}

/**
 * The active, validated keyset, or null if no usable key is available.
 * @return array{active:string,keys:array<string,string>}|null
 */
function secretCryptoKeyset(): ?array
{
    $state = &_secretCryptoState();
    if (!$state['loaded']) {
        $state['loaded'] = true;
        $state['keyset'] = _secretCryptoLoadFromFile();
    }
    return $state['keyset'];
}

/**
 * TEST-ONLY hook: inject a keyset (or null to force "no key") and skip the file
 * read. Used by tests/php/test-secret-crypto.php. Never call from app code.
 * @internal
 */
function secretCryptoInjectKeyset(?array $rawKeyset): void
{
    $state = &_secretCryptoState();
    $state['loaded'] = true;
    $state['keyset'] = $rawKeyset === null ? null : _secretCryptoNormalise($rawKeyset);
}

/** Raw 32-byte binary key for a keyid, or null. @internal */
function _secretCryptoRawKey(string $keyid): ?string
{
    $ks = secretCryptoKeyset();
    if ($ks === null || !isset($ks['keys'][$keyid])) {
        return null;
    }
    $bin = hex2bin($ks['keys'][$keyid]);
    return $bin === false ? null : $bin;
}

/** Preferred algorithm for NEW encryptions: libsodium if present, else GCM. */
function secretCryptoAlgorithm(): string
{
    return function_exists('sodium_crypto_secretbox') ? 'sb' : 'gcm';
}

/* =========================================================================
 * PUBLIC API — encrypt / decrypt / status
 * ========================================================================= */

/** True when a usable master key is loaded (feature can operate). */
function secretCryptoReady(): bool
{
    return secretCryptoKeyset() !== null;
}

/** Does a stored value already carry the encryption envelope? */
function secretIsEncrypted(?string $stored): bool
{
    return is_string($stored)
        && strncmp($stored, SECRET_ENC_PREFIX, strlen(SECRET_ENC_PREFIX)) === 0;
}

/**
 * Encrypt a plaintext into an enc:v1 envelope using the ACTIVE key.
 * @throws RuntimeException if no key is available or the cipher fails — callers
 *         on the WRITE path must surface this (never silently store plaintext
 *         when encryption was expected).
 */
function secretEncrypt(string $plaintext): string
{
    $ks = secretCryptoKeyset();
    if ($ks === null) {
        throw new \RuntimeException('secret_crypto: no master key available for encryption');
    }
    $keyid = $ks['active'];
    $key = _secretCryptoRawKey($keyid);
    if ($key === null) {
        throw new \RuntimeException('secret_crypto: active master key is invalid');
    }
    $alg = secretCryptoAlgorithm();
    if ($alg === 'sb') {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);   // 24 bytes
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key); // MAC included (AEAD)
    } else {
        $nonce = random_bytes(12);                                   // GCM standard IV
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',   // no AAD
            16    // 128-bit tag
        );
        if ($cipher === false) {
            throw new \RuntimeException('secret_crypto: openssl AES-256-GCM encryption failed');
        }
        $cipher .= $tag; // append the 16-byte auth tag for storage
    }
    return SECRET_ENC_PREFIX . $alg . ':' . $keyid . ':'
        . _secretCryptoB64uEncode($nonce) . ':' . _secretCryptoB64uEncode($cipher);
}

/**
 * Parse an `enc:v1:<alg>:<keyid>:<nonce>:<cipher>` envelope into its four
 * colon-separated fields, or null if `$stored` isn't one at all.
 * @return array{0:string,1:string,2:string,3:string}|null [alg, keyid, nonce_b64, cipher_b64]
 * @internal
 *
 * ELI5: this is the ONE place that reads the label on a locked box — "what
 * kind of lock, which key, what's the random bit, what's the locked
 * contents" — without trying to open it. Two other spots in this codebase
 * used to peel that label off by hand with their own copy-pasted
 * `strncmp`/`substr`/`explode`; now they both call here instead.
 * WHY (#1466 review, modularity rule): `secretDecrypt()` below and
 * `_secretAdminEnvelopeKeyid()` in `secret_crypto_admin.php` (rotation's
 * "which key is this value currently under?" check) each carried their own
 * copy of this exact parse. Two copies of "how do I split an envelope" is
 * precisely the class of duplication the repo's modularity rule bans — and
 * it's a bad one to fork, because a future v2 envelope change would have to
 * remember to update both, and a silent drift between them would show up as
 * decrypt succeeding in one code path and mis-reading the keyid in the
 * other. Extracting one parser means a v2 envelope format only needs
 * teaching here. Behavior is unchanged: same null-return on a non-`enc:`
 * value or on a split that doesn't yield exactly 4 fields.
 */
function _secretCryptoParseEnvelope(string $stored): ?array
{
    if (strncmp($stored, SECRET_ENC_PREFIX, strlen(SECRET_ENC_PREFIX)) !== 0) {
        return null;
    }
    $rest = substr($stored, strlen(SECRET_ENC_PREFIX));
    $parts = explode(':', $rest, 4);
    if (count($parts) !== 4) {
        return null;
    }
    return $parts; // [alg, keyid, nonce_b64, cipher_b64]
}

/**
 * Decrypt a stored value.
 *   - null in  → null out.
 *   - legacy plaintext (no `enc:` prefix) → returned UNCHANGED (migration bridge).
 *   - enc:v1 envelope → decrypted plaintext, or null on ANY failure (missing
 *     key, wrong keyid, tampering, unsupported alg). Null = fail-safe "unset",
 *     so a key mismatch degrades to a dormant feature, never a crash or a
 *     wrong-plaintext.
 */
function secretDecrypt(?string $stored): ?string
{
    if ($stored === null) {
        return null;
    }
    if (!secretIsEncrypted($stored)) {
        return $stored; // legacy plaintext passthrough
    }
    /* Envelope parsing itself now lives in ONE shared helper (see
       _secretCryptoParseEnvelope() above) — base64url never contains ':',
       and keyids are alnum, so its limit-4 split cleanly yields
       [alg, keyid, nonce, ciphertext]. */
    $parts = _secretCryptoParseEnvelope($stored);
    if ($parts === null) {
        return null;
    }
    [$alg, $keyid, $nonceB64, $cipherB64] = $parts;

    $key = _secretCryptoRawKey($keyid);
    if ($key === null) {
        return null; // key for this keyid not present on this docroot → dormant
    }
    $nonce = _secretCryptoB64uDecode($nonceB64);
    $cipher = _secretCryptoB64uDecode($cipherB64);
    if ($nonce === false || $cipher === false) {
        return null;
    }

    try {
        if ($alg === 'sb') {
            if (!function_exists('sodium_crypto_secretbox_open')
                || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? null : $plain;
        }
        if ($alg === 'gcm') {
            /* Last 16 bytes are the GCM tag we appended at encrypt time. */
            if (strlen($cipher) < 16) {
                return null;
            }
            $tag = substr($cipher, -16);
            $body = substr($cipher, 0, -16);
            $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            return $plain === false ? null : $plain;
        }
    } catch (\Throwable $e) {
        return null;
    }
    return null; // unknown algorithm
}

/* =========================================================================
 * FINGERPRINT + STATUS (for the /manage health panel + rotation gate)
 * ========================================================================= */

/**
 * A NON-SECRET fingerprint of a key, for the per-docroot consistency check:
 * the operator confirms all three docroots show the SAME fingerprint before the
 * destructive encrypt/rotate card runs. Domain-separated + truncated so it
 * cannot be used to recover or attack the key.
 */
function secretKeyFingerprint(?string $keyid = null): ?string
{
    $ks = secretCryptoKeyset();
    if ($ks === null) {
        return null;
    }
    $keyid = $keyid ?? $ks['active'];
    if (!isset($ks['keys'][$keyid])) {
        return null;
    }
    $raw = hex2bin($ks['keys'][$keyid]);
    if ($raw === false) {
        return null;
    }
    /* HMAC-style domain separation: the fingerprint is a hash of a fixed label
       + the keyid + the raw key, truncated to 12 hex chars. Never the key. */
    return substr(hash('sha256', 'ihymns-secret-key-fingerprint|' . $keyid . '|' . $raw), 0, 12);
}

/** The active keyid, or null. */
function secretActiveKeyId(): ?string
{
    $ks = secretCryptoKeyset();
    return $ks['active'] ?? null;
}

/** All keyids currently loaded (active + any retained for rotation overlap). */
function secretAllKeyIds(): array
{
    $ks = secretCryptoKeyset();
    return $ks === null ? [] : array_keys($ks['keys']);
}

/* =========================================================================
 * SECRET SETTINGS REGISTRY — which tblAppSettings keys get encrypted.
 * ========================================================================= */

/**
 * The tblAppSettings keys whose values are SECRETS and get encrypted at rest.
 * MUST stay in sync with the secret-flagged fields (the `true` flag in the
 * settings-definition array) in manage/configuration.php, plus the SIWA private
 * key (saved by its own handler). tests/php/test-secret-crypto.php scans
 * configuration.php and FAILS if a `password`/`textarea` field flagged secret
 * there is missing here — so a new email/API secret can't be added without also
 * being encrypted.
 *
 * Reads are PREFIX-driven (secretDecrypt handles any enc: value regardless of
 * key), so this list governs only the WRITE side: which keys encrypt-on-save
 * and which the encrypt-in-place migration re-wraps.
 */
function secretSettingKeys(): array
{
    return [
        'email_smtp_pass',
        'email_sendgrid_api_key',
        'email_mailgun_api_key',
        'email_ses_access_key',
        'email_ses_secret_key',
        'email_graph_client_secret',
        'email_gmail_sa_json',
        'apple_siwa_private_key',
        /* #1410 — APNs Auth Key `.p8`. NOT yet writable via any admin-UI
           field (manage/configuration.php has no APNs card in this change —
           see includes/apns.php's file-header "NOT IN SCOPE" note), so this
           key currently has NO row in tblAppSettings on any docroot. Adding
           it here now is a zero-risk, purely-additive registration: every
           consumer of secretSettingKeys() (secretInventory(),
           secretEncryptInPlace(), secretRotateReencrypt()) explicitly skips
           a key with no row / an empty value (see those functions'
           docblocks), so this has NO effect until a future PR adds the
           admin-UI card AND an owner pastes a real key — at which point it
           is encrypted at rest from the very first save, mirroring
           apple_siwa_private_key's custody exactly (rule: "mirror the SIWA
           .p8 custody pattern").
         */
        'apple_apns_private_key',
        /* #311/#1671 F6 — the Web Push VAPID private key. Registered the moment
           anything can write it (the "Generate keypair" button on
           /manage/notifications), so it is encrypted at rest from its very first
           save rather than being retro-fitted later. Its PUBLIC half
           (`webpush_vapid_public`) is deliberately NOT here: that value is
           designed to be handed to every browser as `applicationServerKey`, and
           listing a non-secret would make secretInventory() report a false
           exposure every time it is read. */
        'webpush_vapid_private',
        /* #1725/#1728/#1729 — MWBM-IntAppsAPI gateway credentials. Registered
           the moment the /manage/configuration card can write them, so both
           are encrypted at rest from their very first save, mirroring the
           apple_siwa_private_key / webpush_vapid_private custody pattern
           exactly. `intappsapi_api_key` verifies the X-API-Key header;
           `intappsapi_hmac_secret` signs POST/PATCH/DELETE bodies
           (includes/intapps_client.php::intappsSign()). Both are read back
           transparently decrypted via getAppSetting() — intapps_client.php
           never touches the encryption layer directly. */
        'intappsapi_api_key',
        'intappsapi_hmac_secret',
    ];
}

/** Is this tblAppSettings key one whose value must be encrypted at rest? */
function isSecretSettingKey(string $key): bool
{
    return in_array($key, secretSettingKeys(), true);
}

/**
 * What should actually be STORED in tblAppSettings for this key/value pair?
 *
 * ELI5: if this setting is a secret and encryption is switched on, hand back
 * the encrypted version — and if we cannot encrypt it, refuse outright rather
 * than saving the secret in the clear.
 *
 * WHY THIS IS A SEPARATE, PURE FUNCTION (#1671 F6). The rule below used to live
 * ONLY inside `manage/configuration.php`'s `$saveSetting` closure. The moment a
 * second page needed to store a secret (the VAPID private key) that closure was
 * about to be copy-pasted, and a second copy of "encrypt secrets at rest" is the
 * kind of duplication whose divergence is invisible until a secret is sitting in
 * the database as plaintext. Extracting it also makes it TESTABLE: the decision
 * is now a function of three arguments and
 * `tests/php/test-secret-crypto-admin.php` can call it, where a closure inside a
 * page could only ever be pattern-matched in source.
 *
 * ⚠️ FAIL CLOSED, DELIBERATELY, AND WITH NO function_exists() GUARD. Guarding on
 * the engine being present would fail OPEN — a missing `secret_crypto.php` would
 * silently store plaintext. Instead, an active-encryption install whose master
 * key is unavailable on THIS docroot throws, and the caller surfaces the error.
 * A refused save is recoverable; a secret written in the clear is not.
 *
 * An EMPTY value is passed through unchanged: "" means "unset this", and
 * encrypting the empty string would store an envelope that decrypts to "" —
 * indistinguishable in behaviour, larger, and confusing to anyone reading the
 * row.
 *
 * @param string $key              The tblAppSettings key.
 * @param string $value            The plaintext the operator entered.
 * @param bool   $encryptionActive The `secret_encryption_active` cutover flag.
 * @return string The exact bytes to store.
 * @throws \RuntimeException When a secret must be encrypted and cannot be.
 */
function appSettingValueForStorage(string $key, string $value, bool $encryptionActive): string
{
    if ($value === '' || !isSecretSettingKey($key) || !$encryptionActive) {
        return $value;
    }
    if (!secretCryptoReady()) {
        throw new \RuntimeException(
            'Secret encryption is active but the master key is unavailable on this server '
            . '(.auth/secrets_master_key.php) — refusing to store "' . $key . '" unencrypted.'
        );
    }
    return secretEncrypt($value);
}

/* =========================================================================
 * base64url helpers (URL/JSON-safe, no padding) — envelope fields only.
 * ========================================================================= */

/** @internal */
function _secretCryptoB64uEncode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** @internal @return string|false */
function _secretCryptoB64uDecode(string $s): string|false
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s, true); // strict
}
