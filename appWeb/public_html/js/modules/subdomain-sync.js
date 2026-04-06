/**
 * iHymns — Subdomain Cookie Sync (#133)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shares lightweight user settings across all *.ihymns.app subdomains
 * using a shared cookie with domain=.ihymns.app.
 *
 * This provides instant, reliable sync between beta.ihymns.app,
 * alpha.ihymns.app, ihymns.app, etc. without iframes or servers.
 *
 * WHAT SYNCS (small settings — fits in ~2KB cookie):
 *   - Theme, font size, reduce motion, reduce transparency
 *   - Default songbook, transition style, auto-update pref
 *   - Lyrics search toggle, display preferences
 *
 * WHAT DOES NOT SYNC (too large for cookies):
 *   - Favourites, set lists, history, search history
 *   - These use the iframe StorageBridge instead
 *
 * COOKIE DETAILS:
 *   - Name: ihymns_sync
 *   - Domain: .ihymns.app (shared across all subdomains)
 *   - Path: /
 *   - Max-Age: 1 year
 *   - SameSite: Lax (allows cross-subdomain reads)
 *   - Secure: yes (HTTPS only in production)
 */

import {
    STORAGE_THEME,
    STORAGE_FONT_SIZE,
    STORAGE_REDUCE_MOTION,
    STORAGE_REDUCE_TRANSPARENCY,
    STORAGE_DEFAULT_SONGBOOK,
    STORAGE_TRANSITION,
    STORAGE_AUTO_UPDATE_SONGS,
    STORAGE_SEARCH_LYRICS,
    STORAGE_DISPLAY,
} from '../constants.js';

export class SubdomainSync {
    constructor() {
        /** @type {string} Cookie name */
        this.cookieName = 'ihymns_sync';

        /** @type {number} Cookie max age in seconds (1 year) */
        this.maxAge = 365 * 24 * 60 * 60;

        /**
         * Keys eligible for cookie sync (lightweight settings only).
         * Large data like favourites/setlists are excluded.
         */
        this.syncKeys = [
            STORAGE_THEME,
            STORAGE_FONT_SIZE,
            STORAGE_REDUCE_MOTION,
            STORAGE_REDUCE_TRANSPARENCY,
            STORAGE_DEFAULT_SONGBOOK,
            STORAGE_TRANSITION,
            STORAGE_AUTO_UPDATE_SONGS,
            STORAGE_SEARCH_LYRICS,
            STORAGE_DISPLAY,
        ];

        /** @type {string|null} The shared cookie domain, or null if not on ihymns.app */
        this.cookieDomain = this._detectCookieDomain();

        /** @type {boolean} Whether subdomain sync is active */
        this.active = this.cookieDomain !== null;
    }

    /**
     * Initialise — read the shared cookie and merge into localStorage.
     * Called once on app startup.
     *
     * @returns {boolean} True if any local values were updated from the cookie
     */
    init() {
        if (!this.active) return false;

        const cookieData = this._readCookie();
        if (!cookieData) return false;

        let updated = false;

        for (const [key, value] of Object.entries(cookieData)) {
            /* Only accept known sync keys */
            if (!this.syncKeys.includes(key)) continue;

            const localValue = localStorage.getItem(key);

            /* If local storage is empty but cookie has a value, pull it in */
            if (localValue === null && value !== null) {
                localStorage.setItem(key, value);
                updated = true;
            }
        }

        /* Also push any local values that aren't in the cookie yet */
        this._writeCookie();

        return updated;
    }

    /**
     * Sync a key to the shared cookie.
     * Call this after any localStorage write for an eligible key.
     *
     * @param {string} key The localStorage key
     */
    sync(key) {
        if (!this.active) return;
        if (!this.syncKeys.includes(key)) return;
        this._writeCookie();
    }

    /**
     * Write all eligible settings from localStorage to the shared cookie.
     * @private
     */
    _writeCookie() {
        const data = {};

        for (const key of this.syncKeys) {
            const value = localStorage.getItem(key);
            if (value !== null) {
                data[key] = value;
            }
        }

        const json = JSON.stringify(data);

        /* Safety check: cookies should stay under 4KB */
        if (json.length > 3500) {
            console.warn('[SubdomainSync] Cookie data too large, skipping write');
            return;
        }

        const parts = [
            `${this.cookieName}=${encodeURIComponent(json)}`,
            `domain=${this.cookieDomain}`,
            `path=/`,
            `max-age=${this.maxAge}`,
            `SameSite=Lax`,
        ];

        /* Use Secure flag on HTTPS */
        if (location.protocol === 'https:') {
            parts.push('Secure');
        }

        document.cookie = parts.join('; ');
    }

    /**
     * Read and parse the shared cookie.
     * @private
     * @returns {Object|null} Parsed settings object or null
     */
    _readCookie() {
        try {
            const cookies = document.cookie.split(';');
            for (const cookie of cookies) {
                const [name, ...valueParts] = cookie.trim().split('=');
                if (name === this.cookieName) {
                    const value = valueParts.join('=');
                    return JSON.parse(decodeURIComponent(value));
                }
            }
        } catch {
            /* Malformed cookie — ignore */
        }
        return null;
    }

    /**
     * Detect the cookie domain for subdomain sharing.
     * Returns .ihymns.app if on any ihymns.app subdomain, null otherwise.
     * @private
     * @returns {string|null}
     */
    _detectCookieDomain() {
        const hostname = location.hostname;

        /* Match ihymns.app or any subdomain (beta.ihymns.app, etc.) */
        if (hostname === 'ihymns.app' || hostname.endsWith('.ihymns.app')) {
            return '.ihymns.app';
        }

        /* Localhost/dev — use no domain (local only) for testing */
        if (hostname === 'localhost' || hostname === '127.0.0.1') {
            return null;
        }

        /* ihymns.net or other domains — cookie sync not available,
         * rely on iframe bridge instead */
        return null;
    }

    /**
     * Clear the shared cookie (used on settings reset).
     */
    clear() {
        if (!this.active) return;
        document.cookie = `${this.cookieName}=; domain=${this.cookieDomain}; path=/; max-age=0`;
    }
}
