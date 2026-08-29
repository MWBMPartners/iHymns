<?php

declare(strict_types=1);

/**
 * iHymns — External-Link Types wizard + admin-wizard.js guard (#1992)
 * =====================================================================
 *
 * ELI5
 * ----
 * #1992 gave curators THREE different doors into the same "mint a new
 * link provider" capability — a plain manual form, a guided step-by-step
 * wizard, and a native-app API action — and a brand-new SHARED framework
 * (`js/modules/admin-wizard.js`) the guided wizard is the first of several
 * future wizards to be built on. This file is the standing guard that
 * keeps all three doors wired to the SAME server-side core (never a fork,
 * rule #22), keeps the wizard's live pattern-test honestly calling the
 * REAL detection engine (never a hand-rolled copy), and keeps the shared
 * stepper framework a SINGLETON (never a 6th private re-implementation).
 *
 * WHAT THIS FILE ASSERTS (spec items (a)-(j), the #1992 plan's final §7/D)
 * --------------------------------------------------------------------
 *  (a) Both create-funnels — the page's `wizard_create_type` AJAX branch
 *      and the `create_type` manual-form case, PLUS the API twin
 *      `admin_external_link_type_create` — call
 *      externalLinkTypeAdminValidateNewType() / …NormalisePatterns() /
 *      …Create(), and none of them contains a raw `INSERT INTO
 *      tblExternalLinkTypes`.
 *  (b) Exactly ONE `INSERT INTO tblExternalLinkPatterns` literal exists
 *      anywhere under appWeb/public_html (it lives inside the extracted
 *      `externalLinkTypeAdminInsertPatterns()`), and BOTH
 *      `externalLinkTypeAdminSave()` (edit) and `…Create()` (mint) call
 *      that one function rather than each carrying their own insert loop.
 *  (c) Gate parity (rule #1587): the API create action gates on the SAME
 *      `userHasEntitlement()` key the page itself gates on — read from the
 *      page's own source, never hand-typed here.
 *  (d) The wizard's AJAX branch gates on `validateCsrfRequest()` (rule
 *      #29, same-origin), NOT the legacy form's baked `validateCsrf()`
 *      token — isolated to that one branch.
 *  (e) The live pattern-test genuinely calls the REAL detection engine
 *      (`window.iHymnsLinkDetect.detectFromUrl()` +
 *      `_resetDbRulesCache()`) over a real `window._iHymnsLinkTypes` seed,
 *      and contains NO hand-rolled host-suffix matcher of its own (the
 *      `.endsWith('.'` fingerprint the real engine itself uses internally
 *      — if this page also contained it, that would mean a private copy
 *      of the matching logic, not a call into the shared one).
 *  (f) Un-migrated degrade: the API twin gates on
 *      `externalLinkTypeAdminSchemaReady()`, and both the trigger button
 *      and the modal markup sit inside the page's own schema-ready `<?php
 *      if (...): ?>` gate.
 *  (g) `dispatchParserActionsForFile()` (the SAME tree-derived parser the
 *      standing manage-action/API-coverage guard uses) finds all three of
 *      `save_type_patterns` / `create_type` / `wizard_create_type`
 *      dispatched by the page — proven MUTATION-TESTABLE by writing a
 *      mutated TEMP COPY of the real page source (never the tracked file
 *      itself) with one case's literal renamed, and confirming the parser
 *      stops reporting it.
 *  (h) The manual forms actually FEED their handlers, both directions:
 *      for `save_type_patterns` AND `create_type`, this file ISOLATES the
 *      case body, DERIVES the list of `$_POST['key']` reads from that
 *      body (never a hand-typed field list — a new field is covered
 *      automatically), and asserts the page's HTML emits a matching
 *      `name="key"`/`name="key[]"` control PLUS the hidden
 *      `name="action" value="<action>"` input.
 *  (i) The wizard trigger (button + modal element) is present and imports
 *      `admin-wizard.js`, and BOTH API twins
 *      (`admin_external_link_type_save` / `…_create`) have non-empty case
 *      bodies.
 *  (j) The page never re-implements its own stepper — it calls
 *      `createWizard(` rather than hand-rolling step logic, and EXACTLY
 *      ONE file tree-wide defines `function createWizard(` /
 *      `export function createWizard(` (js/modules/admin-wizard.js).
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, mirroring `test-manage-action-api-coverage.php`'s own
 * precedent:
 *  (1) FIXTURE self-tests (below, PART 1) prove the four PARSING
 *      PRIMITIVES this file is built on — `caseBodyFor()`, `ifBlockFor()`,
 *      `pageEmitsNameFor()`/`pageEmitsHiddenAction()`, and
 *      `findCreateWizardDefiners()` — can both find a marker that IS
 *      there and correctly refuse one that ISN'T, using small synthetic
 *      snippets. These run EVERY invocation.
 *  (2) REAL-CONTENT mutation proofs (inline, PART 3, tagged "MUTATION
 *      PROOF") run the SAME extraction functions against a MUTATED COPY
 *      of the real file content (a `str_replace()`'d in-memory string, or
 *      — for the tree-derived enumerator in (g) — a mutated TEMP FILE
 *      that is written, tested, and deleted within the same assertion,
 *      NEVER the tracked source file) and confirm the check goes red. This
 *      is stronger than a synthetic-only fixture because it exercises the
 *      exact real structure this guard polices, and it runs every time
 *      rather than being a one-off dev-time exercise.
 *
 * @link .claude/live-follow-1770-plan.md            an earlier example of the "wizard" shape sans framework
 * @see appWeb/public_html/js/modules/admin-wizard.js        the shared stepper this guards
 * @see appWeb/public_html/manage/external-link-types.php    the first consumer
 * @see appWeb/public_html/includes/external_link_type_admin.php  the shared write core
 * @see appWeb/public_html/api.php                           the admin_external_link_type_* API twins
 * @see tests/php/test-manage-action-api-coverage.php        the mutation-self-test precedent this mirrors
 * @see tests/php/lib/dispatch_parser.php                    shared tokeniser this file reuses for (g)
 *
 *   php tests/php/test-external-link-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo       = dirname(__DIR__, 2);
$pageFile   = $repo . '/appWeb/public_html/manage/external-link-types.php';
$apiFile    = $repo . '/appWeb/public_html/api.php';
$coreFile   = $repo . '/appWeb/public_html/includes/external_link_type_admin.php';
$wizardFile = $repo . '/appWeb/public_html/js/modules/admin-wizard.js';
$jsDir      = $repo . '/appWeb/public_html/js';
$publicHtml = $repo . '/appWeb/public_html';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  ❌ {$label}\n"; }
}

/* =========================================================================
 * PART 0 — parsing primitives this file is built on.
 * ========================================================================= */

