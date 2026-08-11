/**
 * iHymns — Offline Status Indicator Module (#112)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Monitors online/offline status and displays a non-intrusive banner
 * when the user loses connectivity. Shows cached song count and
 * auto-dismisses "Back online" message when reconnected.
 */

import { EVT_FETCH_FAILED, EVT_FETCH_SUCCEEDED } from '../constants.js';

export class OfflineIndicator {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {HTMLElement|null} The offline banner element */
        this.banner = null;

        /** @type {number|null} Auto-dismiss timer */
        this.dismissTimer = null;

        /** @type {boolean} Current connectivity state. The fetch-succeeded /
         *  fetch-failed signals fire on EVERY search/fetch, so we only act on
         *  an actual transition — otherwise "Back online" would re-flash on
         *  each successful request (e.g. every "Load more"). (WS-I #1017) */
        this._online = true;
    }

    /**
     * Initialise — bind online/offline events and show initial state.
     *
     * `navigator.onLine` is unreliable: it returns true for any active
     * network interface, including captive portals and broken networks
     * where actual fetches fail (#354 / #524). The `online` / `offline`
     * window events are still reliable for STATE CHANGES (browsers fire
     * them when the network adapter changes), so we keep listening to
     * those — but for the INITIAL state we probe with a real fetch
     * against an endpoint we know is cheap + same-origin (manifest.json
     * — small, cached, always present).
     *
     * If the probe fails (network-level error, not HTTP error), show
     * the banner. If it succeeds, don't. If it takes longer than the
     * timeout, assume online (the banner will appear via `offline`
     * event if it really is offline).
     */
    init() {
        window.addEventListener('online', () => this.onOnline());
        window.addEventListener('offline', () => this.onOffline());

        /* Modules elsewhere (e.g. router) can dispatch this when an
           actual fetch fails with a network-level error, giving a more
           reliable signal than navigator.onLine for captive-portal /
           dead-network scenarios. */
        window.addEventListener(EVT_FETCH_FAILED, () => this.onOffline());
        window.addEventListener(EVT_FETCH_SUCCEEDED, () => this.onOnline());

        /* Initial-state probe — see docblock above. */
        this._probeConnectivity();
    }

    /**
     * Real-fetch probe to set the initial banner state.
     * Replaces the unreliable `navigator.onLine` check.
     */
    _probeConnectivity() {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 2500);
        fetch('/manifest.json', { method: 'HEAD', cache: 'no-cache', signal: controller.signal })
            .then(() => { clearTimeout(timer); /* online — no banner */ })
            .catch(err => {
                clearTimeout(timer);
                if (err && err.name === 'AbortError') return; /* slow network — assume online */
                this.onOffline();
            });
    }

    /** Handle going offline */
    onOffline() {
        if (!this._online) return; /* already offline — ignore repeat signals */
        this._online = false;
        clearTimeout(this.dismissTimer);
        this.ensureBanner();

        this.getCachedSongCount().then(count => {
            const countText = count > 0
                ? `${count} recently viewed song${count !== 1 ? 's' : ''} available offline.`
                : 'Some pages may not be available.';

            this.banner.innerHTML = `
                <div class="offline-banner-content">
                    <i class="fa-solid fa-wifi-slash me-2" aria-hidden="true"></i>
                    <span>You're offline &mdash; ${countText}</span>
                </div>`;
            this.banner.className = 'offline-banner offline-banner-offline';
            this.banner.setAttribute('aria-hidden', 'false');
        });
    }

    /** Handle coming back online */
    onOnline() {
        if (this._online) return; /* already online — ignore stray success signals (e.g. every load-more) */
        this._online = true;
        clearTimeout(this.dismissTimer);
        this.ensureBanner();

        this.banner.innerHTML = `
            <div class="offline-banner-content">
                <i class="fa-solid fa-wifi me-2" aria-hidden="true"></i>
                <span>Back online</span>
            </div>`;
        this.banner.className = 'offline-banner offline-banner-online';
        this.banner.setAttribute('aria-hidden', 'false');

        /* Auto-dismiss after 3 seconds */
        this.dismissTimer = setTimeout(() => {
            this.banner.setAttribute('aria-hidden', 'true');
            this.banner.classList.add('offline-banner-hidden');
        }, 3000);
    }

    /** Create the banner element if it doesn't exist */
    ensureBanner() {
        if (this.banner) return;

        this.banner = document.createElement('div');
        this.banner.id = 'offline-banner';
        this.banner.setAttribute('role', 'status');
        this.banner.setAttribute('aria-live', 'polite');
        this.banner.setAttribute('aria-hidden', 'true');
        this.banner.className = 'offline-banner offline-banner-hidden';

        /* Insert after the header */
        const header = document.getElementById('app-header');
        if (header && header.nextSibling) {
            header.parentNode.insertBefore(this.banner, header.nextSibling);
        } else {
            document.body.prepend(this.banner);
        }
    }

    /**
     * Count cached song pages across BOTH service-worker song buckets, de-duped
     * by URL.
     *
     * #112 fix: this used to open only RECENT_CACHE ('ihymns-recent-songs') — the
     * auto-cache of recently-viewed songs. But #1597 routes DELIBERATELY-downloaded
     * songs into a separate SAVED_CACHE ('ihymns-saved-songs',
     * service-worker.js.php's SAVED_CACHE), so a user who downloaded a whole
     * songbook for offline use saw the indicator report "0 cached". Both buckets
     * must be counted. (Bucket names mirror service-worker.js.php's RECENT_CACHE /
     * SAVED_CACHE constants — the SW is a separate global scope, so they are
     * duplicated by necessity, not choice.)
     * @returns {Promise<number>}
     */
    async getCachedSongCount() {
        try {
            if (!('caches' in window)) return 0;
            const urls = new Set();
            for (const bucket of ['ihymns-recent-songs', 'ihymns-saved-songs']) {
                const cache = await caches.open(bucket);
                const keys = await cache.keys();
                keys.forEach(req => { if (req.url.includes('page=song')) urls.add(req.url); });
            }
            return urls.size;   // de-duped: a song can be in both buckets
        } catch {
            return 0;
        }
    }
}
