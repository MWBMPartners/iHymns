// IHAnalyticsEvent.swift
// IHAppSupport
//
// ELI5: What one "something happened" note looks like once the app decides
// to remember it — a short name (like "song_opened") plus a couple of tiny,
// harmless facts about it (like WHICH song, by its public catalogue number —
// never who you are or what you typed).
//
// DETAILED: #189 (Apple Phase 1 — first-party, consent-gated analytics,
// strategy §3.1). This type is the DATA SHAPE only; the decision of
// "should this even be recorded right now" lives one layer up in
// `IHFeatures.IHAnalyticsService` (which checks `IHSettingsStore
// .analyticsConsentEnabled` before ever constructing/forwarding one of
// these) — kept here in `IHAppSupport` (→ `IHModels` only, no `IHFeatures`
// dependency) so the shape itself, and the "is this actually free of PII"
// contract, is testable in complete isolation from any view model or
// `UserDefaults` consent plumbing, mirroring how `CanonicalURL` (this same
// file's neighbour) is a pure, dependency-light helper the higher layers
// compose.
//
// WHY NOT a free-form `[String: Any]`/JSON blob? Every event this app can
// ever produce goes through the closed set of static factories below —
// never a public initializer callers fill in with arbitrary keys/values —
// so "does this event carry PII" is a property of THIS FILE, auditable in
// one place, rather than a promise every call site has to keep on its own.
// In particular: `searchPerformed` deliberately records only a RESULT
// COUNT, never the raw query text — a search box is exactly the kind of
// free-text field a user might (accidentally or not) type something
// personal into, so the safest contract is to never capture it at all.
// `songOpened`/`offlineSave` record the PUBLIC catalogue `SongID` (e.g.
// `"MP-1008"`, the same identifier `CanonicalURL.song(_:)` builds a public
// share link from) — that identifies a piece of PUBLIC catalogue content,
// never a person.
import Foundation

/// One first-party, anonymous analytics event — see this file's header for
/// the full "why no free-form payload" reasoning.
///
/// ELI5: A tiny, pre-approved note like "a song was opened" — never
/// anything that could identify who opened it.
///
/// DETAILED: `Sendable` + `Equatable` so it can cross the `@MainActor`
/// `IHAnalyticsService` → `any IHAnalyticsSink` boundary freely and be
/// asserted on directly in tests (`IHAnalyticsEventTests`,
/// `IHAnalyticsServiceTests`). `parameters` is intentionally a flat
/// `[String: String]` — no nested objects, no arrays — the simplest shape a
/// future first-party ingestion endpoint (see the `for consideration`
/// backend-endpoint issue this task filed) could accept as-is.
public struct IHAnalyticsEvent: Sendable, Equatable {
    /// The event's short, snake_case name — e.g. `"song_opened"`. Matches
    /// the vocabulary the (currently hypothetical) backend endpoint would
    /// expect, so no translation layer is needed once one exists.
    public let name: String

    /// Small, non-identifying context for the event. Every value here is
    /// either a fixed/enum-like label (a screen name) or a public catalogue
    /// identifier/count — see this file's header for the per-event
    /// reasoning on what is and isn't included.
    public let parameters: [String: String]

    /// Public but deliberately NOT the primary construction path — callers
    /// should reach for one of the static factories in the extension below
    /// so the "which events exist, and what do they carry" list stays
    /// enumerable in one place. Still public (not `internal`/`fileprivate`)
    /// because `IHAnalyticsService` (a DIFFERENT module, `IHFeatures`) and
    /// this package's own tests both need to construct/compare events.
    public init(name: String, parameters: [String: String] = [:]) {
        self.name = name
        self.parameters = parameters
    }
}

// MARK: - The closed set of events this app can produce

public extension IHAnalyticsEvent {
    /// A top-level screen became visible — `screen` is a fixed label like
    /// `"Home"`/`"Settings"` (`AppNavigationState.RootSection.title`), never
    /// free text.
    static func screenView(_ screen: String) -> IHAnalyticsEvent {
        IHAnalyticsEvent(name: "screen_view", parameters: ["screen": screen])
    }

    /// A song's full record finished loading and is now on screen —
    /// `songId` is the PUBLIC catalogue identifier (`SongID.rawValue`, e.g.
    /// `"MP-1008"`), the same value `CanonicalURL.song(_:)` already turns
    /// into a public, shareable web link. Identifies a piece of content,
    /// never a person.
    static func songOpened(songId: String) -> IHAnalyticsEvent {
        IHAnalyticsEvent(name: "song_opened", parameters: ["song_id": songId])
    }

    /// A search was SUBMITTED (not every keystroke — see
    /// `AppRootViewModel.commitCurrentSearch()`'s own "record on submit"
    /// convention). Deliberately carries NO query text — see this file's
    /// header for why — only how many results it produced.
    static func searchPerformed(resultCount: Int) -> IHAnalyticsEvent {
        IHAnalyticsEvent(name: "search_performed", parameters: ["result_count": String(resultCount)])
    }

    /// A song was saved for offline reading — same public-`SongID` shape as
    /// `songOpened`.
    static func offlineSave(songId: String) -> IHAnalyticsEvent {
        IHAnalyticsEvent(name: "offline_save", parameters: ["song_id": songId])
    }
}
