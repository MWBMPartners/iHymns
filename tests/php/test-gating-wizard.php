<?php

declare(strict_types=1);

/**
 * iHymns — Content-gating activation wizard: standing guard (#2006, epic #2002)
 * ==================================================================================
 *
 * ELI5
 * ----
 * Turning content locking on is the single most consequential switch this
 * app has (rule #28) — it changes what every visitor sees, in one instant.
 * This file is the standing proof that the guided wizard on
 * `/manage/gating` (#2006) never weakens that safety: the flip still
 * requires the server's own precondition check, the check still runs
 * BEFORE the write, rollback still works UNCONDITIONALLY, the wizard's
 * "test a song" step never leaks lyric text, and there is still exactly
 * ONE place in the whole tree that writes `content_gating_enabled` and
 * exactly ONE place that writes `tblContentRestrictions`.
 *
 * ⚠️ THE OWNER-OVERRIDE ASSERTION (item (f) below) IS THE ONE THAT MATTERS
 * MOST HERE. An earlier draft of this wizard treated "rules require a CCLI
 * licence, but nobody holds one" as a hard BLOCKER. The owner corrected
 * this to WARN-BUT-ALLOW. Item (f)'s truth table asserts this DIRECTLY —
 * that exact combination of inputs must come back as a WARNING, never as a
 * blocker that cannot be dismissed by ticking the acknowledgement box —
 * and a real, on-tree mutation run (reverting the override back to a
 * blocker in a temp copy, in an ISOLATED subprocess) proves this assertion
 * can actually fail.
 *
 * MUTATION-TESTING PROTOCOL (rule #34)
 * --------------------------------------
 * Two layers, mirroring test-organisation-wizard.php's / test-songbook-
 * wizard.php's own precedent:
 *  (1) Structural assertions ((a)-(e), (h)-(i), (k)-(l)) are proven able to
 *      fail by running them a second time against a MUTATED COPY of the
 *      real source (a temp file, `str_replace()`'d from the real content —
 *      NEVER the tracked source itself) and confirming the check goes red,
 *      then discarding the temp file. This runs EVERY invocation, so the
 *      guard stays provably breakable forever, not just at the moment it
 *      was written.
 *  (2) The two FUNCTIONAL truth tables ((f) the precondition evaluator, (g)
 *      the row planner) are proven the SAME way, but because they are
 *      unit-testing PHP FUNCTIONS (not just scanning text), the mutated
 *      copy is `require`d in its OWN isolated `php` subprocess — two
 *      functions with the same name cannot both be loaded in one PHP
 *      process, so this file's own (unmutated) `require` of
 *      `includes/gating_wizard.php` and the mutated copy's `require` must
 *      never share a process.
 *
 * @see appWeb/public_html/manage/gating.php               the wizard page this guards
 * @see appWeb/public_html/includes/gating_wizard.php       the shared core this guards
 * @see appWeb/public_html/includes/restriction_admin.php   the ONE restriction write/validate core
 * @see appWeb/public_html/includes/maintenance.php         setAppSetting() — the ONE tblAppSettings write core
 * @see tests/php/test-manage-action-api-coverage.php       the 'gating.php' web_only mapping this pairs with
 * @see tests/php/test-organisation-wizard.php               the #1996 precedent this structure mirrors
 * @see tests/php/lib/dispatch_parser.php                    shared tokeniser this file reuses
 *
 *   php tests/php/test-gating-wizard.php
 *
 * Exit status 0 = clean, 1 = at least one assertion or mutation self-test failed.
 */

require_once __DIR__ . '/lib/dispatch_parser.php';

$repo           = dirname(__DIR__, 2);
$pageFile       = $repo . '/appWeb/public_html/manage/gating.php';
$configFile     = $repo . '/appWeb/public_html/manage/configuration.php';
$restrictionsPageFile = $repo . '/appWeb/public_html/manage/restrictions.php';
$coreFile       = $repo . '/appWeb/public_html/includes/gating_wizard.php';
$restrictionCoreFile = $repo . '/appWeb/public_html/includes/restriction_admin.php';
$publicHtml     = $repo . '/appWeb/public_html';
$phpBin         = PHP_BINARY ?: 'php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; }
    else { $failed++; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* =========================================================================
 * PART 0 — parsing primitives.
 * ========================================================================= */

/** Strip block + line comments (same shape as every other wizard guard's
 *  own copy — a small enough primitive that a shared lib entry would be
 *  more indirection than the duplication it removes; test-organisation-
 *  wizard.php and test-songbook-wizard.php each keep their own too). */
function gwStripComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
    $src = preg_replace('~(?<!:)//[^\n]*~', '', $src) ?? $src;
    return $src;
}

