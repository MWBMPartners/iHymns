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
 *
 * #1507 — UX polish (loading state + confirmed selection):
 *   - ELI5: while the lookup is running you now see a small spinner
 *     next to the box, so "slow to list" reads as "still working",
 *     not "broken". Once a place is picked (or the field re-opens
 *     already holding one), a green checkmark + border confirm the
 *     hidden Id is actually set.
 *   - DETAILED: a status icon (spinner while `runSearch()` has an
 *     in-flight fetch; a checkmark whenever `hiddenIdInput.value` is
 *     non-empty) is positioned next to the input the same way the
 *     dropdown panel already is (absolute, recomputed on focus/
 *     input/scroll/resize — @link https://developer.mozilla.org/en-US/docs/Web/API/Element/getBoundingClientRect).
 *     The confirmed state piggybacks on the EXISTING `setHiddenId()`
 *     → dispatchEvent('change') wiring, so any caller that sets the
 *     hidden Id directly (e.g. an Edit-drawer pre-fill) and then
 *     dispatches its own 'change' event gets the same visual for free
 *     — no per-page duplication.
 */

(function () {
    'use strict';

    const DEFAULTS = {
        endpoint:   '/manage/places-api.php',
        minChars:   3,
        debounceMs: 250,
        maxResults: 8,
    };

    /* #1507 — one shared <style> block for every attach()'d input on the
       page (spinner keyframes + the two status-icon shapes). Injected at
       most once (id-guarded) so N place fields on one page (birth +
       death, or a venue's address fields) don't each add their own copy. */
    const STATUS_STYLE_ID = 'ihymns-place-search-status-styles';
    function ensureStatusStyles() {
        if (document.getElementById(STATUS_STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STATUS_STYLE_ID;
        style.textContent =
            '@keyframes ihymnsPlaceSearchSpin { to { transform: rotate(360deg); } }' +
            '.ihymns-place-search-spinner {' +
                'display:inline-block;width:0.85rem;height:0.85rem;' +
                'border:2px solid rgba(127,127,127,0.35);' +
                'border-top-color:var(--bs-primary,#0d6efd);' +
                'border-radius:50%;' +
                'animation:ihymnsPlaceSearchSpin 0.6s linear infinite;' +
            '}' +
            '.ihymns-place-search-check {' +
                'display:inline-flex;align-items:center;justify-content:center;' +
                'width:0.95rem;height:0.95rem;border-radius:50%;' +
                'background:var(--bs-success,#198754);color:#fff;' +
                'font-size:0.65rem;line-height:1;' +
            '}';
        document.head.appendChild(style);
    }

    /* Each attach() instance gets its own state object — the
       module supports any number of place inputs on one page. */
    function attach(inputEl, opts) {
        if (!inputEl || inputEl.dataset.placeSearchAttached === '1') {
            return function () {};
        }
        ensureStatusStyles();
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

        /* #1507 — status icon (spinner while a lookup is in flight, a
           checkmark once the hidden Id carries a confirmed pick). Same
           "append to body, position via getBoundingClientRect()" trick as
           the dropdown panel so it isn't clipped by an overflow:hidden
           parent (Bootstrap offcanvas / card). Non-interactive — sits
           visually next to the input, never blocks clicks/typing. */
        const statusIcon = document.createElement('div');
        statusIcon.className = 'ihymns-place-search-status';
        statusIcon.style.position = 'absolute';
        statusIcon.style.zIndex = '20000';
        statusIcon.style.pointerEvents = 'none';
        statusIcon.style.display = 'none';
        document.body.appendChild(statusIcon);

        function positionStatusIcon() {
            const rect = inputEl.getBoundingClientRect();
            const size = 15; /* matches the icon's rendered box, px */
            statusIcon.style.left = (window.scrollX + rect.right - size - 8) + 'px';
            statusIcon.style.top  = (window.scrollY + rect.top + (rect.height - size) / 2) + 'px';
        }

        /* state: 'idle' (hidden) | 'loading' (spinner) | 'confirmed' (check).
           ELI5: one function that decides what little icon (if any) sits
           next to the box right now. */
        function setStatus(state) {
            if (state === 'loading') {
                statusIcon.innerHTML = '<span class="ihymns-place-search-spinner" aria-hidden="true"></span>';
                statusIcon.setAttribute('aria-label', 'Searching for places…');
                statusIcon.style.display = 'block';
                positionStatusIcon();
            } else if (state === 'confirmed') {
                statusIcon.innerHTML = '<span class="ihymns-place-search-check" aria-hidden="true">&#10003;</span>';
                statusIcon.setAttribute('aria-label', 'Place confirmed');
                statusIcon.style.display = 'block';
                positionStatusIcon();
            } else {
                statusIcon.style.display = 'none';
                statusIcon.removeAttribute('aria-label');
            }
        }

        /* Distinct "confirmed" affordance on the INPUT itself (not just the
           small icon) — a subtle success-coloured border + focus-ring glow,
           inline-styled to match this module's existing self-contained
           approach (no external stylesheet dependency). */
        function setConfirmedBorder(isConfirmed) {
            if (isConfirmed) {
                inputEl.style.borderColor = 'var(--bs-success, #198754)';
                inputEl.style.boxShadow   = '0 0 0 0.15rem rgba(25,135,84,0.25)';
            } else {
                inputEl.style.borderColor = '';
                inputEl.style.boxShadow   = '';
            }
        }

        /* The hidden Id is the SINGLE source of truth for "is this place
           confirmed?" — driven by the sibling hidden input's 'change'
           event, which setHiddenId() already dispatches on every write.
           Reacting to the event (rather than only calling this inline at
           pick-time) means an external caller that sets the hidden input's
           value directly (e.g. an Edit-drawer's pre-fill script) gets the
           same checkmark + border for free, just by dispatching its own
           'change' event after the assignment — no per-page duplication. */
        function updateConfirmedFromHidden() {
            const has = !!(settings.hiddenIdInput && settings.hiddenIdInput.value !== '');
            setConfirmedBorder(has);
            /* Don't stomp an in-flight 'loading' state — a confirmed pick
               and a fresh search never overlap in practice (onInput()
               already clears the hidden Id before debouncing a new
               search), but stay defensive rather than assume that. */
            if (statusIcon.getAttribute('aria-label') !== 'Searching for places…') {
                setStatus(has ? 'confirmed' : 'idle');
            }
        }
        if (settings.hiddenIdInput) {
            settings.hiddenIdInput.addEventListener('change', updateConfirmedFromHidden);
            updateConfirmedFromHidden(); /* reflect any value already present at attach time */
        }

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
            /* #1507 — the query is now genuinely in flight (past the
               debounce delay): show the spinner so "nothing's happening"
               reads as "still searching", not "broken". Always safe to set
               for the newest request even if it supersedes an older one —
               the older call's own `requestId !== currentRequest` guards
               below stop it from clobbering this state afterwards. */
            setStatus('loading');
            const url = settings.endpoint + '?action=search&q=' + encodeURIComponent(query) + '&limit=' + settings.maxResults;
            let resp;
            try {
                resp = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
            } catch (_e) {
                if (requestId === currentRequest) {
                    setStatus('idle');
                    renderHint('Place lookup unavailable (offline?). Your text is saved as typed.');
                }
                return;
            }
            if (requestId !== currentRequest) return; /* superseded — the newer request owns the status icon */
            if (!resp.ok) {
                /* Pull the server's `detail` (admins get the real error message)
                   so the curator/dev sees WHY, not just the status (#1180). */
                let detail = '';
                try {
                    const errBody = await resp.json();
                    if (errBody && errBody.detail) { detail = ' — ' + errBody.detail; }
                } catch (_e) { /* no JSON body */ }
                setStatus('idle');
                renderHint('Place lookup unavailable (HTTP ' + resp.status + ')' + detail + '. Your text is saved as typed.');
                return;
            }
            const data = await resp.json().catch(() => null);
            if (!data || !Array.isArray(data.results)) {
                setStatus('idle');
                renderHint('Place lookup returned no data. Your text is saved as typed.');
                return;
            }
            candidates = data.results;
            highlight = candidates.length ? 0 : -1;
            if (!candidates.length) {
                setStatus('idle');
                renderHint('No matching places — your text is saved as typed.');
                return;
            }
            setStatus('idle');
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
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
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
            /* #1507 — the status icon is also absolutely positioned
               against the input, so it needs the same reflow on
               scroll/resize as the dropdown panel. */
            if (statusIcon.style.display !== 'none') positionStatusIcon();
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
            if (settings.hiddenIdInput) {
                settings.hiddenIdInput.removeEventListener('change', updateConfirmedFromHidden);
            }
            if (panel.parentNode) panel.parentNode.removeChild(panel);
            if (statusIcon.parentNode) statusIcon.parentNode.removeChild(statusIcon);
            inputEl.style.borderColor = '';
            inputEl.style.boxShadow   = '';
            delete inputEl.dataset.placeSearchAttached;
        };
    }

    window.iHymnsPlaceSearch = { attach };
})();
