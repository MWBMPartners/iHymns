// ProfileSchema.swift
// IHModels
//
// ELI5: The server keeps ONE list of the small personal settings that follow a
// person between their devices (theme, colour-vision mode, text size, ...) and
// what values each may hold. A copy of that list ships inside the app, so the
// app knows the rules even on its very first launch with no network. This file
// reads that copy and answers "is this value allowed for this setting?".
//
// DETAILED: PR 3b step I4 (#2180). The bundled file is
// `Resources/profile-schema.json`, a snapshot of `includes/profile_registry.php`
// written by `tools/dump-profile-schema.php`; `tests/test-profile-registry-lockstep.js`
// keeps it byte-equal to what that tool prints. Only the fields the app acts on
// are decoded (`Decodable` ignores the rest, so the server can add fields
// without breaking this). The validation mirrors the server's
// `profileValidateSpec()` for the five scalar kinds (bool, int, float, enum,
// string) and refuses every other kind (list, map, json), because the app
// maps scalar settings only: a value this build cannot judge is never
// accepted from the account and never sent. The server stays the real judge
// (it re-checks every value), so a difference here can only make the app
// stricter, never let a bad value in.
import Foundation

/// One JSON scalar a setting can hold.
///
/// ELI5: "true", "dark", "1.5", or "3": the few shapes a simple setting takes.
public enum ProfileValue: Sendable, Equatable, Codable {
    case bool(Bool)
    case int(Int)
    case double(Double)
    case string(String)

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let value = try? container.decode(Bool.self) { self = .bool(value); return }
        if let value = try? container.decode(Int.self) { self = .int(value); return }
        if let value = try? container.decode(Double.self) { self = .double(value); return }
        if let value = try? container.decode(String.self) { self = .string(value); return }
        throw DecodingError.dataCorrupted(.init(codingPath: decoder.codingPath, debugDescription: "Not a scalar profile value"))
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .bool(let value): try container.encode(value)
        case .int(let value): try container.encode(value)
        case .double(let value): try container.encode(value)
        case .string(let value): try container.encode(value)
        }
    }

    /// The number, for `.int` and `.double` (so `1` and `1.0` compare equal).
    public var number: Double? {
        switch self {
        case .int(let value): Double(value)
        case .double(let value): value
        default: nil
        }
    }

    /// Two values are equal when they hold the same thing: a whole number
    /// written `1` and the same number written `1.0` are one value (JSON does
    /// not tell them apart, and the server stores the number either way).
    public static func == (lhs: ProfileValue, rhs: ProfileValue) -> Bool {
        switch (lhs, rhs) {
        case (.bool(let a), .bool(let b)): return a == b
        case (.string(let a), .string(let b)): return a == b
        default:
            if let a = lhs.number, let b = rhs.number { return a == b }
            return false
        }
    }
}

/// One setting in the list: where it lives and what it may hold.
///
/// ELI5: "reading.textScale: a number from 0.5 to 5, for Apple devices."
public struct ProfileEntry: Sendable, Equatable, Decodable {
    /// `section.key`, for example `appearance.theme`.
    public let id: String
    public let section: String
    public let key: String
    /// `bool`, `int`, `float`, `enum`, `string`, `json`, `list` or `map`.
    public let type: String
    /// `account` (follows the person) or `device` (never leaves the device).
    public let scope: String
    /// Which apps use it: `web`, `apple`, `android` or `all`.
    public let platforms: [String]
    /// How it travels: `profile_sync`, `content_sync` or `none`.
    public let sync: String
    /// Whether a value held on a device before the first sync may be claimed by the account.
    public let claim: Bool?
    public let allowed: [String]?
    public let min: Double?
    public let max: Double?
    public let step: Double?
    public let from: Double?
    public let maxLength: Int?
    public let pattern: String?

    /// Is this setting used by the Apple app (named `apple`, or `all`)?
    public var isForApple: Bool { platforms.contains("apple") || platforms.contains("all") }

    /// May a value this device already holds be claimed by the account on the first sync?
    public var isClaimable: Bool { claim == true }

    /// Is `value` allowed for this setting? Mirrors the server's rules for the
    /// scalar kinds; every other kind is refused (see this file's header).
    public func validate(_ value: ProfileValue) -> Bool {
        switch type {
        case "bool":
            if case .bool = value { return true }
            return false
        case "int":
            guard case .int(let v) = value, let lo = min, let hi = max else { return false }
            let stepValue = Int(step ?? 1)
            let origin = Int(from ?? lo)
            return Double(v) >= lo && Double(v) <= hi && stepValue >= 1 && (v - origin) % stepValue == 0
        case "float":
            guard let v = value.number, v.isFinite, let lo = min, let hi = max else { return false }
            return v >= lo && v <= hi
        case "enum":
            guard case .string(let v) = value else { return false }
            return allowed?.contains(v) == true
        case "string":
            guard case .string(let v) = value, !v.isEmpty else { return false }
            if let maxLength, v.count > maxLength { return false }
            if let pattern {
                guard let range = v.range(of: pattern, options: .regularExpression) else { return false }
                return range == v.startIndex..<v.endIndex
            }
            return true
        default:
            return false
        }
    }
}

/// The whole list.
///
/// ELI5: Every shared setting and its rules, as one object.
public struct ProfileSchema: Sendable, Decodable {
    public let schemaVersion: Int
    public let entries: [ProfileEntry]

    public init(schemaVersion: Int, entries: [ProfileEntry]) {
        self.schemaVersion = schemaVersion
        self.entries = entries
    }

    /// The entry with this `section.key` id, or `nil`.
    public func entry(id: String) -> ProfileEntry? {
        entries.first { $0.id == id }
    }

    /// Reads the schema from JSON bytes.
    public static func decode(from data: Data) throws -> ProfileSchema {
        try JSONDecoder().decode(ProfileSchema.self, from: data)
    }

    /// The copy bundled inside the app (`Resources/profile-schema.json`).
    ///
    /// - Throws: if the resource is missing or unreadable (a packaging mistake
    ///   `ProfileSchemaTests` would catch).
    public static func bundled() throws -> ProfileSchema {
        let url = Bundle.module.url(forResource: "profile-schema", withExtension: "json", subdirectory: "Resources")
            ?? Bundle.module.url(forResource: "profile-schema", withExtension: "json")
        guard let url else { throw CocoaError(.fileNoSuchFile) }
        return try decode(from: Data(contentsOf: url))
    }
}
