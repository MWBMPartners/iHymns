// SongComparisonEngineTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "line up verse 1 with verse 1" pairing logic and the
// "strip punctuation before comparing" normalisation actually work — pure
// Swift Testing coverage, no view, no network, no `AppRootViewModel`,
// matching `SongComparisonEngine`'s own deliberately side-effect-free
// design (mirrors `SongNavigationContextTests.swift`'s shape for its
// sibling pure-logic type).
//
// DETAILED: #1445. Builds `SongDetail`/`SongComponent` fixtures inline via
// the compiler-synthesized memberwise initializers (the SAME pattern
// `OfflineStoreSavedSongsTests.makeDetail(...)`/
// `OfflineStorageViewModelTests.makeDetail(...)` already establish for
// "a minimal, valid `SongDetail` this suite only uses as an opaque
// fixture") rather than decoding the bundled `song_detail.json` contract
// fixture — this suite needs several DIFFERENT component/arrangement
// shapes per test, which is far clearer to read as an inline literal than
// as edits layered on top of one shared JSON fixture.
import Testing
@testable import IHFeatures
@testable import IHModels

@Suite("SongComparisonEngine (#1445)")
struct SongComparisonEngineTests {

    // MARK: - Fixture builders

    private static func component(_ type: String, _ number: Int, _ lines: [String]) -> SongComponent {
        SongComponent(type: type, number: number, lines: lines, chords: nil, language: nil, lineIds: Array(0..<lines.count), lineLanguages: nil)
    }

