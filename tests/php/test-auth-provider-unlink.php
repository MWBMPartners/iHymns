<?php
/**
 * iHymns — Linked-account UNLINK: lockout-guard truth table + list-shape
 * masking (#1479 W3)
 *
 * api.php is a 13k+-line dispatcher that executes real request-handling code
 * at file-scope on require (the test-auth-response-shape.php precedent
 * explains why) — so this test:
 *
 *   1. Extracts JUST the PURE _authProviderUnlinkSurvives() function body via
 *      a balanced-brace scan and eval()s that single definition — no
 *      dispatcher code runs, no DB, no network — then asserts the FULL
 *      lockout-guard truth table, including the private-relay-email
 *      stranding case round-2 review of the SIWA-web strategy caught (a
 *      verified, non-relay email with email-login operational is a survivor;
 *      the SAME email at @privaterelay.appleid.com is NOT, unless another
 *      provider is also linked).
 *   2. Extracts JUST the PURE _authProviderMaskEmail() function body and
 *      asserts its masking shape (never the raw address; '' on absent/
 *      malformed input).
 *   3. Extracts _authProviderListForUser() and source-greps its SQL SELECT
 *      list to prove it NEVER selects Subject or RefreshToken — the
 *      structural guard behind "auth_providers_list must never return the
 *      Apple sub or refresh token".
 *   4. Source-greps `case 'auth_provider_unlink':`'s body for the load-bearing
 *      shape: POST-only, validateCsrfRequest() called, the row lock is a real
 *      `FOR UPDATE`, and the lockout decision is delegated to
 *      _authProviderUnlinkSurvives() rather than re-inlined ad hoc.
 *
 *   php tests/php/test-auth-provider-unlink.php
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

function _tapuAssert(bool $cond, string $label): void
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
function _tapuExtractFunction(string $source, string $name): ?string
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
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

function _tapuCaseBody(string $source, string $caseLabel): ?string
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

foreach (['_authProviderUnlinkSurvives', '_authProviderMaskEmail', '_authProviderListForUser'] as $fnName) {
    $fnSrc = _tapuExtractFunction($apiSrc, $fnName);
    if ($fnSrc === null) {
        fwrite(STDERR, "FATAL: could not extract {$fnName}() from api.php — has it been renamed or removed?\n");
        exit(1);
    }
    /* eval() a single, isolated function DEFINITION — no dispatcher code, no
       superglobals, no I/O. Safe: this is source we just extracted and are
       about to assert the shape/behaviour of, not arbitrary/untrusted input. */
    eval($fnSrc);
    if (!function_exists($fnName)) {
        fwrite(STDERR, "FATAL: eval() of the extracted function did not define {$fnName}().\n");
        exit(1);
    }
}

/* ---------------------------------------------------------------------- *
 * 1. _authProviderUnlinkSurvives() — the full lockout-guard truth table.
 * ---------------------------------------------------------------------- */

/* (a) A password always survives, regardless of every other input. */
_tapuAssert(
    _authProviderUnlinkSurvives('somehash', false, '', false, []) === true,
    '(a) non-empty PasswordHash survives unlink even with no email/other provider'
);
_tapuAssert(
    _authProviderUnlinkSurvives('somehash', true, 'a@privaterelay.appleid.com', false, []) === true,
    '(a) password ALSO survives when the (irrelevant) email happens to be a private-relay alias'
);

/* (b) Verified, non-relay email + operational email login = a survivor. */
_tapuAssert(
    _authProviderUnlinkSurvives('', true, 'alice@example.com', true, []) === true,
    '(b) verified non-relay email + email-login operational + no other provider → survives'
);

/* THE STRANDING CASE (round-2 review finding #3) — a verified email that IS
   the Apple private-relay alias must NOT count as a survivor: unlinking
   Apple deactivates the relay, so mail to it bounces forever. */
