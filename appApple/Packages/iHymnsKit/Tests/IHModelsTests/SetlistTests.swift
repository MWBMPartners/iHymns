// SetlistTests.swift
// IHModelsTests
//
// ELI5: Makes sure a `Setlist`/`SetlistSongEntry` round-trips correctly
// through the REAL `user_setlists`/`user_setlists_sync` JSON shape (modelled
// from `appWeb/public_html/api.php`'s handlers, read 2026-07-07 — see
// `Setlist.swift`'s header for the full verification note), and that
// `SharedSetlist`/`ShareSetlistResult` do the same for the separate
// `setlist_share`/`setlist_get` shape.
import Foundation
import Testing
@testable import IHModels

@Suite("Setlist")
struct SetlistTests {

    @Test("Decodes the real UserSetlist JSON row shape")
    func decodesUserSetlistRowShape() throws {
        // Field names/casing match `api.php`'s `case 'user_setlists'`
        // handler's `array_map` exactly: `id`/`name`/`songs`/`createdAt`/
        // `updatedAt`, with each song entry as `id`/`title`/`songbook`/
        // `number` (+ optional `arrangement`).
        let json = Data("""
        {
            "id": "slist-4f9a2c1b",
            "name": "Sunday Morning Service",
            "songs": [
                { "id": "MP-1008", "title": "Amazing Grace", "songbook": "MP", "number": 1008 },
                { "id": "CP-0202", "title": "How Great Thou Art", "songbook": "CP", "number": 202, "arrangement": [0, 1, 2, 1, 3] }
            ],
            "createdAt": "2026-07-01 09:00:00",
            "updatedAt": "2026-07-07T12:34:56+00:00"
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(Setlist.self, from: json)
        #expect(decoded.id == "slist-4f9a2c1b")
        #expect(decoded.name == "Sunday Morning Service")
        #expect(decoded.songs.count == 2)
        #expect(decoded.songs[0].songId.rawValue == "MP-1008")
        #expect(decoded.songs[0].arrangement == nil)
        #expect(decoded.songs[1].arrangement == [0, 1, 2, 1, 3])
        // Kept as raw strings, not parsed Dates — see this type's header for
        // why the two server code paths disagree on shape (space-separated
        // TIMESTAMP vs. ISO-8601 with a "T").
        #expect(decoded.createdAt == "2026-07-01 09:00:00")
        #expect(decoded.updatedAt == "2026-07-07T12:34:56+00:00")
    }

    @Test("A setlist row with no songs, no createdAt/updatedAt decodes cleanly")
    func decodesEmptySetlist() throws {
        let json = Data(#"{ "id": "slist-abc123", "name": "Untitled", "songs": [] }"#.utf8)
        let decoded = try JSONDecoder().decode(Setlist.self, from: json)
        #expect(decoded.songs.isEmpty)
        #expect(decoded.createdAt == nil)
        #expect(decoded.updatedAt == nil)
    }

    @Test("Encodes back to the exact wire shape user_setlists_sync expects")
    func encodesRequestShape() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let setlist = Setlist(
            id: "slist-abc123",
            name: "Evening Worship",
            songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)]
        )
        let data = try JSONEncoder().encode(setlist)
        let json = try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
        #expect(json["id"] as? String == "slist-abc123")
        #expect(json["name"] as? String == "Evening Worship")
        let songs = try #require(json["songs"] as? [[String: Any]])
        #expect(songs[0]["id"] as? String == "MP-1008")
        #expect(songs[0]["songbook"] as? String == "MP")
        #expect(songs[0]["number"] as? Int == 1008)
        // A nil createdAt/updatedAt is OMITTED, never encoded as a literal
        // `null` — Swift's synthesized `Encodable` for `Optional` stored
        // properties uses `encodeIfPresent`.
        #expect(json["createdAt"] == nil)
        #expect(json["updatedAt"] == nil)
    }

    @Test("makeId() produces a slist- prefixed, collision-safe id")
    func makeIdShape() {
        let first = Setlist.makeId()
        let second = Setlist.makeId()
        #expect(first.hasPrefix("slist-"))
        #expect(first != second)
    }

    @Test("contains(_:) reflects whether a song is in the setlist")
    func containsChecksSongs() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let otherSongId = try #require(SongID(rawValue: "MP-2000"))
        let setlist = Setlist(
            id: "slist-abc123",
            name: "Test",
            songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)]
        )
        #expect(setlist.contains(songId) == true)
        #expect(setlist.contains(otherSongId) == false)
    }

    @Test("SetlistSongEntry.summary maps number == 0 (the server's unknown sentinel) to nil, not a literal zero")
    func summaryMapsZeroNumberToNil() throws {
        let songId = try #require(SongID(rawValue: "Misc-0001"))
        let entry = SetlistSongEntry(songId: songId, title: "Untitled Hymn", songbookAbbreviation: "Misc", number: 0)
        #expect(entry.summary.number == nil)
        #expect(entry.summary.displayNumber == "")
    }

    @Test("SetlistSongEntry(summary:) round-trips a SongSummary's fields")
    func initFromSummary() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let summary = SongSummary(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
        let entry = SetlistSongEntry(summary: summary)
        #expect(entry.title == "Amazing Grace")
        #expect(entry.songbookAbbreviation == "MP")
        #expect(entry.number == 1008)
        #expect(entry.arrangement == nil)
    }
}

@Suite("SharedSetlist")
struct SharedSetlistTests {

    @Test("Decodes the real setlist_get response shape, including the undocumented live field")
    func decodesSharedSetlistResponseShape() throws {
        // Modelled from `api.php`'s `case 'setlist_get'` handler's literal
        // `$response` array — see `SharedSetlist.swift`'s header for why
        // `songs` is `[String]` (not `[SongID]`, which would reject the
        // #1380 draft-id shape) and why `live` isn't in `api-docs.yaml`'s
        // documented schema at all.
        let json = Data("""
        {
            "id": "1a2b3c4d",
            "name": "Sunday Morning Service",
            "songs": ["CP-0202", "MP-1008", "song-1a2b3c"],
            "live": true,
            "created": "2026-07-01T09:00:00+00:00",
            "updated": "2026-07-07T12:34:56+00:00"
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(SharedSetlist.self, from: json)
        #expect(decoded.id == "1a2b3c4d")
        #expect(decoded.songs == ["CP-0202", "MP-1008", "song-1a2b3c"])
        #expect(decoded.live == true)
        #expect(decoded.arrangements == nil)
    }

    @Test("Decodes an unavailable-free, arrangements-bearing snapshot share")
    func decodesShareWithArrangements() throws {
        let json = Data("""
        {
            "id": "1a2b3c4d",
            "name": "Snapshot Share",
            "songs": ["CP-0202"],
            "live": false,
            "created": null,
            "updated": null,
            "arrangements": { "CP-0202": [0, 1, 2, 1, 3] }
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(SharedSetlist.self, from: json)
        #expect(decoded.live == false)
        #expect(decoded.created == nil)
        #expect(decoded.arrangements?["CP-0202"] == [0, 1, 2, 1, 3])
    }

    @Test("ShareSetlistResult.canonicalURL builds the full https://ihymns.app URL from the relative path")
    func shareResultCanonicalURL() throws {
        let result = ShareSetlistResult(id: "1a2b3c4d", url: "/setlist/shared/1a2b3c4d")
        #expect(result.canonicalURL?.absoluteString == "https://ihymns.app/setlist/shared/1a2b3c4d")
    }
}
