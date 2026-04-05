/**
 * iHymns — Song Parser Unit Tests
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Unit tests for the song data parser (tools/parse-songs.js) and
 * validation of the generated songs.json output.
 *
 * USAGE:
 *   node tests/test-song-parser.js
 *   npm test
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

/* =========================================================================
 * SETUP
 * ========================================================================= */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const SONGS_JSON = path.join(PROJECT_ROOT, 'data', 'songs.json');

/* Track test results */
let passed = 0;
let failed = 0;
const failures = [];

/**
 * test(name, fn)
 * Simple test runner — runs fn and catches assertion errors.
 */
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

/* =========================================================================
 * LOAD SONG DATA
 * ========================================================================= */

console.log('');
console.log('🧪 iHymns — Song Parser Unit Tests');
console.log('══════════════════════════════════════════════════');

/* Verify songs.json exists */
test('songs.json file exists', () => {
    assert.ok(fs.existsSync(SONGS_JSON), 'data/songs.json does not exist');
});

/* Parse the JSON */
const rawJson = fs.readFileSync(SONGS_JSON, 'utf-8');
let songData;

test('songs.json is valid JSON', () => {
    songData = JSON.parse(rawJson);
    assert.ok(songData, 'Failed to parse songs.json');
});

/* =========================================================================
 * STRUCTURE TESTS
 * ========================================================================= */

console.log('');
console.log('📐 Structure Tests');

test('has meta object', () => {
    assert.ok(songData.meta, 'Missing meta object');
    assert.ok(songData.meta.generatedAt, 'Missing meta.generatedAt');
    assert.ok(songData.meta.totalSongs > 0, 'meta.totalSongs should be > 0');
    assert.ok(songData.meta.totalSongbooks > 0, 'meta.totalSongbooks should be > 0');
});

test('has songbooks array', () => {
    assert.ok(Array.isArray(songData.songbooks), 'songbooks should be an array');
    assert.equal(songData.songbooks.length, 5, 'Should have 5 songbooks');
});

test('has songs array', () => {
    assert.ok(Array.isArray(songData.songs), 'songs should be an array');
    assert.ok(songData.songs.length > 3000, `Should have > 3000 songs, got ${songData.songs.length}`);
});

test('meta.totalSongs matches songs array length', () => {
    assert.equal(songData.meta.totalSongs, songData.songs.length);
});

test('meta.totalSongbooks matches songbooks array length', () => {
    assert.equal(songData.meta.totalSongbooks, songData.songbooks.length);
});

/* =========================================================================
 * SONGBOOK TESTS
 * ========================================================================= */

console.log('');
console.log('📚 Songbook Tests');

const expectedSongbooks = [
    { id: 'CP', name: 'Carol Praise' },
    { id: 'JP', name: 'Junior Praise' },
    { id: 'MP', name: 'Mission Praise' },
    { id: 'SDAH', name: 'Seventh-day Adventist Hymnal' },
    { id: 'CH', name: 'The Church Hymnal' }
];

for (const expected of expectedSongbooks) {
    test(`songbook ${expected.id} exists with correct name`, () => {
        const sb = songData.songbooks.find(s => s.id === expected.id);
        assert.ok(sb, `Songbook ${expected.id} not found`);
        assert.equal(sb.name, expected.name);
        assert.ok(sb.songCount > 0, `${expected.id} songCount should be > 0`);
    });
}

test('songbook song counts match actual songs', () => {
    for (const sb of songData.songbooks) {
        const actualCount = songData.songs.filter(s => s.songbook === sb.id).length;
        assert.equal(actualCount, sb.songCount,
            `${sb.id}: expected ${sb.songCount} songs, found ${actualCount}`);
    }
});

/* =========================================================================
 * SONG PROPERTY TESTS
 * ========================================================================= */

console.log('');
console.log('🎵 Song Property Tests');

test('all songs have required properties', () => {
    const requiredProps = ['id', 'number', 'title', 'songbook', 'songbookName',
                           'writers', 'composers', 'copyright', 'ccli',
                           'hasAudio', 'hasSheetMusic', 'components'];
    for (const song of songData.songs) {
        for (const prop of requiredProps) {
            assert.ok(prop in song, `Song ${song.id} missing property: ${prop}`);
        }
    }
});

test('all song IDs are unique', () => {
    const ids = songData.songs.map(s => s.id);
    const uniqueIds = new Set(ids);
    assert.equal(ids.length, uniqueIds.size, `Duplicate song IDs found`);
});

test('all song IDs follow pattern <SONGBOOK>-<PADDED_NUMBER>', () => {
    for (const song of songData.songs) {
        const match = song.id.match(/^[A-Z]+-\d{4}$/);
        assert.ok(match, `Invalid song ID format: ${song.id}`);
    }
});

test('all songs have non-empty titles', () => {
    for (const song of songData.songs) {
        assert.ok(song.title.length > 0, `Song ${song.id} has empty title`);
    }
});

test('all songs have positive numbers', () => {
    for (const song of songData.songs) {
        assert.ok(song.number > 0, `Song ${song.id} has number ${song.number}`);
    }
});

test('all songs have valid songbook reference', () => {
    const songbookIds = new Set(songData.songbooks.map(sb => sb.id));
    for (const song of songData.songs) {
        assert.ok(songbookIds.has(song.songbook),
            `Song ${song.id} references unknown songbook: ${song.songbook}`);
    }
});

