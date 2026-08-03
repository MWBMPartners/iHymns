<?php

declare(strict_types=1);

/**
 * iHymns — Tune registry page guard (#1741 P4c)
 *
 * ELI5
 * ----
 * P4c rewrote the public `/tune/<slug>` page to read the `tblTunes` registry
 * FIRST, keeping the old free-text heuristic only as a last-resort fallback
 * (rule #33 — song.php still emits name-fold `/tune/<slug>` links for tunes
 * with no registry row, so that fallback can never be deleted). This test
 * makes sure that contract holds: it DERIVES the interesting `tblTunes`
 * columns and the `tblTuneCredits.Role` vocabulary straight from
 * `schema.sql` (rule #34 — never a hand-typed list that can silently drift
 * from the real schema) and checks `includes/pages/tune.php` actually
 * references each one, keeps the registry-first `Slug = ?` lookup and the
 * `tune-registry-fallback` heuristic side by side, widens the song-list
 * match to `TuneId OR TuneName`, consumes the shared external-links
 * partial without forking its own category-label map, and carries the a11y
 * landmarks.
 *
 * #1748 UPDATE (tune-admin CRUD landed) — this file's role-ordering check
 * used to parse a LITERAL `FIELD(c.Role, 'composer', 'arranger', …)` call
 * out of tune.php's source. #1748 centralised that vocabulary into
 * `IHYMNS_TUNE_CREDIT_ROLES` (`includes/tune_helpers.php`) and refactored
 * tune.php to build the FIELD() clause DYNAMICALLY from that const's
 * `array_keys()` — so no literal quoted role list survives in source to
 * parse positionally any more (see the assertion below for the fallback
 * shape this now checks instead). The vocabulary-agreement assertion
 * itself (schema COMMENT === const) now lives in the BEHAVIOURAL D1 check
 * in `tests/php/test-tune-admin-surface.php`, which asks `tune_helpers.php`
 * directly rather than re-deriving order from source text. This file's own
 * "no admin page/nav has invented a `manage_tunes` entitlement ahead of the
 * §3.6 follow-up decision" vacuity scan is likewise RETIRED here — #1748
 * *is* that follow-up landing, so `manage_tunes` now legitimately appears
 * across `includes/entitlements.php` / `js/modules/entitlements.js` /
 * `manage/entitlements.php` / `manage/includes/admin-links.php` /
 * `manage/tunes.php` / `api.php`. That surface's OWN CI coverage
 * (`test-entitlement-parity.php`, `tests/test-entitlement-parity.js`,
 * `test-admin-gate-parity.php`, `test-tune-admin-surface.php`) is what
 * guards it going forward.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * A hand-typed `['Slug','MeterCode', …]` list here would agree with itself
 * by construction. Instead this file slices `tblTunes`'s and
 * `tblTuneCredits`'s `CREATE TABLE` blocks straight out of
 * `appWeb/.sql/schema.sql`: the tune-page columns are checked against the
 * REAL declared column names in the tblTunes block (a rename anywhere in
 * that block is caught here even if this test's own five-name list is
 * stale), and the `FIELD(c.Role, …)` ordering in tune.php is checked
 * against the tblTuneCredits.Role COMMENT's OWN pipe-separated vocabulary —
 * two independently-authored lists (a SQL comment and a PHP ORDER BY
 * clause) that must agree with nothing else enforcing it (rule #35), which
 * is exactly the failure class this test exists to catch.
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-work-identity-fields.php):
 * every core checking function below is exercised in BOTH directions —
 * proven to detect a thing that's there, and proven to detect a thing
 * that's missing — against small in-memory fixtures, not the real files. A
 * guard that has never been proven able to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint
 * step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-tune-registry-page.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/pages/tune.php
 * @see appWeb/public_html/includes/tune_helpers.php
 * @see appWeb/public_html/includes/partials/external-links-panel.php
 * @see tests/php/test-work-identity-fields.php   the P4b sibling this guard mirrors the shape of
 * @see .claude/catalogue-1741-P4-plan.md §3.5
 */

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals. Both the real assertions
 * and the mutation self-tests below call these SAME functions, so a
 * passing mutation test is evidence about the checking logic itself, not a
 * parallel checker that only ever agrees with itself.
 * ========================================================================= */

