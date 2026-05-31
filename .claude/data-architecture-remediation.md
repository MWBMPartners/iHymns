# Data-Architecture Remediation — Findings & Plan (DRAFT)

> **Status:** DRAFT plan, under review — **nothing actioned** (no code, no issues, no commits) until agreed.
> Built from: audit (workflow `wjnabncdu`) → DB-first design (`wyh0j1k8f`) → fresh feature-set
> redesign (`w077yh76c`). Paired with auto-memory `data-architecture-flaw-and-remediation`.
>
> **Owner principle (emphatic):** every data source is the shared MySQL DB, **queried LIVE**.
> **No JSON/SQLite cache-file system anywhere** — it is proof-of-concept residue, to be deleted.

---

## 1. Root cause (corrected by the feature-set inventory)

The MySQL migration moved **writes** to the DB but left several **reads** on the proof-of-concept
`songs.json` corpus. Crucially, it is **not** true that *all* public reads are cached — the picture is:

- **Server-rendered pages already read LIVE MySQL** (per-record / per-query): home, songbooks, single
  songbook, **single song page** (`getSongById`), search-results page (`searchSongs`), work, person,
  tune, random, stats. These propagate correctly across alpha/beta/public today.
- **Five surfaces still depend on the whole-corpus `songs.json`** (`includes/songs_cache.php` →
  `songsCacheServe()`), which is the per-environment, gitignored, deploy-seeded file cache:
  1. **Client-side search** (Fuse.js over the corpus) — main search, header autocomplete, lyrics search.
  2. **Song-of-the-Day** (reads the same client corpus to theme-filter).
  3. **Song Editor** — `?action=load` serves the full corpus; editor holds it in-memory + filters client-side.
  4. **PWA offline** — best-effort precache of `songs.json` for offline Fuse search.
  5. **Native iOS & Android apps** — bundle a `songs.json` asset at **install time** and run fully
     offline on it; **they never call the server API for songs**.
- Because the cache is per-environment and only regenerated where `save_song` ran (and the deploy
  re-seeds it from the committed `data/songs.json`), an edit on alpha is invisible to those five
  surfaces on beta/public until each environment's cache regenerates. The **140 MB** is **not** a DB
  cost — it is `SongData::exportAsJson()` materialising all ~12,370 songs at once to build that corpus
  blob; per-record queries are <2 MB.

### Secondary issues

- **`tblSongs.SongbookName`** is a denormalised copy of `tblSongbooks.Name` → songbook renames don't
  reflect until songs are re-saved.
- **Email-login NEW-user 500** — user row INSERTed + committed, then a failed follow-up SELECT throws
  (api.php:1457-1500): user exists in DB but client sees an error.
- **Service-worker `RECENT_CACHE`** survives version bumps.
- **PoC residue:** committed `data/songs.json`, `data_share/SQLite/ihymns.db` (admin presence-check
  only), per-env `data_share/setlist_json` fallback, `SongData` JSON fallback, `SharedSetlist` file
  fallback.
- **Setlists / favourites / custom tags / history** are localStorage-first with no automatic DB sync.

---

## 2. Target principles (agreed)

**GOVERNING RULE — LIVE / ONLINE-FIRST, ALWAYS.** In every case the app reads from **live MySQL**. A
fallback is used **only** when the database is genuinely unavailable, and the **only** legitimate
fallback is the **client-side PWA offline cache** (when the device has no connection). If the *server's*
MySQL is unreachable, that is an **error state** → show a graceful message; we do **NOT** serve stale
data from a file (the old `SongData` JSON fallback is removed, not kept). No staleness, ever, while the
DB is reachable.

1. **MySQL is the sole source of truth, queried LIVE on every read.** No server-side cache file/blob of
   any kind (`songs_cache.php`, `songs.json`, `ihymns.db`, JSON fallbacks all deleted). Nothing
   materialises the corpus (`exportAsJson` retired).
2. **Nothing lists all songs + lyrics.** Every unfiltered list (incl. the Editor) returns only
   lightweight fields — number, title, songbook. Full song fetched **per-record on demand**.
3. **Search is a LIVE MySQL query**, paginated, fuzzy/typo-tolerant (see §3).
4. **Client-side search, Song-of-the-Day, and the Song Editor all use live MySQL** — none keeps a corpus.
5. **PWA offline cache is an OFFLINE-ONLY fallback.** When online, the app **always** uses live MySQL
   (the SW is network-first and does **not** serve cache while online). The cache is consulted **only**
   when there is no connection.
6. **Graceful offline messaging.** Offline + nothing cached (or cache expired) → show a clear
   "you're offline / not downloaded" message. *Caveat:* the very first visit before the SW has ever
   installed is the browser's own error and outside our control; every offline load after install is
   handled by us.
7. **Every schema/data change ships a data-preserving migration runnable from Admin/Manage.** Any change
   to DB structure OR data is delivered as an idempotent `appWeb/.sql/migrate-*.php`, wired into
   `/manage/setup-database` (`$scriptMap` + `$migrationOrder` + `$migrationCards` + `$migrationProbes`
   with a **real** completion probe), and mirrored in `appWeb/.sql/schema.sql` — per CLAUDE.md #19.
   Migrations **BACKFILL / transform existing data into the new shape BEFORE any destructive step**;
   **no data in the database is ever lost** (e.g. WS-E backfills `tblSongbooks.Name` from
   `tblSongs.SongbookName` before dropping the column; WS-F/G backfill any pre-existing user data).

