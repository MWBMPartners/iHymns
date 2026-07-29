/**
 * iHymns — ChordPro Export Unit Tests (#1264, #1080/#1081)
 *
 * Exercises the pure builder `chordPro.build(song)` exposed by
 *   appWeb/public_html/manage/editor/format-export.js
 *
 * Scope: header directives + {comment:} section labels + lyric lines, PLUS
 * (#1080) inline [chord] markers interleaved into the lyric text whenever a
 * song carries per-line chords. Only `build` is tested: `exportSong*` calls
 * download()/the DOM, which doesn't exist under Node.
 *
 * The no-chords regression test below pins the EXACT output of the
 * pre-#1080 lyrics-only exporter (PR #1277) — captured from the unmodified
 * source before this change landed — so a chordless song is proven
 * byte-identical, not just "looks about right". Every chord-aware
 * assertion in this file was hand-verified to FAIL against that pre-#1080
 * build (no `[chord]` markers, no bracket escaping were ever emitted) —
 * see the PR description / commit history for the captured "before" runs.
 *
 *   USAGE:  node tests/test-chordpro-export.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const require    = createRequire(import.meta.url);

const FORMAT_EXPORT_PATH = path.resolve(
    __dirname, '..',
    'appWeb/public_html/manage/editor/format-export.js'
);

/* format-export.js is a plain global script: requiring it for side effect sets
   globalThis.iHymnsFormatExport (the same contract the browser consumes).
   buildChordPro is pure (no DOM), so it's directly testable. */
require(FORMAT_EXPORT_PATH);
const fmt = globalThis.iHymnsFormatExport;
const buildChordPro = fmt.chordPro.build;

let passed = 0, failed = 0;
const failures = [];
function test(name, fn) {
    try { fn(); passed++; console.log(`  PASS  ${name}`); }
    catch (err) { failed++; failures.push({ name, error: err.message }); console.log(`  FAIL  ${name}`); }
}

const SONG = {
    title: 'Amazing Grace',
    alternateTitle: 'New Britain',
    writers: ['John Newton'],
    composers: ['Trad.'],
    key: 'G',
    capo: 2,
    ccli: '12345',
    copyright: 'Public Domain',
    components: [
        { type: 'verse',  number: 1,    lines: ['Amazing grace how sweet the sound', 'That saved a wretch like me'] },
        { type: 'chorus', number: null, lines: ['Praise God', 'Praise God'] },
    ],
};

test('registry exposes chordPro.build', () => {
    assert.equal(typeof buildChordPro, 'function');
});

test('emits the documented header directives', () => {
    const out = buildChordPro(SONG);
    assert.match(out, /\{title: Amazing Grace\}/);
    assert.match(out, /\{subtitle: New Britain\}/);
    assert.match(out, /\{artist: John Newton, Trad\.\}/);
    assert.match(out, /\{key: G\}/);
    assert.match(out, /\{capo: 2\}/);
    assert.match(out, /\{ccli: 12345\}/);
    assert.match(out, /\{copyright: Public Domain\}/);
});

test('labels sections as {comment:} (Verse 1 / Chorus)', () => {
    const out = buildChordPro(SONG);
    assert.match(out, /\{comment: Verse 1\}/);
    assert.match(out, /\{comment: Chorus\}/);
});

test('includes the lyric lines verbatim', () => {
    const out = buildChordPro(SONG);
    assert.ok(out.includes('Amazing grace how sweet the sound'));
    assert.ok(out.includes('That saved a wretch like me'));
});

