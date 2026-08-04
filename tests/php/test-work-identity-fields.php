<?php

declare(strict_types=1);

/**
 * iHymns — Work identity fields guard (#1741 P4b)
 *
 * ELI5
 * ----
 * P4b added nine "extra" columns to `tblWorks` a work's public page and its
 * admin editor are both supposed to know about (Ccli, Bowi, Subtitle,
 * Disambiguation, TuneName, TuneId, FirstPublishedYear, CopyrightYears,
 * CopyrightHolder). This test makes sure that promise holds by DERIVING the
 * column list from `schema.sql` itself (rule #34 — never a hand-typed list
 * that can silently drift from the real schema) and then checking each one
 * is actually referenced by `SongData::getWork()` and `manage/works.php`.
 * It also checks the handful of other P4b contracts named in the build spec:
 * the identifier chip hrefs + `data-navigate`, the a11y landmarks, the
 * no-inline-regex mechanism (`mediaIdentifierWorkValidate()`), the
 * TuneName/TuneId lockstep funnel (`tuneFindOrCreateByName()`), and the
 * shared external-links partial extraction.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * A hand-typed `['Ccli','Bowi', …]` list here would agree with itself by
 * construction and could never catch a column silently dropped from
 * `getWork()` or `manage/works.php` after this test was written. Instead
 * this file slices `tblWorks`'s `CREATE TABLE` block straight out of
 * `appWeb/.sql/schema.sql` and extracts every column whose `COMMENT` text
 * carries the `#1741 P1` or `#1741 D5` doctag — the SAME doctag convention
 * rule #19's schema-coverage scanner already relies on. A future column
 * added to this family (or one silently removed) changes what this test
 * expects, automatically, with no maintenance here.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-media-identifiers.php):
 *   Each core checking function below (`twifTaggedColumnsInBlock()`,
 *   `twifMissing()`, `twifAnchorHasNav()`) is exercised in BOTH directions —
 *   proven to detect a thing that's there, and proven to detect a thing
 *   that's missing — against small in-memory fixtures, not the real files.
 *   A guard that has never been proven able to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint
 * step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-work-identity-fields.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/SongData.php::getWork()
 * @see appWeb/public_html/includes/pages/work.php
 * @see appWeb/public_html/manage/works.php
 * @see appWeb/public_html/includes/media_identifiers.php
 * @see appWeb/public_html/includes/tune_helpers.php
 * @see appWeb/public_html/includes/partials/external-links-panel.php
 * @see .claude/catalogue-1741-P4-plan.md §2.6
 */

$repoRoot = dirname(__DIR__, 2);

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals. Both the real assertions
 * and the mutation self-tests below call these SAME functions, so a
 * passing mutation test is evidence about the checking logic itself, not a
 * parallel checker that only ever agrees with itself.
 * ========================================================================= */

/**
 * Slice a `CREATE TABLE [IF NOT EXISTS] $table (...) ENGINE=` block out of
 * a schema.sql-shaped string. Returns the column-declaration body (between
 * the outer parens), or null when the table isn't found at all.
 */
function twifSliceCreateTable(string $schemaSql, string $table): ?string
{
    if (!preg_match(
        '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*ENGINE\s*=/is',
        $schemaSql,
        $m
    )) {
        return null;
    }
    return $m[1];
}

/**
 * Within a CREATE TABLE column-declaration body, find every column whose
 * `COMMENT '...'` text contains at least one of the given tag strings.
 * Each column declaration is expected on its own physical line (true of
 * every #1741 P1/D5 column in this schema — verified by direct read), so
 * this is a per-line regex, not a full SQL parser.
 *
 * @param string   $block column-declaration body (twifSliceCreateTable()'s output)
 * @param list<string> $tags e.g. ['#1741 P1', '#1741 D5']
 * @return list<string> column names, in declaration order
 */
function twifTaggedColumnsInBlock(string $block, array $tags): array
{
    $cols = [];
    if (!preg_match_all(
        '/^[ \t]*([A-Za-z_][A-Za-z0-9_]*)[ \t]+[A-Za-z].*?COMMENT\s+\'((?:[^\'\\\\]|\\\\.|\'\')*)\'/mi',
        $block,
        $matches,
        PREG_SET_ORDER
    )) {
        return $cols;
    }
    foreach ($matches as $mm) {
        $col     = $mm[1];
        $comment = $mm[2];
        foreach ($tags as $tag) {
            if (strpos($comment, $tag) !== false) {
                $cols[] = $col;
                break;
            }
        }
    }
    return $cols;
}

