# Apple Phase-2 PR-11 — Watch LAN-remote relay (WatchConnectivity → iPhone session) + reduced control set (#1423)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-13).** Sibling of `.claude/apple-phase2-pr6-spec.md` (TV side + shared crypto, MERGED), `.claude/apple-phase2-pr7-spec.md` (remote-side client, MERGED as PR #1550 `c7e987e9`) and `.claude/apple-phase2-pr8-spec.md` (manual connect/TOFU, MERGED as PR #1553 `4e884cdf`). Grounded in a code-level read of the merged seam on `alpha` (`Sources/IHLive/LANRemote/*`, `Sources/IHFeatures/RemoteControl*`, the #1549 watch-compile fix `e8b91358`), `apple-native-strategy.md` §2.4.2/§2.4.4/§2.2, and `apple-phase2-implementation-plan.md` §2 (PR-11 row: "Watch LAN remote relay (WatchConnectivity → iPhone RemoteSessionActor) + reduced control set", S, 1–1.5d). A Sonnet builder should execute this top-to-bottom with minimal further judgement. Target branch: **`feat/apple-p2-pr11-watch-relay`** off `alpha`; ONE PR targeting `alpha`. **CI's `apple.yml` is NOT a required check (#1526), so every §7 verify command MUST be run locally before the PR is opened — including the watchOS package cross-compile, which post-#1549 is expected GREEN and must STAY green.** This PR touches NO security boundary (no pairing, no TOFU, no token custody change) — its §8 rows are mostly *confinement proofs*, and the two tripwires that matter most are architectural: the watch NEVER touches the network, and the phone-side bridge NEVER adds a second consumer of the session's streams.

---

## 1. Scope + the relay contract

