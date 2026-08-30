<?php

declare(strict_types=1);

/**
 * iHymns — Database Setup dashboard: ?action= CSRF guard standing test (security
 * audit finding L-1, 2026-08-30)
 * ============================================================================
 *
 * ELI5
 * ----
 * `/manage/setup-database.php`'s buttons (Install, Apply all migrations,
 * Backup, Restore, Drop legacy tables, Reset OPcache, the lyrics-cutover
 * gate, every per-migration card) are all plain `<a href="?action=…">`
 * links — a GET request. Before this fix, NOTHING checked that the request
 * genuinely came from this page's own UI; only the session cookie's
 * `SameSite=Strict` attribute stood between a forged cross-site link and a
 * real backup/migration/restore. This file is the standing proof that (a)
 * the server-side gate exists, runs BEFORE every dispatch path, and excludes
 * only the one genuinely read-only action, and (b) every link/form on the
 * page that needs a token to keep working with JavaScript OFF actually
 * carries one.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * -------------------------------------
 * Every structural assertion below is proven able to fail by re-running it
 * against a MUTATED COPY of the real source (a temp file, string-replaced
 * from the real content — NEVER the tracked source itself), confirming the
 * check goes red, then discarding the temp file. This runs on every
 * invocation, so the guard stays provably breakable forever, mirroring
 * tests/php/test-gating-wizard.php's own precedent.
 *
 * @see appWeb/public_html/manage/setup-database.php   the page this guards
 * @see appWeb/public_html/manage/includes/auth.php    validateCsrfRequest()
 *
 *   php tests/php/test-setup-database-csrf.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

$repo  = dirname(__DIR__, 2);
$file  = $repo . '/appWeb/public_html/manage/setup-database.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/** Block-comment-blank a source string, preserving line count (same trick
 *  tests/php/test-deploy-paths.php uses) — lets this file's OWN doc-block
 *  describe the `?action=backup` pattern in prose without the scanner
 *  mistaking that prose for a real, unguarded link. */
function sdcStripComments(string $src): string
{
    return (string)preg_replace_callback(
        '#/\*.*?\*/#s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    );
}

/**
 * Every `?action=…` occurrence that opens a URL STRING this page itself
 * builds (a quote character sits immediately before the `?`) — this is
 * what excludes the unrelated `/api?action=admin_refresh_iana_cldr` fetch()
 * call elsewhere in the file (its `?` is preceded by `i`, not a quote), and
 * it never depends on a hand-typed list of "the links on this page" (a new
 * card added later is covered automatically the same way).
 *
 * For each, checks whether the action carries a CSRF token: either the
 * literal substring `csrf_token` sits in a short (200-char) window right
 * after the match, OR a PHP variable referenced in that window is ITSELF,
 * ANYWHERE in the file, assigned a string containing `csrf_token=` (the
 * `$_paCsrf`/`$csrfQs`/`$_vcCsrf`/`$_dropLegacyCsrf` indirection this page's
 * hrefs actually use). `deploy-forensics` — the one action whose own card
 * copy says "writes nothing — no reset, no DB mutation" — is exempt.
 *
 * @return array{line:int,slug:string}[] every action link missing a token.
 */
function sdcActionLinksMissingCsrf(string $strippedSrc): array
{
    $issues = [];
    if (preg_match_all('/(["\'])\?action=([a-zA-Z0-9_-]*)/', $strippedSrc, $m, PREG_OFFSET_CAPTURE) === 0) {
        return $issues;
    }
    foreach ($m[0] as $i => [$full, $offset]) {
        $slug = $m[2][$i][0];
        if ($slug === 'deploy-forensics') {
            continue; /* the one genuinely read-only action */
        }
        $tail = substr($strippedSrc, $offset + strlen($full), 200);
        $covered = str_contains($tail, 'csrf_token');
        if (!$covered && preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $tail, $vm)) {
            foreach (array_unique($vm[1]) as $varName) {
                if (preg_match('/\$' . preg_quote($varName, '/') . '\s*=\s*[\'"][^\'"]*csrf_token=/', $strippedSrc)) {
                    $covered = true;
                    break;
                }
            }
        }
        if (!$covered) {
            $issues[] = [
                'line' => substr_count(substr($strippedSrc, 0, $offset), "\n") + 1,
                'slug' => $slug !== '' ? $slug : '(dynamic)',
            ];
        }
    }
    return $issues;
}

