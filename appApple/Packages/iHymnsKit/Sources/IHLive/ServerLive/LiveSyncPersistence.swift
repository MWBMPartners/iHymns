// LiveSyncPersistence.swift
// IHLive/ServerLive
//
// ELI5: The little notebook where the engines write down "I was hosting
// under this code" / "I was following this code with this token" / "I was
// present in this service with this token" — so a relaunch (not just a
// brief background) can pick a following/presence session back up.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.6, D-5/D-10). THREE UserDefaults records, JSON-encoded, injectable
// store — deliberately **UserDefaults, NOT Keychain, NOT synchronizable**
// (D-5): the presence token is an anonymous, ≤4 h, server-revocable room
// key, not a credential; Keychain would (a) survive app deletion and (b)
// invite the `synchronizable: true` copy-paste failure PR-6 §8 guards
// against (a presence token synced to a device NOT physically in the room
// would defeat proof-of-presence). The web keeps the equivalent in per-tab
// `sessionStorage` (`service-follow.js:18`); local-only UserDefaults is the
// closest native analogue that still survives a mid-service relaunch.
import Foundation

/// Resume-record storage for hosting/following/presence sessions — see this
/// file's header. `@unchecked Sendable` for the same reason `RecentSearchesStore`
/// documents (this SDK's `UserDefaults` has no `Sendable` conformance yet,
/// despite being documented thread-safe).
public struct LiveSyncPersistence: @unchecked Sendable {
    private let defaults: UserDefaults

    /// The real, `.standard`-backed instance every non-test caller uses.
    public static let standard = LiveSyncPersistence()

    public init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    private enum Keys {
        static let hostSession = "live.hostSession"
        static let followSession = "live.followSession"
        static let servicePresence = "live.servicePresence"
    }

    // MARK: - Host session (`LiveFollowEngine+Host.swift`)

    func saveHostSession(code: String) {
        save(HostSessionRecord(code: code), forKey: Keys.hostSession)
    }

    func clearHostSession() {
        defaults.removeObject(forKey: Keys.hostSession)
    }

    // MARK: - Follow session (`LiveFollowEngine+Follower.swift`)

    func saveFollowSession(code: String, token: String, revision: Int) {
        save(FollowSessionRecord(code: code, token: token, revision: revision), forKey: Keys.followSession)
    }

    /// Refreshes ONLY the revision on an already-persisted follow session —
    /// a no-op if none exists (e.g. persistence was cleared mid-poll-loop
    /// teardown, a benign race with no observable effect).
    func updateFollowRevision(_ revision: Int) {
        guard let record: FollowSessionRecord = load(forKey: Keys.followSession) else { return }
        save(FollowSessionRecord(code: record.code, token: record.token, revision: revision), forKey: Keys.followSession)
    }

    func clearFollowSession() {
        defaults.removeObject(forKey: Keys.followSession)
    }

    // MARK: - Service presence (`ServiceModeEngine.swift`)

    /// One point-in-time presence record: the opaque token, the last-known
    /// revision, when this device joined (the 4 h hard-ceiling clock — spec
    /// §3.5/D-10), and (Apple Phase-2 PR-15, #1428) the server-declared poll
    /// cadence from the join that created this record.
    ///
    /// `pollIntervalMs` is `Int?` (not a plain `Int`) for TWO independent
    /// reasons, both of which need it to decode successfully even when
    /// absent: (1) the join response itself may omit it (an un-migrated
    /// backend, `ServiceJoinInfo.pollIntervalMs`'s own doc comment), and (2)
    /// a record persisted by a PRE-PR-15 build of this app never wrote this
    /// key at all — `JSONDecoder` treats a missing `Optional` key as `nil`
    /// rather than failing the whole decode, so an old on-disk record from
    /// before this PR still loads via `loadServicePresence()`'s existing
    /// `try?` (this file's header: "Optional ⇒ legacy records decode via
    /// the existing try? loader").
    public struct ServicePresenceRecord: Codable, Sendable {
        public let token: String
        public let revision: Int
        public let joinedAt: Date
        public let pollIntervalMs: Int?
    }

    func saveServicePresence(token: String, revision: Int, joinedAt: Date, pollIntervalMs: Int? = nil) {
        save(ServicePresenceRecord(token: token, revision: revision, joinedAt: joinedAt, pollIntervalMs: pollIntervalMs), forKey: Keys.servicePresence)
    }

    /// Refreshes ONLY the revision — `pollIntervalMs` (like `token`/
    /// `joinedAt`) is carried forward UNCHANGED from the existing record, so
    /// a mid-session poll never quietly resets the cadence this device
    /// joined with.
    func updateServiceRevision(_ revision: Int) {
        guard let record = loadServicePresence() else { return }
        save(
            ServicePresenceRecord(token: record.token, revision: revision, joinedAt: record.joinedAt, pollIntervalMs: record.pollIntervalMs),
            forKey: Keys.servicePresence
        )
    }

    /// Reads the persisted presence record, if any — `ServiceModeEngine
    /// .resumeIfPossible()`'s cold-start entry point.
    public func loadServicePresence() -> ServicePresenceRecord? {
        load(forKey: Keys.servicePresence)
    }

    func clearServicePresence() {
        defaults.removeObject(forKey: Keys.servicePresence)
    }

    // MARK: - Storage primitives

    private func save<T: Encodable>(_ value: T, forKey key: String) {
        guard let data = try? JSONEncoder().encode(value) else { return }
        defaults.set(data, forKey: key)
    }

    private func load<T: Decodable>(forKey key: String) -> T? {
        guard let data = defaults.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(T.self, from: data)
    }
}

private struct HostSessionRecord: Codable {
    let code: String
}

private struct FollowSessionRecord: Codable {
    let code: String
    let token: String
    let revision: Int
}
