// AccountView.swift
// IHFeatures
//
// ELI5: The "who am I, and how do I sign out (or delete my account)" screen.
// Signed in → shows your name/username/role, a Sign Out button, and — in
// its own clearly-separated "Danger Zone" — a Delete Account button. Signed
// out → offers a Sign In button that opens `LoginView`.
//
// DETAILED: Native login/account UI task's "An Account surface... show
// signed-in identity + a Sign Out button... Signed-out state offers Sign
// In." Reads `AppRootViewModel.sessionState`/`.currentUser` directly (the
// SAME "root view model exposes the state, the view just renders it"
// pattern every other screen in this package follows) rather than owning
// any network call of its own — `AppRootViewModel.refreshCurrentUser()` is
// what actually fetches `?action=auth_me`, called from this view's `.task`
// (so returning to this screen always shows a fresh identity) and from
// `AppRootViewModel+Auth.swift`'s `syncAfterSignIn()` (so it's already
// populated the moment a sign-in completes, before the user has even
// navigated here).
//
// #1477 UPDATE — in-app account deletion (App Review §5.1.1(v)) adds the
// "Danger Zone" section below: a SEPARATE `Section` from "Sign Out" (a
// destructive, IRREVERSIBLE action must never sit visually alongside a
// harmless one) that presents `AccountDeleteView` — its own re-auth-gated
// confirmation flow — as a sheet, exactly mirroring how `isPresentingLogin`
// already presents `LoginView` below.
//
// PR 3b (I2d, #2180) UPDATE — one sign-in per device. When the site has it
// switched on, a "Your devices" section adds "Sign out everywhere" (always
// asked about first, because it signs out every Apple device the person owns)
// and a switch "Sign in automatically on my other Apple devices" (off cancels
// every automatic sign-in key; on makes one). The section is drawn only from
// what `AppRootViewModel.deviceSignInStatus()` says, never assumed. A failed
// sign-out-everywhere says plainly that the OTHER devices were not signed out
// and leaves this one signed in, mirroring the web Devices card.
//
// PR 3b (I5, #2180) UPDATE — when the site has the new account sync on, signing
// out (either kind) first sends anything still waiting; if some changes could
// not be sent, the view model throws `SignOutBlockedByUnsentChanges` and this
// view ASKS: "Try again" (up to three times), "Sign out anyway" (an explicit
// `discardUnsent: true`), or Cancel, which stays signed in. The view cannot
// skip the question by accident: only that explicit flag does.
import IHAPI
import IHAuth
import IHModels
import SwiftUI

/// The account surface: signed-in identity + sign out + delete account, or
/// a sign-in prompt.
public struct AccountView: View {
    private let rootViewModel: AppRootViewModel

    @State private var isPresentingLogin = false
    @State private var isPresentingDeleteAccount = false
    @State private var isSigningOut = false
    @State private var errorMessage: String?
    @State private var deviceStatus: DeviceSignInStatus?
    @State private var isConfirmingSignOutEverywhere = false
    @State private var isSigningOutEverywhere = false
    @State private var isChangingAutomaticSignIn = false
    @State private var unsentPrompt: UnsentPrompt?
    @State private var unsentRetries = 0

    /// What the "some changes have not reached your account" question is about.
    private struct UnsentPrompt: Identifiable {
        let id = UUID()
        let everywhere: Bool
        let unsent: UnsentChanges
    }

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    /// Any of the three account actions is running: the others wait, so a sign-out and an
    /// automatic-sign-in change can never run at the same moment.
    private var isBusy: Bool { isSigningOut || isSigningOutEverywhere || isChangingAutomaticSignIn }

    public var body: some View {
        content
            .navigationTitle("Account")
            // `id:` re-runs this whenever signed-in-ness flips, so the device controls never keep
            // the status from before a sign-in made from the sheet (or a sign-out).
            .task(id: rootViewModel.sessionState.isSignedIn) {
                await rootViewModel.refreshCurrentUser()
                deviceStatus = await rootViewModel.deviceSignInStatus()
            }
            .sheet(isPresented: $isPresentingLogin) {
                LoginView(rootViewModel: rootViewModel)
            }
            .sheet(isPresented: $isPresentingDeleteAccount) {
                AccountDeleteView(rootViewModel: rootViewModel)
            }
    }

