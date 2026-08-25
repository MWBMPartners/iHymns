// LoginView.swift
// IHFeatures
//
// ELI5: The "sign in" screen — pick username+password OR "email me a code,"
// fill it in, and it logs you in. If something goes wrong (wrong password,
// no internet, too many tries), it tells you what, in plain language.
//
// DETAILED: Native login/account UI task (completing #1398's noted gap:
// `SessionController.signIn`/`signOut` existed with zero UI calling them).
// Two independent sign-in modes, both fully wired end-to-end:
//   - **Password** (`AppRootViewModel.signIn(username:password:)`) —
//     `?action=auth_login`.
//   - **Email code** (`AppRootViewModel.requestEmailLoginCode(email:)` then
//     `.signIn(email:code:)`) — `?action=auth_email_login_request` then
//     `?action=auth_email_login_verify`. This is the ONLY email-login path
//     this view wires up; the magic-link-TAP path (pasting/consuming a
//     `https://ihymns.app/login?token=…` Universal Link) is deliberately
//     NOT built here — see `AuthEndpoints.swift`'s
//     `Endpoint.authEmailLoginVerify(token:)` doc comment for why that's a
//     genuinely separate, not-yet-existing deep-link-handling task, not
//     something worth faking with a "paste your token" text field.
//
// Every failure renders via `APIError.userFacingMessage`
// (`APIError+UserFacing.swift`) — the SAME mapping `AppRootViewModel
// .loadCatalogue()`/`SongDetailViewModel.load()` already use, so "you're
// offline"/"too many attempts"/"iHymns is temporarily unavailable" read
// identically everywhere in this app, never a raw error dump.
//
// #947/#340 native scaffold UPDATE (`.claude/captcha-native-and-outage-plan.md`
// §2.4/§2.5) — this is the ONE screen either gated form
// (`CaptchaConfig.loginFormKey`/`.emailLoginFormKey`) can be submitted from,
// so it is the ONE place `captchaSection(form:)` embeds a challenge (when
// `rootViewModel.captchaConfig` says the form is gated AND a conformance is
// registered for the configured provider) and attaches the solved token to
// the NEXT submit. `captchaToken` is per-SUBMIT state, never cached across
// attempts: `resetCaptcha()` clears it AND tells the live provider instance
// to invalidate its own solved state, called from EVERY failure branch
// below (a `.captchaRequired` refusal, a wrong password/code, or any
// transient error) — tokens are single-use (consumed at the provider's
// first `siteverify` regardless of whether the request went on to succeed),
// so a retry must always carry a FRESH one (plan §2.5).
import IHAPI
import IHModels
import SwiftUI

/// Two ways to sign in — mirrors the segmented control this view presents.
private enum LoginMode: String, CaseIterable, Identifiable {
    case password = "Password"
    case emailCode = "Email Code"
    var id: String { rawValue }
}

/// Which step of the email-code flow is showing — step 1 (enter an email,
/// request a code) or step 2 (enter the code that arrived).
private enum EmailCodeStep {
    case requestCode
    case enterCode(email: String)
}

/// The sign-in screen — presented modally (`AccountView`'s `.sheet`) so
/// dismissing it (via `@Environment(\.dismiss)`, fired automatically on a
/// successful sign-in) always returns to wherever the user triggered it
/// from.
public struct LoginView: View {
    private let rootViewModel: AppRootViewModel

    @Environment(\.dismiss) private var dismiss

    @State private var mode: LoginMode = .password

    // Password mode fields.
    @State private var username = ""
    @State private var password = ""

    // Email-code mode fields/step.
    @State private var emailCodeStep: EmailCodeStep = .requestCode
    @State private var email = ""
    @State private var code = ""
    @State private var emailRequestConfirmation: String?

    @State private var isSubmitting = false
    @State private var errorMessage: String?