/**
 * Every `<form … method="get" …>…</form>` span whose OWN body has no
 * `name="csrf_token"` hidden field — the one shape sdcActionLinksMissingCsrf()
 * cannot see (a GET form's action comes from a hidden `name="action"`
 * field, not a literal `?action=` in its own opening tag).
 *
 * @return int[] 1-based line numbers of every GET form missing the field.
 */
function sdcGetFormsMissingCsrf(string $strippedSrc): array
{
    $issues = [];
    if (preg_match_all('/<form\s[^>]*\bmethod="get"[^>]*>/', $strippedSrc, $m, PREG_OFFSET_CAPTURE) === 0) {
        return $issues;
    }
    foreach ($m[0] as [$tag, $offset]) {
        $close = strpos($strippedSrc, '</form>', $offset);
        $span = $close !== false
            ? substr($strippedSrc, $offset, $close - $offset)
            : substr($strippedSrc, $offset, 2000);
        if (!preg_match('/name="csrf_token"/', $span)) {
            $issues[] = substr_count(substr($strippedSrc, 0, $offset), "\n") + 1;
        }
    }
    return $issues;
}

/** Write $mutatedSrc to a fresh temp .php file, run $fn($tmpPath), delete
 *  it. Never touches a tracked source file (mirrors test-gating-wizard.php). */
function sdcWithMutatedFile(string $mutatedSrc, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_sdc_mut_') . '.php';
    file_put_contents($tmp, $mutatedSrc);
    try {
        return $fn($tmp);
    } finally {
        @unlink($tmp);
    }
}

echo "\nDatabase Setup dashboard — ?action= CSRF guard standing test (L-1)\n\n";

$src = (string)file_get_contents($file);
if ($src === '') {
    fwrite(STDERR, "FAIL: could not read $file\n");
    exit(1);
}
$stripped = sdcStripComments($src);

/* =========================================================================
 * (a) The CSRF gate exists, calls validateCsrfRequest(), and excludes
 * exactly `deploy-forensics`.
 *
 * Anchored on the REAL CODE condition, never the prose heading above it —
 * "CSRF GUARD" is inside a /* *​/ doc-block, which sdcStripComments()
 * deliberately blanks out (so the guard's OWN doc-block can describe
 * "?action=backup" in prose without tripping the link census in part (c)).
 * ========================================================================= */
$guardCodeAnchor = "if (\$action !== '' && \$action !== 'deploy-forensics') {";
$guardMarkerPos  = strpos($stripped, $guardCodeAnchor);
ok('the CSRF GUARD block exists', $guardMarkerPos !== false);

$csrfCallPos = $guardMarkerPos !== false
    ? strpos($stripped, 'validateCsrfRequest(', $guardMarkerPos)
    : false;
ok('the CSRF GUARD block calls validateCsrfRequest(',
    $csrfCallPos !== false && $csrfCallPos < $guardMarkerPos + 2000);

/* MUTATION: rename the validateCsrfRequest( call in a mutated copy -> the
 * "calls validateCsrfRequest(" assertion's own INPUT must go red. */
$mutatedNoCall = str_replace(
    "if (!validateCsrfRequest(\$csrfSuppliedForAction)) {",
    "if (false) { // MUTATED: validateCsrfRequestREMOVED(\$csrfSuppliedForAction)",
    $src
);
ok('MUTATION setup sanity (a): the validateCsrfRequest() removal actually matched real source',
    $mutatedNoCall !== $src);
