/**
 * iHymns — Place Search typeahead module
 *
 * Single source of truth for the live suburb / city / state /
 * country autocomplete across every admin form that exposes a
 * place input (Credit People birth / death place today, follow-up
 * places tomorrow). Wraps a plain <input type="text"> with a
 * debounced typeahead that hits /manage/places-api.php?action=search
 * and stores the picked place's tblPlaces.Id in a sibling hidden
 * input so the form's POST handler can persist the FK alongside
 * the display string.
 *
 * Exposed on `window.iHymnsPlaceSearch`:
 *   attach(inputEl, opts) → teardown fn
 *
 * Options:
 *   hiddenIdInput  HTMLInputElement   sibling hidden input that
 *                                      receives the chosen place
 *                                      Id (or '' for free-text).
 *   endpoint       string             default '/manage/places-api.php'
 *   minChars       number             default 3
 *   debounceMs     number             default 250
 *   maxResults     number             default 8
 *   onSelect(place)                   called with the upserted
 *                                      tblPlaces row payload
 *                                      after the form's hidden
 *                                      input is updated.
 *
 * Behaviour:
 *   - Free-typing clears the hidden Id (curator typed something
 *     not in the registry — the form falls back to display-string-only).
 *   - Picking a candidate from the dropdown POSTs to /upsert and
 *     fills the hidden Id with the returned tblPlaces row Id.
 *   - Down/Up cycles the highlight, Enter accepts, Escape closes
 *     without changing the value.
 *   - Pre-filled value at attach time leaves both the visible
 *     input and the hidden Id alone — server's load handler is
 *     authoritative.
 */

