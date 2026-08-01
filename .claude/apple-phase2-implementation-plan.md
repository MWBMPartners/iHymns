# Apple Native App — Phase 2 Implementation Plan (Live + LAN Remote)

> **STATUS: PLAN (Fable 5, 2026-07-10).** Grounded in `apple-native-strategy.md` §2.4 (owner-corrected 2026-07-06 LAN-direct design) + §3.4, `live-observability-strategy.md` §2.4/§2.5, issues #1405–#1410 / #1420–#1429, and a code-level audit of `api.php`/`iHymnsKit`/`project.yml`/CI. Sibling of `apple-native-strategy.md`; link from its §3.4 + `apple-native-status.md`.

## 0. Ground-truth corrections (verified vs code — reshape sequencing)
1. **`stateVersion` already exists** — `tblLiveFollowSessions.StateRevision` is monotonic, bumped on every broadcast, emitted as `revision`. #1405's stateVersion is a **naming/contract decision, not a build** (native DTO maps `revision`).
2. **Cheap-poll `?since=` already exists** on both poll endpoints (`api.php:13873`/`:13522`), web client already sends it. What remains of #1406 = server-declared `pollIntervalMs` in the join response + a **projector poll budget** (today's 40/min per-token cap throttles a 1 s tvOS projector — real blocker for #1428). **Reject** the HTTP 204/304 variant (breaks the shipped web JSON contract for ~60 bytes).
3. **Observability P0 landed** (`activityLogScrubQuery`, PR #1493; hashed live-poll bucket; `service.session.start/end`). The rest of the §3.2 matrix + `IHLog` (Swift) are NOT in code; observability P1/P2 issues were never filed.
4. **tvOS shell is still `PhaseZeroSkeletonView`** — no lyric-display surface anywhere. #1421 + #1428 share an **unfiled prerequisite: the tvOS projection canvas.** Biggest hidden dependency in the Phase-2 set.
Side-finding: `live_follow_poll`/`_join` still don't filter `Channel` (rule-#26 cross-env class) — fix cheaply inside PR-1.

## 1. Dependency graph (three decoupled tracks)
- **TRACK A — backend-additive** (PHP, dormant, alpha now): PR-1 payload-v2+budget+schema-batch+breadcrumbs → unblocks C0/C3 + all A-schema. Then PR-3 (#1407 device-code), PR-9 (#1408 control token), PR-12 (#1409 manage-devices), PR-13 (#1410 APNs).
- **TRACK B — LAN-direct TV remote** (Swift, ZERO backend): PR-2 IHLog (prereq — engines born instrumented) → PR-4 (#1420 IHRP/1 + TVListenerActor + RemoteSessionActor, **Opus reviews once**) + PR-5 (tvOS ProjectionCanvasView, **unfiled prereq**) → PR-6 (#1421 listener+pairing) → PR-7 (#1422 remote UI) → PR-8 (#1424 manual-connect/troubleshooter) → PR-11 (#1423 Watch relay) → PR-14 (#1425 mirror).
- **TRACK C — server clients** (consumes A): PR-10 (#1426+#1427 IHAPI + engines) → PR-15 (#1428 tvOS projector) → PR-16 (#1429 Live Activities).
- **OVERLAY** PR-17 DiagnosticsView (observability P2). **GATE:** dev-team-security Audit B + multi-device verify (after PR-8/11/15, before external TestFlight).

**Critical path to "drive the TV from the phone":** PR-2→PR-4→PR-5→PR-6→PR-7(→PR-8 VPN). No backend. But **PR-1 goes first anyway** — it's the only work with an external clock (the alpha→beta→prod web promotion is on the Apple critical path; any backend a TestFlight build needs must already be promoted).

## 2. PR sequence (target alpha; H=Haiku, S=Sonnet, O=Opus-review)
| # | PR | Closes | Tier | Size |
|---|---|---|---|---|
| 1 | payload v2 + projector poll budget + Phase-2 schema batch + breadcrumbs | #1405,#1406 (+schema of #1407-1410) | S, **O reviews DDL** | 1–1.5d **FIRST** |
| 2 | IHLog target + sanitizer + APIClient/SessionController retrofit | new obs-P1 issue | S(→H) | ~1d ‖ PR-1 |
| 3 | tvOS device-code pairing (RFC 8628) + /link page | #1407 | S | ~1d |
| 4 | IHLive/LANRemote IHRP/1 + TVListenerActor + RemoteSessionActor | #1420 | S, **O design review** | 2–3d |
| 5 | tvOS ProjectionCanvasView + tvOS browse wiring | **new issue** | S | 1.5–2d ‖ PR-4 |
| 6 | tvOS LAN listener + pairing ceremony (code+QR, cert-pin) + trusted-remotes | #1421 | S | 2d |
| 7 | remote control UI (iPhone/iPad/Mac/vision) | #1422 | S | ~2d |
| 8 | manual connect-by-address + VPN/AP-isolation troubleshooter + venue doc | #1424 | S | ~1d |
| 9 | session control token (scoped, revocable) | #1408 | S | 0.5–1d |
| 10 | native Live Follow + Service Mode clients (IHAPI + engines + UI + fixtures) | #1426,#1427 | S | 2–3d |
| 11 | Watch LAN remote relay | #1423 | S | 1–1.5d |
| 12 | token/device metadata + manage-devices API | #1409 | S(+H) | ~1d |
| 13 | APNs bridge (ES256, .p8 outside docroot, HTTP/2) — dormant | #1410 | S | ~1.5d |
| 14 | optional Service-Mode mirror (mirror-on-ack) | #1425 | **H** | 0.5d |
| 15 | tvOS server-following projector | #1428 | S (orchestrator) | 2–3d |
| 16 | Live Activities + Dynamic Island | #1429 | S | 1.5–2d |
| 17 | connection diagnostics overlay (OSLogStore + ShareLink) | new obs-P2 issue | S(→H) | 1–1.5d |
| — | **GATE: Audit B + multi-device verify matrix** | gate | **O** | 1–2d |

Every Swift PR folds in the observability §2.4/§2.5 instrumentation contract as acceptance criteria (transitions-not-states; token FINGERPRINTS never codes; `.private` for IPs/names).

## 3. FIRST SLICE — PR-1 (recommended; the additive backend batch, NOT the IHRP/1 skeleton)
**Why:** IHRP/1 has no external clock (ships whenever). PR-1 is PHP/dormant/additive/**shippable to alpha today** (old web clients byte-unaffected), sits on the web promotion pipeline (every day off alpha adds to the tail), unblocks BOTH native congregant clients (PR-10 fixtures need a v2-emitting dev API) AND the tvOS projector (can't poll at cadence under 40/min), and per rule #20 the Phase-2 schema must ship as ONE dormant batch now (prevents #1407-1410 each dribbling an ALTER). LAN track loses nothing — PR-2 (IHLog) is its real foundation, runs in parallel.

**4 commits:**
1. **payload v2 (#1405), zero schema** — extract `_liveFollowCleanState()` → `serviceMode_cleanState()` in `includes/service_mode.php` (ONE state allow-list for all 3 writers, rule #26; unit-testable). Add `displayState` (validated vs `const SERVICE_DISPLAY_STATES=['live','blackout','logo']` — VARCHAR-not-ENUM applied to JSON) + `lineIndex` (nullable, clamp 0–9999). **Bidirectional `blank`↔`displayState` mapping inside the cleaner** (v2 `displayState:'blackout'`→also writes `blank:true`; legacy `blank:true`→derives `displayState:'blackout'`) — the subtlest line; without it the two client generations desync on the most visible state. `stateVersion`≡`revision` (no new field; document). Add the missing `Channel` filter to `live_follow_join`/`_poll`.
2. **cheap-poll completion (#1406), one gated column** — `service_join` returns `pollIntervalMs` (2500 congregant / 1000 projector, from `tblAppSettings` with fallbacks) + accepts optional `role∈{congregant,projector}` → `tblServicePresence.Role` (columnExists-gated, fail-open to congregant). `service_poll` reads `Role` → 40/min congregant / **90/min projector**, still per-presence-token. Reject 204/304 + long-poll (note on #1406).
3. **one-pass Phase-2 schema batch (rule #20)** — `migrate-apple-phase2-live-schema.php`, additive/idempotent/dormant, ONE registry entry (multi-object OR-probe), byte-identical schema.sql mirror, `@migration-adds` per column: `tblServicePresence`+`Role VARCHAR(20)`; `tblAuthDeviceCodes` (#1407: DeviceCodeHash CHAR(64), UserCode, Status VARCHAR, UserId FK, Channel, PollCount/LastPolledAt, ExpiresAt DATETIME); `tblSessionControlTokens` (#1408: TokenHash UNIQUE, SessionId FK, Scope VARCHAR, ExpiresAt DATETIME); `tblApiTokens`+`DeviceName/Platform/AppVersion/LastSeenAt` (#1409); `tblApnsTokens` (#1410: Kind VARCHAR `liveActivity|device`, nullable SessionId, PushToken VARBINARY, ApnsEnv, ExpiresAt DATETIME). **Adversarial DDL stress + Opus review before merge.**
4. **observability §3.2 matrix + tests** — add breadcrumbs per the binding table (`live.session.create`, `live.broadcast.song` change-only, `live.session.end`, `live.session.join` ok+fail, `service.broadcast.song` change+fail, `service.presence.join/leave`, `service_code_rotate/current` fail); entity conventions (organisation/orgId, live_session/numeric Id); **never a code/token/digest**; polls NEVER logged. Tests: `test-service-state-clean.php` (allow-list, clamps, blank↔displayState both ways), migration-registry+schema-coverage, breadcrumb key assertions, `php -l`.

**Activate:** merge→deploy→operator runs the ONE migration card→verify web round-trip byte-identical pre-migration, projector join gets 90/min+`pollIntervalMs:1000` post-migration, one web session = expected activity-log rows.

## 4. Security / entitlements
- `project.yml` `iHymns`+`iHymnsTV`: `NSLocalNetworkUsageDescription` + `NSBonjourServices=["_ihymns-remote._tcp"]` (both advertise+browse count). macOS remote uses existing sandbox `network.client`. **Deliberately NOT `com.apple.developer.networking.multicast`** (declaring the service type + NWBrowser/Listener stays un-gated — no 2–6wk Apple approval). **Tripwire:** any PR reaching for raw multicast/UDP re-opens the gate → reject.
- **Pairing threat model** (PR-4/6 criteria): TLS 1.3 + TV P-256 self-signed identity; QR path pins cert fingerprint out-of-band; manual-code path = HMAC-over-fingerprint channel binding (MITM's own cert fails HMAC); code 2-min TTL + operator-initiated + rotate-on-5-fail; pairing token 32-byte, TV stores sha256 only, remote Keychain **`synchronizable:false`** (never account default `true` — else iCloud syncs sanctuary-screen control everywhere); unpaired connections protocol-confined + length-capped TLV; LAN carries ~200-byte INTENT never lyrics (TV fetches content under its own auth; gated→`error(.contentUnavailable)`→sign-TV-in via PR-3).
- Backend: device_code + control tokens hashed at rest; user_code single-use 5-min TTL, per-code rate-limit (never per-IP, rule #26); `/link` uses `validateCsrfRequest()` + shows device+geo; projector role = poll-budget only (no data escalation). **Audit B** gates external-TestFlight promotion, not alpha landings.

## 5. Build-env verifiable now vs device
**Now (CI):** all PR-1/3/9/12/13 PHP (unit tests, migration/schema CI guards, `php -l`); PR-2/4/10 Swift core (IHRP/1 round-trip+fuzz, TLV caps, pairing HMAC vectors, **TLS loopback integration over localhost**, engine poll loops vs MockURLProtocol+injected clocks, IHLog tests, DTO fixtures); PR-5/6/7 compile+snapshot; **add tvOS Simulator build to `apple.yml` in PR-5**. On a dev Mac: tvOS-sim + iOS-sim share the host stack → Bonjour + manual-code pairing + phone→TV control E2E (~80% of functional verify).
**Device/TestFlight only:** real-AP mDNS, AP client-isolation, VPN-without-multicast, the **Local Network permission prompt** (Simulator doesn't enforce it), QR camera pairing, Watch relay, Live Activities/APNs, the full verify matrix. Backend-in-TestFlight also needs PR-1/PR-3 promoted to beta/prod.

## 6. Adversarial self-critique
1. **Unfiled tvOS-canvas (PR-5) is the soft spot** — file it at kickoff; canvas API `(songId,componentIndex,lineIndex,displayState)` with no knowledge of who set them → both LAN + server drivers stay thin (no re-fork).
2. **Schema batch is a rule-#20 bet** — riskiest shape `tblApnsTokens` (per-activity Live-Activity tokens churn; `Kind`+nullable `SessionId` hedge); adversarial-stress + Opus pass before merge; every consumer `tableExists`/`columnExists`-gated so additive follow-ups stay possible.
3. **`blank`↔`displayState` desync** — likeliest field bug; bidirectional map lives in the ONE cleaner + both-direction tests; never map in clients.
4. **iCloud-synced pairing trust** (`synchronizable:true` copy-paste) silently breaks the proximity model, invisible in testing → explicit PR-6 acceptance criterion + Audit-B check + unit test.
5. **Chasing the shiny ends** (Live Activities/Watch) before PR-8 manual-connect — but §2.4.5 makes VPN/manual a first-class v1 rung and it's exactly what the owner (VPN-into-LAN user) hits day one; PR-8 stays ahead of PR-11/16.

## 7. Tracking
Add a Phase-2 checklist to epic **#895** (one checkbox per PR). File 3 new issues: (a) tvOS ProjectionCanvasView prereq [apple-phase-2]; (b) Observability P1 IHLog; (c) Observability P2 diagnostics overlay. On #1405/#1406 close: `stateVersion`≡`revision`, 204/long-poll rejected (file 204 as `for consideration`).
