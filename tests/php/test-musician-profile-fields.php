<?php

declare(strict_types=1);

/**
 * iHymns — Musician profile fields guard (#1741 P4a)
 *
 * ELI5
 * ----
 * P4a added new columns to `tblMusicians` (Type, Biography, Disambiguation)
 * and to `tblMusicianRelations` (RelationType, DateFrom/…/Note) that the
 * public musician page and the admin editor are both supposed to know
 * about. This test makes sure that promise holds by DERIVING the column
 * list from `schema.sql` itself (rule #34 — never a hand-typed list that
 * can silently drift from the real schema) and then checking each one is
 * actually referenced by `includes/pages/musician.php` and
 * `manage/musicians.php`. It also enforces the ONE-write-funnel rule
 * (#1741 P4a's `musicianTypeApply()`) by banning any OTHER `SET
 * IsGroup = …` / `SET IsSpecialCase = …` anywhere in the tree, and checks
 * the identifier-chip internal-routability derives from the tree
 * (`IHYMNS_ID_SCHEMES`), never a second hardcoded `['ipi','isni']` list.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * A hand-typed `['Type','Biography', …]` list here would agree with itself
 * by construction and could never catch a column silently dropped from
 * musician.php or manage/musicians.php after this test was written.
 * Instead this file slices `tblMusicians`'s and `tblMusicianRelations`'s
 * `CREATE TABLE` blocks straight out of `appWeb/.sql/schema.sql` and
 * extracts every column whose `COMMENT` text carries the `#1741 P1`
 * doctag — the SAME doctag convention rule #19's schema-coverage scanner
 * already relies on (and the SAME slicer + tagged-column-extraction
 * approach `tests/php/test-work-identity-fields.php` — the P4b sibling of
 * this guard — already proved out). A future column added to this family
 * (or one silently removed) changes what this test expects, automatically,
 * with no maintenance here.
 *
 * D4 (writer/musician consolidation, #1741 P4a-3) LANDED: `includes/pages/
 * writer.php` is deleted, so `musicianD4Assertions()` below now actually
 * RUNS (it self-skips only on a tree where that file still exists — kept
 * that way so this guard degrades gracefully if writer.php is ever
 * reintroduced by accident rather than failing in a confusing way).
 * NOTE: `$routerFile` originally pointed at the non-existent
 * `js/router.js` (router.js has always lived at `js/modules/router.js`) —
 * a latent bug that only became observable once D4 actually deleted
 * writer.php and these assertions started running for the first time;
 * fixed alongside the P4a-3 implementation.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely
 * in memory, never touching the tree, mirroring test-work-identity-fields.php
 * / test-media-identifiers.php): each core checking function below is
 * exercised in BOTH directions — proven to detect a thing that's there,
 * and proven to detect a thing that's missing — against small in-memory
 * fixtures, not the real files. A guard that has never been proven able
 * to fail is not trustworthy. (The BUILD SPEC's own mutation checklist —
 * mutating the real tree, restoring byte-identical — is a separate,
 * additional step run by hand before merge; it is not re-implemented
 * here because it necessarily touches real files, which this in-memory
 * self-test deliberately does not.)
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint
 * step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-musician-profile-fields.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/pages/musician.php
 * @see appWeb/public_html/manage/musicians.php
 * @see appWeb/public_html/includes/musician_helpers.php
 * @see appWeb/public_html/includes/identifier_normalize.php
 * @see tests/php/test-work-identity-fields.php   the P4b sibling this guard's shape mirrors
 * @see .claude/catalogue-1741-P4-plan.md §1.5
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
function mpfSliceCreateTable(string $schemaSql, string $table): ?string
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
 * every #1741 P1 column in this schema — verified by direct read), so
 * this is a per-line regex, not a full SQL parser.
 *
 * @param string       $block column-declaration body (mpfSliceCreateTable()'s output)
 * @param list<string> $tags  e.g. ['#1741 P1']
 * @return list<string> column names, in declaration order
 */
