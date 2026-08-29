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

/* #1995 — the guided "Live Service setup" wizard (HYBRID shape, owner-
   confirmed D1) reuses the shared stepper (js/modules/admin-wizard.js,
   #1992) to walk a curator through: choose Live Follow vs Service Mode
   (rule #26 — the two features are commonly confused), then, for Service
   Mode, create/reuse a venue, optionally its regular service time, and
   optionally mint a presentation-app driver key. It orchestrates the
   THREE EXISTING API actions (org_admin_venue_save / org_admin_schedule_
   save / service_driver_key_mint, api.php) client-side — ZERO new server
   endpoints (rule #22 — the #1969 write core + its API twins already
   exist; this page's own venue_save/schedule_save/venue_delete/
   schedule_delete POST handlers below are UNTOUCHED). It does NOT start a
   live session itself — the DONE pane links out to the existing consoles
   (/manage/service-projection, /manage/service-lead) for that.
   includes/service_driver_keys.php gives the driver-key protocol
   vocabulary + its own table-existence probe, mirroring the identical
   probe manage/service-projection.php's own driver-key card already
   runs — #1770 C1 is a migration separate from the venue tables above, so
   an install can be $schemaReady here yet still pre-#1770. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'service_driver_keys.php';
$driverKeysReady = serviceDriverKeysTableExists($db);
/* Cache-busted import path for the shared stepper module (#1992/#1993),
   same filemtime-as-version-query pattern head-libs.php uses for every
   other admin JS load. */
