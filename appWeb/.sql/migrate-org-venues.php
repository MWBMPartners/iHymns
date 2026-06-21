<?php

declare(strict_types=1);

/**
 * iHymns — Org Venues + Recurring Service Schedules (Service Mode Phase 1, #1325)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Give an organisation first-class PHYSICAL VENUES and RECURRING SERVICE TIMES —
 * the foundation the "Service Mode" feature family (#1323: congregation-wide
 * Live Follow) sits on, and useful org metadata in its own right. Today orgs
 * carry only a city centroid (tblPlaces) and a per-setlist DATE-only schedule
 * (tblSetlistSchedule) — there is no per-org geographic boundary and no
 * recurring service-time concept. This adds:
 *   - tblOrgVenues           — a venue (name, address, lat/lng + radius, tz).
 *   - tblOrgServiceSchedules — recurring service windows per venue.
 *
 * FORWARD-LOOKING (CLAUDE.md rule #20): the schema is designed for the WHOLE
 * Service-Mode family up front so later phases never re-migrate. lat/lng +
 * RadiusMetres are a CONVENIENCE geofence + map pin — NOT the presence gate
 * (Service Mode Phase 2 gates on a venue-displayed ROTATING CODE, because
 * geolocation is spoofable/inaccurate indoors — see
 * .claude/live-congregant-strategy.md). RecurrenceKind is VARCHAR (app-validated)
 * not ENUM, so adding a cadence is app-level, never an ALTER. The
 * service-OCCURRENCE (scheduleId, date) is computed at read time; Phase-2
 * sessions + Phase-3 CCLI unlock + tblSongUsageEvents.ScheduleId bind to it.
 *
 * Additive + DORMANT + IDEMPOTENT (table-existence guarded). Tables ship empty.
 *
 * @migration-adds tblOrgVenues
 * @migration-adds tblOrgServiceSchedules
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-org-venues.php
 *   Web:  /manage/setup-database → "Org Venues & Service Schedules" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migOV_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migOV_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migOV_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migOV_output("");
_migOV_output("=== iHymns — Org Venues & Service Schedules (#1325) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migOV_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migOV_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migOV_tableExists($mysql, 'tblOrgVenues')) {
        _migOV_output("  [SKIP] tblOrgVenues already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblOrgVenues (
                Id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
                OrgId        INT UNSIGNED      NOT NULL COMMENT 'FK to tblOrganisations.Id — the org this venue belongs to',
                Name         VARCHAR(150)      NOT NULL COMMENT 'Venue display name (e.g. Main Sanctuary, Church Hall)',
                AddressLine  VARCHAR(255)      NULL DEFAULT NULL COMMENT 'Street address (free text)',
                City         VARCHAR(120)      NULL DEFAULT NULL,
                Postcode     VARCHAR(20)       NULL DEFAULT NULL,
                CountryCode  CHAR(2)           NULL DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
                PlaceId      INT UNSIGNED      NULL DEFAULT NULL COMMENT 'FK to tblPlaces.Id — geocoder-resolved place (optional)',
                Latitude     DECIMAL(10,7)     NULL DEFAULT NULL COMMENT 'Venue centroid latitude — convenience geofence + map pin, NOT the presence gate',
                Longitude    DECIMAL(10,7)     NULL DEFAULT NULL COMMENT 'Venue centroid longitude',
                RadiusMetres SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Convenience geofence radius; presence gate is the venue rotating code (Phase 2)',
                TimeZone     VARCHAR(40)       NOT NULL DEFAULT 'UTC' COMMENT 'IANA tz the schedules are interpreted in (e.g. Europe/London)',
                IsActive     TINYINT(1)        NOT NULL DEFAULT 1,
                SortOrder    INT UNSIGNED      NOT NULL DEFAULT 0,
                CreatedAt    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_Org   (OrgId, IsActive),
                INDEX idx_Place (PlaceId),
                CONSTRAINT fk_OrgVenues_Org   FOREIGN KEY (OrgId)   REFERENCES tblOrganisations(Id) ON DELETE CASCADE  ON UPDATE CASCADE,
                CONSTRAINT fk_OrgVenues_Place FOREIGN KEY (PlaceId) REFERENCES tblPlaces(Id)        ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Org physical venues — Service Mode Phase 1 (#1325). lat/lng/radius = convenience geofence + map pin; presence gate is the venue rotating code.'"
        );
        _migOV_output("  [OK] Created tblOrgVenues.");
    }

    if (_migOV_tableExists($mysql, 'tblOrgServiceSchedules')) {
        _migOV_output("  [SKIP] tblOrgServiceSchedules already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblOrgServiceSchedules (
                Id             INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
                VenueId        INT UNSIGNED      NOT NULL COMMENT 'FK to tblOrgVenues.Id',
                OrgId          INT UNSIGNED      NOT NULL COMMENT 'Denorm of the venue org (app-derived) for cheap org-scoped queries',
                Title          VARCHAR(150)      NOT NULL DEFAULT 'Service' COMMENT 'e.g. Sunday Morning, Evening Service',
                DayOfWeek      TINYINT UNSIGNED  NULL DEFAULT NULL COMMENT 'ISO-8601 1=Mon..7=Sun for recurring kinds; NULL for one_off (date in RecurrenceData)',
                StartTime      TIME              NOT NULL COMMENT 'Local service start in the effective TimeZone',
                DurationMins   SMALLINT UNSIGNED NOT NULL DEFAULT 90 COMMENT 'Service window length (drives the active-now check)',
                RecurrenceKind VARCHAR(20)       NOT NULL DEFAULT 'weekly' COMMENT 'weekly|fortnightly|monthly_nth|one_off|custom — app-validated, VARCHAR not ENUM (rule #20)',
                RecurrenceData JSON              NULL DEFAULT NULL COMMENT 'Kind-specific: {interval},{nth,dayOfWeek},{date} for one_off,{until},{exceptions:[dates]}',
                TimeZone       VARCHAR(40)       NULL DEFAULT NULL COMMENT 'Override the venue tz for this schedule; NULL = inherit the venue tz',
                IsActive       TINYINT(1)        NOT NULL DEFAULT 1,
                SortOrder      INT UNSIGNED      NOT NULL DEFAULT 0,
                CreatedAt      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_Venue (VenueId, IsActive),
                INDEX idx_Org   (OrgId, IsActive),
                INDEX idx_Day   (DayOfWeek, StartTime),
                CONSTRAINT fk_OrgSchedules_Venue FOREIGN KEY (VenueId) REFERENCES tblOrgVenues(Id)     ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_OrgSchedules_Org   FOREIGN KEY (OrgId)   REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Recurring service times per venue — Service Mode Phase 1 (#1325). The service-occurrence (scheduleId,date) is computed at read time; Phase-2 sessions + Phase-3 unlock bind to it.'"
        );
        _migOV_output("  [OK] Created tblOrgServiceSchedules.");
    }

    _migOV_output("  Tables ship empty; the admin UI (/manage/venues) populates them. lat/lng/radius are a convenience geofence — the Service-Mode presence gate is a venue rotating code (#1323).");
    _migOV_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migOV_output("ERROR: " . $e->getMessage());
}
