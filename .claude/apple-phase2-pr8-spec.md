# Apple Phase-2 PR-8 — manual connect-by-address (TOFU) + VPN/AP-isolation troubleshooter + venue network doc (#1424)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-12).** Sibling of `.claude/apple-phase2-pr6-spec.md` (TV side + shared crypto, MERGED) and `.claude/apple-phase2-pr7-spec.md` (remote-side client, MERGED to `alpha` as PR #1550, commit `c7e987e9`). Grounded in a code-level read of the merged PR-4→PR-7 seam on `alpha` (`Sources/IHLive/LANRemote/*`, `Sources/IHFeatures/RemoteControl*`), `apple-native-strategy.md` §2.4.3/§2.4.5, and `apple-phase2-implementation-plan.md` §2 (PR-8 row: "manual connect-by-address + VPN/AP-isolation troubleshooter + venue doc", ~1d) + §4 + §5 + §6.5. A Sonnet builder should execute this top-to-bottom with minimal further judgement. Target branch: **`feat/apple-p2-pr8-manual`** off `alpha`; ONE PR targeting `alpha`. **CI's `apple.yml` is NOT a required check (#1526 — it auto-merged red appApple PRs twice), so every §7 verify command MUST be run locally before the PR is opened.** This PR re-opens the LAN remote's SECURITY BOUNDARY in exactly ONE place (the TOFU connect) — §8's threat-model rows are acceptance criteria, not commentary.

---

## 1. The PR-7 / PR-8 boundary — and the exact TOFU connect contract

**PR-7 built (merged, do not touch except where §2 says):** the whole remote-side client — entry-path matrix (`RemotePairingEntryResolver`), ceremony client (`RemotePairingFlowState` + `RemoteControlSession`), ping/reconnect ladder, paired-TV Keychain store, control surface, browse-side Info.plist keys. **PR-7's binding invariant was "the fingerprint is the line": every PR-7 connect path knows `expectedFingerprint` BEFORE connecting** (`RemoteConnectTarget.expectedFingerprint` is non-optional, `RemotePairingEntryResolver.swift:62`; `RemoteSessionActor.connect(to:expectedFingerprint:token:)` has no fingerprint-less path, `RemoteSessionActor+Connection.swift:48`).

