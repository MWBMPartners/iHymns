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
    fetch: undefined,
    /* #1571 — buildBulkFiles()'s cooperative macrotask yield calls
       setTimeout(fn, 0) every 25 songs; a bulk test large enough to cross
       that boundary needs the REAL Node timer (not a no-op stub), so the
       yield actually resolves and the encode completes. */
    setTimeout: setTimeout
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

/* =====================================================================
 * Structural RTF validity — the #1918 follow-up guard (owner-reported
 * 2026-08-25: every exported slide blank, Reflow rows empty).
 *
 * ELI5: instead of checking the RTF string matches one exact spelling,
 * these helpers check it is a REAL RTF document any strict reader can
 * open — because the exact-spelling check is how the broken format
 * survived here for months.
 *
 * Detail (rule #34, .claude/CLAUDE.md): this suite used to assert the
 * exporter's RTF byte-for-byte (`^\{\\rtf1\\ansi\\uc1 `) — a snapshot
 * of whatever the code emitted. That output had NO font table and NO
 * selected font, which violates the RTF spec's formal header grammar
 * (`<header> ::= \rtf <charset> \deff? <fonttbl> <filetbl>? <colortbl>?
 * …` — every header component EXCEPT <fonttbl> carries the optional `?`
 * marker), and ProPresenter's RTF reader (Apple's Cocoa text system)
 * extracts ZERO text from it: right slide count, right group labels,
 * every slide blank. The snapshot assertions stayed green throughout —
 * and the module even cited them as a reason NOT to fix the RTF. A
 * guard must assert the BEHAVIOURAL contract (parseable document, text
 * present, escaping correct), never a byte-snapshot of the current
 * implementation. Mutation-proven: removing the font table, the \f0
 * selector, the \fsN size, or the closing brace from buildRTF() each
 * turn assertValidRtfDoc() red (see the PR's mutation transcript).
 * =================================================================== */

/* Return the index just past the `}` closing the group that opens at
   `start` (which must point at a `{`). Backslash-escaped braces
   (`\{`/`\}`) don't count. Throws on unbalanced input. */
function findGroupEnd(rtf, start) {
    let depth = 0;
    for (let i = start; i < rtf.length; i++) {
        const ch = rtf[i];
        if (ch === '\\') { i++; continue; } /* skip the escaped/next char */
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) return i + 1;
        }
    }
    throw new Error('unbalanced RTF group starting at index ' + start);
}

/* Extract the plain text a conforming RTF reader would see: drop the
   fonttbl/colortbl header groups, resolve `\par` to \n, resolve `\uN`
   escapes (consuming the one-char ANSI fallback per `\uc1`), unescape
   `\\` `\{` `\}`, and drop every other control word / brace. */
function extractRtfText(rtf) {
    let s = rtf;
    for (const marker of ['{\\fonttbl', '{\\colortbl']) {
        const at = s.indexOf(marker);
        if (at !== -1) s = s.slice(0, at) + s.slice(findGroupEnd(s, at));
    }
    let out = '';
    for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (ch === '{' || ch === '}') continue;
        /* Raw CR/LF bytes are ignored by RTF readers (the spec's line
           breaks are \par / \line control words, never naked newlines) —
           the exporter emits a cosmetic "\n" after each \par. */
        if (ch === '\r' || ch === '\n') continue;
        if (ch !== '\\') { out += ch; continue; }
        const next = s[i + 1];
        if (next === '\\' || next === '{' || next === '}') {
            out += next;
            i++;
            continue;
        }
        const m = /^\\([a-z]+)(-?\d+)? ?/.exec(s.slice(i));
        if (m) {
            if (m[1] === 'par') out += '\n';
            i += m[0].length - 1;
            if (m[1] === 'u' && m[2] !== undefined) {
                const n = parseInt(m[2], 10);
                out += String.fromCharCode(n < 0 ? n + 65536 : n);
                /* \uc1 contract: ONE fallback char follows each \uN. */
                i++;
            }
            continue;
        }
        i++; /* lone control symbol — skip it */
    }
    return out;
}

/* Assert `rtf` is a structurally valid, self-contained RTF document a
   strict reader (ProPresenter / Cocoa) can extract text from. */
