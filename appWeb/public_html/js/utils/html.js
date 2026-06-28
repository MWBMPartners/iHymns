/**
 * iHymns — HTML Utility Functions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared HTML helpers used across multiple modules.
 */

/**
 * Escape a string for safe insertion into HTML — text AND attribute contexts.
 *
 * SECURITY: the previous implementation used `_div.textContent = str;
 * return _div.innerHTML`, which encodes &, <, > but NOT the quote characters
 * (textContent→innerHTML leaves " and ' intact, since quotes are valid in text
 * nodes). This helper is interpolated into double-quoted HTML attributes across
 * 16 modules (title="…", data-*="…", aria-label="…"), so a curator- or
 * cross-user-controlled value containing a `"` could break out of the attribute
 * and inject an event handler (attribute-context XSS). We now escape all five
 * HTML-significant characters with an explicit regex map, making the output
 * safe in both text and double-/single-quoted attribute contexts.
 * See OWASP XSS Prevention (rules #1 & #2) and the project security audit.
 *
 * @param {string} str  The raw string to escape
 * @returns {string}    The HTML-safe string (safe in text and attribute contexts)
 */
const _HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (c) { return _HTML_ESCAPES[c]; });
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
