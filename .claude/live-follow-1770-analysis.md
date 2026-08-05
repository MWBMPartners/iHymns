# #1770 — Live Follow / Service Mode UX rethink: deep analysis (ad-hoc vs scheduled)

> **Read-only analysis, 2026-08-05.** Feeds the subsequent DEEP PLANNING pass for issue
> [#1770](https://github.com/MWBMPartners/iHymns/issues/1770). No code was changed.
> Sources: CLAUDE.md rule #26, `.claude/live-congregant-strategy.md`, the live tree
> (all `file:line` cites below verified against the working copy this date), and issues
> #1268 / #1323 / #1335 / #1339 / #1577 / #1767 / #1768 / #1770.
>
> All web paths below are relative to `appWeb/public_html/` unless rooted (`.claude/…`, `help/…`, `wiki/…`).

---

## 1. Current state, precisely

### 1.1 The two features in one sentence each

- **Live Follow (#1268)** — *ad-hoc, person-anchored.* Any signed-in user opens a song, taps
  **Go Live**, gets a **fixed 6-char code**, and everyone who types that code mirrors the
  host's **current song** (song-level only) until the host ends it. No org, no venue, no
  schedule, no admin console. `OrgId` is hardcoded `NULL` (`api.php:16723`).
- **Service Mode (#1323/#1335)** — *scheduled, venue-anchored.* An org-admin starts a session
  bound to a **venue + occurrence date** (optionally a saved schedule) from the **admin
  console**; the venue screen shows a **rotating ~30 s code + QR**; congregants follow **song
  AND section**; presence tokens can (dormantly) unlock CCLI-gated lyrics for the duration.

They share ONE table (`tblLiveFollowSessions`) discriminated by `SessionKind`
(`'host'` vs `'service'`, `schema.sql:4079`) — a storage-sharing decision, not a product
merge. Conflating them "made Live Follow look permanently broken" (rule #26); #1577 separated
the docs; #1770 asks for the *setup/run UX* to be rethought.

### 1.2 Every entry point

| Surface | What | Where | Who sees it |
|---|---|---|---|
| Public home page | **"Join a live service"** button (`data-action="join-service"`) + caption "Enter the code shown on your church's screen" | `includes/pages/home.php:112-117`; wired by `js/modules/service-follow.js:49-53` (delegated click, booted in `js/app.js:330-333`) | Everyone, incl. anonymous |
| Public song page | **"Go Live"** (signed-in only) + **"Join Live"** buttons, injected into the song action row | `js/modules/live-follow.js:376-421` (`_mountControls`; Go Live at :405-413, Join Live at :414-420); booted `app.js:325-328`, mounted from `router.afterPageLoad('song')` | Go Live: signed-in; Join Live: everyone |
| Admin console | **Service Projection** (start + full-bleed rotating code + QR + docked "Drive songs" console) | `manage/service-projection.php`; nav entry `manage/includes/admin-links.php:103` | Nav advertised under `manage_organisations`; page self-gates to `manage_organisations` **OR** org-admin (`service-projection.php:64-70`) |
| Admin console | **Lead a Service** (connect to a running session from a handheld, "Connect & drive") | `manage/service-lead.php`; nav `admin-links.php:107` | Same dual gate (`service-lead.php:52-58`) |
| Admin console | **Venues** (prerequisite editor: venues + recurring service times) | `manage/venues.php`; nav `admin-links.php:99` | **`manage_organisations` ONLY — no org-admin branch** (`venues.php:41-44`) — see §2 P3 |
| QR scan | Projection QR encodes `JOIN_BASE + '/?svc_code=<code>'` | `service-projection.php:358-360` (`joinUrlFor`), rendered via `/qr.php` (rule #38) | **Nothing reads `svc_code`** — see §2 P5 |
| Fixed banners | Blue "Following … live" (Live Follow, `live-follow.js:423-449`) vs green "Following the service live" (Service Mode, `service-follow.js:330-355`) | On `<body>`, fixed-position | Followers |
| Help / docs | Two adjacent accordion topics `#help-service-mode` / `#help-live-follow` (`includes/pages/help.php:486-584`); `help/live-follow.md`; `wiki/Live-Follow-&-Service-Mode.md` (#1577) | | |

**The join side is already unified; the start side is not.** The home button tries
`service_join` first and transparently falls back to `live_follow_join`
(`service-follow.js:141-153`) — a congregant never needs to know which feature a code belongs
to. But a *leader* must pick a world before they start: public-app "Go Live" vs admin-console
"Service Projection", with no chooser, no cross-link at start time, and seven different
user-facing labels between them (Go Live, Join Live, Join a live service, Live Follow,
Service Mode, Service Projection, Lead a Service).

### 1.3 Every API endpoint + auth/gating (all in `api.php`)

**Live Follow (`SessionKind='host'`)**

| Action | Line | Method | Auth / gate | Rate limit | Notes |
|---|---|---|---|---|---|
| `live_follow_create` | 16699 | POST | **Authenticated only** | 20/h/user (:16709) | `OrgId` hardcoded NULL (:16723); Channel stamped (:16728); supersedes host's prior session *per channel* (:16759-16772); fixed 6-char code from the no-ambiguity alphabet (:16774-16800); accepts `setlistId` in the body (:16715) **but no client ever sends it** (`live-follow.js:143-146`) — dormant hook |
| `live_follow_update` | 16817 | POST | Auth + `SessionCode+HostUserId` ownership | 600/min | Host broadcasts full context; web host always sends `componentIndex: 0` (`live-follow.js:98` → `initSongPage` → `_broadcast(songId, 0)`) — **song-level only in practice** |
| `live_follow_heartbeat` | 16900 | POST | Auth + ownership | — | 30 s beat + visibility wake-beat (`live-follow.js:196-224`) |
| `live_follow_leave` | 16939 | POST | Auth + ownership | — | Ends the host session |
| `live_follow_join` | 16975 | GET | **Anonymous** (code is the access control) | 120/min/IP | Channel-filtered (:17004); 180 s freshness; mints a *stateless* per-follower `followToken` (:17026) purely to bucket the poll limiter |
| `live_follow_poll` | 17043 | GET | Anonymous | 40/min per token, 600/min/IP fallback (:17059-17070) | Channel-filtered (:17073-17080) |

**Service Mode (`SessionKind='service'`)**

| Action | Line | Method | Auth / gate | Rate limit | Notes |
|---|---|---|---|---|---|
| `service_session_start` | 17109 | POST | Auth + (`admin`/`global_admin` **or** org-admin of the venue's org, :17142-17147) | 30/h/user | Requires `venueId > 0` (:17127) + `occurrenceDate` (:17128). `scheduleId <= 0` = the **venue-scoped ad-hoc path**: placeholder 10:00/90 min (:17154-17167) floored by #1576 so an evening start is never born expired (`service_mode.php:379-414`). Supersedes prior session per occurrence+channel (:17177-17211), retiring its codes (#1621). Inserts `SessionKind='service'` with internal `SVC…` SessionCode (:17217-17235), then mints the first rotating code (:17237) |
| `service_code_rotate` / `service_code_current` / `service_session_end` | 17250-17384 | POST/GET/POST | Auth + (super **or** session host **or** org-admin, :17299-17310) | `service_operator` 600/min/user | Rotate doubles as heartbeat (:17345-17348); end revokes ALL presence + retires codes in one transaction (:17312-17337) |
| `service_broadcast` | 17394 | POST | Bearer operator **or** #1408 delegated `controlToken` (:17451-17474) | probe 300/min/IP + 600/min/user | Sets `CurrentSongId`/`componentIndex`/state; the ONE endpoint both broadcaster front-ends POST |
| `service_join` | 17544 | POST | **Anonymous** (rotating code = proof of presence) | 300/min/IP, **failed joins only** budgeted (:17582-17586, :17618-17630) | Resolves via `serviceMode_resolveJoin()` (ambiguity REFUSED, #1621); upserts `tblServicePresence` per (session, deviceId) (:17658-17680); returns opaque 43-char `presenceToken` + server-declared poll cadence |
| `service_poll` | 17697 | GET | Anonymous, **presence token** | 40/min congregant, 90/min projector, **per token never per IP** (:17713-17737) | rule #26's NAT rule |
| `service_leave` | 17777 | POST | Presence token | — | Immediate gate revocation |
| `service_control_token_mint` | 17817 | POST | Auth + operator + CSRF (`validateCsrfRequest`) | 30/h/user | #1408 delegated co-leader control; table-existence gated |

All state-changing POSTs additionally sit behind api.php's global `X-Requested-With` CSRF
gate (rule #29; noted at `api.php:16696-16697`, `:17106-17107`).

**The ONE helper core** — `includes/service_mode.php` (1,005 lines): `serviceMode_channel()`
(:241), `_generateCode` (:247), `_cleanState` (:295 — the ONE broadcast-state allow-list for
all three writers, #1405), `_occurrenceEndUtc` (:379, DST-aware + #1576 ad-hoc floor),
`_retireSessionCodes`/`_retireExpiredCodes` (:448/:512, #1621), `_mintCode` (:558,
transactional FOR-UPDATE rotate against the globally-unique `uq_ActiveCode`), `_resolveJoin`
(:667, refuses ambiguity), presence-role/poll-cadence helpers (:720-799), and
`_presenceCcliNumber` (:900, the Phase-3 gate read across BOTH org-licence stores, #1668).
Constants: code TTL 75 s (:82), 4 h hard ceiling (:84), 180 s unified freshness (:94, #1386),
ad-hoc 15-min floor (:202).

### 1.4 Shared table, column by column

`tblLiveFollowSessions` (`schema.sql:4071-4106`):

| Column | Live Follow | Service Mode |
|---|---|---|
| `SessionCode` | **The join key** the follower types (fixed 6-char) | Internal spine id only (`'SVC'+8`); congregants never see it — they use `tblLiveFollowJoinCodes` |
| `HostUserId` | NOT NULL in practice (the leader; FK CASCADE) | Set to the starting operator, but sessions are org-anchored; NULL-able by design (`schema.sql:4074`) |
| `OrgId` / `VenueId` / `ScheduleId` / `OccurrenceDate` | NULL / NULL / NULL / NULL | Set (org + venue required; schedule optional) |
| `SessionKind` | `'host'` (default) | `'service'` — filtered in every service query (e.g. `service_mode.php:683`) |
| `Channel` | Stamped since #1405 (`api.php:16728`) and filtered in join/poll | Stamped + filtered everywhere (rule #26) |
| `SetlistId` | Accepted by API, never sent by any client — dormant | Unused |
| `CurrentSongId` / `CurrentComponentIndex` / `StateJson` / `StateRevision` | Shared broadcast state + monotonic revision — the short-poll relay both use | Same |
| `IsActive` / `StartedAt` / `LastHeartbeatAt` / `ExpiresAt` | 4 h rolling expiry on each update/heartbeat | `ExpiresAt` = resolved-UTC occurrence end (capped 4 h) |

Service-Mode-only satellites: `tblLiveFollowJoinCodes` (`schema.sql:4113-4130` — rotating
codes; `ActiveCode` STORED generated column + `uq_ActiveCode` = live codes globally unique,
#1621; rows retired never deleted), `tblServicePresence` (`:4132-4155` — anonymous device
presence; `PresenceToken` CHAR(43) = the Phase-3 gate key; `Role` congregant|projector),
`tblServicePollCounters` (`:4157-4167` — **designed but NOT what rate limiting actually
uses**; enforcement flows through `checkRateLimit()`/`tblLoginAttempts`, and migrating pollers
to `tblReadRateLimit` is an acknowledged follow-up, `api.php:17725-17734` — a planner must not
assume this table is live), `tblSessionControlTokens` (`:4176`, #1408, dormant-gated).
Prerequisite tables for the scheduled path: `tblOrgVenues` (`:4633`) + `tblOrgServiceSchedules`
(`:4663`), edited on `/manage/venues` (#1325).

### 1.5 Dormancy gates and where the features overlap vs diverge

- **CCLI Phase-3 unlock is ENTIRELY DORMANT** behind `tblAppSettings.content_gating_enabled='0'`
  AND needs `require_licence:ccli` restriction rows to do anything (rule #26/#28).
  The injection point is `checkContentAccess(...)`'s presence-token branch
  (`includes/content_access.php:416-422` → `serviceMode_presenceCcliNumber()`); the cookie
  `ihymns_sf_presence_token` is set/cleared by `service-follow.js:365-373` and read by
  `includes/pages/song.php:257-259`, `song-media.php:196-198`, `audio-media.php:148-150`, and
  four api.php payload paths (`api.php:1022`, `:1202`, `:1337`, `:2095`). Following
  already-visible songs needs nothing from the flag.
- **Schema dormancy**: migrations are web-run; both admin pages probe
  INFORMATION_SCHEMA and render a "run the migration" card (`service-projection.php:74-87`,
  `service-lead.php:63-77`), as does `venues.php`. **Paradox worth knowing:** Live Follow itself
  depends on the Service-Mode migration — `live_follow_create` stamps `Channel`, so on an
  un-migrated install **Go Live fails with "Unknown column 'Channel'"** even for a user who
  never touches Service Mode (documented in `wiki/Live-Follow-&-Service-Mode.md` "Prerequisite
  quirk" and `help/live-follow.md:76`).
- **Overlap**: one table, one short-poll revision relay, one state allow-list
  (`serviceMode_cleanState`), one code alphabet, one 180 s freshness window (#1386), one
  unified congregant join button (with fallback), one broadcaster core
  (`js/modules/service-broadcast.js`, consumed by both admin front-ends).
- **Divergence**: auth model (self vs org-admin), code lifecycle (fixed vs rotating+QR),
  sync granularity (song-level in practice vs song+section), presence (stateless follow token
  vs persisted revocable presence row), gating (none vs CCLI unlock), entry surface (public
  PWA vs `/manage/*`), lifetime (rolling 4 h vs occurrence end).

---

## 2. The UX problem, grounded in code

**P1 — Two start worlds, no chooser.** "Go Live" lives inside the public app *on a song page
only* (`live-follow.js:376-421` — you must first navigate to a song to even see it; there is
no start entry on home, nav, or setlists). "Service Projection"/"Lead a Service" live in the
admin console under the **People** nav group (`admin-links.php:99-107`). Nothing at start time
links or contrasts them; the user must already know which product they want — exactly the
complaint in #1770.

**P2 — Capability asymmetry forces the wrong choice.** Everything a real service wants —
section-level driving, the big projected code, the QR, a song-search console, a second
operator device, delegated control tokens, the CCLI unlock — exists ONLY on the org/venue
path. The ad-hoc host gets none of it: the web host broadcasts whatever song *their own
device* is on, always at `componentIndex 0` (`live-follow.js:98`; confirmed in
`wiki/Live-Follow-&-Service-Mode.md` "Broadcast granularity"). So a worship leader without
org setup is pushed toward the heavyweight path not because they need venues but because they
need the *console*. Note the protocol and follower already support section follow
(`live_follow_update` carries `componentIndex`; `live-follow.js:344-368` scrolls to it) — the
gap is purely the host UI.

**P3 — The scheduled path's prerequisite chain is broken for its own target user.** A "pure"
org-admin (no site role) IS admitted to Service Projection and Lead a Service
(`service-projection.php:64-70`, `service-lead.php:52-58`) but gets a hard 403 on
`/manage/venues` (`venues.php:41-44` — `manage_organisations` only, no org-admin branch).
The projection page's own empty state sends them there: *"No venues to run yet. Add a venue +
service times under Venues first"* (`service-projection.php:197`). So the guided path
dead-ends at a 403 for exactly the church operator the feature is for; a site admin must
pre-create org, membership, and venue. (Also the #1587 gate-mismatch class: all three nav
entries advertise `manage_organisations`, so a pure org-admin never *sees* the links they can
legitimately deep-link to.) Full chain for a fresh church today: account → someone creates the
org (the `organisation_create` API exists for any authenticated user, `api.php:7970`, but
**no UI calls it** — grep finds only api-docs + validators) → org-admin membership row → a
site admin creates the venue (+ schedules) → migrations applied on that env → then
Service Projection works.

**P4 — The Channel wall makes the natural first test fail with a misleading toast.** A
session is walled to its docroot channel (`service_mode.php:241-244`; filtered at
`api.php:17004`, `:17073-17080`, `:17600`, `:17739-17752` and every gate/prune query). The
natural "desktop on dev + my phone on www" smoke test therefore ALWAYS fails — with
`"Session not found or ended."` (`api.php:17019`) or `"That code has expired. Check the
screen for the new code."` (`api.php:17629`). Neither toast mentions the real cause; only the
help table does (`help/live-follow.md:80`). Rule #26 records this as the thing that made
Live Follow "look permanently broken". The wall itself is load-bearing (cross-env leak
class) — the fix space is copy/diagnostics, never weakening the filter.

**P5 — The QR is not actually dead-simple joining (live rule #33 violation).** The projection
QR encodes `/?svc_code=<code>` (`service-projection.php:359`) but **nothing anywhere reads
`svc_code`** (repo-wide grep: only the emitter and its comment). A congregant who scans lands
on the home page and must still find "Join a live service", then read the rotating code off
the screen and type it — under a 75 s TTL. The in-code comment (:349-357) acknowledges the
param is "inert today … forward-compatible". Rule #33: honour it or stop emitting it.

**P6 — Three meanings of "ad-hoc".** (a) Live Follow itself (the genuinely ad-hoc feature);
(b) Service Mode's "— ad-hoc (no set time) —" schedule option (`service-projection.php:309`,
the venue-bound-but-unscheduled path with the 10:00/90-min placeholder + #1576 floor);
(c) the owner's "ad-hoc vs scheduled" framing in #1770. A worship leader meets (a) and (b) as
different things with the same name. Also a **stale-doc finding**: `help/live-follow.md:68`
still tells leaders "ad-hoc services are only reliable in the morning for now" — that is the
pre-#1576 behaviour; the floor (`service_mode.php:405-411`) fixed it.

**P7 — Vocabulary sprawl.** Seven labels across two features (§1.2), two banner colours, two
code formats, two help topics — necessary *documentation* of a confusing structure (#1577 did
this well), but the structure itself is what #1770 challenges. The one place the split already
dissolves for users is the join button — proof the unification approach works.

**P8 — Nothing has ever been verified on real devices.** #1339's live multi-device verify has
never once been executed (rule #26 "Genuinely still TODO"); the help-doc failure table
(`help/live-follow.md:72-85`) is effectively the accumulated list of ways people failed to
self-serve test it.

---

## 3. What a coherent rethink looks like — design options

All options must keep: the Channel wall, the rotating-code proof-of-presence for any
CCLI-relevant session, the ONE helper core (`service_mode.php` — extend, never fork), and
dormant-safety (additive, probe-gated, no-op on un-migrated installs). None of the options
below requires new tables; the spine's columns are already nullable in the right places.

### Option A — One "Start a live session" front door; two backends kept (RECOMMENDED)

**Shape.** A single signed-in entry point in the public app (home card + song-page/setlist
affordance): **"Start a live session"** → a two-choice step in plain language:

1. **Quick** *("just my group, right now")* → `live_follow_create` exactly as today, but the
   host lands in a proper **host console** — the existing `ServiceBroadcaster`
   (`js/modules/service-broadcast.js`) mounted against the `live_follow_update` writer — giving
   ad-hoc hosts song search + **section driving** (the protocol already carries it, P2) and a
   **"Show big code" view** (large code + QR via `/qr.php`, rule #38) for anyone who wants to
   project it. Fixed code, no venue, no org, no CCLI — unchanged semantics.
2. **Church service** *("at our venue, with the screen")* → if the user is an org-admin with
   venues: venue/schedule/date picker inline (POST `service_session_start`), then hand off to
   the projection view / Lead-a-Service as today; if they have no org/venue: a guided
   explainer ("ask your church admin" or the P3-fixed self-serve venue setup) **with Quick
   offered as the no-prerequisite fallback**.

Plus the seam fixes that stand alone (they are Option C, subsumed here): honour
`?svc_code=` (prefill + one-tap join), org-admin branch on `/manage/venues`, channel-hint
join-failure copy, stale-help fix, then execute #1339.

- **API changes:** none required. Optional additive: accept `componentIndex` from the new
  host console (already accepted); optionally start sending `setlistId` (already accepted,
  `api.php:16715`).
- **UI changes:** new ES-module wizard (rule #30 — module via `router.afterPageLoad`, inputs
  from `data-*`; NO inline fragment scripts), broadcaster mounted for hosts, big-code view,
  `svc_code` reader in the home-page module.
- **Data model:** unchanged. **Gating:** unchanged (Quick sessions have `OrgId NULL`, so
  `serviceMode_presenceCcliNumber()` can never match — fail-closed by construction).
- **Cost:** M. **Risk:** low-moderate — the wizard must not blur the operator-gate line
  (Quick = self-owned; Service = org-gated), and the host console must reuse, not fork, the
  broadcaster (rule #26).
- **Dormant-safe:** yes — purely additive UI over shipped endpoints.

### Option B — Collapse to one feature: Quick = a venueless `service` session

**Shape.** Make every session a Service-Mode session. A new thin `service_quick_start`
(or a relaxed `service_session_start`) creates `SessionKind='service'` with
`OrgId/VenueId/ScheduleId NULL`, host-gated like Live Follow; congregants join through the ONE
`service_join` path; `live_follow_*` become deprecated aliases kept for old clients
(links outlive code — rule #33's alias lesson).

- **API:** new start action; `serviceMode_resolveJoin()` already matches any
  `SessionKind='service'` row; operator gates extend "host == user" (already present in the
  rotate/end gate, `api.php:17300`).
- **Data model:** none — all needed columns are already NULL-able (`schema.sql:4074-4078`).
- **Design decision embedded:** quick sessions should keep a **fixed** code (rotation is
  proof-of-presence machinery whose only payoff is the CCLI unlock; a rotating code makes
  voice/text sharing worse). That means the collapsed feature still has two code lifecycles —
  i.e. the user-visible simplification is smaller than it looks.
- **Cost:** L (client migration, alias upkeep, both native apps' Live-Follow surfaces, docs,
  and re-verifying every rate-limit/gate query against the new NULL-org population).
- **Risk:** highest — touches the presence/CCLI gate population (`tblServicePresence` rows
  with NULL org must provably never unlock; today `_presenceCcliNumber`'s INNER JOIN on
  `tblOrganisations` fails closed, which must be preserved as a tested invariant), and it
  rewrites a shipped, never-live-verified feature before #1339 has ever run once.
- **Dormant-safe:** achievable but with the largest verification surface.

### Option C — Keep both, fix the seams (minimum credible)

Just the standalone fixes: (1) a plain-language **chooser** dialog/page that routes to
Go Live vs Service Projection with one comparison table (the #1577 table, in-product);
(2) `?svc_code=` honoured (rule #33) — scan → join prompt pre-filled → one tap;
(3) `/manage/venues` org-admin branch (P3) + nav-gate alignment (#1587 class);
(4) channel-mismatch hint in the two join-failure toasts (kept opaque: "make sure you're on
the same iHymns address as the leader" — never distinguishing wrong/expired/ambiguous);
(5) `help/live-follow.md:68` staleness fix; (6) run #1339.

- **Cost:** S–M. **Risk:** minimal. **Dormant-safe:** trivially.
- **What it does NOT fix:** P2 (ad-hoc hosts still get no console/sections/QR) and P7 (two
  mental models persist, merely signposted). The owner's stated goal ("one clear starting
  point… hide the technical machinery") is only half met.

**Recommendation: A, staged as C-then-A** (C's items are A's first commits and are
independently shippable). B is the eventual internal convergence to *consider* only after A
has proven the unified UX and #1339 has actually run on the current spine; collapsing before
the first real-device verify would be rewriting an unverified feature.

---

## 4. Open questions that genuinely need the OWNER

**Q1 — Should Quick (ad-hoc) sessions gain the full driving experience (section control,
song-search console, big-code/QR view)?**
*Why it needs deciding:* it is a product-positioning call — is Live Follow deliberately
minimal ("follow me as I navigate"), or was its minimalism just build order?
*Options:* (a) full parity via the shared broadcaster — one experience, ad-hoc default
(Option A); (b) keep Quick minimal, invest only in signposting (Option C); doing nothing
keeps pushing venue-less leaders into the org path for the console.
*Recommendation:* **(a)** — the follower side already supports sections, the console already
exists and is shared (rule #26 says reuse), and it directly answers "worship leaders won't
always be technically minded".
*Smallest reply:* "parity" or "keep quick minimal". **Blocks** the A-vs-C choice.

**Q2 — May org-admins create and manage their own venues (org-admin branch on
`/manage/venues`), or is venue creation deliberately site-admin-only?**
*Why:* the scheduled path currently 403s its own target user at the prerequisite step
(§2 P3) — but venue data feeds the CCLI-relevant occurrence window, so widening write access
is a trust call.
*Options:* (a) org-admins manage venues for their own orgs (mirrors the
projection/lead gate); (b) keep site-admin-only and make the projection empty-state say
"ask your site administrator" instead of linking a 403.
*Recommendation:* **(a)** — the same people are already trusted to run live sessions and
mint presence against those venues.
*Smallest reply:* "a" or "b". **Blocks** the guided-setup branch of the wizard; nothing else.

**Q3 — Should the QR deep-link auto-join, or pre-fill with one tap to confirm?**
*Why:* auto-join is the maximum "dead simple" but executes a network join as a side effect
of loading a URL (re-scans, link previews, shared links).
*Options:* (a) prefill + one-tap "Join"; (b) full auto-join; (c) leave inert (status quo =
standing rule #33 violation).
*Recommendation:* **(a)** — one tap keeps intent explicit, avoids join-on-prefetch, and
respects the anti-probe posture (a failed auto-join would fire opaque errors at people who
merely followed a stale link).
*Smallest reply:* "prefill" or "auto". Does **not** block — (a) is the defensible default and
trivially changeable.

**Q4 — One user-facing vocabulary?** Adopt "Live session" with **Quick** vs **Church
service** (or similar) as the ONLY user-facing terms, keeping `Live Follow`/`Service Mode`,
`SessionKind`, endpoint names etc. internal — the exact copy-only relabel precedent of
Catalogue→Collection (rule #24, #945).
*Options:* (a) yes, copy-only relabel everywhere user-facing (help, buttons, wiki);
(b) keep current names with better explanations.
*Recommendation:* **(a)** — the names are the confusion; the internals are fine.
*Smallest reply:* approve/adjust the two words. Does **not** block implementation, only copy.

**Q5 — (Only if Option B is ever chosen) fixed or rotating codes for Quick sessions?**
*Recommendation:* fixed — rotation exists solely as proof-of-presence for the CCLI unlock,
which Quick sessions can never grant (`OrgId NULL`). *Smallest reply:* "fixed". Not blocking
now; recorded so a future collapse doesn't inherit rotation by default.

None of Q1-Q5 blocks starting Option C's seam fixes or scheduling #1339.

---

## 5. Constraints and landmines the plan must respect

1. **The Channel wall is load-bearing, not a bug.** Every new/changed join/poll/broadcast/
   gate/prune query MUST filter `Channel` (rule #26; the correlated-EXISTS shape in
   `service_mode.php:448-537` is the pattern). Cross-docroot joining can never work; the fix
   for P4 is copy/diagnostics, never a filter relaxation. Any new session-creating endpoint
   must stamp `serviceMode_channel()` at insert (the #1405 regression class).
2. **Rate limiting: per presence/follow token, NEVER per IP, for congregant-scale paths**
   (a NAT-shared congregation is one IP — `api.php:17705-17737`, `:17050-17070`). Per-IP is
   correct only for pre-auth probe caps (`service_broadcast_probe`, `:17413-17423`) and the
   failed-joins-only `service_join` budget (`:17552-17586`). Do not migrate pollers to
   `enforceReadRateLimit()` until it takes an explicit key (`:17725-17734`); do not assume
   `tblServicePollCounters` is live — it isn't.
3. **CCLI Phase-3 dormancy must survive any redesign untouched:** dormant behind
   `content_gating_enabled='0'` + `require_licence:ccli` rows; unlock only via
   `checkContentAccess`'s presence branch (`content_access.php:416-422`); presence tokens
   revocable (`IsActive=0`) and occurrence-end-expiring; a Quick/ad-hoc session must keep
   `OrgId NULL` so `_presenceCcliNumber()`'s org join fails closed — make that a tested
   invariant if session kinds are touched. Never gate presence on geolocation (spoofable —
   the rotating venue code IS proof-of-presence, `venues.php` header + strategy doc Reframe 2).
4. **One helper core, one state allow-list, one broadcaster.** Extend
   `includes/service_mode.php`; a 4th broadcast writer reuses `serviceMode_cleanState()`
   (`:258-295`); any host console reuses `js/modules/service-broadcast.js` — re-forking any of
   these is the exact regression rule #26 bans.
5. **Rotating-code discipline (#1621):** live codes are globally unique via the `ActiveCode`
   generated column; retirement is a Status flip, **never** a DELETE (`service_mode.php:57-62`);
   code-space safety depends on retirement — any new end/supersede path must call
   `serviceMode_retireSessionCodes()`.
6. **#1576 semantics:** the ad-hoc floor applies ONLY to placeholder-time sessions; a real
   schedule keeps its honest end ("expired" is the correct answer for a past occurrence,
   `service_mode.php:360-365`). A wizard that invents times must not accidentally convert
   honest-scheduled into floored-ad-hoc. Also fix the stale `help/live-follow.md:68` claim.
7. **Front-end rules:** any new public-app UI is a real ES module wired from
   `router.afterPageLoad()` reading `data-*` (rule #30 — fragments cannot carry executable
   inline scripts; the admin pages' inline modules are fine because they own their `<head>`);
   fixed-position banners/bars tear down unconditionally on every navigation (rule #32 — the
   two existing banners already follow this; a unified banner must too); same-origin calls via
   `apiFetch` (rule #31); event names in `constants.js` (#1581); state-changing AJAX under
   `X-Requested-With`/`validateCsrfRequest()` (rule #29).
8. **URL params are contracts (rule #33):** honour `?svc_code=` or stop emitting it; if the
   wizard emits new deep links (e.g. `/manage/service-projection?venue=`), the destination
   must read them and `tests/` should derive them from the tree (rule #34 — mutation-tested,
   tree-derived guards).
9. **QR only via `/qr.php` (CueRCode, rule #38):** server-side, secret key never
   client-visible, degrades to the typed code on 503 (dormant until the CueRCode key is
   provisioned on `/manage/configuration`). No client-side QR library, ever.
10. **Anti-probe opacity:** join failures stay one message for wrong/expired/unknown/
    ambiguous (`api.php:17607-17630`, `service_mode.php:659-664`); a channel hint may be added
    to that one message but must not create distinguishable failure classes.
11. **Gate/nav coherence (#1587 class):** if org-admins get venue access (Q2), the
    `admin-links.php` entitlement and each page gate must express the same check; today the
    three nav entries advertise `manage_organisations` while two pages admit org-admins.
12. **#1339 is the acceptance test:** the live multi-device verify (two real devices, one
    channel) has NEVER been executed. Whatever design ships, #1339 runs against it before the
    UX is declared fixed — and the analysis above (P4) explains the test-setup trap that
    defeated previous attempts (both devices must be on the SAME docroot).
13. **Schema:** none of options A/C needs migrations. If any future column IS needed, rule
    #19/#20 applies in full (one-pass forward-looking DDL, schema.sql mirror, ONE
    migration-registry entry, honest probe, VARCHAR-not-ENUM).
14. **Docs to update with any change** (#1577 set): `includes/pages/help.php` two topics,
    `help/live-follow.md`, `wiki/Live-Follow-&-Service-Mode.md`, plus the Apple `HelpView`
    noted there.

---

## Owner direction (2026-08-05, during the session) — CAPTURE, not yet built

The owner steered #1770 with these concrete requirements. They REPLACE the need
to guess at Q1 (quick-session capability): the answer is "Quick should be
capable", with specifics:

1. **Quick "Go Live" — session persists across songs.** The leader must be able
   to start a session on one song and then **navigate to another song with the
   session staying open**, followers moving with them. If today's `live_follow_*`
   closes/loses the session on song change, that is a bug to fix. (Design: the
   session lives on the leader's *session id*, not the song; each song view
   re-broadcasts `CurrentSongId`. Verify the follower poll re-resolves the new
   song.)

2. **Quick "Go Live" — ~15-minute leader-idle auto-close.** While a quick session
   is open, if the **leader doesn't interact with iHymns for ~15 min**, the
   session auto-closes (frees followers, stops the CCLI unlock in #3). Design: a
   `LastLeaderSeenAt` heartbeat on the session + a prune that closes stale quick
   sessions (mirror the Service Mode `_occurrenceEndUtc` hard-cap prune, but
   idle-based). Timeout value is owner-tunable; 15 min is the starting default.

3. **CCLI unlock from the HOST's licence to followers (Quick sessions).** If the
   signed-in leader has a CCLI number on **their own user account OR one of their
   organisations**, that unlocks CCLI-copyrighted songs for **everyone following**
   their session, for **as long as the session is active and they are following**.
   (Extends the Service-Mode Phase-3 presence-CCLI unlock, rule #26, to the
   host-licence, venueless case.)
   ⚠️ **Licensing flag raised to owner:** a CCLI licence generally requires the
   followers to be *physically present at the licensed venue/congregation*. In
   Service Mode the venue rotating code IS that proof-of-presence (owner accepted
   this basis, #1324). A Quick session has **no venue / no proof-of-presence**, so
   unlocking CCLI for anyone with the follow link may exceed the licence's terms.
   Owner asked for it regardless — this is the owner's licensing call, recorded
   here. Implementation should still bind the unlock to (a) an active session and
   (b) an actively-following presence token, and stop it the instant the session
   closes or following stops, to keep the exposure as narrow as the mechanism
   allows.

4. **Service Mode — ProPresenter as the operator console.** The intent behind
   Service Mode is for **ProPresenter to dynamically drive the songs** (act as the
   broadcaster/console): when the operator advances a song/section in ProPresenter,
   the live iHymns session's `CurrentSongId` follows. Design surface: a
   ProPresenter-facing broadcast endpoint (API-key or session-scoped token) that
   calls the SAME `service_broadcast` core both existing front-ends use (rule #26
   — one broadcaster core, never re-forked). Likely a small adapter to
   ProPresenter's network/stage-display or webhook protocol; needs its own
   analysis pass.

**Consequence for the design options:** this lands us on **Option A** (one front
door, two backends), with the Quick backend gaining real capability (persist +
idle-timeout + host-CCLI unlock) and the Service backend gaining a ProPresenter
driver. B (full collapse) and the "keep Quick minimal" reading of C are ruled out
by the above. Next step when #1770 is scheduled: a Fable planning pass over these
four requirements (schema deltas — `LastLeaderSeenAt`, host-CCLI resolution;
endpoints; the ProPresenter adapter), then Sonnet implementation, all
additive/dormant per house style.

5. **Quick-session idle timeout is a CONFIGURABLE HIERARCHY (owner, 2026-08-05).**
   The ~15-min auto-close (req. #2) is not a fixed constant — it resolves through
   a precedence chain, most-authoritative first:
   - **App (global) admin** sets the application default (15 min out of the box)
     — an `tblAppSettings` key, e.g. `live_follow_idle_timeout_minutes`.
   - **Organisation** may **override** the app default for its members, AND may
     **enforce** it (lock it) so members cannot change their own — but only when
     the org has turned enforcement on. (Two org columns: the value + an
     `EnforceIdleTimeout` flag.)
   - **User** may set their own timeout — HONOURED only when their org is not
     enforcing one.
   Resolution: `org.enforce ? org.value : (user.value ?? org.value ?? app.default)`.
   Stored as minutes; all three layers are additive/dormant columns/settings
   (rule #20). This mirrors other iHymns per-user/per-org/per-app precedence
   (e.g. card layout, language prefs) — reuse that pattern, don't re-fork it.

6. **DECISION on Q1 (owner, 2026-08-05):** Quick "Go Live" gets the **full
   operator console as an OPTION — not mandatory.** A leader can drive songs by
   section from the console if they want, but a bare "everyone follow my current
   song" Quick session stays valid with no console interaction required. And
   (confirming req. #3) the **host-CCLI unlock DOES apply to Live Followers in a
   Quick Go Live session** — same mechanism as Service Mode, keyed off the
   host's own user/org CCLI licence, active only while the session is live and
   the follower is following. → This lands the design firmly on **Option A**
   (one front door, two backends; Quick backend gains optional-console +
   host-CCLI unlock + persistence + configurable idle-timeout). Option B/C are
   closed. Remaining pre-build sub-decisions (media copy scope, ProPresenter
   protocol specifics) are implementation details with defensible defaults, to
   be settled in the planning pass — none block starting #1770 when scheduled.
