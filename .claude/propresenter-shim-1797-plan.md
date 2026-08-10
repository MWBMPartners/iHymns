# #1797 — ProPresenter driver shim over `service_drive`: deep analysis + implementation plan

> **ANALYSIS + PLAN ONLY — no code changes.** Written 2026-08-10 (deep-analysis spike) from a full
> read of the live tree on branch `claude/issue-sweep-fixes-89`; every `file:line` cite verified
> against the working copy this date. **Feeds:** the follow-up build for #1797. **Reads first:**
> `.claude/live-follow-1770-plan.md` §4.4/§4.5/§7/§10-S1 (the shipped contract this builds ON),
> `.claude/live-congregant-strategy.md` Phase 4, CLAUDE.md rules #22/#26/#38.
> All web paths relative to `appWeb/public_html/` unless rooted.
>
> ⚠️ **GitHub access was disabled in the spike session** — issue #1797's body could not be read;
> this plan is grounded on the tree + the spike tasking. Standing-tasks item: sync this plan's
> findings back onto #1797 (and file the sub-issues in §9/§10) from a session with GitHub access.

---

## §0 Executive summary

1. **PP surface: the official ProPresenter 7.9+ HTTP API is the primary integration surface**
   (chunked-streaming `slide_index` + presentation-structure reads), with a **Bitfocus Companion
   generic-HTTP recipe as the zero-code fallback** that works against the shipped `service_drive`
   contract TODAY. Stage-Display and the legacy Remote WebSocket are rejected (reverse-engineered,
   password-bound, no reliable song/section identity). External-product facts are flagged
   ASSUMED-VERIFY throughout §2 — they cannot be confirmed from this repo.
2. **Bridge shape: a small long-running on-LAN process ("iHymns PP Bridge"), Node CLI, in a NEW
   repo** — it holds the org-admin-minted driver key (`tblServiceDriverKeys`, Protocol
   `'propresenter'` already in the vocabulary, `includes/service_driver_keys.php:84`), watches PP
   over the LAN, and POSTs `service_drive` on every slide change. The browser never sees the key.
   It **pins `sessionId` from the first 200 response** (immune to heartbeat-stale venue
   resolution) and **re-sends its last payload every ~120 s** as a keepalive (the 180 s
   follower-freshness window, `service_mode.php:94` — §3.5 has the full reasoning; a sermon-length
   gap otherwise drops every follower).
3. **`songRef` (free-text title → SongId): a READ-ONLY server-side resolution ladder**, additive to
   `service_drive`: SongId-shaped ref → (gated) org-scoped alias → exact `Title` → the
   `NormalizedTitle` indexed fold (`ihymns_normalize_title()`) → fuzzy candidates via the ONE
   scorer `ihymns_sim_score()` — **fuzzy NEVER auto-drives**; unresolved answers 422 with a
   `candidates` array and the bridge/operator confirms once, then caches. **Deliberately NOT
   `lyricsIngest_resolveSong()`** (its step-4 tail CREATES a provisional song — a typo'd PP
   presentation name minting junk songs mid-service is the failure mode to design out,
   `lyrics_ingest.php:588-590`) and **NOT `tblSongIdentityMap`** (frozen legacy since #1749,
   `schema.sql:3430-3441` — nothing reads or writes it live; do not revive it).