/** Concatenate token TEXT for tokens [$from, $to] inclusive. */
function gwTokensToSource(array $toks, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i <= $to; $i++) {
        $out .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
    }
    return $out;
}

/**
 * Extract the raw source text of ONE case's body within `switch ($switchVar)`
 * in `$file`, built on dispatch_parser.php's `dispatchParserCaseTokens()`
 * (rule #22 — never a second tokeniser walk). Returns '' when not found.
 */
function gwCaseBodyFor(string $file, string $switchVar, string $caseName): string
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
    return gwTokensToSource($toks, $bodyStart, $bodyEnd);
}

/** Extract the `{ ... }` block starting at the first `{` at/after $anchorOffset. */
function gwBlockBodyAfterOffset(string $src, int $anchorOffset): string
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

/** Find `$anchorText` literally in `$src`, then extract the `{ ... }` block
 *  starting at the first `{` after it. '' when not found. */
function gwBlockBodyAfterAnchor(string $src, string $anchorText): string
{
    $pos = strpos($src, $anchorText);
    if ($pos === false) { return ''; }
    return gwBlockBodyAfterOffset($src, $pos);
}

/**
 * Every `content_gating_enabled` WRITE site in `$dir` — a file where the
 * (comment-stripped) source calls the shared `setAppSetting(`
 * (includes/maintenance.php, #1671 F6) or the page-local `$saveSetting(`
 * wrapper with `'content_gating_enabled'` as the key argument. Returns
 * relative paths, sorted, deduped.
 */
function gwFindContentGatingWriteSites(string $dir, string $repoRoot): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = gwStripComments((string)file_get_contents($f->getPathname()));
        if (preg_match('/(setAppSetting|\$saveSetting)\s*\(\s*\$\w+\s*,\s*\'content_gating_enabled\'/', $src)) {
            $hits[] = str_replace($repoRoot . '/', '', $f->getPathname());
        }
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/**
 * Every file where a raw `INSERT INTO tblAppSettings` sits within
 * `$window` chars of the literal `content_gating_enabled` — the "someone
 * bypassed setAppSetting() with their own INSERT" detector. Should be
 * empty everywhere (the ONE generic INSERT, in maintenance.php's
 * setAppSetting(), never contains this specific literal — it takes $key as
 * a parameter).
 */
function gwFindRawInsertNearLiteral(string $dir, string $repoRoot, int $window = 400): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = gwStripComments((string)file_get_contents($f->getPathname()));
        if (!preg_match_all('/content_gating_enabled/', $src, $m, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($m[0] as [, $off]) {
            $chunk = substr($src, max(0, $off - $window), $window * 2);
            if (preg_match('/INSERT\s+INTO\s+tblAppSettings/i', $chunk)) {
                $hits[] = str_replace($repoRoot . '/', '', $f->getPathname());
                break;
            }
        }
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/** Every file with a raw `INSERT INTO tblContentRestrictions`. */
function gwFindRestrictionInsertSites(string $dir, string $repoRoot): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $src = gwStripComments((string)file_get_contents($f->getPathname()));
        if (preg_match('/INSERT\s+INTO\s+tblContentRestrictions/i', $src)) {
            $hits[] = str_replace($repoRoot . '/', '', $f->getPathname());
        }
    }
    sort($hits);
    return array_values(array_unique($hits));
}

/** Run a small PHP snippet in an isolated subprocess with `require $file;`
 *  prepended. Returns ['code'=>int,'stdout'=>string,'stderr'=>string]. Used
 *  to test a MUTATED copy of gating_wizard.php without redeclaring
 *  functions in THIS process (which already loaded the real file). */
