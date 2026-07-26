All investigation complete. Here is the full report.

---

# TASK B3 — RANKED IMPROVEMENT BACKLOG for the alpha releases

**Sources:** all 267 open issues read (3 paginated pulls, totalCount=267); silent-failure sweep across `appWeb/public_html`; B1/B2 reports folded in; `.claude/production-readiness-sequence.md`, `ProjectBrief.md`, `MEMORY.md`, `2026-07-26-HANDOFF.md`. Everything marked VERIFIED was confirmed by reading code/issues in this container; INFERRED is labelled.

## 0. The issue landscape in one paragraph (267 open)

Real themes: (1) a huge **Apple-native programme** (~70 issues, apple-phase-1.5/2/3 + backend-for-apple) whose code is code-complete-but-unmerged on the current branch; (2) an **import/export/interchange platform** (#881 family, #1066/#1090 epics); (3) **quality epics** already sequenced (#1543 wording → #1544 i18n → #1542 colour → #1545 a11y, plus #1536 CodeQL last); (4) **observability/security small-fry** from a June audit (#1022–#1040) that mostly never got done; (5) a long **for consideration** tail (~45 issues); (6) ~40 platform-expansion placeholders (smart-TV #463–#472, Android phase-1 #191–#202) that are dormant by design. **Tracker rot found:** #1406 is OPEN but its feature is fully implemented on origin/alpha (VERIFIED: `api.php` alpha ~14699 `service_poll` takes `?since=`, returns cheap `changed:false`, per-role poll budget 90/40, and `pollIntervalMs` in the join response) — the handoff's suspicion is confirmed. #1377 likewise OPEN but implemented (VERIFIED: `followToken` ×7 on alpha's `live-follow.js`). #1405 is genuinely still open (payload v2 absent from alpha — 0 hits for `displayState|stateVersion`; only its Channel side-finding landed). Stale/duplicate candidates: #24 (superseded by #1010 DB-direct), the April Apple scoping set #12/#14/#16/#19/#22 (superseded by the apple-phase-2 family), #205≈#1415, #207≈#1414, #208≈#1429, #213≈#1430, #206≈#1432, #210≈#1417, #340≈#947, #944≈#1103.

## 1. The silent-failure class hunt (the export-bug class) — quantified

- **0 global error handlers.** No `window.onerror`, no `unhandledrejection` listener anywhere in app JS or `index.php` (VERIFIED by grep; only `storage-bridge.js:110` has an iframe error listener). Every uncaught JS error in the PWA dies console-only — the precondition that let #1565 hide for 7 weeks is still fully in place.
- **2 dead event wires (VERIFIED, new bugs):**
  - **Case-mismatch:** `js/modules/settings-language-filter.js:123` dispatches `ihymns:language-filter-changed` (lower-case h) — **zero listeners exist for that spelling**. The only listener is `js/modules/song-of-the-day.js:47` for `iHymns:language-filter-changed` (capital H), which only `js/modules/songbook-language-filter.js:217` dispatches. DOM event names are case-sensitive → changing the language filter from **Settings** never refreshes the Song-of-the-Day card; changing it from a songbook page does. Silent, no error, exactly the export class.
  - **Listener with no dispatcher:** `js/modules/offline-ui.js:237` listens for `ihymns:offline-settings-changed`, which nothing anywhere dispatches — and the handler body is an admitted no-op placeholder ("future work once the SW actually consumes a flag"). The Settings "include audio offline" pref (`settings.js:70,707-714`) is therefore inert for tile downloads (matches B2's RC finding).
- **2 orphan endpoint pairs (VERIFIED, phantom features):** `push_subscribe`/`push_unsubscribe` exist in `api.php` + `api-docs.yaml:5526` but **no client ever calls them and `service-worker.js.php` has no `push`/`notificationclick`/`showNotification` handler at all** — web push is a backend-only phantom. `service_control_token_mint`/`_revoke` (`api.php:14914+`) have **zero callers in the entire repo including appApple** — the leader-device connect-and-drive flow they were built for (rule #26 "Still TODO") uses `service_code_*`/`service_broadcast` instead (`manage/service-lead.php:247-308`, `service-broadcast.js:154`).
- **~76 API actions with no in-repo caller** (of 215 `case '...'` actions in api.php): the whole `admin_*`/`org_admin_*` parallel admin API surface. VERIFIED the web admin doesn't use it — e.g. `manage/users.php` is a self-contained server-rendered POST page (22 `$_POST` refs, zero `action=` fetches). This is a large, auth-sensitive, completely unexercised surface (presumably for future native admin); #1435 already found its docs are stale.
- **Benign classes checked and cleared:** the 7 empty `.catch(() => {})` are deliberate fire-and-forget (`router.js:578,706` etc.); ~45 empty JS catches are localStorage/sessionStorage guards; the 20+ empty PHP catches are documented best-effort fallbacks (`SongData.php:483+`, `api_keys.php:103` etc.); `@`-suppressions are cleanup calls; all `js/modules/*` are imported by something; the song-correction form IS wired (delegated in `app.js:604-638` → `song_correction_submit`, api.php:9377); `<main aria-live="polite">` exists (`index.php:1261`) so route changes are crudely announced.
- **No tester affordances:** no environment badge (zero hits for env/channel badge in `index.php`/modules), no "What's New" surface, no bug-report affordance beyond per-song corrections and `/request`.

---

# THE RANKED LIST (by value-to-alpha ÷ effort)

## TIER 1 — do next

**1. Open the pending PR (export fix + Apple consolidation) — consider splitting the export fix out**
- **What & why:** Everything fixed this session — the entire public Export feature, Present mode, the CI guard — exists only on the unpushed-to-alpha branch. Alpha testers still have the dead Export menus today. The export commits (`7da63424`..`89cc2398`) have zero file overlap with the Apple work and could ship immediately without waiting for the first Swift compile.
- **Evidence:** handoff §RESUME; branch 65 commits ahead, no PR.
- **Effort:** XS (open draft PR / cherry-pick 6 commits). **Risk:** Low for the export split; Medium for the combined PR (first-ever Swift compile). **Value to alpha:** High. **Issue:** #1565–#1570. **Blockers:** owner go-ahead (standing "no PR yet" direction).

**2. Owner: run the pending migration cards, then execute the #1339 live verify**
- **What & why:** B1's decisive unknown — if the Service-Mode migration was never run on the shared DB, Go Live/Join Live 500s on every attempt on alpha. One click on `/manage/setup-database` potentially fixes the whole Live-Follow saga for all three environments, and the #1339 two-device verify is the acceptance test the feature never had.
- **Evidence:** B1 Cause 3; `migration-registry.php:2239-2256`; #1339 OPEN listing the never-run migrations.
- **Effort:** XS (owner, ~15 min). **Risk:** Low (additive migrations). **Value to alpha:** High. **Issue:** #1339. **Blockers:** owner only — cannot be done from here (no DB access).

**3. Global JS error surfacing: `window.onerror` + `unhandledrejection` → toast + throttled server beacon** *(NEW idea)*
- **What & why:** The owner has now been bitten twice by features that fail into the console with no visible sign. A ~60-line module that catches every uncaught error/rejection, shows a small "Something went wrong — tap to report" toast, and beacons a deduplicated one-liner (message, file:line, route, version) to a tiny api.php endpoint would have surfaced #1565 in day one. This is the single cheapest structural defence against the entire class.
- **Evidence:** zero handlers repo-wide (grep above); the existing `toast.js` + `logActivity()` plumbing make this mostly assembly.
- **Effort:** S. **Risk:** Low (throttle + dedupe to avoid beacon floods; never toast on the toast). **Value to alpha:** High — every future silent failure becomes a report. **Issue:** NEW. **Blockers:** none.

**4. Alpha tester HUD: environment badge + version + "What's new" link** *(NEW idea)*
- **What & why:** B1 Cause 2 happened partly because nothing on screen says which environment you're on — a tester with the PWA installed from www and a desktop on dev cannot know their Live codes live in different worlds. A small footer/header badge ("ALPHA · dev.ihymns.app · v0.4000.0") plus a link to a rendered CHANGELOG page tells testers where they are and what just changed (so they know what to test).
- **Evidence:** no env indicator anywhere in `index.php`/modules (grep); `.env-channel` already injected by `deploy.yml:196-203`; `infoAppVer.php` already loaded (`index.php:106`).
- **Effort:** XS–S. **Risk:** Low. **Value to alpha:** High. **Issue:** NEW. **Blockers:** none.

**5. Fix the language-filter event-name case mismatch (and extract the event names to constants)**
- **What & why:** Changing your language filter in Settings silently fails to refresh the Song-of-the-Day card because one module dispatches `ihymns:…` and the listener wants `iHymns:…`. One-line fix; extracting all `ihymns:*` event names into one shared constants module prevents the class recurring.
- **Evidence:** `settings-language-filter.js:123` vs `song-of-the-day.js:47` vs `songbook-language-filter.js:217` (VERIFIED, zero listeners for the lowercase spelling).
- **Effort:** XS. **Risk:** Low. **Value to alpha:** Medium (real user-visible bug, trivially fixed). **Issue:** NEW. **Blockers:** none.

**6. Offline downloads fix bundle, phase 1 (B2 RC1 + RC5 + RC4 + RC7)**
- **What & why:** Bulk downloads currently self-destruct: the 2000-entry cache cap deletes most of a big download the next time any song is viewed; the Settings progress bar hangs forever on any per-book failure; every deploy wipes all downloaded audio; and "Remove from offline" never deletes audio because of a path-shape mismatch. Fixing the SW policy + honouring the `done` flag makes offline downloads actually keepable.
- **Evidence:** `service-worker.js.php:129,1122-1127` (cap/evict), `settings.js:1196-1244` (done ignored), activate keep-list `:366-387` (media bucket wiped), `EVICT_SONGBOOK :1053-1064` vs flat `/data/audio/<SONGID>.mp3` (`api.php:1488`). Harness outputs in B2.
- **Effort:** M. **Risk:** Medium (SW cache policy changes need careful versioning; test with the harness pattern). **Value to alpha:** High — offline is a flagship PWA promise testers will exercise. **Issue:** NEW (B2 proposed issues 1, 4, 5, 7). **Blockers:** none.

**7. Offline navigation: cache the home/songbooks/songbook/search fragments (B2 RC3)**
- **What & why:** Even a perfect download is unbrowsable offline — every non-song page 503s into a red "Failed to load page" alert, so users can't reach their saved songs except via history/bookmarks. Caching the four fragment types (they're already shared-cache-safe) makes the offline story real.
- **Evidence:** `service-worker.js.php:444-515` (only `page=song` offline-falls-back); `index.php:1276-1278` (empty shell); `router.js:451-453`.
- **Effort:** M. **Risk:** Medium (stale-fragment freshness rules; keep the versioned bucket). **Value to alpha:** High. **Issue:** NEW (B2 proposed issue 3). **Blockers:** ideally lands with/after 6.

**8. Tracker hygiene sprint: close done-not-closed, merge duplicates (feeds existing program #1159)**
- **What & why:** Issues are this project's declared point of truth, and it's drifting: #1406 and #1377 are open with the work verified live on alpha; ~10 pre-reset Apple issues duplicate the current phase-1.5/2/3 set; #340≈#947, #944≈#1103, #24 superseded by #1010. Closing/cross-referencing ~15–20 issues makes every future planning pass (including this one) cheaper and honest.
- **Evidence:** verification greps above (alpha `service_poll` since-poll; `followToken` ×7); title-level dup mapping in §0.
- **Effort:** S–M (mostly issue-writing with evidence SHAs). **Risk:** Low. **Value to alpha:** Medium-High (indirect but compounding). **Issue:** #1159. **Blockers:** none.

## TIER 2 — soon

**9. `bulk_songs` O(N²) fix + chunked bulk downloads (B2 RC2)**
- **What & why:** The per-songbook download renders each song with a full re-fetch AND a whole-book re-hydration per song (the in-code performance comment claiming otherwise is false), so big books time out server-side and OOM the SW client-side. Hoist one `getSongs($abbr)` and inject `$song`/prev-next into `song.php`; then chunk the response so no single request carries 3,500 songs.
- **Evidence:** `api.php:1397-1456` (false comment at 1421-1430), `includes/pages/song.php:23,1470`, `manage/gating-noop-verify.php:104-108` (the repo documenting the re-fetch).
- **Effort:** M–L. **Risk:** Medium (song.php render contract touched by many callers). **Value to alpha:** Medium-High (unblocks Mission-Praise-scale downloads; also helps #1571). **Issue:** NEW (B2 issue 2), related #1571. **Blockers:** none.

**10. Live Follow diagnosability: cross-channel near-miss logging + host stale-session warning (B1 fixes 4-5)**
- **What & why:** The two remaining "it just doesn't work" traps: a code from another environment fails with a generic wrong-code toast (log the near-miss at minimum), and a host whose phone locked >180 s still shows LIVE while joins fail (warn on visibility-return). Both convert un-diagnosable reports into explainable ones.
- **Evidence:** B1 (c)3/(c)5; `live-follow.js` heartbeat design; alpha `api.php:14143-14146`.
- **Effort:** S–M. **Risk:** Low (log-only server change; owner decides on the client hint vs anti-probe trade-off). **Value to alpha:** Medium-High. **Issue:** NEW (B1 issues 3-4). **Blockers:** owner decision only on the client-visible hint.

**11. Rate-limit password reset + per-account lockout**
- **What & why:** `auth_forgot_password` calls `generatePasswordResetToken()` with no `checkRateLimit` — anyone can flood a known address with reset emails; login has per-IP protection (#290) but no per-account lockout. Alpha has real testers' emails in the DB now.
- **Evidence:** `api.php:2657-2678` (VERIFIED no rate limit before token generation); `manage/includes/auth.php:927+`.
- **Effort:** S (the `checkRateLimit` helper already exists and is fail-open). **Risk:** Low. **Value to alpha:** Medium (security posture; invisible when working). **Issue:** #1028, #1027. **Blockers:** none.

**12. `/health` uptime probe endpoint**
- **What & why:** A 200/503 JSON probe lets the owner point UptimeRobot/etc. at all three environments and catch the next "alpha is white-screening" before a tester does. Pairs naturally with the deploy pipeline's verify step.
- **Evidence:** VERIFIED absent (no `case 'health'` on alpha or HEAD); the `isDbConnectionFailure()` 503 path already exists to reuse.
- **Effort:** XS–S. **Risk:** Low. **Value to alpha:** Medium-High. **Issue:** #1022. **Blockers:** none.

**13. Songbook page: stop hydrating the full book for a title list**
- **What & why:** `/songbook/MP` hydrates all 3,517 songs with components (9+ queries + full lyric assembly) to render a list of titles. Switching listing pages to the slim index makes the biggest public pages fast on shared hosting — testers on big books feel this on every visit.
- **Evidence:** `includes/pages/songbook.php:23` (`getSongs($bookId)` — VERIFIED still current); #1037 open since June.
- **Effort:** S–M (preview-line needs `lyricLinesFirstLineMap()`, which exists — rule #25). **Risk:** Low-Medium. **Value to alpha:** Medium-High. **Issue:** #1037. **Blockers:** none.

**14. In-app "Report a problem" for testers** *(NEW idea)*
- **What & why:** The only feedback paths today are per-song corrections and request-a-song. A global footer/menu item that captures free text + auto-attaches route, version, environment, and the last N beaconed errors (from proposal 3's ring buffer) into the existing `tblSongRequests`-style review queue turns every tester into a bug reporter. This is how the 7-week export silence gets shortened to a day.
- **Evidence:** correction plumbing to model on: `app.js:604-638` + `api.php:9377` (`song_correction_submit` routes through tblSongRequests with honeypot + rate limit).
- **Effort:** M. **Risk:** Low. **Value to alpha:** High. **Issue:** NEW (adjacent to #1105's corrections, which stays separate). **Blockers:** best after proposal 3.

**15. Extract request-a-song's inline module (closes the CI-guard allowlist)**
- **What & why:** The last remaining CSP-dead inline script in a fragment. The page degrades safely today, but its offline-queue enhancement never runs, and it's the only entry keeping the #1569 guard's allowlist non-empty.
- **Evidence:** #1572; `test-fragment-inline-scripts.php` allowlist; `includes/pages/request-a-song.php:72`.
- **Effort:** S (needs a small design for the `filemtime` cache-buster). **Risk:** Low. **Value to alpha:** Medium. **Issue:** #1572. **Blockers:** none.

**16. Fix the stale opensong/videopsalm parser tests and wire them into CI**
- **What & why:** Two node test files scrape api.php for a code block that moved — they can never pass, so they're not in CI, so those importers have zero regression coverage. MEMORY.md's own lesson: a test that can't fail (or can't pass) protects nothing.
- **Evidence:** #1575 (reproduced on alpha); `test.yml` runs only the new export tests.
- **Effort:** S. **Risk:** Low. **Value to alpha:** Medium. **Issue:** #1575. **Blockers:** none.

**17. Alpha → beta → main promotion (the production-readiness P1→P2 sequence)**
- **What & why:** beta/www are frozen at v0.1254.1 — every Live-Follow fix, the export fix, and ~55+ commits exist only on dev. Any tester (or TestFlight build — `APIEnvironment.swift` points Release at prod) touching beta/www is testing month-old bugs. The sequence doc is written; it needs the P1 kill-switch PR first so the promotion can't accidentally fire a TestFlight/App Store upload.
- **Evidence:** B1 Cause 1; `.claude/production-readiness-sequence.md` §0-§2 (incl. the AASA body-not-status trap and the apple-deploy.yml side-effect warning).
- **Effort:** L (mostly owner-gated checklist execution). **Risk:** Medium-High if rushed (the doc enumerates the traps); Low if sequenced. **Value to alpha:** Medium now, High the moment any tester is off dev. **Issue:** #1312-adjacent, #1536 last. **Blockers:** owner approval per the doc's own rule.

## TIER 3 — worth considering

**18. Web push: decide build-or-shelve** *(NEW finding)*
- **What & why:** `push_subscribe`/`push_unsubscribe` are live, documented endpoints with no client and no SW push handler — a phantom feature that will confuse the next API consumer (and CodeQL/#1536 will crawl it). Either build the SW `push` handler + a Settings opt-in (M-L) or mark the endpoints dormant in api-docs and file the front-end as tracked future work (XS).
- **Evidence:** `api-docs.yaml:5526`; zero `pushManager`/`showNotification` hits repo-wide.
- **Effort:** XS (decide/annotate) or M-L (build). **Risk:** Low. **Value to alpha:** Low-Medium. **Issue:** NEW. **Blockers:** owner product decision.

**19. Orphan endpoint audit: `service_control_token_mint`/`_revoke` + the 76 uncalled admin actions**
- **What & why:** The control-token pair has zero callers anywhere (superseded by the `service-lead.php` design?); the `admin_*` API surface is a parallel, unexercised, auth-sensitive implementation the web admin never touches. Decide each endpoint's fate (native-app keeper → gets a smoke test; superseded → removed), so the attack/maintenance surface matches reality.
- **Evidence:** `api.php:14914+`; `manage/users.php` (server-rendered, 22 `$_POST`); no-caller list in §1; #1435 (stale docs found by the Apple client).
- **Effort:** M (audit + tests) — the audit list is already produced above. **Risk:** Low. **Value to alpha:** Medium (mostly security posture). **Issue:** NEW; relates #1435, #1386. **Blockers:** none.

**20. Wire the include-audio pref + storage pre-flight (`persist()` + the dormant `getStorageEstimate()`)**
- **What & why:** The Settings audio toggle does nothing for tile downloads and its change event has no dispatcher; no code ever requests persistent storage, so Safari can evict everything after 7 days. Small, honest plumbing that makes offline promises true.
- **Evidence:** `offline-ui.js:234-241` (admitted), `:54-74` (`getStorageEstimate` exported, never called — grep-verified); zero `navigator.storage.persist()` repo-wide.
- **Effort:** S. **Risk:** Low. **Value to alpha:** Medium. **Issue:** NEW (B2 issue 8). **Blockers:** folds neatly into proposal 6.

**21. Public Collections (catalogues) surface — or hide the affordance**
- **What & why:** `tblCatalogues` has admin CRUD but no public route, page, or download path at all — "downloading a catalogue" cannot even be attempted (B2 RC6). Either build `/collection/<slug>` + a scoped bulk endpoint, or make sure nothing user-facing implies Collections exist yet.
- **Evidence:** B2 RC6 (no `includes/pages/catalogue*.php`, `bulk_songs` keyed on SongbookAbbr only, `api.php:1403-1414`).
- **Effort:** L (build) / XS (hide). **Risk:** Low. **Value to alpha:** Medium. **Issue:** NEW (B2 issue 6); naming per rule #24. **Blockers:** owner product decision.

**22. Export follow-ups: gating-model divergence + Mission-Praise-scale export + terser `--module`**
- **What & why:** Three tracked residuals of this week's fix: `song_data` and `songbook_export` strip content under two different models (#1573 — a future licensing hole), whole-book export at 3,517 songs is a memory/UX hazard (#1571 — shares a fix shape with proposal 9), and deployed ES modules ship unminified (#1574 — free bytes).
- **Evidence:** the three issues, filed this session with full detail.
- **Effort:** S (#1574) / M (#1571, #1573). **Risk:** Low-Medium. **Value to alpha:** Low-Medium. **Issue:** #1571/#1573/#1574. **Blockers:** #1573 only matters when gating is enabled.

**23. CI: execute schema.sql + migrations against a real MySQL service container**
- **What & why:** Static lint can't catch invalid DDL or schema/migration divergence in behaviour; a `mysql:8` service job that runs `schema.sql` then every migration would close the "it's in schema.sql but not on alpha" gap that keeps producing white-screen classes (#1228) and the B1 Cause-3 ambiguity.
- **Evidence:** #1175; rule #19's CI guards only compare text today.
- **Effort:** M. **Risk:** Low. **Value to alpha:** Medium (prevents whole bug classes). **Issue:** #1175. **Blockers:** none.

**24. The quality epic train, in the already-agreed order**
- **What & why:** #1543 (plain-English wording) → #1544 (i18n) → #1542 (colour/WCAG) → #1545 (a11y audit) are properly scoped and sequenced in the handoff; nothing found this session changes that order. One note: the `<main aria-live="polite">` whole-page announcer (`index.php:1261`) is crude (announces everything) — fold a dedicated route announcer into #1545 rather than doing #1029 standalone.
- **Effort:** XL each. **Risk:** Low. **Value to alpha:** Medium (wording first is right — testers report what they can understand). **Issue:** #1543/#1544/#1542/#1545 (+#1029 folds in). **Blockers:** capacity.

---

## What I would personally do first, and why

**Proposals 1–5, in that order, this week.** Open the export-fix PR (split from the Apple consolidation so a user-reported, fully-verified fix isn't hostage to a 40-minute Mac build), have the owner spend fifteen minutes on `/manage/setup-database` + the #1339 checklist (it may single-handedly end the "Live Follow never worked" saga), then land the global error handler and the environment badge before anything else new is built. The deep lesson of the export bug, the Live-Follow saga, and the offline findings is the same one three times: **this codebase fails silently, and nobody — owner, tester, or CI — currently finds out.** Proposals 3, 4, 12 and 14 are all cheap instances of one strategy: make the alpha observable. Every subsequent bug gets cheaper to find for as long as the project lives, which is worth more than any single feature in the backlog. After that, the offline bundle (6–7) is the highest-value user-facing repair, because it is the flagship PWA promise and currently breaks in four compounding ways that all *look* like success.