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
// Apple's `Security.framework` has no PUBLIC, CROSS-PLATFORM API to build a
// `SecIdentity` (the type `NWProtocolTLS.Options`' local-identity setter
// requires) purely in-memory from a `SecCertificate` + a matching `SecKey`.
// `SecIdentityCreateWithCertificate` does exactly that — but it is **macOS-
// ONLY** (its use in PR #1529 compiled locally via `swift build` for macOS
// yet BROKE the tvOS Simulator build: "cannot find 'SecIdentityCreateWith
// Certificate' in scope"). The portable route (iOS/tvOS/macOS) is the one
// this file now uses: put the matching PRIVATE KEY and the CERTIFICATE both
// in the Keychain, let the OS AUTO-FORM the identity, then retrieve it with
// a `kSecClassIdentity` query. Every item is tagged by a UUID-unique label
// (or a persistent one, #1421) and removed via `removeFromKeychain()`.
//
// **#1421 FIX #1 (kept) — the private key MUST be generated with
// `kSecAttrIsPermanent: true` directly inside `SecKeyCreateRandomKey`'s OWN
// attributes, never generated ephemeral-then-`SecItemAdd`-ed afterward.**
// A second-hand `SecItemAdd(kSecValueRef:)` never wires up the key's
// `kSecAttrApplicationLabel` (its public-key hash), the ONLY thing the OS
// matches a certificate to its key on when forming a `SecIdentity`. Apple's
// documented "generate a keychain-resident key" pattern
// (https://developer.apple.com/documentation/security/certificate_key_and_trust_services/keys/generating_new_cryptographic_keys)
// fixes this on the first try.
//
// **#1421 FIX #2 (revised for the tvOS-build fix) — the certificate IS stored
// as a `kSecClassCertificate` item (the OS needs BOTH key + cert present to
// form the identity on iOS/tvOS), but it is NEVER LOOKED UP by
// `kSecAttrLabel`** — `SecItemAdd` silently overwrites a certificate item's
// label with its Subject Common Name, so a label lookup is unreliable the
// instant two self-signed identities share a CN. Instead `queryIdentity`
// ENUMERATES every formed `SecIdentity` and returns the one whose certificate
// DER is byte-identical to ours (kept in a stable `kSecClassGenericPassword`
// blob keyed by our own `label`). That DER match is label-independent — it
// can never silently return an UNRELATED identity (the wrong-identity hazard
// PR #1529 rightly worried about) — AND uses no macOS-only API, so it both
// compiles and runs on tvOS. Uses each platform's DEFAULT keychain (file on
// macOS, data-protection on tvOS) — deliberately NOT `kSecUseDataProtection
// Keychain`, which an unsigned headless `swift test` binary can't access
// (it would make the whole gated suite un-runnable on the dev Mac).
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
    ///   - keychainLabel: `nil` (every PR-4 call site, UNCHANGED) keeps
    ///     today's fresh-per-call `"app.ihymns.lanremote.\(UUID())"` label —
    ///     this parameter is purely ADDITIVE. A non-nil value (#1421,
    ///     `LANRemoteIdentityStore.swift`'s persistent-identity path) uses
    ///     that EXACT label instead — the one thing that makes
    ///     `LANRemoteIdentityStore.loadOrCreate()` able to find the SAME
    ///     identity again on the next launch via `queryIdentity(label:)`.
    public static func generateSelfSigned(
        commonName: String = "iHymnsTV",
        validityDays: Int = 365 * 5,
        keychainLabel: String? = nil
    ) throws -> LANRemoteIdentity {
        let label = keychainLabel ?? "app.ihymns.lanremote.\(UUID().uuidString)"
        if keychainLabel != nil {
            // A caller-supplied (persistent) label may have stray items
            // left over from a half-completed PRIOR generate under this
            // same label — pre-delete so this attempt doesn't fail with
            // `errSecDuplicateItem`. A fresh per-call UUID label (every
            // PR-4 call site) can never collide, so this only runs for
            // #1421's persistent-identity path.
            removeFromKeychain(label: label)
        }

        // `kSecAttrIsPermanent: true` HERE (not a separate `SecItemAdd`
        // afterward) — this file's #1421 header update explains why that
        // distinction is load-bearing for `SecIdentityCreateWithCertificate`
        // to ever succeed.
        let keyAttributes: [String: Any] = [
            kSecAttrKeyType as String: kSecAttrKeyTypeECSECPrimeRandom,
            kSecAttrKeySizeInBits as String: 256,
            kSecAttrIsPermanent as String: true,
            kSecAttrLabel as String: label,
            kSecAttrApplicationTag as String: Data(label.utf8),
            // `.afterFirstUnlock` (#1421): readable right after a tvOS
            // reboot, before a SECOND unlock — `KeychainTokenStore.save(_:)`'s
            // identical "early-launch read must succeed" reasoning.
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        let privateKey = try generatePermanentKey(attributes: keyAttributes)
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

        try persistCertificateDER(certificateDER, label: label)
        try addCertificateItem(certificateDER)
        let secIdentityRef = try queryIdentity(label: label)

        return LANRemoteIdentity(
            fingerprint: LANRemoteFingerprint.sha256Hex(certificateDER),
            certificateDER: certificateDER,
            secIdentityRef: secIdentityRef,
            keychainLabel: label
        )
    }

    /// Generates a PERMANENT (`kSecAttrIsPermanent: true`) private key,
    /// retrying a bounded number of times on a TRANSIENT Keychain-daemon
    /// contention failure.
    ///
    /// ELI5: "Ask the Keychain for a new permanent key — if it's briefly
    /// busy and says no, wait a moment and ask again, a couple of times,
    /// before giving up for real."
    ///
    /// DETAILED: #1421. Empirically observed: concurrent
    /// `SecKeyCreateRandomKey(kSecAttrIsPermanent: true)` calls (this PR's
    /// test suites all exercise the same factory at once) can transiently
    /// fail (`errSecItemNotFound`/"failed to generate CDSA key") — a real
    /// macOS/iOS Keychain-daemon contention artifact, not a defect in the
    /// attributes passed. A bounded retry with a short linear backoff is
    /// cheap insurance for a call that happens at most once per TV launch
    /// in production. A genuine, non-transient failure fails identically
    /// on every attempt and surfaces after the retries, same as before.
    private static func generatePermanentKey(attributes: [String: Any]) throws -> SecKey {
        var lastError: Unmanaged<CFError>?
        for attempt in 0..<3 {
            var keyGenError: Unmanaged<CFError>?
            if let key = SecKeyCreateRandomKey(attributes as CFDictionary, &keyGenError) {
                return key
            }
            lastError = keyGenError
            if attempt < 2 {
                Thread.sleep(forTimeInterval: 0.05 * Double(attempt + 1))
            }
        }
        throw LANRemoteIdentityError.keyGenerationFailed(describe(lastError))
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

    private static func describe(_ error: Unmanaged<CFError>?) -> String {
        guard let error else { return "unknown error" }
        return String(describing: error.takeRetainedValue())
    }
}
