# Apple Phase-2 PR-11 (POCKET-CONTROL redesign) — Watch relay with background wake-and-reconnect (#1423)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-13). SUPERSEDES `.claude/apple-phase2-pr11-spec.md`** (the foreground-only relay design, never built) — the owner directed "redesign for pocket-control first": the Apple Watch must drive the venue TV **while the iPhone is backgrounded / locked / in a pocket**, not only while the iPhone is foreground on the Remote screen. This spec is self-contained; a Sonnet builder reads THIS file only (the old spec stays on disk as design history — where a section here says "unchanged from the old spec" the text is REPRODUCED here anyway). Sibling of `apple-phase2-pr6-spec.md` (TV side, MERGED #1529), `apple-phase2-pr7-spec.md` (remote client, MERGED #1550 `c7e987e9`), `apple-phase2-pr8-spec.md` (manual connect/TOFU, MERGED #1553 `4e884cdf`). Grounded in a fresh code-level read of `alpha` HEAD (`3d0f50d2`): `Sources/IHLive/LANRemote/*`, `Sources/IHFeatures/RemoteControl*`, `project.yml`, `.github/workflows/apple.yml` (post-#1556 package-cross-compile shape). Target branch: **`feat/apple-p2-pr11-pocket`** off `alpha`; ONE PR targeting `alpha` (§9 justifies). **CI's `apple.yml` is NOT a required check (#1526) — every §7 verify command MUST be run locally before the PR opens, including the watchOS package cross-compile (post-#1549 it MUST stay green).**
>
> **Why this PR needs a fresh-context Opus security review (unlike the old foreground-only design):** it introduces a LAN session that runs **headless, in the background, on a possibly-locked phone, triggered by a WatchConnectivity wake** — a genuinely new session-lifetime owner on top of PR-7's security-reviewed session. The mitigating headline (verified §1.2 fact 3): the pinned-TLS/pairing/token custody code is **byte-untouched** — `Sources/IHLive/LANRemote/` has **ZERO diff** in this PR. The new owner drives the EXISTING `RemoteControlSession` public API only.

---

## 1. Scope + the pocket-control contract

