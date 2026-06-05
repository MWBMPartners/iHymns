<?php

declare(strict_types=1);

/**
 * iHymns — Interchange fidelity: chords, notes, arrangements (#1066 Theme E)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The multi-format interchange importers currently regex-strip chord rows and
 * drop presenter notes + named arrangements on every round-trip. This migration
 * gives them a lossless home:
 *   - tblSongComponents.ChordsJson — per-line chord annotations parallel to
 *     LinesJson (null-padded array).
 *   - tblSongComponents.NotesJson  — per-line presenter/slide notes.
 *   - tblSongArrangements          — named reorderings + repetitions of a song's
 *     components (the PP7 "arrangement" concept). Coexists with the simpler
 *     tblSongs.ArrangementJson (IsDefault=1 row wins, else fall back to it).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column/table existence guarded).
 *
 * @migration-adds tblSongComponents.ChordsJson
 * @migration-adds tblSongComponents.NotesJson
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-interchange-fidelity.php
 *   Web:  /manage/setup-database → "Interchange fidelity (chords/notes/arrangements)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migFidelity_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migFidelity_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migFidelity_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migFidelity_output("  [OK] Added {$table}.{$col}.");
}

function _migFidelity_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migFidelity_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migFidelity_output("");
_migFidelity_output("=== iHymns — Interchange fidelity (#1066 Theme E) ===");
_migFidelity_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migFidelity_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migFidelity_output("Connected to MySQL: " . DB_NAME);

try {
    _migFidelity_output("");
    _migFidelity_output("--- tblSongComponents: ChordsJson + NotesJson ---");
    _migFidelity_addCol($mysql, 'tblSongComponents', 'ChordsJson',
        "ChordsJson JSON NULL DEFAULT NULL COMMENT 'Per-line chord annotations parallel to LinesJson; null-padded array e.g. [null,[\"C\",\"Am\"],null]. Lossless chord interchange so importers stop regex-stripping chord rows (#1066 Theme E)' AFTER Language");
    _migFidelity_addCol($mysql, 'tblSongComponents', 'NotesJson',
        "NotesJson JSON NULL DEFAULT NULL COMMENT 'Per-line presenter/slide notes parallel to LinesJson; null-padded array of strings e.g. [null,\"Repeat 2x\",null]. ProPresenter speaker notes round-trip (#1066 Theme E)' AFTER ChordsJson");

    _migFidelity_output("");
    _migFidelity_output("--- tblSongArrangements ---");
    if (_migFidelity_tableExists($mysql, 'tblSongArrangements')) {
        _migFidelity_output("  [SKIP] tblSongArrangements already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongArrangements (
                Id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                SongId             VARCHAR(20)   NOT NULL COMMENT 'FK to tblSongs.SongId',
                Name               VARCHAR(255)  NOT NULL COMMENT 'Arrangement name, e.g. Default, Verse-only, Key of G',
                IsDefault          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = canonical arrangement for PP7 export / presentation view',
                ComponentOrderJson JSON          NOT NULL COMMENT 'Array of component indices defining playback sequence, e.g. [0,1,1,2,3]',
                Description        TEXT          NULL DEFAULT NULL COMMENT 'Free-text notes about this arrangement',
                KeySignature       VARCHAR(10)   NULL DEFAULT NULL COMMENT 'Structured key, e.g. G, Bb, F#m — home for the future transpose feature',
                CapoFret           TINYINT       NULL DEFAULT NULL COMMENT 'Capo fret position for chord display (0-12); NULL = none',
                CreatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uk_SongName     (SongId, Name),
                INDEX      idx_SongDefault (SongId, IsDefault),

                CONSTRAINT fk_SongArrangements_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Named song arrangements (#1066 Theme E). PP7 exporter reads IsDefault=1.'"
        );
        _migFidelity_output("  [OK] Created tblSongArrangements.");
    }

    _migFidelity_output("");
    _migFidelity_output("--- Summary ---");
    _migFidelity_output("  Importers can now preserve chord rows + presenter notes, and songs");
    _migFidelity_output("  can carry named arrangements the PP7 exporter reads via IsDefault=1.");
    _migFidelity_output("  tblSongs.ArrangementJson is kept (soft-deprecation; no backfill, no drop).");
    _migFidelity_output("");
    _migFidelity_output("Migration complete.");
} catch (\Throwable $e) {
    _migFidelity_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
