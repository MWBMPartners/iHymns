# ProPresenter 7 chord round-trip — implementation plan (epic #1968, chords work-stream)

**Status: DESIGN — deep-research pass complete 2026-08-28. No production code written yet.**
Research session artifacts: `scratchpad/pp-chord-refs/` (downloaded reference sources),
`scratchpad/pp-samples-verified-findings.md` (the 12-sample decode pass this plan builds on).

---

## ⚠️ HEADLINE FINDING — the "inline `[G]` in RTF" premise is WRONG

The epic's working hypothesis ("ProPresenter 7 stores chords as inline ChordPro `[G]` brackets
typed directly into the slide's lyric text") is **overturned by the references**. That is the
*user-facing editing metaphor*, not the storage format. In the `.pro` file:

- **Chords are stored as protobuf `CustomAttribute` messages** — `{range: IntRange, chord: string}`
  — inside `element.text.attributes.custom_attributes[]`, i.e. positioned attributes over the
  slide's plain text, exactly like a Cocoa `NSAttributedString` custom attribute.
- **The RTF (`rtf_data`) stays CLEAN — no bracket markers in the text at all.** A reference
  implementation's own test asserts it: `assert!(!String::from_utf8_lossy(&text.rtf_data).contains("[C]"))`
  (chordlib `outputs/propresenter.rs` L415).

This is *very good news* for iHymns: the current importer's clean-lyric extraction is clean **by
construction** (there are no markers to strip), and chord capture is a pure *additive* decode of a
protobuf field the vendored schema + static encoder **already support** — no RTF surgery in either
direction, no marker-stripping regex, no risk class of "chord pollution in lyric text".

Had we built to the original premise we would have shipped an exporter that writes literal `[G]`
into `rtf_data` — which real ProPresenter would have displayed **to the audience as lyric text**.
This is precisely the false-positive class the owner's #1 rule exists to prevent.

---

## §1 — The confirmed PP7 chord storage format (with citations)

### 1.1 Protobuf location (CONFIRMED — vendored schema + two independent implementations)

From the repo's own vendored schema (`appWeb/public_html/manage/editor/protos/proto-7.16/graphicsData.proto`):

- `rv.data.Graphics.Text` (the slide text message):
  - field **3** `attributes` (`rv.data.Graphics.Text.Attributes`) — L206
  - field **5** `rtf_data` (bytes) — L208
  - field **12** `chord_pro` (`rv.data.Graphics.Text.ChordPro`) — L234
- `Attributes` field **13**: `repeated rv.data.Graphics.Text.Attributes.CustomAttribute custom_attributes` — L293
- `CustomAttribute` (L381-400): field **1** `rv.data.IntRange range`; a `oneof Attribute` whose
  field **7** is `string chord` (siblings: capitalization=2, original_font_size=3,
  font_scale_factor=4, text_gradient_fill=5, should_preserve_foreground_color=6, cut_out_fill=8,
  media_fill=9, background_effect=10).
- `rv.data.IntRange` (`intRange.proto`): `int32 start = 1; int32 end = 2;`
- `ChordPro` (L262-273): `bool enabled = 1; enum Notation {NOTATION_CHORDS=0, NOTATION_NUMBERS=1,
  NOTATION_NUMERALS=2, NOTATION_DO_RE_MI=3} notation = 2; rv.data.Color color = 3;`

**Reference implementation A — `greyshirtguy/Pro7ChordEditorWin`** (same author as the proto
source iHymns vendored; a real Windows tool whose output real Pro7 then displays; downloaded to
`scratchpad/pp-chord-refs/MainWindow.xaml.cs`). Its READ path (L233-242):

```csharp
foreach (CustomAttribute customAttribute in slideElement.Element_.Text.Attributes.CustomAttributes)
    ...
    if (customAttribute.AttributeCase == CustomAttribute.AttributeOneofCase.Chord)
```

Its WRITE path builds exactly `CustomAttribute { Range = new IntRange { Start, End }, Chord }`
(L489-497) and writes the presentation back with `presentation.WriteTo(output)` — **no RTF is ever
modified** (the `[x]` bracket runs exist only inside its WPF RichTextBox UI, L295:
`Run run = new Run("[" + customChordAttribute.Chord + "]")`, and are excluded from the plain-text
character count on save — L471-506: a run starting with `[` never increments `charIndex`).

**Reference implementation B — `xilefmusics/chordlib`** (Rust; independent of A; downloaded to
`scratchpad/pp-chord-refs/chordlib-{input,output}-propresenter.rs`). Reads chords from
`text.attributes.custom_attributes` (input L201-205), writes them the same way (output L222-228,
L268), and models `chord_pro` as the display-settings struct (input L195-200: notation read only
when `enabled`; output L279-291: sets `{enabled: true, notation, color}` only when chords exist).

