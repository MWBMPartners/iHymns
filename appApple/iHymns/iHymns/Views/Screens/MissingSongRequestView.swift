// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - MissingSongRequestView
/// Form to request songs that are missing from the collection.
/// Rate-limited to 1 request per minute.
struct MissingSongRequestView: View {

    @EnvironmentObject var songStore: SongStore
    @Environment(\.dismiss) private var dismiss

    @State private var songTitle: String = ""
    @State private var songbook: String = ""
    @State private var songNumber: String = ""
    @State private var additionalInfo: String = ""
    @State private var isSubmitting = false
    @State private var showingConfirmation = false
    @State private var lastSubmissionDate: Date?

    /// Pre-fill the title from a failed search.
    var prefillQuery: String?

    private var canSubmit: Bool {
        !songTitle.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty &&
        !isSubmitting &&
        !isRateLimited
    }

    private var isRateLimited: Bool {
        guard let last = lastSubmissionDate else { return false }
        return Date().timeIntervalSince(last) < 60
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Song Title *", text: $songTitle)
                    TextField("Songbook (if known)", text: $songbook)
                    TextField("Song Number (if known)", text: $songNumber)
                        .keyboardType(.numberPad)
                } header: {
                    Text("Song Details")
                } footer: {
                    Text("* Required field")
                }

                Section("Additional Information") {
                    TextEditor(text: $additionalInfo)
                        .frame(minHeight: 80)
                }

                Section {
                    Button {
                        submitRequest()
                    } label: {
                        HStack {
                            if isSubmitting {
                                ProgressView()
                                    .controlSize(.small)
                            }
                            Text(isSubmitting ? "Submitting..." : "Submit Request")
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .disabled(!canSubmit)
                } footer: {
                    if isRateLimited {
                        Text("Please wait before submitting another request.")
                            .foregroundStyle(.orange)
                    }
                }
            }
            .navigationTitle("Request a Song")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .onAppear {
                if let query = prefillQuery {
                    songTitle = query
                }
            }
            .alert("Request Submitted", isPresented: $showingConfirmation) {
                Button("OK") { dismiss() }
            } message: {
                Text("Thank you! Your request for \"\(songTitle)\" has been submitted.")
            }
        }
    }

    private func submitRequest() {
        isSubmitting = true
        // Simulate submission (in production this would call an API)
        Task {
            try? await Task.sleep(for: .seconds(1))
            await MainActor.run {
                isSubmitting = false
                lastSubmissionDate = Date()
                showingConfirmation = true
                HapticManager.success()
            }
        }
    }
}
