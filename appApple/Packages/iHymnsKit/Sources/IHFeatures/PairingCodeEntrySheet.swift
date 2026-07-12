// PairingCodeEntrySheet.swift
// IHFeatures
//
// ELI5: The "type the 6-digit code shown on the TV" screen — shows which TV
// you're pairing with, a place to type the code, and (if you typed it
// wrong) a plain-English "try again" note. If the TV gives up on you after
// too many wrong tries, it explains what to do next instead of just
// vanishing.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §4/§6.3, path B).
// Presented by `RemoteControlView` while `RemoteControlCoordinator.uiPhase`
// is `.codeEntry(tvName:failedAttempts:)`; submitting calls `coordinator
// .submitCode(_:)`, which drives `RemoteControlSession`'s pairing-flow
// reducer (`IHLive`) — this view never talks to the session/actor layer
// directly, matching this package's "screens render `AppRootViewModel`-style
// state, they don't own engines" convention (`CatalogueListView.swift`'s own
// header makes the identical point for the catalogue).
import SwiftUI

/// The 6-digit code entry sheet (spec §4 path B).
public struct PairingCodeEntrySheet: View {
    let tvName: String
    let failedAttempts: Int
    let onSubmit: (String) -> Void
    let onCancel: () -> Void

    @State private var code = ""
    @FocusState private var isCodeFieldFocused: Bool

    public init(tvName: String, failedAttempts: Int, onSubmit: @escaping (String) -> Void, onCancel: @escaping () -> Void) {
        self.tvName = tvName
        self.failedAttempts = failedAttempts
        self.onSubmit = onSubmit
        self.onCancel = onCancel
    }

    public var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text("Enter the 6-digit code shown on \(tvName).")
                } footer: {
                    if failedAttempts > 0 {
                        Text("That code didn't match. Please try again.")
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    TextField("Pairing code", text: $code)
                        .textContentType(.oneTimeCode)
                        #if os(iOS) || os(visionOS)
                        .keyboardType(.numberPad)
                        #endif
                        .focused($isCodeFieldFocused)
                        .onChange(of: code) { _, newValue in
                            let digitsOnly = newValue.filter(\.isNumber)
                            code = String(digitsOnly.prefix(6))
                        }
                }
            }
            .navigationTitle("Enter Code")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", role: .cancel, action: onCancel)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Pair") { onSubmit(code) }
                        .disabled(code.count != 6)
                }
            }
            .onAppear { isCodeFieldFocused = true }
        }
    }
}
