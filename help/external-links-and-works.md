# 🔗 External Links & Works

> How iHymns links each hymn / songbook / person out to the rest of the web, and how Works group different versions of the same composition together.

---

## Find this song / songbook / person elsewhere

On every song page, songbook page and person page in iHymns you'll find a **Find this … elsewhere** panel — a categorised list of curated external links pointing at:

- **Official** — the publisher's official website, the songbook's home page
- **Information** — Wikipedia, Wikidata, Hymnary.org, MusicBrainz
- **Read** — Internet Archive scans, Open Library
- **Sheet music** — IMSLP, downloadable PDFs
- **Listen** — Spotify, Apple Music, YouTube Music, Bandcamp, SoundCloud, LibriVox audio recordings
- **Watch** — YouTube performances, Vimeo
- **Purchase** — CCLI SongSelect, publisher stores
- **Authority** — VIAF, OCLC/WorldCat, Library of Congress
- **Social** — Facebook, Instagram, Twitter/X, LinkedIn
- **Other** — anything else a curator has flagged

Each link opens in a new tab. A small ✓ tick beside the link means a curator has personally verified the URL still points at the right resource. The link text usually shows the provider name (e.g. _"Wikipedia"_, _"Spotify"_); a small grey note alongside provides extra context (_"1779 first edition"_, _"sung by King's College Cambridge"_).

Curators add new links via the in-app admin tools — if you spot a link that's gone stale or want to suggest a new one, use **Request a Song** from the menu and mention the URL in the notes.

---

## Works — same composition, multiple sources

A **Work** in iHymns groups together every version of the same underlying composition that appears across multiple songbooks. So _Amazing Grace_ — which exists in dozens of hymnals under slightly different titles, with different arrangements, sometimes translated into other languages — has one **Work** record, and every individual songbook entry is linked back to it.

Visiting `/work/<slug>` shows you:
- The canonical **title** and (where registered) the **ISWC** — the International Standard Musical Work Code
- Every version of the work across the catalogue, grouped by songbook
- The categorised "Find this work elsewhere" panel, just like the song / songbook / person pages
- **Parent / child** works for nested arrangements: an original Work can have child Works for derivative arrangements / translations / choral versions, with unlimited nesting depth (a child can in turn have its own children, and so on).

On any **song** page, if the song belongs to a Work you'll see a "Part of work" panel listing the Work and "Other versions of this work" — a quick way to jump to the same hymn in a different songbook.

### Why ISWC?

The ISWC (`T-NNN.NNN.NNN-C`) is the international identifier for a musical composition, registered with CISAC societies (BMI, ASCAP, PRS, etc.). It's optional in iHymns because not every creative work has one — many traditional / public-domain hymns predate the system, and many newer compositions haven't been registered. When supplied, it gives external integrations (CCLI tooling, MusicBrainz, royalty platforms) a stable cross-reference.

---

## Related

- [Searching Songs](searching-songs.md) — finding a hymn by title, lyrics, or songbook
- [Songbooks](songbooks.md) — navigating the available songbooks

---

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.
