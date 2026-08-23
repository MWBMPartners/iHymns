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
    $fields = ['label', 'script', 'verify', 'field', 'widgetClass', 'renderGlobal', 'cspScript', 'cspFrame'];
    $missing = array_values(array_filter($fields, static fn($f) => !isset($entry[$f]) || $entry[$f] === '' || $entry[$f] === []));
    cg($missing === [], "2.a '$key' carries every required field (missing: " . implode(',', $missing) . ')');
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
    foreach (array_merge((array)($pe['cspScript'] ?? []), (array)($pe['cspFrame'] ?? [])) as $o) {
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

/* ======================================================================== */

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
