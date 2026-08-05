<?php

declare(strict_types=1);

/**
 * test-songbook-visibility-guard.php — every songbook-grain / disabled-book
 * read site is filtered or marked (#1765 Feature 1, epic build sequence
 * commit 3 of 7)
 * =================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING GUARDED
 * ---------------------
 * "Disable a songbook" (#1765 Feature 1) only works if EVERY read path hides a
 * disabled songbook AND every song that lives inside it — and every read path
 * that must NOT hide either (the /manage/* admin surfaces, which must keep
 * seeing a disabled book so a curator can edit or re-enable it; SongCount
 * recomputes, which are disable-invariant; the songs_exist prune-safety
 * endpoint) says so out loud. This is the SAME shape as #1694's song
 * soft-delete guard (`test-song-visibility-guard.php`), cloned deliberately
 * (CLAUDE.md's modularity rule + the `songbook_visibility.php` file
 * doc-block's "reuse the shape, don't invent a second one"): a shared unit
 * walker, a vouch-token allowlist, a marker convention, a tree-derived floor.
 *
 * TWO DISTINCT QUESTIONS, ONE GUARD (mirrors `songbook_visibility.php`'s own
 * two-predicate split):
 *   (a) SONGBOOK-GRAIN — any unit whose SQL reads `FROM|JOIN tblSongbooks`
 *       must know about `songbookVisibleSql()` (or be marked). Scanned
 *       TREE-WIDE, no restriction — a raw `tblSongbooks` read anywhere
 *       (admin included) is a deliberate decision point, because #1765's
 *       admin contract is "sees everything", not "the code never asks the
 *       question".
 *   (b) SONG-GRAIN — any unit whose SQL reads `FROM|JOIN tblSongs` (the
 *       PARENT guard's own `guardSqlReadsSongs()` detector, reused verbatim
 *       so the two guards can never disagree about what counts as "a tblSongs
 *       read") must know about `songServableSql()` (or be marked). This
 *       reuses the parent guard's full tree-wide census on purpose: the same
 *       ~111 units #1694 already made a soft-delete visibility decision about
 *       are exactly the units that now also need a #1765 disabled-songbook
 *       decision — the two concerns travel together (a hidden-by-delete song
 *       and a hidden-by-disabled-book song are the same shape of "the row
 *       exists but must not appear"), so re-deriving a NARROWER "public
 *       responder only" subset would be a second, harder-to-justify line to
 *       maintain for no real safety gain — the admin/exempt sites take the
 *       `@disabled-visible:` marker route below instead of being excluded
 *       from the scan entirely, which is what makes THEIR exemption a
 *       decision on record rather than an absence.
 *
 * HOW IT LOOKS (and why per UNIT, not per statement or per file)
 * --------------------------------------------------------------
 * Built on the SAME `tests/php/lib/php_source_units.php` the parent guard
 * uses: `sqlOnly` reconstructs SQL across `.` concatenation/interpolation,
 * `comments` carries the marker convention, `code` carries the vouch tokens
 * — comments are erased from `code` so a marker can vouch for a unit while a
 * comment can never impersonate a call (the parent guard's failure-1 lesson).
 * Per-unit vouching (not per-file, not per-statement) is the same tradeoff
 * the parent guard already accepted and documents; repeated here rather than
 * re-litigated.
 *
 * VOUCH TOKENS
 * ------------
 * `songbookVisibleSql(` / `songServableSql(` / `songServableSqlFor(` /
 * `->_bookVisible(` / `songbookDisableReady(` — every one of these appearing
 * anywhere in a unit's CODE view means that unit made the #1765 visibility
 * decision (either the DB-bound driver, the pure core directly — a test or a
 * from-scratch caller — or SongData's private songbook-grain delegate).
 * `songVisibleSqlFor(`/`songSoftDeleteReady(` (the #1694 tokens) do NOT vouch
 * here — they answer a DIFFERENT question (is this row soft-deleted?), and a
 * unit that only asks that one has NOT yet decided whether a disabled book's
 * song should show, which is exactly the gap this guard exists to close.
 *
 * WHAT THIS CANNOT CATCH (stated limits, not oversights — the parent guard's
 * own list, true here for the identical reasons)
 * ------------------------------------------------------
 *  - A unit that calls the helper for ONE statement and leaves a SECOND
 *    statement in the same unit unfiltered (unit-level vouching).
 *  - SQL whose table name arrives via a variable ("FROM {$table}").
 *  - Whether the predicate is spliced into the RIGHT clause (ON vs WHERE) —
 *    that is a behavioural test's job (the LIVE probe run alongside this
 *    guard, and any future `test-songbook-disable.php`). No SQL here has
 *    been executed against MySQL.
 *
 * THE FLOOR (the #1701 lesson the parent guard already encodes)
 * ---------------------------------------------------------------------
 * A scanner that under-reports is worse than none, because its tick is read
 * as coverage. This guard asserts it found at least GUARD_FLOOR qualifying
 * units (>=, never ==): a parser regression collapses the count toward zero
 * and goes RED instead of green-and-empty. The floor is set to ~90% of the
 * count on the day of writing (see the constant below for the exact figure
 * and date) — an ordinary refactor that merges a couple of units does not cry
 * wolf, while a broken walker cannot survive.
 *
 * MUTATION LOG (each observed RED against the real tree, then restored —
 * `cp` a backup, diff against it, never `git checkout --`, mirroring the
 * #1694 guard's own method):
 *   M1  deleted `AND ' . $this->_bookVisible() . '` from
 *       SongData::getSongbooks() → RED (unit listed, its SELECT shown).
 *   M2  deleted the `@disabled-visible:` marker from api.php's
 *       `songs_exist` case → RED.
 *   M3  planted a brand-new unmarked `SELECT b.Name FROM tblSongbooks b`
 *       function in a scratch file under includes/ → RED.
 *   M4  broke the unit walker (an early `return [];` injected into
 *       phpSourceUnits() via a copy of the shared lib) → RED via the floor
 *       ("found only 0"), not a confident empty green.
 *   M5  GREEN survivors verified: the real tree, including every
 *       deliberately-unfiltered `@disabled-visible:` site, and a correct
 *       variant spelling `songbookVisibleSql($db)` (default alias) at a
 *       filtered site.
 * (Each mutation was applied to a scratch copy / reverted immediately after
 * observing the expected verdict — see the session's tool-call log for the
 * literal edit-run-revert sequence; nothing here was hypothesised.)
 *
 * @see appWeb/public_html/includes/songbook_visibility.php  the ONE predicate pair
 * @see tests/php/test-song-visibility-guard.php             the sibling guard this clones (#1694)
 * @see tests/php/lib/php_source_units.php                   the shared walker
 * @see .claude/songbook-catalogue-enhancements-plan.md      the epic plan (Architecture + Feature 1 read-path census)
 */

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/php_source_units.php';

