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
 *  mountStructureTab(container, { store, api, songId, toast }) -> teardown fn
 *    store : reactive store with a `components` slice (array of component rows)
 *    api   : editorApi from api-client.js
 *    songId: the server SongId
 *    toast : (message, type) => void   (optional)
 * ========================================================================== */

const COMPONENT_TYPES = ['verse', 'chorus', 'refrain', 'bridge', 'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude'];
const SAVE_DEBOUNCE_MS = 500;

export function mountStructureTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};

    /* Per-component debounce timers for lyric/chord saves. */
    const saveTimers = new Map();

    function debouncedSave(comp) {
        const key = comp._key;
        if (saveTimers.has(key)) { clearTimeout(saveTimers.get(key)); }
        saveTimers.set(key, setTimeout(() => { saveComponent(comp); }, SAVE_DEBOUNCE_MS));
    }

    /** Persist one component (create or update) atomically. On a CREATE, adopt
     *  the server-assigned componentId so later edits UPDATE in place. */
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
            };
            const res = await api.upsertComponent(songId, payload);
            if (!comp.id && res.componentId) { comp.id = res.componentId; }
        } catch (e) {
            toast('Could not save section: ' + e.message, 'danger');
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
        COMPONENT_TYPES.forEach((t) => {
            const o = document.createElement('option');
            o.value = t;
            o.textContent = t.replace(/^\w/, (c) => c.toUpperCase());
            if (t === comp.type) { o.selected = true; }
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

    async function removeComponent(comp) {
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
        container.innerHTML = '';
    };
}
