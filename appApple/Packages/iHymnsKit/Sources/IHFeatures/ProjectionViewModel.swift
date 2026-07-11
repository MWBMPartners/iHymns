// ProjectionViewModel.swift
// IHFeatures
//
// ELI5: The "brain" behind the TV projection screen — it knows which song
// is up, which verse/chorus/bridge and (maybe) which line is highlighted,
// and whether the screen is showing lyrics/black/the logo/a frozen shot. It
// fetches the song itself (under the TV's OWN sign-in), and it can be
// driven by ANYTHING — the Siri Remote today (this PR), a LAN phone remote
// tomorrow (PR-6), a server-following mode later (PR-15) — because it has
// no idea which of those is asking.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §4, the PR-6
// CONTRACT — every public member below is load-bearing for PR-6's bridge
// and must not be casually changed). This is the "driver-agnostic engine"
// half of the §1 seam: `TVListenerActor` (already merged, PR-4/#1420)
// relays raw remote intents up via `controlEvents`, but deliberately does
// NOT know how to resolve `nextComponent`/`nextLine`/etc into an actual new
// position — its own header comment says that's "the tvOS projection
// view-model, PR-5/PR-6's job." This type IS that job. `ProjectionResolver`
// (this same file's sibling) supplies the pure maths; this class supplies
// the STATE those functions operate on, the network fetch that turns a
// `SongID` into a real `SongDetail`, and the one canonical
// `(songId, componentIndex, lineIndex, displayState)` tuple every driver —
// including a future LAN remote — can read as "the truth."
//
// Three binding rules from the spec: (1) **never see WHO is driving** — no
// `import Network`, no `TVListenerActor` reference, no connection id
// anywhere in this file or its `+Intents.swift` sibling (the D-11
// tripwire); `ProjectionSceneView` (this same PR) is the FIRST driver,
// calling the exact same methods PR-6's LAN bridge will call later. (2)
// **canonical state = what's actually on screen** — `selectSong` publishes
// ONLY after its `song_detail` fetch succeeds; a gated/offline fetch leaves
// `song`/`position` untouched and returns a `SongResult` the caller maps
// into feedback (`.unavailable` → PR-6's `IHRPErrorCode.contentUnavailable`,
// per `IHRPPayloads.swift`'s doc comment naming this exact handoff). (3)
// **the LAN link never carries lyric text** (PR-4's binding invariant,
// `IHRPMessage.swift`'s header) — this view-model fetches `song_detail`
// itself via the INJECTED `fetchSongDetail` closure (production =
// `AppRootViewModel.songDetail(id:)`), so content gating (web rule #28)
// applies on the TV under its own auth automatically.
//
// `stateUpdates` mirrors `IHAuth.SessionController.stateUpdates` /
// `IHLive.TVListenerActor.controlEvents`'s established idiom exactly:
// `AsyncStream.makeStream(of:)` at `init`, `nonisolated let` because the
// stream/continuation pair is fixed once and never reassigned. It has
// exactly ONE intended consumer (the active driver bridge) — the canvas/UI
// instead observes the `@Observable` properties directly, the same split
// `AppRootViewModel.sessionState` already establishes against
// `SessionController.stateUpdates`.
import Foundation
import IHAPI
import IHLive
import IHLog
import IHModels
import Observation

/// The driver-agnostic tvOS projection engine — see this file's header for
/// the full contract.
@MainActor
@Observable
public final class ProjectionViewModel {

    /// The driver-facing canonical 4-tuple — EXACTLY the arguments of
    /// `TVListenerActor.updateState(songId:componentIndex:lineIndex:displayState:)`.
    ///
    /// ELI5: "Here's exactly what the TV is showing right now" — no
    /// `revision` counter, because that's `TVListenerActor`'s job to bump,
    /// not this view-model's (its own doc comment: "the view-model NEVER
    /// manages `revision`").
    public struct ProjectionState: Sendable, Equatable {
        public let songId: SongID?
        public let componentIndex: Int?
        public let lineIndex: Int?
        public let displayState: IHRPDisplayState

