<?php

declare(strict_types=1);

/**
 * iHymns — Syllable-level timing + lossless TTML metadata (#1047 follow-up;
 * aligns with iLyricsDB #141 syllable-sync)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Complete the timing model line → word → SYLLABLE so the shared backend can
 * ingest Apple Music syllable-synced TTML (and karaoke / Enhanced-LRC) without
 * losing data. Mirrors the iLyricsDB #141 spec exactly so the two schemas stay
 * mergeable:
 *
 *   tblLyricSyllables       — one row per syllable within a word, with its own
 *                             start/endTimeMs (FK to tblLyricWords).
 *   tblLyrics.HasSyllableTiming — flag on the master record.
 *   *.MetaJson              — a nullable JSON column on tblLyricLines /
 *                             tblLyricWords / tblLyricSyllables to store any
 *                             TTML-specific attributes losslessly (ttm:role,
 *                             ttm:agent, itunes:key, background-vocal markers,
 *                             song-part refs) so nothing is discarded on import.
 *
 * STRICTLY ADDITIVE. tblLyricSyllables starts empty (populated only by timed
 * imports). IDEMPOTENT (table + column existence guarded).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-lyric-syllables.php
 *   Web:  /manage/setup-database → "Syllable timing + TTML metadata" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSyllables_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migSyllables_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migSyllables_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migSyllables_output("  [OK] Added {$table}.{$col}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSyllables_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSyllables_output("");
_migSyllables_output("=== iHymns — Syllable timing + TTML metadata (#1047 / iLyricsDB #141) ===");
_migSyllables_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSyllables_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSyllables_output("Connected to MySQL: " . DB_NAME);

try {
    _migSyllables_output("");
    _migSyllables_output("--- Step 1: tblLyricSyllables ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblLyricSyllables (
            Id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            WordId      BIGINT UNSIGNED NOT NULL,
            SortOrder   INT UNSIGNED    NOT NULL DEFAULT 0,
            SyllableText VARCHAR(100)   NOT NULL,
            StartTimeMs INT UNSIGNED    NULL DEFAULT NULL,
            EndTimeMs   INT UNSIGNED    NULL DEFAULT NULL,
            MetaJson    JSON            NULL DEFAULT NULL COMMENT 'Lossless TTML/format attrs (itunes:key, role, …)',
            CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_Word (WordId, SortOrder),

            CONSTRAINT fk_LyricSyllables_Word
                FOREIGN KEY (WordId) REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    _migSyllables_output("  [OK] tblLyricSyllables ready.");

    _migSyllables_output("");
    _migSyllables_output("--- Step 2: flags + lossless TTML metadata columns ---");
    _migSyllables_addCol($mysql, 'tblLyrics', 'HasSyllableTiming',
        "HasSyllableTiming TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = syllable-level timing present (TTML/karaoke)' AFTER HasWordTiming");
    _migSyllables_addCol($mysql, 'tblLyricLines', 'MetaJson',
        "MetaJson JSON NULL DEFAULT NULL COMMENT 'Lossless TTML line attrs (ttm:role, ttm:agent, itunes:song-part, background-vocal)'");
    _migSyllables_addCol($mysql, 'tblLyricWords', 'MetaJson',
        "MetaJson JSON NULL DEFAULT NULL COMMENT 'Lossless TTML word attrs (itunes:key, …)'");

    _migSyllables_output("");
    _migSyllables_output("--- Summary ---");
    _migSyllables_output("  Timing model is now line → word → syllable; TTML metadata stored losslessly.");
    _migSyllables_output("  Empty until timed imports populate it; the ingest API (#1064) targets this model.");
    _migSyllables_output("");
    _migSyllables_output("Migration complete.");
} catch (\Throwable $e) {
    _migSyllables_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
