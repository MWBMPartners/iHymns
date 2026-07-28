// SpotlightIndexer.swift
// IHAppSupport
//
// ELI5: The thing that actually talks to the system's search index — tells
// it "here's the current song list" (in batches, so a big catalogue doesn't
// choke Spotlight in one call), and only bothers re-telling it when the
// catalogue has genuinely changed or it's been a while.
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). `SpotlightIndexing` is a
// small protocol seam purely so `SpotlightIndexerTests`/
// `AppRootViewModelSystemIndexTests` can inject a SPY instead of the real
// `CSSearchableIndex` — the same "protocol seam for a system framework"
// shape `IHAuth.TokenStoring`/`IHLive.LANRemote.PairedTVStore` already
// establish elsewhere in this package.
//
// PLATFORM GATE — the ONLY correct one for `CoreSpotlight` in this package
// (verified against Apple's own headers before writing this file):
// `canImport(CoreSpotlight)` is TRUE on tvOS, but `CSSearchableItem`/
// `CSSearchableItemAttributeSet` are marked `__TVOS_PROHIBITED` there — a
// bare `canImport(CoreSpotlight)` gate would therefore still fail to BUILD
// on tvOS; watchOS has no `CoreSpotlight` at all. `#if canImport(CoreSpotlight)
// && !os(tvOS)` is the one gate that's both necessary (excludes watchOS,
// which genuinely can't import the framework) and sufficient (excludes
// tvOS, which imports it but can't use the prohibited types) — see
// `LiveSpotlightIndex`/`SpotlightIndexer.searchableItem(for:)` below, and
// the tvOS cross-compile in this task's own verify list, which exists
// specifically to catch a regression here.
import Foundation
import IHModels
#if canImport(CoreSpotlight) && !os(tvOS)
import CoreSpotlight
import UniformTypeIdentifiers
#endif

/// The system-search-index operations `SpotlightIndexer` needs — real on
/// iOS/macOS/visionOS (`LiveSpotlightIndex`), a no-op everywhere else
/// (`NoOpSpotlightIndex`), or a test spy.
///
/// ELI5: "Delete everything I previously indexed" / "index these new rows."
public protocol SpotlightIndexing: Sendable {
    func indexEntries(_ entries: [SpotlightIndexEntry], inDomain domain: String) async throws
    func deleteDomain(_ domain: String) async throws
}

/// Keeps the system search index in sync with the app's song catalogue —
/// re-indexing only when the content has genuinely changed, or it's been
/// too long since the last check.
///
/// ELI5: "Make sure Spotlight/Siri knows about our current song list,
/// without redoing the work every single time the app launches."
///
/// DETAILED: `@unchecked Sendable` for the identical reason
/// `IHFeatures.RecentlyViewedStore`/`RecentSearchesStore` already are — this
/// type's ONLY mutable-looking dependency is `UserDefaults`, which has
/// always been documented as thread-safe for this read/write usage
/// (https://developer.apple.com/documentation/foundation/userdefaults) but
/// doesn't (yet) carry a compiler-visible `Sendable` conformance; `index`
/// (`any SpotlightIndexing`) is itself constrained `Sendable` by the
/// protocol declaration above.
public struct SpotlightIndexer: @unchecked Sendable {
    /// The one domain every entry this indexer writes is grouped under —
    /// `SpotlightIndexer.deleteDomain(_:)`'s target on every reindex, so a
    /// stale/removed song can never linger in the system index forever.
    public static let domainIdentifier = "app.ihymns.songs"

    /// The most entries indexed in a single `SpotlightIndexing.indexEntries(_:inDomain:)`
    /// call — `CSSearchableIndex`'s own documented guidance is to batch
    /// large indexing operations rather than hand it one enormous array.
    public static let batchSize = 1000

    /// How long a successful index is trusted before this indexer reindexes
    /// even an UNCHANGED catalogue anyway — 14 days. Belt-and-braces against
    /// the system search index silently losing entries (an OS reinstall/
    /// index-rebuild neither this app nor `SpotlightIndexEntry.fingerprint(of:)`
    /// would ever detect from the fingerprint alone).
    static let refreshInterval: TimeInterval = 14 * 86_400

    private static let fingerprintDefaultsKey = "ihymns_spotlight_fingerprint"
    private static let indexedAtDefaultsKey = "ihymns_spotlight_indexed_at"

    private let index: any SpotlightIndexing
    private let defaults: UserDefaults

    /// - Parameters:
    ///   - index: Defaults to the real `LiveSpotlightIndex` on
    ///     iOS/macOS/visionOS, or the inert `NoOpSpotlightIndex` on
    ///     tvOS/watchOS (this file's header explains why those two
    ///     platforms can't use the real one) — tests inject a spy instead.
    ///   - defaults: Where the change-detection fingerprint/date persist;
    ///     `.standard` for the real app, a throwaway suite for tests.
    public init(index: (any SpotlightIndexing)? = nil, defaults: UserDefaults = .standard) {
        #if canImport(CoreSpotlight) && !os(tvOS)
        self.index = index ?? LiveSpotlightIndex()
        #else
        self.index = index ?? NoOpSpotlightIndex()
        #endif
        self.defaults = defaults
    }