/**
 * Which of $needles are NOT present as a literal substring of $haystack.
 *
 * @param string       $haystack
 * @param list<string> $needles
 * @return list<string> the missing subset (empty = all present)
 */
function twifMissing(string $haystack, array $needles): array
{
    $missing = [];
    foreach ($needles as $needle) {
        if (strpos($haystack, $needle) === false) {
            $missing[] = $needle;
        }
    }
    return $missing;
}

/**
 * Slice a named method's body out of a PHP class source: from `function
 * $name(` to the next `public|private|protected function ` declaration
 * (or end of file). Returns '' when the method name isn't found.
 *
 * Used for BOTH `getWork()` and `_worksExtraCols()` — the probe method is
 * physically declared BEFORE `getWork()` in SongData.php (it also backs
 * `_worksMap()`'s pre-existing sibling probe, `_hasWorksSchema()`), so a
 * mutation to `_worksExtraCols()`'s own IN-list would NOT show up inside
 * a `getWork()`-only slice — `getWork()` re-lists the same nine names
 * itself (for the SELECT-fragment builder) and separately keys off them
 * again (for the output-default logic), so a `getWork()`-only slice would
 * still read those names as literal text and stay wrong-but-green even
 * after the PROBE that actually gates them lost one. Checking both
 * methods' own bodies independently is what closes that gap — see
 * mutation-test (a) in the build spec, which is exactly this scenario.
 */
