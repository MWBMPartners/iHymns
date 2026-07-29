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

import { apiFetch } from '../utils/api-client.js';

const SORTABLE_CDN = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';

let _sortablePromise = null;
function loadSortable() {
    if (window.Sortable) return Promise.resolve(window.Sortable);
    if (_sortablePromise) return _sortablePromise;
    /* SRI is intentionally NOT set here — the previous placeholder hash
       silently blocked the script from executing, so the whole reorder
       feature was dead on arrival. When we pin the CDN version we can
       add a real integrity attribute; until then, `crossorigin` alone
       is acceptable for a client-side-only, non-auth-carrying library. */
    _sortablePromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = SORTABLE_CDN;
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
    const res = await apiFetch(`/api?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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

    const surface      = root.dataset.layoutSurface || '';
    const canCustomise = root.dataset.canCustomise === '1';
    const canDefault   = root.dataset.canSetDefault === '1';
    if (!surface || (!canCustomise && !canDefault)) return;
    /* Mark initialised only once we're actually wiring — so a premature
       call before the surface's permissions are known (e.g. bootCardLayout
       firing before applyCardLayout hydrates data-can-* onto the cached
       home grid) doesn't consume the guard and block the real wiring. */
    root.dataset.layoutInitialised = '1';

    /* Drag handle / hide-button host. Admin cards use their `.card-admin`
       header; the public home opts into a dedicated `.card-layout-handle`
       strip via data-layout-handle. */
    const handleSel = root.dataset.layoutHandle || '.card-admin';

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
                qs(item, handleSel)?.appendChild(hideBtn);
            }
        }

        loadSortable().then(S => {
            if (sortable) return;
            sortable = new S(root, {
                animation: 160,
                ghostClass: 'card-layout-ghost',
                handle: handleSel,
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

/**
 * Client-side mirror of cardLayoutMerge() (includes/card_layout.php):
 * saved.order filtered to the baseline (in saved order), then any
 * baseline IDs not yet placed appended; hidden filtered to the baseline.
 */
function mergeLayout(baseline, saved) {
    const baseSet = new Set(baseline);
    const order = [];
    const seen = new Set();
    for (const id of (saved.order || [])) {
        if (baseSet.has(id) && !seen.has(id)) { order.push(id); seen.add(id); }
    }
    for (const id of baseline) {
        if (!seen.has(id)) { order.push(id); seen.add(id); }
    }
    const hidden = [];
    for (const id of (saved.hidden || [])) {
        if (baseSet.has(id)) hidden.push(id);
    }
    return { order, hidden };
}

/**
 * Hydrate a card grid from the viewer's saved layout and apply it to the
 * DOM (reorder + hide), then wire edit-mode. For surfaces served as a
 * SHARED-CACHE fragment (the public home, #448) the server cannot emit a
 * per-user order without breaking the shared ETag, so personalisation is
 * applied client-side here instead. Idempotent per grid.
 *
 * Auth rides the same-origin `ihymns_auth` cookie (getAuthBearerToken's
 * cookie fallback), so a signed-in PWA user is resolved without the page
 * having to attach a bearer header. Signed-out / offline → the request
 * fails or returns empty and the server-rendered default order stands.
 *
 * @param {HTMLElement} root The `[data-layout-surface]` container.
 */
export async function applyCardLayout(root) {
    if (!root || root.dataset.layoutHydrated === '1') return;
    root.dataset.layoutHydrated = '1';

    const surface = root.dataset.layoutSurface || '';
    if (!surface) return;

    /* Baseline = the server-rendered DOM order (the template default). */
    const items = qsa(root, '.card-layout-item');
    const baseline = items.map(i => i.dataset.cardId).filter(Boolean);
    if (!baseline.length) return;

    let data = null;
    try {
        const res = await apiFetch(
            `/api?action=card_layout_get&surface=${encodeURIComponent(surface)}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (res.ok) data = await res.json();
    } catch {
        /* Offline / signed-out — leave the default order untouched. */
    }

    if (data) {
        const def = data.default  || { order: [], hidden: [] };
        const ovr = data.override || { order: [], hidden: [] };
        /* Override fully replaces the default when present (mirrors the
           server resolver), else fall back to the system default. */
        const saved = ((ovr.order && ovr.order.length) || (ovr.hidden && ovr.hidden.length))
            ? ovr : def;
        const eff = mergeLayout(baseline, saved);

        /* Reorder: re-append each item in the resolved order (moves the
           existing nodes; the grid holds only card-layout-items). */
        const byId = new Map(items.map(i => [i.dataset.cardId, i]));
        for (const id of eff.order) {
            const node = byId.get(id);
            if (node) root.appendChild(node);
        }

        /* Hide via Bootstrap's .d-none — its display:none!important beats a
           loader that later reveals a section with an inline display, so a
           user-hidden section stays hidden. */
        const hideSet = new Set(eff.hidden);
        for (const i of items) {
            if (hideSet.has(i.dataset.cardId)) {
                i.dataset.hidden = '1';
                i.classList.add('d-none');
            }
        }

        root.dataset.canCustomise  = data.canCustomiseOwn ? '1' : '0';
        root.dataset.canSetDefault = data.canSetDefault   ? '1' : '0';

        /* Reveal the toolbar only when the viewer may actually edit — no
           dead "Customise" button for the logged-out majority. */
        if (data.canCustomiseOwn || data.canSetDefault) {
            document.getElementById('card-layout-toolbar')?.classList.remove('d-none');
        }
    }

    initCardLayout(root);
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
