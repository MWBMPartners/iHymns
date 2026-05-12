<?php

declare(strict_types=1);

/**
 * iHymns — Credit People AKA / Alias names (#claude/credit-people-aliases)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblCreditPersonAliases so curators can record alternative names
 * for a credit-person to assist searchability. Mirrors the MusicBrainz
 * alias model in slimmed-down form:
 *
 *   - One row per (CreditPersonId, Name) — UNIQUE enforces no dupes.
 *   - Type ENUM classifies the alias (legal, artist, pseudonym, nickname,
 *     maiden, search-hint, misspelling, other).
 *   - Optional Locale tag (IETF BCP 47) for transliterations:
 *     "John Doe" (en) ↔ "ジョン・ドウ" (ja).
 *   - SortName (optional) for surname-first display where useful.
 *   - IsPrimary lets a curator mark one alias per locale as the
 *     preferred display form.
 *
 * SEARCH IMPACT:
 *   Site search, credit-people admin search, and the editor's
 *   typeahead all UNION this table into their LIKE / FULLTEXT queries
 *   so a user typing the alias name finds the canonical person.
 *
 * PUBLIC SURFACE:
 *   /people/<slug> renders aliases under the bio header + emits
 *   JSON-LD alternateName.
 *
 * BULK IMPORT:
 *   The bulk-import parser detects "Smith (a.k.a. Jones)" /
 *   "Smith / Jones" / "Smith (Jones)" patterns in incoming credit
 *   strings and creates aliases automatically.
 *
 * Idempotent — uses CREATE TABLE IF NOT EXISTS. Re-runs no-op.
 *
 * @migration-adds tblCreditPersonAliases
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-credit-people-aliases.php
 *   Web: /manage/setup-database → "Credit People Aliases"
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

function _migCpAlias_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migCpAlias_tableExists(\mysqli $db, string $table): bool
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

_migCpAlias_out('Credit People Aliases migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migCpAlias_tableExists($mysqli, 'tblCreditPeople')) {
    _migCpAlias_out('[skip] tblCreditPeople not present — run migrate-credit-people.php (#545) first.');
    return;
}

if (_migCpAlias_tableExists($mysqli, 'tblCreditPersonAliases')) {
    _migCpAlias_out('[skip] tblCreditPersonAliases already present.');
} else {
    $sql = "CREATE TABLE tblCreditPersonAliases (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        CreditPersonId  INT UNSIGNED NOT NULL,
        Name            VARCHAR(255) NOT NULL COMMENT 'Display form of the alias',
        SortName        VARCHAR(255) NULL COMMENT 'Surname-first sortable form; NULL = derive from Name',
        Type            ENUM('legal','artist','pseudonym','nickname','maiden','search-hint','misspelling','other')
                                     NOT NULL DEFAULT 'other'
                                     COMMENT 'MusicBrainz-style alias classification',
        Locale          VARCHAR(35)  NULL COMMENT 'Optional IETF BCP 47 tag for transliterations (ja, ru-Latn, zh-Hans, …)',
        IsPrimary       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = preferred display form in this Locale',
        SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
        Note            VARCHAR(255) NULL,
        CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uq_person_name (CreditPersonId, Name),
        INDEX idx_person (CreditPersonId),
        INDEX idx_name   (Name),
        INDEX idx_type   (Type),

        CONSTRAINT fk_alias_person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblCreditPersonAliases failed: ' . $mysqli->error);
    }
    _migCpAlias_out('[add ] tblCreditPersonAliases.');
}

_migCpAlias_out('Credit People Aliases migration finished.');
