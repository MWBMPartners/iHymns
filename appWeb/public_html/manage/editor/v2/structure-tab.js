/* ==========================================================================
 *  structure-tab.js — the v2 Song Editor "Structure" tab (#1200, Phase 1)
 *
 *  The components + arrangement editor — the worst pain in the old editor (a
 *  full rebuild of every card on every keystroke/delete). Here, each component
 *  card is built ONCE and updates itself; only STRUCTURAL changes (add / delete
 *  / reorder) re-render the list, driven off the store's `components` slice.
 *  Every edit is an atomic granular save to api2.php — no whole-song save, no
 *  race. A failed save throws → a real error toast, local state untouched.
 *
 *  mountStructureTab(container, { store, api, songId, toast, registerFlush }) -> teardown fn
 *    store        : reactive store with a `components` slice (array of component rows)
 *    api          : editorApi from api-client.js
 *    songId       : the server SongId
 *    toast        : (message, type) => void   (optional)
 *    registerFlush: (fn) => void   (optional; #1846 — hands the shell a "flush
 *                   my pending debounced saves now" function for its manual
 *                   Save button — see flushPending() below)
 *
 *  #1627 items 1+3 — chords UI + per-line enrichment panel. Both extend
 *  buildCard(), which is why they land in one PR: a chords textarea (ABOVE
 *  the enrichment panel, mirroring v1's card layout order) ported from v1's
 *  componentChordsToText(), and enrichment-panel.js's buildEnrichmentPanel()
 *  for per-line language/translation/annotation. See enrichment-panel.js's
 *  own file header for the full per-line-enrichment design; see
 *  componentChordsToText() below + saveComponent()'s chords branch for the
 *  chords clear-semantics trap (empty ARRAY clears, `null` PRESERVES).
 * ========================================================================== */

import { buildEnrichmentPanel } from './enrichment-panel.js';

/* #1869 (epic #1863, CLAUDE.md rule #43's LAST picker item — registry
   SOURCING, not a typeahead). This was the whole section-type vocabulary
   until #1869: 10 types, hand-typed, requiring a code change + redeploy to
   grow. It now survives ONLY as the MINIMAL BUILT-IN FALLBACK — used when
   editor2.php's bootstrap payload (window._iHymnsSongPartTypes, sourced from
   the tblSongPartTypes registry via includes/song_part_type_helpers.php) is
   missing or empty, which happens on an un-migrated install (rule #19/#20:
   the migration is web-run, not automatic on deploy) or a test harness that
   never set the global. NEVER delete this const — it is what keeps the
   Structure tab usable on a database that hasn't run the #1138 migration. */
const COMPONENT_TYPES = ['verse', 'chorus', 'refrain', 'bridge', 'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude'];

/**
 * resolvePartTypes() — the section-type vocabulary the "Structure" tab's type
 * <select> renders (#1869). PREFERS the live `tblSongPartTypes` registry, shipped
 * by editor2.php as `window._iHymnsSongPartTypes` (a list of `{slug, name}`,
 * already ordered by the registry's own SortOrder — see
 * includes/song_part_type_helpers.php::songPartTypesForPicker()); FALLS BACK to
 * the hardcoded COMPONENT_TYPES list above only when that global is absent, not
 * an array, or empty (an un-migrated `tblSongPartTypes`, or this module running
 * outside editor2.php, e.g. a future standalone test harness).
 *
 * ELI5: normally the dropdown's choices come from the database, so a curator can
 * add "Vamp" or "Ad-Lib" without anyone touching this file — but if the database
 * hasn't been updated yet, the dropdown still shows the original 10 choices
 * instead of going blank.
 *
 * Computed ONCE at module-evaluation time (top-level `const` below), matching
 * every other classic-global vocab this page ships (window._iHymnsLinkTypes,
 * window._iHymnsRecordingIdTypes, window._iHymnsLicenceTypes — external-ids-panel.js
 * / rights-panel.js read theirs the same "read once, module scope" way).
 *
 * @returns {Array<{value:string, label:string}>}
 */
