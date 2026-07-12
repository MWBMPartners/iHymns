// LANRemoteManualAddress.swift
// IHLive/LANRemote
//
// ELI5: Turns whatever a person typed into the "Connect by Address" box
// (an IPv4 address, an IPv6 address, a hostname, with or without a
// ":port" on the end) into a clean "here's the host, here's the port" pair
// — or a specific, friendly reason it couldn't, BEFORE anything ever tries
// to open a network connection to it.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §3.1 — BINDING shape,
// do not improvise). A pure, static parser — no I/O, no network, no clock —
// so `LANRemoteManualAddressTests` can table-drive every row of the parse
// contract with plain string literals. This is UX-grade validation (reject
// garbage before wasting a connect attempt), **not a security boundary**:
// the parsed `host` string only ever becomes an `NWEndpoint.Host` inside
// `IHLive` (`RemoteControlSession+ManualConnect.swift`'s
// `attachByAddress`), never a shell command, a SQL fragment, or a URL —
// there is no injection surface here to defend, only "did the user type
// something we can meaningfully dial."
//
// **Precedence rule (spec §3.1, easy to get backwards): a `:port` suffix on
// the Address field WINS over whatever the separate Port field shows.**
// The Port field is always pre-filled with the default (`ManualConnectSheet`,
// spec §6.2), so if a user pastes `"192.168.1.50:8080"` into the Address
// field, that colon-suffixed port is the STRONGER signal of intent than the
// still-showing default in the other field — reading the suffix first (and
// only falling back to `portInput` when there is no suffix at all) is what
// makes that work.
import Foundation

/// A pure parser turning free-typed address/port text into a dialable
/// `(host, port)` pair — see this file's header for the full contract.
///
/// ELI5: "Given what the user typed, what host and port should we actually
/// try to connect to — or why can't we?"
public enum LANRemoteManualAddress {
    /// A successfully parsed address, ready to become an `NWEndpoint`.
    public struct Parsed: Sendable, Equatable {
        /// Brackets stripped for a bracketed IPv6 literal; a `%zone` suffix
        /// (e.g. `fe80::1%en0`) is preserved verbatim — `NWEndpoint.Host`
        /// accepts it as-is.
        public let host: String
        public let port: UInt16

        public init(host: String, port: UInt16) {
            self.host = host
            self.port = port
        }
    }

    /// Why `parse(hostInput:portInput:)` could not produce a `Parsed`
    /// value — each case maps to one inline, plain-English footer message
    /// in `ManualConnectSheet` (spec §6.2).
    public enum ParseError: Error, Sendable, Equatable {
        /// Nothing was typed (after trimming whitespace/newlines).
        case empty
        /// Contains `"://"` anywhere — the user pasted a URL, not a bare
        /// address (e.g. `"http://192.168.1.50"`).
        case unsupportedScheme
        /// The host portion fails the allow-listed charset, or exceeds the
        /// 253-character DNS-name length ceiling.
        case invalidHost
        /// A port (from a host-field suffix or the separate field) was
        /// present but didn't parse as an integer in `1...65535` — `0` is
        /// explicitly invalid (mirrors `RemotePairingEntryResolver`'s own
        /// "a payload port of 0 is malformed" clamp).
        case invalidPort
    }

    /// Every character a host portion may contain — letters, digits, and
    /// the punctuation a hostname, IPv4 literal, or IPv6 literal (including
    /// a `%zone` suffix) can legitimately use. Deliberately permissive
    /// (this is UX validation, not a security boundary — this file's
    /// header) rather than a strict per-address-family grammar.
    private static let allowedHostCharacters = CharacterSet(
        charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_:%"
    )

    /// The longest a DNS hostname can legitimately be (RFC 1035 §3.1).
    private static let maximumHostLength = 253

