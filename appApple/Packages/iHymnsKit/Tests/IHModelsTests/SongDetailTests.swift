// SongDetailTests.swift
// IHModelsTests
//
// ELI5: Proves `SongDetail.orderedComponents` reorders verses/choruses
// exactly the way the web renderer does — including falling back to plain
// order when there's no arrangement, and never rendering an empty song when
// the arrangement is garbage.
//
// DETAILED: Hand-built `SongDetail` values (not a live fixture — every song
// this task sampled on `dev` had `arrangement: null`, see `SongDetail.swift`'s
// doc comment for the #180 correction this property fixes) exercising the
// THREE behaviours `includes/pages/song.php`'s own
// `array_map(fn($i) => $components[$i] ?? null, $arrangement); array_filter(...)`
// exhibits: reorder-by-index, drop-out-of-range, and fall back to
// `components` verbatim when `arrangement` is absent or entirely garbage.
import Foundation
import Testing
@testable import IHModels

@Suite("SongDetail.orderedComponents (#180)")
struct SongDetailTests {

    /// Builds a minimal, valid `SongDetail` from just `components` +
    /// `arrangement` — every other property is a neutral placeholder, since
    /// this suite only cares about the reordering logic.
    private static func makeDetail(components: [SongComponent], arrangement: [Int]?) -> SongDetail {
        SongDetail(
            songId: SongID(songbookAbbreviation: "MP", number: 1),
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

    private static func component(_ type: String, _ number: Int) -> SongComponent {
        SongComponent(type: type, number: number, lines: ["\(type) \(number) line"], chords: nil, language: nil, lineIds: [number], lineLanguages: nil)
    }

    @Test("nil arrangement falls back to plain stored order")
    func fallsBackToStoredOrderWhenArrangementIsNil() {
        let components = [Self.component("verse", 1), Self.component("chorus", 0), Self.component("verse", 2)]
        let detail = Self.makeDetail(components: components, arrangement: nil)
        #expect(detail.orderedComponents.map(\.type) == ["verse", "chorus", "verse"])
    }

    @Test("a real arrangement reorders components by 0-based index, matching the web renderer")
    func reordersByArrangementIndex() {
        // verse 1, chorus, verse 2 stored in that order; arrangement asks
        // for chorus, verse 1, chorus, verse 2 — a real "V1 C V2 C"-style
        // singing order, proving indices can repeat and needn't be a
        // permutation.
        let components = [Self.component("verse", 1), Self.component("chorus", 0), Self.component("verse", 2)]
        let detail = Self.makeDetail(components: components, arrangement: [1, 0, 1, 2])
        #expect(detail.orderedComponents.map(\.type) == ["chorus", "verse", "chorus", "verse"])
        #expect(detail.orderedComponents.map(\.number) == [0, 1, 0, 2])
    }

    @Test("out-of-range indices are silently dropped, mirroring PHP's array_filter")
    func dropsOutOfRangeIndices() {
        let components = [Self.component("verse", 1), Self.component("chorus", 0)]
        let detail = Self.makeDetail(components: components, arrangement: [0, 99, 1])
        #expect(detail.orderedComponents.map(\.type) == ["verse", "chorus"])
    }

    @Test("an arrangement that's ENTIRELY out of range degrades to stored order, never an empty song")
    func fullyInvalidArrangementDegradesToStoredOrder() {
        let components = [Self.component("verse", 1), Self.component("chorus", 0)]
        let detail = Self.makeDetail(components: components, arrangement: [42, 99])
        #expect(detail.orderedComponents.map(\.type) == ["verse", "chorus"])
    }
}
