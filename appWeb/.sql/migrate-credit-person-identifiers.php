<?php

declare(strict_types=1);

/**
 * iHymns — Unified Credit-Person Identifiers Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds ISNI (International Standard Name Identifier) support alongside
 * the existing IPI Name Numbers (#545) by unifying both into a single
 * `tblCreditPersonIdentifiers` table with an IdentifierType discriminator
 * — the MusicBrainz-style shape that future identifier kinds (ISWC for
 * works lives in a separate table, but other person-level codes like
 * GRid, Spotify artist ID, MusicBrainz MBID, …) can join without a fresh
 * schema migration each time.
 *
 *   tblCreditPersonIdentifiers
 *     - Id, CreditPersonId
 *     - IdentifierType ENUM('ipi','isni')       — extensible: ALTER … MODIFY
 *     - IdentifierValue VARCHAR(64)              — the numeric/ID string
 *     - NameUsed       VARCHAR(255)  NULL        — performing name for this row
 *     - Notes          VARCHAR(255)  NULL
 *     - CreatedAt, UpdatedAt
 *     - UNIQUE (CreditPersonId, IdentifierType, IdentifierValue)
 *
 * Backfill copies every row in `tblCreditPersonIPI` over with
 * IdentifierType = 'ipi'. The legacy table stays in place untouched as
 * an emergency-rollback snapshot; a later migration drops it once a
 * release has shipped on the new system (the same one-release-cycle
 * convention `migrate-backfill-songbook-links.php` documents for the
 * songbook URL columns).
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-credit-person-identifiers.php
 *   Web: /manage/setup-database → "Credit-Person Identifiers (IPI + ISNI)"
 *
 * @migration-adds tblCreditPersonIdentifiers
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

function _migCpIds_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migCpIds_tableExists(\mysqli $db, string $table): bool
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

_migCpIds_out('Credit-Person Identifiers migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migCpIds_tableExists($mysqli, 'tblCreditPeople')) {
    _migCpIds_out('ERROR: tblCreditPeople not found. Run migrate-credit-people.php (#545) first.');
    return;
}

/* ----------------------------------------------------------------------
 * Step 1 — tblCreditPersonIdentifiers
 * ---------------------------------------------------------------------- */
if (_migCpIds_tableExists($mysqli, 'tblCreditPersonIdentifiers')) {
    _migCpIds_out('[skip] tblCreditPersonIdentifiers already present.');
} else {
    $sql = "CREATE TABLE tblCreditPersonIdentifiers (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        CreditPersonId  INT UNSIGNED    NOT NULL,
        IdentifierType  ENUM('ipi','isni') NOT NULL,
        IdentifierValue VARCHAR(64)     NOT NULL,
        NameUsed        VARCHAR(255)    NULL,
        Notes           VARCHAR(255)    NULL,
        CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_PersonIdValue (CreditPersonId, IdentifierType, IdentifierValue),
        INDEX idx_TypeValue (IdentifierType, IdentifierValue),
        CONSTRAINT fk_CreditPersonId_Person
            FOREIGN KEY (CreditPersonId) REFERENCES tblCreditPeople(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('CREATE TABLE tblCreditPersonIdentifiers failed: ' . $mysqli->error);
    }
    _migCpIds_out('[add ] tblCreditPersonIdentifiers.');
}

/* ----------------------------------------------------------------------
 * Step 2 — Backfill IPI rows
 *
 * INSERT … SELECT with a NOT EXISTS guard keeps this idempotent — re-runs
 * leave already-migrated rows untouched. `tblCreditPersonIPI` may not
 * exist on a fresh install that ran this migration before #545; that's
 * fine, we just skip the backfill.
 * ---------------------------------------------------------------------- */
if (!_migCpIds_tableExists($mysqli, 'tblCreditPersonIPI')) {
    _migCpIds_out('[skip] tblCreditPersonIPI not present — no IPI rows to backfill.');
} else {
    $sql = "INSERT INTO tblCreditPersonIdentifiers
                 (CreditPersonId, IdentifierType, IdentifierValue, NameUsed, Notes, CreatedAt, UpdatedAt)
            SELECT src.CreditPersonId, 'ipi', src.IPINumber, src.NameUsed, src.Notes, src.CreatedAt, src.UpdatedAt
              FROM tblCreditPersonIPI AS src
             WHERE NOT EXISTS (
                   SELECT 1 FROM tblCreditPersonIdentifiers AS dst
                    WHERE dst.CreditPersonId  = src.CreditPersonId
                      AND dst.IdentifierType  = 'ipi'
                      AND dst.IdentifierValue = src.IPINumber
             )";
    if (!$mysqli->query($sql)) {
        throw new \RuntimeException('Backfill INSERT failed: ' . $mysqli->error);
    }
    $copied = $mysqli->affected_rows;
    _migCpIds_out("[seed] {$copied} IPI row" . ($copied === 1 ? '' : 's')
        . ' copied from tblCreditPersonIPI to tblCreditPersonIdentifiers.');
}

_migCpIds_out('Credit-Person Identifiers migration finished.');
_migCpIds_out('Legacy table tblCreditPersonIPI left in place as a rollback snapshot.');
_migCpIds_out('A future migration will drop it once a release ships on the new table.');
