/**
 * iHymns — /search results keyboard navigation (#1903 item 2)
 *
 * ELI5
 * ----
 * On the search page, pressing the down arrow while typing should jump your
 * keyboard focus into the list of results, and arrow up/down should then move
 * between them. This file checks that wiring is really there in the source —
 * not just that the feature was described somewhere.
 *
 * THE SHAPE THIS GUARDS
 * ----------------------
 * `js/modules/search.js` binds TWO keydown listeners in `initSearchPage()`:
 *   1. On `#page-search-input` — ArrowDown focuses the FIRST `.song-list-item`
 *      result anchor (only when one exists).
 *   2. DELEGATED on `#text-search-results` (the results container, `results`
 *      in source) — ArrowDown/ArrowUp walk between `.song-list-item` anchors,
 *      BOUNDED (no wrap): ArrowUp from the first row returns focus to the
 *      input; ArrowDown from the last row stays put.
 *
 * The container listener MUST be delegated (bound once on the container,
 * never per-row) because `performSearch()` replaces `results.innerHTML` —
 * and therefore every `.song-list-item` row — on every search
 * (`_renderResultItems()` builds fresh `<a>` nodes each time). A listener
 * bound directly to a row would be discarded with it on the very next
 * search; a listener bound to the container (which `performSearch()` never
 * replaces, only repopulates) survives. This is the same "the persistent
 * node is the one to delegate from" shape as #1533's fixed-bar teardown and
 * #1786's re-render-safe sort control, applied to keyboard focus instead of
 * DOM removal.
 *
 * `preventDefault()` in every arrow branch is load-bearing: an unprevented
 * ArrowDown both scrolls the page AND moves focus, which is the classic
 * half-broken outcome (rule #34) — it "does something" so a casual smoke
 * test misses that it did the wrong thing too.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT CHECK
 * --------------------------------------------
 * Home/End handling (the plan marks it optional) and full DOM behavioural
 * focus-walking (no jsdom here) — kept narrow per rule #34 ("a guard so
 * blunt it fails on correct code... gets weakened or deleted rather than
 * fixed"). This is a source-shape guard, not a browser-behaviour test.
 *
 *   node tests/test-search-keyboard-nav.js
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

/* Comments stripped before ANY assertion reads the source — a doc-comment
   that quotes 'ArrowDown'/'preventDefault' (as the one atop the real fix
   does) would otherwise let a reverted fix still report green. Same
   rationale as tests/test-search-enter-submit.js and
   tests/php/test-fragment-inline-scripts.php. search.js uses only block
   comments (verified: zero `//` line comments in the file), so stripping
   `/* ... *\/` is sufficient here. */