/** Concatenate token TEXT for tokens [$from, $to] inclusive — reconstructs
 *  the exact original source bytes that range covers. */
function elwTokensToSource(array $toks, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i <= $to; $i++) {
        $out .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    }
    return $out;
}

/**
 * Extract the raw source text of ONE case's body within `switch ($switchVar)`
 * in `$file` — everything from just after `case '<caseName>':` up to (not
 * including) the next `case`/`default` at the same depth, or the switch's
 * own closing brace. Built on `dispatch_parser.php`'s already-mutation-
 * tested `dispatchParserCaseTokens()` (rule #22 — never a second
 * tokeniser walk). Returns '' when the switch or the case isn't found.
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
        /* Last case in the switch — walk forward by brace depth (relative
           to the switch's own opening brace, which we are already inside
           at depth 1) to find where the switch itself closes. */
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
    return elwTokensToSource($toks, $bodyStart, $bodyEnd);
}

/**
 * Extract the raw source text INSIDE the `{ … }` of the first `if (...)`
 * statement whose condition contains the literal `$conditionNeedle` —
 * used for the page's `if ($action === 'wizard_create_type') { … }` block,
 * which is a plain if, not a switch case. Plain byte-level brace matching
 * (deliberately not a full tokeniser) — safe for THIS known region because
 * none of the code inside it contains a literal `{`/`}` byte inside a
 * string (verified by the guard's own presence assertions passing).
 */
function ifBlockFor(string $src, string $conditionNeedle): string
{
    $pos = strpos($src, $conditionNeedle);
    if ($pos === false) { return ''; }
    $openBrace = strpos($src, '{', $pos);
    if ($openBrace === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $openBrace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $openBrace + 1, $i - $openBrace - 1);
            }
        }
    }
    return '';
}

/** Every unique `$_POST['key']` (or `["key"]`) read inside `$body`, comment-
 *  stripped first so a doc-comment mentioning `$_POST[...]` in prose can't
 *  inflate the derived field list. */
