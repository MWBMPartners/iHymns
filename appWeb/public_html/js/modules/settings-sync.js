/**
 * iHymns — Cross-site settings sync, cookie transport (#2143)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When you change a display setting (theme, text size ...) on one iHymns site,
 * this saves a small note in a cookie that all of iHymns's sites can read.
 * When you open another site, it reads the note and adopts any setting that
 * is newer than its own. Only the settings in the registry can ever be in the
 * note: never favourites, set lists, history or anything that identifies you.
 *
 * DETAILED
 * --------
 * REPLACES `subdomain-sync.js` once the per-channel switch is on (that switch
 * is `includes/cross_site_sync.php`, sent to the page as
 * `config.crossSiteSync.enabled`). While the switch is off, the old module runs
 * exactly as before and this one is never created.
 *
 * Why a cookie and not a hidden frame: every real iHymns site (ihymns.app,
 * www., beta., dev.) is the same "site" to a browser, so one cookie on
 * `.ihymns.app` reaches them all, is available before the page paints (no
 * flash of the wrong theme), and needs no extra download or security-header
 * exception. `*.ihymns.net` only redirects to the matching `*.ihymns.app`
 * address, so nothing runs there that could use it.
 *
 * THE NOTE: cookie `ihymns_prefs`, value = URL-encoded JSON
 *   {"v":2,"k":{"<setting key>":[<value or null>,<stamp>]}}
 * Domain `.ihymns.app`, Path `/`, one year, SameSite=Lax, Secure on https.
 * The merge rules, the safe parsing and the size limit are in
 * settings-sync-core.js; the list of allowed settings is in
 * settings-sync-registry.js.
 *
 * STAMPS: each site keeps, in its own localStorage, when each shared setting
 * last changed ON THIS SITE (`ihymns_xsite_stamps`). That record is device-local
 * and never travels. A setting with a value but no stamp counts as stamp 0
 * ("older than anything newer"); a setting with a stamp but no value is a
 * deletion (a tombstone). Only settings the person has set (or reset) are ever
 * written to the cookie; defaults never are.
 *
 * ONE-TIME IMPORT: the old `ihymns_sync` cookie is read once, to carry over
 * any value this site has no copy of yet (stamp 0), then never again
 * (`ihymns_xsite_legacy_imported`).
 *
 * The browser window is passed in (default: the real one) so a test can run
 * two simulated sites against one shared cookie jar.
 *
 * @see js/modules/settings-sync-core.js        merge rules + safe parsing
 * @see js/modules/settings-sync-registry.js    which settings may travel
 * @see includes/cross_site_sync.php             the per-channel on/off switch
 * @see .claude/settings-sync-2143-plan.md       section 4, steps 5 and 6
 * @link https://developer.mozilla.org/docs/Web/API/Document/cookie
 */

import {
    STORAGE_XSITE_STAMPS,
    STORAGE_XSITE_LEGACY_IMPORTED,
    EVT_XSITE_SETTINGS_APPLIED,
} from '../constants.js';
import { CROSS_SITE_KEYS, CROSS_SITE_SETTINGS, isShareableSetting } from './settings-sync-registry.js';
import { decodeEnvelope, encodeEnvelope, mergeEntries, nextStamp, highestStamp } from './settings-sync-core.js';

/** The shared cookie's name. The privacy page and tests must use this exact name (guarded). */
export const CROSS_SITE_COOKIE = 'ihymns_prefs';

/** The OLD cookie (subdomain-sync.js), read once for the one-time import. */
const LEGACY_COOKIE = 'ihymns_sync';

/** One year, in seconds. Rewritten on every visit, so it renews while a visitor keeps coming back. */
const MAX_AGE_SECONDS = 365 * 24 * 60 * 60;

