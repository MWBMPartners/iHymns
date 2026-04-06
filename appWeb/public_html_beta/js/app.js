/**
 * iHymns — Main Application Entry Point (ES Module)
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Bootstraps the iHymns single-page application. Initialises all
 * modules, sets up the router, loads initial content, and coordinates
 * between the various subsystems (search, favourites, settings, PWA, etc.).
 *
 * ARCHITECTURE:
 * - Uses History API (pushState) for clean URL routing
 * - Pages are loaded via AJAX from the PHP API
 * - Page transitions provide smooth, app-like navigation
 * - All user preferences stored in localStorage
 * - Service worker handles offline caching
 */

import { Router } from './modules/router.js';
import { Transitions } from './modules/transitions.js';
import { Settings } from './modules/settings.js';
import { Search } from './modules/search.js';
import { Favorites } from './modules/favorites.js';
import { PWA } from './modules/pwa.js';
import { Shuffle } from './modules/shuffle.js';
import { Numpad } from './modules/numpad.js';
import { Share } from './modules/share.js';

/**
 * iHymnsApp — Main application class
 *
 * Coordinates all modules and manages the application lifecycle.
 */
class iHymnsApp {
    constructor() {
        /** @type {object} Application configuration from PHP */
        this.config = window.iHymnsConfig || {};

        /** @type {Router} SPA router instance */
        this.router = null;

        /** @type {Transitions} Page transition manager */
        this.transitions = null;

        /** @type {Settings} User settings manager */
        this.settings = null;

        /** @type {Search} Search module */
        this.search = null;

        /** @type {Favorites} Favourites manager */
        this.favorites = null;

        /** @type {PWA} PWA installation manager */
        this.pwa = null;

        /** @type {Shuffle} Random song picker */
        this.shuffle = null;

        /** @type {Numpad} Numeric keypad controller */
        this.numpad = null;

        /** @type {Share} Song sharing module */
        this.share = null;
    }

    /**
     * Initialise the application.
     * Called once when the DOM is ready.
     */
    async init() {
        try {
            /* --- Initialise core modules --- */

            /* Settings must be first — it sets theme, motion prefs, etc. */
            this.settings = new Settings(this);
            this.settings.init();

            /* Page transitions */
            this.transitions = new Transitions(this);

            /* Router — handles URL navigation and AJAX page loading */
            this.router = new Router(this);
            this.router.init();

            /* Search module */
            this.search = new Search(this);
            this.search.init();

            /* Favourites module */
            this.favorites = new Favorites(this);
            this.favorites.init();

            /* PWA installation manager */
            this.pwa = new PWA(this);
            this.pwa.init();

            /* Shuffle / random song */
            this.shuffle = new Shuffle(this);
            this.shuffle.init();

            /* Numeric keypad */
            this.numpad = new Numpad(this);
            this.numpad.init();

            /* Share module */
            this.share = new Share(this);
            this.share.init();

            /* --- Set up global event listeners --- */
            this.bindGlobalEvents();

            /* --- Show first-launch disclaimer if needed --- */
            this.checkDisclaimer();

            /* --- Register service worker --- */
            this.registerServiceWorker();

            /* --- Load initial page based on current URL --- */
            await this.router.handleCurrentRoute();

            /* --- Hide the loading spinner --- */
            this.hideLoader();

            console.log(`[iHymns] v${this.config.version} initialised successfully`);

        } catch (error) {
            console.error('[iHymns] Initialisation error:', error);
            this.hideLoader();
            this.showError('Failed to initialise the application. Please refresh the page.');
        }
    }

