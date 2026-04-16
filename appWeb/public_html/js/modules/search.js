/**
 * iHymns — Search Module
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Handles all search functionality including the header search bar
 * and the dedicated search page. Uses Fuse.js for client-side fuzzy
 * search with weighted field scoring and typo tolerance. Falls back
 * to the server-side PHP API if Fuse.js fails to load.
 *
 * ARCHITECTURE (#82):
 *   1. On first search interaction, Fuse.js is dynamically loaded
 *      from CDN (with local fallback).
 *   2. The songs.json data is fetched and a Fuse.js index is built.
 *   3. Subsequent searches run entirely client-side (no network).
 *   4. If either step fails, falls back to API-based substring search.
 *   5. Offline search works when songs.json is cached by service worker.
 */
import { escapeHtml, verifiedBadge } from '../utils/html.js';
import { STORAGE_SEARCH_LYRICS, songbookLabel } from '../constants.js';

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

        /** @type {object|null} Fuse.js instance (null until loaded) */
        this.fuseIndex = null;

        /** @type {Array|null} Raw songs data for songbook filtering */
        this.songsData = null;

        /** @type {boolean} True if Fuse.js has been attempted and failed */
        this.fuseFailed = false;

        /** @type {boolean} True if currently loading Fuse.js/data */
        this.fuseLoading = false;

        /** @type {object|null} Fuse.js instance with lyrics (#93) */
        this.fuseLyricsIndex = null;

        /** @type {boolean} Whether lyrics search is active */
        this.lyricsSearchEnabled = false;

        /** @type {object|null} Fuse.js constructor reference */
        this.FuseClass = null;
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

        /* Eagerly start loading Fuse.js in the background */
        this.loadFuseIndex();
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
     * FUSE.JS LOADING & INDEX BUILDING (#82)
     * ===================================================================== */

    /**
     * Load Fuse.js from CDN (with local fallback) and build the search index.
     * This runs asynchronously; search falls back to API until ready.
     */
    async loadFuseIndex() {
        /* Don't re-attempt if already loaded, loading, or permanently failed */
        if (this.fuseIndex || this.fuseLoading || this.fuseFailed) return;

        this.fuseLoading = true;

        try {
            /* Step 1: Dynamically import Fuse.js from CDN */
            let Fuse;
            try {
                const fuseModule = await import(this.app.config.fuseJsCdn);
                Fuse = fuseModule.default || fuseModule;
            } catch {
                /* CDN failed — try local fallback */
                console.warn('[Search] Fuse.js CDN failed, trying local fallback');
                try {
                    const fuseLocal = await import('/' + this.app.config.fuseJsLocal);
                    Fuse = fuseLocal.default || fuseLocal;
                } catch {
                    throw new Error('Fuse.js could not be loaded from CDN or local');
                }
            }

            /* Step 2: Fetch the song data (may be served from SW cache) */
            const response = await fetch(this.app.config.dataUrl);
            if (!response.ok) throw new Error('Failed to fetch songs.json');
            const data = await response.json();

            this.songsData = data.songs || [];
            this.FuseClass = Fuse;

            /* Prepare lyricsText field for lyrics search (#93) */
            this.songsData.forEach(song => {
                const lines = [];
                (song.components || []).forEach(c => {
                    (c.lines || []).forEach(l => lines.push(l));
                });
                song.lyricsText = lines.join(' ');
            });

            /* Step 3: Build the Fuse.js index with weighted field scoring */
            this.fuseIndex = new Fuse(this.songsData, {
                /**
                 * Fuse.js search configuration:
                 *   - keys: Fields to search, weighted by importance
                 *   - threshold: 0 = exact match, 1 = match anything (0.35 = moderate fuzzy)
                 *   - distance: How far from the expected position a match can be
                 *   - minMatchCharLength: Minimum characters before considering a match
                 *   - includeScore: Return match quality score for ranking
                 *   - shouldSort: Sort results by relevance score
                 *   - ignoreLocation: Search across the entire string (not just start)
                 */
                keys: [
                    { name: 'title',        weight: 3.0 },  /* Title matches rank highest */
                    { name: 'songbookName', weight: 1.5 },  /* Songbook name matches */
                    { name: 'writers',      weight: 1.2 },  /* Writer/author matches */
                    { name: 'composers',    weight: 1.0 },  /* Composer matches */
                ],
                threshold: 0.35,
                distance: 200,
                minMatchCharLength: 2,
                includeScore: true,
                shouldSort: true,
                ignoreLocation: true,
            });

            console.log(`[Search] Fuse.js index built: ${this.songsData.length} songs`);

            /* Re-render Song of the Day if home page is active (#108) —
               the home page may have rendered before song data finished loading */
            if (document.getElementById('song-of-the-day') && this.app.songOfTheDay) {
                this.app.songOfTheDay.renderHomeSection();
            }

        } catch (error) {
            console.warn('[Search] Fuse.js loading failed, using API fallback:', error);
            this.fuseFailed = true;
        } finally {
            this.fuseLoading = false;
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

                /* Build lyrics index on first enable */
                if (this.lyricsSearchEnabled && !this.fuseLyricsIndex && this.FuseClass && this.songsData) {
                    this.buildLyricsIndex();
                }

                /* Re-run current search with new mode */
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                }
            });

            /* Build lyrics index eagerly if toggle was previously enabled */
            if (this.lyricsSearchEnabled && !this.fuseLyricsIndex && this.FuseClass && this.songsData) {
                this.buildLyricsIndex();
            }
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

    /**
     * Build the lyrics-inclusive Fuse.js index on demand (#93).
     * Uses higher threshold for lyrics (lyrics text is longer and noisier).
     */
    buildLyricsIndex() {
        if (!this.FuseClass || !this.songsData) return;

        this.fuseLyricsIndex = new this.FuseClass(this.songsData, {
            keys: [
                { name: 'title',        weight: 3.0 },
                { name: 'songbookName', weight: 1.5 },
                { name: 'writers',      weight: 1.2 },
                { name: 'composers',    weight: 1.0 },
                { name: 'lyricsText',   weight: 0.8 },
            ],
            threshold: 0.3,
            distance: 500,
            minMatchCharLength: 3,
            includeScore: true,
            includeMatches: true,
            shouldSort: true,
            ignoreLocation: true,
        });

        console.log(`[Search] Lyrics Fuse.js index built: ${this.songsData.length} songs`);
    }

    /* =====================================================================
     * SEARCH EXECUTION — Fuse.js (preferred) with API fallback
     * ===================================================================== */

    /**
     * Perform a search and display results.
     * Uses Fuse.js if available; falls back to server-side API otherwise.
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @param {HTMLElement} container Results container element
     */
    async performSearch(query, songbook, container) {
        try {
            let results;

            if (this.fuseIndex && !this.fuseFailed) {
                /* --- Client-side fuzzy search via Fuse.js --- */
                results = this.fuseSearch(query, songbook);
            } else {
                /* --- Fallback: server-side API search --- */
                results = await this.apiSearch(query, songbook);
            }

            if (results && results.length > 0) {
                const method = this.fuseIndex && !this.fuseFailed ? 'fuzzy' : 'basic';
                container.innerHTML = this.renderSearchResults(results, results.length, method);
                /* Record successful search (#110) */
                if (this.app.searchHistory) {
                    this.app.searchHistory.record(query);
                }
                /* Track search analytics */
                if (this.app.analytics) {
                    this.app.analytics.trackSearch(query, results.length);
                }
            } else {
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
     * Client-side fuzzy search using Fuse.js.
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @returns {Array} Array of song summary objects
     */
    fuseSearch(query, songbook) {
        /* Use lyrics index when lyrics search is enabled (#93) */
        const index = (this.lyricsSearchEnabled && this.fuseLyricsIndex)
            ? this.fuseLyricsIndex
            : this.fuseIndex;

        let fuseResults = index.search(query, { limit: 50 });

        /* Apply songbook filter if specified */
        if (songbook) {
            const bookId = songbook.toUpperCase();
            fuseResults = fuseResults.filter(r => (r.item.songbook || '').toUpperCase() === bookId);
        }

        /* Map Fuse.js results to the same format as API results */
        return fuseResults.map(r => {
            const result = {
                id:           r.item.id,
                number:       r.item.number,
                title:        r.item.title,
                songbook:     r.item.songbook,
                songbookName: r.item.songbookName,
                writers:      r.item.writers || [],
                hasAudio:     r.item.hasAudio || false,
                hasSheetMusic: r.item.hasSheetMusic || false,
            };

            /* Extract lyrics snippet if match was in lyrics (#93) */
            if (this.lyricsSearchEnabled && r.matches) {
                const lyricsMatch = r.matches.find(m => m.key === 'lyricsText');
                if (lyricsMatch) {
                    result.lyricsSnippet = this.extractLyricsSnippet(r.item, query);
                }
            }

            return result;
        });
    }

    /**
     * Extract a matching lyrics snippet from a song (#93).
     * Finds the first line containing the query and returns it.
     *
     * @param {object} song Song object
     * @param {string} query Search query
     * @returns {string} Matching line or empty string
     */
    extractLyricsSnippet(song, query) {
        const q = query.toLowerCase();
        for (const comp of (song.components || [])) {
            for (const line of (comp.lines || [])) {
                if (line.toLowerCase().includes(q)) {
                    return line;
                }
            }
        }
        return '';
    }

    /**
     * Server-side API search (fallback when Fuse.js is unavailable).
     *
     * @param {string} query Search query
     * @param {string} songbook Songbook filter (empty = all)
     * @returns {Promise<Array>} Array of song summary objects
     */
    async apiSearch(query, songbook) {
        const url = new URL(this.app.config.apiUrl, window.location.origin);
        url.searchParams.set('action', 'search');
        url.searchParams.set('q', query);
        if (songbook) url.searchParams.set('songbook', songbook);

        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error(`Search API: HTTP ${response.status}`);
        const data = await response.json();
        return data.results || [];
    }

    /* =====================================================================
     * RENDERING
     * ===================================================================== */

    /**
     * Render search results as a list group.
     *
     * @param {Array} results Array of song summary objects
     * @param {number} total Total number of results
     * @returns {string} HTML string
     */
    renderSearchResults(results, total, method) {
        let html = `<p class="text-muted small mb-2">${total} result${total !== 1 ? 's' : ''} found</p>`;
        html += '<div class="list-group">';

        results.forEach(song => {
            const writers = (song.writers || []).join(', ');
            const snippet = song.lyricsSnippet
                ? `<small class="text-muted d-block fst-italic"><i class="fa-solid fa-music me-1" aria-hidden="true"></i>&ldquo;${escapeHtml(song.lyricsSnippet)}&rdquo;</small>`
                : '';
            html += `
                <a href="/song/${escapeHtml(song.id)}"
                   class="list-group-item list-group-item-action song-list-item"
                   data-navigate="song"
                   data-song-id="${escapeHtml(song.id)}">
                    <span class="song-number-badge" data-songbook="${escapeHtml(song.songbook || '')}">${song.number}</span>
                    <div class="song-info flex-grow-1">
                        <span class="song-title">${escapeHtml(song.title)}${verifiedBadge(song)}</span>
                        <small class="text-muted d-block">
                            ${songbookLabel(song.songbook, song.songbookName)}
                            ${writers ? ' &middot; ' + escapeHtml(writers) : ''}
                        </small>
                        ${snippet}
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </a>`;
        });

        html += '</div>';
        return html;
    }

    /* =====================================================================
     * AUTOCOMPLETE / SUGGESTIONS (#307)
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
     * Show autocomplete suggestions below the input.
     *
     * @param {HTMLInputElement} input The search input
     * @param {string} query Current query
     */
    _showAutocomplete(input, query) {
        /* Use Fuse.js if available, otherwise skip (full search page handles it) */
        if (!this.fuseIndex) return;

        const results = this.fuseIndex.search(query, { limit: 8 });
        if (results.length === 0) {
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

        dropdown.innerHTML = results.map((r, i) => {
            const song = r.item;
            return `<a href="/song/${escapeHtml(song.id)}"
                       class="search-autocomplete-item${i === 0 ? ' active' : ''}"
                       data-navigate="song"
                       data-song-id="${escapeHtml(song.id)}"
                       data-index="${i}"
                       role="option">
                        <span class="song-num">${escapeHtml(song.songbook || '')} ${song.number || ''}</span>
                        <span>${escapeHtml(song.title || '')}</span>
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
