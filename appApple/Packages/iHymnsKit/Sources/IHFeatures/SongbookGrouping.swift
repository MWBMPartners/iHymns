// SongbookGrouping.swift
// IHFeatures
//
// ELI5: Sorts the full list of songbooks into little labelled shelves — one
// shelf per series (e.g. all six "Songs Of Fellowship" books together), and
// one leftover shelf for every book that isn't part of a series — so
// `SongbooksView` can just draw a list of shelves instead of one giant
// unsorted grid.
//
// DETAILED: #1437 (Apple P1 Songbooks browse). Pure, stateless, and
// side-effect-free — a plain `[Songbook] -> [SongbookGroup]` transform, so
// it's trivially unit-testable against the real `songbooks.json` fixture
// with zero networking (see `SongbookGroupingTests.swift`). Groups by each
// songbook's FIRST series membership: `Songbook.series` is modelled as an
// array (a book could in principle belong to more than one), but every live
// row in `Tests/Fixtures/songbooks.json` carries at most one, so "first"
// and "only" coincide today; if a book ever gained a second series, it
// would simply be filed under its first one rather than being duplicated
// into two shelves. `isOfficial` is deliberately NOT a grouping axis here —
// `.claude/CLAUDE.md` rule #24 is explicit that official + unofficial
// songbooks surface TOGETHER as one family, distinguished only by
// `IHUnofficialBadge` on the tile, never split into separate lists/shelves.
import IHModels

/// One section of the Songbooks browse screen — either a named series
/// (#1181, e.g. "Songs Of Fellowship") or the flat "everything else"
/// bucket for songbooks that don't belong to any series.
struct SongbookGroup: Identifiable, Equatable {
    /// `"series-<id>"` for a real series, or the fixed string `"ungrouped"`
    /// for the leftover bucket — stable across re-computation since it's
    /// derived from the server's own `SongbookSeries.id`, not array
    /// position.
    let id: String

    /// The section header text — the series' own `name`, or "Songbooks"/
    /// "All Other Songbooks" for the leftover bucket (see `grouped(from:)`
    /// for exactly which label applies when).
    let title: String

    /// The songbooks in this section, already sorted alphabetically by
    /// name.
    let songbooks: [Songbook]

    /// Groups `songbooks` into series-named sections (sorted alphabetically
    /// by series name) followed by ONE trailing "leftover" section for
    /// every book with no series membership at all (also sorted
    /// alphabetically by name).
    ///
    /// ELI5: "Put these books on the right shelves."
    ///
    /// - Parameter songbooks: Every songbook the catalogue currently knows
    ///   about (from `?action=songbooks`) — unofficial books included, per
    ///   rule #24.
    /// - Returns: Series sections first, then the leftover bucket last —
    ///   omitted entirely if every book happens to belong to a series (an
    ///   empty leftover bucket would be a section with nothing in it).
    static func grouped(from songbooks: [Songbook]) -> [SongbookGroup] {
        var seriesBuckets: [Int: (series: SongbookSeries, books: [Songbook])] = [:]
        var ungrouped: [Songbook] = []

        for book in songbooks {
            if let series = book.series.first {
                seriesBuckets[series.id, default: (series, [])].books.append(book)
            } else {
                ungrouped.append(book)
            }
        }

        let seriesGroups = seriesBuckets.values
            .sorted { $0.series.name.localizedStandardCompare($1.series.name) == .orderedAscending }
            .map { entry in
                SongbookGroup(
                    id: "series-\(entry.series.id)",
                    title: entry.series.name,
                    songbooks: sortedByName(entry.books)
                )
            }

        guard !ungrouped.isEmpty else { return seriesGroups }

        // "Songbooks" (no further qualifier needed) when EVERY book is
        // ungrouped — there's nothing to distinguish this bucket from,
        // e.g. before any series data exists on an install; "All Other
        // Songbooks" once at least one real series section exists above it.
        let ungroupedTitle = seriesGroups.isEmpty ? "Songbooks" : "All Other Songbooks"
        let ungroupedGroup = SongbookGroup(id: "ungrouped", title: ungroupedTitle, songbooks: sortedByName(ungrouped))
        return seriesGroups + [ungroupedGroup]
    }

    private static func sortedByName(_ songbooks: [Songbook]) -> [Songbook] {
        songbooks.sorted { $0.name.localizedStandardCompare($1.name) == .orderedAscending }
    }
}
