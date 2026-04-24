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
    delete_songs:         ['admin', 'global_admin'],
    bulk_edit_songs:      ['admin', 'global_admin'],
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
    run_db_backup:        ['admin', 'global_admin'],
    run_db_restore:       ['global_admin'],
    drop_legacy_tables:   ['global_admin'],

    /* Content moderation */
    review_song_requests: ['editor', 'admin', 'global_admin'],

    /* Content structure — songbook/group/organisation admin surfaces */
    manage_songbooks:     ['admin', 'global_admin'],
    manage_user_groups:   ['admin', 'global_admin'],
    manage_organisations: ['admin', 'global_admin'],

    /* Content gating for regular users */
    manage_content_restrictions: ['admin', 'global_admin'],
    manage_access_tiers:         ['admin', 'global_admin'],
    assign_user_tier:            ['admin', 'global_admin'],

    /* Channel access (#407) */
    access_alpha:         ['user', 'editor', 'admin', 'global_admin'],
    access_beta:          ['user', 'editor', 'admin', 'global_admin'],

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
