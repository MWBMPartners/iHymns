// LANRemoteIdentity.swift
// IHLive/LANRemote
//
// ELI5: Makes the TV's "TLS driver's license" — a private key nobody else
// has, plus a matching certificate that proves it, plus a short fingerprint
// number a remote can double-check against a QR code so it KNOWS it's
// really talking to the right TV.
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4:
// "TLS 1.3 with a persistent P-256 self-signed identity (generate + expose
// its cert fingerprint; storing the identity in the Keychain is PR-6's job
// — here, accept an injected identity/fingerprint so it's testable)").
//
// This file owns the MECHANISM — "generate a real P-256 key + a real X.509
// certificate + bridge them into a `SecIdentity` Network.framework's TLS
// stack can actually use" (the DER encoding itself lives in
// `LANRemoteIdentityDER.swift`, split out for both LOC-budget and
// separation-of-concerns reasons: pure ASN.1 bytes vs. Security-framework/
// Keychain plumbing). **What this file does NOT own is PERSISTENCE
// POLICY** — i.e. "generate once at first launch, then keep reusing the
// same identity across every relaunch so a remote's pinned fingerprint
// stays valid" is explicitly out of scope here (PR-6). `generateSelfSigned`
// below makes a FRESH identity every time it's called; `TVListenerActor`
// (this module) simply accepts an already-built `LANRemoteIdentity` value
// via dependency injection — it never calls the generator itself and has no
// opinion on where the identity it was handed came from. That is exactly
// what "accept an injected identity/fingerprint so it's testable" means:
// `LANRemoteTests`' TLS loopback suite calls `generateSelfSigned` once per
// test run and injects the result; PR-6's real tvOS app will instead call
// it ONCE ever (checking a Keychain-persisted marker first) and inject the
// same identity back on every subsequent launch.
//
// **Why `SecItemAdd`/Keychain at all, if persistence is out of scope?**
// Apple's `Security.framework` has no PUBLIC API to construct a `SecIdentity`
// (the type `NWProtocolTLS.Options`' local-identity setter requires) purely
// in-memory from a `SecCertificate` + a matching `SecKey` — the only
// documented routes are importing a PKCS#12 blob (`SecPKCS12Import`, which
// would mean hand-encoding ANOTHER binary format just to avoid this one) or
// adding the certificate and key as loose Keychain items under a shared
// label and querying `kSecClassIdentity`, which is the well-established
// technique this file uses (and the one Apple's own Network.framework
// sample code for custom peer-to-peer protocols uses too). Every item this
// file adds is tagged with a UUID-unique label and is meant to be removed
// via `removeFromKeychain()` once no longer needed (`LANRemoteTests` does
// this in a `defer`) — this is Keychain used as unavoidable OS PLUMBING to
// bridge two in-memory objects into the shape TLS needs, not as the
// "remember this across app launches" persistence PR-6 owns.
import Foundation
import Network
import Security

/// Everything that can go wrong generating or bridging a LAN-remote TLS
/// identity.
///
/// ELI5: The list of ways "make me a TV identity" can fail.
public enum LANRemoteIdentityError: Error, Sendable, Equatable {
    case keyGenerationFailed(String)
    case publicKeyExportFailed(String)
    case signingFailed(String)
    case certificateCreationFailed
    /// `status` is the raw `OSStatus` from the failing `Security.framework`
    /// call (`SecItemAdd`/`SecItemCopyMatching`) — logged/inspected via
    /// `SecCopyErrorMessageString`, never silently swallowed.
    case keychainBridgeFailed(status: OSStatus)
}

/// A ready-to-use LAN-remote TLS identity: a P-256 key pair, a minimal
/// self-signed X.509 certificate for it, and the certificate's SHA-256
/// fingerprint.
///
/// ELI5: The TV's "who I am" bundle for every TLS connection it accepts.
///
/// DETAILED: `@unchecked Sendable` — `SecIdentity` is an opaque
/// Core-Foundation reference type Apple's `Security.framework` documents as
/// safe to use from any thread/queue (its underlying key material is
/// immutable once created), but the SDK does not itself annotate it
/// `Sendable`; this wrapper is the one place that trust is asserted, rather
/// than every call site needing its own `@unchecked` escape hatch.
public struct LANRemoteIdentity: @unchecked Sendable {
    /// SHA-256 hex digest (64 lowercase hex characters) of the DER-encoded
    /// certificate — what a remote pins against an out-of-band value
    /// (QR/manual code, PR-6) and what `IHLog.discovery`/`IHLog.remote`
    /// log at `.public` privacy (this is a public certificate identity, not
    /// a secret — the same convention as an SSH host-key fingerprint).
    public let fingerprint: String

