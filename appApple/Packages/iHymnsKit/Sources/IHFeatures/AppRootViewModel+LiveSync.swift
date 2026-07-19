// AppRootViewModel+LiveSync.swift
// IHFeatures
//
// ELI5: The ONE place that listens to both live engines and turns whatever
// they announce into a simple "what should the Live screen show right now?"
// value, plus the handful of buttons (join, go live, leave, "I just opened
// this song") every Live-related view actually taps.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §6.5, strategy §2.4.6, plan §2 PR-10). The PR-5 lesson applies verbatim
// here: `AsyncStream` is single-consumer, so each engine's `events` stream
// gets EXACTLY one consumer — the `TaskGroup` inside `observeLiveSyncEvents()`
// below, started once from `AppRootViewModel.init` and cancelled in
// `deinit` (`liveObservationTask`, primary file). Every UI view reads the
// published `liveState`/`liveSnapshot`, never the streams themselves.
import Foundation
import IHLive
import IHLiveActivity
import IHModels

/// What the `.live` section should currently show — derived entirely from
/// the two engines' events, never read back from either engine directly.
public enum LiveSyncUIState: Sendable, Equatable {
    case idle
    /// `currentSongTitle` is the RAW `songId` today (no title-fetch is
    /// wired for the host's own display yet — see PR-10's final report
    /// "Deviations from spec").
    case hosting(code: String, currentSongTitle: String?)
    case followingLeader(hostDisplayName: String, isFresh: Bool)
    case followingService(isFresh: Bool)
}

extension AppRootViewModel {
    /// The ONE consumer of both engines' `events` streams — an inner
    /// `TaskGroup` running two child tasks, each the sole `for await` loop
    /// over its own stream (the PR-5 single-consumer rule, applied to two
    /// DIFFERENT streams rather than one shared one).
    ///
    /// PR-10 Opus-review FIX 1 (retain cycle): neither engines' `events`
    /// stream ever calls `continuation.finish()` (they're live for the
    /// app's whole run), so each `for await` below only ends on
    /// cancellation. The ENGINES are captured STRONGLY via the capture
    /// list (`liveFollowEngine`/`serviceModeEngine` resolve to
    /// `self.liveFollowEngine`/`self.serviceModeEngine`, read ONCE while
    /// `self` is still guaranteed alive — this method is only ever called
    /// from `AppRootViewModel.init`) — the EXACT `[weak self,
    /// sessionController]` shape `observeSessionState()`
    /// (`AppRootViewModel.swift`) already uses for its own indefinite
    /// `for await`. `self` itself is captured only WEAKLY, and re-checked
    /// INSIDE the loop on every iteration (`guard let self,
    /// !Task.isCancelled else { break }`) rather than once before it. The
    /// PRE-FIX shape (`guard let self else { return }` evaluated once,
    /// then looping on `self.<engine>.events`) bound a STRONG `self` for
    /// the closure's entire suspended lifetime — and since `deinit` is
    /// what cancels `liveObservationTask` in the first place, that strong
    /// reference meant `self` could never reach `deinit`, leaking the VM
    /// + both engines + all three tasks on every instance (benign for the
    /// one lifelong production root VM, but every test-created VM leaked).
    func observeLiveSyncEvents() {
        liveObservationTask = Task { [liveFollowEngine, serviceModeEngine] in
            await withTaskGroup(of: Void.self) { group in
                group.addTask { [weak self, liveFollowEngine] in
                    for await event in liveFollowEngine.events {
                        guard let self, !Task.isCancelled else { break }
                        await self.apply(event)
                    }
                }
                group.addTask { [weak self, serviceModeEngine] in
                    for await event in serviceModeEngine.events {
                        guard let self, !Task.isCancelled else { break }
                        await self.apply(event)
                    }
                }
            }
        }
    }

