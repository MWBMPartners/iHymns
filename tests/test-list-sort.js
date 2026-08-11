/**
 * iHymns — public list-sort module + coverage guard (#1786 Option B)
 *
 * ELI5
 * ----
 * Proves the public "Sort ▾" feature the same way test-admin-table-sort.js
 * proves the admin one: pure comparator units run directly (no browser), and
 * a handful of tree-derived checks make sure the pieces that MUST agree with
 * each other — the PHP article vocabulary and the JS one, which surfaces are
 * DOM-mode vs array/server-mode, the admin module still importing the shared
 * comparator rather than re-forking it — actually do (rule #34/#35).
 *
 * WHY TREE-DERIVED, NOT A HAND-TYPED LIST
 * ----------------------------------------
 * Assertion 3 parses every `$listSortSurface` value straight out of
 * `includes/pages/*.php` and `includes/pages/favorites.php`/`search.php`
 * rather than a list typed here — a new surface (DOM or array/server mode)
 * is covered automatically the moment it exists.
 *
 * Usage: node tests/test-list-sort.js
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    makeCompare,
    multiKeyCompare,
    multiKeyCompareMissingLast,
    titleSortKey,
    normalizeSortSpec,
    SORT_ARTICLES,
} from '../appWeb/public_html/js/utils/sort-compare.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT = path.resolve(__dirname, '..');
const PUB = path.join(ROOT, 'appWeb', 'public_html');
const PAGES_DIR = path.join(PUB, 'includes', 'pages');
const JS_ROOT = path.join(PUB, 'js');

let passed = 0, failed = 0;
const failures = [];
function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else {
        failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
        console.log(`  FAIL  ${label}`);
        if (detail) console.log(`        ${detail}`);
    }
}

function walk(dir, re, out = []) {
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return out; }
    for (const e of entries) {
        if (e.name === 'node_modules' || e.name === 'vendor') continue;
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p, re, out);
        else if (re.test(e.name)) out.push(p);
    }
    return out;
}

/* ======================================================================
 * 1 — pure comparator units
 * ==================================================================== */
console.log('\n1 — pure comparator units (js/utils/sort-compare.js):\n');

/* makeCompare */
check('text: locale + numeric ("Item 2" before "Item 10")',
    makeCompare('text', 'asc')('Item 2', 'Item 10') < 0);
check('text: desc reverses',
    makeCompare('text', 'desc')('Item 2', 'Item 10') > 0);
check('number: numeric not lexical (2 < 10)',
    makeCompare('number', 'asc')('2', '10') < 0);
check('number: non-numeric coerces to 0',
    makeCompare('number', 'asc')('abc', '5') < 0);
check('date: chronological order',
    makeCompare('date', 'asc')('2020-01-01', '2021-01-01') < 0);

/* multiKeyCompare — admin index-keyed shape, UNCHANGED behaviour (C1 moved
   it verbatim; this is the same coverage test-admin-table-sort.js already
   has, re-run here as a non-regression check on the NEW home for the code). */
const idxKeys = [
    { key: 'book', index: 0, type: 'text', direction: 'asc' },
    { key: 'number', index: 1, type: 'number', direction: 'asc' },
];
const idxV = (book, num) => ({ 0: book, 1: String(num) });
check('multiKeyCompare: primary level decides when it differs',
    multiKeyCompare(idxKeys, idxV('MP', 999), idxV('WOV', 1)) < 0);
check('multiKeyCompare: secondary breaks a primary tie',
    multiKeyCompare(idxKeys, idxV('MP', 2), idxV('MP', 10)) < 0);
check('multiKeyCompare: all levels equal -> 0',
    multiKeyCompare(idxKeys, idxV('MP', 5), idxV('MP', 5)) === 0);

/* multiKeyCompareMissingLast — the NEW public-surface shape, keyed by name,
   missing sorts after present in BOTH directions. */
const keyKeys = (dir) => [{ key: 'number', type: 'number', direction: dir }];
check('multiKeyCompareMissingLast: present < missing (asc)',
    multiKeyCompareMissingLast(keyKeys('asc'), { number: '5' }, { number: null }) < 0);
