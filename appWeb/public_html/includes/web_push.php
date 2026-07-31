<?php

declare(strict_types=1);

/**
 * web_push.php — VAPID + RFC 8291 Web Push, hand-rolled on openssl (#311, #1671 F6)
 * ============================================================================
 *
 * ELI5
 * ----
 * The browser gives us a "postbox address" for a device. To put a message in it
 * we have to do two things: prove the message is really from iHymns (a signed
 * token), and lock the message so only that device can open it (encryption the
 * push service itself cannot read). This file does both.
 *
 * WHY IT IS HAND-ROLLED
 * ---------------------
 * **This project has no Composer.** There is no `web-push-php` to install, so
 * the two specifications are implemented directly against PHP's `openssl_*` and
 * `hash_hkdf()`. That is unusual and deserves the scrutiny it gets below — a
 * crypto implementation that merely LOOKS right is worse than an absent one,
 * because an absent one does not ship a false promise of confidentiality.
 *
 * THE TWO SPECIFICATIONS, BY SECTION
 * ----------------------------------
 * **RFC 8292 — Voluntary Application Server Identification (VAPID).**
 *   §2      the `Authorization: vapid t=<JWT>, k=<public key>` header;
 *   §2.1    the JWT claims: `aud` (the push origin), `exp` (≤ 24 h out),
 *           `sub` (a `mailto:` or `https:` contact for the operator);
 *   §3.2    the public key is the uncompressed P-256 point, base64url, unpadded.
 *   Signature is ES256 (ECDSA P-256 + SHA-256), JOSE flavour: a raw 64-byte
 *   `r||s`, NOT the DER SEQUENCE `openssl_sign()` emits.
 *
 * **RFC 8291 — Message Encryption for Web Push**, layered on
 * **RFC 8188 — Encrypted Content-Encoding for HTTP** (`aes128gcm`).
 *   8291 §3.1  the ECDH shared secret between an EPHEMERAL server key and the
 *              subscription's `p256dh` key;
 *   8291 §3.3  the key-derivation ladder:
 *                  PRK_key = HMAC-SHA-256(auth_secret, ecdh_secret)
 *                  key_info = "WebPush: info" || 0x00 || ua_public || as_public
 *                  IKM      = HMAC-SHA-256(PRK_key, key_info || 0x01)
 *   8188 §2.2  IKM -> CEK (16 bytes, info "Content-Encoding: aes128gcm\0")
 *              and NONCE (12 bytes, info "Content-Encoding: nonce\0");
 *   8188 §2.1  the record header: salt(16) || rs(4, big-endian) || idlen(1) ||
 *              keyid(idlen) — where, per 8291 §4, keyid IS the server's
 *              ephemeral public key;
 *   8188 §2    each record's plaintext is padded with a delimiter octet: 0x02
 *              for the LAST record (we always emit exactly one).
 *
 * HOW THIS IS PROVEN CORRECT RATHER THAN ASSERTED
 * -----------------------------------------------
 * `tests/php/test-web-push.php` runs **RFC 8291 §5's own published example**
 * through `webPushEncrypt()` — the RFC fixes the plaintext, both keypairs, the
 * auth secret and the salt, and publishes the exact expected ciphertext. Given
 * those inputs this implementation must reproduce that byte string. It does.
 * That is an independent, third-party oracle: a self-consistent
 * encrypt-then-decrypt round trip would prove only that the file agrees with
 * itself, which is exactly the kind of evidence that has been wrong-but-green
 * on this branch four times.
 *
 * The VAPID JWT is verified the same way — signed here, then verified with
 * `openssl_verify()` against the public half in the test, so a signature that
 * is merely well-SHAPED cannot pass.
 *
 * ⚠️ WHAT IS **NOT** PROVEN, AND MUST BE STATED PLAINLY
 * -----------------------------------------------------
 * There is no browser and no network in the build container. **No push has ever
 * been delivered to a real device from this code.** The unexercised links are:
 * `webPushSend()`'s HTTP request, the service worker's `push` handler, the
 * subscription round trip, and every push service's own acceptance of these
 * headers. A green suite proves the JWT and the ciphertext are right; it does
 * NOT prove "push works". Delivery to a real device is this feature's
 * acceptance bar and it has not been reached.
 *
 * DORMANT BY CONSTRUCTION
 * -----------------------
 * `webPushConfigured()` is false until an operator generates a VAPID keypair on
 * /manage/notifications, so every send is a no-op `not_configured` on every
 * docroot today. The private key is registered in `secretSettingKeys()`, so it
 * is encrypted at rest from the very first save (#1466).
 *
 * @see appWeb/public_html/api.php                    — push_subscribe / push_unsubscribe
 * @see appWeb/public_html/service-worker.js.php      — the push / notificationclick handlers
 * @see appWeb/public_html/manage/notifications.php   — the operator surface
 * @see appWeb/public_html/includes/apns.php          — the sibling Apple bridge this mirrors
 * @link https://www.rfc-editor.org/rfc/rfc8292  VAPID
 * @link https://www.rfc-editor.org/rfc/rfc8291  Message Encryption for Web Push
 * @link https://www.rfc-editor.org/rfc/rfc8188  Encrypted Content-Encoding for HTTP
 * @link https://www.php.net/manual/en/function.hash-hkdf.php
 * @link https://www.php.net/manual/en/function.openssl-pkey-derive.php
 * @link https://developer.mozilla.org/docs/Web/API/Push_API
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Reuses _appleSiwaB64UrlEncode() / _appleSiwaB64UrlDecodeStrict() /
   _appleSiwaDerEcdsaToRaw() — the base64url and (genuinely intricate)
   DER->JOSE signature conversion are shared rather than re-forked a third
   time, exactly as includes/apns.php shares them. apple_siwa.php has no hard
   DB dependency at load time, so requiring it keeps this module (and its CLI
   test) standalone-loadable. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'apple_siwa.php';

/* =========================================================================
 * 1. THE KIND REGISTRY — adding a notification kind is ONE line here
 * ========================================================================= */

