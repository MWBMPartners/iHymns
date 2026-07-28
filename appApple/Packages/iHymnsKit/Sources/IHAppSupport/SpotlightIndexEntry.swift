// SpotlightIndexEntry.swift
// IHAppSupport
//
// ELI5: The plain, portable "one row of the system search index" shape for
// a song — a title, a description, and a few search keywords — built
// straight from a `SongSummary` with NO `CoreSpotlight` import at all, so
// this exact same code can run on watchOS (which has no `CoreSpotlight`
// framework) and be unit-tested without ever touching the real system
// search index.
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). Split from the actual
// `CSSearchableItem`-building code (`SpotlightIndexer.swift`, same target)
// for the exact reason the strategy calls out: `CSSearchableItem`/
// `CSSearchableItemAttributeSet` are `__TVOS_PROHIBITED` even though
// `canImport(CoreSpotlight)` is TRUE on tvOS, and watchOS has no
// `CoreSpotlight` at all — a PURE, `CoreSpotlight`-free intermediate type
// lets `SpotlightIndexEntry.entries(from:)`/`.fingerprint(of:)` compile and
// run identically on every platform this package targets, with only the
// thin `SpotlightIndexer.searchableItem(for:)` translation step (the OTHER
// file) needing the platform gate.
//
// Content-gating-safe (`.claude/CLAUDE.md`): every field here is a
// `SongID`/title/songbook label — NEVER lyric text — matching the same
// "SongID/codes/titles ONLY" posture this task's own instructions require
// for every App Intent/entity/Spotlight surface.
import CryptoKit
import Foundation
import IHModels

/// One song, shaped for the system search index — everything Spotlight (or
/// a Siri/Shortcuts entity picker) needs to show and match a result, and
/// nothing more.
///
/// ELI5: "Here's a song, ready to hand to Spotlight."
public struct SpotlightIndexEntry: Sendable, Equatable {
    /// The stable id Spotlight uses to track/replace/delete this specific
    /// item across reindexes — the song's own canonical web URL
    /// (`CanonicalURL.song(_:)`), so a tap on the search result round-trips
    /// straight back through `DeepLinkRouter.resolve(_:)` into the SAME
    /// `.song` destination a Universal Link tap would (`SpotlightIndexerTests`
    /// proves this round trip).
    public let uniqueIdentifier: String

    /// The result's headline — the song's title, unmodified.
    public let title: String

    /// The result's secondary line — the owning songbook's display name
    /// (falling back to its abbreviation if the name is unset), matching
    /// `SongEntity.subtitle`'s (`IHFeatures/AppIntents`) identical choice:
    /// deliberately NUMBER-LESS — the song's number lives in `keywords`
    /// below instead, where a number-only search ("1008") can still match
    /// it, without cluttering the description Spotlight actually displays.
    public let contentDescription: String

    /// Extra search terms Spotlight indexes against but never displays:
    /// title, display number, songbook abbreviation, songbook name — any
    /// that are empty (e.g. `displayNumber` for a numberless song) are
    /// dropped rather than indexed as a blank string.
    public let keywords: [String]

    /// Builds an entry from a live `SongSummary` — `nil` only if
    /// `CanonicalURL.song(_:)` itself fails (practically unreachable: every
    /// `SongSummary.songId` is already a validated `SongID`, and
    /// `CanonicalURL.song(_:)` only returns `nil` on a malformed `URL`
    /// string, which a `<letters>-<digits>` id can never produce) — kept
    /// failable anyway rather than force-unwrapping, matching this
    /// package's existing "defensive, `nil` on failure" convention for
    /// `CanonicalURL`-built values (`CanonicalURL.swift`'s own header).
    public init?(summary: SongSummary) {
        guard let url = CanonicalURL.song(summary.songId) else { return nil }
        self.uniqueIdentifier = url.absoluteString
        self.title = summary.title
        self.contentDescription = summary.songbookName.isEmpty ? summary.songbookAbbreviation : summary.songbookName
        self.keywords = [summary.title, summary.displayNumber, summary.songbookAbbreviation, summary.songbookName]
            .filter { !$0.isEmpty }
    }

    /// Builds one entry per summary, silently dropping any that fail to
    /// build (see `init?(summary:)`'s doc comment for why that's
    /// practically unreachable, not a real data-loss risk).
    ///
    /// ELI5: "Turn this whole song list into Spotlight-ready rows."
    public static func entries(from summaries: [SongSummary]) -> [SpotlightIndexEntry] {
        summaries.compactMap { SpotlightIndexEntry(summary: $0) }
    }

    /// A deterministic digest of `summaries`' content — `SpotlightIndexer
    /// .reindexIfNeeded(_:)`'s change-detection key: two calls with the
    /// SAME song content (regardless of array order) always produce the
    /// SAME fingerprint, so an unchanged catalogue never triggers a needless
    /// reindex.
    ///
    /// ELI5: "A short fingerprint of exactly what's in this song list right
    /// now — changes the instant anything in it does."
    ///
    /// DETAILED: Sorted BY `songId` first (never trusting `summaries`'
    /// incoming order — the network's own row order isn't guaranteed
    /// stable) into `"id|title|songbookName|number\n"` lines, then hashed
    /// with CryptoKit's `SHA256` — the SAME first-party, no-external-
    /// dependency choice `IHLog/IHLogSanitize.swift`'s `tokenFingerprint(_:)`
    /// already makes, and for the identical reason `Hasher`/`hashValue`
    /// would be wrong here: Swift's `Hasher` is explicitly documented to use
    /// a PER-PROCESS random seed
    /// (https://developer.apple.com/documentation/swift/hasher), so the
    /// exact same catalogue would hash differently every single app launch
    /// — useless as a "did this change since last time we persisted a
    /// fingerprint" comparison key.
    public static func fingerprint(of summaries: [SongSummary]) -> String {
        let sorted = summaries.sorted { $0.songId.rawValue < $1.songId.rawValue }
        let lines = sorted.map { "\($0.songId.rawValue)|\($0.title)|\($0.songbookName)|\($0.displayNumber)\n" }
        let digest = SHA256.hash(data: Data(lines.joined().utf8))
        return digest.map { String(format: "%02x", $0) }.joined()
    }
}
