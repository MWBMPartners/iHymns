// SongbookBulkSaveViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the songbook screen's "Save All for
// Offline" button — goes through every song in the book ONE AT A TIME,
// fetching and saving each one exactly like the per-song "Save for Offline"
// button already does, keeping count as it goes so the screen can show
// "Saving 12 of 48…", and remembering which songs (if any) it couldn't save
// instead of giving up on the whole book over one bad song.
//
// DETAILED: #1439 ("songbook-level 'Save All for Offline'"). A focused,
// screen-scoped `@Observable @MainActor` view model — mirrors
// `OfflineStorageViewModel`'s "one small view model per concern" shape
// (that file's own header: a screen gets its own view model rather than
// growing `AppRootViewModel` with state nothing else needs) — constructed
// fresh by `SongbookBulkSaveView` for exactly one bulk-save run, the same
// "per-screen, not app-wide" lifetime `SongDetailViewModel` already
// establishes for a single song.
//
// Deliberately reuses, never re-implements, the EXISTING per-song save
// path (this issue's own "Rough approach": "a bulk action would just call
// [`OfflineStore.saveSongForOffline(_:)`] in a loop"): every song goes
// through the SAME `AppRootViewModel.songDetail(id:)` network fetch and
// `AppRootViewModel.saveSongForOffline(_:)` persistence call
// `SongDetailViewModel.toggleOfflineSave()` uses, and — when the caller
// opts in — the SAME `AppRootViewModel.cacheAllMedia(for:fetcher:)` media
// download loop (#1439 extracted that loop out of
// `SongDetailViewModel.cacheMediaForOffline(_:)` into `AppRootViewModel`
// itself specifically so this type could reuse it — see
// `AppRootViewModel+MediaCache.swift`'s header). This type adds ONLY the
// orchestration `OfflineStore`/`SongDetailViewModel` don't already offer:
// sequencing many songs, progress bookkeeping, cancellation, and
// per-song failure collection.
//
// MEDIA IS OPT-IN, NOT AUTOMATIC — a deliberate decision, not an oversight.
// A songbook can run to 100+ songs; if EVERY song also carries an audio
// recording (commonly several MB each), an unconditional "also grab all the
// media" default could silently commit a user on a metered connection to a
// multi-hundred-MB download with no warning. `SongbookBulkSaveView`
// surfaces this as an explicit toggle the user must opt into (with size
// context in its copy) before starting a run — the same "ask before a
// large, hard-to-undo action" posture `OfflineStorageView`'s "Remove All
// Downloads" confirmation already takes for the inverse operation.
//
// SEQUENTIAL, NOT CONCURRENT — every song is fetched/saved one at a time,
// not in parallel. Three reasons: (1) it keeps "Saving X of Y" an honest,
// monotonically-increasing counter rather than a set of racing completions
// that would need their own reconciliation; (2) it never opens N simultaneous
// connections against the shared API for a single user action, which would
// be an unfriendly (and, at high N, potentially rate-limited) way to treat
// a backend other users' requests also have to queue behind; (3) it makes
// cancellation trivial — see `cancel()` below.
//
// CANCELLATION — `Task.cancel()` on the run loop's own `Task` is sufficient
// on its own: Apple's `URLSession` async APIs (`data(from:)`, which
// `AppRootViewModel.songDetail(id:)`/`cacheAllMedia(for:fetcher:)`
// transitively call) observe the calling `Task`'s cancellation and abort
// the in-flight HTTP request, so a cancel takes effect within the CURRENT
// song's fetch, not just at the next song boundary.
import Foundation
import IHAPI
import IHModels
import Observation

/// Drives a "save every song in this songbook for offline reading" run —
/// sequenced, progress-tracked, cancellable, and resilient to individual
/// song failures.
///
/// ELI5: "Save this whole songbook, and tell me how it's going."
@MainActor
@Observable
public final class SongbookBulkSaveViewModel {
    /// One song this run failed to save, and why — collected rather than
    /// aborting the whole run, per this issue's own "one song failing
    /// doesn't abort the rest" requirement.
    public struct Failure: Sendable, Identifiable, Hashable {
        public let id: SongID
        public let title: String
        public let message: String
    }

    /// Where a bulk-save run currently stands.
    public enum Phase: Sendable, Equatable {
        /// Nothing started yet — `SongbookBulkSaveView`'s initial "confirm
        /// and start" state.
        case idle
        /// A run is actively working through `totalCount` songs.
        case running
        /// Every song was attempted (some may have failed — see `failures`)
        /// and the run was not cancelled.
        case finished
        /// The user cancelled the run partway through.
        case cancelled
    }

    /// See `Phase` above — `SongbookBulkSaveView` switches its whole body on
    /// this.
    public private(set) var phase: Phase = .idle

