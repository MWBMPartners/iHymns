// TVServiceFollowCoordinator.swift
// IHFeatures
//
// ELI5: The one object the tvOS "Live" tab talks to — join with a venue
// code, watch what phase we're in (typing a code / joining / following /
// the service ended), and quietly keep the projection screen showing
// whatever the service is currently showing.
//
// DETAILED: Apple Phase-2 PR-15 (#1428; `.claude/apple-native-strategy.md`
// §2.4.7 — "(b) tvOS server-following projector... = server-mediated").
// The composition root for the THIRD `ProjectionViewModel` driver
// (`TVServiceFollowBridge.swift`'s own header: Siri Remote → PR-5, LAN
// remote → PR-6, this server-poll loop → PR-15) — the exact "everything
// that touches BOTH the engine AND `ProjectionViewModel`" role
// `TVRemoteControlCoordinator` already establishes for the LAN remote,
// mirrored here for `ServiceModeEngine`.
//
// **A SECOND, independent `ServiceModeEngine` instance** — never the root
// `AppRootViewModel`'s own engine. `AppRootViewModel+LiveSync.swift`'s
// `observeLiveSyncEvents()` is already the ONE consumer of the ROOT view
// model's `serviceModeEngine.events` (that file's own doc comment: "each
// engine's `events` stream gets EXACTLY one consumer"); `AsyncStream` is
// single-consumer, so a projector following the SAME session as a second
// presence needs its OWN engine, constructed by `TVRootView` and injected
// here — this coordinator is that engine's ONE consumer, applying the
// SAME "engines captured strongly via the capture list, self captured
// weakly and re-checked every iteration" shape `observeLiveSyncEvents()`
// documents for the identical retain-cycle reason.
//
// Content-gating-safe: every mutation below flows through
// `TVServiceFollowBridge`, which never sees lyric text (that file's own
// header) — this coordinator adds no content of its own, only phase/notice
// bookkeeping for the UI.
import IHLive
import IHModels
import Observation

/// Drives the tvOS Live tab: join-by-code, phase tracking, and keeping the
/// injected `ProjectionViewModel` in sync with the server's broadcasts.
///
/// ELI5: "Here's a code — join the service, tell me what's happening, and
/// keep the big screen showing whatever the service says."
@MainActor
@Observable
public final class TVServiceFollowCoordinator {

    /// What the Live tab should currently render — `TVServiceFollowView`'s
    /// (this same PR) `switch` target.
    public enum FollowPhase: Equatable {
        /// Nothing joined yet — the join form.
        case idle
        /// A `join(code:)` call is in flight.
        case joining
        /// Actively following a session; `isFresh` mirrors the 180 s
        /// freshness window (`LiveFollowerReducer.isFresh`) exactly like
        /// `LiveSyncUIState.followingService(isFresh:)` already does for the
        /// phone/web congregant clients.
        case following(isFresh: Bool)
        /// The session ended for a reason OTHER than this device leaving on
        /// purpose (`reason != .userLeft` — see `phase(after:current:)`).
        case ended(LiveSyncEndReason)
    }

    public private(set) var phase: FollowPhase = .idle
    /// A themed `join(code:)` failure — cleared at the start of every new
    /// attempt. Copy mirrors `LiveJoinSheet.join()`'s existing two-message
    /// convention (temporarily-unavailable vs. everything else) verbatim,
    /// so a venue operator sees the SAME wording on the TV as a congregant
    /// sees on their phone.
    public private(set) var joinErrorMessage: String?
    /// A transient, calm explanation for why the CURRENTLY broadcast song
    /// isn't on screen (gated, unparsable D-13 legacy id, or any other
    /// fetch failure) — `nil` whenever the last snapshot's song is showing
    /// normally. Never the raw underlying error text (this file's header).
    public private(set) var songNotice: String?

    /// Whether the projector actually has a song loaded right now —
    /// `TVServiceFollowView`'s "Waiting for the first song…" empty state
    /// shows exactly when this is `false` while `.following`. Reads
    /// `projectionViewModel.song` directly (the SAME "what's actually on
    /// screen" source of truth `renderedContent`/`ProjectionSceneView
    /// .showPicker` already read from) rather than tracking a second,
    /// possibly-stale copy of "do we have a song" here.
    public var hasSnapshotSong: Bool { projectionViewModel.song != nil }

