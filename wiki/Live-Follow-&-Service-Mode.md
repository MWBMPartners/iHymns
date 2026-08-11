# Live Follow & Service Mode

> Two distinct real-time "follow the leader" features that share one database table and one join dialog — and have been mistaken for each other in every failed test of either feature so far.

---

## Overview

| | **Live Follow** (#1268) | **Service Mode** (#1323 / #1335) |
|---|---|---|
| Who starts it | Any signed-in user, from a song page | A church admin, from the admin console |
| Requires | Sign-in only — no venue, no schedule, no org, no licence | An org + venue + occurrence date + org-admin rights |
| Code | Fixed 6 chars, shared by a person | Rotates ~every 30s, shown on the venue screen |
| Who can join | Anyone, no account | Anyone, no account |
| Syncs | Song only | Song **and** section |
| Banner colour | Blue "Following … live" | Green "Following the service live" |
| Lifetime | 4h hard ceiling | Until the service's occurrence end (4h ceiling) |

Both features are built on the same schema (`tblLiveFollowSessions`, `tblLiveFollowJoinCodes`) and the same generic short-poll relay — `SessionKind` (`host` vs `service`) is what tells them apart server-side. They are documented together for that reason, but a reader should come away treating them as two products, not one feature with two modes.

---

## Live Follow (#1268)

**Host flow.** `api.php`'s `live_follow_create` only checks that the caller is authenticated — there's no venue, org, or licence check. `OrgId` is hard-coded `NULL` for v1 (org scoping is a deferred concept here). The web host UI (`js/modules/live-follow.js`) only renders the **Go Live** button when a user is signed in; signed-out users see just **Join Live**. This is the most common support question — "I can't find Go Live" almost always means "I'm not signed in."

**Join / poll.** Both are anonymous `GET`s — no auth required to follow a code.

**Code format.** Drawn from the alphabet `ABCDEFGHJKMNPQRSTVWXYZ23456789` — no `I`, `L`, `O`, `U`, `0`, or `1`, so a misread character is never ambiguous with a real one. Entry is deliberately forgiving: the client uppercases and strips non-alphanumeric characters before validating, and the server repeats the same normalisation, so a code typed with spaces, dashes, or mixed case still matches.

**Broadcast granularity.** The web host still auto-broadcasts at the song level on every navigation (`componentIndex` defaults to `0`) — but since #1770 (below), an OPTIONAL "Console" panel lets the host also pick a song and step between verse/chorus without leaving whatever page they're on, reusing the SAME `ServiceBroadcaster` module Service Mode's operator console uses, via a transport adapter (never a fork). Section-level follow was Service Mode's differentiator; #1770 brings the same capability to Live Follow as an opt-in, not a forced UI change to the default song-follow flow.

**Prerequisite quirk.** Even though Live Follow needs no venue, its session-create INSERT stamps a `Channel` column, and join/poll both filter on it. That column only exists once the Service Mode schema (below) has been applied — so on an un-migrated install, **Go Live fails** with an "Unknown column 'Channel'" error even for someone who never touches Service Mode at all.

### Technical notes

- Heartbeat: host beats every 30s, plus an immediate beat on `visibilitychange`/`focus` (recovers a throttled/backgrounded tab).
- Freshness: join/poll require a heartbeat within the last **180 seconds** (`LIVE_SESSION_FRESHNESS_SECONDS`, unified across both features by #1386 — it used to be split 180s/90s, which is why an older stale-comment reference to "90s" survived on `service-lead.php` until #1386's follow-up cleaned it up).
- Session lifetime: hard-expires after **4 hours**. Also ends on an explicit **End**, a sign-out (the host's session can no longer authenticate), or starting a *new* Go Live session (which supersedes the old one and mints a brand-new code).

---

## #1770 — persistence, idle auto-close, host-CCLI unlock, external drive

Issue [#1770](https://github.com/MWBMPartners/iHymns/issues/1770) reworked the Live Follow UX around
seven owner-directed requirements. Server foundation (schema + resolution logic) and client/UI/docs
landed together in one PR; see `.claude/live-follow-1770-plan.md` for the full design.

**Persistent host bar (req #1).** A fixed red **LIVE** bar now stays pinned to the bottom of *every*
page while a device is hosting — not just the song page the leader broadcast from. It carries
**Show code** (a large code + QR — see "QR deep link" below), **Console** (the optional
section-driving panel described above) and **End**. This makes the pre-existing cross-page
persistence (the host code has always survived navigation in `sessionStorage`) actually *visible*;
previously the only affordance was the inline badge on the one song page the leader happened to be
on.

**Leader-idle auto-close (req #2/#5).** A session now closes itself after a stretch with **no
genuine leader interaction** — the automated 30s heartbeat keepalive does **not**, by itself, count;
only a real `pointerdown`/`keydown` while hosting, a song/section broadcast, or session creation
resets the clock. This fixes the "an abandoned-but-still-open tab hosts forever" gap. The timeout is
resolved via a three-layer precedence chain, each layer optional, resolved and **stamped on the
session at creation** (not re-resolved per request):

1. **App default** — `tblAppSettings.live_follow_idle_timeout_minutes` (`/manage/configuration`,
   "Live Follow" card), falling back to a hardcoded 15 minutes.
2. **Organisation override** — `tblOrganisations.LiveIdleTimeoutMins` +
   `EnforceIdleTimeout` (`/manage/organisations` for a site admin, `/manage/my-organisations` for an
   org admin). `EnforceIdleTimeout=1` LOCKS the value for every member (their own preference is
   ignored); across several enforcing orgs the *minimum* value wins. A non-enforcing org value is
   only a *default*, beaten by a user's own preference.
3. **User preference** — a `/settings` field (`liveIdleTimeoutMins` in the synced
   `tblUsers.Settings` JSON blob), which beats a non-enforcing org default but loses to an enforcing
   one.

Resolution formula: `enforced ?? (user ?? (orgDefault ?? appDefault))`, clamped to **5–240 minutes**
(240 = the pre-existing 4-hour hard session ceiling, so "never" needs no separate option). On
idle-close: `IsActive=0`, any presence rows for the session are revoked (see below), and the next
`live_follow_update`/`_heartbeat`/`_join`/`_poll` from that session answers exactly as if the
session had been ended normally (409 / `ok:false` / 404 / `active:false`) — a follower or the host's
own next action can't tell "ended" from "timed out," by design (anti-probe opacity).

**Host-CCLI unlock (req #3/#6).** A Quick host's followers can now read gated (copyrighted) lyrics
riding the HOST's own CCLI licence — personal `CcliNumber`, or a licence from any organisation they
belong to — for the life of the session, the same way a Service Mode congregant rides their venue
org's licence. Mechanically: joining now optionally mints a `tblServicePresence` row (an additive
`POST` mode on `live_follow_join`, alongside the byte-identical legacy `GET`) and the client sets the
SAME `ihymns_sf_presence_token` cookie Service Mode already uses. `serviceMode_presenceCcliNumber()`
tries the org-anchored branch first (unchanged — Quick sessions have `OrgId` hardcoded `NULL` so it
can never match one), then falls through to a host-licence branch. **Entirely dormant** behind
`content_gating_enabled='0'` + needing `require_licence:ccli` restriction rows — a Quick session
has no venue and therefore no physical proof-of-presence, so this trades a narrower binding
(active + heartbeat-fresh + idle-fresh + a live-resolved licence, revoked instantly on
leave/idle-close/host-restart) for the same licensing basis Service Mode already relies on; the
owner accepted this basis explicitly (2026-08-05).

**External presentation-app driver (req #4/#7).** A NEW `service_drive` API action lets an
out-of-repo automation (a ProPresenter-class shim, a Companion webhook, a curl loop) drive a
church's *Service Mode* session — advancing song and section — authenticated by a durable,
org-scoped `tblServiceDriverKeys` credential instead of a login. An org-admin mints/lists/revokes
keys from a **"Presentation-app control"** card on `/manage/service-projection` (Label + optional
venue narrowing + protocol). The write path is the SAME `serviceMode_applyBroadcast()` core
`service_broadcast` already uses — `service_drive` is simply its second caller, never a fork. A
free-text section label ("Verse 2", "Chorus", a bare "2") resolves against the song's own
render-order arrangement; an unresolvable one falls back to song-level broadcast, never a guess.
`songRef` (free-text title resolution, for a shim that doesn't know iHymns SongIds) is deliberately
**not yet supported** — the endpoint answers `422` so a shim fails loud; a ProPresenter-specific
protocol shim is tracked as a separate spike, out of this issue's scope.

**QR deep link (`?svc_code=`).** Both the Service-Projection join QR (#1339, live since before
#1770) and the Quick host's own "Show code" view now point at `/?svc_code=<CODE>` — and, as of
#1770, that param actually gets READ: `service-follow.js`'s boot sequence checks for it, validates
the code with the same fold a typed one goes through, and opens a **one-tap confirm** prompt (never
an automatic join — a link preview or accidental tap must not silently drop someone into a live
session). Before #1770 the projection QR emitted a param nothing in the tree read — the classic
"deep link with no destination" failure (rule #33), invisible because the page still opened and
looked fine, it just never did the one useful thing.

---

## #1798 — declared session length + extend

Issue [#1798](https://github.com/MWBMPartners/iHymns/issues/1798) added an explicit **session length**
to Live Follow so a genuinely quiet stretch (the sermon gap) doesn't trip the #1770 idle auto-close.

**Declaring a length at Go Live.** When a host taps **Go Live** they choose how long to keep the
session live — 30 minutes, 1 hour, 2 hours, or until they end it — passed as
`live_follow_create`'s additive `idleTimeoutMins` param (clamped 5–240; "until you end it" resolves to
the 240-minute / 4-hour hard ceiling). Omitting it falls back to the #1770 app→org→user resolver, so
this is purely an optional per-session override.

**Extend, live.** The red **LIVE** bar carries an **Extend** control that re-opens the same picker
mid-service via the new **`live_follow_extend`** endpoint (POST, authenticated, `X-Requested-With` +
`validateCsrfRequest` CSRF, rate-limited). Extending **resets the idle clock** — the full new window
starts from that moment — so a leader whose phone died can pick up where they left off.

**Extend on behalf.** An org admin can extend a *member's* live session for them from a **"Members'
live sessions"** panel on `/manage/my-organisations`, using the same `live_follow_extend` core (the
endpoint accepts an org-admin requester acting on another user's session, not just the host).

**Un-migrated degrade.** Session length lives on the additive `tblLiveFollowSessions.IdleTimeoutMins`
/ `LastLeaderSeenAt` columns. On an install that hasn't run the `migrate-live-follow-quick-capable`
card yet, `live_follow_extend` answers **`409`** with "this install has not been migrated for Live
Follow session lengths yet" (distinct from a `404` wrong-code, rule #35), and `live_follow_create`
simply omits the override — never a STRICT-mode fatal (rule #9).

Service Mode needs none of this: it's bounded by the scheduled service's own occurrence end, never an
idle clock.

---

## Service Mode (#1323 / #1335)

**Start.** Requires the caller to be signed in **and** either an admin/global-admin, or an org-admin of the venue's own organisation. The endpoint validates a real `venueId > 0`, a well-formed `YYYY-MM-DD` occurrence date, and that the venue actually exists — each with its own distinct error message ("Missing venue.", "Invalid occurrence date.", "Unknown venue.").

**Rotating codes.** Each minted code lives **75 seconds** (`SERVICE_MODE_CODE_TTL_SECONDS`); the projection screen rotates to a new one every **30 seconds**. Both the **current** and the **immediately previous** code are accepted on join/poll, so a congregant typing a code just as it rotates isn't punished for the race.

**Operator UI.** Two admin-console entry points, both driving the same broadcaster core (`js/modules/service-broadcast.js`):
- `/manage/service-projection` — start and project a full-bleed rotating code plus a "Drive songs" console, meant for a venue laptop/screen.
- `/manage/service-lead` — connect to an already-running session and drive it from a phone, for a leader who wants to walk the floor instead of standing at the projection laptop.

**Congregant join.** The home page's green **Join a live service** button (`includes/pages/home.php`) is the one discoverable entry point for congregants, and it's deliberately unified: it tries the code as a Service Mode code first, and transparently falls back to a Live Follow join if that fails (`js/modules/service-follow.js`). A congregant never needs to know which feature a code belongs to.

**Sync granularity.** Unlike Live Follow, Service Mode syncs **both** the current song and the current section (verse/chorus/etc.) — the broadcaster and follower both carry a `componentIndex` that actually gets driven by the operator UI, not just carried through the protocol unused.

**Content gating.** `content_gating_enabled` only matters for the still-dormant copyrighted-lyrics unlock (Phase-3 CCLI). Following along with songs that are already publicly visible needs nothing from that flag.

---

## Prerequisites

Service Mode (and, by the `Channel`-column quirk above, Live Follow too) needs two one-off setup steps applied via `/manage/setup-database` before either can work on a given install:

1. **"Org Venues & Service Schedules" (#1325)** — creates the venues/org-venue/schedule tables Service Mode reads from.
2. **"Service Mode sessions" (#1335)** — creates `tblLiveFollowSessions` / `tblLiveFollowJoinCodes` (and the columns Live Follow's `Channel` stamp depends on). Runs after #1. Idempotent — safe to re-run.

Both cards are OR-probed, so the dashboard's pending count only clears once each is genuinely fully applied. An org additionally needs at least one active **venue** (`/manage/venues`) before "Start & project" or "Connect & drive" will find anything to run.

---

## Environments

`dev.ihymns.app`, `beta.ihymns.app`/`www.ihymns.app` (and any other subdomain) all share **one** MySQL database, but each is stamped with its own `Channel` value, and every join/poll/broadcast/gate/prune query filters on it. In practice: **sessions never cross environments.** A leader on `dev` and a follower on `www` will never find each other, no matter how correct the code is — this is the single most common real-world failure mode ("Session not found" while the leader's own screen still says LIVE) and the fix is always "put both devices on the exact same address," never a code or account problem.

**#1792 — the join now names this case.** Rather than the opaque "not found", both `service_join` and `live_follow_join`, when their channel-scoped resolve misses, call `serviceMode_codeOnOtherChannel()` — an existence probe over the *other* channels (both the Quick `SessionCode` and the Service rotating-code families) — and, on a hit, return **"That code belongs to a different iHymns environment … make sure both devices are using the same web address"** (the one `SERVICE_MODE_WRONG_CHANNEL_MESSAGE` constant). This does **not** weaken the anti-probe opacity (rule #26 I6): it fires only for a code the caller already holds validly *somewhere else*, so it reveals nothing about a current-channel session's liveness, grants no access, names no specific environment, and rides the same per-IP join rate limit. Guard: `tests/php/test-live-follow-cross-channel.php`.

This is the one place in this feature's docs where naming the underlying mechanism (`Channel`) is appropriate — everywhere else (help docs, in-app copy) it's described only as "the same iHymns web address."

---

## Native apps

The Apple app's `.live` tab (`LiveHubView`) surfaces both features from one screen:
- **"Join with a Code…"** (a true ellipsis character, matching the web copy's tone) — the same unified join sheet pattern as the web's "Join a live service" button; it doesn't ask which kind of code you have.
- A **"Go Live"** section, visible only to signed-in users, to host a Live Follow session.
- **"Sign in to lead a live session."** in place of Go Live when signed out — an honest footer line rather than a dead button.
- tvOS's Live tab is **follower-only** — there is no native Service Mode *operator* console; that stays web-only (the responsive admin pages already serve it, and native operator consumers are tracked separately).

Four App Shortcuts / Siri phrases are wired up (`IHymnsAppShortcuts.swift`):
- "Go live in iHymns" / "Start a service in iHymns" → `StartServiceIntent` (hosts a Live Follow session, requires sign-in, speaks the join code back).
- "Join a service in iHymns" / "Join with a code in iHymns" → `JoinServiceByCodeIntent` (joins either kind of session by code, no sign-in needed).

---

## See also

- [[Troubleshooting & FAQ]] — the condensed, user-facing symptom list
- [[PWA Features]] — where this sits among the rest of the web app's features
- Issues: [#1268](https://github.com/MWBMPartners/iHymns/issues/1268) (Live Follow), [#1323](https://github.com/MWBMPartners/iHymns/issues/1323) / [#1335](https://github.com/MWBMPartners/iHymns/issues/1335) (Service Mode), [#1576](https://github.com/MWBMPartners/iHymns/issues/1576) (known bug — an ad-hoc service started in the evening can be born already expired; not fixed as of this writing), [#1770](https://github.com/MWBMPartners/iHymns/issues/1770) (persistent host bar, leader-idle auto-close, host-CCLI unlock, external presentation-app driver), [#1798](https://github.com/MWBMPartners/iHymns/issues/1798) (declared session length + `live_follow_extend` + extend-on-behalf), [#1339](https://github.com/MWBMPartners/iHymns/issues/1339) / [#1792](https://github.com/MWBMPartners/iHymns/issues/1792) (the still-outstanding live two-device verify — needs two real devices on one channel, never yet executed)
