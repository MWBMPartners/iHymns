# Proposals program plan — verification + triage (Fable-5, 2026-08-21)

**Branch:** `claude/ilyrics-identity-work-model` @ `db41fa42` (read-only pass; no code changed, nothing committed).
**Input:** the 2026-08-18 ranked-proposals doc (written at `009bd3bf`) — **Wave 4 landed in between**
(`#1628` revision diff, `#1900` multi-holder copyright, `#1912` alt-titles round-trip, `#1903` plan lock),
so several verdicts below differ from that doc. Every verdict here was re-verified against the tree,
never against commit titles or issue text; issue STATES were then fetched to derive the issue actions.

**The single most important find:** **#1662 was mis-closed** (2026-08-18) — the 200-song silent
amputation it reports is still live in `includes/setlist_collab.php:355-361` and is now reachable
from THREE write paths. Reopen it (detail in item 2). Its own 2026-08-01 comment predicted this
exact mis-read and the close made it anyway.

---

## 1. Summary table

| # | Item | Verdict | Issue action | Effort left | Deps | Owner-gated? |
|---|------|---------|--------------|-------------|------|--------------|
| 1 | #1897 CCLI usage W2/W3 | **DONE** | none (closed 2026-08-18, matches code) | — | — | no |
| 2 | #1675/#1660 setlist conflict safety | **REAL** (+ #1662 mis-closed) | **REOPEN #1662**; keep #1675/#1660 open | M | none | no |
| 3 | Real-device verify (#1339/#1597/#1771/#1803) | code **DONE**, verify **REAL** | #1597/#1771 already closed; #1339/#1803/#1792 stay open for the pass | S | deployed alpha; 2 devices ONE channel | **yes** (live env/devices) |
| 4 | Importer metadata fidelity (#1673/#1896/#1904) | **DONE** | none (all closed, incl. credits #1736) | — | — | no |
| 5 | Search suggest + diacritics (#307/#1039) | fold **DONE**; synonyms **QUEUED** (#1903 plan); typeahead **owner call** | update #1039 (Part A landed; synonyms = #1903); typeahead needs a FRESH issue if wanted | 0 here | #1903 already locked | typeahead: **yes** (product) |
| 6 | #1710 settings "sign in" copy | **DONE** | none (closed, matches code) | — | — | no |
| 7 | #1802 shared-setlist "add a song" | **REAL** | keep open | S–M | picker exists (rule #43) | no |
| 8 | #1806 settings.js namespace | **REAL** (server ready) | keep open | S | none | no |
| 9 | #1743/#1744 revision integrity | #1743 **DONE**; #1744 **PARTIAL** (A5+Rev-2 left; Rev-1 fixed) | update #1744; fix A5; Rev-2 rides #1601 | S | Rev-2 ↔ #1601 | Rev-2 partially |
| 10 | #836/#1669 alt-titles editor | **DONE** | **CLOSE #836** (panel shipped; #1669 already closed) | — | — | no |
| 11 | #1655/#1616 C6 drop unblock | **REAL** (data chase; code complete) | keep open | S–M | live DB; owner runs manual card | **yes** |
| 12 | #1601/#1809/#1858 legacy editor | #1858 **DONE**; #1809 **substantially done**; #1601 **owner a/b/c** | update #1809 (14/37 documented, 23 excluded by design); #1601 awaits decision | M (if retire) | #1601 decision | **yes** (#1601) |
| 13 | Account security (#1027/#947/#340) | #1027 **DONE**; CAPTCHA **REAL** | none for #1027 (closed); keep #947/#340 | M | CSP design needed | provider choice: soft |
| 14 | CI signal (#1891/#1892/#1579) | #1579 **fixed in-tree**; #1891 **PARTIAL**; #1892 **REAL** | close #1579 w/ evidence; update #1891; #1892 pends Android scope | S–M | #1892 ↔ Android decision | #1892: **yes** |
| 15 | #1666 vendor-download hashing | **REAL** | keep open | S | none | no |
| 16 | #1148 browse-by-theme | **PARTIAL** (strip DONE; /themes index left) | update #1148 | S–M | none | no |
| 17 | Micro-perf (#1571 + QR cache + ETag) | **REAL** (all three) | file QR-cache + ETag issues; keep #1571 | S–M | QR cache = migration (rules #19/#20) | no |
| 18 | #1531 songbook names + credits | **PARTIAL** (names DONE; italic credits left) | update #1531 | S | none | no |
| 19 | #1713 bg-dark sweep | **REAL** — real count **85**, not ~69 | update #1713 w/ count; fold into #1876 | (in #1876) | #1876 queued (#1874 epic) | queued elsewhere |
| 20 | #1122 diff/blame | **PARTIAL** (diff+restore DONE; blame left) | update #1122 | M | owner-taste ('for consideration') | soft |

---

## 2. Per-item evidence (all paths under `/home/user/iHymns/`)

### Item 1 — #1897 CCLI usage write-coverage W2/W3 → DONE
- **W2 (Service-Mode writer):** `appWeb/public_html/includes/print_usage.php:545` `projectionUsageLog()`
  (org-anchored resolver `printUsageResolveOrgCcliLicence()` :437; shared insert core :255-293 extracted
  from `printUsageLog()` "#1897 W2"). Wired at `includes/service_mode.php:1870` inside
  `serviceMode_applyBroadcast()` — BOTH broadcaster mechanisms funnel through it (rule #26). The org
  CCLI report surfaces projected rows (`manage/includes/ccli-report-results.php:52,95,143`;
  `includes/ccli_report.php:211`).
- **W3 (SetlistId):** client sends it (`js/modules/print.js:1095-1111` `setlist_id`); the API rides it
  into meta (`api.php:9626`); the batch PDF path accepts + rides it (`manage/print-pdf.php:63,266,404`);
  the insert core stores it (`print_usage.php:281,293`).
- Issue closed 2026-08-18 — **correctly**. Live-env confirmation of report rows belongs on the item-3
  checklist, nothing more.

### Item 2 — #1675/#1660 collab conflict safety → REAL; #1662 MIS-CLOSED
- **The upsert is still unconditional last-writer-wins:** `api.php:3758-3776`
  (`ON DUPLICATE KEY UPDATE Name=VALUES(Name), SongsJson=VALUES(SongsJson), UpdatedAt=VALUES(UpdatedAt)`
  — no per-row version/watermark compare). The `since` watermark protects only DELETION of unseen rows
  (`userSyncDeletableIds()`, api.php:3921-3935). #1660's status comment (2026-08-18) confirms the same.
  The doc-comment half of #1660 is already truthful (api.php:3539-3542 admits "unconditional").
- **#1662 re-verified NOT fixed:** `includes/setlist_collab.php:355-361` —
  `setlistCollabSanitiseSongs($songs, int $max = 200)` still `array_slice($songs, 0, $max)`, a silent
  truncation, called from **three** write paths: `api.php:3860` (`user_setlists_sync`), `:11803`
  (`setlist_collab_update`), `:11971` (`setlist_token_update`). No `songsTruncated` flag exists anywhere
  (grep: zero hits). The 2026-08-18 close comment claimed "user_setlists_sync no longer slices at all" —
  that was the **setlist-collection** cap (#1661) and the **plan-slot** cap (#1671 F4), not the songs
  array; the issue's own 2026-08-01 comment documents this exact distinction. **Reopen.**
- Correctly closed from the cluster: **#1664** (tombstones + anti-resurrection, api.php:3841-3851,
  3692-3711) and **#1699** (lazy expiry runs FIRST in the load-bearing order, api.php:3680-3690 and
  :3483-3489 on the read path).
- **Fix shape (for the design pass):** per-row conflict refusal on content overwrite (server row
  `UpdatedAt > since` ⇒ keep server, report the conflict — the same "err toward keeping" doctrine as
  #1649), + the songs cap becomes a 413 REJECTION (the #1661 precedent: reject-before-write, never
  slice — now needed on all three call sites). Legacy native clients (no `since`, no `deleted`) must
  keep byte-identical behaviour. Rules: #35 (status codes, not prose), #20.5 presence-of-key clear
  semantics, protocol-2 gating pattern already in file.

### Item 3 — Real-device / live-env verify pass → code in place; the PASS is the deliverable
- **SW #1597:** all RC machinery present in `appWeb/public_html/service-worker.js.php`
  (SAVED_CACHE :215, keep-list `swKeptCacheNames()` :267/:759, legacy promotion :728-745,
  `storage.persist()` noted :209). Issue now CLOSED.
- **Backup #1771:** streaming `MYSQLI_USE_RESULT` in `appWeb/.sql/backup.php:32,166`. CLOSED.
- **Live Follow #1339:** projector + lead + follower surfaces all exist
  (`manage/service-projection.php`, `manage/service-lead.php`, `js/modules/service-follow.js`);
  only the two-device live verify remains (issue OPEN; #1792's channel-walling caveat applies —
  both devices must be on ONE channel or the test fails by design).
- **#1803** IA-reconcile live smoke: OPEN, needs alpha + archive.org.
- Deliverable: ONE scripted checklist run on deployed alpha; file/close from findings. **Owner-gated**
  (env + devices), S effort.

### Item 4 — Importer metadata fidelity → DONE (all of it)
- Rights: `includes/song_importers.php:372-437` (`_bulkImportRightsFromSong()` — copyright/CCLI/ISWC/
  Verified/LyricsPD/MusicPD extracted per format: OpenSong :2115, VideoPsalm :2441, ChordPro :2655+),
  written on INSERT :618-623, Work auto-link :706 (`workAutolinkSafe`).
- Credits (#1736): :737-782 — writers/composers/etc. via the SAME `creditEntryNormalise()` +
  `musicianPromote()` core the editor uses, per-role case-insensitive dedup, PD recompute :852.
- Issue states: #1673, #1896, #1736, #1904 ALL closed (the last, today, as duplicate-of-#1736 with a
  code-verified rationale that matches this read). **Nothing to do; drop from the program.**

### Item 5 — Search suggestions + diacritic folding → collapses to one owner question
- **Diacritic folding (#1039 Part A): DONE** — `includes/SongData.php:3170-3240` (folded FULLTEXT arm
  over `NormalizedTitle`/`LyricsTextFolded`, `$foldReady` gate); the v1 save core maintains the mirror
  too (`manage/editor/save_song_core.php:729` `searchFoldSyncSong()` — which incidentally fixed
  #1744 Rev-1, see item 9).
- **Synonyms:** locked, owner-greenlit plan `.claude/search-synonyms-1903-plan.md` (2026-08-21) —
  ALREADY QUEUED; do not duplicate. Implementing it is what closes #1039.
- **Typeahead:** `api.php` has NO suggest action (only a history note :9888); #307 is closed
  **not_planned** ("if a suggestions dropdown is ever wanted again it should be a fresh, scoped issue
  against the current search page" — the header search bar itself was removed in #812, and `/search`
  already live-searches as you type). Re-introducing it is a PRODUCT decision → present to owner via
  the §"decision" template; do not build unbidden.

### Item 6 — #1710 settings sign-in copy → DONE
`includes/pages/settings.php:408-421` — "`$currentUser` IS now resolved by api.php for non-cacheable
fragments (settings is one — #1710), so the language-sync copy below correctly distinguishes
signed-in from signed-out"; the conditional at :666-670. Issue closed. Matches.

### Item 7 — #1802 shared-setlist "add a song" → REAL
`includes/pages/setlist-shared.php` is a 173-line shell (no add control); the #1791 token-edit surface
in `js/modules/setlist.js:3414-3494` offers reorder/remove ONLY (`sharedSetlistRowsHtml` +
`bindSharedSetlistRowControls` + `pushTokenEdit` → `setlist_token_update`). No add-song affordance
exists on any shared surface (grep `addSong|Add a song`: only the personal-list dialog).
Dependencies all in place: the generalised picker (`js/modules/place-search.js` `pickMode`, rule #43),
the write path (`setlist_token_update` → `sharedSetlistUpdate()`, rule #40 — mint nothing, write
through the ONE core, read back what the server stored, 401 `signin_required` = sign-in prompt).
**Pairs naturally with item 2** (same handler family; and note the add path will hit the same
200-song sanitiser being fixed there).

### Item 8 — #1806 settings.js namespaced sync → REAL (server ready)
Client still whole-blob: `js/modules/settings.js:205-212` POSTs `{settings: …}` with NO `namespace`
key (pull :224 likewise). Server namespace contract fully shipped: `api.php:4044-4160`
(`?namespace=` GET subtree, body `namespace` POST replaces only that subtree; #1671 F5). The exposure
is real: a whole-blob push can clobber `list_sorts`/`cardLayouts` subtrees. Migration = client sends
`namespace: 'ihymns.web'` + a one-time read of any legacy top-level keys. **No server-side file-based
settings remain** (tblUserPreferences dropped via the manual `drop-user-preferences` card; the client
side is localStorage mirrored to the DB store — nothing on disk).

### Item 9 — #1743/#1744 Editor2 revision integrity → #1743 DONE; #1744 partial
- #1743 (closed): the chain rule `api2.php:1982-2034` (revision N's PreviousData := N-1's NewData;
  legitimate NULL only for a first save), and the 3-rung restore ladder + `revision_get` :6479-6620,
  `revision_restore` :6618+.
- #1744 (open, 'for consideration'): **A5** v2 `credit_upsert` still has NO same-name dedup
  (verified: `api2.php:3638`-onwards contains no `$seenCredit`/existence check — the only "never
  overwrites" logic is the registry COALESCE). **Rev-1** (v1 NormalizedTitle) is now FIXED as a
  side-effect of #1039 Part A (`save_song_core.php:729` repairs NormalizedTitle in-transaction).
  **Rev-2** (v1 never mints PublicId) still true (`grep -c PublicId save_song_core.php` = 0) — moot
  if #1601 retires v1. Action: fix A5 (small, mirror `$seenCredit` #1178); update the issue (Rev-1
  done); park Rev-2 on the #1601 decision.

### Item 10 — #836/#1669 alt-titles chip editor → DONE
`manage/editor/v2/alt-titles-panel.js` (mounted from `metadata-tab.js:1742`), api2 actions
`song_alt_titles`/`_add`/`_delete` (`api2.php:4917-4994`, duplicate-song copy :2259), the ONE write
core `includes/song_alt_titles.php`. #1669 closed; **#836 is still OPEN → close as shipped**
(cite the panel + #1912's interchange round-trip).

### Item 11 — #1655/#1616 unblock the C6 LinesJson drop → REAL, owner/live-DB-gated
In-tree side complete: registry entry `manage/includes/migration-registry.php:2547-2572`
(`'manual' => true`, confirm-gated, probe = LinesJson column existence), soak gate via
`appWeb/.sql/verify-lyrics-cutover.php` green-sentinel < 24 h. What remains is a LIVE-DB data chase
(the ONE component failing G1 mirror-count) + the #1616 runbook + the owner pressing the manual card
inside a maintenance freeze. Cannot progress from this container beyond preparing the
investigation SQL.

### Item 12 — #1601/#1809/#1858 legacy editor → mostly resolved; one owner decision
- **#1858 CLOSED + verified:** `manage/editor/index.php:1611-1613` — Bootstrap JS emitted ONCE by
  admin-footer.php, comment names #1858; CSS via the shared emitter :137-138 (rule #36).
- **v2 default confirmed:** `index.php:75-79` 302 → editor2.php, `?legacy=1` escape.
- **#1809 substantially done:** `api-docs.yaml:7205-7228` — 14 of ~37 v1 actions documented; the
  remaining 23 internal-only actions are EXCLUDED with a written rationale and a reproducible
  parser-derived list (`dispatchParserCasesForSwitch()`); full write-up deliberately declined while
  #1601 pends. Action: update #1809 to reflect this state; final disposition follows #1601.
- **#1601 OWNER-GATED:** the a/b/c decision (keep-302-and-retire-v1-in-a-dedicated-pass / delete now /
  leave indefinitely) is posted on the issue with recommendation (a); non-blocking. If (a): the
  retirement pass is M and interacts with #1743-C3 restore shapes, legacy `songbook_export`, and
  several tree-derived guards.

### Item 13 — account-security pack → lockout DONE; CAPTCHA real
- **#1027 CLOSED + verified:** per-ACCOUNT lockout `api.php:3162-3220` — `'acct:'.sha256(username)`
  bucket through the shared rate-limit helpers, rides idx_IpTime, threshold 20/15 min composing with
  the per-IP 10/15 min (:3138-3160, #290); admin login mirrors it (`manage/includes/auth.php:567`).
- **#947/#340 REAL:** the only trace is a `captcha_provider` settings key surfaced to clients
  (`api.php:6849,6907`, default `'none'`) and a scaffold comment in `manage/configuration.php:9`.
  No verify path, no widget, no secret keys. Design constraints for the pass: the enforcing nonce
  CSP (#117 / rule #30) — Turnstile/hCaptcha load third-party script + frames, so `script-src`/
  `frame-src`/`connect-src` must be extended CONDITIONALLY (only when a provider is configured), SRI
  is impossible for their rotating scripts (documented exception to rule #36's spirit), secret keys
  via `secretSettingKeys()` (encrypted at rest, the CueRCode pattern), dormant-by-default (`'none'`),
  provider-pluggable per #340.

### Item 14 — CI/release signal → one close, one investigate, one parked
- **#1579 (apple-deploy): fixed in-tree** — `apple-deploy.yml:112` `if: vars.APPLE_DEPLOY_ENABLED ==
  'true'` skips the deploy job; the always-on guard job keeps runs green and prints an explicit
  enabled/disabled notice. Live runs: main/beta SUCCESS 2026-08-16; last alpha failure 2026-07-10
  (pre-guard). Action: close with this evidence once one post-guard alpha push is observed green.
- **#1891 (changelog/version false-red): PARTIAL** — version-bump.yml RETIRED (#1899 `3540071c`);
  changelog.yml now beta-only + #1899-defused for GITHUB_TOKEN pushes… yet its latest beta run
  **FAILED 2026-08-16**. Remaining work: read that run's log, fix the residual failure mode
  (likely the human-push path's push-back at changelog.yml:220-233). S.
- **#1892 (Android build job): REAL but scope-gated** — `build-android.yml` exists but is
  workflow_dispatch + tags only (push trigger removed #904; Gradle wrapper/keystore prerequisites
  documented in the file header). The AGP 9.3.1 bump therefore remains CI-unverified. Adding the job
  is S–M *mechanically* but only worth it if Android is in alpha scope — same owner decision as the
  Android-revival L item. Park pending that.

### Item 15 — #1666 vendor-download verification → REAL
`tools/download-vendor.sh` verifies only non-emptiness (:47 "verify it's not empty"); no
sha384/sha256 check against `APP_CONFIG['libraries']` (rule #36's registry). S: pull each entry's
`integrity` hash from `includes/config.php` and `openssl dgst`-verify post-download; fail loudly.

### Item 16 — #1148 browse-by-theme → PARTIAL
Home strip landed: `includes/pages/home.php:406-422` ("#305 → rethought in #1148" — Top-N by usage,
client-rendered, "Browse all themes" inline reveal). The **searchable `/themes` index page is the
explicit follow-on and does not exist** (no `includes/pages/themes.php`; only `tag.php` for one
theme). Remaining: S–M — a cacheable fragment (rule #6 constraints) + router route + ES-module wiring
(rule #30), querying the #1152 hierarchy (`tblSongTags.ParentId`, rule #23).

### Item 17 — micro-perf & resilience pack → REAL (all three)
- **(a) `/qr.php` server cache:** browser-side immutable only (`qr.php:86`); every cold-cache QR
  round-trips to CueRCode. A `tblQrCache` keyed on payload-hash needs ONE migration-registry entry +
  schema.sql mirror (rules #19/#20) and lives behind the ONE client (rule #38). Never filed → file it.
- **(b) `songs_index` ETag:** `api.php:1810-1816` — plain `sendJson`, no ETag/304 (the fragment ETag
  machinery at :886 covers `page=` fragments only). The slim index is NOT language-filtered
  server-side (`SongData.php:2286+` — emits `language` per row for client filtering), so a plain
  content-hash ETag with no Vary complications works. Never filed → file it.
- **(c) #1571 songbook_export at MP scale (open):** server hydrates all 3,517 songs incl. components
  in one request; client probundle builds an uncompressed ZIP in memory in one click handler with no
  progress UI. M: chunked/streamed export + progress; respect rule #17's spirit.

### Item 18 — #1531 → PARTIAL
Part 1 (full songbook NAME in list views) DONE: `js/constants.js:221-231` (`songbookLabel()` cites
#1531; registry populated once from `?action=songbooks`, `app.js:184`; used in setlist :3535,
favourites, search). Part 2 ("Unofficial → italic writing-team credits") NOT started — its enabling
helper was deleted as a no-caller orphan in #1696 (`constants.js:294-306` records this); re-adding
`isOfficial` to the registry is one line (:272-279 says so). Remaining: S; use the one badge/a11y
conventions of rule #24.

### Item 19 — #1713 bg-dark sweep → REAL; real count is 85
`grep -r bg-dark manage --include='*.php'` = **85** (top: configuration.php 27, setup-database.php 16,
musicians.php 15, languages.php 6, ia-reconcile.php 5; the legacy editor's history modal
`index.php:1620` is a live example). #1876 (WCAG sweep incl. CVD/dyslexia; parent epic #1874,
owner-queued to "run after the architectural queue") is OPEN and is the right vehicle — **fold #1713
into #1876** (update #1713 with the derived count + the per-file table so the sweep starts from
tree-derived data, rule #34).

### Item 20 — #1122 field-level diff/blame → PARTIAL
Field-level **diff**: DONE (#1628-C3 — `revision_get` `api2.php:6479-6620` returns the before/after
pair; pure `diffSnapshots()` `v2/revisions-tab.js:390`, rendered pre-Restore). Whole-revision
**rollback**: DONE (#1743 `revision_restore`). Remaining: the **blame** view (per-field "who last
changed this + when" walked across the whole history) and optional per-field rollback. Issue is
'for consideration' — confirm appetite with the owner before building; M if wanted (pure read/compute
over tblSongRevisions, no schema).

---

## 3. Implementation order (REAL work only)

Per `.claude/standing-directives.md`: one branch, no PR stacking; each item = plan-if-complex →
Sonnet/Haiku implement (Opus if complex) → verify → commit+push → issue → `.claude/` → handoff.

### Wave 0 — tracker corrections first (no code; feeds the #1878 sweep)
0. **Reopen #1662** (evidence in item 2). **Close #836** (item 10). **Close #1579** (after one green
   alpha push). Update #1744, #1809, #1039, #1148, #1531, #1713 (+count 85), #1122, #1892, #1891 per
   the table. File the two never-filed perf issues (QR cache, songs_index ETag). *Sonnet-direct.*

### Wave 1 — small, independent, zero-risk (bundle as one PR of atomic commits)
1. **#1666 vendor hashing** (S) — pure tooling. *Sonnet-direct.*
2. **#1806 settings.js `namespace:'ihymns.web'`** (S) — client-only; include the one-time legacy-blob
   read-through. *Sonnet-direct.*
3. **#1744-A5 credit_upsert dedup** (S) — mirror `$seenCredit` (#1178) semantics in the v2 endpoint.
   *Sonnet-direct.*
4. **#1531 part 2 italic unofficial credits** (S) — re-add `isOfficial` to the client registry
   (one line, per constants.js's own note) + the display rule. *Sonnet-direct.*

### Wave 2 — the set-list correctness cluster (the flagship; ONE Fable design pass, then implement)
5. **#1675 + #1660 + reopened #1662 + #1802 together** (M) — per-row conflict-safe upsert (watermark
   compare, err-toward-keeping), songs-cap 413 rejection on all three call sites, and the add-a-song
   picker on the shared edit surface. One family of files (`api.php` sync cases,
   `includes/setlist_collab.php`, `includes/user_sync.php`, `js/modules/setlist.js`), one design pass
   covering: legacy native-client byte-compatibility, protocol-2 gating, rule #35 status-code
   contracts, rule #40 share-core writes, rule #43 picker. **Needs its own Fable design pass** —
   sync semantics across three write paths with silent-data-loss history is exactly the class that
   punished under-design before (#1649).

### Wave 3 — perf & resilience pack (short Fable design for (c); (a)/(b) Sonnet-direct)
6. **songs_index ETag/304** (S) and **tblQrCache** (S; one-pass DDL per rules #19/#20 — design the
   final shape once: payload-hash PK, format/size/ecc in the key, byte column, CreatedAt, prune
   policy). *Sonnet-direct from this triage.*
7. **#1571 songbook_export scale** (M) — server chunking + client progress + probundle memory. The
   export re-shape (pagination protocol vs streamed NDJSON vs size-capped chunks) deserves a **short
   Fable design note** before implementation.

### Wave 4 — security (Fable design pass required)
8. **#947/#340 CAPTCHA** (M) — provider-pluggable, dormant (`'none'` default), secrets via
   `secretSettingKeys()`, and — the hard part — conditional CSP extension under the enforcing nonce
   policy (#117). **Fable design pass** before any code.

### Wave 5 — UI depth
9. **#1148 `/themes` index** (S–M) — cacheable fragment + ES-module wiring; pattern-match
   `songbooks.php` + `tag.php`. *Sonnet-direct.*
10. **#1122 blame view** (M) — ONLY after owner confirms appetite ('for consideration'). If greenlit,
    a **Fable mini-design** for the history-walk semantics (snapshot shapes vary across eras —
    api2.php:6519's three-shape note is the hazard).

### Owner-gated / live-env (schedule around the owner; not buildable from here)
- **Item 3 device-verify checklist** — needs deployed alpha + two devices on ONE channel (#1792).
- **#1655/#1616 C6 soak chase + drop** — live DB investigation, then the owner runs the manual card
  in a freeze. Prepare the investigation SQL in advance (S).
- **#1601 a/b/c** → then the v1 retirement pass (M) or park; #1809's final disposition follows.
- **Typeahead** (#307-successor) — present as a §"Asking the owner" decision (product question:
  re-adding a suggest affordance vs the #812 one-affordance doctrine); only file a fresh issue on a yes.
- **#1892 Android CI** — pends the Android-in-alpha-scope decision.
- **#1713** — runs inside the queued #1876 sweep, not separately.

### Explicitly NOT in this program (already done or queued elsewhere)
Items 1, 4, 6, 10 (done — tracker already/now reflects it); #1039-synonyms (locked plan #1903,
owner queue); #1743, #1858, #1027, #1597, #1771, #1664, #1699 (done + closed); #1876 (owner's
hardening epic).

---

## 4. Owner decisions that block or gate specific items

| Decision | Blocks | Where it stands |
|---|---|---|
| #1601 a/b/c (v1 editor code disposition) | v1 retirement pass; #1744 Rev-2; #1809 final form | Posted on #1601 with rec (a); non-blocking to everything else |
| Android in alpha scope? | #1892 CI job (and the Android-revival L item) | Never asked crisply — ask via the § template |
| Typeahead: re-add a suggest affordance? | any suggest work | #307 closed not_planned; needs a fresh product yes |
| #1122 blame: still wanted? | Wave 5 item 10 | Issue is 'for consideration', unscheduled |
| C6 drop: run the manual card after soak goes green | #1655/#1616 close-out | Owner-run by design (destructive, freeze + backup) |
| Live-env access for the verify pass | Item 3 | Needs deployed alpha + two devices, one channel |

*(None of these blocks Waves 0–3, which cover the majority of the real work.)*
