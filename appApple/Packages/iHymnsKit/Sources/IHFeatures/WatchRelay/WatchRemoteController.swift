// WatchRemoteController.swift
// IHFeatures/WatchRelay
//
// ELI5: The watch's half of the link — sends a button tap to the iPhone
// (waking it if it has to), waits for the honest answer, and keeps the
// watch's little status picture up to date from whatever the iPhone pushes.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §4.5/§5 —
// BINDING). `#if os(watchOS) && canImport(WatchConnectivity)` — this whole
// type only exists on the watch build. `send(_:)` ALWAYS uses
// `sendMessage(_:replyHandler:errorHandler:)` — the reply-bearing form IS
// the wake primitive (§1.3) — and never gates on `isReachable`, which
// drives COPY only (the honest "iPhone not reachable" notice), never
// whether a tap is even attempted.
//
// **`transientNotice` is deliberately NOT cleared by a push** — only a new
// `send(_:)` or the reply/error that resolves it clears it (a deliberate
// refinement of §5's wording). A failed burst's honest reason line (e.g.
// "Couldn't reach the TV…") would otherwise be wiped out ~100 ms later by
// the driver's own `.standby` baseline push racing in right behind the
// failure reply — the user would never get to read why the tap failed.
import Foundation
#if os(watchOS) && canImport(WatchConnectivity)
import IHLive
import IHLog
import Observation
import WatchConnectivity

/// The Sendable value the watch-side delegate shell yields — same
/// concurrency-bridge idiom as `PhoneRelayDelegate` (this module's sibling
/// file).
enum WatchRelayInbound: Sendable {
    case activated(Bool)
    case reachabilityChanged(Bool)
    /// A push arrived with no reply expected — either a live `sendMessage`
    /// echo or an `updateApplicationContext` catch-up.
    case snapshotData(Data)
    /// A `sendMessage` reply arrived for an in-flight `send(_:)`.
    case replyData(Data)
    /// `errorHandler` fired — the `WCError` description, bounded + log-safe
    /// (never anything sensitive; WCSession errors carry no credentials).
    case sendFailed(String)
}

/// `nonisolated` — same shape as `PhoneRelayDelegate`.
final class WatchSessionDelegate: NSObject, WCSessionDelegate, Sendable {
    private let continuation: AsyncStream<WatchRelayInbound>.Continuation

    init(continuation: AsyncStream<WatchRelayInbound>.Continuation) {
        self.continuation = continuation
    }

    func session(_ session: WCSession, activationDidCompleteWith activationState: WCSessionActivationState, error: Error?) {
        continuation.yield(.activated(activationState == .activated))
        // Seed the initial reachability read right after activation — the
        // watch would otherwise have NO reachability value at all until the
        // first `sessionReachabilityDidChange` fires, which may be a while
        // (or never, if it just never changes from its activation-time
        // value). `refresh()`'s own cold-launch `send(.requestState)` still
        // proves LIVE reachability moments later; this just avoids a
        // momentarily-wrong `isPhoneReachable == false` default.
        continuation.yield(.reachabilityChanged(session.isReachable))
    }

    func sessionReachabilityDidChange(_ session: WCSession) {
        continuation.yield(.reachabilityChanged(session.isReachable))
    }

    func session(_ session: WCSession, didReceiveMessage message: [String: Any]) {
        if let data = message[WatchRelayCodec.snapshotKey] as? Data {
            continuation.yield(.snapshotData(data))
        }
    }

    func session(_ session: WCSession, didReceiveApplicationContext applicationContext: [String: Any]) {
        if let data = applicationContext[WatchRelayCodec.snapshotKey] as? Data {
            continuation.yield(.snapshotData(data))
        }
    }
}

/// The watch-side `@Observable` model `WatchRemoteView` renders from — see
/// this file's header for the full picture.
@MainActor
@Observable
public final class WatchRemoteController {
    public private(set) var snapshot: WatchRelaySnapshot = .noSavedTV
    public private(set) var isActivated = false
    public private(set) var isPhoneReachable = false
    /// A footnote-line notice, auto-cleared on the next reply/push — the
    /// §5 transient-overlay copy (`.sendFailed`, an honest `Reason`, a
    /// version mismatch, or the 10s hard-deadline "didn't answer").
    public private(set) var transientNotice: String?
    /// Non-nil while a `send(_:)` reply is outstanding — the watch shows
    /// "Waking iPhone…" once this has been pending > 0.75s.
    public private(set) var pendingSince: Date?
    /// Flips true once a `send(_:)` reply has been pending > 0.75 s — the
    /// honest wake+reconnect latency tell (§5): a cold burst takes ~1-2 s
    /// vs. the ~100-300 ms warm path, so the view shows "Waking iPhone…"
    /// rather than looking hung.
    public private(set) var isWakingPhone = false

