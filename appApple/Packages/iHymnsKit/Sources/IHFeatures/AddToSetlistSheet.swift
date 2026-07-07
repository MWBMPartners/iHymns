// AddToSetlistSheet.swift
// IHFeatures
//
// ELI5: The little popup that appears when you tap "Add to Setlist" on a
// song — pick one of your existing setlists (a checkmark shows it's already
// there), or type a name to start a brand-new one.
//
// DETAILED: #181's setlists half — the "Add a 'Add to setlist' affordance on
// SongDetailToolbar (like the favourite toggle)" brief. Presented as a
// `.sheet` by `SongDetailView` (see that file's header for the
// `isPresentingAddToSetlist` binding split). Reads `rootViewModel.setlists`
// directly (the same "root view model IS the state" pattern `FavoritesView`
// uses for `rootViewModel.favorites`) rather than owning a second, competing
// copy — so a setlist created HERE immediately shows up correctly checked if
// the sheet is reopened for the same song without dismissing/re-presenting.
import IHDesign
import IHModels
import SwiftUI

/// A sheet listing every setlist the signed-in user owns, with a
/// tap-to-add-`song` action per row, plus a "New Setlist" row that creates
/// one and adds `song` to it in the same action.
public struct AddToSetlistSheet: View {
    private let rootViewModel: AppRootViewModel
    private let song: SetlistSongEntry

    @Environment(\.dismiss) private var dismiss
    @State private var newSetlistName = ""
    @State private var isSubmittingNewSetlist = false

    public init(rootViewModel: AppRootViewModel, song: SetlistSongEntry) {
        self.rootViewModel = rootViewModel
        self.song = song
    }

    public var body: some View {
        NavigationStack {
            List {
                if !rootViewModel.setlists.isEmpty {
                    Section("Your Setlists") {
                        ForEach(rootViewModel.setlists) { setlist in
                            existingSetlistRow(setlist)
                        }
                    }
                }

                Section("New Setlist") {
                    TextField("Setlist Name", text: $newSetlistName)
                        .disabled(isSubmittingNewSetlist)
                    Button {
                        Task { await createAndAdd() }
                    } label: {
                        if isSubmittingNewSetlist {
                            ProgressView()
                        } else {
                            Text("Create & Add")
                        }
                    }
                    .disabled(trimmedNewSetlistName.isEmpty || isSubmittingNewSetlist)
                }
            }
            .navigationTitle("Add to Setlist")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .task { await rootViewModel.loadSetlistsIfNeeded() }
        }
    }

    /// One existing-setlist row: tapping it adds `song` (a no-op, per
    /// `AppRootViewModel.addSong(_:to:)`'s own doc comment, if it's already
    /// there) and dismisses. Already-containing setlists still show a
    /// checkmark AND stay tappable — tapping one again is a harmless no-op,
    /// simpler than disabling the row and explaining why.
    private func existingSetlistRow(_ setlist: Setlist) -> some View {
        Button {
            Task {
                await rootViewModel.addSong(song, to: setlist.id)
                dismiss()
            }
        } label: {
            HStack {
                Text(setlist.name)
                    .foregroundStyle(.primary)
                Spacer()
                if setlist.contains(song.songId) {
                    Image(systemName: "checkmark")
                        .foregroundStyle(IHColorTokens.accent)
                }
            }
        }
    }

    private var trimmedNewSetlistName: String {
        newSetlistName.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func createAndAdd() async {
        guard !trimmedNewSetlistName.isEmpty else { return }
        isSubmittingNewSetlist = true
        let setlist = await rootViewModel.createSetlist(name: trimmedNewSetlistName)
        await rootViewModel.addSong(song, to: setlist.id)
        isSubmittingNewSetlist = false
        dismiss()
    }
}
