/**
 * iHymns — /search typeahead suggestion dropdown wiring (#1936 — rebuild of #307)
 *
 * ELI5
 * ----
 * The /search box shows a little drop-down of matching song titles as you
 * type, and picking one jumps straight to that song. This checks that the
 * drop-down is really wired up — and, above all, that it is REACHABLE by a
 * user, not "built but connected to nothing".
 *
 * WHY THIS GUARD EXISTS (the #307 lesson)
 * ----------------------------------------
 * #307 shipped a full autocomplete — initialiser, endpoint, CSS, and a green
 * ARIA test — where the ONE thing missing was the single line that connected
 * it to a search box. `_initAutocomplete` had zero callers; the test built its
 * own DOM and drove the private method directly, so it passed for the entire
 * period no page ever ran the feature. The whole cluster was deleted as dead
 * code and #307 closed as superseded. #1936 is the owner-directed fresh
 * rebuild against the CURRENT /search page — so the load-bearing assertion
 * here is the reachability chain #307 lacked:
 *
 *     router.js  →  Search.initSearchPage()  →  _initSuggest()  →  the panel
 *
 * Every link is derived from the tree and asserted; break any one and this
 * goes red (mutation-proven — see the header of each section).
 *
 * WHAT ELSE IT GUARDS
 * --------------------
 *  - the suggestion fetch REUSES `?action=search` at a LOW limit via apiFetch
 *    (rule #31) — never a revived `?action=suggest` endpoint (which stays
 *    deleted, #307 removal commit 3e5e2a46);
 *  - rows are real `<a href="/song/…" data-navigate="song">` so app.js's
 *    document-level `[data-navigate]` delegator navigates on pick;
 *  - keyboard + ARIA are DELEGATED to the shared combobox-a11y helper
 *    (`handleComboboxKeydown` + `applyComboboxAria`), not re-hand-rolled
 *    (modularity rule #22) — gated by `isOpen()` so it coexists with the
 *    #1903 results-list nav (that half is guarded by
 *    tests/test-search-keyboard-nav.js);
 *  - the panel is a child of the FORM, not `document.body`, so it dies with
 *    the SPA fragment on navigation (no stranded fixed/absolute overlay —
 *    rule #32);
 *  - Enter is NOT hijacked to the first suggestion (`active = -1` at render),
 *    so Enter still runs the full search (the #812 Enter-to-search fix).
 *
 * Source-shape guard (no jsdom): asserts the wiring is present in the source,
 * kept narrow enough not to fail on correct code (rule #34).
 *
 *   node tests/test-search-typeahead.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUB = join(__dirname, '..', 'appWeb', 'public_html');

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

/* Strip block comments before ANY assertion reads the source — this file's
   own history note (and search.js's) mentions `?action=suggest`, `combobox`,
   `data-navigate` etc., which would otherwise let a reverted fix report green
   off a doc-comment. search.js is block-comment-only (verified: zero `//`
   line comments — same as tests/test-search-keyboard-nav.js relies on), so
   stripping `/* … *\/` is sufficient. */
function stripComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Brace-balanced `{ … }` block from the FIRST `{` at/after anchorRegex.
 *  Brace-counting, not a fixed window (rule #34's recorded "window too narrow"
 *  lesson). anchorRegex must NOT be global. */
function extractBlock(src, anchorRegex) {
    const m = anchorRegex.exec(src);
    if (!m) return null;
    const openIdx = src.indexOf('{', m.index);
    if (openIdx === -1) return null;
    let depth = 0;
    for (let i = openIdx; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) return src.slice(m.index, i + 1);
        }
    }
    return null;
}

const searchJs = stripComments(readFileSync(join(PUB, 'js/modules/search.js'), 'utf8'));
const routerJs = stripComments(readFileSync(join(PUB, 'js/modules/router.js'), 'utf8'));

/* ------------------------------------------------------------------ *
 * 1 — REACHABILITY CHAIN: router → initSearchPage → _initSuggest → panel
 *     (the exact link #307 was missing)
 * ------------------------------------------------------------------ */
console.log('\n1 — reachability chain (the #307 "built, reachable from nowhere" lesson)\n');

check('router.js calls Search.initSearchPage() (the SPA entry point runs on /search)',
    /\.initSearchPage\s*\(\s*\)/.test(routerJs),
    'router.js must invoke initSearchPage — without a caller the whole page wiring is dead (#307)');