_tapuAssert(
    _authProviderUnlinkSurvives('', true, 'abc123@privaterelay.appleid.com', true, []) === false,
    'STRANDING CASE: verified @privaterelay.appleid.com email is NOT a survivor (would strand the account) → refused (409)'
);
_tapuAssert(
    _authProviderUnlinkSurvives('', true, 'ABC123@PRIVATERELAY.APPLEID.COM', true, []) === false,
    'STRANDING CASE is detected case-INSENSITIVELY (uppercase relay host)'
);

/* Un-verified email never counts, even if it "looks" fine. */
_tapuAssert(
    _authProviderUnlinkSurvives('', false, 'alice@example.com', true, []) === false,
    'un-verified email (EmailVerified=0) is NOT a survivor, even with email-login operational and no relay'
);

/* Verified + non-relay, but email-login is NOT operational (no provider configured). */
_tapuAssert(
    _authProviderUnlinkSurvives('', true, 'alice@example.com', false, []) === false,
    'verified non-relay email is NOT a survivor when email-login is NOT operational (EmailService::isConfigured() false)'
);

/* Empty email string never counts, even if EmailVerified/operational are both true (shouldn't happen in practice, but the pure function must not misbehave on it). */
_tapuAssert(
    _authProviderUnlinkSurvives('', true, '', true, []) === false,
    'empty account email is NOT a survivor even when EmailVerified/operational are both true'
);

/* (c) Another linked provider is a survivor on its own, independent of (a)/(b). */
_tapuAssert(
    _authProviderUnlinkSurvives('', false, '', false, ['google']) === true,
    '(c) another linked provider survives with no password and no usable email at all'
);
_tapuAssert(
    _authProviderUnlinkSurvives('', true, 'abc123@privaterelay.appleid.com', true, ['google']) === true,
    '(c) another linked provider rescues the STRANDING CASE — the private-relay email alone would refuse, but the extra provider allows it'
);

/* The true "nothing survives" refusal: no password, no usable email, no other provider. */
_tapuAssert(
    _authProviderUnlinkSurvives('', false, '', false, []) === false,
    'the fully degenerate case (no password, no email, no other provider) is refused'
);

/* ---------------------------------------------------------------------- *
 * 2. _authProviderMaskEmail() — masking shape.
 * ---------------------------------------------------------------------- */
_tapuAssert(_authProviderMaskEmail('') === '', 'empty string masks to empty string');
_tapuAssert(_authProviderMaskEmail('not-an-email') === '', 'a string with no @ masks to empty string');
_tapuAssert(_authProviderMaskEmail('alice@example.com') === 'a****@e******.com', 'alice@example.com masks to a****@e******.com');
_tapuAssert(_authProviderMaskEmail('a@b.co') === 'a*@b*.co', 'single-char local/domain-label masks with exactly one star (never zero)');
_tapuAssert(
    _authProviderMaskEmail('user@mail.sub.example.com') === 'u***@m***.sub.example.com',
    'multi-label domain masks ONLY the first label, keeping the rest of the domain readable'
);
/* The masked output must never contain the raw local-part or first domain
   label verbatim (the whole point of masking). */
_tapuAssert(!str_contains(_authProviderMaskEmail('alice@example.com'), 'alice'), 'masked output never contains the raw local-part');
_tapuAssert(!str_contains(_authProviderMaskEmail('alice@example.com'), 'example'), 'masked output never contains the raw first domain label');

/* ---------------------------------------------------------------------- *
 * 3. _authProviderListForUser() never selects Subject/RefreshToken.
 * ---------------------------------------------------------------------- */
