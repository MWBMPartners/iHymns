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
import IHTestFixtures
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
            royaltyIds: nil,
            subtitle: nil,
            disambiguation: nil,
            firstPublishedYear: nil,
            copyrightYears: nil,
            copyrightHolder: nil,
            externalIds: nil
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

// #1752 Slice A — the five #1741 P1 song-identity keys + `externalIds`
// (`.claude/catalogue-1741-1752-plan.md` §1.5). Split into its own suite
// (rather than growing `SongDetailTests` above, which is scoped purely to
// `orderedComponents`) since this exercises a genuinely different property:
// `copyrightDisplay`'s precedence fold, and the whole-struct absent-key
// tolerance that protects `CachedSongDetail`'s offline re-decode (see
// `SongDetail.swift`'s own doc comment on why these six properties are
// `Optional`).
@Suite("SongDetail — #1741 P1 identity keys + copyrightDisplay (#1752)")
struct SongDetailIdentityTests {

    /// Builds a minimal, valid `SongDetail` with the three copyright-related
    /// inputs as parameters — every other property is a neutral placeholder,
    /// mirroring `SongDetailTests.makeDetail`'s own "only the field under
    /// test varies" shape.
    private static func makeDetail(
        copyright: String,
        copyrightYears: String?,
        copyrightHolder: String?
    ) -> SongDetail {
        SongDetail(
            songId: SongID(songbookAbbreviation: "MP", number: 1),
            number: 1,
            title: "Test Song",
            songbookAbbreviation: "MP",
            songbookName: "Mission Praise",
            language: "en",
            copyright: copyright,
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
            arrangement: nil,
            writers: [],
            composers: [],
            arrangers: [],
            adaptors: [],
            translators: [],
            artists: [],
            components: [],
            tags: [],
            alternativeTitles: [],
            links: [],
            works: [],
            media: [],
            translations: nil,
            annotations: nil,
            royaltyIds: nil,
            subtitle: nil,
            disambiguation: nil,
            firstPublishedYear: nil,
            copyrightYears: copyrightYears,
            copyrightHolder: copyrightHolder,
            externalIds: nil
        )
    }

    @Test("copyrightDisplay uses the split years+holder fields when either is present")
    func copyrightDisplayPrefersSplitFields() {
        let detail = Self.makeDetail(copyright: "© 1987 Legacy Music Inc.", copyrightYears: "1987", copyrightHolder: "New Holder Ltd.")
        #expect(detail.copyrightDisplay == "1987 New Holder Ltd.")
    }

    @Test("copyrightDisplay falls back to the legacy free-text copyright when both split fields are empty")
    func copyrightDisplayFallsBackToLegacy() {
        let detail = Self.makeDetail(copyright: "© 1987 Legacy Music Inc.", copyrightYears: "", copyrightHolder: nil)
        #expect(detail.copyrightDisplay == "© 1987 Legacy Music Inc.")
    }

    @Test("copyrightDisplay uses the split fields even when only ONE half is present — never a mix with legacy")
    func copyrightDisplayUsesSplitEvenWhenOnlyOneHalfIsPresent() {
        let detail = Self.makeDetail(copyright: "© 1987 Legacy Music Inc.", copyrightYears: "1987", copyrightHolder: nil)
        #expect(detail.copyrightDisplay == "1987")
    }

    @Test("copyrightDisplay is empty when copyright, copyrightYears and copyrightHolder are all empty")
    func copyrightDisplayEmptyWhenEverythingIsEmpty() {
        let detail = Self.makeDetail(copyright: "", copyrightYears: "", copyrightHolder: "")
        #expect(detail.copyrightDisplay == "")
    }

    // MARK: - Absent-tolerance (the CachedSongDetail back-compat guarantee)

    @Test("song_detail.json decodes the five #1741 P1 keys + externalIds with correct values/types")
    func decodesIdentityKeysFromLiveFixture() throws {
        struct Envelope: Decodable { let song: SongDetail }
        let envelope = try JSONDecoder().decode(Envelope.self, from: ContractFixtures.songDetail())
        let song = envelope.song
        #expect(song.subtitle == "")
        #expect(song.disambiguation == "")
        #expect(song.firstPublishedYear == 1779)
        #expect(song.copyrightYears == "")
        #expect(song.copyrightHolder == "")
        #expect(song.externalIds?.count == 1)
        #expect(song.externalIds?.first?.idScope == "recording")
        #expect(song.externalIds?.first?.idType == "isrc")
        #expect(song.externalIds?.first?.idValue == "GBAYE7900001")
        #expect(song.externalIds?.first?.source == "curator")
    }

    @Test("a copy of song_detail.json with all six #1752 keys stripped decodes to nil for each — never throws")
    func decodesWithAllSixKeysAbsent() throws {
        // Reproduces exactly what `CachedSongDetail` re-decodes on every app
        // launch for an offline-saved song downloaded BEFORE this update, and
        // what any pre-#1750 docroot still sends today (`SongDetail.swift`'s
        // own doc comment on why these six properties are `Optional`, not
        // required). Strips the keys from a COPY of the real fixture's JSON
        // object in memory — the committed fixture file itself is untouched.
        var json = try #require(
            try JSONSerialization.jsonObject(with: ContractFixtures.songDetail()) as? [String: Any]
        )
        var songObject = try #require(json["song"] as? [String: Any])
        for key in ["subtitle", "disambiguation", "firstPublishedYear", "copyrightYears", "copyrightHolder", "externalIds"] {
            songObject.removeValue(forKey: key)
        }
        json["song"] = songObject
        let strippedData = try JSONSerialization.data(withJSONObject: json)

        struct Envelope: Decodable { let song: SongDetail }
        let envelope = try JSONDecoder().decode(Envelope.self, from: strippedData)
        #expect(envelope.song.subtitle == nil)
        #expect(envelope.song.disambiguation == nil)
        #expect(envelope.song.firstPublishedYear == nil)
        #expect(envelope.song.copyrightYears == nil)
        #expect(envelope.song.copyrightHolder == nil)
        #expect(envelope.song.externalIds == nil)
        // The song otherwise decoded fine — proves this is genuine
        // key-absence tolerance, not an accidental whole-decode failure
        // that happens to leave every Optional at its default nil.
        #expect(envelope.song.songId.rawValue == "MP-0031")
    }
}