    /// The raw DER bytes of the self-signed certificate — kept around
    /// purely so `fingerprint` is independently re-derivable/verifiable in
    /// tests without re-parsing anything out of `SecIdentity`.
    public let certificateDER: Data

    let secIdentityRef: SecIdentity
    let keychainLabel: String

    /// Builds this identity's `NWProtocolTLS.Options` for the SERVER
    /// (`TVListenerActor`) side of a connection — sets the local identity
    /// and pins the minimum negotiated version to TLS 1.3 (strategy §2.4.2:
    /// "TLS 1.3").
    ///
    /// ELI5: "Here's how the TV proves who it is when a remote connects."
    public func makeServerTLSOptions() throws -> NWProtocolTLS.Options {
        guard let wrapped = sec_identity_create(secIdentityRef) else {
            throw LANRemoteIdentityError.certificateCreationFailed
        }
        let options = NWProtocolTLS.Options()
        sec_protocol_options_set_local_identity(options.securityProtocolOptions, wrapped)
        sec_protocol_options_set_min_tls_protocol_version(options.securityProtocolOptions, .TLSv13)
        return options
    }

    /// Removes the Keychain items this identity's generation bridged
    /// through (see this file's header comment) — call once the identity is
    /// no longer needed. Idempotent (a second call finds nothing and is a
    /// harmless no-op); errors are intentionally NOT thrown — cleanup best-
    /// effort failing silently is strictly better than a test/teardown path
    /// itself throwing.
    ///
    /// ELI5: "Forget this TV identity."
    public func removeFromKeychain() {
        LANRemoteIdentityFactory.removeFromKeychain(label: keychainLabel)
    }
}

/// Generates fresh `LANRemoteIdentity` values — see this file's header
/// comment for the persistence-policy boundary (PR-6, not here).
public enum LANRemoteIdentityFactory {
    /// Generates a brand-new P-256 self-signed identity.
    ///
    /// ELI5: "Make me a new TV identity, right now."
    ///
    /// - Parameters:
    ///   - commonName: The certificate's Common Name — cosmetic only (never
    ///     validated against anything; strategy §2.4.3's trust model is
    ///     fingerprint pinning, not name matching). Defaults to a generic
    ///     label; PR-6 may pass a venue/device-specific name.
    ///   - validityDays: How long the certificate's `notAfter` is set into
    ///     the future. Irrelevant to trust (never chain-validated) but a
    ///     well-formed certificate needs SOME validity window; defaults to
    ///     5 years so a persisted (PR-6) identity doesn't need routine
    ///     rotation.
    public static func generateSelfSigned(
        commonName: String = "iHymnsTV",
        validityDays: Int = 365 * 5
    ) throws -> LANRemoteIdentity {
        let keyAttributes: [String: Any] = [
            kSecAttrKeyType as String: kSecAttrKeyTypeECSECPrimeRandom,
            kSecAttrKeySizeInBits as String: 256
        ]
        var keyGenError: Unmanaged<CFError>?
        guard let privateKey = SecKeyCreateRandomKey(keyAttributes as CFDictionary, &keyGenError) else {
            throw LANRemoteIdentityError.keyGenerationFailed(describe(keyGenError))
        }
        guard let publicKey = SecKeyCopyPublicKey(privateKey) else {
            throw LANRemoteIdentityError.keyGenerationFailed("SecKeyCopyPublicKey returned nil")
        }
        var pubExportError: Unmanaged<CFError>?
        guard let publicKeyData = SecKeyCopyExternalRepresentation(publicKey, &pubExportError) as Data? else {
            throw LANRemoteIdentityError.publicKeyExportFailed(describe(pubExportError))
        }

        let certificateDER = try buildAndSignCertificate(
            commonName: commonName,
            publicKeyData: publicKeyData,
            privateKey: privateKey,
            validityDays: validityDays
        )

        guard let certificate = SecCertificateCreateWithData(nil, certificateDER as CFData) else {
            throw LANRemoteIdentityError.certificateCreationFailed
        }

        let label = "app.ihymns.lanremote.\(UUID().uuidString)"
        try bridgeToKeychainIdentity(privateKey: privateKey, certificate: certificate, label: label)
        let secIdentityRef = try queryIdentity(label: label)

        let fingerprint = LANRemoteFingerprint.sha256Hex(certificateDER)

        return LANRemoteIdentity(
            fingerprint: fingerprint,
            certificateDER: certificateDER,
            secIdentityRef: secIdentityRef,
            keychainLabel: label
        )
    }

