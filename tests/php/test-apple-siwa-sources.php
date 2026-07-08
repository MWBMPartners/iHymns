<?php
/**
 * iHymns — Sign in with Apple: static source guards (#1402 / #1403)
 *
 * The `test-component-json-guard.php` genre: pure source-tree regex scans
 * (no DB, no network) that lock in load-bearing security properties which a
 * well-intentioned future edit could otherwise silently regress:
 *
 *   1. `case 'auth_apple'` in api.php existence-probes tblUserAuthProviders
 *      before touching it (the #1228 STRICT-mysqli dormancy lesson — a bare
 *      query against a missing table THROWS).
 *   2. includes/apple_siwa.php never disables TLS verification on an
 *      outbound curl call (CURLOPT_SSL_VERIFYPEER=>false /
 *      CURLOPT_SSL_VERIFYHOST=>0) — the transport IS the trust anchor for
 *      the hardcoded appleid.apple.com origin.
 *   3. appleSiwaConsumeNonce() catches mysqli errno 1062 (the duplicate-key
 *      = replay signal) rather than letting it propagate as an uncaught 500.
 *   4. Nothing in apple_siwa.php, the auth_apple case body, or the
 *      account_delete case body logs a raw identityToken / RefreshToken /
 *      $rawNonce VALUE via error_log()/logActivity() — only fixed reason
 *      codes and safe metadata may reach those calls (§2.2 step 12, §3.5).
 *
 *   php tests/php/test-apple-siwa-sources.php
 *
 * Exit status 0 = clean, 1 = at least one violation.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/appWeb/public_html';
$apiFile = $root . '/api.php';
$siwaFile = $root . '/includes/apple_siwa.php';

foreach ([$apiFile, $siwaFile] as $f) {
    if (!is_readable($f)) {
        fwrite(STDERR, "FATAL: could not read $f\n");
        exit(1);
    }
}

$apiSrc = (string)file_get_contents($apiFile);
$siwaSrc = (string)file_get_contents($siwaFile);

$failures = 0;
$passed = 0;

function _tassAssert(bool $cond, string $label): void
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

function _tassCaseBody(string $source, string $caseLabel): ?string
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

/* ---------------------------------------------------------------------- *
 * 1. auth_apple existence-probes tblUserAuthProviders before use.
 * ---------------------------------------------------------------------- */
$authAppleBody = _tassCaseBody($apiSrc, 'auth_apple');
_tassAssert($authAppleBody !== null, "case 'auth_apple' found in api.php");
_tassAssert(
    $authAppleBody !== null
        && str_contains($authAppleBody, 'INFORMATION_SCHEMA.TABLES')
        && str_contains($authAppleBody, 'tblUserAuthProviders'),
    "case 'auth_apple' existence-probes tblUserAuthProviders via INFORMATION_SCHEMA before use"
);
_tassAssert(
    $authAppleBody !== null && preg_match('/is not yet available/i', $authAppleBody) === 1,
    "case 'auth_apple' degrades to a clear \"not yet available\" message when the table is absent"
);

$accountDeleteBody = _tassCaseBody($apiSrc, 'account_delete');
_tassAssert($accountDeleteBody !== null, "case 'account_delete' found in api.php");

/* ---------------------------------------------------------------------- *
 * 2. apple_siwa.php never disables TLS verification.
 * ---------------------------------------------------------------------- */
$verifyPeerDisabled = (bool)preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*(false|0)\b/i', $siwaSrc);
$verifyHostDisabled = (bool)preg_match('/CURLOPT_SSL_VERIFYHOST\s*=>\s*(false|0)\b/i', $siwaSrc);
_tassAssert(!$verifyPeerDisabled, 'apple_siwa.php never sets CURLOPT_SSL_VERIFYPEER to false/0');
_tassAssert(!$verifyHostDisabled, 'apple_siwa.php never sets CURLOPT_SSL_VERIFYHOST to false/0');

/* Every curl_setopt_array block sets both flags to their secure value. */
$curlBlockCount = preg_match_all('/curl_setopt_array\s*\(/', $siwaSrc);
$verifyPeerTrueCount = preg_match_all('/CURLOPT_SSL_VERIFYPEER\s*=>\s*true\b/i', $siwaSrc);
$verifyHostSecureCount = preg_match_all('/CURLOPT_SSL_VERIFYHOST\s*=>\s*2\b/i', $siwaSrc);
_tassAssert($curlBlockCount > 0, 'apple_siwa.php contains at least one curl_setopt_array call');
_tassAssert($verifyPeerTrueCount === $curlBlockCount, 'every curl_setopt_array block sets CURLOPT_SSL_VERIFYPEER => true');
_tassAssert($verifyHostSecureCount === $curlBlockCount, 'every curl_setopt_array block sets CURLOPT_SSL_VERIFYHOST => 2');

