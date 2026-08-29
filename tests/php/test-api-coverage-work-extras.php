<?php

declare(strict_types=1);

/**
 * iHymns — API-coverage: Works "extras" (#1988, epic #1983)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * #1988 closed the LAST gap in `/manage/works`'s own capabilities: the
 * `#1741 P1/D5` "extra" fields (subtitle/CCLI/BOWI/tune name/copyright/…),
 * the places-adoption composition-origin mirror, the song-membership
 * (`tblWorkSongs`) list, and the external-links (`tblWorkExternalLinks`)
 * list were all page-only. This guard checks, from the real dispatched
 * source (not a hand-typed belief), that `admin_work_create`/
 * `admin_work_update` now accept the extras+origin_city keys and that the
 * two NEW `admin_work_members_replace`/`admin_work_external_links_replace`
 * actions genuinely exist, gate on the SAME entitlement
 * `manage/works.php` itself gates on, are documented in `api-docs.yaml`,
 * and — the part a mere "does the endpoint respond" smoke test cannot
 * see — DELEGATE to the shared cores `manage/works.php` itself now
 * one-line-delegates to (rule #22), rather than forking a second copy of
 * the validation/write.
 *
 * WHY TREE-DERIVED (rule #34)
 * ----------------------------------------------------------------------------
 * Same reasoning as `test-api-coverage-batch4a.php`, which this file is
 * modelled on (case-body isolation over `tests/php/lib/dispatch_parser.php`
 * — `api.php` has TWO switches, `$page` and `$action`, and the
 * `admin_work_*` cases sit directly ADJACENT to each other, so a bare
 * substring search risks one case's body bleeding into its neighbour's
 * assertion). The `manage_works` entitlement KEY itself is EXTRACTED from
 * `manage/works.php`'s own page gate — never hand-typed here — so a future
 * rename of the entitlement is caught on BOTH sides at once, not just
 * silently agreed with by construction.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * (1) `caseBodyFor()`/`caseBodyContains()` are proven, against tiny
 *     in-memory token-shaped fixtures assembled with PHP's own
 *     `token_get_all()`, to both find a marker that is there and fail to
 *     find one that is not — duplicated locally rather than imported,
 *     the SAME precedent `test-api-coverage-batch4a.php` /
 *     `test-api-coverage-batch1.php` / `test-api-coverage-batch3.php` set
 *     (this file follows that shape rather than growing a fifth copy in
 *     the shared library). This layer runs on EVERY invocation.
 * (2) A REAL on-tree mutation (delete one delegation call from a SCRATCH
 *     COPY of `api.php` — never the live tree — re-run this file's own
 *     checking logic against the mutated copy, confirm RED, then discard
 *     the scratch copy) was performed once per assertion GROUP while
 *     building this guard, mirroring `test-manage-action-api-coverage.php`'s
 *     own documented protocol (its doc-block explains why that one-time
 *     exercise is NOT baked into the standing runtime file — repeating a
 *     live edit-run-revert cycle against real page sources on every CI run
 *     would make the suite mutate files on every invocation, which is not
 *     what a standing guard should do). Results are reported in the
 *     session/commit that introduced this file.
 *
 *   php tests/php/test-api-coverage-work-extras.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @link .claude/api-coverage-2026-08-28.md              the API-coverage program epic #1988 closes a gap in
 * @see appWeb/public_html/api.php                        admin_work_create/_update (extended) + the two NEW actions
 * @see appWeb/public_html/includes/work_admin.php        workParseExtraFields()/workCheckExtraUniqueness()/workPersistExtraFields()/workPersistOriginCity()/workParentCycleSafe()/workSongsReplace()
 * @see appWeb/public_html/includes/external_link_helpers.php saveExternalLinksForRow()/loadExternalLinksForRow()
 * @see appWeb/public_html/manage/works.php               the page every one of these cores now delegates FROM too
 * @see tests/php/test-api-coverage-batch4a.php            the sibling guard this mirrors (structure, NOT content — batch4a stays frozen)
 * @see #1988 #1983
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo         = dirname(__DIR__, 2);
$api          = $repo . '/appWeb/public_html/api.php';
$apiSrc       = (string)file_get_contents($api);
$workAdminSrc = (string)file_get_contents($repo . '/appWeb/public_html/includes/work_admin.php');
$extLinkSrc   = (string)file_get_contents($repo . '/appWeb/public_html/includes/external_link_helpers.php');
$worksPageSrc = (string)file_get_contents($repo . '/appWeb/public_html/manage/works.php');
$yaml         = (string)file_get_contents($repo . '/appWeb/public_html/api-docs.yaml');

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
 * from an already-tokenised file. Duplicated from test-api-coverage-batch4a.php
 * (that file's own doc-block explains why: the sibling guards each keep a
 * local copy rather than growing dispatch_parser.php with a fifth shape).
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
 * Strip `//` and `/* … *\/` PHP comments — a crude but adequate pass for the
 * whole-file NEGATIVE fork-ban checks below (a literal SQL string mentioned
 * only in a doc-comment must not count as "found"). Does not attempt to
 * understand string literals containing comment-marker-shaped text — no
 * real line in this file's target sources does.
 */
