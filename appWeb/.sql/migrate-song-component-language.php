<?php

declare(strict_types=1);

/**
 * iHymns — Song Component Language Override Migration (#858)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a per-component language override column to tblSongComponents
 * so a multi-language medley (e.g. an English carol with a Spanish
 * chorus) can record the actual language of each verse / chorus /
 * bridge instead of forcing the whole song under a single
 * tblSongs.Language tag. Public render uses the column to set
 * lang="…" on each component <div> (correct screen-reader
 * pronunciation, browser hyphenation) and to populate the JSON-LD
 * MusicComposition.inLanguage union.
 *
 * Schema: one nullable VARCHAR(35) column matching the IETF BCP 47
 * width established for tblSongs.Language and tblSongbooks.Language
 * (#681). NULL = inherit from the parent song's language; a
 * curator-set value overrides per-component.
 *
 * Idempotent: probes INFORMATION_SCHEMA.COLUMNS first; skips if
 * the column already exists.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-song-component-language.php
 *   Web: /manage/setup-database → "Song Component Language Override (#858)"
 *
 * @migration-modifies tblSongComponents (adds Language)
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

function _migComponentLang_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migComponentLang_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migComponentLang_tableExists(\mysqli $db, string $table): bool
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

_migComponentLang_out('Song Component Language migration starting (#858)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migComponentLang_tableExists($mysqli, 'tblSongComponents')) {
    _migComponentLang_out('ERROR: tblSongComponents not found. Run prerequisite migrations first.');
    return;
}

if (_migComponentLang_columnExists($mysqli, 'tblSongComponents', 'Language')) {
    _migComponentLang_out('[skip] tblSongComponents.Language already present.');
} else {
    /* AFTER LinesJson keeps the column adjacent to the lyric content
       it qualifies, so a SHOW CREATE TABLE / DESCRIBE reads in the
       order a curator would expect. */
    $sql = "ALTER TABLE tblSongComponents
              ADD COLUMN Language VARCHAR(35) NULL DEFAULT NULL
              AFTER LinesJson";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('ALTER TABLE tblSongComponents add Language failed: ' . $mysqli->error);
    }
    _migComponentLang_out('[add ] tblSongComponents.Language (VARCHAR(35) NULL).');
}

_migComponentLang_out('Song Component Language migration finished (#858).');
