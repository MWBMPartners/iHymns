#!/usr/bin/env node
'use strict';

/**
 * tools/interchange-gen-samples.js — interchange closure fixture generator (#1129)
 * ===================================================================================
 *
 * ELI5
 * ----
 * Builds ONE small, hand-authored iHymns song, runs it through EVERY worship-format
 * exporter this app ships in `format-export.js` (OpenSong, OpenLP/OpenLyrics,
 * ProPresenter 6, Proclaim, VideoPsalm, FreeShow, ChordPro), and writes each result to
 * its own file. `tests/php/test-interchange-roundtrip.php` then feeds those files back
 * into our REAL PHP importers and checks what comes out against a hand-verified
 * expected dict per format — the SAME "two independently-implemented halves of this
 * app agree with each other" proof `tools/pp7-gen-roundtrip-sample.js` established for
 * ProPresenter 7+ (read that file's doc-block first; this one is its sibling for the
 * seven `format-export.js` formats instead of the one binary `.pro` format, which stays
 * OUT of scope here — see `tests/php/test-interchange-roundtrip.php`'s own header for
 * why PP7 is deliberately excluded and how its row is folded into this harness's matrix
 * by annotation instead of duplicated).
 *
 * DETAILED
 * --------
 * `format-export.js` sets BOTH `module.exports` and `globalThis.iHymnsFormatExport`
 * (see its final two lines) — `tests/test-chordpro-export.js:33-42` already proves the
 * plain-`require()` path works under Node with no DOM/vm-sandbox needed (unlike PP7's
 * `buildPresentation()`, none of these seven `build*()` functions touch `window` /
 * `document` / `protobufjs`; only their `exportSong*()`/`exportSongbook*()` wrappers do,
 * via `download()`, which this script never calls). So this generator is deliberately
 * simpler than `pp7-gen-roundtrip-sample.js`: a plain `require()`, no `vm.createContext`.
 *
 * WHICH FORMATS: `Object.keys(iHymnsFormatExport)` MINUS the one known non-format
 * utility key, `_internal` (rule #34 — never a typed format list; a future 8th format
 * added to the api registry is picked up here automatically). Each discovered key is
 * looked up in the local `SERIALIZERS` map below for (a) the file extension and (b) how
 * to turn that format's `build()` return value into file bytes — this per-format glue is
 * unavoidable (VideoPsalm's `build()` returns a bare per-song object that needs wrapping
 * into the `{Text,Songs:[…]}` SHAPE its own importer requires, exactly as
 * `exportSongVideoPsalm()` does; FreeShow's `build()` already returns the on-disk
 * `[id,show]` tuple, so it only needs `JSON.stringify`; the rest are plain strings) — but
 * the ENUMERATION of which formats need it stays tree-derived, and a format key with no
 * `SERIALIZERS` entry throws loudly rather than being silently skipped.
 *
 * THE FIXTURE (`FIXTURE_SONG` below) is single-source-of-truth for
 * `tests/php/test-interchange-roundtrip.php`'s expected dicts — if this object changes,
 * every expected dict must be re-traced (mirrors `pp7-gen-roundtrip-sample.js`'s own
 * `SAMPLE_SONG` doc-block note). It deliberately exercises, per the #1129 brief:
 *   - writers[] DISTINCT from composers[] (so every format's writer/composer-merging
 *     quirk — folded into one CCLI-style string, or kept as separate XML elements — is
 *     visible in the diff rather than accidentally invisible because the two lists
 *     happened to be the same names).
 *   - a copyright string that itself carries a 4-digit YEAR ('© 1987 Fixture Music') —
 *     none of these seven exporters extracts the year into a separate field the way
 *     PP7's `buildCCLIPayload()` does, so this fixture proves that (copyright round-
 *     trips as ONE opaque string everywhere it round-trips at all).
 *   - a ccli number, a tuneName (OpenSong-only field), an alternateTitle/key/capo
 *     (ChordPro-only fields, and — per the harness's defect-3 finding — fields the real
 *     `SongData::getSongById()` row shape never actually populates: `alternateTitle`
 *     singular doesn't exist there at all, only `alternativeTitles` plural; `key`/`capo`
 *     don't exist there either).
 *   - a NON-natural `arrangement` ([2,0,1], i.e. NOT the identity [0,1,2]) — set on the
 *     input song object even though NONE of these seven `build()` functions ever reads
 *     `song.arrangement` (confirmed: only `propresenter-export.js`'s PP7 builder does).
 *     This is the fixture's proof of the harness's own header claim: OpenLyrics's
 *     `buildOpenLyrics()` (format-export.js:419) always emits a natural-order
 *     `<verseOrder>` from component ITERATION order, never from `song.arrangement` — so
 *     re-importing our own OpenLyrics export always resolves the identity permutation,
 *     which `_bulkImport_parseOpenLyrics()`'s identity-suppression rule (#2062) collapses
 *     to "no arrangement key at all". A blanket `exported == reimported` harness would
 *     have no way to express "the input arrangement was non-trivial and is CORRECTLY,
 *     DELIBERATELY absent from the output" — which is exactly why this harness asserts
 *     hand-traced expected dicts instead.
 *   - per-component `chords`, in BOTH shapes the app's own data model produces (see
 *     format-export.js's own "DATA SHAPE" doc-block on `buildChordPro`): a POSITIONED
 *     STRING cell ('G       D', chord names separated by run-length whitespace — the
 *     shape a live editor textarea can transiently hold) on verse 1, and an ARRAY cell
 *     (['C','G'], the shape every persisted `ChordsJson`-backed read produces) on the
 *     chorus — PLUS a third component (verse 2) with NO `chords` key at all, the
 *     chordless case. Only ChordPro's exporter ever reads `comp.chords` (confirmed: no
 *     other format-export.js builder does), so this dimension exercises ChordPro
 *     specifically; the harness's own header explains why (`chordProChordTokens()`
 *     collapses BOTH cell shapes to the same ordered token list, discarding the string
 *     cell's actual whitespace POSITIONING — unlike PP7's column-anchored chord model).
 *   - a per-line `notes` array and a component `language` + `label` — deliberately
 *     included even though NO exporter in `format-export.js` ever reads `comp.notes`,
 *     `comp.language`, or `comp.label` (confirmed by grepping every `comp.` / `song.`
 *     identifier the file actually dereferences) — so the harness can assert, format by
 *     format, that these are UNIVERSALLY and SILENTLY dropped on our own export, never
 *     accidentally carried through by some format nobody expected to support them.
 *
 * Usage:
 *   node tools/interchange-gen-samples.js <output-dir>
 *
 * Prints a JSON manifest to stdout: { "<formatKey>": "<absolute-path>", ... } — one
 * entry per key `SERIALIZERS` handled (i.e. every non-`_internal` key of
 * `iHymnsFormatExport`, unless the enumeration/serializer mismatch below throws first).
 *
 * Exit status: 0 = every format's bytes written, 1 = any failure (missing arg, a new
 * format key with no SERIALIZERS entry, a build() throw).
 *
 * @see appWeb/public_html/manage/editor/format-export.js   the seven build() functions under test
 * @see appWeb/public_html/includes/song_importers.php       the seven importers under test
 * @see tests/php/test-interchange-roundtrip.php             the consumer of this script's output
 * @see tools/pp7-gen-roundtrip-sample.js                     the PP7 sibling this generator is modelled on
 * @see tests/test-chordpro-export.js:33-42                   proves format-export.js is require()-able under plain Node
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

/* This repo's package.json sets "type":"module", so plain .js files are ESM by
   default and have no __dirname/require of their own — mirrors the exact
   createRequire() technique tests/test-chordpro-export.js:27-29 already uses to pull
   in format-export.js's CommonJS `module.exports` from an ESM script. */
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const require = createRequire(import.meta.url);

