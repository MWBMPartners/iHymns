// LoadState.swift
// IHFeatures
//
// ELI5: A little "where are we right now?" label every screen that fetches
// something over the network can share — "haven't started," "fetching,"
// "got it, here's the data," or "failed, here's why" — so every loading
// spinner/error card in the app is built the same shape instead of each
// screen inventing its own set of booleans.
//
// DETAILED: Introduced by #1399 for `AppRootViewModel`'s catalogue load and
// `SongDetailViewModel`'s per-song load — both are "fetch one thing over
// the network, render it, handle loading/error/empty" screens, so this
// generic enum is the ONE shared shape rather than two near-identical ad
// -hoc state types (the repo's modularity rule: "if a piece of... logic
// exists in more than one place, extract it into a shared module").
// `Value` is generic so the SAME four states cover `[SongSummary]`
// (the catalogue) and `SongDetail` (one song) without duplicating the enum.
import Foundation

/// The load state of one network-fetched `Value`.
///
/// ELI5: "Not started yet," "loading," "here's the data," or "here's what
/// went wrong."
///
/// DETAILED: `Sendable` so a `@MainActor @Observable` view model can safely
/// assign into it from an `async` context reached via an actor-isolated
/// `APIClient` call. `Value: Sendable` is required for the SAME reason —
/// whatever payload this wraps (`[SongSummary]`, `SongDetail`, ...) must
/// itself be safe to cross that same actor boundary, which every `IHModels`
/// DTO already is. No standalone `.empty` case: an empty catalogue/result is
/// represented as `.loaded` with an empty `Value` (e.g. `.loaded([])`) —
/// callers that care about "loaded but empty" check the payload directly
/// (see `CatalogueListView`'s rendering switch) rather than this type
/// needing a redundant fifth case.
public enum LoadState<Value: Sendable>: Sendable {
    /// Nothing has been fetched yet — the state every screen starts in.
    case idle

    /// A fetch is currently in flight.
    case loading

    /// The most recent fetch succeeded, with this payload.
    case loaded(Value)

    /// The most recent fetch failed. Carries an already-user-facing message
    /// (see `APIError.userFacingMessage`) — NOT the raw `APIError` itself —
    /// so every view rendering an error state can display it directly with
    /// no per-screen error-to-copy mapping of its own.
    case error(String)
}

/// Conditional `Equatable` conformance — synthesized by the compiler because
/// every case's associated value is itself `Equatable` when `Value` is.
/// Declared in this same file (required for synthesis) purely so unit tests
/// can write `#expect(viewModel.loadState == .loaded(expected))` directly,
/// mirroring how `Optional`/`Result` in the standard library conditionally
/// conform to `Equatable` the same way.
extension LoadState: Equatable where Value: Equatable {}