**What PR-11 builds (issue #1423 + the pocket-control redesign; strategy §2.4.2 remains BINDING for the transport shape):** the Apple Watch as a REDUCED remote (Next/Prev component, Lyrics, Blackout, Logo, status) for the LAN-direct TV remote, relayed through the paired iPhone — **and the phone-side plumbing that makes the relay work with the iPhone backgrounded**: a watch `sendMessage` **wakes the iHymns iOS app in the background**; a new **headless session driver** reconnects the last-connected saved TV over PR-7's pinned fast path (saved token + pinned fingerprint + last-good address, `hello(token:)`, <1 s typical), forwards the command, relays state back, and **idle-disconnects** after a short inactivity window. The phone never holds a persistent background connection — it reconnects per command-burst inside the background execution window the WatchConnectivity wake grants (wake-and-reconnect-on-demand, the iOS-idiomatic shape; §6.2 states the honest capability matrix).

**What PR-11 does NOT build (tripwires — reviewer rejects on sight):**
- Any `import Network` / `NWConnection` / `NWBrowser` / Bonjour reachable from watchOS code — the watch NEVER touches the network (watchOS restricts Network.framework/Bonjour for third-party apps; strategy §2.4.2 rejected MultipeerConnectivity for this class of reason). The watch relays; the phone connects.
- ANY diff under `Sources/IHLive/LANRemote/` — no pairing/TOFU/Keychain/token-custody change, no new `RemoteControlSession`/`RemoteSessionActor`/`TVListenerActor` API, no change to the pinned connect path. The headless driver is a NEW CALLER of the existing public session API (§1.2 fact 3).
- Any pairing action reachable from the watch: no code entry, no TOFU confirm, no forget/revoke, no `selectSong`. A headless burst that unexpectedly lands in a ceremony (stale/revoked token → `.pairChallenge`) is CANCELLED, never completed (§4.2 event table; §8 row 1).
- A second consumer of any ONE session instance's `events` stream, or of `RemoteSessionActor.phaseUpdates`/`.incomingMessages` (§1.2 fact 6 — the rule is per-INSTANCE; the driver owns its OWN session instances and is the single consumer of each).
- Auth-token mirroring to the watch Keychain (strategy §2.2.1's separate future feature) — NO credential of any kind crosses the watch link (D-10). Tripwire: `IHAuth` imported by no new file.
- Lyric text or song titles over WatchConnectivity (the IHRP "~200-byte navigation intent, never rendered lyric text" invariant — `IHRPPayloads.swift:82` — extends across this hop).
- `transferUserInfo` for commands / any cross-wake command queueing (revised D-8, §3.6 — a blackout that fires minutes late when the phone comes back in range is a live-service hazard; live-or-honest-failure only).
- A declared `UIBackgroundModes` addition, new entitlement, or any `project.yml`/`apple.yml` change (§6.4 resolves: NONE needed — verified honestly, not assumed).
- Any new external package.

### 1.1 The pocket-control flow, end to end (BINDING)

```text
Watch (watchOS)                   iPhone (iOS — BACKGROUNDED, screen off in a pocket)          TV (tvOS, UNTOUCHED)
───────────────                   ─────────────────────────────────────────────────            ────────────────────
WatchRemoteView tap "Next"
 WatchRemoteController.send(.nextComponent)
 WCSession.sendMessage(["c": Data],   ──►  OS wakes/launches iHymns in the BACKGROUND
   replyHandler:, errorHandler:)           PhoneRelayDelegate (installed in IHymnsApp.init —
                                             runs on EVERY launch path, incl. this one)
                                           → ordered AsyncStream → PhoneWatchRelayService (@MainActor)
                                           → beginBackgroundTask (command bracket, §4.4)
                                           → WatchRelayCodec.decodeCommand            (§3.2, pure)
                                           → RemoteControlRelayHub.handle             (§3.4)
                                              route = WatchRelayRules.route(cmd,
                                                        coordinatorPhase)             (§3.3, pure)
                                              = .headless  (no foreground session)
                                           → HeadlessRelayDriver.perform(cmd)         (§4.2)
                                              • store.listPairedTVs().first  = last-connected PairedTVRecord
                                              • RemotePairingEntryResolver.resolve(.savedRow(record),
                                                  saved: records, discovered: [])  → pinned fast-path target
                                              • fresh RemoteControlSession.attach(to:) ──────►  TLS 1.3, pinned fingerprint,
                                              • events: .connecting → .controlling  ◄──────     hello(token:) fast path
                                                (<1 s typical; 4 s hard deadline)               (TVListenerActor+Messages.swift:60-67)
                                              • coordinator-free sendIntent via its OWN session ►  IHRP .nextComponent
                                              • reply(.forwarded, snapshot)
                                           replyHandler(["r": Data]) ◄─────────────────
 ◄── reply {outcome, snapshot} ──────      → linger: idle timer (default 20 s) armed;
 snapshot → watch UI                          TV echoes stream to the watch meanwhile ◄──  .state broadcast (echo)
 (subsequent taps within 20 s:                push: sendMessage-if-reachable +
  same live session, ~100-300 ms)             updateApplicationContext-always          (D-7)
                                           → idle deadline (or bg-budget expiry):
                                              session.endControl() + stop()  ─────────►  clean .endControl, connection closes
                                              publish .standby → watch shows "Ready"
```

Two channels, deliberately (unchanged from the old design): **commands are request/reply** (the watch always learns synchronously what its tap did — including "connecting…" latency up to the 4 s deadline on a cold wake), **state is push** (the TV's echo drives the watch the way it already drives the phone's stateless/reflective surface — `RemoteControlSurfaceView.swift`'s contract, one hop longer).

### 1.2 Facts about the merged seam this spec builds on (each verified in code on `alpha` `3d0f50d2` — cite these in file headers)

1. **The coordinator is SCREEN-SCOPED and stays that way.** `RemoteControlView` owns it as `@State` (`RemoteControlView.swift:23`), `start()`s it in `.task` (`:37`), `stop()`s it `onDisappear` (`:38`), and wires `scenePhase` → `setScenePhaseActive(_:)` (`:39-41`) → `RemoteControlSession.setSuspended(!active)` (`RemoteControlCoordinator.swift:258-260`). This PR does NOT un-scope it — pocket control comes from a SECOND, headless owner, not from making the screen-scoped one immortal.
2. **Backgrounding tears the foreground link down BY DESIGN and resume is fast:** `setSuspended(true)` sends `.endControl`, disconnects, yields `.suspended` (`RemoteControlSession+Link.swift:347-372`); `setSuspended(false)` reconnects with the kept target — `backoff.reset()`, a 20 ms settle, `connect(...)` with the saved token (`:374-398`) — the "<1 s resume" budget PR-7 shipped. The suspended session holds **no TCP connection** — which is exactly what makes a concurrent headless session to the same TV safe at the transport level while the app is backgrounded.
3. **The pinned fast path needs NOTHING new.** `RemotePairingEntryResolver.resolve(.savedRow(record), saved:, discovered: [])` (public, `RemotePairingEntryResolver.swift:169-178` → `resolveSavedRow` `:290-314`) returns a `RemoteConnectTarget` whose `endpoint` is the record's `lastAddress`, `expectedFingerprint` the pinned fingerprint, `token` the saved raw token (`PairedTVRecord.token` is non-optional — `PairedTVStore.swift:49`), `knownCode: nil`. `token != nil` ⇒ `attach(to:)` arms NO ceremony (`RemoteControlSession.swift:243-247`); the TV's `hello(token:)` fast path promotes straight to `.paired` + `.capabilities` (`TVListenerActor+Messages.swift:60-67`). **TOFU is structurally unreachable from a saved record** — the driver can never present a nil token or an unpinned connect.
4. **Keychain custody works on a LOCKED phone (after first unlock):** `KeychainPairedTVStore.baseAttributes` hard-codes `kSecAttrAccessible = kSecAttrAccessibleAfterFirstUnlock` (`KeychainPairedTVStore.swift:169`) and `kSecAttrSynchronizable: false` (`:165`, load-bearing, never touched). So the background wake on a locked-but-once-unlocked phone CAN read the saved TV record. A never-unlocked-since-boot (BFU) phone cannot — reads fail → `listPairedTVs()` returns `[]` → the honest §5 "Open iHymns on your iPhone" watch status (§6.2 row 6; accepted, rare).
5. **A fresh-attach failure is TERMINAL, not a ladder** — `handleIdleOrFailed`'s `guard hasEverControlled` yields `.pairingEnded(.connectionTornDown)` and stops (`RemoteControlSession+Link.swift:104-115`). Battery-critical for wake-connects: a dead/absent TV costs ONE failed connect per watch tap, never a background retry loop.
6. **The single-consumer rule is per session INSTANCE, two-layered:** `RemoteSessionActor.phaseUpdates`/`.incomingMessages` are consumed ONLY by that session's own `+Link.swift` loops (`RemoteControlSession.swift:28-35`, BINDING), and each session's `events` stream has exactly ONE consumer. The coordinator already builds a FRESH session per `start()` (`RemoteControlCoordinator.swift:63-87` doc + `:151`) — multiple instances over time are the established pattern. The driver owns its OWN instances and spawns ONE events consumer per instance (§4.2); it never touches the coordinator's session or streams.
7. **A drop AFTER controlling starts the reconnect ladder** (`+Link.swift:117-130`, `.detached` then `.reconnecting(attempt:delay:)` forever-jittered). The driver treats `.detached` as terminal for its burst — `stop()` the session, fail pending commands, back to standby (§4.2) — so the ladder NEVER churns in the background (D-16).
8. **A stale/revoked token drops the fast path into the ceremony** — `.pairChallenge` lazily arms a flow and yields `.awaitingCodeEntry` (`+Link.swift:192-215`). Headless handling: `cancelPairing()` + a `repairNeeded` failure — pairing is a foreground, human, on-iPhone act, always (§4.2; §8 row 1).
9. **The TV tolerates overlapping paired connections.** Only UNPAIRED connections are capped (`TVListenerActor+Connections.swift:76-83`); `broadcast` sends to every `.paired` connection (`TVListenerActor+Messages.swift:155-159`); command conflict is last-writer-wins by seq+timestamp (`:144-150`). So a ≤1 s takeover race (headless still closing while the foreground attaches, or vice versa) degrades gracefully at the TV — the §4.3 single-owner arbitration is for phone-side coherence, traffic hygiene and battery, not TV correctness. State this honestly; do NOT rely on it as the primary mechanism.
10. **`UIPhase.controlling(tvName:state:)` carries the full `IHRPState`** (`RemoteControlCoordinator+UIPhase.swift:29-43`) — songId, componentIndex, lineIndex, displayState, revision. Everything the watch may see already surfaces through `uiPhase` (foreground) or `RemoteControlSession.Event.controlling` (headless).
11. **`coordinator.sendIntent(_:)` / `session.sendIntent(_:)` is the one intent door**, guarded by `message.isControlIntent` + `assertionFailure` (`RemoteControlCoordinator.swift:263-265`; `RemoteControlSession.swift:280-286`; the intent list `IHRPMessage.swift:220-229`). The reduced set maps 1:1 onto `.nextComponent`/`.prevComponent`/`.setDisplayState(.lyrics/.blackout/.logo)` (`IHRPDisplayState`, `IHRPPayloads.swift:64-69`) — all `isControlIntent == true`, asserted in §7.1.
12. **`RemoteControlCoordinator.swift` is at 397 raw lines** (cap 400, `Scripts/loc-budget.sh:15`) — the tap/hook lines CANNOT land in place. The `+Persistence.swift` pure relocation of `persistPaired`/`touchLastConnected` (`RemoteControlCoordinator.swift:363-392`) creates the headroom (the PR-8 `+UIPhase.swift` byte-identical-move precedent).
13. **The watch shell renders `PhaseZeroSkeletonView` today** (`IHymnsWatchApp.swift:38-44`), links `[IHFeatures, IHDesign]` (`project.yml` iHymnsWatch target), embeds into the iOS variant only (`project.yml:182-184`, `destinationFilters: [iOS]`). `IHFeatures` compiles for watchOS since #1549 (`e8b91358`) — every new view must be watchOS-clean BY DESIGN (no `Menu`, `.segmented`, `DisclosureGroup`, drag/drop, `.keyboardShortcut`, `ToolbarItemPlacement.navigation`).
14. **WCSession needs NO entitlement and NO Info.plist key** (Apple: `WCSession.isSupported()` + delegate + `activate()`); `isSupported()` is false on iPad/Mac/tvOS/visionOS, so the phone shell is `#if os(iOS) && canImport(WatchConnectivity)`-gated AND runtime-guarded. The iOS target's existing `INFOPLIST_KEY_UIBackgroundModes: audio` (`project.yml:98`) is untouched — WatchConnectivity has no background mode to declare (§6.4).
15. **CI shape (post-#1556, `3d0f50d2`):** `apple.yml` runs SwiftLint, package `swift test`, a macOS app-scheme build, **iOS + watchOS PACKAGE cross-compiles** (`apple.yml` "Build iHymnsKit for iOS/watchOS Simulator" steps — the app-scheme iOS build was REMOVED because embedding the watch app needs the ~15 GB watchOS platform runtime on the runner), and a tvOS app build. §7.4 mirrors it locally and ADDS the local iOS app-scheme build (the builder's Mac has the platforms; CI can't).
16. **The concurrency-bridge idiom to copy is `SessionController.stateUpdates`** (`IHAuth/SessionController.swift:80-105` — a `nonisolated` `AsyncStream` fed by a continuation): callback-world yields Sendable values into ONE ordered stream with ONE consumer. Both WCSession delegate shells use exactly this (§4.5) — never `Task {}`-per-callback (two rapid Next taps must not reorder).
17. **The headless session correctly presents as the PHONE:** `RemoteDeviceIdentity.kind` on iOS returns `.phone`/`.pad` (`RemoteDeviceIdentity.swift:34-45`); `IHRPRemoteKind.watchRelay` (`IHRPPayloads.swift:44`) stays dormant (D-1) — the TV's trusted-remotes list shows the device that paired and is connecting, which IS the phone.
18. **The injected-time idiom:** `LANRemoteClock` is `now()`-only (`LANRemoteClock.swift`); sleep intervals are injected `Duration` tunables on a `Configuration` (`RemoteControlSession.Configuration`, `RemoteControlSession.swift:54-71` — millisecond values in tests). `HeadlessRelayDriver.Configuration` copies this exactly; the PURE `HeadlessRelayPolicy` takes explicit `Date`s so its tests need no sleeping at all.
19. **A full loopback E2E is possible without WCSession or devices:** `TVListenerActor` is constructible in tests with `InMemoryLANRemotePairingAuthority` + `boundPort` (`TVListenerActor.swift:50-214`; harness precedent `Tests/IHLiveTests/LANRemote/RemoteControlSessionLoopbackTests.swift:57-119`). §7.2's driver loopback suite proves wake-connect → forward → idle-disconnect headlessly on the macOS test host.
20. **Surface parity strings:** the phone surface shows `state.songId?.rawValue ?? "Nothing selected"` (`RemoteControlSurfaceView.swift:84`) and labels "Previous verse"/"Next verse" (`:108`/`:116`) — the watch reuses both verbatim (D-6).
21. **Widgets never see this code:** `iHymnsWidgets` links `[IHDesign]` only (`project.yml` widgets target) — but `PhoneWatchRelayService` still carries `@available(iOSApplicationExtension, unavailable)` because it touches `UIApplication.shared` background tasks (§4.4), future-proofing against any extension ever linking IHFeatures.

### 1.3 The WatchConnectivity background-wake reality (RESOLVED — the honest capability statement the whole redesign stands on)

- **`sendMessage(_:replyHandler:errorHandler:)` sent FROM the watch app (foreground-active on wrist) DOES wake the iOS app.** Apple's `WCSession.sendMessage` documentation states it directly: calling it from the watch side "wakes up the corresponding iOS app in the background" when the counterpart is reachable. From the watchOS side, `isReachable` requires only that the paired iPhone is powered on and within Bluetooth/Wi-Fi range — **the iOS app does NOT need to be running or foreground**. This is the load-bearing OS contract for pocket control, and it is a real, documented one.
- **A LOCKED iPhone works** — iOS launches/resumes apps in the background while the device is locked, provided the device has been **unlocked at least once since boot** (AFU). Our Keychain records are `AfterFirstUnlock` (fact 4), so the reconnect credentialing works locked. **Before first unlock (BFU — phone rebooted at the venue and never unlocked): the wake and/or the Keychain read fails** → the watch shows the honest §5 failure copy. Accepted residual, stated in the PR body.
- **Background runtime:** the wake grants a short, UNDOCUMENTED execution window. We do not rely on its length: every command is bracketed in `UIApplication.beginBackgroundTask` (officially ~30 s, observable via `backgroundTimeRemaining`), the command reply is bounded at **4 s** (connect deadline) and the linger window at **20 s default**, and the background task's `expirationHandler` forces an immediate clean disconnect (§4.4). Each new watch command arrives as a NEW WCSession delivery — re-waking/re-bracketing as needed.
- **Force-quit (user swiped iHymns away in the app switcher):** iOS's general rule is that a user force-quit suppresses background launches; whether WatchConnectivity is exempt is NOT documented and community evidence is mixed. **Design posture: assume it does NOT relaunch.** Either way the watch degrades honestly — the `errorHandler`/reply-timeout path shows "Open iHymns on your iPhone." §7.3 has a device row to OBSERVE the actual behaviour and record it in the PR body; no design element depends on the answer.
- **Phone out of range / off / Bluetooth+Wi-Fi dead:** `isReachable == false` on the watch and a live `sendMessage` fails fast with `WCErrorCode.notReachable` → the watch shows "iPhone not reachable." **No queueing** (§3.6): `transferUserInfo` is deliberately NOT used for commands — a queued Blackout firing minutes later when the phone comes back in range is a live-service hazard worse than an honest failure. (This deliberately REJECTS the "queue via transferUserInfo" fallback option: status-only is the correct fallback for a live remote.)
- **Verdict: WatchConnectivity's wake is STRONG ENOUGH for the pocket-control goal** — backgrounded and locked-AFU phones are the normal pocket case and both are covered by documented behaviour. The honest exclusions are BFU, force-quit (assumed), and out-of-range — each gets explicit watch-side copy, never a mystery spinner.

---

## 2. Files — new and edited

All new files ≤400 raw lines (`appApple/Scripts/loc-budget.sh`, default budget 400 — split early to leave room for the two-register ELI5+DETAILED comments). SwiftLint clean (`appApple/.swiftlint.yml`). Every file header references **#1423**, strategy **§2.4.2**, and **the pocket-control redesign (this spec)**. Swift 6 strict concurrency (`Package.swift:332-333` — `.swiftLanguageMode(.v6)` + `StrictConcurrency`); `AsyncStream`, never Combine. Observability: `IHLog.remote`, transitions/counts/case-names only, `.public` for enums/booleans, `.private` for any device/TV name, NEVER a token/fingerprint/address.

### New — module `IHLive` (`Sources/IHLive/WatchRelay/` — NEW sibling folder beside `LANRemote/`; same module so it references `IHRPMessage`/`IHRPDisplayState`/`PairedTVRecord` directly, but a distinct folder because this family never imports Network)

| File | Purpose (one line) | ~LOC |
|---|---|---|
| `WatchRelayMessages.swift` | The pure Codable wire vocabulary v1: `WatchRelayCommand`, `WatchRelaySnapshot` (+`Phase`, pocket vocabulary §3.1), `WatchRelayReply` (+`Reason`), `WatchRelayCodec` (versioned Data envelope + dictionary keys) (§3.1/§3.2). | ~260 |
| `WatchRelayRules.swift` | The pure relay brain: `intent(for:)`, the `route(_:coordinatorPhase:)` arbitration table, `mergeSnapshot(coordinator:driverLive:driverBaseline:)` (§3.3). The ONLY place a watch command picks a path or becomes an IHRP intent. | ~180 |
| `HeadlessRelayPolicy.swift` | The pure wake/connect/linger state machine: states, events, effects, the bounded pending-command buffer with per-command deadlines, explicit-`Date` API (§3.5). | ~220 |
| `PairedTVStoring+Touch.swift` | Additive protocol extension: `touchLastConnected(fingerprint:resolvedAddress:now:)` — the shared "refresh lastConnectedAt/lastAddress" upsert the driver uses (and the coordinator MAY adopt later); lives in WatchRelay/ so `LANRemote/` stays zero-diff. | ~60 |

### New — module `IHFeatures` (`Sources/IHFeatures/WatchRelay/`)

| File | Purpose | ~LOC |
|---|---|---|
| `RemoteControlRelayHub.swift` | Platform-NEUTRAL `@MainActor` singleton: weak single-slot coordinator registry + the driver slot, route dispatch (`handle(_:) async -> WatchRelayReply`), snapshot merge + Equatable-deduped `onSnapshot`, `retireHeadless()` (the single-owner handoff door), `expireBackgroundBudget()`. NO WatchConnectivity import — compiles on all platforms, idles everywhere but iOS (§3.4/§4.3). | ~240 |
| `RemoteControlCoordinator+WatchRelay.swift` | The pure `nonisolated static relaySnapshot(from: UIPhase)` mapping (§3.1 table) + the coordinator's thin helpers: `relayRegister()`/`relayUnregister()`/`relayPublish()`/`relayRetireHeadless()` — so the core file gains only call-lines (§4.3). | ~140 |
| `RemoteControlCoordinator+Persistence.swift` | **Pure relocation (fact 12):** `persistPaired(token:resolved:)` + `touchLastConnected()` move BYTE-IDENTICALLY from `RemoteControlCoordinator.swift:363-392` (own commit step; suite green unchanged; the PR-8 precedent). | ~110 |
| `HeadlessRelayDriver.swift` | The **headless session owner** (§4.2): `@MainActor` service owning per-burst `RemoteControlSession` instances; `perform(_:) async -> WatchRelayReply`, `retire()`, `publishBaseline()`; consumes each owned session's `events` (single consumer per instance); enforces the 4 s connect deadline + 20 s idle disconnect via the pure policy. UIKit-free. | ~360 |
| `PhoneWatchRelayService.swift` | `#if os(iOS) && canImport(WatchConnectivity)` + `@available(iOSApplicationExtension, unavailable)` — the iPhone WCSession shell: activation, `PhoneRelayDelegate` + ordered inbound `AsyncStream`, decode→hub→reply, snapshot push (sendMessage-when-reachable + applicationContext-always), **the background-task brackets** (§4.4). | ~360 |
| `WatchRemoteController.swift` | `#if os(watchOS) && canImport(WatchConnectivity)` — the watch-side `@MainActor @Observable` model + its delegate shell/stream: `snapshot`, `isPhoneReachable`, `send(_:)` with the slow-reply "Waking iPhone…" state, `refresh()` (§4.5/§5). | ~320 |
| `WatchRemoteView.swift` | `#if os(watchOS)` — the reduced remote UI: status header, Prev/Next, 3-way display row, the honest phase/reason-driven guidance (§5). | ~260 |
| `WatchRootView.swift` | `#if os(watchOS)` — interim watch ROOT (`NavigationStack { WatchRemoteView() }`, controller `@State`, scenePhase refresh) — Phase-1.5's tab bar gets one obvious insertion point (D-12). | ~60 |

### New — tests (Swift Testing; see §7 for contents)

`Tests/IHLiveTests/WatchRelay/WatchRelayCodecTests.swift` · `WatchRelayRulesTests.swift` · `HeadlessRelayPolicyTests.swift` — and `Tests/IHFeaturesTests/WatchRelaySnapshotMappingTests.swift` · `RemoteControlRelayHubTests.swift` · `HeadlessRelayDriverLoopbackTests.swift`.

### Edited

| File | Edit |
|---|---|
| `Sources/IHFeatures/RemoteControlCoordinator.swift` | (a) persistence helpers REMOVED (pure move); (b) `start()` gains `await relayRetireHeadless()` (first line) + `relayRegister()` (after `spawnEventsConsumer()`); (c) `stop()` gains `relayUnregister()`; (d) `apply(_:)` gains `relayPublish()` as its LAST line; (e) `resolveAndAttach`'s `.connect` branch: `Task { await session.attach(to: target) }` becomes `Task { await self.relayRetireHeadless(); await self.session.attach(to: target) }`; (f) `setScenePhaseActive(_:)` gains `if active { await relayRetireHeadless() }` before `setSuspended`. Net: file shrinks under the cap and gains 6 call-lines + pointer comments (§4.3's handoff sites). |
| `Sources/IHFeatures/RemoteControlCoordinator+ManualConnect.swift` | `connectByAddress(hostInput:portInput:)` (`:25`): the connect `Task` gains the same leading `await relayRetireHeadless()` (handoff site 4). |
| `Apps/iHymnsWatch/Sources/IHymnsWatchApp.swift` | Root swaps `PhaseZeroSkeletonView(shellName: "watchOS")` → `WatchRootView()` (keep `IHFonts.registerBundledFonts()`). |
| `Apps/iHymns/Sources/IHymnsApp.swift` | `init()` gains `#if os(iOS)` `PhoneWatchRelayService.shared.activate()` `#endif` after `registerBundledFonts()` (`IHymnsApp.swift:84-87`). In `init`, NOT `.task`, deliberately: a watch message can LAUNCH the app in the background where scene bodies/`.task` may never run — the delegate must exist on EVERY launch path. `App` is `@MainActor`, so the call is isolation-correct. |
| `CHANGELOG.md` | One Unreleased entry. |

**No edits to:** anything under `Sources/IHLive/LANRemote/` (**ZERO diff** — grep-verifiable), `RemoteControlSession`/`RemoteSessionActor`/`TVListenerActor`, `RemoteControlView`/`RemoteControlSurfaceView`, `RemoteDeviceIdentity`, `Package.swift` (both new folders live inside existing targets), `project.yml`, `apple.yml` (facts 14-15; §6.4).

---

## 3. The pure cores (BINDING shapes — do not improvise)

Everything here is `Sendable + Equatable`, deterministic, and testable with zero WCSession/actors/network/sleeping. The impure shells (§4) are thin enough that a protocol or policy bug is ALWAYS reproducible in a pure test.

### 3.1 The wire vocabulary (`WatchRelayMessages.swift`, IHLive) — the POCKET phase set

```swift
/// Watch↔iPhone relay protocol version — v1 IS this pocket design (the
/// foreground-only vocabulary never shipped). Bumped only on a breaking
/// wire-shape change; the strict-equality probe turns install skew (a watch
/// app lagging an iPhone update by hours) into honest "update" copy, never
/// a decode crash (D-13).
public enum WatchRelayProtocolVersion { public static let current = 1 }

/// The REDUCED control set, strategy §2.4.2 EXACTLY (D-5): Next/Prev
/// (component), Lyrics-restore, Blackout, Logo, status. Flat String raws —
/// trivially Codable, self-evident wire shape.
public enum WatchRelayCommand: String, Sendable, Codable, Equatable, CaseIterable {
    case nextComponent, prevComponent          // → IHRP next/prevComponent
    case showLyrics, showBlackout, showLogo    // → IHRP setDisplayState(...)
    case requestState                          // answered from the snapshot, never forwarded
}

/// The compact projection of the phone's remote-control world — the ONLY
/// thing the watch ever knows. Carries nothing the venue screen isn't
/// already showing publicly (§8).
public struct WatchRelaySnapshot: Sendable, Codable, Equatable {
    public enum Phase: String, Sendable, Codable, Equatable, CaseIterable {
        case noSavedTV     // nothing to drive — open iHymns on the iPhone and pair
        case standby       // saved TV known; a tap wakes the phone + reconnects (THE pocket state)
        case pairing       // a ceremony/TOFU confirm is mid-flight ON THE PHONE — finish there
        case connecting    // foreground connect/reconnect OR a headless wake-connect in flight
        case controlling
    }
    public var phase: Phase
    public var tvName: String?
    /// `SongID.rawValue` — display parity with the phone header
    /// (`RemoteControlSurfaceView.swift:84`); never parsed, never fetched-for (D-6).
    public var songRef: String?
    /// `IHRPDisplayState.rawValue` as a tolerant String — unknown values
    /// (e.g. `frozen`, or a newer phone) highlight nothing, never fail decode.
    public var displayState: String?
    public var revision: UInt64?

    public static let noSavedTV = WatchRelaySnapshot(phase: .noSavedTV, tvName: nil, songRef: nil, displayState: nil, revision: nil)
    public static func standby(tvName: String?) -> WatchRelaySnapshot { ... }
}

/// The synchronous answer to every watch command.
public struct WatchRelayReply: Sendable, Codable, Equatable {
    public enum Outcome: String, Sendable, Codable, Equatable {
        case forwarded    // the intent reached the TV on a live session (incl. after a wake-connect)
        case replyOnly    // requestState
        case failed       // honest failure — `reason` says why, snapshot shows the resulting state
    }
    /// Tolerant vocabulary (decoded as String, mapped to copy; unknown → generic).
    public enum Reason: String, Sendable, Codable, Equatable {
        case noSavedTV        // nothing paired (or Keychain unreadable — BFU)
        case busyPairing      // the phone is mid-ceremony/TOFU — finish on the iPhone
        case busyConnecting   // the foreground screen is mid-connect — momentary, tap again
        case couldNotReachTV  // wake-connect failed/timed out (TV off, network changed, 4 s deadline)
        case repairNeeded     // the TV revoked our token — re-pair on the iPhone
    }
    public var outcome: Outcome
    public var reason: Reason?
    /// ALWAYS attached — the watch renders from this, so even a failure
    /// self-heals the watch's picture of the world.
    public var snapshot: WatchRelaySnapshot
}
```

**`.phoneBackgrounded` is GONE** — that phase was the old design's confession that backgrounding killed the relay; the pocket design's whole point is that a backgrounded phone is `.standby` (or transiently `.connecting`/`.controlling` during a burst). Deliberately ABSENT from the snapshot (unchanged decisions): `componentIndex`/`lineIndex` (no per-line watch display, D-5); fingerprint/token/any pairing material (§8); song title (D-6); `frozen` as a settable state.

### 3.2 The codec (`WatchRelayCodec`, same file — the ONLY serializer either shell may use)

Unchanged in shape from the superseded spec (restated so this file stands alone): single-key `[String: Data]` envelopes under `commandKey = "c"` / `replyKey = "r"` / `snapshotKey = "s"`; wire shape `{"v": 1, "p": <payload>}` via a private generic `Envelope<T: Codable>`; decode = probe `{"v": Int}` first (failure ⇒ `.malformed`), `v != current` ⇒ `.unsupportedVersion(v)` (strict equality — both ends ship from one repo), then payload (failure ⇒ `.malformed`); encode = `JSONEncoder` with `sortedKeys` (deterministic bytes → the §7.1 size assertion is stable). `Result`, never throw, across the shells:

```swift
public enum WatchRelayCodecError: Error, Sendable, Equatable { case malformed; case unsupportedVersion(Int) }
public enum WatchRelayCodec {
    public static let commandKey = "c"; public static let replyKey = "r"; public static let snapshotKey = "s"
    public static func encode(command:) -> Data;  public static func decodeCommand(_:) -> Result<WatchRelayCommand, WatchRelayCodecError>
    public static func encode(reply:) -> Data;    public static func decodeReply(_:) -> Result<WatchRelayReply, WatchRelayCodecError>
    public static func encode(snapshot:) -> Data; public static func decodeSnapshot(_:) -> Result<WatchRelaySnapshot, WatchRelayCodecError>
}
```

### 3.3 The relay brain (`WatchRelayRules.swift`, IHLive — pure; the route table IS the arbitration, the intent map IS the authorization ceiling)

```swift
/// The COMPLETE command → LAN-intent map (exhaustive switch, no default —
/// a new command without a row is a compile error). `requestState → nil`.
public static func intent(for command: WatchRelayCommand) -> IHRPMessage?
// nextComponent → .nextComponent · prevComponent → .prevComponent
// showLyrics → .setDisplayState(.lyrics) · showBlackout → .setDisplayState(.blackout)
// showLogo → .setDisplayState(.logo) · requestState → nil        (all isControlIntent — §7.1 asserts)

/// WHO serves this command — the single-owner arbitration, pure.
/// `coordinatorPhase` = the registered coordinator's CURRENT snapshot phase
/// (nil when no coordinator is registered). Driver state is deliberately
/// NOT an input: the driver serializes its own bursts internally (§4.2),
/// and the coordinator-phase input alone decides ownership because the §4.3
/// retire hooks guarantee the driver is idle whenever the coordinator is
/// live (.controlling/.connecting/.pairing).
public enum WatchRelayRoute: Sendable, Equatable {
    case foreground        // forward via coordinator.sendIntent — the live on-screen session
    case headless          // hand to HeadlessRelayDriver.perform — wake-connect if needed
    case replyOnly         // requestState
    case reject(WatchRelayReply.Reason)   // busyPairing / busyConnecting
}
public static func route(_ command: WatchRelayCommand, coordinatorPhase: WatchRelaySnapshot.Phase?) -> WatchRelayRoute
```

The route table (BINDING — §7.1 asserts all 6 × 6 = 36 cells; `coordinatorPhase` column `nil` = no coordinator registered):

| command \ coordinatorPhase | nil | .noSavedTV | .standby | .pairing | .connecting | .controlling |
|---|---|---|---|---|---|---|
| nextComponent / prevComponent / showLyrics / showBlackout / showLogo | **headless** | **headless** | **headless** | reject(busyPairing) | reject(busyConnecting) | **foreground** |
| requestState | replyOnly | replyOnly | replyOnly | replyOnly | replyOnly | replyOnly |

(`.noSavedTV`/`.standby` from a REGISTERED coordinator = the browsing screen — the user is looking at the TV list; a watch tap still drives the last TV headlessly, and the §4.3 hook retires that burst the moment the user taps a row on the phone. `reject(busyConnecting)` is momentary by construction — the foreground connect resolves to `.controlling` or back within seconds.)

```swift
/// The ONE snapshot the watch sees — pure merge of the two sources.
/// Priority: a LIVE headless burst > a registered coordinator > the
/// driver's at-rest baseline (standby-with-name / noSavedTV).
public static func mergeSnapshot(
    coordinator: WatchRelaySnapshot?,   // nil when unregistered
    driverLive: WatchRelaySnapshot?,    // non-nil ONLY during a burst (.connecting/.controlling)
    driverBaseline: WatchRelaySnapshot  // always present: .standby(tvName) or .noSavedTV
) -> WatchRelaySnapshot
```

Merge rows (BINDING, §7.1): `driverLive != nil` → `driverLive` · else `coordinator != nil` → coordinator, EXCEPT a coordinator `.standby`/`.noSavedTV` with `tvName == nil` is enriched from `driverBaseline` (the browsing screen doesn't know the last TV's name; the baseline does) · else `driverBaseline`.

### 3.4 `RemoteControlRelayHub` (IHFeatures — platform-neutral, `@MainActor`, the ONE coupling point)

```swift
@MainActor
public final class RemoteControlRelayHub {
    public static let shared = RemoteControlRelayHub()
    private(set) weak var coordinator: RemoteControlCoordinator?   // single-slot, identity-checked unregister
    private(set) var coordinatorSnapshot: WatchRelaySnapshot?
    /// Built lazily on iOS by PhoneWatchRelayService.activate(); nil forever elsewhere.
    private(set) var driver: HeadlessRelayDriver?
    public private(set) var lastMerged: WatchRelaySnapshot = .noSavedTV
    /// Set once by the iOS service; fired only on CHANGE (Equatable dedup).
    public var onSnapshot: (@MainActor (WatchRelaySnapshot) -> Void)?

    func register(_ c: RemoteControlCoordinator)      // + republish
    func unregister(_ c: RemoteControlCoordinator)    // identity check; + republish
    func publishCoordinator(_ snapshot: WatchRelaySnapshot)  // from the apply() tap
    func publishDriver()                              // driver state changed — recompute merge
    public func handle(_ command: WatchRelayCommand) async -> WatchRelayReply
    /// The single-owner handoff door (§4.3): awaits the driver's full
    /// teardown (endControl + stop) BEFORE returning — the coordinator's
    /// connect proceeds only after this resolves. No-op when idle.
    public func retireHeadless() async
    /// Background budget expiring (§4.4) — force the driver down NOW.
    public func expireBackgroundBudget() async
}
```

`handle` (exact): `let phase = coordinator == nil ? nil : coordinatorSnapshot?.phase` → `switch WatchRelayRules.route(command, coordinatorPhase: phase)`: `.foreground` → `await coordinator?.sendIntent(WatchRelayRules.intent(for: command)!)`, reply `(.forwarded, nil, lastMerged)` (pre-echo snapshot — acknowledgement + truth, not prediction; the echo arrives via push moments later) · `.replyOnly` → `(.replyOnly, nil, lastMerged)` · `.reject(reason)` → `(.failed, reason, lastMerged)` · `.headless` → `await driver?.perform(command) ?? WatchRelayReply(.failed, .noSavedTV, lastMerged)` (a nil driver = non-iOS platform = unreachable in practice; the fallback is defensive). Log per command: `IHLog.remote.notice("watchrelay.hub command=\(cmd.rawValue, privacy: .public) route=\(route, privacy: .public) outcome=\(outcome, privacy: .public)")` — case names only.

### 3.5 `HeadlessRelayPolicy` (IHLive — the pure wake/connect/linger state machine; explicit `Date`s, zero sleeping)

```swift
public struct HeadlessRelayPolicy: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        /// Command-reply bound: how long a wake-connect may take before every
        /// pending command fails `.couldNotReachTV`. WELL inside any WCSession
        /// reply window and the background-task budget (§1.3).
        public var connectTimeout: Duration = .seconds(4)
        /// The linger: how long a live headless session survives after the
        /// LAST watch command before a clean endControl+disconnect. 20 s
        /// default — inside the ~30 s background-task budget with margin;
        /// consecutive taps inside it reuse the live link (~100-300 ms).
        public var idleDisconnect: Duration = .seconds(20)
        /// Bounded in-flight buffer while connecting (revised D-8): rapid
        /// taps during the <1 s reconnect ride along IN ORDER; never more.
        public var maxPending: Int = 4
    }
    public struct PendingCommand: Sendable, Equatable { public let id: UUID; public let command: WatchRelayCommand }
    public enum State: Sendable, Equatable {
        case idle
        case connecting(deadline: Date)     // pending commands ride in the struct alongside
        case connected(idleDeadline: Date)
        case retiring                        // teardown awaited by retire()/takeover
    }
    public enum Event: Sendable, Equatable {
        case commandAccepted(PendingCommand, now: Date)
        case sessionControlling(now: Date)   // first .controlling arrived
        case sessionFailed                   // terminal: tornDown / ended / detached / ceremony-cancel
        case tick(now: Date)                 // the driver's deadline check fired
        case retireRequested
        case teardownComplete
    }
    public enum Effect: Sendable, Equatable {
        case startConnect                    // build session + attach (driver supplies the target)
        case forward([PendingCommand])       // send these, in order, on the live session
        case failPending([UUID], WatchRelayReply.Reason)
        case scheduleTick(at: Date)
        case disconnect                      // clean endControl + stop
        case none
    }
    public private(set) var state: State
    public private(set) var pending: [PendingCommand]
    public mutating func handle(_ event: Event) -> [Effect]
}
```

Transition table (BINDING — `HeadlessRelayPolicyTests` asserts every row with literal dates):

| state | event | new state | effects |
|---|---|---|---|
| idle | commandAccepted(c, now) | connecting(deadline: now+connectTimeout) | [startConnect, scheduleTick(at: deadline)] |
| connecting | commandAccepted(c, now) | connecting (unchanged) | pending.count < maxPending ? append : [failPending([c.id], .couldNotReachTV)] |
| connecting | sessionControlling(now) | connected(idleDeadline: now+idleDisconnect) | [forward(pending — flushed in order), scheduleTick(at: idleDeadline)]; pending = [] |
| connecting | tick(now ≥ deadline) | retiring | [failPending(all, .couldNotReachTV), disconnect] |
| connecting | sessionFailed | retiring | [failPending(all, reason — driver substitutes .repairNeeded for the ceremony case), disconnect] |
| connected | commandAccepted(c, now) | connected(idleDeadline: now+idleDisconnect) | [forward([c]), scheduleTick(at: new idleDeadline)] |
| connected | tick(now ≥ idleDeadline) | retiring | [disconnect] |
| connected | sessionFailed | retiring | [disconnect] (nothing pending — commands forward immediately when connected) |
| any non-idle | retireRequested | retiring | [failPending(all, .couldNotReachTV), disconnect] |
| retiring | teardownComplete | idle | [none] |
| idle | retireRequested / tick / sessionFailed | idle | [none] (stray events are no-ops, never traps) |

Ticks that fire EARLY (a stale scheduled tick after the deadline moved) are no-ops: the state carries the authoritative deadline, `tick(now)` compares against it. Every effect is data — the driver executes them; the policy never touches a session, a clock, or a task.

### 3.6 Revised D-8 — command queueing, resolved precisely

The old D-8 ("commands NEVER queue") survives in spirit with ONE bounded, deliberate exception: commands may wait **in-flight for their OWN wake-connect**, capped at `maxPending = 4` and bounded by the SAME 4 s connect deadline that bounds their reply. This is not the hazard the old rule targeted (a stale navigation firing minutes later): nothing outlives its own reply window, nothing persists across wakes, nothing rides `transferUserInfo`, and a failed connect fails EVERY pending command loudly. Cross-wake queueing (transferUserInfo or any persistence) remains BANNED — a live-service remote must be live-or-honestly-failed.

---

## 4. The headless driver, the single-owner handoff, and the wake bridge

### 4.1 Which TV? (RESOLVED — D-15)

**v1: the most-recently-connected saved TV, full stop.** `store.listPairedTVs().first` (already sorted newest-first by `lastConnectedAt ?? pairedAt` — `KeychainPairedTVStore.swift:128-148`) — which is by construction the TV the operator just used. No watch-side TV picker in v1 (a blind list on a 41 mm screen mid-service is a projection hazard; park as a future issue if real usage asks). The target comes from `RemotePairingEntryResolver.resolve(.savedRow(record), saved: records, discovered: [])` — the PINNED path with the saved token and `lastAddress` as the endpoint (fact 3). **No discovery in the background** (no `startDiscovery()` from the driver, ever): Bonjour browsing is slow, unnecessary (the record carries the last-good address), and background-inappropriate. A record with `lastAddress == nil` (paired but never controlled — practically impossible since `.controlling` always follows pairing, but defensive) resolves `.unpairable(.noRouteToTV)` → reply `.failed(.couldNotReachTV)`.

### 4.2 `HeadlessRelayDriver` (IHFeatures, `@MainActor`, UIKit-free — the new session-lifetime OWNER)

```swift
@MainActor
public final class HeadlessRelayDriver {
    public struct Configuration: Sendable {
        public var policy = HeadlessRelayPolicy.Configuration()
        public var sessionConfiguration = RemoteControlSession.Configuration(
            remoteKind: RemoteDeviceIdentity.kind, deviceName: RemoteDeviceIdentity.name)
        // tests inject millisecond policy values + the loopback session tunables (fact 18)
    }
    public init(store: any PairedTVStoring = KeychainPairedTVStore(), configuration: Configuration = .init())

    /// The one command door. Awaits the full outcome: forwarded on a live
    /// (possibly just-established) session, or an honest failure — always
    /// within ~connectTimeout + ε, so the WCSession reply never dangles.
    public func perform(_ command: WatchRelayCommand) async -> WatchRelayReply
    /// The handoff door — awaits COMPLETE teardown (endControl + stop) of any
    /// live/connecting burst before returning. Idempotent; no-op when idle.
    public func retire() async
    /// At-rest truth for the hub's merge + the launch-time applicationContext:
    /// reads the store, caches lastKnownTVName, publishes .standby/.noSavedTV.
    public func publishBaseline() async
    /// Background budget expiring — immediate clean disconnect (≡ retire()).
    public func forceDown() async
}
```

**Session ownership (the load-bearing shape):** the driver builds a **fresh `RemoteControlSession` per burst** (`idle → connecting`), exactly the coordinator's own re-arm pattern (fact 6): fresh actor, fresh streams, ONE `for await event in session.events` consumer task per instance, torn down with the burst. Per-burst instances mean no stale-stream re-arm class of bug and no cross-burst state bleed. The driver holds: `policy` (the pure machine), the current `session?` + its consumer task, a `tickTask` (a `Task.sleep(until:)` per `scheduleTick` effect — cancelled/replaced on re-arm), per-pending-command `CheckedContinuation<WatchRelayReply, Never>`s keyed by `PendingCommand.id` (resumed by `forward`/`failPending` effects — this is how `perform` awaits its outcome), and `lastKnownTVName`.

**`perform` (exact):** if `state == .retiring`, await the in-flight teardown first (a stored teardown continuation), then proceed from idle. Feed `policy.handle(.commandAccepted(...))`; execute effects. `startConnect` = read the store (fact 4 — a locked-BFU phone or empty store fails HERE: resume the command's continuation with `.failed(.noSavedTV)` and reset the policy to idle via `.sessionFailed`); resolve the target (§4.1); build session; spawn the events consumer; `await session.attach(to: target)`. `forward(cmds)` = for each, `WatchRelayRules.intent(for:)` → `await session.sendIntent(msg)` → resume its continuation with `(.forwarded, nil, snapshot(.controlling…))` — sequential, order-preserving. `requestState` never reaches the driver (routed `.replyOnly` at the hub).

**The events consumer maps session events → policy events + snapshots (BINDING table):**

| `RemoteControlSession.Event` | driver action |
|---|---|
| `.connecting` | publish live snapshot `.connecting(tvName)` via `hub.publishDriver()` |
| `.controlling(state)` | first arrival: `policy.handle(.sessionControlling(now))` + `store.touchLastConnected(fingerprint:resolvedAddress: await session.currentResolvedAddress(), now:)` (the `PairedTVStoring+Touch` helper — once per burst, the coordinator's own first-arrival discipline, `RemoteControlCoordinator.swift:320-333`). Every arrival: publish `.controlling(tvName, songRef, displayState, revision)` — TV echoes (incl. other remotes') stream to the watch during the linger. |
| `.pairingEnded` / `.ended` | `policy.handle(.sessionFailed)` (terminal — fact 5) |
| `.detached` / `.reconnecting` | **terminal for the burst** (D-16): `policy.handle(.sessionFailed)` → `disconnect` effect → `session.stop()` — the ladder must NEVER churn in the background (fact 7). The next watch tap wake-connects fresh. |
| `.awaitingCodeEntry` / `.awaitingFingerprintConfirmation` | **`await session.cancelPairing()`** + fail pending `.repairNeeded` (fact 8) — pairing is a foreground human act on the iPhone, ALWAYS (§8 row 1). Cache `repairNeeded` so the failure reason is specific, not `.couldNotReachTV`. |
| `.paired` / `.suspended` | unreachable on this path (token pre-armed / driver never suspends) — log `.error` if ever seen, treat as `.sessionFailed`. |

**`disconnect` effect (exact):** `try? await session.endControl()` — wait, `endControl()` already sends `.endControl` + disconnects + yields `.ended` (`RemoteControlSession.swift:293-306`); then `await session.stop()` (finishes the streams, kills the consumer cleanly); drop the session; `policy.handle(.teardownComplete)`; `publishBaseline()` (the watch flips back to "Ready — <TV>"). The clean `.endControl` gives the TV its deliberate-hand-off log line, exactly like a foreground disconnect.

### 4.3 The single-owner handoff (BINDING — never two live sessions on purpose; a ≤1 s race degrades safely)

**Invariant:** at most ONE of {the coordinator's session, the driver's session} holds (or is establishing) a live connection. Enforced by FOUR retire hooks, all funnelled through `relayRetireHeadless()` (= `await RemoteControlRelayHub.shared.retireHeadless()`, which awaits the driver's COMPLETE teardown before returning):

1. `RemoteControlCoordinator.start()` — first line. Covers: user opens the TV-Remote screen while a headless linger is live.
2. `resolveAndAttach`'s connect `Task` — before `session.attach`. Covers: user taps a row / scans a QR while a linger is live (the coordinator was already registered, browsing).
3. `setScenePhaseActive(true)` — before `setSuspended(false)`. Covers: app foregrounds onto the open Remote screen while a headless burst (started during backgrounding) is live — the watch's session yields to the screen the user is now looking at.
4. `+ManualConnect.connectByAddress`'s connect `Task` — before `attachByAddress`. Covers: manual-connect entry while a linger is live.

The reverse direction needs no hook: the router (§3.3) sends watch commands `.headless` ONLY when the coordinator's phase is `standby`/`noSavedTV`/absent — i.e. when the coordinator's session is `.suspended` (no TCP connection, fact 2), browsing (never attached), or gone. All of this is `@MainActor`-serialized: `retireHeadless()` completing BEFORE `attach` starts is a plain `await` ordering, not a lock.

**The residual race, stated honestly (§8 row 9):** `handle()` may have routed `.headless` and be mid-`perform` when a foreground connect fires its retire — the retire then awaits/aborts that burst (`retireRequested` fails its pending commands `.couldNotReachTV`; the watch shows a transient failure + the push channel corrects it within a second). And a TCP-level overlap of ~teardown-milliseconds is tolerated by the TV (fact 9: multi-connection broadcast + last-writer-wins) — same-device, same-token, zero security consequence.

### 4.4 The background-execution brackets (`PhoneWatchRelayService`, iOS-only — where UIKit is allowed)

`@available(iOSApplicationExtension, unavailable)` on the class (fact 21). Two `UIApplication.beginBackgroundTask(withName:expirationHandler:)` scopes:

- **Command bracket:** begun when a `.command` arrives on the inbound stream, ended right after the reply is sent. Guarantees the decode→route→(wake-connect)→forward→reply pipeline (≤ ~4.5 s worst case) survives even if the wake's implicit runtime is stingy.
- **Linger bracket:** begun when the driver reports a live burst (hub `onDriverActivity` → service), ended when the driver returns to idle. Its `expirationHandler` (fires ~30 s in, if iOS wants the time back) calls `Task { await RemoteControlRelayHub.shared.expireBackgroundBudget() }` → `driver.forceDown()` → clean disconnect + `endBackgroundTask` — the phone NEVER holds a connection past what iOS grants, and the idle default (20 s) undercuts the budget with margin anyway.

Begin/end while FOREGROUND is legal and free (registration only; expiration never fires) — the service does not branch on scenePhase, deliberately (less state, no races). Both brackets log `.debug` transitions; task identifiers are never reused after `endBackgroundTask` (the `.invalid` guard).

### 4.5 The WCSession shells + the concurrency bridge (both sides — the fact-16 idiom, restated BINDING)

`WCSessionDelegate` is pre-async NSObject callback world. Bridge: a `nonisolated final class … : NSObject, WCSessionDelegate` shell whose callbacks ONLY translate arguments into a `Sendable` inbound value and `continuation.yield(...)`; ONE `for await` consumer loop per side, in isolation order. **Never `Task {}`-per-callback** (two rapid Next taps must never reorder — WatchConnectivity delivers in order; the single consumer preserves it end-to-end).

```swift
// Phone side (inside PhoneWatchRelayService.swift):
enum PhoneRelayInbound: Sendable {
    case activated(Bool)                 // activationDidComplete
    case reachabilityChanged(Bool)       // sessionReachabilityDidChange
    case command(Data, WatchRelayReplyBox)
    case needsReactivate                 // sessionDidDeactivate (watch switched)
}
// Watch side (inside WatchRemoteController.swift):
enum WatchRelayInbound: Sendable {
    case activated(Bool), reachabilityChanged(Bool)
    case snapshotData(Data)              // didReceiveMessage (no reply) OR didReceiveApplicationContext
    case replyData(Data)                 // a sendMessage reply arrived
    case sendFailed(String)              // errorHandler — the WCError.code CASE NAME, bounded + log-safe
}
```

**`WatchRelayReplyBox` — the ONE `@unchecked Sendable` in this PR (D-14, header-justified):** WCSession's `replyHandler` is a plain `([String: Any]) -> Void`, thread-agnostic, not `@Sendable`, and `[String: Any]` is not `Sendable`. The box stores the closure, exposes exactly `func send(replyData: Data)` (building `[WatchRelayCodec.replyKey: data]` INSIDE, so the non-Sendable dictionary never crosses an isolation boundary), and is call-once-enforced via `OSAllocatedUnfairLock<Bool>` (double-calling a WCSession reply handler is an Apple-side exception; the flag makes it structurally impossible). ~20 lines; a second `@unchecked`/`nonisolated(unsafe)` anywhere in the diff is a review reject.

**`PhoneWatchRelayService` consumer loop:** `.command(data, box)` → begin command bracket → `decodeCommand` → success: `box.send(replyData: encode(await hub.handle(cmd)))`; failure: STILL answer — `(.failed, nil, hub.lastMerged)` (a swallowed reply surfaces as a spurious watch timeout; the watch's own version copy decides what to show) → end bracket. `.reachabilityChanged(true)` → re-push `lastMerged` (closes any missed-push gap). `.activated(true)` → `await driver.publishBaseline()` + push (covers the background-launch-by-watch-message path AND refreshes applicationContext every launch). `.needsReactivate` → `WCSession.default.activate()` (Apple's documented watch-switch dance). `push(_:)` (from the hub's deduped `onSnapshot`): `sendMessage` fire-and-forget when `activationState == .activated && isReachable`, PLUS `try? updateApplicationContext(...)` always when activated + paired + watch-app-installed (D-7 — the latest-wins catch-up channel a cold watch launch reads instantly). No `transferUserInfo`/file-transfer callbacks implemented — no surface to receive.

**`WatchRemoteController.send(_:)`:** guard `isActivated` (else not-ready notice). Always `sendMessage(_:replyHandler:errorHandler:)` with BOTH handlers — the reply-bearing form IS the wake primitive (§1.3), and `isReachable` on the watch drives COPY, never capability (`send` never gates on it). Set `pendingSince = now`; the §5 UI shows "Waking iPhone…" when a reply is pending > 0.75 s (the wake+reconnect case), cleared on reply/error. A watch-side hard deadline (10 s `Task.sleep` guard) clears a truly-hung send with "iPhone didn't answer." Consumer loop: `.replyData` → `decodeReply` → snapshot + reason→copy map (§5); `.unsupportedVersion` → the D-13 update copy; `.snapshotData` → `decodeSnapshot` → live re-render; `.sendFailed` → "iPhone not reachable — bring your iPhone nearby."; `.reachabilityChanged(false→true)` → `refresh()` (= `send(.requestState)`); `.activated(true)` → `refresh()`. No client queue, no debounce.

---

## 5. The reduced Watch UI — and the honest pocket UX

**watchOS-clean by construction** (fact 13): `NavigationStack`/`ScrollView`/`VStack`/`HStack`/`Button`/`Label`/`Text`/`Image`/`ProgressView` only. Buttons ≥44 pt; every control has an `accessibilityLabel`; the display row exposes selection via `accessibilityAddTraits(.isSelected)`.

`WatchRootView`: `@State private var controller = WatchRemoteController()`; `NavigationStack { WatchRemoteView(controller: controller) }`; `.task { controller.activate(); controller.refresh() }`; `@Environment(\.scenePhase)` → on `.active`, `controller.refresh()` (a watch app resuming from always-on/background must re-pull; applicationContext timing is opportunistic).

`WatchRemoteView` renders by `controller.snapshot.phase` (copy BINDING — honest about §1.3's real constraints):

| phase | Screen |
|---|---|
| `.controlling` | The remote (below), live: header shows `tvName` + `songRef ?? "Nothing selected"` (fact 20 parity); the display row highlights `displayState`. |
| `.standby` | **The remote, FULLY ACTIVE** — this is the pocket-control headline: status line "Ready — `tvName`" + caption "Taps wake your iPhone to reach the TV." Buttons enabled; the first tap flips the status to the pending state below. NOT a guidance dead-end. |
| `.connecting` | The remote with a `ProgressView` status line "Connecting to `tvName`…" — buttons stay enabled (taps buffer into the same burst, §3.5). |
| `.pairing` | Guidance: **"Finish pairing on your iPhone"** — "Complete the code or fingerprint step on your iPhone, then this remote takes over." |
| `.noSavedTV` | Guidance: icon `appletv` + **"No TV paired"** — "Open iHymns on your iPhone and connect to a TV once; after that, this watch can drive it even with the phone in your pocket." |

Transient overlays (a `.footnote` themed line, auto-cleared on the next reply/push):
- pending > 0.75 s → **"Waking iPhone…"** (the honest wake+reconnect latency story — a cold burst takes ~1-2 s vs the ~100-300 ms warm path; the copy explains the difference instead of feeling broken).
- `Reason` copy map: `.couldNotReachTV` → "Couldn't reach `tvName`. Is the TV on and on the same network?" · `.repairNeeded` → "The TV no longer trusts this phone — re-pair in iHymns on your iPhone." · `.busyPairing` → "Finish the pairing step on your iPhone first." · `.busyConnecting` → "Your iPhone is connecting — try again in a moment." · `.noSavedTV` → (phase screen already covers; notice suppressed) · unknown string → "iPhone couldn't complete that."
- `.sendFailed` → "iPhone not reachable. Bring your iPhone nearby." · unsupported version → "Update iHymns on your iPhone and Apple Watch." · 10 s watch deadline → "iPhone didn't answer."
- `!isPhoneReachable && isActivated` on the guidance screens → the not-reachable line dominates (reachability drives COPY, never gates `send` — §4.5).

The `.controlling`/`.standby`/`.connecting` remote (one non-scrolling screen on 45 mm; `ScrollView` smaller): (1) status header (2 lines: `tvName` `.headline`; `songRef ?? "Nothing selected"` / "Ready" / "Connecting…" `.footnote.secondary`); (2) Prev/Next row — two large `.borderedProminent` buttons (`chevron.left`/`chevron.right`), accessibility "Previous verse"/"Next verse" (fact 20); (3) display row — **Lyrics / Black / Logo**, the one matching `snapshot.displayState` highlighted (`.borderedProminent` vs `.bordered` + `.isSelected`); unknown/`frozen` highlights none. **Stateless/reflective, one hop longer:** nothing toggles locally — highlights/header change ONLY on reply/push echo, the `RemoteControlSurfaceView` contract verbatim.

Deliberately OUT (D-5, unchanged): line prev/next + Crown line-scroll (no per-line watch display — blind line-nav at a live service is worse than none); song picker/search (Phase-1.5's watch app; a blind `selectSong` is a projection hazard); `frozen` (operator nicety, phone has it); appearance/scroll/`jumpLine`; a watch TV-picker (D-15).

---

## 6. Lifecycle

### 6.1 Activation topology

- **iPhone:** `PhoneWatchRelayService.shared.activate()` from `IHymnsApp.init()` (`#if os(iOS)`) — EVERY launch path including watch-triggered background launches; idempotent; runtime no-op on iPad (`WCSession.isSupported()` false). `activate()` also builds the `HeadlessRelayDriver`, parks it in the hub, and `publishBaseline()`s after activation (so applicationContext carries the truth from every launch).
- **Watch:** `WatchRemoteController.activate()` from `WatchRootView.task` — the watch app IS the remote screen for now (D-12).
- **Coordinator:** registers/unregisters with the hub inside its existing `start()`/`stop()`; publishes its snapshot at the end of every `apply(_:)` (strictly after the pure `uiPhase` recompute and every side-effect branch, including `+ManualConnect`'s direct `uiPhase` writes — the end-of-apply position observes them too). The hub is a tap of ALREADY-APPLIED state — zero new stream consumers at either layer (the old spec's D-2, unchanged and still THE architectural invariant on the coordinator side).
- **Driver:** born idle; lives for the process lifetime; owns sessions only per burst.

### 6.2 The capability/resume matrix (each row = a §7.3 device-test; put this table in the PR body)

| Situation | What happens |
|---|---|
| Phone foreground, Remote screen, controlling | Route `.foreground`: commands forward on the live session (~100-300 ms); echoes push. The old design's steady state, unchanged. |
| Phone foreground, DIFFERENT screen | No coordinator → `.headless`: wake-free (app running) connect ≤1 s, then warm-path taps; idle-disconnect 20 s after the last tap. |
| **Phone backgrounded / screen off / in pocket (AFU)** | **THE HEADLINE:** watch tap → OS wakes iHymns in background → driver wake-connects the last TV (pinned+token, <1 s typical, 4 s bound) → forward → reply; linger 20 s serves follow-up taps warm; clean endControl on idle or budget expiry. |
| **Phone LOCKED (AFU)** | Identical to backgrounded — background execution + `AfterFirstUnlock` Keychain both work locked (§1.3). |
| Phone locked, **never unlocked since boot (BFU)** | Wake and/or Keychain read fails → `.failed(.noSavedTV)` → watch: "Open iHymns on your iPhone." Accepted residual; stated in the PR body. |
| Phone **force-quit** by the user | ASSUMED no relaunch (§1.3) → `errorHandler`/timeout → "iPhone not reachable"/"didn't answer." §7.3 observes the real behaviour and records it. |
| Phone out of range / off | `sendFailed(.notReachable)` → honest copy; NO queueing (§3.6). |
| Remote screen open, app backgrounds mid-control | PR-7 behaviour unchanged: coordinator suspends (`.endControl` + disconnect, fact 2) → coordinator phase `.standby` → the NEXT watch tap runs headless against the same TV. Pocket control begins exactly where foreground control paused. |
| App foregrounds onto the Remote screen during a headless burst | Hook 3 retires the driver (awaited teardown) BEFORE `setSuspended(false)` reconnects the coordinator — the screen takes over; the watch follows via the coordinator's snapshots, seamlessly. |
| User taps a TV row / manual-connects during a linger | Hooks 2/4 retire first; the foreground attach proceeds; watch follows. |
| TV revoked the pairing | Headless burst hits `.awaitingCodeEntry` → cancel + `.repairNeeded` → "re-pair on your iPhone." Never a watch-side ceremony. |
| Watch cold-launch | applicationContext delivers the last snapshot instantly; `refresh()` pulls live truth (waking the phone if needed). |
| User switches watches | `sessionDidDeactivate` → re-`activate()` (Apple's dance); the watch holds NOTHING durable. |

### 6.3 Battery policy (state in the PR body)

One connection burst per interaction cluster; 20 s linger (tunable) then a clean disconnect; NO reconnect ladder in background (D-16 — `.detached` ends the burst); NO discovery in background; a dead TV costs one bounded 4 s attempt per tap (fact 5); zero network activity between bursts; the background-task expiration hard-stops everything iOS didn't want to fund. The watch spends one `sendMessage` round-trip per tap + push deliveries only on CHANGE (hub dedup).

### 6.4 Background modes / entitlements / Info.plist (RESOLVED: **no changes**)

- **WatchConnectivity has NO background mode and NO entitlement** on either platform — the wake behaviour of `sendMessage` is built into the framework (fact 14). Nothing to declare.
- **`beginBackgroundTask` needs no capability** — it is the plain, universally-available background-time API.
- **Short-burst `NWConnection` use during granted background runtime needs no background mode** — background modes exist to KEEP an app running; we run only inside the window WatchConnectivity + the task assertions already grant, and we disconnect before it closes. (Contrast: a persistent background LAN socket would need posture we deliberately rejected — that's the design's whole wake-and-reconnect premise.)
- **Local Network permission:** already granted via PR-7's primer + first browse on the Remote screen; it is app-wide and applies to the headless connect. It can NEVER be prompted from the background — but a user who never opened the Remote screen has no saved TV either, so the `.noSavedTV` path covers the ordering by construction. If permission was revoked in Settings, the connect fails → `.couldNotReachTV` (honest).
- The iOS target's existing `INFOPLIST_KEY_UIBackgroundModes: audio` (`project.yml:98`) is UNTOUCHED. **Zero `project.yml`/`apple.yml`/entitlement/plist diff** — verified by inspection; state in the PR body.

---

## 7. Test plan (Swift Testing; pure-first; WCSession itself is NEVER unit-tested — `isSupported()` is false on the macOS test host; the shells are thin enough that §3's pure suites + the loopback driver suite carry the correctness load)

### 7.1 Always-on pure suites (IHLiveTests/WatchRelay/)

- **`WatchRelayCodecTests`** — every command round-trips (`CaseIterable`); snapshot full (`controlling`, all fields) + minimal (`.noSavedTV`); reply round-trips every outcome × reason (incl. nil reason); `"v":1` literal in encoded JSON (sortedKeys); `{"v":99,…}` ⇒ `.unsupportedVersion(99)`; garbage/empty ⇒ `.malformed`; an UNKNOWN reason string decodes tolerantly (nil-or-generic, never throws); full snapshot `< 512` bytes (field-creep guard, executable).
- **`WatchRelayRulesTests`** — the intent map row-by-row (6 rows incl. `requestState → nil`); **every forwarded message satisfies `isControlIntent`** (ties fact 11's `assertionFailure` guard closed by proof); the FULL 36-cell route table (`allCases × (Phase.allCases + nil)`) against a literally-written expected function; the merge table row-by-row (driverLive wins; coordinator second; baseline enrichment of a nil-tvName standby; baseline fallback).
- **`HeadlessRelayPolicyTests`** — every §3.5 transition row with literal `Date`s; pending-buffer order preservation + the `maxPending` overflow row; connect-deadline expiry fails ALL pending; a stale early tick is a no-op; idle-deadline refresh on each command; `retireRequested` from every state; stray events in `idle` are no-ops; effects compared as literal arrays (order matters — `forward` before `scheduleTick`).

### 7.2 Always-on IHFeaturesTests (`@MainActor` where stateful; `@testable import IHFeatures` — the `RemoteControlUIStateTests` precedent)

- **`WatchRelaySnapshotMappingTests`** — `relaySnapshot(from:)` one row per `UIPhase` case (7 rows): `.browsing → .standby(nil)` · `.connecting/.reconnecting → .connecting` · `.codeEntry/.confirmingFingerprint → .pairing` · `.controlling → .controlling` (songRef/displayState/revision propagation from a constructed `IHRPState`) · **`.suspended → .standby`** (the pocket redesign's key row — no more `.phoneBackgrounded` dead-end); `.confirmingFingerprint`'s fingerprint hex is ABSENT from the encoded snapshot bytes (executable §8 assertion).
- **`RemoteControlRelayHubTests`** — no coordinator + no driver ⇒ `handle(.nextComponent)` = `.failed(.noSavedTV)`; registered coordinator with `uiPhase = .controlling(...)` set directly ⇒ `.requestState` = `.replyOnly`, `.nextComponent` = `.forwarded` (the un-started session's `try?` swallows — no network); `.codeEntry` phase ⇒ `.failed(.busyPairing)`; publish dedup (same merged snapshot twice ⇒ `onSnapshot` once); unregister → merged falls back to the driver baseline; stale unregister can't evict a successor (identity check); `retireHeadless()` with an idle/nil driver is a no-op that returns.
- **`HeadlessRelayDriverLoopbackTests`** — the fact-19 harness (a real `TVListenerActor` on `127.0.0.1`, `InMemoryLANRemotePairingAuthority` pre-seeded with the token, `InMemoryPairedTVStore` holding a record whose `lastAddress` is the bound port; millisecond policy + session tunables): **(a) the headline E2E** — `perform(.nextComponent)` from idle ⇒ reply `.forwarded`; the TV's `controlEvents` yields `.nextComponent`; after the ms-scale idle deadline the TV sees `.endControl` + the connection close, and the driver publishes `.standby`; **(b) warm path** — a second `perform` within the linger forwards WITHOUT a new connect (assert one TV connection total); **(c) buffering** — two commands fired before `.controlling` arrive at the TV in order; **(d) dead TV** — record pointing at an unbound port ⇒ `.failed(.couldNotReachTV)` within the ms deadline, policy back to idle, NO retry traffic; **(e) revoked token** — authority answers false ⇒ the TV challenges ⇒ driver cancels ⇒ `.failed(.repairNeeded)`, no code ever submitted; **(f) retire mid-linger** — `retire()` returns only after the TV observed the close; a subsequent `perform` starts a FRESH burst; **(g) empty store** ⇒ `.failed(.noSavedTV)` with zero network activity.
- **`RemoteControlUIStateTests`** — byte-unchanged, green before AND after the `+Persistence.swift` relocation commit (the provably-pure-move discipline).

### 7.3 Device-only matrix (the ONLY way to exercise WCSession + real wakes; state results honestly in the PR body, like PR-6/7/8's device rows)

Real iPhone + paired real Watch + the PR-6 Apple TV dev build: every §6.2 row, ESPECIALLY — pocket headline (phone locked in pocket, watch Next → TV advances ≤ ~2 s cold, ≤ ~500 ms warm); linger expiry observed (TV log shows clean end-control ~20 s after the last tap); foreground takeover mid-linger (open the Remote screen, no double-connection weirdness, watch keeps mirroring); force-quit observation (record ACTUAL behaviour, §1.3); BFU spot-check if practical (reboot phone, don't unlock, watch tap → honest copy); out-of-range copy; watch cold-launch context; background-task expiration (hold linger via rapid taps ~25 s+ then stop — verify the expiration disconnect fires cleanly, Console `watchrelay` lines). Paired simulators relay WCSession partially (reachability semantics differ; background-wake does NOT reproduce) — UI smoke only; the evidence bar is the real-device pass.

### 7.4 Local pre-PR verification (builder runs ALL — CI is not a required check #1526; CI itself has NO iOS app-scheme build post-#1556, so the LOCAL app builds are the only embed proof)

```bash
cd appApple/Packages/iHymnsKit && swift build && swift test
swiftlint --config appApple/.swiftlint.yml appApple          # 0 violations
bash appApple/Scripts/loc-budget.sh                          # every file ≤400 (incl. the shrunken coordinator)
# Package cross-compiles — EXACTLY what CI runs (apple.yml, post-#1556):
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk iphonesimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-ios26.0-simulator
cd appApple/Packages/iHymnsKit && swift build \
  --sdk "$(xcrun --sdk watchsimulator --show-sdk-path)" \
  -Xswiftc -target -Xswiftc arm64-apple-watchos26.0-simulator
# Local app-scheme builds (the builder's Mac has the platforms; CI doesn't — #1556):
cd appApple && xcodegen generate
xcodebuild -project iHymns.xcodeproj -scheme iHymns   -destination 'generic/platform=iOS Simulator'  CODE_SIGNING_ALLOWED=NO build   # embeds + compiles the REAL watch app
xcodebuild -project iHymns.xcodeproj -scheme iHymns   -destination 'platform=macOS,arch=arm64'       CODE_SIGNING_ALLOWED=NO build
xcodebuild -project iHymns.xcodeproj -scheme iHymnsTV -destination 'generic/platform=tvOS Simulator' CODE_SIGNING_ALLOWED=NO build
```

Builder footnotes: (1) a post-clean `swift build` may fail on the local `safe.bareRepository=explicit` git setting — prefix `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all`. (2) Disk is tight (~5 GB) — iterate on the package cross-compiles; run the three app-scheme builds ONCE at the end; `rm -rf ~/Library/Developer/Xcode/DerivedData/iHymns-*` between them if space pinches. (3) Loopback suites open real localhost sockets — run the full `swift test` serially if the combined LAN suites flake under load (the PR-8 `tofuConnectTimeout` lesson).

---

## 8. Threat model + Decisions

PR-6 §8 / PR-7 §8 / PR-8 §8 apply wholesale and are UNTOUCHED (the LANRemote diff is ZERO). The still-valid relay rows from the superseded spec (command injection via WCSession; info disclosure to the watch; secrets-in-logs) carry over as restated below. **NEW rows — the headless/background/wake surface (the reason this PR gets a fresh Opus review):**

| # | Threat | Mitigation (mechanism, THIS PR) | Residual |
|---|---|---|---|
| 1 | **A headless session doing MORE than a foreground one could** (background pairing, TOFU, trust mutation) | Structural: the driver's target comes ONLY from `resolve(.savedRow(record))` — token always non-nil, fingerprint always pinned (fact 3); TOFU/`attachByAddress` is never called; any ceremony event (`.awaitingCodeEntry`/`.awaitingFingerprintConfirmation`) is CANCELLED and surfaced as `repairNeeded` (§4.2 table, loopback-tested §7.2e). The forwardable set is the same 5 `isControlIntent` intents, proven in §7.1. No store WRITE except the `lastConnectedAt`/`lastAddress` touch — no new record, no delete, no token change. | None — the headless surface is a strict subset of the foreground one. |
| 2 | **Impostor/MITM at the reconnect** (someone squatting the saved address while the phone is in a pocket and can't show UI) | Identical to PR-7's pinned path — the connect REQUIRES the saved fingerprint's TLS pin; an impostor fails the handshake and the burst dies `.couldNotReachTV`. No UI-dependent trust decision exists on this path AT ALL (that's TOFU, unreachable — row 1), so "no human watching" removes nothing. | Same as PR-7's residual (device compromise). |
| 3 | **A malicious app injecting WCSession commands** | WCSession is the OS's app-scoped paired-device channel: encrypted in transit by the system, deliverable ONLY between THIS app and ITS OWN same-team watch app on the user-paired watch. No listener, no port, no discovery surface added. The phone-side delegate answers only `commandKey`-shaped dictionaries and replies honestly to garbage. | Jailbroken device — out of scope (defeats the phone's own remote UI identically). |
| 4 | **The locked-phone wake leaking or unlocking anything** | The wake runs code in the BACKGROUND with the screen locked — no UI, no unlock, no biometric bypass. Credentials read: the `AfterFirstUnlock` paired-TV record (fact 4) — the SAME class the foreground reconnect already uses; nothing is re-classed, nothing new is persisted, nothing crosses to the watch (D-10). BFU: reads fail closed → honest copy (§6.2). | The phone will drive the TV while locked — which is the FEATURE, authorized by the owner-initiated watch tap on a wrist-locked, user-paired watch. |
| 5 | **A lost/stolen WATCH driving the venue TV** (blackout mid-service) | watchOS wrist-detection locks a removed watch behind its passcode — the app is unreachable off-wrist. In range + on a wrist + unlocked = the same trust bar as holding the operator's unlocked phone. | An unlocked watch on the wrong wrist within BT range of the phone — accepted; equivalent to the phone being grabbed. |
| 6 | **Stale/replayed commands** (queued nav firing minutes late) | Revised D-8 (§3.6): the ONLY buffering is in-flight-for-own-connect, ≤4 commands, bounded by the 4 s reply deadline, all-failed-loudly on miss; NO `transferUserInfo`, NO cross-wake persistence; ordering end-to-end via single-consumer streams. | A command in its connection's last ms dies at `try? send` — identical class to a phone-surface tap in the same window. |
| 7 | **Background battery/network abuse via the relay** (a hostile or buggy watch app grinding the phone) | Only the paired watch's HUMAN taps trigger wakes (no timers, no crown-repeat); each burst is one connect + ≤20 s linger + clean close; failed connects are terminal (fact 5, no ladder — D-16); the bg-task expiration hard-stops anything iOS defunds; the TV keeps its own `rateLimited` taxonomy. | A human mashing the watch ≈ a human mashing the phone. |
| 8 | **Info disclosure TO the watch** | The snapshot's five fields are enumerated (§3.1), derived only from `UIPhase`/session events; fingerprint/token/code/nonce never enter it (§7.2 asserts absence from encoded bytes); no lyric text (IHRP invariant). The only NEW datum vs the old design is the standby `tvName` — already public on the venue screen. | — |
| 9 | **Two live sessions / takeover races** | Single-owner arbitration: the pure route table + four awaited retire hooks (§4.3), all `@MainActor`-ordered. Belt-and-suspenders: the TV tolerates same-device overlap (multi-connection broadcast + last-writer-wins, fact 9) — a ≤1 s race degrades to duplicate-but-authenticated traffic from the SAME device, zero trust consequence. | The ≤1 s overlap window itself — cosmetic at worst. |
| 10 | **Secrets/PII in logs from the new background paths** | Binding: log command/route/outcome/policy-state case names + booleans (`.public`); tvName `.private` if ever logged; songRef/snapshot contents/addresses never logged; the reply box logs nothing; bg-task ids `.debug`. Review gate: grep every new file's `IHLog` calls. | — |

**Decisions (do NOT re-litigate while building):**
- **D-1 — The relay drives sessions the PHONE owns; `IHRPRemoteKind.watchRelay` stays dormant** (fact 17). The TV's trusted-remotes list shows the phone — the device that paired and connects. Unchanged.
- **D-2 — Coordinator tap = end-of-apply publish + start/stop register;** never a second `events`/actor-stream consumer, never Observation-tracking loops. Unchanged; still the coordinator-side invariant.
- **D-3 — The hub, rules, policy and driver are platform-neutral and WatchConnectivity-free;** all `canImport(WatchConnectivity)`/UIKit gating is confined to the two shell files + one app-shell line.
- **D-4 — Data-blob envelopes under single dictionary keys, one versioned JSON codec, `Result` at the shell boundary.** Field-by-field `[String: Any]` marshalling is banned.
- **D-5 — The reduced set is strategy §2.4.2's list EXACTLY.** Unchanged.
- **D-6 — `songId.rawValue`, never a fetched title; ZERO API calls on the relay path.** Unchanged.
- **D-7 — Dual-channel state push** (sendMessage-when-reachable + applicationContext-always), hub-deduped. Unchanged.
- **D-8 (REVISED) — No cross-wake queueing, ever; bounded in-flight buffering for a command's OWN wake-connect only** (§3.6: ≤4, one 4 s deadline, fail-loud). `transferUserInfo` for commands remains banned.
- **D-9 (REPLACED — the redesign itself) — Background reconnect IS in scope, as wake-and-reconnect-on-demand:** short bursts inside OS-granted runtime, bracketed by background tasks, idle-disconnected, ladder-free. What remains OUT: any persistent background connection, any declared background mode, any reconnect NOT initiated by a watch command. (The old D-9 parked this as a future issue; the owner promoted it — note in the PR body.)
- **D-10 — No credential crosses the watch link; `IHAuth` imported by no new file.** Unchanged.
- **D-11 — Zero `project.yml`/`apple.yml`/entitlement/plist changes** (§6.4, verified by inspection — state in the PR body).
- **D-12 — The watch root becomes the remote screen** (`WatchRootView`); Phase 1.5 folds it into a tab.
- **D-13 — Version skew = honest copy, never a crash** (strict `v == 1`; every decode failure still ANSWERS phone-side or NOTICES watch-side).
- **D-14 — The one `@unchecked Sendable` is `WatchRelayReplyBox`** (call-once-enforced, dictionary confined). A second `@unchecked`/`nonisolated(unsafe)` is a review reject.
- **D-15 — v1 drives the most-recently-connected saved TV, no watch picker, no background discovery** (§4.1). A multi-TV picker is a future issue if usage asks.
- **D-16 — No reconnect ladder in a headless burst:** `.detached`/`.reconnecting` ends the burst; the next tap reconnects fresh. Background network churn is bounded by human taps.
- **D-17 — Single-owner handoff = pure route table + four awaited retire hooks** (§4.3); the driver serializes internally; TV-side tolerance (fact 9) is fallback, never the mechanism.
- **D-18 — Background time = `beginBackgroundTask` brackets with a force-disconnect expiration handler** (§4.4); the linger default (20 s) deliberately undercuts the ~30 s budget. `performExpiringActivity` was considered and rejected (thread-blocking shape fights structured concurrency; the service file is already iOS-app-only, so `UIApplication` + the `@available(iOSApplicationExtension, unavailable)` annotation is the cleaner fit).
- **D-19 — Scope tripwires (reviewer rejects on sight):** Network/Bonjour from watch code; ANY diff under `Sources/IHLive/LANRemote/`; a second consumer of any one session instance's streams; pairing/TOFU/Keychain-custody changes; a driver path that submits a pairing code or confirms a fingerprint; lyric text/titles over WatchConnectivity; `transferUserInfo` commands; cross-wake queueing; a persistent background connection or new background mode; a new external package; `Task {}`-per-callback in a delegate shell; a #1549-class watch-unavailable API in a new view.
- **Security notes for the PR body:** reproduce this §8 table verbatim; state the ZERO-LANRemote-diff grep proof (`git diff --stat alpha -- appApple/Packages/iHymnsKit/Sources/IHLive/LANRemote/` = empty); state §1.3's honest capability matrix incl. the force-quit observation result; note that Audit B (plan §2's gate) re-reviews PR-6/7/8/11 together before external TestFlight; no backend contact anywhere in this PR.

---

## 9. Commit plan + PR structure

**Recommendation: ONE PR** (`feat/apple-p2-pr11-pocket` → `alpha`). The candidate split — "session-lifetime redesign" PR then "watch UI" PR — dissolves on inspection: the session-lifetime change turned out ADDITIVE (a new owner over the EXISTING, untouched session API; `LANRemote/` zero-diff), so there is no separable security-boundary refactor to review in isolation, and the headless driver is unverifiable end-to-end without the watch side that drives it (the pocket flow IS the acceptance test). One PR also matches the repo rule (one piece of work, atomic commits inside). Give the Opus security pass commits 1–4 as its focus (everything crypto-adjacent lands there; 5–6 are shells + UI).

Each commit compiles + full `swift test` green:

1. `feat(apple): watch-relay wire protocol v1 + route/merge rules + headless linger policy — pure cores (#1423)` — `WatchRelayMessages.swift`, `WatchRelayRules.swift`, `HeadlessRelayPolicy.swift`, `PairedTVStoring+Touch.swift` + the three §7.1 suites. Pure IHLive; no consumer yet.
2. `refactor(apple): relocate RemoteControlCoordinator persistence helpers (pure move, LOC headroom) (#1423)` — `+Persistence.swift` byte-identical; suite green UNCHANGED.
3. `feat(apple): RemoteControlRelayHub + coordinator relay tap + single-owner retire hooks (#1423)` — hub, `+WatchRelay.swift`, the coordinator's 6 call-lines + the ManualConnect hook, `WatchRelaySnapshotMappingTests`, `RemoteControlRelayHubTests`.
4. `feat(apple): HeadlessRelayDriver — background wake-and-reconnect session owner, loopback-tested (#1423)` — `HeadlessRelayDriver.swift`, `HeadlessRelayDriverLoopbackTests` (the §7.2 a–g matrix).
5. `feat(apple): WatchConnectivity shells — phone relay service with background-task brackets + watch controller (#1423)` — `PhoneWatchRelayService.swift`, `WatchRemoteController.swift`, the `IHymnsApp.init` activation line.
6. `feat(apple): Apple Watch pocket remote UI — standby-first control surface + honest guidance; watch shell root (#1423)` — `WatchRemoteView.swift`, `WatchRootView.swift`, `IHymnsWatchApp.swift` root swap.
7. `docs(apple): PR-11 pocket-control docs — CHANGELOG (#1423)`.

PR body: §8 table + security notes verbatim; §1.3's capability statement + the §6.2 matrix with real-device results filled in honestly (incl. the force-quit observation); the §7.4 transcript; note #1526 (CI not required ⇒ local evidence attached), #1549 (watch cross-compile stays green), #1556 (CI has no iOS app-scheme build ⇒ the local one is the embed proof), D-9's promotion (the parked watch-initiated-reconnect idea is now THIS design, owner-directed); PR-11 completes plan §2 Track B's remote rungs — remaining LAN work is PR-14 (#1425 optional mirror) + the Audit-B gate.
