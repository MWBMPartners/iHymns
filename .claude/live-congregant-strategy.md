# Live Congregant ("Service Mode") — feasibility + phased design (DRAFT)

> Draft strategy doc (2026-06-21). Owner decision pending — nothing built, no issues filed.
> Extends the worship-team **Live Follow** (#1268) to the whole congregation, with
> org-defined venues + recurring service times as the foundation. Research: workflow `w1a8x3nsl`.

## Verdict

**Feasible as a phased build — and we already own most of the spine (Live Follow #1268,
organisations + CCLI licence, content-gating, the licence layer's time-boxed-unlock model).**
BUT the *headline* — "auto-unlock copyrighted lyrics on a congregant's personal device because
they're physically present" — is **BLOCKED-ON-EXTERNAL: it turns on CCLI's answer, not on code.**
Two honest reframes fall out of the research, and they happen to point at the *same* better design:

### Reframe 1 — CCLI coverage keys on WHO displays, not on who's present
CCLI's Church Copyright License is written around the **church reproducing/displaying** lyrics
"to assist congregational singing" (projection, print). Nothing in the public terms explicitly
authorises **or** forbids pushing lyrics to each member's **own** device — it's a genuine grey
zone, and **"they're in the room" is your intuition, not a CCLI category**. The central risk is
the licence's **non-transferable / no-sublicense** clause: if CCLI reads "lyrics on a congregant's
installed app" as the church *authorising a third party* to display, the church's licence would
**not** cover it. **Silence is not permission.** → **Do not ship the auto-unlock on assumption.**

### Reframe 2 — geolocation can't prove "in this room for this service"; a venue code can
Browser geolocation is the **wrong primary gate**: trivially spoofable (DevTools Sensors / one-click
extensions, no native defence), **35–100 m indoors** (can't tell the car park or the back-to-back
congregation in the *same building* apart), permission-friction, and the heaviest privacy/GDPR
option. The robust signal is a **venue-displayed, server-minted, short-lived rotating QR + typeable
code** on the projection screen: *you can only read it if you're in the room*, so possession of a
current code **is** proof-of-presence — and it disambiguates shared/back-to-back buildings **for
free**. Geolocation/SSID/BLE can only ever *reduce friction*, never unlock.

→ The org's lat/lng + service schedule become **context/convenience** (auto-surface "join your
10:00 service?"), **not** the security boundary. The **rotating venue code** is the gate.

## What exists vs. what's new (from the infra map)

| Capability | Today | Gap |
|---|---|---|
| Org + CCLI licence | `tblOrganisations.LicenceType/Number/ExpiresAt` + `tblOrganisationLicences`; resolved per-user with org-ancestry (`licences.php`) | CCLI number is **free text, never validated**; the *time-boxed* unlock model already exists in the **licence layer** (`ExpiresAt` honoured) — reuse it, don't invent a new entitlement primitive |
| Geographic boundary on an org | **None** — lat/lng only on `tblPlaces` (city centroid) | **greenfield**: per-org/venue lat/lng + radius |
| Service times | per-setlist `tblSetlistSchedule` (DATE only, no time-of-day, no recurrence) | **greenfield**: recurring per-venue service windows |
| Content gating seam | `content_access.php::checkContentAccess()` (flag-gated at `song.php:135`); `require_licence` already unlocks `ccli` when the user's effective licence set contains it | clean insert: inject a **synthetic, service-end-dated `ccli` licence** into the effective set when bound to an active service session — **zero rule-engine change** |
| Live-Follow spine | `tblLiveFollowSessions` + `live_follow_*` (StateRevision short-poll) | leader-centric: `HostUserId NOT NULL`, `OrgId` present-but-unused, one-session-per-host. Needs org/service scoping + NULLable host + the NAT poll-anti-enumeration hardening already flagged in #1268 |
| Geolocation (browser) | **none anywhere** | greenfield (and only ever a *secondary* signal per Reframe 2) |

## Phased plan

- **Phase 0 — verify with CCLI (owner, non-code, BLOCKING for the unlock).** Put the questions
  below to CCLI *in writing*, per region iHymns targets. Until confirmed, the auto-unlock is parked;
  everything else can proceed.
- **Phase 1 — Org Venues + Recurring Service Schedule admin (BUILDABLE NOW; un-blocked).** ← *what you asked for.*
  The foundation every later phase FKs to, and useful org metadata on its own.
- **Phase 2 — "Service Mode" sessions.** Extend #1268: populate `OrgId` + a service link, NULLable
  host, **join via the venue rotating code**; congregants follow the service's songs (entitled
  content only — no CCLI change). Lands the #1268 NAT poll-hardening + (ideally) per-section follow.
- **Phase 3 — service-scoped CCLI unlock (ONLY if Phase 0 confirms).** Bind a temporary `ccli`
  licence (expires at service end) to a congregant who joined an active session at an org with a
  live CCLI licence, via the licence layer; audited into `tblSongUsageEvents` (already dormant-ready).
  Render the CCLI copyright-notice on each device (a CCL obligation).
- **Phase 4 — ProPresenter / Planning Center driver (optional, later).** iHymns is already the
  *receiver*; add a key-authed `live_follow_drive`. ProPresenter = desktop/no-cloud → needs a small
  **on-LAN bridge** (official HTTP API in 7.9+, else the legacy WebSocket). Planning Center is cloud
  with a real `plan.live.updated` **webhook** (the better signal). The real work is **song-identity
  mapping** (free-text slide/plan titles → iHymns SongId; reuse `lyricsIngest_resolveSong`). Do
  **after** per-section follow + poll-hardening.

## Phase 1 detailed design (the buildable foundation)

**Forward-looking schema (rule #20 — design the final DDL up front, additive, dormant, VARCHAR-not-ENUM):**
- `tblOrgVenues` — `Id, OrgId(FK), Name, AddressLine, City, Postcode, CountryCode,
  Latitude DECIMAL(10,7), Longitude DECIMAL(10,7), RadiusMetres SMALLINT, TimeZone VARCHAR(40),
  IsActive, CreatedAt/UpdatedAt`. (lat/lng/radius = the *convenience* geofence + map pin.)
- `tblOrgServiceSchedules` — `Id, VenueId(FK), Title, DayOfWeek TINYINT, StartTime TIME,
  DurationMins SMALLINT, RecurrenceKind VARCHAR(20) [weekly|fortnightly|monthly_nth|one_off|custom],
  RecurrenceData JSON (interval, nth-of-month, until, EXCEPTION dates), TimeZone VARCHAR(40),
  IsActive, CreatedAt/UpdatedAt`. RecurrenceKind is VARCHAR so adding a cadence is app-level, not an ALTER.
- Reserve a **service-occurrence** concept (a computed/instantiated `(scheduleId, date)`), which
  Phase 2's session + Phase 3's unlock + `tblSongUsageEvents.ScheduleId` all bind to — so no re-migration.
- schema.sql mirror + `migration-registry.php` entry + `setup-database.php` probe, per rule #19.

**Friendly admin UI** (on `/manage/organisations` per-org, or a dedicated `/manage/venues`):
- **Location**: address search → **map pin** picker (reuse the editor's live geocoder / `region_search`),
  with a **radius slider** + a "use my current location" helper. Plain-English summary.
- **Schedule**: a recurring-service editor — "**Every Sunday 10:00 (90 min), Europe/London**" with a
  recurrence dropdown + exception-date list; shows the next few computed occurrences.
- Reuses org CRUD + `manage_organisations` entitlement + `.admin-table-responsive` + sortable headers.
- Effort: **M**. Risk: low. **Un-blocked by CCLI** — ships value immediately (org venue/service metadata).

## The exact questions to put to CCLI (Phase 0)
1. Does our Church Copyright License cover displaying a song's lyrics **live on the personal devices
   of congregants who are physically present**, where *our church operates* the system pushing the text?
2. Is that "projection … via an electronic device" under the CCL, or a separate per-device
   reproduction/distribution the CCL does not grant?
3. Does routing the text through an **app the congregant installed** cross the non-transferable /
   no-sublicense line — and does it matter whether the church vs the app vendor "operates" it?
4. Would the **Streaming License** (which permits distributing service audio/video to members'
   devices) be the correct/needed frame instead — for **in-person** attendees?
5. What **copyright-notice** must each device render, and how does the licence-number display obligation apply per-device?
6. Confirm per **region** (CCLI terms differ US/UK/EU/…); note non-CCLI publishers/CVLI are out of scope.

## Key risks
- **Licensing (the gate)** — misclassification / sublicense; verify in writing before Phase 3. *(blocked-on-external)*
- **Presence spoofing** — mitigated by the rotating-code air-gap + short TTL + per-device cooldown +
  bind-to-session; geolocation never unlocks alone (config flag forbids it).
- **Privacy** — any optional geolocation is opt-in, coarse, server-side pass/fail radius test, **not stored**.
- **Operator is web-only / shared DB** — the rotating-code mint/display must be a **web-runnable** page;
  nonce-consume + cooldown must be transactional on the shared MySQL (3 docroots).
- **Accessibility** — always offer a large human-typeable code beside the QR.
- **#1268 carry-overs** — NAT poll anti-enumeration + per-section follow should land for a real congregation.

## Naming
Worship team = **Live Follow** (shipped). Congregation = suggest **"Service Mode"** (or "Congregation
Follow" / "Pew Mode") — clearly distinct, and it reads as *the church running the service*, which also
aligns better with the CCLI "church operates it" framing.
