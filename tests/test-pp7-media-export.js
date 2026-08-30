/**
 * iHymns — ProPresenter MEDIA-EXPORT wire-shape guard (#1979)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE (the anti-circular core of #1979 — .claude/CLAUDE.md GIRFT rule):
 *   Prove the background-media action this exporter emits into a single-song
 *   `.probundle` matches the shape a REAL ProPresenter writes — never a
 *   self-consistent same-schema round-trip (the export bug shipped "green"
 *   twice that way). It does this in TWO halves that must AGREE:
 *
 *     (A) ANCHOR — decode the media action inside the genuine
 *         `bussnet-testbild.probundle` (a bundle exported FROM ProPresenter
 *         with its media travelling INSIDE the archive) with an INDEPENDENT
 *         protobufjs decode, and assert its portable shape:
 *           · action.type            = ACTION_TYPE_MEDIA
 *           · element.url.absolute_string = a BARE filename (no file://, no '/')
 *           · element.url.local.root = ROOT_CURRENT_RESOURCE  ("next to me")
 *           · the type-specific mirror (image|video .file.local_url) carries
 *             the SAME CURRENT_RESOURCE url  (PP needs URL.local on both).
 *         Why TestBild and not the owner's real video files: those reference
 *         media left IN PLACE on the authoring machine, so they use the
 *         machine-absolute roots ROOT_USER_HOME / ROOT_USER_DOWNLOADS with a
 *         full file:// path — non-portable. ROOT_CURRENT_RESOURCE is the only
 *         one of the three real roots that resolves on ANY machine opening the
 *         bundle, which is exactly why the deliberately-portable TestBild uses
 *         it — and it is what an embedded-at-root export must use.
 *
 *     (B) EXPORT — build this exporter's Presentation payload WITH a background
 *         image and a background video, encode → decode, and assert its media
 *         action satisfies the SAME predicates as the TestBild anchor, PLUS the
 *         "Lyrics Background" cue group is present in cue_groups but is NOT a
 *         step in the arrangement (a background is a palette group, never in the
 *         running order — verified against the owner's genuine v21.4 file).
 *
 *   If the anchor assertions ever fail, our understanding of the real shape
 *   drifted; if the export assertions fail, the exporter drifted from it.
 *   Either way the guard goes red — that is the mechanism (rule #35), not a
 *   "keep these in sync" comment.
 *
 * MUTATION-PROVEN (rule #34): each of these turns the guard red —
 *   · ROOT_CURRENT_RESOURCE → ROOT_SHOW in buildMediaCurrentResourceUrl()
 *   · dropping the video/image type-mirror (fileProps) from buildBackgroundMediaCue()
 *   · type: ACTION_TYPE_MEDIA → ACTION_TYPE_PRESENTATION_SLIDE
 *   · adding the bg group to the arrangement's group_identifiers
 *   · pickBackgroundMedia() preferring image over video
 *
 * USAGE:
 *   node tests/test-pp7-media-export.js
 *   npm test
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import zlib from 'node:zlib';
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
const FIXTURE_DIR = path.join(PROJECT_ROOT, 'tests', 'fixtures', 'propresenter');

const scriptSource = fs.readFileSync(SCRIPT_PATH, 'utf8');
const bundle = JSON.parse(fs.readFileSync(BUNDLE_PATH, 'utf8'));

/* Browser-global sandbox the exporter IIFE relies on (mirrors
   test-propresenter-export.js — the exporter's wire builders are pure and need
   only crypto/TextEncoder/Uint8Array; the download path is NOT exercised here). */
const sandbox = {
    window: {},
    protobuf: protobuf,
    crypto: globalThis.crypto,
    TextEncoder: globalThis.TextEncoder,
    TextDecoder: globalThis.TextDecoder,
    Uint8Array: globalThis.Uint8Array,
    Uint32Array: globalThis.Uint32Array,
    Math: Math, Object: Object, Error: Error, Array: Array, String: String,
    Number: Number, Boolean: Boolean, JSON: JSON, isNaN: isNaN, parseInt: parseInt,
    Date: Date, Promise: Promise, Buffer: Buffer, Blob: globalThis.Blob,
    URL: undefined, document: undefined, fetch: undefined, setTimeout: setTimeout
};
vm.createContext(sandbox);
vm.runInContext(scriptSource, sandbox, { filename: SCRIPT_PATH });
const exporter = sandbox.window.iHymnsProPresenter;
if (!exporter) { throw new Error('Module did not publish window.iHymnsProPresenter'); }

