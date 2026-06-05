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
- `data/songs.json` exists (run `npm run parse-songs` if not).
- A working ProPresenter 7+ install for the **manual** scenarios
  (7.16 or newer recommended; the schema we encode against is the
  greyshirtguy 7.16 reverse-engineered set).

---

## 1. Build the proto bundle

The descriptor is committed to the repo, but you should be able to
regenerate it from the vendored `.proto` files at any time.

```bash
npm run build:proto
```

**Expected:**

```
Wrote appWeb/private_html/editor/protos/proto-bundle.json (≈220 KB)
rv.data.Presentation has 22 fields.
```

**Failure modes to look for:**

- `Cannot resolve import: <name>.proto` — a missing dependency in
  `appWeb/private_html/editor/protos/proto-7.16/`. Re-pull the file
  from greyshirtguy/ProPresenter7-Proto.
- `no such type: rv.data.Presentation` — the entry list in
  `tools/build-proto-bundle.js` no longer includes `presentation.proto`.

---

## 2. Run the unit + round-trip test suite

```bash
npm test
```

**Expected (last lines):**

```
ZIP writer:
  ✓ produces a valid PK ZIP signature
  ✓ ends with EOCD signature
  ✓ central directory contains every filename

31 passed, 0 failed
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

If anything fails, run with `DEBUG=1 npm test` to see stack traces.

---

## 3. CLI smoke test against real songs

The `tools/export-pro-sample.js` helper runs the same browser exporter
under Node and writes real `.pro` files to disk for inspection or
hand-off to ProPresenter.

```bash
# First three songs from the Carol Praise songbook, plus a ZIP.
node tools/export-pro-sample.js --songbook CP --limit 3 --zip CP-samples.zip
```

**Expected:**

```
Wrote tmp/propresenter-samples/001 (CP) - A baby was born in Bethlehem.pro (1675 bytes)
Wrote tmp/propresenter-samples/002 (CP) - A child is born for us today.pro (2239 bytes)
Wrote tmp/propresenter-samples/003 (CP) - A messenger named Gabriel.pro (3140 bytes)
Wrote tmp/propresenter-samples/Carol Praise (CP) [Bundle].zip (≈8 KB, 3 entries)

Verified 3/3 file(s) decode back via protobufjs.
```

Note the `<SongNumber>` is zero-padded to the songbook's digit width
(CP has 243 songs → 3 digits; Mission Praise has 3,517 → 4 digits, so
its files come out as `0001 (MP) - …`). This guarantees lexicographic
sort order in ProPresenter libraries matches numeric order.

Other useful invocations:

```bash
node tools/export-pro-sample.js --id CP-0001              # single song
node tools/export-pro-sample.js --all --zip catalogue.zip # entire catalogue
node tools/export-pro-sample.js --songbook CP --probundle # .probundle bundle
node tools/export-pro-sample.js --songbook CP --lines-per-slide 2 --pre title-blank
node tools/export-pro-sample.js --help
```

The `Verified N/M` line is the round-trip check — the script reads
each freshly-written file back from disk, decodes it via protobufjs
against the schema, and confirms `Presentation.name` and
`Presentation.cues[]` are populated.

---

## 4. Editor UI test (browser, no ProPresenter required)

This validates the dropdown wiring, the export-options modal, the
localStorage preferences, and the lazy-load of the protobuf runtime +
descriptor.

1. `npm run dev` — starts the PHP dev server on `localhost:8000`.
   *(Or just open `appWeb/private_html/editor/index.php` through any
   PHP-capable webserver — the editor lives in `private_html/`.)*
2. Open the editor URL in a browser.
3. Open DevTools → Network. You should see `vendor/protobuf.min.js`
   (served from the same origin) and `protos/proto-bundle.json` load
   **once**, eagerly, on page load. There should be **no** outbound
   CDN requests for the protobuf runtime.
4. Open DevTools → Console. There should be no warnings; if there's a
   `[iHymns] ProPresenter init deferred` message, see the failure
   table at the end of this document.
5. Click **Load JSON** and pick `data/songs.json`.

### 4a. Single-song export — defaults

6. Pick any song from the list.
7. Click **Export → Export as ProPresenter (.pro)**.
8. The **ProPresenter Export Options** modal opens.
9. Confirm the defaults are pre-selected:
   - Slide layout = "One slide per component"
   - Pre-slide ordering = "Lyric slides only"
10. Click **Export**.
    - Expected: a file named `<Number> (<SongbookAbbrev>) - <Title>.pro`
      downloads.
    - Expected toast: `Exported <filename> (<N> bytes).`

### 4b. Single-song export — chunking + Title slide

11. Pick a song with several lines per verse.
12. Click **Export → Export as ProPresenter (.pro)**.
13. In the modal, choose:
    - Slide layout = "Max 2 lines per slide"
    - Pre-slide ordering = "Title slide → Lyric slides"
14. Tick **Save these choices as my default for next time**.
15. Click **Export**.
    - Expected: a `.pro` file downloads.
    - In a fresh tab (or after reload), open the modal again — your
      saved choices should now be pre-selected.

### 4c. Bulk export — ZIP

16. Pick a songbook (e.g. `CP - Carol Praise`) in the sidebar filter.
17. Click **Export → Export all as ProPresenter ZIP**.
18. The modal opens with a **format picker** showing ZIP / .probundle.
19. Leave format = ZIP, click **Export**.
    - Expected: file `Carol Praise (CP) [Bundle].zip` downloads.
    - Expected: zip contains one `.pro` per song using the new
      filename scheme.
    - Expected toast: `Exported N song(s) as <bundle name>.`

### 4d. Bulk export — .probundle

20. Click **Export → Export all as ProPresenter Bundle (.probundle)**.
21. The format picker is pre-set to `.probundle`.
22. Click **Export**.
    - Expected: file `Carol Praise (CP) [Bundle].probundle` downloads.
    - Expected: bundle contains a `manifest.json` at the root and a
      `Documents/` folder with one `.pro` per song.

### 4e. Negative cases

23. Without selecting a song, click **Export → Export as
    ProPresenter (.pro)**.
    - Expected: modal opens, but on Export the curator sees a
      warning toast `Select a song before exporting to ProPresenter.`
24. Without loading any data, click any bulk export entry.
    - Expected: warning toast `No songs loaded — load a songs.json
      file first.`

---

## 5. ProPresenter import test (manual)

This is the scenario the automated tests cannot cover — you need a
human with ProPresenter installed.

### 5a. Single-song import (macOS)

1. Run `node tools/export-pro-sample.js --id CP-0001` to generate a
   sample `.pro` file under `tmp/propresenter-samples/`.
2. Copy the file to the test machine.
3. In ProPresenter 7.16+, open **Library → +** (or drag the file onto
   the ProPresenter icon).
4. Choose the destination library and click **Open**.

**Expected results in ProPresenter:**

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

1. Run `node tools/export-pro-sample.js --songbook CP --limit 25 --zip cp-25.zip`.
2. Unzip the resulting archive into a folder.
3. In ProPresenter, drag the **folder** onto the library panel (or use
   File → Import → Folder).

**Expected:** all 25 entries appear in the chosen library, sorted
alphabetically by file name (which means `CP 1 …` before `CP 10 …`).

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
    'appWeb/private_html/editor/protos/proto-bundle.json', 'utf8')));
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
| Single-song export silent | No song selected | A toast reads `Select a song before exporting…` — load a song first |
| ZIP export silent | No songs loaded or filter excludes everything | Toast reads `No songs loaded` or `No songs match the current filter` |
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
