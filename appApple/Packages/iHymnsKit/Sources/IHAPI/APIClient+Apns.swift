// APIClient+Apns.swift
// IHAPI
//
// ELI5: The actual "phone calls" for registering/forgetting this device's
// push address — thin wrappers over `ApnsEndpoints.swift`'s recipe cards,
// the same shape every other `APIClient+*.swift` extension in this module
// already follows.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Both calls are
// non-idempotent POSTs (register UPSERTs, unregister DELETEs) — go through
// the non-retrying `performOnce`, mirroring `APIClient+LiveSync.swift`'s
// own "never auto-retry a non-idempotent POST" rule. Neither response body
// carries anything callers need back beyond "did this succeed" (both are
// `{"ok": true}` shapes on success, mirroring `authLogout()`/`serviceLeave
// (presenceToken:)`'s own "2xx status alone is the signal" pattern) — no
// dedicated decode function exists for either, matching those precedents.
import Foundation

extension APIClient {
    /// `?action=apns_register` — registers this device's (or this Live
    /// Activity's) push token. See `Endpoint.apnsRegister(...)`'s own doc
    /// comment for the `kind`/`sessionId` contract.
    public func apnsRegister(pushTokenHex: String, apnsEnv: String, kind: String, sessionId: Int?) async throws {
        let endpoint = try Endpoint.apnsRegister(pushTokenHex: pushTokenHex, apnsEnv: apnsEnv, kind: kind, sessionId: sessionId)
        _ = try await performOnce(endpoint)
    }

    /// `?action=apns_unregister` — best-effort forgets this device's push
    /// token (always 200s server-side, even for an unknown token).
    public func apnsUnregister(pushTokenHex: String) async throws {
        let endpoint = try Endpoint.apnsUnregister(pushTokenHex: pushTokenHex)
        _ = try await performOnce(endpoint)
    }
}
