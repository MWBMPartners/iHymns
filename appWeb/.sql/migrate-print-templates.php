<?php

declare(strict_types=1);

/**
 * iHymns — Print templates (#1350 Phase 2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates tblPrintTemplates — curator-authored, block-based print layouts for the
 * clean song-print path (Phase 1 shipped 3 hard-coded built-ins; this lets curators
 * compose + save their own in the WYSIWYG editor at /manage/print-templates).
 *
 * Forward-looking one-pass design (rule #20): the layout is a JSON ORDERED BLOCK LIST
 * (BlocksJson) + page-level options (PageOptionsJson), so adding a new block type or
 * option NEVER needs an ALTER — it's just new JSON the editor + renderer understand.
 * Scope is VARCHAR (song | setlist | …), not ENUM. Templates are global/curated by
 * default (OwnerId NULL); the OwnerId FK reserves per-user templates without a second
 * migration. The 3 built-ins stay in js/modules/print.js (always available offline);
 * this table holds only the custom ones, merged into the picker.
 *
 * @migration-adds tblPrintTemplates
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-print-templates.php
 *   Web:  /manage/setup-database → "Print templates (#1350)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migPT_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migPT_tableExists(\mysqli $db, string $t): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->bind_param('s', $t); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPT_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPT_output("");
_migPT_output("=== iHymns — Print templates (#1350 Phase 2) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migPT_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migPT_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migPT_tableExists($mysql, 'tblPrintTemplates')) {
        _migPT_output("  [SKIP] tblPrintTemplates already present.");
    } else {
        /* DDL is byte-identical to appWeb/.sql/schema.sql (rule #19). */
        $mysql->query(
            "CREATE TABLE tblPrintTemplates (
                Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                Name            VARCHAR(120)    NOT NULL COMMENT 'Curator-visible template name',
                Scope           VARCHAR(20)     NOT NULL DEFAULT 'song' COMMENT 'song | setlist | … (VARCHAR not ENUM, rule #20 — new scopes need no ALTER)',
                OwnerId         INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — NULL = global/curated template (reserves per-user templates without a second migration)',
                BlocksJson      JSON            NOT NULL COMMENT 'Ordered block list: [{type, …options}] — title/subtitle/credits/lyrics/copyright/identifiers/spacer/pagebreak/text. Adding a block type needs no ALTER.',
                PageOptionsJson JSON            NULL DEFAULT NULL COMMENT 'Page-level options (base font pt, columns, …)',
                IsActive        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = hidden from the picker without deleting',
                IsDefault       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = pre-selected in the picker for its scope',
                SortOrder       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Picker order',
                CreatedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who created it',
                CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_ScopeActive (Scope, IsActive, SortOrder),
                INDEX idx_Owner (OwnerId),

                CONSTRAINT fk_PrintTemplate_Owner
                    FOREIGN KEY (OwnerId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migPT_output("  [OK] Created tblPrintTemplates.");
    }
    _migPT_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migPT_output("ERROR: " . $e->getMessage());
}
