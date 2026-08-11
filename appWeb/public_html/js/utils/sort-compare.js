/**
 * iHymns — shared multi-level sort comparator (#1786)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Both halves of #1786 — the admin `<table>` column-header sorter
 * (`admin-table-sort.js`) and the public card/list "Sort ▾" panel
 * (`list-sort.js`) — need the exact same answer to "given an ordered list of
 * sort levels, which of these two rows comes first?" This file is that ONE
 * answer, extracted so neither side re-implements it (rule #22 — the
 * duplicate/counterpart scorer's `_bsls_*` history is exactly the mistake
 * this avoids).
 *
 * WHY EXTRACTED RATHER THAN IMPORTED FROM admin-table-sort.js DIRECTLY
 * ----------------------------------------------------------------------
 * `admin-table-sort.js` auto-wires every `table.cp-sortable` on the page as
 * an import-time side effect (its trailing `if (typeof document !== …)`
 * block). Importing it from the public bundle would drag that side effect —
 * and the admin module's DOM-only assumptions — into every public page.
 * Rule #36's lesson applies: the shared thing must be small enough to adopt
 * without dragging in what it sits inside. `makeCompare` and
 * `multiKeyCompare` below are moved here VERBATIM (byte-identical logic,
 * `admin-table-sort.js` 68-109 as of the #1786 admin half) — behaviour is
 * unchanged, `tests/test-admin-table-sort.js` stays green unmodified.
 *
 * @see appWeb/public_html/js/modules/admin-table-sort.js  the multi-column
 *      `<table>` header sorter — imports makeCompare/multiKeyCompare FROM
 *      here rather than defining its own copy.
 * @see appWeb/public_html/js/modules/list-sort.js  the public card/list
 *      "Sort ▾" panel — imports everything in this file.
 * @see appWeb/public_html/includes/sort_helpers.php  ihymns_title_sort_key()
 *      — the PHP twin of titleSortKey()/SORT_ARTICLES below. The two
 *      article vocabularies are kept in lockstep by
 *      tests/test-list-sort.js's PHP⇄JS parity check, not by this comment
 *      (rule #35 — a "keep these in sync" comment is the failure, not the
 *      fix).
 */

/**
 * Build a typed comparator for ONE level. text / number / date are the
 * supported types (mirrors `data-sort-type` on the admin side and the
 * `type` key of a public list-sort option).
 *
 * VERBATIM from admin-table-sort.js (#1786 admin half, lines 68-86) — do not
 * let the two copies diverge; there is only one now.
 *
 * @param {string} type 'text' | 'number' | 'date'
 * @param {string} direction 'asc' | 'desc'
 * @returns {(a: string, b: string) => number}
 */