    @MainActor
    func apply(_ event: LiveFollowEvent) {
        switch event {
        case .hostingStarted(let code, let sessionId):
            liveState = .hosting(code: code, currentSongTitle: nil)
            liveSnapshot = nil
            // PR-16 (#1429) — a fresh hosting session always starts a fresh
            // Live Activity context (no song broadcast yet, revision reset
            // to 0); `syncNowSingingActivity()` (`+LiveActivity.swift`)
            // diffs this against whatever was showing before (from a PRIOR
            // session, if any — Audit-B F1: the reducer's `(.some, .some)`
            // arm detects the SESSION-IDENTITY change (sessionId/sessionCode
            // differ from the prior session) and returns `.start` directly,
            // which `NowSingingActivityController.start(attributes:state:)`
            // handles correctly by ending any running activity first — no
            // special-casing needed here).
            hostSongId = nil
            hostComponentIndex = nil
            nowSingingContext = NowSingingHostContext(
                sessionCode: code, sessionId: sessionId, songId: nil, songTitle: nil, componentIndex: nil, revision: 0
            )
            syncNowSingingActivity()
            flushPendingActivityPushToken(sessionId: sessionId)
        case .hostingEnded:
            if case .hosting = liveState { liveState = .idle; liveSnapshot = nil }
            hostSongId = nil
            hostComponentIndex = nil
            nowSingingContext = nil
            // #1429 Audit-B F9 — a token parked by `registerActivityPushToken(_:)`
            // while `nowSingingContext?.sessionId` was still nil (a legacy-
            // backend session with no sessionId) must NOT survive to flush
            // against the NEXT hosting session's id — `flushPendingActivityPushToken
            // (sessionId:)` only runs from `.hostingStarted` below, so without
            // this reset a stale token from a PRIOR ended session would
            // silently register itself against a session it was never minted
            // for.
            pendingActivityPushTokenHex = nil
            syncNowSingingActivity()
        case .followingStarted(_, let hostDisplayName, let initial):
            liveState = .followingLeader(hostDisplayName: hostDisplayName, isFresh: true)
            liveSnapshot = initial
        case .followingEnded:
            if case .followingLeader = liveState { liveState = .idle; liveSnapshot = nil }
        case .snapshot(let snapshot):
            liveSnapshot = snapshot
        case .freshnessChanged(let isFresh):
            if case .followingLeader(let host, _) = liveState {
                liveState = .followingLeader(hostDisplayName: host, isFresh: isFresh)
            }
        }
    }

    @MainActor
    func apply(_ event: ServiceModeEvent) {
        switch event {
        case .joined(let initial):
            liveState = .followingService(isFresh: true)
            liveSnapshot = initial
        case .snapshot(let snapshot):
            liveSnapshot = snapshot
        case .left:
            if case .followingService = liveState { liveState = .idle; liveSnapshot = nil }
        case .freshnessChanged(let isFresh):
            if case .followingService = liveState { liveState = .followingService(isFresh: isFresh) }
        case .presenceChanged:
            break
        }
    }

    // MARK: - Façade (called directly by the Live views/sheets)

    /// The unified join (D-8, spec §6.2): tries the Service Mode namespace
    /// FIRST (venue codes rotate every 75 s — the more time-sensitive one),
    /// falling back to Live Follow only on `.codeNotActive`. Any OTHER
    /// failure (`.temporarilyUnavailable`, `.network`, mutual-exclusion)
    /// propagates immediately — trying the other namespace after a genuine
    /// "the site is down" answer would just repeat the same failure.
    ///
    /// ELI5: "Join whatever this code is for" — service first, then a
    /// leader's Live Follow code.
    public func joinWithCode(_ rawCode: String) async throws {
        let code = Self.normalizedJoinCode(rawCode)
        guard Self.isValidJoinCode(code) else { throw LiveSyncError.codeNotActive }
        do {
            _ = try await serviceModeEngine.join(code: code)
        } catch LiveSyncError.codeNotActive {
            _ = try await liveFollowEngine.join(code: code)
        }
    }