$_adminWizardPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'admin-wizard.js';
$adminWizardVer   = is_file($_adminWizardPath) ? (string)filemtime($_adminWizardPath) : '1';

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

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
                <h1 class="h4 mb-2"><i aria-hidden="true" class="bi bi-geo-alt me-2"></i>Venues &amp; Service Times</h1>
                <p class="text-secondary small mb-0" style="max-width: 60ch;">
                    Tell iHymns <strong>where</strong> your organisation meets and <strong>when</strong>.
                    This is the foundation for <em>Service Mode</em> (letting a congregation follow the
                    service on their own device). The map location &amp; radius are a convenience —
                    attendance is confirmed by an on-screen code, not your location.
                </p>
            </div>
            <?php /* #1995 — the guided-wizard trigger. Gated on the SAME
                     condition as the modal + its wiring further down this
                     page (rule: nothing here can run against an org list
                     that doesn't exist yet), never rendered when there is
                     no organisation to attach a venue to. */ ?>
            <?php if ($schemaReady && $orgs): ?>
                <button type="button" class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#svcWizardModal">
                    <i aria-hidden="true" class="bi bi-magic me-1"></i>Live Service setup (guided)
                </button>
            <?php endif; ?>
        </div>

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

    <?php if ($schemaReady && $orgs): ?>
    <?php /* #1995 — Live Service setup wizard: modal + wiring. BEGIN
             Guided, step-by-step alternative to the manual venue/schedule
             forms above (rule: additive — those forms + their POST
             handlers are byte-identical, untouched by this block). Built
             on the shared stepper (js/modules/admin-wizard.js, #1992) —
             see external-link-types.php (#1992) / songbooks.php (#1993)
             for the sibling consumers this mirrors. onFinish orchestrates,
             client-side and sequentially, THREE EXISTING api.php actions —
             never a new server endpoint (rule #22): org_admin_venue_save,
             then org_admin_schedule_save (skipped if the curator ticks
             "ad-hoc"), then service_driver_key_mint (skipped unless the
             curator opts in). It does not itself start a live session —
             the DONE pane links out to /manage/service-projection and
             /manage/service-lead for that. */ ?>
    <script>
        window._iHymnsServiceWizard = {
            csrf: <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            driverKeysReady: <?= $driverKeysReady ? 'true' : 'false' ?>
        };
    </script>

    <div class="modal fade" id="svcWizardModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="svcWizardRoot">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0">Live Service setup — guided</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="svcwiz-steps-wrap">
                        <div data-wiz-progress class="mb-3"></div>

                        <section data-wiz-step data-wiz-label="Mode">
                            <h3 data-wiz-heading class="h6 mb-3">1. Which kind of live session?</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <div class="mb-2">
                                <label class="form-label" for="svcwiz-mode">Live mode</label>
                                <select class="form-select" id="svcwiz-mode">
                                    <option value="service" selected>Service Mode — a venue-wide join code for your congregation</option>
                                    <option value="quick">Quick Live Follow — no setup, start from any song</option>
                                </select>
                            </div>
                            <p class="form-text small mb-0">
                                <strong>Service Mode</strong> is for a venue's regular service: set up a venue and
                                (usually) a weekly time in the next few steps, then project a join code your whole
                                congregation can scan or type in. <strong>Quick Live Follow</strong> needs nothing
                                here — any signed-in leader taps <strong>Go Live</strong> on a song page to start a
                                one-off session immediately.
                            </p>
                        </section>

                        <section data-wiz-step data-wiz-label="Venue" hidden>
                            <h3 data-wiz-heading class="h6 mb-3">2. Where do you meet?</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <div class="mb-3">
                                <label class="form-label" for="svcwiz-org">Organisation</label>
                                <select class="form-select" id="svcwiz-org">
                                    <?php foreach ($orgs as $o): ?>
                                        <option value="<?= (int)$o['Id'] ?>" <?= (int)$o['Id'] === $selectedOrgId ? 'selected' : '' ?>><?= htmlspecialchars($o['Name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="svcwiz-venue-name">Venue name</label>
                                <input type="text" class="form-control" id="svcwiz-venue-name" maxlength="150" placeholder="e.g. Main Sanctuary">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="svcwiz-place">Find location (optional)</label>
                                <input type="text" class="form-control" id="svcwiz-place" autocomplete="off" placeholder="Type an address or town to drop a map pin…">
                                <input type="hidden" id="svcwiz-place-id" value="">
                                <div class="form-text small">Sets the map pin — a convenience only, not the attendance check.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="svcwiz-tz">Timezone</label>
                                <select class="form-select" id="svcwiz-tz">
                                    <?php foreach ($TZ_LIST as $tzId): ?>
                                        <option value="<?= htmlspecialchars($tzId) ?>" <?= $tzId === 'Europe/London' ? 'selected' : '' ?>><?= htmlspecialchars($tzId) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </section>

                        <section data-wiz-step data-wiz-label="Service time" hidden>
                            <h3 data-wiz-heading class="h6 mb-3">3. When do you meet?</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="svcwiz-sched-skip">
                                <label class="form-check-label" for="svcwiz-sched-skip">Skip — we meet ad-hoc, not on a regular schedule</label>
                            </div>
                            <div id="svcwiz-sched-fields">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="svcwiz-sched-title">Service name</label>
                                        <input type="text" class="form-control" id="svcwiz-sched-title" maxlength="150" value="Sunday Service">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="svcwiz-sched-kind">Repeats</label>
                                        <select class="form-select" id="svcwiz-sched-kind">
                                            <?php foreach ($RECURRENCE_KINDS as $k => $label): ?>
                                                <option value="<?= htmlspecialchars($k) ?>" <?= $k === 'weekly' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4" data-svcwiz-rec-field="day">
                                        <label class="form-label" for="svcwiz-sched-dow">Day</label>
                                        <select class="form-select" id="svcwiz-sched-dow">
                                            <?php foreach ($DOW as $n => $dn): ?>
                                                <option value="<?= $n ?>" <?= $n === 7 ? 'selected' : '' ?>><?= htmlspecialchars($dn) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="svcwiz-sched-time">Start time</label>
                                        <input type="time" class="form-control" id="svcwiz-sched-time" value="10:00">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="svcwiz-sched-dur">Duration (min)</label>
                                        <input type="number" class="form-control" id="svcwiz-sched-dur" min="1" max="1440" value="90">
                                    </div>
                                </div>
                                <div class="row g-3" data-svcwiz-rec-field="nth">
                                    <div class="col-md-4">
                                        <label class="form-label" for="svcwiz-sched-nth">Which week</label>
                                        <select class="form-select" id="svcwiz-sched-nth">
                                            <?php foreach ($NTH_LABELS as $nv => $nl): ?>
                                                <option value="<?= $nv ?>" <?= $nv === 1 ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($nl)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3" data-svcwiz-rec-field="oneoff">
                                    <div class="col-md-4">
                                        <label class="form-label" for="svcwiz-sched-oneoff">Date</label>
                                        <input type="date" class="form-control" id="svcwiz-sched-oneoff">
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section data-wiz-step data-wiz-label="Presentation app" hidden>
                            <h3 data-wiz-heading class="h6 mb-3">4. Presentation-app control <span class="text-muted small">(optional)</span></h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <p class="text-secondary small">
                                A driver key lets an external presentation app (ProPresenter, a Stream Deck
                                script, a Companion webhook) advance the song and section on its own, without a
                                person clicking here. Skip this if you'll drive the service by hand — you can
                                always mint a key later from the Projector Screen.
                            </p>
                            <?php if (!$driverKeysReady): ?>
                                <div class="alert alert-warning small mb-0">
                                    <i aria-hidden="true" class="bi bi-database-exclamation me-1"></i>Driver keys
                                    aren't migrated on this environment yet — the venue and service time above still
                                    save fine; mint a key later from the Projector Screen once this is set up.
                                </div>
                            <?php else: ?>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="svcwiz-dk-optin">
                                    <label class="form-check-label" for="svcwiz-dk-optin">Set up a presentation-app driver key now</label>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="svcwiz-dk-label">Label</label>
                                        <input type="text" class="form-control" id="svcwiz-dk-label" maxlength="120" placeholder="e.g. Sanctuary ProPresenter" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="svcwiz-dk-protocol">Protocol</label>
                                        <select class="form-select" id="svcwiz-dk-protocol" disabled>
                                            <?php foreach (SERVICE_DRIVER_KEY_PROTOCOLS as $proto): ?>
                                                <option value="<?= htmlspecialchars($proto) ?>"><?= htmlspecialchars(ucfirst($proto)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section data-wiz-step data-wiz-label="Review" hidden>
                            <h3 data-wiz-heading class="h6 mb-3">5. Review &amp; create</h3>
                            <div role="alert" data-wiz-alert class="alert alert-danger py-2" hidden></div>
                            <dl class="row small mb-0" id="svcwiz-review-summary"></dl>
                        </section>
                    </div>

                    <div id="svcwiz-done" hidden>
                        <h3 tabindex="-1" id="svcwiz-done-heading" class="h6 mb-3">Live Service is set up</h3>
                        <div id="svcwiz-done-body" class="small"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-wiz-back hidden>Back</button>
                    <button type="button" class="btn btn-amber" data-wiz-next>Next</button>
                    <button type="button" class="btn btn-amber" id="svcwiz-done-close" data-bs-dismiss="modal" hidden>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
    /* #1995 — guided "Live Service setup" wizard wiring, built on the
       shared stepper (js/modules/admin-wizard.js). Domain logic only — the
       framework itself knows nothing about venues/schedules/driver keys
       (module doc-block). Mirrors manage/external-link-types.php's (#1992)
       / manage/songbooks.php's (#1993) wizard shape; the ONE difference is
       that onFinish here calls THREE existing api.php actions in sequence
       (bare fetch + X-Requested-With + credentials:'same-origin' — the
       /manage house pattern; js/utils/api-client.js is PWA-only, rule
       #31) rather than one page-local AJAX case. */
    import { createWizard } from '/js/modules/admin-wizard.js?v=<?= htmlspecialchars($adminWizardVer, ENT_QUOTES) ?>';

    (function () {
        'use strict';
        const modalEl = document.getElementById('svcWizardModal');
        if (!modalEl) { return; }

        const seed = window._iHymnsServiceWizard || {};
        const csrfToken = seed.csrf || '';
        const dkReady = !!seed.driverKeysReady;

        const stepsWrap    = document.getElementById('svcwiz-steps-wrap');
        const doneEl       = document.getElementById('svcwiz-done');
        const doneBodyEl   = document.getElementById('svcwiz-done-body');
        const nextBtn      = modalEl.querySelector('[data-wiz-next]');
        const backBtn      = modalEl.querySelector('[data-wiz-back]');
        const doneCloseBtn = document.getElementById('svcwiz-done-close');

        const modeSelect      = document.getElementById('svcwiz-mode');
        const orgSelect        = document.getElementById('svcwiz-org');
        const venueNameInput   = document.getElementById('svcwiz-venue-name');
        const placeInput       = document.getElementById('svcwiz-place');
        const placeIdInput     = document.getElementById('svcwiz-place-id');
        const tzSelect          = document.getElementById('svcwiz-tz');

        const schedSkipEl      = document.getElementById('svcwiz-sched-skip');
        const schedFieldsWrap  = document.getElementById('svcwiz-sched-fields');
        const schedTitleInput  = document.getElementById('svcwiz-sched-title');
        const schedKindSelect  = document.getElementById('svcwiz-sched-kind');
        const schedDowSelect   = document.getElementById('svcwiz-sched-dow');
        const schedTimeInput   = document.getElementById('svcwiz-sched-time');
        const schedDurInput    = document.getElementById('svcwiz-sched-dur');
        const schedNthSelect   = document.getElementById('svcwiz-sched-nth');
        const schedOneoffInput = document.getElementById('svcwiz-sched-oneoff');

        const dkOptinEl    = document.getElementById('svcwiz-dk-optin');
        const dkLabelInput = document.getElementById('svcwiz-dk-label');
        const dkProtocolSel = document.getElementById('svcwiz-dk-protocol');

        const reviewSummary = document.getElementById('svcwiz-review-summary');

        const orgDefaultValue = orgSelect ? orgSelect.value : '';

        const state = { venueId: 0, scheduleId: 0, orgId: 0 };

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        /* ---- modal-scoped recurrence-kind show/hide — DELIBERATELY a
           SEPARATE function + data attribute (data-svcwiz-rec-field, never
           this page's own data-rec-field) from the manual schedule form's
           global venuesToggleRecurrence(), which the manual form's edit
           links still call unmodified (additive only). Querying only
           inside modalEl also means this never touches the manual form's
           own [data-rec-field] elements, and vice versa. */
        function svcwizToggleRecurrence() {
            const kind = schedKindSelect ? schedKindSelect.value : 'weekly';
            const show = {
                weekly:      ['day'],
                fortnightly: ['day'],
                monthly_nth: ['day', 'nth'],
                one_off:     ['oneoff'],
            }[kind] || ['day'];
            modalEl.querySelectorAll('[data-svcwiz-rec-field]').forEach(function (el) {
                el.style.display = show.indexOf(el.getAttribute('data-svcwiz-rec-field')) === -1 ? 'none' : '';
            });
        }
        if (schedKindSelect) { schedKindSelect.addEventListener('change', svcwizToggleRecurrence); }
        svcwizToggleRecurrence();

        function svcwizToggleSchedSkip() {
            if (schedFieldsWrap) { schedFieldsWrap.hidden = !!(schedSkipEl && schedSkipEl.checked); }
        }
        if (schedSkipEl) { schedSkipEl.addEventListener('change', svcwizToggleSchedSkip); }

        if (dkOptinEl) {
            dkOptinEl.addEventListener('change', function () {
                const on = dkOptinEl.checked;
                if (dkLabelInput) { dkLabelInput.disabled = !on; }
                if (dkProtocolSel) { dkProtocolSel.disabled = !on; }
            });
        }

        /* ---- location typeahead — the SAME shared module + attach shape
           the manual venue form above already uses; place-search.js is
           already loaded on this page by that block, never re-loaded
           here. */
        if (window.iHymnsPlaceSearch && placeInput && placeIdInput) {
            window.iHymnsPlaceSearch.attach(placeInput, { hiddenIdInput: placeIdInput });
        }

        /* ---- review ------------------------------------------------- */
        function updateReview() {
            if (!reviewSummary) { return; }
            const orgLabel = orgSelect && orgSelect.selectedOptions[0] ? orgSelect.selectedOptions[0].textContent : '';
            let rows = '';
            rows += '<dt class="col-sm-4">Organisation</dt><dd class="col-sm-8">' + escapeHtml(orgLabel) + '</dd>';
            rows += '<dt class="col-sm-4">Venue</dt><dd class="col-sm-8">' + escapeHtml(venueNameInput.value.trim()) + '</dd>';
            rows += '<dt class="col-sm-4">Timezone</dt><dd class="col-sm-8">' + escapeHtml(tzSelect.value) + '</dd>';
            if (schedSkipEl && schedSkipEl.checked) {
                rows += '<dt class="col-sm-4">Service time</dt><dd class="col-sm-8">Skipped — ad-hoc</dd>';
            } else {
                rows += '<dt class="col-sm-4">Service time</dt><dd class="col-sm-8">' + escapeHtml(schedTitleInput.value.trim() || 'Sunday Service') + '</dd>';
            }
            if (dkOptinEl && dkOptinEl.checked) {
                rows += '<dt class="col-sm-4">Driver key</dt><dd class="col-sm-8">' + escapeHtml(dkLabelInput.value.trim() || '(unnamed)') + '</dd>';
            }
            reviewSummary.innerHTML = rows;
        }

        function showStepError(index, message) {
            const panes = modalEl.querySelectorAll('[data-wiz-step]');
            const pane = panes[index];
            if (!pane) { return; }
            const alertEl = pane.querySelector('[data-wiz-alert]');
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = message;
                alertEl.focus();
            }
        }
        function clearAllStepAlerts() {
            modalEl.querySelectorAll('[data-wiz-alert]').forEach(function (el) { el.hidden = true; el.textContent = ''; });
        }

        const LAST_STEP = modalEl.querySelectorAll('[data-wiz-step]').length - 1;

        /* ---- the wizard itself ----------------------------------------- */
        const wizard = createWizard(modalEl, {
            host: 'bootstrap-modal',
            validateStep: function (index) {
                if (index === 0) {
                    if (modeSelect && modeSelect.value === 'quick') {
                        return 'Quick Live Follow needs no setup here — open any song and tap Go Live. '
                            + 'Choose Service Mode above to keep going, or close this wizard.';
                    }
                    return true;
                }
                if (index === 1) {
                    if (!(orgSelect && parseInt(orgSelect.value, 10) > 0)) {
                        return { ok: false, message: 'Choose an organisation.', focus: orgSelect };
                    }
                    if (!venueNameInput.value.trim()) {
                        return { ok: false, message: 'Venue name is required.', focus: venueNameInput };
                    }
                    return true;
                }
                if (index === 2) {
                    if (schedSkipEl && schedSkipEl.checked) { return true; }
                    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(schedTimeInput.value || '')) {
                        return { ok: false, message: 'Enter a valid start time.', focus: schedTimeInput };
                    }
                    const kind = schedKindSelect.value;
                    if (kind === 'one_off') {
                        if (!/^\d{4}-\d{2}-\d{2}$/.test(schedOneoffInput.value || '')) {
                            return { ok: false, message: 'Enter the one-off date.', focus: schedOneoffInput };
                        }
                    } else {
                        const dow = parseInt(schedDowSelect.value, 10);
                        if (!(dow >= 1 && dow <= 7)) {
                            return { ok: false, message: 'Choose a day of the week.', focus: schedDowSelect };
                        }
                    }
                    return true;
                }
                if (index === 3) {
                    if (dkOptinEl && dkOptinEl.checked && !dkLabelInput.value.trim()) {
                        return { ok: false, message: 'Give the driver key a label.', focus: dkLabelInput };
                    }
                    return true;
                }
                if (index === 4) {
                    updateReview();
                    return true;
                }
                return true;
            },
            onStepChange: function (from, to) {
                if (nextBtn) { nextBtn.textContent = (to === LAST_STEP) ? 'Create' : 'Next'; }
                if (to === LAST_STEP) { updateReview(); }
            },
            onFinish: save,
        });

        /* ---- transport — bare fetch, the /manage house pattern (rule
           #31 is PWA-only; js/utils/api-client.js is not consumed here),
           same shape as manage/service-projection.php's own apiCall(). */
        function svcwizApiCall(action, body) {
            return fetch('/api?action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body || {}),
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    return { status: res.status, data: data };
                });
            });
        }

        /* venue_id is ALWAYS the held state.venueId (0 the first time) —
           org_admin_venue_save treats a positive venue_id as an UPDATE, so
           a retry after a LATER step fails never mints a second venue row
           (there is no name-uniqueness constraint to lean on instead). */
        function doVenueSave() {
            const body = {
                venue_id: state.venueId || 0,
                org_id: parseInt(orgSelect.value, 10) || 0,
                name: venueNameInput.value.trim(),
                timezone: tzSelect.value,
                is_active: 1,
                csrf_token: csrfToken,
            };
            if (placeIdInput.value) { body.place_id = parseInt(placeIdInput.value, 10); }
            return svcwizApiCall('org_admin_venue_save', body).then(function (result) {
                if (result.status === 200 && result.data && result.data.ok && result.data.venue) {
                    return result.data.venue;
                }
                const err = new Error((result.data && result.data.error) || 'Could not save the venue.');
                err.step = 'venue';
                throw err;
            });
        }

        /* schedule_id is likewise held in state — same retry-safety shape. */
        function doScheduleSave(venueId) {
            const kind = schedKindSelect.value;
            const body = {
                schedule_id: state.scheduleId || 0,
                venue_id: venueId,
                title: schedTitleInput.value.trim() || 'Sunday Service',
                recurrence_kind: kind,
                start_time: schedTimeInput.value,
                duration_mins: parseInt(schedDurInput.value, 10) || 90,
                is_active: 1,
                csrf_token: csrfToken,
            };
            if (kind === 'one_off') {
                body.one_off_date = schedOneoffInput.value;
            } else {
                body.day_of_week = parseInt(schedDowSelect.value, 10) || 7;
                if (kind === 'monthly_nth') { body.nth = parseInt(schedNthSelect.value, 10) || 1; }
            }
            return svcwizApiCall('org_admin_schedule_save', body).then(function (result) {
                if (result.status === 200 && result.data && result.data.ok && result.data.schedule) {
                    return result.data.schedule;
                }
                const err = new Error((result.data && result.data.error) || 'Could not save the service time.');
                err.step = 'schedule';
                throw err;
            });
        }

        /* Driver-key mint is the ONE non-fatal leg — it NEVER rejects. A
           failure here must never undo the venue/schedule that already
           saved; the DONE pane reports it and points at the Projector
           Screen to mint later instead. */
        function maybeMintDriverKey(venueId, orgId) {
            if (!dkReady || !dkOptinEl || !dkOptinEl.checked) { return Promise.resolve(null); }
            const label = dkLabelInput.value.trim();
            if (!label) { return Promise.resolve(null); }
            const body = {
                orgId: orgId,
                venueId: venueId,
                label: label,
                protocol: dkProtocolSel ? dkProtocolSel.value : 'generic',
                csrf_token: csrfToken,
            };
            return svcwizApiCall('service_driver_key_mint', body).then(function (result) {
                if (result.status === 200 && result.data && result.data.ok) {
                    return { minted: true, key: result.data.key, prefix: result.data.prefix };
                }
                return { minted: false, error: (result.data && result.data.error) || 'Could not mint a driver key.' };
            }).catch(function () {
                return { minted: false, error: 'Could not reach the server to mint a driver key.' };
            });
        }

        function routeSaveError(err) {
            const msg = (err && err.message) || 'Something went wrong. Please try again.';
            if (err && err.step === 'venue') {
                wizard.goTo(1);
                showStepError(1, msg);
            } else if (err && err.step === 'schedule') {
                wizard.goTo(2);
                showStepError(2, msg);
            } else {
                window.alert(msg);
            }
        }

        function showDonePane(info) {
            if (stepsWrap) { stepsWrap.hidden = true; }
            if (doneEl) { doneEl.hidden = false; }
            if (backBtn) { backBtn.hidden = true; }
            if (nextBtn) { nextBtn.hidden = true; }
            if (doneCloseBtn) { doneCloseBtn.hidden = false; }

            let html = '';
            html += '<p><i aria-hidden="true" class="bi bi-check-circle-fill text-success me-1"></i>Venue <strong>'
                + escapeHtml(info.venueName) + '</strong> is set up.</p>';
            if (info.scheduleSkipped) {
                html += '<p>No regular service time saved — add one any time from this page when you have one.</p>';
            } else {
                html += '<p>Service time <strong>' + escapeHtml(info.scheduleTitle) + '</strong> saved.</p>';
            }
            if (info.mintOutcome && info.mintOutcome.minted) {
                /* Show-once (mirrors manage/service-projection.php's own
                   driver-key mint card): the raw key is never stored,
                   only ever displayed here, and is not sent anywhere
                   again. */
                html += '<div class="alert alert-warning py-2 px-3 mb-3">'
                    + '<strong>Copy this driver key now — it will not be shown again:</strong><br>'
                    + '<code style="user-select:all;" id="svcwiz-done-key">' + escapeHtml(info.mintOutcome.key) + '</code> '
                    + '<button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="svcwiz-copy-key">Copy</button>'
                    + '<div class="form-text small mb-0">Lost it? Revoke it and mint a new one any time on the Projector Screen.</div>'
                    + '</div>';
            } else if (info.mintOutcome && info.mintOutcome.minted === false) {
                html += '<div class="alert alert-secondary py-2 px-3 mb-3">Driver key not minted — '
                    + escapeHtml(info.mintOutcome.error) + ' Mint one later on the Projector Screen.</div>';
            }
            html += '<p class="mb-2"><a href="/manage/service-projection">Open the Projector Screen</a> to start a '
                + 'live service, or <a href="/manage/service-lead">Connect &amp; drive</a> from a leader’s device.</p>';
            html += '<p class="text-secondary small mb-0">This works right now. A congregant’s copyrighted-lyrics '
                + 'unlock during a service is a separate, dormant setting — it needs Content Gating turned on and a '
                + 'CCLI licence restriction configured first.</p>';

            if (doneBodyEl) { doneBodyEl.innerHTML = html; }
            const copyBtn = document.getElementById('svcwiz-copy-key');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    const codeEl = document.getElementById('svcwiz-done-key');
                    if (codeEl && navigator.clipboard) { navigator.clipboard.writeText(codeEl.textContent).catch(function () {}); }
                });
            }
            const heading = document.getElementById('svcwiz-done-heading');
            if (heading) { heading.focus(); }
        }

        function save() {
            if (nextBtn) { nextBtn.disabled = true; }
            clearAllStepAlerts();
            const scheduleSkipped = !!(schedSkipEl && schedSkipEl.checked);
            const scheduleTitle = schedTitleInput.value.trim() || 'Sunday Service';
            const venueName = venueNameInput.value.trim();

            doVenueSave()
                .then(function (venue) {
                    state.venueId = venue.id;
                    state.orgId = venue.orgId;
                    if (scheduleSkipped) { return null; }
                    return doScheduleSave(venue.id);
                })
                .then(function (schedule) {
                    state.scheduleId = schedule ? schedule.id : 0;
                    return maybeMintDriverKey(state.venueId, state.orgId);
                })
                .then(function (mintOutcome) {
                    showDonePane({
                        venueName: venueName,
                        scheduleSkipped: scheduleSkipped,
                        scheduleTitle: scheduleTitle,
                        mintOutcome: mintOutcome,
                    });
                })
                .catch(function (err) { routeSaveError(err); })
                .finally(function () { if (nextBtn) { nextBtn.disabled = false; } });
        }

        /* Reset to a clean slate every time the modal is opened again —
           including collapsing the DONE pane back to the stepper (a
           reopened wizard always starts a FRESH create, never resumes
           the previous run's in-memory ids). */
        modalEl.addEventListener('hidden.bs.modal', function () {
            clearAllStepAlerts();
            if (modeSelect) { modeSelect.value = 'service'; }
            if (orgSelect) { orgSelect.value = orgDefaultValue; }
            venueNameInput.value = '';
            placeInput.value = '';
            placeIdInput.value = '';
            tzSelect.value = 'Europe/London';
            if (schedSkipEl) { schedSkipEl.checked = false; }
            schedTitleInput.value = 'Sunday Service';
            schedKindSelect.value = 'weekly';
            schedDowSelect.value = '7';
            schedTimeInput.value = '10:00';
            schedDurInput.value = '90';
            schedNthSelect.value = '1';
            schedOneoffInput.value = '';
            svcwizToggleRecurrence();
            svcwizToggleSchedSkip();
            if (dkOptinEl) { dkOptinEl.checked = false; }
            if (dkLabelInput) { dkLabelInput.value = ''; dkLabelInput.disabled = true; }
            if (dkProtocolSel) { dkProtocolSel.disabled = true; }
            state.venueId = 0; state.scheduleId = 0; state.orgId = 0;
            if (stepsWrap) { stepsWrap.hidden = false; }
            if (doneEl) { doneEl.hidden = true; }
            if (backBtn) { backBtn.hidden = true; }
            if (nextBtn) { nextBtn.hidden = false; nextBtn.textContent = 'Next'; nextBtn.disabled = false; }
            if (doneCloseBtn) { doneCloseBtn.hidden = true; }
            wizard.goTo(0);
        });
    })();
    </script>
    <?php /* #1995 — Live Service setup wizard: modal + wiring. END */ ?>
    <?php endif; ?>


    <!-- Sortable table headers (#1786 sweep). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
