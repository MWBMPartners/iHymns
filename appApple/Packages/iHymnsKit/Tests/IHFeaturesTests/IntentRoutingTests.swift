// IntentRoutingTests.swift
// IHFeaturesTests
//
// ELI5: Checks the PURE "which song id → where in the app" and "which live
// -sync failure → which spoken-copy error" logic App Intents lean on —
// without ever touching `AppDependencyManager` (this folder's own house
// rule, see `IHFeatures/AppIntents/AppIntentSupport.swift`'s header).
//
// #1415 (App Intents / Siri / Shortcuts / Spotlight).
import Testing
@testable import IHFeatures
@testable import IHLive
@testable import IHModels

@Suite("IntentRouting + AppIntentGateError (#1415)")
struct IntentRoutingTests {

    @Test("Resolves a valid SongEntity id into a .song deep link")
    func resolvesValidSongEntityID() throws {
        let expected = try #require(SongID(rawValue: "MP-1008"))
        #expect(IntentRouting.deepLink(forSongEntityID: "MP-1008") == .song(expected))
    }

    @Test("A garbage SongEntity id resolves to nil")
    func rejectsGarbageSongEntityID() {
        #expect(IntentRouting.deepLink(forSongEntityID: "not-a-song-id") == nil)
    }

    @Test("A Song-of-the-Day WITH a song resolves to that song's deep link")
    func resolvesSongOfTheDayWithSong() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let card = SongOfTheDayCard(
            songId: songId,
            number: 1008,
            title: "Amazing Grace",
            songbookAbbreviation: "MP",
            songbookName: "Mission Praise",
            verified: true
        )
        let sotd = SongOfTheDay(song: card, themeLabel: "Song of the Day", firstLine: "Amazing grace, how sweet the sound")
        #expect(IntentRouting.deepLink(for: sotd) == .song(songId))
    }

    @Test("A Song-of-the-Day with NO song (empty catalogue) resolves to nil")
    func resolvesSongOfTheDayWithNoSong() {
        let sotd = SongOfTheDay(song: nil, themeLabel: "Song of the Day", firstLine: "")
        #expect(IntentRouting.deepLink(for: sotd) == nil)
    }

    @Test(
        "AppIntentGateError maps every LiveSyncError case to spoken copy",
        arguments: [
            LiveSyncError.codeNotActive,
            LiveSyncError.alreadyHosting,
            LiveSyncError.alreadyFollowing,
            LiveSyncError.temporarilyUnavailable,
            LiveSyncError.network
        ]
    )
    func mapsEveryLiveSyncErrorCase(failure: LiveSyncError) {
        let gateError = AppIntentGateError(joinFailure: failure)
        switch failure {
        case .codeNotActive, .alreadyHosting, .alreadyFollowing:
            guard case .joinCodeNotActive = gateError else {
                Issue.record("Expected .joinCodeNotActive for \(failure)")
                return
            }
        case .temporarilyUnavailable:
            guard case .serviceUnavailable = gateError else {
                Issue.record("Expected .serviceUnavailable for \(failure)")
                return
            }
        case .network:
            guard case .network = gateError else {
                Issue.record("Expected .network for \(failure)")
                return
            }
        }
    }
}
