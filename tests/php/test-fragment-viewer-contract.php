<?php

declare(strict_types=1);

/**
 * iHymns — Fragment viewer-variable contract guard (#1710)
 *
 * ELI5: `includes/pages/*.php` are HTML fragments the SPA fetches over
 * `/api?page=…`. A fragment can only "see" a `$variable` if api.php sets it
 * before `require`-ing the fragment. `$currentUser` (the signed-in viewer) is
 * one of those injected variables. This guard makes sure that if a fragment
 * asks "is someone signed in?" by reading `$currentUser`, api.php actually
 * resolves it — because for a long time it did NOT, and every signed-in user
 * was told to "Sign in to sync across devices" on a page they reached by being
 * signed in (#1710). No error, no console warning — the page rendered fine and
 * the one thing it should have known (who you are) was simply undefined.
 *
 * THE BUG THIS GUARDS (#1710)
 * ---------------------------
 * `includes/pages/settings.php` reads `!empty($currentUser)` to choose between
 * "Your selection syncs across devices" and "Sign in to sync…". But api.php's
 * page-routing switch NEVER assigned `$currentUser`, so in the fragment's scope
 * the variable was undefined → `!empty()` always false → the signed-out copy
 * shipped to everyone. It survived because it fails in the HARMLESS direction
 * (an extra invitation, not a lost feature) and the language preference itself
 * syncs over a separate client-side path. It nearly became a SECOND bug when
 * #1695 reached for `$currentUser['role']` to filter a push-kind server-side —
 * which would have evaluated null for everyone and hidden a control from admins
 * too (the rule #30 silent-no-op class). The fix (api.php) resolves the viewer
 * ONCE, before the switch, gated on `!$_shouldCachePage` (rule #6 — a
 * shared-cache fragment must never be personalised).
 *
 * WHY THIS GUARD IS NARROW — AND DELIBERATELY NOT A GENERAL "undefined variable
 * in a fragment" SCANNER
 * ----------------------------------------------------------------------------
 * A general "a fragment reads a variable no caller sets" check sounds better and
 * is a rule #34 trap. To be correct it would need (a) the COMPLETE set of
 * variables api.php provides — globally before the switch (`$app`, `$songData`,
 * `$_cacheablePages`, …) AND per-`case` (`$songId`, `$bookId`, `$personSlug`,
 * `$workSlug`, `$tagSlug`, `$publisherSlug`, …) — and (b) correct write-detection
 * inside each fragment across every PHP binding form (`$x =`, `foreach as $x`,
 * `[$a,$b] =`, `list()`, `global`, `static`, closure params, `catch (T $x)`).
 * Get the provides-set incomplete and it screams on correct code (deleted); get
 * write-detection incomplete and it stays green while a real undefined read
 * ships (worse than no scanner). Both failure modes are documented history in
 * this repo. So this guard names the ONE variable that actually bit us — the
 * injected VIEWER — and asserts its contract precisely. A new injected-variable
 * class that grows a bug of its own gets its own precise guard, the same way.
 *
 * WHAT IT ASSERTS
 * ---------------
 *  A) CONSUMER-BACKED (the reporter's ask, #1710): every `includes/pages/*.php`
 *     fragment that READS `$currentUser` (any reference that is not the LHS of a
 *     plain `=` assignment) must be backed by api.php ASSIGNING `$currentUser` in
 *     its fragment-routing region (between `$_cacheablePages = [` and
 *     `switch ($page)`). Mutation proof: delete the api.php assignment → settings
 *     .php still reads `$currentUser` → RED.
 *
 *  B) CACHE-GATED (rule #6): the api.php resolution must express its
 *     cacheability gate IN the assignment statement (the shipped
 *     `$_shouldCachePage ? null : getAuthenticatedUser()` form). Concretely: no
 *     `$currentUser = …getAuthenticatedUser()…;` statement in the region may omit
 *     a `$_shouldCachePage` / `$_cacheablePages` reference. Mutation proof:
 *     collapse the ternary to an unconditional
 *     `$currentUser = getAuthenticatedUser();` (which would run a per-viewer DB
 *     read + set a non-null viewer on the shared-cache path — the rule #6 leak) →
 *     RED. A future refactor is free to keep the gate in-statement or update this
 *     guard's B-check alongside it; that in-statement convention is the assertion.
 *
 * Comments are stripped before matching (this very file, and api.php's own
 * annotation, both mention `$currentUser` in prose — rule #34's "prose must not
 * satisfy a code assertion" applies in reverse: prose must not TRIP one either).
 *
 *   php tests/php/test-fragment-viewer-contract.php
 *
 * Exit status 0 = contract holds, 1 = a fragment reads an unprovided viewer, or
 * the resolution lost its cache gate.
 *
 * @see https://www.php.net/manual/en/function.token-get-all.php
 */

