// HeadlessRelayDriver.swift
// IHFeatures/WatchRelay
//
// ELI5: The thing that ACTUALLY reconnects to the last TV in the
// background when the watch taps a button — it builds a brand-new
// connection using the exact same pinned, already-trusted path the
// on-screen remote uses, sends the one command, tells the watch what
// happened, and hangs up cleanly a little while after the last tap so the
// phone never sits there connected forever.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §4.1/§4.2 —
// BINDING). The new session-lifetime OWNER this PR's fresh security review
// is about: it builds a FRESH `RemoteControlSession` per burst (the
// coordinator's own re-arm pattern, `RemoteControlCoordinator.swift`'s
// `session` doc comment), is the ONE consumer of that instance's `events`
// stream, and drives `HeadlessRelayPolicy` (`IHLive`) — the pure state
// machine — by turning session events into policy events and policy
// effects into real actions (build/attach/send/disconnect). It NEVER calls
// `RemotePairingEntryResolver.resolve` with anything but `.savedRow(record)`
// — token always non-nil, fingerprint always pinned (fact 3) — so TOFU is
// structurally unreachable from this path; a ceremony event
// (`.awaitingCodeEntry`/`.awaitingFingerprintConfirmation`) is always
// cancelled, never completed (§8 row 1).
//
// **The single-owner handoff:** `teardownInFlight` is the ONE Task both
// `perform(_:)` and `retire()` converge on — whichever caller (a new watch
// tap, or a coordinator retire hook) arrives while a teardown is already
// running simply awaits the SAME task's `.value` rather than racing a
// second one. `runDisconnect()` is the only place `.disconnect` effects are
// ever executed, guaranteeing at most one teardown in flight at a time.
import Foundation
import IHLive
import IHLog

/// The headless session owner — see this file's header for the full
/// picture. `@MainActor`, UIKit-free (the background-task brackets that
/// need `UIApplication` live in `PhoneWatchRelayService`, not here).
@MainActor
public final class HeadlessRelayDriver: HeadlessRelayServicing {

    /// Tunables — mirrors `RemoteControlSession.Configuration`'s identical
    /// discipline so `HeadlessRelayDriverLoopbackTests` can run the whole
    /// wake/connect/linger cycle in milliseconds.
    public struct Configuration: Sendable {
        public var policy: HeadlessRelayPolicy.Configuration
        public var sessionConfiguration: RemoteControlSession.Configuration

        public init(
            policy: HeadlessRelayPolicy.Configuration = .init(),
            sessionConfiguration: RemoteControlSession.Configuration = .init(
                remoteKind: RemoteDeviceIdentity.kind, deviceName: RemoteDeviceIdentity.name
            )
        ) {
            self.policy = policy
            self.sessionConfiguration = sessionConfiguration
        }
    }

    private let store: any PairedTVStoring
    private let configuration: Configuration

    private var policy: HeadlessRelayPolicy
    private var session: RemoteControlSession?
    private var eventsTask: Task<Void, Never>?
    private var tickTask: Task<Void, Never>?
    /// The ONE in-flight teardown, if any — see this file's header.
    private var teardownInFlight: Task<Void, Never>?
    private var continuations: [UUID: CheckedContinuation<WatchRelayReply, Never>] = [:]

    private var currentFingerprint: String?
    private var hasControlledThisBurst = false
    /// Monotonic per-burst token that closes the retire-vs-cold-`perform`
    /// race (#1423 fresh-context security review, Finding 1). `.startConnect`
    /// is executed in a DEFERRED, un-awaited `Task` (it MUST run concurrently
    /// with the connect-deadline tick — see `execute`), so a `retire()` /
    /// `forceDown()` hook can interleave and tear this burst down BEFORE that
    /// Task's `executeStartConnect` body ever runs. Without this token the
    /// stale Task would then build + `attach` a FRESH session while the
    /// policy is back in `.idle` — an orphaned, live, authenticated LAN
    /// connection the (now-idle) single-owner machinery can no longer reap
    /// (and a cross-burst teardown hazard). Bumped on every teardown,
    /// captured when `.startConnect` is dispatched, and re-checked after
    /// `executeStartConnect`'s own `await` so a stale dispatch ABORTS instead
    /// of connecting. (The orphaned link is same-device + token-authenticated,
    /// so it stays inside §8 row 9's trust envelope regardless — this closes
    /// it as a resource/robustness leak against the D-17 single-owner
    /// invariant, not a trust breach.)
    private var burstGeneration = 0
    /// Set right before a `.sessionFailed` whose true cause is more
    /// specific than the policy's own generic `.couldNotReachTV` (an empty
    /// store, or a cancelled ceremony) — consumed (and cleared) by the very
    /// next `failPending` effect this drives, mirroring the coordinator's
    /// own single-purpose transient-flag idiom.
    private var pendingFailureOverride: WatchRelayReply.Reason?

    private var lastKnownTVName: String?

