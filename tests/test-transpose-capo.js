/**
 * iHymns — Capo + Octave Display Overlay Unit Tests (#1271)
 *
 * Exercises the pure, DOM-free render seam exposed by
 *   appWeb/public_html/js/modules/transpose.js
 * — `composeChordDisplay(original, offset, capo)`, `transposeChord()` and
 * `transposeKey()` — plus a STRUCTURAL (source-scanning) guard proving the
 * octave DISPLAY overlay can never reach that seam's semitone maths.
 *
 * WHY a source scan for the octave claim, not just a numeric one: octave
 * shifts are, by definition, multiples of 12 semitones, and
 * transposeChord()'s own arithmetic is mod 12 — so even a BUGGY
 * implementation that (wrongly) added `octave * 12` to the semitone count
 * would be numerically INDISTINGUISHABLE from doing nothing at all. A
 * purely numeric truth table cannot catch that class of mistake; only
 * checking that the word "octave" never appears inside composeChordDisplay's
 * own signature/body (nor in any call site) can. This is the same
 * tree/source-derived-guard shape as tests/test-writer-musician-route.js's
 * comment-stripper self-test — see "Group 3" below, including its own
 * mutation self-test proving the scanner has teeth BEFORE trusting it
 * against the real file.
 *
 * MUTATION-PROVEN (rule #34) — both against the REAL transpose.js, not just
 * the in-file fixtures below, run once during development and reverted:
 *   1. Flipped composeChordDisplay's `offset - capo` to `offset + capo` —
 *      5 of the Group 1 truth-table assertions went red (the identity/arity
 *      checks correctly stayed green, since a sign flip doesn't change
 *      those).
 *   2. Widened the REAL composeChordDisplay to `(original, offset, capo,
 *      octave)` returning `transposeChord(original, offset - capo +
 *      octave)` (an uncalled 4th param, so `octave` was `undefined` at every
 *      real call site) — 8 assertions went red: the numeric truth table
 *      (via `undefined` poisoning the arithmetic), the arity check, AND the
 *      structural "never mentions octave" scan.
 * Both were restored immediately after confirming red; `git diff` was clean
 * before continuing.
 *
 *   USAGE:  node tests/test-transpose-capo.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { composeChordDisplay, transposeChord, transposeKey } from '../appWeb/public_html/js/modules/transpose.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const TRANSPOSE_JS_PATH = path.resolve(__dirname, '..', 'appWeb/public_html/js/modules/transpose.js');
const transposeJsSource = fs.readFileSync(TRANSPOSE_JS_PATH, 'utf8');

let passed = 0, failed = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}: ${err.message}`); }
}

/* ==========================================================================
 * Group 1 — composeChordDisplay(): capo composes with the transpose offset.
 *
 * Semantics under test (transpose.js's own doc-block): SOUNDING chord =
 * transposeChord(original, offset); SHAPE shown = transposeChord(original,
 * offset - capo). Every expected value below was independently computed by
 * calling transposeChord(original, offset - capo) directly and cross-checked
 * against composeChordDisplay's actual output before being pinned here (see
 * the session's verification run) — this is deliberately NOT re-deriving the
 * formula from itself.
 * ========================================================================== */
test('capo 0 is the identity — shape equals the sounding chord', () => {
    assert.equal(composeChordDisplay('G', 0, 0), 'G');
    assert.equal(composeChordDisplay('C', 6, 0), transposeChord('C', 6));
    assert.equal(composeChordDisplay('Am7', -3, 0), transposeChord('Am7', -3));
});

test('capo subtracts correctly across the wrap: sounding G, capo 3 -> E shapes', () => {
    /* G (index 7) - 3 semitones = index 4 = E. The guitarist's fretted SHAPE
       is E even though the room hears G, because fretting capo 3 raises an
       E shape's open strings up to sounding G. */
    assert.equal(composeChordDisplay('G', 0, 3), 'E');
});

test('capo subtracts correctly across the LOW wrap (root under C)', () => {
    /* C (index 0) - 5 semitones wraps past 0 down to index 7 = G. */
    assert.equal(composeChordDisplay('C', 0, 5), 'G');
});