    private var delegate: WatchSessionDelegate?
    private var inboundTask: Task<Void, Never>?
    private var deadlineTask: Task<Void, Never>?
    /// The 0.75 s timer that flips `isWakingPhone` — cancelled and
    /// restarted by every new `send(_:)`, and cancelled outright by every
    /// terminal path (reply/error/10 s deadline) so a stale timer can never
    /// flip `isWakingPhone` back on after the request it belonged to has
    /// already resolved.
    private var wakingTask: Task<Void, Never>?
    /// Set once in `activate()`, held for `send(_:)`'s `sendMessage`
    /// callbacks — see this file's header (fact 16 idiom): the reply/error
    /// handlers ONLY translate their argument into a `Sendable` inbound
    /// value and `continuation.yield(...)` here, they never touch
    /// actor-isolated state directly. This is what makes them safe to fire
    /// from WatchConnectivity's own callback thread instead of hopping onto
    /// `@MainActor` per-callback — the banned `Task {}`-per-callback shape
    /// that could reorder two rapid taps' replies (§4.5).
    private var continuation: AsyncStream<WatchRelayInbound>.Continuation?

    public init() {}

    public func activate() {
        guard WCSession.isSupported() else { return }
        guard delegate == nil else { return }

        let (stream, continuation) = AsyncStream.makeStream(of: WatchRelayInbound.self)
        self.continuation = continuation
        let delegate = WatchSessionDelegate(continuation: continuation)
        self.delegate = delegate
        WCSession.default.delegate = delegate
        WCSession.default.activate()

        inboundTask = Task { [weak self] in
            for await event in stream {
                guard let self else { break }
                await self.handle(event)
            }
        }
    }

    /// `WatchRootView`'s cold-launch pull and `scenePhase` resume — a plain
    /// `.requestState` tap, answered instantly from the hub's snapshot
    /// (never itself a wake-connect).
    public func refresh() {
        send(.requestState)
    }

    /// The ONE command door — see this file's header for why `isReachable`
    /// never gates this call.
    public func send(_ command: WatchRelayCommand) {
        guard isActivated else {
            // Cold launch: `WatchRootView.task` calls `refresh()` before
            // `.activated` has arrived from WCSession — SILENT here for
            // `.requestState` (the very next `.activated(true)` event
            // re-triggers a `refresh()` of its own, `handle(_:)` below), so
            // this guard never flashes a false "iPhone not reachable"
            // before activation has even had a chance to complete. A REAL
            // user-initiated command (Next/Prev/Lyrics/etc.) still gets the
            // honest notice.
            if command != .requestState {
                transientNotice = "iPhone not reachable. Bring your iPhone nearby."
            }
            return
        }
        pendingSince = Date()
        transientNotice = nil
        deadlineTask?.cancel()
        deadlineTask = Task { [weak self] in
            try? await Task.sleep(for: .seconds(10))
            guard let self, !Task.isCancelled else { return }
            self.wakingTask?.cancel()
            self.isWakingPhone = false
            self.pendingSince = nil
            self.transientNotice = "iPhone didn't answer."
        }
        wakingTask?.cancel()
        wakingTask = Task { [weak self] in
            try? await Task.sleep(for: .milliseconds(750))
            guard let self, !Task.isCancelled, self.pendingSince != nil else { return }
            self.isWakingPhone = true
        }

        let data = WatchRelayCodec.encode(command: command)
        WCSession.default.sendMessage(
            [WatchRelayCodec.commandKey: data],
            replyHandler: { [continuation] reply in
                // `nonisolated` callback — ONLY translates + yields, per
                // this file's header idiom; `handleReply` itself runs on
                // the ONE ordered consumer loop below, never here.
                continuation?.yield(.replyData(reply[WatchRelayCodec.replyKey] as? Data ?? Data()))
            },
            errorHandler: { [continuation] error in
                continuation?.yield(.sendFailed(Self.wcErrorCaseName(error)))
            }
        )
    }

