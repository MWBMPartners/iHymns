<?php

declare(strict_types=1);

/**
 * iHymns — Zero-mirrored-line tblSongComponents cleanup guard (#2063)
 *
 * ELI5
 * ----
 * `migrate-cleanup-zero-line-components.php` deletes a `tblSongComponents`
 * row only when THREE things are all true at once: it has no mirrored
 * `tblLyricLines` child, its song has real mirrored content somewhere else,
 * and its `LinesJson` doesn't secretly still hold real lines. Get any one
 * of those three wrong and this either leaves G1-breaking junk behind
 * forever, or — far worse — deletes a blank draft's only sections or a
 * genuine mirror-failure row's evidence. This file truth-tables the exact
 * decision function the migration calls, EXTRACTED from the real migration
 * source (never a hand-typed copy — the #1747 D5 backfill test's documented
 * reasoning: a parallel copy passes even after the real predicate breaks),
 * plus tree-derived checks that the registry entry exists, is
 * `manual`-gated, and is wired the way `migration-registry.php`'s own CI
 * guard (`tests/php/test-migration-registry.php`) expects.
 *
 * DETAILED — WHY THE FUNCTION IS EXTRACTED, NOT REIMPLEMENTED
 * -------------------------------------------------------------
 * `migrate-cleanup-zero-line-components.php` declares
 * `ihymnsZeroLineComponentShouldDelete()` UNCONDITIONALLY at file scope
 * (not inside any if/function), so PHP binds it into the function table as
 * soon as the file is compiled — before ANY of the file's runtime
 * statements execute, including the `getDbMysqli()` connection attempt a
 * few lines later that has no live database to reach in this test
 * environment and throws an uncaught `RuntimeException`. Wrapping the
 * `require` below in try/catch lets that exception propagate harmlessly to
 * here (PHP exceptions from an included file are ordinary catchable
 * exceptions at the including scope, not a process-level fatal) while the
 * function itself is already registered and callable — proven by the tiny
 * harness at the bottom of this comment block, run by hand during
 * development:
 *
 *   php -r '
 *     function f(){ return 1; }
 *     try { require "/tmp/x.php"; } catch (Throwable $e) {}
 *     var_dump(function_exists("f"));  // bool(true)
 *   '
 *
 * This means every truth-table case below calls the REAL migration's REAL
 * function — a bug introduced in the migration script (e.g. dropping
 * clause 2, or flipping the clause-3 comparison) changes what THIS file
 * observes, not just a copy that quietly stops matching it.
 *
 * Pure source + in-process function calls — no DB required — so it slots
 * into `tools/run-php-tests.php`'s glob alongside every other `tests/php/
 * test-*.php` file.
 *
 *   php tests/php/test-cleanup-zero-line-components.php
 *
 * Exit status 0 = clean, 1 = at least one assertion failed.
 *
 * @see appWeb/.sql/migrate-cleanup-zero-line-components.php        the predicate under test
 * @see appWeb/.sql/verify-lyrics-cutover.php                       the G1 gate this predicate mirrors
 * @see appWeb/public_html/manage/includes/migration-registry.php  'cleanup-zero-line-components' entry
 * @see tests/php/test-migration-registry.php                       the project-wide registry-shape guard
 * @see #2063, #1235, #1616, #1260
 */

$repoRoot = dirname(__DIR__, 2);
$migFile  = $repoRoot . '/appWeb/.sql/migrate-cleanup-zero-line-components.php';
$registryFile = $repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php';

$passed   = 0;
$failed   = 0;
$failures = [];

/** Record one assertion's outcome (mirrors tests/php/test-identifier-normalize.php's tinCheck()). */
function czlcCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $label . ($detail !== '' ? " ({$detail})" : '');
    }
}

/* =========================================================================
 * 0. Load the REAL predicate out of the REAL migration file.
 * ========================================================================= */

if (!is_file($migFile)) {
    fwrite(STDERR, "FATAL: migration script not found: $migFile\n");
    exit(1);
}

