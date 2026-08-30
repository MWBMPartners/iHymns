<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 3" org-admin self-service endpoints (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.2/§9 asked for nine new
 * `?action=` endpoints in `api.php` — the org-admin self-service family
 * (O1 `org_admin_settings_update`; O2 `org_admin_logo_upload`/`_delete`/
 * `_set_active`; O3 `org_admin_brand_update`; O4
 * `org_admin_venue_save`/`_delete` + `org_admin_schedule_save`/`_delete`).
 * This guard checks, from the real dispatched source (not a hand-typed
 * belief), that all nine genuinely exist as `$action`-switch cases, are
 * documented in `api-docs.yaml`, gate on the SAME org-scope check
 * (`userCanActOnOrg()`), and — the part a mere "does the endpoint
 * respond" smoke test cannot see — DELEGATE to the shared core their
 * sibling `manage/*.php` page already uses rather than forking a second
 * copy of the validation/write (rule #22): the settings action calls
 * `includes/service_mode.php`/`includes/setlist_collab.php`'s existing
 * constants + dormancy gates; the logo actions call
 * `includes/org_logo_admin.php`; the brand action calls
 * `includes/organisation_validation.php`'s ONE hex normaliser; and the
 * four venue/schedule actions call the NEWLY-EXTRACTED write core in
 * `includes/venue_admin.php` — which `manage/venues.php`'s own POST
 * handlers were re-pointed at in the SAME commit, so this guard also
 * checks THAT page for the extraction (never a raw SQL statement left
 * behind where a core-function call should be).
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch1.php`: `api.php` has TWO
 * switches (`$page` and `$action`), so a bare `grep "case '...'"` cannot
 * tell which one owns a label. Each case's BODY is isolated by slicing the
 * token stream between this case's label and the next case's label in
 * switch order (`tests/php/lib/dispatch_parser.php`), so a delegation
 * check against one action's body cannot accidentally be satisfied by
 * something that only appears in a NEIGHBOURING action's body.
 * `manage/venues.php` gets the SAME treatment for its own `$action`
 * switch (over `$_POST['action']`), to prove the write extraction
 * actually re-pointed the page rather than merely adding a parallel copy
 * in `includes/venue_admin.php`.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyContains()`/
 * `caseBodyFor()` are proven, against tiny in-memory token-shaped fixtures
 * assembled with PHP's own `token_get_all()`, to both find a marker that
 * is there AND fail to find one that is not, before the real assertions
 * below are trusted. (Duplicated here rather than imported — the sibling
 * `test-api-coverage-batch1.php` defines the SAME two tiny helpers
 * locally rather than growing the shared `dispatch_parser.php` library;
 * this file follows that same precedent rather than introducing a THIRD
 * shape.)
 *
 *   php tests/php/test-api-coverage-batch3.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.2/§9   the plan this implements
 * @see appWeb/public_html/api.php                     the nine org_admin_* cases
 * @see appWeb/public_html/includes/venue_admin.php     the extracted write core (O4)
 * @see appWeb/public_html/includes/org_logo_admin.php  the logo write core (O2)
 * @see appWeb/public_html/includes/organisation_validation.php  userCanActOnOrg() + the brand-colour normaliser (O3)
 * @see appWeb/public_html/manage/venues.php            the re-pointed page (O4)
 * @see tests/php/test-api-coverage-batch1.php           the sibling guard this mirrors
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo    = dirname(__DIR__, 2);
$api     = $repo . '/appWeb/public_html/api.php';
$venues  = $repo . '/appWeb/public_html/manage/venues.php';
$venueAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/venue_admin.php');

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
 * NEXT case label in switch order, or end-of-file for the last case.
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
 * against small real-tokeniser fixtures.
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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b3_');
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

echo "\nAPI-coverage batch 3 (#1969): org-admin self-service (O1-O4)\n\n";

$batch3 = [
    'org_admin_settings_update',
    'org_admin_logo_upload',
    'org_admin_logo_delete',
    'org_admin_logo_set_active',
    'org_admin_brand_update',
    'org_admin_venue_save',
    'org_admin_venue_delete',
    'org_admin_schedule_save',
    'org_admin_schedule_delete',
];

/* ---- A. Dispatchable: the real $action switch carries all nine, exactly
   once each — tree-derived from the actual dispatcher, not a belief. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch3 as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch3 as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via the SAME row-level org-scope helper
   (includes/organisation_validation.php's userCanActOnOrg() — system
   admin/global_admin, OR an admin/owner row on tblOrganisationMembers for
   the target org). None of the nine may re-implement this check inline. ---- */
foreach ($batch3 as $name) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userCanActOnOrg( (never a re-implemented inline check)",
        caseBodyContains($body, 'userCanActOnOrg('));
}