/* No redirects followed off-host, HTTPS-only pinned. */
_tassAssert((bool)preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false\b/i', $siwaSrc), 'apple_siwa.php sets CURLOPT_FOLLOWLOCATION => false');
_tassAssert((bool)preg_match('/CURLOPT_MAXREDIRS\s*=>\s*0\b/', $siwaSrc), 'apple_siwa.php sets CURLOPT_MAXREDIRS => 0');
_tassAssert((bool)preg_match('/CURLOPT_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS\b/', $siwaSrc), 'apple_siwa.php pins CURLOPT_PROTOCOLS to HTTPS-only');
_tassAssert((bool)preg_match('/CURLOPT_REDIR_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS\b/', $siwaSrc), 'apple_siwa.php pins CURLOPT_REDIR_PROTOCOLS to HTTPS-only');

/* ---------------------------------------------------------------------- *
 * 3. appleSiwaConsumeNonce() catches the 1062 duplicate-key replay signal.
 * ---------------------------------------------------------------------- */
if (preg_match('/function appleSiwaConsumeNonce\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\n\}/s', $siwaSrc, $m)) {
    $fnBody = $m[1];
    _tassAssert(str_contains($fnBody, 'mysqli_sql_exception'), 'appleSiwaConsumeNonce() catches \\mysqli_sql_exception');
    _tassAssert(str_contains($fnBody, '1062'), 'appleSiwaConsumeNonce() checks for errno 1062 (duplicate key = replay)');
} else {
    _tassAssert(false, 'appleSiwaConsumeNonce() function body located for inspection');
}

/* ---------------------------------------------------------------------- *
 * 4. No error_log()/logActivity() call anywhere in the SIWA surface
 *    references a raw identityToken / RefreshToken / $rawNonce VALUE.
 *
 * Detection: within a small window around each error_log(/logActivity( call
 * site, none of the sensitive variable/column names appear as an
 * interpolated VALUE (a bare mention of the token_prefix substr(hash(...))
 * pattern is fine and explicitly allowed — that's the safe, house-standard
 * debug handle).
 * ---------------------------------------------------------------------- */
$sensitiveTokens = ['$identityToken', '$rawNonce', 'RefreshToken', '$refreshToken', '$token->'];
$allowedPattern = '/token_prefix|substr\s*\(\s*hash\s*\(/'; /* the safe sha256-prefix idiom */

function _tassScanLogCalls(string $source, string $file, array $sensitive, string $allowedPattern): array
{
    $violations = [];
    if (!preg_match_all('/\b(error_log|logActivity)\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return $violations;
    }
    foreach ($m[0] as $hit) {
        $callStart = $hit[1];
        /* Find the matching close-paren for THIS call (balanced scan) so the
           window is exactly the call's own arguments, not neighbouring code. */
        $parenPos = strpos($source, '(', $callStart);
        if ($parenPos === false) { continue; }
        $depth = 0;
        $end = null;
        for ($i = $parenPos; $i < strlen($source); $i++) {
            if ($source[$i] === '(') { $depth++; }
            elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end === null) { continue; }
        $callText = substr($source, $callStart, $end - $callStart + 1);
        foreach ($sensitive as $needle) {
            if (str_contains($callText, $needle) && !preg_match($allowedPattern, $callText)) {
                $line = substr_count(substr($source, 0, $callStart), "\n") + 1;
                $violations[] = "$file:$line references \"$needle\" — " . trim(preg_replace('/\s+/', ' ', $callText));
            }
        }
    }
    return $violations;
}

$violations = array_merge(
    _tassScanLogCalls($siwaSrc, 'includes/apple_siwa.php', $sensitiveTokens, $allowedPattern),
    _tassScanLogCalls($authAppleBody ?? '', "api.php case 'auth_apple'", $sensitiveTokens, $allowedPattern),
    _tassScanLogCalls($accountDeleteBody ?? '', "api.php case 'account_delete'", $sensitiveTokens, $allowedPattern)
);

if ($violations) {
    foreach ($violations as $v) {
        $failures++;
        fwrite(STDERR, "FAIL: sensitive value logged — $v\n");
    }
} else {
    $passed++;
    echo "PASS: no error_log()/logActivity() call references a raw identityToken/RefreshToken/nonce value\n";
}

echo "\n$passed passed, $failures failed.\n";
exit($failures > 0 ? 1 : 0);
