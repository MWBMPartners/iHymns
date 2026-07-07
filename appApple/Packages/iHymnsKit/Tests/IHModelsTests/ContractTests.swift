// ContractTests.swift
// IHModelsTests
//
// ELI5: Takes the REAL JSON the live iHymns server actually sent (not a
// guessed shape) and checks it still decodes into our Swift types — the
// drift alarm #1396 asked for. If a future server change ever renames or
// retypes a field these DTOs rely on, THIS test is what breaks first.
//
// DETAILED: Fixtures live in `Tests/Fixtures/` (recorded from `dev`,
// 2026-07-07 — see `README.md` there for provenance/trimming notes) and are
// loaded through the shared `IHTestFixtures.ContractFixtures` helper so
// `IHAPITests`' equivalent client-level contract tests read the exact same
// bytes. This suite decodes straight into the `IHModels` DTOs with a plain
// `JSONDecoder` (no `APIClient`/networking involved at all — that's
// `IHAPITests`' job) and asserts a representative slice of fields per type,
// not just "it didn't throw."
import Foundation
import Testing
import IHTestFixtures
@testable import IHModels

@Suite("Contract tests — live-recorded fixtures decode into IHModels DTOs")
struct ContractTests {

    @Test("songs_index.json decodes, tolerating the real catalogue's malformed legacy ids")
    func decodesSongsIndexFixture() throws {
        // Deliberately does NOT go through `APIClient.decodeSongsIndex` (an
        // IHAPI concern) — this proves `SongSummary` itself decodes the
        // real per-row shape. The envelope + lossy-row-skip behaviour is
        // exercised by `IHAPITests`' equivalent test instead.
        //
        // A bare `[SongSummary]` decode would throw the instant it hits one
        // of the fixture's ~10 legacy-shaped ids — so this test decodes
        // each row independently (mirroring `LossyElement`'s approach) to
        // prove the 74 well-formed rows all decode correctly, while
        // separately confirming the malformed ones are non-empty (i.e. the
        // fixture really does carry them, not that this test is vacuous).
        let json = try JSONSerialization.jsonObject(with: ContractFixtures.songsIndex()) as? [String: Any]
        let rows = try #require(json?["songs"] as? [[String: Any]])
        #expect(rows.count == 84)

        var decodedCount = 0
        var failedCount = 0
        for row in rows {
            let rowData = try JSONSerialization.data(withJSONObject: row)
            if (try? JSONDecoder().decode(SongSummary.self, from: rowData)) != nil {
                decodedCount += 1
            } else {
                failedCount += 1
            }
        }
        #expect(decodedCount == 74)
        #expect(failedCount == 10)
    }

    @Test("song_detail.json (MP-0031, Amazing grace) decodes with all 4 verses")
    func decodesSongDetailFixture() throws {
        struct Envelope: Decodable { let song: SongDetail }
        let envelope = try JSONDecoder().decode(Envelope.self, from: ContractFixtures.songDetail())
        let song = envelope.song

        #expect(song.songId.rawValue == "MP-0031")
        #expect(song.title == "Amazing grace")
        #expect(song.songbookAbbreviation == "MP")
        #expect(song.number == 31)
        #expect(song.hasAudio == true)
        #expect(song.hasSheetMusic == true)
        #expect(song.writers == ["John Newton"])
        #expect(song.composers == ["Roland Fudge"])
        #expect(song.components.count == 4)
        #expect(song.components[0].type == "verse")
        #expect(song.components[0].lines.count == 4)
        #expect(song.components[0].lineIds.count == 4)
        // The real payload's `chords`/`language`/`translations` are all
        // absent/null for this song — must decode to nil, not throw.
        #expect(song.components[0].chords == nil)
        #expect(song.translations == nil)
        // Real, always-present fields `api-docs.yaml` doesn't document at
        // all (see this file's header + `SongDetail.swift`'s doc comment).
        #expect(song.publicId == "QAJA39W25W")
        #expect(song.verified == false)
    }

    @Test("songbooks.json decodes all 54 songbooks, including a translation parent + a series")
    func decodesSongbooksFixture() throws {
        struct Envelope: Decodable { let songbooks: [Songbook] }
        let envelope = try JSONDecoder().decode(Envelope.self, from: ContractFixtures.songbooks())
        #expect(envelope.songbooks.count == 54)

        let missionPraise = try #require(envelope.songbooks.first { $0.id == "MP" })
        #expect(missionPraise.name == "Mission Praise")
        #expect(missionPraise.isOfficial == true)
        #expect(missionPraise.series.first?.name == "Mission Praise")
        #expect(missionPraise.languages == ["en"])

        // `publicationYear` is a STRING on the wire ("1977"), not the
        // integer `api-docs.yaml` documents — this assertion is what would
        // catch that regressing back to an assumed-integer model.
        let ays = try #require(envelope.songbooks.first { $0.id == "AYS" })
        #expect(ays.publicationYear == "1977")

        // A songbook that's a translation of another (#782 phase D).
        let dlg = try #require(envelope.songbooks.first { $0.id == "DLG" })
        #expect(dlg.parent?.abbreviation == "CIS")
        #expect(dlg.parent?.relationship == "translation")
    }
}
