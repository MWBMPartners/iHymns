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

/* ======================================================================== */

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
