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
import { Audio } from './modules/audio.js';
import { SheetMusic } from './modules/sheet-music.js';
import { History } from './modules/history.js';
import { SetList } from './modules/setlist.js';
import { Display } from './modules/display.js';
import { Compare } from './modules/compare.js';
import { Shortcuts } from './modules/shortcuts.js';
import { Request } from './modules/request.js';
import { Transpose } from './modules/transpose.js';
import { ReadingProgress } from './modules/reading-progress.js';
import { SongbookIndex } from './modules/songbook-index.js';

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

        /** @type {Audio} MIDI audio playback module (#90) */
        this.audio = null;

        /** @type {SheetMusic} PDF sheet music viewer module (#91) */
        this.sheetMusic = null;

        /** @type {History} Recently viewed songs history (#92) */
        this.history = null;

        /** @type {SetList} Worship set list / playlist (#94) */
        this.setList = null;

        /** @type {Display} Display preferences & presentation mode (#95) */
        this.display = null;

        /** @type {Compare} Side-by-side song comparison (#102) */
        this.compare = null;

        /** @type {Shortcuts} Keyboard shortcuts help overlay (#104) */
        this.shortcuts = null;

        /** @type {Request} Missing song request form (#107) */
        this.request = null;

        /** @type {Transpose} Transpose / capo indicator (#101) */
        this.transpose = null;

        /** @type {ReadingProgress} Scroll-linked reading progress (#109) */
        this.readingProgress = null;

        /** @type {SongbookIndex} Songbook alphabetical index (#111) */
        this.songbookIndex = null;
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

            /* Audio playback module (#90) */
            this.audio = new Audio(this);
            this.audio.init();

            /* Sheet music viewer (#91) */
            this.sheetMusic = new SheetMusic(this);
            this.sheetMusic.init();

            /* Recently viewed history (#92) */
            this.history = new History(this);
            this.history.init();

            /* Worship set list / playlist (#94) */
            this.setList = new SetList(this);
            this.setList.init();

            /* Display preferences & presentation mode (#95) */
            this.display = new Display(this);
            this.display.init();

            /* Side-by-side song comparison (#102) */
            this.compare = new Compare(this);
            this.compare.init();

            /* Keyboard shortcuts help overlay (#104) */
            this.shortcuts = new Shortcuts(this);
            this.shortcuts.init();

            /* Missing song request form (#107) */
            this.request = new Request(this);
            this.request.init();

            /* Transpose / capo indicator (#101) */
            this.transpose = new Transpose(this);
            this.transpose.init();

            /* Scroll-linked reading progress (#109) */
            this.readingProgress = new ReadingProgress(this);
            this.readingProgress.init();

            /* Songbook alphabetical index (#111) */
            this.songbookIndex = new SongbookIndex(this);
            this.songbookIndex.init();

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
        /** @type {string} Number buffer for quick-jump (#96) */
        this.quickJumpBuffer = '';
        /** @type {number|null} Quick-jump timeout ID */
        this.quickJumpTimer = null;

        /* --- Keyboard shortcuts --- */
        document.addEventListener('keydown', (e) => {
            /* Don't trigger shortcuts when typing in inputs */
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            /* Quick-jump: capture digit keys (#96) */
            if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                this.quickJumpAppend(e.key);
                return;
            }

            /* Quick-jump: Enter confirms, Escape cancels (#96) */
            if (this.quickJumpBuffer) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.quickJumpGo();
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.quickJumpClear();
                    return;
                }
                if (e.key === 'Backspace') {
                    e.preventDefault();
                    this.quickJumpBuffer = this.quickJumpBuffer.slice(0, -1);
                    if (this.quickJumpBuffer) {
                        this.quickJumpShowIndicator();
                        this.quickJumpResetTimer();
                    } else {
                        this.quickJumpClear();
                    }
                    return;
                }
            }

            switch (e.key) {
                case '?':
                    /* Toggle keyboard shortcuts help (#104) */
                    e.preventDefault();
                    this.shortcuts.toggle();
                    return;
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
                    /* Close shortcuts overlay or search bar */
                    if (this.shortcuts.visible) {
                        this.shortcuts.hide();
                    } else {
                        this.search.toggleHeaderSearch(false);
                    }
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

            /* Audio button — opens the MIDI player (#90) */
            const audioBtn = e.target.closest('.btn-audio');
            if (audioBtn) {
                e.preventDefault();
                const songId = audioBtn.dataset.songId;
                if (songId && this.audio) {
                    this.audio.handleAudioClick(songId);
                }
            }

            /* Sheet music button — opens the PDF viewer (#91) */
            const sheetBtn = e.target.closest('.btn-sheet-music');
            if (sheetBtn) {
                e.preventDefault();
                const songId = sheetBtn.dataset.songId;
                if (songId && this.sheetMusic) {
                    this.sheetMusic.handleSheetMusicClick(songId);
                }
            }

            /* Missing song request button (#107) */
            const requestBtn = e.target.closest('.btn-request-song');
            if (requestBtn) {
                e.preventDefault();
                this.request.showRequestModal(requestBtn.dataset.prefill || '');
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

        /* --- Scroll-to-top button (#97) --- */
        const scrollBtn = document.getElementById('scroll-to-top-btn');
        if (scrollBtn) {
            window.addEventListener('scroll', () => {
                const show = window.scrollY > 300;
                scrollBtn.classList.toggle('visible', show);
                scrollBtn.setAttribute('aria-hidden', String(!show));
                scrollBtn.tabIndex = show ? 0 : -1;
            }, { passive: true });

            scrollBtn.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: document.body.classList.contains('reduce-motion') ? 'auto' : 'smooth'
                });
            });
        }
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

    /* =====================================================================
     * QUICK-JUMP — Keyboard number navigation (#96)
     * ===================================================================== */

    /**
     * Append a digit to the quick-jump buffer and show/update the indicator.
     * @param {string} digit Single digit character
     */
    quickJumpAppend(digit) {
        this.quickJumpBuffer += digit;
        this.quickJumpShowIndicator();
        this.quickJumpResetTimer();
    }

    /** Show or update the floating quick-jump indicator */
    quickJumpShowIndicator() {
        let indicator = document.getElementById('quick-jump-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'quick-jump-indicator';
            indicator.className = 'quick-jump-indicator';
            indicator.setAttribute('role', 'status');
            indicator.setAttribute('aria-live', 'polite');
            document.body.appendChild(indicator);
        }
        indicator.innerHTML = `
            <div class="quick-jump-number">${this.escapeHtml(this.quickJumpBuffer)}</div>
            <small class="quick-jump-hint">Press Enter or wait...</small>`;
        indicator.classList.add('visible');
    }

    /** Clear the quick-jump buffer and hide the indicator */
    quickJumpClear() {
        this.quickJumpBuffer = '';
        clearTimeout(this.quickJumpTimer);
        this.quickJumpTimer = null;
        const indicator = document.getElementById('quick-jump-indicator');
        if (indicator) indicator.classList.remove('visible');
    }

    /** Reset the auto-navigate timer (1.5s after last digit) */
    quickJumpResetTimer() {
        clearTimeout(this.quickJumpTimer);
        this.quickJumpTimer = setTimeout(() => this.quickJumpGo(), 1500);
    }

    /**
     * Execute the quick-jump navigation.
     * Uses default songbook if set, otherwise shows a quick picker.
     */
    quickJumpGo() {
        const number = this.quickJumpBuffer;
        this.quickJumpClear();

        if (!number) return;

        const defaultBook = localStorage.getItem('ihymns_default_songbook');
        if (defaultBook) {
            const padded = number.padStart(4, '0');
            this.router.navigate(`/song/${defaultBook}-${padded}`);
        } else {
            /* Show quick songbook picker */
            this.quickJumpShowPicker(number);
        }
    }

    /**
     * Show a quick songbook picker for the typed number.
     * @param {string} number The typed song number
     */
    quickJumpShowPicker(number) {
        document.getElementById('quick-jump-picker')?.remove();

        const songbooks = this.config.songbooks || [];
        const picker = document.createElement('div');
        picker.id = 'quick-jump-picker';
        picker.className = 'quick-jump-picker';
        picker.setAttribute('role', 'dialog');
        picker.setAttribute('aria-label', 'Select songbook');

        /* Build songbook buttons from config or use fallback */
        const bookButtons = songbooks.length > 0
            ? songbooks.map(b => `
                <button type="button" class="btn btn-outline-primary btn-sm quick-jump-book"
                        data-book="${this.escapeHtml(b.id)}">
                    ${this.escapeHtml(b.id)}
                </button>`).join('')
            : ['CP', 'JP', 'MP', 'SDAH', 'CH'].map(id => `
                <button type="button" class="btn btn-outline-primary btn-sm quick-jump-book"
                        data-book="${id}">${id}</button>`).join('');

        picker.innerHTML = `
            <div class="quick-jump-picker-content">
                <p class="mb-2 fw-bold">Song #${this.escapeHtml(number)}</p>
                <p class="text-muted small mb-2">Select songbook:</p>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    ${bookButtons}
                </div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="quick-jump-remember">
                    <label class="form-check-label small" for="quick-jump-remember">Remember choice</label>
                </div>
            </div>`;

        document.body.appendChild(picker);

        /* Animate in */
        requestAnimationFrame(() => picker.classList.add('visible'));

        /* Bind songbook buttons */
        picker.querySelectorAll('.quick-jump-book').forEach(btn => {
            btn.addEventListener('click', () => {
                const bookId = btn.dataset.book;
                const remember = document.getElementById('quick-jump-remember')?.checked;
                if (remember) {
                    localStorage.setItem('ihymns_default_songbook', bookId);
                }
                picker.remove();
                const padded = number.padStart(4, '0');
                this.router.navigate(`/song/${bookId}-${padded}`);
            });
        });

        /* Close on click outside */
        const closeHandler = (e) => {
            if (!picker.contains(e.target)) {
                picker.remove();
                document.removeEventListener('click', closeHandler);
            }
        };
        setTimeout(() => document.addEventListener('click', closeHandler), 100);

        /* Close on Escape */
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                picker.remove();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
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
     * Register the service worker for offline support and handle updates (#83).
     *
     * When a new service worker is detected (updatefound), we track its
     * installation state. Once installed and waiting, a toast notification
     * is shown to the user offering to refresh for the new version.
     */
    registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;

        navigator.serviceWorker.register('/service-worker.js', {
            scope: '/'
        }).then(registration => {
            console.log('[iHymns] Service worker registered:', registration.scope);

            /* Check for updates periodically (every hour) */
            setInterval(() => registration.update(), 60 * 60 * 1000);

            /*
             * Listen for a new service worker being found (#83).
             * This fires when the browser detects an updated service-worker.js.
             */
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                if (!newWorker) return;

                newWorker.addEventListener('statechange', () => {
                    /*
                     * The new worker is installed and waiting to activate.
                     * Only show the notification if there's already an active
                     * controller (i.e., this isn't the very first install).
                     */
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        this.showUpdateNotification(registration);
                    }
                });
            });

        }).catch(error => {
            console.warn('[iHymns] Service worker registration failed:', error);
        });
    }

    /**
     * Show an update-available toast notification (#83).
     * Offers the user a "Refresh" button that activates the new
     * service worker and reloads the page.
     *
     * @param {ServiceWorkerRegistration} registration
     */
    showUpdateNotification(registration) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-primary border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fa-solid fa-arrow-rotate-right me-2" aria-hidden="true"></i>
                    A new version of ${this.escapeHtml(this.config.appName)} is available.
                    <button type="button" class="btn btn-sm btn-light ms-2" id="sw-update-btn">
                        Refresh
                    </button>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;

        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, { autohide: false });
        toast.show();

        /* Handle the refresh button click */
        const refreshBtn = toastEl.querySelector('#sw-update-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                /* Tell the waiting service worker to skip waiting and activate */
                if (registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
            });
        }

        /*
         * Listen for the new service worker taking control, then reload.
         * This fires after the waiting worker calls skipWaiting().
         */
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            window.location.reload();
        }, { once: true });

        /* Clean up toast element after dismissed */
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    /**
     * Escape HTML for safe insertion into innerHTML.
     * @param {string} str
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
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
