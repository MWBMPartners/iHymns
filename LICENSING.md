# Licensing

This document covers the legal and licensing position of **iHymns** — the
project's own licence, the licences of the third-party software it depends on,
and the rights position of the song content it serves.

> **Summary:** the iHymns source code is **proprietary — © 2026 iHymns / MWBM
> Partners Ltd, all rights reserved**. It is built on top of permissively
> licensed open-source libraries (mostly MIT/Apache-2.0/BSD), each retained under
> its own licence. Song lyrics and music are **not** owned by iHymns and are
> governed by their respective copyright holders + CCLI/PD rules (see
> [§ Song content](#song-content--copyright)).

## 1. iHymns source code

**Proprietary. © 2026 iHymns / MWBM Partners Ltd. All rights reserved.**

The source in this repository (PHP, JavaScript, CSS, Swift, Kotlin, SQL, build
tooling and assets authored by the project) is proprietary and confidential. No
licence to use, copy, modify, distribute, sublicense or create derivative works
is granted except by a separate written agreement with the copyright holder.
Per-file headers read `@license Proprietary — All rights reserved`.

`package.json` declares `"license": "SEE LICENSE IN LICENSE"` and points here.

## 2. Third-party software dependencies

iHymns bundles / loads the following third-party libraries. Each remains under
its own licence; this list is provided for attribution and compliance. Versions
are those currently pinned in the web app (verify against the source on update).

| Library | Version | Licence | Notes |
|---|---|---|---|
| **Bootstrap** | 5.3.x | MIT | UI framework (CSS + JS bundle) |
| **Bootstrap Icons** | 1.11.3 | MIT | Icon set |
| **Font Awesome Free** | 6.7.2 | Icons **CC BY 4.0**, Fonts **SIL OFL 1.1**, Code **MIT** | Free tier — **attribution required**; do not imply FA endorsement |
| **Tone.js** | 15.1.x | MIT | Web-Audio MIDI playback |
| **pdf.js** (`pdfjs-dist`) | 4.9.124 | **Apache-2.0** | Sheet-music PDF viewer |
| **SortableJS** | 1.15.2 | MIT | Drag-and-drop card/list reorder |
| **animate.css** | 4.1.1 | **Hippocratic License 2.1** | ⚠️ See note below |
| **Swagger UI** (`swagger-ui-dist`) | 5.x | Apache-2.0 | API documentation UI |
| **jQuery** | 3.7.1 | MIT | Limited legacy use |
| **protobuf.js** (`protobufjs`) | ^8.5.0 | BSD-3-Clause | Dev dependency (ProPresenter import tooling) |

> ⚠️ **animate.css 4.x licence note.** animate.css moved from MIT to the
> **Hippocratic License 2.1** in v4 — an *ethical-source* licence with use
> restrictions (it is **not** OSI-approved). It is used only for optional,
> non-essential UI animations and is loaded from a CDN. If its restrictions are a
> concern for any distribution channel, the animations can be replaced with the
> project's own CSS keyframes (the motion layer is already abstracted behind the
> reduced-motion guards) — tracked as a licensing-review consideration.

**Compliance practice.** CDN libraries are version-pinned (with Subresource
Integrity where applicable). On any dependency add/update: record it here with
its licence, confirm licence compatibility with proprietary distribution, and
check the version against published CVEs.

## 3. Song content — copyright

iHymns is a catalogue/reader; it **does not claim ownership of the hymns and
worship songs** it indexes. Lyric and music rights remain with their respective
authors, composers and publishers. The app models this explicitly:

- Per-song **public-domain** flags are tracked **independently** for lyrics and
  music (`tblSongs.LyricsPublicDomain` / `MusicPublicDomain`) — a song may be PD
  on one axis only.
- **CCLI** licence numbers, content **access tiers**, and per-song/per-songbook
  **content restrictions** gate display vs reproduction vs export
  (`checkContentAccess()`), so copyrighted material is shown only where permitted.
- Engraving/typesetting copyright on sheet music is distinct from the underlying
  composition and is tracked per-score where applicable.

Curators and importers must respect source licensing; imported content is queued
for review and never overwrites verified records (see the import pipeline).

## 4. Trademarks

"iHymns" and associated logos are trademarks of MWBM Partners Ltd. Third-party
names (CCLI, ProPresenter, OpenLP, MediaShout, EasyWorship, MuseScore,
MusicBrainz, Hymnary.org, etc.) are trademarks of their respective owners and are
used only for identification/interoperability.

---

_Questions about licensing or commercial use: contact the repository owner.
This document is informational and not legal advice._
