<?php

declare(strict_types=1);

/**
 * iHymns — Per-entity colour columns for Catalogues + Songbook Series (#1181)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Songbooks already carry a badge `Colour` (tblSongbooks.Colour). The owner
 * wants the same for **Catalogues / Collections**, and for a **Songbook Series**
 * to define ONE colour that all its member songbooks share. This migration adds
 * the two colour columns; the resolution (a songbook's effective colour = its
 * own Colour → else its series' Colour → else theme default) + the admin colour
 * pickers land alongside it. The auto-contrast pass (#1179) means any chosen
 * colour stays readable, so colours can vary widely.
 *
 * Both columns are VARCHAR(7) hex (#RRGGBB), empty = theme default — byte-for-byte
 * the same shape as tblSongbooks.Colour so the existing validate/auto-pick helpers
 * apply unchanged.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column existence guarded).
 *
 * @migration-adds tblCatalogues.Colour
 * @migration-adds tblSongbookSeries.Colour
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-entity-colours.php
 *   Web:  /manage/setup-database → "Catalogue + Series colours" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migEntityColour_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migEntityColour_columnExists(\mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migEntityColour_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migEntityColour_output("");
_migEntityColour_output("=== iHymns — Catalogue + Songbook-Series colours (#1181) ===");
_migEntityColour_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migEntityColour_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migEntityColour_output("Connected to MySQL: " . DB_NAME);

try {
    /* tblCatalogues.Colour */
    _migEntityColour_output("--- tblCatalogues.Colour ---");
    if (_migEntityColour_columnExists($mysql, 'tblCatalogues', 'Colour')) {
        _migEntityColour_output("  [SKIP] tblCatalogues.Colour already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblCatalogues
                ADD COLUMN Colour VARCHAR(7) NOT NULL DEFAULT ''
                    COMMENT 'Badge colour hex #RRGGBB (empty = theme default) (#1181)'"
        );
        _migEntityColour_output("  [OK] Added tblCatalogues.Colour.");
    }

    /* tblSongbookSeries.Colour — inherited by all member songbooks. */
    _migEntityColour_output("--- tblSongbookSeries.Colour ---");
    if (_migEntityColour_columnExists($mysql, 'tblSongbookSeries', 'Colour')) {
        _migEntityColour_output("  [SKIP] tblSongbookSeries.Colour already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongbookSeries
                ADD COLUMN Colour VARCHAR(7) NOT NULL DEFAULT ''
                    COMMENT 'Badge colour hex #RRGGBB inherited by all member songbooks; empty = theme default (#1181)'"
        );
        _migEntityColour_output("  [OK] Added tblSongbookSeries.Colour.");
    }

    _migEntityColour_output("");
    _migEntityColour_output("--- Summary ---");
    _migEntityColour_output("  Catalogues + Songbook Series can now carry a badge colour. A songbook's");
    _migEntityColour_output("  effective colour resolves own → series → theme default; the admin colour");
    _migEntityColour_output("  pickers + the render resolution land alongside this migration (#1181).");
    _migEntityColour_output("");
    _migEntityColour_output("Migration complete.");
} catch (\Throwable $e) {
    _migEntityColour_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
