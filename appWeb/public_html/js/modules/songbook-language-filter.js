/**
 * Songbook + song language filter (#679, extended in #736, picker #1149)
 *
 * Wires the compact, searchable multi-select dropdown picker rendered by
 * the /includes/partials/songbook-language-filter.php partial to:
 *
 *   1. A pure client-side hide/show pass over the songbook tiles
 *      currently rendered on the surrounding page.
 *   2. The X-Preferred-Languages request header on every fetch — see
 *      "#1031 — X-Preferred-Languages header" below for who attaches it.
 *   3. The /api?action=user_preferred_languages_save endpoint when
 *      the user is signed in, so the choice persists to the
 *      account and syncs across devices.
 *
 * #1031 — X-Preferred-Languages header:
 *   This module no longer attaches the header itself. Every same-origin
 *   request now gets it from `js/utils/api-client.js`'s `apiFetch()`,
 *   which reads STORAGE_LANGUAGE_FILTER fresh from localStorage on every
 *   call (see that module's own doc-comment for the full design). Before
 *   #1031 this module MONKEY-PATCHED `window.fetch` globally to add the
 *   header — a cross-cutting concern attached by side effect, which only
 *   applied if something had booted this module first (the router only
 *   imports it on home/songbooks/settings) and put a single bug in front
 *   of every fetch on the site. #1593 was exactly that: the patch read
 *   `input.url` on a `URL` object (which exposes `href`), got
 *   `undefined`, called `.startsWith` on it and threw — breaking ten
 *   unrelated call sites and presenting as "Song of the Day disappears
 *   when I pick two languages". `saveSubtags()` below still writes
 *   STORAGE_LANGUAGE_FILTER, which is now the ONLY thing api-client.js
 *   needs to see the new preference — no separate "header state" to
 *   prime or keep in sync.
 *
 * Filter rules (per spec):
 *   - "All" checked → no filter; every tile + every song visible.
 *   - One or more languages checked → show tiles whose
 *     data-songbook-language starts with any of the selected
 *     primary subtags. Tiles WITHOUT a data-songbook-language
 *     attribute (i.e. songbooks with no Language set) ALWAYS
 *     stay visible — the absence is treated as
 *     "multi-lingual / not specified".
 *
 * Persistence:
 *   - localStorage key STORAGE_LANGUAGE_FILTER (constants.js) for the
 *     anonymous case (and as a fast-restore on every page load
 *     before the auth check resolves).
 *   - Account preference via /api?action=user_preferred_languages_save
 *     for signed-in users.
 *
 * The module is idempotent: bootSongbookLanguageFilter() can be
 * called multiple times (e.g. once on first page load + once after
 * an SPA navigation) without binding duplicate handlers.
 */

/* #1581 — M4 correction: this module's own dispatch/listener pair with
   song-of-the-day.js was NEVER the mismatch — both already used the same
   capital-H event-name spelling and matched each other correctly (by
   luck of matching typos, not by design). THE actual bug lived in
   settings-language-filter.js, which dispatched a DIFFERENT, lowercase
   spelling of the same event name that neither listener was ever bound
   for — DOM event types are case-sensitive, so toggling the language
   filter from Settings silently never refreshed anything. Importing the
   shared constant here (now the lowercase spelling, since that's what
   js/constants.js standardised on) means every dispatch/listen site
   reads from one source of truth and two files can never disagree on
   spelling again — this module's own pairing with song-of-the-day.js
   was already fine, but is now provably so rather than fine by
   coincidence. See tests/test-event-names.js, which bans a raw quoted
   event-name literal outside constants.js — that's also why this note
   describes the two spellings in prose instead of quoting them. */
/* #1031 — shared localStorage-key constant; see constants.js's own note on
   STORAGE_LANGUAGE_FILTER for why this raw key name predates the ihymns_
   prefix convention and must not be renamed. */
import { EVT_LANGUAGE_FILTER_CHANGED, STORAGE_LANGUAGE_FILTER } from '../constants.js';
import { apiFetch } from '../utils/api-client.js';
import {
    languageGroupOf, mergePreferenceOrder, preferenceMatchesTag,
    MAX_PREFERENCES, TOO_MANY_LANGUAGES_MESSAGE, usablePreferenceList,
} from '../utils/language-tags.js';