(function () {
    'use strict';

    const DEFAULTS = {
        endpoint:   '/manage/places-api.php',
        minChars:   3,
        debounceMs: 250,
        maxResults: 8,
    };

    /* Each attach() instance gets its own state object — the
       module supports any number of place inputs on one page. */
    function attach(inputEl, opts) {
        if (!inputEl || inputEl.dataset.placeSearchAttached === '1') {
            return function () {};
        }
        const settings = Object.assign({}, DEFAULTS, opts || {});
        inputEl.dataset.placeSearchAttached = '1';
        inputEl.setAttribute('autocomplete', 'off');
        inputEl.setAttribute('spellcheck', 'false');
        inputEl.setAttribute('role', 'combobox');
        inputEl.setAttribute('aria-autocomplete', 'list');
        inputEl.setAttribute('aria-expanded', 'false');

        /* Dropdown panel — appended to <body> so it escapes
           parent overflow:hidden containers (Bootstrap offcanvas,
           cards with overflow:auto). Position recomputed on
           focus + input + scroll. */
        const panel = document.createElement('div');
        panel.className = 'ihymns-place-search-panel';
        panel.setAttribute('role', 'listbox');
        panel.style.position = 'absolute';
        panel.style.zIndex = '20000';
        panel.style.minWidth = '20rem';
        panel.style.maxHeight = '20rem';
        panel.style.overflowY = 'auto';
        panel.style.background = 'var(--bs-body-bg, #1a1a1a)';
        panel.style.color = 'var(--bs-body-color, #e8e8e8)';
        panel.style.border = '1px solid var(--bs-border-color, #444)';
        panel.style.borderRadius = '0.375rem';
        panel.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.45)';
        panel.style.display = 'none';
        panel.style.fontSize = '0.875rem';
        document.body.appendChild(panel);

        let currentRequest = 0;
        let candidates = [];
        let highlight = -1;
        let debounceTimer = null;
        let lastQuery = '';

        function setHiddenId(value) {
            if (settings.hiddenIdInput) {
                const next = value || '';
                if (settings.hiddenIdInput.value === next) return;
                settings.hiddenIdInput.value = next;
                /* Fire a synthetic change event so any per-field
                   listener pattern (the Song editor's metadata
                   listeners drive song.originCityId off this) sees
                   the new value without needing a MutationObserver. */
                settings.hiddenIdInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function positionPanel() {
            const rect = inputEl.getBoundingClientRect();
            panel.style.left   = (window.scrollX + rect.left) + 'px';
            panel.style.top    = (window.scrollY + rect.bottom + 2) + 'px';
            panel.style.width  = Math.max(rect.width, 240) + 'px';
        }

        function renderPanel() {
            if (!candidates.length) {
                panel.style.display = 'none';
                inputEl.setAttribute('aria-expanded', 'false');
                return;
            }
            panel.innerHTML = '';
            candidates.forEach((c, i) => {
                const item = document.createElement('div');
                item.className = 'ihymns-place-search-item';
                item.setAttribute('role', 'option');
                item.dataset.index = String(i);
                item.style.padding = '0.4rem 0.6rem';
                item.style.cursor = 'pointer';
                item.style.borderBottom = '1px solid rgba(255,255,255,0.06)';
                if (i === highlight) {
                    item.style.background = 'var(--bs-primary-bg-subtle, rgba(255, 193, 7, 0.18))';
                    item.setAttribute('aria-selected', 'true');
                }
                const main = document.createElement('div');
                main.textContent = c.display_name;
                main.style.fontWeight = '500';
                main.style.whiteSpace = 'nowrap';
                main.style.overflow = 'hidden';
                main.style.textOverflow = 'ellipsis';
                item.appendChild(main);
                if (c.type || c.address) {
                    const meta = document.createElement('div');
                    meta.style.fontSize = '0.75rem';
                    meta.style.opacity = '0.7';
                    meta.textContent = (c.type || '') + (c.address && c.address.country ? ' • ' + c.address.country : '');
                    item.appendChild(meta);
                }
                item.addEventListener('mousedown', (ev) => {
                    /* mousedown rather than click so the pick fires
                       before the input's blur tears the panel down. */
                    ev.preventDefault();
                    pickCandidate(i);
                });
                item.addEventListener('mouseenter', () => {
                    highlight = i;
                    renderPanel();
                });
                panel.appendChild(item);
            });
            positionPanel();
            panel.style.display = 'block';
            inputEl.setAttribute('aria-expanded', 'true');
        }

        /* Show a single non-interactive info row in the panel. Used to
           SURFACE a failed/empty lookup instead of silently showing nothing
           (#1180) — a 404/401/blocked-geocoder used to leave the curator
           typing into a dead field with no clue why no suggestions appeared. */
        function renderHint(text) {
            candidates = [];
            highlight = -1;
            panel.innerHTML = '';
            const item = document.createElement('div');
            item.style.padding = '0.4rem 0.6rem';
            item.style.fontSize = '0.8rem';
            item.style.opacity = '0.7';
            item.textContent = text;
            panel.appendChild(item);
            positionPanel();
            panel.style.display = 'block';
            inputEl.setAttribute('aria-expanded', 'true');
        }

        async function runSearch(query) {
            const requestId = ++currentRequest;
            const url = settings.endpoint + '?action=search&q=' + encodeURIComponent(query) + '&limit=' + settings.maxResults;
            let resp;
            try {
                resp = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
            } catch (_e) {
                if (requestId === currentRequest) renderHint('Place lookup unavailable (offline?). Your text is saved as typed.');
                return;
            }
            if (requestId !== currentRequest) return; /* superseded */
            if (!resp.ok) {
                /* Pull the server's `detail` (admins get the real error message)
                   so the curator/dev sees WHY, not just the status (#1180). */
                let detail = '';
                try {
                    const errBody = await resp.json();
                    if (errBody && errBody.detail) { detail = ' — ' + errBody.detail; }
                } catch (_e) { /* no JSON body */ }
                renderHint('Place lookup unavailable (HTTP ' + resp.status + ')' + detail + '. Your text is saved as typed.');
                return;
            }
            const data = await resp.json().catch(() => null);
            if (!data || !Array.isArray(data.results)) {
                renderHint('Place lookup returned no data. Your text is saved as typed.');
                return;
            }
            candidates = data.results;
            highlight = candidates.length ? 0 : -1;
            if (!candidates.length) {
                renderHint('No matching places — your text is saved as typed.');
                return;
            }
            renderPanel();
        }

        async function pickCandidate(index) {
            const c = candidates[index];
            if (!c) return;
            inputEl.value = c.display_name;
            /* Optimistic UI — show the picked label immediately; the
               upsert call below races behind to claim the FK. */
            inputEl.dataset.placeSearchPicked = '1';
            closePanel();
            try {
                const resp = await fetch(settings.endpoint + '?action=upsert', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify(c),
                });
                if (!resp.ok) {
                    setHiddenId('');
                    return;
                }
                const data = await resp.json().catch(() => null);
                if (!data || !data.place || !data.place.id) {
                    setHiddenId('');
                    return;
                }
                setHiddenId(String(data.place.id));
                if (typeof settings.onSelect === 'function') {
                    try { settings.onSelect(data.place); } catch (_e) { /* swallow */ }
                }
            } catch (_e) {
                setHiddenId('');
            }
        }

        function closePanel() {
            candidates = [];
            highlight = -1;
            panel.style.display = 'none';
            inputEl.setAttribute('aria-expanded', 'false');
        }

        function onInput() {
            /* Free-typing invalidates any previously-picked Id —
               the form should now persist the string as-is. */
            if (settings.hiddenIdInput && settings.hiddenIdInput.value !== '') {
                setHiddenId('');
            }
            const q = inputEl.value.trim();
            if (q === lastQuery) return;
            lastQuery = q;
            if (debounceTimer) clearTimeout(debounceTimer);
            if (q.length < settings.minChars) {
                closePanel();
                return;
            }
            debounceTimer = setTimeout(() => runSearch(q), settings.debounceMs);
        }

        function onKeydown(ev) {
            if (panel.style.display === 'none') return;
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                highlight = Math.min(candidates.length - 1, highlight + 1);
                renderPanel();
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                highlight = Math.max(0, highlight - 1);
                renderPanel();
            } else if (ev.key === 'Enter') {
                if (highlight >= 0 && highlight < candidates.length) {
                    ev.preventDefault();
                    pickCandidate(highlight);
                }
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                closePanel();
            }
        }

        function onBlur() {
            /* Delay close so a mousedown on a panel item still
               registers before the panel disappears. */
            setTimeout(closePanel, 150);
        }

        function onWindow() {
            if (panel.style.display !== 'none') positionPanel();
        }

        inputEl.addEventListener('input', onInput);
        inputEl.addEventListener('keydown', onKeydown);
        inputEl.addEventListener('blur', onBlur);
        window.addEventListener('scroll', onWindow, true);
        window.addEventListener('resize', onWindow);

        return function teardown() {
            inputEl.removeEventListener('input', onInput);
            inputEl.removeEventListener('keydown', onKeydown);
            inputEl.removeEventListener('blur', onBlur);
            window.removeEventListener('scroll', onWindow, true);
            window.removeEventListener('resize', onWindow);
            if (panel.parentNode) panel.parentNode.removeChild(panel);
            delete inputEl.dataset.placeSearchAttached;
        };
    }

    window.iHymnsPlaceSearch = { attach };
})();
