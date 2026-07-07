// AccountView.swift
// IHFeatures
//
// ELI5: The "who am I, and how do I sign out" screen. Signed in → shows
// your name/username/role and a Sign Out button. Signed out → offers a
// Sign In button that opens `LoginView`.
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
import IHAPI
import IHAuth
import IHModels
import SwiftUI

/// The account surface: signed-in identity + sign out, or a sign-in prompt.
public struct AccountView: View {
    private let rootViewModel: AppRootViewModel

    @State private var isPresentingLogin = false
    @State private var isSigningOut = false
    @State private var errorMessage: String?

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle("Account")
            .task { await rootViewModel.refreshCurrentUser() }
            .sheet(isPresented: $isPresentingLogin) {
                LoginView(rootViewModel: rootViewModel)
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
                .disabled(isSigningOut)
            }
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
        } actions: {
            Button("Sign In") { isPresentingLogin = true }
                .buttonStyle(.borderedProminent)
        }
    }

    private func signOut() async {
        errorMessage = nil
        isSigningOut = true
        defer { isSigningOut = false }
        do {
            try await rootViewModel.signOut()
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
        } catch {
            errorMessage = "Something went wrong signing out. Please try again."
        }
    }
}