export function makeCompare(type, direction) {
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
 * Compare two rows across an ORDERED stack of sort levels — the admin
 * `<table>` shape: `keys` entries carry a column `index`, and `valsA`/
 * `valsB` are `{ [columnIndex]: string }` maps. VERBATIM from
 * admin-table-sort.js (#1786 admin half, lines 103-109).
 *
 * Walks the levels in priority order and returns the first non-zero
 * comparison; 0 only when every level ties (the caller then falls back to
 * original position for stability).
 *
 * @param {Array<{index:number,type:string,direction:string}>} keys Ordered levels.
 * @param {Object<number,string>} valsA Column-index → cell value for row A.
 * @param {Object<number,string>} valsB Column-index → cell value for row B.
 * @returns {number} <0, 0, >0
 */
export function multiKeyCompare(keys, valsA, valsB) {
    for (const k of keys) {
        const cmp = makeCompare(k.type, k.direction)(valsA[k.index] ?? '', valsB[k.index] ?? '');
        if (cmp !== 0) return cmp;
    }
    return 0;
}

/**
 * Compare two rows across an ORDERED stack of sort levels — the PUBLIC
 * card/list shape: levels are keyed by NAME (`k.key`, matching a
 * `data-sort-<key>` attribute) rather than a column index, and a level's
 * value may be genuinely ABSENT (the item never carried that attribute —
 * e.g. an unnumbered Misc song has no `data-sort-number`).
 *
 * §3.4 of the #1786 public-sort plan: "a missing attribute sorts after every
 * present value at that level, in BOTH directions" — the unnumbered tail
 * stays a tail whether Number is sorted ascending or descending, the same
 * way a writer-less song groups at the end of a Writer sort either way. This
 * is why missing-ness is decided BEFORE `makeCompare()`'s direction
 * multiplier ever runs: multiplying a "missing wins" sentinel by `dir` would
 * flip that promise to "missing sorts first on desc", which is not what any
 * user asked for.
 *
 * `null` AND `undefined` both count as "missing" — a decorator that read a
 * `data-sort-*` attribute via `getAttribute()` gets `null` when it's absent;
 * a plain object literal built by hand (favourites' array-mode comparator,
 * §6.3 of the plan) may simply omit the key, which reads as `undefined`.
 * Both must behave identically or the two adoption modes would silently
 * disagree about tie-break placement.
 *
 * @param {Array<{key:string,type:string,direction:string}>} keys Ordered levels.
 * @param {Object<string,?string>} valsA key → value (or null/undefined) for row A.
 * @param {Object<string,?string>} valsB key → value (or null/undefined) for row B.
 * @returns {number} <0, 0, >0
 */
export function multiKeyCompareMissingLast(keys, valsA, valsB) {
    for (const k of keys) {
        const a = valsA[k.key];
        const b = valsB[k.key];
        const aMissing = a === null || a === undefined;
        const bMissing = b === null || b === undefined;
        if (aMissing && bMissing) continue;      /* tie at this level — fall through */
        if (aMissing) return 1;                  /* A sorts after B, any direction */
        if (bMissing) return -1;                 /* B sorts after A, any direction */
        const cmp = makeCompare(k.type, k.direction)(a, b);
        if (cmp !== 0) return cmp;
    }
    return 0;
}

/**
 * The single leading-article vocabulary, shared with PHP's
 * `ihymns_title_sort_key()` (`includes/sort_helpers.php`). Kept as an
 * exported array (not baked into a regex string) so
 * `tests/test-list-sort.js` can diff it against the PHP source's own
 * alternation and fail the moment the two lists disagree (rule #35 — a
 * mechanism, not a "keep these in sync" comment).
 *
 * @see includes/sort_helpers.php  ihymns_title_sort_key() — the PHP twin.
 */
export const SORT_ARTICLES = ['the', 'a', 'an', 'o', 'el', 'la', 'le', 'les', 'der', 'die', 'das'];

const _ARTICLE_RE = new RegExp('^(' + SORT_ARTICLES.join('|') + ')\\s+');

/**
 * Client-side mirror of `ihymns_title_sort_key()`. Case-insensitive
 * alphabetical sort key with a single leading article removed.
 *
 * Used ONLY by array-mode surfaces (favourites, §6.3) where the sort runs in
 * the browser over already-downloaded data — DOM-mode surfaces sort by a
 * server-computed `data-sort-title` attribute instead (the PHP fold is
 * authoritative there; this JS fold is a client-only convenience for the one
 * surface with no server round-trip per sort).
 *
 * `String.prototype.toLowerCase()` is Unicode-aware, so this tracks
 * `mb_strtolower()` closely enough for a display sort key — it is not the
 * SQL `ORDER BY` (search's server-side sort, §6.4) which is deliberately
 * documented as non-article-aware (SongData.php's `TitleSortKey` follow-up).
 *
 * @param {string} title
 * @returns {string}
 */
export function titleSortKey(title) {
    let k = String(title ?? '').trim().toLowerCase();
    k = k.replace(_ARTICLE_RE, '');
    return k.trim();
}

/**
 * Validate + normalise a persisted/incoming sort spec against the keys a
 * control actually offers today.
 *
 * ELI5: throw away anything that doesn't make sense any more — a stale
 * saved key the control no longer has, a bad direction, too many levels —
 * rather than letting garbage reach the comparator. "A saved layout is a
 * wish, not a contract" (the same posture `cardLayoutMerge()` takes with a
 * saved card order, `includes/card_layout.php`).
 *
 * Rules (plan §3.2 / ⚑ N1):
 *   - not an array, or empty                → []  (means "Default")
 *   - each entry needs a `key` matching
 *     `/^[a-z0-9_-]{1,40}$/` AND present in
 *     `allowedKeys` (when given)            → entry dropped otherwise
 *   - `dir` must be 'asc' or 'desc'          → entry dropped otherwise
 *   - a key already used by an earlier
 *     level in the SAME spec                 → entry dropped (first wins;
 *                                               one key occupies one level)
 *   - more than `maxLevels` entries          → extras dropped (⚑ N1 caps at 3)
 *
 * PURE — no DOM, no storage — so it can validate a spec fetched from the
 * account store, one read from localStorage, or one built by the panel UI,
 * with the same rules every time.
 *
 * @param {unknown} spec Candidate spec — anything, including malformed JSON output.
 * @param {?string[]} allowedKeys Keys the CURRENT control actually offers,
 *                                or null/undefined to skip the allow-list
 *                                check (e.g. validating shape only).
 * @param {number} [maxLevels] ⚑ N1's cap. Defaults to 3.
 * @returns {Array<{key:string,dir:string}>}
 */
export function normalizeSortSpec(spec, allowedKeys, maxLevels = 3) {
    if (!Array.isArray(spec)) return [];
    const allowed = allowedKeys ? new Set(allowedKeys) : null;
    const seen = new Set();
    const out = [];
    for (const entry of spec) {
        if (out.length >= maxLevels) break;
        if (!entry || typeof entry !== 'object') continue;
        const key = typeof entry.key === 'string' ? entry.key : '';
        if (!/^[a-z0-9_-]{1,40}$/.test(key)) continue;
        const dir = entry.dir === 'desc' ? 'desc' : (entry.dir === 'asc' ? 'asc' : '');
        if (dir === '') continue;
        if (allowed && !allowed.has(key)) continue;
        if (seen.has(key)) continue;
        seen.add(key);
        out.push({ key, dir });
    }
    return out;
}
