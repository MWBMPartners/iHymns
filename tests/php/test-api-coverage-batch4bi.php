<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 4b-i" tags/catalogues/songbook-series (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3/§9 asked for admin/curator
 * registry CRUD whose shared cores DID NOT yet exist — tags/themes (A3),
 * catalogues a.k.a. "Collections" (A4), and songbook series (A5) all lived
 * only as inline `manage/*.php` POST handlers. This guard checks, from the
 * real dispatched source (not a hand-typed belief), that the thirteen new
 * `admin_tag_*` / `admin_catalogue_*` / `admin_songbook_series_*` actions
 * genuinely exist as `$action`-switch cases in `api.php`, are documented in
 * `api-docs.yaml`, gate on the SAME entitlement their sibling `manage/*.php`
 * page gates on (checked from BOTH sides), and — the part a mere "does the
 * endpoint respond" smoke test cannot see — DELEGATE to the newly-extracted
 * shared core their sibling page ALSO now delegates to (rule #22), rather
 * than forking a second validation/write path:
 *
 *   - A3 tags:            includes/tag_admin.php            <-> manage/tags.php
 *   - A4 catalogues:       includes/catalogue_admin.php       <-> manage/catalogues.php
 *   - A5 songbook series:  includes/songbook_series_admin.php <-> manage/songbook-series.php
 *
 * Section H below checks the EXTRACTION side of the story specifically:
 * that each page's re-pointed case body no longer embeds the raw write SQL
 * that used to live there (the "genuinely re-pointed the page" obligation
 * the task set, not just "the API also works") — scoped PER CASE via the
 * same tokenising slicer used for `api.php`, so `marcxml_import` (out of
 * scope, deliberately left untouched and still raw-SQL) can never leak a
 * false pass into `create`/`add`/`update`/`delete`.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch4a.php`: `api.php` has TWO
 * switches (`$page` and `$action`), so a bare `grep "case '...'"` cannot
 * tell which one owns a label. Each case's BODY is isolated by slicing the
 * token stream between this case's label and the next case's label in
 * switch order (`tests/php/lib/dispatch_parser.php`), so a delegation check
 * against one action's body cannot accidentally be satisfied by something
 * that only appears in a NEIGHBOURING action's body. The SAME slicer is
 * reused against the three `manage/*.php` pages' own `$action` switches for
 * section H, for the identical reason — `manage/catalogues.php` in
 * particular has SIX cases (`add`/`marcxml_import`/`update`/`delete`/
 * `add_member`/`remove_member`) sitting next to each other, several
 * referencing the same table names.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyContains()`/
 * `caseBodyFor()` are proven, against tiny in-memory token-shaped fixtures
 * assembled with PHP's own `token_get_all()`, to both find a marker that is
 * there AND fail to find one that is not, before the real assertions below
 * are trusted. (Duplicated locally rather than imported — the sibling
 * `test-api-coverage-batch4a.php` defines the SAME two tiny helpers locally
 * rather than growing the shared `dispatch_parser.php` library; this file
 * follows that same precedent rather than introducing a fourth shape.)
 *
 *   php tests/php/test-api-coverage-batch4bi.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3/§9   the plan this implements
 * @see appWeb/public_html/api.php                          the thirteen new cases
 * @see appWeb/public_html/includes/tag_admin.php            tag write/read core (A3)
 * @see appWeb/public_html/includes/catalogue_admin.php      catalogue write/read core (A4)
 * @see appWeb/public_html/includes/songbook_series_admin.php songbook-series write/read core (A5)
 * @see appWeb/public_html/manage/tags.php               the page manage_tags-gates against
 * @see appWeb/public_html/manage/catalogues.php         the page manage_songbooks-gates against
 * @see appWeb/public_html/manage/songbook-series.php    the page manage_songbooks-gates against
 * @see tests/php/test-api-coverage-batch4a.php           the sibling guard this mirrors
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$tagAdminSrc      = (string)file_get_contents($repo . '/appWeb/public_html/includes/tag_admin.php');
$catalogueAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/catalogue_admin.php');
$seriesAdminSrc    = (string)file_get_contents($repo . '/appWeb/public_html/includes/songbook_series_admin.php');

$tagsPage      = $repo . '/appWeb/public_html/manage/tags.php';
$cataloguesPage = $repo . '/appWeb/public_html/manage/catalogues.php';
$seriesPage     = $repo . '/appWeb/public_html/manage/songbook-series.php';

$tagsPageSrc      = (string)file_get_contents($tagsPage);
$cataloguesPageSrc = (string)file_get_contents($cataloguesPage);
$seriesPageSrc     = (string)file_get_contents($seriesPage);

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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b4bi_');
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

echo "\nAPI-coverage batch 4b-i (#1969): tags (A3) / catalogues (A4) / songbook series (A5)\n\n";

$batch4bi = [
    'admin_tag_create',
    'admin_tag_update',
    'admin_tag_delete',
    'admin_tag_merge',
    'admin_tag_canonical_suggestions',
    'admin_catalogue_create',
    'admin_catalogue_update',
    'admin_catalogue_delete',
    'admin_catalogue_member_add',
    'admin_catalogue_member_remove',
    'admin_songbook_series_create',
    'admin_songbook_series_update',
    'admin_songbook_series_delete',
];

/* ---- A. Dispatchable: the real $action switch carries all thirteen,
   exactly once each — tree-derived from the actual dispatcher, not a
   belief. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch4bi as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch4bi as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page gates on — checked from
   BOTH sides (the API case body AND the page source itself), so a future
   drift in either file is caught, not just a hand-typed belief about what
   the page currently does. ---- */
$entitlementByAction = [
    'admin_tag_create'                 => 'manage_tags',
    'admin_tag_update'                 => 'manage_tags',
    'admin_tag_delete'                 => 'manage_tags',
    'admin_tag_merge'                  => 'manage_tags',
    'admin_tag_canonical_suggestions'  => 'manage_tags',
    'admin_catalogue_create'           => 'manage_songbooks',
    'admin_catalogue_update'           => 'manage_songbooks',
    'admin_catalogue_delete'           => 'manage_songbooks',
    'admin_catalogue_member_add'       => 'manage_songbooks',
    'admin_catalogue_member_remove'    => 'manage_songbooks',
    'admin_songbook_series_create'     => 'manage_songbooks',
    'admin_songbook_series_update'     => 'manage_songbooks',
    'admin_songbook_series_delete'     => 'manage_songbooks',
];
$pageSrcByEntitlement = [
    'manage_tags'       => $tagsPageSrc,
    'manage_songbooks'  => $cataloguesPageSrc . "\n" . $seriesPageSrc,
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
foreach ($pageSrcByEntitlement as $entKey => $src) {
    ok("the sibling manage/*.php page(s) for '{$entKey}' really do gate on userHasEntitlement('{$entKey}' (API gate matches the page's OWN gate, not a belief about it)",
        strpos($src, "userHasEntitlement('{$entKey}'") !== false);
}

/* ---- D. A3 tag actions: delegate to includes/tag_admin.php — never a
   forked validation/write path (rule #22). ---- */
$tagCreateBody = caseBodyFor($api, '$action', 'admin_tag_create');
ok('admin_tag_create delegates to tagAdminValidateFields(',
    caseBodyContains($tagCreateBody, 'tagAdminValidateFields('));
ok('admin_tag_create delegates to tagAdminCreate(',
    caseBodyContains($tagCreateBody, 'tagAdminCreate('));
ok('admin_tag_create does NOT re-embed a raw "INSERT INTO tblSongTags" (would be a forked write)',
    !caseBodyContains($tagCreateBody, 'INSERT INTO tblSongTags'));

$tagUpdateBody = caseBodyFor($api, '$action', 'admin_tag_update');
ok('admin_tag_update delegates to tagAdminValidateFields(',
    caseBodyContains($tagUpdateBody, 'tagAdminValidateFields('));
ok('admin_tag_update delegates to tagAdminFetch( (before-state / existence check)',
    caseBodyContains($tagUpdateBody, 'tagAdminFetch('));
ok('admin_tag_update delegates to tagAdminUpdate(',
    caseBodyContains($tagUpdateBody, 'tagAdminUpdate('));
ok('admin_tag_update does NOT re-embed a raw "UPDATE tblSongTags SET" (would be a forked write)',
    !caseBodyContains($tagUpdateBody, 'UPDATE tblSongTags SET'));

$tagDeleteBody = caseBodyFor($api, '$action', 'admin_tag_delete');
ok('admin_tag_delete delegates to tagAdminUsageCount( (the 409-requires-force gate)',
    caseBodyContains($tagDeleteBody, 'tagAdminUsageCount('));
ok('admin_tag_delete delegates to tagAdminDelete(',
    caseBodyContains($tagDeleteBody, 'tagAdminDelete('));
ok('admin_tag_delete does NOT re-embed a raw "DELETE FROM tblSongTags" (would be a forked write)',
    !caseBodyContains($tagDeleteBody, 'DELETE FROM tblSongTags'));

$tagMergeBody = caseBodyFor($api, '$action', 'admin_tag_merge');
ok('admin_tag_merge delegates to tagAdminFetchNamesByIds( (the pre-flight 404 existence check)',
    caseBodyContains($tagMergeBody, 'tagAdminFetchNamesByIds('));
ok('admin_tag_merge delegates to tagAdminMerge( (the transactional repoint + source delete)',
    caseBodyContains($tagMergeBody, 'tagAdminMerge('));
ok('admin_tag_merge does NOT re-embed a raw "UPDATE tblSongTagMap SET TagId" (would be a forked write)',
    !caseBodyContains($tagMergeBody, 'UPDATE tblSongTagMap SET TagId'));

$tagCanonBody = caseBodyFor($api, '$action', 'admin_tag_canonical_suggestions');
ok('admin_tag_canonical_suggestions delegates to tagAdminCanonicalSuggestions(',
    caseBodyContains($tagCanonBody, 'tagAdminCanonicalSuggestions('));
ok('admin_tag_canonical_suggestions does NOT call ihymns_sim_text( directly (must go through the ONE core, not re-forked scoring, rule #22)',
    !caseBodyContains($tagCanonBody, 'ihymns_sim_text('));

/* ---- E. A3 write/read core functions actually EXIST in
   includes/tag_admin.php (never assumed from the api.php call sites
   alone). ---- */
foreach ([
    'tagAdminNormaliseName', 'tagAdminSlugify', 'tagAdminThemeColumnsReady',
    'tagAdminValidateFields', 'tagAdminFetch', 'tagAdminCreate', 'tagAdminUpdate',
    'tagAdminUsageCount', 'tagAdminDelete', 'tagAdminFetchNamesByIds', 'tagAdminMerge',
    'tagAdminCanonicalSuggestions',
] as $fn) {
    ok("includes/tag_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $tagAdminSrc));
}

/* ---- F. A4 catalogue actions: delegate to includes/catalogue_admin.php —
   never a forked validation/write path (rule #22/#24). ---- */
$catCreateBody = caseBodyFor($api, '$action', 'admin_catalogue_create');
ok('admin_catalogue_create delegates to catalogueAdminValidateCreateFields(',
    caseBodyContains($catCreateBody, 'catalogueAdminValidateCreateFields('));
ok('admin_catalogue_create delegates to catalogueAdminValidatePublicationIds(',
    caseBodyContains($catCreateBody, 'catalogueAdminValidatePublicationIds('));
ok('admin_catalogue_create delegates to catalogueAdminSlugTaken(',
    caseBodyContains($catCreateBody, 'catalogueAdminSlugTaken('));
ok('admin_catalogue_create delegates to catalogueAdminCreate(',
    caseBodyContains($catCreateBody, 'catalogueAdminCreate('));
ok('admin_catalogue_create does NOT re-embed a raw "INSERT INTO tblCatalogues" (would be a forked write)',
    !caseBodyContains($catCreateBody, 'INSERT INTO tblCatalogues'));

$catUpdateBody = caseBodyFor($api, '$action', 'admin_catalogue_update');
ok('admin_catalogue_update delegates to catalogueAdminValidateUpdateFields(',
    caseBodyContains($catUpdateBody, 'catalogueAdminValidateUpdateFields('));
ok('admin_catalogue_update delegates to catalogueAdminFetchTitle( (existence check)',
    caseBodyContains($catUpdateBody, 'catalogueAdminFetchTitle('));
ok('admin_catalogue_update delegates to catalogueAdminUpdate(',
    caseBodyContains($catUpdateBody, 'catalogueAdminUpdate('));
ok('admin_catalogue_update does NOT re-embed a raw "UPDATE tblCatalogues SET" (would be a forked write)',
    !caseBodyContains($catUpdateBody, 'UPDATE tblCatalogues SET'));

$catDeleteBody = caseBodyFor($api, '$action', 'admin_catalogue_delete');
ok('admin_catalogue_delete delegates to catalogueAdminFetchTitle(',
    caseBodyContains($catDeleteBody, 'catalogueAdminFetchTitle('));
ok('admin_catalogue_delete delegates to catalogueAdminDelete(',
    caseBodyContains($catDeleteBody, 'catalogueAdminDelete('));
ok('admin_catalogue_delete does NOT re-embed a raw "DELETE FROM tblCatalogues" (would be a forked write)',
    !caseBodyContains($catDeleteBody, 'DELETE FROM tblCatalogues'));

$catMemberAddBody = caseBodyFor($api, '$action', 'admin_catalogue_member_add');
ok('admin_catalogue_member_add delegates to catalogueAdminFindVisibleSongTitle( (rule #43 — verify a REAL, visible song before writing)',
    caseBodyContains($catMemberAddBody, 'catalogueAdminFindVisibleSongTitle('));
ok('admin_catalogue_member_add delegates to catalogueAdminAddMember(',
    caseBodyContains($catMemberAddBody, 'catalogueAdminAddMember('));
ok('admin_catalogue_member_add does NOT re-embed a raw "INSERT IGNORE INTO tblCatalogueSongs" (would be a forked write)',
    !caseBodyContains($catMemberAddBody, 'INSERT IGNORE INTO tblCatalogueSongs'));

$catMemberRemoveBody = caseBodyFor($api, '$action', 'admin_catalogue_member_remove');
ok('admin_catalogue_member_remove delegates to catalogueAdminRemoveMember(',
    caseBodyContains($catMemberRemoveBody, 'catalogueAdminRemoveMember('));
ok('admin_catalogue_member_remove does NOT re-embed a raw "DELETE FROM tblCatalogueSongs" (would be a forked write)',
    !caseBodyContains($catMemberRemoveBody, 'DELETE FROM tblCatalogueSongs'));

/* Every A4 write action also gates on the schema being migrated (503),
   using the ONE shared probe — never a re-typed INFORMATION_SCHEMA query. */
foreach ([
    'admin_catalogue_create' => $catCreateBody,
    'admin_catalogue_update' => $catUpdateBody,
    'admin_catalogue_delete' => $catDeleteBody,
    'admin_catalogue_member_add' => $catMemberAddBody,
    'admin_catalogue_member_remove' => $catMemberRemoveBody,
] as $name => $body) {
    ok("'{$name}' gates on catalogueAdminTableExists( (503 on an un-migrated environment)",
        caseBodyContains($body, 'catalogueAdminTableExists('));
}

/* ---- G. A4 write/read core functions actually EXIST in
   includes/catalogue_admin.php. ---- */
foreach ([
    'catalogueAdminSlugify', 'catalogueAdminTableExists', 'catalogueAdminPubIdColumnsReady',
    'catalogueAdminValidatePublicationIds', 'catalogueAdminValidateCreateFields',
    'catalogueAdminValidateUpdateFields', 'catalogueAdminSlugTaken', 'catalogueAdminCreate',
    'catalogueAdminUpdate', 'catalogueAdminPersistPublicationIds', 'catalogueAdminFetchTitle',
    'catalogueAdminDelete', 'catalogueAdminFindVisibleSongTitle', 'catalogueAdminAddMember',
    'catalogueAdminRemoveMember',
] as $fn) {
    ok("includes/catalogue_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $catalogueAdminSrc));
}

/* ---- H1. A5 songbook-series actions: delegate to
   includes/songbook_series_admin.php — never a forked validation/write
   path (rule #22). ---- */
$seriesCreateBody = caseBodyFor($api, '$action', 'admin_songbook_series_create');
ok('admin_songbook_series_create delegates to songbookSeriesAdminValidateCoreFields(',
    caseBodyContains($seriesCreateBody, 'songbookSeriesAdminValidateCoreFields('));
ok('admin_songbook_series_create delegates to songbookSeriesAdminValidatePublicationIds(',
    caseBodyContains($seriesCreateBody, 'songbookSeriesAdminValidatePublicationIds('));
ok('admin_songbook_series_create delegates to songbookSeriesAdminSlugTaken(',
    caseBodyContains($seriesCreateBody, 'songbookSeriesAdminSlugTaken('));
ok('admin_songbook_series_create delegates to songbookSeriesAdminCreate(',
    caseBodyContains($seriesCreateBody, 'songbookSeriesAdminCreate('));
ok('admin_songbook_series_create does NOT re-embed a raw "INSERT INTO tblSongbookSeries" (would be a forked write)',
    !caseBodyContains($seriesCreateBody, 'INSERT INTO tblSongbookSeries'));

$seriesUpdateBody = caseBodyFor($api, '$action', 'admin_songbook_series_update');
ok('admin_songbook_series_update delegates to songbookSeriesAdminFetch( (before-state / existence check)',
    caseBodyContains($seriesUpdateBody, 'songbookSeriesAdminFetch('));
ok('admin_songbook_series_update delegates to songbookSeriesAdminUpdate(',
    caseBodyContains($seriesUpdateBody, 'songbookSeriesAdminUpdate('));
ok('admin_songbook_series_update delegates to songbookSeriesAdminParseMemberPost(',
    caseBodyContains($seriesUpdateBody, 'songbookSeriesAdminParseMemberPost('));
ok('admin_songbook_series_update delegates to songbookSeriesAdminReplaceMembership(',
    caseBodyContains($seriesUpdateBody, 'songbookSeriesAdminReplaceMembership('));
ok('admin_songbook_series_update delegates to songbookSeriesAdminMembers( (rule #35 — reads back the STORED list)',
    caseBodyContains($seriesUpdateBody, 'songbookSeriesAdminMembers('));
ok('admin_songbook_series_update does NOT re-embed a raw "UPDATE tblSongbookSeries SET Name" (would be a forked write)',
    !caseBodyContains($seriesUpdateBody, 'UPDATE tblSongbookSeries SET Name'));
ok('admin_songbook_series_update does NOT re-embed a raw "INSERT INTO tblSongbookSeriesMembership" (would be a forked write, rule #45-style membership discipline)',
    !caseBodyContains($seriesUpdateBody, 'INSERT INTO tblSongbookSeriesMembership'));

$seriesDeleteBody = caseBodyFor($api, '$action', 'admin_songbook_series_delete');
ok('admin_songbook_series_delete delegates to songbookSeriesAdminFetch(',
    caseBodyContains($seriesDeleteBody, 'songbookSeriesAdminFetch('));
ok('admin_songbook_series_delete delegates to songbookSeriesAdminDelete(',
    caseBodyContains($seriesDeleteBody, 'songbookSeriesAdminDelete('));
ok('admin_songbook_series_delete does NOT re-embed a raw "DELETE FROM tblSongbookSeries" (would be a forked write)',
    !caseBodyContains($seriesDeleteBody, 'DELETE FROM tblSongbookSeries'));

foreach ([
    'admin_songbook_series_create' => $seriesCreateBody,
    'admin_songbook_series_update' => $seriesUpdateBody,
    'admin_songbook_series_delete' => $seriesDeleteBody,
] as $name => $body) {
    ok("'{$name}' gates on songbookSeriesAdminTableExists( (503 on an un-migrated environment)",
        caseBodyContains($body, 'songbookSeriesAdminTableExists('));
}

/* ---- H2. A5 write/read core functions actually EXIST in
   includes/songbook_series_admin.php. ---- */
foreach ([
    'songbookSeriesAdminSlugify', 'songbookSeriesAdminTableExists', 'songbookSeriesAdminPubIdColumnsReady',
    'songbookSeriesAdminValidatePublicationIds', 'songbookSeriesAdminValidateCoreFields',
    'songbookSeriesAdminSlugTaken', 'songbookSeriesAdminFetch', 'songbookSeriesAdminCreate',
    'songbookSeriesAdminUpdate', 'songbookSeriesAdminPersistPublicationIds', 'songbookSeriesAdminDelete',
    'songbookSeriesAdminParseMemberPost', 'songbookSeriesAdminReplaceMembership', 'songbookSeriesAdminMembers',
] as $fn) {
    ok("includes/songbook_series_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $seriesAdminSrc));
}

/* =========================================================================
 * I. EXTRACTION VERIFICATION — the pages themselves were genuinely
 * RE-POINTED at the shared cores, not just the API. Checked PER CASE via
 * the same tokenising slicer (so `marcxml_import`, deliberately left
 * untouched and still raw-SQL, can never leak a false pass into the
 * re-pointed cases it sits beside).
 * ========================================================================= */

/* --- manage/tags.php ('create'/'update'/'delete'/'merge') --- */
$pageTagCreate = caseBodyFor($tagsPage, '$action', 'create');
ok("manage/tags.php's 'create' case delegates to tagAdminValidateFields(",
    caseBodyContains($pageTagCreate, 'tagAdminValidateFields('));
ok("manage/tags.php's 'create' case delegates to tagAdminCreate(",
    caseBodyContains($pageTagCreate, 'tagAdminCreate('));
ok("manage/tags.php's 'create' case has NO leftover raw \"INSERT INTO tblSongTags\" (genuinely re-pointed at the core, not just calling it alongside the old SQL)",
    !caseBodyContains($pageTagCreate, 'INSERT INTO tblSongTags'));

$pageTagUpdate = caseBodyFor($tagsPage, '$action', 'update');
ok("manage/tags.php's 'update' case delegates to tagAdminFetch(",
    caseBodyContains($pageTagUpdate, 'tagAdminFetch('));
ok("manage/tags.php's 'update' case delegates to tagAdminUpdate(",
    caseBodyContains($pageTagUpdate, 'tagAdminUpdate('));
ok("manage/tags.php's 'update' case has NO leftover raw \"UPDATE tblSongTags SET\"",
    !caseBodyContains($pageTagUpdate, 'UPDATE tblSongTags SET'));

$pageTagDelete = caseBodyFor($tagsPage, '$action', 'delete');
ok("manage/tags.php's 'delete' case delegates to tagAdminUsageCount(",
    caseBodyContains($pageTagDelete, 'tagAdminUsageCount('));
ok("manage/tags.php's 'delete' case delegates to tagAdminDelete(",
    caseBodyContains($pageTagDelete, 'tagAdminDelete('));
ok("manage/tags.php's 'delete' case has NO leftover raw \"DELETE FROM tblSongTags\"",
    !caseBodyContains($pageTagDelete, 'DELETE FROM tblSongTags'));

$pageTagMerge = caseBodyFor($tagsPage, '$action', 'merge');
ok("manage/tags.php's 'merge' case delegates to tagAdminFetchNamesByIds(",
    caseBodyContains($pageTagMerge, 'tagAdminFetchNamesByIds('));
ok("manage/tags.php's 'merge' case delegates to tagAdminMerge(",
    caseBodyContains($pageTagMerge, 'tagAdminMerge('));
ok("manage/tags.php's 'merge' case has NO leftover raw \"UPDATE tblSongTagMap SET TagId\"",
    !caseBodyContains($pageTagMerge, 'UPDATE tblSongTagMap SET TagId'));

ok("manage/tags.php's canonicalisation-suggestions read delegates to tagAdminCanonicalSuggestions( (never re-computed inline)",
    strpos($tagsPageSrc, 'tagAdminCanonicalSuggestions(') !== false);

/* --- manage/catalogues.php ('add'/'update'/'delete'/'add_member'/
   'remove_member' — 'marcxml_import' deliberately excluded) --- */
$pageCatAdd = caseBodyFor($cataloguesPage, '$action', 'add');
ok("manage/catalogues.php's 'add' case delegates to catalogueAdminValidateCreateFields(",
    caseBodyContains($pageCatAdd, 'catalogueAdminValidateCreateFields('));
ok("manage/catalogues.php's 'add' case delegates to catalogueAdminCreate(",
    caseBodyContains($pageCatAdd, 'catalogueAdminCreate('));
ok("manage/catalogues.php's 'add' case has NO leftover raw \"INSERT INTO tblCatalogues\" (genuinely re-pointed — the SAME string legitimately still lives in the untouched 'marcxml_import' case, which this per-case slice does NOT see)",
    !caseBodyContains($pageCatAdd, 'INSERT INTO tblCatalogues'));

$pageCatUpdate = caseBodyFor($cataloguesPage, '$action', 'update');
ok("manage/catalogues.php's 'update' case delegates to catalogueAdminValidateUpdateFields(",
    caseBodyContains($pageCatUpdate, 'catalogueAdminValidateUpdateFields('));
ok("manage/catalogues.php's 'update' case delegates to catalogueAdminUpdate(",
    caseBodyContains($pageCatUpdate, 'catalogueAdminUpdate('));
ok("manage/catalogues.php's 'update' case has NO leftover raw \"UPDATE tblCatalogues SET\"",
    !caseBodyContains($pageCatUpdate, 'UPDATE tblCatalogues SET'));

$pageCatDelete = caseBodyFor($cataloguesPage, '$action', 'delete');
ok("manage/catalogues.php's 'delete' case delegates to catalogueAdminFetchTitle(",
    caseBodyContains($pageCatDelete, 'catalogueAdminFetchTitle('));
ok("manage/catalogues.php's 'delete' case delegates to catalogueAdminDelete(",
    caseBodyContains($pageCatDelete, 'catalogueAdminDelete('));
ok("manage/catalogues.php's 'delete' case has NO leftover raw \"DELETE FROM tblCatalogues\"",
    !caseBodyContains($pageCatDelete, 'DELETE FROM tblCatalogues'));

$pageCatMemberAdd = caseBodyFor($cataloguesPage, '$action', 'add_member');
ok("manage/catalogues.php's 'add_member' case delegates to catalogueAdminFindVisibleSongTitle(",
    caseBodyContains($pageCatMemberAdd, 'catalogueAdminFindVisibleSongTitle('));
ok("manage/catalogues.php's 'add_member' case delegates to catalogueAdminAddMember(",
    caseBodyContains($pageCatMemberAdd, 'catalogueAdminAddMember('));
ok("manage/catalogues.php's 'add_member' case has NO leftover raw \"INSERT IGNORE INTO tblCatalogueSongs\"",
    !caseBodyContains($pageCatMemberAdd, 'INSERT IGNORE INTO tblCatalogueSongs'));

$pageCatMemberRemove = caseBodyFor($cataloguesPage, '$action', 'remove_member');
ok("manage/catalogues.php's 'remove_member' case delegates to catalogueAdminRemoveMember(",
    caseBodyContains($pageCatMemberRemove, 'catalogueAdminRemoveMember('));
ok("manage/catalogues.php's 'remove_member' case has NO leftover raw \"DELETE FROM tblCatalogueSongs\"",
    !caseBodyContains($pageCatMemberRemove, 'DELETE FROM tblCatalogueSongs'));

/* marcxml_import is DELIBERATELY out of scope (a file-upload wizard) and
   stays untouched — it should STILL carry its own raw INSERT, proving the
   absences above are genuine re-pointing and not an accidental across-file
   dead-code deletion that happened to also remove the deferred path. */
$pageCatMarcImport = caseBodyFor($cataloguesPage, '$action', 'marcxml_import');
ok("manage/catalogues.php's 'marcxml_import' case is UNCHANGED (still has its own raw \"INSERT INTO tblCatalogues\" — confirms it was deliberately left out of the extraction, not silently broken)",
    caseBodyContains($pageCatMarcImport, 'INSERT INTO tblCatalogues'));

/* --- manage/songbook-series.php ('create'/'update'/'delete' —
   'marcxml_import' deliberately excluded) --- */
$pageSeriesCreate = caseBodyFor($seriesPage, '$action', 'create');
ok("manage/songbook-series.php's 'create' case delegates to songbookSeriesAdminValidateCoreFields(",
    caseBodyContains($pageSeriesCreate, 'songbookSeriesAdminValidateCoreFields('));
ok("manage/songbook-series.php's 'create' case delegates to songbookSeriesAdminCreate(",
    caseBodyContains($pageSeriesCreate, 'songbookSeriesAdminCreate('));
ok("manage/songbook-series.php's 'create' case has NO leftover raw \"INSERT INTO tblSongbookSeries\" (the SAME string legitimately still lives in the untouched 'marcxml_import' case, which this per-case slice does NOT see)",
    !caseBodyContains($pageSeriesCreate, 'INSERT INTO tblSongbookSeries'));

$pageSeriesUpdate = caseBodyFor($seriesPage, '$action', 'update');
ok("manage/songbook-series.php's 'update' case delegates to songbookSeriesAdminFetch(",
    caseBodyContains($pageSeriesUpdate, 'songbookSeriesAdminFetch('));
ok("manage/songbook-series.php's 'update' case delegates to songbookSeriesAdminUpdate(",
    caseBodyContains($pageSeriesUpdate, 'songbookSeriesAdminUpdate('));
ok("manage/songbook-series.php's 'update' case delegates to songbookSeriesAdminReplaceMembership(",
    caseBodyContains($pageSeriesUpdate, 'songbookSeriesAdminReplaceMembership('));
ok("manage/songbook-series.php's 'update' case has NO leftover raw \"UPDATE tblSongbookSeries SET Name\"",
    !caseBodyContains($pageSeriesUpdate, 'UPDATE tblSongbookSeries SET Name'));
ok("manage/songbook-series.php's 'update' case has NO leftover raw \"INSERT INTO tblSongbookSeriesMembership\"",
    !caseBodyContains($pageSeriesUpdate, 'INSERT INTO tblSongbookSeriesMembership'));

$pageSeriesDelete = caseBodyFor($seriesPage, '$action', 'delete');
ok("manage/songbook-series.php's 'delete' case delegates to songbookSeriesAdminFetch(",
    caseBodyContains($pageSeriesDelete, 'songbookSeriesAdminFetch('));
ok("manage/songbook-series.php's 'delete' case delegates to songbookSeriesAdminDelete(",
    caseBodyContains($pageSeriesDelete, 'songbookSeriesAdminDelete('));
ok("manage/songbook-series.php's 'delete' case has NO leftover raw \"DELETE FROM tblSongbookSeries\"",
    !caseBodyContains($pageSeriesDelete, 'DELETE FROM tblSongbookSeries'));

$pageSeriesMarcImport = caseBodyFor($seriesPage, '$action', 'marcxml_import');
ok("manage/songbook-series.php's 'marcxml_import' case is UNCHANGED (still has its own raw \"INSERT INTO tblSongbookSeries\" — confirms it was deliberately left out of the extraction, not silently broken)",
    caseBodyContains($pageSeriesMarcImport, 'INSERT INTO tblSongbookSeries'));

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

echo "\n{$passed} passed, 0 failed. All 13 API-coverage batch-4b-i endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, and delegate to their shared cores with no forked validation/write — and the three sibling pages were genuinely re-pointed at those SAME cores (their marcxml_import wizards deliberately left untouched).\n";
exit(0);
