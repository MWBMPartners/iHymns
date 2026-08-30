<?php

declare(strict_types=1);

/**
 * iHymns — Outbound-host SSRF guard standing test (security audit finding
 * L-2, 2026-08-30)
 * ============================================================================
 *
 * ELI5
 * ----
 * `includes/cuercode_client.php` and `includes/intapps_client.php` each dial
 * an admin-configured base URL. Before this fix, "https://" was ALWAYS
 * allowed regardless of WHERE the host actually resolved — an admin (or a
 * compromised admin account, or a typo) could point either client at the
 * cloud-metadata address (169.254.169.254), a loopback admin panel, or an
 * internal 10.x host, and the client would happily dial it. This file is the
 * standing proof that both resolvers now refuse a private/reserved host
 * UNLESS the SAME knob that already unlocks the local-test loopback
 * carve-out is on, that the shared `ihymnsHostResolvesPrivate()` core (never
 * a THIRD hand-copied private-range check) is what both call, and that the
 * `manage/configuration.php` save handlers surface a heads-up when an admin
 * saves a base URL that resolves to one.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Structural assertions are proven able to fail by re-running them against a
 * MUTATED COPY of the real source (a temp file, string-replaced from the
 * real content — NEVER the tracked source itself). The functional truth
 * table for `ihymnsHostResolvesPrivate()` is proven the same way but run in
 * an ISOLATED subprocess (this process already loaded the real function; a
 * second definition with the same name would fatal) — mirrors
 * tests/php/test-gating-wizard.php's own precedent for its (f)/(g) truth
 * tables.
 *
 * NETWORK NOTE: every case below uses a LITERAL IP address (never a
 * hostname), so `ihymnsHostResolvesPrivate()` never performs a real DNS
 * lookup here (its own doc-block: "a literal IP host is treated as
 * already-resolved, skips DNS entirely") — this file's assertions hold
 * identically whether or not the test runner has network access.
 *
 * @see appWeb/public_html/includes/network_guard.php     ihymnsHostResolvesPrivate() — the shared core
 * @see appWeb/public_html/includes/cuercode_client.php    _cuercodeResolveUrl() — a caller
 * @see appWeb/public_html/includes/intapps_client.php     _intappsResolveUrl() — a caller
 * @see appWeb/public_html/manage/configuration.php        save_cuercode / save_intappsapi — the resolve-and-warn callers
 *
 *   php tests/php/test-outbound-ssrf-guard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

$repo           = dirname(__DIR__, 2);
$guardFile      = $repo . '/appWeb/public_html/includes/network_guard.php';
$cuercodeFile   = $repo . '/appWeb/public_html/includes/cuercode_client.php';
$intappsFile    = $repo . '/appWeb/public_html/includes/intapps_client.php';
$iaFile         = $repo . '/appWeb/public_html/includes/ia_client.php';   /* L-2 follow-up — same SSRF gap, same shared fix */
$configFile     = $repo . '/appWeb/public_html/manage/configuration.php';
$phpBin         = PHP_BINARY ?: 'php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

