# Apple Phase-2 PR-6 — tvOS listener wiring + pairing ceremony + trusted-remotes Settings (#1421)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-11).** Grounded in a code-level read of the MERGED PR-4 (`Sources/IHLive/LANRemote/*`, commits `445673dc` #1521 + `8a1d8ffc` #1522) and MERGED PR-5 (`4f4e30e9` #1523 + `bf07a897`), `apple-native-strategy.md` §2.4.3/§2.4.5/§2.4.7, `apple-phase2-implementation-plan.md` §2 (PR-6 row) + §4 + §6.4, and the PR-5 spec (`apple-phase2-pr5-spec.md`) whose format this file matches. A Sonnet builder should execute this top-to-bottom with minimal further judgement. Target branch: `feat/apple-p2-pr6-pairing` off the current Apple line; ONE PR targeting `alpha`. **This PR is the SECURITY BOUNDARY of the whole LAN remote — §8's threat model is acceptance criteria, not commentary.**

---

## 1. The PR-6 / PR-7 boundary — and the exact wire ceremony PR-7 implements against

**PR-6 builds (this spec):** everything on the **TV side** plus the shared crypto both sides use:
- The IHRP/1 **pairing sub-protocol** (3 new `IHRPMessage` cases: `.pairChallenge` / `.pairConfirm` / `.pairSuccess`) + one new `IHRPErrorCode` (`.pairingRejected`).
- The **pure ceremony core** (`LANRemotePairingCeremonyState`) and the **ONE proof function** (`LANRemotePairingProof`) — public, because PR-7's phone UI calls the *same* `compute(...)` the TV's verify path uses (modularity rule: one implementation, two callers).
- The **persistent TV identity** (`LANRemoteIdentityStore` — generate once, Keychain, same fingerprint every relaunch).
- The **real `LANRemotePairingAuthority`** (`KeychainLANRemotePairingAuthority` — SHA-256 token hashes at rest, metadata, list + revoke).
- `TVListenerActor` ceremony wiring (`+Pairing.swift`), the tvOS **pairing overlay** (code + QR + fingerprint), the **trusted-remotes Settings tab** (fills `TVRootView`'s deferred Settings slot — its header note says "Settings arrives together with PR-6's trusted-remotes/pairing screens"), and the **listener ↔ `ProjectionViewModel` bridge** PR-5's spec §1 reserved.
- A **test-double remote** (`PairingTestRemote`, tests only) that produces a valid/invalid proof over a real loopback TLS connection — so the TV-side ceremony is E2E-provable without PR-7's UI.

**PR-7 builds (NOT here):** the phone/iPad/Mac/Vision UI that *produces* the proof — discovery list, QR scanner, code-entry sheet, raw-token + pinned-fingerprint persistence on the REMOTE (Keychain, **`synchronizable: false`** — plan §6.4), reconnect ladder. PR-8 adds manual connect-by-address/TOFU; PR-6/PR-7 always know `expectedFingerprint` before connecting (QR or same-account out-of-band), because `RemoteSessionActor.connect(to:expectedFingerprint:token:)` — verified — has no fingerprint-less path today.

**The wire ceremony (BINDING — this is PR-7's contract):**
```
Remote                                   TV (TVListenerActor)
──────                                   ────────────────────
connect (TLS 1.3, verify_block pins expectedFingerprint)     [PR-4, unchanged]
.hello(token: nil|stale, kind) ───────►  phase .unpaired → .pairing
                              ◄───────  .ack(ackSeq)
                              ◄───────  .pairChallenge(nonce)        [NEW]
   user reads 6-digit code from the TV screen (typed or QR-scanned)
   proof = LANRemotePairingProof.compute(code, fingerprintHex: expectedFingerprint, nonceHex: nonce)
.pairConfirm(proof, deviceName) ──────►  verify (constant-time, §3) …
  on success:                 ◄───────  .pairSuccess(token)          [NEW — remote persists raw token]
                              ◄───────  .capabilities(currentState)  [existing → RemoteSessionActor → .controlling]
  on failure:                 ◄───────  .error(.pairingRejected, nil) [NEW code — remote does NOT disconnect]
```
Reconnect stays PR-4's fast path untouched: `.hello(token: saved)` → `pairingAuthority.isPairedToken` → `.paired` → `.capabilities`.

**Facts about the merged PR-4/PR-5 seam this spec builds on (verified in code):**
- `TVListenerActor.completePairing(connectionId:token:) async -> Bool` exists and is the "external confirmation" hook; PR-6 refactors its body into a shared `promoteToPaired(...)` and keeps its public signature byte-identical (PR-4's loopback tests call it).
- `handleUnauthenticated` (in `TVListenerActor+Messages.swift`) accepts ONLY `hello`/`ping` while unconfirmed and tears down anything else — `.pairConfirm` must be added there, NOT relaxed generally.
- **`handlePaired`'s `default:` branch treats every unlisted case as a control intent and yields it up `controlEvents`** — `.pairConfirm` (and the TV-only `.pairChallenge`/`.pairSuccess`) MUST be added to its explicit reject list (`send .error(.malformedRequest, "unexpected on a paired connection")`, no drop) or a paired remote could inject a `pairConfirm` frame into the projection bridge. This is the single easiest edit to get wrong — a review checkpoint.
- `RemoteSessionActor.handleIncomingFrameData` **disconnects on `.error(.unauthorized)`** — which is why proof rejection needs the NEW `.pairingRejected` code (a typo'd code must not tear the connection down; the user retypes).
- `TVRemoteConnectionState` (class, actor-confined) is where per-connection `pairingNonce`/`pairingAttempts` live.
- `LANRemoteIdentityFactory.generateSelfSigned(commonName:validityDays:)` makes a FRESH identity each call under a `"app.ihymns.lanremote.\(UUID)"` label; its header explicitly defers persistence policy to PR-6. `LANRemoteIdentity`'s memberwise init is internal — the identity store must live in `IHLive`.
- `ProjectionViewModel`'s public API (`prepare`/`selectSong`/`nextComponent`/…/`setDisplayState`/`setAppearance`, `SongResult`, `stateUpdates`, `projectionState`) is exactly the PR-5 spec §4.1 contract; `stateUpdates` has ONE intended consumer — the bridge built here claims it (the Siri-Remote driver calls methods directly, no conflict).
- `IHLog.remote` / `IHLog.discovery` / `IHLog.signposter` exist (`Sources/IHLog/IHLog.swift:109/115/130`).
- CI already builds tvOS (`apple.yml`, added by PR-5 + `bf07a897`) — no CI edits needed.

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh`; budget for two-register comments by splitting early). SwiftLint clean. Every file carries ELI5 + DETAILED headers referencing **#1421**, strategy §2.4.3, and plan §2 PR-6 — match PR-4's comment density.

### New — module `IHLive` (`Sources/IHLive/LANRemote/`)

| File | Purpose (one line) |
|---|---|
| `LANRemotePairingProof.swift` | The ONE proof function (HKDF key-derivation + HMAC compute + constant-time verify) **public** for PR-7 reuse, + `LANRemotePairingSecrets` (token/nonce/code minting). |
| `LANRemotePairingCeremony.swift` | Pure, clock-parameterised ceremony state machine: active code, TTL, single-use consumption, fail-count → rotation signal. No I/O, no actor. |
| `LANRemotePairingPayload.swift` | The QR payload (`Codable`: version/name/host?/port?/fingerprint/code?) + its `ihymns-lanpair:v1:` base64url string codec — decoded by PR-7's scanner. |
| `KeychainLANRemotePairingAuthority.swift` | The real `LANRemotePairingAuthority`: SHA-256 token hashes in the Keychain (never raw), `LANRemotePairingMetadata` + `PairedRemoteRecord`, list + revoke-by-hash. |
| `LANRemoteIdentityStore.swift` | Persistent identity: fixed Keychain label, load-if-present else generate-once; `reset()`. |
| `LANRemoteAddress.swift` | Best-effort `primaryIPv4Address()` via `getifaddrs` (prefers `en0`, skips loopback; `nil`-tolerant) — feeds the Settings "Remote Control info" §2.4.5 wants; PR-8 reuses it. |
| `TVListenerActor+Pairing.swift` | Ceremony glue on the actor: `beginPairing`/`rotatePairingCode`/`endPairing`/`activePairingCode`, `handlePairConfirm`, `promoteToPaired`, `sendError(_:message:to:)`, `disconnectPaired(tokenHash:)`, `TVPairingEvent` + its stream. |

### New — module `IHFeatures`

| File | Purpose |
|---|---|
| `TVRemoteControlCoordinator.swift` | `@MainActor @Observable` composition root: builds identity + authority + `TVListenerActor`, runs the 3 bridge/consumer tasks, exposes pairing/trusted-remotes UI state. |
| `TVProjectionBridge.swift` | The PURE intent mapping (PR-5 spec §1's sketch, verbatim): `IHRPMessage` → `ProjectionViewModel` call → optional error reply. Unit-testable without any network. |
| `TVPairingOverlayView.swift` | Full-screen pairing overlay: giant 6-digit code, QR, fingerprint, "remote is trying to pair" indicator, Cancel. |
| `TVSettingsRemoteView.swift` | The Settings tab content: Remote Control info (port/IP/fingerprint/standing QR), "Pair a remote", trusted-remotes list + revoke (confirmation dialog). |
| `PairingQRCode.swift` | `CIFilter.qrCodeGenerator` → `CGImage` helper (`import CoreImage.CIFilterBuiltins`, **no external dependency**); `#if canImport(CoreImage)` — CoreImage does NOT exist on watchOS and IHFeatures compiles there (fallback: `nil`, views show code/fingerprint text only). |

### New — tests

| File | Gate | Purpose |
|---|---|---|
| `Tests/IHLiveTests/LANRemote/LANRemotePairingProofTests.swift` | always-on | compute/verify vectors incl. one GOLDEN vector (§7.1). |
| `Tests/IHLiveTests/LANRemote/LANRemotePairingCeremonyTests.swift` | always-on | TTL/single-use/rotation/attempt state machine via injected `now` (§7.2). |
| `Tests/IHLiveTests/LANRemote/LANRemoteIdentityStoreTests.swift` | `lanRemoteKeychainIdentityAvailable()` | same-fingerprint-across-loads; reset regenerates (§7.4). |
| `Tests/IHLiveTests/LANRemote/KeychainPairingAuthorityTests.swift` | `lanRemoteKeychainIdentityAvailable()` | hash-at-rest, round-trip, revoke, list, `synchronizable == false` (§7.4). |
| `Tests/IHLiveTests/LANRemote/TVListenerPairingLoopbackTests.swift` | `IHYMNS_LAN_LOOPBACK_TESTS=1` | full E2E ceremony over 127.0.0.1 TLS with `PairingTestRemote` (§7.5). |
| `Tests/IHFeaturesTests/TVProjectionBridgeTests.swift` | always-on | message→intent mapping incl. `.unavailable` → `.error(.contentUnavailable)` (§7.3). |

### Edited

| File | Edit |
|---|---|
| `Sources/IHLive/LANRemote/IHRPMessage.swift` | +3 cases (`pairChallenge`/`pairConfirm`/`pairSuccess`), `caseName` + `isControlIntent` entries (§4). |
| `Sources/IHLive/LANRemote/IHRPPayloads.swift` | +`IHRPErrorCode.pairingRejected` (§4). |
| `Sources/IHLive/LANRemote/IHRPFrame.swift` | CodingKeys +`proof`/`nonce`/`deviceName`; 3 new decode/encode branches (§4). |
| `Sources/IHLive/LANRemote/TVListenerActor.swift` | Stored `pairingCeremony` state + `pairingEvents` stream/continuation pair (init-wired, `nonisolated let`, the `controlEvents` idiom). |
| `Sources/IHLive/LANRemote/TVListenerActor+Messages.swift` | hello→`.pairing` branch mints nonce + sends `.pairChallenge` + yields `.remoteEnteredPairing`; `.pairConfirm` dispatch; `handlePaired` reject-list gains the 3 new cases; `completePairing` delegates to `promoteToPaired`. |
| `Sources/IHLive/LANRemote/TVListenerActor+Connections.swift` | `TVRemoteConnectionState` +`pairingNonce: String?` +`pairingAttempts: Int = 0`; `teardownConnection` yields `.remoteLeftPairing` for a `.pairing` connection. |
| `Sources/IHLive/LANRemote/LANRemoteIdentity.swift` | `generateSelfSigned` gains `keychainLabel: String? = nil` (nil ⇒ today's UUID scheme — PR-4 call sites byte-unaffected); pre-deletes items under an explicit label before bridging (recovers a half-broken prior state, `errSecDuplicateItem`); both `SecItemAdd` dicts gain `kSecAttrAccessible = kSecAttrAccessibleAfterFirstUnlock` (listener may start right after reboot). |
| `Sources/IHLive/LANRemote/LANRemotePairingAuthority.swift` | Protocol gains `registerPairedToken(_:metadata:)` (declared in the protocol + a forwarding default in an extension, so `InMemoryLANRemotePairingAuthority` and any test double compile unchanged). |
| `Sources/IHLive/LANRemote/RemoteSessionActor.swift` + `+Connection.swift` | `.pairSuccess(token)` → store into the existing `pairingToken` var; new `public var currentPairingToken: String?` accessor (PR-7 persists it). `.pairChallenge` needs NO actor change — it reaches PR-7/tests via the existing `incomingMessages` stream. |
| `Sources/IHFeatures/TVRootView.swift` | +Settings tab (`gear`) hosting `TVSettingsRemoteView`; `@State` coordinator; `.task { await coordinator.start() }`; ZStack overlay showing `TVPairingOverlayView` while pairing is active; header note updated (Settings no longer deferred; Home still is). |
| `Tests/IHLiveTests/LANRemote/IHRPMessageCodecTests.swift` | +4 `sampleMessages` rows (`pairChallenge`, `pairConfirm` with/without `deviceName`, `pairSuccess`) + `.error(.pairingRejected, nil)` + classification asserts. |
| `appApple/project.yml` | `iHymnsTV` gains `INFOPLIST_KEY_NSLocalNetworkUsageDescription` (§6, D-8 — belt-and-braces; the load-bearing browse-side keys are PR-7's on `iHymns`). |

**No edits to:** `Package.swift` (IHLive already has CryptoKit via the platform SDK; IHFeatures already depends on IHLive; no new external dependency anywhere), `apple.yml` (tvOS build step exists), `IHRPFramer.swift`, `ProjectionViewModel*` (the PR-5 contract is consumed, not changed), `IHymnsTVApp.swift` (TVRootView self-composes the coordinator).

---

## 3. The ceremony's cryptographic design (BINDING — do not improvise)

### 3.1 Secrets and their shapes (`LANRemotePairingSecrets`, in `LANRemotePairingProof.swift`)

| Secret | Shape | Minted by | Notes |
|---|---|---|---|
| Pairing **code** | exactly 6 ASCII digits, zero-padded (`"042317"`) | `mintCode(random:)` — default `{ Int.random(in: 0...999_999, using: &sys) }` with `SystemRandomNumberGenerator` (CSPRNG per Swift stdlib docs); injectable closure for deterministic tests | displayed on the TV; ~20 bits — its security is the ONLINE caps (§3.4), never entropy |
| Connection **nonce** | 32 lowercase hex chars (16 random bytes) | `mintNonce()` — 16 bytes from `SystemRandomNumberGenerator` → hex | per-connection, minted once when the connection enters `.pairing`; NOT secret (travels TV→remote) but never logged (type-names-only contract) |
| Pairing **token** | 64 lowercase hex chars (32 random bytes) | `mintToken()` | strategy §2.4.3's "per-remote 32-byte token"; TV persists ONLY `sha256(token)`; remote gets the raw value once, in `.pairSuccess`, inside TLS |

All three minting functions are `public static` on the caseless `public enum LANRemotePairingSecrets`. Hex encoding reuses the module-internal `LANRemoteFingerprint.sha256Hex` STYLE (lowercase `%02x`) — add one tiny internal `hexString(_ bytes:)` helper next to it rather than three private copies.

### 3.2 The proof — what is HMAC'd, and why (D-1)

```swift
// LANRemotePairingProof.swift — public enum LANRemotePairingProof
/// key   = HKDF<SHA256>.deriveKey(
///             inputKeyMaterial: SymmetricKey(data: Data(code.utf8)),
///             salt: Data("app.ihymns.lanremote.pair.v1".utf8),
///             info: Data("proof".utf8),
///             outputByteCount: 32)
/// msg   = Data((fingerprintHex.lowercased() + "." + nonceHex.lowercased()).utf8)
/// proof = lowercase hex of HMAC<SHA256>.authenticationCode(for: msg, using: key)   // 64 chars
public static func compute(code: String, fingerprintHex: String, nonceHex: String) -> String

/// Constant-time verify — parses `proofHex` to bytes (malformed/odd-length/non-hex ⇒ false,
/// a non-secret-dependent early exit), rebuilds `key`+`msg` exactly as above, then calls
/// HMAC<SHA256>.isValidAuthenticationCode(proofBytes, authenticating: msg, using: key)
/// — CryptoKit's constant-time MAC comparison. NEVER `==` on Strings/Datas of secrets,
/// NEVER a hand-rolled byte loop.
public static func verify(proofHex: String, code: String, fingerprintHex: String, nonceHex: String) -> Bool
```

**What each ingredient buys (threat-model mapping, §8):**
- **`code` as the HMAC key (via HKDF):** proves the person holding the remote could READ the venue TV's screen — the proximity proof strategy §2.4.3 is built on. HKDF (CryptoKit, no dependency) gives domain separation (`salt`/`info` constants versioned `v1`) so the same code could never be replayed into some future protocol use of it; deriving a proper 32-byte key from a 6-digit string also removes any "short HMAC key" edge behaviour.
- **`fingerprintHex` in the message = the channel binding** (strategy §2.4.3: "HMAC over the cert fingerprint keyed by the code"). The remote HMACs **the fingerprint it actually pinned/observed for THIS TLS connection** (`expectedFingerprint` — the value its `sec_protocol_options_set_verify_block` accepted). The TV verifies against **its own** `identity.fingerprint`. A MITM/relay attacker who terminates TLS must present ITS OWN certificate (it cannot present the TV's cert without the TV's private key), so the remote's proof binds to the attacker's fingerprint; when relayed onward, the TV computes over its own fingerprint → mismatch → reject. This defeats relay even in the future TOFU/manual path where no out-of-band pin exists; in PR-6/7's QR path the pin already rejects the MITM at TLS, and the binding is defence-in-depth.
- **`nonceHex` in the message = per-connection freshness.** Binds the proof to exactly one `.pairing` connection: a proof exfiltrated from one channel (bug, memory dump) is useless on any other connection, and a re-sent identical `pairConfirm` on the same connection after failure just re-verifies against the same inputs (harmless). TLS 1.3 already gives confidentiality; the nonce removes even the residual cross-connection replay class. The nonce also gives the remote an unambiguous "you are in pairing mode" signal (`.pairChallenge` doubles as the UX trigger for PR-7's code-entry sheet).
- **Why not SRP/SPAKE2 (the "right" PAKE answer):** a PAKE would let the two ends derive a session key from the code without ever exposing an offline-crackable transcript — but our transcript is never exposed (it exists only inside TLS 1.3), both ends are the same codebase, and CryptoKit ships no PAKE (a vendored implementation = new pinned dependency + audit surface, forbidden-by-default here). HMAC channel-binding over an already-encrypted, fingerprint-pinned channel + hard online caps reaches the same practical bar for a sanctuary-projector remote. Recorded as the considered alternative.

### 3.3 The pure state machine (`LANRemotePairingCeremonyState`)

```swift
public struct LANRemotePairingCeremonyState: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        public var codeTTL: TimeInterval = 120          // strategy §2.4.3 "Code TTL 2min"
        public var rotateAfterFailures: Int = 5         // "5 fails→rotate" — per CODE, global
        public var maxAttemptsPerConnection: Int = 3    // per-CONNECTION cap before teardown
        public var maxCeremonyFailures: Int = 15        // ★ POST-REVIEW FIX (see §8): CUMULATIVE
                                                        //   ceiling across ALL rotations → disarm
        public init()
    }
    public private(set) var activeCode: String?     // nil = ceremony not running / code consumed
    public private(set) var mintedAt: Date?
    public private(set) var wrongAttempts: Int      // per-code, reset on begin AND rotate
    public private(set) var ceremonyFailures: Int   // ★ cumulative; reset ONLY on begin, NOT rotate
    public let configuration: Configuration

    public init(configuration: Configuration = .init())
    public func isActive(now: Date) -> Bool         // activeCode != nil && now < mintedAt+codeTTL
    public mutating func begin(code: String, now: Date)       // NEW ceremony; resets BOTH counters
    public mutating func rotate(code: String, now: Date)      // ★ mid-ceremony; resets only wrongAttempts
    public mutating func end()                                 // disarm entirely
    public mutating func consume()                             // single-use: success clears activeCode
    /// Records one wrong proof (advances BOTH counters). Returns a FailureOutcome:
    /// .exhausted (cumulative ceiling hit → OWNER end()s the ceremony) takes
    /// precedence over .rotate (per-code threshold → OWNER mints+rotate()s a
    /// fresh code) over .recorded (keep going). ★ POST-REVIEW: was `-> Bool`.
    public enum FailureOutcome: Sendable, Equatable { case recorded, rotate, exhausted }
    public mutating func recordFailure() -> FailureOutcome
}
```
Pure (no clock reads, no RNG, no I/O) — `now` and `code` always injected, exactly the `LiveFollowEngine.isFresh(lastUpdatedAt:now:)` seam precedent. An EXPIRED code (`!isActive(now:)`) is treated by the verify path identically to "no ceremony running" — reject without counting toward rotation (an attacker must not be able to farm rotations against a dead code).

### 3.4 The TV-side verify algorithm (`TVListenerActor+Pairing.swift :: handlePairConfirm`)

On `.pairConfirm(proof, deviceName)` from connection `id` (dispatched from `handleUnauthenticated`):
1. `guard state.pairingPhase == .pairing` else → protocol violation: `.error(.unauthorized, nil)` + `teardownConnection` (a `.unpaired` connection that skipped `hello` is hostile/buggy).
2. `state.pairingAttempts += 1`; `guard state.pairingAttempts <= configuration… (ceremony config) .maxAttemptsPerConnection` else → `.error(.pairingRejected, nil)` + `teardownConnection(reason: "pairing attempt cap")` + `IHLog.remote.error("lanremote.pairing attempt-cap")`.
3. `let now = configuration.clock.now()`; `guard pairingCeremony.isActive(now: now), let code = pairingCeremony.activeCode, let nonce = state.pairingNonce` else → `.error(.pairingRejected, nil)`; log `.notice("lanremote.pairing rejected reason=inactive")`; **no rotation counting**; return (connection stays up — the operator may open the overlay next second).
4. `LANRemotePairingProof.verify(proofHex: proof, code: code, fingerprintHex: identity.fingerprint, nonceHex: nonce)`:
   - **false** → `if pairingCeremony.recordFailure() { rotateActiveCode() }` (mint fresh code, `begin`, yield `.codeRotated(newCode:)`, log `.notice("lanremote.pairing code-rotated")` — NEVER the code value); send `.error(.pairingRejected, nil)`; yield `.proofRejected(...)`. Connection stays up unless step 2's cap fires next time.
   - **true** → `pairingCeremony.consume()` (single-use); `let token = LANRemotePairingSecrets.mintToken()`; `await promoteToPaired(id: id, token: token, metadata: LANRemotePairingMetadata(name: deviceName, kind: state.remoteKind, pairedAt: now))`; yield `.paired(name: deviceName, kind: state.remoteKind)`; log `.notice("lanremote.pairing pairing -> paired (ceremony)")`.

`promoteToPaired(id:token:metadata:)` (internal): `await configuration.pairingAuthority.registerPairedToken(token, metadata: metadata)`; `state.pairingPhase = .paired(token: token)`; clear `pairingNonce`/`pairingAttempts`; `send(id:, message: .pairSuccess(token: token))`; `send(id:, message: .capabilities(IHRPCapabilities(currentState: canonicalState)))`. `completePairing(connectionId:token:)` (public, unchanged signature) becomes a thin delegate to this with default metadata — it now ALSO sends `.pairSuccess`, which is semantically correct for every completion path and harmless to PR-4's loopback tests (they `waitFor` specific frames on a stream; extra frames are skipped by the predicate).

**Ceremony public API on the actor** (all actor-isolated; UI reaches them through the coordinator):
```swift
public func beginPairing() -> String            // mints+arms a code, returns it for display
public func rotatePairingCode() -> String       // manual rotate (overlay's TTL countdown calls this)
public func endPairing()                        // disarm; parked .pairing connections stay parked
public var activePairingCode: String? { get }   // UI re-read
public nonisolated let pairingEvents: AsyncStream<TVPairingEvent>
public func sendError(_ code: IHRPErrorCode, message: String?, to connectionId: UUID)  // the bridge's reply path
public func disconnectPaired(tokenHash: String) // Settings revoke: tears down live connections whose
                                                // sha256(phase.token) == tokenHash (LANRemoteFingerprint, module-internal)
```
```swift
public enum TVPairingEvent: Sendable, Equatable {
    case remoteEnteredPairing(connectionId: UUID, kind: IHRPRemoteKind?)
    case remoteLeftPairing(connectionId: UUID)
    case codeRotated(newCode: String)      // in-process only — display, never log
    case paired(name: String?, kind: IHRPRemoteKind?)
    case proofRejected(attemptsTowardRotation: Int)
}
```
Stream + continuation are created in `TVListenerActor.init` beside `controlEvents` (same `AsyncStream.makeStream` idiom, `nonisolated let`).

**Never logged, anywhere, at any level:** the code, the token, the proof, the nonce, raw `hello` tokens — `IHLog.remote` lines carry `caseName`s and transition names only (PR-4's binding instrumentation contract). The overlay/Settings UI receive secrets through return values and `TVPairingEvent` in-process only.

---

## 4. The IHRP/1 protocol extension (exact Codable additions)

`IHRPMessage` (+`caseName` +`isControlIntent`):
```swift
/// TV → remote, sent immediately after a hello parks the connection in `.pairing` —
/// carries the per-connection nonce §3.2's proof binds to, and doubles as the
/// "show the code-entry UI now" signal for PR-7.
case pairChallenge(nonce: String)                      // caseName "pairChallenge", isControlIntent false
/// Remote → TV: the §3.2 proof + an optional user-facing device name for the
/// trusted-remotes list. NEVER logged verbatim (proof is code-derived).
case pairConfirm(proof: String, deviceName: String?)   // caseName "pairConfirm",  isControlIntent true
/// TV → remote, on ceremony success: the freshly-minted raw 32-byte token the
/// remote must persist (Keychain, synchronizable:false — PR-7). NEVER logged.
case pairSuccess(token: String)                        // caseName "pairSuccess",  isControlIntent false
```
`IHRPFrame` custom Codable: `CodingKeys` gains `proof, nonce, deviceName` (`token` already exists for `hello`; `pairSuccess` reuses it). Decoder branches (mirror the existing `jumpLine` missing-field posture exactly):
```swift
case "pairChallenge":
    guard let nonce = try container.decodeIfPresent(String.self, forKey: .nonce) else {
        throw IHRPDecodingError.missingField("nonce", forType: type) }
    self.message = .pairChallenge(nonce: nonce)
case "pairConfirm":
    guard let proof = try container.decodeIfPresent(String.self, forKey: .proof) else {
        throw IHRPDecodingError.missingField("proof", forType: type) }
    self.message = .pairConfirm(proof: proof, deviceName: try container.decodeIfPresent(String.self, forKey: .deviceName))
case "pairSuccess":
    guard let token = try container.decodeIfPresent(String.self, forKey: .token) else {
        throw IHRPDecodingError.missingField("token", forType: type) }
    self.message = .pairSuccess(token: token)
```
Encoder branches are the obvious mirrors. `IHRPErrorCode` gains `case pairingRejected` ("the pairing proof was rejected — wrong/expired code or no active ceremony; retryable on the same connection until the attempt cap").

**Version/tolerance posture (state in code comments):** the version tag stays `IHRP/1` — these are pre-release additions to a same-repo protocol whose decoder is deliberately fail-loud (`IHRPDecodingError.unknownMessageType` / an unknown `IHRPErrorCode` raw value both drop the connection). That IS the designed behaviour for build skew (`IHRPProtocolVersion`'s own doc: both ends are the same app; "these two builds must be reconciled"). "Backward-tolerant" here means: every EXISTING case's wire shape is byte-unchanged (the new CodingKeys are additive; optional-absent fields stay omitted via `encodeIfPresent`), proven by the untouched existing rows of `IHRPMessageCodecTests` continuing to pass. No released build predates PR-6, so no live-skew window exists.

---

## 5. Persistence — identity store + Keychain pairing authority

### 5.1 `LANRemoteIdentityStore` (IHLive — MUST be same-module: uses `LANRemoteIdentity`'s internal memberwise init)

```swift
public enum LANRemoteIdentityStore {
    /// The ONE fixed label the TV's persistent identity lives under.
    static let identityLabel = "app.ihymns.lanremote.identity.v1"
    /// Load the persisted identity, or generate-and-persist on first launch.
    /// Same fingerprint on every subsequent call/launch — the property a
    /// remote's pinned fingerprint depends on (#1421 acceptance criterion).
    public static func loadOrCreate(commonName: String = "iHymnsTV") throws -> LANRemoteIdentity
    /// Deletes the persisted identity (next loadOrCreate mints a new one —
    /// every paired remote's pin breaks; Settings copy must say so). Test hook
    /// + a future "Reset TV identity" affordance; NOT surfaced in PR-6 UI.
    public static func reset()
}
```
`loadOrCreate` algorithm:
1. `SecItemCopyMatching` `[kSecClass: kSecClassIdentity, kSecAttrLabel: identityLabel, kSecReturnRef: true, kSecMatchLimit: kSecMatchLimitOne]` (the exact query shape `LANRemoteIdentityFactory.queryIdentity(label:)` already uses — extract that private func to `internal static` and REUSE it, don't copy).
2. Found → `SecIdentityCopyCertificate(identity, &cert)` → `SecCertificateCopyData(cert) as Data` = DER → `fingerprint = LANRemoteFingerprint.sha256Hex(der)` → return `LANRemoteIdentity(fingerprint:certificateDER:secIdentityRef:keychainLabel: identityLabel)`.
3. `errSecItemNotFound` → `try LANRemoteIdentityFactory.generateSelfSigned(commonName: commonName, keychainLabel: identityLabel)` (the new parameter; the factory pre-deletes any stray items under an explicitly-passed label first — recovers `errSecDuplicateItem` after a half-completed prior generate).
4. Any other `OSStatus` → throw `LANRemoteIdentityError.keychainBridgeFailed(status:)` (existing case).

Synchronous, `SecItem*`-thread-safe statics; the coordinator calls it inside its async `start()` off the main actor. tvOS has no iCloud Keychain and `kSecClassKey`/`kSecClassCertificate` items don't sync regardless — the sync-ban concern (plan §6.4) applies to the generic-password items in §5.2, where it IS explicit.

### 5.2 `KeychainLANRemotePairingAuthority` (IHLive, `actor` — the `KeychainTokenStore` idiom, adapted for a LIST)

```swift
public struct LANRemotePairingMetadata: Sendable, Codable, Equatable {
    public var name: String?; public var kind: IHRPRemoteKind?; public var pairedAt: Date
}
public struct PairedRemoteRecord: Sendable, Equatable, Identifiable {
    public let tokenHashHex: String; public let metadata: LANRemotePairingMetadata
    public var id: String { tokenHashHex }
}
public actor KeychainLANRemotePairingAuthority: LANRemotePairingAuthority {
    public init(service: String = "app.ihymns.lanremote.pairedRemotes")
    public func isPairedToken(_ token: String) async -> Bool
    public func registerPairedToken(_ token: String) async                                    // default metadata
    public func registerPairedToken(_ token: String, metadata: LANRemotePairingMetadata) async
    public func revokePairedToken(_ token: String) async            // by raw token (protocol req)
    public func revoke(tokenHash: String) async                     // by hash — what Settings has
    public func listPairedRemotes() async -> [PairedRemoteRecord]   // sorted newest-first by pairedAt
}
```
**Exact `SecItem` shape** (one generic-password item per paired remote — mirrors `KeychainTokenStore.baseQuery()`, extended for multiplicity):
- `kSecClass = kSecClassGenericPassword`
- `kSecAttrService = "app.ihymns.lanremote.pairedRemotes"` (a private namespace, the `app.ihymns.token` convention)
- `kSecAttrAccount = sha256Hex(token)` — **the account IS the hash**; the raw token is hashed the moment it crosses this actor's boundary and never stored, matching `InMemoryLANRemotePairingAuthority`'s discipline and strategy §2.4.3 ("TV stores `sha256`").
- `kSecValueData = try JSONEncoder().encode(metadata)` — the metadata is NOT secret, but a Keychain item needs a data payload and co-locating it keeps list+revoke a single query surface (vs. a parallel UserDefaults map that could desync).
- `kSecAttrSynchronizable = false` — **EXPLICIT, hard-coded, never injectable** (unlike `KeychainTokenStore`'s deliberate injectability): plan §6.4's tripwire — "iCloud-synced pairing trust silently breaks the proximity model." tvOS lacks iCloud Keychain anyway; the explicit false + the §7.4 unit test make the property survive a future code motion to another platform.
- `kSecAttrAccessible = kSecAttrAccessibleAfterFirstUnlock` (`KeychainTokenStore` precedent — the listener must authorise reconnects right after a reboot).
- No `kSecAttrAccessGroup` (nothing shares these items).
- `isPairedToken`: `SecItemCopyMatching` with `kSecAttrAccount = hash`, `kSecMatchLimit = One` → `errSecSuccess` ⇒ true; `errSecItemNotFound` ⇒ false; any other status ⇒ **false + `IHLog.remote.error("lanremote.authority read-failed status=…")`** (fail-CLOSED for trust decisions).
- `register…`: delete-then-add replace semantics (the `KeychainTokenStore.save` pattern); add failure ⇒ `IHLog.remote.fault("lanremote.authority persist-failed …")` — the in-flight session still works (the connection's phase is already `.paired`), but the pairing won't survive relaunch; `.fault` is the right alarm level.
- `listPairedRemotes`: `kSecMatchLimit = kSecMatchLimitAll`, `kSecReturnAttributes = true`, `kSecReturnData = true` → array of dicts → map (`kSecAttrAccount` → hash, `kSecValueData` → JSON-decode metadata; a row that fails decode still lists with placeholder metadata rather than silently vanishing — a trust entry must never be invisible).
- Expose `internal func baseAttributes(account: String?) -> [String: Any]` so §7.4's `@testable` test can assert the `synchronizable == false` / accessible / service invariants directly.

---

## 6. tvOS wiring — coordinator, bridge, UI

### 6.1 `TVRemoteControlCoordinator` (IHFeatures, `@MainActor @Observable`)

```swift
@MainActor @Observable
public final class TVRemoteControlCoordinator {
    public struct ListenerInfo: Sendable, Equatable {           // Settings "Remote Control info" (§2.4.5)
        public let fingerprint: String; public let port: UInt16; public let ipAddress: String?
    }
    public private(set) var listenerInfo: ListenerInfo?
    public private(set) var startError: String?                 // themed inline error in Settings
    public private(set) var isPairingActive = false
    public private(set) var pairingCode: String?                // displayed by the overlay
    public private(set) var pairingRemoteCount = 0              // ".pairing" connections right now
    public private(set) var pairedRemotes: [PairedRemoteRecord] = []
    public private(set) var lastPairedName: String?             // success banner copy

    public init(projectionViewModel: ProjectionViewModel)
    public func start() async     // idempotent (guard against re-entry)
    public func beginPairing() async / endPairing() async / rotateCode() async
    public func revoke(_ record: PairedRemoteRecord) async      // authority.revoke(tokenHash:) + listener.disconnectPaired(tokenHash:) + refresh
    public func qrPayloadString(includeCode: Bool) -> String?   // LANRemotePairingPayload → overlay (with code) / Settings standing QR (without)
}
```
`start()` (the whole PR-6 "wire it into the tvOS app" step):
1. `let identity = try LANRemoteIdentityStore.loadOrCreate(commonName: deviceName)` — `deviceName` = `UIDevice.current.name` under `#if os(tvOS)`, else `"iHymns TV"` (file must compile for macOS `swift test` + watchOS).
2. `let authority = KeychainLANRemotePairingAuthority()`.
3. `TVListenerActor(identity:, configuration: .init(port: NWEndpoint.Port(rawValue: 7269)!, advertisedName: deviceName, pairingAuthority: authority, clock: SystemLANRemoteClock()))` — 7269 = strategy §2.4.5's documented default so manual connect-by-address (PR-8) is predictable. `try await listener.start(); try await listener.waitUntilReady()`; on `TVListenerError.listenerFailed` retry ONCE with `port: .any` (another app squatting 7269 must not kill the feature; log `.notice`, surface the real port in `listenerInfo`).
4. `listenerInfo = ListenerInfo(fingerprint: identity.fingerprint, port: listener.boundPort?.rawValue ?? 0, ipAddress: LANRemoteAddress.primaryIPv4Address())`.
5. Spawn and retain (for `stop()`/deinit cancellation) three `Task`s: the two bridge loops (§6.2) + the `pairingEvents` consumer (updates `pairingCode` on `.codeRotated`, `pairingRemoteCount` on entered/left, `lastPairedName` + `refreshPairedRemotes()` on `.paired`).
6. `pairedRemotes = await authority.listPairedRemotes()`.

Any thrown error → `startError = "…"` (user-facing copy; the raw error goes to `IHLog.remote.error` — status codes only, never key material).

### 6.2 The listener ↔ view-model bridge — `TVProjectionBridge` + the two loops

The mapping is a PURE static so it is unit-testable without a listener (§7.3); the coordinator's loops are the only place that touches both actors:
```swift
// TVProjectionBridge.swift (IHFeatures) — PR-5 spec §1's sketch, made real:
enum TVProjectionBridge {
    /// Applies one remote intent to the view-model; returns the error reply
    /// (if any) the listener should send back to the ORIGINATING connection.
    static func apply(_ message: IHRPMessage, to vm: ProjectionViewModel) async -> IHRPMessage? {
        switch message {
        case .prepare(let id):        _ = await vm.prepare(songId: id); return nil
        case .selectSong(let id, let c, let l):
            switch await vm.selectSong(songId: id, componentIndex: c, lineIndex: l) {
            case .shown:        return nil
            case .unavailable:  return .error(.contentUnavailable, message: nil)   // §2.4.3's exact handoff
            case .failed:       return .error(.internalError, message: nil)        // never leak the message on-wire
            }
        case .nextComponent: vm.nextComponent(); return nil
        case .prevComponent: vm.prevComponent(); return nil
        case .nextLine:      vm.nextLine();      return nil
        case .prevLine:      vm.prevLine();      return nil
        case .jumpLine(let index):     vm.jumpLine(index: index);      return nil
        case .setDisplayState(let s):  vm.setDisplayState(s);          return nil
        case .scroll(let delta):       vm.scroll(delta: delta);        return nil
        case .setAppearance(let t, let s): vm.setAppearance(theme: t, textScale: s); return nil
        default: return nil   // hello/ping/endControl/pairing = transport concerns TVListenerActor already handled
        }
    }
}
// TVRemoteControlCoordinator — the two retained loops (this IS PR-5 spec §1's "~30-line bridge"):
bridgeInTask = Task { [listener, projectionViewModel] in
    for await event in listener.controlEvents {
        if let reply = await TVProjectionBridge.apply(event.frame.message, to: projectionViewModel),
           case .error(let code, let text) = reply {
            await listener.sendError(code, message: text, to: event.connectionId)
        }
    }
}
bridgeOutTask = Task { [listener, projectionViewModel] in
    for await state in projectionViewModel.stateUpdates {      // the stream's ONE consumer (PR-5 §1 rule 3)
        await listener.updateState(songId: state.songId, componentIndex: state.componentIndex,
                                   lineIndex: state.lineIndex, displayState: state.displayState)
    }
}
```
The Siri-Remote driver (PR-5's `ProjectionSceneView`) keeps calling the view-model directly — both drivers converge on the same intents, which was the whole point of the PR-5 seam.

### 6.3 UI structure

**`TVRootView` edits:** `@State private var coordinator: TVRemoteControlCoordinator` (built in `init` beside `projectionViewModel`, taking it as the dependency); wrap the existing `TabView` in a `ZStack` whose top layer shows `TVPairingOverlayView(coordinator:)` while `coordinator.isPairingActive`; add the 4th tab `NavigationStack { TVSettingsRemoteView(coordinator: coordinator) } .tabItem { Label("Settings", systemImage: "gear") }`; extend the existing `.task` with `await coordinator.start()`. Update the header's deferred-tabs note (Home remains deferred; cite #1421).

**`TVPairingOverlayView`:** full-bleed dim scrim over the tabs; an `.ihGlassCard()` centre panel: title "Pair a remote"; the code as one giant monospaced `Text` (`.font(.system(size: 120, weight: .bold, design: .monospaced)).kerning(20)` — readable across a hall); the QR (`PairingQRCode.image(from: coordinator.qrPayloadString(includeCode: true))`, ~320pt) with caption "Scan with iHymns on your phone"; the fingerprint as a short verification line (first 12 hex chars, grouped in 4s — enough for a human cross-check; full value lives in Settings); a live "1 remote is trying to pair…" line while `pairingRemoteCount > 0`; a success flash naming `lastPairedName` when a `.paired` event lands; a focused **Cancel** button → `endPairing()`. A `TimelineView`/task-based countdown calls `coordinator.rotateCode()` at TTL so the on-screen code is never stale (the actor rejects expired codes regardless — the UI timer is cosmetics, not enforcement; say so in a comment). Accessibility: the code is one element, `accessibilityLabel("Pairing code 0 4 2 3 1 7")` digit-by-digit.

**`TVSettingsRemoteView`:** a `List` with three sections. (1) **Remote Control** — status line ("Listening on port 7269" / `startError`), IP (if `LANRemoteAddress` found one), full fingerprint (grouped, monospaced — the out-of-band value §2.4.3's trust model pins), the STANDING QR (`includeCode: false` — connect-info only, safe to leave on screen; the pairing CODE never appears here). (2) **Pair** — "Pair a remote…" button → `beginPairing()` (flips the overlay). (3) **Trusted remotes** — `ForEach(coordinator.pairedRemotes)`: name (fallback "Unknown remote"), kind icon (`iphone`/`ipad`/`laptopcomputer`/`visionpro`/`applewatch` per `IHRPRemoteKind`), paired-date; a **Revoke** button per row behind a `confirmationDialog` ("This remote will immediately lose control of this TV."). Empty state: "No remotes have been paired yet."

**`LANRemotePairingPayload`** (IHLive): `{ v: 1, name: String, host: String?, port: UInt16?, fp: String, code: String? }`, encoded `"ihymns-lanpair:v1:" + base64url(JSON)` via `encodeToQRString()` / failable `init?(qrString:)` — the exact string PR-7's scanner decodes. `code == nil` ⇒ the Settings standing QR (connect-only); `code != nil` ⇒ the ceremony QR (out-of-band cert pinning + code in one scan, strategy §2.4.3's `{name,ip,port,certFingerprint,pairingCode}`).

### 6.4 `project.yml` (D-8)

Add to `iHymnsTV.settings.base`: `INFOPLIST_KEY_NSLocalNetworkUsageDescription: "iHymns uses your local network so iPhones and iPads you pair can act as remote controls for this TV."` — **belt-and-braces, not load-bearing:** per Apple TN3179 (Local Network Privacy), *listening* for incoming connections and *advertising* a Bonjour service are NOT restricted operations; only browsing/outgoing local-network traffic trips the permission — and the TV side only listens + advertises. The restricted-side keys (`NSBonjourServices` array + usage description on the `iHymns` target for `NWBrowser`) are PR-7's, where the `info:`-block mechanism (the `iHymnsWidgets` precedent) will be needed for the array-typed key. Run `xcodegen generate` after editing.

---

## 7. Test plan (Swift Testing; injected clock everywhere; secrets never printed in test output either)

### 7.1 `LANRemotePairingProofTests` (always-on)
- Shape: `compute` returns 64 lowercase hex; deterministic (same inputs ⇒ same output); differs on any single input change (code / fingerprint / nonce).
- **GOLDEN VECTOR:** hard-code one full input set + its expected proof hex (compute once while building, paste literally, comment "freezes the KDF+HMAC construction — if this fails you changed the crypto, which breaks every already-paired remote in the field; bump the payload/protocol version instead").
- `verify`: accepts its own `compute`; rejects wrong code / wrong fingerprint / wrong nonce / truncated proof / odd-length hex / non-hex / empty; **constant-time property is enforced structurally** (the implementation MUST route through `HMAC<SHA256>.isValidAuthenticationCode` — assert behaviourally with equal-length-wrong-value inputs, and treat any `==`-comparison of proof strings found in review as a reject; a timing benchmark in CI would be noise, not signal).
- `LANRemotePairingSecrets`: `mintCode` is 6 digits zero-padded (inject `random: { _ in 7 }` ⇒ `"000007"`); `mintToken` 64 hex; `mintNonce` 32 hex; two mints never equal.

### 7.2 `LANRemotePairingCeremonyTests` (always-on, pure — `Date(timeIntervalSince1970:)` literals, no clock object needed)
- `begin` → `isActive(now)` true; `isActive(now + 119.9)` true; `isActive(now + 120.1)` **false** (TTL boundary).
- `consume` → inactive immediately (single-use).
- `recordFailure` ×4 → false each time, `wrongAttempts == 4`; 5th → **true** (rotation signal); after a fresh `begin`, `wrongAttempts == 0`.
- `end` → inactive; `begin` after `end` re-arms.
- Configuration defaults are the strategy numbers (120 / 5 / 3) — asserted literally so a drive-by "tune" shows up in review.

### 7.3 `TVProjectionBridgeTests` (always-on, `@MainActor`, `IHFeaturesTests` — fetcher-injected `ProjectionViewModel`, the `ProjectionViewModelTests` precedent; NO network, NO listener)
- `.selectSong` with a succeeding fetcher → returns nil, `vm.projectionState.songId` set.
- fetcher throws `APIError.unauthorized` → returns `.error(.contentUnavailable, message: nil)` (the §2.4.3 handoff, end-to-end).
- fetcher throws `.offline` → `.error(.internalError, message: nil)` and **no** user-facing string on the wire.
- `.nextLine`/`.setDisplayState(.blackout)`/`.scroll(delta:)`/`.jumpLine` mutate the VM exactly as calling it directly would.
- `.ping`/`.hello`/`.endControl`/`.pairConfirm` → nil, VM untouched (transport concerns never reach the VM).

### 7.4 Keychain-gated suites (`.enabled(if: lanRemoteKeychainIdentityAvailable(), …)` — the PR-4/#1522 precedent, same skip message style; every test cleans up in `defer`)
- `LANRemoteIdentityStoreTests`: `loadOrCreate()` twice ⇒ SAME fingerprint + SAME certificateDER (the load-bearing #1421 property); `reset()` then `loadOrCreate()` ⇒ DIFFERENT fingerprint; `makeServerTLSOptions()` succeeds on a LOADED (not just generated) identity.
- `KeychainPairingAuthorityTests`: register → `isPairedToken` true; unknown token false; revoke → false; `revoke(tokenHash:)` (hash-addressed) works; `listPairedRemotes` returns the metadata round-tripped (name/kind/pairedAt) newest-first; **hash-at-rest**: after registering token `T`, `listPairedRemotes` contains `sha256Hex(T)` and NO item's account equals `T`; **`baseAttributes` asserts `kSecAttrSynchronizable == false`** and `kSecAttrAccessible == AfterFirstUnlock` (plan §6.4's required unit test).

### 7.5 `TVListenerPairingLoopbackTests` (`IHYMNS_LAN_LOOPBACK_TESTS=1` gate, verbatim from `LANRemoteLoopbackTests` incl. `advertiseViaBonjour: false`, `.hostPort` connects, `ManualLANRemoteClock` injected into the listener `Configuration` — the clock the ceremony TTL reads)
Test-double: `PairingTestRemote` (tests only) wrapping a real `RemoteSessionActor`: `connect(to:expectedFingerprint:)` with `token: nil` → `waitFor(incomingMessages) { .pairChallenge }` → computes proof via **the same public `LANRemotePairingProof.compute`** (this IS the PR-7 client recipe — say so in its header) → `send(.pairConfirm(proof:deviceName:))` → `waitFor { .pairSuccess }` → returns the token. An `invalidProof:` flag substitutes a garbage proof.
- **Happy path:** `beginPairing()` on the listener → double pairs → receives `.pairSuccess` + `.capabilities`; `pairedConnectionCount == 1`; `remote.currentPairingToken` == the token; disconnect, reconnect with `hello(token:)` → straight to `.controlling` (persistence across the authority, using `InMemoryLANRemotePairingAuthority` here — the KEYCHAIN authority is §7.4's concern; loopback proves the protocol).
- **Wrong proof:** rejected with `.error(.pairingRejected)`; connection still open (remote NOT disconnected — the `.pairingRejected`-vs-`.unauthorized` distinction under test); 3rd wrong attempt on one connection → torn down.
- **Rotation:** 5 wrong proofs (across ≤2 connections) → `pairingEvents` yields `.codeRotated`; old code now rejected, new code (read via `activePairingCode`) pairs.
- **Expired code:** `beginPairing()`, `ManualLANRemoteClock.advance(by: 121)`, valid-code proof → `.pairingRejected` and `wrongAttempts` NOT advanced toward rotation.
- **No ceremony:** proof without `beginPairing()` → `.pairingRejected`, connection survives.
- **Paired-connection injection:** a PAIRED double sends `.pairConfirm` → `.error(.malformedRequest)` reply AND no `ControlEvent` is yielded (guards the `handlePaired` reject-list edit — assert via a short-timeout expectation that `controlEvents` stays silent).
- Codec: the 4 new rows in `IHRPMessageCodecTests` (always-on) cover the wire shapes; `LANRemotePairingPayload` round-trip (encode → init?) + reject-garbage tests are always-on too (add to the payload's own small always-on suite or fold into `LANRemotePairingCeremonyTests`' file if LOC allows).

Local pre-PR verification (builder runs all): `swift test` in `appApple/Packages/iHymnsKit` (522+PR-5 existing + new, green) · `IHYMNS_LAN_LOOPBACK_TESTS=1 swift test --filter Pairing` on the dev Mac · `swiftlint --config appApple/.swiftlint.yml appApple` (0) · `bash appApple/Scripts/loc-budget.sh` · `xcodegen generate` + the macOS AND tvOS xcodebuild steps from `apple.yml`.

---

## 8. Threat model (acceptance criteria) + decisions

| Threat | Mitigation (mechanism, in THIS PR) | Residual |
|---|---|---|
| **MITM / relay during pairing** (attacker terminates TLS between remote and TV) | QR path: fingerprint pinned out-of-band → PR-4's `verify_block` rejects at handshake. Manual-code path: the proof HMACs the fingerprint the remote actually observed (§3.2) — the attacker cannot present the TV's cert, so a relayed proof binds to the WRONG fingerprint and the TV's constant-time verify rejects it. | An attacker who physically swaps the venue's displayed QR pairs the remote with the ATTACKER's device (never grants access to the real TV). Physical-space attack, out of scope; the operator sees no "Paired" banner — noted in Settings copy. |
| **Brute-force of the 6-digit code** | Online-only (the proof exists solely inside TLS; never logged, never persisted): 3 proof attempts per connection → teardown; 4 concurrent unpaired connections (PR-4 cap); 5 wrong proofs per code → rotation; **★ POST-REVIEW FIX — a CUMULATIVE `maxCeremonyFailures` (15) ceiling across ALL rotations → the whole ceremony is disarmed (`.ceremonyExhausted`), and only a fresh operator-initiated `beginPairing()` re-opens it**; 120 s TTL; single-use; ceremony exists ONLY while the operator has the overlay open. Worst case ≤15 guesses against 10⁶ ⇒ ≤0.0015% per ceremony. | The code is knowledge-limited, not entropy-limited, by design (10-foot readability). **★ The ORIGINAL spec text here wrongly claimed "≤5 guesses / TTL auto-rotation bounds each code" — the adversarial review (2026-07-11) found rotation to a fresh RANDOM code gives a uniform-guessing attacker ZERO benefit and never STOPS the ceremony, so without the cumulative ceiling total guesses = rate × overlay-open-time (unbounded). The `maxCeremonyFailures` ceiling is the actual bound; the sticky `ceremonyFailures` counter survives TTL re-arms and intervening successful pairs, so even a "left the overlay open" operator caps an attacker at 15 total guesses per opened ceremony.** |
| **Replay of a captured proof** | TLS 1.3 confidentiality (nothing on-path sees it) + per-connection nonce in the MAC (§3.2) + single-use code consumption. A proof is valid for exactly one (code, nonce, fingerprint) triple on one connection. | None meaningful. |
| **Photographed / leaked ceremony QR** (contains the code) | 120 s TTL + single-use + operator-visible outcome: every success fires the `.paired(name:)` banner and lands in the trusted-remotes list; revoke is one click and also drops live connections (`disconnectPaired(tokenHash:)`). The STANDING Settings QR carries NO code. | A photo used within the TTL, on the venue LAN, while the operator isn't watching, yields a trusted pairing until noticed and revoked. Mitigated by visibility, not prevented — documented honestly (this equals the risk of the operator reading the code aloud). |
| **Malicious remote flooding `.pairing`** | PR-4's `maxUnpairedConnections` (4) bounds slots; per-connection attempt cap (3) bounds proof traffic; nonce minting is 16 cheap random bytes; 4 KB frame cap + protocol confinement unchanged; a `.pairConfirm` before `hello` is an instant teardown. | An attacker can hold the 4 unpaired slots (pairing-availability DoS on the LAN). Accepted: LAN-local, no memory growth, visible in logs (`unpaired-cap`); PR-4 already accepted this envelope. |
| **Token theft at rest (TV)** | Keychain stores `sha256(token)` only (§5.2) — a stolen backup/hash yields no replayable credential (the `hello` fast-path needs the preimage). `synchronizable:false` explicit + unit-tested (plan §6.4). | Remote-side raw token custody is PR-7's (Keychain, non-sync — contract stated in §1). |
| **Paired remote injecting pairing frames** | `handlePaired` reject-list gains all 3 new cases — `.pairConfirm` can never surface as a `ControlEvent` (§7.5's dedicated test). | None. |
| **Identity reset / key extraction** | Persistent P-256 private key lives in the data-protection Keychain (software key; extraction requires device compromise, at which point the attacker IS the TV). `reset()` deliberately un-surfaced in UI this PR. | A future "factory reset identity" UI must warn it breaks every remote's pin (documented on `reset()`). |
| **Log leakage** | Codes/tokens/proofs/nonces NEVER interpolated into `IHLog` at any level (grep-able review gate: the only `IHLog` args in new files are `caseName`s, statuses, counts, and the PUBLIC cert fingerprint). Device names log `.private` (PR-4 discovery convention). | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — HMAC channel-binding over HKDF-derived key; no PAKE.** Rationale + rejected alternative in §3.2. The golden-vector test freezes it.
- **D-2 — Proof rejection uses a NEW `.pairingRejected` error code**, because the merged `RemoteSessionActor` hard-disconnects on `.error(.unauthorized)` (verified, `RemoteSessionActor+Connection.swift:225-228`) and a code typo must be retryable in-place. `.unauthorized` keeps meaning "confinement violation."
- **D-3 — Success = proof alone; no second "Allow" click on the TV.** The operator's confirmation IS opening the ceremony (code exists only then) + reading the code to/showing it at the person pairing (strategy §2.4.3: "pairing screen operator-initiated"). A second confirm would add a remote-side stall for no threat-model gain (anyone who has the live code already had operator cooperation). `completePairing(connectionId:token:)` remains as the manual/test confirmation hook. Alternative (explicit per-remote Allow) noted for Audit B.
- **D-4 — `IHRP/1` version tag unchanged** for the 3 additive cases (§4's skew posture: fail-loud is the design; no released build exists to skew against).
- **D-5 — Expired/absent code rejections do NOT count toward rotation** (§3.4 step 3) — rotation exists to burn guessed-at codes, and letting dead-code spam farm rotations would let an attacker churn the code while the operator is mid-read.
- **D-6 — Metadata lives in the Keychain item's `kSecValueData`**, not a parallel store — one query surface for list/revoke; a desynced sidecar map would be a phantom-trust bug factory.
- **D-7 — The pure ceremony state is a `struct` owned by the actor**, not its own actor — it crosses no isolation boundary (the `IHRPFrameDecoder` precedent), and pure `now:`-parameterised functions are what make §7.2 deterministic.
- **D-8 — TV-side Info.plist key is belt-and-braces** (TN3179: listen/advertise unrestricted); PR-7 owns the load-bearing browse-side keys. If on-device testing shows tvOS 26 prompting anyway, the key is already in place.
- **D-9 — QR via CoreImage `CIFilter.qrCodeGenerator`, zero dependencies**; `#if canImport(CoreImage)` because IHFeatures also compiles for watchOS (no CoreImage there). Correction level `"M"`; scale via `CGAffineTransform` before `CIContext.createCGImage` (crisp at 320 pt).
- **D-10 — Port 7269 with one `.any` retry** (§6.1) — predictable for §2.4.5's manual path, resilient to squatters; the Settings screen always shows the TRUE bound port.
- **D-11 — Scope tripwires:** no remote-side pairing UI, no QR *scanner*, no TOFU/manual-address connect (PR-7/PR-8), no `service_broadcast` mirror (PR-14), no watch relay (PR-11), no iCloud/`synchronizable:true` ANYWHERE in LANRemote (grep gate), no new external package. Any `import Network` in a new IHFeatures file other than nothing-at-all is a review reject (the coordinator talks to actors, not sockets; `NWEndpoint.Port` construction is allowed via `IHLive`'s re-exported types — if the compiler demands `import Network` for the port literal, construct the port inside `TVListenerActor.Configuration`'s defaulting instead and keep IHFeatures Network-free).
- **Security notes for the PR body:** new credential classes introduced (pairing code/token/proof) — all custody rules above; no backend contact; lyric content still only crosses the TV's own authed `APIClient`; §8 table reproduced in the PR body; Audit B (plan §2's gate) re-reviews this PR before external TestFlight.

## 9. Commit plan (one PR, atomic — each commit compiles + tests green)

1. `feat(apple): IHRP/1 pairing sub-protocol + pure ceremony/proof core (#1421)` — `IHRPMessage`/`IHRPFrame`/`IHRPPayloads` edits, `LANRemotePairingProof.swift`, `LANRemotePairingCeremony.swift`, `LANRemotePairingPayload.swift`, codec-test rows, §7.1/§7.2 suites.
2. `feat(apple): persistent TV identity store + Keychain pairing authority (#1421)` — `LANRemoteIdentityStore.swift`, `KeychainLANRemotePairingAuthority.swift`, factory `keychainLabel:` param + accessible attrs, authority-protocol metadata method, §7.4 gated suites.
3. `feat(apple): TVListenerActor pairing ceremony wiring + events + loopback E2E (#1421)` — `TVListenerActor+Pairing.swift`, `+Messages`/`+Connections`/core edits, `RemoteSessionActor` token capture, `LANRemoteAddress.swift`, `PairingTestRemote` + §7.5 suite.
4. `feat(apple): tvOS pairing overlay + trusted-remotes Settings + projection bridge (#1421)` — coordinator, `TVProjectionBridge` + §7.3 tests, both views, `PairingQRCode`, `TVRootView` edits, `project.yml`.
