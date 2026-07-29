<?php

declare(strict_types=1);

/**
 * iHymns — auth abuse-budget guard (#1028 / #1027 / #1022)
 * ============================================================================
 *
 * Pins the three throttling contracts added to appWeb/public_html/api.php:
 *
 *   #1028  ?action=auth_forgot_password is rate limited, and the limiter runs
 *          BEFORE generatePasswordResetToken() — i.e. before we mint a token,
 *          invalidate the victim's previous one, and pay for an SMTP send.
 *          CRITICALLY, throttling must not have re-opened the #898
 *          user-enumeration hole: the response an attacker can observe must be
 *          identical whether or not the submitted account exists.
 *
 *   #1027  ?action=auth_login has a PER-ACCOUNT lockout on top of the existing
 *          per-IP one, keyed on the SUBMITTED username (so it fills identically
 *          for a real and an imaginary account) and emitting a message
 *          byte-identical to the per-IP 429 (so the two are indistinguishable).
 *
 *   #1022  A cheap unauthenticated liveness probe exists, is answered BEFORE
 *          any app bootstrapping (so a DB-down probe can never fall through to
 *          the global handler, which discloses exception text on Alpha/Beta),
 *          and leaks nothing but a two-value status enum.
 *
 * HOW IT TESTS WITHOUT A DATABASE. api.php is a 15k-line dispatcher that
 * executes request-handling code at file scope on require, so it cannot be
 * included in CI. This follows the technique already established by
 * tests/php/test-auth-response-shape.php (#1402):
 *   1. Extract JUST the pure helper functions (balanced-brace scan) and eval()
 *      those single definitions — no dispatcher runs, no DB, no network.
 *   2. Call them and assert behaviour, including strict byte-equality of the
 *      throttled and un-throttled response bodies.
 *   3. Assert the STRUCTURE of the relevant `case` bodies by source analysis —
 *      ordering (limiter before token generation), single-expression response,
 *      and the absence of anything that could leak.
 *
 * Reflection is used to assert something behaviour alone cannot: that
 * apiForgotPasswordDecision() has NO account-existence parameter. That makes
 * "existence cannot influence the response" a property of the signature rather
 * than a convention a later edit can quietly break.
 *
 *   php tests/php/test-auth-rate-limit.php
 *
 * To run against a different copy of api.php (used to verify this test FAILS
 * against the pre-fix source — a test never seen failing protects nothing):
 *   IHYMNS_API_PHP=/path/to/old/api.php php tests/php/test-auth-rate-limit.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 *
 * @see https://www.php.net/manual/en/class.reflectionfunction.php
 * @see https://cwe.mitre.org/data/definitions/204.html  (observable response discrepancy)
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
 */

$apiFile = getenv('IHYMNS_API_PHP') ?: (dirname(__DIR__, 2) . '/appWeb/public_html/api.php');
if (!is_readable($apiFile)) {
    fwrite(STDERR, "FATAL: could not read $apiFile\n");
    exit(1);
}
$apiSrcRaw = (string)file_get_contents($apiFile);

$failures = 0;
$passed   = 0;

/**
 * Blank out every comment in a PHP source string, preserving byte offsets.
 *
 * ELI5: keeps the code exactly where it is but wipes the notes around it, so
 * "does the handler call X before Y" measures the CODE and not the paragraph
 * explaining the code.
 *
 * This is not cosmetic — it is load-bearing. The api.php changes this test
 * guards are heavily annotated (house style: an ELI5 sentence plus the detailed
 * "why"), and those annotations naturally NAME the very symbols the structural
 * assertions look for ("MUST run before generatePasswordResetToken()", "NOT
 * checkRateLimit(), which writes one row per request", "no dead `!== false`
 * guard"). Scanning raw source therefore matches the prose and reports failures
 * that are pure false positives — and, far worse, could one day let a real
 * regression hide behind a mention in a comment.
 *
 * Comment tokens are replaced with spaces of the SAME LENGTH (newlines kept) so
 * every strpos()/offset comparison below stays valid against the original file.
 * The same technique tests/php/test-fragment-inline-scripts.php uses.
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */
function tarlStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                /* Same byte length, newlines preserved → offsets + line
                   structure survive; everything else becomes blank. */
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]);
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