    @ViewBuilder
    private var content: some View {
        if rootViewModel.sessionState.isSignedIn {
            signedInContent
        } else {
            signedOutContent
        }
    }

    private var signedInContent: some View {
        Form {
            Section {
                identityRow
            }

            if let errorMessage {
                Section {
                    Text(errorMessage)
                        .foregroundStyle(.red)
                }
            }

            Section {
                Button(role: .destructive) {
                    Task { await signOut() }
                } label: {
                    if isSigningOut {
                        ProgressView()
                    } else {
                        Text("Sign Out")
                    }
                }
                .disabled(isBusy)
            }

            if deviceStatus?.switchedOn == true {
                devicesSection
            }

            // Deliberately its OWN `Section`, below (never beside) "Sign
            // Out" — an irreversible, whole-account deletion must read as
            // categorically more serious than a harmless sign-out.
            Section {
                Text("Deleting your account permanently removes your profile, favourites, setlists, and all associated data. This cannot be undone.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                Button(role: .destructive) {
                    isPresentingDeleteAccount = true
                } label: {
                    Text("Delete Account")
                }
            } header: {
                Text("Danger Zone")
            }
        }
        .confirmationDialog(
            "Some changes have not reached your account",
            isPresented: Binding(get: { unsentPrompt != nil }, set: { if !$0 { unsentPrompt = nil } }),
            titleVisibility: .visible,
            presenting: unsentPrompt
        ) { prompt in
            if unsentRetries < 3 {
                Button("Try again") {
                    unsentRetries += 1
                    Task { await signOutAgain(prompt, discardUnsent: false) }
                }
            }
            Button("Sign Out Anyway", role: .destructive) {
                Task { await signOutAgain(prompt, discardUnsent: true) }
            }
            Button("Cancel", role: .cancel) { unsentRetries = 0 }
        } message: { prompt in
            Text(unsentMessage(prompt.unsent))
        }
    }

