<?php

declare(strict_types=1);

/**
 * iHymns — Credit-people partial birth/death dates (precision flags)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Historical writers often only have a known YEAR (or month + year) of birth /
 * death. The `BirthDate` / `DeathDate` columns stay `DATE` (so they still sort and
 * query), but a partial date is stored normalised to the FIRST of the period
 * (1823 → 1823-01-01, March 1823 → 1823-03-01) and a precision flag records how
 * much of it is real:
 *
 *   - BirthDatePrecision / DeathDatePrecision = 'year' | 'month' | 'day'
 *     (VARCHAR app-validated — rule #20, never an ENUM; NULL when no date).
 *
 * The shared helper includes/partial_date.php parses the editor input
 * (YYYY / MM/YYYY / DD/MM/YYYY) into (date, precision) and formats it back for
 * display ("1823" / "March 1823" / "15 March 1823").
 *
 * Backfill: every existing non-NULL date is a full date, so its precision is 'day'.
 *
 * Additive + IDEMPOTENT (columnExists-gated). VARCHAR not ENUM (rule #20).
 *
 * @migration-adds tblCreditPeople.BirthDatePrecision
 * @migration-adds tblCreditPeople.DeathDatePrecision
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-creditpeople-date-precision.php
 *   Web:  /manage/setup-database → "Credit-people partial dates" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migCPD_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCPD_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migCPD_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCPD_output("");
_migCPD_output("=== iHymns — Credit-people partial birth/death dates ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migCPD_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migCPD_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migCPD_columnExists($mysql, 'tblCreditPeople', 'BirthDatePrecision')) {
        _migCPD_output("  [SKIP] tblCreditPeople.BirthDatePrecision already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
               ADD COLUMN BirthDatePrecision VARCHAR(5) NULL DEFAULT NULL
                   COMMENT 'How much of BirthDate is real: year | month | day (partial historical dates)' AFTER BirthDate"
        );
        _migCPD_output("  [OK] Added tblCreditPeople.BirthDatePrecision.");
    }

    if (_migCPD_columnExists($mysql, 'tblCreditPeople', 'DeathDatePrecision')) {
        _migCPD_output("  [SKIP] tblCreditPeople.DeathDatePrecision already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
               ADD COLUMN DeathDatePrecision VARCHAR(5) NULL DEFAULT NULL
                   COMMENT 'How much of DeathDate is real: year | month | day (partial historical dates)' AFTER DeathDate"
        );
        _migCPD_output("  [OK] Added tblCreditPeople.DeathDatePrecision.");
    }

    /* Backfill: existing non-NULL dates are full dates → precision 'day'.
       Idempotent (re-running re-sets 'day' on rows that still have no precision). */
    $mysql->query("UPDATE tblCreditPeople SET BirthDatePrecision = 'day' WHERE BirthDate IS NOT NULL AND BirthDatePrecision IS NULL");
    $mysql->query("UPDATE tblCreditPeople SET DeathDatePrecision = 'day' WHERE DeathDate IS NOT NULL AND DeathDatePrecision IS NULL");
    _migCPD_output("  [OK] Backfilled existing full dates to precision 'day'.");

    _migCPD_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migCPD_output("ERROR: " . $e->getMessage());
}
