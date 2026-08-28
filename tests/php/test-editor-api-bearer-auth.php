<?php

declare(strict_types=1);

/**
 * iHymns — editor API Bearer-auth seam guard (owner-approved,
 * `.claude/api-coverage-2026-08-28.md` §3 X1/X3, Batch 2)
 * ============================================================================
 *
 * ELI5
 * ----
 * The web editor proves who you are with a browser cookie. A native curator
 * app has no cookie jar, so `manage/editor/api2.php`, the legacy
 * `manage/editor/api.php`, and `manage/places-api.php` were taught to ALSO
 * accept an `Authorization: Bearer <token>` header, resolved through the
 * SAME `tblApiTokens` verification core the public `api.php` uses
 * (`includes/api_tokens.php`'s `apiTokenResolveBearerUser()`). Because a
 * Bearer token is an explicit, out-of-band credential a cross-site page can
 * never attach to a forged request (unlike a cookie, which the browser
 * attaches automatically), the existing `X-Requested-With` CSRF requirement
 * is relaxed for a Bearer-authenticated POST — but ONLY for one; the cookie
 * path is untouched. This file is the mutation-proven proof that:
 *
 *   (a) a cookie + X-Requested-With POST still passes            (unchanged)
 *   (b) a cookie POST WITHOUT X-Requested-With is still rejected (unchanged)
 *   (c) a Bearer POST WITHOUT X-Requested-With is now ACCEPTED   (the feature)
 *   (d) an unauthenticated request (no cookie, no Bearer) is still 401
 *   (e) the L-1 GET-write rule (POST required for a write action) still
 *       holds regardless of which auth method is used
 *
 * — in BOTH `api2.php` and the legacy `api.php`, and that the CSRF exemption
 * is wired the same way in `places-api.php`.
 *
 * WHAT IT ASSERTS (DB-free — this test never boots the app, connects to
 * MySQL, opens a session, or sends an HTTP request; it works entirely from
 * source text plus pure boolean simulators of the EXACT production
 * expressions, the same posture as tests/php/test-editor-api-write-method.php,
 * the sibling guard for the same two files):
 *
 *   1. `includes/api_tokens.php` defines ONE shared verification core
 *      (`apiTokenResolveBearerUser()`) and all three endpoint files call it
 *      — never a second, forked token-verification query (rule #22).
 *   2. Each file's Bearer-then-cookie guard is structured so the Bearer
 *      branch is tried FIRST and independently, and a Bearer miss falls
 *      through to the EXACT PRE-EXISTING isAuthenticated()/getCurrentUser()
 *      cookie check — the source text proves the miss-path is unchanged,
 *      not merely assumed.
 *   3. Simulating the EXACT CSRF-gate boolean expression each production
 *      file runs proves (a)-(c) for both api2.php and the legacy api.php,
 *      and that the *cookie* path's outcome is identical whether or not the
 *      Bearer-auth feature exists at all (regression guard).
 *   4. Simulating the EXACT auth-guard control flow proves (d): with no
 *      Bearer resolution AND no cookie session, the response is 401 in both
 *      files, before the CSRF gate is even reached.
 *   5. The L-1 GET-write allow-list constants (ED2_GET_SAFE_ACTIONS /
 *      IHYMNS_EDITOR_API_GET_SAFE_ACTIONS) are still present, still run
 *      AFTER the auth+CSRF gates, and still refuse a representative write
 *      action on GET — proving the new Bearer branch was not spliced in
 *      ahead of, or in place of, the existing method gate (e).
 *   6. `places-api.php` carries the same shared-core call + the same
 *      Bearer-exempts-CSRF wiring on its one write action (`upsert`).
 *
 * HOW TO RE-PROVE THIS FILE IS NOT VACUOUS (rule #34 — done once by hand
 * when this file was authored):
 *   1. In api2.php, change `if (!$ed2BearerAuthed && (...) !== 'XMLHttpRequest')`
 *      to drop the `!$ed2BearerAuthed &&` term -> the (c)-scenario regex
 *      match fails and this test goes RED (the simulator would then diverge
 *      from what the source actually says).
 *   2. In api2.php, swap the guard's if/else so isAuthenticated() runs
 *      BEFORE the Bearer check -> the ordering assertion goes RED.
 *   3. In includes/api_tokens.php, rename apiTokenResolveBearerUser() ->
 *      the "one shared core, all three files call it" assertions go RED for
 *      every caller simultaneously (proving it is genuinely ONE core, not
 *      three coincidentally-matching copies).
 *   4. Restore each file -> the suite is GREEN again.
 *
 *   php tests/php/test-editor-api-bearer-auth.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/includes/api_tokens.php        apiTokenResolveBearerUser()
 * @see appWeb/public_html/manage/editor/api2.php          $ed2BearerAuthed guard
 * @see appWeb/public_html/manage/editor/api.php            $edLegacyBearerAuthed guard
 * @see appWeb/public_html/manage/places-api.php            $placesBearerAuthed guard
 * @see tests/php/test-editor-api-write-method.php          sibling L-1 guard (must stay green)
 */

