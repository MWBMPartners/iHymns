/**
 * iHymns — ProPresenter Export CLI Smoke Tool
 * ===========================================
 *
 * Runs the browser exporter under Node and writes one or more `.pro`
 * files (and optionally a ZIP) to disk so an operator can drag-drop
 * them into ProPresenter to validate the import path end-to-end
 * without firing up the editor UI.
 *
 * Usage:
 *   node tools/export-pro-sample.js                    # first fixture song
 *   node tools/export-pro-sample.js --id TF-0001       # by song id
 *   node tools/export-pro-sample.js --songbook TF --limit 5
 *   node tools/export-pro-sample.js --all --zip out.zip
 *   node tools/export-pro-sample.js --json path/to/bundle.json --all
 *
 * By default this reads the small synthetic fixture at
 * tests/fixtures/synthetic-songs.json (#1617 — the real ~14k-song corpus
 * is live MySQL only, never a whole-corpus file; see CLAUDE.md rule #17).
 * Pass --json to point at a real export instead — e.g. one songbook's
 * bundle pulled from the editor's own
 * `?action=songbook_export&abbr=<ABBR>` endpoint (manage/editor/api.php),
 * which is scoped to a single songbook, never the whole catalogue.
 * --json accepts either that per-songbook shape (`{songs, songbook}`) or
 * the multi-songbook interchange shape (`{meta, songbooks, songs}`) that
 * the fixture and tools/parse-songs.js both use.
 *
 * Output is written to ./tmp/propresenter-samples/ by default.
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const SCRIPT_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'propresenter-export.js'
);
const BUNDLE_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);
const DEFAULT_SONGS_PATH = path.join(PROJECT_ROOT, 'tests', 'fixtures', 'synthetic-songs.json');

/* Parse command-line flags. Tiny in-house parser to avoid pulling in
   yargs/minimist for what is effectively a debug utility. */
function parseArgs(argv) {
    const out = { all: false, limit: 1, linesPerSlide: 0, pre: 'lyrics' };

    /* Treat the next argv slot as the flag's value only when it
       doesn't itself look like a flag — keeps `--probundle` (no
       value) from greedily consuming the following flag. */
    function takeValue(i) {
        const next = argv[i + 1];
        if (next != null && !next.startsWith('--')) return next;
        return undefined;
    }

    for (let i = 2; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--all') out.all = true;
        else if (a === '--id') out.id = argv[++i];
        else if (a === '--songbook') out.songbook = argv[++i];
        else if (a === '--limit') out.limit = parseInt(argv[++i], 10);
        else if (a === '--zip') {
            const v = takeValue(i);
            out.zip = v || true;
            if (v) i++;
        }
        else if (a === '--probundle') {
            const v = takeValue(i);
            out.probundle = v || true;
            if (v) i++;
        }
        else if (a === '--lines-per-slide') out.linesPerSlide = parseInt(argv[++i], 10) || 0;
        else if (a === '--pre') out.pre = argv[++i] || 'lyrics';
        else if (a === '--out') out.out = argv[++i];
        else if (a === '--json') out.json = argv[++i];
        else if (a === '--help' || a === '-h') out.help = true;
    }
    return out;
}

function help() {
    console.log(`Usage: node tools/export-pro-sample.js [options]

  --id <song-id>          Export the song with this id.
  --songbook <abbr>       Limit to one songbook.
  --all                   Export every song (or every match).
  --limit <n>             Cap the export count (default 1).
  --zip [filename]        Bundle the exports into a ZIP.
  --probundle [filename]  Bundle the exports into a .probundle.
  --lines-per-slide <n>   Chunk components into N-line slides (0 = off).
  --pre <mode>            Pre-slide ordering: lyrics | title | title-blank.
  --out <directory>       Output directory (default ./tmp/propresenter-samples).
  --json <path>           Song data source (default: tests/fixtures/synthetic-songs.json).
                          Accepts either the editor's ?action=songbook_export
                          shape ({songs, songbook}) or the multi-songbook
                          interchange shape ({meta, songbooks, songs}).
  -h, --help              Show this message.
`);
}

