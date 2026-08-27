# ProPresenter 7+ Interoperability — Implementation Plan (epic #1968)

> **Status:** PLANNING COMPLETE — implementation-ready. Written 2026-08-27 (Fable-5 planning pass,
> per `.claude/standing-directives.md` model routing; implementation = Sonnet/Haiku, Opus for the
> wire-walker + ZIP reader, GIRFT).
>
> **Ground truth for every byte-level claim in this plan** (do not re-derive; do not trust memory):
> - The owner's real v21.4 `.pro`/`.probundle` decode findings (session scratchpad
>   `PP7-GROUND-TRUTH.md`, summarised throughout below — the load-bearing facts are restated here
>   in full so this plan is self-contained).
> - Byte-verified open-source research (`PP7-RESEARCH.md`): full field numbers, the DUAL RTF
>   dialect, `.proplaylist` decode, ZIP64 EOCD quirk, and the 8+ real MIT fixtures cloned at
>   `/home/user/chrismbarr/propresenter-parser/` and `/home/user/bussnet/propresenter7-php-lib/`.
> - The repo's own vendored protos `appWeb/public_html/manage/editor/protos/proto-7.16/*.proto`
>   (field numbers quoted below were re-read from these files during planning, not assumed).
>
> **The owner's hard rule: "get the code right, first time — no more false positives."**
> Two prior export fixes (#1918, the first #1950 attempt) shipped green because they were
> validated against a **circular same-schema round-trip** (our encoder → our decoder → "matches")
> and then failed in real ProPresenter. The structural fix in this plan is §8: every importer is
> validated against **real third-party fixtures with committed expected output**, and the exporter
> is validated against **structural invariants derived from those same genuine files** — never
> against itself. No phase ships without its fixture coverage landing in the same PR.

---

## 1. Overview + phase map

ONE combined program (owner decision), phased **internally**. Each phase is independently
shippable and verified before the next begins.

| Phase | Delivers | Depends on | Epic #1968 mapping |
|---|---|---|---|
| **P0** (folds into P1's PR) | `.pro`→ChordPro mis-routing fix (content sniff), accept-list additions, server-side sniff in `import_file` auto-detect | — | #1968 child "Fix `.pro` extension mis-routing" (epic says it folds into #885's landing) |
| **P1** | `.pro` import: lyrics + structure (verses, section labels incl. rule-#45 display Labels, **arrangements** → `tblSongs.ArrangementJson`), CCLI metadata, dual-dialect RTF extraction, PHP wire decoder | P0 | **#885** (import .pro) + the "Golden reference fixture" child (harness lands WITH P1) |
| **P2** | `.probundle` import (tolerant ZIP64 reader; every inner `.pro` through P1; media entries counted + reported, not ingested) **+ export-side bundle-layout fix** (root-level `.pro`, no invented manifest) | P1 | #885 "bundle support follows" rider; new issue for the export layout fix (see §12.4) |
| **P3** | `.proplaylist` import → iHymns set list; set-list export → `.proplaylist` | P1, P2 (ZIP reader) | New child issue (see §12.4); relates to set-list family #94/#1791 |
| **P4** | Media ingest from `.probundle`: resolve refs → extract bytes → `tblSongMedia` (owner decision: **ingest & store**, linked to song); export writes portable `CURRENT_RESOURCE` URLs + clean ZIP | P2 | Epic "media" line; new child issue |
| **P5** | Themes (opt-in, additive): T1 built-in presets, T2 honour iHymns theme, T3 import-a-`.pro`-as-template (**#888**); "no theme" stays DEFAULT | P1 (decoder for T3) | **#888** |
| **P6** | Timeline / auto-advance + chord_chart (deferred — no chord-bearing sample exists; do not guess `chord_chart`'s shape) | P1 | Context issues #1080/#1082; NOT scheduled here |

**Cross-cutting, lands FIRST (inside P1's PR, commits ordered fixtures-before-importer):** the
golden-fixture corpus + field-diff harness (§8). The harness is the safeguard; the importer is
merely the feature.

**Explicitly NOT in scope (locked owner decisions):** re-doing export fix #1950 (DONE, merged to
alpha — Cocoa RTF); import of media/themes/timeline in P1; any storage-model change.

---

## 2. The decode architecture decision (client vs server)

**The decision.** Where the `.pro` protobuf is decoded for import: in the browser (a static
protobufjs decoder) or on the server (PHP).

**Why it needs deciding.** The browser **cannot** decode a `.pro` today: `pp7-proto-static.js` was
built `--no-decode` (251 encode methods, 0 decode — verified in `tools/build-proto-static.js`,
which documents the trim), and the reflection path (`protobuf.Root.fromJSON`) does lazy
`new Function()` codegen that the enforcing nonce CSP (#117) refuses — the exact reason the static
module exists (#1788). So decode capability must be *built*, and where we build it shapes every
later phase (bundles, media, playlists).

**Options.**

| | Option | Consequences |
|---|---|---|
| **A** | **Server-side PHP decode — a targeted hand-rolled proto3 wire-walker** | Matches the existing import architecture exactly (every importer — Pro6, ChordPro, OpenLyrics, EasyWorship, FreeShow, PPTX — is a server-side POST through `includes/song_importers.php` → `_bulkImport_saveSong()`); CSP-irrelevant; zero client bundle growth; naturally hosts the `.probundle` ZIP64 reader, media byte extraction into `tblSongMedia`, and `.proplaylist` parsing (all inherently server concerns); testable in `tests/php/` with committed binary fixtures and **cross-validated against protobufjs (an independent implementation) decoding the same real files** — a structural defence against the false-positive class. Cost: we own ~250 lines of wire-format code. |
| **B** | Client-side decode (regenerate the static module without `--no-decode`) | Adds decode longhand for the ENTIRE schema tree (~hundreds of KB on an already 787 KB artifact); diverges from the server-side import pattern (the parsed song must then be POSTed anyway — the server would have to trust a client-parsed payload or re-parse); does nothing for `.probundle`/`.proplaylist` ZIP64 or media ingestion, which need server code regardless. |
| **C** | Server-side via the pure-PHP `google/protobuf` Composer package + generated classes (#885's original Option A) | The repo has **no composer flow anywhere** (verified: zero `composer.json` in the tree; mPDF was vendored by hand under `private_html/lib/pdf/`); generated classes for the full 7.16 tree are large and churn on every schema refresh; the runtime is a real dependency to track for maybe 8 field paths. |
| **D** | Do nothing (leave `.pro` routed to ChordPro) | The latent bug stands: real PP7 files are silently mis-parsed as text. Untenable. |

**Recommendation: A — server-side PHP, hand-rolled targeted walker.** Proto3 wire format is small
(4 wire types we care about: varint 0, 64-bit 1, length-delimited 2, 32-bit 5), our field numbers
are **byte-verified twice over** (owner's real v21.4 samples decoded with the repo's own 7.16
descriptor; independent fixtures cross-checked), and PP7-RESEARCH §E confirms the song-relevant
messages are wire-identical 7.16.2 → 21.4 (all diffs additive; unknown fields are skipped by
design in the walker, so future PP versions degrade gracefully). #885's stated worry about a
hand-walker ("more brittle to schema bumps") is answered by the harness: the walker is pinned to
real files, not to a schema we imagine. Option B is *additionally* adopted only where encode
already lives client-side (P3's playlist **export** and P5's presets — encode-only additions to the
existing static module, no decode ever added to it).

**What's needed to proceed:** nothing — this is an architecture call inside the locked program;
recorded here as decided. (Flagged per the CLAUDE.md decision protocol: does not block the owner.)

### 2.1 The walker's design contract (`includes/propresenter7_decode.php` — NEW)

A pure, DB-free library (mirrors `song_similarity.php`'s "pure maths, unit-testable" posture; same
direct-access guard block every `includes/` library carries).

```
pp7WireWalk(string $buf, int $off, int $len): array          // low-level: list of [field, wireType, value|byteslice]
pp7DecodePresentation(string $bytes): array                  // the ONLY high-level entry for .pro
  → {
      applicationInfo: {platform:int, applicationVersion:?string},
      name: string, category: string, notes: string,
      selectedArrangement: ?string,                          // UUID string or null
      arrangements: [{uuid, name, groupIdentifiers:[uuid,…]}],
      cueGroups:    [{groupUuid, groupName, cueIdentifiers:[uuid,…]}],
      cues:         [{uuid, slideRtf:[bytes,…],              // rtf_data of each text element, in element order
                      slideElementInfos:[int,…],             // Slide.Element.info bitmask per element
                      mediaRefs:[{absoluteString,localRoot,localPath},…]}],
      ccli: {author, artistCredits, songTitle, publisher, copyrightYear:?int, songNumber:?int},
      hasTimeline: bool, hasChordChart: bool                 // presence flags only (P6 fodder)
    }
```

Hard rules:
- **Field tables are constants at the top of the file**, one per message, each line commented with
  the vendored `.proto` source line it mirrors. Verified numbers (all re-read from
  `protos/proto-7.16/*.proto` during planning):
  - `Presentation`: 1 application_info, 2 uuid, 3 name, **6 category**, **7 notes**, 8 background,
    9 chord_chart, 10 selected_arrangement, 11 arrangements, 12 cue_groups, 13 cues, 14 ccli,
    17 timeline (presentation.proto).
  - `Presentation.Arrangement`: 1 uuid, 2 name, 3 group_identifiers (repeated UUID).
  - `Presentation.CueGroup`: 1 group (rv.data.Group), 2 cue_identifiers (repeated UUID).
  - `Group`: 1 uuid, 2 name, 3 color, 4 hotKey (groups.proto). **The arrangement link is
    `Arrangement.group_identifiers[] → CueGroup.group.uuid`** — the *Group's* uuid; CueGroup has no
    uuid of its own. Duplicates are normal (Chorus ×3 in an order).
  - `Presentation.CCLI`: 1 author, 2 artist_credits, 3 song_title, 4 publisher,
    5 copyright_year (u32), 6 song_number (u32), 7 display (bool), 8 album, 9 artwork (bytes).
  - `Cue`: 1 uuid, 10 actions, 12 isEnabled (cue.proto).
  - `Action`: 9 type (enum: **11 = ACTION_TYPE_PRESENTATION_SLIDE, 2 = ACTION_TYPE_MEDIA,
    23 = ACTION_TYPE_MACRO**), oneof data: **20 media**, **23 slide** (action.proto — note enum
    value 23 and field number 23 are different namespaces; the comment in code must say so).
  - `Action.SlideType`: 2 presentation, 3 prop (field 1 + name "template" are **reserved** — the
    removed per-slide theme ref; load-bearing for P5's "no theme ref exists in a .pro").
  - `PresentationSlide`: 1 base_slide, 2 notes, 4 chord_chart.
  - `Slide`: 1 elements, 6 size, 7 uuid. `Slide.Element`: 1 element, **4 info** (bitmask:
    1 = IS_TEMPLATE_ELEMENT, 2 = IS_TEXT_ELEMENT, 4 = IS_TICKER).
  - `Graphics.Element`: 1 uuid, 2 name, 3 bounds, 13 text. `Graphics.Text`: 3 attributes,
    **5 rtf_data (bytes)**, 6 vertical_alignment, 12 chord_pro, 13 alternate_texts.
  - `Action.MediaType`: 5 element (rv.data.Media); `Media`: 1 uuid, 2 url, 3 metadata.
  - `rv.data.URL`: 1 absolute_string, 2 relative_path, 3 platform, 4 local
    (`LocalRelativePath`: 1 root, 2 path; Root enum 2=USER_HOME, 3=DOCUMENTS, 10=SHOW,
    **12=CURRENT_RESOURCE**), 5 external.
  - `ApplicationInfo`: 1 platform (1=macOS, 2=Windows), 4 application_version.
  - `UUID`: 1 string.
- **Unknown fields/messages are skipped by wire type** (never an error) — this is what makes a
  v25 file decode with a 7.16 table.
- Defensive limits: max nesting depth 32; every length-delimited slice bounds-checked against the
  buffer; total input ≤ 25 MiB (matches `import_file`'s cap); malformed input →
  `InvalidArgumentException` with byte offset (surfaces as the importer's clean 400, per api2's
  `$userFacing` convention).
- **Field NAMES in the repo descriptor are snake_case** — irrelevant to the walker (numbers only),
  but every JS-side decode in the harness (§8) MUST access `cue_groups`, `base_slide`, `rtf_data`,
  `group_identifiers`, `cue_identifiers`, `selected_arrangement` in snake_case (`keepCase: true`
  bundle) — camelCase reads return `undefined` silently. Documented in the harness helper once.

---

## 3. P1 — `.pro` import (lyrics + structure + arrangements), file by file

### 3.1 Routing fix (P0) — `manage/editor/editor.js`

Current bug (verified at L4085): `.pro` is lumped into the ChordPro extension branch
(`.cho/.chopro/.crd/.chord/.pro → importChordPro`). `.pro` is genuinely ambiguous (ChordPro's
docs bless `.pro`; PP7 owns it too), so the fix is a **content sniff**, not an extension re-map:

1. In `importJSON()`'s change handler, the `.pro` case becomes:
   `file.slice(0, 4096).arrayBuffer()` → `sniffProContent(bytes)`:
   - bytes decode as UTF-8 **and** contain no control bytes outside `\t\r\n` → text:
     - starts with `<?xml` or contains `<RVPresentationDocument` → route to `importPro6(file)`
       (a mis-extensioned `.pro6` imports gracefully instead of erroring — supersedes #885's
       "reject with a message");
     - otherwise → `importChordPro(file)` (unchanged behaviour for genuine ChordPro `.pro`).
   - anything else (NUL/control bytes, invalid UTF-8 — every real PP7 file trips this within the
     first ~100 bytes: varint lengths, float colour fields) → **`importProPresenter7(file)`** (new
     thin wrapper over `importSingleFileFormat`, action `bulk_import_pro7`, field `pro7`).
2. Accept list (~L4053): add `.probundle,.proplaylist` (P2/P3 forward-wired but routed to a
   "coming in the next update" toast until their phases land — do NOT advertise before the server
   handler exists; each phase's PR adds its own routing branch when its handler ships).
3. The client sniff is a convenience only — **the server re-sniffs authoritatively** (§3.2). A
   spoofed or wrong client route cannot corrupt data: the pro7 handler rejects non-protobuf, the
   chordpro handler rejects binary garbage as it always has.

Same fix on the **v2 surface**: `manage/editor/import2.php` (add a
`'pro7' => 'ProPresenter 7+ (.pro)'` dropdown entry; the `.pro` mention moves out of the ChordPro
label) and `manage/editor/api2.php` `import_file` (~L5462): in the `format=auto` branch, `'pro'`
no longer maps straight to `'chordpro'` — it maps to an internal `'proauto'` target that
content-sniffs the body exactly as above (binary → `pro7`, XML root → `pro6`, else `chordpro`) —
the **same precedent as `'xmlauto'` (#882) and the `.json` sniff (#1633)** in the very same
function. `'pro7'` also becomes an explicitly pickable format (operator override, same #1633
posture). Add `'pro7'` to `$bodyFormats` and a `'pro7' => _bulkImport_processPro7($content,
$origName)` match arm.

ZIP path rider: `_bulkImport_processZip()`'s extension router (~L1531) gains
`elseif ($ext === 'pro') { $kind = 'proauto'; }` with the same three-way body sniff at the entry
loop — a folder of `.pro` files zipped up imports like `.pro6`/`.show` folders do. (The ZIP
bomb caps are already generous for `.pro`: real files are 1–30 KB.)

### 3.2 Server handler — `manage/editor/api.php` + `includes/song_importers.php`

New api.php case `bulk_import_pro6`-shaped (copy that case's structure verbatim — upload field
`pro7`, 10 MiB cap [largest real fixture is 30 KB; 10 MiB is 300× headroom], `_bulkImport_dedupeMode`
wiring, `songbookMaintenanceRun` on create, `logActivity`). **Gates come free**: api.php's
top-of-file session + `hasRole('editor')` gate (L39–52) and the file-wide POST
`validateCsrfRequest()` gate (L131–137, rule #29) cover every case; `importSingleFileFormat()`
already sends `X-Requested-With`. Nothing bespoke to add — state this in the PR description so
review doesn't hunt for a missing gate.

In `song_importers.php` (the shared home — #1200 Phase 4b requires both APIs reuse one parser):

- `_bulkImport_processPro7(string $body, ?string $filenameHint): array` — mirrors
  `_bulkImport_processPro6()` line-for-line in shape: parse → songbook upsert → number → assemble →
  `_bulkImport_saveSong()` → summary. Songbook: name **"ProPresenter 7 Import"**, abbr **`PP7`**
  (bracketed-filename-token override via `_bulkImport_videopsalmAbbrevFromHint`, exactly like PP6's
  precedent at L3883-3888 — distinct from PP6's book so provenance stays legible).
- `_bulkImport_parsePro7(string $body): array{0:?array,1:?string}` — **pure** (no DB), returns the
  neutral parsed structure + `warnings[]`. This purity is load-bearing: §8's decoder test runs it
  against fixtures with no database.

### 3.3 The parse walk (inside `_bulkImport_parsePro7`)

1. `pp7DecodePresentation($body)` (throws → `[null, reason]`).
2. **Category gate**: reject when `category` is non-empty and ≠ `'song'` (case-insensitive) —
   "This is a ProPresenter <category> document, not a song." The owner's three genuine song
   samples carry NO category field (proto3 default `''`), so absent must pass; #885's Scripture/
   Notes rejection still works when the field is set.
3. **Section palette** — one iHymns component per `cueGroups[]` entry, in `cue_groups[]` order:
   - Skip groups named `Song Title` / `Lyrics Background` (case-insensitive exact match — the two
     non-lyric groups observed in the owner's real files; each skip appends a `warnings[]` line).
   - Label mapping (§3.5) → `{type, number, label}`.
   - Lines: for each uuid in `cueIdentifiers` → find cue by uuid (missing → warn + skip) → take the
     cue's **lyric RTF** (§3.4 element selection) → `_bulkImport_rtfToText()` → split on `\n`,
     `rtrim` each line, append (the exact Pro6 concatenation idiom at L3798-3806: skip leading
     empties, trim trailing empties). Multiple cues per group (a verse chunked over 3 slides)
     concatenate — matching how the exporter chunks with `linesPerSlide`.
   - A group whose every cue is empty is dropped (Pro6 precedent) with a warning.
4. **Unreferenced cues** (in `cues[]` but in no group): appended after the palette as sequential
   `verse` components with a `warnings[]` note (#885 acceptance bullet 4).
5. **Arrangement** → `ArrangementJson`:
   - Pick: `selectedArrangement` when it resolves to a real arrangement; else the first arrangement
     named `CCLI`/`Standard`/`Original` (case-insensitive); else `arrangements[0]`; else none.
     (**Dangling `selected_arrangement` is real**: `v7 - Feature Test.pro` sets it with
     `arrangements[]` empty — advisory only, never an error.)
   - Map each `groupIdentifiers` uuid → the palette component's **index** (0-based position among
     the components actually created — skipped/empty groups shift indices, so the map is built from
     the surviving component list, not the raw cue_groups array). Unresolvable uuids → warn + drop
     that entry. Repeats are legal and expected (`arrangementSanitise()` explicitly allows a
     refrain between every verse).
   - Result array goes into the parsed song as `'arrangement' => [ints]`;
     **`_bulkImport_assembleSong()` must be extended** to pass `'arrangement'` through (one line —
     `_bulkImport_saveSong()` already persists it via `_sanitiseArrangement()` under the
     `ArrangementJson` column probe, L591-634; today no parser feeds it, this is the first).
   - If the mapped arrangement is exactly `[0,1,2,…,n-1]` (identity), store NULL (no arrangement)
     — matches the render fallback and avoids noise on the ~common case.
6. **Metadata** from `ccli`: `song_title` → title (fallback: `Presentation.name`; then first line;
   then filename stem — the Pro6 ladder), `author` → `writers[]` (split on `/&,;` — Pro6's exact
   regex L3786), `publisher` + `copyright_year` → `copyright` (Pro6's composition L3782),
   `song_number` → `ccli`. `artist_credits` is NOT imported (no importer writes `tblSongArtists` —
   it is #587-migration-gated and deliberately omitted from `_bulkImport_saveSong`'s credit block;
   note in `warnings[]` when present so nothing is silently dropped).
7. Persistence is **entirely** `_bulkImport_saveSong()` — which writes components through
   **`lyricLinesWriteComponents()`** when `lyricLinesSyncReady()` (rule #25's ONE write path,
   L713-715) with the marked `lines-json-fallback` branch un-migrated, INSERT-only semantics,
   credits via `creditEntryNormalise()`/`musicianPromote()`, revision row, activity log. **The PP7
   importer adds zero writes of its own.** Component dicts carry
   `{type, number, lines, label?}` — `label` is presence-gated in the writer
   (`labelProvided = array_key_exists('label', $c)`, lyric_lines_sync.php L549-553): the parser
   only sets the key when a display label survives §3.5, so omission preserves, exactly per
   rule #45.

### 3.4 Element selection (translations, empty slides, multiple text boxes)

Per cue, the decoder returns every element's `rtf_data` + its `Slide.Element.info` bitmask.
Selection rule: **first element whose info has bit 2 (IS_TEXT_ELEMENT) set and whose rtf_data is
non-empty; fallback first element with non-empty rtf_data; else empty.** Elements beyond the first
are **translation layers** (`TestTranslated.pro` is the real fixture; element name strings are
arbitrary user text — never match on them): P1 imports element 0 only and appends ONE summary
warning ("N translation layers present — not imported; see #<followup>"). The follow-up issue
(§12.4) notes the eventual home is `tblLyricLineTranslations` anchored on `tblLyricLines.Id`
(rule #21) — never a parallel-array bolt-on.

### 3.5 Section-label mapping — `_bulkImport_pro7GroupType(string $label): array{type, number, label:?string}`

The reverse of the exporter's `COMPONENT_LABEL_MAP` (propresenter-export.js L437-448), widened to
the observed real-world vocabulary. A NEW function — do **not** reuse `_bulkImport_pro6GroupType()`
whose alias table (`tag→outro`, `coda→outro`, `interlude→refrain`) predates the #1138
`tblSongPartTypes` registry; changing PP6's fold would silently re-type existing round-trips.
Doc-block must state this divergence and why.

1. Parse `^(?<word>.*?)[\s_-]*(?<num>\d+)?\s*(?:\((?<suffix>[^)]*)\))?\s*$` — base word, optional
   number, optional parenthesised suffix (the `Verse 1 (SDAH)` hymnal-variant shape from the
   owner's real files; the suffix is arrangement-scoping noise for TYPE purposes).
2. Fold the base word (case-insensitive) against a static map targeting the **16 seeded
   `tblSongPartTypes` slugs** (migrate-song-part-types.php L74-76): verse, chorus, refrain, bridge,
   pre-chorus (+`prechorus`, `pre chorus`), **tag** (a real slug — NOT outro), **coda**, intro,
   outro (+`ending`), interlude, vamp, instrumental, breakdown, plus exporter-emitted names
   (`Pre-Chorus`, `Tag`, `Coda`, `Intro`, `Outro`, `Interlude` — closure with
   `COMPONENT_LABEL_MAP` is CI-enforced, §11.4). Short forms too: `V`,`C`,`B`,`P`,`T`,`I`,`O`
   (+number) — PP operators use both.
3. Unknown base word → type `refrain` (#885's stated fold; consistent with
   `_bulkImport_componentTypeFor`'s "non-English refrain labels" rationale) **with
   `label` = the raw group name** so nothing is lost (rule #45: `Type` structural, `Label`
   display-only).
4. Suffix present, or raw name ≠ the derived "`ucfirst(type) number`" display → set `label` = raw
   group name. (The server-side D1 hide-when-equal in `component_upsert` stores NULL when a label
   equals the derived form; the importer applies the same equality check locally so it never sends
   a redundant label — cite rule #45.)

### 3.6 The dual-dialect RTF text extractor

**Both dialects are written by ProPresenter itself** (re-verified on the real fixtures):
- Mac: `{\rtf1\ansi\ansicpg1252\cocoartf<ver>\cocoatextscaling0\cocoaplatform0…` with the Cocoa
  soft return — a backslash immediately followed by a raw newline — as the line break
  (`\cocoartf2761/2865/2870` observed).
- Windows (`v7 - Feature Test.pro`, PP 7.13.2/Win): `{\rtf0\ansi\ansicpg1252{\fonttbl…` — **`\rtf0`**,
  no `\cocoartf`, `\par` breaks, `\csgenericrgb` colours, `\strokewidth`/`\strokec`/`\highlight`/`\cb`.

**Implementation: EXTEND the existing shared `_bulkImport_rtfToText()`** (song_importers.php
L3560-3692 — already the Pro6/EasyWorship/Proclaim decoder with group/`\uc`/`\uN`/destination
handling), rather than a fork (modularity rule). Three targeted changes, each a strict correctness
fix for the existing consumers too:

1. **`\` + CR/LF → newline.** Today the "other control symbol" branch (L3660-3662) swallows it —
   the Cocoa soft return would silently join every line. Per the RTF spec an escaped CRLF is a
   `\par` equivalent, so this also fixes any Cocoa-flavoured `.pro6`/EasyWorship RTF.
2. **`\'xx` → cp1252-aware UTF-8** instead of raw `chr()` (L3592 currently emits invalid UTF-8
   bytes for 0x80–0xFF — a latent mojibake bug for Windows-authored RTF in EVERY current importer;
   file the retrospective issue, §12.4). Use a small static 0x80–0x9F remap table + `mb_convert_
   encoding` for the rest (Windows-1252, matching `\ansicpg1252` which every real fixture declares).
3. **Surrogate-pair `\uN` handling.** RTF encodes supplementary-plane chars as two signed-16-bit
   `\uN?` escapes (high then low surrogate). The current code calls `mb_chr()` per half → `false`
   → both dropped. Buffer a high surrogate (0xD800–0xDBFF after +65536 normalisation) and combine
   with the following low surrogate. (Rare in hymnody, cheap to do right, and a §8 truth-table row.)

**Contract** (documented on the function): input = one RTF document (either dialect, or plain RTF
from PP6/EW/Proclaim); output = UTF-8 plain text, lines separated by `\n`; header/table/ignorable
destinations contribute nothing; formatting words strip; unknown control words strip; never throws.

**Unit truth table** (new `tests/php/test-pp7-rtf-extract.php`; every "real" row's input is a
byte-slice lifted from an actual fixture, not typed from memory):
- Cocoa 2-line body (from `v7 - At the Cross.pro`): soft-return split → exactly 2 lines.
- Windows body (from `v7 - Feature Test.pro`): `\par` split; `\csgenericrgb`/`\strokewidth` etc.
  contribute nothing.
- `\'e9` → `é`; `\'93…\'94` (cp1252 smart quotes, the 0x80–0x9F block) → `“…”`.
- `\u8217?` → `’` (U+2019); a surrogate pair (e.g. `\u-10179?\u-8704?`) → the single
  supplementary character.
- `\uc0\u8232 ` (Cocoa line-separator idiom, uc0 = no fallback char) → still handled
  (U+2028 LINE SEPARATOR → normalise to `\n`; add LS/PS → `\n` inside the `u` case).
- Escaped `\{ \} \\`; `{\*\expandedcolortbl;;}` skipped; `{\fonttbl…}` skipped.
- **Non-regression rows**: the existing Pro6 + EasyWorship extraction expectations (lift 2-3 cases
  from those importers' current fixtures/behaviour) stay byte-identical.

### 3.7 Error handling

Rule #5/#9 posture: the DB layer throws (`MYSQLI_REPORT_STRICT`); `_bulkImport_saveSong` already
wraps per-song in try/catch → `['fail', msg]`; the processor's summary carries `errors[]`. Decoder
`InvalidArgumentException` → the handler's clean 400 with the offset-bearing message. `warnings[]`
(skipped groups, translation layers, unresolvable arrangement uuids, artist_credits) ride the
summary so the curator sees an honest report — the epic's silent-failure allergy applies to
imports too.

---

## 4. P2 — `.probundle` import

**Format facts (owner's real v21.4 bundle, byte-verified):** it IS a ZIP, but ZIP64 with a broken
end-of-central-directory (`unzip` AND Python `zipfile` both reject it; bussnet documents the same
98-byte EOCD error on PP7 exports and repairs it). Entries are **STORED (method 0)**; real sizes
live in the **ZIP64 extra field (id 0x0001)** when the 32-bit fields are 0xFFFFFFFF. Layout:
`.pro`(s) at ROOT; media stored under its **original absolute path** as the entry name; **no
manifest file** — the inner `.pro` IS the manifest.

### 4.1 The tolerant reader — `includes/propresenter7_zip.php` (NEW)

`pp7ZipExtractEntries(string $path, array $opts): array{entries:[{name, size, read:callable}], method:string}`

1. **First, try `\ZipArchive`** against the file (cheap, and PP-*imported* clean zips or
   hand-rezipped bundles open fine). The implementation task INCLUDES running ZipArchive against
   the committed real bundle fixtures and recording the result in the test — PP7-GROUND-TRUTH
   predicts rejection ("verify during implementation"; the fallback exists either way).
2. **Fallback: sequential local-file-header scan** (the proven `pb-extract3` algorithm):
   walk `PK\x03\x04` signatures from byte 0; parse each header (name len, extra len, method,
   csize/usize); when sizes are 0xFFFFFFFF read the ZIP64 extra (0x0001); method 0 → bytes sit
   inline after the header; method 8 → `gzinflate()` the compressed span. General-purpose bit 3
   (data descriptor, sizes deferred) with method 0 cannot occur in a sane writer; if met, bail to
   an error naming the entry (never guess span boundaries).
   Never read the central directory at all — that is the broken part.
3. Caps (mirror the #682 bomb posture): ≤ 10,000 entries; per-`.pro` entry ≤ 10 MiB; cumulative
   ≤ 2 GiB *declared* but P2 only **materialises** `.pro` entries (media entries are enumerated —
   name + size — with their byte ranges recorded, not read; P4 reads them lazily via the `read`
   closure).

### 4.2 Import flow

api.php case `bulk_import_probundle` + api2 `import_file` format `probundle` (auto: extension
`.probundle`), + `editor.js`/`import2.php` routing. Handler: extract → for each root-level
`*.pro` entry (basename has no `/` after normalisation) → `_bulkImport_processPro7()` per song →
aggregate one summary (the multi-song summary shape `_bulkImport_processZip` returns). Media
entries: count + total size reported in the summary (`media_present: N`) with a "media ingest
lands in a later update (P4)" note. Non-root `.pro` entries (none observed in real bundles) are
imported the same with a warning.

### 4.3 Export-side layout fix (the dormant false positive)

`exportAllAsBundle()` (propresenter-export.js L1312-1353) currently invents a layout —
`Documents/<file>.pro` + a root `manifest.json` — with an honest "known unknowns" comment admitting
the schema was guessed. **Genuine bundles have `.pro` at root and no manifest.** This export has
never been verified in real PP; it is exactly the class this program exists to kill. Fix: emit
every `.pro` at root, drop `manifest.json` entirely, keep our (already-correct, clean-EOCD) stored
ZIP writer. Owner verify on real PP (§10 checklist gains a `.probundle` row). The
PROPRESENTER-TESTING.md §4b expected-layout text updates in the same commit (it currently
documents the invented layout as truth).

---

## 5. P3 — `.proplaylist` import/export

**Format facts (byte-verified on bussnet's 4 genuine PP20 exports; ⚠️ no raw PP7-exported playlist
from the owner yet — UNCONFIRMED items flagged):** a ZIP64 container (same EOCD quirk, same
tolerant reader), entries: `data` = protobuf `rv.data.PlaylistDocument` + one `<name>.pro` per
referenced presentation at root (dedup by filename) + media at absolute paths.
`PlaylistDocument{1 application_info, 2 type (1=PRESENTATION), 3 root_node, 4 tags}` (verified in
vendored `propresenter.proto`); `Playlist{1 uuid, 2 name, 3 type (1=PLAYLIST,2=GROUP,3=SMART,
4=ROOT), oneof children: 12 playlists, 13 items}`; `PlaylistItem{1 uuid, 2 name, oneof ItemType:
3 header, 4 presentation, 5 cue, 6 planning_center, 8 placeholder; 9 is_hidden}`;
`PlaylistItem.Presentation{1 document_path (URL), 2 arrangement (UUID), 4 user_music_key}` — plus
**`arrangement_name = 5` (string, Pro19+, absent from the vendored 7.16 protos — ADOPT: add the
field to `playlist.proto` additively and rebuild the bundle + static module; wire-compatible)**.

### 5.1 Import (server-side; curator surface first — owner decision D2, §12.3)

api.php case `bulk_import_proplaylist` (+ api2/import2/editor.js routing):
1. Tolerant ZIP → `data` entry → walker tables for the playlist messages (extend
   `propresenter7_decode.php` with `pp7DecodePlaylistDocument()`).
2. Walk `root_node` ("PLAYLIST", TYPE_ROOT) → child playlists/items recursively. ⚠️ UNCONFIRMED:
   the always-one-child-playlist assumption and how nested folders (TYPE_GROUP) export — the
   walker must handle arbitrary nesting (flatten, prefixing nested playlist names into header
   markers) rather than assume.
3. Per `presentation` item: resolve to a ZIP `.pro` entry by **url-decoded basename** of
   `document_path` (the research-verified matching rule; fallback: longest suffix match of
   `local.path`). Found → import via `_bulkImport_processPro7` (INSERT-only dedupe means existing
   songs resolve to their existing SongId — resolve the created-or-skipped SongId back out of the
   save step; `_bulkImport_saveSong` must therefore return the SongId alongside the action —
   a small, additive signature change: `['create'|'skipped'|'fail', err, songId]`, with every
   existing caller ignoring the third element). Not found in ZIP → try catalogue match by CCLI
   number then normalised title (`ihymns_normalize_title`), else a placeholder entry in the set
   list notes.
4. Per `header` item → a set-list header/divider. `cue`/`planning_center`/`placeholder` items →
   skipped with warnings (named types, no "announcement" type exists).
5. Build the set list: server-side INSERT into **`tblUserSetlists`** for the importing curator
   (client-generated-style `SetlistId` minted server-side; `Name` = playlist name; `SongsJson` =
   the standard `{id,title,songbook,number}` refs). ⚠️ Verify during implementation: the exact
   sync/merge direction by which the public app's localStorage picks up a server-created row
   (`js/modules/setlist.js` + its sync endpoints) — if the client sync is push-dominant, the
   fallback is returning the set-list JSON in the response and having import2.php write
   localStorage directly (same origin — `/manage` and `/` share the docroot). Headers: if the
   `SlotsJson` envelope model fits, headers become slots; else prefix-marker entries; decide at
   implementation, default = simplest that renders.

### 5.2 Export (client-side, from the set-list UI)

Set lists live client-side; `setlist.js` already imports `fetchSong` from `./print.js` (used by
set-list print) — the export reuses it per song:
1. Add `propresenter.proto` (and its import `playlist.proto`) to `ENTRY_POINTS` in
   `tools/build-proto-bundle.js`; rebuild bundle + static module (`npm run build:proto` +
   build-proto-static) — **encode-only additions; the static module stays `--no-decode`**.
2. New `exportSetlistAsProPlaylist(setlist)` in propresenter-export.js: per song `fetchSong()` →
   `buildPresentation()` → `<filename>.pro` entries (dedup by filename, `ensureUniqueNames`);
   build `PlaylistDocument{application_info, type: PRESENTATION, root_node: {type: ROOT, name:
   "PLAYLIST", playlists: {playlists: [{type: PLAYLIST, name: <setlist name>, items: {items:
   [...]}}]}}}` — **byte-mirror the shape of the committed `TestPlaylist.proplaylist` fixture**
   (incl. its `document_path` URL form — read from the fixture during implementation and
   replicate exactly; do NOT invent a URL shape). Header items from set-list headers.
3. Wrap `data` + `.pro` entries with the existing clean `buildZip()`; download
   `<Setlist name>.proplaylist`.
4. ⚠️ Flag in the UI changelog + testing doc: export verified against fixture-shape only until the
   owner runs a real-PP import (§10 checklist row).

Content-gating note: the public set-list surface fetches through gated `song_detail`; a gated-off
lyric exports as empty slides — pre-existing behaviour of every public export, unchanged (gating
is dormant, rule #28).

---

## 6. P4 — media ingest (owner decision: ingest & store, linked to the song)

**Facts:** media = an `ACTION_TYPE_MEDIA` action on a cue carrying `media.element.url`
(`absolute_string` = percent-encoded `file://` URL; `local.path` = home-relative; the same values
mirrored under `media.element.video.file.local_url`). ZIP entry name == absolute path with scheme
stripped + percent-decoded. Three observed entry layouts (external-media absolute path;
in-library `Media/x.png` where exact string match is unreliable BY DESIGN; portable
`CURRENT_RESOURCE` flat form) → **resolve by url-decoded basename, fallback longest-suffix of
`local.path`** — the rule that covers all three. Bundles with zero media are valid.

### 6.1 Import

Extend `bulk_import_probundle` (P2 handler): after each inner `.pro` imports, walk its
`mediaRefs`, resolve each to a bundle entry, then:
1. Read bytes via the entry's lazy `read` closure (P2 deliberately deferred materialisation).
2. `SongMediaStorage::validateUpload`-equivalent on bytes: finfo MIME sniff (never the extension),
   size caps, kind derivation: video/* → NEW kind `'video'`; image/* → NEW kind `'image'`;
   audio/* → existing `'audio'`.
3. Registry additions in `SongMediaStorage` (all app-level — **no DDL**: `Kind` is
   `VARCHAR(20)` app-validated, widened from ENUM in #1090 for exactly this): `'video'`,`'image'`
   → `FS_KINDS` (filesystem backend; videos exceed MEDIUMBLOB), `ALLOWED_MIMES` (video/mp4,
   video/quicktime, video/webm; image/jpeg, image/png, image/webp), `SIZE_CAPS` (video 200 MiB,
   image 10 MiB — owner-confirmable defaults, decision D1 §12.3).
4. INSERT via the `media_upload` case's exact idiom (api2.php L5123-5163): SortOrder append,
   `ilidStampNewRow`, `ed2_touchRevision`, `songMediaRecomputeFlags` (derives HasAudio/HasSheetMusic
   only — video/image don't flip flags; verify the fold ignores unknown kinds rather than throwing),
   `logActivity('song-media.upload', …, source: bulk_import_probundle)`. Dedupe by `Sha256` per
   song+kind (the indexed column exists) so a re-import doesn't double-store.
   `Annotation = 'ProPresenter import: <original basename>'`.
5. Gating (rule #28): `contentGatingMediaAllowed($kind,…)` routes through the ONE resolver
   (`accessMediaAllowed`) — register the new kinds in the resolver's kind→cap mapping
   (verify exact map location in `access_resolver.php` during implementation), defaulting to the
   most conservative existing media cap; MUST remain a verified no-op while
   `content_gating_enabled='0'` (rule #28 A). Serving: `song-media.php` streams by row;
   `streamRange` already handles FS media (audio precedent) — confirm range headers for video.
6. Copyright/size design: imported backgrounds are frequently **licensed motion loops** — storage
   is the owner-locked decision; *exposure* default is decision D1 (§12.3, recommended:
   admin-surface visible immediately, public serving of `video`/`image` kinds withheld until the
   owner opts in — a one-line kind filter at the public media-list emit).

### 6.2 Export (the portable form)

When a song has bundle-eligible media (later UI decision — P4 export ships the *capability* behind
the bundle exporter): write the media entry at ZIP root under its flat filename and emit
`URL{absolute_string: <filename>, local: {root: 12 /*CURRENT_RESOURCE*/, path: <filename>}}` on
BOTH the `Media.url` and the type-specific `…video.file.local_url` mirror (research: the mirrored
FileProperties URL must be kept in sync; `URL.local` is MANDATORY — PP shows "media not found"
without it). Verify the emitted message shape against `TestBild.probundle`'s decoded media message
field-for-field before calling it done.

---

## 7. P5 — themes (opt-in, additive; default = NO theme)

**Facts:** there is NO theme reference in a `.pro` (`Action.SlideType` has `reserved "template";
reserved 1;`); per-slide styling is inline on elements. "Apply theme" in PP is **element cloning**
from a `Template.Document` (a folder file literally named `Theme`; exported `.proTheme` = ZIP of
that + assets). "Looks" are workspace config, not interchange — out of scope permanently.

Three tiers, all export-side, all opt-in (the current "no styling — operator applies their Look"
stays the DEFAULT and the recommendation in UI copy):

- **T1 — built-in presets.** A small preset registry in propresenter-export.js (e.g. `default`
  [current constants], `large-print`, `stage-dark`): each preset overrides the SECTION 5c
  constants (font name/size/colour, margins). Because `buildRTF()` **derives its header from those
  same constants** (the #1950 rule-#35 lockstep), presets flow into both the RTF and
  `text.attributes` automatically — no new mechanism.
- **T2 — honour the iHymns display/print theme.** Map the app's theme tokens (the print-template
  family, `includes/print_template_schema.php` vocabulary) onto a preset at export time. Additive
  UI: a "Match my iHymns theme" choice beside the presets.
- **T3 — import-a-`.pro`-as-template (#888).** Server action `pp7_template_extract` (editor-gated,
  POST, multipart): decodes the uploaded `.pro` with the P1 decoder, finds the first
  PresentationSlide-bearing cue, returns a compact styling JSON `{slideSize, backgroundColor?,
  bounds, font{name,size}, textColor, verticalAlignment, scaleBehavior, alignmentFromRtf}`
  (alignment recovered from the template RTF's `\qc/\ql/\qr`, since we will NOT copy
  paragraph_style — next bullet). Client persists it (`localStorage`, per #888) and threads the
  overrides through `buildPresentationPayload()`/`makeLyricCue()`/`buildRTF()`.
  - **Hard constraint carried from #1788:** `Graphics.Text.Attributes.paragraph_style` is the ONE
    field where static/reflection encoders diverge by 2 bytes — it is BANNED from the payload
    (the exporter documents this at L557-573). Donated alignment is applied at the **RTF level**
    (`\qc`→`\ql`/`\qr` swap in the header builder), never via paragraph_style. The
    `test-propresenter-static-csp.js` byte-identity guard stays green by construction.
  - Media/`Background` references in templates are **stripped** (#888's recommended option 1).
  - `.proTheme` (Template.Document) round-trip is the highest tier and stays UNSCHEDULED until a
    real `.proTheme` sample exists (never guess; same doctrine as chord_chart).

---

## 8. Golden fixtures + field-diff harness — THE safeguard

### 8.1 The committed corpus — `tests/fixtures/propresenter/`

All MIT-licensed, small; committed with `LICENSE-NOTES.md` naming source repo + commit + licence
(attribution satisfies MIT). From `/home/user/chrismbarr/propresenter-parser/sample-files/`:

| Fixture (committed name) | Bytes | What it uniquely covers |
|---|---|---|
| `v7-feature-test-win.pro` | 20 KB | **Genuine Windows PP 7.13.2** (`platform=PLATFORM_WINDOWS`), `\rtf0` dialect, `\csgenericrgb`, **dangling selected_arrangement** (set, `arrangements[]` empty), empty slides |
| `v7-at-the-cross-mac.pro` | 4.5 KB | Mac PP20, `\cocoartf2865`, simple verse/chorus |
| `v7-come-thou-fount-mac.pro` | 6 KB | Mac, multi-verse |
| `v7-empty-single-slide.pro` | 1 KB | Degenerate edge case |

From `/home/user/bussnet/propresenter7-php-lib/doc/reference_samples/` (+ `ExamplePlaylists/`):

| Fixture | Bytes | Covers |
|---|---|---|
| `bussnet-test.pro` | 7.8 KB | Arrangements populated |
| `bussnet-translated.pro` | 30 KB | **Multiple text elements = translation layers** |
| `bussnet-media-macro.pro` | 1.6 KB | MEDIA + MACRO actions among cues |
| `bussnet-testbild.probundle` | 1.1 KB | Bundle layout 1 |
| `bussnet-export-from-pp.probundle` | 1.2 KB | **Raw PP export incl. the broken-EOCD quirk** |
| `bussnet-testplaylist.proplaylist` | 6 KB | The playlist container (data + .pro entries) |
| `EmptyPlaylist` / `SampleService` `.proplaylist` | small | Playlist edge cases |

Plus (decision D3, §12.3): the owner's genuine v21.4 samples (from `_temp/`, commit `2642f28` on
alpha) — the only v21.4 + hymnal-vocabulary (`Verse N (SDAH)`, `Tag`, multi-arrangement) coverage.
Copyrighted lyric content, private repo — recommended to commit; owner call.

And ONE generated fixture: `ihymns-export-sample.pro` — **regenerated fresh by CI on every run**
(a pre-step runs `node tools/export-pro-sample.js --id TF-0001 --out tests/fixtures/propresenter/`
against the committed synthetic corpus) so the export-import closure test (§8.3c) can never test a
stale export. Regeneration-as-mechanism, not a "remember to update" comment (rule #35).

### 8.2 Expected-output files — `tests/fixtures/propresenter/expected/*.json`

One JSON per import fixture: the exact `_bulkImport_parsePro7()` output
(`{title, ccli, copyright, writers, components:[{type,number,label?,lines[]}], arrangement,
warnings[]}` — and for bundles/playlists the entry inventory + per-entry sha256). **Generated once
by the protobufjs reflection decoder (an INDEPENDENT implementation) via a
`tools/pp7-make-expected.js` helper, then hand-reviewed line-by-line before commit** — the
committed JSON is thereafter the frozen contract. Two independent decoders agreeing on real
third-party files is the structural opposite of the circular round-trip that shipped the false
positives.

### 8.3 The harness tests

**(a) IMPORT — `tests/php/test-pp7-decoder.php`** (auto-run: `tools/run-php-tests.php` globs
`tests/php/*.php`; no workflow edit needed — verified). Pure, no DB. **Tree-derived**: globs
`tests/fixtures/propresenter/*.pro` (+ bundles/playlists); every fixture MUST have an
`expected/<name>.json` (a fixture without one FAILS — coverage cannot silently shrink); a floor
count (≥ 8 `.pro`) guards against the glob under-matching (rule #34's under-report clause). For
each: `pp7DecodePresentation()` + `_bulkImport_parsePro7()` → deep-compare against expected
(exact: types, numbers, labels, line text, arrangement array, warning KINDS — not prose,
rule #35).

**(b) EXPORT — `tests/test-pp7-export-shape.js`** (node; auto-run via `tools/run-node-tests.js`
glob). The anti-false-positive exporter harness. Phase 1: decode EVERY genuine `.pro` fixture with
protobufjs and **derive** the invariant set from the corpus (computed, not typed):
- every text element's `rtf_data` matches `/^{\\rtf[01]\\ansi/`; every Mac-authored fixture's
  carries `\cocoartf`; the fonttbl group precedes the first text run;
- every PRESENTATION_SLIDE action nests `slide.presentation.base_slide.elements[*].element.text.rtf_data`;
- every `cue_groups[*].cue_identifiers[*]` resolves to a `cues[*].uuid`; every
  `arrangements[*].group_identifiers[*]` resolves to a `cue_groups[*].group.uuid`;
- UUIDs are 36-char hyphenated; `Slide.Element.info` bit 2 set on text elements.
Phase 2: encode the synthetic sample song via OUR exporter and assert the output satisfies
**every** derived invariant — our file is held to the standard genuine files set, not to our own
schema. Any invariant the corpus stops exhibiting (fixture set change) recomputes automatically.

**(c) CLOSURE — `tests/php/test-pp7-roundtrip.php`**: parse the CI-regenerated
`ihymns-export-sample.pro` with the REAL import parser and assert the extracted song equals the
synthetic source (titles, component types/numbers, every lyric line byte-identical, Cocoa dialect
split correctly). This is export→import closure through two independent codebases (JS encoder,
PHP decoder) — not a self-decode.

**(d) RTF truth table — `tests/php/test-pp7-rtf-extract.php`** (§3.6's table, incl. the Pro6/EW
non-regression rows).

**(e) ZIP reader — `tests/php/test-pp7-zip-reader.php`**: both `.probundle` fixtures + the
`.proplaylist` fixtures through `pp7ZipExtractEntries()`; assert entry names, sizes, sha256 of
extracted bytes (expected JSON per §8.2); assert the broken-EOCD raw-PP-export fixture extracts
via the fallback path.

**Mutation proof — mandatory before each guard's first green is trusted** (rule #34; record the
performed mutations in each test's doc-block, the `test-component-label-sites.js` model):
- m1: flip `\cocoartf` → `\cocortf` in `buildRTF()` → (b) red.
- m2: delete the `\`+newline branch from `_bulkImport_rtfToText()` → (a) red on Mac fixtures
  (joined lines) AND (c) red.
- m3: swap two field numbers in the walker's Presentation table → (a) red.
- m4: make `_bulkImport_pro7GroupType` return `'outro'` for `Tag` → (a) red (owner-fixture
  expected has `type:'tag'`).
- m5: drop one fixture's expected JSON → (a) red (coverage floor).
- m6: corrupt 4 bytes mid-central-directory of a bundle fixture copy → (e) still green
  (local-header scan doesn't read it) — documents WHY the reader is tolerant; corrupt a local
  header → (e) red.
- m7: reorder `group_identifiers` in the synthetic song's expected arrangement → (c) red.

---

## 9. Schema / migrations

**P1–P3: NO new tables, NO new columns.** Verified against the write path:
- Lyrics/structure: `tblSongComponents` (thin rows) + `tblLyricLines` via the existing funnel;
  `label` uses the existing #1907 `Label` column.
- Arrangements: the existing `tblSongs.ArrangementJson` (#892, probe-gated in
  `_bulkImport_saveSong` already).
- Set lists: existing `tblUserSetlists`.
- Provenance: considered a `tblSongs.Source` column and **rejected under rule #44** — provenance
  is already derivable (the `PP7` songbook grouping, `logActivity` source key
  `bulk_import_pro7`, the revision row's NewData); the app would act on nothing a column
  collects.

**P4: still no DDL.** `tblSongMedia.Kind` is `VARCHAR(20)` app-validated (#1090 widened it from
ENUM for precisely "new media kinds need no ALTER" — rule #20 already paid this cost). New kinds
= map lines in `SongMediaStorage` (§6.1.3) + the schema.sql **COMMENT** on the Kind column updated
to name `video | image` (comment-only edit; no structural change, but rule #19's byte-mirroring
discipline means the comment edit lands in schema.sql alone — verify `test-schema-coverage.php`
is comment-tolerant before assuming; if it demands migration parity for comment edits, ship a
no-op comment-sync migration per its established pattern — check during implementation).

**P5/P6: none** (template styling is localStorage per #888; `.proTheme` unscheduled).

If any future phase DOES need DDL, rule #19/#20 applies in full (one-pass forward-looking batch,
migration + schema.sql + ONE migration-registry entry, VARCHAR vocab, real probe).

---

## 10. Cross-platform (Windows) verification plan

What we can prove **automatically, now**:
1. **Import from Windows PP is covered by a genuine artefact**: `v7-feature-test-win.pro`
   (PLATFORM_WINDOWS, PP 7.13.2, `\rtf0` + `\csgenericrgb` + `\par`) runs through the decoder test
   and the RTF truth table on every CI run. The expected JSON asserts the extracted lyrics — the
   Windows dialect cannot regress silently.
2. **Export cross-platform reasoning is evidence-backed, not hopeful**: `.pro` is one protobuf on
   both platforms (a Mac file must open on Windows, so Windows PP's RTF reader accepts Cocoa RTF);
   two independent projects (bussnet, ChrisMBarr-adjacent generators) have PP7-verified cocoartf
   imports on both platforms. The #1950 export dialect therefore stands.

The **one residual** that only a human with Windows ProPresenter can close:
3. A real Windows PP7 **open of our export** (single `.pro`, a bundle after §4.3's layout fix, and
   a `.proplaylist` after P3). Deliverable: extend `PROPRESENTER-TESTING.md` §5b into a concrete
   per-artefact checklist (slide count, group labels, lyric text non-blank incl. an `ë`/`é` song,
   arrangement panel shows the imported order, CCLI panel populated) + a pinned comment on #1968
   asking the owner to run it and attach screenshots. **Non-blocking** for merge (the epic already
   operates this way for #1950); each phase's issue stays open with an "owner PP verify"
   checkbox until confirmed. Nice-to-have follow-up recorded in #1968: a Windows-exported v21 `.pro`
   sample would let us diff Windows' own writer output (currently we have only 7.13.2-Win).

---

## 11. CI guards (each tree-derived + mutation-proven; §8.3's five are the core, plus:)

1. **`tests/test-pp7-routing.js`** (node): reads `editor.js`, `import2.php`, `api2.php` source
   (comment-stripped first, the test-qr-cuercode model) and asserts: (a) the `.pro` extension
   branch in `editor.js` calls the sniff (no bare `endsWith('.pro')` routing straight to
   `importChordPro`); (b) api2's auto-map no longer lists `'pro'` inside the chordpro arm;
   (c) the accept lists carry `.pro`, and — once their phases land — `.probundle`/`.proplaylist`
   (phase-gated assertions keyed off whether the handler action string exists in api.php:
   tree-derived, so the guard tightens itself as phases merge rather than being edited).
   Mutations: restore the old chordpro lumping → red; drop `.pro` from an accept list → red.
2. **`tests/test-pp7-label-roundtrip.js`** (node): parses BOTH map literals from source — the
   exporter's `COMPONENT_LABEL_MAP` (propresenter-export.js) and the PHP `_bulkImport_pro7GroupType`
   map — and asserts closure: every `name` (+ short letter form) the exporter can emit folds back
   to the original iHymns type through the import map. Cross-language lockstep by mechanism
   (rule #35), the PHP↔JS pattern `test-org-logo-surfaces.php` check (g) established. Mutation:
   remove `'coda'` from the PHP map → red.
3. **Decoder field-table lockstep** (inside §8.3a): the decoder test also parses the vendored
   `.proto` files for the messages the walker names and asserts each constant table's
   field-number/name pairs match the proto source — the walker cannot silently drift from the
   schema it claims to mirror. (Tree-derived from `protos/proto-7.16/`, not a typed copy.)
4. **Existing guards that must stay green and are affected**: `test-propresenter-export.js`
   (58 tests; P5 preset work touches its RTF assertions), `test-propresenter-static-csp.js`
   (byte-identity — P3's static-module rebuild and P5's styling threading must not disturb it;
   paragraph_style stays banned), `test-component-label-sites.js` (the PP7 exporter must remain
   `.label`-free — machine exports are structural, rule #45; the IMPORTER writing `label` is fine
   and out of that guard's scanned set — verify its glob excludes `includes/` PHP, it is a JS
   display-site guard).
5. Every new PHP test is auto-collected (`tools/run-php-tests.php` globs `tests/php/*.php` —
   verified, incl. the workflow comment "a suite cannot exist without being run"); every new node
   test likewise (`tools/run-node-tests.js`). No workflow-list edits — the glob IS the mechanism.

---

## 12. Risks, owner decisions, and the commit/PR breakdown

### 12.1 Risks

| Risk | Mitigation |
|---|---|
| PP version drift (v25+ adds fields) | Walker skips unknown fields; song-relevant messages wire-stable 7.16→21.4 (all diffs additive); harness fixtures span 7.13–21.4 |
| ZipArchive behaviour differs across shared-hosting PHP builds | The fallback local-header reader is the primary correctness path; ZipArchive is only an optimisation; both paths fixture-tested |
| `.proplaylist` unconfirmed corners (nested GROUP folders, PP7-raw export shape) | Import handles arbitrary nesting defensively; export byte-mirrors the genuine PP20 fixture; flagged UNCONFIRMED in code + issue until an owner sample lands |
| Large bundle memory (media entries) | P2 never materialises media bytes; P4 reads lazily per entry with per-kind caps |
| RTF extractor changes regress Pro6/EasyWorship/Proclaim | Non-regression rows in the truth table; all three changes are strict correctness fixes (documented per-change) |
| Set-list sync direction assumption (P3 §5.1.5) | Marked "verify during implementation" with a designed fallback (same-origin localStorage write) |
| Imported media copyright exposure | D1 below — public serving withheld by default |

### 12.2 Issues to file at kickoff (standing-tasks §2 — file at the moment of discovery)

1. Retrospective: `_bulkImport_rtfToText()` emits raw cp1252 bytes for `\'xx` (latent mojibake in
   Pro6/EW/Proclaim imports) — fixed inside P1.
2. `.probundle` export layout was invented (`Documents/` + `manifest.json`) and never PP-verified
   — fixed in P2 (§4.3).
3. `.proplaylist` import/export (P3 feature issue, child of #1968).
4. `.probundle` media ingest (P4 feature issue, child of #1968; records the owner's ingest-&-store
   decision + D1's exposure default).
5. Translation layers → `tblLyricLineTranslations` follow-up (from §3.4).
6. Export themes T1/T2 (P5 issue; #888 already covers T3).
7. Owner-verify checklist issue (or pinned #1968 comment) for §10.3.

### 12.3 Owner decisions (CLAUDE.md decision format; NONE block P1)

**D1 — Imported media: public exposure default** *(blocks nothing; P4 only)*
1. **Decision:** when P4 stores a bundle's background video/image on the song, is it publicly
   served immediately or admin-only until opted in?
2. **Why:** motion backgrounds are routinely third-party licensed to the *church*, not to iHymns —
   a product/legal judgement, not derivable from code.
3. **Options:** (a) public immediately — richest pages, real infringement exposure; (b)
   **admin-visible only; public per-song opt-in** — safe default, one extra curator click; (c)
   don't store — contradicts the locked ingest decision.
4. **Recommendation: (b)** — mirrors the dormant-until-enabled posture the repo already prefers
   (rule #28), reversible per song.
5. **Need back:** "a, b or c."

**D2 — `.proplaylist` import audience** *(blocks nothing; P3 only)*
1. **Decision:** curator-only surface (editor import page → set list in the curator's account)
   first, or also a public end-user upload?
2. **Why:** importing a playlist can create *songs* (editor-gated); a public surface needs a
   resolve-only mode — a product-scope call.
3. **Options:** (a) **curator-first** (public later if asked); (b) both at once (bigger surface,
   gating split to design now); (c) public resolve-only first (no song creation — weakest utility).
4. **Recommendation: (a)** — smallest correct slice; the server core is shared either way.
5. **Need back:** "a, b or c."

**D3 — committing the owner's v21.4 samples as fixtures** *(blocks nothing; §8.1)*
1. **Decision:** commit the genuine SDAH-vocabulary samples (copyrighted lyrics) under
   `tests/fixtures/propresenter/`, or keep only the MIT set?
2. **Why:** they are the only v21.4 + hymnal-label coverage; the repo is private but licensing
   posture is the owner's call (LICENSING.md discipline).
3. **Options:** (a) **commit as-is** (private repo; they're already in alpha history at
   `2642f28`); (b) commit lyric-sanitised derivatives (a small tool rewrites rtf_data with dummy
   text, preserving structure — loses the RTF-dialect realism for those files); (c) don't commit
   (lose v21.4/`(SDAH)`/`Tag` coverage in CI).
4. **Recommendation: (a)**, with (b) as the fallback if the repo ever opens.
5. **Need back:** "a, b or c."

**D4 — real-ProPresenter verification of the P2 bundle-layout fix + P3 playlist export**
*(non-blocking, but the export changes stay "fixture-verified only" until done)* — run the §10.3
checklist on macOS and Windows PP; reply with pass/fail per row.

### 12.4 Commit/PR breakdown

Per the repo's one-PR-per-piece-of-work rule and standing-directives' one-branch/no-stacking:
each phase is one piece of work = **one PR, landed and alpha-verified before the next branch
opens** (the phases are sequential by dependency, so stacking never arises). Conventional-Commit
titles per rule #46 (`feat:` on P1–P5 PRs — they must bump the minor).

**PR-1 — `feat(editor): import ProPresenter 7+ .pro files (#885, #1968)`** — P0 + P1 + harness:
1. `test: commit ProPresenter golden fixtures + expected outputs (MIT, attributed)` (fixtures
   first — the safeguard precedes the feature).
2. `feat(includes): pp7 proto3 wire decoder (propresenter7_decode.php) + decoder/field-lockstep tests`
3. `fix(importers): dual-dialect RTF extraction in _bulkImport_rtfToText (cocoa soft-return, cp1252, surrogates) + truth table`
4. `feat(importers): _bulkImport_parsePro7/_bulkImport_processPro7 + arrangement passthrough in assembleSong/saveSong`
5. `fix(editor): content-sniff .pro routing (editor.js, import2.php, api2 import_file, ZIP path) + routing guard`
6. `test: export-shape invariants + export→import closure + label round-trip guards`
7. `docs: PROPRESENTER-TESTING.md import scenarios; wiki + CHANGELOG; close #885`

**PR-2 — `feat(editor): import ProPresenter .probundle + genuine bundle export layout`** — P2
(tolerant ZIP reader + tests; bulk handler; export layout fix; docs; D4 checklist comment).

**PR-3 — `feat(setlist): ProPresenter .proplaylist import/export`** — P3 (playlist decode tables +
tests; import handler + set-list write; static-module rebuild with playlist entries +
`arrangement_name=5` adoption; client export; docs; UNCONFIRMED flags).

**PR-4 — `feat(media): ingest .probundle media into tblSongMedia`** — P4 (kind registry additions;
resolve/extract/store; gating registration + dormancy verify; portable-URL export form; docs;
implements D1's answer).

**PR-5 — `feat(export): ProPresenter theming tiers + .pro-as-template (#888)`** — P5.

Every PR: full audit pass (php -l / node --check), issue updates with SHAs, `.claude/` +
handoff per standing-tasks; each phase's warnings/UNCONFIRMED items recorded on its issue —
never only in a transcript.

---

*Plan ends. The two scratchpad ground-truth documents and the cloned fixture repos are
session-local — the durable copies of every load-bearing fact are §2.1's field tables, §3.6's
dialect facts, §4's ZIP facts, §5's playlist facts, and §8.1's fixture inventory (to be committed
in PR-1). If a claim in this plan ever disagrees with a committed fixture, the fixture wins.*
