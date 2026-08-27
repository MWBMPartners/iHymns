# ProPresenter 7+ — Reference Sources & Fixtures (MIT-licensed)

> Created 2026-08-27 during epic **#1968** (ProPresenter 7+ interoperability). These are the
> **open-source, MIT-licensed** projects and **real ProPresenter fixture files** that ground the
> `.pro`/`.probundle`/`.proplaylist`/theme work — the ground truth that ends the "validated against a
> circular same-schema round-trip" false-positive cycle (the export bug shipped green twice before a
> real file proved the root cause). Recorded here so any future session can find and reuse them.
>
> Companion docs: `.claude/propresenter-interop-1968-plan.md` (the implementation plan), and the
> owner's own genuine v21.4 samples committed to `_temp/` on `alpha` (commit `2642f28`).

## 1. Source projects (all MIT — verified from each repo's LICENSE)

| Project | Upstream | Licence | What it gives us | Authority |
|---|---|---|---|---|
| **greyshirtguy/propresenter7-proto** | https://github.com/greyshirtguy/propresenter7-proto | MIT | The reverse-engineered `.proto` schema for `rv.data.Presentation` et al., in **4 generations** (`Proto 7.16`, `Proto7.16.2`, `Proto 19beta`, `autogen-proto` auto-refreshed to **PP 21.4**). **This is the source the iHymns `manage/editor/protos/proto-7.16/*.proto` were vendored from.** | **Canonical schema.** Auto-dumped from the real PP binaries on each release. Highest authority for message/field layout + field numbers. |
| **ChrisMBarr/propresenter-parser** | https://github.com/ChrisMBarr/propresenter-parser | MIT (© 2024 Chris Barr) | A working TS parser for PP4/5/6/7 **plus real `.pro` fixtures** — including the only **Windows-authored** PP7 file we have. | **High (real fixtures).** The fixtures are genuine ProPresenter output = true ground truth for import behaviour. |
| **bussnet/propresenter7-php-lib** | https://github.com/bussnet/propresenter7-php-lib | MIT (© 2026 Thorsten Buss) | A **PHP** reader/generator (the closest language to iHymns' server), the only written **`.probundle` / `.proplaylist` specs** anywhere (`doc/formats/*`, `doc/internal/learnings.md`), + reference samples incl. bundles & a playlist. TDD'd against 169 real songs with PP7-import verification. | **High.** Code + specs; a few prose errors (e.g. URL `platform=5` should be `3`) — trust its **code** over its prose. |

