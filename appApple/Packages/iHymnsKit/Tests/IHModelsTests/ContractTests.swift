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
        // #180 additions — this fixture predates `include=`, so both new
        // opt-in blocks are simply absent; must decode to nil, not throw.
        #expect(song.annotations == nil)
        #expect(song.royaltyIds == nil)
        // #1752 Slice A — the five #1741 P1 identity keys + the opt-in
        // `include=externalIds` block, hand-added to this fixture (see
        // `Tests/Fixtures/README.md`/`SongDetail.swift`'s header for why a
        // real re-record needs a post-#1750 dev pull, tracked as a #1752 §5
        // deferred follow-up).
        #expect(song.subtitle == "")
        #expect(song.disambiguation == "")
        #expect(song.firstPublishedYear == 1779)
        #expect(song.copyrightYears == "")
        #expect(song.copyrightHolder == "")
        #expect(song.externalIds?.count == 1)
        #expect(song.externalIds?.first?.idType == "isrc")
        #expect(song.externalIds?.first?.idValue == "GBAYE7900001")
    }

    @Test("song_links.json (MP-0031) decodes the real empty-counterpart-group envelope")
    func decodesSongLinksFixture() throws {
        // The live answer for every song this task sampled is a genuinely
        // empty group — see `Tests/Fixtures/README.md`. This proves the
        // ENVELOPE (`groupId`/`songs` + the `hasCounterparts` derived check)
        // decodes correctly against real bytes, not a per-row shape no live
        // payload has ever populated.
        let group = try JSONDecoder().decode(SongLinkGroup.self, from: ContractFixtures.songLinks())
        #expect(group.groupId == 0)
        #expect(group.songs.isEmpty)
        #expect(group.hasCounterparts == false)
    }

    @Test("related_songs.json (MP-0031) decodes 10 real related songs with match reasons")
    func decodesRelatedSongsFixture() throws {
        struct Envelope: Decodable { let related: [RelatedSongSummary] }
        let envelope = try JSONDecoder().decode(Envelope.self, from: ContractFixtures.relatedSongs())
        #expect(envelope.related.count == 10)

        let first = try #require(envelope.related.first)
        #expect(first.songId.rawValue == "JP-0008")
        #expect(first.title == "Amazing grace")
        #expect(first.reason == "Same writer: John Newton")

        // `related_songs`' real rows omit `songbookName`/`language`/
        // `hasAudio`/`hasSheetMusic`/`publicId` — this decode succeeding at
        // all (rather than throwing on a missing required key) is what
        // proves `RelatedSongSummary` is correctly its OWN shape, not a
        // reuse of the stricter `SongSummary`.
        #expect(envelope.related.allSatisfy { !$0.songbookAbbreviation.isEmpty })
    }

    @Test("SongDetailTranslationsField decodes the per-line shape when lineId/text/isPrimary are present")
    func decodesPerLineTranslationsShape() throws {
        // Hand-constructed (no live song has this populated yet — see
        // `SongLineEnrichment.swift`'s header) directly from
        // `SongData::getSongDetailExtras()`'s SQL column aliases: `lineId`,
        // `kind`, `targetLanguage`, `text`, `isPrimary`.
        let json = Data("""
        [{"lineId": 768565, "kind": "translation", "targetLanguage": "es", "text": "Sublime gracia", "isPrimary": true}]
        """.utf8)
        let field = try JSONDecoder().decode(SongDetailTranslationsField.self, from: json)
        guard case .perLine(let rows) = field else {
            Issue.record("Expected .perLine, got \(field)")
            return
        }
        #expect(rows.count == 1)
        #expect(rows[0].lineId == 768565)
        #expect(rows[0].kind == "translation")
        #expect(rows[0].targetLanguage == "es")
        #expect(rows[0].isPrimary == true)
    }

    @Test("SongDetailTranslationsField decodes the whole-song cross-language-sibling shape when songId/language are present")
    func decodesCrossLanguageSiblingsShape() throws {
        // The OTHER real shape the exact same wire key can carry
        // (`SongData::_fetchSongRow()`) — proves the two shapes never get
        // confused for one another (see this type's own doc comment for the
        // key collision this resolves).
        let json = Data("""
        [{"songId": "ES-0031", "language": "es"}]
        """.utf8)
        let field = try JSONDecoder().decode(SongDetailTranslationsField.self, from: json)
        guard case .crossLanguageSiblings(let refs) = field else {
            Issue.record("Expected .crossLanguageSiblings, got \(field)")
            return
        }
        #expect(refs.count == 1)
        #expect(refs[0].songId == "ES-0031")
        #expect(refs[0].language == "es")
    }

    @Test("LyricLineAnnotation and SongRoyaltyId decode their PHP-verified shapes")
    func decodesAnnotationAndRoyaltyIdShapes() throws {
        // Hand-constructed from `SongData::getSongDetailExtras()`'s SQL
        // column aliases, same reasoning as the translations test above.
        let annotationJson = Data("""
        [{"startLineId": 768565, "endLineId": 768566, "annotationType": "scripture", "body": "Cf. Ephesians 2:8", "bodyFormat": "plain", "isVerified": true}]
        """.utf8)
        let annotations = try JSONDecoder().decode([LyricLineAnnotation].self, from: annotationJson)
        #expect(annotations.count == 1)
        #expect(annotations[0].startLineId == 768565)
        #expect(annotations[0].endLineId == 768566)
        #expect(annotations[0].isVerified == true)

        let royaltyJson = Data("""
        [{"authority": "ASCAP", "authorityId": "123456789", "note": null}]
        """.utf8)
        let royaltyIds = try JSONDecoder().decode([SongRoyaltyId].self, from: royaltyJson)
        #expect(royaltyIds.count == 1)
        #expect(royaltyIds[0].authority == "ASCAP")
        #expect(royaltyIds[0].note == nil)
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

    @Test("song_of_the_day.json decodes the real bare/undated envelope (#183)")
    func decodesSongOfTheDayFixture() throws {
        let sotd = try JSONDecoder().decode(SongOfTheDay.self, from: ContractFixtures.songOfTheDay())

        let song = try #require(sotd.song)
        #expect(song.songId.rawValue == "GASD-0019")
        #expect(song.number == 19)
        #expect(song.title == "Бог Есть Любовь")
        #expect(song.songbookAbbreviation == "GASD")
        #expect(song.songbookName == "Гимн адвентистов седьмого дня")
        #expect(song.verified == false)
        #expect(sotd.themeLabel == "Song of the Day")
        #expect(!sotd.firstLine.isEmpty)
    }
}
