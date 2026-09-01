/**
 * iHymns — Chord Notation Converter Unit Tests (#1265)
 *
 * Exercises the pure, DOM-free converters in
 *   appWeb/public_html/js/utils/chord-notation.js
 * — `chordToNashville()` (Nashville Number System) and `chordToSolfege()`
 * (Do-Re-Mi, movable- and fixed-do) — plus a STRUCTURAL guard (Group 4)
 * proving neither converter is ever imported by an export or print surface
 * (rule #45: a screen-display notation must never leak into a machine
 * format).
 *
 * Every expected value below was independently computed against the
 * semitone-distance tables named in each converter's own doc-block
 * (NASHVILLE_DEGREES / SOLFEGE_DEGREES_MOVABLE / FIXED_DO_LETTERS) — see the
 * per-group comments for the arithmetic — before being pinned here, the
 * same "not re-deriving the formula from itself" discipline
 * tests/test-transpose-capo.js's own header describes.
 *
 * MUTATION-PROVEN (rule #34) — both applied directly to the REAL
 * appWeb/public_html/js/utils/chord-notation.js, run once during
 * development and reverted immediately after confirming red (file was
 * untracked at mutation time; `git status`/`git diff` confirmed clean
 * before continuing):
 *   1. Shifted NASHVILLE_DEGREES by one semitone (every value moved down one
 *      key, e.g. `0: '1'` -> `0: '2'`, `11: '7'` -> `11: '1'` wrapping) —
 *      9 of the Group 1 (Nashville) assertions went red; the Group 2/3
 *      (solfège) and Group 4 (structural) assertions correctly stayed
 *      green, since they exercise a different table entirely.
 *   2. Swapped chordToSolfege()'s `if (fixed) { ... } else { ... }` branch
 *      bodies (fixed-do's letter-map logic ran when `fixed: false` was
 *      passed, and vice versa) — 10 of the Group 2/3 (solfège) assertions
 *      went red (the movable-do tests got fixed-do's keyless answers and
 *      the fixed-do tests got movable-do's tonic-dependent answers — one
 *      movable-do assertion, "D against tonic C" -> "Re", happened to
 *      survive by coincidence, since D maps to the same syllable "Re" in
 *      both the movable and fixed tables); Group 1 (Nashville, an entirely
 *      separate function) and Group 4 correctly stayed green.
 *
 *   USAGE:  node tests/test-chord-notation.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chordToNashville, chordToSolfege } from '../appWeb/public_html/js/utils/chord-notation.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

let passed = 0, failed = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}: ${err.message}`); }
}

/* ==========================================================================
 * Group 1 — chordToNashville(): Nashville Number System.
 *
 * Semitone-distance-from-tonic table (chord-notation.js's own doc-block):
 *   { 0:'1', 1:'b2', 2:'2', 3:'b3', 4:'3', 5:'4', 6:'b5', 7:'5', 8:'b6',
 *     9:'6', 10:'b7', 11:'7' }
 * Quality suffix (m, 7, maj7, sus4, …) is untouched non-A-G text and
 * passes through verbatim, exactly like transposeChord()'s own suffix
 * handling.
 * ========================================================================== */
test('major scale degrees in the key of C (I, IV, V)', () => {
    assert.equal(chordToNashville('C', 'C'), '1');
    assert.equal(chordToNashville('F', 'C'), '4');
    assert.equal(chordToNashville('G', 'C'), '5');
});

test('minor chord suffix passes through verbatim onto the degree (vi in C)', () => {
    assert.equal(chordToNashville('Am', 'C'), '6m');
});

test('seventh and sus suffixes pass through verbatim', () => {
    assert.equal(chordToNashville('Cmaj7', 'C'), '1maj7');
    assert.equal(chordToNashville('G7', 'C'), '57');
    assert.equal(chordToNashville('Dsus4', 'C'), '2sus4');
});

test('sharp roots convert correctly against tonic C', () => {
    assert.equal(chordToNashville('C#', 'C'), 'b2');
    assert.equal(chordToNashville('D#', 'C'), 'b3');
    assert.equal(chordToNashville('F#', 'C'), 'b5');
});

test('flat roots convert correctly against tonic C (same degrees as their sharp spellings)', () => {
    assert.equal(chordToNashville('Db', 'C'), 'b2');
    assert.equal(chordToNashville('Eb', 'C'), 'b3');
    assert.equal(chordToNashville('Ab', 'C'), 'b6');
});

