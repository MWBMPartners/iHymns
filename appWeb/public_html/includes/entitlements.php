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
    /* MAP ALIGNED TO REALITY, NOT THE OTHER WAY ROUND (#1590, entitlement truth-up).
       ELI5: these two used to say "admins only" while the code let every curator
       do it. Now that something actually CHECKS them, saying "admins only" would
       have quietly taken the ability away from curators who use it every day.
       Detail: both keys were decorative until this pass — nothing anywhere called
       userHasEntitlement() with them. What really gated a delete and a bulk edit
       was the editor APIs' file-level `hasRole($role, 'editor')` (manage/editor/
       api.php:47, api2.php:135), i.e. editor+. Wiring the check with the OLD
       admin+ list would have been a live privilege REMOVAL disguised as a
       de-orphaning fix. The remediation plan's §4.6 predicted this for
       `bulk_edit_songs`; verification found `delete_songs` in the same state (the
       plan believed delete was escalated to a raw `admin` role — it is not; the
       only `hasRole(…,'admin')` near delete decides whether the ERROR DETAIL is
       disclosed, api.php:3446 / api2.php:139).
       An operator who WANTS delete to be admin-only can now make that true by
       unticking `editor` at /manage/entitlements — which is exactly the control
       that did nothing before.

       `delete_songs` IS BACK AT EDITOR+ — #1692 stage 3 (#1695), 2026-07-31.

       It was reduced to admin-only in stage 1 for one stated reason, recorded
       here at the time: a delete was a HARD `DELETE FROM tblSongs`, and 38 of
       the 41 FKs referencing tblSongs(SongId) cascade — including
       `fk_Revisions_Song`, so a delete destroyed the song, its components, its
       credits, its media links AND its entire revision history. Recovery meant
       restoring a database backup. That comment closed by saying the reduction
       was INTERIM and to revisit this line once deletion became recoverable.

       Stage 2 (#1694) made it recoverable, so the reason is gone and the line
       is duly revisited. `delete_songs` now gates a SOFT delete: the song is
       hidden everywhere but wholly intact, and one click on
       /manage/deleted-songs restores it losslessly. The irreversible cascade
       moved to `purge_songs` below — which is the entire point of that key
       existing separately, and why THIS widening cannot drag the permanent
       delete along with it.

       Two safeguards make editor+ safe rather than merely defensible:
       every soft delete notifies every `purge_songs` holder from inside the
       write core (so no future funnel can forget to), and an operator who
       disagrees can re-narrow this key at /manage/entitlements — a control
       that is real, not theoretical.

       ⚠️ Changing this line ALONE may be a no-op on a live install. A stored
       `entitlements_overrides` row replaces the code default outright, and
       `saveEntitlementOverrides()` writes the WHOLE map — so any install where
       that screen has ever been saved keeps the stage-1 value regardless of
       what is written here. `.sql/migrate-delete-songs-rewiden.php` is the
       other half of this change. */
    'delete_songs'         => ['editor', 'admin', 'global_admin'],
    /* `purge_songs` — the IRREVERSIBLE half of #1694's two-step delete (owner
       decision D3: add it now, separately from delete_songs). `delete_songs`
       now gates the RECOVERABLE soft delete (and the /manage/deleted-songs
       Restore); this key alone gates the destructive Purge — the cascade
       DELETE that takes the song, its components, credits, media links AND
       its entire revision history, with no in-app undo.
       WHY A SEPARATE KEY: #1695 (stage 3) re-widens `delete_songs` back to
       editor+ once deletion is recoverable. Without this key, the purge would
       silently widen with it — or need a hardcoded role check, which the
       review red-flag list bans. One key per distinct power, so the operator
       can hand curators the recoverable delete while keeping the permanent
       one at admin+. */
    'purge_songs'          => ['admin', 'global_admin'],
    'bulk_edit_songs'      => ['editor', 'admin', 'global_admin'],
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
    /* ALIGNED to the gate that actually decides this (#1590). /manage/setup-
       database.php — the ONLY page that can run a backup — gates its whole load
       on `run_db_install` (setup-database.php:57), whose default is
       ['global_admin']. So an `admin` has never been able to take a backup; the
       admin tick here advertised a capability the page denied one line earlier.
       Listing `global_admin` alone changes nothing observable and stops the map
       claiming otherwise. */
    'run_db_backup'        => ['global_admin'],
    'run_db_restore'       => ['global_admin'],
    'drop_legacy_tables'   => ['global_admin'],
    /* System configuration — email service, system-wide flags, etc.
       (#768). Global Admin only because the settings affect every
       user (e.g. SMTP credentials, captcha provider, registration
       mode) and a misconfigured email backend can produce spam-
       grade outbound traffic from the server's IP. */
    'manage_configuration' => ['global_admin'],

    /* In-app notifications admin — compose & broadcast. (#813)
       Global Admin only: a broadcast targeting "all signed-in users"
       can amount to mass messaging that's hard to revoke once
       people have read it. Limit to operators who own platform
       comms. The header bell + per-user list endpoints remain open
       to every signed-in user; this is just for posting / managing
       the feed. */
    'manage_notifications' => ['global_admin'],

    /* SQL Diagnostics page (/manage/diagnostics). Curator-curated
       SELECT-only query surface for forensics and "what does the DB
       actually look like right now" questions that the admin grid
       pages don't surface. Server-enforced read-only (regex rejects
       any non-SELECT/SHOW/EXPLAIN/DESCRIBE statement; table whitelist
       blocks mysql.* and performance_schema.*), row-capped at 1000,
       every query writes to tblActivityLog. Global Admin only —
       even with the regex guard, the surface still leaks schema and
       row content that's appropriate only for the operator who owns
       the deployment. */
    'view_diagnostics'     => ['global_admin'],

    /* Machine-to-machine API keys (#1064) — minting/revoking keys that let
       external services write to the public API is the most sensitive admin
       surface, so global_admin only. */
    'manage_api_keys'      => ['global_admin'],
    'request_api_keys'     => ['admin', 'global_admin'],   // self-serve key requests (Phase D); global admins still approve via manage_api_keys

    /* Duplicate-songs review + merge (#1064) — destructive merge, so admin+. */
    'manage_duplicate_songs' => ['admin', 'global_admin'],

    /* Set-list templates (#301 / #1698).
     *
     * `manage_setlist_templates` — an ADMIN OVERRIDE on the owner-only edit
     * rule. `setlistTemplateCanEdit()` is deliberately author-only (org
     * membership is a VISIBILITY grant in `setlist_templates`, and reusing it as
     * an EDIT grant would hand every member of a church destructive power over a
     * colleague's template). That leaves the #1698 orphan: `fk_Template_User` is
     * `ON DELETE SET NULL`, so a template outlives its author with
     * `CreatedBy = NULL` and is then editable by NOBODY — and a PUBLIC one in
     * that state could only be removed with direct database access. The same
     * applies to a template whose author is now a disabled account or an erased
     * tombstone. This entitlement is the manageability answer; it does not widen
     * ordinary editing by one user.
     *
     * `publish_public_templates` — who may set `is_public = 1`. A public
     * template is visible to EVERY user of the app, signed in or not, so
     * publishing is a broadcast, not a save. Defaulting to admin+ mirrors
     * `manage_notifications` (the other "this reaches everybody" capability).
     * ⚠ This default is a JUDGEMENT, flagged as trivially changeable: an
     * operator who wants curators publishing templates ticks `editor` at
     * /manage/entitlements and nothing else changes. Note the request is
     * REJECTED rather than silently demoted to private — a silent demotion is
     * the no-op class this codebase keeps paying for (rule #30). */
    'manage_setlist_templates' => ['admin', 'global_admin'],
    'publish_public_templates' => ['admin', 'global_admin'],

    /* Content moderation */
    'review_song_requests' => ['editor', 'admin', 'global_admin'],

    /* Content structure — songbook/group/organisation admin surfaces */
    'manage_songbooks'     => ['admin', 'global_admin'],
    'manage_user_groups'   => ['admin', 'global_admin'],
    'manage_organisations' => ['admin', 'global_admin'],
    'manage_musicians' => ['admin', 'global_admin'],
    /* Works (#840) — composition-grouping CRUD. Same gate as the rest
       of the catalogue surfaces; curators creating Works are doing
       editorial work that ripples across multiple songbook surfaces. */
    'manage_works'         => ['admin', 'global_admin'],
    /* Tunes (#1748) — tblTunes registry CRUD (aliases/credits/external
       links + merge). Mirrors manage_works/manage_musicians exactly —
       ELI5: same "who can edit the catalogue" people as Works/Musicians.
       @see #1748 */
    'manage_tunes'         => ['admin', 'global_admin'],
    /* External-link types + URL patterns (#845) — controlled-vocabulary
       registry that drives every "Find this … elsewhere" panel and
       the URL auto-detect module. Curator-managed; same gate as the
       rest of the catalogue surfaces. */
    'manage_external_link_types' => ['admin', 'global_admin'],
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

    /* Admin-configurable feature gating (#1481 P1) — DEFINING new gateable
       capabilities (tblGatingCapabilities) + enforcement rules
       (tblGatingRules, P2) is Global-Admin-only, deliberately narrower than
       manage_access_tiers: a bad capability definition can affect every
       tier at once, whereas per-tier VALUES (which caps a given tier grants)
       stay under the existing manage_access_tiers entitlement — an
       owner-locked split (the strategy doc's "Owner decisions" #2). */
    'manage_feature_gating'       => ['global_admin'],

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

    /* API Docs (Swagger UI) — curator-and-up may inspect the OpenAPI
       3.0 spec rendered in-browser. Surfaces request/response shapes
       + a "Try it out" runner for any endpoint the user's own token
       is allowed to call. Editor / Curator role is enough because
       the spec itself isn't sensitive — every endpoint is enforced
       server-side regardless of whether a curator can read the docs. */
    'view_api_docs'        => ['editor', 'admin', 'global_admin'],
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

