// AppRootViewModel+Catalog.swift
// IHFeatures
//
// ELI5: The "give me one song / this song's counterparts / what's similar /
// the list of hymnals / today's featured song" pass-throughs — every screen
// that needs ONE of these reaches it through `AppRootViewModel` rather than
// holding its own `APIClient`. Also owns the WHOLE-catalogue fetch pair
// (`loadCatalogueIfNeeded()`/`loadCatalogue()`, #1399) and the #1415 hook
// that keeps the on-device offline cache + the Siri/Spotlight index in sync
// with whatever the catalogue fetch just returned.
//
// DETAILED: Moved out of `AppRootViewModel.swift` itself (native login/
// account UI + favourites task) purely to keep that file from re-growing
// past the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`) now that it
// also carries `favorites`/`currentUser` state — the exact same "a Swift
// extension can't add new STORED properties, so behaviour-only code is free
// to live in its own file" reasoning `AppRootViewModel+Search.swift`'s own
// header already documents. Every pass-through method below is UNCHANGED
// from its original `AppRootViewModel.swift` home — a pure file move, not a
// behavioural edit — and each only touches `apiClient` (`internal`-visible
// on the primary file, for exactly this kind of cross-file extension).
//
// #1415 UPDATE (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4) — `loadCatalogueIfNeeded()`/
// `loadCatalogue()` (#1399's ORIGINAL fetch pair, previously living in the
// primary file) MOVE here too, paying for the primary file's new
// `spotlightIndexer` stored property in the SAME commit, per this repo's
// LOC-budget discipline (this file's own extraction reasoning above,
// applied a second time). `loadCatalogue()` itself gains exactly ONE new
// line on top of its pre-#1415 body: on a successful fetch, it fires a
// background `Task` into `syncSystemIndexes(_:)` (also new, this file).
// `AppRootViewModel` is the ONE place a catalogue fetch ever lands, so it's
// the ONE correct place to keep BOTH the offline cache (`OfflineStore
// .upsert(_:)`, declared since Phase 0 but never actually CALLED until now
// — the offline-safe App Intents this task adds would otherwise see an
// eternally-empty cache on a cold, no-network launch) and the Siri/Spotlight
// index fresh, without every future catalogue consumer re-deriving that
// same sync itself.
import IHAPI
import IHAppSupport
import IHModels

extension AppRootViewModel {
    /// Fetches the catalogue index over the network — but only if it
    /// hasn't already been fetched (or isn't currently mid-fetch). Safe to
    /// call from `CatalogueListView`'s `.task {}` on every re-appearance
    /// (e.g. navigating back from a song and returning to the list) without
    /// re-issuing the network call each time.
    ///
    /// ELI5: "Get the song list, but only if we don't already have it."
    public func loadCatalogueIfNeeded() async {
        guard case .idle = catalogueLoadState else { return }
        await loadCatalogue()
    }

    /// Forces a catalogue (re)fetch regardless of current state — the hook
    /// a future manual "retry"/pull-to-refresh action calls into.
    ///
    /// ELI5: "Go get the song list right now, even if we already tried."
    public func loadCatalogue() async {
        catalogueLoadState = .loading
        do {
            let songs = try await apiClient.songsIndex()
            catalogueLoadState = .loaded(songs)
            // #1415 — best-effort, fire-and-forget: a fresh catalogue is
            // useful to the offline cache/Siri/Spotlight even if the app
            // itself moves on immediately; nothing here should block (or be
            // able to fail) the UI's own `.loaded` transition above.
            Task { await self.syncSystemIndexes(songs) }
        } catch let error as APIError {
            catalogueLoadState = .error(error.userFacingMessage)
        } catch {
            catalogueLoadState = .error("Something went wrong loading the song list. Please try again.")
        }
    }

    /// Mirrors a freshly fetched catalogue into the two on-device system
    /// surfaces that don't get it for free: the OFFLINE cache (so an App
    /// Intent/Shortcut run with no network can still resolve a song by
    /// title/number — `AppRootViewModel+Intents.swift`'s
    /// `songSummariesForIntents()`) and the SIRI/SPOTLIGHT index (so a
    /// system-wide search for a hymn title surfaces it, and Siri can
    /// resolve a `SongEntity` by name). `internal` (not `private`) so
    /// `AppRootViewModelSystemIndexTests` can `await` it directly rather
    /// than racing the fire-and-forget `Task` `loadCatalogue()` wraps it in.
    ///
    /// ELI5: "We just got the song list from the server — make sure the
    /// offline copy and Siri/Spotlight both know about it too."
    ///
    /// DETAILED: `try? await offlineStore.upsert(songs)` — a cache-write
    /// failure (disk full, a genuinely corrupt database) must never surface
    /// as a catalogue-load error; the network fetch already succeeded and
    /// `catalogueLoadState` is already `.loaded`, so this is purely a
    /// best-effort mirror, matching every other `OfflineStore` write in this
    /// package's "best-effort, never block the primary flow" posture (e.g.
    /// `AppRootViewModel+Offline.swift`'s `isSongSavedOffline(_:)`).
    /// `spotlightIndexer.reindexIfNeeded(_:)` is itself internally
    /// best-effort too (see that type's own doc comment) — nothing here
    /// needs a second layer of error handling around it.
    func syncSystemIndexes(_ songs: [SongSummary]) async {
        try? await offlineStore.upsert(songs)
        await spotlightIndexer.reindexIfNeeded(songs)
    }

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

    /// Fetches one Work (composition grouping) by slug (#840, #1443) — same
    /// pass-through pattern as `songDetail(id:)`/`songbooks()` above.
    ///
    /// ELI5: "Give me everything about this one composition grouping."
    public func work(slug: String) async throws -> Work {
        try await apiClient.work(slug: slug)
    }

    /// Fetches one credit person's bio + discography (#1443, #1444) — same
    /// pass-through pattern as `songDetail(id:)`/`songbooks()` above.
    ///
    /// ELI5: "Give me everything about this one writer/composer."
    public func creditPerson(_ lookup: CreditPersonLookup) async throws -> CreditPerson {
        try await apiClient.creditPerson(lookup)
    }
}