/**
 * Blank out `<!-- ... -->` and `/* ... *\/` comment bodies while keeping
 * every newline they contained (same idiom as
 * `test-fragment-inline-scripts.php`'s `stripCommentsPreservingLines()`).
 *
 * ELI5: like using correction tape on a comment instead of cutting the
 * page out — the words disappear but nothing else moves.
 *
 * WHY THIS MATTERS HERE: tune.php's own doc-block PROSE mentions
 * `` `tblTunes.Slug = ?` `` while explaining the lookup ladder (so a
 * reader understands rung (a) without opening the code) — a naive
 * `strpos($src, 'Slug = ?')` matches that sentence and would stay green
 * even if the REAL prepared statement were mutated to `Name = ?` (this was
 * caught during this guard's own mutation-testing pass: the raw-source
 * check silently passed against that exact mutation). Every check below
 * that means "this SQL/markup literally appears in the CODE" therefore
 * runs against the comment-stripped source; only the `tune-registry-
 * fallback` marker check (deliberately placed INSIDE a comment by the
 * build spec) uses the raw source.
 *
 * @param string $src Raw file contents.
 * @return string Same length in lines; comment bodies blanked.
 */
function trpgStripComments(string $src): string
{
    $src = preg_replace_callback('/<!--.*?-->/s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);
    $src = preg_replace_callback('~/\*.*?\*/~s', static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    }, $src);
    return $src;
}

/**
 * Slice a `CREATE TABLE [IF NOT EXISTS] $table (...) ENGINE=` block out of
 * a schema.sql-shaped string. Returns the column-declaration body (between
 * the outer parens), or null when the table isn't found at all.
 */