const STORAGE_KEY = STORAGE_LANGUAGE_FILTER;

/* #2132 — mirrors includes/language_filter.php's
   IHYMNS_LANGUAGE_FILTER_ALWAYS_SHOWN: a song or songbook whose language is not
   known (und), is in several languages (mul) or has none (zxx) is never hidden
   by a language filter, exactly like an untagged one. */
const ALWAYS_SHOWN_GROUPS = new Set(['und', 'mul', 'zxx']);

/**
 * Read the saved preferred-language list from localStorage: a JSON array of
 * language tags, highest priority first. #2137 — whole tags (`pt-BR`) are kept;
 * this used to accept base codes only (`/^[a-z]{2,3}$/`), so a regional or
 * script preference silently vanished. Older saved lists of base codes are
 * still valid. Returns [] on any error (including "private browsing mode").
 *
 * #2137 review round 5 — only the first 32 (usablePreferenceList()), exactly
 * as the server reads the list: so the grid never filters by, or shows ticked,
 * a language the server-filtered lists ignore.
 */
function loadSavedSubtags() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return usablePreferenceList(parsed);
    } catch (_e) {
        return [];
    }
}

function saveSubtags(subtags) {
    try {
        if (subtags.length === 0) {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(subtags));
        }
    } catch (_e) { /* private mode — best effort */ }
}

/**
 * For signed-in users, persist the chosen subtag list to the
 * account so it syncs across devices. Best-effort: a network
 * failure or 401 (token expired) silently falls back to
 * localStorage-only.
 */
function saveSubtagsToAccount(subtags) {
    /* SPA stores the bearer token under 'ihymns_auth_token' (per
       PWA Features wiki page). Skip the call if it's absent. */
    let token = null;
    try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}
    if (!token) return;

    apiFetch('/api?action=user_preferred_languages_save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        /* #2137 — `languages` is read by the server first; `subtags` keeps an
           older server working during a staggered deploy. */
        body: JSON.stringify({ languages: subtags, subtags }),
    }).catch(() => { /* best-effort */ });
}

/* Find every tile on the page that corresponds to a songbook. The
   home.php compact tiles use `.card-songbook` directly on the inner
   div; the /songbooks full-card tiles use `.card-songbook` on the
   outer <a>. Both surfaces carry data-songbook-language on whichever
   element renders the data attribute. We grab the closest tile
   container — `.col` for home, `.col-12.col-sm-6.col-md-4.col-lg-3`
   for /songbooks — by walking up to the nearest column. */
function findTileColumn(tile) {
    let node = tile;
    while (node && node.parentElement) {
        node = node.parentElement;
        if (node.classList && (node.classList.contains('col') ||
            Array.from(node.classList).some(c => c.startsWith('col-')))) {
            return node;
        }
    }
    return tile;
}

/**
 * Apply the filter to every tile / row currently in the DOM that
 * carries a data-songbook-language or data-song-language attribute.
 *
 * Spec:
 *   - subtags == [] → show everything
 *   - subtags non-empty → show rows whose primary subtag is in the
 *     set, plus rows that have no language attribute at all
 *     (untagged → always shown)
 */
