<?php

declare(strict_types=1);

/**
 * iHymns — Song PD-from-year denorm schema batch + backfill (#1862, epic #1863)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Adds the two integer columns the public-domain suggestion feature stores
 * its answer in — "the LYRICS become public domain starting in year X" /
 * same for MUSIC — then computes that answer once for every existing song
 * so the Editor2 hint has real data from the moment this card is applied.
 *
 * DETAILED — WHY THIS SHAPE
 * ----------------------------------------------------------------------------
 * `tblSongs.LyricsPdFromYear` / `MusicPdFromYear` — SMALLINT UNSIGNED NULL,
 * mirroring `FirstPublishedYear`'s own comment (schema.sql): SMALLINT, not
 * MySQL `YEAR`, because `YEAR` starts at 1901 and both hymns and PD terms
 * predate it. NULL = "cannot conclude a death-basis year" (no credits, or an
 * unresolvable/undated contributor) — see `includes/pd_suggest.php`'s header
 * for the full resolution rule. NEVER auto-sets the `LyricsPublicDomain` /
 * `MusicPublicDomain` checkboxes themselves — those stay a curator's
 * explicit click, always (owner-stated).
 *
 * ONE-PASS, ADDITIVE (rule #20): both columns land together in one ALTER —
 * a `@migration-adds` doctag PER column (the multi-column-ALTER rule,
 * CLAUDE.md #19), since the schema-coverage scanner's literal-ALTER regex
 * only catches the FIRST `ADD COLUMN` in a multi-column statement.
 *
 * BACKFILL: set-based via the SAME fold the live write path uses —
 * `pdRecomputeForSong()` (includes/pd_suggest.php) — looped over every
 * SongId in manageable chunks (rule #22: never re-fork the resolution SQL
 * for a one-off backfill; ~14k rows is trivial either way). Idempotent —
 * `pdRecomputeForSong()` is itself write-if-changed, so re-running this
 * script (or a later live recompute) is always safe.
 *
 * SCHEMA MIRROR: both columns are mirrored byte-identical in
 * appWeb/.sql/schema.sql, immediately after `MusicPublicDomain` (rule #19).
 *
 * Rule #41 — this script resolves shared includes via `IHYMNS_INCLUDES_DIR`
 * (the setup-database runner's real docroot) with the literal
 * `dirname(__DIR__) . '/public_html'` as the standalone/CLI repo fallback
 * ONLY — never an unconditional `/public_html/` literal require.
 *
 * @migration-adds tblSongs.LyricsPdFromYear
 * @migration-adds tblSongs.MusicPdFromYear
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-pd-from-year.php
 *   Web:  /manage/setup-database -> "Public-domain suggestion (#1862)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/pd_suggest.php               the shared fold this backfill calls
 * @see appWeb/public_html/manage/includes/migration-registry.php 'song-pd-from-year' entry + probe
 * @see #1862, epic #1863
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migPdYear_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migPdYear_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPdYear_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

/* Rule #41 — resolve the shared includes/ directory via the runner's real
   docroot when available; the literal '/public_html/' sibling is the
   standalone/CLI repo fallback only (this file always lives in the
   un-renamed appWeb/.sql/ sibling, so dirname(__DIR__) itself is correct on
   every channel — it's appending a literal '/public_html/' after it that
   would be wrong off main). */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/pd_suggest.php';

_migPdYear_output("");
_migPdYear_output("=== iHymns — Song PD-from-year denorm schema batch (#1862) ===");
_migPdYear_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migPdYear_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migPdYear_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- tblSongs.LyricsPdFromYear / MusicPdFromYear ---- */
    _migPdYear_output("--- tblSongs.LyricsPdFromYear / MusicPdFromYear ---");
    $needLyrics = !_migPdYear_colExists($mysql, 'tblSongs', 'LyricsPdFromYear');
    $needMusic  = !_migPdYear_colExists($mysql, 'tblSongs', 'MusicPdFromYear');

    if (!$needLyrics && !$needMusic) {
        _migPdYear_output("  [SKIP] Both columns already present.");
    } else {
        $adds = [];
        if ($needLyrics) {
            $adds[] = "ADD COLUMN LyricsPdFromYear SMALLINT UNSIGNED NULL DEFAULT NULL"
                . " COMMENT 'First calendar year the LYRICS are suggested public domain under life+"
                . IHYMNS_PD_LIFE_PLUS_YEARS . " (#1862): MAX(YEAR(DeathDate)) over tblSongWriters+"
                . "tblSongAdaptors+tblSongTranslators, +" . (IHYMNS_PD_LIFE_PLUS_YEARS + 1) . ". NULL ="
                . " cannot conclude (no credits, or any contributor unresolved/undated). Denorm,"
                . " recomputed on credit/death-date change; a SUGGESTION only — never auto-sets"
                . " LyricsPublicDomain'"
                . " AFTER MusicPublicDomain";
        }
        if ($needMusic) {
            /* AFTER LyricsPdFromYear unconditionally: either this same ALTER just
               added it above (both columns needed), or !$needLyrics means it was
               already present from an earlier partial apply — either way it exists
               by the time this clause runs. */
            $adds[] = "ADD COLUMN MusicPdFromYear SMALLINT UNSIGNED NULL DEFAULT NULL"
                . " COMMENT 'Same as LyricsPdFromYear (#1862) for the MUSIC part: MAX(YEAR(DeathDate))"
                . " over tblSongComposers+tblSongArrangers, +" . (IHYMNS_PD_LIFE_PLUS_YEARS + 1) . "."
                . " NULL = cannot conclude. Denorm; a SUGGESTION only — never auto-sets MusicPublicDomain'"
                . " AFTER LyricsPdFromYear";
        }
        $mysql->query('ALTER TABLE tblSongs ' . implode(', ', $adds));
        if ($needLyrics) { _migPdYear_output("  [OK] Added tblSongs.LyricsPdFromYear."); }
        if ($needMusic)  { _migPdYear_output("  [OK] Added tblSongs.MusicPdFromYear."); }
    }

    /* ---- Backfill: chunked, via the ONE shared fold (pdRecomputeForSong) ---- */
    _migPdYear_output("");
    _migPdYear_output("--- Backfill (via includes/pd_suggest.php's pdRecomputeForSong(), write-if-changed) ---");
    $chunkSize = 500;
    $lastId    = 0;
    $total     = 0;
    while (true) {
        $stmt = $mysql->prepare('SELECT Id, SongId FROM tblSongs WHERE Id > ? ORDER BY Id ASC LIMIT ?');
        $stmt->bind_param('ii', $lastId, $chunkSize);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$rows) { break; }
        foreach ($rows as $row) {
            pdRecomputeForSong($mysql, (string)$row['SongId']);
            $lastId = (int)$row['Id'];
            $total++;
        }
        _migPdYear_output("  ... backfilled {$total} song(s) so far (through Id {$lastId})");
    }
    _migPdYear_output("  [OK] Backfill complete — {$total} song(s) processed.");

    _migPdYear_output("");
    _migPdYear_output("Migration complete.");
} catch (\Throwable $e) {
    _migPdYear_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