**Non-cloned but cited** (read via web during research, keep for later):
- **tylertech01/propresenter-slide-builder** — a working `.proTheme`-consuming, theme-applying `.pro`/`.probundle` builder; the sole detailed source for `.proTheme` internals + "apply theme = clone template slide elements". Medium-high authority (single project, but code that demonstrably feeds PP7).
- **Renewed Vision support** — user-facing confirmation only: [Themes](https://support.renewedvision.com/hc/en-us/articles/11910559859603), [Media Management](https://support.renewedvision.com/hc/en-us/articles/360041815133), [Supported file types](https://support.renewedvision.com/hc/en-us/articles/360058902173).

### Re-clone (these live outside the iHymns repo; a fresh container won't have them)
```bash
git clone https://github.com/greyshirtguy/propresenter7-proto      # protos (7.16 → 21.4)
git clone https://github.com/ChrisMBarr/propresenter-parser        # TS parser + real fixtures
git clone https://github.com/bussnet/propresenter7-php-lib         # PHP reader/generator + specs + samples
```
Clones verified 2026-08-27 at: greyshirtguy `bf6325d`, ChrisMBarr `9444a69`, bussnet `2e3ed50`.

## 2. Real fixture files — copyright-safety triage (verified by decoding each file's `ccli`)

⚠️ **Read the file's metadata, not its name.** `bussnet/.../TestTranslated.pro` is titled "Test…" but
its content is **"Oceans (Where Feet May Fail)", © 2012 Hillsong (CCLI 6428767) — COPYRIGHTED**. Never
commit a fixture on the strength of a "Test"/sample filename; decode its `ccli` first.

### ✅ Safe to commit as golden fixtures (public-domain hymn or synthetic; no live copyright)
| Fixture (repo-relative) | Why safe | Covers |
|---|---|---|
| `ChrisMBarr .../sample-files/v7 - At the Cross.pro` | PD hymn (Watts/Hudson), no ©/CCLI in file | Mac cocoartf, basic structure |
| `ChrisMBarr .../v7 - Come Thou Fount.pro` | PD hymn (Robinson 1758); CCLI 108389 is the PD SongSelect index | Mac cocoartf, author/CCLI metadata |
| `ChrisMBarr .../v7 - Empty Single Slide.pro` | synthetic, empty | empty-slide edge case |
| `ChrisMBarr .../v7 - Feature Test.pro` | **synthetic, no ©** — **the WINDOWS-authored file** (PP 7.13.2 Win, `\rtf0` dialect) | **Windows RTF dialect**, dangling `selected_arrangement`, feature coverage |
| `bussnet .../reference_samples/Test.pro` | placeholder metadata ("Titel/Autor", ©1234) | 2 arrangements / 4 groups / repeat order |
| `bussnet .../TestMitBildernUndMakro.pro` | synthetic ("Moderation") | media + macro actions |
| `bussnet .../TestBild.probundle`, `RestBildExportFromPP.probundle` | synthetic image-test bundles (`sample-media.png`) — verify inner `.pro` before commit | both `.probundle` layouts, ZIP64 quirk |
| `bussnet .../TestPlaylist.proplaylist`, `ExamplePlaylists/*.proplaylist` | synthetic | `.proplaylist` container + item types |
| `bussnet .../all-songs/{Amazing Grace,Doxology,Stille Nacht}.pro` | PD hymns, no ©/CCLI | more PD structure samples |

### ❌ Do NOT commit (live copyright) — reference-only via the upstream repos
| Fixture | Reason |
|---|---|
| `bussnet .../TestTranslated.pro` | "Oceans (Where Feet May Fail)" — **© 2012 Hillsong, CCLI 6428767** (has a translation layer — study upstream only) |
| `bussnet .../all-songs/Cornerstone.pro` | title tied to a © Hillsong work; file claims PD but verify lyric body before any use |
| `ChrisMBarr .../v4/v5/v6 - {Be Near, Give Us Clean Hands, Jesus Saves, You Are}.*` | copyrighted modern-worship songs (also old formats, not our v7 target) |

**Rule of thumb:** commit only PD-hymn + synthetic fixtures under `tests/fixtures/propresenter/`; keep
everything else as an upstream reference (re-clone when needed). This mirrors the epic's "small/
sanitised fixtures only; no copyrighted media" guardrail.

## 3. What each fixture proves (for the import diff-harness — see the plan §8)
- **Windows vs Mac RTF** — `v7 - Feature Test.pro` (`\rtf0`, `\par`, `\csgenericrgb`) vs the Mac
  cocoartf files. The importer MUST parse both; this pair is the regression test.
- **Dangling `selected_arrangement`** — `v7 - Feature Test.pro` (set, but `arrangements[]` empty) →
  the importer must fall back to natural cue order.
- **Empty slides** — `v7 - Empty Single Slide.pro`.
- **Arrangements / repeat-group order** — `bussnet Test.pro` (2 arrangements, 4 groups, Chorus repeated).
- **Translation layers** — study `TestTranslated.pro` upstream (copyrighted — do not commit).
- **Media + macro actions, `.probundle` layouts, ZIP64 EOCD quirk** — the two bussnet `.probundle`s.
- **`.proplaylist` container + the 5 item types** — the bussnet playlists.
