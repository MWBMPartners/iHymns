/**
 * tests/test-structure-chords-remap.js — remapChordsOnLinesChange() truth table (#1263)
 *
 * ELI5
 * ----
 * The v2 editor now has one chord box per lyric line instead of one shared
 * multiline chords box. When you edit the LYRICS textarea (retype a line,
 * reorder lines, add or remove a line), something has to decide which chord
 * goes with which line afterwards — otherwise a chord silently drifts onto
 * whatever line now sits at its old numeric position (the #1263 bug this
 * whole feature exists to fix). `remapChordsOnLinesChange()` is that
 * something. This file runs the REAL function (extracted straight out of
 * `manage/editor/v2/structure-tab.js`'s source, never a hand-typed stand-in)
 * against a truth table of every case the task calls out, so a future edit
 * that swaps the content-match for a naive index-copy — reintroducing the
 * exact bug #1263 fixes — is caught here rather than by a curator noticing
 * a wrong chord weeks later.
 *
 * WHY SOURCE-LEVEL EXTRACTION, NOT AN IMPORT
 * -------------------------------------------
 * `structure-tab.js` is an ES module, but it `import`s `./enrichment-panel.js`,
 * which in turn imports the ABSOLUTE browser path `/js/modules/ietf-language-
 * picker.js` — unresolvable by Node's module loader outside a browser/bundler
 * context. A plain `import` of structure-tab.js would therefore throw before
 * this file's own function is even reached. `remapChordsOnLinesChange()`
 * itself is a pure, dependency-free function (no DOM, no closures over the
 * rest of the module), so — mirroring tests/test-editor-chord-trim.js's own
 * brace-depth extraction technique for this exact file — this test locates
 * its EXACT source text by brace-matching from `export function
 * remapChordsOnLinesChange(` and `new Function`s it directly, so it is
 * always exercising the real deployed algorithm, not a paraphrase of it.
 *
 * TRUTH TABLE (the task's own five cases, each mutation-relevant on its own):
 *   1. reorder            — a moved line carries its own chord with it.
 *   2. in-place edit       — editing a line's text in place (the dominant
 *                            case: this runs on EVERY keystroke of the
 *                            lyrics textarea) keeps that line's chord,
 *                            rather than losing it because the edited text
 *                            no longer content-matches anything.
 *   3. insert               — a brand-new line gets no chord; existing lines'
 *                            chords are undisturbed and stay with their own
 *                            line.
 *   4. delete               — a removed line's chord disappears with it;
 *                            surviving lines keep their own chords.
 *   5. duplicate lines      — two identical lines each keep their OWN
 *                            original chord (consumed in the SAME relative
 *                            order they appear), never both collapsing onto
 *                            the first one.
 *   6. clear-semantics      — when every remapped cell is blank, the result
 *                            is the genuine empty array `[]` (which CLEARS
 *                            server-side, rule #45), never a same-length
 *                            array of `''` (which the isset() gate at
 *                            api2.php's component_upsert would treat as
 *                            "chords supplied, all blank" and NOT clear).
 *
 * MUTATION-PROVEN (rule #34), performed once against the real working tree
 * during authoring, confirmed RED, then reverted (`git diff --stat appWeb`
 * empty before moving on):
 *   - swapped the algorithm's PASS 1 body for a naive INDEX-COPY
 *     (`nLines.map((_, i) => oChords[i] ?? '')`, i.e. exactly the #1263 bug
 *     this feature fixes) -> the "reorder carries chords with their lines"
 *     and "duplicate lines consume in relative order" rows both went RED
 *     (a moved/duplicated line's chord stayed on its old index instead of
 *     following the line), proving this suite actually catches a
 *     regression to the original drift bug rather than only ever exercising
 *     the happy path.
 *
 * Usage: node tests/test-structure-chords-remap.js
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const STRUCT_PATH = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'v2', 'structure-tab.js');

let pass = 0, fail = 0;
function test(name, fn) {
    try { fn(); console.log(`  ✓ ${name}`); pass++; }
    catch (err) { console.error(`  ✗ ${name}\n      ${err && err.stack ? err.stack.split('\n').slice(0, 3).join('\n      ') : err}`); fail++; }
}

/* Brace-depth-bound extraction from an anchor string to its own closing `}`
   — the SAME technique tests/test-editor-chord-trim.js and
   tests/test-propresenter-export.js already use, for the same reason: pull
   the REAL function body text so a mutation to the deployed code has
   something concrete to turn this test red against. */