/* The one file allowed to read tblSongbooks/tblSongs raw without a #1765
   decision: the predicate pair's own home. `songbookDisableReady()` only
   reads INFORMATION_SCHEMA (never tblSongbooks/tblSongs itself), and
   `songServableSqlFor()`'s returned fragment is a `NOT EXISTS (...)` string
   that never begins with SELECT/INSERT/UPDATE/DELETE/REPLACE (so it is not
   even picked up by `sqlOnly` today) — this entry is defensive, matching the
   parent guard's identical single high-scrutiny allowlist entry for its own
   predicate's home file. */
$allowFiles = ['appWeb/public_html/includes/songbook_visibility.php'];

/* Tokens in the CODE view that prove the unit made the #1765 songbook/song
   visibility decision. `->_visible(` (SongData's SONG-grain delegate) DOES
   vouch here, unlike the file header's "VOUCH TOKENS" section might suggest
   at first read — SongData.php's own `_visible()` (see SongData.php) was
   widened in this same commit to AND-compose `songServableSql()` onto
   `songVisibleSql()` in public mode, so any of SongData's ~30 methods that
   call `$this->_visible($alias)` HAVE, transitively, made the #1765
   decision through that ONE chokepoint — that is the entire point of
   routing every tblSongs read through it (the plan's "one edit covers all
   ~30 reads" architecture). `->_bookVisible(` is SongData's SEPARATE
   songbook-grain delegate. Arrow-qualified so a hypothetical unrelated
   global `something_visible()` can't vouch by suffix-accident (the parent
   guard's identical reasoning for its own `->_visible(` token). Does NOT
   include the #1694-ONLY tokens (`songVisibleSqlFor(`/`songSoftDeleteReady(`
   in isolation) — a unit that ONLY asks "is this row soft-deleted?" has not
   yet decided whether a disabled book's song should show. */
$vouchCode = [
    'songbookVisibleSql(', 'songServableSql(', 'songServableSqlFor(',
    '->_bookVisible(', 'songbookDisableReady(', '->_visible(',
];

/* The marker convention for deliberately-unfiltered units (admin surfaces
   that must keep seeing a disabled book, SongCount recomputes, the
   songs_exist prune-safety exemption, the broadcaster gate-elsewhere
   exemption). Lives in COMMENTS — see the file header for why. */
const GUARD_MARKER = '@disabled-visible:';

/* FLOOR: ~90% of the qualifying-unit count found on 2026-08-04 (the day this
   guard was written against the real tree, post-sweep). `>=`, never `==`.
   If a legitimate refactor drops the live count below this, lower it HERE,
   consciously, in the same commit — do not weaken the detection to compensate. */
const GUARD_FLOOR = 100;

