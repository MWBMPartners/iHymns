// IHAnalyticsService.swift
// IHFeatures
//
// ELI5: The ONE place in the app that's allowed to remember "something
// happened" (a screen was opened, a song was opened, a search was run) —
// and it flatly refuses to remember anything at all unless the user
// explicitly said yes to "Share Anonymous Usage Data" in Settings. No
// Apple tracking prompt ever appears, because this never touches anything
// that counts as "tracking" under Apple's rules in the first place.
//
// DETAILED: #189 (Apple Phase 1 — first-party, consent-gated analytics,
// strategy §3.1). Composes the two pure building blocks in `IHAppSupport`
// (`IHAnalyticsEvent`'s closed event vocabulary + `any IHAnalyticsSink`'s
// swappable destination) with the ONE piece of state that actually decides
// anything here: `IHSettingsStore.analyticsConsentEnabled` (#182's Settings
// → Privacy toggle, default OFF). `record(_:)` is the single choke point —
// EVERY convenience method below funnels through it — so "is this a
// verified no-op when consent is off" is a property of one four-line
// function, not something every call site has to get right independently.
//
// WHY NO App Tracking Transparency (ATT) PROMPT: ATT/`ATTrackingManager`
// exists to gate cross-app/cross-company user- or device-level tracking
// (https://developer.apple.com/documentation/apptrackingtransparency) —
// IDFA, fingerprinting, sharing identifiers with third parties/ad networks.
// This service does none of that: every event is FIRST-PARTY (recorded and,
// since #1448, sent only to iHymns' own `?action=analytics_ingest`
// endpoint), carries no device/user identifier of any kind, and
// `IHAnalyticsEvent`'s closed factory list (never a free-form payload)
// makes "no PII ever sneaks in" an auditable property of that one file
// rather than a promise every call site has to keep. That is precisely the
// class of first-party analytics Apple's own guidance says does NOT
// require an ATT prompt or a "tracking" declaration in
// `PrivacyInfo.xcprivacy`.
//
// WHY CONSTRUCTED FRESH AT EACH CALL SITE (not threaded through
// `AppRootViewModel`'s DI graph): `IHSettingsStore` itself already
// establishes exactly this shape — a cheap, stateless VALUE wrapping the
// one real `UserDefaults.standard`, built wherever it's needed
// (`IHymnsApp.swift`'s launch-time environment read, `SettingsViewModel`'s
// default parameter) rather than passed down a constructor chain. This
// service follows the identical convention for the identical reason: the
// state that actually matters — the consent flag — already lives in
// `UserDefaults`, so a fresh instance sees it correctly with no
// synchronization needed. `recordedEvents` below is therefore
// PROCESS-LOCAL to whichever instance recorded them (useful for tests, and
// for any call site that wants to inspect what it just queued) rather than
// a single cross-call-site ledger.
//
// #1448 UPDATE — `IHAnalyticsNetworkSink` (`IHFeatures`) is now the
// PRODUCTION default sink (Release builds only — `DEBUG` keeps the inert
// `IHAnalyticsLogSink`, so `swift test`/local development never fires real
// network traffic). Constructing a FRESH `IHAnalyticsService()` at every
// call site (this file's own convention, above) would defeat that sink's
// batching if it were ALSO constructed fresh each time — a queue-of-one
// flushed on every single call is not batching. `productionNetworkSink`
// below is therefore a `static let` (Swift guarantees exactly-once,
// thread-safe lazy initialization of a static stored property), so every
// default-constructed `IHAnalyticsService()` across the app shares the
// SAME long-lived sink instance and its queue actually accumulates across
// the 7 independent call sites (`RootContainerView`, `SongDetailViewModel`,
// …) — the "revisit this once a real batching sink exists" this comment
// used to flag.
import IHAppSupport

/// Records first-party, anonymous analytics events — a verified no-op
/// unless the user has opted in via Settings → Privacy.
///
/// ELI5: "Something happened — should we remember it?" Only if the user
/// said yes.
@MainActor
public final class IHAnalyticsService {
    /// Where the consent flag actually lives — read fresh on every
    /// `record(_:)` call (never cached at `init`) so a user flipping the
    /// toggle mid-session takes effect on the very next event, not just the
    /// next app launch.
    private let settingsStore: IHSettingsStore

    /// Where a consent-approved event goes once recorded — defaults to the
    /// inert `IHAnalyticsLogSink` (see that type's header for exactly what
    /// "inert" means) until a real first-party endpoint exists.
    private let sink: any IHAnalyticsSink

    /// Every event THIS instance has recorded, oldest-first — see this
    /// file's header for why this is process-local rather than a
    /// cross-instance ledger. Capped defensively so a very long-lived
    /// instance (e.g. one held for a whole app run by a future caller)
    /// can't grow without bound; the cap only ever discards the OLDEST
    /// entries, never the newest.
    public private(set) var recordedEvents: [IHAnalyticsEvent] = []

