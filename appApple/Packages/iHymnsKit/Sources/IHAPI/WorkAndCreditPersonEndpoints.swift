// WorkAndCreditPersonEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "give me one Work (a composition grouping)"
// and "give me one credit person (a writer/composer, with their bio and
// every song they're credited on)" — the two new server calls #1443/#1444
// need.
//
// DETAILED: `?action=work` and `?action=credit_person` are THIN JSON
// wrappers this task's own backend survey found were missing server-side
// (only the HTML page renderers `?page=work`/`?page=person` existed) and
// added alongside `SongData::getWork()`/the new `SongData::getCreditPerson()`
// (`appWeb/public_html/api.php`, `.claude` rule #14/#15) — see those PHP
// functions' own doc comments for the exact field-for-field mapping this
// file's `Endpoint` factories assume.
//
// Split into its own file (rather than growing `CatalogEndpoints.swift`/
// `DiscoveryEndpoints.swift`) purely because neither `Work` nor
// `CreditPerson` fit either existing file's own stated scope ("plain
// catalogue fetches" / "what else is connected to this song") — this is a
// third, genuinely distinct "other catalogue entities" concern. The sibling
// `?action=song_request` submission (#1447) lives in its OWN
// `SongRequestEndpoint.swift` — a separate feature (a community submission,
// not a catalogue read) that happens to have landed in the same task.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=work&slug=…` — one Work (composition grouping across
    /// songbooks), with member songs, parent/children headers, and external
    /// links (#840, #1443). Public, no auth required.
    ///
    /// ELI5: "Give me everything about this one composition grouping."
    ///
    /// - Parameter slug: The Work's URL-safe slug, e.g. `"amazing-grace"` —
    ///   the same value `DeepLink.work(slug:)`/`CanonicalURL.work(slug:)`
    ///   (`IHAppSupport`) carry.
    public static func work(slug: String) -> Endpoint {
        Endpoint(action: "work", queryItems: [("slug", slug)])
    }
}

/// How a `credit_person` lookup identifies WHICH person to fetch —
/// `?action=credit_person` accepts any one of `slug`/`id`/`name` as a query
/// parameter (`api.php`'s `case 'credit_person'`), because the native app
/// itself has three genuinely different starting points:
///   - `.slug(_:)` — a resolved `/person/<slug>` Universal Link
///     (`DeepLink.person(slug:)`).
///   - `.id(_:)` — a stable `tblCreditPeople.Id`, once some future screen
///     has one in hand (not produced by any call site yet, but modelled for
///     completeness alongside the other two — the server itself accepts all
///     three equally).
///   - `.name(_:)` — the ONLY option available from `SongDetail.writers`/
///     `.composers`/etc. (`SongDetail.swift`), which are plain name strings
///     with no id — this is what powers #1444's "tap a composer name → see
///     their songs" flow.
public enum CreditPersonLookup: Sendable, Equatable {
    case slug(String)
    case id(Int)
    case name(String)
}

extension Endpoint {
    /// `?action=credit_person` — one credit person's bio/lifespan/external
    /// links plus every song they're credited on, grouped by role (#1443,
    /// #1444). Public, no auth required.
    ///
    /// ELI5: "Give me everything about this one writer/composer, including
    /// every song of theirs we have."
    public static func creditPerson(_ lookup: CreditPersonLookup) -> Endpoint {
        switch lookup {
        case .slug(let slug):
            Endpoint(action: "credit_person", queryItems: [("slug", slug)])
        case .id(let id):
            Endpoint(action: "credit_person", queryItems: [("id", String(id))])
        case .name(let name):
            Endpoint(action: "credit_person", queryItems: [("name", name)])
        }
    }
}
