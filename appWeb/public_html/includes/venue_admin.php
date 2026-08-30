<?php

declare(strict_types=1);

/**
 * iHymns — Venue + Service-Schedule shared READ + WRITE core (#1969,
 * API-coverage batch 1 C2 + batch 3 O4)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/venues` (the web admin page), the `?action=org_venues` read
 * API, and the new `?action=org_admin_venue_save`, `?action=org_admin_venue_delete`,
 * `?action=org_admin_schedule_save` and `?action=org_admin_schedule_delete`
 * WRITE API actions all need to answer or change the SAME thing — "what
 * venues does this organisation have, what recurring service times does
 * each venue keep, and how do I add/edit/remove one?" This file is the
 * ONE place all of that lives; every caller asks/tells it instead of each
 * running its own copy of the same queries.
 *
 * DETAILED / WHY A SHARED CORE FILE, NOT TWO COPIES (rule #22)
 * ----------------------------------------------------------------------------
 * `manage/venues.php` used to run BOTH the reads (its "load for render"
 * step) and the writes (its `venue_save`/`venue_delete`/`schedule_save`/
 * `schedule_delete` POST handlers) inline. The read half was extracted
 * first, for the `org_venues` API action (§4.1 C2). Batch 3's O4
 * (`.claude/api-coverage-2026-08-28.md` §4.2) extracts the WRITE half the
 * same way, for the org-admin self-service `org_admin_venue_*` /
 * `org_admin_schedule_*` API actions — `manage/venues.php`'s own POST
 * handlers are re-pointed at these same functions (NO behaviour change:
 * every validation rule, default, and SQL statement below is byte-for-byte
 * the code that used to live inline in the page) so the page and the API
 * can never drift on what counts as a valid venue/schedule.
 *
 * The write functions take a POST-shaped `$input` array (string/scalar
 * values, exactly what `$_POST` or a decoded JSON body looks like) rather
 * than reading `$_POST` themselves, so a form-rendering page and a JSON
 * API action can both call in with their own already-decoded body. They
 * THROW `\RuntimeException` with a plain-English message on any validation
 * failure — the caller decides how to surface that (an inline `$error`
 * banner for the page, a `sendJson(['error'=>...], 4xx)` for the API) —
 * mirroring `orgLogoValidateAndStage()`'s throw-on-reject shape
 * (`includes/org_logo_admin.php`).
 *
 * `venueAdminGetVenue()` / `venueAdminGetSchedule()` (single-row reads,
 * new alongside the write functions) serve TWO jobs: (1) resolving a
 * venue/schedule's OWNING OrgId so an API caller can gate an update/delete
 * BEFORE performing it (org-admin, not just system-admin, may now call
 * these — Q4's "org admins manage their own venues" decision — so a
 * write against a venue that turns out to belong to a DIFFERENT org than
 * the caller administers must be refused, not silently reassigned); and
 * (2) echoing the STORED row back to an API caller after a save (rule
 * #35/#40 — the response is server truth, never an echo of the request).
 *
 * Direct access is blocked (same guard as `tune_admin.php` /
 * `publisher_helpers.php`) so this file can't be requested as an endpoint
 * via an open Apache config.
 *
 * @link appWeb/public_html/manage/venues.php   page consumer (both read + write halves re-pointed here)
 * @link appWeb/public_html/api.php              org_venues (read) + org_admin_venue_save/org_admin_schedule_save (write) API consumers
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

/**
 * One venue by Id, in the SAME shape `venueAdminListForOrg()`'s rows are —
 * a single-row twin used to (a) resolve a venue's OWNING OrgId for an
 * org-admin gate check before a write, and (b) echo the stored row back to
 * an API caller after a save. Returns `null` when the venue doesn't exist.
 *
 * @return array{Id:int,OrgId:int,Name:string,AddressLine:?string,City:?string,
 *   Postcode:?string,CountryCode:?string,Latitude:?float,Longitude:?float,
 *   RadiusMetres:?int,TimeZone:string,IsActive:int}|null
 */