/**
 * Does this reconstructed SQL statement READ tblSongbooks?
 *
 * ELI5: "is there a SELECT/UPDATE-subquery in here that pulls rows out of
 * the songbooks table?"
 *
 * FROM|JOIN anchored (not UPDATE/INSERT/DELETE targeting tblSongbooks
 * directly — a WRITE to tblSongbooks, e.g. the SongCount recompute UPDATE,
 * is visibility-blind by construction and is not what this predicate is
 * about) but DOES count a write whose body contains a SELECT-shaped
 * sub-read against tblSongbooks (mirrors the parent guard's own "an UPDATE
 * … (SELECT COUNT(*) FROM tblSongs …) DOES count" reasoning, applied to the
 * songbook table instead). Normalised first (backticks / line breaks) via
 * the shared `phpUnitsNormaliseSql()`.
 */
function guardSqlReadsSongbooks(string $sql): bool
{
    $norm = phpUnitsNormaliseSql($sql);
    return preg_match('/\b(FROM|JOIN)\s+tblSongbooks\b/i', $norm) === 1;
}

/**
 * Does this reconstructed SQL statement READ tblSongs?
 *
 * ELI5: identical question to the parent #1694 guard's own detector —
 * reused VERBATIM (not re-derived) so the two guards can never quietly
 * diverge about what counts as "a tblSongs read" (rule #35: cross-file
 * agreement needs a mechanism, not two copies that could drift — here the
 * mechanism is literally copying the one function body rather than
 * `require`-ing the other test file, since test files are entry points, not
 * libraries; the BODY is pinned identical instead, and any future edit to
 * either copy is a signal to check the other).
 *
 * @see tests/php/test-song-visibility-guard.php guardSqlReadsSongs() — the source of truth this mirrors
 */
function guardSqlReadsSongsTbl(string $sql): bool
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

$violations   = [];
$qualifyUnits = 0;   // units found needing a #1765 decision — the floor's subject
$scanned      = 0;

foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    if (in_array($rel, $allowFiles, true)) {
        continue;
    }
    $src = file_get_contents($file);
    if ($src === false) {
        /* A harness failure must never be printed as a clean result. */
        $violations[] = "$rel  <unreadable — harness failure, not a scan result>";
        continue;
    }
    $scanned++;
    foreach (phpSourceUnits($src) as $unitName => $unit) {
        $offending = null;
        foreach ($unit['sqlOnly'] as $sql) {
            if (guardSqlReadsSongbooks($sql) || guardSqlReadsSongsTbl($sql)) {
                $offending = $sql;
                break;
            }
        }
        if ($offending === null) {
            continue;                       // unit does not read tblSongbooks/tblSongs
        }
        $qualifyUnits++;

        /* (a) the code made the visibility decision … */
        $vouched = false;
        foreach ($vouchCode as $tok) {
            if (strpos($unit['code'], $tok) !== false) {
                $vouched = true;
                break;
            }
        }
        /* … or (b) a marker declares the unit deliberately unfiltered. */
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
    fwrite(STDERR, "FAIL: songbook/song read site(s) with NO #1765 visibility decision:\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  $v\n");
    }
    fwrite(STDERR, "\nEvery unit whose SQL reads tblSongbooks, or reads tblSongs, must either embed\n");
    fwrite(STDERR, "one of the shared predicates (songbookVisibleSql() / songServableSql() /\n");
    fwrite(STDERR, "SongData::_bookVisible() / songbookDisableReady() — every one degrades to\n");
    fwrite(STDERR, "'1=1' un-migrated, so there is no cost argument) or carry an explicit\n");
    fwrite(STDERR, "`@disabled-visible: <reason>` comment saying WHY a disabled songbook must\n");
    fwrite(STDERR, "stay visible to it (admin surface / SongCount recompute / prune-safety / …).\n");
    fwrite(STDERR, "See .claude/songbook-catalogue-enhancements-plan.md 'Feature 1 read-path census'.\n");
}

if ($qualifyUnits < GUARD_FLOOR) {
    $fail++;
    fwrite(STDERR, sprintf(
        "\nFAIL: scanner under-report — found only %d qualifying unit(s), floor is %d.\n"
        . "Either the unit walker / SQL reconstruction broke (fix it — do NOT lower the floor\n"
        . "for this), or a refactor genuinely reduced the count (then lower GUARD_FLOOR\n"
        . "consciously in the same commit). A guard that scans nothing and prints green is\n"
        . "worse than no guard (#1701).\n",
        $qualifyUnits,
        GUARD_FLOOR
    ));
}

if ($fail > 0) {
    exit(1);
}
printf(
    "PASS: %d songbook/song-reading unit(s) across %d scanned file(s) — every one filtered or marked (floor %d).\n",
    $qualifyUnits,
    $scanned,
    GUARD_FLOOR
);
exit(0);
