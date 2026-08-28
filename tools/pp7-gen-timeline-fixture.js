#!/usr/bin/env node
'use strict';

/**
 * tools/pp7-gen-timeline-fixture.js — synthesised `Presentation.timeline` fixture generator (#1968 groundwork)
 * =================================================================================================================
 *
 * ELI5
 * ----
 * We need real-shaped `Presentation.timeline` (auto-advance) data to prove
 * `pp7DecodeTimeline()`/`pp7DecodeTimelineCue()` (`includes/propresenter7_decode.php`) decode it
 * correctly — but the real files hand-inspected to derive this feature's field map ("Rescuer
 * (Good News)", Rend Collective, © 2017, CCLI song #7094920) carry LIVE copyright in fields the
 * existing sanitiser (`tools/pp7-sanitise-fixture.js`) does not touch — it only rewrites RTF
 * lyric runs, not `Presentation.ccli`/`name`/arrangement/cue-group names. A check during authoring
 * confirmed the real author/publisher/song-number block decodes straight through untouched, so
 * that file must NOT be committed even in "sanitised" form (same reasoning
 * `tests/fixtures/propresenter/README.md` already documents for the excluded `TestTranslated.pro`
 * = Hillsong "Oceans", CCLI 6428767). This script instead SYNTHESISES timeline coverage from parts
 * already safe and already committed — the same strategy `tools/pp7-gen-zip64-bundle.js` uses for
 * the broken-EOCD `.probundle` case.
 *
 * ⚠️ FIELD-SEMANTICS FINDING (corrects an earlier draft of this feature's spec — logged here so it
 * is never silently re-introduced): `Timeline.cues` (field **1**) is the clean slide auto-advance
 * schedule (`trigger_time` + `cue_id` referencing a real presentation `Cue`, monotonically
 * increasing). `Timeline.cues_v2` (field **11**) is NOT an alternate/preferred copy of the same
 * schedule — on both real multi-cue Rescuer samples independently decoded during this task,
 * `cues_v2` is a SUPERSET carrying `ACTION_TYPE_CLEAR_GROUP`/`ACTION_TYPE_CLEAR` automation
 * entries (name "Clear All"/"Clear Slide", `trigger_time` frequently **0**, no `cue_id` — an
 * inline `action` instead) interleaved with duplicates of the real cues. Treating `cues_v2` as
 * preferred-when-present (an earlier draft's rule) would have captured a Clear-action automation
 * list as if it were the slide-advance timeline on any file that populates it — exactly the false
 * positive this epic's #1 rule forbids. The one real fixture where `cues`/`cues_v2` happen to
 * agree (`owner-v21-heretostay-video-sanitised.pro`, one entry, byte-identical in both) is the
 * degenerate case that let the wrong rule look correct in limited testing. **`cues` (field 1)
 * alone is authoritative; `cues_v2` is not consulted at all.**
 *
 * DETAILED — what gets built, and from what
 * -------------------------------------------
 * All outputs start from `bussnet-test.pro` (MIT, placeholder-German-word content, no live
 * copyright — see the README) decoded with protobufjs, with ONLY the `timeline` field replaced
 * before re-encoding (`Presentation.encode()`); every other field — name, ccli, cues, cue groups,
 * arrangements, lyric RTF — is untouched, so these fixtures also exercise the REST of the decoder
 * unchanged (they get `expected/*.decode.json` counterparts via `tools/pp7-gen-expected.js` like
 * every other `.pro` fixture, for free full-decoder cross-validation, not just timeline).
 *
 *   - `synthetic-timeline-cues.pro` — `Timeline.cues` (field 1) holds 3 `Cue` entries, each with a
 *     `cue_id` (the `trigger_info` oneof's field-2 branch) referencing one of `bussnet-test.pro`'s
 *     OWN real cue UUIDs (an internally-consistent reference, not dangling), increasing
 *     `trigger_time` values, and a `name`. The FIRST cue's `trigger_time` (0.787310792) is the
 *     exact value independently observed — TWICE, from two different decoders (protobufjs here,
 *     the hand-rolled PHP walker under test) — on the real, non-"Extended", "Rescuer (Good News)
 *     (Life Church Kids Video).pro" sample's own first `cues[0]`; a timing NUMBER carries no
 *     copyright, so reusing it ties this synthetic fixture back to genuine real-world shape
 *     without reproducing any of the song's actually-protected content (title, lyrics, credits).
 *     `cues_v2` is left empty and `loop` (field 6) is explicitly TRUE — the real files all had
 *     `loop=false`, so a test that only ever saw `false` could not tell "decoded the wire value"
 *     from "never read the field, defaulted false"; this fixture forces the non-default path.
 *   - `synthetic-timeline-cues-v2-must-be-ignored.pro` — `cues` (field 1) holds the correct 3
 *     increasing-time entries (same shape as above); `cues_v2` (field 11) is ALSO populated, but
 *     with obviously-wrong sentinel `trigger_time` values (777.x) and names flagged "WRONG" — data
 *     that must NEVER appear in `pp7DecodeTimeline()`'s output. Proves the fix for the field-
 *     semantics finding above: a decoder that still preferred/read `cues_v2` would leak the 777.x
 *     sentinels into the captured schedule.
 *   - `synthetic-timeline-absent.pro` — the `timeline` field is deleted outright (not merely
 *     empty) before re-encoding, so field 17 is genuinely absent from the wire — proves
 *     `pp7DecodePresentation()` returns `timeline: null` / `hasTimeline: false` rather than
 *     fabricating a timeline when none was ever written. Every OTHER real committed `.pro` fixture
 *     actually carries a template `timeline{duration:300}` with zero `cues` (ProPresenter appears
 *     to always write a placeholder Timeline submessage) — so this is the only fixture in the
 *     whole corpus where field 17 is truly missing, and is what makes the "no false positive on
 *     absence" assertion in `tests/php/test-pp7-timeline.php` meaningful rather than incidentally
 *     true.
 *
 * A real, already-committed, already-copyright-vetted single-cue timeline ALSO exists in this
 * corpus without any new fixture needed: `owner-v21-heretostay-video-sanitised.pro` carries one
 * genuine `Timeline.cues[0]` (`trigger_time≈0.6198`, the `action` oneof branch — no `cue_id` — a
 * MEDIA-triggering cue, name = the media filename). `tests/php/test-pp7-timeline.php` decodes that
 * real fixture directly rather than duplicating it here.
 *
 * WHY A SUBDIRECTORY (`timeline/`), not alongside the other fixtures
 * ---------------------------------------------------------------------
 * Both `test-pp7-decode.php` (a) and `test-pp7-parse.php` glob `tests/fixtures/propresenter/*.pro`
 * NON-recursively and FAIL LOUDLY on any `.pro` lacking a matching `expected/*.decode.json` /
 * `expected/*.song.json` — and every `expected/*.song.json` is, by that test's own documented
 * posture, hand-drafted-then-eyeballed against real file content, not machine-generated (see that
 * file's doc-block). These three fixtures test ONLY the timeline decode path (their song content
 * is deliberately unchanged from `bussnet-test.pro`, which already owns that coverage) and are
 * consumed directly by `tests/php/test-pp7-timeline.php` (fixed expected values asserted inline,
 * the `synthetic-zip64.probundle` / `test-pp7-zip.php` pattern) — so they live in the `timeline/`
 * subdirectory precisely so the two top-level, non-recursive globs never see them and never demand
 * sidecar files this feature doesn't need (`assets/tiny.mp4` already established that a
 * subdirectory here is exempt from those globs).
 *
 * Usage:
 *   node tools/pp7-gen-timeline-fixture.js
 *
 * Exit status: 0 = all three written, 1 = any failure (missing source fixture, encode error).
 *
 * @see appWeb/public_html/includes/propresenter7_decode.php   pp7DecodeTimeline()/pp7DecodeTimelineCue()
 * @see tests/php/test-pp7-timeline.php                        the consumer of this script's output
 * @see tests/fixtures/propresenter/README.md                  fixture provenance + why these are SYNTHESISED
 * @see tools/pp7-gen-zip64-bundle.js                           the sibling generator this mirrors
 * @see appWeb/public_html/manage/editor/protos/proto-7.16/presentation.proto   lines 71-90 (Timeline/Cue)
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
const SOURCE_PRO_PATH = path.join(FIXTURES_DIR, 'bussnet-test.pro');
// A subdirectory, deliberately NOT the top-level fixtures dir — see the "WHY A SUBDIRECTORY"
// note in this file's doc-block above.
const OUTPUT_DIR = path.join(FIXTURES_DIR, 'timeline');
const PROTO_BUNDLE = path.join(
  REPO_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

function main() {
  if (!fs.existsSync(SOURCE_PRO_PATH)) {
    console.error(`missing source fixture: ${SOURCE_PRO_PATH}`);
    process.exit(1);
  }
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  const root = protobuf.Root.fromJSON(JSON.parse(fs.readFileSync(PROTO_BUNDLE, 'utf8')));
  const Presentation = root.lookupType('rv.data.Presentation');
  const Timeline = root.lookupType('rv.data.Presentation.Timeline');
  const Cue = root.lookupType('rv.data.Presentation.Timeline.Cue');
  const UUID = root.lookupType('rv.data.UUID');

  const sourceBytes = fs.readFileSync(SOURCE_PRO_PATH);

  // bussnet-test.pro's own real cue UUIDs (verified during authoring: 5 cues, this exact order) —
  // reused so a decoded cue_id always resolves to a genuine cue already in the same file, rather
  // than a dangling/made-up id.
  const REAL_CUE_UUIDS = [
    'A18EF896-F83A-44CE-AEFB-5AE8969A9653',
    '5A6AF946-30B0-4F40-BE7A-C6429C32868A',
    '562C027E-292E-450A-8DAE-7ABE55E707E0',
    '8CEAD8E6-53F4-4DD0-98D4-526ACDCD5FAE',
    'EAFD8A38-77F2-49F7-8AED-61AE11207165',
  ];

  const mkCue = (triggerTime, name, uuidIndex) => Cue.create({
    trigger_time: triggerTime,
    name,
    cue_id: UUID.create({ string: REAL_CUE_UUIDS[uuidIndex] }),
  });

  const writeFixture = (filename, mutate) => {
    const msg = Presentation.decode(sourceBytes);
    mutate(msg);
    const bytes = Buffer.from(Presentation.encode(msg).finish());
    const outPath = path.join(OUTPUT_DIR, filename);
    fs.writeFileSync(outPath, bytes);
    console.log(`wrote ${bytes.length} byte(s) to ${outPath}`);
  };

  // -- synthetic-timeline-cues.pro: `cues` (field 1) populated, `cues_v2` empty, loop=true
  //    (non-default — proves the bool is actually read, not just defaulting false). --
  writeFixture('synthetic-timeline-cues.pro', (msg) => {
    msg.timeline = Timeline.create({
      duration: 217.5,
      loop: true,
      cues: [
        mkCue(0.787310792, 'Cue A', 0), // == real Rescuer (non-Extended) sample's cues[0].trigger_time
        mkCue(11.53149, 'Cue B', 1),
        mkCue(18.899, 'Cue C', 2),
      ],
    });
  });

  // -- synthetic-timeline-cues-v2-must-be-ignored.pro: `cues` (field 1) holds the CORRECT
  //    schedule; `cues_v2` (field 11) holds sentinel/wrong data that must never surface — proves
  //    the field-semantics fix documented in this file's own doc-block above. --
  writeFixture('synthetic-timeline-cues-v2-must-be-ignored.pro', (msg) => {
    msg.timeline = Timeline.create({
      duration: 42.0,
      loop: false,
      cues: [
        mkCue(1.0, 'Cue V1-A', 0),
        mkCue(2.0, 'Cue V1-B', 1),
        mkCue(3.0, 'Cue V1-C', 2),
      ],
      cues_v2: [
        // Mirrors the real files' cues_v2 shape: a Clear-action-like entry with trigger_time 0
        // and no cue_id, PLUS sentinel "cue-shaped" entries at obviously-wrong times — either
        // leaking into the decoded result would fail the guard.
        Cue.create({ trigger_time: 0, name: 'Clear All' }),
        mkCue(777.1, 'WRONG (cues_v2, must never appear)', 3),
        mkCue(777.2, 'WRONG (cues_v2, must never appear)', 4),
      ],
    });
  });

  // -- synthetic-timeline-absent.pro: field 17 deleted outright (true absence, not empty). --
  writeFixture('synthetic-timeline-absent.pro', (msg) => {
    delete msg.timeline;
  });

  console.log('done.');
}

main();
