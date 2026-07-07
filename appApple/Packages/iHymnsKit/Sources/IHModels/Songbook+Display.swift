// Songbook+Display.swift
// IHModels
//
// ELI5: A songbook has a strict short code (used in every song's id/URL,
// letters-and-numbers only) and, sometimes, a prettier label to actually
// SHOW on screen instead. This file answers two tiny questions every
// songbook-listing screen needs: "what should the abbreviation badge say?"
// and "is showing that badge even worth it, or would it just repeat the
// title?"
//
// DETAILED: A native, byte-for-byte mirror of the web's
// `appWeb/public_html/includes/songbook_display.php` helpers
// (`ihymns_songbook_abbr_label()` / `ihymns_songbook_show_abbr()`, #1332) —
// `.claude/CLAUDE.md` rule #27 is explicit that `Songbook.id`
// (`tblSongbooks.Abbreviation`) IS the `SongId` prefix and must never be
// loosened/replaced for display, while `displayAbbr` is a free-text,
// presentation-only override. Kept in its OWN file (rather than folded into
// `Songbook.swift` itself) purely to mirror the web side's own separate
// `songbook_display.php` file — the same "one small shared rule, one place"
// reasoning, applied consistently across both platforms. #1437 (the Apple
// Songbooks browse screen) is this file's first consumer
// (`IHFeatures/SongbookTileView.swift`), but any future screen that renders
// a songbook's abbreviation (a song's metadata line, a share card, ...)
// reuses these two properties rather than re-deriving the same trim/
// case-insensitive-compare logic.
import Foundation

extension Songbook {
    /// The abbreviation label to actually DISPLAY — `displayAbbr` when it's
    /// set to a non-blank value (#1332), otherwise the real `id`
    /// (`Abbreviation`, the `SongId` prefix). Mirrors the web's
    /// `ihymns_songbook_abbr_label()` exactly: trims `displayAbbr`, falls
    /// back to `id` (also trimmed) when the trimmed result is empty.
    ///
    /// ELI5: "What should the little book-code badge actually say?"
    ///
    /// DETAILED: URLs, `SongID`s, and every API call always use `id`
    /// directly — this property is presentation-only and must never leak
    /// into a network request or a deep link (CLAUDE.md rule #27's whole
    /// point: the strict code and the pretty label are deliberately kept
    /// separate so renaming the label can never re-key a song).
    public var abbreviationLabel: String {
        let trimmedDisplay = (displayAbbr ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmedDisplay.isEmpty ? id.trimmingCharacters(in: .whitespacesAndNewlines) : trimmedDisplay
    }

    /// Whether the abbreviation badge is worth rendering AT ALL alongside
    /// this songbook's `name` — mirrors the web's
    /// `ihymns_songbook_show_abbr()` hide-when-equal-to-title check (rule
    /// #27): the unofficial "Psalty" collection's abbreviation IS also
    /// "Psalty", and a tile reading "Psalty · Psalty" tells the reader
    /// nothing a single "Psalty" wouldn't. `false` when `abbreviationLabel`
    /// is empty OR case-insensitively identical to `name`; `true` otherwise.
    ///
    /// ELI5: "Would showing the book-code badge next to the title just be
    /// saying the same word twice?"
    public var showsAbbreviationLabel: Bool {
        let label = abbreviationLabel
        guard !label.isEmpty else { return false }
        return label.caseInsensitiveCompare(name) != .orderedSame
    }
}
