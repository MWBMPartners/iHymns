All investigation complete. Here is the full report.

---

# TASK B1 — "Join Live / Start Live has NEVER worked" — root-cause report

## (a) ROOT CAUSE — an ordered list of independent causes

There is **no single code bug on alpha's happy path** — I traced the entire flow end-to-end (below) and the alpha client+server chain is coherent. The feature's persistent failure is the compound of **three structural facts**, plus one decisive unknown only the owner can check. Ordered by likelihood of being what the owner is experiencing:

### CAUSE 1 — Every Live-Follow fix only ever reached alpha; beta and production still run the pre-fix code (VERIFIED, high confidence)
`origin/main` and `origin/beta` are both frozen at **v0.1254.1** (`git show origin/{main,beta}:appWeb/public_html/includes/infoAppVer.php` → `"0.1254.1"`; main's last merge is PR #1369, ~2026-06-25). That snapshot **predates the entire fix train**:
- **90-second** join/poll freshness (main `api.php:12154`, `12196` — `INTERVAL 90 SECOND`) instead of alpha's 180 s (`alpha api.php:14146`, `14217`);
- **no** host wake-beat (`visibilitychange` grep count = 0 in main's `live-follow.js` vs the fix on alpha) — a briefly backgrounded host tab goes stale and joins fail;
- **no** #1375 fixes (no maintenance-503 disambiguation, no forgiving code entry — grep counts 0);
- **no** #1377 per-follower poll token (`followToken` grep: main 0, alpha 7);
- **no** #1405 Channel stamping/filtering (grep for the `#1405 side-finding` hunk: main 0 hits, alpha 4 hits).

So on `www.ihymns.app` / `beta.ihymns.app` the feature is still exactly as broken as when the owner first reported it. "We believe it was fixed" is true **only for dev.ihymns.app** — the alpha→beta→main promotion (#1312 / `.claude/production-readiness-sequence.md`) has not run since 0.1254.1. **This is a deploy/promotion state, not a code bug.**

### CAUSE 2 — The Channel wall makes every cross-environment test fail by design, and the three hosts share one DB so this looks like "the same site" to the owner (VERIFIED mechanics; INFERRED that this is the owner's test topology; medium-high confidence)
All three docroots share ONE MySQL (rule #26). Alpha stamps `Channel` at create (`api.php` alpha `~13904-13940`) and filters it at join/poll (`14143-14146`, `14217-14219`); prod/beta (0.1254.1) neither stamp nor filter. Deterministic outcomes (all verified by reading both versions):

| Host on | Join on | Result |
|---|---|---|
| dev | dev | works (on paper) |
| dev | www / beta | *found* (prod join has no Channel filter) — but subject to prod's 90 s window/old bugs |
| **www / beta** | **dev** | **NEVER found** — prod create leaves `Channel = NULL`; alpha join requires `Channel='alpha'` → "Session not found or ended", forever |
| www | www | old pre-#1375 behaviour (90 s staleness, blaming toasts) |

The most natural real-world test — desktop on one host, the phone's installed PWA on another — falls into a failing row. Nothing in the UI says "this code belongs to a different iHymns environment"; the follower just gets the wrong-code toast.

### CAUSE 3 — The Service-Mode schema migration may never have been applied to the shared DB; if so, alpha's Go Live/Join Live **500s on every attempt** while prod keeps limping (mechanics VERIFIED; DB state UNKNOWN — the decisive 10-second owner check)
`Channel`/`VenueId`/`SessionKind` + `tblLiveFollowJoinCodes`/`tblServicePresence`/`tblServicePollCounters` exist **only** after `appWeb/.sql/migrate-service-mode-sessions.php` (single atomic ALTER, lines 100-116; registry entry `manage/includes/migration-registry.php:2239-2256`). Migrations are hand-run (`/manage/setup-database`), and **GitHub #1339 is still OPEN and explicitly lists "Run the 4 migrations (#1325/#1327/#1332/#1335) on alpha" plus the "live multi-device verify" as never done** — rule #26 itself says the live verify is "Still TODO". *The end-to-end flow has never once been verified live by anyone.*

If the ALTER hasn't run: alpha `live_follow_create`'s INSERT names `Channel` (alpha `api.php:13940`) → mysqli 1054 under STRICT → uncaught → the global JSON handler (`api.php:91-145`) returns **HTTP 500** with the real message (alpha is verbose, `api.php:82-84`) → toast "Could not start the session: Unknown column 'Channel'…". `live_follow_join` 500s the same way (`s.Channel` in its WHERE). Meanwhile production's 0.1254.1 SQL references none of the new columns, so prod "works". That is precisely "*still never worked, **even in alpha** where fixes should have landed*". (Evidence the **base** tables exist: the #1375-era host successfully minted codes, which exercised `tblLiveFollowSessions` + `StateRevision` — but that predates any `Channel` reference, so it proves nothing about the Service-Mode ALTER.)

**Ruled out (verified):** CSP (controls are built entirely in JS by `live-follow.js:_mountControls`; only `index.php:211` sends a CSP; manage pages send none); stale service-worker JS (SW is network-first + `cache:'no-store'` for JS and `/api`, `service-worker.js.php:64-95`); terser breaking ES modules (empirically tested — terser 5.49 parses `export` without `--module` and minified my ESM fixture correctly); rate-limit/log helpers throwing (`checkRateLimit`/`recordRateLimitHit`/`logActivity` are all fail-open, `includes/rate_limit.php:118-151`); CSRF (client sends `X-Requested-With`, `live-follow.js:108`, guard at `api.php:277-328` accepts it); env-detection drift (deploy injects `.env-channel`, `deploy.yml:196-203`, and per-request detection is per-docroot-consistent so a same-host create/join pair can never mismatch); missing mount anchors (`includes/pages/song.php:400` emits `.page-song[data-song-id]`, `:785` the `.d-flex.flex-wrap.gap-2` row; `router.js:617` calls `initSongPage` on alpha too); `tblUsers.DisplayName` exists (`schema.sql:721/736` region).

## (b) What the user actually experiences

1. **Song page, signed in, on dev** → "Go Live" + "Join Live" buttons render (JS-mounted, `live-follow.js:370-415`). Signed out → **no Go Live button at all** (`:399`) — reads as "Start Live is missing/broken".
2. **Click Go Live**: if Cause 3 holds → red toast "Could not start the session: …" every time. If schema is fine → "You're live — share code X" (host side *looks* healthy).
3. **Second device joins**: on a different host (Cause 2) or after the host phone locked >180 s (heartbeat is a throttled `setInterval`; the wake-beat only fires when the host returns) → "Session not found. Check the code…" — the leader's screen still says LIVE, so the *feature* gets the blame.
4. **On production/beta** (Cause 1): the original bugs — 90 s staleness, no wake-beat, maintenance-503 shown as a wrong code — are all still live.
5. **Projection "Start & project"** (`manage/service-projection.php`): honest degradations — "Service Mode not migrated yet" card (`:152-157`) or "No venues to run yet" (`:158-159`); `service_session_start` additionally requires org-admin + a venue (`api.php:14366-14386`), so a plain admin account without an org/venue can never start a *service* session.

## (c) Other defects found in this subsystem (severity)

1. **MEDIUM — cross-env session kill:** alpha's `live_follow_create` supersede is *not* Channel-scoped (`origin/alpha api.php:13924`: `UPDATE … WHERE HostUserId = ? AND IsActive = 1`). Starting a session on any env silently deactivates the same user's active session on every other env. Already fixed on this branch (#1429 Audit-B F4, `api.php:14000-14010`) but **not yet on alpha** (branch has no PR).
2. **MEDIUM — poison rows:** prod/beta-created sessions carry `Channel=NULL` forever; asymmetric visibility (invisible to alpha joins, visible to prod joins) makes cross-env behaviour non-explainable to a user.
3. **LOW-MED — mobile-host fragility:** heartbeat dies when the host device locks; joins fail after 180 s with a wrong-code-flavoured message; no UI warning to the host that their session went stale.
4. **LOW — maintenance asymmetry (by design):** an admin host bypasses maintenance (`maintenance.php:395-397`) and mints codes while anonymous joiners get 503 — messaging is honest since #1375, but a dev env left in maintenance makes Join permanently fail.
5. **LOW — misleading UX:** no "this code was started on a different iHymns environment" hint; the anti-probe generic message is correct security-wise but indistinguishable from breakage.
6. **INFO:** full Channel audit of alpha (harness output above): every join/poll/broadcast/gate query filters Channel or is keyed by a globally-unique code/token/pre-resolved Id; only finding is item 1.

## (d) Precise fixes (no code written)

1. **Owner/DB:** run the pending cards on `/manage/setup-database` on the shared DB — `org-venues` (#1325) then `service-mode-sessions` (#1335) (+ `live-follow`/`live-follow-revision` if somehow pending). One click resolves Cause 3 for all three envs at once (shared DB).
2. **Process:** promote alpha → beta → main (#1312 sequence) so Cause 1 disappears; until then, tell the owner plainly that Live Follow fixes exist **only** on dev.ihymns.app and both devices must use dev.
3. **Merge this branch** (or cherry-pick the F4 hunk) to get the Channel-scoped supersede onto alpha (`api.php` live_follow_create).
4. **Small UX change (new work):** in `live_follow_join`/`service_join`, when the code matches a session on a *different* Channel, keep the 404 but log it and (optionally, owner decision — weigh the anti-probe property) return a distinct client hint "this code belongs to a different iHymns environment"; at minimum add it to the Activity Log so the next "broken" report is diagnosable.
5. **Small UX change (new work):** host-side stale-session detection — heartbeat response already returns `ok:false`; additionally, on `visibilitychange`-return detect >180 s gap and re-assert/warn ("your live session may have dropped while the screen was off").
6. **Execute #1339's live multi-device verify** as written (it is the acceptance test this feature never had).

## (e) Verifiable here vs. needs the owner

- **Verified in this container:** everything in (a)/(c) — client wiring, all handler logic on `origin/alpha`/`origin/main`/`origin/beta`/HEAD, migration scripts/probes/schema, deploy workflow, SW strategy, terser behaviour (empirical), Channel audit (static harness, output above).
- **Needs the owner (cannot be done here — no DB credentials, no MySQL client, no browser):**
  1. `/manage/setup-database` on dev — are "Org Venues" / "Service Mode sessions" cards **pending**? (decisive for Cause 3; `/manage/service-projection` shows "Service Mode not migrated yet" in the same state);
  2. DevTools → Network on a Go Live click on dev — the `live_follow_create` status + JSON body (alpha is verbose; the body names the real cause);
  3. confirm both test devices were on **dev.ihymns.app** (Cause 2);
  4. check the maintenance toggle in `/manage/configuration` for the dev env;
  5. the two-device happy-path run (#1339's checklist).

## (f) GitHub issue mapping

- **Existing, explains the history:** #1268 (web Live Follow, closed), #1335/#1323 (Service Mode), #1360 (Go Live fatal — fixed), #1375 (join blames the code — fixed), #1377 (NAT token — fixed), #1386 follow-ups (freshness/home-join fallback — fixed), #1405 (payload v2 + the Channel side-finding fix), **#1339 (OPEN — the never-executed migrations-on-alpha + live multi-device verify; this is the issue whose non-completion left the feature unproven)**, #1312 (promotion), #1104/#1426/#1427/#1555 (native clients; DEBUG builds → dev, Release/TestFlight → prod per `APIEnvironment.swift:47-53,79-91` — a TestFlight build talks to the frozen 0.1254.1 backend).
- **New issues to file (exact titles):**
  1. `Live Follow/Service Mode: alpha endpoints 500 if the Service-Mode sessions migration is unapplied — confirm shared-DB schema state and run #1339's migration step`
  2. `Live Follow: fix train (#1360/#1375/#1377/#1386/#1405) is alpha-only — beta/production still run the pre-fix v0.1254.1 code; schedule the promotion`
  3. `Live Follow: cross-environment joins fail silently at the Channel wall — log the cross-channel near-miss and decide on an honest client hint`
  4. `Live Follow: host heartbeat dies when the host device locks — surface a stale-session warning to the host`
  5. (close-after-merge note on #1429 for the F4 Channel-scoped supersede once this branch's PR lands)

Key files: `appWeb/public_html/js/modules/live-follow.js`, `js/modules/service-follow.js`, `js/modules/service-broadcast.js`, `appWeb/public_html/api.php` (13944-14904 on HEAD), `includes/service_mode.php`, `includes/environment.php`, `manage/service-projection.php`, `manage/service-lead.php`, `appWeb/.sql/migrate-service-mode-sessions.php`, `manage/includes/migration-registry.php:1856-1880,2239-2256`, `.github/workflows/deploy.yml:196-231`, `appApple/Packages/iHymnsKit/Sources/IHAPI/APIEnvironment.swift`. Audit harness: `/tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad/channel-audit.php`.