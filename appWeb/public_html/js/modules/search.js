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
 *   Search is a LIVE MySQL query. The search page and lyrics search
 *   hit the server API (`?action=search`) on every keystroke
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
/* #1031 — shared client: attaches X-Preferred-Languages + X-Requested-With
   on every same-origin request and dispatches EVT_FETCH_FAILED/SUCCEEDED
   itself, replacing the old global fetch monkey-patch. */
import { apiFetch } from '../utils/api-client.js';
/* #307 — the header-search autocomplete typeahead (which imported
   combobox-a11y.js for its ARIA, #1594 part 2) was removed as dead code: its
   `_initAutocomplete` had zero callers, so the dropdown, its CSS, the
   `?action=suggest` endpoint and SongData::suggestSongs() were all deleted. The
   combobox-a11y side-effect import went with it — nothing in this module uses
   `window.iHymnsComboboxA11y` any more (the shared module lives on for
   place-search.js et al., which import it themselves). */

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
     * Initialise the search module.
     *
     * ELI5: there is nothing to hook up when the app boots — the search box
     * lives on the /search page, and that page wires itself up in
     * `initSearchPage()` when the router lands on it.
     *
     * Detail: this used to bind a header search bar (toggle button, input,
     * clear button, typeahead). #812 removed that bar from index.php — "the
     * bottom footer-nav already exposes a Search entry that navigates to
     * /search; one affordance is enough, and the saved real-estate matters
     * most on mobile portrait" — and updated app.css to match, but not this
     * module. The bindings survived as `getElementById` calls returning null
     * behind `if (el)` guards: no error, no console warning, nothing to grep,
     * and the two shortcuts that depended on them silently stopped working
     * for as long as nobody pressed them. See `openSearch()` below and
     * tests/test-dom-target-integrity.js, which now fails the build on any id
     * the JS looks up that no markup emits.
     */
    init() {
        /* Intentionally empty — see the doc-comment above. Kept as a method
           because app.js calls it during boot alongside every other module's
           init(), and a module that opts out of that convention is the next
           thing somebody "cleans up" without checking. */
    }

    /**
     * Open search — the target of the `/` and Ctrl/Cmd+K shortcuts.
     *
     * ELI5: take the user to the search page and put the cursor in the box.
     *
     * Detail: replaces `toggleHeaderSearch(true)`, which opened the header
     * search bar deleted in #812 and therefore did nothing at all — while the
     * shortcuts overlay (js/modules/shortcuts.js) and the public help page
     * (includes/pages/help.php) both continued to advertise `/` and
     * `Ctrl`+`K` as "Open search". Because the callers call
     * `e.preventDefault()` first, `/` did not even insert a slash: the
     * keystroke was consumed and discarded.
     *
     * `/search` is the affordance #812 explicitly kept, so this routes there
     * and focuses the page's own input rather than resurrecting the bar.
     *
     * Ordering matters: `router.navigate()` resolves only after the fragment
     * has been injected and `afterPageLoad()` has run, so `#page-search-input`
     * exists by the time we reach for it. When the user is ALREADY on
     * /search, `navigate()` early-returns on the unchanged path, so we skip it
     * and focus directly — otherwise the shortcut would be a no-op on the one
     * page where it is most likely to be pressed a second time.
     *
     * @returns {Promise<void>}
     */
    async openSearch() {
        const SEARCH_PATH = '/search';

        if (window.location.pathname !== SEARCH_PATH) {
            if (this.app.router) {
                /* Never let a failed navigation swallow the shortcut silently —
                   that is the exact class of bug this method exists to fix. */
                try {
                    await this.app.router.navigate(SEARCH_PATH);
                } catch (err) {
                    console.error('[Search] openSearch navigation failed:', err);
                    return;
                }
            } else {
                /* No router (very early boot / hard-failure) — a full page load
                   still gets the user where they asked to go. */
                window.location.href = SEARCH_PATH;
                return;
            }
        }

        const input = document.getElementById('page-search-input');
        if (!input) return;
        input.focus();
        /* Select any prefilled `?q=` text so a second press lets the user
           retype immediately instead of appending to the old query. */
        if (typeof input.select === 'function' && input.value) input.select();
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

        /* Enter must search HERE, not reload the app.
         *
         * ELI5: pressing Enter in a search box should search. It used to throw
         * the page away instead.
         *
         * Detail: this form has one text-type field and no submit button, which
         * is precisely the case where the HTML spec says Enter performs an
         * implicit submission. Nothing bound a submit handler to it — search.js
         * intercepted Enter only on the header search input deleted in #812 —
         * so the browser navigated, the SPA hard-reloaded, and the typed query
         * was discarded with no error anywhere. Silent, and on the one gesture
         * every user makes.
         *
         * preventDefault() keeps us on the page; cancelling the pending
         * debounce stops the timer firing a second, identical search a moment
         * later. Queries shorter than the 2-character floor the debounce
         * enforces are ignored here too, so Enter and typing agree.
         * https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#implicit-submission
         *
         * The form keeps its `action="/search"` + `name="q"` as the no-JS
         * fallback; see the note in includes/pages/search.php.
         * Guard: tests/test-search-enter-submit.js
         */
        const form = document.getElementById('page-search-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                clearTimeout(this.debounceTimer);
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                }
            });
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
            /* Live search failed (offline / server error). apiFetch already
               dispatched EVT_FETCH_FAILED for the offline indicator (#112) —
               this used to do it by hand because search.js was the ONLY module
               signalling connectivity; since #1031 every request does, so a
               second dispatch here would just fire the listener twice.
               Fall back to the precached slim index so the user can still find
               titles. */
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

        const response = await apiFetch(url);
        if (!response.ok) throw new Error(`Search API: HTTP ${response.status}`);
        const data = await response.json();
        /* EVT_FETCH_SUCCEEDED is dispatched by apiFetch (#1031), which clears
           the offline indicator's banner (#112 / WS-I) for EVERY request now,
           not just search's. */
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
        const response = await apiFetch(url);
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

}
