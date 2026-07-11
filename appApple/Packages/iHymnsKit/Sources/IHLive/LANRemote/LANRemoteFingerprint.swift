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
import CryptoKit
import Foundation

enum LANRemoteFingerprint {
    /// SHA-256 of `data`, as 64 lowercase hex characters.
    static func sha256Hex(_ data: Data) -> String {
        let digest = SHA256.hash(data: data)
        return digest.map { String(format: "%02x", $0) }.joined()
    }
}