    /// Builds and self-signs the minimal X.509 DER certificate — see
    /// `LANRemoteIdentityDER.swift`'s header comment for why v1/no-
    /// extensions is the correct, safe shape here.
    private static func buildAndSignCertificate(
        commonName: String,
        publicKeyData: Data,
        privateKey: SecKey,
        validityDays: Int
    ) throws -> Data {
        let serial = LANRemoteDER.integer((0..<16).map { _ in UInt8.random(in: 0...255) })
        let signatureAlgorithm = LANRemoteDER.sequence([LANRemoteDER.objectIdentifier(LANRemoteDER.oidEcdsaWithSHA256)])
        let issuerAndSubject = LANRemoteDER.commonNameOnly(commonName)
        let notBefore = LANRemoteDER.utcTime(Date().addingTimeInterval(-300))
        let notAfter = LANRemoteDER.utcTime(Date().addingTimeInterval(TimeInterval(validityDays) * 86_400))
        let validity = LANRemoteDER.sequence([notBefore, notAfter])
        let subjectPublicKeyInfo = LANRemoteDER.sequence([
            LANRemoteDER.sequence([
                LANRemoteDER.objectIdentifier(LANRemoteDER.oidIdEcPublicKey),
                LANRemoteDER.objectIdentifier(LANRemoteDER.oidPrime256v1)
            ]),
            LANRemoteDER.bitString(Array(publicKeyData))
        ])
        let tbsCertificate = LANRemoteDER.sequence([
            serial, signatureAlgorithm, issuerAndSubject, validity, issuerAndSubject, subjectPublicKeyInfo
        ])

        var signError: Unmanaged<CFError>?
        guard let signature = SecKeyCreateSignature(
            privateKey,
            .ecdsaSignatureMessageX962SHA256,
            Data(tbsCertificate) as CFData,
            &signError
        ) as Data? else {
            throw LANRemoteIdentityError.signingFailed(describe(signError))
        }

        let certificate = LANRemoteDER.sequence([
            tbsCertificate, signatureAlgorithm, LANRemoteDER.bitString(Array(signature))
        ])
        return Data(certificate)
    }

    /// Bridges a loose `SecKey`+`SecCertificate` pair into a queryable
    /// `SecIdentity` by adding both to the Keychain under the same
    /// `kSecAttrLabel` — see this file's header comment.
    private static func bridgeToKeychainIdentity(privateKey: SecKey, certificate: SecCertificate, label: String) throws {
        let keyQuery: [String: Any] = [
            kSecClass as String: kSecClassKey,
            kSecValueRef as String: privateKey,
            kSecAttrLabel as String: label,
            kSecAttrApplicationTag as String: Data(label.utf8)
        ]
        let keyStatus = SecItemAdd(keyQuery as CFDictionary, nil)
        guard keyStatus == errSecSuccess else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: keyStatus)
        }

        let certificateQuery: [String: Any] = [
            kSecClass as String: kSecClassCertificate,
            kSecValueRef as String: certificate,
            kSecAttrLabel as String: label
        ]
        let certificateStatus = SecItemAdd(certificateQuery as CFDictionary, nil)
        guard certificateStatus == errSecSuccess else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: certificateStatus)
        }
    }

    private static func queryIdentity(label: String) throws -> SecIdentity {
        let query: [String: Any] = [
            kSecClass as String: kSecClassIdentity,
            kSecAttrLabel as String: label,
            kSecReturnRef as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let result, CFGetTypeID(result) == SecIdentityGetTypeID() else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: status)
        }
        // swiftlint:disable:next force_cast
        return (result as! SecIdentity)
    }

    /// Removes the Keychain items tagged with `label` — see
    /// `LANRemoteIdentity.removeFromKeychain()`'s doc comment.
    static func removeFromKeychain(label: String) {
        let keyQuery: [String: Any] = [kSecClass as String: kSecClassKey, kSecAttrLabel as String: label]
        let certificateQuery: [String: Any] = [kSecClass as String: kSecClassCertificate, kSecAttrLabel as String: label]
        SecItemDelete(keyQuery as CFDictionary)
        SecItemDelete(certificateQuery as CFDictionary)
    }

    private static func describe(_ error: Unmanaged<CFError>?) -> String {
        guard let error else { return "unknown error" }
        return String(describing: error.takeRetainedValue())
    }
}
