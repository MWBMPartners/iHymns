// SongRequestView.swift
// IHFeatures
//
// ELI5: The "we couldn't find your song — tell us about it" form. Fill in
// the title (and whatever else you know: hymnal, number, language, notes,
// an email if you'd like us to follow up), tap Submit, and a curator sees
// it.
//
// DETAILED: #1447 (#185's deferred "missing-song request form" sub-scope).
// Submits to `?action=song_request` — an ALREADY-SHIPPED, working endpoint
// (#280) this task's backend survey discovered (verified against the LIVE
// `api.php` handler, not guessed); no backend change was needed, only this
// native form + the `IHAPI`/`AppRootViewModel` wiring alongside it. Follows
// `LoginView`'s exact "plain `Form` + `@State` fields + `isSubmitting`/
// `errorMessage` + `rootViewModel` pass-through, no separate ViewModel"
// shape — a one-shot submission has no ongoing `LoadState` to track the way
// a fetch-and-display screen does, so a dedicated `@Observable` view model
// would be pure ceremony here.
//
// Presented as a `.sheet` from `CatalogueListView`'s empty-search-results
// state (this task's other required wiring point, per #185's original
// "linked from search no-results" ask) — `title` is pre-filled from the
// search text the user already typed, so "search for a song, get nothing,
// tap Request" carries that text forward instead of making the user retype
// it.
import IHAPI
import IHDesign
import SwiftUI

/// The missing-song request form.
public struct SongRequestView: View {
    private let rootViewModel: AppRootViewModel

    @Environment(\.dismiss) private var dismiss

    @State private var title: String
    @State private var songbook = ""
    @State private var songNumber = ""
    @State private var details = ""
    @State private var contactEmail = ""

    @State private var isSubmitting = false
    @State private var errorMessage: String?

    /// `nil` while the form is still being filled in; set the moment a
    /// submission succeeds, which swaps the whole form for a confirmation
    /// view carrying the server's tracking id.
    @State private var submittedRequestId: Int?

    /// - Parameter prefillTitle: The search text the user already typed
    ///   (`CatalogueListView`'s empty-results state), if any — carried
    ///   forward into the title field so the user never retypes it.
    public init(rootViewModel: AppRootViewModel, prefillTitle: String = "") {
        self.rootViewModel = rootViewModel
        _title = State(initialValue: prefillTitle)
    }

    public var body: some View {
        NavigationStack {
            Group {
                if let submittedRequestId {
                    confirmation(requestId: submittedRequestId)
                } else {
                    form
                }
            }
            .navigationTitle("Request a Song")
            #if os(iOS) || os(visionOS)
            .navigationBarTitleDisplayMode(.inline)
            #endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(submittedRequestId == nil ? "Cancel" : "Done") { dismiss() }
                }
            }
            .disabled(isSubmitting)
        }
    }

    private var form: some View {
        Form {
            Section {
                Text("Can't find a song in the catalogue? Tell us what you know and a curator will take a look.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }

            Section("Song") {
                TextField("Title (required)", text: $title)
                TextField("Hymnal / songbook, if known", text: $songbook)
                TextField("Number, if known", text: $songNumber)
                    #if os(iOS) || os(visionOS)
                    .keyboardType(.numberPad)
                    #endif
            }

            Section("Details") {
                TextField("First line, tune name, anything that helps us find it", text: $details, axis: .vertical)
                    .lineLimit(3...6)
            }

            Section("Contact (optional)") {
                TextField("Email — if you'd like us to follow up", text: $contactEmail)
                    #if os(iOS) || os(visionOS)
                    .textInputAutocapitalization(.never)
                    .keyboardType(.emailAddress)
                    #endif
                    .autocorrectionDisabled()
            }

            if let errorMessage {
                Section {
                    Text(errorMessage)
                        .foregroundStyle(.red)
                }
            }

            Section {
                Button {
                    Task { await submit() }
                } label: {
                    submitLabel
                }
                .disabled(title.trimmingCharacters(in: .whitespaces).isEmpty)
            }
        }
    }

    private func confirmation(requestId: Int) -> some View {
        ContentUnavailableView {
            Label("Request Sent", systemImage: "checkmark.circle.fill")
        } description: {
            Text("Thanks! A curator will take a look. Your request's tracking number is #\(requestId).")
        }
    }

    @ViewBuilder
    private var submitLabel: some View {
        HStack {
            Spacer()
            if isSubmitting {
                ProgressView()
            } else {
                Text("Submit")
            }
            Spacer()
        }
    }

    private func submit() async {
        errorMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }
        do {
            let result = try await rootViewModel.submitSongRequest(
                title: title.trimmingCharacters(in: .whitespacesAndNewlines),
                songbook: songbook.trimmingCharacters(in: .whitespacesAndNewlines),
                songNumber: songNumber.trimmingCharacters(in: .whitespacesAndNewlines),
                details: details.trimmingCharacters(in: .whitespacesAndNewlines),
                contactEmail: contactEmail.trimmingCharacters(in: .whitespacesAndNewlines)
            )
            submittedRequestId = result.id
        } catch let error as APIError {
            errorMessage = error.userFacingMessage
        } catch {
            errorMessage = "Something went wrong submitting your request. Please try again."
        }
    }
}
