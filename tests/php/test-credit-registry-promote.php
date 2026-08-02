<?php

declare(strict_types=1);

/**
 * iHymns — credit-registry-promote guard (#960)
 *
 * ELI5: every place in this codebase that writes a new row into one of the
 * six credit-role tables (writers / composers / arrangers / adaptors /
 * translators / artists) must ALSO tell the `tblMusicians` registry
 * about that name — otherwise the person has no `/people/<slug>` page, no
 * aliases, no identifiers, nothing. This guard walks the tree, finds every
 * FUNCTION (or top-level dispatch `case`) that writes one of those six
 * tables, and fails the build if that same function/case never calls
 * `musicianPromote()` (the one shared function that does the registry
 * write — `includes/musician_helpers.php`).
 *
 * DETAILED / WHY: the #960 regression was exactly this — the v2 editor's
 * `credit_upsert` action (`manage/editor/api2.php`) wrote the role-table
 * row and simply never called into the promote logic, which at the time
 * lived only inline inside the legacy whole-song save. `credit_search`
 * autocomplete kept working throughout (it unions the role tables
 * directly), so the gap was invisible until someone tried to open the
 * resulting person page — the rule #30 "looks alive, isn't" failure class.
 * This guard exists so that specific shape of regression cannot silently
 * reopen: a NEW credit-table INSERT site that forgets the promote call
 * fails CI immediately instead of shipping a registry gap nobody notices
 * until a curator goes looking for a page that was never created.
 *
 * WHY PER-UNIT (function / dispatch `case`), NOT PER-FILE (D9 revision):
 *   An earlier draft of this guard checked "does the whole FILE contain
 *   the string `musicianPromote(` anywhere?". Mutation-testing that
 *   draft (per rule #34 — a guard must be proven able to fail) found it
 *   COULD NOT go red for the one mutation the plan explicitly requires:
 *   `manage/editor/api2.php` legitimately calls `musicianPromote()`
 *   from TWO independent places (`credit_upsert` and the
 *   `revision_restore` credits-restore loop inside `ed2_applySongSnapshot`)
 *   — #960's own fix adds both in the same file. Removing ONLY the
 *   `credit_upsert` call left the OTHER call site's occurrence of the
 *   substring still present, so a whole-file substring check stayed
 *   GREEN — a false negative, exactly the "wrong-but-green on first run"
 *   failure rule #34 warns a guard's own first draft can produce. Rather
 *   than settle for testing a weaker mutation than the one asked for, this
 *   guard uses the shared `tests/php/lib/php_source_units.php` (#1688) —
 *   built for precisely this "does THIS write call the right helper?"
 *   shape of question, split PER FUNCTION and per top-level dispatch
 *   `case` (api.php-style files put every handler in one giant `switch`,
 *   so file/function scope alone would still let one handler's
 *   correctness vouch for a sibling's regression). Reusing it — rather
 *   than hand-rolling a second, weaker unit-splitter — is also the
 *   project's own modularity rule: extract first, reuse everywhere.
 *
 * DETECTION (tree-derived, not a typed list — rule #34):
 *   For every `*.php` file under `appWeb/public_html/`, `phpSourceUnits()`
 *   splits it into per-function / per-dispatch-case units, each exposing a
 *   `sqlOnly` view (every string that reconstructably BEGINS a SQL verb —
 *   handles multi-piece concatenation and interpolation, so an
 *   `` INSERT INTO `{$table}` `` built from a variable is not missed the
 *   way a naive literal-only regex would miss it) and a `code` view with
 *   comments already stripped by the tokenizer (a comment can never
 *   satisfy either half of this check — no separate comment-stripping
 *   step is needed; the shared library already tokenizes rather than
 *   regex-strips, which is the more robust half of "copy the
 *   comment-stripping approach" this guard inherits by reusing the lib).
 *   A unit is a *credit writer* when one of its `sqlOnly` entries, after
 *   `phpUnitsNormaliseSql()` (drops backticks, collapses whitespace),
 *   matches EITHER:
 *     (a) a LITERAL `INSERT INTO tblSong(Writers|Composers|Arrangers|
 *         Adaptors|Translators|Artists)` — the shape every hand-written
 *         INSERT in this tree uses (save_song_core.php, lyrics_ingest.php);
 *     (b) an INTERPOLATED `INSERT INTO {...$var...}` shape (a dynamic
 *         table name) in a unit whose `code` view ALSO references
 *         `ED2_CREDIT_TABLES` (the api2.php allow-list constant mapping
 *         role => table name) — the api2.php shape. Clause (b) is
 *         MANDATORY, not a nicety: a literal-only regex misses api2.php
 *         entirely, since every credit INSERT there is built from an
 *         interpolated table-name expression.
 *   Every credit-writer unit must also have `musicianPromote(` in its
 *   OWN `code` view.
 *
 * D9 — SAME-UNIT ASSUMPTION (documented per the house rule on guards):
 *   This guard assumes the promote call sits in the SAME function / case
 *   as the INSERT it accompanies — true for all current call sites
 *   (`editorSaveSongCore()`, `case 'credit_upsert'`,
 *   `ed2_applySongSnapshot()`, `lyricsIngest_storeExternalIds()`). If a
 *   FUTURE credit writer routes its promote through a wrapper defined in
 *   ANOTHER function (e.g. a future `creditWriteRow($db, $table, $songId,
 *   $entry)` helper that itself calls `musicianPromote()`
 *   internally), this guard will false-positive on that writer. The
 *   correct response is to WIDEN this check (e.g. also accept a call to
 *   the named wrapper) — NEVER to delete or weaken the guard just because
 *   one legitimate shape doesn't fit it.
 *
 * #1736 — the bulk importers (`includes/song_importers.php`,
 *   `_bulkImport_saveSong()`) parse writers/composers/etc. out of the
 *   source document but currently DROP them — they never INSERT into any
 *   of the six role tables at all. So they are correctly NOT flagged by
 *   this guard (nothing to promote if nothing is written); wiring the
 *   importers to persist credits (and promote them) is tracked separately
 *   as #1736 and deliberately out of scope for this guard, which only
 *   ever asserts "if you write the role table, you must also promote".
 *
 * Pure source-tree scan — no DB — so it slots into the CI lint step.
 *
 *   php tests/php/test-credit-registry-promote.php
 *
 * Exit status 0 = clean, 1 = at least one credit-table-writing unit with
 * no musicianPromote() call, or the vacuity check tripped (see below).
 *
 * MUTATION-TESTING TRANSCRIPT (rule #34 — a guard must be proven able to
 * fail before it is trusted; re-run against the real tree before this
 * file was committed, transcript pasted verbatim into the commit body):
 *
 *   1. Comment out the `musicianPromote(` call inside api2.php's
 *      `credit_upsert` case ONLY (the revision_restore call site is left
 *      untouched, so this is a genuine test of per-UNIT granularity, not
 *      just "does the file mention it anywhere") ->
 *      `php tests/php/test-credit-registry-promote.php` reports FAIL,
 *      naming `manage/editor/api2.php :: case 'credit_upsert'` and
 *      NOTHING else (the revision_restore unit stays green, as it
 *      should). Restore the line -> PASS again.
 *   2. Drop a scratch file under appWeb/public_html/ containing a bare
 *      `INSERT INTO tblSongWriters (SongId, Name) VALUES (?, ?)` inside a
 *      function, and NO `musicianPromote(` call -> FAIL, naming the
 *      scratch file's function (proves the derivation catches a
 *      genuinely NEW writer, not just the three known ones). Delete the
 *      scratch file -> PASS again.
 */