    /// Reindexes `summaries` only if the catalogue's content fingerprint has
    /// changed since the last successful index, OR the last successful
    /// index is older than `refreshInterval` — a no-op otherwise.
    ///
    /// ELI5: "Only bother telling Spotlight again if the song list actually
    /// changed, or it's been a couple of weeks."
    public func reindexIfNeeded(_ summaries: [SongSummary]) async {
        let currentFingerprint = SpotlightIndexEntry.fingerprint(of: summaries)
        let storedFingerprint = defaults.string(forKey: Self.fingerprintDefaultsKey)
        let lastIndexedAt = defaults.object(forKey: Self.indexedAtDefaultsKey) as? Date
        let isStale = lastIndexedAt.map { Date().timeIntervalSince($0) > Self.refreshInterval } ?? true

        guard storedFingerprint != currentFingerprint || isStale else { return }
        await reindex(summaries)
    }

    /// Unconditionally deletes every previously indexed entry in
    /// `domainIdentifier`, then re-indexes `summaries` in batches of
    /// `batchSize`, then persists the new fingerprint/date — best-effort
    /// throughout: a `SpotlightIndexing` failure here is swallowed rather
    /// than propagated, matching `IHPersistence.OfflineStore` writes'
    /// existing "never block the primary flow over a background cache
    /// write" posture (`AppRootViewModel+Catalog.swift`'s
    /// `syncSystemIndexes(_:)`, the ONE caller). A thrown error also means
    /// the fingerprint/date are NOT persisted, so the NEXT `reindexIfNeeded(_:)`
    /// call naturally retries rather than believing a failed attempt
    /// succeeded.
    ///
    /// ELI5: "Wipe what Spotlight had, then hand it the whole current list
    /// again, a batch at a time."
    public func reindex(_ summaries: [SongSummary]) async {
        do {
            try await index.deleteDomain(Self.domainIdentifier)
            let entries = SpotlightIndexEntry.entries(from: summaries)
            for start in stride(from: 0, to: entries.count, by: Self.batchSize) {
                let end = min(start + Self.batchSize, entries.count)
                try await index.indexEntries(Array(entries[start..<end]), inDomain: Self.domainIdentifier)
            }
            defaults.set(SpotlightIndexEntry.fingerprint(of: summaries), forKey: Self.fingerprintDefaultsKey)
            defaults.set(Date(), forKey: Self.indexedAtDefaultsKey)
        } catch {
            // Best-effort — see doc comment above.
        }
    }
}

/// The tvOS/watchOS (and test-default-less) fallback — every operation is a
/// silent no-op, matching this package's existing "an unsupported platform
/// degrades to inert, never a crash" posture.
struct NoOpSpotlightIndex: SpotlightIndexing {
    func indexEntries(_ entries: [SpotlightIndexEntry], inDomain domain: String) async throws {}
    func deleteDomain(_ domain: String) async throws {}
}

#if canImport(CoreSpotlight) && !os(tvOS)
/// The real `CSSearchableIndex`-backed implementation — iOS/macOS/visionOS
/// only (this file's header explains the gate). `CSSearchableIndex.default()`
/// is fetched fresh on every call (it's non-`Sendable`) rather than cached
/// as a stored property, matching Apple's own documented usage pattern.
public struct LiveSpotlightIndex: SpotlightIndexing {
    public init() {}

    public func indexEntries(_ entries: [SpotlightIndexEntry], inDomain domain: String) async throws {
        let items = entries.map { SpotlightIndexer.searchableItem(for: $0) }
        try await CSSearchableIndex.default().indexSearchableItems(items)
    }

    public func deleteDomain(_ domain: String) async throws {
        try await CSSearchableIndex.default().deleteSearchableItems(withDomainIdentifiers: [domain])
    }
}

extension SpotlightIndexer {
    /// Builds the real `CSSearchableItem` Spotlight indexes from a portable
    /// `SpotlightIndexEntry` — the ONE place this package constructs a
    /// `CSSearchableItemAttributeSet`.
    public static func searchableItem(for entry: SpotlightIndexEntry) -> CSSearchableItem {
        let attributeSet = CSSearchableItemAttributeSet(contentType: .text)
        attributeSet.title = entry.title
        attributeSet.contentDescription = entry.contentDescription
        attributeSet.keywords = entry.keywords
        return CSSearchableItem(
            uniqueIdentifier: entry.uniqueIdentifier,
            domainIdentifier: domainIdentifier,
            attributeSet: attributeSet
        )
    }

    /// Recovers the tapped song's URL from a Spotlight-result `NSUserActivity`'s
    /// `userInfo` (`CSSearchableItemActivityIdentifier` holds the SAME
    /// `uniqueIdentifier` string `searchableItem(for:)` set) — the app
    /// shell's `.onContinueUserActivity(CSSearchableItemActionType)`
    /// (`IHymnsApp.swift`, C4) feeds this straight into the EXACT SAME
    /// `handle(url:)`/`DeepLinkRouter.resolve(_:)` a tapped Universal Link
    /// already uses, so a Spotlight tap and a Universal Link tap can never
    /// behave differently.
    ///
    /// ELI5: "Someone tapped a Spotlight search result — which song URL was
    /// that?"
    public static func tapURL(fromActivityUserInfo userInfo: [AnyHashable: Any]?) -> URL? {
        guard let identifier = userInfo?[CSSearchableItemActivityIdentifier] as? String else { return nil }
        return URL(string: identifier)
    }
}
#endif
