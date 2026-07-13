// LiveJoinSheet.swift
// IHFeatures
//
// ELI5: The "type in the code from the screen" sheet — one text field, a
// Join button, and a friendly error if the code isn't live right now.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §6.2). Strip-then-validate mirrors the web exactly (`live-follow.js:231-239`):
// uppercase, strip anything that isn't `[A-Z0-9]` (tolerating spaces/
// hyphens/NBSP/smart-punctuation), then `^[A-Z0-9]{4,12}$`
// (`AppRootViewModel.isValidJoinCode(_:)`). The actual join order (Service
// Mode first, Live Follow fallback, D-8) lives in `AppRootViewModel
// .joinWithCode(_:)` — this sheet only renders whatever that call throws.
import IHLive
import SwiftUI

struct LiveJoinSheet: View {
    let rootViewModel: AppRootViewModel
    let onJoined: () -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var codeText = ""
    @State private var errorMessage: String?
    @State private var isJoining = false

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Code", text: $codeText)
                        #if os(iOS) || os(visionOS)
                        .textInputAutocapitalization(.characters)
                        #endif
                        .autocorrectionDisabled()
                        .font(.system(.title2, design: .monospaced))
                } footer: {
                    if let errorMessage {
                        Text(errorMessage).foregroundStyle(.red)
                    } else {
                        Text("Enter the code from the venue screen or your worship leader.")
                    }
                }
            }
            .navigationTitle("Join with a Code")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Join") { Task { await join() } }
                        .disabled(codeText.trimmingCharacters(in: .whitespaces).isEmpty || isJoining)
                }
            }
        }
    }

    private func join() async {
        isJoining = true
        errorMessage = nil
        do {
            try await rootViewModel.joinWithCode(codeText)
            isJoining = false
            onJoined()
            dismiss()
        } catch LiveSyncError.temporarilyUnavailable {
            isJoining = false
            errorMessage = "iHymns is briefly unavailable — try the code again in a minute. The code is fine."
        } catch {
            isJoining = false
            errorMessage = "That code isn't active right now. Check the screen and try again."
        }
    }
}
