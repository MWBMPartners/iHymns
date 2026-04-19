<?php

declare(strict_types=1);

/**
 * iHymns — Entitlements (#407)
 *
 * Single source of truth for what a given role is allowed to do, keyed
 * by short verb-style entitlement names. Call `hasEntitlement()` instead
 * of `hasRole('editor')` sprinkled through the app — that way, granting
 * (or revoking) a capability is one line here instead of a codebase hunt.
 *
 * Roles are defined in manage/includes/auth.php (`user`, `editor`,
 * `admin`, `global_admin`). Add a new role there, then extend the map
 * below. The client-side counterpart (js/modules/entitlements.js) must
 * stay in sync — the map is small and stable, which is why it's
 * duplicated rather than fetched at runtime.
 *
 * Usage:
 *     require_once 'entitlements.php';
 *     if (userHasEntitlement('edit_songs', $currentUser['role'])) { ... }
 */

/**
 * Map of entitlement → set of roles that carry it.
 *
 * When adding a new entitlement:
 *  1. Add an entry here (lowest role that should have it upward).
 *  2. Mirror it in js/modules/entitlements.js.
 *  3. Reference it via userHasEntitlement() rather than role checks.
 */
const ENTITLEMENTS = [
    /* Song data */
    'edit_songs'           => ['editor', 'admin', 'global_admin'],
    'delete_songs'         => ['admin', 'global_admin'],
    'bulk_edit_songs'      => ['admin', 'global_admin'],
    'verify_songs'         => ['editor', 'admin', 'global_admin'],

    /* User management */
    'view_users'           => ['admin', 'global_admin'],
    'edit_users'           => ['admin', 'global_admin'],
    'change_user_roles'    => ['admin', 'global_admin'],
    'assign_global_admin'  => ['global_admin'],
    'delete_users'         => ['admin', 'global_admin'],

    /* Database + operations */
    'view_admin_dashboard' => ['admin', 'global_admin'],
    'view_analytics'       => ['admin', 'global_admin'],
    'run_db_install'       => ['global_admin'],
    'run_db_migrate'       => ['global_admin'],
    'run_db_backup'        => ['admin', 'global_admin'],
    'run_db_restore'       => ['global_admin'],
    'drop_legacy_tables'   => ['global_admin'],

    /* Content moderation */
    'review_song_requests' => ['editor', 'admin', 'global_admin'],
];

/**
 * Does the given role hold the named entitlement?
 *
 * @param string      $entitlement e.g. 'edit_songs'
 * @param string|null $role        Caller's role; null → treated as guest
 * @return bool
 */
function userHasEntitlement(string $entitlement, ?string $role): bool
{
    if ($role === null || $role === '') {
        return false;
    }
    $allowed = ENTITLEMENTS[$entitlement] ?? null;
    if ($allowed === null) {
        /* Unknown entitlement — fail closed. */
        return false;
    }
    return in_array($role, $allowed, true);
}

/**
 * All entitlements a given role carries. Useful for export to the
 * client (so the UI can hide/show controls without having to ship the
 * entire role→entitlement map).
 *
 * @param string|null $role
 * @return string[]
 */
function entitlementsFor(?string $role): array
{
    if ($role === null || $role === '') return [];
    $out = [];
    foreach (ENTITLEMENTS as $name => $roles) {
        if (in_array($role, $roles, true)) {
            $out[] = $name;
        }
    }
    return $out;
}
