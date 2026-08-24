# Wave 4 — Actionable Remainder (v2-editor parity close-out + queued follow-ups) — Implementation Plan

**Program:** 2026-08-21 autonomous queued-gap program, Wave 4 (sequential Fable-5 planning pass → Sonnet implementation; Opus acceptable for C4 if the per-song verdict plumbing proves fiddly).
**Branch:** `claude/ilyrics-identity-work-model` (the one working branch — standing-directives §2: no PR stacking).
**Planned:** 2026-08-21. All file:line anchors verified against the working tree at commit `ecfa390f` (Waves 1–3 fully landed and pushed — verified via `git log --oneline -25`).
**Execute:** commit-by-commit, in the order given. Every commit must pass `php -l` on touched PHP and `node --check` on touched JS before it is made (CLAUDE.md commit expectations). **CI-faithful suite runs:** move `appWeb/.auth/db_credentials.php` aside before the full `php tools/run-php-tests.php` + node suites (the local MariaDB cred makes the DB-dependent #1747/#1749 tests run-and-fail locally; they SKIP in CI — 2026-08-18 handoff, Key context).

> ⚠️ **Sequencing vs Waves 1–3:** all three prior waves are LANDED on this branch, so there is no
> in-flight overlap left to dodge. But Wave 4 deliberately concentrates on the three files the
> program brief flags as high-traffic — **`manage/editor/api2.php`** (C3, C4, C6, C8),
> **`includes/song_importers.php`** (C1, C6) and **`includes/SongData.php`** (C1) — because that is
> where the verified remainder actually lives. Each commit touches a DISJOINT region of those files
> (line anchors below); implement strictly in order and re-run the touched-file lint before every
> commit. No commit touches `save_song_core.php`, `lyric_lines_*.php`, or any Unicode-handling
> region from Wave 1.

---

## Verification verdicts (the full candidate pool)

| Candidate | Verdict | Evidence (tree + tracker) | Wave-4 action |
|---|---|---|---|
| **#1601** v2-editor cutover epic | **MOSTLY DONE — one child actionable** | The flip LANDED: `manage/editor/index.php:75-79` 302-redirects to `editor2.php` (escape `?legacy=1`; fleet kill-switch `editor_v2_default`), shipped in the `32e14f90` batch (#1853). Children: 7/9 closed; #1616 = owner-gated runbook; #1628 = the last parity child | C3–C5 (via #1628); close-out posts the retire-v1 owner-decision block on #1601 |
| **#1628** v2 parity, five smaller items | **REAL (2 of 5 remain)** | Items 1/2/5 verified done in-tree (issue comment 2026-07-31 + `editor2.php:104`, `v2/sidebar.js:34`, `v2/export.js:122`). Item 3: `grep bulk_move\|bulk_delete\|bulk_export api2.php` → **zero** (only `bulk_verify:5611` / `bulk_tag_attach:5635` / `bulk_tag_detach:5693`); its #1679 blocker is **CLOSED — owner chose option B** and `songRelocate()` now funnels every per-song songbook write (`api2.php:2453`, guarded by `test-song-relocate-funnels.php`). Item 4: `revision_list` is metadata-only (`api2.php:6005-6031`); `revisions-tab.js:54` — "A real side-by-side diff is the better fix and is still wanted" | **C3 (diff), C4+C5 (bulk)** |
| **#1911** ZIP dry-run | **REAL** | `import2.php:295-302` client-side refusal still in place; `_bulkImport_dryRun()` seam exists (`song_importers.php:995`); `tblBulkImportJobs` has **no** DryRun column (`schema.sql:2252-2280`); issue carries its own 5-step spec | **C6** |
| **#1912** interchange alt-titles round-trip | **REAL** | `grep alternativeTitles includes/song_importers.php` → **zero**; the iHymns-JSON mapper's required-key list (`:4861-4866`) and dict (`:3100-3122` is the OpenLyrics arm; JSON arm ~`:4900+`) never populate `altTitles`; `SongData` attaches `alternativeTitles` only in `getSongById` (`:4451-4452`), not the bulk `getSongs` loop (`:2547-2559`); `tests/fixtures/songs.schema.json` lacks the key. The generic saveSong alt-titles write loop already exists (`song_importers.php:812-825`) | **C1** |
| **#1903** search follow-ups | **REAL (split)** | Item 2 (keyboard nav): `search.js` renders plain `list-group-item` anchors (`:544`, `:573-574`) with no arrow-key handling; the combobox-a11y import was removed as dead code (`search.js:50-53`). Item 1 (synonyms): `tblSearchSynonyms` referenced ONLY by `migration-registry.php` — fully dormant, real, but needs a seed-source decision + a new curator CRUD surface | **C2 (item 2)**; item 1 → §0.6 (defer, with mini-spec) |
| **#1900** multi-holder copyright M:N | **REAL** | Zero `tblSongCopyrightHolders` anywhere in the tree; single-holder live (`api2.php` `song_copyright_holder_set`, `metadata-tab.js:567/:1093-1155` picker, `copyright_display.php:90` single-holder statement); the issue's own design (mirror `tblSongbookPublishers`, rule #37) is complete | **C7 + C8** |
| **#1896** importer CCLI/ISWC drop | **ALREADY DONE** | CLOSED completed 2026-08-18; fixed in `8807152d` (`_bulkImportRightsFromSong()`); guard `test-importer-rights-fields.php` | None |
| **#1698** account lifecycle epic | **ALREADY DONE (code) — rehearsal-gated** | All 6 scope items landed `16d6a54a`→`0a96ece2` (2026-07-31, issue comment): `includes/account_lifecycle.php` + `includes/user_status.php` + `tblUsers.Status` + `/manage/users` UI + 100-assertion test, all present in tree (`auth.php:1650-1715` tombstone erase). Open ONLY pending the alpha runtime rehearsal (no MySQL in build container) | None — already in `alpha-verification-runbook.md` territory |
| **#1777** presence token → checkBulkAccess | **OWNER-GATED** | The issue's own text: "Owner decision needed… I lean toward shipping it with P6" — the wiring is a state-(b) enforcement change belonging to the content-gating first-enable pass, which is a HARD EXCLUSION of this program | §0.2 |
| **#1785** musician registry-dedup + merge UX | **ALREADY DONE — merge/deploy-gated** | `.claude/musicians-dedup-1785-plan.md` AS-BUILT header (C1–C10 shipped); tree: `manage/musician-duplicates.php` exists, `musicians.php:57-61` wires `includes/musician_duplicates.php`; landed via the consolidated batch `0b7d8ecf`. Bonus: its flagged `credit_search` third-fork follow-up was ALREADY fixed by #1800 C1 (`api2.php:5740-5752` delegates to `MUSICIAN_CREDIT_ROLE_TABLES`) — **no issue needs filing** | None (closes with the eventual PR/deploy) |
| **#1786** app-wide multi-level sort | **ALREADY DONE — reopened for tracker accuracy only** | Its own body says so; tree: `js/modules/list-sort.js` + `constants.js` `list_sorts` present (merged via `0b7d8ecf`) | None (closes with the PR) |
| **#1797** ProPresenter driver shim spike | **OWNER-GATED** | The issue IS the decision ("spike now vs later"; recommendation: later; PP surface + LAN-bridge facts only the owner/churches have) | §0.3 |
| **#94** (a) setlists (b) IA-OCR | **ALREADY DONE / VERIFY-GATED** | Issue #94 closed 2026-04-06 (setlists). The "IA-OCR #94" thread (plan `ia-ocr-94-plan.md`): Phase 1 LANDED (`manage/ia-reconcile.php` + `includes/ia_client.php` in tree, commits `e33f09fd`→`2418eac7`); Phase 2 is "§7 — DESIGN ONLY, DO NOT BUILD" and gated on the never-run live archive.org smoke (no network in the sandbox) | §0.4 |
| **#1909** Phase-D partner webhooks | **TOO-LARGE for a gap wave** | "grep tblWebhook → nothing" verified; the issue itself mandates a rule-#20 design pass (event vocabulary, delivery/retry, signing, subscription CRUD) before building | §0.5 — recommend its own Fable design pass as a future wave |
| **#1910** line-grain provenance | **BACKLOG BY DESIGN** | Its own text: "Keep as backlog until a concrete case arises" | None |
| **#1874** hardening epic | **SEQUENCING-GATED** | "Runs LAST, after the architectural queue" (its own title/body); the conservative code children were already exercised 2026-08-18 (`777c1ba4`+`edec2095` — lint/a11y/security, issue comment). The full sweep is the program's END-of-queue close-out, not a gap commit | None in Wave 4 |
| **"catalogue D5"** | **ALREADY DONE — lead was stale** | D5 = the #1741 external-ID store batch: `tblSongExternalIds` + backfill (#1747) landed; P5 editor fields exist (`api2.php:141-208` — `song_external_id_*`, `tune_search`); no deferred D5 remainder found in any plan or open issue | None |
| **"org-logos §10" deferred surface** | **DESIGN-GATED** — real issue is **#1840** | "surface organisation logos on the app header, projector and OG-image" — its own body: "deliberately deferred pending a fresh design pass on placement, sizing and theme variant" (= rule #42's explicit ban on wiring without one; the dormant `Variant` axis decision lives there) | §0.7 |

**Score: 6 real-actionable work items (8 code commits), 6 already-done, 4 owner/design-gated, 1 too-large, 1 backlog-by-design, 1 sequencing-gated, 1 stale lead.**

---

## §0 No-code items (evidence + disposition)

### §0.1 #1601 — what remains after this wave, and the owner decision to post

With C3–C5 landed, **every item of #1628 is closed** and #1601's only open children are #1616 (the
owner-gated C6-drop runbook) and the retire-v1 act itself. The flip is live (302 at
`editor/index.php:75-79`, since `32e14f90`), `?legacy=1` and `editor_v2_default='0'` are the escape
hatches, and #1629 already repointed the four non-editor api.php consumers. **Close-out action:**
post on #1601, in the standing 5-part shape: *Decision* — when to delete the v1 editor + its
`api.php` shim (they still serve `?legacy=1`); *why owner* — it needs a soak judgement ("has anyone
needed the escape hatch since the flip?") that only production telemetry answers; *options* — (a)
retire next release, (b) retire after one full curatorial cycle on v2 with zero `?legacy=1` hits
(recommended — check the activity log), (c) keep indefinitely (cost: two editors to maintain, rule
#22 debt); *need back* — a/b/c. **Non-blocking.**

### §0.2 #1777 — presence token into `checkBulkAccess()` — stays owner-gated

The additive parameter is byte-identical-safe, but the issue's own recommendation is to ship the
whole thing with the P6 first-enable pass "so every state-(b) enforcement change lands and is
verified together" — and content-gating first-enable is a hard exclusion of this program. Nothing
in the tree has changed the calculus (verified: `content_access.php` `checkBulkAccess()` still
hard-codes the null). **No code; no comment needed** (the issue already states its own disposition).

### §0.3 #1797 — PP shim spike: awaiting "now vs later" + venue facts. No code.

### §0.4 IA-OCR Phase 2 — blocked behind the Phase-1 live smoke

Phase 1 has **never once fetched from real archive.org** (built against fixtures; the sandbox had no
network). Building Phase 2 (curator-approved GAP → import via the one lyric write path) before the
segmenter/scorer has met one real hymnal scan would be building on an unexercised foundation. The
smoke is on the alpha runbook. **No code.**

### §0.5 #1909 webhooks — recommend: its own sequential Fable-5 design pass

A partner-event platform (vocabulary, retry/backoff, HMAC signing, subscription CRUD, dead-letter
surface) is a rule-#20 one-pass schema family with real security surface — exactly the shape the
brief excludes from a gap wave. Recommend it as the headline of a future wave with its own plan doc.

### §0.6 #1903 item 1 — `tblSearchSynonyms` activation: verified real, deferred with a mini-spec

Real (the table is dormant: registry-only references) but it is three things, not one: (a) a curator
CRUD surface (`/manage/search-synonyms`, mirroring `external-link-types.php`), (b) a query-expansion
arm on the fold-aware search (#1039) — expansion terms OR-folded into the existing match SQL, capped
(≤4 expansions) so a synonym chain can't explode the query, and (c) **seed data, which is the
blocker**: rule #23's precedent is that vocabulary is seeded from a NAMED source, never invented
ad-hoc. A defensible seed exists (British↔American spelling pairs + the hymnody archaisms the issue
names) but choosing it is a curatorial call. **Recommend:** a one-line owner ask ("seed
Saviour/Savior-class spelling pairs + thee/thou archaisms, or start empty and let curators fill
it?") and then a small dedicated pass. Not in Wave 4 — the wave is already api2-heavy.

### §0.7 #1840 org-logo surfaces — design-gated by rule #42's own text. No code.

The `Variant` (theme-paired) axis decision is the point of the required design pass. Any Wave that
picks this up must start with placement/sizing/theme mock-ups for the owner, not code.

---

## Commits (smallest / safest first; every guard tree-derived + mutation-proven, rule #34)

### C1 — #1912: interchange `alternativeTitles` round-trip (export emit + importer map + schema)

**Goal:** a song exported through the interchange/songbook bundle and re-imported keeps its
alternative titles. Binding rules: #22 (the ONE `song_alt_titles.php` core — already consumed by
the generic saveSong loop at `song_importers.php:812-825`; C1 only feeds it), #33 (a key one side
emits, the other honours), #34.

**Changes (all verified anchors):**
1. `tests/fixtures/songs.schema.json` — add **optional** `alternativeTitles`:
   `[{title (required), language?, note?}]` to the song definition. NEVER add it to the importer's
   `$required` list (`song_importers.php:4861-4866`) — old exports must keep importing (rule #33's
   "links outlive code" applied to documents).
2. `includes/song_importers.php` — in the iHymns-JSON per-song mapper (the dict built after the
   `$required` validation), populate `'altTitles' => …` by normalising
   `(array)($raw['alternativeTitles'] ?? [])` to the `[{title, language}]` shape the saveSong loop
   consumes (`:816-822`); malformed entries skipped, matching the loop's own tolerance. Optionally
   thread `note` through `songAltTitleAdd()`'s 5th param — the write core already accepts it.
3. `includes/SongData.php` — in the bulk `getSongs()` attach loop (`:2547-2559`), add
   `$altMap = $this->_songAltTitlesMap($songIds);` alongside `_getTagsMap`/`_externalLinksMap` and
   emit `$song['alternativeTitles'] = $altMap[$sid] ?? [];` — same always-present-array convention
   as `getSongById` (`:4452`). `_songAltTitlesMap()` (`:1383`) is already bulk-capable and
   schema-probe-gated (pre-migration installs get `[]`, STRICT-safe).
4. `tools/parse-songs.js` — check whether the data-prep builder can carry the key from source data;
   if source data has no such field, add nothing (rule #44 — don't invent a field the pipeline
   never fills). Record the outcome in the commit body either way.

**Guard:** extend `tests/php/test-ihymns-json-import.php` — a fixture song carrying
`alternativeTitles` parses to a dict whose `altTitles` matches (title + language), and one WITHOUT
the key parses with `altTitles === []`; extend `tests/test-song-fixture-shape.js` for the schema
addition. **Mutation-prove:** delete the mapper line → red; restore.
**Verification:** `php -l` ×2, `node --check` where touched; CI-faithful full suite (the
`getSongs()` shape change is additive but `songbook_export` consumers exist — the suite run IS the
regression net; any golden-fixture test that pins the getSongs key set will name itself here).
**High-traffic flag:** touches `SongData.php` + `song_importers.php` — smallest possible diffs.
**Effort:** S. **Issue action:** close #1912 with SHA + the round-trip test transcript.

---

### C2 — #1903 item 2: `/search` results keyboard navigation

**Goal:** ArrowDown from `#page-search-input` moves focus into the results list; Arrow keys move
between result anchors; Enter opens (native — the rows are real `<a href>` at `search.js:573-574`).
Deliberately NOT a combobox/typeahead revival (the issue's own constraint; the old autocomplete was
removed as dead code in #812).

**Changes:** `js/modules/search.js` only. Keydown on the input: ArrowDown (when
`#search-results-list` has at least one `.song-list-item`) → `preventDefault()` + focus the first
anchor. Keydown (delegated) on the results container: ArrowDown/ArrowUp move focus between
`.song-list-item` anchors (bounded — no wrap; ArrowUp from the first returns focus to the input);
Home/End optional. No `tabindex` surgery needed — anchors are natively focusable. Re-binding must
survive `performSearch()` re-renders: bind ONE delegated listener on the container
(`#text-search-results`, `:174`), never per-row listeners (the rows are re-created per search).

**Guard:** `tests/test-search-keyboard-nav.js` — comment-stripped source assertions, narrow (rule
#34's "never fail on correct code"): the module binds a `keydown` listener on the results container
(delegated, not per-row), handles `ArrowDown` + `ArrowUp`, and calls `preventDefault` in the arrow
branches (an unprevented ArrowDown scrolls the page AND moves focus — the classic half-broken
outcome). **Mutation-prove:** remove the `preventDefault` → red; remove the input's ArrowDown
branch → red; restore.
**Verification:** `node --check`; manual smoke on alpha (type, ArrowDown, arrows, Enter; then
re-search and repeat — the re-render case). **Effort:** S.
**Issue action:** comment on #1903 (item 2 done, SHA); leave open for item 1 per §0.6.

---

### C3 — #1628 item 4: revision diff view (api2 `revision_get` + revisions-tab diff)

**Goal:** a curator can see WHAT a revision changed before restoring it — closing the hazard the
tab currently only documents (`revisions-tab.js:37-56`). Binding rules: #35 (the server resolves
the "before" snapshot ONCE; the client never re-implements the chain fallback, and branches on
HTTP status, never prose), #34.

**Server — `manage/editor/api2.php`,** new `case 'revision_get'` (GET, beside `revision_list`
`:6005`): input `revisionId` (+ optional `songId` defence-in-depth, copying `revision_restore`'s
guard `:6046-6050`). Returns `{ ok, revision: {id, action, createdAt, userId, username}, after,
before, beforeSource }` where `after` = decoded `NewData`; `before` = decoded `PreviousData` when
non-NULL (Wave 2 C2 `f18c54ac` chains it for all NEW revisions), else the **prior revision's
`NewData`** (one extra SELECT ordered `(CreatedAt, Id)` descending — the same ordering
`revision_list` uses), else `null`; `beforeSource` = `'previousData' | 'priorRevision' | 'none'`
(a vocabulary string, not a boolean pair — rule #20's discipline in miniature). A revision whose
`NewData` is NULL/undecodable → **409** with the same "no snapshot" semantics `revision_restore`
uses (`:6053`). Legacy bare-`tblSongs`-row snapshots (the #1743-C3 shape) are returned AS-IS —
shape normalisation is the client's rendering concern, not the API's.

**Client —** `v2/api-client.js`: `getRevision: (revisionId, songId) => getJson('revision_get', …)`
beside `listRevisions` (`:335`). `v2/revisions-tab.js`: a "Changes" button per row → fetches and
renders a **field-level diff**: (a) scalar keys present in either snapshot with `before ≠ after`
as a two-column changed-fields table; (b) components compared by position — added / removed /
text-changed (line-level LCS is NOT required; a per-section "n lines changed" marker with the
first differing line quoted is enough for v1 of this view — record in the doc-block that
line-grain diff is a possible refinement); (c) credits/tags/links as added/removed name sets.
Extract the comparison as a **pure exported function** (`diffSnapshots(before, after)`) so it is
node-testable without a DOM. When `beforeSource === 'none'` (the song's very first revision, or a
pre-#1743 v1 row with nothing before it) render "No earlier state recorded" — never an error.
A legacy bare-row snapshot renders under the same scalar path (its keys simply are the row's) with
a small "legacy snapshot" badge. Update the `:37-56` doc-block + the `revision_list` doc comment
(`:6003` "the full NewData snapshot is fetched on restore") to name the new endpoint.

**Guard:** `tests/test-revision-diff.js` — behavioural, CALLS `diffSnapshots()`: scalar change
detected; component add/remove/change by position; identical snapshots → empty diff; a legacy
bare-row vs v2-shape pair doesn't throw. PHP side: extend `tests/php/test-editor-api2-contract.php`
(the existing contract scanner) so `revision_get` is asserted present with the
`previousData → priorRevision → none` resolution ladder in its handler source (window ≥300 chars —
that test's own recorded lesson). **Mutation-prove:** invert the before-resolution order → red;
make `diffSnapshots` return `[]` unconditionally → red.
**Verification:** lints; CI-faithful suite; alpha smoke (view changes on a fresh edit, on a
restore row, and on the oldest revision of an old song).
**High-traffic flag:** `api2.php` (new isolated case block only). **Effort:** M.
**Issue action:** comment on #1628 (item 4 done, SHA + screenshots when on alpha).

---

### C4 — #1628 item 3, server half: `bulk_move` + `bulk_delete` in api2 (the shared cores, per-song verdicts)

**Goal:** the two missing bulk mutations exist server-side, each a thin loop over the ONE existing
core — never a re-implementation (rule #22). Binding facts: #1679 is RESOLVED (option B);
`songRelocate()` re-keys + cascades + writes the redirect row and is already the funnel for BOTH
single-song move paths (`save_song_core.php:485`, `api2.php:2453`); `songSoftDelete()`
(`includes/song_soft_delete.php:473`) is the per-song delete verdict core `delete_song` uses
(`:2352`).

**`case 'bulk_move'` (POST `{songIds:[], targetAbbr}`):** gate `ed2_requireEntitlement('bulk_edit_songs')`
(the bulk family's gate — `:5612/:5636/:5694`). Validate ids (≤300 — the sidebar's RENDER_CAP is
the natural ceiling, and "All" can only select rendered rows, `sidebar.js:316`), then per song:
own transaction, `songRelocate($db, $songId, $targetAbbr, $ed2UserId)` +
`ed2_touchRevision($db, $rel['songId'], …, 'metadata')`, commit — mirroring the single-song block
`:2445-2470` INCLUDING its two-tier catch (`\InvalidArgumentException` → per-song 422-class
verdict; `\Throwable` → rollback + recorded as failed, loop continues). **Per-song verdicts, never
all-or-nothing** — the repo's own posture (#1690 A7: "within a bulk move some songs may now proceed
while others refuse"). Response: `{ ok, moved: [{oldId, newId}], failed: [{id, error, status}] }` —
**the new ids are the payload's point**: option B re-keys SongIds, so the client's selection dies
by design. `logActivity('song.bulk_move', …)` once with counts.

**`case 'bulk_delete'` (POST `{songIds:[], reason?, note?}`):** gate
`ed2_requireEntitlement('delete_songs')` (bulk delete is repetition of the single destructive act —
the per-act entitlement governs, matching `delete_song:2334`; the commit body records this choice
explicitly). Per song: `songSoftDelete(...)`, collecting each verdict's ok/error/status; response
`{ ok, deleted: [ids], failed: [{id, error, status}] }`. Soft delete writes no redirects (#1694)
and is restorable from `/manage/deleted-songs` — which is what makes a bulk version acceptable at
all.

**Guards (the existing mechanisms must auto-cover — verify, don't assume):**
- `tests/php/test-song-relocate-funnels.php` CHECK 1 derives "every per-song songbook write calls
  `songRelocate()` in the same function" from the tree — **run it against a deliberately-wrong
  draft** (bulk_move writing `SongbookAbbr` directly) and confirm it goes red BEFORE trusting it;
  if it does not see the new site, extend it until it does (rule #34 — a guard that under-reports
  is worse than none).
- Extend the soft-delete guard family (or `test-editor-api2-contract.php`) with: `bulk_delete`
  delegates to `songSoftDelete(` and contains no `DELETE FROM tblSongs`; `bulk_move` contains no
  direct `UPDATE tblSongs SET SongbookAbbr`.
- api-client + api-docs.yaml entries land in C5 (one commit boundary per concern).

**Verification:** `php -l`; CI-faithful suite; the funnels-guard mutation transcript in the commit
body. **High-traffic flag:** `api2.php`. **Effort:** M.

---

### C5 — #1628 item 3, UI half: bulk bar Move / Delete / Export + client plumbing

**Goal:** the v2 bulk bar (`editor2.php:320-325`) grows Move…, Delete… and Export…; every new
motion is bounded and reuses shared modules. Binding rules: #22, #17 (NEVER re-materialise the
corpus — v1's `_loadSongsFull()` shape is the named anti-pattern in the issue itself), #35.

**Changes:**
1. `v2/api-client.js` — `bulkMove: (songIds, targetAbbr)` and
   `bulkDelete: (songIds, reason)` beside `bulkTagDetach` (`:164`).
2. `editor2.php` — three buttons on `#v2-bulk-bar` + handlers beside the existing bulk wiring
   (`:690-700`): **Move** opens a small modal listing songbooks from the sidebar's
   `songbookList()` source, warns "Numbers will be cleared — renumber afterwards" (the #1679
   sub-decision: v1-matching, collision-free) AND "Song ids change; old links redirect", then calls
   `bulkMove`; on response, toast `moved/failed` counts, `sidebar.clearSelection()`, and refresh
   the slim index (the moved ids are re-keyed — a stale sidebar is the A2 failure below). **Delete**
   confirms with the count + "restorable from Deleted songs", calls `bulkDelete`, same
   refresh. Failures render the per-song `failed[]` list (id + error) in the modal, branching on
   the entry's `status` for wording (rule #35). **Export** opens a format picker (the format keys
   from `window.iHymnsFormatExport` — never a typed list) and then fetches each selected id via
   `editorApi.loadSong(id)` **sequentially in chunks of 5**, feeding
   `iHymnsFormatExport[fmt].exportSongbook(songs, options)` (`format-export.js:850-877` registry;
   its `_internal.buildZip` already handles multi-file formats). Bounded by construction:
   selection ≤ RENDER_CAP (300) per-id single-record reads — the exact "bounded per-id reads"
   #1628 prescribed.
3. `api-docs.yaml` — document `bulk_move` + `bulk_delete` (+ their per-song verdict envelope). The
   existing api-docs guards (version-lockstep etc.) police the sync.

**Guard:** extend the C4 contract assertions with the client side: `api-client.js` exposes
`bulkMove`/`bulkDelete`; `editor2.php`'s export handler references `exportSongbook` and contains
no `load_songs`-style bulk fetch. **Mutation-prove** at least one client assertion (drop the
selection-refresh call → the guard that asserts it goes red — and yes, assert the refresh: a
stale-selection bulk bar after a re-key is a silent data hazard, worth a narrow source assertion).
**Verification:** `node --check` + `php -l`; alpha smoke: move 2 throwaway songs between test
books (verify redirects resolve + numbers cleared), bulk delete + restore from
`/manage/deleted-songs`, export 3 songs in two formats.
**Effort:** M. **Issue action:** close **#1628** (all five items done, SHAs per item); comment on
**#1601** with the §0.1 decision block.

---

### C6 — #1911: ZIP dry-run (migration + async flag + worker gate + UI)

**Goal:** the `import_zip` dry-run 422 becomes a working preview, by persisting the flag across the
async job boundary. The issue's own 5 steps are the spec; anchors verified: refusal UI
`import2.php:295-302`; checkbox `:121-122`; seam `_bulkImport_dryRun()` `song_importers.php:995`;
`tblBulkImportJobs` DDL `schema.sql:2252-2280` (no DryRun column).

**Changes:**
1. **Migration `appWeb/.sql/migrate-bulk-import-dryrun.php`** — `ALTER TABLE tblBulkImportJobs ADD
   COLUMN DryRun TINYINT(1) NOT NULL DEFAULT 0 COMMENT …` (TINYINT, additive; the table's existing
   Status ENUM is grandfathered — add no new ENUM). Rule #19: byte-identical `schema.sql` mirror +
   ONE `migration-registry.php` entry with a real `columnExists` probe. Rule #41: **no column-0
   `/public_html/` literal require** — use the `IHYMNS_INCLUDES_DIR` resolution shape or a guarded
   require (`test-deploy-paths.php` polices this).
2. **`api2.php` `import_zip`** — replace the 422 with writing the flag onto the job row
   (column-existence-gated: on an un-migrated install the 422 REMAINS — degrading to the current
   honest refusal, never a silent ignore; rule #33).
3. **`song_importers.php` worker** — `_bulkImport_processZip()` (and the **synchronous fallback
   path** — EasyWorship zips process inline; the flag must gate BOTH) reads `DryRun` and calls
   `_bulkImport_dryRun(true)` before the per-file loop; under dry-run, skip
   `ed2_runSongbookMaintenance` and any finalisation write beyond the job row's own counters/status
   (mirroring the `import_file` gate). Counters keep their meaning as "would create / would skip";
   `TempPath` cleanup still runs (a dry-run must not leak temp files).
4. **`import2.php`** — remove the client-side ZIP refusal (`:295-302`); the poll UI renders the
   dry-run banner exactly as the single-file path does (`:159-171` branches on the server-echoed
   `dry_run` key — extend the `import_zip_status` payload to echo it; the client branches on that
   echo, never on what it sent — rule #35).
5. **`api-docs.yaml`** — `import_zip` dryRun param + `dry_run` in job status; drop the 422 note.

**Guard:** `tests/php/test-zip-dryrun.php` — comment-stripped source assertions: the worker gates
on the DryRun column read; the maintenance call sits under the not-dry-run branch; `import_zip`
contains the column-existence gate (422 retained pre-migration); `import2.php` no longer contains
the ZIP refusal string-branch. Registry/schema sync auto-policed by `test-schema-coverage.php` +
`test-migration-registry.php`. **Mutation-prove:** move the maintenance call out of the gate → red.
**Verification:** lints; CI-faithful suite; alpha: run a small ZIP with dry-run on (DB row counts
unchanged, summary renders), then off (imports for real).
**High-traffic flag:** `api2.php` + `song_importers.php`. **Effort:** M.
**Issue action:** close #1911 with SHA + the before/after 422 evidence.

---

### C7 — #1900 schema + core: `tblSongCopyrightHolders` + the multi-holder statement

**Goal:** the additive M:N lands one-pass (rule #19/#20), the derived statement joins multiple
holders, and NOTHING user-facing changes until C8 wires the picker (dormant-first).

**Changes:**
1. **Migration `appWeb/.sql/migrate-song-copyright-holders.php`** — `tblSongCopyrightHolders`:
   `Id` PK; `SongId VARCHAR(20) NOT NULL` **FK → tblSongs(SongId) ON DELETE CASCADE ON UPDATE
   CASCADE** (the relocate discipline — a non-CASCADE FK would make every song with holders refuse
   to move, #1690); `PublisherId` FK → `tblPublishers(Id)` (RESTRICT delete — a holder in use must
   not vanish; matches the registry's usage-check posture); `Role VARCHAR(30) NOT NULL DEFAULT
   'holder'` app-validated against a map in `includes/publisher_helpers.php` (VARCHAR-not-ENUM,
   rule #20 — `IHYMNS_PUBLISHER_ROLES` is the model; add a small
   `IHYMNS_COPYRIGHT_HOLDER_ROLES` beside it, or reuse if the vocab genuinely coincides — decide by
   reading the map, record the choice); `SortOrder INT NOT NULL DEFAULT 0`;
   `UNIQUE (SongId, PublisherId, Role)` (mirrors `uq_book_pub_role`, rule #37). Byte-identical
   schema.sql mirror + ONE registry entry + real probe. Rule #41 include-path discipline.
2. **`includes/song_relocate.php`** — add the new FK to `SONG_RELOCATE_EXPECTED_SONGID_FKS`
   (`:426`), tagged with this migration. **This is not optional:** the const is CI-pinned to
   `schema.sql` in both directions — omitting it fails the build by design (#1690's mechanism).
3. **Shared core** — extend `includes/publisher_admin.php` (or a small sibling
   `includes/song_copyright_holders.php` that DELEGATES to `publisher_admin` for
   resolve/find-or-create — never a fork, rule #22/#37): `songCopyrightHoldersList($db,$songId)`,
   `songCopyrightHoldersReplace($db,$songId,array $rows,$userId)` (ordered write + re-sync of the
   `tblSongs.CopyrightHolderId` **denorm to the first-listed holder**, exactly the
   songbook-publisher denorm shape rule #37 mandates — ONE writer for both the M:N and the mirror),
   all column/table-existence-gated (STRICT-safe on un-migrated installs).
4. **`includes/copyright_display.php`** — `ihymns_copyright_statement()` (`:90`) grows an optional
   holders-array input (joined "A / B"); the single-holder call sites keep working unchanged
   (additive signature, default preserves current output byte-identically — assert that).

**Guard:** `tests/php/test-song-copyright-holders.php` — behavioural over the pure parts:
statement joiner (0/1/n holders; n=1 output byte-identical to today's), replace-core ordering +
denorm-resync decision (first-listed wins), vocabulary validation rejects an unknown Role.
Schema/registry/relocate-const sync auto-policed by the three existing suites. **Mutation-prove:**
resync to the LAST-listed holder → red; drop the FK const entry → the relocate pin goes red.
**Verification:** lints; CI-faithful suite. **Effort:** M. (No issue action yet — C8 closes it.)

---

### C8 — #1900 UI/API: multi-pick chips in Editor2 + api2 endpoints

**Goal:** the Copyright-Holder control grows from single-pick to an ordered multi-pick chip list;
the API exposes list/replace; the denorm stays server-resolved.

**Changes:**
1. **`api2.php`** — `song_copyright_holders` (GET) + `song_copyright_holders_set` (POST
   `{songId, holders:[{publisherId?|name, role?}]}`) delegating to the C7 core;
   `publisherResolvePickedOrCreate()` handles the name→id mint (rule #43 — find-or-create on
   commit, never on keystroke). Column-gated **409 on un-migrated** installs (the same honest-409
   posture `song_copyright_holder_set` already uses — clients branch on status, rule #35). The
   existing single `song_copyright_holder_set` endpoint REMAINS (back-compat; its write now routes
   through the same core so the two cannot diverge — one write path).
2. **`v2/metadata-tab.js`** — the bespoke picker block (`:567`, `:1093-1155`) becomes a chip list:
   existing holders render as ordered removable chips; the picker adds; drag/arrows reorder
   (reorder buttons are enough — no new drag dependency); every commit POSTs the FULL ordered list
   to `…_set` and re-renders from the RESPONSE (the server's stored truth, incl. the resolved
   denorm — rule #35's read-back). On 409 (un-migrated) fall back to today's single-pick behaviour
   — feature-detect by status, never by error prose.
3. **`api-docs.yaml`** — the two endpoints.

**Guard:** extend `test-editor-api2-contract.php` (endpoints present, delegate to the C7 core, no
second resolve path) + a narrow metadata-tab source assertion (chips re-render from response, not
from the request payload). **Mutation-prove** one of each side.
**Verification:** lints; CI-faithful suite; alpha: add two holders to a test song, reorder, verify
the derived statement + the `tblSongs.CopyrightHolderId` denorm follows the first chip, remove all
→ denorm cleared, statement falls back per C7 rules.
**High-traffic flag:** `api2.php`, `metadata-tab.js`. **Effort:** M.
**Issue action:** close #1900 with SHAs + the denorm-resync evidence.

---

### C9 — close-out: docs, tracker, decisions (standing-tasks pass; no code)

- **CHANGELOG.md** — one entry per shipped item (#1912, #1903-item-2, #1628 items 3+4, #1911,
  #1900).
- **Issues** — close #1912, #1911, #1900, #1628; comment #1903 (item 2 SHA; item 1 = §0.6 ask);
  post §0.1's retire-v1 decision block on #1601; nothing on #1777/#1797/#1840/#1910 (their state
  is already accurately recorded on each — re-commenting "still true" is noise, the Wave-3
  §0.4 precedent).
- **api-docs.yaml** already updated in C5/C6/C8 — verify the lockstep guards ran.
- **Wiki + help** — editor help (bulk actions + revision diff), import help (ZIP dry-run):
  `manage/help.php` topics + the wiki editor page (the admin-help-coverage guard from the docs
  pass will flag gaps mechanically — trust it, then eyeball).
- **`.claude/`** — handoff entry (incl. the already-done table above so the next session doesn't
  re-verify #1785/#1786/#1698/#94/D5); `ProjectBrief.md` v2-parity status; `MEMORY.md` if the
  per-song-verdict bulk convention is worth codifying.
- **Effort:** S.

---

## §A Adversarial review (what would make each fix wrong, and the defence)

**A.1 — C3's diff lies when snapshots have different shapes.** Three shapes exist: v2 full
snapshots, pre-#1743 rows with `PreviousData = NULL`, and legacy v1 bare-`tblSongs`-row `NewData`
(the #1743-C3 shape). Defence: the SERVER resolves the before-snapshot ladder and names its source
(`beforeSource` vocabulary); the client diffs whatever keys exist and badges legacy rows; the
`'none'` case is a designed rendering, not an error path. The pure `diffSnapshots()` is
behaviourally tested against a legacy/v2 mixed pair. What it deliberately does NOT do: normalise a
bare row into a fake v2 snapshot (inventing keys is how a diff view lies convincingly).

**A.2 — C4/C5's bulk move strands the client on dead ids.** Option B RE-KEYS SongIds; after a
successful bulk move every selected id is stale and only redirects keep it resolving. Defence:
the response carries `{oldId, newId}` pairs; the handler clears the selection AND refreshes the
slim index unconditionally; a guard asserts the refresh call exists. The residual — another
admin's open editor tab on a moved song — is the same exposure the single-song move already has
(and `songRedirectFollow()` covers the reload path); not new risk, recorded in the commit body.

**A.3 — C4's per-song verdicts mask systemic failure.** If ALL 300 fail (e.g. the target book
doesn't exist), a per-song verdict list is the wrong UX. Defence: validate `targetAbbr` resolves
ONCE up front (400/422 before the loop — `songRelocate` throws `InvalidArgumentException` for a
bad book; probe first, fail whole), so per-song verdicts only carry genuinely per-song outcomes
(RESTRICT-FK refusals, soft-deleted rows). This mirrors how the single-song 422 already works.

**A.4 — C5's bulk export re-invents the corpus loader under a new name.** The named anti-pattern
is one request materialising everything. Defence: per-id `load_song` (single-record reads, rule
#17's own sanctioned shape), chunked ×5, ceiling = RENDER_CAP by construction; a source assertion
bans a bulk-fetch call in the export handler. If a curator wants a WHOLE book, `songbook_export`
already exists and the format picker links to it — don't rebuild it.

**A.5 — C6 half-gates the dry-run and writes anyway.** The dangerous paths: the synchronous
EasyWorship-zip fallback (bypasses the worker), songbook upserts inside the per-file loop, and
`ed2_runSongbookMaintenance` at finalisation. Defence: the flag is read at the JOB level and
`_bulkImport_dryRun(true)` is set BEFORE any per-file work in BOTH paths; songbook creation is
already covered by the seam (`:1050-1077` gates it); the guard pins the maintenance call inside
the not-dry-run branch and is mutation-proven by moving it out. The un-migrated install keeps the
422 (column-gated), so a half-deployed fleet degrades to the honest refusal, never a silent wet
run. Also: the worker sets counters that now mean "would create" — the STATUS payload echoes
`dry_run` so the UI labels them; the client branches on the echo, never on what it sent.

**A.6 — C1's new emit key breaks a strict consumer of `getSongs()`.** The bulk shape gains
`alternativeTitles`. Known consumers: the editor songbook_export bundle (v1 editor + v2 both
tolerate extra keys — they already survived `links`/`tags` additions), the public
`songbook_export` API (gating strips by FIELD list, alt titles aren't gated — verified
`contentGatingApply` targets lyric/media fields), and `tools/export-pro-sample.js` (tolerant).
Defence: additive-only, always-an-array (matching `getSongById`), full CI-faithful suite run — any
golden-shape test that pins the key set will name itself; if one does, extend the fixture rather
than emitting sparsely (sparse-vs-always inconsistency between getSongById and getSongs would be
the worse bug).

**A.7 — C7/C8's denorm and M:N drift apart.** Two writers (the old single-set endpoint + the new
replace core) writing `CopyrightHolderId` independently WILL diverge. Defence: C8 re-routes the
legacy endpoint through the C7 core, making the mirror single-writer; the guard asserts the
endpoint delegates; the UI re-renders from the response so what the curator sees is what the
server stored. The FK-relocate trap (a new non-CASCADE FK to `tblSongs(SongId)` silently disables
song moves for affected songs) is defended by the existing CI pin on
`SONG_RELOCATE_EXPECTED_SONGID_FKS` — which is also why C7 MUST add the const entry in the same
commit as the DDL.

**A.8 — C2 breaks what already works.** An over-eager keydown handler can swallow arrows inside
the INPUT (caret movement) or fight the page's infinite-scroll focus. Defence: the input handler
acts ONLY on ArrowDown-with-results; list handlers act only when a `.song-list-item` has focus;
no wrap, no focus traps; the guard's narrowness is itself reviewed against rule #34's "never fail
on correct code".

**A.9 — Scope discipline.** Wave 4 must NOT drift into: the synonyms build (§0.6 — one owner ask
first), webhooks design (§0.5), the retire-v1 act itself (§0.1 — decision block only), org-logo
surfaces (§0.7), or "while I'm in api2" refactors. Anything discovered en route gets an issue at
the moment of discovery (standing-tasks §2a) and stays out of these commits.

---

## Definition of done

- [ ] C1–C8 landed as atomic commits on `claude/ilyrics-identity-work-model`, in order, each with
      `php -l` / `node --check` clean on its touched files.
- [ ] Every new/extended guard green via the CI-faithful run (`appWeb/.auth/db_credentials.php`
      moved aside; `php tools/run-php-tests.php` + the node suites), with a **mutation transcript**
      (guard shown red against the broken state) recorded in each guard commit's body (rule #34).
- [ ] The C4 funnels-guard auto-coverage PROVEN (bulk_move drafted wrong → existing guard red)
      before relying on it.
- [ ] Both migrations (C6, C7) carry: byte-identical schema.sql mirrors, ONE registry entry each
      with a real probe, rule-#41-safe include paths — `test-schema-coverage.php`,
      `test-migration-registry.php`, `test-deploy-paths.php` all green.
- [ ] C7's `SONG_RELOCATE_EXPECTED_SONGID_FKS` entry lands in the SAME commit as the DDL.
- [ ] `api-docs.yaml` updated (C5/C6/C8) and its lockstep guards green.
- [ ] Issues: #1912, #1911, #1900, #1628 CLOSED with SHAs + evidence; #1903 commented (item 2
      SHA + item 1 owner ask); #1601 carries the §0.1 retire-v1 decision block; nothing re-posted
      on the already-accurate gated issues.
- [ ] Alpha smoke items recorded as PENDING-DEPLOY in the handoff (bulk move/delete/export,
      revision diff, ZIP dry-run, multi-holder chips, search keyboard nav) — never claimed done
      from the container.
- [ ] CHANGELOG + handoff + `.claude/` docs + help/wiki updated (C9).

---

## Executive summary

Eighteen candidate leads verified against the tree and tracker; **six are real** and land in eight
code commits, **six are already done** (several still open only for tracker/deploy reasons), and
the rest are owner-, design-, or sequencing-gated. The real remainder: (1) the last two v2-editor
parity items of **#1628** — a revision **diff view** (new api2 `revision_get` resolving the
before-snapshot server-side + a pure, node-tested `diffSnapshots()` renderer) and the **bulk
move / delete / export** trio, now unblocked because #1679 was resolved option-B and every move
already funnels through `songRelocate()` (bulk = per-song verdicts over the existing cores, export
= bounded per-id reads through the shared `exportSongbook` builders — never a corpus load); (2)
**#1911** ZIP dry-run (one additive `DryRun` column + gating both the async worker and the sync
fallback, degrading to the honest 422 on un-migrated installs); (3) **#1912** interchange
alternative-titles round-trip (optional schema key + bulk `getSongs()` emit + importer map into
the existing write loop); (4) **#1900** multi-holder copyright M:N (one-pass schema mirroring the
songbook-publisher pattern, single-writer denorm re-sync, chips UI reading back server truth) —
with the load-bearing detail that its new FK must join the CI-pinned relocate const in the same
commit; (5) **#1903 item 2**, small `/search` keyboard navigation. Verified already-done and
therefore NOT planned: #1896, #1698 (rehearsal-gated), #1785 (+ its credit_search follow-up,
already fixed by #1800 C1 — no issue needed), #1786, #94/IA-OCR Phase 1, and the whole "catalogue
D5" lead. Deferred with reasons: #1777 (its own text holds it for the P6 enable pass), #1797 and
#1840 (owner/design-gated), #1909 (needs its own rule-#20 design wave), #1910 (backlog by design),
#1874 (runs last by owner directive), and #1903 item 1 (real, but seeding a synonym vocabulary
needs a one-line owner ask first). Effort: S+S+M+M+M+M+M+M+S; the wave deliberately concentrates
on the flagged high-traffic files (`api2.php`, `song_importers.php`, `SongData.php`) in disjoint
regions, in a fixed commit order.