require_once __DIR__ . '/lib/php_source_units.php';

const GUARD_LITERAL_INSERT_PATTERN     = '/^INSERT\s+INTO\s+tblSong(?:Writers|Composers|Arrangers|Adaptors|Translators|Artists)\b/i';
/* A dynamic table name: `INSERT INTO` followed (after backticks are
   stripped by phpUnitsNormaliseSql) by zero-or-more literal braces then a
   PHP variable sigil — matches both `` `{$table}` `` and `` `$table` ``
   renderings. Requires the co-located ED2_CREDIT_TABLES reference (below)
   so this can't match an unrelated dynamic INSERT elsewhere in the tree. */
const GUARD_INTERPOLATED_INSERT_PATTERN = '/^INSERT\s+INTO\s+\{*\$/i';
const GUARD_PROMOTE_CALL           = 'musicianPromote(';
const GUARD_ED2_CREDIT_TABLES_REF  = 'ED2_CREDIT_TABLES';

/** Is this one `sqlOnly` entry (already begins with a SQL verb) an INSERT
 *  into one of the six credit-role tables, given the enclosing unit's own
 *  `code` view (for the interpolated-form's ED2_CREDIT_TABLES check)? */
function creditGuardIsCreditTableInsert(string $sql, string $unitCode): bool
{
    $normalised = phpUnitsNormaliseSql($sql);
    if (preg_match(GUARD_LITERAL_INSERT_PATTERN, $normalised) === 1) {
        return true;
    }
    return preg_match(GUARD_INTERPOLATED_INSERT_PATTERN, $normalised) === 1
        && str_contains($unitCode, GUARD_ED2_CREDIT_TABLES_REF);
}