test('omits absent metadata (no empty directives)', () => {
    const out = buildChordPro({ title: 'Bare', components: [{ type: 'verse', number: 1, lines: ['x'] }] });
    assert.match(out, /\{title: Bare\}/);
    assert.ok(!/\{subtitle:/.test(out), 'no subtitle directive');
    assert.ok(!/\{key:/.test(out),      'no key directive');
    assert.ok(!/\{ccli:/.test(out),     'no ccli directive');
    assert.ok(!/\{artist:/.test(out),   'no artist directive');
});

test('untitled fallback when title missing', () => {
    assert.match(buildChordPro({ components: [] }), /\{title: Untitled\}/);
});

test('directive values strip braces/newlines (cannot break the directive)', () => {
    const out = buildChordPro({ title: 'A{B}\nC', components: [] });
    assert.match(out, /\{title: A B C\}/);
    assert.ok(!out.includes('A{B}'), 'inner brace removed');
});

test('throws without a song', () => {
    assert.throws(() => buildChordPro(null), /song required/);
});

/* ==========================================================================
 *  #1080 — no-chords safety property: BYTE-IDENTICAL to the pre-#1080 build.
 *
 *  This exact string was captured by running `buildChordPro(SONG)` against
 *  the unmodified pre-#1080 source (git HEAD before this change) — i.e. it
 *  is the historical ground truth, not a re-derivation of the new code's
 *  own behaviour. If this test ever fails, the chord-aware path has leaked
 *  into a song that has no chords at all — the one thing #1080 promises
 *  never to do.
 * ========================================================================== */
const NO_CHORDS_BASELINE =
    '{title: Amazing Grace}\n{subtitle: New Britain}\n{artist: John Newton, Trad.}\n' +
    '{key: G}\n{capo: 2}\n{ccli: 12345}\n{copyright: Public Domain}\n' +
    '\n{comment: Verse 1}\nAmazing grace how sweet the sound\nThat saved a wretch like me\n' +
    '\n{comment: Chorus}\nPraise God\nPraise God\n';

test('#1080 no-chords regression: byte-identical to the pre-#1080 exporter', () => {
    assert.equal(buildChordPro(SONG), NO_CHORDS_BASELINE);
});

test('#1080 no-chords regression: a component with no chords key at all is untouched', () => {
    const song = { title: 'Plain', components: [{ type: 'verse', number: 1, lines: ['Just words', 'No chords here'] }] };
    assert.equal(
        buildChordPro(song),
        '{title: Plain}\n\n{comment: Verse 1}\nJust words\nNo chords here\n'
    );
});

/* ==========================================================================
 *  #1080 — chord interleaving: exact expected output, byte for byte.
 *
 *  Data shape mirrors what includes/lyric_lines_read.php's assembler and
 *  save_song_core.php's _saveSongCleanChords() actually produce: a `chords`
 *  array parallel to `lines`, each cell an ordered array of chord-symbol
 *  strings for THAT line (no stored character offset — see the ALIGNMENT
 *  DECISION note in format-export.js). Chord token i lands before word i.
 * ========================================================================== */
const CHORDS_SONG = {
    title: 'Amazing Grace',
    components: [
        {
            type: 'verse', number: 1,
            lines:  ['Amazing grace how sweet the sound', 'That saved a wretch like me'],
            chords: [['G', 'C', 'G'], null],
        },
        {
            type: 'chorus', number: null,
            lines:  ['Praise God', 'Praise God'],
            chords: [['C'], ['G', 'D']],
        },
    ],
};
const CHORDS_EXPECTED =
    '{title: Amazing Grace}\n' +
    '\n{comment: Verse 1}\n' +
    '[G]Amazing [C]grace [G]how sweet the sound\n' +
    'That saved a wretch like me\n' +
    '\n{comment: Chorus}\n' +
    '[C]Praise God\n' +
    '[G]Praise [D]God\n';

test('#1080 interleaves [chord] markers word-aligned, in document order', () => {
    assert.equal(buildChordPro(CHORDS_SONG), CHORDS_EXPECTED);
});

test('#1080 a chorded song still leaves an unchorded line in that song plain', () => {
    /* "That saved a wretch like me" carries no chords of its own (its cell
       is null) — it must come through with no [markers], only escaped for
       stray brackets (there are none here, so it is untouched text). */
    assert.match(buildChordPro(CHORDS_SONG), /\nThat saved a wretch like me\n/);
});

/* ==========================================================================
 *  #1080 — malformed / out-of-range chord data degrades sensibly: clamped
 *  to the end of the line, never crashes, never silently drops a chord.
 * ========================================================================== */
test('#1080 out-of-range chords (more chords than words) clamp to line end', () => {
    const song = {
        title: 'Bad Data',
        components: [{ type: 'verse', number: 1, lines: ['Grace'], chords: [['G', 'C', 'D']] }],
    };
    /* One word ("Grace"), three chords — the first lands on the word, the
       two extra are appended in order rather than lost. */
    assert.equal(
        buildChordPro(song),
        '{title: Bad Data}\n\n{comment: Verse 1}\n[G]Grace[C][D]\n'
    );
});

test('#1080 chords with no lyric text render as a chord-only line, not dropped', () => {
    const song = {
        title: 'Instrumental',
        components: [{ type: 'verse', number: 1, lines: [''], chords: [['G', 'C']] }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Instrumental}\n\n{comment: Verse 1}\n[G][C]\n'
    );
});

test('#1080 text with no chords in an otherwise-chorded song stays plain', () => {
    const song = {
        title: 'Mixed',
        components: [{ type: 'verse', number: 1, lines: ['No chords on this one'], chords: [null] }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Mixed}\n\n{comment: Verse 1}\nNo chords on this one\n'
    );
});

test('#1080 does not crash on a chords array shorter than the lines array', () => {
    const song = {
        title: 'Short',
        components: [{ type: 'verse', number: 1, lines: ['First line', 'Second line'], chords: [['G']] }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Short}\n\n{comment: Verse 1}\n[G]First line\nSecond line\n'
    );
});

/* ==========================================================================
 *  #1080 — bracket escaping: ChordPro has no backslash escape for a literal
 *  [ or ] (see the ESCAPING note in format-export.js — the reference
 *  implementation's only mechanism is the parser.altbrackets CONFIG option,
 *  which a generic export cannot assume the reader has set). Once a song is
 *  chord-aware, every literal bracket — in lyrics AND in a chord symbol —
 *  is neutralised to parentheses so no compliant reader misparses it.
 * ========================================================================== */
test('#1080 neutralises literal brackets in lyric text once the song has chords', () => {
    const song = {
        title: 'Brackets',
        components: [{
            type: 'verse', number: 1,
            lines:  ['Turn my [heart] to You', 'No [brackets] here'],
            chords: [['G'], null],
        }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Brackets}\n\n{comment: Verse 1}\n[G]Turn my (heart) to You\nNo (brackets) here\n'
    );
});

test('#1080 a literal bracket in a CHORDLESS song is left alone (byte-identical safety net)', () => {
    /* This song has zero chords anywhere, so chordProSongHasChords() is
       false and the whole escaping step is skipped — matching the
       pre-#1080 exporter's behaviour (which never escaped anything). */
    const song = {
        title: 'Brackets',
        components: [{ type: 'verse', number: 1, lines: ['Turn my [heart] to You'] }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Brackets}\n\n{comment: Verse 1}\nTurn my [heart] to You\n'
    );
});

test('#1080 a bracket inside a chord symbol itself is also neutralised (defensive)', () => {
    const song = {
        title: 'Weird Chord',
        components: [{ type: 'verse', number: 1, lines: ['Word'], chords: [['G]evil']] }],
    };
    const out = buildChordPro(song);
    assert.ok(!/\[G\]evil\]/.test(out), 'the embedded ] must not close the marker early');
    assert.equal(out, '{title: Weird Chord}\n\n{comment: Verse 1}\n[G)evil]Word\n');
});

/* ==========================================================================
 *  #1080 — code-point-safe alignment for the catalogue's non-English
 *  songbooks (~20 of ~30 are non-English). Word-splitting only ever cuts on
 *  whitespace RUNS, never mid-character, so precomposed diacritics and
 *  astral-plane (surrogate-pair) characters both survive untouched.
 * ========================================================================== */
test('#1080 code-point-safe alignment: precomposed diacritics (Portuguese)', () => {
    const song = {
        title: 'Graça',
        components: [{
            type: 'verse', number: 1,
            lines:  ['Graça maravilhosa, quão doce o som'],
            chords: [['G', 'C', 'D']],
        }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Graça}\n\n{comment: Verse 1}\n[G]Graça [C]maravilhosa, [D]quão doce o som\n'
    );
});

test('#1080 code-point-safe alignment: an astral-plane (surrogate-pair) character is not split', () => {
    /* U+1D11E MUSICAL SYMBOL G CLEF is a surrogate pair in UTF-16 (2 code
       units, 1 code point). A byte/UTF-16-index-based aligner could easily
       land a [chord] marker inside the pair and corrupt it; this asserts
       the character comes through whole, as its own "word". */
    const song = {
        title: 'Astral',
        components: [{
            type: 'verse', number: 1,
            lines:  ['𝄞 Swing low sweet chariot'],
            chords: [['G', 'C']],
        }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: Astral}\n\n{comment: Verse 1}\n[G]𝄞 [C]Swing low sweet chariot\n'
    );
    /* Belt-and-braces: the surrogate pair must appear as a single, intact
       code point (Array.from groups by code point) directly after its
       chord marker, not as a lone/split surrogate. */
    const out = buildChordPro(song);
    assert.equal(Array.from(out).filter((cp) => cp === '𝄞').length, 1);
});

/* ==========================================================================
 *  #1080 — the dual chords-cell shape componentChordsToText() (editor.js)
 *  documents: a saved cell is an array ("G","C"); a cell fresh out of the
 *  live editor textarea (pre-save) can still be a single space-separated
 *  string ("G C"). Both must interleave identically.
 * ========================================================================== */
test('#1080 accepts a space-separated string chord cell (pre-save shape)', () => {
    const song = {
        title: 'StringCell',
        components: [{ type: 'verse', number: 1, lines: ['Amazing grace'], chords: ['G   C'] }],
    };
    assert.equal(
        buildChordPro(song),
        '{title: StringCell}\n\n{comment: Verse 1}\n[G]Amazing [C]grace\n'
    );
});

console.log(`\nChordPro export: ${passed} passed, ${failed} failed`);
if (failed) { failures.forEach(f => console.log(`  - ${f.name}: ${f.error}`)); process.exit(1); }
