/**
 * iHymns — Search Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Handles all search functionality including the header search bar
 * and the dedicated search page.
 *
 * ARCHITECTURE (#1014 — DB-direct rewrite):
 *   Search is a LIVE MySQL query. The search page, the header
 *   typeahead, and lyrics search all hit the server API
 *   (`?action=search` / `?action=suggest`) on every keystroke
 *   (debounced). There is NO client-side corpus and NO Fuse.js index
 *   anymore — staleness is impossible because every read is live.
 *
 *   Typo tolerance + partial-word matching is done server-side via the
 *   FULLTEXT BOOLEAN prefix strategy (see SongData::searchSongs, D2),
 *   so the UX that Fuse.js used to provide is preserved without
 *   shipping the whole catalogue to the browser. As of WS-C (#1015)
 *   neither search nor Song of the Day fetches the corpus — SoTD has
 *   its own live endpoint. (The only remaining client corpus consumer
 *   is the offline-download feature in settings.js, addressed by WS-I.)
 */
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { toTitleCase } from '../utils/text.js';
import { STORAGE_SEARCH_LYRICS, songbookLabel } from '../constants.js';

/** Results fetched per page (and per "Load more" click). */
const PAGE_SIZE = 50;

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

        /** @type {boolean} Whether "search within lyrics" is active */
        this.lyricsSearchEnabled = false;

        /** @type {object|null} Current search-page pagination state */
        this._search = null;

        /** @type {number} Monotonic token so stale autocomplete responses
         *  (out-of-order network replies) can't clobber a newer query. */
        this._acSeq = 0;
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
                /* Let autocomplete handle arrow keys and Escape */
                if (this._handleAutocompleteKeydown(e, searchInput)) return;

                if (e.key === 'Enter') {
                    e.preventDefault();
                    this._closeAutocomplete(searchInput);
                    const q = searchInput.value.trim();
                    this.toggleHeaderSearch(false);
                    this.app.router.navigate('/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
                }
            });

            /* Autocomplete on input (#307) */
            this._initAutocomplete(searchInput);
        }

        /* Clear button */
        const clearBtn = document.getElementById('search-clear-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    clearBtn.classList.add('d-none');
                    this._closeAutocomplete(searchInput);
                    searchInput.focus();
                }
            });
        }

        /* Show/hide clear button based on input */
        if (searchInput && clearBtn) {
            searchInput.addEventListener('input', () => {
                clearBtn.classList.toggle('d-none', !searchInput.value);
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

    /* =====================================================================
     * SEARCH PAGE INTEGRATION
     * ===================================================================== */

    /**
     * Initialise the search page controls (called after page loads).
     */
    initSearchPage() {
        const input = document.getElementById('page-search-input');
        const filter = document.getElementById('page-search-filter');
        const results = document.getElementById('text-search-results');
        const lyricsToggle = document.getElementById('search-lyrics-toggle');

        if (!input || !results) return;

        /* Restore lyrics toggle state from localStorage */
        if (lyricsToggle) {
            this.lyricsSearchEnabled = localStorage.getItem(STORAGE_SEARCH_LYRICS) === 'true';
            lyricsToggle.checked = this.lyricsSearchEnabled;

            lyricsToggle.addEventListener('change', () => {
                this.lyricsSearchEnabled = lyricsToggle.checked;
                localStorage.setItem(STORAGE_SEARCH_LYRICS, String(this.lyricsSearchEnabled));
                this.app.syncStorage(STORAGE_SEARCH_LYRICS);

                /* Re-run current search with the new mode (server decides
                   whether lyrics participate — no client index to build). */
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                }
            });
        }

        /* Render search history chips (#110) */
        const historyContainer = document.getElementById('search-history-container');
        if (historyContainer && this.app.searchHistory) {
            this.app.searchHistory.renderChips(historyContainer, (query) => {
                input.value = query;
                input.dispatchEvent(new Event('input'));
            });
        }

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

    /* =====================================================================
     * SEARCH EXECUTION — live MySQL API
     * ===================================================================== */

    /**
     * Perform a search and display results.
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @param {HTMLElement} container Results container element
     * @param {boolean} [append] True when fetching the next page ("Load more")
     */
    async performSearch(query, songbook, container, append = false) {
        try {
            if (!append) {
                /* Fresh search — reset pagination + scaffold the container. */
                this._search = { query, songbook, offset: 0, loaded: 0 };
                container.innerHTML = `
                    <p class="text-muted small mb-2" id="search-count"></p>
                    <div class="list-group" id="search-results-list"></div>
                    <div id="search-loadmore" class="text-center mt-3"></div>`;
            }

            const state = this._search;
            const { results, hasMore } = await this.apiSearch(state.query, state.songbook, state.offset);

            /* No results on a fresh search → friendly empty state. */
            if (!append && (!results || results.length === 0)) {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-face-sad-tear fa-2x mb-2 opacity-50" aria-hidden="true"></i>
                        <p>No results found for "<strong>${escapeHtml(query)}</strong>"</p>
                        <small>Try different keywords or check your spelling</small>
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-primary btn-sm btn-request-song"
                                    data-prefill="${escapeHtml(query)}">
                                <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                                Can't find it? Request this song
                            </button>
                        </div>
                    </div>`;
                return;
            }

            const list = container.querySelector('#search-results-list');
            const countEl = container.querySelector('#search-count');
            const moreEl = container.querySelector('#search-loadmore');

            if (results && results.length && list) {
                list.insertAdjacentHTML('beforeend', this._renderResultItems(results));
                state.loaded += results.length;
                /* Advance the DB offset by the page size (not the post-
                   filter count) so it matches the server's offset window. */
                state.offset += PAGE_SIZE;
            }

            if (countEl) {
                const n = state.loaded;
                countEl.textContent = `${n}${hasMore ? '+' : ''} result${n !== 1 ? 's' : ''} found`;
            }

            /* "Load more" button when the server reports another page. */
            if (moreEl) {
                if (hasMore) {
                    moreEl.innerHTML = `
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="search-loadmore-btn">
                            <i class="fa-solid fa-chevron-down me-1" aria-hidden="true"></i>Load more
                        </button>`;
                    const btn = moreEl.querySelector('#search-loadmore-btn');
                    btn?.addEventListener('click', () => {
                        btn.disabled = true;
                        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Loading…`;
                        this.performSearch(state.query, state.songbook, container, true);
                    }, { once: true });
                } else {
                    moreEl.innerHTML = '';
                }
            }

            /* Record history + analytics on the first page only. */
            if (!append) {
                if (this.app.searchHistory) this.app.searchHistory.record(query);
                if (this.app.analytics) this.app.analytics.trackSearch(query, state.loaded);
            }
        } catch (error) {
            console.error('[Search] Error:', error);
            /* Live search failed (offline / server error). Signal the offline
               indicator (#112) and, for a fresh search, fall back to the
               precached slim index so the user can still find titles. */
            try { window.dispatchEvent(new Event('ihymns:fetch-failed')); } catch (_e) {}
            if (!append) {
                const handled = await this._offlineSearchFallback(query, songbook, container);
                if (!handled) {
                    container.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-wifi-slash me-2" aria-hidden="true"></i>
                            Search is unavailable offline and the song index hasn't been
                            cached yet. Reconnect to search.
                        </div>`;
                }
            } else {
                /* Keep the existing results; just reset the Load-more button. */
                const moreEl = container.querySelector('#search-loadmore');
                if (moreEl) moreEl.innerHTML = `<span class="text-muted small">Couldn't load more — try again.</span>`;
            }
        }
    }

    /**
     * Live server-side search.
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @param {number} offset Pagination offset
     * @returns {Promise<{results: Array, hasMore: boolean, total: number}>}
     */
    async apiSearch(query, songbook, offset = 0) {
        const url = new URL(this.app.config.apiUrl, window.location.origin);
        url.searchParams.set('action', 'search');
        url.searchParams.set('q', query);
        url.searchParams.set('limit', String(PAGE_SIZE));
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('lyrics', this.lyricsSearchEnabled ? '1' : '0');
        if (songbook) url.searchParams.set('songbook', songbook);

        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error(`Search API: HTTP ${response.status}`);
        const data = await response.json();
        /* A live response means we're online — let the offline indicator
           clear its "you're offline" banner (#112 / WS-I). */
        try { window.dispatchEvent(new Event('ihymns:fetch-succeeded')); } catch (_e) {}
        return {
            results: data.results || [],
            hasMore: !!data.hasMore,
            total: data.total || 0,
        };
    }

    /* =====================================================================
     * OFFLINE FALLBACK (WS-I #1017) — slim index, no live API
     * ===================================================================== */

    /**
     * Fetch + memoise the slim catalogue index (id/number/title/songbook).
     * Served live when online; served from the service-worker cache when
     * offline (it's precached). This is the offline-only fallback for
     * search + autocomplete now that the Fuse.js corpus is gone.
     *
     * @returns {Promise<Array>}
     */
    async _getSlimIndex() {
        if (this._slimIndex) return this._slimIndex;
        const url = new URL(this.app.config.dataUrl, window.location.origin);
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(`Slim index: HTTP ${response.status}`);
        const data = await response.json();
        this._slimIndex = data.songs || [];
        return this._slimIndex;
    }

    /**
     * Filter the slim index by title / number (and songbook) — the basic
     * client-side search used when the live API is unreachable.
     *
     * @param {Array} index Slim song rows
     * @param {string} query
     * @param {string} songbook
     * @returns {Array} Matching rows (capped at PAGE_SIZE)
     */
    _filterSlimIndex(index, query, songbook) {
        const q = query.toLowerCase();
        const book = songbook ? songbook.toUpperCase() : '';
        const out = [];
        for (const s of index) {
            if (book && (s.songbook || '').toUpperCase() !== book) continue;
            const title = (s.title || '').toLowerCase();
            const num = String(s.number == null ? '' : s.number);
            if (title.indexOf(q) !== -1 || (q && num.indexOf(q) !== -1)) {
                out.push(s);
                if (out.length >= PAGE_SIZE) break;
            }
        }
        return out;
    }

    /**
     * Offline fallback for the search page: render results filtered from the
     * precached slim index with a clear "you're offline" note. Returns true
     * if it rendered (index available), false otherwise.
     *
     * @param {string} query
     * @param {string} songbook
     * @param {HTMLElement} container
     * @returns {Promise<boolean>}
     */
    async _offlineSearchFallback(query, songbook, container) {
        let index;
        try {
            index = await this._getSlimIndex();
        } catch (_e) {
            return false;
        }
        if (!Array.isArray(index) || index.length === 0) return false;

        const results = this._filterSlimIndex(index, query, songbook);
        container.innerHTML = `
            <div class="alert alert-warning py-2 small mb-2" role="status">
                <i class="fa-solid fa-wifi-slash me-1" aria-hidden="true"></i>
                You're offline — searching cached song titles only. Songs you've
                opened before will still open; others need a connection.
            </div>
            <p class="text-muted small mb-2">${results.length} match${results.length !== 1 ? 'es' : ''} in the offline index</p>
            <div class="list-group">${this._renderResultItems(results)}</div>`;
        return true;
    }

    /* =====================================================================
     * RENDERING
     * ===================================================================== */

    /**
     * Render a batch of result rows as list-group anchors.
     *
     * @param {Array} results Array of song summary objects
     * @returns {string} HTML string
     */
    _renderResultItems(results) {
        let html = '';
        results.forEach(song => {
            /* Canonical separator is "; " (#495). Surname-first hymnal
               citations legitimately contain commas inside a single
               name ("Smith, John"), so comma is ambiguous. */
            const writers = (song.writers || []).join('; ');
            const snippet = song.lyricsSnippet
                ? `<small class="text-muted d-block fst-italic"><i class="fa-solid fa-music me-1" aria-hidden="true"></i>&ldquo;${escapeHtml(song.lyricsSnippet)}&rdquo;</small>`
                : '';
            /* Curator alt-title hint (#832) — "(known as: …)". */
            const altName = song.matchedVia && song.matchedVia.alternativeTitle
                ? `<small class="text-muted d-block"><i class="fa-solid fa-tag me-1" aria-hidden="true"></i>known as &ldquo;${escapeHtml(song.matchedVia.alternativeTitle)}&rdquo;</small>`
                : '';
            html += `
                <a href="/song/${escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(song.id)}">
                    <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook || '')}">${song.number}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(toTitleCase(song.title))}${verifiedBadge(song)}</span>
                        <small class="text-muted d-block">
                            ${songbookLabel(song.songbook, song.songbookName)}
                            ${writers ? ' &middot; ' + escapeHtml(writers) : ''}
                        </small>
                        ${altName}
                        ${snippet}
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>`;
        });
        return html;
    }

    /* =====================================================================
     * AUTOCOMPLETE / SUGGESTIONS (#307) — live `?action=suggest`
     * ===================================================================== */

    /**
     * Initialise autocomplete behaviour on a search input.
     * Shows a dropdown of matching songs as the user types.
     *
     * @param {HTMLInputElement} input The search input element
     */
    _initAutocomplete(input) {
        let acTimer = null;

        /* Ensure the input's parent is positioned for the dropdown */
        const parent = input.closest('.input-group') || input.parentElement;
        if (parent) parent.style.position = 'relative';

        input.addEventListener('input', () => {
            clearTimeout(acTimer);
            const q = input.value.trim();
            if (q.length < 2) {
                this._closeAutocomplete(input);
                return;
            }
            acTimer = setTimeout(() => this._showAutocomplete(input, q), 300);
        });

        /* Close autocomplete when input loses focus (with delay for click) */
        input.addEventListener('blur', () => {
            setTimeout(() => this._closeAutocomplete(input), 200);
        });
    }

    /**
     * Show autocomplete suggestions below the input (live MySQL query).
     *
     * @param {HTMLInputElement} input The search input
     * @param {string} query Current query
     */
    async _showAutocomplete(input, query) {
        /* Stale-response guard — only the newest request may render. */
        const seq = ++this._acSeq;

        let suggestions;
        try {
            const url = new URL(this.app.config.apiUrl, window.location.origin);
            url.searchParams.set('action', 'suggest');
            url.searchParams.set('q', query);
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error(`Suggest API: HTTP ${response.status}`);
            const data = await response.json();
            suggestions = data.suggestions || [];
        } catch (error) {
            /* Offline / server error — fall back to the precached slim
               index so the header typeahead still works offline (WS-I). */
            try {
                const index = await this._getSlimIndex();
                suggestions = this._filterSlimIndex(index, query, '').slice(0, 8);
            } catch (_e) {
                this._closeAutocomplete(input);
                return;
            }
        }

        /* A newer keystroke superseded this request, or the input was
           cleared/blurred while we were waiting — discard. */
        if (seq !== this._acSeq) return;
        if (input.value.trim() !== query) return;

        if (!suggestions.length) {
            this._closeAutocomplete(input);
            return;
        }

        /* Find or create dropdown */
        const parent = input.closest('.input-group') || input.parentElement;
        let dropdown = parent.querySelector('.search-autocomplete');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'search-autocomplete';
            dropdown.setAttribute('role', 'listbox');
            parent.appendChild(dropdown);
        }

        dropdown.innerHTML = suggestions.map((song, i) => {
            return `<a href="/song/${escapeHtml(song.id)}"
                       class="search-autocomplete-item${i === 0 ? ' active' : ''}"
                       data-navigate="song"
                       data-song-id="${escapeHtml(song.id)}"
                       data-index="${i}"
                       role="option">
                        <span class="song-num">${escapeHtml(song.songbook || '')} ${song.number || ''}</span>
                        <span>${escapeHtml(toTitleCase(song.title || ''))}</span>
                    </a>`;
        }).join('');

        /* Click handler for suggestions */
        dropdown.querySelectorAll('.search-autocomplete-item').forEach(item => {
            item.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this._closeAutocomplete(input);
                if (this.app.router) {
                    this.app.router.navigate('/song/' + item.dataset.songId);
                } else {
                    window.location.href = item.href;
                }
            });
        });
    }

    /**
     * Close the autocomplete dropdown for a given input.
     *
     * @param {HTMLInputElement} input The search input
     */
    _closeAutocomplete(input) {
        const parent = input.closest('.input-group') || input.parentElement;
        const dropdown = parent?.querySelector('.search-autocomplete');
        if (dropdown) dropdown.remove();
    }

    /**
     * Handle keyboard navigation within the autocomplete dropdown.
     * Returns true if the event was consumed.
     *
     * @param {KeyboardEvent} e The keydown event
     * @param {HTMLInputElement} input The search input
     * @returns {boolean} True if event was handled
     */
    _handleAutocompleteKeydown(e, input) {
        const parent = input.closest('.input-group') || input.parentElement;
        const dropdown = parent?.querySelector('.search-autocomplete');
        if (!dropdown) return false;

        const items = dropdown.querySelectorAll('.search-autocomplete-item');
        if (items.length === 0) return false;

        if (e.key === 'Escape') {
            e.preventDefault();
            this._closeAutocomplete(input);
            return true;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const activeItem = dropdown.querySelector('.search-autocomplete-item.active');
            let idx = activeItem ? parseInt(activeItem.dataset.index, 10) : -1;

            if (e.key === 'ArrowDown') {
                idx = Math.min(idx + 1, items.length - 1);
            } else {
                idx = Math.max(idx - 1, 0);
            }

            items.forEach(item => item.classList.remove('active'));
            items[idx]?.classList.add('active');
            items[idx]?.scrollIntoView({ block: 'nearest' });
            return true;
        }

        if (e.key === 'Enter') {
            const activeItem = dropdown.querySelector('.search-autocomplete-item.active');
            if (activeItem) {
                e.preventDefault();
                this._closeAutocomplete(input);
                if (this.app.router) {
                    this.app.router.navigate('/song/' + activeItem.dataset.songId);
                } else {
                    window.location.href = activeItem.href;
                }
                return true;
            }
        }

        return false;
    }

}
