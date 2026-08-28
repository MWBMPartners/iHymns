<?php

declare(strict_types=1);

/**
 * iHymns — Venue + Service-Schedule shared READ core (#1969, API-coverage
 * batch 1, C2)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/venues` (the web admin page) and the new `?action=org_venues`
 * API endpoint both need to answer the SAME question — "what venues does
 * this organisation have, and what recurring service times does each
 * venue keep?" This file is the ONE place that read lives; both callers
 * ask it instead of each running its own copy of the same two queries.
 *
 * DETAILED / WHY A SHARED CORE FILE, NOT TWO COPIES (rule #22)
 * ----------------------------------------------------------------------------
 * `manage/venues.php` used to run this SELECT pair inline as part of its
 * "load for render" step (org list → selected org's venues → selected
 * venue's schedules). The plan for the new native-consumer `org_venues`
 * action (`.claude/api-coverage-2026-08-28.md` §4.1 C2) calls for exactly
 * the same two reads, scoped to every org the CALLER administers rather
 * than one `?org=` query param — extracting the READ half here (write
 * actions — `venue_save`/`venue_delete`/`schedule_save`/`schedule_delete`
 * — stay on `manage/venues.php` for now; O4 in the coverage plan tracks
 * their own extraction, pending the owner's Q4 gating decision) means the
 * two callers can never drift on column list, ordering, or the
 * RecurrenceData decode / effective-timezone resolution.
 *
 * Every function here is pure-PHP-plus-`\mysqli` (no `$_GET`/`$_POST`
 * reads) so a form-rendering page and a JSON API action can both call in
 * with their own already-resolved org/venue ids.
 *
 * Direct access is blocked (same guard as `tune_admin.php` /
 * `publisher_helpers.php`) so this file can't be requested as an endpoint
 * via an open Apache config.
 *
 * @link appWeb/public_html/manage/venues.php   page consumer (read half re-pointed here)
 * @link appWeb/public_html/api.php              org_venues API consumer
 * @link appWeb/.sql/migrate-org-venues.php       tblOrgVenues / tblOrgServiceSchedules
 * @see #1325
 * @see #1969
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * True when BOTH `tblOrgVenues` and `tblOrgServiceSchedules` exist on this
 * install. Migrations are web-run, not auto-applied (rule #19) — a caller
 * must gate on this before reading, exactly as `manage/venues.php`'s
 * pre-extraction `$schemaReady` did.
 */
function venueAdminTablesExist(\mysqli $db): bool
{
    $probe = static function (\mysqli $db, string $t): bool {
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
            );
            $stmt->bind_param('s', $t);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $exists;
        } catch (\Throwable $e) {
            error_log('[venueAdminTablesExist] probe failed: ' . $e->getMessage());
            return false;
        }
    };
    return $probe($db, 'tblOrgVenues') && $probe($db, 'tblOrgServiceSchedules');
}

/**
 * One organisation's venues, in the SAME shape + order
 * `manage/venues.php` has always loaded them in.
 *
 * @return array<int, array{Id:int,OrgId:int,Name:string,AddressLine:?string,
 *   City:?string,Postcode:?string,CountryCode:?string,Latitude:?float,
 *   Longitude:?float,RadiusMetres:?int,TimeZone:string,IsActive:int}>
 */
function venueAdminListForOrg(\mysqli $db, int $orgId): array
{
    if ($orgId <= 0) { return []; }
    $stmt = $db->prepare(
        'SELECT Id, OrgId, Name, AddressLine, City, Postcode, CountryCode,
                Latitude, Longitude, RadiusMetres, TimeZone, IsActive
           FROM tblOrgVenues WHERE OrgId = ? ORDER BY SortOrder ASC, Name ASC'
    );
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * One venue's recurring service schedules, decoded + effective-timezone
 * resolved exactly as `manage/venues.php`'s post-fetch loop always has:
 * `_rd` is the decoded `RecurrenceData` JSON (`[]` on empty/invalid), and
 * `_EffTz` falls back to the venue's own `TimeZone` when the schedule row
 * doesn't carry its own override — the SAME `TimeZone ?? $fallbackTz`
 * precedence `venueScheduleSummary()`/`venueNextOccurrences()` expect.
 *
 * @param string $fallbackTz The owning venue's `TimeZone` (used only when
 *                            a schedule row's own `TimeZone` is empty).
 * @return array<int, array> Each row carries the raw columns plus `_rd`
 *   (array) and `_EffTz` (string).
 */
function venueAdminSchedulesForVenue(\mysqli $db, int $venueId, string $fallbackTz): array
{
    if ($venueId <= 0) { return []; }
    $stmt = $db->prepare(
        'SELECT Id, VenueId, Title, DayOfWeek, StartTime, DurationMins,
                RecurrenceKind, RecurrenceData, TimeZone, IsActive
           FROM tblOrgServiceSchedules WHERE VenueId = ?
          ORDER BY DayOfWeek ASC, StartTime ASC'
    );
    $stmt->bind_param('i', $venueId);
    $stmt->execute();
    $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($schedules as &$s) {
        $s['_rd']    = json_decode((string)($s['RecurrenceData'] ?? ''), true) ?: [];
        $s['_EffTz'] = ($s['TimeZone'] ?? null) ?: ($fallbackTz ?: 'UTC');
    }
    unset($s);

    return $schedules;
}
