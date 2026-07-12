// RemoteControlSession+Link.swift
// IHLive/LANRemote
//
// ELI5: The "keep this connection alive, and bring it back if it dies" half
// of the session — split out of `RemoteControlSession.swift`'s public
// surface for the same LOC-budget reason `TVListenerActor+Messages.swift`
// is its own file. This is the ONLY place in the whole app that reads
// `RemoteSessionActor`'s two streams (`phaseUpdates`/`incomingMessages`) —
// the single-consumer rule from `RemoteControlSession.swift`'s header.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §3.4 — BINDING). Two
// structural facts drove this file's shape, both verified against the
// merged `RemoteSessionActor+Connection.swift`:
//
// 1. **Every `connect(...)`/`disconnect()` call causes an internal `.idle`
//    phase transition FIRST**, because `connect(...)`'s very first line is
//    `disconnect()`, and `disconnect()` unconditionally calls
//    `setPhase(.idle)` even when nothing was connected yet. Left unhandled,
//    THIS actor's own `attach(to:)`/reconnect/`disconnect()` calls would look
//    identical to a genuine, unexpected drop and immediately self-destruct
//    the very ceremony/session that just started. `expectedIdleCount` (bumped
//    immediately before every one of THIS actor's own `connect`/`disconnect`
//    calls) is the fix — it swallows exactly that many `.idle` transitions,
//    never a later, genuine one. A COUNTER, not a boolean: two of THIS
//    actor's own calls can legitimately queue up faster than the phase
//    consumer drains them (adversarial-review finding — `setSuspended(true)`'s
//    disconnect immediately followed by `setSuspended(false)`'s connect, each
//    producing their own `.idle` before either is popped off the stream); a
//    boolean would let the FIRST (stale) `.idle` consume the suppression
//    meant for the SECOND (current) one, leaving a genuine reaction point
//    unprotected — exactly the bug a `RemoteControlSessionLoopbackTests
//    .suspendThenResume` re-run caught.
// 2. **A stale/rejected token can drop a "fast path" connection into the
//    ceremony instead** (spec §4's extra resolver rule: a saved token the TV
//    has since revoked gets a `.pairChallenge`, not `.capabilities`) — so
//    `handleIncoming(_:)`'s `.pairChallenge` branch LAZILY starts a
//    `RemotePairingFlowState` (path B: no known code) if one isn't already
//    running, rather than assuming `flow` is always pre-armed by `attach(to:)`.
// 3. **Ceremony completion has exactly ONE authority: `handleControlling(_:)`,
//    never `handleIncoming(_:)`'s `.pairSuccess` case** (adversarial-review
//    finding — an EARLIER version raced the two). `.capabilities` (which
//    always triggers the `.controlling` phase transition) is GUARANTEED to
//    follow `.pairSuccess` on the same connection, so `handleControlling`'s
//    synthesis alone is always sufficient; letting BOTH paths race to finish
//    the SAME flow transition meant whichever one suspended (an `await`)
//    between clearing `flow` and actually yielding `.paired` could lose to
//    the other one sailing past on an now-nil `flow` and yielding
//    `.controlling` FIRST — a flake that only showed up under real
//    concurrency load, never in a single isolated test run.
import Foundation
import IHLog
import Network

extension RemoteControlSession {

    // MARK: - Phase-stream reactions (the ONE consumer of `phaseUpdates`)

    /// See this file's header for why `.idle` needs the `expectedIdleCount`
    /// gate but `.failed` never does (a transport FAILURE is never something
    /// THIS actor's own housekeeping produces).
    func handlePhase(_ phase: RemoteSessionPhase) async {
        switch phase {
        case .idle:
            if expectedIdleCount > 0 {
                expectedIdleCount -= 1
                return
            }
            await handleIdleOrFailed()
        case .failed:
            await handleIdleOrFailed()
        case .controlling(let state):
            await handleControlling(state)
        case .connecting, .awaitingPairing:
            break
        }
    }