        public init(songId: SongID?, componentIndex: Int?, lineIndex: Int?, displayState: IHRPDisplayState) {
            self.songId = songId
            self.componentIndex = componentIndex
            self.lineIndex = lineIndex
            self.displayState = displayState
        }
    }

    /// Driver-agnostic outcome of `prepare`/`selectSong`.
    ///
    /// ELI5: "Did it work, was this song off-limits, or did something else
    /// go wrong?" — a driver (this PR's Siri-Remote picker, PR-6's LAN
    /// bridge) maps this into whatever feedback makes sense for IT: an
    /// inline error label here, an `IHRPErrorCode.contentUnavailable` frame
    /// there.
    public enum SongResult: Sendable, Equatable {
        /// Success — for `prepare`, this means "already cached," not
        /// necessarily "now on screen."
        case shown
        /// The TV's OWN `song_detail` fetch was DENIED (`APIError
        /// .unauthorized`) — content gating (CLAUDE.md rule #28) says this
        /// TV isn't entitled to this song. Canonical state is untouched.
        case unavailable
        /// Offline / maintenance / decode / any other failure — carries an
        /// already-user-facing message (`APIError.userFacingMessage`,
        /// mirroring `LoadState.error`'s own "never the raw `APIError`"
        /// convention). Also used for a superseded (`.failed("superseded")`)
        /// `selectSong` call — see `selectSong(songId:componentIndex:lineIndex:)`.
        case failed(String)
    }

    // MARK: - Observed state (read by `ProjectionSceneView`/`ProjectionCanvasView` — never by drivers)

    /// The song currently loaded for projection — `nil` before anything has
    /// ever been selected (the TV's idle/"choose a song" screen).
    public private(set) var song: SongDetail?

    /// Where in `song` the projection is — see `ProjectionPosition`'s own
    /// doc comment for the component-mode/line-mode distinction.
    ///
    /// `internal(set)` (not `private(set)`) — `ProjectionViewModel+Intents.swift`
    /// is a SEPARATE FILE that mutates this via every relative navigation
    /// intent; Swift's `private` is file-scoped even for a type's own
    /// extensions (the identical cross-file-visibility note
    /// `AppRootViewModel.recentSearches`/`APIClient.session` already
    /// document in this package).
    public internal(set) var position: ProjectionPosition = .none

    /// What the projection surface is currently drawing —
    /// `IHRPDisplayState` (`IHLive`), REUSED rather than re-declared
    /// (Decision D-4: a parallel `ProjectionDisplayState` would be the exact
    /// vocabulary re-fork the plan forbids).
    ///
    /// `internal(set)` — mutated from `+Intents.swift`'s `setDisplayState`,
    /// same cross-file reasoning as `position` above.
    public internal(set) var displayState: IHRPDisplayState = .lyrics

    /// Whether a `selectSong` fetch is currently in flight — `ProjectionSceneView`
    /// shows a `ProgressView` over black while this is `true`. Mutated only
    /// from THIS file (`selectSong`/`prepare`'s error paths), so
    /// `private(set)` is correct here (unlike `position`/`displayState` above).
    public private(set) var isLoadingSong = false

    /// A user-controlled size multiplier on top of the base projection
    /// scale (`ProjectionCanvasView`'s `projectionBaseScale` constant) —
    /// clamped `0.5...3.0` by `setAppearance(theme:textScale:)`.
    ///
    /// `internal(set)` — mutated from `+Intents.swift`'s `setAppearance`,
    /// same cross-file reasoning as `position` above.
    public internal(set) var textScale: Double = 1.0

    /// A cosmetic, TV-local theme hint from `setAppearance(theme:textScale:)`
    /// — stored but DELIBERATELY NEVER APPLIED (Decision D-6: the
    /// projection surface is a fixed black full-bleed; theming it would
    /// undermine 10-foot legibility). Recorded here purely so a future PR
    /// that DOES want to act on it has somewhere to read it from, without
    /// this PR guessing what "applying" a TV theme should even mean.
    ///
    /// `internal(set)` — same cross-file reasoning as `position` above.
    public internal(set) var appearanceThemeHint: String?

