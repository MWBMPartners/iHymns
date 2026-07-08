// IHAnalyticsServiceTests.swift
// IHFeaturesTests
//
// ELI5: Proves the analytics service actually keeps its promise — with
// "Share Anonymous Usage Data" off, NOTHING is queued or forwarded,
// no matter how many events the app tries to record; with it on, events
// really do reach the sink.
//
// DETAILED: Covers #189's three explicitly-required test areas: the
// consent gate (OFF → zero recorded/sent, ON → recorded), and the sink
// seam (a swappable `IHAnalyticsSink` — this file's `RecordingSink` spy —
// is what a real network sink will plug into later). Event-shape/no-PII
// coverage lives in `IHAppSupportTests/IHAnalyticsEventTests.swift`
// alongside the type it tests. `@MainActor` because `IHAnalyticsService`
// is main-actor-isolated, mirroring `SettingsViewModelTests`'s own suite.
import Foundation
import Testing
import IHAppSupport
@testable import IHFeatures

@MainActor
@Suite("IHAnalyticsService")
struct IHAnalyticsServiceTests {

    /// A plain, non-actor-isolated recording spy — `@unchecked Sendable`
    /// for the SAME documented reason `IHSettingsStore` itself already
    /// uses that escape hatch (this file's tests only ever touch it from
    /// the one `@MainActor` test body that constructs it, never
    /// concurrently), so `IHAnalyticsService` can hold it behind the
    /// `any IHAnalyticsSink` existential exactly like a real sink.
    private final class RecordingSink: IHAnalyticsSink, @unchecked Sendable {
        private(set) var recorded: [IHAnalyticsEvent] = []
        func record(_ event: IHAnalyticsEvent) {
            recorded.append(event)
        }
    }

    /// A settings store on a throwaway suite, with consent pre-set —
    /// mirrors `SettingsViewModelTests.makeStore()`'s own isolation
    /// pattern so no test run pollutes `.standard` or another test.
    private func makeSettingsStore(consentEnabled: Bool) -> IHSettingsStore {
        let defaults = UserDefaults(suiteName: "ihtest.analytics.\(UUID().uuidString)")!
        let store = IHSettingsStore(defaults: defaults)
        store.analyticsConsentEnabled = consentEnabled
        return store
    }

    @Test("Consent OFF: record(_:) is a verified no-op — nothing queued, nothing sent")
    func consentOffIsANoOp() {
        let sink = RecordingSink()
        let service = IHAnalyticsService(settingsStore: makeSettingsStore(consentEnabled: false), sink: sink)

        service.screenViewed("Home")
        service.songOpened(songId: "MP-1008")
        service.searchPerformed(resultCount: 3)
        service.offlineSaveToggled(songId: "MP-1008")

        #expect(service.recordedEvents.isEmpty)
        #expect(sink.recorded.isEmpty)
    }

    @Test("Consent ON: events are queued AND forwarded to the sink")
    func consentOnRecordsAndForwards() {
        let sink = RecordingSink()
        let service = IHAnalyticsService(settingsStore: makeSettingsStore(consentEnabled: true), sink: sink)

        service.screenViewed("Home")
        service.songOpened(songId: "MP-1008")

        #expect(service.recordedEvents == [.screenView("Home"), .songOpened(songId: "MP-1008")])
        #expect(sink.recorded == service.recordedEvents)
    }

    @Test("Flipping consent mid-session takes effect on the very next call — no caching at init")
    func consentReadFreshEveryCall() {
        let defaults = UserDefaults(suiteName: "ihtest.analytics.\(UUID().uuidString)")!
        let store = IHSettingsStore(defaults: defaults)
        store.analyticsConsentEnabled = false
        let sink = RecordingSink()
        let service = IHAnalyticsService(settingsStore: store, sink: sink)

        service.screenViewed("Home")
        #expect(sink.recorded.isEmpty)

        store.analyticsConsentEnabled = true
        service.screenViewed("Search")
        #expect(sink.recorded == [.screenView("Search")])
    }

    @Test("The sink seam: swapping the sink changes only WHERE events go, not whether they're recorded")
    func sinkSeamIsSwappable() {
        let firstSink = RecordingSink()
        let secondSink = RecordingSink()
        let store = makeSettingsStore(consentEnabled: true)

        IHAnalyticsService(settingsStore: store, sink: firstSink).songOpened(songId: "MP-1008")
        IHAnalyticsService(settingsStore: store, sink: secondSink).songOpened(songId: "SDA-2")

        #expect(firstSink.recorded == [.songOpened(songId: "MP-1008")])
        #expect(secondSink.recorded == [.songOpened(songId: "SDA-2")])
    }

    @Test("The default sink (no explicit sink argument) never throws or crashes")
    func defaultSinkIsSafeToUse() {
        // Exercises the real `IHAnalyticsLogSink` default end-to-end — the
        // only thing under test is that recording through it completes
        // normally and still respects consent.
        let service = IHAnalyticsService(settingsStore: makeSettingsStore(consentEnabled: true))
        service.screenViewed("Home")
        #expect(service.recordedEvents == [.screenView("Home")])
    }
}
