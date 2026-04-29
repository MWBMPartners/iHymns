<?php

declare(strict_types=1);

/**
 * iHymns — Multiple licence types per organisation (#640)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the `tblOrganisationLicences` join table so each organisation
 * can hold any number of licences (CCLI, MRL, OneLicense, …) instead
 * of the single-value `tblOrganisations.LicenceType` column. Real
 * churches commonly hold both CCLI (lyrics reproduction) AND MRL
 * (musical-notation reproduction); the single-value model forced
 * an artificial choice.
 *
 * Schema:
 *   tblOrganisationLicences
 *     Id              INT AUTO_INCREMENT PK
 *     OrganisationId  INT FK → tblOrganisations.Id (CASCADE)
 *     LicenceType     VARCHAR(30)
 *     LicenceNumber   VARCHAR(255) NULL
 *     IsActive        TINYINT(1)   DEFAULT 1
 *     ExpiresAt       DATE NULL
 *     Notes           TEXT NULL
 *     CreatedAt       DATETIME DEFAULT CURRENT_TIMESTAMP
 *     UpdatedAt       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 *   UNIQUE KEY uk_OrgLicence (OrganisationId, LicenceType)
 *   INDEX idx_OrganisationId (OrganisationId)
 *
 * @migration-adds tblOrganisationLicences.Id
 * @migration-adds tblOrganisationLicences.OrganisationId
 * @migration-adds tblOrganisationLicences.LicenceType
 *
 * Backfill: every existing `tblOrganisations` row with a non-empty
 * non-'none' LicenceType becomes a row in the new table. The primary
 * column is left in place — back-compat for any tooling that reads
 * it directly. Future migrations can drop the column once all
 * consumers are switched over.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-organisation-licences.php
 *
 * Idempotent.
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        /* Guarded: dashboard mode pre-loads auth.php transitively. The

           guard also avoids re-opening the file from outside public_html/,

           which some hosts (open_basedir / php-fpm chroot) refuse even

           though the file is otherwise reachable (#652). */

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
    /* Guarded require — see #652. The dashboard has already loaded

       db_mysql.php via auth.php's bootstrap, so the function already

       exists at this point in dashboard mode; the guard skips the

       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = false;
}

function _migOrgLic_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migOrgLic_tableExists(mysqli $db, string $table): bool
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

_migOrgLic_out('Multi-licence migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) { _migOrgLic_out('ERROR: could not connect.'); exit(1); }

if (_migOrgLic_tableExists($mysqli, 'tblOrganisationLicences')) {
    _migOrgLic_out('[skip] tblOrganisationLicences already present.');
} else {
    $sql = "CREATE TABLE tblOrganisationLicences (
        Id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        OrganisationId  INT UNSIGNED    NOT NULL,
        LicenceType     VARCHAR(30)     NOT NULL,
        LicenceNumber   VARCHAR(255)    NULL,
        IsActive        TINYINT(1)      NOT NULL DEFAULT 1,
        ExpiresAt       DATE            NULL,
        Notes           TEXT            NULL,
        CreatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_OrgLicence (OrganisationId, LicenceType),
        INDEX idx_OrganisationId (OrganisationId),
        INDEX idx_LicenceType    (LicenceType),
        CONSTRAINT fk_OrgLicences_Org
            FOREIGN KEY (OrganisationId) REFERENCES tblOrganisations(Id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($sql)) {
        _migOrgLic_out('ERROR: creating tblOrganisationLicences failed: ' . $mysqli->error);
        exit(1);
    }
    _migOrgLic_out('[add ] tblOrganisationLicences.');
}

/* Backfill: one row per org with a meaningful primary LicenceType. */
$res = $mysqli->query("SELECT Id, LicenceType, LicenceNumber FROM tblOrganisations
                        WHERE LicenceType IS NOT NULL AND LicenceType <> '' AND LicenceType <> 'none'");
$candidates = [];
while ($row = $res->fetch_assoc()) {
    $candidates[] = $row;
}
$res->close();

$ins = $mysqli->prepare(
    'INSERT IGNORE INTO tblOrganisationLicences (OrganisationId, LicenceType, LicenceNumber)
     VALUES (?, ?, ?)'
);
$filled = 0;
foreach ($candidates as $c) {
    $orgId = (int)$c['Id'];
    $type  = (string)$c['LicenceType'];
    $num   = $c['LicenceNumber'] !== null ? (string)$c['LicenceNumber'] : null;
    $ins->bind_param('iss', $orgId, $type, $num);
    if ($ins->execute() && $ins->affected_rows > 0) { $filled++; }
}
$ins->close();
if ($filled > 0) {
    _migOrgLic_out("[seed] backfilled {$filled} primary licence row" . ($filled === 1 ? '' : 's') . ' into the join table.');
} else {
    _migOrgLic_out('[seed] no rows needed backfill (all already present or no primary licences set).');
}

_migOrgLic_out('Multi-licence migration finished.');
$mysqli->close();
