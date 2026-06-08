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

export function mountCreditsTab(container, opts) {
    const { store, api, songId } = opts;
    const toast = opts.toast || function () {};
    const timers = new Map();

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
                });
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
        container.innerHTML = '';
    };
}
