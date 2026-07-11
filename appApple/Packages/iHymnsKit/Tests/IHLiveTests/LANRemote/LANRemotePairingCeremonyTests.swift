// LANRemotePairingCeremonyTests.swift
// IHLiveTests
//
// ELI5: Checks the pairing-ceremony rulebook does exactly what it says —
// a code goes stale after its TTL, a used code can't be reused, enough
// wrong guesses trigger a "please rotate" signal, and closing the pairing
// screen fully resets things. Also checks the little QR-payload string
// format round-trips and rejects garbage.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.2 — BINDING).
// `LANRemotePairingCeremonyState` is pure (`LANRemotePairingCeremony.swift`'s
// header) — every test below uses literal `Date(timeIntervalSince1970:)`
// values, never a clock object or a real sleep, the same posture
// `IHRPFrameOrderingTests.swift` already establishes for `IHRPFrame`'s own
// last-writer-wins ordering. `LANRemotePairingPayloadTests` (this file's
// second `@Suite`) is folded in here rather than given its own file — spec
// §7.5's own suggestion ("fold into `LANRemotePairingCeremonyTests`'s file
// if LOC allows") — since both are small, always-on, no-network suites.
import Foundation
import Testing
@testable import IHLive

@Suite("LANRemotePairingCeremonyState")
struct LANRemotePairingCeremonyTests {

    private let epoch = Date(timeIntervalSince1970: 1_700_000_000)

    @Test("Configuration defaults are strategy's exact numbers — 120s TTL / 5 fails / 3 attempts / 15 ceiling")
    func configurationDefaults() {
        let configuration = LANRemotePairingCeremonyState.Configuration()
        #expect(configuration.codeTTL == 120)
        #expect(configuration.rotateAfterFailures == 5)
        #expect(configuration.maxAttemptsPerConnection == 3)
        // Post-#1421 adversarial-review brute-force ceiling — 3 codes' worth
        // of rotation before the whole ceremony is disarmed.
        #expect(configuration.maxCeremonyFailures == 15)
    }

    @Test("begin() arms the ceremony and isActive(now:) holds until exactly the TTL boundary")
    func beginAndTTLBoundary() {
        var state = LANRemotePairingCeremonyState()
        state.begin(code: "042317", now: epoch)

        #expect(state.activeCode == "042317")
        #expect(state.isActive(now: epoch))
        #expect(state.isActive(now: epoch.addingTimeInterval(119.9)))
        #expect(!state.isActive(now: epoch.addingTimeInterval(120.1)))
    }

    @Test("A ceremony that was never begun is never active")
    func neverBegunIsInactive() {
        let state = LANRemotePairingCeremonyState()
        #expect(state.activeCode == nil)
        #expect(!state.isActive(now: epoch))
    }

    @Test("consume() is single-use — the code is inactive immediately after")
    func consumeIsSingleUse() {
        var state = LANRemotePairingCeremonyState()
        state.begin(code: "042317", now: epoch)
        state.consume()

        #expect(state.activeCode == nil)
        #expect(!state.isActive(now: epoch))
    }

    @Test("recordFailure() signals rotation only on the configured Nth failure, and begin() resets the count")
    func recordFailureRotationThreshold() {
        var state = LANRemotePairingCeremonyState()
        state.begin(code: "042317", now: epoch)

        for expectedCount in 1...4 {
            let outcome = state.recordFailure()
            #expect(outcome == .recorded)
            #expect(state.wrongAttempts == expectedCount)
        }
        // The 5th failure hits `rotateAfterFailures` (default 5) — the
        // rotation signal fires (still well under the 15 cumulative ceiling).
        let fifthOutcome = state.recordFailure()
        #expect(fifthOutcome == .rotate)
        #expect(state.wrongAttempts == 5)
        #expect(state.ceremonyFailures == 5)

        // A fresh `begin(...)` (a brand-new operator-opened ceremony) resets
        // BOTH the per-code count AND the cumulative ceremony count.
        state.begin(code: "998877", now: epoch)
        #expect(state.wrongAttempts == 0)
        #expect(state.ceremonyFailures == 0)
    }

    @Test("rotate() resets the per-code count but PRESERVES the cumulative ceremonyFailures")
    func rotatePreservesCumulativeCount() {
        var state = LANRemotePairingCeremonyState()
        state.begin(code: "042317", now: epoch)
        state.recordFailure()
        state.recordFailure()
        #expect(state.wrongAttempts == 2)
        #expect(state.ceremonyFailures == 2)

        // A mid-ceremony rotation (the caller's response to `.rotate`) clears
        // the per-code count for the fresh code but must NOT hand a brute-
        // forcer a fresh cumulative budget.
        state.rotate(code: "998877", now: epoch)
        #expect(state.activeCode == "998877")
        #expect(state.wrongAttempts == 0)
        #expect(state.ceremonyFailures == 2)
    }

