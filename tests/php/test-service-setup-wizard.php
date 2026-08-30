<?php

declare(strict_types=1);

/**
 * iHymns — "Live Service setup" guided wizard guard (#1995)
 * =====================================================================
 *
 * ELI5
 * ----
 * #1995 gave curators a guided, step-by-step alternative to the manual
 * venue/service-time forms on `/manage/venues` — built on the SAME shared
 * stepper (`js/modules/admin-wizard.js`, #1992) the External-Link Types
 * (#1992) and Songbooks (#1993) wizards use. Unlike those two, this
 * wizard adds NO new server endpoint at all: its `onFinish` orchestrates,
 * client-side and sequentially, three EXISTING `api.php` actions
 * (`org_admin_venue_save` / `org_admin_schedule_save` /
 * `service_driver_key_mint`) that the #1969/#1770 write cores already
 * expose. This file is the standing guard that keeps that shape honest:
 * the manual forms + their POST handlers stay byte-identical, nothing
 * anywhere forks a second `INSERT` into the tables these actions own, the
 * cross-tenant IDOR re-checks those actions already carry stay in place,
 * the wizard never contains a literal that would start a LIVE SESSION
 * (this is a CONFIG wizard, not a RUNTIME one — rule #26), and the minted
 * driver key is shown exactly once, never persisted client-side.
 *
 * WHAT THIS FILE ASSERTS (spec items (1)-(7), `.claude/` plan for #1995)
 * --------------------------------------------------------------------
 *  (1) MANUAL-PATH — `dispatchParserActionsForFile()` (the SAME tree-
 *      derived parser the standing manage-action/API-coverage guard
 *      uses) still finds `venue_save` / `venue_delete` / `schedule_save`
 *      / `schedule_delete` dispatched by `manage/venues.php` — proven
 *      MUTATION-TESTABLE by renaming each case literal in a mutated TEMP
 *      COPY (never the tracked file) and confirming the parser stops
 *      reporting it. Each case body calls its `venueAdmin*` core
 *      function and contains no raw SQL of its own; the manual forms
 *      still emit a matching hidden `name="action"` input plus `name=`
 *      controls for every field the core function reads via
 *      `$input[...]` (save cases) or `$_POST[...]` (delete cases).
 *  (2) NO FORK — exactly ONE `INSERT INTO tblOrgVenues`, ONE `INSERT INTO
 *      tblOrgServiceSchedules` (both `includes/venue_admin.php`) and ONE
 *      `INSERT INTO tblServiceDriverKeys` (`includes/service_driver_
 *      keys.php`) exist tree-wide — a second copy anywhere is the
 *      regression this catches. The wizard's own isolated BEGIN…END block
 *      contains exactly the THREE `api.php` action-name literals it is
 *      allowed to call, and no `INSERT INTO` of its own (it is markup +
 *      JS, never SQL).
 *  (3) IDOR FREEZE — `org_admin_schedule_save`'s existing-schedule re-
 *      check (`venueAdminGetSchedule($db, $existingScheduleId)` +
 *      `userCanActOnOrg($authUser, (int)$existingSchedule['OrgId'])`,
 *      the 2026-08-29 F1 security-audit fix) and `org_admin_venue_save`'s
 *      existing-venue re-check (`venueAdminGetVenue($db, $venueId)` +
 *      `userCanActOnOrg($authUser, (int)$existing['OrgId'])`) both still
 *      run BEFORE their respective write call — mutation-proven by
 *      `str_replace`-ing each check out of a copy and confirming the
 *      presence assertion goes red. (`tests/php/test-security-schedule-
 *      idor.php` already polices the schedule half in more detail; this
 *      file also confirms that standing guard is still green rather than
 *      re-implementing its own copy of it — rule #22 applied to guards.)
 *  (4) GATE PARITY — the page still gates on `userHasEntitlement(
 *      'manage_organisations'` (unchanged), and both the header trigger
 *      button and the modal markup sit after an `if ($schemaReady &&
 *      $orgs)` gate (mutation-proven: renaming the gate condition drops
 *      both positions from "has a preceding gate").
 *  (5) STEPPER SINGLETON — the page calls `createWizard(` rather than
 *      hand-rolling step logic; the singleton itself (`function
 *      createWizard(` exists exactly once tree-wide, in `js/modules/
 *      admin-wizard.js`) is already asserted by `test-external-link-
 *      wizard.php`'s own (j) — this file confirms that standing guard is
 *      still green instead of re-implementing a second copy of the
 *      tree-wide scan (rule #22 applied to guards).
 *  (6) NO RUNTIME (config freeze) — the wizard's isolated block contains
 *      NEITHER the `service_session_start` NOR the `live_follow_create`
 *      action literal (comment-stripped first, so a prose mention in a
 *      doc-comment can never false-positive) — this is a CONFIG wizard
 *      (venue/schedule/driver-key rows), never a RUNTIME one (it does not
 *      start a live session). Mutation-proven by injecting one of those
 *      literals into a copy of the block and confirming the check goes
 *      red.
 *  (7) SHOW-ONCE — the minted driver key is never written to
 *      `localStorage`/`sessionStorage`, and the DONE-pane copy carries
 *      the house "will not be shown again" wording (mirrors `manage/
 *      service-projection.php`'s own mint card). Mutation-proven by
 *      injecting a `localStorage.setItem(` call into a copy of the block
 *      and confirming the check goes red.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, mirroring `test-external-link-wizard.php` / `test-songbook-
 * wizard.php`'s own precedent:
 *  (1) FIXTURE self-tests (PART 1) prove the parsing primitives this file
 *      is built on — `caseBodyFor()`, `stripPhpComments()`,
 *      `pageEmitsNameFor()`/`pageEmitsHiddenAction()`, `inputKeysInBody()`
 *      /`postKeysInBody()`, `withMutatedFile()` — can both find a marker
 *      that IS there and correctly refuse one that ISN'T, using small
 *      synthetic snippets. These run EVERY invocation.
 *  (2) REAL-CONTENT mutation proofs (PART 3, tagged "MUTATION PROOF") run
 *      the SAME extraction functions against a MUTATED COPY of the real
 *      file content (a `str_replace()`'d in-memory string, or — for the
 *      tree-derived enumerator in (1) — a mutated TEMP FILE that is
 *      written, tested, and deleted within the same assertion, NEVER the
 *      tracked source file) and confirm the check goes red.
 *
 * @see appWeb/public_html/manage/venues.php                page consumer — manual forms + guided wizard
 * @see appWeb/public_html/includes/venue_admin.php          the shared venue/schedule write core (#1969)
 * @see appWeb/public_html/includes/service_driver_keys.php  the shared driver-key lifecycle core (#1770 C4)
 * @see appWeb/public_html/api.php                           org_admin_venue_save / org_admin_schedule_save / service_driver_key_mint
 * @see appWeb/public_html/js/modules/admin-wizard.js         the shared stepper (#1992)
 * @see tests/php/test-external-link-wizard.php               the #1992 precedent this mirrors + adapts
 * @see tests/php/test-songbook-wizard.php                    the #1993 precedent this mirrors + adapts
 * @see tests/php/test-security-schedule-idor.php              the standing schedule-IDOR guard (3) defers to
 * @see tests/php/lib/dispatch_parser.php                     shared tokeniser this file reuses for (1)
 *
 *   php tests/php/test-service-setup-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo         = dirname(__DIR__, 2);
$pageFile     = $repo . '/appWeb/public_html/manage/venues.php';
$apiFile      = $repo . '/appWeb/public_html/api.php';
$coreFile     = $repo . '/appWeb/public_html/includes/venue_admin.php';
$driverKeyFile = $repo . '/appWeb/public_html/includes/service_driver_keys.php';
$wizardFile   = $repo . '/appWeb/public_html/js/modules/admin-wizard.js';
$publicHtml   = $repo . '/appWeb/public_html';

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
function svcwTokensToSource(array $toks, int $from, int $to): string
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
    return svcwTokensToSource($toks, $bodyStart, $bodyEnd);
}

/**
 * Strip `/* ... *​/` block comments and `// ...` line comments from `$src`
 * so a doc-comment's PROSE mention of a function/action name can never
 * masquerade as a real call/literal in the checks below (the real trap
 * `test-songbook-wizard.php` documents hitting on its own doc-comments).
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
    $stripped = stripPhpComments($body);
    preg_match_all('/\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
    return array_values(array_unique($m[1]));
}

/** Every unique `$input['key']` (or `["key"]`) read inside `$body` — the
 *  shared venue/schedule core's own parameter name (`venueAdminSaveVenue(
 *  \mysqli $db, array $input)` / `venueAdminSaveSchedule(...)`), the stand-
 *  in for `$_POST` once the page delegates (mirrors `test-songbook-
 *  wizard.php`'s `inKeysInBody()`, adapted to this core's own param name). */
