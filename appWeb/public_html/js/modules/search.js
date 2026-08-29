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
import { toTitleCase, foldSearchText } from '../utils/text.js';
import { STORAGE_SEARCH_LYRICS, songbookLabel, songbookIsOfficial } from '../constants.js';
/* #1031 — shared client: attaches X-Preferred-Languages + X-Requested-With
   on every same-origin request and dispatches EVT_FETCH_FAILED/SUCCEEDED
   itself, replacing the old global fetch monkey-patch. */
import { apiFetch } from '../utils/api-client.js';
/* #1786 Option B — search is a SERVER-SORT surface: results are paginated
   ("Load more"), so re-ordering only the currently-loaded page client-side
   would silently sort a slice and lie about the rest. getListSort() reads
   the saved spec to build the `sort=` query param; wireListSortControl()
   re-runs a fresh (offset-0) search on change. The offline fallback (a
   client-only slim-index filter, ≤ PAGE_SIZE rows, no pagination) DOES sort
   client-side with the shared comparator — there is no server to ask. */
import { wireListSortControl, getListSort } from './list-sort.js';
import { multiKeyCompareMissingLast } from '../utils/sort-compare.js';
/* #1936 — the /search typeahead suggestion dropdown (see `_initSuggest` and
   friends below) reuses the shared ARIA + keyboard combobox helper instead of
   re-hand-rolling arrow/Home/End/Enter/Escape handling (modularity rule #22;
   the SAME helper place-search.js / compare.js / setlist.js / service-
   broadcast.js / request-a-song.js already consume). Imported for its SIDE
   EFFECT only — the module attaches `window.iHymnsComboboxA11y` and
   deliberately has NO ES export (a bound `import { … }` would be a runtime
   error), see its own doc-block for why. #1594 part 2.
   @link https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/import#import_a_module_for_its_side_effects_only */
import './combobox-a11y.js';

/** Allowed list-sort keys for the `search` surface — matches search.php's
 *  $listSortOptions; 'relevance' is the SERVER default and is never an
 *  explicit level a user picks (mirrors SongData::_searchOrderBy()). Both
 *  keys resolve to a TEXT comparator client-side (offline fallback only —
 *  `number` composes songbook+number into one padded string, the same
 *  combined-fragment shape SongData::_searchOrderBy() gives that key
 *  server-side); the live search path never sorts client-side at all. */