function trpgSliceCreateTable(string $schemaSql, string $table): ?string
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
 * Every column name declared in a CREATE TABLE column-declaration body
 * (`twifSliceCreateTable()`'s output), in declaration order. A generic
 * extractor (no COMMENT-doctag filter — unlike test-work-identity-fields
 * .php's tblWorks scan, most of tblTunes's interesting columns pre-date
 * the #1741 P1 doctag convention, see the file's own schema comment: "predates
 * P1 (#1090) — Subtitle/Disambiguation can be absent while the table
 * exists"), so this reads every physical column-declaration LINE and skips
 * the ones that are actually index/constraint clauses (`UNIQUE KEY …`,
 * `INDEX …`, `CONSTRAINT …`, `PRIMARY KEY …` bodies use the same
 * leading-identifier shape but are not columns).
 *
 * @param string $block column-declaration body
 * @return list<string> column names, in declaration order
 */
function trpgColumnNamesInBlock(string $block): array
{
    $cols          = [];
    $nonColumnWord = ['UNIQUE', 'INDEX', 'KEY', 'CONSTRAINT', 'PRIMARY', 'FOREIGN'];
    if (!preg_match_all('/^[ \t]*([A-Za-z_][A-Za-z0-9_]*)[ \t]+[A-Za-z]/m', $block, $matches)) {
        return $cols;
    }
    foreach ($matches[1] as $name) {
        if (in_array(strtoupper($name), $nonColumnWord, true)) {
            continue;
        }
        $cols[] = $name;
    }
    return $cols;
}

/**
 * The pipe-separated vocabulary named in a column's own `COMMENT '...'`
 * text, e.g. `Role VARCHAR(20) NOT NULL COMMENT 'composer | arranger |
 * harmoniser | source (app-validated; VARCHAR not ENUM, rule #20)'` →
 * `['composer', 'arranger', 'harmoniser', 'source']`. Text after the first
 * `(` is dropped (the parenthetical explanation, not part of the
 * vocabulary itself).
 *
 * @param string $block column-declaration body containing the column
 * @param string $column the column name, e.g. 'Role'
 * @return list<string> trimmed vocabulary tokens, in COMMENT order; empty
 *                       when the column or its COMMENT isn't found
 */
function trpgPipeVocabForColumn(string $block, string $column): array
{
    if (!preg_match(
        '/\b' . preg_quote($column, '/') . '\s+[A-Za-z].*?COMMENT\s+\'([^\']*)\'/is',
        $block,
        $m
    )) {
        return [];
    }
    $comment  = $m[1];
    $parenPos = strpos($comment, '(');
    $pipePart = $parenPos !== false ? substr($comment, 0, $parenPos) : $comment;
    $parts    = array_map('trim', explode('|', $pipePart));
    return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
}

/**
 * The literal string arguments of a `FIELD(c.Role, 'a', 'b', …)` call in
 * PHP source, in order. Empty when no such call is found.
 *
 * @return list<string>
 */
function trpgFieldRoleOrder(string $src): array
{
    if (!preg_match('/FIELD\(\s*c\.Role\s*,\s*([^)]*)\)/i', $src, $m)) {
        return [];
    }
    if (!preg_match_all("/'([^']*)'/", $m[1], $mm)) {
        return [];
    }
    return $mm[1];
}

/**
 * Which of $needles are NOT present as a literal substring of $haystack.
 *
 * @param string       $haystack
 * @param list<string> $needles
 * @return list<string> the missing subset (empty = all present)
 */
function trpgMissing(string $haystack, array $needles): array
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
 * True when $src plausibly carries its OWN copy of the shared
 * external-links-panel partial's ten-category label map (`'official' =>
 * 'Official'`, `'sheet-music' => 'Sheet music'`, …) — i.e. a re-inlined
 * fork of the map `includes/partials/external-links-panel.php` (#1741
 * P4b §0.4) already centralises. Deliberately a "how many of the ten
 * pairs are co-located" heuristic (>= 4) rather than a single-string
 * match, since a genuine fork might reformat whitespace/order — but ANY
 * caller legitimately only ever sets `$panelLinks`/`$panelHeading`/
 * `$panelAriaLabel` and requires the partial, so a real caller should
 * score 0.
 *
 * @param string $src candidate page source
 * @return bool
 */
function trpgHasLocalCategoryLabelMap(string $src): bool
{
    $categoryLabels = [
        'official' => 'Official', 'information' => 'Information', 'read' => 'Read',
        'sheet-music' => 'Sheet music', 'listen' => 'Listen', 'watch' => 'Watch',
        'purchase' => 'Purchase', 'authority' => 'Authority', 'social' => 'Social', 'other' => 'Other',
    ];
    $hits = 0;
    foreach ($categoryLabels as $key => $label) {
        $needle = "'{$key}'";
        $pos    = 0;
        while (($pos = strpos($src, $needle, $pos)) !== false) {
            $window = substr($src, $pos, 60);
            if (strpos($window, '=>') !== false && strpos($window, "'{$label}'") !== false) {
                $hits++;
                break;
            }
            $pos += strlen($needle);
        }
    }
    return $hits >= 4;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, before
 * trusting the real assertions below.
 * ========================================================================= */

$mutationFailures = [];

/* --- trpgStripComments(): a code literal survives, a comment-only mention
   of the SAME literal is blanked. This is the exact false-positive class
   this guard's own build caught: a doc-block sentence quoting real SQL
   syntax must not satisfy a "this appears in the code" check. --- */
$fixtureCommentSrc = "/* the real rule is Foo = ? in prose */\n\$x = \$db->prepare(\"WHERE Bar = ?\");\n";
$fixtureStripped   = trpgStripComments($fixtureCommentSrc);
if (strpos($fixtureStripped, 'Bar = ?') === false) {
    $mutationFailures[] = 'trpgStripComments() FAILS-HIGH self-test: a genuine code literal was stripped along with the comment';
}
if (strpos($fixtureStripped, 'Foo = ?') !== false) {
    $mutationFailures[] = 'trpgStripComments() FAILS-LOW self-test: a comment-only literal survived stripping (the exact false-positive this function exists to prevent)';
}

/* --- trpgSliceCreateTable(): finds a fixture table, null for absent. --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT\n) ENGINE=InnoDB;\n";
if (trpgSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'trpgSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (trpgSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'trpgSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}

/* --- trpgColumnNamesInBlock(): finds real columns, skips index/constraint
   lines that share the leading-identifier shape. --- */
$fixtureColBlock = "    Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
                 . "    RealColumn VARCHAR(20) NULL,\n"
                 . "    UNIQUE KEY uq_Fake (RealColumn),\n"
                 . "    INDEX idx_Fake (RealColumn),\n"
                 . "    CONSTRAINT fk_Fake FOREIGN KEY (RealColumn) REFERENCES tblOther(Id)\n";
$fixtureCols = trpgColumnNamesInBlock($fixtureColBlock);
if (!in_array('RealColumn', $fixtureCols, true) || !in_array('Id', $fixtureCols, true)) {
    $mutationFailures[] = 'trpgColumnNamesInBlock() FAILS-HIGH self-test did not find the real fixture columns';
}
if (in_array('UNIQUE', $fixtureCols, true) || in_array('INDEX', $fixtureCols, true) || in_array('CONSTRAINT', $fixtureCols, true)) {
    $mutationFailures[] = 'trpgColumnNamesInBlock() FAILS-LOW self-test wrongly counted an index/constraint clause as a column';
}

/* --- trpgPipeVocabForColumn(): extracts the pipe list, drops the
   parenthetical, ignores an unrelated column. --- */
$fixtureRoleBlock = "    Role VARCHAR(20) NOT NULL COMMENT 'alpha | beta | gamma (app-validated; some note)',\n"
                  . "    Other VARCHAR(20) NOT NULL COMMENT 'delta | epsilon',\n";
$fixtureVocab = trpgPipeVocabForColumn($fixtureRoleBlock, 'Role');
if ($fixtureVocab !== ['alpha', 'beta', 'gamma']) {
    $mutationFailures[] = 'trpgPipeVocabForColumn() FAILS-HIGH self-test did not extract the exact fixture vocabulary (got: ' . implode(',', $fixtureVocab) . ')';
}
if (in_array('delta', $fixtureVocab, true) || in_array('epsilon', $fixtureVocab, true)) {
    $mutationFailures[] = "trpgPipeVocabForColumn() FAILS-LOW self-test bled into the unrelated 'Other' column's vocabulary";
}

/* --- trpgFieldRoleOrder(): extracts args in order; a differently-named
   FIELD() call (not c.Role) is not matched. --- */
$fixtureFieldSrc = "ORDER BY FIELD(c.Role, 'composer', 'arranger', 'harmoniser', 'source'), c.SortOrder";
$fixtureFieldOrder = trpgFieldRoleOrder($fixtureFieldSrc);
if ($fixtureFieldOrder !== ['composer', 'arranger', 'harmoniser', 'source']) {
    $mutationFailures[] = 'trpgFieldRoleOrder() FAILS-HIGH self-test did not extract the exact fixture order (got: ' . implode(',', $fixtureFieldOrder) . ')';
}
if (trpgFieldRoleOrder("ORDER BY FIELD(x.OtherColumn, 'zzz')") !== []) {
    $mutationFailures[] = 'trpgFieldRoleOrder() FAILS-LOW self-test wrongly matched a FIELD() call on an unrelated column';
}

/* --- trpgMissing(): FAILS-HIGH (reports an absent needle) and FAILS-LOW
   (does NOT report a present needle). --- */
$missingResult = trpgMissing('the quick brown fox', ['quick', 'zzz-absent-zzz']);
if (!in_array('zzz-absent-zzz', $missingResult, true)) {
    $mutationFailures[] = 'trpgMissing() FAILS-HIGH self-test did not report an absent needle';
}
if (in_array('quick', $missingResult, true)) {
    $mutationFailures[] = 'trpgMissing() FAILS-LOW self-test wrongly reported a present needle as missing';
}

/* --- trpgHasLocalCategoryLabelMap(): a fixture carrying >= 4 co-located
   category=>label pairs is flagged; legitimate partial-caller source
   (only the three panel variables + a require) is not. --- */
$fixtureForkedMap = "\$xCatLabels = [\n"
                  . "    'official'    => 'Official',\n"
                  . "    'information' => 'Information',\n"
                  . "    'sheet-music' => 'Sheet music',\n"
                  . "    'listen'      => 'Listen',\n"
                  . "];\n";
$fixtureCleanCaller = "\$panelLinks = \$tuneLinks;\n\$panelHeading = 'Find this tune elsewhere';\nrequire 'external-links-panel.php';\n";
if (!trpgHasLocalCategoryLabelMap($fixtureForkedMap)) {
    $mutationFailures[] = 'trpgHasLocalCategoryLabelMap() FAILS-HIGH self-test did not flag an obviously re-inlined category-label map';
}
if (trpgHasLocalCategoryLabelMap($fixtureCleanCaller)) {
    $mutationFailures[] = 'trpgHasLocalCategoryLabelMap() FAILS-LOW self-test wrongly flagged a legitimate shared-partial caller';
}

/* =========================================================================
 * REAL ASSERTIONS
 * ========================================================================= */

$repoRoot = dirname(__DIR__, 2);
$failures = [];

$schemaFile = $repoRoot . '/appWeb/.sql/schema.sql';
$schemaSql  = is_readable($schemaFile) ? (string)file_get_contents($schemaFile) : '';
if ($schemaSql === '') {
    fwrite(STDERR, "FATAL: could not read $schemaFile\n");
    exit(1);
}

$tunesBlock = trpgSliceCreateTable($schemaSql, 'tblTunes');
if ($tunesBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblTunes CREATE TABLE block\n");
    exit(1);
}
$tuneCreditsBlock = trpgSliceCreateTable($schemaSql, 'tblTuneCredits');
if ($tuneCreditsBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblTuneCredits CREATE TABLE block\n");
    exit(1);
}

$tunesColumns = trpgColumnNamesInBlock($tunesBlock);
if (!$tunesColumns) {
    fwrite(STDERR, "FATAL: no columns parsed out of the tblTunes block — schema.sql parsing is likely broken\n");
    exit(1);
}

/* The five tblTunes columns the public page renders (plan §3.5). Checked
   BOTH ways: each must be a genuine declared column in the live schema
   (catches a rename this hand-picked list hasn't caught up with) AND must
   be referenced somewhere in tune.php (catches the page silently dropping
   one). */
$renderedCols = ['Slug', 'MeterCode', 'Subtitle', 'Disambiguation', 'Notes'];
foreach ($renderedCols as $col) {
    if (!in_array($col, $tunesColumns, true)) {
        $failures[] = "tblTunes.{$col} is not a column schema.sql actually declares — the guard's own render-column list has drifted from the schema";
    }
}

$roleVocab = trpgPipeVocabForColumn($tuneCreditsBlock, 'Role');
if (!$roleVocab) {
    fwrite(STDERR, "FATAL: could not extract tblTuneCredits.Role's COMMENT vocabulary — schema.sql parsing is likely broken\n");
    exit(1);
}

$tunePageFile = $repoRoot . '/appWeb/public_html/includes/pages/tune.php';
$partialFile  = $repoRoot . '/appWeb/public_html/includes/partials/external-links-panel.php';
if (!is_readable($tunePageFile)) {
    fwrite(STDERR, "FATAL: could not read includes/pages/tune.php at $tunePageFile\n");
    exit(1);
}
$tunePageSrc = (string)file_get_contents($tunePageFile);

/* Comment-stripped variant for every "this literally appears in the CODE"
   check below — tune.php's own doc-block prose quotes real SQL/PHP syntax
   (e.g. "`tblTunes.Slug = ?`" while explaining the lookup ladder in
   English), and a raw strpos() would count that sentence as satisfying
   the check even if the actual prepared statement were mutated away from
   it. See trpgStripComments()'s own doc-comment for the mutation-testing
   failure this caught. The ONLY check that intentionally runs against the
   RAW source is the `tune-registry-fallback` marker, a few lines down,
   because the build spec places that marker INSIDE a comment on purpose. */
$tunePageCode = trpgStripComments($tunePageSrc);

/* ---- The five rendered tblTunes columns each appear in tune.php's code. ---- */
foreach (trpgMissing($tunePageCode, $renderedCols) as $missingCol) {
    $failures[] = "tblTunes.{$missingCol} is not referenced anywhere in includes/pages/tune.php's code";
}

/* ---- tblTuneCredits.Role's COMMENT vocabulary and tune.php's
   FIELD(c.Role, …) ordering name EXACTLY the same set, in the SAME order
   (rule #35 — cross-file agreement). #1748 — tune.php's FIELD() clause is
   now BUILT dynamically from IHYMNS_TUNE_CREDIT_ROLES's array_keys(), so a
   literal quoted role list no longer exists in source to parse
   positionally (see the file doc-block's #1748 UPDATE note). Try the
   legacy literal-list shape first (still exactly checkable when present);
   otherwise fall back to asserting the dynamic-build marker is present.
   Either way, the actual schema<->const vocabulary-agreement assertion is
   D1 in test-tune-admin-surface.php (behavioural, not a source parse). ---- */
$fieldOrder = trpgFieldRoleOrder($tunePageCode);
if ($fieldOrder) {
    if ($fieldOrder !== $roleVocab) {
        $failures[] = 'includes/pages/tune.php\'s FIELD(c.Role, …) ordering ('
            . implode(', ', $fieldOrder) . ') does not exactly match tblTuneCredits.Role\'s '
            . 'COMMENT vocabulary (' . implode(', ', $roleVocab) . ')';
    }
} elseif (strpos($tunePageCode, 'FIELD(c.Role') === false
    || strpos($tunePageCode, 'IHYMNS_TUNE_CREDIT_ROLES') === false
) {
    $failures[] = 'includes/pages/tune.php has neither a literal ORDER BY FIELD(c.Role, …) '
        . 'clause nor a dynamic one built from IHYMNS_TUNE_CREDIT_ROLES (#1748)';
}

/* ---- Registry-first lookup: FROM tblTunes + a Slug = ? prepare. ---- */
foreach (['FROM tblTunes', 'Slug = ?'] as $needle) {
    if (strpos($tunePageCode, $needle) === false) {
        $failures[] = "includes/pages/tune.php's code is missing the registry-first marker \"{$needle}\"";
    }
}

/* ---- The heuristic fallback (rule #33) MUST survive, clearly marked.
   `tune-registry-fallback` is checked against the RAW source (the build
   spec deliberately places it inside a comment); `DISTINCT TuneName` is
   real SQL and is checked against the comment-stripped code. ---- */
if (strpos($tunePageSrc, 'tune-registry-fallback') === false) {
    $failures[] = "includes/pages/tune.php is missing the heuristic-fallback marker \"tune-registry-fallback\" — deleting the pre-registry heuristic silently breaks every song.php-emitted tune link with no registry row (rule #33)";
}
if (strpos($tunePageCode, 'DISTINCT TuneName') === false) {
    $failures[] = "includes/pages/tune.php's code is missing the heuristic-fallback query \"DISTINCT TuneName\" — deleting the pre-registry heuristic silently breaks every song.php-emitted tune link with no registry row (rule #33)";
}

/* ---- Song-list widened to TuneId OR TuneName on the registry path. ---- */
if (strpos($tunePageCode, 'TuneId = ? OR') === false) {
    $failures[] = 'includes/pages/tune.php\'s song-list prepare does not widen to "TuneId = ? OR" — a song whose TuneId the #1090 backfill never linked would silently disappear from its own tune\'s song list';
}

/* ---- Shared external-links partial: required, no local category map. ---- */
if (!is_readable($partialFile)) {
    $failures[] = 'includes/partials/external-links-panel.php does not exist';
} elseif (strpos($tunePageCode, 'external-links-panel.php') === false) {
    $failures[] = 'includes/pages/tune.php does not require() the shared external-links-panel.php partial';
}
if (trpgHasLocalCategoryLabelMap($tunePageCode)) {
    $failures[] = 'includes/pages/tune.php appears to carry its own category-label map — that must live ONLY in the shared partial (the exact third-copy regression #1741 P4b\'s extraction was written to prevent)';
}

/* ---- a11y landmarks. ---- */
if (strpos($tunePageSrc, 'aria-label="Breadcrumb"') === false) {
    $failures[] = 'includes/pages/tune.php is missing aria-label="Breadcrumb"';
}
if (strpos($tunePageSrc, 'role="list"') === false) {
    $failures[] = 'includes/pages/tune.php is missing role="list"';
}

/* ---- #1748 RETIRED: this used to be a zero-`manage_tunes`-occurrences
   scan guarding against a phantom entitlement being invented ahead of the
   §3.6 tune-admin-CRUD follow-up decision. #1748 *is* that follow-up
   landing (manage/tunes.php + the manage_tunes entitlement), so the
   scan's premise no longer holds — see the file doc-block's #1748 UPDATE
   note for what guards that surface now instead. ---- */

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Tune registry page guard (#1741 P4c):\n\n");
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

echo "PASS: Tune registry page guard — tree-derived tblTunes render columns ("
   . implode(', ', $renderedCols) . ') and tblTuneCredits.Role vocabulary ('
   . implode(', ', $roleVocab) . ') all referenced/ordered correctly in '
   . "includes/pages/tune.php; the registry-first Slug lookup, the tune-registry-fallback "
   . "heuristic, and the widened TuneId-OR-TuneName song list are all present; the shared "
   . "external-links partial is required with no local category-label map; Breadcrumb + "
   . "list a11y landmarks present; all mutation self-tests went red as expected.\n";
exit(0);