/**
 * Blank `/* … *\/`, `//` and `#` PHP comments and `<!-- … -->` HTML comments,
 * preserving newlines so any later offset still maps to its real line.
 *
 * ELI5: correction-tape the comments so their words can't match, but keep the
 * page the same length so line numbers below don't shift.
 */
function viewerContractStripComments(string $src): string
{
    // Token-driven for PHP so we never mis-blank a `#`/`//` that lives inside a
    // string literal; HTML comments are handled by regex afterwards.
    $out  = '';
    $toks = @token_get_all($src);
    if (!is_array($toks)) {
        return $src;
    }
    foreach ($toks as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    // HTML comments (fragments are mostly HTML with PHP islands).
    $out = preg_replace_callback('/<!--.*?-->/s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $out);

    return $out;
}

/**
 * Does this fragment READ `$currentUser` — i.e. reference it anywhere that is
 * not the left-hand side of a plain `=` assignment?
 *
 * ELI5: does the page ever ASK about the viewer, rather than only setting one
 * up itself? `!empty($currentUser)`, `$currentUser['Role']`, `if ($currentUser)`
 * all count as asking; only `$currentUser = …` is "setting up".
 *
 * Detail: token-level so a `$currentUser` mentioned in a comment/string can't
 * match (comments are already blanked by the caller; strings tokenise as their
 * own token and never yield a T_VARIABLE). A "write" is the single next
 * significant token being a bare `=` char; `==`/`===`/`!=` tokenise as
 * T_IS_EQUAL / T_IS_IDENTICAL / … (never the `=` char), so comparisons read as
 * reads, correctly. `$currentUser['x'] = …` is classed a READ of the base — and
 * that IS a read (the element write needs the base to already exist), so
 * counting the fragment as a consumer there is right, not a false positive.
 *
 * @return int  1-indexed line of the first read, or 0 if the fragment never
 *              reads `$currentUser`.
 */
function viewerContractFragmentReadsLine(string $src): int
{
    $toks = @token_get_all($src);
    if (!is_array($toks)) {
        return 0;
    }
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$currentUser') {
            continue;
        }
        // Look ahead to the next significant token.
        $isWrite = false;
        for ($j = $i + 1; $j < $n; $j++) {
            $u = $toks[$j];
            if (is_array($u) && ($u[0] === T_WHITESPACE || $u[0] === T_COMMENT || $u[0] === T_DOC_COMMENT)) {
                continue;
            }
            $isWrite = (!is_array($u) && $u === '=');
            break;
        }
        if (!$isWrite) {
            return is_array($t) ? (int)$t[2] : 0;   // token line number
        }
    }
    return 0;
}

$repoRoot = dirname(__DIR__, 2);
$apiPhp   = $repoRoot . '/appWeb/public_html/api.php';
$pagesDir = $repoRoot . '/appWeb/public_html/includes/pages';

$failures = [];

