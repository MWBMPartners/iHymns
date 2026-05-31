<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Language Column Migration (#673)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds an optional Language VARCHAR(10) NULL column to tblSongbooks
 * so a curator can tag a songbook with the ISO 639-1 code of its
 * predominant language. Mirrors tblSongs.Language without the
 * NOT NULL or DEFAULT 'en' (#673):
 *
 *   - NULLable so a curated grouping that mixes languages can sit
 *     unmarked instead of being mislabelled "en".
 *   - Soft validation via the tblLanguages-sourced dropdown in the
 *     songbook editor; no FK so a new ISO code added to the
 *     reference table doesn't require a CHECK refresh.
 *
 * @migration-adds tblSongbooks.Language
 *
 * Idempotent — re-running is safe; the column-existence probe
 * (INFORMATION_SCHEMA.COLUMNS) skips the ALTER if it's already there.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songbook-language.php
 *   Web: /manage/setup-database → "Songbook Language Column Migration"
 *        (entry point requires global_admin)
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. */
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

function _migSbLang_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migSbLang_columnExists(mysqli $db, string $table, string $column): bool
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

function _migSbLang_tableExists(mysqli $db, string $table): bool
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

_migSbLang_out('Songbook Language column migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migSbLang_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migSbLang_tableExists($mysqli, 'tblSongbooks')) {
    _migSbLang_out('ERROR: tblSongbooks not found. Run install.php first.');
    exit(1);
}

if (_migSbLang_columnExists($mysqli, 'tblSongbooks', 'Language')) {
    _migSbLang_out('[skip] tblSongbooks.Language already present.');
} else {
    /* AFTER Affiliation so the column lands next to the rest of the
       basic metadata block (#502 + #670). The position is cosmetic
       only — SELECT in SongData refers to columns by name. */
    $sql = "ALTER TABLE tblSongbooks
            ADD COLUMN Language VARCHAR(10) NULL DEFAULT NULL
            AFTER Affiliation";
    if (!$mysqli->query($sql)) {
        _migSbLang_out('ERROR: adding Language failed: ' . $mysqli->error);
        exit(1);
    }
    _migSbLang_out('[add ] tblSongbooks.Language.');
}

_migSbLang_out('Songbook Language column migration finished.');
