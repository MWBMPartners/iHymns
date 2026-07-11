<?php
/**
 * iHymns — APNs bridge: ES256 provider-token JWT (#1410)
 *
 * Exercises includes/apns.php's _apnsBuildJwt() with a LOCALLY-GENERATED EC
 * P-256 test keypair — never a real Apple-issued key, never a network call.
 * Mirrors tests/php/test-apple-client-secret.php's structure (the sibling
 * SIWA client_secret JWT test) but asserts the DIFFERENT claim shape APNs
 * provider tokens actually use (iss/iat only — no exp/aud/sub), and proves
 * the DER->JOSE signature conversion round-trips correctly by re-DER-
 * encoding the raw r‖s signature and verifying it against the public key —
 * the same "DER->JOSE trap" apple-backend-auth-plan.md §3.4 documents.
 *
 *   php tests/php/test-apns-jwt.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/apns.php';

$failures = 0;
$passed = 0;

function _tajAssert(bool $cond, string $label): void
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

function _tajB64UrlDecode(string $s): string
{
    $t = strtr($s, '-_', '+/');
    $pad = (4 - (strlen($t) % 4)) % 4;
    $decoded = base64_decode($t . str_repeat('=', $pad));
    return $decoded === false ? '' : $decoded;
}

/** DER helpers for the round-trip re-encode step (deliberately independent
 *  of apple_siwa.php's own private DER helpers — this test proves the
 *  OUTPUT is standards-conformant, not merely "internally consistent"). */
