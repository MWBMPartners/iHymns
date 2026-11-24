// DeviceSignInReason.swift
// IHAuth
//
// ELI5: The few machine "reason words" the server gives when it refuses a
// device sign-in call, and that the app acts on, written down once.
//
// DETAILED: PR 3b step I2c (#2180). The server answers every refusal with an
// HTTP status plus a short `reason` word (`includes/device_signin.php`), and
// the contract is the status AND that word, never the sentence (rule #35). The
// app only branches on the ones named here. Keeping them as one enum, rather
// than string literals scattered through the controller, is what lets
// `tests/test-apple-device-signin-reasons.js` check from the source that every
// word the app relies on is a word the server really sends (a renamed server
// word would otherwise turn a safe "stay signed out" into a silent
// "sign in anyway"). The raw values must match the server's, character for
// character.
import Foundation

/// A machine reason word the app acts on.
///
/// ELI5: Why the server said no, in a word the app understands.
public enum DeviceSignInReason: String, Sendable {
    /// 403 on `auth_device_enrol`: this install's sign-in ended earlier
    /// (signed out, or 30 days unused), so it must be signed in by hand.
    case installSignedOut = "install_signed_out"
}
