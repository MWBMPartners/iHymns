// ServiceMirrorControllerTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "also mirror this TV to the web congregants" switch
// behaves: it only talks to the server when it should, never says the same
// thing twice, never floods the server when the TV changes fast, keeps the
// TV remote working even when the server call fails, and stops cleanly when
// the session ends or the account can't drive it — all WITHOUT any real
// network (a spy stands in for the one `service_broadcast` call).
//
// DETAILED: Apple Phase-2 PR-14 (#1425, `.claude/apple-native-strategy.md`
// §2.4.1). Deterministic Swift-Testing suite driving `ServiceMirrorController`
// through an injected `BroadcastSend` spy (the same injected-closure test
// seam `RemoteControlUIStateTests`/the `ProjectionViewModel` tests use) — no
// `APIClient`, no `URLProtocol`, no sockets. `retryDelaySeconds: 0` keeps the
// degraded→retry path instant; a gate on the spy makes the single-flight /
// coalescing race fully deterministic.
import IHAPI
import IHLive
import IHModels
import Testing
@testable import IHFeatures

/// Records every `service_broadcast` the controller makes; can be told to
/// throw (per-call or a bounded run of failures) and to SUSPEND the first
/// send at a gate so the coalescing race is deterministic. File-scope (not
/// nested in the suite) to keep `Call` within SwiftLint's nesting depth.
private actor BroadcastSpy {
    struct Call: Equatable, Sendable {
        let sessionId: Int; let songId: String; let componentIndex: Int?; let displayState: String
    }
    private(set) var calls: [Call] = []
    private var result = 1
    private var persistentError: Error?
    private var failuresRemaining = 0
    private var failureError: Error?
    private var gateEnabled = false
    private var gate: CheckedContinuation<Void, Never>?

    var callCount: Int { calls.count }
    func setPersistentError(_ error: Error?) { persistentError = error }
    func failNext(_ count: Int, with error: Error) { failuresRemaining = count; failureError = error }
    func enableGate() { gateEnabled = true }
    func releaseGateAndOpen() { gateEnabled = false; gate?.resume(); gate = nil }

    func send(_ sessionId: Int, _ songId: String, _ componentIndex: Int?, _ displayState: String) async throws -> Int {
        calls.append(Call(sessionId: sessionId, songId: songId, componentIndex: componentIndex, displayState: displayState))
        if gateEnabled { await withCheckedContinuation { gate = $0 } }
        if failuresRemaining > 0, let error = failureError { failuresRemaining -= 1; throw error }
        if let persistentError { throw persistentError }
        return result
    }
}

@Suite("ServiceMirrorController — mirror-on-ack (#1425)")
@MainActor
struct ServiceMirrorControllerTests {

    // MARK: - Test doubles + helpers

    private func makeController(spy: BroadcastSpy, retryDelaySeconds: Double = 0) -> ServiceMirrorController {
        ServiceMirrorController(
            send: { sessionId, songId, componentIndex, displayState in
                try await spy.send(sessionId, songId, componentIndex, displayState)
            },
            retryDelaySeconds: retryDelaySeconds
        )
    }

    private func state(
        song: String? = "MP-1008", component: Int? = 0, line: Int? = nil,
        display: IHRPDisplayState = .lyrics, revision: UInt64 = 1
    ) -> IHRPState {
        IHRPState(
            songId: song.map { parts in SongID(rawValue: parts) ?? SongID(songbookAbbreviation: "MP", number: 1) },
            componentIndex: component, lineIndex: line, displayState: display, revision: revision
        )
    }

    /// Polls until `predicate` holds or the timeout elapses — the send runs
    /// in an unstructured `Task` off the main actor, so there is no
    /// structured await to join; this awaits its observable effect instead.
    private func waitUntil(_ predicate: @escaping () async -> Bool, timeout: Duration = .seconds(2)) async {
        let deadline = ContinuousClock().now.advanced(by: timeout)
        while await !predicate(), ContinuousClock().now < deadline {
            try? await Task.sleep(for: .milliseconds(5))
        }
    }

    /// A short settle used ONLY before asserting a NON-event (no send) —
    /// gives any erroneous Task a chance to run so the negative is real.
    private func settle() async { try? await Task.sleep(for: .milliseconds(40)) }

    // MARK: - 1. Pure mapping (no spy)

    @Test("webDisplayState maps the LAN vocabulary onto the narrower web one (frozen→live)")
    func webDisplayStateMapping() {
        #expect(ServiceMirrorController.webDisplayState(for: .lyrics) == "live")
        #expect(ServiceMirrorController.webDisplayState(for: .frozen) == "live")
        #expect(ServiceMirrorController.webDisplayState(for: .blackout) == "blackout")
        #expect(ServiceMirrorController.webDisplayState(for: .logo) == "logo")
    }