function applyFilter(rootEl, subtags) {
    /* #2137 — matching is by language: a `pt-BR` preference shows `pt` and
       `pt-PT` content too (the saved list keeps the whole tag; see
       mergePreferenceOrder()). #2137 review — except that a preference naming
       a SCRIPT hides content in a different script (`zh-Hans` hides `zh-Hant`;
       MATCH-040), via preferenceMatchesTag(), the same rule the server's
       filter applies. #2132 — und / mul / zxx are always shown. */
    const set = new Set(subtags.map(languageGroupOf));
    const prefs = subtags.slice();
    const passes = (tag) => {
        if (ALWAYS_SHOWN_GROUPS.has(languageGroupOf(tag))) return true;
        return prefs.some((p) => preferenceMatchesTag(p, tag));
    };

    /* Songbook tiles.
       #857: visibility is decided on the union of (the songbook's
       own primary subtag) ∪ (every distinct primary subtag carried
       by songs within that book). Server emits this union as
       data-songbook-languages (comma-separated). The legacy
       data-songbook-language attribute stays populated for one
       release-cycle of back-compat with cached JS bundles, and as
       a fallback when the union attribute is absent. */
    rootEl.querySelectorAll('[data-songbook-id]').forEach(tile => {
        /* #2137 review — the WHOLE tags (data-songbook-language-tags: the
           book's own language and its songs') are what a script check needs;
           the language groups stay the fallback for markup without them. */
        const tagsCsv   = (tile.dataset.songbookLanguageTags || '');
        const langsCsv  = (tile.dataset.songbookLanguages || '').toLowerCase();
        const fallback  = (tile.dataset.songbookLanguage  || '').toLowerCase();
        const col = findTileColumn(tile);
        if (!col) return;

        const tileTags = tagsCsv
            ? tagsCsv.split(',').map(s => s.trim()).filter(Boolean)
            : (langsCsv
                ? langsCsv.split(',').map(s => s.trim()).filter(Boolean)
                : (fallback ? [fallback] : []));

        const shouldShow = (() => {
            if (set.size === 0) return true;        /* "All" → everything */
            if (tileTags.length === 0) return true; /* untagged → always pass */
            return tileTags.some(passes);
        })();

        if (shouldShow) {
            col.style.removeProperty('display');
            col.removeAttribute('aria-hidden');
        } else {
            col.style.display = 'none';
            col.setAttribute('aria-hidden', 'true');
        }
    });

    /* Song rows (when present — search results, song lists, etc.) */
    rootEl.querySelectorAll('[data-song-language]').forEach(row => {
        const rowLang = (row.dataset.songLanguage || '').toLowerCase();
        const shouldShow = (() => {
            if (set.size === 0) return true;
            if (!rowLang) return true;
            return passes(rowLang);
        })();
        if (shouldShow) {
            row.style.removeProperty('display');
            row.removeAttribute('aria-hidden');
        } else {
            row.style.display = 'none';
            row.setAttribute('aria-hidden', 'true');
        }
    });

    /* #855 — broadcast the change so independent modules (Song of
       the Day in particular) can re-render without a page reload.
       Detail.subtags carries the LANGUAGE GROUPS of the saved preferences
       (`pt` for `pt-BR`; distinct, in the saved order); an empty array means
       "All" / no filter. Nothing reads detail.subtags today — Song of the
       Day re-reads the saved list itself (api-client.js's
       preferredLanguagesCsv()) — so a listener that needs whole tags should
       do the same. */
    try {
        document.dispatchEvent(new CustomEvent(EVT_LANGUAGE_FILTER_CHANGED, {
            detail: { subtags: Array.from(set) },
        }));
    } catch (_e) { /* polyfill territory; harmless to skip */ }
}

/**
 * Boot the language filter on the page. Call once per
 * SPA-page-render. Idempotent — re-calling on the same DOM is safe.
 *
 * @param {ParentNode} [root=document] Where to scope the search.
 */
