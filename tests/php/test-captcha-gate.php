<?php

declare(strict_types=1);

/**
 * iHymns — CAPTCHA core guard (#947 / #340)
 * ============================================================================
 *
 * Pins the dormant, provider-agnostic CAPTCHA core in
 * appWeb/public_html/includes/captcha.php:
 *
 *   SECTION 1 — the PURE core behaves (no DB, no network): captchaResolveConfig
 *      (null for none/unknown/reserved/missing-either-key; a full array for each
 *      SELECTABLE provider), captchaParseForms (unknown dropped, ''→[],
 *      whitespace/dupes folded), captchaGateDecision (dormant/disabled→null,
 *      enabled+bad-token→the exact 403 array, enabled+ok→null). Reflection
 *      proves captchaGateDecision has NO account/identity parameter — the #1028
 *      anti-enumeration signature property.
 *
 *   SECTION 2 — the provider REGISTRY is self-consistent, derived by iterating
 *      it (never a typed provider list here): every selectable entry carries a
 *      complete field set, every URL is https, and the browser could actually
 *      load the widget script under the CSP the same entry emits.
 *
 *   SECTION 3 — secret CUSTODY: captcha_secret_key is registered for
 *      encryption, appears in NO client emit ($publicKeys in api.php, the
 *      captchaClientConfig() body, js/, index.php), so the secret is
 *      server-proxied only (rule #38).
 *
 *   SECTION 7 — the PURE OUTAGE CORE (the provider-outage grace window):
 *      captchaOutageDecision (the self-closing truth table incl. the STALE-down
 *      and future-stamp rows), captchaHealthNormaliseState (every malformed
 *      shape degrades to a state that ENFORCES), captchaHealthNextState (incl.
 *      the healthy-restamp skip, and the proof that a down→up recovery can
 *      NEVER be skipped), captchaSecretErrorCodeHit, captchaCspOriginsFor's
 *      dormancy row, and captchaKillFilePresent against a real temp directory.
 *
 *   SECTION 8 — the ANTI-BYPASS wiring, which is the whole security argument of
 *      the fallback: the ALLOW decision must derive EXCLUSIVELY from
 *      server-side observations. Asserted structurally — no superglobal or
 *      request body is readable from any decision function; captchaGate's
 *      ordering (dormant short-circuit → verify → strict-list → freshness →
 *      decision) is pinned; captchaOutageDecision has exactly ONE call site and
 *      it is inside captchaGate; api.php may reference only the two telemetry
 *      helpers out of the entire health family (list DERIVED from the
 *      declarations in captcha.php, never typed here); and the kill-file check
 *      precedes captchaConfig's settings reads.
 *
 *   SECTION 9 — DORMANCY, proven by EXECUTION rather than by reading the code:
 *      every public entry point is called for real on this (unconfigured) test
 *      environment and must return its dormant value, and the whole sweep must
 *      finish far inside a single probe's connect timeout — which is what
 *      demonstrates no outbound call was made.
 *
 *   SECTION 10 — the break-glass kill file is genuinely web-denied.
 *
 * The enforcement-site COVERAGE, gate ORDERING and conditional-CSP lockstep
 * assertions are added in the SAME file when the call sites land (C4) — see the
 * "C4 (added when the gates land)" markers below.
 *
 * The core requires DB-free (getAppSetting() only hits the DB when CALLED, not
 * on require), so this test requires the REAL file and calls the REAL pure
 * functions — no re-eval'd copies to drift.
 *
 *   php tests/php/test-captcha-gate.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see .claude/account-security-1027-947-340-plan.md §6.2
 * @see https://www.php.net/manual/en/class.reflectionfunction.php
 */

$docroot = dirname(__DIR__, 2) . '/appWeb/public_html';
require_once $docroot . '/includes/captcha.php';        /* DB-free to require */

$failures = 0;
$passed   = 0;

/** Record one assertion. */
function cg(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Blank out comments, preserving byte offsets (source-scan hygiene). */
function cgStripComments(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
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

/**
 * Return ONE function's body, comment-stripped, by brace matching.
 *
 * ELI5: hand back just the code inside one named function, so an assertion
 * about "what this function does" cannot accidentally be satisfied by a
 * different function further down the file.
 *
 * WHY BRACE MATCHING AND NOT A REGEX WINDOW: rule #34's own worked example.
 * test-editor-api2-contract.php shipped green with a 120-character regex window
 * that silently truncated before the code it was meant to read, and
 * test-editor-deep-links.js was wrong twice for the same species of reason. A
 * fixed window over a heavily-annotated file is a scanner that under-reports,
 * and an under-reporting scanner is worse than none because its tick is read as
 * coverage. Braces are counted from the token stream, so a brace inside a
 * string literal cannot throw the count off.
 *
 * @return string|null null when the function is not declared in $src
 */
function cgFuncBody(string $src, string $name): ?string
{
    $tokens = token_get_all($src);
    $n      = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_FUNCTION) { continue; }
        /* Next meaningful token must be the name we want. */
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE], true)) { $j++; }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) { continue; }
        /* Walk to the opening brace of the body, then brace-match. */
        $depth = 0;
        $body  = '';
        $started = false;
        for ($k = $j; $k < $n; $k++) {
            $tk  = $tokens[$k];
            $txt = is_array($tk) ? $tk[1] : $tk;
            if ($txt === '{') {
                $depth++;
                if (!$started) { $started = true; continue; }   /* skip the outer brace itself */
            } elseif ($txt === '}') {
                $depth--;
                if ($started && $depth === 0) { return $body; }
            }
            if ($started) { $body .= $txt; }
        }
        return $started ? $body : null;
    }
    return null;
}

/** Count non-overlapping occurrences of $needle in $hay. */
function cgCount(string $hay, string $needle): int
{
    return substr_count($hay, $needle);
}

/* ===========================================================================
 * SECTION 1 — pure core behaviour
 * ======================================================================== */

/* captchaResolveConfig — dormant/invalid cases → null. */
cg(captchaResolveConfig('none', 'site', 'secret') === null, "1.1 provider 'none' → null");
cg(captchaResolveConfig('', 'site', 'secret') === null, '1.2 empty provider → null');
cg(captchaResolveConfig('does_not_exist', 'site', 'secret') === null, '1.3 unknown provider → null');
cg(captchaResolveConfig('recaptcha_v3', 'site', 'secret') === null, '1.4 reserved (non-selectable) provider → null');
cg(captchaResolveConfig('turnstile', '', 'secret') === null, '1.5 missing site key → null');
cg(captchaResolveConfig('turnstile', 'site', '') === null, '1.6 missing secret key → null');

