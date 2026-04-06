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
