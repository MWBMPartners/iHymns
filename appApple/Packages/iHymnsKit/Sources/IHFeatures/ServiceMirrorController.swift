// ServiceMirrorController.swift
// IHFeatures
//
// ELI5: An OPTIONAL extra switch a remote-control user can flip: "also show
// whatever's on this TV to everyone following a live Service session on the
// web." Nobody has to touch it — the TV remote works exactly the same
// whether it's on or off. When it's on, every time the TV's picture
// changes, this quietly tells the web server so congregants following along
// on their own phones see the same thing.
//
// DETAILED: Apple Phase-2 PR-14 (#1425, `.claude/apple-native-strategy.md`
// §2.4.1's "optional Service-Mode mirror (mirror-on-ack via existing
// `service_broadcast`)"). Sits ENTIRELY on the operator side of the LAN
// remote (`RemoteControlCoordinator`'s `.controlling` events — §2.4.1's own
// hard separation between the LAN-direct remote and server-mediated Service
// Mode) — it never touches `ServiceModeEngine`, `RemoteControlSession`,
// `RemoteSessionActor`, or the IHRP protocol; it only calls the SAME
// `service_broadcast` endpoint the web operator consoles already POST to
// (`api.php:14478-14597`, `includes/service_mode.php:140-178`), reusing the
// identical `{sessionId,songId,componentIndex,state:{displayState}}`
// contract those consoles use.
//
// State machine (`Phase`): `.off` (nothing configured — the default) →
// `.active(sessionId:)` (armed, last send accepted) → `.degraded
// (sessionId:)` (armed, but the last send failed retryably — still trying)
// → back to `.active` on the next accepted send, or all the way back to
// `.off` (with an explanatory `notice`) on a TERMINAL failure (unauthorized/
// 403/404 — the session has ended, or this account was never authorised to
// drive it). `disarm()` is the operator's own "Stop Mirroring" — always
// available, always goes straight to `.off`, no notice (a deliberate stop
// is not a failure). A THIRD outcome (#1562 F-3): a 400/422 (the broadcast
// song is unknown/invalid to the server — e.g. deleted server-side while
// still cached on the TV) is SKIPPED, not retried — `.active` is kept, the
// failed state's key is recorded as if accepted (so the identical state
// never re-sends), and no retry Task is scheduled; only a genuinely NEW TV
// state tries again. Without this, a stale/deleted song wedges the mirror
// into an eternal 5 s retry loop behind a misleading "Reconnecting…" notice
// even though nothing about THIS failure is transient.
//
// Send discipline: AT MOST one `service_broadcast` in flight at a time,
// plus at most one COALESCED pending state (latest-wins — never a queue).
// A dedup key (`mirrorKey(for:)`) skips resending a broadcast the server
// has already accepted for the identical song/section/display combination
// — `lineIndex`/`revision` never enter the key, because the web congregant
// view has no line-level cursor to mirror (`service_mode.php`'s own state
// shape only ever carries `CurrentSongId`/`ComponentIndex`/`DisplayState`).
//
// Logging (`IHLog.remote`): transitions only, numeric session ids only —
// NEVER a song id, join code, presence token, or bearer token.
import IHAPI
import IHLive
import IHLog
import Observation

/// Mirrors this TV's current song/section/display state into a running
/// Service Mode session — see this file's header for the full picture.
@MainActor
@Observable
public final class ServiceMirrorController {

    /// One `service_broadcast` call:
    /// `(sessionId, songId, componentIndex, webDisplayState) async throws
    /// -> newRevision`. Injected so `RemoteControlView` can wire the REAL
    /// `APIClient.serviceBroadcast` while tests substitute a spy — the same
    /// injected-closure seam `ProjectionViewModel.fetchSongDetail` already
    /// establishes for this package's other view-model-adjacent
    /// controllers.
    public typealias BroadcastSend = @Sendable (
        _ sessionId: Int, _ songId: String, _ componentIndex: Int?, _ displayState: String
    ) async throws -> Int

    /// See this file's header for the full state-machine picture.
    public enum Phase: Equatable {
        case off
        case active(sessionId: Int)
        case degraded(sessionId: Int)
    }

    public private(set) var phase: Phase = .off

    /// A transient, inline explanatory line for `ServiceMirrorCard` — set
    /// on every failure (degraded or terminal), cleared the moment a send
    /// is next accepted. `nil` most of the time.
    public private(set) var notice: String?

    private let send: BroadcastSend
    private let retryDelaySeconds: Double

    /// The `mirrorKey` of the last server-ACCEPTED send. `nil` means
    /// "nothing accepted yet" — set ONLY on a 200, never optimistically, so
    /// a failed send always looks resendable to the next real TV update.
    private var lastAcceptedKey: String?
    /// At most ONE coalesced newer state, captured while a send is already
    /// in flight — latest-wins, never a queue (see file header).
    private var pendingState: IHRPState?
    private var sendTask: Task<Void, Never>?
    private var retryTask: Task<Void, Never>?

