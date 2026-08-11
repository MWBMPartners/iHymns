# #91 — FINAL documentation-consistency sweep + version bump — build plan

**Written 2026-08-11** (Fable-5 deep-recon pass on `claude/issue-sweep-fixes-89`; no code changed).
**Tracker:** #91. **Baseline:** 214 commits since merge-base `18b16dde` (`origin/alpha`), working
tree clean, branch pushed. Every claim below was verified against the tree on this date — file
paths and line numbers are real, and "already covered" means *checked, present*, not assumed.

This is the epic-level reconciling pass the feature builders deferred: they updated
CHANGELOG/ProjectBrief **incrementally and well** (≈95% of CHANGELOG entries exist; the OpenAPI
spec is **already complete** for every new action), so this sweep is mostly (a) three whole
feature families whose docs were *explicitly deferred to #91*, (b) the user-facing help/wiki
surfaces, and (c) the version bump. A builder should execute each phase below mechanically.

---

## OWNER DECISIONS (all defensible defaults chosen — none block starting)

### D1 — Version string: `1.5000.0` (recommended) or `0.5000.0`?

1. **The decision:** the owner asked for **v1.5000.x**. Plan sets
   `$app["Application"]["Version"]["Number"] = "1.5000.0"`.
2. **Why it needs deciding:** every prior version is `0.XXXX.Y` (currently `0.4100.0`), and
   `infoAppVer.php:86` carries a legacy comment *"v1.x.x = Phase 1 (local JSON data), v2.x.x =
   Phase 2 (iLyrics dB)"* that gives `1.x` a conflicting historical meaning. If the owner meant
   "the 5000 minor under the existing scheme", it's `0.5000.0`.
3. **Options:** `1.5000.0` (as literally asked; retire the stale phase comment — the local-JSON
   phase died with epic #1010 anyway) · `0.5000.0` (keeps the legacy major; one-string change) ·
   do nothing (version stays `0.4100.0`, misrepresenting a 214-commit batch).
4. **Recommendation: `1.5000.0`** — it is what was asked, and the phase-meaning comment is
   already false to reality; the sweep rewrites it either way (C3.2).
5. **Need back:** "1.5000.0" or "0.5000.0". **Does not block** — it is one string in six files
   (C3 lists them); trivially flipped before merge.

### D2 — `manifest.json` `"version"` field: bump in lockstep (default: yes)

`appWeb/public_html/manifest.json:8` says `"version": "0.1.5"` — it has NEVER tracked
`infoAppVer.php` (non-standard informational member; nothing reads it). Default: bump it to the
new version in C3 so the three version strings in the repo agree. Trivially reversible; say "leave
it" to skip.

### D3 — Which new invariants get a numbered CLAUDE.md rule?

CLAUDE.md rules are load-bearing (auto-loaded into every session), so additions are curated.
Recommendation (full draft text in C5.3): **two new rules** — #39 (server-PDF one-renderer +
sanitiser + mPDF-vendored/dormant-503) and #40 (set-list capability-URL / edit-audience model) —
plus **two `project-rules.md` notes** (public list-sort persistence model; the SSRF-hardened
outbound-client pattern now at 3 instances). The gating registry/pipeline needs **no new rule**:
rule #28 already covers `TIER_CAPS`, and C5.3 instead patches #28 with the #1769 P2 resolver file
names. **Need back:** nothing — proceed as recommended; the owner can strike a rule in review.

---

## §0 — What landed (grouping of the 214 commits) and its per-surface docs status

Authoritative design docs per family (cite these; do NOT re-derive design):

