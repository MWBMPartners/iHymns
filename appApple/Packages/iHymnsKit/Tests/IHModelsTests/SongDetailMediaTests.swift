// SongDetailMediaTests.swift
// IHModelsTests
//
// ELI5: Proves `SongDetail.audioAsset`/`sheetMusicAsset`/`midiAssets`/
// `musicXmlAssets` (`SongDetail+Media.swift`, #184) pick out exactly the
// right rows from a mixed `media` array, and behave sanely when a kind is
// entirely absent.
//
// DETAILED: Hand-built `SongDetail`/`SongMediaAsset` values (same
// "everything except what THIS suite cares about is a neutral placeholder"
// shape `SongDetailTests.swift`'s own `makeDetail` helper already
// establishes for `orderedComponents`).
import Foundation
import Testing
@testable import IHModels

@Suite("SongDetail media accessors (#184)")
struct SongDetailMediaTests {

    private static func makeDetail(media: [SongMediaAsset]) -> SongDetail {
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
            hasAudio: !media.isEmpty,
            hasSheetMusic: !media.isEmpty,
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
            media: media,
            translations: nil,
            annotations: nil,
            royaltyIds: nil
        )
    }

    private static func asset(id: Int, kind: String, sortOrder: Int = 0, fileName: String = "file") -> SongMediaAsset {
        SongMediaAsset(
            id: id,
            kind: kind,
            fileName: fileName,
            mimeType: "application/octet-stream",
            sizeBytes: 1024,
            annotation: "",
            sortOrder: sortOrder,
            streamUrl: "/song-media/\(id)"
        )
    }

    @Test("A song with no media at all returns nil/empty for every accessor")
    func emptyMedia() {
        let detail = Self.makeDetail(media: [])
        #expect(detail.audioAsset == nil)
        #expect(detail.sheetMusicAsset == nil)
        #expect(detail.midiAssets.isEmpty)
        #expect(detail.musicXmlAssets.isEmpty)
    }

    @Test("Each accessor picks only rows of its own kind out of a mixed array")
    func mixedMediaPicksCorrectKind() {
        let audio = Self.asset(id: 1, kind: SongMediaAsset.Kind.audio, fileName: "recording.mp3")
        let sheet = Self.asset(id: 2, kind: SongMediaAsset.Kind.sheetMusic, fileName: "sheet.pdf")
        let midi1 = Self.asset(id: 3, kind: SongMediaAsset.Kind.midi, sortOrder: 0, fileName: "verse.mid")
        let midi2 = Self.asset(id: 4, kind: SongMediaAsset.Kind.midi, sortOrder: 1, fileName: "chorus.mid")
        let xml = Self.asset(id: 5, kind: SongMediaAsset.Kind.musicXml, fileName: "score.musicxml")
        // Deliberately out of "natural" order in the array — proves the
        // accessors filter by `kind`, not by array position.
        let detail = Self.makeDetail(media: [midi2, sheet, audio, xml, midi1])

        #expect(detail.audioAsset?.id == 1)
        #expect(detail.sheetMusicAsset?.id == 2)
        #expect(Set(detail.midiAssets.map(\.id)) == [3, 4])
        #expect(detail.musicXmlAssets.map(\.id) == [5])
    }

    @Test("audioAsset/sheetMusicAsset pick the FIRST matching row when a kind has several")
    func firstMatchWinsForSingularAccessors() {
        let firstAudio = Self.asset(id: 10, kind: SongMediaAsset.Kind.audio, sortOrder: 0)
        let secondAudio = Self.asset(id: 11, kind: SongMediaAsset.Kind.audio, sortOrder: 1)
        let detail = Self.makeDetail(media: [firstAudio, secondAudio])
        #expect(detail.audioAsset?.id == 10)
    }

    @Test("An unrecognised future kind (e.g. a generic \"pdf\") is invisible to every typed accessor")
    func unknownKindIsIgnored() {
        let future = Self.asset(id: 20, kind: "notation-source")
        let detail = Self.makeDetail(media: [future])
        #expect(detail.audioAsset == nil)
        #expect(detail.sheetMusicAsset == nil)
        #expect(detail.midiAssets.isEmpty)
        #expect(detail.musicXmlAssets.isEmpty)
    }
}