function mpfTaggedColumnsInBlock(string $block, array $tags): array
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
function mpfMissing(string $haystack, array $needles): array
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
 * Slice a named function/method's body out of a PHP source: from
 * `function $name(` to the next top-level `function ` declaration (or end
 * of file). Returns '' when the function name isn't found. Mirrors
 * test-work-identity-fields.php's twifSliceMethod() — see that file's
 * doc-comment for why slicing a NAMED region (rather than scanning the
 * whole file) matters: it stops a mutation to a DIFFERENT, unrelated
 * function from being invisible to a check that only ever looked at the
 * one function's own body.
 */
function mpfSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n(?:function|\s*(?:public|private|protected)\s+function)\s|\z)/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Count how many times a regex matches within $haystack.
 */
function mpfMatchCount(string $pattern, string $haystack): int
{
    return preg_match_all($pattern, $haystack) ?: 0;
}

/**
 * Return up to $window characters of $src starting at the first
 * occurrence of $anchor (anchor included), or '' when $anchor isn't
 * found. ELI5: "show me the code right after this landmark phrase."
 *
 * WHY THIS EXISTS: a bare `strpos($wholeFile, 'Biography')` (rule #34's
 * "under-report" trap) is satisfied by the word appearing ANYWHERE —
 * including in a doc-comment three sections away describing the feature
 * in prose, which is exactly what this repo's OWN column names read like
 * ("Biography", "Type", "Disambiguation" are all common English words a
 * doc-block legitimately uses in sentences). Windowing on a fixed anchor
 * — the `musicianProfileColumnsExist($db)` call site, the ONE place the
 * SELECT-fragment builder lives — proves the column is referenced in the
 * SPECIFIC code region that actually determines whether the page reads
 * it from the database, not merely mentioned somewhere in the file.
 * Mirrors test-work-identity-fields.php's twifAnchorHasNav() windowed-
 * check pattern, generalised from "anchor tag" to "any code region".
 */
function mpfSliceAfterAnchor(string $src, string $anchor, int $window): string
{
    $pos = strpos($src, $anchor);
    if ($pos === false) return '';
    return substr($src, $pos, strlen($anchor) + $window);
}

/**
 * Strip PHP comments (// # /* * /) from a source string via the real
 * tokenizer — NOT a regex — so a comment that merely MENTIONS the
 * anti-pattern being avoided (e.g. this file's own doc-blocks explaining
 * "no hardcoded ['ipi','isni'] list") doesn't false-positive the
 * hardcoded-list check below. Mirrors test-fragment-inline-scripts.php's
 * documented approach ("strips HTML and PHP block comments first" so a
 * `<script>` *mentioned* in a comment doesn't false-positive, rule #30).
 * Non-PHP text (HTML between `?>`/`<?php`) passes through unchanged —
 * token_get_all() emits it as T_INLINE_HTML, which this keeps.
 */
function mpfStripPhpComments(string $src): string
{
    $tokens = token_get_all($src);
    $out = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue; /* drop the comment entirely */
            }
            $out .= $text;
        } else {
            $out .= $token;
        }
    }
    return $out;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, before
 * trusting the real assertions below.
 * ========================================================================= */

$mutationFailures = [];

/* --- mpfTaggedColumnsInBlock(): FAILS-HIGH (finds a tagged column) and
   FAILS-LOW (a column without the tag is NOT returned). --- */
$fixtureBlock = "    Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
              . "    Plain VARCHAR(10) NULL,\n"
              . "    Tagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'something (#1741 P1) more text',\n"
              . "    Untagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'no tag here at all',\n";
$fixtureFound = mpfTaggedColumnsInBlock($fixtureBlock, ['#1741 P1']);
if (!in_array('Tagged', $fixtureFound, true)) {
    $mutationFailures[] = 'mpfTaggedColumnsInBlock() FAILS-HIGH self-test did not find the tagged fixture column';
}
if (in_array('Untagged', $fixtureFound, true) || in_array('Plain', $fixtureFound, true)) {
    $mutationFailures[] = 'mpfTaggedColumnsInBlock() FAILS-LOW self-test wrongly matched an untagged fixture column';
}

