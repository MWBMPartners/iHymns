// LANRemoteIdentityKeychain.swift
// IHLive/LANRemote
//
// ELI5: The Keychain plumbing half of the TV's TLS identity — "write the
// certificate + key into the Keychain so the OS can pair them into an
// identity," "find OUR identity again later," and "forget it." Split out of
// `LANRemoteIdentity.swift` purely for the 400-line file budget
// (`appApple/Scripts/loc-budget.sh`); the WHY of every decision here lives
// in that file's header comment (read it first).
//
// DETAILED: #1421 (PR-6) + the ★ CI fix (PR #1529 broke the tvOS build by
// reaching for the macOS-only `SecIdentityCreateWithCertificate`). This
// extension holds the cross-platform bridge: BOTH the permanent private key
// (`LANRemoteIdentity.swift`) AND the certificate item below are placed in
// the Keychain so iOS/tvOS/macOS AUTO-FORM the `SecIdentity`, then
// `queryIdentity(label:)` retrieves OURS by matching the exact certificate
// DER bytes — never the macOS-only creation API, and never an OS-overwritten
// `kSecAttrLabel`. Uses each platform's DEFAULT keychain (file on macOS,
// data-protection on tvOS) so an unsigned headless `swift test` binary can
// still exercise the gated suites on the dev Mac.
import Foundation
import Security

extension LANRemoteIdentityFactory {

    /// The `kSecClassGenericPassword` namespace the certificate DER bytes are
    /// persisted under — `kSecAttrAccount` is the caller's own `label`, so
    /// each identity's certificate DER is independently addressable and acts
    /// as the STABLE "which certificate is ours" record `queryIdentity` uses.
    private static let certificateDERService = "app.ihymns.lanremote.identitycert"

    /// Persists `der` in the label-keyed generic-password blob (replace
    /// semantics — `KeychainTokenStore.save(_:)`'s "delete first" convention).
    /// This blob is how `queryIdentity(label:)` picks OUR `SecIdentity` out of
    /// a keychain that may hold others, without the OS-overwritten cert label.
    static func persistCertificateDER(_ der: Data, label: String) throws {
        SecItemDelete(certificateDERQuery(label: label) as CFDictionary)
        var query = certificateDERQuery(label: label)
        query[kSecValueData as String] = der
        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: status)
        }
    }

    private static func certificateDERQuery(label: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: certificateDERService,
            kSecAttrAccount as String: label,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
    }

    /// Adds the certificate as a real `kSecClassCertificate` item so the OS
    /// AUTO-FORMS a `SecIdentity` from it + the matching permanent private key
    /// (the cross-platform bridge — `SecIdentityCreateWithCertificate` is
    /// **macOS-only** and its use in PR #1529 broke the tvOS build). Delete-
    /// then-add by the SPECIFIC certificate value (never by label — the OS
    /// derives a cert item's label from its Subject CN, which two same-CN
    /// identities would collide on) keeps regenerate idempotent.
    static func addCertificateItem(_ der: Data) throws {
        guard let certificate = SecCertificateCreateWithData(nil, der as CFData) else {
            throw LANRemoteIdentityError.certificateCreationFailed
        }
        let base: [String: Any] = [
            kSecClass as String: kSecClassCertificate,
            kSecValueRef as String: certificate
        ]
        SecItemDelete(base as CFDictionary)
        let status = SecItemAdd(base as CFDictionary, nil)
        guard status == errSecSuccess || status == errSecDuplicateItem else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: status)
        }
    }

    /// Looks up the `SecIdentity` for `label` — `internal` (not `private`,
    /// #1421 PR-6 spec §5.1) so `LANRemoteIdentityStore.swift` reuses this
    /// EXACT lookup rather than duplicating it.
    ///
    /// **Cross-platform, label-independent** (the ★ CI-fix reconciliation of
    /// two constraints): (1) reads OUR certificate's DER from the stable
    /// generic-password blob; (2) enumerates EVERY `SecIdentity` the keychain
    /// has formed and returns the one whose certificate DER is byte-identical
    /// to ours. Never calls the macOS-only `SecIdentityCreateWithCertificate`
    /// (so it compiles AND runs on tvOS), and never trusts a `kSecAttrLabel`
    /// the OS may have overwritten (so it can't silently return an UNRELATED
    /// identity — the wrong-identity hazard PR #1529 rightly worried about,
    /// solved without the macOS-only API).
    ///
    /// SECURITY-RELEVANT: `TVListenerActor.start()` builds its TLS options
    /// from whatever this returns; matching on the exact DER bytes guarantees
    /// the presented fingerprint equals `LANRemoteIdentity.fingerprint` a
    /// remote pins against.
    static func queryIdentity(label: String) throws -> SecIdentity {
        var derQuery = certificateDERQuery(label: label)
        derQuery[kSecReturnData as String] = true
        derQuery[kSecMatchLimit as String] = kSecMatchLimitOne
        var derResult: CFTypeRef?
        let derStatus = SecItemCopyMatching(derQuery as CFDictionary, &derResult)
        guard derStatus == errSecSuccess, let ourDER = derResult as? Data else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: derStatus)
        }

        let identityQuery: [String: Any] = [
            kSecClass as String: kSecClassIdentity,
            kSecReturnRef as String: true,
            kSecMatchLimit as String: kSecMatchLimitAll
        ]
        var identitiesResult: CFTypeRef?
        let identityStatus = SecItemCopyMatching(identityQuery as CFDictionary, &identitiesResult)
        guard identityStatus == errSecSuccess, let identities = identitiesResult as? [SecIdentity] else {
            // No identities at all ⇒ treat as "not found" so `loadOrCreate`
            // regenerates, rather than surfacing a raw non-notFound status.
            throw LANRemoteIdentityError.keychainBridgeFailed(status: errSecItemNotFound)
        }
        for identity in identities {
            var certificateRef: SecCertificate?
            guard SecIdentityCopyCertificate(identity, &certificateRef) == errSecSuccess,
                  let certificateRef else { continue }
            if (SecCertificateCopyData(certificateRef) as Data) == ourDER {
                return identity
            }
        }
        throw LANRemoteIdentityError.keychainBridgeFailed(status: errSecItemNotFound)
    }

    /// Removes every Keychain item this identity generated — the permanent
    /// private key (by its application tag = `label`), the generic-password
    /// DER blob, and the `kSecClassCertificate` item (deleted by its exact
    /// value, reconstructed from the blob's DER, so a same-CN cert belonging
    /// to something else is never touched).
    static func removeFromKeychain(label: String) {
        // Reconstruct + delete the specific certificate item first (best
        // effort — if the blob is already gone, skip it).
        var derQuery = certificateDERQuery(label: label)
        derQuery[kSecReturnData as String] = true
        derQuery[kSecMatchLimit as String] = kSecMatchLimitOne
        var derResult: CFTypeRef?
        if SecItemCopyMatching(derQuery as CFDictionary, &derResult) == errSecSuccess,
           let der = derResult as? Data, let certificate = SecCertificateCreateWithData(nil, der as CFData) {
            SecItemDelete([
                kSecClass as String: kSecClassCertificate,
                kSecValueRef as String: certificate
            ] as CFDictionary)
        }
        SecItemDelete([
            kSecClass as String: kSecClassKey,
            kSecAttrApplicationTag as String: Data(label.utf8)
        ] as CFDictionary)
        SecItemDelete(certificateDERQuery(label: label) as CFDictionary)
    }
}
