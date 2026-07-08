// SongComparisonViewModel.swift
// IHFeatures
//
// ELI5: The helper behind the comparison screen — fetches BOTH songs at the
// same time, remembers whether each one is still loading/succeeded/failed
// (falling back to a saved offline copy per side, same as the regular song
// screen), and works out the paired verse-by-verse diff once both are in.
//
// DETAILED: #1445. Mirrors `SongDetailViewModel`'s shape closely — `@Observable
// @MainActor`, constructed fresh per screen (`SongComparisonView`'s own
// `@State`), delegates every network call to `AppRootViewModel` rather than
// holding an `APIClient` of its own (there is exactly ONE `APIClient`
// instance per app run). Two independent `LoadState<SongDetail>`s (one per
// side) load CONCURRENTLY via `async let`, exactly like
// `SongDetailViewModel.load()`'s primary/secondary shelves — a slow/failed
// side never blocks the other.
//
// Offline fallback per side copies `SongDetailViewModel
// .isOfflineLikeFailure(_:)`'s exact case list and reasoning (per the
// comparison-plan's own instruction) rather than sharing that private
// static method across files — it's a three-line switch with no meaningful
// behaviour to diverge on, so a tiny, obviously-correct copy here is
// simpler than threading a cross-file dependency for it.
//
// `pairs` recomputes from the two `.loaded` details ON DEMAND (not cached
// in a stored property) via `SongComparisonEngine` — mirrors
// `SongDetailView.enrichmentIndex`'s identical "a song is a few dozen
// lines, this is cheap, no need to track staleness" reasoning.
import Foundation
import IHAPI
import IHModels
import Observation

/// Loads and holds TWO songs' full records for side-by-side comparison,
/// plus the paired diff `SongComparisonEngine` computes once both are in.
///
/// ELI5: "Go get these two songs, and tell me line-by-line where they
/// differ."
@MainActor
@Observable
public final class SongComparisonViewModel {
    /// The primary (usually "the song the user was already reading") side's
    /// load state.
    public private(set) var primaryState: LoadState<SongDetail> = .idle

    /// The secondary (the one the user picked to compare against) side's
    /// load state.
    public private(set) var secondaryState: LoadState<SongDetail> = .idle

    /// Whether `primaryState`'s current `.loaded` value came from the
    /// offline cache rather than a fresh network response — same "Offline —
    /// showing saved copy" signal `SongDetailViewModel.isServingCachedCopy`
    /// already establishes, tracked per side here since the two songs can
    /// independently be fresh or cached.
    public private(set) var isServingCachedCopyPrimary = false

    /// The secondary-side equivalent of `isServingCachedCopyPrimary`.
    public private(set) var isServingCachedCopySecondary = false

    /// Which song is currently on which side — `private(set)` so
    /// `swapSides()` (below) is the only way to change them, keeping every
    /// mutation of this pairing in one auditable place.
    public private(set) var primaryId: SongID
    public private(set) var secondaryId: SongID

    private let rootViewModel: AppRootViewModel

    public init(primaryId: SongID, secondaryId: SongID, rootViewModel: AppRootViewModel) {
        self.primaryId = primaryId
        self.secondaryId = secondaryId
        self.rootViewModel = rootViewModel
    }

    /// Fetches both songs, but only if NEITHER has already been fetched (or
    /// is currently mid-fetch) — safe to call from `.task {}` on every
    /// re-appearance without re-issuing the network calls each time.
    public func loadIfNeeded() async {
        guard case .idle = primaryState, case .idle = secondaryState else { return }
        await load()
    }

    /// Forces a (re)fetch of both sides, regardless of current state — they
    /// run CONCURRENTLY (`async let`), each resolving its own `LoadState`
    /// independently.
    public func load() async {
        primaryState = .loading
        secondaryState = .loading
        isServingCachedCopyPrimary = false
        isServingCachedCopySecondary = false

        async let primaryOutcome = loadOneSide(id: primaryId)
        async let secondaryOutcome = loadOneSide(id: secondaryId)
        let (primaryResult, secondaryResult) = await (primaryOutcome, secondaryOutcome)

        apply(primaryResult, isPrimary: true)
        apply(secondaryResult, isPrimary: false)
    }

