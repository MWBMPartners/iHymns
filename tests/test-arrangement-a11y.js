/**
 * iHymns — setlist Arrangement editor accessibility guard (#1644)
 *
 * ELI5: this test reads the Arrangement editor's source code (not a
 * browser) and checks that every chip you can see is a REAL button that
 * a keyboard can activate, that reordering has a path that doesn't need
 * a mouse, and that a screen-reader user is told what just happened.
 *
 * WHY this had to be fixed (see #1644 for the full report): the modal
 * built by `_showArrangementModal()` in setlist.js rendered every chip
 * as `<span role="button" tabindex="0">`. A <span> is focusable via
 * tabindex and LABELLED "button" via role, but it has no native
 * activation behaviour — only genuinely interactive elements
 * (<button>, <a href>, …) get a synthesised click from Enter/Space.
 * Every "button" in the editor was therefore reachable by Tab and
 * completely inert on Enter/Space: add, reorder, remove all silently
 * did nothing for a keyboard-only user. Separately, reordering was
 * wired ONLY through the HTML5 Drag and Drop API, which never fires
 * from touch input on mobile browsers — so a worship leader arranging
 * a song on their phone (the feature's whole reason to exist) could
 * not reorder anything either. Two independent inert paths to the same
 * feature, neither of which raised an error or a console warning —
 * exactly the silent-no-op class #1565/#1581/#1533 already document in
 * this codebase, and exactly what a source-level test is for.
 *
 * DESIGN NOTE — comments vs. markup: this file strips `//` and
 * `/* *\/` comments out of the slice under test before pattern-matching
 * (see `stripComments()` below), while leaving string / template
 * literal content — i.e. the actual HTML the browser renders — intact.
 * Skipping this step is a real trap: an EXPLANATORY comment in
 * setlist.js legitimately says things like `<span role="button">` while
 * describing what the OLD, now-removed code looked like, and a naive
 * substring/regex check against raw source would trip on that comment
 * and report the bug as still present. Two other test suites written in
 * this same session were bitten by exactly this before switching to a
 * comment-aware check — see the identical design note + implementation
 * in tests/test-api-client-usage.js's `stripCommentsAndStrings()` (that
 * version also blanks strings, which this file must NOT do, because the
 * markup it needs to inspect lives inside those strings).
 *
 * USAGE:
 *   node tests/test-arrangement-a11y.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const SETLIST_JS = path.join(__dirname, '..', 'appWeb', 'public_html', 'js', 'modules', 'setlist.js');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* ========================================================================
 * Comment stripper — see the file header's design note for WHY this
 * exists and why it must leave string/template content untouched.
 *
 * A small character-level state machine rather than a regex: a regex
 * that tries to match "not inside a string" at the same time as
 * "looks like a comment" is exactly the kind of thing that quietly
 * breaks on the first edge case (a `//` inside a URL string, a `/*`
 * inside a template literal). Walking the source one character at a
 * time and tracking which of a small set of states we're in ('code' /
 * 'block' comment / 'line' comment / single-quote / double-quote /
 * template string) has no such blind spot.
 * ====================================================================== */
function stripComments(src) {
    let out = '';
    let i = 0;
    const n = src.length;
    /** @type {'code'|'block'|'line'|'sq'|'dq'|'tpl'} */
    let state = 'code';

    while (i < n) {
        const c  = src[i];
        const c2 = src[i + 1];

        if (state === 'code') {
            if (c === '/' && c2 === '*') { state = 'block'; i += 2; continue; }
            if (c === '/' && c2 === '/') { state = 'line';  i += 2; continue; }
            if (c === "'") { state = 'sq';  out += c; i += 1; continue; }
            if (c === '"') { state = 'dq';  out += c; i += 1; continue; }
            if (c === '`') { state = 'tpl'; out += c; i += 1; continue; }
            out += c;
            i += 1;
            continue;
        }

        if (state === 'block') {
            if (c === '*' && c2 === '/') { state = 'code'; i += 2; continue; }
            out += (c === '\n') ? '\n' : '';
            i += 1;
            continue;
        }

        if (state === 'line') {
            if (c === '\n') { state = 'code'; out += '\n'; i += 1; continue; }
            i += 1;
            continue;
        }

        /* sq / dq / tpl — quoted content is copied through VERBATIM
           (this is the difference from test-api-client-usage.js's
           stripper, which blanks strings too). Escaped characters are
           consumed as a pair so an escaped quote can never look like
           the closing delimiter. */
        const closers = { sq: "'", dq: '"', tpl: '`' };
        const closer = closers[state];
        if (c === '\\') { out += c + (c2 ?? ''); i += 2; continue; }
        if (c === closer) { state = 'code'; out += c; i += 1; continue; }
        out += c;
        i += 1;
    }

    return out;
}