export function bootSongbookLanguageFilter(root) {
    const scope = root || document;
    const wrapper = scope.querySelector('[data-songbook-language-filter]');
    /* #1031 — no "restore the saved list to the global header" branch here
       any more: there is no separate header state to prime. api-client.js's
       apiFetch() reads STORAGE_LANGUAGE_FILTER straight from localStorage on
       every request, so a page without this filter's markup still sends the
       right header purely because saveSubtags() already wrote it there. */
    if (!wrapper || wrapper.dataset.songbookFilterBooted === '1') {
        return;
    }
    wrapper.dataset.songbookFilterBooted = '1';

    const allCheckbox      = wrapper.querySelector('.js-songbook-language-filter-all');
    const optionCheckboxes = Array.from(wrapper.querySelectorAll('.js-songbook-language-filter-option'));
    if (!allCheckbox || optionCheckboxes.length === 0) return;

    /* #1149 picker chrome — all optional (older cached markup may lack
       them), so every reference is null-guarded. */
    const triggerLabel = wrapper.querySelector('.js-lang-filter-trigger-label');
    const countBadge   = wrapper.querySelector('.js-lang-filter-count');
    const searchInput  = wrapper.querySelector('.js-lang-filter-search');
    const emptyState   = wrapper.querySelector('.js-lang-filter-empty');
    const statusRegion = wrapper.querySelector('[data-lang-filter-status]');
    /* #2137 review round 5 — optional (older cached markup lacks it). */
    const limitNote    = wrapper.querySelector('.js-lang-filter-limit');
    function sayLimit(text) {
        if (limitNote) {
            limitNote.textContent = text;
            limitNote.classList.toggle('d-none', text === '');
        }
        if (statusRegion && text !== '') statusRegion.textContent = text;
    }
    const optionRows   = Array.from(wrapper.querySelectorAll('.lang-filter-row[data-search]'));

    /* Update the dropdown trigger's label + count badge to reflect the
       current selection ("Languages: All" / "Languages: English,
       Afrikaans +2"). #1149. */
    function refreshTrigger(subtags) {
        if (triggerLabel) {
            if (subtags.length === 0) {
                triggerLabel.textContent = 'Languages: All';
            } else {
                const names = subtags.map(sub => {
                    const cb = optionCheckboxes.find(c => c.value === sub);
                    return cb ? (cb.dataset.langName || sub.toUpperCase()) : sub.toUpperCase();
                });
                const shown = names.slice(0, 2).join(', ');
                const extra = names.length > 2 ? ' +' + (names.length - 2) : '';
                triggerLabel.textContent = 'Languages: ' + shown + extra;
            }
        }
        if (countBadge) {
            if (subtags.length > 0) {
                countBadge.textContent = String(subtags.length);
                countBadge.classList.remove('d-none');
            } else {
                countBadge.classList.add('d-none');
            }
        }
    }

    /* Filter the panel's language rows from the search box. The "All"
       row is pinned (never filtered). Announces the match count to a
       polite live region. #1149. */
    function applySearch(term) {
        const q = term.trim().toLowerCase();
        let visible = 0;
        optionRows.forEach(row => {
            const hay = row.dataset.search || '';
            const match = q === '' || hay.indexOf(q) !== -1;
            row.classList.toggle('d-none', !match);
            if (match) visible++;
        });
        if (emptyState) emptyState.classList.toggle('d-none', visible !== 0 || q === '');
        if (statusRegion && q !== '') {
            statusRegion.textContent = visible + (visible === 1 ? ' language' : ' languages') + ' match';
        } else if (statusRegion) {
            statusRegion.textContent = '';
        }
    }

    /* Sync UI state from the saved list. Each checkbox is one language GROUP
       (value = the base code, e.g. `pt`); it is ticked when any saved tag is
       in that group (#2137 — a saved `pt-BR` ticks "Portuguese"). */
    function syncUiFromSubtags(subtags) {
        if (subtags.length === 0) {
            allCheckbox.checked = true;
            optionCheckboxes.forEach(cb => { cb.checked = false; });
        } else {
            allCheckbox.checked = false;
            const groups = new Set(subtags.map(languageGroupOf));
            optionCheckboxes.forEach(cb => { cb.checked = groups.has(cb.value); });
        }
        refreshTrigger(subtags.map(languageGroupOf).filter((g, i, a) => a.indexOf(g) === i));
    }

    /* Read the new preference list from UI state, KEEPING the person's
       priority order (#2137): languages already chosen stay in their order
       (and keep their full tag, e.g. `pt-BR`); a newly ticked one goes last.
       This used to return the ticked boxes sorted A-Z, which threw the
       priority away on every change. */
    function readSubtagsFromUi() {
        if (allCheckbox.checked) return [];
        const checkedGroups = optionCheckboxes.filter(cb => cb.checked).map(cb => cb.value);
        return mergePreferenceOrder(loadSavedSubtags(), checkedGroups);
    }

    /* #2137 — the person's own languages first (the shared language policy's
       UI-020), in their priority order, when the panel is first drawn. The
       server renders this list alphabetically by name (UI-040) because the
       page piece is cached and shared by everyone; only the browser knows who
       is looking. Done ONCE here, never on a click (UI-050: a menu does not
       move when something is selected). */
    (function putReaderLanguagesFirst() {
        const saved = loadSavedSubtags();
        const allRow = allCheckbox.closest('.lang-filter-row');
        if (!allRow || saved.length === 0) return;
        const seen = new Set();
        const firstGroups = saved.map(languageGroupOf).filter(g => (seen.has(g) ? false : seen.add(g)));
        let anchor = allRow;
        for (const group of firstGroups) {
            const cb = optionCheckboxes.find(c => c.value === group);
            const row = cb ? cb.closest('.lang-filter-row') : null;
            if (row && row !== anchor) {
                anchor.after(row);
                anchor = row;
            }
        }
    })();

    /* Restore on first boot. Try the saved value first; if it
       references a language no longer in the catalogue, the
       missing checkboxes simply won't toggle on (the rest still
       apply). */
    const initial = loadSavedSubtags();
    syncUiFromSubtags(initial);
    applyFilter(scope, initial);

    /* Wire change handlers. */
    function commit() {
        const subtags = readSubtagsFromUi();
        /* #1031 — saveSubtags() (localStorage) BEFORE applyFilter() is still
           load-bearing, same reason #1593 called out for the old header-state
           priming: applyFilter() dispatches EVT_LANGUAGE_FILTER_CHANGED
           *synchronously* and listeners (Song of the Day) refetch
           immediately, so whatever apiFetch() would read from
           STORAGE_LANGUAGE_FILTER at that moment must already be the NEW
           selection. Now there's only ONE thing to get in the right order
           (the localStorage write) instead of two (a JS header-state variable
           AND localStorage) — api-client.js reads STORAGE_LANGUAGE_FILTER
           fresh on every request, so there's no separate state left to prime. */
        saveSubtags(subtags);
        applyFilter(scope, subtags);
        saveSubtagsToAccount(subtags);
        refreshTrigger(subtags);
    }

    /* Wire the panel search box (#1149). Pure DOM filter over the
       already-rendered rows — no fetch. */
    if (searchInput) {
        searchInput.addEventListener('input', () => applySearch(searchInput.value));
        /* Clearing via the native search "✕" fires 'search'. */
        searchInput.addEventListener('search', () => applySearch(searchInput.value));
        /* a11y (WCAG 2.4.3): when the dropdown opens, move focus into the
           search box so keyboard users can type immediately. Bootstrap's
           shown.bs.dropdown bubbles to the wrapper. */
        wrapper.addEventListener('shown.bs.dropdown', () => searchInput.focus());
    }

    allCheckbox.addEventListener('change', () => {
        if (allCheckbox.checked) {
            optionCheckboxes.forEach(cb => { cb.checked = false; });
        } else {
            /* Can't have nothing selected — re-tick "All" so a
               click on a checked "All" doesn't leave the user in
               a "show nothing" state. */
            allCheckbox.checked = true;
        }
        sayLimit('');
        commit();
    });
    optionCheckboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) {
                /* #2137 review round 5 — at most 32 languages, as on the
                   settings page: a tick that would make the list longer is
                   refused, nothing is saved, and the panel says why. */
                const wouldBe = mergePreferenceOrder(allCheckbox.checked ? [] : loadSavedSubtags(),
                    optionCheckboxes.filter(o => o.checked).map(o => o.value));
                if (wouldBe.length > MAX_PREFERENCES) {
                    cb.checked = false;
                    sayLimit(TOO_MANY_LANGUAGES_MESSAGE);
                    return;
                }
                /* Selecting a specific language clears "All". */
                allCheckbox.checked = false;
            } else if (optionCheckboxes.every(o => !o.checked)) {
                /* If the user just un-ticked the last specific
                   language, fall back to "All" rather than
                   leaving them with no selection. */
                allCheckbox.checked = true;
            }
            sayLimit('');
            commit();
        });
    });

    /* For signed-in users, fetch the account-saved value once
       on boot — it overrides localStorage if newer. Best-effort:
       failures fall back to localStorage. */
    let token = null;
    try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}
    if (token) {
        apiFetch('/api?action=user_preferred_languages', {
            headers: { 'Authorization': 'Bearer ' + token, 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.ok ? r.json() : null)
            .then(j => {
                /* #2137 — `languages` holds the whole tags in the account's
                   saved order; `subtags` (base codes) is the older field,
                   used only if a server predates `languages`. */
                const list = (j && Array.isArray(j.languages)) ? j.languages
                    : ((j && Array.isArray(j.subtags)) ? j.subtags : null);
                if (!list) return;
                /* Round 5 — the first 32, as everywhere else. */
                const remote = usablePreferenceList(list);
                /* Adopt the remote list — it's the canonical
                   "across all my devices" view. */
                saveSubtags(remote);
                syncUiFromSubtags(remote);
                applyFilter(scope, remote);
            })
            .catch(() => { /* ignore — local state stands */ });
    }
}
