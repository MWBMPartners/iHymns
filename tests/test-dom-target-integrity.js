/**
 * iHymns — DOM-target integrity for the public app
 *
 * ELI5
 * ----
 * Our JavaScript reaches for bits of the page by name ("give me the thing
 * called `search-input`"). This checks every one of those names is a thing
 * the page actually builds — and that every keyboard shortcut we advertise
 * to the user leads somewhere real.
 *
 * WHY THIS EXISTS — the silent no-op, again
 * -----------------------------------------
 * `document.getElementById('x')` returns `null` when nothing emits `id="x"`.
 * Every call site in this codebase then guards on it —
 * `if (el) {...}`, `if (!bar) return;` — which is correct defensive code and
 * ALSO the perfect disguise: the handler binds nothing, the shortcut does
 * nothing, no exception is thrown, nothing appears in the console. The page
 * renders, Bootstrap works, the feature is simply absent. That is the class
 * that killed the public Export feature for ~7 weeks (#1565, rule #30) and
 * the Settings language filter (#1581, rule #35).
 *
 * The instance that motivated this file: #812 removed the header search bar
 * ("the footer-nav already exposes a Search entry; one affordance is enough")
 * and updated the CSS to match — but not `js/modules/search.js`, which kept
 * looking up `#header-search-bar` / `#search-input` / `#header-search-toggle`
 * / `#search-clear-btn`. Those four ids have not existed since. The visible
 * consequence was NOT dead markup, it was two DEAD KEYBOARD SHORTCUTS: `/`
 * and `Ctrl`+`K`, both advertised as "Open search" in the shortcuts overlay
 * (js/modules/shortcuts.js) AND on the public help page
 * (includes/pages/help.php) — and both calling `toggleHeaderSearch()`, whose
 * first act is `if (!bar) return;`. `e.preventDefault()` ran first, so `/`
 * did not even type a slash. A documented promise, silently unkept.
 *
 * DERIVED, NOT TYPED (rule #34)
 * -----------------------------
 * Both assertions below build their subject list by walking the tree:
 * assertion 1 from every `getElementById`/`querySelector('#…')` in the public
 * JS, assertion 2 from the `<kbd>` rows the overlay itself renders. Add a new
 * lookup or a new advertised shortcut anywhere and it is covered with no edit
 * here — that is the whole point. Neither has a hand-maintained allowlist,
 * because an allowlist is where this class of bug goes to hide.
 *
 *   node tests/test-dom-target-integrity.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB = join(__dirname, '..', 'appWeb');
const PUB = join(WEB, 'public_html');

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
    try { entries = readdirSync(dir, { withFileTypes: true }); } catch { return out; }
    for (const e of entries) {
        if (e.name === 'vendor' || e.name === 'node_modules' || e.name.startsWith('.')) continue;
        const p = join(dir, e.name);
        if (e.isDirectory()) walk(p, re, out);
        else if (re.test(e.name)) out.push(p);
    }
    return out;
}

const rel = (p) => p.slice(PUB.length + 1);

/* ======================================================================
 * Assertion 1 — every id the public JS looks up is emitted somewhere
 * ==================================================================== */
console.log('\nAssertion 1 — every id public JS looks up is emitted somewhere in the tree:\n');

/* The HAYSTACK is deliberately the WHOLE web tree, not just the public
   fragments: a public module may legitimately be reused by an admin page that
   emits the id (js/modules/song-media-editor.js looks up `tab-media`, which
   manage/editor/index.php emits). Scoping the haystack to includes/ alone made
   this scanner report that as dead — a false positive, and a scanner that
   cries wolf gets deleted rather than fixed (rule #34). */
const haystackFiles = walk(WEB, /\.(php|js|html)$/);
/* Comments are stripped from the haystack, not just read past. A file that
   merely MENTIONS `id="foo"` in a doc-block would otherwise count as emitting
   it, and a genuinely dangling lookup for `#foo` would be waved through. That
   is not hypothetical: 23 ids in this tree exist only inside comments (mostly
   admin-nav route names quoted in prose). The sibling
   tests/test-song-nav-direction.js was caught by exactly this — its own
   explanatory comment satisfied the assertion the comment was describing. */
const haystack = haystackFiles
    .map((f) => readFileSync(f, 'utf8'))
    .join('\n')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/* Ids emitted literally: id="x" in markup, id="x" inside a JS template string
   (where the quotes may be escaped), and the two programmatic forms. */
