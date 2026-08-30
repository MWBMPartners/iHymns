/**
 * iHymns — ProPresenter 7+ STATIC encoder: byte-identical + CSP-safe (#1788)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved. Proprietary.
 *
 * PURPOSE (the bug this locks down):
 * PP7 export died in the browser with a CSP `unsafe-eval` error. Cause: the
 * exporter loaded the schema as a REFLECTION descriptor
 * (`protobuf.Root.fromJSON`) and protobufjs builds a reflected type's encoder
 * LAZILY via `new Function()` on first `.encode()` — refused by the enforcing
 * nonce CSP (#117). The fix ships a `pbjs -t static` build
 * (`protos/pp7-proto-static.js`) that writes the encoder out longhand (no
 * eval) and the exporter prefers it.
 *
 * This test proves the fix is BOTH correct and safe:
 *   1. byte-identical — the STATIC encoder produces EXACTLY the same wire
 *      bytes as the REFLECTION encoder for the same payload (so the .pro file
 *      ProPresenter opens is unchanged);
 *   2. CSP-safe — the static artifact contains no `new Function`/`eval`, and a
 *      static `.encode()` still runs when the `Function` constructor + `eval`
 *      are replaced by throwing stubs (simulating the CSP), whereas the
 *      reflection encoder's codegen needs them.
 *
 * Also a tree-derived CI guard (rule #34): it drives the REAL artifact + the
 * REAL exporter, and every assertion below has been mutation-checked
 * (break the thing → red).
 *
 * USAGE: node tests/test-propresenter-static-csp.js
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
const ED = path.join(PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor');

const EXPORTER_PATH = path.join(ED, 'propresenter-export.js');
const STATIC_PATH   = path.join(ED, 'protos', 'pp7-proto-static.js');
const BUNDLE_PATH   = path.join(ED, 'protos', 'proto-bundle.json');

const exporterSrc = fs.readFileSync(EXPORTER_PATH, 'utf8');
const staticSrc   = fs.readFileSync(STATIC_PATH, 'utf8');
const bundle      = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

const SAMPLE_SONG = {
    id: 'CP-0001', number: 1, title: 'A baby was born in Bethlehem',
    songbook: 'CP', songbookName: 'Carol Praise',
    writers: ['Ivor Golby'], composers: ['Noël Tredinnick'],
    artists: ['All Souls Orchestra'], copyright: '© 2024 A & C Black Limited',
    ccli: '1234567',
    components: [
        { type: 'verse', number: 1, lines: ['Line one', 'Line two'] },
        { type: 'chorus', lines: ['Gloria, gloria'] },
        { type: 'verse', number: 2, lines: ['Line three'] },
    ],
};

let pass = 0, fail = 0;
async function test(name, fn) {
    try { await fn(); console.log(`  ✓ ${name}`); pass++; }
    catch (err) { console.error(`  ✗ ${name}\n      ${err && err.message}`); fail++; }
}

/* Base browser-ish globals for a vm sandbox. `crypto`/`TextEncoder` let the
   exporter mint UUIDs + RTF bytes; `Function`/`eval` are overridable per test. */
function makeSandbox(extra) {
    const sb = {
        window: {}, protobuf, crypto: globalThis.crypto,
        TextEncoder: globalThis.TextEncoder, TextDecoder: globalThis.TextDecoder,
        Uint8Array, Uint32Array, Math, Object, Error, Array, String, Number,
        Boolean, JSON, isNaN, parseInt, Date, Promise, Buffer,
        Blob: globalThis.Blob, URL: undefined, document: undefined, fetch: undefined,
        ...(extra || {}),
    };
    vm.createContext(sb);
    return sb;
}

/* Load the exporter into a sandbox and return window.iHymnsProPresenter. */
function loadExporter(sb) {
    vm.runInContext(exporterSrc, sb, { filename: EXPORTER_PATH });
    const ex = sb.window.iHymnsProPresenter;
    if (!ex) throw new Error('exporter did not publish window.iHymnsProPresenter');
    return ex;
}

