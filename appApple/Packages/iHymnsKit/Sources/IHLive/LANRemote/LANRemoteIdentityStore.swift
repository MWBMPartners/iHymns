// LANRemoteIdentityStore.swift
// IHLive/LANRemote
//
// ELI5: The TV's "keep the same face forever" box — the first time the TV
// app ever runs, it makes itself a TLS identity and locks it in the
// Keychain under one fixed name; every time after that, it just opens the
// box and reuses the SAME identity instead of making a new one. This is
// what lets a paired remote's saved fingerprint keep working across every
// future relaunch/reboot.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §5.1). Fills the
// persistence-policy gap `LANRemoteIdentity.swift`'s own header comment
// explicitly deferred: "storing the identity in the Keychain is PR-6's
// job." MUST live in `IHLive` (not `IHFeatures`) because `LANRemoteIdentity`'s
// memberwise initializer is `internal` — only same-module code can
// construct one directly, which is exactly what `loadOrCreate()`'s
// "found an existing identity" branch below needs to do.
//
// **The load-bearing #1421 acceptance property this file exists for:**
// `loadOrCreate()` called TWICE (i.e. two separate app launches) returns
// the SAME `fingerprint` + the SAME `certificateDER` both times —
// `LANRemoteIdentityStoreTests` asserts this directly. Without it, every
// TV relaunch would silently invalidate every remote's pinned fingerprint
// (`RemoteSessionActor`'s `expectedFingerprint`), turning "pair once" into
// "re-pair every time the TV app restarts."
import Foundation
import Security

/// Loads (or, on first run, generates) the TV's ONE persistent LAN-remote
/// TLS identity.
///
/// ELI5: "Give me the TV's identity — the same one every time, making a
/// new one only if there truly isn't one yet."
public enum LANRemoteIdentityStore {
    /// The ONE fixed Keychain label the persistent identity lives under —
    /// deliberately NOT a per-launch UUID (unlike PR-4's own throwaway-test
    /// label scheme, `LANRemoteIdentityFactory.generateSelfSigned`'s
    /// default): a fixed label is what makes `queryIdentity(label:)` able
    /// to find the SAME identity again on the next launch.
    static let identityLabel = "app.ihymns.lanremote.identity.v1"

    /// Loads the persisted identity, or generates-and-persists one on
    /// first launch.
    ///
    /// ELI5: "Open the TV's identity box — if it's empty, make a new
    /// identity and put it in the box for next time."
    ///
    /// DETAILED (spec §5.1's exact algorithm):
    /// 1. `LANRemoteIdentityFactory.queryIdentity(label:)` — the SAME
    ///    query shape PR-4's factory already uses internally, REUSED here
    ///    rather than a second, hand-rolled `SecItemCopyMatching` call.
    /// 2. Found → re-derive the fingerprint from the identity's OWN
    ///    certificate (`SecIdentityCopyCertificate`/`SecCertificateCopyData`)
    ///    rather than trusting any cached value — the certificate IS the
    ///    fingerprint's only source of truth.
    /// 3. `errSecItemNotFound` → generate a FRESH identity under this
    ///    exact persistent label (`LANRemoteIdentityFactory
    ///    .generateSelfSigned(keychainLabel:)`, #1421's new parameter).
    /// 4. Any OTHER `OSStatus` → propagate `queryIdentity`'s own
    ///    `.keychainBridgeFailed(status:)` unchanged (a genuine Keychain
    ///    error, not "first launch").
    ///
    /// - Parameter commonName: Passed straight through to
    ///   `generateSelfSigned` on first launch only — irrelevant once an
    ///   identity already exists (a certificate's Common Name is cosmetic,
    ///   never re-validated — `LANRemoteIdentity.swift`'s own header).
    public static func loadOrCreate(commonName: String = "iHymnsTV") throws -> LANRemoteIdentity {
        do {
            let secIdentityRef = try LANRemoteIdentityFactory.queryIdentity(label: identityLabel)
            return try wrapExisting(secIdentityRef)
        } catch LANRemoteIdentityError.keychainBridgeFailed(let status) where status == errSecItemNotFound {
            return try LANRemoteIdentityFactory.generateSelfSigned(commonName: commonName, keychainLabel: identityLabel)
        }
    }

    /// Deletes the persisted identity — the next `loadOrCreate()` call
    /// mints an entirely NEW one (a DIFFERENT fingerprint), which breaks
    /// every already-paired remote's pin. Deliberately NOT surfaced in any
    /// PR-6 UI (spec §5.1: "NOT surfaced in PR-6 UI") — this exists as a
    /// test hook today, and a future "reset TV identity" Settings
    /// affordance would need to warn the operator explicitly before ever
    /// calling this.
    ///
    /// ELI5: "Throw away the TV's current identity — the next time it
    /// starts, it'll make a brand new one, and every remote paired against
    /// the OLD one will stop being trusted."
    public static func reset() {
        LANRemoteIdentityFactory.removeFromKeychain(label: identityLabel)
    }

    /// Re-derives a `LANRemoteIdentity` value from an already-bridged
    /// `SecIdentity` found in the Keychain — the "identity already
    /// existed" half of `loadOrCreate()`'s algorithm.
    private static func wrapExisting(_ secIdentityRef: SecIdentity) throws -> LANRemoteIdentity {
        var certificateRef: SecCertificate?
        let status = SecIdentityCopyCertificate(secIdentityRef, &certificateRef)
        guard status == errSecSuccess, let certificateRef else {
            throw LANRemoteIdentityError.keychainBridgeFailed(status: status)
        }
        let certificateDER = SecCertificateCopyData(certificateRef) as Data
        return LANRemoteIdentity(
            fingerprint: LANRemoteFingerprint.sha256Hex(certificateDER),
            certificateDER: certificateDER,
            secIdentityRef: secIdentityRef,
            keychainLabel: identityLabel
        )
    }
}
