// AppRootViewModel+Intents.swift
// IHFeatures
//
// ELI5: The ONE place an App Intent goes to get "the current list of songs"
// — offline-first, so opening a Shortcut or asking Siri to open a hymn
// still works with no signal, and NEVER throws (an App Intent's own error
// handling is a poor fit for "no catalogue yet" — it should just come back
// empty and let the intent itself decide what to say/do).
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). `SongEntityQuery`
// (`AppIntents/SongEntityQuery.swift`) is the ONE caller —
// `entities(matching:)`/`entities(for:)` both resolve against whatever this
// returns, so a `SongEntity` search box in Shortcuts/Siri behaves
// consistently whether or not the app has been opened yet this session.
import IHModels

extension AppRootViewModel {
    /// The best available `[SongSummary]` list for an App Intent to search
    /// against — the already-loaded catalogue if there is one, otherwise the
    /// OFFLINE cache (instant, no network), otherwise a best-effort network
    /// fetch, otherwise an empty list. NEVER throws.
    ///
    /// ELI5: "Give me whatever songs we know about right now" — the freshest
    /// copy available, but never an error.
    ///
    /// DETAILED: Checked in this order because each is strictly cheaper/
    /// more-available than the next: `catalogueLoadState` is already in
    /// memory if ANY screen has loaded it this run; `offlineStore
    /// .allSongSummaries()` survives a cold App Intent launch with no
    /// network (`AppRootViewModel+Catalog.swift`'s `syncSystemIndexes(_:)`
    /// is what keeps it populated); `loadCatalogueIfNeeded()` is the last
    /// resort — a genuinely fresh install/offline-cache-cleared device
    /// running its FIRST Shortcut, worth one network attempt before giving
    /// up. A failure at every step degrades to `[]`, never a thrown error —
    /// `SongEntityQuery.entities(matching:)`/`entities(for:)` have no good
    /// way to surface a thrown error to the Shortcuts/Siri UI anyway.
    public func songSummariesForIntents() async -> [SongSummary] {
        if case .loaded(let songs) = catalogueLoadState {
            return songs
        }
        if let cached = try? await offlineStore.allSongSummaries(), !cached.isEmpty {
            return cached
        }
        await loadCatalogueIfNeeded()
        if case .loaded(let songs) = catalogueLoadState {
            return songs
        }
        return []
    }
}
