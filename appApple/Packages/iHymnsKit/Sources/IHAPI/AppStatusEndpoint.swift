// AppStatusEndpoint.swift
// IHAPI
//
// ELI5: The recipe card for "what's going on right now, before I even try
// to do anything?" — a single bodyless GET the app can fire at launch to
// learn whether a sign-in form needs a CAPTCHA solved first.
//
// DETAILED: `.claude/captcha-native-and-outage-plan.md` §2.3-1 / §0-7: this
// app has NEVER called `?action=app_status` before this scaffold (`grep -rni
// captcha appApple` was 0 hits pre-scaffold) — without it, the client has no
// way to learn a CAPTCHA challenge exists until it blindly submits
// `auth_login`/`auth_email_login_request` and gets refused. Public
// (`requiresAuth: false`, matching the live handler — `app_status` answers
// before a client is signed in) and idempotent (a plain read with no
// side effects), so it goes through the SAME retrying `performIdempotentGET`
// every other catalogue read uses (`APIClient.appStatus()`,
// `AppStatusDecoding.swift`).
import Foundation

extension Endpoint {
    /// `?action=app_status` — the small bag of dormant/optional app-wide
    /// flags this package models as `IHModels.AppStatus` (today: only
    /// `captcha`). No auth required.
    public static let appStatus = Endpoint(action: "app_status")
}
