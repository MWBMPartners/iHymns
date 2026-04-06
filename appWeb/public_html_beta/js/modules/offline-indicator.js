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
    }

    /**
     * Initialise — bind online/offline events and show initial state.
     */
    init() {
        window.addEventListener('online', () => this.onOnline());
        window.addEventListener('offline', () => this.onOffline());

        /* Show banner immediately if already offline */
        if (!navigator.onLine) {
            this.onOffline();
        }
    }

    /** Handle going offline */
    onOffline() {
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
     * Get the count of cached song pages from the service worker RECENT_CACHE.
     * @returns {Promise<number>}
     */
    async getCachedSongCount() {
        try {
            if (!('caches' in window)) return 0;
            const cache = await caches.open('ihymns-recent-v1');
            const keys = await cache.keys();
            /* Count only song API requests */
            return keys.filter(req => req.url.includes('page=song')).length;
        } catch {
            return 0;
        }
    }
}
