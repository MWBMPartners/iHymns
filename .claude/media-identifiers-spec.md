# Shared media identifiers + core media info — cross-repo spec

> **Program (owner, 2026-08-02):** MeedyaSuite-core · MeedyaManager · MeedyaConverter · iHymns all support
> a comprehensive shared set of external/catalogue media IDs + core media info. iHymns first (epic #1741
> decision **D5**), then each Meedya repo (file a GitHub issue, then implement).
> **Grounding:** the Luminate "External IDs" KB article is WebFetch-403, so this is grounded in MWBM's own
> **MeedyaSuite-core** model + the standard music/media-industry ID landscape — NOT a guessed Luminate list.
> **Source:** Fable 5 deep analysis 2026-08-02 (read all four repos; full file:line evidence in that run's
> report — this doc is the reviewed, decision-bearing summary and is authoritative).
> **Model rule:** Fable 5 for analysis/planning, Sonnet/Haiku for implementation, never Opus 5.

## Canonical architecture
MeedyaSuite-core (Rust) is the definitional home. Flow already exists: MeedyaManager consumes it via a pinned
Cargo dep; MeedyaConverter via a planned Swift-bindings product (`SUITE_CORE` flag, off by default); **iHymns
has NO code dependency (PHP) and MIRRORS the vocabulary** in its VARCHAR-typed registries/tables (different
language + cadence + domain-only IDs like `ccli`/`hymnary-tune` that must NOT flow upstream). Agreement is held
by a **guard, not a comment** (rule #34/#35) — the live proof this is needed: core `iswc` vs MeedyaManager
`mm_iswc` META-key drift today.

## 1. ID catalogue by scope (what each identifies) — HAS / PARTIAL / MISSING per repo
**Recording:** ISRC (core✓ MM✓ MC✗ iH✓), MusicBrainz-Recording (core✓ MM✓ iH✓), AcoustID (core✓ MM✓ MC~ iH✗),
Spotify/Apple/Deezer track (core✓ iH: Spotify+Genius only), YouTube video id (STD).
**Work/composition:** ISWC (core~ MM~ iH✓ +/iswc/ resolver), CCLI (iH-only, domain), MusicBrainz-Work
(iH✓ core~), PRO/society IDs (iH `tblSongRoyaltyIds` only), HFA Song Code (STD→fits tblSongRoyaltyIds), EIDR
(core✓ MM✓ — AV), TMDB/TVDB/IMDb (core✓ MC✓ — AV).
**Release/product:** UPC/EAN/GTIN (core✓ MM✓ MC-disc✓ iH `tblSongs.Upc`), catalog number (core✓ MM✓ iH✗),
MusicBrainz-Release (core✓ MM✓ iH✗), MB-Release-Group (MM✓, **core MISSING** — gap vs its own consumer),
GRid / ICPN / DPID / Label Code (STD — no repo models them).
**Party/artist:** ISNI + IPI + IPI-base + CAE (iH-only, `tblMusicianIdentifiers`), IPN (STD→fits it),
MusicBrainz-Artist (iH typed col; MM multi-value; core map), VIAF/Wikidata/GND/ORCID/LoC/FAST/WorldCat/…
(iH-only — the 13-provider `CREDIT_IDENTIFIER_TYPES` registry, the richest party model of the four).
**Label:** name only everywhere; Label Code / DPID / MB-Label = STD.

## 2. Core media info — canonical set vs MeedyaSuite-core gaps
Descriptive (core HAS most via `CommonTag`): title, artist(s), album, track/disc no+total, duration, year,
release date, **first-published year** (aligned: core `OriginalDate`/TDOR ↔ iH `FirstPublishedYear`), label,
genre, copyright, lyrics, cover art, ReplayGain. **core gaps: Subtitle, Language, contributor roles beyond
Composer** (MM has lyricist/conductor/etc.; iH has six credit roles + `tblTuneCredits.Role`).
Technical (core HAS via `meedya-codecs`): codec/container/sample-rate/bit-depth/channels/spatial/HDR/quality;
resolution+frame-rate PARTIAL (MC-side). DJ/extended (core `meedya-tags-extended`): BPM, MusicalKey, cue/loop/
beatgrid, MIK, stems — **correctly NOT in iHymns**.

## 3. VARCHAR-not-ENUM / forward-looking findings
- **iHymns compliant** for party (registry line in `CREDIT_IDENTIFIER_TYPES`) + work (new `tblSongRoyaltyIds`
  Authority string). Two debts: **(D-a)** `tblSongIdentityMap.SourceOfTruth`/`MappingStatus` are ENUMs (pre-existing,
  self-flagged — own issue); **(D-b)** `tblSongIdentityMap` is **one-column-per-provider**
  (MusicBrainzRecordingMBID/SpotifyTrackId/GeniusTrackId/IsrcCode) — the rule-#28 anti-shape; each new provider
  (Apple/Deezer/AcoustID/Tidal) = an ALTER. Fix = a key/value successor (Q5).
- **MeedyaSuite-core:** `CommonTag` is an **exhaustive enum** MM matches totally (no wildcard) → every new variant
  is a semver-breaking, lockstep change. Growth path should be `tags.toml` + `extra_keys` + `external_ids` maps
  (all additive), reserving `CommonTag` variants for genuine per-container frame mappings; consider `#[non_exhaustive]`.
- **MeedyaManager** `tags.json5` + user-override = right shape (JSON, no Rust); only the `mm_*` key drift to fix.
- **MeedyaConverter** enum is slated for replacement by core providers — don't invest.

## 4. OPEN DECISIONS (recommendations; owner to confirm the two schema-shape ones before I implement those parts)
- **Q1 — iHymns release/product entity** (the one real product/data Q): (a) status quo (only `tblSongs.Upc`;
  a 2nd release ID has no home) / (b) full `tblReleases`+`tblReleaseIdentifiers` (real work, no consuming feature)
  / **(c, RECOMMENDED) recording-identity rows carry release-context** (UPC/catalog#/MB-Release as fields on the
  recording-map row — matches how `tblSongs.Upc` already works; no new entity; escalate to (b) only when a feature
  needs release-grain browsing). MeedyaSuite-core already HAS `Album` → attach release IDs there (no decision).
- **Q5 — iHymns recording-ID storage** (the other schema-shape Q): adopt a **key/value successor** to
  `tblSongIdentityMap` — `(SongId, IdType VARCHAR, IdValue, Source, SourceRef, …)`, existing 4 columns become
  grandfathered reads — so "comprehensive recording IDs" costs ONE migration, not one-per-provider (rule #28).
  RECOMMENDED yes.
- **Q2 — vocabulary as a shared artifact** (Meedya-phase): core-owned `identifier_types` data artifact (beside
  `tags.toml`) consumed by MM/MC via the crate; iHymns MIRRORS + a mutation-tested CI diff guard with a declared
  per-repo extension list. RECOMMENDED.
- **Q3 — core `CommonTag` growth**: `#[non_exhaustive]` next breaking release (additions non-breaking; MM loses
  compile-time totality → replace with a registry-coverage guard). RECOMMENDED. (Meedya-phase.)
- **Q4 — MM ⇄ core META-key convergence** (`mm_iswc`→`iswc`, read-both shim one release). RECOMMENDED. (Meedya-phase.)
- **Q6 — STD-only IDs (GRid/ICPN/DPID/Label-Code/IPN/HFA)**: reserve slugs in the vocabulary (free); implement
  storage only where a scope already has a home (IPN→`tblMusicianIdentifiers`, HFA→`tblSongRoyaltyIds` at zero
  schema cost; GRid/LC/DPID wait for release/label features). "All relevant IDs" per owner = implement every ID
  with a home + reserve the rest; the Luminate list, when available, slots in as slug additions, no schema impact.

## 4b. AUTHORITATIVE Luminate taxonomy (owner supplied the article 2026-08-02 — supersedes "STD/guessed")
Luminate's entity model: **Artist · Song · Recording · Musical Work · Release Group · Release · Product**
(Song→Recording/ISRC; Release Group→Release→Product/ICPN; Artist→ISNI; Musical Work→ISWC/BOWI/IPI).
- **Industry IDs** (each dashboard lists ≥1): **ISRC** (recording), **ICPN** (product barcode umbrella — UPC/EAN),
  **ISNI** (artist/name), **ISWC** (work), **BOWI** — *Best Open Work Identifier*, Luminate's open work-ID
  alternative to ISWC (**NEW to this spec** — add to iHymns work vocabulary + `tblWorks`), **IPI** (interested
  party), **GRID/GRid** (release bundle — now confirmed a real industry ID, not STD-only).
- **Provider (DSP) IDs:** Apple, Spotify, Sirius XM, YouTube, Amazon, Deezer, SoundCloud, Anghami (MENA),
  Melon (KR), FLO (KR), Beatport, Tencent, Epic.
- **Database IDs:** Gracenote, TMS, Discogs, MusicBrainz/MBID, Wikidata, IMDb, Mediabase, SoundExchange,
  AllMusic, RateYourMusic.
- **Legacy IDs:** MC_RECORDING, MC_RECORDING_GROUP, MC_RELEASE, MC_RELEASE_GROUP, MC_SONG, MC_PRODUCT,
  Nielsen, BDS, SoundScan. Plus **Luminate's own per-entity ID** (in the dashboard URL).
This is the load-bearing confirmation for **Q5=yes**: ~13 DSP + ~10 database + legacy IDs attach at
recording/song/artist grain — a column-per-provider table cannot absorb that (rule #28); a key/value store can.

## 4c. DECISIONS ADOPTED (owner delegated "all relevant IDs" + supplied the list; recommendations taken, reversible)
- **A (release entity) = (c)** recording-identity rows carry release-context (ICPN/GRid/UPC/catalog#/MC_PRODUCT
  as fields/rows on the recording-map grain). Rationale: iHymns is a hymn-lyrics catalogue, not a commercial
  release DB — product-grain IDs are marginally relevant here; MeedyaSuite-core (which HAS `Album`) models them
  first-class for the Meedya file-management/conversion apps where they ARE central. Reversible → (b) `tblReleases`
  if a release-grain feature ever lands.
- **B (recording-ID storage) = yes** — key/value successor to `tblSongIdentityMap`; the 4 existing columns become
  grandfathered reads. One migration absorbs all DSP+database IDs.
- **Relevance judgement per repo** (owner's "relevant"): iHymns implements work/party/recording IDs fully +
  reserves product/release slugs; the Meedya repos (media files) implement release/product fully via core's `Album`.
- Q2/Q3/Q4 (shared artifact, `#[non_exhaustive]`, MM key convergence) applied in the Meedya phase.

**OWNER FORMALLY CONFIRMED 2026-08-02 (after seeing the built D5):** **A = C** (no iHymns release
entity — releases stay first-class in MeedyaSuite-core's `Album`; the `tblReleases` option (b) remains
reversible/additive if a release-grain feature ever lands). **B = yes** + a refinement: *also* mirror the
grandfathered data into the new store, and *also* mirror `tblSongs.Isrc` "to make it comprehensive, and for
consistency longer term". Both A=C and B=yes were already exactly what the D5 storage (`b428f590`) is — the
confirmation required NO rework. The refinement shipped as the **D5 backfill** (`3ff77e02`): a `genius`
IdType + `migrate-backfill-song-external-ids.php` mirroring `tblSongs.Isrc` + the 4 `tblSongIdentityMap`
columns via idempotent `INSERT IGNORE` (registry card + all-5-source data-derived probe). The **ongoing**
consistency half ("longer term" = dual-write on ISRC edit + resolver union) is distinct from the one-time
backfill and is tracked as **#1749** (recommended for P5). `tblSongs.Isrc` stays the `/isrc/` resolver
authority until that deliberate cutover.

## 5. Buildable next steps (Q1=c, Q5=yes ADOPTED)
- **iHymns D5 (folds into #1741 P3):** party IDs = `CREDIT_IDENTIFIER_TYPES` lines (+IPN +any Luminate); work IDs
  = `tblSongRoyaltyIds` authorities (+HFA); recording IDs = the Q5 key/value shape + the P3 `/isrc/` resolver;
  release IDs per Q1. The shared identifier normaliser/resolver (plan §3.B) consumes this vocabulary.
- **MeedyaSuite-core issue:** the `identifier_types` registry artifact; ISWC/MB-Work/MB-Release-Group typed reach;
  Subtitle/Language/role core-info gaps; the `#[non_exhaustive]` decision.
- **MeedyaManager issue:** consume the registry; converge `mm_*` keys; add an ISWC file-tag to `tags.json5`.
- **MeedyaConverter issue:** no new modelling — track `SUITE_CORE` bindings adoption; ensure passthrough keeps
  the ID tag families (it does — `copyAll` default).
