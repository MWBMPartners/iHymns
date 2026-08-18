<?php

declare(strict_types=1);

/**
 * iHymns — org-admin nav visibility (#1667)
 *
 * ELI5: an organisation admin is allowed to run a live service, but the menu
 * link to the projector / lead-a-service pages only ever showed to holders of
 * the `manage_organisations` permission — so the org admins the feature was
 * built for could reach the pages by URL but never find them. This proves the
 * menu now shows those links to an org admin too, and to nobody it shouldn't.
 *
 * THE BUG (#1667): `admin-links.php` has ONE entitlement column per nav row, and
 * `service-projection.php` / `service-lead.php` self-gate on `manage_organisations`
 * **OR** `userIsOrgAdminOf()` — a per-organisation membership fact a flat
 * entitlement can't express. Fix: an optional index-6 `'org_admin'` sentinel on
 * the row, which `visibleAdminLinks($role, $userId)` treats as "…OR the user is
 * an org admin". This is the reporter's recommended option 1.
 *
 * WHY THIS TEST IS DB-FREE: `visibleAdminLinks()` calls `userHasEntitlement()`
 * and (for the sentinel) `userIsOrgAdminOf()`, both of which hit the DB in
 * production. `admin-links.php` deliberately does NOT require the file that
 * defines them (its doc-block: the caller has already loaded the auth
 * bootstrap), so this test defines controllable STUBS for both before requiring
 * the registry — exercising the real filter logic against every membership shape
 * without a database. (Each PHP suite runs in its own process, so the stubs are
 * isolated.)
 *
 *   php tests/php/test-nav-org-admin-visibility.php
 *
 * Exit 0 = visibility correct, 1 = an org admin can't see (or a stranger can).
 */

/* ---- controllable stubs for the two membership checks visibleAdminLinks uses -- */
$GLOBALS['_stub_ents']     = [];   // entitlement key => bool
$GLOBALS['_stub_orgAdmin'] = [];   // array returned by userIsOrgAdminOf()

function userHasEntitlement(string $entitlement, ?string $role): bool
{
    return (bool)($GLOBALS['_stub_ents'][$entitlement] ?? false);
}
function userIsOrgAdminOf(?int $userId): array
{
    // Only a real (non-null, positive) id ever resolves to memberships.
    return ($userId !== null && $userId > 0) ? ($GLOBALS['_stub_orgAdmin'] ?? []) : [];
}

require_once dirname(__DIR__, 2) . '/appWeb/public_html/manage/includes/admin-links.php';

$failures = [];
$passed   = 0;
function navOk(string $label, bool $cond): void
{
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/** Is a link with this slug present in a visibleAdminLinks() result? */
function hasLink(array $links, string $slug): bool
{
    foreach ($links as $l) {
        if (($l[0] ?? null) === $slug) { return true; }
    }
    return false;
}

$SERVICE = ['service-projection', 'service-lead'];   // the two sentinel rows

/* Sanity: the registry actually carries exactly these two sentinels. Ties the
   test to the tree — a third sentinel row added later shows up here. */
global $_adminLinks;
$sentinelSlugs = [];
foreach ($_adminLinks as $l) {
    if (($l[6] ?? null) === 'org_admin') { $sentinelSlugs[] = $l[0]; }
}
sort($sentinelSlugs);
$expectedSentinels = $SERVICE;
sort($expectedSentinels);
navOk("registry carries exactly the two 'org_admin' sentinels (" . implode(',', $sentinelSlugs) . ')',
    $sentinelSlugs === $expectedSentinels);

/* CASE 1 — an ORG ADMIN holding NO admin entitlements. The bug: they saw none
   of the service links. The fix: they see both (via the sentinel), but NOT
   venues (manage_organisations only, no sentinel). */
$GLOBALS['_stub_ents']     = [];
$GLOBALS['_stub_orgAdmin'] = [42];               // administers one org
$links = visibleAdminLinks('editor', 7);
navOk('org admin sees service-projection (#1667 fix)', hasLink($links, 'service-projection'));
navOk('org admin sees service-lead (#1667 fix)',       hasLink($links, 'service-lead'));
navOk('org admin does NOT see venues (no sentinel, entitlement-only)', !hasLink($links, 'venues'));
navOk('org admin still sees the always-open Dashboard', hasLink($links, 'dashboard'));
navOk('org admin does NOT see an unrelated gated page (users)', !hasLink($links, 'users'));

/* CASE 2 — a NON-org-admin with no entitlements: the sentinel must NOT leak the
   service links to a stranger. */
$GLOBALS['_stub_ents']     = [];
$GLOBALS['_stub_orgAdmin'] = [];                 // administers nothing
$links = visibleAdminLinks('editor', 7);
navOk('non-org-admin does NOT see service-projection', !hasLink($links, 'service-projection'));
navOk('non-org-admin does NOT see service-lead',       !hasLink($links, 'service-lead'));

/* CASE 3 — a manage_organisations holder: sees the service links AND venues via
   the entitlement, sentinel or not. */
$GLOBALS['_stub_ents']     = ['manage_organisations' => true];
$GLOBALS['_stub_orgAdmin'] = [];
$links = visibleAdminLinks('admin', 7);
navOk('manage_organisations holder sees service-projection', hasLink($links, 'service-projection'));
navOk('manage_organisations holder sees venues',             hasLink($links, 'venues'));

/* CASE 4 — the caller could not resolve a user id ($userId null). The sentinel
   must degrade to the entitlement-only view, never throw and never guess. An org
   admin whose id is unknown simply doesn't get the extra links here — the safe,
   documented fallback. Mutation: drop the `$userId !== null` guard and this,
   with userIsOrgAdminOf(null) returning [], still passes — so pair it with the
   positive CASE 1 above, which fails if the sentinel is dropped entirely. */
$GLOBALS['_stub_ents']     = [];
$GLOBALS['_stub_orgAdmin'] = [42];
$links = visibleAdminLinks('editor', null);
navOk('null userId → service links fall back to entitlement-only (no throw)',
    !hasLink($links, 'service-projection') && !hasLink($links, 'service-lead'));

/* ---- report ---------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: org-admin nav visibility (#1667):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  ✗ $f\n"); }
    fwrite(STDERR, "\n{$passed} passed, " . count($failures) . " failed.\n");
    exit(1);
}
echo "PASS: org-admin nav visibility ({$passed} assertions).\n";
exit(0);
