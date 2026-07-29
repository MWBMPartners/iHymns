<?php
/**
 * iHymns — Migration Registry Sanity Test
 *
 * Guards against the regressions seen during the
 * `claude/fix-pending-migrations-Vj4SQ` investigation:
 *
 *   1. A migration is appended to the registry but the entry is
 *      missing one of the three required facets (script / card /
 *      probe) → the dashboard renders inconsistently. Hard to spot
 *      because PHP's array access of a missing key is a NULL warning
 *      at runtime, not a load error.
 *
 *   2. A probe is added as `static fn(\mysqli $db) => true` because
 *      writing a smart probe was deferred → the migration shows as
 *      pending forever even after running, preventing the "Apply all
 *      pending" counter from reaching zero.
 *
 *   3. A migration is added directly to `$scriptMap` /
 *      `$migrationOrder` etc. instead of into the structured
 *      registry → coverage is inconsistent across the four legacy
 *      arrays the page derives from the registry.
 *
 * All three regressions are silent at runtime — the page renders
 * fine and each migration is individually runnable. The signal only
 * surfaces to the curator as "the count never drops" / "the alert
 * never flips to Schema fully up-to-date". This test catches all
 * three at CI time.
 *
 *   php tests/php/test-migration-registry.php
 *
 * Exit status 0 = all assertions pass, 1 = at least one failure.
 */

declare(strict_types=1);

$registryFile = dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/migration-registry.php';
$setupFile    = dirname(__DIR__, 2) . '/appWeb/public_html/manage/setup-database.php';

if (!is_readable($registryFile)) {
    fwrite(STDERR, "FATAL: could not read $registryFile\n");
    exit(1);
}
if (!is_readable($setupFile)) {
    fwrite(STDERR, "FATAL: could not read $setupFile\n");
    exit(1);
}

$registrySrc = (string)file_get_contents($registryFile);
$setupSrc    = (string)file_get_contents($setupFile);

$failures = 0;

/* ---------------------------------------------------------------------- *
 * Load the registry into memory so we can introspect it.
 *
 * The registry's probe closures reference helper functions
 * (_migProbe_tableExists etc.) that live in setup-database.php — we
 * stub them so the require evaluates without erroring. We also stub
 * $hasCredentials because the IANA card's extra_html block reads it.
 * Closures themselves are lazily evaluated, so we don't need a real
 * mysqli instance — we never invoke the closures here.
 * ---------------------------------------------------------------------- */
function _migProbe_tableExists(\mysqli $db, string $t): bool { return false; }
function _migProbe_columnExists(\mysqli $db, string $t, string $c): bool { return false; }
function _migProbe_columnIsNullable(\mysqli $db, string $t, string $c): bool { return false; }
function _migProbe_triggerExists(\mysqli $db, string $t): bool { return false; }
$hasCredentials = true;
/* Bypass the registry file's direct-access guard. */
$_SERVER['SCRIPT_FILENAME'] = '/different.php';

$MIGRATIONS = require $registryFile;

if (!is_array($MIGRATIONS) || !$MIGRATIONS) {
    fwrite(STDERR, "FAIL: registry file did not return a non-empty array.\n");
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * Assertion 1 — every entry has all three required facets.
 *
 * The structured registry shape requires `script`, `card`, and `probe`
 * for every migration. A missing key would cause the dashboard to render
 * with a missing button label / undefined card content, or treat the
 * slug as "no probe = pending forever".
 * ---------------------------------------------------------------------- */
$incomplete = [];
foreach ($MIGRATIONS as $slug => $entry) {
    if (!is_array($entry)) {
        $incomplete[] = "$slug (entry isn't an array)";
        continue;
    }
    $missing = [];
    foreach (['script', 'card', 'probe'] as $facet) {
        if (!array_key_exists($facet, $entry)) $missing[] = $facet;
    }
    if ($missing) $incomplete[] = "$slug (missing: " . implode(', ', $missing) . ')';
}
if ($incomplete) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($incomplete) . " registry entry(ies) missing required facets:\n");
    foreach ($incomplete as $e) fwrite(STDERR, "  - $e\n");
} else {
    echo "PASS: every registry entry has script + card + probe (" . count($MIGRATIONS) . " entries)\n";
}