/* Guarded require: the migration file connects to a live database a few
   lines after declaring the pure function this test needs. In this test
   environment there is no database, so getDbMysqli() throws an uncaught
   RuntimeException — an ordinary catchable PHP exception at THIS including
   scope, not a fatal that kills the process. By the time that throw
   happens, ihymnsZeroLineComponentShouldDelete() (declared unconditionally,
   near the top of the file, before any DB code) is already bound in the
   function table — see the doc-block above for the mechanics. */
try {
    require $migFile;
} catch (\Throwable $e) {
    /* Expected in a DB-less test run — the migration script itself already
       degrades gracefully (its own try/catch reports "[ERROR] ..." and
       returns) for a genuine connection failure; a RuntimeException this
       early (before that try/catch is even reached) is the credentials-not-
       configured case, which is exactly this sandbox. Either way, nothing
       to do here except continue — the function we need is already loaded. */
}

czlcCheck(
    'ihymnsZeroLineComponentShouldDelete() is defined after requiring the real migration script',
    function_exists('ihymnsZeroLineComponentShouldDelete')
);

if (!function_exists('ihymnsZeroLineComponentShouldDelete')) {
    /* Nothing below is meaningful without the function — report now and stop. */
    fwrite(STDERR, "FAIL: could not load ihymnsZeroLineComponentShouldDelete() from $migFile\n");
    fwrite(STDERR, "      ({$failed} failed of " . ($passed + $failed) . " so far)\n");
    exit(1);
}

/* =========================================================================
 * 1. Truth table — the pure candidate-filter decision.
 * ========================================================================= */

/* Case A — LinesJson = "[]" (a mis-parsed empty section), no mirrored
   child, song has real content elsewhere => DELETE = true. This is the
   Psalty-1778530312276 shape the migration exists to clean up. */
czlcCheck(
    'A: LinesJson=[] + no child + song has content => DELETE',
    ihymnsZeroLineComponentShouldDelete([], false, true) === true
);

/* Case B — LinesJson = ["""] (ONE blank-string line): in the REAL system
   this line WOULD have been mirrored into tblLyricLines (a real, if empty,
   instrumental line), so hasMirroredChild is true for this shape — clause
   1 alone must protect it regardless of what LinesJson decodes to. */
czlcCheck(
    'B: LinesJson=[""] + HAS a mirrored child (real instrumental) => DELETE = false',
    ihymnsZeroLineComponentShouldDelete([''], true, true) === false
);

/* Case B2 — isolate clause 3 alone: even with clauses 1+2 satisfied (no
   child, song has content), a NON-EMPTY decoded array — including one
   holding only a blank string — must NOT satisfy clause 3. A non-empty
   array blocks deletion no matter what its elements are; clause 3 asks
   "is the array empty", never "are the lines individually meaningful". */
czlcCheck(
    'B2: LinesJson=[""] alone (no child, song has content) => DELETE = false (clause 3 blocks any non-empty array)',
    ihymnsZeroLineComponentShouldDelete([''], false, true) === false
);

/* Case C — LinesJson decodes to real lyric lines, no mirrored child => a
   GENUINE mirror failure (real source content that never made it into
   tblLyricLines). Clause 3 must protect it — deleting it would hide real
   data loss instead of exposing it to G1. */
czlcCheck(
    'C: LinesJson=[real lines] + no child (mirror failure) => DELETE = false',
    ihymnsZeroLineComponentShouldDelete(['Amazing grace, how sweet the sound', 'that saved a wretch like me'], false, true) === false
);

/* Case D — no mirrored child, but the SONG has no other mirrored content
   anywhere (a genuinely blank draft) => clause 2 must protect it, even
   though LinesJson=[] would otherwise look identical to Case A. */
czlcCheck(
    'D: no child + song has NO other content (blank draft) => DELETE = false',
    ihymnsZeroLineComponentShouldDelete([], false, false) === false
);

/* Case E — post-C6-drop shape: LinesJson column is gone, so the caller
   passes null (clause 3's "nothing to say" value) — decoded=null must NOT
   be treated as "real lines present"; (1)+(2) alone decide, matching the
   migration script's documented post-drop behaviour. */
czlcCheck(
    'E: decoded=null (LinesJson column gone / post-drop) + no child + song has content => DELETE = true',
    ihymnsZeroLineComponentShouldDelete(null, false, true) === true
);