function postKeysInBody(string $body): array
{
    $stripped = preg_replace('~/\*.*?\*/~s', '', $body) ?? $body;
    preg_match_all('/\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
    return array_values(array_unique($m[1]));
}

/** Does `$pageSrc` contain an element carrying `name="$key"` or
 *  `name="$key[]"` (single or double quotes)? */
function pageEmitsNameFor(string $pageSrc, string $key): bool
{
    $needle = preg_quote($key, '/');
    return (bool)preg_match('/name\s*=\s*([\'"])' . $needle . '(\[\])?\1/', $pageSrc);
}

/** Does `$pageSrc` contain an `<input ...>` tag carrying BOTH
 *  `name="action"` and `value="$actionName"` (either attribute order)? */
function pageEmitsHiddenAction(string $pageSrc, string $actionName): bool
{
    if (!preg_match_all('/<input\b[^>]*>/i', $pageSrc, $m)) { return false; }
    $needleVal = preg_quote($actionName, '/');
    foreach ($m[0] as $tag) {
        if (preg_match('/name\s*=\s*[\'"]action[\'"]/', $tag)
            && preg_match('/value\s*=\s*[\'"]' . $needleVal . '[\'"]/', $tag)) {
            return true;
        }
    }
    return false;
}

/** Extract a plain PHP function's body by name via brace-depth matching —
 *  safe for the two specific functions this file uses it on (verified:
 *  neither contains a literal `{`/`}` byte inside a string). */
function functionBodyFor(string $src, string $fnName): string
{
    if (!preg_match('/function\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $m[0][1];
    $openBrace = strpos($src, '{', $start);
    if ($openBrace === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($i = $openBrace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $openBrace + 1, $i - $openBrace - 1); }
        }
    }
    return '';
}

/** Every .js file under `$dir` (recursive) that defines
 *  `function createWizard(` / `export function createWizard(` — comment-
 *  stripped first so a mention in a doc-block doesn't count. */
function findCreateWizardDefiners(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'js') { continue; }
        $src = (string)file_get_contents($f->getPathname());
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
        if (preg_match('/\bfunction\s+createWizard\s*\(/', $src)) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

/** Write `$mutatedSrc` (str_replace applied to `$originalSrc`) to a fresh
 *  temp file, run `$fn($tmpPath)`, delete the temp file, return the
 *  result. Never touches a tracked source file. */
function withMutatedFile(string $originalSrc, string $search, string $replace, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_elw_mut_') . '.php';
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
    case 'gamma': {
        doGamma();
        break;
    }
}
PHP;
$fixtureSwitchFile = tempnam(sys_get_temp_dir(), 'ihymns_elw_fixture_') . '.php';
file_put_contents($fixtureSwitchFile, $fixtureSwitchSrc);

$betaBody = caseBodyFor($fixtureSwitchFile, '$action', 'beta');
ok('caseBodyFor() finds a marker genuinely inside the target case', str_contains($betaBody, 'doBeta('));
ok('MUTATION PROOF: caseBodyFor() does not leak the PRECEDING case\'s marker', !str_contains($betaBody, 'doAlpha('));
ok('MUTATION PROOF: caseBodyFor() does not leak the FOLLOWING case\'s marker', !str_contains($betaBody, 'doGamma('));
ok('MUTATION PROOF: caseBodyFor() returns "" for an absent case name', caseBodyFor($fixtureSwitchFile, '$action', 'delta') === '');

$gammaBody = caseBodyFor($fixtureSwitchFile, '$action', 'gamma'); // last case — the depth-walk-to-switch-close path
ok('caseBodyFor() correctly closes the LAST case in a switch via depth-walk', str_contains($gammaBody, 'doGamma(') && !str_contains($gammaBody, 'doBeta('));

unlink($fixtureSwitchFile);

/* ---- ifBlockFor() ---- */
$fixtureIfSrc = <<<'PHP'
<?php
if ($x) {
    before();
}
if ($action === 'target') {
    inside1();
    if ($nested) { inside2(); }
    inside3();
}
after();
PHP;
$ifBody = ifBlockFor($fixtureIfSrc, "\$action === 'target'");
ok('ifBlockFor() finds markers genuinely inside the target if-block (incl. past a nested brace)',
    str_contains($ifBody, 'inside1(') && str_contains($ifBody, 'inside2(') && str_contains($ifBody, 'inside3('));
ok('MUTATION PROOF: ifBlockFor() does not include code BEFORE the block', !str_contains($ifBody, 'before('));
ok('MUTATION PROOF: ifBlockFor() does not include code AFTER the block', !str_contains($ifBody, 'after('));
ok('MUTATION PROOF: ifBlockFor() returns "" for an absent condition', ifBlockFor($fixtureIfSrc, "\$action === 'nope'") === '');

/* ---- pageEmitsNameFor() / pageEmitsHiddenAction() ---- */
$fixtureHtml = '<input type="text" name="pattern_host[]"><input type="hidden" name="action" value="create_type">';
ok('pageEmitsNameFor() finds a real name= control', pageEmitsNameFor($fixtureHtml, 'pattern_host'));
ok('MUTATION PROOF: pageEmitsNameFor() refuses an absent field name', !pageEmitsNameFor($fixtureHtml, 'pattern_priority'));
ok('pageEmitsHiddenAction() finds the matching hidden action input', pageEmitsHiddenAction($fixtureHtml, 'create_type'));
ok('MUTATION PROOF: pageEmitsHiddenAction() refuses a non-matching action value', !pageEmitsHiddenAction($fixtureHtml, 'save_type_patterns'));

/* ---- findCreateWizardDefiners() ---- */
$fixtureJsDir = sys_get_temp_dir() . '/ihymns_elw_js_fixture_' . uniqid();
mkdir($fixtureJsDir . '/modules', 0777, true);
file_put_contents($fixtureJsDir . '/modules/a.js', "export function createWizard(root, opts) {}\n");
file_put_contents($fixtureJsDir . '/modules/b.js', "export function somethingElse() {}\n// mentions createWizard( in a comment only, must be ignored\n");
$foundOne = findCreateWizardDefiners($fixtureJsDir);
ok('findCreateWizardDefiners() finds the real definer', $foundOne === [$fixtureJsDir . '/modules/a.js']);
ok('MUTATION PROOF: findCreateWizardDefiners() ignores a comment-only mention', !in_array($fixtureJsDir . '/modules/b.js', $foundOne, true));
file_put_contents($fixtureJsDir . '/modules/c.js', "function createWizard(x) {}\n");
$foundTwo = findCreateWizardDefiners($fixtureJsDir);
ok('MUTATION PROOF: findCreateWizardDefiners() reports TWO definers once a second copy exists (never silently capped at one)', count($foundTwo) === 2);
unlink($fixtureJsDir . '/modules/a.js');
unlink($fixtureJsDir . '/modules/b.js');
unlink($fixtureJsDir . '/modules/c.js');
rmdir($fixtureJsDir . '/modules');
rmdir($fixtureJsDir);

/* =========================================================================
 * PART 2 — load the real sources.
 * ========================================================================= */

foreach ([$pageFile, $apiFile, $coreFile, $wizardFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc  = (string)file_get_contents($pageFile);
$apiSrc   = (string)file_get_contents($apiFile);
$coreSrc  = (string)file_get_contents($coreFile);

$pageWizardBody = ifBlockFor($pageSrc, "\$action === 'wizard_create_type'");
$pageManualBody = caseBodyFor($pageFile, '$action', 'create_type');
$pageSaveBody   = caseBodyFor($pageFile, '$action', 'save_type_patterns');
$apiCreateBody  = caseBodyFor($apiFile, '$action', 'admin_external_link_type_create');
$apiSaveBody    = caseBodyFor($apiFile, '$action', 'admin_external_link_type_save');

ok('isolated the page\'s wizard_create_type if-block (non-empty)', $pageWizardBody !== '');
ok('isolated the page\'s create_type case body (non-empty)', $pageManualBody !== '');
ok('isolated the page\'s save_type_patterns case body (non-empty)', $pageSaveBody !== '');
ok('isolated the API\'s admin_external_link_type_create case body (non-empty)', $apiCreateBody !== '');
ok('isolated the API\'s admin_external_link_type_save case body (non-empty)', $apiSaveBody !== '');

/* =========================================================================
 * PART 3 — the (a)-(j) assertions.
 * ========================================================================= */

echo "\nExternal-Link Types wizard + admin-wizard.js guard (#1992)\n\n";

/* ---- (a) all three create-funnels call Validate/Normalise/Create, never
   a raw INSERT INTO tblExternalLinkTypes ---- */
foreach ([
    'page wizard_create_type branch'           => $pageWizardBody,
    'page create_type case'                    => $pageManualBody,
    'api admin_external_link_type_create case' => $apiCreateBody,
] as $label => $body) {
    ok("(a) {$label} calls externalLinkTypeAdminValidateNewType(", str_contains($body, 'externalLinkTypeAdminValidateNewType('));
    ok("(a) {$label} calls externalLinkTypeAdminNormalisePatterns(", str_contains($body, 'externalLinkTypeAdminNormalisePatterns('));
    ok("(a) {$label} calls externalLinkTypeAdminCreate(", str_contains($body, 'externalLinkTypeAdminCreate('));
    ok("(a) {$label} contains no raw INSERT INTO tblExternalLinkTypes", !preg_match('/INSERT\s+INTO\s+tblExternalLinkTypes/i', $body));
}

/* ---- (b) exactly ONE "INSERT INTO tblExternalLinkPatterns" literal tree-
   wide, and both Save()/Create() call the one function that contains it ---- */
$insertPatternHits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicHtml, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
    $src = (string)file_get_contents($f->getPathname());
    $stripped = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $hits = preg_match_all('/INSERT\s+INTO\s+tblExternalLinkPatterns\b/i', $stripped);
    for ($i = 0; $i < $hits; $i++) { $insertPatternHits[] = $f->getPathname(); }
}
ok('(b) exactly ONE "INSERT INTO tblExternalLinkPatterns" literal tree-wide (' . count($insertPatternHits) . ' found)',
    count($insertPatternHits) === 1);
ok('(b) ...and it lives in includes/external_link_type_admin.php',
    ($insertPatternHits[0] ?? '') === realpath($coreFile));

$saveFnBody   = functionBodyFor($coreSrc, 'externalLinkTypeAdminSave');
$createFnBody = functionBodyFor($coreSrc, 'externalLinkTypeAdminCreate');
ok('(b) externalLinkTypeAdminSave() body isolated (non-empty)', $saveFnBody !== '');
ok('(b) externalLinkTypeAdminCreate() body isolated (non-empty)', $createFnBody !== '');
ok('(b) externalLinkTypeAdminSave() calls externalLinkTypeAdminInsertPatterns(', str_contains($saveFnBody, 'externalLinkTypeAdminInsertPatterns('));
ok('(b) externalLinkTypeAdminCreate() calls externalLinkTypeAdminInsertPatterns(', str_contains($createFnBody, 'externalLinkTypeAdminInsertPatterns('));
ok('(b) neither Save() nor Create() has its OWN INSERT INTO tblExternalLinkPatterns',
    !preg_match('/INSERT\s+INTO\s+tblExternalLinkPatterns/i', $saveFnBody)
    && !preg_match('/INSERT\s+INTO\s+tblExternalLinkPatterns/i', $createFnBody));

/* ---- (c) gate parity — entitlement key read from the PAGE, not hand-typed ---- */
preg_match("/userHasEntitlement\('([a-z_]+)'/", $pageSrc, $mEnt);
$entKey = $mEnt[1] ?? null;
ok('(c) extracted the page\'s own entitlement key', $entKey !== null);
if ($entKey !== null) {
    ok("(c) api admin_external_link_type_create gates on userHasEntitlement('{$entKey}'",
        str_contains($apiCreateBody, "userHasEntitlement('{$entKey}'"));
}

/* ---- (d) wizard branch CSRF: validateCsrfRequest(, isolated ---- */
ok('(d) wizard_create_type branch calls validateCsrfRequest(', str_contains($pageWizardBody, 'validateCsrfRequest('));
$classicDispatchStart = strpos($pageSrc, "if (!validateCsrf(");
ok('(d) found the classic-form validateCsrf() gate', $classicDispatchStart !== false);
if ($classicDispatchStart !== false) {
    $classicRegion = substr($pageSrc, $classicDispatchStart);
    ok('(d) the classic form dispatch (save_type_patterns/create_type) does not itself call validateCsrfRequest(',
        !str_contains($classicRegion, 'validateCsrfRequest('));
}

/* ---- (e) live-test via the REAL engine, never a fork ---- */
ok('(e) page calls window.iHymnsLinkDetect.detectFromUrl(', str_contains($pageSrc, 'iHymnsLinkDetect.detectFromUrl('));
ok('(e) page calls _resetDbRulesCache(', str_contains($pageSrc, '_resetDbRulesCache('));
ok('(e) page emits window._iHymnsLinkTypes', str_contains($pageSrc, 'window._iHymnsLinkTypes ='));
ok('(e) page contains NO hand-rolled matcher fingerprint (".endsWith(\'.\'")', !str_contains($pageSrc, ".endsWith('."));

/* ---- (f) un-migrated degrade ---- */
ok('(f) api admin_external_link_type_create gates on externalLinkTypeAdminSchemaReady(', str_contains($apiCreateBody, 'externalLinkTypeAdminSchemaReady('));

preg_match_all('/if\s*\(\s*\$hasTypesSchema\s*&&\s*\$hasPatternsSchema\s*\)/', $pageSrc, $mGates, PREG_OFFSET_CAPTURE);
$gatePositions = array_map(static fn(array $x): int => $x[1], $mGates[0]);
ok('(f) found at least 2 schema-ready gates in the page (header button + modal block)', count($gatePositions) >= 2);

function elwNearestPrecedingGate(array $gatePositions, int $targetPos): ?int
{
    $best = null;
    foreach ($gatePositions as $g) { if ($g < $targetPos) { $best = $g; } }
    return $best;
}
$btnPos   = strpos($pageSrc, 'data-bs-target="#linkTypeWizardModal"');
$modalPos = strpos($pageSrc, '<div class="modal fade" id="linkTypeWizardModal"');
ok('(f) found the trigger button and the modal element', $btnPos !== false && $modalPos !== false);
if ($btnPos !== false) {
    ok('(f) the trigger button sits after a schema-ready gate', elwNearestPrecedingGate($gatePositions, $btnPos) !== null);
}
if ($modalPos !== false) {
    ok('(f) the modal markup sits after a schema-ready gate', elwNearestPrecedingGate($gatePositions, $modalPos) !== null);
}

/* ---- (g) dispatchParserActionsForFile() superset + MUTATION PROOF ---- */
$pageActions = dispatchParserActionsForFile($pageFile)['names'];
foreach (['save_type_patterns', 'create_type', 'wizard_create_type'] as $need) {
    ok("(g) dispatchParserActionsForFile() finds '{$need}' dispatched by the page", in_array($need, $pageActions, true));
}
foreach (['save_type_patterns' => "case 'save_type_patterns':", 'create_type' => "case 'create_type':"] as $name => $needle) {
    ok("(g) fixture precondition: literal '{$needle}' really is in the page source", str_contains($pageSrc, $needle));
    $mutatedNames = withMutatedFile($pageSrc, $needle, "case 'zzz_mutated_{$name}':", static function (string $tmp): array {
        return dispatchParserActionsForFile($tmp)['names'];
    });
    ok("(g) MUTATION PROOF: renaming the '{$name}' case literal makes the enumerator stop reporting it",
        !in_array($name, $mutatedNames, true));
}
$wizardNeedle = "\$action === 'wizard_create_type'";
ok('(g) fixture precondition: the wizard if-condition literal really is in the page source', str_contains($pageSrc, $wizardNeedle));
$mutatedWizardNames = withMutatedFile($pageSrc, $wizardNeedle, "\$action === 'zzz_mutated_wizard_create_type'", static function (string $tmp): array {
    return dispatchParserActionsForFile($tmp)['names'];
});
ok('(g) MUTATION PROOF: renaming the wizard_create_type comparison makes the enumerator stop reporting it',
    !in_array('wizard_create_type', $mutatedWizardNames, true));

/* ---- (h) manual forms feed their handlers, field list DERIVED from the
   handler's own $_POST reads (never a typed field list) ----
   'slug' is a KNOWN indirect emission: #1870's shared partial
   (manage/includes/slug-field.php::ihymns_slug_advanced_field(), itself a
   tree-derived + mutation-proven guard — test-slug-field-partial.php)
   renders the `name="slug"` control on the page's behalf, so a page
   calling it is treated as emitting that field even though the literal
   text doesn't appear in THIS file's own source (rule #22 — reuse the
   shared control rather than a 12th pasted one). Any other field is
   required to appear literally in this page's own source. */
$indirectFieldEmitters = [
    'slug' => 'ihymns_slug_advanced_field(',
];
foreach (['save_type_patterns' => $pageSaveBody, 'create_type' => $pageManualBody] as $actionName => $body) {
    ok("(h) page emits hidden name=\"action\" value=\"{$actionName}\"", pageEmitsHiddenAction($pageSrc, $actionName));
    $keys = postKeysInBody($body);
    ok("(h) derived >= 1 \$_POST field read for '{$actionName}' ({$actionName}: " . count($keys) . ' fields)', count($keys) > 0);
    foreach ($keys as $key) {
        $viaSharedPartial = isset($indirectFieldEmitters[$key]) && str_contains($pageSrc, $indirectFieldEmitters[$key]);
        $label = "(h) '{$actionName}' reads \$_POST['{$key}'] and the page emits a matching name= control"
            . ($viaSharedPartial ? ' (via the #1870 shared slug-field partial)' : '');
        ok($label, pageEmitsNameFor($pageSrc, $key) || $viaSharedPartial);
    }
}
/* MUTATION PROOF: strip one manual-form control's name= attribute from a
   COPY of the page source and confirm the presence check goes red. */
ok('(h) fixture precondition: name="category" really is in the page source', str_contains($pageSrc, 'name="category"'));
$mutatedNoCategory = str_replace('name="category"', 'data-removed="category"', $pageSrc);
ok('(h) MUTATION PROOF: removing the category control\'s name= makes pageEmitsNameFor() go false',
    !pageEmitsNameFor($mutatedNoCategory, 'category'));
ok('(h) fixture precondition: the create_type hidden action input really is in the page source',
    pageEmitsHiddenAction($pageSrc, 'create_type'));
$mutatedNoHiddenAction = str_replace('value="create_type"', 'value="renamed_type"', $pageSrc);
ok('(h) MUTATION PROOF: renaming the hidden action value makes pageEmitsHiddenAction() go false for create_type',
    !pageEmitsHiddenAction($mutatedNoHiddenAction, 'create_type'));
ok('(h) fixture precondition: the page really does call ihymns_slug_advanced_field(', str_contains($pageSrc, 'ihymns_slug_advanced_field('));
$mutatedNoSlugPartialCall = str_replace('ihymns_slug_advanced_field(', 'somethingElse(', $pageSrc);
ok('(h) MUTATION PROOF: removing the ihymns_slug_advanced_field( call makes the indirect slug-emission check go false',
    !(pageEmitsNameFor($mutatedNoSlugPartialCall, 'slug') || str_contains($mutatedNoSlugPartialCall, 'ihymns_slug_advanced_field(')));

/* ---- (i) wizard entry present + both API twins survive ---- */
ok('(i) page emits a wizard trigger with data-bs-target="#linkTypeWizardModal"', str_contains($pageSrc, 'data-bs-target="#linkTypeWizardModal"'));
ok('(i) page emits the modal element id="linkTypeWizardModal"', str_contains($pageSrc, 'id="linkTypeWizardModal"'));
ok('(i) page imports admin-wizard.js', (bool)preg_match('~from\s+[\'"]/js/modules/admin-wizard\.js~', $pageSrc));
ok('(i) api admin_external_link_type_save case body is non-empty', $apiSaveBody !== '');
ok('(i) api admin_external_link_type_create case body is non-empty', $apiCreateBody !== '');

$mutatedNoBtn = str_replace('data-bs-target="#linkTypeWizardModal"', '', $pageSrc);
ok('(i) MUTATION PROOF: removing the trigger button\'s data-bs-target makes the presence check go false',
    !str_contains($mutatedNoBtn, 'data-bs-target="#linkTypeWizardModal"'));
$mutatedApiCreateGone = withMutatedFile($apiSrc, "case 'admin_external_link_type_create':", "case 'zzz_removed_create':", static function (string $tmp): string {
    return caseBodyFor($tmp, '$action', 'admin_external_link_type_create');
});
ok('(i) MUTATION PROOF: removing the API create case makes caseBodyFor() return ""', $mutatedApiCreateGone === '');

/* ---- (j) stepper reuse — not a 6th fork ---- */
ok('(j) page calls createWizard(', str_contains($pageSrc, 'createWizard('));
$definers = findCreateWizardDefiners($jsDir);
ok('(j) exactly ONE createWizard definition tree-wide, in js/modules/admin-wizard.js (found: ' . implode(', ', $definers) . ')',
    $definers === [realpath($wizardFile)]);

/* ---- (k) security audit F2 — pattern-row DoS cap enforced at EVERY
   caller of externalLinkTypeAdminNormalisePatterns(), both page branches
   AND both API twins, each checking BEFORE the normaliser is ever called
   and responding 422 on excess (never a silent truncation). The ONE cap
   constant is IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP
   (includes/external_link_type_admin.php) — every site below is asserted
   to reference that SAME constant, not a re-typed magic number, so a
   future edit that drops the shared constant in favour of a hand-typed
   100 cannot pass silently. ---- */
ok('(k) includes/external_link_type_admin.php defines the ONE shared row-count cap constant IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP',
    (bool)preg_match('/const\s+IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP\s*=/', $coreSrc));

/** True when `$body` checks the shared pattern-row cap constant AND
 *  responds 422 on excess — the two facts this guard cares about, without
 *  caring whether the site is JSON (echo json_encode/sendJson) or a
 *  classic-form $error+break, since both funnels use the shape their own
 *  surrounding code already established. Comment-stripped FIRST — a
 *  doc-comment naming the constant (this file's own edit added exactly
 *  that, right above every real check) must never satisfy this on its
 *  own; only a REFERENCE IN CODE counts. */
function elwHasPatternRowCapCheck(string $body): bool
{
    $stripped = preg_replace('~/\*.*?\*/~s', '', $body) ?? $body;
    return str_contains($stripped, 'IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP') && str_contains($stripped, '422');
}

/* Fixture self-test (rule #34) BEFORE trusting elwHasPatternRowCapCheck()
   against real source — must accept a genuine cap-check shape and refuse
   one missing either half. */
$capFixtureGood = "if (max(count(\$pHosts), count(\$pPaths)) > IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP) {\n"
    . "    http_response_code(422);\n    echo json_encode(['error' => 'Too many pattern rows.']);\n    exit;\n}";
ok('elwHasPatternRowCapCheck() accepts a genuine cap-check body (constant + 422 together)',
    elwHasPatternRowCapCheck($capFixtureGood));
ok('MUTATION PROOF: elwHasPatternRowCapCheck() refuses a body with the constant but no 422 response',
    !elwHasPatternRowCapCheck(str_replace('422', '200', $capFixtureGood)));
ok('MUTATION PROOF: elwHasPatternRowCapCheck() refuses a body with a 422 but no cap-constant reference (a hand-typed magic number)',
    !elwHasPatternRowCapCheck(str_replace('IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP', '100', $capFixtureGood)));
/* The trap this guard's own first draft fell into (caught by the LIVE
   mutation proof against the real tree below, not this fixture — kept
   here too so the failure mode has a fast, isolated regression test): a
   doc-comment ABOVE the real check ALSO names the constant (this file's
   own edits do exactly that), so a naive check must not be satisfied by
   the comment alone once the real condition is gutted. */
$capFixtureCommentOnlyMention = '/* Security audit F2 — checks IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP, responds 422. */'
    . "\nif (false) { /* condition gutted */ }";
ok('MUTATION PROOF: elwHasPatternRowCapCheck() refuses a body where the constant + 422 appear ONLY inside a comment, not in real code',
    !elwHasPatternRowCapCheck($capFixtureCommentOnlyMention));

foreach ([
    'page wizard_create_type branch'           => $pageWizardBody,
    'page save_type_patterns case'             => $pageSaveBody,
    'page create_type case'                    => $pageManualBody,
    'api admin_external_link_type_save case'   => $apiSaveBody,
    'api admin_external_link_type_create case' => $apiCreateBody,
] as $label => $body) {
    ok("(k) {$label} checks the pattern-row cap (IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP) and responds 422 on excess",
        elwHasPatternRowCapCheck($body));
}

/* MUTATION PROOF: strip the cap constant reference from a COPY of the real
   create_type case body (str_replace, never the tracked file) and confirm
   the presence check goes red — proves this isn't vacuously true against
   real source. */
ok('(k) fixture precondition: the real create_type case body genuinely references the cap constant',
    str_contains($pageManualBody, 'IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP'));
$mutatedManualNoCap = str_replace('IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP', 'ZZZ_REMOVED_CAP', $pageManualBody);
ok('(k) MUTATION PROOF: removing the cap-constant reference from a copy of the real create_type body makes the check go red',
    !elwHasPatternRowCapCheck($mutatedManualNoCap));
ok('(k) fixture precondition: the real admin_external_link_type_create API body genuinely references the cap constant',
    str_contains($apiCreateBody, 'IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP'));
$mutatedApiNoCap = str_replace('IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP', 'ZZZ_REMOVED_CAP', $apiCreateBody);
ok('(k) MUTATION PROOF: removing the cap-constant reference from a copy of the real API create body makes the check go red',
    !elwHasPatternRowCapCheck($mutatedApiNoCap));

/* ---- (l) a11y audit F1/F12 — every control addPatternRow() builds
   carries an accessible name (the "Match sub-domains" checkbox was
   nameless before this pass; the host/path/priority inputs already had
   aria-label and are re-asserted here so the pattern can't regress), and
   the Remove button's name is DISTINCT per row (patternSeq-derived), not
   the same "Remove" repeated for every row. ---- */
$addPatternRowBody = functionBodyFor($pageSrc, 'addPatternRow');
ok('(l) isolated addPatternRow() body (non-empty)', $addPatternRowBody !== '');
foreach ([
    'data-wiz-pattern-host'       => 'aria-label="Pattern host"',
    'data-wiz-pattern-path'       => 'aria-label="Pattern path prefix"',
    'data-wiz-pattern-priority'   => 'aria-label="Pattern priority"',
    'data-wiz-pattern-subdomain (Match sub-domains checkbox)' => 'aria-label="Match sub-domains"',
] as $label => $needle) {
    ok("(l) addPatternRow() names the {$label} control ({$needle})", str_contains($addPatternRowBody, $needle));
}
ok('(l) addPatternRow() gives the Remove button a name DISTINCT per row (derived from patternSeq, not a fixed "Remove")',
    str_contains($addPatternRowBody, "aria-label=\"Remove pattern row ' + patternSeq + '\""));

/* MUTATION PROOF: strip the Match-sub-domains aria-label from a COPY of
   the real function body and confirm the presence check goes red. */
ok('(l) fixture precondition: the real addPatternRow() body genuinely names the Match-sub-domains checkbox',
    str_contains($addPatternRowBody, 'aria-label="Match sub-domains"'));
$mutatedNoSubdomainLabel = str_replace('aria-label="Match sub-domains"', '', $addPatternRowBody);
ok('(l) MUTATION PROOF: removing the Match-sub-domains aria-label from a copy of the real body makes the check go red',
    !str_contains($mutatedNoSubdomainLabel, 'aria-label="Match sub-domains"'));
ok('(l) fixture precondition: the real addPatternRow() body genuinely derives the Remove name from patternSeq',
    str_contains($addPatternRowBody, "aria-label=\"Remove pattern row ' + patternSeq + '\""));
$mutatedFixedRemoveName = str_replace("aria-label=\"Remove pattern row ' + patternSeq + '\"", '', $addPatternRowBody);
ok('(l) MUTATION PROOF: reverting the Remove button to a fixed (non-patternSeq) name makes the per-row-distinct check go red',
    !str_contains($mutatedFixedRemoveName, "aria-label=\"Remove pattern row ' + patternSeq + '\""));

/* ---- (m) a11y audit F2/F6 — testPatternRow()'s live pattern-test status
   uses the -emphasis contrast tokens (never a bare text-success/
   text-warning/text-danger, which measures below WCAG 1.4.3 on this
   card's background in at least one theme). ---- */
$testPatternRowBody = functionBodyFor($pageSrc, 'testPatternRow');
ok('(m) isolated testPatternRow() body (non-empty)', $testPatternRowBody !== '');
foreach (['text-success-emphasis', 'text-warning-emphasis', 'text-danger-emphasis'] as $token) {
    ok("(m) testPatternRow() sets the {$token} token on the status element", str_contains($testPatternRowBody, $token));
}
/** True when `$body` contains a BARE text-success/text-warning/text-danger
 *  class (i.e. NOT immediately followed by "-emphasis"). */
function elwHasBareStatusColourClass(string $body): bool
{
    return (bool)preg_match('/\btext-(?:success|warning|danger)\b(?!-emphasis)/', $body);
}
ok('elwHasBareStatusColourClass() accepts a fixture with only -emphasis tokens',
    !elwHasBareStatusColourClass("className = 'small text-success-emphasis';"));
ok('MUTATION PROOF: elwHasBareStatusColourClass() flags a fixture with a bare text-danger class',
    elwHasBareStatusColourClass("className = 'small text-danger';"));
ok('(m) testPatternRow() contains NO bare text-success/text-warning/text-danger status class (WCAG 1.4.3)',
    !elwHasBareStatusColourClass($testPatternRowBody));

/* MUTATION PROOF: revert one -emphasis token to bare on a COPY of the real
   body and confirm the check goes red. */
ok('(m) fixture precondition: the real testPatternRow() body genuinely uses text-danger-emphasis',
    str_contains($testPatternRowBody, 'text-danger-emphasis'));
$mutatedBareDanger = str_replace('text-danger-emphasis', 'text-danger', $testPatternRowBody);
ok('(m) MUTATION PROOF: reverting text-danger-emphasis to bare text-danger on a copy of the real body makes the check go red',
    elwHasBareStatusColourClass($mutatedBareDanger));

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "Every #1992 create-funnel (manual form / guided wizard / API twin) delegates to the SAME\n"
   . "shared core, the wizard's live-test genuinely calls the real detection engine, both manual\n"
   . "forms feed their handlers' own \$_POST reads, and js/modules/admin-wizard.js's createWizard()\n"
   . "stays the ONE stepper implementation tree-wide.\n";
exit(0);
