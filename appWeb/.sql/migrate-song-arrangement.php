<?php

declare(strict_types=1);

/**
 * iHymns — Song Arrangement Persistence Migration (#892)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds an `ArrangementJson` JSON column to `tblSongs` so the Song
 * Editor's Structure-tab arrangement (an array of indices into the
 * components[] list, allowing repetition — e.g. a refrain played
 * between every verse) can finally round-trip through the editor's
 * save → reload path.
 *
 * Pre-#892 the editor rendered the arrangement chips and POSTed
 * `song.arrangement` to `?action=save_song`, but the server-side
 * handler had no column to write into and silently dropped the
 * field. Curators saw the "Saved" toast, then reloaded to find
 * their custom order replaced by the bare SortOrder sequence.
 *
 * Schema: one nullable JSON column. NULL means "render components
 * in their stored SortOrder" (current behaviour); a JSON array of
 * non-negative integer indices overrides the order and may include
 * repetitions (the entire reason this column exists).
 *
 * Idempotent: probes INFORMATION_SCHEMA.COLUMNS first; skips if
 * the column already exists.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-song-arrangement.php
 *   Web: /manage/setup-database → "Song Arrangement Persistence (#892)"
 *
 * @migration-modifies tblSongs (adds ArrangementJson)
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

function _migSongArr_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migSongArr_columnExists(\mysqli $db, string $table, string $column): bool
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

function _migSongArr_tableExists(\mysqli $db, string $table): bool
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

_migSongArr_out('Song Arrangement Persistence migration starting (#892)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migSongArr_tableExists($mysqli, 'tblSongs')) {
    _migSongArr_out('ERROR: tblSongs not found. Run prerequisite migrations first.');
    return;
}

if (_migSongArr_columnExists($mysqli, 'tblSongs', 'ArrangementJson')) {
    _migSongArr_out('[skip] tblSongs.ArrangementJson already present.');
} else {
    /* AFTER LyricsText keeps the column adjacent to the lyric-shape
       data it qualifies. The JSON type validates well-formedness on
       INSERT — a malformed payload from a buggy client fails fast at
       the DB rather than silently storing garbage. */
    $sql = "ALTER TABLE tblSongs
              ADD COLUMN ArrangementJson JSON NULL DEFAULT NULL
              AFTER LyricsText";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('ALTER TABLE tblSongs add ArrangementJson failed: ' . $mysqli->error);
    }
    _migSongArr_out('[add ] tblSongs.ArrangementJson (JSON NULL).');
}

_migSongArr_out('Song Arrangement Persistence migration finished (#892).');
