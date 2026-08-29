<?php

declare(strict_types=1);

/**
 * iHymns — Organisation "New Organisation + licence" wizard + shared CREATE
 * core guard (#1996)
 * =====================================================================
 *
 * ELI5
 * ----
 * #1996 gave curators a guided, step-by-step wizard on `/manage/organisations`
 * — built on the SAME shared stepper (`js/modules/admin-wizard.js`, #1992)
 * the External-Link Types / Songbooks / Venues wizards use — while ALSO, for
 * the first time, giving all THREE ways to create an organisation (the
 * manual "Add an organisation" form, the new wizard, and the new
 * `admin_organisation_create` API twin) ONE shared validate+write core
 * (`includes/organisation_admin.php`, rule #22) instead of an inline copy
 * with no API twin at all. This file is the standing guard that keeps all
 * three doors wired to that SAME core, keeps the #1986 "finer gate"
 * (`manage_org_licences`) enforced SERVER-SIDE in both JSON-speaking funnels
 * (never just a DOM omission), keeps the wizard's org-id IDOR-proof by
 * construction, and keeps the manual form's field set honestly wired.
 *
 * WHAT THIS FILE ASSERTS (spec items (a)-(h), modelled on
 * test-songbook-wizard.php's #1993 precedent — primitives copy-adapted
 * where the shape matches, REPLACED where #1996 genuinely differs: this
 * page's whole POST handler is NOT already validateCsrfRequest()-gated
 * (unlike songbooks.php), so the wizard branch carries its OWN CSRF check —
 * the external-link-types #1992 shape, not the songbooks #1993 shape — and
 * this wizard also composes THREE writes server-side in one request (org +
 * licence rows + member rows), which is what items (c2)/(g) exist for.)
 * --------------------------------------------------------------------
 *  (a) All THREE create funnels — the page's `create` case, the page's new
 *      `wizard_create_organisation` branch, and the API's
 *      `admin_organisation_create` case — call `orgAdminValidateCreate(`/
 *      `orgAdminCreate(`, and NONE of the three bodies contains a raw
 *      `INSERT INTO tblOrganisations` of its own (that SQL now lives ONLY
 *      inside the shared core).
 *  (b) INSERT census, each site traced to its expected home:
 *      `tblOrganisations` — exactly 2 (`includes/organisation_admin.php`'s
 *      `orgAdminCreate()`; `api.php`'s pre-existing, deliberately-untouched
 *      CONSUMER `organisation_create` case — a different product, see that
 *      case's own doc-comment). `tblOrganisationLicences` — exactly 2, BOTH
 *      inside `includes/org_licence_admin.php` (never forked — rule #22's
 *      "licence core stays the only home" applied to this feature).
 *      `tblOrganisationMembers` — exactly 4, pinned by name:
 *      `includes/organisation_admin.php` (the NEW `orgAdminMemberAdd()`
 *      core), `api.php`'s `organisation_create` case (the consumer's own
 *      owner-insert) and its `org_admin_member_add` action (identifier-
 *      resolving org-self-service), and `manage/my-organisations.php`
 *      (member self-service) — the latter three are pre-existing, OUT of
 *      #1996's scope by design (see includes/organisation_admin.php's own
 *      doc-block "WHAT MOVED VERBATIM" / the page's `add_member` case
 *      comment).
 *  (c) Gate parity (rule #1587): `admin_organisation_create` gates on the
 *      SAME `userHasEntitlement()` key the page itself gates the WHOLE page
 *      on — read from the page's own source, never hand-typed here.
 *  (c2) THE FINER GATE (#1986) — `manage_org_licences`. BOTH JSON-speaking
 *      funnels (the page's wizard branch AND the API twin) resolve
 *      `userHasEntitlement('manage_org_licences', ...)` and reference
 *      `$canEditOrgLicences` in an `if ($canEditOrgLicences) { … }` block
 *      that WRAPS the `orgAdminApplyLicenceRows(` call — never a call site
 *      reachable without that check. Proven mutation-testable by stripping
 *      the specific `if ($canEditOrgLicences) {` guard from a MUTATED COPY
 *      of each real body and confirming the regex goes red.
 *  (d) CSRF by position: the wizard branch's OWN `validateCsrfRequest()`
 *      call sits BEFORE the classic forms' `validateCsrf()` gate later in
 *      the same file (the #1992 external-link-types shape — this page's
 *      whole POST handler is NOT already validateCsrfRequest()-gated,
 *      unlike songbooks.php's #1993 shape, so the wizard needs its own
 *      same-origin check here rather than inheriting one).
 *  (e) The wizard branch's JSON body ends in `exit` inside its OWN
 *      try/catch — it can never fall through to the page's generic HTML
 *      error catch further down the file.
 *  (f) MANUAL-PATH — `dispatchParserActionsForFile()` (the SAME tree-derived
 *      parser the standing manage-action/API-coverage guard uses) finds
 *      `create`/`update`/`licence_change`/`add_member`/`update_member_role`/
 *      `remove_member`/`delete`/`wizard_create_organisation` all dispatched
 *      by the page (MUTATION PROOF: renaming any one's case literal in a
 *      temp copy makes the enumerator stop reporting it) — plus
 *      `orgAdminValidateCreate()`'s own `$in[...]` reads each have a
 *      matching `name="key"` control on the manual "Add an organisation"
 *      form (directly, or — for `slug` — INDIRECTLY via the documented
 *      `slug-field.php` partial require chain, the `ihymns_slug_advanced_
 *      field()` shape #1870 established), and the hidden
 *      `action=create` input survives.
 *  (g) IDOR — the wizard branch's body contains NO `$_POST['org_id']` (nor
 *      `$_POST["org_id"]`) read anywhere: the freshly-minted id is the ONLY
 *      organisation id this branch ever touches, so a crafted org_id can
 *      never reach it. MUTATION PROOF: injecting one into a copy of the
 *      body makes the check go red.
 *  (h) Wiring — the page emits the wizard trigger
 *      (`data-bs-target="#orgWizardModal"`) + the modal element
 *      (`id="orgWizardModal"`) + imports `admin-wizard.js` + calls
 *      `createWizard(` + the Members step's user picker reaches the SAME
 *      `user_search` api2 action groups.php's own Add-a-member picker uses
 *      (rule #22 — never a second user-lookup endpoint).
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, mirroring test-songbook-wizard.php's / test-manage-action-
 * api-coverage.php's own precedent:
 *  (1) FIXTURE self-tests (PART 1) prove the parsing primitives this file is
 *      built on — `caseBodyFor()`, `blockBodyAfterAnchor()`,
 *      `functionBodyFor()`, `pageEmitsNameFor()`/`pageEmitsHiddenAction()` —
 *      can both find a marker that IS there and correctly refuse one that
 *      ISN'T, using small synthetic snippets. These run EVERY invocation.
 *  (2) REAL-CONTENT mutation proofs (PART 3, tagged "MUTATION PROOF") run the
 *      SAME extraction functions against a MUTATED COPY of the real file
 *      content (a `str_replace()`'d in-memory string, or — for the
 *      tree-derived enumerator in (f) — a mutated TEMP FILE that is written,
 *      tested, and deleted within the same assertion, NEVER the tracked
 *      source file) and confirm the check goes red.
 *
 * @see appWeb/public_html/includes/organisation_admin.php  the shared CREATE core (#1996)
 * @see appWeb/public_html/manage/organisations.php          page consumer — manual form + guided wizard
 * @see appWeb/public_html/api.php                           admin_organisation_create API consumer
 * @see appWeb/public_html/js/modules/admin-wizard.js         the shared stepper (#1992)
 * @see tests/php/test-songbook-wizard.php                    the #1993 precedent this mirrors + adapts
 * @see tests/php/test-manage-action-api-coverage.php         mapping fix + new wizard_create_organisation entry
 * @see tests/php/test-api-gate-parity.php                    +admin_organisation_create gate-parity entry
 *
 *   php tests/php/test-organisation-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo     = dirname(__DIR__, 2);
$pageFile = $repo . '/appWeb/public_html/manage/organisations.php';
$apiFile  = $repo . '/appWeb/public_html/api.php';
$coreFile = $repo . '/appWeb/public_html/includes/organisation_admin.php';
$slugPartialFile = $repo . '/appWeb/public_html/manage/includes/slug-field.php';
$publicHtml = $repo . '/appWeb/public_html';

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
function orgwTokensToSource(array $toks, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i <= $to; $i++) {
        $out .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    }
    return $out;
}

/**
 * Extract the raw source text of ONE case's body within `switch ($switchVar)`
 * in `$file`. Built on `dispatch_parser.php`'s already-mutation-tested
 * `dispatchParserCaseTokens()` (rule #22 — never a second tokeniser walk).
 * Returns '' when the switch or the case isn't found.
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
    return orgwTokensToSource($toks, $bodyStart, $bodyEnd);
}

/** Strip `/* ... *​/` block comments and `// ...` line comments from `$src`. */
function stripPhpComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/** Every unique `$_POST['key']` (or `["key"]`) read inside `$body`, comment-stripped first. */
function postKeysInBody(string $body): array
{
    $stripped = stripPhpComments($body);
    preg_match_all('/\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
    return array_values(array_unique($m[1]));
}

/** Every unique `$in['key']` (or `["key"]`) read inside `$body` — the shared
 *  core's stand-in for `$_POST`. */
function inKeysInBody(string $body): array
{
    $stripped = stripPhpComments($body);
    preg_match_all('/\$in\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
    return array_values(array_unique($m[1]));
}

/** Does `$pageSrc` contain an element carrying `name="$key"` or `name="$key[]"`? */
function pageEmitsNameFor(string $pageSrc, string $key): bool
{
    $needle = preg_quote($key, '/');
    return (bool)preg_match('/name\s*=\s*([\'"])' . $needle . '(\[\])?\1/', $pageSrc);
}

/** Does `$pageSrc` contain an `<input ...>` tag carrying BOTH `name="action"`
 *  and `value="$actionName"` (either attribute order)? */
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
 *  brace-depth matching, anchored on the function's OWN declaration. */
function functionBodyFor(string $src, string $fnName): string
{
    if (!preg_match('/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    return blockBodyAfterAnchorFromOffset($src, $m[0][1]);
}

/**
 * Extract the `{ ... }` block body that starts at the FIRST `{` found at or
 * after `$anchor` — the same brace-depth-matching shape `functionBodyFor()`
 * uses, generalised to any anchor position (an `if (...)`'s opening brace,
 * not just a function declaration's). Used for the wizard branch, which is
 * an `if ($action === 'wizard_create_organisation') { ... }` block, not a
 * `case` inside a `switch`.
 */
function blockBodyAfterAnchorFromOffset(string $src, int $anchorOffset): string
{
    $openBrace = strpos($src, '{', $anchorOffset);
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

/** Convenience wrapper: find `$anchorText` literally in `$src`, then extract
 *  the `{ ... }` block starting at the first `{` after it. Returns '' when
 *  `$anchorText` isn't found. */
function blockBodyAfterAnchor(string $src, string $anchorText): string
{
    $pos = strpos($src, $anchorText);
    if ($pos === false) { return ''; }
    return blockBodyAfterAnchorFromOffset($src, $pos);
}

/** Every occurrence of `INSERT INTO $table` (word-boundaried, comment-
 *  stripped) under `$dir`, as `[relativePath => hitCount]`. */
function findInsertIntoTreeWide(string $dir, string $repoRoot, string $table): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = (string)file_get_contents($f->getPathname());
        $stripped = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        $n = preg_match_all('/INSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $stripped);
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
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_orgw_mut_') . '.php';
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
$fixtureSwitchFile = tempnam(sys_get_temp_dir(), 'ihymns_orgw_fixture_') . '.php';
file_put_contents($fixtureSwitchFile, $fixtureSwitchSrc);

$betaBody = caseBodyFor($fixtureSwitchFile, '$action', 'beta');
ok('caseBodyFor() finds a marker genuinely inside the target case', str_contains($betaBody, 'doBeta('));
ok('MUTATION PROOF: caseBodyFor() does not leak the PRECEDING case\'s marker', !str_contains($betaBody, 'doAlpha('));
ok('MUTATION PROOF: caseBodyFor() does not leak the FOLLOWING case\'s marker', !str_contains($betaBody, 'doGamma('));
ok('MUTATION PROOF: caseBodyFor() returns "" for an absent case name', caseBodyFor($fixtureSwitchFile, '$action', 'delta') === '');
unlink($fixtureSwitchFile);

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

$fixtureIfSrc = <<<'PHP'
<?php
before();
if ($cond === 'x') {
    inside1();
    if ($nested) { inside2(); }
    inside3();
}
after();
PHP;
$ifBody = blockBodyAfterAnchor($fixtureIfSrc, "if (\$cond === 'x')");
ok('blockBodyAfterAnchor() finds markers genuinely inside the target if-block (incl. past a nested brace)',
    str_contains($ifBody, 'inside1(') && str_contains($ifBody, 'inside2(') && str_contains($ifBody, 'inside3('));
ok('MUTATION PROOF: blockBodyAfterAnchor() does not include code BEFORE the anchor', !str_contains($ifBody, 'before('));
ok('MUTATION PROOF: blockBodyAfterAnchor() does not include code AFTER the closing brace', !str_contains($ifBody, 'after('));
ok('MUTATION PROOF: blockBodyAfterAnchor() returns "" for an absent anchor', blockBodyAfterAnchor($fixtureIfSrc, 'not-here') === '');

$fixtureCommentSrc = "real1();\n/* a block comment mentioning fakeCall( in prose */\nreal2(); // a line comment mentioning otherCall(\nreal3();";
$strippedFixture = stripPhpComments($fixtureCommentSrc);
ok('stripPhpComments() keeps real code', str_contains($strippedFixture, 'real1(') && str_contains($strippedFixture, 'real2(') && str_contains($strippedFixture, 'real3('));
ok('MUTATION PROOF: stripPhpComments() removes a block-comment mention', !str_contains($strippedFixture, 'fakeCall('));
ok('MUTATION PROOF: stripPhpComments() removes a line-comment mention', !str_contains($strippedFixture, 'otherCall('));

$fixtureBody = '$x = $_POST[\'foo\'] ?? null; $y = $in[\'bar\'] ?? null; /* $_POST[\'commented\'] */';
ok('postKeysInBody() finds a real read and ignores a commented-out one', postKeysInBody($fixtureBody) === ['foo']);
ok('inKeysInBody() finds a real $in[...] read', inKeysInBody($fixtureBody) === ['bar']);

$fixtureHtml = '<input type="text" name="abbreviation"><input type="hidden" name="action" value="create">';
ok('pageEmitsNameFor() finds a real name= control', pageEmitsNameFor($fixtureHtml, 'abbreviation'));
ok('MUTATION PROOF: pageEmitsNameFor() refuses an absent field name', !pageEmitsNameFor($fixtureHtml, 'display_order'));
ok('pageEmitsHiddenAction() finds the matching hidden action input', pageEmitsHiddenAction($fixtureHtml, 'create'));
ok('MUTATION PROOF: pageEmitsHiddenAction() refuses a non-matching action value', !pageEmitsHiddenAction($fixtureHtml, 'delete'));

$fixtureTreeDir = sys_get_temp_dir() . '/ihymns_orgw_tree_fixture_' . uniqid();
mkdir($fixtureTreeDir, 0777, true);
file_put_contents($fixtureTreeDir . '/a.php', "<?php \$x = 'INSERT INTO tblOrganisations (Name) VALUES (?)';\n");
file_put_contents($fixtureTreeDir . '/b.php', "<?php /* mentions INSERT INTO tblOrganisations in a comment only, must be ignored */\n");
$fixtureHits = findInsertIntoTreeWide($fixtureTreeDir, $fixtureTreeDir, 'tblOrganisations');
ok('findInsertIntoTreeWide() finds the real INSERT and ignores a comment-only mention', array_keys($fixtureHits) === ['a.php']);
unlink($fixtureTreeDir . '/a.php');
unlink($fixtureTreeDir . '/b.php');
rmdir($fixtureTreeDir);

/* ---- the (c2) finer-gate check, self-tested on a small fixture BEFORE
   trusting it against real source. Built on blockBodyAfterAnchor() (already
   proven above to handle NESTED braces correctly — the real code has a
   foreach/if nested between the guard and the call, which a naive
   no-braces-allowed regex cannot span), not a fresh regex. */
function finerGateWrapsCall(string $body, string $callNeedle): bool
{
    $block = blockBodyAfterAnchor($body, 'if ($canEditOrgLicences) {');
    return $block !== '' && str_contains($block, $callNeedle);
}
$gateFixtureGood = 'before(); if ($canEditOrgLicences) { doStuff(); foreach ($rows as $r) { if ($r) { continue; } } orgAdminApplyLicenceRows($db, $id, $rows); }';
$gateFixtureBad  = 'before(); if (true) { doStuff(); foreach ($rows as $r) { if ($r) { continue; } } orgAdminApplyLicenceRows($db, $id, $rows); }';
ok('finerGateWrapsCall() matches a genuine if ($canEditOrgLicences) { … orgAdminApplyLicenceRows( } shape, even past nested braces',
    finerGateWrapsCall($gateFixtureGood, 'orgAdminApplyLicenceRows('));
ok('MUTATION PROOF: finerGateWrapsCall() does NOT match once the guard is stripped to if (true)',
    !finerGateWrapsCall($gateFixtureBad, 'orgAdminApplyLicenceRows('));

/* =========================================================================
 * PART 2 — load the real sources.
 * ========================================================================= */

foreach ([$pageFile, $apiFile, $coreFile, $slugPartialFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc = (string)file_get_contents($pageFile);
$apiSrc  = (string)file_get_contents($apiFile);
$coreSrc = (string)file_get_contents($coreFile);
$slugPartialSrc = (string)file_get_contents($slugPartialFile);

$pageCreateBody = caseBodyFor($pageFile, '$action', 'create');
$pageAddMemberBody = caseBodyFor($pageFile, '$action', 'add_member');
$pageWizardBody = blockBodyAfterAnchor($pageSrc, "if (\$action === 'wizard_create_organisation') {");
$apiCreateBody  = caseBodyFor($apiFile, '$action', 'admin_organisation_create');
$apiMemberAddBody = caseBodyFor($apiFile, '$action', 'admin_organisation_member_add');
$apiConsumerCreateBody = caseBodyFor($apiFile, '$action', 'organisation_create');

$pageCreateCode  = stripPhpComments($pageCreateBody);
$pageWizardCode  = stripPhpComments($pageWizardBody);
$apiCreateCode   = stripPhpComments($apiCreateBody);

ok("isolated the page's create case body (non-empty)", $pageCreateBody !== '');
ok("isolated the page's add_member case body (non-empty)", $pageAddMemberBody !== '');
ok("isolated the page's wizard_create_organisation if-block body (non-empty)", $pageWizardBody !== '');
ok("isolated the API's admin_organisation_create case body (non-empty)", $apiCreateBody !== '');
ok("isolated the API's admin_organisation_member_add case body (non-empty)", $apiMemberAddBody !== '');
ok("isolated the API's organisation_create (consumer) case body (non-empty)", $apiConsumerCreateBody !== '');

$coreValidateFn = functionBodyFor($coreSrc, 'orgAdminValidateCreate');
$coreCreateFn   = functionBodyFor($coreSrc, 'orgAdminCreate');
$coreApplyFn    = functionBodyFor($coreSrc, 'orgAdminApplyLicenceRows');
$coreMemberFn   = functionBodyFor($coreSrc, 'orgAdminMemberAdd');
ok('isolated orgAdminValidateCreate() body (non-empty)', $coreValidateFn !== '');
ok('isolated orgAdminCreate() body (non-empty)', $coreCreateFn !== '');
ok('isolated orgAdminApplyLicenceRows() body (non-empty)', $coreApplyFn !== '');
ok('isolated orgAdminMemberAdd() body (non-empty)', $coreMemberFn !== '');

/* =========================================================================
 * PART 3 — the (a)-(h) assertions.
 * ========================================================================= */

echo "\nOrganisation \"New Organisation + licence\" wizard + shared CREATE core guard (#1996)\n\n";

/* ---- (a) all THREE create funnels call Validate/Create, never a raw
   INSERT INTO tblOrganisations in the body itself ---- */
foreach ([
    "page 'create' case"                        => $pageCreateCode,
    "page 'wizard_create_organisation' if-block" => $pageWizardCode,
    "api 'admin_organisation_create' case"      => $apiCreateCode,
] as $label => $body) {
    ok("(a) {$label} calls orgAdminValidateCreate(", str_contains($body, 'orgAdminValidateCreate('));
    ok("(a) {$label} calls orgAdminCreate(", str_contains($body, 'orgAdminCreate('));
    ok("(a) {$label} contains no raw INSERT INTO tblOrganisations (that SQL lives ONLY in the shared core)",
        !preg_match('/INSERT\s+INTO\s+tblOrganisations\b/i', $body));
}

/* ---- (b) INSERT census, tree-wide, each traced to its expected home. ---- */
$orgHits = findInsertIntoTreeWide($publicHtml, $repo, 'tblOrganisations');
$orgTotal = array_sum($orgHits);
ok('(b) exactly TWO "INSERT INTO tblOrganisations" literals tree-wide (' . $orgTotal . ' found: ' . implode(', ', array_keys($orgHits)) . ')', $orgTotal === 2);
ok('(b) ...one inside includes/organisation_admin.php (the shared CREATE core — #1996)',
    ($orgHits['appWeb/public_html/includes/organisation_admin.php'] ?? 0) === 1);
ok('(b) ...one inside api.php (its OWN pre-existing organisation_create CONSUMER case — a different product, deliberately untouched)',
    ($orgHits['appWeb/public_html/api.php'] ?? 0) === 1);
ok('(b) api.php\'s ONE hit lives inside the organisation_create case body (never admin_organisation_create/wizard)',
    (bool)preg_match('/INSERT\s+INTO\s+tblOrganisations\b/i', $apiConsumerCreateBody));

$licHits = findInsertIntoTreeWide($publicHtml, $repo, 'tblOrganisationLicences');
$licTotal = array_sum($licHits);
ok('(b) exactly TWO "INSERT INTO tblOrganisationLicences" literals tree-wide, BOTH inside includes/org_licence_admin.php (never forked — ' . $licTotal . ' found: ' . implode(', ', array_keys($licHits)) . ')',
    $licTotal === 2 && ($licHits['appWeb/public_html/includes/org_licence_admin.php'] ?? 0) === 2 && count($licHits) === 1);

$memHits = findInsertIntoTreeWide($publicHtml, $repo, 'tblOrganisationMembers');
$memTotal = array_sum($memHits);
ok('(b) exactly FOUR "INSERT INTO tblOrganisationMembers" literals tree-wide, all at named/expected sites (' . $memTotal . ' found: ' . implode(', ', array_keys($memHits)) . ')', $memTotal === 4);
ok('(b) ...one inside includes/organisation_admin.php (the NEW orgAdminMemberAdd() core — #1996, re-points page add_member + api admin_organisation_member_add onto it)',
    ($memHits['appWeb/public_html/includes/organisation_admin.php'] ?? 0) === 1);
ok('(b) ...exactly TWO inside api.php (its OWN organisation_create consumer owner-insert + org_admin_member_add identifier-resolving self-service action — both pre-existing, out of #1996 scope)',
    ($memHits['appWeb/public_html/api.php'] ?? 0) === 2);
ok('(b) ...one inside manage/my-organisations.php (member self-service — pre-existing, out of #1996 scope)',
    ($memHits['appWeb/public_html/manage/my-organisations.php'] ?? 0) === 1);
$expectedMemberSites = [
    'appWeb/public_html/includes/organisation_admin.php',
    'appWeb/public_html/api.php',
    'appWeb/public_html/manage/my-organisations.php',
];
$unexpectedMemberSites = array_values(array_diff(array_keys($memHits), $expectedMemberSites));
ok('(b) no UNEXPECTED "INSERT INTO tblOrganisationMembers" site exists (would be a re-fork of the shared core)'
    . ($unexpectedMemberSites ? ': ' . implode(', ', $unexpectedMemberSites) : ''),
    $unexpectedMemberSites === []);
ok('(b) manage/organisations.php\'s own add_member case no longer carries a raw INSERT INTO tblOrganisationMembers (re-pointed onto orgAdminMemberAdd())',
    !preg_match('/INSERT\s+INTO\s+tblOrganisationMembers\b/i', $pageAddMemberBody));
ok('(b) api.php\'s admin_organisation_member_add case no longer carries a raw INSERT INTO tblOrganisationMembers (re-pointed onto orgAdminMemberAdd())',
    !preg_match('/INSERT\s+INTO\s+tblOrganisationMembers\b/i', $apiMemberAddBody));
ok('(b) manage/organisations.php\'s add_member case calls orgAdminMemberAdd(', str_contains($pageAddMemberBody, 'orgAdminMemberAdd('));
ok('(b) api.php\'s admin_organisation_member_add case calls orgAdminMemberAdd(', str_contains($apiMemberAddBody, 'orgAdminMemberAdd('));

/* ---- (c) gate parity — entitlement key read from the PAGE, not hand-typed ---- */
preg_match("/userHasEntitlement\('([a-z_]+)'/", $pageSrc, $mEnt);
$entKey = $mEnt[1] ?? null;
ok('(c) extracted the page\'s own entitlement key', $entKey === 'manage_organisations');
if ($entKey !== null) {
    ok("(c) api admin_organisation_create gates on userHasEntitlement('{$entKey}'",
        str_contains($apiCreateCode, "userHasEntitlement('{$entKey}'"));
}

/* ---- (c2) THE FINER GATE (#1986) — both JSON-speaking funnels resolve
   manage_org_licences and wrap orgAdminApplyLicenceRows( in an
   if ($canEditOrgLicences) guard. Mutation-proven by stripping the guard
   from a COPY of each real body. ---- */
ok('(c2) manage/organisations.php resolves userHasEntitlement(\'manage_org_licences\' at page scope (before the POST switch — the SAME $canEditOrgLicences the wizard branch reuses)',
    str_contains($pageSrc, "userHasEntitlement('manage_org_licences'"));
ok('(c2) the page\'s wizard branch wraps orgAdminApplyLicenceRows( in an if ($canEditOrgLicences) { … } guard',
    finerGateWrapsCall($pageWizardCode, 'orgAdminApplyLicenceRows('));
ok('(c2) api.php\'s admin_organisation_create case resolves userHasEntitlement(\'manage_org_licences\' locally',
    str_contains($apiCreateCode, "userHasEntitlement('manage_org_licences'"));
ok('(c2) api.php\'s admin_organisation_create case wraps orgAdminApplyLicenceRows( in an if ($canEditOrgLicences) { … } guard',
    finerGateWrapsCall($apiCreateCode, 'orgAdminApplyLicenceRows('));

ok('(c2) fixture precondition: the page wizard body really does contain the literal guard', str_contains($pageWizardBody, 'if ($canEditOrgLicences) {'));
$mutatedPageWizard = str_replace('if ($canEditOrgLicences) {', 'if (true) {', $pageWizardBody);
ok('(c2) MUTATION PROOF: stripping the page wizard branch\'s $canEditOrgLicences guard makes the finer-gate check go red',
    !finerGateWrapsCall(stripPhpComments($mutatedPageWizard), 'orgAdminApplyLicenceRows('));

ok('(c2) fixture precondition: the API case body really does contain the literal guard', str_contains($apiCreateBody, 'if ($canEditOrgLicences) {'));
$mutatedApiCreate = str_replace('if ($canEditOrgLicences) {', 'if (true) {', $apiCreateBody);
ok('(c2) MUTATION PROOF: stripping the API twin\'s $canEditOrgLicences guard makes the finer-gate check go red',
    !finerGateWrapsCall(stripPhpComments($mutatedApiCreate), 'orgAdminApplyLicenceRows('));

/* ---- (d) CSRF by position — the wizard branch's OWN validateCsrfRequest()
   call sits BEFORE the classic forms' validateCsrf() gate later in the
   file (#1992 external-link-types shape). Note: "if (!validateCsrf(" is
   NOT a substring of "if (!validateCsrfRequest(" — they diverge at the
   character right after "validateCsrf" ('(' vs 'R'), so these two needles
   can never false-positive-match each other. ---- */
$wizardCsrfPos = strpos($pageSrc, 'if (!validateCsrfRequest(');
$legacyCsrfPos = strpos($pageSrc, 'if (!validateCsrf(');
ok('(d) found the wizard branch\'s own validateCsrfRequest() gate', $wizardCsrfPos !== false);
ok('(d) found the classic forms\' legacy validateCsrf() gate', $legacyCsrfPos !== false);
if ($wizardCsrfPos !== false && $legacyCsrfPos !== false) {
    ok('(d) the wizard\'s validateCsrfRequest() gate sits BEFORE the legacy validateCsrf() gate',
        $wizardCsrfPos < $legacyCsrfPos);
}
ok('(d) the wizard branch\'s own body genuinely contains its validateCsrfRequest() call (not just found elsewhere in the file)',
    str_contains($pageWizardCode, 'validateCsrfRequest('));
ok('(d) exactly ONE validateCsrfRequest( CALL exists in the page\'s comment-stripped source (the wizard\'s own — no redundant second copy; the doc-comments above mention it in prose several times, which is why this checks the STRIPPED view, not raw $pageSrc)',
    substr_count(stripPhpComments($pageSrc), 'validateCsrfRequest(') === 1);

/* ---- (e) the wizard branch's JSON body ends in exit inside its OWN
   try/catch — never falls through to the page's generic HTML error catch. ---- */
ok('(e) wizard branch opens its own try {', str_contains($pageWizardCode, 'try {'));
ok('(e) wizard branch catches \\Throwable', str_contains($pageWizardCode, 'catch (\\Throwable $e)'));
$wizardTail = rtrim($pageWizardCode);
$wizardTail = rtrim($wizardTail, "} \t\n\r\0\x0B");
ok('(e) wizard branch\'s body ends in exit; (never falls through to the generic catch)',
    (bool)preg_match('/exit;\s*$/', $wizardTail));

/* ---- (f) MANUAL-PATH — dispatchParserActionsForFile() superset +
   MUTATION PROOF, then field-parity between orgAdminValidateCreate()'s
   $in[...] reads and the manual "Add an organisation" form's name=
   controls. ---- */
$pageActions = dispatchParserActionsForFile($pageFile)['names'];
foreach ([
    'create', 'update', 'licence_change', 'add_member',
    'update_member_role', 'remove_member', 'delete', 'wizard_create_organisation',
] as $need) {
    ok("(f) dispatchParserActionsForFile() finds '{$need}' dispatched by the page", in_array($need, $pageActions, true));
}
foreach ([
    'create'                     => "case 'create':",
    'add_member'                 => "case 'add_member':",
    'wizard_create_organisation' => "if (\$action === 'wizard_create_organisation') {",
] as $name => $needle) {
    ok("(f) fixture precondition: literal '{$needle}' really is in the page source", str_contains($pageSrc, $needle));
    $mutatedNames = withMutatedFile($pageSrc, $needle, str_replace($name, "zzz_mutated_{$name}", $needle), static function (string $tmp): array {
        return dispatchParserActionsForFile($tmp)['names'];
    });
    ok("(f) MUTATION PROOF: renaming the '{$name}' dispatch literal makes the enumerator stop reporting it",
        !in_array($name, $mutatedNames, true));
}

$indirectFieldEmitters = [
    'slug' => 'slug-field.php',
];
$coreDerivedFields = inKeysInBody($coreValidateFn);
ok('(f) derived >= 8 $in[...] field reads from orgAdminValidateCreate() (found ' . count($coreDerivedFields) . ')',
    count($coreDerivedFields) >= 8);
foreach ($coreDerivedFields as $key) {
    $viaSharedPartial = isset($indirectFieldEmitters[$key])
        && str_contains($pageSrc, $indirectFieldEmitters[$key])
        && pageEmitsNameFor($slugPartialSrc, $key);
    $label = "(f) core reads \$in['{$key}'] and the page/partial emits a matching name= control"
        . ($viaSharedPartial ? " (indirectly, via {$indirectFieldEmitters[$key]})" : '');
    ok($label, pageEmitsNameFor($pageSrc, $key) || $viaSharedPartial);
}
ok('(f) the page emits the hidden name="action" value="create" input', pageEmitsHiddenAction($pageSrc, 'create'));

/* MUTATION PROOFs — strip a control's name= from a COPY of the page/partial
   source and confirm the presence check goes red. */
ok('(f) fixture precondition: name="name" really is in the page source', str_contains($pageSrc, 'name="name"'));
$mutatedNoName = str_replace('name="name"', 'data-removed="name"', $pageSrc);
ok('(f) MUTATION PROOF: removing the Name control\'s name= makes pageEmitsNameFor() go false',
    !pageEmitsNameFor($mutatedNoName, 'name'));
ok('(f) fixture precondition: the create hidden action input really is in the page source', pageEmitsHiddenAction($pageSrc, 'create'));
$mutatedNoHiddenAction = str_replace('value="create"', 'value="renamed_create"', $pageSrc);
ok('(f) MUTATION PROOF: renaming the hidden action value makes pageEmitsHiddenAction() go false for create',
    !pageEmitsHiddenAction($mutatedNoHiddenAction, 'create'));
ok('(f) fixture precondition: manage/organisations.php really does require slug-field.php', str_contains($pageSrc, 'slug-field.php'));
$mutatedNoSlugRequire = str_replace('slug-field.php', 'zzz-removed.php', $pageSrc);
ok('(f) MUTATION PROOF: removing the slug-field.php require makes the indirect slug-emission check go false',
    !str_contains($mutatedNoSlugRequire, 'slug-field.php'));
ok('(f) fixture precondition: the slug-field.php partial really does emit name="slug"', pageEmitsNameFor($slugPartialSrc, 'slug'));
$mutatedNoSlugName = str_replace('name="slug"', 'data-removed="slug"', $slugPartialSrc);
ok('(f) MUTATION PROOF: removing the slug partial\'s own name="slug" makes the indirect check go false',
    !pageEmitsNameFor($mutatedNoSlugName, 'slug'));

/* ---- (g) IDOR — the wizard branch body reads NO $_POST['org_id'] /
   $_POST["org_id"] anywhere: the freshly-minted id is the ONLY
   organisation id it ever touches. MUTATION PROOF: injecting one into a
   copy of the body flips the check red. ---- */
ok("(g) the wizard branch's body contains no \$_POST['org_id'] read",
    !preg_match('/\$_POST\[\s*[\'"]org_id[\'"]\s*\]/', $pageWizardCode));
$mutatedWizardWithOrgId = $pageWizardBody . "\n\$injected = (int)(\$_POST['org_id'] ?? 0);\n";
ok("(g) MUTATION PROOF: injecting \$_POST['org_id'] into a copy of the wizard body makes the IDOR check go red",
    (bool)preg_match('/\$_POST\[\s*[\'"]org_id[\'"]\s*\]/', stripPhpComments($mutatedWizardWithOrgId)));

/* ---- (h) wiring — trigger + modal + stepper import + createWizard( + the
   Members step's user picker reaches the SAME api2 user_search action
   groups.php's own Add-a-member picker uses. ---- */
ok('(h) page emits a wizard trigger with data-bs-target="#orgWizardModal"', str_contains($pageSrc, 'data-bs-target="#orgWizardModal"'));
ok('(h) page emits the modal element id="orgWizardModal"', str_contains($pageSrc, 'id="orgWizardModal"'));
ok('(h) page imports admin-wizard.js', (bool)preg_match('~from\s+[\'"]/js/modules/admin-wizard\.js~', $pageSrc));
ok('(h) page calls createWizard(', str_contains($pageSrc, 'createWizard('));
ok('(h) the Members step reaches the SAME api2 user_search action groups.php\'s Add-a-member picker uses (rule #22 — no second user-lookup endpoint)',
    str_contains($pageSrc, "action=user_search"));
ok('(h) the Members step wires iHymnsPlaceSearch.attach( for its per-row user picker',
    str_contains($pageSrc, 'iHymnsPlaceSearch.attach('));

$mutatedNoTrigger = str_replace('data-bs-target="#orgWizardModal"', '', $pageSrc);
ok('(h) MUTATION PROOF: removing the trigger button\'s data-bs-target makes the presence check go false',
    !str_contains($mutatedNoTrigger, 'data-bs-target="#orgWizardModal"'));
$mutatedApiCreateGone = withMutatedFile($apiSrc, "case 'admin_organisation_create':", "case 'zzz_removed_create':", static function (string $tmp): string {
    return caseBodyFor($tmp, '$action', 'admin_organisation_create');
});
ok('(h) MUTATION PROOF: removing the API create case makes caseBodyFor() return ""', $mutatedApiCreateGone === '');

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "Every #1996 create-funnel (manual form / guided wizard / API twin) delegates to the SAME\n"
   . "orgAdminValidateCreate()/orgAdminCreate() core, the #1986 finer manage_org_licences gate is\n"
   . "enforced SERVER-SIDE (never DOM-only) in both JSON-speaking funnels with a mutation-proven\n"
   . "guard around orgAdminApplyLicenceRows(), the wizard's own CSRF check sits ahead of the legacy\n"
   . "form gate, its JSON branch can never leak into the page's HTML error catch, it structurally\n"
   . "reads no org_id from the request (IDOR-proof), the manual form still feeds the core's field\n"
   . "reads, and the member-add write path is unified onto the one shared core across all sites.\n";
exit(0);
