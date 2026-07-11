// IHLogSanitize.swift
// IHLog
//
// ELI5: Turns a secret login pass into a short, safe "fingerprint" — like a
// partial fingerprint at a crime scene, it's enough to say "yep, same
// person as before" without ever revealing enough to steal their identity.
//
// DETAILED: #1505 (`.claude/live-observability-strategy.md` §4). The
// strategy's binding privacy rule is that a bearer/presence/follow token is
// NEVER interpolated into a log line, at ANY privacy level — but a
// developer diagnosing "why did this specific session fail three times"
// still benefits from being able to correlate a client-side `.error` line
// with the matching server-side `tblActivityLog`/`tblApiKeyUsage` row
// WITHOUT the raw secret ever appearing in either place. `tokenFingerprint`
// is that correlation key: a short, one-way, deterministic digest safe to
// log at `.public` (strategy §4: "Token fingerprints ... are `.public`").
import CryptoKit
import Foundation

/// Sanitization helpers for anything that must NEVER appear verbatim in a
/// log line.
///
/// ELI5: "I need to write SOMETHING down about this secret, without writing
/// down the secret itself."
public enum IHLogSanitize {

    /// An 8-character lowercase-hex prefix of the SHA-256 digest of `token`
    /// — safe to log at `.public` privacy as a correlation key.
    ///
    /// ELI5: Turns a 64-character login pass into an 8-character stand-in
    /// that's the same every time for the SAME pass, but tells you nothing
    /// about what the real pass is.
    ///
    /// DETAILED — **NEVER use this for a join/session/pairing CODE.** This
    /// function is only safe for a HIGH-ENTROPY credential (≥128 bits, e.g.
    /// iHymns' 64-hex-character `tblApiTokens`/presence/follow tokens,
    /// which are ~256 bits of randomness): even truncated to a 32-bit
    /// (8 hex char) prefix, brute-forcing which of 2^256 possible SHA-256
    /// preimages produced a given 8-char prefix is still computationally
    /// infeasible — the prefix narrows an attacker's search space by only
    /// 32 bits, leaving the other ~224+ bits of the real token exactly as
    /// hard to guess as before. A join/session/rotating CODE (strategy §0.2/
    /// §4: iHymns' Service-Mode/Live-Follow codes are Crockford-base32,
    /// ~29–39 bits) has NO such margin: a 29-bit code space is entirely
    /// brute-forceable OFFLINE against a leaked digest in seconds on
    /// ordinary hardware, so even a "safe-looking" fingerprint of a code is
    /// really just the code, one extra (trivial) step removed. Codes must
    /// be correlated some other way (e.g. the session's numeric id +
    /// rotation `Generation`, per strategy §3.1/§4) — never through this
    /// function.
    ///
    /// Deterministic (same input → same output, always) and pure — no
    /// device/session state is mixed in, so two client processes (or a
    /// client and the server, if the server ever adopts the identical
    /// algorithm) computing this over the same token agree without needing
    /// to exchange anything else. Uses SHA-256 via CryptoKit
    /// (https://developer.apple.com/documentation/cryptokit/sha256), a
    /// first-party Apple system framework — not an external SwiftPM
    /// dependency — keeping this target's `Package.swift` `dependencies:`
    /// list empty, matching its "zero-dependency leaf" design (§2.1).
    ///
    /// - Parameter token: The full, high-entropy secret value. Never logged
    ///   itself — only this function's return value may be.
    /// - Returns: 8 lowercase hex characters, e.g. `"2cf24dba"`.
    public static func tokenFingerprint(_ token: String) -> String {
        let digest = SHA256.hash(data: Data(token.utf8))
        let hex = digest.map { String(format: "%02x", $0) }.joined()
        return String(hex.prefix(8))
    }
}