sdcWithMutatedFile($mutatedNoCall, function (string $tmp) use ($guardCodeAnchor) {
    $mutSrc = sdcStripComments((string)file_get_contents($tmp));
    $marker = strpos($mutSrc, $guardCodeAnchor);
    $call   = $marker !== false ? strpos($mutSrc, 'validateCsrfRequest(', $marker) : false;
    ok('MUTATION PROOF (a): removing the validateCsrfRequest( call from the guard is detected',
        $call === false || $call >= $marker + 2000);
});

/* =========================================================================
 * (b) ONE CHOKE POINT — the guard sits BEFORE the entitlement gate AND
 * before the dispatch entry (define('IHYMNS_SETUP_DASHBOARD', ...)), so it
 * cannot be bypassed by any of the three downstream dispatch paths
 * (format=text fast path, apply-all-migrations bulk runner, HTML path).
 * ========================================================================= */
/* Real-code anchor again, never the "PER-ACTION ENTITLEMENTS" prose heading
   (also inside a /* *​/ doc-block, also blanked by sdcStripComments()). */
$entitlementCodeAnchorLiteral = "if (\$action !== '' && !\$isInitialSetup) {";
$entitlementMarkerPos = strpos($stripped, $entitlementCodeAnchorLiteral);
$dispatchEntryPos     = strpos($stripped, "define('IHYMNS_SETUP_DASHBOARD', true);");

ok('the entitlement-gate marker is found (sanity)', $entitlementMarkerPos !== false);
ok('the dispatch-entry marker is found (sanity)', $dispatchEntryPos !== false);
ok('the CSRF guard sits BEFORE the entitlement gate',
    $guardMarkerPos !== false && $entitlementMarkerPos !== false && $guardMarkerPos < $entitlementMarkerPos);
ok('the CSRF guard sits BEFORE the dispatch entry (format=text / bulk / HTML paths)',
    $guardMarkerPos !== false && $dispatchEntryPos !== false && $guardMarkerPos < $dispatchEntryPos);

/* MUTATION: genuinely SWAP the two condition lines' text in a mutated copy
 * (a placeholder step avoids the second str_replace() re-matching the
 * first's own output) — so the guard's anchor text now sits where the
 * entitlement gate's used to (later in the file) and vice versa. Simulates
 * "the gate got reordered past the thing it's supposed to gate" in exactly
 * the shape this assertion actually measures (relative strpos() position of
 * the two anchors) -> the ordering assertions must flip to false. */
$mutatedReordered = str_replace($guardCodeAnchor, '__SDC_GUARD_ANCHOR_PLACEHOLDER__', $src);
$mutatedReordered = str_replace($entitlementCodeAnchorLiteral, $guardCodeAnchor, $mutatedReordered);
$mutatedReordered = str_replace('__SDC_GUARD_ANCHOR_PLACEHOLDER__', $entitlementCodeAnchorLiteral, $mutatedReordered);
ok('MUTATION setup sanity (b): the anchor-swap actually matched real source',
    $mutatedReordered !== $src);
sdcWithMutatedFile($mutatedReordered, function (string $tmp) use ($guardCodeAnchor, $entitlementCodeAnchorLiteral) {
    $mutSrc = sdcStripComments((string)file_get_contents($tmp));
    $mutGuardPos       = strpos($mutSrc, $guardCodeAnchor);
    $mutEntitlementPos = strpos($mutSrc, $entitlementCodeAnchorLiteral);
    ok('MUTATION PROOF (b): swapping the guard past the entitlement gate flips the "before" assertion',
        $mutGuardPos !== false && $mutEntitlementPos !== false && $mutGuardPos > $mutEntitlementPos);
});

/* =========================================================================
 * (c) Every ?action= link this page builds carries a CSRF token, except
 * the one genuinely read-only action (deploy-forensics).
 * ========================================================================= */
