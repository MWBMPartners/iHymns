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

**Broadcast granularity.** The web host only ever broadcasts at the song level (`componentIndex` is always `0`) — there's no verse/section control in the web host UI today. A follower's client *will* scroll to a broadcast section if one is ever sent (the protocol supports it), but the current web host simply never sends one. Section-level follow is Service Mode's differentiator, not a Live Follow limitation waiting to be lifted — see the broadcaster note below.

**Prerequisite quirk.** Even though Live Follow needs no venue, its session-create INSERT stamps a `Channel` column, and join/poll both filter on it. That column only exists once the Service Mode schema (below) has been applied — so on an un-migrated install, **Go Live fails** with an "Unknown column 'Channel'" error even for someone who never touches Service Mode at all.

### Technical notes

- Heartbeat: host beats every 30s, plus an immediate beat on `visibilitychange`/`focus` (recovers a throttled/backgrounded tab).
- Freshness: join/poll require a heartbeat within the last **180 seconds** (`LIVE_SESSION_FRESHNESS_SECONDS`, unified across both features by #1386 — it used to be split 180s/90s, which is why an older stale-comment reference to "90s" survived on `service-lead.php` until #1386's follow-up cleaned it up).
- Session lifetime: hard-expires after **4 hours**. Also ends on an explicit **End**, a sign-out (the host's session can no longer authenticate), or starting a *new* Go Live session (which supersedes the old one and mints a brand-new code).

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
- Issues: [#1268](https://github.com/MWBMPartners/iHymns/issues/1268) (Live Follow), [#1323](https://github.com/MWBMPartners/iHymns/issues/1323) / [#1335](https://github.com/MWBMPartners/iHymns/issues/1335) (Service Mode), [#1576](https://github.com/MWBMPartners/iHymns/issues/1576) (known bug — an ad-hoc service started in the evening can be born already expired; not fixed as of this writing)
