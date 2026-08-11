<?php

declare(strict_types=1);

/**
 * iHymns — Print template custom layouts + org-scoped templates (#1767 remainder, P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The one-pass, additive, DORMANT schema batch for feature E (uploadable
 * full-page print layouts) and the reserved column for feature K (org-default
 * templates) from the #1767 print-templates remainder plan
 * (.claude/print-templates-1767-remainder-plan.md §7/§9). Nothing here is read
 * by any live code path yet — the sanitiser (includes/html_sanitizer.php, P2 of
 * the same remainder) and the upload UI (P7) land in later commits.
 *
 * Creates:
 *
 *   - tblPrintTemplateCustomLayout — one row per (template, slot) full-page
 *     HTML "skin" a curator uploads. Only ihymnsSanitizeHtml()'s OUTPUT
 *     (HtmlSanitised) is ever render-served; the raw upload (HtmlOriginal) is
 *     kept dormant, solely so a future allow-list WIDENING can re-sanitise
 *     from source — no render path may read it (guard-banned, see the plan's
 *     §5.4/§7.3 and the forthcoming tests/php/test-html-sanitizer.php). Slot
 *     is reserved (uq_TemplateSlot) for a future per-page-role layout
 *     (cover/continuation/…) — v1 only ever writes 'page', so this whole
 *     table is designed final-shape up front rather than needing a second
 *     migration when that need lands (rule #20).
 *
 *   - tblPrintTemplates.OrgId — RESERVED DORMANT column for feature K
 *     (org-default templates, #1767). NULL = global/curated (today's only
 *     value written by any code path); a value scopes a template to one
 *     org's members. Landing the column now — even though the picker/editor
 *     UI for it may lag (§10 P9) — means K needs zero further ALTER.
 *
 * Every vocabulary column (Slot) is VARCHAR, never ENUM (rule #20) — a
 * growable vocabulary needs an app-level allow-list add, not an ALTER.
 * SanitiserVersion is a plain INT (not a vocabulary) recording which build of
 * includes/html_sanitizer.php produced HtmlSanitised, so a future rule
 * *tightening* (version bump) can flag existing rows for re-sanitise.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table/column existence guarded via
 * INFORMATION_SCHEMA, bound params — rule #5).
 *
 * NOT DESTRUCTIVE. Nothing here drops, renames, truncates or rewrites an
 * existing object — it only CREATEs one new table and ADDs one new nullable
 * column (+ index + FK) to an existing table.
 *
 * SCHEMA MIRROR: both the CREATE TABLE and the ALTER TABLE are mirrored
 * BYTE-IDENTICALLY (including every COMMENT '...' string) in
 * appWeb/.sql/schema.sql, same commit — rule #19;
 * tests/php/test-schema-coverage.php enforces the migrations-are-a-subset-
 * of-schema.sql direction.
 *
 * @migration-adds tblPrintTemplateCustomLayout
 * @migration-adds tblPrintTemplates.OrgId
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-print-template-layouts.php
 *   Web:  /manage/setup-database → "Print template custom layouts (#1767)" button
 *
 * @see .claude/print-templates-1767-remainder-plan.md §7, §9  the full design this migration implements
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* Single output helper — legible in the terminal and the dashboard <pre> capture. */
function _migPTL_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/* Idempotency probes — INFORMATION_SCHEMA with bound params (rule #5). */
function _migPTL_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}
function _migPTL_columnExists(\mysqli $db, string $t, string $c): bool
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
if (!file_exists($credFile)) { _migPTL_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPTL_output("");
_migPTL_output("=== iHymns — Print template custom layouts + org column (#1767 remainder P1) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migPTL_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migPTL_output("Connected to MySQL: " . DB_NAME);

try {
    /* ---- 1. tblPrintTemplateCustomLayout — DDL byte-identical to schema.sql (rule #19) ---- */
    if (!_migPTL_tableExists($mysql, 'tblPrintTemplates')) {
        _migPTL_output("  [WARN] tblPrintTemplates not present — run the 'print-templates' (#1350) card first, then re-run this card.");
    } elseif (_migPTL_tableExists($mysql, 'tblPrintTemplateCustomLayout')) {
        _migPTL_output("  [SKIP] tblPrintTemplateCustomLayout already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblPrintTemplateCustomLayout (
                Id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                TemplateId       INT UNSIGNED    NOT NULL COMMENT 'FK tblPrintTemplates.Id — the template this full-page layout skins',
                Slot             VARCHAR(20)     NOT NULL DEFAULT 'page' COMMENT 'page | cover | continuation | … (VARCHAR not ENUM, rule #20; reserved multiplicity — v1 writes only ''page'')',
                HtmlSanitised    MEDIUMTEXT      NOT NULL COMMENT 'The ONLY render-served payload — output of ihymnsSanitizeHtml(layout). Render paths must never read HtmlOriginal.',
                HtmlOriginal     MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'Upload as received (dormant) — sole source for re-sanitising after an allow-list change; guard-banned from render paths',
                SanitiserVersion INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'IHYMNS_HTML_SANITISER_VERSION that produced HtmlSanitised; a bump flags rows for re-sanitise',
                SizeBytes        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'LENGTH(HtmlSanitised) at save — cheap cap/audit read without pulling the blob',
                IsActive         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = template falls back to the standard document shell without deleting the upload',
                CreatedBy        INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who uploaded it',
                CreatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_TemplateSlot (TemplateId, Slot),
                INDEX idx_CreatedBy (CreatedBy),

                CONSTRAINT fk_PtLayout_Template
                    FOREIGN KEY (TemplateId) REFERENCES tblPrintTemplates(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_PtLayout_User
                    FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migPTL_output("  [OK] Created tblPrintTemplateCustomLayout.");
    }

    /* ---- 2. tblPrintTemplates.OrgId — RESERVED DORMANT (feature K, #1767) ---- */
    if (!_migPTL_tableExists($mysql, 'tblPrintTemplates')) {
        _migPTL_output("  [WARN] tblPrintTemplates not present — run the 'print-templates' (#1350) card first, then re-run this card.");
    } elseif (_migPTL_columnExists($mysql, 'tblPrintTemplates', 'OrgId')) {
        _migPTL_output("  [SKIP] tblPrintTemplates.OrgId already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblPrintTemplates
                ADD COLUMN OrgId INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'FK tblOrganisations.Id — NULL = global/curated; set = visible only to that org''s members (K, #1767; UI may lag the column)'
                    AFTER OwnerId,
                ADD INDEX idx_Org (OrgId),
                ADD CONSTRAINT fk_PrintTemplate_Org FOREIGN KEY (OrgId)
                    REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE"
        );
        _migPTL_output("  [OK] Added tblPrintTemplates.OrgId (+ index + FK).");
    }

    _migPTL_output("");
    _migPTL_output("--- Summary ---");
    _migPTL_output("  Schema-only pass — nothing in the live tree reads either object yet.");
    _migPTL_output("  The sanitiser (includes/html_sanitizer.php) and the upload UI land in");
    _migPTL_output("  later commits of the #1767 remainder.");
    _migPTL_output("");
    _migPTL_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migPTL_output("ERROR: " . $e->getMessage());
}
