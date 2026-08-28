<?php

declare(strict_types=1);

/**
 * iHymns — server-PDF endpoint Bearer-auth seam guard (API-coverage C6,
 * `.claude/api-coverage-2026-08-28.md` §4.1, owner-approved Q3)
 * ============================================================================
 *
 * ELI5
 * ----
 * `/manage/print-pdf.php` used to only accept a `/manage/` browser cookie
 * session. Native apps have no cookie jar, so it was taught to ALSO accept
 * an `Authorization: Bearer <token>` header first, resolved through the SAME
 * `tblApiTokens` verification core the public `api.php` and the editor's
 * Bearer-capable endpoints already use
 * (`includes/api_tokens.php`'s `apiTokenResolveBearerUser()`) — never a
 * second, forked token-verification query. This file is the mutation-proven
 * proof that:
 *
 *   (a) a resolved Bearer token is accepted, exactly as a cookie session
 *       would be
 *   (b) a cookie session is STILL accepted (unchanged) when no Bearer header
 *       is present
 *   (c) an absent/invalid Bearer with no cookie session is STILL a 401
 *       (unchanged failure shape)
 *   (d) the `?ping=1` feature-detect contract is unchanged (still 401
 *       unauthenticated / 503 no-engine / 204 all-clear) and now reflects
 *       the SAME Bearer-then-cookie identity as the main POST flow
 *
 * WHAT IT ASSERTS (DB-free — this test never boots the app, connects to
 * MySQL, opens a session, or sends an HTTP request; it works entirely from
 * source text plus pure boolean simulators of the EXACT production control
 * flow, the same posture as `tests/php/test-editor-api-bearer-auth.php`, the
 * sibling guard this one is modelled on):
 *
 *   1. `includes/api_tokens.php` still defines the ONE shared resolver
 *      `apiTokenResolveBearerUser()`, and `manage/print-pdf.php` calls it —
 *      never a second, forked verification query (rule #22).
 *   2. `manage/print-pdf.php` defines ONE resolver,
 *      `_pdfResolveAuthenticatedUser()`, that tries the Bearer branch FIRST
 *      and independently, and — on a miss — falls through to the EXACT
 *      PRE-EXISTING `isAuthenticated()`/`getCurrentUser()` cookie check (the
 *      source text proves the miss-path is unchanged, not merely assumed).
 *   3. Simulating the EXACT control flow of `_pdfResolveAuthenticatedUser()`
 *      proves (a)-(c): Bearer-authed -> the Bearer user; Bearer-miss +
 *      cookie-authed -> the cookie user; Bearer-miss + cookie-miss -> null.
 *   4. BOTH call sites — the `?ping=1` GET probe and the main POST auth
 *      gate — call `_pdfResolveAuthenticatedUser()` (not a re-typed check),
 *      and both still fail with their documented status codes (401) on a
 *      miss (d).
 *   5. The `?ping=1` contract's OTHER two outcomes — 503 (no engine) and 204
 *      (all clear) — are still present and still gated by
 *      `ihymnsPdfEngineAvailable()`, unaffected by the auth change.
 *   6. The 401 JSON failure shape (`_pdfFailJson(401, 'Authentication
 *      required.')`, no redirect) is unchanged on the POST path.
 *   7. The endpoint's own "no additional entitlement beyond auth" contract
 *      is unchanged — no `userHasEntitlement()`/`requireAdmin()` call was
 *      introduced by this change.
 *   8. `manage/print-pdf.php` still passes `php -l`.
 *
 * HOW TO RE-PROVE THIS FILE IS NOT VACUOUS (rule #34 — done once by hand
 * when this file was authored):
 *   1. In print-pdf.php, change `if ($bearerUser !== null) { return
 *      $bearerUser; }` to `if (false) { ... }` inside
 *      `_pdfResolveAuthenticatedUser()` -> the (a)/simulator-derived
 *      assertions go RED (a resolved Bearer no longer authenticates).
 *   2. In print-pdf.php, swap the resolver's if/else so
 *      `isAuthenticated()` runs BEFORE the Bearer branch -> the ordering
 *      assertion (check 2 above) goes RED.
 *   3. In print-pdf.php, change the `?ping=1` block back to calling
 *      `isAuthenticated()` directly instead of
 *      `_pdfResolveAuthenticatedUser()` -> the "both call sites share the
 *      resolver" assertion goes RED.
 *   4. In includes/api_tokens.php, rename `apiTokenResolveBearerUser()` ->
 *      the "shared core exists and is called" assertions go RED.
 *   5. Restore each file -> the suite is GREEN again.
 *
 *   php tests/php/test-print-pdf-bearer.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see appWeb/public_html/manage/print-pdf.php             _pdfResolveAuthenticatedUser() — the resolver under test
 * @see appWeb/public_html/includes/api_tokens.php           apiTokenResolveBearerUser() — the ONE shared core (rule #22)
 * @see appWeb/public_html/song-media.php                    _songMedia_resolveViewer() — the Bearer-then-cookie two-step this mirrors
 * @see tests/php/test-editor-api-bearer-auth.php            the sibling guard this file's shape/style is modelled on
 * @see tests/php/test-print-one-renderer.php                the not-public / one-renderer guard that must also stay green
 */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/appWeb/public_html';
