/**
 * iHymns — Analytics Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Unified event tracking across multiple analytics platforms (GA4,
 * Plausible, Microsoft Clarity, custom endpoint). Provides pre-defined
 * methods for common user actions and respects DNT / consent settings.
 *
 * ARCHITECTURE:
 * - All events funnel through trackEvent() which dispatches to each
 *   enabled platform.
 * - DNT mode anonymises events by stripping identifiable IDs.
 * - Debug mode logs all events to the console for development.
 * - Session engagement is tracked via a periodic heartbeat.
 * - Scroll depth is tracked on song pages at 25% thresholds.
 */

import { STORAGE_ANALYTICS_DEBUG } from '../constants.js';

export class Analytics {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number|null} Session start timestamp */
        this.sessionStart = null;

        /** @type {number|null} Engagement heartbeat interval ID */
        this.engagementInterval = null;

        /** @type {boolean} Whether analytics consent has been granted */
        this.consentGranted = true;

        /** @type {boolean} Whether Do Not Track is active */
        this.dntActive = false;

        /** @type {boolean} Whether debug logging is enabled */
        this.debug = false;

        /** @type {Set<number>} Scroll depth thresholds already reported */
        this.scrollDepthReported = new Set();

        /** @type {function|null} Bound scroll handler reference for cleanup */
        this._scrollHandler = null;
    }

    /**
     * Initialise analytics — check consent, start session, bind listeners.
     */
    init() {
        /* Check debug mode */
        this.debug = !!localStorage.getItem(STORAGE_ANALYTICS_DEBUG);

        /* Check Do Not Track */
        this.dntActive = navigator.doNotTrack === '1' ||
            navigator.doNotTrack === 'yes' ||
            window.doNotTrack === '1';

        /* Check external consent flag */
        if (typeof window.__analyticsConsent !== 'undefined') {
            this.consentGranted = !!window.__analyticsConsent;
        }

        /* Start session tracking */
        this.startSession();

        /* Start engagement heartbeat (every 30 seconds) */
        this.engagementInterval = setInterval(() => this.trackEngagement(), 30000);

        /* Track scroll depth on song pages */
        this._scrollHandler = this._handleScroll.bind(this);
        window.addEventListener('scroll', this._scrollHandler, { passive: true });

        this._log('init', 'Analytics initialised', { dnt: this.dntActive, consent: this.consentGranted });
    }

    /* =====================================================================
     * CORE — Unified event dispatch
     * ===================================================================== */

    /**
     * Send a tracking event to all enabled analytics platforms.
     *
     * @param {string} category Event category (e.g. 'song', 'search')
     * @param {string} action   Event action (e.g. 'view', 'search')
     * @param {string} [label]  Optional event label
     * @param {number} [value]  Optional numeric value
     */
    trackEvent(category, action, label, value) {
        if (!this.consentGranted) return;

        /* Anonymise when DNT is active — strip specific identifiers */
        const safeLabel = this.dntActive ? undefined : label;
        const safeValue = value;

        this._log('event', `${category}/${action}`, { label: safeLabel, value: safeValue });

        /* Google Analytics 4 */
        if (typeof window.gtag === 'function') {
            window.gtag('event', action, {
                event_category: category,
                event_label: safeLabel,
                value: safeValue,
            });
        }

        /* Plausible Analytics */
        if (typeof window.plausible === 'function') {
            window.plausible(action, {
                props: {
                    category,
                    label: safeLabel,
                    value: safeValue,
                },
            });
        }

        /* Microsoft Clarity */
        if (typeof window.clarity === 'function') {
            window.clarity('event', action);
        }

        /* Custom analytics endpoint */
        const endpoint = this.app.config?.analytics?.custom_endpoint;
        if (endpoint) {
            this._sendToEndpoint(endpoint, {
                category,
                action,
                label: safeLabel,
                value: safeValue,
                timestamp: Date.now(),
                sessionStart: this.sessionStart,
            });
        }
    }

    /**
     * Track a page view across all platforms.
     *
     * @param {string} path  Page path
     * @param {string} title Page title
     */
    trackPageView(path, title) {
        if (!this.consentGranted) return;

        /* Reset scroll depth tracking for new page */
        this.scrollDepthReported.clear();

        this._log('pageview', path, { title });

        /* Google Analytics 4 */
        if (typeof window.gtag === 'function') {
            window.gtag('event', 'page_view', {
                page_path: path,
                page_title: title,
            });
        }

        /* Plausible tracks page views automatically but we can send
         * a custom pageview for SPA navigation */
        if (typeof window.plausible === 'function') {
            window.plausible('pageview', { u: window.location.origin + path });
        }

        /* Clarity tracks page views automatically */
    }

    /* =====================================================================
     * PRE-DEFINED EVENTS — Common user actions
     * ===================================================================== */

    /**
     * Track when a song is viewed.
     * @param {string} songId   Song identifier
     * @param {string} songbook Songbook name
     * @param {string} title    Song title
     */
    trackSongView(songId, songbook, title) {
        this.trackEvent('song', 'view', this.dntActive ? songbook : songId, undefined);
    }

    /**
     * Track when a search is performed.
     * @param {string} query       Search query
     * @param {number} resultCount Number of results returned
     */
    trackSearch(query, resultCount) {
        /* Never send the raw query when DNT is active */
        const safeQuery = this.dntActive ? '[redacted]' : query;
        this.trackEvent('search', 'search', safeQuery, resultCount);
    }

    /**
     * Track when a song is favourited or unfavourited.
     * @param {string}  songId Song identifier
     * @param {boolean} added  True if added, false if removed
     */
    trackFavoriteToggle(songId, added) {
        this.trackEvent('favorite', added ? 'add' : 'remove', this.dntActive ? undefined : songId);
    }

    /**
     * Track when a song is shared.
     * @param {string} songId Song identifier
     * @param {string} method Share method (native/copy/link)
     */
    trackShare(songId, method) {
        this.trackEvent('share', method, this.dntActive ? undefined : songId);
    }

    /**
     * Track set list actions.
     * @param {string} action Action type (create/delete/share)
     * @param {string} listId Set list identifier
     */
    trackSetlistAction(action, listId) {
        this.trackEvent('setlist', action, this.dntActive ? undefined : listId);
    }

    /**
     * Track when the theme is changed.
     * @param {string} theme Theme name (light/dark/system/high-contrast)
     */
    trackThemeChange(theme) {
        this.trackEvent('settings', 'theme_change', theme);
    }

    /**
     * Track PWA install prompt outcome.
     * @param {string} outcome Outcome (accepted/dismissed)
     */
    trackPwaInstall(outcome) {
        this.trackEvent('pwa', 'install_prompt', outcome);
    }

    /**
     * Track offline content downloads.
     * @param {string} type       Download type (songbook/all)
     * @param {string} songbookId Songbook identifier
     */
    trackOfflineDownload(type, songbookId) {
        this.trackEvent('offline', 'download', songbookId, undefined);
    }

    /**
     * Track navigation between songs.
     * @param {string} method Navigation method (swipe/arrow/button)
     */
    trackSongNavigation(method) {
        this.trackEvent('navigation', 'song_navigate', method);
    }

    /* =====================================================================
     * SESSION & ENGAGEMENT
     * ===================================================================== */

    /**
     * Start a new session — records the session start time.
     */
    startSession() {
        this.sessionStart = Date.now();
        this.trackEvent('session', 'start');
    }

    /**
     * Track engagement heartbeat — called every 30 seconds.
     * Sends cumulative time-on-site to analytics.
     */
    trackEngagement() {
        if (!this.sessionStart || !this.consentGranted) return;

        const elapsed = Math.round((Date.now() - this.sessionStart) / 1000);
        this.trackEvent('engagement', 'heartbeat', undefined, elapsed);
    }

    /* =====================================================================
     * SCROLL DEPTH
     * ===================================================================== */

    /**
     * Handle scroll events — track depth on song pages.
     * @private
     */
    _handleScroll() {
        /* Only track on song pages */
        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        if (docHeight <= 0) return;

        const percent = Math.round((scrollTop / docHeight) * 100);
        const thresholds = [25, 50, 75, 100];

        for (const threshold of thresholds) {
            if (percent >= threshold && !this.scrollDepthReported.has(threshold)) {
                this.scrollDepthReported.add(threshold);
                this.trackEvent('engagement', 'scroll_depth', `${threshold}%`, threshold);
            }
        }
    }

    /* =====================================================================
     * CONSENT & DNT
     * ===================================================================== */

    /**
     * Update consent status at runtime.
     * @param {boolean} granted True if consent is granted
     */
    setConsent(granted) {
        this.consentGranted = !!granted;
        window.__analyticsConsent = this.consentGranted;
        this._log('consent', granted ? 'granted' : 'revoked');
    }

    /* =====================================================================
     * INTERNAL HELPERS
     * ===================================================================== */

    /**
     * Send event data to a custom analytics endpoint via POST.
     * Uses sendBeacon with fetch fallback for reliability.
     *
     * @param {string} endpoint URL to POST to
     * @param {object} data     Event payload
     * @private
     */
    _sendToEndpoint(endpoint, data) {
        const json = JSON.stringify(data);

        /* Prefer sendBeacon for reliability during page unload */
        if (navigator.sendBeacon) {
            const blob = new Blob([json], { type: 'application/json' });
            const sent = navigator.sendBeacon(endpoint, blob);
            if (sent) return;
        }

        /* Fallback to fetch */
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: json,
            keepalive: true,
        }).catch(() => {
            /* Silently ignore network errors for analytics */
        });
    }

    /**
     * Log analytics events to the console when debug mode is active.
     *
     * @param {string} type    Event type (event/pageview/init/consent)
     * @param {string} message Description
     * @param {object} [data]  Optional extra data
     * @private
     */
    _log(type, message, data) {
        if (!this.debug) return;
        const prefix = `[Analytics:${type}]`;
        if (data) {
            console.log(prefix, message, data);
        } else {
            console.log(prefix, message);
        }
    }

    /**
     * Clean up — stop heartbeat and remove scroll listener.
     */
    destroy() {
        if (this.engagementInterval) {
            clearInterval(this.engagementInterval);
            this.engagementInterval = null;
        }
        if (this._scrollHandler) {
            window.removeEventListener('scroll', this._scrollHandler);
            this._scrollHandler = null;
        }
    }
}
