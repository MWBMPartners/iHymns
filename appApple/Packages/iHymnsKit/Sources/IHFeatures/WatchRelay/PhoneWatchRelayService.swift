// PhoneWatchRelayService.swift
// IHFeatures/WatchRelay
//
// ELI5: The iPhone's half of the watch link — wakes up (even in the
// background, even locked) whenever the watch sends a message, hands the
// command to the traffic cop, answers honestly, and pushes the watch a
// fresh status picture whenever it changes — all while making sure iOS
// never kills the app mid-command.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §4.4/§4.5 —
// BINDING). `#if os(iOS) && canImport(WatchConnectivity)` — `WCSession
// .isSupported()` is `false` on iPad/Mac/tvOS/visionOS, so this whole type
// is a runtime no-op there too; `@available(iOSApplicationExtension,
// unavailable)` because it touches `UIApplication` background-task APIs
// (fact 21 — no extension links `IHFeatures` today, but this is
// future-proofing, not load-bearing). Needs NO entitlement and NO
// `Info.plist` key (fact 14/15) — `WCSession.sendMessage` waking a
// background launch is a documented, built-in framework behaviour.
import Foundation
#if os(iOS) && canImport(WatchConnectivity)
import IHLive
import IHLog
import UIKit
import WatchConnectivity
import os

/// The ONE `@unchecked Sendable` in this PR (D-14) — WCSession's
/// `replyHandler` is a plain `([String: Any]) -> Void`, thread-agnostic and
/// not itself `@Sendable`. This box stores the closure, exposes ONLY
/// `send(replyData:)` (the non-Sendable dictionary is built and consumed
/// entirely INSIDE this one function, never crossing an isolation
/// boundary), and is call-once-enforced via `OSAllocatedUnfairLock` —
/// double-calling a WCSession reply handler is an Apple-side exception, so
/// this makes it structurally impossible. Logs nothing (§8 row 10).
final class WatchRelayReplyBox: @unchecked Sendable {
    private let hasReplied = OSAllocatedUnfairLock(initialState: false)
    private let rawReply: ([String: Any]) -> Void

    init(rawReply: @escaping ([String: Any]) -> Void) {
        self.rawReply = rawReply
    }

    func send(replyData: Data) {
        let shouldSend = hasReplied.withLock { alreadyReplied -> Bool in
            guard !alreadyReplied else { return false }
            alreadyReplied = true
            return true
        }
        guard shouldSend else { return }
        rawReply([WatchRelayCodec.replyKey: replyData])
    }
}

/// The Sendable value the delegate shell yields into its ONE ordered
/// stream — see this file's header + `RemoteControlSession.Configuration`'s
/// doc comment for why `SessionController.stateUpdates`'s idiom (a
/// `nonisolated` `AsyncStream` fed by a continuation) is copied here rather
/// than `Task {}`-per-callback (two rapid Next taps must not reorder).
enum PhoneRelayInbound: Sendable {
    case activated(Bool)
    case reachabilityChanged(Bool)
    case command(Data, WatchRelayReplyBox)
    case needsReactivate
}

/// `nonisolated` — callbacks ONLY translate arguments into a `Sendable`
/// value and `continuation.yield(...)`, never touch actor-isolated state
/// directly (this file's header idiom).
final class PhoneRelayDelegate: NSObject, WCSessionDelegate, Sendable {
    private let continuation: AsyncStream<PhoneRelayInbound>.Continuation

    init(continuation: AsyncStream<PhoneRelayInbound>.Continuation) {
        self.continuation = continuation
    }

    func session(_ session: WCSession, activationDidCompleteWith activationState: WCSessionActivationState, error: Error?) {
        continuation.yield(.activated(activationState == .activated))
    }

    func sessionReachabilityDidChange(_ session: WCSession) {
        continuation.yield(.reachabilityChanged(session.isReachable))
    }

    func session(_ session: WCSession, didReceiveMessage message: [String: Any], replyHandler: @escaping ([String: Any]) -> Void) {
        let box = WatchRelayReplyBox(rawReply: replyHandler)
        guard let data = message[WatchRelayCodec.commandKey] as? Data else {
            // Malformed shape — STILL answer (a swallowed reply surfaces as
            // a spurious watch timeout); no command to decode, so no
            // `.command` case is yielded.
            box.send(replyData: WatchRelayCodec.encode(reply: .init(outcome: .failed, snapshot: .noSavedTV)))
            return
        }
        continuation.yield(.command(data, box))
    }

    func sessionDidBecomeInactive(_ session: WCSession) {}

    func sessionDidDeactivate(_ session: WCSession) {
        continuation.yield(.needsReactivate)
    }
}

/// The iPhone WCSession shell — see this file's header for the full
/// picture.
@available(iOSApplicationExtension, unavailable)
@MainActor
public final class PhoneWatchRelayService {
    public static let shared = PhoneWatchRelayService()

    private var driver: HeadlessRelayDriver?
    private var delegate: PhoneRelayDelegate?
    private var inboundTask: Task<Void, Never>?
    private var lingerTaskID: UIBackgroundTaskIdentifier = .invalid