export class CrossSiteSync {
    /**
     * @param {{channel?: string, win?: Window}} [options]
     *   channel: 'alpha' | 'beta' | 'production' (informational).
     *   win: the browser window to use (default: the real one). Tests pass a simulated one.
     */
    constructor({ channel = '', win = (typeof window !== 'undefined' ? window : null) } = {}) {
        this.channel = channel;
        this.win = win;
        const host = win?.location?.hostname || '';
        /** @type {string|null} `.ihymns.app` on an iHymns site, otherwise null (inactive). */
        this.cookieDomain = (host === 'ihymns.app' || host.endsWith('.ihymns.app')) ? '.ihymns.app' : null;
        this.active = this.cookieDomain !== null;
        this._visibilityBound = false;
    }

    /* ── Public ── */

    /**
     * Read the shared cookie, adopt anything newer, and publish anything of
     * ours that is newer. Call once at start-up, BEFORE the settings are applied.
     *
     * @returns {boolean} True if any local setting was changed by what the cookie held.
     */
    init() {
        if (!this.active) return false;
        this._importLegacyOnce();
        const changed = this._reconcile(true);
        this._bindVisibility();
        return changed;
    }

    /**
     * A shared setting just changed on this site (the person chose a value, or
     * we adopted one). Stamp it and publish. Keys outside the registry are ignored.
     *
     * @param {string} key A localStorage key.
     */
    recordLocalChange(key) {
        if (!this.active || !CROSS_SITE_SETTINGS.has(key)) return;
        const stamps = this._readStamps();
        const remote = this._readRemote();
        stamps.set(key, nextStamp(Math.max(0, ...stamps.values(), highestStamp(remote))));
        this._writeStamps(stamps);
        this._reconcile(false);
    }

    /**
     * Settings were removed on this site (a reset). Record a deletion for each
     * so other sites remove them too instead of handing the old values back.
     *
     * @param {readonly string[]} [keys] Defaults to every shared setting.
     */
    recordRemoval(keys = CROSS_SITE_KEYS) {
        if (!this.active) return;
        const stamps = this._readStamps();
        const remote = this._readRemote();
        let high = Math.max(0, ...stamps.values(), highestStamp(remote));
        for (const key of keys) {
            if (!CROSS_SITE_SETTINGS.has(key)) continue;
            high = nextStamp(high);
            stamps.set(key, high);
        }
        this._writeStamps(stamps);
        this._reconcile(false);
    }

    /* ── Private: reconcile ── */

    /**
     * Compare this site with the cookie and bring them level.
     *
     * @param {boolean} apply Adopt newer cookie values into local storage? (False
     *   when we are only publishing a change we just made.)
     * @returns {boolean} True if local storage was changed.
     * @private
     */
    _reconcile(apply) {
        const remote = this._readRemote();
        const stamps = this._readStamps();
        const local = this._buildLocal(stamps);
        const { applyLocal, writeRemote } = mergeEntries(local, remote);

        const changedKeys = [];
        if (apply) {
            for (const [key, [value, stamp]] of applyLocal) {
                try {
                    if (value === null) this.win.localStorage.removeItem(key);
                    else this.win.localStorage.setItem(key, value);
                    stamps.set(key, stamp);
                    changedKeys.push(key);
                } catch { /* private mode / storage full: skip this setting */ }
            }
            if (changedKeys.length) this._writeStamps(stamps);
        }

        /* What the cookie should hold now: what it had, plus anything of ours that is newer. */
        const finalRemote = new Map(remote);
        for (const [key, entry] of writeRemote) finalRemote.set(key, entry);
        if (finalRemote.size > 0) this._writeRemote(finalRemote);

        if (changedKeys.length) {
            try {
                /* The standard shape on purpose: tests/test-event-names.js looks for
                   `dispatchEvent(new CustomEvent(EVT_…` so every event has a sender and a listener. */
                this.win.document.dispatchEvent(new CustomEvent(EVT_XSITE_SETTINGS_APPLIED, { detail: { keys: changedKeys } }));
            } catch { /* no CustomEvent: the next page load picks it up anyway */ }
        }
        return changedKeys.length > 0;
    }

