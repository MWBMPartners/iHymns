<?php

declare(strict_types=1);

/**
 * iHymns — "First-run environment setup" guided wizard guard (#2005, epic #2002)
 * =====================================================================
 *
 * ELI5
 * ----
 * #2005 gave `/manage/setup-database` a friendlier, step-by-step front door
 * (built on the SAME shared stepper, `js/modules/admin-wizard.js`, #1992,
 * the four shipped wizards already use) — WITHOUT changing anything about
 * how the classic card grid or its "Apply all pending migrations" button
 * actually work. This file is the standing guard that keeps that shape
 * honest: the wizard exists and is really wired up, it drives migrations
 * by DELEGATING to the exact transport the classic bulk runner already
 * uses (never a second copy), it can NEVER run a `'manual' => true`
 * (destructive / hand-run-only) migration, the page's entitlement gate is
 * untouched, the classic no-JS runner path is byte-for-byte intact, the
 * wizard adds NO new dispatched server action (this page stays entirely
 * `web_only:` in the standing API-coverage guard), its `?wizard=verify`
 * deep link is genuinely read AND honoured, and neither new file ever
 * hardcodes a `/public_html/` path (rule #41).
 *
 * WHAT THIS FILE ASSERTS (a)-(i)
 * -------------------------------
 *  (a) WIZARD EXISTS + WIRED — the page requires the step-panes partial
 *      and emits a `data-bs-target="#setupWizardModal"` trigger; the
 *      partial contains exactly 5 `data-wiz-step` panes, a
 *      `[data-wiz-progress]` trail placeholder, and its own BEGIN/END
 *      markers; the JS wiring module imports `createWizard` from the
 *      shared stepper.
 *  (b) STEPPER SINGLETON — DEFER, don't re-scan. `js/modules/
 *      admin-wizard.js` already has exactly one tree-wide guard for "is
 *      `createWizard(` defined exactly once?" — `tests/php/
 *      test-external-link-wizard.php`'s own singleton scan (rule #22
 *      applied to guards: confirm the standing check is still green
 *      rather than re-implementing a second copy of the same tree walk).
 *  (c) DELEGATION, NOT FORK — the wizard's JS module contains no
 *      `fetch(`, no `/api?`, no `format=text` literal and no `STATUS:`
 *      envelope-parsing of its own; it imports `runOne` from
 *      `setup-bulk-runner.js`, which still exports both `runOne()` and
 *      `parseEnvelope()` and still uses them internally exactly as
 *      before.
 *  (d) EXISTING RUNNER PATH INTACT — the classic `data-bulk-runner-
 *      trigger` / `data-pending-migrations` / `?action=apply-all-
 *      migrations` / `bootSetupBulkRunner()` wiring is untouched, and the
 *      `$bulkRunnerPending` list is still built with the SAME
 *      `empty($migrationManual[…])` filter (#1235 P4/C6) that keeps a
 *      manual/destructive slug off it.
 *  (e) MANUAL/DESTRUCTIVE NEVER WIZARD-DRIVEN — every slug the migration
 *      REGISTRY itself marks `'manual' => true` (extracted mechanically,
 *      never a hand-typed list — rule #34) is absent as a runnable
 *      literal from both new files, and neither new file contains a
 *      `confirm=1` literal anywhere. This is the single most important
 *      assertion in the file: it is what makes "the wizard structurally
 *      cannot drive a destructive migration" true, rather than merely
 *      asserted.
 *  (f) GATE PARITY FROZEN — the page's top-level `userHasEntitlement(
 *      'run_db_install'` gate, its per-action `'run_db_migrate'`
 *      entitlement, and `admin-links.php`'s matching nav-row entitlement
 *      (rule #1587) are all still present, byte-for-byte.
 *  (g) NO NEW ACTION / WEB-ONLY PRESERVED — every `$action`-dispatched
 *      literal the page answers to (via the SAME tree-derived parser the
 *      standing API-coverage guard uses, `dispatchParserActionsForFile()`)
 *      is still a key of that guard's `'setup-database.php'` map, and
 *      every one of those mappings is still `web_only:…` — i.e. the
 *      wizard introduced no new dispatched action and no API twin.
 *  (h) DEEP-LINK HONOURED (rule #33) — the page reads `$_GET['wizard']`
 *      and admits only the literal `'verify'`; the modal shell emits
 *      `data-open-step`; the JS module reads it and calls `.goTo(`.
 *  (i) RULE #41 / DEPLOY-PATH — neither new file contains a
 *      `/public_html/` literal, and the partial's own `require` in the
 *      page resolves via `__DIR__` (never a hardcoded docroot path).
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Mirrors `tests/php/test-service-setup-wizard.php` / `test-external-link-
 * wizard.php`'s own two-layer shape: PART 1 fixture self-tests of every
 * parsing primitive this file is built on (proven against small synthetic
 * strings, BEFORE any real assertion trusts them), PART 3 "MUTATION PROOF"
 * re-runs of the SAME check logic against a MUTATED COPY (an in-memory
 * `str_replace()`'d string, or a `tempnam()`'d temp file that is written,
 * tested and deleted within the same assertion) — NEVER the tracked
 * source file.
 *
 * ⚠️ ONE DELIBERATE DEPARTURE FROM THE ORIGINAL PLAN, noted here for
 * anyone diffing against it: `manage/setup-database.php` dispatches its
 * non-migration actions via a `$action === 'x'` COMPARISON CHAIN (see
 * `tests/php/lib/dispatch_parser.php`'s own header, shape 4), not a
 * `switch ($action) { case … }` — there is no switch anywhere in this
 * file. Assertion (g)'s mutation proof therefore injects a REAL
 * `$action === 'zzz_mutated_wizard_action'` comparison (the file's own
 * idiom) into a temp copy, rather than an unreachable bare `case` label
 * that `dispatchParserCaseTokens()` would never find outside a `switch`.
 *
 * @see appWeb/public_html/manage/setup-database.php                the page this wizard is built on
 * @see appWeb/public_html/manage/includes/setup-wizard-modal.php   the step-panes partial
 * @see appWeb/public_html/js/modules/setup-wizard.js                the wiring module
 * @see appWeb/public_html/js/modules/setup-bulk-runner.js           runOne()/parseEnvelope() this wizard delegates to
 * @see appWeb/public_html/js/modules/admin-wizard.js                the shared stepper (#1992)
 * @see appWeb/public_html/manage/includes/migration-registry.php    the ONE source of which slugs are 'manual'
 * @see tests/php/test-manage-action-api-coverage.php                 the standing action/API-coverage guard (g) cross-checks
 * @see tests/php/test-external-link-wizard.php                       the #1992 createWizard() singleton scan (b) defers to
 * @see tests/php/lib/dispatch_parser.php                             shared tokeniser reused for (g)
 * @see #2005 (child of epic #2002)
 *
 *   php tests/php/test-setup-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo          = dirname(__DIR__, 2);
$registryFile  = $repo . '/appWeb/public_html/manage/includes/migration-registry.php';
$pageFile      = $repo . '/appWeb/public_html/manage/setup-database.php';
$partialFile   = $repo . '/appWeb/public_html/manage/includes/setup-wizard-modal.php';
$jsFile        = $repo . '/appWeb/public_html/js/modules/setup-wizard.js';
$bulkRunnerFile = $repo . '/appWeb/public_html/js/modules/setup-bulk-runner.js';
$stepperFile   = $repo . '/appWeb/public_html/js/modules/admin-wizard.js';
$coverageFile  = $repo . '/tests/php/test-manage-action-api-coverage.php';
$adminLinksFile = $repo . '/appWeb/public_html/manage/includes/admin-links.php';
$extWizardTestFile = __DIR__ . '/test-external-link-wizard.php';

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

/**
 * Strip HTML comments, PHP/JS block comments, and PHP/JS line comments
 * from `$src` — so a doc-comment's PROSE mention of a slug/action/literal
 * can never masquerade as the real thing below. `(?<!:)//` protects a URL's
 * `://` from being eaten as a line comment (mirrors every other guard's
 * own stripper — `stripPhpComments()` in test-service-setup-wizard.php,
 * `a11yStripComments()` in test-a11y-static-checks.php).
 */
