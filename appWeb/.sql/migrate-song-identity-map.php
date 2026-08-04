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
 * CREATE OR REPLACE). The view deliberately does NOT select NormalizedTitle, so
 * this script is order-independent relative to the NormalizedTitle migration.
 *
 * NOT DESTRUCTIVE, with one nuance. The column and the table are pure additions.
 * The VIEW, however, is created with CREATE OR REPLACE and therefore OVERWRITES
 * any existing v_ChristianSongs definition every single run — that is what makes
 * the view step self-healing, but it also means a hand-edited view on a live
 * install is silently reverted to the definition below. Nothing else here can
 * lose data; the "undo" is DROP VIEW / DROP TABLE / DROP COLUMN.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — three steps, three different mechanisms:
 *   1. tblWorks.MusicBrainzWorkMBID + uq_mbwork — SHOW COLUMNS / SHOW INDEX
 *      probes, skipped when present.
 *   2. tblSongIdentityMap — table-existence probe.
 *   3. v_ChristianSongs — no probe at all: CREATE OR REPLACE is inherently
 *      idempotent AND is how a stale view definition gets corrected, so
 *      guarding it would defeat the point.
 * The registry probe is the full three-object AND form (table + column + a
 * lookup in INFORMATION_SCHEMA.VIEWS), so a partial apply never shows green —
 * this is the worked example CLAUDE.md rule #19 asks for.
 *
 * ORDERING DEPENDENCY (the view, not the tables): v_ChristianSongs selects
 * s.OriginCityId (added by migrate-places-adoption.php) and sb.IsChristian
 * (added by migrate-songbook-ischristian.php). Both sit EARLIER in the registry
 * order, so "Apply all pending" is safe; running this script standalone on an
 * install missing either column throws on the CREATE VIEW and the card stays
 * pending. Adding a column to the SELECT below therefore adds a migration-order
 * dependency — check where its migration sits before doing it.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Cross-system identity map +
 * Christian view (#1066)" → button "Run Identity Map Migration". Note the view
 * line always reports "[OK] Created/replaced", even on a repeat run, because of
 * the CREATE OR REPLACE above.
 *
 * SCHEMA MIRROR: the tblWorks column, tblSongIdentityMap and the view are all
 * mirrored in appWeb/.sql/schema.sql (what a FRESH install reads). Table and
 * column declarations must stay byte-identical, COMMENT text included
 * (CLAUDE.md rule #19). ⚠ Two COMMENT strings have already drifted from their
 * schema.sql mirrors (tblSongIdentityMap.SongId and .IsrcCode, plus
 * tblWorks.MusicBrainzWorkMBID) — schema.sql carries the longer wording.
 * test-schema-coverage.php compares column PRESENCE only, so CI cannot see
 * this; reconciling it is a paired schema+migration edit. Note also that
 * schema_audit.php does not parse VIEWS at all, so v_ChristianSongs is covered
 * by NO automated check — the two definitions have to be kept in step by hand.
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
        /* The key design is inverted from what a reader usually expects:
           SongId is a NON-unique INDEX while every external id carries its own
           UNIQUE KEY. That is the correct direction for this domain — one iHymns
           song legitimately maps to MANY recordings (explicit vs clean ISRC,
           live vs studio Spotify track, a dozen cover recordings), but any given
           MusicBrainz recording / Spotify track / Genius track / ISRC must
           resolve to at most ONE song or the map is not a map. All four are
           NULLable and MySQL treats NULLs in a unique index as distinct, so a
           row that only knows an ISRC does not block another that only knows a
           Spotify id.

           Composition identity is deliberately NOT here: MusicBrainz *Work*
           MBID lives on tblWorks (added above), so work-level dedup has exactly
           one home. Mixing recording-level and work-level ids in one table is
           the mistake this split exists to avoid.

           ⚠ SourceOfTruth and MappingStatus are ENUMs, inside the very batch
           (#1066) that codified "a growable vocabulary is VARCHAR, never ENUM"
           — CLAUDE.md rule #20. SourceOfTruth in particular obviously grows:
           the next external system (Apple Music, Hymnary, Discogs) needs an
           ALTER, which is precisely the second migration that rule exists to
           prevent. schema.sql carries the same note against its mirror of these
           two columns. Treat this as a known inconsistency to converge, NOT as
           precedent for a new table — and note that converging it is a paired
           schema.sql + migration change, since the two must stay identical. */
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
    /* A FENCE, not a corpus materialiser. The column list is id / title /
       songbook / flags ONLY — no LyricsText, no ArrangementJson — precisely so
       that nothing can SELECT * this view and re-materialise the whole corpus
       in PHP memory, the ~140 MB OOM class CLAUDE.md rule #17 bans. Keep new
       columns to scalar metadata; the moment a body column appears here, this
       stops being a fence.

       SQL SECURITY INVOKER (not the MySQL default, DEFINER) so the view
       executes with the privileges of whoever queries it — the low-privilege
       app user — rather than those of the admin account that happened to run
       this migration. A DEFINER view would quietly hand the app the migrator's
       rights (https://dev.mysql.com/doc/refman/8.0/en/view-check-option.html,
       and the "Stored Object Access Control" section of the manual).

       NormalizedTitle is intentionally absent: including it would make this
       CREATE VIEW fail on any install where migrate-song-normalized-title.php
       had not yet run, coupling two otherwise independent migrations. */
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
