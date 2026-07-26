// AppIntentSupport.swift
// IHFeatures/AppIntents
//
// ELI5: The small shared pieces every App Intent in this folder leans on —
// the "this module has App Intents" registration marker, the friendly error
// messages Siri/Shortcuts speaks aloud when something goes wrong, and the
// ONE pure function that turns "which song did you pick" into "where should
// the app navigate."
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). This whole FOLDER is gated
// `#if os(iOS) || os(macOS) || os(visionOS)` — the only platforms this
// package's `IHymns` app shell (`Apps/iHymns/`) actually ships to (per
// `appApple/project.yml`'s `supportedDestinations: [iOS, macOS, visionOS]`);
// tvOS/watchOS never import `AppIntents` from here at all, mirroring
// `RootContainerView.swift`'s own "only the iOS/macOS/visionOS shell
// instantiates this" precedent for shell-specific UI.
//
// `AppIntentGateError`/`IntentRouting` are deliberately NOT `public` — every
// consumer (`OpenSongIntent`/`PlaySongOfTheDayIntent`/`StartServiceIntent`/
// `JoinServiceByCodeIntent`, `IntentRoutingTests`) lives in this SAME module
// (`IHFeatures`), so `internal` is the correct, minimal visibility; only
// `IHFeaturesAppIntentsPackage` needs to be `public` (the app shell,
// `Apps/iHymns/Sources/IHymnsAppShortcuts.swift`, references it from a
// DIFFERENT module).
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents
import IHAppSupport
import IHLive
import IHModels

/// Marks `IHFeatures` as contributing its own App Intents, so
/// `Apps/iHymns/Sources/IHymnsAppShortcuts.swift`'s
/// `IHymnsAppIntentsPackage.includedPackages` can pull them in — Apple's
/// documented multi-module App Intents composition
/// (https://developer.apple.com/documentation/appintents/appintentspackage).
///
/// ELI5: "Yes, this module has Siri/Shortcuts actions in it."
public struct IHFeaturesAppIntentsPackage: AppIntentsPackage {
    public init() {}
}

/// The friendly, spoken-out-loud reasons an App Intent's `perform()` can
/// fail — deliberately mirrors `LiveJoinSheet`'s existing copy
/// (`LiveJoinSheet.swift`'s `join()`) so a Siri failure and an in-app
/// failure never disagree about what went wrong.
///
/// ELI5: "Here's what to tell the user (or have Siri say out loud) when this
/// didn't work."
enum AppIntentGateError: Error, CustomLocalizedStringResourceConvertible {
    case signInRequired
    case songNotFound
    case joinCodeNotActive
    case serviceUnavailable
    case network

    /// Maps `AppRootViewModel.joinWithCode(_:)`'s thrown `LiveSyncError`
    /// onto the equivalent spoken-copy case — `.alreadyHosting`/
    /// `.alreadyFollowing` both fold to `.joinCodeNotActive` (Siri has no
    /// concept of "you're already hosting," and the underlying join simply
    /// didn't succeed either way — the SAME "opaque, not distinguishing
    /// which" posture `LiveSyncError.codeNotActive`'s own doc comment
    /// documents for the anti-probe reason).
    init(joinFailure: LiveSyncError) {
        switch joinFailure {
        case .codeNotActive, .alreadyHosting, .alreadyFollowing:
            self = .joinCodeNotActive
        case .temporarilyUnavailable:
            self = .serviceUnavailable
        case .network:
            self = .network
        }
    }

    var localizedStringResource: LocalizedStringResource {
        switch self {
        case .signInRequired:
            "You need to sign in to iHymns first."
        case .songNotFound:
            "I couldn't find that song."
        case .joinCodeNotActive:
            "That code isn't active right now. Check the screen and try again."
        case .serviceUnavailable:
            "iHymns is briefly unavailable — try again in a minute."
        case .network:
            "iHymns couldn't be reached. Check your connection and try again."
        }
    }
}

/// Turns "which song / which featured song" into a `DeepLink` — PURE, no
/// actor hops, so it's directly unit-testable (`IntentRoutingTests`)
/// without ever touching `AppDependencyManager` (this file's header
/// explains why that separation matters: a unit test that mutated the
/// process-global dependency graph would leak state across every OTHER test
/// in the same process).
///
/// ELI5: "Given this song's id (or today's featured song), where in the app
/// should we go?"
enum IntentRouting {
    /// - Parameter id: A `SongEntity.id` — `SongID.rawValue`.
    /// - Returns: `nil` only if `id` isn't a well-shaped `SongID` (should be
    ///   unreachable in practice — every `SongEntity` is built from an
    ///   already-valid `SongID` — but `OpenSongIntent.perform()` still
    ///   checks rather than force-unwrapping).
    static func deepLink(forSongEntityID id: String) -> DeepLink? {
        guard let songId = SongID(rawValue: id) else { return nil }
        return .song(songId)
    }

    /// - Returns: `nil` when `sotd.song` itself is `nil` (an empty
    ///   catalogue, per `SongOfTheDay.song`'s own doc comment) —
    ///   `PlaySongOfTheDayIntent` maps that to a "no featured song today"
    ///   spoken response rather than navigating anywhere.
    static func deepLink(for sotd: SongOfTheDay) -> DeepLink? {
        guard let song = sotd.song else { return nil }
        return .song(song.songId)
    }
}
#endif
