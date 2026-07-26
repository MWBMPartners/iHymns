// NowSingingIntents.swift
// IHLiveActivity
//
// ELI5: The Next/Previous buttons on the Live Activity card are `AppIntent`s
// (Apple's "a button that can run without opening the app" mechanism) — this
// file is the two tiny intents those buttons run, and all they do is hand
// off to `NowSingingIntentBridge` (this target's OWN unguarded mailbox,
// `NowSingingIntentBridge.swift`) rather than know anything about
// `AppRootViewModel` themselves.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). `LiveActivityIntent`
// (not the plain `AppIntent`) is Apple's dedicated protocol for a button
// INSIDE a Live Activity/Dynamic Island — it runs WITHOUT opening the app
// (`AppIntent.perform()` would instead foreground it), which is exactly the
// "tap Next without leaving the Lock Screen" UX this feature needs. Verified
// against the real `AppIntents`/`ActivityKit` module interfaces (iOS 26
// SDK) via a standalone typecheck — `LiveActivityIntent` conforms to
// `AppIntent` and its `perform()` already runs on the main actor (Apple's
// own documentation), matching `NowSingingIntentBridge.handler`'s
// `@MainActor` signature with no extra hopping needed.
#if os(iOS) && canImport(AppIntents)
import AppIntents

/// The "Next section" button shown on the host's own Live Activity
/// (`NowSingingLiveActivityWidget.swift`).
public struct NowSingingNextSectionIntent: LiveActivityIntent {
    // A computed property (not `= "Next section"` stored-with-initial-value
    // sugar) — Swift 6 strict concurrency flags a mutable STORED global as
    // non-concurrency-safe shared state (`#MutableGlobalVariable`); a
    // computed property has no such storage to race on.
    public static var title: LocalizedStringResource { "Next section" }

    public init() {}

    public func perform() async throws -> some IntentResult {
        await NowSingingIntentBridge.handler?(.nextSection)
        return .result()
    }
}

/// The "Previous section" button shown on the host's own Live Activity.
public struct NowSingingPreviousSectionIntent: LiveActivityIntent {
    public static var title: LocalizedStringResource { "Previous section" }

    public init() {}

    public func perform() async throws -> some IntentResult {
        await NowSingingIntentBridge.handler?(.previousSection)
        return .result()
    }
}
#endif
