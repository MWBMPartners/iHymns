#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-chord-fixture.js — synthetic chord-bearing `.pro` fixture generator (#1968 P6)
 * ================================================================================================
 *
 * ⚠️ REFERENCE-DERIVED (greyshirtguy Pro7ChordEditorWin + xilefmusics/chordlib + the vendored
 * proto-7.16 schema) — NOT validated against a real ProPresenter-authored chord file yet; see
 * owner checklist D4 in `.claude/propresenter-chords-plan.md` §5. All 12 real `.pro` samples this
 * epic has decoded are chord-FREE, so until the owner supplies a genuine chord-bearing export,
 * this synthetic, byte-verified-header fixture is the only chord-bearing input the import test
 * (`tests/php/test-pp7-chord-import.php`) can run against.
 *
 * ELI5
 * ----
 * Builds two ProPresenter 7 files that are IDENTICAL in every way — same lyrics, same slide
 * layout, same section names — except ONE of them also carries positioned chord data (the
 * protobuf `CustomAttribute{range, chord}` rows the plan's §1.1 decoded from the real schema and
 * two independent third-party tools). `test-pp7-chord-import.php` imports both and checks: (a) the
 * chord-bearing one places every chord at the right column, and (b) its LYRIC TEXT comes out
 * byte-identical to the chordless twin — proving chord capture never pollutes the words.
 *
 * WHY THIS IS NOT A CIRCULAR ROUND-TRIP (the owner's #1 rule for this epic)
 * --------------------------------------------------------------------------
 * This script builds the `rv.data.Presentation` protobuf message DIRECTLY via `protobufjs`
 * REFLECTION (`protobuf.Root.fromJSON` + `Presentation.create()`/`.encode()`) against the plain
 * object shapes this file defines ITSELF — it does NOT call
 * `appWeb/public_html/manage/editor/propresenter-export.js` (this repo's own exporter) at all, so
 * the importer under test is never being checked against its own sibling's idea of the wire
 * format. (Compare `tools/pp7-gen-roundtrip-sample.js`, which DOES run the real exporter — that
 * script exists for the DIFFERENT purpose of proving our two halves agree with EACH OTHER, §6.2 /
 * commit C4 below; using it here for the chord IMPORT-correctness proof would be exactly the
 * same-schema circularity the owner has repeatedly flagged as unacceptable.) The Cocoa-RTF header
 * bytes below are copied verbatim from `tests/fixtures/propresenter/bussnet-test.pro` (MIT,
 * decoded once during authoring to confirm the exact byte shape — see the header comment on
 * `RTF_HEADER` below) — a REAL, already-committed, already-vetted third-party file — not invented.
 *
 * WHAT GETS BUILT (`.claude/propresenter-chords-plan.md` §5's fixture checklist, ALL covered)
 * -----------------------------------------------------------------------------------------------
 *   - 3 cue_groups / 4 cues (a superset of the plan's "2 groups / 3 cues" — the extra group is
 *     the entirely CHORDLESS "Bridge" component the §6 guard's "a chordless component carries NO
 *     chords key" assertion needs; every other bullet below lives on the first two groups):
 *       "Verse 1"  — cue A (1 line):  chord at column 0, a chord MID-WORD
 *                    cue B (2 lines): a chord positioned right AFTER a non-BMP emoji (exercises
 *                                     UTF-16<->code-point conversion) on line 2 — the multi-line
 *                                     slide that exercises PP7_CHORD_NEWLINE_UNITS bucketing — plus
 *                                     a second, ordinary chord on line 1 (column 0) so a wrong
 *                                     newline weight would visibly mis-bucket ONE of the two, not
 *                                     both identically
 *       "Chorus"   — cue C (1 line):  one ordinary in-bounds chord, one deliberately OVERFLOWING
 *                                     chord offset (clamps to the line's end, plan §3.3 point 3),
 *                                     and one NON-chord CustomAttribute (the `capitalization`
 *                                     oneof branch) that must be silently skipped, never surfacing
 *                                     as a stray chord
 *       "Bridge"   — cue D (1 line):  NO custom_attributes anywhere — the chordless component
 *   - `chord_pro{enabled:true}` is set on cue A's element only, absent on every other element —
 *     the plan §5 bullet "import must not care" (this importer never reads `chord_pro` at all —
 *     see `includes/song_importers.php`'s "SCOPE NOTE" doc-comment on the chord-import section).
 *
 * `NEWLINE_UNITS` (the plan §1.2 open question — chordlib's convention, 1, vs greyshirtguy's, 0)
 * is baked in here as **1** (this generator's own local mirror of the PHP-side
 * `PP7_CHORD_NEWLINE_UNITS` constant — see `globalUtf16Offset()` below), matching the primary
 * convention the plan adopts; flipping it after real D4 evidence is a one-constant change in each
 * half plus a fixture regen, exactly as the plan's isolation requirement intends.
 *
 * Usage:
 *   node tools/pp7-gen-chord-fixture.js <chord-output-path.pro> <chordless-output-path.pro>
 *
 * Regenerated FRESH on every test run (the `pp7-gen-roundtrip-sample.js` posture) — never
 * committed as a binary.
 *
 * Exit status: 0 = both files written, 1 = any failure (missing args, encode error).
 *
 * @see appWeb/public_html/includes/song_importers.php          the importer under test (chord-import section)
 * @see appWeb/public_html/includes/propresenter7_decode.php    the decoder this fixture exercises (C1)
 * @see tests/php/test-pp7-chord-import.php                     the consumer of this script's output
 * @see .claude/propresenter-chords-plan.md                     §5 (this fixture's brief), §1 (the format)
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

const PROTO_BUNDLE = path.join(
  REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

/* ====================================================================
 * Local NEWLINE_UNITS mirror (plan §1.2) — the JS-side sibling of
 * `PP7_CHORD_NEWLINE_UNITS` in includes/song_importers.php. Isolated
 * behind one named constant so a post-D4 flip is a one-line change.
 * ==================================================================== */
const NEWLINE_UNITS = 1;

/* ====================================================================
 * UTF-16 <-> code-point helpers (independently written — NOT imported
 * from propresenter-export.js, which does not even have this half of
 * the maths; export computes UTF-16 FROM code points going the other
 * direction, but the generator needs its OWN "what UTF-16 offset does
 * this position land at" arithmetic to place chords deliberately).
 * ==================================================================== */
function toSigned16(n) {
  return n > 32767 ? n - 65536 : n;
}

function utf16Len(str) {
  let n = 0;
  for (const ch of Array.from(str)) {
    n += ch.codePointAt(0) > 0xffff ? 2 : 1;
  }
  return n;
}

/** Global UTF-16 offset for (lineIndex, codePointColumn) within a multi-line `lines` array. */
function globalUtf16Offset(lines, lineIndex, codePointColumn) {
  let offset = 0;
  for (let i = 0; i < lineIndex; i++) {
    offset += utf16Len(lines[i]) + NEWLINE_UNITS;
  }
  const cps = Array.from(lines[lineIndex]);
  for (let i = 0; i < codePointColumn; i++) {
    offset += cps[i].codePointAt(0) > 0xffff ? 2 : 1;
  }
  return offset;
}

/* ====================================================================
 * RTF builder — Apple "Cocoa RTF" dialect, header bytes copied VERBATIM
 * from tests/fixtures/propresenter/bussnet-test.pro (a REAL, MIT,
 * already-committed third-party file), decoded once during this
 * script's authoring to confirm the exact shape:
 *
 *   {\rtf1\ansi\ansicpg1252\cocoartf2761
 *   \cocoatextscaling0\cocoaplatform0{\fonttbl\f0\fnil\fcharset0 HelveticaNeue;}
 *   {\colortbl;\red255\green255\blue255;\red255\green255\blue255;}
 *   {\*\expandedcolortbl;;\csgray\c100000;}
 *   \deftab1680
 *   \pard\pardeftab1680\pardirnatural\qc\partightenfactor0
 *
 *   \f0\fs84 \cf2 \CocoaLigature0 <BODY>}
 *
 * — with `\uc1` added explicitly before the body (real Cocoa output always
 * carries it; `_bulkImport_rtfToText()`'s own default `$ucStack = [1]`
 * means it is not STRICTLY required, but including it matches genuine
 * Cocoa output rather than relying on a decoder default). Lines within
 * one slide are joined with the SAME Mac "soft return" idiom the real
 * fixture uses for its own multi-line "Vers1.3\nVers1.4" cue — a literal
 * backslash immediately followed by a literal line-feed byte — confirmed,
 * not assumed, by decoding that real cue during authoring (see
 * `tests/fixtures/propresenter/expected/bussnet-test.song.json`'s
 * component 0, whose 4 lines round-trip through exactly this construct).
 * ==================================================================== */
function rtfEscapeLine(s) {
  let out = '';
  for (const ch of Array.from(s)) {
    const cp = ch.codePointAt(0);
    if (ch === '\\') {
      out += '\\\\';
    } else if (ch === '{') {
      out += '\\{';
    } else if (ch === '}') {
      out += '\\}';
    } else if (cp > 127) {
      if (cp > 0xffff) {
        // Supplementary-plane character (e.g. most emoji) -> UTF-16 surrogate pair,
        // RTF-encoded as TWO \uN? escapes, each with its own one-char ANSI fallback —
        // exactly the shape tests/php/test-pp7-rtf-extract.php's surrogate-pair row
        // ("change 3") already proves _bulkImport_rtfToText() reassembles correctly.
        const high = 0xd800 + ((cp - 0x10000) >> 10);
        const low = 0xdc00 + ((cp - 0x10000) & 0x3ff);
        out += '\\u' + toSigned16(high) + '?\\u' + toSigned16(low) + '?';
      } else {
        out += '\\u' + toSigned16(cp) + '?';
      }
    } else {
      out += ch;
    }
  }
  return out;
}

function buildRtfBytes(lines) {
  const header =
    '{\\rtf1\\ansi\\ansicpg1252\\cocoartf2761\n' +
    '\\cocoatextscaling0\\cocoaplatform0{\\fonttbl\\f0\\fnil\\fcharset0 HelveticaNeue;}\n' +
    '{\\colortbl;\\red255\\green255\\blue255;\\red255\\green255\\blue255;}\n' +
    '{\\*\\expandedcolortbl;;\\csgray\\c100000;}\n' +
    '\\deftab1680\n' +
    '\\pard\\pardeftab1680\\pardirnatural\\qc\\partightenfactor0\n\n' +
    '\\f0\\fs84 \\cf2 \\uc1\\CocoaLigature0 ';
  const body = lines.map(rtfEscapeLine).join('\\\n');
  const rtf = header + body + '}';
  // Pure 7-bit ASCII by construction (every non-ASCII code point above was already
  // converted to a \uN? escape) — latin1 is a safe byte-for-byte mapping for that.
  return Buffer.from(rtf, 'latin1');
}

/* ====================================================================
 * Message-tree builders (plain objects — protobufjs `.create()` walks
 * these against the schema; field names below are the schema's OWN
 * snake_case names, verified in appWeb/.../protos/proto-7.16/*.proto —
 * see the field-table citations in includes/propresenter7_decode.php,
 * which this fixture is built to exercise the read side of).
 * ==================================================================== */
let uuidCounter = 0;
function uuidMsg() {
  uuidCounter += 1;
  const hex = uuidCounter.toString(16).padStart(12, '0').toUpperCase();
  return { string: `00000000-0000-4000-8000-${hex}` };
}

/** Tile `range.end` across a SORTED-by-start chord list, mirroring the real writer convention
 *  (plan §1.2) — never consulted by the importer, but included for shape-realism. */
function tileEnds(rows, totalUtf16Len) {
  const sorted = rows.slice().sort((a, b) => a.start - b.start);
  return sorted.map((row, i) => ({
    ...row,
    end: i + 1 < sorted.length ? sorted[i + 1].start : Math.max(row.start + 1, totalUtf16Len),
  }));
}

/**
 * Build one slide text element.
 * @param {object} opts
 * @param {string[]} opts.lines            plain lyric lines (code points; escaped internally)
 * @param {Array<{start:number,chord:string}>} [opts.chordRows]  chord CustomAttribute rows (end auto-tiled)
 * @param {Array<{start:number,end:number,capitalization:number}>} [opts.nonChordRows]  a non-chord
 *        oneof branch row (e.g. capitalization) that MUST be skipped by the importer
 * @param {boolean} [opts.chordProEnabled] set Graphics.Text.chord_pro{enabled:true} (display-only;
 *        import must not care — see the file header)
 * @param {boolean} [opts.withChords]      false => build the CHORDLESS twin (no custom_attributes,
 *        no chord_pro, at ALL — identical lyric text otherwise)
 */
function makeTextElement(opts) {
  const rtfBytes = buildRtfBytes(opts.lines);
  const totalUtf16Len = opts.lines.reduce(
    (acc, l, i) => acc + utf16Len(l) + (i > 0 ? NEWLINE_UNITS : 0),
    0
  );

  const text = { rtf_data: rtfBytes };

  if (opts.withChords) {
    const customAttributes = [];
    if (opts.chordRows && opts.chordRows.length) {
      for (const row of tileEnds(opts.chordRows, totalUtf16Len)) {
        customAttributes.push({ range: { start: row.start, end: row.end }, chord: row.chord });
      }
    }
    if (opts.nonChordRows) {
      for (const row of opts.nonChordRows) {
        customAttributes.push({
          range: { start: row.start, end: row.end },
          capitalization: row.capitalization,
        });
      }
    }
    if (customAttributes.length) {
      text.attributes = { custom_attributes: customAttributes };
    }
    if (opts.chordProEnabled) {
      text.chord_pro = { enabled: true };
    }
  }

  return {
    info: 3, // IS_TEMPLATE_ELEMENT | IS_TEXT_ELEMENT bitmask (matches every real fixture's text element)
    element: {
      uuid: uuidMsg(),
      name: 'Lyrics',
      bounds: { origin: { x: 96, y: 96 }, size: { width: 1728, height: 888 } },
      text,
    },
  };
}

function makeLyricCue(name, elementOpts) {
  const cueUuid = uuidMsg();
  return {
    cueId: cueUuid,
    cue: {
      uuid: cueUuid,
      name,
      isEnabled: true,
      actions: [
        {
          uuid: uuidMsg(),
          name,
          isEnabled: true,
          type: 11, // ACTION_TYPE_PRESENTATION_SLIDE (action.proto — same constant propresenter-export.js uses)
          slide: {
            presentation: {
              base_slide: {
                uuid: uuidMsg(),
                size: { width: 1920, height: 1080 },
                elements: [makeTextElement(elementOpts)],
              },
            },
          },
        },
      ],
    },
  };
}

/**
 * Build the full Presentation payload. `withChords` toggles between the chord-bearing fixture and
 * its byte-for-byte lyric-identical chordless twin (only `custom_attributes`/`chord_pro` differ).
 */
function buildPresentation(withChords) {
  uuidCounter = 0; // deterministic UUIDs across both builds, for easier diffing if ever needed

  const lineA = ['Amazing grace how sweet the sound'];
  const cueA = makeLyricCue('V1.1', {
    lines: lineA,
    withChords,
    chordProEnabled: true, // plan §5: enabled on ONE element only — import must not care
    chordRows: [
      { start: globalUtf16Offset(lineA, 0, 0), chord: 'G' }, // column 0
      { start: globalUtf16Offset(lineA, 0, lineA[0].indexOf('sweet') + 2), chord: 'D' }, // mid-word
    ],
  });

  const linesB = ['That saved a wretch like me', 'I once was \u{1F60A} lost but now am found'];
  const emojiIdx = Array.from(linesB[1]).findIndex((ch) => ch.codePointAt(0) === 0x1f60a);
  const cueB = makeLyricCue('V1.2', {
    lines: linesB,
    withChords,
    chordRows: [
      { start: globalUtf16Offset(linesB, 0, 0), chord: 'Bm' }, // line 1, column 0
      { start: globalUtf16Offset(linesB, 1, emojiIdx + 1), chord: 'C' }, // line 2, right after the emoji
    ],
  });

  const lineC = ['Was blind but now I see'];
  const lineCUtf16Len = utf16Len(lineC[0]);
  const cueC = makeLyricCue('C', {
    lines: lineC,
    withChords,
    chordRows: [
      { start: globalUtf16Offset(lineC, 0, 0), chord: 'F' }, // in-bounds, column 0
      { start: lineCUtf16Len + 50, chord: 'Amen' }, // deliberately OVERFLOWS — must clamp to line end
    ],
    nonChordRows: [
      // A NON-chord CustomAttribute (the `capitalization` oneof branch) — must be silently
      // skipped by pp7DecodeCustomAttribute()'s oneof filter, never surfacing as a stray chord.
      { start: 0, end: 5, capitalization: 1 }, // CAPITALIZATION_ALL_CAPS
    ],
  });

  const lineD = ['Bridge stands alone with no chords at all'];
  const cueD = makeLyricCue('B', {
    lines: lineD,
    withChords: false, // genuinely chordless — even in the "withChords" build (the plan §6 "a
    // chordless component carries NO chords key" assertion needs one WITHIN the
    // chord-bearing fixture itself, not only via the separate chordless twin file).
  });

  const verse1Group = {
    group: { uuid: uuidMsg(), name: 'Verse 1' },
    cue_identifiers: [cueA.cueId, cueB.cueId],
  };
  const chorusGroup = {
    group: { uuid: uuidMsg(), name: 'Chorus' },
    cue_identifiers: [cueC.cueId],
  };
  const bridgeGroup = {
    group: { uuid: uuidMsg(), name: 'Bridge' },
    cue_identifiers: [cueD.cueId],
  };

  return {
    uuid: uuidMsg(),
    name: 'PP7 Chord Fixture',
    cue_groups: [verse1Group, chorusGroup, bridgeGroup],
    cues: [cueA.cue, cueB.cue, cueC.cue, cueD.cue],
  };
}

function main() {
  const [, , chordOutPath, chordlessOutPath] = process.argv;
  if (!chordOutPath || !chordlessOutPath) {
    console.error(
      'usage: node tools/pp7-gen-chord-fixture.js <chord-output-path.pro> <chordless-output-path.pro>'
    );
    process.exit(1);
  }

  const root = protobuf.Root.fromJSON(JSON.parse(fs.readFileSync(PROTO_BUNDLE, 'utf8')));
  const Presentation = root.lookupType('rv.data.Presentation');

  for (const [outPath, withChords] of [
    [chordOutPath, true],
    [chordlessOutPath, false],
  ]) {
    const payload = buildPresentation(withChords);
    const problem = Presentation.verify(payload);
    if (problem) {
      console.error(`payload verify failed (withChords=${withChords}): ${problem}`);
      process.exit(1);
    }
    const message = Presentation.create(payload);
    const bytes = Buffer.from(Presentation.encode(message).finish());
    fs.writeFileSync(outPath, bytes);
    console.log(`wrote ${bytes.length} byte(s) to ${outPath}`);
  }
}

main();
