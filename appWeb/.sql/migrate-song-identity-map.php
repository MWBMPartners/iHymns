<?php

declare(strict_types=1);

/**
 * iHymns — Cross-system identity map + Christian read-fence (#1066 Theme D + C)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * iHymns now stores ISRC/MusicBrainz/Spotify/Genius identifiers, but matching
 * still relies on fuzzy title heuristics. This migration lands the strong-key
 * home plus the read-layer fence the eventual shared core needs:
 *   - tblWorks.MusicBrainzWorkMBID — composition (Work) identity lives WITH the
 *     work, so work-dedup has a single home (not duplicated in the song map).
 *   - tblSongIdentityMap — recording identity: iHymns SongId <-> MusicBrainz
 *     recording / Spotify track / Genius / ISRC. SongId is a NON-unique index
 *     on purpose (a song may map to several recordings); uniqueness lives on the
 *     external-id columns.
 *   - v_ChristianSongs — lightweight read fence (IsChristian=1), id/title/
 *     songbook/flags only, SQL SECURITY INVOKER. NOT a corpus materialiser.
 *
 * Change history is logged to the existing tblActivityLog (no dedicated table).
 * The iLyricsDB link column + bridge views are GATED on the DB-merge decision
 * and are deliberately NOT created here (see the gated issue #1066).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column/table existence guarded; view via
 * CREATE OR REPLACE). The view references no migration-ordered column, so this
 * script is order-independent relative to the NormalizedTitle migration.
 *
 * @migration-adds tblWorks.MusicBrainzWorkMBID
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-identity-map.php
 *   Web:  /manage/setup-database → "Cross-system identity map + Christian view" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migIdMap_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migIdMap_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migIdMap_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migIdMap_output("");
_migIdMap_output("=== iHymns — Cross-system identity map + Christian view (#1066 Theme D/C) ===");
_migIdMap_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migIdMap_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migIdMap_output("Connected to MySQL: " . DB_NAME);

try {
    _migIdMap_output("");
    _migIdMap_output("--- tblWorks.MusicBrainzWorkMBID (composition identity) ---");
    $hasCol = $mysql->query("SHOW COLUMNS FROM tblWorks LIKE 'MusicBrainzWorkMBID'");
    if ($hasCol && $hasCol->num_rows > 0) {
        _migIdMap_output("  [SKIP] tblWorks.MusicBrainzWorkMBID already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblWorks
                ADD COLUMN MusicBrainzWorkMBID VARCHAR(50) NULL DEFAULT NULL
                    COMMENT 'MusicBrainz Work MBID (composition identity) (#1066 Theme D)' AFTER Iswc"
        );
        _migIdMap_output("  [OK] Added tblWorks.MusicBrainzWorkMBID.");
    }
    $hasIdx = $mysql->query("SHOW INDEX FROM tblWorks WHERE Key_name = 'uq_mbwork'");
    if ($hasIdx && $hasIdx->num_rows > 0) {
        _migIdMap_output("  [SKIP] uq_mbwork already present.");
    } else {
        $mysql->query("ALTER TABLE tblWorks ADD UNIQUE KEY uq_mbwork (MusicBrainzWorkMBID)");
        _migIdMap_output("  [OK] Added unique key uq_mbwork.");
    }

    _migIdMap_output("");
    _migIdMap_output("--- tblSongIdentityMap (recording identity) ---");
    if (_migIdMap_tableExists($mysql, 'tblSongIdentityMap')) {
        _migIdMap_output("  [SKIP] tblSongIdentityMap already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongIdentityMap (
                Id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongId                   VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId; NON-unique — a song may map to several recordings',
                MusicBrainzRecordingMBID VARCHAR(50)  NULL DEFAULT NULL COMMENT 'MusicBrainz recording MBID',
                SpotifyTrackId           VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Spotify track id/URI',
                GeniusTrackId            VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Genius track id',
                IsrcCode                 VARCHAR(15)  NULL DEFAULT NULL COMMENT 'Denorm of tblSongs.Isrc for join-free lookups',
                SourceOfTruth            ENUM('ihymns','ilyricsdb','musicbrainz','spotify','genius','manual') NOT NULL DEFAULT 'ihymns',
                MappingStatus            ENUM('pending','verified','conflict','deprecated') NOT NULL DEFAULT 'pending',
                VerifiedAt               DATETIME     NULL DEFAULT NULL,
                VerifiedBy               INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id; NULL = auto-verified',
                Notes                    TEXT         NULL DEFAULT NULL,
                CreatedAt                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX      idx_SongId        (SongId),
                UNIQUE KEY uk_MBRecording    (MusicBrainzRecordingMBID),
                UNIQUE KEY uk_Spotify        (SpotifyTrackId),
                UNIQUE KEY uk_Genius         (GeniusTrackId),
                UNIQUE KEY uk_Isrc           (IsrcCode),
                INDEX      idx_SourceOfTruth (SourceOfTruth),
                INDEX      idx_StatusVerified (MappingStatus, VerifiedAt),

                CONSTRAINT fk_IdentityMap_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_IdentityMap_VerifiedBy
                    FOREIGN KEY (VerifiedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Cross-system recording identity map (#1066 Theme D). iLyricsDB link gated on DB-merge.'"
        );
        _migIdMap_output("  [OK] Created tblSongIdentityMap.");
    }

    _migIdMap_output("");
    _migIdMap_output("--- v_ChristianSongs (read fence) ---");
    $mysql->query(
        "CREATE OR REPLACE
            SQL SECURITY INVOKER
            VIEW v_ChristianSongs AS
        SELECT
            s.Id, s.SongId, s.Number, s.Title, s.SongbookAbbr,
            sb.Id AS SongbookId, sb.Name AS SongbookName, sb.Abbreviation AS SongbookAbbreviation, sb.IsChristian,
            s.Language, s.Copyright, s.OriginCity, s.OriginCityId, s.TuneName,
            s.Ccli, s.Iswc, s.Isrc, s.Upc, s.Verified,
            s.LyricsPublicDomain, s.MusicPublicDomain, s.IsExplicit, s.Genre,
            s.HasAudio, s.HasSheetMusic,
            s.CreatedAt, s.UpdatedAt
        FROM tblSongs s
        JOIN tblSongbooks sb ON s.SongbookAbbr = sb.Abbreviation
        WHERE sb.IsChristian = 1"
    );
    _migIdMap_output("  [OK] Created/replaced view v_ChristianSongs.");

    _migIdMap_output("");
    _migIdMap_output("--- Summary ---");
    _migIdMap_output("  Recording identity now has a home keyed on ISRC/MBID/Spotify/Genius;");
    _migIdMap_output("  composition identity lives on tblWorks.MusicBrainzWorkMBID; v_ChristianSongs");
    _migIdMap_output("  fences the Christian corpus. iLyricsDB bridge is gated on the merge decision.");
    _migIdMap_output("");
    _migIdMap_output("Migration complete.");
} catch (\Throwable $e) {
    _migIdMap_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