    private let projectionViewModel: ProjectionViewModel
    private let engine: ServiceModeEngine
    private var hasStarted = false
    private var eventsTask: Task<Void, Never>?

    public init(projectionViewModel: ProjectionViewModel, engine: ServiceModeEngine) {
        self.projectionViewModel = projectionViewModel
        self.engine = engine
    }

    /// Spawns the ONE consumer of `engine.events`, then attempts a
    /// cold-start resume — idempotent (a second call is a harmless no-op),
    /// mirroring `TVRemoteControlCoordinator.start()`'s identical
    /// "guarded one-shot" convention. The consumer is spawned FIRST so a
    /// `.joined` yielded by `resumeIfPossible()` is never missed — though
    /// `AsyncStream`'s default unbounded buffering would deliver it either
    /// way, ordering it this way costs nothing and reads as obviously safe.
    ///
    /// ELI5: "Start listening for what the service says, then check if we
    /// were already following something before this launch."
    public func start() async {
        guard !hasStarted else { return }
        hasStarted = true
        spawnEventsConsumer()
        await engine.resumeIfPossible()
    }

    /// Joins a service as the venue's PROJECTOR (`role: .projector`) — code
    /// normalisation/validation REUSES `AppRootViewModel`'s existing
    /// `normalizedJoinCode(_:)`/`isValidJoinCode(_:)` (never re-forked,
    /// same-module `internal` visibility) exactly like `LiveJoinSheet`
    /// does today for the phone congregant flow.
    ///
    /// ELI5: "Try to join this service as the venue screen."
    public func join(code rawCode: String) async {
        joinErrorMessage = nil
        songNotice = nil
        let code = AppRootViewModel.normalizedJoinCode(rawCode)
        guard AppRootViewModel.isValidJoinCode(code) else {
            joinErrorMessage = "That code isn't active right now. Check the screen and try again."
            return
        }
        phase = .joining
        do {
            _ = try await engine.join(code: code, role: .projector)
            // On success `phase` is NOT set here — the events consumer's
            // `phase(after:current:)` flips it to `.following` the moment
            // it processes the `.joined` event `engine.join(...)` itself
            // yields, the SAME "let the event stream be the one source of
            // truth for phase" discipline `AppRootViewModel+LiveSync.swift
            // .apply(_:)` already follows for `liveState`.
        } catch LiveSyncError.temporarilyUnavailable {
            phase = .idle
            joinErrorMessage = "iHymns is briefly unavailable — try the code again in a minute. The code is fine."
        } catch {
            // `.codeNotActive`/`.network` (`LiveJoinSheet.join()`'s own
            // identical catch-all) — the server's opaque-404 anti-probe
            // means these two are indistinguishable to the user anyway.
            phase = .idle
            joinErrorMessage = "That code isn't active right now. Check the screen and try again."
        }
    }

    /// The Live tab's "Leave" action.
    public func leave() async {
        await engine.leave()
    }

    /// Scene-visibility wiring (`ServiceModeEngine.setFollowingIdle(_:)`'s
    /// idle-floor cadence row) — `TVServiceFollowView`'s own
    /// `onAppear`/`onDisappear` calls this, not a `scenePhase` change
    /// (that's `setScenePhaseActive(_:)` below); the two are independent
    /// signals (a tab can be backgrounded by the APP going to the
    /// background, OR by the user simply switching to a different tvOS
    /// tab while the app stays frontmost).
    public func setSurfaceVisible(_ visible: Bool) {
        Task { [engine] in await engine.setFollowingIdle(!visible) }
    }

    /// `scenePhase` wiring — same fire-and-forget `Task` shape
    /// `AppRootViewModel.setLiveScenePhaseActive(_:)` already establishes.
    public func setScenePhaseActive(_ active: Bool) {
        Task { [engine] in await engine.setScenePhaseActive(active) }
    }

    // MARK: - Events consumer

    /// The retain-cycle-safe shape `AppRootViewModel+LiveSync.swift
    /// .observeLiveSyncEvents()` documents in full: `engine` captured
    /// STRONGLY (this coordinator's whole reason to exist is to keep
    /// consuming it), `self` captured WEAKLY and re-checked every
    /// iteration — once this coordinator deallocates, the loop simply
    /// exits on its next event rather than being pinned alive forever.
    private func spawnEventsConsumer() {
        eventsTask = Task { [weak self, engine] in
            for await event in engine.events {
                guard let self, !Task.isCancelled else { break }
                await self.handle(event)
            }
        }
    }

