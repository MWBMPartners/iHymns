<?php

/**
 * iHymns — RFC 8628 device-code pairing helpers + endpoint hygiene (#1407)
 *
 * Two parts:
 *   1. Exercises the PURE functions in includes/device_code.php directly
 *      (deviceCode_normaliseUserCode(), deviceCode_generateUserCode()) — no
 *      DB, no superglobals, safe to require_once like service_mode.php
 *      (mirrors test-service-state-clean.php's approach).
 *   2. Source-greps api.php's five `auth_device_code_*` case bodies for the
 *      load-bearing structural properties this PR's spec calls out:
 *        - every case is authDeviceCodesTableExists()-gated (dormancy, rule
 *          #19/#28) before touching tblAuthDeviceCodes
 *        - the approve/deny case calls validateCsrfRequest() (rule #29)
 *        - NO breadcrumb (logActivity() call) inside any of the five cases
 *          — or inside includes/device_code.php — ever passes the
 *          device_code/user_code/hash variables as a detail value (the
 *          "IDs only, never the code or a digest of it" contract).
 *
 *   php tests/php/test-device-code.php
 *
 * Exit status 0 = clean, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/device_code.php';

$failures = 0;
$passed = 0;

function _tdcAssert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/* -------------------------------------------------------------------------
 * 1. deviceCode_normaliseUserCode()
 * ------------------------------------------------------------------------- */

_tdcAssert(deviceCode_normaliseUserCode('ABCDEFGH') === 'ABCD-EFGH', 'flat 8-char code gains the canonical dash');
_tdcAssert(deviceCode_normaliseUserCode('ABCD-EFGH') === 'ABCD-EFGH', 'already-dashed code round-trips unchanged');
_tdcAssert(deviceCode_normaliseUserCode('abcd-efgh') === 'ABCD-EFGH', 'lowercase is upper-cased');
_tdcAssert(deviceCode_normaliseUserCode(' ABCD EFGH ') === 'ABCD-EFGH', 'stray whitespace is stripped');
_tdcAssert(deviceCode_normaliseUserCode('') === '', 'empty input -> rejected');
_tdcAssert(deviceCode_normaliseUserCode('ABCDEFG') === '', '7-char (too short) -> rejected');
_tdcAssert(deviceCode_normaliseUserCode('ABCDEFGHJ') === '', '9-char (too long) -> rejected');
_tdcAssert(deviceCode_normaliseUserCode('ABCDEFGI') === '', "'I' (ambiguous glyph, not in Crockford alphabet) -> rejected");
_tdcAssert(deviceCode_normaliseUserCode('ABCDEFG0') === '', "'0' (ambiguous glyph, not in Crockford alphabet) -> rejected");
_tdcAssert(deviceCode_normaliseUserCode(['ABCDEFGH']) === '', 'array input (type-confusion attempt) -> rejected, not a fatal');
_tdcAssert(deviceCode_normaliseUserCode(null) === '', 'null input -> rejected, not a fatal');

/* -------------------------------------------------------------------------
 * 2. deviceCode_generateUserCode()
 * ------------------------------------------------------------------------- */

$seen = [];
$allValid = true;
for ($i = 0; $i < 200; $i++) {
    $code = deviceCode_generateUserCode();
    if (!preg_match('/^[' . preg_quote(SERVICE_MODE_CODE_ALPHABET, '/') . ']{4}-[' . preg_quote(SERVICE_MODE_CODE_ALPHABET, '/') . ']{4}$/', $code)) {
        $allValid = false;
    }
    /* Round-trips through the normaliser unchanged — the minter and the
       validator must agree on the canonical shape. */
    if (deviceCode_normaliseUserCode($code) !== $code) {
        $allValid = false;
    }
    $seen[$code] = true;
}
_tdcAssert($allValid, '200 generated user_codes all match XXXX-XXXX over the Crockford alphabet and round-trip through the normaliser');
/* Not a strict uniqueness guarantee (base32^8 space, birthday-paradox odds
   of a collision in 200 draws are negligible) — a collision here would
   indicate a broken RNG, not bad luck. */
_tdcAssert(count($seen) === 200, '200 generated user_codes were all distinct (RNG sanity, not a uniqueness guarantee)');

/* -------------------------------------------------------------------------
 * 3. Structural checks against api.php's case bodies.
 * ------------------------------------------------------------------------- */

$apiFile = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
$apiSrc = (string)file_get_contents($apiFile);
$deviceCodeSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/appWeb/public_html/includes/device_code.php');

/**
 * Extract one `case '<label>':` … up to the next top-level `case`/`default`
 * at the same 8-space indent (mirrors test-auth-apple-platform.php's
 * _taapCaseBody()). Handles the shared `case 'a': case 'b': { ... }` shape
 * (auth_device_code_approve/_deny) by searching from EITHER label.
 */
function _tdcCaseBody(string $source, string $caseLabel): ?string
{
    $needle = "case '{$caseLabel}':";
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $bodyStart = $start + strlen($needle);
    $nextCase = strpos($source, "\n        case '", $bodyStart);
    $nextDefault = strpos($source, "\n        default:", $bodyStart);
    $ends = array_filter([$nextCase, $nextDefault], static fn($v) => $v !== false);
    $end = $ends ? min($ends) : strlen($source);
    return substr($source, $bodyStart, $end - $bodyStart);
}

