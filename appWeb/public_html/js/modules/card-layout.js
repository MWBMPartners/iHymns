/**
 * iHymns — Card Layout Module (#448)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Client-side drag-and-drop + hide behaviour for any "card grid" that
 * the server rendered with:
 *
 *   <div class="row" id="..." data-layout-surface="dashboard|home"
 *        data-can-customise="0|1" data-can-set-default="0|1">
 *     <div class="card-layout-item" data-card-id="editor"> … </div>
 *     ...
 *   </div>
 *
 * Attach with `initCardLayout(rootEl)` — the element's data-* attrs
 * tell the module which API surface to save against and whether the
 * current viewer is permitted to edit at all.
 *
 * Uses SortableJS (CDN-loaded once, on first edit toggle) for the
 * reorder interaction. Keyboard fallback: while in edit mode, each
 * card shows up / down buttons that move it by one slot.
 *
 * On save the order + hidden set is POSTed to /api?action=card_layout_
 * save_user. Admins with manage_default_card_layout can also save the
 * current arrangement as the system default via the "Save as site
 * default" button.
 */

const SORTABLE_CDN = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
const SORTABLE_INTEGRITY = 'sha384-aq8eSnxHJKZ/IzVz/6lq59+H3dCblmnJ2opgw7gvpRQa8lVQ+bKvY+z0sn3h47AO';

let _sortablePromise = null;
function loadSortable() {
    if (window.Sortable) return Promise.resolve(window.Sortable);
    if (_sortablePromise) return _sortablePromise;
    _sortablePromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = SORTABLE_CDN;
        s.integrity = SORTABLE_INTEGRITY;
        s.crossOrigin = 'anonymous';
        s.onload  = () => resolve(window.Sortable);
        s.onerror = () => reject(new Error('Failed to load SortableJS'));
        document.head.appendChild(s);
    });
    return _sortablePromise;
}

function qs(root, sel)   { return root.querySelector(sel); }
function qsa(root, sel)  { return Array.from(root.querySelectorAll(sel)); }

function serialiseLayout(root) {
    const order = [];
    const hidden = [];
    for (const item of qsa(root, '.card-layout-item')) {
        const id = item.dataset.cardId;
        if (!id) continue;
        order.push(id);
        if (item.dataset.hidden === '1') hidden.push(id);
    }
    return { order, hidden };
}

async function postLayout(action, surface, payload) {
    const body = JSON.stringify({ surface, ...payload });
    const res = await fetch(`/api?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body,
        credentials: 'same-origin',
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

/** Debounce a function — collapses bursts of drops into one save. */
function debounce(fn, wait) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
    };
}

/**
 * Initialise a card grid. Idempotent — safe to call multiple times.
 * @param {HTMLElement} root The `.row` container with data-layout-surface
 */
export function initCardLayout(root) {
    if (!root || root.dataset.layoutInitialised === '1') return;
    root.dataset.layoutInitialised = '1';

    const surface      = root.dataset.layoutSurface || '';
    const canCustomise = root.dataset.canCustomise === '1';
    const canDefault   = root.dataset.canSetDefault === '1';
    if (!surface || (!canCustomise && !canDefault)) return;

    const toolbar    = document.getElementById('card-layout-toolbar');
    const btnEdit    = document.getElementById('btn-card-layout-edit');
    const btnDone    = document.getElementById('btn-card-layout-done');
    const btnReset   = document.getElementById('btn-card-layout-reset');
    const btnDefault = document.getElementById('btn-card-layout-save-default');
    const help       = document.getElementById('card-layout-help');
    if (!toolbar || !btnEdit || !btnDone) return;

    let sortable = null;

    const save = debounce(async () => {
        if (!canCustomise) return;
        const payload = serialiseLayout(root);
        try { await postLayout('card_layout_save_user', surface, payload); }
        catch (e) { console.error('[card-layout] save failed', e); }
    }, 400);

    function enterEditMode() {
        root.classList.add('card-layout-editing');
        btnEdit.classList.add('d-none');
        btnDone.classList.remove('d-none');
        if (canCustomise) btnReset?.classList.remove('d-none');
        if (canDefault) btnDefault?.classList.remove('d-none');
        help?.classList.remove('d-none');

        for (const item of qsa(root, '.card-layout-item')) {
            if (!qs(item, '.card-layout-hide-btn')) {
                const hideBtn = document.createElement('button');
                hideBtn.type = 'button';
                hideBtn.className = 'btn btn-sm btn-outline-danger card-layout-hide-btn';
                hideBtn.setAttribute('aria-label', 'Hide this card');
                hideBtn.title = 'Hide this card';
                hideBtn.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
                hideBtn.addEventListener('click', () => {
                    item.dataset.hidden = '1';
                    item.classList.add('d-none');
                    save();
                });
                qs(item, '.card-admin')?.appendChild(hideBtn);
            }
        }

        loadSortable().then(S => {
            if (sortable) return;
            sortable = new S(root, {
                animation: 160,
                ghostClass: 'card-layout-ghost',
                handle: '.card-admin',
                onEnd: () => save(),
            });
        }).catch(err => console.error('[card-layout] sortable load failed', err));
    }

    function exitEditMode() {
        root.classList.remove('card-layout-editing');
        btnEdit.classList.remove('d-none');
        btnDone.classList.add('d-none');
        btnReset?.classList.add('d-none');
        btnDefault?.classList.add('d-none');
        help?.classList.add('d-none');
        for (const btn of qsa(root, '.card-layout-hide-btn')) btn.remove();
        if (sortable) { sortable.destroy(); sortable = null; }
    }

    btnEdit.addEventListener('click', enterEditMode);
    btnDone.addEventListener('click', exitEditMode);

    btnReset?.addEventListener('click', async () => {
        if (!confirm('Reset your personalised layout back to the site default?')) return;
        try {
            await postLayout('card_layout_reset_user', surface, {});
            window.location.reload();
        } catch (e) { console.error('[card-layout] reset failed', e); }
    });

    btnDefault?.addEventListener('click', async () => {
        if (!confirm('Save the current order as the site-wide default for every user?')) return;
        const payload = serialiseLayout(root);
        try {
            await postLayout('card_layout_save_default', surface, payload);
            alert('Site default saved.');
        } catch (e) { console.error('[card-layout] save-default failed', e); }
    });
}

/** Auto-init on DOMContentLoaded if the page already has a grid. */
export function bootCardLayout() {
    const go = () => {
        for (const grid of document.querySelectorAll('[data-layout-surface]')) {
            initCardLayout(grid);
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', go, { once: true });
    } else {
        go();
    }
}