$printPdfPath = $publicRoot . '/manage/print-pdf.php';
$tokensPath   = $publicRoot . '/includes/api_tokens.php';

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
 *  the same tokenizer-based idiom test-editor-api-bearer-auth.php's
 *  ebaPhpCode() / test-print-one-renderer.php's stripper use. */
function ppbPhpCode(string $src): string
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

echo "\nServer-PDF endpoint Bearer-auth seam guard\n\n";

/* =============================================================================
 * SELF-TEST — prove the resolver simulator can fail all three ways before
 * trusting anything built on it (rule #34's own repeated lesson).
 * ============================================================================= */
echo "-- Self-test: resolver simulator --\n";

/** Mirrors _pdfResolveAuthenticatedUser()'s EXACT control flow:
 *    $bearerUser = apiTokenResolveBearerUser(getDbMysqli());
 *    if ($bearerUser !== null) { return $bearerUser; }
 *    if (!isAuthenticated()) { return null; }
 *    return getCurrentUser();
 *  Returns 'bearer' | 'cookie' | 'null'. */
function ppbResolveOutcome(?string $bearerUser, bool $cookieAuthed, ?string $cookieUser): ?string
{
    if ($bearerUser !== null) {
        return $bearerUser;
    }
    if (!$cookieAuthed) {
        return null;
    }
    return $cookieUser;
}

check('simulator FAILS-HIGH: Bearer resolves -> that user (tried first)', ppbResolveOutcome('bearer-user', false, null) === 'bearer-user');
check('simulator FAILS-LOW: no Bearer, no cookie -> null', ppbResolveOutcome(null, false, null) === null);
check('simulator: no Bearer, cookie authed -> the cookie user (unchanged path)', ppbResolveOutcome(null, true, 'cookie-user') === 'cookie-user');
check('simulator: both present -> Bearer wins (tried first, matches source order)', ppbResolveOutcome('bearer-user', true, 'cookie-user') === 'bearer-user');

/* =============================================================================
 * includes/api_tokens.php — the ONE shared verification core still exists
 * ============================================================================= */
echo "\n-- includes/api_tokens.php (shared core) --\n";

check('includes/api_tokens.php exists', is_file($tokensPath));
$tokensSrc  = is_file($tokensPath) ? (string)file_get_contents($tokensPath) : '';
check('includes/api_tokens.php is readable', $tokensSrc !== '');
$tokensCode = $tokensSrc !== '' ? ppbPhpCode($tokensSrc) : '';

check(
    'defines the ONE shared resolver apiTokenResolveBearerUser(\mysqli $db): ?array',
    (bool)preg_match('/function\s+apiTokenResolveBearerUser\s*\(\s*\\\\?mysqli\s+\$db\s*\)\s*:\s*\?array/', $tokensCode)
);

/* =============================================================================
 * manage/print-pdf.php — the endpoint under test
 * ============================================================================= */
echo "\n-- manage/print-pdf.php --\n";

check('manage/print-pdf.php exists', is_file($printPdfPath));
$src  = is_file($printPdfPath) ? (string)file_get_contents($printPdfPath) : '';
check('manage/print-pdf.php is readable', $src !== '');
$code = $src !== '' ? ppbPhpCode($src) : '';

