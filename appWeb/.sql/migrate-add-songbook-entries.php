<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Entries N:N junction (#1044 — iLyricsDB alignment, pre-deploy)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Make song↔songbook membership a MANY-TO-MANY relationship via a junction,
 * so the iHymns DB can become the shared iLyricsDB core without a second
 * re-architecture. This is the ONE divergence that is NOT cleanly additive
 * later (it concerns song identity/ownership), so it lands BEFORE the deploy.
 *
 * It ENABLES, but does not force, two ownership models:
 *   - Christian songs  → 1..N songbook entries (a hymn can appear in CP, MP,
 *     SDAH … with different numbers — fixing today's cross-hymnal duplication).
 *   - Non-Christian songs (future) → ZERO entries, owned via their artist(s)
 *     through tblSongArtists, as is normal for secular music.
 *
 * STRICTLY ADDITIVE — the existing tblSongs.SongbookAbbr + Number are RETAINED
 * as each song's "home" songbook (IsHome=1 in the junction), the human SongId
 * ('CP-0001') prefix is untouched, and NO read path changes. Nothing in the
 * app reads tblSongbookEntries yet; it is shape-readiness for the merge.
 *
 * IDEMPOTENT: CREATE TABLE IF NOT EXISTS + INSERT IGNORE backfill — safe to
 * re-run. Misc/unstructured songs keep NULL SongNumber (InnoDB UNIQUE permits
 * multiple NULLs, so the per-book number uniqueness still holds for numbered
 * books while NULL-numbered collections are unconstrained).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-songbook-entries.php
 *   Web:  /manage/setup-database → "Songbook Entries (N:N)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongbookEntries_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSongbookEntries_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSongbookEntries_output("");
_migSongbookEntries_output("=== iHymns — Songbook Entries N:N junction (#1044) ===");
_migSongbookEntries_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongbookEntries_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSongbookEntries_output("Connected to MySQL: " . DB_NAME);

/* ---- Step 1: create the junction ----------------------------------------- */
_migSongbookEntries_output("");
_migSongbookEntries_output("--- Step 1: tblSongbookEntries (N:N song↔songbook) ---");
try {
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblSongbookEntries (
            Id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            SongbookAbbr  VARCHAR(10)     NOT NULL COMMENT 'FK to tblSongbooks.Abbreviation',
            SongId        VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId (the human id)',
            SongNumber    INT UNSIGNED    NULL COMMENT 'Number within THIS songbook; NULL for unstructured collections (Misc)',
            IsHome        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the song''s home/primary songbook (the one its SongId is prefixed from); kept in sync with tblSongs.SongbookAbbr',
            CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uq_book_song   (SongbookAbbr, SongId),
            UNIQUE KEY uq_book_number (SongbookAbbr, SongNumber),
            INDEX idx_SongId   (SongId),
            INDEX idx_Songbook (SongbookAbbr),
            INDEX idx_Home     (SongId, IsHome),

            CONSTRAINT fk_SongbookEntries_Songbook
                FOREIGN KEY (SongbookAbbr) REFERENCES tblSongbooks(Abbreviation)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_SongbookEntries_Song
                FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migSongbookEntries_output("  [OK] tblSongbookEntries ready.");
} catch (\Throwable $e) {
    _migSongbookEntries_output("  [ERROR] Could not create tblSongbookEntries: " . $e->getMessage());
    $mysql->close();
    return;
}

/* ---- Step 2: backfill one HOME entry per existing song -------------------- */
_migSongbookEntries_output("");
_migSongbookEntries_output("--- Step 2: backfill home entries from tblSongs ---");
try {
    /* INSERT IGNORE so re-running is a no-op (the UNIQUE keys dedupe). Only
       songs with a real home songbook are backfilled; the SongId prefix +
       SongbookAbbr stay the source of truth for "home". */
    $mysql->query(
        "INSERT IGNORE INTO tblSongbookEntries (SongbookAbbr, SongId, SongNumber, IsHome)
         SELECT s.SongbookAbbr, s.SongId, s.Number, 1
           FROM tblSongs s
          WHERE s.SongbookAbbr IS NOT NULL AND s.SongbookAbbr <> ''"
    );
    $inserted = $mysql->affected_rows;
    $total = (int)($mysql->query("SELECT COUNT(*) AS c FROM tblSongbookEntries")->fetch_assoc()['c'] ?? 0);
    _migSongbookEntries_output("  [OK] Backfilled {$inserted} new home entr" . ($inserted === 1 ? 'y' : 'ies') . "; {$total} total.");
} catch (\Throwable $e) {
    _migSongbookEntries_output("  [ERROR] Backfill failed: " . $e->getMessage());
    $mysql->close();
    return;
}

_migSongbookEntries_output("");
_migSongbookEntries_output("--- Summary ---");
_migSongbookEntries_output("  tblSongbookEntries created + backfilled (IsHome=1 per song).");
_migSongbookEntries_output("  Additive only — SongbookAbbr / Number / SongId unchanged; no reader touches the junction yet.");
_migSongbookEntries_output("  Follow-up (#1044): wire editor save_song to upsert the home entry on create, + secular-song (0-entry) identity scheme.");
_migSongbookEntries_output("");
_migSongbookEntries_output("Migration complete.");

$mysql->close();
return;