    private init() {}

    /// Called from `IHymnsApp.init()` — EVERY launch path, including a
    /// watch-triggered BACKGROUND launch (where scene bodies/`.task` may
    /// never run, spec §2's `IHymnsApp.swift` edit note). Idempotent;
    /// runtime no-op when `WCSession.isSupported() == false` (iPad/Mac/
    /// visionOS).
    public func activate() {
        guard WCSession.isSupported() else { return }
        guard delegate == nil else { return }

        let driver = HeadlessRelayDriver()
        self.driver = driver
        RemoteControlRelayHub.shared.attachDriver(driver)
        RemoteControlRelayHub.shared.onSnapshot = { [weak self] snapshot in
            self?.push(snapshot)
        }

        let (stream, continuation) = AsyncStream.makeStream(of: PhoneRelayInbound.self)
        let delegate = PhoneRelayDelegate(continuation: continuation)
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

    // MARK: - The inbound consumer (the ONE consumer of this stream)

    private func handle(_ event: PhoneRelayInbound) async {
        switch event {
        case .activated(true):
            // Covers the background-launch-by-watch-message path AND
            // refreshes applicationContext every launch.
            await driver?.publishBaseline()
            push(RemoteControlRelayHub.shared.lastMerged)
        case .activated(false):
            break
        case .reachabilityChanged(let reachable):
            if reachable {
                push(RemoteControlRelayHub.shared.lastMerged) // closes any missed-push gap
            }
        case .needsReactivate:
            WCSession.default.activate() // Apple's documented watch-switch dance
        case .command(let data, let box):
            await handleCommand(data, box: box)
        }
    }

    private func handleCommand(_ data: Data, box: WatchRelayReplyBox) async {
        let taskID = beginBackgroundTask(name: "watchrelay.command")
        defer { endBackgroundTask(taskID) }

        let reply: WatchRelayReply
        switch WatchRelayCodec.decodeCommand(data) {
        case .success(let command):
            reply = await RemoteControlRelayHub.shared.handle(command)
        case .failure:
            // STILL answer — a swallowed reply surfaces as a spurious
            // watch timeout; the watch's own version copy decides what to
            // show.
            reply = WatchRelayReply(outcome: .failed, snapshot: RemoteControlRelayHub.shared.lastMerged)
        }
        box.send(replyData: WatchRelayCodec.encode(reply: reply))
    }

    // MARK: - State push (dual-channel, D-7)

    private func push(_ snapshot: WatchRelaySnapshot) {
        updateLingerBracket(for: snapshot.phase)
        let session = WCSession.default
        guard session.activationState == .activated else { return }

        let data = WatchRelayCodec.encode(snapshot: snapshot)
        if session.isReachable {
            session.sendMessage([WatchRelayCodec.snapshotKey: data], replyHandler: nil, errorHandler: nil)
        }
        if session.isPaired, session.isWatchAppInstalled {
            try? session.updateApplicationContext([WatchRelayCodec.snapshotKey: data])
        }
    }

    // MARK: - Background-execution brackets (§4.4 — where UIKit is allowed)

    /// The command bracket: begun when a `.command` arrives, ended right
    /// after the reply is sent — guarantees the decode→route→(wake-
    /// connect)→forward→reply pipeline survives even a stingy background
    /// runtime grant.
    private func beginBackgroundTask(name: String) -> UIBackgroundTaskIdentifier {
        UIApplication.shared.beginBackgroundTask(withName: name) {
            IHLog.remote.debug("watchrelay.phone command-task-expired")
        }
    }

    private func endBackgroundTask(_ taskID: UIBackgroundTaskIdentifier) {
        guard taskID != .invalid else { return }
        UIApplication.shared.endBackgroundTask(taskID)
    }

    /// The linger bracket: begun the moment the driver reports a live burst
    /// (`.connecting`/`.controlling`), ended once it returns to
    /// `.standby`/`.noSavedTV`/`.pairing`. Its `expirationHandler` forces
    /// the driver down immediately — the phone NEVER holds a connection
    /// past what iOS grants; the idle default (20s) undercuts the ~30s
    /// budget with margin anyway.
    private func updateLingerBracket(for phase: WatchRelaySnapshot.Phase) {
        switch phase {
        case .connecting, .controlling:
            beginLingerBracketIfNeeded()
        case .noSavedTV, .standby, .pairing:
            endLingerBracketIfNeeded()
        }
    }

    private func beginLingerBracketIfNeeded() {
        guard lingerTaskID == .invalid else { return }
        lingerTaskID = UIApplication.shared.beginBackgroundTask(withName: "watchrelay.linger") { [weak self] in
            IHLog.remote.debug("watchrelay.phone linger-task-expired")
            Task { @MainActor in
                await RemoteControlRelayHub.shared.expireBackgroundBudget()
                self?.endLingerBracketIfNeeded()
            }
        }
    }

    private func endLingerBracketIfNeeded() {
        guard lingerTaskID != .invalid else { return }
        let taskID = lingerTaskID
        lingerTaskID = .invalid
        UIApplication.shared.endBackgroundTask(taskID)
    }
}
#endif
