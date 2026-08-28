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
 * Structural RTF validity + Cocoa-dialect + wire-path — the #1918/#1950
 * follow-up guard (owner-reported repeatedly: right groups + right slide
 * count, but every exported slide blank and every Reflow row empty).
 *
 * ELI5: instead of checking the RTF string matches one exact spelling,
 * these helpers check it is a REAL RTF document any strict reader can
 * open, that it is Apple's "Cocoa RTF" flavour ProPresenter actually
 * reads, and that those bytes sit at the exact place on the wire PP7
 * pulls slide text from — because a self-consistent encode/decode check
 * is how the broken format shipped GREEN twice while PP showed blanks.
 *
 * The load-bearing finding (2026, live alpha v1.1.1009): ProPresenter 7
 * is a Cocoa app; its text reader extracts text ONLY from Apple "Cocoa
 * RTF" (the `\cocoartf<version>` header token). Plain RTF — even the
 * spec-valid, font-table-bearing RTF #1950 shipped — parses structurally
 * but yields an EMPTY attributed string, so slides are blank. See
 * assertValidRtfDoc()'s \cocoartf assertion and the raw wire-descent
 * tests further down (which prove the Cocoa bytes land at the PP7 field
 * path WITHOUT a circular protobufjs decode).
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

/* Extract the plain text a conforming RTF reader would see: drop ignorable
   destination groups `{\* … }` and the fonttbl/colortbl header groups,
   resolve `\par` to \n, resolve `\uN` escapes (consuming the one-char ANSI
   fallback per `\uc1`), unescape `\\` `\{` `\}`, and drop every other
   control word / brace.

   #1918/#1950 follow-up: this models the Apple "Cocoa RTF" the exporter now
   emits. Two reader behaviours the earlier plain-RTF model didn't need:
   (a) a leading-`\*` group is an IGNORABLE DESTINATION — Cocoa writes
   `{\*\expandedcolortbl;;}` beside the colour table and a conforming reader
   renders NONE of its contents; (b) control words may contain UPPERCASE
   letters (Cocoa emits `\CocoaLigature0`), so the control-word scan is
   `[a-zA-Z]+`, not lowercase-only — otherwise "CocoaLigature0" would leak
   into the body. (RTF spec: control word = `\` + letters; ignorable
   destination = `{\* …}`.) */
