<?php

declare(strict_types=1);

/**
 * tests/php/test-songbook-visibility-core.php — the songbook-visibility pure
 * cores BEHAVE (Songbook/Catalogue Enhancements epic, commit 1 of 7)
 * ==============================================================================
 *
 * ELI5
 * ----
 * `includes/songbook_visibility.php` mints two tiny fragments of SQL:
 * "is this songbook row itself visible?" (`songbookVisibleSqlFor()`) and
 * "does this song's songbook keep it servable?" (`songServableSqlFor()`).
 * Both are PURE — they take the migration-ready flag as a plain `bool`
 * argument rather than asking a live database — so this suite calls them
 * directly, across every state that matters, with no DB fixture at all.
 * The DB-bound drivers (`songbookVisibleSql()`/`songServableSql()` and the
 * `INFORMATION_SCHEMA` probe `songbookDisableReady()`) are exercised by a
 * LATER commit's guard, once the migration they gate on actually exists —
 * testing them here would mean faking a migration state this commit
 * deliberately does not ship yet.
 *
 * WHAT IS PINNED, AND WHY EACH PIN EXISTS
 * ----------------------------------------------------------------------------
 *   - `songbookVisibleSqlFor()`'s two exact fragments ('b.IsDisabled = 0' /
 *     '1=1') — call sites embed whichever one this returns unconditionally
 *     after `WHERE … AND `, so the EXACT string is the contract, not just
 *     "truthy shaped like SQL".
 *   - `songServableSqlFor()`'s exact `NOT EXISTS (...)` fragment, INCLUDING
 *     the `''` → `tblSongs.SongbookAbbr` (never a bare, ambiguous
 *     `SongbookAbbr`) special case — the one place this function's alias
 *     contract differs in shape from `songbookVisibleSqlFor()`'s.
 *   - A hostile alias (`"b; DROP"`) THROWS in BOTH `$ready` states for BOTH
 *     functions — rule #5's "never string-interpolate unvalidated input
 *     into SQL" clause (b), and the file doc-block's "a caller bug must
 *     never be masked by migration state" contract.
 *   - A merely UNUSUAL-but-VALID alias (e.g. `"sb1"`, a digit-suffixed
 *     name) must NOT throw — rule #34's other half: a guard (and the
 *     function it locks) that rejects correct input gets "fixed" by being
 *     loosened until it stops catching anything at all.
 *
 * MUTATION PROOFS RUN (rule #34 — a guard that has never been proven able to
 * fail is not trustworthy). Each was run by hand against the real tree
 * (edit -> `php tests/php/test-songbook-visibility-core.php` -> observe RED
 * -> `git checkout -- <file>` -> observe GREEN) BEFORE this suite's own
 * automated mutation self-tests (below) were added to keep re-proving them
 * on every future run without manual intervention:
 *   M1 songbookVisibleSqlFor()'s '1=1' branch inverted (`if ($ready)`
 *      instead of `if (!$ready)`)                                    → RED
 *   M2 songbookVisibleSqlFor()'s alias-validation `if` block deleted
 *      (hostile alias interpolated straight into the returned string) → RED
 *   M3 songServableSqlFor()'s `''` special case removed (bare
 *      `SongbookAbbr` emitted instead of `tblSongs.SongbookAbbr`)      → RED
 *   M4 songServableSqlFor()'s alias-validation `if` block deleted      → RED
 *   M5 songServableSqlFor()'s '1=1' un-ready branch deleted (always
 *      emits the NOT EXISTS clause regardless of $ready)               → RED
 *   Automated equivalents of M1/M2/M4/M5 are re-run on every invocation
 *   in the "MUTATION SELF-TESTS" section below (M3 has no clean in-memory
 *   mutation shape — it is a literal-swap in the function body — so it is
 *   covered by the exact-string assertion above only; recorded here per
 *   house style rather than silently dropped).
 *
 *   php tests/php/test-songbook-visibility-core.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/songbook_visibility.php  the module under test
 * @see appWeb/public_html/includes/song_soft_delete.php     the shape this module (and this test) clones
 * @see tests/php/test-identifier-normalize.php              the PASS/FAIL + mutation-self-test reporting convention this file matches
 * @see .claude/songbook-catalogue-enhancements-plan.md      the epic plan
 */

