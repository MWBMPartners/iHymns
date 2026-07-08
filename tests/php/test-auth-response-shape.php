<?php
/**
 * iHymns — auth_login / auth_apple response-shape parity lock (#1402)
 *
 * apiAuthSuccessPayload() (appWeb/public_html/api.php) is the ONE function
 * that builds the bearer-auth success response — the native client's
 * AuthSession DTO decodes EXACTLY `token` + `user.{id, username,
 * display_name, role, avatar_service}` from it, and BOTH `case 'auth_login'`
 * and `case 'auth_apple'` must return that same shape or the native app's
 * Sign-in-with-Apple flow silently breaks while the PWA's own login keeps
 * working (a hard bug to spot without a structural guard).
 *
 * api.php is a 13k+-line dispatcher that executes real request-handling code
 * at file-scope on require — it cannot be require()'d directly in a DB-free,
 * request-free CI test. This test instead:
 *   1. Extracts JUST the apiAuthSuccessPayload() function body from the
 *      source (balanced-brace scan) and eval()s that single function
 *      definition — no dispatcher code runs, no DB, no network.
 *   2. Calls it with representative inputs and asserts the EXACT key sets.
 *   3. Source-greps that BOTH `case 'auth_login':` and `case 'auth_apple':`
 *      call apiAuthSuccessPayload() somewhere in their case body — a static
 *      guard against either handler reverting to a hand-rolled array.
 *
 *   php tests/php/test-auth-response-shape.php
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

function _tarsAssert(bool $cond, string $label): void
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
 * $source via a balanced-brace scan (the codebase's array literals use
 * `[...]`, never `{...}`, so simple brace counting is safe here).
 */
function _tarsExtractFunction(string $source, string $name): ?string
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
    return null; /* Unbalanced — extraction failed. */
}

$fnSrc = _tarsExtractFunction($apiSrc, 'apiAuthSuccessPayload');
if ($fnSrc === null) {
    fwrite(STDERR, "FATAL: could not extract apiAuthSuccessPayload() from api.php — has it been renamed or removed?\n");
    exit(1);
}

/* eval() a single, isolated function DEFINITION — no dispatcher code, no
   superglobals, no I/O. Safe: this is source we just extracted and are
   about to assert the shape of, not arbitrary/untrusted input. */
eval($fnSrc);

if (!function_exists('apiAuthSuccessPayload')) {
    fwrite(STDERR, "FATAL: eval() of the extracted function did not define apiAuthSuccessPayload().\n");
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * 1. Exact key-set assertions — the AuthSession.swift contract.
 * ---------------------------------------------------------------------- */
$payload = apiAuthSuccessPayload('deadbeef' . str_repeat('0', 56), 42, 'alice', 'Alice A.', 'user', 'gravatar');

_tarsAssert(array_keys($payload) === ['token', 'user'], 'top-level keys are exactly [token, user]');
_tarsAssert(
    array_keys($payload['user']) === ['id', 'username', 'display_name', 'role', 'avatar_service'],
    'user keys are exactly [id, username, display_name, role, avatar_service]'
);
_tarsAssert(is_int($payload['user']['id']), 'user.id is an int');
_tarsAssert($payload['user']['id'] === 42, 'user.id round-trips the input value');
_tarsAssert($payload['token'] === 'deadbeef' . str_repeat('0', 56), 'token round-trips the input value');

/* avatar_service null pass-through (#616 column-existence gate). */
$payloadNullAvatar = apiAuthSuccessPayload('tok', 1, 'bob', 'Bob', 'admin', null);
_tarsAssert(array_key_exists('avatar_service', $payloadNullAvatar['user']) && $payloadNullAvatar['user']['avatar_service'] === null, 'avatar_service accepts and preserves null');

/* No stray keys sneak in — e.g. auth_email_login_verify's response ADDS an
   `email` key on purpose (a DIFFERENT, intentionally-divergent shape,
   manage/includes/auth.php ~1712) — apiAuthSuccessPayload() must never grow
   one, or auth_apple would silently diverge from auth_login too. */
_tarsAssert(!array_key_exists('email', $payload['user']), 'user shape does NOT include email (that is auth_email_login_verify\'s deliberately different shape)');

/* ---------------------------------------------------------------------- *
 * 2. Source-grep: both auth_login and auth_apple call the shared helper
 *    instead of hand-rolling the array again.
 * ---------------------------------------------------------------------- */
function _tarsCaseBody(string $source, string $caseLabel): ?string
{
    $needle = "case '{$caseLabel}':";
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    /* Next case at the same indentation (8 spaces, matching this file's
       switch-statement style) marks the end of this case's body. Fall back
       to end-of-file if this is the last case (shouldn't happen here). */
    $bodyStart = $start + strlen($needle);
    $nextCase = strpos($source, "\n        case '", $bodyStart);
    return substr($source, $bodyStart, ($nextCase !== false ? $nextCase : strlen($source)) - $bodyStart);
}

$authLoginBody = _tarsCaseBody($apiSrc, 'auth_login');
$authAppleBody = _tarsCaseBody($apiSrc, 'auth_apple');

_tarsAssert($authLoginBody !== null, "case 'auth_login' found in api.php");
_tarsAssert($authAppleBody !== null, "case 'auth_apple' found in api.php");
_tarsAssert($authLoginBody !== null && str_contains($authLoginBody, 'apiAuthSuccessPayload('), "case 'auth_login' calls apiAuthSuccessPayload()");
_tarsAssert($authAppleBody !== null && str_contains($authAppleBody, 'apiAuthSuccessPayload('), "case 'auth_apple' calls apiAuthSuccessPayload()");

/* Neither case block should ALSO hand-roll a competing `'user' => [` shape
   inline (the exact regression this test guards against) — count that
   apiAuthSuccessPayload( is the ONLY 'user' =>-adjacent construction in each
   body by checking there's no OTHER sendJson([...'user'=>[...]]) literal. */
foreach (['auth_login' => $authLoginBody, 'auth_apple' => $authAppleBody] as $label => $body) {
    if ($body === null) { continue; }
    $hasInlineUserArray = (bool)preg_match("/sendJson\s*\(\s*\[\s*['\"]token['\"]\s*=>/", $body);
    _tarsAssert(!$hasInlineUserArray, "case '{$label}' does not ALSO hand-roll an inline ['token'=>...,'user'=>[...]] response");
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