$apiSrc = tarlStripComments($apiSrcRaw);
tarl(strlen($apiSrc) === strlen($apiSrcRaw),
    '0.1 comment-stripping preserved byte offsets (source analysis below is positionally sound)');

/** Record one assertion. */
function tarl(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/**
 * Extract one top-level `function <name>(...) { ... }` definition by balanced
 * brace scan. Same helper (and same caveat — the codebase's array literals use
 * `[...]`, never `{...}`, so plain brace counting is safe for these bodies) as
 * tests/php/test-auth-response-shape.php.
 */
function tarlExtractFunction(string $source, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $bracePos = strpos($source, '{', $start);
    if ($bracePos === false) { return null; }
    $depth = 0;
    $len = strlen($source);
    for ($i = $bracePos; $i < $len; $i++) {
        if ($source[$i] === '{') { $depth++; }
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) { return substr($source, $start, $i - $start + 1); }
        }
    }
    return null;   /* unbalanced — extraction failed */
}

/**
 * Extract the body of one `case '<name>':` clause from api.php's action switch,
 * i.e. everything up to the next case label at the same (8-space) indentation.
 */
function tarlExtractCase(string $source, string $case): ?string
{
    $needle = "\n        case '" . $case . "':";
    $start = strpos($source, $needle);
    if ($start === false) { return null; }
    $start += strlen($needle);
    $next = preg_match('/\n        case \'/', $source, $m, PREG_OFFSET_CAPTURE, $start)
        ? $m[0][1]
        : strlen($source);
    return substr($source, $start, $next - $start);
}

/* ===========================================================================
 * SECTION 1 — #1028 pure decision helpers (behaviour)
 * ======================================================================== */

$fnGeneric  = tarlExtractFunction($apiSrc, 'apiForgotPasswordGenericResponse');
$fnDecision = tarlExtractFunction($apiSrc, 'apiForgotPasswordDecision');
$fnIdKey    = tarlExtractFunction($apiSrc, 'apiForgotPasswordIdentifierKey');

tarl($fnGeneric  !== null, '1.1 apiForgotPasswordGenericResponse() is defined in api.php');
tarl($fnDecision !== null, '1.2 apiForgotPasswordDecision() is defined in api.php');
tarl($fnIdKey    !== null, '1.3 apiForgotPasswordIdentifierKey() is defined in api.php');