    /**
     * Bind global event listeners for keyboard shortcuts,
     * navigation links, and action buttons.
     */
    bindGlobalEvents() {
        /* --- Keyboard shortcuts --- */
        document.addEventListener('keydown', (e) => {
            /* Don't trigger shortcuts when typing in inputs */
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            switch (e.key) {
                case '/':
                    /* Open search */
                    e.preventDefault();
                    this.search.toggleHeaderSearch(true);
                    break;
                case '#':
                    /* Open numpad modal */
                    e.preventDefault();
                    this.numpad.openModal();
                    break;
                case 'Escape':
                    /* Close search bar */
                    this.search.toggleHeaderSearch(false);
                    break;
                case 'f':
                case 'F':
                    /* Toggle favourite on song page */
                    this.favorites.toggleCurrentSong();
                    break;
                case 'ArrowLeft':
                    /* Previous song (if on song page) */
                    this.navigateSongDirection('prev');
                    break;
                case 'ArrowRight':
                    /* Next song (if on song page) */
                    this.navigateSongDirection('next');
                    break;
            }
        });

        /* Ctrl+K shortcut for search */
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.search.toggleHeaderSearch(true);
            }
        });

        /* --- Navigation click handler (event delegation) --- */
        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-navigate]');
            if (link) {
                e.preventDefault();
                const href = link.getAttribute('href');
                if (href) {
                    this.router.navigate(href);
                }
            }

            /* Action buttons (home page) */
            const action = e.target.closest('[data-action]');
            if (action) {
                e.preventDefault();
                this.handleAction(action.dataset.action, action);
            }

            /*
             * Audio button — currently not implemented (#80).
             * Shows a "coming soon" toast until the audio module is built.
             */
            const audioBtn = e.target.closest('.btn-audio');
            if (audioBtn) {
                e.preventDefault();
                this.showToast(
                    '<i class="fa-solid fa-headphones me-2" aria-hidden="true"></i>'
                    + 'Audio playback is coming soon!',
                    'info', 3000
                );
            }

            /*
             * Sheet music button — currently not implemented (#80).
             * Shows a "coming soon" toast until the sheet music module is built.
             */
            const sheetBtn = e.target.closest('.btn-sheet-music');
            if (sheetBtn) {
                e.preventDefault();
                this.showToast(
                    '<i class="fa-solid fa-file-pdf me-2" aria-hidden="true"></i>'
                    + 'Sheet music viewer is coming soon!',
                    'info', 3000
                );
            }
        });

        /* --- Footer navigation active state --- */
        document.querySelectorAll('.footer-nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const href = item.getAttribute('href');
                if (href) {
                    this.router.navigate(href);
                }
            });
        });
    }

    /**
     * Handle action button clicks (data-action attribute).
     *
     * @param {string} action The action name
     * @param {HTMLElement} el The clicked element
     */
    handleAction(action, el) {
        switch (action) {
            case 'open-search':
                this.router.navigate('/search');
                break;
            case 'open-numpad':
                this.numpad.openModal(el.dataset.numpadBook || null);
                break;
            case 'open-shuffle':
                this.shuffle.openModal();
                break;
            case 'shuffle-book':
                this.shuffle.shuffleFromBook(el.dataset.shuffleBook || null);
                break;
        }
    }

    /**
     * Navigate to the previous or next song (if on song page).
     *
     * @param {string} direction 'prev' or 'next'
     */
    navigateSongDirection(direction) {
        const songPage = document.querySelector('.page-song');
        if (!songPage) return;

        const selector = direction === 'prev'
            ? '.song-navigation a:first-child'
            : '.song-navigation a:last-child';
        const link = songPage.querySelector(selector);
        if (link) {
            const href = link.getAttribute('href');
            if (href) this.router.navigate(href);
        }
    }

    /**
     * Check if the first-launch disclaimer has been accepted.
     * If not, show the disclaimer modal.
     */
    checkDisclaimer() {
        const accepted = localStorage.getItem('ihymns_disclaimer_accepted');
        if (accepted) return;

        const modal = document.getElementById('disclaimer-modal');
        if (!modal) return;

        /* Show the modal using Bootstrap */
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        /* Handle acceptance */
        const acceptBtn = document.getElementById('disclaimer-accept-btn');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => {
                localStorage.setItem('ihymns_disclaimer_accepted', 'true');
                bsModal.hide();
            });
        }
    }

    /**
     * Register the service worker for offline support.
     */
    registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js', {
                scope: '/'
            }).then(registration => {
                console.log('[iHymns] Service worker registered:', registration.scope);

                /* Check for updates periodically */
                setInterval(() => {
                    registration.update();
                }, 60 * 60 * 1000); /* Every hour */

            }).catch(error => {
                console.warn('[iHymns] Service worker registration failed:', error);
            });
        }
    }

    /**
     * Hide the initial page loader spinner.
     */
    hideLoader() {
        const loader = document.getElementById('page-loader');
        if (loader) {
            loader.classList.add('hidden');
        }
    }

    /**
     * Display an error message to the user.
     *
     * @param {string} message Error message text
     */
    showError(message) {
        const content = document.getElementById('page-content');
        if (content) {
            content.innerHTML = `
                <div class="alert alert-danger mt-4" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                    ${message}
                </div>`;
        }
    }

    /**
     * Show a toast notification.
     *
     * @param {string} message Toast message
     * @param {string} type Bootstrap alert type (success, danger, warning, info)
     * @param {number} duration Auto-dismiss in ms (0 = don't auto-dismiss)
     */
    showToast(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-bg-${type} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;

        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, {
            autohide: duration > 0,
            delay: duration
        });
        toast.show();

        /* Clean up after hidden */
        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    }

    /**
     * Send a page view event to analytics (respects DNT).
     *
     * @param {string} path Page path
     * @param {string} title Page title
     */
    trackPageView(path, title) {
        /* Google Analytics 4 */
        if (typeof gtag === 'function') {
            gtag('event', 'page_view', {
                page_path: path,
                page_title: title,
                /* IP anonymised server-side when DNT is active */
            });
        }
    }
}

/* ==========================================================================
   APPLICATION BOOTSTRAP — Wait for DOM ready, then initialise
   ========================================================================== */

document.addEventListener('DOMContentLoaded', () => {
    window.iHymnsApp = new iHymnsApp();
    window.iHymnsApp.init();
});
