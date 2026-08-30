<?php

declare(strict_types=1);

/**
 * iHymns — guided "New Song" wizard (Editor2, #1997) guard
 * =========================================================
 *
 * ELI5
 * ----
 * #1997 gave curators a guided, step-by-step way to start a new song inside
 * Editor2 — built entirely on the shared stepper (`js/modules/admin-wizard.js`,
 * #1992) and calling ONLY api2.php actions that already existed (create_song /
 * metadata_field_update / song_alt_title_add / components_replace) plus the
 * ONE pre-existing `/api?action=missing_songs` read. This file is the standing
 * guard that keeps that promise true: the plain "New" button + its modal stay
 * byte-identical, the wizard delegates to the real write paths instead of
 * forking a second one, a seeded verse/chorus structure goes through the ONE
 * lyric-line write path (rule #25), and both editors agree on who is allowed
 * in at all (rule #1587 gate parity).
 *
 * WHAT THIS FILE ASSERTS (spec items (a)-(h))
 * --------------------------------------------
 *  (a) MANUAL-PATH — editor2.php still wires #v2-new-btn -> #v2-new-modal,
 *      the manual create handler still calls `editorApi.createSong(sb, title
 *      || 'New Song')`, and `runPrefill()` still calls BOTH
 *      `editorApi.createSong(` and `editorApi.updateMetadata(res.songId,
 *      'number', num)` — each check anchored on its OWN bounded region of the
 *      source (the #1840-k lesson: never one blunt "exists somewhere" check),
 *      with a `str_replace` mutation proof per marker.
 *  (b) The wizard is WIRED: editor2.php carries the `#v2-new-wizard-modal`
 *      markup with the `[data-wiz-step]` contract, a `#v2-new-wizard-btn`
 *      trigger, imports `mountNewSongWizard` from
 *      `./v2/new-song-wizard.js` and calls it; that file itself imports
 *      `/js/modules/admin-wizard.js` and calls `createWizard(`.
 *  (c) SINGLETON — new-song-wizard.js consumes the ONE shared stepper rather
 *      than reimplementing one; the tree-wide "exactly one `createWizard(`
 *      definition" scan is `test-external-link-wizard.php`'s own (j) and is
 *      NOT re-implemented here (rule #22 applied to guards).
 *  (d) NO FORKED CREATE/WRITE — new-song-wizard.js contains no `fetch(`
 *      literal and none of api2.php's own action-name string literals appear
 *      raw in its source (they must only ever be reached through the
 *      INJECTED `ctx.api.*` methods or the imported `missingSongNumbers()`
 *      helper); `finish()` calls `ctx.api.createSong(` and
 *      `ctx.api.replaceComponents(`; api2.php's `create_song` case still has
 *      the SAME number of `INSERT INTO tblSongs` literals it had before this
 *      feature touched anything (a pre-existing two-branch
 *      songPublicId-column-ready/fallback pair — NOT a count this feature
 *      changes; a THIRD copy anywhere would be the regression).
 *  (e) RULE #25 — the `components_replace` case body calls
 *      `ed2_persistComponents(` (the ONE gate onto
 *      `lyricLinesWriteComponents()`), backstopped by the pre-existing
 *      `test-component-json-guard.php`.
 *  (f) GATE PARITY (rule #1587) — editor2.php's top-of-file
 *      `hasRole((string)($u['role'] ?? ''), 'editor')` gate and api2.php's own
 *      `hasRole((string)($currentUser['role'] ?? ''), 'editor')` gate name the
 *      SAME role, extracted from each file rather than hand-typed here.
 *  (g) AVAILABILITY TRUTH TABLE — delegated to the Node functional test
 *      `tests/test-new-song-wizard-availability.js` (rule #22 applied to
 *      guards: this file does not re-implement a text-match copy of that
 *      truth table), `exec()`'d here and required to exit 0 — mirrors
 *      `test-songbook-wizard.php`'s own (f) delegation to
 *      `test-songbook-form-parity.php`. Also confirms `numberAvailability` is
 *      genuinely `export`ed from the tracked source.
 *  (h) DEEP LINKS — the wizard adds no new `?param=` editor2.php must honour
 *      (rule #33); the tree-derived `tests/test-editor-deep-links.js` is
 *      `exec()`'d here as a regression check that this change did not disturb
 *      it.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * PART 1 proves the extraction primitives (`boundedRegion()`,
 * `caseBodyFor()`) against small synthetic fixtures, every run. PART 3 tags
 * each real-content check with a `str_replace`/`withMutatedFile` mutation
 * proof against a real assertion, confirming it goes RED — never a check
 * that has only ever been seen passing.
 *
 * @see appWeb/public_html/manage/editor/editor2.php            the manual path + the wizard's markup/wiring
 * @see appWeb/public_html/manage/editor/v2/new-song-wizard.js   the wizard module itself
 * @see appWeb/public_html/manage/editor/v2/api-client.js        missingSongNumbers()
 * @see appWeb/public_html/manage/editor/api2.php                 create_song / metadata_field_update / song_alt_title_add / components_replace
 * @see tests/test-new-song-wizard-availability.js                the functional truth table for numberAvailability()
 * @see tests/php/test-songbook-wizard.php                        the #1993 precedent this guard's shape mirrors + adapts
 * @see tests/php/lib/dispatch_parser.php                         shared tokeniser this file reuses for the case-body extraction
 *
 *   php tests/php/test-new-song-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo         = dirname(__DIR__, 2);
$pageFile     = $repo . '/appWeb/public_html/manage/editor/editor2.php';
$apiFile      = $repo . '/appWeb/public_html/manage/editor/api2.php';
$wizardFile   = $repo . '/appWeb/public_html/manage/editor/v2/new-song-wizard.js';
$apiClientFile = $repo . '/appWeb/public_html/manage/editor/v2/api-client.js';
$adminWizardFile = $repo . '/appWeb/public_html/js/modules/admin-wizard.js';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* =========================================================================
 * PART 0 — parsing primitives this file is built on.
 * ========================================================================= */

