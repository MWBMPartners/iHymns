<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Compilers Migration (#831)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblSongbookCompilers — a many-to-many join between tblSongbooks
 * and tblCreditPeople so a hymnal can record the people who curated /
 * edited / compiled it. Distinct from per-song credits (writer /
 * composer / arranger / adaptor / translator / artist) — this is a
 * credit at the *songbook* level.
 *
 *   • Mission Praise → Peter Horrobin & Greg Leavers
 *   • Christ in Song → Frank E. Belden
 *   • The Church Hymnal → editorial committee (one row per member,
 *     SortOrder preserves the order they appear on the title page)
 *
 * Same shape as tblSongbookSeriesMembership: SortOrder + per-row Note
 * for edition / qualifier context (e.g. "5th edition", "with B. Smith").
 *
 * Schema:
 *   CREATE TABLE tblSongbookCompilers (
 *       Id              auto-increment PK
 *       SongbookId      FK → tblSongbooks(Id)        ON DELETE CASCADE
 *       CreditPersonId  FK → tblCreditPeople(Id)     ON DELETE CASCADE
 *       SortOrder       per-row order on the title page
 *       Note            optional context, e.g. edition / co-compiler
 *       CreatedAt       audit timestamp
 *       UNIQUE (SongbookId, CreditPersonId)  — same person not listed twice
 *   )
 *
 * Idempotent — INFORMATION_SCHEMA probe; CREATE skips if the table
 * already exists. No data backfill (compilers are curator-entered,
 * not derivable from existing columns).
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-songbook-compilers.php
 *   Web: /manage/setup-database → "Songbook Compilers (#831)"
 *        (entry point requires global_admin)
 *
 * @migration-adds tblSongbookCompilers
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

function _migCompiler_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migCompiler_tableExists(\mysqli $db, string $table): bool
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

_migCompiler_out('Songbook Compilers migration starting (#831)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* The two parent tables must exist — this is a join table. */
foreach (['tblSongbooks', 'tblCreditPeople'] as $required) {
    if (!_migCompiler_tableExists($mysqli, $required)) {
        _migCompiler_out("ERROR: {$required} not found. Run the prerequisite migrations first.");
        return;
    }
}

if (_migCompiler_tableExists($mysqli, 'tblSongbookCompilers')) {
    _migCompiler_out('[skip] tblSongbookCompilers already present.');
} else {
    $sql = "CREATE TABLE tblSongbookCompilers (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongbookId      INT UNSIGNED NOT NULL,
        CreditPersonId  INT UNSIGNED NOT NULL,
        SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
        Note            VARCHAR(255) NULL,
        CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

        UNIQUE KEY uq_book_person (SongbookId, CreditPersonId),
        INDEX idx_book   (SongbookId),
        INDEX idx_person (CreditPersonId),

        CONSTRAINT fk_compiler_book
            FOREIGN KEY (SongbookId)     REFERENCES tblSongbooks(Id)    ON DELETE CASCADE,
        CONSTRAINT fk_compiler_person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblSongbookCompilers failed: ' . $mysqli->error);
    }
    _migCompiler_out('[add ] tblSongbookCompilers.');
}

_migCompiler_out('Songbook Compilers migration finished (#831).');