    /// "Go Live" — starts hosting with no song broadcasting yet (the host
    /// console's own "Open a song to broadcast it" footer explains the rest).
    public func goLive() async throws {
        _ = try await liveFollowEngine.goLive(songId: nil, componentIndex: nil)
    }

    /// Leaves/ends whichever live role is currently active — a no-op while
    /// `.idle`.
    public func leaveLive() async {
        switch liveState {
        case .hosting:
            await liveFollowEngine.endHosting()
        case .followingLeader:
            await liveFollowEngine.leaveFollow()
        case .followingService:
            await serviceModeEngine.leave()
        case .idle:
            break
        }
    }

    /// The native `live-follow.js initSongPage` broadcast hook (spec §6.5):
    /// call this from the ONE per-song appear path (`SongDetailView`). A
    /// fire-and-forget no-op unless this device is actually hosting.
    /// Optimistically updates the LOCAL "currently broadcasting" display
    /// immediately (rather than waiting on a network round trip + an engine
    /// event neither role emits for the host's own outgoing broadcasts).
    ///
    /// ELI5: "I just opened this song — if I'm hosting, tell everyone."
    public func liveSongViewed(_ songId: SongID) {
        if case .hosting(let code, _) = liveState {
            liveState = .hosting(code: code, currentSongTitle: songId.rawValue)
            // PR-16 (#1429) — mirror the new song onto the Live Activity
            // context too. `songTitle` reuses the RAW songId, the SAME
            // "no title-fetch is wired for the host's own display yet"
            // placeholder `liveState.hosting(currentSongTitle:)` above
            // already relies on (PR-10's own "Deviations from spec" note) —
            // never lyric text, content-gating-safe either way.
            hostSongId = songId.rawValue
            hostComponentIndex = nil
            if var context = nowSingingContext {
                context.songId = songId.rawValue
                context.songTitle = songId.rawValue
                context.componentIndex = nil
                context.revision += 1
                nowSingingContext = context
                syncNowSingingActivity()
            }
        }
        Task { [liveFollowEngine] in
            await liveFollowEngine.broadcast(songId: songId.rawValue, componentIndex: nil)
        }
    }

    /// `scenePhase` wiring (`RootContainerView`'s `.onChange(of: scenePhase)`,
    /// the `RemoteControlView.swift` precedent) — forwards to both engines;
    /// each is independently a no-op unless it's the one currently active.
    public func setLiveScenePhaseActive(_ active: Bool) {
        Task { [liveFollowEngine, serviceModeEngine] in
            await liveFollowEngine.setScenePhaseActive(active)
            await serviceModeEngine.setScenePhaseActive(active)
        }
    }

    /// Cold-start resume — call once at launch. Drives ONLY `ServiceModeEngine
    /// .resumeIfPossible()`: no `LiveFollowEngine`-side resume method exists
    /// in this PR's binding engine shape (spec §3.4/§3.5 define
    /// `resumeIfPossible()` on `ServiceModeEngine` only), so a cold-launched
    /// host/follower session is NOT restored here — see PR-10's final report
    /// "Deviations from spec."
    public func resumeLiveIfPossible() {
        Task { [serviceModeEngine] in
            await serviceModeEngine.resumeIfPossible()
        }
    }

    // MARK: - Join-code normalisation (web parity, `live-follow.js:231-239`)

    /// Uppercases, then strips everything that isn't an ASCII letter/digit —
    /// tolerates spaces/hyphens/NBSP/smart-punctuation a person might type
    /// or paste alongside a code. `nonisolated` — a pure string function
    /// with no actor state to protect, callable from any context.
    nonisolated static func normalizedJoinCode(_ raw: String) -> String {
        String(raw.uppercased().filter { $0.isASCII && ($0.isLetter || $0.isNumber) })
    }

    /// `^[A-Z0-9]{4,12}$`, applied to an ALREADY-normalised code. `nonisolated`
    /// for the same reason as `normalizedJoinCode(_:)` above.
    nonisolated static func isValidJoinCode(_ code: String) -> Bool {
        (4...12).contains(code.count) && code.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber) }
    }
}