/**
 * Normalise either supported JSON shape into {songs, songbooks}.
 *
 *  - Interchange shape (tests/fixtures/synthetic-songs.json,
 *    tools/parse-songs.js output): { meta, songbooks: [...], songs: [...] }
 *  - Editor per-songbook export shape (manage/editor/api.php
 *    ?action=songbook_export&abbr=…): { songs: [...], songbook: {...} }
 *    — ONE songbook object, not an array, and no meta. Wrapping it in a
 *    single-element array keeps the rest of this script (padding map,
 *    bundle-filename lookup) shape-agnostic.
 */
function normaliseSongData(raw) {
    if (Array.isArray(raw.songbooks)) {
        return { songs: raw.songs || [], songbooks: raw.songbooks };
    }
    if (raw.songbook && typeof raw.songbook === 'object') {
        return { songs: raw.songs || [], songbooks: [raw.songbook] };
    }
    return { songs: raw.songs || [], songbooks: [] };
}

async function main() {
    const args = parseArgs(process.argv);
    if (args.help) { help(); return; }

    const songsPath = args.json
        ? path.resolve(process.cwd(), args.json)
        : DEFAULT_SONGS_PATH;

    if (!fs.existsSync(songsPath)) {
        console.error(`Song data source not found at ${songsPath}`);
        process.exit(1);
    }
    if (!fs.existsSync(BUNDLE_PATH)) {
        console.error(`proto-bundle.json missing — run \`npm run build:proto\` first.`);
        process.exit(1);
    }

    const songData = normaliseSongData(JSON.parse(fs.readFileSync(songsPath, 'utf8')));
    const bundle = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

    /* Filter songs per CLI args. */
    let songs = songData.songs || [];
    if (args.songbook) songs = songs.filter(s => s.songbook === args.songbook);
    if (args.id) songs = songs.filter(s => s.id === args.id);
    if (!args.all) songs = songs.slice(0, args.limit);

    if (songs.length === 0) {
        console.error('No songs matched the supplied filters.');
        process.exit(1);
    }

    /* Load the browser exporter into a vm context with the protobuf
       runtime injected. Node lacks DOM globals so triggerDownload()
       no-ops; we use buildPresentation() directly. */
    const sandbox = {
        window: {},
        protobuf,
        crypto: globalThis.crypto,
        TextEncoder: globalThis.TextEncoder,
        TextDecoder: globalThis.TextDecoder,
        Uint8Array: globalThis.Uint8Array,
        Uint32Array: globalThis.Uint32Array,
        Math, Object, Error, Array, String, Number, Boolean, JSON, isNaN, parseInt, Date,
        Promise, Buffer, console,
        Blob: globalThis.Blob, URL: undefined, document: undefined, fetch: undefined
    };
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(SCRIPT_PATH, 'utf8'), sandbox, { filename: SCRIPT_PATH });
    const exporter = sandbox.window.iHymnsProPresenter;
    await exporter.init({ protobuf, bundle });

    const outDir = args.out || path.join(PROJECT_ROOT, 'tmp', 'propresenter-samples');
    fs.mkdirSync(outDir, { recursive: true });

    const buildOptions = {
        linesPerSlide: args.linesPerSlide,
        preSlideOrder: args.pre
    };

    /* Build a per-songbook padding map from the loaded songData so
       each .pro filename's <SongNumber> is zero-padded to the digit
       count of its songbook's total — keeps ProPresenter library
       entries sorted numerically. */
    const paddingByBook = Object.create(null);
    for (const sb of songData.songbooks || []) {
        paddingByBook[sb.id] = exporter.paddingFor(sb);
    }
    function padFor(song) {
        return song && song.songbook ? (paddingByBook[song.songbook] || 0) : 0;
    }

    const written = [];
    for (const song of songs) {
        const bytes = await exporter.buildPresentation(song, buildOptions);
        const filename = exporter.buildFilename(song, {
            extension: '.pro',
            padNumber: padFor(song)
        });
        const target = path.join(outDir, filename);
        fs.writeFileSync(target, Buffer.from(bytes));
        written.push({ song: song.id, file: target, size: bytes.byteLength });
        console.log(`Wrote ${path.relative(PROJECT_ROOT, target)} (${bytes.byteLength} bytes)`);
    }

    if (args.zip || args.probundle) {
        /* Pull the songbook name out of the data so the bundle filename
           can match the in-browser export verbatim. */
        const sbAbbrev = args.songbook || '';
        const sb = (songData.songbooks || []).find(x => x.id === sbAbbrev);
        const sbName = sb ? sb.name : '';

        const files = [];
        for (const song of songs) {
            files.push({
                name: exporter.buildFilename(song, {
                    extension: '.pro',
                    padNumber: padFor(song)
                }),
                bytes: await exporter.buildPresentation(song, buildOptions)
            });
        }

        if (args.zip) {
            const zip = exporter._internal.buildZip(files);
            const autoName = exporter.buildBundleFilename({
                extension: '.zip',
                songbookName: sbName,
                songbookAbbrev: sbAbbrev
            });
            const zipName = typeof args.zip === 'string' ? args.zip : autoName;
            const zipPath = path.isAbsolute(zipName)
                ? zipName
                : path.join(outDir, zipName);
            fs.writeFileSync(zipPath, Buffer.from(zip));
            console.log(`Wrote ${path.relative(PROJECT_ROOT, zipPath)} (${zip.byteLength} bytes, ${files.length} entries)`);
        }

        if (args.probundle) {
            /* Build the same probundle layout the in-browser exporter
               produces (Documents/<file>.pro + manifest.json) so the
               two paths stay byte-comparable. */
            const bundleFiles = files.map(f => ({
                name: 'Documents/' + f.name, bytes: f.bytes
            }));
            const manifest = {
                generator: 'iHymns Song Editor',
                generatedAt: new Date().toISOString(),
                schema: 'rv.data.Presentation (Proto 7.16)',
                songbook: sbAbbrev || null,
                songbookName: sbName || null,
                documentCount: files.length,
                documents: files.map(f => ({
                    path: 'Documents/' + f.name, bytes: f.bytes.length
                }))
            };
            bundleFiles.unshift({
                name: 'manifest.json',
                bytes: new TextEncoder().encode(JSON.stringify(manifest, null, 2))
            });
            const bundleBytes = exporter._internal.buildZip(bundleFiles);
            const autoName = exporter.buildBundleFilename({
                extension: '.probundle',
                songbookName: sbName,
                songbookAbbrev: sbAbbrev
            });
            const bundleName = typeof args.probundle === 'string' ? args.probundle : autoName;
            const bundlePath = path.isAbsolute(bundleName)
                ? bundleName
                : path.join(outDir, bundleName);
            fs.writeFileSync(bundlePath, Buffer.from(bundleBytes));
            console.log(`Wrote ${path.relative(PROJECT_ROOT, bundlePath)} (${bundleBytes.byteLength} bytes, ${files.length} entries + manifest)`);
        }
    }

    /* Verification pass: decode every file via protobufjs to confirm
       the bytes round-trip cleanly against the schema. */
    const root = protobuf.Root.fromJSON(bundle);
    const Presentation = root.lookupType('rv.data.Presentation');
    let ok = 0;
    for (const w of written) {
        const buf = fs.readFileSync(w.file);
        const decoded = Presentation.decode(buf);
        if (decoded.name && decoded.cues.length > 0) ok++;
    }
    console.log(`\nVerified ${ok}/${written.length} file(s) decode back via protobufjs.`);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
