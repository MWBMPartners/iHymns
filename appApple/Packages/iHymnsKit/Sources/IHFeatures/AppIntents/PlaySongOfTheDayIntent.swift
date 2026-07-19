// PlaySongOfTheDayIntent.swift
// IHFeatures/AppIntents
//
// ELI5: The "What's today's featured hymn?" Siri/Shortcuts action — Siri
// says which song it is and opens its lyrics; despite the name, it does NOT
// start audio playback (see this file's own note below).
//
// DETAILED: #1415. Named `PlaySongOfTheDayIntent` to match the natural
// Siri phrasing ("Play the Song of the Day in iHymns," `IHymnsAppShortcuts`'s
// phrase for it) — but `perform()` only OPENS the song's detail screen
// (`navigationState.pendingDeepLink`), it never starts
// `SongAudioPlaybackEngine`: not every song has an attached recording
// (`SongSummary.hasAudio`), and silently failing to "play" would be a worse
// experience than honestly opening the lyrics, exactly like tapping the
// Home screen's own Song-of-the-Day card does today.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents

public struct PlaySongOfTheDayIntent: AppIntent {
    public static let title: LocalizedStringResource = "Song of the Day"
    public static let openAppWhenRun = true

    @Dependency private var rootViewModel: AppRootViewModel
    @Dependency private var navigationState: AppNavigationState

    public init() {}

    @MainActor
    public func perform() async throws -> some IntentResult & ProvidesDialog {
        guard
            let sotd = try? await rootViewModel.songOfTheDay(),
            let song = sotd.song,
            let link = IntentRouting.deepLink(for: sotd)
        else {
            return .result(dialog: "There's no Song of the Day to show right now.")
        }
        navigationState.pendingDeepLink = link
        return .result(dialog: "Here's \(sotd.themeLabel): \(song.title).")
    }
}
#endif
