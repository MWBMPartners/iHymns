/**
 * iHymns — public card/list multi-level "Sort ▾" control (#1786 Option B)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The admin side of #1786 let you click a table's column headings to sort by
 * one, then shift-click a second heading to say "and THEN by this". The
 * public catalogue pages (songbooks, a songbook's song list, a theme page,
 * favourites, search…) are cards and list-groups, not tables — there are no
 * headings to click. This module gives those pages the same "sort by A, then
 * by B" power through a small "Sort ▾" panel instead, reusing the exact same
 * comparator (`js/utils/sort-compare.js`) so the two halves of #1786 agree.
 *
 * ARCHITECTURE — the two adoption modes
 * --------------------------------------
 *  1. **DOM mode** — most catalogue pages (`/songbooks`, `/songbook/<abbr>`,
 *     `/tag/<slug>`, …) render their full list server-side. A page opts in by
 *     tagging its sortable container(s) `data-list-sort-list="<surface>"`
 *     (one control may govern SEVERAL containers — a page grouped by
 *     songbook, say — each sorted independently) and every item with
 *     `data-sort-<key>` attributes. `initListSort()`, called unconditionally
 *     from `router.js`'s `afterPageLoad()`, finds every
 *     `[data-list-sort-surface]` control, and for any that has a matching
 *     `[data-list-sort-list]` container ANYWHERE on the page, wires it up
 *     and re-orders the container's direct children in place.
 *  2. **Array / server mode** — a page whose list is JS-rendered from an
 *     in-memory array (favourites) or paginated from the server (search)
 *     has NO `data-list-sort-list` container — re-ordering only the
 *     currently-loaded DOM page would silently sort just that slice and
 *     lie about the rest (§9.1 of the plan). Those modules call
 *     `wireListSortControl(surface, onChange)` themselves and re-render
 *     (or re-fetch) on every change; `initListSort()` skips a surface with
 *     no container by construction, so DOM mode can never partially
 *     "half-wire" an array-mode surface by accident.
 *
 * Both modes share the SAME control markup (`includes/partials/
 * list-sort-control.php`, emitted server-side so it works on a
 * shared-cache fragment — rule #6) and the same wiring core below.
 *
 * PERSISTENCE (device-only in THIS commit)
 * ------------------------------------------
 * `STORAGE_LIST_SORT` (`js/constants.js`) holds ONE JSON map,
 * `{ "<surface>": [{key,dir},…] }`, keyed by surface id so each catalogue
 * page remembers its own choice independently. Account-sync (read the
 * signed-in user's saved spec across devices, write through on change) is
 * layered on top of `getListSort()`/`_saveSpec()` in a later commit — see
 * `primeAccountListSorts()`'s doc-block once it lands; nothing here assumes
 * it exists yet, so this module is fully functional standalone.
 *
 * WHY THE CONTROL MARKUP IS SERVER-EMITTED, NOT BUILT BY THIS MODULE
 * ----------------------------------------------------------------------
 * `includes/pages/*.php` fragments can be served through api.php's
 * `$_cacheablePages` shared cache — identical bytes for every visitor. The
 * control's SHAPE (which keys exist) is the same for everyone and therefore
 * safe to bake into that shared markup; only the VIEWER'S saved spec is
 * personal, and that is applied here, client-side, after the fragment lands
 * — never the other way around (never server-personalise a cached
 * fragment). This is the same split `includes/card_layout.php` documents for
 * the home card grid.
 *
 * @see appWeb/public_html/js/utils/sort-compare.js       the shared comparator
 * @see appWeb/public_html/includes/partials/list-sort-control.php  the markup this wires
 * @see appWeb/public_html/js/modules/router.js            afterPageLoad() — the unconditional boot
 * @see .claude/public-list-sort-1786-plan.md  §3, §7        full design
 * @link https://github.com/MWBMPartners/iHymns/issues/1786
 */

