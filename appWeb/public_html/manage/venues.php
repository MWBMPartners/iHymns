<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Venues & Service Times (Service Mode Phase 1, #1325)
 *
 * Lets an organisation define its physical VENUES (name, address, map-pin
 * location + radius, timezone) and the RECURRING SERVICE TIMES at each venue —
 * the foundation for "Service Mode" (#1323, congregation-wide Live Follow), and
 * useful org metadata in its own right.
 *
 * WHY THIS PAGE EXISTS (ELI5): before this, an org could say *who* it is and
 * *what city* it is in, but there was nowhere to say "we meet at the Main
 * Sanctuary, 10am every Sunday." This page is that "where + when" editor.
 *
 * Data model: tblOrgVenues + tblOrgServiceSchedules (migrate-org-venues.php).
 * lat/lng + radius are a CONVENIENCE geofence + map pin — NOT the presence gate
 * (Service Mode Phase 2 gates on a venue-displayed rotating code; geolocation is
 * spoofable/inaccurate indoors — see .claude/live-congregant-strategy.md).
 *
 * Shared infra reused (CLAUDE.md modularity rule): auth.php (isAuthenticated /
 * userHasEntitlement), db_mysql.php (getDbMysqli + bind_param), places.php
 * (placesLoadById for the geocoder pick), place-search.js (the Photon/Nominatim
 * autocomplete, same module organisations.php uses), admin-nav / head-libs /
 * admin-footer partials, .admin-table-responsive, the session-token CSRF helpers
 * (csrfToken / validateCsrf). Gated by `manage_organisations` (same as
 * organisations.php).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_organisations', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_organisations required</h1></body></html>';
    exit;
}
$activePage = 'venues';

$error   = '';
$success = '';
$db      = getDbMysqli();

/* ------------------------------------------------------------------
 * Central vocabularies (CLAUDE.md: never hard-code these inline twice).
 * RecurrenceKind is VARCHAR (app-validated) not ENUM — adding a cadence
 * is an entry here, never an ALTER (rule #20).
 * ------------------------------------------------------------------ */
$DOW = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$RECURRENCE_KINDS = [
    'weekly'      => 'Every week',
    'fortnightly' => 'Every 2 weeks',
    'monthly_nth' => 'Monthly (nth weekday)',
    'one_off'     => 'One-off date',
];
$NTH_LABELS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', -1 => 'last'];
/* Cache the IANA tz list once (DateTimeZone::listIdentifiers is the canonical
   source — https://www.php.net/manual/en/datetimezone.listidentifiers.php). */
$TZ_LIST = \DateTimeZone::listIdentifiers();

/**
 * Schema-presence guard. Migrations are NOT auto-applied on deploy, so the
 * tables may not exist on this env yet ("it's in schema.sql" ≠ "it exists" —
 * CLAUDE.md red flag). Probe INFORMATION_SCHEMA so a missing table renders a
 * themed "run the migration" card instead of white-screening under STRICT.
 */
function _venuesTableExists(\mysqli $db, string $t): bool
{
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
        error_log('[manage/venues.php] table probe failed: ' . $e->getMessage());
        return false;
    }
}
$schemaReady = _venuesTableExists($db, 'tblOrgVenues') && _venuesTableExists($db, 'tblOrgServiceSchedules');

/**
 * Build a human "Every Sunday at 10:00 (90 min) · Europe/London" summary.
 * Pure — takes the row + its effective tz, returns a string for display.
 */
function venueScheduleSummary(array $s, array $DOW, array $NTH_LABELS): string
{
    $rd   = is_array($s['_rd'] ?? null) ? $s['_rd'] : [];
    $day  = $DOW[(int)($s['DayOfWeek'] ?? 0)] ?? '';
    $time = substr((string)($s['StartTime'] ?? '00:00:00'), 0, 5);
    $dur  = (int)($s['DurationMins'] ?? 0);
    $tz   = (string)($s['_EffTz'] ?? 'UTC');
    switch ((string)$s['RecurrenceKind']) {
        case 'weekly':      $base = "Every {$day} at {$time}"; break;
        case 'fortnightly': $base = "Every other {$day} at {$time}"; break;
        case 'monthly_nth':
            $nth  = (int)($rd['nth'] ?? 1);
            $word = $NTH_LABELS[$nth] ?? 'first';
            $base = "The {$word} {$day} of each month at {$time}";
            break;
        case 'one_off':
            $date = (string)($rd['date'] ?? '?');
            $base = "On {$date} at {$time}";
            break;
        default: $base = trim("{$day} at {$time}");
    }
    return "{$base} ({$dur} min) · {$tz}";
}