    /// What the canvas should actually RENDER — equal to `(song, position)`
    /// except while `.frozen`, when it's the snapshot captured at the
    /// instant freezing began (§4.3's frozen-snapshot rule: canonical state
    /// keeps moving behind a freeze, but the picture on screen does not).
    ///
    /// ELI5: "What should actually be drawn right now" — which is NOT
    /// always the same as "where we technically are," because a frozen
    /// screen intentionally lags behind.
    public var renderedContent: (song: SongDetail?, position: ProjectionPosition) {
        frozenSnapshot ?? (song, position)
    }

    /// The current canonical 4-tuple — what `stateUpdates` yields (and what
    /// PR-6's bridge feeds straight into `TVListenerActor.updateState(...)`).
    public var projectionState: ProjectionState {
        ProjectionState(songId: song?.songId, componentIndex: position.componentIndex, lineIndex: position.lineIndex, displayState: displayState)
    }

    /// Driver-facing feed — one `ProjectionState` per ACTUAL change
    /// (Equatable-deduped by `+Intents.swift`'s `move(_:)`/`setDisplayState`
    /// — a clamped no-op intent yields nothing at all, so a driver never
    /// re-broadcasts/burns a revision counter for nothing). Exactly ONE
    /// intended consumer — `AsyncStream` delivers each element to a single
    /// iterator — mirroring `SessionController.stateUpdates`'s identical
    /// "the UI reads the `@Observable` mirror instead" split.
    nonisolated public let stateUpdates: AsyncStream<ProjectionState>

    // MARK: - Internal plumbing (not part of the PR-6 contract)

    /// Fetches one full song by id — production wires this to
    /// `AppRootViewModel.songDetail(id:)` (`TVRootView`'s own composition);
    /// tests inject a canned closure (the `SongDetailViewModel.mediaFetcher`
    /// precedent this spec calls out).
    let fetchSongDetail: @Sendable (SongID) async throws -> SongDetail

    /// Paired with `stateUpdates` at `init` — see that property's doc
    /// comment. `internal` so `+Intents.swift`'s `publish()` can yield into it.
    let continuation: AsyncStream<ProjectionState>.Continuation

    /// `prepare(songId:)`'s cache — capacity 3, oldest evicted first
    /// (strategy §2.4.4: "a cold select adds one API RTT which `prepare`
    /// hides"). `@ObservationIgnored` on this and every plumbing property
    /// below: pure internal bookkeeping a SwiftUI view should never
    /// re-render over (mirrors `AppRootViewModel.hasLoadedFavoritesFromCache`'s
    /// identical posture) — every user-visible effect surfaces through
    /// `song`/`position`/`displayState`, which ARE tracked.
    @ObservationIgnored
    var preparedSongs: [SongID: SongDetail] = [:]

    /// Insertion order for `preparedSongs`'s cap-3 eviction — oldest id is
    /// `preparedOrder.first`.
    @ObservationIgnored
    var preparedOrder: [SongID] = []

    /// Cap for `preparedSongs`/`preparedOrder` — a named constant rather
    /// than a magic `3` at each call site.
    private static let preparedSongCapacity = 3

    /// Latest-wins counter for overlapping `selectSong` calls (§4.2) — a
    /// superseded fetch's completion is detected by comparing its captured
    /// generation against this LIVE counter.
    @ObservationIgnored
    var fetchGeneration: UInt64 = 0

    /// The `(song, position)` captured the instant `.frozen` was entered —
    /// `nil` whenever NOT frozen. Entering/leaving `.frozen` always ALSO
    /// changes `displayState` (which IS tracked), so a SwiftUI view watching
    /// `displayState`/`renderedContent` still re-renders at exactly the
    /// right moments — see `setDisplayState(_:)` in `+Intents.swift`.
    @ObservationIgnored
    var frozenSnapshot: (song: SongDetail?, position: ProjectionPosition)?

