// LANRemotePairingProof.swift
// IHLive/LANRemote
//
// ELI5: The ONE piece of math that answers "does the person standing at
// this remote actually see the code on the TV's screen?" — turn the code
// into a secret key, HMAC it over "which TV, which connection," and check
// the remote's answer matches. Also mints the three kinds of random
// secrets the pairing ceremony needs: the 6-digit code shown on the TV, the
// per-connection nonce the proof binds to, and the 32-byte token a paired
// remote keeps forever.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §3.1/§3.2 — BINDING,
// "do not improvise"). This file is the SECURITY BOUNDARY of the whole LAN
// remote: `TVListenerActor+Pairing.swift`'s `handlePairConfirm` and PR-7's
// (not-yet-built) remote-side pairing UI BOTH call `compute(...)`/
// `verify(...)` below — ONE implementation, two callers, per this repo's
// modularity rule (`.claude/CLAUDE.md`).
//
// **The construction (frozen by `LANRemotePairingProofTests`' golden
// vector — changing ANY of the three pieces below breaks every
// already-paired remote in the field; bump the pairing sub-protocol's own
// version marker instead of touching this):**
//   key   = HKDF<SHA256>.deriveKey(inputKeyMaterial: SymmetricKey(data:
//           Data(code.utf8)), salt: "app.ihymns.lanremote.pair.v1", info:
//           "proof", outputByteCount: 32)
//   msg   = "<fingerprintHex-lowercased>.<nonceHex-lowercased>"
//   proof = lowercase hex of HMAC<SHA256>.authenticationCode(for: msg,
//           using: key)
//
// **What each ingredient buys (spec §3.2's threat-model mapping):**
//   - `code` (via HKDF, never used raw as a MAC key) proves the person at
//     the remote could READ the venue TV's screen — proximity, not secrecy
//     (~20 bits of entropy; the SECURITY comes from the ONLINE caps in
//     `LANRemotePairingCeremonyState`, never from this being uncrackable
//     offline). HKDF's `salt`/`info` are versioned constants purely for
//     domain separation, so this key derivation could never collide with
//     some unrelated future use of the same 6-digit code.
//   - `fingerprintHex` in the message IS the channel binding: the remote
//     HMACs the cert fingerprint it actually pinned for THIS TLS connection
//     (`RemoteSessionActor`'s `expectedFingerprint`); the TV verifies
//     against its OWN `identity.fingerprint`. A relay/MITM attacker must
//     terminate TLS with ITS OWN certificate (it cannot forge the TV's
//     private key), so a relayed proof binds to the WRONG fingerprint and
//     fails verification even before TLS pinning would have caught it.
//   - `nonceHex` in the message is per-connection freshness — a proof
//     captured off one connection (bug, memory dump) is worthless replayed
//     on any other connection or after a reconnect.
//   - Why not a real PAKE (SRP/SPAKE2)? CryptoKit ships none, a vendored
//     one is a new pinned dependency + audit surface (forbidden by
//     default here), and the transcript this HMAC lives inside is ALREADY
//     inside TLS 1.3 — an HMAC channel-binding over an already-encrypted,
//     fingerprint-pinned link + hard online caps reaches the same
//     practical bar for a sanctuary-projector remote. Recorded as the
//     considered-and-rejected alternative (spec §3.2, Decision D-1).
//
// See https://developer.apple.com/documentation/cryptokit/hkdf and
// https://developer.apple.com/documentation/cryptokit/hmac for the
// CryptoKit APIs this file is built on, and RFC 5869
// (https://www.rfc-editor.org/rfc/rfc5869) for HKDF itself.
import CryptoKit
import Foundation

/// Mints the three random secrets the pairing ceremony needs — never the
/// proof itself (that's `LANRemotePairingProof`, this file's other half).
///
/// ELI5: "Give me a new 6-digit code," "give me a new per-connection
/// secret number," "give me a new forever-token for a freshly paired
/// remote."
///
/// DETAILED: A caseless `public enum` (never instantiated) holding only
/// `public static` minting functions — the same "namespace, not a value"
/// shape `LANRemoteFingerprint` (this module) and `IHModels/SongID`'s
/// static factories already use. Every byte here comes from
/// `SystemRandomNumberGenerator`, Swift's own CSPRNG
/// (https://developer.apple.com/documentation/swift/systemrandomnumbergenerator) —
/// never `arc4random`/`rand` called directly, and never seeded.
public enum LANRemotePairingSecrets {
    /// A fresh 6-digit pairing code, zero-padded (`"042317"`, never
    /// `"42317"`) — spec §3.1: "~20 bits — its security is the ONLINE caps
    /// (§3.4), never entropy."
    ///
    /// - Parameter random: Injectable for deterministic tests (spec §7.1:
    ///   `mintCode(random: { _ in 7 })` ⇒ `"000007"`). Defaults to a real
    ///   CSPRNG draw — production code never supplies this parameter.
    public static func mintCode(random: () -> Int = {
        var generator = SystemRandomNumberGenerator()
        return Int.random(in: 0...999_999, using: &generator)
    }) -> String {
        String(format: "%06d", random())
    }

