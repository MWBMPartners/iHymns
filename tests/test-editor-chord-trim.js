/**
 * iHymns — editor chords textarea RIGHT-trim guard, v1 + v2 (#1968 P6, commit C5)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved. Proprietary.
 *
 * PURPOSE
 * -------
 * `manage/editor/editor.js`'s per-line chords textarea (#1094) used to split its value on
 * newlines and `.trim()` EVERY line before storing it back onto `comp.chords`. A stored chord
 * cell is a POSITIONED STRING (#299/#1094/`includes/chord_display.php`'s canonical display
 * semantic, and — since #1968 P6 — PP7's own import/export shape too, plan §2.2): its LEADING
 * whitespace IS the first chord's column, not incidental padding. `.trim()` silently destroyed
 * that column on every keystroke — opening the box for a PP7-imported line whose first chord sits
 * at column 12 ("            G") and typing anywhere in it would save the line back as "G"
 * (column 0), corrupting the positioned cell with no warning, no error, nothing to grep. The fix
 * is a RIGHT-trim only (`l.replace(/\s+$/, '')`) — trailing whitespace stays incidental (nothing
 * renders past the last chord) and is still stripped.
 *
 * v2 EDITOR HAS THE SAME BUG (discovered during this commit's authoring, not in the original
 * plan's file list — `.claude/propresenter-chords-plan.md` §7's C5 row names only editor.js):
 * `manage/editor/v2/structure-tab.js`'s own chords UI (#1627 item 1, whose own comment says
 * it "mirrors v1's card layout order") is a SEPARATE, independent implementation of the identical
 * UI pattern — not a shared module (CLAUDE.md's modularity rule flags this duplication, but
 * splitting it into one shared control is a larger refactor out of scope here) — and carried the
 * exact same `l.trim()` bug. Since v2 is the current/primary editor surface, leaving it unfixed
 * would mean #1968 P6's own PP7-import chord support ships alongside a still-live corruption path
 * in the editor curators actually use day to day, so it is fixed in this SAME commit rather than
 * left for a separate one. Its own "clear semantics" logic (`rows.some((r) => r !== '')`, which
 * decides whether an all-blank set of rows clears `comp.chords` to `[]`) is UNAFFECTED by switching
 * to right-trim-only — a row that is entirely whitespace still right-trims to `''` (every
 * character IS trailing whitespace), so that decision only changes for rows with a REAL leading
 * gap before their first chord, exactly the case this fix is FOR.
 *
 * #1263 UPDATE — v2's chords UI stopped being ONE shared multiline textarea (one line of chords
 * per lyric line, newline-split) and became ONE `<input>` PER lyric line (the chord-drift-on-
 * reorder fix: a lines-parallel `chords` array survives a reorder by staying content-matched to
 * its line, not index-matched, via the new `remapChordsOnLinesChange()` — see
 * tests/test-structure-chords-remap.js). The right-trim transform itself is UNCHANGED in shape —
 * still `l.replace(/\s+$/, '')` — only its SOURCE changed, from `chordsArea.value.split('\n')` to
 * `chordRowInputs.map((inp) => inp.value...)`; the v2 extractor below is re-pointed at that new
 * statement and calls the extracted transform against a `{value: …}` stand-in for one `<input>`
 * element instead of a raw string, mirroring what `onChordRowInput()` actually does per row.
 *
 * WHY A SOURCE-LEVEL EXTRACTION, NOT A FULL DOM TEST
 * -----------------------------------------------------
 * Neither file is built for isolated `vm`-sandbox loading (unlike `propresenter-export.js`,
 * purpose-built with a public API for exactly that) — `editor.js` (~6800 lines) is a bare
 * non-IIFE classic script with top-level `document.addEventListener('DOMContentLoaded', ...)`
 * execution, and `structure-tab.js` is an ES module with its own module-level DOM assumptions.
 * Pulling either whole file into a sandbox just to reach one anonymous `addEventListener` closure
 * buried inside a component-rendering loop would need a DOM stub disproportionate to a one-line
 * regex fix. Instead this mirrors `tests/test-propresenter-export.js`'s own "buildBulkFiles()
 * source contains a setTimeout(...) macrotask yield" technique: find the EXACT function body by
 * brace-depth matching directly in the source text, then EXECUTE that extracted body (via `new
 * Function`) against real input — so this test runs the REAL deployed transform, not a hand-typed
 * stand-in of what it's supposed to do. v1's `.map()` callback is a classic `function (l) { ... }`
 * (brace-delimited); v2's is a brace-less single-expression arrow `(inp) => inp.value.replace(...)`,
 * so each gets its OWN syntax-appropriate extractor below rather than forcing one generic parser
 * onto two different JS forms.
 *
 * MUTATION-PROVEN (rule #34), each performed once against the real working tree during authoring,
 * this test re-run and confirmed RED, then reverted (`git diff --stat` empty before moving on):
 *   - restored the pre-fix `l.trim()` in editor.js -> its "preserves LEADING whitespace" row went
 *     RED (a positioned cell's leading padding was stripped, exactly the corruption this guard
 *     exists to catch).
 *   - restored the pre-fix `l.trim()` in structure-tab.js -> its "preserves LEADING whitespace"
 *     row went RED the same way, independently (proving this guard actually checks BOTH files,
 *     not just one and assuming the other matches). Re-confirmed again post-#1263, against the
 *     new `chordRowInputs.map((inp) => inp.value.trim())` shape.
 *
 * Usage: node tests/test-editor-chord-trim.js
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

let pass = 0, fail = 0;
function test(name, fn) {
    try { fn(); console.log(`  ✓ ${name}`); pass++; }
    catch (err) { console.error(`  ✗ ${name}\n      ${err && err.message}`); fail++; }
}

/* Brace-depth-bound extraction from an anchor string to its own closing `}` — the SAME technique
   test-propresenter-export.js's "buildBulkFiles() source contains a setTimeout..." test already
   uses on a different file, for the same reason: extract the REAL body text so a mutation to the
   deployed code has something concrete to turn this test red against. */
