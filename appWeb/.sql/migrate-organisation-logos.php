<?php

declare(strict_types=1);

/**
 * iHymns — Organisation logos (#1830)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A church wants its own logo on printed song sheets. This script creates
 * ONE new table, `tblOrganisationLogos`, with an empty row waiting for each
 * shape of logo (main logo, wide layout, symbol-only, single-colour, …) an
 * organisation might upload. Nothing reads or writes it until the rest of
 * #1830 lands in later commits — this migration is schema-only.
 *
 * DETAILED
 * --------
 * One row per `(OrgId, Kind, Variant)` — a re-upload replaces the row via
 * `ON DUPLICATE KEY UPDATE` in `includes/org_logo_admin.php`, never a second
 * insert. `Kind` is a VARCHAR app-validated against the ONE central map
 * `IHYMNS_ORG_LOGO_KINDS` (`includes/org_logo_helpers.php`) — never an ENUM
 * (rule #20): a new kind is one line in that map, no ALTER. `Variant`
 * reserves the light/dark theme-pair axis (v1 only ever writes 'default').
 * `ContentSanitised` is the ONLY column any serving path may read; SVG rows
 * ALSO carry the untouched upload in `ContentOriginal` (dormant — never
 * served, sole source for re-sanitising after a future sanitiser-allowlist
 * tightening bump of `IHYMNS_SVG_SANITISER_VERSION`), mirroring the
 * `tblPrintTemplateCustomLayout.HtmlOriginal` shape (#1767 remainder P1).
 * `StorageBackend` + dormant `StoragePath` reserve a future non-database
 * backend (the `tblSongMedia` dual-column precedent, minus its grandfathered
 * ENUM — rule #20 requires VARCHAR here). `MetaJson` is a growable bag for
 * a later surface's hints (focal point, background, padding) — rule #28's
 * "growable vocabulary is JSON, never a new column" discipline.
 *
 * Every column/index/FK below is BYTE-IDENTICAL (incl. every COMMENT) to its
 * mirror in `appWeb/.sql/schema.sql`, same commit (rule #19).
 *
 * IDEMPOTENT — `CREATE TABLE IF NOT EXISTS`, safe to re-run. No data
 * backfill: there is no pre-existing logo data anywhere to migrate.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-organisation-logos.php
 *   Web:  /manage/setup-database → "Organisation Logos (#1830)"
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/org-logos-1830-plan.md §2  the full design this migration implements
 * @see appWeb/public_html/includes/org_logo_helpers.php  the kind/variant vocabulary this schema serves
 * @see appWeb/public_html/manage/includes/migration-registry.php  'organisation-logos' entry
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migOrgLogo_out(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) {
        flush();
    }
}

/* Rule #41 — the deployed docroot is RENAMED per channel (public_html_dev on
   alpha, public_html_beta on beta; only main keeps the literal public_html
   name). The setup-database runner defines IHYMNS_INCLUDES_DIR to its own
   real <docroot>/includes; a standalone/CLI or test run (repo layout, where
   public_html is real) falls back to the literal path — this fallback is an
   ASSIGNMENT, never an unconditional require of the literal itself, so
   tests/php/test-deploy-paths.php's column-0-require scan does not flag it. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/db_mysql.php';

_migOrgLogo_out('');
_migOrgLogo_out('=== iHymns — Organisation logos (#1830) ===');
_migOrgLogo_out('');

try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    _migOrgLogo_out('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migOrgLogo_out('Connected to MySQL.');

try {
    /* SCHEMA MIRROR: byte-identical (incl. every COMMENT) to the
       tblOrganisationLogos block in appWeb/.sql/schema.sql — rule #19. */
    $db->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS tblOrganisationLogos (
    Id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    OrgId            INT UNSIGNED    NOT NULL COMMENT 'FK tblOrganisations.Id — whose branding this is',
    Kind             VARCHAR(20)     NOT NULL COMMENT 'Brand-asset kind vocabulary token -- registry: IHYMNS_ORG_LOGO_KINDS (includes/org_logo_helpers.php, #1830). primary | secondary | emblem | logotype | full | horizontal | stacked | monochrome | reversed | favicon. VARCHAR not ENUM (rule #20) — a new kind is one map line, no ALTER',
    Variant          VARCHAR(10)     NOT NULL DEFAULT 'default' COMMENT 'default | light | dark (reserved multiplicity, rule #20 — v1 only ever writes ''default'')',
    Mime             VARCHAR(127)    NOT NULL COMMENT 'image/svg+xml | image/png — sniffed from the bytes at upload, never taken from the client',
    Width            SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Intrinsic pixel width (PNG) or viewBox-derived width (SVG); NULL when underivable',
    Height           SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Intrinsic pixel height — see Width',
    ByteSize         INT UNSIGNED    NOT NULL COMMENT 'LENGTH(ContentSanitised) at save — cheap cap/audit read without pulling the blob',
    Sha256           CHAR(64)        NOT NULL COMMENT 'sha256 of ContentSanitised — the ETag and the &v= cache-bust token',
    StorageBackend   VARCHAR(20)     NOT NULL DEFAULT 'database' COMMENT 'database | filesystem | object-store (VARCHAR not ENUM, rule #20 — v1 only ever writes ''database''; the row wins on read, SongMediaStorage precedent)',
    ContentSanitised MEDIUMBLOB      NULL COMMENT 'The ONLY serve-readable bytes: svg_sanitizer.php output for SVG, validated original bytes for PNG/APNG',
    ContentOriginal  MEDIUMBLOB      NULL COMMENT 'SVG upload as received (dormant) — sole source for re-sanitising after an allow-list change; guard-banned from serving paths; NULL for raster rows',
    StoragePath      VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Dormant (rule #20) — relative path for a future non-database StorageBackend',
    SanitiserVersion INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'IHYMNS_SVG_SANITISER_VERSION that produced ContentSanitised (0 = raster, not applicable); a bump flags SVG rows for re-sanitise',
    AltText          VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Accessible label for the rendered <img>; NULL = "<Org name> logo"',
    MetaJson         JSON            NULL DEFAULT NULL COMMENT 'Dormant grab-bag for future surface hints (focal point, background, padding) — growable vocabulary is JSON, never new columns (rule #20/#28)',
    IsActive         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = hidden from every surface without deleting the upload',
    UploadedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who uploaded it',
    CreatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_OrgKindVariant (OrgId, Kind, Variant),
    INDEX idx_UploadedBy (UploadedBy),

    CONSTRAINT fk_OrgLogo_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_OrgLogo_User
        FOREIGN KEY (UploadedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    _migOrgLogo_out('  [OK] Created (or already present) tblOrganisationLogos.');
    _migOrgLogo_out('');
    _migOrgLogo_out('Migration complete. /manage/organisations and /manage/my-organisations can');
    _migOrgLogo_out('now show a Logos card once the admin-UI commit lands; org-logo.php serves');
    _migOrgLogo_out('404 until then — nothing else in the live tree reads or writes this table yet.');
} catch (\Throwable $e) {
    _migOrgLogo_out('  [ERROR] ' . $e->getMessage());
}

return;
