// LiveFollowEngine+Host.swift
// IHLive/ServerLive
//
// ELI5: The "leading a live session" half of `LiveFollowEngine` — start
// broadcasting, tell everyone what song/section you're on as you navigate,
// keep sending a heartbeat so the session doesn't look dead, and end it.
//
// DETAILED: Apple Phase-2 PR-10 (#1426; `.claude/apple-phase2-pr10-spec.md`
// §3.4-H). A 409 on `live_follow_update` (`api.php:14026`, "session not
// found, not yours, or ended") is the server's ONE "you've been superseded/
// ended" signal — `APIClient.classify` folds it into the generic
// `.server(status: 409, _)` case (no dedicated 409 case in the shared
// `APIError` taxonomy), so THIS file does the narrowest-possible mapping
// locally (spec §4.2) rather than widening `APIError` for one caller.
import Foundation
import IHAPI
import IHModels

extension LiveFollowEngine {
    /// Starts (or restarts, superseding any prior one — server-side, `api.php
    /// :13924-13928`) a hosting session. Throws `.alreadyFollowing` if this
    /// engine is currently following someone else (mutual exclusivity, web
    /// parity `live-follow.js:64-69`) — re-`goLive` while ALREADY hosting is
    /// allowed (matches the server's own "one active session per host,
    /// supersede" behaviour).
    ///
    /// ELI5: "Start sharing what I'm reading, under a fresh code."
    public func goLive(songId: String?, componentIndex: Int?) async throws -> String {
        if case .following = role {
            throw LiveSyncError.alreadyFollowing
        }
        loopTask?.cancel()
        loopTask = nil

        let created = try await apiClient.liveFollowCreate(songId: songId, componentIndex: componentIndex)
        role = .hosting(code: created.code)
        // #1429 C7 — custody of the new session's numeric id, for the LIFE
        // of this hosting session; cleared on every end path below (see
        // `hostSessionId`'s own doc comment, primary file).
        hostSessionId = created.sessionId
        lastBroadcastKey = songId.map { Self.broadcastKey(songId: $0, componentIndex: componentIndex) }
        persistence.saveHostSession(code: created.code)
        startHeartbeatLoopIfNeeded()
        eventContinuation.yield(.hostingStarted(code: created.code, sessionId: created.sessionId))
        return created.code
    }

    /// Broadcasts the host's current song + section, deduping a repeat
    /// (same song, same section — `live-follow.js:41,173-174`). A no-op
    /// unless currently `.hosting` — safe to call unconditionally from
    /// `liveSongViewed(_:)` (`AppRootViewModel+LiveSync.swift`) on every
    /// song appearance.
    ///
    /// ELI5: "Tell everyone following me: I'm on THIS song now" — but only
    /// if that's actually new information, and only if I'm hosting at all.
    public func broadcast(songId: String, componentIndex: Int?) async {
        guard case .hosting(let code) = role else { return }
        let key = Self.broadcastKey(songId: songId, componentIndex: componentIndex)
        guard key != lastBroadcastKey else { return }
        do {
            _ = try await apiClient.liveFollowUpdate(code: code, songId: songId, componentIndex: componentIndex)
            lastBroadcastKey = key
        } catch APIError.server(let status, _) where status == 409 {
            await endHostingLocally(reason: .superseded)
        } catch {
            // Transient (offline/maintenance/rate-limited/decoding) —
            // leave `lastBroadcastKey` UNSET so the NEXT navigation retries
            // this same broadcast (`live-follow.js:187`'s own "swallow and
            // let the next update try again" posture); D-11: never an end
            // signal.
        }
    }

    /// The host's deliberate "End" — best-effort revoke server-side, always
    /// clears local state.
    public func endHosting() async {
        guard case .hosting(let code) = role else { return }
        loopTask?.cancel()
        loopTask = nil
        _ = try? await apiClient.liveFollowLeave(code: code)
        persistence.clearHostSession()
        role = .idle
        hostSessionId = nil
        lastBroadcastKey = nil
        eventContinuation.yield(.hostingEnded(.userLeft))
    }

    /// Sign-out while hosting — an unauthenticated host can't heartbeat
    /// (`live-follow.js:75-79` parity). No server call (no bearer token to
    /// make one with); local state only.
    public func endHostingForSignOut() async {
        guard case .hosting = role else { return }
        loopTask?.cancel()
        loopTask = nil
        persistence.clearHostSession()
        role = .idle
        hostSessionId = nil
        lastBroadcastKey = nil
        eventContinuation.yield(.hostingEnded(.signedOut))
    }

    /// Starts the heartbeat loop if hosting and no loop is already running —
    /// module-internal (not `private`) so `LiveFollowEngine.setScenePhaseActive`
    /// (the primary file) can restart it on foreground.
    func startHeartbeatLoopIfNeeded() {
        guard loopTask == nil, case .hosting = role else { return }
        loopTask = Task { [weak self] in
            guard let self else { return }
            while !Task.isCancelled {
                do {
                    try await self.config.sleep(self.config.heartbeatInterval)
                } catch {
                    return
                }
                guard !Task.isCancelled else { return }
                await self.performHeartbeat()
            }
        }
    }

    /// One heartbeat send — module-internal so `setScenePhaseActive`'s
    /// foreground "wake beat" can call it immediately, outside the loop's
    /// own sleep cadence.
    func performHeartbeat() async {
        guard case .hosting(let code) = role else { return }
        do {
            let alive = try await apiClient.liveFollowHeartbeat(code: code)
            if !alive {
                await endHostingLocally(reason: .serverEnded)
            }
        } catch {
            // Transient — the next beat retries (D-11: never an end signal).
        }
    }

    /// Shared "the server told us this hosting session is gone" teardown —
    /// used by both the 409-on-broadcast path and a `{"ok":false}` heartbeat.
    private func endHostingLocally(reason: LiveSyncEndReason) async {
        loopTask?.cancel()
        loopTask = nil
        persistence.clearHostSession()
        role = .idle
        hostSessionId = nil
        lastBroadcastKey = nil
        eventContinuation.yield(.hostingEnded(reason))
    }

    /// The host's own broadcast-dedup key (`live-follow.js:41,173-174`).
    static func broadcastKey(songId: String, componentIndex: Int?) -> String {
        "\(songId)|\(componentIndex.map(String.init) ?? "")"
    }
}
