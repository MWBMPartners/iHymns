// ApnsEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "here's my device's push address, remember it"
// and "forget my push address" — the two calls that let this app receive
// Live Activity updates (and, later, ordinary push notifications) from the
// server.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Verified against
// the LIVE `appWeb/public_html/api.php` handlers (`case 'apns_register'`/
// `case 'apns_unregister'`, #1410/#1429 C3) on 2026-07-19 — this branch is
// off `alpha` and does NOT carry PR-14/PR-15's edits, so `LiveSyncEndpoints
// .swift`'s factories are exactly the PR-10 shape. Mirrors `AuthEndpoints
// .swift`'s pattern: `internal` (not `public`) factory members — only
// `APIClient+Apns.swift` (same module) and `@testable import IHAPI` tests
// call these directly.
//
// `apns_register`'s `kind=liveActivity` branch REQUIRES `sessionId` (server
// 400s without it — `api.php`'s own validation) but every OTHER kind
// (`device`, the default) has no session to scope to, so `sessionId` is
// `Optional` here and OMITTED from the body when `nil` — never sent as a
// literal `null` (Swift's synthesized `Encodable` `encodeIfPresent`
// behaviour, the SAME "absent key, never null" convention `AuthEndpoints
// .swift`'s own header documents).
import Foundation

extension Endpoint {
    /// `?action=apns_register` — POST, auth required (`api.php`, `case
    /// 'apns_register'`). Registers (or re-registers, `ON DUPLICATE KEY
    /// UPDATE`) a device's push token.
    ///
    /// - Parameters:
    ///   - pushTokenHex: The raw APNs device/Live-Activity token, lower-case
    ///     hex (the server validates `^[a-f0-9]{32,200}$`).
    ///   - apnsEnv: `"sandbox"` or `"production"` (`APNS_ENVIRONMENTS`).
    ///   - kind: `"device"` (ordinary push) or `"liveActivity"` — the
    ///     latter REQUIRES `sessionId`.
    ///   - sessionId: `tblLiveFollowSessions.Id` — required (server 400s
    ///     otherwise) when `kind == "liveActivity"`; omitted entirely for
    ///     every other kind.
    /// - Throws: Only if `JSONEncoder` fails (see `AuthEndpoints.authLogin`'s
    ///   own doc comment for why this is practically unreachable).
    static func apnsRegister(pushTokenHex: String, apnsEnv: String, kind: String, sessionId: Int?) throws -> Endpoint {
        let body = try JSONEncoder().encode(
            ApnsRegisterBody(pushToken: pushTokenHex, apnsEnv: apnsEnv, kind: kind, sessionId: sessionId)
        )
        return Endpoint(action: "apns_register", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=apns_unregister` — POST, auth required (`api.php`, `case
    /// 'apns_unregister'`). Best-effort; the server always 200s, even on an
    /// un-migrated docroot or an already-unknown token.
    static func apnsUnregister(pushTokenHex: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(ApnsUnregisterBody(pushToken: pushTokenHex))
        return Endpoint(action: "apns_unregister", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }
}

/// The exact wire shape `apns_register`'s JSON body expects — a private
/// implementation detail of `Endpoint.apnsRegister(...)` above, never
/// constructed anywhere else.
private struct ApnsRegisterBody: Encodable {
    let pushToken: String
    let apnsEnv: String
    let kind: String
    let sessionId: Int?
}

/// The exact wire shape `apns_unregister`'s JSON body expects.
private struct ApnsUnregisterBody: Encodable {
    let pushToken: String
}
