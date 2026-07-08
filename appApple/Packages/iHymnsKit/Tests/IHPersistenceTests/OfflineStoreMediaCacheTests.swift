// OfflineStoreMediaCacheTests.swift
// IHPersistenceTests
//
// ELI5: Downloads a couple of fake "files" into the offline media cache,
// then checks reading them back gives the exact same bytes at a real local
// file path, that caching the SAME asset twice replaces rather than
// duplicating, that removing one file (or a whole song's files, or
// EVERYTHING) actually deletes the bytes on disk — not just the database
// row — and that the size bookkeeping the Storage & Offline screen relies
// on adds up correctly. Covers the #1440 offline-media-caching half of
// `OfflineStore`.
//
// DETAILED: Mirrors `OfflineStoreSavedSongsTests.swift`'s exact "in-memory
// `OfflineStore(path: nil)`, no mocked transport needed" shape — this suite
// never fetches anything over the network, `cacheMedia(songId:asset:data:)`
// takes already-fetched `Data` directly (`SongDetailViewModel`'s job is
// fetching it; this store's job is only persisting it). Each test passes
// its own throwaway `mediaCacheDirectory` (never `nil`, unlike most
// `OfflineStore(path: nil)` call sites elsewhere) specifically so a test
// can assert real things about the REAL files this feature writes — a
// fresh, uniquely-named directory under the system temp root per test,
// mirroring `MediaDownloadViewModelTests.scratchDirectory()`'s identical
// isolation reasoning.
import Foundation
import Testing
@testable import IHPersistence
@testable import IHModels

@Suite("OfflineStore media cache (#1440)")
struct OfflineStoreMediaCacheTests {

    private static func scratchDirectory() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(
            "OfflineStoreMediaCacheTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    private static func makeStore() throws -> OfflineStore {
        try OfflineStore(path: nil, mediaCacheDirectory: scratchDirectory())
    }

    private static func makeAsset(id: Int = 1, kind: String = SongMediaAsset.Kind.audio, fileName: String = "amazing-grace.mp3") -> SongMediaAsset {
        SongMediaAsset(
            id: id,
            kind: kind,
            fileName: fileName,
            mimeType: "audio/mpeg",
            sizeBytes: 0,
            annotation: "",
            sortOrder: 0,
            streamUrl: "/song-media/\(id)"
        )
    }

