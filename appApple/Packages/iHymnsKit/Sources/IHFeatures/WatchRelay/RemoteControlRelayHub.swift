// RemoteControlRelayHub.swift
// IHFeatures/WatchRelay
//
// ELI5: The one traffic cop between "a watch button was pressed" and
// "something on the phone actually does it" — it knows whether the
// on-screen remote is open right now (so it can just forward there) or
// whether nobody's looking at the screen (so a background connection
// should handle it instead), and it's the ONE place that decides what the
// watch's little status picture should say.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §3.4 —
// BINDING). Platform-NEUTRAL and WatchConnectivity-free (D-3) — compiles on
// every platform `IHFeatures` targets, idles everywhere but iOS (nothing
// ever calls `handle(_:)` on a platform with no `PhoneWatchRelayService`).
// A single-slot, `weak`, identity-checked coordinator registry (never a
// second `session.events`/actor-stream consumer — D-2, unchanged) plus a
// STRONG driver slot (the headless driver is meant to live for the process
// lifetime, spec §4.2, unlike the screen-scoped coordinator).
//
// **Why `driver` is typed `any HeadlessRelayServicing`, not the concrete
// `HeadlessRelayDriver` the binding spec's own code sketch shows:** the
// concrete driver type is built in a LATER commit (§9 commit 4) that reuses
// this file unchanged — introducing the narrow protocol seam here lets this
// commit compile and its own test suite go green on its own, exactly the
// "each commit compiles + tests green" discipline this PR's commit plan
// requires, without dictating anything about the driver's OWN
// implementation (`HeadlessRelayDriver` is later declared `final class
// HeadlessRelayDriver: HeadlessRelayServicing`). Functionally identical to
// the spec's sketch once the one real conformer exists.
import Foundation
import IHLive
import IHLog

/// The narrow surface this hub needs from the headless driver — see this
/// file's header for why it's a protocol rather than the concrete type.
///
/// ELI5: "Everything the traffic cop needs to know about (and be able to
/// ask of) the background connection."
@MainActor
public protocol HeadlessRelayServicing: AnyObject {
    /// Non-nil ONLY during a live burst (`.connecting`/`.controlling`) —
    /// the hub's merge treats a non-nil value here as the highest-priority
    /// source (`WatchRelayRules.mergeSnapshot`).
    var liveSnapshot: WatchRelaySnapshot? { get }
    /// Always present — `.standby(tvName:)`/`.noSavedTV`, refreshed by
    /// `publishBaseline()`.
    var baselineSnapshot: WatchRelaySnapshot { get }

    func perform(_ command: WatchRelayCommand) async -> WatchRelayReply
    /// The handoff door — awaits COMPLETE teardown before returning;
    /// idempotent, a no-op when idle.
    func retire() async
    /// Background budget expiring — immediate clean disconnect.
    func forceDown() async
}

/// The ONE coupling point between the screen-scoped `RemoteControlCoordinator`
/// (foreground) and the process-lifetime headless driver (background) — see
/// this file's header for the full picture.
@MainActor
public final class RemoteControlRelayHub {
    public static let shared = RemoteControlRelayHub()

    private weak var coordinator: RemoteControlCoordinator?
    private var coordinatorSnapshot: WatchRelaySnapshot?
    /// Built lazily on iOS by `PhoneWatchRelayService.activate()`; `nil`
    /// forever on every other platform. Strong — the driver, once built,
    /// lives for the process lifetime (spec §4.2), unlike the weak
    /// `coordinator` slot above.
    private(set) var driver: (any HeadlessRelayServicing)?

    /// Set once by the iOS service; fired only on CHANGE (`Equatable`
    /// dedup, `republish()`).
    public var onSnapshot: (@MainActor (WatchRelaySnapshot) -> Void)?
    public private(set) var lastMerged: WatchRelaySnapshot = .noSavedTV

    /// `public` (not the spec sketch's bare `init`) so tests can build
    /// isolated instances instead of sharing process-wide `.shared` state.
    public init() {}

    // MARK: - Coordinator registry (single-slot, identity-checked unregister)