/* ---- D. O1 org_admin_settings_update: delegates to the SAME constants +
   dormancy gates manage/my-organisations.php's idle_timeout_update /
   setlist_edit_audience_update handlers use — never a re-typed clamp or
   allow-list. ---- */
$settingsBody = caseBodyFor($api, '$action', 'org_admin_settings_update');
ok('org_admin_settings_update delegates to serviceMode_orgIdleColumnsExist( (includes/service_mode.php)',
    caseBodyContains($settingsBody, 'serviceMode_orgIdleColumnsExist('));
ok('org_admin_settings_update reuses the SAME LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES constant (never a re-typed clamp)',
    caseBodyContains($settingsBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MIN_MINUTES'));
ok('org_admin_settings_update reuses the SAME LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES constant (never a re-typed clamp)',
    caseBodyContains($settingsBody, 'LIVE_FOLLOW_IDLE_TIMEOUT_MAX_MINUTES'));
ok('org_admin_settings_update delegates to setlistOrgAudienceColumnsExist( (includes/setlist_collab.php)',
    caseBodyContains($settingsBody, 'setlistOrgAudienceColumnsExist('));

/* ---- E. O2 logo actions: delegate to includes/org_logo_admin.php /
   org_logo_helpers.php — never a raw write against tblOrganisationLogos
   (that would be a forked write path, rule #42). ---- */
$logoUploadBody = caseBodyFor($api, '$action', 'org_admin_logo_upload');
ok('org_admin_logo_upload delegates to orgLogoValidateAndStage( (the ONE path into ihymnsSanitizeSvg(), rule #42)',
    caseBodyContains($logoUploadBody, 'orgLogoValidateAndStage('));
ok('org_admin_logo_upload delegates to orgLogoUpsert(',
    caseBodyContains($logoUploadBody, 'orgLogoUpsert('));
ok('org_admin_logo_upload validates kind against ihymnsOrgLogoKindKeys( (the ONE registry)',
    caseBodyContains($logoUploadBody, 'ihymnsOrgLogoKindKeys('));
ok('org_admin_logo_upload does NOT re-embed a raw "INSERT INTO tblOrganisationLogos" (would be a forked write)',
    !caseBodyContains($logoUploadBody, 'INSERT INTO tblOrganisationLogos'));

$logoDeleteBody = caseBodyFor($api, '$action', 'org_admin_logo_delete');
ok('org_admin_logo_delete delegates to orgLogoDeleteKindAll( (the default-variant cascade, #1840)',
    caseBodyContains($logoDeleteBody, 'orgLogoDeleteKindAll('));
ok('org_admin_logo_delete delegates to orgLogoDelete(',
    caseBodyContains($logoDeleteBody, 'orgLogoDelete('));
ok('org_admin_logo_delete does NOT re-embed a raw "DELETE FROM tblOrganisationLogos" (would be a forked write)',
    !caseBodyContains($logoDeleteBody, 'DELETE FROM tblOrganisationLogos'));

$logoActiveBody = caseBodyFor($api, '$action', 'org_admin_logo_set_active');
ok('org_admin_logo_set_active delegates to orgLogoSetActiveKind( (the kind-level toggle, #1840)',
    caseBodyContains($logoActiveBody, 'orgLogoSetActiveKind('));
ok('org_admin_logo_set_active does NOT re-embed a raw "UPDATE tblOrganisationLogos" (would be a forked write)',
    !caseBodyContains($logoActiveBody, 'UPDATE tblOrganisationLogos'));

/* All three O2 actions echo the STORED state back via the shared private
   wire-shape helper — never each hand-rolling its own array_map. */
foreach (['org_admin_logo_upload', 'org_admin_logo_delete', 'org_admin_logo_set_active'] as $name) {
    $b = caseBodyFor($api, '$action', $name);
    ok("'{$name}' echoes the stored logo list via _apiOrgLogoWireList( (rule #35/#40)",
        caseBodyContains($b, '_apiOrgLogoWireList('));
}

/* ---- F. O3 org_admin_brand_update: delegates to the ONE hex normaliser
   (includes/organisation_validation.php) — never an inline hex parse. ---- */
$brandBody = caseBodyFor($api, '$action', 'org_admin_brand_update');
ok('org_admin_brand_update delegates to ihymnsOrgBrandColourNormalise( (the ONE allowlist, rule #42)',
    caseBodyContains($brandBody, 'ihymnsOrgBrandColourNormalise('));
ok('org_admin_brand_update delegates to orgSetBrandColour( (the ONE write path)',
    caseBodyContains($brandBody, 'orgSetBrandColour('));
ok('org_admin_brand_update does NOT inline its own hex-colour preg_match (would be a forked validator)',
    !caseBodyContains($brandBody, 'preg_match'));

/* ---- G. O4 venue/schedule actions: delegate to includes/venue_admin.php's
   extracted write core — never a raw INSERT/UPDATE/DELETE against
   tblOrgVenues/tblOrgServiceSchedules inside api.php itself (that would be
   a forked write path, rule #22). ---- */
$venueSaveBody = caseBodyFor($api, '$action', 'org_admin_venue_save');
ok('org_admin_venue_save delegates to venueAdminSaveVenue( (includes/venue_admin.php)',
    caseBodyContains($venueSaveBody, 'venueAdminSaveVenue('));
ok('org_admin_venue_save delegates to venueAdminGetVenue( (echoes the STORED row + gates on the row\'s CURRENT org)',
    caseBodyContains($venueSaveBody, 'venueAdminGetVenue('));
ok('org_admin_venue_save does NOT re-embed a raw "INSERT INTO tblOrgVenues" (would be a forked write)',
    !caseBodyContains($venueSaveBody, 'INSERT INTO tblOrgVenues'));
ok('org_admin_venue_save does NOT re-embed a raw "UPDATE tblOrgVenues" (would be a forked write)',
    !caseBodyContains($venueSaveBody, 'UPDATE tblOrgVenues'));

$venueDeleteBody = caseBodyFor($api, '$action', 'org_admin_venue_delete');
ok('org_admin_venue_delete delegates to venueAdminDeleteVenue(',
    caseBodyContains($venueDeleteBody, 'venueAdminDeleteVenue('));
ok('org_admin_venue_delete delegates to venueAdminGetVenue( (gates on the row\'s CURRENT org before deleting)',
    caseBodyContains($venueDeleteBody, 'venueAdminGetVenue('));
ok('org_admin_venue_delete does NOT re-embed a raw "DELETE FROM tblOrgVenues" (would be a forked write)',
    !caseBodyContains($venueDeleteBody, 'DELETE FROM tblOrgVenues'));

$scheduleSaveBody = caseBodyFor($api, '$action', 'org_admin_schedule_save');
ok('org_admin_schedule_save delegates to venueAdminSaveSchedule(',
    caseBodyContains($scheduleSaveBody, 'venueAdminSaveSchedule('));
ok('org_admin_schedule_save resolves its gate via venueAdminGetVenue( (org_id is DERIVED from the venue, never trusted from the caller)',
    caseBodyContains($scheduleSaveBody, 'venueAdminGetVenue('));
ok('org_admin_schedule_save does NOT trust a posted org_id ($body[\'org_id\']) — OrgId must be derived from the venue',
    !caseBodyContains($scheduleSaveBody, "\$body['org_id']"));
ok('org_admin_schedule_save does NOT re-embed a raw "INSERT INTO tblOrgServiceSchedules" (would be a forked write)',
    !caseBodyContains($scheduleSaveBody, 'INSERT INTO tblOrgServiceSchedules'));
ok('org_admin_schedule_save does NOT re-embed a raw "UPDATE tblOrgServiceSchedules" (would be a forked write)',
    !caseBodyContains($scheduleSaveBody, 'UPDATE tblOrgServiceSchedules'));

$scheduleDeleteBody = caseBodyFor($api, '$action', 'org_admin_schedule_delete');
ok('org_admin_schedule_delete delegates to venueAdminDeleteSchedule(',
    caseBodyContains($scheduleDeleteBody, 'venueAdminDeleteSchedule('));
ok('org_admin_schedule_delete delegates to venueAdminGetSchedule( (gates on the row\'s CURRENT org before deleting)',
    caseBodyContains($scheduleDeleteBody, 'venueAdminGetSchedule('));
ok('org_admin_schedule_delete does NOT re-embed a raw "DELETE FROM tblOrgServiceSchedules" (would be a forked write)',
    !caseBodyContains($scheduleDeleteBody, 'DELETE FROM tblOrgServiceSchedules'));

/* ---- H. The O4 write core actually EXISTS in includes/venue_admin.php
   (never assumed from the api.php call sites alone — a stray typo'd
   function name would otherwise only surface as a runtime fatal). ---- */
foreach (['venueAdminSaveVenue', 'venueAdminDeleteVenue', 'venueAdminSaveSchedule',
          'venueAdminDeleteSchedule', 'venueAdminGetVenue', 'venueAdminGetSchedule'] as $fn) {
    ok("includes/venue_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $venueAdminSrc));
}

/* ---- I. manage/venues.php's OWN POST handlers were re-pointed at the
   SAME core (O4's "extraction commit precedes the API commit and the page
   is re-pointed at the core in the same PR — no behaviour change" rule).
   Checked the SAME way — this page dispatches its own `$action` switch
   over `$_POST['action']`, so the identical case-body-isolation technique
   applies to it too. A raw SQL statement surviving here would mean the
   page kept its OLD inline write path alongside the new shared core —
   two copies, not one (rule #22). ---- */
foreach ([
    'venue_save'      => ['venueAdminSaveVenue(',     'INSERT INTO tblOrgVenues'],
    'venue_delete'    => ['venueAdminDeleteVenue(',   'DELETE FROM tblOrgVenues'],
    'schedule_save'   => ['venueAdminSaveSchedule(',  'INSERT INTO tblOrgServiceSchedules'],
    'schedule_delete' => ['venueAdminDeleteSchedule(', 'DELETE FROM tblOrgServiceSchedules'],
] as $pageAction => [$coreFn, $rawSql]) {
    $pageBody = caseBodyFor($venues, '$action', $pageAction);
    ok("manage/venues.php's '{$pageAction}' handler delegates to {$coreFn} (re-pointed at the shared core)",
        caseBodyContains($pageBody, $coreFn));
    ok("manage/venues.php's '{$pageAction}' handler no longer contains a raw \"{$rawSql}\" (the OLD inline write path was removed, not duplicated)",
        !caseBodyContains($pageBody, $rawSql));
}

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

echo "\n{$passed} passed, 0 failed. All 9 API-coverage batch-3 endpoints are dispatchable, documented, gate on userCanActOnOrg(), and delegate to their shared cores with no forked validation/write — and manage/venues.php was genuinely re-pointed at the same O4 core.\n";
exit(0);
