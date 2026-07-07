// SongNavigationContextTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "what song comes before/after this one?" lookup
// (`SongNavigationContext`) gets the edges right — first song has no
// previous, last song has no next, an id that isn't even in the list
// doesn't crash.
//
// DETAILED: #185 (Apple Phase 1, navigation & UX consolidation). Pure
// Swift Testing coverage for pure logic, per this task's "any decision
// logic" test requirement — no view, no view model, no async, matching
// `SongNavigationContext`'s own deliberately side-effect-free design.
import Testing
@testable import IHFeatures
import IHModels

@Suite("SongNavigationContext (#185)")
struct SongNavigationContextTests {
    // The non-failable `songbookAbbreviation:number:` initializer (rather
    // than `rawValue:`, which is failable and would need `try #require(...)`
    // per call — the repo's own test convention, see e.g.
    // `HomeViewModelTests.swift`) keeps these as plain, non-optional `let`s.
    private let first = SongID(songbookAbbreviation: "MP", number: 1)
    private let second = SongID(songbookAbbreviation: "MP", number: 2)
    private let third = SongID(songbookAbbreviation: "MP", number: 3)
    private let stranger = SongID(songbookAbbreviation: "MP", number: 9999)

    @Test("previousId(before:) is nil for the first song")
    func previousIsNilAtStart() {
        let context = SongNavigationContext(songIds: [first, second, third])
        #expect(context.previousId(before: first) == nil)
    }

    @Test("previousId(before:) steps back one position")
    func previousStepsBack() {
        let context = SongNavigationContext(songIds: [first, second, third])
        #expect(context.previousId(before: second) == first)
        #expect(context.previousId(before: third) == second)
    }

    @Test("nextId(after:) is nil for the last song")
    func nextIsNilAtEnd() {
        let context = SongNavigationContext(songIds: [first, second, third])
        #expect(context.nextId(after: third) == nil)
    }

    @Test("nextId(after:) steps forward one position")
    func nextStepsForward() {
        let context = SongNavigationContext(songIds: [first, second, third])
        #expect(context.nextId(after: first) == second)
        #expect(context.nextId(after: second) == third)
    }

    @Test("a single-song context has neither a previous nor a next")
    func singleSongContextHasNoNeighbours() {
        let context = SongNavigationContext(songIds: [first])
        #expect(context.previousId(before: first) == nil)
        #expect(context.nextId(after: first) == nil)
    }

    @Test("an id absent from the context returns nil both directions")
    func absentIdReturnsNil() {
        let context = SongNavigationContext(songIds: [first, second, third])
        #expect(context.previousId(before: stranger) == nil)
        #expect(context.nextId(after: stranger) == nil)
    }

    @Test("an empty context returns nil for any id")
    func emptyContextReturnsNil() {
        let context = SongNavigationContext(songIds: [])
        #expect(context.previousId(before: first) == nil)
        #expect(context.nextId(after: first) == nil)
    }
}
