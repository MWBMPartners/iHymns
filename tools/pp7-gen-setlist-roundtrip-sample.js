#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-setlist-roundtrip-sample.js — set-list .proplaylist export→import
 * closure fixture generator (#1968 P3-EXPORT, plan §5.2 / §8.3)
 * =================================================================================
 *
 * ELI5
 * ----
 * Sibling to `tools/pp7-gen-roundtrip-sample.js` (which does this for a single
 * `.pro`), but for a whole SET LIST exported as a `.proplaylist`: builds a small,
 * hand-authored iHymns set list (two songs + one non-song running-order slot),
 * runs it through our REAL exporter (`propresenter-export.js`'s
 * `exportSetlistAsProplaylist()` — the exact same JS the set-list UI's Export
 * control calls), and writes the resulting `.proplaylist` ZIP bytes to a file.
 * `tests/php/test-pp7-setlist-roundtrip.php` then feeds those bytes into our REAL
 * PHP `.proplaylist` decoder (`pp7ReadPlaylistBundle()` / `pp7DecodePresentation()`
 * — landed independently in #1973, months before this exporter existed) and checks
 * the playlist name, item order/types, and every presentation item's
 * document_path/arrangement round-trip correctly. Two independently-authored
 * halves of this app — a browser-JS protobuf ENCODER and a hand-rolled PHP wire
 * DECODER that has never seen this exporter's source — agreeing on the same bytes
 * is the anti-false-positive proof this epic's own header demands (a same-process
 * self-decode is explicitly forbidden as evidence — see
 * .claude/propresenter-interop-1968-plan.md's header and §8).
 *
 * WHY `triggerDownload()` NEEDS A CAPTURING STAND-IN
 * ---------------------------------------------------
 * `exportSetlistAsProplaylist()` (like every other download-triggering export in
 * this module) hands its finished ZIP bytes to `triggerDownload()`, which needs a
 * REAL `document`/`URL`/`Blob` to do anything — in a bare Node `vm` sandbox those
 * are `undefined` and it silently no-ops (see that function's own guard), which is
 * fine for the pure/filename-only assertions elsewhere but would leave this script
 * with no bytes to write out. This reuses the exact "capturing Blob" stand-in
 * `tests/test-propresenter-export.js`'s own `createBundleCapturingExporter()`
 * already established for the identical problem on `exportAllAsBundle()` — a tiny
 * `Blob` class that stashes its first constructor argument instead of discarding
 * it, plus inert `document.createElement`/`URL.createObjectURL` stubs.
 *
 * THE FIXTURE
 * -----------
 * A set list named "Roundtrip Service" with a `plan.slots` running order:
 *   1. a non-song "Welcome" slot (no `songId`) — exercises the `header`
 *      `PlaylistItem` branch (resolveSetlistPlaylistEntries()'s "no songId -> a
 *      header" rule);
 *   2. a song slot pointing at `RT-0001` ("Test Roundtrip Song One", one verse);
 *   3. a song slot pointing at `RT-0002` ("Test Roundtrip Song Two", one verse) —
 *      TWO songs (not one) so the test can assert item ORDER, not just presence,
 *      and so `ensureUniqueNames()`'s dedupe path is exercised on realistic
 *      distinctly-named files (it is NOT exercised here — the two titles differ —
 *      but keeping two songs is what makes an ordering assertion meaningful at
 *      all; a single-song playlist can't prove order).
 * Each song's own component/CCLI shape deliberately mirrors
 * `pp7-gen-roundtrip-sample.js`'s SAME "round-trips predictably" design (a title,
 * one writer, a year-free copyright, one verse) — this fixture is not re-testing
 * the `.pro` closure itself (that is `test-pp7-roundtrip.php`'s job); it only needs
 * each song's `.pro` to decode to SOMETHING recognisable so the PLAYLIST-level
 * assertions (item order/types, document_path basename match, arrangement
 * UUID/name agreement) have real per-song data to check against.
 *
 * Usage:
 *   node tools/pp7-gen-setlist-roundtrip-sample.js <output-path.proplaylist>
 *
 * Exit status: 0 = bytes written, 1 = any failure (missing arg, encode error).
 *
 * @see appWeb/public_html/manage/editor/propresenter-export.js   exportSetlistAsProplaylist() — the exporter under test
 * @see appWeb/public_html/includes/propresenter7_playlist.php    pp7ReadPlaylistBundle()/pp7DecodePlaylistDocument() — the decoder under test
 * @see tests/php/test-pp7-setlist-roundtrip.php                  the consumer of this script's output
 * @see tools/pp7-gen-roundtrip-sample.js                         the single-.pro sibling this mirrors
 * @see tests/test-propresenter-export.js                         createBundleCapturingExporter() — the capturing-Blob technique this reuses
 * @see .claude/propresenter-interop-1968-plan.md                 §5.2 / §8.3 — this fixture's brief
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..');

const SCRIPT_PATH = path.join(
    REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'propresenter-export.js'
);
const BUNDLE_PATH = path.join(
    REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

/** THE fixture set list — a running-order plan with one header slot + two song
 *  slots, in that order (see file header for why). `SAMPLE_SETLIST.songs` is the
 *  lightweight ref array setlist.js itself stores; `SAMPLE_SONGS` below is the
 *  parallel FULL song data exportSetlistAsProplaylist()'s `songs` argument
 *  expects (the shape print.js's fetchSong() would have returned). */
const SAMPLE_SETLIST = {
    id: 'RTSL-0001',
    name: 'Roundtrip Service',
    songs: [
        { id: 'RT-0001', title: 'Test Roundtrip Song One', songbook: 'RT', number: 1 },
        { id: 'RT-0002', title: 'Test Roundtrip Song Two', songbook: 'RT', number: 2 }
    ],
    plan: {
        slots: [
            { id: 's1', label: 'Welcome', type: 'welcome' },
            { id: 's2', label: 'Song', type: 'song', songId: 'RT-0001' },
            { id: 's3', label: 'Song', type: 'song', songId: 'RT-0002' }
        ]
    }
};

const SAMPLE_SONGS = [
    {
        id: 'RT-0001', number: 1, title: 'Test Roundtrip Song One',
        songbook: 'RT', songbookName: 'Roundtrip Fixtures',
        writers: ['Jane Doe'], copyright: '© Test Publisher One', ccli: '1112223',
        components: [
            { type: 'verse', number: 1, lines: ['First song first line', 'First song second line'] }
        ]
    },
    {
        id: 'RT-0002', number: 2, title: 'Test Roundtrip Song Two',
        songbook: 'RT', songbookName: 'Roundtrip Fixtures',
        writers: ['John Smith'], copyright: '© Test Publisher Two', ccli: '4445556',
        components: [
            { type: 'verse', number: 1, lines: ['Second song first line', 'Second song second line'] }
        ]
    }
];

async function main() {
    const outPath = process.argv[2];
    if (!outPath) {
        console.error('usage: node tools/pp7-gen-setlist-roundtrip-sample.js <output-path.proplaylist>');
        process.exit(1);
    }

    const scriptSource = fs.readFileSync(SCRIPT_PATH, 'utf8');
    const bundle = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

    /* Capturing-Blob stand-in — see file header. `document`/`URL` need just
       enough surface for triggerDownload()'s call sequence
       (document.createElement -> anchor.click -> document.body.appendChild/
       removeChild -> URL.revokeObjectURL) to run without throwing; only the
       Blob constructor's captured bytes matter. */
    let capturedBytes = null;
    class CapturingBlob {
        constructor(parts) { capturedBytes = parts && parts[0]; }
    }
    const anchor = { href: '', download: '', click() {} };

    const sandbox = {
        window: {},
        protobuf,
        crypto: globalThis.crypto,
        TextEncoder: globalThis.TextEncoder,
        TextDecoder: globalThis.TextDecoder,
        Uint8Array: globalThis.Uint8Array,
        Uint32Array: globalThis.Uint32Array,
        Math,
        Object,
        Error,
        Array,
        String,
        Number,
        Boolean,
        JSON,
        isNaN,
        parseInt,
        Date,
        Promise,
        Buffer,
        Blob: CapturingBlob,
        document: {
            createElement: () => anchor,
            body: { appendChild() {}, removeChild() {} }
        },
        URL: { createObjectURL: () => 'blob:test', revokeObjectURL: () => {} },
        fetch: undefined,
        setTimeout
    };
    vm.createContext(sandbox);
    vm.runInContext(scriptSource, sandbox, { filename: SCRIPT_PATH });
    const exporter = sandbox.window.iHymnsProPresenter;
    if (!exporter) {
        console.error('propresenter-export.js did not publish window.iHymnsProPresenter');
        process.exit(1);
    }

    /* Reflection branch (protobufjs Root.fromJSON), same as
       pp7-gen-roundtrip-sample.js — a plain Node script has no CSP to
       violate, and this is the SAME branch tests/test-propresenter-export.js
       itself uses for every one of its assertions. */
    await exporter.init({ protobuf, bundle });
    const result = await exporter.exportSetlistAsProplaylist(SAMPLE_SETLIST, SAMPLE_SONGS, {});

    if (!capturedBytes || !capturedBytes.length) {
        console.error('exportSetlistAsProplaylist() produced no bytes (capturing Blob stayed empty)');
        process.exit(1);
    }

    fs.writeFileSync(outPath, Buffer.from(capturedBytes));
    console.log(`wrote ${capturedBytes.length} byte(s) to ${outPath} (${JSON.stringify(result)})`);
}

main().catch((err) => {
    console.error('pp7-gen-setlist-roundtrip-sample failed:', err && err.stack || err);
    process.exit(1);
});
