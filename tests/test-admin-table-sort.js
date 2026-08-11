/**
 * iHymns — multi-column table sort core (#1786)
 *
 * ELI5
 * ----
 * Proves the two pieces of the new "sort by X, then by Y" behaviour without a
 * browser: (1) the ORDERED multi-level comparator breaks ties by falling
 * through to the next level, and (2) a plain click replaces the sort while a
 * shift-click ADDS a level (and cycles/removes it), so the "then by …" chain
 * builds and clears the way a user expects.
 *
 * WHY pure-logic tests (rule #34): the DOM wiring is thin; the ORDERING and the
 * click-state-machine are where a regression would silently mis-sort a table.
 * Both are exported pure functions, so they are exercised directly and each
 * assertion is mutation-proven (break the logic → a check goes red).
 *
 *   node tests/test-admin-table-sort.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { multiKeyCompare, nextStack } from '../appWeb/public_html/js/modules/admin-table-sort.js';

let passed = 0, failed = 0;
const failures = [];
function check(label, cond) {
    if (cond) { passed++; console.log(`  ✅ ${label}`); }
    else { failed++; failures.push(label); console.log(`  ❌ ${label}`); }
}

console.log('\n#1786 — multi-column table sort core\n');

/* ---- 1. multiKeyCompare: ordered levels, tie-break fall-through ---------- */
/* Two levels: songbook (col 0, text, asc) THEN number (col 1, number, asc). */
const keys = [
    { key: 'book',   index: 0, type: 'text',   direction: 'asc' },
    { key: 'number', index: 1, type: 'number', direction: 'asc' },
];
const v = (book, num) => ({ 0: book, 1: String(num) });

check('primary level decides when it differs (MP < WOV regardless of number)',
    multiKeyCompare(keys, v('MP', 999), v('WOV', 1)) < 0);
check('secondary level breaks a primary tie (MP-2 before MP-10, numeric)',
    multiKeyCompare(keys, v('MP', 2), v('MP', 10)) < 0);
check('all levels equal ⇒ 0 (caller keeps original order = stable)',
    multiKeyCompare(keys, v('MP', 5), v('MP', 5)) === 0);
check('a desc secondary reverses ONLY the tie-break',
    multiKeyCompare(
        [keys[0], { ...keys[1], direction: 'desc' }],
        v('MP', 2), v('MP', 10)) > 0);
check('numeric type does not sort "10" before "2" as strings would',
    multiKeyCompare([keys[1]], v('x', 10), v('x', 2)) > 0);
check('single-level compare still works (back-compat with #644)',
    multiKeyCompare([keys[0]], v('AM', 1), v('ZZ', 1)) < 0);

/* ---- 2. nextStack: plain-click replaces, shift-click adds/cycles -------- */
const H = (key, index) => ({ key, type: 'text', index });
const book = H('book', 0), num = H('number', 1), title = H('title', 2);

/* plain click on empty → sole asc */
let s = nextStack([], book, false);
check('plain click on empty → [book asc]', s.length === 1 && s[0].key === 'book' && s[0].direction === 'asc');

/* plain click same col → cycle asc→desc */
s = nextStack(s, book, false);
check('plain click again → book desc', s.length === 1 && s[0].direction === 'desc');

/* plain click same col again → desc→none (empty) */
s = nextStack(s, book, false);
check('plain click a third time → cleared', s.length === 0);

/* build a two-level stack via shift-click */
s = nextStack([], book, false);          // [book asc]
s = nextStack(s, num, true);             // + number asc (additive)
check('shift-click adds a SECOND level, keeping the first',
    s.length === 2 && s[0].key === 'book' && s[1].key === 'number' && s[1].direction === 'asc');

/* shift-click the second level cycles ITS direction, leaving the first */
s = nextStack(s, num, true);
check('shift-click the 2nd level cycles only it (book untouched)',
    s.length === 2 && s[0].direction === 'asc' && s[1].direction === 'desc');

/* shift-click the second level once more removes just that level */
s = nextStack(s, num, true);
check('shift-click the 2nd level to desc→none removes only that level',
    s.length === 1 && s[0].key === 'book');

/* a PLAIN click while a multi-level stack exists resets to sole */
s = nextStack([], book, false);
s = nextStack(s, num, true);             // [book, number]
s = nextStack(s, title, false);          // plain click title → sole
check('plain click with a multi-level stack resets to that ONE column',
    s.length === 1 && s[0].key === 'title' && s[0].direction === 'asc');

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll multi-column table-sort assertions passed.');