$actions = [
    'auth_device_code_request',
    'auth_device_code_poll',
    'auth_device_code_link_lookup',
    'auth_device_code_approve',
    'auth_device_code_deny',
];

$bodies = [];
foreach ($actions as $a) {
    _tdcAssert(str_contains($apiSrc, "case '{$a}':"), "case '{$a}' found in api.php");
    $body = _tdcCaseBody($apiSrc, $a);
    $bodies[$a] = $body ?? '';
}
/* auth_device_code_approve/_deny is ONE combined `case 'a': case 'b': { ... }`
   block (both labels share the single executable body that follows the
   SECOND label) — _tdcCaseBody() on the FIRST label of a fallthrough pair
   only captures the (empty) gap up to the next `case`, not the real body.
   Re-point it at the body actually extracted for the second label. */
if (trim($bodies['auth_device_code_approve']) === '') {
    $bodies['auth_device_code_approve'] = $bodies['auth_device_code_deny'];
}

foreach ($actions as $a) {
    _tdcAssert(
        str_contains($bodies[$a], 'authDeviceCodesTableExists('),
        "case '{$a}' gates on authDeviceCodesTableExists() (rule #19/#28 dormancy)"
    );
}

foreach (['auth_device_code_approve', 'auth_device_code_deny'] as $a) {
    _tdcAssert(
        str_contains($bodies[$a], 'validateCsrfRequest('),
        "case '{$a}' calls validateCsrfRequest() (rule #29)"
    );
    _tdcAssert(
        str_contains($bodies[$a], 'getAuthenticatedUser('),
        "case '{$a}' requires an authenticated user"
    );
}

/* The "IDs only, never the code/digest" contract — scan EVERY logActivity(...)
   call's argument list (balanced-paren extraction, since a detail array can
   itself contain nested parens/brackets) across all five case bodies AND
   includes/device_code.php, and assert none of them mention the raw
   device_code/user_code carriers or a hash/substr of them. */
function _tdcExtractLogActivityCalls(string $source): array
{
    $calls = [];
    $offset = 0;
    while (($pos = strpos($source, 'logActivity(', $offset)) !== false) {
        $parenStart = $pos + strlen('logActivity(') - 1; // points at the '('
        $depth = 0;
        $len = strlen($source);
        $end = null;
        for ($i = $parenStart; $i < $len; $i++) {
            if ($source[$i] === '(') { $depth++; }
            elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end === null) { break; }
        $calls[] = substr($source, $pos, $end - $pos + 1);
        $offset = $end + 1;
    }
    return $calls;
}

/* Identifiers that would leak the shared secret or a fingerprint of it.
   NOT the string 'device_code' itself — every one of these breadcrumbs is
   legitimately NAMED 'auth.device_code.<verb>' (a fixed action-name string
   literal, arg 1), so that substring alone would false-positive on every
   single call. What must NEVER appear is a reference to the VARIABLE
   carrying the raw code/hash, or an inline hash(...) computed for logging
   (a fresh digest would be just as much a fingerprint leak as reusing the
   stored one). */
$forbiddenNeedles = ['$deviceCode', '$userCode', '$hash', 'hash('];
$logActivityCalls = array_merge(
    _tdcExtractLogActivityCalls(implode("\n", $bodies)),
    _tdcExtractLogActivityCalls($deviceCodeSrc)
);
_tdcAssert(count($logActivityCalls) >= 4, 'found at least one logActivity() call per breadcrumb (request/approve/deny/consume) — got ' . count($logActivityCalls));

$leaked = false;
foreach ($logActivityCalls as $call) {
    foreach ($forbiddenNeedles as $needle) {
        if (str_contains($call, $needle)) {
            $leaked = true;
            fwrite(STDERR, "  -> forbidden token '$needle' found in: " . trim(preg_replace('/\s+/', ' ', $call)) . "\n");
        }
    }
}
_tdcAssert(!$leaked, 'no logActivity() call anywhere in the device-code cases (or includes/device_code.php) references the device_code, user_code, or a hash/substr of either');

/* Polls are never logged for the ROUTINE outcomes (pending/slow_down/denied/
   expired) — mirrors service_poll's "polls never logged" convention
   (includes/device_code.php header doc-block). The ONLY logActivity() calls
   in the whole poll body are the terminal ones on the token-mint path: the
   success breadcrumb + the (rare) integrity-guard failure breadcrumb — both
   named 'auth.device_code.consume', never a per-poll entry for the four
   early-return error replies above them. */
$pollLogCalls = _tdcExtractLogActivityCalls($bodies['auth_device_code_poll']);
_tdcAssert(count($pollLogCalls) === 2, "case 'auth_device_code_poll' calls logActivity() exactly twice (success + integrity-guard failure), never per-poll for pending/slow_down/denied/expired — got " . count($pollLogCalls));
$pollLogNames = array_map(static fn($c) => str_contains($c, "'auth.device_code.consume'"), $pollLogCalls);
_tdcAssert(!in_array(false, $pollLogNames, true), "every logActivity() call in case 'auth_device_code_poll' is tagged 'auth.device_code.consume'");

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