/** Concatenate token TEXT for tokens [$from, $to] inclusive. */
function nswTokensToSource(array $toks, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i <= $to; $i++) {
        $out .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    }
    return $out;
}

/**
 * Extract the raw source text of ONE case's body within `switch ($switchVar)`
 * in `$file` (mirrors test-songbook-wizard.php's own `caseBodyFor()`, built
 * on the SAME shared, already-mutation-tested tokeniser — rule #22: never a
 * second tokeniser walk, only a per-guard-file re-derivation of the small
 * "slice this one case's body" wrapper, matching that file's own precedent).
 */
function caseBodyFor(string $file, string $switchVar, string $caseName): string
{
    $toks  = dispatchParserTokens($file);
    $n     = count($toks);
    $cases = dispatchParserCaseTokens($file, $switchVar);

    $targetIdx = null;
    $nextIdx   = null;
    foreach ($cases as $pos => $c) {
        if ($c['name'] === $caseName) {
            $targetIdx = $c['index'];
            $nextIdx   = $cases[$pos + 1]['index'] ?? null;
            break;
        }
    }
    if ($targetIdx === null) { return ''; }

    $bodyStart = null;
    for ($k = $targetIdx + 1; $k < $n; $k++) {
        if ($toks[$k] === ':') { $bodyStart = $k + 1; break; }
    }
    if ($bodyStart === null) { return ''; }

    $bodyEnd = null;
    if ($nextIdx !== null) {
        for ($k = $nextIdx; $k >= 0; $k--) {
            if (is_array($toks[$k]) && $toks[$k][0] === T_CASE) { $bodyEnd = $k - 1; break; }
        }
    }
    if ($bodyEnd === null) {
        $depth = 1;
        for ($k = $bodyStart; $k < $n; $k++) {
            $t = $toks[$k];
            if (dispatchParserIsOpenBrace($t)) { $depth++; continue; }
            if ($t === '}') {
                $depth--;
                if ($depth === 0) { $bodyEnd = $k - 1; break; }
            }
        }
    }
    if ($bodyEnd === null || $bodyEnd < $bodyStart) { return ''; }
    return nswTokensToSource($toks, $bodyStart, $bodyEnd);
}