---

## 3. Target design, per surface

| Surface | Today | Target |
|---|---|---|
| Server pages (home, songbook, song, work, person, tune, stats) | **live MySQL** | unchanged (already correct) |
| **Client search + autocomplete + lyrics search** | Fuse.js over corpus | **live `?action=search`** — MySQL FULLTEXT (BOOLEAN) on Title+LyricsText, paginated, lightweight rows; **fuzzy/typo-tolerant** (see decision D2); preserve scripture/abbreviation expansion + tag-curated merge server-side |
| **Song-of-the-Day** | client corpus theme-filter | **live** endpoint that selects the themed song from the DB (calendar logic server-side) |
| **Song Editor** | `?action=load` whole corpus + in-memory filter | **`load_index`** lightweight paginated list (number/title/songbook) + **live MySQL filter/search** in sidebar + **per-song load on open** + **`save_song`** per-record write (already exists). Retire the corpus `load` + the whole-corpus `save` (TRUNCATE+INSERT). Reconsider **Save JSON / Export / Import-JSON** buttons — keep only as an admin import/backup tool, not a primary workflow |
| **Browse / list** | live `?action=songs` | lightweight `songs_list` (number/title/songbook), paginated |
| **Single song** | live `getSongById` | unchanged (per-record) |
| **PWA offline** | precache 5.7 MB corpus + Fuse | **slim index** (number/title/first-line/songbook, a few hundred KB) for offline browse + basic search; **network-first cache-on-view** for full songs; **existing download song/songbook** feature (`offline-ui.js`) for explicit offline. Cache used **only** when offline; refreshed on every online fetch |
| **Native iOS/Android** | bundled `songs.json`, offline-only, no API | **separate track / open decision (D1)** — either migrate to the live API (big change to their offline model) or keep bundle-based for now (stays stale). Web rewrite does **not** break them |

---

## 4. Workstreams (each its own PR to `alpha` when we proceed)

- **WS-A — DB-direct read API:** `songs_list` (lightweight, paginated) + confirm `song_detail` per-record.
- **WS-B — Live MySQL search (fuzzy):** replace Fuse; FULLTEXT + typo-tolerance (D2); keep
  scripture/abbrev expansion + tag merge; power main search, autocomplete, lyrics search.
- **WS-C — Song-of-the-Day → live** DB query (server-side calendar theming).
- **WS-D — Song Editor → live:** `load_index` + live filter + per-song load + `save_song`; retire
  corpus `load`/whole-corpus `save`; review the JSON toolbar buttons.
- **WS-E — De-denormalise `tblSongs.SongbookName`** (drop column, JOIN `tblSongbooks` on read).
- **WS-F — Setlists → DB-first + auto-sync** on create/edit/delete, user-linked; localStorage as
  client cache; offline queue; one-time localStorage→DB backfill (never clear local until server confirms).
- **WS-G — Favourites / custom tags / history → DB-first + auto-sync** (same pattern as WS-F).
- **WS-H — Email-login 500 fix** (transaction + idempotency).
- **WS-I — PWA offline rework:** slim index + network-first cache-on-view + offline-only cache use +
  graceful offline messaging + SW cache-version hygiene (`DATA_VERSION`).
- **WS-J — Decommission file caches (LAST):** delete `songs_cache.php` / `songs.json` /
  `data/songs.json` / `ihymns.db` / JSON + setlist_json fallbacks; clean `deploy.yml`. Gated on
  WS-A…D landing and on the native-app decision (D1).
- **WS-K — (if D1 = yes) Native apps → live API** (separate, large; their own plan).

---

## 5. Decisions

**Locked (owner):**
- All reads live MySQL; no server cache files; nothing materialises the corpus.
- Lightweight unfiltered lists everywhere (incl. Editor).
- Live fuzzy search; SoTD live; Editor live.
- Offline cache is offline-only fallback; online always live; graceful offline messaging.
- Setlists DB-first + auto-sync, user-linked (localStorage = cache).

**Open (need your call):**
- **D1 — Native iOS/Android:** migrate to the live API now (big change to their offline UX), or keep
  bundle-based for now and tackle later? (Doesn't block the web rewrite.)
- **D2 — Typo-tolerance mechanism for search:** MySQL ngram/trigram FULLTEXT vs phonetic (SOUNDEX) vs a
  hybrid (FULLTEXT primary + fuzzy fallback on low results). All server-side; pick on implementation.
- **D3 — JSON Export/Import in the Editor:** keep as an admin backup/import tool, or remove entirely?

---

## 6. Sequencing (proposed)

1. **WS-H** (email-login 500) — quick, independent.
2. **WS-A + WS-E** (DB-direct read API + de-denormalise) — the foundation.
3. **WS-B + WS-C + WS-D** (live search + SoTD + Editor) — move the corpus-fed surfaces to live MySQL.
4. **WS-I** (PWA offline rework) — slim index + network-first + offline messaging.
5. **WS-F + WS-G** (setlists + user stores DB-first) — parallel, independent.
6. **WS-J** (delete the file caches + deploy cleanup) — **last**, after the above are verified on all envs.
7. **WS-K** (native apps) — only if D1 = migrate; separate track.

Each lands as its own PR to `alpha`, verified, then promoted alpha→beta→main (promotion-deploy bridge
now in place). GitHub issues (epic + per-workstream, `infrastructure`/`database`/etc.) to be created
**once this plan is agreed**.
