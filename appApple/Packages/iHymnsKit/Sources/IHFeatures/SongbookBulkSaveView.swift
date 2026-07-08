// SongbookBulkSaveView.swift
// IHFeatures
//
// ELI5: The little sheet that pops up when you tap "Save All for Offline"
// on a songbook — asks whether you also want the audio/sheet-music files
// (those can be big), then shows a "Saving 12 of 48…" progress bar you can
// cancel, and finally tells you if anything didn't save.
//
// DETAILED: #1439. A pure renderer of `SongbookBulkSaveViewModel`, mirroring
// `OfflineStorageView`'s "the view just switches on the view model's state"
// shape — this screen owns no persistence/network logic of its own, same as
// every other screen in this package. Kept as its OWN file (never folded
// into `SongbookSongsView.swift`) purely so that view — already close to
// several siblings' "near budget, extract" guidance — stays a thin list +
// toolbar wiring, per the repo's modularity rule.
//
// Presented as a `.sheet` from `SongbookSongsView`'s toolbar; `songbook`/
// `songs` are the exact songs that view already has in hand (its own
// `songsInBook` filter), so this view fetches nothing extra just to know
// what it's about to save.
import IHModels
import SwiftUI

/// The "Save All for Offline" confirmation + progress sheet for one
/// songbook.
///
/// ELI5: "Save every song in this book for offline — with or without the
/// audio/sheet music — and show me how it's going."
struct SongbookBulkSaveView: View {
    let songbookName: String
    let songs: [SongSummary]
    let rootViewModel: AppRootViewModel

    @Environment(\.dismiss) private var dismiss
    @State private var viewModel: SongbookBulkSaveViewModel
    @State private var includeMedia = false

    init(songbookName: String, songs: [SongSummary], rootViewModel: AppRootViewModel) {
        self.songbookName = songbookName
        self.songs = songs
        self.rootViewModel = rootViewModel
        _viewModel = State(initialValue: SongbookBulkSaveViewModel(rootViewModel: rootViewModel))
    }

    var body: some View {
        NavigationStack {
            content
                .navigationTitle("Save All for Offline")
                #if os(iOS) || os(visionOS)
                .navigationBarTitleDisplayMode(.inline)
                #endif
                .toolbar { toolbarContent }
        }
        // Cancels an in-flight run if the user dismisses the sheet some
        // other way (e.g. swipe-to-dismiss on iOS) instead of tapping
        // "Cancel"/"Done" explicitly — a run must never keep fetching in
        // the background once its own progress UI is gone.
        .onDisappear { viewModel.cancel() }
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.phase {
        case .idle:
            confirmationForm
        case .running:
            progressView
        case .finished, .cancelled:
            resultView
        }
    }

    // MARK: - Idle (confirmation)

    private var confirmationForm: some View {
        Form {
            Section {
                LabeledContent("Songbook", value: songbookName)
                LabeledContent("Songs", value: "\(songs.count)")
            }
            Section {
                Toggle("Include Audio & Sheet Music", isOn: $includeMedia)
            } footer: {
                // #1439's own "decide + document whether Save All also
                // pulls media" call — see `SongbookBulkSaveViewModel.swift`'s
                // header for the full reasoning; this footer is the
                // user-facing half of that same decision.
                Text("Audio recordings and sheet music can add up to a large download for a whole songbook. Leave this off to save just the lyrics and chords.")
            }
            Section {
                Button("Save All \(songs.count) Songs") {
                    viewModel.start(songs: songs, includeMedia: includeMedia)
                }
                .disabled(songs.isEmpty)
            }
        }
    }

    // MARK: - Running (determinate progress)

    private var progressView: some View {
        VStack(spacing: 16) {
            Spacer()
            ProgressView(value: viewModel.fractionCompleted)
                .progressViewStyle(.linear)
                .padding(.horizontal)
            Text("Saving \(viewModel.completedCount) of \(viewModel.totalCount)…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
            Spacer()
            Button("Cancel", role: .cancel) {
                viewModel.cancel()
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Finished / cancelled (result summary)

    private var resultView: some View {
        List {
            Section {
                LabeledContent("Saved", value: "\(viewModel.completedCount - viewModel.failures.count) of \(viewModel.totalCount)")
                if viewModel.phase == .cancelled {
                    Text("Cancelled before every song was saved.")
                        .foregroundStyle(.secondary)
                }
            }
            if !viewModel.failures.isEmpty {
                Section("Couldn't Save") {
                    ForEach(viewModel.failures) { failure in
                        VStack(alignment: .leading, spacing: 2) {
                            Text(failure.title.isEmpty ? failure.id.rawValue : failure.title)
                            Text(failure.message)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        switch viewModel.phase {
        case .idle:
            ToolbarItem(placement: .cancellationAction) {
                Button("Cancel") { dismiss() }
            }
        case .running:
            ToolbarItem(placement: .cancellationAction) {
                EmptyView()
            }
        case .finished, .cancelled:
            ToolbarItem(placement: .confirmationAction) {
                Button("Done") { dismiss() }
            }
        }
    }
}
