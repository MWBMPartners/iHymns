#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-roundtrip-sample.js — export→import closure fixture generator (#1968 PR-1, plan §8.3c)
 * =======================================================================================================
 *
 * ELI5
 * ----
 * Builds ONE small, hand-authored iHymns song (2 verses + a chorus), runs it through our REAL
 * ProPresenter export code — the exact same JS the Song Editor's "Export as .pro" button calls — and
 * writes the resulting `.pro` bytes to a file. `tests/php/test-pp7-roundtrip.php` then feeds those
 * bytes into our REAL PHP importer and checks the song that comes back out matches the one that went
 * in. Two independently-implemented halves of this app (a browser-JS encoder, a PHP decoder) agreeing
 * on a real `.pro` file is the strongest anti-false-positive proof this repo can produce for its OWN
 * round trip — see this file's doc-block and the plan header for why a self-decode is exactly what the
 * owner's #1 rule for this epic (`.claude/propresenter-interop-1968-plan.md`) forbids trusting alone.
 *
 * DETAILED
 * --------
 * This is a THIRD kind of `.pro` producer in this repo, deliberately distinct from the other two:
 *   - `tests/fixtures/propresenter/*.pro` — genuine third-party files, written by real ProPresenter
 *     (chrismbarr/bussnet). These are the GROUND TRUTH `tests/php/test-pp7-parse.php` validates
 *     against and must never be confused with anything this tool produces.
 *   - `tests/php/test-pp7-parse.php` §(b)'s synthetic byte-builder — a MINIMAL, independent, hand-rolled
 *     protobuf writer (varint + length-delimited only) used to reach parser code paths no real fixture
 *     exercises. It does NOT go through `propresenter-export.js` at all.
 *   - THIS tool — the REAL production exporter (`propresenter-export.js`), run exactly as the browser
 *     runs it (same `buildPresentation()` entry point `tests/test-propresenter-export.js` already
 *     exercises), fed a synthetic song. It is the ONLY one of the three that proves our exporter and
 *     our importer agree with each other on the SAME bytes.
 *
 * The fixture song is intentionally simple and deliberately chosen so every field round-trips
 * PREDICTABLY through `buildCCLIPayload()` (propresenter-export.js) on the way out and
 * `_bulkImport_parsePro7()` (includes/song_importers.php) on the way back in — see
 * `tests/php/test-pp7-roundtrip.php`'s own doc-block for the field-by-field trace of WHY each expected
 * value is what it is (composers deliberately omitted so CCLI's single free-text "author" field doesn't
 * need to be split back apart; the copyright string deliberately carries no 4-digit year so it doesn't
 * collide with `buildCCLIPayload()`'s separate `copyright_year` extraction — both real, documented
 * quirks of the CCLI block's shape, not bugs this fixture needs to route around silently).
 *
 * The `.pro` bytes are written FRESH on every test run (never committed) — the same
 * "regeneration-as-mechanism, not a stale committed copy" posture plan §8.1 specifies for
 * `ihymns-export-sample.pro`, so this fixture can never drift out of sync with the exporter it exists
 * to exercise (rule #35 — a mechanism, not a "remember to regenerate" comment).
 *
 * Usage:
 *   node tools/pp7-gen-roundtrip-sample.js <output-path.pro>
 *
 * Exit status: 0 = bytes written, 1 = any failure (missing arg, encode error).
 *
 * @see appWeb/public_html/manage/editor/propresenter-export.js   buildPresentation() — the exporter under test
 * @see appWeb/public_html/includes/song_importers.php             _bulkImport_parsePro7() — the importer under test
 * @see tests/php/test-pp7-roundtrip.php                           the consumer of this script's output
 * @see tests/test-propresenter-export.js                          the vm-sandbox loading technique this reuses
 * @see .claude/propresenter-interop-1968-plan.md                  §8.3(c) — the closure test this fixture serves
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

/**
 * THE fixture song (single source of truth — `tests/php/test-pp7-roundtrip.php`'s own doc-block
 * traces each field of this object through the exporter and back through the importer to derive its
 * hand-verified "expected" PHP array; if this object ever changes, that trace must be redone).
 *
 * Two writers (no composers — see the file header on why), a title, a year-free copyright string, a
 * CCLI number, and three components (verse 1 / chorus / verse 2) each carrying a couple of lines —
 * enough to exercise ordered multi-component parsing, the "no explicit number" chorus path (mapped
 * type number 0, plan §3.5), and the identity-arrangement collapse-to-null rule (plan §3.3 point 5)
 * without any of the metadata edge cases `tests/php/test-pp7-parse.php`'s REAL fixtures already cover.
 */
const SAMPLE_SONG = {
    id: 'TF-0001',
    number: 1,
    title: 'Test Roundtrip Song',
    songbook: 'TF',
    songbookName: 'Test Fixtures',
    writers: ['Jane Doe', 'John Smith'],
    copyright: '© Test Publisher',
    ccli: '7654321',
    components: [
        { type: 'verse', number: 1, lines: ['Amazing grace how sweet the sound', 'That saved a wretch like me'] },
        { type: 'chorus', lines: ['This is the chorus first line', 'This is the chorus second line'] },
        { type: 'verse', number: 2, lines: ['I once was lost but now am found', 'Was blind but now I see'] }
    ]
};

async function main() {
    const outPath = process.argv[2];
    if (!outPath) {
        console.error('usage: node tools/pp7-gen-roundtrip-sample.js <output-path.pro>');
        process.exit(1);
    }

    const scriptSource = fs.readFileSync(SCRIPT_PATH, 'utf8');
    const bundle = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

    /* The SAME vm-sandbox technique `tests/test-propresenter-export.js` uses to load the exporter
       exactly as the browser does, minus DOM globals the encode path never touches. Passing
       `{ bundle }` to `init()` below takes the REFLECTION fallback branch (protobufjs
       `Root.fromJSON`) rather than the CSP-safe static module — correct here, since this is a
       plain Node script with no CSP to violate, and it is the SAME branch
       `tests/test-propresenter-export.js` itself uses for every one of its encode assertions. */
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
        Blob: globalThis.Blob,
        URL: undefined,
        document: undefined,
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

    await exporter.init({ protobuf, bundle });
    const bytes = await exporter.buildPresentation(SAMPLE_SONG);

    fs.writeFileSync(outPath, Buffer.from(bytes));
    console.log(`wrote ${bytes.length} byte(s) to ${outPath}`);
}

main().catch((err) => {
    console.error('pp7-gen-roundtrip-sample failed:', err && err.stack || err);
    process.exit(1);
});
