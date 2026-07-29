/**
 * iHymns — Admin table header sort (#644)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Lightweight client-side sort for admin / manage tables. Opt-in via
 * `class="cp-sortable"` (or any class — the bootSortableTables() entry
 * point takes a selector) on the <table>; mark each <th> that should
 * be sortable with `data-sort-key="…"` and an optional
 * `data-sort-type="text|number|date"` (default text).
 *
 * Click cycles asc → desc → unsorted; arrows + aria-sort reflect the
 * state. The sort is stable so re-sorting on a new column preserves
 * the previous order as the secondary key.
 *
 * Each row's value for a column is read from the matching cell's
 * text content unless a `data-sort-value` attribute on the <td>
 * overrides it (handy for badge-cells like role pills where the
 * displayed text isn't directly orderable).
 *
 * Keyboard: each header becomes role="button" + tabindex=0 so Enter
 * and Space drive the cycle.
 */

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
 * Build a typed comparator. text / number / date are the supported
 * values for `data-sort-type` on the header.
 */
function makeCompare(type, direction) {
    const dir = direction === 'desc' ? -1 : 1;
    if (type === 'number') {
        return (a, b) => {
            const x = parseFloat(a) || 0;
            const y = parseFloat(b) || 0;
            return (x - y) * dir;
        };
    }
    if (type === 'date') {
        return (a, b) => {
            const x = Date.parse(a) || 0;
            const y = Date.parse(b) || 0;
            return (x - y) * dir;
        };
    }
    /* text — locale + numeric so "Item 2" sorts before "Item 10". */
    return (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }) * dir;
}

/**
 * Apply the sort state to a table. `state.column` is the column key
 * (or null for unsorted) and `state.direction` is 'asc' | 'desc' |
 * 'none'.
 */
function applySort(table, state) {
    const tbody = table.tBodies[0];
    if (!tbody) return;

    const headers = Array.from(table.tHead?.rows[0]?.cells ?? [])
        .map((th, i) => ({ th, key: th.getAttribute('data-sort-key'), type: th.getAttribute('data-sort-type') || 'text', index: i }));

    /* Update aria-sort + arrow icons on every header. */
    headers.forEach(h => {
        if (!h.key) return;
        h.th.setAttribute('aria-sort',
            state.column === h.key && state.direction !== 'none'
                ? (state.direction === 'asc' ? 'ascending' : 'descending')
                : 'none');
        const arrow = h.th.querySelector('.sort-arrow');
        if (arrow) {
            arrow.textContent =
                state.column === h.key && state.direction === 'asc'  ? '▲' :
                state.column === h.key && state.direction === 'desc' ? '▼' :
                '';
        }
    });

    if (state.column === null || state.direction === 'none') {
        /* Restore the original DOM order — captured once when we
           first attached. */
        const originalRows = table._adminSortOriginalRows;
        if (originalRows) {
            originalRows.forEach(r => tbody.appendChild(r));
        }
        return;
    }

    const target = headers.find(h => h.key === state.column);
    if (!target) return;

    /* Sort visible rows only — so per-page filters that hide rows
       (e.g. the search input on credit-people) don't shuffle hidden
       rows around. The display-none rows stay in their current
       relative position; we only reorder the visible ones. */
    const allRows = Array.from(tbody.rows);
    const visible = allRows.filter(r => r.offsetParent !== null);
    const compare = makeCompare(target.type, state.direction);

    /* Stable sort via map → sort → re-extract. */
    const decorated = visible.map((row, i) => ({ row, key: cellValue(row, target.index), i }));
    decorated.sort((a, b) => {
        const cmp = compare(a.key, b.key);
        return cmp !== 0 ? cmp : a.i - b.i;
    });

    /* Reinsert visible rows in their new order, preserving the
       interleaved positions of any hidden rows. */
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
 * Wire one table for sortable headers.
 */
function bootTable(table) {
    if (table._adminSortBound) return;
    table._adminSortBound = true;

    /* Capture the original row order once so 'unsorted' can restore. */
    const tbody = table.tBodies[0];
    if (!tbody) return;
    table._adminSortOriginalRows = Array.from(tbody.rows);

    const state = { column: null, direction: 'none' };

    /* Decorate every sortable header.
     *
     * ELI5: the clickable part is a real button placed INSIDE the header cell,
     * instead of turning the header cell itself into a button.
     *
     * Detail (#1646 item 2, WCAG 1.3.1 + 4.1.2): this used to do
     * `th.setAttribute('role', 'button')`, which does not ADD a role — it
     * REPLACES the element's implicit one. A `<th>` in a `<thead>` row is
     * implicitly `columnheader`, and that role is what a screen reader uses to
     * associate each data cell with the column it belongs to. Overwriting it
     * broke that association across all thirteen opted-in admin lists (#842 /
     * #844): navigating the body cells stopped reliably announcing which column
     * you were in.
     *
     * It also quietly disabled the very feature it was decorating. `aria-sort`
     * (set in applySort) is only DEFINED on `columnheader` / `rowheader`, so on
     * an element re-roled to `button` it is not required to be announced at all
     * — the sort state was being written to an attribute the role does not
     * support.
     *
     * The ARIA APG pattern for a sortable table is a real <button> nested
     * inside the <th>, leaving the header cell as the `columnheader` it already
     * was, with `aria-sort` staying on the <th>. As a bonus the explicit
     * keydown handler disappears: a real button synthesises click from Enter
     * and Space natively, which is the same reason #1644 replaced its
     * `role="button"` spans.
     * https://www.w3.org/WAI/ARIA/apg/patterns/table/
     */
    Array.from(table.tHead?.rows[0]?.cells ?? []).forEach(th => {
        if (!th.getAttribute('data-sort-key')) return;
        th.classList.add('admin-sort-th');

        /* Idempotent: bootstrap can run twice on a re-rendered table, and
           wrapping an already-wrapped header would nest buttons. */
        let btn = th.querySelector('.admin-sort-btn');
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'admin-sort-btn';
            /* Move the header's existing content into the button rather than
               reading textContent and re-writing it — a header may legitimately
               contain markup (an icon, a <span>, an abbreviation) and
               flattening it to a string would silently discard that. */
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

        const cycle = () => {
            const key = th.getAttribute('data-sort-key');
            if (state.column !== key) {
                state.column = key;
                state.direction = 'asc';
            } else {
                state.direction = CYCLE_NEXT[state.direction];
                if (state.direction === 'none') state.column = null;
            }
            applySort(table, state);
        };
        btn.addEventListener('click', cycle);
    });

    /* Apply the table's declared default sort, if any. */
    const defaultKey = table.getAttribute('data-default-sort-key');
    const defaultDir = table.getAttribute('data-default-sort-dir') || 'asc';
    if (defaultKey) {
        state.column = defaultKey;
        state.direction = defaultDir === 'desc' ? 'desc' : 'asc';
        applySort(table, state);
    }
}

/**
 * Find every matching table and wire it up. Idempotent — already-
 * bound tables are skipped.
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