/* --- mpfSliceCreateTable(): finds a fixture table, returns null for a
   name that isn't present; also proves "tblMusicians" doesn't accidentally
   swallow "tblMusicianRelations" (the real risk here — both tables share
   the "tblMusician" prefix). --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT\n) ENGINE=InnoDB;\n"
               . "CREATE TABLE IF NOT EXISTS tblFixtureProbeRelations (\n    Id INT,\n    OtherCol INT\n) ENGINE=InnoDB;\n";
if (mpfSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'mpfSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (mpfSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'mpfSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}
$fixtureProbeSlice = mpfSliceCreateTable($fixtureSchema, 'tblFixtureProbe');
if ($fixtureProbeSlice !== null && strpos($fixtureProbeSlice, 'OtherCol') !== false) {
    $mutationFailures[] = 'mpfSliceCreateTable() bled tblFixtureProbeRelations columns into the tblFixtureProbe slice (the prefix-collision risk this test exists to catch)';
}

/* --- mpfSliceFunction(): finds a fixture function's OWN body and
   correctly STOPS before the next function — two adjacent functions must
   not bleed into each other's slice (mirrors the exact property
   mutation-test (a)/(c) below rely on). --- */
$fixtureSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\n"
            . "function beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaSlice = mpfSliceFunction($fixtureSrc, 'alpha');