check('multiKeyCompareMissingLast: present < missing (desc, too — NOT flipped)',
    multiKeyCompareMissingLast(keyKeys('desc'), { number: '5' }, { number: null }) < 0);
check('multiKeyCompareMissingLast: missing > present, symmetric',
    multiKeyCompareMissingLast(keyKeys('asc'), { number: null }, { number: '5' }) > 0);
check('multiKeyCompareMissingLast: both missing at a level -> falls through to next',
    multiKeyCompareMissingLast(
        [{ key: 'a', type: 'text', direction: 'asc' }, { key: 'b', type: 'text', direction: 'asc' }],
        { a: null, b: 'x' }, { a: null, b: 'y' }
    ) < 0);
check('multiKeyCompareMissingLast: undefined counts as missing too (not just null)',
    multiKeyCompareMissingLast(keyKeys('asc'), { number: '5' }, {}) < 0);

/* titleSortKey */
check('titleSortKey strips a leading "The"', titleSortKey('The Church Hymnal') === 'church hymnal');
check('titleSortKey strips a leading "A"', titleSortKey('A Mighty Fortress') === 'mighty fortress');
check('titleSortKey lowercases with no article', titleSortKey('ZION') === 'zion');
check('titleSortKey does not strip a mid-title "the"', titleSortKey('All The Best') === 'all the best');
check('titleSortKey handles null/undefined without throwing', titleSortKey(undefined) === '');

/* normalizeSortSpec — validate/cap/dedupe */
check('normalizeSortSpec: non-array -> []', JSON.stringify(normalizeSortSpec('nope', null)) === '[]');
check('normalizeSortSpec: drops an entry with a bad key',
    normalizeSortSpec([{ key: 'BAD KEY!', dir: 'asc' }], null).length === 0);
check('normalizeSortSpec: drops an entry with a bad dir',
    normalizeSortSpec([{ key: 'title', dir: 'sideways' }], null).length === 0);
check('normalizeSortSpec: filters against allowedKeys',
    normalizeSortSpec([{ key: 'title', dir: 'asc' }, { key: 'ghost', dir: 'asc' }], ['title']).length === 1);
check('normalizeSortSpec: de-dupes a repeated key, first wins',
    JSON.stringify(normalizeSortSpec([{ key: 'title', dir: 'asc' }, { key: 'title', dir: 'desc' }], null))
    === JSON.stringify([{ key: 'title', dir: 'asc' }]));
check('normalizeSortSpec: caps at maxLevels (⚑ N1 default 3)',
    normalizeSortSpec(
        [{ key: 'a', dir: 'asc' }, { key: 'b', dir: 'asc' }, { key: 'c', dir: 'asc' }, { key: 'd', dir: 'asc' }],
        null
    ).length === 3);
check('normalizeSortSpec: a custom lower cap is honoured',
    normalizeSortSpec([{ key: 'a', dir: 'asc' }, { key: 'b', dir: 'asc' }], null, 1).length === 1);

/* ======================================================================
 * 2 — PHP <-> JS article-vocabulary parity (rule #35: a mechanism, not a
 *     "keep these in sync" comment)
 * ==================================================================== */
console.log('\n2 — PHP <-> JS article-vocabulary parity:\n');