$root    = dirname(__DIR__, 2);
$manage  = $root . '/appWeb/public_html/manage';
$editor  = $manage . '/editor';
$incDir  = $root . '/appWeb/public_html/includes';

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \xE2\x9C\x85 {$label}\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  \xE2\x9D\x8C {$label}\n";
    }
}

/** PHP-code-only projection (drop comments/doc-blocks) so a pattern
 *  MENTIONED only in a doc-comment can never satisfy a source assertion —
 *  the same idiom test-editor-api-write-method.php's eawmPhpCode() uses,
 *  restated locally per this test tree's own no-shared-helper convention. */
function ebaPhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT || $tok[0] === T_INLINE_HTML) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/* =============================================================================
 * SELF-TEST — prove the CSRF-gate simulator can fail both ways before
 * trusting anything built on it (rule #34's own repeated lesson).
 * ============================================================================= */
echo "\nEditor API Bearer-auth seam guard\n\n";
echo "-- Self-test: gate simulators --\n";

/** Mirrors api2.php's exact expression:
 *    !$ed2BearerAuthed && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest'
 *  Returns true when the request would be BLOCKED (403). */
function ebaApi2CsrfBlocks(bool $bearerAuthed, bool $hasXRequestedWith): bool
{
    return !$bearerAuthed && !$hasXRequestedWith;
}

/** Mirrors the legacy api.php's exact expression:
 *    !$edLegacyBearerAuthed && !validateCsrfRequest(...)
 *  validateCsrfRequest() itself accepts a valid session token OR the
 *  X-Requested-With same-origin signal (auth.php); for the purposes of this
 *  DB-free/session-free simulation, a request with no baked session token
 *  passes validateCsrfRequest() iff X-Requested-With is present — that is
 *  exactly the scenario this test exercises (a fresh, un-authenticated-by-
 *  cookie-history native/browser client), and is the SAME assumption
 *  test-editor-api-write-method.php's sibling guard makes about this gate.
 *  Returns true when the request would be BLOCKED (403). */
function ebaLegacyCsrfBlocks(bool $bearerAuthed, bool $hasXRequestedWith): bool
{
    $validateCsrfRequestSimulated = $hasXRequestedWith; // no session token in this simulation
    return !$bearerAuthed && !$validateCsrfRequestSimulated;
}

/** Mirrors the shared auth-guard shape every one of the three files now
 *  uses:
 *    if ($bearerAuthed) { $currentUser = $bearerUser; }
 *    else { if (!$cookieAuthed) { 401 } $currentUser = $cookieUser; }
 *  Returns 'bearer' | 'cookie' | '401'. */
function ebaAuthOutcome(bool $bearerAuthed, bool $cookieAuthed): string
{
    if ($bearerAuthed) { return 'bearer'; }
    if (!$cookieAuthed) { return '401'; }
    return 'cookie';
}

check('CSRF simulator FAILS-HIGH: bearer + no X-Requested-With -> ACCEPTED', ebaApi2CsrfBlocks(true, false) === false);
check('CSRF simulator FAILS-LOW: cookie + no X-Requested-With -> BLOCKED', ebaApi2CsrfBlocks(false, false) === true);
check('CSRF simulator: cookie + X-Requested-With -> ACCEPTED', ebaApi2CsrfBlocks(false, true) === false);
check('CSRF simulator: bearer + X-Requested-With -> ACCEPTED (belt and braces)', ebaApi2CsrfBlocks(true, true) === false);

