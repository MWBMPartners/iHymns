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

    private var delegate: WatchSessionDelegate?
    private var inboundTask: Task<Void, Never>?
    private var deadlineTask: Task<Void, Never>?

    public init() {}

    public func activate() {
        guard WCSession.isSupported() else { return }
        guard delegate == nil else { return }

        let (stream, continuation) = AsyncStream.makeStream(of: WatchRelayInbound.self)
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
            transientNotice = "iPhone not reachable. Bring your iPhone nearby."
            return
        }
        pendingSince = Date()
        transientNotice = nil
        deadlineTask?.cancel()
        deadlineTask = Task { [weak self] in
            try? await Task.sleep(for: .seconds(10))
            guard let self, !Task.isCancelled else { return }
            self.pendingSince = nil
            self.transientNotice = "iPhone didn't answer."
        }

        let data = WatchRelayCodec.encode(command: command)
        WCSession.default.sendMessage(
            [WatchRelayCodec.commandKey: data],
            replyHandler: { [weak self] reply in
                guard let replyData = reply[WatchRelayCodec.replyKey] as? Data else { return }
                Task { @MainActor in self?.handleReply(replyData) }
            },
            errorHandler: { error in
                Task { @MainActor in self.handleSendFailed(error.localizedDescription) }
            }
        )
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
