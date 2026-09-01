<?php

declare(strict_types=1);

/**
 * iHymns — Cleanup ZERO-mirrored-line tblSongComponents rows (#2063)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHY THIS EXISTS.
 * The #1235 P4 cutover makes `tblLyricLines` authoritative and the "Verify
 * Lyrics-Cutover Gate" (appWeb/.sql/verify-lyrics-cutover.php) proves — G1 —
 * that the components the shared read assembler (includes/lyric_lines_read.php
 * `lyricLinesFetchPrimary()`) builds FROM the mirrored lines exactly match the
 * components the pre-cutover `tblSongComponents.LinesJson` source describes.
 * The assembler is driven by `tblLyricLines` (a LEFT JOIN from lines back to
 * their component), so a `tblSongComponents` row with ZERO mirrored children
 * simply never gets assembled — it silently vanishes from the read side while
 * still counting on the source side. Song `Psalty-1778530312276` has 8
 * `tblSongComponents` rows but only 4 mirrored into `tblLyricLines` (the other
 * 4 are mis-parsed empty sections from the original scrape), so G1 compares
 * 8 source components against 4 assembled ones and fails — permanently,
 * because nothing MADE those 4 rows, and nothing deletes them either.
 *
 * This migration deletes exactly that class of row: a `tblSongComponents`
 * row with no mirrored `tblLyricLines` child, beside a song that otherwise
 * has real mirrored content. It does NOT touch a row whose `LinesJson`
 * still decodes to real, non-empty lines — that shape is a genuine MIRROR
 * FAILURE (the projection missed real content) that G1 must keep flagging,
 * not a junk row this cleanup is licensed to remove; deleting it would hide
 * data loss instead of fixing the symptom.
 *
 * THE THREE-CLAUSE PREDICATE (all three must hold to delete a row `sc`):
 *
 *   1. NO MIRRORED CHILD — `NOT EXISTS (SELECT 1 FROM tblLyricLines ll
 *      WHERE ll.ComponentId = sc.Id)`. This is exactly the assembler's own
 *      driving condition (lyric_lines_read.php `lyricLinesFetchPrimary()` —
 *      `LEFT JOIN tblSongComponents sc ON sc.Id = ll.ComponentId`): a
 *      component with no line pointing at it is invisible to the read path
 *      no matter what. Protects nothing by itself — it is what MAKES a row
 *      a G1-mismatch candidate in the first place.
 *
 *   2. THE SONG HAS REAL CONTENT ELSEWHERE — the song (`sc.SongId`) has
 *      >= 1 mirrored `tblLyricLines` row for a `tblLyrics` row with
 *      `Source='ihymns'` (the same Source filter `lyricLinesFetchPrimary()`
 *      and the G1 gate's `sourceComponentsFromJson()` both key on). This is
 *      the #1178 "junk only beside real content" guard: a song that is a
 *      genuinely blank draft (nothing ever mirrored) has ALL its sections
 *      match this shape, and clause 1 alone would empty it out entirely.
 *      Requiring the song to already have real mirrored content elsewhere
 *      means this migration only ever prunes leftover junk sitting beside
 *      real, already-working lyrics — never a draft's only content.
 *
 *   3. NOT A GENUINE MIRROR-FAILURE ROW — ONLY while
 *      `tblSongComponents.LinesJson` still exists (pre-C6-drop), its value
 *      is decoded PHP-side (mirrors verify-lyrics-cutover.php's
 *      `sourceComponentsFromJson()` decode exactly: `json_decode(...,
 *      true)`, non-array => treated as empty). A row is deleted only when
 *      that decode is NOT a non-empty array — i.e. `[]`, `null`, or invalid
 *      JSON, all of which mean "the original source had nothing here
 *      either" (a mis-parsed empty section, safe to prune). A row whose
 *      LinesJson DOES decode to real lines is a genuine mirror failure —
 *      real source content that never made it into tblLyricLines — and is
 *      left untouched so G1 keeps failing loudly on it rather than this
 *      script quietly deleting the evidence. Post-drop (the column is
 *      gone), this clause is structurally skipped and (1)+(2) alone decide
 *      — see `ihymnsZeroLineComponentShouldDelete()` below, the PURE
 *      function this file and `tests/php/test-cleanup-zero-line-components.php`
 *      both call, so the CI truth-table and the real deletion decision can
 *      never drift apart (rule #35).
 *
 * SAFETY GATES:
 *   - Registry 'manual' => true + 'dryRunnable' => true (mirrors
 *     migrate-reconcile-media-flags.php / migrate-backfill-canonical-songids.php):
 *     EXCLUDED from "Apply all" and the pending counter — a routine bulk
 *     run can never delete a curator-visible row.
 *   - DRY-RUN BY DEFAULT. A web run without ?confirm=1 (or a CLI run
 *     without --confirm) only REPORTS — the candidate rows and their
 *     per-song counts. It never mutates.
 *   - IDEMPOTENT: once the candidates are gone, a re-run finds nothing and
 *     is a clean no-op (the report says so explicitly).
 *   - Every optional table/column is existence-probed via
 *     INFORMATION_SCHEMA before use (rule #19) — an un-mirrored install
 *     (no tblLyricLines/tblLyrics yet) is a no-op skip, and the LinesJson
 *     access is columnExists-gated so a post-C6-drop install never throws
 *     under MYSQLI_REPORT_STRICT.
 *   - Deletes by `Id`, one row at a time, via a bound `DELETE ... WHERE
 *     Id = ?` — never a bulk/range delete.
 *   - DATA-ONLY — no DDL, no schema.sql change (rule #19 imposes nothing
 *     on this file: it creates/alters no table or column).
 *
 * Rule #41 — this script only needs `getDbMysqli()`, resolved exactly like
 * migrate-retire-component-lines-json.php / migrate-drop-song-chords.php:
 * guarded behind `!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists(...)`
 * so the require never runs (and is never reached) inside the
 * setup-database runner, where the dependency is already loaded — the
 * literal `/public_html/` path below is the standalone/CLI fallback only.
 *
 * USAGE:
 *   Web (dry-run): /manage/setup-database -> "Cleanup zero-line tblSongComponents rows (#2063)"
 *   Web (apply):   add &confirm=1 to the run URL
 *   CLI (dry-run): php appWeb/.sql/migrate-cleanup-zero-line-components.php
 *   CLI (apply):   php appWeb/.sql/migrate-cleanup-zero-line-components.php --confirm
 *
 * @requires PHP 8.1+ with mysqli extension
 * @see appWeb/.sql/verify-lyrics-cutover.php                      the G1 gate this unblocks
 * @see appWeb/public_html/includes/lyric_lines_read.php            the assembler this predicate mirrors
 * @see appWeb/.sql/migrate-retire-component-lines-json.php         the $db / output-helper / confirm-gate shape this mirrors
 * @see appWeb/.sql/migrate-reconcile-media-flags.php                the dry-run-by-default report shape this mirrors
 * @see appWeb/public_html/manage/includes/migration-registry.php   'cleanup-zero-line-components' entry + probe
 * @see tests/php/test-cleanup-zero-line-components.php              the pure-predicate truth table
 * @see #2063, #1235, #1616, #1260
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

/**
 * PURE candidate-filter decision for the #2063 zero-mirrored-line cleanup.
 *
 * Declared unconditionally (top-level, not inside any if/function) so it is
 * function-table-bound as soon as this file is `require`d — even by a
 * caller with no live database, such as the CI truth-table in
 * tests/php/test-cleanup-zero-line-components.php, which requires this file
 * inside a try/catch purely to reach this one function without needing a
 * real mysqli connection.
 *
 * @param mixed $decodedLinesJson      json_decode('...LinesJson...', true)
 *                                     result for this component, PHP-side —
 *                                     or null when the LinesJson column no
 *                                     longer exists (post-C6-drop) / the
 *                                     stored value was NULL / the JSON
 *                                     failed to decode to an array. Any of
 *                                     those collapse to null by the caller
 *                                     BEFORE this function sees them — this
 *                                     function only distinguishes "a
 *                                     non-empty array" from everything else.
 * @param bool  $hasMirroredChild      true when >= 1 tblLyricLines row has
 *                                     ComponentId = this component's Id.
 * @param bool  $songHasMirroredContent true when this component's song has
 *                                     >= 1 mirrored tblLyricLines row (any
 *                                     component) for a tblLyrics row with
 *                                     Source='ihymns'.
 * @return bool true = this component row is safe to delete.
 */