/**
 * Conditionally drop one key from a saved entitlement-overrides map.
 *
 * ELI5: the permissions screen remembers every tick box, not just the ones you
 * changed. If it is still remembering the exact answer we shipped by mistake,
 * forget that one answer so the corrected default applies. If somebody has
 * since chosen something different on purpose, leave their choice alone.
 *
 * WHY THIS EXISTS (#1695, epic #1692 stage 3)
 * -------------------------------------------
 * `effectiveEntitlements()` lets a STORED key replace the code default
 * outright, and `saveEntitlementOverrides()` writes the WHOLE map on every
 * save — not a delta. So on any install where `/manage/entitlements` has ever
 * been saved, changing a default in PHP is a **silent no-op**: the code says
 * editor+, the database says admin+, and the database wins. The feature ships,
 * looks correct in review, and changes nothing for the operator.
 *
 * That is the trap #1695's own scope missed entirely, and it is the same class
 * `migrate-entitlement-truthup.php` (#1590) was written for.
 *
 * WHY CONDITIONAL, NOT A BLANKET CLEAR — the load-bearing decision
 * ----------------------------------------------------------------
 * The stage-1 narrowing of `delete_songs` to admin-only and a deliberate
 * operator lockdown to admin-only are, in the database, THE SAME BYTES. Nothing
 * records which one wrote them.
 *
 * A blanket clear would therefore silently overrule an operator who had made a
 * considered choice — handing song deletion to every curator on a site that had
 * explicitly decided otherwise. That is a worse failure than the no-op it fixes,
 * because it is invisible AND it widens a privilege.
 *
 * So the prune fires ONLY when the stored value is byte-for-byte the value we
 * shipped ($expected). Anything else — `global_admin` only, a custom set, a
 * value already widened — is treated as intentional and preserved. The cost is
 * that an operator who happened to choose exactly the interim value keeps it;
 * that is the safe direction, and it is one tick box away from being changed.
 *
 * PURE — no database, no globals, no I/O. Comparison is order-insensitive but
 * exact on membership, because ['admin','global_admin'] and
 * ['global_admin','admin'] are the same permission and JSON key order is not
 * something a caller controls.
 *
 * @param array<string, mixed> $stored   the decoded overrides map as saved
 * @param string               $key      the entitlement to consider pruning
 * @param string[]             $expected the exact stored value that means
 *                                       "this is ours, not the operator's"
 * @return array{0: array<string, mixed>, 1: bool} the map, and whether it changed
 */
function entitlementOverridesPruneIfEquals(array $stored, string $key, array $expected): array
{
    /* Absent key: nothing shadows the default, so there is nothing to do. This
       is the common case on an install that has never saved the page, and it
       must be a clean no-op rather than an error. */
    if (!array_key_exists($key, $stored)) {
        return [$stored, false];
    }

    $value = $stored[$key];
    if (!is_array($value)) {
        /* A non-array value cannot be the map we shipped, so by the rule above
           it is not ours to remove. Leave it; effectiveEntitlements() already
           ignores a non-array override defensively. */
        return [$stored, false];
    }

    /* Normalise both sides: strings only, de-duplicated, sorted. Order and
       duplicates are storage noise, not meaning. */
    $norm = static function (array $roles): array {
        $r = array_values(array_unique(array_filter($roles, 'is_string')));
        sort($r);
        return $r;
    };

    if ($norm($value) !== $norm($expected)) {
        return [$stored, false];      // the operator's choice — preserve it
    }

    unset($stored[$key]);
    return [$stored, true];
}
