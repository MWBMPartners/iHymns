#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-expected.js — ProPresenter 7+ golden-fixture expected-output generator (#1968 PR-1)
 * =====================================================================================================
 *
 * ELI5
 * ----
 * The PHP decoder (`includes/propresenter7_decode.php`) reads real ProPresenter `.pro` files by
 * hand-walking the raw protobuf bytes. To prove it reads them CORRECTLY — not just "doesn't
 * crash" — this script decodes the SAME files with a completely different, independent decoder
 * (`protobufjs`'s reflection API, driven off the vendored schema JSON) and writes what THAT
 * decoder saw to `tests/fixtures/propresenter/expected/<name>.decode.json`.
 * `tests/php/test-pp7-decode.php` then asserts the PHP decoder's output matches these files
 * field-for-field. Two independent implementations agreeing on real third-party files is the
 * whole point — see `.claude/propresenter-interop-1968-plan.md` §8 for why (the owner's rule:
 * never validate a decoder against a circular same-schema round-trip).
 *
 * DETAILED
 * --------
 * This is NOT the same shape `pp7DecodePresentation()` returns. The PHP decoder's contract
 * (plan §2.1) carries parallel arrays per cue (`slideRtf[]` + `slideElementInfos[]`) because
 * that is a convenient shape for the (later) importer to consume. This generator instead emits
 * the REDUCED, hand-reviewable projection the task that produced this file specified:
 *
 *   { name, selectedArrangement, arrangements:[{uuid,name,groupIdentifiers}],
 *     cueGroups:[{groupUuid,groupName,cueIdentifiers}],
 *     cues:[{uuid, elements:[{info, rtfBase64}]}], ccli }
 *
 * `tests/php/test-pp7-decode.php` projects the PHP decoder's own richer output into this exact
 * shape before comparing (zips `slideRtf`+`slideElementInfos` back into `elements`, base64-encodes
 * the raw RTF bytes, and renames the CCLI keys) — so the comparison is byte-exact even though the
 * two files' native shapes differ slightly.
 *
 * SCOPE: only the plain `.pro` fixtures (NOT `.probundle`/`.proplaylist`, which are ZIP
 * containers — decoding what's INSIDE them needs the tolerant ZIP64 reader from plan §4, a
 * later phase/PR not yet implemented). Every `*.pro` file directly under
 * `tests/fixtures/propresenter/` gets a matching `expected/<name>.decode.json`.
 *
 * FIELD NAMES ARE SNAKE_CASE ON THE WIRE (plan §2.1's own warning): protobufjs's reflection
 * `toObject()` preserves the `.proto` schema's own field names verbatim — `cue_groups`,
 * `base_slide`, `rtf_data`, `group_identifiers`, `selected_arrangement`, etc. Reading a
 * camelCase equivalent (`cueGroups`) silently returns `undefined`. Every access below is
 * deliberately snake_case for this reason.
 *
 * Usage:
 *   node tools/pp7-gen-expected.js
 *
 * @see .claude/propresenter-interop-1968-plan.md   §2.1 (decoder contract), §8.2 (expected-output files)
 * @see includes/propresenter7_decode.php            the PHP decoder this cross-validates
 * @see tests/php/test-pp7-decode.php                 the consumer of this script's output
 * @see https://github.com/protobufjs/protobuf.js#toobject-options  protobufjs toObject() options
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..');
const FIXTURES_DIR = path.join(REPO_ROOT, 'tests', 'fixtures', 'propresenter');
const EXPECTED_DIR = path.join(FIXTURES_DIR, 'expected');
const PROTO_BUNDLE = path.join(
  REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

// The independent decoder: protobufjs's REFLECTION path (Root.fromJSON + lookupType), driven
// off the same vendored schema JSON the browser editor ships — but here running in plain Node,
// so its lazy new Function() codegen (the thing the enforcing browser CSP refuses — #1788) is
// no obstacle at all. This is a genuinely separate code path from the hand-rolled PHP walker:
// different language, different implementation, same wire bytes in.
const protoBundleJson = JSON.parse(fs.readFileSync(PROTO_BUNDLE, 'utf8'));
const root = protobuf.Root.fromJSON(protoBundleJson);
const Presentation = root.lookupType('rv.data.Presentation');

/**
 * A decoded rv.data.UUID{string=1} object → its plain string, or '' if absent/empty.
 * ELI5: unwraps the one-field "a UUID is just a string" wrapper every id in this schema uses.
 */
function uuidStr(msg) {
  return (msg && typeof msg === 'object' && typeof msg.string === 'string') ? msg.string : '';
}

/**
 * One rv.data.Slide.Element (already toObject()-ed) → {info, rtfBase64}.
 * `rtf_data` lives two levels down: Slide.Element.element (a Graphics.Element) .text
 * (a Graphics.Text) .rtf_data — matching the PHP decoder's own nesting
 * (pp7DecodeSlideElement → pp7DecodeGraphicsElementRtf → pp7DecodeGraphicsText).
 */
function elementInfoRtf(el) {
  const info = (el && typeof el.info === 'number') ? el.info : 0;
  const rtfBase64 = (el && el.element && el.element.text && typeof el.element.text.rtf_data === 'string')
    ? el.element.text.rtf_data
    : '';
  return { info, rtfBase64 };
}

/**
 * Decode one `.pro` file's raw bytes with protobufjs and project the result into the reduced
 * comparison shape described in the file header.
 */
function decodeOne(buf) {
  const msg = Presentation.decode(buf);
  // `bytes: String` makes every `bytes`-typed field (rtf_data) arrive pre-base64-encoded,
  // rather than a Node Buffer we'd have to re-encode ourselves — one less place for this
  // "independent" decoder to accidentally share logic with the PHP side.
  //
  // ⚠️ BOTH `enums` and `bytes` MUST be the actual `String` CONSTRUCTOR, never a string
  // literal — protobufjs's generated toObject() checks `options.enums === String` /
  // `options.bytes === String` by reference (see node_modules/protobufjs/src/converter.js).
  // Passing `'string'`/`'base64'` literals silently falls through to protobufjs's default
  // behaviour instead of erroring: `enums` defaults to a bare int (`action.type` comes back
  // as `11`, not `'ACTION_TYPE_PRESENTATION_SLIDE'`, silently failing the `type !==
  // 'ACTION_TYPE_PRESENTATION_SLIDE'` filter below and dropping every element), and `bytes`
  // defaults to a raw Node `Buffer` (which `JSON.stringify()` then renders as
  // `{"type":"Buffer","data":[...]}`, not the base64 string this file's own header promises).
  // Both were caught by hand-reviewing the FIRST generated output before committing — every
  // fixture's `cues[*].elements` came back empty despite real fixtures having real lyric
  // text, exactly the false-negative class this whole PR-1 harness exists to prevent.
  const obj = Presentation.toObject(msg, { longs: String, enums: String, bytes: String, defaults: false });

  const arrangements = (obj.arrangements || []).map((a) => ({
    uuid: uuidStr(a.uuid),
    name: a.name || '',
    groupIdentifiers: (a.group_identifiers || []).map(uuidStr),
  }));

  const cueGroups = (obj.cue_groups || []).map((cg) => ({
    groupUuid: uuidStr(cg.group && cg.group.uuid),
    groupName: (cg.group && cg.group.name) || '',
    cueIdentifiers: (cg.cue_identifiers || []).map(uuidStr),
  }));

  const cues = (obj.cues || []).map((cue) => {
    const elements = [];
    for (const action of (cue.actions || [])) {
      // enums:'string' makes the ActionType enum arrive as its symbolic name rather than a
      // bare int — readable, and immune to this script accidentally typing the wrong number.
      if (action.type !== 'ACTION_TYPE_PRESENTATION_SLIDE') { continue; }
      const els = action.slide && action.slide.presentation && action.slide.presentation.base_slide
        && action.slide.presentation.base_slide.elements;
      for (const el of (els || [])) {
        elements.push(elementInfoRtf(el));
      }
    }
    return { uuid: uuidStr(cue.uuid), elements };
  });

  const ccliRaw = obj.ccli || {};
  const ccli = {
    author: ccliRaw.author || '',
    artistCredits: ccliRaw.artist_credits || '',
    songTitle: ccliRaw.song_title || '',
    publisher: ccliRaw.publisher || '',
    copyrightYear: typeof ccliRaw.copyright_year === 'number' ? ccliRaw.copyright_year : null,
    songNumber: typeof ccliRaw.song_number === 'number' ? ccliRaw.song_number : null,
  };

  // selected_arrangement is itself an rv.data.UUID submessage (may be entirely absent — 001 of
  // the owner's ground-truth samples has none; PP7-GROUND-TRUTH.md §1).
  const selectedArrangement = obj.selected_arrangement
    ? (uuidStr(obj.selected_arrangement) || null)
    : null;

  return {
    name: obj.name || '',
    selectedArrangement,
    arrangements,
    cueGroups,
    cues,
    ccli,
  };
}

function main() {
  const files = fs.readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith('.pro')) // scope: bare .pro only — see file header
    .sort();

  if (files.length === 0) {
    console.error(`No .pro fixtures found under ${FIXTURES_DIR} — that is almost certainly wrong.`);
    process.exit(1);
  }

  if (!fs.existsSync(EXPECTED_DIR)) {
    fs.mkdirSync(EXPECTED_DIR, { recursive: true });
  }

  let count = 0;
  for (const f of files) {
    const buf = fs.readFileSync(path.join(FIXTURES_DIR, f));
    let decoded;
    try {
      decoded = decodeOne(buf);
    } catch (err) {
      console.error(`FAILED decoding ${f}: ${err.message}`);
      process.exitCode = 1;
      continue;
    }
    const outName = f.replace(/\.pro$/, '.decode.json');
    const outPath = path.join(EXPECTED_DIR, outName);
    fs.writeFileSync(outPath, JSON.stringify(decoded, null, 2) + '\n');
    console.log(`wrote ${path.relative(REPO_ROOT, outPath)}`);
    count++;
  }

  console.log(`\n${count} expected-output file(s) generated from ${count} .pro fixture(s) under ${path.relative(REPO_ROOT, FIXTURES_DIR)}.`);
}

main();