/* captchaResolveConfig — every SELECTABLE provider resolves to a complete
   config, derived by iterating the registry (no typed provider list here). */
$selectable = array_keys(array_filter(captchaProviders(), static fn($p) => !empty($p['selectable'])));
cg(count($selectable) >= 2, '1.7 at least two selectable providers exist (found ' . count($selectable) . ')');
$allResolve = true;
foreach ($selectable as $pk) {
    $c = captchaResolveConfig($pk, 'sitekey123', 'secretkey123');
    if (!is_array($c) || ($c['provider'] ?? null) !== $pk
        || ($c['site_key'] ?? null) !== 'sitekey123'
        || ($c['secret_key'] ?? null) !== 'secretkey123') {
        $allResolve = false;
    }
}
cg($allResolve, '1.8 every selectable provider resolves to a config carrying provider + both keys');

/* captchaParseForms. */
cg(captchaParseForms('') === [], '1.9 empty CSV → []');
cg(captchaParseForms('   ') === [], '1.10 whitespace-only CSV → []');
cg(captchaParseForms('login, bogus ,login') === ['login'], '1.11 unknown dropped, whitespace + duplicate folded');
$allForms = captchaFormKeys();
cg(captchaParseForms(implode(',', $allForms)) === $allForms, '1.12 every valid form key round-trips');

/* captchaGateDecision — the pure verdict. */
$cfg = captchaResolveConfig('turnstile', 'site', 'secret');
cg(captchaGateDecision(null, [], 'login', false) === null, '1.13 dormant (null config) → allowed');
cg(captchaGateDecision($cfg, ['login'], 'song_request', false) === null, '1.14 config set but THIS form not enabled → allowed');
$refuse = captchaGateDecision($cfg, ['login'], 'login', false);
cg(is_array($refuse) && ($refuse['reason'] ?? null) === 'captcha_required'
   && ($refuse['reason'] ?? null) === IHYMNS_CAPTCHA_REASON
   && isset($refuse['error']) && is_string($refuse['error']),
   '1.15 enabled form + bad token → 403 array with reason:captcha_required');
cg(captchaGateDecision($cfg, ['login'], 'login', true) === null, '1.16 enabled form + good token → allowed');

/* Reflection — captchaGateDecision has NO account/identity parameter (the
   #1028 signature property: existence cannot influence the refusal). */
$ref = new ReflectionFunction('captchaGateDecision');
$paramNames = array_map(static fn($p) => strtolower($p->getName()), $ref->getParameters());
$leaky = array_filter($paramNames, static fn(string $n) => (bool)preg_match(
    '/(exist|account|user|email|username|found|identity)/', $n
));
cg($leaky === [], '1.17 captchaGateDecision has no account/identity parameter (' . implode(',', $paramNames) . ')');

/* ===========================================================================
 * SECTION 2 — provider registry self-consistency (iterated, not typed)
 * ======================================================================== */

$reg = captchaProviders();
cg($reg !== [], '2.1 the provider registry is non-empty');

/** Registrable-ish domain (last two labels) of a host, for wildcard matching. */
function cgRegistrable(string $host): string
{
    $parts = explode('.', $host);
    return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
}

/** Does a scheme://host appear in a cspScript origin list (exact or via a
 *  *.domain wildcard)? */
function cgOriginCovered(string $host, array $cspOrigins): bool
{
    $reg = cgRegistrable($host);
    foreach ($cspOrigins as $o) {
        $oh = (string)parse_url($o, PHP_URL_HOST);
        if ($oh === '') { $oh = preg_replace('#^https?://#', '', $o); }
        if ($oh === $host) { return true; }                 /* exact */
        if (str_starts_with($oh, '*.') && cgRegistrable(substr($oh, 2)) === $reg) { return true; }
        if ($oh === $reg) { return true; }                  /* registrable root covers a subdomain */
    }
    return false;
}

foreach ($reg as $key => $entry) {
    if (empty($entry['selectable'])) {
        /* Reserved entries need only a label + selectable=false. */
        cg(isset($entry['label']) && ($entry['selectable'] ?? true) === false,
            "2.x reserved provider '$key' is label + selectable=false");
        continue;
    }
    /* cspConnect + secretErrorCodes are required on EVERY selectable entry
       (the outage fallback). A provider added without them would ship a widget
       that may be CSP-blocked from talking to its own back end, and a
       mis-pasted secret on it would be indistinguishable from a bad answer. */
    $fields = ['label', 'script', 'verify', 'field', 'widgetClass', 'renderGlobal',
               'cspScript', 'cspFrame', 'cspConnect', 'secretErrorCodes'];
    $missing = array_values(array_filter($fields, static fn($f) => !isset($entry[$f]) || $entry[$f] === '' || $entry[$f] === []));
    cg($missing === [], "2.a '$key' carries every required field (missing: " . implode(',', $missing) . ')');
    cg(is_array($entry['cspConnect'] ?? null) && is_array($entry['secretErrorCodes'] ?? null),
        "2.a2 '$key' cspConnect + secretErrorCodes are arrays");
    /* Every connect-src origin must be an https origin — a CSP directive is not
       a place a bare host or an http:// origin can be allowed to appear. */
    $badConnect = array_values(array_filter((array)($entry['cspConnect'] ?? []),
        static fn($o) => !is_string($o) || !str_starts_with($o, 'https://')));
    cg($badConnect === [], "2.a3 '$key' every cspConnect origin is https (bad: " . implode(',', $badConnect) . ')');
    cg(str_starts_with((string)$entry['script'], 'https://'), "2.b '$key' script URL is https");
    cg(str_starts_with((string)$entry['verify'], 'https://'), "2.c '$key' verify URL is https (server-side SSRF-safe constant)");
    /* The browser must be able to load the widget script under the SAME entry's
       cspScript — otherwise enabling the provider would be a dead widget. */
    $scriptHost = (string)parse_url((string)$entry['script'], PHP_URL_HOST);
    cg(cgOriginCovered($scriptHost, (array)$entry['cspScript']),
        "2.d '$key' widget script host ($scriptHost) is covered by its own cspScript");
}

/* ===========================================================================
 * SECTION 3 — secret custody (rule #38: server-proxied, never browser-facing)
 * ======================================================================== */

require_once $docroot . '/includes/secret_crypto.php';
cg(in_array('captcha_secret_key', secretSettingKeys(), true),
    '3.1 captcha_secret_key is registered in secretSettingKeys() (encrypted at rest)');