function inputKeysInBody(string $body): array
{
    $stripped = stripPhpComments($body);
    preg_match_all('/\$input\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $stripped, $m);
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

/** Every occurrence of `$needleRegex` (comment-stripped) under `$dir`, as
 *  `[relativePath => hitCount]`. Generalised over `findInsertIntoSongbooks
 *  TreeWide()` (test-songbook-wizard.php) so the SAME walker serves all
 *  three `INSERT INTO tbl*` needles this file checks (rule #22 applied to
 *  guard helpers). */
function findLiteralTreeWide(string $needleRegex, string $dir, string $repoRoot): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = (string)file_get_contents($f->getPathname());
        $stripped = stripPhpComments($src);
        $n = preg_match_all($needleRegex, $stripped);
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
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_svcw_mut_') . '.php';
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
$fixtureSwitchFile = tempnam(sys_get_temp_dir(), 'ihymns_svcw_fixture_') . '.php';
file_put_contents($fixtureSwitchFile, $fixtureSwitchSrc);

$betaBody = caseBodyFor($fixtureSwitchFile, '$action', 'beta');
ok('caseBodyFor() finds a marker genuinely inside the target case', str_contains($betaBody, 'doBeta('));
ok('MUTATION PROOF: caseBodyFor() does not leak the PRECEDING case\'s marker', !str_contains($betaBody, 'doAlpha('));
ok('MUTATION PROOF: caseBodyFor() does not leak the FOLLOWING case\'s marker', !str_contains($betaBody, 'doGamma('));
ok('MUTATION PROOF: caseBodyFor() returns "" for an absent case name', caseBodyFor($fixtureSwitchFile, '$action', 'delta') === '');

$gammaBody = caseBodyFor($fixtureSwitchFile, '$action', 'gamma'); // last case — the depth-walk-to-switch-close path
ok('caseBodyFor() correctly closes the LAST case in a switch via depth-walk', str_contains($gammaBody, 'doGamma(') && !str_contains($gammaBody, 'doBeta('));

unlink($fixtureSwitchFile);

/* ---- stripPhpComments() ---- */
$fixtureCommentSrc = "real1();\n/* a block comment mentioning fakeCall( in prose */\nreal2(); // a line comment mentioning otherCall(\nreal3();";
$strippedFixture = stripPhpComments($fixtureCommentSrc);
ok('stripPhpComments() keeps real code', str_contains($strippedFixture, 'real1(') && str_contains($strippedFixture, 'real2(') && str_contains($strippedFixture, 'real3('));
ok('MUTATION PROOF: stripPhpComments() removes a block-comment mention', !str_contains($strippedFixture, 'fakeCall('));
ok('MUTATION PROOF: stripPhpComments() removes a line-comment mention', !str_contains($strippedFixture, 'otherCall('));

/* ---- postKeysInBody() / inputKeysInBody() ---- */
$fixtureBody = '$x = $_POST[\'foo\'] ?? null; $y = $input[\'bar\'] ?? null; /* $_POST[\'commented\'] */';
ok('postKeysInBody() finds a real read and ignores a commented-out one', postKeysInBody($fixtureBody) === ['foo']);
ok('inputKeysInBody() finds a real $input[...] read', inputKeysInBody($fixtureBody) === ['bar']);

/* ---- pageEmitsNameFor() / pageEmitsHiddenAction() ---- */
$fixtureHtml = '<input type="text" name="venue_id"><input type="hidden" name="action" value="venue_save">';
ok('pageEmitsNameFor() finds a real name= control', pageEmitsNameFor($fixtureHtml, 'venue_id'));
ok('MUTATION PROOF: pageEmitsNameFor() refuses an absent field name', !pageEmitsNameFor($fixtureHtml, 'display_order'));
ok('pageEmitsHiddenAction() finds the matching hidden action input', pageEmitsHiddenAction($fixtureHtml, 'venue_save'));
ok('MUTATION PROOF: pageEmitsHiddenAction() refuses a non-matching action value', !pageEmitsHiddenAction($fixtureHtml, 'schedule_save'));

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

/* ---- findLiteralTreeWide() ---- */
$fixtureTreeDir = sys_get_temp_dir() . '/ihymns_svcw_tree_fixture_' . uniqid();
mkdir($fixtureTreeDir, 0777, true);
file_put_contents($fixtureTreeDir . '/a.php', "<?php \$x = 'INSERT INTO tblFixture (Col) VALUES (?)';\n");
file_put_contents($fixtureTreeDir . '/b.php', "<?php /* mentions INSERT INTO tblFixture in a comment only, must be ignored */\n");
$fixtureHits = findLiteralTreeWide('/INSERT\s+INTO\s+tblFixture\b/i', $fixtureTreeDir, $fixtureTreeDir);
ok('findLiteralTreeWide() finds the real INSERT and ignores a comment-only mention', array_keys($fixtureHits) === ['a.php']);
unlink($fixtureTreeDir . '/a.php');
unlink($fixtureTreeDir . '/b.php');
rmdir($fixtureTreeDir);

/* ---- withMutatedFile() ---- */
ok('withMutatedFile() applies the str_replace and lets the callback observe it',
    withMutatedFile('needle here', 'needle', 'REPLACED', static fn(string $tmp): bool => str_contains((string)file_get_contents($tmp), 'REPLACED')));
ok('withMutatedFile() deletes its temp file afterwards', (function (): bool {
    $seen = null;
    withMutatedFile('x', 'x', 'y', static function (string $tmp) use (&$seen): void { $seen = $tmp; });
    return $seen !== null && !is_file($seen);
})());

/* =========================================================================
 * PART 2 — load the real sources + isolate the regions under test.
 * ========================================================================= */

foreach ([$pageFile, $apiFile, $coreFile, $driverKeyFile, $wizardFile] as $f) {
    ok("source file exists: {$f}", is_file($f));
}

$pageSrc       = (string)file_get_contents($pageFile);
$apiSrc        = (string)file_get_contents($apiFile);
$coreSrc       = (string)file_get_contents($coreFile);
$driverKeySrc  = (string)file_get_contents($driverKeyFile);

$pageVenueSaveBody     = caseBodyFor($pageFile, '$action', 'venue_save');
$pageVenueDeleteBody   = caseBodyFor($pageFile, '$action', 'venue_delete');
$pageScheduleSaveBody  = caseBodyFor($pageFile, '$action', 'schedule_save');
$pageScheduleDeleteBody = caseBodyFor($pageFile, '$action', 'schedule_delete');

$apiVenueSaveBody    = caseBodyFor($apiFile, '$action', 'org_admin_venue_save');
$apiScheduleSaveBody = caseBodyFor($apiFile, '$action', 'org_admin_schedule_save');
$apiMintBody         = caseBodyFor($apiFile, '$action', 'service_driver_key_mint');

ok("isolated the page's venue_save case body (non-empty)", $pageVenueSaveBody !== '');
ok("isolated the page's venue_delete case body (non-empty)", $pageVenueDeleteBody !== '');
ok("isolated the page's schedule_save case body (non-empty)", $pageScheduleSaveBody !== '');
ok("isolated the page's schedule_delete case body (non-empty)", $pageScheduleDeleteBody !== '');
ok("isolated the API's org_admin_venue_save case body (non-empty)", $apiVenueSaveBody !== '');
ok("isolated the API's org_admin_schedule_save case body (non-empty)", $apiScheduleSaveBody !== '');
ok("isolated the API's service_driver_key_mint case body (non-empty)", $apiMintBody !== '');

$coreSaveVenueFn    = functionBodyFor($coreSrc, 'venueAdminSaveVenue');
$coreSaveScheduleFn = functionBodyFor($coreSrc, 'venueAdminSaveSchedule');
ok('isolated venueAdminSaveVenue() body (non-empty)', $coreSaveVenueFn !== '');
ok('isolated venueAdminSaveSchedule() body (non-empty)', $coreSaveScheduleFn !== '');

/* The wizard's own isolated block, bounded by the literal markers the page
   carries around it (see manage/venues.php's own "#1995 — Live Service
   setup wizard: modal + wiring. BEGIN"/"...END" comments). Everything
   checks (2)/(6)/(7) below care about — the fetch() calls, the action
   literals, the DONE-pane rendering — lives inside this one region. */
$wizBeginMarker = '#1995 — Live Service setup wizard: modal + wiring. BEGIN';
$wizEndMarker   = '#1995 — Live Service setup wizard: modal + wiring. END';
$wizBeginPos = strpos($pageSrc, $wizBeginMarker);
$wizEndPos   = strpos($pageSrc, $wizEndMarker);
ok('found the wizard block BEGIN marker', $wizBeginPos !== false);
ok('found the wizard block END marker', $wizEndPos !== false);
ok('the wizard block END marker comes after its BEGIN marker', $wizBeginPos !== false && $wizEndPos !== false && $wizEndPos > $wizBeginPos);
$wizardBlock = ($wizBeginPos !== false && $wizEndPos !== false && $wizEndPos > $wizBeginPos)
    ? substr($pageSrc, $wizBeginPos, $wizEndPos - $wizBeginPos)
    : '';
ok('isolated the wizard block (non-empty)', $wizardBlock !== '');
$wizardBlockCode = stripPhpComments($wizardBlock);

/* =========================================================================
 * PART 3 — the (1)-(7) assertions.
 * ========================================================================= */

echo "\n\"Live Service setup\" guided wizard guard (#1995)\n\n";

/* ---- (1) MANUAL-PATH ---- */
$pageActions = dispatchParserActionsForFile($pageFile)['names'];
foreach (['venue_save', 'venue_delete', 'schedule_save', 'schedule_delete'] as $need) {
    ok("(1) dispatchParserActionsForFile() finds '{$need}' dispatched by the page", in_array($need, $pageActions, true));
}
foreach ([
    'venue_save'      => "case 'venue_save':",
    'venue_delete'    => "case 'venue_delete':",
    'schedule_save'   => "case 'schedule_save':",
    'schedule_delete' => "case 'schedule_delete':",
] as $name => $needle) {
    ok("(1) fixture precondition: literal '{$needle}' really is in the page source", str_contains($pageSrc, $needle));
    $mutatedNames = withMutatedFile($pageSrc, $needle, "case 'zzz_mutated_{$name}':", static function (string $tmp): array {
        return dispatchParserActionsForFile($tmp)['names'];
    });
    ok("(1) MUTATION PROOF: renaming the '{$name}' case literal makes the enumerator stop reporting it",
        !in_array($name, $mutatedNames, true));
}

foreach ([
    'venue_save'      => ['body' => $pageVenueSaveBody,     'core' => 'venueAdminSaveVenue(',     'sql' => '/INSERT\s+INTO\s+tblOrgVenues\b|UPDATE\s+tblOrgVenues\b/i'],
    'schedule_save'   => ['body' => $pageScheduleSaveBody,  'core' => 'venueAdminSaveSchedule(',  'sql' => '/INSERT\s+INTO\s+tblOrgServiceSchedules\b|UPDATE\s+tblOrgServiceSchedules\b/i'],
    'venue_delete'    => ['body' => $pageVenueDeleteBody,   'core' => 'venueAdminDeleteVenue(',   'sql' => '/DELETE\s+FROM\s+tblOrgVenues\b/i'],
    'schedule_delete' => ['body' => $pageScheduleDeleteBody, 'core' => 'venueAdminDeleteSchedule(', 'sql' => '/DELETE\s+FROM\s+tblOrgServiceSchedules\b/i'],
] as $label => $spec) {
    $code = stripPhpComments($spec['body']);
    ok("(1) page '{$label}' case calls its shared core ({$spec['core']}", str_contains($code, $spec['core']));
    ok("(1) page '{$label}' case contains no raw SQL of its own (that lives ONLY in the shared core)",
        !preg_match($spec['sql'], $code));
}

/* The manual forms feed venueAdminSaveVenue()/venueAdminSaveSchedule()'s
   own $input[...] reads — derived from the CORE, never a hand-typed field
   list (a new field the core starts reading is covered automatically). */
$venueCoreFields = inputKeysInBody($coreSaveVenueFn);
ok('(1) derived >= 10 $input[...] field reads from venueAdminSaveVenue() (found ' . count($venueCoreFields) . ')', count($venueCoreFields) >= 10);
foreach ($venueCoreFields as $key) {
    ok("(1) venueAdminSaveVenue() reads \$input['{$key}'] and the page emits a matching name= control", pageEmitsNameFor($pageSrc, $key));
}
ok('(1) the page emits the hidden name="action" value="venue_save" input', pageEmitsHiddenAction($pageSrc, 'venue_save'));

$scheduleCoreFields = inputKeysInBody($coreSaveScheduleFn);
ok('(1) derived >= 10 $input[...] field reads from venueAdminSaveSchedule() (found ' . count($scheduleCoreFields) . ')', count($scheduleCoreFields) >= 10);
foreach ($scheduleCoreFields as $key) {
    ok("(1) venueAdminSaveSchedule() reads \$input['{$key}'] and the page emits a matching name= control", pageEmitsNameFor($pageSrc, $key));
}
ok('(1) the page emits the hidden name="action" value="schedule_save" input', pageEmitsHiddenAction($pageSrc, 'schedule_save'));

$venueDeleteFields = postKeysInBody($pageVenueDeleteBody);
ok('(1) derived >= 1 $_POST field read for venue_delete (found ' . count($venueDeleteFields) . ')', count($venueDeleteFields) > 0);
foreach ($venueDeleteFields as $key) {
    ok("(1) venue_delete reads \$_POST['{$key}'] and the page emits a matching name= control", pageEmitsNameFor($pageSrc, $key));
}
ok('(1) the page emits the hidden name="action" value="venue_delete" input', pageEmitsHiddenAction($pageSrc, 'venue_delete'));

$scheduleDeleteFields = postKeysInBody($pageScheduleDeleteBody);
ok('(1) derived >= 1 $_POST field read for schedule_delete (found ' . count($scheduleDeleteFields) . ')', count($scheduleDeleteFields) > 0);
foreach ($scheduleDeleteFields as $key) {
    ok("(1) schedule_delete reads \$_POST['{$key}'] and the page emits a matching name= control", pageEmitsNameFor($pageSrc, $key));
}
ok('(1) the page emits the hidden name="action" value="schedule_delete" input', pageEmitsHiddenAction($pageSrc, 'schedule_delete'));

/* MUTATION PROOFs — strip a control's name= from a COPY of the page source
   and confirm the presence check goes red. */
ok('(1) fixture precondition: name="venue_id" really is in the page source', str_contains($pageSrc, 'name="venue_id"'));
$mutatedNoVenueId = str_replace('name="venue_id"', 'data-removed="venue_id"', $pageSrc);
ok('(1) MUTATION PROOF: removing every venue_id control\'s name= makes pageEmitsNameFor() go false', !pageEmitsNameFor($mutatedNoVenueId, 'venue_id'));
ok('(1) fixture precondition: the venue_save hidden action really is in the page source', pageEmitsHiddenAction($pageSrc, 'venue_save'));
$mutatedNoHiddenAction = str_replace('value="venue_save"', 'value="renamed_venue_save"', $pageSrc);
ok('(1) MUTATION PROOF: renaming the hidden action value makes pageEmitsHiddenAction() go false for venue_save',
    !pageEmitsHiddenAction($mutatedNoHiddenAction, 'venue_save'));

/* ---- (2) NO FORK ---- */
$venueInsertHits    = findLiteralTreeWide('/INSERT\s+INTO\s+tblOrgVenues\b/i', $publicHtml, $repo);
$scheduleInsertHits = findLiteralTreeWide('/INSERT\s+INTO\s+tblOrgServiceSchedules\b/i', $publicHtml, $repo);
$driverInsertHits   = findLiteralTreeWide('/INSERT\s+INTO\s+tblServiceDriverKeys\b/i', $publicHtml, $repo);

ok('(2) exactly ONE "INSERT INTO tblOrgVenues" literal tree-wide (found: ' . implode(', ', array_keys($venueInsertHits)) . ')',
    array_sum($venueInsertHits) === 1 && ($venueInsertHits['appWeb/public_html/includes/venue_admin.php'] ?? 0) === 1);
ok('(2) exactly ONE "INSERT INTO tblOrgServiceSchedules" literal tree-wide (found: ' . implode(', ', array_keys($scheduleInsertHits)) . ')',
    array_sum($scheduleInsertHits) === 1 && ($scheduleInsertHits['appWeb/public_html/includes/venue_admin.php'] ?? 0) === 1);
ok('(2) exactly ONE "INSERT INTO tblServiceDriverKeys" literal tree-wide (found: ' . implode(', ', array_keys($driverInsertHits)) . ')',
    array_sum($driverInsertHits) === 1 && ($driverInsertHits['appWeb/public_html/includes/service_driver_keys.php'] ?? 0) === 1);

/* The wizard's own block: no SQL of its own, and EXACTLY the three
   api.php action-name literals it is allowed to call (never a fourth). */
ok('(2) the wizard block contains no "INSERT INTO" of its own (it is markup + JS, never SQL)',
    !preg_match('/INSERT\s+INTO/i', $wizardBlockCode));
preg_match_all("/svcwizApiCall\(\s*'([a-zA-Z_]+)'/", $wizardBlockCode, $mActions);
$wizardActionLiterals = array_values(array_unique($mActions[1]));
sort($wizardActionLiterals);
$expectedWizardActions = ['org_admin_schedule_save', 'org_admin_venue_save', 'service_driver_key_mint'];
ok('(2) the wizard block calls EXACTLY the three expected api.php actions (found: ' . implode(', ', $wizardActionLiterals) . ')',
    $wizardActionLiterals === $expectedWizardActions);

/* ---- (3) IDOR FREEZE ---- */
ok('(3) org_admin_schedule_save re-loads the EXISTING schedule by its own id (venueAdminGetSchedule($db, $existingScheduleId))',
    (bool)preg_match('/venueAdminGetSchedule\s*\(\s*\$db\s*,\s*\$existingScheduleId\s*\)/', $apiScheduleSaveBody));
ok('(3) org_admin_schedule_save re-checks userCanActOnOrg against the EXISTING schedule\'s OrgId',
    (bool)preg_match('/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$existingSchedule\[[\'"]OrgId[\'"]\]\s*\)/', $apiScheduleSaveBody));
$posExistingSchedule = strpos($apiScheduleSaveBody, '$existingSchedule = venueAdminGetSchedule');
$posSaveSchedule     = strpos($apiScheduleSaveBody, 'venueAdminSaveSchedule($db, $body)');
ok('(3) the schedule existing-row org re-check runs BEFORE venueAdminSaveSchedule()',
    $posExistingSchedule !== false && $posSaveSchedule !== false && $posExistingSchedule < $posSaveSchedule);

ok('(3) org_admin_venue_save re-loads the EXISTING venue by its own id (venueAdminGetVenue($db, $venueId))',
    (bool)preg_match('/venueAdminGetVenue\s*\(\s*\$db\s*,\s*\$venueId\s*\)/', $apiVenueSaveBody));
ok('(3) org_admin_venue_save re-checks userCanActOnOrg against the EXISTING venue\'s OrgId',
    (bool)preg_match('/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$existing\[[\'"]OrgId[\'"]\]\s*\)/', $apiVenueSaveBody));
$posExistingVenue = strpos($apiVenueSaveBody, '$existing = venueAdminGetVenue');
$posSaveVenue      = strpos($apiVenueSaveBody, 'venueAdminSaveVenue($db, $body)');
ok('(3) the venue existing-row org re-check runs BEFORE venueAdminSaveVenue()',
    $posExistingVenue !== false && $posSaveVenue !== false && $posExistingVenue < $posSaveVenue);

/* MUTATION PROOF for both re-checks, on in-memory copies (never the
   tracked api.php file). */
ok('(3) fixture precondition: the schedule org re-check literal really is present',
    str_contains($apiScheduleSaveBody, "userCanActOnOrg(\$authUser, (int)\$existingSchedule['OrgId'])"));
$mutatedNoScheduleCheck = str_replace(
    "userCanActOnOrg(\$authUser, (int)\$existingSchedule['OrgId'])",
    'true /* removed */',
    $apiScheduleSaveBody
);
ok('(3) MUTATION PROOF: removing the schedule existing-row org re-check makes the presence assertion go false',
    !(bool)preg_match('/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$existingSchedule\[[\'"]OrgId[\'"]\]\s*\)/', $mutatedNoScheduleCheck));

ok('(3) fixture precondition: the venue org re-check literal really is present',
    str_contains($apiVenueSaveBody, "userCanActOnOrg(\$authUser, (int)\$existing['OrgId'])"));
$mutatedNoVenueCheck = str_replace(
    "userCanActOnOrg(\$authUser, (int)\$existing['OrgId'])",
    'true /* removed */',
    $apiVenueSaveBody
);
ok('(3) MUTATION PROOF: removing the venue existing-row org re-check makes the presence assertion go false',
    !(bool)preg_match('/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$existing\[[\'"]OrgId[\'"]\]\s*\)/', $mutatedNoVenueCheck));

/* Delegate to the standing, more-detailed schedule-IDOR guard rather than
   re-implementing a second copy of it (rule #22 applied to guards — mirrors
   test-songbook-wizard.php's (f) deferring to test-songbook-form-parity.php). */
$idorGuardOutput = [];
$idorGuardStatus = 0;
exec('php ' . escapeshellarg(__DIR__ . '/test-security-schedule-idor.php') . ' 2>&1', $idorGuardOutput, $idorGuardStatus);
ok('(3) tests/php/test-security-schedule-idor.php (the standing schedule-IDOR guard) is still green', $idorGuardStatus === 0);

/* ---- (4) GATE PARITY + schema-ready placement ---- */
preg_match("/userHasEntitlement\('([a-z_]+)'/", $pageSrc, $mEnt);
$entKey = $mEnt[1] ?? null;
ok('(4) extracted the page\'s own entitlement key', $entKey === 'manage_organisations');

preg_match_all('/if\s*\(\s*\$schemaReady\s*&&\s*\$orgs\s*\)/', $pageSrc, $mGates, PREG_OFFSET_CAPTURE);
$gatePositions = array_map(static fn(array $x): int => $x[1], $mGates[0]);
ok('(4) found at least 2 "if ($schemaReady && $orgs)" gates in the page (header trigger + wizard block)', count($gatePositions) >= 2);

function svcwNearestPrecedingGate(array $gatePositions, int $targetPos): ?int
{
    $best = null;
    foreach ($gatePositions as $g) { if ($g < $targetPos) { $best = $g; } }
    return $best;
}
$btnPos   = strpos($pageSrc, 'data-bs-target="#svcWizardModal"');
$modalPos = strpos($pageSrc, '<div class="modal fade" id="svcWizardModal"');
ok('(4) found the trigger button and the modal element', $btnPos !== false && $modalPos !== false);
if ($btnPos !== false) {
    ok('(4) the trigger button sits after a schema-ready gate', svcwNearestPrecedingGate($gatePositions, $btnPos) !== null);
}
if ($modalPos !== false) {
    ok('(4) the modal markup sits after a schema-ready gate', svcwNearestPrecedingGate($gatePositions, $modalPos) !== null);
}
/* MUTATION PROOF — renaming the gate condition drops both positions from
   "has a preceding gate". */
$mutatedGateGone = str_replace('if ($schemaReady && $orgs)', 'if (false /* zzz_mutated_gate */)', $pageSrc);
preg_match_all('/if\s*\(\s*\$schemaReady\s*&&\s*\$orgs\s*\)/', $mutatedGateGone, $mGatesMutated, PREG_OFFSET_CAPTURE);
ok('(4) MUTATION PROOF: renaming the schema-ready gate condition leaves zero matching gates',
    count($mGatesMutated[0]) === 0);

/* ---- (5) STEPPER SINGLETON ---- */
ok('(5) page calls createWizard( (the shared stepper)', str_contains($pageSrc, 'createWizard('));
ok('(5) page imports admin-wizard.js', (bool)preg_match('~from\s+[\'"]/js/modules/admin-wizard\.js~', $pageSrc));
$singletonGuardOutput = [];
$singletonGuardStatus = 0;
exec('php ' . escapeshellarg(__DIR__ . '/test-external-link-wizard.php') . ' 2>&1', $singletonGuardOutput, $singletonGuardStatus);
ok('(5) tests/php/test-external-link-wizard.php (its own (j) createWizard-singleton scan) is still green', $singletonGuardStatus === 0);

/* ---- (6) NO RUNTIME (config freeze) ---- */
ok('(6) the wizard block contains no "service_session_start" literal', !str_contains($wizardBlockCode, 'service_session_start'));
ok('(6) the wizard block contains no "live_follow_create" literal', !str_contains($wizardBlockCode, 'live_follow_create'));

/* MUTATION PROOF — inject a REAL (non-comment) forbidden literal into a
   copy of the isolated block, comment-strip it exactly as above, and
   confirm the SAME boolean the real assertion computes flips to false
   (i.e. the guard goes red). A literal placed INSIDE a comment is a
   fixture-precondition check first, so this proof cannot be fooled by
   its own injection landing somewhere stripPhpComments() would hide it
   regardless of whether the freeze is honoured. */
$mutatedRuntimeInjected = str_replace(
    'import { createWizard }',
    "const zzzInjected = 'service_session_start'; import { createWizard }",
    $wizardBlock
);
ok('(6) fixture precondition: the injected literal is REAL code, not inside a comment',
    str_contains(stripPhpComments($mutatedRuntimeInjected), "'service_session_start'"));
$mutatedRuntimeCheckPasses = !str_contains(stripPhpComments($mutatedRuntimeInjected), 'service_session_start');
ok('(6) MUTATION PROOF: injecting a real "service_session_start" literal flips the no-runtime-literal check to false (red)',
    $mutatedRuntimeCheckPasses === false);

/* ---- (7) SHOW-ONCE ---- */
ok('(7) the wizard block never writes to localStorage', !str_contains($wizardBlockCode, 'localStorage'));
ok('(7) the wizard block never writes to sessionStorage', !str_contains($wizardBlockCode, 'sessionStorage'));
ok('(7) the DONE-pane carries the house "will not be shown again" show-once wording',
    str_contains($wizardBlock, 'will not be shown again'));

/* MUTATION PROOF — same shape as (6): inject a real (non-comment)
   localStorage write and confirm the check's own boolean flips to false. */
$mutatedLocalStorageInjected = str_replace(
    'function showDonePane(info) {',
    "function showDonePane(info) { localStorage.setItem('zzz', 'x');",
    $wizardBlock
);
ok('(7) fixture precondition: the injected localStorage.setItem( call is REAL code, not inside a comment',
    str_contains(stripPhpComments($mutatedLocalStorageInjected), "localStorage.setItem('zzz'"));
$mutatedStorageCheckPasses = !str_contains(stripPhpComments($mutatedLocalStorageInjected), 'localStorage');
ok('(7) MUTATION PROOF: injecting a real localStorage.setItem( call flips the never-persisted check to false (red)',
    $mutatedStorageCheckPasses === false);

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
echo "The manual venue/schedule forms + their POST handlers stay wired to the SAME\n"
   . "venueAdmin*() cores with no raw SQL of their own, no INSERT into tblOrgVenues /\n"
   . "tblOrgServiceSchedules / tblServiceDriverKeys exists anywhere but the three shared\n"
   . "cores, the cross-tenant IDOR re-checks on both org_admin_venue_save and\n"
   . "org_admin_schedule_save are still in place and ordered correctly, the wizard sits\n"
   . "behind the page's own manage_organisations + schema-ready gates, it consumes\n"
   . "(never re-implements) the shared createWizard() stepper, it carries no runtime\n"
   . "session-start literal (this is a CONFIG wizard, not a RUNTIME one), and the minted\n"
   . "driver key is shown exactly once with no client-side persistence.\n";
exit(0);
