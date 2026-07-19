// SongEntityQuery.swift
// IHFeatures/AppIntents
//
// ELI5: How Siri/Shortcuts turns "the song the user picked/typed/said" into
// a real `SongEntity` — by id, by search text, or (for the picker's default
// suggestions) "songs you've recently read."
//
// DETAILED: #1415. `EntityStringQuery` (Apple's App Intents protocol for a
// text-searchable entity picker) — `@Dependency` pulls the ONE shared
// `AppRootViewModel` `AppDependencyManager.shared.add(dependency:)`
// registered at launch (`Apps/iHymns/Sources/IHymnsApp.swift`, C4); the
// actual matching/resolving logic is the PURE `SongEntityResolver` enum
// below, kept SEPARATE from this type so it's unit-testable
// (`SongEntityResolverTests`) without ever touching `AppDependencyManager`
// — this folder's own house rule, see `AppIntentSupport.swift`'s header.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents
import IHModels

public struct SongEntityQuery: EntityStringQuery {
    @Dependency private var rootViewModel: AppRootViewModel

    public init() {}

    public func entities(for identifiers: [String]) async throws -> [SongEntity] {
        SongEntityResolver.resolve(identifiers, in: await rootViewModel.songSummariesForIntents())
    }

    public func entities(matching string: String) async throws -> [SongEntity] {
        SongEntityResolver.match(string, in: await rootViewModel.songSummariesForIntents())
    }

    /// The picker's default rows before the user types anything — "songs
    /// you've actually opened lately," most-recent-first, capped at 10 (a
    /// screenful) — `AppRootViewModel.recentlyViewedSongs` is ALREADY
    /// most-recent-first (`RecentlyViewedStore.record(_:)`'s own doc
    /// comment), so no extra sort is needed here.
    public func suggestedEntities() async throws -> [SongEntity] {
        await rootViewModel.recentlyViewedSongs.prefix(10).map(SongEntity.init(recentlyViewed:))
    }
}

/// The PURE resolve/match core `SongEntityQuery` delegates to — see this
/// folder's `AppIntentSupport.swift` header for why keeping it separate
/// from the `@Dependency`-holding query type matters for testability.
enum SongEntityResolver {
    /// Caps `match(_:in:limit:)`'s results — Shortcuts/Siri's own picker UI
    /// has no use for more than a screenful of candidates at once.
    static let matchLimit = 20

    /// Resolves a batch of `SongEntity.id`s (`SongID.rawValue`s) back into
    /// `SongEntity` values, preserving `identifiers`' order and silently
    /// dropping any id that doesn't match a song in `songs` — mirrors
    /// `IHPersistence.CachedSongSummary.toSongSummary()`'s "one bad row
    /// never takes down the read" defensive posture.
    static func resolve(_ identifiers: [String], in songs: [SongSummary]) -> [SongEntity] {
        let bySongId = Dictionary(uniqueKeysWithValues: songs.map { ($0.songId.rawValue, $0) })
        return identifiers.compactMap { bySongId[$0].map(SongEntity.init(summary:)) }
    }

    /// Delegates the ACTUAL matching to `AppRootViewModel.songsMatching(_:in:)`
    /// — the one search matcher this package has (this repo's modularity
    /// rule, `.claude/CLAUDE.md`: never re-fork a shared matcher) — capped
    /// to `limit`.
    static func match(_ query: String, in songs: [SongSummary], limit: Int = matchLimit) -> [SongEntity] {
        Array(AppRootViewModel.songsMatching(query, in: songs).prefix(limit)).map(SongEntity.init(summary:))
    }
}
#endif