/**
 * The push-notification kinds a user can opt in and out of.
 *
 * ELI5: the list of things we might buzz somebody about, and whether they are
 * on by default.
 *
 * `key => [label, description, defaultOn]`.
 *
 * ONE REGISTRY, the same one-line-registry shape as `TIER_CAPS`
 * (`includes/access_tier_validation.php`, rule #28) and
 * `userSyncTombstoneReasons()` (rule #20). To add a kind you add ONE line here
 * and nothing else: `manage/notifications.php` builds its audience picker from
 * this map, `includes/pages/settings.php` emits it to the client as a data
 * attribute, `js/modules/push-notifications.js` renders one checkbox per entry,
 * and `webPushKindEnabled()` resolves the user's choice against it. If adding a
 * kind ever takes more than that line, the design has drifted and the fix is to
 * restore the derivation, not to add a second list.
 *
 * The key is a VARCHAR-style vocabulary, never an ENUM and never a column
 * (rule #20): per-user opt-ins live in the namespaced settings store under
 * `ihymns.push`, so a new kind needs no schema change at all.
 *
 * @return array<string,array{0:string,1:string,2:bool}>
 */
function webPushKinds(): array
{
    return [
        'announcement' => [
            'Announcements',
            'News, new songbooks and service updates from iHymns.',
            true,
        ],
        'test' => [
            'Test notifications',
            'Delivery tests you or an administrator send to your own devices.',
            true,
        ],
        /* #1695 — the safeguard that makes editor+ song deletion defensible.
           Stage 3 hands recoverable deletion back to curators; this is how the
           people who can PERMANENTLY destroy a song find out one is waiting for
           review. It is not decoration — it is the load-bearing half of that
           decision, which is why it fires from inside the write core rather
           than from a caller that a future funnel could forget.

           NOTE THE FOURTH ELEMENT. Every kind above is offered to everyone,
           because everyone can meaningfully receive it. This one cannot: a
           regular user will never be sent it, so offering them the checkbox
           advertises a notification they can never get. The audience is stated
           as an ENTITLEMENT rather than a role list so it tracks whatever the
           operator has configured — the same reason `purge_songs` is a key and
           not a hardcoded role check. */
        'song_deleted' => [
            'Song deletions',
            'When a song is deleted or restored, so it can be reviewed or purged.',
            true,
            'purge_songs',
        ],
    ];
}

/** Is this a kind the registry knows about? */
function webPushKindValid(string $kind): bool
{
    return array_key_exists($kind, webPushKinds());
}

/**
 * The kinds a given role may actually receive.
 *
 * ELI5: only show somebody the notification switches that could ever apply to
 * them. Offering a switch for something you will never be sent is a promise the
 * app cannot keep.
 *
 * Detail: a kind with no fourth element is universal (the historical shape, so
 * existing entries need no edit). A kind WITH one names an entitlement, and the
 * role must hold it. `userHasEntitlement()` reads the live map, so an operator
 * who re-aims `purge_songs` at /manage/entitlements changes who is offered this
 * automatically — no second list to keep in step (rule #35: a mechanism, not a
 * comment saying "keep these in sync").
 *
 * @param string|null $role the viewer's role, or null for anonymous
 * @return array<string, array{0:string,1:string,2:bool,3?:string}>
 */
function webPushKindsForRole(?string $role): array
{
    $out = [];
    foreach (webPushKinds() as $key => $meta) {
        $needs = $meta[3] ?? null;
        if ($needs !== null) {
            if ($role === null || !function_exists('userHasEntitlement')
                || !userHasEntitlement($needs, $role)) {
                continue;
            }
        }
        $out[$key] = $meta;
    }
    return $out;
}

/**
 * Has this user opted in to this kind?
 *
 * ELI5: look up the user's preferences and see whether they want this sort of
 * message; if they have never said, use the kind's default.
 *
 * PURE — takes the already-decoded `tblUsers.Settings` blob, so it can be
 * asserted directly. Reads the `ihymns.push` namespace via the shared
 * `userSettingsSubtree()` when that module is loaded, and falls back to a plain
 * array read otherwise so this file stays standalone-loadable for its CLI test.
 *
 * UNKNOWN KIND => FALSE, never the default. A kind that is not in the registry
 * cannot have been offered as a checkbox, so nobody has consented to it; a
 * typo'd kind must send to NOBODY rather than to everybody.
 *
 * A non-boolean stored value (a string "0", a number, a null from a confused
 * client) is read strictly: only `true` means yes. Reading "0" as truthy is how
 * an opt-OUT becomes an opt-in.
 *
 * @param array  $settings The decoded tblUsers.Settings blob (may be empty).
 * @param string $kind
 */