**User-facing corroboration** (editing metaphor + stage-display behaviour): the
[Pro7ChordEditorWin README](https://github.com/greyshirtguy/Pro7ChordEditorWin) — "You can add/edit
chords to the lyrics of a song by typing them in ChordPro format … Once saved, re-open Pro7 and the
inline chords can then be displayed using the `Chords` option for textboxes on your stage display
(the same as Multitracks)"; [ChordProEditor](https://chordproeditor.com/) ("Add Chords to
ProPresenter for Stage Display" — site itself egress-blocked from this environment; description via
search results); RV's own MultiTracks-chords tutorial (renewedvision.com egress-blocked; title/summary
via search). Audience output shows clean lyrics; chords appear on stage display only.

### 1.2 Range semantics (CONFIRMED with ONE flagged conflict)

- **Units: UTF-16 code units** over the plain text derived from `rtf_data`.
  chordlib is explicit — `line.encode_utf16().count()` (input L235), and its validator's error
  message literally says "invalid **UTF-16 range**" (input L262); it rejects an offset that splits
  a surrogate pair (L308-331). greyshirtguy's WPF `run.Text.Length` (L505) is also UTF-16 units.
  Consistent with the format being a serialized Cocoa attributed-string attribute.
- **Anchor: `range.start` is the chord's position.** Both implementations position by `start` only
  (greyshirtguy read path L246-292; chordlib input L217-244).
