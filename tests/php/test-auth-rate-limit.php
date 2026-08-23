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
 *   #1027  BOTH login surfaces — api.php auth_login AND manage/includes/auth.php
 *          attemptLogin() — have a PER-ACCOUNT lockout on top of the existing
 *          per-IP one, keyed on the SUBMITTED username (so it fills identically
 *          for a real and an imaginary account) via the ONE shared derivation
 *          authLoginAcctKey() (includes/rate_limit.php). The api.php half
 *          emits a message byte-identical to the per-IP 429; the /manage half
 *          returns the same generic invalid-credentials failure. Section 3
 *          derives the login-surface set FROM THE TREE, so a future third
 *          login path is automatically required to check the shared bucket —
 *          and asserts the helper is the SOLE 'acct:' derivation, closing the
 *          key-drift gap that caused the 2026-08-18 api-half-only mis-close.
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
 * SECTION 3 — #1027 per-account login lockout (both surfaces, one derivation)
 * ===========================================================================
 * The 2026-08-18 close shipped the api.php half only; #1027's own scope said
 * "apply identically in both" login surfaces (api.php auth_login AND
 * manage/includes/auth.php attemptLogin). The remainder (this session) adds
 * the /manage half and folds BOTH surfaces onto the ONE shared derivation
 * authLoginAcctKey() + the IHYMNS_AUTH_ACCT_* constants in
 * includes/rate_limit.php. This section pins: the shared helper's behaviour,
 * that it is the SOLE 'acct:' derivation tree-wide, and that EVERY login
 * surface (derived from the tree, not a typed list) checks the shared bucket
 * before password_verify() and records into it on failure.
 * ======================================================================== */

$repoRoot     = dirname(__DIR__, 2);
$docroot      = $repoRoot . '/appWeb/public_html';
$rateFile     = $docroot . '/includes/rate_limit.php';
$manageAuthFn = $docroot . '/manage/includes/auth.php';

$rateSrcRaw   = is_readable($rateFile)     ? (string)file_get_contents($rateFile)     : '';
$manageSrcRaw = is_readable($manageAuthFn) ? (string)file_get_contents($manageAuthFn) : '';
$rateSrc      = $rateSrcRaw   !== '' ? tarlStripComments($rateSrcRaw)   : '';
$manageSrc    = $manageSrcRaw !== '' ? tarlStripComments($manageSrcRaw) : '';

/* --- 3a. The shared helper's behaviour (functional, no DB) --------------- */
$fnAcctKey = $rateSrc !== '' ? tarlExtractFunction($rateSrc, 'authLoginAcctKey') : null;
tarl($fnAcctKey !== null, '3.1 authLoginAcctKey() is defined in includes/rate_limit.php');

if ($fnAcctKey !== null) {
    eval($fnAcctKey);

    /* One fold for both surfaces: mb_strtolower(trim()). If the two login
       surfaces folded differently they would fill two buckets for one
       account (the exact drift that produced the mis-close). */
    tarl(authLoginAcctKey(' UsEr ') === authLoginAcctKey('user'),
        '3.2 authLoginAcctKey() folds case + surrounding whitespace (one bucket per account)');
    tarl(authLoginAcctKey('alice') !== authLoginAcctKey('bob'),
        '3.3 different accounts get different buckets');

    /* Behaviour-identity proof for the api.php swap: for an ALREADY-normalised
       input, the shared helper equals the previous inline derivation
       ('acct:' . substr(hash('sha256', $username), 0, 40) where $username was
       already mb_strtolower(trim())'d). So swapping to the helper changed
       nothing about the key api.php files under. */
    $legacyInline = 'acct:' . substr(hash('sha256', 'user'), 0, 40);
    tarl(authLoginAcctKey('user') === $legacyInline,
        '3.4 for an already-normalised username the helper equals the legacy inline derivation (api.php swap is behaviour-identical)');

    /* Fits tblLoginAttempts.IpAddress VARCHAR(45): 'acct:' (5) + 40 hex = 45. */
    tarl(strlen(authLoginAcctKey(str_repeat('x', 300))) === 45,
        '3.5 the key is 45 chars (fits VARCHAR(45)) even for a very long submission');
}

