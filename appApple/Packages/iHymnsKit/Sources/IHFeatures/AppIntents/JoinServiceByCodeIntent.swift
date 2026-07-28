// JoinServiceByCodeIntent.swift
// IHFeatures/AppIntents
//
// ELI5: The "Join a live service with this code" Siri/Shortcuts action —
// say or type the code from the venue screen, and the app jumps straight
// into following along.
//
// DETAILED: #1415. `code`'s input options force `.allCharacters`
// (all-caps) keyboard capitalization + disable autocorrect on the system's
// own text-entry sheet, mirroring `LiveJoinSheet`'s
// `.textInputAutocapitalization(.characters)` + `.autocorrectionDisabled()`
// — `AppRootViewModel.joinWithCode(_:)` itself already normalises/validates
// whatever arrives (`AppRootViewModel+LiveSync.swift`'s "Join-code
// normalisation" section), so this is purely a Siri/Shortcuts UX nicety,
// not a correctness requirement.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents
import IHLive

public struct JoinServiceByCodeIntent: AppIntent {
    public static let title: LocalizedStringResource = "Join with a Code"
    public static let openAppWhenRun = true

    @Parameter(title: "Code", inputOptions: String.IntentInputOptions(capitalizationType: .allCharacters, autocorrect: false))
    public var code: String

    @Dependency private var rootViewModel: AppRootViewModel
    @Dependency private var navigationState: AppNavigationState

    public init() {}

    public init(code: String) {
        self.code = code
    }

    @MainActor
    public func perform() async throws -> some IntentResult & ProvidesDialog {
        do {
            try await rootViewModel.joinWithCode(code)
        } catch let error as LiveSyncError {
            throw AppIntentGateError(joinFailure: error)
        }
        navigationState.selectedSection = .live
        return .result(dialog: "You're in — following the service live.")
    }
}
#endif