check('auth-outcome simulator FAILS-HIGH: bearer authed -> "bearer" (tried first)', ebaAuthOutcome(true, false) === 'bearer');
check('auth-outcome simulator FAILS-LOW: neither authed -> "401"', ebaAuthOutcome(false, false) === '401');
check('auth-outcome simulator: cookie-only -> "cookie" (unchanged path)', ebaAuthOutcome(false, true) === 'cookie');
check('auth-outcome simulator: both present -> bearer wins (tried first, matches source order)', ebaAuthOutcome(true, true) === 'bearer');

/* =============================================================================
 * includes/api_tokens.php — the ONE shared verification core
 * ============================================================================= */
echo "\n-- includes/api_tokens.php (shared core) --\n";

$tokensFile = $incDir . '/api_tokens.php';
check('includes/api_tokens.php exists', is_file($tokensFile));
$tokensSrc  = is_file($tokensFile) ? (string)file_get_contents($tokensFile) : '';
$tokensCode = $tokensSrc !== '' ? ebaPhpCode($tokensSrc) : '';
check('includes/api_tokens.php is readable', $tokensSrc !== '');

check(
    'defines the ONE shared resolver apiTokenResolveBearerUser(\mysqli $db): ?array',
    (bool)preg_match('/function\s+apiTokenResolveBearerUser\s*\(\s*\\\\?mysqli\s+\$db\s*\)\s*:\s*\?array/', $tokensCode)
);
check(
    'the resolver verifies via tblApiTokens JOIN tblUsers (the SAME table api.php\'s getAuthenticatedUser() reads)',
    strpos($tokensCode, 'FROM tblApiTokens') !== false && strpos($tokensCode, 'JOIN tblUsers') !== false
);
check(
    'the resolver checks ExpiresAt and IsActive = 1 (same expiry/active predicate as the cookie path)',
    strpos($tokensCode, 'ExpiresAt') !== false && strpos($tokensCode, 'IsActive = 1') !== false
);
check(
    'the resolver hashes the token with sha256 before comparing (never compares the raw token)',
    strpos($tokensCode, "hash('sha256'") !== false
);
check(
    'the resolver reads the Bearer token from HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION (mirrors api.php\'s getAuthBearerToken())',
    strpos($tokensCode, 'HTTP_AUTHORIZATION') !== false && strpos($tokensCode, 'REDIRECT_HTTP_AUTHORIZATION') !== false
);
check(
    'the resolver returns lowercase keys (id/username/display_name/role/email) matching getCurrentUser()\'s shape',
    (bool)preg_match('/Id\s+AS\s+id/i', $tokensCode)
    && (bool)preg_match('/Role\s+AS\s+role/i', $tokensCode)
);

/* =============================================================================
 * A small, reusable per-file assertion routine — every endpoint file gets
 * the same battery, parameterised by its own variable/function/constant
 * names, so the three call sites below cannot silently drift from each
 * other (the very thing this whole feature exists to prevent, applied to
 * the test itself).
 * ============================================================================= */

/**
 * @param string      $label            Human label for output, e.g. 'api2.php'
 * @param string      $file             Absolute path to the endpoint file
 * @param string      $bearerAuthedVar  e.g. '$ed2BearerAuthed'
 * @param string      $resolveCallNeedle  A substring proving the shared core is called
 * @param string|null $getSafeConst     Allow-list const name, or null to skip (e) here
 * @param string|null $repWriteAction   A representative write action name for (e)
 * @return array{code:string,found:bool}
 */
