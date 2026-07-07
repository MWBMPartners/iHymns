// OfflineStoreSavedSongsTests.swift
// IHPersistenceTests
//
// ELI5: Saves a couple of FULL songs into an in-memory offline notebook,
// then checks reading them back gives the exact same lyrics, that saving
// the same song twice doesn't duplicate it, that removing one only removes
// that one, and that the size/count bookkeeping the Storage & Offline
// screen relies on adds up correctly. Covers the #187 offline
// -support-and-data-management half of `OfflineStore`.
//
// DETAILED: Mirrors `OfflineStoreTests.swift`'s exact "in-memory
// `OfflineStore(path: nil)`, no mocked transport needed" shape for the
// identical reason that suite documents — `IHPersistenceTests` has no
// `IHTestFixtures` dependency (`Package.swift`), so `makeDetail(...)` below
// hand-builds a minimal-but-valid `SongDetail` the same way
// `IHModelsTests/SongDetailTests.swift`'s own `makeDetail` helper does,
// rather than pulling in a live-recorded fixture this target can't reach.
import Foundation
import Testing
@testable import IHPersistence
@testable import IHModels

@Suite("OfflineStore saved songs (#187)")
struct OfflineStoreSavedSongsTests {

    /// Builds a minimal, valid `SongDetail` — every property besides
    /// `songId`/`title`/`songbookAbbreviation` is a neutral placeholder,
    /// since this suite only cares about the cache round-tripping the
    /// record faithfully, not about any one field's content.
    private static func makeDetail(songId: String, title: String, songbookAbbreviation: String) -> SongDetail {
        SongDetail(
            songId: SongID(rawValue: songId) ?? SongID(songbookAbbreviation: songbookAbbreviation, number: 1),
            number: 1,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
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
            arrangement: nil,
            writers: ["A Writer"],
            composers: [],
            arrangers: [],
            adaptors: [],
            translators: [],
            artists: [],
            components: [
                SongComponent(type: "verse", number: 1, lines: ["Line one", "Line two"], chords: nil, language: nil, lineIds: [1, 2], lineLanguages: nil)
            ],
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

    @Test("allSavedSongs starts empty, and so do count/total bytes")
    func startsEmpty() async throws {
        let store = try OfflineStore(path: nil)
        #expect(try await store.allSavedSongs().isEmpty)
        #expect(try await store.savedSongCount() == 0)
        #expect(try await store.totalSavedSongBytes() == 0)
    }

    @Test("saveSongForOffline then savedSongDetail round-trips the FULL record, lyrics included")
    func savedDetailRoundTrips() async throws {
        let store = try OfflineStore(path: nil)
        let detail = Self.makeDetail(songId: "MP-1", title: "Amazing Grace", songbookAbbreviation: "MP")

        try await store.saveSongForOffline(detail)
        let fetched = try await store.savedSongDetail(try #require(SongID(rawValue: "MP-1")))

        #expect(fetched?.title == "Amazing Grace")
        #expect(fetched?.components.first?.lines == ["Line one", "Line two"])
        #expect(fetched?.writers == ["A Writer"])
    }

    @Test("savedSongDetail is nil for a song that was never saved")
    func savedDetailMissingIsNil() async throws {
        let store = try OfflineStore(path: nil)
        let fetched = try await store.savedSongDetail(try #require(SongID(rawValue: "MP-404")))
        #expect(fetched == nil)
    }

    @Test("isSongSaved reflects exactly what's been saved")
    func isSongSavedReflectsState() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        #expect(try await store.isSongSaved(songId) == false)

        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Song", songbookAbbreviation: "MP"))
        #expect(try await store.isSongSaved(songId) == true)

        try await store.removeSavedSong(songId)
        #expect(try await store.isSongSaved(songId) == false)
    }

    @Test("saving the same songId twice replaces, not duplicates")
    func savingSameSongReplaces() async throws {
        let store = try OfflineStore(path: nil)
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Original Title", songbookAbbreviation: "MP"))
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Updated Title", songbookAbbreviation: "MP"))

        #expect(try await store.savedSongCount() == 1)
        let fetched = try await store.savedSongDetail(try #require(SongID(rawValue: "MP-1")))
        #expect(fetched?.title == "Updated Title")
    }

    @Test("allSavedSongs orders most-recently-saved first and reports a positive sizeBytes")
    func allSavedSongsOrdersByMostRecent() async throws {
        let store = try OfflineStore(path: nil)
        let older = Self.makeDetail(songId: "MP-1", title: "Older Song", songbookAbbreviation: "MP")
        let newer = Self.makeDetail(songId: "MP-2", title: "Newer Song", songbookAbbreviation: "MP")

        try await store.saveSongForOffline(older, savedAt: Date(timeIntervalSince1970: 1000))
        try await store.saveSongForOffline(newer, savedAt: Date(timeIntervalSince1970: 2000))

        let list = try await store.allSavedSongs()
        #expect(list.map(\.title) == ["Newer Song", "Older Song"])
        #expect(list.allSatisfy { $0.sizeBytes > 0 })
    }

    @Test("removeSavedSong removes exactly that song")
    func removeSavedSongRemovesExactlyThatSong() async throws {
        let store = try OfflineStore(path: nil)
        let keep = try #require(SongID(rawValue: "MP-1"))
        let drop = try #require(SongID(rawValue: "MP-2"))
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Keep", songbookAbbreviation: "MP"))
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-2", title: "Drop", songbookAbbreviation: "MP"))

        try await store.removeSavedSong(drop)

        let list = try await store.allSavedSongs()
        #expect(list.map(\.songId) == [keep])
    }

    @Test("removeSavedSong on a song that was never saved is a harmless no-op")
    func removeMissingSavedSongIsNoOp() async throws {
        let store = try OfflineStore(path: nil)
        try await store.removeSavedSong(try #require(SongID(rawValue: "MP-404")))
        #expect(try await store.allSavedSongs().isEmpty)
    }

    @Test("removeAllSavedSongs empties the whole cache")
    func removeAllEmptiesCache() async throws {
        let store = try OfflineStore(path: nil)
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "One", songbookAbbreviation: "MP"))
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-2", title: "Two", songbookAbbreviation: "MP"))

        try await store.removeAllSavedSongs()

        #expect(try await store.allSavedSongs().isEmpty)
        #expect(try await store.savedSongCount() == 0)
        #expect(try await store.totalSavedSongBytes() == 0)
    }

    @Test("totalSavedSongBytes grows as songs are added and shrinks as they're removed")
    func totalBytesTracksAdditionsAndRemovals() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        try await store.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "One", songbookAbbreviation: "MP"))

        let afterAdd = try await store.totalSavedSongBytes()
        #expect(afterAdd > 0)

        try await store.removeSavedSong(songId)
        #expect(try await store.totalSavedSongBytes() == 0)
    }
}