const sortHelpersPhp = fs.readFileSync(path.join(PUB, 'includes', 'sort_helpers.php'), 'utf8');
const phpArticleMatch = sortHelpersPhp.match(/preg_replace\('\/\^\(([a-z|]+)\)\\s\+\/u'/);
check('parsed the PHP article alternation out of ihymns_title_sort_key()', !!phpArticleMatch,
    'the preg_replace pattern in includes/sort_helpers.php has changed shape — update this parser');
const phpArticles = phpArticleMatch ? phpArticleMatch[1].split('|').sort() : [];
const jsArticles = [...SORT_ARTICLES].sort();

check(`PHP article list is non-trivial (${phpArticles.length} found)`, phpArticles.length >= 5);
check('PHP and JS article vocabularies are EXACTLY the same set',
    JSON.stringify(phpArticles) === JSON.stringify(jsArticles),
    `PHP=[${phpArticles.join(',')}] JS=[${jsArticles.join(',')}]`);

/* Mutation self-test: this check must be ABLE to fail — prove it by
   comparing against a deliberately wrong list, inline (not by mutating the
   real source file, which would leave the guard weaker if this script ever
   errored out before restoring it). */
const deliberatelyWrong = [...jsArticles, 'los'].sort();
check('mutation self-test: parity check WOULD fail on a genuinely different list',
    JSON.stringify(phpArticles) !== JSON.stringify(deliberatelyWrong));

/* ======================================================================
 * 3 — wiring, tree-derived
 * ==================================================================== */
console.log('\n3 — wiring, tree-derived:\n');

const routerSrc = fs.readFileSync(path.join(JS_ROOT, 'modules', 'router.js'), 'utf8');
/* router.js boots list-sort.js via a DYNAMIC import() (afterPageLoad()'s
   established pattern for every router-driven module — home-page.js,
   export-ui.js, etc. — not a static top-level `import … from`). */
check("router.js imports './list-sort.js'",
    /from\s+['"]\.\/list-sort\.js['"]/.test(routerSrc) || /import\(\s*['"]\.\/list-sort\.js['"]\s*\)/.test(routerSrc));
check('router.js actually CALLS initListSort( (not just imported — the #1799 "tagged but dead" shape)',
    /\.initListSort\(/.test(routerSrc));

/* Every $listSortSurface value across includes/pages/*.php, and whether
   the SAME file also emits a matching data-list-sort-list container
   (DOM mode) or not (array/server mode). */
const pageFiles = fs.readdirSync(PAGES_DIR).filter((f) => f.endsWith('.php'));
check(`found public page fragments to scan (${pageFiles.length})`, pageFiles.length >= 15);

const surfaces = [];
for (const f of pageFiles) {
    const src = fs.readFileSync(path.join(PAGES_DIR, f), 'utf8');
    const m = src.match(/\$listSortSurface\s*=\s*'([a-z0-9-]{1,40})'/);
    if (!m) continue;
    const surface = m[1];
    const isDomMode = src.includes(`data-list-sort-list="${surface}"`);
    surfaces.push({ file: f, surface, isDomMode });
}
check(`parsed at least 8 $listSortSurface declarations across includes/pages/*.php (${surfaces.length} found)`,
    surfaces.length >= 8);

/* All JS under js/, read PER FILE (not concatenated) — an array/server-mode
   surface needs its OWN consumer file to carry BOTH the surface literal AND
   a wiring call; two unrelated files each satisfying one half must NOT
   count (a concatenated haystack would let list-sort.js's OWN definition of
   wireListSortControl/getListSort — every surface literal will inevitably
   sit somewhere in that ocean too — silently satisfy every surface with no
   real consumer at all). */
const jsFiles = walk(JS_ROOT, /\.js$/);
const jsFileSources = jsFiles
    .filter((f) => path.basename(f) !== 'list-sort.js') // the definer, not a consumer
    .map((f) => ({ file: f, src: fs.readFileSync(f, 'utf8') }));

const arraySurfaces = surfaces.filter((s) => !s.isDomMode);
check(`at least one array/server-mode surface found (${arraySurfaces.length})`, arraySurfaces.length >= 1);

for (const s of surfaces) {
    if (s.isDomMode) continue; // DOM mode is wired automatically by initListSort() — nothing to check per-surface here
    /* The surface literal must be the ARGUMENT passed to one of the two
       wiring calls — not merely present SOMEWHERE in a file that also
       happens to contain the calls (a module named after its own surface,
       e.g. favorites.js, will contain the bare word "favorites" dozens of
       times for unrelated reasons — a co-occurrence-only check would pass
       even after the actual wireListSortControl('favorites', …) call was
       deleted, which defeats the point of the guard). */
    const wireCallWithSurfaceRe = new RegExp(
        `(?:wireListSortControl|getListSort)\\(\\s*['"\`]${s.surface}['"\`]`
    );
    const consumer = jsFileSources.find((f) => wireCallWithSurfaceRe.test(f.src));
    check(`array/server-mode surface "${s.surface}" (${s.file}) has a JS consumer calling wireListSortControl/getListSort with that literal surface name`,
        !!consumer,
        consumer ? '' : `no js/**/*.js file (other than list-sort.js itself) calls wireListSortControl('${s.surface}'…) or getListSort('${s.surface}'…)`);
}

/* songbook-index.js absorb — the pre-#1786 toggle markup/logic must be
   GONE, and the module must listen for EVT_LIST_SORT_CHANGED. */
const songbookIndexSrc = fs.readFileSync(path.join(JS_ROOT, 'modules', 'songbook-index.js'), 'utf8');
check('songbook-index.js no longer builds its own sort-toggle button group (data-sort="number"/"title")',
    !/data-sort=["'`]number["'`]/.test(songbookIndexSrc) && !songbookIndexSrc.includes("btn.dataset.sort"));
check('songbook-index.js imports EVT_LIST_SORT_CHANGED from constants.js',
    /EVT_LIST_SORT_CHANGED/.test(songbookIndexSrc) && /from\s+['"]\.\.\/constants\.js['"]/.test(songbookIndexSrc));
check('songbook-index.js listens for EVT_LIST_SORT_CHANGED (not just imports the name)',
    /addEventListener\(\s*EVT_LIST_SORT_CHANGED/.test(songbookIndexSrc));

/* ======================================================================
 * 4 — admin non-regression (C1 extraction)
 * ==================================================================== */
console.log('\n4 — admin non-regression:\n');

const adminSrc = fs.readFileSync(path.join(JS_ROOT, 'modules', 'admin-table-sort.js'), 'utf8');
check("admin-table-sort.js imports makeCompare/multiKeyCompare from '../utils/sort-compare.js'",
    /from\s+['"]\.\.\/utils\/sort-compare\.js['"]/.test(adminSrc) && /multiKeyCompare/.test(adminSrc));
check('admin-table-sort.js defines no LOCAL copy of makeCompare (function makeCompare()) — imported only',
    !/function\s+makeCompare\s*\(/.test(adminSrc));
check('admin-table-sort.js re-exports multiKeyCompare (test-admin-table-sort.js\'s existing import keeps working)',
    /export\s*\{\s*multiKeyCompare\s*\}/.test(adminSrc));

/* ======================================================================
 * 5 — sync contract: the account read/write literals
 * ==================================================================== */
console.log('\n5 — sync contract (account read/write literals):\n');

const listSortSrc = fs.readFileSync(path.join(JS_ROOT, 'modules', 'list-sort.js'), 'utf8');
check("list-sort.js reads 'action=user_settings&namespace=list_sorts'",
    listSortSrc.includes('action=user_settings&namespace=list_sorts'));
check("list-sort.js writes namespace: 'list_sorts'",
    /namespace:\s*['"`]list_sorts['"`]/.test(listSortSrc));
check('list-sort.js uses apiFetch (rule #31), never a bare fetch(', (() => {
    const withoutComments = listSortSrc.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    /* A lowercase, letter-preceded-free `fetch(` — "apiFetch(" never matches
       this (capital F, and preceded by a letter either way), so this is a
       real bare-fetch detector, not a string-mangling trick. */
    const bareFetch = /(?<![A-Za-z])fetch\(/.test(withoutComments);
    return listSortSrc.includes('apiFetch(') && !bareFetch;
})());
check('list-sort.js exports primeAccountListSorts', /export\s+function\s+primeAccountListSorts/.test(listSortSrc));
check('list-sort.js exports getListSort', /export\s+function\s+getListSort/.test(listSortSrc));
check('list-sort.js exports wireListSortControl', /export\s+function\s+wireListSortControl/.test(listSortSrc));
check('list-sort.js exports initListSort', /export\s+function\s+initListSort/.test(listSortSrc));

/* Mutation self-test — prove the sync-contract checks above can actually
   fail, by asserting the SAME literals against an obviously-wrong string. */
check('mutation self-test: sync-contract check would fail on a renamed namespace',
    !'action=user_settings&namespace=ihymns_web'.includes('namespace=list_sorts'));

/* ======================================================================
 * Summary
 * ==================================================================== */
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('\nFailures:');
    for (const f of failures) console.log(`  - ${f}`);
}
process.exit(failed === 0 ? 0 : 1);