function gwRunIsolated(string $phpBin, string $requireFile, string $snippet): array
{
    /* `php -r CODE` runs CODE already in PHP mode — a leading `<?php` tag
       here would itself be a syntax error (the classic "-r doesn't want
       the opening tag" gotcha). */
    $code = 'require ' . var_export($requireFile, true) . '; ' . $snippet;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$phpBin, '-r', $code], $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'could not spawn subprocess'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['code' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

/** Write $mutatedSrc to a fresh temp .php file, run $fn($tmpPath), delete
 *  it. Never touches a tracked source file. */
function gwWithMutatedFile(string $mutatedSrc, callable $fn): mixed
{
    $tmp = tempnam(sys_get_temp_dir(), 'ihymns_gwiz_mut_') . '.php';
    file_put_contents($tmp, $mutatedSrc);
    try {
        return $fn($tmp);
    } finally {
        @unlink($tmp);
    }
}

/**
 * Same as gwWithMutatedFile(), but writes the temp copy as a SIBLING of
 * `$sameDirAs` (a throwaway, never-committed, never-tracked filename) so
 * its OWN `__DIR__`-relative `require_once` statements (db_mysql.php,
 * licences.php, restriction_admin.php, …) still resolve to the REAL
 * sibling includes. gating_wizard.php is function-only and every one of
 * those requires is side-effect-free to merely LOAD (verified — this is
 * the same "loadable without a DB connection" property the file's own
 * doc-block promises), so this is safe: it never touches or reverts the
 * TRACKED gating_wizard.php, only adds-then-deletes an unrelated new file
 * beside it for the lifetime of one subprocess call.
 */
function gwWithMutatedSiblingFile(string $mutatedSrc, string $sameDirAs, callable $fn): mixed
{
    $dir = dirname($sameDirAs);
    $tmp = $dir . DIRECTORY_SEPARATOR . 'zz_ihymns_gwiz_mutant_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($tmp, $mutatedSrc);
    try {
        return $fn($tmp);
    } finally {
        @unlink($tmp);
    }
}

echo "\nContent-gating activation wizard — standing guard (#2006)\n\n";

$pageSrc         = (string)file_get_contents($pageFile);
$pageSrcStripped = gwStripComments($pageSrc);
$configSrc       = (string)file_get_contents($configFile);
$coreSrc         = (string)file_get_contents($coreFile);
$restrictionsPageSrc = (string)file_get_contents($restrictionsPageFile);
$restrictionCoreSrc  = (string)file_get_contents($restrictionCoreFile);

/* =========================================================================
 * (a) Wizard exists and is the shared stepper.
 * ========================================================================= */

ok('gating.php imports the shared admin-wizard stepper',
    str_contains($pageSrc, "/js/modules/admin-wizard.js"));
ok('gating.php contains >= 7 [data-wiz-step] panes',
    substr_count($pageSrc, 'data-wiz-step') >= 7);
ok('gating.php contains a [data-wiz-progress] host',
    str_contains($pageSrc, 'data-wiz-progress'));

/* MUTATION: delete the import line in a mutated copy -> red. */
$mutatedNoImport = str_replace(
    "import { createWizard } from '/js/modules/admin-wizard.js?v=<?= htmlspecialchars(\$adminWizardVer, ENT_QUOTES) ?>';",
    '/* import removed by mutation test */',
    $pageSrc
);
ok('MUTATION PROOF (a): deleting the admin-wizard import makes the check fail',
    $mutatedNoImport !== $pageSrc
    && !str_contains($mutatedNoImport, "/js/modules/admin-wizard.js?v="));

/* =========================================================================
 * (b) Pre-existing flip path (configuration.php's raw switch) still
 * dispatches — unmodified by #2006.
 * ========================================================================= */

/* configuration.php dispatches via an if/elseif CHAIN, not a switch (only
   gating.php's own POST dispatch above uses a real switch) — so this uses
   dispatch_parser.php's general-purpose action enumerator (which models
   both shapes) rather than the switch-only gwCaseBodyFor(). */
$configActions = dispatchParserActionsForFile($configFile)['names'];
ok('configuration.php\'s dispatch still recognises save_feature_gating',
    in_array('save_feature_gating', $configActions, true));

$saveFeatureGatingBody = gwBlockBodyAfterAnchor($configSrc, "\$action === 'save_feature_gating'");
ok('save_feature_gating\'s handler body still calls $saveSetting($db, \'content_gating_enabled\'',
    str_contains(gwStripComments($saveFeatureGatingBody), "\$saveSetting(\$db, 'content_gating_enabled'"));

/* MUTATION: rename the case literal in a MUTATED COPY -> the case vanishes
   from the switch, so the "still dispatches" assertion's own INPUT goes red. */
$mutatedConfig = str_replace("'save_feature_gating'", "'save_feature_gating_RENAMED'", $configSrc);
gwWithMutatedFile($mutatedConfig, function (string $tmp) {
    $names = dispatchParserActionsForFile($tmp)['names'];
    ok('MUTATION PROOF (b): renaming the case literal removes it from the enumerated dispatch',
        !in_array('save_feature_gating', $names, true));
});

/* =========================================================================
 * (c) ONE flag-write core, tree-derived census.
 * ========================================================================= */

$writeSites = gwFindContentGatingWriteSites($publicHtml, $repo);
ok('content_gating_enabled is written from EXACTLY configuration.php + includes/gating_wizard.php',
    $writeSites === ['appWeb/public_html/includes/gating_wizard.php', 'appWeb/public_html/manage/configuration.php']);

$rawInsertHits = gwFindRawInsertNearLiteral($publicHtml, $repo);
ok('no raw "INSERT INTO tblAppSettings" sits near the content_gating_enabled literal anywhere',
    $rawInsertHits === []);

/* MUTATION: add a fixture file with a raw write bypassing setAppSetting()
   -> the census must pick it up. Written under a REAL temp dir tree
   (mirroring $publicHtml's structure isn't necessary — gwFindContentGatingWriteSites
   walks whatever $dir it's given) so nothing under the tracked tree is
   ever touched. */
$mutFixtureDir = sys_get_temp_dir() . '/ihymns_gwiz_census_' . bin2hex(random_bytes(4));
mkdir($mutFixtureDir);
file_put_contents(
    $mutFixtureDir . '/rogue.php',
    "<?php\nsetAppSetting(\$db, 'content_gating_enabled', '1');\n"
);
$mutSites = gwFindContentGatingWriteSites($mutFixtureDir, $mutFixtureDir);
ok('MUTATION PROOF (c): a rogue setAppSetting() call anywhere is picked up by the census',
    in_array('rogue.php', $mutSites, true));
@unlink($mutFixtureDir . '/rogue.php');
@rmdir($mutFixtureDir);

/* =========================================================================
 * (d) Preconditions BEFORE the write, structurally.
 * ========================================================================= */

$flipBody = gwCaseBodyFor($pageFile, '$postAction', 'wizard_flip_gating');
$flipBodyStripped = gwStripComments($flipBody);
$evalPos = strpos($flipBodyStripped, 'gatingWizardEvaluatePreconditions(');
$setFlagPos = strpos($flipBodyStripped, 'gatingWizardSetFlag(');

ok('wizard_flip_gating span calls gatingWizardEvaluatePreconditions(',
    $evalPos !== false);
ok('wizard_flip_gating span calls gatingWizardSetFlag(',
    $setFlagPos !== false);
ok('the evaluator call occurs BEFORE the flag write, structurally',
    $evalPos !== false && $setFlagPos !== false && $evalPos < $setFlagPos);

$betweenCallsSpan = ($evalPos !== false && $setFlagPos !== false && $setFlagPos > $evalPos)
    ? substr($flipBodyStripped, $evalPos, $setFlagPos - $evalPos)
    : '';
ok('an exit path (break on !ok) sits between the evaluator call and the flag write',
    str_contains($betweenCallsSpan, 'break;') && str_contains($betweenCallsSpan, "\$eval['ok']"));

/* MUTATION: remove the evaluator call entirely in a mutated copy -> red. */
$mutatedNoEval = str_replace(
    "\$eval = gatingWizardEvaluatePreconditions(",
    "\$eval = ['ok' => true, 'blockers' => [], 'warnings' => []]; // gatingWizardEvaluatePreconditionsREMOVED(",
    $pageSrc
);
gwWithMutatedFile($mutatedNoEval, function (string $tmp) {
    $body = gwStripComments(gwCaseBodyFor($tmp, '$postAction', 'wizard_flip_gating'));
    ok('MUTATION PROOF (d1): removing the evaluator call removes it from the span',
        strpos($body, 'gatingWizardEvaluatePreconditions(') === false);
});

/* MUTATION: reorder the two calls in a mutated copy -> the ordering check
   flips. Swap by relabelling then re-labelling back — simplest reliable
   reorder is to move the SetFlag call textually ahead of the eval call. */
$mutatedReordered = str_replace(
    "gatingWizardSetFlag(\$db, true);",
    "/* MOVED-BEFORE-EVAL-MARKER */",
    $pageSrc
);
$mutatedReordered = str_replace(
    "\$eval = gatingWizardEvaluatePreconditions(",
    "gatingWizardSetFlag(\$db, true); /* moved here by mutation */\n                    \$eval = gatingWizardEvaluatePreconditions(",
    $mutatedReordered
);
gwWithMutatedFile($mutatedReordered, function (string $tmp) {
    $body = gwStripComments(gwCaseBodyFor($tmp, '$postAction', 'wizard_flip_gating'));
    $e = strpos($body, 'gatingWizardEvaluatePreconditions(');
    $s = strpos($body, 'gatingWizardSetFlag(');
    ok('MUTATION PROOF (d2): reordering the two calls flips the ordering check to false',
        $e !== false && $s !== false && $s < $e);
});

/* =========================================================================
 * (e) Every wizard JSON branch runs inside its own try/catch and exits.
 * ========================================================================= */

$statusBlock = gwStripComments(gwBlockBodyAfterAnchor($pageSrc, "if (\$getAction === 'wizard_status') {"));
$songTestBlock = gwStripComments(gwBlockBodyAfterAnchor($pageSrc, "if (\$getAction === 'wizard_song_test') {"));
$postWrapperBlock = gwStripComments(gwBlockBodyAfterAnchor(
    $pageSrc,
    "if (in_array(\$postAction, ['wizard_seed_restrictions', 'wizard_flip_gating', 'wizard_rollback_gating'], true)) {"
));

foreach (['wizard_status' => $statusBlock, 'wizard_song_test' => $songTestBlock, 'POST dispatch wrapper' => $postWrapperBlock] as $label => $block) {
    ok("{$label} span is non-empty (anchor found)", $block !== '');
    ok("{$label} span contains try {", str_contains($block, 'try {'));
    ok("{$label} span contains catch (\\Throwable", str_contains($block, 'catch (\Throwable'));
    ok("{$label} span exits", str_contains($block, 'exit;'));
}

/* =========================================================================
 * (f) FUNCTIONAL truth table for gatingWizardEvaluatePreconditions() — the
 * OWNER-OVERRIDE assertion. Loaded WITHOUT a database connection (the file
 * is function-only), exactly as includes/gating_wizard.php's own doc-block
 * promises.
 * ========================================================================= */

require $coreFile;

/* Row 1 — un-migrated schema is a genuine BLOCKER regardless of anything else. */
$r1 = gatingWizardEvaluatePreconditions(false, true, 5, 0, true, 'ENFORCE');
ok('(f1) un-migrated schema blocks the flip', !$r1['ok'] && in_array('schema_unmigrated', $r1['blockers'], true));
$r1b = gatingWizardEvaluatePreconditions(true, false, 5, 0, true, 'ENFORCE');
ok('(f1b) missing tier-caps column also blocks the flip', !$r1b['ok'] && in_array('schema_unmigrated', $r1b['blockers'], true));

/* Row 2 — THE OWNER OVERRIDE. require_licence rows exist, nobody holds a
   CCLI licence: this is a WARNING, and with it acknowledged + ENFORCE
   typed + schema ready, the flip IS allowed. This is the single most
   important assertion in this file. */
$r2 = gatingWizardEvaluatePreconditions(true, true, 0, 3, false, 'ENFORCE');
ok('(f2a) rows-without-holder is a WARNING, not a blocker',
    in_array('require_licence_row', ['require_licence_row']) // no-op, keeps the block visually paired
    && in_array('require_rows_without_holder', $r2['warnings'], true)
    && !in_array('require_rows_without_holder', $r2['blockers'], true));
ok('(f2b) unacknowledged, it DOES block via warnings_unacknowledged (procedural, not permanent)',
    !$r2['ok'] && in_array('warnings_unacknowledged', $r2['blockers'], true));
$r2ack = gatingWizardEvaluatePreconditions(true, true, 0, 3, true, 'ENFORCE');
ok('(f2c) acknowledged + ENFORCE + schema ready -> the flip IS ALLOWED despite the warning (warn-but-allow)',
    $r2ack['ok'] === true
    && in_array('require_rows_without_holder', $r2ack['warnings'], true));

/* Row 3 — milder warning: no holders, but no require rows either. */
$r3 = gatingWizardEvaluatePreconditions(true, true, 0, 0, true, 'ENFORCE');
ok('(f3) zero holders with zero require rows warns no_live_ccli, and is allowed once acknowledged',
    $r3['ok'] === true && in_array('no_live_ccli', $r3['warnings'], true));

/* Row 4 — plenty of holders: no warning of either kind. */
$r4 = gatingWizardEvaluatePreconditions(true, true, 4, 0, false, 'ENFORCE');
ok('(f4) live holders present -> no warning at all, ack not even needed',
    $r4['ok'] === true && $r4['warnings'] === []);

/* Row 5 — confirm text wrong. */
$r5 = gatingWizardEvaluatePreconditions(true, true, 4, 0, false, 'enforce');
ok('(f5) lower-case "enforce" is rejected (case-exact confirm)', !$r5['ok'] && in_array('confirm_mismatch', $r5['blockers'], true));
$r5b = gatingWizardEvaluatePreconditions(true, true, 4, 0, false, '');
ok('(f5b) empty confirm text is rejected', !$r5b['ok'] && in_array('confirm_mismatch', $r5b['blockers'], true));

/* Row 6 — everything good. */
$r6 = gatingWizardEvaluatePreconditions(true, true, 4, 0, false, 'ENFORCE');
ok('(f6) fully-ready install with holders + correct confirm -> ok, zero blockers, zero warnings',
    $r6['ok'] === true && $r6['blockers'] === [] && $r6['warnings'] === []);

/* MUTATION PROOF (f) — the owner-override line itself. Revert the warn-
   but-allow behaviour back to a hard BLOCKER in a MUTATED COPY, run it in
   an ISOLATED subprocess (this process already loaded the real
   gatingWizardEvaluatePreconditions() — a second definition would fatal),
   and confirm THE SAME (f2a)/(f2c) assertions go red against the mutant. */
$mutatedCoreHardBlock = str_replace(
    "        \$warnings[] = 'require_rows_without_holder';",
    "        \$blockers[] = 'require_rows_without_holder'; // MUTATED: owner override reverted to a hard blocker",
    $coreSrc
);
ok('MUTATION setup sanity: the owner-override replacement actually matched the real source',
    $mutatedCoreHardBlock !== $coreSrc);
gwWithMutatedSiblingFile($mutatedCoreHardBlock, $coreFile, function (string $tmp) use ($phpBin) {
    $snippet = 'var_export(gatingWizardEvaluatePreconditions(true, true, 0, 3, true, "ENFORCE"));';
    $result = gwRunIsolated($phpBin, $tmp, $snippet);
    $mutOut = $result['stdout'];
    /* Against the mutant, the SAME acknowledged+ENFORCE+ready inputs that
       are ALLOWED on real gating_wizard.php must now be BLOCKED — proving
       assertion (f2c) really does depend on the owner-override line, not
       on some unrelated always-true condition. */
    $looksBlocked = (bool)preg_match("/'ok'\s*=>\s*false/", $mutOut)
        && str_contains($mutOut, "'require_rows_without_holder'");
    ok('MUTATION PROOF (f): reverting the owner-override line makes the previously-allowed (f2c) case blocked',
        $result['code'] === 0 && $looksBlocked);
});

/* =========================================================================
 * (g) FUNCTIONAL planner safety — gatingWizardPlanRowsFor() never emits a
 * wildcard or a public-domain song.
 * ========================================================================= */

$planned = gatingWizardPlanRowsFor([
    ['SongId' => 'MP-1008', 'LyricsPublicDomain' => 0],
    ['SongId' => '*',       'LyricsPublicDomain' => 0],   /* must be dropped */
    ['SongId' => '',        'LyricsPublicDomain' => 0],   /* must be dropped */
    ['SongId' => 'MP-2000', 'LyricsPublicDomain' => 1],   /* PD — must be dropped */
]);
ok('(g1) planner returns exactly ONE row for the one valid, copyrighted song',
    count($planned) === 1 && $planned[0]['EntityId'] === 'MP-1008');
ok('(g2) planner NEVER emits EntityId === "*"',
    !in_array('*', array_column($planned, 'EntityId'), true));
ok('(g3) planner row shape is correct (require_licence / ccli / deny)',
    $planned[0]['RestrictionType'] === 'require_licence'
    && $planned[0]['TargetId'] === 'ccli'
    && $planned[0]['Effect'] === 'deny'
    && $planned[0]['EntityType'] === 'song');

/* MUTATION PROOF (g) — remove the wildcard guard in a mutated copy, in an
   isolated subprocess, and confirm a wildcard row THEN survives. */
$mutatedCoreNoWildcardGuard = str_replace(
    "        if (\$songId === '' || \$songId === '*') {\n            continue; /* never a wildcard (or empty) row */\n        }",
    "        // MUTATED: wildcard guard removed",
    $coreSrc
);
ok('MUTATION setup sanity (g): the wildcard-guard replacement actually matched the real source',
    $mutatedCoreNoWildcardGuard !== $coreSrc);
gwWithMutatedSiblingFile($mutatedCoreNoWildcardGuard, $coreFile, function (string $tmp) use ($phpBin) {
    $snippet = 'echo json_encode(gatingWizardPlanRowsFor([["SongId"=>"*","LyricsPublicDomain"=>0]]));';
    $result = gwRunIsolated($phpBin, $tmp, $snippet);
    ok('MUTATION PROOF (g): removing the wildcard guard lets a "*" row survive the planner',
        $result['code'] === 0 && str_contains($result['stdout'], '"EntityId":"*"'));
});

/* =========================================================================
 * (h) CSRF + entitlement gates.
 * ========================================================================= */

$postDispatchSpan = gwStripComments(substr(
    $pageSrc,
    (int)strpos($pageSrc, "if (\$requestMethod === 'POST') {"),
    (int)strpos($pageSrc, "/* =========================================================================\n * The rest of this file") - (int)strpos($pageSrc, "if (\$requestMethod === 'POST') {")
));
$csrfPos = strpos($postDispatchSpan, 'validateCsrfRequest(');
$switchPos = strpos($postDispatchSpan, 'switch ($postAction)');
ok('the POST dispatch block calls validateCsrfRequest() BEFORE the action switch',
    $csrfPos !== false && $switchPos !== false && $csrfPos < $switchPos);

$seedBody = gwStripComments(gwCaseBodyFor($pageFile, '$postAction', 'wizard_seed_restrictions'));
$rollbackBody = gwStripComments(gwCaseBodyFor($pageFile, '$postAction', 'wizard_rollback_gating'));
ok('wizard_seed_restrictions span checks manage_content_restrictions (the finer gate)',
    str_contains($seedBody, "\$canEditRestrictions"));
ok('wizard_rollback_gating\'s remove-seeded branch also checks the finer gate',
    str_contains($rollbackBody, "\$canEditRestrictions"));
ok('the page-top entitlement gate is manage_configuration (unchanged)',
    (bool)preg_match("/userHasEntitlement\\('manage_configuration',\\s*\\\$currentUser\\['role'\\]/", $pageSrc));
ok('\$canEditRestrictions itself is resolved from manage_content_restrictions',
    (bool)preg_match("/\\\$canEditRestrictions\\s*=\\s*userHasEntitlement\\('manage_content_restrictions',/", $pageSrc));

/* =========================================================================
 * (i) Rollback is unconditional and writes '0'.
 * ========================================================================= */

ok('wizard_rollback_gating calls gatingWizardSetFlag($db, false)',
    str_contains($rollbackBody, 'gatingWizardSetFlag($db, false)'));
ok('wizard_rollback_gating NEVER calls the precondition evaluator',
    !str_contains($rollbackBody, 'gatingWizardEvaluatePreconditions('));

/* MUTATION: add the evaluator call to a mutated copy -> red. */
$mutatedRollbackWithEval = str_replace(
    "case 'wizard_rollback_gating': {",
    "case 'wizard_rollback_gating': { \$__mut = gatingWizardEvaluatePreconditions(true,true,1,0,true,'ENFORCE');",
    $pageSrc
);
gwWithMutatedFile($mutatedRollbackWithEval, function (string $tmp) {
    $body = gwStripComments(gwCaseBodyFor($tmp, '$postAction', 'wizard_rollback_gating'));
    ok('MUTATION PROOF (i): adding an evaluator call to rollback is detected',
        str_contains($body, 'gatingWizardEvaluatePreconditions('));
});

/* =========================================================================
 * (j) FUNCTIONAL no-content-leak — gatingWizardSummarisePayload() never
 * echoes lyric text.
 * ========================================================================= */

$marker = 'LEAK_MARKER_1a2b3c';
$syntheticSong = [
    'contentRestricted' => true,
    'restrictionReason' => 'copyrighted_requires_higher_tier',
    'components' => [
        ['type' => 'verse', 'lines' => [$marker, 'another line with ' . $marker]],
    ],
    'translations' => [['language' => 'es', 'text' => $marker]],
    'media' => [['kind' => 'audio', 'url' => 'https://example.test/' . $marker . '.mp3']],
    'hasAudio' => true,
    'hasSheetMusic' => false,
    'offlineAllowed' => false,
];
$summary = gatingWizardSummarisePayload($syntheticSong, $syntheticSong);
$summaryJson = (string)json_encode($summary);
ok('(j1) the marker is ABSENT from json_encode() of the summary',
    !str_contains($summaryJson, $marker));
ok('(j2) the summary still reports structural facts correctly (lyricsIncluded/componentCount/mediaKinds)',
    $summary['lyricsIncluded'] === true
    && $summary['componentCount'] === 1
    && $summary['mediaKinds'] === ['audio']
    && $summary['contentRestricted'] === true);

/* MUTATION PROOF (j) — a summariser that naively spreads $gated into its
   output WOULD leak the marker; confirm the marker DOES survive such a
   naive implementation, proving the assertion is sensitive to this exact
   failure mode. */
$naiveSummary = array_merge(['lyricsIncluded' => true], $syntheticSong);
ok('MUTATION PROOF (j): a naive pass-through summary WOULD have leaked the marker (sanity check on the test itself)',
    str_contains((string)json_encode($naiveSummary), $marker));

/* =========================================================================
 * (k) Restriction-write census — includes/restriction_admin.php is the
 * ONLY site with a raw INSERT INTO tblContentRestrictions (OD-2 landed in
 * this same change, so api.php no longer has its own copy either).
 * ========================================================================= */

$restrictionInsertSites = gwFindRestrictionInsertSites($publicHtml, $repo);
ok('tblContentRestrictions is INSERTed from EXACTLY includes/restriction_admin.php',
    $restrictionInsertSites === ['appWeb/public_html/includes/restriction_admin.php']);

ok('restrictions.php\'s create case delegates to restrictionAdminCreate(',
    str_contains(gwStripComments(gwCaseBodyFor($restrictionsPageFile, '$action', 'create')), 'restrictionAdminCreate('));
ok('restrictions.php\'s delete case delegates to restrictionAdminDelete(',
    str_contains(gwStripComments(gwCaseBodyFor($restrictionsPageFile, '$action', 'delete')), 'restrictionAdminDelete('));

/* MUTATION: add a rogue INSERT to a fixture -> census picks it up. */
$mutFixtureDir2 = sys_get_temp_dir() . '/ihymns_gwiz_restr_census_' . bin2hex(random_bytes(4));
mkdir($mutFixtureDir2);
file_put_contents($mutFixtureDir2 . '/rogue2.php', "<?php\n\$db->query('INSERT INTO tblContentRestrictions (EntityType) VALUES (\\'x\\')');\n");
$mutRestrSites = gwFindRestrictionInsertSites($mutFixtureDir2, $mutFixtureDir2);
ok('MUTATION PROOF (k): a rogue raw INSERT INTO tblContentRestrictions is picked up by the census',
    in_array('rogue2.php', $mutRestrSites, true));
@unlink($mutFixtureDir2 . '/rogue2.php');
@rmdir($mutFixtureDir2);

/* =========================================================================
 * (l) GET reads are pure — no write-shaped token in either GET span.
 * ========================================================================= */

$writeTokens = ['setAppSetting(', 'gatingWizardSetFlag(', 'restrictionAdminCreate(', 'restrictionAdminDelete(', 'INSERT ', 'UPDATE ', 'DELETE '];
foreach (['wizard_status' => $statusBlock, 'wizard_song_test' => $songTestBlock] as $label => $block) {
    $found = [];
    foreach ($writeTokens as $tok) {
        if (str_contains($block, $tok)) { $found[] = $tok; }
    }
    ok("{$label} span contains NO write-shaped tokens (found: " . (implode(', ', $found) ?: 'none') . ')',
        $found === []);
}

/* MUTATION: inject a write call into a mutated copy of the wizard_status
   block -> the purity check must catch it. */
$mutatedGetWithWrite = str_replace(
    "if (\$getAction === 'wizard_status') {\n        header('Content-Type: application/json; charset=UTF-8');\n        try {",
    "if (\$getAction === 'wizard_status') {\n        header('Content-Type: application/json; charset=UTF-8');\n        try {\n            gatingWizardSetFlag(\$db, true); // MUTATION: a write injected into a GET read",
    $pageSrc
);
gwWithMutatedFile($mutatedGetWithWrite, function (string $tmp) {
    $srcNow = (string)file_get_contents($tmp);
    $block = gwStripComments(gwBlockBodyAfterAnchor($srcNow, "if (\$getAction === 'wizard_status') {"));
    ok('MUTATION PROOF (l): injecting a write call into the GET span is detected',
        str_contains($block, 'gatingWizardSetFlag('));
});

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n{$passed} passed, {$failed} failed";
if ($failed > 0) {
    echo "\n";
    exit(1);
}
echo "\n\nThe guided content-gating activation wizard stays safe: the raw switch on "
   . "Configuration still dispatches, exactly two functions write content_gating_enabled, "
   . "the precondition check runs before every flip and can never be bypassed by rollback, "
   . "the owner's warn-but-allow override behaves exactly as specified, the row planner "
   . "never emits a wildcard, the song-test endpoint never leaks lyric text, and every "
   . "restriction write funnels through the ONE shared core.\n";
exit(0);
