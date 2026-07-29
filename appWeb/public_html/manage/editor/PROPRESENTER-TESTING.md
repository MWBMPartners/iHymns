# ProPresenter Export — Testing Guide

Step-by-step scenarios for validating the iHymns ProPresenter 7+ `.pro`
exporter (issue [#887](https://github.com/MWBMPartners/iHymns/issues/887)).

The exporter has three independent layers that each need their own
verification:

1. **Schema bundling** — vendored `.proto` files compile cleanly into
   `protos/proto-bundle.json`.
2. **Encode / decode round-trip** — bytes produced by the exporter
   parse back to the same shape via protobufjs against the bundled
   schema.
3. **ProPresenter import** — the `.pro` file actually opens in
   ProPresenter 7+ on macOS or Windows with all expected slides,
   labels and metadata visible.

Scenarios 1 and 2 run automatically in CI. Scenario 3 must be performed
manually by a tester with ProPresenter installed.

---

## 0. Prerequisites

- Node ≥ 22 (project root: `node --version`).
- `npm install` has completed (installs `protobufjs` + `protobufjs-cli`).
- No `songs.json` corpus file is needed. `tools/export-pro-sample.js`
  ships with a small synthetic fixture
  (`tests/fixtures/synthetic-songs.json`, songbooks `TF`/`SX`, 5
  invented songs) and uses it by default — the real ~14k-song
  catalogue is live MySQL only, never a whole-corpus file (#1617,
  CLAUDE.md rule #17). For a smoke test against **real** hymn text,
  pull one songbook's export instead — see §3.
- An authenticated `global_admin` / `edit_songs` session on
  `/manage/editor/` for the **manual browser** scenarios in §4 (that
  editor is DB-direct and requires login — it is not a static file you
  can open from disk).
- A working ProPresenter 7+ install for the **manual import** scenarios
  in §5 (7.16 or newer recommended; the schema we encode against is the
  greyshirtguy 7.16 reverse-engineered set).

---

## 1. Build the proto bundle

The descriptor is committed to the repo, but you should be able to
regenerate it from the vendored `.proto` files at any time.

```bash
npm run build:proto
```

> ⚠️ **Known-broken as of this rewrite (found while fixing #1632, not
> yet its own tracked fix):** `tools/build-proto-bundle.js`'s
> `PROTO_DIR`/`OUTPUT_PATH` still point at
> `appWeb/private_html/editor/protos/…`, which no longer exists — the
> real vendored `.proto` set and the committed `proto-bundle.json` both
> live under `appWeb/public_html/manage/editor/protos/` now. Running
> the command above currently fails with `Proto directory not found`
> rather than producing the output below. Don't spend time debugging a
> "broken" schema change against this step until that path is fixed.

**Expected (once the path above is fixed):**

```
Wrote appWeb/public_html/manage/editor/protos/proto-bundle.json (≈220 KB)
rv.data.Presentation has 22 fields.
```

**Failure modes to look for:**

- `Cannot resolve import: <name>.proto` — a missing dependency in
  `appWeb/public_html/manage/editor/protos/proto-7.16/`. Re-pull the
  file from greyshirtguy/ProPresenter7-Proto.
- `no such type: rv.data.Presentation` — the entry list in
  `tools/build-proto-bundle.js` no longer includes `presentation.proto`.

---

## 2. Run the unit + round-trip test suite

`npm test` now runs the ENTIRE node suite (every `tests/*.js` file, via
`tools/run-node-tests.js` — #1631 item 5), so its output is much longer
than just this exporter's tests. To run only the ProPresenter-export
suite:

```bash
node tests/test-propresenter-export.js
# or: npm run test:export   (also runs test-export-ui.js)
```

**Expected (last lines):**

```
Bundle URL (#1566):
  ✓ DEFAULT_BUNDLE_URL is pinned to the root-absolute path
  ✓ the pinned URL maps to a real, committed file
  ✓ init() with no bundle/bundleUrl fetches DEFAULT_BUNDLE_URL (runtime path)
  ✓ an explicit { bundleUrl } overrides DEFAULT_BUNDLE_URL

58 passed, 0 failed
```

The suite covers:

- Module bootstrap in a Node `vm` context with protobufjs injected.
- UUID v4 format.
- RTF builder (prefix, `\par` separation, metacharacter escaping,
  non-ASCII `\uN?` form, empty-input safety).
- Filename helper (composition, illegal-char stripping, fallback).
- Component → cue label mapping (V1, C, B).
- Schema **`Presentation.verify()`** on the in-memory payload.
- **Full encode → decode round-trip** through protobufjs against the
  bundled schema, including:
  - top-level `name`, `category` and `cue_groups`/`cues` count;
  - decoded UUIDs are valid v4;
  - cue-group cue references resolve to a real cue;
  - decoded slide action carries an RTF-bearing element;
  - CCLI block contains the new `artist_credits` field from #587.
- Stored-mode ZIP signatures and central-directory entries.

If anything fails, run with `DEBUG=1 node tests/test-propresenter-export.js` to see stack traces.

---

## 3. CLI smoke test against real songs

The `tools/export-pro-sample.js` helper runs the same browser exporter
under Node and writes real `.pro` files to disk for inspection or
hand-off to ProPresenter. There is no in-repo `data/songs.json`
corpus any more (#1617) — MySQL is the only source of truth — so the
tool defaults to a small bundled synthetic fixture and reads real
content only via `--json`.

### 3a. Quick smoke test (bundled fixture, no login needed)

```bash
# All three songs in the fixture's "TF" songbook, plus a ZIP.
node tools/export-pro-sample.js --songbook TF --limit 3 --zip TF-samples.zip
```

**Expected:**

```
Wrote tmp/propresenter-samples/1 (TF) - Rivers of Wonder.pro (1214 bytes)
Wrote tmp/propresenter-samples/2 (TF) - Song for Silent Fields.pro (1780 bytes)
Wrote tmp/propresenter-samples/3 (TF) - The Quiet Harbor.pro (707 bytes)
Wrote tmp/propresenter-samples/TF-samples.zip (4137 bytes, 3 entries)

Verified 3/3 file(s) decode back via protobufjs.
```

(Byte sizes are illustrative — a schema or exporter change will shift
them slightly.) The fixture's `TF` songbook only has 3 songs, so
`<SongNumber>` isn't zero-padded here; on a real songbook the number
is zero-padded to that songbook's digit width (e.g. a 243-song book
pads to 3 digits, a 3,517-song book to 4), so lexicographic sort order
in a ProPresenter library matches numeric order.

### 3b. Smoke test against a real songbook

Pull one songbook's real export from a logged-in `/manage/editor/`
session (`?action=songbook_export` needs the same-origin admin
cookie), save it to a file, then point `--json` at it:

```bash
# In a browser logged into /manage/editor/, visit (or curl with the
# session cookie): /manage/editor/api.php?action=songbook_export&abbr=CP
# Save the response as cp-export.json, then:
node tools/export-pro-sample.js --json cp-export.json --songbook CP --limit 3 --zip CP-samples.zip
```

`--json` also accepts the multi-songbook interchange shape
`tools/parse-songs.js` produces (`{meta, songbooks, songs}`), for
whoever still has a `.SourceSongData/` checkout to regenerate one from.

Other useful invocations (against whichever `--json` source is active,
or the bundled fixture if `--json` is omitted):

```bash
node tools/export-pro-sample.js --id TF-0001                # single song
node tools/export-pro-sample.js --all --zip catalogue.zip   # every loaded song
node tools/export-pro-sample.js --songbook TF --probundle   # .probundle bundle
node tools/export-pro-sample.js --songbook TF --lines-per-slide 2 --pre title-blank
node tools/export-pro-sample.js --help
```

The `Verified N/M` line is the round-trip check — the script reads
each freshly-written file back from disk, decodes it via protobufjs
against the schema, and confirms `Presentation.name` and
`Presentation.cues[]` are populated.

---

## 4. Editor UI test (browser, no ProPresenter required)

> **Rewritten 2026-07-29 (#1632).** The editor this section originally
> described (`appWeb/private_html/editor/`, with a "Load JSON" button
> and an "Export Options" modal offering slide-layout / pre-slide-order
> choices + a "save as default" checkbox) was retired by #589;
> `appWeb/private_html/editor/index.php` is now a bare 301 redirect
> stub. The **live** editor is `/manage/editor/` — DB-direct, no JSON
> load step, and (as of this rewrite) no export-options modal: the
> ProPresenter dropdown items export immediately with fixed defaults.
> The steps below are based on reading `manage/editor/index.php` and
> its inline ProPresenter wiring script, plus `propresenter-export.js`
> and `editor.js` — **not** a live click-through, so treat exact toast
> wording as "verified from source, worth a quick spot-check" rather
> than gospel. This validates the dropdown wiring and the lazy-load of
> the protobuf runtime + descriptor; it does **not** offer a
> slide-layout / pre-slide-order picker in the UI — those options
> still exist in the underlying exporter and are reachable from
> `tools/export-pro-sample.js` (§3), just not from this page.

1. Log into `/manage/editor/` as a user with `edit_songs` (or
   `global_admin`) — the song list loads automatically from the live
   database; there is no manual data-load step.
2. Open DevTools → Network. You should see `vendor/protobuf.min.js`
   and `protos/proto-bundle.json` load **once**, eagerly, on page
   load. There should be **no** outbound CDN requests for the protobuf
   runtime.
3. Open DevTools → Console. There should be no warnings; a
   `[ProPresenter] schema init failed; export disabled:` message means
   the bundle failed to load — see the failure table at the end of
   this document.

### 4a. Single-song export

4. Open any song in the editor (so it becomes the current song).
5. Open the **Export** dropdown and click **ProPresenter 7+ `.pro`**.
   There is no options modal — the export runs immediately with the
   exporter's built-in defaults (no line chunking, lyric-only
   pre-slides).
   - Expected: a file named `<Number> (<SongbookAbbrev>) - <Title>.pro`
     downloads.
   - Expected toast (source: `index.php`'s `exportCurrentSong()`):
     `Exported <filename>`.

### 4b. Bulk export — .probundle (one songbook)

6. In the sidebar, filter the song list down to one songbook (e.g.
   `CP - Carol Praise`).
7. Open the **Export** dropdown and click
   **ProPresenter 7+ `.probundle`**.
   - Expected toast while it builds: `Building ProPresenter bundle for
     <ABBR>…`
   - Expected: file `<Songbook Name> (<ABBR>) [Bundle].probundle`
     downloads, containing a `manifest.json` at the root and a
     `Documents/` folder with one `.pro` per song in that songbook
     (verified against `exportAllAsBundle()` in
     `propresenter-export.js`).
   - Expected success toast: `Exported N song(s) → <bundle name>`.

There is currently no ZIP bundle option for ProPresenter 7+ in this UI
(ProPresenter 6 has its own separate `.zip` bulk-export item — a
different code path, not covered here). A ZIP of PP7 `.pro` files is
still available via `tools/export-pro-sample.js --zip` (§3).

### 4c. Negative cases

8. With no song open, click **ProPresenter 7+ `.pro`** in the Export
   dropdown.
   - Expected warning toast: `Open a song first, then export it.`
9. With the sidebar songbook filter cleared (showing all songbooks, or
   no filter applied), click **ProPresenter 7+ `.probundle`**.
   - Expected warning toast: `Filter the song list to one songbook
     first (sidebar dropdown), then export it.`

---

## 5. ProPresenter import test (manual)

This is the scenario the automated tests cannot cover — you need a
human with ProPresenter installed.

### 5a. Single-song import (macOS)

1. Pull a real songbook export per §3b (or, for a bare smoke check
   with no login, use the bundled fixture: `node
   tools/export-pro-sample.js --id TF-0001`) to generate a sample
   `.pro` file under `tmp/propresenter-samples/`.
2. Copy the file to the test machine.
3. In ProPresenter 7.16+, open **Library → +** (or drag the file onto
   the ProPresenter icon).
4. Choose the destination library and click **Open**.

**Expected results in ProPresenter** (field names below are
illustrative — a live catalogue song's exact title/author/copyright
will differ from these placeholders, and content that was accurate
when #887 shipped this table can drift as songs are edited; check the
shape of each row against whichever song you actually exported, not
the literal strings):

| Where to look | What to see |
| --- | --- |
| Library entry name | `A baby was born in Bethlehem` |
| Slide arrangement | One slide per song component, in order |
| Group labels | `V1`, `V2`, `V3`, `C` etc. matching the iHymns components |
| Slide text | The lyric lines, line-broken via `\par` |
| **Show Info** → CCLI section | Author = `Ivor Golby / Noël Tredinnick`<br>Artist = (whatever iHymns has, post-#587)<br>Title = `A baby was born in Bethlehem`<br>Copyright = `© 2024 …`<br>Year = `2024`<br>CCLI Number = (parsed digits from the iHymns CCLI field) |
| Notes / Description | Empty unless `song.notes` was populated |

### 5b. Single-song import (Windows)

Repeat 5a on a Windows ProPresenter install. ProPresenter's file format
is identical across platforms, but slide rendering occasionally differs
on font fallback. Confirm that:

- The same slide count appears.
- No "could not open document" alert is shown.
- Lyric text is readable (no missing-glyph squares for accented
  characters such as `ë` in `Noël`).

### 5c. Bulk import via ZIP

1. Using a real songbook export pulled per §3b, run
   `node tools/export-pro-sample.js --json cp-export.json --songbook CP --limit 25 --zip cp-25.zip`
   (drop `--limit 25` to export the whole book, or point `--songbook`
   at whichever abbreviation is in your exported JSON — the bundled
   fixture only has 3-5 songs per book, too few for a meaningful bulk
   test).
2. Unzip the resulting archive into a folder.
3. In ProPresenter, drag the **folder** onto the library panel (or use
   File → Import → Folder).

**Expected:** every exported entry appears in the chosen library,
sorted alphabetically by file name (zero-padded numbers keep e.g.
`001 …` before `010 …`).

### 5d. Edge-case songs

To exercise the corners of the encoder, run the same import flow on:

- A song with **non-ASCII characters** in the title or lyrics (e.g.
  `Noël`, `Père`, `Hallelujah! Sálvanos`). Expect glyphs to render
  correctly thanks to the `\uN?` RTF Unicode escape.
- A song with **`{`, `}` or `\` in the lyrics** (rare in hymnals but
  possible in chord-pro). Expect the text to render unaltered (RTF
  metacharacter escaping should hide the encoding from view).
- A song with **multiple authors** (`writers[]` length > 1). Expect
  the CCLI Author field to be a comma-joined list.
- A song with **distinct writers and composers**. Expect the CCLI
  Author field to be `<writers> / <composers>`.
- A song with **no CCLI number**. Expect the CCLI Number field to be
  empty rather than `0`.
- A song with **no copyright text**. Expect the CCLI block to still
  display, with empty Publisher and Year.
- A song with **`pre-chorus`, `bridge`, `intro`, `outro`** components.
  Expect group labels `P`, `B`, `I`, `O` respectively.

### 5e. Round-trip parity check

After importing a song into ProPresenter and re-exporting it (right-
click → Export As… in ProPresenter), open the re-exported `.pro` with:

```bash
node tools/export-pro-sample.js --help
# (then use protobufjs from a small node REPL, or)
node -e "import('protobufjs').then(async ({default: p}) => {
  const fs = require('fs'); const path = require('path');
  const root = p.Root.fromJSON(JSON.parse(fs.readFileSync(
    'appWeb/public_html/manage/editor/protos/proto-bundle.json', 'utf8')));
  const T = root.lookupType('rv.data.Presentation');
  const dec = T.decode(fs.readFileSync(process.argv[1]));
  console.log(JSON.stringify({
    name: dec.name,
    cueGroups: dec.cue_groups.map(g => g.group.name),
    ccli: dec.ccli
  }, null, 2));
});" /path/to/file.pro
```

This dumps the decoded structure as JSON. Compare with the same dump
from the original iHymns export to confirm ProPresenter preserved the
fields we set.

---

## 6. Failure / troubleshooting reference

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `protobufjs runtime not found` toast | `vendor/protobuf.min.js` failed to load (file missing on the host, wrong MIME, or SRI hash mismatch) | Confirm the file exists on the server, regenerate the SRI hash with `openssl dgst -sha384 -binary vendor/protobuf.min.js \| openssl base64 -A` and update the `integrity=` attribute in `index.php` if you replaced the vendored copy |
| `Failed to load protos/proto-bundle.json` | The bundle was never built or the file 404s | Run `npm run build:proto`; ensure the file is deployed alongside `propresenter-export.js` |
| `Presentation schema verification failed: …` toast | A future iHymns schema change broke a field shape | The verify error names the offending field; adjust `buildPresentationPayload()` accordingly |
| Single-song export silent | No song open in the editor | A toast reads `Open a song first, then export it.` — open a song first |
| `.probundle` export silent | Sidebar songbook filter isn't set to exactly one songbook | Toast reads `Filter the song list to one songbook first (sidebar dropdown), then export it.` |
| ProPresenter says "the document could not be opened" | The schema we target is older than the running ProPresenter; it may need an `application_info` block | Open `tools/build-proto-bundle.js` and switch to a newer Proto directory; re-run `npm run build:proto`; rebuild `proto-bundle.json` |
| ProPresenter opens but slides are blank | RTF text body is malformed | Inspect the RTF bytes via the round-trip-parity dump (5e); confirm `\rtf1\ansi\uc1` prefix and balanced `{}` |
| Diacritics render as `?` | The receiving template's font lacks the glyph; not an exporter bug | Switch the ProPresenter template to a Unicode-complete font (e.g. Helvetica Neue, Segoe UI) |

---

## 7. What is *not* tested

- **DRM / signing** — ProPresenter 7+ does not require signed
  documents, so we do not produce one.
- **Themes / styling** — slides are emitted with no font, colour or
  alignment overrides. ProPresenter applies the receiving library's
  default template.
- **Backgrounds / media** — no `Background` or `Media` references
  emitted. Add per-slide if a future change populates them in iHymns.
- **Arrangements** — only the natural component order is exported.
  ProPresenter's "Arrangements" panel will show `Default` only.
- **Timeline / chord charts** — out of scope for this MVP.

When any of those become required, extend
`buildPresentationPayload()` and add a corresponding test in
`tests/test-propresenter-export.js`.
