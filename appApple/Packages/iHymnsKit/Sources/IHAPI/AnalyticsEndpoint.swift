// AnalyticsEndpoint.swift
// IHAPI
//
// ELI5: The recipe card for "send a little batch of anonymous usage notes
// to the server" — the network shape `?action=analytics_ingest` (#1448)
// expects.
//
// DETAILED: `?action=analytics_ingest` is the backend endpoint #1448 built
// to receive `IHAppSupport.IHAnalyticsEvent`s once the user has opted in
// via Settings → Privacy (`IHAnalyticsService`). This file deliberately
// does NOT import `IHAppSupport` or reference `IHAnalyticsEvent` directly —
// `IHAPI`'s existing layering (`Package.swift`'s header: `IHAPI → IHModels`
// only) has no edge to `IHAppSupport`, and `SongRequestEndpoint.swift`
// already establishes the precedent this file follows: an `Endpoint`
// factory takes PLAIN values (there, six `String`s; here, an array of this
// file's own small `AnalyticsIngestEventPayload`), never a domain type
// owned by a higher module. `IHFeatures.IHAnalyticsNetworkSink` (the one
// caller) is where a queued `IHAnalyticsEvent` + its capture `Date` gets
// translated into this shape.
import Foundation

/// One event queued for the `analytics_ingest` batch POST — an IHAPI-local
/// mirror of `IHAppSupport.IHAnalyticsEvent` plus the `Date` it was
/// captured at (a network sink's own concern, not the event model's — see
/// this file's header for why `IHAnalyticsEvent` itself is never
/// referenced here).
///
/// ELI5: "One usage note, plus exactly when it happened," ready to mail.
public struct AnalyticsIngestEventPayload: Sendable, Equatable {
    /// The allow-listed event name, e.g. `"song_opened"` — validated
    /// server-side against the SAME closed vocabulary
    /// `IHAnalyticsEvent.swift`'s static factories produce
    /// (`includes/analytics_ingest.php`'s `ANALYTICS_INGEST_ALLOWED_EVENT_NAMES`).
    public let name: String

    /// Small, non-identifying context — a flat string map, matching
    /// `IHAnalyticsEvent.parameters`'s own shape exactly.
    public let parameters: [String: String]

    /// When the SINK captured this event (not necessarily when the app
    /// action happened — the two are the same in practice since `record(_:)`
    /// is synchronous, see `IHAnalyticsNetworkSink`).
    public let clientTimestamp: Date

    public init(name: String, parameters: [String: String], clientTimestamp: Date) {
        self.name = name
        self.parameters = parameters
        self.clientTimestamp = clientTimestamp
    }
}

/// The wire shape one event takes inside the `analytics_ingest` JSON body —
/// a private `Encodable` DTO, never constructed anywhere but
/// `Endpoint.analyticsIngest(events:platform:)` below (mirrors
/// `SongRequestEndpoint.swift`'s private `SongRequestBody` precedent).
private struct AnalyticsIngestWireEvent: Encodable {
    let name: String
    let parameters: [String: String]
    let clientTimestamp: String
}

/// The wire shape of the whole POST body — a batch, plus the reporting
/// platform (verified against the LIVE `api.php` `case 'analytics_ingest':`
/// handler, #1448: `{"platform": "...", "events": [...]}`, a bare object,
/// not wrapped in a further envelope key).
private struct AnalyticsIngestBody: Encodable {
    let platform: String
    let events: [AnalyticsIngestWireEvent]
}

extension Endpoint {
    /// `?action=analytics_ingest` — POST a batch of anonymous, consent-
    /// approved analytics events (#1448). NEVER auto-retried by
    /// `APIClient` (`submitAnalyticsEvents(_:platform:)` calls the
    /// non-retrying `performOnce`, the same "don't retry a POST that might
    /// already have landed" rule `submitSongRequest` follows) — a dropped
    /// batch is simply lost, consistent with `IHAnalyticsSink`'s own
    /// "best-effort, failure-silent" contract.
    ///
    /// - Parameters:
    ///   - events: MUST be non-empty — the one caller,
    ///     `IHAnalyticsNetworkSink.flushNow()`, never invokes this with an
    ///     empty queue.
    ///   - platform: The reporting client platform, e.g. `"apple"`. The
    ///     server falls back to `"unknown"` for anything it doesn't
    ///     recognise rather than rejecting the batch.
    /// - Throws: Only if `JSONEncoder` itself somehow fails encoding plain
    ///   `String`/`[String: String]`/ISO-8601-`String` fields (practically
    ///   unreachable, matching `Endpoint.songRequest`'s identical `throws`
    ///   posture).
    public static func analyticsIngest(
        events: [AnalyticsIngestEventPayload],
        platform: String
    ) throws -> Endpoint {
        let formatter = ISO8601DateFormatter()
        let wireEvents = events.map {
            AnalyticsIngestWireEvent(
                name: $0.name,
                parameters: $0.parameters,
                clientTimestamp: formatter.string(from: $0.clientTimestamp)
            )
        }
        let body = try JSONEncoder().encode(AnalyticsIngestBody(platform: platform, events: wireEvents))
        return Endpoint(action: "analytics_ingest", httpMethod: "POST", httpBody: body)
    }
}