function webPushKindEnabled(array $settings, string $kind): bool
{
    $kinds = webPushKinds();
    if (!array_key_exists($kind, $kinds)) {
        return false;
    }
    $subtree = $settings['ihymns.push'] ?? null;
    if (!is_array($subtree) || !array_key_exists($kind, $subtree)) {
        return (bool)$kinds[$kind][2];   /* never asked => the kind's default */
    }
    return $subtree[$kind] === true;
}

/* =========================================================================
 * 2. KEY MATERIAL — raw base64url <-> PEM, and keypair generation
 * ========================================================================= */

/**
 * DER prefix for a P-256 SubjectPublicKeyInfo, up to (not including) the point.
 *
 * ELI5: the fixed wrapper OpenSSL expects around a raw public key.
 *
 * `SEQUENCE { SEQUENCE { OID 1.2.840.10045.2.1 (ecPublicKey),
 *                        OID 1.2.840.10045.3.1.7 (prime256v1) },
 *             BIT STRING (0 unused bits) }` — 26 bytes, then the 65-byte
 * uncompressed point. Constant because the curve is constant: Web Push is
 * P-256 only (RFC 8291 §3.1), so there is nothing here to parameterise.
 *
 * @internal
 */
function _webPushSpkiPrefix(): string
{
    return (string)hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
}

/** base64url-encode (no padding) — shared with the Apple modules. @internal */
function _webPushB64u(string $raw): string
{
    return _appleSiwaB64UrlEncode($raw);
}

/**
 * base64url-decode, strictly. Returns null on anything malformed.
 *
 * Strict on purpose: a subscription key that does not decode cleanly is a
 * corrupt row, and quietly accepting a partial decode would derive a wrong
 * shared secret and produce a payload the device silently fails to open.
 *
 * @internal
 */
function _webPushB64uDecode(string $s): ?string
{
    return _appleSiwaB64UrlDecodeStrict(trim($s));
}

/**
 * Wrap a raw 65-byte uncompressed P-256 point as a PEM public key.
 *
 * ELI5: put the browser's raw key into the envelope OpenSSL can read.
 *
 * The 0x04 lead byte is the uncompressed-point marker (SEC 1 §2.3.3); a
 * compressed point (0x02/0x03) is 33 bytes and is rejected rather than
 * guessed at, because Web Push subscriptions always publish the uncompressed
 * form and a 33-byte value means the row is not what we think it is.
 *
 * @param string $raw65 Exactly 65 bytes: 0x04 || X(32) || Y(32).
 * @return string|null PEM, or null when the input is not a plausible point.
 */
function webPushRawPublicToPem(string $raw65): ?string
{
    if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") {
        return null;
    }
    $der = _webPushSpkiPrefix() . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Wrap a raw 32-byte private scalar (+ its public point) as a SEC 1 PEM.
 *
 * ELI5: rebuild the private key file from the two raw numbers we stored.
 *
 * WHY THE RAW FORM IS WHAT GETS STORED. The VAPID public key must reach the
 * browser as a raw 65-byte point (it is what `applicationServerKey` takes), and
 * every other Web Push implementation stores the pair as base64url raw. Keeping
 * PEM in the database instead would mean converting on the way OUT for the
 * browser and would make the value un-pasteable into any other tool.
 *
 * The DER assembled here is:
 *     SEQUENCE {
 *       INTEGER 1,                       -- ECPrivateKey version (RFC 5915 §3)
 *       OCTET STRING (32)  privateKey,
 *       [0] OID prime256v1,              -- parameters
 *       [1] BIT STRING (65) publicKey
 *     }
 * All lengths are fixed because the curve is fixed, so the structure is a
 * constant template with two slots — no length arithmetic to get subtly wrong.
 *
 * @param string $priv32 Raw 32-byte private scalar.
 * @param string $pub65  Raw 65-byte uncompressed public point.
 * @return string|null PEM, or null when either input is the wrong shape.
 */
