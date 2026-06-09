/* ==========================================================================
 *  credits-tab.js — the v2 Song Editor "Credits" tab (#1200, Phase 2)
 *
 *  Six credit roles (writers / composers / arrangers / adaptors / translators /
 *  artists), each a list of name rows. Each row saves ITS OWN credit atomically
 *  via the granular `credit_upsert` / `credit_delete` endpoints — no whole-song
 *  save, and (unlike the legacy editor) no way for a save-race to duplicate a
 *  credit 15× (#1178). A new row adopts the server-assigned credit id on first
 *  save so later edits UPDATE in place.
 *
 *  mountCreditsTab(container, { store, api, songId, toast }) -> teardown fn
 *  Reads the store's `credits` slice: { writers:[{id,name}], composers:[…], … }.
 * ========================================================================== */

const ROLES = [
    ['writers',     'Writers'],
    ['composers',   'Composers'],
    ['arrangers',   'Arrangers'],
    ['adaptors',    'Adaptors'],
    ['translators', 'Translators'],
    ['artists',     'Artists'],
];
const SAVE_DEBOUNCE_MS = 500;

const SEARCH_DEBOUNCE_MS = 180;

export function mountCreditsTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    const timers = new Map();

    /* One shared autocomplete dropdown, repositioned (fixed) under the active
       credit input — so the per-render container wipe never strands per-input
       dropdowns. Suggestions come from credit_search (all roles + the registry). */
    let searchTimer = null;
    const dropdown = document.createElement('div');
    dropdown.className = 'list-group shadow-sm';
    dropdown.style.position = 'fixed';
    dropdown.style.zIndex = '1056';
    dropdown.style.maxHeight = '240px';
    dropdown.style.overflowY = 'auto';
    dropdown.style.display = 'none';
    document.body.appendChild(dropdown);

    function hideDropdown() { dropdown.style.display = 'none'; dropdown.innerHTML = ''; }
    function positionDropdown(input) {
        const r = input.getBoundingClientRect();
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = r.bottom + 'px';
        dropdown.style.minWidth = r.width + 'px';
    }
    function showSuggestions(input, role, credit, suggestions) {
        dropdown.innerHTML = '';
        const existing = new Set((getCredits()[role] || []).map((c) => String(c.name || '').toLowerCase()));
        let shown = 0;
        suggestions.forEach((s) => {
            if (existing.has(String(s.name || '').toLowerCase())) { return; }   // already credited in this role
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1';
            const nm = document.createElement('span');
            nm.textContent = s.name;
            const badge = document.createElement('span');
            badge.className = 'badge bg-secondary rounded-pill';
            badge.textContent = String(s.usage || 0);
            badge.title = (s.usage || 0) + ' song(s)';
            item.append(nm, badge);
            item.addEventListener('mousedown', (ev) => {
                ev.preventDefault();
                input.value = s.name;
                credit.name = s.name;
                hideDropdown();
                saveCredit(role, credit);
            });
            dropdown.appendChild(item);
            shown++;
        });
        if (shown === 0) { hideDropdown(); return; }
        positionDropdown(input);
        dropdown.style.display = '';
    }
    function runSearch(input, role, credit) {
        const q = input.value.trim();
        if (q === '') { hideDropdown(); return; }
        api.searchCredits(q, 'any')
            .then((res) => showSuggestions(input, role, credit, res.suggestions || []))
            .catch(() => { /* autocomplete is best-effort */ });
    }

    function getCredits() {
        const c = store.get('credits') || {};
        ROLES.forEach(([role]) => { if (!Array.isArray(c[role])) { c[role] = []; } });
        return c;
    }

    async function saveCredit(role, credit) {
        try {
            const res = await api.upsertCredit(songId, role, { id: credit.id || 0, name: credit.name });
            if (!credit.id && res.creditId) { credit.id = res.creditId; }
        } catch (e) {
            toast('Could not save credit: ' + e.message, 'danger');
        }
    }
    function debouncedSave(role, credit) {
        const key = role + ':' + (credit._key);
        if (timers.has(key)) { clearTimeout(timers.get(key)); }
        timers.set(key, setTimeout(() => saveCredit(role, credit), SAVE_DEBOUNCE_MS));
    }

    async function removeCredit(role, credit) {
        const credits = getCredits();
        credits[role] = credits[role].filter((c) => c !== credit);
        store.set('credits', credits);   // re-renders
        if (credit.id) {
            try { await api.deleteCredit(songId, role, credit.id); }
            catch (e) { toast('Could not delete credit: ' + e.message, 'danger'); }
        }
    }

    function addCredit(role) {
        const credits = getCredits();
        credits[role].push({ _key: 'k' + Date.now().toString(36) + credits[role].length, id: 0, name: '' });
        store.set('credits', credits);   // re-renders; focus handled below
    }

    function render() {
        const credits = getCredits();
        if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
        hideDropdown();
        container.innerHTML = '';
        const row = document.createElement('div');
        row.className = 'row g-3';

        ROLES.forEach(([role, label]) => {
            const col = document.createElement('div');
            col.className = 'col-12 col-md-6';
            const card = document.createElement('div');
            card.className = 'card h-100';
            const body = document.createElement('div');
            body.className = 'card-body';

            const h = document.createElement('h3');
            h.className = 'h6 mb-2';
            h.textContent = label;
            body.appendChild(h);

            credits[role].forEach((credit) => {
                if (!credit._key) { credit._key = 'k' + (credit.id || 'n') + Math.random().toString(36).slice(2, 6); }
                const group = document.createElement('div');
                group.className = 'input-group input-group-sm mb-2';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
                input.placeholder = 'Name';
                input.value = credit.name || '';
                input.addEventListener('input', () => {
                    credit.name = input.value;
                    if (input.value.trim() !== '') { debouncedSave(role, credit); }
                    if (searchTimer) { clearTimeout(searchTimer); }
                    searchTimer = setTimeout(() => runSearch(input, role, credit), SEARCH_DEBOUNCE_MS);
                });
                input.addEventListener('blur', () => { setTimeout(hideDropdown, 150); });
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn btn-outline-danger';
                del.title = 'Remove';
                del.innerHTML = '<i class="bi bi-x-lg"></i>';
                del.addEventListener('click', () => removeCredit(role, credit));
                group.append(input, del);
                body.appendChild(group);
            });

            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-sm btn-outline-primary';
            add.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add ' + label.replace(/s$/, '');
            add.addEventListener('click', () => addCredit(role));
            body.appendChild(add);

            card.appendChild(body);
            col.appendChild(card);
            row.appendChild(col);
        });

        container.appendChild(row);
    }

    const off = store.subscribe('credits', render);
    render();

    return function teardown() {
        off();
        timers.forEach((t) => clearTimeout(t));
        timers.clear();
        if (searchTimer) { clearTimeout(searchTimer); }
        dropdown.remove();
        container.innerHTML = '';
    };
}