**What PR-11 builds (issue #1423, strategy §2.4.2 — BINDING):** the Apple Watch as a REDUCED remote control for the LAN-direct TV remote, relaying through the paired iPhone. watchOS restricts `Network.framework`/Bonjour for third-party apps (and MultipeerConnectivity was rejected for exactly this class of reason, strategy §2.4.2), so **the watch NEVER opens an `NWConnection` and never discovers anything** — it sends tiny command intents to the iPhone over WatchConnectivity `sendMessage`, the iPhone forwards them into its OWN already-paired, already-connected LAN session, and the iPhone relays compact state back. ~100–300 ms added latency (stated in strategy; accepted). Reduced control set: **Next/Prev (component), Blackout, Logo, Lyrics-restore, status** — nothing else (§5, D-5).

**What PR-11 does NOT build (tripwires, reviewer rejects on sight):** any `import Network`/`NWConnection`/`NWBrowser`/Bonjour reachable from watchOS code; a second `RemoteControlSession` (or a dedicated `.watchRelay`-kind connection) on the phone; ANY change to pairing/TOFU/Keychain/token custody (PR-6/7/8 are done — the relay issues only already-authorized control intents through the phone's already-paired session); a second consumer of `RemoteControlSession.events` or of `RemoteSessionActor.phaseUpdates`/`.incomingMessages` (§1.2 fact 2 — the subtlest correctness point, resolved in §1.3); auth-token mirroring to the watch Keychain (strategy §2.2.1 names it as a SEPARATE future feature — NO credential of any kind crosses the watch link in this PR, D-10); lyric text over WatchConnectivity (the IHRP content invariant extends to this hop); command queuing for later delivery (D-8); any new external package; any `project.yml` entitlement/plist change (§1.2 fact 13, D-11).

### 1.1 The relay flow, end to end (BINDING)

```text
Watch (watchOS)                        iPhone (iOS)                                      TV (tvOS, UNTOUCHED)
───────────────                        ────────────                                      ────────────────────
WatchRemoteView tap "Next"
 WatchRemoteController.send(.nextComponent)
 WCSession.sendMessage(["c": Data]) ──► PhoneRelayDelegate (nonisolated shell)
                                         → AsyncStream (ordered, ONE consumer)  (§4.1)
                                         → PhoneWatchRelayService (@MainActor)
                                         → WatchRelayCodec.decodeCommand        (§3.2, pure)
                                         → RemoteControlRelayHub.handle         (§3.4)
                                            phase = lastSnapshot.phase
                                            WatchRelayRules.decide(cmd, phase)  (§3.3, pure)
                                            .forward(.nextComponent)
                                         → coordinator.sendIntent(...)          (existing public API,
                                         → session.sendIntent → RemoteSessionActor  RemoteControlCoordinator.swift:263-265)
                                                              ──────────────────────►  IHRP .nextComponent over the
                                         replyHandler(["r": Data(reply)])              paired TLS connection
 ◄── reply {outcome, snapshot} ────────  (outcome=forwarded + CURRENT snapshot)
 snapshot → UI                                                                   ◄──  .state broadcast (TV echo)
                                        session.events → coordinator.apply(_:)   (the ONE events consumer,
                                         → END-OF-APPLY TAP:                      RemoteControlCoordinator.swift:291-298)
                                           hub.publish(relaySnapshot(uiPhase))   (§3.5 — dedup on Equatable)
                                         → PhoneWatchRelayService.push:
 ◄── sendMessage(["s": Data]) if reachable  +  updateApplicationContext always   (D-7)
 snapshot′ (new songRef/displayState) → watch UI re-renders
```

Two independent channels, deliberately: **commands are request/reply** (the watch always learns synchronously whether its tap did anything, and gets the truth-snapshot either way), **state is push** (the TV's echo drives the watch exactly the way it already drives the phone's own stateless/reflective surface — `RemoteControlSurfaceView.swift`'s header contract, extended one hop).

### 1.2 Facts about the merged seam this spec builds on (verified in code — cite these in file headers)

1. **`RemoteControlCoordinator.spawnEventsConsumer()` is THE one consumer of `session.events`** (`RemoteControlCoordinator.swift:289-298`, header `:12-16`: "consumes ONLY `session.events` — never `RemoteSessionActor`'s own streams"). Every event lands in `apply(_:)` (`:300-361`), which FIRST recomputes `uiPhase` via the pure map and THEN runs side effects. This is the tap point (§1.3).
2. **The single-consumer rule is two-layered:** `RemoteSessionActor.phaseUpdates`/`.incomingMessages` are consumed ONLY by `RemoteControlSession+Link.swift`'s two loops (`RemoteControlSession.swift:28-35` header, BINDING), and `session.events` is consumed ONLY by the coordinator (fact 1). The relay must therefore tap ABOVE both layers — at the coordinator's applied state — never beside them.
3. **`UIPhase.controlling(tvName:state:)` carries the FULL `IHRPState`** (`RemoteControlCoordinator+UIPhase.swift:33`) — songId, componentIndex, lineIndex, displayState, revision. Everything the watch is allowed to see already surfaces through `uiPhase`; no new session/actor API is needed to build the snapshot.
4. **`coordinator.sendIntent(_:)` is the existing public one-door for control intents** (`RemoteControlCoordinator.swift:263-265` → `RemoteControlSession.sendIntent`, `RemoteControlSession.swift:280-286`, which `guard`s `message.isControlIntent` and `assertionFailure`s otherwise). The relay forwards through THIS door — an additional *caller* of a public method, not a new stream consumer. (Strategy §2.4.2's "iPhone's `RemoteSessionActor` forwards" is honoured one supervision layer up, exactly where PR-7 put the public seam — going at the actor directly would violate fact 2.)
5. **The coordinator is SCREEN-SCOPED:** `RemoteControlView` owns it as `@State` (`RemoteControlView.swift:23`), `start()`s it in `.task` and `stop()`s it `onDisappear` (`:38-39`). There is NO app-wide LAN session. Therefore the watch can only drive a TV while the iPhone has the TV-Remote screen open — the §5 UX states this honestly instead of hiding it.
6. **Backgrounding the phone tears the link down BY DESIGN:** `RemoteControlView`'s `scenePhase` wiring (`:40-42`) → `setScenePhaseActive` (`RemoteControlCoordinator.swift:258-260`) → `RemoteControlSession.setSuspended(true)` sends `.endControl` + disconnects and yields `.suspended` (`RemoteControlSession+Link.swift:347-372`). The watch cannot keep the TV link alive through a locked phone (D-9); the snapshot's `.phoneBackgrounded` phase tells the user the truth.
7. **`.controlling(IHRPState)` fires on EVERY TV state broadcast** including echoes of our own intents (`RemoteControlSession+Link.swift:182`, `RemoteControlSession.swift:97-99`) — so the end-of-apply tap sees every display change with zero polling, and the hub's Equatable dedup (§3.5) is what keeps WatchConnectivity traffic proportional to CHANGE, not to echo volume.
8. **The phone's own control surface shows `state.songId?.rawValue ?? "Nothing selected"`** — the SongID string, NOT a fetched title (`RemoteControlSurfaceView.swift:84`). The watch does exactly the same (D-6): no API fetch is added to the relay path, and the two surfaces can never disagree.
9. **`IHRPRemoteKind.watchRelay` already exists** (`IHRPPayloads.swift:41-44`) and `RemoteDeviceIdentity.kind` returns it on watchOS (`RemoteDeviceIdentity.swift:34-45`) — BOTH are documented as defensive/never-read-today. This PR deliberately does NOT light them up (D-1): the relay rides the phone's existing session, whose `hello` kind stays `.phone`/`.pad`.
10. **The reduced set maps onto existing IHRP intents 1:1:** `.nextComponent`/`.prevComponent` (`IHRPMessage.swift:92-93`), `.setDisplayState(.lyrics/.blackout/.logo)` (`:101`, `IHRPPayloads.swift:64-69`). All are `isControlIntent == true` (`IHRPMessage.swift:220-229`) — §7.1 asserts this so fact 4's guard can never trip.
11. **`IHFeatures` compiles for watchOS since #1549** (`e8b91358`): 9 views carry `#if os(watchOS)` compile-only fallbacks (e.g. `RemoteControlSurfaceView.swift:148-157`). Every NEW view in this PR must be watchOS-clean BY DESIGN (the watch UI actually RUNS there — no `.segmented`, no `Menu`, no `DisclosureGroup`, no `.draggable`/`.dropDestination`, no `.keyboardShortcut`, no `ToolbarItemPlacement.navigation`).
12. **The watch shell renders `PhaseZeroSkeletonView` today** (`IHymnsWatchApp.swift:38-44`) and links `[IHFeatures, IHDesign]` (`project.yml:291-293`); it embeds into the iOS variant of the `iHymns` target only (`project.yml:182-184`, `destinationFilters: [iOS]`). Strategy §2.2's full watch app (Now/Remote/Favourites/Setlist tabs) is Phase 1.5 — this PR ships the Remote screen as the interim watch ROOT (D-12), not the whole watch app.
13. **WatchConnectivity needs NO entitlement and NO Info.plist key** — Apple's framework docs require only `WCSession.isSupported()` + delegate + `activate()`. `WCSession` exists on iOS and watchOS ONLY, and `isSupported()` is false on iPad — so the phone-side shell is `#if os(iOS) && canImport(WatchConnectivity)`-gated AND runtime-guarded, and nothing WatchConnectivity-shaped compiles into macOS/tvOS/visionOS slices (D-11). **No `project.yml` change, no `apple.yml` change.**
14. **`apple.yml` already proves every slice this PR touches:** package `swift test` (`apple.yml:61-63`), macOS app build (`:69`), **iOS-Simulator app build that embeds a genuinely-compiled iHymnsWatch.app** (`:98`, added by #1549), tvOS build (`:117`). The iOS app build IS CI's watch-compile check; §7.4 mirrors all of it locally.
15. **`RemoteControlCoordinator.swift` is at 397 raw lines** (400 cap, `Scripts/loc-budget.sh`) — the 3-line tap CANNOT be added in place. §2's `+Persistence.swift` pure relocation (the PR-8 `+UIPhase.swift` precedent, byte-identical move first, tests green, THEN the new lines) creates the headroom.
16. **The concurrency-bridge idiom to copy is `SessionController.stateUpdates`** (`IHAuth/SessionController.swift:103` — a `nonisolated public let` `AsyncStream` fed by a continuation): non-async callback world yields Sendable values into ONE ordered stream with ONE consumer. Both WCSession delegate shells use exactly this shape (§4.1) — never a `Task {}`-per-callback fan-out, which would let two rapid Next taps reorder.
17. **`RemoteControlUIStateTests` uses `@testable import IHFeatures`** (`RemoteControlUIStateTests.swift:16`) — the §7.2 hub tests can therefore drive `coordinator.uiPhase` (which is `public internal(set)`, `RemoteControlCoordinator.swift:41`) directly, with no test-only seams added to production code.

### 1.3 The single-consumer resolution (BINDING — the one subtle design point, decided)

The bridge does **NOT** consume `session.events` (that would be the second-consumer bug class the PR-5 review caught, restated as binding in `RemoteControlSession.swift:28-35`), and does NOT use `withObservationTracking` re-arm loops over the `@Observable` coordinator (fragile, fires mid-mutation, untestable ordering). Instead:

- **State OUT:** `RemoteControlCoordinator.apply(_:)` gains ONE line at its very END — `RemoteControlRelayHub.shared.publish(Self.relaySnapshot(from: uiPhase))` — running strictly AFTER the pure `uiPhase` recompute and every side-effect branch (including `+ManualConnect`'s direct `uiPhase` writes, which happen inside `apply`'s `.awaitingFingerprintConfirmation` case, so the end-of-apply position observes them too). Plus `start()` registers / `stop()` unregisters the coordinator with the hub (each publishing the truthful boundary snapshot). The hub is a tap of ALREADY-APPLIED state — zero new stream consumers at either layer.
- **Commands IN:** the hub holds the registered coordinator `weak`, decides via the pure `WatchRelayRules.decide` (§3.3), and forwards through the existing public `coordinator.sendIntent(_:)` (fact 4). No session/actor API is touched, added, or bypassed.
- On macOS/tvOS/visionOS the hub compiles and no-ops (nothing ever sets its `onSnapshot`, nothing ever calls `handle`) — the coordinator's tap lines are platform-unconditional and harmless, keeping `#if` noise out of `RemoteControlCoordinator.swift` entirely (D-3).

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh` — budget for two-register ELI5+DETAILED comments by splitting early). SwiftLint clean (`appApple/.swiftlint.yml`). Every file header references **#1423**, strategy §2.4.2, and plan §2 PR-11 — match PR-6/7/8 comment density. Swift 6 strict concurrency throughout (`Package.swift:331-334`); `AsyncStream`, never Combine.

### New — module `IHLive` (`Sources/IHLive/WatchRelay/` — a NEW sibling folder beside `LANRemote/`; same module, so it references `IHRPMessage`/`IHRPDisplayState` directly, but a distinct folder because this family never imports Network)

| File | Purpose (one line) | ~LOC |
|---|---|---|
| `WatchRelayMessages.swift` | The pure Codable wire vocabulary: `WatchRelayCommand`, `WatchRelaySnapshot` (+`Phase`), `WatchRelayReply`, `WatchRelayCodec` (versioned Data envelope encode/decode + the dictionary keys) (§3.1/§3.2). | ~230 |
| `WatchRelayRules.swift` | The pure relay brain: `intent(for:) -> IHRPMessage?` + `decide(_:phase:) -> WatchRelayDecision` — the ONLY place a watch command becomes (or is refused becoming) a LAN intent (§3.3). | ~140 |

### New — module `IHFeatures` (`Sources/IHFeatures/WatchRelay/`)

| File | Purpose | ~LOC |
|---|---|---|
| `RemoteControlRelayHub.swift` | Platform-NEUTRAL `@MainActor` singleton: weak single-slot coordinator registry, Equatable-deduped `publish`, `onSnapshot` closure (set only by the iOS service), `handle(_:) async -> WatchRelayReply` (§3.4). NO WatchConnectivity import — compiles on all five platforms. | ~180 |
| `RemoteControlCoordinator+WatchRelay.swift` | The pure `nonisolated static relaySnapshot(from: UIPhase) -> WatchRelaySnapshot` mapping (§3.5) + the coordinator's three thin tap helpers (`relayRegister`/`relayUnregister`/`relayPublish`) so the core file gains only call-lines. | ~120 |
| `RemoteControlCoordinator+Persistence.swift` | **Pure relocation (LOC budget, fact 15):** `persistPaired(token:resolved:)` + `touchLastConnected()` move here BYTE-IDENTICALLY from `RemoteControlCoordinator.swift:363-392` (the PR-8 `+UIPhase.swift` precedent — move in its own commit step, existing tests green, before any new line lands). | ~110 |
| `PhoneWatchRelayService.swift` | `#if os(iOS) && canImport(WatchConnectivity)` — the iPhone-side WCSession shell: activation, the `PhoneRelayDelegate` nonisolated NSObject + its ordered inbound `AsyncStream`, command decode→hub→reply, snapshot push (sendMessage-when-reachable + applicationContext-always) (§4.2). | ~330 |
| `WatchRemoteController.swift` | `#if os(watchOS) && canImport(WatchConnectivity)` — the watch-side `@MainActor @Observable` model + its own nonisolated delegate shell/stream: activation, `snapshot`, `isPhoneReachable`, `send(_:)` with reply handling, `refresh()` (§4.3). | ~300 |
| `WatchRemoteView.swift` | `#if os(watchOS)` — the reduced remote UI: status header, Prev/Next, the 3-way display row, and the honest phase-driven guidance screens (§5). | ~240 |
| `WatchRootView.swift` | `#if os(watchOS)` — the interim watch ROOT (`NavigationStack { WatchRemoteView() }` + the controller's `@State` ownership), so the shell stays a one-liner and the future Phase-1.5 tab bar has one obvious insertion point (D-12). | ~60 |

### New — tests

| File | Purpose |
|---|---|
| `Tests/IHLiveTests/WatchRelay/WatchRelayCodecTests.swift` | Round-trips every command (`CaseIterable` loop), full + minimal snapshots, replies; the version probe (`v:1` asserted literally in the encoded JSON; `{"v":99,…}` ⇒ `.unsupportedVersion(99)`; garbage/empty Data ⇒ `.malformed`); the tiny-payload guarantee (encoded full snapshot `< 512` bytes — WatchConnectivity budget honesty, executable). |
| `Tests/IHLiveTests/WatchRelay/WatchRelayRulesTests.swift` | The FULL §3.3 decision table — `WatchRelayCommand.allCases × WatchRelaySnapshot.Phase.allCases` (30 rows) asserted against the expected-outcome function written out literally; the intent map row-by-row; **every `.forward`ed message satisfies `isControlIntent == true`** (ties to `RemoteControlSession.sendIntent`'s guard, fact 10). |
| `Tests/IHFeaturesTests/WatchRelaySnapshotMappingTests.swift` | `relaySnapshot(from:)` — one row per `UIPhase` case (7 rows: browsing/connecting/codeEntry/controlling/reconnecting/suspended/confirmingFingerprint), asserting phase + tvName + songRef + displayState + revision propagation exactly (§3.5's table, literally). |
| `Tests/IHFeaturesTests/RemoteControlRelayHubTests.swift` | `@MainActor`, `@testable` (fact 17): no coordinator ⇒ `handle(.nextComponent)` = `.rejected` + `.noSession` snapshot; registered coordinator with `uiPhase = .controlling(...)` set directly ⇒ `.requestState` = `.replyOnly`, `.nextComponent` = `.forwarded` (the un-connected session's `try? remote.send` swallows harmlessly — no network in tests); publish dedup (same snapshot twice ⇒ `onSnapshot` fired ONCE, counted); unregister ⇒ `.noSession` published; a SECOND coordinator registering replaces the first (single-slot). |

### Edited

| File | Edit |
|---|---|
| `Sources/IHFeatures/RemoteControlCoordinator.swift` | (a) `persistPaired`/`touchLastConnected` REMOVED (pure move to `+Persistence.swift`); (b) `start()` gains `relayRegister()` after `spawnEventsConsumer()`; (c) `stop()` gains `relayUnregister()` before `session.stop()`; (d) `apply(_:)` gains `relayPublish()` as its LAST line (after the switch). Net: file shrinks below the cap and gains 3 call-lines + short pointers to `+WatchRelay.swift` for the why. |
| `Apps/iHymnsWatch/Sources/IHymnsWatchApp.swift` | Root swaps `PhaseZeroSkeletonView(shellName: "watchOS")` → `WatchRootView()` (keep the `IHFonts.registerBundledFonts()` init). The Phase-0 skeleton retires from the WATCH shell only. |
| `Apps/iHymns/Sources/IHymnsApp.swift` | `init()` gains the one activation call after `registerBundledFonts()` (`IHymnsApp.swift:85-86`): `#if os(iOS)` `PhoneWatchRelayService.shared.activate()` `#endif`. In `init` (NOT `.task`) deliberately: a watch `sendMessage` can LAUNCH the iOS app in the background, where scene bodies/`.task` may never run — the delegate must be installed on EVERY launch path (§6.2). `App` is a `@MainActor` protocol, so the call is isolation-correct from `init`. |
| `CHANGELOG.md` | One entry under Unreleased (the PR-10 `33dc48fa` docs-commit precedent). |

**No edits to:** anything under `Sources/IHLive/LANRemote/` (**ZERO diff — the whole LAN family is untouched**), `RemoteControlSession`/`RemoteSessionActor`/`TVListenerActor` (no new API, no new consumer), `RemoteControlView`/`RemoteControlSurfaceView` (the phone UI is done), `RemoteDeviceIdentity` (`.watchRelay` stays dormant, D-1), `PairedTVStore`/Keychain anything, `Package.swift` (both new folders live inside existing targets), `project.yml`, `apple.yml` (facts 13–14).

---

## 3. The pure cores (BINDING shapes — do not improvise)

Everything in this section is `Sendable + Equatable`, fully deterministic, and testable with zero WCSession/actors/network. The WCSession shells (§4) are thin enough that a protocol bug is ALWAYS reproducible in a pure test.

### 3.1 The wire vocabulary (`WatchRelayMessages.swift`, IHLive)

```swift
/// The watch↔iPhone relay protocol version — bumped ONLY on a breaking
/// wire-shape change. Both apps ship in one bundle (the watch app embeds in
/// the iOS binary, project.yml:182-184), but a watch-side INSTALL can lag an
/// iPhone update by hours — the version probe turns that skew into honest
/// "update" copy instead of a decode crash (D-13).
public enum WatchRelayProtocolVersion {
    public static let current = 1
}

/// Everything the watch can ask for — the REDUCED control set, strategy
/// §2.4.2 ("Next/Prev/Blackout/Logo/status"), EXACTLY (D-5). Flat String
/// raw values (not associated values) so Codable is trivially synthesized
/// and the wire shape is self-evident.
public enum WatchRelayCommand: String, Sendable, Codable, Equatable, CaseIterable {
    case nextComponent, prevComponent          // → IHRP next/prevComponent
    case showLyrics, showBlackout, showLogo    // → IHRP setDisplayState(...)
    case requestState                          // "status" — answered from the snapshot, never forwarded
}

/// The compact projection of the iPhone's remote-control state — the ONLY
/// thing the watch ever knows. Derived EXCLUSIVELY from `UIPhase` (§3.5);
/// carries nothing the venue screen doesn't already display publicly (§8).
public struct WatchRelaySnapshot: Sendable, Codable, Equatable {
    public enum Phase: String, Sendable, Codable, Equatable, CaseIterable {
        case noSession          // no coordinator registered, or browsing
        case pairing            // codeEntry / confirmingFingerprint — finish on the iPhone
        case connecting         // connecting or reconnecting (the watch can't tell them apart, and needn't)
        case controlling
        case phoneBackgrounded  // UIPhase.suspended — the phone app left the foreground (fact 6)
    }
    public var phase: Phase
    public var tvName: String?
    /// `SongID.rawValue` (e.g. "MP-1008") — DISPLAY-ONLY parity with the
    /// phone surface's own header (`RemoteControlSurfaceView.swift:84`);
    /// never parsed, never fetched-for (D-6).
    public var songRef: String?
    /// `IHRPDisplayState.rawValue` as a tolerant String — an unknown value
    /// from a newer phone renders as "no selection highlighted", never a
    /// decode failure.
    public var displayState: String?
    public var revision: UInt64?

    public static let noSession = WatchRelaySnapshot(
        phase: .noSession, tvName: nil, songRef: nil, displayState: nil, revision: nil)
}

/// The synchronous answer to every watch command.
public struct WatchRelayReply: Sendable, Codable, Equatable {
    public enum Outcome: String, Sendable, Codable, Equatable {
        case forwarded    // the intent went to the TV on the live session
        case replyOnly    // requestState — nothing to forward, snapshot attached
        case rejected     // no live controlling session — snapshot says why
    }
    public var outcome: Outcome
    /// ALWAYS attached — the watch renders from this, so even a rejection
    /// self-heals the watch's picture of the world.
    public var snapshot: WatchRelaySnapshot
}
```

Deliberately ABSENT from the snapshot (each a decision, not an oversight): `componentIndex`/`lineIndex` (the watch UI has no per-line display — D-5 keeps line nav out); the TV fingerprint or ANY pairing material (§8 row 3); song title (D-6); `frozen` as a watch-settable state (D-5 — a phone-surface nicety; if the TV IS frozen, `displayState` faithfully reports the raw string and the watch simply highlights nothing).

### 3.2 The codec (`WatchRelayCodec`, same file — the ONLY serializer either shell may use)

```swift
public enum WatchRelayCodecError: Error, Sendable, Equatable {
    case malformed
    case unsupportedVersion(Int)
}

public enum WatchRelayCodec {
    /// The three WCSession dictionary keys — single-key envelopes, the whole
    /// payload one JSON-encoded Data blob. The [String: Any] dictionaries
    /// WCSession requires are built ONLY in the §4 shells; everything
    /// testable lives at the Data level here.
    public static let commandKey = "c"
    public static let replyKey = "r"
    public static let snapshotKey = "s"

    public static func encode(command: WatchRelayCommand) -> Data
    public static func encode(reply: WatchRelayReply) -> Data
    public static func encode(snapshot: WatchRelaySnapshot) -> Data
    public static func decodeCommand(_ data: Data) -> Result<WatchRelayCommand, WatchRelayCodecError>
    public static func decodeReply(_ data: Data) -> Result<WatchRelayReply, WatchRelayCodecError>
    public static func decodeSnapshot(_ data: Data) -> Result<WatchRelaySnapshot, WatchRelayCodecError>
}
```

Wire shape: `{"v": 1, "p": <payload>}` via a private generic `Envelope<T: Codable>`. Decode = two-step: probe `{"v": Int}` first (any decode failure ⇒ `.malformed`); `v != current` ⇒ `.unsupportedVersion(v)` (strict equality — both ends ship from one repo; there is no N-version support matrix, the same "fail loud, fail closed" posture as `IHRPProtocolVersion`, `IHRPMessage.swift:42-58`); then decode the payload (failure ⇒ `.malformed`). `encode` uses a plain `JSONEncoder` with `sortedKeys` (deterministic bytes → the §7.1 size assertion is stable). Errors NEVER throw across the shells — `Result`, always handled.

### 3.3 The relay brain (`WatchRelayRules.swift`, IHLive — pure, the decision table IS the security confinement)

```swift
public enum WatchRelayDecision: Sendable, Equatable {
    case forward(IHRPMessage)   // send to the TV, reply .forwarded
    case replyOnly              // answer from the snapshot, reply .replyOnly
    case reject                 // reply .rejected — the snapshot carries the why
}

public enum WatchRelayRules {
    /// The COMPLETE watch-command → LAN-intent map. `requestState` maps to
    /// nil BY DESIGN — status is answered locally, never a wire round-trip.
    /// This function is the ONLY place a WatchRelayCommand can become an
    /// IHRPMessage; a case added to the command enum without a row here is
    /// a compile error (exhaustive switch, no default).
    public static func intent(for command: WatchRelayCommand) -> IHRPMessage?

    /// The gate: control commands forward ONLY from `.controlling`; the
    /// status request answers from ANY phase; everything else is an honest
    /// rejection. Pure — the hub supplies `phase` from its last published
    /// snapshot, so this table is the ENTIRE authorization surface of the
    /// relay (over and above the session's own pairing, which this PR never
    /// touches).
    public static func decide(_ command: WatchRelayCommand, phase: WatchRelaySnapshot.Phase) -> WatchRelayDecision
}
```

The intent map (BINDING, tested row-by-row): `nextComponent → .nextComponent` · `prevComponent → .prevComponent` · `showLyrics → .setDisplayState(.lyrics)` · `showBlackout → .setDisplayState(.blackout)` · `showLogo → .setDisplayState(.logo)` · `requestState → nil`.

The decision table (BINDING — §7.1 asserts all 30 cells):

| command \ phase | noSession | pairing | connecting | controlling | phoneBackgrounded |
|---|---|---|---|---|---|
| nextComponent / prevComponent / showLyrics / showBlackout / showLogo | reject | reject | reject | **forward(intent)** | reject |
| requestState | replyOnly | replyOnly | replyOnly | replyOnly | replyOnly |

### 3.4 `RemoteControlRelayHub` (IHFeatures — platform-neutral, `@MainActor`, the ONE coupling point)

```swift
/// The one place WatchConnectivity-shaped traffic meets the LAN remote —
/// and it doesn't even import WatchConnectivity. The iOS-only
/// `PhoneWatchRelayService` (§4.2) is its ONLY producer/consumer; on every
/// other platform this type compiles, sits idle, and costs nothing (D-3).
@MainActor
public final class RemoteControlRelayHub {
    public static let shared = RemoteControlRelayHub()

    /// Single-slot, weak — the screen-scoped coordinator (fact 5) registers
    /// in `start()` and unregisters in `stop()`; a replacement registration
    /// (tab-switch overlap: new start() before old stop()) simply takes the
    /// slot, and the old unregister is identity-checked so it can't evict
    /// its successor.
    private(set) weak var coordinator: RemoteControlCoordinator?
    public private(set) var lastSnapshot: WatchRelaySnapshot = .noSession
    /// Set ONCE by PhoneWatchRelayService.activate() on iOS; nil forever
    /// elsewhere. Fired only on CHANGE (Equatable dedup) — fact 7's echo
    /// volume never reaches WatchConnectivity.
    public var onSnapshot: (@MainActor (WatchRelaySnapshot) -> Void)?

    func register(_ c: RemoteControlCoordinator)          // + publish(c's current snapshot)
    func unregister(_ c: RemoteControlCoordinator)        // identity check; + publish(.noSession)
    func publish(_ snapshot: WatchRelaySnapshot)          // dedup, store, fire onSnapshot

    /// The command door. Reads the CURRENT phase, runs the pure decide,
    /// forwards via the coordinator's existing public sendIntent (fact 4).
    public func handle(_ command: WatchRelayCommand) async -> WatchRelayReply
}
```

`handle` (exact behaviour): `let phase = coordinator == nil ? .noSession : lastSnapshot.phase` (a dead coordinator — deallocated without stop(), defensive — degrades to `.noSession`); `switch WatchRelayRules.decide(command, phase:)` → `.forward(msg)`: `await coordinator?.sendIntent(msg)`, reply `(.forwarded, lastSnapshot)`; `.replyOnly`: reply `(.replyOnly, lastSnapshot)`; `.reject`: reply `(.rejected, lastSnapshot)`. The reply snapshot is the PRE-echo state on a forward (the TV's echo arrives asynchronously moments later via the push channel — §1.1's two-channel design; the reply's job is acknowledgement + truth, not prediction). Log per command: `IHLog.remote.notice("watchrelay.hub command=\(command.rawValue, privacy: .public) outcome=\(outcome, privacy: .public)")` — case names only, never a tvName/songRef here.

### 3.5 The snapshot mapping (`RemoteControlCoordinator+WatchRelay.swift` — pure, mirrors the `uiPhase(after:)` idiom)

```swift
extension RemoteControlCoordinator {
    /// UIPhase → the watch's compact truth. Pure + nonisolated static so
    /// `WatchRelaySnapshotMappingTests` drives it with zero actors (the
    /// exact `uiPhase(after:current:tvName:)` test idiom, fact 17's suite).
    nonisolated static func relaySnapshot(from phase: UIPhase) -> WatchRelaySnapshot
}
```

| UIPhase (`+UIPhase.swift:29-43`) | → snapshot |
|---|---|
| `.browsing` | `.noSession` (all nil) |
| `.connecting(tvName, _)` | phase `.connecting`, tvName |
| `.codeEntry(tvName, _)` | phase `.pairing`, tvName |
| `.confirmingFingerprint(tvName, _)` | phase `.pairing`, tvName (the fingerprint NEVER crosses — §8 row 3) |
| `.controlling(tvName, state)` | phase `.controlling`, tvName, songRef = `state.songId?.rawValue`, displayState = `state.displayState.rawValue`, revision = `state.revision` |
| `.reconnecting(tvName, _)` | phase `.connecting`, tvName |
| `.suspended(tvName)` | phase `.phoneBackgrounded`, tvName |

Plus the three thin tap helpers the core file calls (`relayRegister()` = `RemoteControlRelayHub.shared.register(self)`, `relayUnregister()`, `relayPublish()` = `RemoteControlRelayHub.shared.publish(Self.relaySnapshot(from: uiPhase))`) — kept here so `RemoteControlCoordinator.swift` gains only three one-line calls (fact 15).

---

## 4. The two WCSession shells + the concurrency bridge

`WCSessionDelegate` is a pre-async, thread-agnostic NSObject protocol — the bridge into Swift 6 strict concurrency is the ONE pattern, used identically on both sides (fact 16, the `SessionController.stateUpdates` idiom): a `final class … : NSObject, WCSessionDelegate` shell whose callbacks do NOTHING but translate their arguments into a `Sendable` inbound value and `continuation.yield(...)` it; a single `for await` loop (per side) consumes the stream in isolation order. **Never a `Task {}` per callback** — WatchConnectivity delivers messages in order, and a Task-per-message fan-out would let two rapid "Next" taps race and land reversed at the TV.

### 4.1 The inbound streams (both sides — ordered, single-consumer by construction)

```swift
// Phone side (inside PhoneWatchRelayService.swift):
enum PhoneRelayInbound: Sendable {
    case activated(Bool)                            // activationDidComplete (success flag; error logged in the shell)
    case reachabilityChanged(Bool)                  // sessionReachabilityDidChange → isReachable
    case command(Data, WatchRelayReplyBox)          // didReceiveMessage(replyHandler:) — Data under codec.commandKey
    case needsReactivate                            // sessionDidDeactivate (watch switched) — call activate() again
}

// Watch side (inside WatchRemoteController.swift):
enum WatchRelayInbound: Sendable {
    case activated(Bool)
    case reachabilityChanged(Bool)
    case snapshotData(Data)                         // didReceiveMessage (no reply) OR didReceiveApplicationContext
}
```

**`WatchRelayReplyBox` (phone side, the one `@unchecked Sendable` in this PR — justified in its header):** WCSession's `replyHandler` is a plain `([String: Any]) -> Void` closure Apple documents as callable from any thread, but it is not `@Sendable` and `[String: Any]` is not `Sendable` — it cannot ride an `AsyncStream` element without a wrapper. `WatchRelayReplyBox` is a tiny `final class: @unchecked Sendable` that stores the closure, exposes exactly `func send(replyData: Data)` (building the `[WatchRelayCodec.replyKey: data]` dictionary INSIDE the box, so the non-Sendable dictionary never crosses an isolation boundary), and is called AT MOST ONCE (an `OSAllocatedUnfairLock<Bool>` consumed flag — a double-call of a WCSession reply handler is an Apple-side exception; the flag makes it structurally impossible). This is the same "confine the unsafety to one 20-line, header-justified type" trade PR-8's D-2 made — the alternative (making the whole delegate `@unchecked Sendable` or sprinkling `nonisolated(unsafe)`) spreads the unsafety instead of boxing it.

### 4.2 `PhoneWatchRelayService` (iOS — `#if os(iOS) && canImport(WatchConnectivity)`)

```swift
@MainActor
public final class PhoneWatchRelayService {
    public static let shared = PhoneWatchRelayService()
    /// Idempotent. Guard `WCSession.isSupported()` (false on iPad — the
    /// relay simply never exists there); install the delegate shell
    /// (retained strongly HERE — WCSession.delegate is weak); `activate()`;
    /// spawn the ONE inbound-consumer task; set `RemoteControlRelayHub
    /// .shared.onSnapshot = { [weak self] in self?.push($0) }`.
    public func activate()
}
```

The consumer loop (one `for await inbound in stream`, `@MainActor` via the service's isolation):
- `.command(data, replyBox)`: `WatchRelayCodec.decodeCommand(data)` → success: `let reply = await RemoteControlRelayHub.shared.handle(command)`; `replyBox.send(replyData: WatchRelayCodec.encode(reply: reply))`. Failure: reply `WatchRelayReply(outcome: .rejected, snapshot: RemoteControlRelayHub.shared.lastSnapshot)` — decode failures still get an ANSWER (the watch's own version/copy logic decides what to show; a swallowed reply would surface as a spurious timeout on the watch). Log `.notice` `"watchrelay.phone command-decode outcome=\(ok|malformed|unsupportedVersion, privacy: .public)"`.
- `.reachabilityChanged(true)`: re-push the current `lastSnapshot` unconditionally (the watch may have missed live pushes while unreachable; applicationContext usually covers this, but the re-push closes the gap at zero cost). Log transition `.notice` `"watchrelay.phone reachable=\(flag, privacy: .public)"`.
- `.activated(success)`: log; on success push the current snapshot (covers the background-launch-by-watch-message path, §6.2).
- `.needsReactivate`: `WCSession.default.activate()` (Apple's documented requirement after `sessionDidDeactivate` when the user switches watches).

`push(_ snapshot:)` (called only by the hub's deduped `onSnapshot`): (a) if `session.activationState == .activated && session.isReachable` → `session.sendMessage([WatchRelayCodec.snapshotKey: data], replyHandler: nil, errorHandler: nil)` — fire-and-forget, the low-latency live channel; (b) ALWAYS (when `activationState == .activated && session.isPaired && session.isWatchAppInstalled`) → `try? session.updateApplicationContext([WatchRelayCodec.snapshotKey: data])` — the latest-wins catch-up channel (OS-coalesced; a watch app launching cold reads the last context immediately). Both under one guard-and-log; failures are `.debug` noise, never user-visible (D-7). tvName/songRef never appear in any log line from this file (they're IN the payload, not the log).

The delegate shell (`PhoneRelayDelegate`, same file): `nonisolated` NSObject; implements `activationDidComplete` / `sessionDidBecomeInactive` (log only) / `sessionDidDeactivate` (`yield(.needsReactivate)`) / `sessionReachabilityDidChange` / `session(_:didReceiveMessage:replyHandler:)` — the last extracts `message[WatchRelayCodec.commandKey] as? Data` (absent ⇒ reply immediately with a `.rejected`+`.noSession` reply via the box — never leave a reply handler hanging — and log `.debug` "unknown-message-shape"), wraps the handler in a `WatchRelayReplyBox`, yields. NO other WCSession callback is implemented (no `transferUserInfo`/file-transfer surface exists to receive).

### 4.3 `WatchRemoteController` (watchOS — `#if os(watchOS) && canImport(WatchConnectivity)`)

```swift
@MainActor @Observable
public final class WatchRemoteController {
    public private(set) var snapshot: WatchRelaySnapshot = .noSession
    public private(set) var isPhoneReachable = false
    public private(set) var isActivated = false
    /// Transient, auto-cleared on the next successful reply/push — "iPhone
    /// didn't answer" / "Update iHymns on your iPhone and Apple Watch."
    public private(set) var notice: String?

    public func activate()                     // idempotent; delegate shell + consumer loop, §4.1
    public func send(_ command: WatchRelayCommand)
    public func refresh()                      // = send(.requestState)
}
```

`send(_:)`: guard `isActivated` else set the not-ready notice and return. Build `[WatchRelayCodec.commandKey: WatchRelayCodec.encode(command: command)]`; `WCSession.default.sendMessage(_:replyHandler:errorHandler:)` with BOTH handlers (a reply-bearing sendMessage from the watch is what WAKES a backgrounded/terminated iOS app — §6.2 — so this is the right primitive even when `isReachable` reads false). The two handlers are non-Sendable-callback world again: each hops its payload into the controller via the SAME inbound stream discipline — concretely, the reply handler extracts `Data` under `replyKey` and yields a fourth inbound case `replyData(Data)`; the error handler yields `sendFailed(String)` (the `WCError.code`'s name, NOT the raw NSError description — bounded, log-safe). Consumer loop: `.replyData` → `decodeReply` → success: `snapshot = reply.snapshot; notice = nil` (outcome `.rejected` needs NO extra copy — the snapshot phase drives the §5 guidance screens, which ARE the explanation); `.unsupportedVersion` → the D-13 update copy; `.malformed` → generic "iPhone didn't answer properly". `.snapshotData` → `decodeSnapshot` → `snapshot =`, `notice = nil`. `.sendFailed` → "iPhone not reachable — bring your iPhone nearby with iHymns open." `.reachabilityChanged` → `isPhoneReachable =`; on a false→true transition, `refresh()` (pull, don't wait for a push). `.activated(true)` → `isActivated = true; refresh()`.

Rapid taps: `sendMessage` calls are independent and WatchConnectivity preserves message order phone-side (§4.1's single consumer preserves it end-to-end); replies may interleave but each carries a full snapshot, and the PUSH channel delivers the final truth — no client-side queue, no debounce (a remote that debounces "Next" feels broken).

Logging (watch side): `IHLog.remote.notice("watchrelay.watch send=\(command.rawValue, privacy: .public)")`, activation/reachability transitions; NEVER the snapshot contents.

---

## 5. The reduced Watch UI (`WatchRemoteView` + `WatchRootView`) — and the honest no-session UX

**watchOS-clean by construction** (fact 11): `NavigationStack`/`ScrollView`/`VStack`/`HStack`/`Button`/`Label`/`Text`/`Image`/`ProgressView` only. No `Menu`, no `.segmented`, no `DisclosureGroup`, no drag/drop, no keyboard shortcuts, no `ToolbarItemPlacement.navigation`. Buttons ≥44 pt hit targets; every control has an `accessibilityLabel`; the display row exposes its selection state via `accessibilityAddTraits(.isSelected)`.

`WatchRootView`: `@State private var controller = WatchRemoteController()`; `NavigationStack { WatchRemoteView(controller: controller) }`; `.task { controller.activate(); controller.refresh() }`; `@Environment(\.scenePhase)` → on `.active`, `controller.refresh()` (the watch app resuming from the always-on/backgrounded state must re-pull — pushes may have been dropped while non-reachable, and applicationContext delivery timing is opportunistic).

`WatchRemoteView` renders by `controller.snapshot.phase` — one screen per phase, copy BINDING (plain English, honest about the relay's real constraints — facts 5–6):

| phase | Screen |
|---|---|
| `.controlling` | The remote (below). |
| `.noSession` | Icon `appletv` + **"No TV connected"** + "Open iHymns on your iPhone and connect to a TV — this watch remote drives the phone's connection." |
| `.pairing` | **"Finish pairing on your iPhone"** + "Enter the code (or confirm the fingerprint) on your iPhone to finish connecting to `tvName`." |
| `.connecting` | `ProgressView()` + **"Connecting…"** + the `tvName` — covers reconnects too (§3.5 folds both). |
| `.phoneBackgrounded` | Icon `iphone.slash` (or `lock.iphone`) + **"iPhone is locked or in the background"** + "iHymns pauses the TV link while it's not on screen. Unlock your iPhone and reopen iHymns to resume." — the TRUTH of fact 6, stated instead of a mystery spinner. |

Overlaid on any phase: `controller.notice` (transient, small `.footnote` themed line) and — dominating the guidance screens when `!isPhoneReachable && isActivated` — "iPhone not reachable. Bring your iPhone nearby." (Reachability honesty: on watchOS, `isReachable` false mostly means the phone is out of range/BT+WiFi off — a live `sendMessage` may STILL succeed by background-launching the app when in range, which is why `send` never gates on the flag; the flag drives COPY, not capability.)

The `.controlling` remote (top to bottom, one non-scrolling screen on 45 mm; `ScrollView` for smaller):
1. **Status header** (compact, 2 lines): `tvName` (`.headline`) + `songRef ?? "Nothing selected"` (`.footnote.secondary`) — byte-parity with the phone header (`RemoteControlSurfaceView.swift:84`, fact 8).
2. **Prev / Next row** — the primary controls, two large buttons (`chevron.left`/`chevron.right`, `.borderedProminent`, equal flex): `controller.send(.prevComponent)` / `.nextComponent`. Accessibility labels "Previous verse"/"Next verse" (the phone surface's exact strings, `RemoteControlSurfaceView.swift:108-116`).
3. **Display row** — three equal buttons **Lyrics / Black / Logo** → `send(.showLyrics/.showBlackout/.showLogo)`; the one matching `snapshot.displayState` renders highlighted (`.borderedProminent` vs `.bordered` + `.isSelected` trait). An unknown/`frozen` displayState highlights none (tolerant String, §3.1).

**Stateless/reflective, one hop longer:** nothing on the watch toggles locally — a tap sends; the highlight/header change ONLY when the echoed state arrives via reply/push, exactly the `RemoteControlSurfaceView` design contract (its header, verbatim posture). At the strategy's ~100–300 ms relay budget this reads as "responsive" without any optimistic-UI reconciliation code.

Deliberately OUT of the watch UI (D-5, each with the reason): line prev/next + Digital-Crown line scroll (no per-line display on the watch to make position legible — blind line-nav at a live service is worse than none; future issue if real usage asks); song picker/search (a catalogue browse is Phase 1.5's watch app, and `selectSong` from a blind list is a projection hazard); `frozen` (operator nicety, phone has it); appearance/scroll/`jumpLine` (not in strategy's reduced set).

---

## 6. Lifecycle

### 6.1 Activation topology

- **iPhone:** `PhoneWatchRelayService.shared.activate()` from `IHymnsApp.init()` (`#if os(iOS)`) — EVERY launch path, including background launches (§6.2); idempotent; no-ops via `WCSession.isSupported()` on iPad. The service lives for the process lifetime; the HUB carries no WCSession types, so the coordinator side is lifecycle-symmetric on all platforms.
- **Watch:** `WatchRemoteController.activate()` from `WatchRootView.task` — the watch app IS the remote screen for now (D-12), so view-lifetime == app-lifetime here.
- **Coordinator:** registers/unregisters with the hub inside its EXISTING `start()`/`stop()` (fact 5) — the relay's availability window is exactly the remote screen's lifetime, with `.noSession` published at both boundaries so the watch never shows a stale `controlling` screen after the phone navigates away.

### 6.2 The background-wake path (honest capability statement — put this in the PR body)

A reply-bearing `sendMessage` from the watch **launches/wakes the iOS app in the background** (documented WatchConnectivity behaviour). What that buys us: the delegate (installed in `init`, NOT `.task` — scene bodies may never run on a background launch) receives the command and replies HONESTLY within a second or two — which, with no coordinator registered (screen-scoped, fact 5) or a suspended session (fact 6), is `(.rejected, .noSession/.phoneBackgrounded)` → the watch renders the §5 guidance instead of timing out. What it does NOT buy: actual TV control from a pocketed/locked phone — `setSuspended(true)` already sent `.endControl` and disconnected (fact 6), and re-establishing a LAN TLS session from a background wake is out of scope BY DECISION (D-9: needs background-networking posture we don't declare, an auto-reconnect nobody consented to on-screen, and an App-Review conversation — parked as a possible future enhancement issue, not smuggled in).

### 6.3 Reachability + resume matrix (each row = a §7.3 device-test)

| Situation | What happens |
|---|---|
| Phone foreground on the remote screen, controlling | The steady state: commands forward, echoes push, watch mirrors in ~100–300 ms. |
| Phone foreground, DIFFERENT screen | Coordinator stopped → hub `.noSession` → watch guidance screen; commands rejected honestly. |
| Phone locked/backgrounded mid-control | `.suspended` event → tap publishes `.phoneBackgrounded` (push usually lands in the backgrounding window; applicationContext guarantees eventual truth) → watch shows the unlock copy. |
| Phone returns to foreground | `setSuspended(false)` reconnects (<1 s, `+Link.swift:374-397`) → `.controlling` echo → push → watch resumes silently. |
| Watch out of range / phone off | `isReachable` false → copy overlay; a tap's `errorHandler` fires → transient notice; NO queueing (D-8). |
| Watch app cold-launch | applicationContext delivers the last snapshot immediately; `refresh()` then pulls the live truth. |
| User switches watches | `sessionDidDeactivate` → `.needsReactivate` → re-`activate()` (Apple's documented dance) — relay resumes on the new watch with zero state to migrate (the watch holds NOTHING durable). |

---

## 7. Test plan (Swift Testing; pure-first; WCSession itself is NEVER unit-tested — `WCSession.isSupported()` is false on the macOS test host, and both shells are thin enough that §3's pure suites carry the correctness load)

### 7.1 Always-on pure suites (IHLiveTests)

- **`WatchRelayCodecTests`** — every `WatchRelayCommand` round-trips (CaseIterable loop); snapshot round-trips full (`controlling` + all fields) and minimal (`.noSession`); reply round-trips all three outcomes; the encoded JSON literally contains `"v":1` (sortedKeys determinism); `{"v":99,"p":…}` ⇒ `.unsupportedVersion(99)`; truncated/garbage/empty Data ⇒ `.malformed`; a full snapshot encodes to `< 512` bytes (the tiny-payload contract, executable — WatchConnectivity's practical budget is orders of magnitude above this, the assertion guards against a future field-creep regression, not the OS limit).
- **`WatchRelayRulesTests`** — the intent map row-by-row (6 rows, incl. `requestState → nil`); the FULL 30-cell decision table via `allCases × allCases` against a literally-written expected function; **every `.forward(msg)` result satisfies `msg.isControlIntent`** (so `RemoteControlSession.sendIntent`'s `assertionFailure` guard, `RemoteControlSession.swift:281-284`, is unreachable from the relay by proof, not hope).

### 7.2 Always-on IHFeaturesTests (`@MainActor` where stateful, `@testable import IHFeatures` — fact 17)

- **`WatchRelaySnapshotMappingTests`** — the §3.5 table, one row per `UIPhase` case (7 rows); `.controlling` asserts songRef/displayState/revision propagation from a constructed `IHRPState`; `.confirmingFingerprint` asserts the fingerprint does NOT appear anywhere in the snapshot (encode it and assert the hex string is absent from the bytes — §8 row 3, executable).
- **`RemoteControlRelayHubTests`** — the §2 table's rows: no-coordinator rejection; `.replyOnly`/`.forwarded` against a registered coordinator with `uiPhase` set directly (the coordinator's un-started session swallows the send via `try?` — no network, no flake); publish dedup fires `onSnapshot` exactly once for a repeated snapshot; `unregister` publishes `.noSession`; second-registration replaces; a STALE `unregister` (old coordinator after a new one registered) does NOT evict the new one (identity check).
- **`RemoteControlUIStateTests`** — byte-unchanged, run before and after the `+Persistence.swift` relocation commit-step (the PR-8 §7.2 "provably pure move" discipline).

### 7.3 Device-only matrix (state honestly in the PR body — the ONLY way to exercise WCSession; mirrors the §6.3 table)

Real iPhone + paired real Watch (+ the Apple TV dev build from PR-6): steady-state relay latency feel (tap-to-TV ≤ ~300 ms, tap-to-watch-highlight one echo later); every §6.3 row; background-wake honest rejection (phone locked, watch tap → guidance within ~2 s, no timeout spinner); cold watch launch shows the last context then refreshes; watch-switch reactivation. Simulator note: PAIRED phone+watch simulators DO relay WatchConnectivity messages (partially — reachability semantics differ) and are useful for smoke-testing the UI, but the PR's evidence bar is the real-device pass, same as PR-6/7/8's device rows.

### 7.4 Local pre-PR verification (builder runs ALL — CI is not a required check, #1526)

```bash
cd appApple/Packages/iHymnsKit && swift build && swift test
swiftlint --config appApple/.swiftlint.yml appApple          # 0 violations
bash appApple/Scripts/loc-budget.sh                          # every file ≤400 (incl. the shrunken coordinator)
# watchOS package cross-compile — post-#1549 this MUST be green (the watch code RUNS there now):
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk watchsimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-watchos26.0-simulator
# iOS package cross-compile:
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk iphonesimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-ios26.0-simulator
# Shared IHFeatures files were edited ⇒ ALL app-scheme builds (the #1532 lesson):
cd appApple && xcodegen generate
xcodebuild -project iHymns.xcodeproj -scheme iHymns   -destination 'generic/platform=iOS Simulator'  CODE_SIGNING_ALLOWED=NO build   # embeds + compiles the REAL watch app (#1549 CI step, mirrored)
xcodebuild -project iHymns.xcodeproj -scheme iHymns   -destination 'platform=macOS,arch=arm64'       CODE_SIGNING_ALLOWED=NO build
xcodebuild -project iHymns.xcodeproj -scheme iHymnsTV -destination 'generic/platform=tvOS Simulator' CODE_SIGNING_ALLOWED=NO build
```

**Builder footnotes:** (1) a `swift build` right after a clean may fail on the local `safe.bareRepository=explicit` git setting — prefix `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all` (harmless on CI). (2) Disk is tight (~5–7 GB free) — prefer the two package cross-compiles for iteration; run the three app-scheme builds ONCE at the end, and `rm -rf ~/Library/Developer/Xcode/DerivedData/iHymns-*` between them if space pinches.

---

## 8. Threat model (acceptance criteria — PR-6 §8 / PR-7 §8 / PR-8 §8 all still apply wholesale and are UNTOUCHED; these are the relay rows) + Decisions

| Threat | Mitigation (mechanism, THIS PR) | Residual |
|---|---|---|
| **Command injection into the relay channel** (a third party driving the TV via the watch link) | WatchConnectivity is the OS's paired-device channel: encrypted in transit by the system, deliverable ONLY between THIS app and ITS OWN watch extension on the user's paired watch (per-app namespacing — another app's WCSession cannot address ours). There is no listener, no port, no discovery surface added anywhere. | A compromised/jailbroken phone or watch — out of scope (device compromise defeats the phone's own remote UI identically). |
| **Privilege escalation through the relay** (the watch doing MORE than the phone user authorized) | The entire authorization surface is the pure §3.3 table: 5 forwardable intents, all `isControlIntent`, forwarded ONLY in `.controlling` phase, ONLY through `coordinator.sendIntent` into a session the phone user opened, paired, and has on-screen. No pairing action, no code entry, no TOFU confirm, no forget/revoke, no `selectSong`, no reconnect trigger is reachable from any watch command — there is NO code path (grep gate: the only `WatchRelayRules.intent` cases). | None — the relay is a strict subset of what the phone screen already permits. |
| **Credential / pairing-material exposure over the watch link** | By construction: the snapshot's five fields are enumerated (§3.1) and derived only from `UIPhase` (§3.5); the pairing token/code/proof/nonce/fingerprint never enter `UIPhase` in transmissible form except `.confirmingFingerprint`'s hex — which the mapping DROPS (§3.5 table; §7.2 asserts its absence from the encoded bytes). The strategy §2.2.1 auth-token-to-watch-Keychain mirror is a DIFFERENT future feature — tripwire: NO Keychain code, NO token type, NO `IHAuth` import appears in any new file (D-10). | None. |
| **Stale/replayed commands** (a queued "next verse" firing minutes late mid-service) | D-8: commands NEVER queue — `sendMessage` is live-or-error; `transferUserInfo` is deliberately unused for commands; a rejection is final and honest. Ordering is preserved end-to-end by the single-consumer inbound streams (§4.1). | A command in flight during the phase's last ms can land after a disconnect — it dies at `try? remote.send` exactly like a phone-surface tap in the same window. Identical class, identical (nil) consequence. |
| **Relay-triggered flood at the TV** | The watch is human-tap-driven (no repeat timers, no crown-to-command mapping); every command costs a WCSession round-trip (~100 ms floor); the TV keeps its own defensive `rateLimited` taxonomy (`IHRPPayloads.swift:183`). | Accepted — a human mashing the watch is indistinguishable from a human mashing the phone. |
| **Information disclosure TO the watch** | The snapshot carries exactly what the venue projection screen is already showing publicly (TV name, song id, display state) — nothing gated, no lyric text (the IHRP content invariant extends across this hop: nothing in the relay can carry what the LAN link itself never carries). | — |
| **Secrets/PII in logs** | Binding contract restated: log ONLY command/outcome/phase case names + reachability/activation booleans (`.public`); tvName `.private` if ever logged; songRef and snapshot contents never logged; the reply box logs nothing. Review gate: grep every new file's `IHLog` calls. | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — The relay drives the phone's EXISTING session; `IHRPRemoteKind.watchRelay` stays dormant.** A dedicated `.watchRelay` connection would be a SECOND session needing its own pairing/token custody — contradicting "already-authorized intents through the already-paired session" and doubling the TV-side trust surface for zero user value. The TV's trusted-remotes list correctly shows the PHONE, which is the device that paired and is connected. `.watchRelay` remains reserved (documented on the case) for a hypothetical future dedicated-relay design.
- **D-2 — The single-consumer rule is honoured by tapping the coordinator's APPLIED state** (end-of-`apply` publish + start/stop register), never by a second `events`/actor-stream consumer and never by Observation-tracking loops (§1.3). This is THE architectural invariant of the PR.
- **D-3 — The hub is platform-neutral and WatchConnectivity-free;** all `#if os(...)`/`canImport` gating is confined to the two shell files + one app-shell line. The coordinator's tap lines are unconditional on every platform (idle-hub cost: one Equatable compare per event).
- **D-4 — Data-blob envelopes under single dictionary keys** (`"c"`/`"r"`/`"s"`), one versioned JSON codec, `Result`-not-throw at the shell boundary. Field-by-field `[String: Any]` marshalling is banned (untestable, un-Sendable, skew-fragile).
- **D-5 — The reduced set is strategy §2.4.2's list EXACTLY** (Next/Prev component, Blackout, Logo, Lyrics-restore, status). Line nav, crown scroll, song picker, `frozen`, appearance — all OUT with reasons (§5); park any future ask as its own issue.
- **D-6 — The watch shows `songId.rawValue`, never a fetched title** — parity with the phone surface header (fact 8); the relay path performs ZERO API calls.
- **D-7 — Dual-channel state push:** `sendMessage` (reachable, fire-and-forget) for latency + `updateApplicationContext` (always, OS-coalesced latest-wins) for catch-up; hub-side Equatable dedup keeps both proportional to real change.
- **D-8 — No command queuing, ever.** Live-or-honest-rejection. A worship-service remote must never fire a stale navigation minutes later.
- **D-9 — No background keep-alive / background reconnect.** PR-7's suspend-on-background contract (fact 6) is unchanged; the background wake buys an honest rejection, not control (§6.2). A future "watch asks the phone to reconnect the saved TV" is EXPLICITLY parked (file a `for consideration` issue at PR time).
- **D-10 — No credential of any kind crosses the watch link** (incl. NOT starting strategy §2.2.1's token-mirror). Tripwire: `IHAuth` imported by no new file.
- **D-11 — No `project.yml`/`apple.yml`/entitlement/plist changes** (facts 13–14, verified by inspection — state in the PR body).
- **D-12 — The watch app's root becomes the remote screen** (`WatchRootView`), retiring `PhaseZeroSkeletonView` from the watch shell; the Phase-1.5 full watch app will fold it into a tab.
- **D-13 — Version skew = honest copy, never a crash:** strict `v == 1` probe; `.unsupportedVersion` → "Update iHymns on your iPhone and Apple Watch"; `.malformed` → generic non-answer copy; every decode failure still ANSWERS (phone side) or NOTICES (watch side).
- **D-14 — The one `@unchecked Sendable` is `WatchRelayReplyBox`** — 20 lines, header-justified, call-once-enforced, dictionary construction confined inside (§4.1). Any second `@unchecked`/`nonisolated(unsafe)` in the diff is a review reject.
- **D-15 — Scope tripwires (reviewer rejects on sight):** Network/Bonjour APIs reachable from watch code; a second consumer of `session.events`/actor streams; ANY diff under `Sources/IHLive/LANRemote/`; a new `RemoteControlSession`/`RemoteSessionActor` API; pairing/Keychain/token changes; lyric text or titles over WatchConnectivity; `transferUserInfo` for commands; a new external package; a new IHFeatures view using a #1549-class watch-unavailable API; `Task {}`-per-callback in a delegate shell.
- **Security notes for the PR body:** reproduce this §8 table verbatim; state that Audit B (plan §2's gate) re-reviews PR-6/7/8/11 together before external TestFlight; no backend contact anywhere in this PR; the watch link is OS-mediated and carries no secret by construction.

---

## 9. Commit plan (one PR, atomic — each commit compiles + `swift test` green)

1. `feat(apple): watch-relay wire protocol + relay rules — pure Codable vocabulary, versioned codec, decision table (#1423)` — `WatchRelayMessages.swift`, `WatchRelayRules.swift`, `WatchRelayCodecTests`, `WatchRelayRulesTests`. Pure IHLive; no consumer yet.
2. `refactor(apple): relocate RemoteControlCoordinator persistence helpers (pure move, LOC headroom) (#1423)` — `+Persistence.swift` byte-identical move of `persistPaired`/`touchLastConnected`; `RemoteControlUIStateTests` + full suite green UNCHANGED (the provably-pure-move discipline, PR-8 §7.2 precedent).
3. `feat(apple): RemoteControlRelayHub + coordinator relay tap — snapshot mapping, register/publish, command door (#1423)` — `RemoteControlRelayHub.swift`, `RemoteControlCoordinator+WatchRelay.swift`, the three coordinator call-lines, `WatchRelaySnapshotMappingTests`, `RemoteControlRelayHubTests`.
4. `feat(apple): WatchConnectivity shells — iPhone relay service + watch controller, ordered AsyncStream bridges (#1423)` — `PhoneWatchRelayService.swift`, `WatchRemoteController.swift`, the `IHymnsApp.init` activation line.
5. `feat(apple): Apple Watch remote UI — reduced control surface + honest phase guidance; watch shell root (#1423)` — `WatchRemoteView.swift`, `WatchRootView.swift`, `IHymnsWatchApp.swift` root swap.
6. `docs(apple): PR-11 docs — CHANGELOG entry (#1423)`.

PR body: §8 table + security notes verbatim; the §7.4 command transcript as evidence; the §7.3 device-only matrix stated honestly (with whatever real-device rows were actually run vs deferred to the Audit-B verify pass); note #1526 (CI not required ⇒ local verification attached), #1549 (watch cross-compile green, no new guarded API), D-9's parked `for consideration` issue (watch-initiated reconnect), and that PR-11 completes plan §2 Track B's remote-side rungs — remaining LAN work is PR-14 (#1425 optional mirror) + the Audit-B gate.