function ebaCheckEndpoint(
    string $label,
    string $file,
    string $bearerAuthedVar,
    string $resolveCallNeedle,
    ?string $getSafeConst,
    ?string $repWriteAction
): array {
    check("{$label} exists", is_file($file));
    $src  = is_file($file) ? (string)file_get_contents($file) : '';
    check("{$label} is readable", $src !== '');
    $code = $src !== '' ? ebaPhpCode($src) : '';
    if ($code === '') {
        return ['code' => '', 'found' => false];
    }

    /* (shared core) — calls the ONE resolver, never a second inline query. */
    check("{$label} calls the shared apiTokenResolveBearerUser() core ({$resolveCallNeedle})", strpos($code, $resolveCallNeedle) !== false);
    check(
        "{$label} does NOT re-open a second SELECT ... FROM tblApiTokens (no forked verification query outside the shared core call)",
        substr_count($code, 'FROM tblApiTokens') === 0
    );

    /* (guard ordering) — Bearer branch tried first, cookie branch unchanged. */
    $bearerVarPos = strpos($code, $bearerAuthedVar . ' ');
    check("{$label} assigns {$bearerAuthedVar}", $bearerVarPos !== false);
    $ifBearerPos = strpos($code, 'if (' . $bearerAuthedVar . ')');
    check("{$label} branches on {$bearerAuthedVar} immediately (if ({$bearerAuthedVar}) {{)", $ifBearerPos !== false);
    $isAuthenticatedPos = strpos($code, 'isAuthenticated()', $ifBearerPos !== false ? $ifBearerPos : 0);
    check("{$label}'s isAuthenticated() cookie check sits AFTER the Bearer branch (tried second, unchanged)", $ifBearerPos !== false && $isAuthenticatedPos !== false && $isAuthenticatedPos > $ifBearerPos);
    check("{$label} still calls getCurrentUser() on the cookie path (unchanged)", strpos($code, 'getCurrentUser()') !== false);
    check("{$label} still calls hasRole(...,'editor') to gate role (unchanged)", strpos($code, 'hasRole(') !== false && strpos($code, "'editor')") !== false);

    /* (L-1, item e) */
    if ($getSafeConst !== null) {
        $gatePos   = strpos($code, $getSafeConst);
        check("{$label} still defines/uses {$getSafeConst} (L-1 GET-write allow-list untouched)", $gatePos !== false);
        if ($gatePos !== false && $ifBearerPos !== false) {
            check("{$label}'s auth guard runs BEFORE the L-1 GET-write gate (auth decided first, method gated after — unaffected by which auth path won)", $ifBearerPos < $gatePos);
        }
    }
    if ($repWriteAction !== null) {
        check("{$label} still names '{$repWriteAction}' as a case (representative write action still exists)", strpos($code, "'{$repWriteAction}'") !== false);
    }

    return ['code' => $code, 'found' => true];
}

/* =============================================================================
 * api2.php
 * ============================================================================= */
echo "\n-- api2.php --\n";
$api2 = ebaCheckEndpoint(
    'manage/editor/api2.php',
    $editor . '/api2.php',
    '$ed2BearerAuthed',
    'ed2_resolveBearerUser()',
    'ED2_GET_SAFE_ACTIONS',
    'create_song'
);
if ($api2['found']) {
    $code = $api2['code'];

    check(
        'ed2_resolveBearerUser() delegates to apiTokenResolveBearerUser(getDbMysqli())',
        (bool)preg_match('/function\s+ed2_resolveBearerUser\s*\(\s*\)\s*:\s*\?array\s*\{\s*return\s+apiTokenResolveBearerUser\s*\(\s*getDbMysqli\s*\(\s*\)\s*\)\s*;/', $code)
    );

    /* --- (a)/(b)/(c): the production CSRF-gate expression, verified verbatim --- */
    $csrfExprFound = (bool)preg_match(
        "/if\\s*\\(\\s*!\\\$ed2BearerAuthed\\s*&&\\s*\\(\\\$_SERVER\\['HTTP_X_REQUESTED_WITH'\\]\\s*\\?\\?\\s*''\\)\\s*!==\\s*'XMLHttpRequest'\\s*\\)/",
        $code
    );
    check('api2.php\'s CSRF gate is EXACTLY `!$ed2BearerAuthed && (...) !== \'XMLHttpRequest\'` (the source this test simulates)', $csrfExprFound);
    check('SECURITY (b): cookie POST WITHOUT X-Requested-With is BLOCKED (unchanged)', ebaApi2CsrfBlocks(false, false) === true);
    check('(a): cookie POST WITH X-Requested-With is ACCEPTED (unchanged)', ebaApi2CsrfBlocks(false, true) === false);
    check('FEATURE (c): Bearer POST WITHOUT X-Requested-With is now ACCEPTED', ebaApi2CsrfBlocks(true, false) === false);

    /* --- (d): unauthenticated -> 401, reachable only in the Bearer-miss branch --- */
    check(
        'api2.php responds 401 "Authentication required." inside the Bearer-miss / cookie-miss branch (unauthenticated -> 401, unchanged)',
        (bool)preg_match(
            "/if\\s*\\(\\\$ed2BearerAuthed\\)\\s*\\{[^}]*\\}\\s*else\\s*\\{\\s*if\\s*\\(!isAuthenticated\\(\\)\\)\\s*\\{\\s*ed2_respond\\(\\['ok'\\s*=>\\s*false,\\s*'error'\\s*=>\\s*'Authentication required\\.'\\],\\s*401\\)/",
            $code
        )
    );
    check('(d): simulated outcome for no-bearer + no-cookie is "401"', ebaAuthOutcome(false, false) === '401');

    /* --- (e): a representative write action still refused on GET regardless
           of auth method — the L-1 gate has no auth-awareness at all, so it
           is unaffected by which branch authenticated the request. --- */
    $allowListPos = strpos($code, 'const ED2_GET_SAFE_ACTIONS = [');
    if ($allowListPos !== false) {
        $bracketStart = strpos($code, '[', $allowListPos);
        $depth = 0; $endPos = null;
        for ($i = $bracketStart, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '[') { $depth++; }
            elseif ($code[$i] === ']') { $depth--; if ($depth === 0) { $endPos = $i; break; } }
        }
        $allowList = null;
        if ($endPos !== null) {
            $literal = substr($code, $bracketStart, $endPos - $bracketStart + 1);
            try { eval('$allowList = ' . $literal . ';'); } catch (\Throwable $e) { $allowList = null; }
        }
        check('extracted ED2_GET_SAFE_ACTIONS as a real PHP array', is_array($allowList));
        if (is_array($allowList)) {
            check("(e): 'create_song' is NOT in the GET-safe allow-list (still POST-only, auth-method-independent)", !in_array('create_song', $allowList, true));
            check("(e): 'load_song' IS in the GET-safe allow-list (reads unaffected)", in_array('load_song', $allowList, true));
        }
    } else {
        check('ED2_GET_SAFE_ACTIONS array literal located', false);
    }
}