    private static func detail(songId: String, components: [SongComponent], arrangement: [Int]? = nil) -> SongDetail {
        SongDetail(
            songId: SongID(rawValue: songId) ?? SongID(songbookAbbreviation: "MP", number: 1),
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

    // MARK: - Pairing

    @Test("Identical structures pair 1:1, in order")
    func identicalStructuresPairInOrder() {
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["Line A1"]),
            Self.component("chorus", 0, ["Line A2"])
        ])
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["Line B1"]),
            Self.component("chorus", 0, ["Line B2"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)

        #expect(pairs.count == 2)
        #expect(pairs[0].primary?.type == "verse")
        #expect(pairs[0].secondary?.type == "verse")
        #expect(pairs[1].primary?.type == "chorus")
        #expect(pairs[1].secondary?.type == "chorus")
    }

    @Test("A verse-count mismatch leaves the extra verse unmatched on its own side")
    func verseCountMismatchLeavesExtrasUnmatched() {
        // Primary has verses 1-4; secondary has verses 1-3 plus verse 5.
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["A1"]),
            Self.component("verse", 2, ["A2"]),
            Self.component("verse", 3, ["A3"]),
            Self.component("verse", 4, ["A4"])
        ])
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["B1"]),
            Self.component("verse", 2, ["B2"]),
            Self.component("verse", 3, ["B3"]),
            Self.component("verse", 5, ["B5"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)

        #expect(pairs.count == 5)
        // Verse 4 (primary-only) pairs with a nil secondary.
        let verse4Pair = pairs.first { $0.primary?.number == 4 }
        #expect(verse4Pair?.secondary == nil)
        // Verse 5 (secondary-only) is appended at the end with a nil primary.
        let verse5Pair = pairs.last
        #expect(verse5Pair?.primary == nil)
        #expect(verse5Pair?.secondary?.number == 5)
    }

    @Test("A chorus matches by (type, number) even when the secondary stores it at a different position")
    func chorusMatchesAcrossDifferentPositions() {
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["A1"]),
            Self.component("chorus", 0, ["Refrain A"]),
            Self.component("verse", 2, ["A2"])
        ])
        // Secondary's chorus appears FIRST, not between the two verses.
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("chorus", 0, ["Refrain B"]),
            Self.component("verse", 1, ["B1"]),
            Self.component("verse", 2, ["B2"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)

        // Primary's own order is preserved for the pair list; the chorus
        // pair (index 1, matching primary's own position) still resolves
        // to the secondary's chorus despite the positional mismatch.
        #expect(pairs.count == 3)
        #expect(pairs[1].primary?.type == "chorus")
        #expect(pairs[1].secondary?.lines == ["Refrain B"])
    }

    @Test("arrangement is respected — pairing follows orderedComponents, not raw components order")
    func arrangementIsRespected() {
        // Stored order: verse2, verse1. Arrangement says: show verse1 (index 1) first.
        let primary = Self.detail(
            songId: "MP-0001",
            components: [
                Self.component("verse", 2, ["Stored-first, but shown second"]),
                Self.component("verse", 1, ["Stored-second, but shown first"])
            ],
            arrangement: [1, 0]
        )
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["B1"]),
            Self.component("verse", 2, ["B2"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)

        #expect(pairs.count == 2)
        // Arrangement puts verse 1 FIRST in the pair list.
        #expect(pairs[0].primary?.number == 1)
        #expect(pairs[1].primary?.number == 2)
    }

    @Test("Component type matching is case-insensitive")
    func typeMatchingIsCaseInsensitive() {
        let primary = Self.detail(songId: "MP-0001", components: [Self.component("Chorus", 0, ["A"])])
        let secondary = Self.detail(songId: "MP-0002", components: [Self.component("chorus", 0, ["B"])])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)

        #expect(pairs.count == 1)
        #expect(pairs[0].secondary != nil)
    }

    // MARK: - Normalisation

    @Test("Punctuation-only differences normalise equal")
    func punctuationOnlyDifferencesAreEqual() {
        let withPunctuation = SongComparisonEngine.normalise("Amazing grace! how sweet the sound,")
        let withoutPunctuation = SongComparisonEngine.normalise("Amazing grace, how sweet the sound")
        #expect(withPunctuation == withoutPunctuation)
    }

    @Test("Diacritic-only differences normalise equal")
    func diacriticOnlyDifferencesAreEqual() {
        #expect(SongComparisonEngine.normalise("Café") == SongComparisonEngine.normalise("Cafe"))
    }

    @Test("A real word change normalises differently")
    func realWordChangeDiffers() {
        #expect(SongComparisonEngine.normalise("Amazing grace") != SongComparisonEngine.normalise("Amazing love"))
    }

    @Test("Runs of whitespace collapse to one space")
    func whitespaceCollapses() {
        #expect(SongComparisonEngine.normalise("Amazing   grace") == SongComparisonEngine.normalise("Amazing grace"))
    }

    // MARK: - Line diff

    @Test("Index-paired differing lines land in differingLineIndices")
    func differingLinesAreIndexPaired() {
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["Same line", "Different in A", "Same again"])
        ])
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["Same line", "Different in B", "Same again"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)
        #expect(pairs.count == 1)
        #expect(pairs[0].differingLineIndices == [1])
    }

    @Test("A longer secondary component's overflow lands in secondaryOnlyLineIndices")
    func longerSecondaryOverflowsIntoSecondaryOnly() {
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["Line 1", "Line 2"])
        ])
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["Line 1", "Line 2", "Line 3", "Line 4"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)
        #expect(pairs[0].differingLineIndices.isEmpty)
        #expect(pairs[0].secondaryOnlyLineIndices == [2, 3])
    }

    @Test("A longer primary component's overflow lands in differingLineIndices")
    func longerPrimaryOverflowsIntoDiffering() {
        let primary = Self.detail(songId: "MP-0001", components: [
            Self.component("verse", 1, ["Line 1", "Line 2", "Line 3"])
        ])
        let secondary = Self.detail(songId: "MP-0002", components: [
            Self.component("verse", 1, ["Line 1"])
        ])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)
        #expect(pairs[0].differingLineIndices == [1, 2])
        #expect(pairs[0].secondaryOnlyLineIndices.isEmpty)
    }

    @Test("Empty lines arrays don't crash and produce no diffs")
    func emptyLinesArraysDoNotCrash() {
        let primary = Self.detail(songId: "MP-0001", components: [Self.component("verse", 1, [])])
        let secondary = Self.detail(songId: "MP-0002", components: [Self.component("verse", 1, [])])

        let pairs = SongComparisonEngine.pairs(primary: primary, secondary: secondary)
        #expect(pairs[0].differingLineIndices.isEmpty)
        #expect(pairs[0].secondaryOnlyLineIndices.isEmpty)
    }

    // MARK: - Layout

    @Test("Compact width lays out as paged")
    func compactLaysOutPaged() {
        #expect(ComparisonLayout.layout(horizontalSizeClass: .compact) == .paged)
    }

    @Test("Regular width lays out side by side")
    func regularLaysOutSideBySide() {
        #expect(ComparisonLayout.layout(horizontalSizeClass: .regular) == .sideBySide)
    }

    @Test("No size class (macOS/visionOS) lays out side by side")
    func nilSizeClassLaysOutSideBySide() {
        #expect(ComparisonLayout.layout(horizontalSizeClass: nil) == .sideBySide)
    }
}
