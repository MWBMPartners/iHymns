<?php

declare(strict_types=1);

/**
 * iHymns — DM-1 recovery: revert the org-licence consolidation (#1769 P5)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Undo migrate-consolidate-org-licences.php:
 *   A. clear the join-table ExpiresAt back to NULL where it exactly matches the
 *      legacy tblOrganisations.LicenceExpiresAt it was carried from (a curator's
 *      own differing expiry is left alone);
 *   B. delete the join-table rows that exactly match a hand-written ghost
 *      tblContentLicences row (OrgId + LicenceType + LicenceKey). On a normal
 *      install tblContentLicences is empty, so this clears nothing.
 * A re-run of the forward pass re-creates everything, so this is safe.
 *
 * CLI-ONLY (a deliberate recovery action, never a dashboard card).
 * USAGE:  php appWeb/.sql/revert-consolidate-org-licences.php
 *
 * @requires PHP 8.1+ with mysqli
 * @link .claude/gating-model-review-1769-plan.md  DM-1
 * @see appWeb/.sql/migrate-consolidate-org-licences.php  the forward pass
 * @see #1769
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "This recovery tool runs from the CLI only.\n";
    return;
}
@set_time_limit(0);

function _revConsolidateOrgLic_out(string $m): void { echo $m . "\n"; }
function _revConsolidateOrgLic_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _revConsolidateOrgLic_colExists(\mysqli $db, string $t, string $c): bool {
    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _revConsolidateOrgLic_out("ERROR: MySQL credentials not found."); return; }
require_once $credFile;

_revConsolidateOrgLic_out("");
_revConsolidateOrgLic_out("=== iHymns — #1769 P5 DM-1 RECOVERY: revert org-licence consolidation ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _revConsolidateOrgLic_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_revConsolidateOrgLic_out("Connected to MySQL: " . DB_NAME);

try {
    if (!_revConsolidateOrgLic_tableExists($mysql, 'tblOrganisationLicences')) {
        _revConsolidateOrgLic_out("  [SKIP] tblOrganisationLicences absent — nothing to revert.");
        $mysql->close();
        return;
    }

    /* A — clear the carried expiry (only where it still equals the legacy value). */
    if (_revConsolidateOrgLic_colExists($mysql, 'tblOrganisations', 'LicenceExpiresAt')) {
        $a = $mysql->query(
            "UPDATE tblOrganisationLicences ol
               JOIN tblOrganisations o
                 ON o.Id = ol.OrganisationId AND ol.LicenceType = o.LicenceType
                SET ol.ExpiresAt = NULL
              WHERE ol.ExpiresAt IS NOT NULL
                AND o.LicenceExpiresAt IS NOT NULL
                AND ol.ExpiresAt <=> o.LicenceExpiresAt"
        );
        _revConsolidateOrgLic_out("  [OK] Cleared " . $mysql->affected_rows . " carried expiry value(s).");
    }

    /* B — delete join rows that exactly match a ghost tblContentLicences row. */
    if (_revConsolidateOrgLic_tableExists($mysql, 'tblContentLicences')) {
        $b = $mysql->query(
            "DELETE ol FROM tblOrganisationLicences ol
               JOIN tblContentLicences cl
                 ON cl.OrgId = ol.OrganisationId
                AND cl.LicenceType = ol.LicenceType
                AND cl.LicenceKey = ol.LicenceNumber
              WHERE cl.OrgId IS NOT NULL AND cl.LicenceType NOT IN ('none', '')"
        );
        _revConsolidateOrgLic_out("  [OK] Deleted " . $mysql->affected_rows . " ghost-derived join row(s).");
    }
} catch (\Throwable $e) {
    _revConsolidateOrgLic_out("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
