// ProjectionResolverTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "what does next/previous/jump/scroll actually mean" maths
// in `ProjectionResolver.swift` gives the exact right answer for a whole
// grid of hand-picked song shapes and starting positions — no view, no
// network, no `ProjectionViewModel`, matching `ProjectionResolver`'s own
// deliberately side-effect-free design (mirrors `SongComparisonEngineTests
// .swift`'s shape for its sibling pure-logic type).
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §7.1). Fixture maps
// are constructed DIRECTLY via `ProjectionSongMap(componentLineCounts:)` —
// no `SongDetail` decoding needed for the resolver-behaviour assertions —
// per the spec's own table (its listed `C = [3]` fixture is not actually
// exercised by any of the spec's worked assertions, so it isn't declared
// here — an unused fixture would just be dead code):
//   A = [4, 2, 4]  (three components, 4/2/4 lines)
//   B = []         (no song / an empty song)
//   D = [2, 0, 3]  (a DEFENSIVE zero-line middle component — shouldn't exist
//                   per the web assembler, but this resolver is a TOTAL
//                   function and must not assume that invariant holds)
//
// The `prevComponent` row below encodes the CORRECTED expectations per the
// task's clarification #1 (the spec's own §7.1 prose has a muddled
// self-correction at its end — ignore that wording): a clamped component
// step is a FULL no-op (position, including `lineIndex`, entirely
// unchanged), exactly as §3 states and `ProjectionResolver.swift`'s own
// header documents.
import Testing
import IHAPI
import IHTestFixtures
@testable import IHFeatures
@testable import IHModels

@Suite("ProjectionResolver (#1504)")
struct ProjectionResolverTests {

    // MARK: - Fixture maps (spec §7.1)

    private let mapA = ProjectionSongMap(componentLineCounts: [4, 2, 4])
    private let mapB = ProjectionSongMap(componentLineCounts: [])
    private let mapD = ProjectionSongMap(componentLineCounts: [2, 0, 3])

    private func pos(_ componentIndex: Int?, _ lineIndex: Int?) -> ProjectionPosition {
        ProjectionPosition(componentIndex: componentIndex, lineIndex: lineIndex)
    }

    // MARK: - initialPosition

    @Test("initialPosition: non-empty song starts at (0, nil); empty song is .none")
    func initialPosition() {
        #expect(ProjectionResolver.initialPosition(map: mapA) == pos(0, nil))
        #expect(ProjectionResolver.initialPosition(map: mapB) == .none)
    }

    // MARK: - clamped

    @Test("clamped: out-of-range indices clamp into bounds; nil lineIndex stays nil; empty song is always .none")
    func clamped() {
        #expect(ProjectionResolver.clamped(componentIndex: 7, lineIndex: 9, map: mapA) == pos(2, 3))
        #expect(ProjectionResolver.clamped(componentIndex: -2, lineIndex: -5, map: mapA) == pos(0, 0))
        #expect(ProjectionResolver.clamped(componentIndex: 1, lineIndex: nil, map: mapA) == pos(1, nil))
        #expect(ProjectionResolver.clamped(componentIndex: 99, lineIndex: 99, map: mapB) == .none)
    }

    // MARK: - nextComponent / prevComponent

    @Test("nextComponent: clamp-as-no-op at the end; mode-preserving line rule; drops line mode into an empty target")
    func nextComponentBehaviour() {
        #expect(ProjectionResolver.nextComponent(from: pos(0, nil), map: mapA) == pos(1, nil))
        #expect(ProjectionResolver.nextComponent(from: pos(2, nil), map: mapA) == pos(2, nil))
        #expect(ProjectionResolver.nextComponent(from: pos(2, 3), map: mapA) == pos(2, 3))
        #expect(ProjectionResolver.nextComponent(from: pos(0, 2), map: mapA) == pos(1, 0))
        #expect(ProjectionResolver.nextComponent(from: pos(0, 0), map: mapD) == pos(1, nil))
    }

    @Test("prevComponent: clamp-as-no-op at the start (corrected expectations, not the spec's muddled prose); mode-preserving line rule")
    func prevComponentBehaviour() {
        // Corrected per this task's clarification #1: a clamped component
        // step at the very start of the song is a FULL no-op — the entire
        // position, including a non-nil lineIndex, is left untouched.
        #expect(ProjectionResolver.prevComponent(from: pos(0, 3), map: mapA) == pos(0, 3))
        #expect(ProjectionResolver.prevComponent(from: pos(1, nil), map: mapA) == pos(0, nil))
        #expect(ProjectionResolver.prevComponent(from: pos(2, 1), map: mapA) == pos(1, 0))
    }

    // MARK: - nextLine / prevLine

    @Test("nextLine: enters line mode, advances, rolls over skipping empty components, clamps at end of song")
    func nextLineBehaviour() {
        #expect(ProjectionResolver.nextLine(from: pos(0, nil), map: mapA) == pos(0, 0))
        #expect(ProjectionResolver.nextLine(from: pos(0, 2), map: mapA) == pos(0, 3))
        #expect(ProjectionResolver.nextLine(from: pos(0, 3), map: mapA) == pos(1, 0))
        #expect(ProjectionResolver.nextLine(from: pos(2, 3), map: mapA) == pos(2, 3))
        #expect(ProjectionResolver.nextLine(from: pos(0, 1), map: mapD) == pos(2, 0))
        #expect(ProjectionResolver.nextLine(from: .none, map: mapB) == .none)
    }

