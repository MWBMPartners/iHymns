// LANRemoteIdentityStoreTests.swift
// IHLiveTests
//
// ELI5: Proves the TV's "keep the same face forever" box actually works —
// asking for the identity twice in a row gives back the SAME identity both
// times, and deliberately throwing it away then asking again gives back a
// genuinely DIFFERENT one.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.4). Gated on
// `lanRemoteKeychainIdentityAvailable()` — the SAME headless-CI limitation
// `LANRemoteIdentityTests.swift` (PR-4/#1420) already documents in full:
// an unsigned `swift test` binary can `SecItemAdd` but its
// `kSecClassIdentity` query comes back `errSecItemNotFound` with no login
// keychain to bridge into. Every test cleans up via `LANRemoteIdentityStore
// .reset()` in a `defer` so a full suite run never leaves this PR's
// FIXED-label identity (`"app.ihymns.lanremote.identity.v1"`, unlike PR-4's
// throwaway per-call UUID labels) behind in the developer/CI Keychain.
import Foundation
import Testing
@testable import IHLive

@Suite(
    "LANRemoteIdentityStore persistence",
    // `.serialized` (NOT the default parallel execution) — every test in
    // this suite shares the ONE fixed Keychain label
    // (`LANRemoteIdentityStore.identityLabel`, deliberately fixed so a
    // relaunch finds the SAME identity — this file's own header comment).
    // Running two of these tests concurrently would race two independent
    // reset()/loadOrCreate() sequences against that single shared label,
    // which is a TEST-ISOLATION hazard, not a real code bug — unlike
    // `KeychainPairingAuthorityTests`, which sidesteps this entirely by
    // giving every test its OWN randomly-suffixed `service`.
    .serialized,
    .enabled(
        if: lanRemoteKeychainIdentityAvailable(),
        "Needs login-keychain SecIdentity bridging an unsigned headless `swift test` binary can't do — see LANRemoteIdentityTests.swift's identical gate."
    )
)
struct LANRemoteIdentityStoreTests {

    @Test("loadOrCreate() called twice returns the SAME fingerprint and certificate DER")
    func sameIdentityAcrossCalls() async throws {
        // `KeychainTestSerialization` (`LANRemoteTestSupport.swift`) — see
        // its doc comment: needed to stop THIS suite's Keychain calls from
        // overlapping `LANRemoteIdentityTests`'/`KeychainPairingAuthorityTests`'
        // own, which is what actually caused #1421's observed flakiness
        // (a per-suite `.serialized` trait alone does not prevent that).
        try await KeychainTestSerialization.shared.run {
            LANRemoteIdentityStore.reset()
            defer { LANRemoteIdentityStore.reset() }

            let first = try LANRemoteIdentityStore.loadOrCreate()
            let second = try LANRemoteIdentityStore.loadOrCreate()

            #expect(first.fingerprint == second.fingerprint)
            #expect(first.certificateDER == second.certificateDER)
        }
    }

    @Test("reset() then loadOrCreate() mints a genuinely DIFFERENT identity")
    func resetThenLoadOrCreateMintsNewIdentity() async throws {
        try await KeychainTestSerialization.shared.run {
            LANRemoteIdentityStore.reset()
            defer { LANRemoteIdentityStore.reset() }

            let original = try LANRemoteIdentityStore.loadOrCreate()
            LANRemoteIdentityStore.reset()
            let afterReset = try LANRemoteIdentityStore.loadOrCreate()

            #expect(original.fingerprint != afterReset.fingerprint)
            #expect(original.certificateDER != afterReset.certificateDER)
        }
    }

    @Test("makeServerTLSOptions() succeeds on a LOADED (not just freshly-generated) identity")
    func serverTLSOptionsBuildableAfterReload() async throws {
        try await KeychainTestSerialization.shared.run {
            LANRemoteIdentityStore.reset()
            defer { LANRemoteIdentityStore.reset() }

            _ = try LANRemoteIdentityStore.loadOrCreate()
            // The SECOND call exercises the `wrapExisting(_:)` "found it in
            // the Keychain" path specifically (the first call always takes
            // the "generate fresh" branch on a clean slate).
            let reloaded = try LANRemoteIdentityStore.loadOrCreate()
            _ = try reloaded.makeServerTLSOptions()
        }
    }
}