- **`range.end` = the NEXT chord's `start` (or end of text for the last chord)** — chords TILE the
  text. Both writers agree: greyshirtguy save loop (L477-531: `rangeLastChord.End = charIndex` at
  the moment the next chord run is found; final chord's `End` = end-of-text count), chordlib output
  (L217-229: `end = chord_starts.get(index + 1) … unwrap_or(text_end)`). Readers ignore `end`
  (both), so import must never depend on it beyond validation.
- ⚠️ **CONFLICT — do newlines count?** chordlib counts each `'\n'` as **1 unit** in the running
  offset (input L243: `line_start = line_end.saturating_add(1)`; output L201-214 pushes `'\n'`
  into the same string it counts). greyshirtguy's tool counts **only run text within paragraphs**
  — paragraph breaks contribute 0 (read L253-289 and save L455-514 both skip them). These cannot
  both match ProPresenter. **This plan adopts chordlib's convention (newline = 1 UTF-16 unit)** as
  primary because it is the Cocoa `NSAttributedString.string` convention (the string includes the
  `\n`s; attribute ranges index into it) and chordlib is the more rigorous implementation — but it
  is **an assumption to be settled by a real PP-authored chord-bearing file (owner item D4)**. The
  design isolates the choice in ONE named constant (`NEWLINE_UNITS = 1`) in each half so flipping
  it after D4 evidence is a one-line change + fixture regen.
- **Practical blast radius of the conflict is small for import**: iHymns only needs to bucket a
  chord to the right LINE (see §2); a wrong newline weight mis-buckets only chords within
  (line-index) units of a boundary. For export it shifts stage-display chords rightward by up to
  (lines−1) characters on the last line of a multi-line slide — visible, so D4 must confirm.

### 1.3 The `chord_pro` struct — display settings, NOT content (CONFIRMED against real files)

Decoded from the real, PP-authored, chord-LESS samples this epic already verified
(`scratchpad/pp-samples/`, run 2026-08-28 with protobufjs against `protos/proto-bundle.json`):

- Every text element carries a latent `chord_pro` — e.g. `{"color":{"alpha":1}}` and on one
  element `{"color":{"red":0.993,"green":0.760,"blue":0.032,"alpha":1}}` (a gold `#FDC208`-ish —
  evidently PP's chord-display colour setting) — with `enabled` absent (=false) and
  `custom_attributes: []`. Confirms: `chord_pro` is a per-textbox chord-DISPLAY settings struct
  (colour + notation), present regardless of chord content; the CONTENT lives solely in
  `custom_attributes`.
- chordlib treats `notation` as governing how the stored chord STRINGS are interpreted
  (`NOTATION_NUMBERS=1` ⇒ stored strings are Nashville numbers needing a key — input L206-215).
- ⚠️ **`chord_pro.enabled`'s output-side semantics are UNCONFIRMED** — does PP set it when a user
  types chords? Could `enabled:true` make chords render on the AUDIENCE output? greyshirtguy's
  real-PP-validated writer **never touches `chord_pro`** and chords still display on the stage
  display (per its README, the stage-display textbox's own `Chords` option is what turns display
  on). chordlib sets `enabled:true` but is self-described "experimental" with no proven
  real-PP validation. **Decision: our v1 export does NOT write `chord_pro` at all** (matches the
  only real-PP-validated writer; zero risk of accidentally surfacing chords to the audience).
  Revisit at D4 by inspecting what PP itself writes.

### 1.4 What chords are NOT: `chord_chart` (do not touch)

`Presentation.chord_chart` (field 9, `rv.data.URL`) and `PresentationSlide.chord_chart` (field 4)
are the **ProPresenter 6-era "Chord Charts" feature: an ATTACHED PDF/image file** shown on the
stage display ([PP6 docs: learn.renewedvision.com/propresenter6/the-features-of-propresenter/chord-charts](https://learn.renewedvision.com/propresenter6/the-features-of-propresenter/chord-charts)
— document-level or per-slide PDF attachment). Entirely distinct from inline chords. All 12 real
samples have it empty. Stays out of scope, consistent with the interop plan's P6 deferral
("do not guess `chord_chart`'s shape").

### 1.5 Song key metadata (relevant, optional)

`Presentation.music_key` (string, field 22) and `Presentation.music` (field 23,
`message Music { string original_music_key = 1; string user_music_key = 2;
rv.data.MusicKeyScale original = 3; rv.data.MusicKeyScale user = 4; }` —
`presentation.proto` L114-121). greyshirtguy's tool edits `Music.Original`/`Music.User` and its
README documents Pro7's behaviour: "You can change the `User Key` to have Pro7 *automatically
transpose* the chords from the `Original Key`" — i.e. **stored chord strings are in the ORIGINAL
key**; User≠Original means PP renders them transposed. chordlib reads user-key-first (input
L395-419) and writes both (+ `music_key`) from the song key (output L104-143). All 12 real
(chordless) samples have `music: null, music_key: ""`. iHymns has a home for this:
`tblSongKeys.OriginalKey` (#298, schema.sql L1886-1898) — see §3.5/§4.4.

---

## §2 — iHymns' chord model, and the ChordsJson ↔ positioned-chords mapping

### 2.1 The in-app representation (verified from code)

- **The per-line chord cell is a WHITESPACE-POSITIONED STRING (or a token array) — never a
  {position, chord} pair list.** `includes/song_importers.php` L2748-2750 states it outright:
  "the app's chord model is a chord line per lyric line, not a positioned overlay … this captures
  the chords present, not their inline character offset". `format-export.js` L637-666 (the DATA
  SHAPE / ALIGNMENT DECISION doc-block) confirms: a cell is `null`, an array of chord-symbol
  strings, or a space-separated string; "THERE IS NO STORED position".
- **BUT the string form's whitespace IS positional in every display surface**:
  `includes/chord_display.php` (#299) renders the string verbatim into a `white-space: pre`
  monospace container "so the chords stay roughly over the right words"; `js/modules/print.js`
  does the same (its built-in template fixture at L186-187 is the canonical example:
  `chords: ['G        G7       C   G', 'G            D']` over "Amazing grace! how sweet the
  sound"); the editor's chords textarea (#1094, `manage/editor/editor.js` L1184-1215) shows/edits
  the same monospace lines ("Each chord line lines up with the lyric line above it").
- **Storage**: component-level `chords` array parallel to `lines` → the ONE write path
  `lyricLinesWriteComponents()` stores each cell as JSON on the line row
  (`includes/lyric_lines_sync.php` L779-780: `json_encode($chords[$i])` →
  `tblLyricLines.ChordsJson`); the ONE read path reassembles the parallel array
  (`includes/lyric_lines_read.php` L202-213, null when no line carries chords). Rule #25 —
  the importer/exporter never touch ChordsJson directly.
- **ChordPro inline mapping already exists and stays UNCHANGED**: import
  `_bulkImport_chordProSplitLine()` (song_importers.php L2753-2767 — captures `[sym]` tokens in
  order, strips them from the lyric); export `buildChordProLine()` (format-export.js L756-781 —
  token *i* interleaved before word *i*, overflow appended, `chordProBracketSafe()` escaping).
  That word-index convention is ChordPro's canonical inline mapping in this app (#1080) and is NOT
  what PP7 needs — PP7 has real character offsets.

### 2.2 The mapping decision — "export what iHymns displays"

**IMPORT (PP7 → iHymns): build a POSITIONED STRING cell.** For each lyric line, place each chord's
symbol starting at the chord-line column equal to the chord's character offset within that lyric
line (converted UTF-16 → code points; pad with spaces; if the previous chord's symbol would
overlap, fall back to a single separating space). This is native to the app: `chord_display.php` /
`print.js` / the editor textarea all render it correctly positioned with **zero changes**, and it
preserves PP7's positioning through iHymns rather than discarding it.

**EXPORT (iHymns → PP7): emit each chord at the offset the iHymns song page displays it at.**
- **String cell** (the positioned form): each token's starting COLUMN in the chord line (code
  points) = the chord's character position over the lyric line → convert to UTF-16 units, clamp to
  line length (a chord positioned past the line end anchors at line end — mirrors #1080's clamp
  philosophy of never silently dropping a chord).
- **Array cell** (position already lost — same as `ihymns_chord_line_to_string()`'s space-join and
  `buildChordProLine()`'s word-index): token *i* anchors at the START of word *i* of the lyric
  line; overflow tokens anchor at line end. This reuses ChordPro-export's exact alignment decision
  for exactly the shape where that decision already applies.
- Global offsets: line offsets accumulate as UTF-16 length + `NEWLINE_UNITS` (=1, §1.2) per line
  break, over the SAME lines the slide's RTF is built from; `range.end` = next chord's start /
  text end (tiling, §1.2).

**Round-trip property**: PP7 → iHymns → PP7 preserves chord positions exactly (string cells carry
the columns); iHymns-authored positioned cells export at their displayed columns (WYSIWYG);
ChordPro-imported token cells export at word starts (the position ChordPro's own inline form
implies). The two cell interpretations are not a fork of the ChordPro mapping — the positioned
string IS the app's canonical display semantic (#299/#1094), and the token/word-index branch IS
ChordPro-export's canonical semantic (#1080), each reused where it already governs.

**Where the mapping lives (rule #22 — one core each side, provably inverse):**
- **JS** (export): new pure helpers in `propresenter-export.js` SECTION 5 (or a small
  `chord-position-map.js` if the editor later wants them): `chordCellPositions(lineText, cell)`
  → `[{cpOffset, symbol}]` and `linesChordAttributes(lines, cells)` → `[{range:{start,end},chord}]`
  (UTF-16 conversion + tiling). Tokenisation reuses the `chordProChordTokens()` contract
  (format-export.js L705-714) — split on whitespace runs, drop blanks — NOT a re-fork.
- **PHP** (import): inverse helpers beside the importer (`_bulkImport_pro7ChordCellsFromRanges()`
  et al, §3.3). PHP and JS halves are INVERSES, not copies — the agreement mechanism (rule #35) is
  the cross-language round-trip guard (§6.2), not a lockstep comment.
- **Code-point discipline** (rule #21): columns are code points — `mb_*` in PHP, `Array.from()` /
  code-point iteration in JS; UTF-16 conversion is explicit and only at the protobuf boundary.
  Surrogate-splitting offsets from a malformed file are clamped to the nearest boundary with a
  warning (chordlib hard-errors there; an importer should degrade, not refuse the song).

---

## §3 — Import design

### 3.1 Decoder extension (`includes/propresenter7_decode.php`)

Additive, same hand-rolled wire-walker style (field tables + `_pp7Walk`):

- `PP7_FIELDS_GRAPHICS_TEXT` gains `'attributes' => 3` (graphicsData.proto L206).
- New tables: `PP7_FIELDS_TEXT_ATTRIBUTES = ['custom_attributes' => 13]`;
  `PP7_FIELDS_CUSTOM_ATTRIBUTE = ['range' => 1, 'chord' => 7]`;
  `PP7_FIELDS_INT_RANGE = ['start' => 1, 'end' => 2]`.
- New decoders: `pp7DecodeIntRange()` (two varints; negative ⇒ treat row invalid),
  `pp7DecodeCustomAttribute()` → `?array{start:int, end:int, chord:string}` — returns null when
  the `chord` oneof member is absent (capitalization/gradient/etc. attributes are skipped, exactly
  greyshirtguy's `AttributeCase == Chord` filter), `pp7DecodeTextAttributesChords()` → list of
  those rows.
- `pp7DecodeGraphicsText()` currently returns the rtf string (L698-706) and is called by
  `pp7DecodeGraphicsElementRtf()` → keep signatures stable for existing callers; add a parallel
  `pp7DecodeGraphicsElementChords()` or (cleaner) extend `pp7DecodeSlideElement()`'s return from
  `{info, rtf}` to `{info, rtf, chords: list}` (additive key — its consumers index by name).
- `pp7DecodePresentation()`'s per-cue output grows `slideElementChords[]` parallel to the existing
  `slideRtf[]`/`slideElementInfos[]`.
- No DB, no STRICT concerns at this layer. Depth budget unchanged (attributes nests 3 deeper —
  well within the depth-32 headroom noted at L457).

### 3.2 Importer wiring (`includes/song_importers.php`)

`_bulkImport_pro7SelectCueText()` (L4641-4682) selects ONE element's RTF and extracts text; extend
to also return that SAME chosen element's chord rows (never another element's — a translation
layer's chords must not cross-attach):

- Return shape `{text: string, chordCells: list<string>}` where `chordCells` is parallel to
  `explode("\n", text)` — the per-line positioned cells built by §3.3. (Internal shape change only;
  its one caller is `_bulkImport_parsePro7`.)
- `_bulkImport_pro7AppendCueLines()` (L4695-4703) gains a chords-parallel sibling (or an extra
  by-ref param): every line appended/skipped appends/skips its cell in lockstep — including the
  leading-empty-line skip and the caller's trailing-blank `array_pop` trim (L4946-4948), which must
  pop cells in lockstep.
- Component assembly (L4957): attach `'chords' => $cells` **only when at least one cell is
  non-empty** — mirroring `_bulkImport_parseChordPro()`'s flush gate (L2810-2816) so a chordless
  import's component array stays **byte-identical to today** (the non-regression the existing
  fixtures + `test-pp7-parse.php` pin).
- Persistence: nothing new — `_bulkImport_saveSong()` already carries `chords` through
  `lyricLinesWriteComponents()` (the ChordPro importer's exact path, rule #25). Un-migrated /
  STRICT safety is the write path's existing gated behaviour (`lyricLinesSyncReady()` false ⇒
  legacy shadow columns) — no new SQL anywhere in this feature's import half.

### 3.3 Range → per-line positioned cells (`_bulkImport_pro7ChordCellsFromRanges()`)

Pure function: `(string $plainText, list<array{start,end,chord}> $rows) → list<string>` (cells
parallel to the text's lines).

1. Drop rows with `trim(chord) === ''` (both references do; greyshirtguy's editor even creates
   empty `[]` runs transiently). Collapse interior whitespace in a symbol to a single
   non-breaking token (`preg_replace('/\s+/u','',…)`) — a cell is whitespace-delimited, so an
   embedded space would split one chord into two on the next tokenisation.
2. Sort by `start` ascending (chordlib input L230; PP order in the repeated field is not
   guaranteed).
3. Walk lines of `$plainText`; line *k* spans `[lineStart, lineStart + utf16len(line_k)]`,
   `lineStart += utf16len(line_k) + NEWLINE_UNITS`. A chord with `start` in that closed interval
   buckets to line *k* at in-line UTF-16 offset `start - lineStart` (chordlib's exact bucketing,
   input L232-244).
4. Convert the in-line UTF-16 offset to a CODE-POINT column (clamping mid-surrogate to the
   preceding boundary, warning collected); build the cell by space-padding to that column, or a
   single space after the previous symbol when columns collide. `start` beyond text end clamps to
   the last line's end (defensive; validator-style hard errors are for tests, not imports).
5. `range.end` is IGNORED for placement (§1.2) — at most sanity-checked.

**Font-suppression interplay** (the PR-1 dominant-font filter): offsets index the element's FULL
attributed text, but `_bulkImport_pro7SelectCueText()` extracts through
`_bulkImport_rtfToText($rtf, maxFs)`, which may DROP small-font runs — making the emitted text
shorter than what the ranges index. Rule: bucket against the UNFILTERED extraction
(`_bulkImport_rtfToText($rtf, 0)`); if filtered === unfiltered (the overwhelmingly common
single-font lyric case) use the cells directly; otherwise align filtered lines to unfiltered lines
by content+order and keep only matching lines' cells — and if that alignment is ambiguous, **drop
the chords with a collected warning** ("chords present but could not be aligned to the imported
text") rather than mis-anchoring them. A wrong chord placement is worse than a reported absence.

### 3.4 Notation and clean-lyric guarantees

- Import the raw chord strings regardless of `chord_pro.notation` (iHymns chords are free text).
  When `chord_pro.enabled && notation ∉ {0}`, append ONE summary warning ("chords use
  numbers/numerals/do-re-mi notation; imported verbatim") — chordlib refuses these; we degrade.
- Clean lyrics are structural (no markers exist in RTF — §1) but the guard §6.1 still asserts the
  imported LINES are byte-identical with and without chord capture, so a future regression that
  leaks chord text into lines goes red.

### 3.5 Optional (separate commit, flagged): key capture

`presentation.music.original` / `music_key` → `tblSongKeys.OriginalKey` (#298; feeds the
transpose module #101, which already reads `song.key`). Requires: INFORMATION_SCHEMA-gated
existence probe (rule: migrations aren't auto-applied), a small write in `_bulkImport_saveSong()`,
and the MusicKey enum → name map (musicKeyScale.proto: `MUSIC_KEY_A_FLAT=0 … ` — chordlib's
21-name table, input L431-442, matches the vendored enum). Defer-able without loss: chords import
fine without it; the stored symbols are original-key per §1.5.

---

## §4 — Export design (`manage/editor/propresenter-export.js`)

### 4.1 Where chords enter

- The song object already carries them: v2 `export.js` `buildExportSong()` maps
  `chords: Array.isArray(c.chords) ? c.chords : null` (L38-43, added by #1080's fix); the public
  path (`js/modules/export-ui.js`) feeds `?action=song_data`'s song, whose components include
  `chords` via the one assembler. **No data plumbing needed.**
- `buildPresentationPayload()` L932-940: `chunkLines(getComponentLines(comp), options.linesPerSlide)`
  then `makeLyricCue(actionName, buildRTF(lineChunks[c]))`. Change: chunk the chords array **with
  the same boundaries** (either refactor `chunkLines` to return index ranges, or add
  `chunkParallel(lines, cells, lps)` — same chunker, two arrays), then
  `makeLyricCue(actionName, buildRTF(chunk.lines), chordAttrsFor(chunk.lines, chunk.cells))`.
- `makeLyricCue(name, rtfString, chordAttrs)` (L718-783): when `chordAttrs` is a non-empty array,
  set `text.attributes = Object.assign(defaultTextAttributes(), { custom_attributes: chordAttrs })`.
  Nothing else changes: **no `chord_pro` write in v1 (§1.3)**, no RTF change (`buildRTF()` L389-453
  stays byte-identical — the chords never enter the RTF), title/blank pre-slides get no chords.

### 4.2 Offset computation (`linesChordAttributes(lines, cells)`)

Per §2.2: per line, token positions from the cell (string ⇒ code-point columns via a
whitespace-run scan — the same alternating split `buildChordProLine()` uses (L763), applied to the
CHORD line; array ⇒ word-start columns of the LYRIC line, overflow at line end); convert columns →
UTF-16 units; add the running line offset (utf16len + `NEWLINE_UNITS` per preceding line, matching
the `\par`-joined RTF's derived text); tile `end` = next `start` / total utf16 length; emit
`{range: {start, end}, chord: symbol}`. Symbols pass through verbatim (PP has no bracket-escaping
problem — there is no inline syntax to collide with; `chordProBracketSafe()` remains a
ChordPro-only concern).

### 4.3 Encoder support — already present, verify byte-identity, do NOT regenerate

The static encoder `protos/pp7-proto-static.js` already encodes the whole chain:
`Attributes.custom_attributes` (L4441-4443, tag `writer.uint32(106)` = field 13), `CustomAttribute`
incl. `chord` (L4752-4770) and `IntRange`; the reflection bundle (`proto-bundle.json`) decodes it
(proven by this session's decode of the real samples' `custom_attributes`/`chord_pro`). **No
encoder regeneration.** The #1788 static-vs-reflection byte-identity guard
(`tests/test-propresenter-static-csp.js`) gains a chord-bearing payload row (§6.4) — sub-message
arrays are the case its own doc-block flags as the one that can diverge, so this row is mandatory,
mutation-proven, and lands in the SAME commit as the exporter change.

### 4.4 Optional (same separate commit as §3.5): key emit

When the song has a key (`tblSongKeys.OriginalKey` → needs the read plumbed into the export song
shape; `format-export.js`'s `buildChordPro()` already consumes `song.key` L797 so the field name
exists), set `music_key` + `music.{original_music_key, user_music_key, original, user}` (both =
original; chordlib output L137-143 is the template; enum mapping §3.5). Static encoder support
already present (pp7-proto-static.js L131-134, L551-574). Omit entirely when no key — matches every
real sample (`music: null`).

---

## §5 — Reference-derived fixture (NO real chord-bearing sample exists)

**Fact (verified, findings doc):** all 12 real samples are chord-free — they prove the chordless
baseline only. Until the owner supplies a real chord-bearing export, chord tests run against a
**synthetic, reference-derived** fixture, clearly marked as such:

- `tools/pp7-gen-chord-fixture.js` — builds a small chord-bearing `.pro` **with protobufjs
  REFLECTION directly** (NOT through `propresenter-export.js` — the import test's fixture must not
  be produced by our own exporter, or the pair is the circular same-schema round-trip the owner
  forbids). Shape per §1: clean Cocoa RTF (reuse the byte-verified header idiom), 2 groups /
  3 cues, `custom_attributes` with UTF-16 tiling offsets INCLUDING a multi-line slide (exercises
  `NEWLINE_UNITS`), a chord mid-word, a chord at column 0, an overflow offset, a non-BMP char
  (emoji) BEFORE a chord (exercises UTF-16↔code-point), a `chord_pro {enabled: true}` on one
  element and absent on another (import must not care), and one non-chord CustomAttribute
  (capitalization) that must be skipped. File header: **"REFERENCE-DERIVED (greyshirtguy
  Pro7ChordEditorWin + xilefmusics/chordlib + proto-7.16 schema) — NOT validated against a real
  ProPresenter-authored chord file yet; see owner checklist D4"**. Regenerated per test run, never
  committed (the `pp7-gen-roundtrip-sample.js` posture).
- **Owner checklist D4 (blocking for "done", not for building):**
  1. Owner produces a REAL chord-bearing export: open any song in ProPresenter 7, type 3-4 chords
     (`[G]`, one mid-word, one on line 2+ of a multi-line slide — the line-2 chord is what settles
     the §1.2 newline conflict), save, send the `.pro`.
  2. We decode it: confirm storage location, offset convention (newline weight), `end` tiling,
     whether PP itself writes `chord_pro.enabled`, and how a User≠Original key affects stored
     symbols. Fixture + `NEWLINE_UNITS` corrected if needed (one-line + regen by design).
  3. Owner opens OUR chord-bearing export in real PP7: lyrics clean on output, chords visible on a
     stage display layout with the textbox `Chords` option on, positions sane, file re-saves
     without corruption.

---

## §6 — Guards (rule #34: tree-derived where applicable, every one mutation-proven)

1. **`tests/php/test-pp7-chord-import.php`** (new; DB-free, like `test-pp7-parse.php`).
   Runs `tools/pp7-gen-chord-fixture.js` (node, same probe/skip idiom as `test-pp7-roundtrip.php`
   L202-213) → `_bulkImport_parsePro7()`. Asserts: chords land on the right components/lines at
   the right code-point columns; the emoji line's chord column is code-point-correct; the
   non-chord CustomAttribute is ignored; the overflow offset clamps with a warning; **the
   components' `lines` are byte-identical to importing the chordless twin of the same fixture**
   (clean-lyric preservation); a chordless component carries NO `chords` key (shape
   non-regression). Mutations that must go red: flip `NEWLINE_UNITS` to 0 → line-2 chord
   mis-buckets; make the bucketer use code points instead of UTF-16 → emoji-line column wrong;
   have the cell builder append symbols un-positioned → column asserts fail; make chord capture
   append the symbol into the line text → clean-lyric byte-compare fails.
2. **Round-trip closure — extend `tools/pp7-gen-roundtrip-sample.js` + `tests/php/test-pp7-roundtrip.php`.**
   `SAMPLE_SONG` gains chords: one positioned STRING cell (with a chord NOT over word 0 — the case
   word-index cannot represent), one ARRAY cell, one chordless component. The PHP side asserts the
   re-imported cells reproduce the positioned columns exactly and the array cell comes back at its
   word-start columns, and lyrics remain byte-identical. This is the two-independent-halves
   (JS encoder / PHP wire-walker) agreement proof — the legitimate NON-circular closure this repo
   already established for #1968 PR-1. Mutations: break the export UTF-16 conversion → red; break
   the import bucketing → red; drop the chunk-parallel chords slicing (with `linesPerSlide` set in
   a second generator invocation) → red.
3. **`tests/test-propresenter-export.js`** — extend: decode the exported bytes with protobufjs
   (reflection — the independent decoder) and assert `custom_attributes` presence/ranges on the
   lyric elements, `rtf_data` contains no `[` marker for any emitted chord symbol (the
   premise-guard: chords must NEVER enter the RTF), a chordless song emits NO `custom_attributes`
   and NO `chord_pro` and its payload is deep-equal to the pre-change exporter's (the #1080-style
   byte-safety property), and title/blank cues never carry chords. Mutations: weave brackets into
   `buildRTF` input → red; emit `custom_attributes: []` on chordless → red.
4. **`tests/test-propresenter-static-csp.js`** — add a chord-bearing payload to the
   static-vs-reflection byte-identity comparison (§4.3). Mutation: perturb one `IntRange` field id
   in a scratch copy of the static encoder → red.
5. **Non-regression:** `tests/php/test-pp7-parse.php` + the 12 real samples must pass UNCHANGED
   (chordless imports byte-identical); `tests/test-chordpro-export.js`,
   `tests/php/test-chordpro-parser.php`, `tests/php/test-chord-display.php` untouched and green
   (this feature reuses, never modifies, their subjects).

---

## §7 — Commit breakdown (one PR, per repo convention)

| # | Commit | Content | Buildable now? |
|---|--------|---------|----------------|
| C1 | `feat(pp7): decode chord custom-attributes` | §3.1 decoder tables + functions; unit rows in `test-pp7-decode.php` | YES (schema + 2 refs) |
| C2 | `feat(pp7): import chords to per-line cells` | §3.2-3.4 importer; `tools/pp7-gen-chord-fixture.js`; `test-pp7-chord-import.php` | YES (reference-derived, marked) |
| C3 | `feat(pp7): export chords as custom-attributes` | §4.1-4.3 exporter helpers + `makeLyricCue`/chunking; static-csp chord row; export-test rows | YES |
| C4 | `test(pp7): chord round-trip closure` | §6.2 generator + roundtrip-test extension | YES |
| C5 | `fix(editor): keep leading chord-column whitespace` | editor.js L1206 `l.trim()` → right-trim only (`replace(/\s+$/,'')`) — today the chords textarea destroys a positioned cell's leading padding on edit; becomes user-visible once PP7 imports position chords mid-line. Mutation-tested via a small DOM-free unit if practical, else noted in the manual-verify list | YES |
| C6 | *(optional, may defer to its own issue)* `feat(pp7): song key ↔ presentation.music` | §3.5 + §4.4, INFORMATION_SCHEMA-gated | YES, but lowest value; fine to split out |
| D4 | *(no code)* owner validation per §5 | real chord-bearing file both directions | **NEEDS OWNER + real ProPresenter** |

WHATS-NEW bullet (rule #46 companion): plain-language, on the `feat:` push — e.g. "Songs exported
to ProPresenter now carry their chords (shown on stage displays), and importing a ProPresenter
file brings its chords in too." Wiki: extend the ProPresenter interop page's format notes with §1
(it currently documents the chordless baseline).

---

## §8 — Confidence + open risks

### CONFIRMED (multiple independent references, or bytes/code in hand)
| Claim | Evidence |
|---|---|
| Chords live in `text.attributes.custom_attributes[] {range, chord}`; RTF stays clean | vendored proto (graphicsData.proto L293, L381-400) + greyshirtguy read/write code (verbatim, §1.1) + chordlib read/write (§1.1) + chordlib's own no-`[C]`-in-RTF assertion |
| `range.start` anchors; `end` = next chord's start / text end; readers ignore `end` | both implementations, §1.2 |
| Offsets are UTF-16 code units | chordlib explicit (validator + surrogate handling); WPF `Length` consistent |
| `chord_pro` = per-textbox display settings (enabled/notation/colour), latent on chordless real files | proto + chordlib + this session's decode of real samples (`{"color":{…}}`, `custom_attributes: []`) |
| `chord_chart` = PP6-style attached PDF/image, unrelated | PP6 docs + all 12 samples empty |
| Static encoder + reflection bundle already handle every needed field | pp7-proto-static.js L131-134/551-574/4441-4443/4752-4770; live decode this session |
| iHymns cell shape, write path, display positioning, ChordPro mapping | repo code, §2.1 |
| Chords already flow into both export entry points' song objects | v2 export.js L38-43; export-ui.js → song_data |

### ASSUMPTIONS — flagged, isolated, resolved by D4
| Assumption | Risk if wrong | Isolation |
|---|---|---|
| Newline = 1 UTF-16 unit in offsets (chordlib) vs 0 (greyshirtguy) | import: chord near a line boundary lands one line off; export: stage chords drift right on later lines | ONE `NEWLINE_UNITS` constant per half + regenerable fixture |
| Not writing `chord_pro` is safe/sufficient (greyshirtguy precedent) | worst case: owner must tick the stage textbox `Chords` option (documented anyway); chords never leak to audience under this choice | revisit after D4 shows what PP itself writes |
| PP accepts our tiling `end` values / ignores them on read | none observable if PP, like both references, positions by `start` | D4 re-save check |
| Stored symbols are original-key when User≠Original | import could mis-label key context (symbols themselves still correct) | §3.5 deferred; D4 |
| chordlib's conventions match PP-authored bytes (it is self-described "experimental") | shared-wrongness residual risk — mitigated by greyshirtguy's real-PP-validated agreement on everything except the newline question | D4 is the definitive check |

### Explicitly NOT in scope
`chord_chart` (P6 deferral stands); capo (`tblSongArrangements.CapoFret` — no PP7 counterpart
found in any reference); per-arrangement keys; MultiTracks (`multi_tracks_licensing` — greyshirtguy
BLOCKS editing MT docs for licence reasons; our importer should keep treating MT-licensed docs like
any other on read, but we must never inject chords into one on a future edit-in-place feature —
note kept here for the record); Nashville/numeral notation conversion (imported verbatim, §3.4).
