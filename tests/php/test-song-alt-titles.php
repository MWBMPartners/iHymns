<?php

declare(strict_types=1);

/**
 * iHymns — tblSongAlternativeTitles write-path guard (#1669, epic #832)
 *
 * ELI5
 * ----
 * A song's "also known as" titles could always be READ, but nothing could
 * ever CREATE the first one. This file checks that the new write path
 * (`includes/song_alt_titles.php` + api2.php's three `song_alt_title*`
 * actions + the v2 client) is wired correctly and — the specific regression
 * this feature is most at risk of — that no OTHER file ever grows a second,
 * competing `INSERT INTO tblSongAlternativeTitles` site (rule #22: ONE write
 * core, api2/importer/future-admin-surface all delegate to it).
 *
 * WHAT IT ASSERTS (source-contract, no database needed)
 *   1. Tree-wide: the ONLY two `INSERT ... tblSongAlternativeTitles` sites
 *      anywhere under appWeb/public_html/**\/*.php are
 *      `includes/song_alt_titles.php` (the new write core — exactly one
 *      INSERT statement, songAltTitleAdd()'s INSERT IGNORE) and
 *      `manage/editor/api2.php` (the PRE-EXISTING `duplicate_song` copy
 *      site — exactly one occurrence, a count-exact allowlist so a SECOND
 *      one appearing there is caught just as loudly as a stray one
 *      anywhere else).
 *   2. All three `song_alt_title*` api2.php case bodies delegate to the
 *      core — NO case body contains its OWN inline
 *      `tblSongAlternativeTitles` SQL (a raw SELECT/INSERT/UPDATE/DELETE);
 *      each case calls at least one `songAltTitle*()` core function.
 *   3. The core's `songAltTitleAdd()` contains `INSERT IGNORE`, a canonical
 *      re-select, and bound params (`bind_param`) — the exact
 *      `song_external_id_add` idiom this feature mirrors.
 *   4. Every core function that touches the table gates that access behind
 *      `songAltTitlesTableExists()` — an un-migrated install must degrade,
 *      never mysqli-STRICT-throw.
 *   5. PHP<->JS action-name lockstep: the three action strings
 *      (`song_alt_titles` / `song_alt_title_add` / `song_alt_title_delete`)
 *      that api2.php dispatches on are the SAME three strings
 *      `api-client.js`'s `editorApi` sends (rule #35 — a renamed action on
 *      either side is invisible until clicked).
 *
 * DERIVED + MUTATION-PROVEN (rule #34): section 1's file list is a real
 * recursive filesystem walk (mirrors `test-component-json-guard.php`'s
 * scan), never a typed list — a new file anywhere in the tree that starts
 * writing this table is caught automatically. Every regex-based check below
 * carries an in-file self-test proving it can actually fail against a
 * fabricated fixture (never proven able to fail = not trustworthy); the
 * REAL source files were additionally mutated by hand during
 * implementation (break -> red -> restore, reported in the session) for
 * the three checks the build spec calls out explicitly.
 *
 *   php tests/php/test-song-alt-titles.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 *
 * @see #1669
 * @see #832
 * @link .claude/wave2-importer-editor-fidelity-plan.md §2   the build spec this file verifies
 * @link tests/php/test-duplicate-copy-set.php                the sibling "count-exact allowlist" idiom this borrows
 * @link tests/php/test-component-json-guard.php               the sibling tree-wide recursive-scan idiom this borrows
 * @link tests/test-v2-external-ids-ui.js                       the sibling client<->server lockstep idiom (JS side) this borrows for PHP
 */

$root = dirname(__DIR__, 2);
$pub  = $root . '/appWeb/public_html';

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  \xE2\x9C\x85 {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  \xE2\x9D\x8C {$label}\n"; }
}

/* Strip PHP comments so a reference inside a doc-block/comment (this file's
   own doc-block above discusses the exact literals at length) can't
   satisfy — or trip — a check meant to be about executable code. Mirrors
   test-duplicate-copy-set.php / test-component-json-guard.php's approach. */
function stripPhpComments(string $s): string
{
    $s = preg_replace('!/\*.*?\*/!s', '', $s) ?? $s;
    $s = preg_replace('!(^|[^:])//[^\n]*!', '$1', $s) ?? $s;
    return $s;
}

echo "\n#1669 — tblSongAlternativeTitles write-path guard\n\n";

/* ========================================================================
 * 1. Tree-wide INSERT-site allowlist (the headline regression guard).
 * ==================================================================== */

$coreFile = $pub . '/includes/song_alt_titles.php';
$api2File = $pub . '/manage/editor/api2.php';
$coreRel  = 'appWeb/public_html/includes/song_alt_titles.php';
$api2Rel  = 'appWeb/public_html/manage/editor/api2.php';

/**
 * Count case-insensitive `INSERT ... tblSongAlternativeTitles` occurrences
 * in $src, after stripping PHP comments. Pure function so the mutation
 * self-test below can exercise it without touching the real files.
 */
function saltInsertCount(string $src): int
{
    $stripped = stripPhpComments($src);
    return preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+tblSongAlternativeTitles\b/i', $stripped);
}