const decodeRoot = protobuf.Root.fromJSON(bundle);
const Presentation = decodeRoot.lookupType('rv.data.Presentation');

/* Independent, from-the-spec tolerant reader: walk ZIP LOCAL FILE HEADERS
   (PK\x03\x04) from byte 0, return { name, bytes } for each entry (inflating
   DEFLATE). Deliberately NOT reusing iHymns' own zip reader — an independent
   re-derivation so a shared bug can't hide a shape regression (same spirit as
   listZipEntryNames() in test-propresenter-export.js). */
function readZipEntries(buf) {
    const out = [];
    let pos = 0;
    while (pos + 30 <= buf.length && buf.readUInt32LE(pos) === 0x04034b50) {
        const method = buf.readUInt16LE(pos + 8);
        const compSize = buf.readUInt32LE(pos + 18);
        const nameLen = buf.readUInt16LE(pos + 26);
        const extraLen = buf.readUInt16LE(pos + 28);
        const name = buf.toString('utf8', pos + 30, pos + 30 + nameLen);
        const dataStart = pos + 30 + nameLen + extraLen;
        if (!compSize) { break; } /* streaming/ZIP64 sentinel — not needed for these small MIT bundles */
        let data = buf.subarray(dataStart, dataStart + compSize);
        if (method === 8) { data = zlib.inflateRawSync(data); }
        out.push({ name, bytes: data });
        pos = dataStart + compSize;
    }
    return out;
}

/* Decode a `.pro` byte set to a plain object with enum NAMES (legible +
   robust: we compare 'ROOT_CURRENT_RESOURCE'/'ACTION_TYPE_MEDIA' symbolically,
   not the numeric 12/2). */
function decodePro(bytes) {
    return Presentation.toObject(Presentation.decode(bytes), {
        enums: String, defaults: false, arrays: true
    });
}

/* Pull the FIRST media action out of a decoded presentation. */
function firstMediaAction(pres) {
    for (const c of (pres.cues || [])) {
        for (const a of (c.actions || [])) {
            if (a.media) { return a; }
        }
    }
    return null;
}

/* The portable-embedded-media predicates, asserted identically for the real
   TestBild anchor AND this exporter's output. `mirrorKind` = 'image'|'video'. */
function assertPortableMediaAction(action, mirrorKind, label) {
    assert.equal(action.type, 'ACTION_TYPE_MEDIA', label + ': action.type must be ACTION_TYPE_MEDIA');
    const el = action.media.element;
    assert.ok(el && el.url, label + ': media.element.url must be present');
    const url = el.url;
    assert.equal(url.local.root, 'ROOT_CURRENT_RESOURCE',
        label + ': element.url.local.root must be ROOT_CURRENT_RESOURCE (portable, resolves next to the bundle)');
    /* Bare filename: the absolute_string is the flat name, NOT a file:// URI. */
    assert.ok(!/^file:/i.test(url.absolute_string),
        label + ': element.url.absolute_string must be a bare filename, not a file:// URI');
    assert.ok(url.absolute_string.indexOf('/') === -1 && url.absolute_string.indexOf('\\') === -1,
        label + ': element.url.absolute_string must have no path separators');
    assert.equal(url.absolute_string, url.local.path,
        label + ': absolute_string and local.path must be the same bare filename');
    /* The type-specific mirror carries the SAME CURRENT_RESOURCE url. */
    assert.ok(el[mirrorKind] && el[mirrorKind].file && el[mirrorKind].file.local_url,
        label + ': element.' + mirrorKind + '.file.local_url must be present');
    const mirror = el[mirrorKind].file.local_url;
    assert.equal(mirror.local.root, 'ROOT_CURRENT_RESOURCE',
        label + ': ' + mirrorKind + '.file.local_url.local.root must be ROOT_CURRENT_RESOURCE');
    assert.deepEqual(
        { a: mirror.absolute_string, r: mirror.local.root, p: mirror.local.path },
        { a: url.absolute_string, r: url.local.root, p: url.local.path },
        label + ': the type-mirror local_url must equal element.url (PP needs URL.local on both)');
}

let pass = 0, fail = 0;
async function test(name, fn) {
    try { await fn(); console.log('  ✓ ' + name); pass++; }
    catch (e) { console.error('  ✗ ' + name + '\n    ' + (e && e.message)); fail++; }
}

