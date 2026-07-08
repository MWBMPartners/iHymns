// APIClient+Analytics.swift
// IHAPI
//
// ELI5: The "mail this little batch of anonymous usage notes" phone call —
// layered on the SAME `APIClient` actor every other read/write already
// uses, just split into its own file (mirrors `APIClient+SongRequest.swift`'s
// same "genuinely different concern, own file" precedent).
//
// DETAILED: #1448. `submitAnalyticsEvents(_:platform:)` is a non-idempotent
// POST — routed through the non-retrying `performOnce`, never
// `performIdempotentGET`, for the same reason `submitSongRequest`/
// `authLogin` are: retrying a POST that may have already landed risks the
// server recording the batch twice. The caller (`IHFeatures
// .IHAnalyticsNetworkSink`) already treats ANY thrown `APIError` here as
// "silently drop this batch" per `IHAnalyticsSink`'s own best-effort
// contract, so this method deliberately does NOT try to decode a typed
// response body — the batch either reached the server (2xx) or it didn't;
// the `accepted`/`rejected` counts the endpoint returns are diagnostic only
// and not needed by any caller today.
import Foundation

extension APIClient {
    /// `?action=analytics_ingest` — POST a batch of anonymous analytics
    /// events (#1448). NEVER auto-retried (see this file's header).
    ///
    /// ELI5: "Here's a little batch of anonymous usage notes."
    ///
    /// - Returns: The raw response body, discardable — see this file's
    ///   header for why no typed decode happens here.
    @discardableResult
    public func submitAnalyticsEvents(
        _ events: [AnalyticsIngestEventPayload],
        platform: String
    ) async throws -> Data {
        let endpoint = try Endpoint.analyticsIngest(events: events, platform: platform)
        return try await performOnce(endpoint)
    }
}
