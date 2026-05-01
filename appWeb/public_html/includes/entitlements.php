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

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

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
    /* System configuration — email service, system-wide flags, etc.
       (#768). Global Admin only because the settings affect every
       user (e.g. SMTP credentials, captcha provider, registration
       mode) and a misconfigured email backend can produce spam-
       grade outbound traffic from the server's IP. */
    'manage_configuration' => ['global_admin'],

    /* Content moderation */
    'review_song_requests' => ['editor', 'admin', 'global_admin'],

    /* Content structure — songbook/group/organisation admin surfaces */
    'manage_songbooks'     => ['admin', 'global_admin'],
    'manage_user_groups'   => ['admin', 'global_admin'],
    'manage_organisations' => ['admin', 'global_admin'],
    'manage_credit_people' => ['admin', 'global_admin'],
    /* Reference data — IETF BCP 47 language registry (tblLanguages).
       Mostly seeded from the IANA registry (#738) but admins occasionally
       need to add a private-use code, fix a NativeName, or deactivate a
       deprecated subtag without dropping the row. */
    'manage_languages'     => ['admin', 'global_admin'],
    /* Tags / themes (tblSongTags). Curator-managed taxonomy that
       powers the public Browse-by-Theme home section + /tag/<slug>
       listing pages. Curators occasionally need to rename, merge
       duplicates, or delete unused tags. (#770) */
    'manage_tags'          => ['admin', 'global_admin'],

    /* Org-admin role (#707) — system-level grant that says "this role
       MAY hold an admin/owner role on at least one organisation".
       The actual page-level gate calls userHasOwnOrganisation() (below)
       which reads tblOrganisationMembers to check whether THIS specific
       user holds the role on any org. system-admin / global_admin
       implicitly qualify because they can manage any org. */
    'manage_own_organisation' => ['user', 'editor', 'admin', 'global_admin'],

    /* Content gating for regular users — per-song / per-songbook / per-user
       restrictions (tblContentRestrictions) and access-tier definitions
       (tblAccessTiers) that control lyrics / audio / MIDI / PDF / offline. */
    'manage_content_restrictions' => ['admin', 'global_admin'],
    'manage_access_tiers'         => ['admin', 'global_admin'],
    'assign_user_tier'            => ['admin', 'global_admin'],

    /* Card-layout personalisation (#448). Default site layout is set by
       admins; every role is allowed to customise their own by default,
       but an admin can revoke `customise_own_card_layout` to lock a site
       down. Group-level veto via tblUserGroups.AllowCardReorder layers
       on top. */
    'manage_default_card_layout' => ['admin', 'global_admin'],
    'customise_own_card_layout'  => ['user', 'editor', 'admin', 'global_admin'],

    /* Channel access gating (#407) — controls who can reach alpha/beta
       subdomains. Applied BEFORE the page renders so pre-release builds
       are invisible to the public even when indexed. Defaults intentionally
       include `user` so that internal testers + curators can both access. */
    'access_alpha'         => ['user', 'editor', 'admin', 'global_admin'],
    'access_beta'          => ['user', 'editor', 'admin', 'global_admin'],

    /* Licences — multi-licence + inheritance (#462). Separate from the
       generic `manage_organisations` entitlement so licence edits can
       be delegated without granting full org admin. */
    'manage_org_licences'   => ['admin', 'global_admin'],
    'manage_user_licences'  => ['admin', 'global_admin'],
    'view_licence_audit'    => ['admin', 'global_admin'],

    /* Licence-compliance reporting (#317). Pulled from tblSongHistory
       against tblSongs.Ccli, exportable as CSV for the annual CCLI
       usage return. */
    'view_ccli_report'     => ['admin', 'global_admin'],

    /* Activity log viewer (#535). Reads tblActivityLog — every
       meaningful auth, CRUD, user-action, API, and system event.
       Default is admin+ since rows expose IP, UA, and email columns. */
    'view_activity_log'    => ['admin', 'global_admin'],

    /* Meta */
    'manage_entitlements'  => ['global_admin'],
];

/**
 * In-memory cache of the effective map (defaults merged with any admin
 * overrides from tblAppSettings). Populated lazily by effectiveEntitlements().
 */
$_ihymns_effective_entitlements = null;

/**
 * Return the most-restrictive role tier required by an entitlement,
 * for use by the admin-surface padlock indicators (#758).
 *
 * Mapping:
 *   - null / empty key → null   (no chip)
 *   - user or editor in roles → null   (no chip — anyone can act)
 *   - admin in roles → 'admin'   (yellow chip)
 *   - only global_admin in roles → 'global_admin'   (red chip)
 *
 * Reads the effective map (defaults + admin overrides) so a curator
 * who has tightened access via /manage/entitlements sees padlocks
 * that match the live policy, not the hardcoded defaults.
 *
 * @param string|null $key Entitlement key (e.g. 'manage_songbooks')
 * @return string|null     'admin' | 'global_admin' | null
 */
