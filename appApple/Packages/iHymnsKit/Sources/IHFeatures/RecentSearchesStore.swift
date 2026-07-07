// RecentSearchesStore.swift
// IHFeatures
//
// ELI5: Remembers the last ~10 things you searched for, so
// `CatalogueListView` can show them again when the search field is empty
// and let you tap one to re-run it.
//
// DETAILED: Implements strategy §2.1's "Recent searches: persist the last
// ~10 queries (`@AppStorage`/UserDefaults)" (#1436). A plain, `Sendable`
// value type wrapping `UserDefaults` directly — NOT the `@AppStorage`
// property wrapper — because this type needs to be INJECTABLE the same way
// every other engine in this package is (`APIClient`'s `URLSession`,
// `KeychainTokenStore`'s Keychain attributes): `@AppStorage` is designed to
// be declared directly on a SwiftUI `View`/`@Observable` property with no
// clean seam for a test to substitute a throwaway suite, whereas a plain
// `UserDefaults` parameter lets tests pass `UserDefaults(suiteName:)` so
// runs never pollute (or are polluted by) the real `.standard` defaults, or
// each other. `AppRootViewModel` holds exactly ONE instance (injected via
// its own initializer, defaulting to the real `.standard`) and mirrors this
// store's `load()` result into its `@Observable` `recentSearches` property
// — `CatalogueListView` therefore only ever reads a plain `[String]`, never
// touching `UserDefaults` (or this type) directly.
import Foundation

/// Persists the user's recent catalogue searches, most-recent-first,
/// deduplicated, capped at a small fixed count.
///
/// ELI5: A tiny notebook that remembers the last few things you searched
/// for.
///
/// DETAILED: `@unchecked Sendable` rather than plain `Sendable` — this
/// SDK's `UserDefaults` doesn't (yet) carry a `Sendable` conformance the
/// compiler can see, even though `UserDefaults` has always been documented
/// as thread-safe for exactly this kind of read/write usage
/// (https://developer.apple.com/documentation/foundation/userdefaults).
/// `key`/`limit` are plain `Sendable` value types, so `defaults` is the only
/// property actually relying on the `@unchecked` escape hatch.
public struct RecentSearchesStore: @unchecked Sendable {
    private let defaults: UserDefaults
    private let key: String
    private let limit: Int

    /// - Parameters:
    ///   - defaults: Where to persist — `.standard` for the real app; tests
    ///     inject a throwaway `UserDefaults(suiteName:)`.
    ///   - key: The `UserDefaults` key this store owns exclusively.
    ///   - limit: How many distinct queries to remember — #1436 calls for
    ///     "the last ~10."
    public init(defaults: UserDefaults = .standard, key: String = "ihRecentCatalogueSearches", limit: Int = 10) {
        self.defaults = defaults
        self.key = key
        self.limit = limit
    }

    /// The currently-persisted recent searches, most-recent-first.
    ///
    /// ELI5: "What have I searched for recently?"
    public func load() -> [String] {
        defaults.stringArray(forKey: key) ?? []
    }

    /// Records `query` as the newest recent search.
    ///
    /// ELI5: "Remember this one — and if I'd already searched it before,
    /// just move it to the top instead of listing it twice."
    ///
    /// DETAILED: Trims whitespace and ignores an empty result (nothing
    /// meaningful to remember); removes any existing case-insensitive
    /// duplicate BEFORE inserting at the front, so re-searching something
    /// already in the list moves it to the top rather than showing it
    /// twice; then caps the list at `limit`, dropping the OLDEST entries
    /// first (the tail, since the list is already most-recent-first).
    public func record(_ query: String) {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }

        var existing = load()
        existing.removeAll { $0.caseInsensitiveCompare(trimmed) == .orderedSame }
        existing.insert(trimmed, at: 0)
        if existing.count > limit {
            existing.removeLast(existing.count - limit)
        }
        defaults.set(existing, forKey: key)
    }

    /// Forgets every recorded recent search — the "Clear" affordance.
    public func clear() {
        defaults.removeObject(forKey: key)
    }
}
