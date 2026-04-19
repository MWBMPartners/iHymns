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

    /* Meta */
    'manage_entitlements'  => ['global_admin'],
];

/**
 * In-memory cache of the effective map (defaults merged with any admin
 * overrides from tblAppSettings). Populated lazily by effectiveEntitlements().
 */
$_ihymns_effective_entitlements = null;

/**
 * Return the effective entitlement map, applying any admin overrides
 * saved under `tblAppSettings.SettingKey = 'entitlements_overrides'`.
 *
 * The override value is a JSON map of `entitlement => [role, …]` that
 * completely replaces the default list for each entitlement it covers.
 * Entitlements absent from the override keep their hardcoded default,
 * so adding a new entitlement in code never needs a DB change.
 *
 * @return array<string, string[]>
 */
function effectiveEntitlements(): array
{
    global $_ihymns_effective_entitlements;
    if ($_ihymns_effective_entitlements !== null) {
        return $_ihymns_effective_entitlements;
    }

    $map = ENTITLEMENTS;

    /* getDb() is PDO, defined in manage/includes/db.php. Not every
       caller pre-loads it, so fail soft — an unreachable DB falls back
       to the hardcoded defaults. */
    if (function_exists('getDb')) {
        try {
            $db = getDb();
            $stmt = $db->prepare(
                'SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?'
            );
            $stmt->execute(['entitlements_overrides']);
            $raw = (string)($stmt->fetchColumn() ?: '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $ent => $roles) {
                        if (isset($map[$ent]) && is_array($roles)) {
                            $map[$ent] = array_values(array_filter($roles, 'is_string'));
                        }
                    }
                }
            }
        } catch (\Throwable $_e) {
            /* DB unreachable — stick with defaults. */
        }
    }

    $_ihymns_effective_entitlements = $map;
    return $map;
}

/**
 * Replace (or clear) the admin overrides. Called from
 * /manage/entitlements.php after the form is saved.
 *
 * @param array<string, string[]> $overrides Full intended map
 *        (overrides for every entitlement, not deltas).
 * @return bool
 */
function saveEntitlementOverrides(array $overrides): bool
{
    global $_ihymns_effective_entitlements;
    if (!function_exists('getDb')) return false;
    try {
        $db = getDb();
        $json = json_encode($overrides, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        $stmt->execute(['entitlements_overrides', (string)$json]);
        $_ihymns_effective_entitlements = null; /* bust cache */
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

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
    $map = effectiveEntitlements();
    $allowed = $map[$entitlement] ?? null;
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
    foreach (effectiveEntitlements() as $name => $roles) {
        if (in_array($role, $roles, true)) {
            $out[] = $name;
        }
    }
    return $out;
}
