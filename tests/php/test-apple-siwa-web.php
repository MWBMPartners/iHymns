<?php
/**
 * iHymns — Sign in with Apple for WEB: dormancy gate + redirect-URI + code-
 * exchange body helpers (#1470 W1)
 *
 * Exercises the THREE PURE cores includes/apple_siwa.php gained for the web
 * SIWA slice — no DB, no network, no request:
 *
 *   1. _appleSiwaExchangeCodeBody() — proves `redirect_uri` is INCLUDED iff a
 *      non-empty value is passed and OMITTED (byte-identical to the pre-#1470
 *      native-only body) when null/empty. appleSiwaExchangeCode() itself
 *      always POSTs to https://appleid.apple.com/auth/token, which CI must
 *      never actually call — this pure body-builder is the extracted seam
 *      the plan's test-writing guidance calls for.
 *   2. _appleWebLoginEnabledCore() — the full channel allow-list truth table
 *      (no services id / empty allow-list / channel not listed / channel
 *      listed / `all` / mixed-case + whitespace tokens), plus the impure
 *      wrapper appleWebLoginEnabledForChannel() resolves to false in THIS
 *      CI environment (no DB credentials — mirrors test-gating-noop.php's
 *      "no stub needed, the real fail-safe defaults do the job" approach).
 *   3. _appleSiwaWebHostAllowed() — the Host-header validation
 *      appleSiwaWebRedirectUri() relies on before ever building a URL from
 *      $_SERVER['HTTP_HOST'], plus the impure wrapper exercised end-to-end
 *      by temporarily setting $_SERVER['HTTP_HOST'].
 *
 *   php tests/php/test-apple-siwa-web.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/apple_siwa.php';

$failures = 0;
$passed = 0;

function _tasw_assert(bool $cond, string $label): void
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

/* ------------------------------------------------------------------------ *
 * 1. _appleSiwaExchangeCodeBody() — redirect_uri inclusion/omission.
 * ------------------------------------------------------------------------ */
$bodyNoRedirect = _appleSiwaExchangeCodeBody('CODE1', 'SECRET1', 'app.ihymns', null);
parse_str($bodyNoRedirect, $parsedNoRedirect);
_tasw_assert(!array_key_exists('redirect_uri', $parsedNoRedirect), 'null redirectUri: redirect_uri key is OMITTED entirely');
_tasw_assert(
    $parsedNoRedirect === [
        'grant_type'    => 'authorization_code',
        'code'          => 'CODE1',
        'client_id'     => 'app.ihymns',
        'client_secret' => 'SECRET1',
    ],
    'null redirectUri: body is the byte-identical pre-#1470 4-key shape'
);

$bodyEmptyRedirect = _appleSiwaExchangeCodeBody('CODE1', 'SECRET1', 'app.ihymns', '');
_tasw_assert($bodyEmptyRedirect === $bodyNoRedirect, 'empty-string redirectUri produces the SAME body as null (never sent as redirect_uri=)');

$bodyWithRedirect = _appleSiwaExchangeCodeBody('CODE2', 'SECRET2', 'app.ihymns.web', 'https://ihymns.app/');
parse_str($bodyWithRedirect, $parsedWithRedirect);
_tasw_assert(($parsedWithRedirect['redirect_uri'] ?? null) === 'https://ihymns.app/', 'non-empty redirectUri: redirect_uri is present with the exact value');
_tasw_assert(
    array_keys($parsedWithRedirect) === ['grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri'],
    'non-empty redirectUri: the other four keys are unchanged and redirect_uri is appended last'
);

/* appleSiwaExchangeCode()'s own guard clauses never touch the network. */
_tasw_assert(appleSiwaExchangeCode('', 'SECRET', 'app.ihymns') === null, 'appleSiwaExchangeCode(): empty code short-circuits to null (no network)');
_tasw_assert(appleSiwaExchangeCode('CODE', '', 'app.ihymns') === null, 'appleSiwaExchangeCode(): empty clientSecret short-circuits to null (no network)');
_tasw_assert(appleSiwaExchangeCode('CODE', '', 'app.ihymns', 'https://ihymns.app/') === null, 'appleSiwaExchangeCode(): guard clause still short-circuits with a redirectUri present');

/* ------------------------------------------------------------------------ *
 * 2. _appleWebLoginEnabledCore() — full truth table.
 * ------------------------------------------------------------------------ */
