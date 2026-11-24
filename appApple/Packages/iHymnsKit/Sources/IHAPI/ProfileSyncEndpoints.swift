// ProfileSyncEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards and answer shapes for `profile_sync`: the one call the
// server offers to keep a person's small personal settings (theme, colour-vision
// mode, text size, ...) in step across their devices.
//
// DETAILED: PR 3b step I4 (#2180). Verified against `api.php`'s
// `case 'profile_sync'` and `includes/profile_sync.php` (read 2026-11-24):
//   - READ: GET `?action=profile_sync&cursor=N` returns everything the account
//     changed after version N.
//   - SEND: POST `{schemaVersion, client:{platform, appVersion}, cursor,
//     changes:[{section, key, op:"set"|"delete", value?}]}` applies the
//     changes (at most 200, body at most 64 KiB) and then reads, in the same
//     reply.
//   - ANSWER: `{ok, cursor, more, resetCursor, schemaVersion, changes:[{section,
//     key, value, deleted, version}], rejected:[{section, key, reason}], applied}`.
//   - REFUSALS the app acts on, by status plus reason word (rule #35):
//     409 `profile_sync_disabled` / `profile_sync_not_ready` (not switched on
//     here: the app does nothing), 409 `account_writes_off_here` (saving is
//     paused on this site: keep the changes and try later), 413 `body_too_large`
//     / `too_many_changes` (send fewer), 401, 429, 503. Every endpoint here
//     captures the reason word.
// A server change whose value is not a plain scalar (a list, an object: the
// web's reading preferences, say) decodes with `value: nil` rather than
// failing, because the app does not map those settings and must still read
// the rest of the page.
import Foundation
import IHModels

/// The machine reason words the app acts on when `profile_sync` refuses, written down once.
///
/// ELI5: Why the server said no, in a word the app understands.
///
/// DETAILED: The contract is the status PLUS this word, never the server's
/// sentence (rule #35), so the engine compares against these cases instead of
/// typed-in text. The raw values must equal the server's constants
/// (`PROFILE_SYNC_DISABLED_REASON`, `PROFILE_SYNC_NOT_READY_REASON` in
/// `includes/profile_sync_gate.php`, `ACCOUNT_WRITES_OFF_REASON` in
/// `includes/account_write_gate.php`); `tests/test-profile-registry-lockstep.js`
/// section 4 compares them from the source.
public enum ProfileSyncReason: String, Sendable {
    /// 409: this site has not switched the new account sync on.
    case disabled = "profile_sync_disabled"
    /// 409: switched on, but the database objects do not exist yet.
    case notReady = "profile_sync_not_ready"
    /// 409 on a send: saving to accounts is paused on this site.
    case paused = "account_writes_off_here"
}

/// One change the app sends.
///
/// ELI5: "Set `appearance.theme` to dark" or "remove `appearance.cvd`".
public struct ProfileSyncChange: Sendable, Equatable, Encodable {
    public enum Operation: String, Sendable, Equatable { case set, delete }

    public let section: String
    public let key: String
    public let op: Operation
    /// The new value for `.set`; `nil` for `.delete`.
    public let value: ProfileValue?

    public init(section: String, key: String, op: Operation, value: ProfileValue? = nil) {
        self.section = section
        self.key = key
        self.op = op
        self.value = value
    }

    private enum CodingKeys: String, CodingKey { case section, key, op, value }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(section, forKey: .section)
        try container.encode(key, forKey: .key)
        try container.encode(op.rawValue, forKey: .op)
        // `value` only for a set: a delete carries none (the server would refuse a null set).
        if op == .set, let value { try container.encode(value, forKey: .value) }
    }
}

/// A whole send.
///
/// ELI5: "Here is my cursor and these changes."
public struct ProfileSyncRequest: Sendable, Equatable {
    public let schemaVersion: Int
    public let appVersion: String?
    public let cursor: Int
    public let changes: [ProfileSyncChange]

    public init(schemaVersion: Int, appVersion: String?, cursor: Int, changes: [ProfileSyncChange]) {
        self.schemaVersion = schemaVersion
        self.appVersion = appVersion
        self.cursor = cursor
        self.changes = changes
    }
}

/// One change the server reports.
///
/// ELI5: "On the account, `appearance.theme` is now dark" (or was removed).
public struct ProfileServerChange: Sendable, Equatable, Decodable {
    public let section: String
    public let key: String
    /// The value, or `nil` for a removal or for a value that is not a plain scalar.
    public let value: ProfileValue?
    public let deleted: Bool
    public let version: Int

    public init(section: String, key: String, value: ProfileValue?, deleted: Bool, version: Int) {
        self.section = section
        self.key = key
        self.value = value
        self.deleted = deleted
        self.version = version
    }