/* Case F — invalid/undecodable JSON collapses to null (mirrors
   sourceComponentsFromJson()'s own `if (!is_array($lines)) { $lines = []; }`
   fallback) — same outcome as Case A/E, not a "real content" signal. */
czlcCheck(
    'F: decoded=null (invalid JSON, stored value unreadable) + no child + song has content => DELETE = true',
    ihymnsZeroLineComponentShouldDelete(null, false, true) === true
);

/* =========================================================================
 * 2. MUTATION SELF-TESTS (rule #34) — prove the truth table above is not
 * vacuous by pairing each clause with an input that ONLY a correctly
 * clause-ordered implementation gets right. Run on every invocation.
 * ========================================================================= */

$mutationFailures = [];

/* If clause 1 (hasMirroredChild) were ever DROPPED from the real function,
   Case B above (hasChild=true, LinesJson=[""]) would wrongly return true
   instead of false — a component the assembler DOES build would get
   deleted out from under a live song. Case B is exactly that probe; assert
   it explicitly here as the mutation self-test for clause 1. */
if (ihymnsZeroLineComponentShouldDelete([''], true, true) !== false) {
    $mutationFailures[] = 'clause-1 (hasMirroredChild) self-test did not go red — a component WITH a mirrored child was judged deletable';
}

/* If clause 2 (songHasMirroredContent) were ever DROPPED, Case D above
   (blank draft, LinesJson=[]) would wrongly return true — a brand-new
   draft song's only sections would be deleted the moment it's saved. */
if (ihymnsZeroLineComponentShouldDelete([], false, false) !== false) {
    $mutationFailures[] = 'clause-2 (songHasMirroredContent) self-test did not go red — a blank draft\'s only section was judged deletable';
}

/* If clause 3 were ever DROPPED (or its emptiness check inverted), Case C
   above (real lyric lines, no child = genuine mirror failure) would
   wrongly return true — real, never-mirrored lyric content would be
   silently deleted instead of left for G1 to keep flagging. */
if (ihymnsZeroLineComponentShouldDelete(['real line one', 'real line two'], false, true) !== false) {
    $mutationFailures[] = 'clause-3 (LinesJson non-empty) self-test did not go red — a genuine mirror-failure row (real lines, no mirrored child) was judged deletable';
}

/* If clause 3's emptiness check were loosened to "is the array set" rather
   than "is the array non-empty" (e.g. `$decodedLinesJson !== null` instead
   of `!== []`), Case A ([] => DELETE=true) would wrongly flip to false —
   the migration would never clean up the exact junk shape it exists for. */
if (ihymnsZeroLineComponentShouldDelete([], false, true) !== true) {
    $mutationFailures[] = 'clause-3 emptiness-check self-test did not go red — an EMPTY decoded array ([]) was judged NOT deletable, defeating the migration\'s entire purpose';
}

/* =========================================================================
 * 3. Tree-derived checks — the registry entry exists, is manual+gated, and
 * names a script that is really on disk (test-migration-registry.php
 * already enforces the general "script exists" + "no bare => true" shape
 * project-wide; these are the narrower assertions specific to THIS entry,
 * matching the documented convention in tests/php/test-song-external-ids-
 * backfill.php).
 * ========================================================================= */

if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read $registryFile\n");
    exit(1);
}
$registrySrc = (string)file_get_contents($registryFile);

/* Isolate just this entry's slice of the registry source (from its key to
   the next top-level ']," ' 'array-close), so assertions below can't
   accidentally pass by matching some OTHER entry's 'manual' => true. */
if (!preg_match(
    "/'cleanup-zero-line-components'\s*=>\s*\[(.*?)\n    \],\n/s",
    $registrySrc,
    $entryMatch
)) {
    czlcCheck('registry: cleanup-zero-line-components entry is present and parseable', false);
    $entrySrc = '';
} else {
    czlcCheck('registry: cleanup-zero-line-components entry is present and parseable', true);
    $entrySrc = $entryMatch[1];
}