function assertValidRtfDoc(rtf, label) {
    label = label || 'rtf';
    /* Envelope: RTF magic + ANSI charset, closed at the end. */
    assert.match(rtf, /^\{\\rtf1\\ansi/, label + ': must open with {\\rtf1\\ansi');
    assert.ok(rtf.endsWith('}'), label + ': must close with }');
    /* Whole document is one balanced group tree. */
    assert.equal(findGroupEnd(rtf, 0), rtf.length, label + ': unbalanced braces');
    /* REQUIRED font table (the #1918 follow-up root cause): the RTF
       header grammar has no `?` on <fonttbl>; without it ProPresenter
       extracts zero text — right slides, blank screens. */
    const ftStart = rtf.indexOf('{\\fonttbl');
    assert.ok(ftStart !== -1, label + ': missing {\\fonttbl} — the RTF header REQUIRES a font table; ProPresenter extracts no text without one (#1918 follow-up)');
    const ftEnd = findGroupEnd(rtf, ftStart);
    const table = rtf.slice(ftStart, ftEnd);
    const decl = table.match(/\\f0(?:\\[a-z]+\d*)* +([^;{}\\]+);/);
    assert.ok(decl && decl[1].trim() !== '', label + ': font table must declare \\f0 with a non-empty name terminated by ";"');
    /* The declared font must also be SELECTED in the document area —
       declared-but-never-selected leaves a strict reader with no
       current font (same empty-extraction failure). */
    const body = rtf.slice(ftEnd);
    assert.match(body, /\\f0(?![0-9])/, label + ': \\f0 is declared but never selected in the document area');
    const fs = body.match(/\\fs(\d+)/);
    assert.ok(fs && parseInt(fs[1], 10) > 0, label + ': missing \\fsN font size in the document area');
    /* \cfN colour reference requires a colour table to index into. */
    if (/\\cf\d/.test(body)) {
        assert.ok(rtf.includes('{\\colortbl'), label + ': \\cf used without a {\\colortbl}');
    }
    /* \uc1 must precede any \uN so the one-char fallback contract holds. */
    const uAt = rtf.search(/\\u-?\d/);
    if (uAt !== -1) {
        const ucAt = rtf.indexOf('\\uc1');
        assert.ok(ucAt !== -1 && ucAt < uAt, label + ': \\uN used before \\uc1');
    }
    /* Pure 7-bit ASCII: non-ASCII must ride \uN?, never raw UTF-8 bytes
       (an \ansi reader would garble multi-byte sequences). */
    assert.ok(!/[^\x00-\x7f]/.test(rtf), label + ': raw non-ASCII byte in RTF — must be \\uN? escaped');
    return { table, body };
}

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
    await test('emits a structurally valid RTF document containing the text (#1918 follow-up)', () => {
        const rtf = exporter.buildRTF(['Hello']);
        assertValidRtfDoc(rtf, 'buildRTF([Hello])');
        assert.equal(extractRtfText(rtf), 'Hello');
    });
    await test('separates lines with \\par', () => {
        const rtf = exporter.buildRTF(['Line 1', 'Line 2']);
        assert.match(rtf, /Line 1\\par\nLine 2/);
        assert.equal(extractRtfText(rtf), 'Line 1\nLine 2');
    });
    await test('escapes RTF metacharacters (\\, {, })', () => {
        const rtf = exporter.buildRTF(['a\\b{c}d']);
        assert.ok(rtf.includes('a\\\\b\\{c\\}d'));
        assertValidRtfDoc(rtf, 'buildRTF(metachars)');
        assert.equal(extractRtfText(rtf), 'a\\b{c}d');
    });
    await test('escapes non-ASCII via \\uN?', () => {
        const rtf = exporter.buildRTF(['Noël']);
        assert.ok(rtf.includes('\\u235?'));
        assertValidRtfDoc(rtf, 'buildRTF(Noël)');
        assert.equal(extractRtfText(rtf), 'Noël');
    });
    await test('handles empty/missing input gracefully (still a valid document)', () => {
        for (const input of [[], null]) {
            const rtf = exporter.buildRTF(input);
            assertValidRtfDoc(rtf, 'buildRTF(' + JSON.stringify(input) + ')');
            assert.equal(extractRtfText(rtf), '', 'empty input must yield an empty-bodied document');
        }
    });
    await test('RTF header styling agrees with SECTION 5c text.attributes (rule #35)', () => {
        /* ELI5: the RTF says "Arial, 80pt, white" and so does the
           protobuf attributes field — this checks the two can't drift.
           Detail: buildRTF() derives its header from the SAME
           DEFAULT_FONT_NAME / DEFAULT_FONT_SIZE / DEFAULT_TEXT_COLOR
           constants defaultTextAttributes() reads; \fs is half-points,
           so RTF \fsN must equal attributes.font.size * 2. */
        const attrs = exporter._internal.defaultTextAttributes();
        const { table, body } = assertValidRtfDoc(exporter.buildRTF(['x']), 'lockstep');
        assert.ok(table.includes(' ' + attrs.font.name + ';'),
            'font table must name the same font as text.attributes.font.name');
        const fs = body.match(/\\fs(\d+)/);
        assert.equal(parseInt(fs[1], 10), attrs.font.size * 2,
            'RTF \\fsN (half-points) must equal text.attributes.font.size * 2');
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
        assertValidRtfDoc(rtf, 'decoded slide 1 rtf_data');
        assert.ok(rtf.includes('Line one'));
        assert.ok(rtf.includes('Line two'));
    });

    await test('EVERY slide\'s rtf_data is a valid self-contained RTF document (#1918 follow-up)', () => {
        /* ELI5: check every single slide's text file, not just the first
           one, is something ProPresenter can actually read words out of.
           Detail: the 2026-08-25 owner report was exactly this failure —
           12 slides, right group labels, ZERO text extracted, because
           each slide's RTF had no font table. Reflow shows text content
           regardless of styling, so an empty Reflow row means the READER
           got nothing, which this assertion now models: structural
           validity (assertValidRtfDoc) plus the slide's own words
           surviving a conforming-reader text extraction. */
        const decoded = Presentation.decode(encoded);
        const expectedTexts = ['Line one\nLine two', 'Gloria, gloria', 'Line three'];
        decoded.cues.forEach((cue, i) => {
            for (const action of cue.actions) {
                const element = action.slide.presentation.base_slide.elements[0].element;
                const rtf = new TextDecoder().decode(element.text.rtf_data);
                assertValidRtfDoc(rtf, 'cue ' + (i + 1) + ' (' + cue.name + ') rtf_data');
                assert.equal(extractRtfText(rtf), expectedTexts[i],
                    'cue ' + (i + 1) + ': a conforming RTF reader must extract the slide\'s own words');
            }
        });
    });

    /* ---------------------------------------------------------------- */
    /* #1918 — the blank/blue-slide regression guard. rule #34 in
       .claude/CLAUDE.md: the OLD test suite already round-tripped and
       decoded the slide element above without ever noticing it had a
       0x0 frame — a guard that only checks the element EXISTS is not a
       guard against it being invisible. These assertions check the
       actual rendering-relevant shape: a non-zero `bounds.size`, a set
       `text.attributes.font`, and that the RTF body a real ProPresenter
       user would see actually contains the song's own words. Mutation
       tested: temporarily removing `bounds:` from makeLyricCue() in
       propresenter-export.js turns the first assertion below RED (see
       the session's mutation transcript in the handoff/PR description).

       ⚠️ #1918 POSTSCRIPT (2026-08-25): these guards were themselves
       wrong-but-green in the rule-#34 sense — bounds/attributes were
       necessary but NOT the cause of the blank slides. The RTF itself
       was unreadable (no font table), the `rtf.includes(...)` check
       below only proved OUR text sat in OUR bytes, and the then-active
       exact-prefix snapshot assertions actively locked the broken
       format in. The behavioural guard is assertValidRtfDoc() +
       extractRtfText() above; these remain as the layout-shape guard. */
    await test('every lyric slide element has a non-zero bounds.size (#1918)', () => {
        const decoded = Presentation.decode(encoded);
        assert.ok(decoded.cues.length > 0, 'expected at least one cue to check');
        for (const cue of decoded.cues) {
            for (const action of cue.actions) {
                const element = action.slide.presentation.base_slide.elements[0].element;
                assert.ok(element.bounds, 'element.bounds is unset — this is the #1918 invisible-element regression');
                assert.ok(element.bounds.size, 'element.bounds.size is unset');
                assert.ok(element.bounds.size.width > 0, 'element.bounds.size.width must be > 0, got ' + element.bounds.size.width);
                assert.ok(element.bounds.size.height > 0, 'element.bounds.size.height must be > 0, got ' + element.bounds.size.height);
            }
        }
    });
    await test('every lyric slide element sets text.attributes.font (#1918)', () => {
        const decoded = Presentation.decode(encoded);
        for (const cue of decoded.cues) {
            for (const action of cue.actions) {
                const element = action.slide.presentation.base_slide.elements[0].element;
                assert.ok(element.text && element.text.attributes, 'element.text.attributes is unset');
                assert.ok(element.text.attributes.font, 'element.text.attributes.font is unset');
                assert.ok(element.text.attributes.font.name, 'font.name is unset');
                assert.ok(element.text.attributes.font.size > 0, 'font.size must be > 0');
            }
        }
    });
    await test('decoded rtf_data (TextDecoder) contains the expected verse text (#1918)', () => {
        const decoded = Presentation.decode(encoded);
        const element = decoded.cues[0].actions[0].slide.presentation.base_slide.elements[0].element;
        /* Use TextDecoder (not Buffer) per the #1918 spec, to exercise the
           same decode path a browser (not just Node/Buffer) would use. */
        const rtf = new TextDecoder().decode(element.text.rtf_data);
        assert.ok(rtf.includes('Line one'), 'expected decoded RTF to contain the verse text "Line one"');
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
    console.log('\nProgress + macrotask yield (#1571):');

    function stubSongs(n) {
        return Array.from({ length: n }, (_v, i) => ({
            songbook: 'CP', number: i + 1, title: 'Song ' + (i + 1),
            components: [{ type: 'verse', number: 1, lines: ['x'] }]
        }));
    }

    await test('buildBulkFiles() invokes onProgress once per song, with (done, total)', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const N = 30;
        const calls = [];
        const files = await exporter._internal.buildBulkFiles(stubSongs(N), {
            onProgress: (done, total) => { calls.push([done, total]); }
        });
        assert.equal(files.length, N);
        assert.equal(calls.length, N);
        assert.deepEqual(calls[0], [1, N]);
        assert.deepEqual(calls[N - 1], [N, N]);
    });

    await test('a throwing onProgress callback does not reject the build', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        /* Mutation target: removing buildBulkFiles()'s per-call try/catch
           around onProgress would make THIS throw instead of resolving. */
        const files = await exporter._internal.buildBulkFiles(stubSongs(5), {
            onProgress: () => { throw new Error('onProgress boom'); }
        });
        assert.equal(files.length, 5);
    });

    await test('an absent onProgress is fine (the option is optional)', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const files = await exporter._internal.buildBulkFiles(stubSongs(3), {});
        assert.equal(files.length, 3);
    });

    await test('buildBulkFiles() source contains a setTimeout(...) macrotask yield gated on a %25 boundary', () => {
        /* Source-level check (mirrors tests/php/test-qr-cache.php's PHP-side
           function-body extractor): brace-depth-bound the REAL function text
           so a mutation that deletes the yield — which changes ONLY timing,
           never the encoded output the functional tests above assert on —
           still has something concrete to turn red against. None of this
           function's string literals contain a brace character, so simple
           depth counting is safe without a separate comment-strip pass. */
        const idx = scriptSource.indexOf('async function buildBulkFiles');
        assert.ok(idx !== -1, 'buildBulkFiles() not found in propresenter-export.js');
        const openIdx = scriptSource.indexOf('{', idx);
        let depth = 0;
        let endIdx = -1;
        for (let i = openIdx; i < scriptSource.length; i++) {
            if (scriptSource[i] === '{') { depth++; }
            else if (scriptSource[i] === '}') {
                depth--;
                if (depth === 0) { endIdx = i; break; }
            }
        }
        assert.ok(endIdx !== -1, 'could not bound buildBulkFiles() by brace depth');
        const body = scriptSource.slice(openIdx, endIdx + 1);
        assert.ok(/setTimeout\s*\(/.test(body), 'expected a setTimeout(...) call inside buildBulkFiles()');
        assert.ok(/%\s*25\s*===\s*0/.test(body), 'expected the yield gated on a "every 25 songs" boundary');
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