    /// A genuine drop (or an outright failed connect attempt) — routed to
    /// exactly one of three reactions depending on WHAT was in flight,
    /// spec §3.4 point 3.
    func handleIdleOrFailed() async {
        guard !isSuspended else {
            // `setSuspended(true)` sends `.endControl` THEN disconnects —
            // the TV may ALSO react to `.endControl` by closing its own end
            // of the socket, which surfaces as `RemoteSessionActor`'s OWN
            // INTERNAL `disconnect()` call (a receive error/`isComplete` in
            // `onReceive`) — an `.idle` `expectedIdleCount` never anticipated
            // (it only counts THIS actor's own explicit connect/disconnect
            // calls). Any drop observed while deliberately suspended is
            // expected noise regardless of the counter — `setSuspended(false)`
            // is the only thing that ever reconnects from a suspended state.
            return
        }
        if var currentFlow = flow {
            // A ceremony (or its underlying connection) failed before ever
            // completing — never auto-retried (the user is standing at the
            // TV; a fresh scan/tap starts a whole new attempt instead).
            let effect = currentFlow.handle(.transportClosed)
            flow = nil
            await apply(effect)
            return
        }

        guard hasEverControlled else {
            // A brand-new FAST-PATH attach's very first connect attempt
            // failed outright (e.g. the saved address is unreachable) —
            // there is no ceremony to report failure through, so this
            // reuses the same terminal vocabulary directly. #1424: a
            // TOFU connect that drops before confirmation lands here too —
            // sweep its state alongside `target` (spec §4.2).
            target = nil
            manualConnect = nil
            pendingChallengeNonce = nil
            eventContinuation.yield(.pairingEnded(.connectionTornDown))
            return
        }

        // A previously-controlling (or mid-ladder) link just dropped again —
        // the auto-reconnect regime. `.detached` is yielded only on the
        // TRANSITION out of `.controlling` (`wasAttached == true`); a retry
        // that itself fails while already mid-ladder does not re-yield it.
        let wasAttached = isAttached
        isAttached = false
        pingTask?.cancel(); pingTask = nil
        if wasAttached {
            IHLog.remote.notice("lanremote.link detached")
            eventContinuation.yield(.detached)
        }
        scheduleReconnectAttempt()
    }

    /// The TV's canonical state arrived (via `phase`, which
    /// `RemoteSessionActor` already sets whenever `.state`/`.capabilities`
    /// lands) — first arrival resets the health/backoff machines and starts
    /// the ping ticker (spec §3.4 point 1's `phaseUpdates` bullet).
    ///
    /// **The pairSuccess-before-controlling race:** `phaseUpdates` and
    /// `incomingMessages` are TWO INDEPENDENT `AsyncStream`s, each drained by
    /// its OWN `Task` (this file's header/single-consumer rule) — Swift
    /// concurrency gives NO guarantee that this actor processes an already
    /// -queued `.pairSuccess` message (yielded first, on the wire) before it
    /// processes the `.controlling` phase transition that logically follows
    /// it (`.capabilities` is only ever sent by the TV AFTER `.pairSuccess`
    /// on the same connection — `TVListenerActor+Pairing.swift`'s
    /// `promoteToPaired`), only that they're SEQUENCED that way on the wire.
    /// If this method runs first, `flow` may still be sitting in
    /// `.proofSent` even though the actor's OWN `currentPairingToken` has
    /// ALREADY been updated (`RemoteSessionActor+Connection.swift`'s
    /// `handleIncomingFrameData` sets `pairingToken` synchronously, with no
    /// suspension point, strictly before the `.capabilities` frame that
    /// triggers THIS phase transition is even processed) — so finishing the
    /// flow HERE, reading that already-current token, guarantees `.paired`
    /// is always yielded before `.controlling`, regardless of which of this
    /// session's two consumer tasks the scheduler happened to run first.
    func handleControlling(_ state: IHRPState) async {
        if let currentFlow = flow, case .proofSent = currentFlow.phase, let token = await remote.currentPairingToken {
            var mutableFlow = currentFlow
            let effect = mutableFlow.handle(.pairSuccess(token: token))
            flow = nil
            await apply(effect)
        }

        if let lastKnownRevision, state.revision > lastKnownRevision + 1 {
            // A jump means we missed one or more broadcasts while
            // disconnected/reconnecting — `IHRPPayloads.swift`'s intended
            // consumer of `revision`. Counts only, never content.
            IHLog.remote.notice(
                "lanremote.link revision-jump from=\(lastKnownRevision, privacy: .public) to=\(state.revision, privacy: .public)"
            )
        }
        lastKnownRevision = state.revision

        if !isAttached {
            isAttached = true
            hasEverControlled = true
            reconnectTask?.cancel(); reconnectTask = nil
            backoff.reset()
            health.reset()
            startPingTicker()
            IHLog.remote.notice("lanremote.link attach")
        }
        eventContinuation.yield(.controlling(state))
    }

    // MARK: - Message-stream reactions (the ONE consumer of `incomingMessages`)

