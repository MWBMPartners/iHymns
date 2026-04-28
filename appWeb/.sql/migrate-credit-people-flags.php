<?php

declare(strict_types=1);

/**
 * iHymns — Credit People classification flags migration (#584, #585)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds two boolean classification flags to tblCreditPeople so the
 * registry can distinguish:
 *
 *   1. Special-case attributions (#584) — Anonymous, Traditional,
 *      Public Domain, Unknown — which today get registered as if
 *      they were real people, mixing into autocomplete and the
 *      registry list.
 *   2. Groups / bands / collectives (#585) — Hillsong United,
 *      Bethel Music, Sovereign Grace Music, Stuart Townend & Keith
 *      Getty — which don't have a single birth/death date and read
 *      as one credit even though they're "many people".
 *
 * Schema additions:
 *   tblCreditPeople.IsSpecialCase  TINYINT(1) NOT NULL DEFAULT 0
 *   tblCreditPeople.IsGroup        TINYINT(1) NOT NULL DEFAULT 0
 *
 * @migration-adds tblCreditPeople.IsSpecialCase
 * @migration-adds tblCreditPeople.IsGroup
 *
 * One-time backfill: any row whose Name matches a known special-case
 * label gets IsSpecialCase=1 set. Curators can refine later via the
 * registry's Edit Person dialog.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-credit-people-flags.php
 *
 * Idempotent — re-running is safe; columns that already exist are
 * skipped, the backfill runs only over rows that don't already have
 * the flag set.
 */

if (PHP_SAPI === 'cli') {
    /* CLI bootstrap mirrors the other migrate-*.php files. */
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        require_once __DIR__ . '/../public_html/manage/includes/auth.php';
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
    require_once __DIR__ . '/../public_html/includes/db_mysql.php';
    $isCli = false;
}

function _migCpFlags_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    @ob_flush();
    @flush();
}

function _migCpFlags_columnExists(mysqli $db, string $table, string $column): bool
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

_migCpFlags_out('Credit People flags migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migCpFlags_out('ERROR: could not connect to database.');
    exit(1);
}

/* The base credit-people tables must already exist — this migration
   only adds columns. If the parent migration has not yet been run we
   bail with a clear pointer rather than failing later in an ALTER. */
$stmt = $mysqli->prepare(
    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
);
$tableName = 'tblCreditPeople';
$stmt->bind_param('s', $tableName);
$stmt->execute();
$baseExists = $stmt->get_result()->fetch_row() !== null;
$stmt->close();
if (!$baseExists) {
    _migCpFlags_out('ERROR: tblCreditPeople not found. Run migrate-credit-people first.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: add IsSpecialCase column (#584)
 * ---------------------------------------------------------------------- */
if (_migCpFlags_columnExists($mysqli, 'tblCreditPeople', 'IsSpecialCase')) {
    _migCpFlags_out('[skip] tblCreditPeople.IsSpecialCase already present.');
} else {
    $sql = 'ALTER TABLE tblCreditPeople
            ADD COLUMN IsSpecialCase TINYINT(1) NOT NULL DEFAULT 0
            AFTER DeathDate';
    if (!$mysqli->query($sql)) {
        _migCpFlags_out('ERROR: adding IsSpecialCase failed: ' . $mysqli->error);
        exit(1);
    }
    _migCpFlags_out('[add ] tblCreditPeople.IsSpecialCase.');
}

/* ----------------------------------------------------------------------
 * Step 2: add IsGroup column (#585)
 * ---------------------------------------------------------------------- */
if (_migCpFlags_columnExists($mysqli, 'tblCreditPeople', 'IsGroup')) {
    _migCpFlags_out('[skip] tblCreditPeople.IsGroup already present.');
} else {
    $sql = 'ALTER TABLE tblCreditPeople
            ADD COLUMN IsGroup TINYINT(1) NOT NULL DEFAULT 0
            AFTER IsSpecialCase';
    if (!$mysqli->query($sql)) {
        _migCpFlags_out('ERROR: adding IsGroup failed: ' . $mysqli->error);
        exit(1);
    }
    _migCpFlags_out('[add ] tblCreditPeople.IsGroup.');
}

/* ----------------------------------------------------------------------
 * Step 3: one-time backfill of known special-case labels (#584)
 * ---------------------------------------------------------------------- */
$specialNames = ['Anonymous', 'Traditional', 'Public Domain', 'Unknown'];
$placeholders = implode(',', array_fill(0, count($specialNames), '?'));
$sql = "UPDATE tblCreditPeople
           SET IsSpecialCase = 1
         WHERE IsSpecialCase = 0
           AND Name IN ($placeholders)";
$stmt = $mysqli->prepare($sql);
$types = str_repeat('s', count($specialNames));
$stmt->bind_param($types, ...$specialNames);
if ($stmt->execute()) {
    $flagged = $stmt->affected_rows;
    $stmt->close();
    if ($flagged > 0) {
        _migCpFlags_out("[seed] flagged {$flagged} known special-case row" . ($flagged === 1 ? '' : 's') . '.');
    } else {
        _migCpFlags_out('[seed] no special-case rows needed flagging.');
    }
} else {
    _migCpFlags_out('WARN: backfill failed: ' . $mysqli->error);
}

_migCpFlags_out('Credit People flags migration finished.');
