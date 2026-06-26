<?php

declare(strict_types=1);

/**
 * iHymns — Raw-source-document store (#1143)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblLyricsSourceDocuments retains the verbatim ingested carrier document
 * (LyricsFile YAML, .ilyrics, raw TTML/LRC/ASS) at whole-document grain so any
 * format round-trips losslessly — generalising the per-element MetaJson approach
 * (e.g. iLyricsDB #144 LyricsFile passthrough). Additive + dormant; the carrier
 * import/export endpoints are follow-on code.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-source-documents.php
 *   Web:  /manage/setup-database → "Raw-source-document store" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSD_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSD_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSD_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSD_output("");
_migSD_output("=== iHymns — Raw-source-document store (#1143) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSD_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSD_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSD_tableExists($mysql, 'tblLyricsSourceDocuments')) {
        _migSD_output("  [SKIP] tblLyricsSourceDocuments already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricsSourceDocuments (
                Id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                LyricsId    INT UNSIGNED  NOT NULL COMMENT 'FK to tblLyrics.Id — the version this document produced',
                Format      VARCHAR(40)   NOT NULL COMMENT 'lyricsfile|ilyrics|ttml|lrc|ass|… (VARCHAR not ENUM)',
                MimeType    VARCHAR(120)  NULL DEFAULT NULL,
                SpecVersion VARCHAR(40)   NULL DEFAULT NULL COMMENT 'Carrier spec version (LyricsFile 2.0.0, …)',
                Source      VARCHAR(100)  NOT NULL DEFAULT 'ihymns',
                SourceUrl   VARCHAR(1000) NULL DEFAULT NULL,
                RawPayload  LONGTEXT      NOT NULL COMMENT 'Verbatim source document — round-trips losslessly',
                Sha256      CHAR(64)      NULL DEFAULT NULL COMMENT 'Dedup / idempotent re-import',
                CreatedAt   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Lyrics_Sha (LyricsId, Sha256),
                INDEX idx_Lyrics (LyricsId),
                CONSTRAINT fk_SourceDocs_Lyrics FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Verbatim ingested carrier documents for lossless round-trip (#1143).'"
        );
        _migSD_output("  [OK] Created tblLyricsSourceDocuments.");
    }
    _migSD_output("Migration complete.");
} catch (\Throwable $e) { _migSD_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;
