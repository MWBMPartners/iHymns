# Live-Follow / Remote-Control Observability Strategy

> **STATUS: DRAFT for owner approval** (Fable 5 deep-planning round, 2026-07-10). No code actioned from this doc except the P0 security fix (a live secret-leak, treated as a bug). Per the owner's "plan = draft the doc" rule, P1/P2/P3 await sign-off.

**Scope:** `appApple/Packages/iHymnsKit` + `appWeb/public_html`
**Related:** #1335 (Service Mode), #1268 (Live Follow spine), #1104/#1115 (native remote/tvOS), #1339 (multi-device verify), `apple-native-strategy.md` §2.4 (LAN remote), CLAUDE.md rule #26.

---

## 0. Ground-truth corrections (they reshape the plan)

**0.1 — The client remote-control engine largely does not exist yet.** `IHLive/LiveFollowEngine.swift` is a ~70-line Phase-0 skeleton: one pure `isFresh(lastUpdatedAt:now:)`. There is no polling loop, no heartbeat, no join/broadcast call, no Bonjour/`NWBrowser`/`NWListener`, and no `live_follow_*`/`service_*` endpoints in `IHAPI`. The LAN-direct TV remote (IHRP/1, `TVListenerActor`, `RemoteSessionActor`) is a Phase-2 *design*, not code. **Consequence:** we cannot "add logging to the engine." We (a) build the `IHLog` facility now, (b) retrofit the code that *does* exist (`APIClient`, `SessionController`), and (c) define a **binding instrumentation contract** (§2.4/§2.5) that becomes acceptance criteria inside the Phase-2 engine-build issues — so the engines are born instrumented.

**0.2 — The backend already logs the poll firehose, and it currently LEAKS SECRETS.** `api.php:197` installs `installRequestActivityLogger` (`includes/activity_log.php:707–756`), which writes one `request.success|failure|error` row per request with `Details.query = substr(<raw query string>, 0, 255)`. For the GET endpoints that means, **today, on every request:** `service_poll` → `presenceToken=<token>`; `live_follow_poll` → `code=<session code>` **and** `token=<follow token>`; `live_follow_join` → `code=<session code>`. This violates the house rule ("logs never carry bearer/magic-link tokens"). Adjacent: `live_follow_poll` rate-limits on the **raw** follow token (`api.php:13498`, persisted into `tblLoginAttempts.IpAddress`), whereas `service_poll` correctly hashes (`:13870`). **Consequence:** the first backend deliverable is REDACTION, not new logging. And "don't log the polls, they'd flood" is moot at the request layer — they're already logged per #1207 policy; our lifecycle breadcrumbs add negligible marginal volume, and we must NOT add a second per-poll row.