/* captchaClientConfig() must not carry the secret — assert its SOURCE body
   references no secret_key (structural: the emit cannot regress to leaking). */
$captchaSrc = cgStripComments((string)file_get_contents($docroot . '/includes/captcha.php'));
if (preg_match('/function\s+captchaClientConfig\s*\([^)]*\)\s*:\s*\??array\s*\{(.*?)\n\}/s', $captchaSrc, $m)) {
    cg(strpos($m[1], 'secret') === false,
        '3.2 captchaClientConfig() body references no secret (never emits the secret key)');
} else {
    cg(false, '3.2 captchaClientConfig() body is locatable for the no-secret scan');
}

/* The api.php public-settings emit ($publicKeys) must not include the secret. */
$apiSrc = cgStripComments((string)file_get_contents($docroot . '/api.php'));
if (preg_match('/\$publicKeys\s*=\s*\[(.*?)\]\s*;/s', $apiSrc, $m)) {
    cg(strpos($m[1], 'captcha_secret_key') === false,
        '3.3 api.php $publicKeys does not include captcha_secret_key');
} else {
    cg(false, '3.3 api.php $publicKeys array is locatable');
}

/* Zero references to the secret key name anywhere a browser can reach it. */
$browserReachable = [
    $docroot . '/index.php',
];
$browserDirs = [$docroot . '/js', $docroot . '/includes/pages', $docroot . '/includes/partials'];
$secretLeaks = [];
foreach ($browserReachable as $bf) {
    if (is_readable($bf) && strpos((string)file_get_contents($bf), 'captcha_secret_key') !== false) {
        $secretLeaks[] = str_replace($docroot . '/', '', $bf);
    }
}
foreach ($browserDirs as $bd) {
    if (!is_dir($bd)) { continue; }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bd, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if (!$f->isFile()) { continue; }
        if (strpos((string)file_get_contents($f->getPathname()), 'captcha_secret_key') !== false) {
            $secretLeaks[] = str_replace($docroot . '/', '', $f->getPathname());
        }
    }
}
cg($secretLeaks === [],
    '3.4 no browser-reachable file (index.php, js/, includes/pages, includes/partials) names captcha_secret_key (leaks: '
        . implode(',', $secretLeaks) . ')');

/* ===========================================================================
 * SECTION 4 — enforcement COVERAGE (tree-derived) + gate ORDERING (C4)
 * ======================================================================== */

/** All docroot .php files, comment-stripped, keyed by relative path. */
$phpSources = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $phpSources[str_replace($docroot . '/', '', $f->getPathname())] = cgStripComments((string)file_get_contents($f->getPathname()));
    }
}

/* Every form key (derived by CALLING captchaFormKeys(), never a typed list)
   must have at least one captchaGate('<key>' enforcement site somewhere under
   the docroot. A new form key is then automatically demanded a gate. */
$uncovered = [];
foreach (captchaFormKeys() as $fk) {
    $needle = "captchaGate('" . $fk . "'";
    $found = false;
    foreach ($phpSources as $src) {
        if (strpos($src, $needle) !== false) { $found = true; break; }
    }
    if (!$found) { $uncovered[] = $fk; }
}
cg($uncovered === [], '4.1 every captchaFormKeys() key has a captchaGate() enforcement site (uncovered: '
    . implode(',', $uncovered) . ')');

$apiRel = 'api.php';
$api = $phpSources[$apiRel] ?? '';

/** Position of $target inside the case that begins with $gate, or -1. Bounds
 *  the search to the next `\n        case '` so a later case can't satisfy it. */
function cgOrder(string $src, string $gate, string $target): int
{
    $g = strpos($src, $gate);
    if ($g === false) { return -1; }
    $next = preg_match('/\n        case \'/', $src, $m, PREG_OFFSET_CAPTURE, $g) ? $m[0][1] : strlen($src);
    $t = strpos($src, $target, $g);
    return ($t !== false && $t < $next && $t > $g) ? 1 : 0;
}

cg(cgOrder($api, "captchaGate('registration'", 'password_hash(') === 1,
    '4.2 auth_register gate precedes password_hash() (no bcrypt spent on a refused signup)');
cg(cgOrder($api, "captchaGate('password_reset'", 'generatePasswordResetToken(') === 1,
    '4.3 auth_forgot_password gate precedes generatePasswordResetToken()');
cg(cgOrder($api, "captchaGate('email_login'", 'generateEmailLoginToken(') === 1,
    '4.4 auth_email_login_request gate precedes generateEmailLoginToken()');
/* The email-login per-IP bucket must ALSO precede token generation (budget-first). */
$ipBucketPos = strpos($api, "checkRateLimit('auth_email_login_request_ip'");
$emailTokPos = strpos($api, 'generateEmailLoginToken(');
cg($ipBucketPos !== false && $emailTokPos !== false && $ipBucketPos < $emailTokPos,
    '4.5 the email-login per-IP bucket precedes generateEmailLoginToken() (budget-first)');

/* song_request_submit: honeypot BEFORE the gate (a 403 must never tip off a
   honeypot bot), gate BEFORE the INSERT. */
$honeyPos  = strpos($api, "if (\$honey !== '')");
$srGatePos = strpos($api, "captchaGate('song_request'");
cg($honeyPos !== false && $srGatePos !== false && $honeyPos < $srGatePos,
    '4.6 song_request honeypot check precedes the CAPTCHA gate (a 403 never tips off a honeypot bot)');
/* cgOrder bounds the INSERT search to the song_request_submit case, so the
   OTHER INSERT INTO tblSongRequests (in the song-correction case) can't
   satisfy it. */
cg(cgOrder($api, "captchaGate('song_request'", 'INSERT INTO tblSongRequests') === 1,
    '4.7 song_request CAPTCHA gate precedes the INSERT (same case)');

/* auth_login: gate after both buckets, before password_verify(). */
cg(cgOrder($api, "captchaGate('login'", 'password_verify(') === 1,
    '4.8 auth_login gate precedes password_verify()');

/* ===========================================================================
 * SECTION 5 — conditional CSP + app_status emit + hostname-literal ban (C4)
 * ======================================================================== */

$indexSrc = $phpSources['index.php'] ?? '';
cg(strpos($indexSrc, 'captchaCspOrigins(') !== false,
    '5.1 index.php builds the CSP captcha origins via captchaCspOrigins()');
cg(strpos($indexSrc, '{$cspCaptchaScript}') !== false && strpos($indexSrc, '{$cspCaptchaFrame}') !== false,
    '5.2 index.php appends the captcha origins to script-src and frame-src');

