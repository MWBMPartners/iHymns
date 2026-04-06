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
                return { page: 'song', params: { id: segments[1] || '' } };
            case 'search':
                return { page: 'search', params: {} };
            case 'favorites':
            case 'favourites':
                return { page: 'favorites', params: {} };
            case 'setlist':
            case 'setlists':
                return { page: 'setlist', params: {} };
            case 'settings':
                return { page: 'settings', params: {} };
            case 'stats':
            case 'statistics':
                return { page: 'stats', params: {} };
            case 'help':
                return { page: 'help', params: {} };
            case 'terms':
                return { page: 'terms', params: {} };
            case 'privacy':
                return { page: 'privacy', params: {} };
            default:
                return { page: 'not-found', params: {} };
        }
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
            /* Start exit transition */
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

            /* Start enter transition */
            await this.app.transitions.pageIn(content);

        } catch (error) {
            if (error.name === 'AbortError') {
                /* Request was cancelled — another navigation started */
                return;
            }

            console.error('[Router] Failed to load page:', error);
            content.innerHTML = `
                <div class="alert alert-danger mt-4" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                    Failed to load page. Please check your connection and try again.
                </div>`;
            this.app.transitions.pageIn(content);
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
            'settings': 'Settings — ' + appName,
            'stats': 'Usage Statistics — ' + appName,
            'help': 'Help — ' + appName,
            'terms': 'Terms of Use — ' + appName,
            'privacy': 'Privacy Policy — ' + appName,
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
                }

                /* Load related songs (#118) — async, non-blocking */
                this.loadRelatedSongs(songId);
            }
        }

        /* Render recently viewed section on home page (#92) */
        if (page === 'home') {
            this.app.history.renderHomeSection();
            this.app.songOfTheDay.renderHomeSection();
        }

        /* Initialise favourites list on favorites page */
        if (page === 'favorites') {
            this.app.favorites.loadFavoritesList();
        }

        /* Initialise settings controls on settings page */
        if (page === 'settings') {
            this.app.settings.initSettingsPage();
        }

        /* Initialise set list page controls (#94) */
        if (page === 'setlist') {
            this.app.setList.initSetListPage();
        }

        /* Initialise songbook index (#111) */
        if (page === 'songbook') {
            this.app.songbookIndex.initSongbookPage();
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
    }

    /**
     * Populate the statistics page with client-side data (#120).
     * Reads from localStorage: history, favourites, setlists, search history.
     */
    populateStats() {
        /* History data */
        let history = [];
        try { history = JSON.parse(localStorage.getItem('ihymns_history')) || []; } catch {}

        /* Favourites data */
        let favorites = [];
        try { favorites = JSON.parse(localStorage.getItem('ihymns_favorites')) || []; } catch {}

        /* Set lists data */
        let setlists = [];
        try { setlists = JSON.parse(localStorage.getItem('ihymns_setlists')) || []; } catch {}

        /* Search history data */
        let searches = [];
        try { searches = JSON.parse(localStorage.getItem('ihymns_search_history')) || []; } catch {}

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
                        <a href="/song/${this.escapeHtml(s.id)}" class="text-decoration-none flex-grow-1 text-truncate"
                           data-navigate="song" data-song-id="${this.escapeHtml(s.id)}">
                            <span class="song-number-badge song-number-badge-sm" data-songbook="${this.escapeHtml(s.songbook)}">${s.number || '?'}</span>
                            <span class="ms-1">${this.escapeHtml(s.title)}</span>
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
                        <span class="song-number-badge song-number-badge-sm" data-songbook="${this.escapeHtml(sb)}">${this.escapeHtml(sb)}</span>
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
                        `<span class="badge bg-body-secondary text-body">${this.escapeHtml(term)} <span class="text-muted">(${count})</span></span>`
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

            const currentWriters = new Set((currentSong.writers || []).map(w => w.toLowerCase()));
            const currentComposers = new Set((currentSong.composers || []).map(c => c.toLowerCase()));
            const currentSongbook = currentSong.songbook || '';

            /* Score each song for relatedness */
            const scored = [];
            for (const song of songs) {
                if (song.id === currentSongId) continue;
                let score = 0;

                /* +3 per shared writer */
                for (const w of (song.writers || [])) {
                    if (currentWriters.has(w.toLowerCase())) score += 3;
                }
                /* +2 per shared composer */
                for (const c of (song.composers || [])) {
                    if (currentComposers.has(c.toLowerCase())) score += 2;
                }
                /* +1 for same songbook */
                if (song.songbook === currentSongbook) score += 1;

                if (score > 0) {
                    scored.push({ song, score });
                }
            }

            if (scored.length === 0) return;

            /* Sort by score descending, take top 5 */
            scored.sort((a, b) => b.score - a.score);
            const related = scored.slice(0, 5);

            /* Render related songs */
            itemsEl.innerHTML = related.map(({ song }) => `
                <a href="/song/${this.escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${this.escapeHtml(song.id)}"
                   role="listitem">
                    <span class="song-number-badge" data-songbook="${this.escapeHtml(song.songbook)}">${song.number || '?'}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${this.escapeHtml(song.title)}</span>
                        <small class="text-muted d-block">${this.escapeHtml(song.songbookName || song.songbook)}</small>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>
            `).join('');

            container.classList.remove('d-none');

        } catch (err) {
            /* Non-critical — silently skip if songs data unavailable */
            console.warn('[Router] Failed to load related songs:', err.message);
        }
    }

    /**
     * Escape HTML to prevent XSS.
     * @param {string} str
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