$betaSlice  = mpfSliceFunction($fixtureSrc, 'beta');
if (strpos($alphaSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'mpfSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "mpfSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}
if (strpos($betaSlice, 'NeedleInAlpha') !== false) {
    $mutationFailures[] = "mpfSliceFunction() FAILS-LOW self-test: beta()'s slice bled into alpha()";
}

/* --- mpfMissing(): FAILS-HIGH (reports an absent needle) and FAILS-LOW
   (does NOT report a present needle). --- */
$missingResult = mpfMissing('the quick brown fox', ['quick', 'zzz-absent-zzz']);
if (!in_array('zzz-absent-zzz', $missingResult, true)) {
    $mutationFailures[] = 'mpfMissing() FAILS-HIGH self-test did not report an absent needle';
}
if (in_array('quick', $missingResult, true)) {
    $mutationFailures[] = 'mpfMissing() FAILS-LOW self-test wrongly reported a present needle as missing';
}

/* --- mpfSliceAfterAnchor(): finds the window right after a fixed anchor
   (FAILS-HIGH), does NOT pick up a needle placed BEFORE the anchor or
   well past the window's end (FAILS-LOW — the exact shape of mutation
   test (a): removing a needle from inside the window must be invisible
   to a needle that still exists elsewhere in the file, outside it). --- */
$fixtureAnchorSrc = 'PREFIX-NeedleBeforeAnchor-ANCHOR-NeedleInsideWindow-'
                   . str_repeat('x', 500) . '-NeedleFarAfterWindow';
$anchorSlice = mpfSliceAfterAnchor($fixtureAnchorSrc, 'ANCHOR', 40);
if (strpos($anchorSlice, 'NeedleInsideWindow') === false) {
    $mutationFailures[] = 'mpfSliceAfterAnchor() FAILS-HIGH self-test did not find a needle inside the window';
}
if (strpos($anchorSlice, 'NeedleBeforeAnchor') !== false) {
    $mutationFailures[] = 'mpfSliceAfterAnchor() FAILS-LOW self-test wrongly included text BEFORE the anchor';
}
if (strpos($anchorSlice, 'NeedleFarAfterWindow') !== false) {
    $mutationFailures[] = 'mpfSliceAfterAnchor() FAILS-LOW self-test wrongly included text past the window bound';
}
if (mpfSliceAfterAnchor($fixtureAnchorSrc, 'NO-SUCH-ANCHOR', 40) !== '') {
    $mutationFailures[] = 'mpfSliceAfterAnchor() FAILS-LOW self-test wrongly returned non-empty for a missing anchor';
}

/* --- mpfStripPhpComments(): drops a // line comment and a /* block
   comment* / while leaving real code untouched — FAILS-HIGH (comment
   text is gone) and FAILS-LOW (code text survives). --- */
$fixtureCommentSrc = "<?php\n\$keep = 'real code with [\'ipi\',\'isni\'] inside a string, not a comment';\n"
                   . "// a hand-typed ['ipi','isni'] mentioned only in a line comment\n"
                   . "/* another ['ipi','isni'] mentioned only in a block comment */\n"
                   . "\$alsoKeep = true;\n";
$strippedFixture = mpfStripPhpComments($fixtureCommentSrc);
if (strpos($strippedFixture, 'line comment') !== false || strpos($strippedFixture, 'block comment') !== false) {
    $mutationFailures[] = 'mpfStripPhpComments() FAILS-HIGH self-test: comment text survived stripping';
}
if (strpos($strippedFixture, '$keep') === false || strpos($strippedFixture, '$alsoKeep') === false) {
    $mutationFailures[] = 'mpfStripPhpComments() FAILS-LOW self-test: real code was stripped along with the comments';
}

/* --- mpfMatchCount(): the flag-funnel-ban regex itself — FAILS-HIGH on a
   genuine `SET ... IsGroup = ...` / `SET ... IsSpecialCase = ...` UPDATE,
   FAILS-LOW on an unrelated SELECT that merely reads IsGroup, and on an
   INSERT column-list (no SET keyword) that carries IsGroup as a plain
   column name. --- */
$banPattern = '/SET\s[^;]{0,300}\b(IsGroup|IsSpecialCase)\s*=/s';
$fixtureBadSql  = "UPDATE tblMusicians SET Notes = ?, IsGroup = ?, IsSpecialCase = ? WHERE Id = ?";
$fixtureGoodSql1 = "SELECT Id, IsGroup, IsSpecialCase FROM tblMusicians WHERE Id = ?";
$fixtureGoodSql2 = "INSERT INTO tblMusicians (Name, IsSpecialCase, IsGroup) VALUES (?, ?, ?)";
if (mpfMatchCount($banPattern, $fixtureBadSql) < 1) {
    $mutationFailures[] = 'flag-funnel-ban regex FAILS-HIGH self-test did not match a genuine SET IsGroup=/IsSpecialCase= UPDATE';
}
if (mpfMatchCount($banPattern, $fixtureGoodSql1) !== 0) {
    $mutationFailures[] = 'flag-funnel-ban regex FAILS-LOW self-test wrongly matched a plain SELECT of IsGroup/IsSpecialCase';
}
if (mpfMatchCount($banPattern, $fixtureGoodSql2) !== 0) {
    $mutationFailures[] = 'flag-funnel-ban regex FAILS-LOW self-test wrongly matched an INSERT column list (no SET keyword)';
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

$musiciansBlock = mpfSliceCreateTable($schemaSql, 'tblMusicians');
if ($musiciansBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblMusicians CREATE TABLE block\n");
    exit(1);
}
$relationsBlock = mpfSliceCreateTable($schemaSql, 'tblMusicianRelations');
if ($relationsBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblMusicianRelations CREATE TABLE block\n");
    exit(1);
}

$musicianTaggedCols  = mpfTaggedColumnsInBlock($musiciansBlock, ['#1741 P1']);
$relationTaggedCols  = mpfTaggedColumnsInBlock($relationsBlock, ['#1741 P1']);
if (!$musicianTaggedCols) {
    fwrite(STDERR, "FATAL: no #1741 P1-tagged columns found in tblMusicians — schema.sql parsing is likely broken\n");
    exit(1);
}
if (!$relationTaggedCols) {
    fwrite(STDERR, "FATAL: no #1741 P1-tagged columns found in tblMusicianRelations — schema.sql parsing is likely broken\n");
    exit(1);
}

$musicianPageFile   = $repoRoot . '/appWeb/public_html/includes/pages/musician.php';
$musiciansAdminFile = $repoRoot . '/appWeb/public_html/manage/musicians.php';
$helpersFile        = $repoRoot . '/appWeb/public_html/includes/musician_helpers.php';
$writerPageFile     = $repoRoot . '/appWeb/public_html/includes/pages/writer.php';
$apiFile            = $repoRoot . '/appWeb/public_html/api.php';
$routerFile         = $repoRoot . '/appWeb/public_html/js/modules/router.js';
$sitemapFile        = $repoRoot . '/appWeb/public_html/sitemap.xml.php';

foreach ([
    'musician.php'          => $musicianPageFile,
    'manage/musicians.php'  => $musiciansAdminFile,
    'musician_helpers.php'  => $helpersFile,
] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read $label at $path\n");
        exit(1);
    }
}

$musicianPageSrc   = (string)file_get_contents($musicianPageFile);
$musiciansAdminSrc = (string)file_get_contents($musiciansAdminFile);
$helpersSrc        = (string)file_get_contents($helpersFile);

/* ---- Every #1741 P1 tblMusicians column appears in BOTH musician.php
   AND manage/musicians.php (§1.5 first bullet).
   musician.php gets the STRICTER windowed check (mpfSliceAfterAnchor,
   anchored at the musicianProfileColumnsExist($db) call site that opens
   the SELECT-fragment builder) rather than a whole-file mpfMissing() —
   "Type"/"Biography"/"Disambiguation" are common English words this very
   file's OWN doc-comments legitimately use in prose sentences elsewhere
   (e.g. "a dedicated public Biography field" in the file's header
   doc-block), so a whole-file substring check is satisfied by a comment
   mentioning the word and stays wrong-but-green after the SELECT
   reference itself is deleted — exactly mutation test (a) in the build
   spec, and exactly the rule #34 "under-report" trap. manage/musicians.php
   has no single equivalent anchor (Type/Biography/Disambiguation each
   have their own read AND write call sites spread across the add/
   update_person handlers), so it keeps the whole-file check — still
   meaningful there because the admin page never uses these words in
   prose the way a page's own doc-comment does. ---- */
$musicianSelectWindow = mpfSliceAfterAnchor($musicianPageSrc, 'musicianProfileColumnsExist($db)', 400);
if ($musicianSelectWindow === '') {
    $failures[] = 'musician.php does not call musicianProfileColumnsExist($db) — cannot locate the SELECT-fragment builder to check';
} else {
    foreach (mpfMissing($musicianSelectWindow, $musicianTaggedCols) as $missingCol) {
        $failures[] = "tblMusicians.{$missingCol} (#1741 P1) is not referenced in musician.php's Type/Biography/Disambiguation SELECT-fragment builder (the ~400 chars after musicianProfileColumnsExist(\$db))";
    }
}
foreach (mpfMissing($musiciansAdminSrc, $musicianTaggedCols) as $missingCol) {
    $failures[] = "tblMusicians.{$missingCol} (#1741 P1) is not referenced anywhere in manage/musicians.php";
}

/* ---- musician.php contains the notes-as-bio-fallback marker AND calls
   musicianProfileColumnsExist() (§1.5 second bullet). The marker check is
   windowed (mpfSliceAfterAnchor, anchored at the `$bioText = trim(…
   ['Biography'] …)` line that opens the bio switch) for the SAME reason
   the SELECT-fragment check above is windowed: this file's own doc-block
   and a later cross-reference comment BOTH legitimately mention the
   literal phrase "notes-as-bio-fallback" in prose (see this very guard's
   file for the identical pattern), so a whole-file `strpos()` stays
   green even after the actual marker comment on the fallback branch
   itself is deleted — mutation test (b) in the build spec. ---- */
$bioSwitchWindow = mpfSliceAfterAnchor($musicianPageSrc, "\$bioText = trim((string)(\$person['Biography']", 200);
if ($bioSwitchWindow === '') {
    $failures[] = "musician.php's bio switch (\$bioText = trim(...\$person['Biography']...)) could not be located";
} elseif (strpos($bioSwitchWindow, 'notes-as-bio-fallback') === false) {
    $failures[] = "musician.php's bio switch is missing the literal notes-as-bio-fallback marker comment on its fallback branch";
}
if (strpos($musicianPageSrc, 'musicianProfileColumnsExist') === false) {
    $failures[] = 'musician.php does not call musicianProfileColumnsExist()';
}

/* ---- RelationType appears in musician_helpers.php's loader region AND
   manage/musicians.php (§1.5 third bullet). "Loader region" = the named
   loader functions' own bodies, sliced independently so a mutation to
   ONE loader can't hide behind another still mentioning the column
   (mirrors test-work-identity-fields.php's getWork()/_worksExtraCols()
   double-check, and mutation-test (a)/(c) below rely on this). ---- */
$loaderFnNames = [
    'musicianRelationColumnsExist', 'loadMusicianGroupMembers', 'loadMusicianGroupMembersBulk',
    'loadMusicianPortrayedBy', 'loadMusicianPortrays', 'addMusicianRelation',
];
$loaderSlicesMissingRelationType = [];
foreach ($loaderFnNames as $fnName) {
    $slice = mpfSliceFunction($helpersSrc, $fnName);
    if ($slice === '') {
        $failures[] = "Could not locate function {$fnName}() in musician_helpers.php";
        continue;
    }
    if (strpos($slice, 'RelationType') === false) {
        $loaderSlicesMissingRelationType[] = $fnName;
    }
}
if ($loaderSlicesMissingRelationType) {
    $failures[] = 'RelationType is not referenced inside these musician_helpers.php loader/writer function(s): '
                . implode(', ', $loaderSlicesMissingRelationType);
}
if (strpos($musiciansAdminSrc, 'RelationType') === false && strpos($musiciansAdminSrc, 'relation_type') === false) {
    $failures[] = 'manage/musicians.php does not reference RelationType / relation_type anywhere';
}

/* ---- Every #1741 P1 tblMusicianRelations column appears somewhere in
   musician_helpers.php — the ONE place that ever names these columns as
   SQL identifiers (§1.5 only requires RelationType, checked separately
   above, to surface in manage/musicians.php's own text; the four date/
   note columns reach the admin page only as PHP variables
   ($date_from/$date_to/$note POST fields), never as literal column-name
   strings, which is the correct shape — manage/musicians.php should
   never hardcode a SQL column name itself; see rule #5). ---- */
foreach (mpfMissing($helpersSrc, $relationTaggedCols) as $missingCol) {
    $failures[] = "tblMusicianRelations.{$missingCol} (#1741 P1) is not referenced anywhere in musician_helpers.php";
}

/* ---- Flag-funnel ban (§1.5 fourth bullet): regex-count-exact over every
   *.php under appWeb/public_html EXCLUDING musician_helpers.php and
   appWeb/.sql/ must be ZERO. Directory walk (not glob('**') — PHP's glob()
   doesn't support ** without GLOB_BRACE tricks) so every file is actually
   visited, not just top-level ones. ---- */
function mpfWalkPhpFiles(string $dir, array &$out): void
{
    $entries = scandir($dir);
    if ($entries === false) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            mpfWalkPhpFiles($path, $out);
        } elseif (substr($path, -4) === '.php') {
            $out[] = $path;
        }
    }
}
$allPhpFiles = [];
mpfWalkPhpFiles($repoRoot . '/appWeb/public_html', $allPhpFiles);
$flagFunnelHits = [];
foreach ($allPhpFiles as $path) {
    if (strpos($path, DIRECTORY_SEPARATOR . 'musician_helpers.php') !== false) continue;
    $src = (string)file_get_contents($path);
    $n = mpfMatchCount($banPattern, $src);
    if ($n > 0) {
        $flagFunnelHits[] = $path . ' (' . $n . ' match(es))';
    }
}
if ($flagFunnelHits) {
    $failures[] = 'flag-funnel-ban violated — direct SET IsGroup=/IsSpecialCase= UPDATE(s) found outside musician_helpers.php: '
                . implode('; ', $flagFunnelHits);
}

