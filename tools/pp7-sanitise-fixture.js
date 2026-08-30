#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-sanitise-fixture.js — lyric-sanitising derivative generator for ProPresenter fixtures
 * ================================================================================================
 *
 * ELI5
 * ----
 * The owner has genuine ProPresenter 7+ (v21.4) `.pro`/`.probundle` files that carry v21.4
 * schema/vocabulary/media-reference coverage the small MIT fixtures don't. We WANT that coverage
 * in the committed test corpus — but those files also carry copyrighted worship lyrics we may not
 * redistribute. So this tool takes an original and writes a DERIVATIVE with the visible lyric
 * text replaced by "Sanitised line N", while keeping EVERYTHING ELSE byte-faithful: the RTF
 * dialect header (`\cocoartf`/`\rtf0`, fonttbl, colortbl, Cocoa `\`+newline soft returns), the
 * arrangements, cue-group names ("Verse 1 (SDAH)", "Tag"), CCLI metadata, and the media
 * references. The result is safe to commit and still exercises the real code paths.
 *
 * The owner chose this (#1968 P4 decision D3, option b — plan §6.6): a committed tool so the
 * fixtures are REPRODUCIBLE from the originals, which live in `_temp/` on alpha and are NOT
 * committed.
 *
 * DETAILED
 * --------
 * Two modes, dispatched by extension:
 *
 *   • `.pro`  — decode `rv.data.Presentation` with protobufjs reflection (the same independent
 *               decoder `tools/pp7-gen-expected.js` uses, driven off the vendored
 *               `proto-bundle.json`), rewrite every text element's `rtf_data`, re-encode.
 *   • `.probundle` — read the container with a tolerant local-file-header scanner (the owner's
 *               real bundles are ZIP64 with a broken EOCD that `unzip`/`yauzl` reject — the exact
 *               reason `includes/propresenter7_zip.php` exists, plan §4), keep every entry NAME
 *               byte-identical (the absolute-path media entry name IS the resolution coverage),
 *               sanitise the inner `.pro`(s), and replace each MEDIA entry's bytes with the
 *               committed `tests/fixtures/propresenter/assets/tiny.mp4` (~20 KB, a real
 *               finfo-sniffable single-black-frame MP4). Re-emit a CLEAN STORED zip.
 *
 * THE RTF REWRITE is the delicate part. It must replace ONLY the visible lyric runs and leave the
 * dialect header + control words + soft returns untouched, or the fixtures stop exercising the
 * dialect handling they exist to cover. So this file ports the tokeniser control-flow of
 * `_bulkImport_rtfToText()` (song_importers.php) — the SAME notion of "what is visible text"
 * (skips fonttbl/colortbl/stylesheet/info/pict/object/themedata/datastore and `\*` destinations;
 * treats soft-return / `\par` / `\line` / U+2028 as line breaks) — but instead of BUILDING the
 * extracted string it RECORDS the byte span of every emitted visible character, groups maximal
 * contiguous spans into runs, and splices `Sanitised line N` over each run from the end of the
 * buffer backwards so earlier offsets never shift. Control words, escapes and soft returns between
 * runs create byte gaps that end a run, so they are preserved verbatim. Keeping the two in lockstep
 * (extractor's "visible text" ↔ this rewriter's "visible text") is why the fixtures then decode to
 * a DETERMINISTIC "Sanitised line 1\nSanitised line 2\n…" through the real importer.
 *
 * HONEST LIMITATION (stated here and in the fixtures' README): a re-encoded `.pro`'s protobuf
 * FIELD ORDERING is protobufjs's, not ProPresenter's own writer's. These derivatives therefore
 * carry v21.4 SCHEMA + VOCABULARY + MEDIA-REF coverage; raw-PP-writer byte realism (including the
 * broken-EOCD ZIP64 quirk) stays covered by the UNTOUCHED MIT fixtures
 * (`bussnet-export-from-pp.probundle`, `bussnet-testbild.probundle`, the synthetic-zip64 fixture).
 *
 * PRIVACY NOTE: the media references and (for `.probundle`) the media entry NAME retain the
 * owner's real absolute paths (their macOS username / church library path). That is deliberate —
 * the resolution logic keys on the url-decoded basename + a longest-suffix match of `local.path`,
 * so the real path shape IS the coverage (plan §6.4). D3 sanitises LYRICS (the copyright concern),
 * not directory names, and the fixtures live in the owner's own private repository.
 *
 * Usage:
 *   node tools/pp7-sanitise-fixture.js --in <path> --out <path> [--media <tiny.mp4>]
 *   node tools/pp7-sanitise-fixture.js --all      # regenerate the committed P4 fixtures from _temp/
 *
 * @see .claude/propresenter-interop-1968-plan.md   §6.6 (D3 fixtures), §2.1 (decoder contract)
 * @see appWeb/public_html/includes/song_importers.php  _bulkImport_rtfToText() — the extractor this mirrors
 * @see appWeb/public_html/includes/propresenter7_zip.php  the tolerant reader this scanner mirrors
 * @see tools/pp7-gen-expected.js                    the protobufjs-reflection sibling generator
 * @see https://www.biblioscape.com/rtf15_spec.htm   RTF 1.5 control words (soft return = escaped EOL)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..');
const PROTO_BUNDLE = path.join(
  REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

// The independent decoder — protobufjs reflection over the vendored schema, in plain Node (the
// lazy codegen the browser CSP refuses, #1788, is no obstacle here). Same rig as pp7-gen-expected.js.
const root = protobuf.Root.fromJSON(JSON.parse(fs.readFileSync(PROTO_BUNDLE, 'utf8')));
const Presentation = root.lookupType('rv.data.Presentation');

/* ────────────────────────────── CRC-32 (for the clean output zip) ────────────────────────────── */

// Standard IEEE 802.3 CRC-32 table + accumulator — ZIP local/central headers require a CRC-32 of
// each stored entry. Hand-rolled (rather than depending on Node's version-gated zlib.crc32) so the
// tool runs on any Node ≥18. https://en.wikipedia.org/wiki/Cyclic_redundancy_check
const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) { c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1); }
    t[n] = c >>> 0;
  }
  return t;
})();
function crc32(buf) {
  let c = 0xFFFFFFFF;
  for (let i = 0; i < buf.length; i++) { c = CRC_TABLE[(c ^ buf[i]) & 0xFF] ^ (c >>> 8); }
  return (c ^ 0xFFFFFFFF) >>> 0;
}

/* ─────────────────────────────── tolerant ZIP reader (mirrors §4) ─────────────────────────────── */

/**
 * Scan a ZIP buffer's LOCAL file headers (PK\x03\x04), resolving 0xFFFFFFFF ZIP64 size sentinels
 * from the extra field (id 0x0001) — never reading the (in real bundles, broken) EOCD/central
 * directory. Mirrors includes/propresenter7_zip.php. Returns [{ name, method, data:Buffer }].
 */
function scanLocalEntries(b) {
  const entries = [];
  let i = 0;
  while (i + 4 <= b.length) {
    const sig = b.readUInt32LE(i);
    if (sig === 0x04034b50) {
      const gp = b.readUInt16LE(i + 6);
      const method = b.readUInt16LE(i + 8);
      let csize = b.readUInt32LE(i + 18);
      let usize = b.readUInt32LE(i + 22);
      const fnlen = b.readUInt16LE(i + 26);
      const eflen = b.readUInt16LE(i + 28);
      const name = b.slice(i + 30, i + 30 + fnlen).toString('utf8');
      // Resolve ZIP64 sentinels from the extra field (id 0x0001: uncompressed then compressed).
      let ep = i + 30 + fnlen;
      const epEnd = ep + eflen;
      while (ep + 4 <= epEnd) {
        const hid = b.readUInt16LE(ep);
        const hsz = b.readUInt16LE(ep + 2);
        let p = ep + 4;
        if (hid === 0x0001) {
          if (usize === 0xffffffff) { usize = Number(b.readBigUInt64LE(p)); p += 8; }
          if (csize === 0xffffffff) { csize = Number(b.readBigUInt64LE(p)); p += 8; }
        }
        ep += 4 + hsz;
      }
      if (gp & 0x08) {
        // Streaming/data-descriptor bundles (sizes after the data) aren't produced by PP here;
        // refuse rather than guess — the tool operates only on the real, header-sized bundles.
        throw new Error(`entry ${JSON.stringify(name)} uses a data descriptor (unsupported by this tool)`);
      }
      const dataStart = i + 30 + fnlen + eflen;
      let data = b.slice(dataStart, dataStart + csize);
      if (method === 8) { data = zlib.inflateRawSync(data); }
      else if (method !== 0) { throw new Error(`entry ${JSON.stringify(name)} uses compression method ${method}`); }
      entries.push({ name, method, data });
      i = dataStart + csize;
      continue;
    }
    if (sig === 0x02014b50) { break; } // reached the central directory — done with local headers
    i++;
  }
  return entries;
}

/* ─────────────────────────────── clean STORED zip writer ─────────────────────────────── */

/**
 * Write a plain (32-bit) STORED zip from [{ name, data:Buffer }]. Entry names are preserved
 * byte-for-byte (UTF-8). Sizes here are always < 4 GiB (a sanitised inner .pro + tiny.mp4), so no
 * ZIP64 is needed — the output is a "clean" zip the tolerant reader and any standard unzip both
 * accept, deliberately NOT reproducing the broken-EOCD quirk (that realism stays with the MIT
 * fixtures). https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT
 */
function writeStoredZip(entries) {
  const locals = [];
  const centrals = [];
  let offset = 0;
  for (const e of entries) {
    const nameBuf = Buffer.from(e.name, 'utf8');
    const crc = crc32(e.data);
    const size = e.data.length;
    const nonAscii = nameBuf.some((byte) => byte > 0x7f);
    const flags = nonAscii ? 0x0800 : 0x0000; // bit 11 = UTF-8 filename

    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0);
    lh.writeUInt16LE(20, 4);          // version needed
    lh.writeUInt16LE(flags, 6);
    lh.writeUInt16LE(0, 8);           // method 0 = stored
    lh.writeUInt16LE(0, 10);          // mod time
    lh.writeUInt16LE(0x21, 12);       // mod date (1980-01-01)
    lh.writeUInt32LE(crc, 14);
    lh.writeUInt32LE(size, 18);       // compressed
    lh.writeUInt32LE(size, 22);       // uncompressed
    lh.writeUInt16LE(nameBuf.length, 26);
    lh.writeUInt16LE(0, 28);          // extra len
    locals.push(lh, nameBuf, e.data);

    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0);
    ch.writeUInt16LE(0x031e, 4);      // version made by (3.0, unix)
    ch.writeUInt16LE(20, 6);          // version needed
    ch.writeUInt16LE(flags, 8);
    ch.writeUInt16LE(0, 10);          // method
    ch.writeUInt16LE(0, 12);          // time
    ch.writeUInt16LE(0x21, 14);       // date
    ch.writeUInt32LE(crc, 16);
    ch.writeUInt32LE(size, 20);
    ch.writeUInt32LE(size, 24);
    ch.writeUInt16LE(nameBuf.length, 28);
    ch.writeUInt16LE(0, 30);          // extra len
    ch.writeUInt16LE(0, 32);          // comment len
    ch.writeUInt16LE(0, 34);          // disk #
    ch.writeUInt16LE(0, 36);          // internal attrs
    ch.writeUInt32LE(0, 38);          // external attrs
    ch.writeUInt32LE(offset, 42);     // local header offset
    centrals.push(ch, nameBuf);

    offset += lh.length + nameBuf.length + e.data.length;
  }

  const localBuf = Buffer.concat(locals);
  const centralBuf = Buffer.concat(centrals);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(0, 4);                        // disk #
  eocd.writeUInt16LE(0, 6);                        // cd start disk
  eocd.writeUInt16LE(entries.length, 8);           // entries this disk
  eocd.writeUInt16LE(entries.length, 10);          // total entries
  eocd.writeUInt32LE(centralBuf.length, 12);       // cd size
  eocd.writeUInt32LE(localBuf.length, 16);         // cd offset
  eocd.writeUInt16LE(0, 20);                       // comment len
  return Buffer.concat([localBuf, centralBuf, eocd]);
}

/* ─────────────────────────────── the RTF lyric rewriter ─────────────────────────────── */

const ALPHA = (ch) => (ch >= 0x41 && ch <= 0x5a) || (ch >= 0x61 && ch <= 0x7a);
const DIGIT = (ch) => ch >= 0x30 && ch <= 0x39;
const DEST_WORDS = new Set(['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'themedata', 'datastore']);

/**
 * Rewrite the visible lyric runs in one RTF payload (Buffer) → new Buffer. Ports the control-flow
 * of `_bulkImport_rtfToText()` to identify emitted-visible-character byte spans, then splices
 * `Sanitised line N` over each maximal contiguous run. Everything else (header, control words,
 * soft returns, group braces, destinations) is preserved byte-for-byte.
 */
function sanitiseRtfBuffer(rtf) {
  const n = rtf.length;
  let i = 0;
  let depth = 0;
  const ucStack = [1];
  let unicodeSkip = 0;
  let skipUntilDepth = -1;

  // Ordered events: {t:'char', s, e} for an emitted visible char's byte span, or {t:'break'}.
  const events = [];
  const emit = (s, e) => { events.push({ t: 'char', s, e }); };
  const brk = () => { events.push({ t: 'break' }); };

  while (i < n) {
    const c = rtf[i];
    if (c === 0x5c /* \ */) {
      const next = i + 1 < n ? rtf[i + 1] : 0;
      // Escaped literal brace / backslash.
      if (next === 0x5c || next === 0x7b || next === 0x7d) {
        if (skipUntilDepth < 0) {
          if (unicodeSkip > 0) { unicodeSkip--; } else { emit(i, i + 2); }
        }
        i += 2; continue;
      }
      // Cocoa soft return: backslash + CR and/or LF → a line break.
      if (next === 0x0d || next === 0x0a) {
        let j = i + 2;
        if (next === 0x0d && j < n && rtf[j] === 0x0a) { j++; }
        if (skipUntilDepth < 0) { if (unicodeSkip > 0) { unicodeSkip--; } else { brk(); } }
        i = j; continue;
      }
      // \'XX — one hex byte.
      if (next === 0x27 /* ' */) {
        if (skipUntilDepth < 0) { if (unicodeSkip > 0) { unicodeSkip--; } else { emit(i, i + 4); } }
        i += 4; continue;
      }
      // Control word: \word, optional signed number, optional single trailing space.
      if (ALPHA(next)) {
        let j = i + 1;
        while (j < n && ALPHA(rtf[j])) { j++; }
        const word = rtf.slice(i + 1, j).toString('latin1');
        let numStr = '';
        if (j < n && (rtf[j] === 0x2d /* - */ || DIGIT(rtf[j]))) {
          let k = j;
          if (rtf[k] === 0x2d) { k++; }
          while (k < n && DIGIT(rtf[k])) { k++; }
          numStr = rtf.slice(j, k).toString('latin1');
          j = k;
        }
        if (j < n && rtf[j] === 0x20 /* space */) { j++; }
        const tokEnd = j;

        switch (word) {
          case 'u': {
            let code = parseInt(numStr || '0', 10);
            if (code < 0) { code += 65536; }
            if (skipUntilDepth < 0) {
              if (code === 0x2028 || code === 0x2029) { brk(); }        // Cocoa line/para separator
              else if (!(code >= 0xD800 && code <= 0xDBFF)) { emit(i, tokEnd); } // (lone-surrogate high emits nothing)
            }
            unicodeSkip = ucStack[ucStack.length - 1];
            break;
          }
          case 'uc': ucStack[ucStack.length - 1] = Math.max(0, parseInt(numStr || '0', 10)); break;
          case 'par': case 'line': if (skipUntilDepth < 0) { brk(); } break;
          case 'tab': if (skipUntilDepth < 0) { brk(); } break;
          default:
            if (DEST_WORDS.has(word)) { if (skipUntilDepth < 0) { skipUntilDepth = depth; } }
            break;
        }
        i = tokEnd; continue;
      }
      // \* ignorable destination.
      if (next === 0x2a /* * */) { if (skipUntilDepth < 0) { skipUntilDepth = depth; } i += 2; continue; }
      // Other control symbol — drop.
      i += 2; continue;
    }
    if (c === 0x7b /* { */) { depth++; ucStack.push(ucStack[ucStack.length - 1]); i++; continue; }
    if (c === 0x7d /* } */) {
      if (skipUntilDepth >= 0 && depth <= skipUntilDepth) { skipUntilDepth = -1; }
      if (depth > 0) { depth--; }
      if (ucStack.length > 1) { ucStack.pop(); }
      i++; continue;
    }
    if (c === 0x0d || c === 0x0a) { i++; continue; } // raw CR/LF is not text
    if (skipUntilDepth < 0) { if (unicodeSkip > 0) { unicodeSkip--; } else { emit(i, i + 1); } }
    i++;
  }

  // Group maximal contiguous emitted spans into runs (a break or a byte-gap ends a run).
  const runs = [];
  let curS = -1, curE = -1;
  const flush = () => { if (curS >= 0) { runs.push([curS, curE]); curS = -1; curE = -1; } };
  for (const ev of events) {
    if (ev.t === 'break') { flush(); continue; }
    if (curE >= 0 && ev.s === curE) { curE = ev.e; }
    else { flush(); curS = ev.s; curE = ev.e; }
  }
  flush();

  if (runs.length === 0) { return Buffer.from(rtf); } // no visible text (e.g. an empty title box)

  // Splice replacements from the END backwards so earlier offsets never shift.
  let out = Buffer.from(rtf);
  let lineNo = runs.length;
  for (let r = runs.length - 1; r >= 0; r--) {
    const [s, e] = runs[r];
    const repl = Buffer.from(`Sanitised line ${lineNo}`, 'latin1');
    out = Buffer.concat([out.slice(0, s), repl, out.slice(e)]);
    lineNo--;
  }
  return out;
}

/* ─────────────────────────────── .pro sanitiser ─────────────────────────────── */

/**
 * Sanitise one `.pro` buffer: decode, rewrite every slide text element's `rtf_data`, re-encode.
 * Returns { bytes:Buffer, rewritten:int } (number of RTF payloads rewritten).
 */
function sanitiseProBuffer(buf) {
  const msg = Presentation.decode(buf);
  let rewritten = 0;
  for (const cue of (msg.cues || [])) {
    for (const action of (cue.actions || [])) {
      const els = action?.slide?.presentation?.base_slide?.elements
        || action?.slide?.presentation?.baseSlide?.elements;   // tolerate either casing
      for (const el of (els || [])) {
        const text = el?.element?.text;
        if (!text) { continue; }
        const rtf = text.rtf_data ?? text.rtfData;
        if (rtf && rtf.length) {
          const nb = sanitiseRtfBuffer(Buffer.from(rtf));
          if ('rtf_data' in text) { text.rtf_data = nb; } else { text.rtfData = nb; }
          rewritten++;
        }
      }
    }
  }
  const bytes = Buffer.from(Presentation.encode(msg).finish());
  return { bytes, rewritten };
}

/* ─────────────────────────────── .probundle sanitiser ─────────────────────────────── */

function sanitiseProbundleBuffer(buf, tinyMp4) {
  const entries = scanLocalEntries(buf);
  let rewrittenPro = 0, mediaReplaced = 0;
  const out = entries.map((e) => {
    if (e.name.toLowerCase().endsWith('.pro')) {
      const { bytes, rewritten } = sanitiseProBuffer(e.data);
      rewrittenPro += rewritten;
      return { name: e.name, data: bytes };
    }
    if (e.name.endsWith('/') || e.data.length === 0) { return { name: e.name, data: e.data }; }
    // Any non-.pro payload entry is media → swap for the tiny stub (name preserved).
    mediaReplaced++;
    return { name: e.name, data: tinyMp4 };
  });
  return { bytes: writeStoredZip(out), rewrittenPro, mediaReplaced, entryCount: entries.length };
}

/* ─────────────────────────────── CLI ─────────────────────────────── */

function sanitiseFile(inPath, outPath, mediaPath) {
  const buf = fs.readFileSync(inPath);
  const ext = path.extname(inPath).toLowerCase();
  if (ext === '.pro') {
    const { bytes, rewritten } = sanitiseProBuffer(buf);
    fs.writeFileSync(outPath, bytes);
    console.log(`  ${path.basename(outPath)} ← ${path.basename(inPath)} (.pro, ${rewritten} RTF payload(s) sanitised, ${bytes.length}b)`);
  } else if (ext === '.probundle' || ext === '.proplaylist') {
    if (!mediaPath) { throw new Error('--media <tiny.mp4> is required for .probundle/.proplaylist'); }
    const tiny = fs.readFileSync(mediaPath);
    const { bytes, rewrittenPro, mediaReplaced, entryCount } = sanitiseProbundleBuffer(buf, tiny);
    fs.writeFileSync(outPath, bytes);
    console.log(`  ${path.basename(outPath)} ← ${path.basename(inPath)} (${ext}, ${entryCount} entries, ${rewrittenPro} RTF payload(s), ${mediaReplaced} media→tiny, ${bytes.length}b)`);
  } else {
    throw new Error(`unsupported input extension: ${ext}`);
  }
}

// The committed P4 fixture set (plan §6.6 table), sourced from the owner's _temp/ originals.
const FIXTURES_DIR = path.join(REPO_ROOT, 'tests', 'fixtures', 'propresenter');
const TINY_MP4 = path.join(FIXTURES_DIR, 'assets', 'tiny.mp4');
const TEMP = path.join(REPO_ROOT, '_temp');
const P4_SET = [
  { in: path.join(TEMP, '002 (SDAH) - All Creatures Of Our God And King (Lasst Uns Erfreuen).pro'),
    out: path.join(FIXTURES_DIR, 'owner-v21-002-sdah-sanitised.pro') },
  { in: path.join(TEMP, '001 (SDAH) - Praise To The Lord The Almighty (Lobe den Herren).probundle'),
    out: path.join(FIXTURES_DIR, 'owner-v21-001-media-sanitised.probundle') },
  { in: path.join(TEMP, 'Here To Stay (Anthem Worship LLUC)[Video].pro'),
    out: path.join(FIXTURES_DIR, 'owner-v21-heretostay-video-sanitised.pro') },
  { in: path.join(TEMP, 'Here To Stay (Anthem Worship LLUC).pro'),
    out: path.join(FIXTURES_DIR, 'owner-v18-heretostay-sanitised.pro') },
];

function parseArgs(argv) {
  const a = { };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--in') { a.in = argv[++i]; }
    else if (argv[i] === '--out') { a.out = argv[++i]; }
    else if (argv[i] === '--media') { a.media = argv[++i]; }
    else if (argv[i] === '--all') { a.all = true; }
  }
  return a;
}

function main() {
  const a = parseArgs(process.argv.slice(2));
  if (a.all) {
    console.log('Regenerating the committed #1968 P4 fixtures from _temp/ originals:');
    for (const f of P4_SET) {
      if (!fs.existsSync(f.in)) { console.error(`  SKIP (source absent): ${path.basename(f.in)}`); continue; }
      sanitiseFile(f.in, f.out, TINY_MP4);
    }
    return;
  }
  if (!a.in || !a.out) {
    console.error('Usage: node tools/pp7-sanitise-fixture.js --in <path> --out <path> [--media <tiny.mp4>]');
    console.error('       node tools/pp7-sanitise-fixture.js --all');
    process.exit(2);
  }
  sanitiseFile(a.in, a.out, a.media || TINY_MP4);
}

main();