const initPageBody = extractBlock(searchJs, /initSearchPage\s*\(\s*\)\s*\{/);
check('initSearchPage() body was extracted (brace-balanced)', !!initPageBody);
if (initPageBody) {
    check('initSearchPage() CALLS this._initSuggest(...) — the dropdown is mounted from the page init',
        /this\._initSuggest\s*\(/.test(initPageBody),
        'the #307 failure was an initialiser with zero callers; _initSuggest MUST be called here');
    check('initSearchPage() feeds the dropdown from the debounced input (this._fetchSuggestions)',
        /this\._fetchSuggestions\s*\(/.test(initPageBody),
        'the mounted dropdown must actually be fed a query, or it is inert like #307 was');
}

const initSuggestBody = extractBlock(searchJs, /_initSuggest\s*\([^)]*\)\s*\{/);
check('_initSuggest() body was extracted (brace-balanced)', !!initSuggestBody);
if (initSuggestBody) {
    check('_initSuggest() appends the panel to the FORM (not document.body → no rule #32 stranded overlay)',
        /form\.appendChild\s*\(\s*panel\s*\)/.test(initSuggestBody)
        && !/document\.body\.appendChild/.test(initSuggestBody),
        'body-appended fixed/absolute overlays must tear down on every nav (rule #32); a form child dies with the fragment');
    check('_initSuggest() wires dismissal on blur',
        /addEventListener\s*\(\s*['"]blur['"]/.test(initSuggestBody));
}

/* ------------------------------------------------------------------ *
 * 2 — the fetch REUSES ?action=search at a low limit (no ?action=suggest)
 * ------------------------------------------------------------------ */
console.log('\n2 — suggestion fetch reuses ?action=search (low limit), never a revived ?action=suggest\n');

const fetchBody = extractBlock(searchJs, /_fetchSuggestions\s*\([^)]*\)\s*\{/);
check('_fetchSuggestions() body was extracted (brace-balanced)', !!fetchBody);
if (fetchBody) {
    check('_fetchSuggestions() uses apiFetch (rule #31 — not bare fetch / not a window.fetch patch)',
        /apiFetch\s*\(/.test(fetchBody));
    check('_fetchSuggestions() targets action=search',
        /set\s*\(\s*['"]action['"]\s*,\s*['"]search['"]\s*\)/.test(fetchBody));
    check('_fetchSuggestions() uses a LOW limit (8), not the 50-row page size',
        /set\s*\(\s*['"]limit['"]\s*,\s*['"]8['"]\s*\)/.test(fetchBody));
    check('_fetchSuggestions() asks for titles only (lyrics=0) for a quick-jump',
        /set\s*\(\s*['"]lyrics['"]\s*,\s*['"]0['"]\s*\)/.test(fetchBody));
    check('_fetchSuggestions() has a stale-response guard (monotonic seq)',
        /seq\s*!==\s*s\.seq/.test(fetchBody),
        'an out-of-order response from an earlier keystroke must not clobber a newer query');
}

check('the deleted ?action=suggest endpoint is NOT revived anywhere in search.js',
    !/set\s*\(\s*['"]action['"]\s*,\s*['"]suggest['"]\s*\)/.test(searchJs)
    && !/action=suggest/.test(searchJs),
    '#307 removed ?action=suggest + SongData::suggestSongs(); #1936 reuses ?action=search instead');

/* ------------------------------------------------------------------ *
 * 3 — rows are real navigable anchors; Enter is not hijacked
 * ------------------------------------------------------------------ */
console.log('\n3 — rows navigate via [data-navigate]; Enter still runs the full search\n');

const renderBody = extractBlock(searchJs, /_renderSuggestions\s*\([^)]*\)\s*\{/);
check('_renderSuggestions() body was extracted (brace-balanced)', !!renderBody);
if (renderBody) {
    check('suggestion rows are real anchors to /song/ (natively navigable)',
        /\/song\//.test(renderBody) && /<a href=/.test(renderBody));
    check('suggestion rows carry data-navigate="song" (app.js delegator navigates on pick)',
        /data-navigate="song"/.test(renderBody),
        'without this the row is a dead link — the navigate-on-pick contract is the delegator in app.js');
    check('render starts with NO highlight (active = -1), so Enter runs the full search, not the top suggestion',
        /s\.active\s*=\s*-1/.test(renderBody),
        'a preselected first row would hijack Enter away from the #812 Enter-to-search fix');
}

/* ------------------------------------------------------------------ *
 * 4 — keyboard + ARIA delegated to the shared combobox-a11y helper
 * ------------------------------------------------------------------ */
console.log('\n4 — keyboard + ARIA reuse the shared combobox-a11y helper (rule #22), gated by isOpen()\n');

check('search.js imports the shared combobox-a11y helper for its side effect',
    /import\s+['"]\.\/combobox-a11y\.js['"]\s*;/.test(searchJs));

const keyBody = extractBlock(searchJs, /_handleSuggestKeydown\s*\([^)]*\)\s*\{/);
check('_handleSuggestKeydown() body was extracted (brace-balanced)', !!keyBody);
if (keyBody) {
    check('_handleSuggestKeydown() delegates to iHymnsComboboxA11y.handleComboboxKeydown (not a hand-rolled arrow ladder)',
        /handleComboboxKeydown\s*\(/.test(keyBody));
    check('_handleSuggestKeydown() gates on isOpen() so it is a no-op when the dropdown is closed (coexists with #1903)',
        /isOpen\s*:/.test(keyBody));
    check('_handleSuggestKeydown() commits a pick by replaying the row click (el.click()), not a second nav path',
        /onCommit\s*:/.test(keyBody) && /\.click\s*\(\s*\)/.test(keyBody),
        'commit === click the row → app.js [data-navigate] delegator does the navigation (rule #35 one mechanism)');
}

check('the dropdown applies combobox ARIA via applyComboboxAria (aria-activedescendant / aria-expanded / role=option)',
    /applyComboboxAria\s*\(/.test(searchJs),
    'the visual highlight is CSS-only; a screen reader needs the ARIA the shared helper paints');

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nThe /search typeahead suggestion dropdown (#1936) is not fully wired.');
    console.error('See js/modules/search.js `_initSuggest()` and the reachability chain from router.js.');
    process.exit(1);
}
console.log('\nAll search-typeahead (#1936) assertions passed.');