/* ---- Chips: musician.php must reference IHYMNS_ID_SCHEMES (the
   tree-derived internal-route source), never a hand-typed ['ipi','isni']
   list (§1.5 fifth bullet). Comments are stripped first (via the real
   tokenizer, mpfStripPhpComments()) so a doc-comment MENTIONING the
   anti-pattern being avoided — exactly what this very file's own
   doc-block, and musician.php's, both do — doesn't false-positive. ---- */
if (strpos($musicianPageSrc, 'IHYMNS_ID_SCHEMES') === false) {
    $failures[] = "musician.php does not reference IHYMNS_ID_SCHEMES — the identifier chips' internal-routability derivation is missing or was re-hardcoded";
}
$musicianPageCodeOnly = mpfStripPhpComments($musicianPageSrc);
if (preg_match('/\[\s*[\'"]ipi[\'"]\s*,\s*[\'"]isni[\'"]\s*\]/', $musicianPageCodeOnly)
    || preg_match('/\[\s*[\'"]isni[\'"]\s*,\s*[\'"]ipi[\'"]\s*\]/', $musicianPageCodeOnly)) {
    $failures[] = "musician.php contains a hand-typed ['ipi','isni'] (or ['isni','ipi']) literal in real code — the regression rule #35 forbids";
}