    /// A fresh 32-lowercase-hex-char (16-byte) per-connection nonce, minted
    /// once when a connection enters `.pairing` — spec §3.1's connection
    /// nonce. Not secret (it travels TV→remote in `.pairChallenge`), but
    /// never logged regardless (this module's blanket "never log a pairing
    /// secret" contract treats it the same as the code/token/proof).
    public static func mintNonce() -> String {
        LANRemoteFingerprint.hexString(randomBytes(count: 16))
    }

    /// A fresh 64-lowercase-hex-char (32-byte) pairing token — spec §3.1's
    /// "per-remote 32-byte token" (strategy §2.4.3). The TV persists only
    /// `sha256(token)` (`KeychainLANRemotePairingAuthority.swift`); the
    /// remote receives this raw value exactly once, inside TLS, in
    /// `.pairSuccess`.
    public static func mintToken() -> String {
        LANRemoteFingerprint.hexString(randomBytes(count: 32))
    }

    private static func randomBytes(count: Int) -> [UInt8] {
        var generator = SystemRandomNumberGenerator()
        return (0..<count).map { _ in UInt8.random(in: 0...255, using: &generator) }
    }
}

/// The ONE proof function both the TV (`TVListenerActor+Pairing.swift`) and
/// the remote (PR-7) call — see this file's header for the full crypto
/// design.
public enum LANRemotePairingProof {
    /// Computes the pairing proof a remote sends in `.pairConfirm` —
    /// deterministic (same three inputs always produce the same 64-char
    /// lowercase-hex output), which is exactly what lets the TV recompute
    /// and compare it in `verify(...)` below.
    public static func compute(code: String, fingerprintHex: String, nonceHex: String) -> String {
        let key = derivedKey(code: code)
        let message = bindingMessage(fingerprintHex: fingerprintHex, nonceHex: nonceHex)
        let authenticationCode = HMAC<SHA256>.authenticationCode(for: message, using: key)
        return LANRemoteFingerprint.hexString(Array(authenticationCode))
    }

    /// Verifies a proof the TV received in `.pairConfirm` — **constant-time
    /// by construction**: a malformed/odd-length/non-hex `proofHex` is
    /// rejected via a plain length/format check (a NON-secret-dependent
    /// early exit — rejecting based on the ATTACKER'S OWN malformed input
    /// shape leaks nothing about the real proof), and every well-formed
    /// candidate is compared via CryptoKit's own
    /// `HMAC.isValidAuthenticationCode(_:authenticating:using:)`
    /// (https://developer.apple.com/documentation/cryptokit/hmac/isvalidauthenticationcode(_:authenticating:using:)),
    /// which is documented to perform the comparison in constant time.
    /// **NEVER replace this with `==` on `String`/`Data` — that reintroduces
    /// a timing side-channel on a security-boundary comparison** (spec
    /// §3.2's explicit "NEVER a hand-rolled byte loop" instruction).
    public static func verify(proofHex: String, code: String, fingerprintHex: String, nonceHex: String) -> Bool {
        guard let proofData = hexDecodedData(proofHex) else { return false }
        let key = derivedKey(code: code)
        let message = bindingMessage(fingerprintHex: fingerprintHex, nonceHex: nonceHex)
        return HMAC<SHA256>.isValidAuthenticationCode(proofData, authenticating: message, using: key)
    }

    /// HKDF-derives a 32-byte MAC key from the 6-digit code — see this
    /// file's header for why HKDF (not the raw code bytes) is the key.
    private static func derivedKey(code: String) -> SymmetricKey {
        HKDF<SHA256>.deriveKey(
            inputKeyMaterial: SymmetricKey(data: Data(code.utf8)),
            salt: Data("app.ihymns.lanremote.pair.v1".utf8),
            info: Data("proof".utf8),
            outputByteCount: 32
        )
    }

    /// The exact byte sequence the HMAC authenticates — see this file's
    /// header for why `fingerprintHex` (channel binding) and `nonceHex`
    /// (per-connection freshness) are both folded in. Both hex strings are
    /// lowercased first so a caller that happens to pass uppercase hex
    /// (from a copy-pasted fingerprint, say) still produces the identical
    /// proof `compute`/`verify` agree on.
    private static func bindingMessage(fingerprintHex: String, nonceHex: String) -> Data {
        Data((fingerprintHex.lowercased() + "." + nonceHex.lowercased()).utf8)
    }

    /// Decodes a hex string into raw bytes — `nil` for an odd-length or
    /// non-hex-digit input, which `verify(...)` maps straight to `false`.
    /// This rejection is format-only (it never inspects the SECRET key or
    /// message), so short-circuiting here adds no exploitable timing
    /// signal about the real proof value.
    private static func hexDecodedData(_ hex: String) -> Data? {
        guard hex.count % 2 == 0 else { return nil }
        var data = Data(capacity: hex.count / 2)
        var index = hex.startIndex
        while index < hex.endIndex {
            let next = hex.index(index, offsetBy: 2)
            guard let byte = UInt8(hex[index..<next], radix: 16) else { return nil }
            data.append(byte)
            index = next
        }
        return data
    }
}