/**
 * Compute the next $n occurrence datetimes (Y-m-d H:i) for a schedule, in its
 * effective timezone. Iterates day-by-day applying a recurrence predicate —
 * simple + correct for a short preview (caps at ~14 months of look-ahead).
 */
function venueNextOccurrences(array $s, int $n = 3): array
{
    try {
        $tz = new \DateTimeZone((string)($s['_EffTz'] ?? 'UTC'));
    } catch (\Throwable $e) {
        $tz = new \DateTimeZone('UTC');
    }
    $rd   = is_array($s['_rd'] ?? null) ? $s['_rd'] : [];
    $kind = (string)$s['RecurrenceKind'];
    $dow  = (int)($s['DayOfWeek'] ?? 0);
    $hhmm = substr((string)($s['StartTime'] ?? '00:00:00'), 0, 5);
    $exceptions = array_flip(array_map('strval', $rd['exceptions'] ?? []));
    $until = isset($rd['until']) && $rd['until'] !== '' ? (string)$rd['until'] : null;

    if ($kind === 'one_off') {
        $d = (string)($rd['date'] ?? '');
        return $d !== '' ? ["{$d} {$hhmm}"] : [];
    }
    if ($dow < 1 || $dow > 7) {
        return [];
    }

    // Anchor for the fortnightly week-parity test (default: today).
    try {
        $anchor = isset($rd['anchor']) && $rd['anchor'] !== ''
            ? new \DateTime((string)$rd['anchor'], $tz)
            : new \DateTime('today', $tz);
    } catch (\Throwable $e) {
        $anchor = new \DateTime('today', $tz);
    }
    $cursor = new \DateTime('today', $tz);
    $out = [];
    for ($i = 0; $i < 420 && count($out) < $n; $i++, $cursor->modify('+1 day')) {
        if ((int)$cursor->format('N') !== $dow) {
            continue;
        }
        $ymd = $cursor->format('Y-m-d');
        if (isset($exceptions[$ymd]) || ($until !== null && $ymd > $until)) {
            continue;
        }
        $match = false;
        if ($kind === 'weekly') {
            $match = true;
        } elseif ($kind === 'fortnightly') {
            $weeks = (int)floor((int)$anchor->diff($cursor)->days / 7);
            $match = ($weeks % 2 === 0);
        } elseif ($kind === 'monthly_nth') {
            $nth = (int)($rd['nth'] ?? 1);
            $dayOfMonth = (int)$cursor->format('j');
            if ($nth === -1) {
                // last weekday-of-month: no same weekday within the next 7 days
                $probe = (clone $cursor)->modify('+7 days');
                $match = ((int)$probe->format('n') !== (int)$cursor->format('n'));
            } else {
                $match = ((int)ceil($dayOfMonth / 7) === $nth);
            }
        }
        if ($match) {
            $out[] = "{$ymd} {$hhmm}";
        }
    }
    return $out;
}

