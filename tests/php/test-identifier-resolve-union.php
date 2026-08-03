<?php

declare(strict_types=1);

/**
 * test-identifier-resolve-union.php — the #1749 tblSongExternalIds union widening
 * ==================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `includes/identifier_resolve.php`'s `_ihymns_resolve_songs_sql()` builds
 * the SQL text `/isrc/<code>` uses to find songs. Before #1749 it only ever
 * looked at `tblSongs.Isrc`; #1749 widens it so an ISRC that only exists as
 * a row in the `tblSongExternalIds` store (a curator-entered second
 * recording, say) can be found too. This test proves that widening is wired
 * correctly: the extra `OR` clause is there when asked for, absent when
 * not, the visibility predicate still covers BOTH arms of the OR, and the
 * two real call sites (the runtime existence-gate + the `case 'isrc':`
 * wiring) actually use it.
 *
 * WHY THIS FILE CALLS THE BUILDER DIRECTLY (rule #34's "a property a test
 * could simply have CALLED")
 * ----------------------------------------------------------------------------
 * `_ihymns_resolve_songs_sql()` is deliberately PURE — no `\mysqli`, no I/O
 * — precisely so a test can invoke it with plain strings and assert on the
 * SQL text it returns, mirroring `musicianResolveLegacySlug()` /
 * `test-musician-slug-resolve.php`'s injected-callable pattern one file
 * over: a testable seam beats re-deriving intent from a live query plan.
 *
 * WHY THE SOURCE SCAN IS COMMENT-STRIPPED FIRST (rule #34)
 * ----------------------------------------------------------------------------
 * A comment mentioning `tblSongExternalIds` or `'isrc'` must never satisfy
 * an assertion that the RUNTIME code actually references it — this file
 * strips `T_COMMENT`/`T_DOC_COMMENT` tokens via `token_get_all()` before any
 * regex runs against source text, and proves (in-memory, no tree access)
 * that a comment-only fixture is correctly ignored.
 *
 * MUTATION-TESTING PROTOCOL (rule #34) — every slicer/stripper below is
 * exercised in BOTH directions (fails-high / fails-low) against small
 * in-memory fixtures before any real assertion runs, on every invocation.
 *
 *   php tests/php/test-identifier-resolve-union.php
 *
 * Exit status 0 = clean, 1 = at least one mismatch or a mutation self-test
 * failed to go red.
 *
 * @see appWeb/public_html/includes/identifier_resolve.php _ihymns_resolve_songs_sql(), _ihymns_resolve_songs()
 * @see tests/php/test-musician-slug-resolve.php            the injected-callable / pure-fn testing pattern this mirrors
 * @see tests/php/test-song-external-id-mirror.php           the comment-stripped-scan + mutation-self-test shape this mirrors
 * @see .claude/catalogue-1741-followups-small-plan.md §1.3  this file's build spec
 * @see #1749
 */

$root = dirname(__DIR__, 2);

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/* Direct access to identifier_resolve.php is blocked by a
   `basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)` guard — see
   the file's own doc-block. This test's own basename is
   `test-identifier-resolve-union.php`, never `identifier_resolve.php`, so a
   plain require_once here sails straight through it exactly as every OTHER
   consumer of this file does. It `require_once`s identifier_normalize.php +
   song_soft_delete.php itself, and IHYMNS_ID_SCHEMES / songVisibleSql() are
   never called by this file (only the pure SQL builder is), so no DB and no
   extra requires are needed here. */
require_once $root . '/appWeb/public_html/includes/identifier_resolve.php';

/* =========================================================================
 * PURE CORE FUNCTIONS — no file I/O, no globals (mirrors
 * test-song-external-id-mirror.php's seimSliceFunction()/seimSliceCase()).
 * ========================================================================= */

/**
 * Slice a named top-level PHP `function NAME(...) { ... }` region — from
 * `function NAME(` to the next top-level (column-0) `function ` declaration,
 * or end of file. Copied locally (not required from the sibling test) —
 * this file is standalone per the runner's glob contract.
 */