function extractFunctionSource(source, anchor, label) {
    const anchorIdx = source.indexOf(anchor);
    assert.ok(anchorIdx !== -1, `could not find "${anchor}" (${label})`);
    const openIdx = source.indexOf('{', anchorIdx + anchor.length - 1);
    assert.ok(openIdx !== -1, `no opening brace after "${anchor}" (${label})`);
    let depth = 0;
    let endIdx = -1;
    for (let i = openIdx; i < source.length; i++) {
        if (source[i] === '{') { depth++; }
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) { endIdx = i; break; }
        }
    }
    assert.ok(endIdx !== -1, `could not brace-match "${anchor}" (${label})`);
    // Return the FULL "function name(params) { ... }" text (anchor through the closing brace),
    // widened generously past just the anchor so a reasonable reformat (rule #34) still extracts.
    return source.slice(anchorIdx, endIdx + 1);
}

const structSrc = fs.readFileSync(STRUCT_PATH, 'utf8');

/* Anchor on the EXPORTED declaration's signature line, not just the function
   name — "remapChordsOnLinesChange" alone would also match this very
   doc-comment's own prose mentions of the name (e.g. in the JSDoc above the
   real definition), and indexOf() would find whichever occurs FIRST in the
   file (the doc-comment, which precedes the code) rather than the function
   itself. Anchoring on the full `export function NAME(` signature is unique
   to the real declaration. */
const ANCHOR = 'export function remapChordsOnLinesChange(oldLines, oldChords, newLines) {';
const fnSource = extractFunctionSource(structSrc, ANCHOR, 'remapChordsOnLinesChange');

/* `new Function`'s body must be plain function-expression-able JS — strip the
   leading `export ` ES-module keyword (the ONLY module syntax in this
   extraction; the function body itself is plain JS with no other import/
   export) before wrapping it into something `new Function` can hand back a
   callable for. Using `new Function` (not `eval`) keeps this in its own
   scope, matching the pattern test-editor-chord-trim.js and
   test-propresenter-export.js already use for the identical extraction. */
const fnExpr = fnSource.replace(/^export\s+/, '');
// eslint-disable-next-line no-new-func
const remapChordsOnLinesChange = new Function(
    `const fn = ${fnExpr}; return fn;`
)();

assert.equal(typeof remapChordsOnLinesChange, 'function', 'extraction failed to produce a callable function');

console.log('remapChordsOnLinesChange() — #1263 chord-drift-on-reorder truth table\n');

/* ---- 1. reorder — a moved line carries its own chord with it ---------- */

test('reorder: a moved line takes its chord with it (simple swap)', () => {
    const oldLines = ['Amazing grace', 'how sweet the sound', 'that saved a wretch like me'];
    const oldChords = ['C', 'G', 'Am F'];
    const newLines = ['that saved a wretch like me', 'Amazing grace', 'how sweet the sound'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['Am F', 'C', 'G']);
});

test('reorder: a full reversal keeps every line paired with its own chord', () => {
    const oldLines = ['one', 'two', 'three', 'four'];
    const oldChords = ['C', 'D', 'E', 'F'];
    const newLines = ['four', 'three', 'two', 'one'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['F', 'E', 'D', 'C']);
});

/* ---- 2. in-place edit — the dominant per-keystroke case ---------------- */

