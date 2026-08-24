<?php

declare(strict_types=1);

/**
 * iHymns — Song-editor identity fields guard (#1741 P5a)
 *
 * ELI5
 * ----
 * P5a taught the v2 song editor about six `tblSongs` columns it never touched
 * before: the five #1741 P1 identity columns (Subtitle, Disambiguation,
 * FirstPublishedYear, CopyrightYears, CopyrightHolder) plus the pre-existing
 * `Isrc` (#1064). This test makes sure that promise holds by DERIVING the P1
 * column list from `schema.sql` itself (rule #34 — never a hand-typed list
 * that can silently drift from the real schema) and then checking each one is
 * actually wired into `api2.php`'s `ED2_META_FIELDS` allow-list AND
 * `metadata-tab.js`'s `FIELDS` render list, that the ISRC branch canonicalises
 * + shape-validates rather than storing raw text, and that BOTH the write
 * path (`metadata_field_update`) and the revision-restore path
 * (`ed2_applySongSnapshot()`) consult the SAME existence-gate helper before
 * touching a P1 column that might not exist yet on an un-migrated install.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * A hand-typed `['Subtitle', 'Disambiguation', …]` list here would agree with
 * itself by construction and could never catch a column silently dropped
 * from `ED2_META_FIELDS` or `FIELDS` after this test was written. Instead
 * this file slices `tblSongs`'s `CREATE TABLE` block straight out of
 * `appWeb/.sql/schema.sql` and extracts every column whose `COMMENT` text
 * carries the `#1741 P1` doctag — the SAME doctag convention rule #19's
 * schema-coverage scanner already relies on, and the SAME slicing approach
 * `tests/php/test-work-identity-fields.php` (P4b's sibling guard) already
 * uses for `tblWorks`. `Isrc` (#1064) predates the P1 batch, so it is NOT
 * tagged and would never be derived — it is asserted EXPLICITLY, by name,
 * with this comment as the reason (the one hand-named entry in this file).
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-work-identity-fields.php):
 * each core checking function below is exercised in BOTH directions — proven
 * to detect a thing that's there, and proven to detect a thing that's
 * missing — against small in-memory fixtures, not the real files. A guard
 * that has never been proven able to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint step
 * alongside `php -l` and the other guards.
 *
 *   php tests/php/test-song-identity-editor-fields.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/manage/editor/api2.php ED2_META_FIELDS, metadata_field_update, ed2_applySongSnapshot(), ed2_songIdentityColsPresent()
 * @see appWeb/public_html/manage/editor/v2/metadata-tab.js FIELDS, GATED_COLUMNS
 * @see appWeb/public_html/includes/identifier_normalize.php ihymns_canonical_isrc()
 * @see appWeb/public_html/includes/media_identifiers.php mediaIdentifierValidateValue()
 * @see .claude/catalogue-1741-P5-plan.md §1.7
 */

$repoRoot = dirname(__DIR__, 2);

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals. Both the real assertions
 * and the mutation self-tests below call these SAME functions, so a passing
 * mutation test is evidence about the checking logic itself, not a parallel
 * checker that only ever agrees with itself.
 * ========================================================================= */

/**
 * Slice a `CREATE TABLE [IF NOT EXISTS] $table (...) ENGINE=` block out of a
 * schema.sql-shaped string. Returns the column-declaration body (between the
 * outer parens), or null when the table isn't found at all.
 */
function sifSliceCreateTable(string $schemaSql, string $table): ?string
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
 * `COMMENT '...'` text contains the given tag string. Each column
 * declaration is expected on its own physical line (true of every #1741 P1
 * column in tblSongs — verified by direct read), so this is a per-line
 * regex, not a full SQL parser. Byte-identical approach to
 * test-work-identity-fields.php's `twifTaggedColumnsInBlock()`.
 *
 * @return list<string> column names, in declaration order
 */
function sifTaggedColumnsInBlock(string $block, string $tag): array
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
        if (strpos($mm[2], $tag) !== false) {
            $cols[] = $mm[1];
        }
    }
    return $cols;
}

/**
 * Slice a named top-level `const NAME = [ ... ];` PHP array declaration out
 * of source text — from `const $name` to the first `];` that follows.
 * Returns '' when the constant isn't found.
 */