function extractRtfText(rtf) {
    let s = rtf;
    /* Ignorable destinations first: `{\* …}` groups contribute no body text. */
    let star;
    while ((star = s.indexOf('{\\*')) !== -1) {
        s = s.slice(0, star) + s.slice(findGroupEnd(s, star));
    }
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
        /* Control words may contain UPPERCASE letters — Cocoa emits
           `\CocoaLigature0`. A lowercase-only class would stop at the `C`
           and leak the rest as body text. */
        const m = /^\\([a-zA-Z]+)(-?\d+)? ?/.exec(s.slice(i));
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
    /* THE #1918/#1950 follow-up root cause (owner-reported again on live
       alpha v1.1.1009): ProPresenter 7 is a Cocoa app and its text reader
       extracts text ONLY from Apple "Cocoa RTF". A generic — even fully
       spec-valid — document parses its structure (right cue/slide counts)
       but yields an EMPTY attributed string, so every slide renders blank.
       The load-bearing marker is the `\cocoartf<version>` header token in
       the document preamble; without it PP7 extracts zero text. This is the
       assertion that would have caught the plain-RTF that shipped in
       #1918/#1950. (We deliberately do NOT assert \CocoaLigature0 — a real
       PP7 export omits it and only \cocoartf is universal across known-good
       files, so asserting it would fail on correct output — rule #34.) */
    assert.match(rtf, /^\{\\rtf1\\ansi\\ansicpg\d+\\cocoartf\d+/,
        label + ': missing \\cocoartf<version> — PP7 (Cocoa) extracts NO text from non-Cocoa RTF (#1918/#1950 follow-up)');
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

/* =====================================================================
 * Raw protobuf WIRE descent (the NON-CIRCULAR path proof) — #1918/#1950.
 *
 * ELI5: instead of decoding the file with the same schema that wrote it
 * (which always agrees with itself), we walk the raw bytes by hand,
 * field number by field number, down to the exact spot ProPresenter reads
 * slide text from — and check the Cocoa RTF is really there.
 *
 * Detail: the twice-shipped false-green was an encode→decode round-trip
 * against ONE schema (self-consistent by construction). These helpers use
 * NO protobufjs: they read varints and length-delimited chunks straight
 * off the wire and follow the field-number path re-derived from the .proto
 * sources (matching the greyshirtguy Proto 7.16 the bundle is built from):
 *   Presentation.cues=13 → Cue.actions=10 → Action.slide=23 →
 *   Action.SlideType.presentation=2 → PresentationSlide.base_slide=1 →
 *   Slide.elements=1 → [Slide.Element] → element=1 →
 *   Graphics.Element.text=13 → Graphics.Text.rtf_data=5,
 * with Slide.Element.info=4 (a varint) read directly off the Slide.Element
 * message. If the exporter ever moved the RTF to the wrong field, or
 * dropped the Cocoa dialect, or stopped setting info, these turn red
 * against the SHIPPED bytes — not against a decode of them.
 * =================================================================== */
function wireReadVarint(buf, pos) {
    let result = 0n, shift = 0n, p = pos;
    for (;;) {
        const b = buf[p++];
        result |= BigInt(b & 0x7f) << shift;
        if ((b & 0x80) === 0) break;
        shift += 7n;
    }
    return { value: result, next: p };
}
/* Enumerate the DIRECT fields of a message region [start,end). Each entry is
   { field, wt, varint? , vStart?, vEnd? }. */
function wireFields(buf, start, end) {
    const out = [];
    let p = start;
    while (p < end) {
        const t = wireReadVarint(buf, p); p = t.next;
        const tag = Number(t.value);
        const field = tag >>> 3, wt = tag & 7;
        if (wt === 0) { const v = wireReadVarint(buf, p); out.push({ field, wt, varint: Number(v.value) }); p = v.next; }
        else if (wt === 1) { out.push({ field, wt, vStart: p, vEnd: p + 8 }); p += 8; }
        else if (wt === 5) { out.push({ field, wt, vStart: p, vEnd: p + 4 }); p += 4; }
        else if (wt === 2) { const ld = wireReadVarint(buf, p); const len = Number(ld.value); out.push({ field, wt, vStart: ld.next, vEnd: ld.next + len }); p = ld.next + len; }
        else throw new Error('bad wire type ' + wt + ' at byte ' + (p - 1));
    }
    return out;
}
/* First DIRECT field #field of wire-type #wt in [s,e). Throws if absent. */
function wireFirst(buf, s, e, field, wt) {
    const f = wireFields(buf, s, e).find(x => x.field === field && x.wt === wt);
    if (!f) throw new Error('wire field ' + field + '/wt' + wt + ' not found in [' + s + ',' + e + ')');
    return f;
}
/* Descend the spine to every cue's first Slide.Element region (pure
   field-number walk, no schema). Returns [{ vStart, vEnd }]. */
function wireSlideElements(buf) {
    const els = [];
    for (const cue of wireFields(buf, 0, buf.length).filter(x => x.field === 13 && x.wt === 2)) {
        const a  = wireFirst(buf, cue.vStart, cue.vEnd, 10, 2); // Cue.actions=10
        const s  = wireFirst(buf, a.vStart, a.vEnd, 23, 2);     // Action.slide=23
        const pr = wireFirst(buf, s.vStart, s.vEnd, 2, 2);      // SlideType.presentation=2
        const b  = wireFirst(buf, pr.vStart, pr.vEnd, 1, 2);    // PresentationSlide.base_slide=1
        const el = wireFirst(buf, b.vStart, b.vEnd, 1, 2);      // Slide.elements=1 (Slide.Element)
        els.push({ vStart: el.vStart, vEnd: el.vEnd });
    }
    return els;
}
/* The rtf_data leaf bytes (latin1) for one Slide.Element region. */
function wireRtfOfElement(buf, el) {
    const g = wireFirst(buf, el.vStart, el.vEnd, 1, 2);   // Slide.Element.element=1
    const t = wireFirst(buf, g.vStart, g.vEnd, 13, 2);    // Graphics.Element.text=13
    const r = wireFirst(buf, t.vStart, t.vEnd, 5, 2);     // Graphics.Text.rtf_data=5
    return buf.toString('latin1', r.vStart, r.vEnd);
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

/* #1968 P2 §4.3 — the shared `sandbox` above deliberately sets `document`/`URL`
   undefined so `triggerDownload()` no-ops (see that function's own guard) — fine
   for filename/count assertions, but proving the .probundle export-LAYOUT fix
   (root-level .pro entries, no manifest.json) needs the actual ZIP BYTES
   `exportAllAsBundle()` hands to `triggerDownload()`, which the no-op path never
   exposes. This builds a fresh exporter instance with a `Blob`/`document`/`URL`
   stand-in that CAPTURES those bytes instead of discarding them, reusing
   `createFreshExporter()`'s override mechanism rather than forking a new one. */
function createBundleCapturingExporter() {
    let lastBytes = null;
    class CapturingBlob {
        constructor(parts) { lastBytes = parts && parts[0]; }
    }
    const anchor = { href: '', download: '', click() {} };
    const freshExporter = createFreshExporter({
        Blob: CapturingBlob,
        document: {
            createElement: () => anchor,
            body: { appendChild() {}, removeChild() {} }
        },
        URL: { createObjectURL: () => 'blob:test', revokeObjectURL: () => {} }
    });
    return { exporter: freshExporter, getBytes: () => lastBytes };
}

/* Walks a ZIP's LOCAL FILE HEADERS (PK\x03\x04) from byte 0 and returns every
   entry's raw NAME — the same shape/spirit as includes/propresenter7_zip.php's
   pp7ZipListEntries() (deliberately NOT reusing any iHymns encoder/decoder here:
   this is an independent, from-the-spec re-derivation, so a bug shared between
   buildZip() and a shared reader couldn't hide the export-layout regression this
   test exists to catch). buildZip() (propresenter-export.js SECTION 10) always
   writes STORED (method 0) entries with the name stored as literal UTF-8 bytes
   in the local header, immediately after the 30-byte fixed portion — see that
   function's own field-by-field construction. */
function listZipEntryNames(bytes) {
    const buf = Buffer.from(bytes);
    const names = [];
    let pos = 0;
    while (pos + 4 <= buf.length && buf.readUInt32LE(pos) === 0x04034b50) {
        const method   = buf.readUInt16LE(pos + 8);
        const compSize = buf.readUInt32LE(pos + 18);
        const nameLen  = buf.readUInt16LE(pos + 26);
        const extraLen = buf.readUInt16LE(pos + 28);
        names.push(buf.toString('utf8', pos + 30, pos + 30 + nameLen));
        assert.equal(method, 0, 'expected every buildZip() entry to be STORED (method 0)');
        pos = pos + 30 + nameLen + extraLen + compSize;
    }
    return names;
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
    /* #1918/#1950 follow-up — NON-CIRCULAR wire-path proof. Everything
       above decodes with the SAME protobufjs schema that encoded the
       bytes; that is exactly the self-consistent check that shipped green
       twice while ProPresenter showed blank slides. These two tests walk
       the RAW shipped bytes by field number (no protobufjs decode) and
       assert the load-bearing facts: the Cocoa-dialect RTF sits at the
       precise PP7 field path, and info=3 is set. Mutation-proven — revert
       \cocoartf in buildRTF() or drop info:3 in makeLyricCue() and the
       matching test below turns red (see the PR's mutation transcript). */
    await test('the Cocoa-dialect RTF lands at the exact PP7 wire field path (raw bytes, no decode)', () => {
        const buf = Buffer.from(encoded);
        const els = wireSlideElements(buf);
        assert.equal(els.length, 3, 'expected 3 Slide.Element regions on the wire (one per component)');
        els.forEach((el, i) => {
            /* rtf_data reached ONLY by field-number descent:
               13→10→23→2→1→1→1→13→5. wireRtfOfElement throws if any hop's
               field is missing, so a moved field fails loudly here. */
            const rtf = wireRtfOfElement(buf, el);
            assert.ok(rtf.startsWith('{\\rtf1'),
                'slide ' + (i + 1) + ': rtf_data at the PP7 leaf must begin {\\rtf1');
            assert.match(rtf, /\\cocoartf\d+/,
                'slide ' + (i + 1) + ': rtf_data at the PP7 leaf must carry \\cocoartf<version> — the token PP7 (Cocoa) needs to extract ANY text (#1918/#1950)');
            /* the pure-ASCII opening word must be physically present in the
               shipped bytes (rules out a char-escaping cause: every prior
               slide was blank INCLUDING pure-ASCII lines). */
            assert.ok(/[A-Za-z]/.test(rtf.replace(/\\[A-Za-z]+\d*/g, '')),
                'slide ' + (i + 1) + ': rtf_data carries no literal body text');
        });
    });

    await test('every Slide.Element sets info=3 on the wire (defensive #1918/#1950 lever)', () => {
        const buf = Buffer.from(encoded);
        const els = wireSlideElements(buf);
        assert.ok(els.length > 0, 'expected at least one Slide.Element');
        els.forEach((el, i) => {
            /* Slide.Element.info = field 4, uint32 varint (slide.proto:26). */
            const info = wireFields(buf, el.vStart, el.vEnd).find(x => x.field === 4 && x.wt === 0);
            assert.ok(info, 'slide ' + (i + 1) + ': Slide.Element.info (field 4 varint) is absent on the wire');
            assert.equal(info.varint, 3,
                'slide ' + (i + 1) + ': Slide.Element.info must be 3 (matches the PP7-verified bussnet generator)');
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

    /* #1968 P2 §4.3 — the export-layout FIX. Real ProPresenter-exported bundles
       carry their `.pro`(s) at the ZIP ROOT with NO manifest file (the inner
       `.pro` IS the manifest — see includes/propresenter7_zip.php's doc-block
       and .claude/propresenter-interop-1968-plan.md §4 for the byte-verified
       ground truth). This exporter used to invent a "Documents/" + top-level
       "manifest.json" layout that was never verified against real ProPresenter
       — exactly the false-positive class this epic exists to kill.

       MUTATION PROOF (performed 2026-08-28 against the real working tree, each
       mutation applied, this suite re-run and confirmed RED, then reverted via
       the Edit tool back to the exact original text before moving on — rule #34):
         m1 — re-prefixed every entry name with 'Documents/' (the old layout) ->
              the "ROOT, no Documents/ prefix" test went RED (2 of 3 new tests
              failed: the single-song root check and the multi-song root check).
         m2 — re-added the 'manifest.json' unshift() (the old invented file) ->
              3 of the 3 new tests went RED (entry COUNT no longer matched 1/3,
              so even the root-prefix checks failed downstream of the count
              assertion).
       Both mutations were reverted immediately after confirming red; the
       propresenter-export.js this suite ships against is unmodified. */
    await test('exportAllAsBundle: .pro entries sit at the bundle ROOT, no "Documents/" prefix', async () => {
        const { exporter: capturingExporter, getBytes } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        const result = await capturingExporter.exportAllAsBundle(
            [SAMPLE_SONG],
            { songbookName: 'Carol Praise', songbookAbbrev: 'CP' }
        );
        const zipBytes = getBytes();
        assert.ok(zipBytes, 'expected exportAllAsBundle() to hand triggerDownload() real ZIP bytes');
        assert.equal(result.count, 1);
        const names = listZipEntryNames(zipBytes);
        assert.equal(names.length, 1, 'expected exactly one entry (the one song, no manifest)');
        assert.ok(!names[0].includes('/'), `expected the .pro entry name to carry NO '/' at all (sitting at the ZIP root), got '${names[0]}'`);
        assert.ok(!/^Documents\//.test(names[0]), `expected NO "Documents/" prefix on '${names[0]}' (the pre-#1968 invented layout)`);
        assert.ok(names[0].endsWith('.pro'), `expected a '.pro' entry, got '${names[0]}'`);
    });

    await test('exportAllAsBundle: NO manifest.json entry anywhere in the ZIP', async () => {
        const { exporter: capturingExporter, getBytes } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        await capturingExporter.exportAllAsBundle(
            [SAMPLE_SONG],
            { songbookName: 'Carol Praise', songbookAbbrev: 'CP' }
        );
        const names = listZipEntryNames(getBytes());
        assert.ok(!names.includes('manifest.json'), `expected no 'manifest.json' entry, got entries: ${names.join(', ')}`);
    });

    await test('exportAllAsBundle: a MULTI-song bundle has every .pro at root, one entry per song, no manifest', async () => {
        const { exporter: capturingExporter, getBytes } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        const subset = [
            { songbook: 'CP', number: 1, title: 'Song One',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'CP', number: 2, title: 'Song Two',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] },
            { songbook: 'CP', number: 3, title: 'Song Three',
              components: [{ type: 'verse', number: 1, lines: ['x'] }] }
        ];
        await capturingExporter.exportAllAsBundle(subset, { songbookName: 'Carol Praise', songbookAbbrev: 'CP' });
        const names = listZipEntryNames(getBytes());
        assert.equal(names.length, 3, `expected exactly 3 entries (one .pro per song, no manifest), got: ${names.join(', ')}`);
        names.forEach((n) => {
            assert.ok(!n.includes('/'), `expected '${n}' to carry no '/' (root-level)`);
            assert.ok(n.endsWith('.pro'), `expected '${n}' to be a .pro entry`);
        });
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

    /* ==================================================================
     * Set-list .proplaylist export (#1968 P3-EXPORT, plan §5.2)
     * ==================================================================
     * The deep JS-encoder <-> PHP-decoder closure (playlist name, item
     * order/types, document_path/arrangement round-trip) lives in
     * tests/php/test-pp7-setlist-roundtrip.php (a separate process, per
     * that file's own doc-block on why a same-process self-decode proves
     * nothing for this epic). These tests cover what belongs IN this
     * process: the pure entry-resolution logic, the URL-shape helper, the
     * public function's contract (skip-unresolved, throw-on-nothing-
     * resolvable), and the ZIP LAYOUT (via the same capturing-Blob
     * technique createBundleCapturingExporter() already established for
     * exportAllAsBundle() above). */
    console.log('\nSet-list .proplaylist export (#1968 P3):');

    /* #1968 P3 — every value under test in this block is built by CODE RUNNING
       INSIDE the vm sandbox (`exporter._internal.*`, `capturingExporter.*`),
       which is a SEPARATE V8 realm from this outer test file: a `{}`/`[]`
       LITERAL written inside sandboxed source code binds to THAT realm's own
       intrinsic Object/Array prototypes regardless of what `sandbox.Object`/
       `sandbox.Array` happen to reference (a well-known `vm` quirk — setting
       those sandbox properties only affects code that explicitly resolves the
       global IDENTIFIER `Array`/`Object`, never a bare literal). `assert.strict`'s
       `deepEqual`/`deepStrictEqual` compares prototypes too, so comparing a
       sandbox-realm object/array directly against an outer-realm literal fails
       with "same structure but are not reference-equal" even though every KEY
       and VALUE matches — see the existing `Array.from(payload.cue_groups.map(…))`
       calls a little further up this file for the same fix applied to a
       primitives-only array (`Array.from`, called as the OUTER Array's own
       method, copies elements into a genuine outer-realm array — but does
       nothing for NESTED objects, which is why a plain array-of-strings can get
       away with just that while these object-bearing shapes need the fuller
       fix below). `reify()` round-trips through JSON, which only ever produces
       genuine plain objects/arrays in the CALLING (outer) realm — safe here
       because every value in this block is plain JSON-shaped data (strings/
       numbers/plain objects/arrays), never a class instance, Map, or anything
       else JSON can't faithfully represent. */
    const reify = (v) => JSON.parse(JSON.stringify(v));

    await test('resolveSetlistPlaylistEntries: with a plan, a slot WITH songId is a song entry, one WITHOUT is a header', () => {
        const entries = exporter._internal.resolveSetlistPlaylistEntries({
            name: 'X',
            songs: [{ id: 'A' }],
            plan: { slots: [
                { id: 's1', label: 'Welcome', type: 'welcome' },
                { id: 's2', label: 'Song', type: 'song', songId: 'A' },
                { id: 's3', label: 'Prayer', type: 'prayer' }
            ] }
        });
        assert.deepEqual(reify(entries), [
            { kind: 'header', sectionName: 'Welcome' },
            { kind: 'song', songId: 'A' },
            { kind: 'header', sectionName: 'Prayer' }
        ]);
    });

    await test('resolveSetlistPlaylistEntries: no plan falls back to the flat songs array (no headers)', () => {
        const entries = exporter._internal.resolveSetlistPlaylistEntries({
            name: 'X',
            songs: [{ id: 'A' }, { id: 'B' }]
        });
        assert.deepEqual(reify(entries), [
            { kind: 'song', songId: 'A' },
            { kind: 'song', songId: 'B' }
        ]);
    });

    await test('resolveSetlistPlaylistEntries: an empty plan.slots array also falls back to setlist.songs', () => {
        const entries = exporter._internal.resolveSetlistPlaylistEntries({
            name: 'X',
            songs: [{ id: 'A' }],
            plan: { slots: [] }
        });
        assert.deepEqual(reify(entries), [{ kind: 'song', songId: 'A' }]);
    });

    await test('resolveSetlistPlaylistEntries: an unlabelled non-song slot falls back to "Section"', () => {
        const entries = exporter._internal.resolveSetlistPlaylistEntries({
            name: 'X', songs: [], plan: { slots: [{ id: 's1', type: 'other' }] }
        });
        assert.deepEqual(reify(entries), [{ kind: 'header', sectionName: 'Section' }]);
    });

    await test('buildPlaylistPresentationUrl: percent-encodes the basename and sets a CURRENT_RESOURCE-relative local pointer', () => {
        const url = exporter._internal.buildPlaylistPresentationUrl('1 (CP) - A Song.pro');
        assert.equal(url.absolute_string, 'file:///1%20(CP)%20-%20A%20Song.pro');
        assert.deepEqual(reify(url.local), { root: 12, path: '1 (CP) - A Song.pro' });
    });

    await test('makePlaylistPresentationItem: omits arrangement/arrangement_name when not supplied', () => {
        const item = exporter._internal.makePlaylistPresentationItem('Song', 'song.pro', null, null);
        assert.equal(item.name, 'Song');
        assert.ok(!('arrangement' in item.presentation));
        assert.ok(!('arrangement_name' in item.presentation));
    });

    await test('makePlaylistHeaderItem: sets the header oneof branch with the given label as name', () => {
        const item = exporter._internal.makePlaylistHeaderItem('Prayer');
        assert.equal(item.name, 'Prayer');
        assert.deepEqual(reify(item.header), {});
        assert.ok(!('presentation' in item));
    });

    const SETLIST_SAMPLE_SONGS = [
        { id: 'CP-0001', number: 1, title: 'Song One', songbook: 'CP',
          components: [{ type: 'verse', number: 1, lines: ['line one'] }] },
        { id: 'MP-0100', number: 100, title: 'Song Two', songbook: 'MP',
          components: [{ type: 'verse', number: 1, lines: ['line two'] }] }
    ];

    await test('buildSetlistProFiles: attaches songId + a real minted arrangement UUID/name per file', async () => {
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const files = await exporter._internal.buildSetlistProFiles(SETLIST_SAMPLE_SONGS, {});
        assert.equal(files.length, 2);
        files.forEach((f, i) => {
            assert.equal(f.songId, SETLIST_SAMPLE_SONGS[i].id);
            assert.equal(f.arrangementName, 'Default');
            assert.ok(f.arrangementUuid && typeof f.arrangementUuid.string === 'string'
                && f.arrangementUuid.string.length === 36,
                `expected a real rv.data.UUID object, got ${JSON.stringify(f.arrangementUuid)}`);
        });
    });

    await test('exportSetlistAsProplaylist: ZIP contains "data" + one root-level .pro per song, no other entries', async () => {
        const { exporter: capturingExporter, getBytes } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        const setlist = { id: 'sl1', name: 'Sunday AM', songs: [{ id: 'CP-0001' }, { id: 'MP-0100' }] };
        const result = await capturingExporter.exportSetlistAsProplaylist(setlist, SETLIST_SAMPLE_SONGS, {});
        assert.equal(result.filename, 'Sunday AM.proplaylist');
        assert.equal(result.songCount, 2);
        assert.equal(result.itemCount, 2);
        assert.deepEqual(reify(result.skipped), []);

        const names = listZipEntryNames(getBytes());
        assert.equal(names.length, 3, `expected 3 entries (data + 2 .pro), got: ${names.join(', ')}`);
        assert.ok(names.includes('data'), `expected a "data" entry, got: ${names.join(', ')}`);
        const proNames = names.filter((n) => n !== 'data');
        proNames.forEach((n) => {
            assert.ok(!n.includes('/'), `expected '${n}' to carry no '/' (root-level, mirrors .probundle's P2 layout fix)`);
            assert.ok(n.endsWith('.pro'), `expected '${n}' to be a .pro entry`);
        });
    });

    await test('exportSetlistAsProplaylist: honours plan.slots headers -> item count includes headers, songCount does not', async () => {
        const { exporter: capturingExporter } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        const setlist = {
            id: 'sl2', name: 'With Headers',
            songs: [{ id: 'CP-0001' }, { id: 'MP-0100' }],
            plan: { slots: [
                { id: 's1', label: 'Welcome', type: 'welcome' },
                { id: 's2', type: 'song', songId: 'CP-0001' },
                { id: 's3', label: 'Prayer', type: 'prayer' },
                { id: 's4', type: 'song', songId: 'MP-0100' }
            ] }
        };
        const result = await capturingExporter.exportSetlistAsProplaylist(setlist, SETLIST_SAMPLE_SONGS, {});
        assert.equal(result.songCount, 2, 'songCount counts only the .pro files actually built');
        assert.equal(result.itemCount, 4, 'itemCount counts headers AND songs (4 playlist items)');
    });

    await test('exportSetlistAsProplaylist: a song referenced but absent from `songs` is SKIPPED, not fatal', async () => {
        const { exporter: capturingExporter, getBytes } = createBundleCapturingExporter();
        await capturingExporter.init({ protobuf, bundle });
        const setlist = { id: 'sl3', name: 'Missing One', songs: [{ id: 'CP-0001' }, { id: 'GHOST-9999' }] };
        const result = await capturingExporter.exportSetlistAsProplaylist(setlist, SETLIST_SAMPLE_SONGS, {});
        assert.equal(result.songCount, 1);
        assert.equal(result.itemCount, 1);
        assert.deepEqual(reify(result.skipped), ['GHOST-9999']);
        const names = listZipEntryNames(getBytes());
        assert.equal(names.length, 2, `expected data + 1 .pro, got: ${names.join(', ')}`);
    });

    await test('exportSetlistAsProplaylist: throws when no setlist argument is given', async () => {
        await assert.rejects(
            () => exporter.exportSetlistAsProplaylist(null, SETLIST_SAMPLE_SONGS, {}),
            /setlist argument is required/
        );
    });

    await test('exportSetlistAsProplaylist: throws when nothing in the set list is resolvable', async () => {
        const setlist = { id: 'sl4', name: 'All Missing', songs: [{ id: 'GHOST-1' }, { id: 'GHOST-2' }] };
        await assert.rejects(
            () => exporter.exportSetlistAsProplaylist(setlist, [], {}),
            /no resolvable songs/
        );
    });

    await test('exportSetlistAsProplaylist: the SAME arrangement UUID a song\'s .pro carries is referenced by its playlist item', async () => {
        /* Cross-checks the encode-time wiring purely in-process (protobufjs
           decode of BOTH the .pro and the PlaylistItem.Presentation) -- the
           FULL closure through the independent PHP decoder is
           tests/php/test-pp7-setlist-roundtrip.php's job; this is the
           quick in-process confirmation that the same object flows through. */
        exporter._internal.resetForTests();
        await exporter.init({ protobuf, bundle });
        const files = await exporter._internal.buildSetlistProFiles([SETLIST_SAMPLE_SONGS[0]], {});
        const proBytes = files[0].bytes;
        const Presentation = protobuf.Root.fromJSON(bundle).lookupType('rv.data.Presentation');
        const decodedPro = Presentation.decode(proBytes);
        const proObj = Presentation.toObject(decodedPro, { defaults: false });

        const item = exporter._internal.makePlaylistPresentationItem(
            'Song One', files[0].name, files[0].arrangementUuid, files[0].arrangementName
        );
        assert.equal(item.presentation.arrangement.string, proObj.selected_arrangement.string,
            'the playlist item\'s arrangement UUID must be the SAME string as the .pro\'s own selected_arrangement');
    });

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
})();