    public init(send: @escaping BroadcastSend, retryDelaySeconds: Double = 5) {
        self.send = send
        self.retryDelaySeconds = retryDelaySeconds
    }

    /// Arms the mirror against `sessionId` — the operator typed this in
    /// from the web console's session picker (#1425, the web display-only
    /// commit). Immediately mirrors `currentState` if one is already known,
    /// so congregants don't wait for the NEXT TV change to see anything.
    public func arm(sessionId: Int, currentState: IHRPState?) {
        retryTask?.cancel(); retryTask = nil
        sendTask?.cancel(); sendTask = nil
        phase = .active(sessionId: sessionId)
        notice = nil
        lastAcceptedKey = nil
        pendingState = nil
        IHLog.remote.notice("servicemirror.armed session=\(sessionId, privacy: .public)")
        if let currentState {
            observe(currentState)
        }
    }

    /// The operator's own "Stop Mirroring" — always goes straight to
    /// `.off`, cancelling any in-flight send/scheduled retry. No notice: a
    /// deliberate stop is not a failure.
    public func disarm() {
        sendTask?.cancel(); sendTask = nil
        retryTask?.cancel(); retryTask = nil
        phase = .off
        notice = nil
        lastAcceptedKey = nil
        pendingState = nil
        IHLog.remote.notice("servicemirror.stopped reason=operator")
    }

    /// `RemoteControlCoordinator.apply(_:)`'s `.controlling` tap — called
    /// on EVERY TV broadcast, including echoes of our own intents (spec
    /// §1.1). No-ops while `.off`, so this is safe to call unconditionally.
    ///
    /// ELI5 (#1562 F-2): while the TV picture is PINNED (`.frozen` —
    /// `ProjectionViewModel`'s frozen-snapshot rule), don't tell the web
    /// congregants what the operator is quietly navigating to behind the
    /// freeze — they'd see it move even though the venue screen hasn't.
    ///
    /// DETAILED: `.controlling` echoes still carry the MOVING canonical
    /// `IHRPState` while frozen (`RemoteControlCoordinator`'s own doc:
    /// "Navigation intents keep working while frozen... only
    /// `renderedContent`'s PICTURE stays pinned") — the canonical state is
    /// truthful for the LAN remote's own UI, but mirroring it live would
    /// leak the operator's in-progress navigation to every congregant
    /// phone while the venue's actual screen stays frozen on the old song.
    /// Skipped here rather than filtered at the dedup/`mirrorKey` layer
    /// because the freeze is a DISPLAY concern, not a "same state as last
    /// time" one. The unfreeze echo carries a different `mirrorKey`
    /// (`displayState` changed), so re-sync to the congregants is
    /// automatic the moment the operator unfreezes — no extra bookkeeping
    /// needed here.
    public func observe(_ state: IHRPState) {
        guard phase != .off else { return }
        guard state.displayState != .frozen else { return }
        guard let key = Self.mirrorKey(for: state), key != lastAcceptedKey else { return }
        guard sendTask == nil else {
            pendingState = state
            return
        }
        startSend(state: state, key: key)
    }

    /// The web `service_broadcast` display-state vocabulary
    /// (`includes/service_mode.php`'s `DisplayState` column) is narrower
    /// than the LAN remote's — `.frozen` (a NATIVE-remote-only "pin the
    /// picture locally" concept, `IHRPDisplayState`'s own header) has no
    /// web equivalent, so it mirrors as whatever is actually still on
    /// screen while frozen: the lyrics view, i.e. `"live"`.
    nonisolated public static func webDisplayState(for state: IHRPDisplayState) -> String {
        switch state {
        case .lyrics, .frozen: return "live"
        case .blackout: return "blackout"
        case .logo: return "logo"
        }
    }

    /// `nil` when there's nothing to mirror yet (no song selected — never
    /// broadcast an idle/no-song state, matching `service_broadcast`'s own
    /// REQUIRED-`songId` contract). Deliberately EXCLUDES `lineIndex`/
    /// `revision` — see this file's header "Send discipline."
    nonisolated public static func mirrorKey(for state: IHRPState) -> String? {
        guard let songId = state.songId else { return nil }
        return "\(songId.rawValue)|\(state.componentIndex ?? -1)|\(webDisplayState(for: state.displayState))"
    }

    // MARK: - Send / retry internals

    private var armedSessionId: Int? {
        switch phase {
        case .off: return nil
        case .active(let sessionId), .degraded(let sessionId): return sessionId
        }
    }

    private func startSend(state: IHRPState, key: String) {
        guard let sessionId = armedSessionId, let songId = state.songId else { return }
        let componentIndex = state.componentIndex
        let displayState = Self.webDisplayState(for: state.displayState)
        sendTask = Task { [weak self, send] in
            do {
                _ = try await send(sessionId, songId.rawValue, componentIndex, displayState)
                guard !Task.isCancelled else { return }
                await self?.handleSendResult(.success(key: key, sessionId: sessionId))
            } catch {
                guard !Task.isCancelled else { return }
                await self?.handleSendResult(.failure(error, sessionId: sessionId, state: state))
            }
        }
    }