/* ALL THREE lists must reach a directive. captchaCspOrigins() returns three;
   a list that is produced and never appended is a silently dead widget nobody
   would see until a real solve failed, so each is asserted INTO ITS OWN
   directive rather than merely "mentioned somewhere in the file". */
$cspDirectiveChecks = [
    'script-src'  => '{$cspCaptchaScript}',
    'frame-src'   => '{$cspCaptchaFrame}',
    'connect-src' => '{$cspCaptchaConnect}',
];
/* Split the $cspDirectives array literal into its ELEMENTS.
   ⚠️ THE OBVIOUS VERSION OF THIS CHECK IS WRONG, and was — the first draft
   bounded each directive on /"<name>[^"]*"/ and reported a confident false
   FAILURE for frame-src, whose entry is a CONCATENATION
   ("frame-src 'self' " . APP_CONFIG[...] . " …{$cspCaptchaFrame}"): the match
   ends at the first closing quote, long before the interpolation. That is
   precisely the bug rule #34 records against test-editor-deep-links.js
   ("bounding an href on 'no quotes' truncates"), reproduced here on first run.
   So elements are delimited STRUCTURALLY — a new element begins at a line whose
   first non-space character opens a string — which survives concatenation,
   multi-line entries and interpolation alike. */
$arrStart = strpos($indexSrc, '$cspDirectives = [');
$arrEnd   = $arrStart !== false ? strpos($indexSrc, "\n];", $arrStart) : false;
$cspElements = [];
if ($arrStart !== false && $arrEnd !== false) {
    $current = null;
    foreach (explode("\n", substr($indexSrc, $arrStart, $arrEnd - $arrStart)) as $line) {
        if (str_starts_with(ltrim($line), '"')) {
            if ($current !== null) { $cspElements[] = $current; }
            $current = $line;
        } elseif ($current !== null) {
            $current .= "\n" . $line;
        }
    }
    if ($current !== null) { $cspElements[] = $current; }
}
cg($cspElements !== [], '5.2a the $cspDirectives array literal is parseable (' . count($cspElements) . ' directives)');

$unconsumed = [];
foreach ($cspDirectiveChecks as $directive => $var) {
    $element = null;
    foreach ($cspElements as $el) {
        if (str_starts_with(ltrim($el), '"' . $directive . ' ')) { $element = $el; break; }
    }
    if ($element === null) { $unconsumed[] = "$directive (directive not found)"; continue; }
    if (strpos($element, $var) === false) { $unconsumed[] = "$directive (missing $var)"; }
}
cg($unconsumed === [],
    '5.2b index.php appends each captcha CSP list into its OWN directive (unconsumed: '
        . implode(', ', $unconsumed) . ')');

/* And the builder must produce all three from the ONE registry read. */
cg(substr_count($indexSrc, "\$captchaCsp['") >= 3 || substr_count($indexSrc, '$captchaCsp[') >= 3,
    '5.2c index.php reads all three lists off the single captchaCspOrigins() result');

/* The app_status emit uses captchaClientConfig() (never the secret). */
cg(strpos($api, 'captchaClientConfig()') !== false,
    '5.3 api.php app_status emits the captcha client config via captchaClientConfig()');

/* Captcha-provider hostnames live ONLY in includes/captcha.php (the registry) —
   index.php and every other file must reach them through captchaCspOrigins(),
   never a literal (rule #35: the registry is the one source). Comment-stripped
   so a doc mention can't false-positive; scans php + js. */
$hostnamePatterns = ['challenges.cloudflare.com', 'js.hcaptcha.com', 'api.hcaptcha.com', '*.hcaptcha.com', 'recaptcha/api'];
$literalLeaks = [];
$scanFiles = $phpSources;   /* php (already comment-stripped) */
foreach (['js'] as $dir) {  /* + js/ (raw is fine — a hostname in a JS comment would still be a leak to investigate) */
    $jd = $docroot . '/' . $dir;
    if (!is_dir($jd)) { continue; }
    $jrii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jd, FilesystemIterator::SKIP_DOTS));
    foreach ($jrii as $jf) {
        if ($jf->isFile() && strtolower($jf->getExtension()) === 'js') {
            $scanFiles[str_replace($docroot . '/', '', $jf->getPathname())] = (string)file_get_contents($jf->getPathname());
        }
    }
}
foreach ($scanFiles as $rel => $src) {
    if ($rel === 'includes/captcha.php') { continue; }   /* the registry — allowed */
    foreach ($hostnamePatterns as $pat) {
        if (strpos($src, $pat) !== false) { $literalLeaks[] = "$rel ($pat)"; break; }
    }
}
cg($literalLeaks === [],
    '5.4 no captcha-provider hostname literal outside includes/captcha.php (leaks: '
        . implode(', ', $literalLeaks) . ')');

/* 5.5 (adversarial-review follow-up) — index.php must inline NONE of the
   provider CSP origins; they belong ONLY via captchaCspOrigins(). Derived from
   the REGISTRY (every selectable provider's cspScript+cspFrame hosts), so a new
   provider's hosts are covered automatically, and the reCAPTCHA-v2 bare hosts
   (www.google.com / www.gstatic.com) that 5.4's fixed pattern list omits (they
   appear legitimately elsewhere in the tree) are caught here — SCOPED to
   index.php, where those hosts have no legitimate non-captcha use. */
$providerCspHosts = [];
foreach (captchaProviders() as $pe) {
    if (empty($pe['selectable'])) { continue; }
    foreach (array_merge((array)($pe['cspScript'] ?? []), (array)($pe['cspFrame'] ?? []), (array)($pe['cspConnect'] ?? [])) as $o) {
        $h = preg_replace('#^https?://#', '', (string)$o);
        $h = ltrim((string)$h, '*.');   /* '*.hcaptcha.com' → 'hcaptcha.com' */
        if ($h !== '' && !in_array($h, $providerCspHosts, true)) { $providerCspHosts[] = $h; }
    }
}
$indexInlined = [];
foreach ($providerCspHosts as $h) {
    if (strpos($indexSrc, $h) !== false) { $indexInlined[] = $h; }
}
cg($indexInlined === [],
    '5.5 index.php inlines NO provider CSP host (they arrive via captchaCspOrigins) — inlined: '
        . implode(',', $indexInlined));

/* ===========================================================================
 * SECTION 6 — the admin configuration card (C6)
 * ======================================================================== */

$cfgRaw = (string)file_get_contents($docroot . '/manage/configuration.php');
$cfg    = cgStripComments($cfgRaw);

cg(strpos($cfg, "\$action === 'save_captcha'") !== false,
    '6.1 configuration.php has a save_captcha handler');
