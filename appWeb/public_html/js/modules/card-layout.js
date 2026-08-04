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
 * reorder interaction. WCAG 2.2 2.5.7 (Dragging Movements) + 2.1.1
 * (Keyboard) fallback: while in edit mode, each card also grows
 * "Move up" / "Move down" buttons (mirrors the pattern already used
 * for set-list reordering in setlist.js) so nobody has to be able to
 * drag with a pointer to reorder a card. This comment used to claim
 * that fallback existed when only the hide (×) button did — see #1151.
 *
 * On save the order + hidden set is POSTed to /api?action=card_layout_
 * save_user. Admins with manage_default_card_layout can also save the
 * current arrangement as the system default via the "Save as site
 * default" button.
 */

import { apiFetch } from '../utils/api-client.js';

/* SortableJS load parameters (#1647).
 *
 * These MUST stay in step with APP_CONFIG['libraries']['sortablejs'] in
 * includes/config.php, which is the registry download-vendor.sh reads. This
 * module cannot read PHP config, so the values are duplicated here and
 * tests/test-vendor-sri.js asserts the two copies agree — "two lists that must
 * match with nothing enforcing it" is a failure mode this codebase has hit
 * repeatedly, so the guard is the tie. */
const SORTABLE_VERSION = '1.15.2';
const SORTABLE_CDN = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
const SORTABLE_SRI = 'sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM';
const SORTABLE_LOCAL = '/vendor/sortablejs/Sortable.min.js';

/**
 * Inject one <script> and resolve when it has run.
 *
 * @param {string}  src        URL to load.
 * @param {?string} integrity  SRI hash, or null for the same-origin fallback.
 * @returns {Promise<any>} resolves with window.Sortable
 */
function injectSortable(src, integrity) {
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        if (integrity) {
            s.integrity = integrity;
            /* SRI on a cross-origin script REQUIRES crossorigin, or the browser
               cannot read the body to hash it and blocks the load outright.
               https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity */
            s.crossOrigin = 'anonymous';
        }
        s.onload = () => (window.Sortable
            ? resolve(window.Sortable)
            : reject(new Error('SortableJS loaded but did not define window.Sortable')));
        s.onerror = () => reject(new Error(`Failed to load SortableJS from ${src}`));
        document.head.appendChild(s);
    });
}

let _sortablePromise = null;

/**
 * Lazily load SortableJS: pinned CDN with SRI, falling back to the vendored copy.
 *
 * ELI5: fetch the drag-and-drop library from the fast public copy, but check it
 * hasn't been tampered with — and if that check fails, use our own copy instead
 * of giving up.
 *
 * WHY THE FALLBACK IS THE POINT (#1647)
 * -------------------------------------
 * This load previously had NO integrity attribute, with a comment saying SRI
 * would be added "when we pin the CDN version" — while the URL was already
 * pinned at @1.15.2. The stated precondition had been met; the follow-through
 * hadn't. The real history is in the comment it replaced: a PLACEHOLDER hash
 * was committed once, silently blocked the script, and killed the entire
 * reorder feature, so SRI was removed rather than computed correctly.
 *
 * That is why the fallback matters more than the hash. With only a CDN load, a
 * wrong or stale hash is indistinguishable from a dead feature — which is
 * exactly what happened, and exactly what would discourage the next person from
 * re-adding SRI. With the vendored copy behind it, a hash mismatch degrades to
 * a working same-origin load. The security control becomes safe to keep.
 *
 * The fallback needs no integrity of its own: it is same-origin, so it is
 * covered by the CSP's `self` and by whatever trust the origin already has.
 * Note the vendored file is fetched at deploy time by tools/download-vendor.sh,
 * which does not itself verify a hash — worth knowing, and tracked separately.
 *
 * Without this, a jsDelivr compromise executes arbitrary JavaScript in the
 * PUBLIC origin for any signed-in user who opens card-layout edit mode. The
 * ihymns_auth cookie is HttpOnly so the token is not readable, but same-origin
 * API calls carry it automatically — injected script could drive every
 * state-changing endpoint as the victim, including an admin on the dashboard.
 * Exploitability is low (it needs a supply-chain event); blast radius is the
 * whole origin.
 *
 * @returns {Promise<any>} resolves with window.Sortable
 */