    private enum SendOutcome {
        case success(key: String, sessionId: Int)
        case failure(Error, sessionId: Int, state: IHRPState)
    }

    /// Runs on the SAME MainActor turn as `startSend`'s send call, so this
    /// is where the coalesced `pendingState` (if any newer TV update
    /// arrived while the send was in flight) gets picked up — re-entering
    /// `observe(_:)` rather than duplicating its dedup/gate logic.
    private func handleSendResult(_ outcome: SendOutcome) {
        sendTask = nil
        switch outcome {
        case .success(let key, let sessionId):
            retryTask?.cancel(); retryTask = nil
            lastAcceptedKey = key
            phase = .active(sessionId: sessionId)
            notice = nil
            if let pending = pendingState {
                pendingState = nil
                observe(pending)
            }
        case .failure(let error, let sessionId, let state):
            handleFailure(error, sessionId: sessionId, fallbackState: state)
        }
    }

    /// `.unauthorized`/403/404 mean the session is gone or this account was
    /// never authorised to drive it (`api.php:14478-14597`'s error
    /// branches) — no retry can fix that, so stop cleanly with an
    /// explanation. Everything else (offline, `.maintenance`, `.rateLimited`,
    /// an opaque 5xx) is presumed transient — stay armed, degrade, and
    /// schedule exactly ONE retry of the latest known state (the real
    /// safety net is "the next TV change re-sends naturally" via
    /// `observe(_:)`; this retry is a courtesy so a quiet TV still
    /// recovers).
    private func handleFailure(_ error: Error, sessionId: Int, fallbackState: IHRPState) {
        guard !Self.isTerminal(error) else {
            phase = .off
            lastAcceptedKey = nil
            pendingState = nil
            notice = "Mirroring stopped — the service session has ended or this account isn't authorised for it."
            IHLog.remote.notice("servicemirror.stopped reason=terminal session=\(sessionId, privacy: .public)")
            return
        }
        // #1562 F-3: a 400/422 means the SERVER rejected this exact song
        // state as unknown/invalid (e.g. deleted server-side while still
        // cached on the TV) — no amount of retrying changes that answer,
        // so this is a SKIP, not a degrade-and-retry. Recording the failed
        // state's own key as "accepted" stops the identical state from
        // being resent (mirroring the real success path's dedup), while
        // leaving `phase` at `.active` (never `.degraded`) keeps the UI
        // honest: mirroring itself is fine, only this one song isn't.
        guard !Self.isSkippable(error) else {
            lastAcceptedKey = Self.mirrorKey(for: fallbackState)
            phase = .active(sessionId: sessionId)
            notice = "That song isn't available to the congregation."
            IHLog.remote.notice("servicemirror.skipped session=\(sessionId, privacy: .public)")
            // A newer TV update coalesced while this send was in flight is
            // a GENUINELY different state — still worth trying, unlike a
            // retry of the one that was just rejected.
            if let pending = pendingState {
                pendingState = nil
                observe(pending)
            }
            return
        }
        phase = .degraded(sessionId: sessionId)
        notice = "Reconnecting to iHymns — your projection is unaffected."
        IHLog.remote.notice("servicemirror.degraded session=\(sessionId, privacy: .public)")
        // A newer TV update coalesced while this send was in flight IS the
        // latest state — retry that instead of the one that just failed.
        let retryState = pendingState ?? fallbackState
        pendingState = nil
        scheduleRetry(state: retryState)
    }

    nonisolated private static func isTerminal(_ error: Error) -> Bool {
        switch error {
        case APIError.unauthorized:
            return true
        case APIError.server(let status, _):
            return status == 403 || status == 404
        default:
            return false
        }
    }

    /// #1562 F-3: "this exact song state is unknown/invalid to the server"
    /// — distinct from `isTerminal` (session-level: gone/unauthorised) and
    /// from every other failure (presumed transient: offline/maintenance/
    /// rate-limited/opaque 5xx). See `handleFailure`'s skip branch.
    nonisolated private static func isSkippable(_ error: Error) -> Bool {
        switch error {
        case APIError.server(let status, _):
            return status == 400 || status == 422
        default:
            return false
        }
    }

    private func scheduleRetry(state: IHRPState) {
        retryTask?.cancel()
        let delay = retryDelaySeconds
        retryTask = Task { [weak self] in
            try? await Task.sleep(for: .seconds(delay))
            guard !Task.isCancelled else { return }
            await self?.fireRetry(state: state)
        }
    }

    private func fireRetry(state: IHRPState) {
        retryTask = nil
        guard case .degraded = phase, sendTask == nil else { return }
        guard let key = Self.mirrorKey(for: state) else { return }
        startSend(state: state, key: key)
    }
}