    /// The most events `recordedEvents` will ever hold at once.
    private static let recordedEventsCap = 500

    /// The ONE shared `IHAnalyticsNetworkSink` every default-constructed,
    /// Release-build `IHAnalyticsService()` forwards to — see this file's
    /// "#1448 UPDATE" header note for why a `static let` singleton (not a
    /// fresh sink per call site) is what makes batching actually work.
    /// `public`, not `private` — despite existing purely as internal
    /// wiring for `init`'s default parameter below, Swift requires a
    /// default-argument EXPRESSION to be at least as accessible as the
    /// function itself (it's re-evaluated at each call site, which for a
    /// `public init` can be a different module entirely — e.g.
    /// `Apps/iHymns/Sources/IHymnsApp.swift`); `private`/`internal` here
    /// fails to build with "cannot be referenced from a default argument
    /// value." No call site outside this file is expected to reference it
    /// directly.
    public static let productionNetworkSink: any IHAnalyticsSink = IHAnalyticsNetworkSink()

    /// `DEBUG` (incl. `swift test`) keeps the inert `IHAnalyticsLogSink` —
    /// no unit test or local run should ever fire a real network request
    /// just because it happened to construct a default `IHAnalyticsService()`.
    /// Release (TestFlight + App Store) gets the real, batched network sink.
    /// Mirrors `APIEnvironment.defaultForBuild`'s identical `#if DEBUG` shape.
    /// `public` for the same default-argument-accessibility reason as
    /// `productionNetworkSink` above.
    public static var defaultSink: any IHAnalyticsSink {
        #if DEBUG
        IHAnalyticsLogSink()
        #else
        productionNetworkSink
        #endif
    }

    /// - Parameters:
    ///   - settingsStore: Where to read `analyticsConsentEnabled` from;
    ///     defaults to the real `IHSettingsStore()`. Tests inject a store
    ///     built on a throwaway `UserDefaults(suiteName:)`, the same seam
    ///     `SettingsViewModel`'s own tests already use.
    ///   - sink: Where a consent-approved event is forwarded; defaults to
    ///     `defaultSink` above (`IHAnalyticsLogSink()` in `DEBUG`, the
    ///     shared `IHAnalyticsNetworkSink` in Release). Tests inject a
    ///     recording spy to assert on what actually reached the "sink seam"
    ///     versus what stayed queued.
    public init(settingsStore: IHSettingsStore = IHSettingsStore(), sink: any IHAnalyticsSink = IHAnalyticsService.defaultSink) {
        self.settingsStore = settingsStore
        self.sink = sink
    }

    /// The single choke point every event flows through. A verified no-op
    /// — nothing appended to `recordedEvents`, nothing forwarded to `sink`
    /// — when `analyticsConsentEnabled` is `false`.
    ///
    /// ELI5: "Only actually do anything if the user said yes."
    public func record(_ event: IHAnalyticsEvent) {
        guard settingsStore.analyticsConsentEnabled else { return }
        recordedEvents.append(event)
        if recordedEvents.count > Self.recordedEventsCap {
            recordedEvents.removeFirst(recordedEvents.count - Self.recordedEventsCap)
        }
        sink.record(event)
    }

    // MARK: - Convenience wrappers (the call sites this task wires)

    /// A top-level screen became visible. `RootContainerView` calls this
    /// from an `.onChange(of: navigationState.selectedSection, initial:
    /// true)`, which — per SwiftUI's own documented `initial:` behaviour —
    /// fires once immediately for the launch screen too, not just on later
    /// switches.
    public func screenViewed(_ screen: String) {
        record(.screenView(screen))
    }

    /// A song's full record finished loading. `SongDetailViewModel` calls
    /// this from `loadPrimaryDetail()`, anchored to the SAME successful-load
    /// moment as `AppRootViewModel.recordRecentlyViewed(_:)` (#183) —
    /// consistent with that method's own reasoning: a still-loading or
    /// failed fetch was never actually "opened" from the user's
    /// perspective.
    public func songOpened(songId: String) {
        record(.songOpened(songId: songId))
    }

    /// A search was submitted. `AppRootViewModel.commitCurrentSearch()`
    /// calls this — see that method's own "record on submit, not on every
    /// keystroke" convention, which this event reuses rather than firing on
    /// every character typed.
    public func searchPerformed(resultCount: Int) {
        record(.searchPerformed(resultCount: resultCount))
    }

    /// A song was saved for offline reading.
    /// `SongDetailViewModel.toggleOfflineSave()` calls this only on the
    /// SAVE path, never the remove path — "offline_save" names the action
    /// being measured, not "offline storage state changed."
    public func offlineSaveToggled(songId: String) {
        record(.offlineSave(songId: songId))
    }
}