4. **`sectionRef`: the label-fold stays server-side** (it already lives there,
   `serviceMode_resolveSectionIndex()`, `service_mode.php:1836`); the bridge sends PP group names
   verbatim. The spike found a REAL round-trip gap to fix additively: the exporter emits group
   names `Tag`/`Coda`/`Interlude` (`manage/editor/propresenter-export.js:299-310`) that the
   resolver folds AWAY from their own stored types (`tag→outro`, `coda→outro`,
   `interlude→refrain`, `service_mode.php:1690-1702`), so a song whose component IS
   `type='tag'` cannot be addressed by the label "Tag"; `ad-lib` is unaddressable entirely; and
   the `/^([a-zA-Z\-]*)\s*(\d*)\s*$/` regex (:1843) rejects the common space form "Pre Chorus 2".
   Fix = **exact-type-first (empirical, against the song's own component types) + a space/hyphen
   pre-fold** — purely additive, previously-unresolvable labels start resolving.
5. **The server contract is NOT broken, only extended** (the whole point of the shim boundary,
   rule #26): `songRef` moves from `422 song_ref_unsupported` (api.php:18471-18473, documented
   "NOT SUPPORTED YET" api-docs.yaml:12887-12889) to *attempted* — resolved → 200 with additive
   `songResolved`/`songId` fields; unresolved → still 422, new code `song_ref_unresolved` +
   `candidates`. Clients branch on HTTP status (rule #35), so the status-class stability is the
   compatibility guarantee. An optional additive `resolveOnly: true` gives the bridge a dry-run
   through the SAME code path (one mechanism — the resolution used in rehearsal is byte-identical
   to the one used live). Every existing request shape stays fixture-diff byte-identical.
6. **Split of work**: in THIS repo, one PR (§6: C1 dormant alias table [sub-decision D2] → C2
   resolver + fold fixes → C3 endpoint extension → C4 mutation-proven guards, incl. a born-RED
   exporter-label↔resolver round-trip guard → C5 docs incl. the Companion recipe). The bridge
   itself is an **out-of-repo deliverable** (new repo, §3.7) — exactly what api-docs.yaml:12815-
   12820 already declares (“its consumer is, by construction, out-of-repo”). The live verify
   needs a real ProPresenter install (the PROPRESENTER-TESTING.md §5 manual-tester precedent) and
   two devices on ONE channel (#1339-class) — it can never run in this environment.

---

## §1 Verified current-state anchors (the facts the plan builds on)

| Fact | Where |
|---|---|
| `service_drive` shipped: POST, key-authed, per-key rate-limited (600/min on a hash bucket), org+channel-scoped session resolution, opaque 404 | api.php:18417-18594 |
| `songRef` answers 422 `song_ref_unsupported` BEFORE anything else, so a shim fails loud | api.php:18471-18473; api-docs.yaml:12887-12889, :12927-12929 |
| Session resolution: explicit `sessionId` path has NO heartbeat-freshness predicate; the `venueId` path requires `LastHeartbeatAt` within 180 s | api.php:18485-18514; `LIVE_SESSION_FRESHNESS_SECONDS = 180`, service_mode.php:94 |
| `songId` omitted → keep current song; present-but-invalid → 400 after `songVisibleSql`/`songServableSql` | api.php:18534-18548 |
| `sectionIndex` wins over `sectionRef`; unresolvable ref → song-level + `sectionResolved:false` | api.php:18550-18572, :18592 |
| The ONE write core `serviceMode_applyBroadcast()` — bumps `StateRevision`, touches `LastHeartbeatAt` (a broadcast IS a heartbeat) | service_mode.php:1653-1677 |
| Section resolver + its closed label map + arrangement projection | service_mode.php:1690-1702 (map), :1748-1797 (projection), :1836-1888 (resolver; bare number defaults to `verse`, :1848-1849) |
| Stored component-type vocabulary: `verse chorus refrain bridge pre-chorus tag coda intro outro interlude vamp ad-lib` | includes/song_importers.php:4377-4381 |
| The assembler defaults an empty type to `'verse'` | includes/lyric_lines_read.php:163 |
| Driver-key lifecycle core: `'ihdrv_live_'` raw prefix, SHA-256-only storage, Protocol vocab `generic|propresenter|openlp` | includes/service_driver_keys.php:78-132, :189-237 |
| Admin mint/list/revoke card LANDED on the projection page, incl. a Protocol select | manage/service-projection.php:128-149, :247-330, :573-650 |
| `service_drive` is allow-listed as a deliberately out-of-repo-consumer surface | tests/php/fixtures/orphan-allowlist.php:289-308 |
| State vocabulary a driver may send: `displayState`/`blank`, `lineIndex` 0-9999, `scrollPct`, `transposeOffset` | serviceMode_cleanState(), service_mode.php:295-333 |
| `_liveFollowCleanSongId()` strips to `[A-Za-z0-9_-]` ≤20 — SongId refs survive it | api.php:19849-19852 |
| Ingest resolver ladder (explicit id → ISRC → exact/normalized title + artist tiebreak → **CREATE**) — the create tail is what makes it unsuitable to call directly | includes/lyrics_ingest.php:437-591 |
| `ihymns_normalize_title()` — the EXACT dedup fold (rule #22) | includes/title_normalize.php:27 |
| `tblSongs.NormalizedTitle` is app-maintained + INDEXED (`idx_NormalizedTitle`) — the fast pre-filter | appWeb/.sql/schema.sql:272, :322 |
| `tblSongIdentityMap` is FROZEN LEGACY (#1749) — “Do NOT build a live store->identity-map sync”; recording IDs live in `tblSongExternalIds` (global recording identity — the WRONG grain for a per-church PP mapping) | schema.sql:3420-3481 (note :3430-3441), :3484-3510 |
| The ONE fuzzy scorer `ihymns_sim_score()` (#1216) — never re-fork the maths | includes/song_similarity.php; CLAUDE.md rule #22 |
| iHymns already EXPORTS to PP7: protobuf `.pro` via the vendored greyshirtguy 7.16 schema; group names from `COMPONENT_LABEL_MAP` (“Verse 1”, “Chorus”, “Tag”…); cue name = short form (“V1”, “C”) | manage/editor/propresenter-export.js (header, :299-320, :477-535) |
| The exporter emits ONE “Default” arrangement in RAW component order — it does NOT read `song.arrangements[]` (“Future work”), while `componentIndex` is ARRANGEMENT-projected render order → **group ordinal ≠ componentIndex** when a song has an `ArrangementJson` | propresenter-export.js:538-559 vs service_mode.php:1748-1797 |
| Export tests + the #1788 static-encoder CSP guard prove the proto knowledge is real and CI-covered | tests/test-propresenter-export.js, tests/test-propresenter-static-csp.js |
| Phase-4 precedent: “ProPresenter = desktop/no-cloud → needs a small on-LAN bridge (official HTTP API in 7.9+, else the legacy WebSocket)… The real work is song-identity mapping” | .claude/live-congregant-strategy.md:73-78 |
| The #1770 plan flagged exactly this spike and its open questions | .claude/live-follow-1770-plan.md:511-527 (§7), :595-601 (§10 S1) |

---

## §2 ProPresenter integration surfaces — comparison + recommendation

⚠️ **Epistemic framing:** ProPresenter is an external product; NOTHING in this repo documents its
network protocols (the repo's PP knowledge is the `.pro` FILE format via the vendored
reverse-engineered proto set + the community repos the exporter's header cites —
greyshirtguy/ProPresenter7-Proto, jeffmikels/propresenter_parser). Every row below is therefore
**assumed-from-general-knowledge, to be verified against a real PP7 install** (the
PROPRESENTER-TESTING.md §5 manual-scenario precedent). The verify steps are in §2.4.

### 2.1 The candidate surfaces

| Surface | Current-presentation + slide-index signal | Usable song/section label | Auth / setup burden (church AV operator) | Availability |
|---|---|---|---|---|
| **A. Official HTTP API** (“Network API”, PP ≥7.9, documented at openapi.propresenter.com) | ✅ `GET /v1/presentation/active` (presentation UUID + name), `GET /v1/presentation/slide_index` (active presentation + global slide index); **push-like** via HTTP chunked streaming (`?chunked=true` on status endpoints) — an update per slide change, no polling | ✅ `GET /v1/presentation/{uuid}` returns the presentation structure incl. groups (name, colour, per-group slides) → slide-index → group-name derivation; `GET /v1/status/slide` gives current/next slide TEXT | LOW: enable Network in PP Preferences, note IP:port. Believed **no auth** on the HTTP API (LAN-trust) — VERIFY | PP 7.9+ (2022), macOS + Windows; the whole PP7 subscription base auto-updates |
| **B. Legacy Remote-control WebSocket** (`/remote`, controller password) | ✅ events exist | partial | MEDIUM: password + an undocumented, version-brittle protocol | PP6 + PP7; reverse-engineered only |
| **C. Stage Display protocol** | ⚠️ current/next slide TEXT + notes only | ❌ no presentation UUID, no group labels — would force lyric-TEXT matching | MEDIUM: stage password; reverse-engineered | PP6 + PP7 |
| **D. Bitfocus Companion** (community PP module rides surface A; Generic-HTTP action + trigger-on-variable-change) | ✅ (via A) | ✅ (via A, as exposed module variables) | MEDIUM: the church must run Companion — but MANY AV desks already do (Stream Deck) | wherever A is |
| **E. NDI / screen scraping** | ❌ OCR of a video feed | ❌ | absurd | — |

### 2.2 Recommendation

**Primary: surface A (the official HTTP API), consumed by the dedicated bridge (§3).** It is the
only DOCUMENTED surface; it exposes exactly the two identities the shim needs (a durable
presentation UUID for song mapping §4, a group name for section mapping §5); chunked streaming
means one outbound `service_drive` POST per slide change with no polling loop; and its setup story
for a church volunteer is a single Preferences checkbox.

**Fallback / Day-1: surface D as a DOCUMENTED RECIPE, not code.** A Companion trigger on the PP
module's current-slide variable firing a Generic-HTTP POST of
`{"songRef": "$(presentation_name)", "sectionRef": "$(group_name)"}` at
`/api.php?action=service_drive` with the driver key in an `X-API-Key` header **already works
against the shipped contract for the sectionRef half, and works fully once §4 lands** — zero code
from us, and it doubles as living documentation of the generic contract (#1770 §7's promise).
Lands as a wiki page in C5.

**Rejected:** B (undocumented + version-brittle — the exact maintenance treadmill the
protocol-agnostic `service_drive` boundary exists to keep out of the server), C (no song identity
— it would force fuzzy LYRIC-text matching per slide, the least reliable signal available), E
(obviously), and **PP6 support in any form** (owner decision D4 — PP6 is end-of-life; supporting
it means adopting surface B's treadmill).

### 2.3 What the API gives the shim, concretely (ASSUMED-VERIFY)

- **Song identity**: `presentation.id.uuid` (durable per .pro file — survives renames) + the
  presentation NAME (the human title, e.g. “10,000 Reasons (Bless The Lord)”). The UUID is the
  bridge's cache key (§4.5); the name is the `songRef` sent to iHymns on cache miss.
- **Section identity**: the global `slide_index` + the presentation's group structure → the
  ACTIVE group's `name` (“Verse 2”, “Chorus”, “Tag”, or a custom label). The bridge sends that
  name verbatim as `sectionRef`.
- **NOT safe to use: group ORDINAL → `sectionIndex`.** Two independent reorderings break the
  arithmetic: PP arrangements reorder groups on the PP side, and on the iHymns side
  `componentIndex` is arrangement-PROJECTED render order while an iHymns-exported .pro's groups
  are RAW component order (propresenter-export.js:538-544 emits only a “Default” arrangement and
  explicitly defers reading `song.arrangements[]`). Label-based `sectionRef` is immune to both.
  (v2 could add exported-by-iHymns detection + index math; not v1.)

### 2.4 The verify checklist (first task of bridge build B1, on a real PP7 install)

1. API reachability + port discovery + whether ANY auth applies to the HTTP API (vs the
   remote-app passwords).
2. Whether `slide_index` counts through the ACTIVE arrangement's expanded order or the base group
   order — the bridge's slide→group derivation must match whichever it is. (The label-first
   design tolerates either; only the derivation code cares.)
3. `?chunked=true` stream cadence + reconnect semantics (half-open sockets after PP restarts).
4. Whether the current GROUP name is directly available on a status endpoint (would remove the
   presentation-structure fetch entirely) — check `GET /v1/status/slide`'s payload.
5. Confirm minimum PP version in the church-facing docs from what the live install reports
   (`GET /version`).

---

## §3 The bridge — deployment design

### 3.1 Where it runs

A **small long-running process on the church's LAN**, normally on the SAME machine that runs
ProPresenter (the AV desk laptop/Mac mini) — PP's API is LAN-only (live-congregant-strategy.md:73-78:
“desktop/no-cloud → needs a small on-LAN bridge”). It makes OUTBOUND HTTPS to iHymns only; it
listens on nothing (v1) — no port-forwarding, no firewall work for the church.

### 3.2 What holds the driver key

**The bridge's local config file, and nothing else.** The org-admin mints the key on
`/manage/service-projection` (the shipped card, Protocol = `propresenter`,
service-projection.php:247-330) and pastes it into the bridge config ONCE. The key never touches
a browser page, a QR, or PP itself. Revocation is instant server-side
(`serviceDriverKeyRevoke()`, service_driver_keys.php:251-271) and the bridge halts on 401 (§3.6).

### 3.3 Config (the whole static surface)

```jsonc
{
  "ihymnsBaseUrl": "https://www.ihymns.example",   // ⚠ MUST be the docroot the congregation uses — §3.8
  "driverKey":     "ihdrv_live_…",                 // minted on /manage/service-projection
  "venueId":       12,                              // optional if the key itself is venue-bound
  "ppHost":        "127.0.0.1",
  "ppPort":        1025,
  "keepaliveSecs": 120                              // must stay < the server's 180 s window
}
```

### 3.4 The loop

1. Open the PP chunked stream (slide-index/status). On each change event:
2. Resolve the ACTIVE presentation → **song** via the UUID-keyed local cache; on miss run the
   §4.5 resolve-and-confirm flow; cache the result.
3. Derive the ACTIVE group name → send verbatim as `sectionRef`.
4. `POST {ihymnsBaseUrl}/api.php?action=service_drive` with
   `Authorization: Bearer <key>` and `{songId, sectionRef}` (songId from cache; songRef only
   during resolution). First 200 → **pin `sessionId` from the response** and send it on every
   subsequent call (the explicit-sessionId path has no heartbeat-freshness predicate,
   api.php:18485-18495, so a pinned bridge cannot be locked out by its own silence; `IsActive=1`
   still ends it cleanly when the operator ends the session).
5. Remember the last successful payload for §3.5.

### 3.5 The keepalive discipline (an operational finding, not a nicety)

A PP-driven church projects from PP — **`/manage/service-projection` may not be open anywhere**,
so nothing else heartbeats the session. `serviceMode_applyBroadcast()` touches `LastHeartbeatAt`
on every write (service_mode.php:1660), but a sermon is a 30-minute slide gap: after 180 s
(service_mode.php:94) follower polls read the session as not-fresh → every congregant's client
shows the session ended; and an UN-pinned bridge's venue resolution 404s
(api.php:18505-18511). **Therefore the bridge re-POSTs its LAST successful payload every
`keepaliveSecs` (120 s default).** Re-sending the identical `{sessionId, songId, sectionRef}` is
visibly a no-op for followers (same song, same section; `StateRevision` bumps harmlessly) and
counts as a heartbeat. ⚠️ The keepalive must be the FULL last payload — an empty-body
`service_drive` keeps the song but NULLs `componentIndex`/`StateJson`
(api.php:18534-18536, :18555, :18574 — nothing carries the previous section over), which would
visibly snap every follower back to song-level.

### 3.6 Failure handling (status-code contract, rule #35)

| Status | Bridge behaviour |
|---|---|
| 404 (opaque unknown/ended) | drop the pinned sessionId, fall back to venue resolution, retry with backoff (the session may simply not have started yet — pre-service idle is NORMAL) |
| 401 | halt + surface loudly (key revoked/expired) — never retry-forever |
| 422 `song_ref_unresolved` | enter the §4.5 confirm flow; drive NOTHING for that presentation until confirmed (never guess a song at a congregation) |
| 429 / 503 | exponential backoff; 503 also covers an un-migrated server |
| network error | backoff + re-open the PP stream (PP restarts mid-service happen) |

### 3.7 Packaging: language + home (owner decision D1)

Recommendation: **Node ≥22 CLI in a NEW repo** (suggested: `MWBMPartners/iHymns-PPBridge`).
Why Node: every tool/test in this org is already Node (`tools/*.js`, the whole `tests/` harness,
protobufjs); the PP-format knowledge is in Node; a future “read the .pro library for pre-service
mapping” feature could literally import knowledge from `propresenter-export.js`'s domain. Why a
new repo: the consumer is out-of-repo BY DESIGN (api-docs.yaml:12815-12820 says so), it ships to
churches on its own cadence, and it must never grow an import on server code. Distribution v1 =
`npm`/git + `node bridge.js`; v2 = a single-file executable (Node SEA/pkg-class) so a church
volunteer gets one .exe/.app — that is B2, not B1. (Go was considered for the single-binary win
alone; rejected: a second language for one deliverable, and B2 closes the gap.)

**Buildable in THIS environment:** everything in §6's C-commits. **NOT buildable/verifiable
here:** the bridge's live behaviour (needs a real PP install + LAN) and the end-to-end verify
(needs two devices on ONE channel — the #1339 class).

### 3.8 The channel wall, restated for the bridge README (rule #26)

A session is walled to its `Channel` (the serving docroot). The bridge's `ihymnsBaseUrl` MUST be
the SAME docroot the operator started the session on and the congregation joins on — a bridge
pointed at `dev.` can never drive a session the congregation follows on `www.`, and the failure
is the designed opaque 404. This is the standing two-device-test trap (#1339/#1792); it will be
the #1 support question, so it goes in the config comment AND the wiki page.

---

## §4 `songRef` — free-text title → SongId (the deliberate 422 gap this spike owns)

### 4.1 What NOT to reuse, and why (verified)

- **NOT `lyricsIngest_resolveSong()` called directly.** Its ladder is right; its TAIL is wrong
  for this caller: step 4 CREATES a provisional song in `Misc` when nothing matches
  (lyrics_ingest.php:588-590) — correct for an ingest pipeline, catastrophic for a live-service
  driver (any typo'd PP presentation name would mint a junk song row mid-service). The build
  reuses the ladder's SHAPE and its exact helpers, in a new read-only resolver.
- **NOT `tblSongIdentityMap`.** Frozen legacy per #1749 — schema.sql:3430-3441 says in terms:
  provider columns absorbed one-way into `tblSongExternalIds`, “nothing reads or writes these
  four columns live today”, table gated on the #1010 DB-merge decision. Reviving it here would
  be the exact regression that note forbids.
- **NOT `tblSongExternalIds` for the PP mapping.** It is GLOBAL recording identity
  (schema.sql:3505-3510, app-validated `IdType` from `media_identifiers.php`). A PP presentation
  UUID is **per-church**: two churches' PP libraries map the same song under different UUIDs, and
  the same UUID means nothing across orgs. Wrong grain — the mapping is org-scoped (§4.4).

### 4.2 The read-only resolution ladder — `serviceMode_resolveSongRef()`

New helper in `includes/service_mode.php` (extending the ONE core, rule #26 I4):

```
serviceMode_resolveSongRef(\mysqli $db, string $ref, ?int $orgId): array
  → ['songId' => ?string, 'candidates' => list<{songId,title,songbook,score}>]
```

1. **SongId-shaped ref** — `<letters>-<digits>` (rule #27's parse) surviving
   `_liveFollowCleanSongId()`: check existence under the SAME
   `songVisibleSql`/`songServableSql` predicates `service_drive` already applies to `songId`
   (api.php:18538). An operator who pastes “MP-1008” into a PP presentation name gets exact,
   unambiguous behaviour.
2. **Org-scoped alias** (gated on D2's table existing — §4.4): exact `(OrgId, Source,
   SourceRef)` hit → resolve. `SourceRef` here is the folded title (the bridge keys its OWN
   cache by PP UUID locally; the server alias uses the fold so it also serves Companion-recipe
   users, who only have the name).
3. **Exact `Title = ?`** (visible+servable) — exactly ONE hit → resolve.
4. **`NormalizedTitle = ihymns_normalize_title($ref)`** over `idx_NormalizedTitle`
   (schema.sql:272, :322 — the indexed pre-filter built for exactly this, #1066 Theme D) —
   exactly ONE hit → resolve.
5. **Fuzzy candidates, never auto-resolve**: bound the scan by the first-normalized-word LIKE
   pre-filter (the lyricsIngest_resolveSong:533-547 shape), score with **`ihymns_sim_score()`**
   (the ONE scorer, rule #22 — the third consumer after the batch builder and
   `/manage/duplicate-songs`), return the top ≤5 as `candidates`. The FUZZY fold
   (`ihymns_sim_normalise()`, strips a leading article) stays distinct from step 4's EXACT fold —
   rule #22's two-normalisers discipline.
- **Ambiguity at ANY exact step (≥2 hits) → candidates, no resolve.** Driving the WRONG song to
  a congregation is strictly worse than driving nothing — the same never-a-guess posture as
  owner req #7 on sections and lyricsIngest's own ambiguous→don't-guess artist tiebreak.

### 4.3 The additive `service_drive` contract extension

- `songId` present → wins outright; `songRef` ignored (explicit beats free-text — mirrors the
  shipped `sectionIndex`-beats-`sectionRef` precedent, api.php:18550-18554).
- `songRef` present, resolves → proceed exactly as if that `songId` had been sent; **200
  response gains additive `songResolved: true` and echoes `songId`** (naming symmetry with the
  shipped `sectionResolved`).
- `songRef` present, unresolved/ambiguous → **still 422** (status class unchanged — rule #35's
  compatibility guarantee), body `{error, code: 'song_ref_unresolved', candidates: […]}`.
  **No write happens** (no partial drive with only the section applied).
- **`resolveOnly: true`** (additive, optional): run auth + session resolution + song/section
  resolution, answer with the outcome, **skip the `serviceMode_applyBroadcast()` write**. This
  is the bridge's pre-service rehearsal pass (“map my whole PP playlist before 10:00”) through
  the IDENTICAL code path — one mechanism, so rehearsal and live can never diverge (rule #35).
  A separate read action was considered and rejected: it would duplicate the auth + org/venue
  session-resolution + rate-limit block for zero behavioural difference.
- Candidate exposure is not an information leak: the catalogue is public (the PWA's public
  search serves the same titles); the key is org-authed anyway.

### 4.4 The org-scoped alias store (owner decision D2 — recommended, dormant)

The v1 flow needs ZERO schema (the bridge caches UUID→SongId locally after confirm). But the
foreseeable v2 wants server-persisted, org-shared mappings (a replaced AV laptop loses the local
cache; two venues/machines of one org re-confirm the same titles; a future operator confirm-UI
on `/manage/service-projection` needs somewhere to write). Rule #20 says design the family's
FINAL schema once and ship it dormant rather than dribble a second migration later:

```sql
CREATE TABLE IF NOT EXISTS tblServiceSongAliases (
    Id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    OrgId     INT UNSIGNED NOT NULL COMMENT 'FK tblOrganisations — aliases are per-church (#1797)',
    Source    VARCHAR(30)  NOT NULL DEFAULT 'propresenter' COMMENT 'Shim family vocab — same values as tblServiceDriverKeys.Protocol; VARCHAR not ENUM (rule #20)',
    SourceRef VARCHAR(190) NOT NULL COMMENT 'The external identity being mapped: the ihymns_normalize_title() fold of the presentation/plan title (#1797 §4.2 step 2)',
    SongId    VARCHAR(20)  NOT NULL COMMENT 'FK tblSongs.SongId',
    CreatedBy INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the confirming operator; NULL = machine-learned (reserved, v1 never writes)',
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Org_Source_Ref (OrgId, Source, SourceRef),
    INDEX idx_Song (SongId),
    CONSTRAINT fk_SongAlias_Org  FOREIGN KEY (OrgId)  REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_SongAlias_Song FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)     ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_SongAlias_User FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id)      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-organisation free-text→SongId aliases for external presentation-app drivers (#1797). Dormant until the read arm + a confirm surface consume it.';
```

Adversarial second-migration stress: per-venue narrowing (rejected — a church's PP library is
org-wide; same titles either side of a venue split); several sources (✓ `Source` in the UNIQUE);
UUID-keyed server aliases later (✓ just another `Source` value, e.g. `'propresenter-uuid'`);
audit (✓ `CreatedBy` + `tblActivityLog`). The `UNIQUE` makes resolution deterministic by
construction — one ref can never map to two songs. **v1 ships the table + the gated READ arm
only; the WRITE path is the deferred operator confirm-UI** — deliberately NOT a driver-key write
(a stolen key can already drive wrong songs transiently; letting it poison DURABLE mappings is a
worse class, so alias writes stay behind an authenticated org-admin surface).

### 4.5 The bridge-side confirm flow (out-of-repo)

Cache hit by PP UUID → drive. Miss → `service_drive {resolveOnly:true, songRef:<name>}` →
`songResolved` → cache + drive; `song_ref_unresolved` + candidates → surface the candidates for
a ONE-TIME human pick (v1: CLI prompt / simple list; B2: localhost status page), cache the pick,
drive. Until confirmed, that presentation drives NOTHING (no guessing). Pre-service, the bridge
can rehearse the whole PP playlist via `resolveOnly` so Sunday morning has zero prompts.

---

## §5 `sectionRef` — PP slide-group name → section (the fold stays server-side)

### 5.1 Where the fold lives: the server, full stop

`serviceMode_resolveSectionIndex()` already IS the one fold (service_mode.php:1836-1888), it is
already arrangement-projection-aware (:1748-1797 — the part a shim could never replicate without
re-reading the song), and rule #22 forbids a second copy. **The bridge sends PP group names
verbatim.** A bridge-side fold would also have to be duplicated into the Companion recipe —
i.e. instantly forked.

### 5.2 Verified round-trip gap (the spike's concrete finding)

The stored component-type vocabulary is
`verse chorus refrain bridge pre-chorus tag coda intro outro interlude vamp ad-lib`
(song_importers.php:4377-4381). iHymns' OWN PP exporter emits group names for all of them
(“Tag”, “Coda”, “Interlude”… — propresenter-export.js:299-310). But the resolver's map folds
`tag→outro`, `coda→outro`, `ending→outro`, `interlude→refrain`, `vamp→refrain`
(service_mode.php:1690-1702) and has no `ad-lib` entry at all. Consequences, on today's tree:

- A song whose component IS `type='tag'` (a stored, first-class type) cannot be addressed by the
  label “Tag” — the resolver looks for `outro`-typed components, finds none, returns null →
  song-level fallback. Same for `coda`, `interlude`, `vamp`; `ad-lib` is unreachable by any label.
- “Pre Chorus 2” (space form — common in real PP libraries) fails the
  `/^([a-zA-Z\-]*)\s*(\d*)\s*$/` regex outright (:1843): the word group stops at “Pre”, “Chorus”
  is not digits → no match → null.
- So a shim replaying the very labels iHymns' own export wrote gets silent song-level fallback
  on those sections — the rule-#35 “two files must agree with nothing enforcing it” shape, here
  between `COMPONENT_LABEL_MAP` (JS) and `SERVICE_MODE_SECTION_LABEL_MAP` (PHP).

### 5.3 The additive fix (C2)

1. **Pre-fold** the incoming word: lowercase, collapse internal spaces/underscores to `-` (so
   “Pre Chorus” ≡ “pre-chorus”), tolerate ONE trailing letter after the number (“Verse 1a” from
   slide-split conventions) by extending the regex, discarding the letter.
2. **Exact-type-first, empirically**: after folding, if the word EQUALS one of the song's OWN
   component types (collect the distinct `type` values from the already-assembled components —
   no new vocabulary list to drift, no cross-file import), match against that type directly;
   only THEN fall back through `SERVICE_MODE_SECTION_LABEL_MAP`. This fixes every §5.2 case
   while keeping today's resolutions: a song with no `tag` component still folds “Tag”→`outro`.
3. **Honest behaviour-change note**: a song having BOTH a `tag` and an `outro` component
   currently resolves “Tag” to the outro; after the fix it resolves to the tag — closer to the
   operator's intent, documented in the commit body, and the round-trip guard (§8 G-A) pins the
   new truth.
4. Custom PP group names (“Turnaround”, non-English “Coro”) stay unresolvable → song-level
   fallback (the shipped, owner-approved behaviour: never a guess). NOT extending the map with
   guesses; churches wanting section-follow name groups conventionally (goes in the wiki page).

---

## §6 Implementation plan — what lands where

### 6.1 In THIS repo (one PR to `alpha`, follow-up build; each commit atomic)

| # | Commit | Contents | Verification (rule #34 / #19 discipline) | Dormant? |
|---|---|---|---|---|
| C1 | `schema: dormant org song-alias table (#1797)` *(drops out if D2 = bridge-local-only)* | §4.4 DDL as `migrate-service-song-aliases.php` + byte-identical schema.sql mirror + ONE `migration-registry.php` entry + probe | `php -l`; `test-schema-coverage.php`; `test-migration-registry.php`; card idempotence ×2 on scratch DB | **Pure no-op** — nothing reads it until C3 |
| C2 | `service-mode: read-only songRef resolver + section-fold fixes (#1797)` | `serviceMode_resolveSongRef()` (ladder §4.2, alias arm table-existence-gated); §5.3 pre-fold + exact-type-first in `serviceMode_resolveSectionIndex()` | Pure matrix tests: exact/normalized/alias/fuzzy/ambiguous/SongId-shaped; section fold: every §5.2 case flips resolvable; existing resolutions unchanged (fixture list derived from the CURRENT map) | Helpers unreferenced until C3 (fold fix live but strictly additive) |
| C3 | `service_drive: attempt songRef + resolveOnly (additive) (#1797)` | api.php:18471-18473's early 422 becomes the §4.3 attempt; `songResolved`/`songId` response fields; `resolveOnly`; api-docs.yaml sync | **Fixture-diff: every EXISTING request shape byte-identical pre/post** (no songRef → identical; songId+songRef → songId wins). New: resolved/unresolved/ambiguous/resolveOnly paths; no write on 422; per-key 429 unchanged | Behaviour change ONLY for callers sending `songRef` — which today get a hard 422 |
| C4 | `guards: #1797 invariants (mutation-proven)` | §8 G-A/G-B/G-C | Each broken-red-restored, documented in the commit body; G-A is BORN RED on the pre-C2 tree (its first run proves it can fail) | n/a |
| C5 | `docs + consistency (#1797)` | wiki: “ProPresenter bridge” page (+ the Companion recipe §2.2 + the §3.8 channel warning); help topic touch-up; CHANGELOG; `.claude/ProjectBrief.md`; issue updates w/ SHAs; file the bridge-repo issue + D-decision issues | Standing-tasks checklist (incl. the deferred GitHub sync noted in this file's header) | n/a |

No client (browser) work at all — this feature has no in-app UI beyond the already-shipped admin
card. No CSP/fragment/router concerns arise.

### 6.2 Out-of-repo (the bridge deliverable — new repo per D1)

| # | Phase | Contents |
|---|---|---|
| B0 | Companion recipe | Documentation only — ships inside C5's wiki page; works Day 1 (fully once C3 lands) |
| B1 | Bridge v1 (Node CLI) | §3.3 config, §2.4 verify checklist EXECUTED against a real PP7, chunked observe loop, §4.5 resolve-and-confirm (CLI prompt), §3.4 drive + sessionId pinning, §3.5 keepalive, §3.6 failure table, local UUID→SongId cache file |
| B2 | Packaging + status page | Single-file executable; localhost status/confirm page; auto-reconnect polish |
| B3 | Live end-to-end verify | Real PP + bridge + one docroot + two devices on ONE channel (the #1339 class — has never been executable in this environment) |

---

## §7 What would break the shipped contract — and how we avoid it

The shim boundary's whole point (rule #26 / #1770 §7) is that the server contract is STABLE.
This spike concludes **no breaking change is needed** — everything is additive. The near-misses,
named so the builder does not wander into them:

1. **Repurposing the 422.** Shipped: any non-empty `songRef` → 422 `song_ref_unsupported`
   (api.php:18471-18473). After C3: unresolved `songRef` → 422 `song_ref_unresolved`. The HTTP
   status — the contract per rule #35 — is unchanged for the failure class; a shim built
   against the shipped docs (which say “NOT SUPPORTED **YET**”) treats 422 as “songRef didn't
   work” and is unaffected. What WOULD break it: moving the failure to a 200-with-flag (silent
   for status-branching clients) or a 404 (colliding with the opaque session-miss). Avoided by
   construction in §4.3.
2. **Making previously-valid requests behave differently.** Guarded by C3's fixture-diff:
   requests without `songRef` are byte-identical pre/post; `songId` still wins over everything.
3. **Weakening the opaque 404 / org scoping / channel wall / per-key rate limiting** while
   adding the resolver. `serviceMode_resolveSongRef()` runs AFTER key auth + session resolution
   and carries the same visible/servable predicates; no new query touches
   `tblLiveFollowSessions` (nothing new to channel-filter — §8 G1-ride confirms).
4. **A section fold that REGRESSES a resolvable label.** §5.3 only adds resolutions; the one
   documented preference change (exact type over folded type when both exist) is pinned by G-A.
5. **`resolveOnly` accidentally writing.** The flag's guard sits immediately before the ONE
   `serviceMode_applyBroadcast()` call — and G-B mutation-proves the no-write.
6. **The bridge creating server-side coupling.** The bridge consumes ONLY `service_drive` (+ its
   own PP LAN surface). If B1 discovers it needs anything more from the server, that is a
   FINDING to bring back here as an additive proposal — never a licence to redesign the endpoint
   (this spike's own tasking, restated for the bridge repo's README).

---

## §8 Guards (rule #34 — tree-derived, mutation-proven, narrow)

- **G-A `tests/test-pp-label-roundtrip.js` — exporter-label ↔ resolver agreement.** Derive the
  emitted label set from `COMPONENT_LABEL_MAP` in `manage/editor/propresenter-export.js` (parse
  the source — comment-stripped, the `test-qr-cuercode.js` discipline) and the resolver
  vocabulary from `service_mode.php` (`SERVICE_MODE_SECTION_LABEL_MAP` + the §5.3 exact-type
  arm); assert every label the exporter can emit (with and without a number, incl. the space
  form) resolves to the SAME stored type it was exported from. **BORN RED on today's tree**
  (“Tag”/“Coda”/“Interlude”/“Vamp” fail per §5.2) — first run proves it can fail; C2 turns it
  green. This is the missing rule-#35 mechanism between the two files.
- **G-B resolver matrix (PHP)** — pure tests over §4.2: each ladder step resolves alone;
  ambiguity at every exact step yields candidates-not-resolve; the create tail is ABSENT (assert
  no `INSERT` in the resolver's file-slice — mutation: plant one → red); `resolveOnly` performs
  no `serviceMode_applyBroadcast()` call (assert via the G4-style tree-derivation that the
  write-core call-site count in the `service_drive` case is exactly one and flag-guarded).
- **G-C rides**: `test-openapi-actions-exist` (new fields documented), the #1770 G1
  channel-filter scanner and G4 one-broadcaster-core scanner (both must stay green untouched —
  C2/C3 add no new session queries or writers), `test-migration-registry` /
  `test-schema-coverage` (C1), PHP/JS syntax sweeps.

---

## §9 Owner decisions (house shape; NONE block C2–C5)

- **D1 — Bridge home + language.** *Decision:* where the bridge code lives and what it is
  written in. *Why owner:* a new repo + a shipped-to-churches deliverable is a
  product/maintenance commitment, not a code question. *Options:* (a) new repo, Node CLI —
  matches every existing org tool/test, one runtime to learn, packaging closed in B2; (b) new
  repo, Go — single static binary Day 1, but a second language for one deliverable; (c) in-tree
  under `tools/` — no new repo, but couples church-facing release cadence to the web app's and
  contradicts the documented out-of-repo consumer posture (api-docs.yaml:12815-12820). Doing
  nothing = churches use the B0 Companion recipe only. *Recommendation:* **(a)** — for the
  reuse and cadence reasons in §3.7. *Reply needed:* “a, b or c” (+ repo name if a/b).
  **Blocks only B1.**
- **D2 — Server-side alias table now (dormant) vs bridge-local-only.** *Decision:* ship §4.4's
  `tblServiceSongAliases` dormant in C1, or keep all learned mappings in the bridge's local
  cache file. *Why owner:* a schema commitment (rule #20 one-pass judgement) against YAGNI.
  *Options:* (a) ship dormant — read arm gated, write-UI deferred; survives AV-laptop
  replacement, shared across an org's machines, serves Companion users, and avoids the
  second-migration this family would otherwise need; (b) bridge-local-only — zero schema, but
  the foreseeable confirm-UI/v2 forces exactly the later ALTER-era migration rule #20 forbids.
  *Recommendation:* **(a)**, per rule #20 and the worked #1066/#1088 precedent.
  *Reply needed:* “a or b”. **Blocks only C1** (C2's alias arm is existence-gated either way).
- **D3 — Fuzzy matches never auto-drive.** *Decision:* confirm that a fuzzy (non-exact) title
  match ALWAYS requires a one-time human confirm, with no auto-accept threshold. *Why owner:*
  product risk-tolerance — the failure mode is the wrong song in front of a congregation.
  *Options:* (a) always-confirm (recommended; one prompt per new song, ever, per church);
  (b) auto-accept at ≥ some score (zero prompts, occasional public wrong-song). *Recommendation:*
  **(a)** — implemented as the default in §4.2/§4.5; trivially changeable later (one threshold
  constant in the bridge). *Reply needed:* “confirm a” (or a threshold). **Blocks nothing.**
- **D4 — ProPresenter 6 / legacy-protocol support.** *Decision:* whether the bridge ever targets
  PP6 or the legacy Remote WebSocket. *Why owner:* church-base reality I cannot see.
  *Options:* (a) PP 7.9+ only (recommended — the documented API; PP6 is EOL); (b) add the
  reverse-engineered legacy protocol (a version-brittle maintenance treadmill, §2.2).
  *Recommendation:* **(a)**; a PP6 church can still use B0/Companion if its tooling allows, or
  the shipped operator console. *Reply needed:* “a or b”. **Blocks nothing.**

---

## §10 Landmines (for the builder)

1. **Never let the resolver grow a create tail** (§4.1) — the ingest resolver is the cautionary
   sibling, not the template. G-B pins it.
2. **The keepalive must re-send the FULL last payload** (§3.5) — an empty-body keepalive NULLs
   the section state for every follower. This is a bridge-repo landmine; it also goes in the
   wiki recipe (Companion users need a periodic re-fire too, or their congregants drop at the
   sermon).
3. **Do not "fix" the venue-resolution 404 after a long gap by removing the freshness predicate**
   (api.php:18505-18511) — that predicate is what makes "freshest ACTIVE session at this venue"
   safe. The bridge-side answers are pinning (§3.4) + keepalive (§3.5).
4. **`songRef` resolution must not bypass `songVisibleSql`/`songServableSql`** — the resolver
   inherits the same predicates the `songId` path applies (api.php:18538), or a hidden/deleted
   song becomes drivable by title.
5. **Alias writes stay off the driver key** (§4.4) — durable-mapping poison is a worse class
   than transient wrong-drives; writes wait for the authenticated operator surface.
6. **The 422's `code` values are vocabulary, not prose** — clients branch on HTTP status first
   (rule #35), `code` second; nobody regexes the sentence. api-docs.yaml documents both codes.
7. **G-A parses JS from PHP-adjacent tooling** — apply the rule-#34 lesson list directly: strip
   comments before matching, widen regex windows against the REAL source, and break each
   assertion once before trusting its green.
8. **The bridge README leads with the channel wall** (§3.8) — it will otherwise be the first,
   second and third support ticket, exactly as it was for the two-device test (#1339/#1792).

---

## §11 What done looks like

A church's AV volunteer mints a `propresenter` driver key on `/manage/service-projection`,
pastes it into the bridge config beside the PP host and the venue id, and starts the bridge. At
rehearsal the bridge resolves the whole PP playlist via `resolveOnly` — two songs need a one-tap
confirm, cached forever. On Sunday an org-admin starts the service session; the operator drives
PP exactly as they always have; every slide change lands one `service_drive` POST; congregants'
phones follow song AND section through the sermon-length gap; the projection page never needs to
be open. Nothing about the shipped `service_drive` contract changed for any other caller —
proven by fixture-diff — and G-A stays green only while iHymns' own exporter and its own
resolver agree about what a section is called.