    /// Routes exactly the frames the pairing sub-protocol and the ping
    /// ladder care about; every control-surface response (`.ack`/`.error`
    /// other than `.pairingRejected`) is intentionally left to `phase`
    /// (which already reflects `.state`/`.capabilities`) — this loop never
    /// duplicates that.
    func handleIncoming(_ frame: IHRPFrame) async {
        switch frame.message {
        case .pairChallenge(let nonce):
            // #1424 — the challenge-before-armed race (spec §4.2): mid-TOFU
            // the actor auto-sends `hello`, so the TV's challenge can arrive
            // while `attachByAddress` is still suspended (real at loopback
            // speed). `flow`/`target` don't exist yet, so stash the nonce for
            // `confirmObservedFingerprint()` to replay, gated on
            // `manualConnect != nil` so a post-teardown challenge stays dropped.
            if manualConnect != nil, flow == nil, target == nil {
                pendingChallengeNonce = nonce
                return
            }
            if flow == nil, let target {
                // The stale-token fallback (this file's header point 2) —
                // path B semantics: no known code, prompt the human.
                flow = RemotePairingFlowState(context: .init(
                    expectedFingerprint: target.expectedFingerprint, knownCode: nil, deviceName: configuration.deviceName
                ))
            }
            guard var currentFlow = flow else { return }
            let effect = currentFlow.handle(.challengeReceived(nonce: nonce))
            flow = currentFlow
            await apply(effect)
        // `.pairSuccess` is deliberately NOT handled here — see
        // `handleControlling(_:)`'s "pairSuccess-before-controlling race"
        // doc comment. `.capabilities` (which always triggers a
        // `.controlling` phase transition) is GUARANTEED to follow
        // `.pairSuccess` on the same connection (`TVListenerActor
        // +Pairing.swift`'s `promoteToPaired` sends both, unconditionally,
        // in that fixed order) — so `handleControlling`'s synthesis is
        // ALWAYS sufficient to complete the ceremony on its own. Having
        // TWO code paths race to finish the SAME flow transition is exactly
        // what caused the flake this comment replaces: whichever path
        // suspends (an `await`) between clearing `flow` and actually
        // yielding `.paired` can let the OTHER path observe `flow == nil`
        // and sail past it, yielding `.controlling` first. One authority,
        // never a photo finish.
        case .error(let code, _) where code == .pairingRejected:
            guard var currentFlow = flow else { return }
            let effect = currentFlow.handle(.pairingRejected)
            flow = currentFlow
            await apply(effect)
        case .pong:
            health.onPong()
        default:
            break
        }
    }

    /// The one place a `RemotePairingFlowState.Effect` gets turned into
    /// either a wire send or a yielded `Event` — shared by every
    /// `handleIncoming`/`handleIdleOrFailed`/`submitPairingCode` caller so
    /// the mapping never drifts between them.
    func apply(_ effect: RemotePairingFlowState.Effect) async {
        switch effect {
        case .none:
            break
        case .sendProof(let proof, let deviceName):
            try? await remote.send(.pairConfirm(proof: proof, deviceName: deviceName))
        case .promptForCode(let failedAttempts):
            eventContinuation.yield(.awaitingCodeEntry(failedAttempts: failedAttempts))
        case .reportPaired(let token):
            // The fresh token supersedes whatever was in `target` (nil for a
            // brand-new pairing, or a stale value for a re-pair) — every
            // later reconnect (including the ladder) must use THIS token.
            if let existing = target {
                target = Target(
                    tvName: existing.tvName, endpoint: existing.endpoint, fallbackEndpoint: existing.fallbackEndpoint,
                    expectedFingerprint: existing.expectedFingerprint, token: token, knownCode: nil
                )
            }
            let resolved = await remote.currentResolvedRemoteAddress
            eventContinuation.yield(.paired(token: token, resolved: resolved))
        case .reportFailed(let failure):
            target = nil
            eventContinuation.yield(.pairingEnded(failure))
        }
    }

    // MARK: - Ping ticker (spec §3.4 point 2 — the only OTHER sleeping code)

    func startPingTicker() {
        pingTask?.cancel()
        pingTask = Task { [weak self] in
            await self?.runPingTicker()
        }
    }

    func runPingTicker() async {
        while !Task.isCancelled {
            try? await Task.sleep(for: health.configuration.pingInterval)
            guard !Task.isCancelled, isAttached else { return }
            switch health.onTick() {
            case .sendPing:
                do {
                    try await remote.send(.ping)
                } catch {
                    // `.notConnected` — the actor already knows it's down;
                    // react immediately rather than waiting for the phase
                    // stream to (also) notice.
                    await handleIdleOrFailed()
                    return
                }
            case .declareDetached:
                await handleIdleOrFailed()
                return
            }
        }
    }