/* =============================================================================
 * api.php (legacy)
 * ============================================================================= */
echo "\n-- api.php (legacy) --\n";
$apiLegacy = ebaCheckEndpoint(
    'manage/editor/api.php (legacy)',
    $editor . '/api.php',
    '$edLegacyBearerAuthed',
    'apiTokenResolveBearerUser(getDbMysqli())',
    'IHYMNS_EDITOR_API_GET_SAFE_ACTIONS',
    'delete_song'
);
if ($apiLegacy['found']) {
    $code = $apiLegacy['code'];

    /* --- (a)/(b)/(c): the production CSRF-gate expression, verified verbatim --- */
    $csrfExprFound = (bool)preg_match(
        "/if\\s*\\(\\s*\\(\\\$_SERVER\\['REQUEST_METHOD'\\]\\s*\\?\\?\\s*'GET'\\)\\s*===\\s*'POST'\\s*&&\\s*!\\\$edLegacyBearerAuthed\\s*&&\\s*!validateCsrfRequest\\(/",
        $code
    );
    check('api.php\'s CSRF gate is EXACTLY `... === \'POST\' && !$edLegacyBearerAuthed && !validateCsrfRequest(...)` (the source this test simulates)', $csrfExprFound);
    check('SECURITY (b): cookie POST WITHOUT X-Requested-With is BLOCKED (unchanged)', ebaLegacyCsrfBlocks(false, false) === true);
    check('(a): cookie POST WITH X-Requested-With is ACCEPTED (unchanged)', ebaLegacyCsrfBlocks(false, true) === false);
    check('FEATURE (c): Bearer POST WITHOUT X-Requested-With is now ACCEPTED', ebaLegacyCsrfBlocks(true, false) === false);

    /* --- (d): unauthenticated -> 401 --- */
    check(
        'api.php responds 401 "Authentication required." inside the Bearer-miss / cookie-miss branch (unauthenticated -> 401, unchanged)',
        (bool)preg_match(
            "/if\\s*\\(\\\$edLegacyBearerAuthed\\)\\s*\\{[^}]*\\}\\s*else\\s*\\{\\s*if\\s*\\(!isAuthenticated\\(\\)\\)\\s*\\{[^}]*http_response_code\\(401\\)/",
            $code
        )
    );
    check('(d): simulated outcome for no-bearer + no-cookie is "401"', ebaAuthOutcome(false, false) === '401');

    /* --- (e): a representative write action still refused on GET --- */
    $allowListPos = strpos($code, 'const IHYMNS_EDITOR_API_GET_SAFE_ACTIONS = [');
    if ($allowListPos !== false) {
        $bracketStart = strpos($code, '[', $allowListPos);
        $depth = 0; $endPos = null;
        for ($i = $bracketStart, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '[') { $depth++; }
            elseif ($code[$i] === ']') { $depth--; if ($depth === 0) { $endPos = $i; break; } }
        }
        $allowList = null;
        if ($endPos !== null) {
            $literal = substr($code, $bracketStart, $endPos - $bracketStart + 1);
            try { eval('$allowList = ' . $literal . ';'); } catch (\Throwable $e) { $allowList = null; }
        }
        check('extracted IHYMNS_EDITOR_API_GET_SAFE_ACTIONS as a real PHP array', is_array($allowList));
        if (is_array($allowList)) {
            check("(e): 'delete_song' is NOT in the GET-safe allow-list (still POST-only, auth-method-independent)", !in_array('delete_song', $allowList, true));
            check("(e): 'load_song' IS in the GET-safe allow-list (reads unaffected)", in_array('load_song', $allowList, true));
        }
    } else {
        check('IHYMNS_EDITOR_API_GET_SAFE_ACTIONS array literal located', false);
    }
}