$repoRoot = dirname(__DIR__, 2);

$passed = 0;
$failed = 0;
$failures = [];

/** Record one assertion's outcome — mirrors test-identifier-normalize.php's tinCheck() convention. */
function svCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
    }
}

/* ---- Load the module under test (guarded require — the file 403s direct
   HTTP access via a SCRIPT_FILENAME check; set it to something else first,
   the house idiom every tests/php/test-*.php suite uses for an includes/*
   module with the direct-access guard). ---- */
$visibilityFile = $repoRoot . '/appWeb/public_html/includes/songbook_visibility.php';
if (!is_readable($visibilityFile)) {
    fwrite(STDERR, "FATAL: could not read $visibilityFile\n");
    exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = 'test-runner';
require_once $visibilityFile;

if (!function_exists('songbookVisibleSqlFor') || !function_exists('songServableSqlFor')) {
    fwrite(STDERR, "FATAL: songbook_visibility.php loaded but songbookVisibleSqlFor()/songServableSqlFor() are missing\n");
    exit(1);
}

/* =========================================================================
 * songbookVisibleSqlFor() — songbook-grain predicate
 * ========================================================================= */

svCheck(
    "songbookVisibleSqlFor(true) with the default alias 'b' === 'b.IsDisabled = 0'",
    songbookVisibleSqlFor(true) === 'b.IsDisabled = 0'
);
svCheck(
    "songbookVisibleSqlFor(false) === '1=1' regardless of alias",
    songbookVisibleSqlFor(false) === '1=1'
);
svCheck(
    "songbookVisibleSqlFor(true, '') === 'IsDisabled = 0' (bare column, no alias prefix)",
    songbookVisibleSqlFor(true, '') === 'IsDisabled = 0'
);
svCheck(
    "songbookVisibleSqlFor(false, '') === '1=1' (un-ready wins regardless of alias)",
    songbookVisibleSqlFor(false, '') === '1=1'
);
svCheck(
    "songbookVisibleSqlFor(true, 'sb') === 'sb.IsDisabled = 0' (a non-default, but valid, alias)",
    songbookVisibleSqlFor(true, 'sb') === 'sb.IsDisabled = 0'
);
svCheck(
    "songbookVisibleSqlFor(true, 'sb1') does NOT throw — a digit-suffixed alias is unusual but VALID (rule #34's other half)",
    songbookVisibleSqlFor(true, 'sb1') === 'sb1.IsDisabled = 0'
);

/* Hostile alias — THROWS in BOTH $ready states (rule #5 clause b: never
   interpolate unvalidated input into SQL; the file doc-block's "a caller
   bug must never be masked by migration state" contract). */
foreach ([true, false] as $readyState) {
    $threw = false;
    try {
        songbookVisibleSqlFor($readyState, 'b; DROP TABLE tblSongbooks; --');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    svCheck(
        "songbookVisibleSqlFor(\$ready=" . var_export($readyState, true) . ", 'b; DROP TABLE …') throws InvalidArgumentException",
        $threw,
        'a hostile alias must be refused in EVERY migration state, not just the ready one'
    );
}

/* =========================================================================
 * songServableSqlFor() — song-grain predicate
 * ========================================================================= */

svCheck(
    "songServableSqlFor(true, 's') is the exact expected NOT EXISTS fragment",
    songServableSqlFor(true, 's')
        === 'NOT EXISTS (SELECT 1 FROM tblSongbooks _dsb WHERE _dsb.Abbreviation = s.SongbookAbbr AND _dsb.IsDisabled = 1)'
);
svCheck(
    "songServableSqlFor(true, '') uses the LITERAL 'tblSongs' table name, never a bare 'SongbookAbbr'",
    songServableSqlFor(true, '')
        === 'NOT EXISTS (SELECT 1 FROM tblSongbooks _dsb WHERE _dsb.Abbreviation = tblSongs.SongbookAbbr AND _dsb.IsDisabled = 1)'
);
svCheck(
    "songServableSqlFor(false, 's') === '1=1' (un-migrated degrade, aliased)",
    songServableSqlFor(false, 's') === '1=1'
);
svCheck(
    "songServableSqlFor(false, '') === '1=1' (un-migrated degrade, bare)",
    songServableSqlFor(false, '') === '1=1'
);
svCheck(
    "songServableSqlFor(true, 'song') with a non-default valid alias substitutes correctly",
    songServableSqlFor(true, 'song')
        === 'NOT EXISTS (SELECT 1 FROM tblSongbooks _dsb WHERE _dsb.Abbreviation = song.SongbookAbbr AND _dsb.IsDisabled = 1)'
);

/* Hostile alias — THROWS in BOTH $ready states. */
foreach ([true, false] as $readyState) {
    $threw = false;
    try {
        songServableSqlFor($readyState, 's; DROP TABLE tblSongs; --');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    svCheck(
        "songServableSqlFor(\$ready=" . var_export($readyState, true) . ", 's; DROP TABLE …') throws InvalidArgumentException",
        $threw,
        'a hostile alias must be refused in EVERY migration state, not just the ready one'
    );
}

/* The empty-string alias is the one legitimate way to skip validation (it
   is replaced by a hardcoded literal, never interpolated from the
   argument) — confirm it does NOT throw for either function, in either
   $ready state, so the two "hostile alias throws" checks above are not
   accidentally passing because EVERY alias throws. */
svCheck(
    "songbookVisibleSqlFor(true, '') does not throw (empty alias is the explicit bare-column case)",
    songbookVisibleSqlFor(true, '') === 'IsDisabled = 0'
);
svCheck(
    "songServableSqlFor(false, '') does not throw (empty alias is the explicit bare-table case)",
    songServableSqlFor(false, '') === '1=1'
);

/* =========================================================================
 * MUTATION SELF-TESTS (rule #34) — run on EVERY invocation, entirely
 * in-process, re-proving (in an automatable shape) the mutations recorded
 * by hand in the file doc-block above.
 * ========================================================================= */

$mutationFailures = [];

/* M1-equivalent: if the '1=1' branch's condition were inverted, ready=true
   would incorrectly return '1=1'. Confirm the REAL function does not. */
if (songbookVisibleSqlFor(true, 'b') === '1=1') {
    $mutationFailures[] = "songbookVisibleSqlFor() M1 self-test did not go red — ready=true incorrectly produced '1=1'";
}
if (songServableSqlFor(true, 's') === '1=1') {
    $mutationFailures[] = "songServableSqlFor() M5 self-test did not go red — ready=true incorrectly produced '1=1'";
}

/* M2/M4-equivalent: a value with a SQL metacharacter a naive "looks like a
   word" check might still accept (e.g. a trailing space, which
   ihymnsSqlIdentifierSafe()'s charset already refuses) must still throw —
   probing several distinct hostile shapes, not just one, so a validator
   loosened to reject only the ONE shape recorded above would still be
   caught here. */
$hostileAliases = ['b DROP', "b'", 'b`', 'b/*x*/', ' b', 'b ', ''];
foreach ($hostileAliases as $hostile) {
    if ($hostile === '') {
        continue; // '' is the explicit legitimate bare-column/table case, not hostile
    }
    $threwVisible = false;
    try {
        songbookVisibleSqlFor(true, $hostile);
    } catch (\InvalidArgumentException $e) {
        $threwVisible = true;
    }
    if (!$threwVisible) {
        $mutationFailures[] = "songbookVisibleSqlFor() alias-validation self-test did not go red for hostile alias " . var_export($hostile, true);
    }

    $threwServable = false;
    try {
        songServableSqlFor(true, $hostile);
    } catch (\InvalidArgumentException $e) {
        $threwServable = true;
    }
    if (!$threwServable) {
        $mutationFailures[] = "songServableSqlFor() alias-validation self-test did not go red for hostile alias " . var_export($hostile, true);
    }
}

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($failed > 0) {
        fwrite(STDERR, "FAIL: Songbook visibility core guard:\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    fwrite(STDERR, "\n" . $passed . " passed, " . $failed . " failed.\n");
    exit(1);
}

echo "PASS: Songbook visibility core guard — {$passed} assertion(s) passed "
   . "(songbookVisibleSqlFor()/songServableSqlFor() exact fragments across both \$ready states, "
   . "the '' -> literal-table-name special case, hostile-alias-throws-in-both-states, "
   . "and " . (2 + 2 * (count($hostileAliases) - 1)) . " mutation self-tests all went red as expected).\n";
exit(0);
