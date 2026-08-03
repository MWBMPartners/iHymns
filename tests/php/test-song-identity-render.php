<?php

declare(strict_types=1);

/**
 * iHymns — Song identity fields render guard (#1750 / #1741 P1)
 *
 * ELI5
 * ----
 * #1741 P1 added five "identity" columns to `tblSongs` (Subtitle,
 * Disambiguation, FirstPublishedYear, CopyrightYears, CopyrightHolder).
 * #1750 is the one that actually SHOWS them: on the public song page, in
 * the `song_detail`/`song_data`/bulk API payload, and in the song's
 * JSON-LD structured data. This test makes sure that promise holds by
 * DERIVING the column list from `schema.sql` itself (rule #34 — never a
 * hand-typed list that can silently drift from the real schema) and then
 * checking each one is actually referenced by `SongData::_songIdentityCols()`,
 * both SELECT call sites (`_fetchSongRow()` + `getSongs()`, via the shared
 * `_songIdentitySelect()` fold — rule #22), `includes/pages/song.php` and
 * `index.php`'s JSON-LD wiring.
 *
 * MODEL: `tests/php/test-work-identity-fields.php` (#1741 P4b) — same
 * architecture (pure core functions + in-memory mutation self-tests run on
 * EVERY invocation), function names carry a `tsir` prefix instead of `twif`
 * so the two standalone test scripts never collide.
 *
 * WHY TREE-DERIVED, NOT HAND-TYPED (rule #34)
 * ----------------------------------------------------------------------------
 * A hand-typed `['Subtitle','Disambiguation', …]` list here would agree
 * with itself by construction and could never catch a column silently
 * dropped from `_songIdentityCols()` or `song.php` after this test was
 * written. Instead this file slices `tblSongs`'s `CREATE TABLE` block
 * straight out of `appWeb/.sql/schema.sql` and extracts every column whose
 * `COMMENT` text carries the `#1741 P1` doctag — the SAME doctag
 * convention rule #19's schema-coverage scanner (and `test-work-identity-
 * fields.php`) already rely on. A future column added to this family (or
 * one silently removed) changes what this test expects, automatically,
 * with no maintenance here.
 *
 * ONE DELIBERATE UPGRADE OVER THE P4b GUARD (per this issue's brief):
 * every source file is run through `tsirStripPhpComments()` — a real
 * tokenizer pass via `token_get_all()` that drops `T_COMMENT` /
 * `T_DOC_COMMENT` — BEFORE any substring assertion runs against it. A
 * needle that survives only inside a comment (e.g. this very file's own
 * annotations naming the deleted `$_tuneSlugFooter` variable, or a
 * doc-block mentioning `'subtitle'` as an example) must NOT satisfy a
 * check (rule #34's #1 lesson: a scanner that under-reports is worse than
 * no scanner, because its tick is read as coverage).
 *
 * MUTATION-TESTING PROTOCOL (rule #34 — run on EVERY invocation, entirely in
 * memory, never touching the tree, mirroring test-work-identity-fields.php):
 *   Each core checking function below (`tsirTaggedColumnsInBlock()`,
 *   `tsirSliceMethod()`, `tsirMissing()`, `tsirStripPhpComments()`) is
 *   exercised in BOTH directions — proven to detect a thing that's there,
 *   and proven to detect a thing that's missing — against small in-memory
 *   fixtures, not the real files. A guard that has never been proven able
 *   to fail is not trustworthy.
 *
 * Pure source-tree scan — no DB required — so it slots into the CI lint
 * step alongside `php -l` and the other guards.
 *
 *   php tests/php/test-song-identity-render.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch (either direction) or a
 * mutation self-test failed to go red.
 *
 * @see appWeb/public_html/includes/SongData.php::_songIdentityCols()
 * @see appWeb/public_html/includes/SongData.php::_songIdentitySelect()
 * @see appWeb/public_html/includes/SongData.php::_fetchSongRow()
 * @see appWeb/public_html/includes/SongData.php::getSongs()
 * @see appWeb/public_html/includes/SongData.php::songDetailIncludeBlocks()
 * @see appWeb/public_html/includes/SongData.php::getSongDetailExtras()
 * @see appWeb/public_html/includes/pages/song.php
 * @see appWeb/public_html/index.php
 * @see tests/php/test-work-identity-fields.php  the P4b guard this mirrors
 * @see .claude/catalogue-1741-1750-plan.md
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
function tsirSliceCreateTable(string $schemaSql, string $table): ?string
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
 * @param string       $block column-declaration body (tsirSliceCreateTable()'s output)
 * @param list<string> $tags  e.g. ['#1741 P1']
 * @return list<string> column names, in declaration order
 */