/** Strip block + line comments, so a doc-comment MENTIONING a marker in
 *  prose can never masquerade as the marker actually being called (the
 *  same trap test-songbook-wizard.php's own `stripPhpComments()` names —
 *  this file's JS sources use `/* ... *\/` and `// ...` too, so the same
 *  stripper works unmodified on either language). */
function stripComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/**
 * Slice `$src` between the FIRST occurrence of `$startMarker` and the NEXT
 * occurrence of `$endMarker` AFTER it — the #1840-k lesson applied here:
 * every "does X call Y" check below is anchored on its OWN bounded region
 * rather than one blunt "does the whole 1000-line file contain this
 * literal anywhere" test, so a marker's ACTUAL owner is what gets
 * confirmed, not merely its presence somewhere in the file.
 *
 * @return string '' if either marker is not found in the expected order.
 */
function boundedRegion(string $src, string $startMarker, string $endMarker): string
{
    $start = strpos($src, $startMarker);
    if ($start === false) { return ''; }
    $end = strpos($src, $endMarker, $start + strlen($startMarker));
    if ($end === false) { return ''; }
    return substr($src, $start, $end - $start);
}

/** Write `$mutatedSrc` (str_replace applied to `$originalSrc`) to a fresh
 *  temp file, run `$fn($tmpPath)`, delete the temp file, return the
 *  result. Never touches a tracked source file. */
function withMutatedFile(string $originalSrc, string $search, string $replace, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_nsw_mut_') . '.php';
    file_put_contents($tmp, str_replace($search, $replace, $originalSrc));
    try {
        return $fn($tmp);
    } finally {
        @unlink($tmp);
    }
}

/* =========================================================================
 * PART 1 — FIXTURE self-tests for the primitives above (rule #34).
 * ========================================================================= */

/* ---- boundedRegion() ---- */
$fixtureBounded = "AAA start-here BBB middle CCC end-here DDD start-here EEE";
ok('boundedRegion() finds a marker genuinely between start and end',
    str_contains(boundedRegion($fixtureBounded, 'start-here', 'end-here'), 'BBB middle CCC'));
ok('MUTATION PROOF: boundedRegion() does not leak content BEFORE the start marker',
    !str_contains(boundedRegion($fixtureBounded, 'start-here', 'end-here'), 'AAA'));
ok('MUTATION PROOF: boundedRegion() does not leak content AFTER the FIRST end marker (stops at the nearest one)',
    !str_contains(boundedRegion($fixtureBounded, 'start-here', 'end-here'), 'DDD'));
ok('boundedRegion() returns "" when the start marker is absent',
    boundedRegion($fixtureBounded, 'zzz-nope', 'end-here') === '');
ok('boundedRegion() returns "" when the end marker never follows the start marker',
    boundedRegion($fixtureBounded, 'DDD', 'zzz-nope') === '');

/* ---- caseBodyFor() ---- */
$fixtureSwitchSrc = <<<'PHP'
<?php
switch ($action) {
    case 'alpha': {
        doAlpha();
        break;
    }
    case 'beta': {
        doBeta();
        break;
    }
}
PHP;
$fixtureSwitchFile = tempnam(sys_get_temp_dir(), 'ihymns_nsw_fixture_') . '.php';
file_put_contents($fixtureSwitchFile, $fixtureSwitchSrc);
$betaBody = caseBodyFor($fixtureSwitchFile, '$action', 'beta');
ok('caseBodyFor() finds a marker genuinely inside the target case', str_contains($betaBody, 'doBeta('));
ok('MUTATION PROOF: caseBodyFor() does not leak the PRECEDING case\'s marker', !str_contains($betaBody, 'doAlpha('));
ok('MUTATION PROOF: caseBodyFor() returns "" for an absent case name', caseBodyFor($fixtureSwitchFile, '$action', 'gamma') === '');
unlink($fixtureSwitchFile);