function entitlementHighestRole(?string $key): ?string
{
    if ($key === null || $key === '') return null;
    $map = effectiveEntitlements();
    $roles = $map[$key] ?? [];
    if (empty($roles)) return null;
    if (in_array('user', $roles, true) || in_array('editor', $roles, true)) {
        return null;
    }
    if (in_array('admin', $roles, true)) {
        return 'admin';
    }
    return 'global_admin';
}

/**
 * Render the inline HTML for a padlock chip next to an entitlement-
 * gated label / card title. Empty string when the entitlement is
 * accessible to user/editor (no chip needed). Bootstrap Icons
 * bi-lock-fill is SVG-rendered and accepts CSS colour overrides via
 * the .lock-chip-{tier} class. (#758)
 *
 * @param string|null $key Entitlement key
 * @return string          Inline HTML (already escaped) or ''
 */
function entitlementLockChipHtml(?string $key): string
{
    $tier = entitlementHighestRole($key);
    if ($tier === null) return '';
    $tierCls = $tier === 'global_admin' ? 'lock-chip-global-admin' : 'lock-chip-admin';
    $label   = $tier === 'global_admin' ? 'Requires Global Admin' : 'Requires Admin';
    return ' <i class="bi bi-lock-fill lock-chip ' . $tierCls . '"'
         . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
         . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"></i>';
}

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

    /* db_mysql.php is required at the top of this file, so getDbMysqli()
       should always be available — but keep the function_exists guard
       as cheap defence against a require_once failure (e.g. file mode
       breaking on deploy). Fail soft: an unreachable DB falls back to
       the hardcoded defaults. */
    if (function_exists('getDbMysqli')) {
        try {
            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?'
            );
            $key = 'entitlements_overrides';
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $raw = (string)($row[0] ?? '');
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
    if (!function_exists('getDbMysqli')) return false;
    try {
        $db   = getDbMysqli();
        $json = (string)json_encode($overrides, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        $key = 'entitlements_overrides';
        $stmt->bind_param('ss', $key, $json);
        $stmt->execute();
        $stmt->close();
        $_ihymns_effective_entitlements = null; /* bust cache */
        if (function_exists('logActivity')) {
            logActivity('settings.entitlements_change', 'app_setting', 'entitlements_overrides', [
                'after_keys' => array_keys($overrides),
            ]);
        }
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
 * Is invite-only channel gating currently enforced?
 *
 * Lives in tblAppSettings under `channel_gate_enabled`. Absent / "0" /
 * empty → gate is open (bootstrap mode) so the first admin can sign in
 * and configure role-based access without locking themselves out. An
 * admin flips this on from /manage/entitlements once entitlements are
 * set to taste.
 */
function isChannelGateEnabled(): bool
{
    if (!function_exists('getDbMysqli')) return false;
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare(
            'SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?'
        );
        $key = 'channel_gate_enabled';
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (string)($row[0] ?? '') === '1';
    } catch (\Throwable $_e) {
        /* DB unreachable — fail open so admins can still sign in. */
        return false;
    }
}

/**
 * Persist the gate-enabled flag. Called from /manage/entitlements.php.
 */
function setChannelGateEnabled(bool $enabled): bool
{
    if (!function_exists('getDbMysqli')) return false;
    try {
        $db   = getDbMysqli();
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        $key   = 'channel_gate_enabled';
        $value = $enabled ? '1' : '0';
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        if (function_exists('logActivity')) {
            logActivity('settings.channel_gate_change', 'app_setting', 'channel_gate_enabled', [
                'enabled' => $enabled,
            ]);
        }
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
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

/**
 * Org-admin scope (#707) — list the organisation IDs this user holds
 * an `admin` or `owner` role on (per tblOrganisationMembers).
 *
 * The two pieces:
 *   - userIsOrgAdminOf($userId)      → list of OrgIds the user manages
 *   - userHasOwnOrganisation($userId) → bool: are they admin/owner on ANY org?
 *
 * Page-level gate on /manage/my-organisations checks
 * userHasOwnOrganisation(); row-level checks (when an action targets a
 * specific org) call userIsOrgAdminOf() and require the target to be in
 * the returned list. system-admin / global_admin shortcut to "all orgs"
 * via the system role check before this helper runs.
 *
 * Best-effort against schema drift: if tblOrganisationMembers doesn't
 * exist on a fresh deployment, both helpers return [] / false rather
 * than 500'ing.
 */
function userIsOrgAdminOf(?int $userId): array
{
    if (!$userId || $userId <= 0) return [];
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare(
            "SELECT OrgId FROM tblOrganisationMembers
              WHERE UserId = ? AND Role IN ('admin', 'owner')"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $orgIds = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_row()) {
            $orgIds[] = (int)$row[0];
        }
        $stmt->close();
        return $orgIds;
    } catch (\Throwable $_e) {
        return [];
    }
}

function userHasOwnOrganisation(?int $userId): bool
{
    return !empty(userIsOrgAdminOf($userId));
}
