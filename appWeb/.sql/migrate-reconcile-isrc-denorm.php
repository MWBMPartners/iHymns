<?php

declare(strict_types=1);

/**
 * iHymns — #1749 full-unification cutover: reconcile tblSongs.Isrc <-> tblSongExternalIds (epic #1741)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * From now on, `tblSongExternalIds` is the SOURCE OF TRUTH for a song's
 * ISRC, and `tblSongs.Isrc` is just a one-value "current answer" box kept in
 * sync with it. Before this script runs, some songs may still disagree —
 * either because they were last edited before the sync existed (P5d), or
 * because a very old revision restore wrote a raw value the mirror hadn't
 * canonicalised yet. This script is a ONE-TIME cleanup pass that makes every
 * song's box agree with the store, in both directions, and is safe to run
 * again later (it will simply find nothing left to fix).
 *
 * DETAILED / TWO STEPS, TWO DIRECTIONS
 * ----------------------------------------------------------------------------
 * Step A (column -> store): for any song whose CURRENT tblSongs.Isrc
 * disagrees with its store MARKER row (SourceRef = the mirror's ownership
 * literal) — including a song with NO marker row at all, or (an anomaly that
 * can only exist pre-cutover) a song with MORE THAN ONE marker row — this
 * heals from the COLUMN, because the column was the authoritative value at
 * the moment it was last written, for any install that pre-dates this
 * migration. It calls the ONE write core, songExternalIdMirrorIsrc()
 * (includes/song_external_ids.php) — the SAME function every live editor
 * edit already funnels through — which DELETE-then-INSERTs the marker row
 * and then (its own embedded call) re-projects the store back into the
 * column. Re-minting from the column ALSO collapses a multi-marker anomaly
 * back down to exactly one row, because the mirror's DELETE targets every
 * row matching the ownership predicate before it inserts the fresh one.
 *
 * Step B (store -> column): for everything Step A's scan did NOT touch,
 * select every song whose column still disagrees with the §2.1 primary-ISRC
 * PROJECTION (songExternalIdIsrcProjectionSql()) and call the projection
 * write directly, songExternalIdSyncIsrcDenorm(). Post-Step-A this is
 * exactly the "store-only manual second recording, empty column" cohort —
 * a song a curator added an ISRC row to via the panel (song_external_id_add)
 * before #1749 landed, back when that panel action never touched the column
 * (the 0.9 bug this whole cutover exists to fix).
 *
 * Both steps call the SAME shared builders `song_external_ids.php` declares
 * (songExternalIdIsrcProjectionSql(), the ORDER BY constant behind it) —
 * never a second, independently-typed copy of the primary-ISRC rule
 * (rule #22; the tests/php/test-song-external-id-mirror.php §7.2 sweep bans
 * a re-forked "ORDER BY (e.SourceRef" literal anywhere outside that file).
 *
 * WHY THIS IS DATA-ONLY (no schema.sql edit needed)
 * ----------------------------------------------------------------------------
 * This script contains no CREATE TABLE, no ALTER — only SELECTs plus calls
 * into the existing write core, which itself only ever UPDATEs/INSERTs/
 * DELETEs rows in tables that already exist. `appWeb/.sql/schema.sql` needs
 * no matching edit (mirrors migrate-backfill-song-external-ids.php's own
 * "NO schema.sql CHANGE" declared posture) — rule #19 only applies to
 * migrations that create schema OBJECTS, and CI's test-schema-coverage.php
 * stays green because schemaAuditScanMigrations() finds no CREATE TABLE /
 * ALTER ... ADD COLUMN / @migration-adds signal in this file to attribute.
 *
 * IDEMPOTENT + RE-RUNNABLE. Both steps' SELECTs return zero rows once the
 * corpus is in lockstep — running this script again after it has already
 * succeeded does nothing (and is exactly what the registry probe below
 * re-checks, so it doubles as a live drift detector for as long as this
 * install exists).
 *
 * GATED. Refuses to run (with a clear message, not an error) if
 * `tblSongExternalIds` doesn't exist yet — mirrors every other script in
 * this family's `SHOW TABLES LIKE` existence guard.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-reconcile-isrc-denorm.php
 *   Web:  /manage/setup-database -> "Reconcile ISRC denorm ↔ external-ID store (#1749)" button
 *   Prerequisite: the 'song-external-ids' AND 'backfill-song-external-ids'
 *   cards must already be applied (this script does not create the target
 *   table, and running it before the backfill would treat every backfillable
 *   value as a NEW divergence instead of the copy the backfill is meant to be).
 *
 * @requires PHP 8.1+ with mysqli
 * @link .claude/catalogue-1741-1749-unification-plan.md §4/§5.7 the build spec this script implements
 * @see appWeb/public_html/includes/song_external_ids.php songExternalIdMirrorIsrc(), songExternalIdSyncIsrcDenorm(), songExternalIdIsrcProjectionSql()
 * @see appWeb/.sql/migrate-backfill-song-external-ids.php the one-time COPY this script's cutover reconciliation follows
 * @see appWeb/public_html/manage/includes/migration-registry.php 'reconcile-isrc-denorm' entry
 * @see #1741
 * @see #1749
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migReconcileIsrc_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migReconcileIsrc_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migReconcileIsrc_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

/* The ONE write core + the ONE projection builder — never re-derived here
   (rule #22; precedent for a migration require_once'ing a public_html
   include: migrate-backfill-canonical-songids.php:78-83). */