import { announce } from '../utils/announce.js';
import { multiKeyCompareMissingLast, normalizeSortSpec } from '../utils/sort-compare.js';
import { STORAGE_LIST_SORT, EVT_LIST_SORT_CHANGED } from '../constants.js';

/** ⚑ N1 — a card-friendly panel tops out at 3 levels. */
const MAX_LEVELS = 3;

/** Controls wired so far this page load, surface → the row's internal API.
 *  Used so a later account-sync layer can find a live control and re-apply
 *  it without this module needing to know who else might ask. */
const _wiredControls = new Map();

/* =========================================================================
 * PERSISTENCE — device localStorage, ONE JSON map keyed by surface
 * ========================================================================= */

/** @returns {Object<string,unknown>} the raw stored map, or {} if absent/corrupt. */
function _readLocalMap() {
    try {
        const raw = localStorage.getItem(STORAGE_LIST_SORT);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
    } catch (_e) {
        /* Corrupt JSON / private-mode throw — same "treated as absent" posture
           as every other localStorage reader in this codebase. */
        return {};
    }
}

/** @param {Object<string,unknown>} map */
function _writeLocalMap(map) {
    try {
        localStorage.setItem(STORAGE_LIST_SORT, JSON.stringify(map));
    } catch (_e) {
        /* Quota exceeded / private-mode Safari — a saved sort preference is
           not worth surfacing an error for; the control still works this
           session, it just won't remember next time. */
    }
}

/**
 * Persist ONE surface's spec into the local map. Deletes the key entirely
 * when the spec is empty (Default) rather than storing `[]`, so an absent
 * key and an explicit "no override" read identically on the next visit.
 *
 * @param {string} surface
 * @param {Array<{key:string,dir:string}>} spec
 */
function _saveSpec(surface, spec) {
    const local = _readLocalMap();
    if (spec.length) {
        local[surface] = spec;
    } else {
        delete local[surface];
    }
    _writeLocalMap(local);
}

/**
 * Read back the validated, capped spec for one surface.
 *
 * ELI5: "what did this viewer last choose for this list, if anything (and is
 * it still something the control can actually offer today)?"
 *
 * @param {string} surface
 * @param {?string[]} [allowedKeys] Keys the CURRENT control offers — a saved
 *   key the control no longer has is dropped silently ("a saved layout is a
 *   wish, not a contract", the `cardLayoutMerge()` posture). Omit to skip
 *   that filter (shape-validation only).
 * @returns {Array<{key:string,dir:string}>} `[]` means "no override — Default".
 */
export function getListSort(surface, allowedKeys) {
    const local = _readLocalMap();
    return normalizeSortSpec(local[surface], allowedKeys ?? null, MAX_LEVELS);
}

/* =========================================================================
 * OPTIONS PARSING (from the server-emitted data-list-sort-options JSON)
 * ========================================================================= */

/**
 * @param {HTMLElement} controlEl
 * @returns {?Object<string,{label:string,type:string,dir:string}>}
 */
function _parseOptions(controlEl) {
    const raw = controlEl.dataset.listSortOptions || '';
    if (!raw) return null;
    try {
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return null;
        return parsed;
    } catch (_e) {
        return null;
    }
}

/* =========================================================================
 * DISPLAY TEXT
 * ========================================================================= */

function _levelLabel(level, options) {
    const opt = options[level.key];
    return opt ? opt.label : level.key;
}

/** Button label — the live summary of the active spec (plan §3.1). */
function _summaryText(spec, defaultLabel, options) {
    if (!spec.length) return `Sort: Default (${defaultLabel})`;
    const parts = spec.map((lvl) => `${_levelLabel(lvl, options)} ${lvl.dir === 'desc' ? '↓' : '↑'}`);
    return `Sort: ${parts.join(', then ')}`;
}

/** aria-live announcement text (WCAG 4.1.3) — plan §3.1. */
function _announceText(spec, options) {
    if (!spec.length) return 'Default order restored.';
    const parts = spec.map((lvl) => `${_levelLabel(lvl, options)} ${lvl.dir === 'desc' ? 'descending' : 'ascending'}`);
    return `Sorted by ${parts.join(', then ')}.`;
}