function resolvePartTypes() {
    const served = (typeof window !== 'undefined') ? window._iHymnsSongPartTypes : undefined;
    if (Array.isArray(served) && served.length > 0) {
        const mapped = served
            .filter((t) => t && typeof t.slug === 'string' && t.slug !== '')
            .map((t) => ({ value: t.slug, label: (typeof t.name === 'string' && t.name !== '') ? t.name : t.slug }));
        if (mapped.length > 0) { return mapped; }
    }
    return COMPONENT_TYPES.map((t) => ({ value: t, label: t.replace(/^\w/, (c) => c.toUpperCase()) }));
}

/* The resolved vocabulary — a list of {value, label} pairs, DB-sourced when
   available. buildCard() below is the ONE consumer (the type <select>). */
const PART_TYPES = resolvePartTypes();

const SAVE_DEBOUNCE_MS = 500;

/**
 * componentChordsToText(comp) — render a component's chords back into the
 * editable textarea: one line per lyric line, chords space-separated. A
 * freshly-LOADED component's chords are arrays per line (["C","G"]); a
 * component the user has already typed into this session holds a plain
 * per-line string ("C G") because the textarea's own input handler writes
 * strings, not arrays — both render identically. Ported from v1
 * (editor.js's componentChordsToText, ~1275) unchanged in behaviour. (#1094,
 * #1627 item 1)
 * @param {{chords?:Array}} comp
 * @returns {string}
 */
function componentChordsToText(comp) {
    if (!Array.isArray(comp.chords)) { return ''; }
    return comp.chords.map((c) => {
        if (Array.isArray(c)) { return c.join(' '); }
        return (c == null) ? '' : String(c);
    }).join('\n');
}

