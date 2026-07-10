<?php
/**
 * iHymns — `?action=auth_apple` platform discriminator + single-audience
 * resolution (#1470 W1)
 *
 * api.php is a 13k+-line dispatcher that executes real request-handling code
 * at file-scope on require (the test-auth-response-shape.php precedent
 * explains why) — so this test:
 *
 *   1. Extracts JUST the pure _authApplePlatformResolve() function body via a
 *      balanced-brace scan and eval()s that single definition — no
 *      dispatcher code runs, no DB, no network — then asserts its full
 *      allow-list behaviour (native/web/absent accepted, everything else
 *      rejected, including type-confusion inputs a tampered JSON body could
 *      send: 0, false, [], 'Native').
 *   2. Source-greps `case 'auth_apple':`'s body to prove the "never try both
 *      auds" invariant structurally: appleSiwaVerifyIdentityToken() is
 *      called EXACTLY ONCE, and that one call site uses the single resolved
 *      $expectedAud variable rather than a literal IHYMNS_SIWA_CLIENT_ID
 *      (which would silently re-introduce "always check native's aud", the
 *      exact regression W1 fixes) or two side-by-side verify attempts.
 *   3. Source-greps that the `platform=web` branch is dormancy-gated by
 *      appleWebLoginEnabledForChannel() BEFORE _authAppleMapAccount() ever
 *      runs — i.e. the dormant/disabled path can't reach the DB writes.
 *
 *   php tests/php/test-auth-apple-platform.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

$apiFile = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
if (!is_readable($apiFile)) {
    fwrite(STDERR, "FATAL: could not read $apiFile\n");
    exit(1);
}
$apiSrc = (string)file_get_contents($apiFile);

$failures = 0;
$passed = 0;

function _taap_assert(bool $cond, string $label): void
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

/**
 * Extract one top-level `function $name(...) { ... }` definition from
 * $source via a balanced-brace scan (mirrors test-auth-response-shape.php's
 * _tarsExtractFunction() — the codebase's array literals use `[...]`, never
 * `{...}`, so simple brace counting is safe here).
 */
function _taapExtractFunction(string $source, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $bracePos = strpos($source, '{', $start);
    if ($bracePos === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($source[$i] === '{') { $depth++; }
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

$fnSrc = _taapExtractFunction($apiSrc, '_authApplePlatformResolve');
if ($fnSrc === null) {
    fwrite(STDERR, "FATAL: could not extract _authApplePlatformResolve() from api.php — has it been renamed or removed?\n");
    exit(1);
}

/* eval() a single, isolated function DEFINITION — no dispatcher code, no
   superglobals, no I/O. Safe: this is source we just extracted and are
   about to assert the shape of, not arbitrary/untrusted input. */
eval($fnSrc);

if (!function_exists('_authApplePlatformResolve')) {
    fwrite(STDERR, "FATAL: eval() of the extracted function did not define _authApplePlatformResolve().\n");
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * 1. Allow-list truth table.
 * ---------------------------------------------------------------------- */
_taap_assert(_authApplePlatformResolve(null) === ['ok' => true, 'platform' => 'native'], 'absent (null) platform → ok, defaults to "native"');
_taap_assert(_authApplePlatformResolve('') === ['ok' => true, 'platform' => 'native'], 'empty-string platform → ok, defaults to "native"');
_taap_assert(_authApplePlatformResolve('native') === ['ok' => true, 'platform' => 'native'], '"native" → ok, platform="native"');
_taap_assert(_authApplePlatformResolve('web') === ['ok' => true, 'platform' => 'web'], '"web" → ok, platform="web"');

foreach (['Native', 'WEB', 'ios', 'android', ' web', 'web ', 'native;web', 0, 1, false, true, [], ['web']] as $bad) {
    $label = is_scalar($bad) ? var_export($bad, true) : gettype($bad) . '(' . json_encode($bad) . ')';
    $result = _authApplePlatformResolve($bad);
    _taap_assert($result === ['ok' => false, 'platform' => null], "invalid platform $label → rejected (ok=false, platform=null)");
}

/* ---------------------------------------------------------------------- *
 * 2. "Never try both auds" — structural guard on the real case body.
 * ---------------------------------------------------------------------- */
function _taapCaseBody(string $source, string $caseLabel): ?string
{
    $needle = "case '{$caseLabel}':";
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $bodyStart = $start + strlen($needle);
    $nextCase = strpos($source, "\n        case '", $bodyStart);
    return substr($source, $bodyStart, ($nextCase !== false ? $nextCase : strlen($source)) - $bodyStart);
}

$authAppleBody = _taapCaseBody($apiSrc, 'auth_apple');
_taap_assert($authAppleBody !== null, "case 'auth_apple' found in api.php");

if ($authAppleBody !== null) {
    /* Match the actual INVOCATION form ("$verify = appleSiwaVerifyIdentityToken(")
       rather than the bare function name — a nearby explanatory comment
       ("Re-decoded again inside appleSiwaVerifyIdentityToken() (cheap; ...)")
       also contains the bare name and would otherwise double-count as a
       second call. */
    $verifyCallCount = preg_match_all('/\$verify\s*=\s*appleSiwaVerifyIdentityToken\s*\(/', $authAppleBody);
    _taap_assert($verifyCallCount === 1, "case 'auth_apple' calls appleSiwaVerifyIdentityToken() EXACTLY ONCE (found {$verifyCallCount}) — never tries a second aud on failure");
    _taap_assert(
        (bool)preg_match('/appleSiwaVerifyIdentityToken\s*\(\s*\$identityToken\s*,\s*\$jwks\s*,\s*\$expectedAud\s*,/', $authAppleBody),
        "the one appleSiwaVerifyIdentityToken() call uses the single resolved \$expectedAud variable (not a literal IHYMNS_SIWA_CLIENT_ID)"
    );
    _taap_assert(
        !(bool)preg_match('/appleSiwaVerifyIdentityToken\s*\([^)]*IHYMNS_SIWA_CLIENT_ID/', $authAppleBody),
        "no appleSiwaVerifyIdentityToken() call hardcodes IHYMNS_SIWA_CLIENT_ID directly (that would silently re-introduce native-only aud checking)"
    );
    _taap_assert(
        (bool)preg_match('/_authApplePlatformResolve\s*\(/', $authAppleBody),
        "case 'auth_apple' calls _authApplePlatformResolve() (the shared, tested resolver) rather than re-inlining its own allow-list check"
    );

    /* Dormancy gate ordering: appleWebLoginEnabledForChannel() must appear
       BEFORE _authAppleMapAccount() (the first DB-write path) in source
       order, so a disabled/unconfigured web request 503s before any account
       mapping/creation is attempted. */
    $gatePos = strpos($authAppleBody, 'appleWebLoginEnabledForChannel(');
    $mapPos  = strpos($authAppleBody, '_authAppleMapAccount(');
    _taap_assert($gatePos !== false, "case 'auth_apple' calls appleWebLoginEnabledForChannel()");
    _taap_assert($mapPos !== false, "case 'auth_apple' calls _authAppleMapAccount()");
    _taap_assert($gatePos !== false && $mapPos !== false && $gatePos < $mapPos, 'the web dormancy gate runs BEFORE account mapping — a disabled/unconfigured web request never reaches the DB-write path');
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