test('in-place edit: fixing a typo on ONE line (one keystroke) keeps that line\'s chord, and every untouched line keeps its own', () => {
    const oldLines = ['Amazing grace', 'how sweet the soun', 'that saved a wretch like me'];
    const oldChords = ['C', 'G', 'Am F'];
    // Simulate the very next keystroke: the middle line gains one letter, nothing else changes.
    const newLines = ['Amazing grace', 'how sweet the sound', 'that saved a wretch like me'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', 'G', 'Am F']);
});

test('in-place edit: a single-character edit deep inside a longer song leaves every OTHER chord untouched', () => {
    const oldLines = ['verse one line a', 'verse one line b', 'verse one line c', 'verse one line d'];
    const oldChords = ['C', 'G', 'Am', 'F'];
    const newLines = ['verse one line a', 'verse one line B!', 'verse one line c', 'verse one line d'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', 'G', 'Am', 'F']);
});

/* ---- 3. insert — a brand-new line gets no chord ------------------------ */

test('insert: a new line in the middle gets no chord; the lines around it keep their own', () => {
    const oldLines = ['Amazing grace', 'how sweet the sound', 'that saved a wretch like me'];
    const oldChords = ['C', 'G', 'Am F'];
    const newLines = ['Amazing grace', 'NEW LINE', 'how sweet the sound', 'that saved a wretch like me'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', '', 'G', 'Am F']);
});

test('insert: a new line appended at the end gets no chord', () => {
    const oldLines = ['one', 'two'];
    const oldChords = ['C', 'G'];
    const newLines = ['one', 'two', 'three'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', 'G', '']);
});

/* ---- 4. delete — a removed line's chord disappears with it ------------- */

test('delete: removing the middle line drops its chord; the surviving lines keep their own', () => {
    const oldLines = ['Amazing grace', 'how sweet the sound', 'that saved a wretch like me'];
    const oldChords = ['C', 'G', 'Am F'];
    const newLines = ['Amazing grace', 'that saved a wretch like me'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', 'Am F']);
});

/* ---- 5. duplicate lines — consumed in relative order -------------------- */

test('duplicate lines: two identical lines each keep their OWN chord (relative order), not both grabbing the first', () => {
    const oldLines = ['Amen', 'verse text', 'Amen'];
    const oldChords = ['C', 'G', 'F'];
    // Reordered: the second "Amen" moves to the front.
    const newLines = ['Amen', 'Amen', 'verse text'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    // First new "Amen" claims the FIRST old "Amen" (index 0, chord 'C');
    // second new "Amen" claims the SECOND old "Amen" (index 2, chord 'F').
    assert.deepEqual(result, ['C', 'F', 'G']);
});

test('duplicate lines: three identical lines that never move stay paired with their own original chords', () => {
    const oldLines = ['la', 'la', 'la'];
    const oldChords = ['C', 'G', 'F'];
    const newLines = ['la', 'la', 'la'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['C', 'G', 'F']);
});

/* ---- 6. clear-semantics — an all-blank remap collapses to [] ----------- */

test('clear-semantics: every remapped cell blank -> the genuine empty array [] (never a same-length array of \'\')', () => {
    const oldLines = ['one', 'two'];
    const oldChords = ['', ''];
    const newLines = ['two', 'one'];   // reordered, but there was nothing to carry
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, []);
});

test('clear-semantics: a mix that remaps to SOME content stays the full array, not []', () => {
    const oldLines = ['one', 'two'];
    const oldChords = ['', 'G'];
    const newLines = ['two', 'one'];
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['G', '']);
});

/* ---- edge cases: never called with an array, degrade quietly ----------- */

test('edge case: no prior chords (null) remaps to an all-blank result -> []', () => {
    const result = remapChordsOnLinesChange(['a', 'b'], null, ['b', 'a']);
    assert.deepEqual(result, []);
});

test('edge case: chords shorter than lines (a ragged array) treats the missing cell as blank, not a throw', () => {
    const result = remapChordsOnLinesChange(['a', 'b', 'c'], ['C'], ['a', 'b', 'c']);
    assert.deepEqual(result, ['C', '', '']);
});

test('edge case: a multi-chord ARRAY cell (server-loaded #1094 shape) is carried over VERBATIM, never renormalised', () => {
    const oldLines = ['a', 'b'];
    const oldChords = [['C', 'G'], 'F'];
    const newLines = ['b', 'a'];   // swapped
    const result = remapChordsOnLinesChange(oldLines, oldChords, newLines);
    assert.deepEqual(result, ['F', ['C', 'G']]);
});

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
