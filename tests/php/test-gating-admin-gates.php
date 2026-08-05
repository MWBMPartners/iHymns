<?php

declare(strict_types=1);

/**
 * iHymns — gating-family API actions gate on the same entitlement as their page (#1769 P0, OV-10)
 * ===============================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The admin PAGES for tiers and content-restrictions are protected by a named
 * permission (an "entitlement") that a global-admin can grant or revoke per
 * role on /manage/entitlements. But several of the matching API actions in
 * api.php were protected by a hardcoded role list instead — so revoking
 * "manage access tiers" locked the tiers PAGE while leaving the tier-editing
 * API wide open to any admin. This guard makes sure those API actions gate on
 * the SAME entitlement the page does, so the two can never drift apart again.
 *
 * It also pins the related fix that `admin_set_user_tier` validates the tier
 * name against the LIVE tblAccessTiers table, not a hardcoded five-name list
 * (which silently rejected every curator-created tier).
 *
 * DETAILED — this is a FOCUSED regression pin, not a general API-gate scanner:
 * it names the specific gating-family actions #1769 P0 corrected and asserts
 * each one's handler window uses the intended entitlement and no raw-role list.
 * A general "every admin_* action ↔ its page entitlement" scanner is a larger,
 * separate build (there is no mechanical page↔action mapping in the tree yet);
 * tracked as a follow-up. This pin still earns its keep: it goes red the moment
 * any of these five handlers reverts to a raw-role gate or the hardcoded tier
 * list (mutation-proven below).
 *
 * MUTATION PROOF (rule #34): revert any of the five handlers to
 * `in_array($authUser['Role'], ['admin','global_admin'])` (or restore
 * `$validTiers = [...]` in admin_set_user_tier) and this guard goes red;
 * restore → green. (Proven by the author via a cp-backup mutation.)
 *
 * @link appWeb/public_html/api.php  the five action handlers under guard (#1769 P0)
 * @link appWeb/public_html/manage/includes/admin-links.php  the entitlements the pages advertise
 */

$repoRoot = dirname(__DIR__, 2);
$apiFile  = $repoRoot . '/appWeb/public_html/api.php';
$src      = is_readable($apiFile) ? (string)file_get_contents($apiFile) : '';
if ($src === '') {
    fwrite(STDERR, "FATAL: could not read {$apiFile}\n");
    exit(1);
}

$failures = [];

/* The intended contract: gating-family action => the entitlement its admin
   page + nav entry advertise (admin-links.php). Documented here so the guard
   states the rule it enforces. */
$actionEntitlement = [
    'admin_tier_create'  => 'manage_access_tiers',
    'admin_tier_update'  => 'manage_access_tiers',
    'admin_tier_delete'  => 'manage_access_tiers',
    'admin_restrictions' => 'manage_content_restrictions',
    'admin_set_user_tier' => 'assign_user_tier',
];

/** Slice the handler body from `case 'action'` up to the next `case '` (or
 *  ~1500 chars) — enough to contain the auth gate at the top of the case. */
$handlerWindow = static function (string $src, string $action): ?string {
    $pos = strpos($src, "case '{$action}'");
    if ($pos === false) { return null; }
    $rest = substr($src, $pos, 1600);
    // trim at the next case label so we don't bleed into the neighbour
    if (($next = strpos($rest, "case '", 8)) !== false) {
        $rest = substr($rest, 0, $next);
    }
    return $rest;
};

foreach ($actionEntitlement as $action => $ent) {
    $win = $handlerWindow($src, $action);
    if ($win === null) {
        $failures[] = "api.php: could not find `case '{$action}'` — did the action get renamed? (#1769 P0 pin needs updating).";
        continue;
    }
    if (!str_contains($win, "userHasEntitlement('{$ent}'")) {
        $failures[] = "api.php: action '{$action}' does not gate on userHasEntitlement('{$ent}', …) — the "
                    . "entitlement its /manage page advertises. It must, so revoking that entitlement locks "
                    . "the API too (#1769 P0, OV-10).";
    }
    if (str_contains($win, "in_array(\$authUser['Role'], ['admin', 'global_admin'])")) {
        $failures[] = "api.php: action '{$action}' still carries a raw-role gate "
                    . "(in_array(\$authUser['Role'], ['admin','global_admin'])) — replace it with "
                    . "userHasEntitlement('{$ent}', …) so the API honours entitlement overrides (#1769 P0).";
    }
}

