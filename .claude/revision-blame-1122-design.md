# Field-level diff / blame / rollback over `tblSongRevisions` — LOCKED design (#1122)

**Status: LOCKED** — implementation-ready, commit-phased. 2026-08-23 (Fable-5 design pass).
**Issue:** #1122 ("Field-level diff / blame / rollback over tblSongRevisions" — "who changed this copyright line and when" + cleanly undo a single bad edit; *pure read/compute over tblSongRevisions as-is; no schema*).
All paths below are relative to `appWeb/public_html/` unless prefixed.

## §0 What already exists (confirmed against the tree)

| Piece | Where | Confirmed |
|---|---|---|
| Diff (#1628 item 4) | `revision_get` GET returns `{revision, after, before, beforeSource}`; before resolves through a server-side ladder (`previousData` → `priorRevision` → `none`) | `manage/editor/api2.php:6544-6681` |
| Pure client diff | `diffSnapshots(before, after)` — pure, no DOM, Node-tested | `manage/editor/v2/revisions-tab.js:390-468`; test `tests/test-revision-diff.js` |
| Whole-revision restore (#1743) | `revision_restore` POST → `ed2_applySongSnapshot()` + forced `restore` revision | `api2.php:6683-6752`, `:1767-1970` |
| Metadata-only list | `revision_list` GET, `ORDER BY CreatedAt DESC, Id DESC LIMIT ?` (default 50, max 200) | `api2.php:6516-6542` |
| Snapshot chain (#1743-C2) | revision N's `PreviousData` := revision N-1's `NewData`, copied verbatim, whatever shape | `ed2_touchRevision()`, `api2.php:1983-2054` |
| Snapshot builder | `ed2_buildSongSnapshot()` → `{song: SELECT * row, components, credits, tags, links}` | `api2.php:1632-1738` |
| Schema | `tblSongRevisions` (#313): `Id, SongId, UserId, Action, PreviousData JSON, NewData JSON, Status, CreatedAt`; `idx_Song` | `appWeb/.sql/schema.sql:1999-2025` |
| Global audit page | `/manage/revisions` — metadata table only, links `/manage/editor/?song=…&tab=history` | `manage/revisions.php:83-93, 196-205` |
| Tab deep-link | editor2 honours `tab=history` → `revisions` alias | `manage/editor/editor2.php:100-111` |

**Remaining for #1122:** (a) per-field **blame** across the whole history; (b) per-field **rollback** that reverts ONE field without discarding other edits.

## §1 The hazard: three snapshot shapes coexist (confirmed)

`tblSongRevisions.NewData`/`PreviousData` carry three shapes (`api2.php:6584-6591`, `:1983-1997`):

1. **v2 full snapshot** — `{song:{Uppercase tblSongs columns…}, components, credits:{role:[{id,name}]}, tags, links}`. `.song` is a `SELECT *` row, so it carries derived/noise columns: `NormalizedTitle`, `LyricsText`, `LyricsTextFolded`, `ArrangementJson`, `UpdatedAt` (auto-`ON UPDATE`, differs on every adjacent pair).
2. **Bare `tblSongs` row** (pre-#1743 v1) — Uppercase keys, no `song`/`components` siblings. `save_song_core.php:413-424`.
3. **Editor-payload lowercase-keys shape** — legacy `NewData` = `json_encode($song)` (decoded POST body): lowercase scalars (`title`, `number`, `songbook`, `copyright`, `ccli`, `iswc`, `verified`…), top-level `components`, top-level role arrays `writers`/`composers`. No `song` key, no `credits` object.

The only normaliser reconciling these today is client JS: `isV2Shape()` (`revisions-tab.js:473-477`), `scalarsOf()` (`:479-485`), the `legacy`-flag discipline (`:362-375`). `revision_get` returns shapes untouched ("normalising/diffing is the CLIENT's job", `api2.php:6588-6591`). The lowercase-payload keys ARE the `ED2_META_FIELDS` field keys and the Uppercase keys are that constant's column values (`api2.php:562-614`) — the canonical field-map already exists once, in PHP. This drives Decision 1.

## §2 The four core decisions

### D1 — Normaliser location → **(b) thin bulk read endpoint + the existing client normaliser. LOCKED.**

(a) server walk = rejected (a PHP mirror of ~170 lines of tolerance logic = the PHP↔JS fork rule #22/#35 forbids). (c) per-revision `revision_get` = rejected (N round-trips; re-runs rung-2 fallback N times). **(b) chosen** — ONE normaliser (the shipped JS, minimally extended), zero server resolution logic, bounded response.

**Endpoint (new, GET, read-only):**
```
GET api2.php?action=revision_snapshots&songId=<id>[&limit=N]
-> { ok, revisions:[{id, action, createdAt, userId, username, newData}],  // NEWEST first
     base, baseSource,   // 'previousData' | 'none'  (rule #20 vocabulary string)
     truncated,          // bool: older rows exist beyond this window
     fieldMap,           // {field:'column'} — ED2_META_FIELDS projected {k: v[0]}
     noRollback }        // ['songbook','hasAudio','hasSheetMusic'] — server-derived
```
- Ordering + limit clamp byte-mirror `revision_list`: `ORDER BY r.CreatedAt DESC, r.Id DESC LIMIT ?`, default 50, max 200; fetch `limit+1` to set `truncated`.
- `newData` = decoded JSON (`null` when NULL/undecodable — a row-level null, never a 409: there is a whole list to render around one bad row).
- `base` = the oldest returned row's own `PreviousData`, decoded (zero extra SQL, NO ladder — keeps `revision_get`'s "never a third reader with its own resolution logic" contract; blame's chain IS consecutive `NewData` per `:1983-1997`).
- `fieldMap` derived at runtime: `array_map(fn($v)=>$v[0], ED2_META_FIELDS)` — never a re-typed list (rule #35). **There is no JS copy** — the client folds payload lowercase keys → canonical column keys via the served map.
- No entitlement beyond the file-wide editor gate (`api2.php:467-473`), matching `revision_list`/`revision_get`. No `logActivity` (reads).
- Client: `api-client.js` grows `listRevisionSnapshots(songId, limit)` beside `listRevisions` (`v2/api-client.js:373-375`).

### D2 — Granularity + field set → **ED2_META_FIELDS columns + 4 structured group keys; groups set-level, components per-position; absent≠cleared. LOCKED.**

- **Scalars:** the served `fieldMap`'s column values (Title, Number, Copyright, Ccli, Iswc, Isrc, TuneName, Subtitle, Disambiguation, FirstPublishedYear, CopyrightYears, CopyrightHolder, OriginCity, OriginCityId, Verified, LyricsPublicDomain, MusicPublicDomain, HasAudio, HasSheetMusic, LyricsRightsLicenceKey, MusicRightsLicenceKey, SongbookAbbr, Language). Same keys `diffSnapshots()` already emits. Columns outside the fieldMap (`UpdatedAt`, `NormalizedTitle`, `LyricsText*`, `ArrangementJson`) EXCLUDED — else every pair "changes" `UpdatedAt`.
- **Structured groups:** `components` (per-position), `credits.<role>` (name-set), `tags` (name-set), `links` (url-set), blamed whole-group/per-position (matching the shipped diff). Refinements recorded (not built): id-keyed component matching; admitting payload-shape lowercase groups.

**The walk — new exported pure `blameFromSnapshots(rows, base, fieldMap, noRollback)` in `revisions-tab.js`:**
1. Reverse to oldest→newest; prepend `base` when `baseSource==='previousData'`.
2. Bridge over `newData:null` rows (pair last-decodable → next-decodable; the bad row is opaque, invents nothing).
3. Per pair, extract canonical scalar map via new pure `canonicalScalarsOf(snap, fieldMap)`: v2 → `.song` filtered to fieldMap columns; bare-row → itself filtered; payload → lowercase keys folded through fieldMap. Each entry `{value, present:true}`; a key missing from a shape is absent.
4. **Absent vs cleared:** a change is recorded ONLY when a key is `present` on BOTH sides and values differ (reuse `sameValue()` `:490-499`). present→present with empty/NULL after = a CLEAR (blamed). absent↔present = shape boundary (never a change); first present = `firstRecorded` anchor.
5. Groups compare only when BOTH sides `isV2Shape()` (`:473-477`); a non-v2 side is a group blame boundary.
6. Output per key: `{ key, verdict, last:{revisionId,action,createdAt,userId,username,before,after}|null, firstRecorded:{revisionId,createdAt}|null, canRevert:bool, currentValue }`, `verdict ∈ 'changed'|'firstRecorded'|'unchangedInWindow'` (rule #20). `unchangedInWindow` = `truncated && no in-window change` → "not changed in the last N recorded revisions", NEVER a claimed author.

### D3 — Per-field rollback → **a normal forward save through the EXISTING granular endpoint; scalars only in v1; same PR as blame. LOCKED.**

- **Endpoint:** existing `metadata_field_update {songId, field, value}` (`api2.php:2497+`) via `editorApi.updateMetadata` (`v2/api-client.js:193`). Client inverts the served fieldMap (column→field).
- **Allow-list (rule #5):** already enforced — unrecognised field 400s (`api2.php:2511-2513`); column name only from `ED2_META_FIELDS`.
- **CSRF (rule #29):** inherited — api2 POST gate requires `X-Requested-With` (`:532-534`); `postJson()` sends it.
- **Entitlement:** file-wide editor gate + per-branch gates (rights keys need `edit_songs`, `:2695`). Identical to typing the value.
- **Funnel:** the branch re-validates/canonicalises exactly as a fresh keystroke (ISRC fold, tuneName lockstep, identity 409, rights 422, Title→NormalizedTitle) then `ed2_touchRevision(…,'metadata')` in the same txn — the revert IS its own audited revision (or coalesces in the 15s window).
- **Exclusions (`noRollback`, server-derived, blame-only, no Revert button):** `songbook` (triggers `songRelocate()` re-key; `ed2_applySongSnapshot` never restores `SongbookAbbr`), `hasAudio`/`hasSheetMusic` (derived, ignored/recomputed — rule #44).
- **Structured fields:** deferred fast-follow (blame still displays them). No atomic single call for a credit/tag set; `revision_restore` covers "the lyrics are wrong, go back". The motivating case is a scalar.
- **UX:** confirm dialog (before→after + author/date) → toast + `window.location.reload()` (the `restore()` pattern `:80-85`) — a one-field revert can ripple (tune registry, ISRC mirror, work autolink) so no store-patch shortcut.

### D4 — UI → **a "Field history" toggle inside the existing Revisions tab. LOCKED.**

- Mounted by existing `mountRevisionsTab(container, ctx)` (`editor2.php:543,677`) — admin page, not an SPA fragment, so rule #30 doesn't bite; match the file's ES-module pattern (no inline `<script>`).
- Two-button switch: **History** (shipped list) | **Field history** (blame, lazy-fetch on first activation, mirroring `toggleDiff()` `:103-113`).
- Render: one row per canonical field — label, current value, verdict copy, author+date badge, "Show change" (reuse `fmtScalarValue()` `:178-182`), Revert (scalars not in `noRollback`). Bootstrap semantic classes only — NO `bg-dark`/hardcoded theme (rule #16).
- Mixed-shape history renders calm boundaries ("recorded before detailed history began"), mirroring the diff's `legacy` badge. No new URL param (rule #33).

## §3 STRICT-safety / dormancy
Pure `SELECT` over `tblSongRevisions` + `tblUsers` (core schema); `revision_list` already queries them un-probed — no existence gate. No schema, no migration card. Zero revisions → `revisions:[]`, `base:null`, `baseSource:'none'`, `truncated:false`. Any mix of 3 shapes → correctness is the §2 presence/shape gating (proven by fixtures §6).

## §4 Performance
Bound: LIMIT 1..200 (default 50), `(CreatedAt, Id) DESC`, `idx_Song`. Response ~(1 full snapshot × N); on-demand admin view, fetched only when Field history opens. Recorded (not built): `scalarsOnly=1` strip; base-anchored pagination for deeper history.

## §5 Commit phasing (one PR → alpha)
- **C1 — server:** `revision_snapshots` case + Actions doc-block + one sentence in `revision_get`'s doc naming the no-ladder sibling; `listRevisionSnapshots` in api-client.js. Gate: `php -l`, `test-editor-api2-contract.php`.
- **C2 — pure logic:** `canonicalScalarsOf()` + `blameFromSnapshots()` exported from `revisions-tab.js` + **`tests/test-revision-blame.js`**. Gate: node suite.
- **C3 — UI:** Field history view in `mountRevisionsTab()` (toggle, lazy fetch, verdicts, theme-safe). Gate: node suite + `node --check`.
- **C4 — rollback:** Revert affordance (confirm → `updateMetadata` → toast+reload), `canRevert` honoured. Gate: node suite (asserts `canRevert:false` for `noRollback`).
- **C5 — guards + docs:** contract-test additions (§6), CHANGELOG/WHATS-NEW/wiki, issue close-out.

## §6 CI guards (tree-derived + mutation-proven, rule #34)
1. **`tests/test-revision-blame.js`** (new, behavioural sibling of `test-revision-diff.js`): all 3 shapes; last-writer attribution; absent≠cleared; shape boundaries; null-newData bridging; zero-revision; truncated honesty; `canRevert` exclusions. Mutation-prove: flip presence gate (absent→cleared) → red; drop reverse-ordering → red.
2. **`test-editor-api2-contract.php` additions** (same isolation machinery it uses for `revision_get`): the `revision_snapshots` case references `ED2_META_FIELDS` (fieldMap derived — mutation-proof by replacing with a literal → red); its `ORDER BY` is byte-equal to `revision_list`'s (both parsed from source); client exposes `listRevisionSnapshots` → `'revision_snapshots'`.
3. **PHP↔JS lockstep:** structurally eliminated — no PHP normaliser, no JS field-map copy (served per response). Guard (2)'s derived-fieldMap check prevents a second typed map.

## §7 Adversarial stress
- Server-rendered/native blame surface → migrate the fold server-side wholesale (never both). Global "who changed X across all songs" → needs a materialised change-event table (schema, out of #1122 scope; nothing forecloses it). Unbounded histories → bounded now; pagination composes via base-anchoring. Component insert shifts positions → per-position misattribution, inherited knowingly (id-keyed recorded). Chain corruption → blame walks consecutive `NewData`; a corrupt `PreviousData` degrades one anchor only. Fourth shape → `canonicalScalarsOf` fallthrough = empty map, all absent, boundaries not inventions. Revert-value drift (dead licence 422 / un-migrated 409) → the branch's own status code, rendered by status not prose.

## §8 Owner decisions
**None block the build.** Two defensible defaults, trivially changeable:
1. **v1 rollback scope** — (A) scalars only [recommended; whole-revision Restore covers the rest] vs (B) + components/links via `components_replace`/`link_save_all` vs (C) + credits/tags (non-atomic today). Default A; fast-follow B.
2. **Blame surface** — (A) editor Revisions tab only [recommended; matches "full diffs live in the editor"] vs (B) also `/manage/revisions`. Default A; additive later.

*End of design document.*