/* --- 3b. The shared helper is the SOLE 'acct:' derivation ---------------- */
/* Exactly one definition; and no other file re-derives an 'acct:' + sha256
   key by hand (the drift that mis-closed #1027). Comment-stripped so a
   doc-block mentioning the pattern can't false-positive. */
$acctDefs = 0;
$acctInlineDerivations = [];
$phpFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $phpFiles[] = $f->getPathname(); }
}
sort($phpFiles);
foreach ($phpFiles as $pf) {
    $src = tarlStripComments((string)file_get_contents($pf));
    $acctDefs += preg_match_all('/function\s+authLoginAcctKey\s*\(/', $src);
    /* An inline "'acct:' . ... hash('sha256'" derivation anywhere BUT the
       shared helper's own file is the forbidden fork. */
    if (basename($pf) !== 'rate_limit.php'
        && preg_match("/'acct:'\s*\.\s*substr\(\s*hash\(/", $src)) {
        $acctInlineDerivations[] = str_replace($docroot . '/', '', $pf);
    }
}
tarl($acctDefs === 1,
    '3.6 exactly ONE authLoginAcctKey() definition tree-wide (found ' . $acctDefs . ')');
tarl($acctInlineDerivations === [],
    '3.7 no file re-derives an \'acct:\' bucket key by hand outside the shared helper (found: '
        . implode(',', $acctInlineDerivations) . ')');

/* --- 3c. api.php auth_login uses the shared bucket, correctly ordered ---- */
$loginCase = tarlExtractCase($apiSrc, 'auth_login');
tarl($loginCase !== null, "3.8 case 'auth_login' found in the action switch");

if ($loginCase !== null) {
    $posCheck   = strpos($loginCase, 'checkRateLimit(IHYMNS_AUTH_ACCT_ACTION');
    $posVerify  = strpos($loginCase, 'password_verify(');
    $posRecord  = strpos($loginCase, 'recordRateLimitHit(IHYMNS_AUTH_ACCT_ACTION');
    $posKey     = strpos($loginCase, 'authLoginAcctKey($username)');

    tarl($posKey !== false,
        '3.9 the account key is derived via the shared authLoginAcctKey() helper (no inline copy)');
    tarl($posCheck !== false && $posVerify !== false && $posCheck < $posVerify,
        '3.10 the per-account bucket is checked BEFORE password_verify() (a hammered account never spends a bcrypt)');
    tarl($posRecord !== false,
        '3.11 failures are recorded into the shared per-account bucket (read and write are paired)');

    /* The recorder must sit inside the SHARED "unknown user OR wrong password"
       branch, so the counter advances identically for a real and imaginary
       account (no username-existence oracle). Offsets prove the nesting. */
    $posBranch  = strpos($loginCase, 'if (!$user || !password_verify(');
    $posActive  = strpos($loginCase, "if (!\$user['IsActive'])");
    tarl(
        $posBranch !== false && $posRecord !== false && $posActive !== false
            && $posBranch < $posRecord && $posRecord < $posActive,
        '3.12 the account failure is recorded inside the shared unknown-user/wrong-password branch'
    );

    /* Both 429s must read identically to the client, or the account lockout
       becomes distinguishable from the per-IP one. */
    tarl(
        substr_count($loginCase, "sendJson(['error' => 'Too many failed login attempts. Please try again later.'], 429);") === 2,
        '3.13 the account 429 message is byte-identical to the per-IP 429 message'
    );
}