const REPO_ROOT = path.resolve(__dirname, '..');
const FORMAT_EXPORT_PATH = path.join(
    REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'format-export.js'
);

/**
 * THE fixture song — see the file header for the full "why this shape" trace. Every
 * expected value in `tests/php/test-interchange-roundtrip.php` was hand-traced through
 * this exact object; if it changes, every expected dict there must be re-derived.
 */
const FIXTURE_SONG = {
    id: 'IX-0007',
    number: 7,
    title: 'Interchange Fixture Song',
    songbook: 'IX',
    songbookName: 'Interchange Fixtures',
    writers: ['Ada Writer', 'Bea Writer'],
    composers: ['Cy Composer'],
    copyright: '© 1987 Fixture Music',
    ccli: '1234567',
    tuneName: 'FIXTURE TUNE',
    alternateTitle: 'The Other Title',
    key: 'D',
    capo: 3,
    /* Deliberately NON-natural (not the identity [0,1,2]) — see the file header's
       "NON-natural arrangement" note. No format-export.js build() function reads this
       field; it exists to prove that, not to round-trip through it. */
    arrangement: [2, 0, 1],
    components: [
        {
            type: 'verse', number: 1,
            language: 'en',
            label: 'Opening Verse',
            lines: ['Line one of verse one', 'Line two of verse one'],
            /* POSITIONED STRING cell (run-length whitespace, not word-aligned) on
               line 0; line 1 carries no chords of its own. */
            chords: ['G       D', null],
            /* Per-line note — read by no exporter here; proves the universal drop. */
            notes: [null, 'A per-line note on line two']
        },
        {
            type: 'chorus',
            lines: ['Chorus line one', 'Chorus line two'],
            /* ARRAY cell (word-start aligned) on line 0; line 1 has none. */
            chords: [['C', 'G'], null]
        },
        {
            type: 'verse', number: 2,
            lines: ['Line one of verse two', 'Line two of verse two']
            /* No `chords` key at all — the chordless-component case. */
        }
    ]
};