    /// The "Your devices" section (PR 3b, I2d): drawn only when the site has
    /// one sign-in per device switched on.
    private var devicesSection: some View {
        Section {
            if deviceStatus?.canOfferAutomaticSignIn == true {
                Toggle(
                    "Sign in automatically on my other Apple devices",
                    isOn: Binding(
                        get: { deviceStatus?.keyHeld == true && deviceStatus?.automaticSignInOff == false },
                        set: { newValue in Task { await setAutomaticSignIn(newValue) } }
                    )
                )
                .disabled(isBusy)
            }
            Button(role: .destructive) {
                // Never signs out directly: it signs out EVERY Apple device, so it is asked about first.
                isConfirmingSignOutEverywhere = true
            } label: {
                if isSigningOutEverywhere {
                    ProgressView()
                } else {
                    Text("Sign Out Everywhere")
                }
            }
            .disabled(isBusy)
            .confirmationDialog(
                "Sign out everywhere?",
                isPresented: $isConfirmingSignOutEverywhere,
                titleVisibility: .visible
            ) {
                Button("Sign Out Everywhere", role: .destructive) {
                    Task { await signOutEverywhere() }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("This signs you out on every device, including this one. You will need to sign in again on each.")
            }
        } header: {
            Text("Your devices")
        } footer: {
            Text("Each device has its own sign-in. Automatic sign-in lets a new Apple device on your iCloud account sign in without asking. Turn it off to stop that.")
        }
    }

    @ViewBuilder
    private var identityRow: some View {
        if let user = rootViewModel.currentUser {
            VStack(alignment: .leading, spacing: 4) {
                Text(user.displayName)
                    .font(.headline)
                Text("@\(user.username)")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Text(user.role.capitalized)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .accessibilityElement(children: .combine)
        } else {
            // `currentUser` hasn't resolved yet (`.task` above is still in
            // flight, or the last `authMe()` attempt failed and left it
            // `nil` — `refreshCurrentUser()`'s own doc comment: it only
            // ever leaves a PREVIOUSLY-known identity alone on failure, so
            // a genuinely-`nil` value here means this is the FIRST load).
            // A neutral placeholder row rather than a scary error card —
            // being signed in with a not-yet-fetched profile is a normal,
            // brief transient state, not a failure worth alarming over.
            HStack {
                ProgressView()
                Text("Loading your profile…")
                    .foregroundStyle(.secondary)
            }
        }
    }

    private var signedOutContent: some View {
        ContentUnavailableView {
            Label("Not Signed In", systemImage: "person.crop.circle")
        } description: {
            Text("Sign in to sync your favourites across devices.")
            // Shown here too: after a sign-out the screen is this one, and a sign-out message must not vanish.
            if let errorMessage {
                Text(errorMessage)
                    .foregroundStyle(.red)
            }
        } actions: {
            Button("Sign In") {
                errorMessage = nil
                isPresentingLogin = true
            }
                .buttonStyle(.borderedProminent)
        }
    }

    /// Signs every device out. A failure leaves THIS device signed in and says
    /// so: the other devices were not signed out, and the person can retry. Unsent
    /// changes (account sync on) are asked about first, see this file's I5 note.
    private func signOutEverywhere(discardUnsent: Bool = false) async {
        errorMessage = nil
        isSigningOutEverywhere = true
        defer { isSigningOutEverywhere = false }
        do {
            try await rootViewModel.signOutEverywhere(discardUnsent: discardUnsent)
        } catch let blocked as SignOutBlockedByUnsentChanges {
            unsentPrompt = UnsentPrompt(everywhere: true, unsent: blocked.unsent)
        } catch let error as SessionControllerError {
            errorMessage = Self.message(for: error, everywhere: true)
        } catch {
            errorMessage = "Your other devices were not signed out. Check your connection and try again."
        }
    }

    /// Flips automatic sign-in, then re-reads the status so the switch shows
    /// what is really true (a failed change leaves it where it was).
    private func setAutomaticSignIn(_ enabled: Bool) async {
        errorMessage = nil
        isChangingAutomaticSignIn = true
        defer { isChangingAutomaticSignIn = false }
        do {
            try await rootViewModel.setAutomaticSignIn(enabled: enabled)
        } catch {
            errorMessage = "Could not change automatic sign-in. Check your connection and try again."
        }
        deviceStatus = await rootViewModel.deviceSignInStatus()
    }

    private func signOut(discardUnsent: Bool = false) async {
        errorMessage = nil
        isSigningOut = true
        defer { isSigningOut = false }
        do {
            try await rootViewModel.signOut(discardUnsent: discardUnsent)
        } catch let blocked as SignOutBlockedByUnsentChanges {
            unsentPrompt = UnsentPrompt(everywhere: false, unsent: blocked.unsent)
        } catch let error as SessionControllerError {
            errorMessage = Self.message(for: error, everywhere: false)
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
        } catch {
            errorMessage = "Something went wrong signing out. Please try again."
        }
    }

    /// The person answered the unsent-changes question: try again, or sign out anyway.
    private func signOutAgain(_ prompt: UnsentPrompt, discardUnsent: Bool) async {
        if prompt.everywhere {
            await signOutEverywhere(discardUnsent: discardUnsent)
        } else {
            await signOut(discardUnsent: discardUnsent)
        }
    }

    /// What to tell the person for each honest sign-out outcome. Chosen from the error CASE, never from a sentence.
    private static func message(for error: SessionControllerError, everywhere: Bool) -> String {
        switch error {
        case .couldNotRecordSignOut:
            everywhere
                ? "Your sign-ins were ended, but this device could not save that. Tap Sign Out Everywhere again to finish."
                : "This device could not save your sign-out, so you are still signed in here. Please try again."
        case .signedOutButTokenNotRemoved:
            everywhere
                ? "You were signed out everywhere. This device could not clear its saved sign-in, but it will not be used."
                : "You were signed out. This device could not clear its saved sign-in, but it will not be used."
        }
    }

    /// The words for the "some changes have not reached your account" question.
    private func unsentMessage(_ unsent: UnsentChanges) -> String {
        if unsent.couldNotCount && unsent.total == 0 {
            return "We could not check whether some changes are still waiting to be sent. Signing out now could lose them on this device."
        }
        let tail = unsent.couldNotCount ? " We could not check for others." : ""
        return "\(unsent.total) change(s) could not be sent. Signing out now would lose them on this device.\(tail)"
    }
}
