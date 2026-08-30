<?php
/**
 * test-security-schedule-idor.php — regression guard for the cross-tenant
 * IDOR fixed on 2026-08-29 (redo security audit finding F1).
 *
 * The `org_admin_schedule_save` API action, on an UPDATE (schedule_id > 0),
 * must re-check the caller against the EXISTING schedule row's current owning
 * org — not only the org derived from the posted venue_id — or an admin of
 * org A could re-parent org B's schedule onto their own venue.
 * `org_admin_venue_save` already guards its own existing-row case; this test
 * pins the same discipline onto schedule_save so the fix cannot silently
 * regress (rule #34: tree-derived + mutation-proven).
 *
 * DB-free: it reads the api.php source and asserts the guard is present and
 * ordered BEFORE the write core call. Mutation-proven: deleting the
 * `venueAdminGetSchedule($db, $existingScheduleId)` re-check makes this RED.
 */

declare(strict_types=1);

$apiPath = dirname(__DIR__, 2) . '/appWeb/public_html/api.php';
$src = file_get_contents($apiPath);
if ($src === false) {
    fwrite(STDERR, "FAIL: cannot read api.php\n");
    exit(1);
}

$fails = 0;
$check = static function (bool $ok, string $msg) use (&$fails): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $msg . "\n";
    if (!$ok) { $fails++; }
};

/* Isolate the org_admin_schedule_save case body (up to the next `case '`). */
$startTok = "case 'org_admin_schedule_save':";
$start = strpos($src, $startTok);
$check($start !== false, "found the org_admin_schedule_save case");
$body = '';
if ($start !== false) {
    $next = strpos($src, "case '", $start + strlen($startTok));
    $body = substr($src, $start, ($next !== false ? $next - $start : 4000));
}

/* The existing-schedule ownership re-check must be present. */
$hasExistingLookup = (bool)preg_match(
    '/venueAdminGetSchedule\s*\(\s*\$db\s*,\s*\$existingScheduleId\s*\)/',
    $body
);
$check($hasExistingLookup, "re-loads the EXISTING schedule by its own id (venueAdminGetSchedule(\$db, \$existingScheduleId))");

$hasExistingOrgCheck = (bool)preg_match(
    '/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$existingSchedule\[[\'"]OrgId[\'"]\]\s*\)/',
    $body
);
$check($hasExistingOrgCheck, "re-checks userCanActOnOrg against the EXISTING schedule's OrgId");

/* And it must happen BEFORE the write core runs (order matters — a check
   after the UPDATE is useless). */
$posExisting = strpos($body, '$existingSchedule = venueAdminGetSchedule');
$posSave     = strpos($body, 'venueAdminSaveSchedule($db, $body)');
$check(
    $posExisting !== false && $posSave !== false && $posExisting < $posSave,
    "the existing-row org re-check runs BEFORE venueAdminSaveSchedule()"
);

/* The posted-venue check must also still be present (defence-in-depth, the
   pre-existing half). */
$hasPostedVenueCheck = (bool)preg_match(
    '/userCanActOnOrg\s*\(\s*\$authUser\s*,\s*\(int\)\s*\$venue\[[\'"]OrgId[\'"]\]\s*\)/',
    $body
);
$check($hasPostedVenueCheck, "still checks userCanActOnOrg against the posted venue's OrgId");

if ($fails > 0) {
    fwrite(STDERR, "\n$fails assertion(s) failed — the schedule IDOR guard has regressed.\n");
    exit(1);
}
echo "\nAll org_admin_schedule_save IDOR-guard assertions passed.\n";