    /// Parses the "Connect by Address" sheet's two text fields into a
    /// dialable host/port pair.
    ///
    /// - Parameters:
    ///   - hostInput: The Address field — may itself carry a `:port`
    ///     suffix (e.g. `"192.168.1.50:8080"`, `"[fe80::1%en0]:7269"`).
    ///   - portInput: The Port field — used only when `hostInput` carries
    ///     no suffix port; `nil`/empty/whitespace-only falls back to
    ///     `LANRemoteDiscovery.defaultPort`.
    ///
    /// ELI5: "Here's what the two boxes say — work out where to actually
    /// connect, or tell me why you can't."
    public static func parse(hostInput: String, portInput: String?) -> Result<Parsed, ParseError> {
        let trimmedHost = hostInput.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmedHost.isEmpty else { return .failure(.empty) }
        guard !trimmedHost.contains("://") else { return .failure(.unsupportedScheme) }

        let (host, suffixPort) = splitHostAndSuffixPort(trimmedHost)
        let trimmedPortInput = portInput?.trimmingCharacters(in: .whitespacesAndNewlines)

        let resolvedPort: UInt16
        if let suffixPort, !suffixPort.isEmpty {
            // The host-field suffix WINS over the separate field — this
            // file's header.
            guard let value = validPort(suffixPort) else { return .failure(.invalidPort) }
            resolvedPort = value
        } else if let trimmedPortInput, !trimmedPortInput.isEmpty {
            guard let value = validPort(trimmedPortInput) else { return .failure(.invalidPort) }
            resolvedPort = value
        } else {
            resolvedPort = LANRemoteDiscovery.defaultPort
        }

        // Port validity is checked BEFORE host validity so a degenerate
        // input like `":0"` (an empty host with an explicitly-zero suffix
        // port) is reported as the more specific `.invalidPort`, not
        // `.invalidHost` — the port is the field the user is more likely
        // to have fat-fingered in that shape of input.
        guard isValidHost(host) else { return .failure(.invalidHost) }
        return .success(Parsed(host: host, port: resolvedPort))
    }

    /// Splits `input` into `(host, suffixPort)` — the bracketed-IPv6,
    /// bare-IPv6, and "exactly one colon" cases from this file's header.
    private static func splitHostAndSuffixPort(_ input: String) -> (host: String, suffixPort: String?) {
        if input.hasPrefix("["), let closeBracketIndex = input.firstIndex(of: "]") {
            // Bracketed IPv6, optionally with a `:port` suffix after the
            // closing bracket — `"[fe80::1%en0]:7269"` / `"[::1]"`.
            let bracketedHost = String(input[input.index(after: input.startIndex)..<closeBracketIndex])
            let remainder = input[input.index(after: closeBracketIndex)...]
            if remainder.hasPrefix(":") {
                return (bracketedHost, String(remainder.dropFirst()))
            }
            return (bracketedHost, nil)
        }

        let colonCount = input.reduce(into: 0) { count, character in
            if character == ":" { count += 1 }
        }
        if colonCount >= 2 {
            // A bare (unbracketed) IPv6 literal — splitting on the LAST
            // colon would be ambiguous (is it a port suffix, or part of
            // the address?), so the whole string is the host and no port
            // suffix is ever parsed out of it (spec §3.1: "NO suffix
            // parsing (ambiguous)").
            return (input, nil)
        }

        if let colonIndex = input.firstIndex(of: ":") {
            // IPv4 literal or hostname with exactly one colon — split into
            // host + suffix port.
            let host = String(input[input.startIndex..<colonIndex])
            let suffix = String(input[input.index(after: colonIndex)...])
            return (host, suffix)
        }

        return (input, nil)
    }

    private static func isValidHost(_ host: String) -> Bool {
        guard !host.isEmpty, host.count <= maximumHostLength else { return false }
        return host.unicodeScalars.allSatisfy(allowedHostCharacters.contains)
    }

    /// `nil` unless `text` is a base-10 integer in `1...65535` — `0` is
    /// explicitly invalid (a real listener never binds port 0).
    private static func validPort(_ text: String) -> UInt16? {
        guard let value = UInt16(text), value >= 1 else { return nil }
        return value
    }
}