function stripPhpComments(string $src): string
{
    $out = preg_replace('#/\*.*?\*/#s', '', $src);
    $out = preg_replace('#(?<!:)//[^\n]*#', '', (string)$out);
    return (string)$out;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34), layer 1 — prove the two core parsing
 * functions can both find a marker that is there and fail to find one that
 * is not, against small real-tokeniser fixtures. Runs on EVERY invocation.
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
$fixtureFile = tempnam(sys_get_temp_dir(), 'ihymns_dispatch_fixture_workextras_');
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

/* --- stripPhpComments(): a needle inside a comment must vanish; real code
   must survive. --- */
$commentFixture = "/* mentions INSERT INTO tblWorkSongs in prose */\n\$x = 'INSERT INTO tblRealCode';\n// also DELETE FROM tblWorkSongs here\n\$y = 1;";
$stripped = stripPhpComments($commentFixture);
if (strpos($stripped, 'INSERT INTO tblWorkSongs') !== false) {
    $mutationFailures[] = 'stripPhpComments() FAILS-HIGH self-test: a needle inside a /* */ comment survived stripping';
}
if (strpos($stripped, 'DELETE FROM tblWorkSongs') !== false) {
    $mutationFailures[] = 'stripPhpComments() FAILS-HIGH self-test: a needle inside a // comment survived stripping';
}
if (strpos($stripped, "INSERT INTO tblRealCode") === false || strpos($stripped, '$y = 1;') === false) {
    $mutationFailures[] = 'stripPhpComments() FAILS-LOW self-test: real code outside any comment was wrongly stripped too';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

echo "\nAPI-coverage: Works \"extras\" (#1988)\n\n";

/* ---- A0. The manage_works entitlement key is EXTRACTED from
   manage/works.php's own page gate — never hand-typed here, so a future
   rename is caught on both sides at once. ---- */
preg_match(
    "/if\\s*\\(!\\\$currentUser \\|\\| !userHasEntitlement\\('([a-z_]+)'/",
    $worksPageSrc,
    $entMatch
);
$worksEntitlement = $entMatch[1] ?? null;
ok('manage/works.php\'s own page gate entitlement key was extracted (not hand-typed)', $worksEntitlement !== null);
$worksEntitlement = $worksEntitlement ?? 'manage_works'; // fallback keeps the rest of this file runnable if extraction ever breaks — assertion above already failed loudly

$workExtras = [
    'admin_work_create',
    'admin_work_update',
    'admin_work_members_replace',
    'admin_work_external_links_replace',
];

/* ---- A. Dispatchable: the real $action switch carries all four, exactly
   once each. admin_work_create/_update are PRE-EXISTING (batch 4a); the
   other two are NEW. ---- */
$actionCases  = dispatchParserCasesForSwitch($api, '$action');
$actionCounts = array_count_values($actionCases);
foreach ($workExtras as $name) {
    ok("'{$name}' is a real \$action case in api.php (found " . ($actionCounts[$name] ?? 0) . " time(s))",
        ($actionCounts[$name] ?? 0) === 1);
}

/* ---- B. Documented: each has a top-level path item in api-docs.yaml. ---- */
preg_match_all('/^  \/[^\s:]*\?action=([a-z0-9_]+):/mi', $yaml, $m);
$documented = array_flip($m[1] ?? []);
foreach ($workExtras as $name) {
    ok("'{$name}' has a top-level path item in api-docs.yaml", isset($documented[$name]));
}

/* Comment-stripped case bodies for EVERY check below (C onward) — not just
   the negative fork-bans. A mutation run while building this guard proved
   why: this file's own doc-comment on the members_replace case's
   parse/clamp block PROSE-mentions "workSongsReplace()" ("...stays HERE
   per caller, same as the page — workSongsReplace() owns only..."), which
   falsely satisfies a raw (non-comment-stripped) caseBodyContains() check
   even after the REAL delegation call is deleted — exactly the "a comment
   must never satisfy an assertion" trap this codebase's own guards
   (test-native-identity-contract.js et al.) warn about. Anchoring every
   check on the CODE, not the prose, closes that gap for good rather than
   relying on nobody ever writing a similarly-worded comment again. */
function caseCode(string $file, string $switchVar, string $name): ?string
{
    $body = caseBodyFor($file, $switchVar, $name);
    return $body !== null ? stripPhpComments($body) : null;
}

/* ---- C. Every action gates via userHasEntitlement() on the SAME
   entitlement key manage/works.php's own page gates on. ---- */
foreach ($workExtras as $name) {
    $code = caseCode($api, '$action', $name);
    ok("'{$name}' gates via userHasEntitlement('{$worksEntitlement}'",
        caseBodyContains($code, "userHasEntitlement('{$worksEntitlement}'"));
}

/* ---- D. admin_work_create/_update: delegate to the extras/origin-city
   write cores (includes/work_admin.php) — never a forked copy. ---- */
$createCode = caseCode($api, '$action', 'admin_work_create');
foreach ([
    'workParseExtraFields(', 'workCheckExtraUniqueness(', 'workPersistExtraFields(',
    'workPersistOriginCity(', 'workExtraColumnsPresent(',
] as $fn) {
    ok("admin_work_create delegates to {$fn}", caseBodyContains($createCode, $fn));
}
ok('admin_work_create does NOT delegate to workParentCycleSafe( (create has no parent-cycle concern — nothing to re-parent against yet)',
    !caseBodyContains($createCode, 'workParentCycleSafe('));
ok('admin_work_create does NOT delegate to workSongsReplace( (membership is the separate admin_work_members_replace action)',
    !caseBodyContains($createCode, 'workSongsReplace('));

$updateCode = caseCode($api, '$action', 'admin_work_update');
foreach ([
    'workParseExtraFields(', 'workCheckExtraUniqueness(', 'workPersistExtraFields(',
    'workPersistOriginCity(', 'workExtraColumnsPresent(', 'workParentCycleSafe(',
] as $fn) {
    ok("admin_work_update delegates to {$fn}", caseBodyContains($updateCode, $fn));
}
ok("admin_work_update applies the key-present-preserve carry via array_key_exists( over the request body",
    caseBodyContains($updateCode, 'array_key_exists('));
ok('admin_work_update does NOT delegate to workSongsReplace( (membership is the separate admin_work_members_replace action)',
    !caseBodyContains($updateCode, 'workSongsReplace('));
ok('admin_work_update does NOT delegate to saveExternalLinksForRow( (links are the separate admin_work_external_links_replace action)',
    !caseBodyContains($updateCode, 'saveExternalLinksForRow('));

/* ---- E. admin_work_members_replace: delegates to workSongsReplace() —
   never a forked DELETE+INSERT. ---- */
$membersCode = caseCode($api, '$action', 'admin_work_members_replace');
ok('admin_work_members_replace delegates to workSongsReplace( (includes/work_admin.php)',
    caseBodyContains($membersCode, 'workSongsReplace('));
ok('admin_work_members_replace delegates to workExists( (the Work itself must exist)',
    caseBodyContains($membersCode, 'workExists('));
ok('admin_work_members_replace does NOT re-embed a raw "INSERT INTO tblWorkSongs" (would be a forked write)',
    !caseBodyContains($membersCode, 'INSERT INTO tblWorkSongs'));
ok('admin_work_members_replace does NOT re-embed a raw "DELETE FROM tblWorkSongs" (would be a forked write)',
    !caseBodyContains($membersCode, 'DELETE FROM tblWorkSongs'));

/* ---- F. admin_work_external_links_replace: delegates to
   saveExternalLinksForRow()/loadExternalLinksForRow() — never a forked
   saver, and probes tblWorkExternalLinks itself first (the shared saver
   does not self-probe). ---- */
$linksCode = caseCode($api, '$action', 'admin_work_external_links_replace');
ok('admin_work_external_links_replace delegates to saveExternalLinksForRow( (includes/external_link_helpers.php)',
    caseBodyContains($linksCode, 'saveExternalLinksForRow('));
ok('admin_work_external_links_replace delegates to loadExternalLinksForRow( (reads back the STORED list, rule #35)',
    caseBodyContains($linksCode, 'loadExternalLinksForRow('));
ok('admin_work_external_links_replace probes tblWorkExternalLinks existence itself (INFORMATION_SCHEMA.TABLES) before delegating — the shared saver does not self-probe and would throw',
    caseBodyContains($linksCode, "TABLE_NAME   = 'tblWorkExternalLinks'"));
ok('admin_work_external_links_replace does NOT re-embed a raw "INSERT INTO tblWorkExternalLinks" (would be a forked write)',
    !caseBodyContains($linksCode, 'INSERT INTO tblWorkExternalLinks'));

/* ---- G. Write cores actually EXIST where claimed (never assumed from the
   api.php call sites alone). ---- */
foreach ([
    'workParseExtraFields', 'workCheckExtraUniqueness', 'workPersistExtraFields',
    'workPersistOriginCity', 'workParentCycleSafe', 'workSongsReplace',
    'workExtraColumnsPresent',
] as $fn) {
    ok("includes/work_admin.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $workAdminSrc));
}
foreach (['saveExternalLinksForRow', 'loadExternalLinksForRow'] as $fn) {
    ok("includes/external_link_helpers.php defines function {$fn}(",
        (bool)preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $extLinkSrc));
}

/* ---- H. NEGATIVE fork-bans, comment-stripped, WHOLE-FILE (rule #22): no
   raw tblWorkSongs/tblWorkExternalLinks write literal anywhere in api.php
   OUTSIDE work_admin.php/external_link_helpers.php, and manage/works.php
   no longer contains them either (it was the ORIGINAL owner, extracted by
   this same change) while still CALLING each extracted function. ---- */
$apiStripped   = stripPhpComments($apiSrc);
$worksStripped = stripPhpComments($worksPageSrc);
foreach (['INSERT INTO tblWorkSongs', 'DELETE FROM tblWorkSongs', 'INSERT INTO tblWorkExternalLinks'] as $literal) {
    ok("api.php contains NO \"{$literal}\" literal (comment-stripped) — that write lives ONLY in the shared core",
        strpos($apiStripped, $literal) === false);
    ok("manage/works.php contains NO \"{$literal}\" literal (comment-stripped) — extracted into the shared core",
        strpos($worksStripped, $literal) === false);
}
foreach ([
    'workParseExtraFields(', 'workCheckExtraUniqueness(', 'workPersistExtraFields(',
    'workPersistOriginCity(', 'workParentCycleSafe(', 'workSongsReplace(', 'workExtraColumnsPresent(',
] as $fn) {
    ok("manage/works.php calls {$fn} (delegates to the shared core, does not just happen to lack the literal)",
        strpos($worksStripped, $fn) !== false);
}
/* work_admin.php is the SOLE definer of the tblWorkSongs write literals
   (workLinkSongRow()/workUnlinkSongRow() from #1860, PLUS workSongsReplace()
   from #1988) — a positive check, not just "absent elsewhere". */
ok('includes/work_admin.php DOES contain the tblWorkSongs write literals (it is the sole definer)',
    strpos($workAdminSrc, 'INSERT INTO tblWorkSongs') !== false
    && strpos($workAdminSrc, 'DELETE FROM tblWorkSongs') !== false);

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

echo "\n{$passed} passed, 0 failed. admin_work_create/_update accept the #1988 extras/origin_city keys and the two"
   . " new admin_work_members_replace/admin_work_external_links_replace actions are dispatchable, documented,"
   . " gate on the SAME entitlement manage/works.php itself gates on, and delegate entirely to the shared"
   . " includes/work_admin.php / includes/external_link_helpers.php cores with no forked SQL anywhere.\n";
exit(0);