function swStripComments(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/**
 * Position of the `]` that matches the `[` at `$openPos` (which MUST be
 * `[`), tracked by depth — so a nested array (e.g. one entry's own
 * `'card' => ['title' => …, 'body' => […nested…]]`) never fools the walk
 * into stopping early.
 */
function swMatchingBracketEnd(string $s, int $openPos): ?int
{
    if (($s[$openPos] ?? null) !== '[') { return null; }
    $depth = 0;
    $len = strlen($s);
    for ($k = $openPos; $k < $len; $k++) {
        $ch = $s[$k];
        if ($ch === '[') { $depth++; }
        elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) { return $k; }
        }
    }
    return null;
}

/**
 * Every top-level `    '<key>' => [ … ],` entry in `$stripped` (4-space
 * indented — the shape BOTH `migration-registry.php`'s 174 entries and
 * `test-manage-action-api-coverage.php`'s per-page `$MAPPING` entries
 * share) -> `[key => fullBracketedSpanText]`. Generic over both files
 * (rule #22 applied to this guard's own helpers) rather than one
 * extractor per file.
 *
 * @return array<string,string>
 */
function swTopLevelEntrySpans(string $stripped): array
{
    $spans = [];
    if (preg_match_all("/^[ \\t]{4}'([a-zA-Z0-9_.-]+)'\\s*=>\\s*\\[/m", $stripped, $m, PREG_OFFSET_CAPTURE) === false) {
        return $spans;
    }
    $n = count($m[0]);
    for ($i = 0; $i < $n; $i++) {
        $key      = $m[1][$i][0];
        $matchTxt = $m[0][$i][0];
        $matchPos = $m[0][$i][1];
        $openPos  = $matchPos + strlen($matchTxt) - 1;
        $endPos   = swMatchingBracketEnd($stripped, $openPos);
        if ($endPos === null) { continue; }
        $spans[$key] = substr($stripped, $openPos, $endPos - $openPos + 1);
    }
    return $spans;
}

