<?php

declare(strict_types=1);

/**
 * iHymns — Multi-language tables for songbooks + songs (#778 phase A)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Songs / songbooks today carry exactly one Language tag (BCP 47, set
 * up in #673 / #681). Catalogues with songbooks that mix two related
 * languages — e.g. an English/Welsh hymnal, a Russian/Ukrainian
 * one — and songs whose verses cycle through different languages
 * can't be modelled cleanly with one column. Phase A introduces the
 * many-to-many tables; phases B-E (separate PRs) wire the read paths
 * + UI + filter union on top.
 *
 * Schema:
 *
 *   tblSongbookLanguages  — many-to-many from tblSongbooks.
 *   tblSongLanguages      — many-to-many from tblSongs.
 *
 * Both carry IsPrimary + SortOrder so a row's display order is
 * stable, and the picker can promote the user's primary choice
 * for headings / filter defaults.
 *
 * The legacy single-tag columns (tblSongbooks.Language,
 * tblSongs.Language) STAY POPULATED. Backfill copies their value
 * into the new tables with IsPrimary=1, SortOrder=0. Read paths
 * consuming the legacy columns continue to work. Phase F (a much
 * later PR) drops the legacy columns once read paths fully migrate.
 *
 * Idempotent — re-running on a deployment that's already been
 * migrated is a no-op (each step probes its target before acting).
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-multi-language-tables.php
 *   Web: /manage/setup-database → "Multi-language tables (#778 phase A)"
 *        (entry point requires global_admin)
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

function _migMultiLang_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migMultiLang_tableExists(mysqli $db, string $table): bool
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

function _migMultiLang_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migMultiLang_out('Multi-language tables migration starting (#778 phase A)…');

$db = getDbMysqli();
if (!$db) {
    _migMultiLang_out('ERROR: could not connect to database.');
    exit(1);
}

/* =========================================================================
 * Step 1 — tblSongbookLanguages
 * ========================================================================= */
if (_migMultiLang_tableExists($db, 'tblSongbookLanguages')) {
    _migMultiLang_out('[skip] tblSongbookLanguages already exists.');
} else {
    $sql = "CREATE TABLE tblSongbookLanguages (
        SongbookId  INT UNSIGNED NOT NULL,
        Language    VARCHAR(35)  NOT NULL COMMENT 'IETF BCP 47 tag — same shape as tblSongbooks.Language',
        IsPrimary   TINYINT(1)   NOT NULL DEFAULT 0
                    COMMENT 'Display-default language for this songbook; exactly one row per songbook should carry IsPrimary=1',
        SortOrder   SMALLINT     NOT NULL DEFAULT 0
                    COMMENT 'Render order in chip-list editor; lower comes first',
        CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (SongbookId, Language),
        KEY idx_Language (Language),
        KEY idx_Primary  (SongbookId, IsPrimary),
        CONSTRAINT fk_sblang_songbook FOREIGN KEY (SongbookId)
            REFERENCES tblSongbooks(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migMultiLang_out('ERROR: create tblSongbookLanguages failed: ' . $db->error);
        exit(1);
    }
    _migMultiLang_out('[add ] tblSongbookLanguages.');
}

/* =========================================================================
 * Step 2 — tblSongLanguages
 * ========================================================================= */
if (_migMultiLang_tableExists($db, 'tblSongLanguages')) {
    _migMultiLang_out('[skip] tblSongLanguages already exists.');
} else {
    $sql = "CREATE TABLE tblSongLanguages (
        SongId      VARCHAR(20) NOT NULL,
        Language    VARCHAR(35) NOT NULL,
        IsPrimary   TINYINT(1)  NOT NULL DEFAULT 0,
        SortOrder   SMALLINT    NOT NULL DEFAULT 0,
        CreatedAt   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (SongId, Language),
        KEY idx_Language (Language),
        KEY idx_Primary  (SongId, IsPrimary),
        CONSTRAINT fk_slang_song FOREIGN KEY (SongId)
            REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migMultiLang_out('ERROR: create tblSongLanguages failed: ' . $db->error);
        exit(1);
    }
    _migMultiLang_out('[add ] tblSongLanguages.');
}

/* =========================================================================
 * Step 3 — Backfill from legacy single-tag columns
 *
 * For every row whose legacy Language column is non-empty, INSERT IGNORE
 * a corresponding row into the new table with IsPrimary=1, SortOrder=0.
 * Already-present rows (re-runs after the first backfill) are no-ops via
 * the unique key on (SongbookId/SongId, Language).
 * ========================================================================= */

/* tblSongbooks → tblSongbookLanguages */
if (_migMultiLang_columnExists($db, 'tblSongbooks', 'Language')) {
    $res = $db->query(
        "INSERT IGNORE INTO tblSongbookLanguages (SongbookId, Language, IsPrimary, SortOrder)
         SELECT Id, Language, 1, 0
           FROM tblSongbooks
          WHERE Language IS NOT NULL AND Language <> ''"
    );
    if ($res === false) {
        _migMultiLang_out('WARN: tblSongbooks backfill failed: ' . $db->error);
    } else {
        $rows = $db->affected_rows;
        _migMultiLang_out("[seed ] tblSongbookLanguages: {$rows} primary row(s) backfilled from tblSongbooks.Language.");
    }
} else {
    _migMultiLang_out('[skip ] tblSongbooks.Language column missing — nothing to backfill.');
}

/* tblSongs → tblSongLanguages */
if (_migMultiLang_columnExists($db, 'tblSongs', 'Language')) {
    $res = $db->query(
        "INSERT IGNORE INTO tblSongLanguages (SongId, Language, IsPrimary, SortOrder)
         SELECT SongId, Language, 1, 0
           FROM tblSongs
          WHERE Language IS NOT NULL AND Language <> ''"
    );
    if ($res === false) {
        _migMultiLang_out('WARN: tblSongs backfill failed: ' . $db->error);
    } else {
        $rows = $db->affected_rows;
        _migMultiLang_out("[seed ] tblSongLanguages: {$rows} primary row(s) backfilled from tblSongs.Language.");
    }
} else {
    _migMultiLang_out('[skip ] tblSongs.Language column missing — nothing to backfill.');
}

/* =========================================================================
 * Final sanity probe
 * ========================================================================= */
$res = $db->query('SELECT COUNT(*) FROM tblSongbookLanguages');
$bookLangCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(*) FROM tblSongLanguages');
$songLangCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
_migMultiLang_out("Catalogue now carries {$bookLangCount} songbook-language row(s) and {$songLangCount} song-language row(s).");

_migMultiLang_out('Multi-language tables migration finished (#778 phase A).');