    /// The `WCError.code` CASE NAME (`"notReachable"`, `"deviceNotPaired"`,
    /// …) — bounded, log-safe, and stable across OS localizations, unlike
    /// `error.localizedDescription` (a full, USER-FACING, LOCALIZED
    /// sentence that §4.5's spec explicitly warns against logging
    /// `.public`). `IHLog.remote.notice` in `handleSendFailed` logs exactly
    /// this string.
    ///
    /// **All 19 current `WCErrorCode` cases get a name** (the binding
    /// spec's own sketch named only the 11 most relevant to this flow) — a
    /// literal `switch` enumerating all 19 tripped SwiftLint's
    /// `cyclomatic_complexity` gate (21, the watchOS cross-compile is what
    /// first surfaced the missing 8), so this is a plain lookup TABLE
    /// instead: a dictionary miss (a genuinely FUTURE case a later SDK
    /// adds) falls back to the numeric `wcError(<rawValue>)` form — the
    /// same honest fallback `@unknown default` would have given, with zero
    /// branching.
    private static let wcErrorCodeNames: [WCError.Code: String] = [
        .genericError: "genericError",
        .sessionNotSupported: "sessionNotSupported",
        .sessionMissingDelegate: "sessionMissingDelegate",
        .sessionNotActivated: "sessionNotActivated",
        .deviceNotPaired: "deviceNotPaired",
        .watchAppNotInstalled: "watchAppNotInstalled",
        .notReachable: "notReachable",
        .invalidParameter: "invalidParameter",
        .payloadTooLarge: "payloadTooLarge",
        .payloadUnsupportedTypes: "payloadUnsupportedTypes",
        .messageReplyFailed: "messageReplyFailed",
        .messageReplyTimedOut: "messageReplyTimedOut",
        .fileAccessDenied: "fileAccessDenied",
        .deliveryFailed: "deliveryFailed",
        .insufficientSpace: "insufficientSpace",
        .sessionInactive: "sessionInactive",
        .transferTimedOut: "transferTimedOut",
        .companionAppNotInstalled: "companionAppNotInstalled",
        .watchOnlyApp: "watchOnlyApp"
    ]

    private static func wcErrorCaseName(_ error: any Error) -> String {
        guard let code = (error as? WCError)?.code else { return "unknown" }
        return wcErrorCodeNames[code] ?? "wcError(\(code.rawValue))"
    }

    // MARK: - The inbound consumer (the ONE consumer of this stream)

    private func handle(_ event: WatchRelayInbound) async {
        switch event {
        case .activated(let activated):
            isActivated = activated
            if activated { refresh() }
        case .reachabilityChanged(let reachable):
            isPhoneReachable = reachable
            if reachable { refresh() }
        case .snapshotData(let data):
            applySnapshotData(data)
        case .replyData(let data):
            handleReply(data)
        case .sendFailed(let description):
            handleSendFailed(description)
        }
    }

    private func handleReply(_ data: Data) {
        deadlineTask?.cancel()
        wakingTask?.cancel()
        isWakingPhone = false
        pendingSince = nil
        switch WatchRelayCodec.decodeReply(data) {
        case .success(let reply):
            snapshot = reply.snapshot
            transientNotice = Self.noticeCopy(for: reply)
        case .failure(.unsupportedVersion):
            transientNotice = "Update iHymns on your iPhone and Apple Watch."
        case .failure(.malformed):
            transientNotice = "iPhone couldn't complete that."
        }
    }

    private func applySnapshotData(_ data: Data) {
        switch WatchRelayCodec.decodeSnapshot(data) {
        case .success(let decoded):
            snapshot = decoded
        case .failure(.unsupportedVersion):
            transientNotice = "Update iHymns on your iPhone and Apple Watch."
        case .failure(.malformed):
            break // a push is best-effort; never surface a notice for it
        }
    }

    private func handleSendFailed(_ description: String) {
        deadlineTask?.cancel()
        wakingTask?.cancel()
        isWakingPhone = false
        pendingSince = nil
        transientNotice = "iPhone not reachable. Bring your iPhone nearby."
        IHLog.remote.notice("watchrelay.watch send-failed reason=\(description, privacy: .public)")
    }

    /// The §5 `Reason` copy map — `.noSavedTV` is suppressed (the phase
    /// screen already covers it); an unrecognized/absent reason on a
    /// `.failed` outcome is the tolerant-decode fallback (`WatchRelayCodec`'s
    /// header) and gets the generic line.
    private static func noticeCopy(for reply: WatchRelayReply) -> String? {
        guard reply.outcome == .failed else { return nil }
        switch reply.reason {
        case .couldNotReachTV:
            return "Couldn't reach \(reply.snapshot.tvName ?? "the TV"). Is the TV on and on the same network?"
        case .repairNeeded:
            return "The TV no longer trusts this phone — re-pair in iHymns on your iPhone."
        case .busyPairing:
            return "Finish the pairing step on your iPhone first."
        case .busyConnecting:
            return "Your iPhone is connecting — try again in a moment."
        case .noSavedTV:
            return nil
        case nil:
            return "iPhone couldn't complete that."
        }
    }
}
#endif
