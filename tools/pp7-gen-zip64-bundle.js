#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-zip64-bundle.js — synthesised broken-EOCD `.probundle` fixture generator (#1968 P2)
 * =====================================================================================================
 *
 * ELI5
 * ----
 * Real ProPresenter 7 exports write `.probundle` ZIPs with a specific, consistent bug: the
 * "how big is the file index at the end?" number they write is always **98 bytes too large**
 * (confirmed independently: the owner's own real ~2 MB export was proven in-session against this
 * exact defect, and `bussnet/propresenter7-php-lib`'s `Zip64Fixer` — a completely separate,
 * independently-written PHP library — documents recalculating and patching the identical 98-byte
 * discrepancy: `doc/internal/learnings.md` "central-directory size consistently 98 bytes too
 * large"). That one wrong number is enough to make `unzip`, Python's `zipfile`, and PHP's
 * `\ZipArchive` all refuse to open the file outright — which is the whole reason
 * `includes/propresenter7_zip.php` exists: it never reads that index at all.
 *
 * The two REAL `.probundle` fixtures already committed here
 * (`bussnet-testbild.probundle`/`bussnet-export-from-pp.probundle`) are small enough that they
 * never actually trip this bug (see `includes/propresenter7_zip.php`'s file-level doc-block) — so
 * this script builds a THIRD, deliberately-broken fixture from copyright-safe, ALREADY-COMMITTED
 * parts, byte-for-byte reproducing the real defect, so CI has a fixture that actually forces the
 * tolerant-reader code path real production files need.
 *
 * DETAILED — the exact defect this reproduces
 * ---------------------------------------------
 * A ZIP64 archive's real end-of-file index is three records, written back-to-back:
 *   1. the ZIP64 "end of central directory" RECORD (`PK\x06\x06`) — carries, among other things,
 *      an 8-byte "size of the central directory" field at byte offset +40 from its own signature,
 *      and an 8-byte "offset of start of central directory" field at +48;
 *   2. the ZIP64 EOCD LOCATOR (`PK\x06\x07`) — just points at where record #1 starts;
 *   3. the classic (32-bit) EOCD (`PK\x05\x06`) — carries its OWN mirrored 4-byte "size of the
 *      central directory" field at +12 and "offset" field at +16, present for pre-ZIP64 reader
 *      compatibility.
 * A spec-clean writer sets every one of those "size"/"offset" fields to the SAME true values.
 * ProPresenter's writer instead computes the central directory's size wrong in BOTH copies (the
 * ZIP64 record's +40 field AND the classic EOCD's +12 mirror), consistently overstating it by
 * exactly 98 bytes, while leaving the "offset of start" fields (+48 / +16) correct. This script's
 * `writeZip64Eocd()`/`writeClassicEocd()` reproduce EXACTLY that: correct offsets, `size + 98`.
 *
 * That single wrong number is what makes strict readers reject the file: they cross-check that
 * the declared central-directory size is internally consistent with where the directory actually
 * sits, and a 98-byte overstatement fails that check. Empirically verified during this task
 * (see `tests/php/test-pp7-zip.php`'s doc-block for the exact recorded results): PHP's
 * `\ZipArchive::open()` returns `ZIPARCHIVE_ER_INCONS` (21) — the SAME code the coordinator
 * observed opening the owner's real bundle; Python's `zipfile` raises
 * `BadZipFile('Corrupt zip64 end of central directory record')` — the SAME message quoted in
 * `.claude/propresenter-interop-1968-plan.md` §4.1; and `unzip -l` prints "missing 98 bytes in
 * zipfile" / "reported length of central directory is 98 bytes too long … Compensating…" — the
 * SAME wording `doc/internal/learnings.md` describes for genuine ProPresenter output. Three
 * independent tools, three independently-worded confirmations of the identical byte-level defect.
 *
 * WHAT GOES INTO THE ARCHIVE (copyright-safe, already-committed parts only)
 * -----------------------------------------------------------------------------
 * - The `.pro` entry is the REAL BYTES of the already-committed, already-triaged-safe
 *   `tests/fixtures/propresenter/bussnet-test.pro` (placeholder "Titel"/"Autor" metadata, no live
 *   copyright — see `tests/fixtures/propresenter/README.md`), read verbatim and stored (method 0,
 *   uncompressed) — matching real PP7 export behaviour, which per `pp_bundle_spec.md` §2 always
 *   uses STORED compression, unlike the two smaller committed bundles (which happen to be
 *   DEFLATE — see `includes/propresenter7_zip.php`'s doc-block for that separate finding).
 * - The media entry is a small SYNTHETIC placeholder (not a real image — nothing in this codebase
 *   decodes bundle media bytes yet, so its content is irrelevant; only its ZIP-entry shape
 *   matters), named with a real-PP7-export-shaped ABSOLUTE path
 *   (`/Users/curator/Downloads/pp-test/Media/dummy.png`), mirroring
 *   `doc/formats/pp_bundle_spec.md` §2's documented "PP7 Export (Absolute Paths)" example
 *   (`/Users/me/Downloads/pp-test/Media/background.png`) as closely as possible without using any
 *   third-party path string verbatim.
 * - Entry order is media-then-`.pro` (`pp_bundle_spec.md` §2: "Media files first, then the `.pro`
 *   file last — ProPresenter does not enforce order, but this matches PP7 export behavior").
 * - BOTH entries use STORED + the ZIP64 `0xFFFFFFFF` size sentinel + a genuine ZIP64 extra field
 *   (header id `0x0001`, order: uncompressed size then compressed size — same as
 *   `includes/propresenter7_zip.php`'s `_pp7ZipParseZip64Extra()` expects), in the local file
 *   header AND its central-directory mirror, matching real PP7 export shape end to end.
 *
 * Usage:
 *   node tools/pp7-gen-zip64-bundle.js [output-path.probundle]
 *   (defaults to tests/fixtures/propresenter/synthetic-zip64.probundle)
 *
 * Exit status: 0 = bytes written, 1 = any failure (missing source fixture, write error).
 *
 * @see appWeb/public_html/includes/propresenter7_zip.php   the tolerant reader this fixture proves
 * @see tests/php/test-pp7-zip.php                          the consumer of this script's output
 * @see tests/fixtures/propresenter/bussnet-test.pro         the real, copyright-safe inner .pro source
 * @see tests/fixtures/propresenter/README.md                fixture provenance + why this one is
 *      SYNTHESISED (not third-party) and why it exists
 * @see .claude/propresenter-interop-1968-plan.md            §4.1 (the broken-EOCD format facts)
 * @see https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT   §4.3.14 (ZIP64 EOCD record),
 *      §4.3.15 (ZIP64 EOCD locator), §4.3.16 (EOCD record) — the three structures this forges
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..');

const FIXTURES_DIR = path.join(REPO_ROOT, 'tests', 'fixtures', 'propresenter');
const SOURCE_PRO_PATH = path.join(FIXTURES_DIR, 'bussnet-test.pro');
const DEFAULT_OUT_PATH = path.join(FIXTURES_DIR, 'synthetic-zip64.probundle');

/** The real, documented, byte-verified magnitude of ProPresenter's central-directory-size bug
 *  (see this file's own doc-block for the three independent confirmations). */
const REAL_PP7_EOCD_SIZE_BUG_BYTES = 98;

const ZIP64_SENTINEL = 0xFFFFFFFF;
const LOCAL_FILE_HEADER_SIG = 'PK\x03\x04';
const CENTRAL_DIR_HEADER_SIG = 'PK\x01\x02';
const ZIP64_EOCD_RECORD_SIG = 'PK\x06\x06';
const ZIP64_EOCD_LOCATOR_SIG = 'PK\x06\x07';
const EOCD_SIG = 'PK\x05\x06';

/** ProPresenter-export-shaped absolute media path (mirrors, without copying verbatim,
 *  `doc/formats/pp_bundle_spec.md` §2's documented `/Users/me/Downloads/pp-test/Media/…`
 *  PP7-export example). */
const MEDIA_ENTRY_NAME = '/Users/curator/Downloads/pp-test/Media/dummy.png';
const PRO_ENTRY_NAME = 'Test.pro';

function u16le(n) {
    const b = Buffer.alloc(2);
    b.writeUInt16LE(n & 0xFFFF, 0);
    return b;
}
function u32le(n) {
    const b = Buffer.alloc(4);
    b.writeUInt32LE(n >>> 0, 0);
    return b;
}
function u64le(n) {
    const b = Buffer.alloc(8);
    b.writeBigUInt64LE(BigInt(n), 0);
    return b;
}
function sig(s) {
    return Buffer.from(s, 'binary');
}

/**
 * ZIP64 extended-information extra field (APPNOTE §4.5.3), carrying the TRUE uncompressed and
 * compressed sizes for an entry whose fixed-header size fields are both the `0xFFFFFFFF`
 * sentinel — mirrors `includes/propresenter7_zip.php`'s `_pp7ZipParseZip64Extra()` read order
 * exactly (uncompressed size, then compressed size).
 */
function buildZip64Extra(usize, csize) {
    const body = Buffer.concat([u64le(usize), u64le(csize)]);
    return Buffer.concat([u16le(0x0001), u16le(body.length), body]);
}

/**
 * One local file header (APPNOTE §4.3.7) for a STORED entry, sizes forced to the ZIP64 sentinel
 * with the real sizes carried in its ZIP64 extra field — the real-PP7-export shape.
 * @returns {{header: Buffer, crc: number, usize: number, csize: number}}
 */
function buildLocalHeader(name, content) {
    const nameBuf = Buffer.from(name, 'utf8');
    const usize = content.length;
    const csize = content.length; // STORED: compressed size === uncompressed size
    const crc = zlib.crc32(content);
    const extra = buildZip64Extra(usize, csize);
    const fixed = Buffer.concat([
        u16le(45),               // version needed to extract (4.5 = ZIP64 support required)
        u16le(0),                 // general purpose bit flag
        u16le(0),                 // compression method: STORED
        u16le(0),                 // last mod file time
        u16le(0),                 // last mod file date
        u32le(crc),
        u32le(ZIP64_SENTINEL),    // compressed size -> ZIP64 sentinel
        u32le(ZIP64_SENTINEL),    // uncompressed size -> ZIP64 sentinel
        u16le(nameBuf.length),
        u16le(extra.length),
    ]);
    const header = Buffer.concat([sig(LOCAL_FILE_HEADER_SIG), fixed, nameBuf, extra, content]);
    return { header, crc, usize, csize };
}

/**
 * One central-directory file header (APPNOTE §4.3.12) mirroring a local file header — same
 * ZIP64-sentinel-plus-extra-field shape; `localHeaderOffset` is written as a REAL (non-sentinel)
 * 32-bit value since this fixture is far under 4 GiB (matches the real writer's observed
 * behaviour of only sentineling fields that genuinely need it).
 */
function buildCdHeader(name, crc, usize, csize, localHeaderOffset) {
    const nameBuf = Buffer.from(name, 'utf8');
    const extra = buildZip64Extra(usize, csize);
    const fixed = Buffer.concat([
        u16le(45),                     // version made by (host byte 0 = MS-DOS-compatible, spec 4.5)
        u16le(45),                     // version needed to extract
        u16le(0),                      // general purpose bit flag
        u16le(0),                      // compression method: STORED
        u16le(0),                      // last mod file time
        u16le(0),                      // last mod file date
        u32le(crc),
        u32le(ZIP64_SENTINEL),         // compressed size -> ZIP64 sentinel
        u32le(ZIP64_SENTINEL),         // uncompressed size -> ZIP64 sentinel
        u16le(nameBuf.length),
        u16le(extra.length),
        u16le(0),                       // file comment length
        u16le(0),                       // disk number start
        u16le(0),                       // internal file attributes
        u32le(0),                       // external file attributes
        u32le(localHeaderOffset),       // relative offset of local header (real value; fits in 32 bits)
    ]);
    return Buffer.concat([sig(CENTRAL_DIR_HEADER_SIG), fixed, nameBuf, extra]);
}

/**
 * The ZIP64 end-of-central-directory record (APPNOTE §4.3.14), with the "size of the central
 * directory" field DELIBERATELY overstated by `REAL_PP7_EOCD_SIZE_BUG_BYTES` — this is the exact
 * defect real ProPresenter exports carry (see this file's doc-block). The "offset of start of
 * central directory" field is correct, matching the real writer's observed behaviour of only the
 * SIZE field being wrong.
 */
function writeZip64Eocd(entryCount, correctCdSize, cdStartOffset) {
    const wrongCdSize = correctCdSize + REAL_PP7_EOCD_SIZE_BUG_BYTES;
    const fixed = Buffer.concat([
        u64le(44),                 // size of this record's remainder (fixed 56-byte record - 12)
        u16le(45),                 // version made by
        u16le(45),                 // version needed to extract
        u32le(0),                  // number of this disk
        u32le(0),                  // disk where the central directory starts
        u64le(entryCount),         // total entries in the central directory on this disk
        u64le(entryCount),         // total entries in the central directory
        u64le(wrongCdSize),        // size of the central directory -- DELIBERATELY WRONG (+98)
        u64le(cdStartOffset),      // offset of start of central directory (correct)
    ]);
    return { buf: Buffer.concat([sig(ZIP64_EOCD_RECORD_SIG), fixed]), wrongCdSize };
}

/** The ZIP64 EOCD locator (APPNOTE §4.3.15) — just points at the record above. */
function writeZip64Locator(zip64EocdOffset) {
    const fixed = Buffer.concat([
        u32le(0),                   // number of the disk with the start of the zip64 EOCD
        u64le(zip64EocdOffset),     // relative offset of the zip64 EOCD record
        u32le(1),                   // total number of disks
    ]);
    return Buffer.concat([sig(ZIP64_EOCD_LOCATOR_SIG), fixed]);
}

/**
 * The classic 32-bit end-of-central-directory record (APPNOTE §4.3.16), carrying the SAME
 * deliberately-wrong central-directory size as its mirrored 4-byte field — the second half of
 * the real defect (`Zip64Fixer.php` patches both `+40` in the ZIP64 record AND `+12` here).
 */
function writeClassicEocd(entryCount, wrongCdSize64, cdStartOffset) {
    const wrongCdSize32 = wrongCdSize64 > 0xFFFFFFFF ? 0xFFFFFFFF : Number(wrongCdSize64);
    const fixed = Buffer.concat([
        u16le(0),                 // number of this disk
        u16le(0),                 // disk where the central directory starts
        u16le(entryCount),        // central directory records on this disk
        u16le(entryCount),        // total central directory records
        u32le(wrongCdSize32),     // size of the central directory -- DELIBERATELY WRONG (+98, mirrored)
        u32le(cdStartOffset),     // offset of start of central directory (correct)
        u16le(0),                  // comment length
    ]);
    return Buffer.concat([sig(EOCD_SIG), fixed]);
}

function buildSyntheticZip64Bundle(proBytes, mediaBytes) {
    const chunks = [];
    let pos = 0;
    const push = (buf) => {
        chunks.push(buf);
        pos += buf.length;
    };

    // Entry order: media first, then .pro last (pp_bundle_spec.md §2 — matches real PP7 export).
    const mediaLocalOffset = pos;
    const media = buildLocalHeader(MEDIA_ENTRY_NAME, mediaBytes);
    push(media.header);

    const proLocalOffset = pos;
    const pro = buildLocalHeader(PRO_ENTRY_NAME, proBytes);
    push(pro.header);

    const cdStart = pos;
    push(buildCdHeader(MEDIA_ENTRY_NAME, media.crc, media.usize, media.csize, mediaLocalOffset));
    push(buildCdHeader(PRO_ENTRY_NAME, pro.crc, pro.usize, pro.csize, proLocalOffset));
    const correctCdSize = pos - cdStart;

    const zip64EocdOffset = pos;
    const { buf: zip64EocdBuf, wrongCdSize } = writeZip64Eocd(2, correctCdSize, cdStart);
    push(zip64EocdBuf);

    push(writeZip64Locator(zip64EocdOffset));
    push(writeClassicEocd(2, wrongCdSize, cdStart));

    return Buffer.concat(chunks);
}

function main() {
    const outPath = process.argv[2] || DEFAULT_OUT_PATH;

    if (!fs.existsSync(SOURCE_PRO_PATH)) {
        console.error(`missing source fixture: ${SOURCE_PRO_PATH}`);
        process.exit(1);
    }
    const proBytes = fs.readFileSync(SOURCE_PRO_PATH);

    // Synthetic placeholder media — content is irrelevant (nothing decodes bundle media bytes
    // yet), only its ZIP-entry shape matters. Deliberately NOT a real image; the name says so.
    const mediaBytes = Buffer.from(
        'SYNTHETIC PLACEHOLDER MEDIA BYTES — not a real image, content is irrelevant to this fixture\'s purpose. '.repeat(2),
        'utf8'
    );

    const bundle = buildSyntheticZip64Bundle(proBytes, mediaBytes);
    fs.writeFileSync(outPath, bundle);
    console.log(`wrote ${bundle.length} byte(s) to ${outPath}`);
    console.log(`  entries: ${MEDIA_ENTRY_NAME} (${mediaBytes.length}B), ${PRO_ENTRY_NAME} (${proBytes.length}B)`);
    console.log(`  central-directory size overstated by ${REAL_PP7_EOCD_SIZE_BUG_BYTES} bytes in both the ZIP64 EOCD record and the classic EOCD mirror`);
}

main();
