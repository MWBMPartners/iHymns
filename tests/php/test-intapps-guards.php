<?php

declare(strict_types=1);

/**
 * iHymns — IntAppsAPI boundary guard + secrets manifest + dormancy proof
 * (#1725/#1731)
 *
 * ELI5: three separate checks that a gateway feature-flag switch can never
 * accidentally become a second way to decide "may this person see this
 * content" — (1) it never shows up inside the files that answer that
 * question, (2) inside `api.php` it only ever appears where it's supposed
 * to, and (3) the two credential settings really are registered for
 * encrypted-at-rest storage.
 *
 * DETAIL:
 * -------
 * Rule (design §6 / .claude/intappsapi-integration-plan.md): "gateway
 * flags are operational kill switches for app-build behaviour; they
 * compose with existing gating by STRUCTURAL SEPARATION, never by
 * precedence." A gateway flag must never answer a question `TIER_CAPS`
 * / `checkTierAccess()` / `contentGatingApply()` / `gatingRulesApply()`
 * / `checkContentAccess()` already answers — not as an input, not as an
 * override.
 *
 * DERIVATION (rule #34 — a guard must be tree-derived, not a typed list):
 * §1 below greps `includes/*.php` for the literal function DEFINITIONS
 * (`function checkTierAccess`, `function contentGatingApply`, ...), so a
 * future gating file is picked up automatically the moment it defines one
 * of those functions — nobody has to remember to add it to a list here.
 * §2 locates the `case 'app_status':` block in `api.php` by finding ITS
 * own byte offset and the next top-level `case`/`default` after it (every
 * case in this switch is consistently indented 8 spaces — verified: 216
 * of 216 `case` labels match that indentation, so the pattern is not
 * guessing at structure it hasn't checked). §3's file list (song-media.php
 * + includes/pages/*.php) is also a directory scan, not a typed list; the
 * one legitimate exception (home.php's #1733 consumer) is named and
 * justified inline, not silently allowed.
 *
 * Comment-stripping: block `/* ... *\/` comments are stripped before
 * scanning (the same idiom `test-editor-api2-contract.php` uses) so a
 * DOCBLOCK that merely DISCUSSES `intappsFlag()` — like this very file
 * would, if it were itself a scan target — never false-positives.
 *
 *   php tests/php/test-intapps-guards.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

$repoRoot = dirname(__DIR__, 2);
$includesDir = $repoRoot . '/appWeb/public_html/includes';

/* Names the boundary must never see inside a gating file, or anywhere in
   api.php outside the app_status case, or anywhere in song-media.php /
   includes/pages/* (bar the one named exception). Deliberately the full
   public+internal surface, not just the four public entry points — an
   internal helper like `_intappsResolveUrl` leaking into gating code would
   be just as much a structural violation as `intappsFlag` itself. */
const INTAPPS_BOUNDARY_IDENTIFIERS = [
    'intappsFlag', 'intappsFlagMeta', 'intappsRequest', 'intappsCachedScope',
    'intappsSyncRow', 'intappsRefreshIfDue', 'intappsForceRefresh',
    'intappsConfig', 'intappsEnabled', 'intappsSign', 'tblIntAppsSync',
];

function stripBlockComments(string $src): string
{
    return preg_replace('#/\*[\s\S]*?\*/#', '', $src) ?? $src;
}

function containsIntappsIdentifier(string $haystackNoComments): ?string
{
    foreach (INTAPPS_BOUNDARY_IDENTIFIERS as $needle) {
        if (preg_match('/\b' . preg_quote($needle, '/') . '\b/', $haystackNoComments)) {
            return $needle;
        }
    }
    return null;
}

/* =============================================================================
 * 1. GATING FILES NEVER REFERENCE THE GATEWAY MODULE (tree-derived)
 * ============================================================================= */

$gatingFunctionPattern = '/function\s+(checkTierAccess|contentGatingApply|gatingRulesApply|checkContentAccess|tierCap\w*)\s*\(/';
$gatingFiles = [];
foreach (glob($includesDir . '/*.php') ?: [] as $file) {
    $src = stripBlockComments((string)file_get_contents($file));
    if (preg_match($gatingFunctionPattern, $src)) {
        $gatingFiles[] = $file;
    }
}
check('derived at least 4 gating files (content_gating.php, ccli_validator.php, access_tier_validation.php, gating_rules.php, content_access.php)', count($gatingFiles) >= 4);

foreach ($gatingFiles as $file) {
    $src = stripBlockComments((string)file_get_contents($file));
    $hit = containsIntappsIdentifier($src);
    check('gating file ' . basename($file) . ' never references the gateway module', $hit === null);
    if ($hit !== null) {
        echo "       found '{$hit}' in " . basename($file) . "\n";
    }
}

/* =============================================================================
 * 2. api.php: intapps identifiers appear ONLY inside case 'app_status':
 * ============================================================================= */

$apiFile = $repoRoot . '/appWeb/public_html/api.php';
$apiSrcRaw = (string)file_get_contents($apiFile);
$apiSrc = stripBlockComments($apiSrcRaw);

preg_match_all('/^        case \'([a-zA-Z0-9_]+)\':/m', $apiSrc, $caseMatches, PREG_OFFSET_CAPTURE);
$caseLabels = $caseMatches[1] ?? [];
check('derived a plausible number of top-level api.php cases (>= 100)', count($caseLabels) >= 100);

