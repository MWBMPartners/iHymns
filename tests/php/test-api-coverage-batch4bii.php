<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 4b-ii" languages/external-link-types/print-templates (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3/§9 asked for three more admin/
 * curator registry surfaces to get native-app-capable JSON twins: the BCP 47
 * language registry (A6), the external-link type + URL-pattern registry
 * (A7), and print templates + their custom full-page layout skin (A8). This
 * guard checks, from the real dispatched source (not a hand-typed belief),
 * that the twelve new `admin_language_*` / `admin_external_link_type_save` /
 * `admin_print_template_*` / `admin_print_layout_*` actions genuinely exist
 * as `$action`-switch cases in `api.php`, are documented in `api-docs.yaml`,
 * gate on the SAME entitlement their sibling `manage/*.php` page gates on
 * (checked from BOTH sides), and DELEGATE to the newly-extracted (or, for
 * the print-layout pair, pre-existing) shared core their sibling page ALSO
 * delegates to (rule #22), rather than forking a second validation/write
 * path:
 *
 *   - A6 languages:            includes/language_admin.php          <-> manage/languages.php
 *   - A7 external-link types:  includes/external_link_type_admin.php <-> manage/external-link-types.php
 *   - A8 print templates:      includes/print_template_admin.php     <-> manage/print-templates.php
 *   - A8 print layout (pre-existing core, reused not forked):
 *                               includes/print_custom_layout.php      <-> manage/print-templates.php
 *
 * Section G is the rule-#39 obligation the task called out explicitly: it
 * does not just check that `admin_print_layout_save` calls
 * `printCustomLayoutSave(` — it also opens `includes/print_custom_layout.php`
 * and confirms THAT function's own body calls `ihymnsSanitizeHtml(`, so the
 * guard cannot be satisfied by a same-named decoy that never actually
 * sanitises anything.
 *
 * Section I checks the EXTRACTION side of the story: that each page's
 * re-pointed case body no longer embeds the raw write SQL that used to live
 * there — scoped PER CASE via the same tokenising slicer used for
 * `api.php`, so `manage/print-templates.php`'s deliberately-untouched
 * `import` case (out of scope for this batch) can never leak a false pass
 * into `save`/`clone`/`delete`/`set_default`.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch4bi.php`: `api.php` has TWO
 * switches (`$page` and `$action`), so a bare `grep "case '...'"` cannot
 * tell which one owns a label. Each case's BODY is isolated by slicing the
 * token stream between this case's label and the next case's label in
 * switch order (`tests/php/lib/dispatch_parser.php`), so a delegation check
 * against one action's body cannot accidentally be satisfied by something
 * that only appears in a NEIGHBOURING action's body. The SAME slicer is
 * reused against the three `manage/*.php` pages' own `$action` switches for
 * section I, for the identical reason.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyContains()`/
 * `caseBodyFor()` are proven, against tiny in-memory token-shaped fixtures
 * assembled with PHP's own `token_get_all()`, to both find a marker that is
 * there AND fail to find one that is not, before the real assertions below
 * are trusted (duplicated locally, same precedent `test-api-coverage-
 * batch4bi.php` set rather than growing the shared library). `phpFunctionBody()`
 * (section G's brace-matching function-body slicer, a NEW small helper this
 * file needs that neither sibling guard did) gets its own tiny mutation
 * self-test for the identical reason — proven to isolate ONE named
 * function's body and not bleed into a neighbouring function, BEFORE it is
 * trusted against the real `print_custom_layout.php` source.
 *
 *   php tests/php/test-api-coverage-batch4bii.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3/§9   the plan this implements
 * @see appWeb/public_html/api.php                              the twelve new cases
 * @see appWeb/public_html/includes/language_admin.php           language write/read core (A6)
 * @see appWeb/public_html/includes/external_link_type_admin.php external-link-type write core (A7)
 * @see appWeb/public_html/includes/print_template_admin.php     print-template scalar-row write core (A8)
 * @see appWeb/public_html/includes/print_custom_layout.php      PRE-EXISTING custom-layout write core (A8, reused not forked)
 * @see appWeb/public_html/manage/languages.php               the page manage_languages-gates against
 * @see appWeb/public_html/manage/external-link-types.php     the page manage_external_link_types-gates against
 * @see appWeb/public_html/manage/print-templates.php         the page manage_songbooks-gates against
 * @see tests/php/test-api-coverage-batch4bi.php               the sibling guard this mirrors
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';

$languageAdminSrc         = (string)file_get_contents($repo . '/appWeb/public_html/includes/language_admin.php');
$externalLinkTypeAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/external_link_type_admin.php');
$printTemplateAdminSrc    = (string)file_get_contents($repo . '/appWeb/public_html/includes/print_template_admin.php');
$printCustomLayoutSrc     = (string)file_get_contents($repo . '/appWeb/public_html/includes/print_custom_layout.php');

$languagesPage        = $repo . '/appWeb/public_html/manage/languages.php';
$externalLinkTypesPage = $repo . '/appWeb/public_html/manage/external-link-types.php';
$printTemplatesPage    = $repo . '/appWeb/public_html/manage/print-templates.php';

$languagesPageSrc        = (string)file_get_contents($languagesPage);
$externalLinkTypesPageSrc = (string)file_get_contents($externalLinkTypesPage);
$printTemplatesPageSrc    = (string)file_get_contents($printTemplatesPage);

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

/**
 * Section-G-only helper: the raw source text of ONE top-level
 * `function $fnName(...) { ... }` definition in $src — from the `function`
 * keyword's own occurrence up to its matching closing brace (simple depth
 * counter over `{`/`}` characters in the source text; adequate for this
 * codebase's own clean, non-adversarial include files — the same trust
 * level `caseBodyFor()`'s token-stream slicer already places in
 * `dispatch_parser.php`'s own source files).
 *
 * @return string|null null when no such function is defined in $src.
 */
function phpFunctionBody(string $src, string $fnName): ?string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $searchFrom = $m[0][1];
    $braceStart = strpos($src, '{', $searchFrom);
    if ($braceStart === false) { return null; }

    $depth = 0;
    $len = strlen($src);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $searchFrom, $i - $searchFrom + 1);
            }
        }
    }
    return null; // unbalanced — treat as "could not isolate"
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the core helper functions above
 * can both find a marker that is there and fail to find one that is not,
 * against small real-tokeniser/real-source fixtures.
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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b4bii_');
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