require_once dirname(__DIR__) . '/public_html/includes/song_external_ids.php';
require_once dirname(__DIR__) . '/public_html/includes/identifier_normalize.php';

_migReconcileIsrc_output("");
_migReconcileIsrc_output("=== iHymns — #1749 full unification: reconcile ISRC denorm <-> external-ID store ===");
_migReconcileIsrc_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migReconcileIsrc_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migReconcileIsrc_output("Connected to MySQL: " . DB_NAME);

try {
    if (!_migReconcileIsrc_tableExists($mysql, 'tblSongExternalIds')) {
        _migReconcileIsrc_output("  [SKIP] tblSongExternalIds does not exist yet.");
        _migReconcileIsrc_output("         Run the \"Song external IDs (#1741 D5)\" and \"Backfill song external IDs (#1747 D5)\" migration cards first, then re-run this one.");
        $mysql->close();
        return;
    }

    $isrcMirrorSourceRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;

    /* ---- Step A: column -> store (heal from the column, the pre-cutover
       authority) --------------------------------------------------------- */
    _migReconcileIsrc_output("--- Step A: re-mint the store marker from tblSongs.Isrc where they disagree ---");
    $stepAStmt = $mysql->prepare(
        "SELECT s.SongId, s.Isrc FROM tblSongs s
          WHERE (NULLIF(s.Isrc,'') IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM tblSongExternalIds e
                    WHERE e.SongId = s.SongId AND e.IdType = 'isrc'
                      AND e.SourceRef = ? AND e.IdValue = s.Isrc))
             OR (NULLIF(s.Isrc,'') IS NULL AND EXISTS (
                   SELECT 1 FROM tblSongExternalIds e
                    WHERE e.SongId = s.SongId AND e.IdType = 'isrc' AND e.SourceRef = ?))
             OR (SELECT COUNT(*) FROM tblSongExternalIds e
                  WHERE e.SongId = s.SongId AND e.IdType = 'isrc'
                    AND e.SourceRef = ?) > 1"
    );
    $stepAStmt->bind_param('sss', $isrcMirrorSourceRef, $isrcMirrorSourceRef, $isrcMirrorSourceRef);
    $stepAStmt->execute();
    $stepARows = $stepAStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stepAStmt->close();

    $stepACount = 0;
    foreach ($stepARows as $row) {
        $songId = (string)$row['SongId'];
        $canon  = ihymns_canonical_isrc((string)($row['Isrc'] ?? ''));
        songExternalIdMirrorIsrc($mysql, $songId, $canon === '' ? null : $canon);
        $stepACount++;
    }
    _migReconcileIsrc_output("  [OK] " . $stepACount . " song(s) re-minted from tblSongs.Isrc.");

    /* ---- Step B: store -> column (promote whatever Step A didn't cover,
       e.g. a store-only manual second-recording row left the column empty
       under the pre-#1749 model) ------------------------------------------ */
    _migReconcileIsrc_output("");
    _migReconcileIsrc_output("--- Step B: project the store's primary ISRC into tblSongs.Isrc where they still disagree ---");
    $stepBStmt = $mysql->query(
        'SELECT s.SongId FROM tblSongs s WHERE NOT (NULLIF(s.Isrc, \'\') <=> ('
        . songExternalIdIsrcProjectionSql('s.SongId')
        . '))'
    );
    $stepBSongIds = [];
    while ($row = $stepBStmt->fetch_assoc()) { $stepBSongIds[] = (string)$row['SongId']; }

    $stepBCount = 0;
    foreach ($stepBSongIds as $songId) {
        songExternalIdSyncIsrcDenorm($mysql, $songId);
        $stepBCount++;
    }
    _migReconcileIsrc_output("  [OK] " . $stepBCount . " song(s) projected from the external-IDs store.");

    _migReconcileIsrc_output("");
    _migReconcileIsrc_output("Reconcile complete. tblSongExternalIds is now the recording-ID authority;");
    _migReconcileIsrc_output("tblSongs.Isrc is its kept-in-sync projection. Idempotent — safe to re-run;");
    _migReconcileIsrc_output("a second run finds nothing left to fix once every live write path (editor,");
    _migReconcileIsrc_output("panel, ingest, merge) is on #1749 code.");
} catch (\Throwable $e) {
    _migReconcileIsrc_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
