<?php

declare(strict_types=1);

/**
 * test-song-visibility-guard.php — every tblSongs read is filtered or marked (#1694)
 * =================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING GUARDED
 * ---------------------
 * Song soft delete (#1694) only works if EVERY read path hides a soft-deleted
 * song — and every read path that must NOT hide one (mint seeds, number-slot
 * occupancy, FK pre-checks, audit surfaces) says so out loud. One forgotten
 * SELECT is a silent leak: the hidden song keeps appearing on some surface with
 * no error anywhere, which is this codebase's worst failure class (rule #30's
 * silent no-op, in reverse).
 *
 * So this guard DERIVES the list from the tree (rule #34 — never a typed list):
 * every function/case unit under appWeb/public_html whose SQL contains a SELECT
 * reading tblSongs must either
 *
 *   (a) consult the ONE shared visibility predicate —
 *       `songVisibleSql(` / `songVisibleSqlFor(` / `->_visible(` /
 *       `songSoftDeleteReady(` in its CODE view — or
 *   (b) carry an explicit `@deleted-visible: <reason>` marker in its COMMENTS
 *       view, declaring the unit deliberately reads hidden rows.
 *
 * A unit with neither is exactly a new read site somebody wrote without making
 * the visibility decision, and the build fails until they make it.
 *
 * HOW IT LOOKS (and why per UNIT, not per statement or per file)
 * --------------------------------------------------------------
 * Built on tests/php/lib/php_source_units.php: its `sqlOnly` view reconstructs
 * SQL across `.` concatenation and interpolation, so the multi-line, spliced
 * statements this sweep produced ("… AND " . songVisibleSql($db, 's') . " …")
 * cannot hide from it, and a statement mentioned in PROSE cannot satisfy it.
 * The `comments` view (added for this guard) carries the markers — comments
 * are erased from `code`, so a marker can vouch for a unit while a comment can
 * still never impersonate a call. Per-unit is the honest resolution: per-file
 * vouching is the #1688 bug (one call excuses fifty handlers — though a page
 * whose work is all at file scope still collapses to one unit, a stated
 * limit), and per-statement is unattainable for SQL assembled from arrays
 * (`$where[] = $this->_visible();` filters a statement the parser can never
 * connect it to).
 *
 * WHAT THIS CANNOT CATCH (stated limits, not oversights)
 * ------------------------------------------------------
 *  - A unit that calls the helper for ONE statement and leaves a SECOND
 *    statement in the same unit unfiltered (unit-level vouching).
 *  - SQL whose table name arrives via a variable ("FROM {$table}").
 *  - Whether the predicate is spliced into the RIGHT clause (ON vs WHERE on a
 *    LEFT JOIN) — that is test-song-soft-delete.php's and the alpha
 *    rehearsal's job. No SQL here has been executed against MySQL.
 *
 * THE FLOOR (the #1701 lesson, same week)
 * ---------------------------------------
 * test-api-client-usage.js silently scanned HALF of ten files for a year —
 * its stripper lacked regex-literal state — and reported zero violations as a
 * clean pass. A scanner that under-reports is worse than none, because its
 * tick is read as coverage. So this guard asserts it FOUND at least
 * GUARD_FLOOR units reading tblSongs (>=, never ==): a parser regression
 * collapses the count toward zero and goes RED instead of green-and-empty.
 * The floor is ~90% of the count on the day of writing (111 units,
 * 2026-07-31), so an ordinary refactor that merges a couple of units does not
 * cry wolf (rule #34's other half), while a broken walker cannot survive.
 *
 * MUTATION LOG (each observed RED against the real tree, then restored —
 * method per the #1694 commit-3 brief: cp backup, diff against the backup,
 * never `git checkout --`):
 *   M1  removed `$where[] = $this->_visible();` from SongData::_searchByLike
 *       → RED (unit listed, its SELECT shown).
 *   M2  removed the `@deleted-visible` marker from songRelocateIdTaken()
 *       → RED.
 *   M3  planted a brand-new unmarked `SELECT … FROM tblSongs` function in
 *       includes/song_key.php → RED.
 *   M4  broke the unit walker (an early `return [];` in phpSourceUnits())
 *       → RED via the floor ("found only 0"), not a confident empty green.
 *   M5  GREEN survivors verified: the real tree, including every
 *       deliberately-unfiltered `@deleted-visible` site, and a correct
 *       variant spelling `$this->_visible('s')` at a filtered site.
 *   M6  (constraint interplay, asserted by test-song-redirect-claim.php, not
 *       here) widening getSongById()'s redirect-claim condition to
 *       `… || songSoftDeletedHolds(…)` instead of the separate `if` turned
 *       THAT guard red — observed, then restored — proving the separate-`if`
 *       shape this sweep used was load-bearing, not stylistic.
 *
 * @see appWeb/public_html/includes/song_soft_delete.php  the ONE predicate
 * @see tests/php/lib/php_source_units.php                the shared walker
 * @see tests/php/test-component-json-guard.php           the sibling gated-column guard
 * @see .claude/plan-song-soft-delete-2026-07-31.md       §2 (inventory) + §5 (this guard)
 */

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/php_source_units.php';

/* The one file allowed to read tblSongs.IsDeleted raw: the predicate's own
   home (songSoftDeletedHolds(), the write cores' row-state reads). A single,
   high-scrutiny entry — the component-json guard's allowlist shape. */
$allowFiles = ['appWeb/public_html/includes/song_soft_delete.php'];

/* Tokens in the CODE view that prove the unit made the visibility decision.
   `->_visible(` is SongData's private delegate (the arrow keeps a hypothetical
   unrelated global `something_visible()` from vouching by suffix-accident). */