test('writers and composers are arrays', () => {
    for (const song of songData.songs) {
        assert.ok(Array.isArray(song.writers), `Song ${song.id} writers is not an array`);
        assert.ok(Array.isArray(song.composers), `Song ${song.id} composers is not an array`);
    }
});

test('hasAudio and hasSheetMusic are booleans', () => {
    for (const song of songData.songs) {
        assert.equal(typeof song.hasAudio, 'boolean', `Song ${song.id} hasAudio is not boolean`);
        assert.equal(typeof song.hasSheetMusic, 'boolean', `Song ${song.id} hasSheetMusic is not boolean`);
    }
});

test('components is an array', () => {
    for (const song of songData.songs) {
        assert.ok(Array.isArray(song.components), `Song ${song.id} components is not an array`);
    }
});

/* =========================================================================
 * COMPONENT TESTS
 * ========================================================================= */

console.log('');
console.log('🎼 Component Tests');

const validComponentTypes = ['verse', 'chorus', 'refrain', 'bridge',
    'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude'];

test('all components have valid type', () => {
    for (const song of songData.songs) {
        for (const comp of song.components) {
            assert.ok(validComponentTypes.includes(comp.type),
                `Song ${song.id} has invalid component type: ${comp.type}`);
        }
    }
});

test('all components have lines array', () => {
    for (const song of songData.songs) {
        for (const comp of song.components) {
            assert.ok(Array.isArray(comp.lines),
                `Song ${song.id} component has non-array lines`);
        }
    }
});

test('songs with components have at least one line', () => {
    let songsWithContent = 0;
    for (const song of songData.songs) {
        if (song.components.length > 0) {
            const totalLines = song.components.reduce((sum, c) => sum + c.lines.length, 0);
            if (totalLines > 0) songsWithContent++;
        }
    }
    assert.ok(songsWithContent > 3500, `Expected > 3500 songs with lyrics, got ${songsWithContent}`);
});

/* =========================================================================
 * SPECIFIC SONG TESTS (known songs for regression testing)
 * ========================================================================= */

console.log('');
console.log('🔍 Regression Tests (Known Songs)');

test('CH-0003 "Come, Thou Almighty King" parsed correctly', () => {
    const song = songData.songs.find(s => s.id === 'CH-0003');
    assert.ok(song, 'CH-0003 not found');
    assert.equal(song.title, 'Come, Thou Almighty King');
    assert.equal(song.number, 3);
    assert.equal(song.songbook, 'CH');
    assert.equal(song.components.length, 3);
    assert.equal(song.components[0].type, 'verse');
    assert.equal(song.components[0].number, 1);
    assert.ok(song.components[0].lines.length >= 4);
});

test('CH-0563 "Softly and Tenderly" has refrain', () => {
    const song = songData.songs.find(s => s.id === 'CH-0563');
    assert.ok(song, 'CH-0563 not found');
    const refrain = song.components.find(c => c.type === 'refrain');
    assert.ok(refrain, 'Should have a refrain component');
    assert.ok(refrain.lines.length >= 3);
});

test('MP-0050 "Be Still" has writer credits', () => {
    const song = songData.songs.find(s => s.id === 'MP-0050');
    assert.ok(song, 'MP-0050 not found');
    assert.ok(song.writers.length > 0, 'Should have writers');
    assert.ok(song.writers[0].includes('David'), 'Writer should be David J Evans');
    assert.ok(song.hasAudio, 'Should have audio');
});

test('SDAH-0001 "Praise to the Lord" parsed without quotes', () => {
    const song = songData.songs.find(s => s.id === 'SDAH-0001');
    assert.ok(song, 'SDAH-0001 not found');
    assert.equal(song.title, 'Praise to the Lord');
    assert.ok(song.components.length >= 3, 'Should have at least 3 verses');
});

test('CP-0001 has audio and sheet music flags', () => {
    const song = songData.songs.find(s => s.id === 'CP-0001');
    assert.ok(song, 'CP-0001 not found');
    assert.ok(song.hasAudio, 'Should have audio');
    assert.ok(song.hasSheetMusic, 'Should have sheet music');
    assert.ok(song.writers.length > 0, 'Should have writers');
});

/* =========================================================================
 * JSON VALIDITY TESTS
 * ========================================================================= */

console.log('');
console.log('📋 JSON Validity Tests');

test('manifest.json is valid', () => {
    const manifestPath = path.join(PROJECT_ROOT, 'appWeb', 'public_html_beta', 'manifest.json');
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));
    assert.ok(manifest.name, 'manifest.json missing name');
    assert.ok(manifest.short_name, 'manifest.json missing short_name');
    assert.ok(manifest.start_url, 'manifest.json missing start_url');
    assert.equal(manifest.display, 'standalone');
});

test('package.json is valid', () => {
    const pkgPath = path.join(PROJECT_ROOT, 'package.json');
    const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'));
    assert.ok(pkg.name, 'package.json missing name');
    assert.ok(pkg.version, 'package.json missing version');
    assert.equal(pkg.type, 'module');
});

/* =========================================================================
 * RESULTS SUMMARY
 * ========================================================================= */

console.log('');
console.log('══════════════════════════════════════════════════');
console.log(`✅ Passed: ${passed}`);
console.log(`❌ Failed: ${failed}`);
console.log(`📊 Total:  ${passed + failed}`);

if (failures.length > 0) {
    console.log('');
    console.log('Failures:');
    for (const f of failures) {
        console.log(`  ❌ ${f.name}: ${f.error}`);
    }
}

console.log('');

/* Exit with non-zero if any tests failed */
process.exit(failed > 0 ? 1 : 0);
