/**
 * iHymns — FreeShow Import Unit Tests (Issue #884)
 *
 * Exercises the parser in `appWeb/private_html/editor/freeshow-import.cjs`
 * against the fixtures under `tests/fixtures/import/freeshow/`.
 *
 *   node tests/test-freeshow-import.js
 */

import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const require = createRequire(import.meta.url);

const FreeShowIO = require(path.join(
    PROJECT_ROOT,
    'appWeb', 'private_html', 'editor', 'freeshow-import.cjs'
));

const FIXTURES = path.join(PROJECT_ROOT, 'tests', 'fixtures', 'import', 'freeshow');

let passed = 0;
let failed = 0;
const failures = [];

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✅ ${name}`);
    } catch (err) {
        failed++;
        failures.push({ name, error: err.message });
        console.log(`  ❌ ${name}`);
        console.log(`     ${err.message}`);
    }
}

function loadShow(filename) {
    return JSON.parse(fs.readFileSync(path.join(FIXTURES, filename), 'utf-8'));
}

console.log('');
console.log('🧪 FreeShow Import — Issue #884');
console.log('══════════════════════════════════════════════════');

/* -------------------------------------------------------------------------
 * Detection
 * ------------------------------------------------------------------------- */
console.log('\n🔍 Format detection');

test('looksLikeFreeShow recognises a valid show', () => {
    assert.equal(FreeShowIO.looksLikeFreeShow(loadShow('simple-song.show')), true);
});

test('looksLikeFreeShow rejects iHymns wrapper', () => {
    assert.equal(FreeShowIO.looksLikeFreeShow({ songs: [], songbooks: [] }), false);
});

test('looksLikeFreeShow rejects VideoPsalm-style payload', () => {
    assert.equal(FreeShowIO.looksLikeFreeShow({ Songs: [{ Title: 'X' }] }), false);
});

test('looksLikeFreeShow rejects non-objects', () => {
    assert.equal(FreeShowIO.looksLikeFreeShow(null),     false);
    assert.equal(FreeShowIO.looksLikeFreeShow('string'), false);
    assert.equal(FreeShowIO.looksLikeFreeShow([]),       false);
});

/* -------------------------------------------------------------------------
 * Simple-song parsing
 * ------------------------------------------------------------------------- */
console.log('\n🎵 Simple-song parsing');

const simpleResult = FreeShowIO.parseFreeShowShow(loadShow('simple-song.show'),
    { sourceName: 'simple-song', songbook: 'Misc', number: 1, songbookName: 'Miscellaneous' });

test('simple song parses without errors', () => {
    assert.deepEqual(simpleResult.errors, []);
});

test('title comes from meta.title', () => {
    assert.equal(simpleResult.song.title, 'Be Thou My Vision');
});

test('writers split on " & "', () => {
    assert.deepEqual(simpleResult.song.writers, ['Mary E. Byrne', 'Eleanor H. Hull']);
});

test('CCLI carried through as string', () => {
    assert.equal(simpleResult.song.ccli, '30639');
});

test('tune carried through to song.tune', () => {
    assert.equal(simpleResult.song.tune, 'Slane');
});

test('id formed from songbook + zero-padded number', () => {
    assert.equal(simpleResult.song.id, 'Misc-0001');
});

test('verses are numbered sequentially', () => {
    const verses = simpleResult.song.components.filter(c => c.type === 'verse');
    assert.equal(verses.length, 2);
    assert.equal(verses[0].number, 1);
    assert.equal(verses[1].number, 2);
});

test('consecutive chorus slides collapse into a single chorus', () => {
    const choruses = simpleResult.song.components.filter(c => c.type === 'chorus');
    assert.equal(choruses.length, 1);
    assert.equal(choruses[0].lines.length, 3);
});

test('component lines preserved as array', () => {
    const v1 = simpleResult.song.components.find(c => c.type === 'verse' && c.number === 1);
    assert.ok(Array.isArray(v1.lines));
    assert.ok(v1.lines[0].includes('Be Thou my vision'));
});

/* -------------------------------------------------------------------------
 * Multi-layout
 * ------------------------------------------------------------------------- */
console.log('\n🗂  Multi-layout handling');

const multiResult = FreeShowIO.parseFreeShowShow(loadShow('multi-layout.show'),
    { songbook: 'Misc', number: 2 });

test('multi-layout parse uses first layout order', () => {
    assert.deepEqual(multiResult.errors, []);
    const v1 = multiResult.song.components[0];
    assert.ok(v1.lines[0].includes('Amazing grace'), 'first verse should be from layout slide order');
});

test('alternate layouts reported as warnings', () => {
    const w = multiResult.warnings.join(' ');
    assert.ok(/Alt arrangement/.test(w),  'should mention alternate layout');
    assert.ok(/Intro only/.test(w),        'should mention intro layout');
    assert.ok(/Dropped 2 alternate/.test(w), 'should report two dropped layouts');
});

/* -------------------------------------------------------------------------
 * Sermon-notes rejection
 * ------------------------------------------------------------------------- */
console.log('\n🛑 Non-song rejection');

const sermonResult = FreeShowIO.parseFreeShowShow(loadShow('sermon-notes.show'),
    { songbook: 'Misc', number: 99 });

test('non-song category rejected', () => {
    assert.equal(sermonResult.song, null);
    assert.equal(sermonResult.errors.length, 1);
    assert.ok(/category/i.test(sermonResult.errors[0]));
});

/* -------------------------------------------------------------------------
 * Media-slide stripping
 * ------------------------------------------------------------------------- */
console.log('\n🖼  Media-slide stripping');

const mediaResult = FreeShowIO.parseFreeShowShow(loadShow('with-media-slide.show'),
    { songbook: 'Misc', number: 3 });

test('media-only slide silently skipped', () => {
    assert.deepEqual(mediaResult.errors, []);
    /* The fixture has v1 (text) + media1 (image+timer, no text) + c1 (text). */
    assert.equal(mediaResult.song.components.length, 2);
    assert.equal(mediaResult.song.components[0].type, 'verse');
    assert.equal(mediaResult.song.components[1].type, 'chorus');
});

/* -------------------------------------------------------------------------
 * Filename helpers (Issue #884 acceptance)
 * ------------------------------------------------------------------------- */
console.log('\n📁 Filename helpers');

test('zero-pads to width', () => {
    assert.equal(FreeShowIO.padNumber(5, 4), '0005');
    assert.equal(FreeShowIO.padNumber(123, 4), '0123');
    assert.equal(FreeShowIO.padNumber(12345, 4), '12345'); // already wider
});

test('songbookPadWidth honours largest number in songbook', () => {
    const songs = [
        { songbook: 'CP', number: 243 },
        { songbook: 'MP', number: 1355 },
        { songbook: 'JP', number: 12 }
    ];
    assert.equal(FreeShowIO.songbookPadWidth('CP', songs), 3);
    assert.equal(FreeShowIO.songbookPadWidth('MP', songs), 4);
    assert.equal(FreeShowIO.songbookPadWidth('JP', songs), 3); /* min 3 */
});

test('individual song filename without tune title', () => {
    const fn = FreeShowIO.buildSongFilename(
        { number: 5, songbook: 'CP', title: 'Amazing Grace' }, 4, '.show');
    assert.equal(fn, '0005 (CP) - Amazing Grace.show');
});

test('individual song filename with tune title', () => {
    const fn = FreeShowIO.buildSongFilename(
        { number: 5, songbook: 'CP', title: 'Amazing Grace', tune: 'New Britain' }, 4, '.show');
    assert.equal(fn, '0005 (CP) - Amazing Grace (New Britain).show');
});

test('individual song filename strips OS-illegal characters', () => {
    const fn = FreeShowIO.buildSongFilename(
        { number: 1, songbook: 'CP', title: 'Hope: Faith / Love?' }, 4, '.show');
    assert.ok(!/[\\/:*?"<>|]/.test(fn), 'should not contain illegal chars: ' + fn);
});

test('bulk bundle filename', () => {
    const fn = FreeShowIO.buildBundleFilename({ id: 'CP', name: 'Carol Praise' }, '.zip');
    assert.equal(fn, 'Carol Praise (CP) [Bundle].zip');
});

test('bulk bundle filename for full library', () => {
    const fn = FreeShowIO.buildBundleFilename({ id: 'iHymns', name: 'iHymns Library' }, '.zip');
    assert.equal(fn, 'iHymns Library (iHymns) [Bundle].zip');
});

/* -------------------------------------------------------------------------
 * Round-trip export
 * ------------------------------------------------------------------------- */
console.log('\n♻️  Round-trip export');

test('iHymns song → FreeShow → back to iHymns preserves text', () => {
    const original = simpleResult.song;
    const exported = FreeShowIO.iHymnsSongToFreeShow(original);
    assert.equal(exported.category, 'song');
    assert.equal(exported.meta.title, 'Be Thou My Vision');
    assert.equal(exported.meta.tune,  'Slane');

    const reimported = FreeShowIO.parseFreeShowShow(exported,
        { songbook: 'Misc', number: 1 }).song;
    assert.equal(reimported.title, original.title);
    assert.equal(
        reimported.components.filter(c => c.type === 'verse').length,
        original.components.filter(c => c.type === 'verse').length
    );
});

/* -------------------------------------------------------------------------
 * Results
 * ------------------------------------------------------------------------- */
console.log('');
console.log('══════════════════════════════════════════════════');
console.log(`✅ Passed: ${passed}`);
console.log(`❌ Failed: ${failed}`);
console.log(`📊 Total:  ${passed + failed}`);

if (failures.length > 0) {
    console.log('\nFailures:');
    for (const f of failures) console.log(`  ❌ ${f.name}: ${f.error}`);
}
console.log('');
process.exit(failed > 0 ? 1 : 0);
