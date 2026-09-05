/**
 * iHymns — v2 Editor Export Menu ↔ format-export.js Registry Agreement (#2068)
 *
 * PURPOSE:
 * ELI5: makes sure every worship file format the app actually knows how to
 * build (OpenSong, ChordPro, VideoPsalm, …) also shows up as a button in
 * the newer Song Editor's Export ▾ menu. ChordPro was fully built, tested,
 * and already offered on the public song page and (per its own doc-comment)
 * was clearly INTENDED to be in this menu — but the menu's hand-typed list
 * never actually got a row for it, so it was reachable everywhere except
 * the one place a curator was most likely to look for it.
 *
 * Detail: manage/editor/v2/export.js's `ITEMS` array is a hand-typed list
 * of [label, handler] pairs. window.iHymnsFormatExport (format-export.js)
 * is the actual, single source of truth for which formats the app can
 * build — CLAUDE.md rule #35 ("two things that must agree need a
 * mechanism, not a comment") applies directly here: nothing PHP/JS/test
 * ever compared the two lists, so they silently drifted (ChordPro was
 * added to format-export.js and never carried over). This test is that
 * mechanism.
 *
 * How it derives the "real" list (rule #34 — never a typed list of our
 * own): it `require()`s format-export.js for real, the same way
 * tests/test-chordpro-export.js does, and reads off every OWN key on the
 * object it exposes — nothing here is retyped from memory. `_internal` is
 * skipped (that's a helper bag for other tests, not a format). ProPresenter
 * 7 is checked separately: it isn't a key on window.iHymnsFormatExport at
 * all (it's a distinct binary-protobuf exporter, window.iHymnsProPresenter,
 * consumed via export.js's own `pp7`/`exportPP7` special case) so it can't
 * be found by scanning the registry — this file asserts that special case
 * exists instead.
 *
 * Comments are stripped from export.js's source before scanning so a
 * format merely MENTIONED in a doc-comment (as ChordPro was, for years,
 * in the `buildExportSong()` header — see git history) can't
 * false-positive a `runFmt(...)` call that was never actually written.
 *
 * MUTATION-TESTED (rule #34): commenting out the `['ChordPro', ... ]` ITEMS
 * row (the exact bug this file exists to catch) was confirmed to turn the
 * "offers chordPro" case red; restoring the row turns it green again — see
 * the commit that introduced this file.
 *
 *   USAGE:  node tests/test-v2-export-menu-formats.js
 *   Exit status 0 = all pass, 1 = at least one assertion failed.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const require    = createRequire(import.meta.url);

const FORMAT_EXPORT_PATH = path.resolve(
    __dirname, '..', 'appWeb/public_html/manage/editor/format-export.js'
);
const V2_EXPORT_PATH = path.resolve(
    __dirname, '..', 'appWeb/public_html/manage/editor/v2/export.js'
);

/* format-export.js is a plain global script (see its own header comment):
   requiring it for side effect sets globalThis.iHymnsFormatExport, the same
   contract the browser gets from a plain <script src="format-export.js">
   tag. This is the REAL registry — not a copy of it. */
require(FORMAT_EXPORT_PATH);
const fmt = globalThis.iHymnsFormatExport;

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

/* The tree-derived list of formats format-export.js actually builds —
   every own key on the exported object except the `_internal` helper bag.
   Never hand-typed: add a tenth format to format-export.js tomorrow and
   this array grows with it automatically. */
const REAL_FORMAT_KEYS = Object.keys(fmt).filter((k) => k !== '_internal');

const v2SourceRaw = fs.readFileSync(V2_EXPORT_PATH, 'utf8');
/* Strip /* … *\/ block comments and // line comments so a format that is
   only MENTIONED in prose can't satisfy the regex below. (Simple, not a
   full tokenizer — this file has no string literals containing `//` or
   `/*` in a way that would confuse it; the same approach is used by
   tests/test-component-label-sites.js and test-voice-render-sites.js
   against this same source tree.) */
const v2Source = v2SourceRaw
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

test('sanity: format-export.js exposes a real, non-trivial format registry', () => {
    assert.ok(
        REAL_FORMAT_KEYS.length >= 6,
        `expected several formats on window.iHymnsFormatExport, found: [${REAL_FORMAT_KEYS.join(', ')}] — ` +
        'this usually means format-export.js failed to load, not that formats went away.'
    );
});

for (const key of REAL_FORMAT_KEYS) {
    test(`v2 Export menu offers "${key}" (calls runFmt('${key}'))`, () => {
        const pattern = new RegExp(`runFmt\\(\\s*['"]${key}['"]\\s*\\)`);
        assert.match(
            v2Source,
            pattern,
            `format-export.js can build "${key}", but manage/editor/v2/export.js's ITEMS list never ` +
            `calls runFmt('${key}') — the v2 Export menu is missing this format. Add a row to ITEMS.`
        );
    });
}

test('v2 Export menu offers ProPresenter 7 via its own pp7.exportSong() code path', () => {
    /* PP7 is deliberately NOT a window.iHymnsFormatExport key (it's a
       separate binary-protobuf exporter) so it can't be picked up by the
       loop above — checked explicitly instead. */
    assert.match(
        v2Source,
        /pp7\.exportSong\s*\(/,
        'expected the ProPresenter 7 special-case handler (exportPP7) to call pp7.exportSong(...)'
    );
});

console.log('');
console.log(`${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.log('');
    console.log('Failures:');
    failures.forEach((f) => console.log(`  - ${f.name}: ${f.error}`));
    process.exit(1);
}