test('slash chord converts BOTH the root and the bass against the tonic', () => {
    assert.equal(chordToNashville('G/B', 'C'), '5/7');
    assert.equal(chordToNashville('C/E', 'C'), '1/3');
});

test('the tonic chord is always degree 1, across all 12 chromatic tonics (sharp spellings)', () => {
    const tonics = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    for (const t of tonics) assert.equal(chordToNashville(t, t), '1', `tonic=${t}`);
});

test('the tonic chord is always degree 1, for flat-spelled tonics too', () => {
    const tonics = ['Db', 'Eb', 'Gb', 'Ab', 'Bb'];
    for (const t of tonics) assert.equal(chordToNashville(t, t), '1', `tonic=${t}`);
});

test('minor tonic: the tonic itself is "1m"; other degrees still spelled against its MAJOR scale', () => {
    /* Key of Em. E=4, G=7, D=2, A=9 on the chromatic scale (index from C=0).
       G: (7-4)=3            -> 'b3'  (relative major's tonic, a minor 3rd up)
       D: (2-4+12)%12=10     -> 'b7'  (natural minor's flat-seven)
       Am: (9-4)=5           -> '4m'  (the iv chord) */
    assert.equal(chordToNashville('Em', 'Em'), '1m');
    assert.equal(chordToNashville('G', 'Em'), 'b3');
    assert.equal(chordToNashville('D', 'Em'), 'b7');
    assert.equal(chordToNashville('Am', 'Em'), '4m');
});

test('unknown/non-chord token (no A-G letter to match) passes through unchanged', () => {
    assert.equal(chordToNashville('H', 'C'), 'H');
});

test('falsy chord input passes through unchanged (mirrors transposeChord\'s own guard)', () => {
    assert.equal(chordToNashville('', 'C'), '');
    assert.equal(chordToNashville(null, 'C'), null);
});

test('an unresolvable tonic makes chordToNashville a pure passthrough', () => {
    assert.equal(chordToNashville('C', ''), 'C');
    assert.equal(chordToNashville('C', 'Z'), 'C');
});

/* ==========================================================================
 * Group 2 — chordToSolfege(): MOVABLE-do (tonic = "Do").
 *
 * Same semitone-distance table shape as Group 1, syllables per
 * chord-notation.js's SOLFEGE_DEGREES_MOVABLE (flat-family syllables for
 * the altered degrees, per the #1265 sub-decision):
 *   { 0:'Do', 1:'Ra', 2:'Re', 3:'Me', 4:'Mi', 5:'Fa', 6:'Se', 7:'Sol',
 *     8:'Le', 9:'La', 10:'Te', 11:'Ti' }
 * ========================================================================== */
test('movable-do maps the full major scale in the key of C', () => {
    assert.equal(chordToSolfege('C', 'C'), 'Do');
    assert.equal(chordToSolfege('D', 'C'), 'Re');
    assert.equal(chordToSolfege('E', 'C'), 'Mi');
    assert.equal(chordToSolfege('F', 'C'), 'Fa');
    assert.equal(chordToSolfege('G', 'C'), 'Sol');
    assert.equal(chordToSolfege('A', 'C'), 'La');
    assert.equal(chordToSolfege('B', 'C'), 'Ti');
});

test('movable-do uses the flat-family syllable for every altered (sharp or flat) degree', () => {
    assert.equal(chordToSolfege('C#', 'C'), 'Ra');
    assert.equal(chordToSolfege('Eb', 'C'), 'Me');
    assert.equal(chordToSolfege('F#', 'C'), 'Se');
    assert.equal(chordToSolfege('Ab', 'C'), 'Le');
    assert.equal(chordToSolfege('Bb', 'C'), 'Te');
});

test('movable-do genuinely moves: the tonic is always "Do", whatever the key', () => {
    assert.equal(chordToSolfege('G', 'G'), 'Do');
    assert.equal(chordToSolfege('D', 'G'), 'Sol'); /* the V chord, same syllable as in C */
});

test('movable-do minor tonic: suffix passes through onto "Do" exactly like Nashville\'s "1m"', () => {
    assert.equal(chordToSolfege('Em', 'Em'), 'Dom');
});

test('movable-do slash chord converts both root and bass', () => {
    assert.equal(chordToSolfege('G/B', 'C'), 'Sol/Ti');
});