/* ---------------------------------------------------------------------- *
 * Assertion 2 — every card has the three required sub-keys.
 * ---------------------------------------------------------------------- */
$badCards = [];
foreach ($MIGRATIONS as $slug => $entry) {
    if (!is_array($entry['card'] ?? null)) {
        $badCards[] = "$slug (card isn't an array)";
        continue;
    }
    $missing = [];
    foreach (['title', 'body', 'button'] as $sub) {
        if (!array_key_exists($sub, $entry['card'])) $missing[] = $sub;
    }
    if ($missing) $badCards[] = "$slug (card missing: " . implode(', ', $missing) . ')';
}
if ($badCards) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($badCards) . " card(s) missing required sub-keys:\n");
    foreach ($badCards as $e) fwrite(STDERR, "  - $e\n");
} else {
    echo "PASS: every card has title + body + button (" . count($MIGRATIONS) . " cards)\n";
}

/* ---------------------------------------------------------------------- *
 * Assertion 3 — every probe is a Closure, never the always-true literal.
 *
 * The literal `static fn(\mysqli $db) => true` was the original bug — five
 * probes hardcoded to always report their migration as pending, which
 * made the "Apply all pending" counter impossible to drive to zero.
 * Catching the literal here means any future "I'll write a smart probe
 * later" placeholder fails CI immediately rather than silently
 * re-introducing the regression.
 *
 * Detection works at the source level: scan the registry file for the
 * canonical literal pattern. The smart-probe form (multi-line `static
 * function (\mysqli $db): bool { … }`) doesn't match.
 * ---------------------------------------------------------------------- */
$nonClosure = [];
foreach ($MIGRATIONS as $slug => $entry) {
    if (!($entry['probe'] ?? null) instanceof \Closure) {
        $nonClosure[] = $slug;
    }
}
if ($nonClosure) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($nonClosure) . " probe(s) are not Closures:\n");
    foreach ($nonClosure as $s) fwrite(STDERR, "  - $s\n");
} else {
    echo "PASS: every probe is a Closure\n";
}

if (preg_match_all(
    "/'probe'\s*=>\s*static\s+fn\s*\(\s*\\\\?mysqli\s+\\\$\w+\s*\)\s*=>\s*true\s*,/m",
    $registrySrc,
    $matches
)) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($matches[0]) . " probe(s) hardcoded to always-pending (=> true).\n");
    fwrite(STDERR, "Always-true probes mean the migration shows as pending forever and the\n");
    fwrite(STDERR, "\"Apply all pending\" counter can never reach 0. Either write a smart\n");
    fwrite(STDERR, "probe that detects completion from the live schema/data, or have the\n");
    fwrite(STDERR, "migration write a sentinel row in tblAppSettings and check that.\n");
} else {
    echo "PASS: no probe is hardcoded to always-pending\n";
}

/* ---------------------------------------------------------------------- *
 * Assertion 4 — setup-database.php derives the four legacy arrays from
 * the registry instead of declaring them inline.
 *
 * Detection: the file should NOT contain top-level array literals named
 * $migrationOrder, $migrationCards, or $migrationProbes (those are
 * derived). A standalone $scriptMap is OK because it carries the
 * installer-only entries; we just check there's a `require` of the
 * registry above the derivation block.
 * ---------------------------------------------------------------------- */
$leakedLegacy = [];
foreach (['migrationOrder', 'migrationCards', 'migrationProbes'] as $var) {
    /* Match `$var = [` followed by anything OTHER than a closing `]`
       on the same line. The derivation pattern uses `$var = [];` (empty
       array initialiser before the foreach populates it) which is
       allowed; an inline registry uses `$var = [` then content on
       subsequent lines, which is not. */
    if (preg_match('/^\$' . $var . '\s*=\s*\[(?!\s*\];)/m', $setupSrc)) {
        $leakedLegacy[] = $var;
    }
}
if ($leakedLegacy) {
    $failures++;
    fwrite(STDERR, "FAIL: setup-database.php declares legacy array(s) inline instead of deriving from \$MIGRATIONS:\n");
    foreach ($leakedLegacy as $v) fwrite(STDERR, "  - \$$v\n");
    fwrite(STDERR, "Add new migrations to includes/migration-registry.php instead — the derivation\n");
    fwrite(STDERR, "block at the bottom of the registry-load section populates these from \$MIGRATIONS.\n");
} else {
    echo "PASS: setup-database.php derives the legacy arrays from \$MIGRATIONS (no inline declarations)\n";
}

