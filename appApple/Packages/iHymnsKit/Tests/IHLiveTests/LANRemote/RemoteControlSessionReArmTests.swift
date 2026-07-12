// RemoteControlSessionReArmTests.swift
// IHLiveTests
//
// ELI5: Proves the fix for a real bug — switching away from the remote
// screen and back used to leave it dead. Checks that once a session is
// told to fully stop, its "what's happening" stream really is done for
// good, and that building a BRAND-NEW session (exactly what the fix now
// does every time the screen reappears) works perfectly fine — same TV,
// same token, straight back to controlling.
//
// DETAILED: #1422 review fix 3 (`.claude/apple-phase2-pr7-spec.md` §3.4 —
// `RemoteControlSession`). Split out of `RemoteControlSessionLoopbackTests
// .swift` — NOT for a topical reason, purely to keep that struct's
// `type_body_length` under SwiftLint's budget (the `+Link.swift`/
// `TVListenerActor+Messages.swift` precedent for splitting an
// over-budget file, applied here to a test target). This is an `extension`
// of the SAME `RemoteControlSessionLoopbackTests` struct/suite — sharing
// its `@Suite` gate (`IHYMNS_LAN_LOOPBACK_TESTS=1`, real 127.0.0.1 TLS —
// macOS Local Network Privacy blocks an unsigned headless `swift test`
// binary from completing a real loopback handshake) and its `makeSession()`
// /`makeListener(...)`/`target(...)` helpers (loosened from `private` to
// `internal` there for exactly this reuse).
//
// **What this test actually proves:** `RemoteControlSession` itself gained
// NO new "re-arm" capability — `stop()` is still terminal, by design (this
// file's header doc on `RemoteControlSession.swift` explains why: full
// teardown, `eventContinuation.finish()`, nothing more is ever yielded).
// The fix that closes the "tab-switch-while-pushed dead screen" review
// finding lives one layer up, in `RemoteControlCoordinator.start()`
// (`IHFeatures`): it now builds a BRAND-NEW `RemoteControlSession` every
// time it (re)runs (`session = RemoteControlSession(configuration:
// sessionConfiguration)`), rather than relying on the single `let` instance
// it used to construct once in `init`. This test exercises exactly that
// premise end-to-end over a real loopback connection: a stopped session's
// stream really is dead, and a freshly-constructed session (mirroring what
// the coordinator now builds) reattaches to the very same TV without a
// hitch.
import Foundation
import Network
import Testing
@testable import IHLive

extension RemoteControlSessionLoopbackTests {

    @Test("stop() finishes a session's events stream for good; a FRESH session (the coordinator's re-arm strategy) reattaches fine")
    func freshSessionAfterStopReattaches() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        // First session: attach, reach `.controlling`, then a deliberate
        // full teardown — the same `stop()` `RemoteControlView.onDisappear`
        // calls (`RemoteControlCoordinator.stop()` → `session.stop()`).
        let firstSession = makeSession()
        var firstEvents = firstSession.events.makeAsyncIterator()
        await firstSession.attach(to: target(port: port, fingerprint: identity.fingerprint, token: token))
        _ = await firstEvents.next() // .connecting
        guard case .controlling = await firstEvents.next() else {
            Issue.record("first session never controlled")
            return
        }
        await firstSession.stop()
        #expect(await firstEvents.next() == nil) // finished for good — nothing more is EVER delivered

        // A brand-new `RemoteControlSession` — exactly what
        // `RemoteControlCoordinator.start()` now builds on every (re)start
        // instead of relying on the dead first instance — reattaches to
        // the SAME listener with no ceremony (the token is still valid),
        // proving the coordinator-level re-arm strategy actually works.
        let secondSession = makeSession()
        defer { Task { await secondSession.stop() } }
        var secondEvents = secondSession.events.makeAsyncIterator()
        await secondSession.attach(to: target(port: port, fingerprint: identity.fingerprint, token: token))
        #expect(await secondEvents.next() == .connecting(attempt: 0))
        guard case .controlling = await secondEvents.next() else {
            Issue.record("expected the fresh post-stop() session to reach .controlling")
            return
        }
    }
}
