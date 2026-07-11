// LANRemotePairingPayload.swift
// IHLive/LANRemote
//
// ELI5: What's actually hiding inside the QR code the TV shows — which
// version of the format this is, the TV's name, maybe how to reach it on
// the network, its fingerprint, and (only on the pairing-ceremony QR, never
// the Settings "standing" one) the 6-digit code — all packed into one
// short string a camera can scan.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.3). This is a
// SHARED wire shape, not TV-only: `TVRemoteControlCoordinator`
// (`IHFeatures`) encodes it for the tvOS pairing overlay/Settings screen
// this PR builds, and PR-7's phone-side QR SCANNER decodes the identical
// string — the modularity rule applied to a data shape, not just a
// function. `code == nil` marks the Settings "standing" QR (connect-info
// only, safe to leave on screen indefinitely); `code != nil` marks the
// ceremony QR (out-of-band cert pinning + the live pairing code in one
// scan) — `TVSettingsRemoteView`/`TVPairingOverlayView` (`IHFeatures`) are
// what choose which one to render.
//
// Encoded as `"ihymns-lanpair:v1:" + base64url(JSON)` — a custom
// base64url (RFC 4648 §5, https://www.rfc-editor.org/rfc/rfc4648#section-5)
// implementation lives in THIS file rather than reusing `Data
// .base64EncodedString()` directly, because standard base64's `+`/`/`/`=`
// characters are all either reserved or awkward inside a URI-like string a
// QR-code scanner or a pasted manual-entry field might see — base64url
// swaps `+`→`-`, `/`→`_`, and drops the `=` padding (recoverable on decode
// from the string's own length), the same reasoning RFC 4648 gives for
// defining it as a distinct alphabet from standard base64.
import Foundation

/// The decoded contents of an iHymns LAN-pairing QR code.
///
/// ELI5: "Here's the TV you'd be connecting to, and (maybe) the code to
/// prove you're standing in front of it."
public struct LANRemotePairingPayload: Sendable, Equatable, Codable {
    /// The payload format version — `1` today. A future incompatible
    /// change bumps this (and the `"ihymns-lanpair:v1:"` string prefix
    /// alongside it), never silently changes this shape's meaning in place.
    /// Wire key `"v"` (`CodingKeys` below) — spec §6.3's exact short field
    /// name (a QR code's whole point is a small, dense payload); the SWIFT
    /// property is spelled out in full because SwiftLint's `identifier_name`
    /// rule (`appApple/.swiftlint.yml`) rejects single/two-letter Swift
    /// identifiers — the wire shape and the Swift API are free to differ.
    public let version: Int
    /// The TV's advertised/device name — cosmetic, shown to the person
    /// scanning ("Pairing with iHymns TV — Fellowship Hall").
    public let name: String
    /// Best-effort LAN address (`LANRemoteAddress.primaryIPv4Address()`) —
    /// `nil` if undeterminable; PR-8's manual "connect by address" path is
    /// the intended future reader of this field (Bonjour discovery doesn't
    /// need it).
    public let host: String?
    /// The bound listener port — `nil` only in the unlikely case the TV
    /// couldn't determine it (mirrors `host`'s optionality for the same
    /// reason).
    public let port: UInt16?
    /// The TV's TLS identity fingerprint — the value a scanning remote
    /// pins as `RemoteSessionActor.connect(to:expectedFingerprint:token:)`'s
    /// `expectedFingerprint` (PR-4, already merged). Wire key `"fp"` — see
    /// `version`'s doc comment above for why the Swift name is longer.
    public let fingerprintHex: String
    /// The live 6-digit pairing code — present ONLY on the ceremony QR
    /// (`TVPairingOverlayView`'s `includeCode: true`); absent on the
    /// Settings standing QR (`includeCode: false`), which carries no
    /// secret at all and is safe to leave displayed indefinitely.
    public let code: String?

    /// `CodingKeys` maps the readable Swift property names above onto
    /// spec §6.3's exact short wire keys (`v`/`fp`) — every other field's
    /// wire key already matches its Swift name 1:1.
    private enum CodingKeys: String, CodingKey {
        case version = "v"
        case name, host, port
        case fingerprintHex = "fp"
        case code
    }

    public init(
        version: Int = 1, name: String, host: String? = nil, port: UInt16? = nil, fingerprintHex: String, code: String? = nil
    ) {
        self.version = version
        self.name = name
        self.host = host
        self.port = port
        self.fingerprintHex = fingerprintHex
        self.code = code
    }

    /// The fixed string prefix every encoded payload starts with — a
    /// custom URI-like scheme (not a REAL registered URL scheme; this is
    /// never opened by the system, only ever scanned/pasted and decoded by
    /// THIS type) versioned independently of `v` above so a scanner can
    /// reject a wildly wrong format (a barcode from some OTHER app) before
    /// even attempting base64/JSON decoding.
    private static let qrPrefix = "ihymns-lanpair:v1:"

    /// Encodes this payload as the exact string a QR code renders/a
    /// scanner decodes — `nil` only if `JSONEncoder` itself somehow fails
    /// (unreachable for this closed, simple-value shape in practice, but
    /// surfaced rather than force-unwrapped per this codebase's own
    /// `RemoteSessionError.encodingFailed` precedent).
    public func encodeToQRString() -> String? {
        guard let json = try? JSONEncoder().encode(self) else { return nil }
        return Self.qrPrefix + Self.base64URLEncode(json)
    }

    /// Decodes a scanned/pasted string back into a payload — `nil` for
    /// anything that isn't a well-formed `ihymns-lanpair:v1:`-prefixed,
    /// base64url-encoded JSON payload of this exact shape (a garbage scan
    /// is simply "not a valid pairing code," never a crash).
    public init?(qrString: String) {
        guard qrString.hasPrefix(Self.qrPrefix) else { return nil }
        let encoded = String(qrString.dropFirst(Self.qrPrefix.count))
        guard let data = Self.base64URLDecode(encoded),
              let decoded = try? JSONDecoder().decode(LANRemotePairingPayload.self, from: data) else {
            return nil
        }
        self = decoded
    }

    // MARK: - base64url (RFC 4648 §5) — see this file's header for why a
    // dedicated codec lives here rather than reusing `Data
    // .base64EncodedString()`'s standard alphabet directly.

    private static func base64URLEncode(_ data: Data) -> String {
        data.base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }

    private static func base64URLDecode(_ string: String) -> Data? {
        var base64 = string
            .replacingOccurrences(of: "-", with: "+")
            .replacingOccurrences(of: "_", with: "/")
        let remainder = base64.count % 4
        if remainder > 0 {
            base64.append(String(repeating: "=", count: 4 - remainder))
        }
        return Data(base64Encoded: base64)
    }
}