function loadSortable() {
    if (window.Sortable) return Promise.resolve(window.Sortable);
    if (_sortablePromise) return _sortablePromise;

    _sortablePromise = injectSortable(SORTABLE_CDN, SORTABLE_SRI)
        .catch((cdnErr) => {
            /* Reached on a genuine network failure, on a CSP refusal, and on an
               SRI mismatch — the browser reports all three as an error event on
               the element, with no way to tell them apart from script. Logged
               rather than swallowed: under /manage there is no client error
               monitor (#1587), so a silent fallback would hide a real
               supply-chain signal behind a feature that merely kept working. */
            console.warn(
                `[card-layout] SortableJS ${SORTABLE_VERSION} failed from the CDN `
                + `(network, CSP, or SRI mismatch) — falling back to ${SORTABLE_LOCAL}.`,
                cdnErr
            );
            return injectSortable(SORTABLE_LOCAL, null);
        });

    /* Do not cache a rejection: if BOTH sources fail, the next attempt should
       retry rather than replay the failure forever. */
    _sortablePromise = _sortablePromise.catch((err) => {
        _sortablePromise = null;
        throw err;
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
 * Re-derive the disabled state of every Move up / Move down button from
 * the CURRENT DOM order, so the boundary buttons stay correct after a
 * drag-drop, a button move, or a hide. Cheap — the grids this runs
 * against are a handful of items — so it is simplest to just recompute
 * on every change rather than track indices by hand.
 * @param {HTMLElement} root
 */
function refreshMoveButtons(root) {
    const items = qsa(root, '.card-layout-item');
    items.forEach((item, idx) => {
        const up = qs(item, '.card-layout-move-up-btn');
        const down = qs(item, '.card-layout-move-down-btn');
        if (up) up.disabled = (idx === 0);
        if (down) down.disabled = (idx === items.length - 1);
    });
}

/**
 * Move one card-layout-item by one slot in the DOM (the keyboard/switch
 * equivalent of dragging it past its neighbour). No-ops silently at
 * either end — callers keep the buttons disabled there, but a stray
 * event (e.g. a double click racing the disabled-state update) should
 * never throw.
 * @param {HTMLElement} root
 * @param {HTMLElement} item
 * @param {number} delta -1 to move up, +1 to move down
 */
function moveCardItem(root, item, delta) {
    const items = qsa(root, '.card-layout-item');
    const idx = items.indexOf(item);
    if (idx === -1) return;
    const targetIdx = idx + delta;
    if (targetIdx < 0 || targetIdx >= items.length) return;
    if (delta < 0) {
        root.insertBefore(item, items[targetIdx]);
    } else {
        root.insertBefore(item, items[targetIdx].nextElementSibling);
    }
    refreshMoveButtons(root);
    /* Keep focus on the control the keyboard user just activated (now at
       its new position) so repeated presses keep working without the
       browser losing track of where focus should land. */
    qs(item, delta < 0 ? '.card-layout-move-up-btn' : '.card-layout-move-down-btn')?.focus();
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
            if (!qs(item, '.card-layout-controls')) {
                const host = qs(item, handleSel);
                if (!host) continue;
                const controls = document.createElement('div');
                controls.className = 'card-layout-controls';

                const upBtn = document.createElement('button');
                upBtn.type = 'button';
                upBtn.className = 'btn btn-sm btn-outline-secondary card-layout-move-up-btn';
                upBtn.setAttribute('aria-label', 'Move card up');
                upBtn.title = 'Move up';
                upBtn.innerHTML = '<i class="bi bi-chevron-up" aria-hidden="true"></i>';
                upBtn.addEventListener('click', () => {
                    moveCardItem(root, item, -1);
                    save();
                });
                controls.appendChild(upBtn);

                const downBtn = document.createElement('button');
                downBtn.type = 'button';
                downBtn.className = 'btn btn-sm btn-outline-secondary card-layout-move-down-btn';
                downBtn.setAttribute('aria-label', 'Move card down');
                downBtn.title = 'Move down';
                downBtn.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
                downBtn.addEventListener('click', () => {
                    moveCardItem(root, item, 1);
                    save();
                });
                controls.appendChild(downBtn);

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
                controls.appendChild(hideBtn);

                host.appendChild(controls);
            }
        }
        refreshMoveButtons(root);

        loadSortable().then(S => {
            if (sortable) return;
            sortable = new S(root, {
                animation: 160,
                ghostClass: 'card-layout-ghost',
                handle: handleSel,
                onEnd: () => {
                    refreshMoveButtons(root);
                    save();
                },
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
        for (const controls of qsa(root, '.card-layout-controls')) controls.remove();
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