    /// The most recently solved CAPTCHA token, if the active form is gated
    /// — attached to the NEXT submit, then cleared (see this file's header
    /// "per-submit token acquisition" contract). `nil` on a dormant/
    /// ungated install (the challenge section never renders, so this never
    /// gets set) and while a fresh challenge hasn't been solved yet.
    @State private var captchaToken: String?

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        NavigationStack {
            Form {
                Section {
                    Picker("Sign-in method", selection: $mode) {
                        ForEach(LoginMode.allCases) { option in
                            Text(option.rawValue).tag(option)
                        }
                    }
                    // `.segmented` is UNAVAILABLE on watchOS (#1549) — this
                    // screen is never shown on the watch (`iHymnsWatch` links
                    // the whole IHFeatures target, so it must still COMPILE
                    // there); `.automatic` is a compile-only fallback,
                    // non-watch keeps `.segmented` unchanged.
                    #if os(watchOS)
                    .pickerStyle(.automatic)
                    #else
                    .pickerStyle(.segmented)
                    #endif
                    .onChange(of: mode) { _, _ in errorMessage = nil }
                }

                switch mode {
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
            .navigationTitle("Sign In")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .disabled(isSubmitting)
        }
    }

    // MARK: - Password mode

    @ViewBuilder
    private var passwordFields: some View {
        Section {
            TextField("Username", text: $username)
                #if os(iOS) || os(visionOS)
                .textInputAutocapitalization(.never)
                #endif
                .autocorrectionDisabled()
            SecureField("Password", text: $password)
                .onSubmit { Task { await signInWithPassword() } }
        }
        captchaSection(form: CaptchaConfig.loginFormKey)
        Section {
            Button {
                Task { await signInWithPassword() }
            } label: {
                submitLabel("Sign In")
            }
            .disabled(username.trimmingCharacters(in: .whitespaces).isEmpty || password.isEmpty)
        }
    }

    private func signInWithPassword() async {
        errorMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }
        do {
            try await rootViewModel.signIn(username: username, password: password, captchaToken: captchaToken)
            dismiss()
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
            resetCaptcha()
        } catch {
            errorMessage = "Something went wrong signing in. Please try again."
            resetCaptcha()
        }
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
            captchaSection(form: CaptchaConfig.emailLoginFormKey)
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
                    .onSubmit { Task { await verifyEmailCode(email: sentTo) } }
            }
            Section {
                Button {
                    Task { await verifyEmailCode(email: sentTo) }
                } label: {
                    submitLabel("Verify & Sign In")
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
            let message = try await rootViewModel.requestEmailLoginCode(email: email, captchaToken: captchaToken)
            emailRequestConfirmation = message
            emailCodeStep = .enterCode(email: email)
            // The token (if any) was just spent at the provider's
            // `siteverify` — never carry it forward into the (ungated)
            // verify step or a later "use a different email" retry.
            captchaToken = nil
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
            resetCaptcha()
        } catch {
            errorMessage = "Something went wrong requesting a code. Please try again."
            resetCaptcha()
        }
    }

    private func verifyEmailCode(email: String) async {
        errorMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }
        do {
            try await rootViewModel.signIn(email: email, code: code)
            dismiss()
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
        } catch {
            errorMessage = "Something went wrong verifying your code. Please try again."
        }
    }

    // MARK: - CAPTCHA (#947/#340 native scaffold — see this file's header)

    /// Embeds a challenge for `form` when (a) `rootViewModel.captchaConfig`
    /// says `form` is gated, AND (b) something registered a conformance for
    /// the configured provider (`CaptchaChallengeRegistry.provider(for:)`).
    /// Renders NOTHING in every other case — including "gated, but no
    /// conformance resolves" (an unsupported platform, or a genuinely
    /// unknown future provider string this app has no conformance for) —
    /// per this file's header: the submit then simply proceeds token-less,
    /// and the server's own branchable 403 drives the message, never a
    /// client-side hard block (the server may have its own outage-fallback
    /// grace window open, `.claude/captcha-native-and-outage-plan.md` Part
    /// 2, which this client must never pre-empt).
    ///
    /// A provider that resolves but can't render on THIS platform (tvOS/
    /// watchOS, D-N2) still gets a `Section` — its own `makeChallengeView`
    /// degrades to a clear, worded "not available" message there rather
    /// than nothing at all, so the user isn't left guessing why sign-in
    /// keeps failing.
    @ViewBuilder
    private func captchaSection(form: String) -> some View {
        if let config = rootViewModel.captchaConfig, config.isRequired(for: form),
           let provider = CaptchaChallengeRegistry.provider(for: config.provider) {
            Section {
                provider.makeChallengeView(config: config) { token in
                    captchaToken = token
                }
                .frame(minHeight: 70)
            }
        }
    }

    /// Clears the captured token AND tells the live provider instance to
    /// invalidate its own solved state — called from EVERY failed-submit
    /// branch (a `.captchaRequired` refusal, a wrong password/code, or any
    /// transient failure), per this file's header "tokens are single-use"
    /// contract. A no-op when no provider is configured/resolved — safe to
    /// call unconditionally from every `catch` block regardless of whether
    /// a challenge was ever actually shown.
    private func resetCaptcha() {
        captchaToken = nil
        guard let config = rootViewModel.captchaConfig else { return }
        CaptchaChallengeRegistry.provider(for: config.provider)?.reset()
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
