<?php

declare(strict_types=1);

/**
 * iHymns — Organisation shared helpers (#719 PR 2c)
 *
 * Single source of truth for the bits both /manage/organisations.php
 * (system admin) and /manage/my-organisations.php (org admin) share
 * with the new admin_organisation_* / org_admin_* API endpoints in
 * /api.php:
 *
 *   - ORG_MEMBER_ROLES — the allowlist of `tblOrganisationMembers.Role`
 *     values. Both surfaces accept the same three (member / admin /
 *     owner).
 *   - slugifyOrganisationName() — same lowercase + non-alphanum-→hyphen
 *     transform organisations.php has used since #459 / #260, lifted
 *     out so the API auto-slug path matches exactly.
 *   - userCanActOnOrg() — row-level gate from PR #726. Returns true
 *     when the caller is system admin OR holds an admin/owner row
 *     on tblOrganisationMembers for the target org. Used by every
 *     org_admin_* endpoint to refuse cross-org POSTs.
 *
 * The licence-type allowlists are deliberately NOT shared — the two
 * surfaces accept slightly different sets (system admin uses `none`
 * as a "no primary" sentinel; org admin uses individual rows where
 * absence-is-no-licence). Each call site keeps its own const.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* tblOrganisationMembers.Role allowlist — shared between both surfaces. */
if (!defined('IHYMNS_ORG_MEMBER_ROLES_DEFINED')) {
    define('IHYMNS_ORG_MEMBER_ROLES_DEFINED', true);
    define('ORG_MEMBER_ROLES', ['member', 'admin', 'owner']);
}

/**
 * Lowercase + non-alphanum-→hyphen slug transform. Matches the
 * closure in organisations.php (and the auto-slug path in
 * /api?action=organisation_create) so a curator who types the same
 * name on either surface gets the same slug.
 *
 * @param string $s Raw text — typically the org name or a curator-
 *                  provided slug override.
 * @return string A trimmed, hyphen-joined, lowercase slug. May be
 *                empty if the input had no [a-z0-9] characters at all
 *                (caller decides whether to refuse or fall back).
 */
function slugifyOrganisationName(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/**
 * Row-level org-admin gate (PR #726 / #707). Returns true when
 * the caller is system admin OR holds an admin/owner row on
 * tblOrganisationMembers for the target org.
 *
 * Caller is the bearer-token user (from getAuthenticatedUser()).
 * The role lookup is keyed by the PascalCase 'Id' / 'Role' shape
 * that endpoint produces.
 *
 * @param array $authUser Authenticated user array with 'Id' + 'Role'.
 * @param int   $orgId    Target organisation id (must be > 0).
 * @return bool True if allowed; false otherwise.
 */
function userCanActOnOrg(array $authUser, int $orgId): bool
{
    if ($orgId <= 0) return false;
    $role = (string)($authUser['Role'] ?? '');
    if (in_array($role, ['admin', 'global_admin'], true)) return true;

    $userId = (int)($authUser['Id'] ?? 0);
    if ($userId <= 0) return false;
    /* userIsOrgAdminOf is best-effort against schema drift —
       returns [] on a pre-migration deployment, which means the
       gate refuses (correct fail-closed behaviour). */
    if (!function_exists('userIsOrgAdminOf')) {
        return false;
    }
    return in_array($orgId, userIsOrgAdminOf($userId), true);
}