    /// The loaded song's per-component line counts — `ProjectionResolver`'s
    /// only input besides `position`. Recomputed on every successful load;
    /// `ProjectionSongMap(componentLineCounts: [])` (the harmless empty
    /// case) before any song has ever loaded.
    @ObservationIgnored
    var songMap = ProjectionSongMap(componentLineCounts: [])

    /// - Parameter fetchSongDetail: Injected so this view-model never
    ///   constructs its own `APIClient`/`AppRootViewModel` — the SAME
    ///   "engines are injected, never self-constructed" rule
    ///   `AppRootViewModel.init`'s own header documents.
    public init(fetchSongDetail: @escaping @Sendable (SongID) async throws -> SongDetail) {
        self.fetchSongDetail = fetchSongDetail
        let (stream, continuation) = AsyncStream.makeStream(of: ProjectionState.self)
        self.stateUpdates = stream
        self.continuation = continuation
    }

    /// "I'm about to `selectSong` this — start fetching it now" (strategy
    /// §2.4.4: "a cold select adds one API RTT which `prepare` hides").
    ///
    /// ELI5: "Get this song ready in the background, but don't show it yet."
    ///
    /// DETAILED: NEVER mutates `song`/`position`/`displayState` and NEVER
    /// publishes — this is purely a cache-warming call. `.shown` covers
    /// BOTH "already the current song" and "already cached" (spec §4.2's
    /// "if already loaded or cached → `.shown`"); a genuine fetch failure
    /// maps through the identical `mapSelectError(_:songId:)` `selectSong`
    /// uses, so the two intents never drift in how they describe the same
    /// underlying failure.
    @discardableResult
    public func prepare(songId: SongID) async -> SongResult {
        if let song, song.songId == songId { return .shown }
        if preparedSongs[songId] != nil { return .shown }
        do {
            let detail = try await fetchSongDetail(songId)
            cachePrepared(detail, songId: songId)
            return .shown
        } catch {
            return mapSelectError(error, songId: songId)
        }
    }

    /// "Show this song," optionally jumping straight to a component/line —
    /// the ONE intent that can change WHICH song is projected.
    ///
    /// ELI5: "Put this song on screen" — reusing whatever's already loaded
    /// or cached where possible, otherwise fetching it for real, and never
    /// changing what's on screen until that fetch actually succeeds.
    ///
    /// DETAILED (§4.2's algorithm, verbatim):
    /// 1. Already projecting this exact song → NO fetch, reposition only.
    /// 2. Otherwise resolve a `SongDetail` — `preparedSongs[songId]` if
    ///    cached, else a real network fetch — behind a `fetchGeneration`
    ///    latest-wins guard: if a NEWER `selectSong` call won the race while
    ///    this one was suspended on `await`, this call returns
    ///    `.failed("superseded")` WITHOUT touching any state at all — the
    ///    newer call owns it (spec: "canonical state and `song` change only
    ///    on the winning, successful fetch").
    /// 3. On success: load the song, reposition, publish — `applyLoadedSong(_:componentIndex:lineIndex:)`.
    /// 4. On failure: state stays UNTOUCHED — `mapSelectError(_:songId:)`.
    @discardableResult
    public func selectSong(songId: SongID, componentIndex: Int? = nil, lineIndex: Int? = nil) async -> SongResult {
        if let song, song.songId == songId {
            position = repositioned(componentIndex: componentIndex, lineIndex: lineIndex)
            publish()
            return .shown
        }

        fetchGeneration += 1
        let generation = fetchGeneration
        isLoadingSong = true

        do {
            let detail: SongDetail
            if let cached = preparedSongs[songId] {
                detail = cached
            } else {
                detail = try await fetchSongDetail(songId)
            }
            guard generation == fetchGeneration else { return .failed("superseded") }
            applyLoadedSong(detail, componentIndex: componentIndex, lineIndex: lineIndex)
            return .shown
        } catch {
            guard generation == fetchGeneration else { return .failed("superseded") }
            isLoadingSong = false
            return mapSelectError(error, songId: songId)
        }
    }