/* Provider validation: rejects a non-selectable / unknown provider. */
cg(strpos($cfg, "empty(\$providers[\$provIn]['selectable'])") !== false,
    '6.2 the save handler rejects a non-selectable provider');
/* Forms validated against the registry list (⊆ captchaFormKeys()). */
cg(strpos($cfg, 'captchaFormKeys()') !== false && strpos($cfg, "\$_POST['captcha_forms']") !== false,
    '6.3 the save handler validates the ticked forms against captchaFormKeys()');
/* The secret is a PASSWORD input (never echoed back / exposed as text), and its
   VALUE is never read into a render var — only whether it is SET. */
cg((bool)preg_match('/type="password"[^>]*name="captcha_secret_key"/', $cfgRaw),
    '6.4 the secret key is a password input (never rendered as text)');
cg(strpos($cfg, 'value="<?= htmlspecialchars($captchaSecretVal') === false
   && strpos($cfg, '$captchaSecretVal') === false,
    '6.5 the secret VALUE is never read into a render variable (only $captchaSecretSet)');
/* The card is fed from the registry, not a typed provider/form list. */
cg(strpos($cfg, '$captchaProvidersReg') !== false && strpos($cfg, 'captchaProviders()') !== false,
    '6.6 the provider <select> is built from captchaProviders() (no typed list)');

/* ===========================================================================
 * SECTION 7 — the PURE OUTAGE CORE (the provider-outage grace window)
 *
 * Every row below drives the REAL functions. The whole policy is deliberately
 * pure so that "when does the window open?" is a table, not an argument.
 * ======================================================================== */

$T = 1_700_000_000;   /* a fixed "now" so every row is deterministic */

/** Build a normalised state from a status + a checkedAt, via the real coercer. */
$mkState = static fn(string $status, int $checkedAt): array => captchaHealthNormaliseState(
    (string)json_encode(['status' => $status, 'checkedAt' => $checkedAt])
);

/* --- 7a. captchaOutageDecision: THE truth table ------------------------- */
cg(captchaOutageDecision($mkState('up', $T - 5), $T) === 'enforce',
    '7.1 (up, fresh) → enforce (a healthy provider never admits)');
cg(captchaOutageDecision($mkState('down', $T - 5), $T) === 'admit',
    '7.2 (down, fresh) → admit (the grace window)');
cg(captchaOutageDecision($mkState('down', $T - (CAPTCHA_HEALTH_FRESH_SECONDS + 1)), $T) === 'enforce',
    '7.3 (down, STALE) → enforce — the SELF-CLOSING property');
cg(captchaOutageDecision($mkState('down', $T - CAPTCHA_HEALTH_FRESH_SECONDS), $T) === 'admit',
    '7.4 (down, exactly at the freshness boundary) → admit (boundary is inclusive)');
cg(captchaOutageDecision($mkState('misconfig', $T - 5), $T) === 'admit',
    '7.5 (misconfig, fresh) → admit (a wrong secret must not brick every form)');
cg(captchaOutageDecision($mkState('down', $T + 500), $T) === 'enforce',
    '7.6 (down, checkedAt in the FUTURE) → enforce (a bad clock cannot pin the window open)');
cg(captchaOutageDecision($mkState('down', 0), $T) === 'enforce',
    '7.7 (down, never actually checked) → enforce');
cg(captchaOutageDecision(captchaHealthColdState(), $T) === 'enforce',
    '7.8 cold state → enforce (a fresh install never admits)');
cg(captchaOutageDecision(captchaHealthNormaliseState('{not json'), $T) === 'enforce',
    '7.9 malformed stored state → enforce (fail-safe)');
cg(captchaOutageDecision(captchaHealthNormaliseState(''), $T) === 'enforce',
    '7.10 empty stored state (the migration seed) → enforce');
cg(captchaOutageDecision([], $T) === 'enforce',
    '7.11 an entirely empty array → enforce');

/* An unrecognised status word must NOT admit — it is coerced to the enforcing
   value, so a future/typo'd status can never become an accidental bypass. */
cg(captchaOutageDecision($mkState('totally_made_up', $T - 5), $T) === 'enforce',
    '7.12 unrecognised status word → enforce');

/* Reflection: the decision takes NO request-shaped input. This is the §3.2
   universal-bypass ban made structural — there is no parameter through which a
   header, body flag or client hint could reach the verdict, the same trick
   captchaGateDecision() uses for account enumeration. */
$refDec = new ReflectionFunction('captchaOutageDecision');
$decParams = array_map(static fn($pp) => strtolower($pp->getName()), $refDec->getParameters());
$decLeaky = array_filter($decParams, static fn(string $nm) => (bool)preg_match(
    '/(request|token|hint|header|client|form|body|ip|user|claim)/', $nm
));
cg($decLeaky === [] && count($decParams) === 2,
    '7.13 captchaOutageDecision takes only (state, now) — no request-shaped parameter ('
        . implode(',', $decParams) . ')');

/* --- 7b. captchaHealthNormaliseState ------------------------------------ */
$normCold = captchaHealthNormaliseState('');
cg($normCold === captchaHealthColdState(), '7.14 empty value normalises to exactly the cold state');
cg(captchaHealthNormaliseState('[1,2,3]')['status'] === 'up',
    '7.15 a JSON array (not an object) normalises to the enforcing status');
$normNeg = captchaHealthNormaliseState((string)json_encode([
    'status' => 'down', 'checkedAt' => -99, 'hintCount' => -4, 'admitCount' => -1, 'consecutiveFailures' => -7,
]));
cg($normNeg['checkedAt'] === 0 && $normNeg['hintCount'] === 0
   && $normNeg['admitCount'] === 0 && $normNeg['consecutiveFailures'] === 0,
    '7.16 negative counters/timestamps are clamped to 0 (a hand-edited row cannot go negative)');
cg(captchaHealthNormaliseState((string)json_encode(['status' => 'down', 'downSince' => null]))['downSince'] === null,
    '7.17 a null downSince survives normalisation as null');

/* Every declared status word must round-trip (derived from the vocabulary
   function, never typed here — a new status is covered automatically). */
$statusRoundTrip = true;
foreach (captchaHealthStatuses() as $st) {
    if (captchaHealthNormaliseState((string)json_encode(['status' => $st]))['status'] !== $st) {
        $statusRoundTrip = false;
    }
}
cg($statusRoundTrip, '7.18 every captchaHealthStatuses() value round-trips through normalisation');