    @Test("prevLine: rolls back skipping empty components, is a no-op at line 0 or from component mode")
    func prevLineBehaviour() {
        #expect(ProjectionResolver.prevLine(from: pos(1, 0), map: mapA) == pos(0, 3))
        #expect(ProjectionResolver.prevLine(from: pos(0, 0), map: mapA) == pos(0, 0))
        #expect(ProjectionResolver.prevLine(from: pos(0, nil), map: mapA) == pos(0, nil))
        #expect(ProjectionResolver.prevLine(from: pos(2, 0), map: mapD) == pos(0, 1))
    }

    // MARK: - jumpLine

    @Test("jumpLine: clamps within the current component; no-op with no song or a zero-line component")
    func jumpLineBehaviour() {
        #expect(ProjectionResolver.jumpLine(to: 99, from: pos(0, 0), map: mapA) == pos(0, 3))
        #expect(ProjectionResolver.jumpLine(to: -5, from: pos(0, 0), map: mapA) == pos(0, 0))
        #expect(ProjectionResolver.jumpLine(to: 2, from: .none, map: mapB) == .none)
        #expect(ProjectionResolver.jumpLine(to: 0, from: pos(1, nil), map: mapD) == pos(1, nil))
    }

    // MARK: - scroll

    @Test("scroll: applies nextLine/prevLine |delta| times, stops early on a no-op, delta 0 is a no-op, a huge delta terminates")
    func scrollBehaviour() {
        #expect(ProjectionResolver.scroll(delta: 3, from: pos(0, nil), map: mapA) == pos(0, 2))
        #expect(ProjectionResolver.scroll(delta: -1, from: pos(1, 0), map: mapA) == pos(0, 3))
        #expect(ProjectionResolver.scroll(delta: 0, from: pos(0, 0), map: mapA) == pos(0, 0))
        #expect(ProjectionResolver.scroll(delta: 1000, from: pos(0, 0), map: mapA) == pos(2, 3))
    }

    // MARK: - ProjectionSongMap derivation

    @Test("ProjectionSongMap(detail:) matches orderedComponents.map(\\.lines.count) against a real fixture")
    func songMapFromRealFixture() throws {
        let data = try ContractFixtures.songDetail()
        let detail = try APIClient.decodeSongDetail(from: data)
        let map = ProjectionSongMap(detail: detail)
        #expect(map.componentLineCounts == detail.orderedComponents.map(\.lines.count))
    }

    @Test("ProjectionSongMap(detail:) honours a non-trivial arrangement, not raw component order")
    func songMapHonoursArrangement() {
        // Stored order: 3-line, 1-line, 2-line. Arrangement reverses it, so
        // the resulting map must read [2, 1, 3] — proof this goes through
        // `orderedComponents`, never the raw `components` array.
        let components = [
            SongComponent(type: "verse", number: 1, lines: ["a", "b", "c"], chords: nil, language: nil, lineIds: [1, 2, 3], lineLanguages: nil),
            SongComponent(type: "chorus", number: 0, lines: ["d"], chords: nil, language: nil, lineIds: [4], lineLanguages: nil),
            SongComponent(type: "verse", number: 2, lines: ["e", "f"], chords: nil, language: nil, lineIds: [5, 6], lineLanguages: nil)
        ]
        let detail = Self.makeSongDetail(components: components, arrangement: [2, 1, 0])
        let map = ProjectionSongMap(detail: detail)
        #expect(map.componentLineCounts == [2, 1, 3])
    }

    /// Minimal, valid `SongDetail` fixture via the compiler-synthesized
    /// memberwise initializer — the SAME idiom `SongComparisonEngineTests
    /// .detail(songId:components:arrangement:)` establishes (`@testable
    /// import IHModels`), reused here rather than re-forked.
    private static func makeSongDetail(components: [SongComponent], arrangement: [Int]?) -> SongDetail {
        SongDetail(
            songId: SongID(rawValue: "MP-0001") ?? SongID(songbookAbbreviation: "MP", number: 1),
            number: 1,
            title: "Test Song",
            songbookAbbreviation: "MP",
            songbookName: "Mission Praise",
            language: "en",
            copyright: "",
            tuneName: "",
            ccli: "",
            iswc: "",
            verified: false,
            lyricsPublicDomain: false,
            musicPublicDomain: false,
            hasAudio: false,
            hasSheetMusic: false,
            originCity: "",
            originCityId: nil,
            publicId: nil,
            arrangement: arrangement,
            writers: [],
            composers: [],
            arrangers: [],
            adaptors: [],
            translators: [],
            artists: [],
            components: components,
            tags: [],
            alternativeTitles: [],
            links: [],
            works: [],
            media: [],
            translations: nil,
            annotations: nil,
            royaltyIds: nil
        )
    }
}