if ($fnGeneric !== null && $fnDecision !== null && $fnIdKey !== null) {
    /* eval() of three isolated, self-contained function definitions lifted from
       our own source — no request state, no DB handle, no superglobals. */
    eval($fnGeneric);
    eval($fnDecision);
    eval($fnIdKey);

    $generic = apiForgotPasswordGenericResponse();
    tarl(
        $generic === [
            'ok'      => true,
            'message' => 'If an account exists with that username or email, a reset link has been generated.',
        ],
        '1.4 the generic 200 body is the #898 non-committal wording, unchanged'
    );

    $allowed     = apiForgotPasswordDecision(true, true);
    $idThrottled = apiForgotPasswordDecision(true, false);
    $ipThrottled = apiForgotPasswordDecision(false, true);
    $bothOut     = apiForgotPasswordDecision(false, false);

    tarl($allowed['status'] === 200 && $allowed['send'] === true,
        '1.5 within budget → 200 and we are allowed to mint + email a token');

    /* THE headline anti-enumeration assertion: a request silently dropped
       because that ADDRESS has had its share this hour must be byte-identical
       to one we actually acted on. Anything else tells an attacker "somebody
       has been requesting resets for this address" — i.e. that it is real. */
    tarl($idThrottled['status'] === 200 && $idThrottled['send'] === false,
        '1.6 identifier bucket exhausted → silent drop (200, no token minted)');
    tarl($idThrottled['body'] === $allowed['body'],
        '1.7 identifier-throttled body is BYTE-IDENTICAL to the allowed body');
    tarl($idThrottled['status'] === $allowed['status'],
        '1.8 identifier-throttled status is identical to the allowed status');

    /* The per-IP verdict is about the CALLER, not any account — a visible 429
       there is safe, but it must not vary with the identifier bucket, or the
       429/200 boundary itself starts carrying account information. */
    tarl($ipThrottled['status'] === 429 && $ipThrottled['send'] === false,
        '1.9 IP bucket exhausted → 429, no token minted');
    tarl($ipThrottled === $bothOut,
        '1.10 the 429 is identical regardless of the identifier bucket state');
    tarl(!isset($ipThrottled['body']['ok']) && isset($ipThrottled['body']['error']),
        '1.11 the 429 body carries only a generic error, no account detail');

    /* Structural proof that account existence CANNOT reach the decision: the
       function has no parameter for it. Behaviour alone cannot assert this. */
    $ref = new ReflectionFunction('apiForgotPasswordDecision');
    $paramNames = array_map(static fn($p) => strtolower($p->getName()), $ref->getParameters());
    tarl(count($paramNames) === 2,
        '1.12 apiForgotPasswordDecision() takes exactly the two throttle verdicts');
    $leaky = array_filter($paramNames, static fn(string $n) => (bool)preg_match(
        '/(exist|account|user|email|found|result|token)/', $n
    ));
    tarl($leaky === [],
        '1.13 no parameter names an account/user/existence input (' . implode(',', $paramNames) . ')');
    $allBool = true;
    foreach ($ref->getParameters() as $p) {
        $t = $p->getType();
        if (!$t instanceof ReflectionNamedType || $t->getName() !== 'bool') { $allBool = false; }
    }
    tarl($allBool, '1.14 both parameters are typed bool (nothing richer can smuggle state in)');

    /* Identifier bucket key: same normalisation generatePasswordResetToken()
       applies, and inside tblLoginAttempts.IpAddress's VARCHAR(45). */
    tarl(
        apiForgotPasswordIdentifierKey('  Alice@Example.COM ') === apiForgotPasswordIdentifierKey('alice@example.com'),
        '1.15 identifier key folds case + surrounding whitespace (no budget doubling)'
    );
    tarl(
        apiForgotPasswordIdentifierKey('alice@example.com') !== apiForgotPasswordIdentifierKey('bob@example.com'),
        '1.16 different identifiers get different buckets'
    );
    $longEmail = str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.example.com';
    tarl(strlen(apiForgotPasswordIdentifierKey($longEmail)) <= 45,
        '1.17 key fits tblLoginAttempts.IpAddress VARCHAR(45) even for a long address');
}

/* ===========================================================================
 * SECTION 2 — #1028 handler structure: limiter BEFORE token generation
 * ======================================================================== */

$forgotCase = tarlExtractCase($apiSrc, 'auth_forgot_password');
tarl($forgotCase !== null, "2.1 case 'auth_forgot_password' found in the action switch");