const SEARCH_SORT_TYPES = { title: 'text', number: 'text' };
/* #307 → #1936 — HISTORY. The ORIGINAL #307 header-search autocomplete
   (which imported combobox-a11y.js for its ARIA, #1594 part 2) was removed as
   dead code in #812 / commit 3e5e2a46: its `_initAutocomplete` had zero
   callers ("built, reachable from nowhere"), so the dropdown, its CSS, the
   `?action=suggest` endpoint and SongData::suggestSongs() were all deleted,
   and #307 was closed as superseded — the /search page searches live as you
   type, so the header typeahead had no host. The owner's close note asked for
   any revival to be "a fresh, scoped issue against the current search page":
   that is #1936, and it lives BELOW (`_initSuggest`) — a real typeahead
   suggestion dropdown wired to THIS page's `#page-search-input`, reachable
   from `initSearchPage()` (not from nowhere), reusing combobox-a11y (hence the
   side-effect import above is live again, deliberately). The `?action=suggest`
   endpoint stays deleted — #1936 reuses `?action=search` with a low limit. */

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

        /** @type {object|null} #1936 — the live typeahead suggestion dropdown
         *  state, created lazily by `_initSuggest()` on the first /search
         *  navigation (the router re-creates this fragment each time, so the
         *  panel + its ARIA are (re)built per visit). Shape:
         *  `{ input, panel, open, active, items, seq, idPrefix }`. `seq` is a
         *  monotonic token so a stale (out-of-order) suggestion response can't
         *  clobber a newer query's dropdown. Null until the page mounts. */
        this._suggest = null;
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
                /* #1936 — Enter with NO suggestion highlighted falls through to
                   here (the combobox only consumes Enter when a row is active),
                   so a submit is an explicit "run the full search" — dismiss
                   the quick-jump dropdown and let the results list below own the
                   screen. (Enter WITH a highlight never reaches this handler:
                   combobox-a11y preventDefault()s it and navigates.) */
                this._closeSuggest();
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                }
            });
        }

        /* #1936 — mount the live typeahead suggestion dropdown on this page's
           search input (rebuild of #307 against the current /search page). The
           panel is a child of the search form (destroyed with the fragment on
           navigation, so no body-append teardown is needed — cf. rule #32),
           reachable from THIS method (not "from nowhere" as the dead #307
           cluster was), and fed by the debounced input handler below. */
        this._initSuggest(input, form);

        /* Debounced search on input */
        input.addEventListener('input', () => {
            clearTimeout(this.debounceTimer);
            /* #1936 — collapse the quick-jump dropdown IMMEDIATELY when the box
               is cleared or drops below the 2-char floor, rather than leaving a
               stale suggestion list up for the whole debounce window. */
            if (input.value.trim().length < 2) this._closeSuggest();
            this.debounceTimer = setTimeout(() => {
                const q = input.value.trim();
                if (q.length >= 2) {
                    this.performSearch(q, filter?.value || '', results);
                    /* #1936 — same debounce, same query: refresh the top-N
                       title suggestions for the floating quick-jump dropdown.
                       A separate lightweight fetch (limit 8, titles only) — see
                       `_fetchSuggestions` for why it stays independent of the
                       heavier paginated results fetch above. */
                    this._fetchSuggestions(q);
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

        /* #1903 item 2 — keyboard navigation from the search input into the
         * results list.
         *
         * ELI5: press the down arrow in the search box and your keyboard
         * focus jumps into the list of results, so you can arrow up/down
         * between them and press Enter to open one — all without touching
         * the mouse.
         *
         * Detail: this handles the FULL RESULTS LIST navigation, which is NOT
         * a combobox — the rows rendered by `_renderResultItems()` below are
         * real `<a href>` elements (`.song-list-item`, natively focusable, no
         * `tabindex` needed), so Enter opens them via ordinary browser anchor
         * activation and arrows just move DOM focus between them. This is
         * DISTINCT from the #1936 quick-jump suggestion dropdown (`_initSuggest`
         * below), which IS a proper ARIA combobox over the shared
         * combobox-a11y helper. The two COEXIST cleanly by ownership of the
         * open/closed state: while the dropdown is OPEN it owns the arrow/
         * Home/End/Enter/Escape keys (the `_handleSuggestKeydown(e)` guard
         * added as this handler's first statement returns true and we stop);
         * while it is CLOSED, `handleComboboxKeydown`'s own `isOpen()` guard
         * makes that call a no-op and the results-list navigation below runs
         * exactly as #1903 shipped it. (#307's original header autocomplete
         * stays deleted — #1936 is the fresh rebuild the owner asked for; see
         * the file-top history note.)
         *
         * ArrowDown on the input (dropdown closed) moves focus to the FIRST
         * result row, but only when at least one exists (a query typed but not
         * yet resolved, or a zero-result search, must not steal focus into an
         * empty list). ArrowDown/ArrowUp on a focused row then walk between
         * rows, BOUNDED — no wrap: ArrowDown on the last row is a no-op
         * (stays put) and ArrowUp on the first row returns focus to the
         * input, mirroring the common "type more, arrow back down" flow.
         * `preventDefault()` is required on every branch that moves focus:
         * an unprevented ArrowDown both scrolls the page AND moves focus,
         * which reads as the list silently misbehaving rather than working.
         * https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/APG/Patterns/listbox
         * (the "arrow moves focus, bounded" shape below borrows the
         * listbox pattern's keyboard model without adopting full listbox
         * semantics — these result rows stay plain links, not `role="option"`).
         *
         * The listener on `results` (`#text-search-results`) is bound ONCE,
         * delegated, and MUST be — `performSearch()` replaces
         * `results.innerHTML` (and therefore every `.song-list-item` row)
         * on every search, but never replaces the `results` element itself,
         * so a listener bound directly to a row would be discarded with it
         * on the very next search. Binding on the stable container is what
         * survives. `dataset.keynavBound` follows the same double-bind
         * guard convention used elsewhere in this module family (see e.g.
         * settings.js's `dataset.bound`, request-a-song.js's
         * `dataset.songbookPickerBound`) — `initSearchPage()` itself only
         * runs once per `/search` navigation (router.js re-creates this
         * fragment, and with it fresh `input`/`results` nodes, each time),
         * but the guard keeps a future caller that re-runs it against an
         * already-wired container from double-firing every keystroke.
         * Guard: tests/test-search-keyboard-nav.js
         */
        if (!input.dataset.keynavBound) {
            input.dataset.keynavBound = '1';
            input.addEventListener('keydown', (e) => {
                /* #1936 — the quick-jump suggestion dropdown gets FIRST refusal
                   on every key. When it is OPEN, combobox-a11y consumes the
                   arrow/Home/End/Enter/Escape keys and this returns true, so we
                   stop before the #1903 results-list nav below. When it is
                   CLOSED, `_handleSuggestKeydown` returns false (its isOpen()
                   gate) and the #1903 behaviour runs unchanged. */
                if (this._handleSuggestKeydown(e)) return;
                /* #1903 — dropdown closed: ArrowDown jumps into the results. */
                if (e.key !== 'ArrowDown') return;
                const firstRow = results.querySelector('.song-list-item');
                if (!firstRow) return;
                e.preventDefault();
                firstRow.focus();
            });
        }

        if (!results.dataset.keynavBound) {
            results.dataset.keynavBound = '1';
            results.addEventListener('keydown', (e) => {
                if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
                /* Delegated: only act when the keydown originated on (or
                   inside) a result row, not the "Load more" button or the
                   count text that also live in this container. */
                const row = e.target.closest ? e.target.closest('.song-list-item') : null;
                if (!row) return;

                const rows = Array.from(results.querySelectorAll('.song-list-item'));
                const idx = rows.indexOf(row);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const next = rows[idx + 1];
                    if (next) next.focus();
                    /* Last row: no wrap — focus stays put. */
                } else {
                    e.preventDefault();
                    const prev = rows[idx - 1];
                    if (prev) {
                        prev.focus();
                    } else {
                        input.focus();
                    }
                }
            });
        }

        /* #1786 — Sort ▾ control. A change re-runs the CURRENT query fresh
           (append=false inside performSearch resets offset/loaded — see
           apiSearch() below for how the spec becomes a `sort=` param). No
           query yet ⇒ nothing to re-sort, so this is a silent no-op until
           the visitor has actually searched for something. */
        wireListSortControl('search', () => {
            const q = input.value.trim();
            if (q.length >= 2) {
                this.performSearch(q, filter?.value || '', results);
            }
        });
    }

    /* =====================================================================
     * TYPEAHEAD SUGGESTION DROPDOWN (#1936 — fresh rebuild of #307)
     *
     * A live "quick-jump" combobox over `#page-search-input`: as the visitor
     * types, up to 8 title matches appear in a floating listbox anchored under
     * the input; picking one (click / Enter / Tab) navigates straight to that
     * song. It is ADDITIVE to — never a replacement for — the full paginated
     * results list below (lyrics snippets, "Load more", the sort control and
     * the #1903 arrow-into-results keyboard nav all stay). Keyboard + ARIA are
     * delegated to the shared `combobox-a11y.js` (imported at file top);
     * navigate-on-pick is delegated to app.js's document-level
     * `[data-navigate]` click delegator (each row is a real
     * `<a href="/song/…" data-navigate="song">`), so this module owns only the
     * fetch, the render, and "commit === click the row".
     * ===================================================================== */

    /**
     * Build the floating suggestion panel and wire its dismissal.
     *
     * ELI5: makes the little drop-down box that shows song titles while you
     * type, and hangs it just under the search field.
     *
     * Detail: idempotent per fragment (a `dataset.suggestBound` guard — the
     * router re-creates this fragment each visit, so a fresh panel is built
     * per visit and the old one dies with the old fragment: no body-append, no
     * rule #32 teardown). Stamps the resting combobox ARIA on the input up
     * front so assistive tech announces it as a combobox before the first
     * search resolves.
     *
     * @param {HTMLInputElement} input  `#page-search-input`
     * @param {HTMLElement|null} form   `#page-search-form` (positioning context)
     */
    _initSuggest(input, form) {
        if (!form || form.dataset.suggestBound === '1') return;
        form.dataset.suggestBound = '1';
        /* The panel is absolutely positioned against the form, so the form
           becomes its positioning context. */
        form.style.position = 'relative';

        const panel = document.createElement('div');
        /* `.dropdown-menu` is reused ONLY so the child `.dropdown-item` rows
           inherit Bootstrap's theme-aware `--bs-dropdown-*` colour tokens
           (scoped to `.dropdown-menu`); positioning + display are overridden
           inline below. No `.search-autocomplete*` CSS is revived (that was
           deleted with the #307 dead cluster) — the panel is self-contained
           and theme-aware for free.
           @link https://getbootstrap.com/docs/5.3/components/dropdowns/ */
        panel.className = 'search-suggest-panel dropdown-menu shadow';
        panel.setAttribute('role', 'listbox');
        panel.style.position = 'absolute';
        panel.style.top = '100%';
        panel.style.left = '0';
        panel.style.right = '0';
        panel.style.width = 'auto';
        panel.style.maxHeight = '22rem';
        panel.style.overflowY = 'auto';
        panel.style.zIndex = '1055';
        panel.style.display = 'none';
        form.appendChild(panel);

        this._suggest = {
            input,
            panel,
            open: false,
            active: -1,
            items: [],
            seq: 0,
            idPrefix: 'search-suggest',
        };

        /* Dismiss on blur, delayed so a mousedown-pick on a row still lands
           before the panel tears down (the same 150 ms place-search uses). A
           row click navigates the SPA anyway, which swaps this fragment away. */
        input.addEventListener('blur', () => {
            setTimeout(() => this._closeSuggest(), 150);
        });

        /* Stamp the resting combobox ARIA (role=combobox, aria-expanded=false)
           on the input now, before any search. */
        this._closeSuggest();
    }

    /**
     * The live `.search-suggest-item` anchors currently in the panel, in
     * order. Re-queried on demand (never cached) so combobox-a11y always sees
     * the live DOM.
     * @returns {HTMLElement[]}
     */
    _suggestItemEls() {
        const s = this._suggest;
        if (!s || !s.panel) return [];
        return Array.from(s.panel.querySelectorAll('.search-suggest-item'));
    }

    /**
     * Fetch the top-N title suggestions for `query` and render them.
     *
     * Reuses the EXISTING `?action=search` endpoint with a low limit and
     * `lyrics=0` (a quick-jump wants titles/numbers, not full-text snippets),
     * honouring the current songbook filter. Kept a separate lightweight fetch
     * from `performSearch()` so the two features stay decoupled. Uses
     * `apiFetch` (rule #31), and a monotonic `seq` so an out-of-order response
     * from an earlier keystroke can't clobber a newer query's dropdown.
     *
     * @param {string} query
     */
    async _fetchSuggestions(query) {
        const s = this._suggest;
        if (!s) return;
        const seq = ++s.seq;
        const url = new URL(this.app.config.apiUrl, window.location.origin);
        url.searchParams.set('action', 'search');
        url.searchParams.set('q', query);
        url.searchParams.set('limit', '8');
        url.searchParams.set('offset', '0');
        url.searchParams.set('lyrics', '0');
        const filter = document.getElementById('page-search-filter');
        if (filter && filter.value) url.searchParams.set('songbook', filter.value);

        let data;
        try {
            const response = await apiFetch(url);
            if (!response.ok) return;   /* silent — performSearch() surfaces real errors */
            data = await response.json();
        } catch (_e) {
            /* Offline / network error — the dropdown is a convenience, and
               performSearch()'s own offline fallback already informs the user;
               just don't show a stale/empty quick-jump. */
            return;
        }
        if (seq !== s.seq) return;      /* superseded by a newer keystroke */
        this._renderSuggestions((data && data.results) ? data.results.slice(0, 8) : []);
    }

    /**
     * Paint the dropdown rows from a batch of song summaries.
     *
     * Each row is a real navigable anchor so app.js's `[data-navigate]`
     * delegator handles clicks (and combobox-a11y's Enter/Tab commit, which
     * just replays that click). Starts with NO row highlighted (`active = -1`)
     * so pressing Enter runs the FULL search (the form submit — the #812
     * Enter-to-search fix) instead of hijacking it to the first suggestion;
     * ArrowDown is the explicit gesture that enters the list.
     *
     * @param {Array} rows
     */
    _renderSuggestions(rows) {
        const s = this._suggest;
        if (!s) return;
        if (!rows || !rows.length) { this._closeSuggest(); return; }
        s.items = rows;
        s.active = -1;
        s.panel.innerHTML = rows.map((song) => {
            const num = (song.number == null || song.number === '') ? '' : String(song.number);
            const id = escapeHtml(song.id);
            return '<a href="/song/' + id + '" '
                + 'class="dropdown-item search-suggest-item d-flex align-items-center gap-2 py-2" '
                + 'data-navigate="song" data-song-id="' + id + '" role="option">'
                + '<span class="song-number-badge flex-shrink-0" data-songbook="'
                    + escapeHtml(song.songbook || '') + '">' + escapeHtml(num) + '</span>'
                + '<span class="flex-grow-1 text-truncate">'
                    + escapeHtml(toTitleCase(song.title || '')) + '</span>'
                + '<small class="text-muted flex-shrink-0">' + escapeHtml(song.songbook || '') + '</small>'
                + '</a>';
        }).join('');
        s.panel.style.display = 'block';
        s.open = true;
        this._paintSuggestActive();
    }

    /**
     * Reflect the current highlight: toggle Bootstrap's `.active` on the
     * highlighted row (the VISUAL cue combobox-a11y deliberately leaves to
     * callers) and hand the rest — role / aria-selected / aria-activedescendant
     * / aria-expanded — to `applyComboboxAria`. Safe if the helper is absent.
     */
    _paintSuggestActive() {
        const s = this._suggest;
        if (!s) return;
        const els = this._suggestItemEls();
        els.forEach((el, i) => el.classList.toggle('active', i === s.active));
        const combo = window.iHymnsComboboxA11y;
        if (combo && typeof combo.applyComboboxAria === 'function') {
            combo.applyComboboxAria({
                input: s.input,
                panel: s.panel,
                items: els,
                activeIndex: s.active,
                idPrefix: s.idPrefix,
                expanded: els.length > 0,
            });
        }
    }

    /**
     * Close + empty the dropdown and clear its combobox ARIA. Idempotent —
     * called on blur, Escape, submit, a too-short query, and once at mount to
     * stamp the resting ARIA state.
     */
    _closeSuggest() {
        const s = this._suggest;
        if (!s) return;
        s.open = false;
        s.active = -1;
        s.items = [];
        if (s.panel) { s.panel.style.display = 'none'; s.panel.innerHTML = ''; }
        const combo = window.iHymnsComboboxA11y;
        if (combo && typeof combo.applyComboboxAria === 'function') {
            combo.applyComboboxAria({
                input: s.input,
                panel: s.panel,
                items: [],
                activeIndex: -1,
                idPrefix: s.idPrefix,
                expanded: false,
            });
        }
    }

    /**
     * Route an input keydown through the shared combobox handler, but ONLY
     * while the dropdown is open (its `isOpen()` gate). Returns true iff the
     * key was consumed, so the caller (the input keydown listener) can skip
     * the #1903 results-list navigation for that key. `onCommit` navigates by
     * replaying the row's own click — app.js's `[data-navigate]` delegator
     * does the SPA navigation, never a second copy of the nav logic (the
     * `el.click()` pattern combobox-a11y's doc-block prescribes).
     *
     * @param {KeyboardEvent} e
     * @returns {boolean}
     */
    _handleSuggestKeydown(e) {
        const s = this._suggest;
        const combo = window.iHymnsComboboxA11y;
        if (!s || !combo || typeof combo.handleComboboxKeydown !== 'function') return false;
        return combo.handleComboboxKeydown(e, {
            isOpen: () => s.open,
            getItems: () => this._suggestItemEls(),
            getActiveIndex: () => s.active,
            setActiveIndex: (i) => { s.active = i; },
            render: () => this._paintSuggestActive(),
            onCommit: (_idx, el) => { if (el) el.click(); },
            onClose: () => this._closeSuggest(),
        });
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
                /* a11y audit M10 — role="status" (not the container's old blanket
                   aria-live) so only THIS summary line is announced per search,
                   not the whole re-rendered results list. Created empty and
                   filled below (after the awaited fetch) so the mutation happens
                   to a region assistive tech is already watching — the same
                   "empty now, fill next tick" shape announce.js documents. */
                container.innerHTML = `
                    <p class="text-muted small mb-2" id="search-count" role="status"></p>
                    <div class="list-group" id="search-results-list"></div>
                    <div id="search-loadmore" class="text-center mt-3"></div>`;
            }

            const state = this._search;
            const { results, hasMore } = await this.apiSearch(state.query, state.songbook, state.offset);

            /* No results on a fresh search → friendly empty state. */
            if (!append && (!results || results.length === 0)) {
                container.innerHTML = `
                    <div class="text-center text-muted py-4" role="status">
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

        /* #1786 — CSV `key.dir` tokens, e.g. `sort=title.asc,number.desc`.
           Default (no saved spec) sends no `sort` param at all, so a
           server that has never seen this parameter behaves exactly as
           before (rule #33 — a param the destination doesn't need is
           simply omitted, never sent empty). */
        const sortSpec = getListSort('search', Object.keys(SEARCH_SORT_TYPES));
        if (sortSpec.length) {
            url.searchParams.set('sort', sortSpec.map((s) => `${s.key}.${s.dir}`).join(','));
        }

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
        /* #1039 Part A — fold the query the SAME way the offline title is folded
           (diacritic + apostrophe insensitive), so "milosc"/"arent" match
           "Miłość"/"aren’t" offline too, mirroring the live server search. */
        const q = foldSearchText(query);
        const book = songbook ? songbook.toUpperCase() : '';
        const out = [];
        if (!q) return out;
        for (const s of index) {
            if (book && (s.songbook || '').toUpperCase() !== book) continue;
            const title = foldSearchText(s.title || '');
            const num = String(s.number == null ? '' : s.number);
            if (title.indexOf(q) !== -1 || num.indexOf(q) !== -1) {
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

        let results = this._filterSlimIndex(index, query, songbook);

        /* #1786 — there is no server to send `sort=` to while offline, so
           this (already tiny, ≤ PAGE_SIZE, unpaginated) fallback list sorts
           client-side with the SAME shared comparator every other surface
           uses. `number` composes songbook+number into one text value
           (matching SongData::_searchOrderBy()'s combined
           `s.SongbookAbbr, s.Number` fragment for that key). */
        const sortSpec = getListSort('search', Object.keys(SEARCH_SORT_TYPES));
        if (sortSpec.length) {
            const levels = sortSpec.map((s) => ({ key: s.key, type: 'text', direction: s.dir }));
            const decorated = results.map((row, i) => ({
                row,
                i,
                vals: {
                    title: row.title || '',
                    number: `${(row.songbook || '').toLowerCase()} ${String(row.number ?? 0).padStart(6, '0')}`,
                },
            }));
            decorated.sort((a, b) => {
                const cmp = multiKeyCompareMissingLast(levels, a.vals, b.vals);
                return cmp !== 0 ? cmp : a.i - b.i; /* stable */
            });
            results = decorated.map((d) => d.row);
        }

        container.innerHTML = `
            <div class="alert alert-warning py-2 small mb-2" role="status">
                <i class="fa-solid fa-wifi-slash me-1" aria-hidden="true"></i>
                You're offline — searching cached song titles only. Songs you've
                opened before will still open; others need a connection.
            </div>
            <p class="text-muted small mb-2" role="status">${results.length} match${results.length !== 1 ? 'es' : ''} in the offline index</p>
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
            /* #1531 part 2 — for an Unofficial songbook (rule #24) the
               writing-team credit sitting next to the songbook name is
               rendered in italics, the SAME visual convention rule #24 uses
               for Chorus/Refrain (`.lyric-chorus,.lyric-refrain { font-style:
               italic }`, #1337) — a shared, theme-aware affordance rather
               than a new one-off invented here. Purely presentational: the
               credit text itself is unchanged and still fully legible/
               announced to a screen reader without the italic (rule: don't
               rely on style alone to carry meaning). */
            const writersHtml = writers
                ? (songbookIsOfficial(song.songbook)
                    ? ` &middot; ${escapeHtml(writers)}`
                    : ` &middot; <span class="songbook-credits-unofficial">${escapeHtml(writers)}</span>`)
                : '';
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
                            ${songbookLabel(song.songbook, song.songbookName)}${writersHtml}
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