export function mountStructureTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    /* #1846 — hand the shell a "flush me now" function once, at mount time
       (a plain injected callback, not a DOM event — rule #35/#1581). Defaults
       to a no-op so the tab still mounts standalone in a test harness that
       doesn't pass registerFlush. */
    const registerFlush = opts.registerFlush || function () {};

    /* Per-component debounce timers for lyric/chord saves. */
    const saveTimers = new Map();
    /* #1846 — comp._key -> the pending comp, same key space as saveTimers
       above. Lets the shell's manual Save button fire a pending debounced
       write early via flushPending() below. Structural ops (move/remove/add)
       call saveComponent()/the API directly and never touch this map — only
       a lyric/chord textarea's debouncedSave() does. */
    const pendingSaves = new Map();

    function debouncedSave(comp) {
        const key = comp._key;
        if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); }
        pendingSaves.set(key, comp);
        saveTimers.set(key, setTimeout(() => { pendingSaves.delete(key); saveComponent(comp); }, SAVE_DEBOUNCE_MS));
    }

    /**
     * #1846 — the shell's manual Save button's hook into this tab: cancel
     * every pending per-component debounce timer and fire each recorded
     * component's save immediately instead of waiting out SAVE_DEBOUNCE_MS.
     *
     * ELI5: if you were mid-typing a lyric or chord line, clicking Save
     * shouldn't make you wait for the pause-timer — this sends it right now.
     *
     * @returns {Promise<number>} Resolves once every flushed save has
     *   settled to the COUNT of saves that FAILED (0 = all ok) — the shell's
     *   Save button (#1846/#1851) sums this across every tab to decide
     *   between "All changes saved." and a real failure report. saveComponent()
     *   already catches its own rejection into a toast and resolves a
     *   boolean rather than rethrowing, so the `.catch(() => false)` here is
     *   belt-and-braces, not the normal path — this function itself must
     *   still never reject.
     */
    function flushPending() {
        saveTimers.forEach((t) => clearTimeout(t));
        saveTimers.clear();
        const proms = [];
        pendingSaves.forEach((comp) => {
            proms.push(Promise.resolve(saveComponent(comp)).catch(() => false));
        });
        pendingSaves.clear();
        return Promise.all(proms).then((results) => results.reduce((n, ok) => n + (ok === false ? 1 : 0), 0));
    }
    registerFlush(flushPending);

    /** Persist one component (create or update) atomically. On a CREATE, adopt
     *  the server-assigned componentId so later edits UPDATE in place.
     *  #1846/#1851 — resolves TRUE on success, FALSE on failure (never
     *  rejects); flushPending() below sums the FALSEs into a failure count
     *  for the shell's Save-button outcome report. */
    async function saveComponent(comp) {
        try {
            const payload = {
                id:        comp.id || 0,
                type:      comp.type,
                number:    comp.number,
                sortOrder: comp.sortOrder,
                lines:     comp.lines,
                chords:    comp.chords || null,
                language:  comp.language || null,
                /* #1627 item 3 — per-line language OVERRIDES (comp.languages,
                   an array parallel to lines), distinct from the single
                   per-SECTION `language` above. `comp.languages || null`:
                   once enrichment-panel.js's setLineLang() has turned this
                   into an array it is NEVER null again (see that file's
                   clear-semantics note), so this only ever sends `null` when
                   no per-line override has ever been set on this component —
                   a true no-op, not a clear attempt. */
                languages: comp.languages || null,
            };
            const res = await api.upsertComponent(songId, payload);
            if (!comp.id && res.componentId) { comp.id = res.componentId; }
            return true;
        } catch (e) {
            toast('Could not save section: ' + e.message, 'danger');
            return false;
        }
    }

    /** Build one component card. Wires its own inputs; no list re-render on edit. */
    function buildCard(comp, index, total) {
        const card = document.createElement('div');
        card.className = 'card mb-3 component-card';
        card.dataset.key = comp._key;

        const header = document.createElement('div');
        header.className = 'card-header d-flex align-items-center gap-2 flex-wrap';

        const label = document.createElement('strong');
        label.className = 'me-auto';
        label.textContent = (comp.type || 'verse').replace(/^\w/, (c) => c.toUpperCase()) + (comp.number ? ' ' + comp.number : '');

        const typeSel = document.createElement('select');
        typeSel.className = 'form-select form-select-sm';
        typeSel.style.width = '150px';
        /* #1869 — PART_TYPES (module scope, resolved once above), not the raw
           COMPONENT_TYPES fallback const directly: DB-sourced when
           window._iHymnsSongPartTypes was served, hardcoded-fallback
           otherwise. If the currently-loaded component's type isn't in the
           resolved list (e.g. free-text data older than the registry), no
           option is marked selected — the same pre-existing behaviour as
           before #1869, unchanged here. */
        PART_TYPES.forEach((t) => {
            const o = document.createElement('option');
            o.value = t.value;
            o.textContent = t.label;
            if (t.value === comp.type) { o.selected = true; }
            typeSel.appendChild(o);
        });
        typeSel.addEventListener('change', () => {
            comp.type = typeSel.value;
            label.textContent = comp.type.replace(/^\w/, (c) => c.toUpperCase()) + (comp.number ? ' ' + comp.number : '');
            saveComponent(comp);
        });

        const numInput = document.createElement('input');
        numInput.type = 'number';
        numInput.min = '0';
        numInput.className = 'form-control form-control-sm';
        numInput.style.width = '90px';
        numInput.placeholder = '#';
        numInput.value = comp.number ? String(comp.number) : '';
        numInput.addEventListener('change', () => {
            comp.number = parseInt(numInput.value, 10) || 0;
            label.textContent = (comp.type || 'verse').replace(/^\w/, (c) => c.toUpperCase()) + (comp.number ? ' ' + comp.number : '');
            saveComponent(comp);
        });

        /* #1206 — per-section language override (BCP 47, incl. script subtag).
           Blank = inherit the song language. The public render emits this as
           lang=… + dir on the verse (#858/#1205); component_upsert already
           persists it. Loose client validation (non-blocking) flags a malformed
           tag without preventing the save. */
        const langInput = document.createElement('input');
        langInput.type = 'text';
        langInput.className = 'form-control form-control-sm';
        langInput.style.width = '130px';
        langInput.placeholder = 'lang (e.g. zh-Hans)';
        langInput.title = 'Per-section language override — BCP 47, including script where relevant (en, sw, zh-Hans, sr-Cyrl, ja-Latn). Blank = inherit the song language.';
        langInput.setAttribute('aria-label', 'Section language (BCP 47)');
        langInput.value = comp.language || '';
        langInput.addEventListener('change', () => {
            const v = langInput.value.trim();
            comp.language = v !== '' ? v : null;
            langInput.classList.toggle('is-invalid', v !== '' && !/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/.test(v));
            saveComponent(comp);
        });

        const btnUp = iconBtn('bi-arrow-up', 'Move up', index === 0, () => move(index, -1));
        const btnDown = iconBtn('bi-arrow-down', 'Move down', index === total - 1, () => move(index, 1));
        const btnDel = iconBtn('bi-x-lg', 'Remove section', false, () => removeComponent(comp));
        btnDel.classList.add('text-danger');

        const btns = document.createElement('span');
        btns.className = 'btn-group btn-group-sm';
        btns.append(btnUp, btnDown, btnDel);

        header.append(label, typeSel, numInput, langInput, btns);

        const body = document.createElement('div');
        body.className = 'card-body';
        const ta = document.createElement('textarea');
        ta.className = 'form-control component-lyrics';
        ta.rows = Math.max(2, (comp.lines || []).length);
        ta.placeholder = 'Enter lyrics here…';
        ta.value = Array.isArray(comp.lines) ? comp.lines.join('\n') : '';
        ta.addEventListener('input', () => {
            comp.lines = ta.value.split('\n');
            debouncedSave(comp);   // incremental — NO list re-render
        });
        body.appendChild(ta);

        /* #1627 item 1 — optional manual per-line chords (collapsible),
           ABOVE the enrichment panel (mirrors v1's card layout order). One
           line of chords per lyric line; persisted via saveComponent()'s
           existing `chords: comp.chords || null` (above). Auto-opened when
           the loaded component already carries chords, same "hasChords"
           test as v1's chordsBox.style.display. */
        const chordsWrap = document.createElement('div');
        chordsWrap.className = 'mt-2';
        const hasChords = Array.isArray(comp.chords) && comp.chords.some((c) => c && (Array.isArray(c) ? c.length : String(c).trim()));
        const chordsToggle = document.createElement('button');
        chordsToggle.type = 'button';
        chordsToggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
        chordsToggle.innerHTML = '<i class="bi bi-music-note-beamed me-1"></i>Chords';
        const chordsBox = document.createElement('div');
        chordsBox.className = 'mt-1';
        chordsBox.style.display = hasChords ? '' : 'none';
        const chordsArea = document.createElement('textarea');
        chordsArea.className = 'form-control form-control-sm component-chords font-monospace';
        chordsArea.rows = 2;
        chordsArea.placeholder = 'One line of chords per lyric line, e.g.  C    G    Am';
        chordsArea.value = componentChordsToText(comp);
        chordsArea.addEventListener('input', () => {
            const rows = chordsArea.value.split('\n').map((l) => l.trim());
            /* CLEAR-SEMANTICS TRAP, opposite direction from the per-line
               language one in enrichment-panel.js: component_upsert PRESERVES
               the stored chords when the `chords` key is absent/null
               (isset() gate) but CLEARS them when it's an empty array. So an
               all-blank textarea must become `[]`, never a same-length array
               of '' — sending `['','','']` would "succeed" but silently
               leave the old chord symbols in place, and `null` would too.
               `[] || null` in saveComponent() stays `[]` (a truthy array),
               so this is the one line that has to get it right. */
            comp.chords = rows.some((r) => r !== '') ? rows : [];
            debouncedSave(comp);   // incremental — NO list re-render
        });
        chordsToggle.addEventListener('click', () => {
            chordsBox.style.display = (chordsBox.style.display === 'none') ? '' : 'none';
            if (chordsBox.style.display !== 'none') { chordsArea.focus(); }
        });
        const chordsHint = document.createElement('div');
        chordsHint.className = 'form-text small';
        chordsHint.textContent = 'Optional. Each chord line lines up with the lyric line above it.';
        chordsBox.append(chordsArea, chordsHint);
        chordsWrap.append(chordsToggle, chordsBox);
        body.appendChild(chordsWrap);

        /* #1627 item 3 — per-line language / translation / annotation panel. */
        body.appendChild(buildEnrichmentPanel(comp, { store, api, songId, toast, saveComponent }));

        card.append(header, body);
        return card;
    }

    function iconBtn(icon, title, disabled, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-secondary';
        b.title = title;
        b.disabled = !!disabled;
        b.innerHTML = '<i class="bi ' + icon + '"></i>';
        b.addEventListener('click', onClick);
        return b;
    }

    /* ---- structural ops (these DO re-render via the store) ---- */

    async function move(index, dir) {
        const comps = store.get('components').slice();
        const target = index + dir;
        if (target < 0 || target >= comps.length) { return; }
        const tmp = comps[index]; comps[index] = comps[target]; comps[target] = tmp;
        comps.forEach((c, i) => { c.sortOrder = i; });
        store.set('components', comps);   // re-renders
        try {
            await api.reorderComponents(songId, comps.filter((c) => c.id).map((c) => c.id));
        } catch (e) { toast('Could not reorder: ' + e.message, 'danger'); }
    }

    /**
     * #1851 — cancel this component's pending debounced lyric/chord autosave
     * FIRST. Without this, an edit-then-immediate-delete (within
     * SAVE_DEBOUNCE_MS) left the debounce timer armed: it fired
     * component_upsert AFTER deleteComponent had already removed the row,
     * and api2.php's upsert re-APPENDS a component whose id no longer
     * exists — the section resurrects itself right after being deleted.
     * Same key space as debouncedSave() above (comp._key).
     */
    async function removeComponent(comp) {
        const key = comp._key;
        if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); saveTimers.delete(key); }
        pendingSaves.delete(key);
        const comps = store.get('components').filter((c) => c !== comp);
        comps.forEach((c, i) => { c.sortOrder = i; });
        store.set('components', comps);   // re-renders immediately
        if (comp.id) {
            try { await api.deleteComponent(songId, comp.id); }
            catch (e) { toast('Could not delete section: ' + e.message, 'danger'); }
        }
    }

    async function addComponent() {
        const comps = store.get('components').slice();
        const comp = { _key: 'c' + comps.length + '_' + comps.reduce((a, c) => a + (c.id || 0), 0), id: 0, type: 'verse', number: comps.length + 1, sortOrder: comps.length, lines: [''], chords: null, language: null };
        comps.push(comp);
        store.set('components', comps);   // re-renders
        await saveComponent(comp);        // create → adopts the server id
    }

    /* ---- render ---- */

    function render() {
        container.innerHTML = '';
        const comps = store.get('components') || [];
        comps.forEach((c, i) => {
            if (!c._key) { c._key = 'c' + i + '_' + (c.id || 'new'); }
            container.appendChild(buildCard(c, i, comps.length));
        });

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-sm btn-outline-primary';
        addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add section';
        addBtn.addEventListener('click', addComponent);
        container.appendChild(addBtn);
    }

    const off = store.subscribe('components', render);
    render();

    return function teardown() {
        off();
        saveTimers.forEach((t) => clearTimeout(t));
        saveTimers.clear();
        pendingSaves.clear();   // #1846 — no lingering references once the tab is gone
        container.innerHTML = '';
    };
}
