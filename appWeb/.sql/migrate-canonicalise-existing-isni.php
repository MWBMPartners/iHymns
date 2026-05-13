<?php

declare(strict_types=1);

/**
 * iHymns — Canonicalise Existing ISNI Rows Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The first ISNI rows in tblCreditPersonIdentifiers were stored as bare
 * 16-character strings ("0000000121032683") because the previous version
 * of normaliseCreditPersonIsni() stripped separators on save. Search +
 * link-out + the UNIQUE constraint all work better when every ISNI is
 * stored in the canonical ISO 27729 display form "NNNN NNNN NNNN NNNX".
 *
 * This migration re-formats every existing ISNI row through the same
 * canonicaliseIsni() helper the runtime save path uses, so historic
 * rows match the new convention. Idempotent: rows already in canonical
 * form are no-ops; only changed rows get UPDATE'd.
 *
 * Race against UNIQUE: if two rows on the same person happen to
 * canonicalise to the same string (e.g. one stored bare-digits, another
 * with hyphens but representing the same ISNI), the second UPDATE will
 * fail on uk_PersonIdValue. We catch the duplicate-key error and DELETE
 * the redundant row instead so the curator ends up with the surviving
 * canonical record.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-canonicalise-existing-isni.php
 *   Web: /manage/setup-database → "Canonicalise Existing ISNI Rows"
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    if (!function_exists('canonicaliseIsni')) {
        require_once dirname(__DIR__) . '/public_html/includes/credit_people_helpers.php';
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
    if (!function_exists('canonicaliseIsni')) {
        require_once dirname(__DIR__) . '/public_html/includes/credit_people_helpers.php';
    }
    $isCli = false;
}

function _migIsniCanon_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migIsniCanon_tableExists(\mysqli $db, string $table): bool
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

_migIsniCanon_out('Canonicalise Existing ISNI Rows migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migIsniCanon_tableExists($mysqli, 'tblCreditPersonIdentifiers')) {
    _migIsniCanon_out('[skip] tblCreditPersonIdentifiers not present — run'
        . ' migrate-credit-person-identifiers.php first.');
    return;
}

$stmt = $mysqli->prepare(
    "SELECT Id, CreditPersonId, IdentifierValue
       FROM tblCreditPersonIdentifiers
      WHERE IdentifierType = 'isni'"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$updated = 0;
$skipped = 0;
$deletedDupes = 0;

$updStmt = $mysqli->prepare(
    'UPDATE tblCreditPersonIdentifiers SET IdentifierValue = ? WHERE Id = ?'
);
$delStmt = $mysqli->prepare('DELETE FROM tblCreditPersonIdentifiers WHERE Id = ?');

foreach ($rows as $r) {
    $id          = (int)$r['Id'];
    $currentVal  = (string)$r['IdentifierValue'];
    $canonical   = canonicaliseIsni($currentVal);
    if ($canonical === $currentVal) {
        $skipped++;
        continue;
    }
    $updStmt->bind_param('si', $canonical, $id);
    if ($updStmt->execute()) {
        $updated++;
    } else {
        /* Duplicate-key race: another row on the same person already
           holds the canonical form. Drop this row instead so the
           surviving record is the canonical one. */
        if ($mysqli->errno === 1062) {
            $delStmt->bind_param('i', $id);
            $delStmt->execute();
            $deletedDupes++;
        } else {
            throw new \RuntimeException('UPDATE failed: ' . $mysqli->error);
        }
    }
}
$updStmt->close();
$delStmt->close();

_migIsniCanon_out("[done] {$updated} row" . ($updated === 1 ? '' : 's')
    . " canonicalised, {$skipped} already canonical"
    . ($deletedDupes > 0 ? ", {$deletedDupes} duplicate" . ($deletedDupes === 1 ? '' : 's') . ' removed' : '')
    . '.');
_migIsniCanon_out('Canonicalise Existing ISNI Rows migration finished.');