$appStatusIndex = null;
foreach ($caseLabels as $i => [$name, $_offset]) {
    if ($name === 'app_status') {
        $appStatusIndex = $i;
        break;
    }
}
check("found 'case \\'app_status\\':' in api.php", $appStatusIndex !== null);

if ($appStatusIndex !== null) {
    $appStatusStart = (int)$caseLabels[$appStatusIndex][1];
    $appStatusEnd = isset($caseLabels[$appStatusIndex + 1])
        ? (int)$caseLabels[$appStatusIndex + 1][1]
        : strlen($apiSrc);

    $before = substr($apiSrc, 0, $appStatusStart);
    $inside = substr($apiSrc, $appStatusStart, $appStatusEnd - $appStatusStart);
    $after  = substr($apiSrc, $appStatusEnd);

    check('the app_status case block itself DOES reference the gateway module (sanity — proves the scan region is right)', containsIntappsIdentifier($inside) !== null);

    $hitBefore = containsIntappsIdentifier($before);
    check('no intapps identifier appears in api.php BEFORE the app_status case', $hitBefore === null);
    if ($hitBefore !== null) {
        echo "       found '{$hitBefore}' before app_status\n";
    }

    $hitAfter = containsIntappsIdentifier($after);
    check('no intapps identifier appears in api.php AFTER the app_status case', $hitAfter === null);
    if ($hitAfter !== null) {
        echo "       found '{$hitAfter}' after app_status\n";
    }
}

/* =============================================================================
 * 3. song-media.php + includes/pages/*.php: zero intapps references, EXCEPT
 *    home.php's one named, justified #1733 consumer.
 * ============================================================================= */

$songMediaFile = $repoRoot . '/appWeb/public_html/song-media.php';
if (is_file($songMediaFile)) {
    $hit = containsIntappsIdentifier(stripBlockComments((string)file_get_contents($songMediaFile)));
    check('song-media.php (the byte-serving path) never references the gateway module', $hit === null);
} else {
    check('song-media.php exists to be scanned', false);
}

/* Directory scan, not a typed list (rule #34) — the ONLY sanctioned entry
   is 'home.php' (the #1733 web.sotd_card consumer, a cosmetic card-presence
   decision, never a content/auth gate). Any OTHER page in this directory
   referencing the module fails loudly; widening this allowlist requires
   editing this comment, not a silent addition. */
const INTAPPS_PAGES_ALLOWLIST = ['home.php' => '#1733 web.sotd_card consumer — cosmetic card presence only, never content/auth'];

$pagesDir = $repoRoot . '/appWeb/public_html/includes/pages';
$pagesFiles = glob($pagesDir . '/*.php') ?: [];
check('derived a plausible number of page fragments (>= 10)', count($pagesFiles) >= 10);
foreach ($pagesFiles as $file) {
    $base = basename($file);
    $hit = containsIntappsIdentifier(stripBlockComments((string)file_get_contents($file)));
    if (isset(INTAPPS_PAGES_ALLOWLIST[$base])) {
        check("$base references the gateway module — EXPECTED, allowlisted (" . INTAPPS_PAGES_ALLOWLIST[$base] . ')', $hit !== null);
    } else {
        check("$base never references the gateway module", $hit === null);
        if ($hit !== null) {
            echo "       found '{$hit}' in $base — not on the allowlist\n";
        }
    }
}

/* =============================================================================
 * 4. SECRETS MANIFEST — the two credential keys are registered for
 *    encrypted-at-rest storage (#1728).
 * ============================================================================= */

require_once $includesDir . '/secret_crypto.php';
$secretKeys = secretSettingKeys();
check('intappsapi_api_key is registered in secretSettingKeys()', in_array('intappsapi_api_key', $secretKeys, true));
check('intappsapi_hmac_secret is registered in secretSettingKeys()', in_array('intappsapi_hmac_secret', $secretKeys, true));

/* =============================================================================
 * 5. DORMANCY: with no settings rows present, intappsRequest() resolves
 *    to 'disabled' WITHOUT ever reaching curl — both by reading the source
 *    (the guard clause is textually the first statement, before curl_init)
 *    and by calling it for real.
 * ============================================================================= */

require_once $includesDir . '/intapps_client.php';

$clientSrc = (string)file_get_contents($includesDir . '/intapps_client.php');
$fnStart = strpos($clientSrc, 'function intappsRequest(');
check('located function intappsRequest( in the source', $fnStart !== false);
if ($fnStart !== false) {
    $curlInitPos = strpos($clientSrc, 'curl_init(', $fnStart);
    $enabledGuardPos = strpos($clientSrc, 'if (!intappsEnabled())', $fnStart);
    check(
        'the intappsEnabled() guard appears BEFORE the first curl_init() call in intappsRequest() (textually, not just at runtime)',
        $enabledGuardPos !== false && $curlInitPos !== false && $enabledGuardPos < $curlInitPos
    );
}

check('intappsEnabled() is false with no settings rows present', intappsEnabled() === false);

$result = intappsRequest('GET', '/v1/status');
check("intappsRequest() with the module disabled returns transport 'disabled'", $result['transport'] === 'disabled');
check('...and never claims success', $result['ok'] === false);
check('...and completes near-instantly (no network attempted)', $result['durationMs'] < 20.0);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All intapps-guards assertions passed.\n";
exit(0);