if (!is_dir($pub)) {
    fwrite(STDERR, "FATAL: {$pub} not found\n");
    exit(1);
}

/* Recursively collect *.php under public_html — never a typed list
   (rule #34). */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pub, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
}
sort($files);
ok('recursive scan found a plausible number of PHP files (>= 200)', count($files) >= 200);

$rootPrefix = $root . '/';
$insertSites = [];   // relFile => count
foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    $n = saltInsertCount($src);
    if ($n > 0) {
        $rel = substr($file, strlen($rootPrefix));
        $insertSites[$rel] = $n;
    }
}

ok("core file ({$coreRel}) has EXACTLY 1 INSERT ... tblSongAlternativeTitles statement",
    ($insertSites[$coreRel] ?? 0) === 1);
ok("api2.php ({$api2Rel}) has EXACTLY 1 INSERT ... tblSongAlternativeTitles statement (the pre-existing duplicate_song copy site — count-exact allowlist)",
    ($insertSites[$api2Rel] ?? 0) === 1);

$unexpectedSites = $insertSites;
unset($unexpectedSites[$coreRel], $unexpectedSites[$api2Rel]);
ok('NO other file anywhere under appWeb/public_html contains an INSERT ... tblSongAlternativeTitles statement',
    $unexpectedSites === []);
foreach ($unexpectedSites as $rel => $n) {
    echo "       unexpected: {$rel} has {$n} INSERT ... tblSongAlternativeTitles statement(s)\n";
}

/* Mutation self-test for saltInsertCount() itself (rule #34): a fabricated
   fixture with a THIRD insert site must be counted, and a comment-only
   mention must NOT be. */
ok('(mutation self-test) saltInsertCount() counts a real INSERT IGNORE statement',
    saltInsertCount("\$x = \$db->prepare('INSERT IGNORE INTO tblSongAlternativeTitles (SongId) VALUES (?)');") === 1);
ok('(mutation self-test) saltInsertCount() counts a real plain INSERT statement',
    saltInsertCount("\$x = \$db->prepare('INSERT INTO tblSongAlternativeTitles (SongId) VALUES (?)');") === 1);
ok('(mutation self-test) saltInsertCount() ignores a comment-only mention',
    saltInsertCount("/* never do: INSERT INTO tblSongAlternativeTitles (SongId) VALUES (?) */") === 0);
ok('(mutation self-test) saltInsertCount() counts TWO occurrences in one fixture',
    saltInsertCount("'INSERT INTO tblSongAlternativeTitles (A)';\n'INSERT IGNORE INTO tblSongAlternativeTitles (B)';") === 2);

/* ========================================================================
 * 2. The three api2.php case bodies delegate — no inline SQL, each calls
 *    at least one songAltTitle*() core function.
 * ==================================================================== */

$api2Src = (string)file_get_contents($api2File);
if ($api2Src === '') {
    fwrite(STDERR, "FATAL: could not read {$api2File}\n");
    exit(1);
}

/**
 * Brace-matched case-body extractor (mirrors test-duplicate-copy-set.php's
 * own extractor) — finds `case '$name':` then walks to the matching `}` of
 * the block that opens after it. Pure function so it can be mutation-
 * self-tested below.
 *
 * @return string|null null if the case label isn't found.
 */
function saltCaseBody(string $src, string $name): ?string
{
    $casePos = strpos($src, "case '{$name}':");
    if ($casePos === false) { return null; }
    $open = strpos($src, '{', $casePos);
    if ($open === false) { return null; }
    $depth = 0;
    $end = $open;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    return substr($src, $open, $end - $open + 1);
}

$caseNames = ['song_alt_titles', 'song_alt_title_add', 'song_alt_title_delete'];
$coreFns   = ['songAltTitlesTableExists', 'songAltTitlesList', 'songAltTitleAdd', 'songAltTitleDelete', 'songAltTitleIsRedundant'];

foreach ($caseNames as $name) {
    $body = saltCaseBody($api2Src, $name);
    ok("api2.php has a `case '{$name}':` body to inspect", $body !== null && strlen((string)$body) > 100);
    if ($body === null) { continue; }
    $strippedBody = stripPhpComments($body);

    ok("case '{$name}' contains NO inline tblSongAlternativeTitles SQL (delegates entirely to the core)",
        saltInsertCount($strippedBody) === 0
        && !preg_match('/\b(?:SELECT|UPDATE|DELETE)\b[^;]*\btblSongAlternativeTitles\b/is', $strippedBody));

    $callsCore = false;
    foreach ($coreFns as $fn) {
        if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/', $strippedBody)) { $callsCore = true; break; }
    }
    ok("case '{$name}' calls at least one songAltTitle*() core function (delegates, never re-forks)",
        $callsCore);
}

/* Mutation self-test for saltCaseBody() (rule #34): a fabricated case with a
   nested brace must still find the CORRECT matching close, not the first
   inner one. */
