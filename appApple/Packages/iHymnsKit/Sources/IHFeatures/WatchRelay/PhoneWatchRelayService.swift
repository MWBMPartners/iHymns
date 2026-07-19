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
//
// **The linger bracket's dedup safety (a deliberate refinement of the
// spec's named `onDriverActivity` hook, which does not exist on the built
// `RemoteControlRelayHub`/`HeadlessRelayDriver` — do NOT add it; this
// file's `push(_:)`, driven from the hub's already-deduped `onSnapshot`,
// is the correct trigger):** `updateLingerBracket(for:)` derives the
// bracket purely from the MERGED snapshot's `phase` inside `push()`. This
// is safe against `RemoteControlRelayHub.republish()`'s own
// `guard merged != lastMerged else { return }` dedup because (a) a
// headless burst ALWAYS transits through merged `.connecting` before it
// can ever reach `.controlling` — the begin-bracket branch is therefore
// never skipped by dedup swallowing an intermediate state; and (b) every
// teardown path ends in `HeadlessRelayDriver.publishBaseline()`, which
// always republishes `.standby`/`.noSavedTV` — the end-bracket branch is
// therefore never skipped either. Holding the bracket open while the
// FOREGROUND coordinator is controlling (merged phase also reads
// `.controlling` then) is legal and free per §4.4's "begin/end while
// foreground is registration only, expiration never fires" — and
// self-heals the moment backgrounding suspends the coordinator (its
// `.standby` republish ends the bracket the ordinary way).
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
            // `.command` case is yielded. `.noSavedTV` (not `lastMerged`) is
            // the only snapshot available here: this delegate is
            // `nonisolated` and cannot synchronously read the `@MainActor`
            // hub's `lastMerged` from inside a callback. Genuine command
            // bytes that merely fail to DECODE still get the real
            // `lastMerged` — that path runs on the consumer (`handleCommand`,
            // `@MainActor`), which can read it.
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
    /// UUID-keyed rather than a single `var` slot: `beginBackgroundTask`'s
    /// `expirationHandler` and `handleCommand`'s own `defer` can BOTH race
    /// to end the SAME bracket (the expiration handler firing moments before
    /// the reply finishes encoding) — a naive shared var would let the
    /// second caller read a value the first already invalidated (a Swift 6
    /// data-race on plain `var` capture), and worse, could double-end (or
    /// silently end a LATER command's bracket) if two commands overlapped.
    /// Keying by a fresh `UUID` per bracket plus `removeValue(forKey:)`'s
    /// idempotent nil-on-miss makes double-end and cross-command aliasing
    /// structurally impossible.
    private var commandBrackets: [UUID: UIBackgroundTaskIdentifier] = [:]

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
        let bracket = beginCommandBracket()
        defer { endCommandBracket(bracket) }

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
    /// runtime grant. Returns `nil` when iOS refuses the grant outright
    /// (`beginBackgroundTask` returning `.invalid`) — `handleCommand` still
    /// runs (best-effort), it just isn't bracketed.
    ///
    /// ELI5: "Ask iOS for a little extra time to finish this one command,
    /// and remember which command this timer belongs to."
    private func beginCommandBracket() -> UUID? {
        let key = UUID()
        let identifier = UIApplication.shared.beginBackgroundTask(withName: "watchrelay.command") { [weak self] in
            // iOS wants the time back before the reply finished — force
            // this bracket closed from the SAME actor `endCommandBracket`
            // itself runs on, so the dictionary mutation is never touched
            // from two isolation domains at once.
            Task { @MainActor in
                IHLog.remote.debug("watchrelay.phone command-task-expired")
                self?.endCommandBracket(key)
            }
        }
        guard identifier != .invalid else { return nil }
        commandBrackets[key] = identifier
        return key
    }

    /// `removeValue(forKey:)` returns `nil` (a silent no-op) on a KEY that
    /// was already ended — the expiration handler firing and `handleCommand`'s
    /// own `defer` racing to close the SAME bracket can therefore never
    /// double-end it (double-ending a `UIBackgroundTaskIdentifier` is an
    /// Apple-side assertion crash).
    private func endCommandBracket(_ key: UUID?) {
        guard let key, let identifier = commandBrackets.removeValue(forKey: key) else { return }
        UIApplication.shared.endBackgroundTask(identifier)
    }

    /// The linger bracket: begun the moment the MERGED snapshot reports a
    /// live burst (`.connecting`/`.controlling`), ended once it returns to
    /// `.standby`/`.noSavedTV`/`.pairing`. See this file's header for why
    /// deriving this from `push()`'s merged phase — rather than the spec's
    /// non-existent `onDriverActivity` hook — is dedup-safe on both edges.
    /// Its `expirationHandler` forces the driver down immediately (expire
    /// FIRST, then end the bracket) — the phone NEVER holds a connection
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
