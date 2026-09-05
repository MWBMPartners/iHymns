# 📊 iHymns — Project Status

> **Quick reference for current project state**

---

## 🚦 Overall Status: 🟢 In Progress

| Area | Status | Notes |
| --- | --- | --- |
| 📋 Project Plan | ✅ Complete | See [Project_Plan.md](Project_Plan.md) |
| 🗂 Project Structure | ✅ Complete | Directories, .gitignore, deployment structure |
| 📖 Help Documentation | ✅ Complete | 15 guides in `help/` + in-app help (25 public topics, 49 admin sections) |
| 🎫 GitHub Issues | 🟢 Active | Highest issue now #2087 — see GitHub for live open/closed counts |
| 🔧 Song Data | ✅ Active | ~14,000 songs across 30+ songbooks (live count in `tblSongs` — query the DB, don't trust this file); served **live from MySQL** (DB-direct #1010; the static cache was decommissioned #1020) |
| 🌐 Web PWA | ✅ Core + Enhanced | Live MySQL search (no client-side corpus — #1014/#1015), songbooks, lyrics, favourites, themes (Light/Dark/High-contrast/CVD/System #956), deep linking, WCAG 2.1 AA, offline support |
| 🛠 Song Editor | ✅ Complete | `appWeb/public_html/manage/editor/` — **v2 (granular, per-edit) is now the default** (#1601 scope item 2), 302-redirected from the legacy route; the legacy v1 editor is not retired and stays reachable via `?legacy=1`. v2 has a chords box, an Arrangement (running-order) editor, and per-line translation/annotation panels; bulk import (ZIP / VideoPsalm / OpenSong / FreeShow / EasyWorship / iHymns JSON #1633), media uploads, per-component language overrides |
| 🛠 Admin Portal | ✅ Active | 48 nav-registered admin destinations under `/manage/*`, organised as Dashboard + 7 groups (Songs / Song Library / Live Services / People / Access & Permissions / System & Reports / Help — the #1822 pass split Live Services out of People and gave every group a plain-English name). People hosts the org-scoped My CCLI Report (#1861); Songs hosts the unified Find Duplicates page (#1215, absorbed the old song-link-suggestions); Song Library gained the Tunes registry (#1748); System & Reports gained the outbound Webhooks surface (#1909) |
| 🚀 CI/CD Pipeline | ✅ Complete | 15 workflows: deploy, changelog, release, test, lint, apple, apple-deploy, apple-dmg, auto-merge-alpha, build-android, dependabot-security-backport, language-registry-refresh, maintenance-ha-integrity-audit, maintenance-issues-sweep, promotion-deploy-bridge. Versioning is now **tag-free** (#1963/#1965, extended with the marketing-version/build-number split): `deploy.yml` itself classifies + bumps the committed `MAJOR.MINOR.PATCH` anchor on `alpha` (a `Release: patch` merge footer mints a deliberate patch release; the always-climbing build number is a separate field, injected on every deploy); `release.yml` is dormant (manual-tag-only) and `promotion-deploy-bridge.yml` is back to being just the beta/main deploy bridge, no longer a tag minter |
| 🍎 Apple App | 🟡 Consolidated, unreleased | Phase 1 + Phase 2 code-complete (iHymnsKit SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents); consolidated and CI-compiled but unreleased; device matrices and APNs provisioning owner-gated |
| 🤖 Android App | 🟡 Scaffold / in progress | Kotlin / Jetpack Compose — ~12 Kotlin files; scaffold, not yet feature-complete |

> **Recently merged (v1.0.0 → v1.3.0):** the `claude/ilyrics-identity-work-model` branch merged to `alpha` as **v1.0.0** (#1937), carrying the whole iLyrics identity / Work-model epic (#1860), medley composition (#1907), org-logo screen surfaces (#1840), print templates (#1767), the gating model review (#1590 / #1769), the live `/search` quick-jump typeahead (#1936), full songbook names in list rows (#1531), theme-aware admin surfaces (#1713), field-level revision blame + per-field revert (#1122), outbound partner webhooks (#1909), the searchable `/themes` A–Z index (#1148), the account-security pack (#1027 / #947 / #340), the Wave 3 perf pack (#1920 / #1921 / #1571), the set-list sync-correctness cluster (#1662 / #1675 / #1660 / #1802), and PublicId parity + CI hygiene (#1744 / #1891 / #1892). A retrospective **v1.1.0** bump then covered the dormant-features audit (#1955), tag-free versioning (#1963/#1965), the ProPresenter 7+ interoperability epic (#1968 — import/export of `.pro`/`.probundle`/`.proplaylist`, media embedding, chord round-trip, dormant timeline groundwork), multi-licence organisations (#1969), and signed-in device auto-naming + rename (#1975). **v1.3.0** completed the guided-wizard program to eight wizards ("Connect a service", "Guided environment setup", "Turn on content locking"), split the marketing version from the always-climbing build number with a deliberate `Release: patch` mechanism, and closed out the first half of a whole-codebase security + accessibility audit. Most recently, a further same-day session hardened the dynamic sitemap, added an admin-controllable per-channel search-engine visibility switch, fixed three runtime correctness bugs found by a dedicated bug hunt, and completed the second, deeper half of the security + accessibility audit (epic #2027, WCAG 2.1 AA) — landing as a genuine `feat:` (the search-visibility switch). This merged (PR #2048) and deployed to `alpha` as **v1.3.0** — the branch's own committed 1.3.0 was already the version anchor, so the auto-classifier had no commits after it to bump; the owner accepted 1.3.0 (a clean v1.1.0 → v1.3.0 minor jump carrying all the work). See the highlights sections below for detail.

---

## 📌 Completed Milestones

### Milestone 1: Project Setup & Data Pipeline ✅

Project structure, .gitignore, help docs, GitHub Issues, package.json, song parser, songs.json seed (retired entirely, #1617 — runtime reads are live MySQL, #1010).

### Milestone 2: Web PWA Core ✅

Layout, songbook browser, song detail, search (Fuse.js), responsive design, dark mode, favourites, PWA.

### Milestone 3: Web PWA Enhanced ✅

Deep linking (.htaccess), accessibility (WCAG 2.1 AA), in-app help, colourblind-friendly mode, numpad search, iLyrics dB colour scheme alignment, print stylesheet.

### Milestone 6: Song Editor ✅

Web-based admin tool at `/manage/editor/`: metadata, structure/arrangement, writers/composers, CCLI / ISWC, JSON validation/save, bulk import/export, preview, accompanying media uploads (audio / sheet music / MIDI / MusicXML).

### Infrastructure ✅

15 GitHub Actions workflows: SFTP deployment (`deploy.yml`, which now also classifies + bumps the tag-free version anchor on alpha, #1963/#1965), the beta/main deploy bridge (`promotion-deploy-bridge.yml`), changelog generation, a now-dormant manual-tag-only GitHub Releases workflow, CI lint/test, workflow-YAML lint, Apple CI/deploy/DMG, alpha auto-merge, Android build, Dependabot security-fix backport to the release branches, the monthly BCP 47 language-registry refresh, and the two monthly maintenance sweeps. (The old minor-auto-bumping `version-bump.yml` was retired at #1899; the tag-derived scheme #1899 introduced was itself retired in favour of the tag-free scheme at #1963/#1965.)

### 2026-05 catalogue & platform work ✅ (highlights)

- **Works composition grouping** (#840) — `tblWorks` self-FK nesting + `tblWorkSongs`; public `/work/<slug>` page; admin CRUD at `/manage/works`.
- **External-links registry** (#833 / #845) — MusicBrainz-style `tblExternalLinkTypes` + per-entity `tblXxxExternalLinks`; provider patterns in `tblExternalLinkPatterns`; auto-detect URL → provider via `js/modules/external-link-detect.js`; curator CRUD at `/manage/external-link-types`.
- **Activity-log resilience** (#917–#931) — per-request rows, IPv6/proxy/VPN resolution, every PHP fatal mirrored, defensive `bindParamSafe()` helper.
- **Real email delivery** (#922) — magic-link, password reset, register, admin force-reset (closes #898 P0/security).
- **Credit-people structured-name split** (#935) — `FirstNames` / `Surname` / `Suffix` alongside canonical `Name`.
- **Quick-wins batch** (#948) — clickable Tune / CCLI / ISWC + Translations section; Catalogues many-to-many grouping (#941); Works ISWC backfill (#942); various UX/bug fixes.
- **Centralised link styling** (#952) — kills Bootstrap default `<a>` blue + underline site-wide; `.song-meta-link` muted convention everywhere.

### 2026-06 data-layer & worship program ✅ (highlights)

- **DB-direct read layer** (epic #1010, WS-A–WS-K) — song reads now go **live to MySQL**; the whole-corpus `songs.json` cache / `songs_json` endpoint were removed (WS-J #1020). Scoped reads only: `?action=songs_index` (slim index), editor `?action=songbook_export` (one book), `?action=song_detail` (one record). A DB outage returns a themed **503** (WS-K #1021), never stale JSON. `songs.json` is retired entirely (#1617) — there is no tracked corpus file of any kind any more.
- **Lyrics normalisation** (#1235) — `tblLyricLines` is the **source of truth** for lyric lines (one shared read path `includes/lyric_lines_read.php`, one write path `lyricLinesWriteComponents()`); the `tblSongComponents` `LinesJson`/`ChordsJson`/`NotesJson` columns are a gated shadow being retired.
- **Duplicate & counterpart detection** (#1215 / #1216) — unified `/manage/duplicate-songs` (absorbed the old `/manage/song-link-suggestions`, now a 302 redirect); shared scorer `includes/song_similarity.php`.
- **Standard theme vocabulary** (#1152 / #1222) — CCLI / OpenLyrics theme taxonomy seeded into `tblSongTags`; curator canonicalisation on `/manage/tags`.
- **Service Mode — congregation Live-Follow** (#1323 / #1335) — org venues + recurring schedules, rotating-code join, anonymous presence, the two broadcaster UIs (`/manage/service-projection` + `/manage/service-lead`), dormant CCLI content gate. Currently dormant behind `content_gating_enabled='0'`.
- **Songbook DisplayAbbr** (#1332) — optional display-only label distinct from the SongId-prefix `Abbreviation`; **Catalogues** are user-labelled "Collections" (#1223, internal name stays `catalogue`); unofficial-songbook badging (#1223).
- **API gating + enforcement + rate limiting** (#1352 / #1353 / #1354) — content gating is now **server-enforced** (`includes/content_gating.php` strips gated fields from the API by tier cap) on an **extensible one-line capability registry** (`TIER_CAPS` + JSON-backed caps, no schema change); **dormant** until `content_gating_enabled='1'`. The heaviest public reads carry a per-requester (token-or-IP) windowed **rate limit** (`429` + `Retry-After`, fail-open, dormant until migrated). CSRF hardened to a robust same-origin `validateCsrfRequest()` (ends the sporadic stale-token errors on merge/delete/edits); `save_song` moved to the shared v2 editor API core under its X-Requested-With gate.

### 2026-07 highlights ✅

- **Public Export fixed** (#1565–#1570) — the enforcing nonce CSP silently killed the SPA fragment's inline `<script>` wiring, breaking the public Export ▾ menu (all 8 formats, both surfaces) and the Present button for about 7 weeks with no visible failure. Fixed by wiring `export-ui.js` as a real ES module from `router.js`'s `afterPageLoad()`; new CI guard `tests/php/test-fragment-inline-scripts.php` bans executable inline `<script>` in any page/partial fragment going forward.
- **Live Follow & Service Mode documented** (#1577) — the two features share one DB table but are functionally distinct (any signed-in user vs. venue/org-based); previously-conflated docs made Live Follow look permanently broken. New `help/live-follow.md`, `wiki/Live-Follow-&-Service-Mode.md`, and an Apple HelpView section.
- **Observability batch** (#1581 / #1582 / #1583) — event names unified behind `js/constants.js` with a CI literal-ban guard; uncaught client errors surface one toast + a throttled, scrubbed beacon into the Activity Log; a `/whats-new` page extracts the top CHANGELOG sections on every deploy.
- **Deploy media guard** (#1584) — `data/audio/` and `data/music/` excluded from the docroot mirror; every prior deploy had been silently wiping uploaded/downloadable song media.
- **Apple branch consolidation** — Phase 1 + Phase 2 Apple work (watch relay, tvOS projector, Live Activities, App Intents) merged into the single active branch; CI-compiled, still unreleased.
- **Eight pre-gating security fixes** (#1388) — media-byte gating (`contentGatingMediaAllowed()`) for `/song-media/<id>` and the `bulk_audio` manifest; `songbook_export` now strips gated fields per song via `contentGatingApply()`; Service-Mode presence CCLI unlock requires a live heartbeat, not just `IsActive`; first-admin registration race closed with a transaction + row lock on both registration paths; logout clears per-user service-worker caches; `validateCsrfRequest()` no longer accepts `X-Requested-With` alone with no `Origin`/`Referer` at all. Everything is a verified no-op while `content_gating_enabled='0'`.
- **Shared API client, fetch monkey-patch deleted** (#1031) — new `js/utils/api-client.js` (`apiFetch`/`apiFetchJson`) replaces the site-wide `window.fetch` override that `songbook-language-filter.js` installed; fixes an anonymous user's saved language filter being silently ignored on a cold `/search` load.
- **Setlist playback mode** (#1533) — tap a song in an own or shared setlist to arm a floating prev/next nav bar with keyboard navigation and an aria-live announcement; fixes shared setlists being unnavigable. Alongside a Revisions Audit "Open in editor" link fix (#1623).
- **Dead code + doc-accuracy cleanup** (#1612, #1615, #1618) — removed the unused `js/utils/transpose.js` (and its stale service-worker precache entry); corrected the lyrics-cutover verifier's gate-count claim from "10/13" to the actual nine implemented gates, tracking the real gap as #1618.
- **Song Editor v2 becomes the default** (#1601 scope item 2) — `/manage/editor/` now 302-redirects to the granular, per-edit v2 editor; the legacy whole-song editor remains available via `?legacy=1` and is deliberately not yet retired. Shipped once every parity gap found along the way closed: a chords box, an Arrangement (running-order) editor, and per-line translation/annotation panels (#1627); `?tab=` / `?songbook=` / `#number=` / `?open=` deep links, the sidebar songbook filter + sort, `bulk_tag_detach`, and the export lines-per-slide setting (#1628, #1680); and a P0 fix (#1677) for a bug that had made every v2 write return 403 since the shell first shipped.
- **Setlist collaboration finished** (#1638) — invited collaborators are now notified, see shared setlists under "Shared with me," and their view/edit permission is actually enforced (it had shipped write-only and decorative).
- **Cross-device sync data-loss fixes** (#1649) — capped per-user syncs (set lists / favourites / custom tags) no longer silently delete rows that were only dropped by the cap; a new sync watermark stops an older device from deleting another device's newer, unseen writes.
- **Accessibility + security sweep** (#1643–#1648, #1665) — high-contrast/CVD modes restored across the whole `/manage` admin surface (they had never been styled there at all); Present mode is a real focus-trapping dialog; Service Mode announces section changes and no longer races the page render; sortable table headers keep their `columnheader` role; SPA navigation stopped reading whole pages aloud on every route change; the setlist Arrangement editor works by keyboard and touch; the SortableJS and Bootstrap CDN loads gained SRI + vendored fallbacks; eight admin pages' access gates now match what the nav actually advertises.
- **iHymns interchange JSON importer** (#1633) — a new additive/merge-only importer writes iHymns's own JSON export format straight to the database, following the same never-truncate contract as the ZIP importer.

### 2026-08 highlights ✅ — the `claude/issue-sweep-fixes-89` batch (v0.5050.0)

The consolidated 214-commit branch (one PR, `#89`/`#91`). Version bumped **0.4100.0 → 0.5050.0**
(owner-directed). All new schema is additive/dormant; content gating stays off by default.

- **Musician registry-vs-registry duplicate detection + easier merge UX** (#1785, follow-up to #1784, epic #1787) — a new live-computed scan (`includes/musician_duplicates.php`) finds registry rows that are probably the same person, blocked (not naive all-pairs) so it stays sub-second at thousands of rows; a new `/manage/musician-duplicates` review page (mirroring `/manage/duplicate-songs`, #1215) offers one-click merge, dismiss/undismiss, and a lifespan-conflict guard on the dangerous class of merge; every merge affordance across the app (the Merge modal, bulk-promote, the new page) now shows WHY two similar names look alike and WHICH registry row is which — closing the "which is merging into which?" confusion the #1784 fix surfaced. The merge core is now one shared function (`musicianMergeExecute()`), closing two data-loss bugs found during its extraction (a stranded sixth credit table; silently cascade-deleted aliases/relations on merge). Plus **#1800/#1799** merge/dedup follow-ups and the admin sortable-headers adoption sweep.
- **Songbook/catalogue enhancements epic + Publishers registry** (#1765 / #93) — songbook disable + public-domain flags, ARK/OpenLibrary/ISBN/ISSN identifiers, a Google Books external-link provider, MARCXML import/export, and the free-text publisher promoted to a first-class `tblPublishers` registry (persons + companies, imprint grouping, aliases, public `/publisher/<slug>` page).
- **Content-gating program (P0–P6) + hub** (#1769 / #1778) — facts × grants × one viewer resolver × one enforcement pipeline (`access_context.php` / `access_resolver.php` / `licence_registry.php`), a `tblLicenceTypes` vocabulary registry with `/manage/licence-types` CRUD, and a `/manage/gating` readiness hub. **Entirely dormant** behind `content_gating_enabled='0'`, proven byte-identical no-op.
- **Print templates / server-PDF remainder** (#1767) — a shared print-template engine (browser Print, server PDF, whole-set-list PDF, admin preview all one renderer), an allowlist HTML sanitiser, vendored mPDF (outside every docroot), a signed-in Download PDF affordance, uploadable custom layouts, and CCLI print-usage logging.
- **IA-reconcile Phase 1** (#94) — a read-only archive.org OCR audit tool (SSRF-hardened fetcher + pure segmenter/scorer) that scores a scan against a songbook and reports the gap list; never writes song content.
- **Live Follow work** (#1770 / #1792 / #1798) — a persistent host bar, leader-idle auto-close, host-CCLI unlock, an external presentation-app driver (`service_drive` + driver keys), a cross-channel error message, and declared session length + live/on-behalf **Extend**.
- **Set-list share-by-link** (#1790 / #1791 / #1789) — a playlist-first view link and a revocable, org-clampable edit-capability link; shared set lists print through the one template engine.
- **Public multi-level list sort** (#1786) — a Sort ▾ control on every catalogue list, up to 3 levels, per-surface device memory + account sync via the `user_settings` `list_sorts` namespace.
- **Editor duplicate-song** (#1783), **ProPresenter CSP-safe export** (#1788), **QR → CueRCode** (`/qr.php`, owner directive), and the **#89 sweep** items (#288 song-page tags, #150 article-blind sort, #299 inline chords, #302 set-list Save-as-PDF, #112 offline count, et al.).

### 2026-08 (mid) highlights ✅ — v0.5160.0 → v0.5250.0 → `claude/ilyrics-identity-work-model`

- **#1853 batch** (v0.5200.0) — a musician-profile migration false-negative fixed so `/manage/setup-database`'s "Apply all pending" stops retrying an already-superseded card (#1824); the v2 editor's Credits-tab autosave no longer mints orphaned `tblMusicians` rows on every keystroke (#1843); a responsive v2 shell for phones/tablets (#1845); the restored IETF BCP 47 live-search Language picker (#1849); a manual **Save** button that flushes pending autosaves (#1846); single-line songbook-coloured sidebar rows (#1850); a CSP-safe CDN→`/vendor` stylesheet fallback (#1832); a 10-fix editor/shell hardening pass (#1851); and Microsoft Clarity session recording no longer loads under Do-Not-Track (#1852).
- **#1859 P0 transport-routing fix** (v0.5250.0) — every browser POST/PUT/PATCH/DELETE aimed at a literal `/manage/**.php` URL had been silently losing its request body for months (a 301 cosmetic-URL redirect downgrades a write to a body-less GET per RFC 7231 §6.4), breaking the v2 editor's metadata saves, the whole Arrangement editor, server-PDF download, bulk import and more — masquerading as unrelated client bugs (#1846/#1847/#1851) because reads were unaffected. Fixed at both layers (the redirect now exempts write methods; every browser call site uses the extensionless URL), with a new mutation-proven guard banning any browser request to a literal `/manage/**.php` path.
- **#1860 — permanent internal ids (ILIDs) + Work identity** — every catalogue entity now mints a grammar-disjoint permanent internal id (`IL<letter><digits>`) on create, dual-addressed alongside its public id across every read path; Works now auto-link on song save via one fail-safe wrapper. Groundwork for the future iLyricsDB merge.
- **#1861 — org-scoped CCLI usage report** — a self-serve `/manage/my-ccli-report` alongside the existing system-wide report, plus an organisation-filter on the system-wide one and a fix for usage under-attributed away from an org's own licence.
- **#1862 — Song Editor metadata derivation** (epic #1863) — the copyright display line, a public-domain suggestion, and the audio/sheet-music availability line all now derive themselves from what the catalogue already knows, replacing three things a curator used to have to fill in or tick by hand.
- **#1863 picker rollout (#1864–#1869)** — six more registry-referencing fields across Works, Songbooks, Collections, Song Requests, User Groups and the Song Editor's Structure tab became find-or-create search-select pickers instead of free text, closing the "typo mints a duplicate registry row" failure mode app-wide.
- **Build-number CI** — a monotonic per-commit build number is now injected into `infoAppVer.php` at deploy, alongside the existing SHA/date injection.
- **`api-docs.yaml` sync + two new CI guards** — the ILID dual-addressing behaviour, the canonical `?page=musician` path, and the five `iswc`-sibling identifier pages are now documented; a version-lockstep guard and an admin-nav↔Help-coverage guard (both tree-derived, mutation-proven) close the exact class of gap that let this branch's new admin pages ship without in-app help.

### 2026-08 (late) highlights ✅ — tag-derived v1.0.0 batch (through #1906)

- **Tag-derived versioning** (#1899) — the version scheme moved to `MAJOR.RELEASE.BUILD` with the baseline reset to **v1.0.0**: MAJOR is hand-edited (rare), RELEASE is automated at the beta→main promotion by `promotion-deploy-bridge.yml`, and BUILD is the per-commit git commit count. The old minor-auto-bumping `version-bump.yml` (which had ballooned the minor to 5250) is retired; `release.yml` + `promotion-deploy-bridge.yml` are the tag/release pipeline that replaced it.
- **Security-hardening pass** (#1905 / #1906) — a made-up path (a `/wp-admin/` scanner probe, or any unknown URL) now returns a real **404** instead of a soft HTTP-200 app shell, with the valid-route list **derived** from the app's own pages and CI-guarded in lockstep with the client router (#1905). Registration + email-code brute-force protections now actually engage (the registration throttle was dead code; the email-code check gained a per-email bucket alongside the per-IP one), a session-fixation gap on cross-surface admin sign-in is closed, the `/manage` admin area and the social-card (`og-image.php`) endpoint gained security headers/CSP, copyrighted lyrics no longer leak via the share-image endpoint when content-locking is on, several heavy public endpoints gained rate limits, and `X-Powered-By` now advertises our own `iHymns/<version>` identity while the PHP runtime version is suppressed at source (`expose_php=Off`) (#1906). Entirely defensive; no user-visible change.
- **Bulk-import rights passthrough** (#1673 / #1896) — bulk imports now keep the copyright line, CCLI number, ISWC and public-domain flags the source file provides (they were silently blanked for every format), fixing a CCLI-report undercount of imported songs and letting imported songs auto-link to their Work by identifier (writers/composers credits remain a follow-up, #1904).
- **CCLI write-coverage widening** (#1897) — Service-Mode usage logging and the org-scoped #1861 report now include SetlistId, so multi-song set-list metrics are captured and projected uses surface in the report.
- **Accent- & apostrophe-folded search** (#1039) — song / songwriter / tune / place search folds accents and smart apostrophes to base characters ("Café" matches "cafe", "don't" matches "dont"), online and in the offline cache.
- **Shared live set-list expiry** (#1699) — a shared **live** set-list link now stops serving once the owner's per-set-list expiry passes (previously it honoured only the link's own expiry), returning "no longer shared" without deleting any data.
- **Org-admin Service Mode nav** (#1667) — organisation admins now see the Service Mode links (Projector Screen, Lead a Service) in the admin menu; they were always allowed to use them, only the menu visibility was gated too broadly (nav↔gate parity).
- **Signed-in sync notice fix** (#1710) — a signed-in user is no longer wrongly told to "Sign in to sync…" on Settings; `api.php` now resolves the current user for non-cacheable fragments while cacheable fragments stay un-personalised for shared-cache safety.

### 2026-08 (late) highlights ✅ — #1860 Phase 5: medley composition (#1907)

- **Medley composition + custom component names** (#1860 Phase 5 / #1907) — the dormant #1860 work-identity schema is now wired (new `tblSongComponents.Label` column + `tblWorkComponents`). A curator can give any song section a custom display name ("Kyrie", or one-click its language name) instead of the automatic "Verse 1 / Chorus" — display-only, so the section *type* still drives chorus highlighting, slide grouping and every export format's structural keyword. A Work can now be defined as an ordered list of constituent Works via a "Constituent works (medley)" editor on `/manage/works`, so the song page and `/work/<slug>` show a read-only "Medley of: A, B, C" line, and each section can point at the Work it excerpts. One shared medley core (`workMedley*()` in `includes/work_admin.php`); tree-derived guard `tests/test-component-label-sites.js`.

### 2026-08 (late) highlights ✅ — Wave 3 perf & resilience pack (#1920 / #1921 / #1571 safe subset)

- **QR image cache** (#1920) — `/qr.php` and the server-PDF renderer's inline QR both read through a new server-side cache (`tblQrCache`, additive/dormant) before ever calling the CueRCode service, so a QR for a fixed payload+options only round-trips once. A cache miss falls through to the untouched HTTP path and only a real success is ever stored — an outage can never freeze into a permanent cached 503. Fail-soft throughout: an un-migrated or unkeyed install behaves exactly as before.
- **Catalogue-index conditional revalidation** (#1921) — `?action=songs_index` now supports `If-None-Match` → **304 with no body**, skipping the ~14.5k-row query entirely on a hit, keyed on a version-signal ETag (corpus content + API contract version + deploy build + schema shape — never a hash of the payload). The service worker gained the matching client half (`networkFirstRevalidated()`) so the PWA actually sends the validator — without it the server-side half would have been invisible to its own primary consumer, since that route's existing `cache: 'no-store'` fetch (kept for an unrelated, still-needed reason) also meant no `If-None-Match` was ever attached.
- **Songbook-export rate-bucket split + large-export consent/progress** (#1571 safe subset) — `songbook_export` now has its own read-rate-limit budget, split from the one it shared with the offline-sync bulk endpoints, so a curator's export and a device's background sync no longer contend for the same counter. Every export surface now asks before building a 500+ song export and shows coarse progress while the ProPresenter bundle builds, cooperatively yielding so the page stays responsive. The full chunked-fetch re-architecture for Mission-Praise-scale exports remains an open owner decision (#1571).

### 2026-08 (late) highlights ✅ — v1.0.0 baseline → v1.1.0 (dormant-features audit, tag-free versioning, ProPresenter interop, org licences, device naming)

- **v1.0.0 baseline** (#1937) — the `claude/ilyrics-identity-work-model` branch (200+ commits) merged to `alpha`, closing the epics listed in the "Recently merged" note above.
- **Dormant-features audit** (#1955) — four silent-failure fixes plus a CAPTCHA provider-outage fallback and BCP 47 language-registry work (scheduled refresh, a live-search subtag picker, unknown-tag curation).
- **Tag-free, Conventional-Commit-driven versioning** (#1963 → #1965) — replaced the tag-derived `MAJOR.RELEASE.BUILD` scheme (#1899) entirely. The version anchor is now the **committed `MAJOR.MINOR`** in `infoAppVer.php`, bumped by `deploy.yml` itself on `alpha` from a Conventional-Commit classifier (`classify-bump.sh`) — no git tags, no GitHub Releases. `release.yml` is now dormant (manual-tag-only); `promotion-deploy-bridge.yml` reverted to being purely the beta/main SFTP-deploy bridge.
- **ProPresenter 7+ interoperability** (epic #1968) — the Song Editor now imports a genuine ProPresenter `.pro` file, a `.probundle` (multiple presentations + media), and a `.proplaylist` service order (arriving as a ready-made set list); export gained a matching `.proplaylist` direction and, for `.probundle`, embedded background media and a positioned-custom-attribute chord round-trip (not inline `[G]` brackets — confirmed against real ProPresenter files, not just a self-consistent round-trip). Dormant groundwork was also laid to capture a presentation's auto-advance timeline for later playback work. Every decoder/encoder pass is cross-validated against real, independently-produced ProPresenter files (protobufjs reflection + genuine third-party fixtures), the epic's own anti-false-positive rule.
- **Multiple licences per organisation** (#1969) — a church can record several licences at once (CCLI for the lyrics, an MRL for the music, …), each with its own number/expiry/active flag. The storage and member self-service editor already existed; this added the **global-admin** editor and fixed two data-integrity bugs (a whole-licence-set wipe on every save; an unenforced expiry in the tier resolver) via one new shared core, `includes/org_licence_admin.php`.
- **Signed-in devices — auto-naming + rename** (#1975) — the web Signed-in Devices list previously showed every entry as "Unnamed device"; the server now derives a friendly label ("Chrome on Windows") from the browser at sign-in, and a device can be renamed in place.

### 2026-08-29/30 highlights ✅ — v1.1.0 → v1.3.0 (guided-wizard suite completed, marketing-version/build-number split, security & accessibility audit)

- **Three more guided wizards complete the #1992 family (eight total)** — on top of the five that already shipped (link-type provider, songbook, live-service venue, organisation, new song), this pass added: a **"Connect a service"** wizard on `/manage/configuration` covering IntAppsAPI, CueRCode, CAPTCHA, Email, Sign in with Apple and outbound webhooks (#2003/#2004, one registry-driven engine, not six copies), a **"Guided environment setup"** wizard over `/manage/setup-database` for a brand-new install (#2005), and a **"Turn on content locking"** activation wizard on `/manage/gating` — the most safety-sensitive wizard in the program, previewing impact and checking a licence is on file before flipping the switch, with a one-click undo (#2006). Every wizard stays purely additive; the classic manual form or switch it walks through is untouched.
- **Marketing version split from the build number** (v1.1.0 → v1.3.0) — `Version.Number` is now a clean `MAJOR.MINOR.PATCH` end-to-end instead of folding the commit count into the patch digit; the commit count lives only in the separate, always-climbing `Version.Build.Number`. PATCH becomes a real, deliberate release level via a whole-line `Release: patch` merge-message footer. Every display surface now shows both, never merged: `iHymns v1.3.0 · build <n> · Alpha`.
- **2026-08-30 security & accessibility audit — first pass** — a whole-codebase pass. Security: the Database Setup dashboard's `?action=` links gained a CSRF check (L-1), and the outbound clients for CueRCode/IntApps/Internet Archive now refuse to contact a private or cloud-metadata address even when an admin-typed URL points at one (L-2, new shared `network_guard.php` core). Accessibility: a batch of keyboard/screen-reader fixes across the favourites tag editor, the shared external-link editor, the compare tool, the musicians page, and every guided-wizard modal (a dead "Undo" button in the gating wizard fixed, live status regions added, focus management corrected). A coordinated light-theme contrast pass fixed five colour failures across the admin area that only showed up in Light/System-light mode (closes #2000).

### 2026-08-30 (same-day, later) highlights — runtime bug hunt, sitemap hardening, search-engine visibility, second security & accessibility pass → v1.3.0

A further push the same day, still on `claude/dormant-features-settings-1sdw4t` (held for owner review, not yet merged as of this writing).

- **Runtime bug hunt** (epics #2008, #2018) — a "silent no-op" sweep found and fixed three real dead-wiring bugs (unreachable export-button lookups in the legacy editor, a dead song-count update, a Live-Follow mount selector running on a brittle fallback) plus six new/extended mutation-proven guards. A follow-up correctness review of the branch's own diff plus high-risk existing subsystems confirmed three more: an SSRF guard that missed bracketed-IPv6/numeric-IPv4 host literals (F-1, Medium/security), a licence-expiry check enforced on only one of two code paths so an expired legacy CCLI licence could still grant a paid tier (F-2, Medium), and a cosmetic duplicate-tag bug in the favourites editor (F-3, Low).
- **Dynamic sitemap hardening** (#2023) — `/sitemap.xml` is now a sitemap index with honest per-entity `lastmod` (never a placeholder "today"), five previously-missing public page types added, two per-user page types that were wrongly advertised removed, conditional GET so a repeat crawler hit costs almost nothing, and a bulk-record read replaced with a slim id+date query.
- **Per-channel search-engine visibility** (#2024/#2025) — a new admin card lets production/beta/alpha each be switched independently in or out of search-engine listings (production on, beta/alpha off by default, no migration needed); switching a channel off 404s its sitemap, adds `X-Robots-Tag: noindex` site-wide, and drops its line from a newly-dynamic `robots.txt` — while the site itself keeps working normally. This is the session's one genuine `feat:`; the branch merged (PR #2048) and deployed to `alpha` as **v1.3.0** (owner-accepted — the committed 1.3.0 was already the version anchor, so the classifier had nothing after it to bump).
- **Second, deeper security + accessibility pass** (epics #2018, #2027) — closed a stored-XSS gap in the publisher page's JSON-LD block, widened the L-2 SSRF guard to the two address forms it had missed, and completed a full WCAG 2.1 AA review (0 Critical, 2 High, 8 Medium, 10 Low, all fixed): further colour-contrast fixes, four more pop-up panels gained a proper keyboard focus trap, every catalogue-record page now sets a real browser-tab title, the opt-in "Emphasise Links" mode gained an underline, and dozens of smaller labelling gaps were closed across the admin area and the legacy editor. Six CI guards built or widened, all mutation-proven.
- **Full suite green throughout**: `npm test` 92/92, `php tools/run-php-tests.php` 258/258, `php -l` and `node --check` clean across the whole tree.

---

## 📌 Next Milestones

### Milestone 0 (blocking, not a feature): live multi-device Service Mode / Live-Follow verify (#1339)

The `claude/wave3-fixes` runtime-verification item this section used to track is **done** — that
branch (`49d4d57d`) is merged and its commits are in every branch's history, including this one.
The one item in this family still genuinely unverified (CLAUDE.md rule #26) is **#1339**: Service
Mode / Live-Follow has never once been exercised with two real devices on one channel (a leader
device broadcasting + a congregant device following, live). Everything else in the family —
including the persistent host bar, presentation-app driver key, and the #1770 idle-timeout
resolver — has shipped and been reasoned through, but this one needs physical hardware the
container doesn't have.

### Milestone 4 & 5: Apple App (consolidated, unreleased)

- Phase 1 + Phase 2 code-complete (iHymnsKit SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents); consolidated and CI-compiled but unreleased; device matrices and APNs provisioning owner-gated
- Song data model, browser, search, detail view, favourites — shipped
- Remaining: device matrices, APNs provisioning, App Store submission, signing & notarisation (owner-gated)

### Milestone 7: Android App (scaffold / in progress)

- Kotlin / Jetpack Compose — ~12 Kotlin files, not yet feature-complete
- Feature parity with Apple app

### Major catalogue follow-ups (issue-tracked, full design captured)

- **#943** — Works ISWC API integration (ISWCnet + MusicBrainz + MRO IDs)
- **#944** — UI i18n + Translator role + Roles admin area
- **#945** — Naming cleanup: User Groups / Access Tiers / Roles / Entitlements / Licence Types vocabulary audit
- **#946** — Analytics expansion + external platform integration (GA4 / Plausible / Matomo)
- **#947** — Login forms: Cloudflare Turnstile / reCAPTCHA / hCaptcha admin-configurable

---

## 📈 Progress Summary

- **Songs**: ~14,000 across 30+ songbooks (multilingual: English, Afrikaans, Spanish, French, Swahili, Portuguese, and others; live count in `tblSongs` — query the DB, don't trust this file), served **live from MySQL** (DB-direct #1010)
- **Web PWA**: Feature-complete (core + enhanced + admin portal + editor)
- **GitHub Issues**: highest issue now #2087 — see GitHub for live open/closed counts
- **Phase**: ONE (local-catalogue / DB-direct; pre Phase-TWO iLyrics dB API integration)
- **Version**: 1.3.0 Alpha (authoritative: `includes/infoAppVer.php`) — **tag-free**, Conventional-Commit-driven scheme (#1963 → #1965 → the marketing-version/build-number split, supersedes the retired tag-derived #1899 scheme): the committed `MAJOR.MINOR.PATCH` is the anchor, bumped by `deploy.yml` on `alpha` from a commit-message classifier (`feat:` → minor, a breaking change → major, a whole-line `Release: patch` merge footer → patch, everything else → build-only); the build number is a SEPARATE, always-climbing field (`Version.Build.Number` = `git rev-list --count HEAD`, deploy-injected; `NULL` on an undeployed checkout) shown alongside the version, never folded into it. No git tags, no GitHub Releases
- **CI/CD**: 15 GitHub Actions workflows live

---

## 🔑 Legend

| Symbol | Meaning |
| --- | --- |
| ✅ | Complete |
| 🟢 | In Progress — on track |
| 🟡 | In Progress — needs attention |
| 🔴 | Blocked |
| 🔲 | Not Started |
| ⏳ | Waiting (on external input) |

---

Last updated: 2026-08-30