/* admin_set_user_tier: validates against the LIVE table, not a hardcoded list. */
$win = $handlerWindow($src, 'admin_set_user_tier');
if ($win !== null) {
    if (!preg_match('/FROM\s+tblAccessTiers\s+WHERE\s+Name\s*=\s*\?/i', $win)) {
        $failures[] = "api.php: admin_set_user_tier does not validate the tier against the live tblAccessTiers "
                    . "catalogue (SELECT … FROM tblAccessTiers WHERE Name = ?) — a curator-created tier must be "
                    . "assignable via the API, matching /manage/users (#1769 P0, OV-10).";
    }
    if (preg_match('/\$validTiers\s*=\s*\[/', $win)) {
        $failures[] = "api.php: admin_set_user_tier still builds a hardcoded \$validTiers list — that silently "
                    . "rejects every custom tier. Validate against the live table instead (#1769 P0).";
    }
}

/* Licence-type CRUD (#1769 P4): the four admin_licence_type_* actions are
   FALL-THROUGH case labels sharing ONE handler body (the gate + dispatch live
   under `case 'admin_licence_type_delete':`), so the $actionEntitlement loop's
   trim-at-next-`case '` window would be empty for the first three. Bound the
   whole group from its first label to the next non-licence case
   (admin_tier_create) and assert the shared body gates on manage_licence_types
   — the entitlement /manage/licence-types + its nav entry advertise (#1587). */
$ltActions = [
    'admin_licence_type_create',
    'admin_licence_type_update',
    'admin_licence_type_toggle',
    'admin_licence_type_delete',
];
$ltStart = strpos($src, "case 'admin_licence_type_create'");
$ltEnd   = strpos($src, "case 'admin_tier_create'");
if ($ltStart === false || $ltEnd === false || $ltEnd <= $ltStart) {
    $failures[] = "api.php: could not bound the admin_licence_type_* handler group "
                . "(case 'admin_licence_type_create' … case 'admin_tier_create') — did the actions get "
                . "renamed or moved? (#1769 P4 pin needs updating).";
} else {
    $ltWin = substr($src, $ltStart, $ltEnd - $ltStart);
    foreach ($ltActions as $a) {
        if (!str_contains($ltWin, "case '{$a}'")) {
            $failures[] = "api.php: admin_licence_type_* group is missing `case '{$a}'` — the four CRUD "
                        . "actions must share the one gated handler body (#1769 P4).";
        }
    }
    if (!str_contains($ltWin, "userHasEntitlement('manage_licence_types'")) {
        $failures[] = "api.php: admin_licence_type_* group does not gate on "
                    . "userHasEntitlement('manage_licence_types', …) — the entitlement "
                    . "/manage/licence-types advertises (#1769 P4, #1587).";
    }
    if (str_contains($ltWin, "in_array(\$authUser['Role'], ['admin', 'global_admin'])")) {
        $failures[] = "api.php: admin_licence_type_* group still carries a raw-role gate — replace it with "
                    . "userHasEntitlement('manage_licence_types', …) (#1769 P4).";
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: gating-family admin-gate guard (#1769 P0, OV-10):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    fwrite(STDERR, "\n");
    exit(1);
}
echo "PASS: gating-family API actions (tier CRUD, admin_restrictions, admin_set_user_tier, licence-type CRUD) "
   . "gate on their page's entitlement, and admin_set_user_tier validates tiers against the live catalogue.\n";
exit(0);