    /**
     * This site's entries: the live value (null if absent) with its stamp. A key with
     * neither a value nor a stamp has no entry (defaults are never written).
     * @private
     */
    _buildLocal(stamps) {
        const local = new Map();
        for (const key of CROSS_SITE_KEYS) {
            let value = null;
            try { value = this.win.localStorage.getItem(key); } catch { /* ignore */ }
            const stamp = stamps.get(key) ?? 0;
            if (value === null && stamp === 0) continue;
            if (value !== null && !isShareableSetting(key, value)) continue;   /* a value we would never accept */
            local.set(key, [value, stamp]);
        }
        return local;
    }

    /* ── Private: the shared cookie ── */

    /** @private */
    _readRemote() {
        return decodeEnvelope(this._readCookieValue(CROSS_SITE_COOKIE));
    }

    /** @private */
    _readCookieValue(name) {
        try {
            for (const part of this.win.document.cookie.split(';')) {
                const eq = part.indexOf('=');
                if (eq > 0 && part.slice(0, eq).trim() === name) return part.slice(eq + 1).trim();
            }
        } catch { /* cookies blocked */ }
        return null;
    }

    /** @private */
    _writeRemote(map) {
        const encoded = encodeEnvelope(map);
        if (encoded === null) return;   /* too long to store safely: skip */
        const parts = [
            `${CROSS_SITE_COOKIE}=${encoded}`,
            `Domain=${this.cookieDomain}`,
            'Path=/',
            `Max-Age=${MAX_AGE_SECONDS}`,
            'SameSite=Lax',
        ];
        if (this.win.location.protocol === 'https:') parts.push('Secure');
        try { this.win.document.cookie = parts.join('; '); } catch { /* cookies blocked */ }
    }

    /* ── Private: this site's own stamps (device-local, never shared) ── */

    /** @private */
    _readStamps() {
        const stamps = new Map();
        try {
            const parsed = JSON.parse(this.win.localStorage.getItem(STORAGE_XSITE_STAMPS) || 'null');
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                for (const key of CROSS_SITE_KEYS) {
                    if (Object.hasOwn(parsed, key) && Number.isInteger(parsed[key]) && parsed[key] > 0) stamps.set(key, parsed[key]);
                }
            }
        } catch { /* missing or damaged: start empty */ }
        return stamps;
    }

    /** @private */
    _writeStamps(stamps) {
        try {
            const obj = {};
            for (const [key, stamp] of stamps) obj[key] = stamp;
            this.win.localStorage.setItem(STORAGE_XSITE_STAMPS, JSON.stringify(obj));
        } catch { /* private mode: stamps are lost, which only makes this site look older */ }
    }

    /* ── Private: one-time import of the old cookie ── */

    /** @private */
    _importLegacyOnce() {
        try {
            if (this.win.localStorage.getItem(STORAGE_XSITE_LEGACY_IMPORTED) === '1') return;
            const raw = this._readCookieValue(LEGACY_COOKIE);
            if (raw) {
                let old = null;
                try { old = JSON.parse(decodeURIComponent(raw)); } catch { old = null; }
                if (old && typeof old === 'object' && !Array.isArray(old)) {
                    for (const key of CROSS_SITE_KEYS) {
                        if (!Object.hasOwn(old, key)) continue;
                        if (this.win.localStorage.getItem(key) !== null) continue;   /* we already have one */
                        if (isShareableSetting(key, old[key])) this.win.localStorage.setItem(key, old[key]);
                    }
                }
            }
            this.win.localStorage.setItem(STORAGE_XSITE_LEGACY_IMPORTED, '1');
        } catch { /* private mode: it will be tried again next visit, which is harmless */ }
    }

    /* ── Private: pick up changes made on another site while this tab was in the background ── */

    /** @private */
    _bindVisibility() {
        if (this._visibilityBound) return;
        this._visibilityBound = true;
        try {
            this.win.document.addEventListener('visibilitychange', () => {
                if (this.win.document.visibilityState === 'visible') this._reconcile(true);
            });
        } catch { /* ignore */ }
    }
}
