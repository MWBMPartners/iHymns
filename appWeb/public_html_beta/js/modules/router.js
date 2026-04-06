/**
 * iHymns — SPA Router Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
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
            case 'settings':
                return { page: 'settings', params: {} };
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
            'settings': 'Settings — ' + appName,
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
            }
        }

        /* Render recently viewed section on home page (#92) */
        if (page === 'home') {
            this.app.history.renderHomeSection();
        }

        /* Initialise favourites list on favorites page */
        if (page === 'favorites') {
            this.app.favorites.loadFavoritesList();
        }

        /* Initialise settings controls on settings page */
        if (page === 'settings') {
            this.app.settings.initSettingsPage();
        }

        /* Initialise search page controls */
        if (page === 'search') {
            this.app.search.initSearchPage();
            this.app.numpad.initSearchPageNumpad();
        }
    }
}
