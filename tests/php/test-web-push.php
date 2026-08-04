<?php

/**
 * iHymns — Web Push: VAPID (RFC 8292) + payload encryption (RFC 8291) (#311, #1671 F6)
 *
 * THE POINT OF THIS FILE
 * ----------------------
 * `includes/web_push.php` hand-rolls two cryptographic specifications, because
 * this project has no Composer and there is no `web-push-php` to install. A
 * crypto implementation that merely LOOKS right is worse than an absent one, so
 * the assertions here do not read the source — they run it against an
 * INDEPENDENT ORACLE:
 *
 *   §2 feeds **RFC 8291 §5's own published example** — the RFC fixes the
 *      plaintext, both keypairs, the auth secret and the salt, and prints the
 *      exact expected ciphertext — through `webPushEncrypt()` and demands the
 *      byte string back. A self-consistent encrypt-then-decrypt round trip
 *      would prove only that the file agrees with itself, which is precisely
 *      the sort of evidence that has been wrong-but-green on this branch four
 *      times.
 *
 *   §3 SIGNS a VAPID JWT and then VERIFIES it with `openssl_verify()` against
 *      the public half, after converting the JOSE `r||s` signature back to DER.
 *      A signature that is merely well-SHAPED cannot pass that.
 *
 * ⚠️ WHAT THIS FILE DOES **NOT** PROVE
 * ------------------------------------
 * There is no network and no browser in this container. **No push has ever been
 * delivered to a real device from this code.** `webPushSend()`'s HTTP request,
 * the service worker's `push` handler, the subscription round trip and every
 * push service's own acceptance of these headers are exercised by NOTHING here.
 * A green run means "the JWT and the ciphertext are correct". It does not mean
 * "push works". Delivery to a real device is this feature's acceptance bar and
 * it has not been reached.
 *
 *   php tests/php/test-web-push.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @link https://www.rfc-editor.org/rfc/rfc8291#section-5  the example vector §2 uses
 * @link https://www.rfc-editor.org/rfc/rfc8292#section-2  VAPID claims + header
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/web_push.php';
/* §9 calls appSettingValueForStorage() — the fail-closed secret-storage rule
   the VAPID private key travels through. Required here rather than by
   web_push.php, which deliberately has no dependency on the crypto engine:
   the SENDER never stores a secret, only reads one via getAppSetting(). */
require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/secret_crypto.php';

$failures = 0;
$passed = 0;

function _wpAssert(bool $cond, string $label): void
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

/** base64url decode, for the RFC's own printed values. */
function _wpB64d(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) { $s .= str_repeat('=', 4 - $pad); }
    return (string)base64_decode($s, true);
}