/* phpFunctionBody() self-test — two functions, one calling a "sanitiser",
   one not; must isolate correctly in both directions. */
$fnFixtureSrc = <<<'PHP'
<?php
function decoyFunction(string $x): string
{
    return trim($x);
}

function realWriter(string $raw): array
{
    $clean = pretendSanitize($raw, 'layout');
    return ['ok' => true, 'clean' => $clean];
}

function afterFunction(): void
{
    pretendSanitize('should not leak backwards', 'layout');
}
PHP;

$decoyBody = phpFunctionBody($fnFixtureSrc, 'decoyFunction');
$realBody  = phpFunctionBody($fnFixtureSrc, 'realWriter');
if ($realBody === null || strpos($realBody, "pretendSanitize(") === false) {
    $mutationFailures[] = 'phpFunctionBody() FAILS-HIGH self-test: a marker genuinely present inside realWriter() was not found';
}
if ($decoyBody === null || strpos($decoyBody, 'pretendSanitize(') !== false) {
    $mutationFailures[] = 'phpFunctionBody() FAILS-LOW self-test: decoyFunction()\'s isolated body wrongly contains a marker that only exists in a NEIGHBOURING function (realWriter/afterFunction) — the slice is bleeding across function boundaries';
}
if (phpFunctionBody($fnFixtureSrc, 'doesNotExistFunction') !== null) {
    $mutationFailures[] = 'phpFunctionBody() FAILS-LOW self-test: a non-existent function name returned a body instead of null';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 4b-ii (#1969): languages (A6) / external-link types (A7) / print templates (A8)\n\n";

$batch4bii = [
    'admin_language_create',
    'admin_language_update',
    'admin_language_toggle',
    'admin_language_delete',
    'admin_language_remap_tag',
    'admin_external_link_type_save',
    'admin_print_template_save',
    'admin_print_template_clone',
    'admin_print_template_delete',
    'admin_print_template_set_default',
    'admin_print_layout_save',
    'admin_print_layout_delete',
];

/* ---- A. Dispatchable: the real $action switch carries all twelve, exactly
   once each — tree-derived from the actual dispatcher, not a belief. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch4bii as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch4bii as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page gates on — checked from
   BOTH sides (the API case body AND the page source itself), so a future
   drift in either file is caught, not just a hand-typed belief about what
   the page currently does. ---- */
$entitlementByAction = [
    'admin_language_create'             => 'manage_languages',
    'admin_language_update'             => 'manage_languages',
    'admin_language_toggle'             => 'manage_languages',
    'admin_language_delete'             => 'manage_languages',
    'admin_language_remap_tag'          => 'manage_languages',
    'admin_external_link_type_save'     => 'manage_external_link_types',
    'admin_print_template_save'         => 'manage_songbooks',
    'admin_print_template_clone'        => 'manage_songbooks',
    'admin_print_template_delete'       => 'manage_songbooks',
    'admin_print_template_set_default'  => 'manage_songbooks',
    'admin_print_layout_save'           => 'manage_songbooks',
    'admin_print_layout_delete'         => 'manage_songbooks',
];
$pageSrcByEntitlement = [
    'manage_languages'             => $languagesPageSrc,
    'manage_external_link_types'   => $externalLinkTypesPageSrc,
    'manage_songbooks'             => $printTemplatesPageSrc,
];
foreach ($entitlementByAction as $name => $entKey) {
    $body = caseBodyFor($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$entKey}'",
        caseBodyContains($body, "userHasEntitlement('{$entKey}'"));
}
foreach ($pageSrcByEntitlement as $entKey => $src) {
    ok("the sibling manage/*.php page for '{$entKey}' really does gate on userHasEntitlement('{$entKey}' (API gate matches the page's OWN gate, not a belief about it)",
        strpos($src, "userHasEntitlement('{$entKey}'") !== false);
}

/* ---- D. A6 language actions: delegate to includes/language_admin.php —
   never a forked validation/write path (rule #22/#25). ---- */
$langCreateBody = caseBodyFor($api, '$action', 'admin_language_create');
ok('admin_language_create delegates to languageAdminValidateFields(',
    caseBodyContains($langCreateBody, 'languageAdminValidateFields('));
ok('admin_language_create delegates to languageAdminCodeExists( (friendlier pre-flight than the unique-key violation)',
    caseBodyContains($langCreateBody, 'languageAdminCodeExists('));
ok('admin_language_create delegates to languageAdminCreate(',
    caseBodyContains($langCreateBody, 'languageAdminCreate('));
ok('admin_language_create does NOT re-embed a raw "INSERT INTO tblLanguages" (would be a forked write)',
    !caseBodyContains($langCreateBody, 'INSERT INTO tblLanguages'));

$langUpdateBody = caseBodyFor($api, '$action', 'admin_language_update');
ok('admin_language_update delegates to languageAdminValidateFields(',
    caseBodyContains($langUpdateBody, 'languageAdminValidateFields('));
ok('admin_language_update delegates to languageAdminFetch( (before-state / existence check)',
    caseBodyContains($langUpdateBody, 'languageAdminFetch('));
ok('admin_language_update delegates to languageAdminUpdate(',
    caseBodyContains($langUpdateBody, 'languageAdminUpdate('));
ok('admin_language_update does NOT re-embed a raw "UPDATE tblLanguages" (would be a forked write)',
    !caseBodyContains($langUpdateBody, 'UPDATE tblLanguages'));

$langToggleBody = caseBodyFor($api, '$action', 'admin_language_toggle');
ok('admin_language_toggle delegates to languageAdminToggleActive(',
    caseBodyContains($langToggleBody, 'languageAdminToggleActive('));
ok('admin_language_toggle does NOT re-embed a raw "UPDATE tblLanguages" (would be a forked write)',
    !caseBodyContains($langToggleBody, 'UPDATE tblLanguages'));

$langDeleteBody = caseBodyFor($api, '$action', 'admin_language_delete');
ok('admin_language_delete delegates to languageAdminUsageCounts( (the 409-requires-force gate)',
    caseBodyContains($langDeleteBody, 'languageAdminUsageCounts('));
ok('admin_language_delete delegates to languageAdminDelete(',
    caseBodyContains($langDeleteBody, 'languageAdminDelete('));
ok('admin_language_delete does NOT re-embed a raw "DELETE FROM tblLanguages" (would be a forked write)',
    !caseBodyContains($langDeleteBody, 'DELETE FROM tblLanguages'));

$langRemapBody = caseBodyFor($api, '$action', 'admin_language_remap_tag');
ok('admin_language_remap_tag delegates to languageAdminRemapPreflight( (the shared 400/404/409 validate-then-confirm glue)',
    caseBodyContains($langRemapBody, 'languageAdminRemapPreflight('));
ok('admin_language_remap_tag delegates to languageTagRemap( (the ONE remap write core, unchanged, includes/language_tag_audit.php)',
    caseBodyContains($langRemapBody, 'languageTagRemap('));
ok('admin_language_remap_tag does NOT re-embed the grammar check "_ietfBcp47Validate(" directly (must go through the shared preflight, not a re-forked copy)',
    !caseBodyContains($langRemapBody, '_ietfBcp47Validate('));
ok('admin_language_remap_tag does NOT re-embed a raw call to "languageTagAuditScan(" directly (the live-total recompute lives inside languageAdminRemapPreflight(), not duplicated here)',
    !caseBodyContains($langRemapBody, 'languageTagAuditScan('));

/* ---- E. A6 write/read core functions actually EXIST in
   includes/language_admin.php (never assumed from the api.php call sites
   alone). ---- */
foreach ([
    'languageAdminValidateCode', 'languageAdminValidateName', 'languageAdminValidateNativeName',
    'languageAdminValidateTextDirection', 'languageAdminValidateScope', 'languageAdminValidateFields',
    'languageAdminCodeExists', 'languageAdminFetch', 'languageAdminCreate', 'languageAdminUpdate',
    'languageAdminToggleActive', 'languageAdminUsageCounts', 'languageAdminDelete',
    'languageAdminRemapPreflight',
] as $fn) {
    ok("includes/language_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $languageAdminSrc));
}

/* ---- F. A7 external-link-type action: delegates to
   includes/external_link_type_admin.php — never a forked validation/write
   path (rule #22). ---- */
$linkTypeSaveBody = caseBodyFor($api, '$action', 'admin_external_link_type_save');
ok('admin_external_link_type_save gates on externalLinkTypeAdminSchemaReady( (503 on an un-migrated environment)',
    caseBodyContains($linkTypeSaveBody, 'externalLinkTypeAdminSchemaReady('));
ok('admin_external_link_type_save delegates to externalLinkTypeAdminFetchAppliesTo( (existence check + the "existing" input to the resolver)',
    caseBodyContains($linkTypeSaveBody, 'externalLinkTypeAdminFetchAppliesTo('));
ok('admin_external_link_type_save delegates to externalLinkTypeAdminResolveAppliesTo(',
    caseBodyContains($linkTypeSaveBody, 'externalLinkTypeAdminResolveAppliesTo('));
ok('admin_external_link_type_save delegates to externalLinkTypeAdminNormalisePatterns(',
    caseBodyContains($linkTypeSaveBody, 'externalLinkTypeAdminNormalisePatterns('));
ok('admin_external_link_type_save delegates to externalLinkTypeAdminSave( (the ONE transactional writer)',
    caseBodyContains($linkTypeSaveBody, 'externalLinkTypeAdminSave('));
ok('admin_external_link_type_save does NOT re-embed a raw "UPDATE tblExternalLinkTypes SET" (would be a forked write)',
    !caseBodyContains($linkTypeSaveBody, 'UPDATE tblExternalLinkTypes SET'));
ok('admin_external_link_type_save does NOT re-embed a raw "INSERT INTO tblExternalLinkPatterns" (would be a forked write)',
    !caseBodyContains($linkTypeSaveBody, 'INSERT INTO tblExternalLinkPatterns'));

/* ---- G. A7 write/read core functions actually EXIST in
   includes/external_link_type_admin.php. ---- */
foreach ([
    'externalLinkTypeAdminSchemaReady', 'externalLinkTypeAdminFetchAppliesTo',
    'externalLinkTypeAdminResolveAppliesTo', 'externalLinkTypeAdminNormalisePatterns',
    'externalLinkTypeAdminSave',
] as $fn) {
    ok("includes/external_link_type_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $externalLinkTypeAdminSrc));
}

/* ---- H. A8 print-template actions: delegate to
   includes/print_template_admin.php — never a forked validation/write path
   (rule #22). ---- */
$ptSaveBody = caseBodyFor($api, '$action', 'admin_print_template_save');
ok('admin_print_template_save gates on printTemplateAdminTableExists( (503 on an un-migrated environment)',
    caseBodyContains($ptSaveBody, 'printTemplateAdminTableExists('));
ok('admin_print_template_save delegates to printTemplateAdminValidateContent(',
    caseBodyContains($ptSaveBody, 'printTemplateAdminValidateContent('));
ok('admin_print_template_save delegates to printTemplateAdminCreate( or printTemplateAdminUpdate( (INSERT/UPDATE branch)',
    caseBodyContains($ptSaveBody, 'printTemplateAdminCreate(') && caseBodyContains($ptSaveBody, 'printTemplateAdminUpdate('));
ok('admin_print_template_save does NOT re-embed a raw "INSERT INTO tblPrintTemplates" (would be a forked write)',
    !caseBodyContains($ptSaveBody, 'INSERT INTO tblPrintTemplates'));
ok('admin_print_template_save does NOT re-embed a raw "UPDATE tblPrintTemplates SET" (would be a forked write)',
    !caseBodyContains($ptSaveBody, 'UPDATE tblPrintTemplates SET'));
ok('admin_print_template_save does NOT call ptSanitiseBlocks( directly (must go through printTemplateAdminValidateContent(), not a re-forked copy)',
    !caseBodyContains($ptSaveBody, 'ptSanitiseBlocks('));

$ptCloneBody = caseBodyFor($api, '$action', 'admin_print_template_clone');
ok('admin_print_template_clone gates on printTemplateAdminTableExists(',
    caseBodyContains($ptCloneBody, 'printTemplateAdminTableExists('));
ok('admin_print_template_clone delegates to printTemplateAdminClone(',
    caseBodyContains($ptCloneBody, 'printTemplateAdminClone('));
ok('admin_print_template_clone does NOT re-embed a raw "INSERT INTO tblPrintTemplates" (would be a forked write)',
    !caseBodyContains($ptCloneBody, 'INSERT INTO tblPrintTemplates'));

$ptDeleteBody = caseBodyFor($api, '$action', 'admin_print_template_delete');
ok('admin_print_template_delete gates on printTemplateAdminTableExists(',
    caseBodyContains($ptDeleteBody, 'printTemplateAdminTableExists('));
ok('admin_print_template_delete delegates to printTemplateAdminDelete(',
    caseBodyContains($ptDeleteBody, 'printTemplateAdminDelete('));
ok('admin_print_template_delete does NOT re-embed a raw "DELETE FROM tblPrintTemplates" (would be a forked write)',
    !caseBodyContains($ptDeleteBody, 'DELETE FROM tblPrintTemplates'));

$ptSetDefaultBody = caseBodyFor($api, '$action', 'admin_print_template_set_default');
ok('admin_print_template_set_default gates on printTemplateAdminTableExists(',
    caseBodyContains($ptSetDefaultBody, 'printTemplateAdminTableExists('));
ok('admin_print_template_set_default delegates to printTemplateAdminFetch( (existence check BEFORE the write — see that core\'s own doc-comment on why affected_rows is not reliable here)',
    caseBodyContains($ptSetDefaultBody, 'printTemplateAdminFetch('));
ok('admin_print_template_set_default delegates to printTemplateAdminSetDefault(',
    caseBodyContains($ptSetDefaultBody, 'printTemplateAdminSetDefault('));
ok('admin_print_template_set_default does NOT re-embed a raw "UPDATE tblPrintTemplates SET IsDefault" (would be a forked write)',
    !caseBodyContains($ptSetDefaultBody, 'UPDATE tblPrintTemplates SET IsDefault'));

/* ---- G continued (A8 print-layout pair). The custom-layout write core
   ALREADY EXISTED before this batch — includes/print_custom_layout.php —
   so these two actions call it DIRECTLY rather than through a new
   print_template_admin.php wrapper (rule #22: never re-wrap a writer that
   already exists). rule #39's obligation: prove the layout-SAVE path
   actually reaches the HTML sanitiser, in TWO steps so a same-named decoy
   cannot satisfy the check — (1) the API case body calls
   printCustomLayoutSave(, and (2) THAT function's own body (opened fresh
   from print_custom_layout.php and isolated by phpFunctionBody()) calls
   ihymnsSanitizeHtml(. ---- */
$ptLayoutSaveBody = caseBodyFor($api, '$action', 'admin_print_layout_save');
ok('admin_print_layout_save gates on printTemplateAdminTableExists(',
    caseBodyContains($ptLayoutSaveBody, 'printTemplateAdminTableExists('));
ok('admin_print_layout_save delegates to printCustomLayoutSave( (THE ONE writer, includes/print_custom_layout.php)',
    caseBodyContains($ptLayoutSaveBody, 'printCustomLayoutSave('));
ok('admin_print_layout_save does NOT call ihymnsSanitizeHtml( directly (sanitisation happens INSIDE printCustomLayoutSave(), never a second call site — rule #39)',
    !caseBodyContains($ptLayoutSaveBody, 'ihymnsSanitizeHtml('));

$printCustomLayoutSaveFnBody = phpFunctionBody($printCustomLayoutSrc, 'printCustomLayoutSave');
ok('includes/print_custom_layout.php defines function printCustomLayoutSave(',
    $printCustomLayoutSaveFnBody !== null);
ok("printCustomLayoutSave()'s OWN body calls ihymnsSanitizeHtml( — the layout-save path genuinely reaches the sanitiser, not just a same-named decoy (rule #39)",
    $printCustomLayoutSaveFnBody !== null && strpos($printCustomLayoutSaveFnBody, 'ihymnsSanitizeHtml(') !== false);

$ptLayoutDeleteBody = caseBodyFor($api, '$action', 'admin_print_layout_delete');
ok('admin_print_layout_delete gates on printTemplateAdminTableExists(',
    caseBodyContains($ptLayoutDeleteBody, 'printTemplateAdminTableExists('));
ok('admin_print_layout_delete delegates to printCustomLayoutDelete( (THE ONE writer, includes/print_custom_layout.php)',
    caseBodyContains($ptLayoutDeleteBody, 'printCustomLayoutDelete('));

/* ---- H2. A8 write/read core functions actually EXIST in
   includes/print_template_admin.php. ---- */
foreach ([
    'printTemplateAdminTableExists', 'printTemplateAdminValidateContent', 'printTemplateAdminNextSortOrder',
    'printTemplateAdminFetch', 'printTemplateAdminCreate', 'printTemplateAdminUpdate',
    'printTemplateAdminDelete', 'printTemplateAdminClone', 'printTemplateAdminSetDefault',
] as $fn) {
    ok("includes/print_template_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $printTemplateAdminSrc));
}
foreach (['printCustomLayoutSave', 'printCustomLayoutDelete'] as $fn) {
    ok("includes/print_custom_layout.php (pre-existing core, reused not forked) defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $printCustomLayoutSrc));
}

/* =========================================================================
 * I. EXTRACTION VERIFICATION — the pages themselves were genuinely
 * RE-POINTED at the shared cores, not just the API. Checked PER CASE via
 * the same tokenising slicer (so manage/print-templates.php's
 * deliberately-untouched 'import' case can never leak a false pass into
 * the re-pointed 'save'/'clone'/'delete'/'set_default' cases it sits
 * beside).
 * ========================================================================= */

/* --- manage/languages.php ('create'/'update'/'toggle_active'/'delete'/
   'remap_tag') --- */
$pageLangCreate = caseBodyFor($languagesPage, '$action', 'create');
ok("manage/languages.php's 'create' case delegates to languageAdminValidateFields(",
    caseBodyContains($pageLangCreate, 'languageAdminValidateFields('));
ok("manage/languages.php's 'create' case delegates to languageAdminCreate(",
    caseBodyContains($pageLangCreate, 'languageAdminCreate('));
ok("manage/languages.php's 'create' case has NO leftover raw \"INSERT INTO tblLanguages\" (genuinely re-pointed at the core, not just calling it alongside the old SQL)",
    !caseBodyContains($pageLangCreate, 'INSERT INTO tblLanguages'));

$pageLangUpdate = caseBodyFor($languagesPage, '$action', 'update');
ok("manage/languages.php's 'update' case delegates to languageAdminFetch(",
    caseBodyContains($pageLangUpdate, 'languageAdminFetch('));
ok("manage/languages.php's 'update' case delegates to languageAdminUpdate(",
    caseBodyContains($pageLangUpdate, 'languageAdminUpdate('));
ok("manage/languages.php's 'update' case has NO leftover raw \"UPDATE tblLanguages\"",
    !caseBodyContains($pageLangUpdate, 'UPDATE tblLanguages'));

$pageLangToggle = caseBodyFor($languagesPage, '$action', 'toggle_active');
ok("manage/languages.php's 'toggle_active' case delegates to languageAdminToggleActive(",
    caseBodyContains($pageLangToggle, 'languageAdminToggleActive('));
ok("manage/languages.php's 'toggle_active' case has NO leftover raw \"UPDATE tblLanguages\"",
    !caseBodyContains($pageLangToggle, 'UPDATE tblLanguages'));

$pageLangDelete = caseBodyFor($languagesPage, '$action', 'delete');
ok("manage/languages.php's 'delete' case delegates to languageAdminUsageCounts(",
    caseBodyContains($pageLangDelete, 'languageAdminUsageCounts('));
ok("manage/languages.php's 'delete' case delegates to languageAdminDelete(",
    caseBodyContains($pageLangDelete, 'languageAdminDelete('));
ok("manage/languages.php's 'delete' case has NO leftover raw \"DELETE FROM tblLanguages\"",
    !caseBodyContains($pageLangDelete, 'DELETE FROM tblLanguages'));

$pageLangRemap = caseBodyFor($languagesPage, '$action', 'remap_tag');
ok("manage/languages.php's 'remap_tag' case delegates to languageAdminRemapPreflight(",
    caseBodyContains($pageLangRemap, 'languageAdminRemapPreflight('));
ok("manage/languages.php's 'remap_tag' case delegates to languageTagRemap( (unchanged ONE write core)",
    caseBodyContains($pageLangRemap, 'languageTagRemap('));
ok("manage/languages.php's 'remap_tag' case has NO leftover direct \"_ietfBcp47Validate(\" call (genuinely re-pointed at the shared preflight)",
    !caseBodyContains($pageLangRemap, '_ietfBcp47Validate('));

/* --- manage/external-link-types.php ('save_type_patterns') --- */
$pageLinkTypeSave = caseBodyFor($externalLinkTypesPage, '$action', 'save_type_patterns');
ok("manage/external-link-types.php's 'save_type_patterns' case delegates to externalLinkTypeAdminFetchAppliesTo(",
    caseBodyContains($pageLinkTypeSave, 'externalLinkTypeAdminFetchAppliesTo('));
ok("manage/external-link-types.php's 'save_type_patterns' case delegates to externalLinkTypeAdminResolveAppliesTo(",
    caseBodyContains($pageLinkTypeSave, 'externalLinkTypeAdminResolveAppliesTo('));
ok("manage/external-link-types.php's 'save_type_patterns' case delegates to externalLinkTypeAdminNormalisePatterns(",
    caseBodyContains($pageLinkTypeSave, 'externalLinkTypeAdminNormalisePatterns('));
ok("manage/external-link-types.php's 'save_type_patterns' case delegates to externalLinkTypeAdminSave(",
    caseBodyContains($pageLinkTypeSave, 'externalLinkTypeAdminSave('));
ok("manage/external-link-types.php's 'save_type_patterns' case has NO leftover raw \"UPDATE tblExternalLinkTypes SET\"",
    !caseBodyContains($pageLinkTypeSave, 'UPDATE tblExternalLinkTypes SET'));
ok("manage/external-link-types.php's 'save_type_patterns' case has NO leftover raw \"INSERT INTO tblExternalLinkPatterns\"",
    !caseBodyContains($pageLinkTypeSave, 'INSERT INTO tblExternalLinkPatterns'));
ok("manage/external-link-types.php's whole-file schema probe delegates to externalLinkTypeAdminSchemaReady( (no leftover hand-typed INFORMATION_SCHEMA probe)",
    strpos($externalLinkTypesPageSrc, 'externalLinkTypeAdminSchemaReady(') !== false);

/* --- manage/print-templates.php ('save'/'clone'/'delete'/'set_default' —
   'import'/'layout_save'/'layout_delete' checked separately below) --- */
$pageSave = caseBodyFor($printTemplatesPage, '$action', 'save');
ok("manage/print-templates.php's 'save' case delegates to printTemplateAdminValidateContent(",
    caseBodyContains($pageSave, 'printTemplateAdminValidateContent('));
ok("manage/print-templates.php's 'save' case delegates to printTemplateAdminCreate( and printTemplateAdminUpdate(",
    caseBodyContains($pageSave, 'printTemplateAdminCreate(') && caseBodyContains($pageSave, 'printTemplateAdminUpdate('));
ok("manage/print-templates.php's 'save' case has NO leftover raw \"INSERT INTO tblPrintTemplates\" (the SAME string legitimately still lives in the untouched 'import' case, which this per-case slice does NOT see)",
    !caseBodyContains($pageSave, 'INSERT INTO tblPrintTemplates'));
ok("manage/print-templates.php's 'save' case has NO leftover raw \"UPDATE tblPrintTemplates SET\"",
    !caseBodyContains($pageSave, 'UPDATE tblPrintTemplates SET'));

$pageClone = caseBodyFor($printTemplatesPage, '$action', 'clone');
ok("manage/print-templates.php's 'clone' case delegates to printTemplateAdminClone(",
    caseBodyContains($pageClone, 'printTemplateAdminClone('));
ok("manage/print-templates.php's 'clone' case has NO leftover raw \"INSERT INTO tblPrintTemplates\"",
    !caseBodyContains($pageClone, 'INSERT INTO tblPrintTemplates'));

$pageDelete = caseBodyFor($printTemplatesPage, '$action', 'delete');
ok("manage/print-templates.php's 'delete' case delegates to printTemplateAdminDelete(",
    caseBodyContains($pageDelete, 'printTemplateAdminDelete('));
ok("manage/print-templates.php's 'delete' case has NO leftover raw \"DELETE FROM tblPrintTemplates\"",
    !caseBodyContains($pageDelete, 'DELETE FROM tblPrintTemplates'));

$pageSetDefault = caseBodyFor($printTemplatesPage, '$action', 'set_default');
ok("manage/print-templates.php's 'set_default' case delegates to printTemplateAdminSetDefault(",
    caseBodyContains($pageSetDefault, 'printTemplateAdminSetDefault('));
ok("manage/print-templates.php's 'set_default' case has NO leftover raw \"UPDATE tblPrintTemplates SET IsDefault\"",
    !caseBodyContains($pageSetDefault, 'UPDATE tblPrintTemplates SET IsDefault'));

/* 'import' is DELIBERATELY out of scope for this batch (paste-JSON, see
   includes/print_template_admin.php's doc-block) and stays untouched — it
   should STILL carry its own raw INSERT and its own direct ptSanitiseBlocks(
   call, proving the absences above are genuine re-pointing and not an
   accidental across-file dead-code deletion that happened to also remove
   the deferred path. */
$pageImport = caseBodyFor($printTemplatesPage, '$action', 'import');
ok("manage/print-templates.php's 'import' case is UNCHANGED (still has its own raw \"INSERT INTO tblPrintTemplates\" — confirms it was deliberately left out of the extraction, not silently broken)",
    caseBodyContains($pageImport, 'INSERT INTO tblPrintTemplates'));
ok("manage/print-templates.php's 'import' case still calls ptSanitiseBlocks( directly (unchanged — the schema rulebook itself was never re-forked, only the tblPrintTemplates scalar-row CRUD was extracted)",
    caseBodyContains($pageImport, 'ptSanitiseBlocks('));

/* 'layout_save'/'layout_delete' were ALREADY delegating to the pre-existing
   print_custom_layout.php core before this batch — this batch's job was
   only to give the API the SAME delegation, never to touch these two page
   cases. Confirms they are untouched (still delegate, never grew their own
   raw write). */
$pageLayoutSave = caseBodyFor($printTemplatesPage, '$action', 'layout_save');
ok("manage/print-templates.php's 'layout_save' case still delegates to printCustomLayoutSave( (pre-existing core, untouched by this batch)",
    caseBodyContains($pageLayoutSave, 'printCustomLayoutSave('));
$pageLayoutDelete = caseBodyFor($printTemplatesPage, '$action', 'layout_delete');
ok("manage/print-templates.php's 'layout_delete' case still delegates to printCustomLayoutDelete( (pre-existing core, untouched by this batch)",
    caseBodyContains($pageLayoutDelete, 'printCustomLayoutDelete('));

ok("manage/print-templates.php's whole-file schema probe delegates to printTemplateAdminTableExists( (no leftover hand-typed INFORMATION_SCHEMA probe)",
    strpos($printTemplatesPageSrc, 'printTemplateAdminTableExists(') !== false);

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

echo "\n{$passed} passed, 0 failed. All 12 API-coverage batch-4b-ii endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, and delegate to their shared cores (new or pre-existing) with no forked validation/write — the layout-save path is proven (not assumed) to reach the HTML sanitiser, and the three sibling pages were genuinely re-pointed at those SAME cores ('import' deliberately left untouched).\n";
exit(0);
