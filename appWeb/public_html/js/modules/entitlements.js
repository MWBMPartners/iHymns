/**
 * iHymns — Entitlements (#407, client side)
 *
 * Mirrors appWeb/public_html/includes/entitlements.php so the UI can
 * decide whether to show/hide a control without waiting on a server
 * round-trip. The PHP copy is authoritative — API endpoints re-check
 * via userHasEntitlement() before executing. This file is purely an
 * affordance for the UI.
 *
 * Keep the two maps in lock-step.
 */

/** @type {Object<string, string[]>} */
export const ENTITLEMENTS = {
    /* Song data */
    edit_songs:           ['editor', 'admin', 'global_admin'],
    /* editor+ since #1590 — see the long note beside the same two keys in
       includes/entitlements.php. Short version: nothing checked either key, and
       what really gated a delete / bulk edit was the editor APIs' editor-level
       role gate. Wiring the checks with the old admin-only list would have
       removed a capability curators use today, so the MAP moved to match the
       code rather than the other way round. This file is a MIRROR — copy the
       role list verbatim, never form a second opinion. */
    /* Admin-only by owner decision (#1692 stage 1): a song delete is a HARD
       delete that cascades away the song's components, credits, media links and
       its entire revision history, with no in-app undo. Interim — stage 2 adds
       soft delete, after which this returns to editor+. Must stay identical to
       includes/entitlements.php; test-entitlement-parity.php enforces that. */
    delete_songs:         ['admin', 'global_admin'],
    bulk_edit_songs:      ['editor', 'admin', 'global_admin'],
    verify_songs:         ['editor', 'admin', 'global_admin'],

    /* User management */
    view_users:           ['admin', 'global_admin'],
    edit_users:           ['admin', 'global_admin'],
    change_user_roles:    ['admin', 'global_admin'],
    assign_global_admin:  ['global_admin'],
    delete_users:         ['admin', 'global_admin'],

    /* Database + operations */
    view_admin_dashboard: ['admin', 'global_admin'],
    view_analytics:       ['admin', 'global_admin'],
    run_db_install:       ['global_admin'],
    run_db_migrate:       ['global_admin'],
    /* global_admin only since #1590 — /manage/setup-database gates its whole
       page load on run_db_install (global_admin), so an admin could never reach
       the backup button regardless of what this said. Mirror of the PHP map. */
    run_db_backup:        ['global_admin'],
    run_db_restore:       ['global_admin'],
    drop_legacy_tables:   ['global_admin'],

    /* Content moderation */
    review_song_requests: ['editor', 'admin', 'global_admin'],

    /* Content structure — songbook/group/organisation admin surfaces */
    manage_songbooks:     ['admin', 'global_admin'],
    manage_user_groups:   ['admin', 'global_admin'],
    manage_organisations: ['admin', 'global_admin'],
    manage_credit_people: ['admin', 'global_admin'],

    /* Content gating for regular users */
    manage_content_restrictions: ['admin', 'global_admin'],
    manage_access_tiers:         ['admin', 'global_admin'],
    assign_user_tier:            ['admin', 'global_admin'],
    /* Admin-configurable feature gating (#1481 P1) — defining capabilities/
       rules is Global-Admin-only; per-tier values stay manage_access_tiers. */
    manage_feature_gating:       ['global_admin'],

    /* Card-layout personalisation (#448) */
    manage_default_card_layout: ['admin', 'global_admin'],
    customise_own_card_layout:  ['user', 'editor', 'admin', 'global_admin'],

    /* Channel access (#407) */
    access_alpha:         ['user', 'editor', 'admin', 'global_admin'],
    access_beta:          ['user', 'editor', 'admin', 'global_admin'],

    /* Licences — multi-licence + inheritance (#462). Separate from the
     * generic `manage_organisations` entitlement so licence edits can
     * be delegated without granting full org admin. */
    manage_org_licences:  ['admin', 'global_admin'],
    manage_user_licences: ['admin', 'global_admin'],
    view_licence_audit:   ['admin', 'global_admin'],

    /* Licence-compliance reporting (#317). Pulled from tblSongHistory
     * against tblSongs.Ccli, exportable as CSV for the annual CCLI
     * usage return. */
    view_ccli_report:     ['admin', 'global_admin'],

    /* Catalogue + curation surfaces (#1641 item 3).
     *
     * These thirteen existed only in the PHP map. Nothing in JS asked for them
     * yet, so the drift was LATENT — userHasEntitlement() returns false for an
     * unknown key, so the first client-side affordance gated on one of them
     * would simply never render, with no error and nothing in the console.
     * Same silent-no-op class as #1581's event names: the code looks complete
     * and the failure is an absence.
     *
     * Role lists copied verbatim from includes/entitlements.php — this map is a
     * MIRROR, not a second opinion. tests/test-entitlement-parity.js now fails
     * the build if the two diverge in either direction, so the mirror cannot
     * silently rot again. */
    manage_tags:                ['admin', 'global_admin'],
    manage_works:               ['admin', 'global_admin'],
    manage_languages:           ['admin', 'global_admin'],
    manage_external_link_types: ['admin', 'global_admin'],
    manage_duplicate_songs:     ['admin', 'global_admin'],

    /* Operations + diagnostics */
    view_activity_log:          ['admin', 'global_admin'],
    view_diagnostics:           ['global_admin'],
    manage_configuration:       ['global_admin'],
    manage_notifications:       ['global_admin'],

    /* API access (#1590 Phase D — self-serve requests, global admins approve) */
    view_api_docs:              ['editor', 'admin', 'global_admin'],
    request_api_keys:           ['admin', 'global_admin'],
    manage_api_keys:            ['global_admin'],

    /* Org self-service — deliberately open to plain users: someone must be able
       to administer their OWN organisation without holding a site-wide role. */
    manage_own_organisation:    ['user', 'editor', 'admin', 'global_admin'],

    /* Meta */
    manage_entitlements:  ['global_admin'],
};

/**
 * Does the given role hold the named entitlement?
 * @param {string} entitlement
 * @param {string|null|undefined} role
 * @returns {boolean}
 */
export function userHasEntitlement(entitlement, role) {
    if (!role) return false;
    const allowed = ENTITLEMENTS[entitlement];
    return Array.isArray(allowed) && allowed.includes(role);
}