| Family | Commits (first…last) | Plan file(s) | CHANGELOG | OpenAPI | help | wiki |
|---|---|---|---|---|---|---|
| **#1765 songbook/catalogue epic** — songbook disable + PD flag + ARK/OpenLibrary ids + Google Books provider + MARCXML import/export + form parity | `b0cdbd27`…`cbeeeba0`, fixes `0ca1a480`/`4b736005` | `.claude/songbook-catalogue-enhancements-plan.md` | ❌ **NONE** (docs explicitly deferred "to Step 7 / #91") | n/a (admin page-local actions) | ❌ | ❌ |
| **#93 Publishers registry** (part of epic #1765) | `eb275953`…`beb228e3` | rule #37; plan §Features | ✅ (5 entries) | ❌ `?page=publisher` missing | ❌ | ❌ |
| **#1769/#1778 gating program P0–P6 + hub** | `9ecfa1fb`…`3acc68b6` | `.claude/gating-model-review-1769-plan.md`, `gating-p2/p3/p4-design.md` | ✅ P2–P5 + hub (P0/P1 only referenced) | ✅ (`admin_licence_type_*` ×4) | ❌ admin help | ❌ |
| **#1771 backup streaming fix** | `c690a78b` | — | ✅ | n/a | n/a | n/a |
| **QR → CueRCode** (owner directive) | `115b4a3e`…`96028999` | `.claude/qr-cuercode-integration-plan.md` | ✅ | n/a (`/qr.php` is not an api.php action) | n/a | (Architecture optional) |
| **#1767 print remainder** — schema (OrgId + custom layouts), HTML/CSS sanitiser, mPDF vendor, server-PDF pipeline + `manage/print-pdf.php`, Download-PDF affordance, CCLI print-usage (copies prompt + log + enforced footer), full-HTML custom layouts, batch set-list PDF | `2ce2daa0`…`ffada413` (P1–P7) | `.claude/print-templates-1767-remainder-plan.md` (+ `print-templates-1767-plan.md` for the shipped A–AM half) | ❌ **NONE** for the remainder (the older Z/J + no-schema-slice entries exist) | ✅ (`print_usage_context`/`_log`) | ❌ | ❌ |
| **#94 IA-reconcile Phase 1** (read-only audit tool) | `e33f09fd`…`2418eac7` | `.claude/ia-ocr-94-plan.md` | ❌ **NONE** | n/a — deliberately NO public API (admin page-local; note it) | ❌ admin help | ❌ |
| **#1791 set-list share/edit links + org policy** (server `7679d33d`…`0a9fbb19`; client `6984b36f`…`1f8f0e40`) | | `.claude/setlist-sharing-1790-1791-plan.md` | ✅ client half (server half has NO standalone entry — referenced only) | ✅ (`setlist_share` scope, `setlist_token_update`/`_share_list`/`_share_revoke`) | ✅ **help.php Sharing topic rewritten 2026-08-11** | ✅ wiki Setlists §Sharing |
| **#1790 playlist-first shared page** | `76781aba` | same plan | ✅ | n/a | ✅ | ✅ |
| **#1789 set-list print fix + engine reuse** | `60cd3bc6`, `83d45a68`/`2eff4be1` | same plan | ✅ | n/a | ❌ (no print help at all) | ❌ (no print section) |
| **#1783 duplicate-song** | `34b86654`…`276a0aa9` | `.claude/duplicate-song-1783-plan.md` | ✅ | ✅ (`duplicate_song` in editor api2) | n/a (curator) | (Architecture optional) |
| **#1788 ProPresenter CSP-safe export** | `453c6dc2` | — | ✅ | n/a | n/a | n/a |
| **#1770 Live Follow C1–C8** (host bar, idle auto-close, host-CCLI unlock, `service_drive` + driver keys, svc_code) | `559ac465`…`bf8a7e95` + `99e9d13a` (C8 docs) | `.claude/live-follow-1770-plan.md`, `live-follow-1770-analysis.md` | ✅ | ✅ | ✅ (C8 did api-docs/help/wiki) | ✅ |
| **#1792 Quick-code lifetime + cross-channel error** | `f57e283c`, `65682f64` | — | ✅ | ✅ | ✅ | ✅ (`3e9034bc`) |
| **#1798 session length + extend + extend-on-behalf** | `1709e13c`…`5d022af2` | — | ✅ | ✅ (`live_follow_extend`; `live_follow_create.idleTimeoutMins`) | ✅ (`help/live-follow.md` + help.php topic, 2026-08-10) | ❌ **wiki NOT updated** |
| **#1784/#1785/#1800/#1799 musicians dedup family** | `bb75b682`, `325c4446`…`85674bea`, `9912524a`…`e4dd793e`, `65ad0f5e` | `.claude/musicians-dedup-1785-plan.md` | ✅ | n/a | n/a (curator) | ✅ (Architecture/Security/DB `6181f62f`) |
| **#1786 public multi-level list-sort + account sync** (+ admin sortable sweep `03aa8b6d`) | `f20b3af8`…`0c03bf0d` | `.claude/public-list-sort-1786-plan.md` | ✅ | ✅ (search `sort`, `user_settings` `list_sorts` + its 403) | ❌ **no help topic** | ✅ PWA-Features line (`0c03bf0d`) |
| **#89 sweep items** — #288 tags on song page · #150 article-blind alphabetisation · #307 dead autocomplete removal · #299 inline chords · #302 set-list Save-as-PDF · #85 "#0" fix · #112 offline count | `e9fcf3e7`…`88890e8e` | — | ✅ (`183c1844`) | ✅ (#307 removed `suggest` from docs) | partial (chords ✅; tags/#302 ❌) | ❌ PWA-Features |
| **#1736/#1739 importer/link fixes** | `dd466d8c`, `c51b259e` | — | ✅ | n/a | n/a | n/a |
| **#1797 ProPresenter shim** | `1c6c6dd1` | `.claude/propresenter-shim-1797-plan.md` | n/a — **spike only, no code**; do NOT document as shipped | — | — | — |