function ihymnsZeroLineComponentShouldDelete($decodedLinesJson, bool $hasMirroredChild, bool $songHasMirroredContent): bool
{
    /* Clause 1 — a component with a mirrored child is exactly what the
       assembler DOES build; it is never a G1-mismatch candidate. */
    if ($hasMirroredChild) {
        return false;
    }
    /* Clause 2 — #1178 "junk only beside real content": a song with NO
       mirrored content anywhere is a blank draft, not junk-plus-real-work.
       Never let this cleanup empty out a draft's only sections. */
    if (!$songHasMirroredContent) {
        return false;
    }
    /* Clause 3 — a non-empty decoded LinesJson means the ORIGINAL source
       had real lines that never made it into tblLyricLines: a genuine
       mirror failure, not a mis-parsed empty section. Leave it for G1 to
       keep flagging rather than deleting the evidence. */
    if (is_array($decodedLinesJson) && $decodedLinesJson !== []) {
        return false;
    }
    return true;
}

function _migCleanupZLC_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Table-existence probe (idempotency + the un-mirrored-install skip). */
function _migCleanupZLC_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/** Column-existence probe (the pre-/post-C6-drop LinesJson gate — clause 3). */
function _migCleanupZLC_colExists(\mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migCleanupZLC_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* CONFIRM resolution: CLI uses --confirm; web uses ?confirm=1. Anything else
   is a DRY RUN (report only) — the default-safe stance, mirrored from
   migrate-reconcile-media-flags.php / migrate-backfill-canonical-songids.php. */
$confirm = false;
if ($isCli) {
    $confirm = in_array('--confirm', $argv ?? [], true);
} else {
    $confirm = (($_GET['confirm'] ?? '') === '1');
}
$dryRun = !$confirm;

_migCleanupZLC_out('=== iHymns — Cleanup zero-mirrored-line tblSongComponents rows (#2063) ===');
_migCleanupZLC_out($dryRun ? 'Mode: DRY RUN (report only — pass ?confirm=1 / --confirm to apply)' : 'Mode: APPLY');
_migCleanupZLC_out('');

try {
    /* Un-mirrored install (the #1235 cutover hasn't run here yet) — nothing
       to clean up; a clean no-op skip rather than a STRICT throw on a
       missing table. */
    if (!_migCleanupZLC_tableExists($db, 'tblLyricLines') || !_migCleanupZLC_tableExists($db, 'tblLyrics')
        || !_migCleanupZLC_tableExists($db, 'tblSongComponents')) {
        _migCleanupZLC_out('  [SKIP] tblLyricLines / tblLyrics / tblSongComponents not fully present —');
        _migCleanupZLC_out('         the #1235 lyrics-normalisation cutover has not run on this install yet.');
        _migCleanupZLC_out('Done (nothing to do).');
        return;
    }

    /* Clause 3's column — gated per rule #19 / the #component-json-guard
       convention: LinesJson is dropped by migrate-retire-component-lines-json.php
       (C6), so any reference MUST be columnExists-guarded. */
    $linesJsonExists = _migCleanupZLC_colExists($db, 'tblSongComponents', 'LinesJson');
    $linesJsonSel    = $linesJsonExists ? 'sc.LinesJson' : 'NULL';   /* fixed PHP-source constant, never user input */

    /* Clauses 1 + 2, in SQL: a component with no mirrored line (clause 1),
       beside a song that has >= 1 mirrored line for its 'ihymns' lyrics
       (clause 2) — the SAME Source='ihymns' filter lyric_lines_read.php's
       lyricLinesFetchPrimary() and the G1 gate's sourceComponentsFromJson()
       both key on. Clause 3 is applied PHP-side per row below (it needs a
       real json_decode, not a SQL JSON_LENGTH proxy, to match the gate's
       decode semantics exactly). */
    $res = $db->query(
        "SELECT sc.Id AS CompId, sc.SongId AS SongId, sc.Type AS CompType, sc.Number AS CompNumber,
                {$linesJsonSel} AS LinesJsonVal
           FROM tblSongComponents sc
           LEFT JOIN tblLyricLines ll ON ll.ComponentId = sc.Id
          WHERE ll.Id IS NULL
            AND EXISTS (
                  SELECT 1
                    FROM tblLyricLines ll2
                    JOIN tblLyrics ly2 ON ly2.Id = ll2.LyricsId
                   WHERE ly2.SongId = sc.SongId AND ly2.Source = 'ihymns'
                )
          ORDER BY sc.SongId, sc.SortOrder, sc.Id"
    );
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) { $res->close(); }

    if (empty($rows)) {
        _migCleanupZLC_out('  [OK] No zero-mirrored-line tblSongComponents rows found beside real content.');
        _migCleanupZLC_out('Done (already clean).');
        return;
    }

    _migCleanupZLC_out('  Found ' . count($rows) . ' zero-mirrored-line candidate row(s) (song has other real mirrored content).');
    _migCleanupZLC_out('');

    $toDelete  = [];
    $protected = 0;   /* clause-3-protected genuine mirror failures — left untouched, reported so an operator can see them */

    foreach ($rows as $row) {
        $decoded = null;
        if ($linesJsonExists && $row['LinesJsonVal'] !== null) {
            $tmp     = json_decode((string)$row['LinesJsonVal'], true);
            $decoded = is_array($tmp) ? $tmp : null;   /* invalid JSON / non-array decode => treated as empty, mirrors sourceComponentsFromJson() */
        }
        /* Clauses 1 (no child) + 2 (song has content) already hold for
           every row in $rows by construction of the SQL above — only
           clause 3 (this component's own LinesJson) can still say "keep". */
        if (ihymnsZeroLineComponentShouldDelete($decoded, false, true)) {
            $toDelete[] = $row;
        } else {
            $protected++;
        }
    }

    if ($protected > 0) {
        _migCleanupZLC_out("  [PROTECTED] {$protected} row(s) left untouched — LinesJson still decodes to real, non-empty lines.");
        _migCleanupZLC_out('              That is a genuine mirror failure the G1 gate should keep flagging, not a junk row.');
        _migCleanupZLC_out('');
    }

    if (empty($toDelete)) {
        _migCleanupZLC_out('  Nothing left to delete after clause 3 — done.');
        _migCleanupZLC_out('Done.');
        return;
    }

    $bySong = [];
    foreach ($toDelete as $row) {
        $sid = (string)$row['SongId'];
        $bySong[$sid] = ($bySong[$sid] ?? 0) + 1;
    }

    _migCleanupZLC_out('  ' . ($dryRun ? 'Would delete' : 'Deleting') . ' ' . count($toDelete)
        . ' junk component row(s) across ' . count($bySong) . ' song(s):');
    foreach ($bySong as $songId => $n) {
        _migCleanupZLC_out("    - {$songId}: {$n} row(s)");
    }
    if ($dryRun) {
        foreach ($toDelete as $row) {
            _migCleanupZLC_out("      · Id={$row['CompId']} Type={$row['CompType']} Number={$row['CompNumber']}");
        }
    }

    if ($dryRun) {
        _migCleanupZLC_out('');
        _migCleanupZLC_out('Dry run complete — no changes written. Pass ?confirm=1 (web) or --confirm (CLI) to apply.');
        return;
    }

    $del = $db->prepare('DELETE FROM tblSongComponents WHERE Id = ?');
    $deleted = 0;
    foreach ($toDelete as $row) {
        $id = (int)$row['CompId'];
        $del->bind_param('i', $id);
        $del->execute();
        $deleted++;
    }
    $del->close();

    _migCleanupZLC_out('');
    _migCleanupZLC_out("[OK] Deleted {$deleted} junk component row(s) across " . count($bySong) . ' song(s).');
    _migCleanupZLC_out('Done.');
} catch (\Throwable $e) {
    _migCleanupZLC_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