$listFnSrc = _tapuExtractFunction($apiSrc, '_authProviderListForUser');
_tapuAssert($listFnSrc !== null, '_authProviderListForUser() found in api.php');
if ($listFnSrc !== null) {
    _tapuAssert(!str_contains($listFnSrc, 'Subject'), '_authProviderListForUser() SQL never references Subject (the Apple sub)');
    _tapuAssert(!str_contains($listFnSrc, 'RefreshToken'), '_authProviderListForUser() SQL never references RefreshToken');
    _tapuAssert(str_contains($listFnSrc, 'EmailIsPrivateRelay'), '_authProviderListForUser() selects EmailIsPrivateRelay (feeds is_private_relay)');
    _tapuAssert(str_contains($listFnSrc, '_authProviderMaskEmail('), '_authProviderListForUser() masks Email via _authProviderMaskEmail() rather than returning it raw');
}

/* ---------------------------------------------------------------------- *
 * 4. Structural guards on `case 'auth_provider_unlink'`'s body.
 * ---------------------------------------------------------------------- */
$unlinkBody = _tapuCaseBody($apiSrc, 'auth_provider_unlink');
_tapuAssert($unlinkBody !== null, "case 'auth_provider_unlink' found in api.php");

if ($unlinkBody !== null) {
    _tapuAssert(
        (bool)preg_match('/REQUEST_METHOD\'\]\s*!==\s*\'POST\'/', $unlinkBody),
        "case 'auth_provider_unlink' rejects non-POST requests"
    );
    _tapuAssert(
        (bool)preg_match('/validateCsrfRequest\s*\(/', $unlinkBody),
        "case 'auth_provider_unlink' calls validateCsrfRequest() (rule #29 same-origin CSRF check)"
    );
    _tapuAssert(
        (bool)preg_match('/getAuthenticatedUser\s*\(/', $unlinkBody),
        "case 'auth_provider_unlink' requires a bearer via getAuthenticatedUser()"
    );
    _tapuAssert(
        (bool)preg_match('/FOR UPDATE/', $unlinkBody),
        "case 'auth_provider_unlink' locks rows with FOR UPDATE before deciding (race-safe lockout guard)"
    );
    _tapuAssert(
        (bool)preg_match('/_authProviderUnlinkSurvives\s*\(/', $unlinkBody),
        "case 'auth_provider_unlink' delegates the lockout decision to the shared, tested _authProviderUnlinkSurvives() rather than re-inlining it"
    );
    _tapuAssert(
        (bool)preg_match('/\bin_array\s*\(\s*\$provider\s*,\s*\$providerAllowList\s*,\s*true\s*\)/', $unlinkBody),
        "case 'auth_provider_unlink' validates \$provider against an explicit allow-list (never an unchecked value reaching SQL)"
    );
    _tapuAssert(
        (bool)preg_match('/_authProviderUnlinkRevokeApple\s*\(/', $unlinkBody),
        "case 'auth_provider_unlink' attempts a best-effort Apple revoke via the shared helper"
    );
    _tapuAssert(
        (bool)preg_match('/logActivity\s*\(\s*\'auth\.provider_unlink\'/', $unlinkBody),
        "case 'auth_provider_unlink' audits via logActivity('auth.provider_unlink', ...)"
    );
    /* Never logs the Apple sub / refresh token — same discipline as the SIWA
       sources guard (test-apple-siwa-sources.php), scoped to this new case. */
    _tapuAssert(!str_contains($unlinkBody, "'Subject'"), "case 'auth_provider_unlink' never logs or echoes the raw Subject column name in a sensitive context");
}

$listBody = _tapuCaseBody($apiSrc, 'auth_providers_list');
_tapuAssert($listBody !== null, "case 'auth_providers_list' found in api.php");
if ($listBody !== null) {
    _tapuAssert(
        (bool)preg_match('/getAuthenticatedUser\s*\(/', $listBody),
        "case 'auth_providers_list' requires a bearer via getAuthenticatedUser()"
    );
    _tapuAssert(
        (bool)preg_match('/_authProviderListForUser\s*\(/', $listBody),
        "case 'auth_providers_list' delegates to the shared _authProviderListForUser() row-builder"
    );
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