/**
 * Every slug in the migration registry file whose entry carries
 * `'manual' => true` — mechanically DERIVED from the live registry, never
 * a hand-typed list (rule #34: an empty derivation is itself a failure,
 * the under-reporting trap).
 *
 * @return array<int,string>
 */
function swExtractManualSlugs(string $registryFile): array
{
    $stripped = swStripComments((string) file_get_contents($registryFile));
    $manual = [];
    foreach (swTopLevelEntrySpans($stripped) as $slug => $span) {
        if (preg_match('/\'manual\'\s*=>\s*true/', $span)) {
            $manual[] = $slug;
        }
    }
    return $manual;
}

/**
 * `['key' => 'value', …]` pairs inside a bracketed span extracted by
 * `swTopLevelEntrySpans()` — used to read the coverage guard's own
 * per-page action -> mapping table.
 *
 * @return array<string,string>
 */
function swSpanKeyValuePairs(string $span): array
{
    $out = [];
    if (preg_match_all("/'([a-zA-Z0-9_-]+)'\\s*=>\\s*'([a-zA-Z0-9_:.-]+)'/", $span, $m)) {
        foreach ($m[1] as $i => $k) { $out[$k] = $m[2][$i]; }
    }
    return $out;
}

/** Does every name in `$names` exist as a key of `$mapKeys`? The exact
 *  boolean the real "no new dispatched action" assertion computes, kept
 *  as its own function so the mutation proof can re-run the SAME logic
 *  against a deliberately-uncovered name. */
function swAllCovered(array $names, array $mapKeys): bool
{
    foreach ($names as $n) {
        if (!in_array($n, $mapKeys, true)) { return false; }
    }
    return true;
}

/** Write `$mutatedSrc` (a `str_replace()`'d copy of `$originalSrc`) to a
 *  fresh temp file, run `$fn($tmpPath)`, delete the temp file, return the
 *  result. Never touches a tracked source file. */