/** base64url encode, unpadded — for comparing against the RFC's printed output. */
function _wpB64e(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/* ====================================================================== *
 * 1. KEY MATERIAL — raw <-> PEM round trips
 * ====================================================================== */

echo "\n-- key material --\n";

$kp = webPushGenerateVapidKeys();
_wpAssert(is_array($kp), 'webPushGenerateVapidKeys() produces a keypair');

$rawPub  = _wpB64d($kp['publicKey']);
$rawPriv = _wpB64d($kp['privateKey']);
_wpAssert(strlen($rawPub) === 65, 'the public key is 65 bytes (uncompressed P-256 point)');
_wpAssert($rawPub[0] === "\x04", 'the public key starts with the 0x04 uncompressed-point marker');
_wpAssert(strlen($rawPriv) === 32, 'the private key is exactly 32 bytes (left-padded when OpenSSL trims)');
_wpAssert(
    $kp['publicKey'] === rtrim($kp['publicKey'], '=') && !str_contains($kp['publicKey'], '+'),
    'keys are emitted base64url and UNPADDED (what applicationServerKey expects)'
);

/* The rebuilt PEM must load AND be the same key — a PEM that merely parses but
   describes a different point would sign tokens the push service rejects. */
$pem = webPushRawPrivateToPem($rawPriv, $rawPub);
_wpAssert(is_string($pem) && str_contains($pem, 'BEGIN EC PRIVATE KEY'), 'a raw scalar rebuilds into a SEC 1 PEM');
$loaded = openssl_pkey_get_private((string)$pem);
_wpAssert($loaded !== false, 'the rebuilt private PEM LOADS in OpenSSL');
if ($loaded !== false) {
    _wpAssert(
        webPushRawPublicFromKey($loaded) === $rawPub,
        'the rebuilt key yields the SAME public point (not merely a valid one)'
    );
}

$pubPem = webPushRawPublicToPem($rawPub);
_wpAssert(is_string($pubPem) && str_contains($pubPem, 'BEGIN PUBLIC KEY'), 'a raw point wraps into an SPKI PEM');
_wpAssert(openssl_pkey_get_public((string)$pubPem) !== false, 'the SPKI PEM loads in OpenSSL');

/* Malformed inputs must be REFUSED, not guessed at. A 64-byte "point" is the
   real-world failure this guards: OpenSSL strips leading zero bytes from X and
   Y, so an unpadded concatenation is 64 bytes roughly one key in 256. */
_wpAssert(webPushRawPublicToPem(str_repeat('x', 64)) === null, 'a 64-byte point is rejected');
_wpAssert(webPushRawPublicToPem("\x03" . str_repeat('x', 64)) === null, 'a COMPRESSED point (0x03) is rejected');
_wpAssert(webPushRawPublicToPem('') === null, 'an empty public key is rejected');
_wpAssert(webPushRawPrivateToPem(str_repeat('x', 31), $rawPub) === null, 'a 31-byte private scalar is rejected');
_wpAssert(webPushRawPrivateToPem($rawPriv, str_repeat('x', 64)) === null, 'a malformed public half is rejected');

/* Two generations must differ — a "random" generator that repeats would make
   every install share a key and every rotation a no-op. */
$kp2 = webPushGenerateVapidKeys();
_wpAssert($kp2['privateKey'] !== $kp['privateKey'], 'two generated keypairs differ');

/* ⭐ THE LEADING-ZERO CASE, TESTED DETERMINISTICALLY.
   OpenSSL strips leading zero bytes from X, Y and d, so roughly one coordinate
   in 256 comes back 31 bytes and an unpadded concatenation yields a 64-byte
   "point" every push service rejects — silently, and only sometimes, which
   reads as a flaky push service rather than a bug here.
   A mutation deleting the padding LEFT THIS SUITE GREEN while it was only
   exercised through generated keys: a property that fires on 1 key in 128 is
   not tested by generating one key. It is tested by calling the function that
   owns it, which is why webPushPadScalar() is a named, exported step. */
_wpAssert(strlen(webPushPadScalar(str_repeat("\x11", 31))) === 32, 'a 31-byte scalar pads to 32');
_wpAssert(webPushPadScalar(str_repeat("\x11", 31))[0] === "\x00", 'the padding goes on the LEFT (big-endian)');
_wpAssert(webPushPadScalar(str_repeat("\x11", 31)) === "\x00" . str_repeat("\x11", 31), 'the original bytes are preserved after the pad');
_wpAssert(strlen(webPushPadScalar(str_repeat("\x11", 30))) === 32, 'a 30-byte scalar pads to 32');
_wpAssert(strlen(webPushPadScalar("\x01")) === 32, 'a 1-byte scalar pads to 32');
_wpAssert(webPushPadScalar(str_repeat("\x11", 32)) === str_repeat("\x11", 32), 'a full 32-byte scalar is unchanged');

/* ====================================================================== *
 * 2. RFC 8291 §5 — THE PUBLISHED EXAMPLE VECTOR
 *
 * This is the load-bearing section of the whole file. Every input below is
 * printed in the RFC; so is the expected output. Reproducing it byte-for-byte
 * is INDEPENDENT evidence that the ECDH, the two HKDF stages, the record
 * framing and the AES-128-GCM call are all correct — evidence this codebase
 * cannot manufacture for itself.
 * ====================================================================== */

echo "\n-- RFC 8291 section 5 example vector --\n";

$rfcPlaintext = 'When I grow up, I want to be a watermelon';
$rfcSalt      = _wpB64d('DGv6ra1nlYgDCS1FRnbzlw');
$rfcAsPublic  = _wpB64d('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$rfcAsPrivate = _wpB64d('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$rfcUaPublic  = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$rfcAuth      = 'BTBZMqHH6r4Tts7J_aSIgg';
$rfcExpected  = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vC'
              . 'YLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXL'
              . 'WyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

/* Sanity-check the FIXTURE before trusting the comparison: a mistyped constant
   would make a failure look like an implementation bug (the #1661 lesson — an
   assertion that failed because of its fixture, not its code). */
_wpAssert(strlen($rfcSalt) === 16, 'fixture: the RFC salt decodes to 16 bytes');
_wpAssert(strlen($rfcAsPublic) === 65, 'fixture: the RFC application-server public key decodes to 65 bytes');
_wpAssert(strlen($rfcAsPrivate) === 32, 'fixture: the RFC application-server private key decodes to 32 bytes');
_wpAssert(strlen(_wpB64d($rfcAuth)) === 16, 'fixture: the RFC auth secret decodes to 16 bytes');

$encrypted = webPushEncrypt(
    $rfcPlaintext,
    $rfcUaPublic,
    $rfcAuth,
    $rfcSalt,
    ['private' => $rfcAsPrivate, 'public' => $rfcAsPublic]
);
_wpAssert($encrypted !== null, 'the RFC example encrypts without error');
_wpAssert(
    $encrypted !== null && _wpB64e($encrypted) === $rfcExpected,
    '⭐ webPushEncrypt() reproduces RFC 8291 §5\'s published ciphertext BYTE-FOR-BYTE'
);

/* Frame the same output structurally, so a future failure says WHICH part
   drifted rather than just "the bytes differ". */
if ($encrypted !== null) {
    _wpAssert(substr($encrypted, 0, 16) === $rfcSalt, 'the record header starts with the 16-byte salt (RFC 8188 §2.1)');
    _wpAssert(unpack('N', substr($encrypted, 16, 4))[1] === 4096, 'the record size field is 4096');
    _wpAssert(ord($encrypted[20]) === 65, 'the key-id length field is 65');
    _wpAssert(substr($encrypted, 21, 65) === $rfcAsPublic, 'the key id IS the ephemeral public key (RFC 8291 §4)');
    /* header(86) + plaintext(40) + delimiter(1) + tag(16) */
    _wpAssert(strlen($encrypted) === 86 + strlen($rfcPlaintext) + 1 + 16, 'the body length matches header + record + tag');
}

/* ====================================================================== *
 * 3. THE ENCRYPTION'S OWN REFUSALS + FRESHNESS
 * ====================================================================== */

echo "\n-- encryption refusals + freshness --\n";

$goodUa   = $rfcUaPublic;
$goodAuth = $rfcAuth;

_wpAssert(webPushEncrypt('hi', 'not base64url!!', $goodAuth) === null, 'an undecodable p256dh key is refused');
_wpAssert(webPushEncrypt('hi', _wpB64e(str_repeat('x', 64)), $goodAuth) === null, 'a 64-byte p256dh key is refused');
_wpAssert(webPushEncrypt('hi', $goodUa, _wpB64e(str_repeat('x', 12))) === null,
    'a short auth secret is refused (RFC 8291 §3.2 fixes it at 16 octets)');
_wpAssert(webPushEncrypt('hi', $goodUa, $goodAuth, str_repeat('s', 15)) === null, 'a 15-byte salt is refused');

/* THE CAP IS A REJECTION, NEVER A TRUNCATION — a half-JSON payload is a payload
   the service worker cannot parse, and the failure surfaces on the device as
   "nothing happened". */
$cap = webPushMaxPayloadBytes();
_wpAssert(is_string(webPushEncrypt(str_repeat('a', $cap), $goodUa, $goodAuth)), 'a payload exactly at the cap encrypts');
_wpAssert(webPushEncrypt(str_repeat('a', $cap + 1), $goodUa, $goodAuth) === null,
    'a payload one byte over the cap is REFUSED, not truncated');

/* FRESHNESS. A reused salt or a reused ephemeral key repeats a (key, nonce)
   pair, which is the classic catastrophic GCM failure. Two encryptions of the
   SAME plaintext must therefore differ. */
$a = webPushEncrypt('same message', $goodUa, $goodAuth);
$b = webPushEncrypt('same message', $goodUa, $goodAuth);
_wpAssert(is_string($a) && is_string($b) && $a !== $b,
    'two encryptions of identical plaintext DIFFER (fresh salt + fresh ephemeral key per message)');
_wpAssert(is_string($a) && is_string($b) && substr($a, 0, 16) !== substr($b, 0, 16), 'the salt is fresh each time');
_wpAssert(is_string($a) && is_string($b) && substr($a, 21, 65) !== substr($b, 21, 65),
    'the ephemeral application-server key is fresh each time (NOT the VAPID identity key)');

/* ====================================================================== *
 * 4. VAPID — signed here, VERIFIED with openssl_verify()
 * ====================================================================== */

echo "\n-- VAPID JWT (RFC 8292) --\n";

$now = 1800000000;
$jwt = webPushBuildVapidJwt('https://push.example.net', 'mailto:ops@example.com',
    $kp['privateKey'], $kp['publicKey'], $now);
_wpAssert(is_string($jwt), 'a VAPID JWT is minted');

$parts = explode('.', (string)$jwt);
_wpAssert(count($parts) === 3, 'the JWT has three dot-separated parts');

$header = json_decode(_wpB64d($parts[0]), true);
$claims = json_decode(_wpB64d($parts[1]), true);
_wpAssert(($header['alg'] ?? '') === 'ES256', 'the header declares alg ES256');
_wpAssert(($header['typ'] ?? '') === 'JWT', 'the header declares typ JWT');
_wpAssert(($claims['aud'] ?? '') === 'https://push.example.net', 'aud is the push origin');
_wpAssert(($claims['sub'] ?? '') === 'mailto:ops@example.com', 'sub is the operator contact');
_wpAssert(($claims['exp'] ?? 0) > $now, 'exp is in the future');
_wpAssert(($claims['exp'] ?? 0) <= $now + 86400, 'exp is at most 24 hours out (RFC 8292 §2)');
/* The cap must be APPLIED, not trusted from the caller — a mistaken argument
   should degrade to "shorter" rather than "refused by every push service". */
$longJwt = webPushBuildVapidJwt('https://p.example', 'mailto:a@b.co', $kp['privateKey'], $kp['publicKey'], $now, 999999);
$longClaims = json_decode(_wpB64d(explode('.', (string)$longJwt)[1]), true);
_wpAssert(($longClaims['exp'] ?? 0) <= $now + 86400, 'an over-long requested TTL is CAPPED at 24 hours, not passed through');

/* ⭐ THE SIGNATURE IS VERIFIED, not merely measured. The JOSE r||s is converted
   back to the DER SEQUENCE openssl_verify() expects. */
$rawSig = _wpB64d($parts[2]);
_wpAssert(strlen($rawSig) === 64, 'the signature is a 64-byte JOSE r||s, not a DER blob');

/** Minimal DER INTEGER: strip leading zeros, re-add one if the high bit is set. */
function _wpDerInt(string $raw): string
{
    $raw = ltrim($raw, "\x00");
    if ($raw === '') { $raw = "\x00"; }
    if (ord($raw[0]) & 0x80) { $raw = "\x00" . $raw; }
    return "\x02" . chr(strlen($raw)) . $raw;
}
$derSig = _wpDerInt(substr($rawSig, 0, 32)) . _wpDerInt(substr($rawSig, 32, 32));
$derSig = "\x30" . chr(strlen($derSig)) . $derSig;

$verifyKey = openssl_pkey_get_public((string)webPushRawPublicToPem($rawPub));
$verified = ($verifyKey !== false)
    ? openssl_verify($parts[0] . '.' . $parts[1], $derSig, $verifyKey, OPENSSL_ALGO_SHA256)
    : -1;
_wpAssert($verified === 1, '⭐ the VAPID signature VERIFIES against the public key (openssl_verify)');

/* …and it must NOT verify against a DIFFERENT key, or the check above is
   vacuous — a verifier that accepts everything proves nothing. */
$otherPub = openssl_pkey_get_public((string)webPushRawPublicToPem(_wpB64d($kp2['publicKey'])));
_wpAssert(
    $otherPub !== false && openssl_verify($parts[0] . '.' . $parts[1], $derSig, $otherPub, OPENSSL_ALGO_SHA256) !== 1,
    'the signature does NOT verify against an unrelated key (the check is not vacuous)'
);
/* …and a tampered signing input must fail. */
_wpAssert(
    $verifyKey !== false
        && openssl_verify($parts[0] . '.' . $parts[1] . 'x', $derSig, $verifyKey, OPENSSL_ALGO_SHA256) !== 1,
    'the signature does NOT verify over tampered content'
);

/* Refusals. */
_wpAssert(webPushBuildVapidJwt('', 'mailto:a@b.co', $kp['privateKey'], $kp['publicKey'], $now) === null,
    'no audience => no token');
_wpAssert(webPushBuildVapidJwt('https://p.example', '', $kp['privateKey'], $kp['publicKey'], $now) === null,
    'no subject => no token');
_wpAssert(webPushBuildVapidJwt('https://p.example', 'mailto:a@b.co', 'not-base64!!', $kp['publicKey'], $now) === null,
    'an undecodable private key => no token');
_wpAssert(webPushBuildVapidJwt('https://p.example', 'mailto:a@b.co', _wpB64e(str_repeat('x', 31)), $kp['publicKey'], $now) === null,
    'a wrong-length private key => no token');

/* The Authorization header shape (RFC 8292 §3.1). */
$authHeader = webPushAuthorizationHeader('https://push.example.net', 'mailto:ops@example.com',
    $kp['privateKey'], $kp['publicKey'], $now);
_wpAssert(is_string($authHeader) && str_starts_with((string)$authHeader, 'vapid t='),
    'the Authorization header uses the `vapid t=…, k=…` form');
_wpAssert(is_string($authHeader) && str_contains((string)$authHeader, ', k=' . $kp['publicKey']),
    'the header carries the base64url public key in k=');

/* ====================================================================== *
 * 5. AUDIENCE + SUBJECT
 * ====================================================================== */

echo "\n-- audience + subject --\n";

_wpAssert(webPushAudience('https://fcm.googleapis.com/fcm/send/abc123') === 'https://fcm.googleapis.com',
    'the audience is scheme+host ONLY — the secret endpoint path is never signed into a header');
_wpAssert(webPushAudience('https://updates.push.services.mozilla.com/wpush/v2/xyz') === 'https://updates.push.services.mozilla.com',
    'a Mozilla endpoint resolves to its origin');
_wpAssert(webPushAudience('https://Example.COM/x') === 'https://example.com', 'the host is lower-cased');
_wpAssert(webPushAudience('https://example.com:8443/x') === 'https://example.com:8443', 'a non-default port is kept');
_wpAssert(webPushAudience('https://example.com:443/x') === 'https://example.com', 'the default port is omitted');
_wpAssert(webPushAudience('http://example.com/x') === null, 'a plain-http endpoint is refused (no silent downgrade)');
_wpAssert(webPushAudience('not a url') === null, 'a malformed endpoint is refused');
_wpAssert(webPushAudience('') === null, 'an empty endpoint is refused');

_wpAssert(webPushSubjectValid('mailto:ops@example.com') === true, 'a mailto: subject is valid (RFC 8292 §2)');
_wpAssert(webPushSubjectValid('https://ihymns.app/contact') === true, 'an https: subject is valid');
_wpAssert(webPushSubjectValid('ops@example.com') === false, 'a bare email address is NOT valid');
_wpAssert(webPushSubjectValid('http://example.com') === false, 'an http: subject is NOT valid');
_wpAssert(webPushSubjectValid('') === false, 'an empty subject is NOT valid');

/* ====================================================================== *
 * 6. STATUS HANDLING — branch on the NUMBER, never the prose (rule #35)
 * ====================================================================== */

echo "\n-- response status handling --\n";

_wpAssert(webPushShouldPrune(404) === true, '404 prunes the subscription');
_wpAssert(webPushShouldPrune(410) === true, '410 Gone prunes the subscription');
/* A prune is IRREVERSIBLE: the user simply stops receiving pushes with nothing
   to indicate why. So every transient status must NOT prune. */
foreach ([200, 201, 202, 400, 401, 403, 413, 429, 500, 502, 503, 504, 0] as $s) {
    _wpAssert(webPushShouldPrune($s) === false, "HTTP $s does NOT prune (a prune is irreversible)");
}
_wpAssert(webPushIsSuccess(201) === true, '201 Created is success (the specified answer)');
_wpAssert(webPushIsSuccess(200) === true, '200 is accepted as success');
_wpAssert(webPushIsSuccess(202) === true, '202 is accepted as success');
foreach ([204, 400, 404, 410, 429, 500] as $s) {
    _wpAssert(webPushIsSuccess($s) === false, "HTTP $s is not success");
}

/* ====================================================================== *
 * 7. THE KIND REGISTRY — one line to add a kind
 * ====================================================================== */

echo "\n-- kind registry + opt-ins --\n";

$kinds = webPushKinds();
_wpAssert(count($kinds) >= 1, 'the kind registry is populated');
foreach ($kinds as $key => $meta) {
    _wpAssert(preg_match('/^[a-z][a-z0-9_]*\z/', $key) === 1, "kind key '$key' is lower-case ASCII");
    _wpAssert(is_string($meta[0]) && trim($meta[0]) !== '', "kind '$key' has a label a checkbox can show");
    _wpAssert(is_string($meta[1]) && trim($meta[1]) !== '', "kind '$key' has a description");
    _wpAssert(is_bool($meta[2]), "kind '$key' has a boolean default");
}
_wpAssert(webPushKindValid('announcement') === true, 'a registered kind validates');
_wpAssert(webPushKindValid('not_a_kind') === false, 'an unregistered kind does not validate');

/* Opt-in resolution — the behaviour, asked as a question. */
_wpAssert(webPushKindEnabled([], 'announcement') === $kinds['announcement'][2],
    'a user who has never chosen gets the kind\'s DEFAULT');
_wpAssert(webPushKindEnabled(['ihymns.push' => ['announcement' => false]], 'announcement') === false,
    'an explicit opt-OUT is honoured');
_wpAssert(webPushKindEnabled(['ihymns.push' => ['announcement' => true]], 'announcement') === true,
    'an explicit opt-IN is honoured');
/* STRICT — only boolean true means yes. Reading a stored "0" as truthy is how
   an opt-OUT silently becomes an opt-in. */
foreach ([['0'], [0], [''], [null], ['false'], [[]]] as $v) {
    _wpAssert(
        webPushKindEnabled(['ihymns.push' => ['announcement' => $v[0]]], 'announcement') === false,
        'a non-boolean stored value reads as OFF, never as truthy: ' . var_export($v[0], true)
    );
}
/* An UNKNOWN kind must send to NOBODY. A typo'd kind reaching everybody is the
   worse direction by a wide margin. */
_wpAssert(webPushKindEnabled(['ihymns.push' => ['bogus' => true]], 'bogus') === false,
    'an UNREGISTERED kind is never enabled, even when the user has a stored true');
_wpAssert(webPushKindEnabled(['ihymns.push' => 'not-an-array'], 'announcement') === $kinds['announcement'][2],
    'a corrupt push subtree falls back to the default rather than throwing');
/* Namespace isolation: another product's settings must not answer our question. */
_wpAssert(webPushKindEnabled(['ilyricsdb' => ['announcement' => false]], 'announcement') === $kinds['announcement'][2],
    'a value in ANOTHER namespace does not affect this one (#1671 F5 isolation)');

/* ====================================================================== *
 * 8. PAYLOAD SHAPE — and the cross-file agreement with the service worker
 * ====================================================================== */

echo "\n-- payload shape + the SW agreement --\n";

$payload = json_decode(webPushBuildPayload('announcement', '  Hello  ', '  Body  ', '/songbooks'), true);
_wpAssert($payload['kind'] === 'announcement', 'the payload carries the kind');
_wpAssert($payload['title'] === 'Hello', 'the title is trimmed');
_wpAssert($payload['body'] === 'Body', 'the body is trimmed');
_wpAssert($payload['url'] === '/songbooks', 'a site-relative url passes through');

/* A push notification is an attacker-attractive way to send somebody to another
   origin, and there is no legitimate need to leave the app. */
foreach ([
    'https://evil.example/x' => 'an absolute URL',
    '//evil.example/x'       => 'a protocol-relative URL',
    '\\\\evil.example\\x'    => 'a backslash UNC-style path',
    'javascript:alert(1)'    => 'a javascript: URL',
    ''                       => 'an empty url',
] as $bad => $what) {
    $p = json_decode(webPushBuildPayload('announcement', 'T', '', $bad), true);
    _wpAssert($p['url'] === '/', "$what is replaced with '/'");
}
$unknown = json_decode(webPushBuildPayload('not_a_kind', 'T', ''), true);
_wpAssert($unknown['kind'] === 'announcement', 'an unregistered kind falls back to announcement rather than being emitted');

$long = json_decode(webPushBuildPayload('announcement', str_repeat('T', 500), str_repeat('B', 900)), true);
_wpAssert(mb_strlen($long['title']) === 120, 'the title is bounded to 120 characters');
_wpAssert(mb_strlen($long['body']) === 300, 'the body is bounded to 300 characters');

/* ⭐ THE CROSS-FILE AGREEMENT. The payload builder (PHP) and the service
   worker's `push` handler (JS) must agree on every key, and neither language
   can call the other. Rather than a comment asking somebody to keep them in
   step — a comment is the failure, not the fix (rule #35) — the SW source is
   re-read here and every key the builder ACTUALLY emits is required to appear
   in it. Derived from the builder's own output, so a new key is covered the
   day it is added. */
$swPath = dirname(__DIR__, 2) . '/appWeb/public_html/service-worker.js.php';
$swSrc  = (string)@file_get_contents($swPath);
_wpAssert(strlen($swSrc) > 10000, 'the service worker source was read (a failed read must not read as "all clean")');
$pushBlock = '';
$pushPos = strpos($swSrc, "self.addEventListener('push'");
_wpAssert($pushPos !== false, 'the service worker registers a `push` listener');
if ($pushPos !== false) {
    $endPos = strpos($swSrc, "self.addEventListener('notificationclick'", $pushPos);
    $pushBlock = substr($swSrc, $pushPos, ($endPos !== false ? $endPos : strlen($swSrc)) - $pushPos);
    foreach (array_keys((array)json_decode(webPushBuildPayload('announcement', 'T', 'B', '/x'), true)) as $key) {
        _wpAssert(
            str_contains($pushBlock, 'parsed.' . $key),
            "the SW push handler reads the payload key `$key` the PHP builder emits"
        );
    }
    /* showNotification() must actually be called: a push event whose waitUntil
       resolves without showing anything makes some browsers display their own
       generic notification, or penalise the origin's future pushes. */
    _wpAssert(str_contains($pushBlock, 'showNotification('), 'the SW push handler SHOWS a notification');
    _wpAssert(str_contains($pushBlock, 'event.waitUntil('), 'the SW push handler holds the event open with waitUntil');
}
_wpAssert(str_contains($swSrc, "self.addEventListener('notificationclick'"),
    'the service worker registers a `notificationclick` listener (a notification that does nothing on tap is a dead end)');

/* The icon paths must EXIST. A wrong path is silent — the notification still
   shows, with the browser's generic globe — and this one was wrong on the first
   draft (`/assets/icons/icon-192.png`, a directory that does not exist). */
if ($pushBlock !== '') {
    preg_match_all('#[a-z]+:\s*\'(/assets/[^\']+)\'#', $pushBlock, $iconHits);
    _wpAssert(count($iconHits[1]) >= 2, 'the SW push handler names icon assets (found ' . count($iconHits[1]) . ')');
    foreach (array_unique($iconHits[1]) as $asset) {
        _wpAssert(
            is_file(dirname(__DIR__, 2) . '/appWeb/public_html' . $asset),
            "the notification asset $asset EXISTS on disk"
        );
    }
}

/* ====================================================================== *
 * 9. STRUCTURAL — the things with NO runtime handle here
 * ====================================================================== */

echo "\n-- wiring with no runtime handle --\n";

/** Strip comments so no assertion can be satisfied by PROSE. */
function _wpStrip(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/* The VAPID PRIVATE key must be registered for encryption at rest, and the
   PUBLIC key must NOT be — listing a non-secret makes secretInventory() report
   a false exposure every time it is read. */
$cryptoSrc = _wpStrip((string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/secret_crypto.php'
));
_wpAssert(str_contains($cryptoSrc, "'webpush_vapid_private'"),
    'the VAPID private key is registered in secretSettingKeys() (encrypted at rest, #1466)');
_wpAssert(!str_contains($cryptoSrc, "'webpush_vapid_public'"),
    'the VAPID PUBLIC key is NOT registered as a secret (it is handed to every browser)');

/* appSettingValueForStorage() must FAIL CLOSED. This is the one place a secret
   could be written in the clear, and the property is a BEHAVIOUR, so it is
   called rather than pattern-matched. */
_wpAssert(appSettingValueForStorage('webpush_vapid_public', 'abc', true) === 'abc',
    'a NON-secret key is stored verbatim even when encryption is active');
_wpAssert(appSettingValueForStorage('webpush_vapid_private', 'abc', false) === 'abc',
    'a secret is stored verbatim while the encryption cutover flag is off (pre-cutover no-op)');
_wpAssert(appSettingValueForStorage('webpush_vapid_private', '', true) === '',
    'an EMPTY secret is passed through (\'\' means unset; an envelope for "" is pointless)');
$threw = false;
try {
    /* No master key is loaded in this process, so secretCryptoReady() is false. */
    appSettingValueForStorage('webpush_vapid_private', 'a-real-secret', true);
} catch (\RuntimeException $e) {
    $threw = true;
}
_wpAssert($threw, '⭐ storing a secret with encryption ACTIVE and no master key THROWS (fails closed, never plaintext)');

/* The admin page must be gated by the entitlement its nav entry advertises
   (#1587) — verified rather than assumed, because Batch 6 found ten decorative
   permission keys in this codebase. */
$notifSrc = _wpStrip((string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/manage/notifications.php'
));
_wpAssert(str_contains($notifSrc, "userHasEntitlement('manage_notifications'"),
    'the push admin surface is gated on manage_notifications');
$linksSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/admin-links.php'
);
_wpAssert(str_contains($linksSrc, "'manage_notifications'"),
    'the nav entry advertises the SAME entitlement the page enforces (#1587)');
$entSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/entitlements.php'
);
_wpAssert(str_contains($entSrc, "'manage_notifications'"),
    'manage_notifications is a REAL key in the central entitlement map, not a decorative one');

/* Every push write must go through the shared, fail-closed setting writer. */
_wpAssert(str_contains($notifSrc, 'setAppSetting($db, \'webpush_vapid_private\''),
    'the private key is written through the shared setAppSetting() (never a local INSERT)');
_wpAssert(!preg_match('/INSERT INTO tblAppSettings/', $notifSrc),
    'the push admin page does NOT hand-roll its own tblAppSettings INSERT');

/* configuration.php must delegate to the same rule rather than keeping a copy —
   two implementations of "encrypt secrets at rest" is the duplication whose
   divergence is invisible until a secret is sitting in the clear. */
$confSrc = _wpStrip((string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/manage/configuration.php'
));
_wpAssert(str_contains($confSrc, 'appSettingValueForStorage('),
    'manage/configuration.php delegates to the shared storage rule');
_wpAssert(!str_contains($confSrc, 'refusing to store'),
    'manage/configuration.php no longer carries its own copy of the fail-closed branch');

/* The service worker keeps NATIVE fetch — rule #31's one deliberate exception.
   Importing api-client.js there would be the rule misapplied (different global
   scope, no localStorage, requests are not user-scoped).

   ⚠️ THIS ASSERTION WAS WRONG-BUT-RED ON ITS FIRST DRAFT, which is the same
   failure in the opposite direction. It was `!str_contains($swSrc,
   'api-client.js')` and it flagged the service worker's own COMMENT explaining
   why it does not import api-client — a guard failing on correct code, which is
   how guards get weakened or deleted rather than fixed (rule #34's other half).
   Comments are stripped, and the check now requires an actual IMPORT construct
   rather than a mention of a filename. */
$swCode = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//[^\n]*#m'], '', $swSrc);
_wpAssert(
    preg_match('#(import\s[^\n;]*|importScripts\s*\([^)]*)api-client#', $swCode) !== 1,
    'the service worker does NOT import the window-scope api-client (rule #31\'s one exception)'
);
/* …and the stripper must actually be doing something, or the assertion above is
   passing for the wrong reason. */
_wpAssert(strlen($swCode) < strlen($swSrc),
    'the comment stripper removed something (an inert stripper makes the check above vacuous)');

/* The settings fragment must emit BOTH data-* inputs the client reads, or the
   card renders with no key and no checkboxes and nothing says why. */
$settingsSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/includes/pages/settings.php'
);
foreach (['data-push-card', 'data-vapid-key', 'data-push-kinds'] as $attr) {
    _wpAssert(str_contains($settingsSrc, $attr), "the settings fragment emits $attr");
}
_wpAssert(str_contains($settingsSrc, 'webPushKinds()'),
    'the fragment renders the kind list from the ONE registry, not a hardcoded copy');

/* The client module must be imported by the router — rule #30's pattern. A
   module nobody imports is the silent-no-op class this codebase keeps paying
   for. */
$routerSrc = (string)file_get_contents(
    dirname(__DIR__, 2) . '/appWeb/public_html/js/modules/router.js'
);
_wpAssert(str_contains($routerSrc, "import('./push-notifications.js')"),
    'router.js imports the push module (a module nobody imports never runs)');

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