$repoRoot = dirname(__DIR__, 2);
$scanRoot = $repoRoot . '/appWeb/public_html';

if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: $scanRoot not found\n");
    exit(1);
}

/* Recursively collect *.php under public_html — the same shape as
   test-component-json-guard.php's tree walk. */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$rootPrefix  = $repoRoot . '/';
$writerFiles = [];   // relative path => true (for the vacuity check)
$failures    = [];   // "relFile :: unitName" strings missing the promote call
$scanned     = 0;

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    $scanned++;

    $units = phpSourceUnits($src);
    if (!$units) { continue; }   // unparseable / empty — nothing to check

    $rel = substr($file, strlen($rootPrefix));

    foreach ($units as $unitName => $unit) {
        $isCreditWriter = false;
        foreach ($unit['sqlOnly'] as $sql) {
            if (creditGuardIsCreditTableInsert($sql, $unit['code'])) {
                $isCreditWriter = true;
                break;
            }
        }
        if (!$isCreditWriter) { continue; }

        $writerFiles[$rel] = true;

        if (!str_contains($unit['code'], GUARD_PROMOTE_CALL)) {
            $failures[] = sprintf(
                '%s :: %s — writes a credit role table (tblSongWriters/Composers/Arrangers/'
                . 'Adaptors/Translators/Artists) but never calls musicianPromote() in the '
                . 'same function/case — the tblMusicians registry silently goes unpopulated '
                . 'for this write path (#960)',
                $rel,
                $unitName
            );
        }
    }
}

/* Vacuity check (rule #34): a scan that finds NOTHING is not evidence the
   codebase is clean — it is evidence the derivation itself broke (a typo
   in the regex, a moved directory, the shared lib failing to parse
   anything). Fail loudly rather than pass silently. The true count as of
   #960 is 3 files (save_song_core.php, api2.php, lyrics_ingest.php); the
   >= 2 threshold leaves a margin so a legitimate future removal of one
   writer doesn't need this guard edited, while still catching "the scan
   found literally nothing". */
if (count($writerFiles) < 2) {
    array_unshift($failures, sprintf(
        'vacuity check failed: scan found only %d file(s) with a credit-table-writing unit '
        . '(expected >= 2) — the derivation itself is broken (regex, path, or directory '
        . 'drift), not the codebase',
        count($writerFiles)
    ));
}

if ($failures) {
    fwrite(STDERR, "FAIL: credit-registry-promote guard (#960):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  $f\n"); }
    fwrite(STDERR, "\nEvery function/case that INSERTs into a credit role table must also call\n");
    fwrite(STDERR, "musicianPromote() (includes/musician_helpers.php) in the SAME\n");
    fwrite(STDERR, "function/case, so the tblMusicians registry never silently falls out of\n");
    fwrite(STDERR, "sync with the role tables. See this file's doc-block for the D9 same-unit\n");
    fwrite(STDERR, "assumption and what to do if a future writer routes through a wrapper.\n");
    exit(1);
}

echo "PASS: every credit-table-writing function/case promotes into tblMusicians "
   . "({$scanned} files scanned, " . count($writerFiles) . " file(s) with a credit writer: "
   . implode(', ', array_keys($writerFiles)) . ").\n";
exit(0);