/* =========================================================================
 * D4 assertions — GUARDED on writer.php's absence (§1.5 sixth bullet).
 * P4a-3 (the writer/musician consolidation) is explicitly OUT OF SCOPE for
 * this commit and held pending an owner decision (D4) — these assertions
 * simply do not run while includes/pages/writer.php still exists, so this
 * guard passes correctly on the current, D4-undecided tree. They activate
 * automatically the moment writer.php is deleted in a future commit.
 * ========================================================================= */
function musicianD4Assertions(
    bool   $writerFileExists,
    string $apiSrc,
    string $routerSrc,
    string $sitemapSrc
): array {
    if ($writerFileExists) {
        return []; /* D4 not yet applied — nothing to assert. */
    }
    $out = [];
    if (strpos($apiSrc, "'writer'") === false) {
        $out[] = "D4: writer.php is gone but api.php's alias block no longer mentions 'writer'";
    }
    if (strpos($routerSrc, "case 'writer'") === false) {
        $out[] = "D4: writer.php is gone but router.js has no case 'writer' alias";
    }
    if (strpos($sitemapSrc, '/musician/') === false) {
        $out[] = 'D4: writer.php is gone but sitemap.xml.php does not emit /musician/ links';
    }
    if (strpos($sitemapSrc, '/writer/') !== false) {
        $out[] = 'D4: writer.php is gone but sitemap.xml.php still emits /writer/ links';
    }
    return $out;
}

