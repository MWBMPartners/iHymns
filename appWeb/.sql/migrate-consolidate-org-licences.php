<?php

declare(strict_types=1);

/**
 * iHymns — DM-1: complete the org-licence consolidation into tblOrganisationLicences (#1769 P5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The #1769 plan calls for ONE authoritative org-licence store
 * (`tblOrganisationLicences`) before the legacy `tblOrganisations` licence
 * columns can ever be retired. The bulk of that consolidation is ALREADY DONE:
 * the shipped `migrate-organisation-licences.php` backfilled the join table from
 * `tblOrganisations.LicenceType`/`LicenceNumber` (verified — see that file:172).
 * This pass closes the two residual gaps that backfill left, so the join table
 * is genuinely a superset before any retire:
 *   A. it did NOT carry `tblOrganisations.LicenceExpiresAt` — so a join row can
 *      have a NULL expiry while the legacy column holds one. Fill-NULL-only.
 *   B. it did not consider hand-written ghost rows in `tblContentLicences`
 *      (which has NO writer in the codebase — permanently empty on a normal
 *      install, so this leg is a zero-row no-op in practice, but a human could
 *      have SQL-inserted an org-scoped row directly).
 *
 * WHY IT'S A VERIFIED NO-OP FOR RESOLUTION
 * ----------------------------------------------------------------------------
 * `resolveEffectiveTier()` (ccli_validator.php) unions the legacy column AND the
 * join table, matching on `LicenceType` only — it never reads `ExpiresAt`. So
 * Leg A changes NOTHING it resolves today. Leg A carries data the eventual
 * (deferred, gated) legacy-column retire will need.
 *
 * ⚠ Leg B is a no-op ONLY because `tblContentLicences` is empty in practice. An
 * org-scoped ghost that DID exist is already honoured by `licences.php`
 * (branches c/d), but NOT by `resolveEffectiveTier()` (which does not read
 * `tblContentLicences`). Migrating it into the join table WOULD make the tier
 * resolver see it — and because licence→tier conferral runs regardless of the
 * master switch (#1769 P2-F), that could change a member's effective tier. This
 * pass therefore REPORTS the ghost count and never migrates silently; on every
 * real install the count is 0.
 *
 * DATA-ONLY (no schema.sql edit — D6). GATED: no-ops cleanly when
 * `tblOrganisationLicences` is absent (run the "Organisation licences (#640)"
 * card first). Idempotent + re-runnable — the probe re-checks the same thing so
 * it doubles as a drift detector. Recovery: revert-consolidate-org-licences.php.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-consolidate-org-licences.php
 *   Web:  /manage/setup-database -> "Consolidate org licences (#1769 P5)"
 *   Prerequisite: the "Organisation licences (#640)" card (creates + first-backfills the join table).
 *
 * @requires PHP 8.1+ with mysqli
 * @link .claude/gating-model-review-1769-plan.md  DM-1
 * @see appWeb/.sql/migrate-organisation-licences.php  the shipped Leg-1 backfill this completes
 * @see appWeb/public_html/includes/ccli_validator.php  resolveEffectiveTier() — unions on LicenceType only
 * @see appWeb/.sql/revert-consolidate-org-licences.php  the recovery tool
 * @see #1769
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
if ($isCli) { @set_time_limit(0); }

function _migConsolidateOrgLic_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) { flush(); } }
function _migConsolidateOrgLic_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migConsolidateOrgLic_colExists(\mysqli $db, string $t, string $c): bool {
    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migConsolidateOrgLic_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migConsolidateOrgLic_out("");
_migConsolidateOrgLic_out("=== iHymns — #1769 P5 DM-1: complete the org-licence consolidation into tblOrganisationLicences ===");
_migConsolidateOrgLic_out("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migConsolidateOrgLic_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migConsolidateOrgLic_out("Connected to MySQL: " . DB_NAME);

try {
    if (!_migConsolidateOrgLic_tableExists($mysql, 'tblOrganisationLicences')) {
        _migConsolidateOrgLic_out("  [SKIP] tblOrganisationLicences does not exist yet.");
        _migConsolidateOrgLic_out("         Run the \"Organisation licences (#640)\" card first, then re-run this one.");
        $mysql->close();
        return;
    }

    /* ---- Leg A: carry the legacy LicenceExpiresAt into the join table's
       matching primary-licence row (fill-NULL-only). ------------------------ */
    if (_migConsolidateOrgLic_colExists($mysql, 'tblOrganisations', 'LicenceExpiresAt')) {
        $a = $mysql->prepare(
            "UPDATE tblOrganisationLicences ol
               JOIN tblOrganisations o
                 ON o.Id = ol.OrganisationId AND ol.LicenceType = o.LicenceType
                SET ol.ExpiresAt = o.LicenceExpiresAt
              WHERE ol.ExpiresAt IS NULL
                AND o.LicenceExpiresAt IS NOT NULL
                AND o.LicenceType NOT IN ('none', '')"
        );
        $a->execute();
        _migConsolidateOrgLic_out("  [OK] Carried legacy licence expiry onto " . $a->affected_rows . " join-table row(s).");
        $a->close();
    } else {
        _migConsolidateOrgLic_out("  [INFO] tblOrganisations.LicenceExpiresAt absent — nothing to carry.");
    }

    /* ---- Leg B: consolidate hand-written ghost org-scoped tblContentLicences
       rows into the join table (row-count-probed; empty in practice). ------- */
    $ghostCount = 0;
    if (_migConsolidateOrgLic_tableExists($mysql, 'tblContentLicences')) {
        $r = $mysql->query(
            "SELECT COUNT(*) FROM tblContentLicences
              WHERE OrgId IS NOT NULL AND LicenceType NOT IN ('none', '')"
        );
        $ghostCount = (int)($r->fetch_row()[0] ?? 0);
        if ($r) { $r->close(); }

        if ($ghostCount > 0) {
            _migConsolidateOrgLic_out("  [WARN] {$ghostCount} hand-written org-scoped tblContentLicences row(s) found.");
            _migConsolidateOrgLic_out("         Consolidating them into tblOrganisationLicences (INSERT IGNORE). NOTE: an org");
            _migConsolidateOrgLic_out("         licence that CONFERS a tier will then be seen by resolveEffectiveTier() too —");
            _migConsolidateOrgLic_out("         a member's effective tier can change (licence conferral runs even with gating off).");
            $b = $mysql->prepare(
                "INSERT IGNORE INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber, ExpiresAt, IsActive)
                 SELECT cl.OrgId, cl.LicenceType, cl.LicenceKey, cl.ExpiresAt, cl.IsActive
                   FROM tblContentLicences cl
                  WHERE cl.OrgId IS NOT NULL AND cl.LicenceType NOT IN ('none', '')"
            );
            $b->execute();
            _migConsolidateOrgLic_out("  [OK] Consolidated " . $b->affected_rows . " ghost row(s) into the join table.");
            $b->close();
        } else {
            _migConsolidateOrgLic_out("  [OK] No hand-written org-scoped tblContentLicences rows (the expected state).");
        }
    } else {
        _migConsolidateOrgLic_out("  [INFO] tblContentLicences absent — no ghost rows to consolidate.");
    }

    if (function_exists('logActivity')) {
        logActivity('admin.migration.consolidate-org-licences', 'migration', 'consolidate-org-licences', [
            'ghost_rows_found' => $ghostCount,
        ]);
    }

    _migConsolidateOrgLic_out("");
    _migConsolidateOrgLic_out("Consolidation complete. tblOrganisationLicences now holds the legacy expiry + any ghost rows.");
    _migConsolidateOrgLic_out("Idempotent — safe to re-run. The legacy-column RETIRE stays a separate, gated P6 step.");
    _migConsolidateOrgLic_out("Recovery: revert-consolidate-org-licences.php.");
} catch (\Throwable $e) {
    _migConsolidateOrgLic_out("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
