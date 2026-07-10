// AccountDeleteView.swift
// IHFeatures
//
// ELI5: The "permanently delete my account" screen — a clear warning, then
// asks you to prove it's really you (your password, or a one-time code
// emailed to you) before deleting anything. Only once that's confirmed does
// it actually delete the account.
//
// DETAILED: In-app account deletion task (#1477, App Review §5.1.1(v)).
// Deliberately REUSES `LoginView`'s two re-auth affordances rather than
// inventing a third UI pattern for what is, mechanically, the same "prove
// who you are" interaction:
//   - A segmented `Picker` choosing the re-auth method — mirrors
//     `LoginView`'s `LoginMode` picker exactly.
//   - The "email code" method is the SAME two-step flow `LoginView
//     .emailCodeFields` establishes: enter an email, `AppRootViewModel
//     .requestEmailLoginCode(email:)` sends a 6-digit code (this is the
//     unauthenticated `?action=auth_email_login_request` call, unchanged
//     from `LoginView`'s own use of it), then enter that code. The email
//     the user types here MUST be their account's own registered address —
//     `api.php`'s `case 'account_delete'` re-auth rung validates the
//     submitted code against `tblUsers.Email` for the SIGNED-IN account, not
//     whatever address the request happened to be sent to. This is the same
//     trust assumption `LoginView`'s existing "sign in with email code" flow
//     already makes (the user must know their own account email); `AuthUser`
//     (the `auth_me` response `IHModels/AuthSession.swift` models) carries no
//     `email` field for this screen to pre-fill from, so asking is the only
//     option without a backend contract change, which is out of scope here.
//
// Presented as a sheet from `AccountView`'s "Danger Zone" section. Every
// failure renders via `APIError.accountDeleteUserFacingMessage`
// (`APIError+UserFacing.swift`) — DISTINCT from `LoginView`'s
// `userFacingMessage` because 401/429/409 need account-deletion-specific
// wording (see that property's own doc comment).
import IHAPI
import SwiftUI

/// Which credential the user is proving their identity with — mirrors
/// `LoginView`'s private `LoginMode`.
private enum DeleteAccountReauthMode: String, CaseIterable, Identifiable {
    case password = "Password"
    case emailCode = "Email Code"
    var id: String { rawValue }
}

/// Which step of the email-code re-auth flow is showing — mirrors
/// `LoginView`'s private `EmailCodeStep`.
private enum DeleteAccountEmailCodeStep {
    case requestCode
    case enterCode(email: String)
}

/// Which screen this view is currently showing — the warning + re-auth
/// form, or the post-deletion confirmation.
private enum DeleteAccountStep {
    case confirm
    case succeeded
}

/// The account-deletion screen: an irreversible-action warning, a
/// re-authentication step, and (on success) a confirmation that the account
/// is gone.
public struct AccountDeleteView: View {
    private let rootViewModel: AppRootViewModel

    @Environment(\.dismiss) private var dismiss

    @State private var step: DeleteAccountStep = .confirm
    @State private var reauthMode: DeleteAccountReauthMode = .password

    // Password mode field.
    @State private var password = ""

    // Email-code mode fields/step.
    @State private var emailCodeStep: DeleteAccountEmailCodeStep = .requestCode
    @State private var email = ""
    @State private var code = ""
    @State private var emailRequestConfirmation: String?