function twifSliceMethod(string $src, string $name): string
{
    if (!preg_match(
        '/function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n\s*(?:public|private|protected)\s+function\s|\z)/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * True when $src contains an occurrence of $hrefFragment with a
 * `data-navigate="$navValue"` attribute within $window characters either
 * side — i.e. plausibly the SAME anchor tag. Deliberately a bounded
 * window rather than a single regex anchored on "no `>`" (rule #34's
 * explicit lesson: that truncates at an interpolated `?>` and similar
 * PHP-in-HTML artefacts) — bounded generously (~300 chars default,
 * matching the build spec's own guidance) rather than tightly, so
 * reordering attributes or wrapping the anchor across lines doesn't
 * false-negative.
 */
function twifAnchorHasNav(string $src, string $hrefFragment, string $navValue, int $window = 300): bool
{
    $navNeedle = 'data-navigate="' . $navValue . '"';
    $offset    = 0;
    while (($pos = strpos($src, $hrefFragment, $offset)) !== false) {
        $start = max(0, $pos - $window);
        $len   = $window + strlen($hrefFragment) + $window;
        $slice = substr($src, $start, $len);
        if (strpos($slice, $navNeedle) !== false) {
            return true;
        }
        $offset = $pos + strlen($hrefFragment);
    }
    return false;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, before
 * trusting the real assertions below.
 * ========================================================================= */

$mutationFailures = [];

/* --- twifTaggedColumnsInBlock(): FAILS-HIGH (finds a tagged column) and
   FAILS-LOW (a column without the tag is NOT returned). --- */
$fixtureBlock = "    Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
              . "    Plain VARCHAR(10) NULL,\n"
              . "    Tagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'something (#1741 P1) more text',\n"
              . "    OtherTag VARCHAR(20) NULL DEFAULT NULL COMMENT 'another (#1741 D5) field',\n"
              . "    Untagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'no tag here at all',\n";
$fixtureFound = twifTaggedColumnsInBlock($fixtureBlock, ['#1741 P1', '#1741 D5']);
if (!in_array('Tagged', $fixtureFound, true) || !in_array('OtherTag', $fixtureFound, true)) {
    $mutationFailures[] = 'twifTaggedColumnsInBlock() FAILS-HIGH self-test did not find the tagged fixture columns';
}
if (in_array('Untagged', $fixtureFound, true) || in_array('Plain', $fixtureFound, true)) {
    $mutationFailures[] = 'twifTaggedColumnsInBlock() FAILS-LOW self-test wrongly matched an untagged fixture column';
}

/* --- twifSliceCreateTable(): finds a fixture table, returns null for a
   name that isn't present. --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT\n) ENGINE=InnoDB;\n";
if (twifSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'twifSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (twifSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'twifSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}

/* --- twifSliceMethod(): finds a fixture method's OWN body and correctly
   STOPS before the next method (the exact property mutation-test (a)
   in the build spec relies on — two adjacent methods must not bleed into
   each other's slice). --- */
$fixtureClassSrc = "class Fixture {\n"
                 . "    private function alpha(): array\n    {\n        \$cols = ['NeedleInAlpha'];\n        return \$cols;\n    }\n\n"
                 . "    public function beta(): array\n    {\n        \$cols = ['NeedleInBeta'];\n        return \$cols;\n    }\n"
                 . "}\n";
$alphaSlice = twifSliceMethod($fixtureClassSrc, 'alpha');
$betaSlice  = twifSliceMethod($fixtureClassSrc, 'beta');
if (strpos($alphaSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'twifSliceMethod() FAILS-HIGH self-test did not find the needle inside its own fixture method';
}
if (strpos($alphaSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "twifSliceMethod() FAILS-LOW self-test: alpha()'s slice bled into beta() — a mutation to beta() would be invisible when checking alpha()";
}
if (strpos($betaSlice, 'NeedleInAlpha') !== false) {
    $mutationFailures[] = "twifSliceMethod() FAILS-LOW self-test: beta()'s slice bled into alpha()";
}

/* --- twifMissing(): FAILS-HIGH (reports an absent needle) and FAILS-LOW
   (does NOT report a present needle). --- */
$missingResult = twifMissing('the quick brown fox', ['quick', 'zzz-absent-zzz']);
if (!in_array('zzz-absent-zzz', $missingResult, true)) {
    $mutationFailures[] = 'twifMissing() FAILS-HIGH self-test did not report an absent needle';
}
if (in_array('quick', $missingResult, true)) {
    $mutationFailures[] = 'twifMissing() FAILS-LOW self-test wrongly reported a present needle as missing';
}

/* --- twifAnchorHasNav(): co-located href+data-navigate is found; the
   SAME href with NO data-navigate anywhere nearby is not. --- */
$fixtureAnchorGood = '<a class="song-meta-link" href="/iswc/T-123" data-navigate="iswc">T-123</a>';
$fixtureAnchorBad  = '<a class="song-meta-link" href="/iswc/T-123">T-123</a>' . str_repeat('x', 500) . 'data-navigate="iswc"';
if (!twifAnchorHasNav($fixtureAnchorGood, '/iswc/', 'iswc')) {
    $mutationFailures[] = 'twifAnchorHasNav() FAILS-HIGH self-test did not find a genuinely co-located href+data-navigate';
}
if (twifAnchorHasNav($fixtureAnchorBad, '/iswc/', 'iswc', 50)) {
    $mutationFailures[] = 'twifAnchorHasNav() FAILS-LOW self-test wrongly matched a data-navigate far outside the window';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$failures = [];

$schemaFile = $repoRoot . '/appWeb/.sql/schema.sql';
$schemaSql  = is_readable($schemaFile) ? (string)file_get_contents($schemaFile) : '';
if ($schemaSql === '') {
    fwrite(STDERR, "FATAL: could not read $schemaFile\n");
    exit(1);
}

$worksBlock = twifSliceCreateTable($schemaSql, 'tblWorks');
if ($worksBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblWorks CREATE TABLE block\n");
    exit(1);
}

$taggedCols = twifTaggedColumnsInBlock($worksBlock, ['#1741 P1', '#1741 D5']);
if (!$taggedCols) {
    fwrite(STDERR, "FATAL: no #1741 P1/D5-tagged columns found in tblWorks — schema.sql parsing is likely broken\n");
    exit(1);
}

$songDataFile   = $repoRoot . '/appWeb/public_html/includes/SongData.php';
$worksAdminFile = $repoRoot . '/appWeb/public_html/manage/works.php';
$workPageFile   = $repoRoot . '/appWeb/public_html/includes/pages/work.php';
$partialFile    = $repoRoot . '/appWeb/public_html/includes/partials/external-links-panel.php';

foreach (['SongData.php' => $songDataFile, 'manage/works.php' => $worksAdminFile, 'work.php' => $workPageFile] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read $label at $path\n");
        exit(1);
    }
}

$songDataSrc   = (string)file_get_contents($songDataFile);
$worksAdminSrc = (string)file_get_contents($worksAdminFile);
$workPageSrc   = (string)file_get_contents($workPageFile);

/* ---- Slice the getWork() region: from `function getWork` to the next
   method declaration (or end of file) — AND, separately, the
   `_worksExtraCols()` cached-probe method that actually gates what
   getWork() can select (see twifSliceMethod()'s doc-comment for why
   BOTH are checked independently, not just the one). ---- */
$getWorkSlice        = twifSliceMethod($songDataSrc, 'getWork');
$worksExtraColsSlice = twifSliceMethod($songDataSrc, '_worksExtraCols');
if ($getWorkSlice === '') {
    $failures[] = 'Could not locate the function getWork() region in SongData.php';
}
if ($worksExtraColsSlice === '') {
    $failures[] = 'Could not locate the _worksExtraCols() region in SongData.php';
}

/* ---- Every tree-derived column appears in getWork(), in
   _worksExtraCols()'s own probe list, AND in manage/works.php. ---- */
if ($getWorkSlice !== '') {
    foreach (twifMissing($getWorkSlice, $taggedCols) as $missingCol) {
        $failures[] = "tblWorks.{$missingCol} (#1741 P1/D5) is not referenced anywhere inside SongData::getWork()";
    }
}
if ($worksExtraColsSlice !== '') {
    foreach (twifMissing($worksExtraColsSlice, $taggedCols) as $missingCol) {
        $failures[] = "tblWorks.{$missingCol} (#1741 P1/D5) is not in SongData::_worksExtraCols()'s own probe IN-list";
    }
}
foreach (twifMissing($worksAdminSrc, $taggedCols) as $missingCol) {
    $failures[] = "tblWorks.{$missingCol} (#1741 P1/D5) is not referenced anywhere in manage/works.php";
}

/* ---- work.php display render keys (TuneId is exempt — it surfaces as
   the `tune` link, not its own display key). ---- */
$displayKeys = [
    "'subtitle'", "'disambiguation'", "'ccli'", "'bowi'", "'tuneName'",
    "'firstPublishedYear'", "'copyrightYears'", "'copyrightHolder'",
];
foreach (twifMissing($workPageSrc, $displayKeys) as $missingKey) {
    $failures[] = "work.php does not reference display render key {$missingKey}";
}

/* ---- Identifier chip hrefs, each co-located with its data-navigate. ---- */
foreach (['iswc' => '/iswc/', 'ccli' => '/ccli/', 'bowi' => '/bowi/'] as $scheme => $hrefFragment) {
    if (!twifAnchorHasNav($workPageSrc, $hrefFragment, $scheme)) {
        $failures[] = "work.php has no anchor combining href=\"{$hrefFragment}...\" with data-navigate=\"{$scheme}\"";
    }
}

/* ---- a11y landmarks. ---- */
if (strpos($workPageSrc, 'aria-label="Breadcrumb"') === false) {
    $failures[] = 'work.php is missing aria-label="Breadcrumb"';
}
if (strpos($workPageSrc, 'role="list"') === false) {
    $failures[] = 'work.php is missing role="list"';
}

/* ---- No-inline-regex mechanism (rule #35) + the tune find-or-create
   funnel (§2.4.3). ---- */
if (strpos($worksAdminSrc, 'mediaIdentifierWorkValidate') === false) {
    $failures[] = 'manage/works.php does not call mediaIdentifierWorkValidate() — an inline preg_match() would be the rule #35 regression';
}
if (strpos($worksAdminSrc, 'tuneFindOrCreateByName') === false) {
    $failures[] = 'manage/works.php does not call tuneFindOrCreateByName()';
}

/* ---- Shared external-links partial extraction (§0.4). ---- */
if (!is_readable($partialFile)) {
    $failures[] = 'includes/partials/external-links-panel.php does not exist';
} elseif (strpos($workPageSrc, 'external-links-panel.php') === false) {
    $failures[] = 'work.php does not require() the shared external-links-panel.php partial';
}
if (strpos($workPageSrc, '$wCatLabels') !== false) {
    $failures[] = 'work.php still contains its own $wCatLabels map — the category-label map must live ONLY in the shared partial';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Work identity fields guard (#1741 P4b):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    exit(1);
}

echo "PASS: Work identity fields guard — " . count($taggedCols)
   . ' tree-derived #1741 P1/D5 tblWorks column(s) (' . implode(', ', $taggedCols) . ') '
   . "all referenced in SongData::getWork() and manage/works.php; work.php carries the display "
   . "render keys, the ISWC/CCLI/BOWI identifier chips with co-located data-navigate, the "
   . "Breadcrumb + list a11y landmarks, and the shared external-links partial (no local "
   . "\$wCatLabels map); manage/works.php calls mediaIdentifierWorkValidate() and "
   . "tuneFindOrCreateByName(); all mutation self-tests went red as expected.\n";
exit(0);