/* =========================================================================
 * THE SHARED WIRING CORE — used by BOTH initListSort() (DOM mode) and
 * wireListSortControl() (array/server mode)
 * ========================================================================= */

/**
 * @param {HTMLElement} controlEl
 * @param {string} surface
 * @param {Object<string,{label:string,type:string,dir:string}>} options
 * @param {string} defaultLabel
 * @param {(spec: Array<{key:string,dir:string}>) => void} onApply Called with
 *   the validated spec every time it changes (including the initial apply).
 */
function _wireControlCommon(controlEl, surface, options, defaultLabel, onApply) {
    if (controlEl.dataset.listSortWired === '1') return;
    controlEl.dataset.listSortWired = '1';

    const summaryEl = controlEl.querySelector('.list-sort-summary');
    const levelsEl = controlEl.querySelector('.list-sort-levels');
    const addBtn = controlEl.querySelector('.list-sort-add');
    const resetBtn = controlEl.querySelector('.list-sort-reset');
    if (!levelsEl || !addBtn || !resetBtn) return; /* malformed control markup */

    const allowedKeys = Object.keys(options);
    let spec = getListSort(surface, allowedKeys);

    function renderSummary() {
        if (summaryEl) summaryEl.textContent = _summaryText(spec, defaultLabel, options);
    }

    function buildLevelRow(level, idx) {
        const row = document.createElement('div');
        row.className = 'list-sort-level d-flex align-items-center gap-2 mb-2';

        /* ①②③ … priority badge — mirrors the admin module's own glyphs
           (admin-table-sort.js) so both halves of #1786 read the same way. */
        const badge = document.createElement('span');
        badge.className = 'list-sort-level-badge small text-muted';
        badge.setAttribute('aria-hidden', 'true');
        badge.textContent = String.fromCharCode(0x2460 + Math.min(idx, 8));
        row.appendChild(badge);

        const usedKeys = new Set(spec.map((s) => s.key));
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm list-sort-level-key';
        select.setAttribute('aria-label', `Level ${idx + 1} sort key`);
        for (const [key, def] of Object.entries(options)) {
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = def.label;
            if (key !== level.key && usedKeys.has(key)) opt.disabled = true;
            if (key === level.key) opt.selected = true;
            select.appendChild(opt);
        }
        select.addEventListener('change', () => {
            const next = spec.slice();
            const key = select.value;
            next[idx] = { key, dir: options[key]?.dir === 'desc' ? 'desc' : 'asc' };
            apply(next, { fromUser: true });
        });
        row.appendChild(select);

        const dirBtn = document.createElement('button');
        dirBtn.type = 'button';
        dirBtn.className = 'btn btn-sm btn-outline-secondary list-sort-level-dir';
        dirBtn.setAttribute('aria-pressed', String(level.dir === 'desc'));
        dirBtn.setAttribute('aria-label', `Level ${idx + 1} direction: ${level.dir === 'asc' ? 'ascending' : 'descending'}`);
        dirBtn.innerHTML = `<i class="fa-solid fa-arrow-${level.dir === 'desc' ? 'down' : 'up'}" aria-hidden="true"></i>`;
        dirBtn.addEventListener('click', () => {
            const next = spec.slice();
            next[idx] = { key: level.key, dir: level.dir === 'desc' ? 'asc' : 'desc' };
            apply(next, { fromUser: true });
        });
        row.appendChild(dirBtn);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-secondary list-sort-level-remove';
        removeBtn.setAttribute('aria-label', `Remove sort level ${idx + 1}`);
        removeBtn.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        removeBtn.addEventListener('click', () => {
            const next = spec.slice();
            next.splice(idx, 1);
            apply(next, { fromUser: true });
        });
        row.appendChild(removeBtn);

        return row;
    }

    function renderLevels() {
        levelsEl.innerHTML = '';
        spec.forEach((level, idx) => levelsEl.appendChild(buildLevelRow(level, idx)));

        const usedKeys = new Set(spec.map((s) => s.key));
        const anyUnusedKey = allowedKeys.some((k) => !usedKeys.has(k));
        addBtn.classList.toggle('d-none', spec.length >= MAX_LEVELS || !anyUnusedKey);
        resetBtn.classList.toggle('d-none', spec.length === 0);
    }

    /**
     * Apply a NEW candidate spec: validate/cap/dedupe it, re-render the
     * panel + summary, run the caller's onApply (the actual re-sort), and —
     * only for a genuine user-driven change — announce + persist.
     *
     * @param {Array} candidate
     * @param {{fromUser:boolean}} opts fromUser=false is the account-sync
     *   reapply path (added in a later commit): the visible order still
     *   changes and is still announced, but is NOT re-saved (it already
     *   came FROM the store) and NOT treated as "the user just did this".
     */
    function apply(candidate, { fromUser }) {
        const next = normalizeSortSpec(candidate, allowedKeys, MAX_LEVELS);
        const changed = JSON.stringify(next) !== JSON.stringify(spec);
        spec = next;
        renderLevels();
        renderSummary();
        onApply(spec);
        /* Dispatched on EVERY apply, not just a user-driven one — a page
           loading with an already-saved non-default spec re-orders the DOM
           on this very FIRST apply (async, after songbook-index.js's own
           synchronous initial letter-map build — see router.js's
           afterPageLoad ordering), so a listener that only rebuilds derived
           UI on "user changed something" would start life already stale.
           Listeners filter on `detail.surface`, so this is a cheap no-op
           for every surface that has none. */
        try {
            document.dispatchEvent(new CustomEvent(EVT_LIST_SORT_CHANGED, { detail: { surface, spec } }));
        } catch (_e) { /* legacy browsers without CustomEvent — never fatal */ }
        if (changed) announce(_announceText(spec, options));
        if (fromUser) _saveSpec(surface, spec);
    }

    addBtn.addEventListener('click', () => {
        const usedKeys = new Set(spec.map((s) => s.key));
        const nextKey = allowedKeys.find((k) => !usedKeys.has(k));
        if (!nextKey) return;
        apply(spec.concat([{ key: nextKey, dir: options[nextKey]?.dir === 'desc' ? 'desc' : 'asc' }]), { fromUser: true });
    });

    resetBtn.addEventListener('click', () => apply([], { fromUser: true }));

    /* Initial render — reflects whatever was already saved, WITHOUT treating
       page load itself as a user action (no announce, no re-save). */
    renderLevels();
    renderSummary();
    onApply(spec);

    _wiredControls.set(surface, {
        /** Re-apply a spec that arrived from somewhere OTHER than this
         *  control (the account-sync layer, added in a later commit). */
        reapplyExternal(candidate) {
            apply(candidate, { fromUser: false });
        },
    });
}