    private enum CodingKeys: String, CodingKey { case section, key, value, deleted, version }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        section = try container.decode(String.self, forKey: .section)
        key = try container.decode(String.self, forKey: .key)
        deleted = try container.decodeIfPresent(Bool.self, forKey: .deleted) ?? false
        version = try container.decodeIfPresent(Int.self, forKey: .version) ?? 0
        // A list or object value is not a scalar: read it as "no mappable value", never a failure.
        value = try? container.decode(ProfileValue.self, forKey: .value)
    }
}

/// A change the server refused, with the reason word.
///
/// ELI5: "I did not take `x.y`, because `invalid_value`."
public struct ProfileSyncRejection: Sendable, Equatable, Decodable {
    public let section: String
    public let key: String
    public let reason: String

    public init(section: String, key: String, reason: String) {
        self.section = section
        self.key = key
        self.reason = reason
    }
}

/// The server's answer to a read or a send.
///
/// ELI5: "Here is what changed, where to carry on from, and what I refused."
public struct ProfileSyncReply: Sendable, Equatable, Decodable {
    public let cursor: Int
    /// Another page is waiting: ask again from `cursor`.
    public let more: Bool
    /// The cursor we sent was higher than the account ever reached (a restored backup): `cursor` is the real one.
    public let resetCursor: Bool
    public let changes: [ProfileServerChange]
    public let rejected: [ProfileSyncRejection]
    public let applied: Int

    public init(
        cursor: Int,
        more: Bool = false,
        resetCursor: Bool = false,
        changes: [ProfileServerChange] = [],
        rejected: [ProfileSyncRejection] = [],
        applied: Int = 0
    ) {
        self.cursor = cursor
        self.more = more
        self.resetCursor = resetCursor
        self.changes = changes
        self.rejected = rejected
        self.applied = applied
    }

    private enum CodingKeys: String, CodingKey { case cursor, more, resetCursor, changes, rejected, applied }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        cursor = try container.decode(Int.self, forKey: .cursor)
        more = try container.decodeIfPresent(Bool.self, forKey: .more) ?? false
        resetCursor = try container.decodeIfPresent(Bool.self, forKey: .resetCursor) ?? false
        changes = try container.decodeIfPresent([ProfileServerChange].self, forKey: .changes) ?? []
        rejected = try container.decodeIfPresent([ProfileSyncRejection].self, forKey: .rejected) ?? []
        applied = try container.decodeIfPresent(Int.self, forKey: .applied) ?? 0
    }
}

extension Endpoint {
    /// `?action=profile_sync&cursor=N`: read what changed on the account after `cursor`.
    static func profileSyncRead(cursor: Int) -> Endpoint {
        Endpoint(
            action: "profile_sync",
            queryItems: [(name: "cursor", value: String(max(0, cursor)))],
            requiresAuth: true,
            capturesRefusalReason: true
        )
    }

    /// `?action=profile_sync` (POST): send changes, and read in the same reply.
    ///
    /// - Throws: Only if `JSONEncoder` fails (practically unreachable).
    static func profileSyncSend(_ request: ProfileSyncRequest) throws -> Endpoint {
        let body = try JSONEncoder().encode(ProfileSyncRequestBody(request: request))
        return Endpoint(action: "profile_sync", requiresAuth: true, httpMethod: "POST", httpBody: body, capturesRefusalReason: true)
    }
}

/// The exact JSON a send carries. `platform` is always `"apple"`.
private struct ProfileSyncRequestBody: Encodable {
    struct Client: Encodable {
        let platform: String
        let appVersion: String?
    }

    let schemaVersion: Int
    let client: Client
    let cursor: Int
    let changes: [ProfileSyncChange]

    init(request: ProfileSyncRequest) {
        schemaVersion = request.schemaVersion
        client = Client(platform: "apple", appVersion: request.appVersion)
        cursor = request.cursor
        changes = request.changes
    }
}

extension APIClient {
    /// Reads what changed on the account after `cursor`.
    ///
    /// ELI5: "What changed on my account since I last looked?"
    ///
    /// - Throws: `.server(status: 409, message: "profile_sync_disabled" | "profile_sync_not_ready")`
    ///   when this site does not use it (the caller does nothing), and the usual transport cases.
    public func profileSyncRead(cursor: Int) async throws -> ProfileSyncReply {
        let data = try await performIdempotentGET(.profileSyncRead(cursor: cursor))
        return try Self.decodeProfileSyncReply(from: data)
    }

    /// Sends `request`'s changes and reads in the same reply. A POST, so never retried automatically.
    ///
    /// ELI5: "Here is what I changed. Tell me what changed elsewhere."
    public func profileSyncSend(_ request: ProfileSyncRequest) async throws -> ProfileSyncReply {
        let data = try await performOnce(try Endpoint.profileSyncSend(request))
        return try Self.decodeProfileSyncReply(from: data)
    }

    /// Decodes a `profile_sync` answer.
    nonisolated public static func decodeProfileSyncReply(from data: Data) throws -> ProfileSyncReply {
        do {
            return try JSONDecoder().decode(ProfileSyncReply.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}