/* --- 3d. The shared constants compose with the per-IP cap ---------------- */
if ($rateSrc !== '') {
    $accMax = preg_match('/const\s+IHYMNS_AUTH_ACCT_MAX\s*=\s*(\d+)/', $rateSrc, $m) ? (int)$m[1] : 0;
    $accWin = preg_match('/const\s+IHYMNS_AUTH_ACCT_WINDOW\s*=\s*(\d+)/', $rateSrc, $m) ? (int)$m[1] : 0;
    tarl($accMax > 10,
        '3.14 IHYMNS_AUTH_ACCT_MAX (' . $accMax . ') exceeds the per-IP cap (10) — unreachable from one address');
    tarl($accWin === 900,
        '3.15 IHYMNS_AUTH_ACCT_WINDOW (' . $accWin . ') matches the per-IP 15-minute window so the two compose');
}

/* --- 3e. EVERY login surface got the shared bucket (tree-derived) -------- */
/* Derive the login-surface set from the tree rather than a typed list: a
   credential-LOGIN lockout surface is one that verifies a password AND carries
   the per-IP login brute-force count query `IpAddress = ? AND Success = 0`. The
   IpAddress-keyed query is what distinguishes a LOGIN (an anonymous caller
   establishing a session, keyed on the client IP) from a RE-AUTH gate (an
   already-signed-in admin re-proving identity — e.g. setup-database.php's
   secret_rotate, which looks up by session Id and keys its own isolated
   lockout on `Username = ?` with an EMPTY IpAddress, and MUST NOT share the
   login bucket per #1466). A future third login surface following the house
   per-IP-lockout pattern is then automatically DEMANDED to check the shared
   account bucket. */
$loginSurfaceFiles = [];
foreach ($phpFiles as $pf) {
    $src = tarlStripComments((string)file_get_contents($pf));
    $isLoginLockoutSurface = strpos($src, 'password_verify(') !== false
        && strpos($src, 'IpAddress = ? AND Success = 0') !== false;
    if ($isLoginLockoutSurface) { $loginSurfaceFiles[] = $pf; }
}
tarl(count($loginSurfaceFiles) >= 2,
    '3.16 at least the two known login surfaces (api.php + manage/includes/auth.php) are detected (found '
        . count($loginSurfaceFiles) . ')');
$missingAcctBucket = [];
foreach ($loginSurfaceFiles as $pf) {
    $src = tarlStripComments((string)file_get_contents($pf));
    if (strpos($src, 'checkRateLimit(IHYMNS_AUTH_ACCT_ACTION') === false
        || strpos($src, 'recordRateLimitHit(IHYMNS_AUTH_ACCT_ACTION') === false) {
        $missingAcctBucket[] = str_replace($docroot . '/', '', $pf);
    }
}
tarl($missingAcctBucket === [],
    '3.17 every login-lockout surface checks AND records the shared per-account bucket (missing: '
        . implode(',', $missingAcctBucket) . ')');

/* --- 3f. The /manage attemptLogin() half specifically ------------------- */
$attemptFn = $manageSrc !== '' ? tarlExtractFunction($manageSrc, 'attemptLogin') : null;
tarl($attemptFn !== null, '3.18 attemptLogin() is defined in manage/includes/auth.php');
if ($attemptFn !== null) {
    $mCheck  = strpos($attemptFn, 'checkRateLimit(IHYMNS_AUTH_ACCT_ACTION');
    $mVerify = strpos($attemptFn, 'password_verify(');
    $mRecord = strpos($attemptFn, 'recordRateLimitHit(IHYMNS_AUTH_ACCT_ACTION');
    tarl($mCheck !== false && $mVerify !== false && $mCheck < $mVerify,
        '3.19 /manage attemptLogin() checks the shared account bucket BEFORE password_verify()');
    tarl($mRecord !== false && $mVerify !== false && $mRecord > $mVerify,
        '3.20 /manage attemptLogin() records a failure into the shared account bucket after the verify branch opens');
    tarl(strpos($attemptFn, 'authLoginAcctKey($username)') !== false,
        '3.21 /manage attemptLogin() derives its key via the shared helper (no inline copy, same fold as api.php)');
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
