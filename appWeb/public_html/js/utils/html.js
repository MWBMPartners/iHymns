/**
 * iHymns — HTML Utility Functions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared HTML helpers used across multiple modules.
 */

/**
 * Escape a string for safe insertion into HTML.
 * Uses the browser's own text-node encoding to neutralise &, <, >, ", etc.
 *
 * @param {string} str  The raw string to escape
 * @returns {string}    The HTML-safe string
 */
const _div = document.createElement('div');

export function escapeHtml(str) {
    _div.textContent = str || '';
    return _div.innerHTML;
}

/**
 * Return an inline SVG verified badge if the song is verified.
 * Returns an empty string when the song is not verified or missing.
 *
 * @param {Object} song  Song object (must have `verified` boolean)
 * @returns {string}     HTML string — either the badge or ''
 */
export function verifiedBadge(song) {
    if (!song?.verified) return '';
    return '<span class="verified-badge" title="Verified lyrics" aria-label="Verified lyrics">'
        + '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        + '<circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/>'
        + '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/>'
        + '<path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg></span>';
}
