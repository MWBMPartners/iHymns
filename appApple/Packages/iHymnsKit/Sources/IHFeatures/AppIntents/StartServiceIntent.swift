// StartServiceIntent.swift
// IHFeatures/AppIntents
//
// ELI5: The "Start hosting a live service in iHymns" Siri/Shortcuts action
// — starts Live Follow hosting and reads back the join code so the user can
// share it, without opening the app UI first.
//
// DETAILED: #1415. Requires sign-in (`AppRootViewModel.isSignedInNow()`,
// `+Auth.swift`) — `goLive()` itself has no such guard (`LiveHubView`'s own
// UI is what currently gates the button), but an intent run from Siri/
// Shortcuts has no surrounding UI to show a "sign in first" screen, so this
// checks explicitly and fails with spoken copy instead of a confusing
// silent/generic error.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents

public struct StartServiceIntent: AppIntent {
    public static let title: LocalizedStringResource = "Go Live"
    public static let openAppWhenRun = true

    @Dependency private var rootViewModel: AppRootViewModel
    @Dependency private var navigationState: AppNavigationState

    public init() {}

    @MainActor
    public func perform() async throws -> some IntentResult & ProvidesDialog {
        guard await rootViewModel.isSignedInNow() else {
            throw AppIntentGateError.signInRequired
        }
        let code = try await rootViewModel.goLive()
        navigationState.selectedSection = .live
        return .result(dialog: "You're live. Your join code is \(code).")
    }
}
#endif