/* --- 7c. captchaHealthNextState ----------------------------------------- */
$upFresh = $mkState('up', $T - 1);
cg(captchaHealthNextState($upFresh, 'up', 0, 200, $T) === null,
    '7.19 up→up within the probe interval is SKIPPED (no needless write per sign-in)');
cg(captchaHealthNextState($mkState('up', $T - (CAPTCHA_HEALTH_PROBE_MIN_INTERVAL + 1)), 'up', 0, 200, $T) !== null,
    '7.20 up→up after the probe interval DOES re-stamp');

/* THE ONE THAT MUST NEVER BE SKIPPED: recovery. If a down→up observation could
   be skipped, the window would never close. */
$downFresh = $mkState('down', $T - 1);
$recovered = captchaHealthNextState($downFresh, 'up', 0, 200, $T);
cg($recovered !== null && $recovered['status'] === 'up' && $recovered['checkedAt'] === $T
   && $recovered['downSince'] === null && $recovered['consecutiveFailures'] === 0,
    '7.21 down→up recovery is NEVER skipped, clears downSince and zeroes the failure count');
cg(captchaOutageDecision((array)$recovered, $T) === 'enforce',
    '7.22 …and the recovered state immediately enforces again (the window closes)');

$firstDown = captchaHealthNextState($upFresh, 'down', 7, 0, $T);
cg($firstDown !== null && $firstDown['downSince'] === $T && $firstDown['consecutiveFailures'] === 1
   && $firstDown['hintCount'] === 0 && $firstDown['admitCount'] === 0,
    '7.23 up→down stamps downSince, starts the failure count and zeroes the counters');

$stillDown = captchaHealthNextState(
    ['status' => 'down', 'checkedAt' => $T - 10, 'downSince' => $T - 300,
     'consecutiveFailures' => 4, 'hintCount' => 9, 'admitCount' => 12],
    'down', 7, 0, $T
);
cg($stillDown !== null && $stillDown['downSince'] === $T - 300 && $stillDown['consecutiveFailures'] === 5
   && $stillDown['hintCount'] === 9 && $stillDown['admitCount'] === 12,
    '7.24 down→down carries downSince + counters forward and increments the failure count');
cg(captchaHealthNextState($upFresh, 'nonsense', 0, 0, $T) === null,
    '7.25 an unrecognised observation writes nothing at all');

/* --- 7d. captchaSecretErrorCodeHit -------------------------------------- */
$sec = ['missing-input-secret', 'invalid-input-secret'];
cg(captchaSecretErrorCodeHit(['success' => false, 'error-codes' => ['invalid-input-secret']], $sec) === true,
    '7.26 a SECRET-side error code is detected (→ misconfig, not an outage)');
cg(captchaSecretErrorCodeHit(['success' => false, 'error-codes' => ['invalid-input-response']], $sec) === false,
    '7.27 a RESPONSE-side error code is NOT a misconfig (an ordinary bad token stays fail-closed)');
cg(captchaSecretErrorCodeHit(['success' => false, 'error-codes' => ['timeout-or-duplicate']], $sec) === false,
    '7.28 a replayed/expired token is NOT a misconfig');
cg(captchaSecretErrorCodeHit(['success' => false], $sec) === false,
    '7.29 no error-codes key at all → not a misconfig');
cg(captchaSecretErrorCodeHit(['success' => false, 'error-codes' => 'invalid-input-secret'], $sec) === false,
    '7.30 a non-array error-codes value is ignored (never trusted blindly)');
cg(captchaSecretErrorCodeHit(['success' => false, 'error-codes' => ['invalid-input-secret']], []) === false,
    '7.31 an empty per-provider list can never match');

/* --- 7e. captchaCspOriginsFor: the DORMANCY row ------------------------- */
cg(captchaCspOriginsFor(null) === ['script' => [], 'frame' => [], 'connect' => []],
    '7.32 captchaCspOriginsFor(null) is three EMPTY lists (the byte-identical CSP promise)');
$cspTs = captchaCspOriginsFor(captchaResolveConfig('turnstile', 'site', 'secret'));
cg(isset($cspTs['connect']) && $cspTs['connect'] !== [],
    '7.33 a configured provider yields a non-empty connect list');

/* --- 7f. captchaKillFilePresent against a REAL directory ---------------- */
$killDir = sys_get_temp_dir() . '/ihymns-captcha-guard-' . getmypid();
@mkdir($killDir, 0700, true);
cg(captchaKillFilePresent($killDir) === false, '7.34 kill file absent → false');
@file_put_contents($killDir . '/' . CAPTCHA_KILL_FILE_NAME, '');
cg(captchaKillFilePresent($killDir) === true, '7.35 kill file present → true (the break-glass fires)');
@unlink($killDir . '/' . CAPTCHA_KILL_FILE_NAME);
cg(captchaKillFilePresent($killDir) === false, '7.36 deleting the file switches CAPTCHA back on');
@rmdir($killDir);

/* ===========================================================================
 * SECTION 8 — ANTI-BYPASS WIRING (the security argument, made structural)
 * ======================================================================== */

$captchaBody = $phpSources['includes/captcha.php'] ?? '';
cg($captchaBody !== '', '8.0 includes/captcha.php source is readable for the wiring scan');

/* --- 8a. NO decision function may read the request ---------------------- */
/* Derived from the tree: every function DECLARED in captcha.php whose name
   marks it as part of the outage-decision path. A new one is covered the moment
   it is declared, without editing this list. */
preg_match_all('/function\s+(captcha(?:Outage|Health|Probe|Force|SecretError)\w*)\s*\(/', $captchaBody, $mDec);
$decisionFns = array_values(array_unique($mDec[1] ?? []));
cg(count($decisionFns) >= 8,
    '8.1 the outage-path function family is discoverable from the source (found ' . count($decisionFns) . ')');

$requestReaders = ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_FILES', 'php://input', 'getallheaders', 'HTTP_'];
$leakyFns = [];
foreach ($decisionFns as $fn) {
    $b = cgFuncBody($captchaBody, $fn);
    if ($b === null) { $leakyFns[] = "$fn (body not locatable)"; continue; }
    foreach ($requestReaders as $rr) {
        if (strpos($b, $rr) !== false) { $leakyFns[] = "$fn ($rr)"; break; }
    }
}
cg($leakyFns === [],
    '8.2 NO outage-path function reads the request — the allow decision cannot be client-driven (leaks: '
        . implode(', ', $leakyFns) . ')');

/* captchaGate itself may read REMOTE_ADDR (it is passed to the provider as
   remoteip), but nothing else request-borne beyond its own $token parameter. */