/* =========================================================================
 * MODE 1 — DOM reorder (most catalogue pages)
 * ========================================================================= */

/**
 * Re-order every sortable container for a DOM-mode surface in place.
 *
 * @param {HTMLElement[]} containers
 * @param {HTMLElement[][]} originalByContainer Each container's ORIGINAL
 *   (server-rendered) child order, captured once at wire time — Default
 *   restores exactly this, not "whatever order the DOM happens to be in".
 * @param {Array<{key:string,dir:string}>} spec
 * @param {Object<string,{label:string,type:string,dir:string}>} options
 */
function _applyDomSort(containers, originalByContainer, spec, options) {
    containers.forEach((container, ci) => {
        if (!spec.length) {
            /* Default — restore the captured original order. Filter to
               .isConnected so a node some OTHER code removed in the meantime
               can never be resurrected into the container (plan §9.3). */
            const frag = document.createDocumentFragment();
            for (const node of originalByContainer[ci]) {
                if (node.isConnected) frag.appendChild(node);
            }
            container.appendChild(frag);
            return;
        }

        /* Sort ALL direct children, including any hidden (d-none) ones —
           deliberately unlike the admin table module's visible-only dance;
           there is no interleaving contract to preserve here (see the
           module doc-block). One decorate pass (attribute reads only, no
           layout reads) + one DocumentFragment re-append = one reflow. */
        const levels = spec.map((s) => ({ key: s.key, type: options[s.key]?.type || 'text', direction: s.dir }));
        const decorated = Array.from(container.children).map((el, i) => {
            const vals = {};
            for (const lvl of levels) {
                vals[lvl.key] = el.hasAttribute(`data-sort-${lvl.key}`) ? el.getAttribute(`data-sort-${lvl.key}`) : null;
            }
            return { el, vals, i };
        });
        decorated.sort((a, b) => {
            const cmp = multiKeyCompareMissingLast(levels, a.vals, b.vals);
            return cmp !== 0 ? cmp : a.i - b.i; /* stable */
        });

        const frag = document.createDocumentFragment();
        for (const d of decorated) frag.appendChild(d.el);
        container.appendChild(frag);
    });
}