/* ===================================================================
 * POST actions — every one CSRF-checked + bind_param. OrgId for a
 * schedule is DERIVED from its venue (never trusted from the client).
 * =================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {

            /* ---- Venue create / update ---- */
            case 'venue_save': {
                $venueId = (int)($_POST['venue_id'] ?? 0);
                $orgId   = (int)($_POST['org_id'] ?? 0);
                $name    = trim((string)($_POST['name'] ?? ''));
                $addr    = trim((string)($_POST['address_line'] ?? ''));
                $city    = trim((string)($_POST['city'] ?? ''));
                $post    = trim((string)($_POST['postcode'] ?? ''));
                $cc      = strtoupper(trim((string)($_POST['country_code'] ?? '')));
                $tz      = trim((string)($_POST['timezone'] ?? 'UTC'));
                $placeId = (int)($_POST['place_id'] ?? 0);
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($name === '') { throw new \RuntimeException('Venue name is required.'); }
                if ($orgId <= 0)  { throw new \RuntimeException('Choose an organisation first.'); }
                if (!in_array($tz, $GLOBALS['TZ_LIST'], true)) { $tz = 'UTC'; }
                if ($cc !== '' && !preg_match('/^[A-Z]{2}$/', $cc)) { $cc = ''; }

                // Confirm the org exists (FK would throw, but this gives a friendly error).
                $chk = $db->prepare('SELECT 1 FROM tblOrganisations WHERE Id = ? LIMIT 1');
                $chk->bind_param('i', $orgId);
                $chk->execute();
                if ($chk->get_result()->fetch_row() === null) { $chk->close(); throw new \RuntimeException('Unknown organisation.'); }
                $chk->close();

                // Resolve coordinates: a geocoder pick (PlaceId) wins; else the
                // optional manual lat/lng. placesLoadById() returns {lat,lon}.
                $lat = ($_POST['latitude']  ?? '') !== '' ? (float)$_POST['latitude']  : null;
                $lng = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
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
                $radius = ($_POST['radius_metres'] ?? '') !== '' ? max(0, min(50000, (int)$_POST['radius_metres'])) : null;

                $addrN = $addr !== '' ? $addr : null;
                $cityN = $city !== '' ? $city : null;
                $postN = $post !== '' ? $post : null;
                $ccN   = $cc !== '' ? $cc : null;

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
                    logActivity('venue.edit', 'organisation', (string)$orgId, ['venue_id' => $venueId, 'name' => $name]);
                    $success = 'Venue updated.';
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
                    logActivity('venue.create', 'organisation', (string)$orgId, ['venue_id' => $venueId, 'name' => $name]);
                    $success = 'Venue added.';
                }
                break;
            }

            /* ---- Venue delete (CASCADE removes its schedules) ---- */
            case 'venue_delete': {
                $venueId = (int)($_POST['venue_id'] ?? 0);
                if ($venueId <= 0) { throw new \RuntimeException('Missing venue.'); }
                $stmt = $db->prepare('SELECT OrgId, Name FROM tblOrgVenues WHERE Id = ?');
                $stmt->bind_param('i', $venueId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $del = $db->prepare('DELETE FROM tblOrgVenues WHERE Id = ?');
                    $del->bind_param('i', $venueId);
                    $del->execute();
                    $del->close();
                    logActivity('venue.delete', 'organisation', (string)$row['OrgId'], ['venue_id' => $venueId, 'name' => $row['Name']]);
                    $success = 'Venue deleted.';
                }
                break;
            }

            /* ---- Service schedule create / update ---- */
            case 'schedule_save': {
                $schedId = (int)($_POST['schedule_id'] ?? 0);
                $venueId = (int)($_POST['venue_id'] ?? 0);
                if ($venueId <= 0) { throw new \RuntimeException('Missing venue.'); }

                // Derive OrgId + default tz from the venue — never trust posted OrgId.
                $vs = $db->prepare('SELECT OrgId, TimeZone FROM tblOrgVenues WHERE Id = ?');
                $vs->bind_param('i', $venueId);
                $vs->execute();
                $venue = $vs->get_result()->fetch_assoc();
                $vs->close();
                if (!$venue) { throw new \RuntimeException('Unknown venue.'); }
                $orgId = (int)$venue['OrgId'];

                $title = trim((string)($_POST['title'] ?? 'Service'));
                if ($title === '') { $title = 'Service'; }
                $kind  = (string)($_POST['recurrence_kind'] ?? 'weekly');
                if (!array_key_exists($kind, $GLOBALS['RECURRENCE_KINDS'])) { $kind = 'weekly'; }
                $startTime = (string)($_POST['start_time'] ?? '');
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
                    throw new \RuntimeException('Enter a valid start time (HH:MM).');
                }
                $startTime .= ':00';
                $duration = max(1, min(1440, (int)($_POST['duration_mins'] ?? 90)));
                $tzOverride = trim((string)($_POST['timezone'] ?? ''));
                $tzN = ($tzOverride !== '' && in_array($tzOverride, $GLOBALS['TZ_LIST'], true)) ? $tzOverride : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                // DayOfWeek required for recurring kinds; NULL for one_off.
                $dow = (int)($_POST['day_of_week'] ?? 0);
                if ($kind !== 'one_off') {
                    if ($dow < 1 || $dow > 7) { throw new \RuntimeException('Choose a day of the week.'); }
                    $dowN = $dow;
                } else {
                    $dowN = null;
                }

                // Assemble RecurrenceData JSON from the kind-specific inputs.
                $rd = [];
                if ($kind === 'monthly_nth') {
                    $nth = (int)($_POST['nth'] ?? 1);
                    if (!in_array($nth, [1, 2, 3, 4, 5, -1], true)) { $nth = 1; }
                    $rd['nth'] = $nth;
                } elseif ($kind === 'one_off') {
                    $oneOff = (string)($_POST['one_off_date'] ?? '');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $oneOff)) { throw new \RuntimeException('Enter the one-off date (YYYY-MM-DD).'); }
                    $rd['date'] = $oneOff;
                } elseif ($kind === 'fortnightly') {
                    $anchor = (string)($_POST['anchor_date'] ?? '');
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) { $rd['anchor'] = $anchor; }
                }
                $until = (string)($_POST['until_date'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) { $rd['until'] = $until; }
                // Exceptions: comma/space/newline-separated YYYY-MM-DD dates.
                $excRaw = (string)($_POST['exceptions'] ?? '');
                if (trim($excRaw) !== '') {
                    $exc = preg_split('/[\s,]+/', trim($excRaw)) ?: [];
                    $exc = array_values(array_filter($exc, static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
                    if ($exc) { $rd['exceptions'] = $exc; }
                }
                $rdJson = $rd ? json_encode($rd, JSON_UNESCAPED_SLASHES) : null;

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
                    logActivity('venue.schedule.edit', 'organisation', (string)$orgId, ['schedule_id' => $schedId, 'venue_id' => $venueId]);
                    $success = 'Service time updated.';
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
                    $newId = (int)$db->insert_id;
                    $stmt->close();
                    logActivity('venue.schedule.create', 'organisation', (string)$orgId, ['schedule_id' => $newId, 'venue_id' => $venueId]);
                    $success = 'Service time added.';
                }
                break;
            }

            /* ---- Schedule delete ---- */
            case 'schedule_delete': {
                $schedId = (int)($_POST['schedule_id'] ?? 0);
                if ($schedId <= 0) { throw new \RuntimeException('Missing service time.'); }
                $stmt = $db->prepare('SELECT OrgId FROM tblOrgServiceSchedules WHERE Id = ?');
                $stmt->bind_param('i', $schedId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $del = $db->prepare('DELETE FROM tblOrgServiceSchedules WHERE Id = ?');
                    $del->bind_param('i', $schedId);
                    $del->execute();
                    $del->close();
                    logActivity('venue.schedule.delete', 'organisation', (string)$row['OrgId'], ['schedule_id' => $schedId]);
                    $success = 'Service time deleted.';
                }
                break;
            }
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage() !== '' ? $e->getMessage() : 'Could not save your changes.';
        error_log('[manage/venues.php] ' . $e->getMessage());
    }
}

