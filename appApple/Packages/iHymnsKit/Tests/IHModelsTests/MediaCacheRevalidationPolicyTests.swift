// MediaCacheRevalidationPolicyTests.swift
// IHModelsTests
//
// ELI5: Proves the "should we re-check/re-download this cached file?"
// rulebook (#1450) gives the right answer for the three situations that
// matter — checked too recently (leave it alone), checked now and the
// server's own size still matches (still fresh, just note the check), and
// checked now and the size has changed (stale — go get a fresh copy) —
// with pure values, no network/disk/mocking involved at all.
//
// DETAILED: `MediaCacheRevalidationPolicy.decide(...)` is deliberately pure
// (no I/O, `now`/`staleAfter` both injectable) specifically so this suite
// can drive it directly, mirroring `SongIDTests.swift`'s "hand-built inputs,
// direct assertions" shape for another pure `IHModels` type.
import Foundation
import Testing
@testable import IHModels

@Suite("MediaCacheRevalidationPolicy (#1450)")
struct MediaCacheRevalidationPolicyTests {
    private static let referenceNow = Date(timeIntervalSince1970: 1_800_000_000)

    @Test("within the TTL window, no matter what changed, the answer is always 'skip — too recent'")
    func withinWindowAlwaysSkips() {
        let justValidated = Self.referenceNow.addingTimeInterval(-60) // one minute ago
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 100,
                serverSizeBytes: 100,
                lastValidatedAt: justValidated,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .skipTooRecent
        )
        // Even a genuinely CHANGED size is still skipped inside the window
        // — the whole point of a TTL is "don't even look yet," not "look,
        // but only act on it later."
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 100,
                serverSizeBytes: 999,
                lastValidatedAt: justValidated,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .skipTooRecent
        )
    }

    @Test("past the TTL window with a matching size is 'still fresh'")
    func pastWindowMatchingSizeIsStillFresh() {
        let longAgo = Self.referenceNow.addingTimeInterval(-100_000)
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 4_096,
                serverSizeBytes: 4_096,
                lastValidatedAt: longAgo,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .stillFresh
        )
    }

    @Test("past the TTL window with a changed size is 'stale — needs refetch'")
    func pastWindowChangedSizeNeedsRefetch() {
        let longAgo = Self.referenceNow.addingTimeInterval(-100_000)
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 4_096,
                serverSizeBytes: 5_000,
                lastValidatedAt: longAgo,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .staleNeedsRefetch
        )
    }

    @Test("exactly at the TTL boundary counts as due for a check (inclusive)")
    func exactlyAtBoundaryIsDue() {
        let exactlyStaleAfter = Self.referenceNow.addingTimeInterval(-3_600)
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 10,
                serverSizeBytes: 10,
                lastValidatedAt: exactlyStaleAfter,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .stillFresh
        )
    }

    @Test("a lastValidatedAt in the future (clock skew) is treated as too recent, never as an error")
    func futureLastValidatedAtIsSkipped() {
        let future = Self.referenceNow.addingTimeInterval(60)
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 10,
                serverSizeBytes: 999,
                lastValidatedAt: future,
                now: Self.referenceNow,
                staleAfter: 3_600
            ) == .skipTooRecent
        )
    }

    @Test("defaultStaleAfter is a full day, matching the documented TTL")
    func defaultStaleAfterIsOneDay() {
        #expect(MediaCacheRevalidationPolicy.defaultStaleAfter == 86_400)
    }

    // MARK: - isDueForRecheck / decideConditional (#1460)

    @Test("isDueForRecheck is false within the TTL window")
    func isDueForRecheckFalseWithinWindow() {
        let justValidated = Self.referenceNow.addingTimeInterval(-60)
        #expect(MediaCacheRevalidationPolicy.isDueForRecheck(lastValidatedAt: justValidated, now: Self.referenceNow, staleAfter: 3_600) == false)
    }

    @Test("isDueForRecheck is true past the TTL window, inclusive at the exact boundary")
    func isDueForRecheckTruePastWindow() {
        let longAgo = Self.referenceNow.addingTimeInterval(-100_000)
        #expect(MediaCacheRevalidationPolicy.isDueForRecheck(lastValidatedAt: longAgo, now: Self.referenceNow, staleAfter: 3_600))
        let exactlyStaleAfter = Self.referenceNow.addingTimeInterval(-3_600)
        #expect(MediaCacheRevalidationPolicy.isDueForRecheck(lastValidatedAt: exactlyStaleAfter, now: Self.referenceNow, staleAfter: 3_600))
    }

    @Test("decide(...) and isDueForRecheck agree on the same TTL gate")
    func decideAndIsDueForRecheckAgree() {
        let justValidated = Self.referenceNow.addingTimeInterval(-60)
        #expect(MediaCacheRevalidationPolicy.isDueForRecheck(lastValidatedAt: justValidated, now: Self.referenceNow, staleAfter: 3_600) == false)
        #expect(
            MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: 10, serverSizeBytes: 999, lastValidatedAt: justValidated, now: Self.referenceNow, staleAfter: 3_600
            ) == .skipTooRecent
        )
    }

    @Test("decideConditional maps a real 304 (notModified) to stillFresh")
    func decideConditionalNotModifiedIsStillFresh() {
        #expect(MediaCacheRevalidationPolicy.decideConditional(outcome: .notModified) == .stillFresh)
    }

    @Test("decideConditional maps a real 200 (replaced) to staleNeedsRefetch")
    func decideConditionalReplacedIsStaleNeedsRefetch() {
        #expect(MediaCacheRevalidationPolicy.decideConditional(outcome: .replaced) == .staleNeedsRefetch)
    }
}