    /// The shared "which position does `(componentIndex, lineIndex)` mean"
    /// resolution both `selectSong` branches use — both nil ⇒ "from the
    /// top" (`ProjectionResolver.initialPosition`), otherwise an explicit
    /// clamp (`ProjectionResolver.clamped`).
    private func repositioned(componentIndex: Int?, lineIndex: Int?) -> ProjectionPosition {
        guard componentIndex != nil || lineIndex != nil else {
            return ProjectionResolver.initialPosition(map: songMap)
        }
        return ProjectionResolver.clamped(componentIndex: componentIndex, lineIndex: lineIndex, map: songMap)
    }

    /// The winning-fetch success path shared by `selectSong`'s cached and
    /// network branches — loads `detail` as the projected song, recomputes
    /// `songMap`, repositions, clears the loading flag, and publishes.
    /// Deliberately does NOT touch `displayState`/`frozenSnapshot` — §4.3's
    /// "if `displayState == .frozen` leave it frozen": an operator can cue a
    /// new song behind an active freeze without it flashing onto screen.
    private func applyLoadedSong(_ detail: SongDetail, componentIndex: Int?, lineIndex: Int?) {
        song = detail
        songMap = ProjectionSongMap(detail: detail)
        position = repositioned(componentIndex: componentIndex, lineIndex: lineIndex)
        isLoadingSong = false
        publish()
        IHLog.remote.notice("projection.select id=\(detail.songId.rawValue, privacy: .public)")
    }

    /// Inserts `detail` into `preparedSongs`, evicting the oldest cached
    /// entry once the cap-3 capacity (strategy §2.4.4) is exceeded.
    private func cachePrepared(_ detail: SongDetail, songId: SongID) {
        if preparedSongs[songId] == nil {
            preparedOrder.append(songId)
        }
        preparedSongs[songId] = detail
        while preparedOrder.count > Self.preparedSongCapacity {
            let evicted = preparedOrder.removeFirst()
            preparedSongs.removeValue(forKey: evicted)
        }
    }

    /// The ONE error-to-`SongResult` mapping both `prepare` and `selectSong`
    /// use (§4.2 point 4) — `.unauthorized` is content gating denying the
    /// TV (CLAUDE.md rule #28); every other `APIError` gets its existing
    /// `userFacingMessage` (`APIError+UserFacing.swift`); anything else gets
    /// a generic apology. Logs the RESULT KIND only, per this file's own
    /// "never the raw response" rule — never the underlying error's own
    /// description, which could carry a URL/response body.
    private func mapSelectError(_ error: Error, songId: SongID) -> SongResult {
        if let apiError = error as? APIError, apiError == .unauthorized {
            IHLog.remote.error("projection.select failed id=\(songId.rawValue, privacy: .public) kind=unauthorized")
            return .unavailable
        }
        if let apiError = error as? APIError {
            IHLog.remote.error("projection.select failed id=\(songId.rawValue, privacy: .public) kind=apiError")
            return .failed(apiError.userFacingMessage)
        }
        IHLog.remote.error("projection.select failed id=\(songId.rawValue, privacy: .public) kind=other")
        return .failed("Something went wrong loading this song. Please try again.")
    }

    // Relative navigation intents (`nextComponent`/`prevComponent`/
    // `nextLine`/`prevLine`/`jumpLine`/`scroll`), `setDisplayState` (the
    // frozen-snapshot rule), `setAppearance`, and the shared `publish()`
    // helper live in `ProjectionViewModel+Intents.swift` — split out purely
    // to keep this file under the repo's LOC-budget tripwire
    // (`Scripts/loc-budget.sh`), the same "same-type extension in its own
    // file" split `TVListenerActor`/`TVListenerActor+Messages.swift` and
    // `APIClient`/`APIClient+Networking.swift` already establish.
}
