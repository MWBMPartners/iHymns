// SpotlightIndexerTests.swift
// IHAppSupportTests
//
// ELI5: Checks the "keep Spotlight in sync" bookkeeping — the first sync
// deletes-then-batches, an unchanged catalogue does nothing on the next
// call, a genuinely changed catalogue (or a stale injected date) DOES
// reindex, a Spotlight tap resolves back to the same song a Universal Link
// would, and the real `CSSearchableItem` this builds actually carries the
// entry's fields — all against a SPY index, never the developer machine's
// real Spotlight database.
//
// #1415 (App Intents / Siri / Shortcuts / Spotlight).
import Foundation
import Testing
@testable import IHAppSupport
@testable import IHModels
#if canImport(CoreSpotlight) && !os(tvOS)
import CoreSpotlight
#endif

@Suite("SpotlightIndexer (#1415)")
struct SpotlightIndexerTests {

    /// Records every call instead of touching the real system search index.
    private actor SpySpotlightIndex: SpotlightIndexing {
        private(set) var deletedDomains: [String] = []
        private(set) var indexedBatches: [[SpotlightIndexEntry]] = []

        func indexEntries(_ entries: [SpotlightIndexEntry], inDomain domain: String) async throws {
            indexedBatches.append(entries)
        }

        func deleteDomain(_ domain: String) async throws {
            deletedDomains.append(domain)
        }
    }

    private func makeDefaults() -> UserDefaults {
        let suite = "ihtest.spotlight.\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        return UserDefaults(suiteName: suite)!
    }

    private func makeSummaries(count: Int) -> [SongSummary] {
        (1...count).map { number in
            SongSummary(
                songId: SongID(songbookAbbreviation: "MP", number: number),
                title: "Song \(number)",
                songbookAbbreviation: "MP",
                number: number,
                songbookName: "Mission Praise"
            )
        }
    }

    @Test("The first reindexIfNeeded call deletes the domain, then indexes in batches of at most 1000")
    func firstCallDeletesAndBatches() async {
        let spy = SpySpotlightIndex()
        let indexer = SpotlightIndexer(index: spy, defaults: makeDefaults())

        await indexer.reindexIfNeeded(makeSummaries(count: 2500))

        #expect(await spy.deletedDomains.count == 1)
        let batches = await spy.indexedBatches
        #expect(batches.count == 3)
        #expect(batches.map(\.count) == [1000, 1000, 500])
    }

    @Test("An unchanged corpus triggers no further reindex on a second call")
    func unchangedCorpusIsNoOp() async {
        let spy = SpySpotlightIndex()
        let indexer = SpotlightIndexer(index: spy, defaults: makeDefaults())
        let summaries = makeSummaries(count: 5)

        await indexer.reindexIfNeeded(summaries)
        await indexer.reindexIfNeeded(summaries)

        #expect(await spy.deletedDomains.count == 1)
    }

    @Test("A genuinely changed corpus triggers a second reindex")
    func changedCorpusReindexes() async {
        let spy = SpySpotlightIndex()
        let indexer = SpotlightIndexer(index: spy, defaults: makeDefaults())

        await indexer.reindexIfNeeded(makeSummaries(count: 5))
        await indexer.reindexIfNeeded(makeSummaries(count: 6))

        #expect(await spy.deletedDomains.count == 2)
    }

    @Test("A stale injected indexed-at date triggers a reindex even for an unchanged corpus")
    func staleDateReindexesUnchangedCorpus() async {
        let spy = SpySpotlightIndex()
        let defaults = makeDefaults()
        let summaries = makeSummaries(count: 5)
        // Seed the SAME fingerprint `reindexIfNeeded` would compute, but an
        // indexed-at date well past the 14-day `refreshInterval`.
        defaults.set(SpotlightIndexEntry.fingerprint(of: summaries), forKey: "ihymns_spotlight_fingerprint")
        defaults.set(Date(timeIntervalSinceNow: -15 * 86_400), forKey: "ihymns_spotlight_indexed_at")

        let indexer = SpotlightIndexer(index: spy, defaults: defaults)
        await indexer.reindexIfNeeded(summaries)

        #expect(await spy.deletedDomains.count == 1)
    }

    @Test("A fresh indexed-at date does NOT trigger a reindex for an unchanged corpus")
    func freshDateSkipsUnchangedCorpus() async {
        let spy = SpySpotlightIndex()
        let defaults = makeDefaults()
        let summaries = makeSummaries(count: 5)
        defaults.set(SpotlightIndexEntry.fingerprint(of: summaries), forKey: "ihymns_spotlight_fingerprint")
        defaults.set(Date(), forKey: "ihymns_spotlight_indexed_at")

        let indexer = SpotlightIndexer(index: spy, defaults: defaults)
        await indexer.reindexIfNeeded(summaries)

        #expect(await spy.deletedDomains.isEmpty)
    }

    // MARK: - CoreSpotlight-gated (runs on macOS, where the framework is
    // fully available — see `SpotlightIndexer.swift`'s header for why the
    // gate excludes only tvOS/watchOS, not this test host).

    @Test("tapURL round-trips a Spotlight tap through DeepLinkRouter into the same song")
    func tapURLRoundTrips() throws {
        #if canImport(CoreSpotlight) && !os(tvOS)
        let userInfo: [AnyHashable: Any] = [CSSearchableItemActivityIdentifier: "https://ihymns.app/song/MP-1008"]
        let url = try #require(SpotlightIndexer.tapURL(fromActivityUserInfo: userInfo))
        let songId = try #require(SongID(rawValue: "MP-1008"))
        #expect(DeepLinkRouter.resolve(url) == .song(songId))
        #endif
    }

    @Test("tapURL returns nil when the activity carries no identifier")
    func tapURLReturnsNilForMissingIdentifier() {
        #if canImport(CoreSpotlight) && !os(tvOS)
        #expect(SpotlightIndexer.tapURL(fromActivityUserInfo: [:]) == nil)
        #expect(SpotlightIndexer.tapURL(fromActivityUserInfo: nil) == nil)
        #endif
    }

    @Test("searchableItem's attribute set carries the entry's title/description/keywords")
    func searchableItemAttributeSetFields() throws {
        #if canImport(CoreSpotlight) && !os(tvOS)
        let summary = makeSummaries(count: 1)[0]
        let entry = try #require(SpotlightIndexEntry(summary: summary))
        let item = SpotlightIndexer.searchableItem(for: entry)

        #expect(item.uniqueIdentifier == entry.uniqueIdentifier)
        #expect(item.domainIdentifier == SpotlightIndexer.domainIdentifier)
        #expect(item.attributeSet.title == entry.title)
        #expect(item.attributeSet.contentDescription == entry.contentDescription)
        #expect(item.attributeSet.keywords == entry.keywords)
        #endif
    }
}
