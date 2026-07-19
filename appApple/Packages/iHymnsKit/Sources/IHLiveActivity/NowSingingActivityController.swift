// NowSingingActivityController.swift
// IHLiveActivity
//
// ELI5: The thing that actually talks to iOS's Live Activity system —
// starts the Lock Screen / Dynamic Island card, refreshes it as the song
// changes, and dismisses it when hosting ends. The PROTOCOL it implements
// is available everywhere in this package; the REAL implementation only
// exists on iOS, where ActivityKit exists at all.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). `NowSingingActivityControlling`
// is deliberately UNGUARDED (no `#if os(iOS)`) so `IHFeatures`
// (`AppRootViewModel+LiveActivity.swift`, built for EVERY platform in
// `Package.swift`'s `platforms:` list) can hold `(any
// NowSingingActivityControlling)?` and call `apply(_:)` on it without ever
// importing `ActivityKit` itself — the exact same "protocol lives where
// everyone can see it, the concrete framework-backed type lives behind a
// platform guard" shape `IHLive`'s `LiveFollowEngine` already uses for its
// own `apiClient: APIClient` dependency (just one layer further, gating an
// entire TYPE rather than a stored property). `AppRootViewModel
// +Live.swift`'s `makeLive(environment:)` is the ONE place that ever
// constructs the real `NowSingingActivityController` (under the identical
// `#if os(iOS) && canImport(ActivityKit)` guard); every other platform
// passes `nil` for the protocol-typed dependency, and every `IHFeatures`
// call site is written to be a silent no-op against that `nil`.
import Foundation
import IHModels

/// What "control the host's Live Activity" means — see this file's header
/// for why this protocol is unguarded while its real implementation isn't.
///
/// ELI5: "Start the card, update the card, end the card" — plus "tell me
/// when you get a fresh push address for it."
@MainActor
public protocol NowSingingActivityControlling: AnyObject {
    /// Fires with a freshly-minted APNs push token (already lower-case hex)
    /// whenever ActivityKit hands the controller a new one.
    /// `AppRootViewModel+LiveActivity.swift` is the one real consumer — it
    /// registers the token against the current hosting session via
    /// `?action=apns_register`. The token is NEVER logged anywhere in this
    /// call chain (`.claude/CLAUDE.md`'s "a token in any log" tripwire).
    var onPushTokenHex: (@Sendable (String) async -> Void)? { get set }

    /// Executes one `NowSingingActivityReducer`-decided command
    /// (`.start`/`.update`/`.end`/`.none`).
    func apply(_ command: NowSingingActivityCommand) async
}

/// Which APNs environment THIS build talks to — Apple's own Sandbox-vs-
/// Production split (`apns.php`'s `APNS_ENVIRONMENTS` mirrors this
/// server-side). Deliberately UNGUARDED (not `#if os(iOS)`), even though
/// its only real consumer today is the `kind=liveActivity` token
/// registration flow — `AppRootViewModel+LiveActivity.swift` (`IHFeatures`,
/// EVERY platform this package targets) reads `.current` to build the
/// `apnsRegister(...)` call, so this must compile on watchOS/tvOS/macOS
/// too, not just where `ActivityKit` itself exists.
///
/// ELI5: "Debug builds talk to Apple's practice server; release builds
/// talk to the real one."
public enum ApnsEnvironmentDefault {
    public static var current: String {
        #if DEBUG
        "sandbox"
        #else
        "production"
        #endif
    }
}

#if os(iOS) && canImport(ActivityKit)
// `@preconcurrency` — ActivityKit's own `Activity<Attributes>.update(_:)`/
// `.end(_:dismissalPolicy:)` are `nonisolated async` instance methods; even
// though `Activity` and `NowSingingActivityAttributes` are both genuinely
// `Sendable`, Swift 6's region-based "sending" checker (SE-0414) still
// flags passing a MainActor-derived `Activity` reference into them as a
// possible data race (`current.update(...)`/`current.end(...)` below) —
// the exact class of "SDK not fully concurrency-audited for the newest
// checker" friction `@preconcurrency import` exists for (verified this is
// what actually clears the diagnostic; `Sendable`-conformance alone on both
// generic parameters, confirmed via a standalone probe, was NOT enough).
@preconcurrency import ActivityKit

