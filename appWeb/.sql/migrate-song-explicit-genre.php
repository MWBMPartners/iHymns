<?php

declare(strict_types=1);

/**
 * iHymns — Song IsExplicit + Genre metadata (#1046 — iLyricsDB alignment, pre-deploy)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Two cheap, additive song-level columns the generic iLyricsDB model carries
 * that are pleasant to have in iHymns from day one:
 *   - IsExplicit : content flag (always 0 for the current Christian corpus,
 *     but the axis exists so a future generic/secular import can mark it).
 *   - Genre      : free-text secondary classification (e.g. 'Hymn',
 *     'Contemporary Worship', and later non-Christian genres). NOT the
 *     Christian-filter axis — that is tblSongbooks.IsChristian (#1045).
 *
 * Both are single nullable/defaulted columns — near-zero cost, no data
 * change. IDEMPOTENT (column-existence guarded). STRICTLY ADDITIVE.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-explicit-genre.php
 *   Web:  /manage/setup-database → "Song IsExplicit + Genre" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongExplicitGenre_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSongExplicitGenre_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSongExplicitGenre_output("");
_migSongExplicitGenre_output("=== iHymns — Song IsExplicit + Genre (#1046) ===");
_migSongExplicitGenre_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongExplicitGenre_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSongExplicitGenre_output("Connected to MySQL: " . DB_NAME);

/* IsExplicit ---------------------------------------------------------------- */
_migSongExplicitGenre_output("");
_migSongExplicitGenre_output("--- tblSongs.IsExplicit ---");
try {
    $r = $mysql->query("SHOW COLUMNS FROM tblSongs LIKE 'IsExplicit'");
    if ($r && $r->num_rows > 0) {
        _migSongExplicitGenre_output("  [SKIP] tblSongs.IsExplicit already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN IsExplicit TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Explicit-content flag (#1046); 0 for the Christian corpus, axis exists for generic/secular imports'
                    AFTER MusicPublicDomain"
        );
        _migSongExplicitGenre_output("  [OK] Added tblSongs.IsExplicit (default 0).");
    }
} catch (\Throwable $e) {
    _migSongExplicitGenre_output("  [ERROR] Could not add tblSongs.IsExplicit: " . $e->getMessage());
    $mysql->close();
    return;
}

/* Genre --------------------------------------------------------------------- */
_migSongExplicitGenre_output("");
_migSongExplicitGenre_output("--- tblSongs.Genre ---");
try {
    $r = $mysql->query("SHOW COLUMNS FROM tblSongs LIKE 'Genre'");
    if ($r && $r->num_rows > 0) {
        _migSongExplicitGenre_output("  [SKIP] tblSongs.Genre already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN Genre VARCHAR(100) NULL DEFAULT NULL
                    COMMENT 'Free-text secondary genre (#1046): Hymn / Contemporary Worship / … (NOT the Christian-filter axis — that is tblSongbooks.IsChristian)'
                    AFTER IsExplicit,
                ADD INDEX idx_Genre (Genre)"
        );
        _migSongExplicitGenre_output("  [OK] Added tblSongs.Genre + idx_Genre.");
    }
} catch (\Throwable $e) {
    _migSongExplicitGenre_output("  [ERROR] Could not add tblSongs.Genre: " . $e->getMessage());
    $mysql->close();
    return;
}

_migSongExplicitGenre_output("");
_migSongExplicitGenre_output("--- Summary ---");
_migSongExplicitGenre_output("  tblSongs.IsExplicit (default 0) + tblSongs.Genre added.");
_migSongExplicitGenre_output("");
_migSongExplicitGenre_output("Migration complete.");

$mysql->close();
return;
