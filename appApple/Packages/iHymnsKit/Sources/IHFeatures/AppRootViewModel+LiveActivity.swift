// AppRootViewModel+LiveActivity.swift
// IHFeatures
//
// ELI5: The bridge between "what's happening in this Live Follow hosting
// session" (the stuff `AppRootViewModel+LiveSync.swift` already tracks) and
// "what should the Lock Screen / Dynamic Island card show" — plus the ONE
// button handler that lets a tap on that card's Next/Previous buttons
// actually advance the song.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Deliberately NOT a
// second consumer of `liveFollowEngine.events` — `AppRootViewModel
// +LiveSync.swift`'s `apply(_ event: LiveFollowEvent)` (the ONE real
// consumer, per the PR-5/PR-10 "AsyncStream is single-consumer" lesson its
// own header documents) calls `syncNowSingingActivity()` below directly
// from its `.hostingStarted`/`.hostingEnded` branches, and `liveSongViewed
// (_:)` calls it from its own `.hosting` branch — this file only ever
// REACTS to state those call sites already own, never subscribes to
// anything on its own.
//
// `syncNowSingingActivity()` is the ONE place `nowSingingActivity?.apply(_:)`
// is ever called — every mutation site below updates `nowSingingContext`
// then calls it, mirroring the reducer-then-effect split
// `NowSingingActivityReducer`'s own header documents.
import Foundation
import IHLive
import IHLiveActivity
import IHModels

extension AppRootViewModel {
    // MARK: - Wiring (called once from `AppRootViewModel.init`)

    /// Registers this view model as the Live Activity's Next/Previous
    /// button handler, and (when a real controller was injected) its push-
    /// token listener. `[weak self]` throughout — a torn-down view model
    /// must never leave a dangling strong reference in `NowSingingIntentBridge`
    /// .handler`'s process-wide static, or in the controller's own closure
    /// property.
    ///
    /// ELI5: "Hook up the Next/Previous buttons, and start listening for a
    /// fresh push address, before anything else happens."
    func configureNowSingingActivity() {
        NowSingingIntentBridge.handler = { [weak self] action in
            switch action {
            case .nextSection:
                await self?.hostAdvanceSection(1)
            case .previousSection:
                await self?.hostAdvanceSection(-1)
            }
        }
        guard let nowSingingActivity else { return }
        nowSingingActivity.onPushTokenHex = { [weak self] pushTokenHex in
            await self?.registerActivityPushToken(pushTokenHex)
        }
    }

    // MARK: - Reducer -> controller sync (the ONE place `apply(_:)` is called)

    /// Diffs `nowSingingContext`'s value against what was last synced,
    /// through the pure `NowSingingActivityReducer`, and forwards whatever
    /// command comes back to the injected controller. A silent no-op when
    /// no controller was injected (every non-iOS shell) or when nothing
    /// actually changed.
    ///
    /// ELI5: "Figure out what changed since last time, and tell the card to
    /// catch up — but only if there's actually a card to tell."
    func syncNowSingingActivity() {
        let command = NowSingingActivityReducer.command(previous: nowSingingSyncedContext, current: nowSingingContext)
        nowSingingSyncedContext = nowSingingContext
        guard command != .none, let nowSingingActivity else { return }
        /* #1429 Audit-B F5 — chain onto `lastApplyTask` (`AppRootViewModel
           .swift`) rather than spawning an independent `Task` per call: two
           back-to-back syncs (e.g. `hostingEnded` then a fresh
           `hostingStarted`, both MainActor-synchronous) would otherwise
           race whichever `Task` happens to get scheduled/hop first, with no
           guarantee the `.end` reaches the controller before the `.start`
           does. Awaiting `prev?.value` before this call's own `apply(_:)`
           enforces strict FIFO with no lock needed. */
        lastApplyTask = Task { [prev = lastApplyTask] in
            await prev?.value
            await nowSingingActivity.apply(command)
        }
    }

    // MARK: - Host section navigation (the Live Activity's Next/Previous buttons)

