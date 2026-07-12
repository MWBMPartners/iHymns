// LANRemoteFingerprint.swift
// IHLive/LANRemote
//
// ELI5: The ONE "turn these bytes into a short hex fingerprint" helper
// every other file in this module that needs a SHA-256 hex digest calls,
// instead of each writing its own copy of the same three lines.
//
// DETAILED: #1420. `.claude/CLAUDE.md`'s "extract first, use second" rule
// applied within this new subsystem: `LANRemoteIdentityFactory` (a
// certificate's fingerprint), `RemoteSessionActor+Connection.swift` (a
// PRESENTED certificate's fingerprint, for pin comparison), and
// `LANRemotePairingAuthority`'s in-memory reference implementation (a
// pairing token's hash-at-rest) all need "SHA-256, then lowercase hex" —
// this is that one function, not three near-identical private copies.
//
// #1421 UPDATE (PR-6 spec §3.1) — `hexString(_:)` was pulled OUT of
// `sha256Hex(_:)`'s body so `LANRemotePairingSecrets`/`LANRemotePairingProof`
// (`LANRemotePairingProof.swift`) can reuse the identical lowercase-hex
// encoding for a random nonce/token and an HMAC output, which are NOT
// SHA-256 digests themselves and so can't route through `sha256Hex(_:)`
// directly — "add one tiny internal `hexString(_ bytes:)` helper next to
// it rather than three private copies" (spec §3.1), applying this file's
// own "extract first" rule to itself.
//
// #1424 UPDATE (PR-8 spec §2/D-13 — "the ONE fingerprint-grouping helper")
// — the enum itself became `public` (it was purely `internal` before, since
// every prior consumer lived inside `IHLive`) so `displayGrouped(_:every:)`
// below can be called from `IHFeatures`' `FingerprintConfirmView` (the TOFU
// interstitial, spec §6.3) — every OTHER member here (`sha256Hex`/
// `hexString`) stays at its existing default `internal` access, unreachable
// from outside this module, exactly as before.
import CryptoKit
import Foundation

public enum LANRemoteFingerprint {
    /// SHA-256 of `data`, as 64 lowercase hex characters.
    static func sha256Hex(_ data: Data) -> String {
        hexString(Array(SHA256.hash(data: data)))
    }

    /// Lowercase hex encoding of raw `bytes` — the ONE place every
    /// SHA-256/HMAC/random-byte hex-encoding in this module routes through
    /// (this file's #1421 header update).
    static func hexString(_ bytes: [UInt8]) -> String {
        bytes.map { String(format: "%02x", $0) }.joined()
    }

    /// Groups a fingerprint hex string into `every`-character chunks
    /// separated by spaces, for side-by-side human comparison against the
    /// value shown on the TV's own Settings screen (#1424 — PR-6's
    /// `TVSettingsRemoteView`/`TVPairingOverlayView` already grouped a
    /// fingerprint this same way via their own private
    /// `PairingFingerprintDisplay.grouped(_:prefixLength:)`; this is now
    /// the ONE canonical grouping helper — `TVSettingsRemoteView`'s
    /// whole-string row was mechanically switched to call THIS function
    /// instead, per this repo's "extract first, use second" modularity
    /// rule. `TVPairingOverlayView`'s own 12-character-PREFIX short line is
    /// a genuinely different shape (truncate-then-group) this function
    /// deliberately doesn't support — that one line keeps its own
    /// `PairingFingerprintDisplay` helper rather than this PR inventing an
    /// unspecified `prefixLength` parameter here.
    ///
    /// ELI5: "Break this long fingerprint into short, easy-to-compare
    /// chunks — like how a credit card number is grouped into 4s."
    public static func displayGrouped(_ hex: String, every: Int = 4) -> String {
        guard every > 0 else { return hex }
        var groups: [String] = []
        var startIndex = hex.startIndex
        while startIndex < hex.endIndex {
            let endIndex = hex.index(startIndex, offsetBy: every, limitedBy: hex.endIndex) ?? hex.endIndex
            groups.append(String(hex[startIndex..<endIndex]))
            startIndex = endIndex
        }
        return groups.joined(separator: " ")
    }
}
