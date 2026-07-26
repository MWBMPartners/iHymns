/**
 * iHymns — ProPresenter Export Unit Tests
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Validates appWeb/private_html/editor/propresenter-export.js end-to-end:
 *  - The script loads in a browser-like vm context.
 *  - The schema-validated `Presentation` payload encodes successfully
 *    via protobufjs against the vendored Proto 7.16 schema.
 *  - The encoded bytes round-trip back to the same shape via
 *    `Presentation.decode()` — proving the field numbers, wire types
 *    and nested message structure are correct.
 *  - RTF, filename, ZIP and component-label helpers behave as spec'd.
 *
 * USAGE:
 *   node tests/test-propresenter-export.js
 *   npm test
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

/* The editor moved to appWeb/public_html/manage/editor/ in the DB-direct
   rewrite (WS-D #1016); the ProPresenter exporter + proto bundle were
   salvaged there (#887). */
const SCRIPT_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'propresenter-export.js'
);
const BUNDLE_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

const scriptSource = fs.readFileSync(SCRIPT_PATH, 'utf8');
const bundle = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

/* Construct a vm context that mimics the browser globals the exporter
   relies on. The protobufjs runtime is injected as `protobuf` so the
   script's `getProtobuf()` helper can find it. */
const sandbox = {
    window: {},
    protobuf: protobuf,
    crypto: globalThis.crypto,
    TextEncoder: globalThis.TextEncoder,
    TextDecoder: globalThis.TextDecoder,
    Uint8Array: globalThis.Uint8Array,
    Uint32Array: globalThis.Uint32Array,
    Math: Math,
    Object: Object,
    Error: Error,
    Array: Array,
    String: String,
    Number: Number,
    Boolean: Boolean,
    JSON: JSON,
    isNaN: isNaN,
    parseInt: parseInt,
    Date: Date,
    Promise: Promise,
    Buffer: Buffer,
    Blob: globalThis.Blob,
    URL: undefined,
    document: undefined,
    fetch: undefined
};
vm.createContext(sandbox);
vm.runInContext(scriptSource, sandbox, { filename: SCRIPT_PATH });
const exporter = sandbox.window.iHymnsProPresenter;
if (!exporter) {
    throw new Error('Module did not publish window.iHymnsProPresenter');
}