function extractBraceBody(source, anchor, label) {
    const anchorIdx = source.indexOf(anchor);
    assert.ok(anchorIdx !== -1, `could not find "${anchor}" (${label})`);
    const openIdx = source.indexOf('{', anchorIdx + anchor.length - 1);
    let depth = 0;
    let endIdx = -1;
    for (let i = openIdx; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) { endIdx = i; break; }
        }
    }
    assert.ok(endIdx !== -1, `could not brace-match "${anchor}" (${label})`);
    return source.slice(openIdx + 1, endIdx);
}

/* Extract one COMPLETE STATEMENT starting at `anchor` and running to its own terminating `;` at
   paren-depth 0 (parens/brackets tracked so a `;` inside e.g. a regex or nested call doesn't
   truncate early) — for a brace-less single-expression arrow like
   `const rows = chordsArea.value.split('\n').map((l) => l.replace(/\s+$/, ''));`. */
function extractStatement(source, anchor, label) {
    const anchorIdx = source.indexOf(anchor);
    assert.ok(anchorIdx !== -1, `could not find "${anchor}" (${label})`);
    let depth = 0;
    let endIdx = -1;
    for (let i = anchorIdx; i < source.length; i++) {
        const ch = source[i];
        if (ch === '(' || ch === '[') depth++;
        else if (ch === ')' || ch === ']') depth--;
        else if (ch === ';' && depth === 0) { endIdx = i; break; }
    }
    assert.ok(endIdx !== -1, `could not find a statement-terminating ";" for "${anchor}" (${label})`);
    return source.slice(anchorIdx, endIdx);
}

/* Extract a PAREN-DEPTH-BOUND call expression starting at `anchor` (which must end in an opening
   "(") through to ITS OWN matching close paren — e.g. anchor "l.replace(" -> returns
   "l.replace(/\s+$/, '')" — never grabbing a SUBSEQUENT enclosing call's closing paren the way a
   naive "first )" regex would. */
function extractCallExpression(source, anchor, label) {
    const anchorIdx = source.indexOf(anchor);
    assert.ok(anchorIdx !== -1, `could not find "${anchor}" (${label})`);
    const openIdx = anchorIdx + anchor.length - 1; // the "(" the anchor itself ends with
    assert.equal(source[openIdx], '(', `anchor "${anchor}" (${label}) must end with "("`);
    let depth = 0;
    let endIdx = -1;
    for (let i = openIdx; i < source.length; i++) {
        if (source[i] === '(') depth++;
        else if (source[i] === ')') {
            depth--;
            if (depth === 0) { endIdx = i; break; }
        }
    }
    assert.ok(endIdx !== -1, `could not find the matching ")" for "${anchor}" (${label})`);
    return source.slice(anchorIdx, endIdx + 1);
}

