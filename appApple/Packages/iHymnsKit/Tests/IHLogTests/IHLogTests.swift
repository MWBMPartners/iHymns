// IHLogTests.swift
// IHLogTests
//
// ELI5: Checks that the labels we use to find our own log lines later
// (`subsystem`/`category`) never quietly change, and that the "safe
// fingerprint" helper always gives the same short answer for the same
// secret.
//
// DETAILED: #1505 (`.claude/live-observability-strategy.md` §6: "Swift:
// `IHLogTests` (subsystem/category constants stable = Console-predicate
// contract; `tokenFingerprint` vector; usable-from-actor)"). `os.Logger`
// does not expose its own `subsystem`/`category` back out once constructed
// (there is no `Logger.subsystem`/`Logger.category` getter in the public
// API), so these tests assert the underlying STRING constants
// (`IHLog.subsystem`, `IHLog.Category.*`) directly, at their source of
// definition, rather than trying to introspect a built `Logger` instance.
// `@testable import IHLog` is required for `Category` (module-internal by
// design — see its doc comment in `IHLog.swift`).
import Foundation
import Testing
@testable import IHLog

@Suite("IHLog")
struct IHLogTests {

    // MARK: - Subsystem/category stability (Console-predicate contract)

    @Test("Subsystem is the one stable Console-predicate value")
    func subsystemIsStable() {
        // Must match IHAppSupport/IHAnalyticsSink.swift:29's literal too —
        // this file cannot import IHAppSupport (wrong layering direction,
        // see Package.swift's layering comment), so that agreement is
        // enforced by code review + this doc comment, not a cross-target
        // test.
        #expect(IHLog.subsystem == "app.ihymns")
    }

    @Test("Category names are stable Console-predicate values")
    func categoryNamesAreStable() {
        #expect(IHLog.Category.api == "api")
        #expect(IHLog.Category.liveFollow == "live-follow")
        #expect(IHLog.Category.remote == "remote")
        #expect(IHLog.Category.discovery == "discovery")
        #expect(IHLog.Category.auth == "auth")
        #expect(IHLog.Category.signposts == "spans")
    }

    // MARK: - Usable from an actor (Sendable)

    /// Not a runtime assertion so much as a COMPILE-time one: if
    /// `Logger`/`OSSignposter` (or `IHLog` itself) ever stopped being safe
    /// to reference from inside an `actor` under Swift 6 strict
    /// concurrency, this test file would fail to BUILD, not just fail to
    /// pass — exactly the guard strategy §6 asks for.
    @Test("IHLog's loggers are usable from inside an actor")
    func usableFromActor() async {
        actor LoggerProbe {
            func touchEveryLogger() {
                IHLog.api.debug("probe")
                IHLog.liveFollow.debug("probe")
                IHLog.remote.debug("probe")
                IHLog.discovery.debug("probe")
                IHLog.auth.debug("probe")
                _ = IHLog.signposter.makeSignpostID()
            }
        }
        let probe = LoggerProbe()
        await probe.touchEveryLogger()
    }
}

@Suite("IHLogSanitize")
struct IHLogSanitizeTests {

    @Test("Known vector: SHA-256('hello') → its 8-char hex prefix")
    func knownVector() {
        // Full SHA-256("hello") is
        // 2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
        // (independently verified via `shasum -a 256`); the function's
        // contract is the first 8 hex characters of that digest.
        #expect(IHLogSanitize.tokenFingerprint("hello") == "2cf24dba")
    }

    @Test("Deterministic — same input always produces the same fingerprint")
    func deterministic() {
        let token = "a-64-hex-character-style-high-entropy-example-token-value-000"
        #expect(IHLogSanitize.tokenFingerprint(token) == IHLogSanitize.tokenFingerprint(token))
    }

    @Test("Different inputs produce different fingerprints")
    func differsForDifferentInput() {
        #expect(IHLogSanitize.tokenFingerprint("token-a") != IHLogSanitize.tokenFingerprint("token-b"))
    }

    @Test("Always exactly 8 lowercase hex characters")
    func fixedLowercaseHexLength() {
        let fingerprint = IHLogSanitize.tokenFingerprint("some-arbitrary-token-value")
        #expect(fingerprint.count == 8)
        #expect(fingerprint.allSatisfy { "0123456789abcdef".contains($0) })
    }

    @Test("Usable from inside an actor")
    func usableFromActor() async {
        actor FingerprintProbe {
            func fingerprint(of token: String) -> String {
                IHLogSanitize.tokenFingerprint(token)
            }
        }
        let probe = FingerprintProbe()
        let fingerprint = await probe.fingerprint(of: "actor-context-token")
        #expect(fingerprint.count == 8)
    }
}