let pass = 0;
let fail = 0;
async function test(name, fn) {
    try {
        await fn();
        console.log(`  ✓ ${name}`);
        pass++;
    } catch (err) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${err.message}`);
        if (process.env.DEBUG) console.error(err.stack);
        fail++;
    }
}

const SAMPLE_SONG = {
    id: 'CP-0001',
    number: 1,
    title: 'A baby was born in Bethlehem',
    songbook: 'CP',
    songbookName: 'Carol Praise',
    writers: ['Ivor Golby'],
    composers: ['Noël Tredinnick'],
    artists: ['All Souls Orchestra'],
    copyright: '© 2024 A & C Black Limited',
    ccli: '1234567',
    components: [
        { type: 'verse', number: 1, lines: ['Line one', 'Line two'] },
        { type: 'chorus', lines: ['Gloria, gloria'] },
        { type: 'verse', number: 2, lines: ['Line three'] }
    ]
};

/* Reusable protobufjs root for round-trip decoding tests. */
const decodeRoot = protobuf.Root.fromJSON(bundle);
const Presentation = decodeRoot.lookupType('rv.data.Presentation');

/* ELI5: this builds a brand-new pretend-browser tab for the bundle-URL
   tests below, instead of reusing the one already opened at the top of
   this file.
   Detail: `protoRoot` / `initPromise` (SECTION 1 of the module) are plain
   closure variables captured once by the IIFE the FIRST time it runs in a
   `vm` context — `init()` early-returns the cached `initPromise` on every
   subsequent call. The suite's shared `sandbox`/`exporter` above already
   called `init({ bundle })` once, so re-running assertions against it
   would silently observe the FIRST run's cached state (and never touch
   `fetch` again) rather than the runtime fetch path this section exists
   to exercise (#1566). A fresh `vm.createContext()` + a fresh
   `vm.runInContext()` re-executes the IIFE and mints new closure
   variables, so each test below gets its own untouched module instance. */
function createFreshExporter(overrides) {
    const freshSandbox = {
        window: {},
        protobuf: protobuf,
        crypto: globalThis.crypto,
        TextEncoder: globalThis.TextEncoder,
        TextDecoder: globalThis.TextDecoder,
        Uint8Array: globalThis.Uint8Array,
        Uint32Array: globalThis.Uint32Array,
        Math: Math,
        Object: Object,
        Error: Error,
        Array: Array,
        String: String,
        Number: Number,
        Boolean: Boolean,
        JSON: JSON,
        isNaN: isNaN,
        parseInt: parseInt,
        Date: Date,
        Promise: Promise,
        Buffer: Buffer,
        Blob: globalThis.Blob,
        URL: undefined,
        document: undefined,
        fetch: undefined,
        ...overrides
    };
    vm.createContext(freshSandbox);
    vm.runInContext(scriptSource, freshSandbox, { filename: SCRIPT_PATH });
    return freshSandbox.window.iHymnsProPresenter;
}

(async function run() {
    /* ---------------------------------------------------------------- */
    console.log('Initialisation:');
    await test('init() resolves with the supplied bundle', async () => {
        await exporter.init({ protobuf: protobuf, bundle: bundle });
        assert.ok(exporter._internal.getRoot() != null);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nUUID:');
    await test('uuidV4 produces RFC 4122 v4 format', () => {
        const u = exporter._internal.uuidV4();
        assert.match(u, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nRTF builder:');
    await test('wraps with rtf1 prefix and closing brace', () => {
        const rtf = exporter.buildRTF(['Hello']);
        assert.match(rtf, /^\{\\rtf1\\ansi\\uc1 /);
        assert.ok(rtf.endsWith('}'));
    });
    await test('separates lines with \\par', () => {
        const rtf = exporter.buildRTF(['Line 1', 'Line 2']);
        assert.match(rtf, /Line 1\\par\nLine 2/);
    });
    await test('escapes RTF metacharacters (\\, {, })', () => {
        const rtf = exporter.buildRTF(['a\\b{c}d']);
        assert.ok(rtf.includes('a\\\\b\\{c\\}d'));
    });
    await test('escapes non-ASCII via \\uN?', () => {
        const rtf = exporter.buildRTF(['Noël']);
        assert.ok(rtf.includes('\\u235?'));
    });
    await test('handles empty/missing input gracefully', () => {
        assert.match(exporter.buildRTF([]), /^\{\\rtf1\\ansi\\uc1 \}$/);
        assert.match(exporter.buildRTF(null), /^\{\\rtf1\\ansi\\uc1 \}$/);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nFilename helper:');
    await test('includes number, songbook abbreviation and title', () => {
        /* Per #887 spec revision: "<Number> (<SongbookAbbrev>) - <Title>" */
        assert.equal(
            exporter.buildFilename({ songbook: 'CP', number: 1, title: 'A baby was born' }),
            '1 (CP) - A baby was born.pro'
        );
    });
    await test('appends (Tune Title) when the song has one', () => {
        /* Inert in real data today (no tuneTitle field exists), but
           the encoder honours it via getTuneTitle() so the data-pipeline
           change can land independently. */
        assert.equal(
            exporter.buildFilename({
                songbook: 'CP', number: 1, title: 'Amazing Grace', tuneTitle: 'New Britain'
            }),
            '1 (CP) - Amazing Grace (New Britain).pro'
        );
    });
    await test('strips illegal filesystem characters', () => {
        const f = exporter.buildFilename({
            songbook: 'MP', number: 42, title: 'Some/Title*With?Bad:Chars'
        });
        assert.ok(!/[\\/:*?"<>|]/.test(f));
        assert.ok(f.endsWith('.pro'));
    });
    await test('untitled songs still get a sensible name', () => {
        assert.equal(exporter.buildFilename({}), 'Untitled.pro');
    });
    await test('zero-pads <SongNumber> to the songbook width when supplied', () => {
        /* CP has 243 songs → 3-digit padding. */
        assert.equal(
            exporter.buildFilename(
                { songbook: 'CP', number: 1, title: 'A baby was born' },
                { extension: '.pro', padNumber: 3 }
            ),
            '001 (CP) - A baby was born.pro'
        );
        /* MP has 3,517 songs → 4-digit padding. */
        assert.equal(
            exporter.buildFilename(
                { songbook: 'MP', number: 42, title: 'Foo' },
                { extension: '.pro', padNumber: 4 }
            ),
            '0042 (MP) - Foo.pro'
        );
    });
    await test('does not truncate numbers wider than the pad width', () => {
        /* Defensive: pad=3 but number=1234 → still emits "1234". */
        assert.equal(
            exporter.buildFilename(
                { songbook: 'MP', number: 1234, title: 'x' },
                { extension: '.pro', padNumber: 3 }
            ),
            '1234 (MP) - x.pro'
        );
    });
    await test('paddingFor() handles songbook records, numbers and arrays', () => {
        assert.equal(exporter.paddingFor({ songCount: 243 }), 3);
        assert.equal(exporter.paddingFor({ songCount: 3517 }), 4);
        assert.equal(exporter.paddingFor(243), 3);
        assert.equal(exporter.paddingFor([
            { number: 7 }, { number: 250 }, { number: 99 }
        ]), 3);
        assert.equal(exporter.paddingFor(null), 0);
        assert.equal(exporter.paddingFor(0), 0);
    });
    await test('bundle filename: songbook name + abbrev + [Bundle]', () => {
        assert.equal(
            exporter.buildBundleFilename({
                extension: '.zip',
                songbookName: 'Carol Praise',
                songbookAbbrev: 'CP'
            }),
            'Carol Praise (CP) [Bundle].zip'
        );
    });
    await test('bundle filename: .probundle extension when requested', () => {
        assert.equal(
            exporter.buildBundleFilename({
                extension: '.probundle',
                songbookName: 'Carol Praise',
                songbookAbbrev: 'CP'
            }),
            'Carol Praise (CP) [Bundle].probundle'
        );
    });

    /* ---------------------------------------------------------------- */
    console.log('\nComponent label mapping:');
    await test('verse 1 → V1', () => {
        assert.equal(exporter._internal.componentLabel({ type: 'verse', number: 1 }).short, 'V1');
    });
    await test('chorus (no number) → C', () => {
        assert.equal(exporter._internal.componentLabel({ type: 'chorus' }).short, 'C');
    });
    await test('bridge → B', () => {
        assert.equal(exporter._internal.componentLabel({ type: 'bridge' }).short, 'B');
    });

    /* ---------------------------------------------------------------- */
    console.log('\nPayload builder (schema verification):');
    await test('payload passes Presentation.verify()', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG);
        const problem = Presentation.verify(payload);
        assert.equal(problem, null, 'verify error: ' + problem);
    });
    await test('payload contains one cue + one cue_group per component', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG);
        assert.equal(payload.cues.length, 3);
        assert.equal(payload.cue_groups.length, 3);
    });
    await test('payload labels cue_groups with full names (Verse 1 / Chorus / Verse 2)', () => {
        /* Per #887 spec revision: cue_groups[].group.name is the full
           human-readable component name, not the short letter form. */
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG);
        const names = Array.from(payload.cue_groups.map(g => g.group.name));
        assert.deepEqual(names, ['Verse 1', 'Chorus', 'Verse 2']);
    });
    await test('payload includes a Default arrangement covering every cue_group', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG);
        assert.equal(payload.arrangements.length, 1);
        assert.equal(payload.arrangements[0].name, 'Default');
        assert.equal(
            payload.arrangements[0].group_identifiers.length,
            payload.cue_groups.length
        );
        assert.equal(
            payload.selected_arrangement.string,
            payload.arrangements[0].uuid.string
        );
    });
    await test('linesPerSlide chunks long components into multiple cues', () => {
        const longSong = {
            title: 'Long verse',
            components: [{
                type: 'verse', number: 1,
                lines: ['l1', 'l2', 'l3', 'l4', 'l5']
            }]
        };
        const payload = exporter._internal.buildPresentationPayload(longSong, {
            linesPerSlide: 2
        });
        /* 5 lines, 2 per slide → 3 chunks → 3 cues under one cue_group. */
        assert.equal(payload.cues.length, 3);
        assert.equal(payload.cue_groups.length, 1);
        assert.equal(payload.cue_groups[0].cue_identifiers.length, 3);
    });
    await test('linesPerSlide=0 keeps all lines on one slide (default)', () => {
        const payload = exporter._internal.buildPresentationPayload({
            title: 'x',
            components: [{ type: 'verse', number: 1, lines: ['a', 'b', 'c', 'd'] }]
        }, { linesPerSlide: 0 });
        assert.equal(payload.cues.length, 1);
    });
    await test('preSlideOrder=title prepends a Title cue_group', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {
            preSlideOrder: 'title'
        });
        assert.equal(payload.cue_groups[0].group.name, 'Title');
        assert.equal(payload.cue_groups.length, 4); /* Title + 3 components */
    });
    await test('preSlideOrder=title-blank prepends Title and Blank', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {
            preSlideOrder: 'title-blank'
        });
        const names = Array.from(payload.cue_groups.map(g => g.group.name));
        assert.deepEqual(names.slice(0, 2), ['Title', 'Blank']);
    });
    await test('preSlideOrder=lyrics (default) emits no pre-slides', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {
            preSlideOrder: 'lyrics'
        });
        const firstName = payload.cue_groups[0].group.name;
        assert.equal(firstName, 'Verse 1');
    });
    await test('CCLI artist_credits picks up artists[] (#587)', () => {
        const ccli = exporter._internal.buildCCLIPayload(SAMPLE_SONG);
        assert.equal(ccli.artist_credits, 'All Souls Orchestra');
    });
    await test('CCLI extracts copyright_year from copyright text', () => {
        const ccli = exporter._internal.buildCCLIPayload(SAMPLE_SONG);
        assert.equal(ccli.copyright_year, 2024);
    });
    await test('CCLI parses song_number from ccli string', () => {
        const ccli = exporter._internal.buildCCLIPayload(SAMPLE_SONG);
        assert.equal(ccli.song_number, 1234567);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nProtobuf encode / decode round-trip:');
    let encoded;

    await test('buildPresentation returns a non-empty Uint8Array', async () => {
        encoded = await exporter.buildPresentation(SAMPLE_SONG);
        assert.ok(encoded instanceof Uint8Array || encoded.constructor.name === 'Uint8Array');
        assert.ok(encoded.byteLength > 0);
    });

    await test('encoded bytes decode back into a valid Presentation', () => {
        const decoded = Presentation.decode(encoded);
        assert.equal(decoded.name, 'A baby was born in Bethlehem');
        assert.equal(decoded.category, 'Song');
        assert.equal(decoded.cue_groups.length, 3);
        assert.equal(decoded.cues.length, 3);
    });

    await test('decoded UUID is a valid v4 string', () => {
        const decoded = Presentation.decode(encoded);
        assert.match(
            decoded.uuid.string,
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
        );
    });

    await test('decoded cue_group names use full human-readable form', () => {
        /* Spec revision: full name in cue_groups[].group.name. */
        const decoded = Presentation.decode(encoded);
        assert.deepEqual(
            decoded.cue_groups.map(g => g.group.name),
            ['Verse 1', 'Chorus', 'Verse 2']
        );
    });
    await test('decoded action cue names retain the short keyboard-friendly form', () => {
        /* The cue.name (and slide-action name) keeps the short form
           so ProPresenter's keyboard cue palette stays compact. */
        const decoded = Presentation.decode(encoded);
        assert.deepEqual(
            decoded.cues.map(c => c.name),
            ['V1', 'C', 'V2']
        );
    });
    await test('decoded presentation has a single Default arrangement', () => {
        const decoded = Presentation.decode(encoded);
        assert.equal(decoded.arrangements.length, 1);
        assert.equal(decoded.arrangements[0].name, 'Default');
        assert.equal(
            decoded.arrangements[0].group_identifiers.length,
            decoded.cue_groups.length
        );
    });

    await test('decoded cue references link to a real cue uuid', () => {
        const decoded = Presentation.decode(encoded);
        const cueUuids = new Set(decoded.cues.map(c => c.uuid.string));
        for (const cg of decoded.cue_groups) {
            for (const ref of cg.cue_identifiers) {
                assert.ok(cueUuids.has(ref.string), 'orphaned cue reference: ' + ref.string);
            }
        }
    });

    await test('decoded action carries an RTF-bearing slide element', () => {
        const decoded = Presentation.decode(encoded);
        const action = decoded.cues[0].actions[0];
        assert.equal(action.type, 11); /* ACTION_TYPE_PRESENTATION_SLIDE */
        const element = action.slide.presentation.base_slide.elements[0].element;
        assert.equal(element.name, 'Lyrics');
        const rtf = Buffer.from(element.text.rtf_data).toString('utf8');
        assert.match(rtf, /^\{\\rtf1\\ansi\\uc1 /);
        assert.ok(rtf.includes('Line one'));
        assert.ok(rtf.includes('Line two'));
    });

    await test('decoded CCLI block contains author, title and #587 artist', () => {
        const decoded = Presentation.decode(encoded);
        assert.equal(decoded.ccli.author, 'Ivor Golby / Noël Tredinnick');
        assert.equal(decoded.ccli.song_title, 'A baby was born in Bethlehem');
        assert.equal(decoded.ccli.artist_credits, 'All Souls Orchestra');
        assert.equal(decoded.ccli.copyright_year, 2024);
        assert.equal(decoded.ccli.song_number, 1234567);
        assert.equal(decoded.ccli.display, true);
    });

    await test('empty-component songs still produce a valid Presentation', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf: protobuf, bundle: bundle });
        const bytes = await exporter.buildPresentation({ title: 'Bare', components: [] });
        const decoded = Presentation.decode(bytes);
        assert.equal(decoded.name, 'Bare');
        assert.ok(decoded.cues.length >= 1, 'expected at least one default slide');
    });

    await test('throws on missing song', async () => {
        await assert.rejects(() => exporter.buildPresentation(null));
    });

    /* ---------------------------------------------------------------- */
    console.log('\nZIP writer:');
    await test('produces a valid PK ZIP signature', () => {
        const z = exporter._internal.buildZip([
            { name: 'a.pro', bytes: new Uint8Array([1, 2, 3]) }
        ]);
        assert.equal(z[0], 0x50);
        assert.equal(z[1], 0x4b);
        assert.equal(z[2], 0x03);
        assert.equal(z[3], 0x04);
    });
    await test('ends with EOCD signature', () => {
        const z = exporter._internal.buildZip([{ name: 'x.pro', bytes: new Uint8Array([9]) }]);
        let found = false;
        for (let i = z.length - 22; i >= 0; i--) {
            if (z[i] === 0x50 && z[i+1] === 0x4b && z[i+2] === 0x05 && z[i+3] === 0x06) {
                found = true; break;
            }
        }
        assert.ok(found, 'EOCD signature missing');
    });
    await test('central directory contains every filename', () => {
        const z = exporter._internal.buildZip([
            { name: 'song-1.pro', bytes: new Uint8Array([1]) },
            { name: 'song-2.pro', bytes: new Uint8Array([2]) }
        ]);
        const haystack = Buffer.from(z).toString('utf8');
        assert.ok(haystack.includes('song-1.pro'));
        assert.ok(haystack.includes('song-2.pro'));
    });

    /* ---------------------------------------------------------------- */
    console.log('\nNormalised options:');
    await test('normaliseOptions: defaults are linesPerSlide=0, preSlideOrder=lyrics', () => {
        const o = exporter._internal.normaliseOptions(undefined);
        assert.equal(o.linesPerSlide, 0);
        assert.equal(o.preSlideOrder, 'lyrics');
    });
    await test('normaliseOptions: invalid linesPerSlide clamps to 0', () => {
        assert.equal(exporter._internal.normaliseOptions({ linesPerSlide: -3 }).linesPerSlide, 0);
        assert.equal(exporter._internal.normaliseOptions({ linesPerSlide: 'oops' }).linesPerSlide, 0);
    });
    await test('normaliseOptions: invalid preSlideOrder falls back to lyrics', () => {
        assert.equal(
            exporter._internal.normaliseOptions({ preSlideOrder: 'invented' }).preSlideOrder,
            'lyrics'
        );
    });
    await test('chunkLines: respects N and drops empty trailing chunks', () => {
        const chunks = exporter._internal.chunkLines(['a','b','c','d','e'], 2);
        assert.equal(chunks.length, 3);
        assert.deepEqual(Array.from(chunks[2]), ['e']);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nBundle (.probundle) export:');
    await test('exportAllAsBundle returns a .probundle filename', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const result = await exporter.exportAllAsBundle(
            [SAMPLE_SONG],
            { songbookName: 'Carol Praise', songbookAbbrev: 'CP' }
        );
        assert.equal(result.filename, 'Carol Praise (CP) [Bundle].probundle');
        assert.equal(result.count, 1);
    });
    await test('bulk export pads <SongNumber> to the songbook width via songbookSize hint', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const subset = [
            { songbook: 'CP', number: 1, title: 'Tiny',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'CP', number: 42, title: 'Mid',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'CP', number: 200, title: 'Big',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] }
        ];
        /* CP has 243 songs → 3-digit pad. */
        const files = await exporter._internal.buildBulkFiles(subset, {
            songbookAbbrev: 'CP',
            songbookSize: 243
        });
        const names = Array.from(files.map(f => f.name));
        assert.deepEqual(names, [
            '001 (CP) - Tiny.pro',
            '042 (CP) - Mid.pro',
            '200 (CP) - Big.pro'
        ]);
    });
    await test('bulk export computes padding per-songbook when crossing books', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const subset = [
            { songbook: 'CP', number: 7, title: 'CP small',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'MP', number: 3,   title: 'MP small',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'MP', number: 3500, title: 'MP big',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] }
        ];
        const files = await exporter._internal.buildBulkFiles(subset, {});
        const names = Array.from(files.map(f => f.name));
        /* CP subset max = 7 → 1 digit pad; MP subset max = 3500 → 4
           digit pad. Per-songbook padding keeps each library sorted. */
        assert.deepEqual(names, [
            '7 (CP) - CP small.pro',
            '0003 (MP) - MP small.pro',
            '3500 (MP) - MP big.pro'
        ]);
    });

    /* ---------------------------------------------------------------- */
    console.log('\nTune title (forward-compatible):');
    await test('getTuneTitle returns the empty string when absent', () => {
        assert.equal(exporter._internal.getTuneTitle(SAMPLE_SONG), '');
    });
    await test('getTuneTitle picks up tuneTitle when present', () => {
        assert.equal(
            exporter._internal.getTuneTitle({ tuneTitle: 'New Britain' }),
            'New Britain'
        );
    });

    /* ---------------------------------------------------------------- */
    console.log('\nBundle URL (#1566):');
    /* This whole section guards the fix that shipped in commit 4abfc366:
       the descriptor URL the module fetch()es at runtime, exercised via
       the REAL fetch code path (SECTION 2 of propresenter-export.js) —
       not the test-injected `{ bundle }` shortcut the rest of this file
       (deliberately) uses. That shortcut is exactly why the bug survived
       code review the first time: every existing `init()` call bypassed
       `fetch()` entirely, so a broken URL never had a chance to fail. */

    await test('DEFAULT_BUNDLE_URL is pinned to the root-absolute path', () => {
        /* ELI5: check the actual line of code, word for word, so a future
           edit that quietly puts the leading slash back to a relative
           path ('protos/proto-bundle.json') fails immediately here —
           before it ever reaches a browser.
           Detail: a relative URL resolves against the DOCUMENT's location
           (whatever /song/<id> or /songbook/<abbr> page imported this
           script from), not the <script src>'s own location — see the
           SECTION 1 doc-comment in propresenter-export.js and #1566. */
        assert.match(
            scriptSource,
            /DEFAULT_BUNDLE_URL\s*=\s*'\/manage\/editor\/protos\/proto-bundle\.json'/,
            'DEFAULT_BUNDLE_URL must stay the root-absolute /manage/editor/... path'
        );
    });

    await test('the pinned URL maps to a real, committed file', () => {
        /* Belt-and-braces: the string above is only useful if that exact
           absolute path is actually where the bundle lives on disk (i.e.
           where Apache/the docroot will serve it from), so a rename of
           protos/proto-bundle.json without updating the constant is also
           caught here rather than surfacing as a 404 in production. */
        assert.ok(
            fs.existsSync(BUNDLE_PATH),
            'expected ' + BUNDLE_PATH + ' to exist alongside the pinned DEFAULT_BUNDLE_URL'
        );
    });

    await test('init() with no bundle/bundleUrl fetches DEFAULT_BUNDLE_URL (runtime path)', async () => {
        /* THE gap: build a fresh sandbox with a real `fetch` stand-in
           (unlike the shared `sandbox` above, which sets `fetch: undefined`
           and is never exercised) and call init() the way a real page
           does — no `{ bundle }` override — so SECTION 2's `if
           (typeof fetch !== 'function') { throw … }` / `await fetch(url)`
           branch actually runs. */
        let seenUrl = null;
        const freshExporter = createFreshExporter({
            fetch: async (url) => {
                seenUrl = url;
                return { ok: true, json: async () => bundle };
            }
        });
        const root = await freshExporter.init({ protobuf: protobuf });
        assert.equal(seenUrl, '/manage/editor/protos/proto-bundle.json');
        /* init() resolving at all means protoRoot.lookupType() succeeded
           against the REAL on-disk bundle returned by the fetch stub. */
        assert.ok(root != null);
        assert.ok(freshExporter._internal.getRoot().lookupType('rv.data.Presentation'));
    });

    await test('an explicit { bundleUrl } overrides DEFAULT_BUNDLE_URL', async () => {
        let seenUrl = null;
        const freshExporter = createFreshExporter({
            fetch: async (url) => {
                seenUrl = url;
                return { ok: true, json: async () => bundle };
            }
        });
        await freshExporter.init({ protobuf: protobuf, bundleUrl: '/custom/bundle.json' });
        assert.equal(seenUrl, '/custom/bundle.json');
    });

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
