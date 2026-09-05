/**
 * iHymns — format-export.js ↔ export-filename.js Naming Agreement (#1721)
 *
 * PURPOSE:
 * ELI5: makes sure a song exported as OpenSong/ChordPro/VideoPsalm/… from
 * format-export.js gets named EXACTLY the way the rest of the app already
 * names an exported song ("042 (CIS) - Amazing Grace (New Britain)"),
 * instead of the smaller, undocumented "42 Amazing Grace" scheme
 * format-export.js used to build entirely on its own.
 *
 * Detail: `js/modules/export-filename.js` is the app's one shared
 * filename convention (used by the editor's raw single-song JSON export
 * and the whole-songbook bundle download). `manage/editor/format-export.js`
 * — the module every worship-format exporter actually calls — had its own,
 * separate `baseFilename()`/`sanitizeFilename()` pair that never agreed
 * with it (CLAUDE.md rule #35: two things that must agree need a
 * mechanism). format-export.js is a plain classic <script> (deliberately —
 * a dozen Node tests `require()` it as CommonJS, e.g.
 * tests/test-chordpro-export.js, and it would break every one of them the
 * moment it gained a top-level `import`), so it can't `import` the shared
 * module directly; instead it reads it off a global,
 * `window.iHymnsExportFilename`, that export-filename.js optionally
 * exposes itself as — see the long comment on `baseFilename()` for exactly
 * how, and why that's the same pattern `buildZip()` already uses to reach
 * `window.iHymnsProPresenter`.
 *
 * This file proves BOTH halves of that contract:
 *   1. When the global IS present (the real case on every page this task
 *      touched: manage/editor/v2/export.js and the public
 *      js/modules/export-ui.js), format-export.js's baseFilename()
 *      produces the IDENTICAL string export-filename.js's own
 *      songExportFilename() would — not a close copy, the same call.
 *   2. When the global is ABSENT (a page that hasn't loaded
 *      export-filename.js — today, only the legacy v1 editor), it
 *      degrades to the old naming rather than throwing, so that page
 *      keeps exporting instead of breaking outright.
 *
 * MUTATION-TESTED (rule #34): reverting baseFilename() to read straight
 * from `song.number`/`song.title` (ignoring the global) was confirmed to
 * turn case 1 red while leaving case 2 green; restoring the delegation
 * turns case 1 back green. (Checked by hand while writing this test — see
 * the commit that introduced this file.)
 *
 *   USAGE:  node tests/test-format-export-filename.js
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
    __dirname, '..', 'appWeb/public_html/manage/editor/format-export.js'
);
const EXPORT_FILENAME_PATH = path.resolve(
    __dirname, '..', 'appWeb/public_html/js/modules/export-filename.js'
);

/* The REAL shared helper, loaded as the ES module it is — not a stand-in. */
const { songExportFilename } = await import(EXPORT_FILENAME_PATH);

let passed = 0;
let failed = 0;
const failures = [];
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  PASS  ${name}`);
    } catch (err) {
        failed++;
        failures.push({ name, error: err.message });
        console.log(`  FAIL  ${name}`);
        console.log(`        ${err.message}`);
    }
}

const SONG_WITH_TUNE = { number: 42, title: 'Amazing Grace', songbook: 'CIS', tuneName: 'New Britain' };
const SONG_NO_TUNE   = { number: 7,  title: 'Joy to the World', songbook: 'MP' };

/* format-export.js is a plain global script (see its own header comment):
   require()ing it for side effect sets globalThis.iHymnsFormatExport,
   reading globalThis.iHymnsExportFilename EACH TIME baseFilename() is
   called (not once at load time) — so toggling the global between the two
   test blocks below, with no re-require needed, exercises exactly what a
   real page transition would. */
require(FORMAT_EXPORT_PATH);
const fmt = globalThis.iHymnsFormatExport;
const baseFilename = fmt._internal.baseFilename;

test('_internal.baseFilename is exposed for this test to reach', () => {
    assert.equal(typeof baseFilename, 'function');
});

/* ---- 1. helper present: format-export.js must defer to it, verbatim ---- */
delete globalThis.iHymnsExportFilename;
globalThis.window = globalThis.window || {};
globalThis.window.iHymnsExportFilename = { songExportFilename };
// format-export.js's IIFE was invoked with `globalThis` as its `global`
// param under Node (no real `window` exists) — see its own bottom line —
// so the global it actually reads is `globalThis.iHymnsExportFilename`,
// mirrored here the same way the browser's `window.iHymnsExportFilename`
// assignment in export-filename.js would be (window === globalThis there).
globalThis.iHymnsExportFilename = { songExportFilename };

test('helper present: matches songExportFilename() exactly (with a tune)', () => {
    assert.equal(
        baseFilename(SONG_WITH_TUNE),
        songExportFilename(SONG_WITH_TUNE, [])
    );
    assert.equal(baseFilename(SONG_WITH_TUNE), '042 (CIS) - Amazing Grace (New Britain)');
});

test('helper present: matches songExportFilename() exactly (no tune)', () => {
    assert.equal(
        baseFilename(SONG_NO_TUNE),
        songExportFilename(SONG_NO_TUNE, [])
    );
    assert.equal(baseFilename(SONG_NO_TUNE), '007 (MP) - Joy to the World');
});

/* ---- 2. helper absent: graceful degrade, not a throw ---- */
delete globalThis.iHymnsExportFilename;
delete globalThis.window.iHymnsExportFilename;

test('helper absent: degrades to the old "<number> <title>" naming, no throw', () => {
    assert.doesNotThrow(() => baseFilename(SONG_WITH_TUNE));
    assert.equal(baseFilename(SONG_WITH_TUNE), '42 Amazing Grace');
});

test('helper absent: a song with no number falls back to the title alone', () => {
    assert.equal(baseFilename({ title: 'Untitled Hymn' }), 'Untitled Hymn');
});

console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    failures.forEach((f) => console.log(`  - ${f.name}: ${f.error}`));
    process.exit(1);
}
