<?php

declare(strict_types=1);

/**
 * iHymns — Normalised lyrics model (#1047 — iLyricsDB alignment, post-#1044)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Introduce the generic, timing-capable lyrics model that the shared iLyricsDB
 * core uses and that TTML / Enhanced-LRC / karaoke ingest (#141, MeedyaDL) and
 * the richer format importers depend on:
 *
 *   tblLyrics      — a master lyrics record per song. A song MAY have several
 *                    (an iHymns "approved" version, an explicit vs clean
 *                    variant, a timed Apple-Music import, …).
 *   tblLyricLines  — one row per line, with optional line-level timing
 *                    (startTimeMs/endTimeMs), a per-line language override and
 *                    an isInstrumental flag. Replaces the JSON blob long-term.
 *   tblLyricWords  — one row per word, for word/syllable timing (TTML, LRC-A,
 *                    karaoke). Starts empty; populated by timed imports.
 *
 * STRICTLY ADDITIVE + NON-DESTRUCTIVE: tblSongComponents.LinesJson stays the
 * authoritative source the editor + readers use today; this migration BACKFILLS
 * the new tables from it (one "ihymns" primary tblLyrics per song + its lines)
 * but switches NO read path. A later, separately-verified step moves readers
 * over. IDEMPOTENT: tblLyrics is keyed UNIQUE(SongId, Source) and only lyrics
 * with zero lines are line-backfilled, so re-running is a no-op.
 *
 * Works on MySQL 5.7+ (no JSON_TABLE dependency — lines are expanded in PHP).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-normalize-lyrics.php
 *   Web:  /manage/setup-database → "Normalised lyrics model" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migNormLyrics_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migNormLyrics_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migNormLyrics_output("");
_migNormLyrics_output("=== iHymns — Normalised lyrics model (#1047) ===");
_migNormLyrics_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migNormLyrics_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migNormLyrics_output("Connected to MySQL: " . DB_NAME);

/* ---- Step 1: tables ------------------------------------------------------- */
_migNormLyrics_output("");
_migNormLyrics_output("--- Step 1: create tblLyrics / tblLyricLines / tblLyricWords ---");
try {
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblLyrics (
            Id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
            SongId        VARCHAR(20)     NOT NULL COMMENT 'FK to tblSongs.SongId',
            Source        VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'Provenance: ihymns / applemusic-ttml / user-submission / …',
            SourceUrl     VARCHAR(1000)   NULL DEFAULT NULL,
            FormatVersion VARCHAR(20)     NOT NULL DEFAULT '1.0',
            IsPrimary     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the canonical lyrics shown for the song',
            IsExplicit    TINYINT(1)      NOT NULL DEFAULT 0,
            HasTiming     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = line-level timing present',
            HasWordTiming TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = word-level timing present (TTML/LRC-A)',
            Status        ENUM('draft','pending_review','approved','rejected','archived') NOT NULL DEFAULT 'approved',
            SubmittedBy   INT UNSIGNED    NULL DEFAULT NULL,
            ApprovedBy    INT UNSIGNED    NULL DEFAULT NULL,
            ApprovedAt    DATETIME        NULL DEFAULT NULL,
            CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_song_source (SongId, Source),
            INDEX idx_SongId  (SongId),
            INDEX idx_Primary (SongId, IsPrimary),
            INDEX idx_Status  (Status),

            CONSTRAINT fk_Lyrics_Song
                FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_Lyrics_SubmittedBy
                FOREIGN KEY (SubmittedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL,
            CONSTRAINT fk_Lyrics_ApprovedBy
                FOREIGN KEY (ApprovedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblLyricLines (
            Id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            LyricsId      INT UNSIGNED    NOT NULL,
            ComponentId   INT UNSIGNED    NULL DEFAULT NULL COMMENT 'Source tblSongComponents.Id during transition (traceability)',
            PartType      VARCHAR(20)     NULL DEFAULT NULL COMMENT 'Denorm of the component type (verse/chorus/…) for standalone use',
            PartNumber    INT UNSIGNED    NULL DEFAULT NULL,
            SortOrder     INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Global line order within the lyrics',
            LineText      TEXT            NOT NULL,
            StartTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
            EndTimeMs     INT UNSIGNED    NULL DEFAULT NULL,
            LanguageCode  VARCHAR(35)     NULL DEFAULT NULL COMMENT 'Per-line language override (IETF tag); NULL = song default',
            IsInstrumental TINYINT(1)     NOT NULL DEFAULT 0,
            CreatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_Lyrics    (LyricsId, SortOrder),
            INDEX idx_Component (ComponentId),

            CONSTRAINT fk_LyricLines_Lyrics
                FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblLyricWords (
            Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            LineId      BIGINT UNSIGNED NOT NULL,
            SortOrder   INT UNSIGNED    NOT NULL DEFAULT 0,
            WordText    VARCHAR(200)    NOT NULL,
            StartTimeMs INT UNSIGNED    NULL DEFAULT NULL,
            EndTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
            CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_Line (LineId, SortOrder),

            CONSTRAINT fk_LyricWords_Line
                FOREIGN KEY (LineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migNormLyrics_output("  [OK] Three tables ready.");
} catch (\Throwable $e) {
    _migNormLyrics_output("  [ERROR] Could not create tables: " . $e->getMessage());
    $mysql->close();
    return;
}

/* ---- Step 2: backfill one primary 'ihymns' tblLyrics per song ------------- */
_migNormLyrics_output("");
_migNormLyrics_output("--- Step 2: backfill master tblLyrics (one per song) ---");
try {
    $mysql->query(
        "INSERT IGNORE INTO tblLyrics (SongId, Source, IsPrimary, Status, FormatVersion)
         SELECT s.SongId, 'ihymns', 1, 'approved', '1.0' FROM tblSongs s"
    );
    $newMasters = $mysql->affected_rows;
    _migNormLyrics_output("  [OK] {$newMasters} new master record(s).");
} catch (\Throwable $e) {
    _migNormLyrics_output("  [ERROR] Master backfill failed: " . $e->getMessage());
    $mysql->close();
    return;
}

/* ---- Step 3: backfill tblLyricLines from tblSongComponents.LinesJson ------ */
_migNormLyrics_output("");
_migNormLyrics_output("--- Step 3: backfill lines from tblSongComponents (LinesJson) ---");
try {
    /* Only ihymns-primary lyrics that have NO lines yet (idempotent). */
    $todo = $mysql->query(
        "SELECT ly.Id AS LyricsId, ly.SongId
           FROM tblLyrics ly
          WHERE ly.Source = 'ihymns'
            AND NOT EXISTS (SELECT 1 FROM tblLyricLines l WHERE l.LyricsId = ly.Id)"
    )->fetch_all(MYSQLI_ASSOC);

    $compStmt = $mysql->prepare(
        "SELECT Id, Type, Number, SortOrder, LinesJson
           FROM tblSongComponents WHERE SongId = ?
          ORDER BY SortOrder, Id"
    );
    $lineStmt = $mysql->prepare(
        "INSERT INTO tblLyricLines (LyricsId, ComponentId, PartType, PartNumber, SortOrder, LineText, IsInstrumental)
         VALUES (?, ?, ?, ?, ?, ?, 0)"
    );

    $songsDone = 0;
    $linesAdded = 0;
    foreach ($todo as $row) {
        $lyricsId = (int)$row['LyricsId'];
        $songId   = (string)$row['SongId'];
        $compStmt->bind_param('s', $songId);
        $compStmt->execute();
        $comps = $compStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $order = 0;
        $mysql->begin_transaction();
        try {
            foreach ($comps as $c) {
                $type   = $c['Type'] !== null ? (string)$c['Type'] : null;
                $pnum   = ($c['Number'] !== null && $c['Number'] !== '') ? (int)$c['Number'] : null;
                $lines  = json_decode((string)($c['LinesJson'] ?? '[]'), true);
                if (!is_array($lines)) { $lines = []; }
                $compId = (int)$c['Id'];
                foreach ($lines as $lineText) {
                    $text = is_string($lineText) ? $lineText : (string)$lineText;
                    $lineStmt->bind_param('iisiss', $lyricsId, $compId, $type, $pnum, $order, $text);
                    $lineStmt->execute();
                    $order++;
                    $linesAdded++;
                }
            }
            $mysql->commit();
        } catch (\Throwable $inner) {
            $mysql->rollback();
            _migNormLyrics_output("  [WARN] Skipped {$songId} (line backfill error): " . $inner->getMessage());
            continue;
        }
        $songsDone++;
        if ($songsDone % 500 === 0) {
            _migNormLyrics_output("  … {$songsDone} songs, {$linesAdded} lines so far");
        }
    }
    $compStmt->close();
    $lineStmt->close();
    _migNormLyrics_output("  [OK] Backfilled {$linesAdded} line(s) across {$songsDone} song(s).");
} catch (\Throwable $e) {
    _migNormLyrics_output("  [ERROR] Line backfill failed: " . $e->getMessage());
    $mysql->close();
    return;
}

_migNormLyrics_output("");
_migNormLyrics_output("--- Summary ---");
_migNormLyrics_output("  tblLyrics / tblLyricLines / tblLyricWords created + backfilled from tblSongComponents.");
_migNormLyrics_output("  tblSongComponents stays authoritative (no read-path switch yet) — additive only.");
_migNormLyrics_output("  tblLyricWords is empty, ready for TTML / LRC-A / karaoke ingest (#141 / API import).");
_migNormLyrics_output("");
_migNormLyrics_output("Migration complete.");

$mysql->close();
return;