    /// The paired, line-diffed comparison — empty until BOTH sides have
    /// loaded. Recomputed on demand each time it's read (see this file's
    /// header for why that's cheap enough not to cache).
    public var pairs: [ComparisonComponentPair] {
        guard case .loaded(let primary) = primaryState, case .loaded(let secondary) = secondaryState else { return [] }
        return SongComparisonEngine.pairs(primary: primary, secondary: secondary)
    }

    /// The loaded primary `SongDetail`, or `nil` while still loading/failed.
    public var primaryDetail: SongDetail? {
        if case .loaded(let detail) = primaryState { return detail }
        return nil
    }

    /// The loaded secondary `SongDetail`, or `nil` while still loading/failed.
    public var secondaryDetail: SongDetail? {
        if case .loaded(let detail) = secondaryState { return detail }
        return nil
    }

    /// Whether BOTH sides have finished loading successfully — the point at
    /// which `SongComparisonView` can render the paired grid/pages.
    public var isReady: Bool {
        if case .loaded = primaryState, case .loaded = secondaryState { return true }
        return false
    }

    /// One combined failure message if EITHER side ended in `.error` (after
    /// its own offline fallback attempt) — the comparison screen's "one
    /// failure UI rule: never a half-rendered comparison" (see the plan's
    /// §1.4). Primary's message wins if both sides happen to have failed,
    /// which is an arbitrary but harmless tie-break (`retry()` reloads
    /// both regardless).
    public var combinedErrorMessage: String? {
        if case .error(let message) = primaryState { return message }
        if case .error(let message) = secondaryState { return message }
        return nil
    }

    /// Swaps which song is "primary" (left/page A) versus "secondary"
    /// (right/page B) — a pure state swap, no refetch, since both songs are
    /// already loaded (or already failed) either way.
    ///
    /// ELI5: "Flip the two songs around."
    public func swapSides() {
        swap(&primaryId, &secondaryId)
        swap(&primaryState, &secondaryState)
        swap(&isServingCachedCopyPrimary, &isServingCachedCopySecondary)
    }

    // MARK: - Per-side load + offline fallback

    /// One side's load outcome — kept as a private enum (rather than
    /// mutating `primaryState`/`secondaryState` directly from inside the
    /// concurrently-running `loadOneSide` calls) so `load()` can `await`
    /// both sides' results via a single `async let` tuple and apply them
    /// afterwards, with no risk of one side's write racing the other's on
    /// this `@MainActor` object.
    private enum SideOutcome {
        case loaded(SongDetail, isCached: Bool)
        case failed(String)
    }

    private func loadOneSide(id: SongID) async -> SideOutcome {
        do {
            let detail = try await rootViewModel.songDetail(id: id)
            return .loaded(detail, isCached: false)
        } catch let error as APIError {
            // Same "only .offline/.maintenance ever fall back to a saved
            // copy" posture `SongDetailViewModel.loadPrimaryDetail()`
            // documents — any OTHER `APIError` means something IS actually
            // wrong, and silently masking that with a possibly-stale cached
            // copy would hide it.
            if Self.isOfflineLikeFailure(error), let cached = await rootViewModel.savedSongDetail(id) {
                return .loaded(cached, isCached: true)
            }
            return .failed(error.userFacingMessage)
        } catch {
            return .failed("Something went wrong loading this song. Please try again.")
        }
    }

    private func apply(_ outcome: SideOutcome, isPrimary: Bool) {
        switch outcome {
        case .loaded(let detail, let isCached):
            if isPrimary {
                primaryState = .loaded(detail)
                isServingCachedCopyPrimary = isCached
            } else {
                secondaryState = .loaded(detail)
                isServingCachedCopySecondary = isCached
            }
        case .failed(let message):
            if isPrimary {
                primaryState = .error(message)
            } else {
                secondaryState = .error(message)
            }
        }
    }

    /// See this file's header for why this is a deliberate copy of
    /// `SongDetailViewModel.isOfflineLikeFailure(_:)` rather than a shared
    /// cross-file helper.
    private static func isOfflineLikeFailure(_ error: APIError) -> Bool {
        switch error {
        case .offline, .maintenance:
            true
        default:
            false
        }
    }
}