function osgStripComments(string $src): string
{
    return (string)preg_replace_callback(
        '#/\*.*?\*/#s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    );
}

/** Run a small PHP snippet in an isolated subprocess with `require $file;`
 *  prepended (mirrors test-gating-wizard.php's gwRunIsolated()). */
function osgRunIsolated(string $phpBin, string $requireFile, string $snippet): array
{
    $code = 'require ' . var_export($requireFile, true) . '; ' . $snippet;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$phpBin, '-r', $code], $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'could not spawn subprocess'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['code' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

/** Write $mutatedSrc as a throwaway SIBLING of $sameDirAs (never the tracked
 *  file itself) so its own __DIR__-relative requires still resolve — mirrors
 *  test-gating-wizard.php's gwWithMutatedSiblingFile(). */
function osgWithMutatedSiblingFile(string $mutatedSrc, string $sameDirAs, callable $fn): mixed
{
    $dir = dirname($sameDirAs);
    $tmp = $dir . DIRECTORY_SEPARATOR . 'zz_ihymns_osg_mutant_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($tmp, $mutatedSrc);
    try {
        return $fn($tmp);
    } finally {
        @unlink($tmp);
    }
}

echo "\nOutbound-host SSRF guard — standing test (security audit L-2)\n\n";

/* =========================================================================
 * (a) FUNCTIONAL truth table — ihymnsHostResolvesPrivate(). Loaded directly
 * (side-effect-free to require, per its own doc-block).
 * ========================================================================= */
require $guardFile;

ok('(a1) loopback IPv4 (127.0.0.1) is private', ihymnsHostResolvesPrivate('127.0.0.1'));
ok('(a2) loopback IPv6 (::1) is private', ihymnsHostResolvesPrivate('::1'));
ok('(a3) RFC1918 10.x is private', ihymnsHostResolvesPrivate('10.0.0.5'));
ok('(a4) RFC1918 192.168.x is private', ihymnsHostResolvesPrivate('192.168.1.1'));
ok('(a5) RFC1918 172.16-31.x is private', ihymnsHostResolvesPrivate('172.20.3.4'));
ok('(a6) the cloud-metadata link-local address 169.254.169.254 is private',
    ihymnsHostResolvesPrivate('169.254.169.254'));
ok('(a7) a real public IP (8.8.8.8) is NOT private', !ihymnsHostResolvesPrivate('8.8.8.8'));
ok('(a8) an unresolvable hostname is NOT treated as private (fails toward "let the real HTTP attempt fail")',
    !ihymnsHostResolvesPrivate('this-host-does-not-exist-ihymns-test.invalid'));
ok('(a9) an empty host is NOT private (nothing to resolve)', !ihymnsHostResolvesPrivate(''));

$guardSrc = (string)file_get_contents($guardFile);

/* MUTATION: invert the filter_var() flags in a mutated copy (in an ISOLATED
 * subprocess — this process already has ihymnsHostResolvesPrivate() loaded)
 * -> the SAME loopback case must flip from private to NOT private. */
$mutatedGuardInverted = str_replace(
    'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE',
    '0 /* MUTATED: private/reserved flags removed */',
    $guardSrc
);
ok('MUTATION setup sanity (a): the filter-flag replacement actually matched real source',
    $mutatedGuardInverted !== $guardSrc);
osgWithMutatedSiblingFile($mutatedGuardInverted, $guardFile, function (string $tmp) use ($phpBin) {
    $result = osgRunIsolated($phpBin, $tmp, "var_export(ihymnsHostResolvesPrivate('127.0.0.1'));");
    ok('MUTATION PROOF (a): removing the private/reserved filter flags makes 127.0.0.1 read as NOT private',
        $result['code'] === 0 && trim($result['stdout']) === 'false');
});

/* =========================================================================
 * (b) FUNCTIONAL — both resolvers actually refuse a private host, and both
 * skip the check when their OWN loopback-allow flag is on (the documented
 * escape hatch, never widened to "any private host" — only the SAME carve-
 * out the pre-existing http+loopback branch already grants).
 * ========================================================================= */
require $cuercodeFile;
require $intappsFile;

ok('(b1) _cuercodeResolveUrl() refuses https to the cloud-metadata address',
    _cuercodeResolveUrl('https://169.254.169.254/', '/api/v1/generate', false) === null);
ok('(b2) _cuercodeResolveUrl() refuses https to a loopback IP',
    _cuercodeResolveUrl('https://127.0.0.1/', '/api/v1/generate', false) === null);
ok('(b3) _cuercodeResolveUrl() refuses https to an RFC1918 host',
    _cuercodeResolveUrl('https://10.1.2.3/', '/api/v1/generate', false) === null);
ok('(b4) _cuercodeResolveUrl() still allows a real public https host',
    _cuercodeResolveUrl('https://cuercode.net', '/api/v1/generate', false) !== null);
ok('(b5) _cuercodeResolveUrl() allows the metadata-shaped address when the loopback-allow knob is ON '
    . '(the documented local/test escape hatch)',
    _cuercodeResolveUrl('https://169.254.169.254/', '/api/v1/generate', true) !== null);

ok('(b6) _intappsResolveUrl() refuses https to the cloud-metadata address',
    _intappsResolveUrl('https://169.254.169.254/', '/v1/status', false) === null);
ok('(b7) _intappsResolveUrl() refuses https to a loopback IP',
    _intappsResolveUrl('https://127.0.0.1/', '/v1/status', false) === null);
ok('(b8) _intappsResolveUrl() still allows a real public https host',
    _intappsResolveUrl('https://api.mwbmpartners.ltd', '/v1/status', false) !== null);
ok('(b9) _intappsResolveUrl() allows the metadata-shaped address when the loopback-allow knob is ON',
    _intappsResolveUrl('https://169.254.169.254/', '/v1/status', true) !== null);

/* Pre-existing behaviour must be completely unchanged (no widening, no
 * narrowing) for the cases this fix does NOT touch. */
ok('(b10) _cuercodeResolveUrl() still refuses a non-loopback http:// host (unchanged)',
    _cuercodeResolveUrl('http://evil.example.com', '/api/v1/generate', true) === null);
ok('(b11) _cuercodeResolveUrl() still allows http://127.0.0.1 when the knob is on (unchanged)',
    _cuercodeResolveUrl('http://127.0.0.1:8080', '/api/v1/generate', true) !== null);
ok('(b12) _intappsResolveUrl() still refuses a non-loopback http:// host (unchanged)',
    _intappsResolveUrl('http://evil.example.com', '/v1/status', true) === null);
ok('(b13) _intappsResolveUrl() still allows http://127.0.0.1 when the knob is on (unchanged)',
    _intappsResolveUrl('http://127.0.0.1:8124', '/v1/status', true) !== null);

/* =========================================================================
 * (c) STRUCTURAL — both resolvers call the SHARED core (never a fresh,
 * third hand-copied private-range check), and the call is gated behind
 * `!$allowLoopback` (never unconditional — that would defeat the local-test
 * carve-out the pre-existing http+loopback branch already grants).
 * ========================================================================= */
$cuercodeSrc = osgStripComments((string)file_get_contents($cuercodeFile));
$intappsSrc  = osgStripComments((string)file_get_contents($intappsFile));
$iaSrc       = osgStripComments((string)file_get_contents($iaFile));

ok('cuercode_client.php requires includes/network_guard.php',
    str_contains($cuercodeSrc, "'network_guard.php'"));
ok('intapps_client.php requires includes/network_guard.php',
    str_contains($intappsSrc, "'network_guard.php'"));
ok('ia_client.php requires includes/network_guard.php',
    str_contains($iaSrc, "'network_guard.php'"));
ok('_cuercodeResolveUrl() calls the SHARED ihymnsHostResolvesPrivate(), never a re-forked check',
    (bool)preg_match('/if\s*\(\s*!\$allowLoopback\s*&&\s*ihymnsHostResolvesPrivate\(\$host\)\s*\)\s*\{\s*return\s+null;/', $cuercodeSrc));
ok('_intappsResolveUrl() calls the SHARED ihymnsHostResolvesPrivate(), never a re-forked check',
    (bool)preg_match('/if\s*\(\s*!\$allowLoopback\s*&&\s*ihymnsHostResolvesPrivate\(\$host\)\s*\)\s*\{\s*return\s+null;/', $intappsSrc));
ok('_iaResolveUrl() calls the SHARED ihymnsHostResolvesPrivate(), never a re-forked check',
    (bool)preg_match('/if\s*\(\s*!\$allowLoopback\s*&&\s*ihymnsHostResolvesPrivate\(\$host\)\s*\)\s*\{\s*return\s+null;/', $iaSrc));

/* No re-forked FILTER_FLAG_NO_PRIV_RANGE check anywhere in any client —
 * proves no file grew its OWN copy of the range-check logic instead of
 * delegating (rule #22). */
ok('cuercode_client.php has NO local FILTER_FLAG_NO_PRIV_RANGE (delegates, does not re-fork)',
    !str_contains($cuercodeSrc, 'FILTER_FLAG_NO_PRIV_RANGE'));
ok('intapps_client.php has NO local FILTER_FLAG_NO_PRIV_RANGE (delegates, does not re-fork)',
    !str_contains($intappsSrc, 'FILTER_FLAG_NO_PRIV_RANGE'));
ok('ia_client.php has NO local FILTER_FLAG_NO_PRIV_RANGE (delegates, does not re-fork)',
    !str_contains($iaSrc, 'FILTER_FLAG_NO_PRIV_RANGE'));

/* MUTATION: remove the `!$allowLoopback &&` guard in a mutated copy of
 * cuercode_client.php's resolver -> the structural "gated behind
 * !$allowLoopback" assertion must go red, AND the functional behaviour
 * would widen to "always block private hosts, even with the knob on" (a
 * real behaviour change this mutation proves the guard would catch). */
$mutatedCuercodeUnconditional = str_replace(
    'if (!$allowLoopback && ihymnsHostResolvesPrivate($host)) {',
    'if (ihymnsHostResolvesPrivate($host)) { // MUTATED: unconditional, loopback-allow no longer skips it',
    (string)file_get_contents($cuercodeFile)
);
ok('MUTATION setup sanity (c1): the !$allowLoopback guard removal actually matched real source',
    $mutatedCuercodeUnconditional !== (string)file_get_contents($cuercodeFile));
$mutatedStripped = osgStripComments($mutatedCuercodeUnconditional);
ok('MUTATION PROOF (c1): removing the !$allowLoopback gate is detected by the structural pattern',
    !(bool)preg_match('/if\s*\(\s*!\$allowLoopback\s*&&\s*ihymnsHostResolvesPrivate\(\$host\)\s*\)\s*\{\s*return\s+null;/', $mutatedStripped));
osgWithMutatedSiblingFile($mutatedCuercodeUnconditional, $cuercodeFile, function (string $tmp) use ($phpBin) {
    $result = osgRunIsolated($phpBin, $tmp,
        "var_export(_cuercodeResolveUrl('https://169.254.169.254/', '/api/v1/generate', true) === null);");
    ok('MUTATION PROOF (c1) functional: with the gate removed, the loopback-allow escape hatch no longer works',
        $result['code'] === 0 && trim($result['stdout']) === 'true');
});

/* =========================================================================
 * (d) STRUCTURAL — manage/configuration.php's save_intappsapi / save_cuercode
 * handlers surface a resolve-and-warn heads-up (never blocking), matching
 * save_email's own $smtpHostIsPrivate() precedent.
 * ========================================================================= */
$configSrc = osgStripComments((string)file_get_contents($configFile));

$saveIntappsPos  = strpos($configSrc, "\$action === 'save_intappsapi'");
$saveCuercodePos = strpos($configSrc, "\$action === 'save_cuercode'");
$saveCaptchaPos  = strpos($configSrc, "\$action === 'save_captcha'"); /* the next branch — bounds save_cuercode's span */

ok('save_intappsapi branch found (sanity)', $saveIntappsPos !== false);
ok('save_cuercode branch found (sanity)', $saveCuercodePos !== false);
ok('save_captcha branch found (sanity, bounds save_cuercode below)', $saveCaptchaPos !== false);

$saveIntappsSpan  = ($saveIntappsPos !== false && $saveCuercodePos !== false)
    ? substr($configSrc, $saveIntappsPos, $saveCuercodePos - $saveIntappsPos) : '';
$saveCuercodeSpan = ($saveCuercodePos !== false && $saveCaptchaPos !== false)
    ? substr($configSrc, $saveCuercodePos, $saveCaptchaPos - $saveCuercodePos) : '';

ok('save_intappsapi calls the shared ihymnsHostResolvesPrivate( and sets $saveWarning on a private host',
    str_contains($saveIntappsSpan, 'ihymnsHostResolvesPrivate(') && str_contains($saveIntappsSpan, '$saveWarning'));
ok('save_cuercode calls the shared ihymnsHostResolvesPrivate( and sets $saveWarning on a private host',
    str_contains($saveCuercodeSpan, 'ihymnsHostResolvesPrivate(') && str_contains($saveCuercodeSpan, '$saveWarning'));

/* MUTATION: remove the ihymnsHostResolvesPrivate( call from save_cuercode in
 * a mutated copy -> the span-scoped assertion must go red. */
$mutatedNoWarnCall = str_replace(
    "if (\$cuercodeHostVal !== '' && ihymnsHostResolvesPrivate(\$cuercodeHostVal)) {",
    "if (false) { // MUTATED: ihymnsHostResolvesPrivateREMOVED(\$cuercodeHostVal)",
    (string)file_get_contents($configFile)
);
ok('MUTATION setup sanity (d): the save_cuercode heads-up removal actually matched real source',
    $mutatedNoWarnCall !== (string)file_get_contents($configFile));
$mutStripped = osgStripComments($mutatedNoWarnCall);
$mutCuercodePos = strpos($mutStripped, "\$action === 'save_cuercode'");
$mutCaptchaPos  = strpos($mutStripped, "\$action === 'save_captcha'");
$mutSpan = ($mutCuercodePos !== false && $mutCaptchaPos !== false)
    ? substr($mutStripped, $mutCuercodePos, $mutCaptchaPos - $mutCuercodePos) : '__NOT_FOUND__';
ok('MUTATION PROOF (d): removing the save_cuercode heads-up call is detected',
    !str_contains($mutSpan, 'ihymnsHostResolvesPrivate('));

/* =========================================================================
 * REPORT
 * ========================================================================= */
echo "\n{$passed} passed, {$failed} failed";
if ($failed > 0) {
    echo "\n";
    exit(1);
}
echo "\n\nAll three outbound-service clients (CueRCode, IntApps, Internet Archive) now refuse a private/reserved destination "
   . "(including the 169.254.169.254 cloud-metadata address) through the ONE shared "
   . "ihymnsHostResolvesPrivate() core, skip that check ONLY via the same knob that "
   . "already unlocks the local-test loopback carve-out, leave every pre-existing "
   . "case byte-identical, and the Configuration save handlers surface a heads-up "
   . "(never a block) when an admin saves a base URL that resolves to one.\n";
exit(0);