/* ---- api.php side: does the router PROVIDE $currentUser, and is it gated? --- */
if (!is_file($apiPhp)) {
    fwrite(STDERR, "FATAL: $apiPhp not found\n");
    exit(1);
}
$apiSrc     = viewerContractStripComments((string)file_get_contents($apiPhp));
// Region = the fragment-routing block, bracketed by two anchors that each occur
// exactly once: the cacheable-pages array literal and the page dispatch switch.
$regionStart = strpos($apiSrc, '$_cacheablePages = [');
$regionEnd   = strpos($apiSrc, 'switch ($page)');
if ($regionStart === false || $regionEnd === false || $regionEnd <= $regionStart) {
    fwrite(STDERR, "FATAL: could not locate the api.php fragment-routing region "
        . "(anchors '\$_cacheablePages = [' … 'switch (\$page)'). If these were "
        . "renamed, update this guard's anchors (#1710).\n");
    exit(1);
}
$region = substr($apiSrc, $regionStart, $regionEnd - $regionStart);

// Every `$currentUser = <rhs>;` assignment statement in the region.
preg_match_all('/\$currentUser\s*=\s*(?!=)([^;]*);/', $region, $assigns);
$providesCurrentUser = count($assigns[0]) > 0;

// B) cache-gate: any assignment whose RHS calls getAuthenticatedUser() must
//    also reference the cacheability gate in the SAME statement.
$ungatedResolve = [];
foreach ($assigns[1] as $rhs) {
    $callsResolver = stripos($rhs, 'getAuthenticatedUser') !== false;
    $mentionsGate  = (strpos($rhs, '$_shouldCachePage') !== false)
                  || (strpos($rhs, '$_cacheablePages') !== false);
    if ($callsResolver && !$mentionsGate) {
        $ungatedResolve[] = trim($rhs);
    }
}

/* ---- fragment side: who READS $currentUser? -------------------------------- */
if (!is_dir($pagesDir)) {
    fwrite(STDERR, "FATAL: $pagesDir not found\n");
    exit(1);
}
$consumers = [];   // basename => line
$scanned   = 0;
foreach (glob($pagesDir . '/*.php') ?: [] as $file) {
    $src = (string)file_get_contents($file);
    if ($src === '') { continue; }
    $scanned++;
    $stripped = viewerContractStripComments($src);
    $line     = viewerContractFragmentReadsLine($stripped);
    if ($line > 0) {
        $consumers[basename($file)] = $line;
    }
}

/* ---- assertions ------------------------------------------------------------ */

// A) A consumer with no provider is the #1710 bug, back again.
if ($consumers && !$providesCurrentUser) {
    foreach ($consumers as $base => $line) {
        $failures[] = sprintf(
            'includes/pages/%s:%d reads $currentUser, but api.php never assigns it '
            . 'before requiring the fragment — the #1710 bug (signed-in users told to sign in).',
            $base,
            $line
        );
    }
}

// B) The resolution must keep its rule #6 cache gate in-statement.
foreach ($ungatedResolve as $rhs) {
    $failures[] = sprintf(
        'api.php resolves $currentUser = %s; WITHOUT a $_shouldCachePage/$_cacheablePages gate — '
        . 'an ungated getAuthenticatedUser() personalises shared-cache fragments (rule #6). '
        . 'Keep the `$_shouldCachePage ? null : getAuthenticatedUser()` form.',
        $rhs
    );
}

if ($failures) {
    fwrite(STDERR, "FAIL: fragment viewer-variable contract broken (#1710):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  $f\n"); }
    fwrite(STDERR, "\nFix: in api.php, resolve the viewer once before `switch (\$page)` with\n");
    fwrite(STDERR, "  \$currentUser = \$_shouldCachePage ? null : getAuthenticatedUser();\n");
    fwrite(STDERR, "so every non-cacheable fragment can read \$currentUser, and no cacheable one\n");
    fwrite(STDERR, "is personalised (rule #6). See the fragment doc-block in settings.php.\n");
    exit(1);
}

printf(
    "PASS: viewer-variable contract holds — api.php provides \$currentUser%s, "
    . "%d fragment(s) read it (%s), %d fragment(s) scanned.\n",
    $providesCurrentUser ? ' (cache-gated)' : '',
    count($consumers),
    $consumers ? implode(', ', array_keys($consumers)) : 'none',
    $scanned
);
exit(0);