function venueAdminGetVenue(\mysqli $db, int $venueId): ?array
{
    if ($venueId <= 0) { return null; }
    $stmt = $db->prepare(
        'SELECT Id, OrgId, Name, AddressLine, City, Postcode, CountryCode,
                Latitude, Longitude, RadiusMetres, TimeZone, IsActive
           FROM tblOrgVenues WHERE Id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $venueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * One service schedule by Id, decoded exactly as `venueAdminSchedulesForVenue()`
 * decodes its rows (`_rd` + `_EffTz`) — resolves its own owning venue's
 * `TimeZone` itself (a one-row JOIN) so a caller needs only the schedule
 * id, not a separately-fetched venue row. Returns `null` when the
 * schedule doesn't exist. `OrgId` is the schedule's OWN denormalised
 * column (every write below keeps it in lockstep with its venue's OrgId —
 * see `venueAdminSaveSchedule()`), so this needs no join for the gate
 * check half of its job, only for `_EffTz`.
 *
 * @return array{Id:int,VenueId:int,OrgId:int,Title:string,DayOfWeek:?int,
 *   StartTime:string,DurationMins:int,RecurrenceKind:string,
 *   RecurrenceData:?string,TimeZone:?string,IsActive:int,_rd:array,_EffTz:string}|null
 */
function venueAdminGetSchedule(\mysqli $db, int $scheduleId): ?array
{
    if ($scheduleId <= 0) { return null; }
    $stmt = $db->prepare(
        'SELECT s.Id, s.VenueId, s.OrgId, s.Title, s.DayOfWeek, s.StartTime, s.DurationMins,
                s.RecurrenceKind, s.RecurrenceData, s.TimeZone, s.IsActive,
                v.TimeZone AS VenueTimeZone
           FROM tblOrgServiceSchedules s
           JOIN tblOrgVenues v ON v.Id = s.VenueId
          WHERE s.Id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }
    $row['_rd']    = json_decode((string)($row['RecurrenceData'] ?? ''), true) ?: [];
    $row['_EffTz'] = ($row['TimeZone'] ?? null) ?: ((string)($row['VenueTimeZone'] ?? '') ?: 'UTC');
    return $row;
}

/* =============================================================================
 * WRITE HALF (#1969 batch 3, O4) — extracted byte-for-byte from
 * `manage/venues.php`'s pre-extraction `venue_save`/`venue_delete`/
 * `schedule_save`/`schedule_delete` POST handlers. Every validation rule,
 * default, clamp, and SQL statement below is UNCHANGED from that inline
 * code — only the source of the input (a passed-in `$input` array instead
 * of reading `$_POST` directly) and the failure signal (a thrown
 * `\RuntimeException` instead of an inline `$error =` + implicit `break`)
 * differ, so the page's re-pointed handlers behave identically to before.
 * ========================================================================= */

/**
 * `RecurrenceKind` vocabulary — VARCHAR + app-validated allow-list, never
 * an ENUM (rule #20: a growable vocabulary is a value-add away from an
 * ALTER). The ONE central map both `manage/venues.php` (its `<select>`
 * options + this validation) and the `org_admin_schedule_save` API action
 * read, so the two can never list a different set of cadences.
 */
if (!defined('IHYMNS_VENUE_RECURRENCE_KINDS_DEFINED')) {
    define('IHYMNS_VENUE_RECURRENCE_KINDS_DEFINED', true);
    define('IHYMNS_VENUE_RECURRENCE_KINDS', [
        'weekly'      => 'Every week',
        'fortnightly' => 'Every 2 weeks',
        'monthly_nth' => 'Monthly (nth weekday)',
        'one_off'     => 'One-off date',
    ]);
}

/**
 * Venue create/update — the ONE write path shared by `manage/venues.php`'s
 * `venue_save` POST handler and the `org_admin_venue_save` API action.
 * `$input['venue_id']` absent/`0` creates a new row; a positive value
 * updates that row (including re-pointing its `OrgId`, unchanged from the
 * pre-extraction page behaviour — the caller is responsible for gating
 * that on both the venue's CURRENT org and the TARGET org, since this
 * function itself has no notion of "who is allowed to do this").
 *
 * @param  array $input  POST-shaped fields: venue_id?, org_id, name,
 *   address_line?, city?, postcode?, country_code?, timezone?, place_id?,
 *   is_active?, latitude?, longitude?, radius_metres?.
 * @return array{id:int, orgId:int, name:string, created:bool}
 * @throws \RuntimeException  Plain-English message on validation/lookup failure.
 */
function venueAdminSaveVenue(\mysqli $db, array $input): array
{
    $venueId = (int)($input['venue_id'] ?? 0);
    $orgId   = (int)($input['org_id'] ?? 0);
    $name    = trim((string)($input['name'] ?? ''));
    $addr    = trim((string)($input['address_line'] ?? ''));
    $city    = trim((string)($input['city'] ?? ''));
    $post    = trim((string)($input['postcode'] ?? ''));
    $cc      = strtoupper(trim((string)($input['country_code'] ?? '')));
    $tz      = trim((string)($input['timezone'] ?? 'UTC'));
    $placeId = (int)($input['place_id'] ?? 0);
    $isActive = !empty($input['is_active']) ? 1 : 0;

    if ($name === '') { throw new \RuntimeException('Venue name is required.'); }
    if ($orgId <= 0)  { throw new \RuntimeException('Choose an organisation first.'); }
    $tzList = \DateTimeZone::listIdentifiers();
    if (!in_array($tz, $tzList, true)) { $tz = 'UTC'; }
    if ($cc !== '' && !preg_match('/^[A-Z]{2}$/', $cc)) { $cc = ''; }

    // Confirm the org exists (FK would throw, but this gives a friendly error).
    $chk = $db->prepare('SELECT 1 FROM tblOrganisations WHERE Id = ? LIMIT 1');
    $chk->bind_param('i', $orgId);
    $chk->execute();
    if ($chk->get_result()->fetch_row() === null) { $chk->close(); throw new \RuntimeException('Unknown organisation.'); }
    $chk->close();

    // Resolve coordinates: a geocoder pick (PlaceId) wins; else the
    // optional manual lat/lng. placesLoadById() returns {lat,lon}.
    $lat = (($input['latitude']  ?? '') !== '') ? (float)$input['latitude']  : null;
    $lng = (($input['longitude'] ?? '') !== '') ? (float)$input['longitude'] : null;
    $placeIdOrNull = $placeId > 0 ? $placeId : null;
    if ($placeIdOrNull !== null && function_exists('placesLoadById')) {
        $place = placesLoadById($db, $placeIdOrNull);
        if ($place) {
            if ($lat === null && isset($place['lat'])) { $lat = (float)$place['lat']; }
            if ($lng === null && isset($place['lon'])) { $lng = (float)$place['lon']; }
        }
    }
    // Clamp coordinates to valid WGS84 ranges.
    if ($lat !== null && ($lat < -90 || $lat > 90))   { $lat = null; }
    if ($lng !== null && ($lng < -180 || $lng > 180)) { $lng = null; }
    $radius = (($input['radius_metres'] ?? '') !== '') ? max(0, min(50000, (int)$input['radius_metres'])) : null;

    $addrN = $addr !== '' ? $addr : null;
    $cityN = $city !== '' ? $city : null;
    $postN = $post !== '' ? $post : null;
    $ccN   = $cc !== '' ? $cc : null;

    $created = false;
    if ($venueId > 0) {
        $stmt = $db->prepare(
            'UPDATE tblOrgVenues
                SET OrgId = ?, Name = ?, AddressLine = ?, City = ?, Postcode = ?,
                    CountryCode = ?, PlaceId = ?, Latitude = ?, Longitude = ?,
                    RadiusMetres = ?, TimeZone = ?, IsActive = ?
              WHERE Id = ?'
        );
        // i s s s s  s i d d  i s i  i
        $stmt->bind_param(
            'isssssiddisii',
            $orgId, $name, $addrN, $cityN, $postN,
            $ccN, $placeIdOrNull, $lat, $lng,
            $radius, $tz, $isActive, $venueId
        );
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO tblOrgVenues
                (OrgId, Name, AddressLine, City, Postcode, CountryCode,
                 PlaceId, Latitude, Longitude, RadiusMetres, TimeZone, IsActive)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'isssssiddisi',
            $orgId, $name, $addrN, $cityN, $postN, $ccN,
            $placeIdOrNull, $lat, $lng, $radius, $tz, $isActive
        );
        $stmt->execute();
        $venueId = (int)$db->insert_id;
        $stmt->close();
        $created = true;
    }

    return ['id' => $venueId, 'orgId' => $orgId, 'name' => $name, 'created' => $created];
}

/**
 * Venue delete (CASCADE removes its schedules via the FK) — the ONE write
 * path shared by `manage/venues.php`'s `venue_delete` POST handler and the
 * `org_admin_venue_delete` API action.
 *
 * @return array{orgId:int, name:string}|null  The deleted venue's org +
 *   name (for the caller's activity log), or `null` if the venue never
 *   existed (the caller decides whether that's a 404 or a silent no-op —
 *   `manage/venues.php` treats it as a silent no-op, matching its
 *   pre-extraction behaviour).
 * @throws \RuntimeException  When `$venueId` is missing/invalid.
 */
function venueAdminDeleteVenue(\mysqli $db, int $venueId): ?array
{
    if ($venueId <= 0) { throw new \RuntimeException('Missing venue.'); }
    $stmt = $db->prepare('SELECT OrgId, Name FROM tblOrgVenues WHERE Id = ?');
    $stmt->bind_param('i', $venueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }
    $del = $db->prepare('DELETE FROM tblOrgVenues WHERE Id = ?');
    $del->bind_param('i', $venueId);
    $del->execute();
    $del->close();
    return ['orgId' => (int)$row['OrgId'], 'name' => (string)$row['Name']];
}

/**
 * Service-schedule create/update — the ONE write path shared by
 * `manage/venues.php`'s `schedule_save` POST handler and the
 * `org_admin_schedule_save` API action. `OrgId` is ALWAYS DERIVED from
 * the venue (`tblOrgVenues.OrgId`) — never trusted from the caller's
 * input, exactly as the pre-extraction inline handler never trusted a
 * posted `org_id` for this write.
 *
 * @param  array $input  POST-shaped fields: schedule_id?, venue_id, title?,
 *   recurrence_kind?, start_time, duration_mins?, timezone?, is_active?,
 *   day_of_week? (required unless recurrence_kind is one_off), nth?
 *   (monthly_nth), one_off_date? (one_off), anchor_date? (fortnightly),
 *   until_date?, exceptions? (comma/space/newline-separated dates).
 * @return array{id:int, orgId:int, venueId:int, title:string, created:bool}
 * @throws \RuntimeException  Plain-English message on validation/lookup failure.
 */
function venueAdminSaveSchedule(\mysqli $db, array $input): array
{
    $schedId = (int)($input['schedule_id'] ?? 0);
    $venueId = (int)($input['venue_id'] ?? 0);
    if ($venueId <= 0) { throw new \RuntimeException('Missing venue.'); }

    // Derive OrgId + default tz from the venue — never trust posted OrgId.
    $vs = $db->prepare('SELECT OrgId, TimeZone FROM tblOrgVenues WHERE Id = ?');
    $vs->bind_param('i', $venueId);
    $vs->execute();
    $venue = $vs->get_result()->fetch_assoc();
    $vs->close();
    if (!$venue) { throw new \RuntimeException('Unknown venue.'); }
    $orgId = (int)$venue['OrgId'];

    $title = trim((string)($input['title'] ?? 'Service'));
    if ($title === '') { $title = 'Service'; }
    $kind  = (string)($input['recurrence_kind'] ?? 'weekly');
    if (!array_key_exists($kind, IHYMNS_VENUE_RECURRENCE_KINDS)) { $kind = 'weekly'; }
    $startTime = (string)($input['start_time'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        throw new \RuntimeException('Enter a valid start time (HH:MM).');
    }
    $startTime .= ':00';
    $duration = max(1, min(1440, (int)($input['duration_mins'] ?? 90)));
    $tzOverride = trim((string)($input['timezone'] ?? ''));
    $tzList = \DateTimeZone::listIdentifiers();
    $tzN = ($tzOverride !== '' && in_array($tzOverride, $tzList, true)) ? $tzOverride : null;
    $isActive = !empty($input['is_active']) ? 1 : 0;

    // DayOfWeek required for recurring kinds; NULL for one_off.
    $dow = (int)($input['day_of_week'] ?? 0);
    if ($kind !== 'one_off') {
        if ($dow < 1 || $dow > 7) { throw new \RuntimeException('Choose a day of the week.'); }
        $dowN = $dow;
    } else {
        $dowN = null;
    }

    // Assemble RecurrenceData JSON from the kind-specific inputs.
    $rd = [];
    if ($kind === 'monthly_nth') {
        $nth = (int)($input['nth'] ?? 1);
        if (!in_array($nth, [1, 2, 3, 4, 5, -1], true)) { $nth = 1; }
        $rd['nth'] = $nth;
    } elseif ($kind === 'one_off') {
        $oneOff = (string)($input['one_off_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $oneOff)) { throw new \RuntimeException('Enter the one-off date (YYYY-MM-DD).'); }
        $rd['date'] = $oneOff;
    } elseif ($kind === 'fortnightly') {
        $anchor = (string)($input['anchor_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) { $rd['anchor'] = $anchor; }
    }
    $until = (string)($input['until_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) { $rd['until'] = $until; }
    // Exceptions: comma/space/newline-separated YYYY-MM-DD dates.
    $excRaw = (string)($input['exceptions'] ?? '');
    if (trim($excRaw) !== '') {
        $exc = preg_split('/[\s,]+/', trim($excRaw)) ?: [];
        $exc = array_values(array_filter($exc, static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        if ($exc) { $rd['exceptions'] = $exc; }
    }
    $rdJson = $rd ? json_encode($rd, JSON_UNESCAPED_SLASHES) : null;

    $created = false;
    if ($schedId > 0) {
        $stmt = $db->prepare(
            'UPDATE tblOrgServiceSchedules
                SET VenueId = ?, OrgId = ?, Title = ?, DayOfWeek = ?, StartTime = ?,
                    DurationMins = ?, RecurrenceKind = ?, RecurrenceData = ?,
                    TimeZone = ?, IsActive = ?
              WHERE Id = ?'
        );
        $stmt->bind_param(
            'iisisisssii',
            $venueId, $orgId, $title, $dowN, $startTime,
            $duration, $kind, $rdJson, $tzN, $isActive, $schedId
        );
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO tblOrgServiceSchedules
                (VenueId, OrgId, Title, DayOfWeek, StartTime, DurationMins,
                 RecurrenceKind, RecurrenceData, TimeZone, IsActive)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'iisisisssi',
            $venueId, $orgId, $title, $dowN, $startTime,
            $duration, $kind, $rdJson, $tzN, $isActive
        );
        $stmt->execute();
        $schedId = (int)$db->insert_id;
        $stmt->close();
        $created = true;
    }

    return ['id' => $schedId, 'orgId' => $orgId, 'venueId' => $venueId, 'title' => $title, 'created' => $created];
}

/**
 * Schedule delete — the ONE write path shared by `manage/venues.php`'s
 * `schedule_delete` POST handler and the `org_admin_schedule_delete` API
 * action.
 *
 * @return array{orgId:int}|null  The deleted schedule's org (for the
 *   caller's activity log), or `null` if the schedule never existed
 *   (`manage/venues.php` treats that as a silent no-op, unchanged).
 * @throws \RuntimeException  When `$scheduleId` is missing/invalid.
 */
function venueAdminDeleteSchedule(\mysqli $db, int $scheduleId): ?array
{
    if ($scheduleId <= 0) { throw new \RuntimeException('Missing service time.'); }
    $stmt = $db->prepare('SELECT OrgId FROM tblOrgServiceSchedules WHERE Id = ?');
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }
    $del = $db->prepare('DELETE FROM tblOrgServiceSchedules WHERE Id = ?');
    $del->bind_param('i', $scheduleId);
    $del->execute();
    $del->close();
    return ['orgId' => (int)$row['OrgId']];
}