(async function run() {
    await exporter.init({ protobuf: protobuf, bundle: bundle });

    /* ================================================================
     *  (A) ANCHOR — the real TestBild bundle's media action shape
     * ================================================================ */
    console.log('Anchor: real bussnet-testbild.probundle media action:');

    const testbildBuf = fs.readFileSync(path.join(FIXTURE_DIR, 'bussnet-testbild.probundle'));
    const entries = readZipEntries(testbildBuf);
    const proEntry = entries.find(e => e.name.toLowerCase().endsWith('.pro'));

    let anchorAction = null;
    await test('TestBild bundle contains an inner .pro', () => {
        assert.ok(proEntry, 'no .pro entry found in bussnet-testbild.probundle');
    });
    await test('the inner .pro carries a real ACTION_TYPE_MEDIA action', () => {
        anchorAction = firstMediaAction(decodePro(proEntry.bytes));
        assert.ok(anchorAction, 'no media action decoded from TestBild');
    });
    await test('the real media action is the portable ROOT_CURRENT_RESOURCE / bare-filename shape', () => {
        /* TestBild's background is an IMAGE (test-background.png) -> mirror lives
           under element.image.file.local_url. */
        assert.ok(anchorAction.media.element.image, 'TestBild media element expected to be an image');
        assertPortableMediaAction(anchorAction, 'image', 'TestBild');
    });

    /* ================================================================
     *  (B) EXPORT — this exporter's emitted media action matches it
     * ================================================================ */
    const SAMPLE_SONG = {
        id: 'CP-0001', number: 1, title: 'A baby was born in Bethlehem',
        songbook: 'CP', songbookName: 'Carol Praise',
        components: [
            { type: 'verse', number: 1, lines: ['Line one', 'Line two'] },
            { type: 'chorus', lines: ['Gloria, gloria'] }
        ]
    };

    console.log('\nExport: buildPresentationPayload({ backgroundMedia }) — image:');
    let imgDecoded = null;
    await test('payload with an image background passes Presentation.verify()', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {
            backgroundMedia: { filename: 'my-background.png', kind: 'image', ext: 'png' }
        });
        const problem = Presentation.verify(payload);
        assert.equal(problem, null, 'verify() reported: ' + problem);
        imgDecoded = decodePro(Presentation.encode(payload).finish());
    });
    await test('exported image media action matches the TestBild portable shape field-for-field', () => {
        const action = firstMediaAction(imgDecoded);
        assert.ok(action, 'exporter emitted no media action');
        assertPortableMediaAction(action, 'image', 'export(image)');
        assert.equal(action.media.element.url.absolute_string, 'my-background.png');
    });
    await test('the "Lyrics Background" group is present in cue_groups', () => {
        const names = (imgDecoded.cue_groups || []).map(g => g.group && g.group.name);
        assert.ok(names.includes('Lyrics Background'),
            'expected a "Lyrics Background" cue group, saw: ' + JSON.stringify(names));
    });
    await test('the "Lyrics Background" group is NOT a step in the arrangement', () => {
        const bgGroup = imgDecoded.cue_groups.find(g => g.group && g.group.name === 'Lyrics Background');
        const arr = imgDecoded.arrangements[0];
        assert.ok(!arr.group_identifiers.some(gid => gid.string === bgGroup.group.uuid.string),
            'the Lyrics Background group must not appear in the arrangement (backgrounds are palette-only)');
    });
    await test('every LYRIC group is still in the arrangement (unchanged by the media prepend)', () => {
        const arr = imgDecoded.arrangements[0];
        const lyricGroups = imgDecoded.cue_groups.filter(g => g.group.name !== 'Lyrics Background');
        assert.equal(arr.group_identifiers.length, lyricGroups.length,
            'arrangement should list exactly the lyric groups');
        for (const lg of lyricGroups) {
            assert.ok(arr.group_identifiers.some(gid => gid.string === lg.group.uuid.string),
                'lyric group "' + lg.group.name + '" missing from arrangement');
        }
    });
    await test('the media cue is PREPENDED (cues[0]) so it renders behind the first slide', () => {
        assert.ok(imgDecoded.cues[0].actions.some(a => a.media),
            'the first cue must be the media cue');
    });

    console.log('\nExport: buildPresentationPayload({ backgroundMedia }) — video:');
    let vidDecoded = null;
    await test('payload with a video background verifies + uses the VIDEO mirror', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {
            backgroundMedia: { filename: 'Music Notes.mp4', kind: 'video', ext: 'mp4' }
        });
        assert.equal(Presentation.verify(payload), null);
        vidDecoded = decodePro(Presentation.encode(payload).finish());
        const action = firstMediaAction(vidDecoded);
        assertPortableMediaAction(action, 'video', 'export(video)');
        assert.ok(!action.media.element.image, 'a video background must NOT carry an image mirror');
        assert.equal(action.media.element.url.absolute_string, 'Music Notes.mp4');
    });
    await test('the media metadata.format token is carried (element.metadata.format)', () => {
        const action = firstMediaAction(vidDecoded);
        assert.equal(action.media.element.metadata.format, 'mp4');
    });

    /* A song with NO background media produces NO media action (bare .pro path). */
    await test('a song with no background media yields no media action', () => {
        const payload = exporter._internal.buildPresentationPayload(SAMPLE_SONG, {});
        const decoded = decodePro(Presentation.encode(payload).finish());
        assert.equal(firstMediaAction(decoded), null, 'no media provided -> no media action');
        const names = (decoded.cue_groups || []).map(g => g.group.name);
        assert.ok(!names.includes('Lyrics Background'), 'no media -> no Lyrics Background group');
    });

    /* ================================================================
     *  (C) pure media helpers
     * ================================================================ */
    console.log('\npickBackgroundMedia() selection rules:');
    const P = exporter._internal.pickBackgroundMedia;
    await test('prefers the first VIDEO over any image', () => {
        const bg = P({ media: [
            { kind: 'image', fileName: 'a.png', mimeType: 'image/png', streamUrl: '/song-media/1' },
            { kind: 'video', fileName: 'b.mp4', mimeType: 'video/mp4', streamUrl: '/song-media/2' }
        ]});
        assert.equal(bg.kind, 'video');
        assert.equal(bg.filename, 'b.mp4');
        assert.equal(bg.ext, 'mp4');
    });
    await test('falls back to the first IMAGE when there is no video', () => {
        const bg = P({ media: [
            { kind: 'image', fileName: 'a.png', mimeType: 'image/png', streamUrl: '/song-media/1' }
        ]});
        assert.equal(bg.kind, 'image');
        assert.equal(bg.filename, 'a.png');
    });
    await test('ignores audio / pdf / sheet-music (never a background)', () => {
        const bg = P({ media: [
            { kind: 'audio', fileName: 'a.mp3', mimeType: 'audio/mpeg', streamUrl: '/song-media/1' },
            { kind: 'sheet-music', fileName: 's.pdf', mimeType: 'application/pdf', streamUrl: '/song-media/2' }
        ]});
        assert.equal(bg, null);
    });
    await test('skips a media row with no streamUrl (an admin-only row filtered from the public read)', () => {
        const bg = P({ media: [ { kind: 'video', fileName: 'x.mp4', mimeType: 'video/mp4' } ] });
        assert.equal(bg, null);
    });
    await test('returns null for a song with no media at all', () => {
        assert.equal(P({}), null);
        assert.equal(P({ media: [] }), null);
    });

    console.log('\nsanitizeMediaFilename() / mediaFormatToken():');
    const S = exporter._internal.sanitizeMediaFilename;
    const F = exporter._internal.mediaFormatToken;
    await test('sanitizeMediaFilename keeps the extension but drops path + unsafe chars', () => {
        assert.equal(S('/Users/x/My Video.mp4'), 'My Video.mp4');
        assert.equal(S('a\\b\\c.png'), 'c.png');
        assert.equal(S('bad:name?.mov'), 'badname.mov');
        assert.equal(S(''), 'media');
        assert.equal(S(null), 'media');
    });
    await test('mediaFormatToken uses the filename extension, else the MIME fallback', () => {
        assert.equal(F('clip.MP4', 'video/quicktime'), 'mp4');   /* filename wins, lower-cased */
        assert.equal(F('noext', 'video/quicktime'), 'mov');       /* MIME fallback */
        assert.equal(F('noext', 'image/png'), 'png');
        assert.equal(F('noext', 'application/octet-stream'), ''); /* unknown -> empty */
    });

    console.log('\n' + (fail === 0
        ? 'All ' + pass + ' media-export checks passed.'
        : (fail + ' of ' + (pass + fail) + ' checks FAILED.')));
    if (fail > 0) { process.exit(1); }
})();
