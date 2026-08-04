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
 * Options (additive, #1741 P5c) — GENERALISATION for a second, non-place
 * consumer (the Song Editor's tune typeahead), never a fork of this module
 * (modularity rule). Every default below reproduces TODAY's behaviour
 * byte-for-byte, so the two pre-existing consumers (Credit People / Works /
 * Venues / Organisations place pickers, and this tab's own origin-city
 * picker) are UNCHANGED unless they opt in:
 *   searchUrl(query, settings) -> string
 *                                      Builds the search request URL.
 *                                      DEFAULT: `${endpoint}?action=search&
 *                                      q=${query}&limit=${maxResults}` (the
 *                                      places-api.php shape). A caller with a
 *                                      differently-shaped search endpoint
 *                                      (tune_search's `&meter=` leg) supplies
 *                                      its own.
 *   parseResults(data) -> array|null   Maps the fetched JSON to the
 *                                      candidates array (or null for "no
 *                                      usable data", which renders the
 *                                      existing "returned no data" hint).
 *                                      DEFAULT: `Array.isArray(data.results)
 *                                      ? data.results : null`. Each returned
 *                                      candidate is rendered as
 *                                      `{ display_name, hint?, id, ... }` —
 *                                      `hint`, if present, REPLACES the
 *                                      default type/address.country meta
 *                                      line (see renderPanel() below).
 *   pickMode       'upsert' | 'value'  DEFAULT 'upsert' — today's behaviour:
 *                                      pickCandidate() POSTs `?action=upsert`
 *                                      and adopts `data.place.id`. 'value':
 *                                      NO network call — sets the hidden id
 *                                      straight from the candidate's own
 *                                      `id`, calls onSelect(candidate), and
 *                                      leaves PERSISTING the pick entirely to
 *                                      the caller (e.g. a separate
 *                                      `song_tune_set` POST) — needed because
 *                                      a place row is idempotently
 *                                      upsert-by-value, but a tblTunes
 *                                      find-or-create is NOT something a
 *                                      generic typeahead should trigger
 *                                      twice for one pick.
 *   noun           {singular, plural}  DEFAULT {singular:'place',
 *                                      plural:'places'} — threads through
 *                                      every user-facing string (loading
 *                                      hints, live-region announcements) so
 *                                      a non-place instance reads "2 tunes
 *                                      found." instead of "2 places found.".
 * No other behaviour changes — the #1594 keyboard/ARIA machinery below is
 * shape-agnostic and untouched; @see tests/test-place-search-keyboard.js
 * (proves the pre-existing default path is unaffected).
 *
 * Behaviour:
 *   - Free-typing clears the hidden Id (curator typed something
 *     not in the registry — the form falls back to display-string-only).
 *   - Picking a candidate from the dropdown POSTs to /upsert and
 *     fills the hidden Id with the returned tblPlaces row Id.
 *   - Down/Up cycles the highlight, Home/End jump to the first/last
 *     candidate, Enter accepts, Tab commits the highlighted candidate
 *     and then moves focus on as normal, Escape closes without
 *     changing the value (and does not propagate further — see the
 *     #1594 note below for why that matters inside a Bootstrap
 *     offcanvas/modal host).
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
 *
 * #1594 — keyboard-accessible combobox completion:
 *   - ELI5: arrow keys / Tab / Escape / Home / End now all behave the
 *     way a screen-reader user (or anyone who doesn't reach for the
 *     mouse) expects, the browser's own address-autofill popup no
 *     longer steals the arrow keys, and Escape only closes OUR
 *     dropdown instead of also slamming shut the whole Credit People
 *     drawer underneath it.
 *   - DETAILED, per sub-fix:
 *     1. `autocomplete="off"` is replaced with an unrecognised token
 *        (see the comment at the `setAttribute('autocomplete', …)`
 *        call) — Chrome ignores `off` on address-shaped fields and
 *        was intercepting Down/Up/Enter at the browser level before
 *        our `onKeydown` ever ran.
 *     2. Tab now COMMITS the highlighted candidate (`pickCandidate()`)
 *        instead of silently discarding it via `onBlur()`'s bare
 *        `closePanel()`; Tab is never `preventDefault()`-ed so focus
 *        still moves on as normal (no keyboard trap).
 *     3. Home / End jump the highlight to the first / last candidate.
 *     4. Escape now `stopPropagation()`s in addition to
 *        `preventDefault()` — see the comment on the Escape branch of
 *        `onKeydown` for why the musicians offcanvas drawer needed
 *        this.
 *     5. The ARIA 1.2 combobox pattern is now complete: `aria-controls`
 *        ties the input to the (now uniquely `id`'d) listbox panel,
 *        `aria-activedescendant` tracks the highlighted option's
 *        (also uniquely `id`'d) row, inactive options carry an explicit
 *        `aria-selected="false"`, and a reused polite live region
 *        announces the result count on every resolved search.
 *        @link https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
 *     6. The hover/keyboard "highlight fight" (mouse resting stationary
 *        over a row that `renderPanel()` just recreated re-pins the
 *        highlight against the arrow keys) is fixed by listening for
 *        `mousemove` instead of `mouseenter` — see the comment on that
 *        listener for the mechanism.
 *   - NOT done: a partial/diffed re-render of just the changed rows on
 *     highlight change (would also remove the need for fix #6 and
 *     preserve scroll/focus state more cleanly) — `renderPanel()` still
 *     does a full `innerHTML` rebuild on every highlight change. Scoped
 *     out as a larger refactor; tracked as a follow-up in #1594.
 */

(function () {
    'use strict';

    const DEFAULTS = {
        endpoint:   '/manage/places-api.php',
        minChars:   3,
        debounceMs: 250,
        maxResults: 8,
    };

    /* #1741 P5c — the four additive generalisation defaults (module doc-block
       above). Kept OUTSIDE the `DEFAULTS` object above deliberately: DEFAULTS
       holds the places-api.php REQUEST TUNING the 2 pre-existing consumers
       (birth/death place, origin-city) already read/override, and this
       module's own default-content proof (tests/test-place-search-keyboard.js)
       and the P5c UI guard both check that object's exact 4-key shape stays
       unchanged (rule #34) — folding function-valued options into it would
       make "unchanged in content" ambiguous to assert against. */

    /** DEFAULT searchUrl(query, settings) — today's :`?action=search&q=…&limit=…` line, extracted verbatim. */
    function defaultSearchUrl(query, settings) {
        return settings.endpoint + '?action=search&q=' + encodeURIComponent(query) + '&limit=' + settings.maxResults;
    }

    /** DEFAULT parseResults(data) — today's `Array.isArray(data.results) ? data.results : null` check, extracted verbatim. */
    function defaultParseResults(data) {
        return (data && Array.isArray(data.results)) ? data.results : null;
    }

    const DEFAULT_NOUN = { singular: 'place', plural: 'places' };

    /** Capitalise the first character — used to turn `noun.singular` ("place" /
     *  "tune") into the sentence-initial "Place" / "Tune" the hint strings need. */
    function capitalise(word) {
        return word.length ? word.charAt(0).toUpperCase() + word.slice(1) : word;
    }

    /* #1594 — module-scoped counter so every attach()'d input gets a
       globally-unique panel/option id set. ELI5: the musicians
       drawer alone calls attach() twice on one page (birth place +
       death place), so a hardcoded id would collide between the two
       instances and break `aria-controls` / `aria-activedescendant` for
       one of them (duplicate DOM ids are invalid HTML and assistive
       tech behaviour around them is undefined). A simple incrementing
       counter guarantees uniqueness without pulling in a UUID helper. */
    let instanceSeq = 0;

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
        /* #1741 P5c — resolve the four additive generalisation options once,
           here, rather than re-checking `typeof settings.X === 'function'`
           at every call site below. Each falls back to TODAY's behaviour
           (see the defaultSearchUrl/defaultParseResults functions + the
           module doc-block's "Options (additive)" section above) when the
           caller didn't pass one — the 2 pre-existing consumers pass none
           of these, so `resolvedSearchUrl`/`resolvedParseResults` are
           exactly `defaultSearchUrl`/`defaultParseResults` for them. */
        const resolvedSearchUrl    = typeof settings.searchUrl === 'function' ? settings.searchUrl : defaultSearchUrl;
        const resolvedParseResults = typeof settings.parseResults === 'function' ? settings.parseResults : defaultParseResults;
        const pickMode = settings.pickMode === 'value' ? 'value' : 'upsert';   // default 'upsert'
        const noun = (settings.noun && settings.noun.singular && settings.noun.plural) ? settings.noun : DEFAULT_NOUN;
        inputEl.dataset.placeSearchAttached = '1';
        /* #1594 — `autocomplete="off"` is NOT sufficient here. Chrome
           deliberately IGNORES `off` on fields it heuristically decides
           are address-shaped (this module's actual call sites: birth
           place / death place / venue address / song origin city all
           qualify), and layers its OWN native autofill dropdown on top
           of ours — which then swallows ArrowDown/ArrowUp/Enter at the
           BROWSER level before this module's `onKeydown` listener ever
           sees them. That's why "the keydown listener is attached and
           the keys still do nothing" was reported on the live site but
           never reproduced under jsdom (no real autofill engine there).
           Autofill engines only back off for a value they can't map to
           a known field type, so we hand Chrome a value it has no
           heuristic for instead of "off". This is empirically-observed
           browser behaviour, not a documented guarantee — it may need
           revisiting if a future Chrome starts pattern-matching custom
           token strings too.
           @link https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/autocomplete */
        inputEl.setAttribute('autocomplete', 'ihymns-place-search');
        inputEl.setAttribute('spellcheck', 'false');
        inputEl.setAttribute('role', 'combobox');
        inputEl.setAttribute('aria-autocomplete', 'list');
        inputEl.setAttribute('aria-expanded', 'false');

        /* #1594 — every attach()'d instance needs a unique panel id so
           `aria-controls` (input → panel) and `aria-activedescendant`
           (input → active option) are valid, non-colliding references —
           see the `instanceSeq` module-scope comment above for why a
           hardcoded id would break the second instance on the same page. */
        const instanceId = 'ihymns-place-search-panel-' + (++instanceSeq);

        /* Dropdown panel — appended to <body> so it escapes
           parent overflow:hidden containers (Bootstrap offcanvas,
           cards with overflow:auto). Position recomputed on
           focus + input + scroll. */
        const panel = document.createElement('div');
        panel.id = instanceId;
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
        inputEl.setAttribute('aria-controls', instanceId);

        /* #1594 — one reused polite live region per attach()'d instance,
           announcing the result COUNT whenever a search resolves ("3
           places found" / "No places found"). ELI5: the dropdown itself
           is only useful to sighted mouse/keyboard users watching the
           screen — a screen-reader user gets no feedback at all that
           typing produced (or didn't produce) results unless something
           explicitly speaks it. `aria-live="polite"` + `aria-atomic="true"`
           makes a screen reader announce the ENTIRE new text every time
           it changes (not just an appended diff), read out once the user
           pauses rather than interrupting their typing. Created ONCE here
           and its `textContent` is overwritten on each resolution —
           creating a fresh node per keystroke would just be DOM churn
           for zero benefit, since it's the live-region role (not node
           identity) that assistive tech tracks. Visually hidden with the
           standard "clip" technique (same approach Bootstrap's own
           `.visually-hidden` uses) so sighted users never see it.
           @link https://www.w3.org/WAI/ARIA/apg/patterns/combobox/ (announcing result counts)
           @link https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-live */
        const liveRegion = document.createElement('div');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        liveRegion.style.position = 'absolute';
        liveRegion.style.width = '1px';
        liveRegion.style.height = '1px';
        liveRegion.style.padding = '0';
        liveRegion.style.margin = '-1px';
        liveRegion.style.overflow = 'hidden';
        liveRegion.style.clip = 'rect(0,0,0,0)';
        liveRegion.style.whiteSpace = 'nowrap';
        liveRegion.style.border = '0';
        document.body.appendChild(liveRegion);

        function announce(text) {
            liveRegion.textContent = text;
        }

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

        /* #1594 — the id of the currently-highlighted option, or null. This
           is the string `aria-activedescendant` needs; kept as one helper
           so every call site (renderPanel's two branches, renderHint,
           closePanel) computes/clears it the same way instead of each
           re-deriving `instanceId + '-opt-' + highlight` inline. */
        function optionId(index) {
            return instanceId + '-opt-' + index;
        }

        /* #1594 — the piece of the ARIA combobox pattern that makes a
           screen reader actually SPEAK the highlighted option. ELI5: the
           yellow highlight box is a purely visual cue — without this, a
           screen-reader user arrowing through the list hears nothing at
           all change, because focus itself never leaves the <input> (this
           is a "virtual focus" / "activedescendant" pattern, not real DOM
           focus moving into the listbox). Must be called every time
           `highlight` changes, and cleared (not left pointing at a
           stale/removed option id) whenever there's no valid highlight.
           @link https://www.w3.org/WAI/ARIA/apg/patterns/combobox/ */
        function updateActiveDescendant() {
            if (highlight >= 0 && highlight < candidates.length) {
                inputEl.setAttribute('aria-activedescendant', optionId(highlight));
            } else {
                inputEl.removeAttribute('aria-activedescendant');
            }
        }

        /* #1594 — follow the highlight when it moves via the KEYBOARD
           (Arrow/Home/End) past what's currently scrolled into view.
           `block: 'nearest'` is deliberate over `'center'`/`'start'`: it
           only scrolls the minimum amount needed to bring the row fully
           into view, so a highlight move that's already visible causes no
           scroll jump at all. Guarded for environments (older browsers,
           jsdom) where `scrollIntoView` isn't implemented, so a missing
           polyfill degrades to "no auto-scroll" rather than a thrown error.
           @link https://developer.mozilla.org/en-US/docs/Web/API/Element/scrollIntoView */
        function scrollActiveIntoView() {
            if (highlight < 0 || highlight >= candidates.length) return;
            const el = document.getElementById(optionId(highlight));
            if (el && typeof el.scrollIntoView === 'function') {
                try { el.scrollIntoView({ block: 'nearest' }); } catch (_e) { /* no-op */ }
            }
        }

        function renderPanel() {
            if (!candidates.length) {
                panel.style.display = 'none';
                inputEl.setAttribute('aria-expanded', 'false');
                updateActiveDescendant();
                return;
            }
            panel.innerHTML = '';
            candidates.forEach((c, i) => {
                const item = document.createElement('div');
                item.className = 'ihymns-place-search-item';
                item.setAttribute('role', 'option');
                item.id = optionId(i);
                item.dataset.index = String(i);
                item.style.padding = '0.4rem 0.6rem';
                item.style.cursor = 'pointer';
                item.style.borderBottom = '1px solid rgba(255,255,255,0.06)';
                if (i === highlight) {
                    item.style.background = 'var(--bs-primary-bg-subtle, rgba(255, 193, 7, 0.18))';
                    item.setAttribute('aria-selected', 'true');
                } else {
                    /* #1594 — explicit "false" on every inactive row, not
                       just an implicit absence of "true". Several screen
                       readers only announce selection state reliably when
                       aria-selected is present on ALL options in the
                       listbox, not just the active one. */
                    item.setAttribute('aria-selected', 'false');
                }
                const main = document.createElement('div');
                main.textContent = c.display_name;
                main.style.fontWeight = '500';
                main.style.whiteSpace = 'nowrap';
                main.style.overflow = 'hidden';
                main.style.textOverflow = 'ellipsis';
                item.appendChild(main);
                /* #1741 P5c — a caller's parseResults() may map a candidate
                   to `{ display_name, hint, ... }` instead of the places-api
                   `{ type, address }` shape (tune_search has neither — it has
                   meter/alias/usage folded into one `hint` string server-
                   side isn't right either, so the CLIENT's parseResults maps
                   it to `hint`). `c.hint` takes over the WHOLE meta line when
                   present; the default place shape (no `hint` key) renders
                   exactly as before. */
                if (c.hint || c.type || c.address) {
                    const meta = document.createElement('div');
                    meta.style.fontSize = '0.75rem';
                    meta.style.opacity = '0.7';
                    meta.textContent = c.hint
                        ? c.hint
                        : (c.type || '') + (c.address && c.address.country ? ' • ' + c.address.country : '');
                    item.appendChild(meta);
                }
                item.addEventListener('mousedown', (ev) => {
                    /* mousedown rather than click so the pick fires
                       before the input's blur tears the panel down. */
                    ev.preventDefault();
                    pickCandidate(i);
                });
                /* #1594 — bind 'mousemove', NOT 'mouseenter'. This looks
                   like an obviously-safe swap so it's worth spelling out
                   the bug it fixes: renderPanel() destroys and recreates
                   EVERY row on each highlight change (arrow-key press). If
                   the mouse cursor happens to be resting stationary over
                   the list when that happens, the browser still fires
                   'mouseenter' on the freshly-created node under it —
                   because 'mouseenter' fires from a hit-test recompute
                   whenever the DOM changes under a pointer, not only from
                   genuine pointer motion. That handler used to then call
                   renderPanel() again, which recreates the rows again,
                   which can refire 'mouseenter' again — a feedback loop
                   where simply resting the mouse over the list fights
                   every arrow-key press and repeatedly pins the highlight
                   back to wherever the cursor happens to sit.
                   'mousemove' only fires on an actual pointer-input event,
                   never as a side effect of us mutating the DOM, so this
                   listener cannot re-trigger itself. The `highlight === i`
                   guard is a second, cheap layer that also skips redundant
                   re-renders while the pointer drifts within the same row.
                   @link https://developer.mozilla.org/en-US/docs/Web/API/Element/mousemove_event */
                item.addEventListener('mousemove', () => {
                    if (highlight === i) return;
                    highlight = i;
                    renderPanel();
                });
                panel.appendChild(item);
            });
            positionPanel();
            panel.style.display = 'block';
            inputEl.setAttribute('aria-expanded', 'true');
            updateActiveDescendant();
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
            /* #1594 — this panel state has no selectable options (it's an
               informational row, not a listbox item — see the function
               doc-comment above), so there's nothing valid to point
               aria-activedescendant at; clear it rather than leave it
               referencing a since-removed option id. */
            updateActiveDescendant();
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
            /* #1741 P5c — resolvedSearchUrl defaults to today's exact
               '?action=search&q=…&limit=…' line (defaultSearchUrl above). */
            const url = resolvedSearchUrl(query, settings);
            let resp;
            try {
                resp = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
            } catch (_e) {
                if (requestId === currentRequest) {
                    setStatus('idle');
                    renderHint(capitalise(noun.singular) + ' lookup unavailable (offline?). Your text is saved as typed.');
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
                renderHint(capitalise(noun.singular) + ' lookup unavailable (HTTP ' + resp.status + ')' + detail + '. Your text is saved as typed.');
                return;
            }
            const data = await resp.json().catch(() => null);
            /* #1741 P5c — resolvedParseResults defaults to today's exact
               `Array.isArray(data.results) ? data.results : null` check
               (defaultParseResults above); a caller with a differently-
               shaped payload (tune_search's `{ suggestions: [...] }`)
               supplies its own mapping to the SAME candidate shape. */
            const results = data ? resolvedParseResults(data) : null;
            if (!results) {
                setStatus('idle');
                renderHint(capitalise(noun.singular) + ' lookup returned no data. Your text is saved as typed.');
                return;
            }
            candidates = results;
            highlight = candidates.length ? 0 : -1;
            if (!candidates.length) {
                setStatus('idle');
                renderHint('No matching ' + noun.plural + ' — your text is saved as typed.');
                /* #1594 — announce the resolved-but-empty result too, not
                   just the success count below. A screen-reader user who
                   hears nothing after typing can't tell "still searching"
                   apart from "found nothing" apart from "this is broken". */
                announce('No ' + noun.plural + ' found.');
                return;
            }
            setStatus('idle');
            renderPanel();
            /* #1594 — announce the result COUNT on every resolved search
               (the ARIA APG combobox pattern's recommended feedback for
               screen-reader users, who can't see the panel populate).
               Singular/plural handled via `noun` (#1741 P5c) rather than a
               pluralisation helper for one string. */
            announce(candidates.length + (candidates.length === 1 ? ' ' + noun.singular + ' found.' : ' ' + noun.plural + ' found.'));
        }

        async function pickCandidate(index) {
            const c = candidates[index];
            if (!c) return;
            inputEl.value = c.display_name;
            /* Optimistic UI — show the picked label immediately; the
               upsert call below races behind to claim the FK. */
            inputEl.dataset.placeSearchPicked = '1';
            closePanel();

            /* #1741 P5c — pickMode:'value' (default 'upsert', untouched
               below). NO network call here: the candidate already carries
               its own `id` (tune_search's suggestions do), so the hidden
               input is set straight from it and PERSISTING the pick is left
               entirely to the caller via onSelect() — a find-or-CREATE
               funnel (tblTunes) must not be triggered a SECOND time by this
               module racing its own upsert behind the caller's real save. */
            if (pickMode === 'value') {
                setHiddenId(String(c.id != null ? c.id : ''));
                if (typeof settings.onSelect === 'function') {
                    try { settings.onSelect(c); } catch (_e) { /* swallow */ }
                }
                return;
            }

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
            /* #1594 — no panel means no valid active option; drop the
               reference rather than leave the input pointing at an id
               that's about to be wiped out on the next renderPanel(). */
            updateActiveDescendant();
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
                scrollActiveIntoView();
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                highlight = Math.max(0, highlight - 1);
                renderPanel();
                scrollActiveIntoView();
            } else if (ev.key === 'Home') {
                /* #1594 — jump straight to the first candidate. Matches
                   the ARIA APG combobox pattern's recommended keyboard
                   support (Home = first option, End = last option) and
                   spares a keyboard user N presses of ArrowUp on a long
                   result list. */
                ev.preventDefault();
                highlight = 0;
                renderPanel();
                scrollActiveIntoView();
            } else if (ev.key === 'End') {
                ev.preventDefault();
                highlight = candidates.length - 1;
                renderPanel();
                scrollActiveIntoView();
            } else if (ev.key === 'Enter') {
                if (highlight >= 0 && highlight < candidates.length) {
                    ev.preventDefault();
                    pickCandidate(highlight);
                }
            } else if (ev.key === 'Tab') {
                /* #1594 — Tab must COMMIT the highlighted option, not
                   silently discard it. Previously Tab fell through with
                   no handler here at all, so the field just blurred —
                   onBlur()'s delayed closePanel() throws away whatever
                   the user had arrowed to, a silent data-loss trap for
                   anyone tabbing through the form by keyboard instead of
                   pressing Enter first. Deliberately NOT preventDefault()
                   here: trapping Tab would break the page's natural focus
                   order, a keyboard-trap failure of its own (WCAG 2.1.2).
                   So: commit synchronously, then let the browser move
                   focus on exactly as it would have anyway — pickCandidate()
                   calls closePanel() synchronously before its network
                   round-trip, so there's nothing left open by the time
                   focus lands on the next field.
                   @link https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
                   @link https://www.w3.org/WAI/WCAG21/Understanding/no-keyboard-trap.html */
                if (highlight >= 0 && highlight < candidates.length) {
                    pickCandidate(highlight);
                }
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                /* #1594 — MUST also stopPropagation(). Without it, this
                   keydown keeps bubbling up past the input and reaches
                   Bootstrap's own Escape handler on any offcanvas/modal
                   host the field happens to live inside — e.g. Credit
                   People's `#cpDrawer` — which then closes the ENTIRE
                   drawer. The curator only meant to dismiss the suggestion
                   panel and instead loses a part-filled form: a data-loss
                   hazard, not just a UX papercut. This branch only runs
                   while the panel is open (the function-top guard above
                   returns early otherwise), so a bare Escape on an idle
                   field is untouched by this and still bubbles normally —
                   closing the host drawer/modal in that case is correct.
                   @link https://developer.mozilla.org/en-US/docs/Web/API/Event/stopPropagation */
                ev.stopPropagation();
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
            /* #1594 — the live region is a body-appended node like panel/
               statusIcon above, so it needs the same teardown; and the two
               ARIA attributes this module added to the caller's own
               <input> (aria-controls / aria-activedescendant) must be
               removed too, or a torn-down instance leaves the input
               pointing at ids for a panel/listbox that no longer exist. */
            if (liveRegion.parentNode) liveRegion.parentNode.removeChild(liveRegion);
            inputEl.removeAttribute('aria-controls');
            inputEl.removeAttribute('aria-activedescendant');
            inputEl.style.borderColor = '';
            inputEl.style.boxShadow   = '';
            delete inputEl.dataset.placeSearchAttached;
        };
    }

    window.iHymnsPlaceSearch = { attach };
})();