/* ------------------------------------------------------------------------
 * Self-test — proves the stripper can both fire (catch a real match) and
 * stay silent (ignore the same text inside a comment). A guard that can't
 * fail is worthless (#1581 precedent, restated in test-setlist-playback.js
 * and test-api-client-usage.js).
 * ---------------------------------------------------------------------- */
console.log('Assertion 0 — comment stripper self-test:');

check('a match inside real code survives stripping',
    stripComments('const x = `<span role="button">`;').includes('role="button"'));

check('the SAME text inside a // comment is removed',
    !stripComments('// old markup used <span role="button"> here\nconst x = 1;').includes('role="button"'));

check('the SAME text inside a /* block */ comment is removed',
    !stripComments('/* old markup used <span role="button"> here */\nconst x = 1;').includes('role="button"'));

check('a // inside a string is NOT treated as a comment start',
    stripComments('const url = "https://example.com/x";').includes('https://example.com/x'));

/* ========================================================================
 * Load + scope to the arrangement-editor region only, so assertions
 * about "no role=button" etc. can't accidentally pass or fail because
 * of unrelated code elsewhere in this 2500+ line module.
 * ====================================================================== */

const fullSrc = fs.readFileSync(SETLIST_JS, 'utf8');

const START_MARKER = '_showArrangementModal(listId, songId, songData, arrangement) {';
const END_MARKER   = 'setSongArrangement(listId, songId, arrangement, arrLabel = null) {';

const startIdx = fullSrc.indexOf(START_MARKER);
const endIdx   = fullSrc.indexOf(END_MARKER, startIdx);

check('found the arrangement-editor region markers (source shape check)',
    startIdx !== -1 && endIdx !== -1 && endIdx > startIdx,
    `startIdx=${startIdx} endIdx=${endIdx}`);

const regionRaw     = fullSrc.slice(startIdx, endIdx);
const region        = stripComments(regionRaw);   /* comments gone, markup intact */

/* ========================================================================
 * Assertion 1 — no role="button" / tabindex="0" left on the chips
 * ====================================================================== */
console.log('\nAssertion 1 — pool and strip chips are real buttons, not role-played spans:');

check('no role="button" remains in the arrangement-editor markup',
    !region.includes('role="button"'));

check('no tabindex="0" remains in the arrangement-editor markup',
    !region.includes('tabindex="0"'));

