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
/* #1969 — the read half (table-existence probe, venue list, schedule list +
   RecurrenceData decode / effective-tz resolution) now lives in the shared
   includes/venue_admin.php core, reused by the new ?action=org_venues API
   endpoint (rule #22). This page's own POST write handlers are unchanged. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'venue_admin.php';

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
/* #1969 batch 3 (O4) — the vocabulary itself now lives ONCE in
   includes/venue_admin.php (IHYMNS_VENUE_RECURRENCE_KINDS), reused by the
   org_admin_schedule_save API action's own validation so the two can never
   list a different set of cadences (rule #20's "never a hard-coded list
   that already exists in a central map" applied to this page's own former
   local copy). */
$RECURRENCE_KINDS = IHYMNS_VENUE_RECURRENCE_KINDS;
$NTH_LABELS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', -1 => 'last'];
/* Cache the IANA tz list once (DateTimeZone::listIdentifiers is the canonical
   source — https://www.php.net/manual/en/datetimezone.listidentifiers.php). */
$TZ_LIST = \DateTimeZone::listIdentifiers();

/* Schema-presence guard. Migrations are NOT auto-applied on deploy, so the
   tables may not exist on this env yet ("it's in schema.sql" ≠ "it exists" —
   CLAUDE.md red flag). Probe INFORMATION_SCHEMA so a missing table renders a
   themed "run the migration" card instead of white-screening under STRICT.
   #1969 — now the shared venueAdminTablesExist() (includes/venue_admin.php),
   reused by the ?action=org_venues API action (rule #22). */