$writerFileExists = file_exists($writerPageFile);
$apiSrcForD4      = $writerFileExists ? '' : (string)@file_get_contents($apiFile);
$routerSrcForD4   = $writerFileExists ? '' : (string)@file_get_contents($routerFile);
$sitemapSrcForD4  = $writerFileExists ? '' : (string)@file_get_contents($sitemapFile);
foreach (musicianD4Assertions($writerFileExists, $apiSrcForD4, $routerSrcForD4, $sitemapSrcForD4) as $d4Failure) {
    $failures[] = $d4Failure;
}

/* --- D4-gate self-test (mutation-testable in memory): the SAME function
   must report failures when fed a writer-file-absent fixture that is
   missing the expected markers, and report NOTHING when
   $writerFileExists = true regardless of fixture content. --- */
$d4Skipped = musicianD4Assertions(true, '', '', ''); // writer.php present → must be []
if ($d4Skipped !== []) {
    $mutationFailures[] = 'musicianD4Assertions() FAILS-LOW self-test: returned non-empty while writerFileExists=true (should always skip)';
}
$d4ShouldFail = musicianD4Assertions(false, 'no writer alias here', 'no router case here', '/writer/ still here, no musician link');
if (count($d4ShouldFail) < 1) {
    $mutationFailures[] = 'musicianD4Assertions() FAILS-HIGH self-test: did not flag a writer-absent fixture missing every D4 marker';
}
$d4ShouldPass = musicianD4Assertions(false, "case 'writer'", "case 'writer':", '/musician/only');
if ($d4ShouldPass !== []) {
    $mutationFailures[] = 'musicianD4Assertions() FAILS-LOW self-test: flagged a writer-absent fixture that DOES carry every D4 marker';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Musician profile fields guard (#1741 P4a):\n\n");
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

echo "PASS: Musician profile fields guard — " . count($musicianTaggedCols)
   . ' tree-derived #1741 P1 tblMusicians column(s) (' . implode(', ', $musicianTaggedCols) . ') '
   . 'and ' . count($relationTaggedCols) . ' tblMusicianRelations column(s) ('
   . implode(', ', $relationTaggedCols) . ") all referenced where expected; "
   . "notes-as-bio-fallback + musicianProfileColumnsExist() present in musician.php; "
   . "flag-funnel-ban clean (0 matches outside musician_helpers.php); "
   . "chips derive internal-routability from IHYMNS_ID_SCHEMES (no hardcoded "
   . "['ipi','isni']); D4 assertions correctly "
   . ($writerFileExists ? 'SKIPPED (writer.php still exists)' : 'RAN (writer.php absent)')
   . "; all mutation self-tests went red as expected.\n";
exit(0);