    func register(_ coordinator: RemoteControlCoordinator) {
        self.coordinator = coordinator
        republish()
    }

    /// Identity-checked (`===`) so a STALE unregister — the previous
    /// screen's `stop()` finally running after a NEW screen already
    /// `register`ed — can never evict a successor.
    func unregister(_ coordinator: RemoteControlCoordinator) {
        guard self.coordinator === coordinator else { return }
        self.coordinator = nil
        coordinatorSnapshot = nil
        republish()
    }

    /// From the coordinator's `apply(_:)` tap — a tap of ALREADY-APPLIED
    /// state, never a second consumer of anything (D-2).
    func publishCoordinator(_ snapshot: WatchRelaySnapshot) {
        coordinatorSnapshot = snapshot
        republish()
    }

    // MARK: - Driver slot

    func attachDriver(_ driver: any HeadlessRelayServicing) {
        self.driver = driver
    }

    /// The driver's OWN state changed (a burst started/ended, the baseline
    /// refreshed) — recompute the merge and republish if it changed.
    func publishDriver() {
        republish()
    }

    // MARK: - Command dispatch (spec §3.4's `handle`, exact)

    /// ELI5: "A watch button was pressed — figure out who should handle it,
    /// and answer honestly."
    public func handle(_ command: WatchRelayCommand) async -> WatchRelayReply {
        let phase = coordinator == nil ? nil : coordinatorSnapshot?.phase
        let route = WatchRelayRules.route(command, coordinatorPhase: phase)
        let reply = await execute(route, for: command)
        IHLog.remote.notice(
            "watchrelay.hub command=\(command.rawValue, privacy: .public) route=\(Self.routeCaseName(route), privacy: .public) outcome=\(reply.outcome.rawValue, privacy: .public)"
        )
        return reply
    }

    private func execute(_ route: WatchRelayRules.WatchRelayRoute, for command: WatchRelayCommand) async -> WatchRelayReply {
        switch route {
        case .foreground:
            // The pre-echo snapshot — acknowledgement + truth, not
            // prediction; the TV's echo arrives via push moments later.
            if let intent = WatchRelayRules.intent(for: command) {
                await coordinator?.sendIntent(intent)
            }
            return WatchRelayReply(outcome: .forwarded, snapshot: lastMerged)
        case .replyOnly:
            return WatchRelayReply(outcome: .replyOnly, snapshot: lastMerged)
        case .reject(let reason):
            return WatchRelayReply(outcome: .failed, reason: reason, snapshot: lastMerged)
        case .headless:
            // A `nil` driver is a non-iOS platform — unreachable in
            // practice (the hub only ever runs where `PhoneWatchRelayService`
            // built one); the fallback is defensive, never load-bearing.
            guard let driver else {
                return WatchRelayReply(outcome: .failed, reason: .noSavedTV, snapshot: lastMerged)
            }
            return await driver.perform(command)
        }
    }

    // MARK: - The single-owner handoff door (spec §4.3)

    /// Awaits the driver's full teardown (`endControl` + `stop`) before
    /// returning — every coordinator connect proceeds only after this
    /// resolves. No-op when idle/no driver.
    public func retireHeadless() async {
        await driver?.retire()
    }

    /// Background budget expiring (spec §4.4) — force the driver down NOW.
    public func expireBackgroundBudget() async {
        await driver?.forceDown()
    }

    // MARK: - Merge + change-dedup

    private func republish() {
        let merged = WatchRelayRules.mergeSnapshot(
            coordinator: coordinatorSnapshot,
            driverLive: driver?.liveSnapshot,
            driverBaseline: driver?.baselineSnapshot ?? .noSavedTV
        )
        guard merged != lastMerged else { return }
        lastMerged = merged
        onSnapshot?(merged)
    }

    private static func routeCaseName(_ route: WatchRelayRules.WatchRelayRoute) -> String {
        switch route {
        case .foreground: return "foreground"
        case .headless: return "headless"
        case .replyOnly: return "replyOnly"
        case .reject: return "reject"
        }
    }
}