if (!preg_match('/require\s+__DIR__\s*\.\s*[^;]*migration-registry\.php/m', $setupSrc)) {
    $failures++;
    fwrite(STDERR, "FAIL: setup-database.php does not appear to require the migration registry.\n");
} else {
    echo "PASS: setup-database.php requires includes/migration-registry.php\n";
}

/* ---------------------------------------------------------------------- *
 * Assertion 5 — no probe interpolates a superglobal into SQL.
 *
 * Any value entering a SQL string MUST be bound via $stmt->bind_param —
 * never string-interpolated. The most common SQL-injection vector is
 * concatenating a $_GET / $_POST / $_REQUEST / $_COOKIE / $_SERVER /
 * $_FILES value directly into a query. This regex catches the obvious
 * shapes: `query("..." . $_X[...]` and `query("...{$_X[...]}...")` and
 * `prepare("...{$_X[...]}...")` (prepare with interpolation defeats
 * the binding). Hardcoded `{$col}` interpolation from a fixed PHP
 * array is allowed — the regex specifically targets superglobals.
 * ---------------------------------------------------------------------- */
$sqlInjectionPatterns = [
    /* Concatenation: query("..." . $_GET["x"]) */
    '/->(query|prepare|real_query)\s*\([^)]*\.\s*\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/i',
    /* Curly interpolation inside a string literal: query("...{$_GET[...]}...") */
    '/->(query|prepare|real_query)\s*\(\s*"[^"]*\{\s*\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/i',
];
$hits = [];
foreach ($sqlInjectionPatterns as $p) {
    if (preg_match_all($p, $registrySrc, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $line = substr_count(substr($registrySrc, 0, $hit[1]), "\n") + 1;
            $hits[] = "  - line $line: " . trim($hit[0]);
        }
    }
}
if ($hits) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($hits) . " probe(s) interpolate a superglobal into SQL — bind via \$stmt->bind_param() instead:\n");
    foreach ($hits as $h) fwrite(STDERR, "$h\n");
} else {
    echo "PASS: no probe interpolates a superglobal into SQL\n";
}

/* ----------------------------------------------------------------------
 * Assertion 8 — every entry's `script` exists on disk (#1642).
 *
 * ELI5: the dashboard lists a migration by naming a file. Check the file
 * is actually there.
 *
 * WHY THIS EXISTS. The owner hit "Apply all pending migrations" on dev and
 * got `Script not found: migrate-apple-phase2-live-schema.php` — and because
 * "Apply all" stops on the FIRST hard failure, that one missing file blocked
 * EVERY migration from being applied through the dashboard. Not one card
 * failing: the whole mechanism.
 *
 * That particular case was a DEPLOY gap (the file is in git, it just never
 * reached the docroot), which no CI check can see from here. But the sibling
 * case is entirely catchable and was equally unguarded: a registry entry
 * naming a script that was renamed, moved, or never written. Same operator
 * symptom, same total blockage, and a `git grep` away from being impossible.
 *
 * The registry had 125 entries and nothing verified that any of the named
 * files existed.
 * ---------------------------------------------------------------------- */
$sqlDir  = dirname(__DIR__, 2) . '/appWeb/.sql/';
$missing = [];
foreach ($MIGRATIONS as $slug => $entry) {
    $script = is_array($entry) ? (string)($entry['script'] ?? '') : '';
    if ($script === '') {
        continue;   /* Assertion 1 already reports a missing `script` facet. */
    }
    if (!is_file($sqlDir . $script)) {
        $missing[] = "  - '$slug' names '$script', which does not exist in appWeb/.sql/";
    }
}
if ($missing) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($missing) . " registry entr(ies) name a script that is not on disk.\n");
    fwrite(STDERR, "      'Apply all' stops on the first hard failure, so ONE of these blocks every migration:\n");
    foreach ($missing as $m) fwrite(STDERR, "$m\n");
} else {
    echo 'PASS: every registry entry\'s script exists in appWeb/.sql/ (' . count($MIGRATIONS) . " entries)\n";
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures assertion(s) failed.\n");
    exit(1);
}
echo "\nAll migration-registry assertions passed.\n";
exit(0);
