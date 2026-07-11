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
import CryptoKit
import Foundation

enum LANRemoteFingerprint {
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
}
