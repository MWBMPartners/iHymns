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
 * PERSISTENCE — device localStorage + account sync (§7 of the plan)
 * ----------------------------------------------------------------------
 * `STORAGE_LIST_SORT` (`js/constants.js`) holds ONE JSON map,
 * `{ "<surface>": [{key,dir},…] }`, keyed by surface id so each catalogue
 * page remembers its own choice independently — this is the FULL answer for
 * an anonymous device. Signed-in accounts additionally sync the same shape
 * through the EXISTING namespaced `user_settings` endpoint (#1671 F5),
 * namespace `list_sorts` — no new endpoint, no schema, no migration
 * (`primeAccountListSorts()` below). Precedence: localStorage applies
 * immediately at first paint (offline-safe); the account fetch, once it
 * resolves, WINS for any surface it has an opinion on and re-applies if
 * different (with an announce); a surface present only in localStorage
 * stays device-local — nothing is ever silently uploaded just by reading
 * it. A write goes to both, synchronously to localStorage then a
 * write-through POST of the FULL map to the account.
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
import { STORAGE_LIST_SORT, EVT_LIST_SORT_CHANGED, EVT_AUTH_CHANGED } from '../constants.js';
/* #1786 Option B §7 — account sync rides the EXISTING namespaced
   `user_settings` endpoint (#1671 F5), namespace `list_sorts`. No new
   endpoint, no schema, no migration — `tblUsers.Settings` already exists
   and the namespace write contract already does exactly what this needs
   ("name a namespace and only that subtree is replaced"). `apiFetch` (rule
   #31) attaches auth + X-Requested-With by structure. */
import { apiFetch } from '../utils/api-client.js';

/** ⚑ N1 — a card-friendly panel tops out at 3 levels. */
const MAX_LEVELS = 3;

/** Controls wired so far this page load, surface → the row's internal API.
 *  Used so a later account-sync layer can find a live control and re-apply
 *  it without this module needing to know who else might ask. */
const _wiredControls = new Map();

/* =========================================================================
 * PERSISTENCE — device localStorage + (§7) account sync
 * ========================================================================= */

/**
 * Module-level memo of the signed-in account's `list_sorts` subtree —
 * `{ "<surface>": [{key,dir},…] }` (raw, NOT yet shape-validated — that
 * happens per-read in getListSort() against that call's own allowedKeys).
 * `null` = not primed yet, or priming failed/the caller is signed out; an
 * empty object `{}` is a valid "signed in, nothing saved yet" result and
 * is deliberately distinct from `null` (only `null` falls through to
 * localStorage in getListSort() below).
 */
let _accountMap = null;

/** The in-flight/most-recent primeAccountListSorts() promise — memoised so
 *  a fetch runs at most once per session until EVT_AUTH_CHANGED resets it. */
let _accountPrimePromise = null;

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
 * Persist ONE surface's spec — localStorage SYNCHRONOUSLY, then (§7.3)
 * write-through the FULL map to the account store when signed in. Deletes
 * the key entirely when the spec is empty (Default) rather than storing
 * `[]`, so an absent key and an explicit "no override" read identically on
 * the next visit.
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

    /* Keep the in-memory account cache consistent with what was JUST
       written, so a getListSort() call for this surface a moment later
       (before the POST below round-trips) reflects the change immediately
       rather than the stale value primeAccountListSorts() last fetched. */
    if (_accountMap) {
        if (spec.length) {
            _accountMap[surface] = spec;
        } else {
            delete _accountMap[surface];
        }
    }

    _pushAccountListSorts(local);
}

/**
 * Read back the validated, capped spec for one surface. Account wins over
 * localStorage once primed (§7.3) — a surface the account store has never
 * heard of stays device-local, never silently uploaded just by reading it.
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
    const fromAccount = _accountMap && Object.prototype.hasOwnProperty.call(_accountMap, surface);
    const raw = fromAccount ? _accountMap[surface] : local[surface];
    return normalizeSortSpec(raw, allowedKeys ?? null, MAX_LEVELS);
}

/**
 * Write-through POST of the FULL `list_sorts` map to the account (§7.2).
 * The namespaced write REPLACES the whole subtree, so sending the complete
 * map (account-merged-plus-this-change, since `_saveSpec` already folded
 * the change into a copy of what priming last mirrored) is what makes
 * "what you POST is what you GET" safe — a partial post would delete every
 * surface it omitted.
 *
 * Fails soft, always (§7.5 / rule #9): anonymous → 401 → console-only, never
 * a user-facing error; the localStorage write above has already happened
 * regardless, so device-local behaviour is never blocked on this.
 *
 * @param {Object<string,unknown>} map
 */
