// NowSingingSectionNavigatorTests.swift
// IHLiveActivityTests
//
// ELI5: Checks the Next/Previous clamp math — you can't go before the first
// section, you can't go past the last one (when we know how many there
// are), and tapping a direction that wouldn't actually move anything is a
// no-op (`nil`), never a redundant broadcast.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Table-driven over
// `NowSingingSectionNavigator.target(current:delta:sectionCount:)`'s full
// clamp contract (this file's header doc comment).
import Foundation
import Testing
@testable import IHLiveActivity

@Suite("NowSingingSectionNavigator.target(current:delta:sectionCount:)")
struct NowSingingSectionNavigatorTests {

    @Test("delta == 0 is always a no-op, regardless of current/sectionCount")
    func zeroDeltaIsAlwaysNoOp() {
        #expect(NowSingingSectionNavigator.target(current: nil, delta: 0, sectionCount: nil) == nil)
        #expect(NowSingingSectionNavigator.target(current: 3, delta: 0, sectionCount: 10) == nil)
    }

    @Test("Next (delta > 0) from nil starts at section 0")
    func nextFromNilStartsAtZero() {
        #expect(NowSingingSectionNavigator.target(current: nil, delta: 1, sectionCount: nil) == 0)
        #expect(NowSingingSectionNavigator.target(current: nil, delta: 5, sectionCount: nil) == 0)
    }

    @Test("Previous (delta < 0) from nil is a no-op — nothing before the start")
    func previousFromNilIsNoOp() {
        #expect(NowSingingSectionNavigator.target(current: nil, delta: -1, sectionCount: nil) == nil)
    }

    @Test("Previous at the lower bound (0) clamps and is a no-op — unchanged result")
    func previousAtLowerBoundIsNoOp() {
        #expect(NowSingingSectionNavigator.target(current: 0, delta: -1, sectionCount: nil) == nil)
        #expect(NowSingingSectionNavigator.target(current: 0, delta: -1, sectionCount: 5) == nil)
    }

    @Test("Next with no known sectionCount just increments, unbounded above")
    func nextWithUnknownCountIncrements() {
        #expect(NowSingingSectionNavigator.target(current: 2, delta: 1, sectionCount: nil) == 3)
    }

    @Test("Next at the upper bound (sectionCount - 1) clamps and is a no-op")
    func nextAtUpperBoundIsNoOp() {
        #expect(NowSingingSectionNavigator.target(current: 2, delta: 1, sectionCount: 3) == nil)
    }

    @Test("Next just below the upper bound moves to the last section")
    func nextBelowUpperBoundMoves() {
        #expect(NowSingingSectionNavigator.target(current: 1, delta: 1, sectionCount: 3) == 2)
    }

    @Test("A large negative delta clamps to 0, not a negative index")
    func largeNegativeDeltaClampsToZero() {
        #expect(NowSingingSectionNavigator.target(current: 5, delta: -10, sectionCount: nil) == 0)
    }

    @Test("A large positive delta clamps to the last section")
    func largePositiveDeltaClampsToUpperBound() {
        #expect(NowSingingSectionNavigator.target(current: 0, delta: 100, sectionCount: 4) == 3)
    }
}