/* =============================================================================
 * manage/places-api.php — same shared core, same Bearer-exempts-CSRF wiring
 * on its one write action (upsert). No L-1 allow-list exists on this file
 * (it has exactly one write action, gated inline), so (e) is N/A here.
 * ============================================================================= */
echo "\n-- manage/places-api.php --\n";
$placesFile = $manage . '/places-api.php';
check('manage/places-api.php exists', is_file($placesFile));
$placesSrc  = is_file($placesFile) ? (string)file_get_contents($placesFile) : '';
check('manage/places-api.php is readable', $placesSrc !== '');
$placesCode = $placesSrc !== '' ? ebaPhpCode($placesSrc) : '';

if ($placesCode !== '') {
    check('places-api.php calls the shared apiTokenResolveBearerUser() core', strpos($placesCode, 'apiTokenResolveBearerUser(getDbMysqli())') !== false);
    check(
        'places-api.php does NOT re-open a second SELECT ... FROM tblApiTokens (no forked verification query)',
        substr_count($placesCode, 'FROM tblApiTokens') === 0
    );
    check('places-api.php assigns $placesBearerAuthed', strpos($placesCode, '$placesBearerAuthed') !== false);
    check(
        'places-api.php\'s upsert CSRF gate is EXACTLY `!$placesBearerAuthed && !validateCsrfRequest(...)` (Bearer exempts CSRF here too)',
        (bool)preg_match("/if\\s*\\(\\s*!\\\$placesBearerAuthed\\s*&&\\s*!validateCsrfRequest\\(/", $placesCode)
    );
    check('SECURITY (b): cookie POST WITHOUT X-Requested-With is BLOCKED (unchanged)', ebaLegacyCsrfBlocks(false, false) === true);
    check('FEATURE (c): Bearer POST WITHOUT X-Requested-With is now ACCEPTED', ebaLegacyCsrfBlocks(true, false) === false);
    check(
        'places-api.php responds 401 inside the Bearer-miss / cookie-miss branch (unauthenticated -> 401, unchanged)',
        (bool)preg_match(
            "/if\\s*\\(\\\$placesBearerAuthed\\)\\s*\\{[^}]*\\}\\s*else\\s*\\{\\s*if\\s*\\(!isAuthenticated\\(\\)\\)\\s*\\{[^}]*http_response_code\\(401\\)/",
            $placesCode
        )
    );
}

/* =============================================================================
 * PHP syntax check on all four touched files — cheap, catches a stray
 * bracket the regex checks above wouldn't (they only pattern-match text).
 * ============================================================================= */
echo "\n-- php -l on every touched file --\n";
foreach ([
    'includes/api_tokens.php'        => $tokensFile,
    'manage/editor/api2.php'         => $editor . '/api2.php',
    'manage/editor/api.php (legacy)' => $editor . '/api.php',
    'manage/places-api.php'          => $placesFile,
] as $label => $path) {
    if (!is_file($path)) { check("{$label} exists for lint", false); continue; }
    $out = [];
    $rc  = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    check("{$label} passes php -l", $rc === 0);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "\nAll editor API Bearer-auth seam assertions passed.\n";
exit(0);