function webPushRawPrivateToPem(string $priv32, string $pub65): ?string
{
    if (strlen($priv32) !== 32 || strlen($pub65) !== 65 || $pub65[0] !== "\x04") {
        return null;
    }
    $der = "\x30\x77"                                   /* SEQUENCE, 0x77 = 119 bytes */
        . "\x02\x01\x01"                                /* INTEGER 1 (version) */
        . "\x04\x20" . $priv32                          /* OCTET STRING (32) private */
        . "\xa0\x0a\x06\x08" . hex2bin('2a8648ce3d030107')  /* [0] OID prime256v1 */
        . "\xa1\x44\x03\x42\x00" . $pub65;              /* [1] BIT STRING (65) public */

    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

/**
 * Left-pad a P-256 scalar or coordinate to exactly 32 bytes.
 *
 * ELI5: OpenSSL hands back numbers with any leading zeros chopped off; the Push
 * API wants a fixed-width 32 bytes, so put the zeros back.
 *
 * ⚠️ THIS IS A SEPARATE, EXPORTED FUNCTION SO IT CAN BE TESTED DETERMINISTICALLY,
 * and that is the whole reason it exists rather than being an inline
 * `str_pad()`. OpenSSL strips leading zero bytes, so roughly one coordinate in
 * 256 comes back 31 bytes — which means an inline version is exercised by a
 * generated-key test only about 1% of the time. A mutation deleting the padding
 * duly left the suite GREEN. A property that fires on 1 key in 128 is not
 * testable by generating one key; it IS testable by calling the function with a
 * 31-byte string.
 *
 * The failure it prevents is the worst kind: concatenating unpadded coordinates
 * yields a 64-byte "point" that every push service rejects — silently, and only
 * sometimes, which reads as a flaky push service rather than as a bug here.
 *
 * @param string $raw A big-endian integer, 32 bytes or fewer.
 */
function webPushPadScalar(string $raw): string
{
    return str_pad($raw, 32, "\x00", STR_PAD_LEFT);
}

/**
 * Extract the raw 65-byte uncompressed point from an OpenSSL key handle.
 *
 * ELI5: pull the two coordinates out and glue them together the way the Push
 * API expects them.
 *
 * X and Y are left-padded by `webPushPadScalar()` — see that function for why
 * it is a named, separately-tested step rather than an inline `str_pad()`.
 *
 * @param \OpenSSLAsymmetricKey $key
 * @return string|null 65 bytes, or null when the key is not EC P-256.
 */
function webPushRawPublicFromKey($key): ?string
{
    $details = @openssl_pkey_get_details($key);
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
        return null;
    }
    $curve = $details['ec']['curve_name'] ?? null;
    /* P-256 is reported under either name depending on the OpenSSL build —
       the same two-name check appleSiwaBuildClientSecret() and _apnsBuildJwt()
       apply. */
    if ($curve !== 'prime256v1' && $curve !== 'secp256r1') {
        return null;
    }
    $x = $details['ec']['x'] ?? null;
    $y = $details['ec']['y'] ?? null;
    if (!is_string($x) || !is_string($y)) {
        return null;
    }
    return "\x04" . webPushPadScalar($x) . webPushPadScalar($y);
}

/**
 * Generate a fresh VAPID keypair.
 *
 * ELI5: make the long-lived identity key that says "this really is iHymns".
 *
 * Returns base64url raw halves, the form every Web Push tool and every
 * `applicationServerKey` uses. The private half is a SECRET and is registered
 * in `secretSettingKeys()` so it is encrypted at rest the moment it is stored;
 * the public half is designed to be shipped to every browser and is not.
 *
 * ⚠️ ROTATING THE VAPID KEY INVALIDATES EVERY EXISTING SUBSCRIPTION. A push
 * service binds a subscription to the application-server key that created it,
 * so a new keypair means every stored `tblPushSubscriptions` row stops working
 * and every browser must re-subscribe. The admin UI says so before generating a
 * second one; this function does not decide, it only mints.
 *
 * @return array{publicKey:string,privateKey:string}|null null when OpenSSL cannot generate.
 */
function webPushGenerateVapidKeys(): ?array
{
    $key = @openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);
    if ($key === false) {
        return null;
    }
    $details = @openssl_pkey_get_details($key);
    $pub = webPushRawPublicFromKey($key);
    $d   = $details['ec']['d'] ?? null;
    if ($pub === null || !is_string($d)) {
        return null;
    }
    return [
        'publicKey'  => _webPushB64u($pub),
        /* Left-padded for the same reason as X and Y: an unpadded 31-byte
           scalar rebuilds into a PEM whose fixed-length DER template no longer
           fits, and the key silently stops loading. */
        'privateKey' => _webPushB64u(webPushPadScalar($d)),
    ];
}

/* =========================================================================
 * 3. VAPID — the signed identity header (RFC 8292)
 * ========================================================================= */

/**
 * The `aud` claim for an endpoint: its scheme + host, nothing else.
 *
 * ELI5: the push service's front door, without the private part of the address.
 *
 * RFC 8292 §2 requires the ORIGIN of the push resource. Including the path
 * would leak the subscription's secret endpoint id into a token that transits
 * as a header, and would also be rejected by several push services.
 *
 * @return string|null null when the endpoint is not a usable absolute URL.
 */
