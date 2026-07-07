// TokenStoring.swift
// IHAuth
//
// ELI5: The rulebook for "somewhere safe to keep the user's login token" —
// save it, read it back, throw it away. `KeychainTokenStore` is the real
// safe; tests use a pretend one instead so they don't need real device
// security hardware.
//
// DETAILED: Extracting this as a `protocol` (rather than calling
// `KeychainTokenStore` directly everywhere) is what makes
// `SessionControllerTests` possible without touching the real Keychain —
// CI runners and sandboxed test hosts often can't exercise
// `kSecClassGenericPassword` reliably (no interactive session to unlock a
// login keychain, no keychain-access-group entitlement for a bare `swift
// test` binary), so the *tests* substitute `InMemoryTokenStore`
// (`IHAuthTests`) while the real `actor KeychainTokenStore` remains the
// production implementation wired up by the app shells. This is the
// standard "protocol witness" testability pattern — see Apple's own
// discussion of protocol-oriented programming:
// https://developer.apple.com/videos/play/wwdc2015/408/.
import Foundation

/// Anything capable of durably storing/retrieving/deleting a single bearer
/// token for the current signed-in session.
///
/// ELI5: Save a token, read it back, or delete it.
///
/// DETAILED: `Sendable` because conforming actors/types must be safely
/// usable from the `SessionController` actor's isolated context (strategy
/// §1.3). Deliberately single-token (not keyed by account) because iHymns'
/// v1 native auth model is "one global login" (strategy §1.6) — one Apple
/// ID, one token, synced via iCloud Keychain across all the user's devices.
public protocol TokenStoring: Sendable {
    /// Persists `token`, replacing any previously stored value.
    func save(_ token: String) async throws

    /// Returns the currently stored token, or `nil` if none is stored.
    func load() async throws -> String?

    /// Removes any stored token.
    ///
    /// DETAILED: Called as the SECOND step of sign-out, strictly after the
    /// server has already revoked the token (strategy §3.2: "sign-out =
    /// server-revoke THEN delete synchronizable item — else iCloud
    /// resurrects a revoked token"). `TokenStoring` itself doesn't enforce
    /// that ordering — that's `SessionController`'s job — but the doc
    /// comment lives here too since it's the sharpest edge in this whole
    /// subsystem.
    func delete() async throws
}
