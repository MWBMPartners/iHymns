<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 1" consumer read endpoints (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.1/§9 asked for four new
 * `?action=` read endpoints in `api.php` — `tune` (C3), `publisher_detail`
 * (C4), `org_venues` (C2), and `org_ccli_report` (C5). This guard checks,
 * from the real dispatched source (not a hand-typed belief), that all four
 * genuinely exist as `$action`-switch cases, are documented in
 * `api-docs.yaml`, and — the part a mere "does the endpoint respond"
 * smoke test cannot see — that each one DELEGATES to the shared core its
 * sibling page already uses rather than forking a second copy of the read
 * (rule #22): `tune` and `publisher_detail` must call
 * `tuneResolveDisplayData()` / `publisherResolveDisplayData()`
 * (`includes/tune_helpers.php` / `includes/publisher_helpers.php`) rather
 * than re-embed the resolution ladder's own SQL; `org_venues` must call
 * `includes/venue_admin.php`'s three read functions rather than re-embed
 * `tblOrgVenues`/`tblOrgServiceSchedules` SELECTs; `org_ccli_report` must
 * call the ORG-SCOPED `ccliReportOrgRows()` (never the all-orgs
 * `ccliReportSystemRows()`, and never `userCanActOnOrg()`'s
 * admin/global_admin bypass) — mirroring `manage/my-ccli-report.php`'s own
 * doc-blocked isolation contract exactly.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * "Dispatchable" is asked of the REAL `$action` switch via the shared
 * `tests/php/lib/dispatch_parser.php` tokeniser (the same library
 * `test-openapi-actions-exist.php` and `test-orphan-inventory.php` use —
 * rule #22/#35, not a third copy of the switch-walker) rather than a
 * `grep "case '...'"`, for the exact reason that file's own header names:
 * `case 'tune':` also exists in the UNRELATED `$page` switch (the HTML
 * fragment route), so a text grep cannot tell "is a real `$action` case"
 * from "shares a name with a `$page` case by design". Each case's BODY is
 * then isolated by slicing the token stream between this case's label and
 * the next case's label in switch order — so a delegation check against
 * `org_venues`'s body cannot accidentally be satisfied by something that
 * only appears in `org_ccli_report`'s body (or vice versa).
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyContains()`/
 * `caseBodyFor()` are proven, against tiny in-memory token-shaped fixtures
 * assembled with PHP's own `token_get_all()`, to both find a marker that
 * is there AND fail to find one that is not, before the real assertions
 * below are trusted.
 *
 *   php tests/php/test-api-coverage-batch1.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.1/§9   the plan this implements
 * @see appWeb/public_html/api.php                     tune / publisher_detail / org_venues / org_ccli_report cases
 * @see appWeb/public_html/includes/tune_helpers.php    tuneResolveDisplayData()
 * @see appWeb/public_html/includes/publisher_helpers.php publisherResolveDisplayData()
 * @see appWeb/public_html/includes/venue_admin.php     venueAdmin*() read core
 * @see appWeb/public_html/includes/ccli_report.php     ccliReportOrgRows()/ccliReportResolveOrgScope()
 * @see tests/php/test-openapi-actions-exist.php         the sibling guard this reuses the parser library from
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

/**
 * Reconstruct the raw source text spanned by two token indices [start, end)
 * from an already-tokenised file (token_get_all() shape: array tokens carry
 * their text at [1]; single-char tokens are the text itself).
 */
function tokSpanText(array $toks, int $start, int $end): string
{
    $buf = '';
    $n = min($end, count($toks));
    for ($k = $start; $k < $n; $k++) {
        $t = $toks[$k];
        $buf .= is_array($t) ? $t[1] : $t;
    }
    return $buf;
}

/**
 * The source text of ONE `case '$name':` body inside the `$switchVar`
 * switch of `$file` — from this case's own label up to (not including) the
 * NEXT case label in switch order, or end-of-file for the last case. This
 * is what makes a "must / must NOT contain X" check safe: the slice cannot
 * bleed into an unrelated case's body except for a trailing comment
 * immediately preceding the NEXT case label (harmless for the presence/
 * absence checks this file runs — none of them are sensitive to a
 * doc-comment naming a DIFFERENT function).
 *
 * @return string|null null when no such case label exists in this switch.
 */
function caseBodyFor(string $file, string $switchVar, string $name): ?string
{
    $toks  = dispatchParserTokens($file);
    $cases = dispatchParserCaseTokens($file, $switchVar);
    foreach ($cases as $i => $c) {
        if ($c['name'] !== $name) { continue; }
        $start = $c['index'];
        $end   = isset($cases[$i + 1]) ? $cases[$i + 1]['index'] : count($toks);
        return tokSpanText($toks, $start, $end);
    }
    return null;
}

/** True when $needle is a literal substring of $body (or $body is null -> false). */
function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos($body, $needle) !== false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the two core functions above can
 * both find a marker that is there and fail to find one that is not,
 * against small real-tokeniser fixtures (never a hand-simulated token
 * array — token_get_all() itself produces the fixture tokens, so the
 * function under test sees the exact same shapes production code does).
 * ========================================================================= */

$mutationFailures = [];

$fixtureSrc = <<<'PHP'
<?php
switch ($action) {
    case 'alpha':
        doAlphaThing();
        break;
    case 'beta':
        doBetaThing();
        alsoBetaHelper();
        break;
    case 'gamma':
        doGammaThing();
        break;
}
PHP;
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_');
file_put_contents($fixtureFile, $fixtureSrc);

$betaBody = caseBodyFor($fixtureFile, '$action', 'beta');
if (!caseBodyContains($betaBody, 'doBetaThing(') || !caseBodyContains($betaBody, 'alsoBetaHelper(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-HIGH self-test: markers genuinely present in the beta case body were not found';
}
if (caseBodyContains($betaBody, 'doAlphaThing(') || caseBodyContains($betaBody, 'doGammaThing(')) {
    $mutationFailures[] = 'caseBodyFor()/caseBodyContains() FAILS-LOW self-test: a NEIGHBOURING case\'s marker was wrongly found inside the beta case body — the slice is bleeding across case boundaries';
}
if (caseBodyFor($fixtureFile, '$action', 'does-not-exist') !== null) {
    $mutationFailures[] = 'caseBodyFor() FAILS-LOW self-test: a non-existent case name returned a body instead of null';
}
unlink($fixtureFile);

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 1 (#1969): tune / publisher_detail / org_venues / org_ccli_report\n\n";

/* ---- A. Dispatchable: the real $action switch carries all four, exactly
   once each — tree-derived from the actual dispatcher, not a belief. ---- */
$actionCases = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

$batch1 = ['tune', 'publisher_detail', 'org_venues', 'org_ccli_report'];
foreach ($batch1 as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* Positive control mirroring test-openapi-actions-exist.php's own: 'tune'
   ALSO exists as a $page case (the pre-existing HTML fragment route) BY
   DESIGN (naming/shape parallels `work`) — proving this guard's parser
   call is asking the RIGHT switch, not merely finding the string anywhere
   in the file. */
$pageCases = dispatchParserCasesForSwitch($api, '$page');
ok("control: 'tune' is ALSO a \$page case (proving \$action-scoped parsing, not a bare grep)",
    in_array('tune', $pageCases, true));

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch1 as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Delegates to the shared core — never forks a second copy of the
   read (rule #22). ---- */
$tuneBody = caseBodyFor($api, '$action', 'tune');
ok('tune delegates to tuneResolveDisplayData( (includes/tune_helpers.php)',
    caseBodyContains($tuneBody, 'tuneResolveDisplayData('));
ok('tune does NOT re-embed a raw "FROM tblTunes" SELECT (would be a forked read)',
    !caseBodyContains($tuneBody, 'FROM tblTunes'));
ok('tune does NOT re-embed the heuristic-fallback query "DISTINCT TuneName" (would be a forked read)',
    !caseBodyContains($tuneBody, 'DISTINCT TuneName'));

$pubBody = caseBodyFor($api, '$action', 'publisher_detail');
ok('publisher_detail delegates to publisherResolveDisplayData( (includes/publisher_helpers.php)',
    caseBodyContains($pubBody, 'publisherResolveDisplayData('));
ok('publisher_detail does NOT re-embed a raw "FROM tblPublishers" SELECT (would be a forked read)',
    !caseBodyContains($pubBody, 'FROM tblPublishers'));

$venuesBody = caseBodyFor($api, '$action', 'org_venues');
ok('org_venues delegates to venueAdminTablesExist( (includes/venue_admin.php)',
    caseBodyContains($venuesBody, 'venueAdminTablesExist('));
ok('org_venues delegates to venueAdminListForOrg( (includes/venue_admin.php)',
    caseBodyContains($venuesBody, 'venueAdminListForOrg('));
ok('org_venues delegates to venueAdminSchedulesForVenue( (includes/venue_admin.php)',
    caseBodyContains($venuesBody, 'venueAdminSchedulesForVenue('));
ok('org_venues does NOT re-embed a raw "FROM tblOrgVenues" SELECT (would be a forked read)',
    !caseBodyContains($venuesBody, 'FROM tblOrgVenues'));
ok('org_venues does NOT re-embed a raw "FROM tblOrgServiceSchedules" SELECT (would be a forked read)',
    !caseBodyContains($venuesBody, 'FROM tblOrgServiceSchedules'));
/* Gate: same membership lookup service_session_start uses, with the SAME
   global_admin/admin allowance manage/venues.php's own manage_organisations
   gate has (§4.1 C2's stated gating). */
ok('org_venues authenticates via getAuthenticatedUser(',
    caseBodyContains($venuesBody, 'getAuthenticatedUser('));
ok('org_venues resolves org-admin scope via userIsOrgAdminOf(',
    caseBodyContains($venuesBody, 'userIsOrgAdminOf('));

$ccliBody = caseBodyFor($api, '$action', 'org_ccli_report');
ok('org_ccli_report delegates to the ORG-SCOPED ccliReportOrgRows( (includes/ccli_report.php)',
    caseBodyContains($ccliBody, 'ccliReportOrgRows('));
ok('org_ccli_report delegates to ccliReportResolveOrgScope( (includes/ccli_report.php)',
    caseBodyContains($ccliBody, 'ccliReportResolveOrgScope('));
ok('org_ccli_report delegates to ccliReportWindow( (includes/ccli_report.php)',
    caseBodyContains($ccliBody, 'ccliReportWindow('));
/* ⚠ THE isolation contract (mirrors manage/my-ccli-report.php's own
   doc-block, and test-ccli-report-org-scope.php's A1.2/A2.1): NEVER the
   all-orgs system query, and NEVER userCanActOnOrg()'s admin/global_admin
   bypass — scope is derived SOLELY from userIsOrgAdminOf(). */
ok('org_ccli_report does NOT reference the all-orgs ccliReportSystemRows( (would leak every org\'s usage)',
    !caseBodyContains($ccliBody, 'ccliReportSystemRows('));
ok('org_ccli_report does NOT call userCanActOnOrg( (that helper\'s admin/global_admin bypass would defeat the org-isolation contract)',
    !caseBodyContains($ccliBody, 'userCanActOnOrg('));
ok('org_ccli_report resolves scope via userIsOrgAdminOf( (the ONLY source of scope, per manage/my-ccli-report.php\'s own contract)',
    caseBodyContains($ccliBody, 'userIsOrgAdminOf('));
ok('org_ccli_report gates on the view_org_ccli_report entitlement',
    caseBodyContains($ccliBody, "userHasEntitlement('view_org_ccli_report'"));

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($mutationFailures) {
        fwrite(STDERR, "\nFAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    echo "\n{$passed} passed, {$failed} failed";
    if ($mutationFailures) { echo ' (+ ' . count($mutationFailures) . ' mutation self-test failure(s))'; }
    echo "\n";
    exit(1);
}

echo "\n{$passed} passed, 0 failed. All 4 API-coverage batch-1 endpoints are dispatchable, documented, and delegate to their shared cores with no forked reads.\n";
exit(0);
