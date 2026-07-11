// IHRPFrameOrderingTests.swift
// IHLiveTests
//
// ELI5: If two remotes both press a button at almost the same moment,
// which command does the TV actually listen to? This file proves the
// "whoever's clock says later wins" rule, both as a plain function AND as
// `TVListenerActor` actually applies it — with NO real networking and NO
// wall-clock sleeps, just directly-constructed timestamps.
//
// DETAILED: #1420 (task brief: "the seq/last-writer-wins ordering" test +
// "Injected clock (no wall-clock sleeps)"). `TVListenerActor.applyLastWriterWins(_:)`
// is `internal` (module-visible, not `private`) precisely so this file can
// call it directly via `@testable import IHLive` without needing two full
// `RemoteSessionActor`s racing real packets over 127.0.0.1 — that end-to-end
// shape IS exercised too (`LANRemoteLoopbackTests`'s control-message test),
// but ORDERING LOGIC itself deserves a test that can't be flaky for
// network-timing reasons.
import Foundation
import IHModels
import Testing
@testable import IHLive

@Suite("IHRP last-writer-wins ordering")
struct IHRPFrameOrderingTests {

    private static let songId = SongID(songbookAbbreviation: "MP", number: 1008)

    private func frame(seq: UInt64, secondsFromEpoch: TimeInterval) -> IHRPFrame {
        IHRPFrame(seq: seq, timestamp: Date(timeIntervalSince1970: secondsFromEpoch), message: .nextComponent)
    }

    // MARK: Pure `IHRPFrame.isMoreRecent(than:)`

    @Test("A later timestamp wins regardless of seq")
    func laterTimestampWins() {
        let older = frame(seq: 99, secondsFromEpoch: 100)
        let newer = frame(seq: 1, secondsFromEpoch: 101)
        #expect(newer.isMoreRecent(than: older))
        #expect(!older.isMoreRecent(than: newer))
    }

    @Test("Equal timestamps fall back to comparing seq")
    func tiedTimestampFallsBackToSeq() {
        let lower = frame(seq: 5, secondsFromEpoch: 100)
        let higher = frame(seq: 6, secondsFromEpoch: 100)
        #expect(higher.isMoreRecent(than: lower))
        #expect(!lower.isMoreRecent(than: higher))
    }

    @Test("A frame is never more recent than an identical copy of itself")
    func identicalFrameIsNotMoreRecent() {
        let one = frame(seq: 5, secondsFromEpoch: 100)
        let two = frame(seq: 5, secondsFromEpoch: 100)
        #expect(!one.isMoreRecent(than: two))
        #expect(!two.isMoreRecent(than: one))
    }

    // MARK: `TVListenerActor.applyLastWriterWins(_:)` — no networking

    private func makeListener() throws -> (TVListenerActor, LANRemoteIdentity) {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        let config = TVListenerActor.Configuration(
            pairingAuthority: InMemoryLANRemotePairingAuthority(),
            clock: ManualLANRemoteClock()
        )
        return (TVListenerActor(identity: identity, configuration: config), identity)
    }

    @Test(
        "The TV accepts a strictly-later frame and rejects a stale one, from two different senders",
        .enabled(
            if: lanRemoteKeychainIdentityAvailable(),
            "Builds a real TVListenerActor, which needs a keychain-bridged identity an unsigned headless `swift test` binary can't create (see lanRemoteKeychainIdentityAvailable()). The pure IHRPFrame.isMoreRecent(than:) tests above cover the same last-writer-wins logic always-on, so CI still verifies it."
        )
    )
    func actorAppliesLastWriterWins() async throws {
        let (listener, identity) = try makeListener()
        defer { identity.removeFromKeychain() }

        // Remote A's command, sent (by clock) at t=100.
        let fromRemoteA = frame(seq: 1, secondsFromEpoch: 100)
        #expect(await listener.applyLastWriterWins(fromRemoteA))

        // Remote B's command, sent at t=105 — strictly later, wins even
        // though it's a totally different connection's own seq counter.
        let fromRemoteB = frame(seq: 1, secondsFromEpoch: 105)
        #expect(await listener.applyLastWriterWins(fromRemoteB))

        // A late-arriving, OLDER frame from remote A (e.g. delayed by
        // network jitter) must NOT win over what's already been accepted.
        let staleFromRemoteA = frame(seq: 2, secondsFromEpoch: 102)
        #expect(await listener.applyLastWriterWins(staleFromRemoteA) == false)
    }
}
