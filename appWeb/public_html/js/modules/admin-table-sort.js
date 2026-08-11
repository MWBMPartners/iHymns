/**
 * iHymns — table header sort (#644; multi-column #1786)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Click a column header to sort the table by it. Shift-click a SECOND header to
 * sort by that one too, WITHOUT losing the first — so you can say "by songbook,
 * then by number" the way a spreadsheet does. A little ①②③ badge shows the
 * order the columns are applied in.
 *
 * USAGE
 * -----
 * Opt-in via any selector passed to `bootSortableTables()` (default
 * `table.cp-sortable`) on the <table>; mark each sortable <th> with
 * `data-sort-key="…"` and an optional `data-sort-type="text|number|date"`
 * (default text). A `data-default-sort-key` / `data-default-sort-dir` on the
 * <table> applies an initial single-column sort.
 *
 * INTERACTION (#1786)
 * -------------------
 *  - **Plain click** = make this the SOLE sort key, cycling asc → desc →
 *    unsorted (clearing any other levels). The everyday case, unchanged from
 *    the single-column behaviour (#644/#844).
 *  - **Shift-click** (or Alt-click, for trackpads that swallow shift-click) =
 *    add this column as an ADDITIONAL level, or cycle/remove it if already a
 *    level — the previous levels stay, so it builds a "then by …" chain.
 *
 * Each row's value for a column is read from the matching cell's text content
 * unless a `data-sort-value` attribute on the <td> overrides it (handy for
 * badge cells where the displayed text isn't directly orderable). The sort is
 * stable (equal rows keep their prior relative order), and only VISIBLE rows
 * are reordered so a per-page filter that hides rows isn't disturbed.
 *
 * Accessibility: the clickable target is a real <button> nested inside the <th>
 * (the ARIA APG sortable-table pattern), leaving the cell as its implicit
 * `columnheader`; `aria-sort` on the <th> reflects each active level.
 * https://www.w3.org/WAI/ARIA/apg/patterns/table/
 */

/* #1786 (rev 2 / public-sort build) — makeCompare()/multiKeyCompare() used to
   be defined HERE. They are now the shared core in
   js/utils/sort-compare.js so the public card/list "Sort ▾" panel
   (js/modules/list-sort.js) can reuse the exact same comparator instead of
   re-forking it (rule #22 — the duplicate/counterpart scorer's old `_bsls_*`
   copies are the shape this avoids repeating). Only `multiKeyCompare` is
   imported + RE-EXPORTED here, unchanged, so tests/test-admin-table-sort.js's
   existing `import { multiKeyCompare, … } from '.../admin-table-sort.js'`
   keeps working with zero behaviour change. `makeCompare` is NOT imported
   here on purpose — nothing in this file uses it and its callers import it
   directly from js/utils/sort-compare.js, so naming it here would be a dead
   import (ESLint no-unused-vars). This file's own logic did not move, only its
   HOME did. */
import { multiKeyCompare } from '../utils/sort-compare.js';
export { multiKeyCompare };

/* Single-column click cycle. Shift-click uses the same asc→desc→remove ladder
   per level. */
const CYCLE_NEXT = { 'none': 'asc', 'asc': 'desc', 'desc': 'none' };

/**
 * Read the sort-comparable value for a row's cell.
 *
 * @param {HTMLTableRowElement} row
 * @param {number} colIndex Column index (matches the <th>'s position).
 * @returns {string} Raw string; the comparator coerces by type.
 */
function cellValue(row, colIndex) {
    const cell = row.cells?.[colIndex];
    if (!cell) return '';
    const explicit = cell.getAttribute('data-sort-value');
    return explicit !== null ? explicit : cell.textContent.trim();
}

/**
 * Index every sortable header once, so both the click handler and applySort()
 * resolve a data-sort-key to its column index + type the same way.
 * @returns {Array<{th:HTMLTableCellElement,key:?string,type:string,index:number}>}
 */
function headerInfo(table) {
    return Array.from(table.tHead?.rows[0]?.cells ?? []).map((th, i) => ({
        th,
        key: th.getAttribute('data-sort-key'),
        type: th.getAttribute('data-sort-type') || 'text',
        index: i,
    }));
}

/**
 * Apply the current sort STACK to a table. `state.keys` is an ordered array of
 * `{ key, direction, type, index }`; empty ⇒ restore the original DOM order.
 */