/**
 * Wire every `[data-list-sort-surface]` control found under `root` that has
 * a matching `[data-list-sort-list]` container somewhere on the page.
 * Idempotent (per control) and safe to call on every navigation — a fresh
 * page fragment has fresh, unwired elements, so there is nothing to skip.
 *
 * @param {Document|HTMLElement} [root] Defaults to the whole document.
 */
export function initListSort(root = document) {
    const scope = (root && typeof root.querySelectorAll === 'function') ? root : document;
    const controls = scope.querySelectorAll('[data-list-sort-surface]');

    controls.forEach((controlEl) => {
        if (controlEl.dataset.listSortWired === '1') return;

        const surface = controlEl.dataset.listSortSurface || '';
        if (!/^[a-z0-9-]{1,40}$/.test(surface)) return;

        const options = _parseOptions(controlEl);
        if (!options || Object.keys(options).length === 0) return;

        /* No matching container anywhere ⇒ an array/server-mode surface;
           its OWN module calls wireListSortControl() instead. Searching the
           whole document (not just `scope`) matters when `root` is a
           narrower subtree than the container. */
        const containers = Array.from(document.querySelectorAll(`[data-list-sort-list="${surface}"]`));
        if (containers.length === 0) return;

        const originalByContainer = containers.map((c) => Array.from(c.children));
        const defaultLabel = controlEl.dataset.listSortDefaultLabel || 'Default';

        _wireControlCommon(controlEl, surface, options, defaultLabel, (spec) => {
            _applyDomSort(containers, originalByContainer, spec, options);
        });
    });
}

/* =========================================================================
 * MODE 2 — array / server mode (favourites, search)
 * ========================================================================= */

/**
 * Wire a control whose surface has NO `data-list-sort-list` container —
 * the page itself owns rendering (an in-memory array, or a fresh server
 * fetch) and re-runs it whenever the sort changes.
 *
 * @param {string} surface
 * @param {(spec: Array<{key:string,dir:string}>) => void} onChange Called
 *   with the validated spec on every change, INCLUDING the initial apply
 *   (so the caller can do its first render sorted, not render-then-resort).
 */
export function wireListSortControl(surface, onChange) {
    const controlEl = document.querySelector(`[data-list-sort-surface="${surface}"]`);
    if (!controlEl || controlEl.dataset.listSortWired === '1') return;

    const options = _parseOptions(controlEl);
    if (!options || Object.keys(options).length === 0) return;

    const defaultLabel = controlEl.dataset.listSortDefaultLabel || 'Default';
    _wireControlCommon(controlEl, surface, options, defaultLabel, (spec) => {
        try {
            onChange(spec);
        } catch (err) {
            console.error(`[list-sort] onChange for surface "${surface}" failed:`, err);
        }
    });
}
