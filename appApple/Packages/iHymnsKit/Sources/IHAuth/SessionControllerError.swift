// SessionControllerError.swift
// IHAuth
//
// ELI5: The two honest things a sign-out can say besides "it worked" or "the
// network failed": "I could not write down that you signed out, so I did
// nothing", and "you ARE signed out, but I could not clear the saved copy".
//
// DETAILED: PR 3b review fixes (#2180). `SessionController.signOut()` and
// `signOutEverywhere()` must not forget "signed out here" (design rule 5: a
// device with no token and no mark signs itself straight back in from the
// shared sign-in or the automatic sign-in key), so they write that mark FIRST
// and refuse to go on if it cannot be saved. Callers choose their words from
// the CASE, never from an error sentence (rule #35).
import Foundation

public enum SessionControllerError: Error, Sendable, Equatable {
    /// "Signed out here" could not be saved, so nothing local was changed and
    /// this device is still signed in. (For a sign-out that already reached the
    /// server, trying again finishes the job: the server answers 401 and that
    /// counts as already done.)
    case couldNotRecordSignOut

    /// The server ended the sign-in and this device IS signed out (the mark is
    /// saved and the app shows signed out), but the saved copy of the token
    /// could not be removed from the Keychain. It is dead and will not be used.
    case signedOutButTokenNotRemoved
}