function sifSliceConstArray(string $src, string $name): string
{
    if (!preg_match(
        '/const\s+' . preg_quote($name, '/') . '\s*=\s*\[.*?\];/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a named top-level `const NAME = [ ... ];` JS array literal declared
 * with `const NAME = [` (the metadata-tab.js `FIELDS` shape) — same
 * "stop at the first closing `];`" approach as sifSliceConstArray(), just
 * matching the JS spelling (no leading `const` type keyword requirement
 * beyond the literal `const NAME = [`).
 */
function sifSliceJsConstArray(string $src, string $name): string
{
    if (!preg_match(
        '/const\s+' . preg_quote($name, '/') . '\s*=\s*\[.*?\];/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a `case 'NAME':` region out of a PHP switch statement — from the
 * `case` line to the next top-level `case '` occurrence (or end of file).
 * Mirrors test-editor-api2-contract.php's / test-work-identity-fields.php's
 * "slice to the next sibling declaration" approach, applied to a switch
 * case instead of a class method.
 */
function sifSliceCase(string $src, string $caseName): string
{
    if (!preg_match(
        '/case\s+\'' . preg_quote($caseName, '/') . '\'\s*:.*?(?=\n\s*case\s+\')/s',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function ` declaration,
 * or end of file. api2.php declares every helper function flush-left (no
 * indentation), so a start-of-line anchor is a reliable sibling boundary —
 * the same "next declaration" idiom test-work-identity-fields.php's
 * `twifSliceMethod()` uses for class methods.
 */
function sifSliceFunction(string $src, string $name): string
{
    if (!preg_match(
        '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?(?=\n^function\s|\z)/ms',
        $src,
        $m
    )) {
        return '';
    }
    return $m[0];
}

/**
 * Strip PHP `/* ... *\/` and `//`/`#` comments out of a source snippet via
 * the REAL tokenizer (`token_get_all()`), not a regex — so a doc-comment
 * that happens to MENTION a function name (e.g. this very file's own
 * "canonicalise via ihymns_canonical_isrc()" explanations) can never satisfy
 * an assertion that is checking for a genuine CALL. A snippet is a partial
 * `case`/`function` slice (unbalanced braces are fine — the tokenizer only
 * lexes, it does not require complete grammar), so it is prefixed with
 * `<?php` when not already present.
 *
 * WHY THIS EXISTS: the #1 mutation-test finding while building this guard —
 * deleting the real `ihymns_canonical_isrc()` call stayed GREEN on the first
 * pass because this file's OWN explanatory comment inside the case region
 * also contains the string `ihymns_canonical_isrc()`. A guard that can be
 * satisfied by prose describing the code, rather than the code itself, is
 * exactly the "wrong-but-green" failure rule #34 exists to catch — this
 * function is the fix, not a comment promising to remember it.
 */
function sifStripPhpComments(string $src): string
{
    $wrapped = (strpos(ltrim($src), '<?php') === 0) ? $src : ("<?php\n" . $src);
    $tokens = @token_get_all($wrapped);
    if ($tokens === false) { return $src; }
    $out = '';
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * Which of $needles are NOT present as a literal substring of $haystack.
 *
 * @param list<string> $needles
 * @return list<string> the missing subset (empty = all present)
 */
function sifMissing(string $haystack, array $needles): array
{
    $missing = [];
    foreach ($needles as $needle) {
        if (strpos($haystack, $needle) === false) {
            $missing[] = $needle;
        }
    }
    return $missing;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory, before
 * trusting the real assertions below.
 * ========================================================================= */

$mutationFailures = [];

/* --- sifTaggedColumnsInBlock(): FAILS-HIGH (finds a tagged column) and
   FAILS-LOW (a column without the tag is NOT returned). --- */
$fixtureBlock = "    Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
              . "    Plain VARCHAR(10) NULL,\n"
              . "    Tagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'something (#1741 P1) more text',\n"
              . "    Untagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'no tag here at all (#1064)',\n";
$fixtureFound = sifTaggedColumnsInBlock($fixtureBlock, '#1741 P1');
if (!in_array('Tagged', $fixtureFound, true)) {
    $mutationFailures[] = 'sifTaggedColumnsInBlock() FAILS-HIGH self-test did not find the tagged fixture column';
}
if (in_array('Untagged', $fixtureFound, true) || in_array('Plain', $fixtureFound, true)) {
    $mutationFailures[] = 'sifTaggedColumnsInBlock() FAILS-LOW self-test wrongly matched an untagged fixture column';
}

/* --- sifSliceCreateTable(): finds a fixture table, returns null for a name
   that isn't present. --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT\n) ENGINE=InnoDB;\n";
if (sifSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'sifSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (sifSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'sifSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}

/* --- sifSliceConstArray() / sifSliceJsConstArray(): find a fixture const's
   OWN body and STOP before the next const (two adjacent consts must not
   bleed into each other's slice). --- */
$fixtureConstSrc = "const ALPHA = [\n    'NeedleInAlpha' => 1,\n];\n\nconst BETA = [\n    'NeedleInBeta' => 2,\n];\n";
$alphaSlice = sifSliceConstArray($fixtureConstSrc, 'ALPHA');
$betaSlice  = sifSliceConstArray($fixtureConstSrc, 'BETA');
if (strpos($alphaSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'sifSliceConstArray() FAILS-HIGH self-test did not find the needle inside its own fixture const';
}
if (strpos($alphaSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "sifSliceConstArray() FAILS-LOW self-test: ALPHA's slice bled into BETA — a mutation to BETA would be invisible when checking ALPHA";
}

/* --- sifSliceCase(): finds a fixture case's OWN body and stops before the
   next case. --- */
$fixtureSwitchSrc = "switch (\$action) {\n    case 'alpha': {\n        \$x = 'NeedleInAlpha';\n        break;\n    }\n    case 'beta': {\n        \$x = 'NeedleInBeta';\n        break;\n    }\n}\n";
$alphaCaseSlice = sifSliceCase($fixtureSwitchSrc, 'alpha');
if (strpos($alphaCaseSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'sifSliceCase() FAILS-HIGH self-test did not find the needle inside its own fixture case';
}
if (strpos($alphaCaseSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "sifSliceCase() FAILS-LOW self-test: 'alpha' case slice bled into 'beta' case";
}

/* --- sifSliceFunction(): finds a fixture function's OWN body and stops
   before the next top-level function. --- */
$fixtureFnSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaFnSlice = sifSliceFunction($fixtureFnSrc, 'alpha');
$betaFnSlice  = sifSliceFunction($fixtureFnSrc, 'beta');
if (strpos($alphaFnSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'sifSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaFnSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "sifSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}
if (strpos($betaFnSlice, 'NeedleInAlpha') !== false) {
    $mutationFailures[] = "sifSliceFunction() FAILS-LOW self-test: beta()'s slice bled into alpha()";
}

/* --- sifStripPhpComments(): a call OUTSIDE a comment survives; a call
   MENTIONED ONLY INSIDE a comment is stripped. --- */
$fixtureCommentSrc = "/* calls realFn() in prose, but never actually */\n\$x = otherFn();\n// also mentions realFn() here\n";
$strippedFixture = sifStripPhpComments($fixtureCommentSrc);
if (strpos($strippedFixture, 'otherFn()') === false) {
    $mutationFailures[] = 'sifStripPhpComments() FAILS-HIGH self-test: a genuine call outside any comment was stripped too';
}
if (strpos($strippedFixture, 'realFn()') !== false) {
    $mutationFailures[] = 'sifStripPhpComments() FAILS-LOW self-test: a mention inside a comment survived stripping — the exact wrong-but-green gap this function exists to close';
}

/* --- sifMissing(): FAILS-HIGH (reports an absent needle) and FAILS-LOW (does
   NOT report a present needle). --- */
$missingResult = sifMissing('the quick brown fox', ['quick', 'zzz-absent-zzz']);
if (!in_array('zzz-absent-zzz', $missingResult, true)) {
    $mutationFailures[] = 'sifMissing() FAILS-HIGH self-test did not report an absent needle';
}
if (in_array('quick', $missingResult, true)) {
    $mutationFailures[] = 'sifMissing() FAILS-LOW self-test wrongly reported a present needle as missing';
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

$songsBlock = sifSliceCreateTable($schemaSql, 'tblSongs');
if ($songsBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblSongs CREATE TABLE block\n");
    exit(1);
}

$taggedCols = sifTaggedColumnsInBlock($songsBlock, '#1741 P1');
if (!$taggedCols) {
    fwrite(STDERR, "FATAL: no #1741 P1-tagged columns found in tblSongs — schema.sql parsing is likely broken\n");
    exit(1);
}

$api2File = $repoRoot . '/appWeb/public_html/manage/editor/api2.php';
$tabFile  = $repoRoot . '/appWeb/public_html/manage/editor/v2/metadata-tab.js';
foreach (['api2.php' => $api2File, 'metadata-tab.js' => $tabFile] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read $label at $path\n");
        exit(1);
    }
}
$api2Src = (string)file_get_contents($api2File);
$tabSrc  = (string)file_get_contents($tabFile);

/* ---- Every derived (#1741 P1) column name appears in ED2_META_FIELDS AND
   in metadata-tab.js's FIELDS — EXCEPT CopyrightHolder (#1862), which became
   a bespoke tblPublishers-backed picker (rule #43 — never a free-text box
   into a registry) and so is no longer a FIELDS row at all. Same posture as
   the pre-existing 'isrc'/Isrc hand-named carve-out immediately below: the
   tree-derivation still finds CopyrightHolder (it IS #1741 P1-tagged in
   schema.sql — the FK column is dormant, the free-text mirror column isn't),
   but its wiring is asserted EXPLICITLY, by name, against the bespoke
   control instead of the generic FIELDS/GATED_COLUMNS machinery. ---- */
$fieldsCheckCols = array_values(array_diff($taggedCols, ['CopyrightHolder']));

$metaFieldsSlice = sifSliceConstArray($api2Src, 'ED2_META_FIELDS');
$fieldsJsSlice   = sifSliceJsConstArray($tabSrc, 'FIELDS');
if ($metaFieldsSlice === '') {
    $failures[] = 'Could not locate the ED2_META_FIELDS const array in api2.php';
}
if ($fieldsJsSlice === '') {
    $failures[] = 'Could not locate the FIELDS const array in metadata-tab.js';
}
if ($metaFieldsSlice !== '') {
    /* CopyrightHolder DOES stay a key of api2.php's ED2_META_FIELDS (rule
       #33 — a stale cached client sending the old plain field must still be
       caught by the CopyrightHolder alias branch, never silently reach the
       generic column write) — so the allow-list side is NOT excluded here,
       only the client FIELDS side below. */
    foreach (sifMissing($metaFieldsSlice, $taggedCols) as $missingCol) {
        $failures[] = "tblSongs.{$missingCol} (#1741 P1) is not referenced inside api2.php's ED2_META_FIELDS";
    }
}
if ($fieldsJsSlice !== '') {
    foreach (sifMissing($fieldsJsSlice, $fieldsCheckCols) as $missingCol) {
        $failures[] = "tblSongs.{$missingCol} (#1741 P1) is not referenced inside metadata-tab.js's FIELDS";
    }
}

/* ---- CopyrightHolder's bespoke replacement (#1862) is actually present:
   the zero-extra-request client gate (`'CopyrightHolder' in song`, the same
   idiom GATED_COLUMNS itself uses) AND the picker's own control id. Comment-
   stripped (rpfPhpCode-equivalent inline here, mirroring
   test-rights-panel-fields.php's own JS strip) so a doc-comment describing
   the removal (this file's own explanatory prose, elsewhere) can't satisfy
   a check for the real gate. ---- */
$tabSrcStripped = preg_replace('#/\*[\s\S]*?\*/#', '', $tabSrc) ?? $tabSrc;
$tabSrcStripped = preg_replace('#(^|[^:])//.*$#m', '$1', $tabSrcStripped) ?? $tabSrcStripped;
if (strpos($tabSrcStripped, "'CopyrightHolder' in song") === false) {
    $failures[] = "metadata-tab.js does not gate the Copyright Holder picker on \"'CopyrightHolder' in song\" — the #1741 P1 zero-extra-request client gate (#1862 replacement for GATED_COLUMNS)";
}
if (strpos($tabSrcStripped, 'meta-copyrightHolder') === false) {
    $failures[] = 'metadata-tab.js has no sign of the bespoke Copyright Holder picker control (id="meta-copyrightHolder", #1862)';
}

/* ---- 'isrc' is hand-named (Isrc predates P1, so the tree-derivation above
   would never find it) — assert it explicitly in both regions. ---- */
if ($metaFieldsSlice !== '' && strpos($metaFieldsSlice, "'isrc'") === false) {
    $failures[] = "'isrc' is not a key of api2.php's ED2_META_FIELDS (Isrc is #1064, pre-dates #1741 P1, so it is never tree-derived — this is the ONE hand-named assertion in this file)";
}
if ($fieldsJsSlice !== '' && strpos($fieldsJsSlice, "'isrc'") === false) {
    $failures[] = "'isrc' is not a field key inside metadata-tab.js's FIELDS (same hand-named reason as above)";
}

/* ---- metadata_field_update case region references the canonicaliser +
   validator (never a re-typed inline regex, rule #35). Comments are
   STRIPPED before checking — see sifStripPhpComments()'s own doc-comment
   for the exact wrong-but-green gap this closes (a doc-comment explaining
   the call is not the call). ---- */
$mfuSlice = sifSliceCase($api2Src, 'metadata_field_update');
$mfuSliceCode = $mfuSlice !== '' ? sifStripPhpComments($mfuSlice) : '';
if ($mfuSlice === '') {
    $failures[] = "Could not locate case 'metadata_field_update': in api2.php";
} else {
    foreach (['ihymns_canonical_isrc', 'mediaIdentifierValidateValue'] as $needle) {
        if (strpos($mfuSliceCode, $needle) === false) {
            $failures[] = "case 'metadata_field_update' in api2.php does not CALL {$needle}() outside a comment";
        }
    }
    if (strpos($mfuSliceCode, 'ed2_songIdentityColsPresent') === false) {
        $failures[] = "case 'metadata_field_update' in api2.php does not CALL ed2_songIdentityColsPresent() outside a comment — the #1741 P1 409 existence gate";
    }
}

/* ---- ed2_applySongSnapshot() also consults the SAME existence gate before
   restoring a P1 column (silent skip on an un-migrated install). ---- */
$snapSlice = sifSliceFunction($api2Src, 'ed2_applySongSnapshot');
$snapSliceCode = $snapSlice !== '' ? sifStripPhpComments($snapSlice) : '';
if ($snapSlice === '') {
    $failures[] = 'Could not locate function ed2_applySongSnapshot() in api2.php';
} elseif (strpos($snapSliceCode, 'ed2_songIdentityColsPresent') === false) {
    $failures[] = 'ed2_applySongSnapshot() does not CALL ed2_songIdentityColsPresent() outside a comment — a restore of an absent P1 column would throw under STRICT instead of silently skipping';
}

/* ---- metadata-tab.js's client gate: GATED_COLUMNS exists AND every
   derived column name appears inside its own declaration (the client gate
   can't silently cover a subset — a P1 column left out of GATED_COLUMNS
   would render a dead control on an un-migrated install). ---- */
$gatedColsSlice = sifSliceJsConstArray($tabSrc, 'GATED_COLUMNS');
/* GATED_COLUMNS is declared as `new Set([...])`, not a bare array literal —
   sifSliceJsConstArray()'s `\[.*?\];` still matches (the Set(...) wrapper is
   just extra characters before/after the bracket the regex anchors on), but
   fall back to a plain substring search bounded at the next `const ` if the
   stricter slice comes up empty, so a harmless declaration-style change
   doesn't false-negative this guard (rule #34 — keep a guard narrow enough
   not to fail on correct code). */
if ($gatedColsSlice === '') {
    if (preg_match('/const\s+GATED_COLUMNS\s*=.*?;/s', $tabSrc, $gm)) {
        $gatedColsSlice = $gm[0];
    }
}
if ($gatedColsSlice === '') {
    $failures[] = 'Could not locate the GATED_COLUMNS declaration in metadata-tab.js';
} else {
    /* Isrc is deliberately never gated (predates #1741 P1). CopyrightHolder
       is deliberately EXCLUDED here too (#1862) — its bespoke picker gates
       itself directly on `'CopyrightHolder' in song`, asserted separately
       above, rather than going through this shared Set. */
    $p1OnlyCols = array_values(array_diff($taggedCols, ['Isrc', 'CopyrightHolder']));
    foreach (sifMissing($gatedColsSlice, $p1OnlyCols) as $missingCol) {
        $failures[] = "tblSongs.{$missingCol} (#1741 P1) is missing from metadata-tab.js's GATED_COLUMNS — an un-migrated install would render a dead control for it";
    }
    if (strpos($gatedColsSlice, "'Isrc'") !== false) {
        $failures[] = "metadata-tab.js's GATED_COLUMNS wrongly includes 'Isrc' — Isrc (#1064) predates #1741 P1 and needs no existence gate";
    }
    if (strpos($gatedColsSlice, "'CopyrightHolder'") !== false) {
        $failures[] = "metadata-tab.js's GATED_COLUMNS wrongly includes 'CopyrightHolder' — #1862 moved its gate to a direct \"'CopyrightHolder' in song\" check on the bespoke picker, not this shared Set";
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Song-editor identity fields guard (#1741 P5a):\n\n");
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

echo "PASS: Song-editor identity fields guard — " . count($taggedCols)
   . ' tree-derived #1741 P1 tblSongs column(s) (' . implode(', ', $taggedCols) . ') '
   . "all referenced in api2.php's ED2_META_FIELDS; all except CopyrightHolder (#1862's bespoke "
   . "picker, asserted separately) referenced in metadata-tab.js's FIELDS; 'isrc' present in both "
   . "(hand-named); metadata_field_update canonicalises + shape-validates ISRC and consults "
   . "ed2_songIdentityColsPresent(); ed2_applySongSnapshot() consults the same gate; "
   . "metadata-tab.js's GATED_COLUMNS covers every P1 column except Isrc + CopyrightHolder "
   . "(both gate themselves directly); all mutation self-tests went red as expected.\n";
exit(0);