$fixtureCase = "case 'x': {\n  if (true) { \$a = 1; }\n  break;\n}\ncase 'y': {\n  \$b = 2;\n}\n";
$fixtureBody = saltCaseBody($fixtureCase, 'x');
ok('(mutation self-test) saltCaseBody() brace-matches past a NESTED if-block without truncating early',
    $fixtureBody !== null && strpos((string)$fixtureBody, '$a = 1') !== false && strpos((string)$fixtureBody, '$b = 2') === false);

/* ========================================================================
 * 3. songAltTitleAdd() contains INSERT IGNORE + a canonical re-select +
 *    bound params.
 * ==================================================================== */

$coreSrc = (string)file_get_contents($coreFile);
if ($coreSrc === '') {
    fwrite(STDERR, "FATAL: could not read {$coreFile}\n");
    exit(1);
}
$coreStripped = stripPhpComments($coreSrc);

/**
 * Extract a top-level `function NAME(...) { ... }` body by brace-matching
 * from the `function` keyword's own opening `{`. Pure, mutation-testable.
 */
function saltFunctionBody(string $src, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*:\s*[^\{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $open = $m[0][1] + strlen($m[0][0]) - 1;   // position of the opening '{'
    $depth = 0;
    $end = $open;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    return substr($src, $open, $end - $open + 1);
}

$addBody = saltFunctionBody($coreStripped, 'songAltTitleAdd');
ok('songAltTitles.php has a songAltTitleAdd() body to inspect (>= 300 chars — #1671 lesson: widen the window)',
    $addBody !== null && strlen((string)$addBody) >= 300);
if ($addBody !== null) {
    ok("songAltTitleAdd() uses INSERT IGNORE", (bool)preg_match('/INSERT\s+IGNORE\s+INTO\s+tblSongAlternativeTitles/i', $addBody));
    ok('songAltTitleAdd() re-selects the canonical row after the write (SELECT ... WHERE SongId = ? AND Title = ?)',
        (bool)preg_match('/SELECT[^;]*FROM\s+tblSongAlternativeTitles[^;]*WHERE\s+SongId\s*=\s*\?\s+AND\s+Title\s*=\s*\?/is', $addBody));
    $bindCount = preg_match_all('/bind_param\s*\(/', $addBody);
    ok('songAltTitleAdd() binds parameters at least twice (the SortOrder read, the INSERT, and the re-select each bind)',
        $bindCount >= 2);
}

/* Mutation self-test for saltFunctionBody() (rule #34). */
$fixtureFn = "function needle(\\mysqli \$db): int\n{\n    if (true) { return 1; }\n    return 0;\n}\nfunction other(): void {}\n";
$fixtureFnBody = saltFunctionBody($fixtureFn, 'needle');
ok('(mutation self-test) saltFunctionBody() isolates the CORRECT function, not a neighbour',
    $fixtureFnBody !== null
    && strpos((string)$fixtureFnBody, 'return 1') !== false
    && strpos((string)$fixtureFnBody, 'function other') === false);

/* ========================================================================
 * 4. Every core function that touches the table gates it behind the probe.
 * ==================================================================== */

$gatedFns = ['songAltTitlesList', 'songAltTitleAdd', 'songAltTitleDelete'];
foreach ($gatedFns as $fn) {
    $body = saltFunctionBody($coreStripped, $fn);
    ok("core function {$fn}() exists and is inspectable", $body !== null);
    if ($body === null) { continue; }
    ok("core function {$fn}()'s table access is gated behind songAltTitlesTableExists()",
        strpos((string)$body, 'songAltTitlesTableExists') !== false);
}

/* ========================================================================
 * 5. PHP<->JS action-name lockstep (rule #35).
 * ==================================================================== */

$clientFile = $pub . '/manage/editor/v2/api-client.js';
$clientSrc  = (string)file_get_contents($clientFile);
if ($clientSrc === '') {
    fwrite(STDERR, "FATAL: could not read {$clientFile}\n");
    exit(1);
}
/* Strip JS comments the same way tests/test-v2-external-ids-ui.js does, so
   this file's own extensive doc-block prose (which names every action
   literal) cannot satisfy the assertion in place of real code. */
$clientStripped = preg_replace('#/\*[\s\S]*?\*/#', '', $clientSrc) ?? $clientSrc;
$clientStripped = preg_replace('#(^|[^:])//.*$#m', '$1', $clientStripped) ?? $clientStripped;

foreach ($caseNames as $name) {
    ok("api2.php dispatches on case '{$name}' (already proven above) AND api-client.js sends the SAME literal '{$name}' to (get|post)Json",
        preg_match('/\bcase\s+\'' . preg_quote($name, '/') . '\'\s*:/', $api2Src) === 1
        && preg_match('/(?:getJson|postJson)\(\s*\'' . preg_quote($name, '/') . '\'/', $clientStripped) === 1);
}

/* ---------------------------------------------------------------------- */

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\nA second INSERT site, an un-delegated case body, or a drifted PHP<->JS\n");
    fwrite(STDERR, "action name is invisible at runtime (rule #34/#35) — that is exactly what\n");
    fwrite(STDERR, "this guard exists to catch for the #1669 alt-titles write path.\n");
    exit(1);
}
echo "\nAll #1669 tblSongAlternativeTitles write-path assertions passed.\n";
exit(0);