    private func handle(_ event: ServiceModeEvent) async {
        phase = Self.phase(after: event, current: phase)
        switch event {
        case .joined(let snapshot), .snapshot(let snapshot):
            await applySnapshotAndUpdateNotice(snapshot)
        case .left:
            songNotice = nil
        case .freshnessChanged, .presenceChanged:
            break
        }
    }

    /// Applies ONE snapshot to `projectionViewModel` and derives
    /// `songNotice` from the outcome. Computes `TVServiceFollowBridge
    /// .plan(for:current:)` directly FIRST (rather than only calling
    /// `apply(_:to:)`) purely to reach `FollowPlan.unparsableSongId` — the
    /// D-13 calm-notice signal `apply(_:to:)`'s own fixed `SongResult?`
    /// return shape doesn't carry (that type only describes a `selectSong`
    /// OUTCOME, not "we didn't even attempt one, and here's why"); both
    /// calls are pure and read the IDENTICAL `snapshot`/`projectionState`
    /// with no `await` between them, so there is no race between the two.
    ///
    /// ELI5/DETAILED (#1562 correctness sweep, F-1): `plan.displayState` is
    /// applied HERE, BEFORE the D-13 unparsable-songId early return — a
    /// blackout/logo change riding the SAME snapshot as an unparsable
    /// legacy songId must still land on the projector; only the SELECTION
    /// half of the snapshot is legitimately skipped for D-13. Mirrors
    /// `TVServiceFollowBridge.apply(_:to:)`'s own "displayState FIRST" rule
    /// (that function's doc comment) — this direct-plan path was the one
    /// place that rule had gone missing, because the early return sat
    /// ABOVE the apply instead of below it.
    ///
    /// `internal`, not `private` (#1562) — the SAME "small, independently
    /// testable seam" convention `RemoteControlCoordinator.swift`/
    /// `AppRootViewModel+LiveSync.swift .apply(_:)` already document, so
    /// `TVServiceFollowCoordinatorTests` can drive this directly with a
    /// fabricated `LiveBroadcastSnapshot` rather than standing up a real
    /// `ServiceModeEngine` + network mock just to reach a private method.
    func applySnapshotAndUpdateNotice(_ snapshot: LiveBroadcastSnapshot) async {
        let plan = TVServiceFollowBridge.plan(for: snapshot, current: projectionViewModel.projectionState)
        if let displayState = plan.displayState {
            projectionViewModel.setDisplayState(displayState)
        }
        if plan.unparsableSongId != nil {
            songNotice = "This song can't be displayed on this TV."
            return
        }
        guard let result = await TVServiceFollowBridge.apply(snapshot, to: projectionViewModel) else { return }
        switch result {
        case .shown:
            songNotice = nil
        case .unavailable:
            songNotice = "Sign in on this TV to show this song."
        case .failed:
            // Never echoed verbatim on a shared venue screen — the SAME
            // "never leak the underlying message" posture
            // `TVProjectionBridge`'s `.failed` branch already takes for the
            // LAN remote's wire reply.
            songNotice = "This song can't be displayed on this TV."
        }
    }

    // MARK: - Pure phase table

    /// The exhaustive `ServiceModeEvent` → `FollowPhase` transition table —
    /// pure, so `TVServiceFollowUIStateTests.swift` can exercise every row
    /// without a real engine.
    ///
    /// ELI5: "Given what just happened and what phase we were in, what
    /// phase are we in now?"
    nonisolated static func phase(after event: ServiceModeEvent, current: FollowPhase) -> FollowPhase {
        switch event {
        case .joined:
            return .following(isFresh: true)
        case .snapshot, .presenceChanged:
            return current
        case .left(let reason):
            switch reason {
            case .userLeft:
                return .idle
            case .serverEnded, .superseded, .expired, .signedOut:
                return .ended(reason)
            }
        case .freshnessChanged(let isFresh):
            guard case .following = current else { return current }
            return .following(isFresh: isFresh)
        }
    }
}