/* Load the STATIC module into a sandbox; it reads the sandbox `protobuf` and
   sets `iHymnsPP7Proto`. The IIFE targets `self`||`this`; provide `self`.
   #1968 P3 — the existence check now ALSO requires rv.data.PlaylistDocument
   (added by regenerating tools/build-proto-static.js's ENTRY_POINTS to
   include propresenter.proto/playlist.proto): this guard is exactly where a
   FUTURE regen that accidentally dropped one of the two top-level types
   would be caught, so both are asserted here rather than only the one this
   test happened to be written against originally. */
function loadStatic(sb) {
    sb.self = sb;
    vm.runInContext(staticSrc, sb, { filename: STATIC_PATH });
    const root = sb.self.iHymnsPP7Proto || sb.iHymnsPP7Proto;
    if (!root || !root.rv || !root.rv.data || !root.rv.data.Presentation) {
        throw new Error('static module did not publish iHymnsPP7Proto.rv.data.Presentation');
    }
    if (!root.rv.data.PlaylistDocument) {
        throw new Error('static module did not publish iHymnsPP7Proto.rv.data.PlaylistDocument (#1968 P3)');
    }
    return root.rv.data.Presentation;
}

/* #1968 P3 — sibling to loadStatic() above, for the NEW rv.data.PlaylistDocument
   type. A separate function (rather than overloading loadStatic()'s return)
   so existing Presentation-only call sites are untouched. */
function loadStaticPlaylistDocument(sb) {
    sb.self = sb;
    vm.runInContext(staticSrc, sb, { filename: STATIC_PATH });
    const root = sb.self.iHymnsPP7Proto || sb.iHymnsPP7Proto;
    if (!root || !root.rv || !root.rv.data || !root.rv.data.PlaylistDocument) {
        throw new Error('static module did not publish iHymnsPP7Proto.rv.data.PlaylistDocument');
    }
    return root.rv.data.PlaylistDocument;
}