const searchJs = readFileSync(join(PUB, 'js/modules/search.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/**
 * Extract a brace-balanced `{ ... }` block starting at the FIRST `{` at or
 * after `anchorRegex`'s match. Brace-counting (rather than a fixed-length
 * window) is deliberate: `test-editor-api2-contract.php` needed its window
 * widened from 120 to 300 chars against real source (rule #34's own
 * recorded lesson) — counting braces instead of guessing a length can't be
 * "too narrow" the same way.
 *
 * @param {string} src
 * @param {RegExp} anchorRegex Must NOT have the global flag.
 * @returns {string|null} The block including its outer braces, or null.
 */
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

console.log('\n1 — the results container is the delegation point, not individual rows\n');

check('the results container is looked up as #text-search-results',
    /getElementById\(\s*['"]text-search-results['"]\s*\)/.test(searchJs));

const resultsHandlerAnchor = /results\.addEventListener\(\s*['"]keydown['"]\s*,\s*\(e\)\s*=>\s*\{/;
check('a keydown listener is bound on the results container variable',
    resultsHandlerAnchor.test(searchJs));

const resultsHandlerBody = extractBlock(searchJs, resultsHandlerAnchor);
check('the results keydown handler body was extracted (brace-balanced)', !!resultsHandlerBody);

/* Rows are rendered fresh every search — `_renderResultItems()` returns an
   HTML STRING (`insertAdjacentHTML`/`container.innerHTML =`), so it cannot
   itself call `addEventListener` on a row; that is the structural guarantee
   that rows can't carry per-row listeners. Confirm the render function
   really is string-based and never attaches a listener directly. */
const renderAnchor = /_renderResultItems\s*\([^)]*\)\s*\{/;
const renderBody = extractBlock(searchJs, renderAnchor);
check('found _renderResultItems() to confirm it builds rows as HTML, not live nodes',
    !!renderBody);
if (renderBody) {
    check('_renderResultItems() never binds a listener directly to a row (would not survive a re-render)',
        !/addEventListener/.test(renderBody),
        'a per-row addEventListener here would be discarded on every performSearch() re-render');
}

console.log('\n2 — the delegated handler handles BOTH ArrowDown and ArrowUp\n');

if (resultsHandlerBody) {
    check("the handler's key guard covers ArrowDown",
        /['"]ArrowDown['"]/.test(resultsHandlerBody));
    check("the handler's key guard covers ArrowUp",
        /['"]ArrowUp['"]/.test(resultsHandlerBody));
    check('the handler delegates via closest(), not a per-row reference',
        /closest\s*\(\s*['"]\.song-list-item['"]\s*\)/.test(resultsHandlerBody));
}

console.log('\n3 — preventDefault() fires in EVERY arrow branch (not just one)\n');

if (resultsHandlerBody) {
    /* Split the delegated handler into its two branches so a preventDefault
       removed from EITHER ONE is caught — asserting a single "contains
       preventDefault somewhere" over the whole handler would stay green if
       only one of the two calls were deleted, which is exactly the kind of
       under-reporting rule #34 warns about. */
    const downBranchAnchor = /if\s*\(\s*e\.key\s*===\s*['"]ArrowDown['"]\s*\)\s*\{/;
    const downBranch = extractBlock(resultsHandlerBody, downBranchAnchor);
    check('found the delegated handler\'s ArrowDown branch', !!downBranch);
    if (downBranch) {
        check('ArrowDown branch calls preventDefault()',
            /preventDefault\s*\(\s*\)/.test(downBranch));
        check('ArrowDown branch moves to the NEXT row (no wrap-around math)',
            /rows\[\s*idx\s*\+\s*1\s*\]/.test(downBranch)
            && !/%\s*rows\.length/.test(downBranch));
    }

    const upBranchStart = downBranch ? resultsHandlerBody.indexOf(downBranch) + downBranch.length : -1;
    const upBranch = upBranchStart >= 0 ? resultsHandlerBody.slice(upBranchStart) : null;
    check('found the delegated handler\'s ArrowUp (else) branch', !!upBranch);
    if (upBranch) {
        check('ArrowUp branch calls preventDefault()',
            /preventDefault\s*\(\s*\)/.test(upBranch));
        check('ArrowUp branch moves to the PREVIOUS row (no wrap-around math)',
            /rows\[\s*idx\s*-\s*1\s*\]/.test(upBranch)
            && !/%\s*rows\.length/.test(upBranch));
        check('ArrowUp from the first row returns focus to the input (bounded, not wrapped)',
            /input\.focus\s*\(\s*\)/.test(upBranch));
    }
}

console.log('\n4 — ArrowDown on the search input focuses the first result row\n');

const inputHandlerAnchor = /input\.addEventListener\(\s*['"]keydown['"]\s*,\s*\(e\)\s*=>\s*\{/;
check('a keydown listener is bound on the search input',
    inputHandlerAnchor.test(searchJs));

const inputHandlerBody = extractBlock(searchJs, inputHandlerAnchor);
check('the input keydown handler body was extracted (brace-balanced)', !!inputHandlerBody);

if (inputHandlerBody) {
    check('the input handler checks specifically for ArrowDown (not every key)',
        /e\.key\s*!==\s*['"]ArrowDown['"]/.test(inputHandlerBody)
        || /e\.key\s*===\s*['"]ArrowDown['"]/.test(inputHandlerBody));
    check('the input handler looks up a result row before acting (.song-list-item)',
        /\.song-list-item/.test(inputHandlerBody));
    check('the input handler calls preventDefault()',
        /preventDefault\s*\(\s*\)/.test(inputHandlerBody));
    check('the input handler focuses the row it found',
        /\.focus\s*\(\s*\)/.test(inputHandlerBody));
}

console.log('\n5 — the #1903 results-list nav COEXISTS with the #1936 typeahead dropdown (not replaced by it)\n');

/* HISTORY: this section once asserted `!combobox-a11y` — "the helper was
   removed as dead code in #812 and must stay out". #1936 deliberately reverses
   that: the /search page now has a REAL typeahead suggestion dropdown that
   legitimately uses the shared combobox helper (owner-directed rebuild of
   #307). So the invariant this section guards changed from "no combobox at
   all" to "the two keyboard models COEXIST": the #1903 results-list
   ArrowDown-into-results branch is PRESERVED and gated behind the dropdown's
   route-through, so it still runs whenever the dropdown is closed. The full
   #1936 wiring (fetch, render, navigate-on-pick, reachability) is guarded by
   tests/test-search-typeahead.js; this file stays about the #1903 nav. */

check('the FULL results rows are still real <a href> elements (Enter opens them natively)',
    /<a href="\/song\/\$\{escapeHtml\(song\.id\)\}"/.test(searchJs));

check('combobox-a11y is now imported (the #1936 typeahead dropdown uses the shared helper)',
    /import\s+['"]\.\/combobox-a11y\.js['"]\s*;/.test(searchJs),
    '#1936 rebuilt the /search typeahead on the shared combobox helper — the old `!combobox-a11y` ban no longer applies');

if (inputHandlerBody) {
    check('the input handler gives the #1936 dropdown FIRST refusal, then falls through to #1903',
        /_handleSuggestKeydown\s*\(\s*e\s*\)\s*\)\s*return/.test(inputHandlerBody),
        'the combobox must consume open-dropdown keys (return early) BEFORE the #1903 ArrowDown-into-results branch runs');
    /* Prove the #1903 branch is genuinely still THERE and after the
       route-through, not that the route-through swallowed it: the input
       handler must still focus a result row on ArrowDown when the dropdown is
       closed. §4 already asserts the .song-list-item + focus() parts; here we
       assert the ORDER — the route-through appears before the row focus. */
    const routeThroughIdx = inputHandlerBody.search(/_handleSuggestKeydown\s*\(\s*e\s*\)/);
    const rowFocusIdx = inputHandlerBody.search(/firstRow\.focus\s*\(\s*\)/);
    check('the #1936 route-through comes BEFORE the #1903 results-list focus (open dropdown wins the key)',
        routeThroughIdx >= 0 && rowFocusIdx >= 0 && routeThroughIdx < rowFocusIdx);
}

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    console.error('\nSearch results keyboard navigation (#1903 item 2) is not fully wired.');
    console.error('See js/modules/search.js `initSearchPage()` for the ArrowDown/ArrowUp bindings.');
    process.exit(1);
}
console.log('\nAll search-keyboard-nav assertions passed.');