function webPushAudience(string $endpoint): ?string
{
    $parts = @parse_url($endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    /* https only. A push endpoint is always https in practice, and signing an
       audience we would then have to fetch over http is an obvious downgrade. */
    if (strtolower((string)$parts['scheme']) !== 'https') {
        return null;
    }
    $origin = 'https://' . strtolower((string)$parts['host']);
    if (!empty($parts['port']) && (int)$parts['port'] !== 443) {
        $origin .= ':' . (int)$parts['port'];
    }
    return $origin;
}

/**
 * Mint a VAPID JWT (ES256) — RFC 8292 §2.
 *
 * ELI5: a short-lived signed note saying "iHymns is sending this, and here is
 * who to contact about it".
 *
 * PURE apart from the `openssl_*` calls: every input is an explicit parameter,
 * including the clock, so `tests/php/test-web-push.php` signs with a locally
 * generated keypair and then VERIFIES the signature with `openssl_verify()`.
 * A signature that is merely well-shaped cannot pass that.
 *
 * `exp` is capped at 24 hours (RFC 8292 §2) — a longer-lived token is rejected
 * by push services, and the cap is applied here rather than trusted from the
 * caller so a mistaken argument degrades to "valid but shorter" instead of
 * "silently refused by every push service".
 *
 * The signature is converted from OpenSSL's DER SEQUENCE to the JOSE raw
 * `r||s` form by the SHARED `_appleSiwaDerEcdsaToRaw()`. That conversion is the
 * genuinely intricate part of ES256 and is deliberately not re-forked here (see
 * includes/apns.php's docblock for the same decision).
 *
 * @param string $audience   Output of webPushAudience().
 * @param string $subject    `mailto:…` or `https://…` operator contact.
 * @param string $privB64u   VAPID private key, base64url raw 32 bytes.
 * @param string $pubB64u    VAPID public key, base64url raw 65 bytes.
 * @param int    $now        Unix time (injected for testability).
 * @param int    $ttlSeconds Desired lifetime; capped to 24 h.
 * @return string|null Compact JWS, or null when the inputs do not form a usable key.
 */
function webPushBuildVapidJwt(
    string $audience,
    string $subject,
    string $privB64u,
    string $pubB64u,
    int $now,
    int $ttlSeconds = 43200
): ?string {
    if ($audience === '' || $subject === '') {
        return null;
    }
    $priv = _webPushB64uDecode($privB64u);
    $pub  = _webPushB64uDecode($pubB64u);
    if ($priv === null || $pub === null) {
        return null;
    }
    $pem = webPushRawPrivateToPem($priv, $pub);
    if ($pem === null) {
        return null;
    }
    $key = @openssl_pkey_get_private($pem);
    if ($key === false) {
        return null;
    }

    $ttl = max(60, min($ttlSeconds, 86400));   /* RFC 8292 §2: at most 24 hours */
    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $claims = ['aud' => $audience, 'exp' => $now + $ttl, 'sub' => $subject];

    $h64 = _webPushB64u((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $p64 = _webPushB64u((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = "{$h64}.{$p64}";

    $derSig = '';
    if (!openssl_sign($signingInput, $derSig, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $rawSig = _appleSiwaDerEcdsaToRaw($derSig, 32);
    if ($rawSig === null) {
        return null;
    }
    return $signingInput . '.' . _webPushB64u($rawSig);
}

/**
 * The full `Authorization` header value for a request (RFC 8292 §3.1).
 *
 * ELI5: the signed note plus our public key, in the shape the push service
 * expects to read them.
 *
 * @return string|null null when the JWT could not be minted.
 */
function webPushAuthorizationHeader(
    string $audience,
    string $subject,
    string $privB64u,
    string $pubB64u,
    int $now
): ?string {
    $jwt = webPushBuildVapidJwt($audience, $subject, $privB64u, $pubB64u, $now);
    if ($jwt === null) {
        return null;
    }
    return 'vapid t=' . $jwt . ', k=' . $pubB64u;
}

/* =========================================================================
 * 4. PAYLOAD ENCRYPTION — RFC 8291 over RFC 8188 aes128gcm
 * ========================================================================= */

/**
 * Largest plaintext we will encrypt, in bytes.
 *
 * ELI5: push services only accept small messages, so there is a limit on how
 * much we can send — and going over is refused, never trimmed.
 *
 * The wire budget is 4096 bytes for the whole encrypted body. The RFC 8188
 * header costs 16 (salt) + 4 (rs) + 1 (idlen) + 65 (the ephemeral key) = 86,
 * and the record adds 1 padding delimiter + 16 GCM tag. 3800 leaves a
 * comfortable margin under the resulting 3993 ceiling, and no notification
 * anywhere near it is readable on a lock screen anyway.
 *
 * A REJECTION, never a truncation: cutting a JSON payload in half produces a
 * body the service worker cannot parse, and the failure surfaces on the device
 * as "nothing happened".
 */
function webPushMaxPayloadBytes(): int
{
    return 3800;
}

/**
 * The RFC 8188 record size we advertise. 4096 is what every implementation uses
 * and what RFC 8291 §5's published example uses, which matters because that
 * example is this file's correctness oracle.
 *
 * @internal
 */
function _webPushRecordSize(): int
{
    return 4096;
}

/**
 * Encrypt a Web Push payload — RFC 8291 §3, output framed per RFC 8188 §2.
 *
 * ELI5: lock the message so that only the one device that gave us this
 * subscription can open it. Not even the push service can read it.
 *
 * THE LADDER, step by step, with the section each step comes from:
 *
 *   1. ECDH (8291 §3.1) between an EPHEMERAL server keypair — a fresh one per
 *      message, never the VAPID identity key — and the subscription's `p256dh`.
 *      Reusing the VAPID key here would tie every message's confidentiality to
 *      one long-lived secret, which is precisely what the ephemeral key exists
 *      to avoid.
 *   2. IKM (8291 §3.3) = HKDF(salt = the subscription's `auth` secret,
 *      ikm = the ECDH secret,
 *      info = "WebPush: info" || 0x00 || ua_public || as_public).
 *      Binding BOTH public keys into `info` is what stops a substituted key
 *      producing a valid-looking decryption.
 *   3. CEK / NONCE (8188 §2.2) = HKDF(salt = the 16 random bytes,
 *      ikm = IKM, info = "Content-Encoding: aes128gcm\0" / "…nonce\0"),
 *      truncated to 16 and 12 bytes.
 *   4. The single record's plaintext is `payload || 0x02` — 8188 §2's
 *      last-record delimiter. Using 0x01 (a NON-last record) makes a receiver
 *      wait for a record that never comes.
 *   5. The body is header || AES-128-GCM(CEK, NONCE, plaintext) || tag.
 *
 * `$salt` and `$asKeys` are injectable ONLY so the RFC's published example can
 * be reproduced exactly in the test. In production both are absent and both are
 * generated fresh per message — a reused salt or a reused ephemeral key would
 * repeat a (key, nonce) pair, which is the classic catastrophic GCM failure.
 *
 * @param string      $payload      The plaintext (usually a small JSON object).
 * @param string      $uaPublicB64u The subscription's `p256dh` key, base64url.
 * @param string      $authB64u     The subscription's `auth` secret, base64url (16 bytes).
 * @param string|null $salt         TEST SEAM: exactly 16 bytes. Null = fresh random.
 * @param array|null  $asKeys       TEST SEAM: ['private' => raw32, 'public' => raw65]. Null = fresh.
 * @return string|null The encrypted body, or null when an input is unusable.
 */
function webPushEncrypt(
    string $payload,
    string $uaPublicB64u,
    string $authB64u,
    ?string $salt = null,
    ?array $asKeys = null
): ?string {
    if (strlen($payload) > webPushMaxPayloadBytes()) {
        return null;   /* rejection, never a truncation — see the cap's docblock */
    }

    $uaPublic = _webPushB64uDecode($uaPublicB64u);
    $auth     = _webPushB64uDecode($authB64u);
    if ($uaPublic === null || $auth === null) {
        return null;
    }
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
        return null;   /* not an uncompressed P-256 point */
    }
    /* RFC 8291 §3.2: the auth secret is 16 octets. A shorter one means a
       corrupt or truncated row; deriving from it anyway would produce a payload
       the device silently cannot open. */
    if (strlen($auth) !== 16) {
        return null;
    }

    /* --- 1. the EPHEMERAL server keypair (fresh per message in production) -- */
    if ($asKeys !== null) {
        $asPrivateRaw = (string)($asKeys['private'] ?? '');
        $asPublicRaw  = (string)($asKeys['public'] ?? '');
        $asPem = webPushRawPrivateToPem($asPrivateRaw, $asPublicRaw);
        if ($asPem === null) {
            return null;
        }
        $asKey = @openssl_pkey_get_private($asPem);
        if ($asKey === false) {
            return null;
        }
    } else {
        $asKey = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($asKey === false) {
            return null;
        }
        $asPublicRaw = webPushRawPublicFromKey($asKey);
        if ($asPublicRaw === null) {
            return null;
        }
    }

    /* --- ECDH (8291 §3.1) ------------------------------------------------- */
    $uaPem = webPushRawPublicToPem($uaPublic);
    if ($uaPem === null) {
        return null;
    }
    $uaKey = @openssl_pkey_get_public($uaPem);
    if ($uaKey === false) {
        return null;
    }
    /* openssl_pkey_derive returns the X coordinate of the shared point — the
       32-byte "Z" of SEC 1 ECDH, which is exactly what RFC 8291 calls
       ecdh_secret. Requesting 32 bytes explicitly rather than taking the
       default keeps the length independent of the OpenSSL build. */
    $ecdhSecret = @openssl_pkey_derive($uaKey, $asKey, 32);
    if (!is_string($ecdhSecret) || strlen($ecdhSecret) !== 32) {
        return null;
    }

    /* --- 2. IKM (8291 §3.3) ---------------------------------------------- */
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublicRaw;
    $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $auth);

    /* --- 3. CEK + NONCE (8188 §2.2) -------------------------------------- */
    if ($salt === null) {
        $salt = random_bytes(16);
    }
    if (strlen($salt) !== 16) {
        return null;
    }
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    /* --- 4. the single record (8188 §2) ---------------------------------- */
    $tag = '';
    $ciphertext = openssl_encrypt(
        $payload . "\x02",          /* 0x02 = LAST-record delimiter */
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',                          /* no additional authenticated data */
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        return null;
    }

    /* --- 5. the header (8188 §2.1) + body -------------------------------- */
    $header = $salt
        . pack('N', _webPushRecordSize())    /* rs, 4-byte big-endian */
        . chr(strlen($asPublicRaw))          /* idlen */
        . $asPublicRaw;                      /* keyid = the ephemeral key (8291 §4) */

    return $header . $ciphertext . $tag;
}

/* =========================================================================
 * 5. CONFIGURATION + TRANSPORT
 * ========================================================================= */

/**
 * The stored VAPID credentials.
 *
 * The private key is read through `getAppSetting()`, which transparently
 * decrypts an `enc:v1` envelope (#1466) and FAILS SAFE to the default on a
 * decrypt failure — so a docroot without the master key sees an unset key and
 * goes dormant rather than trying to sign with ciphertext.
 *
 * @return array{publicKey:string,privateKey:string,subject:string}
 */
function webPushVapidCredentials(): array
{
    $get = static fn(string $k): string => function_exists('getAppSetting')
        ? trim((string)(getAppSetting($k, '') ?? ''))
        : '';
    return [
        'publicKey'  => $get('webpush_vapid_public'),
        'privateKey' => $get('webpush_vapid_private'),
        'subject'    => $get('webpush_vapid_subject'),
    ];
}

/**
 * Is the VAPID bridge provisioned AND does the stored key actually load?
 *
 * ELI5: do we have a working identity key, or is this feature asleep?
 *
 * Mirrors `apnsConfigured()`: it does not merely check the settings are
 * non-empty, it proves the key material parses, because a truncated paste
 * produces a value that looks configured and fails on every send.
 */
function webPushConfigured(): bool
{
    $c = webPushVapidCredentials();
    if ($c['publicKey'] === '' || $c['privateKey'] === '' || $c['subject'] === '') {
        return false;
    }
    return webPushBuildVapidJwt('https://example.com', $c['subject'], $c['privateKey'], $c['publicKey'], time()) !== null;
}

/**
 * Is a `sub` claim usable? RFC 8292 §2 requires `mailto:` or an https URL.
 *
 * PURE, so the admin form can validate before storing rather than discovering
 * at send time that every push is refused.
 */
function webPushSubjectValid(string $subject): bool
{
    $s = trim($subject);
    if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $s) === 1) {
        return true;
    }
    return preg_match('#^https://[^\s/]+\.[^\s/]+#i', $s) === 1;
}

/**
 * Should a subscription be DELETED because of this response status?
 *
 * ELI5: the push service told us this postbox no longer exists, so stop writing
 * to it.
 *
 * BRANCHES ON THE STATUS, never on the body's prose (rule #35 / #1677). Push
 * services word their errors differently and reword them without notice; the
 * status codes are the contract. 404 = the subscription was never/no longer
 * known; 410 Gone = it has been permanently revoked (the browser was
 * uninstalled, the user cleared site data). Anything else — including 429 and
 * every 5xx — is transient and must NOT delete a working subscription, because
 * a prune is irreversible and the user would simply stop receiving pushes with
 * nothing to indicate why.
 */
function webPushShouldPrune(int $httpStatus): bool
{
    return $httpStatus === 404 || $httpStatus === 410;
}

/**
 * Is a response status a successful delivery hand-off?
 *
 * 201 Created is the specified answer; 200 and 202 are accepted because several
 * services use them and refusing a success is worse than accepting a variant.
 */
function webPushIsSuccess(int $httpStatus): bool
{
    return $httpStatus === 200 || $httpStatus === 201 || $httpStatus === 202;
}

/**
 * Build the notification payload the service worker will render.
 *
 * ELI5: the little JSON parcel with the title, the text and where tapping it
 * should take you.
 *
 * PURE. Kept here rather than inline at each caller so the service worker has
 * exactly ONE shape to parse — the `push` handler and this function are the two
 * halves of a cross-file agreement, and `tests/php/test-web-push.php` asserts
 * every key emitted here is read there (rule #35: a mechanism, not a comment).
 *
 * The URL is restricted to a site-relative path. A push notification is an
 * attacker-attractive way to send somebody to an arbitrary origin, and there is
 * no legitimate need to leave the app.
 *
 * @param string $kind  A key from webPushKinds().
 * @param string $title
 * @param string $body
 * @param string $url   Site-relative path, or '' for the home page.
 */
function webPushBuildPayload(string $kind, string $title, string $body, string $url = ''): string
{
    $safeUrl = (preg_match('#^/[^/\\\\]#', $url) === 1) ? $url : '/';
    return (string)json_encode([
        'kind'  => webPushKindValid($kind) ? $kind : 'announcement',
        'title' => mb_substr(trim($title), 0, 120),
        'body'  => mb_substr(trim($body), 0, 300),
        'url'   => mb_substr($safeUrl, 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Is the subscriptions table present on THIS install?
 *
 * `tblPushSubscriptions` ships in schema.sql and has for a long time, but the
 * three docroots share one MySQL and migrations are web-run, so the probe costs
 * one memoised query and removes a whole class of white-screen (#1228).
 */
function webPushSubscriptionsReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPushSubscriptions' LIMIT 1"
        );
        $stmt->execute();
        $ready = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Send ONE push to ONE subscription.
 *
 * ELI5: knock on this one device's postbox with a locked message.
 *
 * DORMANT BY CONSTRUCTION: returns `not_configured` immediately — no network
 * call, no DB write — unless an operator has generated a VAPID keypair, which
 * is the case on every docroot today.
 *
 * ⚠️ THE HTTP REQUEST BELOW HAS NEVER BEEN EXECUTED. There is no network in the
 * build container. The JWT and the ciphertext it carries are proven correct by
 * `tests/php/test-web-push.php` (the RFC's own example vector); the request
 * itself, and every push service's acceptance of it, are not.
 *
 * @param \mysqli $db
 * @param array   $subscription ['Endpoint' => string, 'P256dhKey' => string, 'AuthKey' => string]
 * @param string  $payload      Output of webPushBuildPayload().
 * @param int     $ttl          Seconds the push service may hold the message.
 * @return array{ok:bool,status:string,httpStatus?:int}
 */
function webPushSend(\mysqli $db, array $subscription, string $payload, int $ttl = 86400): array
{
    $endpoint = trim((string)($subscription['Endpoint'] ?? ''));
    $p256dh   = trim((string)($subscription['P256dhKey'] ?? ''));
    $auth     = trim((string)($subscription['AuthKey'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return ['ok' => false, 'status' => 'invalid_subscription'];
    }

    $audience = webPushAudience($endpoint);
    if ($audience === null) {
        return ['ok' => false, 'status' => 'invalid_endpoint'];
    }

    $creds = webPushVapidCredentials();
    if ($creds['publicKey'] === '' || $creds['privateKey'] === '' || $creds['subject'] === '') {
        return ['ok' => false, 'status' => 'not_configured'];
    }
    $authHeader = webPushAuthorizationHeader(
        $audience, $creds['subject'], $creds['privateKey'], $creds['publicKey'], time()
    );
    if ($authHeader === null) {
        return ['ok' => false, 'status' => 'not_configured'];
    }

    $body = webPushEncrypt($payload, $p256dh, $auth);
    if ($body === null) {
        return ['ok' => false, 'status' => 'encrypt_failed'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 'transport_unavailable'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_HTTPHEADER      => [
            'Authorization: ' . $authHeader,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . max(0, $ttl),
            /* RFC 8030 §5.3. "normal" is right for an announcement: "high"
               wakes a sleeping device and is for genuinely time-critical
               messages, and services throttle senders who over-claim it. */
            'Urgency: normal',
        ],
        CURLOPT_RETURNTRANSFER  => true,
        /* TLS verification is ALWAYS on — the same non-negotiable invariant
           apns.php and apple_siwa.php document for their outbound calls. */
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_MAXREDIRS       => 0,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 8,
        CURLOPT_TIMEOUT         => 15,
    ]);
    $resp   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    unset($resp);   /* the body is never logged: it can echo endpoint material */

    if ($errno !== 0) {
        return ['ok' => false, 'status' => 'transport_error', 'httpStatus' => 0];
    }
    if (webPushIsSuccess($status)) {
        return ['ok' => true, 'status' => 'sent', 'httpStatus' => $status];
    }
    if (webPushShouldPrune($status)) {
        _webPushPrune($db, $endpoint);
        return ['ok' => false, 'status' => 'pruned', 'httpStatus' => $status];
    }
    return ['ok' => false, 'status' => 'push_error', 'httpStatus' => $status];
}

/**
 * Delete a subscription the push service says is permanently dead.
 *
 * Best-effort and never throws: a prune failure must not turn an
 * already-reported send failure into an uncaught exception mid-fan-out.
 *
 * @internal
 */
function _webPushPrune(\mysqli $db, string $endpoint): void
{
    if (!webPushSubscriptionsReady($db)) {
        return;
    }
    try {
        $stmt = $db->prepare('DELETE FROM tblPushSubscriptions WHERE Endpoint = ?');
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[webPush/prune] ' . $e->getMessage());
    }
}

/**
 * Fan a notification out to every opted-in subscription.
 *
 * ELI5: send this one message to everybody who asked to hear about this sort of
 * thing, and quietly forget the postboxes that no longer exist.
 *
 * OPT-IN IS CHECKED PER USER, not per subscription: the preference lives on the
 * account (in the `ihymns.push` settings namespace), so a user who turned
 * announcements off on their phone has turned them off everywhere, which is
 * what "off" means to a person.
 *
 * ⚠️ SYNCHRONOUS AND BOUNDED. There is no job queue in this deployment, so a
 * broadcast runs inside the admin's request. `$maxRecipients` bounds it the way
 * `NOTIFY_BROADCAST_MAX` bounds the in-app broadcast on the same page. A larger
 * audience needs a queue, and pretending otherwise would produce a request that
 * times out halfway through with no record of where it stopped.
 *
 * @param \mysqli    $db
 * @param string     $kind          A key from webPushKinds().
 * @param string     $payload       Output of webPushBuildPayload().
 * @param int[]|null $userIds       Restrict to these users; null = everyone opted in.
 * @param int        $maxRecipients Hard bound on subscriptions contacted.
 * @return array{sent:int,failed:int,pruned:int,skipped:int,configured:bool}
 */
function webPushBroadcast(
    \mysqli $db,
    string $kind,
    string $payload,
    ?array $userIds = null,
    int $maxRecipients = 2000
): array {
    $result = ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => 0, 'configured' => webPushConfigured()];
    if (!$result['configured'] || !webPushSubscriptionsReady($db)) {
        return $result;
    }

    /* The user's settings blob rides along so the opt-in check costs no extra
       query per subscription. LEFT JOIN because a user row with a NULL Settings
       column is the normal state for anyone who has never changed a setting. */
    $sql = 'SELECT s.Endpoint, s.P256dhKey, s.AuthKey, s.UserId, u.Settings
              FROM tblPushSubscriptions s
              LEFT JOIN tblUsers u ON u.Id = s.UserId';
    if ($userIds !== null) {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return $result;
        }
        /* Placeholders built from a COUNT — a hardcoded construction, never
           request data (CLAUDE.md rule #5). */
        $sql .= ' WHERE s.UserId IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    }
    $sql .= ' ORDER BY s.Id ASC LIMIT ' . max(1, $maxRecipients);

    $stmt = $db->prepare($sql);
    if ($userIds !== null) {
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Opt-in is a per-USER answer, so resolve it once per user rather than once
       per device — a five-device user would otherwise decode the same blob five
       times to reach the same conclusion. */
    $optInCache = [];
    foreach ($rows as $row) {
        $uid = (int)$row['UserId'];
        if (!array_key_exists($uid, $optInCache)) {
            $settings = json_decode((string)($row['Settings'] ?? ''), true);
            $optInCache[$uid] = webPushKindEnabled(is_array($settings) ? $settings : [], $kind);
        }
        if (!$optInCache[$uid]) {
            $result['skipped']++;
            continue;
        }
        $sent = webPushSend($db, $row, $payload);
        if ($sent['ok']) {
            $result['sent']++;
        } elseif (($sent['status'] ?? '') === 'pruned') {
            $result['pruned']++;
        } else {
            $result['failed']++;
        }
    }
    return $result;
}