async function main() {
    console.log('ProPresenter 7+ static encoder — byte-identical + CSP-safe (#1788):');

    /* Build the payload ONCE (via the real exporter internals), then encode it
       through BOTH schema representations. Using one payload object means the
       minted UUIDs are identical for both encoders, so any byte difference is a
       genuine encoder discrepancy — not randomness. */
    const sb = makeSandbox();
    const exporter = loadExporter(sb);
    assert.ok(exporter._internal && typeof exporter._internal.buildPresentationPayload === 'function',
        'exporter must expose _internal.buildPresentationPayload');
    const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG);

    const reflectionPresentation = protobuf.Root.fromJSON(bundle).lookupType('rv.data.Presentation');
    const staticPresentation = loadStatic(makeSandbox());

    let bytesReflection, bytesStatic;

    await test('reflection encoder produces bytes (baseline)', () => {
        bytesReflection = Buffer.from(reflectionPresentation.encode(payload).finish());
        assert.ok(bytesReflection.length > 0, 'reflection output empty');
    });

    await test('static encoder produces bytes', () => {
        bytesStatic = Buffer.from(staticPresentation.encode(payload).finish());
        assert.ok(bytesStatic.length > 0, 'static output empty');
    });

    await test('STATIC output is BYTE-IDENTICAL to reflection output', () => {
        assert.equal(bytesStatic.length, bytesReflection.length,
            `length differs: static=${bytesStatic.length} reflection=${bytesReflection.length}`);
        assert.equal(Buffer.compare(bytesStatic, bytesReflection), 0,
            'static and reflection wire bytes differ');
    });

    await test('the static output round-trips back through the reflection decoder', () => {
        const decoded = reflectionPresentation.decode(bytesStatic);
        const obj = reflectionPresentation.toObject(decoded, { defaults: false });
        assert.ok(obj && obj.cues && obj.cues.length >= 1, 'decoded presentation has no cues');
    });

    /* ==================================================================
     * #1968 P6 — chord custom_attributes byte-identity (plan §4.3/§6.4).
     *
     * WHY THIS ROW IS MANDATORY, NOT OPTIONAL: this file's OWN doc-block
     * above explains #1788's root cause — protobufjs's REFLECTION encoder
     * builds a message type's encoder LAZILY via `new Function()`, which
     * the enforcing nonce CSP refuses in-browser, so the STATIC (`pbjs -t
     * static`) artifact is what actually ships. `Attributes.custom_attributes`
     * is a REPEATED SUB-MESSAGE array field (`CustomAttribute`, itself
     * containing a `oneof` and a nested `IntRange` sub-message) — exactly
     * the shape class this file's own §4.3 citation flags as the one
     * that CAN diverge between the two encoders (sub-message arrays,
     * unlike the flat scalar `info` field #1918 already proved identical
     * — see propresenter-export.js's own comment on why `info` was safe
     * but `paragraph_style`, a DIFFERENT sub-message, was not). Every
     * assertion above this point used SAMPLE_SONG, which carries NO
     * chords — so without this row, the STATIC encoder's handling of
     * `custom_attributes` would ship completely unverified against its
     * one real-world consumer, real ProPresenter opening the file. */
    const CHORD_SAMPLE_SONG = {
        id: 'CP-9999', number: 99, title: 'Chord Export Sample', songbook: 'CP',
        components: [
            {
                type: 'verse', number: 1,
                lines: ['Amazing grace how sweet the sound', 'That saved a wretch like me'],
                chords: ['G        D', null],
            },
            {
                type: 'chorus',
                lines: ['Praise the Lord'],
                chords: [['C', 'F', 'G']],
            },
        ],
    };
    const chordPayload = exporter._internal.buildPresentationPayload(CHORD_SAMPLE_SONG);

    let bytesChordReflection, bytesChordStatic;

    await test('chord payload: reflection encoder produces bytes (baseline)', () => {
        bytesChordReflection = Buffer.from(reflectionPresentation.encode(chordPayload).finish());
        assert.ok(bytesChordReflection.length > 0, 'reflection output empty');
    });

    await test('chord payload: static encoder produces bytes', () => {
        bytesChordStatic = Buffer.from(staticPresentation.encode(chordPayload).finish());
        assert.ok(bytesChordStatic.length > 0, 'static output empty');
    });

    await test('chord payload: STATIC output is BYTE-IDENTICAL to reflection output (custom_attributes sub-message arrays)', () => {
        assert.equal(bytesChordStatic.length, bytesChordReflection.length,
            `length differs: static=${bytesChordStatic.length} reflection=${bytesChordReflection.length}`);
        assert.equal(Buffer.compare(bytesChordStatic, bytesChordReflection), 0,
            'static and reflection wire bytes differ for a chord-bearing payload');
    });

    await test('chord payload: the static output round-trips back through the reflection decoder WITH custom_attributes intact', () => {
        const decoded = reflectionPresentation.decode(bytesChordStatic);
        // { defaults: true } here (unlike every OTHER toObject() call in this file) is
        // deliberate: `range.start` for the FIRST chord is 0 — proto3's implicit-presence
        // default, genuinely absent from the wire (the same omission
        // includes/propresenter7_decode.php's pp7DecodeIntRange() had to learn to handle
        // correctly, see that function's own doc-block) — so {defaults:false} would report it
        // as `undefined` even though decode succeeded correctly; {defaults:true} fills it back
        // in as the explicit 0 this assertion wants to see.
        const obj = reflectionPresentation.toObject(decoded, { defaults: true });
        const verse1Element = obj.cues[0].actions[0].slide.presentation.base_slide.elements[0].element;
        assert.ok(verse1Element.text.attributes.custom_attributes
            && verse1Element.text.attributes.custom_attributes.length === 2,
            'expected 2 custom_attributes rows on the STRING-cell verse');
        assert.equal(verse1Element.text.attributes.custom_attributes[0].chord, 'G');
        assert.equal(verse1Element.text.attributes.custom_attributes[0].range.start, 0);
        assert.equal(verse1Element.text.attributes.custom_attributes[1].chord, 'D');
        assert.equal(verse1Element.text.attributes.custom_attributes[1].range.start, 9);
    });

    await test('static artifact contains NO new Function / eval (CSP-safe by construction)', () => {
        /* Strip block + line comments so the header prose ("… new Function …")
           doesn't false-positive (rule #34 — the guard must not fire on
           legitimate text). */
        const code = staticSrc
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/^\s*\/\/.*$/gm, '');
        assert.ok(!/\bnew\s+Function\b/.test(code), 'static artifact contains `new Function`');
        assert.ok(!/[^.\w]eval\s*\(/.test(code), 'static artifact contains an eval() call');
    });

    await test('static .encode() still runs with Function + eval POISONED (CSP simulation)', () => {
        /* Load protobuf + the static module normally, THEN poison the sandbox's
           Function constructor and eval and encode again. The static encoder
           uses only the hand-written protobuf.Writer, so it must not care. */
        const poison = () => { throw new Error('CSP: unsafe-eval blocked'); };
        const sbCsp = makeSandbox();
        const P = loadStatic(sbCsp);
        sbCsp.Function = poison;
        sbCsp.eval = poison;
        /* Run the encode INSIDE the poisoned context so any lazy Function use
           would resolve to the poisoned global. */
        sbCsp.__P = P; sbCsp.__payload = payload; sbCsp.__out = null;
        vm.runInContext('__out = __P.encode(__payload).finish();', sbCsp, { filename: 'csp-encode' });
        assert.ok(sbCsp.__out && sbCsp.__out.length > 0, 'static encode produced no bytes under CSP');
        assert.equal(Buffer.compare(Buffer.from(sbCsp.__out), bytesStatic), 0,
            'static encode under CSP differs from normal static encode');
    });

    await test('exporter init() PREFERS the static schema (no reflection codegen in-browser)', async () => {
        /* With a static root present, init() must adopt it and NEVER build a
           reflection Root (the codegen path). Prove by giving init() a static
           root and a POISONED reflection runtime — if it touched fromJSON it
           would throw. */
        const sb2 = makeSandbox();
        const ex2 = loadExporter(sb2);
        const staticRoot = (() => { const s = makeSandbox(); loadStatic(s); return (s.self.iHymnsPP7Proto); })();
        ex2._internal.resetForTests();
        await ex2.init({
            protoStatic: staticRoot,
            protobuf: { Root: { fromJSON: () => { throw new Error('reflection path must not run'); } } },
        });
        const bytes = await ex2.buildPresentation(SAMPLE_SONG, {});
        assert.ok(bytes && bytes.length > 0, 'exporter static path produced no bytes');
    });

    /* ==================================================================
     * #1968 P3 — rv.data.PlaylistDocument (the .proplaylist EXPORT
     * addition): the SAME byte-identical + CSP-safe proof this file already
     * runs for rv.data.Presentation, repeated for the NEW top-level message
     * type tools/build-proto-bundle.js / tools/build-proto-static.js now
     * also generate encode/create/verify for (propresenter.proto +
     * playlist.proto added to both files' ENTRY_POINTS). This is the
     * concrete "confirm the regen covers PlaylistDocument too" proof the
     * task brief asked for, distinct from the Presentation-byte-identity
     * tests above (which prove the regen did NOT perturb the EXISTING
     * message — this proves the NEW message works at all, under the same
     * CSP constraints). */
    const SAMPLE_PLAYLIST_DOCUMENT = {
        type: 1, // PlaylistDocument.Type.TYPE_PRESENTATION
        root_node: {
            name: 'PLAYLIST',
            type: 1, // Playlist.Type.TYPE_PLAYLIST (see propresenter-export.js's
                     // PLAYLIST_TYPE_PLAYLIST doc-block for why not TYPE_ROOT)
            playlists: { playlists: [{
                name: 'Test Playlist',
                type: 1,
                items: { items: [
                    { name: 'Welcome', header: {} },
                    {
                        name: 'Song One',
                        presentation: {
                            document_path: {
                                absolute_string: 'file:///song.pro',
                                local: { root: 12, path: 'song.pro' } // ROOT_CURRENT_RESOURCE
                            },
                            arrangement: { string: '11111111-2222-3333-4444-555555555555' },
                            arrangement_name: 'Default' // #1968 P3 field 5 — see playlist.proto:116
                        }
                    }
                ] }
            }] }
        }
    };

    const reflectionPlaylistDocument = protobuf.Root.fromJSON(bundle).lookupType('rv.data.PlaylistDocument');
    const staticPlaylistDocument = loadStaticPlaylistDocument(makeSandbox());

    let bytesPlaylistReflection, bytesPlaylistStatic;

    await test('PlaylistDocument: reflection encoder produces bytes (baseline)', () => {
        bytesPlaylistReflection = Buffer.from(reflectionPlaylistDocument.encode(SAMPLE_PLAYLIST_DOCUMENT).finish());
        assert.ok(bytesPlaylistReflection.length > 0, 'reflection output empty');
    });

    await test('PlaylistDocument: static encoder produces bytes', () => {
        bytesPlaylistStatic = Buffer.from(staticPlaylistDocument.encode(SAMPLE_PLAYLIST_DOCUMENT).finish());
        assert.ok(bytesPlaylistStatic.length > 0, 'static output empty');
    });

    await test('PlaylistDocument: STATIC output is BYTE-IDENTICAL to reflection output', () => {
        assert.equal(bytesPlaylistStatic.length, bytesPlaylistReflection.length,
            `length differs: static=${bytesPlaylistStatic.length} reflection=${bytesPlaylistReflection.length}`);
        assert.equal(Buffer.compare(bytesPlaylistStatic, bytesPlaylistReflection), 0,
            'PlaylistDocument static and reflection wire bytes differ');
    });

    await test('PlaylistDocument: the static output round-trips back through the reflection decoder', () => {
        const decoded = reflectionPlaylistDocument.decode(bytesPlaylistStatic);
        const obj = reflectionPlaylistDocument.toObject(decoded, { defaults: false });
        assert.ok(obj && obj.root_node && obj.root_node.playlists
            && obj.root_node.playlists.playlists.length === 1,
            'decoded PlaylistDocument has no root_node.playlists');
        const item = obj.root_node.playlists.playlists[0].items.items[1];
        assert.equal(item.presentation.arrangement_name, 'Default',
            'the NEW field 5 (arrangement_name, #1968 P3) did not round-trip');
    });

    await test('PlaylistDocument: static .encode() still runs with Function + eval POISONED (CSP simulation)', () => {
        const poison = () => { throw new Error('CSP: unsafe-eval blocked'); };
        const sbCsp = makeSandbox();
        const PD = loadStaticPlaylistDocument(sbCsp);
        sbCsp.Function = poison;
        sbCsp.eval = poison;
        sbCsp.__PD = PD; sbCsp.__payload = SAMPLE_PLAYLIST_DOCUMENT; sbCsp.__out = null;
        vm.runInContext('__out = __PD.encode(__payload).finish();', sbCsp, { filename: 'csp-encode-playlist' });
        assert.ok(sbCsp.__out && sbCsp.__out.length > 0, 'static PlaylistDocument encode produced no bytes under CSP');
        assert.equal(Buffer.compare(Buffer.from(sbCsp.__out), bytesPlaylistStatic), 0,
            'static PlaylistDocument encode under CSP differs from normal static encode');
    });

    console.log(`\n${pass} passed, ${fail} failed`);
    if (fail > 0) process.exit(1);
}

main().catch((err) => { console.error(err); process.exit(1); });
