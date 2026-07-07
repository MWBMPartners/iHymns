// SheetMusicViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the sheet-music fetcher (`SheetMusicViewModel`, #184) walks
// idle→loading→loaded/error correctly, only fetches once per url unless
// forced, and turns network failures into an honest message — all against
// an injected fake fetcher closure, never a real network call.
//
// DETAILED: `SheetMusicViewModel.init(fetcher:)` requires an `@Sendable`
// closure (it's stored on a `@MainActor` class and invoked from inside an
// `async` context) — a call-counting test needs its counter to be safe to
// mutate from that same closure, so this file reuses `IHAPITestSupport
// .LockedCounter` (the exact "manually-synchronized counter safe to capture
// in a `@Sendable` mock... handler" `Package.swift`'s own doc comment
// describes it for) rather than a plain captured `var`, which Swift 6
// strict concurrency correctly rejects for an `@Sendable` closure capture.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHFeatures

@MainActor
@Suite("SheetMusicViewModel (#184)")
struct SheetMusicViewModelTests {

    private static let url = URL(string: "https://dev.ihymns.app/song-media/2")!

    @Test("Starts idle")
    func startsIdle() {
        let viewModel = SheetMusicViewModel(fetcher: { _ in Data() })
        #expect(viewModel.state == .idle)
    }

    @Test("A successful fetch reaches .loaded with the returned bytes")
    func successfulFetchLoads() async {
        let bytes = Data([0x25, 0x50, 0x44, 0x46]) // "%PDF"
        let viewModel = SheetMusicViewModel(fetcher: { _ in bytes })
        await viewModel.load(url: Self.url)
        #expect(viewModel.state == .loaded(bytes))
    }

    @Test("loadIfNeeded() only fetches once — a second call while already loaded is a no-op")
    func loadIfNeededFetchesOnlyOnce() async {
        let counter = LockedCounter()
        let viewModel = SheetMusicViewModel(fetcher: { _ in
            counter.increment()
            return Data([1, 2, 3])
        })
        await viewModel.loadIfNeeded(url: Self.url)
        await viewModel.loadIfNeeded(url: Self.url)
        #expect(counter.current == 1)
        #expect(viewModel.state == .loaded(Data([1, 2, 3])))
    }

    @Test("An empty response is treated as an error, not a valid (empty) file")
    func emptyResponseIsAnError() async {
        let viewModel = SheetMusicViewModel(fetcher: { _ in Data() })
        await viewModel.load(url: Self.url)
        #expect(viewModel.state == .error("The sheet music file was empty. Please try again."))
    }

    @Test("An offline-shaped failure maps to the offline message")
    func offlineFailureMapsToOfflineMessage() async {
        let viewModel = SheetMusicViewModel(fetcher: { _ in throw URLError(.notConnectedToInternet) })
        await viewModel.load(url: Self.url)
        #expect(viewModel.state == .error("You're offline. Check your connection and try again."))
    }

    @Test("Any other failure maps to a generic message")
    func genericFailureMapsToGenericMessage() async {
        let viewModel = SheetMusicViewModel(fetcher: { _ in throw URLError(.badServerResponse) })
        await viewModel.load(url: Self.url)
        #expect(viewModel.state == .error("Couldn't load the sheet music. Please try again."))
    }

    @Test("load() forces a re-fetch even when something is already loaded")
    func loadForcesRefetch() async {
        let counter = LockedCounter()
        let viewModel = SheetMusicViewModel(fetcher: { _ in
            Data([UInt8(counter.increment())])
        })
        await viewModel.load(url: Self.url)
        await viewModel.load(url: Self.url)
        #expect(counter.current == 2)
        #expect(viewModel.state == .loaded(Data([2])))
    }
}