    /// Advances (or retreats) the host's broadcast section by `delta`
    /// sections — the effect behind a Live Activity Next/Previous tap
    /// (`NowSingingIntentBridge.handler`, wired in `configureNowSingingActivity()`
    /// above). A no-op while not hosting, while no song has been broadcast
    /// yet, or when `NowSingingSectionNavigator` says the move wouldn't
    /// change anything (already at a clamped boundary).
    ///
    /// ELI5: "Someone tapped Next/Previous on the card — if I'm actually
    /// hosting a song right now, move to the next/previous section and tell
    /// everyone following."
    @MainActor
    public func hostAdvanceSection(_ delta: Int) async {
        guard case .hosting = liveState, let songId = hostSongId else { return }
        guard let target = NowSingingSectionNavigator.target(current: hostComponentIndex, delta: delta, sectionCount: nil) else {
            return
        }
        await liveFollowEngine.broadcast(songId: songId, componentIndex: target)
        hostComponentIndex = target
        if var context = nowSingingContext {
            context.componentIndex = target
            context.revision += 1
            nowSingingContext = context
            syncNowSingingActivity()
        }
    }

    // MARK: - Push token custody (`?action=apns_register`, kind=liveActivity)

    /// Registers a freshly-minted Live Activity push token against the
    /// CURRENT hosting session — or, if no session id is known yet, parks
    /// it in `pendingActivityPushTokenHex` for `flushPendingActivityPushToken
    /// (sessionId:)` to pick up the moment one arrives.
    ///
    /// ELI5: "Got a new push address for the card — tell the server which
    /// session it belongs to, or remember it for a moment if we don't know
    /// that yet."
    ///
    /// DETAILED: This race is real, not defensive theatre — `NowSingingActivityController
    /// .start(attributes:state:)` (`IHLiveActivity`) calls `Activity.request
    /// (pushType: .token)` the instant `syncNowSingingActivity()` forwards
    /// the `.start` command from `hostingStarted`, and ActivityKit can hand
    /// back a push token from that SAME async context before this method's
    /// caller (`configureNowSingingActivity()`'s closure) has finished
    /// running — there is no ordering guarantee between "the closure that
    /// reads `nowSingingContext?.sessionId`" and "the `nowSingingContext`
    /// assignment inside `apply(_ event:)`" beyond both being MainActor-
    /// serialized, which alone doesn't prove one specific interleaving.
    /// Never throws outward — a failed registration means no push arrives
    /// for THIS token; the hosting session itself is entirely unaffected
    /// (mirrors `liveFollowEngine.broadcast(songId:componentIndex:)`'s own
    /// "swallow a transient failure" posture). The token is NEVER logged.
    func registerActivityPushToken(_ pushTokenHex: String) async {
        guard let sessionId = nowSingingContext?.sessionId else {
            pendingActivityPushTokenHex = pushTokenHex
            return
        }
        try? await apiClient.apnsRegister(
            pushTokenHex: pushTokenHex, apnsEnv: ApnsEnvironmentDefault.current, kind: "liveActivity", sessionId: sessionId
        )
    }

    /// Flushes a push token parked by `registerActivityPushToken(_:)` above,
    /// the moment a session id becomes known (`hostingStarted`'s own
    /// `sessionId` payload, `+LiveSync.swift`). A no-op when nothing is
    /// parked, or when `sessionId` itself is `nil` (a legacy backend that
    /// hasn't shipped `live_follow_create`'s `sessionId` field yet — see
    /// `LiveFollowEvent.hostingStarted`'s own doc comment).
    func flushPendingActivityPushToken(sessionId: Int?) {
        guard let sessionId, let pushTokenHex = pendingActivityPushTokenHex else { return }
        pendingActivityPushTokenHex = nil
        Task { [apiClient] in
            try? await apiClient.apnsRegister(
                pushTokenHex: pushTokenHex, apnsEnv: ApnsEnvironmentDefault.current, kind: "liveActivity", sessionId: sessionId
            )
        }
    }
}