    /// How many songs this run has FINISHED attempting (success or failure)
    /// — `SongbookBulkSaveView`'s "Saving 12 of 48…" progress copy reads
    /// this directly.
    public private(set) var completedCount = 0

    /// How many songs this run started with — fixed for the lifetime of one
    /// run (a songbook can't change size mid-save; this view model is
    /// constructed fresh per run, mirroring `SongDetailViewModel`'s own
    /// per-screen, not app-wide, lifetime).
    public private(set) var totalCount = 0

    /// Every song this run couldn't save, in the order each failure
    /// happened — `SongbookBulkSaveView`'s post-run failure summary reads
    /// this; empty means every attempted song saved successfully.
    public private(set) var failures: [Failure] = []

    /// `completedCount / totalCount`, safe against a zero-song songbook —
    /// feeds a determinate `ProgressView(value:)` directly.
    public var fractionCompleted: Double {
        totalCount == 0 ? 0 : Double(completedCount) / Double(totalCount)
    }

    /// The run loop's own `Task` — held so `cancel()` can cancel it; see
    /// this file's header for why a plain `Task.cancel()` is sufficient to
    /// abort even a currently in-flight network fetch.
    @ObservationIgnored
    private var runTask: Task<Void, Never>?

    private let rootViewModel: AppRootViewModel

    /// Fetches one media asset's raw bytes — defaults to a real
    /// `URLSession` fetch; tests inject a canned closure instead, mirroring
    /// `SongDetailViewModel`'s identical `mediaFetcher` parameter (this type
    /// hands the SAME closure straight through to
    /// `AppRootViewModel.cacheAllMedia(for:fetcher:)`, never fetching bytes
    /// itself).
    private let mediaFetcher: @Sendable (URL) async throws -> Data

    public init(
        rootViewModel: AppRootViewModel,
        mediaFetcher: @escaping @Sendable (URL) async throws -> Data = { url in
            try await URLSession.shared.data(from: url).0
        }
    ) {
        self.rootViewModel = rootViewModel
        self.mediaFetcher = mediaFetcher
    }

    /// Starts saving `songs` for offline reading — one full `SongDetail`
    /// fetch + save per song, plus (when `includeMedia` is `true`) that
    /// song's attached media. Re-entrant-safe: calling this again while a
    /// run is already in progress cancels the previous run first, mirroring
    /// `SongDetailViewModel.load()`'s own "a fresh call always wins" posture
    /// for its (unrelated) primary/secondary network loads.
    ///
    /// ELI5: "Go save every one of these songs, right now."
    public func start(songs: [SongSummary], includeMedia: Bool) {
        runTask?.cancel()
        completedCount = 0
        totalCount = songs.count
        failures = []
        phase = .running

        guard !songs.isEmpty else {
            phase = .finished
            return
        }

        runTask = Task { [weak self] in
            await self?.run(songs: songs, includeMedia: includeMedia)
        }
    }

    /// Cancels an in-progress run — `SongbookBulkSaveView`'s "Cancel"
    /// button. A harmless no-op if nothing is running (`phase` isn't
    /// `.running`).
    ///
    /// ELI5: "Stop saving — I've got enough" / "never mind."
    public func cancel() {
        guard phase == .running else { return }
        runTask?.cancel()
        phase = .cancelled
    }

    /// The actual sequential save loop — see this file's header for why
    /// sequential (not concurrent) and why a `Task` cancellation alone is
    /// enough to abort promptly.
    private func run(songs: [SongSummary], includeMedia: Bool) async {
        for song in songs {
            guard !Task.isCancelled else { break }
            do {
                let detail = try await rootViewModel.songDetail(id: song.songId)
                try await rootViewModel.saveSongForOffline(detail)
                if includeMedia {
                    await rootViewModel.cacheAllMedia(for: detail, fetcher: mediaFetcher)
                }
            } catch {
                // A cancellation surfacing as a thrown error (rather than
                // `Task.isCancelled` alone) is still a CANCELLATION, not a
                // per-song failure worth reporting — falling through to
                // `failures.append` here would misreport "cancelled mid-fetch"
                // as "this song is broken."
                guard !Task.isCancelled else { break }
                failures.append(Failure(id: song.songId, title: song.title, message: Self.message(for: error)))
            }
            completedCount += 1
        }
        phase = Task.isCancelled ? .cancelled : .finished
    }

    /// Renders any thrown error as short, user-facing copy for one failed
    /// song's row — reuses `APIError.userFacingMessage`
    /// (`APIError+UserFacing.swift`) for the common case, same as every
    /// other error-surfacing call site in this package.
    private static func message(for error: Error) -> String {
        if let apiError = error as? APIError {
            return apiError.userFacingMessage
        }
        return "Couldn't save this song."
    }
}
