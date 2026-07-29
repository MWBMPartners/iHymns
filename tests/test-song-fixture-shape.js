/**
 * iHymns — Song Interchange Format Shape Tests
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Structural validation of the iHymns song interchange format (formerly
 * "songs.json") against a small SYNTHETIC fixture, plus a couple of
 * unrelated sanity checks on repo-root JSON files.
 *
 * HISTORY (#1617):
 * This file used to be tests/test-song-parser.js and asserted on the real
 * ~3,600-song data/songs.json corpus (frozen 2026-04-13, ~4x stale versus
 * the live MySQL catalogue and never regenerated in CI — CI has no
 * database and never ran this file). It never actually invoked the parser
 * (tools/parse-songs.js) either, despite the name — it only re-validated
 * whatever songs.json happened to be checked out. Both the corpus-specific
 * regression assertions (checks pinned to real hymns like "CH-0003") and
 * the corpus file itself were retired when data/songs.json was removed —
 * MySQL is the live source of truth (WS-J #1020, CLAUDE.md rule #17) and
 * copying real hymn lyrics into a public-repo test fixture would itself be
 * a copyright problem.
 *
 * What survives here is everything that was never corpus-dependent: the
 * *shape* tests, now run against tests/fixtures/synthetic-songs.json (5
 * invented songs, no real titles or lyrics) instead of the real corpus,
 * plus the manifest.json / package.json validity checks below.
 *
 * USAGE:
 *   node tests/test-song-fixture-shape.js
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
const FIXTURE_JSON = path.join(__dirname, 'fixtures', 'synthetic-songs.json');

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
 * LOAD FIXTURE DATA
 * ========================================================================= */

console.log('');
console.log('🧪 iHymns — Song Interchange Format Shape Tests');
console.log('══════════════════════════════════════════════════');

/* Verify the fixture exists */
test('synthetic-songs.json fixture exists', () => {
    assert.ok(fs.existsSync(FIXTURE_JSON), 'tests/fixtures/synthetic-songs.json does not exist');
});

/* Parse the JSON */
const rawJson = fs.readFileSync(FIXTURE_JSON, 'utf-8');
let songData;

test('synthetic-songs.json is valid JSON', () => {
    songData = JSON.parse(rawJson);
    assert.ok(songData, 'Failed to parse synthetic-songs.json');
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
    assert.ok(songData.songbooks.length > 0, 'Should have at least one songbook');
});

test('has songs array', () => {
    assert.ok(Array.isArray(songData.songs), 'songs should be an array');
    assert.ok(songData.songs.length > 0, 'Should have at least one song');
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
    'pre-chorus', 'tag', 'coda', 'intro', 'outro', 'interlude', 'vamp', 'ad-lib'];

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
    assert.equal(songsWithContent, songData.songs.length,
        `Expected every fixture song to carry lyrics, got ${songsWithContent}/${songData.songs.length}`);
});

/* =========================================================================
 * JSON VALIDITY TESTS
 * ========================================================================= */

console.log('');
console.log('📋 JSON Validity Tests');

test('manifest.json is valid', () => {
    const manifestPath = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manifest.json');
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
