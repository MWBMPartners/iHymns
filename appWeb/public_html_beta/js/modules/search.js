/**
 * iHymns — Search Module
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
 *
 * PURPOSE:
 * Handles all search functionality including the header search bar
 * and the dedicated search page. Performs AJAX searches against
 * the PHP API with debounced input handling.
 */

export class Search {
    /**
     * @param {object} app Reference to the main iHymnsApp instance
     */
    constructor(app) {
        this.app = app;

        /** @type {number} Debounce delay in milliseconds */
        this.debounceDelay = 300;

        /** @type {number|null} Debounce timer ID */
        this.debounceTimer = null;
    }

    /**
     * Initialise the search module — bind header search events.
     */
    init() {
        /* Header search toggle button */
        const toggle = document.getElementById('header-search-toggle');
        if (toggle) {
            toggle.addEventListener('click', () => {
                const bar = document.getElementById('header-search-bar');
                const isOpen = bar?.classList.contains('open');
                this.toggleHeaderSearch(!isOpen);
            });
        }

        /* Header search input — pressing Enter navigates to search page */
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const q = searchInput.value.trim();
                    this.toggleHeaderSearch(false);
                    this.app.router.navigate('/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
                }
            });
        }

        /* Clear button */
        const clearBtn = document.getElementById('search-clear-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    clearBtn.style.display = 'none';
                    searchInput.focus();
                }
            });
        }

        /* Show/hide clear button based on input */
        if (searchInput && clearBtn) {
            searchInput.addEventListener('input', () => {
                clearBtn.style.display = searchInput.value ? 'block' : 'none';
            });
        }
    }

    /**
     * Toggle the header search bar open/closed.
     *
     * @param {boolean} open True to open, false to close
     */
    toggleHeaderSearch(open) {
        const bar = document.getElementById('header-search-bar');
        const toggle = document.getElementById('header-search-toggle');
        const input = document.getElementById('search-input');

        if (!bar) return;

        if (open) {
            bar.classList.add('open');
            bar.setAttribute('aria-hidden', 'false');
            document.body.classList.add('search-open');
            toggle?.setAttribute('aria-expanded', 'true');
            /* Focus the search input after animation */
            setTimeout(() => input?.focus(), 300);
        } else {
            bar.classList.remove('open');
            bar.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('search-open');
            toggle?.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Initialise the search page controls (called after page loads).
     */
    initSearchPage() {
        const input = document.getElementById('page-search-input');
        const filter = document.getElementById('page-search-filter');
        const results = document.getElementById('text-search-results');

        if (!input || !results) return;

        /* Pre-fill from URL query string */
        const params = new URLSearchParams(window.location.search);
        const initialQuery = params.get('q');
        if (initialQuery) {
            input.value = initialQuery;
            this.performSearch(initialQuery, filter?.value || '', results);
        }

        /* Debounced search on input */
        input.addEventListener('input', () => {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                } else if (q.length === 0) {
                    results.innerHTML = `
                        <div class="text-center text-muted py-5" id="search-placeholder">
                            <i class="fa-solid fa-magnifying-glass fa-3x mb-3 opacity-25" aria-hidden="true"></i>
                            <p>Start typing to search across all songs</p>
                        </div>`;
                }
            }, this.debounceDelay);
        });

        /* Filter change triggers new search */
        if (filter) {
            filter.addEventListener('change', () => {
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter.value, results);
                }
            });
        }
    }

    /**
     * Perform a search via the API and display results.
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @param {HTMLElement} container Results container element
     */
    async performSearch(query, songbook, container) {
        try {
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'search');
            url.searchParams.set('q', query);
            if (songbook) url.searchParams.set('songbook', songbook);

            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();

            if (data.results && data.results.length > 0) {
                container.innerHTML = this.renderSearchResults(data.results, data.total);
            } else {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-face-sad-tear fa-2x mb-2 opacity-50" aria-hidden="true"></i>
                        <p>No results found for "<strong>${this.escapeHtml(query)}</strong>"</p>
                        <small>Try different keywords or check your spelling</small>
                    </div>`;
            }
        } catch (error) {
            console.error('[Search] Error:', error);
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    Search failed. Please try again.
                </div>`;
        }
    }

    /**
     * Render search results as a list group.
     *
     * @param {Array} results Array of song summary objects
     * @param {number} total Total number of results
     * @returns {string} HTML string
     */
    renderSearchResults(results, total) {
        let html = `<p class="text-muted small mb-2">${total} result${total !== 1 ? 's' : ''} found</p>`;
        html += '<div class="list-group">';

        results.forEach(song => {
            const writers = (song.writers || []).join(', ');
            html += `
                <a href="/song/${this.escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${this.escapeHtml(song.id)}">
                    <span class="song-number-badge">${song.number}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${this.escapeHtml(song.title)}</span>
                        <small class="text-muted d-block">
                            ${this.escapeHtml(song.songbookName)}
                            ${writers ? ' · ' + this.escapeHtml(writers) : ''}
                        </small>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>`;
        });

        html += '</div>';
        return html;
    }

    /**
     * Escape HTML special characters to prevent XSS.
     *
     * @param {string} str Input string
     * @returns {string} Escaped string
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