/// The REAL controller — starts/updates/ends the actual iOS Live Activity
/// via `ActivityKit.Activity<NowSingingActivityAttributes>`. See
/// `NowSingingActivityControlling`'s own doc comment for why `IHFeatures`
/// never references this concrete type directly (only through the
/// protocol).
///
/// DETAILED: `Activity.request(attributes:content:pushType:)` is Apple's
/// synchronous-but-throwing entry point (NOT `async` — verified against the
/// real `ActivityKit.swiftmodule`, iOS 26 SDK); `pushType: .token` is what
/// makes ActivityKit hand back a `pushTokenUpdates` async sequence at all
/// (omitting it would leave this app with no way to push server-driven
/// updates while backgrounded — the whole point of #1429's APNs bridge,
/// `includes/apns.php`/`includes/live_activity_push.php`). A 180-second
/// `staleDate` on every start/update mirrors `LIVE_SESSION_FRESHNESS_SECONDS`
/// (`service_mode.php`) — the SAME "how long is a broadcast still fresh"
/// window the rest of Live Follow/Service Mode uses everywhere else, so the
/// Lock Screen card visually goes stale on the identical horizon a
/// follower's poll would.
@MainActor
public final class NowSingingActivityController: NowSingingActivityControlling {
    public var onPushTokenHex: (@Sendable (String) async -> Void)?

    /// The freshness window mirrored from `LiveFollowEngine
    /// .freshnessWindowSeconds` (`IHLive`) — not imported directly (this
    /// target has no dependency on `IHLive`), so the literal is restated
    /// here with the same value; both sides derive from the identical
    /// server-side `LIVE_SESSION_FRESHNESS_SECONDS` constant this file's
    /// own doc comment cites.
    private static let staleWindowSeconds: TimeInterval = 180

    private var activity: Activity<NowSingingActivityAttributes>?
    private var pushTokenTask: Task<Void, Never>?

    public init() {}

    public func apply(_ command: NowSingingActivityCommand) async {
        switch command {
        case .start(let attributes, let state):
            await start(attributes: attributes, state: state)
        case .update(let state):
            await update(state: state)
        case .end(let final):
            await end(final: final)
        case .none:
            break
        }
    }

    /// Starts a fresh activity. If one is somehow already running (a
    /// re-`goLive` supersede racing this controller), it's ended first —
    /// ActivityKit itself permits multiple concurrent activities of the
    /// same kind, but this app only ever wants ONE "now singing" card on
    /// screen at a time.
    private func start(attributes: NowSingingActivityAttributes, state: NowSingingActivityAttributes.ContentState) async {
        if activity != nil {
            await end(final: state)
        }
        do {
            let content = ActivityContent(state: state, staleDate: Date().addingTimeInterval(Self.staleWindowSeconds))
            let started = try Activity<NowSingingActivityAttributes>.request(
                attributes: attributes, content: content, pushType: .token
            )
            activity = started
            listenForPushTokens(on: started)
        } catch {
            // Live Activities can be denied (the user disabled them in
            // Settings, or the system's concurrent-activity budget is
            // exhausted) — a silent no-op is the right degrade: the app's
            // OWN Live Follow hosting session is entirely unaffected either
            // way, only the Lock Screen/Dynamic Island card never appears.
        }
    }

    private func update(state: NowSingingActivityAttributes.ContentState) async {
        guard let current = activity else { return }
        let content = ActivityContent(state: state, staleDate: Date().addingTimeInterval(Self.staleWindowSeconds))
        await current.update(content)
    }

    private func end(final state: NowSingingActivityAttributes.ContentState) async {
        pushTokenTask?.cancel()
        pushTokenTask = nil
        guard let current = activity else { return }
        activity = nil
        let content = ActivityContent(state: state, staleDate: nil)
        await current.end(content, dismissalPolicy: .immediate)
    }

    /// One long-lived `for await` loop per activity, mirroring `AppRootViewModel
    /// +LiveSync.swift`'s own "single consumer per stream" shape —
    /// cancelled explicitly on `end(final:)` rather than left to outlive the
    /// activity it was minted for.
    private func listenForPushTokens(on activity: Activity<NowSingingActivityAttributes>) {
        pushTokenTask?.cancel()
        pushTokenTask = Task { [weak self] in
            for await tokenData in activity.pushTokenUpdates {
                guard let self, !Task.isCancelled else { return }
                let hex = tokenData.map { String(format: "%02x", $0) }.joined()
                await self.onPushTokenHex?(hex)
            }
        }
    }
}
#endif