    // MARK: - HeadlessRelayServicing

    public private(set) var liveSnapshot: WatchRelaySnapshot?
    public private(set) var baselineSnapshot: WatchRelaySnapshot = .noSavedTV

    public init(store: any PairedTVStoring = KeychainPairedTVStore(), configuration: Configuration = .init()) {
        self.store = store
        self.configuration = configuration
        self.policy = HeadlessRelayPolicy(configuration: configuration.policy)
    }

    /// The one command door — awaits the full outcome: forwarded on a live
    /// (possibly just-established) session, or an honest failure, always
    /// within ~`connectTimeout` + ε.
    ///
    /// ELI5: "Do this — and tell me exactly what happened, even if it
    /// takes a moment to wake the connection up."
    public func perform(_ command: WatchRelayCommand) async -> WatchRelayReply {
        if let teardownInFlight {
            await teardownInFlight.value
        }
        let pending = HeadlessRelayPolicy.PendingCommand(command: command)
        return await withCheckedContinuation { continuation in
            continuations[pending.id] = continuation
            let effects = policy.handle(.commandAccepted(pending, now: Date()))
            Task { await self.execute(effects) }
        }
    }

    /// The handoff door — awaits COMPLETE teardown of any live/connecting
    /// burst before returning; idempotent, a no-op when idle.
    public func retire() async {
        if let teardownInFlight {
            await teardownInFlight.value
            return
        }
        guard policy.state != .idle else { return }
        await execute(policy.handle(.retireRequested))
    }

    /// At-rest truth for the hub's merge + the launch-time
    /// applicationContext: reads the store, caches `lastKnownTVName`,
    /// publishes `.standby`/`.noSavedTV`.
    public func publishBaseline() async {
        let records = await store.listPairedTVs()
        lastKnownTVName = records.first?.name
        baselineSnapshot = lastKnownTVName.map { WatchRelaySnapshot.standby(tvName: $0) } ?? .noSavedTV
        notifyHub()
    }

    /// Background budget expiring — immediate clean disconnect.
    public func forceDown() async {
        await retire()
    }

    // MARK: - Effect execution (the driver's half of the pure policy)

    private func execute(_ effects: [HeadlessRelayPolicy.Effect]) async {
        for effect in effects {
            switch effect {
            case .startConnect:
                // Deliberately NOT awaited inline: `attach(to:)` blocks
                // until the connection resolves (ready OR failed), which
                // can take a while on a slow/dead network — awaiting it
                // HERE would delay this very loop from ever reaching the
                // `.scheduleTick` effect that always accompanies
                // `.startConnect` in the SAME array (the idle->connecting
                // transition), defeating the connect-deadline's entire
                // purpose. Racing the tick against the connect requires
                // them to run CONCURRENTLY, not sequentially. Because it is
                // deferred, capture the burst token NOW so a teardown that
                // interleaves before the Task body runs makes it abort
                // instead of connecting an orphan (see `burstGeneration`).
                let generation = burstGeneration
                Task { await self.executeStartConnect(generation: generation) }
            case .forward(let commands):
                await executeForward(commands)
            case .failPending(let ids, let reason):
                executeFailPending(ids, reason: reason)
            case .scheduleTick(let deadline):
                scheduleTick(at: deadline)
            case .disconnect:
                await runDisconnect()
            case .none:
                break
            }
        }
    }

    /// §4.1 — the most-recently-connected saved TV, full stop; the PINNED
    /// fast path (`RemotePairingEntryResolver.resolve(.savedRow(record),
    /// saved:, discovered: [])`) is the ONLY resolution this driver ever
    /// calls — no discovery, no TOFU, ever.
    private func executeStartConnect(generation: Int) async {
        let records = await store.listPairedTVs()
        // Re-check AFTER the store read: a `retire()`/`forceDown()` may have
        // torn this burst down (bumping `burstGeneration`), or a fresh burst
        // may have re-entered `.connecting`, while we awaited the store —
        // either way this deferred dispatch is stale and must NOT build/
        // attach an orphan session. The retire path has already resumed any
        // pending command's continuation, so aborting here leaves nothing
        // dangling (no watch-side hang).
        guard generation == burstGeneration, case .connecting = policy.state else { return }
        guard let record = records.first else {
            pendingFailureOverride = .noSavedTV
            await execute(policy.handle(.sessionFailed))
            return
        }
        guard case .connect(let target) = RemotePairingEntryResolver.resolve(.savedRow(record), saved: records, discovered: []) else {
            pendingFailureOverride = .couldNotReachTV
            await execute(policy.handle(.sessionFailed))
            return
        }

        lastKnownTVName = record.name
        currentFingerprint = record.fingerprintHex
        hasControlledThisBurst = false
        IHLog.remote.notice("watchrelay.driver burst-start tvName=\(record.name, privacy: .private)")

        let newSession = RemoteControlSession(configuration: configuration.sessionConfiguration)
        session = newSession
        spawnEventsConsumer(for: newSession)
        await newSession.attach(to: target)
    }

