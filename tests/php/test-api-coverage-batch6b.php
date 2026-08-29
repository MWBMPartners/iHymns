<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 6b" MARCXML imports + IA reconcile
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3 A17 deferred the three
 * MARCXML-file-upload imports (songbooks/catalogues/songbook-series) and a
 * grab-bag of "genuinely multi-step / interactive tools" (bulk-promote
 * wizards, an `ia-reconcile` run, `family_manifest`) pending a confirmed
 * native-curator-app surface (§8 Q1). The owner confirmed Q1=yes, so this
 * batch PORTS the three MARCXML imports verbatim, and — after individually
 * assessing each of the other three — ALSO ports `ia-reconcile` (a
 * genuinely clean single POST -> JSON-report call, already fully
 * core-delegated) while DEFERRING bulk-promote and family_manifest (both
 * are honest preview/per-row-review wizards with no clean single-call
 * shape). This guard checks, from the REAL dispatched source (never a
 * hand-typed belief), that:
 *
 *   1. all four new actions genuinely exist as `$action`-switch cases in
 *      api.php (dispatched exactly once each);
 *   2. each has a top-level path item in api-docs.yaml;
 *   3. each gates on the SAME entitlement its sibling manage/*.php page
 *      ACTUALLY gates on (checked from BOTH sides);
 *   4. each DELEGATES to the shared cores (rule #22) — never a re-forked
 *      MARCXML parser (includes/marcxml.php, via manage/includes/
 *      marcxml_admin.php's thin wiring) and, where a row-write core
 *      already exists (catalogues/songbook-series), never a re-forked
 *      INSERT either. Songbooks has NO extracted row-write core (mirrors
 *      admin_songbook_create's own precedent — see that case's doc-block),
 *      so its marcxml-import case is checked for FIDELITY to the page's
 *      own INSERT+UPDATE shape instead of a core-function call;
 *   5. the THREE PAGES' own `marcxml_import` handlers are genuinely
 *      UNCHANGED by this batch (still their own raw INSERT) — this batch
 *      deliberately did not re-point them (out of scope, see the doc-block
 *      updates this batch made to includes/catalogue_admin.php and
 *      includes/songbook_series_admin.php);
 *   6. `admin_ia_reconcile_run` delegates end-to-end to the pipeline
 *      manage/ia-reconcile.php itself calls (includes/ia_client.php +
 *      includes/ia_reconcile.php), never a forked fetch/segment/score;
 *   7. THE DEFERRAL ITSELF — no bulk-promote or family_manifest action was
 *      added to api.php's dispatch surface, so a later session cannot
 *      mistake "we looked at it" for "we shipped it";
 *   8. api2.php / print-pdf.php / editor api.php stay untouched (explicit
 *      task constraint) — none of them mention this batch's four new
 *      action names.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * `api.php` has TWO switches (`$page` and `$action`), so a bare
 * `grep "case '...'"` cannot tell which one owns a label —
 * `dispatch_parser.php`'s token walker does. Every one of this batch's
 * four actions is its OWN separate `case 'name': { ... }` block (never
 * grouped fall-through), so `caseBodyFor()` cleanly isolates each action's
 * full body from its neighbours.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyFor()`/`caseBodyContains()`
 * are proven, against a tiny in-memory fixture, to both find a marker that
 * is there AND fail to find one that is not, before the real assertions
 * below are trusted (duplicated locally per the precedent every sibling
 * `test-api-coverage-batch*.php` file already sets, rather than growing
 * the shared `dispatch_parser.php` library with test-only helpers).
 *
 *   php tests/php/test-api-coverage-batch6b.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3 A17   the plan this implements
 * @see appWeb/public_html/api.php                                    the four new cases
 * @see appWeb/public_html/manage/includes/marcxml_admin.php          the ONE MARCXML parse/wiring core
 * @see appWeb/public_html/includes/marcxml.php                       the pure MARCXML parse/map/generate core
 * @see appWeb/public_html/includes/catalogue_admin.php               catalogue row-write core (A4)
 * @see appWeb/public_html/includes/songbook_series_admin.php         songbook-series row-write core (A5)
 * @see appWeb/public_html/includes/ia_client.php                     archive.org fetch + cache core
 * @see appWeb/public_html/includes/ia_reconcile.php                  OCR segment/score/persist core
 * @see appWeb/public_html/manage/songbooks.php                       page sibling (marcxml_import untouched)
 * @see appWeb/public_html/manage/catalogues.php                      page sibling (marcxml_import untouched)
 * @see appWeb/public_html/manage/songbook-series.php                 page sibling (marcxml_import untouched)
 * @see appWeb/public_html/manage/ia-reconcile.php                    page sibling (untouched, gate parity)
 * @see appWeb/public_html/manage/musicians-bulk-promote.php          DEFERRED — see report
 * @see tests/php/test-api-coverage-batch6a.php                       the sibling guard this mirrors
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$marcxmlAdminSrc   = (string)file_get_contents($repo . '/appWeb/public_html/manage/includes/marcxml_admin.php');
$catalogueAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/catalogue_admin.php');
$seriesAdminSrc    = (string)file_get_contents($repo . '/appWeb/public_html/includes/songbook_series_admin.php');
$iaClientSrc       = (string)file_get_contents($repo . '/appWeb/public_html/includes/ia_client.php');
$iaReconcileSrc    = (string)file_get_contents($repo . '/appWeb/public_html/includes/ia_reconcile.php');

$songbooksPage    = $repo . '/appWeb/public_html/manage/songbooks.php';
$cataloguesPage   = $repo . '/appWeb/public_html/manage/catalogues.php';
$seriesPage       = $repo . '/appWeb/public_html/manage/songbook-series.php';
$iaReconcilePage  = $repo . '/appWeb/public_html/manage/ia-reconcile.php';

$songbooksPageSrc   = (string)file_get_contents($songbooksPage);
$cataloguesPageSrc  = (string)file_get_contents($cataloguesPage);
$seriesPageSrc      = (string)file_get_contents($seriesPage);
$iaReconcilePageSrc = (string)file_get_contents($iaReconcilePage);

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
 * from an already-tokenised file (token_get_all() shape).
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

/**
 * Strip `//`/`#`/`/* *\/`/doc-comments from a PHP source fragment, so a
 * substring check below can never be fooled by a marker that only appears
 * in PROSE (a doc-block mentioning a function name, e.g.) rather than
 * actual code. Wraps a bare fragment so token_get_all() tokenises it as
 * PHP rather than one giant T_INLINE_HTML blob.
 */
function stripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $toks = @token_get_all($wrapped);
    if (!is_array($toks)) { return $src; }
    $out = '';
    foreach ($toks as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/**
 * True when $needle is a literal substring of $body's CODE — comments
 * stripped first — or $body is null -> false.
 */
function caseBodyContains(?string $body, string $needle): bool
{
    return $body !== null && strpos(stripPhpComments($body), $needle) !== false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the core helper functions above
 * can both find a marker that is there AND fail to find one that is not,
 * against a small real-tokeniser/real-source fixture.
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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b6b_');
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

/* stripPhpComments() / caseBodyContains() self-test — the exact trap this
   shape hit in a sibling test: a doc-comment mentioning a function NAME
   (with trailing parens, so it visually reads like a call) must NOT
   register as the function actually being CALLED. */
$commentTrapSrc = <<<'PHP'
/* This block deliberately never calls dangerousFunction() from here —
   see the design doc for why. */
safeFunction();
PHP;
if (caseBodyContains($commentTrapSrc, 'dangerousFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-LOW self-test: a marker that appears ONLY inside a /* comment */ was wrongly treated as present in the code';
}
if (!caseBodyContains($commentTrapSrc, 'safeFunction(')) {
    $mutationFailures[] = 'caseBodyContains()/stripPhpComments() FAILS-HIGH self-test: a marker genuinely present in real CODE (outside the comment) was not found';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 6b: MARCXML imports + IA reconcile (§4.3 A17)\n\n";

$batch6b = [
    'admin_songbook_marcxml_import',
    'admin_catalogue_marcxml_import',
    'admin_songbook_series_marcxml_import',
    'admin_ia_reconcile_run',
];

/* ---- A. Dispatchable: the real $action switch carries all four, exactly
   once each — tree-derived from the actual dispatcher. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch6b as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml. ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch6b as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement its sibling manage/*.php page ACTUALLY gates on — checked
   from BOTH sides. ---- */
$entitlementByAction = [
    'admin_songbook_marcxml_import'        => 'manage_songbooks',
    'admin_catalogue_marcxml_import'       => 'manage_songbooks',
    'admin_songbook_series_marcxml_import' => 'manage_songbooks',
    'admin_ia_reconcile_run'               => 'edit_songs',
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
ok('manage/songbooks.php really does gate on userHasEntitlement(\'manage_songbooks\' (API gate matches the page\'s OWN gate)',
    strpos($songbooksPageSrc, "userHasEntitlement('manage_songbooks'") !== false);
ok('manage/catalogues.php really does gate on userHasEntitlement(\'manage_songbooks\' (API gate matches the page\'s OWN gate)',
    strpos($cataloguesPageSrc, "userHasEntitlement('manage_songbooks'") !== false);
ok('manage/songbook-series.php really does gate on userHasEntitlement(\'manage_songbooks\' (API gate matches the page\'s OWN gate)',
    strpos($seriesPageSrc, "userHasEntitlement('manage_songbooks'") !== false);
ok('manage/ia-reconcile.php really does gate on userHasEntitlement(\'edit_songs\' (API gate matches the page\'s OWN gate)',
    strpos($iaReconcilePageSrc, "userHasEntitlement('edit_songs'") !== false);

/* ---- D. The three MARCXML-import actions all delegate to the ONE shared
   parse/wiring core — manage/includes/marcxml_admin.php — never a
   re-forked MARCXML parser. ---- */
$songbookImportBody = caseBodyFor($api, '$action', 'admin_songbook_marcxml_import');
$catalogueImportBody = caseBodyFor($api, '$action', 'admin_catalogue_marcxml_import');
$seriesImportBody    = caseBodyFor($api, '$action', 'admin_songbook_series_marcxml_import');

foreach ([
    'admin_songbook_marcxml_import'        => [$songbookImportBody, 'songbook'],
    'admin_catalogue_marcxml_import'       => [$catalogueImportBody, 'catalogue'],
    'admin_songbook_series_marcxml_import' => [$seriesImportBody, 'series'],
] as $name => [$body, $kind]) {
    ok("'{$name}' delegates to marcxmlAdmin_parseUpload(",
        caseBodyContains($body, 'marcxmlAdmin_parseUpload('));
    ok("'{$name}' passes the correct entity kind '{$kind}' to marcxmlAdmin_parseUpload(",
        caseBodyContains($body, "marcxmlAdmin_parseUpload(\$_FILES['marcxml_file'] ?? [], '{$kind}')"));
    ok("'{$name}' delegates to marcxmlAdmin_cleanPublicationIdentifiers(",
        caseBodyContains($body, 'marcxmlAdmin_cleanPublicationIdentifiers('));
    ok("'{$name}' requires manage/includes/marcxml_admin.php (never a bare call with no require)",
        caseBodyContains($body, "'marcxml_admin.php'"));
}

/* ---- E. Catalogue + songbook-series MARCXML imports write through the
   SAME row-write core admin_catalogue_create/admin_songbook_series_create
   already use — never a re-forked INSERT (rule #22). ---- */
ok('admin_catalogue_marcxml_import delegates to catalogueAdminSlugify(',
    caseBodyContains($catalogueImportBody, 'catalogueAdminSlugify('));
ok('admin_catalogue_marcxml_import delegates to catalogueAdminSlugTaken(',
    caseBodyContains($catalogueImportBody, 'catalogueAdminSlugTaken('));
ok('admin_catalogue_marcxml_import delegates to catalogueAdminCreate(',
    caseBodyContains($catalogueImportBody, 'catalogueAdminCreate('));
ok('admin_catalogue_marcxml_import delegates to catalogueAdminPersistPublicationIds(',
    caseBodyContains($catalogueImportBody, 'catalogueAdminPersistPublicationIds('));
ok('admin_catalogue_marcxml_import gates on catalogueAdminTableExists( (503 on an un-migrated environment)',
    caseBodyContains($catalogueImportBody, 'catalogueAdminTableExists('));
ok('admin_catalogue_marcxml_import does NOT re-embed a raw "INSERT INTO tblCatalogues" (would be a forked write)',
    !caseBodyContains($catalogueImportBody, 'INSERT INTO tblCatalogues'));
ok('admin_catalogue_marcxml_import imports HIDDEN (admin_only visibility, matching the page\'s own marcxml_import)',
    caseBodyContains($catalogueImportBody, "'visibility' => 'admin_only'"));

ok('admin_songbook_series_marcxml_import delegates to songbookSeriesAdminSlugify(',
    caseBodyContains($seriesImportBody, 'songbookSeriesAdminSlugify('));
ok('admin_songbook_series_marcxml_import delegates to songbookSeriesAdminSlugTaken(',
    caseBodyContains($seriesImportBody, 'songbookSeriesAdminSlugTaken('));
ok('admin_songbook_series_marcxml_import delegates to songbookSeriesAdminCreate(',
    caseBodyContains($seriesImportBody, 'songbookSeriesAdminCreate('));
ok('admin_songbook_series_marcxml_import delegates to songbookSeriesAdminPersistPublicationIds(',
    caseBodyContains($seriesImportBody, 'songbookSeriesAdminPersistPublicationIds('));
ok('admin_songbook_series_marcxml_import gates on songbookSeriesAdminTableExists( (503 on an un-migrated environment)',
    caseBodyContains($seriesImportBody, 'songbookSeriesAdminTableExists('));
ok('admin_songbook_series_marcxml_import does NOT re-embed a raw "INSERT INTO tblSongbookSeries" (would be a forked write)',
    !caseBodyContains($seriesImportBody, 'INSERT INTO tblSongbookSeries'));

/* ---- F. Songbook MARCXML import: no row-write core exists for
   tblSongbooks (admin_songbook_create itself inlines its own INSERT —
   this is a PRE-EXISTING, not newly-introduced, condition), so this case
   is checked for FIDELITY to the page's own two-step INSERT+UPDATE shape
   and for its own uniqueness/IL-id/maintenance-hook obligations instead
   of a core-function delegation check. ---- */
ok('admin_songbook_marcxml_import checks Abbreviation uniqueness before insert (409 on collision)',
    caseBodyContains($songbookImportBody, 'SELECT Id FROM tblSongbooks WHERE Abbreviation = ?'));
ok('admin_songbook_marcxml_import validates the abbreviation via validateSongbookAbbr( (the ONE shared validator, rule #22)',
    caseBodyContains($songbookImportBody, 'validateSongbookAbbr('));
ok('admin_songbook_marcxml_import delegates the auto-colour pick to pickAutoSongbookColour( (never a re-forked palette pick)',
    caseBodyContains($songbookImportBody, 'pickAutoSongbookColour('));
ok('admin_songbook_marcxml_import mints the permanent IL-id via ilidStampNewRow( (#1860 go-live parity with admin_songbook_create)',
    caseBodyContains($songbookImportBody, "ilidStampNewRow(\$db, 'songbook',"));
ok('admin_songbook_marcxml_import runs the post-write maintenance hook via songbookMaintenanceRun( (parity with the page\'s own marcxml_import)',
    caseBodyContains($songbookImportBody, 'songbookMaintenanceRun('));

/* ---- G. admin_ia_reconcile_run delegates end-to-end to the SAME pipeline
   manage/ia-reconcile.php calls — never a forked fetch/segment/score. ---- */
$iaRunBody = caseBodyFor($api, '$action', 'admin_ia_reconcile_run');
foreach ([
    'ihymns_canonical_ia_identifier(',
    'iaCachedMetadata(',
    'iaCachedFulltext(',
    'iaRecSegmentOcr(',
    'iaRecSongFeatures(',
    'iaRecScoreCandidates(',
    'iaRecPersistRun(',
] as $fnCall) {
    ok("admin_ia_reconcile_run delegates to {$fnCall}", caseBodyContains($iaRunBody, $fnCall));
}
ok('admin_ia_reconcile_run validates songbook_abbr against a real tblSongbooks row before running (rule #5 — never trust the posted abbreviation blindly)',
    caseBodyContains($iaRunBody, 'SELECT 1 FROM tblSongbooks WHERE Abbreviation = ?'));

/* ---- H. Every core function this batch calls actually EXISTS in the file
   claimed (never assumed from the api.php call sites alone). ---- */
foreach (['marcxmlAdmin_parseUpload', 'marcxmlAdmin_cleanPublicationIdentifiers'] as $fn) {
    ok("manage/includes/marcxml_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $marcxmlAdminSrc));
}
foreach ([
    'catalogueAdminSlugify', 'catalogueAdminSlugTaken', 'catalogueAdminCreate',
    'catalogueAdminPersistPublicationIds', 'catalogueAdminPubIdColumnsReady', 'catalogueAdminTableExists',
] as $fn) {
    ok("includes/catalogue_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $catalogueAdminSrc));
}
foreach ([
    'songbookSeriesAdminSlugify', 'songbookSeriesAdminSlugTaken', 'songbookSeriesAdminCreate',
    'songbookSeriesAdminPersistPublicationIds', 'songbookSeriesAdminPubIdColumnsReady', 'songbookSeriesAdminTableExists',
] as $fn) {
    ok("includes/songbook_series_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $seriesAdminSrc));
}
foreach (['iaCachedMetadata', 'iaCachedFulltext'] as $fn) {
    ok("includes/ia_client.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $iaClientSrc));
}
foreach (['iaRecSegmentOcr', 'iaRecSongFeatures', 'iaRecScoreCandidates', 'iaRecPersistRun'] as $fn) {
    ok("includes/ia_reconcile.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $iaReconcileSrc));
}

/* =========================================================================
 * I. THE THREE PAGES' OWN marcxml_import HANDLERS STAY UNCHANGED — this
 * batch deliberately did NOT re-point them (see the doc-block updates in
 * includes/catalogue_admin.php / includes/songbook_series_admin.php).
 * Checked PER CASE via the same tokenising slicer, so this can never be
 * satisfied by the NEW api.php case bodies bleeding into the page source.
 * ========================================================================= */

$pageSongbooksMarcImport = caseBodyFor($songbooksPage, '$action', 'marcxml_import');
ok("manage/songbooks.php's 'marcxml_import' case is UNCHANGED (still has its own raw \"INSERT INTO tblSongbooks\")",
    caseBodyContains($pageSongbooksMarcImport, 'INSERT INTO tblSongbooks'));

$pageCataloguesMarcImport = caseBodyFor($cataloguesPage, '$action', 'marcxml_import');
ok("manage/catalogues.php's 'marcxml_import' case is UNCHANGED (still has its own raw \"INSERT INTO tblCatalogues\", not re-pointed at catalogueAdminCreate())",
    caseBodyContains($pageCataloguesMarcImport, 'INSERT INTO tblCatalogues')
    && !caseBodyContains($pageCataloguesMarcImport, 'catalogueAdminCreate('));

$pageSeriesMarcImport = caseBodyFor($seriesPage, '$action', 'marcxml_import');
ok("manage/songbook-series.php's 'marcxml_import' case is UNCHANGED (still has its own raw \"INSERT INTO tblSongbookSeries\", not re-pointed at songbookSeriesAdminCreate())",
    caseBodyContains($pageSeriesMarcImport, 'INSERT INTO tblSongbookSeries')
    && !caseBodyContains($pageSeriesMarcImport, 'songbookSeriesAdminCreate('));

/* manage/ia-reconcile.php's own POST 'run' handler is untouched — same
   pipeline functions still called directly in the page, proving this
   batch's API twin is a genuine PARALLEL caller, not a refactor of the
   page (which stays its own self-contained, session-only surface). */
foreach ([
    'iaCachedMetadata(', 'iaCachedFulltext(', 'iaRecSegmentOcr(',
    'iaRecSongFeatures(', 'iaRecScoreCandidates(', 'iaRecPersistRun(',
] as $fnCall) {
    ok("manage/ia-reconcile.php still calls {$fnCall} directly (untouched by this batch)",
        strpos($iaReconcilePageSrc, $fnCall) !== false);
}

/* =========================================================================
 * J. THE DEFERRAL ITSELF — bulk-promote and family_manifest were
 * individually assessed and DEFERRED (neither has a clean single-call
 * shape: bulk-promote requires per-row curator review of live-computed
 * fuzzy-match suggestions across a dynamically-sized candidate set;
 * family_manifest's whole value is a human visually scanning a per-row
 * will_link/will_relink/already_ok/… plan table before ticking confirm).
 * This locks the decision in — a later session cannot mistake "we looked
 * at it" for "we shipped it".
 * ========================================================================= */

foreach ([
    'admin_musicians_bulk_promote', 'admin_musician_bulk_promote', 'bulk_promote',
    'admin_songbook_family_manifest', 'admin_family_manifest', 'family_manifest',
] as $bannedName) {
    ok("no '{$bannedName}' action was added to api.php's \$action switch (bulk-promote / family_manifest stay deferred, web-only)",
        !isset($actionCounts[$bannedName]));
}

/* manage/musicians-bulk-promote.php and manage/songbooks.php's own
   family_manifest case both still exist, page-only, exactly as before —
   confirms the deferral is "left alone", not "silently deleted". */
ok('manage/musicians-bulk-promote.php still exists (deferred, not deleted — remains the web-only path)',
    is_file($repo . '/appWeb/public_html/manage/musicians-bulk-promote.php'));
$pageFamilyManifest = caseBodyFor($songbooksPage, '$action', 'family_manifest');
ok("manage/songbooks.php's 'family_manifest' case still exists (deferred, not deleted — remains the web-only path)",
    $pageFamilyManifest !== null);

/* =========================================================================
 * K. api2.php / print-pdf.php / editor api.php CONSTRAINT COMPLIANCE — this
 * batch's task explicitly listed those as out of scope ("Do NOT touch
 * api2.php/editor api.php/print-pdf.php"). Confirms none of them mention
 * this batch's four new action names at all.
 * ========================================================================= */

foreach ([
    'api2.php'       => $repo . '/appWeb/public_html/manage/editor/api2.php',
    'editor api.php' => $repo . '/appWeb/public_html/manage/editor/api.php',
    'print-pdf.php'  => $repo . '/appWeb/public_html/manage/print-pdf.php',
] as $label => $path) {
    if (!is_file($path)) { continue; }
    $src = stripPhpComments((string)file_get_contents($path));
    $touched = false;
    foreach ($batch6b as $name) {
        if (strpos($src, $name) !== false) { $touched = true; break; }
    }
    ok("{$label} was left untouched by this batch (mentions none of the four new action names)", !$touched);
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

echo "\n{$passed} passed, 0 failed. All four API-coverage batch-6b endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, and delegate to their shared parse/write cores with no forked SQL or fetch/segment/score logic — the three MARCXML-import pages and the ia-reconcile page stay genuinely untouched, and the bulk-promote / family_manifest deferral is locked in.\n";
exit(0);