    @Test("recordFailure() returns .exhausted once the cumulative ceiling is reached, across rotations")
    func cumulativeCeilingExhaustsCeremony() {
        // A small ceiling so the whole path is exercised without staging 15
        // real failures: rotate every 2, disarm the ceremony after 4 total.
        let configuration = LANRemotePairingCeremonyState.Configuration(
            rotateAfterFailures: 2, maxCeremonyFailures: 4
        )
        var state = LANRemotePairingCeremonyState(configuration: configuration)
        state.begin(code: "111111", now: epoch)

        #expect(state.recordFailure() == .recorded)          // 1 (cumulative 1)
        #expect(state.recordFailure() == .rotate)            // 2 (cumulative 2) → rotate
        state.rotate(code: "222222", now: epoch)             // preserves cumulative 2
        #expect(state.recordFailure() == .recorded)          // 1 (cumulative 3)
        // Cumulative 4 reaches maxCeremonyFailures — `.exhausted` wins over
        // the per-code `.rotate` it would otherwise have signalled.
        #expect(state.recordFailure() == .exhausted)         // 2 (cumulative 4)
        #expect(state.ceremonyFailures == 4)
    }

    @Test("end() fully disarms — begin() afterward re-arms from a clean slate")
    func endThenReBegin() {
        var state = LANRemotePairingCeremonyState()
        state.begin(code: "042317", now: epoch)
        state.recordFailure()
        state.end()

        #expect(state.activeCode == nil)
        #expect(!state.isActive(now: epoch))

        state.begin(code: "556677", now: epoch)
        #expect(state.activeCode == "556677")
        #expect(state.isActive(now: epoch))
    }

    @Test("A custom Configuration is honoured end to end")
    func customConfiguration() {
        let configuration = LANRemotePairingCeremonyState.Configuration(
            codeTTL: 10, rotateAfterFailures: 2, maxAttemptsPerConnection: 1
        )
        var state = LANRemotePairingCeremonyState(configuration: configuration)
        state.begin(code: "042317", now: epoch)

        #expect(state.isActive(now: epoch.addingTimeInterval(9.9)))
        #expect(!state.isActive(now: epoch.addingTimeInterval(10.1)))
        let firstOutcome = state.recordFailure()
        #expect(firstOutcome == .recorded)
        let secondOutcome = state.recordFailure()
        #expect(secondOutcome == .rotate)
    }
}

@Suite("LANRemotePairingPayload QR string codec")
struct LANRemotePairingPayloadTests {

    @Test("A payload with every field present round-trips through encode/decode unchanged")
    func roundTripFullPayload() throws {
        let original = LANRemotePairingPayload(
            name: "iHymns TV — Fellowship Hall", host: "192.168.1.42", port: 7269,
            fingerprintHex: String(repeating: "ab", count: 32), code: "042317"
        )
        let encoded = try #require(original.encodeToQRString())
        #expect(encoded.hasPrefix("ihymns-lanpair:v1:"))

        let decoded = try #require(LANRemotePairingPayload(qrString: encoded))
        #expect(decoded == original)
    }

    @Test("The Settings 'standing' QR (code: nil, host/port absent) round-trips with those fields absent")
    func roundTripStandingQR() throws {
        let original = LANRemotePairingPayload(name: "iHymns TV", fingerprintHex: String(repeating: "cd", count: 32))
        let encoded = try #require(original.encodeToQRString())
        let decoded = try #require(LANRemotePairingPayload(qrString: encoded))

        #expect(decoded.code == nil)
        #expect(decoded.host == nil)
        #expect(decoded.port == nil)
        #expect(decoded == original)
    }

    @Test("A string missing the version prefix is rejected")
    func rejectsMissingPrefix() {
        #expect(LANRemotePairingPayload(qrString: "not-a-pairing-code") == nil)
    }

    @Test("A prefixed but non-base64 payload is rejected")
    func rejectsGarbageAfterPrefix() {
        #expect(LANRemotePairingPayload(qrString: "ihymns-lanpair:v1:!!!not-base64!!!") == nil)
    }

    @Test("A prefixed, valid-base64, but non-JSON payload is rejected")
    func rejectsNonJSONPayload() {
        let garbage = Data("not json".utf8).base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
        #expect(LANRemotePairingPayload(qrString: "ihymns-lanpair:v1:" + garbage) == nil)
    }

    @Test("An empty string is rejected")
    func rejectsEmptyString() {
        #expect(LANRemotePairingPayload(qrString: "") == nil)
    }
}