**PR-8 builds (this spec) — the FINAL rung of the LAN-remote client, issue #1424:**
1. **Manual connect-by-address (TOFU)** — the user types an IP/host + port (default TCP **7269**, strategy §2.4.5's documented default) to reach a TV Bonjour can't find (VPN'd-in remote — "expect connectivity without discovery"; AP client isolation). NO out-of-band fingerprint exists, so the first connect is **Trust-On-First-Use**: accept whatever certificate the peer presents, CAPTURE its fingerprint `F_obs`, show it to the human for a cross-check against the TV's Settings screen, then run the SAME PR-6 ceremony — whose proof binds `F_obs` (channel binding) — and **pin `F_obs` only after the ceremony succeeds** (§4, D-1/D-3). On success the TV becomes a perfectly normal `PairedTVRecord`; every later reconnect uses the NORMAL pinned path, never TOFU again (§4.4).
2. **VPN / AP-isolation troubleshooter** — an in-app diagnostic screen (strategy §2.4.5: "in-app troubleshooter + deploy doc"): Local Network permission state, does Bonjour find ANYTHING, direct-connect reachability of a typed/saved address, AP-client-isolation inference, plain-English guidance. Built as a PURE decision function over observed inputs + thin probes in IHLive + a thin IHFeatures screen (§5).
3. **Venue network doc** — `appApple/dev-docs/Venue-Network-Guide.md` for venue operators (non-isolated SSID/VLAN, TCP 7269, VPN-into-LAN, the Local Network prompt). `dev-docs/` is NEVER bundled into any app (two independent guarantees, `appApple/dev-docs/README.md`) (§9).

**PR-8 does NOT build (tripwires, reviewer rejects on sight):** the Watch relay (PR-11 #1423); any `service_broadcast` mirror (PR-14 #1425); any new external package; `import Network` in any new IHFeatures file (views/coordinator speak host-`String` + port-`UInt16` primitives; ALL `NWEndpoint`/`NWConnection`/`NWBrowser` construction stays in IHLive); lyric text on the wire; any change to the paired-TV store's `synchronizable:false` posture; a second consumer of `RemoteSessionActor.phaseUpdates`/`incomingMessages` (the `RemoteControlSession+Link.swift` loops stay the ONLY ones); **any `RemoteSessionActor` semantic change beyond the ONE additive TOFU connect method** (§4.1).

### 1.1 The TOFU flow, end to end (BINDING — the manual-connect contract)

```
Remote (this PR)                                          TV (merged PR-6, UNTOUCHED)
────────────────                                          ───────────────────────────
user types host [+ port, default 7269]  → LANRemoteManualAddress.parse (§3.1, pure)
RemoteControlSession.attachByAddress(host:port:displayName:)          (§4.2)
  → RemoteSessionActor.connectTrustingFirstUse(to:)                   (§4.1 — the ONE actor addition)
      TLS 1.3; verify block ACCEPTS any presented cert,
      REJECTS a peer presenting none, and CAPTURES F_obs
      (log: "lanremote.pin tofu-observed fingerprint=F_obs" .notice)
      pairingToken forced nil — NO saved token EVER rides a TOFU connect (D-5)
  actor auto-sends .hello(token: nil, kind)      ─►  parks connection in .pairing
                                                 ◄─  .ack / .pairChallenge(nonce)
  session STASHES the nonce (flow not armed yet — §4.2 race note)
  session yields .awaitingFingerprintConfirmation(F_obs)
  ── coordinator: F_obs already in PairedTVStore? ──
  │ YES → KNOWN TV reached via a new address: drop this connection,
  │       re-attach via the NORMAL PINNED path with the saved token
  │       (RemotePairingEntryResolver.manualResolution → .knownTV target)
  │       → fast path → .controlling. No interstitial, no ceremony. (§4.3)
  │ NO  → FingerprintConfirmView: "check this matches the fingerprint on the
  │       TV's Settings → Remote Control screen"  [It Matches] / [Cancel]  (§6.3)
  user confirms → session.confirmObservedFingerprint()
  arms Target(expectedFingerprint: F_obs, token: nil) + RemotePairingFlowState
  replays the stashed nonce → .awaitingCodeEntry(0) → the NORMAL code sheet
  proof = LANRemotePairingProof.compute(code,
            fingerprintHex: F_obs,   ← the fingerprint we OBSERVED on THIS
            nonceHex: nonce)           TLS connection — the channel binding
  .pairConfirm(proof, deviceName)                ─►  verify over ITS OWN
                                                      identity.fingerprint
                                                      (TVListenerActor+Pairing.swift:166)
  on success:                                    ◄─  .pairSuccess(token) + .capabilities
  → .paired → coordinator persists PairedTVRecord{F_obs, token, lastAddress}
    — THE PIN IS WRITTEN HERE AND ONLY HERE (D-1). All later reconnects =
    connect(to:expectedFingerprint: F_obs, token:) — the pinned path. (§4.4)
```

**Why this is safe, compactly (full rows in §8):** a first-connect **MITM/relay** must terminate TLS with ITS OWN certificate (it cannot present the TV's cert without the TV's private key), so `F_obs = F_mitm ≠ F_TV`; the remote's proof binds `F_mitm`, the TV verifies over `F_TV` (`TVListenerActor+Pairing.swift:166`), constant-time verify rejects — a relayed proof NEVER pairs the attacker's victim-facing session through to the real TV. A **standalone rogue** at the typed address doesn't know the TV-screen code and is bounded by the PR-6 online caps (3/connection, 5→rotate, 15 cumulative, 120 s TTL, single-use). The honest residuals — an active MITM/rogue can offline-recover the ~20-bit code from ONE harvested proof and then pair ITSELF with the real TV inside the TTL; and the user may blind-tap the fingerprint confirm — are §8 rows 1–2, with the interstitial (D-3) as the designed mitigation and the QR path remaining the recommended primary (venue doc says so, §9).

### 1.2 Facts about the merged seam this spec builds on (verified in code — cite these in file headers)

- `RemoteSessionActor.connect(to:expectedFingerprint:token:)` (`RemoteSessionActor+Connection.swift:48`) pins via `sec_protocol_options_set_verify_block` capturing the PARAMETER (`:59`, `[expectedFingerprint]`); `matches = presented != nil && presented == expectedFingerprint` (`:61`); "no expected fingerprint = reject" is the header's stated posture (`:17-19`). **The actor's stored `var expectedFingerprint` (`RemoteSessionActor.swift:101`) is WRITE-ONLY bookkeeping — set at `:50`, read nowhere** (verified by grep) — so the TOFU method setting it to `F_obs` post-handshake changes no behaviour.
- The verify block completes strictly BEFORE the connection reaches `.ready`, and `connect` awaits `.ready` via `pendingConnectContinuation` (`+Connection.swift:86-89`, `:144-153`) — so reading a lock-box the verify block wrote is race-free after the continuation resumes (§4.1).
- `disconnect()` (`+Connection.swift:128-140`) resets connection/decoder/`resolvedRemoteAddress` and resumes a pending continuation with `.notConnected`; `connect`'s first line is `disconnect()` — the TOFU method mirrors this exactly.
- `onConnectionStateChanged`/`scheduleReceive`/`sendHello`/`handleIncomingFrameData` are actor-internal and shared — a same-module extension file reuses them all; the TOFU method adds NO new receive/phase machinery.
- `hello` is auto-sent at the tail of every connect (`+Connection.swift:92`, `sendHello()` `:166-168` sends `.hello(token: pairingToken, …)`) — a TOFU connect therefore MUST have forced `pairingToken = nil` before this fires (D-5).
- `RemoteControlSession.attach(to:)` (`RemoteControlSession.swift:181-203`) arms the flow BEFORE connecting because the fingerprint is already known; TOFU cannot (F_obs doesn't exist yet) — hence §4.2's deferred arming + nonce stash. `handleIncoming`'s `.pairChallenge` branch (`RemoteControlSession+Link.swift:190-201`) lazily arms a flow ONLY when `target != nil` — mid-TOFU both `flow` and `target` are nil, so an unstashd challenge would be silently DROPPED (the race §4.2 closes).
- `cancelPairing()` guards `flow != nil` (`RemoteControlSession.swift:217`) — it would NO-OP for a TOFU cancel at the interstitial (flow not armed yet); §4.2 extends it.
- `handleIdleOrFailed` (`+Link.swift:81-126`): flow==nil + `hasEverControlled==false` ⇒ `target = nil` + yield `.pairingEnded(.connectionTornDown)` (`:104-111`) — a failed/dropped TOFU connect ALREADY produces the right terminal event through the phase stream; `attachByAddress` must not double-yield (§4.2).
- `apply(.reportPaired)` rebuilds `target` with the fresh token (`+Link.swift:244-251`) and `performReconnectAttempt` calls the PINNED `remote.connect(to:expectedFingerprint:token:)` (`:322`) — so once a TOFU pair completes, the ladder is automatically pinned-only with zero new code (§4.4).
- `RemoteControlCoordinator.persistPaired` (`RemoteControlCoordinator.swift:329-341`) writes the `PairedTVRecord` ONLY on `.paired` — i.e. only after `.pairSuccess` — which IS the "pin only after a successful ceremony" rule (D-1); it reads `currentFingerprint`/`currentTVName`, which the TOFU path must set (§6.5). `touchLastConnected` (`:349-358`) refreshes `lastAddress` from `currentResolvedAddress()` on first `.controlling` — manual reconnects update the saved address for free.
- `RemoteControlCoordinator.localNetworkDenied` is documented "**Always `false` in this PR**" (`RemoteControlCoordinator.swift:53-63`) because surfacing it needed a `RemoteSessionActor` addition PR-7 refused to smuggle in. **PR-8 resolves the user need WITHOUT that actor edit:** the troubleshooter detects `kDNSServiceErr_PolicyDenied` on its OWN bounded browse via the existing internal helper `RemoteSessionActor.isLocalNetworkPermissionDenied(_:)` (`RemoteSessionActor.swift:236-241` — internal, same-module-callable) (§5.2, D-7). The coordinator property stays as-is.
- `IHRPCapabilities` carries `protocolVersion`/`maxFrameBytes`/`currentState` ONLY (`IHRPPayloads.swift:126-138`) — the wire never tells the remote the TV's NAME, which is why a manually-paired TV is recorded under its typed host string (D-10).
- `RemotePairingEntryResolver.isValidFingerprint` = exactly 64 lowercase hex (`RemotePairingEntryResolver.swift:284-286`) — `F_obs` always satisfies it by construction (our own `LANRemoteFingerprint.sha256Hex`).
- Loopback harness helpers (`makeSession`/`makeListener`/`target`) are deliberately `internal` for cross-file reuse (`RemoteControlSessionLoopbackTests.swift:57-97`) — §7's new suites reuse them, never re-derive the recipe.
- `apple.yml` ALREADY has the iOS-Simulator PACKAGE cross-compile step (added by PR-7, with the #1549 explanation of why the app scheme can't build iOS yet) and the macOS + tvOS `xcodebuild` steps; `dev-docs/**` and `**/*.md` are path-EXCLUDED from triggering it — the §9 doc commit fires no CI. **No `apple.yml` edit in this PR.**
- `project.yml`'s `iHymns` target `info:` block (`project.yml:118-124`) already ships `NSLocalNetworkUsageDescription` + `NSBonjourServices` + `NSCameraUsageDescription`. Per TN3179, LOCAL-network unicast (which a typed-LAN-address connect is) is covered by the same Local Network permission the browse already prompts for; a VPN-routed address isn't local-network traffic at all. **No `project.yml` edit, no new entitlement (plan §4 confirmed).**

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh` — budget for two-register comments by splitting early). SwiftLint clean. Every file carries ELI5 + DETAILED headers referencing **#1424**, strategy §2.4.5 (and §2.4.3 for the TOFU files), and plan §2 PR-8 — match PR-6/PR-7 comment density.

### New — module `IHLive` (`Sources/IHLive/LANRemote/`)

| File | Purpose (one line) |
|---|---|
| `RemoteSessionActor+TOFU.swift` | THE one additive transport change: `connectTrustingFirstUse(to:) async throws -> String` — accept-any-observe verify block, deliberate ~40-line mirror of the pinned connect (§4.1, D-2). |
| `LANRemoteManualAddress.swift` | Pure typed-address validator/parser: host (IPv4/IPv6/hostname, optional `:port` suffix, bracketed v6) + port (1–65535, default `LANRemoteDiscovery.defaultPort`) (§3.1). |
| `RemoteControlSession+ManualConnect.swift` | The session's manual-connect half: `attachByAddress`, `confirmObservedFingerprint`, `ManualConnectState`, TOFU teardown helpers (§4.2/§4.3). |
| `LANConnectivityProbe.swift` | Single-shot diagnostic reachability probe (TCP+TLS accept-any-OBSERVE-cancel, never IHRP, injected timeout) + the PURE `Outcome` classifier over `NWError` (§5.1). |
| `LANTroubleshooter.swift` | The diagnostic runner: bounded discovery sample (own `NWBrowser`, PolicyDenied detection via the existing internal helper) + optional address probe → `LANTroubleshooterReport` (§5.2). |
| `LANTroubleshooterAssessment.swift` | PURE decision function: `evaluate(inputs) -> [Finding]` — the §5.3 table, exactly (§5.3, D-8). |

### New — module `IHFeatures`

| File | Purpose |
|---|---|
| `ManualConnectSheet.swift` | The "Connect by Address" form: Address + Port (pre-filled 7269 / last-used), parse-error inline copy, Connect → `coordinator.connectByAddress(hostInput:portInput:)` (§6.2). |
| `FingerprintConfirmView.swift` | The TOFU interstitial: grouped `F_obs`, "compare with the TV's Settings → Remote Control screen" copy, **It Matches** / **Cancel** (§6.3, D-3). |
| `NetworkTroubleshooterView.swift` | The troubleshooter screen: check rows (permission / discovery / direct connection), findings cards with plain-English guidance, "Connect by Address" hand-off (§6.4). |
| `NetworkTroubleshooterViewModel.swift` | `@MainActor @Observable` thin driver of `LANTroubleshooter`; maps `Finding` → user copy; owns the address field state via the SAME parser (§6.4). |
| `RemoteControlCoordinator+ManualConnect.swift` | Coordinator half: `connectByAddress`, `confirmFingerprint`, the known-TV shortcut on `.awaitingFingerprintConfirmation`, manual-specific failure copy (§6.5). |
| `RemoteControlCoordinator+UIPhase.swift` | **Pure relocation** (LOC budget): `UIPhase` + `uiPhase(after:current:tvName:)` move here from `RemoteControlCoordinator.swift` byte-identically, then gain the ONE new case/row (§6.5). |

### New — tests

| File | Gate | Purpose |
|---|---|---|
| `Tests/IHLiveTests/LANRemote/LANRemoteManualAddressTests.swift` | always-on | the §3.1 parse table, exhaustively (IPv4/v6/hostname/suffix/brackets/zone/garbage/scheme/port bounds). |
| `Tests/IHLiveTests/LANRemote/LANTroubleshooterAssessmentTests.swift` | always-on | the §5.3 decision table row-by-row + the `Outcome` classifier over constructed `NWError.posix(...)` values. |
| `Tests/IHLiveTests/LANRemote/ManualConnectLoopbackTests.swift` | `IHYMNS_LAN_LOOPBACK_TESTS=1` | the REAL TOFU flow vs a REAL `TVListenerActor` over 127.0.0.1 (§7.3): observe→confirm→code→paired→controlling; wrong code; cancel-at-interstitial; drop-before-confirm; **restart-with-different-identity reconnect MUST fail (pinned, never re-TOFU)**; known-TV manual reconnect target. |
| `Tests/IHLiveTests/LANRemote/LANConnectivityProbeLoopbackTests.swift` | `IHYMNS_LAN_LOOPBACK_TESTS=1` | probe vs a live listener ⇒ `.reachable(fp)`; probe vs a closed 127.0.0.1 port ⇒ `.refused`. (Timeout/isolation rows are classifier-level only — §5.4 honesty.) |

### Edited

| File | Edit |
|---|---|
| `Sources/IHLive/LANRemote/LANRemoteDiscovery.swift` | +`public static let defaultPort: UInt16 = 7269` beside the service-type constant ("one shared literal" file — strategy §2.4.5's documented default; PR-6's tvOS coordinator hardcoded it). |
| `Sources/IHLive/LANRemote/LANRemoteFingerprint.swift` | +`public static func displayGrouped(_ hex: String, every: Int = 4) -> String` — the ONE fingerprint-grouping helper; the TV overlay/Settings views' private grouping (if inline — builder: grep `TVPairingOverlayView`/`TVSettingsRemoteView`) is mechanically switched to call it (modularity rule: extract first, use second). |
| `Sources/IHLive/LANRemote/RemoteControlSession.swift` | +stored `var manualConnect: ManualConnectState?` + `var pendingChallengeNonce: String?` (stored props must live in the actor body; the TYPE lives in `+ManualConnect.swift`); `attach(to:)`/`endControl()`/`stop()` clear both; `cancelPairing()` extended to ALSO tear down a manual connect in flight (§4.2); `setSuspended(true)` mid-TOFU ⇒ cancel semantics (D-12). If the file nears 400 lines, relocate `setSuspended` intact into `+Link.swift` (pure move, note in commit body). |
| `Sources/IHLive/LANRemote/RemoteControlSession+Link.swift` | `handleIncoming`'s `.pairChallenge`: NEW first branch — mid-manual-connect (`manualConnect != nil`, flow nil, target nil) ⇒ stash `pendingChallengeNonce`, return (§4.2 race); `handleIdleOrFailed`: clear manual state alongside the existing `target = nil` terminal branch. |
| `Sources/IHFeatures/RemoteControlCoordinator.swift` | `UIPhase` + `uiPhase(after:...)` REMOVED (moved to `+UIPhase.swift`); `apply(_:)` gains the `.awaitingFingerprintConfirmation` case (delegating to the `+ManualConnect` extension); `internal` (not `private`) access for `currentTVName`/`currentFingerprint`/`notice`-setting so the same-module extension files can drive them (mirror PR-7's split conventions). |
| `Sources/IHFeatures/RemoteControlView.swift` | Browsing state gains the "Can't find your TV?" section (Connect by Address… / Troubleshoot…) + the same two actions on the empty state; `.confirmingFingerprint` UIPhase case renders `FingerprintConfirmView`; two new sheet presentations (§6.1). |
| `Sources/IHFeatures/IHSettingsStore.swift` | +`lastManualConnectAddress: String?` (UserDefaults-backed, same shape as `hasSeenLocalNetworkPrimer`) — prefills the manual sheet + troubleshooter. A LAN host string is not a secret; UserDefaults is correct custody. + its `IHSettingsStoreTests` row. |
| `Tests/IHLiveTests/LANRemote/TVListenerPairingLoopbackTests.swift` (support) | `PairingTestRemote` gains a `fingerprintOverride: String?` beside the existing `invalidProof` flag; +ONE test: a proof computed over a DIFFERENT (attacker's) fingerprint ⇒ `.error(.pairingRejected)` — §8 row 1's relay claim, executable. |
| `Tests/IHFeaturesTests/RemoteControlUIStateTests.swift` | +rows for the new event/phase (§7.2). |
| `Sources/IHFeatures/TVRemoteControlCoordinator.swift` | ONE-line mechanical: the `7269` literal reads `LANRemoteDiscovery.defaultPort` (no behaviour change). |

**No edits to:** `RemoteSessionActor.swift` / `RemoteSessionActor+Connection.swift` (**ZERO diff — the pinned path stays byte-identical; the TOFU method is a NEW same-module extension file**, D-2), `Package.swift`, `IHRPMessage`/`IHRPFrame`/`IHRPPayloads` (no protocol change — TOFU rides the existing ceremony), `LANRemotePairingProof`/`Ceremony`/`Payload` (frozen, golden vector), `TVListenerActor*` (the TV side is done and never learns TOFU exists), `PairedTVStore`/`KeychainPairedTVStore` (a TOFU pair produces a NORMAL record), `RemotePairingFlowState` (context already takes an arbitrary fingerprint), `RemoteLinkPolicy`, `project.yml`, `apple.yml`.

---

## 3. The pure cores (BINDING shapes — do not improvise)

No new time-driven machine exists in this PR — the only sleeps are the probe timeout and discovery window, both injected `Duration`s (the `LiveFollowEngine.isFresh(lastUpdatedAt:now:)` seam precedent, applied again).

### 3.1 `LANRemoteManualAddress` (pure parser — `LANRemoteManualAddress.swift`)

```swift
public enum LANRemoteManualAddress {
    public struct Parsed: Sendable, Equatable {
        public let host: String        // brackets stripped for v6; %zone preserved
        public let port: UInt16
    }
    public enum ParseError: Error, Sendable, Equatable {
        case empty                     // nothing typed
        case unsupportedScheme         // contains "://" — "just the address, no http://"
        case invalidHost               // charset/length violation
        case invalidPort               // suffix or field not 1...65535
    }
    /// `hostInput` = the Address field (may carry a `:port` suffix);
    /// `portInput` = the Port field (nil/empty ⇒ suffix, else default).
    /// Precedence: a host-field `:port` suffix WINS over the port field
    /// (the field is pre-filled with the default; a pasted "ip:port" is the
    /// stronger signal of intent — document in the doc comment).
    public static func parse(hostInput: String, portInput: String?) -> Result<Parsed, ParseError>
}
```
Rules (each a test row): trim whitespace/newlines; empty ⇒ `.empty`; `"://"` anywhere ⇒ `.unsupportedScheme`; **bracketed IPv6** `[fe80::1%en0]:7269` / `[::1]` ⇒ strip brackets, optional suffix port; **bare IPv6** (≥2 colons, unbracketed) ⇒ whole string is the host, NO suffix parsing (ambiguous); **IPv4/hostname with exactly one colon** ⇒ split into host + suffix port; port from suffix → `portInput` → `LANRemoteDiscovery.defaultPort`, must land in 1…65535 else `.invalidPort` (0 is explicitly invalid — the resolver's own port-0 clamp precedent); host charset allow-list `[A-Za-z0-9.\-_:%]`, length ≤253, else `.invalidHost`. This is UX-grade validation (garbage rejected before a connect attempt), not a security boundary — the host only ever feeds `NWEndpoint.Host` inside IHLive, never a shell/SQL/URL context; say so in the header.

### 3.2 `LANConnectivityProbe.Outcome` + classifier (pure half of §5.1)

```swift
public enum LANConnectivityProbeOutcome: Sendable, Equatable {
    case reachable(fingerprintHex: String)  // TLS completed; a cert was observed
    case refused          // TCP actively refused — host up, nothing on that port
    case timedOut         // no answer inside the injected timeout — down/blocked/ISOLATED
    case tlsFailed        // TCP connected but the TLS handshake failed — not an iHymns listener
    case dnsFailed        // the hostname didn't resolve
    case invalidAddress   // parse-level reject (never reaches the network)

    /// PURE error→outcome mapping — table-tested with constructed
    /// `NWError.posix(.ECONNREFUSED)` / `.posix(.ETIMEDOUT)` / `.dns(...)`
    /// values; anything unrecognised degrades to `.timedOut` (the honest
    /// "couldn't reach it" bucket), never a crash.
    public static func classify(_ error: NWError) -> LANConnectivityProbeOutcome
}
```

### 3.3 `LANTroubleshooterAssessment` (pure — the §5.3 decision table)

```swift
public struct LANTroubleshooterInputs: Sendable, Equatable {
    public var localNetworkPermissionDenied: Bool
    public var discoveredServiceCount: Int
    public var probe: LANConnectivityProbeOutcome?   // nil ⇒ no address was probed
}
public enum LANTroubleshooterFinding: Sendable, Equatable, CaseIterable { … §5.3's rows … }
public enum LANTroubleshooterAssessment {
    /// Ordered, deterministic — first element is the HEADLINE finding the
    /// UI leads with. Pure: no clock, no I/O, no network.
    public static func evaluate(_ inputs: LANTroubleshooterInputs) -> [LANTroubleshooterFinding]
}
```

### 3.4 `RemotePairingEntryResolver.manualResolution` (pure — the known-TV shortcut's decision)

```swift
extension RemotePairingEntryResolver {
    public enum ManualResolution: Sendable {
        /// F_obs matches a saved record — connect via the NORMAL PINNED path
        /// with the saved token; the typed address is the primary endpoint,
        /// the record's lastAddress (when different) the fallback.
        case knownTV(RemoteConnectTarget)
        /// Never seen this fingerprint — the interstitial + ceremony run.
        case unknownTV
    }
    public static func manualResolution(
        observedFingerprint: String, host: String, port: UInt16,
        displayName: String, saved: [PairedTVRecord]
    ) -> ManualResolution
}
```
`.knownTV`'s target: `tvName = record.name` (the REAL saved name, not the typed host), `expectedFingerprint = record.fingerprintHex`, `token = record.token`, `knownCode = nil`. Table-tested beside the existing §4-matrix rows.

### 3.5 `ManualConnectState` (session bookkeeping — type in `+ManualConnect.swift`, stored var in the actor body)

```swift
enum ManualConnectState: Sendable, Equatable {
    case connecting(host: String, port: UInt16, displayName: String)
    case awaitingConfirmation(host: String, port: UInt16, displayName: String, fingerprintHex: String)
}
```
Internal (not public) — pure bookkeeping the session actor owns; every terminal path (`attach`, `cancelPairing`, `endControl`, `stop`, suspend, drop) clears it together with `pendingChallengeNonce` (§4.2 lists each site — an orphaned stash is the bug class to design out).

---

## 4. The TOFU connect — the ONE additive `RemoteSessionActor` change + the session flow

### 4.1 `RemoteSessionActor+TOFU.swift` (NEW file; the pinned files take ZERO diff — D-2)

```swift
extension RemoteSessionActor {
    /// Connects WITHOUT a pinned fingerprint — Trust-On-First-Use, for the
    /// manual connect-by-address path ONLY (#1424, strategy §2.4.5). Accepts
    /// whatever certificate the peer presents (rejecting only a peer that
    /// presents NONE), captures its SHA-256 fingerprint, and returns it once
    /// the transport is up and `hello(token: nil)` has been sent.
    ///
    /// SECURITY (file-header contract, spelled out):
    ///  1. NO saved token ever rides this connect — there is deliberately no
    ///     `token:` parameter; `pairingToken` is forced nil so the auto-sent
    ///     `hello` can never leak a credential to an UNVERIFIED peer (D-5).
    ///  2. The returned fingerprint is TRUSTED BY NOBODY at this point — the
    ///     caller must run the full pairing ceremony (whose proof channel-binds
    ///     this exact value) and persist it only on `.pairSuccess` (D-1).
    ///  3. This method is used for AT MOST ONE connection per TV, ever —
    ///     every reconnect after pairing uses the pinned `connect(...)`.
    public func connectTrustingFirstUse(to endpoint: NWEndpoint) async throws -> String
}
```
Body = a deliberate ~40-line MIRROR of `connect(to:expectedFingerprint:token:)` (`+Connection.swift:48-97`) with exactly these deltas:
1. `self.expectedFingerprint = nil` … then set to `F_obs` after capture (the var is write-only bookkeeping, §1.2 — keeps it truthful); `self.pairingToken = nil` (hard-coded — no parameter).
2. The verify block: an `OSAllocatedUnfairLock<String?>` box (`import os`, Sendable — no `@unchecked`) captured by the closure; block body: `let presented = Self.certificateFingerprint(from: trustRef)` → `box.withLock { $0 = presented }` → `presented != nil ? IHLog.remote.notice("lanremote.pin tofu-observed fingerprint=\(presented!, privacy: .public)") : IHLog.remote.fault("lanremote.pin tofu-no-certificate")` → `complete(presented != nil)`. **Accept-any-cert, reject-no-cert.** TLS 1.3 minimum stays.
3. After `scheduleReceive(); await sendHello()`: `guard let observed = box.withLock({ $0 }) else { disconnect(); throw RemoteSessionError.transport("tofu-no-fingerprint") }` (defensive — unreachable, since `.ready` implies the verify block completed `true` which implies a cert was seen; the read is race-free because verification strictly precedes `.ready`, §1.2). `self.expectedFingerprint = observed; return observed`.

**Why a mirrored body instead of extracting a shared `connectCore`:** the parent tripwire is "the pinned path stays byte-identical" — ANY extraction puts the security-reviewed pinned connect back into the diff and onto the reviewer's plate. ~40 duplicated lines, confined to one file, cross-referenced in BOTH headers ("kept in deliberate sync with `+Connection.swift:48-97`; if you change one, change both — the §7 loopback suites cover each"), is the cheaper risk. This is a DOCUMENTED exception to the modularity rule, justified in the file header (Decision D-2) — the same trade PR-6 made when it refused to relax `handleUnauthenticated` generally.

State/stream reuse (no other actor changes): `stateUpdateHandler → onConnectionStateChanged` (phase `.connecting → .awaitingPairing` exactly as pinned), same `scheduleReceive` loop, same `handleIncomingFrameData`, same `disconnect()` housekeeping. The TOFU method stores NOTHING new on the actor — the observed fingerprint travels by RETURN VALUE only, so `disconnect()` needs no new clearing line and the pinned files stay untouched.

### 4.2 `RemoteControlSession` — the manual-connect flow (`+ManualConnect.swift` + small core/link edits)

```swift
extension RemoteControlSession {
    /// Primitives only — IHFeatures never imports Network; the NWEndpoint is
    /// built HERE (§1's tripwire).
    public func attachByAddress(host: String, port: UInt16, displayName: String) async
    /// The interstitial's "It Matches" — arms the target+flow over F_obs and
    /// replays any stashed challenge.
    public func confirmObservedFingerprint() async
}
```
New session `Event` case (public enum, same package — additive):
```swift
/// A TOFU connect reached `.awaitingPairing` and observed this fingerprint.
/// The coordinator decides: known TV ⇒ silent pinned re-attach (§4.3);
/// unknown ⇒ the FingerprintConfirmView interstitial (§6.3).
case awaitingFingerprintConfirmation(fingerprintHex: String)
```

`attachByAddress` (mirrors `attach(to:)`'s reset discipline, `RemoteControlSession.swift:181-203`):
1. `ensureDriverLoopStarted()`; cancel ping/reconnect tasks; reset `target = nil`, `flow = nil`, `hasEverControlled/isAttached/isSuspended = false`, `lastKnownRevision = nil`, `backoff.reset()`, `health.reset()`, `pendingChallengeNonce = nil`.
2. `manualConnect = .connecting(host:port:displayName:)`; yield `.connecting(attempt: 0)`; `IHLog.remote.notice("lanremote.manual attach-begin")` (host NEVER in the log line — or `.private` if included; follow the PR-4 convention).
3. `expectedIdleCount += 1` (the TOFU connect's internal `disconnect()` produces the same housekeeping `.idle` the pinned one does — `+Link.swift`'s header point 1 applies unchanged).
4. `guard let nwPort = NWEndpoint.Port(rawValue: port) else { manualConnect = nil; return }` (parser guarantees 1…65535; defensive); `let observed = try? await remote.connectTrustingFirstUse(to: .hostPort(host: .init(host), port: nwPort))`.
5. On throw (`observed == nil`): `manualConnect = nil; pendingChallengeNonce = nil; return` — **do NOT yield a failure event here**: the actor's `.failed` phase already routes through `handlePhase → handleIdleOrFailed → .pairingEnded(.connectionTornDown)` (§1.2), exactly like the pinned `attach`'s `try?` posture. Double-yielding is the bug.
6. On success: `manualConnect = .awaitingConfirmation(host:port:displayName:fingerprintHex: observed)`; yield `.awaitingFingerprintConfirmation(fingerprintHex: observed)`.

**The challenge-before-armed race (BINDING — close it exactly this way):** the actor auto-sends `hello` inside the TOFU connect, so the TV's `.pairChallenge` can arrive and be processed by the message-consumer task WHILE `attachByAddress` is still suspended (actor reentrancy; at loopback μs-latency the race is REAL, not theoretical). Today `handleIncoming`'s `.pairChallenge` branch (`+Link.swift:190-201`) drops the frame when both `flow` and `target` are nil. NEW first branch: `if manualConnect != nil, flow == nil, target == nil { pendingChallengeNonce = nonce; return }` — stash, never drop. The stash is gated on `manualConnect != nil` specifically so a challenge arriving AFTER a cancel (state already cleared) is still dropped, not resurrected.

`confirmObservedFingerprint`:
1. `guard case .awaitingConfirmation(let host, let port, let displayName, let fp) = manualConnect else { return }` (idempotent no-op otherwise).
2. Build + store `target = RemoteConnectTarget(tvName: displayName, endpoint: .hostPort(host:port:), fallbackEndpoint: nil, expectedFingerprint: fp, token: nil, knownCode: nil)`; arm `flow = RemotePairingFlowState(context: .init(expectedFingerprint: fp, knownCode: nil, deviceName: configuration.deviceName))`; `manualConnect = nil`; `IHLog.remote.notice("lanremote.manual fingerprint-confirmed")`.
3. `if let nonce = pendingChallengeNonce { pendingChallengeNonce = nil; ` replay `flow.handle(.challengeReceived(nonce:))` through the SHARED `apply(effect)` `}` — yields `.awaitingCodeEntry(0)` → the NORMAL PR-7 code sheet, reducer untouched. If no nonce arrived yet (slow network), the flow sits in `.awaitingChallenge` and the live `.pairChallenge` routes through the existing branch (target is now non-nil).

Teardown edits (each ONE line-ish, all in `RemoteControlSession.swift`):
- `cancelPairing()`: currently `guard flow != nil` (`:217`) — becomes `guard flow != nil || manualConnect != nil`; body additionally clears `manualConnect`/`pendingChallengeNonce`. The interstitial's Cancel and the code sheet's Cancel converge on the same `.pairingEnded(.cancelled)`.
- `attach(to:)` / `endControl()` / `stop()`: clear `manualConnect`/`pendingChallengeNonce` alongside their existing resets (`attach` is what the known-TV shortcut calls MID-manual-connect — it must sweep the TOFU state as it takes over).
- `setSuspended(true)` mid-manual-connect (`manualConnect != nil`): clear both, disconnect, yield `.pairingEnded(.cancelled)` INSTEAD of `.suspended` (D-12 — there is no target to resume to; resuming into a half-confirmed TOFU is a trust decision nobody made).
- `handleIdleOrFailed()` (`+Link.swift`): the existing flow-nil/`hasEverControlled`-false terminal branch (`:104-111`) additionally clears `manualConnect`/`pendingChallengeNonce` — a TV that drops mid-interstitial lands in `.pairingEnded(.connectionTornDown)` with clean state.

### 4.3 The known-TV shortcut (coordinator-driven, resolver-decided — §3.4)

On `.awaitingFingerprintConfirmation(fp)`, the coordinator (`+ManualConnect.swift`, §6.5) looks up the store: `RemotePairingEntryResolver.manualResolution(observedFingerprint: fp, host:port:displayName:, saved: savedRecords)`:
- `.knownTV(target)` — the user typed the address of a TV we ALREADY trust (the classic "TV moved to a new IP / discovery broken" reconnect): `currentTVName = record.name; currentFingerprint = fp; await session.attach(to: target)` — the ordinary PINNED fast path (`hello(token:)` → `.capabilities` → `.controlling` <1 s). `attach` sweeps the parked TOFU connection (§4.2's teardown edit). **No interstitial, no ceremony, no new trust decision** — the pin check happens at TLS exactly as any path-C connect; if an impostor squats the typed address, the pinned re-connect FAILS at handshake (logged `.fault`) rather than silently TOFU-ing. `touchLastConnected` then refreshes `lastAddress` to the typed address on first `.controlling` (§1.2) — the "last-good address" cache updates itself. Log: `"lanremote.manual known-tv-shortcut"`.
- `.unknownTV` — set `currentTVName = displayName; currentFingerprint = fp; uiPhase = .confirmingFingerprint(tvName: displayName, fingerprintHex: fp)`.

### 4.4 Reconnect after a TOFU pair = the NORMAL pinned regime (zero new code — verify, don't build)

`apply(.reportPaired)` already rebuilds `target` with the fresh token over the SAME `expectedFingerprint` (= `F_obs`) and endpoint (= the typed host:port) (`+Link.swift:244-251`); the ladder's `performReconnectAttempt` calls the pinned `remote.connect(to:expectedFingerprint:token:)` (`:322`); `persistPaired` writes the normal `PairedTVRecord` (fingerprint-keyed, raw token, `lastAddress` = the resolved typed address) so the NEXT app session reconnects via path C with the saved address as its endpoint (`resolveSavedRow`, `RemotePairingEntryResolver.swift:290-314`). **The §7.3 suite proves the negative:** restart the listener on the same port with a DIFFERENT identity → the ladder retries and FAILS at the pin, `.controlling` never fires, `.awaitingFingerprintConfirmation` never fires — TOFU is unreachable from any reconnect path by construction (there is no code path from the ladder to `connectTrustingFirstUse`).

---

## 5. The VPN / AP-isolation troubleshooter

### 5.1 `LANConnectivityProbe` (IHLive — deliberately NOT `RemoteSessionActor`, D-7)

```swift
public enum LANConnectivityProbe {
    /// One bounded reachability check: TCP connect + TLS handshake with an
    /// accept-any-OBSERVE verify block, then IMMEDIATE cancel. Sends no
    /// application byte, speaks no IHRP, never sends `hello`, never pairs —
    /// observe-and-hang-up, full stop.
    public static func probe(host: String, port: UInt16, timeout: Duration = .seconds(4)) async -> LANConnectivityProbeOutcome
}
```
Implementation notes: `NWConnection` has no connect timeout — race the state handler against `Task.sleep(for: timeout)` (injected, so the loopback tests run at milliseconds); `.ready` + observed cert ⇒ `.reachable(fp)` then `cancel()`; `.failed(let e)` ⇒ `Outcome.classify(e)` (§3.2); sleep wins ⇒ `.timedOut` + `cancel()`. Log: `"lanremote.troubleshoot probe outcome=\(caseName, privacy: .public)"` — host `.private`, fingerprint (public value) `.public`, NOTHING else. **Why not reuse `RemoteSessionActor`:** the tripwire caps this PR at ONE actor addition (the TOFU connect); a diagnostic must never send `hello` (it would consume one of the TV's 4 unpaired slots and show "a remote is trying to pair" on the venue screen for a mere reachability check); and a single-shot static with no streams is the honest shape of a probe. Stated in the file header as Decision D-7.

### 5.2 `LANTroubleshooter` (IHLive — the runner)

```swift
public struct LANTroubleshooterReport: Sendable, Equatable {
    public var inputs: LANTroubleshooterInputs
    public var discoveredNames: [String]           // shown on-screen, never logged verbatim
    public var findings: [LANTroubleshooterFinding]
}
public actor LANTroubleshooter {
    public init(discoveryWindow: Duration = .seconds(3), probeTimeout: Duration = .seconds(4))
    /// `probingHost == nil` ⇒ discovery-only run (probe = nil in the inputs).
    public func run(probingHost: String?, port: UInt16) async -> LANTroubleshooterReport
}
```
Runs its OWN bounded `NWBrowser` (independent instance — the single-consumer rule concerns `RemoteSessionActor`'s streams, not the OS's browser multiplicity): start, collect results + watch `.waiting(let error)` for `RemoteSessionActor.isLocalNetworkPermissionDenied(error)` (the existing internal helper, same module — `RemoteSessionActor.swift:236-241`; REUSE, never re-derive the `kDNSServiceErr_PolicyDenied` shape), stop after `discoveryWindow`. Then the probe (if a host was given). Then `LANTroubleshooterAssessment.evaluate(...)`. Everything injected; the assessment is where the logic lives — this actor is plumbing.

### 5.3 The decision table (BINDING — `LANTroubleshooterAssessment.evaluate`, one always-on test per row)

| # | permissionDenied | discovered | probe | HEADLINE finding | Plain-English guidance (copy lives in IHFeatures) |
|---|---|---|---|---|---|
| 1 | **true** | any | any | `.localNetworkPermissionDenied` | "Allow Local Network for iHymns: Settings → Privacy & Security → Local Network." (Dominates every other row — appended findings still listed after it.) |
| 2 | false | 0 | nil | `.nothingDiscoveredNoProbe` | "No TVs found. Check you're on the venue Wi-Fi and iHymns is open on the TV — or type the TV's address below to test it directly." |
| 3 | false | ≥1 | nil | `.discoveryWorking` | "Found N device(s) — discovery is working. Go back and pair by QR code, or test a specific address below." |
| 4 | false | ≥1 | `.reachable` | `.allClear` | "Everything checks out — discovery and direct connection both work." |
| 5 | false | 0 | `.reachable` | `.vpnOrMulticastBlocked` | "Direct connection works but discovery doesn't — typical on a VPN or a network that blocks device discovery. Use Connect by Address." **(THE owner's day-one case, strategy §2.4.5.)** |
| 6 | false | ≥1 | `.timedOut` | `.likelyClientIsolation` | "Devices are visible but can't talk to each other — the Wi-Fi likely has AP/client isolation enabled. The venue network must allow devices on this Wi-Fi to reach each other (see the venue network guide)." |
| 7 | false | 0 | `.timedOut` | `.unreachableAndInvisible` | "Nothing answered. Wrong Wi-Fi, client isolation, a firewall, or the TV is off." |
| 8 | false | any | `.refused` | `.portClosedOnHost` | "That device is on the network but nothing is listening on port N — is iHymns open on the TV? Check the port on the TV's Settings → Remote Control screen." |
| 9 | false | any | `.tlsFailed` | `.notAnIHymnsListener` | "Something answered, but it doesn't look like iHymns on an Apple TV — double-check the address and port." |
| 10 | false | any | `.dnsFailed` | `.hostnameNotResolving` | "That name didn't resolve — try the TV's IP address instead (shown on the TV's Settings → Remote Control screen)." |
| 11 | false | any | `.invalidAddress` | `.invalidAddress` | (Parser copy — normally caught before the run.) |

`evaluate` returns the headline first, then any secondary findings (e.g. row 6 also appends `.discoveryWorking`). Deterministic order; asserted literally in the §7 table test.

### 5.4 Simulator-testable vs device-only (state HONESTLY, in code comments + the PR body — plan §5)

- **CI/loopback-testable:** the parser, the classifier, the decision table, the UI mapping; probe `.reachable` (vs a live loopback listener) and `.refused` (vs a closed 127.0.0.1 port — deterministic on every OS).
- **Device-only:** real-AP mDNS, actual AP client isolation (rows 6/7 as LIVE outcomes), VPN-without-multicast (row 5 live), the Local Network permission prompt + PolicyDenied (row 1 live — the Simulator does not enforce the prompt). The manual-connect FLOW itself is fully simulator/loopback-verifiable (plan §5: "the manual-connect flow itself is simulator-testable over loopback").
- The troubleshooter's own browse can be the FIRST browse the app ever runs (user went straight to Troubleshoot) — the OS prompt fires then; acceptable (this screen is exactly where a user is primed for network questions). Note it in the view's header.

---

## 6. UI structure — view deltas, sheets, coordinator wiring

**watchOS note (#1549, applies to EVERY new IHFeatures file):** IHFeatures does not yet compile for watchOS (~9 pre-existing views use unavailable APIs). New PR-8 views MUST NOT extend that list — use `List`/`Form`/`Button`/`TextField`/`Label`/`ContentUnavailableView` only; NO `Menu`, NO `.segmented`/`.navigation` picker styles, NO `DisclosureGroup`, NO `.draggable`/`.dropDestination`, NO `.keyboardShortcut`. §7's watch cross-compile check verifies no NEW file appears in #1549's error list.

### 6.1 `RemoteControlView` deltas

- **Browsing list:** a new bottom `Section("Can't find your TV?")` with two rows — `Button("Connect by Address…") { isPresentingManualSheet = true }` and `NavigationLink("Troubleshoot Connection…") { NetworkTroubleshooterView() }`. The SAME two actions join `emptyStateView`'s `actions:` block (beside "Enter Code") — the empty state is precisely where the VPN'd-in owner lands on day one.
- **New UIPhase case:** `.confirmingFingerprint(tvName:fingerprintHex:)` renders `FingerprintConfirmView` full-screen (the `.codeEntry` precedent — a modal moment, not a sheet, so Cancel is unambiguous).
- New `@State private var isPresentingManualSheet = false` + `.sheet` presenting `ManualConnectSheet` wired to `coordinator.connectByAddress(hostInput:portInput:)`.

### 6.2 `ManualConnectSheet`

`NavigationStack { Form }` (the `PairingPayloadEntrySheet` idiom, verbatim posture): Section 1 — Address `TextField` (placeholder `"192.168.1.50 or hall-tv.local"`, `.autocorrectionDisabled()`, `.textInputAutocapitalization(.never)` under the same `#if` guards, prefilled from `IHSettingsStore().lastManualConnectAddress`); Port `TextField` prefilled `"7269"`; footer: "The TV shows its address and port in Settings → Remote Control." Section 2 (footer): "If you can scan or paste the TV's QR code instead, prefer that — it verifies the TV automatically." (§8's honest steer toward the pinned path.) Toolbar: Cancel / **Connect** (disabled while the address is empty). Connect → `LANRemoteManualAddress.parse` → error ⇒ inline red footer copy per `ParseError` case (never a crash, never a connect attempt); success ⇒ persist `lastManualConnectAddress`, dismiss, `coordinator.connectByAddress(...)`.

### 6.3 `FingerprintConfirmView` (the D-3 interstitial — the TOFU path's security UI)

Full-screen: title "Check This Is Your TV"; the fingerprint via `LANRemoteFingerprint.displayGrouped(fp)` in a monospaced, multi-line, selectable `Text` (all 64 chars, grouped in 4s — the TV's Settings screen shows the FULL value, PR-6 §6.3, so show the full value here too; a 12-char prefix would train partial-checking); copy: "On your TV, open **Settings → Remote Control** and compare this fingerprint. Only continue if it matches — this is how you know you're talking to YOUR TV and not something else on the network."; buttons **It Matches — Continue** (`coordinator.confirmFingerprint()`) and **Cancel** (`coordinator.cancelPairing()` — the session edit §4.2 makes this work pre-flow). Accessibility: the fingerprint is one element with a digit-grouped `accessibilityLabel`; buttons ≥44 pt. This screen is REQUIRED — there is no code path from `.awaitingFingerprintConfirmation` to the code sheet that skips it (only the known-TV shortcut, which needs no human because the PIN decides).

### 6.4 `NetworkTroubleshooterView` + `NetworkTroubleshooterViewModel`

VM (`@MainActor @Observable`): `addressInput`/`portInput` (prefilled from `lastManualConnectAddress` / `"7269"`), `isRunning`, `report: LANTroubleshooterReport?`, `func runChecks() async` — parse (empty address ⇒ discovery-only run), `await LANTroubleshooter().run(...)`, store. A `findingCopy(_ finding:) -> (title: String, guidance: String)` map = the §5.3 copy column (ONE map, exhaustive `switch` so a new Finding case is a compile error here).
View: intro copy ("If your phone can't find or reach the TV, this checks the usual causes."); the address+port fields; **Run Checks** button (`ProgressView` while running); results as three status rows (Local Network permission ✓/✗, "Found N device(s) via discovery", "Direct connection: <outcome>") + the findings cards (headline styled prominent, secondaries below); a contextual **Connect by Address** button when the headline is row 4/5 (reachable). No `Menu`, no segmented anything (watch rule). Header notes the device-only realities (§5.4).

### 6.5 `RemoteControlCoordinator` deltas (+ the two new extension files)

- **`+UIPhase.swift` (pure relocation, LOC budget):** `public enum UIPhase` + `nonisolated static func uiPhase(after:current:tvName:)` move BYTE-IDENTICALLY out of `RemoteControlCoordinator.swift` into an `extension RemoteControlCoordinator { }` file, THEN gain: `case confirmingFingerprint(tvName: String, fingerprintHex: String)` and the mapping row `case .awaitingFingerprintConfirmation: return current` — **the PURE map deliberately returns `current`** because the next phase depends on a STORE LOOKUP (side effect); the coordinator's `apply` sets `.confirmingFingerprint` explicitly in the unknown-TV branch (this asymmetry is documented on the case and §7.2-tested).
- **`+ManualConnect.swift`:** `public func connectByAddress(hostInput: String, portInput: String?)` — parse (reject ⇒ `notice` copy per error case); success ⇒ `notice = nil; currentTVName = parsed.host; currentFingerprint = nil; isManualConnectInFlight = true; lastManualParsed = parsed;` `Task { await session.attachByAddress(host:port:displayName: parsed.host) }`. `public func confirmFingerprint()` → `Task { await session.confirmObservedFingerprint() }`. `func handleFingerprintObservation(_ fp: String) async` — the §4.3 shortcut: `manualResolution(...)` over `savedRecords`; `.knownTV` ⇒ silent pinned `session.attach(to:)` (+ set name/fingerprint); `.unknownTV` ⇒ `currentFingerprint = fp; uiPhase = .confirmingFingerprint(...)`.
- **`RemoteControlCoordinator.swift` edits:** `apply(_:)` gains `case .awaitingFingerprintConfirmation(let fp): await handleFingerprintObservation(fp)`; the `.pairingEnded(.connectionTornDown)` branch consults `isManualConnectInFlight` for the copy — manual: `"Couldn't reach <host>:<port>. Check the address, and that iHymns is open on the TV."` vs the existing operator-guidance line; `isManualConnectInFlight` cleared on `.paired`/`.controlling`/`.pairingEnded`/`.ended`. New stored props: `isManualConnectInFlight: Bool`, `lastManualParsed: LANRemoteManualAddress.Parsed?` (internal, for copy + shortcut inputs).
- `persistPaired`/`touchLastConnected` are UNTOUCHED — the TOFU path feeds them through the same `currentFingerprint`/`currentTVName`/`.paired` route as every other path (that's the point).

### 6.6 Per-platform + `project.yml`

All new UI is platform-neutral (no camera, no scanner — manual entry is text). macOS/visionOS gain their first fully self-contained NEW-pair path that needs no payload string conveyed from the TV (PR-7 §4.3 noted "that TV waits for PR-8" — update `RemoteControlView`'s macOS empty-state line to mention Connect by Address). tvOS never reaches these screens (the `.live` tab is the phone-shell's). **No `project.yml` change, no `apple.yml` change** (§1.2, D-11) — verify by inspection, state in the PR body.

---

## 7. Test plan (Swift Testing; injected durations; secrets never printed — the fingerprint is public, the CODE never appears in any assertion message)

### 7.1 Always-on pure suites
- `LANRemoteManualAddressTests` — the §3.1 table: `"192.168.1.50"` ⇒ host+7269; `"192.168.1.50:8000"` ⇒ 8000 (suffix wins over field); `portInput: "9000"` no-suffix ⇒ 9000; `"hall-tv.local"`; `"[fe80::1%en0]:7269"` ⇒ host `fe80::1%en0`; bare `"fe80::1"` (no suffix parse); `"http://x"` ⇒ `.unsupportedScheme`; `""`/whitespace ⇒ `.empty`; `":0"`/`"host:0"`/`"host:70000"`/`"host:abc"` ⇒ `.invalidPort`; `"ho st"`/254-char host ⇒ `.invalidHost`; default asserted literally `== 7269` AND `== LANRemoteDiscovery.defaultPort`.
- `LANTroubleshooterAssessmentTests` — one case per §5.3 row (11 rows), headline-first order asserted, secondary-findings rows asserted (row 6 includes `.discoveryWorking`); classifier: `NWError.posix(.ECONNREFUSED)` ⇒ `.refused`, `.posix(.ETIMEDOUT)` ⇒ `.timedOut`, a `.dns` error ⇒ `.dnsFailed`, an unrecognised posix code ⇒ `.timedOut`.
- `RemotePairingEntryResolverTests` additions — `manualResolution`: matching saved fingerprint ⇒ `.knownTV` with the SAVED name + SAVED token + typed-address primary endpoint (+ lastAddress fallback when it differs); unknown fingerprint ⇒ `.unknownTV`; a saved record whose lastAddress EQUALS the typed address ⇒ no self-fallback.
- `RemotePairingFlowStateTests` — NO new rows (the reducer is untouched; assert that in the PR body, not with a test).

### 7.2 `RemoteControlUIStateTests` additions (always-on, `@MainActor`)
- `.awaitingFingerprintConfirmation` from every current phase ⇒ `current` returned unchanged (the documented pure-map asymmetry).
- `.pairingEnded(.cancelled)` from `.confirmingFingerprint` ⇒ `.browsing`, no notice (the interstitial Cancel round-trip).
- Existing rows byte-unchanged (the relocation is provably pure because this suite still passes untouched BEFORE the new rows are added — do the move in its own commit step).

### 7.3 `ManualConnectLoopbackTests` (`IHYMNS_LAN_LOOPBACK_TESTS=1`; reuse `RemoteControlSessionLoopbackTests`'s internal `makeSession`/`makeListener` helpers + millisecond config + the instant-`defer` teardown discipline its header mandates)
- **TOFU happy path (headline):** `beginPairing()` on the listener; `attachByAddress(host: "127.0.0.1", port:, displayName: "Typed TV")` ⇒ events: `.connecting(0)` → `.awaitingFingerprintConfirmation(fp == identity.fingerprint)` (the load-bearing equality — F_obs IS the listener's real fingerprint); `confirmObservedFingerprint()` ⇒ `.awaitingCodeEntry(0)` (proves the challenge-stash replay — at loopback speed the challenge ALWAYS beats the confirm); `submitPairingCode(code)` ⇒ `.paired(token:resolved:)` (resolved = 127.0.0.1 + port) → `.controlling`. Then detach + `attach` with the pinned target + token ⇒ `.controlling`, NO ceremony events (§4.4 forward half).
- **Wrong code:** `submitPairingCode("000000")` ⇒ `.awaitingCodeEntry(1)`, connection survives; correct code then pairs.
- **Cancel at the interstitial:** `attachByAddress` → `.awaitingFingerprintConfirmation` → `cancelPairing()` ⇒ `.pairingEnded(.cancelled)`; listener's unpaired slot released (connection count drops).
- **Drop before confirm:** stop the listener while awaiting confirmation ⇒ `.pairingEnded(.connectionTornDown)`, and a subsequent `attachByAddress` to a fresh listener works (state fully swept).
- **Nothing listening:** `attachByAddress` to a closed port ⇒ `.connecting(0)` then `.pairingEnded(.connectionTornDown)` — exactly ONCE (the §4.2 no-double-yield rule under test).
- **★ The pin holds (the anti-re-TOFU negative):** TOFU-pair against identity A on fixed port P; stop; start a NEW listener on P with identity B (+ authority pre-seeded with the token); the ladder retries and NEVER yields `.controlling` NOR `.awaitingFingerprintConfirmation` within a generous multi-attempt window (short-timeout expectation) — reconnects are pinned to A, TOFU is unreachable.
- **Known-TV shortcut target:** `attach(to: manualResolution(...).knownTV-target)` against a listener whose authority holds the saved token ⇒ straight `.controlling`, no ceremony events (the session-level half; the coordinator's store-lookup half is covered by the pure resolver rows + 7.2).
- **`TVListenerPairingLoopbackTests` addition (the §8 row-1 relay claim, executable):** `PairingTestRemote` with `fingerprintOverride:` set to a DIFFERENT valid 64-hex value computes its proof over the WRONG fingerprint ⇒ TV replies `.error(.pairingRejected)`; correct-fingerprint retry on the same connection then succeeds.
- `LANConnectivityProbeLoopbackTests`: probe vs live listener ⇒ `.reachable(fp == identity.fingerprint)` AND the listener records NO pairing-side effects (no `.remoteEnteredPairing` event within a short window — observe-and-hang-up proven, D-7); probe vs closed port ⇒ `.refused`; both under millisecond timeouts.

### 7.4 Local pre-PR verification (builder runs ALL — CI is not a required check, #1526)
```
cd appApple/Packages/iHymnsKit && swift build && swift test
IHYMNS_LAN_LOOPBACK_TESTS=1 swift test --filter 'ManualConnect|ConnectivityProbe|RemoteControlSessionLoopback|TVListenerPairingLoopback'
swiftlint --config appApple/.swiftlint.yml appApple          # 0 violations
bash appApple/Scripts/loc-budget.sh                          # every file ≤400
# iOS package cross-compile (the PR-7/#1549 interim — NOT the app scheme):
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk iphonesimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-ios26.0-simulator
# Shared IHLive/IHFeatures files were edited ⇒ BOTH platform builds (the #1532 lesson):
cd appApple && xcodegen generate
xcodebuild -project iHymns.xcodeproj -scheme iHymns  -destination 'platform=macOS,arch=arm64'          CODE_SIGNING_ALLOWED=NO build
xcodebuild -project iHymns.xcodeproj -scheme iHymnsTV -destination 'generic/platform=tvOS Simulator'   CODE_SIGNING_ALLOWED=NO build
# watchOS regression check (EXPECTED TO FAIL with exactly #1549's pre-existing list —
# assert NO NEW FILE appears in the error output):
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk watchsimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-watchos26.0-simulator ; true
```
**Builder footnote (local git quirk):** a `swift build` right after a clean may fail on the local `safe.bareRepository=explicit` git setting — prefix `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all` to the command (harmless on CI).

---

## 8. Threat model (acceptance criteria — PR-6 §8 + PR-7 §8 still apply wholesale; these are the TOFU/troubleshooter rows) + Decisions

| Threat | Mitigation (mechanism, THIS PR) | Residual |
|---|---|---|
| **First-connect MITM/relay on the manual path** (ARP spoof / evil twin / hostile VPN concentrator between the remote and the typed address) | The proof channel-binds `F_obs` (§1.1): the MITM must terminate TLS with its own cert, so a RELAYED proof binds `F_mitm ≠ F_TV` and the TV's constant-time verify rejects it (`TVListenerActor+Pairing.swift:166`; §7.3's `fingerprintOverride` test makes this executable). The D-3 interstitial lets an attentive user catch `F_mitm ≠` the TV-Settings fingerprint BEFORE any code is typed. Nothing else of value transits: TOFU carries NO token by API construction (D-5). | **★ THE honest residual (PR-6 §3.2's "defeats relay even in the TOFU path" claim needs this precision):** direct relay is defeated, but an active MITM (or rogue, next row) that receives ONE proof can OFFLINE brute-force the ~20-bit code (10⁶ HKDF+HMAC evaluations, well under a second) — the PR-6 online caps do not bind an offline search — and then pair ITSELF with the real TV using the recovered code, inside the 120 s TTL, while the ceremony is open. Bounded by: the attacker must be actively on-path at that exact moment; the interstitial check defeats it up front; single-use consumption means the attacker's pairing burns the code, so the victim's own pairing then visibly fails (or the victim "controls" a TV that doesn't respond) → operator-visible anomaly + the trusted-remotes list + one-tap revoke (PR-6 §8 row 4's visibility argument). Equivalent-in-kind to "the operator read the code to an attacker," REACHED only when the user skips the fingerprint check AND an active on-path attacker exists. Accepted; the QR path stays primary (sheet copy §6.2 + venue doc §9 both steer to it). |
| **Standalone rogue at the typed address** (typo, or socially-engineered address) | The rogue doesn't know the TV-screen code; online caps bound guessing (3/connection·5→rotate·15 cumulative·120 s·single-use); the interstitial shows an unfamiliar fingerprint; no token ever sent (D-5); pin written only after `.pairSuccess` (D-1) so an abandoned TOFU leaves ZERO trust residue. | If the user types the REAL TV's code into the rogue's ceremony, the rogue harvests a proof ⇒ the offline-recovery residual above, same bounds. The interstitial copy carries the burden ("only continue if it matches"). |
| **TOFU silently replacing the pin later** (the classic TOFU downgrade) | Structurally impossible: `connectTrustingFirstUse` is reachable ONLY from `attachByAddress` (a fresh user gesture); the ladder, path C, suspend/resume, and the known-TV shortcut all call the pinned `connect` (§4.4). A fingerprint change on a SAVED TV = pin failure = `.fault` log + no connection — never a re-prompt to trust the new cert (re-pairing is an explicit user ceremony via QR or a fresh manual connect, which UPSERTS by fingerprint only after a fresh `.pairSuccess`). §7.3's identity-swap test proves the negative. | A TV that legitimately reset its identity (PR-6 `LANRemoteIdentityStore.reset()`) requires the user to Forget + re-pair — correct, documented in the venue doc. |
| **Saved-token leakage to an unverified host** | By construction: no `token:` parameter on the TOFU connect; `hello` rides with nil (D-5). The known-TV shortcut sends the token ONLY under the saved fingerprint's TLS pin (§4.3). | None. |
| **Diagnostic probe as an attack/annoyance surface** | Observe-and-hang-up: no `hello`, no IHRP byte, immediate cancel — never consumes a TV pairing slot, never triggers the venue "remote is trying to pair" indicator (§7.3 asserts no listener event); user-initiated only, bounded by timeout; separate from the transport actor (D-7). | A user can probe arbitrary LAN hosts — indistinguishable from any port-scan-adjacent tool; single-shot + user-driven, accepted. |
| **Troubleshooter information disclosure** | The report renders on-screen only — discovered names/IPs never leave the device (this PR touches NO backend); logs carry outcome `caseName`s + counts, hosts `.private`, fingerprints `.public` (the module contract). | — |
| **Malformed typed input** | Pure parser rejects before any socket exists (§3.1); host string only ever becomes an `NWEndpoint.Host` inside IHLive; port hard-bounded 1–65535. | None meaningful. |
| **Secrets/PII in logs** | Restated binding contract: the code/token/proof/nonce NEVER appear in any `IHLog` interpolation in ANY new file; hosts/addresses/TV names `.private`; the observed/pinned fingerprint (public by design) `.public`. Review gate: grep every new file's `IHLog` calls. | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — Pin ONLY after a successful ceremony.** The observed TOFU cert is trusted by nobody until `.pairSuccess`; `persistPaired` (unchanged) is the single pin-writing site. An abandoned/cancelled/failed TOFU leaves no record.
- **D-2 — The TOFU connect is a NEW method in a NEW file (`RemoteSessionActor+TOFU.swift`), body a documented ~40-line mirror of the pinned connect; the pinned files take ZERO diff.** Rejected alternatives: an `expectedFingerprint: String?` overload (optionality creep onto the security-critical signature; a nil-slip becomes silent TOFU) and a shared `connectCore` extraction (puts the reviewed pinned path back in the diff). The mirror is the cheaper risk; both headers cross-reference the sync obligation; §7.3 covers both paths.
- **D-3 — The fingerprint-confirm interstitial is REQUIRED on every unknown-TV manual connect.** It converts blind TOFU into user-verifiable pinning (the TV's Settings screen displays the fingerprint — PR-6 built the other half of this handshake) and is the ONLY pre-code defence against §8 rows 1–2's residual. Full 64-char value shown (no prefix-training).
- **D-4 — TOFU never carries a `knownCode`.** A ceremony QR in hand IS the paste path (B′ — the payload carries the fingerprint); manual connect exists precisely for the no-payload case. One less path through the reducer.
- **D-5 — No token parameter on `connectTrustingFirstUse` — enforce "no credential to an unverified peer" by API shape,** not by caller discipline.
- **D-6 — Known-fingerprint shortcut (§4.3):** manual address + already-saved F_obs ⇒ drop the TOFU connection, re-attach pinned with the saved token. Costs one extra RTT; buys zero-new-trust-logic and the correct UX for the single most common manual-connect scenario (re-finding YOUR TV after discovery broke).
- **D-7 — The troubleshooter's probes live beside, not inside, `RemoteSessionActor`** (`LANConnectivityProbe`/`LANTroubleshooter`): keeps "TOFU is the ONLY transport-actor change" literally true, and a diagnostic that spoke IHRP would consume unpaired slots + spook venue screens. `isLocalNetworkPermissionDenied` is REUSED (internal, same module), never re-derived.
- **D-8 — The assessment is a pure decision function; findings are a semantic enum; copy lives in IHFeatures** (exhaustive switch ⇒ a new finding is a compile error at the copy map).
- **D-9 — The challenge-before-armed race is closed by stashing the nonce under the `manualConnect != nil` gate** — stash-never-drop while in flight, drop-never-resurrect after teardown (§4.2).
- **D-10 — A manually-paired TV is recorded under its typed host string** — the wire carries no TV name (`IHRPCapabilities`, §1.2). Cosmetic; self-heals on any later QR re-pair (upsert by fingerprint overwrites `name`). Noted, not fought.
- **D-11 — No `project.yml`/`apple.yml`/entitlement changes** (§1.2 verified: PR-7's Local Network keys cover unicast; CI already builds every needed slice; `dev-docs` is CI-excluded).
- **D-12 — Suspend mid-TOFU = cancel (`.pairingEnded(.cancelled)`), never `.suspended`** — there is no target to resume into, and auto-resuming a half-confirmed trust decision is exactly what a TOFU design must never do.
- **D-13 — `defaultPort` (7269) becomes the shared `LANRemoteDiscovery.defaultPort` constant** — one literal, three consumers (parser, sheets, tvOS coordinator's mechanical swap).
- **D-14 — Scope tripwires (reviewer rejects on sight):** any second `RemoteSessionActor` change beyond D-2's file; any TOFU reachable from a reconnect/ladder/saved-row path; `import Network` in new IHFeatures files; a probe that sends `hello`/IHRP; Watch relay (PR-11); `service_broadcast` mirror (PR-14); new external packages; `synchronizable: true` anywhere under `Sources/IHLive/LANRemote/` (grep gate re-run); lyric text on the wire; a second consumer of the actor streams; a new IHFeatures view using a #1549-class watchOS-unavailable API.
- **Security notes for the PR body:** reproduce this §8 table verbatim; name the offline-code-recovery residual EXPLICITLY (it refines PR-6 §3.2's optimistic TOFU sentence); state that Audit B (plan §2's gate) re-reviews PR-6+PR-7+PR-8 together before external TestFlight; no backend contact anywhere in this PR.

---

## 9. The venue network doc — `appApple/dev-docs/Venue-Network-Guide.md`

Lives in `dev-docs/` (developer/venue documentation, NEVER bundled — the two guarantees in `dev-docs/README.md`; CI path-excluded, so commit 5 fires no build). Written for a venue operator / AV volunteer, not a developer. Outline (builder writes full prose, ~150–250 lines, plain English, no jargon unglossed):

1. **What the iHymns TV remote needs from your network** — one table: same Wi-Fi/LAN (or a routed VPN); devices allowed to talk to EACH OTHER (no AP/client isolation); TCP port **7269** open between clients and the TV; Bonjour/mDNS for automatic discovery (optional — manual connect works without it).
2. **AP / client isolation** — what it is, why guest networks default to it, vendor spellings to look for ("AP isolation", "client isolation", "station isolation", "guest mode", "wireless isolation"), the fix (a non-isolated SSID or VLAN for the AV kit — strategy §2.4.5's owner-named requirement).
3. **Connecting over a VPN** — expect "connectivity without discovery" (multicast doesn't traverse routed VPNs); use **Connect by Address** with the TV's IP/port from its Settings → Remote Control screen; the last-good address is remembered per TV.
4. **The iPhone's Local Network permission** — what the prompt looks like, why iHymns asks, where to re-enable it (Settings → Privacy & Security → Local Network).
5. **Pairing, briefly, for operators** — the QR path is preferred (verifies the TV automatically); for manual connects, the app shows a fingerprint to compare against the TV's Settings screen — **teach operators to actually compare it**; the 6-digit code is short-lived (2 min), single-use, and every successful pairing shows on the TV + appears in the trusted-remotes list; revoke is one click.
6. **Firewall / managed-network checklist** — allow client↔TV TCP 7269; allow mDNS (UDP 5353, `_ihymns-remote._tcp`) if discovery is wanted; no internet dependency for the remote itself (content is the TV's own business).
7. **Troubleshooting flow** — mirrors the in-app troubleshooter's §5.3 outcomes, same plain-English guidance, so the doc and the app never tell different stories; final rung = the server-projector fallback (strategy §2.4.5, future PR-15).

---

## 10. Commit plan (one PR, atomic — each commit compiles + `swift test` green)

1. `feat(apple): TOFU transport connect + manual-address parser + shared port/fingerprint helpers (#1424)` — `RemoteSessionActor+TOFU.swift`, `LANRemoteManualAddress.swift`, `LANRemoteDiscovery.defaultPort`, `LANRemoteFingerprint.displayGrouped` (+ TV-view mechanical reuse), `TVRemoteControlCoordinator` literal swap, `LANRemoteManualAddressTests`, `PairingTestRemote.fingerprintOverride` + the wrong-fingerprint loopback row.
2. `feat(apple): RemoteControlSession manual connect-by-address — TOFU flow, fingerprint confirmation, known-TV shortcut (#1424)` — session stored state + `+ManualConnect.swift` + core/`+Link` edits + `RemotePairingEntryResolver.manualResolution` (+ resolver test rows) + `ManualConnectLoopbackTests`.
3. `feat(apple): manual-connect UI — address sheet, fingerprint interstitial, coordinator wiring (#1424)` — `RemoteControlCoordinator+UIPhase.swift` (pure move FIRST, existing tests green, THEN the new case), `+ManualConnect.swift`, coordinator/`RemoteControlView` edits, `ManualConnectSheet`, `FingerprintConfirmView`, `IHSettingsStore` key, `RemoteControlUIStateTests` rows.
4. `feat(apple): VPN/AP-isolation network troubleshooter (#1424)` — `LANConnectivityProbe`, `LANTroubleshooter`, `LANTroubleshooterAssessment` + table tests, probe loopback suite, `NetworkTroubleshooterView`/`ViewModel`, view wiring.
5. `docs(apple): venue network deployment guide (#1424)` — `appApple/dev-docs/Venue-Network-Guide.md` (CI-inert by path filter).

PR body: §8 table + security notes verbatim; the §7.4 command transcript as evidence; note #1526 (CI not required ⇒ local verification attached), #1549 (no new watch-incompatible API; watch cross-compile diff clean), and that PR-8 completes plan §1's "critical path to drive the TV from the phone" — the remaining Phase-2 LAN work is PR-11 (Watch relay) + the Audit-B gate.