    @Test("mirrorKey is nil with no song, and ignores lineIndex/revision but not song/component/display")
    func mirrorKeySensitivity() {
        #expect(ServiceMirrorController.mirrorKey(for: state(song: nil)) == nil)
        let base = ServiceMirrorController.mirrorKey(for: state(component: 1, line: 3, revision: 5))
        // lineIndex + revision are OUTSIDE the key (web has no line cursor).
        #expect(base == ServiceMirrorController.mirrorKey(for: state(component: 1, line: 99, revision: 900)))
        // frozen collapses to live, so lyrics↔frozen is the SAME key.
        #expect(base == ServiceMirrorController.mirrorKey(for: state(component: 1, display: .frozen)))
        // song, component and (lyrics↔blackout) each change the key.
        #expect(base != ServiceMirrorController.mirrorKey(for: state(song: "MP-2000", component: 1)))
        #expect(base != ServiceMirrorController.mirrorKey(for: state(component: 2)))
        #expect(base != ServiceMirrorController.mirrorKey(for: state(component: 1, display: .blackout)))
    }

    // MARK: - 2. Dormancy

    @Test("observe() while .off never touches the network")
    func dormantWhenOff() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.observe(state())
        await settle()
        #expect(await spy.callCount == 0)
        #expect(controller.phase == .off)
    }

    @Test("disarm() goes straight to .off and sends nothing further")
    func disarmStops() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 5, currentState: state())
        await waitUntil { await spy.callCount == 1 }
        controller.disarm()
        #expect(controller.phase == .off)
        controller.observe(state(component: 9))   // .off ⇒ no-op
        await settle()
        #expect(await spy.callCount == 1)
    }

    // MARK: - 3. Arm

    @Test("arm() with a current song mirrors it immediately")
    func armMirrorsImmediately() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 12, currentState: state(song: "MP-1008", component: 2, display: .blackout))
        await waitUntil { await spy.callCount == 1 }
        #expect(controller.phase == .active(sessionId: 12))
        let call = await spy.calls.first
        #expect(call == .init(sessionId: 12, songId: "MP-1008", componentIndex: 2, displayState: "blackout"))
    }

    @Test("arm() with no song yet is armed-but-silent")
    func armWithNoSongIsSilent() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 7, currentState: state(song: nil))
        await settle()
        #expect(await spy.callCount == 0)
        #expect(controller.phase == .active(sessionId: 7))
    }

    // MARK: - 4. Dedup

    @Test("an identical echo does not re-broadcast; a real change does")
    func dedupSkipsUnchanged() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 3, currentState: state(component: 1))
        await waitUntil { await spy.callCount == 1 }
        // Same song/component/display (only revision/line differ) ⇒ skipped.
        controller.observe(state(component: 1, line: 8, revision: 42))
        await settle()
        #expect(await spy.callCount == 1)
        // A component change ⇒ a new broadcast.
        controller.observe(state(component: 2))
        await waitUntil { await spy.callCount == 2 }
        #expect(await spy.callCount == 2)
    }

    // MARK: - 5. Single-flight + coalescing

    @Test("rapid updates during a send coalesce to latest-wins — the middle one is dropped")
    func coalescesToLatest() async {
        let spy = BroadcastSpy()
        await spy.enableGate()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 9, currentState: state(component: 1))   // send #1 (A) starts, suspends at the gate
        await waitUntil { await spy.callCount == 1 }
        controller.observe(state(component: 2))   // pends B (send in flight)
        controller.observe(state(component: 3))   // pends C, overwriting B
        await spy.releaseGateAndOpen()            // A completes ⇒ the coalesced C sends
        await waitUntil { await spy.callCount == 2 }
        await settle()
        let components = await spy.calls.map(\.componentIndex)
        #expect(components == [1, 3])             // B (component 2) was never sent
        #expect(controller.phase == .active(sessionId: 9))
    }

    // MARK: - 6. Terminal failures

    @Test("unauthorized / 403 / 404 stop the mirror with an explanation and no retry", arguments: [
        APIError.unauthorized, APIError.server(status: 403, message: nil), APIError.server(status: 404, message: nil)
    ])
    func terminalFailuresStop(_ error: APIError) async {
        let spy = BroadcastSpy()
        await spy.setPersistentError(error)
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 4, currentState: state())
        await waitUntil { controller.phase == .off }
        #expect(controller.phase == .off)
        #expect(controller.notice != nil)
        controller.observe(state(component: 5))   // .off ⇒ no retry, no send
        await settle()
        #expect(await spy.callCount == 1)
    }

    // MARK: - 6b. Frozen (#1562 F-2)

    @Test("observe() while the TV is frozen never sends — the operator's in-flight navigation doesn't leak to congregants")
    func observeWhileFrozenNeverSends() async {
        let spy = BroadcastSpy()
        let controller = makeController(spy: spy)
        controller.arm(sessionId: 11, currentState: state(component: 1))
        await waitUntil { await spy.callCount == 1 }
        // Navigating WHILE frozen — the venue screen stays pinned to the
        // old snapshot, so this must not mirror.
        controller.observe(state(component: 2, display: .frozen))
        await settle()
        #expect(await spy.callCount == 1)
        // Unfreezing (a genuinely different mirrorKey) resumes mirroring
        // automatically, with no extra bookkeeping needed.
        controller.observe(state(component: 2, display: .lyrics))
        await waitUntil { await spy.callCount == 2 }
        #expect(await spy.callCount == 2)
    }

    // MARK: - 7. Retryable failures

    @Test("a retryable failure degrades but stays armed")
    func retryableDegrades() async {
        let spy = BroadcastSpy()
        await spy.setPersistentError(APIError.maintenance(retryAfterSeconds: nil))
        let controller = makeController(spy: spy, retryDelaySeconds: 60)   // park the retry out of the test window
        controller.arm(sessionId: 8, currentState: state())
        await waitUntil { controller.phase == .degraded(sessionId: 8) }
        #expect(controller.phase == .degraded(sessionId: 8))
        #expect(controller.notice != nil)
        controller.disarm()   // cancel the parked retry
    }

    @Test("a degraded mirror recovers on the next successful retry, then dedups")
    func retryRecoversThenDedups() async {
        let spy = BroadcastSpy()
        await spy.failNext(1, with: APIError.rateLimited(retryAfterSeconds: nil))
        let controller = makeController(spy: spy, retryDelaySeconds: 0)
        controller.arm(sessionId: 6, currentState: state(component: 1))
        // arm() sets .active IMMEDIATELY, so that can't be the recovery
        // signal — wait on the real one: two sends made (fail + retry) AND
        // the "Reconnecting…" notice cleared, which only a 200 does.
        await waitUntil { await spy.callCount == 2 && controller.notice == nil }
        #expect(controller.phase == .active(sessionId: 6))
        #expect(await spy.callCount == 2)
        #expect(controller.notice == nil)
        // lastAcceptedKey was set ONLY on the successful retry, so the same
        // state now dedups (no third send).
        controller.observe(state(component: 1))
        await settle()
        #expect(await spy.callCount == 2)
    }

    // MARK: - 8. Skippable failures (#1562 F-3)

    @Test("a 400/422 'this song isn't recognised' is SKIPPED — stays .active (never .degraded), and schedules no retry", arguments: [
        APIError.server(status: 400, message: nil), APIError.server(status: 422, message: nil)
    ])
    func skippableFailureStaysActiveWithNoRetry(_ error: APIError) async {
        let spy = BroadcastSpy()
        await spy.setPersistentError(error)
        let controller = makeController(spy: spy, retryDelaySeconds: 0)
        controller.arm(sessionId: 13, currentState: state(component: 1))
        // `notice` starts `nil` (set by `arm()`) and only a completed
        // failure handler sets it — a reliable signal the async send +
        // `handleFailure` have both finished, unlike racing on `callCount`.
        await waitUntil { controller.notice != nil }
        #expect(controller.phase == .active(sessionId: 13))
        #expect(controller.notice == "That song isn't available to the congregation.")
        // No retry Task was scheduled — settling past the (zero-second)
        // retry delay proves nothing more was sent automatically (a
        // `.degraded` retry loop would have sent again by now).
        await settle()
        #expect(await spy.callCount == 1)
        // `lastAcceptedKey` was set to the FAILED send's own key, so the
        // identical state is treated as already-mirrored and dedups.
        controller.observe(state(component: 1))
        await settle()
        #expect(await spy.callCount == 1)
    }

    @Test("a state coalesced during a skipped send is still tried — it's a NEW state, not a retry of the rejected one")
    func skippableFailureStillTriesACoalescedNewerState() async {
        let spy = BroadcastSpy()
        await spy.enableGate()
        let controller = makeController(spy: spy, retryDelaySeconds: 0)
        controller.arm(sessionId: 15, currentState: state(component: 1))   // send #1 (component 1) suspends at the gate
        await waitUntil { await spy.callCount == 1 }
        controller.observe(state(component: 2))   // coalesces while #1 is in flight
        await spy.setPersistentError(APIError.server(status: 400, message: nil))
        await spy.releaseGateAndOpen()   // #1 resumes and fails with 400 -> skip -> re-observes the coalesced component 2
        await waitUntil { await spy.callCount == 2 }
        let components = await spy.calls.map(\.componentIndex)
        #expect(components == [1, 2])
        #expect(controller.phase == .active(sessionId: 15))
    }
}
