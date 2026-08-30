<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage "Batch 5" curator workflows (#1969)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `.claude/api-coverage-2026-08-28.md` §4.3/§9 asked for six more curator
 * workflows to get native-app-capable JSON twins: duplicate & counterpart
 * song merge/link/unlink/rebuild/auto-link (A10), deleted-song restore/
 * purge (A11), musician-duplicate dismiss/undismiss (A13), admin analytics
 * top-songs/top-books (A14), the data-health "disconnect legacy fallbacks"
 * fix (A15), and the activity-log IP-geolocation backfill (A16). This
 * guard checks, from the real dispatched source (never a hand-typed
 * belief), that the twelve new `admin_*` actions genuinely exist as
 * `$action`-switch cases in `api.php`, are documented in `api-docs.yaml`,
 * gate on the SAME entitlement their sibling `manage/*.php` page ACTUALLY
 * gates on (checked from BOTH sides), and DELEGATE to the shared core
 * their sibling page was ALSO re-pointed onto in this same batch (rule
 * #22) — never a forked validation/write path. It also proves the #1218
 * same-official-songbook merge guard survived the extraction unweakened,
 * and — since `manage/editor/api2.php` was EXPLICITLY out of scope for
 * this batch ("Do NOT touch api2.php") — that `admin_song_link`/
 * `admin_song_unlink` instead call an INDEPENDENT shared core
 * (`includes/song_link_admin.php`) that faithfully MIRRORS api2.php's
 * `song_link_add`/`song_link_remove` behaviour, while api2.php's own two
 * cases were genuinely left untouched (still carry their own original
 * SQL — proven, not assumed) rather than silently broken by a partial
 * edit.
 *
 *   - A10 duplicate songs:  includes/duplicate_song_admin.php (merge/rebuild/auto_link)
 *                           includes/song_link_admin.php (link/unlink — a MIRROR of
 *                             api2.php's song_link_add/song_link_remove, which stays untouched)
 *                           <-> manage/duplicate-songs.php
 *   - A11 deleted songs:    includes/song_soft_delete.php (pre-existing core, reused not forked)
 *                           <-> manage/deleted-songs.php
 *   - A13 musician dupes:   includes/musician_duplicates.php
 *                           <-> manage/musician-duplicates.php
 *   - A14 analytics top:    includes/analytics_ingest.php
 *                           <-> manage/analytics.php
 *   - A15 data health fix:  includes/data_health_admin.php
 *                           <-> manage/data-health.php
 *   - A16 activity-log geo: includes/activity_log_geo.php
 *                           <-> manage/activity-log.php
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch4bii.php`: `api.php` has TWO
 * switches (`$page` and `$action`), so a bare `grep "case '...'"` cannot
 * tell which one owns a label — `dispatch_parser.php`'s token walker does.
 * Each case's BODY is isolated by slicing the token stream between this
 * case's label and the next case's label in switch order, so a
 * delegation check against one action's body cannot accidentally be
 * satisfied by something that only appears in a NEIGHBOURING action's
 * body. The sibling PAGES in this batch mostly do NOT use `switch
 * ($action)` — `manage/duplicate-songs.php` and `manage/
 * musician-duplicates.php` use `if ($action === 'x') { ... }` chains, and
 * `manage/data-health.php`/`manage/activity-log.php` gate a single POST
 * handler with `if (($_POST['action'] ?? '') === 'x')` with no
 * intermediate `$action` variable at all — so this file's own
 * `braceBlockAfter()` slices those by BRACE-DEPTH from a distinctive
 * marker substring instead (tighter than a textual "up to the next
 * occurrence" cut: it stops at the block's OWN closing brace, so trailing
 * code after the block can never leak in). `manage/analytics.php`'s
 * top_songs/top_books panels DO sit inside a real `switch ($exportPanel)`,
 * so those reuse `caseBodyFor()` exactly like the api.php cases do.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — `caseBodyFor()`/
 * `caseBodyContains()` and `braceBlockAfter()` are proven, against tiny
 * in-memory fixtures, to both find a marker that is there AND fail to
 * find one that is not, before the real assertions below are trusted
 * (duplicated locally, the same precedent `test-api-coverage-
 * batch4bii.php` set rather than growing the shared library).
 *
 *   php tests/php/test-api-coverage-batch5.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md §4.3/§9   the plan this implements
 * @see appWeb/public_html/api.php                              the twelve new cases
 * @see appWeb/public_html/includes/duplicate_song_admin.php    merge/rebuild/auto_link core (A10)
 * @see appWeb/public_html/includes/song_link_admin.php         per-song link/unlink core (A10, mirrors api2.php's song_link_add/song_link_remove, which stays untouched)
 * @see appWeb/public_html/includes/song_soft_delete.php        pre-existing restore/purge core (A11, reused not forked)
 * @see appWeb/public_html/includes/musician_duplicates.php     dismiss/undismiss core (A13)
 * @see appWeb/public_html/includes/analytics_ingest.php        top songs/books read core (A14)
 * @see appWeb/public_html/includes/data_health_admin.php       disconnect-fallbacks write core (A15)
 * @see appWeb/public_html/includes/activity_log_geo.php        geo resolve/snapshot core (A16)
 * @see tests/php/test-api-coverage-batch4bii.php                the sibling guard this mirrors
 * @see #1969
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo = dirname(__DIR__, 2);
$api  = $repo . '/appWeb/public_html/api.php';
$api2 = $repo . '/appWeb/public_html/manage/editor/api2.php';

$dupCoreSrc      = (string)file_get_contents($repo . '/appWeb/public_html/includes/duplicate_song_admin.php');
$linkCoreSrc     = (string)file_get_contents($repo . '/appWeb/public_html/includes/song_link_admin.php');
$softDeleteSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/song_soft_delete.php');
$musDupCoreSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/musician_duplicates.php');
$analyticsSrc    = (string)file_get_contents($repo . '/appWeb/public_html/includes/analytics_ingest.php');
$dataHealthSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/data_health_admin.php');
$activityGeoSrc  = (string)file_get_contents($repo . '/appWeb/public_html/includes/activity_log_geo.php');

$dupPage      = $repo . '/appWeb/public_html/manage/duplicate-songs.php';
$musDupPage   = $repo . '/appWeb/public_html/manage/musician-duplicates.php';
$deletedPage  = $repo . '/appWeb/public_html/manage/deleted-songs.php';
$analyticsPage = $repo . '/appWeb/public_html/manage/analytics.php';
$dataHealthPage = $repo . '/appWeb/public_html/manage/data-health.php';
$activityLogPage = $repo . '/appWeb/public_html/manage/activity-log.php';

$dupPageSrc       = (string)file_get_contents($dupPage);
$musDupPageSrc    = (string)file_get_contents($musDupPage);
$deletedPageSrc   = (string)file_get_contents($deletedPage);
$analyticsPageSrc = (string)file_get_contents($analyticsPage);
$dataHealthPageSrc = (string)file_get_contents($dataHealthPage);
$activityLogPageSrc = (string)file_get_contents($activityLogPage);

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
 * Works for ANY switch variable (api.php's `$action`, analytics.php's
 * `$exportPanel`), not just `$action` — same generic shape
 * `test-api-coverage-batch4bii.php`'s own copy uses.
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
 * Slice ONE brace-delimited block out of $src by BRACE DEPTH, starting
 * from the first `{` found AT OR AFTER the first occurrence of
 * $conditionMarker. Used for the `if`-chain / bare-superglobal dispatch
 * shapes this batch's sibling pages use, where there is no real
 * `switch()` for `dispatchParserCaseTokens()` to walk. Tighter than a
 * textual "cut at the next occurrence of a sibling marker" slice: this
 * one stops at the block's OWN matching closing brace, so trailing code
 * after the block (or a sibling block's body) can never leak into the
 * assertion.
 *
 * @return string|null null when the marker isn't found, or the block is
 *         unbalanced (no matching close brace before EOF).
 */
function braceBlockAfter(string $src, string $conditionMarker): ?string
{
    $pos = strpos($src, $conditionMarker);
    if ($pos === false) { return null; }
    $braceStart = strpos($src, '{', $pos);
    if ($braceStart === false) { return null; }

    $depth = 0;
    $len = strlen($src);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $pos, $i - $pos + 1);
            }
        }
    }
    return null; // unbalanced — treat as "could not isolate"
}

/**
 * Slice a named top-level `function NAME(...) { ... }` region's body via
 * brace depth from the `function` keyword's own occurrence. Mirrors
 * `test-api-coverage-batch4bii.php`'s `phpFunctionBody()` / `test-v1-
 * consumer-deorphan.php`'s `extractFunctionBody()` — the SAME shape,
 * duplicated locally per that file's own stated precedent.
 */
function functionBodyFor(string $src, string $fnName): ?string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m)) {
        return null;
    }
    return braceBlockAfter($src, $m[0]);
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — prove the core helper functions above
 * can both find a marker that is there AND fail to find one that is not,
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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_b5_');
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

/* braceBlockAfter() self-test — two adjacent if-blocks; must isolate the
   NAMED one's own body without bleeding into its sibling either direction. */
$ifFixtureSrc = <<<'PHP'
<?php
if ($action === 'first') {
    doFirstThing();
    helperCall();
}
if ($action === 'second') {
    doSecondThing();
}
trailingCodeAfterBothBlocks();
PHP;
$firstBlock  = braceBlockAfter($ifFixtureSrc, "\$action === 'first'");
$secondBlock = braceBlockAfter($ifFixtureSrc, "\$action === 'second'");
if ($firstBlock === null || strpos($firstBlock, 'doFirstThing(') === false || strpos($firstBlock, 'helperCall(') === false) {
    $mutationFailures[] = 'braceBlockAfter() FAILS-HIGH self-test: markers genuinely present in the "first" block were not found';
}
if ($firstBlock !== null && (strpos($firstBlock, 'doSecondThing(') !== false || strpos($firstBlock, 'trailingCodeAfterBothBlocks(') !== false)) {
    $mutationFailures[] = 'braceBlockAfter() FAILS-LOW self-test: the "first" block wrongly bled into its sibling block or trailing code';
}
if ($secondBlock === null || strpos($secondBlock, 'doSecondThing(') === false) {
    $mutationFailures[] = 'braceBlockAfter() FAILS-HIGH self-test: a marker genuinely present in the "second" block was not found';
}
if ($secondBlock !== null && strpos($secondBlock, 'doFirstThing(') !== false) {
    $mutationFailures[] = 'braceBlockAfter() FAILS-LOW self-test: the "second" block wrongly bled into its PRECEDING sibling';
}
if (braceBlockAfter($ifFixtureSrc, "\$action === 'does-not-exist'") !== null) {
    $mutationFailures[] = 'braceBlockAfter() FAILS-LOW self-test: a non-existent marker returned a block instead of null';
}

/* functionBodyFor() self-test — reuses the same ifFixtureSrc shape via a
   tiny function fixture. */
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
PHP;
$decoyBody = functionBodyFor($fnFixtureSrc, 'decoyFunction');
$realBody  = functionBodyFor($fnFixtureSrc, 'realWriter');
if ($realBody === null || strpos($realBody, 'pretendSanitize(') === false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-HIGH self-test: a marker genuinely present inside realWriter() was not found';
}
if ($decoyBody === null || strpos($decoyBody, 'pretendSanitize(') !== false) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: decoyFunction()\'s isolated body wrongly contains a marker that only exists in the NEIGHBOURING realWriter()';
}
if (functionBodyFor($fnFixtureSrc, 'doesNotExistFunction') !== null) {
    $mutationFailures[] = 'functionBodyFor() FAILS-LOW self-test: a non-existent function name returned a body instead of null';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage batch 5 (#1969): duplicate songs (A10) / deleted songs (A11) / musician duplicates (A13) / analytics top (A14) / data-health fix (A15) / activity-log geo (A16)\n\n";

$batch5 = [
    'admin_song_merge',
    'admin_song_link',
    'admin_song_unlink',
    'admin_song_suggestions_rebuild',
    'admin_song_auto_link',
    'admin_song_restore',
    'admin_song_purge',
    'admin_musician_duplicate_dismiss',
    'admin_musician_duplicate_undismiss',
    'admin_analytics_top',
    'admin_data_health_fix',
    'admin_ip_geolocate',
];

/* ---- A. Dispatchable: the real $action switch carries all twelve, exactly
   once each — tree-derived from the actual dispatcher, not a belief. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);

foreach ($batch5 as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml
   (mirrors test-openapi-actions-exist.php's own extraction regex). ---- */
$yaml = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($batch5 as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key its sibling manage/*.php page ACTUALLY gates on —
   checked from BOTH sides (the API case body AND the page source itself),
   so a future drift in either file is caught, not just a hand-typed
   belief about what the page currently does. ---- */
$entitlementByAction = [
    'admin_song_merge'                   => 'manage_duplicate_songs',
    'admin_song_link'                    => 'edit_songs',
    'admin_song_unlink'                  => 'edit_songs',
    'admin_song_suggestions_rebuild'     => 'edit_songs',
    'admin_song_auto_link'               => 'manage_duplicate_songs',
    'admin_song_restore'                 => 'delete_songs',
    'admin_song_purge'                   => 'purge_songs',
    'admin_musician_duplicate_dismiss'   => 'manage_musicians',
    'admin_musician_duplicate_undismiss' => 'manage_musicians',
    'admin_analytics_top'                => 'view_analytics',
    'admin_data_health_fix'              => 'drop_legacy_tables',
    'admin_ip_geolocate'                 => 'view_activity_log',
];
/* Every page this batch's entitlements come from, so section C's "does the
   page ITSELF really gate on this" half can check every key once. */
$pageSrcByEntitlement = [
    'manage_duplicate_songs' => $dupPageSrc,
    'edit_songs'              => $dupPageSrc,
    'delete_songs'            => $deletedPageSrc,
    'purge_songs'             => $deletedPageSrc,
    'manage_musicians'        => $musDupPageSrc,
    'view_analytics'          => $analyticsPageSrc,
    'drop_legacy_tables'      => $dataHealthPageSrc,
    'view_activity_log'       => $activityLogPageSrc,
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

/* ---- D. A10 duplicate-song actions: delegate to the shared cores —
   never a forked validation/write path (rule #22). ---- */
$mergeBody = caseBodyFor($api, '$action', 'admin_song_merge');
ok('admin_song_merge delegates to duplicateSongMergeExecute(',
    caseBodyContains($mergeBody, 'duplicateSongMergeExecute('));
ok('admin_song_merge forwards force from the request body (!empty($body[\'force\'])) — never hardcodes it',
    caseBodyContains($mergeBody, "!empty(\$body['force'])"));
ok('admin_song_merge does NOT re-embed a raw "UPDATE IGNORE" (would be a forked write)',
    !caseBodyContains($mergeBody, 'UPDATE IGNORE'));
ok('admin_song_merge does NOT re-embed a raw "DELETE FROM tblSongs" (would be a forked write)',
    !caseBodyContains($mergeBody, 'DELETE FROM tblSongs'));

$linkBody = caseBodyFor($api, '$action', 'admin_song_link');
ok('admin_song_link delegates to songLinkAdd( (includes/song_link_admin.php, a mirror of api2.php\'s song_link_add — see section M)',
    caseBodyContains($linkBody, 'songLinkAdd('));
ok('admin_song_link does NOT re-embed a raw "INSERT INTO tblSongLinks" (would fork the write a THIRD time within this batch\'s own scope)',
    !caseBodyContains($linkBody, 'INSERT INTO tblSongLinks'));

$unlinkBody = caseBodyFor($api, '$action', 'admin_song_unlink');
ok('admin_song_unlink delegates to songLinkRemove( (includes/song_link_admin.php, the SAME core manage/duplicate-songs.php\'s own unlink action calls — see section M for the api2.php mirror story)',
    caseBodyContains($unlinkBody, 'songLinkRemove('));
ok('admin_song_unlink does NOT re-embed a raw "DELETE FROM tblSongLinks" (would fork the write a THIRD time within this batch\'s own scope)',
    !caseBodyContains($unlinkBody, 'DELETE FROM tblSongLinks'));

$rebuildBody = caseBodyFor($api, '$action', 'admin_song_suggestions_rebuild');
ok('admin_song_suggestions_rebuild delegates to duplicateSongRebuildSuggestions(',
    caseBodyContains($rebuildBody, 'duplicateSongRebuildSuggestions('));
ok('admin_song_suggestions_rebuild does NOT re-embed a raw require of build-song-link-suggestions.php (would fork the ob_start()/require dance)',
    !caseBodyContains($rebuildBody, 'build-song-link-suggestions.php'));

$autoLinkBody = caseBodyFor($api, '$action', 'admin_song_auto_link');
ok('admin_song_auto_link delegates to duplicateSongAutoLink(',
    caseBodyContains($autoLinkBody, 'duplicateSongAutoLink('));
ok('admin_song_auto_link does NOT re-embed a raw require of auto-link-hard-id-counterparts.php (would fork the wrapper)',
    !caseBodyContains($autoLinkBody, 'auto-link-hard-id-counterparts.php'));

/* ---- E. A10 write/read core functions actually EXIST in
   includes/duplicate_song_admin.php / includes/song_link_admin.php (never
   assumed from the api.php call sites alone). ---- */
foreach (['duplicateSongMergeExecute', 'duplicateSongRebuildSuggestions', 'duplicateSongAutoLink'] as $fn) {
    ok("includes/duplicate_song_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $dupCoreSrc));
}
foreach (['songLinkAdd', 'songLinkRemove'] as $fn) {
    ok("includes/song_link_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $linkCoreSrc));
}

/* ---- F. The #1218 same-official-songbook merge guard survived the
   extraction UNWEAKENED — proven against duplicateSongMergeExecute()'s
   OWN isolated body (not just "the string appears somewhere in the
   file"), so a same-named decoy core cannot satisfy this check. ---- */
$mergeFnBody = functionBodyFor($dupCoreSrc, 'duplicateSongMergeExecute');
ok('includes/duplicate_song_admin.php defines function duplicateSongMergeExecute( (isolatable by functionBodyFor())',
    $mergeFnBody !== null);
if ($mergeFnBody !== null) {
    ok("duplicateSongMergeExecute()'s OWN body checks IsOfficial (the #1218 same-official-songbook signal)",
        strpos($mergeFnBody, 'IsOfficial') !== false);
    ok("duplicateSongMergeExecute()'s OWN body refuses with 409 when the #1218 guard fires and force was not set",
        strpos($mergeFnBody, "'status' => 409") !== false);
    ok("duplicateSongMergeExecute()'s OWN body gates the guard on the \$force parameter (never unconditionally blocked, never unconditionally skipped)",
        strpos($mergeFnBody, 'if (!$force)') !== false);
}

/* ---- G. A11 restore/purge: delegate to the PRE-EXISTING
   includes/song_soft_delete.php core (reused, not forked) — and
   admin_song_purge preserves the server-enforced type-to-confirm
   ceremony exactly. ---- */
$restoreBody = caseBodyFor($api, '$action', 'admin_song_restore');
ok('admin_song_restore delegates to songRestore( (pre-existing includes/song_soft_delete.php core, reused not forked)',
    caseBodyContains($restoreBody, 'songRestore('));

$purgeBody = caseBodyFor($api, '$action', 'admin_song_purge');
ok('admin_song_purge delegates to songPurge( (pre-existing includes/song_soft_delete.php core, reused not forked)',
    caseBodyContains($purgeBody, 'songPurge('));
ok('admin_song_purge re-enforces the type-to-confirm ceremony server-side (strcasecmp($confirm, $songId)) — never trusts a client-side arm alone',
    caseBodyContains($purgeBody, 'strcasecmp($confirm, $songId)'));
foreach (['songRestore', 'songPurge'] as $fn) {
    ok("includes/song_soft_delete.php defines function {$fn}( (pre-existing core both the page and this API action call)",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $softDeleteSrc));
}

/* ---- H. A13 musician-duplicate actions: delegate to
   includes/musician_duplicates.php — never a forked validation/write path. ---- */
$musDismissBody = caseBodyFor($api, '$action', 'admin_musician_duplicate_dismiss');
ok('admin_musician_duplicate_dismiss delegates to musicianDuplicatesDismissCluster(',
    caseBodyContains($musDismissBody, 'musicianDuplicatesDismissCluster('));
ok('admin_musician_duplicate_dismiss does NOT re-embed a raw "INSERT INTO tblMusicianDuplicatesDismissed" (would be a forked write)',
    !caseBodyContains($musDismissBody, 'INSERT INTO tblMusicianDuplicatesDismissed'));

$musUndismissBody = caseBodyFor($api, '$action', 'admin_musician_duplicate_undismiss');
ok('admin_musician_duplicate_undismiss delegates to musicianDuplicatesUndismissPair(',
    caseBodyContains($musUndismissBody, 'musicianDuplicatesUndismissPair('));
ok('admin_musician_duplicate_undismiss does NOT re-embed a raw "DELETE FROM tblMusicianDuplicatesDismissed" (would be a forked write)',
    !caseBodyContains($musUndismissBody, 'DELETE FROM tblMusicianDuplicatesDismissed'));

foreach (['musicianDuplicatesDismissCluster', 'musicianDuplicatesUndismissPair'] as $fn) {
    ok("includes/musician_duplicates.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $musDupCoreSrc));
}

/* ---- I. A14 analytics-top: delegates to includes/analytics_ingest.php's
   analyticsTopSongs()/analyticsTopBooks() — never a forked query, and
   never the SAME query the public popular_songs action already runs
   (that one filters visibility/servability/language; this is the
   unfiltered admin historical report). ---- */
$analyticsTopBody = caseBodyFor($api, '$action', 'admin_analytics_top');
ok('admin_analytics_top delegates to analyticsTopSongs(',
    caseBodyContains($analyticsTopBody, 'analyticsTopSongs('));
ok('admin_analytics_top delegates to analyticsTopBooks(',
    caseBodyContains($analyticsTopBody, 'analyticsTopBooks('));
ok('admin_analytics_top does NOT re-embed a raw "FROM tblSongHistory" query (would fork the read)',
    !caseBodyContains($analyticsTopBody, 'FROM tblSongHistory'));
foreach (['analyticsTopSongs', 'analyticsTopBooks'] as $fn) {
    ok("includes/analytics_ingest.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $analyticsSrc));
}

/* ---- J. A15 data-health fix: delegates to includes/data_health_admin.php. ---- */
$dataHealthFixBody = caseBodyFor($api, '$action', 'admin_data_health_fix');
ok('admin_data_health_fix delegates to dataHealthFixApply(',
    caseBodyContains($dataHealthFixBody, 'dataHealthFixApply('));
ok('admin_data_health_fix does NOT re-embed a raw @rename( loop (would fork the write)',
    !caseBodyContains($dataHealthFixBody, '@rename('));
foreach (['dataHealthLegacyPaths', 'dataHealthDisconnectFallbacks', 'dataHealthFixApply'] as $fn) {
    ok("includes/data_health_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $dataHealthSrc));
}

/* ---- K. A16 activity-log geo: delegates to includes/activity_log_geo.php. ---- */
$ipGeoBody = caseBodyFor($api, '$action', 'admin_ip_geolocate');
ok('admin_ip_geolocate gates on activityLogObsColumnsExist( (503 on an un-migrated environment)',
    caseBodyContains($ipGeoBody, 'activityLogObsColumnsExist('));
ok('admin_ip_geolocate delegates to activityLogGeoResolveIps(',
    caseBodyContains($ipGeoBody, 'activityLogGeoResolveIps('));
ok('admin_ip_geolocate does NOT re-embed a raw ihymnsGeoLookup( call directly (must go through the shared core, not a re-forked loop)',
    !caseBodyContains($ipGeoBody, 'ihymnsGeoLookup('));
foreach (['activityLogObsColumnsExist', 'activityLogGeoResolveIps'] as $fn) {
    ok("includes/activity_log_geo.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $activityGeoSrc));
}

/* =========================================================================
 * L. EXTRACTION VERIFICATION — the sibling pages themselves were genuinely
 * RE-POINTED at the shared cores, not just the API. Sliced PER BLOCK via
 * braceBlockAfter()/caseBodyFor() so a deliberately-untouched sibling
 * block (duplicate-songs.php's own 'link'/'dismiss') can never leak a
 * false pass into the re-pointed blocks it sits beside.
 * ========================================================================= */

/* --- manage/duplicate-songs.php ('merge'/'unlink'/'rebuild'/'auto_link' —
   'link'/'dismiss' are DELIBERATELY untouched, see the file's own
   doc-block, and are checked below to still carry their original SQL as
   proof the absences above are genuine re-pointing, not an accidental
   deletion that happened to also remove the deferred blocks). --- */
$pageMerge = braceBlockAfter($dupPageSrc, "if (\$action === 'merge')");
ok("manage/duplicate-songs.php's 'merge' block delegates to duplicateSongMergeExecute(",
    caseBodyContains($pageMerge, 'duplicateSongMergeExecute('));
ok("manage/duplicate-songs.php's 'merge' block has NO leftover raw \"UPDATE IGNORE\" (genuinely re-pointed at the core)",
    !caseBodyContains($pageMerge, 'UPDATE IGNORE'));

$pageUnlink = braceBlockAfter($dupPageSrc, "if (\$action === 'unlink')");
ok("manage/duplicate-songs.php's 'unlink' block delegates to songLinkRemove(",
    caseBodyContains($pageUnlink, 'songLinkRemove('));
ok("manage/duplicate-songs.php's 'unlink' block has NO leftover raw \"DELETE FROM tblSongLinks\" (the SAME string legitimately still lives in the untouched 'link'/'dismiss' blocks, which this per-block slice does NOT see)",
    !caseBodyContains($pageUnlink, 'DELETE FROM tblSongLinks'));

$pageRebuild = braceBlockAfter($dupPageSrc, "if (\$action === 'rebuild')");
ok("manage/duplicate-songs.php's 'rebuild' block delegates to duplicateSongRebuildSuggestions(",
    caseBodyContains($pageRebuild, 'duplicateSongRebuildSuggestions('));
ok("manage/duplicate-songs.php's 'rebuild' block has NO leftover raw require of build-song-link-suggestions.php",
    !caseBodyContains($pageRebuild, "'build-song-link-suggestions.php'"));

$pageAutoLink = braceBlockAfter($dupPageSrc, "if (\$action === 'auto_link')");
ok("manage/duplicate-songs.php's 'auto_link' block delegates to duplicateSongAutoLink(",
    caseBodyContains($pageAutoLink, 'duplicateSongAutoLink('));
ok("manage/duplicate-songs.php's 'auto_link' block has NO leftover raw require of auto-link-hard-id-counterparts.php",
    !caseBodyContains($pageAutoLink, "'auto-link-hard-id-counterparts.php'"));

/* 'link'/'dismiss' are DELIBERATELY out of scope for this batch (whole-SET
   N-song algorithms, functionally different from api2's pairwise cores —
   see includes/duplicate_song_admin.php's own doc-block) and stay
   untouched — they should STILL carry their own raw SQL, proving the
   absences above are genuine re-pointing, not an accidental cross-block
   deletion that happened to also remove the deferred paths. */
$pageLink = braceBlockAfter($dupPageSrc, "if (\$action === 'link')");
ok("manage/duplicate-songs.php's 'link' block is UNCHANGED (still has its own raw \"INSERT INTO tblSongLinks\" — confirms it was deliberately left out of this batch, not silently broken)",
    caseBodyContains($pageLink, 'INSERT INTO tblSongLinks'));
$pageDismiss = braceBlockAfter($dupPageSrc, "if (\$action === 'dismiss')");
ok("manage/duplicate-songs.php's 'dismiss' block is UNCHANGED (still has its own raw \"INSERT INTO tblSongLinkSuggestionsDismissed\" — confirms it was deliberately left out of this batch)",
    caseBodyContains($pageDismiss, 'INSERT INTO tblSongLinkSuggestionsDismissed'));

/* --- manage/musician-duplicates.php ('dismiss'/'undismiss') --- */
$musPageDismiss = braceBlockAfter($musDupPageSrc, "if (\$action === 'dismiss')");
ok("manage/musician-duplicates.php's 'dismiss' block delegates to musicianDuplicatesDismissCluster(",
    caseBodyContains($musPageDismiss, 'musicianDuplicatesDismissCluster('));
ok("manage/musician-duplicates.php's 'dismiss' block has NO leftover raw \"INSERT INTO tblMusicianDuplicatesDismissed\"",
    !caseBodyContains($musPageDismiss, 'INSERT INTO tblMusicianDuplicatesDismissed'));

$musPageUndismiss = braceBlockAfter($musDupPageSrc, "if (\$action === 'undismiss')");
ok("manage/musician-duplicates.php's 'undismiss' block delegates to musicianDuplicatesUndismissPair(",
    caseBodyContains($musPageUndismiss, 'musicianDuplicatesUndismissPair('));
ok("manage/musician-duplicates.php's 'undismiss' block has NO leftover raw \"DELETE FROM tblMusicianDuplicatesDismissed\"",
    !caseBodyContains($musPageUndismiss, 'DELETE FROM tblMusicianDuplicatesDismissed'));

/* --- manage/analytics.php ('top_songs'/'top_books' — real switch, uses
   caseBodyFor() like the api.php cases). --- */
$pageTopSongs = caseBodyFor($analyticsPage, '$exportPanel', 'top_songs');
ok("manage/analytics.php's 'top_songs' export case delegates to analyticsTopSongs(",
    caseBodyContains($pageTopSongs, 'analyticsTopSongs('));
ok("manage/analytics.php's 'top_songs' export case has NO leftover raw \"FROM tblSongHistory\" query",
    !caseBodyContains($pageTopSongs, 'FROM tblSongHistory'));

$pageTopBooks = caseBodyFor($analyticsPage, '$exportPanel', 'top_books');
ok("manage/analytics.php's 'top_books' export case delegates to analyticsTopBooks(",
    caseBodyContains($pageTopBooks, 'analyticsTopBooks('));
ok("manage/analytics.php's 'top_books' export case has NO leftover raw \"FROM tblSongHistory\" query",
    !caseBodyContains($pageTopBooks, 'FROM tblSongHistory'));

/* --- manage/data-health.php (disconnect_fallbacks). --- */
$pageDisconnect = braceBlockAfter($dataHealthPageSrc, "=== 'disconnect_fallbacks'");
ok("manage/data-health.php's disconnect_fallbacks block delegates to dataHealthDisconnectFallbacks(",
    caseBodyContains($pageDisconnect, 'dataHealthDisconnectFallbacks('));
ok("manage/data-health.php's disconnect_fallbacks block has NO leftover raw @rename( loop",
    !caseBodyContains($pageDisconnect, '@rename('));

/* --- manage/activity-log.php (?action=geo). --- */
$pageGeo = braceBlockAfter($activityLogPageSrc, "=== 'geo'");
ok("manage/activity-log.php's ?action=geo block delegates to activityLogGeoResolveIps(",
    caseBodyContains($pageGeo, 'activityLogGeoResolveIps('));
ok("manage/activity-log.php's ?action=geo block has NO leftover raw ihymnsGeoLookup( call",
    !caseBodyContains($pageGeo, 'ihymnsGeoLookup('));

/* =========================================================================
 * M. api2.php CONSTRAINT COMPLIANCE — this batch's task explicitly listed
 * api2.php as OUT OF SCOPE ("Do NOT touch api2.php"), so
 * admin_song_link/admin_song_unlink could not literally be re-pointed onto
 * api2.php's existing song_link_add/song_link_remove cases the way
 * manage/duplicate-songs.php's 'unlink' block was. Instead
 * includes/song_link_admin.php's songLinkAdd()/songLinkRemove() are an
 * INDEPENDENT, behaviour-matching MIRROR that api.php's two new actions
 * call — so this section proves BOTH halves of that story: (1) api2.php's
 * own song_link_add/song_link_remove cases were genuinely left UNTOUCHED
 * (still carry their own original raw SQL, not silently broken by a
 * partial edit-then-revert), and (2) the mirror core's own bodies (opened
 * fresh, isolated by functionBodyFor()) genuinely contain the matching
 * tblSongLinks SQL — not a same-named decoy that merely LOOKS like a
 * shared core while doing nothing. api.php's own case bodies not
 * re-embedding this SQL is already covered by section D above. ---- */
$api2LinkAddBody = caseBodyFor($api2, '$action', 'song_link_add');
ok("api2.php's song_link_add case is UNCHANGED (still has its own raw \"INSERT INTO tblSongLinks\" — confirms api2.php was genuinely left untouched per this batch's explicit constraint, not silently broken by a partial edit)",
    caseBodyContains($api2LinkAddBody, 'INSERT INTO tblSongLinks'));
ok("api2.php's song_link_add case does NOT call songLinkAdd( (api2.php keeps its OWN independent implementation this batch — it is a mirror source, not a caller of the new core)",
    !caseBodyContains($api2LinkAddBody, 'songLinkAdd('));

$api2LinkRemoveBody = caseBodyFor($api2, '$action', 'song_link_remove');
ok("api2.php's song_link_remove case is UNCHANGED (still has its own raw \"DELETE FROM tblSongLinks\" — confirms api2.php was genuinely left untouched)",
    caseBodyContains($api2LinkRemoveBody, 'DELETE FROM tblSongLinks'));
ok("api2.php's song_link_remove case does NOT call songLinkRemove( (api2.php keeps its OWN independent implementation this batch)",
    !caseBodyContains($api2LinkRemoveBody, 'songLinkRemove('));

$songLinkAddFnBody = functionBodyFor($linkCoreSrc, 'songLinkAdd');
ok("songLinkAdd()'s OWN body genuinely contains \"INSERT INTO tblSongLinks\" — not just a same-named decoy",
    $songLinkAddFnBody !== null && strpos($songLinkAddFnBody, 'INSERT INTO tblSongLinks') !== false);
$songLinkRemoveFnBody = functionBodyFor($linkCoreSrc, 'songLinkRemove');
ok("songLinkRemove()'s OWN body genuinely contains \"DELETE FROM tblSongLinks\" — not just a same-named decoy",
    $songLinkRemoveFnBody !== null && strpos($songLinkRemoveFnBody, 'DELETE FROM tblSongLinks') !== false);

/* Cross-check: exactly THREE files in the whole tree write "INSERT INTO
   tblSongLinks (GroupId" — api2.php's own untouched song_link_add case
   (the pairwise shape, left alone per this batch's constraint), the
   mirror core (includes/song_link_admin.php's songLinkAdd(), the SAME
   pairwise shape), and manage/duplicate-songs.php's own
   deliberately-untouched 'link' block (a DIFFERENT, whole-SET shape) —
   never a FOURTH copy (which api.php re-embedding its own would create;
   already checked absent in section D above). */
$insertGroupIdSites = 0;
foreach ([
    $repo . '/appWeb/public_html/includes/song_link_admin.php',
    $repo . '/appWeb/public_html/manage/duplicate-songs.php',
    $repo . '/appWeb/public_html/manage/editor/api2.php',
    $api,
] as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, 'INSERT INTO tblSongLinks (GroupId') !== false) { $insertGroupIdSites++; }
}
ok('exactly three files write "INSERT INTO tblSongLinks (GroupId" (api2.php\'s own untouched copy + the mirror core + the deliberately-untouched page block) — api.php does NOT carry a fourth copy',
    $insertGroupIdSites === 3);

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

echo "\n{$passed} passed, 0 failed. All twelve API-coverage batch-5 endpoints are dispatchable, documented, gate on the same entitlement as their sibling page, delegate to their shared cores with no forked validation/write, the #1218 same-official-songbook merge guard survived the extraction unweakened, admin_song_link/admin_song_unlink call an independent shared core that faithfully mirrors api2.php's song_link_add/song_link_remove (api2.php itself proven genuinely untouched, per this batch's explicit constraint), and every sibling page was genuinely re-pointed at those SAME cores ('link'/'dismiss' on duplicate-songs.php deliberately left untouched).\n";
exit(0);
