// TVServiceFollowUIStateTests.swift
// IHFeaturesTests
//
// ELI5: Checks that "given this just happened, and we were in this phase,
// what phase are we in now" table for the tvOS Live tab is exactly right —
// for every kind of thing the service engine can announce.
//
// DETAILED: Apple Phase-2 PR-15 (#1428). Exercises `TVServiceFollowCoordinator
// .phase(after:current:)` directly — a `nonisolated static` pure function,
// so every row here is synchronous, in-process, and needs no engine, no
// network, no `ProjectionViewModel` at all. `IHFeaturesTests` does not
// depend on the `IHLiveTests` target's `ServerLive/` mock-network test
// support (`ActionResponseQueue`/`SleepRecorder`), so this suite covers the
// coordinator's PURE phase table only — the coordinator's actual
// network-touching calls (`join`/`leave`/`start`) are thin `ServiceModeEngine`
// pass-throughs already covered end-to-end by `ServiceModeEngineTests`
// (`IHLiveTests`), and its snapshot-application logic is covered by
// `TVServiceFollowBridgeTests` (this target).
import Foundation
import Testing
import IHLive
@testable import IHFeatures
@testable import IHModels

@Suite("TVServiceFollowCoordinator.phase(after:current:) (#1428)")
struct TVServiceFollowUIStateTests {
    private typealias FollowPhase = TVServiceFollowCoordinator.FollowPhase

    private static let snapshot = LiveBroadcastSnapshot(songId: "MP-0031", componentIndex: 0, state: nil, revision: 1)

    // MARK: - .joined always lands on following(isFresh: true), from any starting phase

    @Test(".joined from .idle → .following(isFresh: true)")
    func joinedFromIdle() {
        let result = TVServiceFollowCoordinator.phase(after: .joined(initial: Self.snapshot), current: .idle)
        #expect(result == .following(isFresh: true))
    }

    @Test(".joined from .joining → .following(isFresh: true)")
    func joinedFromJoining() {
        let result = TVServiceFollowCoordinator.phase(after: .joined(initial: Self.snapshot), current: .joining)
        #expect(result == .following(isFresh: true))
    }

    @Test(".joined from a stale .following(isFresh: false) → resets to .following(isFresh: true)")
    func joinedFromStaleFollowingResetsFreshness() {
        let result = TVServiceFollowCoordinator.phase(after: .joined(initial: Self.snapshot), current: .following(isFresh: false))
        #expect(result == .following(isFresh: true))
    }

    @Test(".joined from .ended(...) (rejoining after a previous session ended) → .following(isFresh: true)")
    func joinedFromEnded() {
        let result = TVServiceFollowCoordinator.phase(after: .joined(initial: Self.snapshot), current: .ended(.serverEnded))
        #expect(result == .following(isFresh: true))
    }

    // MARK: - .snapshot / .presenceChanged never change phase on their own

    @Test(".snapshot leaves phase exactly as it was")
    func snapshotLeavesPhaseUnchanged() {
        #expect(TVServiceFollowCoordinator.phase(after: .snapshot(Self.snapshot), current: .idle) == .idle)
        #expect(TVServiceFollowCoordinator.phase(after: .snapshot(Self.snapshot), current: .following(isFresh: true)) == .following(isFresh: true))
    }

    @Test(".presenceChanged leaves phase exactly as it was")
    func presenceChangedLeavesPhaseUnchanged() {
        #expect(TVServiceFollowCoordinator.phase(after: .presenceChanged, current: .idle) == .idle)
        #expect(TVServiceFollowCoordinator.phase(after: .presenceChanged, current: .following(isFresh: false)) == .following(isFresh: false))
    }

    // MARK: - .left — .userLeft → idle; every other reason → .ended(reason)

    @Test(".left(.userLeft) → .idle")
    func leftUserLeftGoesIdle() {
        #expect(TVServiceFollowCoordinator.phase(after: .left(.userLeft), current: .following(isFresh: true)) == .idle)
    }

    @Test(".left(.serverEnded) → .ended(.serverEnded)")
    func leftServerEndedGoesEnded() {
        #expect(TVServiceFollowCoordinator.phase(after: .left(.serverEnded), current: .following(isFresh: true)) == .ended(.serverEnded))
    }

    @Test(".left(.expired) → .ended(.expired)")
    func leftExpiredGoesEnded() {
        #expect(TVServiceFollowCoordinator.phase(after: .left(.expired), current: .following(isFresh: true)) == .ended(.expired))
    }

    @Test(".left(.superseded) → .ended(.superseded)")
    func leftSupersededGoesEnded() {
        #expect(TVServiceFollowCoordinator.phase(after: .left(.superseded), current: .following(isFresh: true)) == .ended(.superseded))
    }

    @Test(".left(.signedOut) → .ended(.signedOut)")
    func leftSignedOutGoesEnded() {
        #expect(TVServiceFollowCoordinator.phase(after: .left(.signedOut), current: .following(isFresh: true)) == .ended(.signedOut))
    }

    // MARK: - .freshnessChanged only affects an ALREADY-.following phase

    @Test(".freshnessChanged(false) while following flips isFresh to false")
    func freshnessChangedFalseWhileFollowing() {
        let result = TVServiceFollowCoordinator.phase(after: .freshnessChanged(false), current: .following(isFresh: true))
        #expect(result == .following(isFresh: false))
    }

    @Test(".freshnessChanged(true) while following flips isFresh back to true")
    func freshnessChangedTrueWhileFollowing() {
        let result = TVServiceFollowCoordinator.phase(after: .freshnessChanged(true), current: .following(isFresh: false))
        #expect(result == .following(isFresh: true))
    }

    @Test(".freshnessChanged is a no-op while NOT following (.idle/.joining/.ended)")
    func freshnessChangedIgnoredOutsideFollowing() {
        #expect(TVServiceFollowCoordinator.phase(after: .freshnessChanged(false), current: .idle) == .idle)
        #expect(TVServiceFollowCoordinator.phase(after: .freshnessChanged(true), current: .joining) == .joining)
        #expect(TVServiceFollowCoordinator.phase(after: .freshnessChanged(false), current: .ended(.serverEnded)) == .ended(.serverEnded))
    }
}