test('offset + capo compose correctly at the existing -5..+6 offset clamp boundary', () => {
    /* offset is clamped to the balanced -5..+6 representative range by
       bindControls()'s up/down handlers (unchanged by #1271) — but
       composeChordDisplay must still resolve ANY offset-capo combination
       correctly, since capo (0-11) can push the composed semitone count
       well outside that range. offset=+6 (the clamp's top) with capo=11
       composes to -5 semitones, and must match calling transposeChord with
       -5 directly — proving the composition doesn't silently clamp or
       truncate at the boundary, it just keeps wrapping mod 12. */
    assert.equal(composeChordDisplay('C', 6, 11), transposeChord('C', -5));
    assert.equal(composeChordDisplay('C', 6, 11), 'G');
    /* And the opposite extreme: offset at the bottom of the clamp (-5) with
       a small capo. */
    assert.equal(composeChordDisplay('C', -5, 1), transposeChord('C', -6));
    assert.equal(composeChordDisplay('C', -5, 1), 'F#');
});

test('composes correctly across every offset in the -5..+6 clamp x every capo fret 0-11', () => {
    /* Exhaustive self-consistency sweep: composeChordDisplay must ALWAYS
       equal calling transposeChord directly with (offset - capo), for
       every offset the UI can actually produce and every capo fret the
       stepper allows. This is the property the two hand-picked boundary
       cases above are instances of. */
    const chord = 'C';
    for (let offset = -5; offset <= 6; offset++) {
        for (let capo = 0; capo <= 11; capo++) {
            assert.equal(
                composeChordDisplay(chord, offset, capo),
                transposeChord(chord, offset - capo),
                `offset=${offset} capo=${capo}`
            );
        }
    }
});

test('handles compound / slash chords the same way transposeChord does', () => {
    assert.equal(composeChordDisplay('Am7', 2, 0), 'Bm7');
    assert.equal(composeChordDisplay('Am7', 2, 2), 'Am7'); /* net 0 semitones */
    assert.equal(composeChordDisplay('G/B', 0, 4), 'D#/G');
});

test('composeChordDisplay has exactly 3 parameters (original, offset, capo) — no octave slot', () => {
    assert.equal(composeChordDisplay.length, 3);
});

/* ==========================================================================
 * Group 2 — transposeKey() / transposeChord() still behave exactly as
 * before #1271 (the SOUNDING key badge reads transposeKey() directly, with
 * no capo involved at all — see transpose.js's updateDisplay()).
 * ========================================================================== */
test('transposeKey is unaffected by capo — no capo parameter exists', () => {
    assert.equal(transposeKey.length, 2);
    assert.equal(transposeKey('G', 3), 'A#');
    assert.equal(transposeKey('Am', -2), 'Gm');
});

test('transposeChord(x, 0) is the identity (still true post-refactor)', () => {
    assert.equal(transposeChord('Cmaj7', 0), 'Cmaj7');
});

/* ==========================================================================
 * Group 3 — STRUCTURAL guard: octave can never reach the chord-transposition
 * seam. See the file doc-block above for why this must be a source scan
 * rather than a numeric assertion.
 * ========================================================================== */

/**
 * Strip `/* ... *\/` block comments from `source`. transpose.js's own
 * doc-blocks mention "composeChordDisplay()" (bare, no arguments) many
 * times in prose — without stripping those first, a naive call-site regex
 * over the RAW source counts every one of those mentions as a "0-argument
 * call site" and false-positives. This file has no `/*` inside a string
 * literal to worry about, so a plain non-greedy regex is sufficient (this
 * is NOT a general JS comment stripper, just enough for this one guard —
 * matching the scope of tests/test-writer-musician-route.js's own
 * comment-stripper self-test below).
 */
function stripBlockComments(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '');
}

test('comment-stripper self-test — proves the guard below can actually go blind to a comment, not just to real code', () => {
    const mixed = 'const x = composeChordDisplay(a, b, c); /* composeChordDisplay() mentioned in prose */';
    const stripped = stripBlockComments(mixed);
    assert.ok(stripped.includes('composeChordDisplay(a, b, c)'), 'a match inside real code must SURVIVE stripping');
    assert.ok(!stripped.includes('mentioned in prose'), 'the SAME text wrapped in a comment must be REMOVED by stripping');
});

const strippedSource = stripBlockComments(transposeJsSource);

/**
 * Extract `{ params, body }` for the named top-level `export function` in
 * `source`, by locating its signature and then bracket-counting from the
 * first `{` to its matching `}`. Returns null if the function isn't found.
 * Deliberately simple (this file has no nested template-literal braces
 * inside composeChordDisplay to confuse a naive counter) — good enough for
 * a guard over ONE small pure function, not a general JS parser.
 */