$vouchCode = ['songVisibleSql(', 'songVisibleSqlFor(', '->_visible(', 'songSoftDeleteReady('];

/* The marker convention for deliberately-unfiltered units (mint seeds,
   number-slot occupancy, FK pre-checks, audit/compliance surfaces, resolvers,
   migration probes). Lives in COMMENTS — see the file header for why. */
const GUARD_MARKER = '@deleted-visible:';

/* FLOOR: 100 = ~90% of the 111 units found on 2026-07-31. `>=`, never `==`.
   If a legitimate refactor drops the live count below this, lower it HERE,
   consciously, in the same commit — do not weaken the detection to compensate. */
const GUARD_FLOOR = 100;

/**
 * Does this reconstructed SQL statement READ tblSongs?
 *
 * ELI5: "is there a SELECT in here that pulls rows out of the songs table?"
 *
 * Anchored on FROM/JOIN so a write (`UPDATE tblSongs SET …`, `DELETE FROM
 * tblSongs` with no SELECT, `INSERT INTO tblSongs … VALUES`) does not count —
 * writes are deliberately visibility-blind (#1694: writing a hidden row is
 * harmless and restore-preserving). An `UPDATE tblSongbooks … (SELECT COUNT(*)
 * FROM tblSongs …)` DOES count: the subquery is a read (the SongCount
 * recomputes are exactly this shape and had to be filtered under D1).
 * Normalised first so backticks / line breaks cannot hide the table name
 * (phpUnitsNormaliseSql — the api2 backtick lesson in the lib's doc-block).
 */
function guardSqlReadsSongs(string $sql): bool
{
    $norm = phpUnitsNormaliseSql($sql);
    return preg_match('/\b(FROM|JOIN)\s+tblSongs\b/i', $norm) === 1
        && preg_match('/\bSELECT\b/i', $norm) === 1;
}

/* ---- collect the tree (derived, never typed — rule #34) ------------------ */
$scanRoot = $root . '/appWeb/public_html';
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: $scanRoot not found\n");
    exit(1);
}
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$violations = [];
$readerUnits = 0;   // units found reading tblSongs — the floor's subject
$scanned     = 0;

foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    if (in_array($rel, $allowFiles, true)) {
        continue;
    }
    $src = file_get_contents($file);
    if ($src === false) {
        /* A harness failure must never be printed as a clean result (the
           four-times-on-this-branch lesson): an unreadable file is a FAIL. */
        $violations[] = "$rel  <unreadable — harness failure, not a scan result>";
        continue;
    }
    $scanned++;
    foreach (phpSourceUnits($src) as $unitName => $unit) {
        $offending = null;
        foreach ($unit['sqlOnly'] as $sql) {
            if (guardSqlReadsSongs($sql)) {
                $offending = $sql;
                break;
            }
        }
        if ($offending === null) {
            continue;                       // unit does not read tblSongs
        }
        $readerUnits++;

        /* (a) the code made the visibility decision … */
        $vouched = false;
        foreach ($vouchCode as $tok) {
            if (strpos($unit['code'], $tok) !== false) {
                $vouched = true;
                break;
            }
        }
        /* (b) … or a marker declares the unit deliberately unfiltered. */
        if (!$vouched && strpos(implode("\n", $unit['comments']), GUARD_MARKER) !== false) {
            $vouched = true;
        }
        if (!$vouched) {
            $snippet = trim((string)preg_replace('/\s+/', ' ', $offending));
            if (strlen($snippet) > 140) {
                $snippet = substr($snippet, 0, 140) . '…';
            }
            $violations[] = sprintf("%s :: %s\n      %s", $rel, $unitName, $snippet);
        }
    }
}

/* ---- verdicts ------------------------------------------------------------ */
$fail = 0;

if ($violations) {
    $fail++;
    fwrite(STDERR, "FAIL: tblSongs read site(s) with NO visibility decision (#1694):\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  $v\n");
    }
    fwrite(STDERR, "\nEvery unit whose SQL SELECTs from tblSongs must either embed the shared\n");
    fwrite(STDERR, "predicate (songVisibleSql() / SongData::_visible() — degrades to '1=1'\n");
    fwrite(STDERR, "un-migrated, so there is no cost argument) or carry an explicit\n");
    fwrite(STDERR, "`@deleted-visible: <reason>` comment saying WHY hidden rows must stay\n");
    fwrite(STDERR, "visible to it (mint seed / slot occupancy / FK pre-check / audit / …).\n");
    fwrite(STDERR, "See .claude/plan-song-soft-delete-2026-07-31.md §2 for the worked taxonomy.\n");
}

if ($readerUnits < GUARD_FLOOR) {
    $fail++;
    fwrite(STDERR, sprintf(
        "\nFAIL: scanner under-report — found only %d tblSongs-reading unit(s), floor is %d.\n"
        . "Either the unit walker / SQL reconstruction broke (fix it — do NOT lower the floor\n"
        . "for this), or a refactor genuinely reduced the count (then lower GUARD_FLOOR\n"
        . "consciously in the same commit). A guard that scans nothing and prints green is\n"
        . "worse than no guard (#1701).\n",
        $readerUnits,
        GUARD_FLOOR
    ));
}

if ($fail > 0) {
    exit(1);
}
printf(
    "PASS: %d tblSongs-reading unit(s) across %d scanned file(s) — every one filtered or marked (floor %d).\n",
    $readerUnits,
    $scanned,
    GUARD_FLOOR
);
exit(0);