function _tajDerLen(int $len): string
{
    if ($len < 0x80) { return chr($len); }
    $b = '';
    while ($len > 0) { $b = chr($len & 0xFF) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function _tajDerInt(string $raw): string
{
    $raw = ltrim($raw, "\x00");
    if ($raw === '') { $raw = "\x00"; }
    if ((ord($raw[0]) & 0x80) !== 0) { $raw = "\x00" . $raw; }
    return "\x02" . _tajDerLen(strlen($raw)) . $raw;
}
function _tajDerEncodeEcdsa(string $r, string $s): string
{
    $body = _tajDerInt($r) . _tajDerInt($s);
    return "\x30" . _tajDerLen(strlen($body)) . $body;
}

/* ---------------------------------------------------------------------- *
 * Fixture: a fresh EC P-256 keypair (never a real Apple-issued key).
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
$KEY_ID  = 'APNSKEY123';
$NOW = time();

/* ---------------------------------------------------------------------- *
 * 1. Happy path — mint + shape (iss/iat ONLY — no exp/aud/sub, unlike the
 *    sibling SIWA client_secret JWT).
 * ---------------------------------------------------------------------- */
$jwt = _apnsBuildJwt($TEAM_ID, $KEY_ID, $ecPrivatePem, $NOW);
_tajAssert($jwt !== null, 'APNs provider JWT minted');

$parts = explode('.', (string)$jwt);
_tajAssert(count($parts) === 3, 'JWT has 3 JWS segments');
[$h64, $p64, $s64] = $parts + [null, null, null];

$header = json_decode(_tajB64UrlDecode((string)$h64), true);
_tajAssert(is_array($header) && $header['alg'] === 'ES256', 'header alg is ES256');
_tajAssert(is_array($header) && $header['kid'] === $KEY_ID, 'header kid matches the APNs Key ID');
_tajAssert(is_array($header) && count($header) === 2, 'header carries ONLY alg + kid (no extra claims)');

$claims = json_decode(_tajB64UrlDecode((string)$p64), true);
_tajAssert(is_array($claims) && $claims['iss'] === $TEAM_ID, 'claims iss is the Team ID');
_tajAssert(is_array($claims) && is_int($claims['iat']) && $claims['iat'] === $NOW, 'claims iat is the injected now');
_tajAssert(is_array($claims) && !array_key_exists('exp', $claims), 'claims carry NO exp (APNs provider tokens are exp-less, unlike SIWA client_secret)');
_tajAssert(is_array($claims) && !array_key_exists('aud', $claims), 'claims carry NO aud');
_tajAssert(is_array($claims) && !array_key_exists('sub', $claims), 'claims carry NO sub');
_tajAssert(is_array($claims) && count($claims) === 2, 'claims carry ONLY iss + iat');

/* ---------------------------------------------------------------------- *
 * 2. Raw signature is exactly 64 bytes (32-byte r + 32-byte s, P-256).
 * ---------------------------------------------------------------------- */
$rawSig = _tajB64UrlDecode((string)$s64);
_tajAssert(strlen($rawSig) === 64, 'raw ES256 signature is exactly 64 bytes');

/* ---------------------------------------------------------------------- *
 * 3. DER->JOSE round-trip: re-DER-encode r‖s and verify against the public
 *    key — proves the shared _appleSiwaDerEcdsaToRaw() conversion this
 *    function reuses (rather than re-forking) is correct here too.
 * ---------------------------------------------------------------------- */
$r = substr($rawSig, 0, 32);
$s = substr($rawSig, 32, 32);
$reDerEncoded = _tajDerEncodeEcdsa($r, $s);
$verifyResult = openssl_verify("$h64.$p64", $reDerEncoded, (string)$ecPublicPem, OPENSSL_ALGO_SHA256);
_tajAssert($verifyResult === 1, 'raw r‖s signature re-DER-encodes and verifies against the EC public key');

/* ---------------------------------------------------------------------- *
 * 4. Reject paths.
 * ---------------------------------------------------------------------- */
$rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsaKey, $rsaPem);
_tajAssert(_apnsBuildJwt($TEAM_ID, $KEY_ID, (string)$rsaPem, $NOW) === null, 'RSA key rejected (must be EC P-256)');
_tajAssert(_apnsBuildJwt($TEAM_ID, $KEY_ID, 'not a real PEM at all', $NOW) === null, 'garbage PEM rejected');
_tajAssert(_apnsBuildJwt('', $KEY_ID, $ecPrivatePem, $NOW) === null, 'empty teamId rejected');
_tajAssert(_apnsBuildJwt($TEAM_ID, '', $ecPrivatePem, $NOW) === null, 'empty keyId rejected');
_tajAssert(_apnsBuildJwt($TEAM_ID, $KEY_ID, '', $NOW) === null, 'empty PEM rejected');

/* ---------------------------------------------------------------------- *
 * 5. apnsConfigured() / apnsCredentials() stay dormant (empty) with no
 *    tblAppSettings row reachable in this standalone CLI context — proves
 *    the "no key provisioned = guaranteed no-op" invariant the #1410 task
 *    brief requires, without needing a database at all.
 * ---------------------------------------------------------------------- */
_tajAssert(apnsConfigured() === false, 'apnsConfigured() is false with no getAppSetting()/DB context (dormant by default)');
$creds = apnsCredentials();
_tajAssert($creds['teamId'] === '' && $creds['keyId'] === '' && $creds['p8Pem'] === '', 'apnsCredentials() resolves all-empty with no settings source reachable');

/* ---------------------------------------------------------------------- *
 * 6. Vocabulary + endpoint-host guards (pure, no DB).
 * ---------------------------------------------------------------------- */
_tajAssert(apnsEndpointHost('sandbox') === 'api.sandbox.push.apple.com', 'sandbox env resolves to the sandbox APNs host');
_tajAssert(apnsEndpointHost('production') === 'api.push.apple.com', 'production env resolves to the production APNs host');
_tajAssert(in_array('device', APNS_KINDS, true) && in_array('liveActivity', APNS_KINDS, true), 'APNS_KINDS carries both device and liveActivity');
_tajAssert(in_array('sandbox', APNS_ENVIRONMENTS, true) && in_array('production', APNS_ENVIRONMENTS, true), 'APNS_ENVIRONMENTS carries both sandbox and production');

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