async function _pushAccountListSorts(map) {
    try {
        const res = await apiFetch('/api?action=user_settings', {
            method: 'POST',
            auth: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ namespace: 'list_sorts', settings: map }),
        });
        if (!res.ok) {
            console.warn('[list-sort] account sync save failed:', res.status);
        }
    } catch (err) {
        console.error('[list-sort] account sync save failed:', err);
    }
}

/**
 * Mirror the account's subtree into localStorage — "account wins on read"
 * (§7.3): for every surface the account has an opinion on, that becomes
 * the local copy too, so a later offline/anonymous read (or a DIFFERENT
 * device that hasn't synced yet) sees the same value. Surfaces present ONLY
 * in localStorage are left alone — reading the account never uploads a
 * device-local-only surface on its own (no silent auto-upload on read).
 *
 * @param {Object<string,unknown>} accountSettings
 */
function _mirrorAccountIntoLocal(accountSettings) {
    const local = _readLocalMap();
    let changed = false;
    for (const [surface, rawSpec] of Object.entries(accountSettings)) {
        const spec = normalizeSortSpec(rawSpec, null, MAX_LEVELS);
        const already = JSON.stringify(local[surface] ?? []);
        const incoming = JSON.stringify(spec);
        if (already === incoming) continue;
        if (spec.length) {
            local[surface] = spec;
        } else {
            delete local[surface];
        }
        changed = true;
    }
    if (changed) _writeLocalMap(local);
}

/**
 * For every surface the account has an opinion on AND that has a control
 * wired on the CURRENT page, re-apply it — with an announce, since the
 * visible order may genuinely change out from under whatever the local
 * copy already showed (§7.3: "if the visible surface's applied spec
 * differs, re-applied").
 *
 * @param {Object<string,unknown>} accountSettings
 */
function _reapplyWiredFromAccount(accountSettings) {
    for (const [surface, rawSpec] of Object.entries(accountSettings)) {
        const wired = _wiredControls.get(surface);
        if (wired) wired.reapplyExternal(rawSpec);
    }
}

/**
 * Fetch the signed-in account's `list_sorts` subtree ONCE per app session
 * (memoised; re-primed on `EVT_AUTH_CHANGED` — a login/logout changes WHOSE
 * data this should reflect). Safe to call from anywhere, any number of
 * times — every caller after the first gets the SAME in-flight/settled
 * promise. Anonymous / offline / any failure → resolves to `null` and
 * `getListSort()` simply falls back to localStorage, exactly as before this
 * layer existed (rule #9 — a verified no-op when this never succeeds).
 *
 * @returns {Promise<?Object<string,unknown>>}
 */
export function primeAccountListSorts() {
    if (_accountPrimePromise) return _accountPrimePromise;
    _accountPrimePromise = (async () => {
        try {
            const res = await apiFetch('/api?action=user_settings&namespace=list_sorts', { auth: true });
            if (!res.ok) return null; /* 401 (anonymous) / network-adjacent failure — device-local only */
            const data = await res.json();
            const settings = (data && typeof data.settings === 'object' && data.settings) || {};
            _accountMap = settings;
            _mirrorAccountIntoLocal(settings);
            _reapplyWiredFromAccount(settings);
            return settings;
        } catch (err) {
            console.error('[list-sort] account sync fetch failed:', err);
            return null;
        }
    })();
    return _accountPrimePromise;
}

/* Re-prime on sign-in/sign-out — a stale cross-account cache would either
   leak the PREVIOUS account's sort choices onto a new session or fail to
   pick up the NEWLY signed-in account's saved choices without a full page
   reload. Guarded for non-DOM environments (e.g. this module imported
   directly by a Node test with no `document`) — the same posture
   admin-table-sort.js's own auto-wire side effect already takes. */
if (typeof document !== 'undefined') {
    document.addEventListener(EVT_AUTH_CHANGED, () => {
        _accountPrimePromise = null;
        _accountMap = null;
        primeAccountListSorts();
    });
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
     *   reapply path (§7.3): the visible order still changes and is still
     *   announced, but is NOT re-saved (it already came FROM the store) and
     *   NOT treated as "the user just did this".
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
         *  control — the account-sync layer (§7.3), when the fetched
         *  account subtree differs from what localStorage/priming already
         *  applied. */
        reapplyExternal(candidate) {
            apply(candidate, { fromUser: false });
        },
    });

    /* §7.3 — kick off (or piggy-back on an already in-flight) account
       fetch. Memoised, so this costs nothing extra when more than one
       control is wired on the same page; a resolved fetch re-applies THIS
       control too if the account's saved spec differs from what was just
       applied from localStorage above. */
    primeAccountListSorts();
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
