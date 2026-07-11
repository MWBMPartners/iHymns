// LANRemoteIdentityDER.swift
// IHLive/LANRemote
//
// ELI5: The tiny, boring "how do you write a number/a string/a list down in
// the exact byte format an X.509 certificate needs" helpers — kept
// completely separate from anything Keychain/TLS so they're trivial to
// read and (indirectly) verify by inspecting the certificate they produce.
//
// DETAILED: #1420. There is no first-party Apple API for "just generate me
// a self-signed certificate" — `Security.framework` only offers `SecKey`
// generation (`SecKeyCreateRandomKey`) and `SecKeyCreateSignature`; turning
// a raw EC public key into an actual X.509 DER certificate is on the
// caller. Rather than pull in an external SwiftPM dependency (swift-
// certificates or similar) for what is, structurally, a MINIMAL v1
// certificate with a handful of fixed fields, this file hand-encodes the
// small ASN.1 DER subset required — a well-understood, mechanical format
// (see ITU-T X.690 https://www.itu.int/rec/T-REC-X.690 for the general
// DER rules, and RFC 5280 https://www.rfc-editor.org/rfc/rfc5280 for the
// X.509 certificate structure this builds a deliberately minimal instance
// of).
//
// **This certificate is INTENTIONALLY v1 (no extensions, no CA/basic-
// constraints, no key-usage bits).** That is safe HERE, and only here,
// because of `.claude/apple-native-strategy.md` §2.4.3's trust model:
// nothing on either end of an IHRP connection ever asks the OS to validate
// this certificate against the system trust store (that would fail outright
// for a self-signed leaf anyway) — `RemoteSessionActor` instead does
// **pin-before-trust**: it compares the presented certificate's SHA-256
// fingerprint against the one shown out-of-band (QR/manual code, PR-6)
// and REJECTS on any mismatch, in its own `sec_protocol_options_set_verify_block`.
// The certificate's only two jobs are (1) carry a real P-256 public key
// TLS can use for the handshake, and (2) have a stable DER encoding whose
// SHA-256 hash is the fingerprint being pinned — X.509 v3 extensions add
// nothing to either job for a protocol that never walks a certificate
// chain.
import Foundation

/// A tiny, purpose-built ASN.1 DER encoder — just the handful of primitives
/// `buildSelfSignedCertificateDER` needs, not a general-purpose ASN.1
/// library.
///
/// ELI5: Helper functions for "how do you write THIS particular kind of
/// value down in DER bytes."
enum LANRemoteDER {
    /// Encodes a DER length octet(s) for a value of `count` bytes — the
    /// short form for `count < 0x80`, the long form (a length-of-the-length
    /// byte followed by the big-endian length) otherwise. See X.690 §8.1.3.
    static func length(_ count: Int) -> [UInt8] {
        if count < 0x80 { return [UInt8(count)] }
        var bytes: [UInt8] = []
        var value = count
        while value > 0 {
            bytes.insert(UInt8(value & 0xff), at: 0)
            value >>= 8
        }
        return [UInt8(0x80 | bytes.count)] + bytes
    }

    /// Wraps `value` with a DER tag+length header — the one shape every
    /// other helper below builds on.
    static func tlv(_ tag: UInt8, _ value: [UInt8]) -> [UInt8] {
        [tag] + length(value.count) + value
    }

    /// `SEQUENCE` (tag `0x30`) of already-encoded children, concatenated.
    static func sequence(_ children: [[UInt8]]) -> [UInt8] {
        tlv(0x30, children.flatMap { $0 })
    }

    /// `OBJECT IDENTIFIER` (tag `0x06`) from its already-encoded body bytes
    /// (this file only ever needs the three FIXED OIDs declared below, so
    /// there's no general "encode an arbitrary dotted OID string" helper —
    /// that would be dead code no test could meaningfully exercise).
    static func objectIdentifier(_ bodyBytes: [UInt8]) -> [UInt8] {
        tlv(0x06, bodyBytes)
    }

    /// `UTF8String` (tag `0x0C`) — used for the certificate's Common Name.
    static func utf8String(_ string: String) -> [UInt8] {
        tlv(0x0C, Array(string.utf8))
    }

    /// `INTEGER` (tag `0x02`) from an unsigned big-endian byte sequence,
    /// with a leading `0x00` inserted if the high bit of the first byte is
    /// set (DER integers are signed two's-complement; a genuinely-positive
    /// value whose top bit happens to be 1 would otherwise be misread as
    /// negative — X.690 §8.3.2).
    static func integer(_ bytes: [UInt8]) -> [UInt8] {
        var body = bytes
        if body.isEmpty { body = [0x00] }
        if let first = body.first, first & 0x80 != 0 {
            body.insert(0x00, at: 0)
        }
        return tlv(0x02, body)
    }

    /// `BIT STRING` (tag `0x03`) with zero unused trailing bits — every use
    /// in this file (the EC public-key point, the DER-encoded ECDSA
    /// signature) is already a whole number of bytes.
    static func bitString(_ bytes: [UInt8]) -> [UInt8] {
        tlv(0x03, [0x00] + bytes)
    }

    /// A `RelativeDistinguishedName`/`Name` (RFC 5280 §4.1.2.4) containing
    /// exactly one `commonName` (OID `2.5.4.3`) attribute — the only kind of
    /// Issuer/Subject this self-signed certificate ever needs, since Issuer
    /// == Subject (self-signed) and nothing here is ever validated against
    /// a certificate chain (this file's header comment).
    static func commonNameOnly(_ commonName: String) -> [UInt8] {
        let attribute = sequence([objectIdentifier(oidCommonName), utf8String(commonName)])
        let relativeDistinguishedName = tlv(0x31, attribute) // SET OF
        return sequence([relativeDistinguishedName]) // RDNSequence
    }

    /// `UTCTime` (tag `0x17`, RFC 5280 §4.1.2.5.1 form, valid 1950–2049 —
    /// comfortably covers this short-lived certificate's real validity
    /// window) for a `Date`.
    static func utcTime(_ date: Date) -> [UInt8] {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyMMddHHmmss'Z'"
        formatter.timeZone = TimeZone(identifier: "UTC")
        formatter.locale = Locale(identifier: "en_US_POSIX")
        return tlv(0x17, Array(formatter.string(from: date).utf8))
    }

    // MARK: Fixed OIDs (all three are well-known, unchanging byte
    // sequences — see RFC 5758 §3.2 for `ecdsa-with-SHA256`, SEC1/RFC 5480
    // for `id-ecPublicKey`/`prime256v1`, and RFC 5280 Appendix A for
    // `commonName`).

    /// `1.2.840.10045.4.3.2` — ecdsa-with-SHA256.
    static let oidEcdsaWithSHA256: [UInt8] = [0x2A, 0x86, 0x48, 0xCE, 0x3D, 0x04, 0x03, 0x02]
    /// `1.2.840.10045.2.1` — id-ecPublicKey.
    static let oidIdEcPublicKey: [UInt8] = [0x2A, 0x86, 0x48, 0xCE, 0x3D, 0x02, 0x01]
    /// `1.2.840.10045.3.1.7` — prime256v1 (secp256r1, i.e. P-256).
    static let oidPrime256v1: [UInt8] = [0x2A, 0x86, 0x48, 0xCE, 0x3D, 0x03, 0x01, 0x07]
    /// `2.5.4.3` — commonName.
    static let oidCommonName: [UInt8] = [0x55, 0x04, 0x03]
}
