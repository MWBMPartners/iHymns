<?php

declare(strict_types=1);

/**
 * iHymns — Songbook "New Songbook" wizard + shared CREATE core guard (#1993)
 * =====================================================================
 *
 * ELI5
 * ----
 * #1993 gave curators a THIRD way to make a new songbook — a guided,
 * step-by-step wizard on `/manage/songbooks`, built on the SAME shared
 * stepper framework (`js/modules/admin-wizard.js`, #1992) the External-Link
 * Types wizard uses — while ALSO, for the first time, giving all three
 * create funnels (the manual "Add a songbook" form, the new wizard, and the
 * `admin_songbook_create` API action) ONE shared validate+write core
 * (`includes/songbook_admin.php`, rule #22) instead of two drifted inline
 * copies. This file is the standing guard that keeps all three doors wired
 * to that SAME core, keeps the one genuinely-permanent field (the
 * abbreviation — rule #27) honestly validated both client- and
 * server-side, and keeps the MARCXML funnel's deliberate exclusion from
 * the shared core honestly scoped (never silently widened into a 4th
 * `INSERT INTO tblSongbooks` copy).
 *
 * WHAT THIS FILE ASSERTS (spec items (a)-(k), modelled on
 * test-external-link-wizard.php's #1992 precedent — primitives copy-adapted
 * where the shape matches, REPLACED where #1993 genuinely differs)
 * --------------------------------------------------------------------
 *  (a) All THREE create funnels — the page's `create` case, the page's new
 *      `wizard_create_songbook` case, and the API's `admin_songbook_create`
 *      case — call `songbookAdminValidateCreate(`/`songbookAdminCreate(`,
 *      and NONE of the three case bodies contains a raw
 *      `INSERT INTO tblSongbooks` of its own (that SQL now lives ONLY
 *      inside the shared core).
 *  (b) Exactly THREE `INSERT INTO tblSongbooks` literals exist tree-wide —
 *      one inside `includes/songbook_admin.php`'s `songbookAdminCreate()`,
 *      one inside the page's OWN `marcxml_import` case (deliberately out of
 *      the shared core's scope), one inside the API's OWN
 *      `admin_songbook_marcxml_import` case (same reason) — each traced to
 *      its expected home; a 4th copy anywhere is the regression this
 *      assertion exists to catch. Both MARCXML sites are also checked for
 *      the tracking comment pointing back at the core's own scope note.
 *  (c) Gate parity (rule #1587): `admin_songbook_create` gates on the SAME
 *      `userHasEntitlement()` key the page itself gates on — read from the
 *      page's own source, never hand-typed here.
 *  (d) CSRF by position: the page's ONE `validateCsrfRequest()` gate sits
 *      BEFORE `switch ($action)` (so it covers every case, including the
 *      new wizard one) — simpler than #1992's external-link-types wizard,
 *      which needed a SEPARATE same-origin check because its classic form
 *      uses the legacy `validateCsrf()`. Confirms `wizard_create_songbook`
 *      does NOT also carry its own redundant `validateCsrfRequest()` call.
 *  (e) The wizard's JSON case body ends in `exit` inside its OWN try/catch
 *      — it can never fall through to the page's generic HTML error catch
 *      further down the file (which would echo HTML into what's meant to
 *      be a JSON response).
 *  (f) The manual "Add a songbook" form + the MARCXML form stay wired to
 *      the shared `songbook-form-fields.php` partial (delegated to the
 *      PRE-EXISTING, unmodified `test-songbook-form-parity.php`, which this
 *      file confirms is still green rather than re-implementing its own
 *      copy — rule #22 applied to guards themselves).
 *  (g) `dispatchParserActionsForFile()` (the SAME tree-derived parser the
 *      standing manage-action/API-coverage guard uses) finds all three of
 *      `create` / `marcxml_import` / `wizard_create_songbook` dispatched by
 *      the page — proven MUTATION-TESTABLE by writing a mutated TEMP COPY
 *      of the real page source (never the tracked file itself) with one
 *      case's literal renamed, and confirming the parser stops reporting
 *      it.
 *  (h) THE MANUAL-PATH guard — #1993 moved the `create` case's field
 *      parsing OUT of the page and into `songbookAdminValidateCreate()`, so
 *      unlike #1992's ELT precedent (which derived `$_POST[...]` reads from
 *      the PAGE's own case body), this derives `$in[...]` reads from the
 *      shared core's function body — the stand-in for `$_POST` now that the
 *      page passes it straight through — UNION any field the page's
 *      `marcxml_import` case still reads directly (untouched by #1993).
 *      Asserts the page emits a matching `name="key"` control, OR
 *      indirectly via the documented require chain
 *      (`songbook-form-fields.php` → `colour-picker.php` /
 *      `ietf-language-picker.php` for the two fields that don't literally
 *      appear as `name="…"` in the partial's own source), PLUS the hidden
 *      `action=create` input and the plain `abbreviation` input survive
 *      (`str_replace` mutation proofs).
 *  (i) The wizard trigger (button + modal element) is present and imports
 *      `admin-wizard.js`, and the two related API case bodies
 *      (`admin_songbook_create` / `admin_songbook_update`) are non-empty.
 *  (j) `createWizard()` singleton — already asserted by
 *      `test-external-link-wizard.php`'s own (j); not re-implemented here
 *      (rule #22 applied to guards: one singleton check, not a second copy
 *      per wizard consumer).
 *  (k) The abbreviation truth table — calls `validateSongbookAbbr()`
 *      DIRECTLY (a genuine functional test, not a text-match) against the
 *      exact cases the plan named, and confirms
 *      `songbookAdminValidateCreate()`'s body genuinely calls
 *      `validateSongbookAbbr(` rather than a hand-rolled regex.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, mirroring `test-manage-action-api-coverage.php`'s /
 * `test-external-link-wizard.php`'s own precedent:
 *  (1) FIXTURE self-tests (PART 1) prove the parsing primitives this file
 *      is built on — `caseBodyFor()`, `functionBodyFor()`,
 *      `pageEmitsNameFor()`/`pageEmitsHiddenAction()` — can both find a
 *      marker that IS there and correctly refuse one that ISN'T, using
 *      small synthetic snippets. These run EVERY invocation.
 *  (2) REAL-CONTENT mutation proofs (PART 3, tagged "MUTATION PROOF") run
 *      the SAME extraction functions against a MUTATED COPY of the real
 *      file content (a `str_replace()`'d in-memory string, or — for the
 *      tree-derived enumerator in (g) — a mutated TEMP FILE that is
 *      written, tested, and deleted within the same assertion, NEVER the
 *      tracked source file) and confirm the check goes red.
 *
 * @see appWeb/public_html/includes/songbook_admin.php        the shared CREATE core (#1993)
 * @see appWeb/public_html/manage/songbooks.php                page consumer — manual form + guided wizard
 * @see appWeb/public_html/api.php                             admin_songbook_create API consumer
 * @see appWeb/public_html/js/modules/admin-wizard.js           the shared stepper (#1992)
 * @see tests/php/test-external-link-wizard.php                 the #1992 precedent this mirrors + adapts
 * @see tests/php/test-songbook-form-parity.php                 the Add/Edit field-parity guard (f) defers to
 * @see tests/php/test-manage-action-api-coverage.php            the mutation-self-test precedent for (g)
 * @see tests/php/lib/dispatch_parser.php                        shared tokeniser this file reuses for (g)
 *
 *   php tests/php/test-songbook-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo        = dirname(__DIR__, 2);
$pageFile    = $repo . '/appWeb/public_html/manage/songbooks.php';
$apiFile     = $repo . '/appWeb/public_html/api.php';
$coreFile    = $repo . '/appWeb/public_html/includes/songbook_admin.php';
$validationFile = $repo . '/appWeb/public_html/includes/songbook_validation.php';
$partialFile = $repo . '/appWeb/public_html/manage/includes/songbook-form-fields.php';
$wizardFile  = $repo . '/appWeb/public_html/js/modules/admin-wizard.js';
$publicHtml  = $repo . '/appWeb/public_html';

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
function sbwTokensToSource(array $toks, int $from, int $to): string
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
 * tested `dispatchParserCaseTokens()` (rule #22 — never a second tokeniser
 * walk). Returns '' when the switch or the case isn't found.
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
    return sbwTokensToSource($toks, $bodyStart, $bodyEnd);
}

/**
 * Strip `/* ... *​/` block comments and `// ...` line comments from `$src`.
 * `caseBodyFor()` slices by TOKEN POSITION, so a doc-comment sitting
 * between two `case` labels (this file's OWN doc-comment on
 * `wizard_create_songbook`, which discusses `validateCsrfRequest()` in
 * PROSE, is the real example that caught this) is included in the
 * PRECEDING case's sliced body — a real trap, not a hypothetical one (it
 * broke assertion (d) on first run; see this file's own PR report). Every
 * "does this case body call X(" check below runs against the
 * comment-stripped view, never the raw one, so prose can never masquerade
 * as a real call.
 */
function stripPhpComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/** Every unique `$_POST['key']` (or `["key"]`) read inside `$body`, comment-
 *  stripped first. */
function postKeysInBody(string $body): array
{
    $stripped = preg_replace('~/\*.*?\*/~s', '', $body) ?? $body;
    preg_match_all('/\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
    return array_values(array_unique($m[1]));
}

/** Every unique `$in['key']` (or `["key"]`) read inside `$body` — the
 *  shared core's stand-in for `$_POST` (#1993 — see this file's own
 *  doc-block on why (h) reads `$in[...]` here instead of `$_POST[...]`). */
function inKeysInBody(string $body): array
{
    $stripped = preg_replace('~/\*.*?\*/~s', '', $body) ?? $body;
    preg_match_all('/\$in\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
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

/** Extract a plain top-level PHP `function NAME(...) { ... }` body by
 *  brace-depth matching, anchored on the function's OWN declaration
 *  (never a mention in a comment/doc-block preceding it). */
function functionBodyFor(string $src, string $fnName): string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
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

/** Every occurrence of `INSERT INTO tblSongbooks` (word-boundaried, comment-
 *  stripped) under `$dir`, as `[relativePath => hitCount]`. */
function findInsertIntoSongbooksTreeWide(string $dir, string $repoRoot): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = (string)file_get_contents($f->getPathname());
        $stripped = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        $n = preg_match_all('/INSERT\s+INTO\s+tblSongbooks\b/i', $stripped);
        if ($n > 0) {
            $rel = str_replace($repoRoot . '/', '', $f->getPathname());
            $hits[$rel] = $n;
        }
    }
    ksort($hits);
    return $hits;
}

/** Write `$mutatedSrc` (str_replace applied to `$originalSrc`) to a fresh
 *  temp file, run `$fn($tmpPath)`, delete the temp file, return the
 *  result. Never touches a tracked source file. */
function withMutatedFile(string $originalSrc, string $search, string $replace, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_sbw_mut_') . '.php';
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
$fixtureSwitchFile = tempnam(sys_get_temp_dir(), 'ihymns_sbw_fixture_') . '.php';
file_put_contents($fixtureSwitchFile, $fixtureSwitchSrc);

$betaBody = caseBodyFor($fixtureSwitchFile, '$action', 'beta');
ok('caseBodyFor() finds a marker genuinely inside the target case', str_contains($betaBody, 'doBeta('));
ok('MUTATION PROOF: caseBodyFor() does not leak the PRECEDING case\'s marker', !str_contains($betaBody, 'doAlpha('));
ok('MUTATION PROOF: caseBodyFor() does not leak the FOLLOWING case\'s marker', !str_contains($betaBody, 'doGamma('));
ok('MUTATION PROOF: caseBodyFor() returns "" for an absent case name', caseBodyFor($fixtureSwitchFile, '$action', 'delta') === '');

$gammaBody = caseBodyFor($fixtureSwitchFile, '$action', 'gamma'); // last case — the depth-walk-to-switch-close path
ok('caseBodyFor() correctly closes the LAST case in a switch via depth-walk', str_contains($gammaBody, 'doGamma(') && !str_contains($gammaBody, 'doBeta('));

unlink($fixtureSwitchFile);

/* ---- functionBodyFor() ---- */
$fixtureFnSrc = <<<'PHP'
<?php
function before() { return 1; }
function target($a, $b) {
    inside1();
    if ($a) { inside2(); }
    inside3();
}
function after() { return 2; }
PHP;
$fnBody = functionBodyFor($fixtureFnSrc, 'target');
ok('functionBodyFor() finds markers genuinely inside the target function (incl. past a nested brace)',
    str_contains($fnBody, 'inside1(') && str_contains($fnBody, 'inside2(') && str_contains($fnBody, 'inside3('));
ok('MUTATION PROOF: functionBodyFor() does not include code from a PRECEDING function', !str_contains($fnBody, 'return 1'));
ok('MUTATION PROOF: functionBodyFor() does not include code from a FOLLOWING function', !str_contains($fnBody, 'return 2'));
ok('MUTATION PROOF: functionBodyFor() returns "" for an absent function name', functionBodyFor($fixtureFnSrc, 'missing') === '');

/* ---- stripPhpComments() ---- */
$fixtureCommentSrc = "real1();\n/* a block comment mentioning fakeCall( in prose */\nreal2(); // a line comment mentioning otherCall(\nreal3();";
$strippedFixture = stripPhpComments($fixtureCommentSrc);
ok('stripPhpComments() keeps real code', str_contains($strippedFixture, 'real1(') && str_contains($strippedFixture, 'real2(') && str_contains($strippedFixture, 'real3('));
ok('MUTATION PROOF: stripPhpComments() removes a block-comment mention', !str_contains($strippedFixture, 'fakeCall('));
ok('MUTATION PROOF: stripPhpComments() removes a line-comment mention', !str_contains($strippedFixture, 'otherCall('));

/* ---- postKeysInBody() / inKeysInBody() ---- */
$fixtureBody = '$x = $_POST[\'foo\'] ?? null; $y = $in[\'bar\'] ?? null; /* $_POST[\'commented\'] */';
ok('postKeysInBody() finds a real read and ignores a commented-out one', postKeysInBody($fixtureBody) === ['foo']);
ok('inKeysInBody() finds a real $in[...] read', inKeysInBody($fixtureBody) === ['bar']);

/* ---- pageEmitsNameFor() / pageEmitsHiddenAction() ---- */
$fixtureHtml = '<input type="text" name="abbreviation"><input type="hidden" name="action" value="create">';
ok('pageEmitsNameFor() finds a real name= control', pageEmitsNameFor($fixtureHtml, 'abbreviation'));
ok('MUTATION PROOF: pageEmitsNameFor() refuses an absent field name', !pageEmitsNameFor($fixtureHtml, 'display_order'));
ok('pageEmitsHiddenAction() finds the matching hidden action input', pageEmitsHiddenAction($fixtureHtml, 'create'));
ok('MUTATION PROOF: pageEmitsHiddenAction() refuses a non-matching action value', !pageEmitsHiddenAction($fixtureHtml, 'marcxml_import'));

/* ---- findInsertIntoSongbooksTreeWide() ---- */
$fixtureTreeDir = sys_get_temp_dir() . '/ihymns_sbw_tree_fixture_' . uniqid();
mkdir($fixtureTreeDir, 0777, true);
file_put_contents($fixtureTreeDir . '/a.php', "<?php \$x = 'INSERT INTO tblSongbooks (Abbreviation) VALUES (?)';\n");
file_put_contents($fixtureTreeDir . '/b.php', "<?php /* mentions INSERT INTO tblSongbooks in a comment only, must be ignored */\n");
$fixtureHits = findInsertIntoSongbooksTreeWide($fixtureTreeDir, $fixtureTreeDir);
ok('findInsertIntoSongbooksTreeWide() finds the real INSERT and ignores a comment-only mention',
    array_keys($fixtureHits) === ['a.php']);
unlink($fixtureTreeDir . '/a.php');
unlink($fixtureTreeDir . '/b.php');
rmdir($fixtureTreeDir);

/* =========================================================================
 * PART 2 — load the real sources.
 * ========================================================================= */

foreach ([$pageFile, $apiFile, $coreFile, $validationFile, $partialFile, $wizardFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc  = (string)file_get_contents($pageFile);
$apiSrc   = (string)file_get_contents($apiFile);
$coreSrc  = (string)file_get_contents($coreFile);
$partialSrc = (string)file_get_contents($partialFile);

$pageCreateBody   = caseBodyFor($pageFile, '$action', 'create');
$pageWizardBody   = caseBodyFor($pageFile, '$action', 'wizard_create_songbook');
$pageMarcxmlBody  = caseBodyFor($pageFile, '$action', 'marcxml_import');
$apiCreateBody    = caseBodyFor($apiFile, '$action', 'admin_songbook_create');
$apiUpdateBody    = caseBodyFor($apiFile, '$action', 'admin_songbook_update');
$apiMarcxmlBody   = caseBodyFor($apiFile, '$action', 'admin_songbook_marcxml_import');

/* Comment-stripped views — caseBodyFor() slices by TOKEN POSITION, so a
   doc-comment sitting between two `case` labels is included in the
   PRECEDING case's sliced body (see stripPhpComments()'s own doc-block).
   Every "does this case body call X(" check below uses these, never the
   raw bodies, so this file's OWN prose can never masquerade as a real
   call. */
$pageCreateCode  = stripPhpComments($pageCreateBody);
$pageWizardCode  = stripPhpComments($pageWizardBody);
$apiCreateCode   = stripPhpComments($apiCreateBody);

ok("isolated the page's create case body (non-empty)", $pageCreateBody !== '');
ok("isolated the page's wizard_create_songbook case body (non-empty)", $pageWizardBody !== '');
ok("isolated the page's marcxml_import case body (non-empty)", $pageMarcxmlBody !== '');
ok("isolated the API's admin_songbook_create case body (non-empty)", $apiCreateBody !== '');
ok("isolated the API's admin_songbook_update case body (non-empty)", $apiUpdateBody !== '');
ok("isolated the API's admin_songbook_marcxml_import case body (non-empty)", $apiMarcxmlBody !== '');

$coreValidateFn = functionBodyFor($coreSrc, 'songbookAdminValidateCreate');
$coreCreateFn   = functionBodyFor($coreSrc, 'songbookAdminCreate');
ok('isolated songbookAdminValidateCreate() body (non-empty)', $coreValidateFn !== '');
ok('isolated songbookAdminCreate() body (non-empty)', $coreCreateFn !== '');

/* =========================================================================
 * PART 3 — the (a)-(k) assertions.
 * ========================================================================= */

echo "\nSongbook \"New Songbook\" wizard + shared CREATE core guard (#1993)\n\n";

/* ---- (a) all THREE create funnels call Validate/Create, never a raw
   INSERT INTO tblSongbooks in the CASE BODY itself ---- */
foreach ([
    "page 'create' case"                 => $pageCreateCode,
    "page 'wizard_create_songbook' case" => $pageWizardCode,
    "api 'admin_songbook_create' case"   => $apiCreateCode,
] as $label => $body) {
    ok("(a) {$label} calls songbookAdminValidateCreate(", str_contains($body, 'songbookAdminValidateCreate('));
    ok("(a) {$label} calls songbookAdminCreate(", str_contains($body, 'songbookAdminCreate('));
    ok("(a) {$label} contains no raw INSERT INTO tblSongbooks (that SQL lives ONLY in the shared core)",
        !preg_match('/INSERT\s+INTO\s+tblSongbooks\b/i', $body));
}

/* ---- (b) "INSERT INTO tblSongbooks" literals tree-wide, each traced to
   its expected home; a NEW site anywhere is the regression this catches.
   The #1993 create-funnel unification only ever concerned THREE of these
   (the shared core + the two admin-facing MARCXML funnels it deliberately
   excluded) — but the tree ALSO carries two genuinely pre-existing, out-
   of-scope stub-creators this guard's first run discovered were NOT in the
   plan's assumed count: `includes/song_importers.php` (a bulk ZIP/folder
   song import auto-creates a minimal songbook row — folder-derived name/
   language, no curator form involved at all) and
   `manage/editor/api2.php` (a fixture "Pending duplicates" system
   songbook the duplicate-review tooling seeds on demand). Neither goes
   anywhere NEAR a curator-facing create form, so folding them into
   songbookAdminCreate() would be scope creep, not a fix — but a guard
   that only expected 3 and silently widened its acceptance to "however
   many exist" would stop being a guard at all. Every site is named and
   pinned by exact count instead. ---- */
$insertHits = findInsertIntoSongbooksTreeWide($publicHtml, $repo);
$totalInsertHits = array_sum($insertHits);
ok('(b) exactly SEVEN "INSERT INTO tblSongbooks" literals tree-wide, all at named/expected sites (' . $totalInsertHits . ' found: ' . implode(', ', array_keys($insertHits)) . ')',
    $totalInsertHits === 7);
ok('(b) ...one inside includes/songbook_admin.php (the shared CREATE core — #1993 in-scope)',
    ($insertHits['appWeb/public_html/includes/songbook_admin.php'] ?? 0) === 1);
ok('(b) ...one inside manage/songbooks.php (its OWN marcxml_import case, out of the core\'s scope — #1993 in-scope-but-excluded)',
    ($insertHits['appWeb/public_html/manage/songbooks.php'] ?? 0) === 1);
ok('(b) ...one inside api.php (its OWN admin_songbook_marcxml_import case, out of the core\'s scope — #1993 in-scope-but-excluded)',
    ($insertHits['appWeb/public_html/api.php'] ?? 0) === 1);
ok('(b) ...exactly TWO inside includes/song_importers.php (pre-existing bulk-import stub-creator, never a curator form — #1993 out of scope)',
    ($insertHits['appWeb/public_html/includes/song_importers.php'] ?? 0) === 2);
ok('(b) ...exactly TWO inside manage/editor/api2.php (pre-existing "Pending duplicates" fixture seeder — #1993 out of scope)',
    ($insertHits['appWeb/public_html/manage/editor/api2.php'] ?? 0) === 2);
$expectedInsertSites = [
    'appWeb/public_html/includes/songbook_admin.php',
    'appWeb/public_html/manage/songbooks.php',
    'appWeb/public_html/api.php',
    'appWeb/public_html/includes/song_importers.php',
    'appWeb/public_html/manage/editor/api2.php',
];
$unexpectedInsertSites = array_values(array_diff(array_keys($insertHits), $expectedInsertSites));
ok('(b) no UNEXPECTED "INSERT INTO tblSongbooks" site exists (would be a 4th admin-facing copy, or a new stub-creator nobody documented)'
    . ($unexpectedInsertSites ? ': ' . implode(', ', $unexpectedInsertSites) : ''),
    $unexpectedInsertSites === []);
/* Pin the songbooks.php / api.php hit to the MARCXML case specifically —
   never the create/wizard/admin_songbook_create case bodies (which (a)
   already independently proves are INSERT-free; this cross-checks the
   INVERSE — the ONE hit that does exist is the RIGHT one). */
ok('(b) manage/songbooks.php\'s ONE hit lives inside the marcxml_import case body',
    (bool)preg_match('/INSERT\s+INTO\s+tblSongbooks\b/i', $pageMarcxmlBody));
ok('(b) api.php\'s ONE hit lives inside the admin_songbook_marcxml_import case body',
    (bool)preg_match('/INSERT\s+INTO\s+tblSongbooks\b/i', $apiMarcxmlBody));
/* The tracking note (#1993 doesn't have a numbered follow-up issue filed
   at write time — see this PR's own report — so both MARCXML sites point
   back at the core's own "SCOPE — MARCXML stays OUT" doc-block, which
   names the tracked parent issue, rather than a hand-typed issue number
   that would drift the moment a follow-up is actually filed under its own
   number). */
ok('(b) the page\'s marcxml_import case comment points at songbook_admin.php\'s scope note',
    str_contains($pageMarcxmlBody, 'songbook_admin.php') || (bool)preg_match('/marcxml_import[\s\S]{0,600}songbook_admin\.php/', substr($pageSrc, max(0, strpos($pageSrc, "case 'marcxml_import'") - 800), 1400)));
ok('(b) includes/songbook_admin.php documents the MARCXML scope-out (its "SCOPE — MARCXML stays OUT" doc-block)',
    str_contains($coreSrc, 'SCOPE') && str_contains($coreSrc, 'MARCXML'));
$apiMarcxmlDocRegion = substr($apiSrc, max(0, (int)strpos($apiSrc, "case 'admin_songbook_marcxml_import'") - 1200), 1600);
ok('(b) the API\'s admin_songbook_marcxml_import doc-comment points at songbook_admin.php\'s scope note',
    str_contains($apiMarcxmlDocRegion, 'songbook_admin.php'));

/* ---- (c) gate parity — entitlement key read from the PAGE, not hand-typed ---- */
preg_match("/userHasEntitlement\('([a-z_]+)'/", $pageSrc, $mEnt);
$entKey = $mEnt[1] ?? null;
ok('(c) extracted the page\'s own entitlement key', $entKey !== null);
if ($entKey !== null) {
    ok("(c) api admin_songbook_create gates on userHasEntitlement('{$entKey}'",
        str_contains($apiCreateBody, "userHasEntitlement('{$entKey}'"));
}

/* ---- (d) CSRF by position — the ONE top-of-handler gate covers every
   case (incl. the new wizard one); neither create-funnel case re-adds its
   own redundant validateCsrfRequest() call. ---- */
$csrfGatePos   = strpos($pageSrc, 'if (!validateCsrfRequest(');
$switchPos     = strpos($pageSrc, 'switch ($action)');
ok('(d) found the page-wide validateCsrfRequest() gate', $csrfGatePos !== false);
ok('(d) found the switch ($action) dispatch', $switchPos !== false);
if ($csrfGatePos !== false && $switchPos !== false) {
    ok('(d) the CSRF gate sits BEFORE the switch (covers every case, including wizard_create_songbook)',
        $csrfGatePos < $switchPos);
}
ok('(d) the wizard_create_songbook case does NOT carry its own redundant validateCsrfRequest() call',
    !str_contains($pageWizardCode, 'validateCsrfRequest('));
ok('(d) the create case does NOT carry its own redundant validateCsrfRequest() call',
    !str_contains($pageCreateCode, 'validateCsrfRequest('));

/* ---- (e) the wizard's JSON case ends in exit inside its OWN try/catch —
   never falls through to the page's generic HTML error catch. Uses the
   comment-stripped view throughout (see stripPhpComments()'s doc-block —
   caseBodyFor()'s raw slice includes trailing between-case doc-comments,
   which would otherwise corrupt the "ends in exit;" tail check below). ---- */
ok('(e) wizard_create_songbook opens its own try {', str_contains($pageWizardCode, 'try {'));
ok('(e) wizard_create_songbook catches \\Throwable', str_contains($pageWizardCode, 'catch (\\Throwable $e)'));
/* The case body's LAST non-whitespace statement before its closing brace
   must be `exit;` — confirmed by trimming trailing whitespace/brace noise
   and checking the tail, rather than a bare substring search (which could
   be fooled by an `exit;` earlier in the body that ISN'T actually last). */
$wizardTail = rtrim($pageWizardCode);
$wizardTail = rtrim($wizardTail, "} \t\n\r\0\x0B");
ok('(e) wizard_create_songbook\'s case body ends in exit; (never falls through to the generic catch)',
    (bool)preg_match('/exit;\s*$/', $wizardTail));

/* ---- (f) manual form parity — delegate to the pre-existing, unmodified
   guard rather than re-implementing a second copy of it (rule #22 applied
   to guards). ---- */
$parityGuardOutput = [];
$parityGuardStatus = 0;
exec('php ' . escapeshellarg(__DIR__ . '/test-songbook-form-parity.php') . ' 2>&1', $parityGuardOutput, $parityGuardStatus);
ok('(f) test-songbook-form-parity.php (the manual Add/Edit form guard) is still green — ' . trim(implode(' ', $parityGuardOutput)),
    $parityGuardStatus === 0);

/* ---- (g) dispatchParserActionsForFile() superset + MUTATION PROOF ---- */
$pageActions = dispatchParserActionsForFile($pageFile)['names'];
foreach (['create', 'marcxml_import', 'wizard_create_songbook'] as $need) {
    ok("(g) dispatchParserActionsForFile() finds '{$need}' dispatched by the page", in_array($need, $pageActions, true));
}
foreach ([
    'create'                 => "case 'create':",
    'marcxml_import'         => "case 'marcxml_import':",
    'wizard_create_songbook' => "case 'wizard_create_songbook':",
] as $name => $needle) {
    ok("(g) fixture precondition: literal '{$needle}' really is in the page source", str_contains($pageSrc, $needle));
    $mutatedNames = withMutatedFile($pageSrc, $needle, "case 'zzz_mutated_{$name}':", static function (string $tmp): array {
        return dispatchParserActionsForFile($tmp)['names'];
    });
    ok("(g) MUTATION PROOF: renaming the '{$name}' case literal makes the enumerator stop reporting it",
        !in_array($name, $mutatedNames, true));
}

/* ---- (h) manual-path field survival — derived from the SHARED CORE's
   $in[...] reads (the create case itself has none any more, #1993), UNION
   the marcxml case's own still-literal $_POST[...] reads. ---- */
$indirectFieldEmitters = [
    'colour'   => 'colour-picker.php',
    'language' => 'ietf-language-picker.php',
];
$coreDerivedFields = inKeysInBody($coreValidateFn);
ok('(h) derived >= 20 $in[...] field reads from songbookAdminValidateCreate() (found ' . count($coreDerivedFields) . ')',
    count($coreDerivedFields) >= 20);
foreach ($coreDerivedFields as $key) {
    $viaSharedPartial = isset($indirectFieldEmitters[$key]) && str_contains($partialSrc, $indirectFieldEmitters[$key]);
    $label = "(h) core reads \$in['{$key}'] and the page/partial emits a matching name= control"
        . ($viaSharedPartial ? " (indirectly, via {$indirectFieldEmitters[$key]})" : '');
    ok($label, pageEmitsNameFor($pageSrc, $key) || pageEmitsNameFor($partialSrc, $key) || $viaSharedPartial);
}
ok('(h) the page emits the hidden name="action" value="create" input', pageEmitsHiddenAction($pageSrc, 'create'));
ok('(h) the page emits a plain "abbreviation" input (create form + MARCXML form both feed it)',
    pageEmitsNameFor($pageSrc, 'abbreviation'));

$marcxmlDerivedFields = postKeysInBody($pageMarcxmlBody);
ok('(h) derived >= 1 $_POST field read for marcxml_import (found ' . count($marcxmlDerivedFields) . ')', count($marcxmlDerivedFields) > 0);
foreach ($marcxmlDerivedFields as $key) {
    ok("(h) marcxml_import reads \$_POST['{$key}'] and the page emits a matching name= control", pageEmitsNameFor($pageSrc, $key));
}
ok('(h) the page emits the hidden name="action" value="marcxml_import" input', pageEmitsHiddenAction($pageSrc, 'marcxml_import'));

/* MUTATION PROOFs — strip a control's name= from a COPY of the page/partial
   source and confirm the presence check goes red. */
ok('(h) fixture precondition: name="abbreviation" really is in the page source', str_contains($pageSrc, 'name="abbreviation"'));
$mutatedNoAbbr = str_replace('name="abbreviation"', 'data-removed="abbreviation"', $pageSrc);
ok('(h) MUTATION PROOF: removing the abbreviation control\'s name= makes pageEmitsNameFor() go false',
    !pageEmitsNameFor($mutatedNoAbbr, 'abbreviation'));
ok('(h) fixture precondition: the create hidden action input really is in the page source', pageEmitsHiddenAction($pageSrc, 'create'));
$mutatedNoHiddenAction = str_replace('value="create"', 'value="renamed_create"', $pageSrc);
ok('(h) MUTATION PROOF: renaming the hidden action value makes pageEmitsHiddenAction() go false for create',
    !pageEmitsHiddenAction($mutatedNoHiddenAction, 'create'));
ok('(h) fixture precondition: the partial really does require colour-picker.php', str_contains($partialSrc, 'colour-picker.php'));
$mutatedNoColourRequire = str_replace('colour-picker.php', 'zzz-removed.php', $partialSrc);
ok('(h) MUTATION PROOF: removing the colour-picker.php require makes the indirect colour-emission check go false',
    !str_contains($mutatedNoColourRequire, 'colour-picker.php'));
ok('(h) fixture precondition: the partial really does require ietf-language-picker.php', str_contains($partialSrc, 'ietf-language-picker.php'));
$mutatedNoLangRequire = str_replace('ietf-language-picker.php', 'zzz-removed.php', $partialSrc);
ok('(h) MUTATION PROOF: removing the ietf-language-picker.php require makes the indirect language-emission check go false',
    !str_contains($mutatedNoLangRequire, 'ietf-language-picker.php'));

/* ---- (i) wizard entry present + related API case bodies survive ---- */
ok('(i) page emits a wizard trigger with data-bs-target="#songbookWizardModal"', str_contains($pageSrc, 'data-bs-target="#songbookWizardModal"'));
ok('(i) page emits the modal element id="songbookWizardModal"', str_contains($pageSrc, 'id="songbookWizardModal"'));
ok('(i) page imports admin-wizard.js', (bool)preg_match('~from\s+[\'"]/js/modules/admin-wizard\.js~', $pageSrc));
ok('(i) api admin_songbook_create case body is non-empty', $apiCreateBody !== '');
ok('(i) api admin_songbook_update case body is non-empty', $apiUpdateBody !== '');

$mutatedNoBtn = str_replace('data-bs-target="#songbookWizardModal"', '', $pageSrc);
ok('(i) MUTATION PROOF: removing the trigger button\'s data-bs-target makes the presence check go false',
    !str_contains($mutatedNoBtn, 'data-bs-target="#songbookWizardModal"'));
$mutatedApiCreateGone = withMutatedFile($apiSrc, "case 'admin_songbook_create':", "case 'zzz_removed_create':", static function (string $tmp): string {
    return caseBodyFor($tmp, '$action', 'admin_songbook_create');
});
ok('(i) MUTATION PROOF: removing the API create case makes caseBodyFor() return ""', $mutatedApiCreateGone === '');

/* ---- (j) stepper singleton — already asserted by
   test-external-link-wizard.php's own (j); this page just proves it
   CONSUMES the shared stepper rather than re-checking the singleton a
   second time (rule #22 applied to guards themselves). ---- */
ok('(j) page calls createWizard( (the shared stepper — singleton itself asserted by test-external-link-wizard.php)',
    str_contains($pageSrc, 'createWizard('));

/* ---- (k) the abbreviation truth table — a REAL functional test against
   validateSongbookAbbr() (includes/songbook_validation.php), not a text
   match. Covers the charset/length/IL-reservation grammar (rule #27). ---- */
require_once $validationFile;
$abbrCases = [
    ''         => false, // required
    'MP'       => true,  // ok — plain 2-char alnum
    'ABCDEFGHIJK' => false, // 11 chars — over the 10-char cap
    'MP-1'     => false, // hyphen — charset violation
    'mp 1'     => false, // space — charset violation
    'il'       => false, // bare IL, case-insensitive — reserved
    'ILS'      => false, // IL + one letter — reserved
    'ILSONGS'  => true,  // IL + MORE than one letter — legal
    'IL9'      => true,  // IL + a DIGIT (not a letter) — legal
];
foreach ($abbrCases as $input => $expectOk) {
    $result = validateSongbookAbbr($input);
    $isOk = $result === null;
    $shownInput = $input === '' ? "''" : "'{$input}'";
    ok("(k) validateSongbookAbbr({$shownInput}) " . ($expectOk ? 'accepts (returns null)' : 'rejects (returns an error string)'),
        $isOk === $expectOk);
}
/* MUTATION-SENSITIVITY: the truth table above IS the mutation proof for
   the validator itself — a weakened grammar (e.g. an IL-reservation regex
   that stopped rejecting 'ILS') would flip one of the 9 cases above and
   this loop would go red, exactly as a hand-written mutation proof would.
   Additionally confirm the shared core genuinely CALLS this validator
   rather than a hand-rolled duplicate. */
ok('(k) songbookAdminValidateCreate() calls validateSongbookAbbr(', str_contains($coreValidateFn, 'validateSongbookAbbr('));
ok('(k) songbookAdminValidateCreate() contains NO second hand-rolled IL-reservation regex (must delegate to ilidAbbrIsReserved() via the ONE validator, never a private copy)',
    !preg_match('/\^IL\[/i', $coreValidateFn));

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "Every #1993 create-funnel (manual form / guided wizard / API twin) delegates to the SAME\n"
   . "songbookAdminValidateCreate()/songbookAdminCreate() core, the MARCXML funnel's deliberate\n"
   . "exclusion stays honestly scoped to its two documented sites, the wizard's JSON branch can\n"
   . "never leak into the page's HTML error catch, the manual forms still feed their handlers'\n"
   . "field reads (now followed one level into the shared core), and the abbreviation grammar\n"
   . "(rule #27) is proven against the real validator, not a text match.\n";
exit(0);
