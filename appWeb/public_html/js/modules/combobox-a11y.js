/**
 * iHymns — Shared Combobox Keyboard + ARIA behaviour helper (#1594 part 2)
 *
 * Second half of the #1594 combobox consolidation. `place-search.js` (read
 * that file first — it's the reference implementation this helper was
 * extracted from) hardened ONE typeahead into a complete ARIA 1.2 combobox.
 * Eight-and-a-half further typeahead implementations across the codebase
 * (manage/works.php's add-song search, manage/includes/renderEntityPicker.js,
 * three popovers in manage/editor/editor.js, manage/editor/v2/tags-tab.js,
 * js/modules/service-broadcast.js, js/modules/compare.js and — ARIA only —
 * js/modules/search.js) were all independent, divergent, and mostly
 * keyboard-hostile: the modularity rule (.claude/CLAUDE.md, "when a piece
 * of UI exists in more than one place, extract it") flags that as a real
 * accessibility defect, not just duplication.
 *
 * DECISION (see the #1594 part-2 task notes): a full generic combobox core
 * was rejected in favour of this narrower helper. The eight sites genuinely
 * differ in what they fetch, how they render a row, and what "commit"
 * means (upsert a place vs. attach a tag vs. broadcast a song vs. insert a
 * work member) — forcing them all through one render/fetch abstraction
 * would be a large, lossy rewrite for a keyboard-and-ARIA bug. This module
 * owns ONLY the three things that were genuinely duplicated: which key
 * does what (with the same clamping / Tab-commit / Escape-stopPropagation
 * semantics place-search.js proved out), the aria-activedescendant /
 * aria-selected / aria-expanded bookkeeping, and nothing else. Each call
 * site keeps its own fetch, its own render, and its own idea of what
 * "commit" does.
 *
 * Exposed on `window.iHymnsComboboxA11y`:
 *   handleComboboxKeydown(event, config) → boolean (was the key handled?)
 *   applyComboboxAria(config)            → void (ARIA + id bookkeeping)
 *
 * DUAL CONSUMPTION (both are required — see the task's call-site table):
 *   - Admin pages (`/manage/*`, no CSP) load this as a plain classic
 *     `<script src="/js/modules/combobox-a11y.js?v=...">` — same as
 *     external-link-detect.js and place-search.js — and read
 *     `window.iHymnsComboboxA11y` directly. It is loaded once, globally,
 *     from `manage/includes/head-libs.php` (mirrors how that file already
 *     loads external-link-detect.js for every admin page); the two pages
 *     with a bespoke `<head>` that skips head-libs.php (editor/index.php,
 *     which loads editor.js as a classic non-module script) load it
 *     explicitly instead, right where place-search.js already is.
 *   - SPA / v2-editor ES modules (search.js, compare.js,
 *     service-broadcast.js, editor/v2/tags-tab.js) import it for its
 *     side effect — `import '/js/modules/combobox-a11y.js';` — then also
 *     read `window.iHymnsComboboxA11y`. This file deliberately contains
 *     NO `import`/`export` statement: that keyword is a hard SyntaxError
 *     when a file is loaded via a classic (non type="module") <script src>
 *     tag, which is exactly how editor.js, works.php and
 *     renderEntityPicker.js consume their globals today. Avoiding
 *     `export` is what lets ONE file serve both worlds instead of forking
 *     into two copies — see the CLAUDE.md modularity rule this whole
 *     effort exists to satisfy. ES-module `import` for side effects only
 *     (no bound names) is a standard, spec-legal pattern:
 *     @link https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/import#import_a_module_for_its_side_effects_only
 *
 * WHAT THIS HELPER DELIBERATELY DOES NOT OWN (so a caller isn't surprised):
 *   - Fetching / debouncing — every call site already has its own.
 *   - Rendering rows — every call site already has its own render()/
 *     renderXxx() function; `handleComboboxKeydown` calls it back after
 *     moving the highlight so the caller can re-paint (mirrors
 *     place-search.js's own renderPanel()-on-every-arrow-press design;
 *     that file's doc-comment #6 already notes a diffed re-render was
 *     scoped OUT as a larger follow-up, so this helper doesn't attempt
 *     one either).
 *   - Visual highlight styling — callers already use Bootstrap's
 *     `.list-group-item.active` for this (tags-tab.js and search.js did
 *     already; the newly-migrated sites now match them) so this module
 *     stays framework-agnostic and only sets the ARIA state, not a class.
 *   - "What does commit mean" — `onCommit(index, el)` is the caller's own
 *     pick logic. Every migrated call site implements it by simply
 *     re-dispatching the SAME mouse event its row already listens for
 *     (`el.click()` or a synthetic `mousedown`, matching whichever the
 *     row's own listener expects) — see each file's own comment at its
 *     `onCommit` for why this is deliberately reuse-not-reimplementation.
 *
 * @link https://www.w3.org/WAI/ARIA/apg/patterns/combobox/ (ARIA 1.2 combobox pattern this mirrors)
 */

