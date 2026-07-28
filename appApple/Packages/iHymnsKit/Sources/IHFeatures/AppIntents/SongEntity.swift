// SongEntity.swift
// IHFeatures/AppIntents
//
// ELI5: The "this is a song" shape Siri/Shortcuts understands — enough to
// show a picker row (title + which songbook) and enough to look one back up
// later from just its id.
//
// DETAILED: #1415. `AppEntity` (Apple's App Intents parameter type,
// https://developer.apple.com/documentation/appintents/appentity).
// Content-gating-safe (`.claude/CLAUDE.md`): carries `SongID`/title/songbook
// label ONLY, never lyric text. `id` is `SongID.rawValue` (a plain
// `String`, matching `AppEntity`'s `ID: Hashable & Sendable` requirement)
// rather than `SongID` itself — `SongID` has no `AppEntity`-required
// lossless-string-conversion story of its own, and re-parsing via
// `SongID(rawValue:)` at the query layer (`SongEntityResolver`) is exactly
// as safe since every `SongEntity` was built FROM an already-valid `SongID`
// in the first place. See this folder's `AppIntentSupport.swift` header for
// why the whole folder (this file included) is platform-gated.
#if os(iOS) || os(macOS) || os(visionOS)
import AppIntents
import IHModels

public struct SongEntity: AppEntity {
    public static let typeDisplayRepresentation: TypeDisplayRepresentation = "Song"
    /// `let`, not `var` — `SongEntityQuery` has no mutable state of its own
    /// (its ONE stored property is a `@Dependency`, itself an indirection to
    /// the shared `AppRootViewModel`), so a single shared, immutable
    /// instance is both correct and what Swift 6 strict concurrency requires
    /// for a `nonisolated static` property of a `Sendable` type.
    public static let defaultQuery = SongEntityQuery()

    public let id: String
    public let title: String
    public let subtitle: String

    public var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "\(title)", subtitle: "\(subtitle)")
    }

    /// Builds an entity from a catalogue row — `SongEntityResolver`'s
    /// `resolve(_:in:)`/`match(_:in:)` both funnel through this.
    public init(summary: SongSummary) {
        self.id = summary.songId.rawValue
        self.title = summary.title
        self.subtitle = summary.songbookName.isEmpty ? summary.songbookAbbreviation : summary.songbookName
    }

    /// Builds an entity from a "songs you've actually opened" row —
    /// `SongEntityQuery.suggestedEntities()`'s data source (`IHFeatures
    /// .RecentlyViewedSong`, same module — no extra import needed).
    public init(recentlyViewed: RecentlyViewedSong) {
        self.id = recentlyViewed.songId.rawValue
        self.title = recentlyViewed.title
        self.subtitle = recentlyViewed.songbookAbbreviation
    }
}
#endif
