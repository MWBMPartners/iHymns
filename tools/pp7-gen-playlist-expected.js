#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-playlist-expected.js — `.proplaylist` golden-fixture expected-output generator
 * (#1968 P3 foundation)
 * =====================================================================================================
 *
 * ELI5
 * ----
 * Sibling to `tools/pp7-gen-expected.js` (which does this for plain `.pro` files), but for
 * `.proplaylist` containers instead: it opens the ZIP, pulls out the `data` entry (a protobuf
 * `rv.data.PlaylistDocument` — the playlist tree), decodes it with a COMPLETELY SEPARATE
 * implementation from the PHP decoder (`includes/propresenter7_playlist.php`) — Node + protobufjs
 * reflection here, vs. a hand-rolled proto3 wire-walker there — and writes what THIS decoder saw
 * to `tests/fixtures/propresenter/expected/<name>.playlist.json`.
 * `tests/php/test-pp7-playlist-decode.php` then asserts the PHP decoder's output matches these
 * files field-for-field. Two independent implementations agreeing on real third-party files is
 * the whole point (owner's rule: never validate a decoder against a circular same-schema
 * round-trip — see `.claude/propresenter-interop-1968-plan.md` §0/§8, and this task's own brief).
 *
 * DEVIATION FROM THE TASK BRIEF, RECORDED PLAINLY (never bury a finding)
 * ------------------------------------------------------------------------
 * The task brief says "`proto-bundle.json` (the protobufjs descriptor) already includes the
 * playlist messages — reuse it." **Verified false during this task**: `proto-bundle.json` is
 * built by `tools/build-proto-bundle.js`'s `ENTRY_POINTS` list, which does NOT include
 * `propresenter.proto`/`playlist.proto` (only `presentation.proto` + its own dependency closure —
 * confirmed by reading `build-proto-bundle.js` directly, then confirmed a SECOND way by loading
 * the committed `proto-bundle.json` and calling `root.lookupType('rv.data.PlaylistDocument')`,
 * which throws `no such type`). Adding those two files to `ENTRY_POINTS` and regenerating the
 * SHARED `proto-bundle.json` is explicitly a LATER task (plan §5.2 step 1 — it feeds the CLIENT
 * static export module, `pp7-proto-static.js`, a separate concern from this decoder-only PR) —
 * doing it here would be scope creep into that later phase's file. This generator therefore loads
 * `propresenter.proto` directly from the vendored `proto-7.16/` directory into its OWN throwaway
 * `protobuf.Root()`, exactly the same technique `build-proto-bundle.js` itself uses, but scoped to
 * this test tool alone — the committed `proto-bundle.json` is never read or written by this file.
 *
 * A SECOND, smaller deviation: the vendored `proto-7.16/playlist.proto`'s `PlaylistItem.
 * Presentation` message does not declare field 5 (`arrangement_name` — a Pro19+, wire-compatible
 * addition; see `includes/propresenter7_playlist.php`'s file doc-block "UNCONFIRMED corner #4").
 * Real fixture bytes DO carry this field (`"normal"`/`"short"` observed) — protobufjs simply can't
 * see it without being told the field exists. This generator patches ONE field onto the
 * in-memory (never persisted) `Presentation` type after loading — see `patchArrangementName()`
 * below — so protobufjs decodes the SAME field 5 the PHP decoder does, genuinely independently
 * (different field-registration code, same wire bytes in, same value out). Discovered and
 * verified by hand during this task: decoding a real fixture WITHOUT the patch silently drops
 * `arrangement_name` from protobufjs's output (an unknown field, invisible via `toObject()`),
 * which would have made this generator's "expected" files WRONG — a false-negative source that
 * would have failed the PHP decoder for doing the CORRECT, tolerant thing. Recorded here so this
 * patch is never mistaken for decoration.
 *
 * OUTPUT SHAPE — deliberately IDENTICAL to `pp7ReadPlaylistBundle()`'s PHP return shape
 * -----------------------------------------------------------------------------------------
 * Unlike `pp7-gen-expected.js` (which emits a REDUCED projection the PHP test re-projects onto),
 * this generator's projector functions (`projPlaylist()`, `projPlaylistItem()`, …) mirror
 * `includes/propresenter7_playlist.php`'s decode functions field-for-field and key-for-key
 * (camelCase `hasColor`/`actionCount`/`documentPath`/`isHidden`/`itemType`, the same {uuid, name,
 * type, playlists, items} node shape, …) so the PHP test can compare `pp7ReadPlaylistBundle()`'s
 * OWN native output against this file directly — no further re-shaping needed on the PHP side
 * (simpler and less to get subtly wrong than `pp7-gen-expected.js`'s two-shape dance).
 *
 * ZIP READING: a from-scratch, independent JS port of the SAME tolerant algorithm
 * `includes/propresenter7_zip.php` implements (walk `PK\x03\x04` local file headers from byte 0,
 * resolve ZIP64 extra-field sizes when the 32-bit fields read the `0xFFFFFFFF` sentinel, NEVER
 * read a central directory or EOCD record) — see `listZipEntries()`/`readZipEntry()` below. This
 * is deliberately a SEPARATE re-implementation, not a shared module, mirroring how `protobufjs` is
 * a separate implementation from the PHP protobuf walker: an independent oracle is only
 * independent if it doesn't share code with the thing it's checking.
 *
 * SCOPE: only the three committed `.proplaylist` fixtures under `tests/fixtures/propresenter/`
 * (`.probundle` files are P2, already covered by `tests/php/test-pp7-zip.php` +
 * `test-pp7-probundle-import.php`; this generator does not touch them).
 *
 * Usage:
 *   node tools/pp7-gen-playlist-expected.js
 *
 * @see .claude/propresenter-interop-1968-plan.md      §5 (the `.proplaylist` design), §8.2 (expected-output files)
 * @see includes/propresenter7_playlist.php             the PHP decoder this cross-validates
 * @see includes/propresenter7_zip.php                  the PHP ZIP64 reader this file's listZipEntries()/readZipEntry() independently mirror
 * @see tests/php/test-pp7-playlist-decode.php           the consumer of this script's output
 * @see tools/pp7-gen-expected.js                        the sibling generator for plain `.pro` fixtures (same conventions, different scope)
 * @see https://github.com/protobufjs/protobuf.js#toobject-options  protobufjs toObject() options
 * @see https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT  §4.3.7 / §4.5.3 (ZIP local file header / ZIP64 extra field)
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
const FIXTURES_DIR = path.join(REPO_ROOT, 'tests', 'fixtures', 'propresenter');
const EXPECTED_DIR = path.join(FIXTURES_DIR, 'expected');
const PROTO_DIR = path.join(
  REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-7.16'
);

/* ============================================================================================
 * INDEPENDENT ZIP64-TOLERANT READER (mirrors includes/propresenter7_zip.php's ALGORITHM, not
 * its code — see the file-level doc-block for why that independence matters)
 * ============================================================================================ */

const ZIP_LOCAL_FILE_HEADER_SIG = 0x04034b50; // "PK\x03\x04" as a little-endian uint32

/**
 * Resolve an entry's true 64-bit uncompressed/compressed sizes from its ZIP64 extra field
 * (header id 0x0001), for an entry whose 32-bit header fields read the ZIP64 sentinel
 * 0xFFFFFFFF. Mirrors `_pp7ZipParseZip64Extra()` in propresenter7_zip.php.
 *
 * @param {Buffer} extra
 * @param {number} usize
 * @param {number} csize
 * @returns {{usize:number, csize:number}}
 */
function parseZip64Extra(extra, usize, csize) {
  const needUsize = usize === 0xFFFFFFFF;
  const needCsize = csize === 0xFFFFFFFF;
  if (!needUsize && !needCsize) {
    return { usize, csize };
  }
  let p = 0;
  while (p + 4 <= extra.length) {
    const id = extra.readUInt16LE(p);
    const len = extra.readUInt16LE(p + 2);
    if (p + 4 + len > extra.length) {
      throw new Error(`pp7playlist-gen: ZIP64 extra field id ${id} (len ${len}) runs past the extra-field block`);
    }
    if (id === 0x0001) {
      let q = p + 4;
      let outUsize = usize;
      let outCsize = csize;
      if (needUsize) {
        outUsize = Number(extra.readBigUInt64LE(q));
        q += 8;
      }
      if (needCsize) {
        outCsize = Number(extra.readBigUInt64LE(q));
        q += 8;
      }
      return { usize: outUsize, csize: outCsize };
    }
    p += 4 + len;
  }
  throw new Error('pp7playlist-gen: entry declares a 0xFFFFFFFF size sentinel but carries no ZIP64 extra field (id 0x0001)');
}

/**
 * Walk local file headers from byte 0 (NEVER the central directory / EOCD — see file doc-block).
 * @param {Buffer} buf
 * @returns {Array<{name:string, method:number, size:number, csize:number, offset:number}>}
 */
function listZipEntries(buf) {
  const entries = [];
  let pos = 0;
  while (pos + 4 <= buf.length) {
    if (buf.readUInt32LE(pos) !== ZIP_LOCAL_FILE_HEADER_SIG) {
      break; // normally the start of the central directory — stop, never read past this point
    }
    if (pos + 30 > buf.length) {
      throw new Error(`pp7playlist-gen: truncated local file header at byte offset ${pos}`);
    }
    const flags = buf.readUInt16LE(pos + 6);
    const method = buf.readUInt16LE(pos + 8);
    let csize = buf.readUInt32LE(pos + 18);
    let usize = buf.readUInt32LE(pos + 22);
    const nlen = buf.readUInt16LE(pos + 26);
    const elen = buf.readUInt16LE(pos + 28);

    if ((flags & 0x0008) !== 0) {
      throw new Error(`pp7playlist-gen: entry at byte offset ${pos} uses a deferred data descriptor (general-purpose bit 3), unsupported`);
    }
    if (method !== 0 && method !== 8) {
      throw new Error(`pp7playlist-gen: unsupported compression method ${method} at byte offset ${pos}`);
    }

    const nameStart = pos + 30;
    if (nameStart + nlen + elen > buf.length) {
      throw new Error(`pp7playlist-gen: entry name/extra field runs past the buffer at byte offset ${nameStart}`);
    }
    const name = buf.slice(nameStart, nameStart + nlen).toString('utf8');
    const extra = buf.slice(nameStart + nlen, nameStart + nlen + elen);

    if (csize === 0xFFFFFFFF || usize === 0xFFFFFFFF) {
      ({ usize, csize } = parseZip64Extra(extra, usize, csize));
    }

    const dataStart = nameStart + nlen + elen;
    if (dataStart + csize > buf.length) {
      throw new Error(`pp7playlist-gen: entry '${name}' data runs past the buffer at byte offset ${dataStart}`);
    }

    entries.push({ name, method, size: usize, csize, offset: dataStart });
    pos = dataStart + csize;
  }
  if (entries.length === 0) {
    throw new Error('pp7playlist-gen: no local file headers found at byte offset 0');
  }
  return entries;
}

/**
 * Return one entry's decompressed bytes. Mirrors `pp7ZipReadEntry()`.
 * @param {Buffer} buf
 * @param {{name:string, method:number, size:number, csize:number, offset:number}} entry
 * @returns {Buffer}
 */
function readZipEntry(buf, entry) {
  const raw = buf.slice(entry.offset, entry.offset + entry.csize);
  if (entry.method === 0) {
    return raw; // STORED — verbatim
  }
  // DEFLATE (raw deflate stream, no zlib/gzip wrapper) — Node's zlib.inflateRawSync is PHP's
  // gzinflate() equivalent (both operate on a raw deflate stream, no wrapper header).
  return zlib.inflateRawSync(raw);
}

/* ============================================================================================
 * PROTOBUFJS SCHEMA LOADING (independent of the shared proto-bundle.json — see file doc-block
 * "DEVIATION FROM THE TASK BRIEF" for why)
 * ============================================================================================ */

function loadPlaylistRoot() {
  const root = new protobuf.Root();
  root.resolvePath = (origin, target) => {
    if (target.startsWith('google/')) { return null; }
    if (path.isAbsolute(target)) { return fs.existsSync(target) ? target : null; }
    const local = path.join(PROTO_DIR, target);
    return fs.existsSync(local) ? local : null;
  };
  return root.load(path.join(PROTO_DIR, 'propresenter.proto'), { keepCase: true });
}

/**
 * Patch `arrangement_name = 5` onto `rv.data.PlaylistItem.Presentation` — see the file-level
 * doc-block's "A SECOND, smaller deviation" section for the full rationale. In-memory only;
 * never touches any file on disk.
 */
function patchArrangementName(root) {
  const presentationType = root.lookupType('rv.data.PlaylistItem.Presentation');
  presentationType.add(new protobuf.Field('arrangement_name', 5, 'string'));
  root.resolveAll();
}

/* ============================================================================================
 * PROJECTORS — mirror includes/propresenter7_playlist.php's decode functions field-for-field
 * (see file doc-block "OUTPUT SHAPE")
 * ============================================================================================ */

function uuidStr(msg) {
  return (msg && typeof msg === 'object' && typeof msg.string === 'string') ? msg.string : '';
}

function projUrl(u) {
  if (!u || typeof u !== 'object') {
    return { absoluteString: null, localRoot: null, localPath: null };
  }
  return {
    absoluteString: typeof u.absolute_string === 'string' ? u.absolute_string : null,
    localRoot: (u.local && typeof u.local.root === 'number') ? u.local.root : null,
    localPath: (u.local && typeof u.local.path === 'string') ? u.local.path : null,
  };
}

/** rv.data.Version{major_version=1,minor_version=2,patch_version=3} -> "major.minor[.patch]",
 *  matching pp7DecodeVersionString()'s EXACT formatting rule (patch omitted unless > 0). */
function versionStr(v) {
  if (!v || typeof v.major_version !== 'number') {
    return null;
  }
  const major = v.major_version;
  const minor = typeof v.minor_version === 'number' ? v.minor_version : 0;
  const patch = typeof v.patch_version === 'number' ? v.patch_version : 0;
  return patch > 0 ? `${major}.${minor}.${patch}` : `${major}.${minor}`;
}

function projApplicationInfo(ai) {
  return {
    platform: (ai && typeof ai.platform === 'number') ? ai.platform : 0,
    applicationVersion: ai ? versionStr(ai.application_version) : null,
  };
}

function projHeader(h) {
  return {
    hasColor: !!(h && h.color),
    actionCount: (h && Array.isArray(h.actions)) ? h.actions.length : 0,
  };
}

function projPresentation(p) {
  return {
    documentPath: projUrl(p && p.document_path),
    arrangement: (p && p.arrangement) ? (uuidStr(p.arrangement) || null) : null,
    arrangementName: (p && typeof p.arrangement_name === 'string') ? p.arrangement_name : null,
  };
}

function projPlaylistItem(it) {
  let itemType = 'unknown';
  let header = null;
  let presentation = null;
  if (it.header) {
    itemType = 'header';
    header = projHeader(it.header);
  } else if (it.presentation) {
    itemType = 'presentation';
    presentation = projPresentation(it.presentation);
  } else if (it.cue) {
    itemType = 'cue';
  } else if (it.planning_center) {
    itemType = 'planningCenter';
  } else if (it.placeholder) {
    itemType = 'placeholder';
  }
  return {
    uuid: uuidStr(it.uuid),
    name: it.name || '',
    isHidden: !!it.is_hidden,
    itemType,
    header,
    presentation,
  };
}

/**
 * rv.data.Playlist -> {uuid, name, type, playlists, items}. `playlists` merges BOTH nesting
 * mechanisms (the flat `children` field then the oneof `playlists` field — see
 * includes/propresenter7_playlist.php's pp7DecodePlaylist() doc-block "UNCONFIRMED corner #3"
 * for why declaration order is used here rather than true wire order: toObject() cannot recover
 * cross-field wire interleaving, and no real fixture populates both fields at once anyway).
 */
function projPlaylist(p) {
  const src = p || {};
  const childrenArr = Array.isArray(src.children) ? src.children.map(projPlaylist) : [];
  const oneofArr = (src.playlists && Array.isArray(src.playlists.playlists))
    ? src.playlists.playlists.map(projPlaylist)
    : [];
  const items = (src.items && Array.isArray(src.items.items))
    ? src.items.items.map(projPlaylistItem)
    : [];
  return {
    uuid: uuidStr(src.uuid),
    name: src.name || '',
    type: typeof src.type === 'number' ? src.type : 0,
    playlists: [...childrenArr, ...oneofArr],
    items,
  };
}

function projPlaylistDocument(doc) {
  const root = projPlaylist(doc.root_node);
  let playlists = root.playlists;
  if (playlists.length === 0 && root.items.length > 0) {
    // Tolerance fallback — see pp7DecodePlaylistDocument()'s doc-block. Never hit by a real
    // committed fixture (all three nest at least one child playlist), included for parity.
    playlists = [root];
  }
  return {
    applicationInfo: projApplicationInfo(doc.application_info),
    type: typeof doc.type === 'number' ? doc.type : 0,
    root,
    playlists,
  };
}

/* ============================================================================================
 * MAIN
 * ============================================================================================ */

async function main() {
  const files = fs.readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith('.proplaylist'))
    .sort();

  if (files.length === 0) {
    console.error(`No .proplaylist fixtures found under ${FIXTURES_DIR} — that is almost certainly wrong.`);
    process.exit(1);
  }

  if (!fs.existsSync(EXPECTED_DIR)) {
    fs.mkdirSync(EXPECTED_DIR, { recursive: true });
  }

  const root = await loadPlaylistRoot();
  patchArrangementName(root);
  root.resolveAll();
  const Doc = root.lookupType('rv.data.PlaylistDocument');

  let count = 0;
  for (const f of files) {
    const zipBytes = fs.readFileSync(path.join(FIXTURES_DIR, f));
    let out;
    try {
      const entries = listZipEntries(zipBytes);
      const dataEntry = entries.find((e) => e.name === 'data');
      if (!dataEntry) {
        throw new Error('no entry named "data" found in the ZIP');
      }
      const proEntries = [];
      const mediaEntries = [];
      for (const e of entries) {
        if (e.name === '' || e.name.endsWith('/') || e.name === 'data') { continue; }
        if (e.name.toLowerCase().endsWith('.pro')) {
          proEntries.push(e.name);
        } else {
          mediaEntries.push(e.name);
        }
      }
      const dataBytes = readZipEntry(zipBytes, dataEntry);
      const msg = Doc.decode(dataBytes);
      const obj = Doc.toObject(msg, { longs: String, defaults: false });
      out = {
        document: projPlaylistDocument(obj),
        proEntries,
        mediaEntries,
      };
    } catch (err) {
      console.error(`FAILED decoding ${f}: ${err.message}`);
      process.exitCode = 1;
      continue;
    }
    const outName = f.replace(/\.proplaylist$/, '.playlist.json');
    const outPath = path.join(EXPECTED_DIR, outName);
    fs.writeFileSync(outPath, JSON.stringify(out, null, 2) + '\n');
    console.log(`wrote ${path.relative(REPO_ROOT, outPath)}`);
    count++;
  }

  console.log(`\n${count} expected-output file(s) generated from ${count} .proplaylist fixture(s) under ${path.relative(REPO_ROOT, FIXTURES_DIR)}.`);
}

main().catch((err) => {
  console.error(err.stack);
  process.exit(1);
});