Side-finding (separate issue, not fixed here): the `live_follow_*` host-mode handlers never filter `Channel` (unlike `service_*`) — a host session created on alpha is joinable from production against the shared DB (rule #26 cross-env class). The new breadcrumbs' `Environment` column makes any such flow visible.

---

## 1. Objective + failure layers

When the owner/any TestFlight tester drives the TV from a phone, hosts a Live Follow, or joins Service Mode and it FAILS, there must be a readable trail — on-device (overlay), on a Mac (Console.app/sysdiagnose), and server-side (`/manage/activity-log`) — that localises the failure to a layer, with NO log anywhere carrying a token, code, or key.

| # | Layer | Typical failures | Evidence lands |
|---|---|---|---|
| L1 | LAN discovery (Bonjour `_ihymns-remote._tcp`) | mDNS blocked (VPN/routed), AP client isolation, TV not advertising | client `discovery` + overlay |
| L2 | Local-network permission | user denied; `PolicyDenied` | client `discovery` `.error` + overlay |
| L3 | LAN connect/pair/TLS | connect timeout, pin mismatch, pairing reject | client `remote` + signpost |
| L4 | Relay / cloud path | offline, 429/503, retry exhaustion, DNS | client `api` + backend `request.*` |
| L5 | Auth / session / channel | 401, 403 not-operator, 404 unknown/expired, wrong-channel, stale code | **backend lifecycle+failure breadcrumbs** + client `.error` |
| L6 | Poll/broadcast liveness | stale session, revision stuck, poll storms | client `live-follow` transitions + backend song-change breadcrumbs |
| L7 | TV-apply | received but not applied / late | client (tvOS) `remote` signpost + TV overlay |

---

## 2. Client plan (Swift, `iHymnsKit`)

### 2.1 `IHLog` — a new zero-dependency SwiftPM leaf target
Importable from `IHAPI`/`IHLive`/`IHAuth`/`IHFeatures`. NOT in `IHModels` (wrong layer) or `IHAppSupport` (drags analytics into the network cone). New target `IHLog` (deps: none beyond `os`/Foundation) + `IHLogTests`. Update the `Package.swift` layering comment (sits beside `IHModels`).

```swift
public enum IHLog {
    public static let subsystem = "app.ihymns"           // matches IHAnalyticsSink.swift:77
    public static let api        = Logger(subsystem: subsystem, category: "api")
    public static let liveFollow = Logger(subsystem: subsystem, category: "live-follow")
    public static let remote     = Logger(subsystem: subsystem, category: "remote")
    public static let discovery  = Logger(subsystem: subsystem, category: "discovery")
    public static let auth       = Logger(subsystem: subsystem, category: "auth")
    public static let signposter = OSSignposter(subsystem: subsystem, category: "spans")
}
// IHLogSanitize.tokenFingerprint(_:) -> 8-char SHA-256 hex prefix of a HIGH-ENTROPY
//   credential (>=128-bit). NEVER for join/session CODES (~29-39 bit = brute-forceable).
```

**Level policy (this IS the verbosity switch — no custom gate):** `.debug` = per-tick chatter (free in prod, visible only when streaming/opted-in); `.notice` = lifecycle **transitions** (persisted → sysdiagnose); `.error` = failures with taxonomy; `.fault` = programmer errors. **Cardinal rule: log TRANSITIONS, not STATES** — a dead server produces ONE `.error` (healthy→degraded) + `.debug` ticks, never an `.error` every 2.5 s.

**Privacy (binding):** `.public` — endpoint `action`, `APIError` case, HTTP status, attempt/revision numbers, durations, numeric session IDs, channel/env, state names, song IDs, counts, TLS **public-key fingerprints**. `.private` — user/device IDs, display names, LAN IPs/hostnames/Bonjour instance names. **Never interpolated at any level** — bearer/presence/follow tokens, join/session/pairing codes, URLs-with-query, bodies, `Authorization` headers. Token *fingerprints* are `.public`.

### 2.2 Retrofit the call sites that exist today
- **`IHAPI/APIClient.swift`** `performOnce(_:)` (~L293): wrap in `OSSignposter` `"api.request"` (attr: `endpoint.action`); on throw → `.error(action, apiErrorCase, status, ms)` all `.public`; on success → one `.debug`. **Never log `request.url`** (query carries `presenceToken` once the congregant client exists) — log the `action` only. `performIdempotentGET(_:)` (~L261): `.notice` per retry decision + a "giving up after N" line. `classify`/`makeURLRequest` stay pure/unlogged.
- **`IHAuth/SessionController`**: `.notice` on signed-out↔signed-in transitions (no token/username; userId `.private`); `.error` when a 401 forces sign-out.
- **`IHLive/LiveFollowEngine.swift`**: nothing yet (pure) — instrumented per §2.4 as built.

### 2.3 Deliberately NOT configurable
No runtime kill-switch / custom verbosity for client logging (`os.Logger` is always-on by design; `.debug` is free; a gate adds a "logging was off during the failed test" failure mode). The **overlay** is the only toggle. Never piggyback on `IHAnalyticsService` — it is consent-gated; diagnostics must work when consent is off.

### 2.4 Binding contract — server-mediated engine (built later, logged from birth) — category `live-follow`
join begin/success/failure (`.notice`/`.notice`/`.error`); poll tick (`.debug`); poll-health transition healthy↔degraded↔dead @ 3 consecutive failures (`.notice`/`.error`); freshness transition via `isFresh` (`.notice`); heartbeat-failure transition (`.error`); host broadcast sent/failed (`.debug`/`.error`, songId+componentIndex+revision); session end (`.notice`, reason user/expired/serverInactive); signposts `"live.join"`, `"live.poll.gap"`. All fields `.public` except none-are-user-data here.

### 2.5 Binding contract — LAN remote (`IHLive/LANRemote`) — categories `discovery`+`remote`
browser start/stop; result-set-changed (count `.public`, instance names/IPs `.private`); **local-network permission denied** (`.error` — the single most valuable TestFlight line); connect attempt/result per path {lanDirect, manualAddress, serverRelay} (+ signpost `"lan.connect"`); TLS/pin outcome (pin mismatch = `.fault`; expected-vs-presented **fingerprints** `.public`); pairing step transitions (NEVER the pairing code); IHRP/1 msg send/recv (`.debug`, type name only); tvOS apply span `"remote.apply"`; Watch relay reachability.

### 2.6 On-device diagnostic overlay (the Mac-less-tester path)
`DiagnosticsView` in `IHFeatures`, via Settings → Support → "Connection Diagnostics". Shows app version + API env, auth state (yes/no, never token), live-session summary (kind, numeric id, revision, seconds-fresh, poll health), LAN state, last errors. **Fed by `OSLogStore(scope: .currentProcessIdentifier)`** filtered to `subsystem == app.ihymns`, level ≥ notice, last 15 min, cap 500, on-open only (no standing tail, no parallel ring buffer). iOS/iPadOS/macOS: a `ShareLink` text export (what an iPhone-only tester sends instead of a sysdiagnose). tvOS: read-only, photographable, large type. Plain visible Settings row (no hidden gesture). Safe in production by construction — nothing secret is ever logged.

---

## 3. Backend plan (PHP)

### 3.1 P0 — redact the existing leak (`includes/activity_log.php`) — **SECURITY, treat as a bug**
Extract a pure `activityLogScrubQuery(string $query): string` used by `installRequestActivityLogger` before writing `Details.query` (and the `Referrer`). Policy: parse pairs; a param whose **name** matches `/token|code|secret|key|password|passwd|auth|signature|otp/i` OR the explicit list `presenceToken, token, code, state` → value `[redacted]` (name kept: `action=service_poll&presenceToken=[redacted]&since=41` stays forensically useful). Pure → unit-testable. Also hash the `live_follow_poll` rate bucket (`api.php:13498` → `'tok:'.substr(hash('sha256',$liveTok),0,24)`) to match `service_poll:13870`. *(Rejected for now: default-deny allowlist — degrades admin forensics for unlisted benign params; file as `for consideration`.)*

### 3.2 Endpoint logging matrix (exact, binding) — via existing `logActivity()` (best-effort, auto-captures UserId/IP/UA/Environment/RequestId)
`service.*` family → `entityType='organisation', entityId=orgId`. Host-mode `live.*` (no org) → `entityType='live_session', entityId=<numeric tblLiveFollowSessions.Id>`. **Never the SessionCode/token/rotating code/digest-of-a-code.**

| Endpoint (api.php) | Log? | Action / Result | Entity | Details (IDs only) |
|---|---|---|---|---|
| `live_follow_create` (13232) | success | `live.session.create` | live_session/insert_id | `{superseded, has_setlist, has_song}` |
| `live_follow_update` (13307) | **song-change only** | `live.broadcast.song` | live_session/Id | `{song_id}` (log only when CurrentSongId changed) |
| `live_follow_heartbeat` (13366) | NO | — | — | keepalive |
| `live_follow_leave` (13405) | YES | `live.session.end` | live_session/Id | `{}` |
| `live_follow_join` (13426) | success+failure | `live.session.join`/`failure` | live_session/Id (fail:`''`) | ok `{}`; fail `{reason:'not_found_or_stale'}` — never the code |
| `live_follow_poll` (13481) | **NEVER** | — | — | firehose |
| `service_session_start` (13541) | extend existing | `service.session.start`(+`failure` @13575) | organisation/orgId | add `schedule_id, occurrence_date, superseded`; fail `{reason:'not_authorised', venue_id}` |
| `service_code_rotate` (13633) | **failures only** | `service.code.rotate`/`failure` | organisation/orgId | `{session_id, reason}` (success = the join-codes table IS the history; verify mintCode retains rows) |
| `service_code_current` (13634) | failures only | `service.code.current`/`failure` | organisation/orgId | `{session_id, reason}` |
| `service_session_end` (13635) | already (13678) | — | — | leave |
| `service_broadcast` (13733) | **song-change + failures** | `service.broadcast.song`; `failure` | organisation/orgId | `{session_id, song_id, channel}` on change; fail `{session_id, reason}` — the "operator can't drive the TV" smoking gun |
| `service_join` (13794) | success+failure | `service.presence.join`/`failure` | organisation/orgId (fail:`''`) | ok `{session_id, channel}`; fail `{reason:'code_not_active', channel}` — never `presenceDeviceId` |
| `service_poll` (13862) | **NEVER** | — | — | firehose |
| `service_leave` (13911) | YES | `service.presence.leave` | organisation/orgId | `{session_id, channel}` |

**No new table, no schema change** — `tblActivityLog` already has `Environment`/`RequestId`/`Details`/µs `CreatedAt` + a web admin UI. Added volume is lifecycle-bounded (~1 create + 1 join/device + song-count changes + 1 end per session).

---

## 4. Security / privacy analysis (summary)
Tokens (≥128-bit) → never logged; **fingerprint only** (8-char SHA-256 prefix, `.public`), lets client `.error` and server row correlate without the value. Rotating codes (~29–39 bit) → never, **not even a digest** (offline-brute-forceable while live) — log session id + Generation instead. User/device identity → `.private` or omitted (congregant joins anonymous). URLs/bodies/headers → never (endpoint `action` only; query scrubbed P0). Song/session ids, revisions, states, error codes, channels → `.public`. TLS pin fingerprints → `.public` (public-key fingerprints aren't secrets). `Logger`'s un-annotated interpolations default to **private**, so annotation drift redacts rather than leaks. Optional CI guard: grep failing on `privacy: .public` near `token|code|secret` outside `IHLogSanitize`.

---

## 5. Phasing (each independently shippable, additive/dormant)
| Phase | Contents | Size | Tier |
|---|---|---|---|
| **P0 — backend hygiene + breadcrumbs** (de-risks the next TestFlight round alone: web broadcaster/congregant flows light up in `/manage/activity-log`) | §3.1 redaction + hashed live-poll bucket + §3.2 matrix + PHP tests | 0.5–1 d | **Sonnet** (shared security helper + 13 sites; not Haiku) |
| **P1 — client `IHLog` + retrofit** | new target + sanitizer + `APIClient`/`SessionController` sites + `IHLogTests` | ~1 d | **Sonnet** (Package.swift layering + actor isolation); mechanical inserts → Haiku |
| **P2 — diagnostics overlay** | `DiagnosticsView` + OSLogStore reader + ShareLink + Settings row + tvOS variant | ~1–1.5 d | **Sonnet**; copy → Haiku |
| **P3 — contract enforcement** (not a PR) | paste §2.4/§2.5 tables into the Phase-2 engine/LANRemote issues as acceptance criteria | ~0 | whoever builds those |

**Order: P0 first** (server visibility helps even while the native live client doesn't exist — the *web* flows light up immediately), then P1, then P2.

---

## 6. Test / verification (summary)
PHP: `test-activity-log-scrub.php` (pure scrubber: redacts token/code/pattern, preserves action/since/id, empty/no-`=`/dup params, 255-cap) + assert new action keys ≤50 chars + Details builders never key-name-like-a-credential; `php -l` sweep. Swift: `IHLogTests` (subsystem/category constants stable = Console-predicate contract; `tokenFingerprint` vector; usable-from-actor); `APIClient` logging side-effect-only (existing MockURLProtocol tests unchanged); overlay mapper unit test. Manual: Mac Console `subsystem == app.ihymns` — airplane-mode fetch → one `.error` `.offline` no URL; **leak test** = text-search a 10-min capture for the real token + real code, both ABSENT / fingerprint present; backend alpha: garbage `service_join` → `failure` row with `[redacted]`, one real web session → exact expected row set, `service_poll` `request.success` shows `presenceToken=[redacted]`.

---

## 7. Adversarial self-critique
1. **Planning against code that doesn't exist** (biggest) → split "retrofit now" (real line numbers) from "contract for birth" (P3 acceptance criteria); risk = contracts rot → P3 is an explicit deliverable.
2. **Redaction touches a live audit path (every request)** → pure fn + exhaustive tests + pattern-denylist (no regression for benign params); `logActivity` stays best-effort.
3. **Breadcrumb flood via "log failures"** (code-enumeration against `service_join`) → bounded by 300/60s/IP limit + 200/req cap; these are exactly the rows you want during an attack; sample-per-IP noted if it bites.
4. **Fingerprint misuse spreading to codes** → never-for-codes rule in sanitizer doc + this doc + PR checklist + optional grep guard; codes identified by session id + Generation.
5. **Overlay over-build / OSLogStore disappointment** → on-open only, notice+, 15-min/500 cap, UI states the "this-launch-only" boundary; fallback (tee'd ring buffer) flagged, not built.

**Honest boundary:** unified-log lines need a Mac/sysdiagnose/overlay to read — a remote iPhone-only tester gets value from P2 + backend rows, not P1 alone. Backend breadcrumbs only see L4–L6; L1–L3 (LAN discovery/permission) never touch the backend, so for the headline TV-remote the client tier is the ONLY tier — hence contract-at-birth (P3) matters most.

---

## 8. GitHub issues + filenames
- **Doc:** this file (`.claude/live-observability-strategy.md`).
- **Security issue (P0, urgent):** "Activity Log leaks presenceToken / live-follow code / follow token via `request.query`" — scrubber + hashed live-poll rate bucket.
- **Feature issue (P1–P3):** "Observability for remote-control / Live Follow / Service Mode: `IHLog` client instrumentation contract + backend lifecycle breadcrumbs."
- **Side issue (`for consideration`):** `live_follow_*` handlers don't filter `Channel` (rule-#26 cross-env class).

### Implementer anchors
`Package.swift` (new `IHLog` target); `IHAPI/APIClient.swift:261,293`; `IHAppSupport/IHAnalyticsSink.swift:77` (subsystem precedent, leave untouched); `includes/activity_log.php:707–756` (scrubber); `api.php:13232–13923` (matrix), `:13498` (raw-token bucket), `:13628/13678` (existing logActivity calls to mirror); `apple-native-strategy.md §2.4` (LANRemote design).