    @Test("cachedMediaURL is nil before anything is cached")
    func cachedMediaURLStartsNil() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        #expect(await store.cachedMediaURL(songId: songId, mediaAssetId: 1) == nil)
    }

    @Test("cacheMedia writes the exact bytes to a real local file, and cachedMediaURL finds it")
    func cacheMediaRoundTripsBytes() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let bytes = Data([0x49, 0x44, 0x33]) // "ID3" — a real MP3 tag magic number
        let asset = Self.makeAsset()

        let fileURL = try await store.cacheMedia(songId: songId, asset: asset, data: bytes)
        #expect(try Data(contentsOf: fileURL) == bytes)

        let resolved = await store.cachedMediaURL(songId: songId, mediaAssetId: asset.id)
        #expect(resolved == fileURL)
    }

    @Test("caching the same (songId, mediaAssetId) twice replaces the file, not duplicates it")
    func cachingSameAssetTwiceReplaces() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let asset = Self.makeAsset()

        _ = try await store.cacheMedia(songId: songId, asset: asset, data: Data([1, 2, 3]))
        let secondURL = try await store.cacheMedia(songId: songId, asset: asset, data: Data([9, 9]))

        let all = try await store.cachedMedia(forSong: songId)
        #expect(all.count == 1)
        #expect(try Data(contentsOf: secondURL) == Data([9, 9]))
        #expect(all.first?.sizeBytes == 2)
    }

    @Test("two different assets on the same song both cache independently, never colliding")
    func differentAssetsDoNotCollide() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let audio = Self.makeAsset(id: 1, kind: SongMediaAsset.Kind.audio, fileName: "sheet.pdf")
        let sheetMusic = Self.makeAsset(id: 2, kind: SongMediaAsset.Kind.sheetMusic, fileName: "sheet.pdf")

        let audioURL = try await store.cacheMedia(songId: songId, asset: audio, data: Data([1]))
        let sheetURL = try await store.cacheMedia(songId: songId, asset: sheetMusic, data: Data([2]))

        #expect(audioURL != sheetURL)
        #expect(try Data(contentsOf: audioURL) == Data([1]))
        #expect(try Data(contentsOf: sheetURL) == Data([2]))
    }

    @Test("cachedMedia(forSong:) lists every cached asset for that song, and none for another")
    func cachedMediaListsOnlyThatSong() async throws {
        let store = try Self.makeStore()
        let songOne = try #require(SongID(rawValue: "MP-1"))
        let songTwo = try #require(SongID(rawValue: "MP-2"))
        _ = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(id: 1), data: Data([1]))
        _ = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(id: 2, kind: SongMediaAsset.Kind.midi, fileName: "tune.mid"), data: Data([2]))
        _ = try await store.cacheMedia(songId: songTwo, asset: Self.makeAsset(id: 1), data: Data([3]))

        let songOneMedia = try await store.cachedMedia(forSong: songOne)
        #expect(songOneMedia.count == 2)
        let songTwoMedia = try await store.cachedMedia(forSong: songTwo)
        #expect(songTwoMedia.count == 1)
    }

    @Test("removeCachedMedia deletes both the row and the on-disk file for exactly that asset")
    func removeCachedMediaDeletesRowAndFile() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let asset = Self.makeAsset()
        let fileURL = try await store.cacheMedia(songId: songId, asset: asset, data: Data([1]))
        #expect(FileManager.default.fileExists(atPath: fileURL.path))

        try await store.removeCachedMedia(songId: songId, mediaAssetId: asset.id)

        #expect(await store.cachedMediaURL(songId: songId, mediaAssetId: asset.id) == nil)
        #expect(FileManager.default.fileExists(atPath: fileURL.path) == false)
    }

    @Test("removeCachedMedia on an asset that was never cached is a harmless no-op")
    func removeMissingCachedMediaIsNoOp() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-404"))
        try await store.removeCachedMedia(songId: songId, mediaAssetId: 1)
        #expect(try await store.cachedMedia(forSong: songId).isEmpty)
    }

    @Test("removeAllCachedMedia(forSong:) removes every asset for that song, leaving other songs' media intact")
    func removeAllForSongLeavesOthersIntact() async throws {
        let store = try Self.makeStore()
        let songOne = try #require(SongID(rawValue: "MP-1"))
        let songTwo = try #require(SongID(rawValue: "MP-2"))
        _ = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(id: 1), data: Data([1]))
        _ = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(id: 2, kind: SongMediaAsset.Kind.midi, fileName: "tune.mid"), data: Data([2]))
        let keptURL = try await store.cacheMedia(songId: songTwo, asset: Self.makeAsset(id: 1), data: Data([3]))

        try await store.removeAllCachedMedia(forSong: songOne)

        #expect(try await store.cachedMedia(forSong: songOne).isEmpty)
        #expect(try await store.cachedMedia(forSong: songTwo).count == 1)
        #expect(try Data(contentsOf: keptURL) == Data([3]))
    }

    @Test("totalCachedMediaBytes sums every cached file across every song, and shrinks as they're removed")
    func totalBytesTracksAdditionsAndRemovals() async throws {
        let store = try Self.makeStore()
        let songOne = try #require(SongID(rawValue: "MP-1"))
        let songTwo = try #require(SongID(rawValue: "MP-2"))
        _ = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(), data: Data(repeating: 0, count: 10))
        _ = try await store.cacheMedia(songId: songTwo, asset: Self.makeAsset(), data: Data(repeating: 0, count: 25))

        #expect(try await store.totalCachedMediaBytes() == 35)

        try await store.removeAllCachedMedia(forSong: songOne)
        #expect(try await store.totalCachedMediaBytes() == 25)
    }

    @Test("removeAllCachedMedia() empties the whole media cache, files included, across every song")
    func removeAllEmptiesEntireCache() async throws {
        let store = try Self.makeStore()
        let songOne = try #require(SongID(rawValue: "MP-1"))
        let songTwo = try #require(SongID(rawValue: "MP-2"))
        let firstURL = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(), data: Data([1]))
        let secondURL = try await store.cacheMedia(songId: songTwo, asset: Self.makeAsset(), data: Data([2]))

        try await store.removeAllCachedMedia()

        #expect(try await store.totalCachedMediaBytes() == 0)
        #expect(try await store.cachedMedia(forSong: songOne).isEmpty)
        #expect(try await store.cachedMedia(forSong: songTwo).isEmpty)
        #expect(FileManager.default.fileExists(atPath: firstURL.path) == false)
        #expect(FileManager.default.fileExists(atPath: secondURL.path) == false)

        // The cache directory itself must still exist and be writable —
        // `init(path:mediaCacheDirectory:)`'s own invariant that this
        // directory is always present, so a LATER cache write in the same
        // app run never has to re-create the root itself.
        let againURL = try await store.cacheMedia(songId: songOne, asset: Self.makeAsset(), data: Data([9]))
        #expect(try Data(contentsOf: againURL) == Data([9]))
    }

    @Test("cacheMedia stores the etag/lastModified it's given (#1460), and both default to nil when omitted")
    func cacheMediaRoundTripsEtagAndLastModified() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))

        _ = try await store.cacheMedia(songId: songId, asset: Self.makeAsset(id: 1), data: Data([1]))
        let withoutEtag = try await store.cachedMedia(forSong: songId)
        #expect(withoutEtag.first?.etag == nil)
        #expect(withoutEtag.first?.lastModified == nil)

        _ = try await store.cacheMedia(
            songId: songId, asset: Self.makeAsset(id: 2, fileName: "tune.mid"), data: Data([2]),
            etag: "\"abc123\"", lastModified: "Wed, 08 Jul 2026 12:00:00 GMT"
        )
        let withEtag = try await store.cachedMedia(forSong: songId).first { $0.mediaAssetId == 2 }
        #expect(withEtag?.etag == "\"abc123\"")
        #expect(withEtag?.lastModified == "Wed, 08 Jul 2026 12:00:00 GMT")
    }

    @Test("re-caching the same asset with a NEW etag replaces the stored one, not append/duplicate")
    func recachingReplacesStoredEtag() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let asset = Self.makeAsset()

        _ = try await store.cacheMedia(songId: songId, asset: asset, data: Data([1]), etag: "\"old\"")
        _ = try await store.cacheMedia(songId: songId, asset: asset, data: Data([2]), etag: "\"new\"")

        let all = try await store.cachedMedia(forSong: songId)
        #expect(all.count == 1)
        #expect(all.first?.etag == "\"new\"")
    }

    @Test("a path-traversal-shaped media file name is reduced to a safe leaf name (shared MediaFileNaming sanitizer)")
    func sanitizesPathTraversalAttempts() async throws {
        let store = try Self.makeStore()
        let songId = try #require(SongID(rawValue: "MP-1"))
        let asset = Self.makeAsset(fileName: "../../etc/evil.mp3")

        let fileURL = try await store.cacheMedia(songId: songId, asset: asset, data: Data([1]))

        #expect(fileURL.lastPathComponent.hasSuffix("evil.mp3"))
        #expect(fileURL.path.contains("..") == false)
    }
}
