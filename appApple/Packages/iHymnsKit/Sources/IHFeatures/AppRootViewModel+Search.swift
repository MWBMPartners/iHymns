// AppRootViewModel+Search.swift
// IHFeatures
//
// ELI5: Everything about "which songs match what I typed, and which
// songbook/language chips did I tap, and what did I search before" — split
// out of `AppRootViewModel.swift` itself purely to keep that file from
// re-growing past the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`)
// now that search grew a number-match mode, songbook/language facet
// filters, and recent-search bookkeeping on top of the original #1399
// substring-only `filteredSongs`.
//
// DETAILED: #1436 (Apple P1 catalogue-nav search). Every property/method
// here is pure behaviour over `AppRootViewModel`'s OWN stored properties
// (`searchText`, `selectedSongbookAbbreviations`, `selectedLanguages`,
// `catalogueLoadState`, `recentSearches`, `recentSearchesStore`) declared
// in the primary file — a Swift extension cannot add new STORED
// properties, so every piece of actual state stays there; this file only
// adds computed properties and methods on top of it. Kept as an
// `AppRootViewModel` extension (not a second, parallel view model) because
// `CatalogueListView.swift`'s own header comment is explicit that
// `AppRootViewModel` itself IS the catalogue view model — a standalone
// `SearchViewModel` would just be the exact same state duplicated under a
// second name.
import Foundation
import IHModels

extension AppRootViewModel {

    /// The songs currently shown by `CatalogueListView`'s list: every
    /// `SongSummary` in the loaded catalogue that passes the current
    /// songbook/language facet filters AND (when `searchText` isn't empty)
    /// the current search — by NUMBER when `searchText` parses as one
    /// (#1436's "by-number mode": `"1008"`, `"MP 1008"`, `"MP-1008"`, ...)
    /// and that interpretation actually finds something, otherwise by
    /// substring match on title/number/songbook (#1399's original
    /// behaviour).
    ///
    /// ELI5: "Of the songs we've loaded, which ones should the list show
    /// right now?"
    public var filteredSongs: [SongSummary] {
        guard case .loaded(let songs) = catalogueLoadState else { return [] }
        let faceted = applyFacetFilters(to: songs)
        return Self.songsMatching(searchText, in: faceted)
    }

    /// The number-then-substring matching core `filteredSongs` delegates
    /// to — extracted (#1422, `.claude/apple-phase2-pr7-spec.md` §2/D-8) so
    /// `RemoteSongPickerView` (`IHFeatures`) can reuse the EXACT SAME
    /// matching logic over its own (unfiltered by songbook/language facets)
    /// song list without re-forking the number/substring ladder — this
    /// repo's own named regression class (`.claude/CLAUDE.md`'s "the
    /// builder's old private `_bsls_*` copies" precedent, applied here to
    /// Swift search matching instead of PHP similarity scoring). An empty
    /// query returns `songs` unchanged (`filteredSongs`' own original
    /// behaviour, now shared).
    ///
    /// ELI5: "Of these songs, which ones match what was typed?" — the same
    /// answer whether you're searching the whole catalogue or picking a
    /// song from the TV remote.
    public nonisolated static func songsMatching(_ query: String, in songs: [SongSummary]) -> [SongSummary] {
        guard !query.isEmpty else { return songs }

        if let numberQuery = CatalogueNumberQuery(rawQuery: query) {
            let numberMatches = songs.filter(numberQuery.matches)
            // Only trust the number interpretation if it actually found
            // something. A query like "Song 23" parses just fine as
            // songbook-prefix "SONG" + number 23, but no real songbook is
            // abbreviated "SONG" — confidently showing zero results there
            // would be worse than falling through to the plain substring
            // search below, which at least has a chance of matching the
            // title text itself.
            if !numberMatches.isEmpty { return numberMatches }
        }

        let needle = query.lowercased()
        return songs.filter {
            $0.title.lowercased().contains(needle)
                || $0.displayNumber.contains(needle)
                || $0.songbookAbbreviation.lowercased().contains(needle)
                || $0.songbookName.lowercased().contains(needle)
        }
    }