**Also already done (do NOT redo):** LICENSING.md mPDF/GPL-2.0 entry + rationale note (verify
only); SECURITY.md's `validateCsrfRequest` list gained the #1785 pages; DEV_NOTES §musician-dedup;
PROJECT_STATUS "2026-08 highlights" first entry (#1785); README musicians bullets + admin-table
Musicians cell; CLAUDE.md rules #37/#38 + their red flags; `data/whats-new.md` regenerates from
CHANGELOG on every deploy (no manual step).

**OpenAPI completeness — VERIFIED:** parsing the four dispatchers with
`tests/php/lib/dispatch_parser.php` gives **294 real actions vs 275 documented paths**; the 19-gap
is exactly the **20 legacy `manage/editor/api.php` actions** (`add_song_link`, `load_songs`,
`song_media_*` ×5, `bulk_import_*` ×10, `get_song_links`/`remove_song_link`/`suggest_song_links`/
`dismiss_song_link_suggestion`) which were **already undocumented at merge-base** (checked against
`18b16dde`'s api-docs.yaml) — a deliberate pre-existing hole, not a branch regression. Every
action ADDED on this branch is documented: `admin_licence_type_{create,update,toggle,delete}`,
`live_follow_extend`, `print_usage_context`, `print_usage_log`, `service_drive`,
`service_driver_key_{mint,list,revoke}`, `setlist_token_update`, `setlist_share_list`,
`setlist_share_revoke`, plus editor-api2 `duplicate_song`, the `setlist_share` `scope`/`editAudience`
params, `search`'s `sort` param, `user_settings`'s `list_sorts` namespace, and
`live_follow_create`'s `idleTimeoutMins`. The ONLY missing spec item found: **`?page=publisher`**.

---

## Phase C1 — in-app help (commit 1: `docs(help): …`)

Files: `appWeb/public_html/includes/pages/help.php` · `help/exporting.md` ·
`help/searching-songs.md` · `help/README.md` · `appWeb/public_html/manage/help.php`.

⚠️ `includes/pages/help.php` is an SPA fragment — **no executable inline `<script>`** (rule #30;
`tests/php/test-fragment-inline-scripts.php` enforces). Update its header doc-block's
"Last updated" narrative (the file's convention) in the same edit.

### C1.1 `help.php` — NEW accordion topic "Printing & saving as PDF" (place after "Sharing & Exporting Songs", `#help-export` ~line 302)

The public app has **zero** print/PDF help (grep-verified: no "print"/"PDF" in the body). Cover:

- **Print a song** — the song page's Print opens a template-driven printout (curator-designed
  templates decide blocks/page size/chords etc.).
- **Download PDF** (#1767 remainder P4) — a **"Download PDF"** button appears beside Print in the
  template-picker dialog **when signed in** (`manage/print-pdf.php?ping=1` gates it — the server
  endpoint needs an authenticated session; anonymous users simply see Print only, so word it as
  "sign in to get a direct PDF download"). Same layout as the printout, server-generated, named
  after the song.
- **Set lists** — Print, **"Save as PDF"** (#302 — via the browser print dialog), and the
  set-list **Download PDF** which renders the WHOLE list as ONE PDF (#1767 P6: cover page +
  running order + each song).
- **CCLI copies prompt** (#1767 P5) — when the signed-in user's organisation holds a CCLI licence
  AND the song carries a CCLI number, printing/downloading asks "how many copies?" and logs them
  for the organisation's CCLI report (`/manage/ccli-report`); a CCL notice line is added to the
  printed footer automatically. For everyone else nothing changes — say so.
- **Songbook + number on printouts** — one sentence (unofficial/collection songs print no book/
  number; already worded in `help/exporting.md:48-51`, reuse it).

### C1.2 `help.php` — NEW accordion topic "Sorting lists" (place after "Searching for Songs", `#help-search` ~line 213)

#1786 has NO help. Cover: the **Sort ▾** control on catalogue lists (`/songbooks`, a songbook's
songs, favourites, search results, theme/musician/tune/publisher/work/identifier pages); up to
**3 levels** ("then by…"); per-surface memory on this device; **synced to your account** when
signed in; "Reset to default". One short topic, ~10 lines.

### C1.3 `help.php` — edit "Reading a Song" (`#help-reading`, ~line 246)

Add ONE bullet: song pages now show the song's **themes/tags** as tappable chips (#288) linking to
the theme page. (Transpose/chords toggle #299 is already covered at line 258 — verified; do not
duplicate.)

### C1.4 `help.php` — edit "Setlists & Sharing" (`#help-setlists`)

After the "Playing through a setlist" sub-head, add a short "Print or save it" paragraph
cross-referencing the new Printing topic (Print / Save as PDF / Download PDF one-file). The
**Sharing** sub-topic was rewritten for #1791 on 2026-08-11 — verified current; do NOT touch.

### C1.5 `help/exporting.md` — fix the stale claim + additions

- Line 46's parenthetical *"(There's no separate download — your browser's print dialog is where
  'Save as PDF' lives…)"* is now **false**: rewrite for the signed-in **Download PDF** button
  (song dialog + set-list view; whole-set single PDF).
- Append a short "CCLI copy reporting" paragraph (same content as C1.1's bullet).

### C1.6 `help/searching-songs.md` — append a "Sorting your results" section

The md mirror of C1.2 (3-6 lines). Update `help/README.md`'s contents table description for
searching-songs.md to mention sorting. (A separate `help/sorting-lists.md` is NOT needed — folding
into searching keeps the guide count stable; flag as trivially changeable.)

### C1.7 `manage/help.php` — admin help additions (grep-verified absent)

The `$sections` registry (line ~40) + a `<section>` each, mirroring existing tone:

- **`publishers`** (after `musicians`) — `/manage/publishers`: persons + companies, Kind/Role
  vocab, imprint parent, merge/rename-cascade, the songbook editor's multi-publisher picker
  syncing the free-text denorm, the public `/publisher/<slug>` page. Entitlement
  `manage_publishers`.
- **`licence-types`** (after `tiers`) — `/manage/licence-types`: the licence vocabulary
  (`tblLicenceTypes`), LicenceKey immutability, covers, tier conferral, why system rows can't be
  deleted. Entitlement `manage_licence_types`.
- **`gating`** (after `licence-types`) — `/manage/gating`: the family hub + activation-readiness
  checklist; stress that everything stays **dormant behind `content_gating_enabled='0'`**.
  Entitlement `manage_configuration`.
- **`ia-reconcile`** (near `songbooks`) — `/manage/ia-reconcile`: fetches an archive.org item's
  OCR text, segments + scores against a songbook, renders exact/strong/review/GAP report;
  **read-only — never writes song content**; results persist (`tblIaFetchCache`/
  `tblIaImportCandidates`). Entitlement `edit_songs`.
- **`musician-duplicates`** — either its own section after `musicians` or 2 sentences appended to
  the existing `musicians` section (builder's choice; the page exists at
  `/manage/musician-duplicates`, entitlement `manage_musicians`).
- **Extend `print-templates`** (line ~918) — org-scoped templates (`OrgId`), **custom full-page
  HTML layouts** (uploaded, passed through the allowlist sanitiser — say what's stripped), the
  server-PDF path + Download PDF, CCLI print-usage logging + the enforced footer line, template
  clone/JSON import-export/system default (#1767 Z/J — also currently undocumented here).
- **Extend `my-organisations`** — the two new org policies: **Set-list edit links** (No
  preference / Default to signed-in / Require signed-in, #1791 G4-org) and **Live idle-timeout**
  override/enforce + "Members' live sessions" extend-on-behalf (#1770/#1798).
- **Extend `service-mode`** — the "Presentation-app control" driver-key card on Service
  Projection (`service_drive`, mint/list/revoke), and the projection QR now coming from
  `/qr.php` (CueRCode; typed code is the fallback when unconfigured).

Audit: `php -l` both files; run `tests/php/test-fragment-inline-scripts.php` and the full PHP
suite (admin-gate-parity is content-independent but cheap to re-run).

---

## Phase C2 — wiki (commit 2: `docs(wiki): …`)

The wiki lives IN-REPO at `wiki/` (an older ProjectBrief note claiming it needs a separate clone
is stale — ignore it).

### C2.1 `wiki/Database-&-Migrations.md`

The table list is a **curated subset** (40 of 152 tables) + prose notes — match that style, don't
aim for exhaustiveness. Add rows/notes for (all verified in `schema.sql` on this branch):

- `tblPublishers` / `tblSongbookPublishers` / `tblPublisherAliases` / `tblPublisherExternalLinks`
  (#93 — one row for the family is fine; note the `tblSongbooks.Publisher` denorm-mirror rule).
- `tblLicenceTypes` (#1769 P1 — licence vocabulary registry; seeds preserved on un-migrated
  installs via `licence_registry.php`).
- `tblServiceDriverKeys` (#1770 — org-scoped external-driver credentials for `service_drive`).
- `tblPrintTemplateCustomLayout` (+ `tblPrintTemplates.OrgId`) (#1767 remainder P1).
- `tblIaFetchCache` / `tblIaImportCandidates` (#94 Phase 1 — audit bookkeeping, NOT song content).
- Column-family prose notes (mirror the existing "`tblSongs` also gained…" paragraph style):
  - `tblSharedSetlists` + `Scope`/`EditAudience`/`ShowSharerName`/`Label`/`RevokedAt`/
    `ExpiresAt`/`LastUsedAt`/`EditCount` (#1791 share-scope batch).
  - `tblOrganisations` + `SetlistEditAudience`/`EnforceSetlistEditAudience` (#1791 G4-org) and
    `LiveIdleTimeoutMins`/`EnforceIdleTimeout` (#1770).
  - `tblLiveFollowSessions` + `IdleTimeoutMins`/`LastLeaderSeenAt` (#1770/#1798).
  - Gating rights facts: `tblSongs.LyricsRightsLicenceKey`/`MusicRightsLicenceKey`,
    `tblSongbooks.Default{Lyrics,Music}RightsLicenceKey`,
    `tblSongArrangements.MusicRightsLicenceKey`/`MusicRightsStatus`,
    `tblGatingCapabilities.EnforceJson` (#1769 P1 — all dormant until the master switch).
  - Publication metadata (#1765): `tblSongbooks.IsDisabled`/`IsPublicDomain`/
    `OpenLibraryWorkId`/`OpenLibraryEditionId`; `tblSongbookSeries` + `tblSongbooks`-mirroring
    identifier cols (`Isbn`/`Issn`/`ArkId`/`OpenLibrary*`); `tblCatalogues.ArkId`/`OpenLibrary*`.
- Mention the 11 new migration cards by script name (all in `migration-registry.php` with real
  probes): `migrate-publication-metadata`, `migrate-publishers-entity`,
  `migrate-reconcile-credit-name-bytes`, `migrate-musician-duplicates-dismissed`,
  `migrate-add-gating-facts-and-licence-types`, `migrate-derive-rights-facts`,
  `migrate-consolidate-org-licences`, `migrate-live-follow-quick-capable`,
  `migrate-setlist-share-scope`, `migrate-print-template-layouts`, `migrate-ia-reconcile`.

### C2.2 `wiki/API-Reference.md`

The page deliberately does NOT itemise all actions (delegates to OpenAPI) — respect that. Do:

- Page-endpoints table: add `?page=publisher&slug=slug` (mirror the tune row).
- Action-families table: add rows — **Print usage** (`print_usage_context`, `print_usage_log`);
  **Licence types (admin)** (`admin_licence_type_*`); **Service driver**
  (`service_drive`, `service_driver_key_*` — key-authed external control); **Set-list share
  links** (`setlist_share` with `scope: edit`, `setlist_token_update`, `setlist_share_list`,
  `setlist_share_revoke`). Extend the existing **Live Follow** row's examples with
  `live_follow_extend`.
- One-line notes: `search` accepts a multi-level `sort` param (#1786); `user_settings` gained the
  `list_sorts` namespace (403 for anonymous callers, as documented in the spec); the IA-reconcile
  tool exposes **no public API** (admin page-local actions on `/manage/ia-reconcile` only);
  update the "roughly **195** actions" count to "roughly **270**" (275 documented paths).

### C2.3 `wiki/Setlists-&-Arrangements.md`

Add a **"Printing & PDF"** section (the Sharing + Playback sections are current — verified):
Print via the shared #1767 template engine (`83d45a68` — set lists reuse the ONE renderer);
"Save as PDF" (#302); the whole-list single **Download PDF** (#1767 P6, one CCLI copies prompt
for the whole set); screen-only chrome stripped from printouts (#1789).

### C2.4 `wiki/Live-Follow-&-Service-Mode.md`

The #1770 and #1792 sections are current (C8 commit `99e9d13a` + `3e9034bc`) — verify, don't
rewrite. **Add the #1798 slice** (grep-verified absent: no "extend"/"session length" anywhere):
declaring a session length at "Go Live"; the **Extend** control on the LIVE bar; org-admin
"Members' live sessions" extend-on-behalf on `/manage/my-organisations`; the un-migrated-install
degrade message; `live_follow_extend` endpoint name.

### C2.5 `wiki/PWA-Features.md`

Sort bullet is done (`0c03bf0d`). Add bullets: inline **chord charts + transpose** on the song
page (#299); song-page **theme chips** (#288); **Download PDF / print templates** (one line,
cross-ref Setlists page); songbook lists alphabetise **ignoring a leading article** (#150);
offline saved-count now includes deliberately-downloaded songs (#112 — optional, one clause).

### C2.6 `wiki/Architecture.md`

Add short shared-core paragraphs in the existing style (match the #1785 musician paragraph,
2-4 sentences each; cite the plan files rather than duplicating design):

- **Print pipeline** (#1767 remainder): `includes/print_template_schema.php` (ONE block/page-
  option registry), `includes/pdf_renderer.php` (the ONE swappable engine seam — mPDF ~8.3
  vendored at `appWeb/private_html/lib/pdf/vendor/`, outside every docroot; 503-degrades),
  `includes/html_sanitizer.php` (allowlist profiles), `includes/print_usage.php` (the ONE
  CCLI print-usage writer), `includes/print_custom_layout.php`. One-renderer invariant:
  browser print, server PDF, batch set-list PDF and the admin live preview all render through
  the same body renderer (guard `tests/php/test-print-one-renderer.php`).
- **Gating Model-2** (#1769 P2): `includes/access_context.php` (viewer resolved ONCE),
  `includes/access_resolver.php` (every field/media decision), `includes/licence_registry.php`
  (the ONE `tblLicenceTypes` reader) — `contentGatingApply()`/`contentGatingMediaAllowed()` are
  thin delegates; entirely dormant behind `content_gating_enabled='0'`; hub `/manage/gating`.
- **Publisher cores** (#93): `includes/publisher_admin.php` + `publisher_helpers.php` (already
  fully specified in CLAUDE.md rule #37 — one paragraph + pointer).
- **IA reconcile** (#94): `includes/ia_client.php` (SSRF-hardened, host-bound archive.org
  fetcher in the `intapps_client.php`/`cuercode_client.php` mould) + `includes/ia_reconcile.php`
  (pure segmenter/scorer); read-only for song content, CI-enforced
  (`tests/php/test-ia-reconcile-guards.php`).
- **List-sort cores** (#1786): `js/utils/sort-compare.js` (pure comparators, shared with
  `admin-table-sort.js`) + `js/modules/list-sort.js` + `includes/partials/list-sort-control.php`;
  persistence device-local + `user_settings` namespace `list_sorts`.
- **Songbook visibility** (#1765): `includes/songbook_visibility.php` + `SongData` audience mode
  (`forAdmin()`); public reads compose `songVisibleSql AND songServableSql`.
- **MARCXML** (#1765): `includes/marcxml.php` pure/DB-free import+export for the 3 publication
  entities; `manage/includes/marcxml_admin.php` the admin funnel.
- **Service driver keys** (#1770): `includes/service_driver_keys.php`; `service_drive` writes
  through the same `serviceMode_applyBroadcast()` core as `service_broadcast`.

### C2.7 `wiki/Security.md`

Add bullets under "Security model": custom print layouts pass through the **allowlist HTML/CSS
sanitiser** (`includes/html_sanitizer.php`) on save AND the server-PDF path — no scripts, no
event handlers, no external fetches survive; the **server-PDF endpoint**
(`manage/print-pdf.php`) requires an authenticated session (401 JSON, not a redirect), sanitises
the POSTed document server-side, and the GPL engine is vendored **outside every web docroot**;
**set-list edit links** are 256-bit capability URLs — revocable per-link, org-clampable to
signed-in-only, and the server re-resolves the audience on every write (a `401
{reason:'signin_required'}` contract, never trust the client's claim); the **IA fetch client** is
SSRF-hardened (host-bound to archive.org, size-capped, no redirects). Also extend the existing
`validateCsrfRequest()` page list sentence with the #1769 P0 additions (tiers / restrictions /
entitlements pages).

### C2.8 Verify-only

`wiki/Home.md`, `_Sidebar.md`, `User-Accounts-&-Roles.md` (add `manage_publishers` /
`manage_licence_types` only if the page already lists entitlements — a grep found no entitlement
inventory there, so likely no-op), `Development-Setup.md`, `Deployment-&-CI-CD.md` (no deploy
changes on this branch except the lftp resilience commit `cc925a89` — one line in Deployment if
it fits naturally; optional).

---

## Phase C3 — OpenAPI + VERSION bump (commit 3: `docs(api)+chore(version): …`)

### C3.1 `appWeb/public_html/api-docs.yaml`

- `info.version: "0.4100.0"` → **`"1.5000.0"`** (line 5).
- Add **`/api.php?page=publisher`** (mirror the `page=tune` block at line ~11021: `slug` param,
  text/html fragment response, note the exact-slug → name-fold → alias-fold ladder).
- Nothing else is missing (see §0's verified completeness note). Do NOT document the 20 legacy
  editor-api actions in this sweep — file a `for consideration` issue instead ("document or
  retire the legacy editor API surface"), since that surface is slated for v1-editor retirement.

### C3.2 `appWeb/public_html/includes/infoAppVer.php`

- Line 87: `"0.4100.0"` → `"1.5000.0"` (per D1).
- Rewrite the "Manually jumped 0.4001.0 -> 0.4100.0…" comment block (lines ~81-86) to record THIS
  jump: manually jumped `0.4100.0` → `1.5000.0` for the #89/#91 consolidated batch (the 214-commit
  `claude/issue-sweep-fixes-89` branch: #1765/#93/#1769/#1767-remainder/#94/#1770/#1791/#1786/
  #1785 et al.), owner-directed major bump; the alpha auto-bumper note stays.
- **Delete/rewrite the stale line** `/* v1.x.x = Phase 1 (local JSON data), v2.x.x = Phase 2
  (iLyrics dB) */` — that scheme is dead (DB-direct since epic #1010) and now collides with D1.

### C3.3 Lockstep version references (all verified — this is the complete set)

- `appWeb/public_html/manifest.json:8` `"version": "0.1.5"` → `"1.5000.0"` (per D2).
- `service-worker.js.php` — **NO change**: `$swCacheKey` derives from `infoAppVer.php` × commit
  stamp (line 52), so the bump auto-invalidates PWA caches; leave `SW_CACHE_REVISION = '2'`.
- `README.md:5` version badge `0.4001.0 Alpha` → `1.5000.0 Alpha` (doubly stale — it missed the
  0.4100.0 bump) and `README.md:26` `(v0.4001.0)` → `(v1.5000.0)`.
- `PROJECT_STATUS.md:128` `0.4001.0 Alpha` → `1.5000.0 Alpha`.
- `appWeb/CHANGELOG.md:5` "…(through 0.4001.0)…" → "…(through 1.5000.0)…".
- Post-edit check: `grep -rn '0\.4100\.0\|0\.4001\.0' --include='*.php' --include='*.yaml'
  --include='*.json' appWeb/` must return ONLY historical CHANGELOG narrative (root
  `CHANGELOG.md:97`'s bump-record entry and `help.php`'s doc-block history are fine to keep —
  they describe past events).
- `.claude/` version mentions are handled in C5, not here.

---

## Phase C4 — CHANGELOG + top-level markdown (commit 4: `docs(changelog,md): …`)

### C4.1 `CHANGELOG.md` — release header + the three missing families

- Line 1: `## [unreleased] — alpha` → **`## [1.5000.0] — 2026-08-11 (alpha)`**, preceded by a
  fresh `## [unreleased] — alpha` stub (empty) so in-flight work has a home, and followed by a
  3-5 line release intro naming the branch, the commit count, and the headline families.
  ⚠️ The What's-New deploy extraction (`deploy.yml` ~line 341) takes the FIRST sections
  budget-capped — an empty `[unreleased]` stub followed by the release section still extracts the
  release entries; run `node tests/test-whats-new-extraction.js` + `php
  tests/php/test-whats-new-render.php` to prove it.
- **ADD the missing entries** (top of the release section, house style — bold headline, issue
  refs, mechanism, verification note):
  1. **#1767 remainder** (one consolidated entry, sub-bullets per P): P1 schema
     (`tblPrintTemplateCustomLayout` + `tblPrintTemplates.OrgId`, `2ce2daa0`); P2 sanitiser
     (`e0b357bd`); mPDF vendoring (`29ca8a75`) + schema extraction (`a99ee6dc`); P3 server-PDF
     pipeline + `manage/print-pdf.php` + one-renderer guard (`4a080160`/`5402e910`); P4
     Download-PDF affordance + serverOnly H-family page options (`e2c884e9`); P5 CCLI
     print-usage — copies prompt + `print_usage_context`/`_log` + enforced footer (`abd22d79`);
     P7 uploadable full-page custom HTML layouts (`4bd00097`); P6 batch set-list single-PDF
     (`ffada413`).
  2. **#94 IA-reconcile Phase 1**: SSRF client (`e33f09fd`); migration (`9ce5be66`);
     segmenter/scorer/persistence (`7fdaab53`); admin page + nav, `edit_songs` (`0aff2d15`);
     CI guard (`2418eac7`). Stress read-only-for-song-content + the owner decisions D1=A /
     D2=edit_songs.
  3. **#1765 songbook/catalogue epic core** (one consolidated entry): foundations (`b0cdbd27`);
     publication-metadata migration (`d64dd1a7`); disabled-songbook public read sweep
     (`ca6d8120`); admin surfaces + shared form-fields partial + parity guard (`05026c92`);
     Google Books provider (`7992e541`); MARCXML export (`99390d91`) + import (`cbeeeba0`);
     adversarial-review fixes (`0ca1a480`, `4b736005`).
- **Small gaps to patch in existing entries:** append one sentence to the #1791 client entry (or
  a short standalone entry) crediting the server half (`7679d33d` C1 dormant columns, `dc3ea408`
  C2a resolver/write-core, `c5070055` C2b mint/write/list/revoke, `e1f4fb8f`/`341bb4be`/
  `4f7f2522`/`0a9fbb19` G3/G4/G4-org audience wiring); add a one-line entry for gating **P0/P1**
  (`2e9aa850`…`70739838` — currently only referenced from the P2 entry); add the version-bump
  chore entry (`0.4100.0` → `1.5000.0`, cites #91 + D1).

### C4.2 `README.md`

- Features: add bullets — **Print templates & PDF** (templates, Download PDF, whole-set-list PDF,
  CCLI print-usage logging); **Set-list sharing** (view links, revocable edit links with
  per-link audience, org policy); **Publishers registry** (#93 — already partially present via
  the admin table? no: add a Catalogue bullet); **Public list sorting** (#1786); **Live Follow
  session length + extend** (#1798); **Songbook publication metadata + MARCXML** (#1765).
- Admin table row updates: Catalogue cell + `Publishers (/manage/publishers)`; Access cell +
  `Licence Types (/manage/licence-types) · Gating hub (/manage/gating)`; Songs cell +
  `IA Reconcile (/manage/ia-reconcile)` (or an Operations placement — match where
  `admin-links.php` actually puts it; check at build time).
- (Version badge/table already done in C3.)

### C4.3 `PROJECT_STATUS.md`

Extend "### 2026-08 highlights" (currently only #1785) with one bullet per family from §0's
table — #1765+#93, #1769/#1778, #1767 remainder, #94, #1770/#1792/#1798, #1791/#1790/#1789,
#1786, #1783, #1788, QR/CueRCode, the #89 sweep line. Refresh "Next Milestones" if stale.

### C4.4 `DEV_NOTES.md`

Add reuse-contract sections in the existing "#1785" style (short — name the cores + "never
re-fork", point at the plan file): print pipeline cores; gating Model-2 cores; publisher cores;
IA cores; `sort-compare.js`/list-sort; the setlist share-scope resolver + write core
(`setlistShare*` family in `api.php`/includes — verify exact names at build time from
`dc3ea408`).

### C4.5 `SECURITY.md`

Mirror C2.7's four bullets (sanitiser; server-PDF; capability edit-links; IA SSRF client) into
the "Security model" section — the repo file and the wiki Security page should say the same
thing in the same pass.

### C4.6 `LICENSING.md` — VERIFY ONLY

The mPDF GPL-2.0 entry + rationale block landed with the vendoring (`29ca8a75`) — confirm
present (it is, at line ~46) and that the vendored path named
(`appWeb/private_html/lib/pdf/vendor/`) still matches the tree. No edit expected.

---

## Phase C5 — `.claude` context + HANDOFF (commit 5: `docs(claude): …`)

### C5.1 `.claude/ProjectBrief.md`

Append ONE consolidated continuation note (the file's convention is append-and-supersede, never
rewrite history):

- **Supersede the "NOT YET PUSHED" claims** in the two 2026-08-11 notes (lines 7, 54) — the
  branch IS pushed and up-to-date with origin.
- Record the sweep: version **1.5000.0** (correcting the stale `0.4001.0` claims at lines
  522/617 by supersession), the §0 family list with plan-file pointers, and that #91's docs
  reconciliation is DONE (help/wiki/OpenAPI/md/.claude).
- **Header-fact refresh** (the 2026-06-21 "fact-refresh" precedent): `schema.sql` now has
  **152** `CREATE TABLE` statements (was "~131"); the API surface is **~294 dispatched actions /
  275 documented OpenAPI paths across 4 dispatchers** (was "70+"); 11 new migration cards
  pending on the environments (operator runs them via `/manage/setup-database`).

### C5.2 `.claude/MEMORY.md`

"Where things stand" refresh (last updated 2026-07-30): version `1.5000.0` + repeat the
"version-bump.yml fires only on beta — expect manual bumps" warning with this jump as the second
worked example; the branch/PR state; one line per major family; new gotchas worth memory:
`manage/print-pdf.php` is auth-gated but deliberately NOT entitlement-gated (its doc-block says
why); the wiki is in-repo at `wiki/`; the 20 legacy editor-api actions are knowingly
undocumented.

### C5.3 `.claude/CLAUDE.md` — proposed new rules (per D3)

**Rule #39 (draft):**

> **39. Song/set-list printing renders through ONE renderer, and PDFs are made server-side by the
> swappable mPDF seam — dormant-degrading, sanitised, GPL-quarantined** (#1767 remainder, plan
> `.claude/print-templates-1767-remainder-plan.md`). The block/page-option vocabulary lives ONCE
> in `includes/print_template_schema.php` (mirrored client-side; agreement CI-guarded by
> `test-print-block-registry.php`); browser Print, the server PDF, the batch set-list PDF and the
> admin live preview all flow through the SAME body renderer — never a second renderer
> (`test-print-one-renderer.php` bans it). Server PDFs come from `manage/print-pdf.php` →
> `includes/pdf_renderer.php`, the ONE engine seam (mPDF ~8.3, **vendored under
> `appWeb/private_html/lib/pdf/` outside every docroot** — GPL-2.0, see LICENSING.md; a Chromium
> service is the documented later swap). The endpoint is session-auth-gated (401 JSON, no
> redirect), and clients feature-detect it (`?ping=1`) — anonymous users keep plain Print, and a
> missing/broken engine degrades to the browser path, never an error page. EVERY HTML that
> reaches the renderer — including uploaded full-page custom layouts
> (`tblPrintTemplateCustomLayout`) — passes `includes/html_sanitizer.php`'s allowlist profiles on
> save AND at render; never render user HTML unsanitised, never let the client claim the CCLI
> copies answer (`includes/print_usage.php` re-resolves the licence server-side and is the ONE
> usage writer).

**Rule #40 (draft):**

> **40. Set-list sharing is capability-URL-scoped with a server-resolved edit audience — the
> mint response is the truth, not the request** (#1791, plan
> `.claude/setlist-sharing-1790-1791-plan.md`). A share link's power lives in the token row
> (`tblSharedSetlists.Scope` `view|edit`, `EditAudience`, `ShowSharerName`, `RevokedAt`, …), not
> in who holds the URL: edit links are 256-bit capability tokens minted only by the owner
> (`setlist_share` with `scope:'edit'`), written through the ONE scope-aware resolver + write
> core that `setlist_token_update` uses, revocable per-link (`setlist_share_revoke`), and
> listable (`setlist_share_list`). The edit audience resolves per-WRITE through the three-layer
> owner-choice → org-default → org-enforce ladder — an org may clamp "anyone" to
> "signed-in required", so the client MUST read back what the server stored (rule #35) and treat
> `401 {reason:'signin_required'}` as a sign-in prompt, not an error. Never gate a shared-page
> write on the client's claimed audience, never mint tokens outside the share core, and never
> add a share capability as a new endpoint when a `Scope`/audience value on the existing row
> does it (rule #20's vocabulary discipline applied to tokens).

Also: **patch rule #28** with one sentence naming the #1769 P2 files (the resolver now lives in
`includes/access_context.php` + `access_resolver.php` + `licence_registry.php`;
`contentGatingApply()` is a thin delegate) so the rule keeps pointing at the real seam. Add TWO
red-flag bullets mirroring #39/#40 (second renderer / unsanitised print HTML; out-of-core token
mint / client-trusted audience).

### C5.4 `.claude/project-rules.md`

Add short notes (not CLAUDE.md rules, per D3): the public list-sort persistence model (#1786 —
spec normalisation "a saved layout is a wish, not a contract"; `list_sorts` namespace; never a
new endpoint for a per-user preference the `user_settings` namespaces already carry) and the
outbound-HTTP client house pattern (now 3 instances: `intapps_client.php`, `cuercode_client.php`,
`ia_client.php` — SSRF-hardened host-bound URL, size-capped aborting write-callback, no
redirects, fail-open null, dormant-until-keyed where applicable; any 4th outbound integration
copies this shape).

### C5.5 `.claude/sessions/2026-08-11-HANDOFF.md`

Fresh handoff: branch state (214 commits, pushed, PR pending per the one-PR rule); the sweep's
5 commits; **operator actions** — run the 11 pending migration cards on each env via
`/manage/setup-database` (list them, from C2.1); paste a CueRCode API key on
`/manage/configuration` (QR dormant until then); `content_gating_enabled` stays `'0'`;
the genuinely-outstanding items (live two-device verify #1339/#1792; #1797 shim is a spike only;
legacy editor-API documentation issue from C3.1; add-song on #1791 edit surfaces deferred by
plan). Note anything from this sweep that could NOT be completed, explicitly.

### C5.6 GitHub bookkeeping (with the C5 commit, per standing-tasks §2)

Close/update #91 with the commit SHAs; file the ONE new issue from C3.1 (legacy editor-API
surface: document or retire — `for consideration`); verify #1767/#94/#1765/#1791 issues carry
their as-built comments (builders largely did this — verify, don't duplicate).

---

## §6 — Guards + consistency (rule #34 discipline)

**Must stay green after every phase** (run the full suites; these are the ones this sweep can
plausibly break):

- `php tests/php/test-fragment-inline-scripts.php` — C1's `help.php` edits (fragment; no inline
  `<script>`, ld+json exempt).
- `php tests/php/test-openapi-actions-exist.php` — C3's yaml edit (the new `page=publisher` path
  is a `?page=` path, outside its `?action=` regex — verify it stays 0-phantom).
- `node tests/test-whats-new-extraction.js` + `php tests/php/test-whats-new-render.php` — C4's
  CHANGELOG head restructure (budgets + first-sections extraction).
- `php -l` on every touched `.php`; the full PHP + node suites once at the end (153 PHP / 56
  node at branch tip).

**New guard? Recommendation: NO broad "docs coverage" guard** — "is the wiki current?" is not
tree-derivable, and a hand-listed check would be exactly the wrong-but-green scanner rule #34
bans. ONE narrow, derivable, mutation-provable extension IS worth offering (optional, in C3 or
as the follow-up issue): extend `test-openapi-actions-exist.php` with the **reverse direction**
(every dispatched action must be documented) over the SAME `dispatch_parser.php` output, with an
explicit per-entry-reasoned `$UNDOCUMENTED_LEGACY` allowlist of the 20 editor-api actions
(mirroring its existing `$EXEMPT` honesty check so a retired action must leave the list). Prove
it can fail both ways: delete a documented path → red; add a fake `case 'zz_test':` → red;
restore → green. If not done in-sweep, fold it into the C3.1 issue.

---

## §7 — Commit plan summary

| Commit | Scope | Files |
|---|---|---|
| **C1** `docs(help)` | public + admin in-app help | `includes/pages/help.php`, `help/exporting.md`, `help/searching-songs.md`, `help/README.md`, `manage/help.php` |
| **C2** `docs(wiki)` | 7 wiki pages | `wiki/Database-&-Migrations.md`, `API-Reference.md`, `Setlists-&-Arrangements.md`, `Live-Follow-&-Service-Mode.md`, `PWA-Features.md`, `Architecture.md`, `Security.md` |
| **C3** `docs(api) + chore(version)` | OpenAPI + all version strings | `api-docs.yaml`, `includes/infoAppVer.php`, `manifest.json`, `README.md` (badge+table), `PROJECT_STATUS.md` (version line), `appWeb/CHANGELOG.md` (line 5) |
| **C4** `docs(changelog,md)` | release section + top-level md | `CHANGELOG.md`, `README.md` (features/admin table), `PROJECT_STATUS.md` (highlights), `DEV_NOTES.md`, `SECURITY.md`, `LICENSING.md` (verify-only), `appWeb/CHANGELOG.md` |
| **C5** `docs(claude)` | context + handoff (+ optional rule patch) | `.claude/ProjectBrief.md`, `MEMORY.md`, `CLAUDE.md`, `project-rules.md`, `sessions/2026-08-11-HANDOFF.md` |

Single PR (this branch's one-PR rule); each commit atomic + revertable. C4 depends on C3's
version string; C1/C2 are order-independent. Nothing in this sweep touches runtime behaviour
except the version bump (which auto-rolls the PWA cache key — that is the point).