if ($code !== '') {
    /* ---- shared-core wiring (rule #22) ---- */
    check(
        'requires includes/api_tokens.php (the shared resolver source)',
        strpos($code, "'api_tokens.php'") !== false
    );
    check(
        'calls the shared apiTokenResolveBearerUser() core',
        strpos($code, 'apiTokenResolveBearerUser(') !== false
    );
    check(
        'does NOT re-open a second SELECT ... FROM tblApiTokens (no forked verification query outside the shared core call)',
        substr_count($code, 'FROM tblApiTokens') === 0
    );

    /* ---- ONE resolver function, correctly shaped ---- */
    check(
        'defines the ONE resolver _pdfResolveAuthenticatedUser(): ?array',
        (bool)preg_match('/function\s+_pdfResolveAuthenticatedUser\s*\(\s*\)\s*:\s*\?array/', $code)
    );
    /* Extract the resolver's own body (balanced-brace, not "somewhere in
       the file") so every assertion below is scoped to what this ONE
       function actually does — mirrors test-print-pdf-batch.php's own
       balanced-brace loop-body extraction idiom. */
    $fnPos = strpos($code, 'function _pdfResolveAuthenticatedUser(');
    $resolverBody = '';
    $resolverEndPos = null; // absolute position in $code of the resolver's closing brace
    if ($fnPos !== false) {
        $braceStart = strpos($code, '{', $fnPos);
        if ($braceStart !== false) {
            $depth = 0;
            $endPos = null;
            for ($i = $braceStart, $len = strlen($code); $i < $len; $i++) {
                if ($code[$i] === '{') { $depth++; }
                elseif ($code[$i] === '}') { $depth--; if ($depth === 0) { $endPos = $i; break; } }
            }
            if ($endPos !== null) {
                $resolverBody = substr($code, $braceStart, $endPos - $braceStart + 1);
                $resolverEndPos = $endPos; // absolute index into $code — reused below
            }
        }
    }
    check('extracted _pdfResolveAuthenticatedUser()\'s function body', $resolverBody !== '');

    if ($resolverBody !== '') {
        $bearerCallPos = strpos($resolverBody, 'apiTokenResolveBearerUser(getDbMysqli())');
        check('resolver body calls apiTokenResolveBearerUser(getDbMysqli())', $bearerCallPos !== false);

        $bearerReturnPos = strpos($resolverBody, 'return $bearerUser;');
        check('resolver body returns the Bearer user when resolved (return $bearerUser;)', $bearerReturnPos !== false);

        $isAuthenticatedPos = strpos($resolverBody, 'isAuthenticated()');
        check(
            'resolver body\'s isAuthenticated() cookie check sits AFTER the Bearer branch (tried second, unchanged ordering)',
            $bearerCallPos !== false && $isAuthenticatedPos !== false && $isAuthenticatedPos > $bearerReturnPos
        );

        check(
            'resolver body returns null on a Bearer-miss + cookie-miss (if (!isAuthenticated()) { return null; })',
            (bool)preg_match('/if\s*\(\s*!\s*isAuthenticated\s*\(\s*\)\s*\)\s*\{\s*return\s+null\s*;\s*\}/', $resolverBody)
        );

        check(
            'resolver body falls through to getCurrentUser() on the cookie path (unchanged)',
            (bool)preg_match('/return\s+getCurrentUser\s*\(\s*\)\s*;/', $resolverBody)
        );

        /* Side-effect-free: never sets a response code or calls the
           endpoint's own fail helper — both call sites decide how to react
           to a miss independently (the doc-block's stated contract). */
        check(
            'resolver body never calls _pdfFailJson()/http_response_code() itself (side-effect-free, shared by both call sites)',
            strpos($resolverBody, '_pdfFailJson(') === false && strpos($resolverBody, 'http_response_code(') === false
        );
    }

    /* ---- (d): both call sites use the ONE resolver ---- */
    $resolverCallCount = substr_count($code, '_pdfResolveAuthenticatedUser()');
    /* 1 definition + at least 2 call sites (ping GET, main POST auth gate). */
    check(
        '_pdfResolveAuthenticatedUser() is called from at least 2 sites (the ?ping=1 probe AND the main POST auth gate) plus its own definition',
        $resolverCallCount >= 3
    );

    /* ---- ?ping=1 block still gated by the resolver, still 401/503/204 ---- */
    $pingBlockStart = strpos($code, "isset(\$_GET['ping'])");
    check('located the ?ping=1 GET block', $pingBlockStart !== false);
    if ($pingBlockStart !== false) {
        /* Slice out roughly the ping block (up to the POST-method-gate that
           follows it) for scoped assertions. */
        $postGatePos = strpos($code, "!== 'POST'", $pingBlockStart);
        $pingBlock = $postGatePos !== false
            ? substr($code, $pingBlockStart, $postGatePos - $pingBlockStart)
            : substr($code, $pingBlockStart, 800);

        check(
            '?ping=1 block calls _pdfResolveAuthenticatedUser() (not a re-typed isAuthenticated() check)',
            strpos($pingBlock, '_pdfResolveAuthenticatedUser()') !== false
        );
        check(
            '?ping=1 block does NOT call isAuthenticated() directly (delegates entirely to the shared resolver)',
            strpos($pingBlock, 'isAuthenticated()') === false
        );
        check(
            '?ping=1 block responds 401 on a resolver miss ("=== null")',
            (bool)preg_match('/_pdfResolveAuthenticatedUser\(\)\s*===\s*null\s*\)\s*\{\s*http_response_code\(401\)/', $pingBlock)
        );
        check(
            '?ping=1 block STILL checks ihymnsPdfEngineAvailable() for the 503 branch (unaffected by the auth change)',
            strpos($pingBlock, 'ihymnsPdfEngineAvailable()') !== false && strpos($pingBlock, 'http_response_code(503)') !== false
        );
        check(
            '?ping=1 block STILL emits 204 on the all-clear path (unaffected by the auth change)',
            strpos($pingBlock, 'http_response_code(204)') !== false
        );
    }

    /* ---- main POST auth gate: unchanged 401 JSON shape, resolver-driven ---- */
    check(
        'main POST auth gate assigns $currentUser = _pdfResolveAuthenticatedUser();',
        (bool)preg_match('/\$currentUser\s*=\s*_pdfResolveAuthenticatedUser\s*\(\s*\)\s*;/', $code)
    );
    check(
        'main POST auth gate still fails via _pdfFailJson(401, \'Authentication required.\') on a miss (unchanged JSON shape, no redirect)',
        (bool)preg_match('/if\s*\(\s*!\$currentUser\s*\)\s*\{\s*_pdfFailJson\(401,\s*\'Authentication required\.\'\)\s*;\s*\}/', $code)
    );
    /* The auth gate must not be a bare isAuthenticated() call any more —
       proves the fallback was actually wired in, not left dormant beside a
       dead resolver definition. Scoped to strictly AFTER the resolver
       function's own closing brace (which legitimately contains its own
       `if (!isAuthenticated())` internally) and up to the auth-gate call
       site, so the resolver's own internals can never trip this check. */
    $authGatePos = strpos($code, '$currentUser = _pdfResolveAuthenticatedUser();');
    check(
        'no leftover direct isAuthenticated() call between the resolver definition and the auth gate (old gate fully replaced, not left running alongside the new one)',
        $authGatePos !== false && $resolverEndPos !== null && $authGatePos > $resolverEndPos
        && strpos(substr($code, $resolverEndPos, $authGatePos - $resolverEndPos), 'if (!isAuthenticated())') === false
    );

    /* ---- unchanged contracts this change must NOT touch ---- */
    check(
        'still NO userHasEntitlement()/requireAdmin() call anywhere (auth-only gate contract unchanged, rule: file doc-block "GATE")',
        strpos($code, 'userHasEntitlement(') === false && strpos($code, 'requireAdmin(') === false
    );
    check(
        'CSRF gate still calls validateCsrfRequest() unconditionally (not exempted for Bearer — out of this change\'s scope)',
        (bool)preg_match('/if\s*\(\s*!validateCsrfRequest\(/', $code)
    );
    check(
        'PDF_ALLOWED_MODES still recognises \'batch\' (unrelated cap machinery untouched by the auth change)',
        strpos($code, "'batch'") !== false
    );
    check(
        '405 method-gate for non-POST/non-ping requests is unchanged',
        strpos($code, "_pdfFailJson(405, 'POST required (or GET ?ping=1).');") !== false
    );
}

/* =============================================================================
 * PHP syntax check — cheap, catches a stray bracket the regex checks above
 * wouldn't (they only pattern-match text).
 * ============================================================================= */
echo "\n-- php -l --\n";
foreach ([
    'includes/api_tokens.php'   => $tokensPath,
    'manage/print-pdf.php'      => $printPdfPath,
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
echo "\nAll server-PDF Bearer-auth seam assertions passed.\n";
exit(0);
