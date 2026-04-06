/**
 * iHymns — Numeric Keypad Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Provides a touch-friendly numeric keypad for searching songs by
 * number. Works in the modal (global) and embedded on the search page.
 * Performs live search-as-you-type against the API.
 */

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

    /**
     * Initialise — bind events for the modal numpad.
     */
    init() {
        /* Populate songbook dropdown in modal */
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

        /* Pre-select songbook if provided */
        if (songbookId) {
            const select = document.getElementById('numpad-songbook');
            if (select) select.value = songbookId;
        }

        /* Clear previous results */
        const results = document.getElementById('numpad-results');
        if (results) results.innerHTML = '';

        /* Show the Bootstrap modal */
        const modal = document.getElementById('numpad-modal');
        if (modal) {
            new bootstrap.Modal(modal).show();
        }
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
        this.searchByNumber('numpad-songbook', this.currentNumber, 'numpad-results');
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
        this.populateSongbookDropdown('page-numpad-songbook');

        /* Page numpad button clicks */
        document.querySelectorAll('#panel-number-search [data-page-num]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handlePageKey(btn.dataset.pageNum);
            });
        });
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

        this.searchByNumber('page-numpad-songbook', this.pageNumber, 'page-numpad-results');
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
                                <strong>#${song.number}</strong> — ${this.escapeHtml(song.title)}
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
            const data = await response.json();

            if (data.songbooks) {
                select.innerHTML = data.songbooks
                    .filter(b => b.songCount > 0)
                    .map(b => `<option value="${b.id}">${b.name} (${b.id})</option>`)
                    .join('');
            }
        } catch (error) {
            console.error('[Numpad] Failed to load songbooks:', error);
        }
    }

    escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }
}