(function () {
    'use strict';

    /**
     * handleComboboxKeydown(event, config)
     * -------------------------------------
     * ELI5: one function that reads "which arrow/key did the curator just
     * press?" and does the right combobox thing — move the highlight, jump
     * to the first/last row, pick the highlighted row, or close the list —
     * so every call site stops hand-rolling its own (and, in most of the
     * eight sites this migrates, EITHER only Escape worked OR arrow keys
     * did nothing at all).
     *
     * DETAILED: mirrors `place-search.js`'s onKeydown branch-for-branch
     * (that file is the proven reference — see its #1594 doc-comment for
     * WHY each behaviour exists, e.g. Tab must commit-not-discard, Escape
     * must stopPropagation so it doesn't also close a host Bootstrap
     * offcanvas/modal). This function is stateless: it reads the caller's
     * current state via `config.getActiveIndex()` / `config.getItems()`
     * and writes the new state back via `config.setActiveIndex()`, so
     * ownership of "where is the highlight" stays with each call site's
     * own closure variables exactly as it does today — this helper never
     * introduces a second source of truth for that state.
     *
     * @param {KeyboardEvent} event
     * @param {Object} config
     * @param {() => boolean} [config.isOpen]
     *        Is the panel/dropdown currently showing candidates? When
     *        provided and false, every key is left untouched (returns
     *        false) — matches place-search.js's own top-of-function guard
     *        so a closed panel never intercepts normal text-field editing
     *        (e.g. Home/End still move the text cursor as usual).
     * @param {() => HTMLElement[]} config.getItems
     *        Ordered, currently-rendered candidate rows (the elements
     *        `applyComboboxAria` will also tag `role="option"`).
     * @param {() => number} config.getActiveIndex
     *        Current highlight; -1 = none highlighted.
     * @param {(index: number) => void} config.setActiveIndex
     *        Store the new highlight. Called BEFORE `config.render()`,
     *        mirroring place-search.js (`highlight = …; renderPanel();`).
     * @param {() => void} [config.render]
     *        Re-paint the list to reflect the new highlight (the caller's
     *        own render/highlight function — expected to also call
     *        `applyComboboxAria` so aria-selected/aria-activedescendant
     *        stay in sync with what's on screen).
     * @param {(index: number, el: HTMLElement) => void} config.onCommit
     *        Called for Enter (only when a row is highlighted) and,
     *        unless `commitOnTab` is false, for Tab too. See the
     *        module doc-comment for why callers implement this as
     *        "replay the row's own click/mousedown listener" rather than
     *        a second copy of the pick logic.
     * @param {() => void} [config.onClose]
     *        Called for Escape, after the panel-closed ARIA state is the
     *        caller's job to apply (this helper only handles the KEY).
     * @param {boolean} [config.commitOnTab=true]
     *        Tab commits the highlighted row exactly like place-search.js
     *        (see that file's #1594 comment on why Tab must never be
     *        preventDefault()-ed — trapping Tab is its own WCAG 2.1.2
     *        failure). Set false for a call site where Tab-away should
     *        stay a plain "discard and move on" (none of the nine sites
     *        need this today; the flag exists so a future adopter isn't
     *        forced into a behaviour change it didn't ask for).
     * @param {boolean} [config.stopEscapePropagation=true]
     *        See place-search.js's Escape branch comment — needed
     *        whenever the input can live inside a Bootstrap
     *        offcanvas/modal that also listens for Escape.
     * @param {boolean} [config.scrollActiveIntoView=true]
     *        Calls `scrollIntoView({block:'nearest'})` on the new active
     *        row after `render()` returns (re-queries `getItems()` since
     *        render() may have rebuilt the DOM — same reason
     *        place-search.js's `scrollActiveIntoView()` re-looks-up the
     *        element by id rather than keeping a stale reference).
     * @returns {boolean} true if this function recognised and acted on
     *        the key (ArrowDown/ArrowUp/Home/End/Enter-with-highlight/
     *        Escape-while-open). Tab returns false even when it commits,
     *        because Tab is deliberately never "consumed" (see above) —
     *        callers that want to skip their own fallback Enter-handling
     *        (e.g. editor.js's "Enter with no highlight creates a new
     *        tag from the typed text") should check the highlight
     *        themselves for that one case, exactly as place-search.js's
     *        own Enter branch does.
     */
    function handleComboboxKeydown(event, config) {
        if (typeof config.isOpen === 'function' && !config.isOpen()) {
            return false;
        }
        var items = typeof config.getItems === 'function' ? config.getItems() : [];
        var key = event.key;

        /* Shared "move to index N, repaint, scroll" tail for the four
           navigation keys — keeps the per-key branches below to a single
           line of index arithmetic each, matching how compact
           place-search.js's own branches are. */
        function moveTo(nextIndex) {
            config.setActiveIndex(nextIndex);
            if (typeof config.render === 'function') { config.render(); }
            if (config.scrollActiveIntoView !== false) {
                var freshItems = typeof config.getItems === 'function' ? config.getItems() : items;
                var el = freshItems[nextIndex];
                if (el && typeof el.scrollIntoView === 'function') {
                    try { el.scrollIntoView({ block: 'nearest' }); } catch (_e) { /* jsdom / old browser — no-op */ }
                }
            }
        }

        if (key === 'ArrowDown') {
            if (!items.length) { return false; }
            event.preventDefault();
            moveTo(Math.min(items.length - 1, config.getActiveIndex() + 1));
            return true;
        }
        if (key === 'ArrowUp') {
            if (!items.length) { return false; }
            event.preventDefault();
            moveTo(Math.max(0, config.getActiveIndex() - 1));
            return true;
        }
        if (key === 'Home') {
            if (!items.length) { return false; }
            event.preventDefault();
            moveTo(0);
            return true;
        }
        if (key === 'End') {
            if (!items.length) { return false; }
            event.preventDefault();
            moveTo(items.length - 1);
            return true;
        }
        if (key === 'Enter') {
            var enterIdx = config.getActiveIndex();
            if (enterIdx >= 0 && enterIdx < items.length) {
                event.preventDefault();
                config.onCommit(enterIdx, items[enterIdx]);
                return true;
            }
            return false;
        }
        if (key === 'Tab') {
            /* #1594 — deliberately NEVER preventDefault(): trapping Tab
               is a keyboard trap (WCAG 2.1.2), the exact failure mode
               place-search.js's own Tab branch calls out. Commit
               synchronously so focus lands on the next field with the
               highlighted pick already applied, same as place-search.js.
               @link https://www.w3.org/WAI/WCAG21/Understanding/no-keyboard-trap.html */
            if (config.commitOnTab === false) { return false; }
            var tabIdx = config.getActiveIndex();
            if (tabIdx >= 0 && tabIdx < items.length) {
                config.onCommit(tabIdx, items[tabIdx]);
            }
            return false;
        }
        if (key === 'Escape') {
            event.preventDefault();
            /* #1594 — see place-search.js's Escape branch: without
               stopPropagation() this bubbles to a host offcanvas/modal's
               own Escape handler and closes THAT too. */
            if (config.stopEscapePropagation !== false) { event.stopPropagation(); }
            if (typeof config.onClose === 'function') { config.onClose(); }
            return true;
        }
        return false;
    }

    /**
     * applyComboboxAria(config)
     * --------------------------
     * ELI5: paints the invisible-but-essential attributes that let a
     * screen reader (or any assistive tech) know which row is highlighted
     * right now — the visible yellow-box/blue-`.active` highlight a
     * sighted mouse user sees is CSS-only and screen readers cannot see
     * CSS. Call this every time a call site (re-)renders its dropdown.
     *
     * DETAILED: idempotent and safe to call on every render (options are
     * a fresh DOM tree each time at every one of these sites, matching
     * place-search.js's own full-innerHTML-rebuild-per-render approach —
     * see this file's module doc-comment for why that's accepted rather
     * than "fixed" here). Ids are assigned only when missing so a caller
     * that already has stable ids (rare, but keeps this idempotent) isn't
     * overwritten.
     *
     * @param {Object} config
     * @param {HTMLInputElement} [config.input]
     *        The combobox text input. Gets `role="combobox"`,
     *        `aria-autocomplete="list"` (both set once, if absent),
     *        `aria-controls` (pointing at `config.panel`'s id),
     *        `aria-expanded`, and `aria-activedescendant`.
     * @param {HTMLElement} [config.panel]
     *        The listbox container. Gets `role="listbox"` and an id
     *        (generated from `idPrefix` if it doesn't already have one).
     * @param {HTMLElement[]} config.items
     *        Ordered candidate rows. Each gets `role="option"`, an id
     *        (generated from `idPrefix` + its index if missing), and an
     *        explicit `aria-selected` — "true" on the active row, "false"
     *        (never just absent) on every other row, because several
     *        screen readers only announce selection state reliably when
     *        EVERY option in the listbox carries it (the same reasoning
     *        place-search.js's renderPanel() comment gives).
     * @param {number} config.activeIndex
     *        -1 = no row highlighted.
     * @param {string} config.idPrefix
     *        Must be unique per combobox instance on the page (several of
     *        these sites can have more than one live on screen at once —
     *        e.g. two credit-person popovers in editor.js). Callers
     *        already have a natural unique string available (an element
     *        id, or their own module-scoped counter) — see each call
     *        site's usage for the value chosen.
     * @param {boolean} [config.expanded]
     *        Defaults to `config.items.length > 0`. Pass explicitly for a
     *        panel that's visible but showing a non-selectable info row
     *        (e.g. "No matching places") with an empty `items` array —
     *        matches place-search.js's `renderHint()` case.
     */
    function applyComboboxAria(config) {
        var input = config.input;
        var panel = config.panel;
        var items = config.items || [];
        var activeIndex = (typeof config.activeIndex === 'number') ? config.activeIndex : -1;
        var idPrefix = config.idPrefix || 'ihymns-combobox';

        if (input) {
            if (!input.getAttribute('role')) { input.setAttribute('role', 'combobox'); }
            if (!input.getAttribute('aria-autocomplete')) { input.setAttribute('aria-autocomplete', 'list'); }
        }
        if (panel) {
            if (!panel.id) { panel.id = idPrefix + '-listbox'; }
            if (!panel.getAttribute('role')) { panel.setAttribute('role', 'listbox'); }
            if (input && input.getAttribute('aria-controls') !== panel.id) {
                input.setAttribute('aria-controls', panel.id);
            }
        }

        items.forEach(function (el, i) {
            if (!el.id) { el.id = idPrefix + '-opt-' + i; }
            el.setAttribute('role', 'option');
            /* #1594 — explicit "false" on every inactive row; see the
               @param doc above for why implicit absence isn't enough. */
            el.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
        });

        if (input) {
            var expanded = (typeof config.expanded === 'boolean') ? config.expanded : (items.length > 0);
            input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (activeIndex >= 0 && activeIndex < items.length) {
                input.setAttribute('aria-activedescendant', items[activeIndex].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }
    }

    window.iHymnsComboboxA11y = {
        handleComboboxKeydown: handleComboboxKeydown,
        applyComboboxAria: applyComboboxAria,
    };
})();