    // MARK: - Reconnect ladder (spec §3.4 point 3)

    /// Spawns (replacing any prior) the ONE-SHOT "sleep, then try one
    /// endpoint" task. A failed attempt produces another `.idle`/`.failed`
    /// phase event, which re-enters `handleIdleOrFailed()` and schedules the
    /// NEXT attempt — the ladder is a recursive re-triggering through the
    /// SAME phase consumer, not a second stream reader or its own loop.
    func scheduleReconnectAttempt() {
        guard let target else { return }
        reconnectTask?.cancel()
        reconnectTask = Task { [weak self] in
            await self?.performReconnectAttempt(target: target)
        }
    }

    func performReconnectAttempt(target: Target) async {
        guard !Task.isCancelled else { return }
        let attemptNumber = backoff.attempt
        let delay = backoff.nextDelay(unitRandom: Double.random(in: 0..<1))
        eventContinuation.yield(.reconnecting(attempt: attemptNumber, delay: delay))
        IHLog.remote.notice("lanremote.link reconnect attempt=\(attemptNumber, privacy: .public)")

        try? await Task.sleep(for: .seconds(delay))
        guard !Task.isCancelled else { return }

        // Attempts ALTERNATE primary/last-good so a TV that lost its
        // Bonjour registration (or a remote that roamed networks) still
        // comes back.
        let endpoints: [NWEndpoint] = [target.endpoint, target.fallbackEndpoint].compactMap { $0 }
        guard !endpoints.isEmpty else { return }
        let endpoint = endpoints[attemptNumber % endpoints.count]

        expectedIdleCount += 1
        try? await remote.connect(to: endpoint, expectedFingerprint: target.expectedFingerprint, token: target.token)
    }

    // MARK: - Suspend / resume (scenePhase wiring)
    // #1424 — relocated from `RemoteControlSession.swift` (pure move + D-12's new mid-TOFU branch) for the LOC budget.
    /// `scenePhase` wiring — `true` when the app leaves `.active`
    /// (quiesce, keep the target so resuming is a fast token reconnect);
    /// `false` on return to `.active` (immediate reconnect).
    ///
    /// ELI5: "The app just went to the background" / "the app just came
    /// back to the foreground."
    public func setSuspended(_ suspended: Bool) async {
        guard suspended != isSuspended else { return }
        isSuspended = suspended

        if suspended {
            pingTask?.cancel(); pingTask = nil
            reconnectTask?.cancel(); reconnectTask = nil
            isAttached = false
            expectedIdleCount += 1

            if manualConnect != nil {
                // #1424, D-12 — backgrounding mid-TOFU is a CANCEL, never a
                // `.suspended` awaiting resume: there's no pinned target to
                // resume into, and auto-resuming a half-confirmed trust
                // decision is exactly what TOFU must never do.
                manualConnect = nil
                pendingChallengeNonce = nil
                await remote.disconnect()
                eventContinuation.yield(.pairingEnded(.cancelled))
                return
            }

            try? await remote.send(.endControl)
            await remote.disconnect()
            eventContinuation.yield(.suspended)
            return
        }

        guard let target else { return }
        backoff.reset()
        eventContinuation.yield(.connecting(attempt: 0))
        // ★ Adversarial-review finding: `RemoteSessionActor.disconnect()`
        // cancels `connection` but does NOT synchronously stop an
        // already-in-flight `connection.receive(...)` completion — that
        // callback can still fire moments later (Network.framework's own
        // queue), and `onReceive`'s error/`isComplete` path calls
        // `disconnect()` AGAIN as a delayed echo of a teardown we already
        // initiated ourselves. If that echo lands AFTER this method has
        // ALREADY started a brand-new `connect(...)` (which sets its own
        // fresh `pendingConnectContinuation`), the echo's `disconnect()`
        // resumes THAT continuation with `.notConnected` — cancelling a
        // perfectly healthy new connection attempt out from under itself.
        // `RemoteSessionActor` is additive-only (no connect/disconnect
        // semantics changes allowed), so the fix lives here: a short settle
        // wait gives Network.framework's queue time to flush the echo
        // BEFORE we ask for a new continuation, at negligible cost against
        // spec §3.4's "<1s" resume budget (production; this suite's
        // millisecond configuration still comfortably clears it too).
        try? await Task.sleep(for: .milliseconds(20))
        expectedIdleCount += 1
        try? await remote.connect(to: target.endpoint, expectedFingerprint: target.expectedFingerprint, token: target.token)
    }
}
