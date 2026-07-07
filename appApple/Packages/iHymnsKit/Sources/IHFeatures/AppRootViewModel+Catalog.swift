// AppRootViewModel+Catalog.swift
// IHFeatures
//
// ELI5: The "give me one song / this song's counterparts / what's similar /
// the list of hymnals / today's featured song" pass-throughs — every screen
// that needs ONE of these reaches it through `AppRootViewModel` rather than
// holding its own `APIClient`.
//
// DETAILED: Moved out of `AppRootViewModel.swift` itself (native login/
// account UI + favourites task) purely to keep that file from re-growing
// past the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`) now that it
// also carries `favorites`/`currentUser` state — the exact same "a Swift
// extension can't add new STORED properties, so behaviour-only code is free
// to live in its own file" reasoning `AppRootViewModel+Search.swift`'s own
// header already documents. Every method below is UNCHANGED from its
// original `AppRootViewModel.swift` home — this is a pure file move, not a
// behavioural edit — and each only touches `apiClient` (`internal`-visible
// on the primary file, for exactly this kind of cross-file extension).
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Fetches one full song record — a thin pass-through to `APIClient`,
    /// exactly like `cachedSongSummaries()`'s read-through above, so
    /// `SongDetailViewModel` (#1399) never needs the `APIClient` itself.
    ///
    /// ELI5: "Give me everything about this one song."
    public func songDetail(id: SongID) async throws -> SongDetail {
        try await apiClient.songDetail(id: id)
    }

    /// Fetches this song's cross-book counterparts (#180, #807) — same
    /// pass-through pattern as `songDetail(id:)` above.
    ///
    /// ELI5: "Does this exact song also appear, under a different id, in
    /// some other songbook?"
    public func songLinks(id: SongID) async throws -> SongLinkGroup {
        try await apiClient.songLinks(id: id)
    }

    /// Fetches songs related to this one by shared tag/writer/composer/
    /// vicinity (#180) — same pass-through pattern as `songDetail(id:)`.
    ///
    /// ELI5: "What else might I like, based on this song?"
    public func relatedSongs(id: SongID) async throws -> [RelatedSongSummary] {
        try await apiClient.relatedSongs(id: id)
    }

    /// Fetches every songbook in the catalogue (#1437, `SongbooksViewModel`'s
    /// only network dependency) — same pass-through pattern as
    /// `songDetail(id:)`/`songLinks(id:)`/`relatedSongs(id:)` above: exactly
    /// ONE `APIClient` instance exists per app run (owned here), so every
    /// screen that needs a network call reaches it through this root view
    /// model rather than holding an `APIClient` of its own.
    ///
    /// ELI5: "Give me the list of hymnals."
    public func songbooks() async throws -> [Songbook] {
        try await apiClient.songbooks()
    }

    /// Fetches today's Song of the Day (#183) — `HomeViewModel`'s only
    /// network dependency, mirroring `songbooks()`'s pass-through pattern
    /// exactly, PLUS resolving `hemisphere`/`country` from the device's own
    /// `Locale` (`IHAPI.LocaleRegionSignals`, privacy-safe — no GeoIP, no
    /// location permission) so `HomeViewModel` never has to know those
    /// parameters exist at all.
    ///
    /// ELI5: "What's today's featured song, for someone roughly where I
    /// am?"
    public func songOfTheDay() async throws -> SongOfTheDay {
        try await apiClient.songOfTheDay(
            hemisphere: LocaleRegionSignals.hemisphere(),
            country: LocaleRegionSignals.country()
        )
    }
}
