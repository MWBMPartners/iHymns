/**
 * iHymns — Numeric Keypad Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Provides a touch-friendly numeric keypad for searching songs by
 * number. Works in the modal (global) and embedded on the search page.
 * Performs live search-as-you-type against the API.
 */
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { toTitleCase } from '../utils/text.js';
import { STORAGE_DEFAULT_SONGBOOK, STORAGE_NUMPAD_LIVE_SEARCH } from '../constants.js';

export class Numpad {
    constructor(app) {
        this.app = app;
        /** @type {string} Current number being entered (modal) */
        this.currentNumber = '';
        /** @type {string} Current number on search page */
        this.pageNumber = '';
        /** @type {number|null} Debounce timer */
        this.debounceTimer = null;
    }

    /** Whether live search-as-you-type is enabled (off by default). */
    get liveSearchEnabled() {
        return localStorage.getItem(STORAGE_NUMPAD_LIVE_SEARCH) === 'true';
    }

    /**
     * Initialise — bind events for the modal numpad.
     */
    init() {
        /* Populate songbook dropdown in modal and pre-select default */
        this.populateSongbookDropdown('numpad-songbook');

        /* Modal numpad button clicks */
        document.querySelectorAll('#numpad-modal [data-num]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handleModalKey(btn.dataset.num);
            });
        });
    }

    /**
     * Open the numpad modal, optionally pre-selecting a songbook.
     *
     * @param {string|null} songbookId Pre-select this songbook
     */
    openModal(songbookId) {
        this.currentNumber = '';
        this.updateModalDisplay();

        /* Pre-select songbook: explicit param > default setting */
        const bookId = songbookId || localStorage.getItem(STORAGE_DEFAULT_SONGBOOK) || '';
        if (bookId) {
            const select = document.getElementById('numpad-songbook');
            if (select) select.value = bookId;
        }

        /* Clear previous results */
        const results = document.getElementById('numpad-results');
        if (results) results.innerHTML = '';

        /* Show the Bootstrap modal */
        const modal = document.getElementById('numpad-modal');
        if (modal) {
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();

            /* Bind physical keyboard input while modal is open */
            this._modalKeyHandler = (e) => this._handlePhysicalKey(e, 'modal');
            document.addEventListener('keydown', this._modalKeyHandler);

            /* Unbind when modal closes */
            modal.addEventListener('hidden.bs.modal', () => {
                document.removeEventListener('keydown', this._modalKeyHandler);
                this._modalKeyHandler = null;
            }, { once: true });
        }
    }

    /**
     * Handle physical keyboard input for numpad (modal or search page).
     * Maps digit keys, Backspace, Enter, and Escape to numpad actions.
     * @param {KeyboardEvent} e
     * @param {string} context 'modal' or 'page'
     */
    _handlePhysicalKey(e, context) {
        /* Ignore if user is typing in the songbook dropdown */
        if (e.target.tagName === 'SELECT') return;

        if (e.key >= '0' && e.key <= '9') {
            e.preventDefault();
            if (context === 'modal') {
                this.handleModalKey(e.key);
            } else {
                this.handlePageKey(e.key);
            }
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            if (context === 'modal') {
                this.handleModalKey('clear');
            } else {
                this.handlePageKey('clear');
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (context === 'modal') {
                this.handleModalKey('go');
            } else {
                this.handlePageKey('go');
            }
        }
        /* Escape is handled by Bootstrap modal dismiss */
    }

    /**
     * Handle a key press on the modal numpad.
     *
     * @param {string} key The key value ('0'-'9', 'clear', 'go')
     */
    handleModalKey(key) {
        if (key === 'clear') {
            /* Backspace — remove last digit */
            this.currentNumber = this.currentNumber.slice(0, -1);
        } else if (key === 'go') {
            /* Go — navigate to the first result or exact match */
            this.goToSong('numpad-songbook', this.currentNumber, 'numpad-modal');
            return;
        } else {
            /* Digit — append (max 4 digits) */
            if (this.currentNumber.length < 4) {
                this.currentNumber += key;
            }
        }

        this.updateModalDisplay();
        if (this.liveSearchEnabled) {
            this.searchByNumber('numpad-songbook', this.currentNumber, 'numpad-results');
        }
    }

    /**
     * Update the modal number display.
     */
    updateModalDisplay() {
        const display = document.getElementById('numpad-display');
        if (display) display.value = this.currentNumber;
    }

    /**
     * Initialise the search page embedded numpad.
     */
    initSearchPageNumpad() {
        this.pageNumber = '';
        /* Populate and pre-select default songbook */
        this.populateSongbookDropdown('page-numpad-songbook');

        /* Page numpad button clicks */
        document.querySelectorAll('#panel-number-search [data-page-num]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handlePageKey(btn.dataset.pageNum);
            });
        });

        /*
         * Physical keyboard support for the search page numpad.
         * Only active when the number search panel is visible and
         * no text input has focus (to avoid capturing search bar typing).
         */
        this._pageKeyHandler = (e) => {
            const panel = document.getElementById('panel-number-search');
            if (!panel || panel.classList.contains('d-none')) return;
            if (e.target.matches('input[type="text"], input[type="search"], textarea')) return;
            this._handlePhysicalKey(e, 'page');
        };
        document.addEventListener('keydown', this._pageKeyHandler);
    }

    /**
     * Handle a key press on the search page numpad.
     */
    handlePageKey(key) {
        if (key === 'clear') {
            this.pageNumber = this.pageNumber.slice(0, -1);
        } else if (key === 'go') {
            this.goToSong('page-numpad-songbook', this.pageNumber);
            return;
        } else {
            if (this.pageNumber.length < 4) {
                this.pageNumber += key;
            }
        }

        const display = document.getElementById('page-numpad-display');
        if (display) display.value = this.pageNumber;

        if (this.liveSearchEnabled) {
            this.searchByNumber('page-numpad-songbook', this.pageNumber, 'page-numpad-results');
        }
    }

    /**
     * Search by number via the API and display results.
     *
     * @param {string} selectId ID of the songbook select element
     * @param {string} number Current number string
     * @param {string} resultsId ID of the results container
     */
    async searchByNumber(selectId, number, resultsId) {
        clearTimeout(this.debounceTimer);

        const results = document.getElementById(resultsId);
        if (!results) return;

        if (number.length === 0) {
            results.innerHTML = '';
            return;
        }

        this.debounceTimer = setTimeout(async () => {
            const select = document.getElementById(selectId);
            const songbook = select?.value || '';

            if (!songbook) {
                results.innerHTML = '<small class="text-muted">Select a songbook first</small>';
                return;
            }

            try {
                const url = new URL(this.app.config.apiUrl, window.location.origin);
                url.searchParams.set('action', 'search_num');
                url.searchParams.set('songbook', songbook);
                url.searchParams.set('number', number);

                const response = await fetch(url);
                const data = await response.json();

                if (data.results && data.results.length > 0) {
                    results.innerHTML = '<div class="list-group list-group-flush">' +
                        data.results.slice(0, 8).map(song => `
                            <a href="/song/${song.id}"
                               class="list-group-item list-group-item-action py-2"
                               data-navigate="song" data-song-id="${song.id}">
                                <strong>#${song.number}</strong> — ${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}
                            </a>
                        `).join('') + '</div>';

                    /* Bind navigation clicks */
                    results.querySelectorAll('[data-navigate]').forEach(link => {
                        link.addEventListener('click', (e) => {
                            e.preventDefault();
                            /* Close modal if open */
                            const modalEl = document.getElementById('numpad-modal');
                            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
                            this.app.router.navigate(link.getAttribute('href'));
                        });
                    });
                } else {
                    results.innerHTML = '<small class="text-muted">No matching songs</small>';
                }
            } catch (error) {
                console.error('[Numpad] Search error:', error);
                results.innerHTML = '<small class="text-danger">Search failed</small>';
            }
        }, 150);
    }

    /**
     * Navigate directly to a song by exact number.
     */
    async goToSong(selectId, number, modalId) {
        const select = document.getElementById(selectId);
        const songbook = select?.value || '';

        if (!songbook || !number) return;

        /* Close modal if provided */
        if (modalId) {
            const modalEl = document.getElementById(modalId);
            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
        }

        /* Navigate to the song using the songbook-padded ID format */
        const paddedNumber = number.padStart(4, '0');
        this.app.router.navigate(`/song/${songbook}-${paddedNumber}`);
    }

    /**
     * Populate a songbook dropdown from the API.
     */
    async populateSongbookDropdown(selectId) {
        const select = document.getElementById(selectId);
        if (!select || select.options.length > 1) return;

        try {
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'songbooks');
            const response = await fetch(url);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (data.songbooks) {
                /* Exclude Misc — songs in that collection have no songbook
                   number, so "jump by number" makes no sense there (#392). */
                select.innerHTML = data.songbooks
                    .filter(b => b.songCount > 0 && b.id !== 'Misc')
                    .map(b => `<option value="${escapeHtml(b.id)}">${escapeHtml(b.name)} (${escapeHtml(b.id)})</option>`)
                    .join('');

                /* Pre-select default songbook if set */
                const defaultBook = localStorage.getItem(STORAGE_DEFAULT_SONGBOOK);
                if (defaultBook) select.value = defaultBook;
            }
        } catch (error) {
            console.error('[Numpad] Failed to load songbooks:', error);
        }
    }
}