$missingLinks = sdcActionLinksMissingCsrf($stripped);
ok('every non-exempt ?action= link/href on this page carries a csrf_token (missing: '
    . ($missingLinks === [] ? 'none' : implode(', ', array_map(
        static fn(array $x): string => "{$x['slug']}:{$x['line']}",
        $missingLinks
    ))) . ')',
    $missingLinks === []);

ok('deploy-forensics itself is correctly treated as exempt (not flagged)',
    !in_array('deploy-forensics', array_column($missingLinks, 'slug'), true));

/* MUTATION: strip the csrf_token query param AND its backing variable from
 * the "install" link in a mutated copy -> the census must catch it. */
$mutatedNoInstallToken = str_replace(
    '<a href="?action=install&amp;csrf_token=<?= urlencode(csrfToken()) ?>"',
    '<a href="?action=install"',
    $src
);
ok('MUTATION setup sanity (c1): the install-link token removal actually matched real source',
    $mutatedNoInstallToken !== $src);
$missingAfterMutation = sdcActionLinksMissingCsrf(sdcStripComments($mutatedNoInstallToken));
ok('MUTATION PROOF (c1): stripping the token from the "install" link is detected',
    in_array('install', array_column($missingAfterMutation, 'slug'), true));

/* MUTATION: break the SYMBOLIC trace by renaming the $_paCsrf assignment
 * (but not its use sites) in a mutated copy -> the dry-run/run links that
 * depend on it must now be flagged, proving the check isn't fooled by the
 * variable NAME alone without a real assignment backing it. */
$mutatedBrokenTrace = str_replace(
    "\$_paCsrf    = '&amp;csrf_token=' . urlencode(csrfToken());",
    "\$_paCsrf_RENAMED_BY_MUTATION = '&amp;csrf_token=' . urlencode(csrfToken());",
    $src
);
ok('MUTATION setup sanity (c2): the $_paCsrf assignment rename actually matched real source',
    $mutatedBrokenTrace !== $src);
$missingAfterTraceBreak = sdcActionLinksMissingCsrf(sdcStripComments($mutatedBrokenTrace));
ok('MUTATION PROOF (c2): breaking the $_paCsrf symbolic trace is detected (the pending-migration links use it)',
    array_filter($missingAfterTraceBreak, static fn(array $x): bool => $x['line'] >= 2820 && $x['line'] <= 2850) !== []);

/* =========================================================================
 * (d) The one GET <form> on this page (Restore's Preview/Pre-flight/Restore
 * buttons) carries a hidden csrf_token field.
 * ========================================================================= */
$missingForms = sdcGetFormsMissingCsrf($stripped);
ok('the page\'s GET <form> (Restore) carries a hidden csrf_token field (missing at line: '
    . ($missingForms === [] ? 'none' : implode(', ', $missingForms)) . ')',
    $missingForms === []);

/* MUTATION: strip the hidden csrf_token field from the Restore form in a
 * mutated copy -> the census must catch it. */
$mutatedNoFormToken = str_replace(
    '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, \'UTF-8\') ?>">' . "\n"
        . '                                <select name="file" id="backup-restore-file"',
    '<select name="file" id="backup-restore-file"',
    $src
);
ok('MUTATION setup sanity (d): the Restore-form token removal actually matched real source',
    $mutatedNoFormToken !== $src);
$missingFormsAfterMutation = sdcGetFormsMissingCsrf(sdcStripComments($mutatedNoFormToken));
ok('MUTATION PROOF (d): stripping the Restore form\'s hidden csrf_token field is detected',
    $missingFormsAfterMutation !== []);

/* =========================================================================
 * REPORT
 * ========================================================================= */
echo "\n{$passed} passed, {$failed} failed";
if ($failed > 0) {
    echo "\n";
    exit(1);
}
echo "\n\nThe database-setup dashboard's ?action= dispatch is CSRF-gated: the guard runs "
   . "once, before every downstream path, excludes only the genuinely read-only "
   . "deploy-forensics action, and every link/form a no-JS curator would click still "
   . "carries a valid token.\n";
exit(0);