    /// Narrows `songs` to the current songbook/language facet selections.
    /// An EMPTY selection set for either facet means "no filter applied on
    /// that axis," never "match nothing" — matching how a freshly-opened
    /// filter menu with nothing ticked should show every song.
    private func applyFacetFilters(to songs: [SongSummary]) -> [SongSummary] {
        songs.filter { song in
            (selectedSongbookAbbreviations.isEmpty || selectedSongbookAbbreviations.contains(song.songbookAbbreviation))
                && (selectedLanguages.isEmpty || selectedLanguages.contains(song.language))
        }
    }

    /// Every distinct songbook in the LOADED catalogue — deliberately NOT
    /// the currently-filtered subset, since the filter menu's own choices
    /// must never shrink as other filters get applied — abbreviation
    /// paired with its full display name, sorted alphabetically by name for
    /// the filter menu.
    ///
    /// ELI5: "Which songbooks could I filter by?"
    public var availableSongbookAbbreviations: [(abbreviation: String, name: String)] {
        guard case .loaded(let songs) = catalogueLoadState else { return [] }
        var seen: [String: String] = [:]
        for song in songs {
            seen[song.songbookAbbreviation] = song.songbookName.isEmpty ? song.songbookAbbreviation : song.songbookName
        }
        return seen
            .map { (abbreviation: $0.key, name: $0.value) }
            .sorted { $0.name.localizedStandardCompare($1.name) == .orderedAscending }
    }

    /// Every distinct BCP-47 language tag in the loaded catalogue, sorted —
    /// same "always the full loaded set" reasoning as
    /// `availableSongbookAbbreviations` above.
    ///
    /// ELI5: "Which languages could I filter by?"
    public var availableLanguages: [String] {
        guard case .loaded(let songs) = catalogueLoadState else { return [] }
        return Array(Set(songs.map(\.language))).sorted()
    }

    /// Whether any songbook/language filter is currently active — drives
    /// the filter button's "on" glyph and whether "Clear Filters" appears
    /// in its menu.
    public var hasActiveFilters: Bool {
        !selectedSongbookAbbreviations.isEmpty || !selectedLanguages.isEmpty
    }

    /// Toggles one songbook abbreviation in/out of the active filter set.
    public func toggleSongbookFilter(_ abbreviation: String) {
        if selectedSongbookAbbreviations.contains(abbreviation) {
            selectedSongbookAbbreviations.remove(abbreviation)
        } else {
            selectedSongbookAbbreviations.insert(abbreviation)
        }
    }

    /// Toggles one language tag in/out of the active filter set.
    public func toggleLanguageFilter(_ language: String) {
        if selectedLanguages.contains(language) {
            selectedLanguages.remove(language)
        } else {
            selectedLanguages.insert(language)
        }
    }

    /// Clears both facet filters back to "show everything."
    public func clearFilters() {
        selectedSongbookAbbreviations.removeAll()
        selectedLanguages.removeAll()
    }

    /// Records the CURRENT `searchText` into the recent-searches list
    /// (most-recent-first, deduplicated, capped — see
    /// `RecentSearchesStore`) — called from `CatalogueListView`'s
    /// `.onSubmit(of: .search)`, matching the same "record on submit, not
    /// on every keystroke" convention as Safari/Mail's own recent-searches
    /// UX. A no-op for an empty query — nothing meaningful to remember.
    public func commitCurrentSearch() {
        guard !searchText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        recentSearchesStore.record(searchText)
        recentSearches = recentSearchesStore.load()
        // #189 — the query text itself is never recorded (see
        // `IHAnalyticsEvent.searchPerformed`'s own header for why), only how
        // many results it produced.
        IHAnalyticsService().searchPerformed(resultCount: filteredSongs.count)
    }

    /// Re-runs a previously recorded search — sets `searchText` directly,
    /// so `filteredSongs` re-filters exactly as if the user had just typed
    /// it. `CatalogueListView` wires this to `.searchCompletion(_:)`'s
    /// built-in tap-to-fill behaviour rather than calling it manually.
    public func applyRecentSearch(_ query: String) {
        searchText = query
    }

    /// Clears every recorded recent search — the "Clear" affordance in
    /// `CatalogueListView`'s search suggestions.
    public func clearRecentSearches() {
        recentSearchesStore.clear()
        recentSearches = []
    }
}