$gateBody = cgFuncBody($captchaBody, 'captchaGate');
cg($gateBody !== null, '8.3 captchaGate body is locatable');
$gateLeaks = array_values(array_filter(
    ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE', 'php://input', 'getallheaders'],
    static fn(string $rr) => $gateBody !== null && strpos($gateBody, $rr) !== false
));
cg($gateLeaks === [],
    '8.4 captchaGate reads no request superglobal beyond REMOTE_ADDR (leaks: ' . implode(',', $gateLeaks) . ')');

/* --- 8b. captchaGate ORDERING ------------------------------------------- */
$gb = (string)$gateBody;
$posShortCircuit = strpos($gb, 'return null');
$posVerify       = strpos($gb, 'captchaVerifyToken(');
$posStrict       = strpos($gb, 'captchaOutageStrictForms(');
$posFresh        = strpos($gb, 'captchaHealthEnsureFresh(');
$posDecide       = strpos($gb, 'captchaOutageDecision(');
$posAdmit        = strpos($gb, 'captchaHealthNoteAdmit(');
/* ⚠️ ORDERING ALONE IS NOT ENOUGH, and proving that took a mutation: replacing
   the condition with `if (false)` leaves a `return null` sitting textually
   before the verify call, so a position-only check stays GREEN while dormancy
   is completely broken (the test then dies of a TypeError deeper in, which is a
   crash, not a diagnosis). So the CONDITION itself is pinned, not just where
   its body sits. */
cg($gateBody !== null && strpos($gb, '$config === null') !== false
   && strpos($gb, 'in_array($form, $forms, true)') !== false,
    '8.5a captchaGate still tests BOTH dormancy conditions (config null, form not gated)');
$posDormantCond = strpos($gb, '$config === null');
cg($posDormantCond !== false && $posShortCircuit !== false && $posVerify !== false
   && $posDormantCond < $posShortCircuit && $posShortCircuit < $posVerify,
    '8.5 the dormant/ungated short-circuit precedes any verify call (no I/O when dormant)');
cg($posVerify !== false && $posStrict !== false && $posVerify < $posStrict,
    '8.6 the verify precedes the strict-list check');
cg($posStrict !== false && $posFresh !== false && $posStrict < $posFresh,
    '8.7 the strict-list check precedes the freshness probe (a strict form never triggers a probe)');
cg($posFresh !== false && $posDecide !== false && $posFresh < $posDecide,
    '8.8 the freshness probe precedes the decision (a STALE state is re-probed before it can admit)');
cg($posDecide !== false && $posAdmit !== false && $posDecide < $posAdmit,
    '8.9 the admit counter is bumped only after the decision says admit');

/* --- 8c. captchaOutageDecision has exactly ONE enforcement call site ----- */
cg(cgCount($captchaBody, 'captchaOutageDecision(') === 2,
    '8.10 captchaOutageDecision appears exactly twice in captcha.php — its declaration and ONE call');
cg($gateBody !== null && strpos($gateBody, 'captchaOutageDecision(') !== false,
    '8.11 …and that one call site is inside captchaGate');

/* Tree-wide: no file outside includes/captcha.php and the read-only /manage
   surfaces may call the decision. Classified by PATH, derived from the tree —
   never a typed file list. A second ENFORCEMENT consumer (e.g. an API handler
   admitting on its own) therefore fails here. */
$decisionCallers = [];
foreach ($phpSources as $rel => $src) {
    if ($rel === 'includes/captcha.php') { continue; }
    if (strpos($src, 'captchaOutageDecision(') !== false) { $decisionCallers[] = $rel; }
}
$badCallers = array_values(array_filter($decisionCallers, static fn(string $rel) => !str_starts_with($rel, 'manage/')));
cg($badCallers === [],
    '8.12 captchaOutageDecision is called only from captcha.php + read-only /manage surfaces (offenders: '
        . implode(',', $badCallers) . ')');

/* --- 8d. api.php touches ONLY the two telemetry helpers ------------------ */
/* The banned set is DERIVED: every outage-path function declared in captcha.php
   minus the two the hint endpoint is allowed to use. A new health function is
   therefore banned from api.php by default — the safe direction. */
$apiAllowedHealthFns = ['captchaHealthNoteHint', 'captchaHealthEnsureFresh'];
$apiHealthLeaks = [];
foreach ($decisionFns as $fn) {
    if (in_array($fn, $apiAllowedHealthFns, true)) { continue; }
    if (strpos($api, $fn . '(') !== false) { $apiHealthLeaks[] = $fn; }
}
cg($apiHealthLeaks === [],
    '8.13 api.php references only the two telemetry helpers from the outage family (offenders: '
        . implode(',', $apiHealthLeaks) . ')');

/* The hint case body specifically: it may not decide, gate, or write policy. */
$hintCasePos = strpos($api, "case 'captcha_widget_health'");
cg($hintCasePos !== false, '8.14 the captcha_widget_health case exists in api.php');
if ($hintCasePos !== false) {
    $hintEnd  = strpos($api, "\n        default:", $hintCasePos);
    $hintCase = substr($api, $hintCasePos, ($hintEnd !== false ? $hintEnd - $hintCasePos : 4000));
    $hintBanned = ['captchaOutageDecision(', 'captchaGateDecision(', 'captchaGate(',
                   'CAPTCHA_SETTING_FORMS', 'CAPTCHA_SETTING_STRICT_FORMS', 'setAppSetting('];
    $hintLeaks = array_values(array_filter($hintBanned, static fn(string $b) => strpos($hintCase, $b) !== false));
    cg($hintLeaks === [],
        '8.15 the hint case decides nothing and writes no policy (offenders: ' . implode(',', $hintLeaks) . ')');
    cg(strpos($hintCase, 'captchaConfig() === null') !== false,
        '8.16 the hint endpoint is dormant-gated (it does not observably exist when unconfigured)');
    cg(strpos($hintCase, 'captchaHealthNoteHint(') !== false
       && strpos($hintCase, 'captchaHealthEnsureFresh(') !== false,
        '8.17 the hint endpoint does exactly its two allowed things (counter + ask the server to look)');
}

/* --- 8e. the counter bumpers may NEVER touch status/checkedAt ------------ */
$bumpBody = cgFuncBody($captchaBody, 'captchaHealthBumpCounter');
cg($bumpBody !== null && strpos($bumpBody, 'checkedAt') === false && strpos($bumpBody, "'status'") === false,
    '8.18 captchaHealthBumpCounter never writes status/checkedAt (a counter cannot wedge the window open)');

/* Only ONE function computes a new state, and it has ONE caller. */
cg(cgCount($captchaBody, 'captchaHealthNextState(') === 2,
    '8.19 captchaHealthNextState has exactly one call site (one place decides the next state)');
