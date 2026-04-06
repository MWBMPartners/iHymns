/**
 * iHymns — Main Application Entry Point (ES Module)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
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
import { SearchHistory } from './modules/search-history.js';
import { SongOfTheDay } from './modules/song-of-the-day.js';
import { OfflineIndicator } from './modules/offline-indicator.js';
import { StorageBridge } from './modules/storage-bridge.js';
import { SubdomainSync } from './modules/subdomain-sync.js';
import { Gestures } from './modules/gestures.js';
import { Analytics } from './modules/analytics.js';
import { escapeHtml } from './utils/html.js';

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

        /** @type {SearchHistory} Recent search terms (#110) */
        this.searchHistory = null;

        /** @type {SongOfTheDay} Song of the Day (#108) */
        this.songOfTheDay = null;

        /** @type {OfflineIndicator} Offline status indicator (#112) */
        this.offlineIndicator = null;

        /** @type {StorageBridge} Cross-domain storage bridge (#133) */
        this.storageBridge = null;

        /** @type {SubdomainSync} Subdomain cookie sync (#133) */
        this.subdomainSync = null;

        /** @type {Gestures} Touch gesture navigation (#143) */
        this.gestures = null;

        /** @type {Analytics} Unified analytics tracking */
        this.analytics = null;
    }

    /**
     * Initialise the application.
     * Called once when the DOM is ready.
     */
    async init() {
        try {
            /* --- Initialise core modules --- */

            /* Subdomain cookie sync — pull settings from other *.ihymns.app
             * subdomains BEFORE applying settings, so shared preferences
             * are available immediately (#133, Layer 1). */
            this.subdomainSync = new SubdomainSync();
            this.subdomainSync.init();

            /* Settings must be first — it sets theme, motion prefs, etc. */
            this.settings = new Settings(this);
            this.settings.init();

            /* Cross-domain storage bridge (#133) — initialise in background.
             * Modules use localStorage directly (synchronous). The bridge
             * syncs data across domains asynchronously without blocking. */
            if (this.config.storageBridgeUrl) {
                this.storageBridge = new StorageBridge(this.config.storageBridgeUrl);
                this.storageBridge.init().then((connected) => {
                    if (connected) {
                        /* Pull any settings from other domains and re-apply */
                        this.storageBridge.getAll().then((data) => {
                            let needsRefresh = false;
                            for (const [key, value] of Object.entries(data)) {
                                if (localStorage.getItem(key) !== value) {
                                    localStorage.setItem(key, value);
                                    needsRefresh = true;
                                }
                            }
                            if (needsRefresh) {
                                /* Re-apply settings that may have been updated */
                                this.settings.applyTheme(this.settings.get('theme'));
                                this.settings.applyReduceMotion(this.settings.get('reduceMotion'));
                                this.settings.applyFontSize(this.settings.get('fontSize'));
                            }
                        });
                    }
                });
            }

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

            /* Recent search terms (#110) */
            this.searchHistory = new SearchHistory(this);
            this.searchHistory.init();

            /* Song of the Day (#108) */
            this.songOfTheDay = new SongOfTheDay(this);
            this.songOfTheDay.init();

            /* Offline status indicator (#112) */
            this.offlineIndicator = new OfflineIndicator(this);
            this.offlineIndicator.init();

            /* Touch gesture navigation (#143) */
            this.gestures = new Gestures(this);
            this.gestures.init();

            /* Unified analytics tracking */
            this.analytics = new Analytics(this);
            this.analytics.init();

            /* --- Set up global event listeners --- */
            this.bindGlobalEvents();

            /* --- Show first-launch disclaimer if needed --- */
            this.checkDisclaimer();

            /* --- Register service worker --- */
            this.registerServiceWorker();

            /* --- Listen for service worker messages (#131, #132) --- */
            this.initServiceWorkerMessaging();

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
                case 'p':
                case 'P':
                    /* Toggle presentation mode (#125) */
                    this.display.togglePresentationMode();
                    break;
                case 'l':
                case 'L':
                    /* Navigate to set list page (#125) */
                    e.preventDefault();
                    this.router.navigate('/setlist');
                    break;
                case 's':
                case 'S':
                    /* Toggle auto-scroll on song pages (#125) */
                    if (document.querySelector('.song-lyrics')) {
                        this.display.toggleAutoScroll();
                    }
                    break;
                case '+':
                case '=':
                    /* Increase font size (#125) */
                    this.display.adjustFontSize(1);
                    break;
                case '-':
                    /* Decrease font size (#125) */
                    this.display.adjustFontSize(-1);
                    break;
                case ' ':
                    /* Pause/resume auto-scroll (#125) */
                    if (this.display.autoScrollActive) {
                        e.preventDefault();
                        this.display.toggleAutoScroll();
                    }
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
                const nearBottom = (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 200);
                const show = window.scrollY > 300 && !nearBottom;
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
            <div class="quick-jump-number">${escapeHtml(this.quickJumpBuffer)}</div>
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
                        data-book="${escapeHtml(b.id)}">
                    ${escapeHtml(b.id)}
                </button>`).join('')
            : ['CP', 'JP', 'MP', 'SDAH', 'CH'].map(id => `
                <button type="button" class="btn btn-outline-primary btn-sm quick-jump-book"
                        data-book="${id}">${id}</button>`).join('');

        picker.innerHTML = `
            <div class="quick-jump-picker-content">
                <p class="mb-2 fw-bold">Song #${escapeHtml(number)}</p>
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
                    A new version of ${escapeHtml(this.config.appName)} is available.
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
     * Initialise service worker message listener (#131, #132).
     * Handles song update notifications and auto-update confirmations.
     */
    initServiceWorkerMessaging() {
        if (!('serviceWorker' in navigator)) return;

        /* Send auto-update preference to SW on init */
        navigator.serviceWorker.ready.then(() => {
            const autoUpdate = localStorage.getItem('ihymns_auto_update_songs') === 'true';
            navigator.serviceWorker.controller?.postMessage({
                type: 'SET_AUTO_UPDATE',
                enabled: autoUpdate,
            });
        });

        navigator.serviceWorker.addEventListener('message', (event) => {
            if (!event.data) return;

            /* A cached song has a newer version available — ask user (#131) */
            if (event.data.type === 'SONG_UPDATE_AVAILABLE') {
                this.showSongUpdateNotification(event.data.songId, event.data.url);
            }

            /* A song was auto-updated or manually updated (#131) */
            if (event.data.type === 'SONG_UPDATED') {
                if (event.data.auto) {
                    console.log(`[iHymns] Song ${event.data.songId} auto-updated in cache`);
                } else {
                    this.showToast('Song updated to latest version', 'success', 2000);
                }
            }
        });
    }

    /**
     * Show a notification that a cached song has an update available (#131).
     * Offers an "Update" button to re-cache the new version.
     *
     * @param {string} songId Song ID (e.g. 'CP-0001')
     * @param {string} url The API URL for the updated song
     */
    showSongUpdateNotification(songId, url) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-info border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'polite');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>
                    Song ${escapeHtml(songId)} has been updated.
                    <button type="button" class="btn btn-sm btn-light ms-2 btn-update-song"
                            data-song-id="${escapeHtml(songId)}"
                            data-url="${escapeHtml(url)}">
                        Update
                    </button>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;

        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, { autohide: false });
        toast.show();

        /* Handle the update button */
        const updateBtn = toastEl.querySelector('.btn-update-song');
        if (updateBtn) {
            updateBtn.addEventListener('click', () => {
                navigator.serviceWorker.controller?.postMessage({
                    type: 'UPDATE_SONG_CACHE',
                    songId: updateBtn.dataset.songId,
                    url: updateBtn.dataset.url,
                });
                updateBtn.disabled = true;
                updateBtn.textContent = 'Updating...';
                toast.hide();
            });
        }

        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    /**
     * Escape HTML for safe insertion into innerHTML.
     * @param {string} str
     * @returns {string}
     */

    /**
     * Sync a localStorage key to the cross-domain storage bridge (#133).
     * Call this after any direct localStorage.setItem() for ihymns_ keys.
     * Non-blocking — failures are silent.
     *
     * @param {string} key Full localStorage key (e.g. 'ihymns_favorites')
     */
    syncStorage(key) {
        const value = localStorage.getItem(key);
        /* Layer 1: Subdomain cookie sync (*.ihymns.app) */
        this.subdomainSync?.sync(key);
        /* Layer 2: Iframe bridge (cross-domain / large data) */
        if (value !== null && this.storageBridge) {
            this.storageBridge.set(key, value);
        }
    }

    /**
     * Show a custom confirm dialog replacing native confirm() (#114).
     * Returns a Promise that resolves to true (OK) or false (Cancel).
     *
     * @param {string} message The confirm message
     * @param {object} opts Options: { title, okText, cancelText, okClass }
     * @returns {Promise<boolean>}
     */
    showConfirm(message, opts = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Confirm',
                okText = 'OK',
                cancelText = 'Cancel',
                okClass = 'btn-primary',
            } = opts;

            const id = 'ihymns-confirm-' + Date.now();
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = id;
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', id + '-label');
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${id}-label">${escapeHtml(title)}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">${escapeHtml(message)}</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${escapeHtml(cancelText)}</button>
                            <button type="button" class="btn ${okClass}" id="${id}-ok">${escapeHtml(okText)}</button>
                        </div>
                    </div>
                </div>`;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);

            let resolved = false;
            modal.querySelector(`#${id}-ok`).addEventListener('click', () => {
                resolved = true;
                bsModal.hide();
            });
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolve(resolved);
            });

            bsModal.show();
        });
    }

    /**
     * Show a custom prompt dialog replacing native prompt() (#114).
     * Returns a Promise that resolves to the entered string or null (cancelled).
     *
     * @param {string} message The prompt message
     * @param {string} defaultValue Default input value
     * @param {object} opts Options: { title, okText, cancelText, placeholder }
     * @returns {Promise<string|null>}
     */
    showPrompt(message, defaultValue = '', opts = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Input',
                okText = 'OK',
                cancelText = 'Cancel',
                placeholder = '',
            } = opts;

            const id = 'ihymns-prompt-' + Date.now();
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = id;
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', id + '-label');
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${id}-label">${escapeHtml(title)}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label for="${id}-input" class="form-label">${escapeHtml(message)}</label>
                            <input type="text" class="form-control" id="${id}-input"
                                   value="${escapeHtml(defaultValue)}"
                                   placeholder="${escapeHtml(placeholder)}">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${escapeHtml(cancelText)}</button>
                            <button type="button" class="btn btn-primary" id="${id}-ok">${escapeHtml(okText)}</button>
                        </div>
                    </div>
                </div>`;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            const input = modal.querySelector(`#${id}-input`);

            let result = null;
            const submit = () => {
                result = input.value;
                bsModal.hide();
            };

            modal.querySelector(`#${id}-ok`).addEventListener('click', submit);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') submit();
            });
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolve(result);
            });
            modal.addEventListener('shown.bs.modal', () => {
                input.focus();
                input.select();
            });

            bsModal.show();
        });
    }

    /**
     * Show a custom choice dialog with two action options (#114).
     * Used for import mode selection (Replace vs Merge).
     *
     * @param {string} message The message
     * @param {object} opts { title, option1Text, option2Text, option1Class, option2Class }
     * @returns {Promise<string|null>} 'option1', 'option2', or null if dismissed
     */
    showChoice(message, opts = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Choose',
                option1Text = 'Option 1',
                option2Text = 'Option 2',
                option1Class = 'btn-primary',
                option2Class = 'btn-secondary',
            } = opts;

            const id = 'ihymns-choice-' + Date.now();
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = id;
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', id + '-label');
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${id}-label">${escapeHtml(title)}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">${escapeHtml(message)}</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn ${option2Class}" id="${id}-opt2">${escapeHtml(option2Text)}</button>
                            <button type="button" class="btn ${option1Class}" id="${id}-opt1">${escapeHtml(option1Text)}</button>
                        </div>
                    </div>
                </div>`;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);

            let result = null;
            modal.querySelector(`#${id}-opt1`).addEventListener('click', () => {
                result = 'option1';
                bsModal.hide();
            });
            modal.querySelector(`#${id}-opt2`).addEventListener('click', () => {
                result = 'option2';
                bsModal.hide();
            });
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolve(result);
            });

            bsModal.show();
        });
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
                    ${escapeHtml(message)}
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

        /* Whitelist valid Bootstrap toast types */
        const validTypes = ['primary', 'secondary', 'success', 'danger', 'warning', 'info'];
        const safeType = validTypes.includes(type) ? type : 'info';

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-bg-${safeType} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${escapeHtml(message)}</div>
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
        /* Delegate to unified analytics module */
        if (this.analytics) {
            this.analytics.trackPageView(path, title);
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
