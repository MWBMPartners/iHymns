// IHAnalyticsSink.swift
// IHAppSupport
//
// ELI5: "Where do recorded events actually GO?" — a tiny, swappable seam so
// the analytics service never has to know or care whether an event ends up
// nowhere (today), in the Xcode console (debug builds), or on a real server
// (once one exists).
//
// DETAILED: #189 (Apple Phase 1 analytics). The project's own backend
// (`appWeb/public_html/api.php`) was surveyed for a first-party event-
// ingestion endpoint and has none — `manage/analytics.php` is a READ-ONLY
// admin dashboard over tables other features already populate
// (`tblSongHistory`/`tblActivityLog`/`tblLoginAttempts`), not a generic
// `?action=` a client can POST arbitrary anonymous events to. Rather than
// block Phase-1 client work on a backend endpoint that doesn't exist yet (or
// bolt one on speculatively, guessing at a shape nobody has reviewed), this
// file defines the SEAM a real network sink will plug into later, and ships
// `IHAnalyticsLogSink` — a genuinely inert default — today. A `for
// consideration` issue tracks the backend endpoint itself.
//
// `IHAnalyticsService` (`IHFeatures`) is the ONLY thing that constructs a
// sink and calls into it, and only ever AFTER confirming
// `IHSettingsStore.analyticsConsentEnabled` — a sink implementation never
// needs its own consent check, mirroring how `CanonicalURL` (this file's
// neighbour) never re-validates its inputs either; the gating is the
// service's job, once, not every sink's.
import Foundation
#if DEBUG
import os
#endif

/// Where a recorded `IHAnalyticsEvent` is forwarded to.
///
/// ELI5: "Send this event... somewhere." What "somewhere" means is up to
/// whichever sink is plugged in.
///
/// DETAILED: `Sendable` so an `any IHAnalyticsSink` can be safely held by
/// the `@MainActor` `IHAnalyticsService` and swapped for a throwaway
/// recording spy in tests (`IHAnalyticsServiceTests`'s "sink seam" cases).
/// Synchronous by design — every conformer today (the log sink) is
/// synchronous, and a future real network sink can still satisfy this
/// protocol by kicking off its own best-effort, fire-and-forget `Task`
/// internally (matching this task's "batched, best-effort, failure-silent"
/// brief) rather than forcing every call site to `await` a network round
/// trip just to record a screen view.
public protocol IHAnalyticsSink: Sendable {
    /// Forward one already consent-approved event. Implementations MUST be
    /// best-effort/failure-silent — a broken analytics pipe must never
    /// surface an error to the user or block the UI action that triggered
    /// the event.
    func record(_ event: IHAnalyticsEvent)
}

/// The default sink until a real first-party ingestion endpoint exists
/// (see this file's header). Genuinely does nothing observable outside the
/// device: in `DEBUG` builds it writes one line to the unified system log
/// (visible only in Xcode/Console.app on the SAME device, never
/// transmitted) purely so a developer can see analytics firing while
/// testing consent-gating; in a Release build it is a true no-op.
///
/// ELI5: The "nowhere, but let a developer peek" version of "send this
/// event somewhere."
public struct IHAnalyticsLogSink: IHAnalyticsSink {
    public init() {}

    public func record(_ event: IHAnalyticsEvent) {
        #if DEBUG
        // `String(describing:)` first — `Logger`'s privacy-annotated string
        // interpolation only has a built-in overload for `String`, not for
        // an arbitrary `[String: String]`.
        let parameterDescription = String(describing: event.parameters)
        Self.logger.debug("[analytics] \(event.name, privacy: .public) \(parameterDescription, privacy: .public)")
        #endif
    }

    #if DEBUG
    private static let logger = Logger(subsystem: "app.ihymns", category: "analytics")
    #endif
}