function extractFunctionSource(source, name) {
    const sigMatch = source.match(new RegExp(`function\\s+${name}\\s*\\(([^)]*)\\)\\s*\\{`));
    if (!sigMatch) return null;
    const params = sigMatch[1];
    const bodyStart = sigMatch.index + sigMatch[0].length;
    let depth = 1;
    let i = bodyStart;
    for (; i < source.length && depth > 0; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
    }
    const body = source.slice(bodyStart, i - 1);
    return { params, body };
}

/** True when `octave` (case-insensitive, word-boundary) appears anywhere in `text`. */
function mentionsOctave(text) {
    return /\boctave\b/i.test(text);
}

test('mutation self-test — the scanner DOES flag a fixture that leaks octave into the seam', () => {
    /* MUTATION PROVEN: this fixture is what composeChordDisplay would look
       like if someone "fixed" #1265 by wiring the octave stepper straight
       into the semitone maths (`offset - capo + octave`) and widened the
       signature to match. Run this same session: pasting this exact fixture
       in place of the real function and re-running this test file WITHOUT
       this self-test still-passing would be a false negative; this
       assertion is what proves that mutation is actually caught. */
    const badFixture = [
        'export function composeChordDisplay(original, offset, capo, octave) {',
        '    return transposeChord(original, offset - capo + octave);',
        '}',
    ].join('\n');
    const extracted = extractFunctionSource(badFixture, 'composeChordDisplay');
    assert.ok(extracted, 'self-test fixture must itself be extractable');
    assert.ok(
        mentionsOctave(extracted.params) || mentionsOctave(extracted.body),
        'the scanner failed to flag an octave leak in the mutated fixture — it has no teeth'
    );
});

test('mutation self-test — the scanner does NOT false-positive on an unrelated "octave" comment', () => {
    /* Guards against the opposite failure mode (rule #34's "too blunt" red
       flag): a function that merely happens to sit near a comment
       mentioning octave elsewhere in the file must not trip this. Only text
       INSIDE the extracted signature/body counts. */
    const fixtureWithUnrelatedComment = [
        '/* the octave stepper lives elsewhere in this file */',
        'export function composeChordDisplay(original, offset, capo) {',
        '    return transposeChord(original, offset - capo);',
        '}',
    ].join('\n');
    const extracted = extractFunctionSource(fixtureWithUnrelatedComment, 'composeChordDisplay');
    assert.ok(extracted);
    assert.ok(!mentionsOctave(extracted.params) && !mentionsOctave(extracted.body));
});

test('composeChordDisplay in the REAL transpose.js never mentions octave (signature or body)', () => {
    const extracted = extractFunctionSource(strippedSource, 'composeChordDisplay');
    assert.ok(extracted, 'composeChordDisplay not found in transpose.js — did it get renamed?');
    assert.ok(!mentionsOctave(extracted.params), `octave found in composeChordDisplay's parameter list: (${extracted.params})`);
    assert.ok(!mentionsOctave(extracted.body), 'octave found inside composeChordDisplay\'s body');
});

test('transposeChord in the REAL transpose.js never mentions octave (signature or body)', () => {
    const extracted = extractFunctionSource(strippedSource, 'transposeChord');
    assert.ok(extracted, 'transposeChord not found in transpose.js');
    assert.ok(!mentionsOctave(extracted.params));
    assert.ok(!mentionsOctave(extracted.body));
});

test('every composeChordDisplay(...) call site in transpose.js passes exactly 3 arguments, none named octave', () => {
    /* Scanned against the COMMENT-STRIPPED source (see stripBlockComments()
       above) — transpose.js's own doc-blocks mention "composeChordDisplay()"
       bare, in prose, many times; those must not be counted as call sites. */
    const callSites = [...strippedSource.matchAll(/composeChordDisplay\(([^)]*)\)/g)]
        /* Exclude the function's own declaration (its "call-shaped" text —
           `composeChordDisplay(original, offset, capo)` — is the signature,
           already checked above). */
        .filter((m) => !strippedSource.slice(Math.max(0, m.index - 9), m.index).includes('function '));
    assert.ok(callSites.length >= 1, 'no composeChordDisplay(...) call site found — is renderChords() still calling it?');
    for (const m of callSites) {
        const args = m[1].split(',').map((s) => s.trim()).filter((s) => s !== '');
        assert.equal(args.length, 3, `call site "${m[0]}" does not pass exactly 3 arguments`);
        assert.ok(!mentionsOctave(m[1]), `call site "${m[0]}" references octave`);
    }
});

console.log(`\nTranspose capo/octave overlay: ${passed} passed, ${failed} failed`);
if (failed) { failures.forEach(f => console.log(`  - ${f.name}: ${f.error}`)); process.exit(1); }