$recordBody = cgFuncBody($captchaBody, 'captchaHealthRecordObservation');
cg($recordBody !== null && strpos($recordBody, 'captchaHealthNextState(') !== false,
    '8.20 …and that caller is captchaHealthRecordObservation (the ONE state writer)');

/* --- 8f. the kill-file check precedes captchaConfig's settings reads ----- */
$cfgBody = cgFuncBody($captchaBody, 'captchaConfig');
cg($cfgBody !== null, '8.21 captchaConfig body is locatable');
$posKill  = $cfgBody !== null ? strpos($cfgBody, 'captchaKillFilePresent(') : false;
$posSetting = $cfgBody !== null ? strpos($cfgBody, 'getAppSetting(') : false;
cg($posKill !== false && $posSetting !== false && $posKill < $posSetting,
    '8.22 the CAPTCHA_DISABLED check runs BEFORE captchaConfig reads any setting '
        . '(the break-glass works when the settings are the thing that is wrong)');

/* --- 8g. the app_status emit carries NO health/window field -------------- */
$statusPos = strpos($api, '$captchaStatusPayload');
cg($statusPos !== false, '8.23 the app_status captcha payload construction is locatable');
if ($statusPos !== false) {
    $statusRegion = substr($api, $statusPos, 2000);
    $emitLeaks = array_values(array_filter(
        ['captchaHealthState(', 'captcha_health_state', 'captchaOutageDecision(', 'degraded', 'graceWindow'],
        static fn(string $b) => strpos($statusRegion, $b) !== false
    ));
    cg($emitLeaks === [],
        '8.24 app_status emits NO health/window field — telling clients would be telling bots (offenders: '
            . implode(',', $emitLeaks) . ')');
}

/* ===========================================================================
 * SECTION 9 — DORMANCY, PROVEN BY EXECUTION
 *
 * This test environment has no database, and getAppSetting() returns its
 * default on any DB error — so every call below resolves exactly as it would on
 * a genuinely unconfigured install. These are the REAL public entry points, not
 * a reading of their source.
 * ======================================================================== */

$dormantStart = microtime(true);

/* Wrapped so a REGRESSION REPORTS ITSELF rather than killing the runner. A
   broken dormancy short-circuit sends a null config into a typed parameter and
   throws — and an uncaught throw here produced a bare exit code with no failing
   assertion named, which is the least useful possible signal from a guard. */
$gateNonNull = [];
foreach (captchaFormKeys() as $fk) {
    try {
        if (captchaGate($fk, null) !== null)               { $gateNonNull[] = "$fk (no token)"; }
        if (captchaGate($fk, 'some-token-value') !== null)  { $gateNonNull[] = "$fk (with token)"; }
    } catch (\Throwable $e) {
        $gateNonNull[] = "$fk (THREW: " . get_class($e) . ')';
    }
}
cg($gateNonNull === [],
    '9.1 every form key is ALLOWED when dormant, with or without a token (offenders: '
        . implode(',', $gateNonNull) . ')');

cg(captchaCspOrigins() === ['script' => [], 'frame' => [], 'connect' => []],
    '9.2 captchaCspOrigins() is three empty lists when dormant (CSP header byte-identical)');
cg(captchaClientConfig() === null,
    '9.3 captchaClientConfig() is null when dormant (app_status emits no captcha key)');
$widgetNonEmpty = array_values(array_filter(captchaFormKeys(),
    static fn(string $fk) => captchaWidgetHtml($fk) !== ''));
cg($widgetNonEmpty === [],
    '9.4 captchaWidgetHtml() is empty for every form when dormant (manage/login.php byte-identical)');
cg(captchaOutageStrictForms() === [],
    '9.5 the strict-forms list reads empty on an un-migrated/unconfigured install');
cg(captchaHealthState() === captchaHealthColdState(),
    '9.6 the health state reads COLD on an un-migrated install (and cold enforces — see 7.8)');

$dormantMs = (microtime(true) - $dormantStart) * 1000;
/* A single probe leg cannot complete in under CAPTCHA_PROBE_CONNECT_TIMEOUT
   seconds when it fails, and a successful one still costs a real round trip.
   Finishing the entire sweep in a fraction of that is the evidence that NO
   outbound call happened on any dormant path. Bounded generously so a slow CI
   box cannot make this flaky. */
$dormantBudgetMs = (CAPTCHA_PROBE_CONNECT_TIMEOUT * 1000) / 4;
cg($dormantMs < $dormantBudgetMs,
    sprintf('9.7 the whole dormant sweep made no outbound call (%.1f ms, budget %.0f ms)',
        $dormantMs, $dormantBudgetMs));

/* ===========================================================================
 * SECTION 10 — the break-glass kill file is genuinely web-denied
 * ======================================================================== */

$htaccess = (string)@file_get_contents($docroot . '/.htaccess');
cg($htaccess !== '', '10.1 the docroot .htaccess is readable');
/* The kill file lives beside captcha.php, i.e. under includes/ — which must be
   forbidden outright. */
cg((bool)preg_match('/RewriteRule\s+\^includes\/\s+-\s+\[F,L\]/', $htaccess),
    '10.2 .htaccess forbids the whole includes/ directory (where the kill file lives)');
/* Belt-and-braces: an ACCESS-PHASE deny keyed on the file NAME, so a rewrite
   [L]-stop can never expose it (the #1906 lesson). Derived from the CONSTANT,
   so renaming the file forces this assertion to be revisited. */
cg(strpos($htaccess, '<FilesMatch "^' . CAPTCHA_KILL_FILE_NAME . '$">') !== false
   && (bool)preg_match('/<FilesMatch "\^' . preg_quote(CAPTCHA_KILL_FILE_NAME, '/') . '\$">\s*\n\s*Require all denied/', $htaccess),
    '10.3 .htaccess also denies the kill file by name in the authz phase');
/* Nothing but the resolver may look for the file — one place decides. */
cg(cgCount($captchaBody, 'CAPTCHA_KILL_FILE_NAME') === 2,
    '10.4 CAPTCHA_KILL_FILE_NAME is referenced exactly twice in captcha.php (declaration + the ONE check)');
/* And the file must not be committed to the repo — shipping it would disable
   CAPTCHA on every install the moment it deploys. */
cg(!file_exists($docroot . '/includes/' . CAPTCHA_KILL_FILE_NAME),
    '10.5 the kill file is NOT committed to the repo (it would disable CAPTCHA fleet-wide)');

/* ======================================================================== */

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