/**
 * Per-format (extension, serializer) glue. `serialize(built)` turns that format's
 * `build(FIXTURE_SONG, {})` return value into the exact bytes/text this script writes
 * to disk — mirroring what each format's OWN `exportSong*()` wrapper does internally
 * before handing bytes to `download()` (which this script never calls, since
 * `download()` is a browser-only no-op under Node — see the file header).
 *
 * A format key present in `iHymnsFormatExport` but ABSENT from this map is a hard
 * error (see the enumeration loop below) rather than a silent skip — rule #34's "a
 * scanner that under-reports is worse than no scanner" applies just as much to a
 * generator as to a test.
 */
const SERIALIZERS = {
    openSong: {
        ext: 'xml',
        serialize: (api) => api.openSong.build(FIXTURE_SONG, {})
    },
    openLyrics: {
        ext: 'xml',
        serialize: (api) => api.openLyrics.build(FIXTURE_SONG, {})
    },
    proPresenter6: {
        ext: 'pro6',
        serialize: (api) => api.proPresenter6.build(FIXTURE_SONG, {})
    },
    proclaim: {
        ext: 'txt',
        serialize: (api) => api.proclaim.build(FIXTURE_SONG, {})
    },
    videoPsalm: {
        ext: 'json',
        /* VideoPsalm's native unit is a whole SONGBOOK ({Text,Songs:[…]}); a single
           song exports as a 1-song songbook so its own importer
           (_bulkImport_parseVideoPsalmSongbook, which requires a top-level Songs[]
           array) can read it back — exactly what exportSongVideoPsalm() does
           (format-export.js:229-235), reproduced here since build() alone returns
           only the bare per-song object. */
        serialize: (api) => {
            const built = api.videoPsalm.build(FIXTURE_SONG, {});
            const book = { Text: String(FIXTURE_SONG.title || 'Untitled'), Songs: [built] };
            return JSON.stringify(book, null, 2);
        }
    },
    freeShow: {
        ext: 'show',
        /* build() already returns the on-disk [id, show] tuple (see buildFreeShow's
           own doc-block) — exportSongFreeShow() just JSON.stringifies it verbatim. */
        serialize: (api) => JSON.stringify(api.freeShow.build(FIXTURE_SONG, {}))
    },
    chordPro: {
        ext: 'cho',
        serialize: (api) => api.chordPro.build(FIXTURE_SONG, {})
    }
};

function main() {
    const outDir = process.argv[2];
    if (!outDir) {
        console.error('usage: node tools/interchange-gen-samples.js <output-dir>');
        process.exit(1);
    }
    fs.mkdirSync(outDir, { recursive: true });

    /* format-export.js is a plain global script: requiring it for SIDE EFFECT sets
       globalThis.iHymnsFormatExport (the same contract the browser consumes) —
       tests/test-chordpro-export.js:33-42 establishes this exact pattern already.
       Reading require()'s OWN return value here would NOT work: this repo's
       package.json sets "type":"module", so a plain .js file loaded via
       createRequire() from an ESM script runs under Node's CJS-in-ESM interop
       shim, where format-export.js's `if (typeof module !== 'undefined')` guard
       finds no `module` binding in scope (module.exports is never reached) even
       though the IIFE's OWN `global` parameter — always bound to globalThis, see
       format-export.js's last line — sets globalThis.iHymnsFormatExport
       unconditionally. Confirmed empirically before writing this comment. */
    require(FORMAT_EXPORT_PATH);
    const api = globalThis.iHymnsFormatExport;
    if (!api || typeof api !== 'object') {
        console.error('format-export.js did not set globalThis.iHymnsFormatExport');
        process.exit(1);
    }

    /* rule #34 — DERIVE the format list from the live registry, never type it out. */
    const formatKeys = Object.keys(api).filter((k) => k !== '_internal');
    if (formatKeys.length < 5) {
        console.error(`vacuity check failed: only ${formatKeys.length} format key(s) found on iHymnsFormatExport`);
        process.exit(1);
    }

    const manifest = {};
    for (const key of formatKeys) {
        const spec = SERIALIZERS[key];
        if (!spec) {
            console.error(
                `iHymnsFormatExport.${key} has no SERIALIZERS entry in tools/interchange-gen-samples.js — `
                + 'a new export format was added but this generator (and the harness\'s expected-dict '
                + 'completeness check) was not updated for it.'
            );
            process.exit(1);
        }
        let body;
        try {
            body = spec.serialize(api);
        } catch (err) {
            console.error(`iHymnsFormatExport.${key}.build() threw: ${(err && err.stack) || err}`);
            process.exit(1);
        }
        const outPath = path.join(outDir, `${key}.${spec.ext}`);
        fs.writeFileSync(outPath, body, 'utf8');
        manifest[key] = outPath;
    }

    console.log(JSON.stringify(manifest));
}

main();