_tasw_assert(_appleWebLoginEnabledCore('', 'alpha', 'alpha') === false, 'no Services ID → false regardless of allow-list/channel');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', '', 'alpha') === false, 'Services ID set but allow-list EMPTY → false (disabled everywhere, the shipped default)');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', 'beta', 'alpha') === false, 'Services ID set, allow-list has a DIFFERENT channel → false');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', 'alpha', 'alpha') === true, 'Services ID set, allow-list contains the current channel → true');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', 'alpha,beta', 'beta') === true, 'multi-token allow-list: current channel is one of several → true');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', 'alpha,beta', 'production') === false, 'multi-token allow-list: current channel is NOT listed → false');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', 'all', 'production') === true, 'the literal "all" matches every channel');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', ' ALPHA , Beta ', 'alpha') === true, 'allow-list tokens are trimmed + case-insensitive');
_tasw_assert(_appleWebLoginEnabledCore('  ', 'all', 'alpha') === false, 'whitespace-only Services ID counts as unset → false even with "all"');
_tasw_assert(_appleWebLoginEnabledCore('app.ihymns.web', ',,', 'alpha') === false, 'an allow-list of only empty/comma tokens → false (never a stray-match)');

/* The impure wrapper, exercised for real: no DB credentials in CI, so
   getAppSetting() catches getDbMysqli()'s RuntimeException and returns its
   default ('') for both settings — mirrors test-gating-noop.php's approach
   of proving the REAL fail-safe defaults, not a stub. */
_tasw_assert(appleWebLoginEnabledForChannel() === false, 'appleWebLoginEnabledForChannel(): byte-identical dormant no-op with no settings configured (real getAppSetting()/ihymns_environment(), no DB in CI)');

/* ------------------------------------------------------------------------ *
 * 3. _appleSiwaWebHostAllowed() — Host-header validation.
 * ------------------------------------------------------------------------ */
_tasw_assert(_appleSiwaWebHostAllowed('ihymns.app') === true, 'bare apex "ihymns.app" is allowed');
_tasw_assert(_appleSiwaWebHostAllowed('www.ihymns.app') === true, '"www.ihymns.app" subdomain is allowed');
_tasw_assert(_appleSiwaWebHostAllowed('beta.ihymns.app') === true, '"beta.ihymns.app" subdomain is allowed');
_tasw_assert(_appleSiwaWebHostAllowed('dev.ihymns.app') === true, '"dev.ihymns.app" subdomain is allowed');
_tasw_assert(_appleSiwaWebHostAllowed('IHYMNS.APP') === true, 'case-insensitive: "IHYMNS.APP" is allowed');
_tasw_assert(_appleSiwaWebHostAllowed('') === false, 'empty host is rejected');
_tasw_assert(_appleSiwaWebHostAllowed('evil.com') === false, 'a foreign domain is rejected');
_tasw_assert(_appleSiwaWebHostAllowed('notihymns.app') === false, 'a look-alike host missing the dot boundary ("notihymns.app") is rejected');
_tasw_assert(_appleSiwaWebHostAllowed('ihymns.app.evil.com') === false, 'a suffix-spoof attempt ("ihymns.app.evil.com") is rejected — the domain must END WITH .ihymns.app, not merely contain it');
_tasw_assert(_appleSiwaWebHostAllowed('ihymns.app@evil.com') === false, 'a userinfo-injection attempt is rejected by the bare-hostname charset gate');
_tasw_assert(_appleSiwaWebHostAllowed("ihymns.app\r\nX-Injected: 1") === false, 'a CR/LF header-injection attempt is rejected by the charset gate');
_tasw_assert(_appleSiwaWebHostAllowed('ihymns.app:8443') === false, 'a host:port pair is rejected by the charset gate (no colon allowed)');

/* The impure wrapper, via a temporarily-faked $_SERVER['HTTP_HOST'] — safe
   in a CLI test process (no real request in flight). */
$origHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'beta.ihymns.app';
_tasw_assert(appleSiwaWebRedirectUri() === 'https://beta.ihymns.app/', 'appleSiwaWebRedirectUri(): valid docroot host → "https://<host>" . APPLE_SIWA_WEB_RETURN_PATH');
$_SERVER['HTTP_HOST'] = 'evil.example.com';
_tasw_assert(appleSiwaWebRedirectUri() === null, 'appleSiwaWebRedirectUri(): a foreign/spoofed Host header → null (never a guessed fallback)');
if ($origHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $origHost;
}

_tasw_assert(APPLE_SIWA_WEB_RETURN_PATH === '/', 'APPLE_SIWA_WEB_RETURN_PATH is the documented "/" origin-root value');

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
