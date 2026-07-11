// LANRemoteClock.swift
// IHLive/LANRemote
//
// ELI5: Instead of `TVListenerActor`/`RemoteSessionActor` asking "what time
// is it right now?" directly, they ask a little clock object — which means a
// TEST can hand them a fake clock that ticks exactly when the test wants it
// to, instead of the test having to `sleep()` for real seconds.
//
// DETAILED: #1420. The task brief's binding test requirement: "Injected
// clock (no wall-clock sleeps)" — mirrors the EXACT same seam
// `LiveFollowEngine.isFresh(lastUpdatedAt:now:)` already establishes in
// this same `IHLive` target (that function takes `now` as a parameter
// rather than reading `Date()` internally) and the pattern `.claude/apple-native-strategy.md`
// §3.3 calls for repo-wide: "injected-clock tests for IHLive cadence/
// freshness (no wall-clock sleeps)". Every timestamp an `IHRPFrame` needs
// (both `RemoteSessionActor`'s outgoing `seq`/`timestamp` pair and
// `TVListenerActor`'s ping-timeout bookkeeping) goes through this
// abstraction so `LANRemoteTests` can assert exact seq/timestamp ordering
// deterministically.
import Foundation

/// Something that can produce the current `Date` — the ONE seam every
/// LANRemote clock read goes through.
///
/// ELI5: "What time is it?" — ask this instead of `Date()` directly.
///
/// DETAILED: `Sendable` (a stateless protocol requirement) so a conforming
/// type can be safely captured by both `TVListenerActor` and
/// `RemoteSessionActor` across their actor-isolation boundaries.
public protocol LANRemoteClock: Sendable {
    func now() -> Date
}

/// The real clock — reads the device's actual current time. This is what
/// every non-test caller (the tvOS app, the remote-device app) uses.
///
/// ELI5: The normal, real-world clock.
public struct SystemLANRemoteClock: LANRemoteClock {
    public init() {}
    public func now() -> Date { Date() }
}