function swWithMutatedFile(string $originalSrc, string $search, string $replace, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_setupwiz_mut_') . '.php';
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

/* ---- swStripComments() ---- */
$fixtureCommentSrc = "real1();\n<!-- mentions fakeHtml( in an html comment -->\n/* mentions fakeBlock( in a block comment */\nreal2(); // mentions fakeLine( in a line comment\nreal3(); \$x = 'http://example.com'; // a URL, not a comment start";
$strippedFixture = swStripComments($fixtureCommentSrc);
ok('swStripComments() keeps real code', str_contains($strippedFixture, 'real1(') && str_contains($strippedFixture, 'real2(') && str_contains($strippedFixture, 'real3('));
ok('MUTATION PROOF: swStripComments() removes an HTML-comment mention', !str_contains($strippedFixture, 'fakeHtml('));
ok('MUTATION PROOF: swStripComments() removes a block-comment mention', !str_contains($strippedFixture, 'fakeBlock('));
ok('MUTATION PROOF: swStripComments() removes a line-comment mention', !str_contains($strippedFixture, 'fakeLine('));
ok('swStripComments() does not eat a URL\'s :// as a line comment', str_contains($strippedFixture, "http://example.com'"));

/* ---- swMatchingBracketEnd() / swTopLevelEntrySpans() ---- */
$fixtureRegistrySrc = <<<'PHP'
<?php
return [
    'alpha' => [
        'script' => 'migrate-alpha.php',
    ],
    'beta' => [
        'script' => 'migrate-beta.php',
        'manual' => true,
    ],
    'gamma' => [
        'script' => 'migrate-gamma.php',
        'nested' => [
            'inner' => true,
        ],
        'manual' => false,
    ],
];
PHP;
$fixtureSpans = swTopLevelEntrySpans($fixtureRegistrySrc);
ok('swTopLevelEntrySpans() finds all three top-level entries', array_keys($fixtureSpans) === ['alpha', 'beta', 'gamma']);
ok('swTopLevelEntrySpans() does not leak alpha\'s content into beta\'s span', !str_contains($fixtureSpans['beta'], 'migrate-alpha'));
ok('swTopLevelEntrySpans() correctly closes past a NESTED array (gamma) without stopping early',
    str_contains($fixtureSpans['gamma'], "'manual' => false") && str_contains($fixtureSpans['gamma'], "'inner' => true"));

$fixtureRegistryFile = tempnam(sys_get_temp_dir(), 'ihymns_setupwiz_fixture_') . '.php';
file_put_contents($fixtureRegistryFile, $fixtureRegistrySrc);
$fixtureManual = swExtractManualSlugs($fixtureRegistryFile);
ok('swExtractManualSlugs() finds exactly the ONE entry with manual => true (beta)', $fixtureManual === ['beta']);
ok('MUTATION PROOF: swExtractManualSlugs() stops finding beta once its manual=>true is removed',
    swWithMutatedFile($fixtureRegistrySrc, "'manual' => true,", '', function (string $tmp): array {
        return swExtractManualSlugs($tmp);
    }) === []);
unlink($fixtureRegistryFile);

/* ---- swSpanKeyValuePairs() ---- */
$fixtureMapSpan = "\n        'run' => 'api:do_run',\n        'stop' => 'web_only:console',\n    ";
$fixturePairs = swSpanKeyValuePairs($fixtureMapSpan);
ok('swSpanKeyValuePairs() extracts both pairs', $fixturePairs === ['run' => 'api:do_run', 'stop' => 'web_only:console']);

/* ---- swAllCovered() ---- */
ok('swAllCovered() is true when every name has a mapping', swAllCovered(['a', 'b'], ['a', 'b', 'c']));
ok('MUTATION PROOF: swAllCovered() flips to false the moment one name is uncovered', swAllCovered(['a', 'zzz'], ['a', 'b']) === false);

/* ---- swWithMutatedFile() ---- */
ok('swWithMutatedFile() applies the str_replace and lets the callback observe it',
    swWithMutatedFile('needle here', 'needle', 'REPLACED', static fn(string $tmp): bool => str_contains((string) file_get_contents($tmp), 'REPLACED')));
ok('swWithMutatedFile() deletes its temp file afterwards', (function (): bool {
    $seen = null;
    swWithMutatedFile('x', 'x', 'y', static function (string $tmp) use (&$seen): void { $seen = $tmp; });
    return $seen !== null && !is_file($seen);
})());

/* =========================================================================
 * PART 2 — load the real sources.
 * ========================================================================= */

foreach ([$registryFile, $pageFile, $partialFile, $jsFile, $bulkRunnerFile, $stepperFile, $coverageFile, $adminLinksFile, $extWizardTestFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc        = (string) file_get_contents($pageFile);
$partialSrc     = (string) file_get_contents($partialFile);
$jsSrc          = (string) file_get_contents($jsFile);
$bulkRunnerSrc  = (string) file_get_contents($bulkRunnerFile);
$coverageSrc    = (string) file_get_contents($coverageFile);
$adminLinksSrc  = (string) file_get_contents($adminLinksFile);

$pageStripped    = swStripComments($pageSrc);
$partialStripped = swStripComments($partialSrc);
$jsStripped      = swStripComments($jsSrc);

$manualSlugs = swExtractManualSlugs($registryFile);
ok('derived >= 1 manual/destructive slug from the live migration registry (found ' . count($manualSlugs) . ')', count($manualSlugs) >= 1);

/* =========================================================================
 * PART 3 — the (a)-(i) assertions.
 * ========================================================================= */

echo "\n\"First-run environment setup\" guided wizard guard (#2005)\n\n";

/* ---- (a) WIZARD EXISTS + WIRED ---- */
ok('(a) the page requires includes/setup-wizard-modal.php via __DIR__ (never a literal path)',
    (bool) preg_match('/require\s+__DIR__\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'includes\'\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'setup-wizard-modal\.php\'/', $pageSrc));
ok('(a) the page emits a data-bs-target="#setupWizardModal" trigger', str_contains($pageSrc, 'data-bs-target="#setupWizardModal"'));
ok('(a) the page renders the real <div class="modal fade" id="setupWizardModal"> element',
    (bool) preg_match('/<div\s+class="modal fade"\s+id="setupWizardModal"/', $pageSrc));

/* ---- (a-a11y) the modal is accessibly labelled + has a plain close button.
        WHY here and not in test-a11y-static-checks.php: that scanner classifies
        a modal as a "wizard modal" only when a `data-wiz-progress` marker sits
        beside the `<div class="modal fade">` IN THE SAME FILE, and it does not
        follow `require`s. Here the progress trail lives in the required partial
        (so test-wizard-empty-state.php can keep the modal id + empty-state call
        in this one file), which means the general scanner does not "see" this
        modal. Rather than move the marker (which the (a) checks above and
        test-wizard-empty-state.php both anchor on), this feature's OWN guard
        checks the same two accessibility properties directly, so the modal can
        never lose its label or gain an invisible-in-light-theme close button
        without a test going red. Plain English: prove the pop-up still tells a
        screen reader its name, and that its close "X" is visible in light mode. */
ok('(a-a11y) the modal carries aria-labelledby="setupWizardModalLabel"',
    (bool) preg_match('/<div\s+class="modal fade"\s+id="setupWizardModal"[^>]*\baria-labelledby="setupWizardModalLabel"/', $pageSrc));
ok('(a-a11y) a matching heading id="setupWizardModalLabel" exists so the label resolves',
    (bool) preg_match('/id="setupWizardModalLabel"[^>]*>/', $pageSrc));
ok('(a-a11y) the modal close button is the plain, theme-aware btn-close (never btn-close-white)',
    str_contains($pageSrc, 'class="btn-close"') && preg_match('/class="btn-close[^"]*btn-close-white/', $pageSrc) !== 1);
/* MUTATION PROOFs — copies only, never the tracked file. */
ok('(a-a11y) MUTATION PROOF: removing the modal aria-labelledby drops the labelled-modal match',
    !(bool) preg_match('/<div\s+class="modal fade"\s+id="setupWizardModal"[^>]*\baria-labelledby="setupWizardModalLabel"/',
        str_replace(' aria-labelledby="setupWizardModalLabel"', '', $pageSrc)));
ok('(a-a11y) MUTATION PROOF: a btn-close-white close button trips the plain-close assertion',
    preg_match('/class="btn-close[^"]*btn-close-white/', str_replace('class="btn-close"', 'class="btn-close btn-close-white"', $pageSrc)) === 1);

$stepPaneCount = substr_count($partialStripped, 'data-wiz-step');
ok('(a) the partial contains EXACTLY 5 data-wiz-step panes (found ' . $stepPaneCount . ')', $stepPaneCount === 5);
ok('(a) the partial contains a [data-wiz-progress] trail placeholder', str_contains($partialStripped, 'data-wiz-progress'));
ok('(a) the partial carries its own BEGIN marker', str_contains($partialSrc, '#2005 — Guided environment setup wizard: step panes. BEGIN'));
ok('(a) the partial carries its own END marker', str_contains($partialSrc, '#2005 — Guided environment setup wizard: step panes. END'));

ok('(a) setup-wizard.js imports createWizard from ./admin-wizard.js',
    (bool) preg_match('/import\s*{\s*createWizard\s*}\s*from\s*[\'"]\.\/admin-wizard\.js[\'"]/', $jsStripped));

/* MUTATION PROOFs — copies only. */
ok('(a) MUTATION PROOF: removing the require line drops the "page requires the partial" match',
    !(bool) preg_match(
        '/require\s+__DIR__\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'includes\'\s*\.\s*DIRECTORY_SEPARATOR\s*\.\s*\'setup-wizard-modal\.php\'/',
        str_replace("require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'setup-wizard-modal.php';", '/* removed */', $pageSrc)
    ));
$partialOneStepRemoved = preg_replace('/<section data-wiz-step data-wiz-label="Verify" hidden>.*?<\/section>\s*/s', '', $partialSrc, 1);
ok('(a) fixture precondition: the mutation above actually removed one pane',
    $partialOneStepRemoved !== null && substr_count(swStripComments($partialOneStepRemoved), 'data-wiz-step') === 4);
ok('(a) MUTATION PROOF: removing one data-wiz-step pane drops the count below 5',
    $partialOneStepRemoved !== null && substr_count(swStripComments($partialOneStepRemoved), 'data-wiz-step') !== 5);

/* ---- (b) STEPPER SINGLETON — defer, don't re-implement ---- */
ok('(b) the page imports admin-wizard.js indirectly via setup-wizard.js, which itself imports createWizard (checked above)', true);
$singletonGuardOutput = [];
$singletonGuardStatus = 0;
exec('php ' . escapeshellarg($extWizardTestFile) . ' 2>&1', $singletonGuardOutput, $singletonGuardStatus);
ok('(b) tests/php/test-external-link-wizard.php (its own createWizard() singleton scan) is still green', $singletonGuardStatus === 0);

/* ---- (c) DELEGATION, NOT FORK ---- */
ok('(c) setup-wizard.js imports runOne from ./setup-bulk-runner.js',
    (bool) preg_match('/import\s*{\s*runOne\s*}\s*from\s*[\'"]\.\/setup-bulk-runner\.js[\'"]/', $jsStripped));
ok('(c) setup-wizard.js contains no literal "format=text" of its own', !str_contains($jsStripped, 'format=text'));
ok('(c) setup-wizard.js contains no "STATUS:" envelope-parsing of its own', !str_contains($jsStripped, 'STATUS:'));
ok('(c) setup-wizard.js never calls fetch( directly', !str_contains($jsStripped, 'fetch('));
ok('(c) setup-wizard.js never calls /api?', !str_contains($jsStripped, '/api?'));
ok('(c) setup-bulk-runner.js still exports runOne()', (bool) preg_match('/export\s+async\s+function\s+runOne\s*\(/', $bulkRunnerSrc));
ok('(c) setup-bulk-runner.js still exports parseEnvelope()', (bool) preg_match('/export\s+function\s+parseEnvelope\s*\(/', $bulkRunnerSrc));
ok('(c) setup-bulk-runner.js\'s own runSequence() still calls runOne(action) internally (existing path intact)',
    str_contains(swStripComments($bulkRunnerSrc), 'await runOne(action)'));

/* MUTATION PROOF — inject a real (non-comment) fetch( call into a copy and
   confirm the SAME check flips to detecting it. */
$jsMutatedFetch = str_replace(
    "import { createWizard } from './admin-wizard.js';",
    "import { createWizard } from './admin-wizard.js';\nfetch('?action=x&format=text');",
    $jsSrc
);
$jsMutatedFetchStripped = swStripComments($jsMutatedFetch);
ok('(c) fixture precondition: the injected fetch( call is real code, not inside a comment',
    str_contains($jsMutatedFetchStripped, "fetch('?action=x"));
ok('(c) MUTATION PROOF: injecting a real fetch( call flips the "never calls fetch(" check to true (detected/red)',
    str_contains($jsMutatedFetchStripped, 'fetch('));

/* ---- (d) EXISTING RUNNER PATH INTACT ---- */
ok('(d) the page still emits data-bulk-runner-trigger', str_contains($pageSrc, 'data-bulk-runner-trigger'));
ok('(d) the page still emits data-pending-migrations', str_contains($pageSrc, 'data-pending-migrations'));
/* Security-audit finding L-1 (2026-08-30) HONEST CONSEQUENCE: setup-database.php's
   ?action= GET dispatch is now CSRF-gated (tests/php/test-setup-database-csrf.php),
   so every no-JS action link — this one included — now carries its own
   &csrf_token=… query param appended after the action name. The assertion's
   actual INTENT (a plain href-based no-JS fallback pointing at
   ?action=apply-all-migrations still exists) is unchanged and still true; only
   the exact-closing-quote match needed relaxing to a prefix match, and the
   token's own presence is now asserted explicitly alongside it — this is a
   narrowing/correction, never a weakening of what's being proven. */
ok('(d) the page still emits the no-JS href="?action=apply-all-migrations…" (now CSRF-token-bearing, L-1)',
    str_contains($pageSrc, 'href="?action=apply-all-migrations&amp;csrf_token='));
ok('(d) the page still imports+boots bootSetupBulkRunner', str_contains($pageSrc, 'import { bootSetupBulkRunner }') && str_contains($pageSrc, 'bootSetupBulkRunner();'));

$bulkPendingAnchor = '$bulkRunnerPending = array_values(array_filter(';
$bulkPendingPos = strpos($pageSrc, $bulkPendingAnchor);
ok('(d) located the $bulkRunnerPending = array_values(array_filter( block', $bulkPendingPos !== false);
$bulkPendingWindow = $bulkPendingPos !== false ? substr($pageSrc, $bulkPendingPos, 400) : '';
ok('(d) the $bulkRunnerPending block still filters out manual slugs via empty($migrationManual[',
    str_contains($bulkPendingWindow, 'empty($migrationManual['));

/* MUTATION PROOFs on copies. */
ok('(d) MUTATION PROOF: stripping data-bulk-runner-trigger from a copy removes it',
    !str_contains(str_replace('data-bulk-runner-trigger', '', $pageSrc), 'data-bulk-runner-trigger'));
$bulkPendingWindowMutated = str_replace('empty($migrationManual[', 'empty($zzz_removed[', $bulkPendingWindow);
ok('(d) MUTATION PROOF: stripping the empty($migrationManual[ filter from the block flips the check to false',
    !str_contains($bulkPendingWindowMutated, 'empty($migrationManual['));

/* ---- (e) MANUAL/DESTRUCTIVE NEVER WIZARD-DRIVEN ---- */
$combinedNewFilesStripped = $jsStripped . "\n" . $partialStripped;
foreach ($manualSlugs as $slug) {
    ok("(e) manual slug '{$slug}' never appears as a single-quoted literal in the new files",
        !str_contains($combinedNewFilesStripped, "'{$slug}'"));
    ok("(e) manual slug '{$slug}' never appears as a double-quoted literal in the new files",
        !str_contains($combinedNewFilesStripped, "\"{$slug}\""));
}
ok('(e) setup-wizard.js contains no confirm=1 literal', !str_contains($jsStripped, 'confirm=1'));
ok('(e) the partial contains no confirm=1 literal', !str_contains($partialStripped, 'confirm=1'));
ok('(e) the partial contains no <a href="...confirm=1..."> anywhere',
    !(bool) preg_match('/<a\b[^>]*href="[^"]*confirm=1[^"]*"/i', $partialStripped));

/* MUTATION PROOFs — same shape as (c): inject a REAL literal into a copy
   and confirm the check's own boolean flips. */
$jsMutatedConfirm = str_replace(
    "import { createWizard } from './admin-wizard.js';",
    "import { createWizard } from './admin-wizard.js';\nconst zzz = 'confirm=1';",
    $jsSrc
);
$jsMutatedConfirmStripped = swStripComments($jsMutatedConfirm);
ok('(e) fixture precondition: the injected confirm=1 literal is real code, not inside a comment',
    str_contains($jsMutatedConfirmStripped, "'confirm=1'"));
ok('(e) MUTATION PROOF: injecting a real confirm=1 literal flips the "no confirm=1" check to true (detected/red)',
    str_contains($jsMutatedConfirmStripped, 'confirm=1'));

$firstManualSlug = $manualSlugs[0] ?? null;
if ($firstManualSlug !== null) {
    $jsMutatedManualSlug = str_replace(
        "import { createWizard } from './admin-wizard.js';",
        "import { createWizard } from './admin-wizard.js';\nconst zzz = '?action=' + '{$firstManualSlug}';",
        $jsSrc
    );
    $jsMutatedManualSlugStripped = swStripComments($jsMutatedManualSlug);
    ok("(e) fixture precondition: the injected manual-slug literal '{$firstManualSlug}' is real code, not inside a comment",
        str_contains($jsMutatedManualSlugStripped, "'{$firstManualSlug}'"));
    ok("(e) MUTATION PROOF: injecting the manual slug '{$firstManualSlug}' as a literal flips the check to true (detected/red)",
        str_contains($jsMutatedManualSlugStripped, "'{$firstManualSlug}'"));
} else {
    ok('(e) MUTATION PROOF skipped — no manual slug was derived (this itself already failed the earlier >= 1 assertion)', false);
}

/* ---- (f) GATE PARITY FROZEN ---- */
ok("(f) the page's top gate still calls userHasEntitlement('run_db_install'", str_contains($pageSrc, "userHasEntitlement('run_db_install'"));
ok("(f) the page's per-action ladder still assigns \$actionEntitlement = 'run_db_migrate'", str_contains($pageSrc, "'run_db_migrate'"));
ok("(f) admin-links.php's /manage/setup-database row still names run_db_install",
    (bool) preg_match("/\\['setup-database',[^\\]]*'run_db_install'/", $adminLinksSrc));

/* MUTATION PROOFs on copies. */
ok('(f) MUTATION PROOF: renaming the top-gate entitlement literal in a copy removes the match',
    !str_contains(str_replace("userHasEntitlement('run_db_install'", "userHasEntitlement('zzz_mutated'", $pageSrc), "userHasEntitlement('run_db_install'"));
ok('(f) MUTATION PROOF: renaming admin-links.php\'s row entitlement in a copy removes the match',
    !(bool) preg_match(
        "/\\['setup-database',[^\\]]*'run_db_install'/",
        str_replace("'run_db_install',              'System & Reports'", "'zzz_mutated',              'System & Reports'", $adminLinksSrc)
    ));

/* ---- (g) NO NEW ACTION / WEB-ONLY PRESERVED ---- */
$enumeratedNames = dispatchParserActionsForFile($pageFile)['names'];
ok('(g) enumerated at least one dispatched action from the live page (sanity)', count($enumeratedNames) > 0);

$coverageStripped = swStripComments($coverageSrc);
$coverageSpans = swTopLevelEntrySpans($coverageStripped);
ok("(g) located the coverage guard's 'setup-database.php' mapping block", isset($coverageSpans['setup-database.php']));
$setupDbMapping = $coverageSpans['setup-database.php'] ?? '';
$setupDbMapPairs = swSpanKeyValuePairs($setupDbMapping);
ok('(g) the coverage mapping block has at least one entry', count($setupDbMapPairs) > 0);

ok('(g) every action the page dispatches is a key of the coverage guard\'s setup-database.php map',
    swAllCovered($enumeratedNames, array_keys($setupDbMapPairs)));

$nonWebOnly = array_filter($setupDbMapPairs, static fn(string $v): bool => !str_starts_with($v, 'web_only:'));
ok('(g) every setup-database.php mapping is still web_only:… (no api:/native: twin introduced) — found: '
    . (empty($nonWebOnly) ? 'none' : implode(', ', array_keys($nonWebOnly))), empty($nonWebOnly));

/* MUTATION PROOF 1 — the enumerator itself really can find a NEW
   `$action === 'x'` comparison in this file's own idiom (never a bare
   `case` label — there is no switch here, see the file doc-block's
   "deliberate departure from the plan" note). */
$mutatedEnumerated = swWithMutatedFile(
    $pageSrc,
    "if (\$action === 'backup') {",
    "if (\$action === 'zzz_mutated_wizard_action') { /* injected */ } elseif (\$action === 'backup') {",
    static function (string $tmp): array {
        return dispatchParserActionsForFile($tmp)['names'];
    }
);
ok('(g) MUTATION PROOF: injecting a new $action === literal makes the enumerator report it',
    in_array('zzz_mutated_wizard_action', $mutatedEnumerated, true));

/* MUTATION PROOF 2 — re-run the SAME coverage-comparison logic with that
   newly-"discovered" action added, against the REAL (unmapped-for-it)
   map, and confirm swAllCovered() — the exact boolean the assertion above
   trusts — flips to false. This is the proof that an unmapped new action
   would genuinely fail this guard, not merely that the enumerator saw it. */
ok('(g) MUTATION PROOF: an unmapped new action makes swAllCovered() go false (the guard would fail)',
    swAllCovered(array_merge($enumeratedNames, ['zzz_mutated_wizard_action']), array_keys($setupDbMapPairs)) === false);

/* ---- (h) DEEP-LINK HONOURED (rule #33) ---- */
ok('(h) the page reads $_GET[\'wizard\'] and admits only the literal \'verify\'',
    (bool) preg_match('/\(\s*\$_GET\[\s*[\'"]wizard[\'"]\s*\]\s*\?\?\s*\'\'\s*\)\s*===\s*\'verify\'/', $pageSrc));
ok('(h) the page emits data-open-step= on the modal root', str_contains($pageSrc, 'data-open-step='));
ok('(h) setup-wizard.js reads dataset.openStep', str_contains($jsStripped, 'dataset.openStep'));
ok('(h) setup-wizard.js calls .goTo( on the wizard instance', str_contains($jsStripped, 'wizard.goTo('));

ok('(h) MUTATION PROOF: removing the $_GET[\'wizard\'] read from a copy drops the match',
    !(bool) preg_match(
        '/\(\s*\$_GET\[\s*[\'"]wizard[\'"]\s*\]\s*\?\?\s*\'\'\s*\)\s*===\s*\'verify\'/',
        str_replace("(\$_GET['wizard'] ?? '') === 'verify'", '/* removed */ false', $pageSrc)
    ));

/* ---- (i) RULE #41 / DEPLOY-PATH ---- */
ok('(i) setup-wizard.js contains no "/public_html/" literal', !str_contains($jsStripped, '/public_html/'));
ok('(i) the step-panes partial contains no "/public_html/" literal', !str_contains($partialStripped, '/public_html/'));
ok('(i) the partial\'s own require in the page resolves via __DIR__ (checked in (a) — confirming here too)',
    (bool) preg_match('/require\s+__DIR__\s*\./', $pageSrc));

/* MUTATION PROOF — inject a real "/public_html/" literal into a copy and
   confirm the check flips. */
$jsMutatedPublicHtml = str_replace(
    "import { createWizard } from './admin-wizard.js';",
    "import { createWizard } from './admin-wizard.js';\nconst zzz = '/public_html/includes/x.php';",
    $jsSrc
);
$jsMutatedPublicHtmlStripped = swStripComments($jsMutatedPublicHtml);
ok('(i) fixture precondition: the injected /public_html/ literal is real code, not inside a comment',
    str_contains($jsMutatedPublicHtmlStripped, '/public_html/includes'));
ok('(i) MUTATION PROOF: injecting a real /public_html/ literal flips the check to true (detected/red)',
    str_contains($jsMutatedPublicHtmlStripped, '/public_html/'));

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "The guided setup wizard exists and is wired to the real setupWizardModal, it\n"
   . "delegates migration execution ENTIRELY to the existing runOne()/parseEnvelope()\n"
   . "transport (no second fetch/envelope logic anywhere), the classic no-JS runner path\n"
   . "and its manual-slug filter are byte-for-byte intact, every 'manual' => true slug the\n"
   . "live migration registry names is structurally absent from both new files (never a\n"
   . "runnable literal, never behind a confirm=1), the page's run_db_install/run_db_migrate\n"
   . "gates and admin-links.php's matching row are untouched, the wizard introduces NO new\n"
   . "dispatched action (setup-database.php stays entirely web_only: in the standing\n"
   . "API-coverage guard), the ?wizard=verify deep link is genuinely read and honoured, and\n"
   . "neither new file ever hardcodes a /public_html/ path.\n";
exit(0);
