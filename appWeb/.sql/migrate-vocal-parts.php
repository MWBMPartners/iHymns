<?php

declare(strict_types=1);

/**
 * iHymns — Vocal / singing parts (first-class) (#1137)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Promote vocal/singing-part separation (lead/backing/soloist/duet/named-singer)
 * from lossless-only (tblLyricLines.MetaJson ttm:agent/ttm:role/background-vocal)
 * to a first-class, queryable model anchored on the normalized lyrics:
 *   - tblVocalParts          — per-lyrics-version part registry (decoded ttm:agent);
 *     PartKind VARCHAR (app-validated), named singer reuses tblCreditPeople,
 *     Gender orthogonal axis.
 *   - tblLyricLineVocalParts — MANY-to-MANY line assignment (true duet/unison).
 *   - tblLyricWordVocalParts — MANY-to-MANY word assignment (overrides line).
 *
 * Additive + DORMANT: MetaJson stays the loss-free source of truth; the
 * TTML->first-class back-fill is follow-on feature work, so the tables ship
 * empty. STRICTLY ADDITIVE + IDEMPOTENT (table existence guarded).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-vocal-parts.php
 *   Web:  /manage/setup-database → "Vocal / singing parts" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migVP_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migVP_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migVP_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migVP_output("");
_migVP_output("=== iHymns — Vocal / singing parts (#1137) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migVP_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migVP_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migVP_tableExists($mysql, 'tblVocalParts')) {
        _migVP_output("  [SKIP] tblVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblVocalParts (
                Id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                LyricsId       INT UNSIGNED    NOT NULL COMMENT 'FK to tblLyrics.Id — parts are per lyrics version',
                PartKind       VARCHAR(30)     NOT NULL DEFAULT 'lead' COMMENT 'lead|main|backing|soloist|male|female|duet|group|unison|choir|congregation|cantor|descant|narrator|spoken|named-singer (app-validated). VARCHAR not ENUM',
                Label          VARCHAR(120)    NULL DEFAULT NULL COMMENT 'Editor display override (Soprano, Worship Leader, …)',
                CreditPersonId INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK to tblCreditPeople.Id — typed named-singer link (reuses the person registry, NOT a new tblArtists)',
                SingerName     VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Free-text named singer when no registry row',
                Gender         VARCHAR(16)     NULL DEFAULT NULL COMMENT 'male|female|neutral — orthogonal axis',
                TtmlAgentId    VARCHAR(64)     NULL DEFAULT NULL COMMENT 'Source <ttm:agent> handle (v1,v2) — loss-free re-export + idempotent back-fill key',
                Source         VARCHAR(100)    NOT NULL DEFAULT 'ihymns' COMMENT 'applemusic-ttml | manual | … (mirrors tblLyrics.Source)',
                SortOrder      INT UNSIGNED    NOT NULL DEFAULT 0,
                MetaJson       JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML <head> agent def attrs',
                CreatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Lyrics_Agent (LyricsId, TtmlAgentId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Kind   (PartKind),
                INDEX idx_Person (CreditPersonId),
                CONSTRAINT fk_VocalParts_Lyrics FOREIGN KEY (LyricsId)       REFERENCES tblLyrics(Id)       ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_VocalParts_Person FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-version singing-part registry — first-class vocal parts (#1137).'"
        );
        _migVP_output("  [OK] Created tblVocalParts.");
    }

    if (_migVP_tableExists($mysql, 'tblLyricLineVocalParts')) {
        _migVP_output("  [SKIP] tblLyricLineVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricLineVocalParts (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                LineId       BIGINT UNSIGNED NOT NULL,
                VocalPartId  INT UNSIGNED    NOT NULL,
                LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm of tblLyricLines.LyricsId; app derives from the line',
                IsBackground TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'TTML background-vocal / ttm:role=x-bg',
                SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
                CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Line_Part (LineId, VocalPartId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Line   (LineId, SortOrder),
                INDEX idx_Part   (VocalPartId),
                CONSTRAINT fk_LineVP_Line   FOREIGN KEY (LineId)      REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineVP_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LineVP_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-line vocal-part assignment, many-to-many for duet/unison (#1137).'"
        );
        _migVP_output("  [OK] Created tblLyricLineVocalParts.");
    }

    if (_migVP_tableExists($mysql, 'tblLyricWordVocalParts')) {
        _migVP_output("  [SKIP] tblLyricWordVocalParts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricWordVocalParts (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                WordId       BIGINT UNSIGNED NOT NULL,
                VocalPartId  INT UNSIGNED    NOT NULL,
                LyricsId     INT UNSIGNED    NOT NULL COMMENT 'Denorm via word->line->lyrics; app-derived',
                IsBackground TINYINT(1)      NOT NULL DEFAULT 0,
                SortOrder    INT UNSIGNED    NOT NULL DEFAULT 0,
                CreatedAt    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Word_Part (WordId, VocalPartId),
                INDEX idx_Lyrics (LyricsId),
                INDEX idx_Word   (WordId, SortOrder),
                INDEX idx_Part   (VocalPartId),
                CONSTRAINT fk_WordVP_Word   FOREIGN KEY (WordId)      REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_WordVP_Part   FOREIGN KEY (VocalPartId) REFERENCES tblVocalParts(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_WordVP_Lyrics FOREIGN KEY (LyricsId)    REFERENCES tblLyrics(Id)     ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-word vocal-part assignment (#1137).'"
        );
        _migVP_output("  [OK] Created tblLyricWordVocalParts.");
    }

    _migVP_output("  Tables ship empty; the TTML ttm:agent -> first-class back-fill is follow-on feature work.");
    _migVP_output("Migration complete.");
} catch (\Throwable $e) { _migVP_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
