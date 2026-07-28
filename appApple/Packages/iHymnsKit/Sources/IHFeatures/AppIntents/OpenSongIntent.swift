// OpenSongIntent.swift
// IHFeatures/AppIntents
//
// ELI5: The "Open a hymn" Shortcuts/Siri action — pick a song (by name, by
// number, or from what you recently read), and the app jumps straight to
// it.
//
// DETAILED: #1415. `openAppWhenRun = true` — this intent's whole point is
// to bring the app to the foreground showing the chosen song, mirroring how
// tapping a Universal Link already behaves (`IHymnsApp.swift`'s
// `handle(url:)`). `perform()` itself is the ONE place in this folder that
// touches the `@Dependency` directly — deliberately NOT unit-tested (this
// folder's house rule: `AppDependencyManager` is process-global, so a unit
// test driving `perform()` would mutate state every OTHER test in the same
// process could observe); `IntentRoutingTests` covers the PURE routing
// logic this delegates to instead.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents

public struct OpenSongIntent: AppIntent {
    public static let title: LocalizedStringResource = "Open a Song"
    public static let openAppWhenRun = true

    @Parameter(title: "Song")
    public var song: SongEntity

    @Dependency private var navigationState: AppNavigationState

    public init() {}

    public init(song: SongEntity) {
        self.song = song
    }

    @MainActor
    public func perform() async throws -> some IntentResult {
        guard let link = IntentRouting.deepLink(forSongEntityID: song.id) else {
            throw AppIntentGateError.songNotFound
        }
        navigationState.pendingDeepLink = link
        return .result()
    }
}
#endif
