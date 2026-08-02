<?php

declare(strict_types=1);

/**
 * iHymns — Backfill tblSongExternalIds from grandfathered recording-ID columns (epic #1741 D5 backfill)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tblSongExternalIds` (migrate-song-external-ids.php) is the new key/value
 * home for "what external/catalogue ids does this song's recording have?" —
 * but when that table was created it started EMPTY. Meanwhile the OLD
 * one-column-per-provider home (`tblSongIdentityMap`, plus `tblSongs.Isrc`
 * itself) already had real data sitting in it. This script copies that
 * existing data INTO the new table, so the new table becomes the
 * comprehensive single home the D5 decision asked for — instead of a
 * shiny-but-empty table sitting next to the columns that actually have data.
 *
 * DETAILED / WHY THIS IS A SEPARATE SCRIPT FROM migrate-song-external-ids.php
 * ----------------------------------------------------------------------------
 * migrate-song-external-ids.php's own doc-block explicitly deferred this:
 * "a later backfill MAY populate tblSongExternalIds from them, but that
 * migration ... [is] out of scope here (this batch is purely additive)".
 * This is that later backfill (owner-confirmed 2026-08-02, decision B=yes,
 * media-identifiers-spec.md §4c). It is intentionally its OWN script rather
 * than folded into the D5 schema batch:
 *   - it's a DATA migration (INSERT ... SELECT), not a DDL migration (CREATE
 *     TABLE) — mixing the two in one script means a re-run either has to
 *     re-detect "table already created, but did I backfill yet?" or the
 *     backfill silently never runs on an install that applied the schema
 *     card before this commit existed;
 *   - it must run strictly AFTER 'song-external-ids' in the registry
 *     (rule #19 — "ordering matters" — the target table must exist before
 *     anything can INSERT into it). Keeping backfill separate makes that
 *     ordering explicit as two registry entries rather than an internal
 *     "did the table exist when I started?" branch inside one script.
 *
 * WHAT THIS BACKFILLS (5 sources -> tblSongExternalIds, all IdScope='recording'):
 *   1. tblSongs.Isrc                              -> IdType='isrc'
 *   2. tblSongIdentityMap.MusicBrainzRecordingMBID -> IdType='musicbrainz-recording'
 *   3. tblSongIdentityMap.SpotifyTrackId           -> IdType='spotify'
 *   4. tblSongIdentityMap.GeniusTrackId            -> IdType='genius'
 *      (the #1747 D5 vocabulary addition this backfill is paired with —
 *      see includes/media_identifiers.php RECORDING_EXTERNAL_ID_TYPES['genius'])
 *   5. tblSongIdentityMap.IsrcCode                 -> IdType='isrc'
 *      (tblSongIdentityMap's own denormalised copy of tblSongs.Isrc — see
 *      that table's own doc-comment: "Denorm of tblSongs.Isrc for join-free
 *      lookups". Both (1) and (5) target the SAME IdType='isrc'; when a song
 *      has both, they collide harmlessly on the (SongId, IdType, IdValue)
 *      UNIQUE key and the second INSERT IGNORE is a no-op for that row — this
 *      is a FEATURE, not a bug: two provenance trails confirming the same
 *      fact collapse to one row rather than one row per source.)
 *
 * Every inserted row carries Source='ihymns-backfill' and a SourceRef naming
 * the exact origin column (e.g. 'tblSongs.Isrc',
 * 'tblSongIdentityMap.GeniusTrackId') — provenance so a future cleanup pass
 * (or a curator auditing the table) can tell "this row was copied by the D5
 * backfill from column X" apart from a row written by the eventual P3
 * alias-URL resolver or a manual curator entry. Source/SourceRef are NOT
 * part of the UNIQUE key (mirrors migrate-song-external-ids.php's own
 * design note) — a later write from a DIFFERENT source for the identical
 * (SongId, IdType, IdValue) fact is expected to collide and be treated as
 * re-confirming the same fact, not a new row.
 *
 * IDEMPOTENT + RE-RUNNABLE. Every INSERT uses `INSERT IGNORE ... SELECT`
 * against the live `uq_Song_Type_Value (SongId, IdType, IdValue)` UNIQUE key
 * (migrate-song-external-ids.php), so running this script twice — or running
 * it after some rows were already written some other way — silently skips
 * any row that already exists rather than erroring or duplicating. This is
 * also what makes running it safe on a fixture/dev DB with real data already
 * present: nothing is ever overwritten or duplicated, only added.
 *
 * WHAT THIS DOES **NOT** DO (explicitly out of scope):
 *   - It does NOT delete or touch tblSongs.Isrc / tblSongIdentityMap's four
 *     columns. They remain GRANDFATHERED READS — anything that reads them
 *     directly today keeps working unchanged. In particular
 *     `tblSongs.Isrc` REMAINS the /isrc/ alias-URL resolver's authority for
 *     now (whichever future commit builds that resolver reads from
 *     tblSongs.Isrc, not this table, until a deliberate follow-up cutover).
 *   - It does NOT set up ongoing dual-write-on-edit consistency (i.e. when a
 *     curator edits tblSongs.Isrc or tblSongIdentityMap going forward,
 *     nothing here makes that edit also update tblSongExternalIds). This
 *     backfill is a ONE-TIME (but idempotently re-runnable) catch-up copy of
 *     data that already existed at the moment it runs; keeping the two
 *     stores in sync on every future edit is a tracked follow-up (P5), NOT
 *     this script's job. Re-running this script after further manual edits
 *     to the grandfathered columns WILL pick up any newly-populated values
 *     (that's what "re-runnable" buys), but it is not a live sync mechanism.
 *   - It does NOT validate values against includes/media_identifiers.php's
 *     RECORDING_EXTERNAL_ID_TYPES regexes before copying — the source
 *     columns are treated as already-trusted app data (whatever passed
 *     validation on the way INTO tblSongs.Isrc / tblSongIdentityMap when
 *     they were first written), not untrusted external input being ingested
 *     for the first time.
 *
 * STRICTLY ADDITIVE (no DDL — no CREATE TABLE, no ALTER — this script only
 * INSERTs rows) + IDEMPOTENT. Guarded: refuses to run (with a clear message,
 * not an error) if tblSongExternalIds doesn't exist yet, and skips the
 * tblSongIdentityMap-sourced inserts (with a message) if that table doesn't
 * exist — both existence-probed via SHOW TABLES LIKE, mirroring
 * migrate-song-external-ids.php's own `_migSongExtIds_tableExists()` idiom.
 *
 * NO schema.sql CHANGE — this migration creates no table and no column, so
 * appWeb/.sql/schema.sql needs no matching edit (rule #19 only applies to
 * migrations that create schema objects; test-schema-coverage.php stays
 * green because schemaAuditScanMigrations() finds no CREATE TABLE / ALTER
 * ... ADD COLUMN / @migration-adds signal in this file to attribute).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-backfill-song-external-ids.php
 *   Web:  /manage/setup-database -> "Backfill song external IDs (#1747 D5)" button
 *   Prerequisite: the 'song-external-ids' card (tblSongExternalIds) must
 *   have already been applied — this script detects and reports that rather
 *   than attempting to create the table itself (that stays
 *   migrate-song-external-ids.php's job, per the file's own doc-block).
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/media-identifiers-spec.md §4b (taxonomy), §4c (decisions A=c, B=yes)
 * @see appWeb/.sql/migrate-song-external-ids.php (creates the target table this backfills into)
 * @see appWeb/public_html/includes/media_identifiers.php (the IdScope/IdType vocabulary, incl. the new 'genius' entry)
 * @see appWeb/public_html/manage/includes/migration-registry.php ('backfill-song-external-ids' entry)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migBackfillSongExtIds_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migBackfillSongExtIds_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migBackfillSongExtIds_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migBackfillSongExtIds_output("");
_migBackfillSongExtIds_output("=== iHymns — Backfill tblSongExternalIds from grandfathered columns (#1747 D5) ===");
_migBackfillSongExtIds_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migBackfillSongExtIds_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migBackfillSongExtIds_output("Connected to MySQL: " . DB_NAME);

try {
    /* Guard: the target table is created by migrate-song-external-ids.php,
       NOT here — this script only ever INSERTs into it. Ordered AFTER
       'song-external-ids' in the registry so this should never fire on a
       dashboard "Apply all pending" run, but a direct CLI invocation on an
       un-migrated install needs the same clear refusal. */
    if (!_migBackfillSongExtIds_tableExists($mysql, 'tblSongExternalIds')) {
        _migBackfillSongExtIds_output("  [SKIP] tblSongExternalIds does not exist yet.");
        _migBackfillSongExtIds_output("         Run the \"Song external IDs (#1741 D5)\" migration card first, then re-run this one.");
        $mysql->close();
        return;
    }

    /* ---- Source 1: tblSongs.Isrc -> IdType='isrc' ---------------------- */
    _migBackfillSongExtIds_output("--- tblSongs.Isrc -> IdType='isrc' ---");
    $mysql->query(
        "INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
         SELECT SongId, 'recording', 'isrc', Isrc, 'ihymns-backfill', 'tblSongs.Isrc'
         FROM tblSongs
         WHERE Isrc IS NOT NULL AND Isrc <> ''"
    );
    _migBackfillSongExtIds_output("  [OK] " . $mysql->affected_rows . " row(s) backfilled / already present.");

    /* ---- Sources 2-5: tblSongIdentityMap, only if that (dormant, #1066)
       table exists on this install. ------------------------------------- */
    if (!_migBackfillSongExtIds_tableExists($mysql, 'tblSongIdentityMap')) {
        _migBackfillSongExtIds_output("");
        _migBackfillSongExtIds_output("--- tblSongIdentityMap ---");
        _migBackfillSongExtIds_output("  [SKIP] tblSongIdentityMap does not exist on this install — nothing to backfill from it.");
    } else {
        _migBackfillSongExtIds_output("");
        _migBackfillSongExtIds_output("--- tblSongIdentityMap.MusicBrainzRecordingMBID -> IdType='musicbrainz-recording' ---");
        $mysql->query(
            "INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
             SELECT SongId, 'recording', 'musicbrainz-recording', MusicBrainzRecordingMBID, 'ihymns-backfill', 'tblSongIdentityMap.MusicBrainzRecordingMBID'
             FROM tblSongIdentityMap
             WHERE MusicBrainzRecordingMBID IS NOT NULL AND MusicBrainzRecordingMBID <> ''"
        );
        _migBackfillSongExtIds_output("  [OK] " . $mysql->affected_rows . " row(s) backfilled / already present.");

        _migBackfillSongExtIds_output("");
        _migBackfillSongExtIds_output("--- tblSongIdentityMap.SpotifyTrackId -> IdType='spotify' ---");
        $mysql->query(
            "INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
             SELECT SongId, 'recording', 'spotify', SpotifyTrackId, 'ihymns-backfill', 'tblSongIdentityMap.SpotifyTrackId'
             FROM tblSongIdentityMap
             WHERE SpotifyTrackId IS NOT NULL AND SpotifyTrackId <> ''"
        );
        _migBackfillSongExtIds_output("  [OK] " . $mysql->affected_rows . " row(s) backfilled / already present.");

        _migBackfillSongExtIds_output("");
        _migBackfillSongExtIds_output("--- tblSongIdentityMap.GeniusTrackId -> IdType='genius' ---");
        $mysql->query(
            "INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
             SELECT SongId, 'recording', 'genius', GeniusTrackId, 'ihymns-backfill', 'tblSongIdentityMap.GeniusTrackId'
             FROM tblSongIdentityMap
             WHERE GeniusTrackId IS NOT NULL AND GeniusTrackId <> ''"
        );
        _migBackfillSongExtIds_output("  [OK] " . $mysql->affected_rows . " row(s) backfilled / already present.");

        _migBackfillSongExtIds_output("");
        _migBackfillSongExtIds_output("--- tblSongIdentityMap.IsrcCode -> IdType='isrc' ---");
        $mysql->query(
            "INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
             SELECT SongId, 'recording', 'isrc', IsrcCode, 'ihymns-backfill', 'tblSongIdentityMap.IsrcCode'
             FROM tblSongIdentityMap
             WHERE IsrcCode IS NOT NULL AND IsrcCode <> ''"
        );
        _migBackfillSongExtIds_output("  [OK] " . $mysql->affected_rows . " row(s) backfilled / already present.");
    }

    _migBackfillSongExtIds_output("");
    _migBackfillSongExtIds_output("Backfill complete. tblSongExternalIds is now the comprehensive home for");
    _migBackfillSongExtIds_output("recording-ID data; the grandfathered columns above remain readable and");
    _migBackfillSongExtIds_output("untouched. Safe to re-run at any time — INSERT IGNORE collapses re-imports");
    _migBackfillSongExtIds_output("and cross-source duplicates onto the uq_Song_Type_Value UNIQUE key.");
} catch (\Throwable $e) {
    _migBackfillSongExtIds_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