const emitted = new Set();
for (const m of haystack.matchAll(/\bid\s*=\s*(?:\\?["'])([A-Za-z][\w:.-]*)(?:\\?["'])/g)) emitted.add(m[1]);
for (const m of haystack.matchAll(/\.id\s*=\s*['"`]([\w:.-]+)['"`]/g)) emitted.add(m[1]);
for (const m of haystack.matchAll(/setAttribute\(\s*['"]id['"]\s*,\s*['"`]([\w:.-]+)['"`]/g)) emitted.add(m[1]);

/* Ids built dynamically — `id="row-${i}"`, `id="card-<?= $x ?>"`. Only the
   static PREFIX is knowable, so record it and treat any lookup starting with
   one as satisfiable. Without this the scanner reports every templated id as
   dead, which is the under-reporting-in-reverse failure of rule #34. */
const dynPrefixes = [];
for (const m of haystack.matchAll(/\bid\s*=\s*\\?["'`]([\w-]*)(?:\$\{|<\?)/g)) if (m[1]) dynPrefixes.push(m[1]);

const jsFiles = walk(join(PUB, 'js'), /\.js$/);
const lookups = new Map();   /* id -> ["file:line", ...] */
for (const f of jsFiles) {
    readFileSync(f, 'utf8').split('\n').forEach((line, i) => {
        for (const m of line.matchAll(/getElementById\(\s*['"]([\w:.-]+)['"]\s*\)/g)) {
            if (!lookups.has(m[1])) lookups.set(m[1], []);
            lookups.get(m[1]).push(`${rel(f)}:${i + 1}`);
        }
        for (const m of line.matchAll(/querySelector(?:All)?\(\s*['"]#([\w:.-]+)['"]\s*\)/g)) {
            if (!lookups.has(m[1])) lookups.set(m[1], []);
            lookups.get(m[1]).push(`${rel(f)}:${i + 1}`);
        }
    });
}

/* A zero here would mean the scanner matched nothing and every assertion below
   is vacuously true — the "confident green that verifies nothing" failure. */
check(`scanner found id lookups to check (${lookups.size} distinct, across ${jsFiles.length} JS files)`,
    lookups.size > 50, `only found ${lookups.size}`);
check(`scanner found ids emitted by the tree (${emitted.size} literal, ${dynPrefixes.length} templated)`,
    emitted.size > 100, `only found ${emitted.size}`);

const dangling = [];
for (const [id, where] of [...lookups].sort()) {
    if (emitted.has(id)) continue;
    if (dynPrefixes.some((p) => p && id.startsWith(p))) continue;
    dangling.push([id, where]);
}
check('every id looked up by public JS is emitted somewhere',
    dangling.length === 0,
    dangling.map(([id, w]) => `#${id} <- ${w.join(', ')}`).join('\n        '));

/* ======================================================================
 * Assertion 2 — every advertised keyboard shortcut reaches a live handler
 * ==================================================================== */
console.log('\nAssertion 2 — every shortcut the overlay advertises has a dispatch case:\n');

const shortcutsSrc = readFileSync(join(PUB, 'js/modules/shortcuts.js'), 'utf8');
const appSrc = readFileSync(join(PUB, 'js/app.js'), 'utf8');

/* Each advertised row is `<dt>…<kbd>K</kbd>…</dt>` + `<dd>description</dd>`. */
const advertised = [];
for (const m of shortcutsSrc.matchAll(/<dt>([\s\S]*?)<\/dt>\s*<dd>([\s\S]*?)<\/dd>/g)) {
    const keys = [...m[1].matchAll(/<kbd>(.*?)<\/kbd>/g)].map((k) => k[1].trim());
    advertised.push({ keys, desc: m[2].trim().replace(/\s+/g, ' ') });
}
check(`parsed the advertised shortcut rows out of shortcuts.js (${advertised.length} rows)`,
    advertised.length >= 10, `only parsed ${advertised.length}`);

/* HTML notation -> KeyboardEvent.key. This is a NOTATION table, not a list of
   things to check: the rows come from the tree, and an unrecognised token
   FAILS below rather than being skipped, so the table cannot silently shrink
   this test's coverage.
   https://developer.mozilla.org/docs/Web/API/KeyboardEvent/key/Key_Values */
const KEY_NOTATION = {
    '&larr;': 'ArrowLeft', '&rarr;': 'ArrowRight',
    'Space': ' ', 'Esc': 'Escape', 'Enter': 'Enter', 'Backspace': 'Backspace',
};
/* Modifier names never appear as a `case` on their own. */
const MODIFIERS = new Set(['Ctrl', 'Cmd', 'Shift', 'Alt', 'Option']);

/* The digit range `<kbd>0</kbd>&ndash;<kbd>9</kbd>` is handled by app.js's
   explicit `e.key >= '0' && e.key <= '9'` range test, not by a case label. */
const hasDigitRange = /e\.key\s*>=\s*'0'\s*&&\s*e\.key\s*<=\s*'9'/.test(appSrc);

function dispatched(key) {
    if (MODIFIERS.has(key)) return true;
    const k = KEY_NOTATION[key] ?? key;
    if (/^[0-9]$/.test(k)) return hasDigitRange;
    /* `case 'f':` / `case 'F':` — accept either casing for a letter. */
    const variants = k.length === 1 ? [k.toLowerCase(), k.toUpperCase()] : [k];
    const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return variants.some((v) =>
        new RegExp(`case\\s+'${esc(v)}'\\s*:`).test(appSrc)
        /* A modified shortcut (Ctrl+K) gets its own listener with an equality
           test rather than a case label in the plain-key switch. */
        || new RegExp(`e\\.key\\s*===\\s*'${esc(v)}'`).test(appSrc));
}

for (const row of advertised) {
    check(`"${row.desc}" — ${row.keys.join(' ')} is dispatched`,
        row.keys.every(dispatched),
        `unhandled: ${row.keys.filter((k) => !dispatched(k)).join(', ')}`);
}

/* ======================================================================
 * Assertion 3 — "Open search" actually reaches the search page
 * ==================================================================== */
console.log('\nAssertion 3 — the "Open search" shortcut leads to the search page:\n');

/* Assertion 2 only proves a `case` exists. `/` HAD a case for as long as it was
   broken — it called toggleHeaderSearch(), which returned immediately because
   the bar #812 deleted was gone. So follow the handler one step further: find
   the Search method the `/` case invokes, and require that method to reference
   the /search route. #812's own comment names that route as the surviving
   affordance, so this is the contract, not a guess. */
/* Strip block comments BEFORE matching. A fixed character window between the
   case label and the call is unreliable for exactly the reason it looks safe:
   the first draft of this file used 400/300 chars and went red the moment the
   fix landed, because the fix added an explanatory comment between the two.
   The same mistake is recorded against test-editor-api2-contract.php (rule
   #34) — a window widened once is a window that will be too narrow again.
   Removing the comments removes the variable instead of guessing at it.       */
const appCode = appSrc.replace(/\/\*[\s\S]*?\*\//g, '');
/* Guard the strip itself: if it ate the code as well as the prose, every
   assertion below would fail for the wrong reason and send the next reader
   hunting a bug that is in this file. */
check('comment-stripping left app.js dispatch intact', /switch\s*\(\s*e\.key\s*\)/.test(appCode));

const slashCase = /case '\/':[\s\S]{0,800}?this\.search\.([A-Za-z_$][\w$]*)\s*\(/.exec(appCode);
check("the `/` case calls a method on the search module", !!slashCase,
    'no `this.search.<method>(...)` found within the `/` case');

const ctrlK = /\(\s*e\.ctrlKey\s*\|\|\s*e\.metaKey\s*\)[\s\S]{0,800}?this\.search\.([A-Za-z_$][\w$]*)\s*\(/.exec(appCode);
check('the Ctrl/Cmd+K listener calls a method on the search module', !!ctrlK,
    'no `this.search.<method>(...)` found in the Ctrl/Cmd+K listener');

/* Same reasoning as appCode above — and here it matters twice over, since the
   assertions look for `/search` and `header-search-bar` as SUBSTRINGS, and both
   appear in this module's prose. Matching against the comments would let a
   doc-block describing the fix stand in for the fix. */
const searchSrc = readFileSync(join(PUB, 'js/modules/search.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');
check('comment-stripping left search.js class body intact', /class Search/.test(searchSrc));

for (const [label, hit] of [['`/`', slashCase], ['Ctrl/Cmd+K', ctrlK]]) {
    if (!hit) continue;
    const method = hit[1];
    /* Grab the method body: from its declaration to the next method at the same
       indent. Bounded generously — a 120-char window silently truncated a
       sibling guard in this repo before (rule #34). */
    const body = new RegExp(`\\n    (?:async\\s+)?${method}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\n    \\}`).exec(searchSrc);
    check(`${label} -> Search.${method}() exists in search.js`, !!body);
    if (!body) continue;
    check(`${label} -> Search.${method}() navigates to the /search route (not a removed header bar)`,
        /['"`]\/search/.test(body[1]),
        `Search.${method}() never mentions /search — it is the #812 dead end again`);
    check(`${label} -> Search.${method}() does not depend on the #812-removed header bar`,
        !/header-search-bar/.test(body[1]),
        `Search.${method}() still looks up #header-search-bar, deleted in #812`);
}

/* ======================================================================
 * Assertion 4 — openSearch() BEHAVES, not just reads correctly
 * ==================================================================== */
/* Assertions 1-3 are structural: they prove the wiring names things that
   exist. They cannot prove the method does the right thing when run, and the
   bug this file exists for was precisely a method that read fine and did
   nothing. So run it, in a real DOM, both ways round.
   The now-removed search autocomplete (#307) showed why this matters: its
   ARIA test drove `_showAutocomplete()` directly against an
   `<input id="search-input">` it built itself, so it passed for the entire
   period during which no page anywhere emitted that id and no code ever called
   `_initAutocomplete` — the feature was unreachable the whole time. Exercising
   a function in isolation says nothing about whether the product can reach it;
   that dead cluster was deleted wholesale in #307. */
console.log('\nAssertion 4 — openSearch() navigates and focuses when actually run:\n');

const { JSDOM } = await import('jsdom');

async function runOpenSearch(startPath, { alreadyHasInput }) {
    const dom = new JSDOM('<!doctype html><html><body></body></html>',
        { url: `https://example.test${startPath}` });
    const { window } = dom;
    global.window = window;
    global.document = window.document;
    global.URL = window.URL;
    global.fetch = async () => ({ ok: true, json: async () => ({}) });
    window.fetch = global.fetch;
    global.localStorage = window.localStorage;

    const addInput = () => {
        const el = window.document.createElement('input');
        el.id = 'page-search-input';
        window.document.body.appendChild(el);
        return el;
    };
    if (alreadyHasInput) addInput();

    const navigated = [];
    const app = {
        config: { apiUrl: '/api' },
        router: {
            navigate: async (p) => {
                navigated.push(p);
                /* Mirror the real router: the fragment (and therefore the
                   input) only exists AFTER navigate() resolves. */
                addInput();
            },
        },
    };

    const { Search } = await import(pathToFileURL(join(PUB, 'js/modules/search.js')).href);
    const search = new Search(app);
    await search.openSearch();
    return { navigated, active: window.document.activeElement };
}

const fromHome = await runOpenSearch('/', { alreadyHasInput: false });
check('from another page, openSearch() navigates to /search',
    fromHome.navigated.length === 1 && fromHome.navigated[0] === '/search',
    `navigated to: ${JSON.stringify(fromHome.navigated)}`);
check('from another page, openSearch() focuses the search input once it exists',
    fromHome.active && fromHome.active.id === 'page-search-input',
    `focus landed on: ${fromHome.active ? fromHome.active.id || fromHome.active.tagName : 'nothing'}`);

/* The router early-returns when the path is unchanged, so on /search itself a
   navigate-then-focus implementation would focus nothing. This is the case a
   user hits every time they press the shortcut twice. */
const onSearch = await runOpenSearch('/search', { alreadyHasInput: true });
check('already on /search, openSearch() does NOT re-navigate',
    onSearch.navigated.length === 0,
    `navigated to: ${JSON.stringify(onSearch.navigated)}`);
check('already on /search, openSearch() still focuses the input',
    onSearch.active && onSearch.active.id === 'page-search-input',
    `focus landed on: ${onSearch.active ? onSearch.active.id || onSearch.active.tagName : 'nothing'}`);

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nAn id nothing emits makes every guarded handler a silent no-op: the page');
    console.error('renders, nothing throws, and the feature is simply absent. Emit the id, or');
    console.error('stop looking it up — and if a shortcut is advertised to the user, make sure');
    console.error('the thing it calls still leads somewhere.');
    process.exit(1);
}
console.log('\nAll DOM-target integrity assertions passed.');