    @State private var isSubmitting = false
    @State private var errorMessage: String?

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        NavigationStack {
            Group {
                switch step {
                case .confirm:
                    confirmForm
                case .succeeded:
                    succeededView
                }
            }
            .navigationTitle("Delete Account")
            .toolbar {
                if case .confirm = step {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { dismiss() }
                    }
                }
            }
            .disabled(isSubmitting)
        }
        // Prevents an accidental swipe-to-dismiss mid-submission from
        // leaving the caller uncertain whether the delete actually went
        // through — mirrors the `.disabled(isSubmitting)` guard on every
        // button below.
        .interactiveDismissDisabled(isSubmitting)
    }

    // MARK: - Confirm + re-auth

    @ViewBuilder
    private var confirmForm: some View {
        Form {
            Section {
                Label("This action is permanent and cannot be undone.", systemImage: "exclamationmark.triangle.fill")
                    .foregroundStyle(.red)
                Text("Deleting your account permanently removes your profile, favourites, setlists, and every other piece of data associated with it.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }

            Section {
                Picker("Re-authenticate with", selection: $reauthMode) {
                    ForEach(DeleteAccountReauthMode.allCases) { option in
                        Text(option.rawValue).tag(option)
                    }
                }
                .pickerStyle(.segmented)
                .onChange(of: reauthMode) { _, _ in errorMessage = nil }
            }

            switch reauthMode {
            case .password:
                passwordFields
            case .emailCode:
                emailCodeFields
            }

            if let errorMessage {
                Section {
                    Text(errorMessage)
                        .foregroundStyle(.red)
                }
            }
        }
    }

    // MARK: - Password mode

    @ViewBuilder
    private var passwordFields: some View {
        Section {
            SecureField("Current password", text: $password)
                .onSubmit { Task { await deleteWithPassword() } }
        }
        Section {
            Button(role: .destructive) {
                Task { await deleteWithPassword() }
            } label: {
                submitLabel("Permanently Delete Account")
            }
            .disabled(password.isEmpty)
        }
    }

    private func deleteWithPassword() async {
        await performDelete(reauth: .password(password))
    }

    // MARK: - Email-code mode

    @ViewBuilder
    private var emailCodeFields: some View {
        switch emailCodeStep {
        case .requestCode:
            Section {
                TextField("Email address", text: $email)
                    #if os(iOS) || os(visionOS)
                    .textInputAutocapitalization(.never)
                    .keyboardType(.emailAddress)
                    #endif
                    .autocorrectionDisabled()
            }
            Section {
                Button {
                    Task { await requestEmailCode() }
                } label: {
                    submitLabel("Send Code")
                }
                .disabled(email.trimmingCharacters(in: .whitespaces).isEmpty)
            }

        case .enterCode(let sentTo):
            Section {
                if let emailRequestConfirmation {
                    Text(emailRequestConfirmation)
                        .foregroundStyle(.secondary)
                }
                Text("Enter the 6-digit code sent to \(sentTo).")
                TextField("6-digit code", text: $code)
                    #if os(iOS) || os(visionOS)
                    .keyboardType(.numberPad)
                    #endif
                    .onSubmit { Task { await deleteWithEmailCode() } }
            }
            Section {
                Button(role: .destructive) {
                    Task { await deleteWithEmailCode() }
                } label: {
                    submitLabel("Permanently Delete Account")
                }
                .disabled(code.trimmingCharacters(in: .whitespaces).count != 6)

                Button("Use a Different Email") {
                    emailCodeStep = .requestCode
                    code = ""
                    errorMessage = nil
                    emailRequestConfirmation = nil
                }
            }
        }
    }

    private func requestEmailCode() async {
        errorMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }
        do {
            let message = try await rootViewModel.requestEmailLoginCode(email: email)
            emailRequestConfirmation = message
            emailCodeStep = .enterCode(email: email)
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
        } catch {
            errorMessage = "Something went wrong requesting a code. Please try again."
        }
    }

    private func deleteWithEmailCode() async {
        await performDelete(reauth: .emailCode(code))
    }

    // MARK: - Shared submit

    /// Runs `rootViewModel.deleteAccount(reauth:)` and, on success, advances
    /// to `.succeeded`. A failure leaves `step` at `.confirm` with
    /// `errorMessage` set — `AppRootViewModel.deleteAccount(reauth:)`'s own
    /// doc comment guarantees every failure case leaves local session state
    /// untouched, so simply staying on this screen and letting the user
    /// retry (a different credential, or the same one again) is always
    /// correct.
    private func performDelete(reauth: AccountDeleteReauth) async {
        errorMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }
        do {
            try await rootViewModel.deleteAccount(reauth: reauth)
            step = .succeeded
        } catch let error as APIError {
            errorMessage = error.accountDeleteUserFacingMessage
        } catch {
            errorMessage = "Something went wrong deleting your account. Please try again."
        }
    }

    // MARK: - Succeeded

    private var succeededView: some View {
        ContentUnavailableView {
            Label("Account Deleted", systemImage: "checkmark.circle.fill")
        } description: {
            Text("Your account has been deleted.")
        } actions: {
            Button("Done") { dismiss() }
                .buttonStyle(.borderedProminent)
        }
    }

    // MARK: - Shared

    @ViewBuilder
    private func submitLabel(_ title: String) -> some View {
        HStack {
            Spacer()
            if isSubmitting {
                ProgressView()
            } else {
                Text(title)
            }
            Spacer()
        }
    }
}