function tsirTaggedColumnsInBlock(string $block, array $tags): array
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
function tsirMissing(string $haystack, array $needles): array
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
 * Identical shape to test-work-identity-fields.php's `twifSliceMethod()` —
 * used here for `_songIdentityCols()`, `_songIdentitySelect()`,
 * `_fetchSongRow()`, `getSongs()`, `songDetailIncludeBlocks()` and
 * `getSongDetailExtras()`, each checked independently so a mutation to
 * ANY one of them (not just the render call sites) shows up.
 */
function tsirSliceMethod(string $src, string $name): string
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
 * Strip PHP comments (`T_COMMENT` + `T_DOC_COMMENT`) out of a PHP source
 * string via a real tokenizer pass, leaving every other token (code,
 * strings, whitespace between non-comment tokens) intact. A needle that
 * appears ONLY inside a stripped comment must not survive; a needle that
 * appears in real code is untouched.
 *
 * ELI5: reads the file the way PHP itself does, and throws away anything
 * PHP would have thrown away as a comment — so a checker that greps the
 * output can't be fooled by a code sample living inside a doc-block.
 *
 * DETAILED / WHY A TOKENIZER, NOT A REGEX: a regex-based comment stripper
 * (`/\/\*.*?\*\//s`) breaks on a `//` or `#` inside a string literal (e.g.
 * a URL in an annotation, `'https://example.com'`) — `token_get_all()` is
 * PHP's own lexer, so it already knows the difference between a comment
 * token and a string token containing `//`. Non-PHP input (a string that
 * doesn't open with `<?php`) is returned unchanged — none of this guard's
 * four target files are anything but plain PHP, so that branch never
 * actually fires here, but it keeps the helper honest about its contract
 * rather than silently mis-tokenizing.
 *
 * @see #1750
 * @link https://www.php.net/manual/en/function.token-get-all.php
 */
/**
 * Find the local variable a page extracts a `$song['$songKey']` value
 * into (e.g. `$songSubtitle = trim((string)($song['subtitle'] ?? ''));`
 * -> `songSubtitle`), then count how many times that variable is
 * referenced (word-bounded) anywhere in the same source.
 *
 * ELI5: "song.php pulls the subtitle out of the array into a local
 * variable — does it then actually DO anything with that variable, or
 * does it just sit there unused?" The extraction line itself always
 * contributes exactly one reference, so a total of 1 means "extracted
 * but never rendered" — the specific mutation this guard's build spec
 * calls out ("delete the 'subtitle' render → guard red").
 *
 * DETAILED / WHY NOT JUST GREP FOR THE QUOTED KEY: song.php (unlike
 * work.php, which reads `$work['subtitle']` inline at its one render
 * site) extracts every #1741 P1 field into a local variable UP FRONT
 * (build spec §2.1) and renders the VARIABLE further down — so the
 * quoted string `'subtitle'` is present in the file the moment the
 * extraction line exists, regardless of whether anything downstream
 * ever reads `$songSubtitle` again. A guard that only checked for the
 * quoted key would therefore stay wrong-but-green after deleting the
 * render and keeping the extraction — exactly the failure mode rule #34
 * warns about. Following the variable through its OWN name (whatever
 * abbreviation the author chose — `$songDisambig`, `$firstPubYear`, …
 * are not derivable by any fixed transform of the column name) sidesteps
 * that: it reads back the SAME name the extraction line itself declares
 * rather than guessing one.
 *
 * @return array{var:string,count:int}|null null when no extraction line
 *                                           reading $song['$songKey'] was found
 */
function tsirVariableUsageCount(string $src, string $songKey): ?array
{
    if (!preg_match(
        '/\$([A-Za-z_]\w*)\s*=[^;]*\$song\[\'' . preg_quote($songKey, '/') . '\'\]/',
        $src,
        $m
    )) {
        return null;
    }
    $varName = $m[1];
    $count = preg_match_all('/\$' . preg_quote($varName, '/') . '\b/', $src);
    return ['var' => $varName, 'count' => $count === false ? 0 : $count];
}

function tsirStripPhpComments(string $src): string
{
    if (strpos($src, '<?php') === false) {
        return $src;
    }
    $tokens = token_get_all($src);
    $out = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
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

/* --- tsirTaggedColumnsInBlock(): FAILS-HIGH (finds a tagged column) and
   FAILS-LOW (a column without the tag is NOT returned). --- */
$fixtureBlock = "    Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
              . "    Plain VARCHAR(10) NULL,\n"
              . "    Tagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'something (#1741 P1) more text',\n"
              . "    Untagged VARCHAR(20) NULL DEFAULT NULL COMMENT 'no tag here at all',\n";
$fixtureFound = tsirTaggedColumnsInBlock($fixtureBlock, ['#1741 P1']);
if (!in_array('Tagged', $fixtureFound, true)) {
    $mutationFailures[] = 'tsirTaggedColumnsInBlock() FAILS-HIGH self-test did not find the tagged fixture column';
}
if (in_array('Untagged', $fixtureFound, true) || in_array('Plain', $fixtureFound, true)) {
    $mutationFailures[] = 'tsirTaggedColumnsInBlock() FAILS-LOW self-test wrongly matched an untagged fixture column';
}

/* --- tsirSliceCreateTable(): finds a fixture table, returns null for a
   name that isn't present. --- */
$fixtureSchema = "CREATE TABLE IF NOT EXISTS tblFixtureProbe (\n    Id INT\n) ENGINE=InnoDB;\n";
if (tsirSliceCreateTable($fixtureSchema, 'tblFixtureProbe') === null) {
    $mutationFailures[] = 'tsirSliceCreateTable() FAILS-HIGH self-test did not find the fixture table';
}
if (tsirSliceCreateTable($fixtureSchema, 'tblDoesNotExist') !== null) {
    $mutationFailures[] = 'tsirSliceCreateTable() FAILS-LOW self-test wrongly matched a non-existent table';
}

/* --- tsirSliceMethod(): finds a fixture method's OWN body and correctly
   STOPS before the next method (two adjacent methods must not bleed into
   each other's slice — the exact property that lets us check
   `_songIdentityCols()`, `_songIdentitySelect()`, `_fetchSongRow()` and
   `getSongs()` each independently, below). --- */
$fixtureClassSrc = "class Fixture {\n"
                 . "    private function alpha(): array\n    {\n        \$cols = ['NeedleInAlpha'];\n        return \$cols;\n    }\n\n"
                 . "    public function beta(): array\n    {\n        \$cols = ['NeedleInBeta'];\n        return \$cols;\n    }\n"
                 . "}\n";
$alphaSlice = tsirSliceMethod($fixtureClassSrc, 'alpha');
$betaSlice  = tsirSliceMethod($fixtureClassSrc, 'beta');
if (strpos($alphaSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'tsirSliceMethod() FAILS-HIGH self-test did not find the needle inside its own fixture method';
}
if (strpos($alphaSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "tsirSliceMethod() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}
if (strpos($betaSlice, 'NeedleInAlpha') !== false) {
    $mutationFailures[] = "tsirSliceMethod() FAILS-LOW self-test: beta()'s slice bled into alpha()";
}

/* --- tsirMissing(): FAILS-HIGH (reports an absent needle) and FAILS-LOW
   (does NOT report a present needle). --- */
$missingResult = tsirMissing('the quick brown fox', ['quick', 'zzz-absent-zzz']);
if (!in_array('zzz-absent-zzz', $missingResult, true)) {
    $mutationFailures[] = 'tsirMissing() FAILS-HIGH self-test did not report an absent needle';
}
if (in_array('quick', $missingResult, true)) {
    $mutationFailures[] = 'tsirMissing() FAILS-LOW self-test wrongly reported a present needle as missing';
}

/* --- tsirStripPhpComments(): a needle living ONLY inside a /* comment
   must disappear; the SAME needle appearing in real code must survive. --- */
$fixtureCommentOnly = "<?php\n/* mentions 'subtitle-comment-only-needle' here */\n\$x = 1;\n";
$fixtureCommentAndCode = "<?php\n// mentions 'subtitle-both-needle' in a line comment too\n\$x = 'subtitle-both-needle';\n";
$strippedCommentOnly = tsirStripPhpComments($fixtureCommentOnly);
$strippedBoth        = tsirStripPhpComments($fixtureCommentAndCode);
if (strpos($strippedCommentOnly, 'subtitle-comment-only-needle') !== false) {
    $mutationFailures[] = 'tsirStripPhpComments() FAILS-HIGH self-test: a needle that exists ONLY inside a comment survived stripping';
}
if (strpos($strippedBoth, 'subtitle-both-needle') === false) {
    $mutationFailures[] = 'tsirStripPhpComments() FAILS-LOW self-test: a needle that exists in REAL CODE was wrongly stripped too';
}

/* --- tsirVariableUsageCount(): an extraction-only fixture (the variable
   is assigned but never read again) reports count===1; an
   extraction-PLUS-render fixture reports count>=2. This is the exact
   distinction the "delete the subtitle render" mutation proof at the
   bottom of this file relies on. --- */
$fixtureExtractOnly   = "<?php\n\$songFoo = trim((string)(\$song['foo'] ?? ''));\n";
$fixtureExtractAndUse = "<?php\n\$songFoo = trim((string)(\$song['foo'] ?? ''));\necho htmlspecialchars(\$songFoo);\n";
$usageExtractOnly   = tsirVariableUsageCount($fixtureExtractOnly, 'foo');
$usageExtractAndUse = tsirVariableUsageCount($fixtureExtractAndUse, 'foo');
if ($usageExtractOnly === null || $usageExtractOnly['count'] !== 1) {
    $mutationFailures[] = 'tsirVariableUsageCount() FAILS-HIGH self-test: extraction-only fixture should report count=1, got '
        . ($usageExtractOnly === null ? 'null (no extraction found)' : (string)$usageExtractOnly['count']);
}
if ($usageExtractAndUse === null || $usageExtractAndUse['count'] < 2) {
    $mutationFailures[] = 'tsirVariableUsageCount() FAILS-LOW self-test: extraction+use fixture should report count>=2, got '
        . ($usageExtractAndUse === null ? 'null (no extraction found)' : (string)$usageExtractAndUse['count']);
}
if (tsirVariableUsageCount("<?php\n\$x = 1;\n", 'not-present') !== null) {
    $mutationFailures[] = 'tsirVariableUsageCount() FAILS-LOW self-test: reported a result for a key with no extraction at all';
}

/* --- lcfirst mapping fixture: the camelCase key derivation used below
   ($camel = lcfirst($column)) matches the agreed editor/API spellings. --- */
$lcfirstFixture = [
    'Subtitle'           => 'subtitle',
    'Disambiguation'     => 'disambiguation',
    'FirstPublishedYear' => 'firstPublishedYear',
    'CopyrightYears'     => 'copyrightYears',
    'CopyrightHolder'    => 'copyrightHolder',
];
foreach ($lcfirstFixture as $col => $expectedCamel) {
    if (lcfirst($col) !== $expectedCamel) {
        $mutationFailures[] = "lcfirst mapping self-test: lcfirst('{$col}') produced '" . lcfirst($col) . "', expected '{$expectedCamel}'";
    }
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

$songsBlock = tsirSliceCreateTable($schemaSql, 'tblSongs');
if ($songsBlock === null) {
    fwrite(STDERR, "FATAL: schema.sql has no tblSongs CREATE TABLE block\n");
    exit(1);
}

$taggedCols = tsirTaggedColumnsInBlock($songsBlock, ['#1741 P1']);
if (!$taggedCols) {
    fwrite(STDERR, "FATAL: no #1741 P1-tagged columns found in tblSongs — schema.sql parsing is likely broken\n");
    exit(1);
}

/* camelCase wire keys, e.g. 'Subtitle' -> 'subtitle' — must match the
   editor's ED2_META_FIELDS spellings exactly (rule #35: the mechanism is
   THIS derivation running against the real files, not a comment asking
   two files to agree). */
$camelKeys = array_map('lcfirst', $taggedCols);

$songDataFile = $repoRoot . '/appWeb/public_html/includes/SongData.php';
$songPageFile = $repoRoot . '/appWeb/public_html/includes/pages/song.php';
$indexFile    = $repoRoot . '/appWeb/public_html/index.php';

foreach (['SongData.php' => $songDataFile, 'song.php' => $songPageFile, 'index.php' => $indexFile] as $label => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "FATAL: could not read $label at $path\n");
        exit(1);
    }
}

/* Comment-stripped BEFORE any substring assertion — the guard's one
   deliberate upgrade over the P4b guard (see file doc-block). */
$songDataSrc = tsirStripPhpComments((string)file_get_contents($songDataFile));
$songPageSrc = tsirStripPhpComments((string)file_get_contents($songPageFile));
$indexSrc    = tsirStripPhpComments((string)file_get_contents($indexFile));

/* ---- 1. Each derived Column name appears in the _songIdentityCols()
   method slice — the probe's own hardcoded IN-list. ---- */
$identityColsSlice = tsirSliceMethod($songDataSrc, '_songIdentityCols');
if ($identityColsSlice === '') {
    $failures[] = 'Could not locate the _songIdentityCols() region in SongData.php';
} else {
    foreach (tsirMissing($identityColsSlice, $taggedCols) as $missingCol) {
        $failures[] = "tblSongs.{$missingCol} (#1741 P1) is not in SongData::_songIdentityCols()'s own probe IN-list";
    }
}

/* ---- 2. Each Column appears in the _songIdentitySelect() helper slice
   (as its camelCase alias), AND the literal `_songIdentitySelect` call is
   present in BOTH _fetchSongRow() and getSongs() — the drift-proof
   formulation from the build spec: renaming/dropping either call site
   goes red even though the helper itself never changed. ---- */
$identitySelectSlice = tsirSliceMethod($songDataSrc, '_songIdentitySelect');
if ($identitySelectSlice === '') {
    $failures[] = 'Could not locate the _songIdentitySelect() region in SongData.php';
} else {
    foreach (tsirMissing($identitySelectSlice, $camelKeys) as $missingKey) {
        $failures[] = "camelCase key '{$missingKey}' is not in SongData::_songIdentitySelect()'s alias map";
    }
}
$fetchSongRowSlice = tsirSliceMethod($songDataSrc, '_fetchSongRow');
$getSongsSlice     = tsirSliceMethod($songDataSrc, 'getSongs');
if ($fetchSongRowSlice === '') {
    $failures[] = 'Could not locate the _fetchSongRow() region in SongData.php';
} elseif (strpos($fetchSongRowSlice, '_songIdentitySelect') === false) {
    $failures[] = 'SongData::_fetchSongRow() does not call _songIdentitySelect() — the single-song read path would silently drop the #1741 P1 fields';
}
if ($getSongsSlice === '') {
    $failures[] = 'Could not locate the getSongs() region in SongData.php';
} elseif (strpos($getSongsSlice, '_songIdentitySelect') === false) {
    $failures[] = 'SongData::getSongs() does not call _songIdentitySelect() — the bulk/songbook_export/bulk_songs read path would silently drop the #1741 P1 fields (§0 shape contract)';
}

/* ---- 3. Each derived camelCase key is extracted into a local variable
   in includes/pages/song.php, AND that variable is used again beyond
   its own extraction line (i.e. actually rendered) — see
   tsirVariableUsageCount()'s doc-block for why a bare "is the quoted key
   present" check is insufficient here (song.php extracts every field
   up-front, unlike work.php's inline reads). ---- */
foreach ($camelKeys as $key) {
    $usage = tsirVariableUsageCount($songPageSrc, $key);
    if ($usage === null) {
        $failures[] = "song.php has no local variable extracted from \$song['{$key}']";
        continue;
    }
    if ($usage['count'] < 2) {
        $failures[] = "song.php extracts \$song['{$key}'] into \${$usage['var']} but that variable is never referenced again — the render for '{$key}' appears to be missing";
    }
}

/* ---- 4. Each derived camelCase key appears in index.php (the JSON-LD
   wiring). ---- */
foreach (tsirMissing($indexSrc, $camelKeys) as $missingKey) {
    $failures[] = "index.php does not reference '{$missingKey}' anywhere — the JSON-LD wiring for that field is missing";
}

/* ---- 5. song.php contains the copyrightDisplay precedence fold, and
   does NOT contain the deleted $_tuneSlugFooter fold (the duplicate
   local name-fold this issue removed must stay dead — a needle that
   would ONLY survive in this file's own explanatory comments, which is
   exactly why comment-stripping (upgrade over P4b) matters here). ---- */
if (strpos($songPageSrc, 'copyrightDisplay') === false) {
    $failures[] = 'song.php does not reference $copyrightDisplay — the split-vs-legacy copyright precedence fold is missing';
}
if (strpos($songPageSrc, '_tuneSlugFooter') !== false) {
    $failures[] = 'song.php still contains the deleted $_tuneSlugFooter local name-fold — §2.6 requires ONE $tuneSlug computed once and reused by both render sites';
}

/* ---- 6. songDetailIncludeBlocks() slice contains 'externalIds', and the
   getSongDetailExtras() slice references tblSongExternalIds but NEVER
   SourceRef (the internal idempotency ref must never reach the wire —
   §4.3 of the build spec). ---- */
$includeBlocksSlice = tsirSliceMethod($songDataSrc, 'songDetailIncludeBlocks');
if ($includeBlocksSlice === '') {
    $failures[] = 'Could not locate the songDetailIncludeBlocks() region in SongData.php';
} elseif (strpos($includeBlocksSlice, "'externalIds'") === false) {
    $failures[] = "SongData::songDetailIncludeBlocks() does not list 'externalIds' in its allow-list";
}
$extrasSlice = tsirSliceMethod($songDataSrc, 'getSongDetailExtras');
if ($extrasSlice === '') {
    $failures[] = 'Could not locate the getSongDetailExtras() region in SongData.php';
} else {
    if (strpos($extrasSlice, 'tblSongExternalIds') === false) {
        $failures[] = "SongData::getSongDetailExtras() has no 'externalIds' case reading tblSongExternalIds";
    }
    if (strpos($extrasSlice, 'SourceRef') !== false) {
        $failures[] = "SongData::getSongDetailExtras() references SourceRef — the internal idempotency ref must NEVER reach the wire (§4.3)";
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failures || $mutationFailures) {
    if ($failures) {
        fwrite(STDERR, "FAIL: Song identity fields render guard (#1750):\n\n");
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

echo "PASS: Song identity fields render guard — " . count($taggedCols)
   . ' tree-derived #1741 P1 tblSongs column(s) (' . implode(', ', $taggedCols) . ') '
   . "all referenced in SongData::_songIdentityCols()'s probe IN-list and "
   . "_songIdentitySelect()'s alias map; both _fetchSongRow() and getSongs() call "
   . "_songIdentitySelect(); song.php and index.php carry every camelCase wire key "
   . "and song.php's split-copyright precedence fold; the deleted \$_tuneSlugFooter "
   . "name-fold stays dead; songDetailIncludeBlocks() lists 'externalIds' and "
   . "getSongDetailExtras() reads tblSongExternalIds without ever exposing SourceRef; "
   . "all mutation self-tests went red as expected.\n";
exit(0);
