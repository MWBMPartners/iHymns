# Apple Phase-2 PR-7 — remote-control app UI (iPhone/iPad/Mac/Vision): discovery, pairing client, reconnect ladder, control surface (#1422)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-12).** Sibling of `.claude/apple-phase2-pr6-spec.md` (the TV side + shared crypto, MERGED — its §1 wire ceremony is the BINDING contract this PR implements the CLIENT of, and its §8 threat model is acceptance criteria here too). Grounded in a code-level read of the merged PR-4/PR-5/PR-6 seam on `alpha` (`Sources/IHLive/LANRemote/*`, `Sources/IHFeatures/TVRemoteControlCoordinator.swift` et al.), `apple-native-strategy.md` §2.4.2–§2.4.5, and `apple-phase2-implementation-plan.md` §2 (PR-7 row) + §4 + §5 + §6. A Sonnet builder should execute this top-to-bottom with minimal further judgement. Target branch: **`feat/apple-p2-pr7-remote`** off `alpha`; ONE PR targeting `alpha`. **CI's `apple.yml` is NOT a required check (#1526 — it auto-merged red appApple PRs twice), so every §7 verify command MUST be run locally before the PR is opened.**

---

## 1. The PR-6 / PR-7 boundary — and the exact ceremony/reconnect contract, from the REMOTE's point of view

**PR-6 built (merged, do not touch):** the TV listener ceremony (`TVListenerActor+Pairing.swift`), the ONE proof function (`LANRemotePairingProof`, public specifically so THIS PR calls the same `compute(...)` the TV verifies — `LANRemotePairingProof.swift:12-17`), the QR payload codec (`LANRemotePairingPayload`), the TV's Keychain authority + identity store, the tvOS overlay/Settings UI, and the tvOS-side coordinator (`TVRemoteControlCoordinator.swift` — the composition-root pattern this PR mirrors on the phone side).