if ($forgotCase !== null) {
    $posLimit = strpos($forgotCase, 'checkRateLimit(');
    $posMint  = strpos($forgotCase, 'generatePasswordResetToken(');

    tarl($posLimit !== false, '2.2 the handler calls checkRateLimit()');
    tarl($posMint !== false, '2.3 the handler still calls generatePasswordResetToken()');
    tarl(
        $posLimit !== false && $posMint !== false && $posLimit < $posMint,
        '2.4 checkRateLimit() runs BEFORE generatePasswordResetToken() (no token/SMTP spend on a throttled request)'
    );
    tarl(substr_count($forgotCase, 'generatePasswordResetToken(') === 1,
        '2.5 exactly one token-generation call, so there is no unguarded second path');

    /* Both throttle buckets are present — per-IP alone is walked past by a
       botnet, per-identifier alone lets one machine cycle addresses forever. */
    tarl(strpos($forgotCase, "checkRateLimit('auth_forgot_password_ip'") !== false,
        '2.6 a per-IP bucket is checked');
    tarl(strpos($forgotCase, "checkRateLimit('auth_forgot_password_id'") !== false,
        '2.7 a per-submitted-identifier bucket is checked');

    /* Every reply emitted at or after the throttle decision must come from the
       SAME single expression, so the response cannot vary with anything the
       lookup discovers about the account. */
    $tailStart = strpos($forgotCase, 'apiForgotPasswordDecision(');
    if ($tailStart === false) {
        tarl(false, '2.8 the handler delegates to apiForgotPasswordDecision()');
    } else {
        $tail = substr($forgotCase, $tailStart);
        preg_match_all('/sendJson\([^;]*\);/', $tail, $m);
        $unique = array_values(array_unique($m[0]));
        tarl(
            count($unique) === 1 && $unique[0] === "sendJson(\$forgotDecision['body'], \$forgotDecision['status']);",
            '2.8 every reply after the throttle decision is the one shared '
            . '$forgotDecision expression (found: ' . count($unique) . ' distinct)'
        );
    }

    /* The 200 wording must no longer be hand-inlined in the case body — one
       builder, so the throttled and un-throttled bodies cannot drift apart. */
    tarl(
        strpos($forgotCase, 'If an account exists with that username or email') === false,
        '2.9 the 200 wording is not re-inlined in the handler (single source of truth)'
    );
}

/* ===========================================================================
 * SECTION 3 — #1027 per-account login lockout
 * ======================================================================== */

$loginCase = tarlExtractCase($apiSrc, 'auth_login');
tarl($loginCase !== null, "3.1 case 'auth_login' found in the action switch");

if ($loginCase !== null) {
    tarl(strpos($loginCase, "checkRateLimit('auth_login_acct'") !== false,
        '3.2 a per-account failure bucket is checked');
    tarl(strpos($loginCase, "recordRateLimitHit('auth_login_acct'") !== false,
        '3.3 failures are recorded into the per-account bucket (read and write are paired)');

    /* Keyed on the SUBMITTED username, never on a resolved row — a bucket that
       only existed for real accounts would be an existence oracle by itself. */
    tarl(preg_match("/\\\$loginAcctKey\s*=\s*'acct:'\s*\.\s*substr\(hash\('sha256',\s*\\\$username\)/", $loginCase) === 1,
        '3.4 the account bucket is keyed on a hash of the SUBMITTED username');

    /* The recorder must sit inside the SHARED "unknown user OR wrong password"
       branch, so the counter advances identically for a real and an imaginary
       account. Offsets prove the nesting without parsing PHP. */
    $posBranch  = strpos($loginCase, 'if (!$user || !password_verify(');
    $posRecord  = strpos($loginCase, "recordRateLimitHit('auth_login_acct'");
    $posActive  = strpos($loginCase, "if (!\$user['IsActive'])");
    tarl(
        $posBranch !== false && $posRecord !== false && $posActive !== false
            && $posBranch < $posRecord && $posRecord < $posActive,
        '3.5 the account failure is recorded inside the shared unknown-user/wrong-password branch'
    );

    /* Both 429s must read identically to the client, or the account lockout
       becomes distinguishable from the per-IP one. */
    tarl(
        substr_count($loginCase, "sendJson(['error' => 'Too many failed login attempts. Please try again later.'], 429);") === 2,
        '3.6 the account 429 message is byte-identical to the per-IP 429 message'
    );

    /* The account cap must exceed the per-IP cap, or it would fire on a single
       fat-fingering user before the per-IP limit ever did. */
    if (preg_match("/checkRateLimit\('auth_login_acct',\s*\\\$loginAcctKey,\s*(\d+),\s*(\d+)/", $loginCase, $m)) {
        tarl((int)$m[1] > 10, '3.7 account cap (' . $m[1] . ') exceeds the per-IP cap (10) — unreachable from one address');
        tarl((int)$m[2] === 900, '3.8 account window matches the per-IP 15-minute window so the two compose');
    } else {
        tarl(false, '3.7 account cap/window are readable from the checkRateLimit() call');
    }
}

