// NowSingingIntentBridge.swift
// IHLiveActivity
//
// ELI5: A tiny mailbox that lets a tap on the Live Activity's Next/Previous
// button (which runs OUTSIDE the app, inside an `AppIntent`, possibly while
// the app isn't even running) reach the ONE view model that actually knows
// how to advance the song — without `AppIntents`-only code (`NowSingingIntents
// .swift`, iOS-only) ever needing to import `IHFeatures` or know what an
// `AppRootViewModel` is.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Deliberately
// UNGUARDED (no `#if os(iOS)`) — `AppIntent.perform()` bodies are the ONLY
// thing that ever WRITES `handler` (`NowSingingIntents.swift`, iOS-only,
// `#if os(iOS) && canImport(AppIntents)`), but `AppRootViewModel
// +LiveActivity.swift` (`IHFeatures`, every platform) needs to READ/ASSIGN
// this same static from a plain, framework-agnostic file, exactly the way
// this file's sibling `NowSingingActivityControlling` protocol
// (`NowSingingActivityController.swift`) stays unguarded so `IHFeatures` can
// hold a reference to it without importing `ActivityKit` at all. A no-op
// `handler` (the default `nil`) is entirely safe: an `AppIntent` firing
// before `AppRootViewModel.init` has registered its handler simply does
// nothing, rather than crashing.
import Foundation

/// The hand-off point between a Live Activity button's `AppIntent` and the
/// app's own live-hosting logic. `@MainActor` because `AppRootViewModel`
/// (the only real handler this app registers) is itself `@MainActor`, and
/// because `LiveActivityIntent.perform()` bodies already run on the main
/// actor (Apple's own `LiveActivityIntent` documentation).
///
/// ELI5: "Someone tapped Next/Previous on the card — go tell whoever's
/// listening."
@MainActor
public enum NowSingingIntentBridge {
    /// Which button was tapped.
    public enum Action: String, Sendable {
        case nextSection
        case previousSection
    }

    /// Set ONCE by `AppRootViewModel.init` (`AppRootViewModel
    /// +LiveActivity.swift`) via a `[weak self]`-capturing closure, so a
    /// deallocated view model never leaves a dangling strong reference here.
    /// `nil` (the default) is the safe "nobody's listening yet" no-op state.
    public static var handler: (@MainActor (Action) async -> Void)?
}
