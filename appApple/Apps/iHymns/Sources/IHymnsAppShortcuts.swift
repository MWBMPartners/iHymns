// IHymnsAppShortcuts.swift
// iHymns (iOS / iPadOS / macOS / visionOS shell)
//
// ELI5: Tells iOS/macOS "here are the four things you can ask Siri to do in
// iHymns, and here's every phrase that should trigger each one" — this is
// what makes "Hey Siri, open Amazing Grace in iHymns" (or the matching
// Shortcuts action) actually work, system-wide, without the app even being
// open.
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). `IHymnsAppIntentsPackage`
// composes `IHFeaturesAppIntentsPackage` (`IHFeatures/AppIntents/
// AppIntentSupport.swift`) — Apple's documented multi-module App Intents
// registration (https://developer.apple.com/documentation/appintents/appintentspackage);
// this shell owns NO intent logic of its own, it only declares which
// package(s) exist and which phrases trigger which intent (this repo's
// modularity rule — the shell composes, `IHFeatures` implements). EVERY
// phrase below embeds `\(.applicationName)` (Apple's own App Shortcuts
// requirement: https://developer.apple.com/documentation/appintents/app-shortcuts) —
// Siri/Shortcuts rejects a phrase that omits it.
import AppIntents
import IHFeatures

/// Composes every `AppIntentsPackage` this app ships — today just
/// `IHFeatures`' own, but a future widget/watch-specific package would
/// extend `includedPackages` here rather than each app shell hand-rolling
/// its own registration.
struct IHymnsAppIntentsPackage: AppIntentsPackage {
    static var includedPackages: [any AppIntentsPackage.Type] {
        [IHFeaturesAppIntentsPackage.self]
    }
}

/// The four App Shortcuts this app exposes to Siri/Spotlight/the Shortcuts
/// app out of the box — no user setup required, unlike a custom Shortcut
/// the user builds themselves.
struct IHymnsAppShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: OpenSongIntent(),
            phrases: [
                "Open a song in \(.applicationName)",
                "Open \(\.$song) in \(.applicationName)"
            ],
            shortTitle: "Open a Song",
            systemImageName: "music.note.list"
        )
        AppShortcut(
            intent: PlaySongOfTheDayIntent(),
            phrases: [
                "Song of the Day in \(.applicationName)",
                "What's today's song in \(.applicationName)"
            ],
            shortTitle: "Song of the Day",
            systemImageName: "star"
        )
        AppShortcut(
            intent: StartServiceIntent(),
            phrases: [
                "Go live in \(.applicationName)",
                "Start a service in \(.applicationName)"
            ],
            shortTitle: "Go Live",
            systemImageName: "dot.radiowaves.left.and.right"
        )
        AppShortcut(
            intent: JoinServiceByCodeIntent(),
            phrases: [
                "Join a service in \(.applicationName)",
                "Join with a code in \(.applicationName)"
            ],
            shortTitle: "Join with a Code",
            systemImageName: "person.wave.2"
        )
    }
}
