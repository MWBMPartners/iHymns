/**
 * iHymns — ChordPro Export Unit Tests (#1264)
 *
 * Exercises the pure builder `chordPro.build(song)` exposed by
 *   appWeb/public_html/manage/editor/format-export.js
 *
 * Scope: the lyrics-only v1 — header directives + {comment:} section labels +
 * lyric lines. (Inline [chord] markers are a follow-on once per-line chords
 * are surfaced on the export read path, #299/#1094.) Only `build` is tested:
 * `exportSong*` call download()/the DOM, which doesn't exist under Node.
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

console.log(`\nChordPro export: ${passed} passed, ${failed} failed`);
if (failed) { failures.forEach(f => console.log(`  - ${f.name}: ${f.error}`)); process.exit(1); }
