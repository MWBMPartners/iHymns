# Apple Phase-2 PR-10 — native server-mediated live clients: Live Follow host + follower (#1426) and Service Mode congregant (#1427)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-13).** Sibling of `.claude/apple-phase2-pr{6,7,8}-spec.md` (all merged). Grounded in a code-level read of the LIVE backend contract on `alpha` (`appWeb/public_html/api.php` `live_follow_*` / `service_*` handlers + `includes/service_mode.php`), the two web reference clients (`js/modules/live-follow.js`, `js/modules/service-follow.js`), and the merged native seam (`Sources/IHLive/LiveFollowEngine.swift`, `Sources/IHAPI/*`, `Sources/IHFeatures/LiveHubView.swift` + `AppRootViewModel*`). Fulfils `apple-phase2-implementation-plan.md` §2 row 10 ("native Live Follow + Service Mode clients (IHAPI + engines + UI + fixtures)", closes **#1426 + #1427**) under strategy §2.4.6's contract. A Sonnet builder should execute this top-to-bottom with minimal further judgement. Target branch: **`feat/apple-p2-pr10-congregant`** off `alpha`; **ONE PR** targeting `alpha` (§9 justifies). **CI's `apple.yml` is NOT a required check (#1526), so every §7.5 verify command MUST be run locally before the PR is opened.** **ZERO backend changes** — payload v2 (`displayState`/`lineIndex`/`revision`≡stateVersion), `pollIntervalMs`, the presence `Role` budget and the `Channel` filters are all already live on alpha (plan §0/§3, PR-1 merged).
>
> **THE HARD SEPARATION (strategy §2.4.1):** this PR is the **server-mediated** side — client→PHP API→polling clients, seconds-latency, trust = server session + rotating code / presence token. It shares a MODULE (`IHLive`) but **no code, no protocol, no trust machinery** with the LAN-direct TV remote (PR-4→PR-8, `Sources/IHLive/LANRemote/`). Any `import`/reference of `IHRPMessage`/`RemoteSessionActor`/`TVListenerActor`/`IHRPDisplayState` from a file this PR creates is a reviewer-rejects-on-sight tripwire (`IHRPDisplayState` at `IHRPPayloads.swift:64-68` is the LAN vocabulary `lyrics/blackout/logo/frozen`; the SERVER vocabulary is `live/blackout/logo` — §3.1 defines its own type).

---

## 1. Scope — what exists, what PR-10 builds, and the backend contract

### 1.1 What ALREADY exists (verified in code — cite these in file headers, do not rebuild)

