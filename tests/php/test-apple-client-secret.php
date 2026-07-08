<?php
/**
 * iHymns — Sign in with Apple: ES256 client_secret minting (#1402)
 *
 * Exercises includes/apple_siwa.php's appleSiwaBuildClientSecret() with a
 * LOCALLY-GENERATED EC P-256 test keypair — never a committed key, never a
 * network call. Verifies the JOSE header/claims shape, the exp-iat=300
 * window, and — most importantly — the DER->JOSE conversion of the raw ES256
 * signature: parses the minted JWT's raw 64-byte r‖s signature, re-encodes it
 * as a DER SEQUENCE{INTEGER r, INTEGER s}, and verifies THAT against the
 * public key with openssl_verify() — proving the DER<->JOSE round-trip both
 * directions (§3.4's "DER->JOSE trap").
 *
 *   php tests/php/test-apple-client-secret.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/apple_siwa.php';

$failures = 0;
$passed = 0;

function _tacsAssert(bool $cond, string $label): void
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

function _tacsB64UrlDecode(string $s): string
{
    $t = strtr($s, '-_', '+/');
    $pad = (4 - (strlen($t) % 4)) % 4;
    $decoded = base64_decode($t . str_repeat('=', $pad));
    return $decoded === false ? '' : $decoded;
}

/** DER helpers for the round-trip re-encode step (deliberately independent
 *  of apple_siwa.php's own private DER helpers — this test proves the OUTPUT
 *  is standards-conformant, not merely "internally consistent"). */
function _tacsDerLen(int $len): string
{
    if ($len < 0x80) { return chr($len); }
    $b = '';
    while ($len > 0) { $b = chr($len & 0xFF) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function _tacsDerInt(string $raw): string
{
    $raw = ltrim($raw, "\x00");
    if ($raw === '') { $raw = "\x00"; }
    if ((ord($raw[0]) & 0x80) !== 0) { $raw = "\x00" . $raw; }
    return "\x02" . _tacsDerLen(strlen($raw)) . $raw;
}
function _tacsDerEncodeEcdsa(string $r, string $s): string
{
    $body = _tacsDerInt($r) . _tacsDerInt($s);
    return "\x30" . _tacsDerLen(strlen($body)) . $body;
}

/* ---------------------------------------------------------------------- *
 * Fixture: a fresh EC P-256 keypair.
 * ---------------------------------------------------------------------- */
$ecKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
if ($ecKey === false) {
    fwrite(STDERR, "FATAL: could not generate a test EC P-256 keypair.\n");
    exit(1);
}
openssl_pkey_export($ecKey, $ecPrivatePem);
$ecDetails = openssl_pkey_get_details($ecKey);
$ecPublicPem = $ecDetails['key'];

$TEAM_ID = 'TEAMID1234';
$KEY_ID  = 'KEYID12345';
$NOW = time();

/* ---------------------------------------------------------------------- *
 * 1. Happy path — mint + shape.
 * ---------------------------------------------------------------------- */
$secret = appleSiwaBuildClientSecret($TEAM_ID, $KEY_ID, IHYMNS_SIWA_CLIENT_ID, $ecPrivatePem, $NOW);
_tacsAssert($secret !== null, 'client secret minted');

$parts = explode('.', (string)$secret);
_tacsAssert(count($parts) === 3, 'client secret has 3 JWS segments');
[$h64, $p64, $s64] = $parts + [null, null, null];

$header = json_decode(_tacsB64UrlDecode((string)$h64), true);
_tacsAssert(is_array($header) && $header['alg'] === 'ES256', 'header alg is ES256');
_tacsAssert(is_array($header) && $header['kid'] === $KEY_ID, 'header kid matches the SIWA Key ID');

$claims = json_decode(_tacsB64UrlDecode((string)$p64), true);
_tacsAssert(is_array($claims) && $claims['iss'] === $TEAM_ID, 'claims iss is the Team ID');
_tacsAssert(is_array($claims) && $claims['sub'] === IHYMNS_SIWA_CLIENT_ID, 'claims sub is the client id');
_tacsAssert(is_array($claims) && $claims['aud'] === 'https://appleid.apple.com', 'claims aud is Apple\'s token endpoint origin');
_tacsAssert(is_array($claims) && is_int($claims['iat']) && is_int($claims['exp']), 'claims iat/exp are integers');
_tacsAssert(is_array($claims) && ($claims['exp'] - $claims['iat']) === 300, 'claims exp - iat === 300 (5 minutes)');

/* ---------------------------------------------------------------------- *
 * 2. Raw signature is exactly 64 bytes (32-byte r + 32-byte s, P-256).
 * ---------------------------------------------------------------------- */
$rawSig = _tacsB64UrlDecode((string)$s64);
_tacsAssert(strlen($rawSig) === 64, 'raw ES256 signature is exactly 64 bytes');

/* ---------------------------------------------------------------------- *
 * 3. DER->JOSE round-trip: re-DER-encode r‖s and verify against the public
 *    key — proves the conversion both directions (§3.4's "DER->JOSE trap").
 * ---------------------------------------------------------------------- */
$r = substr($rawSig, 0, 32);
$s = substr($rawSig, 32, 32);
$reDerEncoded = _tacsDerEncodeEcdsa($r, $s);
$verifyResult = openssl_verify("$h64.$p64", $reDerEncoded, (string)$ecPublicPem, OPENSSL_ALGO_SHA256);
_tacsAssert($verifyResult === 1, 'raw r‖s signature re-DER-encodes and verifies against the EC public key');

/* ---------------------------------------------------------------------- *
 * 4. Reject paths.
 * ---------------------------------------------------------------------- */
$rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsaKey, $rsaPem);
_tacsAssert(appleSiwaBuildClientSecret($TEAM_ID, $KEY_ID, IHYMNS_SIWA_CLIENT_ID, (string)$rsaPem, $NOW) === null, 'RSA key rejected (must be EC P-256)');
_tacsAssert(appleSiwaBuildClientSecret($TEAM_ID, $KEY_ID, IHYMNS_SIWA_CLIENT_ID, 'not a real PEM at all', $NOW) === null, 'garbage PEM rejected');
_tacsAssert(appleSiwaBuildClientSecret('', $KEY_ID, IHYMNS_SIWA_CLIENT_ID, $ecPrivatePem, $NOW) === null, 'empty teamId rejected');
_tacsAssert(appleSiwaBuildClientSecret($TEAM_ID, '', IHYMNS_SIWA_CLIENT_ID, $ecPrivatePem, $NOW) === null, 'empty keyId rejected');
_tacsAssert(appleSiwaBuildClientSecret($TEAM_ID, $KEY_ID, IHYMNS_SIWA_CLIENT_ID, '', $NOW) === null, 'empty PEM rejected');

/* A different (but still EC P-256) curve is out of scope for Apple's SIWA
   keys — Apple only ever issues P-256 — so no separate P-384/P-521 case is
   exercised here; the type+curve-name check in appleSiwaBuildClientSecret()
   is covered by the RSA-key rejection above (same guard clause). */

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
