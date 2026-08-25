// APIClient+CaptchaClassification.swift
// IHAPI
//
// ELI5: Looks at a "no" response's ACTUAL WORDS (not the human sentence,
// the machine tag) to tell "you need to solve a puzzle first" apart from
// every other kind of "no."
//
// DETAILED: `.claude/captcha-native-and-outage-plan.md` §2.3-3/§0-9:
// `APIClient.classify(httpStatus:retryAfterSeconds:)` (`APIClient.swift`) is
// a PINNED, status-code-only function — it discards the response BODY
// entirely, so a 403 always falls to its `default:` branch
// (`.server(status: 403, message: nil)`) no matter WHY the server refused.
// That's correct for most 403s, but wrong for the ONE 403 kind this app
// must recognise specifically: a CAPTCHA refusal
// (`captchaGateDecision()`/`IHYMNS_CAPTCHA_REASON`, `includes/captcha.php`).
// Rather than mutate the pinned `classify` (the plan is explicit: "NOT by
// mutating the pinned `classify`"), this file adds a NEW, separate,
// body-aware sibling that `APIClient+Networking.swift`'s `performOnce`
// consults FIRST — see that file's own call site for the exact ordering.
//
// Branches on HTTP STATUS + the MACHINE `code`/`reason` field ONLY, never
// the human-readable prose (repo rule #35 — "HTTP status is the contract,
// not the error prose"; mirrors the reference web client's
// `isCaptchaRefusal(status, data)`, `js/modules/captcha-widget.js:78-80`,
// which does the identical status-plus-machine-field check).
import Foundation

extension APIClient {
    /// Decodes a 4xx body far enough to recognise a machine-coded CAPTCHA
    /// refusal. Returns `nil` for anything else — every non-403, every
    /// 403 that ISN'T a CAPTCHA refusal (a future account-disabled 403, a
    /// CSRF 403, …), and any body that fails to parse as JSON — so the
    /// caller (`performOnce`) falls back to the pinned
    /// `classify(httpStatus:retryAfterSeconds:)` completely unchanged for
    /// every one of those cases.
    ///
    /// Handles BOTH wire shapes the server can emit for the SAME refusal
    /// (`.claude/captcha-native-and-outage-plan.md` §0-8):
    ///   - The **v2 uniform envelope** — what THIS client actually
    ///     receives, since `makeURLRequest` always sends
    ///     `X-API-Version: 2` (`APIClient.swift:218`):
    ///     `{"ok":false,"error":{"code":…,"message":…,"reason":…}}`, the
    ///     `reason` key preserved inside `error` by the envelope's own
    ///     key-preservation loop (`includes/api_envelope.php:87-92`).
    ///   - The **v1 flat** shape (defensive only — this client never
    ///     actually receives it, but `unwrapEnvelope`'s own "tolerant
    ///     pass-through" philosophy for the SUCCESS path applies equally
    ///     to the error path here): `{"error":"…","reason":…,"code":…}`.
    ///
    /// Checks EITHER `code` OR `reason` equalling `captcha_required` — not
    /// `code` alone. As of this writing the server-side companion change
    /// (plan §2.1: adding `'code' => IHYMNS_CAPTCHA_REASON` to
    /// `captchaGateDecision()`'s returned array) has NOT landed — today's
    /// live refusal still carries only `reason` (`code` defaults to the
    /// generic `http_403`, `api_envelope.php:87`'s own fallback). Checking
    /// both means this classifier already works correctly against BOTH the
    /// current server AND the planned one, with no dependency ordering
    /// between this native change and that server change.
    nonisolated static func classifyMachineRefusal(httpStatus: Int, body: Data) -> APIError? {
        guard httpStatus == 403 else { return nil }
        guard
            let object = try? JSONSerialization.jsonObject(with: body, options: [.fragmentsAllowed]),
            let dict = object as? [String: Any]
        else { return nil }

        if let errorObject = dict["error"] as? [String: Any], Self.dictSignalsCaptchaRequired(errorObject) {
            return .captchaRequired
        }
        if Self.dictSignalsCaptchaRequired(dict) {
            return .captchaRequired
        }
        return nil
    }

    /// True when EITHER `dict["code"]` OR `dict["reason"]` is the string
    /// `"captcha_required"` — the ONE machine-readable value both PHP
    /// (`IHYMNS_CAPTCHA_REASON`, `includes/captcha.php:87`) and the
    /// reference web client (`CAPTCHA_REASON`,
    /// `js/modules/captcha-widget.js`) already branch on.
    nonisolated private static func dictSignalsCaptchaRequired(_ dict: [String: Any]) -> Bool {
        if let reason = dict["reason"] as? String, reason == Self.captchaRequiredValue {
            return true
        }
        if let code = dict["code"] as? String, code == Self.captchaRequiredValue {
            return true
        }
        return false
    }

    /// Mirrors `IHYMNS_CAPTCHA_REASON` (`includes/captcha.php:87`) /
    /// `CAPTCHA_REASON` (`js/modules/captcha-widget.js`) — there is no
    /// shared PHP↔Swift constant to import across that language boundary,
    /// so this is the ONE Swift-side literal of the value, named
    /// specifically so a future rename greps to exactly one hit here.
    nonisolated private static let captchaRequiredValue = "captcha_required"
}
