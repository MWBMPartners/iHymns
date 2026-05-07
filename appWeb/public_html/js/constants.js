/**
 * iHymns — Shared Constants
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Centralised localStorage key names and other shared constants.
 * All modules import keys from here to prevent key-name drift (#139).
 */

/* ── localStorage Keys ─────────────────────────────────────────────────── */

/* Core data */
export const STORAGE_FAVORITES      = 'ihymns_favorites';
export const STORAGE_SETLISTS       = 'ihymns_setlists';
export const STORAGE_HISTORY        = 'ihymns_history';
export const STORAGE_SEARCH_HISTORY = 'ihymns_search_history';
export const STORAGE_CUSTOM_TAGS    = 'ihymns_custom_tags';
export const STORAGE_OWNER_ID       = 'ihymns_owner_id';

/* User preferences */
export const STORAGE_THEME              = 'ihymns_theme';
export const STORAGE_FONT_SIZE          = 'ihymns_fontSize';
export const STORAGE_REDUCE_MOTION      = 'ihymns_reduceMotion';
export const STORAGE_REDUCE_TRANSPARENCY = 'ihymns_reduceTransparency';
export const STORAGE_TRANSITION         = 'ihymns_transition';
export const STORAGE_DEFAULT_SONGBOOK   = 'ihymns_default_songbook';
export const STORAGE_AUTO_UPDATE_SONGS  = 'ihymns_auto_update_songs';
export const STORAGE_NUMPAD_LIVE_SEARCH = 'ihymns_numpad_live_search';
export const STORAGE_SEARCH_LYRICS      = 'ihymns_search_lyrics';
export const STORAGE_DISPLAY            = 'ihymns_display';

/* Status & consent */
export const STORAGE_ANALYTICS_CONSENT  = 'ihymns_analytics_consent';
export const STORAGE_ANALYTICS_DEBUG    = 'ihymns_analytics_debug';
export const STORAGE_DISCLAIMER_ACCEPTED = 'ihymns_disclaimer_accepted';
export const STORAGE_PWA_BANNER_DISMISSED = 'ihymns_pwa_banner_dismissed';

export const STORAGE_RECENT_SONGBOOKS = 'ihymns_recent_songbooks';

/* Auth (cross-subdomain SPA session token + cached user object) */
export const STORAGE_AUTH_TOKEN         = 'ihymns_auth_token';
export const STORAGE_AUTH_USER          = 'ihymns_auth_user';

/* Accessibility — Colour Vision Deficiency mode (#319) */
export const STORAGE_CVD_MODE           = 'ihymns_cvd_mode';

/* Offline downloads — whether to also cache MIDI audio when bulk-saving songs */
export const STORAGE_OFFLINE_INCLUDE_AUDIO = 'ihymns_offline_include_audio';

/* Dynamic key prefix (appended with song ID) */
export const STORAGE_TRANSPOSE_PREFIX   = 'ihymns_transpose_';

/* ── Songbook Name Lookup ─────────────────────────────────────────────── */

/**
 * Canonical mapping of songbook abbreviation → full display name.
 * Used by songbookLabel() to render responsive names across the app.
 */
export const SONGBOOK_NAMES = {
    CP:   'Carol Praise',
    JP:   'Junior Praise',
    MP:   'Mission Praise',
    SDAH: 'Seventh-day Adventist Hymnal',
    CH:   'The Church Hymnal',
    Misc: 'Miscellaneous',
};

/**
 * Return responsive songbook label HTML showing full name by default
 * and abbreviation on narrow screens. Both are always present in the
 * DOM; CSS toggles visibility based on viewport width.
 *
 * @param {string} abbr  Songbook abbreviation (e.g. 'MP')
 * @param {string} [fullName]  Optional override for full name
 * @returns {string} HTML with both spans
 */
export function songbookLabel(abbr, fullName) {
    const full = fullName || SONGBOOK_NAMES[abbr] || abbr;
    if (full === abbr) return abbr; /* no full name available */
    return `<span class="songbook-name-full">${full}</span><span class="songbook-name-abbr">${abbr}</span>`;
}