$schemaReady = venueAdminTablesExist($db);

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

            /* ---- Venue create / update ----
               #1969 batch 3 (O4) — delegates to the shared write core
               (includes/venue_admin.php), reused by the org_admin_venue_save
               API action (rule #22). Validation/defaults/SQL are unchanged;
               only the source of the input and the failure signal moved. */
            case 'venue_save': {
                $result = venueAdminSaveVenue($db, $_POST);
                logActivity(
                    $result['created'] ? 'venue.create' : 'venue.edit',
                    'organisation', (string)$result['orgId'],
                    ['venue_id' => $result['id'], 'name' => $result['name']]
                );
                $success = $result['created'] ? 'Venue added.' : 'Venue updated.';
                break;
            }

            /* ---- Venue delete (CASCADE removes its schedules) ---- */
            case 'venue_delete': {
                $venueId = (int)($_POST['venue_id'] ?? 0);
                $deleted = venueAdminDeleteVenue($db, $venueId);
                if ($deleted !== null) {
                    logActivity('venue.delete', 'organisation', (string)$deleted['orgId'], ['venue_id' => $venueId, 'name' => $deleted['name']]);
                    $success = 'Venue deleted.';
                }
                break;
            }

            /* ---- Service schedule create / update ---- */
            case 'schedule_save': {
                $result = venueAdminSaveSchedule($db, $_POST);
                logActivity(
                    $result['created'] ? 'venue.schedule.create' : 'venue.schedule.edit',
                    'organisation', (string)$result['orgId'],
                    ['schedule_id' => $result['id'], 'venue_id' => $result['venueId']]
                );
                $success = $result['created'] ? 'Service time added.' : 'Service time updated.';
                break;
            }

            /* ---- Schedule delete ---- */
            case 'schedule_delete': {
                $schedId = (int)($_POST['schedule_id'] ?? 0);
                $deleted = venueAdminDeleteSchedule($db, $schedId);
                if ($deleted !== null) {
                    logActivity('venue.schedule.delete', 'organisation', (string)$deleted['orgId'], ['schedule_id' => $schedId]);
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
            /* #1969 — shared core (includes/venue_admin.php); same columns,
               same ORDER BY, reused by the ?action=org_venues API action. */
            $venues = venueAdminListForOrg($db, $selectedOrgId);
        }

        // Confirm the selected venue belongs to the selected org, then load its schedules.
        $selectedVenue = null;
        foreach ($venues as $v) {
            if ((int)$v['Id'] === $selectedVenueId) { $selectedVenue = $v; break; }
        }
        if ($selectedVenue) {
            /* #1969 — shared core; decodes RecurrenceData + resolves the
               effective timezone identically to the pre-extraction inline
               loop below (byte-identical: `TimeZone ?? $fallbackTz`). */
            $schedules = venueAdminSchedulesForVenue($db, $selectedVenueId, (string)($selectedVenue['TimeZone'] ?? 'UTC'));
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

        <h1 class="h4 mb-2"><i aria-hidden="true" class="bi bi-geo-alt me-2"></i>Venues &amp; Service Times</h1>
        <p class="text-secondary small mb-4" style="max-width: 60ch;">
            Tell iHymns <strong>where</strong> your organisation meets and <strong>when</strong>.
            This is the foundation for <em>Service Mode</em> (letting a congregation follow the
            service on their own device). The map location &amp; radius are a convenience —
            attendance is confirmed by an on-screen code, not your location.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><i aria-hidden="true" class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i aria-hidden="true" class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="card border-warning">
                <div class="card-body">
                    <h2 class="h6 text-warning-emphasis"><i aria-hidden="true" class="bi bi-database-exclamation me-1"></i>Schema not yet migrated</h2>
                    <p class="small mb-2">
                        The <code>tblOrgVenues</code> / <code>tblOrgServiceSchedules</code> tables don't
                        exist on this environment yet. Migrations aren't applied automatically on deploy.
                    </p>
                    <a class="btn btn-sm btn-amber-solid" href="/manage/setup-database">
                        <i aria-hidden="true" class="bi bi-database-gear me-1"></i>Open Database Setup → run “Org Venues &amp; Service Schedules”
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
                <span class="fw-semibold"><i aria-hidden="true" class="bi bi-building me-1"></i>Venues</span>
                <a class="btn btn-sm btn-amber-solid" href="<?= htmlspecialchars(venuesUrl(['edit_venue' => 'new', 'venue' => null])) ?>#venue-form">
                    <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add venue
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!$venues): ?>
                    <p class="text-secondary small m-3 mb-3">No venues yet. Add the place(s) your organisation meets.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table-responsive cp-sortable align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" data-col-priority="primary" data-sort-key="venue" data-sort-type="text">Venue</th>
                                <th scope="col" data-col-priority="secondary" data-sort-key="location" data-sort-type="text">Location</th>
                                <th scope="col" data-col-priority="tertiary" data-sort-key="tz" data-sort-type="text">Timezone</th>
                                <th scope="col" data-col-priority="tertiary" data-sort-key="status" data-sort-type="text">Status</th>
                                <th scope="col" data-col-priority="primary" class="text-end">Actions</th>
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
                                <td data-col-priority="secondary" data-sort-value="<?= htmlspecialchars(implode(', ', $loc), ENT_QUOTES) ?>">
                                    <span class="small"><?= $loc ? htmlspecialchars(implode(', ', $loc)) : '<span class="text-secondary">—</span>' ?></span>
                                    <?php if ($hasCoords): ?>
                                        <span class="badge text-bg-light ms-1" title="Map pin set (convenience geofence<?= $v['RadiusMetres'] !== null ? ', radius ' . (int)$v['RadiusMetres'] . ' m' : '' ?>)" role="img" aria-label="Map pin set">
                                            <i class="bi bi-pin-map" aria-hidden="true"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="tertiary"><span class="small"><?= htmlspecialchars($v['TimeZone']) ?></span></td>
                                <td data-col-priority="tertiary" data-sort-value="<?= (int)$v['IsActive'] === 1 ? 'Active' : 'Hidden' ?>">
                                    <?php if ((int)$v['IsActive'] === 1): ?>
                                        <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary" class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(venuesUrl(['venue' => (int)$v['Id'], 'edit_venue' => null, 'edit_schedule' => null])) ?>#schedules" title="Manage service times">
                                        <i aria-hidden="true" class="bi bi-clock-history"></i><span class="d-none d-md-inline ms-1">Service times</span>
                                    </a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_venue' => (int)$v['Id'], 'venue' => null])) ?>#venue-form" title="Edit venue"
                                       aria-label="Edit venue <?= htmlspecialchars($v['Name'], ENT_QUOTES) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form method="post" action="/manage/venues" class="d-inline"
                                          onsubmit="return confirm('Delete venue “<?= htmlspecialchars(addslashes($v['Name'])) ?>” and all its service times?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="venue_delete">
                                        <input type="hidden" name="org_id" value="<?= $selectedOrgId ?>">
                                        <input type="hidden" name="venue_id" value="<?= (int)$v['Id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete venue"
                                                aria-label="Delete venue <?= htmlspecialchars($v['Name'], ENT_QUOTES) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
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
                    <i aria-hidden="true" class="bi bi-<?= $isEditV ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $isEditV ? 'Edit venue' : 'Add a venue' ?>
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
                            <button type="submit" class="btn btn-amber-solid"><i aria-hidden="true" class="bi bi-check-lg me-1"></i><?= $isEditV ? 'Save venue' : 'Add venue' ?></button>
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
                    <span class="fw-semibold"><i aria-hidden="true" class="bi bi-calendar-week me-1"></i>Service times — <?= htmlspecialchars($selectedVenueRow['Name']) ?></span>
                    <a class="btn btn-sm btn-amber-solid" href="<?= htmlspecialchars(venuesUrl(['edit_schedule' => 'new'])) ?>#schedule-form">
                        <i aria-hidden="true" class="bi bi-plus-lg me-1"></i>Add service time
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$schedules): ?>
                        <p class="text-secondary small m-3 mb-3">No service times yet for this venue.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table admin-table-responsive cp-sortable align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" data-col-priority="primary" data-sort-key="service" data-sort-type="text">Service</th>
                                    <th scope="col" data-col-priority="primary" data-sort-key="when" data-sort-type="text">When</th>
                                    <th scope="col" data-col-priority="secondary" data-sort-key="next" data-sort-type="date">Next dates</th>
                                    <th scope="col" data-col-priority="tertiary" data-sort-key="status" data-sort-type="text">Status</th>
                                    <th scope="col" data-col-priority="primary" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schedules as $s): ?>
                                <?php $next = venueNextOccurrences($s, 3); ?>
                                <tr>
                                    <td data-col-priority="primary"><span class="fw-semibold"><?= htmlspecialchars($s['Title']) ?></span></td>
                                    <td data-col-priority="primary"><span class="small"><?= htmlspecialchars(venueScheduleSummary($s, $DOW, $NTH_LABELS)) ?></span></td>
                                    <td data-col-priority="secondary" data-sort-value="<?= htmlspecialchars($next[0] ?? '', ENT_QUOTES) ?>">
                                        <?php if ($next): ?>
                                            <span class="small text-secondary"><?= htmlspecialchars(implode(' · ', array_map(static fn($d) => substr($d, 0, 16), $next))) ?></span>
                                        <?php else: ?>
                                            <span class="small text-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-col-priority="tertiary" data-sort-value="<?= (int)$s['IsActive'] === 1 ? 'Active' : 'Hidden' ?>">
                                        <?= (int)$s['IsActive'] === 1
                                            ? '<span class="badge text-bg-success-subtle text-success-emphasis">Active</span>'
                                            : '<span class="badge text-bg-secondary-subtle text-secondary-emphasis">Hidden</span>' ?>
                                    </td>
                                    <td data-col-priority="primary" class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(venuesUrl(['edit_schedule' => (int)$s['Id']])) ?>#schedule-form" title="Edit"
                                           aria-label="Edit service time <?= htmlspecialchars($s['Title'], ENT_QUOTES) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        <form method="post" action="/manage/venues" class="d-inline"
                                              onsubmit="return confirm('Delete this service time?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="schedule_delete">
                                            <input type="hidden" name="schedule_id" value="<?= (int)$s['Id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                                    aria-label="Delete service time <?= htmlspecialchars($s['Title'], ENT_QUOTES) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
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
                        <i aria-hidden="true" class="bi bi-<?= $isEditS ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $isEditS ? 'Edit service time' : 'Add a service time' ?>
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
                                <button type="submit" class="btn btn-amber-solid"><i aria-hidden="true" class="bi bi-check-lg me-1"></i><?= $isEditS ? 'Save service time' : 'Add service time' ?></button>
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

    <!-- Sortable table headers (#1786 sweep). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
