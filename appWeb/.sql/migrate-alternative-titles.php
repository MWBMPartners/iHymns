<?php

declare(strict_types=1);

/**
 * iHymns — Alternative Titles Migration (#832)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Two parallel join tables that let curators store multiple
 * "also known as" titles per song and per songbook. Used for:
 *
 *   1. Internal search — a search for "Faith's Review and Expectation"
 *      returns Amazing Grace (its original 1779 title); "Adventist
 *      Hymnal" returns The Church Hymnal.
 *   2. SEO / webpage metadata — surfaces alt titles via JSON-LD
 *      `alternateName` on song + songbook pages so search engines
 *      pick up the equivalents.
 *
 * Schema:
 *   tblSongAlternativeTitles
 *     SongId       FK → tblSongs(SongId)
 *     Title        the alt title
 *     Language     optional IETF BCP 47 tag for multilingual hymns
 *                  (e.g. 'es' for "Sublime Gracia" alt of "Amazing Grace")
 *     SortOrder    display order
 *     Note         optional context ("original title", "first line", …)
 *     CreatedAt
 *     UNIQUE (SongId, Title)
 *
 *   tblSongbookAlternativeTitles
 *     SongbookId   FK → tblSongbooks(Id)
 *     Title
 *     SortOrder
 *     Note
 *     CreatedAt
 *     UNIQUE (SongbookId, Title)
 *
 * Idempotent — INFORMATION_SCHEMA probe; CREATE skips when present.
 * No data backfill (alt titles are curator-entered, not derivable).
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-alternative-titles.php
 *   Web: /manage/setup-database → "Alternative Titles (#832)"
 *        (entry point requires global_admin)
 *
 * @migration-adds tblSongAlternativeTitles
 * @migration-adds tblSongbookAlternativeTitles
 * @requires PHP 8.1+ with mysqli extension
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migAltTitles_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migAltTitles_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migAltTitles_out('Alternative Titles migration starting (#832)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblSongs', 'tblSongbooks'] as $required) {
    if (!_migAltTitles_tableExists($mysqli, $required)) {
        _migAltTitles_out("ERROR: {$required} not found. Run install.php / migrate-json.php first.");
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — tblSongAlternativeTitles
 * ---------------------------------------------------------------------- */
if (_migAltTitles_tableExists($mysqli, 'tblSongAlternativeTitles')) {
    _migAltTitles_out('[skip] tblSongAlternativeTitles already present.');
} else {
    $sql = "CREATE TABLE tblSongAlternativeTitles (
        Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongId       VARCHAR(20)  NOT NULL,
        Title        VARCHAR(255) NOT NULL,
        Language     VARCHAR(35)  NULL,
        SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
        Note         VARCHAR(255) NULL,
        CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_song   (SongId),
        INDEX idx_title  (Title),
        UNIQUE KEY uq_song_title (SongId, Title),

        CONSTRAINT fk_alt_song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongAlternativeTitles failed: ' . $mysqli->error);
    }
    _migAltTitles_out('[add ] tblSongAlternativeTitles.');
}

/* ----------------------------------------------------------------------
 * Step 2 — tblSongbookAlternativeTitles
 * ---------------------------------------------------------------------- */
if (_migAltTitles_tableExists($mysqli, 'tblSongbookAlternativeTitles')) {
    _migAltTitles_out('[skip] tblSongbookAlternativeTitles already present.');
} else {
    $sql = "CREATE TABLE tblSongbookAlternativeTitles (
        Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongbookId   INT UNSIGNED NOT NULL,
        Title        VARCHAR(255) NOT NULL,
        SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
        Note         VARCHAR(255) NULL,
        CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_book   (SongbookId),
        INDEX idx_title  (Title),
        UNIQUE KEY uq_book_title (SongbookId, Title),

        CONSTRAINT fk_alt_book
            FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongbookAlternativeTitles failed: ' . $mysqli->error);
    }
    _migAltTitles_out('[add ] tblSongbookAlternativeTitles.');
}

_migAltTitles_out('Alternative Titles migration finished (#832).');