check('the pool chip is a real <button>',
    /<button type="button" class="badge rounded-pill arrangement-pool-chip/.test(region));

check('the strip chip is a real <button>',
    /<button type="button" class="badge rounded-pill arrangement-strip-chip/.test(region));

/* ========================================================================
 * Assertion 2 — move-left / move-right controls exist AND are wired
 * ====================================================================== */
console.log('\nAssertion 2 — move-left / move-right buttons exist in markup and have handlers:');

check('arrangement-chip-move-left exists in markup',
    region.includes('arrangement-chip-move-left'));

check('arrangement-chip-move-right exists in markup',
    region.includes('arrangement-chip-move-right'));

check('arrangement-chip-move-left has a bound click handler',
    /querySelectorAll\('\.arrangement-chip-move-left'\)[\s\S]{0,200}addEventListener\('click'/.test(region));

check('arrangement-chip-move-right has a bound click handler',
    /querySelectorAll\('\.arrangement-chip-move-right'\)[\s\S]{0,200}addEventListener\('click'/.test(region));

check('ArrowLeft/ArrowRight on a focused chip also moves it (additive keyboard convenience)',
    /ArrowLeft/.test(region) && /ArrowRight/.test(region) && /preventDefault\(\)/.test(region));

/* ========================================================================
 * Assertion 3 — the remove control is a SIBLING, never nested inside
 * the chip's own tag (WCAG 4.1.2 — interactive-inside-interactive)
 * ====================================================================== */
console.log('\nAssertion 3 — remove control is a sibling, not nested inside the chip:');

const chipMarker = 'class="badge rounded-pill arrangement-strip-chip border-0"';
const chipMarkerIdx = region.indexOf(chipMarker);
check('located the strip chip button in the stripped region', chipMarkerIdx !== -1);

if (chipMarkerIdx !== -1) {
    const openTagStart = region.lastIndexOf('<button', chipMarkerIdx);
    const closeTagEnd   = region.indexOf('</button>', chipMarkerIdx) + '</button>'.length;
    const chipTemplate  = region.slice(openTagStart, closeTagEnd);

    check('the strip chip <button>...</button> template does NOT contain arrangement-chip-remove',
        !chipTemplate.includes('arrangement-chip-remove'),
        `chip template: ${chipTemplate.slice(0, 160)}...`);

    check('the strip chip <button>...</button> template does NOT contain arrangement-chip-move-left',
        !chipTemplate.includes('arrangement-chip-move-left'));

    check('the strip chip <button>...</button> template does NOT contain arrangement-chip-move-right',
        !chipTemplate.includes('arrangement-chip-move-right'));
} else {
    /* Marker missing is already reported above; skip the dependent
       sub-checks rather than throwing on a null slice. */
    failed += 3;
    failures.push('remove/move nesting checks skipped — chip marker not found');
}

/* ========================================================================
 * Assertion 4 — the move path re-renders AND re-focuses (the "single
 * most important detail" per the #1644 brief: renderStrip() destroys
 * and recreates every node, so skipping the re-focus strands a
 * keyboard user at the top of the modal after every successful move)
 * ====================================================================== */
console.log('\nAssertion 4 — moving a chip re-renders the strip and restores focus:');

const moveFnIdx = region.indexOf('const moveStripEntry = ');
check('moveStripEntry() is defined', moveFnIdx !== -1);

const moveFnChunk = moveFnIdx !== -1 ? region.slice(moveFnIdx, moveFnIdx + 1200) : '';

check('moveStripEntry() calls renderStrip()', /renderStrip\(\)/.test(moveFnChunk));

check('moveStripEntry() calls .focus() AFTER renderStrip() (order matters — focusing before the ' +
    're-render would just focus a node about to be destroyed)',
    (() => {
        const renderAt = moveFnChunk.indexOf('renderStrip()');
        const focusAt  = moveFnChunk.indexOf('.focus()');
        return renderAt !== -1 && focusAt !== -1 && focusAt > renderAt;
    })());

check('moveStripEntry() re-queries the chip by its NEW position before focusing (data-pos="${newPos}")',
    /data-pos="\$\{newPos\}"/.test(moveFnChunk) && /\.focus\(\)/.test(moveFnChunk));

/* ========================================================================
 * Assertion 5 — every mutating action announces itself to screen readers
 * ====================================================================== */
console.log('\nAssertion 5 — add, move and remove all call _announce():');

const poolClickIdx = region.indexOf("querySelectorAll('.arrangement-pool-chip')");
const poolClickChunk = poolClickIdx !== -1 ? region.slice(poolClickIdx, poolClickIdx + 400) : '';
check('the "add from pool" handler calls _announce(', /_announce\(/.test(poolClickChunk));

check('moveStripEntry() calls _announce(', /_announce\(/.test(moveFnChunk));

const removeClickIdx = region.indexOf("querySelectorAll('.arrangement-chip-remove')");
const removeClickChunk = removeClickIdx !== -1 ? region.slice(removeClickIdx, removeClickIdx + 1000) : '';
check('the remove handler calls _announce(', /_announce\(/.test(removeClickChunk));

check('the remove handler ALSO restores focus after renderStrip() (same #1644 pitfall as move)',
    /\.focus\(\)/.test(removeClickChunk));

/* ========================================================================
 * Assertion 6 — the stale "drag to reorder" instruction is gone, and
 * drag-and-drop itself is preserved as an enhancement
 * ====================================================================== */
console.log('\nAssertion 6 — stale drag-only copy is gone; drag-and-drop survives as an enhancement:');

check('the old "Drag to reorder, click × to remove" title text is gone',
    !region.includes('Drag to reorder, click × to remove'));

check('_initStripDragDrop is still defined',
    /_initStripDragDrop\(strip, workingArr, components, renderStrip, renderPreview\) \{/.test(fullSrc));

check('_initStripDragDrop is still called from renderStrip() (drag kept as a bonus, not deleted)',
    /this\._initStripDragDrop\(strip, workingArr, components, renderStrip, renderPreview\);/.test(region));

check('the strip chip keeps draggable="true" (drag survives as a pointer-only enhancement)',
    /draggable="true"/.test(region));

/* ------------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll arrangement-editor accessibility assertions passed.');