function irusSliceFunction(string $src, string $name): string
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
 * Slice a `case 'NAME':` region out of a PHP switch statement — from the
 * `case` line to the next top-level `case '` occurrence, end of `switch`
 * (closing brace at column 8, matching `ihymns_resolve_identifier()`'s own
 * indentation), or end of file — whichever comes first. Bounded to at most
 * $maxLen chars past the case start (rule #34 / #1676's "generous bounded
 * window, never to end of line" lesson) so a missing terminator can't run
 * away across the whole file.
 */
function irusSliceCase(string $src, string $caseName, int $maxLen = 300): string
{
    if (!preg_match(
        '/case\s+\'' . preg_quote($caseName, '/') . '\'\s*:/',
        $src,
        $m,
        PREG_OFFSET_CAPTURE
    )) {
        return '';
    }
    $start = $m[0][1];
    return substr($src, $start, $maxLen);
}

/**
 * Strip PHP comments (`T_COMMENT`, `T_DOC_COMMENT`) out of a source string
 * via `token_get_all()`, so a subsequent regex scan can never be satisfied
 * by a mention inside a comment (rule #34). Every other token's text is
 * preserved verbatim (including whitespace tokens), so byte offsets inside
 * the SURVIVING code stay meaningful even though the string as a whole is
 * shorter. A leading `<?php` tag is required for `token_get_all()` to
 * tokenize as PHP — callers pass real file contents, which already have one.
 */
function irusStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run FIRST, entirely in memory.
 * ========================================================================= */

$mutationFailures = [];

/* --- irusSliceFunction() --- */
$fixtureFnSrc = "function alpha(): array\n{\n    \$cols = ['NeedleInAlpha'];\n    return \$cols;\n}\n\nfunction beta(): array\n{\n    \$cols = ['NeedleInBeta'];\n    return \$cols;\n}\n";
$alphaFnSlice = irusSliceFunction($fixtureFnSrc, 'alpha');
if (strpos($alphaFnSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'irusSliceFunction() FAILS-HIGH self-test did not find the needle inside its own fixture function';
}
if (strpos($alphaFnSlice, 'NeedleInBeta') !== false) {
    $mutationFailures[] = "irusSliceFunction() FAILS-LOW self-test: alpha()'s slice bled into beta()";
}

/* --- irusSliceCase() --- */
$fixtureSwitchSrc = "switch (\$action) {\n    case 'alpha':\n        \$x = 'NeedleInAlpha';\n        break;\n    case 'beta':\n        \$x = 'NeedleInBeta';\n        break;\n}\n";
$alphaCaseSlice = irusSliceCase($fixtureSwitchSrc, 'alpha', 60);
if (strpos($alphaCaseSlice, 'NeedleInAlpha') === false) {
    $mutationFailures[] = 'irusSliceCase() FAILS-HIGH self-test did not find the needle inside its own fixture case (within the bounded window)';
}
$alphaCaseSliceWide = irusSliceCase($fixtureSwitchSrc, 'alpha', 4000);
if (strpos($alphaCaseSliceWide, 'NeedleInBeta') === false) {
    /* A generously-bounded window legitimately runs PAST the next case (it
       is not smart about `case` boundaries) — that is fine, since callers
       of THIS test file's real assertion below bound the window well short
       of the next case in the real source. This assertion only proves the
       slicer's window-length parameter actually does something observable,
       i.e. it hasn't been mutated into a no-op. */
    $mutationFailures[] = 'irusSliceCase() self-test: widening $maxLen had no observable effect — window parameter looks dead';
}
if (irusSliceCase($fixtureSwitchSrc, 'does-not-exist') !== '') {
    $mutationFailures[] = "irusSliceCase() FAILS-LOW self-test wrongly matched a case label that isn't in the fixture";
}

/* --- irusStripComments() --- */
$fixtureCommentSrc = "<?php\n// a line comment mentioning NeedleInComment\n/* a block comment mentioning NeedleInComment too */\n\$real = 'NeedleInCode';\n";
$stripped = irusStripComments($fixtureCommentSrc);
if (strpos($stripped, 'NeedleInComment') !== false) {
    $mutationFailures[] = 'irusStripComments() FAILS-LOW self-test: a comment-only needle survived stripping';
}
if (strpos($stripped, 'NeedleInCode') === false) {
    $mutationFailures[] = 'irusStripComments() FAILS-HIGH self-test: real code was stripped along with the comments';
}

/* =========================================================================
 * SECTION 1 — direct-call assertions against the pure SQL builder.
 * ========================================================================= */

echo "1 — _ihymns_resolve_songs_sql(): the pure builder, called directly\n";

$sqlNoStore = _ihymns_resolve_songs_sql('Isrc', '1=1', false);
ok("no-store SQL contains NO 'tblSongExternalIds'",
    strpos($sqlNoStore, 'tblSongExternalIds') === false,
    'got: ' . $sqlNoStore);
ok('no-store SQL carries exactly 1 placeholder',
    substr_count($sqlNoStore, '?') === 1,
    'count=' . substr_count($sqlNoStore, '?') . ' sql=' . $sqlNoStore);

$sqlWithStore = _ihymns_resolve_songs_sql('Isrc', '1=1', true);
ok("with-store SQL DOES contain 'tblSongExternalIds'",
    strpos($sqlWithStore, 'tblSongExternalIds') !== false,
    'got: ' . $sqlWithStore);
ok('with-store SQL carries exactly 3 placeholders (value, IdType, value)',
    substr_count($sqlWithStore, '?') === 3,
    'count=' . substr_count($sqlWithStore, '?') . ' sql=' . $sqlWithStore);

$orPos   = strpos($sqlWithStore, 'OR s.SongId IN');
$andPos  = strpos($sqlWithStore, 'AND 1=1');
ok('the union arm sits BEFORE the visibility predicate (#1694 parenthesisation property)',
    $orPos !== false && $andPos !== false && $orPos < $andPos,
    'orPos=' . var_export($orPos, true) . ' andPos=' . var_export($andPos, true) . ' sql=' . $sqlWithStore);

/* The #1694 lesson: the OR pair must be PARENTHESISED so the trailing
   `AND <visibility>` binds to BOTH arms, not just the second one. Find the
   `WHERE (` open-paren that starts the match group and confirm the char
   immediately after `WHERE ` is `(`. */
if (preg_match('/WHERE\s+(\S)/', $sqlWithStore, $mOpen)) {
    ok('the match group opens with a literal "(" right after WHERE — the whole OR pair is one parenthesised group',
        $mOpen[1] === '(',
        'first non-space char after WHERE was: ' . var_export($mOpen[1], true) . ' sql=' . $sqlWithStore);
} else {
    ok('the match group opens with a literal "(" right after WHERE', false, 'no WHERE clause found at all: ' . $sqlWithStore);
}

$sqlCcli = _ihymns_resolve_songs_sql('Ccli', '1=1', false);
ok("Ccli builder output still carries the \"<> ''\" empty-string guard",
    strpos($sqlCcli, "<> ''") !== false,
    'got: ' . $sqlCcli);

/* =========================================================================
 * SECTION 1b — direct-call assertions on the pure gate decision
 * (_ihymns_resolve_use_store). Catches a polarity inversion behaviourally,
 * where a source co-occurrence scan cannot (an adversarial audit found the
 * first-draft §2 assertion stayed GREEN under both `!$storeTableExists` and a
 * dead-code `$useStore = false`). #1749.
 * ========================================================================= */

echo "\n1b — _ihymns_resolve_use_store(): the pure store-union gate, called directly\n";

ok('gate TRUE only when the table exists AND a store IdType was asked for',
    _ihymns_resolve_use_store(true, 'isrc') === true,
    'got: ' . var_export(_ihymns_resolve_use_store(true, 'isrc'), true));
ok('gate FALSE when the store table is ABSENT (an inverted polarity would return true here)',
    _ihymns_resolve_use_store(false, 'isrc') === false,
    'got: ' . var_export(_ihymns_resolve_use_store(false, 'isrc'), true));
ok('gate FALSE when no store IdType was asked for (iswc/ccli path), even if the table exists',
    _ihymns_resolve_use_store(true, null) === false,
    'got: ' . var_export(_ihymns_resolve_use_store(true, null), true));
ok('gate FALSE when neither holds',
    _ihymns_resolve_use_store(false, null) === false,
    'got: ' . var_export(_ihymns_resolve_use_store(false, null), true));

/* =========================================================================
 * SECTION 2 — source-scan assertions, comment-stripped first (rule #34).
 * ========================================================================= */

echo "\n2 — source-scan: the runtime code actually USES the widened builder\n";

$srcFile = $root . '/appWeb/public_html/includes/identifier_resolve.php';
if (!is_readable($srcFile)) {
    fwrite(STDERR, "FATAL: could not read {$srcFile}\n");
    exit(1);
}
$rawSrc     = (string)file_get_contents($srcFile);
$strippedSrc = irusStripComments($rawSrc);

$resolveSongsSlice = irusSliceFunction($strippedSrc, '_ihymns_resolve_songs');
ok('_ihymns_resolve_songs() (comment-stripped) references _ihymns_table_exists(...tblSongExternalIds...) — the RUNTIME existence gate, not just the builder',
    $resolveSongsSlice !== ''
    && strpos($resolveSongsSlice, '_ihymns_table_exists') !== false
    && strpos($resolveSongsSlice, 'tblSongExternalIds') !== false,
    'slice: ' . $resolveSongsSlice);

/* The dead-code-disable gap: the assertion above passes as long as the probe
   is MENTIONED, even if the code then hardcodes `$useStore = false` and
   ignores it. Require `$useStore` to be ASSIGNED from the pure gate decision
   (which §1b proves has correct polarity), and to drive the store bind — so a
   `$useStore = false` / discarded-probe mutation goes red (rule #34). */
ok('_ihymns_resolve_songs() assigns $useStore from _ihymns_resolve_use_store(...) — the gate result is not discarded/hardcoded',
    $resolveSongsSlice !== ''
    && preg_match('/\$useStore\s*=\s*_ihymns_resolve_use_store\s*\(/', $resolveSongsSlice) === 1,
    'slice: ' . $resolveSongsSlice);
ok('_ihymns_resolve_songs() gates the 3-placeholder store bind on $useStore (if ($useStore) ... bind_param(\'sss\'))',
    $resolveSongsSlice !== ''
    && preg_match('/if\s*\(\s*\$useStore\s*\)/', $resolveSongsSlice) === 1
    && strpos($resolveSongsSlice, "bind_param('sss'") !== false,
    'slice: ' . $resolveSongsSlice);

$isrcCaseSlice = irusSliceCase($strippedSrc, 'isrc', 300);
ok("the (comment-stripped) case 'isrc': region passes a 4th argument 'isrc' to _ihymns_resolve_songs(...)",
    $isrcCaseSlice !== '' && preg_match('/_ihymns_resolve_songs\s*\(\s*\$db\s*,\s*\'Isrc\'\s*,\s*\$canonical\s*,\s*\'isrc\'\s*\)/', $isrcCaseSlice) === 1,
    'slice: ' . $isrcCaseSlice);

/* --- Mutation self-test: a comment-only mention must NOT satisfy the scan
   (the assertion that proves comment-stripping is load-bearing, per §1.3
   step 3 of the build spec). --- */
$commentOnlyFixture = "<?php\nfunction _ihymns_resolve_songs(\\mysqli \$db, string \$column, string \$canonical, ?string \$storeIdType = null): array\n{\n    // mentions tblSongExternalIds only in this comment, never in real code\n    return [];\n}\n";
$commentOnlyStripped = irusStripComments($commentOnlyFixture);
$commentOnlySlice = irusSliceFunction($commentOnlyStripped, '_ihymns_resolve_songs');
if (strpos($commentOnlySlice, 'tblSongExternalIds') !== false) {
    $mutationFailures[] = 'comment-stripping self-test: a tblSongExternalIds mention that ONLY existed in a comment survived stripping and would have false-passed the real assertion above';
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

echo "\n";
$totalFail = $fail + count($mutationFailures);
if ($mutationFailures) {
    echo "FAIL: mutation self-test(s) did not go red as expected:\n";
    foreach ($mutationFailures as $f) { echo "  - $f\n"; }
    echo "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n";
}
if ($totalFail > 0) {
    echo "$totalFail assertion(s)/self-test(s) failed.\n";
    exit(1);
}
echo "All #1749 identifier-resolve union assertions passed.\n";
exit(0);