/* ===================================================================
 * Load for render — org list, selected org's venues, selected venue's
 * schedules. All reads guarded; everything echoed via htmlspecialchars.
 * =================================================================== */
$orgs          = [];
$venues        = [];
$schedules     = [];
$selectedOrgId = (int)($_GET['org'] ?? ($_POST['org_id'] ?? 0));
$selectedVenueId = (int)($_GET['venue'] ?? 0);

if ($schemaReady) {
    try {
        $res = $db->query('SELECT Id, Name FROM tblOrganisations ORDER BY Name ASC');
        $orgs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if ($selectedOrgId <= 0 && $orgs) { $selectedOrgId = (int)$orgs[0]['Id']; }

        if ($selectedOrgId > 0) {
            $stmt = $db->prepare(
                'SELECT Id, OrgId, Name, AddressLine, City, Postcode, CountryCode,
                        Latitude, Longitude, RadiusMetres, TimeZone, IsActive
                   FROM tblOrgVenues WHERE OrgId = ? ORDER BY SortOrder ASC, Name ASC'
            );
            $stmt->bind_param('i', $selectedOrgId);
            $stmt->execute();
            $venues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Confirm the selected venue belongs to the selected org, then load its schedules.
        $selectedVenue = null;
        foreach ($venues as $v) {
            if ((int)$v['Id'] === $selectedVenueId) { $selectedVenue = $v; break; }
        }
        if ($selectedVenue) {
            $stmt = $db->prepare(
                'SELECT Id, VenueId, Title, DayOfWeek, StartTime, DurationMins,
                        RecurrenceKind, RecurrenceData, TimeZone, IsActive
                   FROM tblOrgServiceSchedules WHERE VenueId = ?
                  ORDER BY DayOfWeek ASC, StartTime ASC'
            );
            $stmt->bind_param('i', $selectedVenueId);
            $stmt->execute();
            $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            // Decode RecurrenceData + resolve effective tz once per row.
            foreach ($schedules as &$s) {
                $s['_rd']     = json_decode((string)($s['RecurrenceData'] ?? ''), true) ?: [];
                $s['_EffTz']  = ($s['TimeZone'] ?? null) ?: ($selectedVenue['TimeZone'] ?? 'UTC');
            }
            unset($s);
        } else {
            $selectedVenueId = 0;
        }
    } catch (\Throwable $e) {
        $error = $error ?: 'Could not load venues. The schema may need migrating (/manage/setup-database).';
        error_log('[manage/venues.php] load: ' . $e->getMessage());
    }
}

// Find the row being edited (?edit_venue / ?edit_schedule), if any.
$editVenue = null;
$editVenueId = (int)($_GET['edit_venue'] ?? 0);
foreach ($venues as $v) { if ((int)$v['Id'] === $editVenueId) { $editVenue = $v; break; } }
$editSchedule = null;
$editScheduleId = (int)($_GET['edit_schedule'] ?? 0);
foreach ($schedules as $s) { if ((int)$s['Id'] === $editScheduleId) { $editSchedule = $s; break; } }
$editSchedRd = $editSchedule ? ($editSchedule['_rd'] ?? []) : [];

$selectedVenueRow = null;
foreach ($venues as $v) { if ((int)$v['Id'] === $selectedVenueId) { $selectedVenueRow = $v; break; } }

$csrf = csrfToken();

/** Tiny helper: build a same-page URL preserving org/venue context. */
function venuesUrl(array $overrides = []): string
{
    $base = ['org' => (int)($GLOBALS['selectedOrgId'] ?? 0)];
    if (($GLOBALS['selectedVenueId'] ?? 0) > 0) { $base['venue'] = (int)$GLOBALS['selectedVenueId']; }
    $q = array_filter(array_merge($base, $overrides), static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return '/manage/venues' . ($q ? '?' . http_build_query($q) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venues &amp; Service Times — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-2"><i class="bi bi-geo-alt me-2"></i>Venues &amp; Service Times</h1>
        <p class="text-secondary small mb-4" style="max-width: 60ch;">
            Tell iHymns <strong>where</strong> your organisation meets and <strong>when</strong>.
            This is the foundation for <em>Service Mode</em> (letting a congregation follow the
            service on their own device). The map location &amp; radius are a convenience —
            attendance is confirmed by an on-screen code, not your location.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="card border-warning">
                <div class="card-body">
                    <h2 class="h6 text-warning-emphasis"><i class="bi bi-database-exclamation me-1"></i>Schema not yet migrated</h2>
                    <p class="small mb-2">
                        The <code>tblOrgVenues</code> / <code>tblOrgServiceSchedules</code> tables don't
                        exist on this environment yet. Migrations aren't applied automatically on deploy.
                    </p>
                    <a class="btn btn-sm btn-amber-solid" href="/manage/setup-database">
                        <i class="bi bi-database-gear me-1"></i>Open Database Setup → run “Org Venues &amp; Service Schedules”
                    </a>
                </div>
            </div>
        <?php else: ?>

        <!-- Organisation selector -->
        <form method="get" action="/manage/venues" class="row g-2 align-items-end mb-4" style="max-width: 480px;">
            <div class="col">
                <label for="org-select" class="form-label small fw-semibold mb-1">Organisation</label>
                <select id="org-select" name="org" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['Id'] ?>" <?= (int)$o['Id'] === $selectedOrgId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['Name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$orgs): ?>
                <div class="col-auto"><span class="text-secondary small">No organisations yet.</span></div>
            <?php endif; ?>
        </form>

        <?php if ($selectedOrgId > 0): ?>
        <!-- ============================ VENUES ============================ -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-building me-1"></i>Venues</span>
                <a class="btn btn-sm btn-amber-solid" href="<?= htmlspecialchars(venuesUrl(['edit_venue' => 'new', 'venue' => null])) ?>#venue-form">
                    <i class="bi bi-plus-lg me-1"></i>Add venue
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!$venues): ?>
                    <p class="text-secondary small m-3 mb-3">No venues yet. Add the place(s) your organisation meets.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table-responsive align-middle mb-0">
                        <thead>
                            <tr>
                                <th data-col-priority="primary">Venue</th>
                                <th data-col-priority="secondary">Location</th>
                                <th data-col-priority="tertiary">Timezone</th>
                                <th data-col-priority="tertiary">Status</th>
                                <th data-col-priority="primary" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($venues as $v): ?>
                            <?php
                                $loc = array_filter([$v['AddressLine'] ?? '', $v['City'] ?? '', $v['Postcode'] ?? '', $v['CountryCode'] ?? '']);
                                $hasCoords = $v['Latitude'] !== null && $v['Longitude'] !== null;
                                $isSel = (int)$v['Id'] === $selectedVenueId;
                            ?>
                            <tr<?= $isSel ? ' class="table-active"' : '' ?>>
                                <td data-col-priority="primary">
                                    <span class="fw-semibold"><?= htmlspecialchars($v['Name']) ?></span>
                                </td>
                                <td data-col-priority="secondary">
                                    <span class="small"><?= $loc ? htmlspecialchars(implode(', ', $loc)) : '<span class="text-secondary">—</span>' ?></span>
                                    <?php if ($hasCoords): ?>
                                        <span class="badge text-bg-light ms-1" title="Map pin set (convenience geofence<?= $v['RadiusMetres'] !== null ? ', radius ' . (int)$v['RadiusMetres'] . ' m' : '' ?>)">
                                            <i class="bi bi-pin-map"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="tertiary"><span class="small"><?= htmlspecialchars($v['TimeZone']) ?></span></td>
                                <td data-col-priority="tertiary">
                                    <?php if ((int)$v['IsActive'] === 1): ?>
                                        <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary" class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(venuesUrl(['venue' => (int)$v['Id'], 'edit_venue' => null, 'edit_schedule' => null])) ?>#schedules" title="Manage service times">
                                        <i class="bi bi-clock-history"></i><span class="d-none d-md-inline ms-1">Service times</span>
                                    </a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_venue' => (int)$v['Id'], 'venue' => null])) ?>#venue-form" title="Edit venue">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" action="/manage/venues" class="d-inline"
                                          onsubmit="return confirm('Delete venue “<?= htmlspecialchars(addslashes($v['Name'])) ?>” and all its service times?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="venue_delete">
                                        <input type="hidden" name="org_id" value="<?= $selectedOrgId ?>">
                                        <input type="hidden" name="venue_id" value="<?= (int)$v['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete venue"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===================== VENUE ADD / EDIT FORM ===================== -->
        <?php if ($editVenue !== null || $editVenueId === 0 && ($_GET['edit_venue'] ?? '') === 'new'): ?>
            <?php $ev = $editVenue ?? []; $isEditV = !empty($ev); ?>
            <div class="card mb-4" id="venue-form">
                <div class="card-header fw-semibold">
                    <i class="bi bi-<?= $isEditV ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $isEditV ? 'Edit venue' : 'Add a venue' ?>
                </div>
                <div class="card-body">
                    <form method="post" action="/manage/venues" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="venue_save">
                        <input type="hidden" name="org_id" value="<?= $selectedOrgId ?>">
                        <input type="hidden" name="venue_id" value="<?= $isEditV ? (int)$ev['Id'] : 0 ?>">

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="v-name">Venue name <span class="text-danger">*</span></label>
                            <input type="text" id="v-name" name="name" class="form-control" required maxlength="150"
                                   value="<?= htmlspecialchars($ev['Name'] ?? '') ?>" placeholder="e.g. Main Sanctuary">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="v-place">Find location (optional)</label>
                            <input type="text" id="v-place" class="form-control" autocomplete="off"
                                   placeholder="Type an address or town to drop a map pin…"
                                   value="<?= htmlspecialchars(trim((($ev['City'] ?? '') . ' ' . ($ev['CountryCode'] ?? '')))) ?>">
                            <input type="hidden" id="v-place-id" name="place_id" value="">
                            <div class="form-text">Sets the map pin (lat/lng). A convenience only — not the attendance check.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="v-address">Address line</label>
                            <input type="text" id="v-address" name="address_line" class="form-control" maxlength="255"
                                   value="<?= htmlspecialchars($ev['AddressLine'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-city">City / town</label>
                            <input type="text" id="v-city" name="city" class="form-control" maxlength="120"
                                   value="<?= htmlspecialchars($ev['City'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-postcode">Postcode</label>
                            <input type="text" id="v-postcode" name="postcode" class="form-control" maxlength="20"
                                   value="<?= htmlspecialchars($ev['Postcode'] ?? '') ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-cc">Country (ISO-2)</label>
                            <input type="text" id="v-cc" name="country_code" class="form-control text-uppercase" maxlength="2"
                                   value="<?= htmlspecialchars($ev['CountryCode'] ?? '') ?>" placeholder="GB">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-lat">Latitude</label>
                            <input type="number" step="0.0000001" min="-90" max="90" id="v-lat" name="latitude" class="form-control"
                                   value="<?= isset($ev['Latitude']) && $ev['Latitude'] !== null ? htmlspecialchars((string)$ev['Latitude']) : '' ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-lng">Longitude</label>
                            <input type="number" step="0.0000001" min="-180" max="180" id="v-lng" name="longitude" class="form-control"
                                   value="<?= isset($ev['Longitude']) && $ev['Longitude'] !== null ? htmlspecialchars((string)$ev['Longitude']) : '' ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="v-radius">Radius (metres)</label>
                            <input type="number" min="0" max="50000" step="10" id="v-radius" name="radius_metres" class="form-control"
                                   value="<?= isset($ev['RadiusMetres']) && $ev['RadiusMetres'] !== null ? (int)$ev['RadiusMetres'] : '' ?>" placeholder="e.g. 150">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="v-tz">Timezone</label>
                            <select id="v-tz" name="timezone" class="form-select">
                                <?php $vtz = $ev['TimeZone'] ?? 'Europe/London'; foreach ($TZ_LIST as $tzId): ?>
                                    <option value="<?= htmlspecialchars($tzId) ?>" <?= $tzId === $vtz ? 'selected' : '' ?>><?= htmlspecialchars($tzId) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="v-active" name="is_active" <?= !$isEditV || (int)($ev['IsActive'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="v-active">Active (available for Service Mode)</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-amber-solid"><i class="bi bi-check-lg me-1"></i><?= $isEditV ? 'Save venue' : 'Add venue' ?></button>
                            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_venue' => null])) ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===================== SCHEDULES (selected venue) ===================== -->
        <?php if ($selectedVenueRow !== null): ?>
            <div class="card mb-4" id="schedules">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-calendar-week me-1"></i>Service times — <?= htmlspecialchars($selectedVenueRow['Name']) ?></span>
                    <a class="btn btn-sm btn-amber-solid" href="<?= htmlspecialchars(venuesUrl(['edit_schedule' => 'new'])) ?>#schedule-form">
                        <i class="bi bi-plus-lg me-1"></i>Add service time
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$schedules): ?>
                        <p class="text-secondary small m-3 mb-3">No service times yet for this venue.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table admin-table-responsive align-middle mb-0">
                            <thead>
                                <tr>
                                    <th data-col-priority="primary">Service</th>
                                    <th data-col-priority="primary">When</th>
                                    <th data-col-priority="secondary">Next dates</th>
                                    <th data-col-priority="tertiary">Status</th>
                                    <th data-col-priority="primary" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schedules as $s): ?>
                                <?php $next = venueNextOccurrences($s, 3); ?>
                                <tr>
                                    <td data-col-priority="primary"><span class="fw-semibold"><?= htmlspecialchars($s['Title']) ?></span></td>
                                    <td data-col-priority="primary"><span class="small"><?= htmlspecialchars(venueScheduleSummary($s, $DOW, $NTH_LABELS)) ?></span></td>
                                    <td data-col-priority="secondary">
                                        <?php if ($next): ?>
                                            <span class="small text-secondary"><?= htmlspecialchars(implode(' · ', array_map(static fn($d) => substr($d, 0, 16), $next))) ?></span>
                                        <?php else: ?>
                                            <span class="small text-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-col-priority="tertiary">
                                        <?= (int)$s['IsActive'] === 1
                                            ? '<span class="badge text-bg-success-subtle text-success-emphasis">Active</span>'
                                            : '<span class="badge text-bg-secondary-subtle text-secondary-emphasis">Hidden</span>' ?>
                                    </td>
                                    <td data-col-priority="primary" class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_schedule' => (int)$s['Id']])) ?>#schedule-form" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <form method="post" action="/manage/venues" class="d-inline"
                                              onsubmit="return confirm('Delete this service time?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="schedule_delete">
                                            <input type="hidden" name="schedule_id" value="<?= (int)$s['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SCHEDULE ADD / EDIT FORM -->
            <?php if ($editSchedule !== null || ($_GET['edit_schedule'] ?? '') === 'new'): ?>
                <?php $es = $editSchedule ?? []; $isEditS = !empty($es); $esKind = $es['RecurrenceKind'] ?? 'weekly'; ?>
                <div class="card mb-4" id="schedule-form">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-<?= $isEditS ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $isEditS ? 'Edit service time' : 'Add a service time' ?>
                    </div>
                    <div class="card-body">
                        <form method="post" action="/manage/venues" class="row g-3" id="sched-form-el">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="schedule_save">
                            <input type="hidden" name="venue_id" value="<?= $selectedVenueId ?>">
                            <input type="hidden" name="schedule_id" value="<?= $isEditS ? (int)$es['Id'] : 0 ?>">

                            <div class="col-md-5">
                                <label class="form-label small fw-semibold" for="s-title">Service name</label>
                                <input type="text" id="s-title" name="title" class="form-control" maxlength="150"
                                       value="<?= htmlspecialchars($es['Title'] ?? 'Sunday Service') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold" for="s-kind">Repeats</label>
                                <select id="s-kind" name="recurrence_kind" class="form-select" onchange="venuesToggleRecurrence()">
                                    <?php foreach ($RECURRENCE_KINDS as $k => $label): ?>
                                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $esKind ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3" data-rec-field="day">
                                <label class="form-label small fw-semibold" for="s-dow">Day</label>
                                <select id="s-dow" name="day_of_week" class="form-select">
                                    <?php foreach ($DOW as $n => $dn): ?>
                                        <option value="<?= $n ?>" <?= (int)($es['DayOfWeek'] ?? 7) === $n ? 'selected' : '' ?>><?= htmlspecialchars($dn) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small fw-semibold" for="s-time">Start time</label>
                                <input type="time" id="s-time" name="start_time" class="form-control" required
                                       value="<?= htmlspecialchars(substr((string)($es['StartTime'] ?? '10:00:00'), 0, 5)) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold" for="s-dur">Duration (min)</label>
                                <input type="number" id="s-dur" name="duration_mins" class="form-control" min="1" max="1440"
                                       value="<?= (int)($es['DurationMins'] ?? 90) ?>">
                            </div>
                            <div class="col-md-3" data-rec-field="nth">
                                <label class="form-label small fw-semibold" for="s-nth">Which week</label>
                                <select id="s-nth" name="nth" class="form-select">
                                    <?php foreach ([1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth', -1 => 'Last'] as $nv => $nl): ?>
                                        <option value="<?= $nv ?>" <?= (int)($esKind === 'monthly_nth' ? ($editSchedRd['nth'] ?? 1) : 1) === $nv ? 'selected' : '' ?>><?= htmlspecialchars($nl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3" data-rec-field="oneoff">
                                <label class="form-label small fw-semibold" for="s-oneoff">Date</label>
                                <input type="date" id="s-oneoff" name="one_off_date" class="form-control"
                                       value="<?= htmlspecialchars((string)($editSchedRd['date'] ?? '')) ?>">
                            </div>

                            <div class="col-md-3" data-rec-field="anchor">
                                <label class="form-label small fw-semibold" for="s-anchor">Starting from</label>
                                <input type="date" id="s-anchor" name="anchor_date" class="form-control"
                                       value="<?= htmlspecialchars((string)($editSchedRd['anchor'] ?? '')) ?>">
                                <div class="form-text">Sets which fortnight is “week 1”.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold" for="s-until">Until (optional)</label>
                                <input type="date" id="s-until" name="until_date" class="form-control"
                                       value="<?= htmlspecialchars((string)($editSchedRd['until'] ?? '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold" for="s-exc">Skip dates (optional)</label>
                                <input type="text" id="s-exc" name="exceptions" class="form-control"
                                       value="<?= htmlspecialchars(implode(', ', $editSchedRd['exceptions'] ?? [])) ?>"
                                       placeholder="2026-12-25, 2027-01-01">
                                <div class="form-text">Comma-separated YYYY-MM-DD dates with no service.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold" for="s-tz">Timezone (override)</label>
                                <select id="s-tz" name="timezone" class="form-select">
                                    <option value="">Inherit venue (<?= htmlspecialchars($selectedVenueRow['TimeZone']) ?>)</option>
                                    <?php foreach ($TZ_LIST as $tzId): ?>
                                        <option value="<?= htmlspecialchars($tzId) ?>" <?= ($es['TimeZone'] ?? '') === $tzId ? 'selected' : '' ?>><?= htmlspecialchars($tzId) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="s-active" name="is_active" <?= !$isEditS || (int)($es['IsActive'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="s-active">Active</label>
                                </div>
                            </div>

                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-amber-solid"><i class="bi bi-check-lg me-1"></i><?= $isEditS ? 'Save service time' : 'Add service time' ?></button>
                                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_schedule' => null])) ?>">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; /* selectedVenueRow */ ?>
        <?php endif; /* selectedOrgId */ ?>
        <?php endif; /* schemaReady */ ?>

    </div>

    <?php if ($schemaReady): ?>
    <!-- Reuse the shared location autocomplete (#681) — same module organisations.php
         uses; resolves to a tblPlaces row and writes its Id into #v-place-id. -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
        (function () {
            if (window.iHymnsPlaceSearch) {
                var v = document.getElementById('v-place');
                var h = document.getElementById('v-place-id');
                if (v && h) { window.iHymnsPlaceSearch.attach(v, { hiddenIdInput: h }); }
            }
        })();
        // Show only the recurrence fields relevant to the chosen cadence.
        function venuesToggleRecurrence() {
            var kind = (document.getElementById('s-kind') || {}).value || 'weekly';
            var show = {
                weekly:      ['day'],
                fortnightly: ['day', 'anchor'],
                monthly_nth: ['day', 'nth'],
                one_off:     ['oneoff']
            }[kind] || ['day'];
            document.querySelectorAll('[data-rec-field]').forEach(function (el) {
                el.style.display = show.indexOf(el.getAttribute('data-rec-field')) === -1 ? 'none' : '';
            });
        }
        venuesToggleRecurrence();
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