/* ===========================================================================
 * SECTION 4 — #1022 liveness probe
 * ======================================================================== */

$posProbe    = strpos($apiSrc, "if (\$action === 'health') {");
$posBootstrap = strpos($apiSrc, '$songData = new SongData();');

tarl($posProbe !== false, '4.1 an ?action=health probe exists');
tarl(
    $posProbe !== false && $posBootstrap !== false && $posProbe < $posBootstrap,
    '4.2 the probe is answered BEFORE new SongData() opens the DB '
    . '(a DB-down probe must not fall through to the Alpha/Beta-verbose global handler)'
);

/* It must NOT also be a switch case — that would be dead code below the
   early exit, and a maintainer could "fix" the wrong one. */
tarl(strpos($apiSrc, "case 'health':") === false,
    '4.3 no dead `case \'health\':` duplicate in the dispatch switch');

if ($posProbe !== false && $posBootstrap !== false && $posProbe < $posBootstrap) {
    $probe = substr($apiSrc, $posProbe, $posBootstrap - $posProbe);

    tarl(strpos($probe, 'enforceReadRateLimit(') !== false,
        '4.4 the probe is rate limited (via the windowed counter, not a row-per-request table)');
    tarl(strpos($probe, 'checkRateLimit(') === false,
        '4.5 the probe does NOT use checkRateLimit() (which would write one tblLoginAttempts row per poll)');
    tarl(strpos($probe, 'isDbConnectionFailure(') !== false,
        '4.6 the probe reuses the shared isDbConnectionFailure() classifier');
    tarl(strpos($probe, "\$healthDb->query('SELECT 1')") !== false,
        '4.7 liveness is proved by SELECT 1 — no table, so no schema/data oracle');
    tarl(strpos($probe, '!== false') === false,
        '4.8 no dead `!== false` guard (mysqli runs under MYSQLI_REPORT_STRICT and throws)');

    /* Response bodies: the two-value enum and nothing else. */
    preg_match_all('/json_encode\((\[[^\]]*\])/', $probe, $bodies);
    $bodySet = array_values(array_unique($bodies[1]));
    sort($bodySet);
    tarl(
        $bodySet === ["['status' => 'ok']", "['status' => 'unavailable']"],
        '4.9 the probe emits ONLY {"status":"ok"} / {"status":"unavailable"} (found: '
        . implode(' | ', $bodySet) . ')'
    );

    /* Nothing about the failure may reach the client — the status code is the
       whole signal; detail goes to error_log only. */
    $echoLeak = false;
    foreach (explode("\n", $probe) as $line) {
        if (strpos($line, 'echo') !== false
            && (strpos($line, 'getMessage') !== false || strpos($line, 'getFile') !== false)) {
            $echoLeak = true;
        }
    }
    tarl(!$echoLeak, '4.10 no exception message/file is echoed to the client');
    tarl(strpos($probe, 'error_log(') !== false,
        '4.11 the failure detail is written to the server log instead');

    /* Cheap reconnaissance surface — assert none of the usual give-aways. */
    $recon = ['APP_CONFIG', 'phpversion', 'gethostname', 'DB_HOST', 'DB_NAME',
              'infoAppVer', 'Version', 'ihymns_environment'];
    $found = array_values(array_filter($recon, static fn(string $n) => strpos($probe, $n) !== false));
    tarl($found === [],
        '4.12 the probe exposes no version/host/env/schema detail (' . implode(',', $found) . ')');

    tarl(strpos($probe, "header('Cache-Control: no-store')") !== false,
        '4.13 probe responses are no-store (a cached "ok" from a dead node is worse than none)');
}

/* ======================================================================== */

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