test('opts omitted defaults to movable-do (not fixed)', () => {
    assert.equal(chordToSolfege('D', 'C'), 'Re');
});

/* ==========================================================================
 * Group 3 — chordToSolfege(): FIXED-do (tonic ignored entirely).
 * FIXED_DO_LETTERS: C=Do, D=Re, E=Mi, F=Fa, G=Sol, A=La, B=Si; the
 * accidental (# or b) is appended VERBATIM, never resolved against a scale.
 * ========================================================================== */
test('fixed-do maps every natural letter, tonic irrelevant', () => {
    assert.equal(chordToSolfege('C', 'C', { fixed: true }), 'Do');
    assert.equal(chordToSolfege('D', 'C', { fixed: true }), 'Re');
    assert.equal(chordToSolfege('E', 'C', { fixed: true }), 'Mi');
    assert.equal(chordToSolfege('F', 'C', { fixed: true }), 'Fa');
    assert.equal(chordToSolfege('G', 'C', { fixed: true }), 'Sol');
    assert.equal(chordToSolfege('A', 'C', { fixed: true }), 'La');
    assert.equal(chordToSolfege('B', 'C', { fixed: true }), 'Si');
});

test('fixed-do genuinely ignores the tonic argument — same chord, different tonics, same answer', () => {
    assert.equal(chordToSolfege('C', 'C', { fixed: true }), 'Do');
    assert.equal(chordToSolfege('C', 'G', { fixed: true }), 'Do');
    assert.equal(chordToSolfege('C', 'F#', { fixed: true }), 'Do');
});

test('fixed-do needs NO key at all — an empty/undefined tonic still resolves (the keyless-song case)', () => {
    assert.equal(chordToSolfege('C', '', { fixed: true }), 'Do');
    assert.equal(chordToSolfege('C', undefined, { fixed: true }), 'Do');
});

test('fixed-do appends the accidental verbatim rather than resolving it against a scale', () => {
    assert.equal(chordToSolfege('C#', 'C', { fixed: true }), 'Do#');
    assert.equal(chordToSolfege('Bb', 'C', { fixed: true }), 'Sib');
});

test('fixed-do slash chord converts both root and bass', () => {
    assert.equal(chordToSolfege('G/B', 'C', { fixed: true }), 'Sol/Si');
});

test('fixed-do: unknown/non-chord token passes through unchanged', () => {
    assert.equal(chordToSolfege('H', 'C', { fixed: true }), 'H');
});

/* ==========================================================================
 * Group 4 — STRUCTURAL guard (rule #45): chord-notation.js's converters are
 * SCREEN-DISPLAY ONLY. Neither export surface — the OpenLyrics/OpenSong/
 * ChordPro/… writer or the ProPresenter writer — nor the print renderer may
 * ever import this module; every one of them must keep reading/writing the
 * stored LETTER chord, or output stops round-tripping through any external
 * tool that reads it back.
 * ========================================================================== */
const EXPORT_SURFACE_FILES = [
    'appWeb/public_html/manage/editor/format-export.js',
    'appWeb/public_html/manage/editor/propresenter-export.js',
    'appWeb/public_html/js/modules/print.js',
];

test('mutation self-test — the import-leak scan below DOES flag an obvious leak', () => {
    /* In-memory fixture only — never touches a real file. Proves the plain
       substring/regex check that Group 4's real assertion relies on can
       actually go red, before trusting it against the real exporters. */
    const badSource = [
        "import { chordToNashville } from '../../js/utils/chord-notation.js';",
        'export function exportSong(s) { return chordToNashville(s.chord, s.key); }',
    ].join('\n');
    assert.ok(/chord-notation/i.test(badSource), 'the scanner failed to flag a leaked chord-notation import — it has no teeth');
});

test('chord-notation.js is not imported by any export or print surface', () => {
    for (const rel of EXPORT_SURFACE_FILES) {
        const full = path.join(PROJECT_ROOT, rel);
        assert.ok(fs.existsSync(full), `expected export/print file is missing — did it move? ${rel}`);
        const source = fs.readFileSync(full, 'utf8');
        assert.ok(
            !/chord-notation/i.test(source),
            `${rel} references "chord-notation" — a screen-display converter must never reach an exporter or the print renderer (rule #45)`
        );
    }
});

console.log(`\nChord notation converters: ${passed} passed, ${failed} failed`);
if (failed) { failures.forEach(f => console.log(`  - ${f.name}: ${f.error}`)); process.exit(1); }