console.log('v1 (editor.js):');
{
    const editorSrc = fs.readFileSync(
        path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'editor.js'), 'utf8'
    );
    const listenerBody = extractBraceBody(
        editorSrc, "chordsArea.addEventListener('input', function () {", 'v1 input listener'
    );

    test('v1: the chords textarea input listener does NOT call l.trim() (the #1968 P6 corruption bug)', () => {
        assert.ok(!/\.map\(\s*function\s*\(\s*l\s*\)\s*\{\s*return\s+l\.trim\(\)/.test(listenerBody),
            'found the pre-fix l.trim() pattern — this destroys a positioned chord cell\'s leading column');
    });
    test('v1: the chords textarea input listener uses a RIGHT-trim-only regex (\\s+$)', () => {
        assert.match(listenerBody, /l\.replace\(\s*\/\\s\+\$\/\s*,\s*''\s*\)/,
            'expected l.replace(/\\s+$/, \'\') — a right-trim-only transform');
    });

    const mapCallbackBody = extractBraceBody(listenerBody, 'function (l) {', 'v1 .map() callback');
    // eslint-disable-next-line no-new-func
    const trimFn = new Function('l', mapCallbackBody);

    test('v1: EXECUTING the extracted real transform — preserves LEADING whitespace (a positioned column)', () => {
        assert.equal(trimFn('            G'), '            G',
            'a chord positioned at column 12 must keep its leading padding');
    });
    test('v1: EXECUTING the extracted real transform — strips TRAILING whitespace (incidental)', () => {
        assert.equal(trimFn('G        D   '), 'G        D');
    });
    test('v1: EXECUTING the extracted real transform — preserves INTERIOR whitespace exactly', () => {
        assert.equal(trimFn('G                   D'), 'G                   D');
    });
    test('v1: EXECUTING the extracted real transform — an all-whitespace line collapses to empty', () => {
        assert.equal(trimFn('   '), '');
    });
    test('v1: EXECUTING the extracted real transform — an already-clean line is untouched', () => {
        assert.equal(trimFn('C G Am'), 'C G Am');
    });
    test('v1: EXECUTING the extracted real transform — an empty line stays empty', () => {
        assert.equal(trimFn(''), '');
    });
}

console.log('\nv2 (structure-tab.js):');
{
    const v2Src = fs.readFileSync(
        path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'structure-tab.js'), 'utf8'
    );
    /* #1263 — re-pointed from the retired single-textarea statement
       (`const rows = chordsArea.value.split(...)`) at the per-line handler's
       equivalent: one chord <input> per lyric line, read via
       chordRowInputs.map(...) instead of one shared textarea's newline-split
       value. Same extraction technique (extractStatement, brace/paren-depth
       bound), same anchor SHAPE (`const rows = …`), new source expression. */
    const statement = extractStatement(
        v2Src, 'const rows = chordRowInputs.map', 'v2 chords rows statement'
    );

    test('v2: the chords rows statement does NOT call inp.value.trim() (the #1968 P6 corruption bug)', () => {
        assert.ok(!/\(inp\)\s*=>\s*inp\.value\.trim\(\)/.test(statement),
            'found the pre-fix inp.value.trim() pattern — this destroys a positioned chord cell\'s leading column');
    });
    test('v2: the chords rows statement uses a RIGHT-trim-only regex (\\s+$)', () => {
        assert.match(statement, /\(inp\)\s*=>\s*inp\.value\.replace\(\s*\/\\s\+\$\/\s*,\s*''\s*\)/,
            'expected (inp) => inp.value.replace(/\\s+$/, \'\') — a right-trim-only transform');
    });

    // Extract "inp.value.replace(...)" via paren-depth matching (never a naive "first )" regex,
    // which would stop one paren too early and swallow the ENCLOSING .map()'s own closing paren
    // instead) — then wrap it back into a callable function so this test runs the REAL deployed
    // expression, not a hand-typed stand-in of it. `inp` stands in for one chord <input> element
    // (only its `.value` is read by the real expression), matching what onChordRowInput() actually
    // hands the transform per row — NOT a raw string, unlike v1's `l` above.
    const replaceCall = extractCallExpression(statement, 'inp.value.replace(', 'v2 inp.value.replace(...) call');
    // eslint-disable-next-line no-new-func
    const trimFn = new Function('inp', `return ${replaceCall};`);
    const asInput = (value) => ({ value });

    test('v2: EXECUTING the extracted real transform — preserves LEADING whitespace (a positioned column)', () => {
        assert.equal(trimFn(asInput('            G')), '            G',
            'a chord positioned at column 12 must keep its leading padding');
    });
    test('v2: EXECUTING the extracted real transform — strips TRAILING whitespace (incidental)', () => {
        assert.equal(trimFn(asInput('G        D   ')), 'G        D');
    });
    test('v2: EXECUTING the extracted real transform — preserves INTERIOR whitespace exactly', () => {
        assert.equal(trimFn(asInput('G                   D')), 'G                   D');
    });
    test('v2: EXECUTING the extracted real transform — an all-whitespace line collapses to empty', () => {
        assert.equal(trimFn(asInput('   ')), '');
    });
    test('v2: EXECUTING the extracted real transform — an already-clean line is untouched', () => {
        assert.equal(trimFn(asInput('C G Am')), 'C G Am');
    });
    test('v2: EXECUTING the extracted real transform — an empty line stays empty', () => {
        assert.equal(trimFn(asInput('')), '');
    });
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