    private func executeForward(_ commands: [HeadlessRelayPolicy.PendingCommand]) async {
        for command in commands {
            if let session, let intent = WatchRelayRules.intent(for: command.command) {
                await session.sendIntent(intent)
            }
            resumeContinuation(id: command.id, reply: WatchRelayReply(outcome: .forwarded, snapshot: liveSnapshot ?? baselineSnapshot))
        }
    }

    private func executeFailPending(_ ids: [UUID], reason: WatchRelayReply.Reason) {
        let effectiveReason = pendingFailureOverride ?? reason
        pendingFailureOverride = nil
        for id in ids {
            resumeContinuation(id: id, reply: WatchRelayReply(outcome: .failed, reason: effectiveReason, snapshot: baselineSnapshot))
        }
    }

    private func resumeContinuation(id: UUID, reply: WatchRelayReply) {
        guard let continuation = continuations.removeValue(forKey: id) else { return }
        continuation.resume(returning: reply)
    }

    private func scheduleTick(at deadline: Date) {
        tickTask?.cancel()
        tickTask = Task { [weak self] in
            let interval = deadline.timeIntervalSinceNow
            if interval > 0 {
                try? await Task.sleep(for: .seconds(interval))
            }
            guard let self, !Task.isCancelled else { return }
            await self.execute(self.policy.handle(.tick(now: Date())))
        }
    }

    /// The ONE place `.disconnect` effects run — converges every caller
    /// (this driver's own tick/events tasks, `perform(_:)`, `retire()`) on
    /// the SAME in-flight teardown task (this file's header).
    private func runDisconnect() async {
        if let existing = teardownInFlight {
            await existing.value
            return
        }
        let task = Task { [weak self] in
            guard let self else { return }
            await self.executeDisconnect()
        }
        teardownInFlight = task
        await task.value
        teardownInFlight = nil
    }

    private func executeDisconnect() async {
        // Bump the burst token FIRST (synchronously, before any await) so a
        // still-pending deferred `.startConnect` for the burst being torn
        // down sees the new value and aborts instead of connecting an orphan
        // (see `burstGeneration`, #1423 security review Finding 1).
        burstGeneration += 1
        if let session {
            await session.endControl()
            await session.stop()
        }
        session = nil
        eventsTask?.cancel(); eventsTask = nil
        tickTask?.cancel(); tickTask = nil
        currentFingerprint = nil
        liveSnapshot = nil
        IHLog.remote.notice("watchrelay.driver burst-end")
        _ = policy.handle(.teardownComplete)
        await publishBaseline()
    }

    // MARK: - The events consumer (the ONE consumer of this session's events)

    private func spawnEventsConsumer(for session: RemoteControlSession) {
        eventsTask = Task { [weak self] in
            for await event in session.events {
                guard let self else { break }
                await self.handleSessionEvent(event)
            }
        }
    }

    /// BINDING event → policy/snapshot mapping (spec §4.2's table).
    private func handleSessionEvent(_ event: RemoteControlSession.Event) async {
        switch event {
        case .connecting:
            liveSnapshot = WatchRelaySnapshot(phase: .connecting, tvName: lastKnownTVName)
            notifyHub()
        case .controlling(let state):
            await handleControlling(state)
        case .pairingEnded, .ended:
            await execute(policy.handle(.sessionFailed))
        case .detached, .reconnecting:
            // D-16 — the reconnect ladder must NEVER churn in the
            // background; this ends the burst outright (fact 7). The next
            // watch tap wake-connects fresh.
            await execute(policy.handle(.sessionFailed))
        case .awaitingCodeEntry, .awaitingFingerprintConfirmation:
            // Pairing is a foreground, human, on-iPhone act, ALWAYS
            // (fact 8) — never completed from this path.
            await session?.cancelPairing()
            pendingFailureOverride = .repairNeeded
            await execute(policy.handle(.sessionFailed))
        case .paired, .suspended:
            // Unreachable on this path (token pre-armed / driver never
            // suspends) — defensive.
            IHLog.remote.error("watchrelay.driver unexpected-session-event")
            await execute(policy.handle(.sessionFailed))
        }
    }

    private func handleControlling(_ state: IHRPState) async {
        if !hasControlledThisBurst {
            hasControlledThisBurst = true
            if let currentFingerprint {
                let resolved = await session?.currentResolvedAddress()
                await store.touchLastConnected(fingerprint: currentFingerprint, resolvedAddress: resolved, now: Date())
            }
            await execute(policy.handle(.sessionControlling(now: Date())))
        }
        liveSnapshot = WatchRelaySnapshot(
            phase: .controlling, tvName: lastKnownTVName, songRef: state.songId?.rawValue,
            displayState: state.displayState.rawValue, revision: state.revision
        )
        notifyHub()
    }

    private func notifyHub() {
        RemoteControlRelayHub.shared.publishDriver()
    }
}