**PR-7 builds (this spec):** everything on the **remote-device side** —
- The **entry-path matrix** (§4): ceremony-QR scan → one-shot pair; standing-QR scan/paste → connect + typed code; discovery list → reconnect to already-paired TVs.
- The **pairing-flow client** (pure reducer + the `RemoteControlSession` actor that drives `RemoteSessionActor` through the §1.1 ceremony).
- The **ping/reconnect ladder** (5 s ping, 3 missed pongs → detached, jittered exponential-backoff reconnect) — pure, injected-config state machines + an actor driver, because the transport actor deliberately does neither (verified: `RemoteSessionActor+Connection.swift:169-205` — a receive error or `isComplete` just calls `disconnect()` → `.idle`; no retry, no ping loop anywhere in `IHLive` today).
- The **remote-side paired-TV Keychain store** (raw token + pinned fingerprint + last-good address, **`synchronizable: false` hard-coded** — plan §4/§6.4's tripwire).
- The **control surface** (song search/select, component/line nav, display-state, appearance, scroll) reflecting `.state` broadcasts — last-writer-wins multi-remote per strategy §2.4.4.
- The browse-side **`Info.plist` keys** on the `iHymns` target (`NSLocalNetworkUsageDescription` + `NSBonjourServices` + `NSCameraUsageDescription`) — **load-bearing here**, unlike PR-6's belt-and-braces tvOS key (`project.yml:219-228`): per Apple TN3179 it is *browsing* that trips the Local Network gate, and `NWBrowser` browsing is exactly what this PR ships.

**PR-8 builds (NOT here — tripwire):** manual connect-by-address (typed IP/host + port with NO fingerprint → TOFU), the VPN/AP-isolation troubleshooter, the venue network doc. **The line is the fingerprint:** every PR-7 connect path knows `expectedFingerprint` BEFORE connecting, because `RemoteSessionActor.connect(to:expectedFingerprint:token:)` (`RemoteSessionActor+Connection.swift:48`) has no fingerprint-less path — and this PR must not add one. Pasting the QR **string** (§4 path B') is NOT manual connect: the pasted payload carries the fingerprint, it's just transported by AirDrop/Messages/typing instead of a camera.

### 1.1 The wire ceremony, remote's POV (BINDING — PR-6 spec §1's diagram, mirrored)

```
Remote (this PR)                                      TV (merged PR-6)
────────────────                                      ────────────────
RemoteSessionActor.connect(to:, expectedFingerprint:, token:)
  TLS 1.3; verify_block pins expectedFingerprint      [PR-4, unchanged]
  → phase .connecting → .awaitingPairing (.ready)
  actor auto-sends .hello(token: saved-or-nil, kind)  ─►  paired token? → fast path:
                                        ◄─  .ack(ackSeq)
    ── FAST PATH (token accepted) ──
                                        ◄─  .capabilities(currentState)   → phase .controlling  DONE
    ── CEREMONY (token nil/stale) ──
                                        ◄─  .pairChallenge(nonce)         [the "show code UI" signal]
  proof = LANRemotePairingProof.compute(code,
            fingerprintHex: expectedFingerprint,       ← the fingerprint WE pinned, never one the
            nonceHex: nonce)                             TV sends us (channel binding, PR-6 §3.2)
  .pairConfirm(proof, deviceName)       ─►  constant-time verify…
    on success:                         ◄─  .pairSuccess(token)   → actor stores currentPairingToken;
                                        ◄─  .capabilities(...)      WE persist raw token (§5)
    on wrong code:                      ◄─  .error(.pairingRejected, nil)  → connection STAYS UP,
                                            user retypes (≤3 per connection, then TV tears down)
```

Steady state: WE send `.ping` every 5 s (strategy §2.4.4 — the TV only ever answers `.pong`, it never initiates pings); the TV broadcasts `.state(IHRPState)` to every paired remote on every accepted change (`TVListenerActor+Messages.swift:201-214` — `revision` bumps monotonically, broadcast includes the originator, so our own intents come back as state too).

### 1.2 Facts about the merged seam this spec builds on (verified in code — cite these in file headers)

- **`RemoteSessionActor`'s streams are single-consumer `AsyncStream`s** (`RemoteSessionActor.swift:120-136`). The PR-5 review caught exactly this class of bug. **BINDING: `RemoteControlSession` (§3.4) is the ONLY consumer of `phaseUpdates` and `incomingMessages`; the coordinator/UI consume ONLY the session's own `events` stream.** A second `for await` on either actor stream silently steals frames.
- `connect(...)` throws on transport failure OR pin mismatch (indistinguishable at this layer — `RemoteSessionError.transport`'s doc, `RemoteSessionActor.swift:37-45`; the definitive pin-mismatch signal is the `.fault` log line at `+Connection.swift:69-71`).
- `send(_:)` throws `.notConnected` unless `phase.isConnected` (`+Connection.swift:103-104`); `.awaitingPairing` counts as connected (`RemoteSessionActor.swift:63-72`) — so `.pairConfirm` is sendable mid-ceremony, and a thrown `.notConnected` from the ping loop IS a detach signal.
- On `.error(.unauthorized)` the actor hard-disconnects; on `.error(.pairingRejected)` it does NOT (`+Connection.swift:234-237` + `IHRPPayloads.swift:164-173`, PR-6 D-2) — the retype loop rides on that distinction.
- `.pairSuccess(token)` is captured into `currentPairingToken` (`RemoteSessionActor.swift:104-117`, `+Connection.swift:225-233`), and `.capabilities` always follows on the same connection → `.controlling` fires without this PR touching `phase`.
- `.state`/`.capabilities` both drive `phase = .controlling(state)` (`+Connection.swift:220-226`) — reflecting TV state is "consume phase/events", not new protocol work.
- Discovery: `startDiscovery()` returns a fresh per-call stream of `[LANRemoteDiscoveredService]` (`RemoteSessionActor.swift:156-175`); a result carries **name + `NWEndpoint` only — no fingerprint** (`LANRemoteDiscovery.swift:32-45`), which is WHY discovery alone can never start a brand-new pairing (§4 path C′). Local-Network permission denial surfaces as `NWError.dns(kDNSServiceErr_PolicyDenied)` via `isLocalNetworkPermissionDenied` (`RemoteSessionActor.swift:217-225`).
- `LANRemotePairingPayload.init?(qrString:)` (`LANRemotePairingPayload.swift:112-120`) decodes the exact string both TV QRs render: `code != nil` ⇒ ceremony QR, `code == nil` ⇒ standing QR; `host`/`port` are best-effort optionals (`:49-57`).
- `PairingTestRemote` (`TVListenerPairingLoopbackTests.swift:34-60`) is the mirror-image client recipe — its own header says "this IS the exact recipe PR-7's real client will use." §7.5 inverts it: the REAL client machinery drives a real `TVListenerActor` over loopback TLS.
- The remote-side store's shape precedents: `KeychainTokenStore` (`IHAuth`, `synchronizable` INJECTABLE, default `true` — `KeychainTokenStore.swift:55-65`; the account token WANTS iCloud sync) vs `KeychainLANRemotePairingAuthority` (`IHLive`, `synchronizable` HARD-CODED `false` — `KeychainLANRemotePairingAuthority.swift:211-226`). §5 follows the SECOND, for the same proximity-model reason.
- The app shell: `RootContainerView`'s `.live` section is an honest "coming soon" placeholder today (`RootContainerView.swift:388-394`, switch at `:368-369`, tab at `:321-325`); `RootSection` caps at 7 tabs deliberately (`AppNavigationState.swift:68-80`). §6.1 fills `.live` with a real hub rather than adding an 8th tab.
- Song search building blocks to REUSE, not fork: `AppRootViewModel.filteredSongs` + `CatalogueNumberQuery` (`AppRootViewModel+Search.swift:40-64`); `SongSummary.songId: SongID` (`IHModels/SongSummary.swift:50-60`) is exactly what `.selectSong` wants.
- `IHLog.remote` / `IHLog.discovery` / `IHLog.signposter` exist; AVFoundation is already imported inside IHFeatures (`SongAudioPlaybackEngine.swift`) — the QR scanner adds no new framework class to the dependency picture. No `Package.swift` change anywhere in this PR.

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh` — budget for two-register comments by splitting early). SwiftLint clean. Every file carries ELI5 + DETAILED headers referencing **#1422**, strategy §2.4.4/§2.4.5, and plan §2 PR-7 — match PR-4/PR-6 comment density.

### New — module `IHLive` (`Sources/IHLive/LANRemote/`)

| File | Purpose (one line) |
|---|---|
| `RemotePairingFlowState.swift` | Pure pairing-flow reducer (client half of the ceremony): challenge → (auto-proof \| prompt) → proof → success/rejected. No I/O, no actor. |
| `RemoteLinkPolicy.swift` | TWO pure machines: `RemoteLinkHealthState` (tick-counted 5 s ping / 3-miss → detached) + `RemoteReconnectBackoffState` (jittered exponential backoff, injected unit-random). |
| `PairedTVStore.swift` | `PairedTVRecord` (fingerprint-keyed: raw token + name + last-good host/port + dates) + `PairedTVStoring` protocol + `InMemoryPairedTVStore` (tests/previews). |
| `KeychainPairedTVStore.swift` | The real store — one generic-password item per TV, account = fingerprint, `kSecAttrSynchronizable = false` **hard-coded**, `internal baseAttributes(account:)` test hook (§5). |
| `RemotePairingEntryResolver.swift` | Pure entry-path decisions: payload/saved/discovered → `RemoteConnectPlan`; and the pinned-saved + nearby list merge (`RemoteTVListRow`) with name-match-is-routing-only semantics (§4). |
| `RemoteControlSession.swift` | The supervising `actor` (remote-side mirror of `TVListenerActor`'s role): owns ONE `RemoteSessionActor`, runs the ceremony client, exposes `events: AsyncStream<RemoteControlSessionEvent>` + `send(_:)`/`submitPairingCode(_:)`/`endControl()`/`setSuspended(_:)`. |
| `RemoteControlSession+Link.swift` | The link half (LOC split, the `TVListenerActor+*` precedent): stream consumption loop, ping ticker, detach detection, the reconnect ladder, endpoint alternation. |

### New — module `IHFeatures`

| File | Purpose |
|---|---|
| `RemoteControlCoordinator.swift` | `@MainActor @Observable` composition root (the `TVRemoteControlCoordinator` mirror): builds store + session, consumes `session.events` + discovery, persists on `.paired`, exposes `uiPhase`/rows/errors. Includes the PURE static `uiPhase(after:current:)` event→UI mapping (§7.3-testable). |
| `RemoteDeviceIdentity.swift` | Platform-conditional `IHRPRemoteKind` + device display name (`UIDevice`/`Host`/`WKInterfaceDevice`), the `hello`/`pairConfirm` cosmetic identity. |
| `LiveHubView.swift` | The real `.live` section: "TV Remote" entry (→ `RemoteControlView`) + the retained honest "Live Follow / Service Mode coming soon" block (PR-10's future slot). |
| `RemoteControlView.swift` | Screen scaffold: local-network primer, discovery list (paired pinned + nearby), Scan/Paste entry buttons, scenePhase suspend/resume, connect/disconnect lifecycle. |
| `RemoteControlSurfaceView.swift` | The controlling surface: TV header + state dot, transport controls (component/line/scroll), display-state control, appearance controls, song-picker entry (§6.3). |
| `RemoteSongPickerView.swift` | Search-and-select sheet reusing the extracted shared matcher (§6.3) — sends `.selectSong` on tap, `.prepare` from a context action. |
| `PairingCodeEntrySheet.swift` | 6-digit code entry (path B), failed-attempt copy, "ask the operator" guidance after teardown. |
| `PairingPayloadEntrySheet.swift` | The scan-or-paste chooser: Scan button (`#if os(iOS)`) + paste field (ALL platforms) → `LANRemotePairingPayload(qrString:)` → coordinator. |
| `PairingScanView.swift` | `#if os(iOS)` ONLY: `AVCaptureSession` + `AVCaptureMetadataOutput(.qr)` + preview-layer representable + permission handling (§4.3/§6.4). Non-iOS: file compiles to nothing. |

### New — tests

| File | Gate | Purpose |
|---|---|---|
| `Tests/IHLiveTests/LANRemote/RemotePairingFlowStateTests.swift` | always-on | reducer transitions; **golden-vector reuse** — the flow's computed proof for PR-6 §7.1's frozen inputs equals the frozen output (§7.1). |
| `Tests/IHLiveTests/LANRemote/RemoteLinkPolicyTests.swift` | always-on | 3-miss detach, pong reset, backoff sequence/jitter bounds/cap/reset; defaults asserted literally (§7.2). |
| `Tests/IHLiveTests/LANRemote/RemotePairingEntryResolverTests.swift` | always-on | the §4 matrix as table-driven cases incl. every degraded branch (§7.2). |
| `Tests/IHLiveTests/LANRemote/PairedTVStoreTests.swift` | always-on | `InMemoryPairedTVStore` round-trip/list-order/delete + `PairedTVRecord` Codable stability (§7.2). |
| `Tests/IHLiveTests/LANRemote/KeychainPairedTVStoreTests.swift` | `lanRemoteKeychainIdentityAvailable()` + `KeychainTestSerialization` | real-Keychain round-trip, **`synchronizable == false` asserted on `baseAttributes`**, raw-token-at-rest semantics, lookup-by-fingerprint (§7.4). |
| `Tests/IHLiveTests/LANRemote/RemoteControlSessionLoopbackTests.swift` | `IHYMNS_LAN_LOOPBACK_TESTS=1` | the REAL client vs a REAL `TVListenerActor` over 127.0.0.1 TLS: ceremony (auto-code + typed-code), wrong-code retype, reconnect fast path, **kill-the-listener → detach → backoff → restart → re-attach** (§7.5). |
| `Tests/IHFeaturesTests/RemoteControlUIStateTests.swift` | always-on | the pure `uiPhase(after:current:)` mapping (§7.3). |
| `Tests/IHFeaturesTests/SongsMatchingQueryTests.swift` | always-on | the extracted shared matcher behaves byte-identically to pre-extraction `filteredSongs` fixtures (§7.3). |

### Edited

| File | Edit |
|---|---|
| `Sources/IHLive/LANRemote/RemoteSessionActor+Connection.swift` | On `.ready`, capture `connection.currentPath?.remoteEndpoint`'s host/port into a new stored `resolvedRemoteAddress: LANRemoteResolvedAddress?` (cleared in `disconnect()`); expose `public var currentResolvedRemoteAddress`. Additive ONLY — any change to connect/disconnect/verify behaviour is a review reject. |
| `Sources/IHLive/LANRemote/LANRemoteAddress.swift` | +`public struct LANRemoteResolvedAddress: Sendable, Equatable, Codable { public let host: String; public let port: UInt16 }` (lives beside the existing address helper — one address-shaped file). |
| `Sources/IHFeatures/RootContainerView.swift` | `.live` switch case + Live tab now host `LiveHubView(rootViewModel:)`; `liveComingSoonView` removed (its copy moves INTO `LiveHubView`'s server-half block); header note updated (#1422). |
| `Sources/IHFeatures/AppRootViewModel+Search.swift` | **Extract-first refactor:** the number-then-substring matching core of `filteredSongs` becomes `public static func songsMatching(_ query: String, in songs: [SongSummary]) -> [SongSummary]`; `filteredSongs` delegates to it (facet filtering stays where it is); `RemoteSongPickerView` calls the SAME static. Behaviour byte-identical (§7.3 guards it). |
| `Sources/IHFeatures/IHSettingsStore.swift` | +`hasSeenLocalNetworkPrimer: Bool` (UserDefaults-backed, same shape as `hasSeenOnboarding`) — drives the §4.3 pre-permission explainer. + its `IHSettingsStoreTests` row. |
| `appApple/project.yml` | `iHymns` target gains an `info:` block with `NSLocalNetworkUsageDescription` + `NSBonjourServices: [_ihymns-remote._tcp]` + `NSCameraUsageDescription` (§6.5, D-13 — the array-typed key cannot ride the flat `INFOPLIST_KEY_` mechanism). |
| `.github/workflows/apple.yml` | +`sudo xcodebuild -downloadPlatform iOS` + an iOS-Simulator build step for the `iHymns` scheme (the PR-5/#1504 tvOS precedent, byte-for-byte pattern) — PR-7 introduces the first `#if os(iOS)`-only UI in IHFeatures and the current macOS-only `iHymns` build would leave it unverified. |

**No edits to:** `Package.swift` (no new dependency, no new target), `IHRPMessage/IHRPFrame/IHRPPayloads` (the protocol is complete for this PR), `TVListenerActor*` / `TVRemoteControlCoordinator` / the tvOS views (the TV side is done), `LANRemotePairingProof/Payload/Ceremony` (frozen by PR-6's golden vector), `KeychainTokenStore` (the account token's sync stance is correct and untouched).

---

## 3. The pure state machines + the session actor (BINDING shapes — do not improvise)

Everything time-driven is a pure, injected-input machine (the `LiveFollowEngine.isFresh(lastUpdatedAt:now:)` seam, `LiveFollowEngine.swift:67`, and PR-6's `LANRemotePairingCeremonyState` precedent): no clock reads, no RNG, no I/O inside any of §3.1–§3.3. The ONLY place that sleeps is the session actor's driver loops (§3.4), and every interval it sleeps for comes from an injectable `Configuration` so the §7.5 loopback tests run the whole ladder in tens of milliseconds.

### 3.1 `RemotePairingFlowState` (pure reducer — the ceremony's client half)

```swift
public struct RemotePairingFlowState: Sendable, Equatable {
    /// Everything the flow needs up front. `knownCode` non-nil = path A
    /// (ceremony QR): the challenge is answered WITHOUT prompting.
    public struct Context: Sendable, Equatable {
        public var expectedFingerprint: String   // the fingerprint WE pinned — the proof binds to it
        public var knownCode: String?
        public var deviceName: String?
        public init(expectedFingerprint: String, knownCode: String? = nil, deviceName: String? = nil)
    }
    public enum Phase: Sendable, Equatable {
        case awaitingChallenge                       // connected, hello sent, nothing back yet
        case awaitingCode(nonce: String, failedAttempts: Int)   // challenge in hand, need the human
        case proofSent(nonce: String, failedAttempts: Int)
        case paired(token: String)
        case failed(RemotePairingFlowFailure)        // terminal for THIS connection
    }
    public enum Event: Sendable, Equatable {
        case challengeReceived(nonce: String)
        case codeEntered(String)                     // path B: the user typed it
        case pairSuccess(token: String)
        case pairingRejected                         // .error(.pairingRejected) arrived
        case transportClosed                         // actor phase fell to .idle/.failed mid-ceremony
    }
    /// What the DRIVER must now do — computing the proof is pure
    /// (`LANRemotePairingProof.compute` is deterministic), so it happens
    /// INSIDE the reducer and the effect carries the finished proof.
    public enum Effect: Sendable, Equatable {
        case none
        case sendProof(proof: String, deviceName: String?)
        case promptForCode(failedAttempts: Int)      // surface the code sheet (0 = first ask)
        case reportPaired(token: String)
        case reportFailed(RemotePairingFlowFailure)
    }
    public enum RemotePairingFlowFailure: Sendable, Equatable {
        case connectionTornDown      // TV hit its 3-attempt cap (or dropped) — "ask the operator for a fresh code"
        case cancelled
    }
    public private(set) var phase: Phase
    public let context: Context
    public init(context: Context)
    public mutating func handle(_ event: Event) -> Effect
}
```
Transition rules (each an explicit reducer branch + a §7.1 test):
- `awaitingChallenge` + `challengeReceived(nonce)` → `knownCode != nil` ? (`proofSent`, effect `.sendProof(compute(knownCode, fp, nonce))`) : (`awaitingCode(nonce, 0)`, effect `.promptForCode(0)`).
- `awaitingCode` + `codeEntered(code)` → `proofSent` (same `failedAttempts`), effect `.sendProof(compute(code, fp, nonce))`. The typed code is held ONLY long enough to compute — never stored on the state (Equatable dumps in test logs must never contain a live code).
- `proofSent` + `pairSuccess(token)` → `paired`, effect `.reportPaired(token)`.
- `proofSent` + `pairingRejected` → `awaitingCode(nonce, failedAttempts + 1)`, effect `.promptForCode(n)` — **including when `knownCode` was set** (a scanned code that expired between scan and confirm degrades gracefully into typing the freshly rotated one; the stale `knownCode` is discarded after its one shot).
- Any non-terminal phase + `transportClosed` → `failed(.connectionTornDown)`, effect `.reportFailed` (covers the TV's 3-attempt teardown — `TVListenerActor+Pairing.swift:148-154` — and any mid-ceremony drop).
- Events that don't apply in the current phase → `.none` (e.g. a duplicate `pairingRejected` after teardown) — never a crash, never an assert.

### 3.2 `RemoteLinkHealthState` (pure — the "5 s ping / 3 miss" machine, in `RemoteLinkPolicy.swift`)

```swift
public struct RemoteLinkHealthState: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        /// Strategy §2.4.4's "`ping`(5s)" — the DRIVER sleeps this long between ticks.
        public var pingInterval: Duration = .seconds(5)
        /// Ticks that may elapse with no pong before the link is declared dead.
        public var maxMissedPongs: Int = 3
        public init()
    }
    public private(set) var pingsAwaitingPong: Int = 0
    public let configuration: Configuration
    public enum TickOutcome: Sendable, Equatable { case sendPing, declareDetached }
    /// One timer tick: if `pingsAwaitingPong >= maxMissedPongs` → `.declareDetached`
    /// (3 pings went unanswered across ~15 s); else increment and `.sendPing`.
    public mutating func onTick() -> TickOutcome
    public mutating func onPong()      // reset to 0 — ANY pong proves liveness
    public mutating func reset()       // fresh connection
}
```
Deliberately **tick-counted, not Date-based** — the machine needs no clock at all (the driver's sleep cadence IS the time source), which makes §7.2's tests trivial and removes a whole class of wall-clock flake. Worst-case detach latency = `pingInterval × (maxMissedPongs + 1)` ≈ 20 s; a hard transport error (send throws / receive error → actor `.idle`) detaches IMMEDIATELY via the phase stream, so the ping ladder is the SLOW detector for silent half-open links (Wi-Fi power-save, AP roam), not the only one.

### 3.3 `RemoteReconnectBackoffState` (pure — same file)

```swift
public struct RemoteReconnectBackoffState: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        public var baseDelay: TimeInterval = 1
        public var multiplier: Double = 2
        public var maxDelay: TimeInterval = 30
        public var jitterFraction: Double = 0.2      // ±20%
        public init()
    }
    public private(set) var attempt: Int = 0
    public let configuration: Configuration
    /// delay = min(maxDelay, baseDelay × multiplier^attempt) × (1 − j + 2j·unitRandom),
    /// then attempt += 1. `unitRandom` ∈ [0,1) injected (production: one
    /// `Double.random(in: 0..<1)` at the call site; tests: literals).
    public mutating func nextDelay(unitRandom: Double) -> TimeInterval
    public mutating func reset()
}
```
Sequence (unitRandom 0.5 ⇒ jitter ≈ ×1.0): ~1, 2, 4, 8, 16, 30, 30, … **Why jitter is load-bearing, not polish:** multi-remote is a first-class mode (strategy §2.4.4 last-writer-wins) — when the venue TV app relaunches, every paired remote detects detach on the same ~5 s cadence and would otherwise dial back in lockstep against PR-4's 4-slot unpaired cap; ±20% decorrelates the herd. Attempts are UNBOUNDED while the remote screen is open and the target is a PAIRED TV (the operator's real-world want: "it comes back by itself when the TV does") — but the ladder runs ONLY while the surface is visible and foregrounded (§3.4 suspend), so it can never become a background battery drain.

### 3.4 `RemoteControlSession` (actor — the driver; core + `+Link.swift`)

The remote-side mirror of the TV's composition split: transport stays in `RemoteSessionActor` (untouched), supervision lives HERE (an `IHLive` actor, NOT the `@MainActor` coordinator), because (a) the §7.5 loopback suite must drive the whole ladder headlessly without pumping a MainActor, (b) PR-11's Watch relay reuses exactly this supervision on the iPhone with no UI attached, (c) the coordinator would blow the LOC budget, and (d) `RemoteSessionActor`'s own header assigns reconnect/replay policy OUTSIDE the transport actor. (Decision D-2.)

```swift
public actor RemoteControlSession {
    public struct Configuration: Sendable {
        public var remoteKind: IHRPRemoteKind
        public var deviceName: String?
        public var health: RemoteLinkHealthState.Configuration = .init()
        public var backoff: RemoteReconnectBackoffState.Configuration = .init()
        public var clock: any LANRemoteClock = SystemLANRemoteClock()
        public init(remoteKind: IHRPRemoteKind, deviceName: String? = nil)
    }
    /// Everything needed to (re)connect to ONE TV — produced by
    /// `RemotePairingEntryResolver` (§4), never assembled ad-hoc in a view.
    public struct Target: Sendable {
        public var tvName: String
        public var endpoint: NWEndpoint            // primary (discovered service OR QR host:port)
        public var fallbackEndpoint: NWEndpoint?   // saved last-good host:port when primary is a Bonjour service
        public var expectedFingerprint: String     // ALWAYS present — no TOFU (PR-8)
        public var token: String?                  // nil ⇒ ceremony; non-nil ⇒ fast path + auto-reconnect eligibility
        public var knownCode: String?              // path A only
    }
    public enum Event: Sendable, Equatable {
        case connecting(attempt: Int)                      // 0 = first connect, ≥1 = ladder
        case awaitingCodeEntry(failedAttempts: Int)        // show/refresh the code sheet
        case pairingEnded(RemotePairingFlowState.RemotePairingFlowFailure)
        case paired(token: String, resolved: LANRemoteResolvedAddress?)   // coordinator persists (§5)
        case controlling(IHRPState)                        // every TV state broadcast lands here
        case detached                                      // 3-miss or transport drop on a PAIRED link
        case reconnecting(attempt: Int, delay: TimeInterval)
        case suspended                                     // scenePhase left .active
        case ended                                         // stop()/endControl() completed
    }
    public nonisolated let events: AsyncStream<Event>      // the ONE stream the UI consumes
    public init(configuration: Configuration)
    public func startDiscovery() async -> AsyncStream<[LANRemoteDiscoveredService]>   // pass-through
    public func stopDiscovery() async
    public func attach(to target: Target) async            // begin connect (+ceremony or fast path)
    public func submitPairingCode(_ code: String) async    // path B's sheet
    public func cancelPairing() async                      // sheet dismissed → disconnect, .pairingEnded(.cancelled)
    public func sendIntent(_ message: IHRPMessage) async   // control surface's one door (asserts isControlIntent)
    public func endControl() async                         // deliberate: try? send(.endControl) → disconnect → .ended
    public func setSuspended(_ suspended: Bool) async      // scenePhase: true = endControl-style quiesce, keep target;
                                                           // false = immediate token reconnect (fast path <1 s)
    public func stop() async                               // full teardown: cancel loops, disconnect, finish nothing else
}
```
`+Link.swift` internals (the only sleeping code in this PR):
1. **One consumption loop** owns BOTH actor streams via a `TaskGroup` (`phaseUpdates` + `incomingMessages`) — the §1.2 single-consumer rule, enforced structurally: the two `for await`s exist exactly once, here. `incomingMessages` routing: `.pairChallenge` → flow reducer; `.pairSuccess` → reducer (token also mirrored by the actor's `currentPairingToken`); `.error(.pairingRejected)` → reducer; `.pong` → `health.onPong()`; `.state`/`.capabilities` → yield `.controlling` + revision-jump `.notice` when `revision > last + 1` after a reconnect (`IHRPPayloads.swift:86-88`'s intended consumer). `phaseUpdates`: `.idle`/`.failed` while attached → detach path; `.controlling` first arrival → `backoff.reset()` + start ping ticker.
2. **Ping ticker**: `while attached { try await Task.sleep(for: health.configuration.pingInterval); switch health.onTick() { case .sendPing: try? await remote.send(.ping) — a THROW here (.notConnected) short-circuits to detach; case .declareDetached: → detach } }`.
3. **Detach path**: only meaningful for a paired target (`token != nil`): `remote.disconnect()`, yield `.detached`, then the ladder — `let delay = backoff.nextDelay(unitRandom: .random(in: 0..<1)); yield .reconnecting(attempt:delay:); try await Task.sleep(for: .seconds(delay)); connect(endpoints[attempt % endpoints.count], token: savedToken)` where `endpoints = [primary, fallback].compactMap{...}` — attempts ALTERNATE primary/last-good so a TV that lost its Bonjour registration (or a remote that roamed onto the VPN, strategy §2.4.5) still comes back. An UNPAIRED (mid-ceremony) drop never auto-retries — the user is standing at the TV; surface `.pairingEnded(.connectionTornDown)` instead.
4. **Suspend** (`setSuspended(true)`): cancel ticker + ladder, `try? send(.endControl)` (the deliberate-hand-off log on the TV, `IHRPMessage.swift:112-115`), disconnect, yield `.suspended`, KEEP the target. Resume: straight to a token reconnect. This is strategy §2.4.4's "backgrounded remote = detached, TV holds state, <1 s reconnect" made literal.

**Instrumentation contract (binding, PR-4's):** transitions-not-states — `lanremote.link attach`, `lanremote.link detached missed-pongs=N`, `lanremote.link reconnect attempt=N`, `lanremote.link suspended/resumed`, `lanremote.pairflow prompt/proof-sent/paired/rejected count=N` — `caseName`s and counts ONLY; the code/proof/token/nonce NEVER appear in any `IHLog` interpolation at any level; TV names and hosts log `.private` (the `RemoteSessionActor.swift:212` convention); the fingerprint (public by design) may log `.public`.

---

## 4. The entry-path matrix (the subtlest part of this PR — resolve it EXACTLY this way)

**The invariant behind the whole table: `expectedFingerprint` must be in hand BEFORE `connect(...)` is called, and it comes from exactly two sources — a scanned/pasted `LANRemotePairingPayload`, or a saved `PairedTVRecord`. Bonjour discovery NEVER supplies trust — a discovered result is name + endpoint only (§1.2) — and name-matching a discovered service to a saved record is ROUTING ONLY (picking which endpoint to dial), never a trust decision: an impostor advertising a saved TV's name simply fails the TLS pin (`+Connection.swift:59-74`) because we always pin the SAVED record's fingerprint.**

| Path | User action | Fingerprint from | Endpoint from | Code | Flow |
|---|---|---|---|---|---|
| **A — ceremony QR** (the PRIMARY new-pair path) | Scans the TV's pairing-overlay QR (iOS/iPadOS camera; any platform via B′ paste) | `payload.fingerprintHex` | `payload.host:port`; if `host == nil` → a discovered service whose name == `payload.name`; if neither → error card (below) | `payload.code` — auto-answered | connect → challenge → auto `.sendProof` → `.pairSuccess` → persist → controlling. Zero typing. Expired-in-hand code degrades to path B's sheet (§3.1). |
| **B — standing QR + typed code** | Scans/pastes the Settings standing QR, operator opens pairing on the TV, user types the 6-digit code | `payload.fingerprintHex` | as A | typed when `.pairChallenge` arrives (`awaitingCodeEntry`) | connect → challenge → sheet → `.sendProof` → success/retype (≤3/connection, then TV tears down → "ask the operator to re-open pairing"). |
| **B′ — pasted payload string** (macOS + visionOS's ONLY new-pair path; also iOS fallback) | Pastes the `ihymns-lanpair:v1:…` string (AirDropped/Messaged/typed from the TV screen) into `PairingPayloadEntrySheet` | same as A/B — the string IS the QR payload | as A | per payload (`code` present ⇒ A semantics, absent ⇒ B) | identical to A/B from `LANRemotePairingPayload(qrString:)` onward. **NOT PR-8's manual connect: the fingerprint travels with the string.** A paste that fails `init?(qrString:)` = "Not a valid iHymns pairing code," never a crash, never a host:port fallback prompt. |
| **C — discovery/saved list row (paired TV)** | Taps a pinned saved TV | the SAVED record's pinned fingerprint | name-matched discovered endpoint if present, else saved last-good `host:port` (both wired as primary/fallback into `Target`) | none | `connect(token:)` → fast path → controlling <1 s. |
| **C′ — discovery row (unpaired nearby TV)** | Taps a nearby TV that is NOT in the store | **NONE — connection is impossible by design** | — | — | The row is informational: "To pair, scan the QR code shown on this TV" + a button opening path A/B′. Wiring ANY connect here (or a "trust on first use" shortcut) is the PR-8 boundary violation — reviewer reject. |

`RemotePairingEntryResolver` (IHLive, pure — §7.2-tested) encodes the table:
```swift
public enum RemotePairingEntryResolver {
    public enum Entry: Sendable { case payload(LANRemotePairingPayload), savedRow(PairedTVRecord) }
    public enum Resolution: Sendable {
        case connect(RemoteControlSession.Target)
        case unpairable(reason: UnpairableReason)   // .noRouteToTV (payload host nil + no name match)
    }
    public static func resolve(_ entry: Entry, saved: [PairedTVRecord],
                               discovered: [LANRemoteDiscoveredService]) -> Resolution
    /// The list model: saved records first (pinned, `isNearby` flagged when a
    /// discovered name matches), then unpaired nearby services. Sorting: saved
    /// by lastConnectedAt desc; nearby by name.
    public static func listRows(saved: [PairedTVRecord],
                                discovered: [LANRemoteDiscoveredService]) -> [RemoteTVListRow]
}
```
Extra resolver rules (all table-driven-tested): a scanned payload whose fingerprint matches an EXISTING saved record = a RE-pair of a known TV → the resulting `.paired` overwrites that record (same account key, §5) and the target still carries the saved token? **No — a ceremony payload means the user intends to (re)pair: `token` stays nil so the ceremony runs; the fresh token replaces the old on success.** A STANDING payload (`code == nil`) whose fingerprint matches a saved record short-circuits to path C (we already trust this TV — connect with the saved token instead of making the user type a code pointlessly); only if that token is later rejected (TV revoked us → `.pairChallenge` arrives instead of `.capabilities`) does the flow drop into the code sheet — which the §1.1 contract gives us for free, because a stale-token `hello` lands in `.pairing` on the TV side.

### 4.3 Camera + permission handling (path A's iOS half)

- `PairingScanView` (`#if os(iOS)`): `AVCaptureSession` + `AVCaptureMetadataOutput` with `metadataObjectTypes = [.qr]`, an `AVCaptureVideoPreviewLayer` inside a `UIViewRepresentable`, torch toggle omitted (scope). First matching string that `LANRemotePairingPayload(qrString:)` accepts wins; non-matching QR codes are ignored silently (people WILL scan the venue Wi-Fi poster by mistake). Session started/stopped with view appear/disappear on a utility queue (never the main thread — Apple's AVCapture guidance).
- Permission: `AVCaptureDevice.authorizationStatus(for: .video)` — `.notDetermined` → `requestAccess` on the Scan button tap (never on screen appear); `.denied/.restricted` → inline guidance card ("Allow camera access in Settings, or paste the pairing code instead") + the paste field. Camera denial NEVER dead-ends pairing — B′ is always on screen. (Decision D-12.)
- **Platform truth (Decision D-12):** visionOS gets NO camera scanning — `AVCaptureDevice` main-camera access is unavailable to third-party visionOS apps (enterprise-entitlement only), so pretending otherwise would ship a dead button. macOS gets none either (a Mac pointed at a TV is not a real ergonomic, and it avoids the sandbox camera entitlement entirely). Both use B′/C. iPadOS = iOS (`#if os(iOS)` covers it). If a TV simply cannot be paired from a Mac because no payload string can be conveyed to it, that TV waits for PR-8 — say so in the empty-state copy honestly.
- **Local Network primer (strategy §2.4.5 "permission prompt explained pre-fire"):** the FIRST time `RemoteControlView` appears with `!IHSettingsStore().hasSeenLocalNetworkPrimer`, show an explainer card ("iHymns will ask to find devices on your local network — that's how it finds your TV") whose Continue button sets the flag AND starts discovery (the OS prompt then fires on the first `NWBrowser` start). If discovery later reports permission-denied (`isLocalNetworkPermissionDenied`, §1.2), swap the list for a guidance card (Settings → Privacy → Local Network). Note in code: the Simulator does not enforce this prompt (plan §5) — device verification only.

---

## 5. Persistence — the remote-side paired-TV Keychain store

### 5.1 `PairedTVRecord` + `PairedTVStoring` (+ `InMemoryPairedTVStore`) — `PairedTVStore.swift`

```swift
public struct PairedTVRecord: Sendable, Codable, Equatable, Identifiable {
    public let fingerprintHex: String          // the TV's STABLE identity — the natural PK (id)
    public var name: String                    // cosmetic, from payload.name / advertised name
    public var token: String                   // the RAW pairing token — see custody note below
    public var lastAddress: LANRemoteResolvedAddress?   // last-good host:port (PR-8's future seed too)
    public var pairedAt: Date
    public var lastConnectedAt: Date?
    public var id: String { fingerprintHex }
}
public protocol PairedTVStoring: Sendable {
    func save(_ record: PairedTVRecord) async            // upsert by fingerprint
    func record(forFingerprint: String) async -> PairedTVRecord?
    func listPairedTVs() async -> [PairedTVRecord]       // lastConnectedAt ?? pairedAt, newest first
    func delete(fingerprint: String) async               // "Forget this TV"
}
public actor InMemoryPairedTVStore: PairedTVStoring { ... }   // tests/previews — the InMemoryLANRemotePairingAuthority precedent
```
**Why the fingerprint is the key, not the token:** the token ROTATES (every re-pair mints a fresh one — `LANRemotePairingSecrets.mintToken()` per ceremony) while the fingerprint is the TV's persistent identity (`LANRemoteIdentityStore`, PR-6 §5.1's whole point); keying by fingerprint makes re-pairing an UPSERT instead of a duplicate row, and lookup-by-fingerprint IS the reconnect path. **Why the RAW token (unlike the TV's sha256-only rule):** the remote must present the preimage in `hello` — asymmetric custody is the DESIGN (strategy §2.4.3: "TV stores `sha256`; remote stores raw + pinned cert"); the raw value lives only inside a Keychain item.

### 5.2 `KeychainPairedTVStore` (actor — the `KeychainLANRemotePairingAuthority` idiom, exact `SecItem` shape)

One `kSecClassGenericPassword` item per paired TV:
- `kSecAttrService = "app.ihymns.lanremote.pairedTVs"` (a NEW private namespace beside `app.ihymns.lanremote.pairedRemotes` — never shared with it).
- `kSecAttrAccount = fingerprintHex` — the record key.
- `kSecValueData = try JSONEncoder().encode(record)` (dates via `.iso8601` for dump-stability; the record INCLUDES the raw token — one item, one query surface, no metadata sidecar to desync: PR-6 Decision D-6 applied here).
- **`kSecAttrSynchronizable = false` — EXPLICIT, HARD-CODED, never injectable** (the `KeychainLANRemotePairingAuthority.swift:211-226` posture, NOT `KeychainTokenStore`'s injectable one): iCloud-syncing this item would propagate sanctuary-screen control to every device on the Apple ID and silently break the physical-proximity trust ceremony (plan §4/§6.4's named tripwire). Unlike the TV, remotes RUN on platforms that HAVE iCloud Keychain — this hard-coded `false` is the single most load-bearing character in the file. §7.4 asserts it on `baseAttributes`; the §8 grep gate re-checks it at review.
- `kSecAttrAccessible = kSecAttrAccessibleAfterFirstUnlock` (reconnect after reboot; the established precedent).
- No `kSecAttrAccessGroup` (nothing shares these items — widgets/watch never drive the TV directly; PR-11 relays through the iPhone app itself).
- `save` = delete-then-add replace; add failure → `IHLog.remote.fault("lanremote.tvstore persist-failed status=…")` (status only) — the live session keeps working, the pairing just won't survive relaunch.
- Reads: `errSecItemNotFound` ⇒ nil/[]; any other status ⇒ nil/[] + `.error` log (fail-closed: a TV we can't prove we trust is a TV we don't list).
- `internal func baseAttributes(account: String?) -> [String: Any]` — the §7.4 `@testable` assertion hook, mirroring the authority.

Persistence timing (coordinator, §6.2): on `.paired(token:resolved:)` → upsert `{fingerprint, name, token, lastAddress: resolved ?? payload host:port, pairedAt: now, lastConnectedAt: now}`; on each `.controlling` first-arrival after an `attach`/reconnect → update `lastConnectedAt` + `lastAddress` from `currentResolvedRemoteAddress` (the §2 actor edit). "Forget this TV" → `delete(fingerprint:)` + copy that is honest about the other half: "This TV will still list this device as trusted until you revoke it in the TV's Settings."

---

## 6. UI structure — coordinator, views, per-platform matrix, project.yml

### 6.1 Placement: the `.live` section becomes real (Decision D-6)

`RootContainerView`'s `.live` case (both `tabbedRoot` and `splitView`) now hosts **`LiveHubView(rootViewModel:)`**: a "TV Remote" navigation card (→ `RemoteControlView`) above a retained, honestly-labelled "Live Follow & Service Mode — coming in a future update" block (the exact `ContentUnavailableView` copy moving in from `RootContainerView.swift:388-394`, which is deleted there). Why here and not Settings or an 8th section: a control surface is a DESTINATION, not a preference (Settings rows push preference-shaped screens — `SettingsView.swift`'s own "one row, real content on the pushed screen" pattern is about settings content); `RootSection` deliberately caps at 7 tabs (`AppNavigationState.swift:76-80`); and `.live` is this feature family's designed home — PR-10's server clients land beside the remote later, making the hub honest twice over. The `.live` placeholder's "don't fake it" header rule is SATISFIED, not violated: the remote is real, the server half stays explicitly labelled future. tvOS/watchOS shells are untouched (`RootContainerView`'s `#else` branch never renders sections; the TV has `TVRootView`; the Watch is PR-11).

### 6.2 `RemoteControlCoordinator` (IHFeatures, `@MainActor @Observable` — the `TVRemoteControlCoordinator` mirror)

```swift
@MainActor @Observable
public final class RemoteControlCoordinator {
    public enum UIPhase: Equatable {
        case browsing                                    // list; not connected
        case connecting(tvName: String, attempt: Int)
        case codeEntry(tvName: String, failedAttempts: Int)
        case controlling(tvName: String, state: IHRPState)
        case reconnecting(tvName: String, attempt: Int)
        case suspended(tvName: String)
    }
    public private(set) var uiPhase: UIPhase = .browsing
    public private(set) var rows: [RemoteTVListRow] = []       // resolver-built (§4)
    public private(set) var notice: String?                    // inline themed error/guidance copy
    public private(set) var localNetworkDenied = false
    public init(store: any PairedTVStoring = KeychainPairedTVStore(),
                sessionConfiguration: RemoteControlSession.Configuration =
                    .init(remoteKind: RemoteDeviceIdentity.kind, deviceName: RemoteDeviceIdentity.name))
    public func start() async            // idempotent: load saved TVs, start discovery (post-primer), spawn the ONE events-consumer task
    public func stop() async             // session.stop() + stopDiscovery + cancel tasks (view teardown)
    public func handleScannedOrPasted(_ string: String)   // → payload init? → resolver → attach / notice
    public func connect(row: RemoteTVListRow)             // path C / C′ routing per the resolver
    public func submitCode(_ code: String) async
    public func disconnect() async                        // user's explicit button → session.endControl()
    public func forget(_ record: PairedTVRecord) async
    public func setScenePhaseActive(_ active: Bool) async  // → session.setSuspended(!active)
    public func sendIntent(_ message: IHRPMessage) async   // the surface's pass-through
    /// PURE (static) event→UI mapping — `uiPhase(after: event, current: phase, tvName:)`
    /// — the §7.3 always-on test target; the events-consumer task is a thin
    /// `for await` that applies it + performs the §5.2 persistence side-effects.
}
```
Injectable `store`/`sessionConfiguration` defaults keep production call sites one-line while §7.3's tests hand in `InMemoryPairedTVStore` + a never-started session config. The coordinator consumes ONLY `session.events` + the discovery stream (never the actor's streams — §1.2 rule). Discovery results also refresh `rows` via `RemotePairingEntryResolver.listRows`.

### 6.3 The control surface + song picker

**`RemoteControlView`** (scaffold): owns `@State private var coordinator` (built in `init` — the `TVRootView.swift:54-61` pattern), `.task { await coordinator.start() }`, `.onDisappear { Task { await coordinator.stop() } }`, `@Environment(\.scenePhase)` → `setScenePhaseActive`. Renders by `uiPhase`: `browsing` → primer card OR the list (pinned saved rows: name, kind glyph `tv`, "Nearby" badge when `isNearby`, relative last-connected; swipe-delete = Forget with the §5.2 copy; nearby-unpaired rows per C′) + toolbar buttons "Scan" (`#if os(iOS)`) and "Enter Code" (all platforms → `PairingPayloadEntrySheet`); `connecting/reconnecting` → the list with an overlaid progress banner + Cancel; `codeEntry` → `PairingCodeEntrySheet` presented; `controlling` → `RemoteControlSurfaceView`; `suspended` → a "reconnecting when you return" placeholder. Empty state (no saved, none nearby): "Open iHymns on your Apple TV, then scan the QR code in its Settings → Remote Control." with the macOS/visionOS-specific extra line "…or paste the pairing code from the TV screen."

**`RemoteControlSurfaceView`** (the actual remote — STATELESS/REFLECTIVE, Decision D-7): every control renders from the `IHRPState` in `uiPhase.controlling` and every interaction ONLY calls `coordinator.sendIntent(...)`; nothing is optimistically toggled locally. The TV is the single display authority (strategy §2.4.1) and its `.state` broadcast — which includes echoes of our own intents (§1.1) — is the ONLY thing that updates the UI. This makes multi-remote last-writer-wins correct BY CONSTRUCTION: when another remote changes the display, our surface just re-renders the broadcast; there is no local shadow state to fight over, and the sub-100 ms echo latency makes the round-trip invisible. Layout (one scrolling column, `.ihGlassCard()` groupings, all controls ≥44 pt, VoiceOver labels on every glyph):
1. **Header** — TV name, live dot, current song title line ("Nothing selected" for `songId == nil`), Disconnect button.
2. **Song** — "Choose Song…" (→ `RemoteSongPickerView` sheet) + prev/next COMPONENT buttons flanking a "Verse/Component" label (the TV resolves arrangement order — the remote never counts components, `IHRPMessage.swift:89-92`).
3. **Line** — prev/next line + a scroll-nudge pair sending `.scroll(delta: ±1)`.
4. **Display** — a 4-way control for `IHRPDisplayState` (`lyrics/blackout/logo/frozen`), selection = `state.displayState`, tap = `.setDisplayState(...)`.
5. **Appearance** — theme menu (sends `.setAppearance(theme: key, textScale: nil)`) + textScale stepper (`.setAppearance(theme: nil, textScale: value)`) — fire-and-forget hints, no local persistence (they're TV-side cosmetics).
**Content INVARIANT (restate in the file header):** every send is a ~200-byte intent; no lyric text ever crosses this seam in either direction the UI can observe beyond `IHRPState` indices (`IHRPMessage.swift:15-25`).

**`RemoteSongPickerView`**: a sheet with a search field + list of `SongSummary` rows filtered by the EXTRACTED shared matcher `AppRootViewModel.songsMatching(_:in:)` over `rootViewModel`'s already-loaded catalogue (`catalogueLoadState`) — its OWN `@State query`, deliberately NOT `rootViewModel.searchText` (mutating that would clobber the Search tab's live state; the extraction exists precisely so both callers share the number-then-substring logic — `AppRootViewModel+Search.swift:40-64` — without sharing UI state). Row tap → `sendIntent(.selectSong(songId:, componentIndex: nil, lineIndex: nil))` + dismiss. Row context menu "Prepare on TV" → `sendIntent(.prepare(songId:))` and stays open — the real worship flow ("prefetch the next hymn during the current verse", strategy §2.4.4's RTT-hiding intent; sending prepare+select on the same tap would hide nothing, Decision D-9). If the catalogue isn't loaded yet, the sheet triggers the same `loadCatalogueIfNeeded()` the Search tab uses.

### 6.4 `RemoteDeviceIdentity` (IHFeatures)

`static var kind: IHRPRemoteKind` — `#if os(iOS)`: `UIDevice.current.userInterfaceIdiom == .pad ? .pad : .phone`; `#elseif os(visionOS)`: `.vision`; `#elseif os(macOS)`: `.mac`; `#elseif os(watchOS)`: `.watchRelay` (defensive — PR-11's future caller); `#else` (tvOS compile-only): `.phone` (dead code, the package compiles everywhere). `static var name: String?` — `UIDevice.current.name` where UIKit exists (NOTE in the comment: iOS 16+ returns the generic model name, not the user's personalised one, without a special entitlement — fine, it's cosmetic), `Host.current().localizedName` on macOS, `WKInterfaceDevice.current().name` on watchOS.

### 6.5 `project.yml` — the browse-side keys (Decision D-13) + CI

Add to the `iHymns` target (keep ALL existing `settings.base` keys byte-untouched):
```yaml
    # #1422 (PR-7 spec §6.5, D-13) — the LOAD-BEARING Local Network keys (TN3179:
    # BROWSING is the restricted operation; the tvOS listener side's copy of
    # NSLocalNetworkUsageDescription is belt-and-braces, THIS one is not).
    # NSBonjourServices is array-typed with no INFOPLIST_KEY_ build-setting
    # equivalent, so it rides XcodeGen's `info:` mechanism (the iHymnsWidgets
    # precedent) — Xcode merges the INFOPLIST_KEY_* settings above into this
    # generated file's content at build time, so the existing flat keys keep
    # working unchanged.
    info:
      path: Apps/iHymns/Generated/Info.plist
      properties:
        NSLocalNetworkUsageDescription: "iHymns looks for iHymns on your Apple TV over your local network so this device can act as its remote control."
        NSBonjourServices:
          - _ihymns-remote._tcp
        NSCameraUsageDescription: "iHymns uses the camera only to scan the pairing QR code shown on your TV."
```
**Builder MUST verify the merge** (this is the one toolchain-behaviour bet in this PR): after `xcodegen generate` + an iOS-Simulator build, `plutil -p` the built product's `Info.plist` and confirm it contains BOTH the new keys AND the pre-existing `INFOPLIST_KEY_`-sourced ones (`CFBundleDisplayName`, `UIBackgroundModes`, `ITSAppUsesNonExemptEncryption`). **Documented fallback if any key is missing:** migrate every `INFOPLIST_KEY_*` value on this target into the `info:` block as real plist keys (`UILaunchScreen: {}` for the launch-screen flag) and set `GENERATE_INFOPLIST_FILE: NO` — the exact `iHymnsWidgets` configuration (`project.yml:286-307`). Either way, the three new keys shipping is the acceptance criterion; which mechanism carries them is not. `NSCameraUsageDescription` on the macOS/visionOS destinations of this multiplatform target is inert (no code path requests the camera there — §4.3). No entitlements change (macOS App Sandbox is not currently enabled on this target; when the strategy §3.1 sandbox lands later, `com.apple.security.network.client` becomes its concern, noted, out of scope).

`apple.yml`: after the existing macOS build step, add `sudo xcodebuild -downloadPlatform iOS` + an `xcodebuild -project iHymns.xcodeproj -scheme iHymns -destination 'generic/platform=iOS Simulator' CODE_SIGNING_ALLOWED=NO build` step — copy the tvOS step's comment style + #1504 citation, adding #1422.

---

## 7. Test plan (Swift Testing; injected config everywhere; secrets never printed in test output either)

### 7.1 `RemotePairingFlowStateTests` (always-on, pure)
- Path A: `challengeReceived` with `knownCode` ⇒ `.sendProof` whose proof EQUALS `LANRemotePairingProof.compute` for the same inputs; **golden-vector cross-check** — feed PR-6 §7.1's frozen (code, fingerprint, nonce) triple through the reducer and assert the effect carries the frozen proof hex verbatim ("if this fails you changed the client half of a frozen construction").
- Path B: challenge without code ⇒ `.promptForCode(0)`; `codeEntered` ⇒ `.sendProof`; `pairingRejected` ⇒ `.promptForCode(1)` and the nonce is retained; three reject/re-enter cycles keep counting.
- Expired-scanned-code degrade: `knownCode` + `pairingRejected` after its auto-proof ⇒ `.promptForCode(1)` (the stale code is NOT re-sent).
- `pairSuccess` ⇒ `.reportPaired(token)`; `transportClosed` from every non-terminal phase ⇒ `.reportFailed(.connectionTornDown)`; out-of-phase events ⇒ `.none`; `Phase`'s `Equatable` dump never contains a typed code (structural: the code isn't stored — assert `awaitingCode` only carries nonce+count).

### 7.2 `RemoteLinkPolicyTests` + `RemotePairingEntryResolverTests` + `PairedTVStoreTests` (always-on, pure)
- Health: fresh state → 3 × `onTick()` ⇒ `.sendPing` each, `pingsAwaitingPong == 3`; 4th tick ⇒ `.declareDetached`; `onPong()` after 2 ticks resets to 0 and the ladder restarts; defaults asserted literally (`.seconds(5)` / 3) so a drive-by tune shows in review.
- Backoff: with `unitRandom: 0.5` the sequence is 1, 2, 4, 8, 16, 30, 30; with 0.0/1.0 every delay stays within ±20% of nominal; `reset()` returns to attempt 0; defaults asserted literally (1 / 2 / 30 / 0.2).
- Resolver: one table-driven case per §4 row incl. — ceremony payload for an ALREADY-saved fingerprint ⇒ token nil (re-pair); standing payload for a saved fingerprint ⇒ path-C target with the saved token; payload with nil host + name-matching discovery ⇒ discovered endpoint primary; payload nil host + no match ⇒ `.unpairable(.noRouteToTV)`; saved row with a name-matched discovery ⇒ discovered primary + saved-address fallback; saved row alone ⇒ saved-address primary, no fallback; `listRows` pins saved first, flags `isNearby`, sorts as specified.
- Store: InMemory upsert-by-fingerprint (a re-save replaces, count stays 1), list order (lastConnectedAt desc, nil sorts by pairedAt), delete; `PairedTVRecord` JSON round-trip with `.iso8601` dates.

### 7.3 `IHFeaturesTests` (always-on, `@MainActor`)
- `RemoteControlUIStateTests`: the pure `uiPhase(after:current:tvName:)` mapping — every `RemoteControlSession.Event` from every current phase (e.g. `.detached` while `.controlling` ⇒ `.reconnecting`… as specified by the mapping's own doc table; `.suspended`/resume; `.pairingEnded(.cancelled)` ⇒ `.browsing` with no notice, `.connectionTornDown` ⇒ `.browsing` + operator-guidance notice).
- `SongsMatchingQueryTests`: `songsMatching(_:in:)` reproduces `filteredSongs`' documented behaviour on fixture summaries — number-mode hit ("MP 1008"), number-mode miss falling through to substring ("Song 23" → title match), plain substring, empty query returns input unchanged; and (regression guard for the extraction) `AppRootViewModel.filteredSongs` still passes its existing expectations via the current `AppRootViewModelTests`/`CatalogueNumberQueryTests` suites untouched.

### 7.4 `KeychainPairedTVStoreTests` (`.enabled(if: lanRemoteKeychainIdentityAvailable(), …)` — the exact PR-6 gate + skip-message style; every test wraps Keychain work in `KeychainTestSerialization.shared.run { }` and cleans up in `defer`)
- save → `record(forFingerprint:)` returns the record INCLUDING the raw token (the preimage-custody property); upsert replaces; `listPairedTVs` order; `delete` removes; unknown fingerprint ⇒ nil.
- **`baseAttributes(account:)` asserts `kSecAttrSynchronizable == false` and `kSecAttrAccessible == AfterFirstUnlock` and service == `app.ihymns.lanremote.pairedTVs`** — plan §6.4's required unit test, remote side.

### 7.5 `RemoteControlSessionLoopbackTests` (`IHYMNS_LAN_LOOPBACK_TESTS=1` gate, verbatim posture from `TVListenerPairingLoopbackTests.swift:62-68`: `advertiseViaBonjour: false`, `.hostPort` connects, `ManualLANRemoteClock` into the LISTENER, `KeychainTestSerialization` around identity generation). Session config for ALL tests here: `health.pingInterval = .milliseconds(40)`, `backoff = .init(baseDelay: 0.05, maxDelay: 0.2)` — the ladder runs in real milliseconds; the ALWAYS-ON suites own the numeric defaults.
- **Ceremony, path A:** listener `beginPairing()`; session `attach` with `knownCode` ⇒ events yield `.paired(token:resolved:)` then `.controlling`; `resolved` is 127.0.0.1 + the bound port (the §2 actor edit, verified end-to-end); reconnect `attach` with that token ⇒ `.controlling` with NO `.awaitingCodeEntry` ever yielded.
- **Ceremony, path B:** `attach` without code ⇒ `.awaitingCodeEntry(0)`; `submitPairingCode(wrong)` ⇒ `.awaitingCodeEntry(1)` and the connection survives (the `.pairingRejected`-not-`.unauthorized` distinction, client side); `submitPairingCode(correct)` ⇒ `.paired`. Then 3 wrongs on a fresh attach ⇒ TV teardown ⇒ `.pairingEnded(.connectionTornDown)`.
- **The ladder (the headline test):** pair; `await listener.stop()`; expect `.detached` (ping throws/receive error at ms cadence) then ≥1 `.reconnecting(attempt:delay:)`; start a NEW listener on the SAME fixed port (pick a random high port up front and pass it to both `Configuration(port:)` calls so the restart is addressable) with the SAME identity + an authority pre-seeded with the token; expect `.controlling` again with NO ceremony events. Assert attempts observed ≥1 and delays non-decreasing until reset.
- **Suspend/resume:** pair; `setSuspended(true)` ⇒ `.suspended` + the listener sees the connection close; `setSuspended(false)` ⇒ `.controlling` (fast path).
- **Multi-remote reflection:** two sessions paired to one listener; session A `sendIntent(.setDisplayState(.blackout))` ⇒ BOTH sessions yield `.controlling` whose state is `.blackout` with the same bumped revision (last-writer-wins reflection, `TVListenerActor+Messages.swift:201-214` observed from the client).

**No snapshot tests (Decision D-10):** `Package.swift` carries no snapshot-testing dependency and adding one violates this PR's no-new-external-package tripwire; view coverage = compile-everywhere + the §6 walkthrough on device/Simulator. Snapshot infra is tracked as #1527 — note it in the PR body, don't smuggle it in.

**Local pre-PR verification (builder runs ALL — CI is not a required check, #1526):**
```
cd appApple/Packages/iHymnsKit && swift build && swift test
IHYMNS_LAN_LOOPBACK_TESTS=1 swift test --filter 'RemoteControlSessionLoopback|TVListenerPairingLoopback|LANRemoteLoopback'
swiftlint --config appApple/.swiftlint.yml appApple          # 0 violations
bash appApple/Scripts/loc-budget.sh
cd appApple && xcodegen generate
xcodebuild -project iHymns.xcodeproj -scheme iHymns -destination 'generic/platform=iOS Simulator' CODE_SIGNING_ALLOWED=NO build
xcodebuild -project iHymns.xcodeproj -scheme iHymns -destination 'platform=macOS,arch=arm64' CODE_SIGNING_ALLOWED=NO build
# + §6.5's plutil Info.plist merge verification on the built iOS product
```
(The tvOS target is untouched by this PR, but if ANY IHLive/IHFeatures shared file was edited — it was — run the tvOS build too before pushing; the #1532 lesson was exactly a shared-code platform break: `xcodebuild -scheme iHymnsTV -destination 'generic/platform=tvOS Simulator' CODE_SIGNING_ALLOWED=NO build`.)

---

## 8. Threat model (acceptance criteria — PR-6 §8 still applies wholesale; these are the REMOTE-side rows) + Decisions

| Threat | Mitigation (mechanism, THIS PR) | Residual |
|---|---|---|
| **Swapped/forged venue QR** (attacker's payload pairs the phone to the ATTACKER's device) | The pin means the phone talks ONLY to whoever holds the private key for the scanned fingerprint — the attacker pairs the phone to their own box, never gains anything toward the REAL TV (no credential of ours transits: the proof binds to the attacker's fingerprint and is useless elsewhere — PR-6 §3.2). The phone shows the payload's TV name before connecting; the real TV's operator sees no "Paired" banner. | The user drives the attacker's screen until they notice. Physical-space attack, accepted (PR-6 §8 row 1); UI copy nudges name-checking. |
| **Malicious payload string / QR content** (fuzz the parser, absurd fields) | `LANRemotePairingPayload.init?(qrString:)` is nil-on-garbage (PR-6-tested); resolver additionally clamps: displayed name truncated for UI, port 0 ⇒ `.unpairable`, non-64-hex fingerprint ⇒ reject before any connect. The scanner ignores non-`ihymns-lanpair:` codes silently. | None meaningful. |
| **Evil "TV" advertising on the LAN** (rogue Bonjour service, spoofed name of a saved TV) | Discovery is trust-free by construction (§4): an unpaired nearby row CANNOT be connected; a name-spoof of a saved TV gets dialled but fails the SAVED fingerprint's TLS pin at handshake (logged `.fault`). | An attacker can pollute the nearby list cosmetically (LAN-local, no interaction possible). Accepted. |
| **Raw-token theft from the remote** | Keychain generic-password, `AfterFirstUnlock`, **`synchronizable:false` hard-coded + unit-tested + grep-gated** — never UserDefaults, never logs, never crosses iCloud. A stolen token is revocable in one tap on the TV (PR-6's trusted-remotes list + `disconnectPaired`). | Device compromise = the attacker IS the remote (parity with PR-6's identity row). Accepted. |
| **Reconnect storm / self-DoS** (TV restarts, N remotes redial; or a dead TV drains the phone) | ONE supervisor per surface; exponential backoff to 30 s cap; ±20% jitter decorrelates the herd (§3.3); ladder runs only while the screen is open AND foregrounded (`setSuspended` on scenePhase); unpaired ceremonies never auto-retry. | None meaningful (PR-4's unpaired-slot DoS envelope unchanged). |
| **Pasted ceremony code lingering in a pasteboard** | The app NEVER reads the pasteboard programmatically (no `UIPasteboard.general` access — the user pastes into a field themselves, so no iOS paste-prompt surprise and no ambient clipboard sniffing by us). A pasted CEREMONY payload does carry a live code — bounded by the TV's 120 s TTL + single-use + operator visibility (PR-6 §8 row 4); the STANDING payload carries no secret and is the recommended paste artefact (UI copy says so). | Third-party clipboard managers retaining the string — outside our control, TTL-bounded. |
| **Secrets/PII in logs** | Binding contract restated in §3.4: no code/proof/token/nonce in ANY `IHLog` interpolation; TV names + hosts `.private`; fingerprints only `.public` value. Review gate: grep every new file's `IHLog` calls. | — |
| **Multi-remote races** (two operators fight) | Last-writer-wins is the TV's resolution (PR-4 seq+timestamp); the surface is reflective-only (D-7) so a losing remote just sees the winner's state — no client-side merge, no divergence. Primary-operator lock stays deferred (strategy §2.4.4). | Two people can still alternate rapidly; visible, human-resolvable. Accepted. |
| **Local Network permission denied** | Detected via the documented `kDNSServiceErr_PolicyDenied` shape (§1.2) → guidance card; pairing via QR host:port STILL WORKS (unicast connect needs no browse permission — TN3179), so denial degrades, not bricks. Primer pre-explains the prompt (§4.3). | Discovery-less UX until the user flips the toggle. |
| **Camera permission denied / unavailable** | Request on tap, not appear; denial → paste path always present (§4.3); visionOS/macOS never offer the dead button. | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — Entry-path matrix as §4's table.** Fingerprint before connect, always; Bonjour is routing, never trust; C′ rows are unconnectable by design. Rationale: `connect` has no fingerprint-less path and PR-8 owns TOFU.
- **D-2 — Supervision lives in `RemoteControlSession` (IHLive actor), not the coordinator.** Headless loopback testability + PR-11 reuse + LOC + the transport actor's own policy note (§3.4).
- **D-3 — Health machine is tick-counted (no Date)**; detach ≈ 20 s worst case for silent links, immediate for hard errors. Numbers 5 s/3 from #1422's own text; asserted literally in tests.
- **D-4 — Backoff 1 s × 2 → 30 s cap, ±20% injected jitter, unbounded while visible+paired, suspended on background.** Jitter is a correctness feature (herd vs the 4-slot cap), not polish.
- **D-5 — Store keyed by fingerprint; raw token stored; `synchronizable:false` hard-coded.** Asymmetric custody is the strategy §2.4.3 design; §7.4 + grep gate enforce.
- **D-6 — UI home is the `.live` section via `LiveHubView`** (7-tab cap; destination-not-preference; PR-10's future slot beside it).
- **D-7 — The surface is stateless/reflective**; the TV's broadcast (including self-echo) is the only UI-state source. Multi-remote correctness by construction.
- **D-8 — Song matching is EXTRACTED to `AppRootViewModel.songsMatching(_:in:)`** and shared by `filteredSongs` + the picker; the picker keeps its own query string. Re-forking the number/substring ladder is the exact `_bsls_*`-class regression this repo names.
- **D-9 — `.prepare` is a deliberate context action ("Prepare on TV"), `.selectSong` is the tap.** Same-tap prepare+select hides no RTT; don't ship cargo-cult prefetch.
- **D-10 — No snapshot tests** (no package; #1527); no new external dependency of any kind.
- **D-11 — CI gains the iOS-Simulator `iHymns` build step** (PR-5/#1504 precedent) because this PR ships the first iOS-only IHFeatures code; AND the builder runs everything locally regardless (#1526).
- **D-12 — Camera = iOS/iPadOS only, `AVCaptureSession`+`AVCaptureMetadataOutput`;** visionOS (no third-party camera access) and macOS (ergonomics + sandbox surface) use paste (B′). Honest per-platform matrix over a uniform lie.
- **D-13 — `info:`-block for the array-typed `NSBonjourServices`,** merge-verified via `plutil`, with the full-migration fallback documented (§6.5). The three keys shipping is the criterion, the mechanism is not.
- **D-14 — `RemoteSessionActor` edits are ADDITIVE ONLY** (resolved-address capture). Any change to connect/verify/disconnect semantics, or a second consumer of its streams, is a review reject.
- **D-15 — Scope tripwires (reviewer rejects on sight):** manual connect-by-address / any fingerprint-less or TOFU connect (PR-8); Watch relay (PR-11); `service_broadcast` mirror (PR-14); `synchronizable: true` anywhere under `Sources/IHLive/LANRemote/` (grep gate: `grep -rn "Synchronizable" appApple/Packages/iHymnsKit/Sources/IHLive/LANRemote/` must show only hard-coded `false`); any new external package; lyric text in any IHRP payload; a second consumer of `phaseUpdates`/`incomingMessages`; `import Network` in new IHFeatures files (views/coordinator talk to the session; `NWEndpoint` values ride inside IHLive types).
- **Security notes for the PR body:** new credential custody on the REMOTE side (raw token at rest — custody rules above); no backend contact anywhere in this PR; reproduce this §8 table; note Audit B re-reviews PR-6+PR-7 together before external TestFlight (plan §2 gate).

## 9. Commit plan (one PR, atomic — each commit compiles + `swift test` green)

1. `feat(apple): remote-side pairing flow + link policy cores, paired-TV stores (#1422)` — `RemotePairingFlowState.swift`, `RemoteLinkPolicy.swift`, `PairedTVStore.swift`, `KeychainPairedTVStore.swift`, `RemotePairingEntryResolver.swift`, `LANRemoteResolvedAddress` (in `LANRemoteAddress.swift`), §7.1/§7.2/§7.4 suites.
2. `feat(apple): RemoteControlSession — ceremony client, ping ladder, jittered reconnect (#1422)` — `RemoteControlSession.swift` + `+Link.swift`, the `RemoteSessionActor+Connection.swift` resolved-address edit, §7.5 loopback suite.
3. `feat(apple): remote control UI — Live hub, discovery/paired list, pairing sheets, control surface (#1422)` — all §2 IHFeatures files, `RootContainerView`/`AppRootViewModel+Search`/`IHSettingsStore` edits, §7.3 suites.
4. `feat(apple): iHymns browse-side Local Network/Bonjour/camera Info.plist keys + iOS-sim CI build (#1422)` — `project.yml` `info:` block (+ merge verification noted in the commit body), `apple.yml` step, regenerated-project build proof.
