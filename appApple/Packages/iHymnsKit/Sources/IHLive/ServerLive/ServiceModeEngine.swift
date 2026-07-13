// ServiceModeEngine.swift
// IHLive/ServerLive
//
// ELI5: The congregant actor — joins a service by the venue's rotating
// code, keeps polling for song changes, holds onto the secret "room key"
// (presence token) that (once the owner turns gating on) quietly unlocks
// copyrighted lyrics for exactly as long as you're in the room, and leaves
// cleanly.
//
// DETAILED: Apple Phase-2 PR-10 (#1427; `.claude/apple-phase2-pr10-spec.md`
// §3.5, the #1427 core). Drives the SAME shared `LiveFollowerReducer` spine
// `LiveFollowEngine`'s follower half uses (D-3). Presence-token custody is
// the single most security-sensitive piece of this PR (spec §8's threat
// table): the token NEVER rides an event payload, is persisted ONLY as
// local UserDefaults (D-5, `LiveSyncPersistence`), and is logged ONLY via
// `presenceTokenFingerprint()`'s `IHLogSanitize.tokenFingerprint` — never
// interpolated raw, anywhere, at any privacy level.
import Foundation
import IHAPI
import IHLog
import IHModels

/// The Service Mode congregant actor — see this file's header.
public actor ServiceModeEngine {
    /// The 4 h hard ceiling (`service_mode.php:35`,
    /// `SERVICE_MODE_HARD_CEILING_HOURS`) — `resumeIfPossible()` refuses to
    /// even poll a persisted record older than this; certainly dead.
    private static let hardCeilingSeconds: TimeInterval = 4 * 3_600

    public enum Phase: Sendable, Equatable {
        case idle, following
    }

    let apiClient: APIClient
    let config: LiveSyncConfiguration
    let deviceIdentity: ServiceDeviceIdentity
    let persistence: LiveSyncPersistence

    private(set) var phase: Phase = .idle
    var followerState = LiveFollowerState()
    /// THE gate key — never leaves this actor raw (D-5). Only
    /// `presenceTokenFingerprint()` may ever externalise a trace of it.
    private var presenceToken: String?
    /// Server-declared cadence from the join response, if any (`nil` on an
    /// un-migrated backend — `LiveFollowerReducer` falls back to
    /// `config.servicePollFallback`).
    var pollIntervalMs: Int?
    var loopTask: Task<Void, Never>?
    var suspended = false

    nonisolated public let events: AsyncStream<ServiceModeEvent>
    let eventContinuation: AsyncStream<ServiceModeEvent>.Continuation

    public init(
        apiClient: APIClient,
        configuration: LiveSyncConfiguration = LiveSyncConfiguration(),
        deviceIdentity: ServiceDeviceIdentity = .standard,
        persistence: LiveSyncPersistence = .standard
    ) {
        self.apiClient = apiClient
        self.config = configuration
        self.deviceIdentity = deviceIdentity
        self.persistence = persistence
        let (stream, continuation) = AsyncStream.makeStream(of: ServiceModeEvent.self)
        self.events = stream
        self.eventContinuation = continuation
    }

    /// Joins a service by its venue-displayed rotating code. On success,
    /// custodies the presence token (INCLUDING handing it to `apiClient
    /// .updateServicePresenceToken(_:)` — done HERE, inside the engine, so
    /// the D-6 gating hook can never be forgotten by a caller), persists a
    /// resume record, and starts polling.
    ///
    /// ELI5: "Let me join this service."
    ///
    /// - Throws: `.codeNotActive` (opaque 404), `.temporarilyUnavailable`
    ///   (503 — the site is briefly down, NOT a bad code), or `.network` for
    ///   any other failure.
    public func join(code: String) async throws -> ServiceJoinInfo {
        loopTask?.cancel()
        loopTask = nil
        do {
            let result = try await apiClient.serviceJoin(code: code, presenceDeviceId: deviceIdentity.id, role: "congregant")
            let now = config.now()
            presenceToken = result.presenceToken
            pollIntervalMs = result.info.pollIntervalMs
            phase = .following
            followerState = LiveFollowerState(
                revision: result.info.initial.revision,
                lastContactAt: now,
                consecutiveFailures: 0,
                isIdle: false,
                latest: result.info.initial
            )
            await apiClient.updateServicePresenceToken(result.presenceToken)
            persistence.saveServicePresence(token: result.presenceToken, revision: result.info.initial.revision, joinedAt: now)
            startPollLoopIfNeeded()
            eventContinuation.yield(.joined(initial: result.info.initial))
            eventContinuation.yield(.presenceChanged)
            return result.info
        } catch APIError.server(let status, _) where status == 404 {
            throw LiveSyncError.codeNotActive
        } catch APIError.maintenance {
            throw LiveSyncError.temporarilyUnavailable
        } catch {
            throw LiveSyncError.network
        }
    }

    /// The congregant's deliberate "Leave" — revokes server-side FIRST
    /// (best-effort), THEN clears local custody (mirrors the "server-revoke
    /// THEN delete" discipline strategy §3.2 mandates for the account
    /// token — order matters, spec §3.5).
    public func leave() async {
        guard phase == .following, let token = presenceToken else { return }
        loopTask?.cancel()
        loopTask = nil
        _ = try? await apiClient.serviceLeave(presenceToken: token)
        await clearCustody()
        eventContinuation.yield(.left(.userLeft))
        eventContinuation.yield(.presenceChanged)
    }

    /// Cold-start resume: if a persisted record exists and is within the
    /// 4 h hard ceiling, restores custody and fires ONE immediate poll.
    /// `active:false` ⇒ silent cleanup (no `.left` event — there was never a
    /// visible "following" state on THIS launch to announce the end of); a
    /// genuine change ⇒ `.joined` with the fresh snapshot; unchanged ⇒
    /// `.joined` with whatever's available (`nil` fields — the "Waiting for
    /// the first song…" empty state, spec §6.3, until the next real change).
    /// A record older than the ceiling is dropped WITHOUT even polling
    /// (certainly dead — `service_mode.php:35`).
    public func resumeIfPossible() async {
        guard phase == .idle, let record = persistence.loadServicePresence() else { return }
        let now = config.now()
        let elapsed = now.timeIntervalSince(record.joinedAt)
        guard elapsed >= 0, elapsed < Self.hardCeilingSeconds else {
            persistence.clearServicePresence()
            return
        }

        do {
            let update = try await apiClient.servicePoll(presenceToken: record.token, since: record.revision)
            guard update.active != false else {
                persistence.clearServicePresence()
                return
            }
            var state = LiveFollowerState(revision: record.revision, lastContactAt: now, consecutiveFailures: 0, isIdle: false, latest: nil)
            let effect = LiveFollowerReducer.applyPoll(update, state: &state, now: now)
            followerState = state
            presenceToken = record.token
            pollIntervalMs = nil
            phase = .following
            await apiClient.updateServicePresenceToken(record.token)
            persistence.updateServiceRevision(followerState.revision)
            startPollLoopIfNeeded()
            let snapshot: LiveBroadcastSnapshot
            if case .apply(let applied) = effect {
                snapshot = applied
            } else {
                snapshot = LiveBroadcastSnapshot(songId: nil, componentIndex: nil, state: nil, revision: followerState.revision)
            }
            eventContinuation.yield(.joined(initial: snapshot))
            eventContinuation.yield(.presenceChanged)
        } catch {
            // A transient failure on resume leaves this device idle — the
            // user can rejoin manually; the persisted record is NOT cleared
            // (it may still be genuinely valid, just unreachable right now).
        }
    }

    /// Marks the following screen frontmost/backgrounded — feeds the
    /// reducer's idle-floor cadence row.
    public func setFollowingIdle(_ isIdle: Bool) {
        followerState.isIdle = isIdle
    }

    /// `scenePhase` wiring — background cancels the loop outright;
    /// foreground fires an immediate poll then restarts it.
    public func setScenePhaseActive(_ active: Bool) async {
        suspended = !active
        guard active else {
            loopTask?.cancel()
            loopTask = nil
            return
        }
        guard phase == .following else { return }
        await performPoll()
        startPollLoopIfNeeded()
    }

    /// A safe, redacted trace of the presence token for diagnostics/logging
    /// — the ONLY externally-visible sign the raw token ever existed.
    public func presenceTokenFingerprint() -> String? {
        presenceToken.map(IHLogSanitize.tokenFingerprint)
    }

    func startPollLoopIfNeeded() {
        guard loopTask == nil, phase == .following else { return }
        loopTask = Task { [weak self] in
            guard let self else { return }
            while !Task.isCancelled {
                let delay = await self.currentPollDelay()
                do {
                    try await self.config.sleep(delay)
                } catch {
                    return
                }
                guard !Task.isCancelled else { return }
                await self.performPoll()
            }
        }
    }

    private func currentPollDelay() -> Duration {
        LiveFollowerReducer.nextPollDelay(followerState, serverIntervalMs: pollIntervalMs, config: config)
    }

    private func performPoll() async {
        guard phase == .following, let token = presenceToken else { return }
        let now = config.now()
        let wasFresh = LiveFollowerReducer.isFresh(followerState, now: now)
        do {
            let update = try await apiClient.servicePoll(presenceToken: token, since: followerState.revision)
            let effect = LiveFollowerReducer.applyPoll(update, state: &followerState, now: now)
            persistence.updateServiceRevision(followerState.revision)
            switch effect {
            case .apply(let snapshot):
                eventContinuation.yield(.snapshot(snapshot))
            case .sessionEnded:
                await clearCustody()
                eventContinuation.yield(.left(.serverEnded))
                eventContinuation.yield(.presenceChanged)
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

    private func clearCustody() async {
        loopTask?.cancel()
        loopTask = nil
        phase = .idle
        presenceToken = nil
        pollIntervalMs = nil
        followerState = LiveFollowerState()
        persistence.clearServicePresence()
        await apiClient.updateServicePresenceToken(nil)
    }
}
