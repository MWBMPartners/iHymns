// CatalogueNumberQuery.swift
// IHFeatures
//
// ELI5: Looks at what you typed in the search box and decides "is this
// person asking for a SONG NUMBER (like '1008', or 'MP 1008' if they also
// typed which book), or just typing a normal title search?"
//
// DETAILED: #1436's "by-number mode" — `CatalogueListView`'s search field
// stays the single `.searchable(text:)` it already had (#1399); this type
// is the parsing step `AppRootViewModel.filteredSongs`
// (`AppRootViewModel+Search.swift`) runs the raw text through BEFORE
// falling back to the original substring match, so a numeric-shaped query
// resolves against `SongSummary.number` (an `Int`) directly rather than the
// looser "does the DISPLAY string merely CONTAIN these digits" check a
// plain substring match on `displayNumber` gives — searching "8" no longer
// incorrectly matches every song numbered 8, 18, 28, 80...
//
// Mirrors `IHModels/SongID.swift`'s own `NSRegularExpression`-based
// parse-don't-validate approach for a similar "<letters><separator><digits>"
// shape, but is deliberately a SEPARATE type: `SongID` requires BOTH parts
// and is a wire-format identity type that belongs in IHModels; this is a
// UI-search-only, letters-OPTIONAL heuristic that has no business in the
// models layer.
import Foundation
import IHModels

/// A parsed "look up song number N, optionally within songbook B" search
/// intent, recognised from raw `.searchable` text like `"1008"`,
/// `"MP 1008"`, `"MP-1008"`, or `"MP1008"`.
struct CatalogueNumberQuery: Equatable {
    /// The songbook prefix the user typed, uppercased for a
    /// case-insensitive compare against `SongSummary.songbookAbbreviation`
    /// — `nil` when the query was a bare number with no book prefix.
    let songbookAbbreviation: String?

    /// The song number to match against `SongSummary.number`.
    let number: Int

    /// One-or-more letters (optional), then zero-or-more spaces/hyphens,
    /// then one-or-more digits — anchored at both ends so "Song 23 is
    /// great" (extra trailing words after the digits) correctly does NOT
    /// parse as a number query. Compiled once (see `SongID.swift`'s
    /// identical reasoning for caching a compiled `NSRegularExpression`).
    private static let pattern: NSRegularExpression = {
        // swiftlint:disable:next force_try
        try! NSRegularExpression(pattern: "^([A-Za-z]+)?[\\s-]*([0-9]+)$")
    }()

    /// Attempts to parse `rawQuery` as a number-search intent.
    ///
    /// - Parameter rawQuery: The raw `.searchable` text, exactly as typed.
    /// - Returns: `nil` when the string doesn't match the
    ///   `<letters>?<space/hyphen>*<digits>` shape at all — an ordinary
    ///   text search, not a malformed number search.
    ///
    /// ELI5: Give it what the user typed; if it LOOKS like a song number
    /// (with an optional book prefix), you get one back — otherwise `nil`.
    init?(rawQuery: String) {
        let trimmed = rawQuery.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }

        let range = NSRange(trimmed.startIndex..<trimmed.endIndex, in: trimmed)
        guard
            let match = Self.pattern.firstMatch(in: trimmed, range: range),
            let numberRange = Range(match.range(at: 2), in: trimmed),
            let parsedNumber = Int(trimmed[numberRange])
        else {
            return nil
        }

        // Group 1 (the letters prefix) is OPTIONAL in the pattern — when it
        // didn't participate in the match, `match.range(at: 1)` is
        // `{NSNotFound, 0}`, and `Range(_:in:)` correctly returns `nil` for
        // that, which the `if let` below treats as "no prefix was given."
        if let abbrRange = Range(match.range(at: 1), in: trimmed), !trimmed[abbrRange].isEmpty {
            songbookAbbreviation = trimmed[abbrRange].uppercased()
        } else {
            songbookAbbreviation = nil
        }
        number = parsedNumber
    }

    /// Whether `song` matches this number query: its own `number` equals
    /// `number` exactly, AND — only when a songbook prefix was given — its
    /// `songbookAbbreviation` matches too (case-insensitively). A bare
    /// number query (no prefix) matches that number in ANY songbook.
    ///
    /// ELI5: "Is this the exact song (in the exact book, if I said one) the
    /// person is looking for?"
    func matches(_ song: SongSummary) -> Bool {
        guard song.number == number else { return false }
        guard let songbookAbbreviation else { return true }
        return song.songbookAbbreviation.caseInsensitiveCompare(songbookAbbreviation) == .orderedSame
    }
}
