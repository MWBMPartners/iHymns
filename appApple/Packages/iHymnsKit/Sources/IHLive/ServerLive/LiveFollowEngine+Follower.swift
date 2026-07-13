// LiveFollowEngine+Follower.swift
// IHLive/ServerLive
//
// ELI5: The "following someone else's live session" half of
// `LiveFollowEngine` — join by code, keep polling for updates, and leave.
//
// DETAILED: Apple Phase-2 PR-10 (#1426; `.claude/apple-phase2-pr10-spec.md`
// §3.4-F). Drives the SAME shared `LiveFollowerReducer` spine
// `ServiceModeEngine` uses (D-3 — one pure follower spine, never a second
// copy) — the only Live-Follow-specific choice this file makes is which
// base cadence to feed it: `LiveSyncConfiguration.liveFollowPollFallback`
// (2500 ms, `live-follow.js:25`) ALWAYS, since this endpoint never declares
// a server interval (unlike Service Mode's `pollIntervalMs`).
import Foundation
import IHAPI
import IHModels

extension LiveFollowEngine {
    /// Joins a Live Follow session by code. Throws `.alreadyHosting` if this
    /// engine is currently hosting (mutual exclusivity); `.codeNotActive` on
    /// the server's opaque 404 (`api.php:14160`).
    ///
    /// ELI5: "Let me follow whatever this code is showing."
    public func join(code: String) async throws -> LiveFollowJoinInfo {
        if case .hosting = role {
            throw LiveSyncError.alreadyHosting
        }
        loopTask?.cancel()
        loopTask = nil

        do {
            let result = try await apiClient.liveFollowJoin(code: code)
            let now = config.now()
            followToken = result.followToken
            role = .following(code: code)
            followerState = LiveFollowerState(
                revision: result.info.initial.revision,
                lastContactAt: now,
                consecutiveFailures: 0,
                isIdle: false,
                latest: result.info.initial
            )
            persistence.saveFollowSession(code: code, token: result.followToken, revision: result.info.initial.revision)
            startFollowerLoopIfNeeded()
            eventContinuation.yield(.followingStarted(code: code, hostDisplayName: result.info.hostDisplayName, initial: result.info.initial))
            return result.info
        } catch APIError.server(let status, _) where status == 404 {
            throw LiveSyncError.codeNotActive
        } catch {
            // Any other failure (offline/maintenance/rate-limited/decoding)
            // — a generic network-ish outcome, mirroring `ServiceModeEngine
            // .join`'s own failure-mapping table (spec §3.5).
            throw LiveSyncError.network
        }
    }

    /// The follower's own "Leave" — no server call exists for an anonymous
    /// LF follower (the web just stops polling, `live-follow.js:277-289`);
    /// this simply clears local state.
    public func leaveFollow() async {
        guard case .following = role else { return }
        loopTask?.cancel()
        loopTask = nil
        role = .idle
        followToken = nil
        followerState = LiveFollowerState()
        persistence.clearFollowSession()
        eventContinuation.yield(.followingEnded(.userLeft))
    }

    /// Marks the following screen frontmost/backgrounded — feeds the
    /// reducer's idle-floor cadence row (spec §3.3). Does NOT itself
    /// start/stop the loop (that's `setScenePhaseActive`'s job).
    public func setFollowingIdle(_ isIdle: Bool) {
        followerState.isIdle = isIdle
    }

    /// Starts the poll loop if following and no loop is already running —
    /// module-internal so the primary file's `setScenePhaseActive` can
    /// restart it on foreground.
    func startFollowerLoopIfNeeded() {
        guard loopTask == nil, case .following = role else { return }
        loopTask = Task { [weak self] in
            guard let self else { return }
            while !Task.isCancelled {
                let delay = await self.currentFollowerPollDelay()
                do {
                    try await self.config.sleep(delay)
                } catch {
                    return
                }
                guard !Task.isCancelled else { return }
                await self.performFollowerPoll()
            }
        }
    }

    private func currentFollowerPollDelay() -> Duration {
        let baseMs = config.liveFollowPollFallback.milliseconds
        return LiveFollowerReducer.nextPollDelay(followerState, serverIntervalMs: baseMs, config: config)
    }

    /// One poll — module-internal so `setScenePhaseActive`'s foreground
    /// "immediate poll" wake action can call it outside the loop's own sleep
    /// cadence.
    func performFollowerPoll() async {
        guard case .following(let code) = role, let followToken else { return }
        let now = config.now()
        let wasFresh = LiveFollowerReducer.isFresh(followerState, now: now)
        do {
            let update = try await apiClient.liveFollowPoll(code: code, since: followerState.revision, token: followToken)
            let effect = LiveFollowerReducer.applyPoll(update, state: &followerState, now: now)
            persistence.updateFollowRevision(followerState.revision)
            switch effect {
            case .apply(let snapshot):
                eventContinuation.yield(.snapshot(snapshot))
            case .sessionEnded:
                await leaveFollowLocally(reason: .serverEnded)
                return
            case .none:
                break
            }
        } catch {
            LiveFollowerReducer.applyFailure(state: &followerState, now: now)
        }
        let isFreshNow = LiveFollowerReducer.isFresh(followerState, now: now)
        if isFreshNow != wasFresh {
            eventContinuation.yield(.freshnessChanged(isFreshNow))
        }
    }

    /// Server-driven ("the session ended") teardown — distinct from
    /// `leaveFollow()`'s user-initiated one only in the emitted reason.
    private func leaveFollowLocally(reason: LiveSyncEndReason) async {
        loopTask?.cancel()
        loopTask = nil
        role = .idle
        followToken = nil
        persistence.clearFollowSession()
        eventContinuation.yield(.followingEnded(reason))
    }
}