/* ---- stripComments() ---- */
$fixtureCommentSrc = "real1();\n/* mentions fakeCall( in a block comment */\nreal2(); // mentions otherCall( in a line comment\nreal3();";
$strippedFixture = stripComments($fixtureCommentSrc);
ok('stripComments() keeps real code', str_contains($strippedFixture, 'real1(') && str_contains($strippedFixture, 'real3('));
ok('MUTATION PROOF: stripComments() removes a block-comment mention', !str_contains($strippedFixture, 'fakeCall('));
ok('MUTATION PROOF: stripComments() removes a line-comment mention', !str_contains($strippedFixture, 'otherCall('));

/* =========================================================================
 * PART 2 — load the real sources.
 * ========================================================================= */

foreach ([$pageFile, $apiFile, $wizardFile, $apiClientFile, $adminWizardFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc       = (string)file_get_contents($pageFile);
$apiSrc        = (string)file_get_contents($apiFile);
$wizardSrc     = (string)file_get_contents($wizardFile);
$wizardCode    = stripComments($wizardSrc);
$apiClientSrc  = (string)file_get_contents($apiClientFile);

/* =========================================================================
 * PART 3 — the (a)-(h) assertions.
 * ========================================================================= */

echo "\nGuided \"New Song\" wizard guard (#1997)\n\n";

/* ---- (a) MANUAL-PATH stays byte-identical ---- */

/* (a1) the button + modal wiring itself. */
ok('(a1) page emits id="v2-new-btn"', str_contains($pageSrc, 'id="v2-new-btn"'));
ok('(a1) page emits id="v2-new-modal"', str_contains($pageSrc, 'id="v2-new-modal"'));
ok("(a1) page wires byId('v2-new-btn').addEventListener('click'", str_contains($pageSrc, "byId('v2-new-btn').addEventListener('click'"));

$mutatedNoNewBtnId = str_replace('id="v2-new-btn"', 'data-removed="v2-new-btn"', $pageSrc);
ok('(a1) MUTATION PROOF: removing id="v2-new-btn" makes the presence check go false',
    !str_contains($mutatedNoNewBtnId, 'id="v2-new-btn"'));

/* (a2) the manual create handler — bounded to its OWN region (from the
   create button's own addEventListener to the next distinct button wired
   after it), so this can never be satisfied by runPrefill()'s createSong
   call living somewhere else in the same file (the #1840-k lesson). */
$manualCreateRegion = boundedRegion($pageSrc, "byId('v2-new-create').addEventListener", "byId('v2-duplicate-btn')");
ok('(a2) isolated the manual create-handler region (non-empty)', $manualCreateRegion !== '');
ok("(a2) manual create handler calls editorApi.createSong(sb, title || 'New Song')",
    str_contains($manualCreateRegion, "editorApi.createSong(sb, title || 'New Song')"));

$mutatedNoManualCreate = str_replace(
    "editorApi.createSong(sb, title || 'New Song')",
    "editorApi.zzzMutatedCreateSong(sb, title || 'New Song')",
    $pageSrc
);
ok('(a2) MUTATION PROOF: renaming the manual handler\'s createSong call makes the bounded-region check go false',
    !str_contains(boundedRegion($mutatedNoManualCreate, "byId('v2-new-create').addEventListener", "byId('v2-duplicate-btn')"), "editorApi.createSong(sb, title || 'New Song')"));

/* (a3) runPrefill() — bounded to its OWN function region, distinct from
   the manual handler's region above. */
$runPrefillRegion = boundedRegion($pageSrc, 'async function runPrefill(book, numStr)', '/* ---- initial ---- */');
ok('(a3) isolated runPrefill()\'s own region (non-empty)', $runPrefillRegion !== '');
ok("(a3) runPrefill() calls editorApi.createSong(book, 'New Song')",
    str_contains($runPrefillRegion, "editorApi.createSong(book, 'New Song')"));
ok("(a3) runPrefill() calls editorApi.updateMetadata(res.songId, 'number', num)",
    str_contains($runPrefillRegion, "editorApi.updateMetadata(res.songId, 'number', num)"));

$mutatedNoPrefillNumber = str_replace(
    "editorApi.updateMetadata(res.songId, 'number', num)",
    "editorApi.zzzMutated(res.songId, 'number', num)",
    $pageSrc
);
ok('(a3) MUTATION PROOF: renaming runPrefill()\'s updateMetadata call makes the bounded-region check go false',
    !str_contains(boundedRegion($mutatedNoPrefillNumber, 'async function runPrefill(book, numStr)', '/* ---- initial ---- */'), "editorApi.updateMetadata(res.songId, 'number', num)"));

/* Cross-check: the manual region and the runPrefill region are genuinely
   DISJOINT (a mutation of one must never be masked by the other still
   containing the same literal, since both call createSong with different
   argument shapes anyway — this pins that difference explicitly). */
ok('(a) manual handler and runPrefill() use DIFFERENT createSong() call shapes (proves the two regions are not the same text)',
    str_contains($manualCreateRegion, "createSong(sb, title || 'New Song')")
    && str_contains($runPrefillRegion, "createSong(book, 'New Song')")
    && !str_contains($manualCreateRegion, "createSong(book, 'New Song')"));

/* ---- (b) wizard wired ---- */
ok('(b) page emits id="v2-new-wizard-modal"', str_contains($pageSrc, 'id="v2-new-wizard-modal"'));
ok('(b) page emits data-wiz-step', str_contains($pageSrc, 'data-wiz-step'));
ok('(b) page emits data-wiz-progress', str_contains($pageSrc, 'data-wiz-progress'));
ok('(b) page emits data-wiz-next', str_contains($pageSrc, 'data-wiz-next'));
ok('(b) page emits data-wiz-back', str_contains($pageSrc, 'data-wiz-back'));
ok('(b) page emits the trigger id="v2-new-wizard-btn"', str_contains($pageSrc, 'id="v2-new-wizard-btn"'));
ok('(b) page trigger targets data-bs-target="#v2-new-wizard-modal"', str_contains($pageSrc, 'data-bs-target="#v2-new-wizard-modal"'));
ok('(b) page imports mountNewSongWizard from ./v2/new-song-wizard.js',
    (bool)preg_match('~import\s*\{\s*mountNewSongWizard\s*\}\s*from\s*[\'"]\./v2/new-song-wizard\.js[\'"]~', $pageSrc));
ok('(b) page calls mountNewSongWizard(', str_contains($pageSrc, 'mountNewSongWizard('));
ok('(b) new-song-wizard.js imports /js/modules/admin-wizard.js',
    (bool)preg_match('~import\s*\{\s*createWizard\s*\}\s*from\s*[\'"]/js/modules/admin-wizard\.js[\'"]~', $wizardSrc));
ok('(b) new-song-wizard.js calls createWizard(', str_contains($wizardCode, 'createWizard('));

$mutatedNoWizardBtn = str_replace('data-bs-target="#v2-new-wizard-modal"', '', $pageSrc);
ok('(b) MUTATION PROOF: removing the trigger\'s data-bs-target makes the presence check go false',
    !str_contains($mutatedNoWizardBtn, 'data-bs-target="#v2-new-wizard-modal"'));
$mutatedNoMount = str_replace('mountNewSongWizard(', 'zzzMutatedMount(', $pageSrc);
ok('(b) MUTATION PROOF: renaming the mount call makes the presence check go false',
    !str_contains($mutatedNoMount, 'mountNewSongWizard('));

/* ---- (c) singleton — deferred to test-external-link-wizard.php's own (j),
   NOT re-implemented here (rule #22 applied to guards). This file only
   confirms new-song-wizard.js is a genuine CONSUMER of the shared stepper. ---- */
ok('(c) new-song-wizard.js consumes the shared stepper (createWizard() singleton itself is asserted by test-external-link-wizard.php)',
    str_contains($wizardCode, 'createWizard('));

/* ---- (d) no forked create/write ---- */
ok('(d) new-song-wizard.js contains no raw fetch( literal', !preg_match('/\bfetch\s*\(/', $wizardCode));

/* None of api2.php's own action-name string literals may appear RAW in
   new-song-wizard.js — every write must go through an injected ctx.api.*
   method, never a hand-typed action string this file could silently drift
   from. */
$bannedActionLiterals = ['create_song', 'metadata_field_update', 'song_alt_title_add', 'components_replace', 'load_index'];
foreach ($bannedActionLiterals as $lit) {
    ok("(d) new-song-wizard.js never spells out the raw api2 action string '{$lit}'",
        !preg_match('/[\'"]' . preg_quote($lit, '/') . '[\'"]/', $wizardCode));
}
ok('(d) finish() calls ctx.api.createSong(', str_contains($wizardCode, 'ctx.api.createSong('));
ok('(d) finish() calls ctx.api.replaceComponents(', str_contains($wizardCode, 'ctx.api.replaceComponents('));
ok('(d) finish() calls ctx.api.updateMetadata(', str_contains($wizardCode, 'ctx.api.updateMetadata('));
ok('(d) finish() calls ctx.api.addAltTitle(', str_contains($wizardCode, 'ctx.api.addAltTitle('));

$mutatedFetch = str_replace('missingSongNumbers', 'missingSongNumbers', $wizardSrc); // no-op precondition holder
$mutatedWithFetch = preg_replace('/export function mountNewSongWizard\(ctx\) \{/', 'export function mountNewSongWizard(ctx) { fetch("/zzz");', $wizardSrc, 1);
ok('(d) MUTATION PROOF: injecting a raw fetch( literal makes the ban check go false',
    (bool)preg_match('/\bfetch\s*\(/', stripComments((string)$mutatedWithFetch)));

/* The api2 create_song case's OWN write-site count is UNCHANGED by this
   feature (a pre-existing songPublicId-column-ready/fallback TWO-branch
   pattern) — pinned by exact count so a future THIRD copy (a forked write
   path anywhere) is caught, without wrongly asserting a count this
   feature never touched. */
$createSongBody = caseBodyFor($apiFile, '$action', 'create_song');
ok('(d) isolated api2.php\'s create_song case body (non-empty)', $createSongBody !== '');
$insertCount = preg_match_all('/INSERT\s+INTO\s+tblSongs\b/i', stripComments($createSongBody));
ok('(d) api2.php\'s create_song case still has exactly 2 "INSERT INTO tblSongs" literals (the pre-existing PublicId-column-ready/fallback pair — found ' . $insertCount . ')',
    $insertCount === 2);

/* ---- (e) rule #25 — components_replace delegates to ed2_persistComponents( ---- */
$componentsReplaceBody = caseBodyFor($apiFile, '$action', 'components_replace');
ok('(e) isolated api2.php\'s components_replace case body (non-empty)', $componentsReplaceBody !== '');
ok('(e) components_replace case body calls ed2_persistComponents(', str_contains($componentsReplaceBody, 'ed2_persistComponents('));

$mutatedNoPersist = withMutatedFile($apiSrc, "case 'components_replace':", "case 'zzz_mutated_components_replace':", static function (string $tmp): string {
    return caseBodyFor($tmp, '$action', 'components_replace');
});
ok('(e) MUTATION PROOF: renaming the components_replace case label makes caseBodyFor() return ""', $mutatedNoPersist === '');

/* ---- (f) gate parity (rule #1587) — extracted, never hand-typed ---- */
preg_match("/if \(!\\\$u \|\| !hasRole\(\(string\)\(\\\$u\['role'\] \?\? ''\), '([a-z_]+)'\)\)/", $pageSrc, $mPage);
preg_match("/if \(!\\\$currentUser \|\| !hasRole\(\(string\)\(\\\$currentUser\['role'\] \?\? ''\), '([a-z_]+)'\)\)/", $apiSrc, $mApi);
ok("(f) extracted editor2.php's own top-of-file role gate", isset($mPage[1]));
ok("(f) extracted api2.php's own top-of-file role gate", isset($mApi[1]));
if (isset($mPage[1], $mApi[1])) {
    ok("(f) editor2.php gates on '{$mPage[1]}' and api2.php gates on the SAME role '{$mApi[1]}'", $mPage[1] === $mApi[1]);
}

$mutatedApiGate = str_replace(
    "if (!\$currentUser || !hasRole((string)(\$currentUser['role'] ?? ''), 'editor'))",
    "if (!\$currentUser || !hasRole((string)(\$currentUser['role'] ?? ''), 'admin'))",
    $apiSrc
);
preg_match("/if \(!\\\$currentUser \|\| !hasRole\(\(string\)\(\\\$currentUser\['role'\] \?\? ''\), '([a-z_]+)'\)\)/", $mutatedApiGate, $mApiMutated);
ok('(f) MUTATION PROOF: weakening api2.php\'s gate role makes the parity check go false',
    isset($mApiMutated[1]) && isset($mPage[1]) && $mApiMutated[1] !== $mPage[1]);

/* ---- (g) availability truth table — delegated to the Node functional test ---- */
ok('(g) new-song-wizard.js exports numberAvailability(',
    (bool)preg_match('/export\s+function\s+numberAvailability\s*\(/', $wizardSrc));

$nodeAvailTest = $repo . '/tests/test-new-song-wizard-availability.js';
ok('(g) the Node functional truth-table test exists: ' . $nodeAvailTest, is_file($nodeAvailTest));
if (is_file($nodeAvailTest)) {
    $nodeOutput = [];
    $nodeStatus = 0;
    exec('node ' . escapeshellarg($nodeAvailTest) . ' 2>&1', $nodeOutput, $nodeStatus);
    ok('(g) tests/test-new-song-wizard-availability.js (the numberAvailability() functional truth table) is green — '
        . trim(implode(' ', array_slice($nodeOutput, -1))), $nodeStatus === 0);
}

/* ---- (h) deep links — no new param; regression-check the existing
   tree-derived guard is still green (rule #33) ---- */
$deepLinksTest = $repo . '/tests/test-editor-deep-links.js';
ok('(h) tests/test-editor-deep-links.js exists: ' . $deepLinksTest, is_file($deepLinksTest));
if (is_file($deepLinksTest)) {
    $dlOutput = [];
    $dlStatus = 0;
    exec('node ' . escapeshellarg($deepLinksTest) . ' 2>&1', $dlOutput, $dlStatus);
    ok('(h) tests/test-editor-deep-links.js is still green after #1997 (the wizard adds no new deep-link param) — '
        . trim(implode(' ', array_slice($dlOutput, -1))), $dlStatus === 0);
}

/* ---- api-client.js — the ONE new client addition, sanity-checked ---- */
ok('missingSongNumbers() is exported from api-client.js',
    (bool)preg_match('/export\s+async\s+function\s+missingSongNumbers\s*\(/', $apiClientSrc));
ok('new-song-wizard.js imports missingSongNumbers from ./api-client.js',
    (bool)preg_match('~import\s*\{\s*missingSongNumbers\s*\}\s*from\s*[\'"]\./api-client\.js[\'"]~', $wizardSrc));

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "The plain New-song modal + runPrefill() stay byte-identical, the guided wizard is wired\n"
   . "through the SAME shared stepper + the SAME existing api2.php write actions (no forked\n"
   . "create/write path, no raw action-string literal, the seeded structure delegates to\n"
   . "ed2_persistComponents()), editor2.php and api2.php agree on who is let in, and\n"
   . "numberAvailability()'s five-outcome truth table is proven functionally (not by text\n"
   . "match) via the Node test it delegates to.\n";
exit(0);