**Native:**
- `IHLive.LiveFollowEngine` (`Sources/IHLive/LiveFollowEngine.swift`) is a **Phase-0 skeleton**: an `actor` holding `private let apiClient: APIClient` (`:40`), `init(apiClient:)` (`:42`), and exactly ONE real piece of logic — the pure, `nonisolated static func isFresh(lastUpdatedAt:now:)` freshness check with `freshnessWindowSeconds = 180` (`:38`, `:67-69`), unit-tested in `Tests/IHLiveTests/LiveFollowEngineTests.swift` with injected clock values. **That seam is THE freshness function for this whole PR — extend it, never re-derive 180s anywhere else.** There is NO polling loop, NO heartbeat, NO join/leave, NO Service-Mode engine, NO host side. PR-10 builds all of that.
- `AppRootViewModel` already stores + injects the engine: `private let liveFollowEngine: LiveFollowEngine` (`AppRootViewModel.swift:208`), init param (`:256-268`), constructed in the composition root `AppRootViewModel.makeLive(environment:)` (`AppRootViewModel+Live.swift:52-66`). PR-10 widens the property to `internal` (`let liveFollowEngine` — the exact `apiClient`-at-`:201` precedent for cross-file extensions) and adds a sibling `ServiceModeEngine`.
- `IHAPI.APIClient` (actor) sends `X-Requested-With` on EVERY request (`APIClient.swift:17-21`) — which is precisely what satisfies the web backend's `validateCsrfRequest()` same-origin gate on the anonymous state-changing POSTs this PR calls (`service_join`/`service_leave`; web congregant does the identical thing, `service-follow.js:255`). Bearer only rides `requiresAuth` endpoints (`APIClient+Networking.swift:128-131`). Retry/backoff (idempotent GETs, max 3 attempts) and `MockURLProtocol` test harness (`Tests/IHAPITestSupport/`) exist.
- `IHFeatures.LiveHubView` (PR-7, #1422) fills `RootContainerView`'s `.live` section (`RootContainerView.swift:330-333` tabbed, `:376-377` split) with the TV-Remote card **plus the honest "Live Follow & Service Mode — coming in a future update" `ContentUnavailableView`** (`LiveHubView.swift:44-50`). **PR-10 fills exactly that slot** — the coming-soon block is deleted because the thing it was honest about now exists.
- `IHLog` + `IHLogSanitize.tokenFingerprint` (`IHLogSanitize.swift`) — the ONLY way any token may appear in a log line (8-hex sha256 prefix; its own doc block warns it is **NEVER safe for short join CODES** — codes must never be logged or fingerprinted at all).
- `SongID` parses only `<letters>-<digits>` (`SongID.swift:120` `init?(rawValue:)`); ~10 live legacy ids don't match (fixtures README) — §3.2/D-13.
- `SongComponentView` (IHFeatures) — the ONE shared lyric-component renderer (already reused by `SongComparisonView` per its `#1445 UPDATE` header, proving the "reuse, don't fork a second lyric renderer" pattern this PR's following screen relies on).
- `ProjectionViewModel` (tvOS, PR-5) — **NOT touched.** It is PR-15's (#1428, tvOS server-following projector) consumer of THIS PR's engines; its header names that future driver explicitly.

**Backend (all live on alpha — plan §0 ground truths):**
- Payload v2: `serviceMode_cleanState()` is the ONE state allow-list for all three broadcast writers, with the bidirectional `blank`↔`displayState` bridge (`includes/service_mode.php:140-178`); `SERVICE_DISPLAY_STATES = ['live','blackout','logo']` (`:63`). `stateVersion` **IS** the existing `revision`/`StateRevision` counter — no separate field (file header `:23-27`).
- Cheap poll `?since=` on both poll endpoints; server-declared `pollIntervalMs` in the `service_join` response (`api.php:14686`, `serviceMode_pollIntervalMs()` `service_mode.php:397-408`, defaults 2500 congregant / 1000 projector); presence `Role` poll budgets (40/min congregant, 90/min projector, per-TOKEN-hash, `api.php:14711-14713`).
- `Channel` filters on every join/poll (rule #26; `api.php:14139-14148` live-follow, `serviceMode_resolveJoin` `service_mode.php:293-318` service).
- Unified 180 s freshness `LIVE_SESSION_FRESHNESS_SECONDS` (`service_mode.php:45`); 4 h hard ceiling (`:35`); 75 s rotating-code TTL (`:33`).
- The CCLI presence unlock: `serviceMode_presenceCcliNumber()` (`service_mode.php:424-457`) consumed by `checkContentAccess(...,$presenceToken)` (`content_access.php:72,146-157`) and `contentGatingApply(...,$presenceToken)` (`content_gating.php:148,190-219`) — **fed ONLY from the `ihymns_sf_presence_token` COOKIE on `song_detail`/`random`** (`api.php:810-815`, `:693-698`). §4.3/D-6 is how a cookie-less native client rides this WITHOUT a backend change. Entirely dormant behind `content_gating_enabled='0'` — build it correctly now, it becomes live when the owner flips the setting.

### 1.2 What PR-10 builds — and does NOT build (tripwires)

**Builds (§2 files):**
1. **IHModels DTOs** for the live-sync wire shapes incl. the client-side `blank`↔`displayState` bridge (§3.1–3.2).
2. **IHAPI**: 9 typed endpoints + decoding + the presence-token Cookie transport on `APIClient` (§4).
3. **IHLive `ServerLive/`**: the pure follower spine (`LiveFollowerReducer` — revision dedup, freshness, cadence policy, injected clock), `LiveFollowEngine` grown into the real host+follower actor, the new `ServiceModeEngine` congregant actor, device-identity + resume persistence (§3.3–3.6).
4. **IHFeatures**: `LiveHubView` filled with the real entries; unified join sheet; the following screen (reusing `SongComponentView`); a bottom status banner; `AppRootViewModel+LiveSync` as the ONE stream consumer; the one-line song-viewed hook that makes native hosting broadcast-as-you-navigate (§5–6).
5. **Fixtures** for every wire shape (live-recorded where recordable, code-derived-and-marked where a live session is required — §7.1 honesty rules).

**Does NOT build (reviewer rejects on sight):**
- The **native Service-Mode OPERATOR console** — `service_session_start` / `service_code_rotate` / `service_session_end` / venue+schedule pickers / a native `service_broadcast` driving surface. Deferred with reasons in §5.3 (D-2).
- Any `service_broadcast` call at all — that endpoint's native consumers are PR-14 (#1425 mirror-on-ack) and the deferred operator console.
- The tvOS server-following projector (PR-15 #1428) — but §3's engines are BUILT AS its future driver source (poll spine platform-agnostic, no UI imports).
- Live Activities / Dynamic Island (PR-16 #1429); APNs anything (#1410).
- Any LAN-remote coupling (header tripwire above); any lyric text on the poll wire (D-12); any backend change; any new external package; any `project.yml`/entitlement change (server HTTPS only — nothing to declare).
- Any new IHFeatures view using a #1549-class watchOS-unavailable API (`.segmented`, `Menu`, `draggable`/`dropDestination`, `DisclosureGroup`, `keyboardShortcut`, `ToolbarItemPlacement.navigation`) — §6.6.

### 1.3 The backend contract (BINDING — the exact wire shapes the DTOs decode; cited from `appWeb/public_html/api.php`)

All under `GET|POST /api?action=…` on the selected `APIEnvironment`. Every response is JSON. Native sends `X-Requested-With: XMLHttpRequest` on everything (already the case). "auth" = `Authorization: Bearer` required (`requiresAuth: true`); "anon" = none.

**Live Follow (#1268 spine, `api.php:13870-14239`):**

| Action | Method/Auth | Request | Success response | Non-success |
|---|---|---|---|---|
| `live_follow_create` | POST auth (`:13880`) | body `{songId?: String\|null, componentIndex?: Int\|null, setlistId?: String\|null, state?: {…}}` | `{ok:true, code:"ABC234", revision:0}` (`:13966`) | 401; 400 `Unknown song.`; 503 code-alloc; rate 20/h/user (`:13889`). Creating supersedes the host's prior active session (`:13924-13928`). |
| `live_follow_update` | POST auth (`:13970`) | body `{code: String, songId: String — REQUIRED per :13990, componentIndex?: Int\|null, state?: {…}}` | `{ok:true, revision:Int}` (`:14039`) | **409 `{ok:false,error}` = session superseded/ended/expired** (`:14026`) — the host's "you've been ended" signal; 400 unknown song; rate 600/min. |
| `live_follow_heartbeat` | POST auth (`:14043`) | body `{code}` | `{ok:Bool}` — `false` = session gone (`:14078`); does NOT bump revision (`:14055`) | — |
| `live_follow_leave` | POST auth (`:14082`) | body `{code}` | `{ok:true}` | — |
| `live_follow_join` | GET anon (`:14116`) | `?code=` (4–12 `[A-Z0-9]`, server strips/uppers) | `{ok:true, code, followToken:"<32 hex>", hostDisplayName:String, currentSongId:String\|null, componentIndex:Int\|null, state:{…}\|null, revision:Int}` (`:14171-14180`) | 404 `{ok:false,error:'Session not found or ended.'}` — deliberately OPAQUE (`:14155-14161`); 400 bad shape; rate 120/60s/IP (`:14129`). Join requires the session fresh within 180 s + `Channel` match (`:14140-14147`). `followToken` is a STATELESS poll-rate bucket, **not** authorisation (`:14163-14167`). |
| `live_follow_poll` | GET anon (`:14184`) | `?code=&since=<revision>&token=<followToken>` | `{active:false}` (dead/stale/unknown, `:14226`) \| `{changed:false, revision}` (`:14229` — note NO `active` field when alive-unchanged) \| `{changed:true, currentSongId, componentIndex, state, revision}` (`:14231-14237`) | rate 40/60s per token-hash (`:14200-14207`), 600/60s per IP token-less fallback. |

**Service Mode congregant (#1335, `api.php:14600-14783`):**

| Action | Method/Auth | Request | Success response | Non-success |
|---|---|---|---|---|
| `service_join` | POST anon (`:14600`) | body `{code: String, presenceDeviceId: String — REQUIRED, charset [A-Za-z0-9-] ≤64 per :14613-14614, venueId?: Int (omit — code-alone resolve, :14612), role?: "congregant"\|"projector" (:14617; native congregant sends "congregant")}` | `{ok:true, presenceToken:"<43-char base64url>", pollIntervalMs:Int, currentSongId:String\|null, componentIndex:Int\|null, state:{…}\|null, revision:Int}` (`:14683-14691`) | 404 `{ok:false,error:'That code isn't active. Check the screen and try again.'}` OPAQUE (`:14623-14628`); 400; **503 `{maintenance:true}` possible — distinguish "site down" from "wrong code" exactly as `service-follow.js:92-95` does**; rate 300/60s/IP (`:14607`). Re-join with the same deviceId re-tokens rather than duplicating (`:14648-14676`). |
| `service_poll` | GET anon (`:14695`) | `?presenceToken=&since=` | `{active:false}` (ended/expired/revoked/stale/malformed-token, `:14698`, `:14732`) \| `{active:true, changed:false, revision}` (`:14741`) \| `{active:true, changed:true, currentSongId, componentIndex, state, revision}` (`:14742-14749`) | rate 40/min per token-hash congregant (`:14711-14713`). Poll touches `LastSeenAt` (`:14735-14738`) — the congregant has NO separate heartbeat. |
| `service_leave` | POST anon (`:14753`) | body `{presenceToken}` | `{ok:true}` always (even malformed, `:14756`) — immediate presence revocation (`:14768-14771`) | — |

**The `state` object** (written ONLY by `serviceMode_cleanState()`, `service_mode.php:140-178`): `{displayState?: "live"|"blackout"|"logo", blank?: Bool, lineIndex?: Int 0–9999, scrollPct?: Double 0–1, transposeOffset?: Int −12…12}`. The cleaner ALWAYS (re)writes `displayState` and `blank` TOGETHER whenever either was sent (`:154-160`) — the client bridge in §3.1 leans on that guarantee.

**The content read the follower makes** is the EXISTING `song_detail` (`api.php:763-818`) via the EXISTING `apiClient.songDetail(id:)` — gated server-side by `contentGatingApply` incl. the presence unlock (`:810-815`). **Nothing on the poll wire ever carries lyric text** — `currentSongId` + indices + display intent only (D-12; the same invariant PR-4 enforces on the LAN link).

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh` — budget for two-register comments by splitting early). SwiftLint clean (`appApple/.swiftlint.yml`). Every file carries ELI5 + DETAILED headers referencing **#1426/#1427**, strategy §2.4.6, plan §2 PR-10 — match PR-6/7/8 comment density. Swift 6 strict concurrency; `AsyncStream`, never Combine.

### New — module `IHModels`

| File | Purpose (one line) |
|---|---|
| `LiveSync.swift` | `ServiceDisplayState` (the SERVER vocabulary), `LiveBroadcastState` (+ the pure `resolvedDisplayState` bridge), `LiveBroadcastSnapshot`, `LiveSyncEndReason`, `LiveFollowJoinInfo`, `ServiceJoinInfo` — all `Sendable + Equatable` value types, `Codable` where wire-facing (§3.1–3.2). |

### New — module `IHAPI`

| File | Purpose |
|---|---|
| `LiveSyncEndpoints.swift` | The 9 `Endpoint` factories (§4.1) — JSON bodies pre-encoded in the factory, exactly the `AuthEndpoints.swift` POST pattern (`Endpoint.swift:61-72`). |
| `LiveSyncDecoding.swift` | Wire envelopes (`LiveFollowCreateResponse`, `LiveFollowJoinResponse`, `ServiceJoinResponse`, `LivePollResponse`, `OkResponse`) + mapping into the IHModels types (§4.2). All-optional-fields tolerant decode for the three-shape poll envelope. |
| `APIClient+LiveSync.swift` | The typed async methods (§4.2) — thin `perform` wrappers, no logic. |

### New — module `IHLive`, subdirectory `Sources/IHLive/ServerLive/`

*(The new subdirectory makes the strategy §2.4.1 hard separation legible on disk, mirroring `LANRemote/`. SwiftPM is folder-agnostic within a target — zero manifest impact.)*

| File | Purpose |
|---|---|
| `LiveFollowEngine.swift` | **MOVED** (`git mv` from `Sources/IHLive/LiveFollowEngine.swift`) then grown into the real actor primary file: stored state (role, streams, tasks, config), `isFresh`/`freshnessWindowSeconds` **byte-preserved**, `init(apiClient:configuration:)` (config defaulted — existing call sites compile unchanged) (§3.4). |
| `LiveFollowEngine+Host.swift` | Host lifecycle: `goLive`/`broadcast` (dedup)/heartbeat loop/`endHosting` + the 409-means-ended handling (§3.4-H). |
| `LiveFollowEngine+Follower.swift` | Follower lifecycle: `join`/poll loop/`leaveFollow`, driving the shared reducer (§3.4-F). |
| `ServiceModeEngine.swift` | The congregant actor: `join`/poll loop/`leave`/`resumeIfPossible`, presence-token custody + the `apiClient.updateServicePresenceToken` wiring (§3.5). |
| `LiveFollowerReducer.swift` | **The pure shared spine** — `LiveFollowerState`, `applyPoll`/`applyFailure`, `isFresh` (delegating to `LiveFollowEngine.isFresh`), `nextPollDelay` cadence table (§3.3). No `Date()` reads, no sleeps — pure functions of inputs. |
| `LiveSyncEvents.swift` | `LiveFollowEvent` + `ServiceModeEvent` — the `AsyncStream` vocabularies (§3.4/3.5). Tokens/codes NEVER ride an event payload. |
| `LiveSyncConfiguration.swift` | Injected clock/sleep + the cadence literals (fallbacks 2500/4000 ms, idle floor 15 s, heartbeat 30 s, clamps) — ONE home for every number, cited to its web/backend source (§3.3). |
| `ServiceDeviceIdentity.swift` | Durable anonymous device id (UserDefaults-backed, injectable store) — the native `SF_DEVICE_KEY` (`service-follow.js:55-66`) equivalent (§3.6, D-7). |
| `LiveSyncPersistence.swift` | Resume records (host code / follow code+token / presence token+revision+joinedAt) — UserDefaults-backed, injectable, 4 h-ceiling-aware (§3.6, D-5/D-10). |

### New — module `IHFeatures`

| File | Purpose |
|---|---|
| `AppRootViewModel+LiveSync.swift` | The ONE consumer of both engines' event streams (§6.5) → `@Observable` published live state; `joinWithCode`/`goLive`/`leaveLive`/`liveSongViewed` façade; sign-out force-ends hosting; scenePhase forwarding. |
| `LiveJoinSheet.swift` | Code entry (strip-then-validate, `live-follow.js:231-239` parity) → the unified join (D-8) (§6.2). |
| `LiveFollowingView.swift` | The follower screen: header (host/service + freshness), the current song rendered via shared `SongComponentView` rows in a `ScrollViewReader`, scroll-to-`componentIndex`, Leave (§6.3). |
| `LiveHostControlsView.swift` | Hosting console section: the big shareable code (`ShareLink`), current broadcast song, End (§6.1). |
| `LiveStatusBanner.swift` | The app-wide bottom banner while hosting/following (web green/blue banner parity), `safeAreaInset`-hosted (§6.4). |

### New — tests (all always-on; no env-gated suites — nothing here needs a real network)

| File | Purpose |
|---|---|
| `Tests/IHModelsTests/LiveSyncModelTests.swift` | Fixture decodes + the `resolvedDisplayState` bridge table (§7.2). |
| `Tests/IHAPITests/LiveSyncAPITests.swift` | Request building (action/method/auth/body/query) for all 9 endpoints; the presence-Cookie transport rows (§7.2). |
| `Tests/IHLiveTests/ServerLive/LiveFollowerReducerTests.swift` | The pure spine, exhaustively — injected clocks only (§7.2). |
| `Tests/IHLiveTests/ServerLive/LiveFollowEngineLoopTests.swift` | Host + follower loops vs `MockURLProtocol`, injected recording sleeper (§7.3). |
| `Tests/IHLiveTests/ServerLive/ServiceModeEngineTests.swift` | Congregant lifecycle vs `MockURLProtocol` incl. presence custody + resume (§7.3). |
| `Tests/IHFeaturesTests/LiveSyncUIStateTests.swift` | `@MainActor` view-model state transitions + banner/copy mapping (§7.4). |

### Edited

| File | Edit |
|---|---|
| `Sources/IHLive/LiveFollowEngine.swift` | REMOVED (moved into `ServerLive/` — see above). |
| `Sources/IHAPI/APIClient.swift` | + `private(set) var servicePresenceToken: String?` + `updateServicePresenceToken(_:)` (mirrors `bearerToken`/`updateBearerToken` `:66`, `:108-110`); `makeURLRequest` gains a DEFAULTED `presenceToken: String? = nil` parameter → sets `Cookie: ihymns_sf_presence_token=<token>` + `httpShouldHandleCookies = false` when non-nil (§4.3). All existing call sites/tests compile byte-unchanged. |
| `Sources/IHAPI/APIClient+Networking.swift` | The `makeURLRequest` call site (`:128-131`) passes `presenceToken: servicePresenceToken`. |
| `Sources/IHFeatures/AppRootViewModel.swift` | `private let liveFollowEngine` (`:208`) → `let` (internal — the `apiClient:201` precedent); + `let serviceModeEngine: ServiceModeEngine`; init (`:256`) gains DEFAULTED `serviceModeEngine: ServiceModeEngine? = nil` → `?? ServiceModeEngine(apiClient: apiClient)` (zero call-site breakage); init also starts the live observation task (mirrors `observeSessionState()` `:274`) + cancels it in `deinit` (the `sessionObservationTask` `nonisolated(unsafe)` pattern `:253-254`, applied identically). |
| `Sources/IHFeatures/AppRootViewModel+Live.swift` | `makeLive` constructs + passes an explicit `ServiceModeEngine` (composition root stays the ONE real wiring). |
| `Sources/IHFeatures/LiveHubView.swift` | Rewritten (§6.1): real Live Follow + Join-with-code + Service copy sections replace the `ContentUnavailableView` coming-soon block (`:44-50`); TV Remote section byte-unchanged. |
| `Sources/IHFeatures/RootContainerView.swift` | + `.safeAreaInset(edge: .bottom)` hosting `LiveStatusBanner` on BOTH `tabbedRoot` and `splitView` (one private computed banner var, applied twice); + `.onChange(of: scenePhase)` forwarding to `viewModel.setLiveScenePhaseActive(_)` (the `RemoteControlView.swift:28,39` precedent) (§6.4/6.6). |
| `Sources/IHFeatures/SongDetailView.swift` | ONE line + comment: the existing per-song appear path also calls `rootViewModel.liveSongViewed(songId)` — the native equivalent of `live-follow.js initSongPage`'s host broadcast hook (`live-follow.js:84-103`) (§6.5). |
| `Package.swift` | `IHTestFixtures` target: add the new fixture `.copy(...)` resource entries (§7.1). NO dependency-graph change (`IHLive → IHAPI, IHModels, IHLog` already holds, `Package.swift:22-33`). |
| `Tests/Fixtures/README.md` + fixtures | + the new fixture files + provenance notes incl. the code-derived honesty block (§7.1). |
| `tools/apple-refresh-fixtures.sh` | + re-record lines for the live-recordable negative fixtures (§7.1). |

**No edits to:** anything under `Sources/IHLive/LANRemote/` or the `RemoteControl*`/`TV*`/`Projection*` IHFeatures files (the OTHER live subsystem); `project.yml` (no new entitlement — plain HTTPS to the existing API hosts); `apple.yml`; `IHAuth`/`IHPersistence`; the web backend (ZERO PHP diff).

---

## 3. The pure cores + the two engines (BINDING shapes — do not improvise)

Discipline restated from PR-8 §3: **no wall-clock reads, no real sleeps in anything testable.** Every time-driven decision is a pure function of injected `Date`s/`Duration`s; the actor loops thread an injected `sleep`/`now` pair from `LiveSyncConfiguration`.

### 3.1 `ServiceDisplayState` + `LiveBroadcastState` — the client half of the `blank`↔`displayState` bridge (IHModels, pure)

```swift
/// The SERVER display vocabulary (service_mode.php:63) — NOT the LAN IHRPDisplayState.
public enum ServiceDisplayState: String, Sendable, Codable, Equatable, CaseIterable {
    case live, blackout, logo
}

public struct LiveBroadcastState: Sendable, Equatable, Codable {
    public let displayStateRaw: String?   // CodingKey "displayState" — kept RAW (a VARCHAR vocabulary can grow server-side, rule #20)
    public let blank: Bool?
    public let lineIndex: Int?
    public let scrollPct: Double?
    public let transposeOffset: Int?

    /// The client half of serviceMode_cleanState()'s bidirectional bridge
    /// (service_mode.php:118-160): a RECOGNISED displayState wins; an
    /// unrecognised/absent one falls back to the legacy `blank` boolean
    /// (true → .blackout — fail toward HIDDEN, the same direction the
    /// server bridge degrades `logo` for legacy clients); nothing → .live.
    public var resolvedDisplayState: ServiceDisplayState {
        if let raw = displayStateRaw, let known = ServiceDisplayState(rawValue: raw) { return known }
        if let blank { return blank ? .blackout : .live }
        return .live
    }
}
```

The server cleaner guarantees `blank` is present whenever `displayState` is (`service_mode.php:154-160`), so the fallback chain is total in practice; the table in §7.2 tests every row including the theoretical unknown-value-no-blank → `.live` case.

### 3.2 `LiveBroadcastSnapshot` + join-info shapes (IHModels)

```swift
public struct LiveBroadcastSnapshot: Sendable, Equatable {
    public let songId: String?          // RAW server id — see D-13 (legacy non-SongID ids exist)
    public let componentIndex: Int?
    public let state: LiveBroadcastState?
    public let revision: Int
}

public struct LiveFollowJoinInfo: Sendable, Equatable {
    public let code: String
    public let hostDisplayName: String
    public let initial: LiveBroadcastSnapshot
    // followToken deliberately NOT here — engine-internal custody (§3.4-F), never rides a UI-facing type.
}

public struct ServiceJoinInfo: Sendable, Equatable {
    public let pollIntervalMs: Int?
    public let initial: LiveBroadcastSnapshot
    // presenceToken deliberately NOT here — engine custody only (§3.5, D-5).
}

public enum LiveSyncEndReason: String, Sendable, Equatable {
    case userLeft, serverEnded, superseded, expired, signedOut
}
```

**D-13 (songId leniency):** `LiveBroadcastSnapshot.songId` stays a raw `String`. The UI layer maps it through `SongID(rawValue:)` (`SongID.swift:120`); the ~10 legacy ids that don't parse (fixtures README's "Here To Stay" rows) render a calm "This song can't be displayed on this device yet" row instead of crashing or silently dropping the follow. Never tighten the DTO to `SongID` — that would make one legacy broadcast kill the decode of the whole poll response.

### 3.3 `LiveFollowerReducer` — the ONE shared poll spine (pure)

Both engines poll a `(since:) → LivePollResponse`-shaped endpoint, dedupe on `revision`, treat `active == false` as a positive end signal, degrade to "stale" on 180 s of failed contact, and choose the next delay from the same cadence table. That is ONE state machine, written ONCE:

```swift
public struct LiveFollowerState: Sendable, Equatable {
    public var revision: Int                 // monotonic high-water (== the server's stateVersion, plan §0.1)
    public var lastContactAt: Date?          // last SUCCESSFUL poll/join response
    public var consecutiveFailures: Int
    public var isIdle: Bool                  // following screen not frontmost (§6.6)
    public var latest: LiveBroadcastSnapshot?
}

public enum LiveFollowerEffect: Sendable, Equatable {
    case apply(LiveBroadcastSnapshot)        // changed == true and revision advanced
    case sessionEnded                        // active == false — leave, clear custody
    case none                                // unchanged / stale-revision duplicate
}

public enum LiveFollowerReducer {
    /// Poll response arrived. Pure. `revision <= state.revision` NEVER applies
    /// (an out-of-order or duplicate response must not rewind the display).
    public static func applyPoll(_ r: LivePollUpdate, state: inout LiveFollowerState, now: Date) -> LiveFollowerEffect

    /// Poll threw (network / 5xx / decode). Pure — bumps consecutiveFailures,
    /// never touches revision/lastContactAt. Always returns .none: transient
    /// failure is NEVER an end signal (web parity: live-follow.js:330-331
    /// "keep the session, retry next tick"); only active:false or the 180s
    /// freshness lapse (below) surface to the user.
    public static func applyFailure(state: inout LiveFollowerState, now: Date)

    /// Freshness = LiveFollowEngine.isFresh(lastUpdatedAt: lastContactAt, now:)
    /// — the EXISTING seam, delegated to, never re-derived (180 s, strict <).
    public static func isFresh(_ state: LiveFollowerState, now: Date) -> Bool

    /// The cadence table (below). Pure function of state + the server-declared
    /// interval + the configuration literals.
    public static func nextPollDelay(_ state: LiveFollowerState, serverIntervalMs: Int?, config: LiveSyncConfiguration) -> Duration
}
```

**The cadence table (BINDING — every number has a cited source):**

| Condition | Delay | Source |
|---|---|---|
| base, Service Mode | `clamp(serverIntervalMs, 1000…60000)` ms when the join declared it; else **4000 ms** | server-declared `pollIntervalMs` wins (plan §0.2, `api.php:14686`); 4 s fallback = strategy §2.4.6 "~4 s active" for an old backend that omits the field. Clamp is defensive only. |
| base, Live Follow follower | **2500 ms** (no server field on this endpoint) | web parity `LF_POLL_MS` (`live-follow.js:25`); 24 polls/min sits comfortably inside the 40/min per-token budget (`api.php:14206`). |
| `isIdle == true` (following screen not frontmost, app still active) | `max(base, 15 s)` | strategy §2.4.6 "15–30 s idle" — bottom of the band; 4/min. |
| NOT fresh (180 s no contact) | `max(base, 15 s)` | battery: a dead network shouldn't be hammered at 2.5 s; the reducer keeps trying forever until `active:false` or the user leaves. |
| `consecutiveFailures = n > 0` | `min(base × 2^min(n,3), 15 s)` | bounded backoff; resets to base on the first success. |
| scenePhase ≠ `.active` | **loop cancelled entirely** (no delay — no loop) | strategy §1.3 "Live loops = structured Task… cancelled on scene-phase `.background`… restarted `.active`". |

`LiveSyncConfiguration` (one home for the literals + the injected clock):

```swift
public struct LiveSyncConfiguration: Sendable {
    public var liveFollowPollFallback: Duration = .milliseconds(2500)   // live-follow.js:25
    public var servicePollFallback: Duration = .milliseconds(4000)      // strategy §2.4.6
    public var serverIntervalClamp: ClosedRange<Int> = 1000...60000
    public var idleFloor: Duration = .seconds(15)                        // strategy §2.4.6
    public var heartbeatInterval: Duration = .seconds(30)                // live-follow.js:26; 6× inside the 180 s window (service_mode.php:36-45)
    public var now: @Sendable () -> Date = { Date() }
    public var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
}
```

Tests inject `now` sequences and a recording no-op `sleep` — zero wall-clock anywhere in §7.

### 3.4 `LiveFollowEngine` — grown from the skeleton into the real host+follower actor

**Primary file (`ServerLive/LiveFollowEngine.swift`, the moved file):** keeps `freshnessWindowSeconds`/`isFresh` byte-identical and adds the stored state:

```swift
public actor LiveFollowEngine {
    public static let freshnessWindowSeconds: TimeInterval = 180        // UNCHANGED
    nonisolated public static func isFresh(lastUpdatedAt: Date, now: Date) -> Bool  // UNCHANGED

    public enum Role: Sendable, Equatable { case idle, hosting(code: String), following(code: String) }

    // Stored (actor body — extensions can't add stored props):
    let apiClient: APIClient                       // widened private→internal for the +Host/+Follower files
    let config: LiveSyncConfiguration
    let persistence: LiveSyncPersistence
    private(set) var role: Role = .idle
    var followerState: LiveFollowerState           // reducer state while following
    var followToken: String?                       // poll bucket token — NEVER leaves the actor
    var lastBroadcastKey: String?                  // host dedup "songId|componentIndex" (live-follow.js:41,173-174)
    var loopTask: Task<Void, Never>?               // the ONE poll-or-heartbeat loop
    var suspended = false                          // scenePhase mirror
    nonisolated public let events: AsyncStream<LiveFollowEvent>
    // + the private continuation, AsyncStream.makeStream(of:) at init —
    //   the ProjectionViewModel.stateUpdates idiom, ONE consumer (§6.5).

    public init(apiClient: APIClient,
                configuration: LiveSyncConfiguration = LiveSyncConfiguration(),
                persistence: LiveSyncPersistence = .standard)   // defaulted ⇒ makeLive + every existing test compiles unchanged
}
```

**Mutual exclusivity is web parity** (`live-follow.js:64-69` "host wins"): `goLive` while following throws `LiveSyncError.alreadyFollowing`; `join` while hosting throws `.alreadyHosting`. One `loopTask` slot enforces one loop.

**`+Host.swift` (H):**
- `goLive(songId: String?, componentIndex: Int?) async throws -> String` → `live_follow_create`; on success: role = `.hosting(code)`, seed `lastBroadcastKey`, persist `{code}`, start the heartbeat loop (interval `config.heartbeatInterval`), emit `.hostingStarted(code:)`.
- `broadcast(songId: String, componentIndex: Int?) async` — dedup on the key; POST `live_follow_update`; **HTTP 409 ⇒ the session was superseded/ended server-side** (`api.php:14026`) → `endHostingLocally(reason: .superseded)` (web parity `live-follow.js:180-185`). Transient errors: swallow, keep the key UNSET so the next navigation retries (`live-follow.js:187`).
- Heartbeat loop: sleep(30 s) → `live_follow_heartbeat`; `{ok:false}` ⇒ ended (`.serverEnded`); errors tolerated (next beat retries). **Wake-beat:** `setScenePhaseActive(true)` while hosting fires an IMMEDIATE beat before the loop resumes — the exact revival trick `live-follow.js:193-201` documents (LastHeartbeatAt refresh un-stales a session that lapsed while backgrounded, since `ExpiresAt` is 4 h and only freshness lapsed).
- `endHosting() async` — POST `live_follow_leave` (best-effort), clear persistence, role `.idle`, emit `.hostingEnded(.userLeft)`.
- `endHostingForSignOut()` — same, reason `.signedOut` (an unauthenticated host can't heartbeat; web parity `live-follow.js:75-79`).

**`+Follower.swift` (F):**
- `join(code: String) async throws -> LiveFollowJoinInfo` → GET `live_follow_join`; on success: stash `followToken` (actor-only), role = `.following(code)`, reducer state seeded (`revision`, `lastContactAt = now`, `latest = initial`), persist `{code, token, revision}`, start the poll loop, emit `.followingStarted(...)`. 404 → throw `LiveSyncError.codeNotActive` (the caller's unified-join fallback, §6.2).
- Poll loop: sleep(`nextPollDelay`) → GET `live_follow_poll(code:since:token:)` → `LiveFollowerReducer.applyPoll`; `.apply` ⇒ emit `.snapshot`; `.sessionEnded` ⇒ leave-locally + emit `.followingEnded(.serverEnded)`; failures → `applyFailure`; freshness transitions emit `.freshnessChanged(Bool)` (edge-triggered, not per-tick).
- `leaveFollow() async` — no server call exists for an anonymous LF follower (the web just stops polling, `live-follow.js:277-289`); clear state/persistence, emit `.followingEnded(.userLeft)`.

### 3.5 `ServiceModeEngine` — the congregant actor (the #1427 core)

Same spine, Service-Mode specifics: presence token custody, server-declared cadence, the gating hook, resume.

```swift
public actor ServiceModeEngine {
    public enum Phase: Sendable, Equatable { case idle, following }
    let apiClient: APIClient
    let config: LiveSyncConfiguration
    let deviceIdentity: ServiceDeviceIdentity
    let persistence: LiveSyncPersistence
    private(set) var phase: Phase = .idle
    var followerState: LiveFollowerState
    private var presenceToken: String?             // THE gate key — never leaves the actor raw (D-5)
    var pollIntervalMs: Int?                       // server-declared (join response)
    var loopTask: Task<Void, Never>?
    var suspended = false
    nonisolated public let events: AsyncStream<ServiceModeEvent>

    public init(apiClient: APIClient,
                configuration: LiveSyncConfiguration = LiveSyncConfiguration(),
                deviceIdentity: ServiceDeviceIdentity = .standard,
                persistence: LiveSyncPersistence = .standard)
}
```

- `join(code: String) async throws -> ServiceJoinInfo` → POST `service_join` `{code, presenceDeviceId: deviceIdentity.id, role: "congregant"}`; on success: custody the token, **`await apiClient.updateServicePresenceToken(token)`** (the D-6 gating hook — done HERE, inside the engine, so it can never be forgotten by a caller), persist `{token, revision, joinedAt: now}`, start the poll loop, emit `.joined(initial:)`. Failure mapping: 404 → `.codeNotActive`; 503/`maintenance` → `.temporarilyUnavailable` (distinct copy, `service-follow.js:92-95` parity); else `.network`.
- Poll loop: identical spine via `LiveFollowerReducer` with `serverIntervalMs: pollIntervalMs`. `active:false` ⇒ ended: clear custody (token nil + `apiClient.updateServicePresenceToken(nil)` + persistence clear), emit `.left(.serverEnded)` (web parity toast "The service has ended", `service-follow.js:160-164`).
- `leave() async` — POST `service_leave` (best-effort — the server revokes presence immediately, `api.php:14768-14771`), THEN clear custody exactly as above, emit `.left(.userLeft)`. **Order matters:** revoke server-side first, then forget — mirroring the "server-revoke THEN delete" discipline strategy §3.2 mandates for the account token.
- `resumeIfPossible() async` — cold-start: if a persisted record exists AND `joinedAt` is within the 4 h hard ceiling (`SERVICE_MODE_HARD_CEILING_HOURS`, `service_mode.php:35` — don't even poll a certainly-dead token), restore custody + fire ONE immediate poll: `active:false` ⇒ silent cleanup; else resume following. This is the native `sessionStorage` resume (`service-follow.js:39-52`), deliberately surviving relaunch (D-5 rationale).
- `presenceTokenFingerprint` (nonisolated-safe accessor returning `IHLogSanitize.tokenFingerprint(token)` or nil) — the ONLY externally visible trace of the token, for diagnostics/logging.

**Events:** `ServiceModeEvent.presenceChanged` carries NO payload (the UI only needs "am I following"; the raw token never rides an event — redaction by construction).

### 3.6 Identity + persistence (custody decisions D-5/D-7)

- `ServiceDeviceIdentity` — `UUID().uuidString` minted once, stored in **UserDefaults** (injectable suite for tests) under `"live.serviceDeviceId"`. Matches the web's durable `localStorage` device id (`service-follow.js:55-66`; already `[A-Za-z0-9\-]` ≤64-safe for `api.php:14613`). **NOT Keychain** (would outlive app deletion — MORE tracking-persistent than the anonymous web id, the wrong direction for a "not collected" privacy posture, strategy §3.1), **NOT** `identifierForVendor` (resets unpredictably, ties to vendor identity).
- `LiveSyncPersistence` — three UserDefaults records (JSON-encoded, injectable store): `"live.hostSession"` `{code}`, `"live.followSession"` `{code, token, revision}`, `"live.servicePresence"` `{token, revision, joinedAt}`. **UserDefaults, deliberately NOT Keychain and NOT synchronizable** (D-5): the presence token is an anonymous, ≤4 h, server-revocable room key — not a credential; Keychain would (a) survive app deletion and (b) invite the `synchronizable:true` copy-paste failure PR-6 §8 guards against (a presence token synced to a device NOT in the room would defeat proof-of-presence). The web keeps it in per-tab `sessionStorage` (`service-follow.js:18`); local-only UserDefaults is the closest native equivalent that still survives a mid-service relaunch.

---

## 4. The API layer (IHAPI)

### 4.1 `LiveSyncEndpoints.swift` — the 9 factories

Static `Endpoint` factories, JSON bodies pre-encoded in the factory (the `AuthEndpoints` pattern — `Endpoint.swift:61-72`); GET params via `queryItems` (never string-built — `APIClient.makeURLRequest` percent-encodes through `URLComponents`, `APIClient.swift:147-150`):

| Factory | action | Method | requiresAuth |
|---|---|---|---|
| `.liveFollowCreate(songId:componentIndex:)` | `live_follow_create` | POST | **true** |
| `.liveFollowUpdate(code:songId:componentIndex:)` | `live_follow_update` | POST | **true** |
| `.liveFollowHeartbeat(code:)` | `live_follow_heartbeat` | POST | **true** |
| `.liveFollowLeave(code:)` | `live_follow_leave` | POST | **true** |
| `.liveFollowJoin(code:)` | `live_follow_join` | GET | false |
| `.liveFollowPoll(code:since:token:)` (`token` optional → omitted item) | `live_follow_poll` | GET | false |
| `.serviceJoin(code:presenceDeviceId:role:)` | `service_join` | POST | false |
| `.servicePoll(presenceToken:since:)` | `service_poll` | GET | false |
| `.serviceLeave(presenceToken:)` | `service_leave` | POST | false |

`state` is deliberately NOT sent by any native writer in this PR (the host broadcasts song/section only, exactly like the web host `live-follow.js:176-179`; display-state writing is the projector/operator's job — PR-15/deferred console). The DTOs DECODE it fully (§3.1) so followers understand v2 broadcasters.

### 4.2 Typed methods + decoding

`APIClient+LiveSync.swift` — one thin method per endpoint, returning the §3.2 IHModels shapes via `LiveSyncDecoding.swift` envelopes. The poll envelope is ONE tolerant struct for both endpoints (their shapes are compatible unions — §1.3's three-row tables):

```swift
public struct LivePollUpdate: Sendable, Equatable, Codable {
    public let active: Bool?      // absent on live_follow_poll's alive rows (api.php:14229) — absent ≠ false
    public let changed: Bool?
    public let currentSongId: String?
    public let componentIndex: Int?
    public let state: LiveBroadcastState?
    public let revision: Int?
}
```

**Decode rule (BINDING):** `active == false` is the ONLY end signal; `active == nil` means alive (the live_follow_poll unchanged/changed rows simply omit it). The reducer (§3.3) owns that interpretation — decoding stays dumb. POSTs that answer `{ok:true/false, revision?}` decode through one `OkResponse {ok: Bool?, revision: Int?, error: String?}`. A `live_follow_update` HTTP 409 must surface as a TYPED outcome (not a thrown generic) so the host engine can distinguish "superseded" from "network blip" — follow whatever `APIError` classification pattern `APIClient+Networking.classify` already produces for 4xx (builder: read `APIError.swift` and map `.some(409-shaped case)` → `LiveSyncError.superseded` in the engine; if `classify` folds 409 into a generic client-error case, add the narrowest possible mapping in the ENGINE, not by widening `APIError`).

### 4.3 The presence-token → CCLI-unlock transport (D-6 — the one genuinely novel plumbing decision)

**Problem:** the backend's presence unlock on `song_detail`/`random` reads ONLY the `ihymns_sf_presence_token` COOKIE (`api.php:810-815`, `:693-698` — `$_COOKIE`, gated behind `content_gating_enabled === '1'`). Native sends Bearer headers and owns no browser cookie jar. PR-10 is forbidden backend changes.

**Decision D-6:** `APIClient` carries the presence token exactly the way a browser does — as that ONE named cookie, attached by `makeURLRequest` while a Service-Mode presence is active:

- `updateServicePresenceToken(_:)` set by `ServiceModeEngine` on join/leave/end (§3.5) — the engine, not the UI, owns the lifecycle, so the gate can't dangle.
- When `servicePresenceToken != nil`, `makeURLRequest` sets `Cookie: ihymns_sf_presence_token=<token>` **on every request to the selected environment** (mirrors the browser, which sends it on every same-origin request — the server ignores it everywhere except the two gating reads; zero per-endpoint drift) and sets `request.httpShouldHandleCookies = false` **on those requests only** (determinism: URLSession's shared `HTTPCookieStorage` must never merge/override/persist our explicit header — Apple docs on `httpShouldHandleCookies`; when the token is nil the request is byte-identical to today).
- The token value is a 43-char base64url string (`api.php:14642`) — no characters needing cookie-escaping; assert the shape (`^[A-Za-z0-9_-]{43}$`) before attaching, drop silently otherwise (defence against a corrupted persisted value ever reaching the wire).
- **Why not a query param:** tokens in URLs persist into server access logs — the exact thing strategy §3.2's "Bearer header (never cookie/URL …) stays out of access logs" rule exists to prevent; a Cookie header, like Authorization, is not access-logged.
- **Why not a new `X-Presence-Token` header:** that's a backend change (forbidden here). File it as a `for consideration` cleanliness ask if the owner ever wants it; the cookie route is contract-faithful today and stays correct even after such a header exists.
- **Redaction:** IHAPI/IHLog must never log request headers (they don't today — request logs carry action names + status). Binding rule for every new file: no `IHLog` interpolation of `Cookie`, the token, the followToken, or any join code — only `IHLogSanitize.tokenFingerprint` for the two high-entropy tokens, NOTHING for codes (`IHLogSanitize.swift`'s own "NEVER for a CODE" warning).

**Effect when gating activates:** a congregant who joined a service in the native app fetches `song_detail` under their own (possibly anonymous) auth + the presence cookie → `contentGatingApply` OR-grants `view_copyrighted`/`play_audio` via `serviceMode_presenceCcliNumber` (`content_gating.php:214-219`) for exactly the presence window — identical semantics to the web congregant, achieved with zero backend diff. Today (`content_gating_enabled='0'`) the cookie is read by nobody and the whole path is a verified no-op — same dormancy posture as the backend itself (rule #28-A).

**Bearer + presence coexist:** a signed-in congregant sends BOTH `Authorization` and the presence cookie — precisely the web's signed-in-user-in-a-service case; `contentGatingApply` already unions the grants (`content_gating.php:218-219`).

---

## 5. The follower render path, displayState mapping, and the host/leader scope decision

### 5.1 The follower render path (content is API-fetched, never wire-carried)

`LiveFollowingView` (§6.3) receives snapshots from the view-model and:
1. Maps `snapshot.songId` → `SongID(rawValue:)`; on change, fetches via the EXISTING `rootViewModel.songDetail(id:)` (`AppRootViewModel+Catalog.swift:28-31`) — the same read the rest of the app uses, so offline cache behaviour, retry, and (dormant) content gating all apply for free. Unparsable id → the D-13 calm row.
2. Renders the fetched `SongDetail`'s components as shared **`SongComponentView`** rows inside a `ScrollViewReader`, each row `.id(index)`; on `componentIndex` change, `withAnimation { proxy.scrollTo(index, anchor: .top) }` — the native `_scrollToComponent` (`service-follow.js:197-203`). **This is composition of the shared renderer, NOT a fork** — `SongComparisonView` already established reusing `SongComponentView` outside `SongDetailView` (its `#1445 UPDATE` header); `SongDetailView` itself is the wrong host here (it composes toolbar/media/related/works sections a follow screen must not carry, and it owns per-screen state this screen doesn't want).
3. A gated fetch (`APIError.unauthorized` → the `SongResult.unavailable`-style outcome) renders "This song isn't available on your account" — content stays the reader's own entitlement problem, per the same rule the TV applies (strategy §2.4.3's "content entitlements ride the device's OWN auth", which D-6's presence cookie may satisfy when gating is on).

### 5.2 `displayState` on a congregant device (D-4 — web parity, deliberately minimal)

**Verified fact:** BOTH web follower clients apply ONLY `currentSongId` + `componentIndex` and ignore `state` entirely (`service-follow.js:120,169` and `live-follow.js:271,327-329` — `_applyState(d.currentSongId, d.componentIndex)`; `d.state` is never read). `displayState` today drives the PROJECTOR (`/manage/service-projection.php`), not congregant phones — a blackout is a venue-screen concern; blanking a congregant's personal reading device mid-hymn would be strictly worse UX than the web.

**Decision D-4:** the native congregant does the same — snapshots carry the fully-decoded `state` (DTO complete, PR-15's projector will consume it), the following screen applies song + componentIndex, and surfaces `resolvedDisplayState != .live` only as a small passive status line ("The venue display is paused") with the lyrics left readable. `lineIndex`/`scrollPct`/`transposeOffset` are decoded, not applied (web parity again; PR-15 territory). Never invent congregant-side blackout behaviour the web doesn't have — two client generations must not desync on visible behaviour (the same reasoning as the server's blank-bridge).

### 5.3 The host/leader scope decision (D-2 — what of #1426 ships here)

**IN (this PR):**
- **Live Follow HOST** — the native "Go Live": `live_follow_create` from the Live hub, broadcast-as-you-navigate via the `SongDetailView` hook (`liveSongViewed` → `broadcast`, dedup in the engine), 30 s heartbeat + wake-beat, End. This IS "a leader starts/drives a Live-Follow session" — #1426's leader half, and the native "service-lead" in the sense strategy §2.4.7 assigns to the #1104 remainder: driving a SERVER session as a broadcaster. Auth = the user's existing Bearer session; the server enforces host ownership per-call (`HostUserId` in every UPDATE, `api.php:14020`).
- **Live Follow FOLLOWER** (join by code) + **Service Mode CONGREGANT** (#1427 complete).

**OUT (deferred, with reasons — not laziness):** the native **Service-Mode operator console** (start/rotate/end a VENUE session; drive it via `service_broadcast`):
1. It is org-admin/operator-gated and depends on venue + schedule admin data (`tblOrgVenues`/`tblOrgServiceSchedules` pickers, `api.php:14264-14302`) — none of those reads exist in the native endpoint catalogue, and building them drags org-administration into a congregant-features PR.
2. The web already serves it responsively (`/manage/service-projection.php` + `/manage/service-lead`, rule #26) from any phone browser — the operator is by definition a privileged user with web access.
3. The native consumers of `service_broadcast` are already scheduled: PR-14 (#1425, mirror-on-ack from the LAN remote coordinator) and PR-15 (#1428 projector context). Building a third bespoke console now would pre-empt how those two want to share it.

**Issue bookkeeping (PR body):** close **#1426** (host+join shipped) and **#1427** (congregant shipped); comment on **#1104** that the same-room case was fulfilled by the LAN remote (PR-4→8), the Live-Follow server-broadcaster case by THIS PR, and the remaining native Service-Mode OPERATOR console is deferred — leave #1104 open (or re-scope it to exactly that console) for the owner to decide. If the owner wants it, it composes cleanly on this PR's IHAPI layer + PR-9's control tokens (#1408).

---

## 6. UI — filling the `.live` section

### 6.1 `LiveHubView` rewrite

Replace the coming-soon `ContentUnavailableView` (`LiveHubView.swift:44-50`) with real content; the TV Remote section (`:34-42`) stays byte-identical and FIRST (it shipped first, users know it):

```
List {
  [ACTIVE STATE — only when hosting or following]
    Section("Now") {
      hosting  → LiveHostControlsView (code via ShareLink, current song title, End)
      following→ NavigationLink → LiveFollowingView (status row: "Following <host>" / "Following the service", freshness dot)
    }
  Section("TV Remote")            // UNCHANGED from PR-7
  Section("Follow Along") {
    Button "Join with a Code…"    → LiveJoinSheet          // the ONE join entry (D-8)
    footer: "Enter the code from the venue screen or your worship leader to follow the service live."
  }
  Section("Lead") {               // only when rootViewModel.currentUser != nil
    Button "Go Live"              → vm.goLive() → shows LiveHostControlsView
    footer: "Broadcast the songs you open to everyone following your code."
  }                               // signed out: a single footer line "Sign in to lead a live session." — no dead button
}
```

`LiveHostControlsView`: the 6-char code in a large monospaced `Text` + `ShareLink(item: code)` (available on every platform incl. watchOS 9+ — #1549-safe), the current broadcast song title (or "Open a song to broadcast it"), a destructive End button with `confirmationDialog`. No custom pasteboard code — `ShareLink` covers copy.

### 6.2 `LiveJoinSheet` — the unified join (D-8)

- One `TextField` (`.textInputAutocapitalization(.characters)`, `.autocorrectionDisabled()`, monospaced), Join + Cancel buttons, inline error `Text`.
- **Strip-then-validate** exactly like the web (`live-follow.js:231-239`): uppercase, strip `[^A-Z0-9]` (tolerating spaces/hyphens/NBSP/smart-punctuation), then `^[A-Z0-9]{4,12}$`.
- **Unified join order (web-parity, `service-follow.js:97-109` / #1386):** try `serviceModeEngine.join(code:)` first (venue codes rotate every 75 s — the more time-sensitive namespace); on `.codeNotActive`, fall back to `liveFollowEngine.join(code:)`; only if BOTH miss show ONE combined error ("That code isn't active right now. Check the screen and try again."). Maintenance (503) short-circuits with its own non-blaming copy BEFORE the fallback ("iHymns is briefly unavailable — try the code again in a minute. The code is fine.", `service-follow.js:92-95` parity).
- On success: dismiss; the hub's active section + banner appear; auto-push `LiveFollowingView`.

### 6.3 `LiveFollowingView`

Header: source line ("Following the service" (green tint) vs "Following <hostDisplayName>" (blue tint) — the web's two banner colours translated to an accessible label + tint, never colour-only), a freshness indicator ("Live" / "Reconnecting…" driven by `.freshnessChanged`), Leave button (`confirmationDialog`). Body per §5.1 (shared `SongComponentView` rows + `ScrollViewReader`); empty state before the first broadcast: "Waiting for the first song…". The passive §5.2 display-status line sits under the header when `resolvedDisplayState != .live`. All APIs used: `List`/`ScrollViewReader`/`Text`/`Button`/`NavigationLink`/`confirmationDialog` — watch-safe (§6.6).

### 6.4 `LiveStatusBanner` + `RootContainerView`

- A slim capsule bar: icon + "Following live" / "LIVE · <code>" + a Leave/End `Button`; tap-anywhere navigates to `.live` (`navigationState.selectedSection = .live`). `role: .status` a11y equivalent: `.accessibilityElement(children: .combine)` + `.accessibilityLabel`. Colours via IHDesign tokens, `warning`/`accent` tints — never hex literals.
- Hosted once per root variant with `.safeAreaInset(edge: .bottom)` on the `TabView` (tabbed) and the `NavigationSplitView` (split) — the SwiftUI equivalent of the web's `position:fixed;bottom:0` banner (`service-follow.js:213-217`), pushing content up instead of covering it.
- Hidden when `vm.liveState == .idle`. Rendered from ONE private `@ViewBuilder` helper used by both roots (modularity rule).

### 6.5 `AppRootViewModel+LiveSync` — the ONE stream consumer

The PR-5 lesson (AsyncStream is single-consumer — the bug its review caught) applies verbatim: each engine's `events` stream has EXACTLY ONE consumer, the observation task this extension starts from `init` (pattern: `observeSessionState()` / `sessionObservationTask`, `AppRootViewModel.swift:230-254,274`). UI reads the published `@Observable` state, never the streams.

```swift
public enum LiveSyncUIState: Sendable, Equatable {
    case idle
    case hosting(code: String, currentSongTitle: String?)
    case followingLeader(hostDisplayName: String, isFresh: Bool)
    case followingService(isFresh: Bool)
}
// Published: var liveState: LiveSyncUIState = .idle
//            var liveSnapshot: LiveBroadcastSnapshot?
```

Façade methods (all `@MainActor`, thin actor hops): `joinWithCode(_:) async throws` (the D-8 two-step), `goLive() async throws`, `leaveLive() async` (routes to whichever engine is active), `liveSongViewed(_ songId: SongID)` (fire-and-forget `Task` → `liveFollowEngine.broadcast` — a no-op unless hosting; called from the ONE `SongDetailView` hook), `setLiveScenePhaseActive(_ active: Bool)` (forwards to both engines: cancel loops on inactive, restart + wake-beat/immediate-poll on active), and a `resumeLiveIfPossible()` called once at startup (drives `ServiceModeEngine.resumeIfPossible` + the LF resume records). **Sign-out:** the existing session-state observation additionally calls `liveFollowEngine.endHostingForSignOut()` when a hosting user's session ends (`live-follow.js:75-79` parity); following/presence are auth-independent and survive sign-out untouched.

### 6.6 Per-platform + scenePhase + watch safety

- **iPhone/iPad/Mac/visionOS:** everything above, through the shared `RootContainerView`.
- **tvOS:** NO UI this PR (`TVRootView` untouched) — the TV's server-following surface is PR-15 (#1428), which consumes §3's engines. The engines themselves compile for tvOS (no UI imports in IHLive) — the §7.5 tvOS builds prove it.
- **watchOS:** no new watch surface; every new IHFeatures view sticks to the #1549-safe API subset (§1.2 list — verified: nothing in §6 uses `Menu`/`.segmented`/`DisclosureGroup`/`keyboardShortcut`/`draggable`/`ToolbarItemPlacement.navigation`). The §7.5 watch cross-compile check asserts NO NEW file joins #1549's pre-existing failure list.
- **scenePhase:** wired ONCE in `RootContainerView` (`.onChange(of: scenePhase)`, the `RemoteControlView.swift:28,39` precedent) → `vm.setLiveScenePhaseActive(newPhase == .active)`. Background = loops cancelled (strategy §1.3); return = restart + host wake-beat + immediate follower poll (§3.4-H). State the honest limitation in the host-console footer copy: "iHymns keeps your session alive while the app is open. If you leave the app for more than a few minutes, followers may briefly lose sync until you return." (180 s freshness; the real fix is PR-16's Live Activity.)

---

## 7. Test plan (Swift Testing; injected clocks; tokens only ever fingerprinted; join codes NEVER in any assertion message or log)

### 7.1 Contract fixtures (strategy §3.3 "the drift alarm") — record vs derive, honestly

New files in `Tests/Fixtures/` (+ `Package.swift` `.copy` entries + README provenance blocks + `tools/apple-refresh-fixtures.sh` lines):

**Live-recordable TODAY, unauthenticated, no session needed (record for real — these prove the negative/edge envelopes):**
- `live_follow_join_not_found.json` — `GET dev.ihymns.app/api?action=live_follow_join&code=ZZZZ99` → the opaque 404 body (`api.php:14160`).
- `live_follow_poll_inactive.json` — `GET …action=live_follow_poll&code=ZZZZ99` → `{"active":false}` (`api.php:14226`).
- `service_join_not_active.json` — `curl -X POST -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' -d '{"code":"ZZZZ99","presenceDeviceId":"fixture-recorder"}' …action=service_join` → the opaque 404 (`api.php:14627`).
- `service_poll_inactive.json` — `GET …action=service_poll&presenceToken=<43 junk chars>&since=0` → `{"active":false}`.

**Require a LIVE session (an operator + venue on dev):** `live_follow_create/join/poll-changed` positives and `service_join/service_poll-changed` positives. **Honesty rule (the `song_links.json`/`Work.swift` precedent, fixtures README):** if the builder has a signed-in dev account, record the LIVE-FOLLOW positives for real (create needs only a normal account — `api.php:13882`); the SERVICE positives need an org-admin-started venue session — if unavailable, commit **code-derived** fixtures (`live_follow_join.json`, `live_follow_poll_changed.json`, `service_join.json`, `service_poll_changed.json`, `service_poll_unchanged.json`) built field-by-field from the cited `sendJson` sites in §1.3, each README-marked **"CODE-DERIVED from api.php:<lines>, NOT live-recorded — re-record during the multi-device live verify"**, and note that re-record obligation in the PR body. The envelope tests then prove the DTOs against the documented shape while the README keeps the drift-alarm honest about which files are real recordings.

### 7.2 Always-on pure suites

- **`LiveSyncModelTests`** — decode every fixture; the `resolvedDisplayState` table: (`"live"`,any)→`.live`; (`"blackout"`,any)→`.blackout`; (`"logo"`,any)→`.logo`; (`"sepia"`[unknown],`blank:true`)→`.blackout`; (`"sepia"`,`blank:false`)→`.live`; (nil,`blank:true`)→`.blackout`; (nil,nil)→`.live`; clamp-shaped values decode (lineIndex 9999, scrollPct 1.0, transposeOffset −12); a `state` object with ONLY unknown keys decodes to an all-nil `LiveBroadcastState` (forward-compat).
- **`LiveSyncAPITests`** — for all 9 factories: exact `action`, method, `requiresAuth`, JSON body keys (decode the `httpBody` back — never string-compare JSON), query items incl. `since`/`token` presence/omission. **Presence-Cookie rows:** `makeURLRequest(..., presenceToken: valid43)` ⇒ header exactly `ihymns_sf_presence_token=<token>` AND `httpShouldHandleCookies == false`; `presenceToken: nil` ⇒ NO Cookie header AND `httpShouldHandleCookies` untouched (default true) — byte-parity with today; malformed token (42 chars / bad alphabet) ⇒ dropped, no header; presence + Bearer coexist on an auth endpoint.
- **`LiveFollowerReducerTests`** — revision monotonicity (a duplicate/lower revision NEVER re-applies — `.none`); `changed:true` + higher revision ⇒ `.apply` + state advanced; `active:false` ⇒ `.sessionEnded` regardless of revision; `active:nil, changed:false` ⇒ `.none` + `lastContactAt` refreshed; failure never touches `lastContactAt`/revision; freshness edge at exactly 180 s (strict `<` — the existing `LiveFollowEngineTests` boundary rows stay green untouched, proving `isFresh` didn't move); the FULL cadence table §3.3 row-by-row incl. clamp bounds (999→1000, 60001→60000), idle floor, failure backoff powers + 15 s cap + reset-on-success.

### 7.3 Engine loop suites (MockURLProtocol + a recording no-op sleeper — zero wall-clock)

Config for all: `now` = stepped fake clock; `sleep` = records the requested `Duration` then returns immediately (cancellation-cooperative).

- **`ServiceModeEngineTests`** — join happy path (scripted `service_join` 200): custody set (fingerprint accessor non-nil), `apiClient` presence token updated (assert via a probe `makeURLRequest` showing the Cookie), persistence written, `.joined` emitted with the initial snapshot; poll sequence unchanged→changed→`active:false` ⇒ ONE `.snapshot`, then `.left(.serverEnded)` + custody CLEARED everywhere (engine, apiClient, persistence); `leave()` ⇒ `service_leave` POSTed BEFORE custody cleared (request order via MockURLProtocol's recorded requests); `since` increments across polls; cadence: first recorded sleep == server-declared `pollIntervalMs` (clamped), idle flips to ≥15 s; resume: persisted fresh record ⇒ one immediate poll, `active:false` ⇒ silent cleanup, changed ⇒ `.snapshot`; persisted record older than 4 h ⇒ NO network call at all; join 404 ⇒ `.codeNotActive`; join 503 ⇒ `.temporarilyUnavailable`.
- **`LiveFollowEngineLoopTests`** — HOST: `goLive` 200 ⇒ `.hostingStarted`, heartbeat loop sleeps 30 s intervals, `{ok:false}` beat ⇒ `.hostingEnded(.serverEnded)`; `broadcast` dedup (same songId|idx ⇒ NO second POST — count via MockURLProtocol), changed idx ⇒ POST; update 409 ⇒ `.hostingEnded(.superseded)`; `endHosting` ⇒ leave POSTed + persistence cleared; wake (`setScenePhaseActive(true)`) ⇒ an IMMEDIATE heartbeat before the next 30 s sleep. FOLLOWER: join seeds reducer from the response revision (a pre-join broadcast isn't re-applied); poll loop parity rows with the service suite (shared spine — keep these thinner, the reducer suite carries the exhaustive table); mutual exclusion: `goLive` while following throws, `join` while hosting throws.
- Suspension: `setScenePhaseActive(false)` cancels the loop task (no further recorded sleeps/requests); `(true)` restarts it.

### 7.4 `LiveSyncUIStateTests` (`@MainActor`)

Event→state rows: `.hostingStarted` ⇒ `.hosting`; `.followingStarted` ⇒ `.followingLeader(host:)`; service `.joined` ⇒ `.followingService`; `.freshnessChanged(false)` ⇒ `isFresh` false in state (banner shows Reconnecting); any `…Ended/left` ⇒ `.idle`; sign-out while hosting ⇒ engines told + `.idle`; `joinWithCode` fallback order (service engine consulted FIRST, LF second — assert via spy engines/scripted transports); banner visibility = `liveState != .idle`.

### 7.5 Local pre-PR verification (builder runs ALL — CI is not a required check, #1526)

```
cd appApple/Packages/iHymnsKit && swift build && swift test
swiftlint --config appApple/.swiftlint.yml appApple          # 0 violations
bash appApple/Scripts/loc-budget.sh                          # every file ≤400
# Package cross-compiles for the three non-mac platforms (shared IHLive/IHFeatures edits ⇒ the #1532 lesson):
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk iphonesimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-ios26.0-simulator
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk appletvsimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-tvos26.0-simulator
# watchOS regression check — if #1549 has MERGED this passes clean; if not, it fails with
# exactly #1549's pre-existing list and the assertion is: NO NEW FILE in the error output:
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk watchsimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-watchos26.0-simulator ; true
# App-scheme builds if disk permits (~5-7 GB free — prefer macOS + tvOS; add the iOS app scheme
# if #1549 has merged and re-enabled it):
cd appApple && xcodegen generate
xcodebuild -project iHymns.xcodeproj -scheme iHymns  -destination 'platform=macOS,arch=arm64'        CODE_SIGNING_ALLOWED=NO build
xcodebuild -project iHymns.xcodeproj -scheme iHymnsTV -destination 'generic/platform=tvOS Simulator' CODE_SIGNING_ALLOWED=NO build
```
**Builder footnote (local git quirk):** a `swift build` right after a clean may fail on the local `safe.bareRepository=explicit` git setting — prefix `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all` (harmless on CI).

**Manual end-to-end smoke (state honestly in the PR body, not as CI):** with the dev backend — web `/manage` operator starts a service session (or a web "Go Live" on a song page) → native Join with the code → song changes follow within one poll interval; native Go Live → web "Join Live" with the native code follows. This is the same cross-client verify the multi-device matrix (plan §2 gate) will repeat formally.

---

## 8. Threat model (acceptance criteria) + Decisions

| Threat | Mitigation (mechanism, THIS PR) | Residual |
|---|---|---|
| **Presence-token leakage** (the CCLI gate key: logs, URLs, sync, backup) | Custody is engine-internal; never rides an event/UI type (§3.5); transmitted ONLY as the named Cookie header over TLS to the selected environment (never a URL — access-log-safe, §4.3) and the `service_leave` body; persisted local-only UserDefaults, never Keychain-synchronizable (D-5 — a synced presence token on an out-of-room device would defeat proof-of-presence); logged ONLY as `IHLogSanitize.tokenFingerprint` (≥256-bit entropy — the sanitizer's own precondition). Review gate: grep every new file's `IHLog` calls + assertion messages. | UserDefaults is readable on a jailbroken device — accepted for an anonymous ≤4 h room key the server can revoke instantly (`service_session_end` revokes ALL presence, `api.php:14415`). |
| **Join-code exposure** (short, low-entropy, venue-displayed) | Codes live in memory + POST bodies only; NEVER logged, NEVER fingerprinted (`IHLogSanitize`'s explicit "never for a CODE" rule), NEVER in an assertion message (§7 discipline); host code rendered on-screen deliberately (that IS the feature) via `ShareLink`, no analytics event carries it. | A shoulder-surfed LF host code admits a follower — by design (the web is identical); the SERVICE code rotates every 75 s server-side. |
| **Code enumeration / session probing from a compromised client** | Nothing new: joins are user-gesture-only (no retry loop — a failed join returns to the sheet); the server's opaque 404s + per-IP caps (`api.php:14129`, `:14607`) are the real control; native surfaces the server's opaque copy verbatim, adding no oracle. | Server-side concern, already handled (rule #26). |
| **Poll-budget / battery abuse** (native turning a congregation into a DoS) | The §3.3 cadence table is BINDING: server-declared interval honoured, 24/min worst-case vs the 40/min per-token budget; idle floor 15 s; failure backoff; scenePhase cancels loops outright; `since` cheap-poll always sent. Engine tests assert recorded sleep durations (§7.3). | A user leaving the following screen open all service polls at the declared cadence — that IS the product. |
| **Lyric text on the sync wire** (D-12) | Structurally absent: the poll DTO has no lyric field (§1.3 — the backend never sends one); content arrives ONLY via the existing `song_detail` under the device's own auth + (dormant) gating, so `contentGatingApply` gates native congregants exactly like web (`api.php:810-815`). | None. |
| **CSRF / cross-origin abuse of the anonymous POSTs** | `X-Requested-With` on every APIClient request (`APIClient.swift:17-21`) satisfies `validateCsrfRequest` (rule #29) — identical posture to the web congregant (`service-follow.js:255`). Native adds no cookie-authenticated state the server trusts implicitly (Bearer-only auth). | — |
| **Account/presence separation** | The presence token is never sent as a Bearer and grants ONLY the server-side gating OR-grant (`content_gating.php:218-219`); hosting requires the real Bearer session and force-ends on sign-out (§6.5); following/presence survive sign-out by design (they're anonymous). | — |
| **Malicious/garbage broadcast payloads** (a hostile host feeds followers junk) | Server-side `serviceMode_cleanState` allow-list already bounds `state` (`service_mode.php:140-178`); native re-validates: songId through `SongID(rawValue:)` before ANY navigation/fetch (D-13 — an unparsable id renders a calm row, never interpolates into a path); componentIndex only ever used as a scroll target index bounds-checked against the fetched component count. | A host can legitimately broadcast an unlisted-but-real song the follower's tier can't view → the §5.1 gated-fetch copy. Correct behaviour, not a bug. |
| **Cross-environment session leakage** | Server-side `Channel` filters on every join/poll (rule #26, `api.php:14139-14148`) — nothing for the client to do; the native env picker just selects which docroot it talks to. | — |
| **No-geolocation invariant** | Restated: presence is proven by the rotating venue code ALONE (rule #26 "never gate presence on geolocation — spoofable"); this PR requests no location entitlement, no CoreLocation import anywhere. | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — ONE PR closing #1426 + #1427.** §9.
- **D-2 — Host scope:** Live Follow host IN; native Service-Mode operator console OUT (§5.3's three reasons; #1104 comment).
- **D-3 — ONE pure follower spine (`LiveFollowerReducer`) shared by both engines**, delegating freshness to the EXISTING `LiveFollowEngine.isFresh` seam. A second copy of the dedup/freshness/cadence maths anywhere (incl. PR-15 later) is the `_bsls_*` regression class (web rule #22) applied to Swift.
- **D-4 — Congregant display = web parity** (song + componentIndex; displayState decoded but passive) (§5.2).
- **D-5 — Presence custody: engine-internal + local-only UserDefaults; never Keychain, never synchronizable, never in an event payload** (§3.6). Resume survives relaunch inside the 4 h ceiling.
- **D-6 — CCLI unlock rides the `ihymns_sf_presence_token` Cookie header, attached by `makeURLRequest` when custody is set; `httpShouldHandleCookies=false` on those requests; zero backend change** (§4.3).
- **D-7 — Device id = UserDefaults UUID** (web-localStorage parity; not Keychain, not IDFV) (§3.6).
- **D-8 — Unified join: service_join first, live_follow_join fallback, one combined error; maintenance short-circuits** (web #1386 parity) (§6.2).
- **D-9 — Cadence: server-declared `pollIntervalMs` wins (clamped 1–60 s); fallbacks 2500 ms LF / 4000 ms SM; idle floor 15 s; backoff ×2^n≤15 s; scenePhase cancels** (§3.3 table — every number cited).
- **D-10 — Host + follow + presence all persist for relaunch-resume; host revival leans on the server's wake-beat semantics** (LastHeartbeatAt refresh un-stales within the 4 h ExpiresAt) (§3.4-H).
- **D-11 — Transient failure is never an end signal**; only `active:false` (server-positive) ends a follow; 180 s of silence shows "Reconnecting…" and keeps polling at the idle floor (§3.3).
- **D-12 — Intent-only wire invariant** (the server-side twin of PR-4's LAN invariant) (§1.3/§8 row 5).
- **D-13 — `songId` stays a raw String in DTOs; SongID-parse at the UI edge; legacy ids degrade gracefully** (§3.2).
- **D-14 — One `LiveFollowEngine` actor for host+follower with web-parity mutual exclusion; `ServiceModeEngine` separate** (different custody + lifecycle; the spine is the shared part, not the actor) (§3.4/3.5).
- **D-15 — Scope tripwires (reviewer rejects on sight):** any LANRemote import/reference from new files; any `service_broadcast`/operator endpoint call; lyric text in any live DTO; a token/code in any log/event/assertion; Keychain/synchronizable presence storage; a second consumer of either `events` stream; a second freshness/cadence implementation; `project.yml`/backend/`Package.swift`-dependency changes; a new view using a #1549-class watch-unavailable API.
- **Security notes for the PR body:** reproduce this §8 table; state the fixtures' recorded-vs-code-derived split honestly (§7.1); note the dormant-gating no-op status of D-6 until `content_gating_enabled='1'`; note that Audit B (plan §2 gate) covers this PR before external TestFlight.

---

## 9. Commit plan — ONE PR, four commits (each compiles + `swift test` green)

**Recommendation: ONE PR closing both #1426 and #1427** (branch `feat/apple-p2-pr10-congregant`, target `alpha`). Splitting per-issue would be artificial: the two features share the DTO file, the IHAPI file set, the reducer spine, the join sheet, the banner, and the view-model — a #1426-only PR would still build ~80% of #1427's plumbing, then a second PR would re-review it (the repo's one-PR-per-piece-of-work rule + the plan's own sizing of PR-10 as one 2–3 d unit). The LAN-remote precedent (PR-6/7/8 each one PR) holds.

1. **`feat(apple): live-sync wire contract — DTOs, IHAPI endpoints, presence-token transport, fixtures (#1426, #1427)`** — `IHModels/LiveSync.swift`; `IHAPI/LiveSyncEndpoints|LiveSyncDecoding|APIClient+LiveSync.swift`; `APIClient.swift`/`+Networking.swift` presence-cookie edits; fixtures + `Package.swift` resources + README + refresh-script lines; `LiveSyncModelTests` + `LiveSyncAPITests`.
2. **`feat(apple): server-live engines — shared follower spine, Live Follow host+follower, Service Mode congregant (#1426, #1427)`** — `git mv` LiveFollowEngine into `ServerLive/` (do the PURE move as the first hunk so the diff reads move-then-grow); `LiveFollowerReducer|LiveSyncEvents|LiveSyncConfiguration|ServiceDeviceIdentity|LiveSyncPersistence|ServiceModeEngine|LiveFollowEngine+Host|+Follower`; reducer + both engine-loop test suites. Existing `LiveFollowEngineTests` stays green UNTOUCHED (the isFresh-didn't-move proof).
3. **`feat(apple): Live section UI — hub, unified join, following screen, status banner, host hook (#1426, #1427)`** — `AppRootViewModel` edits + `+LiveSync.swift`; `makeLive` wiring; `LiveHubView` rewrite; `LiveJoinSheet|LiveFollowingView|LiveHostControlsView|LiveStatusBanner`; `RootContainerView` inset + scenePhase; the one-line `SongDetailView` hook; `LiveSyncUIStateTests`.
4. **`docs(apple): PR-10 docs — fixtures provenance, CHANGELOG, dev notes (#1426, #1427)`** — README provenance block finalised, `CHANGELOG.md`, any `DEV_NOTES.md`/wiki-worthy note; PR body drafted with the §8 security notes + the §7.5 verify evidence + the #1104 comment text (§5.3).

**After merge (not this PR):** PR-15 (#1428) consumes `ServiceModeEngine`/the spine for the tvOS projector; PR-14 (#1425) adds the `service_broadcast` IHAPI endpoint beside §4.1's nine; PR-16 (#1429) wraps the host session in a Live Activity. The multi-device verify matrix (plan §2 gate) re-records the §7.1 code-derived fixtures for real.
