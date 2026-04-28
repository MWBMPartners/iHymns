/**
 * iHymns — SPA Router Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Manages client-side routing using the History API (pushState).
 * Intercepts navigation clicks, loads page content via AJAX from
 * the PHP API, and manages page transitions.
 *
 * Clean URLs: All navigation uses paths like /song/CP-0001 instead
 * of hash-based routes. The server (.htaccess) rewrites all paths
 * to index.php, and this router handles the rest client-side.
 */

import { toTitleCase } from '../utils/text.js';
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { userHasEntitlement } from './entitlements.js';
import {
    STORAGE_FAVORITES,
    STORAGE_SETLISTS,
    STORAGE_HISTORY,
    STORAGE_SEARCH_HISTORY,
    STORAGE_RECENT_SONGBOOKS,
    songbookLabel,
} from '../constants.js';

export class Router {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;
        this.config = app.config;

        /** @type {string} The API base URL for AJAX requests */
        this.apiUrl = this.config.apiUrl || '/api';

        /** @type {string|null} Currently active route path */
        this.currentPath = null;

        /** @type {AbortController|null} For cancelling in-flight AJAX requests */
        this.abortController = null;
    }

    /**
     * Initialise the router — listen for popstate (back/forward) events.
     */
    init() {
        /* Handle magic link login before any routing (#magic-link) */
        this._handleMagicLink();

        /* Handle browser back/forward navigation */
        window.addEventListener('popstate', () => {
            this.handleCurrentRoute();
        });
    }

    /**
     * Navigate to a new URL path.
     * Pushes the new state to the browser history and loads the page.
     *
     * @param {string} path URL path to navigate to (e.g., '/song/CP-0001')
     */
    async navigate(path) {
        /* Normalise path */
        path = path || '/';
        if (path !== '/' && path.endsWith('/')) {
            path = path.slice(0, -1);
        }

        /* Don't reload if already on this path */
        if (path === this.currentPath) return;

        /* Push new state to browser history */
        window.history.pushState({ path }, '', path);

        /* Load the page content */
        await this.handleCurrentRoute();
    }

    /**
     * Handle the current URL route — determine which page to load
     * and fetch its content from the API.
     */
    async handleCurrentRoute() {
        const path = window.location.pathname || '/';
        this.currentPath = path;

        /* Parse the route into an API request */
        const { page, params } = this.parseRoute(path);

        /* For song pages, replace the URL with the canonical zero-padded form
         * so that /song/MP-1 silently becomes /song/MP-0001 in the address bar.
         * This ensures consistent URLs for bookmarking, sharing, and SEO. */
        if (page === 'song' && params.id) {
            const canonicalPath = `/song/${params.id}`;
            if (canonicalPath !== path) {
                window.history.replaceState({ path: canonicalPath }, '', canonicalPath);
                this.currentPath = canonicalPath;
            }
        }

        /* Login route: show auth modal instead of loading a page from API */
        if (page === 'login') {
            const token = new URLSearchParams(window.location.search).get('token');
            if (token) {
                /* Magic link with token — handled by _handleMagicLink() on init.
                 * If we reach here via navigate(), handle it now. */
                this._verifyMagicLinkToken(token);
            } else {
                /* No token — show the auth modal and go home */
                this.app.userAuth?.showAuthModal('login');
                window.history.replaceState({ path: '/' }, '', '/');
                this.currentPath = '/';
                await this.handleCurrentRoute();
            }
            return;
        }

        /* Update the active footer nav item */
        this.updateActiveNav(page);

        /* Update the document title */
        this.updateTitle(page, params);

        /* Track page view in analytics */
        this.app.trackPageView(path, document.title);

        /* Build the API URL for fetching the page content */
        const apiUrl = this.buildApiUrl(page, params);

        /* Load the page via AJAX with transitions */
        await this.loadPage(apiUrl);

        /* Clean up previous page state (#95) */
        this.app.display.cleanup();
        this.app.readingProgress.cleanup();

        /* Run post-load hooks (e.g., initialise favourites on song pages) */
        this.afterPageLoad(page, params);

        /* Scroll to top on navigation */
        document.getElementById('main-content')?.scrollTo(0, 0);
        window.scrollTo(0, 0);
    }

    /**
     * Parse a URL path into a page name and parameters.
     *
     * @param {string} path URL path (e.g., '/song/CP-0001')
     * @returns {{ page: string, params: object }}
     */
    parseRoute(path) {
        /* Remove leading slash and split into segments */
        const segments = path.replace(/^\//, '').split('/').filter(Boolean);

        if (segments.length === 0) {
            return { page: 'home', params: {} };
        }

        switch (segments[0]) {
            case 'songbook':
                return { page: 'songbook', params: { id: segments[1] || '' } };
            case 'songbooks':
                return { page: 'songbooks', params: {} };
            case 'song':
                return { page: 'song', params: { id: this.normalizeSongId(segments[1] || '') } };
            case 'search':
                return { page: 'search', params: {} };
            case 'favorites':
            case 'favourites':
                return { page: 'favorites', params: {} };
            case 'setlist':
            case 'setlists':
                if (segments[1] === 'shared' && segments[2]) {
                    return { page: 'setlist-shared', params: { data: segments[2] } };
                }
                return { page: 'setlist', params: {} };
            case 'settings':
                return { page: 'settings', params: {} };
            case 'stats':
            case 'statistics':
                return { page: 'stats', params: {} };
            case 'writer':
                return { page: 'writer', params: { id: segments[1] || '' } };
            case 'people':
            case 'person':
                /* Credit Person public page (#588). Both /people/<slug>
                   and /person/<slug> resolve to the same page so the URL
                   is forgiving — Wikipedia-style /wiki/Foo + linked-data
                   habits both work. */
                return { page: 'person', params: { slug: segments[1] || '' } };
            case 'help':
                return { page: 'help', params: {} };
            case 'terms':
                return { page: 'terms', params: {} };
            case 'privacy':
                return { page: 'privacy', params: {} };
            case 'request-a-song':
                return { page: 'request-a-song', params: {} };
            case 'login':
                return { page: 'login', params: {} };
            default:
                return { page: 'not-found', params: {} };
        }
    }

    /**
     * Normalise a song ID to its canonical zero-padded format.
     *
     * Accepts flexible formats like 'MP-1', 'MP-01', 'MP-001' and
     * normalises them to the canonical 4-digit padded form 'MP-0001'.
     * This ensures consistent URLs for SEO and caching.
     *
     * @param {string} id Song ID in any format (e.g., 'MP-1', 'mp-01')
     * @returns {string} Canonical ID (e.g., 'MP-0001') or original if not parseable
     */
    normalizeSongId(id) {
        if (!id) return id;

        /* Match pattern: letters, hyphen, digits */
        const match = id.match(/^([A-Za-z]+)-0*(\d+)$/);
        if (!match) return id;

        const prefix = match[1].toUpperCase();
        const number = match[2];

        /* Pad the number to 4 digits (the canonical format) */
        const padded = number.padStart(4, '0');
        return `${prefix}-${padded}`;
    }

    /**
     * Build the AJAX API URL for fetching page content.
     *
     * @param {string} page Page name
     * @param {object} params Route parameters
     * @returns {string} Full API URL
     */
    buildApiUrl(page, params) {
        const url = new URL(this.apiUrl, window.location.origin);
        url.searchParams.set('page', page);

        /* Add route-specific parameters */
        if (params.id) {
            url.searchParams.set('id', params.id);
        }
        /* Person page (#588) carries `slug`, not `id`. */
        if (params.slug) {
            url.searchParams.set('slug', params.slug);
        }

        return url.toString();
    }

    /**
     * Load a page via AJAX and inject it into the content area.
     *
     * @param {string} url API URL to fetch
     */
    async loadPage(url) {
        const content = document.getElementById('page-content');
        if (!content) return;

        /* Cancel any in-flight request */
        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        try {
            /* Start loading bar and exit transition in parallel */
            this.app.transitions.startLoading();
            await this.app.transitions.pageOut(content);

            /* Fetch the page content from the API */
            const response = await fetch(url, {
                signal: this.abortController.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();

            /* Inject the new content */
            content.innerHTML = html;

            /* Browsers intentionally do NOT run <script> tags inserted via
               innerHTML, so any inline JS in the injected page template
               (e.g. home.php's Popular Songs / Browse by Theme / Recently
               Viewed fetches) silently no-ops. Replace each script node
               with a freshly-created one so the browser parses and runs
               it as if it had been in the original document. Preserves
               type, src, async/defer and other attributes. */
            this._executeInlineScripts(content);

            /* Complete loading bar and start enter transition */
            this.app.transitions.completeLoading();
            await this.app.transitions.pageIn(content);

        } catch (error) {
            if (error.name === 'AbortError') {
                /* Request was cancelled — another navigation started */
                return;
            }

            console.error('[Router] Failed to load page:', error);
            this.app.transitions.completeLoading();
            content.innerHTML = `
                <div class="alert alert-danger mt-4" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                    Failed to load page. Please check your connection and try again.
                </div>`;
            this.app.transitions.pageIn(content);
        }
    }

    /**
     * Re-create every <script> descendant of `root` so the browser actually
     * executes it. `innerHTML` parses script tags but skips their execution
     * by design — any JS in an injected page template would otherwise no-op
     * silently. Re-created nodes preserve the original attributes (src,
     * type, async, defer, nomodule, integrity, crossorigin) and replace
     * the original in-place so document order is kept for side-effectful
     * scripts that depend on it.
     *
     * @param {HTMLElement} root Container whose script descendants to run
     * @private
     */
    _executeInlineScripts(root) {
        if (!root) return;
        const scripts = root.querySelectorAll('script');
        for (const oldScript of scripts) {
            const newScript = document.createElement('script');
            for (const attr of oldScript.attributes) {
                newScript.setAttribute(attr.name, attr.value);
            }
            if (!oldScript.src) {
                newScript.textContent = oldScript.textContent;
            }
            oldScript.replaceWith(newScript);
        }
    }

    /**
     * Update the active state on footer navigation items.
     *
     * @param {string} page Current page name
     */
    updateActiveNav(page) {
        document.querySelectorAll('.footer-nav-item').forEach(item => {
            const navPage = item.dataset.navigate;
            const isActive = navPage === page || (navPage === 'home' && page === 'home');
            item.classList.toggle('active', isActive);
            item.setAttribute('aria-current', isActive ? 'page' : 'false');
        });
    }

    /**
     * Update the document title based on the current page.
     *
     * @param {string} page Page name
     * @param {object} params Route parameters
     */
    updateTitle(page, params) {
        const appName = this.config.appName || 'iHymns';
        const titles = {
            'home': appName + ' — Christian Hymns & Worship Songs',
            'songbooks': 'Songbooks — ' + appName,
            'songbook': 'Songbook — ' + appName,
            'song': 'Song — ' + appName,
            'search': 'Search — ' + appName,
            'favorites': 'Favourites — ' + appName,
            'setlist': 'Set Lists — ' + appName,
            'setlist-shared': 'Shared Set List — ' + appName,
            'settings': 'Settings — ' + appName,
            'stats': 'Usage Statistics — ' + appName,
            'writer': 'Writer — ' + appName,
            'help': 'Help — ' + appName,
            'terms': 'Terms of Use — ' + appName,
            'privacy': 'Privacy Policy — ' + appName,
            'request-a-song': 'Request a Song — ' + appName,
        };
        document.title = titles[page] || appName;
    }

    /**
     * Post-load hooks — called after page content is injected.
     * Used to initialise page-specific functionality.
     *
     * @param {string} page Page name
     * @param {object} params Route parameters
     */
    afterPageLoad(page, params) {
        /* Re-bind any offline-download buttons in the freshly injected
           HTML (#453 / #454). The helper idempotently ignores nodes
           that already have handlers. */
        import('./offline-ui.js').then(m => m.bootOfflineUi()).catch(() => {});

        /* Initialise favourites state on song pages */
        if (page === 'song') {
            this.app.favorites.initSongPage();
            this.app.share.initSongPage();
            this.app.setList.initSongPage();
            this.app.setList.renderSongNavigation();
            this.app.display.initSongPage();
            this.app.compare.initSongPage();
            this.app.transpose.initSongPage();
            this.app.readingProgress.initSongPage();

            /* Audio button — hide if the browser can't actually play
               our MIDI-via-Tone.js pipeline (#602). The audio module
               feature-detects Web Audio support; if absent, every
               .btn-audio on the page is hidden so curators don't see
               a button that wouldn't work. Idempotent — safe to call
               on every navigation. */
            this.app.audio?.hideButtonsIfUnsupported?.();

            /* Edit button — show only to users whose role carries the
               `edit_songs` entitlement (#407). The PHP editor API
               re-checks the same map server-side, so hiding the button
               is purely a UX affordance. */
            const editBtn = document.getElementById('btn-edit-song');
            if (editBtn) {
                const role = this.app.userAuth?.getUser()?.role;
                if (userHasEntitlement('edit_songs', role)) {
                    editBtn.classList.remove('d-none');
                }
            }

            /* Save Offline button — check cache state and bind click */
            const saveOfflineBtn = document.querySelector('.btn-save-offline');
            if (saveOfflineBtn) {
                const songId = saveOfflineBtn.dataset.songId;
                this.app.settings.checkSongCacheStatus(songId, saveOfflineBtn);
                saveOfflineBtn.addEventListener('click', () => {
                    this.app.settings.saveSongOffline(songId, saveOfflineBtn);
                });
            }

            /* Precache this song for offline access (#105) */
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                const songApiUrl = this.buildApiUrl('song', params);
                navigator.serviceWorker.controller.postMessage({
                    type: 'CACHE_SONG',
                    url: songApiUrl,
                });
            }

            /* Record song view in history (#92) */
            const songPage = document.querySelector('.page-song');
            if (songPage) {
                const songId = songPage.dataset.songId || params.id || '';
                const titleEl = songPage.querySelector('h1');
                const title = titleEl ? titleEl.textContent.trim() : '';
                const songbook = songPage.dataset.songbook || '';
                const number = parseInt(songPage.dataset.songNumber, 10) || 0;
                if (songId) {
                    this.app.history.recordView(songId, title, songbook, number);
                    if (songbook) this.trackRecentSongbook(songbook);

                    /* Record song view on server for history/popular tracking (#287) */
                    fetch(`${this.apiUrl}?action=song_view`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ song_id: songId })
                    }).catch(() => {}); // fire-and-forget
                }

                /* Load song translations (#352) — async, non-blocking */
                this.loadTranslations(songId);

                /* Load related songs (#118) — async, non-blocking */
                this.loadRelatedSongs(songId);
            }
        }

        /* Render recently viewed section on home page (#92) */
        if (page === 'home') {
            this.app.history.renderHomeSection();
            this.app.songOfTheDay.renderHomeSection();
            this.renderRecentSongbooks();

            /* Popular Songs / Recently Viewed / Browse by Theme (#303,
               #304, #305). Previously lived as an inline <script> at the
               bottom of home.php, which relied on a re-parse shim in
               loadPage() to execute after innerHTML injection. Pulling
               it into a proper module and invoking it here removes that
               transport dependency — the sections now run reliably
               whenever the home page is shown. */
            import('./home-page.js')
                .then(m => m.initHomePage())
                .catch(err => console.error('[Router] home-page init failed:', err));
        }

        /* Initialise favourites list on favorites page */
        if (page === 'favorites') {
            this.app.favorites.loadFavoritesList();
        }

        /* Initialise settings controls on settings page */
        if (page === 'settings') {
            this.app.settings.initSettingsPage();
        }

        /* After the new page HTML is in the DOM, broadcast the current auth
           state so any just-injected markup (Account card, sync bars, etc.)
           lands in the correct logged-in/logged-out state. */
        try {
            document.dispatchEvent(new CustomEvent('ihymns:auth-changed', {
                detail: {
                    loggedIn: !!this.app.userAuth?.isLoggedIn(),
                    user: this.app.userAuth?.getUser() ?? null,
                },
            }));
        } catch { /* legacy browsers — ignore */ }

        /* Initialise set list page controls (#94) */
        if (page === 'setlist') {
            this.app.setList.initSetListPage();
        }

        /* Initialise shared set list page (#147) */
        if (page === 'setlist-shared') {
            this.app.setList.initSharedSetListPage(params.data);
        }

        /* Initialise songbook index (#111) and track visit (#121) */
        if (page === 'songbook') {
            this.app.songbookIndex.initSongbookPage();
            this.trackRecentSongbook(params.id);
        }

        /* Initialise search page controls */
        if (page === 'search') {
            this.app.search.initSearchPage();
            this.app.numpad.initSearchPageNumpad();
        }

        /* Populate usage statistics (#120) */
        if (page === 'stats') {
            this.populateStats();
        }

        /* Auto-fix badge text contrast for all songbook badges (#152) */
        this.fixBadgeContrast();
    }

    /* =====================================================================
     * MAGIC LINK LOGIN
     * ===================================================================== */

    /**
     * Check for a magic link token in the URL on page load.
     * If `?token=` is present (typically on /login?token=...), verify
     * the token with the API, store credentials, and redirect home.
     * Called once during init() before any routing occurs.
     */
    _handleMagicLink() {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('token');
        if (!token) return;

        /* Clear the token from the URL immediately to prevent re-triggering
         * on refresh or back/forward navigation */
        const cleanPath = window.location.pathname || '/';
        window.history.replaceState({ path: cleanPath }, '', cleanPath);

        /* Verify the token asynchronously */
        this._verifyMagicLinkToken(token);
    }

    /**
     * Verify a magic link token with the API and handle the result.
     *
     * On success: stores bearer token + user info, shows success toast,
     * updates header state, triggers setlist sync, and navigates home.
     *
     * On error: shows error toast and navigates home.
     *
     * @param {string} token The magic link token from the URL
     */
    async _verifyMagicLinkToken(token) {
        try {
            const res = await fetch(`${this.apiUrl}?action=auth_email_login_verify`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ token }),
            });

            const data = await res.json();

            if (res.ok && data.token && data.user) {
                /* Store credentials */
                this.app.userAuth?.saveCredentials(data.token, data.user);

                /* Update header to reflect logged-in state */
                this.app.userAuth?._updateHeaderState();

                /* Show success toast */
                this.app.showToast('Signed in successfully!', 'success', 3000);

                /* Trigger setlist sync in background */
                this.app.userAuth?.triggerSetlistSync();
            } else {
                /* Token invalid or expired */
                const message = data.error || 'Login link expired. Please request a new one.';
                this.app.showToast(message, 'danger', 5000);
            }
        } catch {
            this.app.showToast('Login link expired. Please request a new one.', 'danger', 5000);
        }

        /* Navigate to home (clear /login from URL if still there) */
        if (window.location.pathname !== '/') {
            window.history.replaceState({ path: '/' }, '', '/');
            this.currentPath = null; /* Reset so handleCurrentRoute proceeds */
            this.handleCurrentRoute();
        }
    }

    /**
     * Automatically set badge text colour (dark/light) based on the
     * computed background luminance.  Uses WCAG relative-luminance
     * formula so any future songbook colour is handled automatically.
     */
    fixBadgeContrast() {
        const badges = document.querySelectorAll(
            '.song-number-badge, .song-number-badge-lg, .songbook-icon'
        );
        if (!badges.length) return;

        const toLinear = c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        const rootStyles = getComputedStyle(document.documentElement);

        badges.forEach(badge => {
            let rgb = null;

            /* Try 1: read the solid CSS variable from data-songbook attribute (#159) */
            const bookId = badge.dataset?.songbook
                || badge.className?.match(/songbook-icon-(\w+)/)?.[1];
            if (bookId) {
                const solidColor = rootStyles.getPropertyValue(`--songbook-${bookId}-solid`).trim();
                if (solidColor) {
                    /* Parse hex (#rrggbb) or rgb() */
                    const hex = solidColor.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
                    if (hex) {
                        rgb = [parseInt(hex[1], 16), parseInt(hex[2], 16), parseInt(hex[3], 16)];
                    } else {
                        const rgbM = solidColor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                        if (rgbM) rgb = [parseInt(rgbM[1], 10), parseInt(rgbM[2], 10), parseInt(rgbM[3], 10)];
                    }
                }
            }

            /* Try 2: fall back to computed backgroundColor (for non-gradient badges) */
            if (!rgb) {
                const bg = getComputedStyle(badge).backgroundColor;
                if (!bg || bg === 'transparent' || bg === 'rgba(0, 0, 0, 0)') return;
                const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                if (!m) return;
                rgb = [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
            }

            const r = rgb[0] / 255;
            const g = rgb[1] / 255;
            const b = rgb[2] / 255;
            const L = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);

            /* Light backgrounds (L > 0.4) get dark text; dark backgrounds get white */
            badge.style.color = L > 0.4 ? '#1a1a1a' : '#ffffff';
        });
    }

    /**
     * Populate the statistics page with client-side data (#120).
     * Reads from localStorage: history, favourites, setlists, search history.
     */
    populateStats() {
        /* History data */
        let history = [];
        try { history = JSON.parse(localStorage.getItem(STORAGE_HISTORY)) || []; } catch {}

        /* Favourites data */
        let favorites = [];
        try { favorites = JSON.parse(localStorage.getItem(STORAGE_FAVORITES)) || []; } catch {}

        /* Set lists data */
        let setlists = [];
        try { setlists = JSON.parse(localStorage.getItem(STORAGE_SETLISTS)) || []; } catch {}

        /* Search history data */
        let searches = [];
        try { searches = JSON.parse(localStorage.getItem(STORAGE_SEARCH_HISTORY)) || []; } catch {}

        /* Summary counts */
        const el = (id) => document.getElementById(id);
        if (el('stats-total-views')) el('stats-total-views').textContent = history.length;
        if (el('stats-total-favorites')) el('stats-total-favorites').textContent = favorites.length;
        if (el('stats-total-setlists')) el('stats-total-setlists').textContent = setlists.length;
        if (el('stats-total-searches')) el('stats-total-searches').textContent = searches.length;

        /* Most viewed songs — count occurrences in history */
        if (history.length > 0) {
            const viewCounts = {};
            for (const entry of history) {
                if (!viewCounts[entry.id]) {
                    viewCounts[entry.id] = { ...entry, count: 0 };
                }
                viewCounts[entry.id].count++;
            }
            const sorted = Object.values(viewCounts).sort((a, b) => b.count - a.count).slice(0, 10);
            const maxCount = sorted[0]?.count || 1;

            const container = el('stats-most-viewed');
            if (container) {
                container.innerHTML = sorted.map(s => `
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <a href="/song/${escapeHtml(s.id)}" class="text-decoration-none flex-grow-1 text-truncate"
                           data-navigate="song" data-song-id="${escapeHtml(s.id)}">
                            <span class="song-number-badge song-number-badge-sm" data-songbook="${escapeHtml(s.songbook)}">${s.number ?? ''}</span>
                            <span class="ms-1">${escapeHtml(toTitleCase(s.title))}</span>
                        </a>
                        <div class="stats-bar-wrap">
                            <div class="stats-bar" style="width: ${(s.count / maxCount * 100).toFixed(0)}%"></div>
                        </div>
                        <span class="badge bg-secondary">${s.count}</span>
                    </div>
                `).join('');
            }
        }

        /* Favourites by songbook */
        if (favorites.length > 0) {
            const bySongbook = {};
            for (const fav of favorites) {
                const sb = fav.songbook || 'Unknown';
                bySongbook[sb] = (bySongbook[sb] || 0) + 1;
            }
            const sorted = Object.entries(bySongbook).sort((a, b) => b[1] - a[1]);
            const maxCount = sorted[0]?.[1] || 1;

            const container = el('stats-favorites-by-songbook');
            if (container) {
                container.innerHTML = sorted.map(([sb, count]) => `
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="song-number-badge song-number-badge-sm" data-songbook="${escapeHtml(sb)}">${escapeHtml(sb)}</span>
                        <div class="stats-bar-wrap">
                            <div class="stats-bar bg-danger" style="width: ${(count / maxCount * 100).toFixed(0)}%"></div>
                        </div>
                        <span class="badge bg-secondary">${count}</span>
                    </div>
                `).join('');
            }
        }

        /* Search trends — frequency list */
        if (searches.length > 0) {
            const termCounts = {};
            for (const s of searches) {
                const term = (s.query || s).toString().toLowerCase().trim();
                if (term) termCounts[term] = (termCounts[term] || 0) + 1;
            }
            const sorted = Object.entries(termCounts).sort((a, b) => b[1] - a[1]).slice(0, 15);

            const container = el('stats-search-trends');
            if (container) {
                container.innerHTML = '<div class="d-flex flex-wrap gap-2">' +
                    sorted.map(([term, count]) =>
                        `<span class="badge bg-body-secondary text-body">${escapeHtml(term)} <span class="text-muted">(${count})</span></span>`
                    ).join('') + '</div>';
            }
        }

        /* Time-based activity */
        const now = new Date();
        const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const weekStart = new Date(todayStart); weekStart.setDate(weekStart.getDate() - weekStart.getDay());
        const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);

        let today = 0, week = 0, month = 0;
        for (const entry of history) {
            const d = new Date(entry.viewedAt);
            if (d >= todayStart) today++;
            if (d >= weekStart) week++;
            if (d >= monthStart) month++;
        }

        if (el('stats-views-today')) el('stats-views-today').textContent = today;
        if (el('stats-views-week')) el('stats-views-week').textContent = week;
        if (el('stats-views-month')) el('stats-views-month').textContent = month;
    }

    /**
     * Fetch and render translation links for the current song (#352).
     * Queries the API for songs linked as translations in other languages.
     * Also checks the reverse direction (if this song is itself a translation).
     *
     * @param {string} songId The current song's ID
     */
    async loadTranslations(songId) {
        const container = document.getElementById('song-translations');
        const itemsEl = document.getElementById('song-translations-items');
        if (!container || !itemsEl) return;

        try {
            const resp = await fetch(`${this.apiUrl}?action=song_translations&id=${encodeURIComponent(songId)}`);
            if (!resp.ok) return;
            const data = await resp.json();

            const translations = data.translations || [];
            if (translations.length === 0) return;

            itemsEl.innerHTML = translations.map(tr => `
                <a href="/song/${escapeHtml(tr.songId)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(tr.songId)}"
                   role="listitem">
                    <span class="song-number-badge">${tr.number || '?'}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(tr.title))}${tr.verified ? ' <i class="fa-solid fa-circle-check text-success small" aria-hidden="true" title="Verified"></i>' : ''}</span>
                        <small class="text-muted d-block">
                            <i class="fa-solid fa-language me-1" aria-hidden="true"></i>${escapeHtml(tr.languageNativeName || tr.languageName || tr.language)}${tr.translator ? ` — ${escapeHtml(tr.translator)}` : ''}
                        </small>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');

            container.classList.remove('d-none');
            this.fixBadgeContrast();
        } catch (err) {
            console.warn('[Router] Failed to load translations:', err.message);
        }
    }

    /**
     * Find and render related songs for the current song page (#118).
     * Uses songs.json data to match by shared writers, composers, and songbook.
     * Runs asynchronously to avoid blocking page load.
     *
     * @param {string} currentSongId The current song's ID
     */
    async loadRelatedSongs(currentSongId) {
        const container = document.getElementById('related-songs');
        const itemsEl = document.getElementById('related-songs-items');
        if (!container || !itemsEl) return;

        try {
            const songs = await this.app.settings.getSongsData();
            const currentSong = songs.find(s => s.id === currentSongId);
            if (!currentSong) return;

            /* --- Metadata signals --- */
            const currentWriters = new Set((currentSong.writers || []).map(w => w.toLowerCase()));
            const currentComposers = new Set((currentSong.composers || []).map(c => c.toLowerCase()));
            const currentSongbook = currentSong.songbook || '';

            /* --- Content similarity: extract significant terms from lyrics --- */
            const currentTerms = this._extractTerms(currentSong);
            const currentTermSet = new Set(currentTerms);

            /* Build IDF (inverse document frequency) for the corpus on first run.
             * Cached on the class instance so subsequent calls are instant. */
            if (!this._idfCache) {
                this._idfCache = this._buildIdf(songs);
            }
            const idf = this._idfCache;

            /* TF vector for the current song */
            const currentTf = this._termFrequency(currentTerms);

            /* Score each song for relatedness */
            const scored = [];
            for (const song of songs) {
                if (song.id === currentSongId) continue;

                /* --- Metadata score (max ~10 typically) --- */
                let metaScore = 0;
                for (const w of (song.writers || [])) {
                    if (currentWriters.has(w.toLowerCase())) metaScore += 3;
                }
                for (const c of (song.composers || [])) {
                    if (currentComposers.has(c.toLowerCase())) metaScore += 2;
                }
                if (song.songbook === currentSongbook) metaScore += 1;

                /* --- Content score: TF-IDF cosine similarity (0–1) --- */
                const candidateTerms = this._extractTerms(song);
                /* Quick check: skip cosine calc if no term overlap */
                let hasOverlap = false;
                for (const t of candidateTerms) {
                    if (currentTermSet.has(t)) { hasOverlap = true; break; }
                }

                let contentScore = 0;
                if (hasOverlap) {
                    const candidateTf = this._termFrequency(candidateTerms);
                    contentScore = this._cosineSimilarity(currentTf, candidateTf, idf);
                }

                /* --- Combined score ---
                 * Content similarity is scaled to 0–15 so it carries more weight
                 * than metadata (writer +3, composer +2, songbook +1).
                 * A song with very similar lyrics but different writers will
                 * rank higher than one with the same writer but unrelated lyrics. */
                const combinedScore = metaScore + (contentScore * 15);

                if (combinedScore > 0.5) {
                    scored.push({ song, score: combinedScore });
                }
            }

            if (scored.length === 0) return;

            /* Sort by score descending, take top 5 */
            scored.sort((a, b) => b.score - a.score);
            const related = scored.slice(0, 5);

            /* Render related songs */
            itemsEl.innerHTML = related.map(({ song }) => `
                <a href="/song/${escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(song.id)}"
                   role="listitem">
                    <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook)}">${song.number ?? ''}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}</span>
                        <small class="text-muted d-block">${songbookLabel(song.songbook, song.songbookName)}</small>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');

            container.classList.remove('d-none');

            /* Fix badge contrast for newly rendered badges */
            this.fixBadgeContrast();

        } catch (err) {
            /* Non-critical — silently skip if songs data unavailable */
            console.warn('[Router] Failed to load related songs:', err.message);
        }
    }

    /* -----------------------------------------------------------------------
     * Content-based similarity helpers (#118 enhancement)
     * Uses TF-IDF cosine similarity on lyric text to find thematically
     * related songs regardless of writer/composer overlap.
     * ----------------------------------------------------------------------- */

    /** Common English stop words + hymn-specific filler to exclude */
    static STOP_WORDS = new Set([
        'a','an','the','and','or','but','in','on','at','to','for','of','with',
        'is','am','are','was','were','be','been','being','have','has','had',
        'do','does','did','will','would','shall','should','may','might','can',
        'could','i','me','my','we','us','our','you','your','he','him','his',
        'she','her','it','its','they','them','their','this','that','these',
        'those','not','no','nor','so','if','then','than','too','very','just',
        'all','each','every','both','few','more','most','some','any','such',
        'from','by','as','up','out','off','over','into','through','about',
        'again','once','here','there','when','where','how','what','which',
        'who','whom','why','oh','o','la','da','na','yeah','amen',
    ]);

    /**
     * Extract significant terms from a song's lyrics + title.
     * Lowercased, stop-words removed, short words filtered.
     *
     * @param {object} song Song object with components and title
     * @returns {string[]} Array of significant terms
     */
    _extractTerms(song) {
        let text = (song.title || '') + ' ';
        for (const c of (song.components || [])) {
            for (const line of (c.lines || [])) {
                text += line + ' ';
            }
        }
        return text
            .toLowerCase()
            .replace(/[^a-z\s'-]/g, ' ')
            .split(/\s+/)
            .filter(w => w.length > 2 && !Router.STOP_WORDS.has(w));
    }

    /**
     * Build term frequency map from an array of terms.
     *
     * @param {string[]} terms
     * @returns {Map<string, number>}
     */
    _termFrequency(terms) {
        const tf = new Map();
        for (const t of terms) {
            tf.set(t, (tf.get(t) || 0) + 1);
        }
        return tf;
    }

    /**
     * Build inverse document frequency map across the entire corpus.
     * IDF = log(N / df) where df = number of documents containing the term.
     *
     * @param {object[]} songs All songs
     * @returns {Map<string, number>}
     */
    _buildIdf(songs) {
        const N = songs.length;
        const df = new Map();

        for (const song of songs) {
            const seen = new Set();
            const terms = this._extractTerms(song);
            for (const t of terms) {
                if (!seen.has(t)) {
                    df.set(t, (df.get(t) || 0) + 1);
                    seen.add(t);
                }
            }
        }

        const idf = new Map();
        for (const [term, count] of df) {
            idf.set(term, Math.log(N / count));
        }
        return idf;
    }

    /**
     * Compute cosine similarity between two TF vectors using IDF weighting.
     *
     * @param {Map<string, number>} tfA TF map for song A
     * @param {Map<string, number>} tfB TF map for song B
     * @param {Map<string, number>} idf IDF map
     * @returns {number} Similarity score 0–1
     */
    _cosineSimilarity(tfA, tfB, idf) {
        let dot = 0, magA = 0, magB = 0;

        /* Only iterate over terms in A — terms not in B contribute 0 to dot product */
        for (const [term, freqA] of tfA) {
            const w = idf.get(term) || 0;
            const wA = freqA * w;
            magA += wA * wA;

            const freqB = tfB.get(term);
            if (freqB) {
                dot += wA * (freqB * w);
            }
        }

        /* Magnitude of B */
        for (const [term, freqB] of tfB) {
            const w = idf.get(term) || 0;
            const wB = freqB * w;
            magB += wB * wB;
        }

        const denom = Math.sqrt(magA) * Math.sqrt(magB);
        return denom > 0 ? dot / denom : 0;
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */

    /* =====================================================================
     * RECENT SONGBOOKS (#121)
     * ===================================================================== */

    /**
     * Track a songbook visit in localStorage.
     * Keeps the 5 most recent unique songbooks.
     * @param {string} songbookId
     */
    trackRecentSongbook(songbookId) {
        if (!songbookId) return;
        const key = STORAGE_RECENT_SONGBOOKS;
        let recent = [];
        try { recent = JSON.parse(localStorage.getItem(key)) || []; } catch {}

        /* Move to front if already tracked */
        recent = recent.filter(id => id !== songbookId);
        recent.unshift(songbookId);
        recent = recent.slice(0, 5);

        localStorage.setItem(key, JSON.stringify(recent));
    }

    /**
     * Render recent songbook quick-access badges on the home page (#121, #162).
     * Shows coloured squares with the songbook abbreviation inside them.
     */
    renderRecentSongbooks() {
        const container = document.getElementById('recent-songbooks');
        if (!container) return;

        let recent = [];
        try { recent = JSON.parse(localStorage.getItem(STORAGE_RECENT_SONGBOOKS)) || []; } catch {}

        /* Only show if user has visited 2+ songbooks */
        if (recent.length < 2) return;

        const songbooks = this.config.songbooks || [];

        const badges = recent.map(id => {
            const sb = songbooks.find(b => b.id === id);
            const name = sb?.name || id;
            return `<a href="/songbook/${escapeHtml(id)}"
                       class="text-decoration-none text-center"
                       data-navigate="songbook"
                       data-songbook-id="${escapeHtml(id)}"
                       title="${escapeHtml(name)}"
                       aria-label="${escapeHtml(name)}">
                        <span class="song-number-badge d-flex align-items-center justify-content-center"
                              data-songbook="${escapeHtml(id)}"
                              style="width: 48px; height: 48px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.02em;">
                            ${escapeHtml(id)}
                        </span>
                    </a>`;
        }).join('');

        container.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-1">
                <small class="text-muted fw-semibold">
                    <i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>
                    Recent
                </small>
            </div>
            <div class="d-flex flex-wrap gap-2">${badges}</div>
        `;
        container.classList.remove('d-none');

        /* Fix badge text contrast for the songbook-coloured badges */
        this.fixBadgeContrast();
    }
}
