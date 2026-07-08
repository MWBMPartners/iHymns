// IHAnalyticsNetworkSink.swift
// IHFeatures
//
// ELI5: The REAL "somewhere" `IHAnalyticsSink.swift`'s header talked about —
// once the user says yes to "Share Anonymous Usage Data," this is what
// actually mails a small batch of those anonymous notes to iHymns' own
// server every so often, and quietly gives up if that fails (nobody's
// singing along gets interrupted by a broken analytics pipe).
//
// DETAILED: #1448 — the backend survey in `IHAnalyticsSink.swift`'s own
// header found no ingestion endpoint; #1448 built one
// (`?action=analytics_ingest`, `includes/analytics_ingest.php`). This type
// is the client half that completes the loop: an `actor` (not a plain
// class) so its queue can be safely appended to from `record(_:)`'s
// `nonisolated` entry point, which every one of `IHAnalyticsService`'s 7
// call sites hits synchronously and cannot itself be async (the protocol's
// `record(_:)` requirement is sync/non-throwing — see `IHAnalyticsSink`'s
// own doc comment: "a future real network sink can still satisfy this
// protocol by kicking off its own best-effort, fire-and-forget `Task`
// internally," which is exactly what `record(_:)` below does).
//
// BATCHING: events accumulate in `queue` until `batchSize` is reached, at
// which point `enqueue(_:)` awaits `flushNow()` directly (no nested
// `Task` — see `enqueue(_:)`'s own doc comment for why that matters for
// tests). A caller that wants a smaller pending queue flushed sooner (e.g.
// the app entering the background) can call the `public` `flushNow()`
// directly; no such call site exists yet — DEFERRED, not wired into
// `RootContainerView`'s scene-phase today, to keep this task's scope to
// exactly what #1448 asked for (a working batched network sink), not a
// speculative app-lifecycle integration nobody requested.
//
// CONSENT RE-CHECKED AT FLUSH, NOT JUST AT QUEUE TIME: `IHAnalyticsService
// .record(_:)` already gates BEFORE ever calling `sink.record(_:)` — a
// sink "never needs its own consent check" per that file's header. This
// type deliberately adds ONE anyway, specifically at `flushNow()`, because
// batching introduces a real time gap between "event queued" and "batch
// actually sent": a user who queues a few events and THEN revokes consent
// (Settings → Privacy → off) before the batch reaches `batchSize` must
// never have that already-queued batch escape the device. Re-reading
// `settingsStore.analyticsConsentEnabled` fresh at flush time (never
// cached) is the same "state that matters lives in UserDefaults, read
// fresh" convention `IHAnalyticsService`'s own header documents.
import Foundation
import IHAPI
import IHAppSupport

/// The production `IHAnalyticsSink` — batches consent-approved events in
/// memory and best-effort POSTs them to `?action=analytics_ingest` (#1448).
/// Failure-silent per `IHAnalyticsSink.record(_:)`'s own contract: a thrown
/// `APIError` is swallowed in `flushNow()`, never surfaced.
///
/// ELI5: The real mailbox for anonymous usage notes.
public actor IHAnalyticsNetworkSink: IHAnalyticsSink {
    private let apiClient: APIClient
    private let settingsStore: IHSettingsStore
    private let platform: String
    private let batchSize: Int
    private var queue: [(event: IHAnalyticsEvent, capturedAt: Date)] = []

    /// - Parameters:
    ///   - session: The transport; defaults to `.shared`. Tests inject
    ///     `MockURLProtocol.makeSession()` (the same seam `APIClient`
    ///     itself already exposes for this exact purpose).
    ///   - settingsStore: Where `analyticsConsentEnabled` AND
    ///     `apiEnvironmentOverride` are read from — defaults to the real
    ///     `IHSettingsStore()`. The environment is resolved ONCE here (at
    ///     construction), mirroring `IHymnsApp.swift`'s own one-time
    ///     `apiEnvironmentOverride ?? .defaultForBuild` read — see that
    ///     property's own doc comment for why an override "takes effect on
    ///     next launch, not live."
    ///   - platform: The reporting-client label the endpoint's `platform`
    ///     field carries; defaults to `"apple"`.
    ///   - batchSize: Events accumulated before an automatic flush.
    public init(
        session: URLSession = .shared,
        settingsStore: IHSettingsStore = IHSettingsStore(),
        platform: String = "apple",
        batchSize: Int = 20
    ) {
        self.apiClient = APIClient(
            environment: settingsStore.apiEnvironmentOverride ?? .defaultForBuild,
            session: session
        )
        self.settingsStore = settingsStore
        self.platform = platform
        self.batchSize = batchSize
    }

    /// The `IHAnalyticsSink` protocol's synchronous entry point — queues
    /// `event` via a detached `Task` (see this file's header for why
    /// `record(_:)` itself can't be `async`).
    nonisolated public func record(_ event: IHAnalyticsEvent) {
        Task { await self.enqueue(event) }
    }

    /// Queues one event, auto-flushing once `batchSize` is reached.
    ///
    /// DETAILED: `internal` (module-visible), not `private` — mirrors
    /// `APIClient.performOnce`'s identical "another file/the test suite
    /// needs to call this directly" access-level relaxation. Tests call
    /// THIS (awaited, deterministic) rather than `record(_:)` (which
    /// dispatches through an untracked `Task`, racy to assert against)
    /// to prove the count-triggered auto-flush actually fires.
    func enqueue(_ event: IHAnalyticsEvent) async {
        queue.append((event: event, capturedAt: Date()))
        if queue.count >= batchSize {
            await flushNow()
        }
    }

    /// Sends whatever is currently queued, if the user is STILL opted in —
    /// see this file's header for why consent is re-checked here rather
    /// than trusted from queue time. Always drains `queue` (even when
    /// consent is off), so a revoked-consent batch is discarded, not
    /// retried forever.
    ///
    /// - Returns: `true` only if a non-empty, consent-approved batch was
    ///   sent successfully — purely informational for tests/callers; never
    ///   used to decide whether to retry (this sink never retries, per
    ///   `IHAnalyticsSink`'s "best-effort, failure-silent" contract).
    @discardableResult
    public func flushNow() async -> Bool {
        guard !queue.isEmpty else { return false }
        let pending = queue
        queue.removeAll()

        guard settingsStore.analyticsConsentEnabled else { return false }

        let payloads = pending.map {
            AnalyticsIngestEventPayload(name: $0.event.name, parameters: $0.event.parameters, clientTimestamp: $0.capturedAt)
        }
        do {
            try await apiClient.submitAnalyticsEvents(payloads, platform: platform)
            return true
        } catch {
            return false   // failure-silent — see this file's header
        }
    }
}
