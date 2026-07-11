<?php

declare(strict_types=1);

/**
 * iHymns — Credit-people optional Maiden Surname (#1501)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * ELI5: some hymn writers/composers are catalogued under a name they took
 * on later in life (typically a married surname) but were BORN under a
 * different surname. This adds one optional field to record that birth
 * surname alongside the existing structured-name columns (FirstNames /
 * Surname / Suffix, #934) WITHOUT touching the canonical `Name` the
 * song-credit tables already cite.
 *
 * DETAILED: this is deliberately a dedicated column, not a repurposed row
 * in the existing `tblCreditPersonAliases` 'maiden' alias type (#1348's
 * sibling feature) — an alias is a free-text ALTERNATE NAME a curator
 * might search on; MaidenSurname is a structured BIOGRAPHICAL FACT that
 * sits next to Surname in the Edit drawer and in the search haystack, the
 * same way BirthPlace/BirthDate already do. Nothing stops a curator from
 * ALSO adding a "maiden name" alias row if the full birth name should be
 * searchable — the two features are complementary, not overlapping.
 *
 * Additive + IDEMPOTENT (columnExists-gated). VARCHAR (rule #20 — a
 * plain optional text field, not a growable vocabulary, so no ENUM
 * concern applies either way).
 *
 * @migration-adds tblCreditPeople.MaidenSurname
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-creditperson-maiden-surname.php
 *   Web:  /manage/setup-database → "Credit-person maiden surname" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migCPMS_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCPMS_columnExists(\mysqli $db, string $t, string $c): bool
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
if (!file_exists($credFile)) { _migCPMS_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCPMS_output("");
_migCPMS_output("=== iHymns — Credit-person optional Maiden Surname (#1501) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migCPMS_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migCPMS_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migCPMS_columnExists($mysql, 'tblCreditPeople', 'MaidenSurname')) {
        _migCPMS_output("  [SKIP] tblCreditPeople.MaidenSurname already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCreditPeople
               ADD COLUMN MaidenSurname VARCHAR(100) NULL DEFAULT NULL
                   COMMENT 'Optional birth surname, when different from the current Surname (#1501)' AFTER Surname"
        );
        _migCPMS_output("  [OK] Added tblCreditPeople.MaidenSurname.");
    }

    _migCPMS_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migCPMS_output("ERROR: " . $e->getMessage());
}
