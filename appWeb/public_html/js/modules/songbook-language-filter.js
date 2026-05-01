/**
 * Songbook + song language filter (#679, extended in #736)
 *
 * Wires the multi-select chip group rendered by the
 * /includes/partials/songbook-language-filter.php partial to:
 *
 *   1. A pure client-side hide/show pass over the songbook tiles
 *      currently rendered on the surrounding page.
 *   2. The X-Preferred-Languages request header on every fetch
 *      (set globally so subsequent SPA navigations + native-API
 *      consumers all see the same filter state).
 *   3. The /api?action=user_preferred_languages_save endpoint when
 *      the user is signed in, so the choice persists to the
 *      account and syncs across devices.
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
 *   - localStorage key 'songbook-language-filter' for the
 *     anonymous case (and as a fast-restore on every page load
 *     before the auth check resolves).
 *   - Account preference via /api?action=user_preferred_languages_save
 *     for signed-in users.
 *
 * The module is idempotent: bootSongbookLanguageFilter() can be
 * called multiple times (e.g. once on first page load + once after
 * an SPA navigation) without binding duplicate handlers.
 */

const STORAGE_KEY = 'songbook-language-filter';

/**
 * Read the saved preferred-language subtag list from localStorage.
 * Stored as a JSON array of lowercase primary subtags.
 * Returns [] on any error (including "private browsing mode").
 */
function loadSavedSubtags() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return parsed.filter(s => typeof s === 'string' && /^[a-z]{2,3}$/.test(s));
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
 * Tell the global fetch wrapper to send X-Preferred-Languages on
 * every request. Falls back to a per-fetch override using a
 * Symbol-keyed property on window so anything that uses native
 * fetch picks up the header without the SPA having to touch every
 * call site.
 */
function applyHeaderToFetch(subtags) {
    /* The SPA's fetch wrapper (if any) reads window.__iHymnsPreferredLanguages
       and prepends it as a request header. If no wrapper exists,
       we monkey-patch fetch globally to add the header — once. */
    window.__iHymnsPreferredLanguages = subtags.join(',');

    if (window.__iHymnsLangFilterFetchPatched) return;
    window.__iHymnsLangFilterFetchPatched = true;

    const origFetch = window.fetch;
    window.fetch = function (input, init) {
        const csv = window.__iHymnsPreferredLanguages || '';
        if (csv === '') return origFetch(input, init);

        /* Only attach the header for same-origin requests — never
           leak the user's language preference to a third-party
           CDN or analytics endpoint. */
        let url = '';
        try {
            url = typeof input === 'string' ? input : input.url;
        } catch (_e) {}
        const sameOrigin = !url.startsWith('http')
            || url.startsWith(window.location.origin);
        if (!sameOrigin) return origFetch(input, init);

        const next = init ? { ...init } : {};
        next.headers = new Headers(next.headers || {});
        next.headers.set('X-Preferred-Languages', csv);
        return origFetch(input, next);
    };
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

    fetch('/api?action=user_preferred_languages_save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ subtags }),
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
    const set = new Set(subtags.map(s => s.toLowerCase()));

    /* Songbook tiles. */
    rootEl.querySelectorAll('[data-songbook-id]').forEach(tile => {
        const tileLang = (tile.dataset.songbookLanguage || '').toLowerCase();
        const col = findTileColumn(tile);
        if (!col) return;

        const shouldShow = (() => {
            if (set.size === 0) return true;
            if (!tileLang) return true;
            const primary = tileLang.split('-', 1)[0];
            return set.has(primary);
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
            const primary = rowLang.split('-', 1)[0];
            return set.has(primary);
        })();
        if (shouldShow) {
            row.style.removeProperty('display');
            row.removeAttribute('aria-hidden');
        } else {
            row.style.display = 'none';
            row.setAttribute('aria-hidden', 'true');
        }
    });
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
    if (!wrapper || wrapper.dataset.songbookFilterBooted === '1') {
        /* Even when the wrapper isn't on this page, restore the
           saved subtag list to the global header so cross-page
           navigations still apply the filter to song listings
           rendered by the SPA after this point. */
        const savedHeaderOnly = loadSavedSubtags();
        applyHeaderToFetch(savedHeaderOnly);
        return;
    }
    wrapper.dataset.songbookFilterBooted = '1';

    const allCheckbox      = wrapper.querySelector('.js-songbook-language-filter-all');
    const optionCheckboxes = Array.from(wrapper.querySelectorAll('.js-songbook-language-filter-option'));
    if (!allCheckbox || optionCheckboxes.length === 0) return;

    /* Sync UI state from saved subtag list. */
    function syncUiFromSubtags(subtags) {
        if (subtags.length === 0) {
            allCheckbox.checked = true;
            optionCheckboxes.forEach(cb => { cb.checked = false; });
        } else {
            allCheckbox.checked = false;
            const set = new Set(subtags);
            optionCheckboxes.forEach(cb => { cb.checked = set.has(cb.value); });
        }
    }

    /* Read current subtag list from UI state. */
    function readSubtagsFromUi() {
        if (allCheckbox.checked) return [];
        return optionCheckboxes
            .filter(cb => cb.checked)
            .map(cb => cb.value)
            .sort();
    }

    /* Restore on first boot. Try the saved value first; if it
       references a language no longer in the catalogue, the
       missing checkboxes simply won't toggle on (the rest still
       apply). */
    const initial = loadSavedSubtags();
    syncUiFromSubtags(initial);
    applyFilter(scope, initial);
    applyHeaderToFetch(initial);

    /* Wire change handlers. */
    function commit() {
        const subtags = readSubtagsFromUi();
        saveSubtags(subtags);
        applyFilter(scope, subtags);
        applyHeaderToFetch(subtags);
        saveSubtagsToAccount(subtags);
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
        commit();
    });
    optionCheckboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) {
                /* Selecting a specific language clears "All". */
                allCheckbox.checked = false;
            } else if (optionCheckboxes.every(o => !o.checked)) {
                /* If the user just un-ticked the last specific
                   language, fall back to "All" rather than
                   leaving them with no selection. */
                allCheckbox.checked = true;
            }
            commit();
        });
    });

    /* For signed-in users, fetch the account-saved value once
       on boot — it overrides localStorage if newer. Best-effort:
       failures fall back to localStorage. */
    let token = null;
    try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}
    if (token) {
        fetch('/api?action=user_preferred_languages', {
            headers: { 'Authorization': 'Bearer ' + token, 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.ok ? r.json() : null)
            .then(j => {
                if (!j || !Array.isArray(j.subtags)) return;
                const remote = j.subtags.filter(s => /^[a-z]{2,3}$/.test(s));
                /* Adopt the remote list — it's the canonical
                   "across all my devices" view. */
                saveSubtags(remote);
                syncUiFromSubtags(remote);
                applyFilter(scope, remote);
                applyHeaderToFetch(remote);
            })
            .catch(() => { /* ignore — local state stands */ });
    }
}
