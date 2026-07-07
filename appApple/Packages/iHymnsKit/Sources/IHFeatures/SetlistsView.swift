// SetlistsView.swift
// IHFeatures
//
// ELI5: The "my setlists" screen — every setlist you've made, with a "+" to
// start a new one, swipe-to-rename/delete on each, and tapping one opens it.
// Signed out shows a sign-in prompt instead (setlists need an account, just
// like favourites).
//
// DETAILED: #181's setlists half. Mirrors `FavoritesView.swift`'s exact
// shape (signed-out/empty/loaded states, its own `.navigationDestination`
// for the same "this screen can be hosted in its own `NavigationStack` OR as
// a `NavigationSplitView` content column" reason documented there) — the
// list-of-entities screen pattern this package already establishes, applied
// to setlists instead of favourited songs. Create/rename both use a plain
// `.alert(...) { TextField ... }` (rather than a full sheet, unlike
// `AddToSetlistSheet`'s picker) — a single required text field is exactly
// what `.alert`'s built-in text-field support is for, and keeps this screen
// from needing a second file just for a name-entry form.
import IHModels
import SwiftUI

/// The setlists list: every setlist the signed-in user owns, most-recently
/// -touched first (`AppRootViewModel.setlists`'s own ordering).
public struct SetlistsView: View {
    private let rootViewModel: AppRootViewModel

    @State private var isPresentingLogin = false
    @State private var isPresentingCreateAlert = false
    @State private var newSetlistName = ""

    /// The setlist currently being renamed via `.alert(item:)` — `nil` when
    /// no rename is in progress. Carrying the setlist itself (not just its
    /// id) lets the alert's title interpolate the CURRENT name.
    @State private var renamingSetlist: Setlist?
    @State private var renameText = ""

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle("Setlists")
            .navigationDestination(for: Setlist.self) { setlist in
                SetlistDetailView(setlistId: setlist.id, rootViewModel: rootViewModel)
            }
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button {
                        newSetlistName = ""
                        isPresentingCreateAlert = true
                    } label: {
                        Label("New Setlist", systemImage: "plus")
                    }
                    .disabled(!rootViewModel.sessionState.isSignedIn)
                }
            }
            .alert("New Setlist", isPresented: $isPresentingCreateAlert) {
                TextField("Setlist Name", text: $newSetlistName)
                Button("Cancel", role: .cancel) {}
                Button("Create") {
                    Task { await rootViewModel.createSetlist(name: newSetlistName) }
                }
            }
            .alert(
                "Rename Setlist",
                isPresented: Binding(get: { renamingSetlist != nil }, set: { if !$0 { renamingSetlist = nil } })
            ) {
                TextField("Setlist Name", text: $renameText)
                Button("Cancel", role: .cancel) {}
                Button("Rename") {
                    if let setlist = renamingSetlist {
                        Task { await rootViewModel.renameSetlist(setlist.id, to: renameText) }
                    }
                }
            }
            .sheet(isPresented: $isPresentingLogin) {
                LoginView(rootViewModel: rootViewModel)
            }
            .task {
                await rootViewModel.loadSetlistsIfNeeded()
                await rootViewModel.refreshSetlistsFromServer()
            }
    }

    @ViewBuilder
    private var content: some View {
        if !rootViewModel.sessionState.isSignedIn {
            signedOutView
        } else if rootViewModel.setlists.isEmpty {
            emptyView
        } else {
            List(rootViewModel.setlists) { setlist in
                NavigationLink(value: setlist) {
                    setlistRow(setlist)
                }
                .swipeActions(edge: .trailing) {
                    Button(role: .destructive) {
                        Task { await rootViewModel.deleteSetlist(setlist.id) }
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }
                }
                .swipeActions(edge: .leading) {
                    Button {
                        renameText = setlist.name
                        renamingSetlist = setlist
                    } label: {
                        Label("Rename", systemImage: "pencil")
                    }
                    .tint(.orange)
                }
            }
            .listStyle(.plain)
        }
    }

    /// One setlist's row: name + a small song-count caption, so the list
    /// reads like "Sunday Morning Service — 8 songs" without opening it.
    private func setlistRow(_ setlist: Setlist) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(setlist.name)
                .font(.body)
            Text(songCountText(for: setlist))
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    private func songCountText(for setlist: Setlist) -> String {
        switch setlist.songs.count {
        case 0: "No songs yet"
        case 1: "1 song"
        default: "\(setlist.songs.count) songs"
        }
    }

    private var signedOutView: some View {
        ContentUnavailableView {
            Label("Sign In to See Setlists", systemImage: "list.bullet.rectangle.portrait")
        } description: {
            Text("Setlists are synced to your account across every device.")
        } actions: {
            Button("Sign In") { isPresentingLogin = true }
                .buttonStyle(.borderedProminent)
        }
    }

    private var emptyView: some View {
        ContentUnavailableView {
            Label("No Setlists Yet", systemImage: "list.bullet.rectangle.portrait")
        } description: {
            Text("Tap the + button to start your first setlist.")
        } actions: {
            Button("New Setlist") {
                newSetlistName = ""
                isPresentingCreateAlert = true
            }
            .buttonStyle(.borderedProminent)
        }
    }
}