function applySort(table, state) {
    const tbody = table.tBodies[0];
    if (!tbody) return;

    const headers = headerInfo(table);

    /* Reflect state on every header: aria-sort for the active levels, and an
       arrow + (when there is more than one level) a small order badge so the
       "then by …" priority is visible. */
    const multi = state.keys.length > 1;
    headers.forEach(h => {
        if (!h.key) return;
        const level = state.keys.findIndex(k => k.key === h.key);
        const active = level !== -1;
        h.th.setAttribute('aria-sort',
            active ? (state.keys[level].direction === 'asc' ? 'ascending' : 'descending') : 'none');
        const arrow = h.th.querySelector('.sort-arrow');
        if (arrow) {
            const dir = active ? (state.keys[level].direction === 'asc' ? '▲' : '▼') : '';
            /* ①②③ … only when a multi-level sort is in effect, so the common
               single-column case stays visually quiet. U+2460 is ①. */
            const badge = (active && multi) ? String.fromCharCode(0x2460 + Math.min(level, 8)) : '';
            arrow.textContent = dir ? (dir + badge) : '';
        }
    });

    if (state.keys.length === 0) {
        const originalRows = table._adminSortOriginalRows;
        if (originalRows) { originalRows.forEach(r => tbody.appendChild(r)); }
        return;
    }

    /* Only the columns in the stack are needed per row. */
    const allRows = Array.from(tbody.rows);
    const visible = allRows.filter(r => r.offsetParent !== null);
    const decorated = visible.map((row, i) => {
        const vals = {};
        state.keys.forEach(k => { vals[k.index] = cellValue(row, k.index); });
        return { row, vals, i };
    });
    decorated.sort((a, b) => {
        const cmp = multiKeyCompare(state.keys, a.vals, b.vals);
        return cmp !== 0 ? cmp : a.i - b.i;   /* stable */
    });

    /* Reinsert visible rows in their new order, preserving the interleaved
       positions of any hidden (filtered-out) rows. */
    let visibleIdx = 0;
    allRows.forEach(row => {
        if (row.offsetParent === null) {
            tbody.appendChild(row);
        } else {
            tbody.appendChild(decorated[visibleIdx++].row);
        }
    });
}

/**
 * Mutate the sort stack for a header click.
 *
 * @param {Array} keys      Current stack (mutated + returned).
 * @param {{key:string,type:string,index:number}} hdr The clicked header.
 * @param {boolean} additive Shift/Alt-click — add/cycle a level, keep the rest.
 * @returns {Array} The new stack.
 *
 * Exported for `tests/test-admin-table-sort.js` (the click-transition logic is
 * the new UX; it is proven, not assumed — rule #34).
 */
export function nextStack(keys, hdr, additive) {
    const at = keys.findIndex(k => k.key === hdr.key);
    if (additive) {
        if (at === -1) {
            keys.push({ key: hdr.key, type: hdr.type, index: hdr.index, direction: 'asc' });
        } else {
            const nd = CYCLE_NEXT[keys[at].direction];
            if (nd === 'none') { keys.splice(at, 1); } else { keys[at].direction = nd; }
        }
        return keys;
    }
    /* Plain click — this column becomes the SOLE sort. If it already is the
       sole sort, cycle it; otherwise reset the stack to just this one asc. */
    if (keys.length === 1 && at === 0) {
        const nd = CYCLE_NEXT[keys[0].direction];
        return nd === 'none' ? [] : [{ ...keys[0], direction: nd }];
    }
    return [{ key: hdr.key, type: hdr.type, index: hdr.index, direction: 'asc' }];
}

/**
 * Wire one table for sortable headers.
 */
function bootTable(table) {
    if (table._adminSortBound) return;
    table._adminSortBound = true;

    const tbody = table.tBodies[0];
    if (!tbody) return;
    /* Capture the original row order once so an emptied stack can restore it. */
    table._adminSortOriginalRows = Array.from(tbody.rows);

    const state = { keys: [] };

    headerInfo(table).forEach(hdr => {
        if (!hdr.key) return;
        const th = hdr.th;
        th.classList.add('admin-sort-th');

        /* Idempotent: bootstrap can run twice on a re-rendered table, and
           wrapping an already-wrapped header would nest buttons. */
        let btn = th.querySelector('.admin-sort-btn');
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'admin-sort-btn';
            btn.title = 'Click to sort. Shift-click to add another sort level.';
            /* Move existing content (may be markup — an icon, a <span>) into the
               button rather than flattening to a string. */
            while (th.firstChild) { btn.appendChild(th.firstChild); }
            th.appendChild(btn);
        }
        if (!btn.querySelector('.sort-arrow')) {
            const arrow = document.createElement('span');
            arrow.className = 'sort-arrow ms-1 small text-muted';
            arrow.style.minWidth = '0.6em';
            arrow.style.display = 'inline-block';
            arrow.setAttribute('aria-hidden', 'true');   /* aria-sort on the <th> is the real signal */
            btn.appendChild(arrow);
        }

        btn.addEventListener('click', (ev) => {
            const additive = ev.shiftKey || ev.altKey;
            state.keys = nextStack(state.keys, hdr, additive);
            applySort(table, state);
        });
    });

    /* Apply the table's declared default single-column sort, if any. */
    const defaultKey = table.getAttribute('data-default-sort-key');
    if (defaultKey) {
        const hdr = headerInfo(table).find(h => h.key === defaultKey);
        if (hdr) {
            const dir = (table.getAttribute('data-default-sort-dir') || 'asc') === 'desc' ? 'desc' : 'asc';
            state.keys = [{ key: hdr.key, type: hdr.type, index: hdr.index, direction: dir }];
            applySort(table, state);
        }
    }
}

/**
 * Find every matching table and wire it up. Idempotent — already-bound tables
 * are skipped.
 *
 * @param {string} selector CSS selector for tables to make sortable.
 *                          Defaults to `table.cp-sortable`.
 */
export function bootSortableTables(selector = 'table.cp-sortable') {
    document.querySelectorAll(selector).forEach(bootTable);
}

/* Auto-wire on DOMContentLoaded for plain script-tag consumers. */
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootSortableTables());
    } else {
        bootSortableTables();
    }
}