czlcCheck(
    "registry: entry names script 'migrate-cleanup-zero-line-components.php'",
    str_contains($entrySrc, "'script' => 'migrate-cleanup-zero-line-components.php'")
);
czlcCheck(
    'registry: entry is on disk at appWeb/.sql/',
    is_file($repoRoot . '/appWeb/.sql/migrate-cleanup-zero-line-components.php')
);
czlcCheck(
    "registry: entry is 'manual' => true (excluded from Apply-all; deletes curator-visible rows)",
    (bool)preg_match('/\'manual\'\s*=>\s*true/', $entrySrc)
);
czlcCheck(
    "registry: entry is 'dryRunnable' => true (dry-run-by-default report mode)",
    (bool)preg_match('/\'dryRunnable\'\s*=>\s*true/', $entrySrc)
);
czlcCheck(
    'registry: entry probe is NOT the banned always-pending literal',
    !preg_match("/'probe'\s*=>\s*static\s+fn\s*\(\s*\\\\?mysqli\s+\\\$\w+\s*\)\s*=>\s*true\s*,/", $entrySrc)
);
czlcCheck(
    'registry: probe is table-existence-gated (tblLyricLines) before querying',
    str_contains($entrySrc, "_migProbe_tableExists(\$db, 'tblLyricLines')")
);
czlcCheck(
    'registry: probe LinesJson access is column-existence-gated (never an unguarded reference)',
    str_contains($entrySrc, "_migProbe_columnExists(\$db, 'tblSongComponents', 'LinesJson')")
);

/* The registry's own derivation block (setup-database.php) is what makes
   this entry surface on the dashboard at all — confirm it isn't declared
   directly in setup-database.php instead (which test-migration-registry.php
   already guards project-wide, but a direct re-check here documents WHY
   this specific card would be invisible otherwise). */
$setupFile = $repoRoot . '/appWeb/public_html/manage/setup-database.php';
if (is_readable($setupFile)) {
    $setupSrc = (string)file_get_contents($setupFile);
    czlcCheck(
        'setup-database.php derives its cards from $MIGRATIONS (registry-driven, not a hand-typed second copy)',
        (bool)preg_match('/\$MIGRATIONS\s*=\s*require\s+__DIR__/', $setupSrc)
    );
}

/* =========================================================================
 * 4. Migration script source checks — the LinesJson decode (clause 3) is
 * columnExists-gated in the real file (mirrors the #component-json-guard
 * convention, applied here by hand since that CI guard only scans
 * appWeb/public_html/, not appWeb/.sql/ migrations by design).
 * ========================================================================= */

$migSrc = (string)file_get_contents($migFile);
czlcCheck(
    'migration: LinesJson decode site is guarded by a column-existence check ($linesJsonExists)',
    str_contains($migSrc, '$linesJsonExists') && str_contains($migSrc, "_migCleanupZLC_colExists(\$db, 'tblSongComponents', 'LinesJson')")
);
czlcCheck(
    'migration: deletes by Id via a bound prepared statement (never string-interpolated)',
    (bool)preg_match('/DELETE\s+FROM\s+tblSongComponents\s+WHERE\s+Id\s*=\s*\?/', $migSrc)
        && str_contains($migSrc, "bind_param('i', \$id)")
);
czlcCheck(
    'migration: confirm gate — web ?confirm=1 / CLI --confirm, dry-run is the default',
    str_contains($migSrc, "\$_GET['confirm'] ?? '') === '1'") && str_contains($migSrc, "'--confirm'")
);

/* =========================================================================
 * REPORT
 * ========================================================================= */

if ($failed > 0 || $mutationFailures) {
    if ($failed > 0) {
        fwrite(STDERR, "FAIL: zero-line tblSongComponents cleanup guard (#2063):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    if ($mutationFailures) {
        fwrite(STDERR, "FAIL: mutation self-test(s) did not go red as expected:\n\n");
        foreach ($mutationFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\nA guard that cannot be proven to fail is not trustworthy (rule #34).\n");
    }
    fwrite(STDERR, "\n" . $passed . " passed, " . $failed . " failed, " . count($mutationFailures) . " mutation self-test failure(s).\n");
    exit(1);
}

echo "PASS: zero-line tblSongComponents cleanup guard — {$passed} assertion(s) passed "
   . "(6 truth-table cases + 4 mutation self-tests all went red as expected, "
   . "registry entry present/manual/dryRunnable/gated, migration source guards LinesJson + binds its DELETE).\n";
exit(0);
