// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SharedSetListView
/// Displays a shared set list fetched from the API via its share ID.
/// Opened from deep links: https://ihymns.app/setlist/shared/{id}
struct SharedSetListView: View {

    let shareId: String
    @EnvironmentObject var songStore: SongStore
    @Environment(\.dismiss) private var dismiss

    @State private var sharedSetList: SharedSetListResponse?
    @State private var isLoading = true
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    ProgressView("Loading set list...")
                } else if let error = errorMessage {
                    ContentUnavailableView {
                        Label("Error", systemImage: "exclamationmark.triangle")
                    } description: {
                        Text(error)
                    }
                } else if let setList = sharedSetList {
                    setListContent(setList)
                }
            }
            .navigationTitle(sharedSetList?.name ?? "Shared Set List")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .task {
            await loadSharedSetList()
        }
    }

    // MARK: - Content

    private func setListContent(_ setList: SharedSetListResponse) -> some View {
        List {
            Section {
                HStack {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(setList.name)
                            .font(.headline)
                        Text("\(setList.songs.count) songs")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    // Import button
                    Button {
                        importSetList(setList)
                    } label: {
                        Label("Import", systemImage: "square.and.arrow.down")
                    }
                    .buttonStyle(.bordered)
                    .tint(AmberTheme.accent)
                }
            }

            Section("Songs") {
                ForEach(Array(setList.songs.enumerated()), id: \.offset) { index, songId in
                    if let song = songStore.song(byId: songId) {
                        NavigationLink(destination: SongDetailView(song: song)) {
                            HStack(spacing: Spacing.md) {
                                Text("\(index + 1)")
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(.secondary)
                                    .frame(width: 24)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text(song.title)
                                        .font(.body)
                                        .lineLimit(1)
                                    Text("\(song.songbook) #\(song.number)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    } else {
                        HStack {
                            Text("\(index + 1)")
                                .font(.caption.weight(.bold))
                                .foregroundStyle(.secondary)
                                .frame(width: 24)
                            Text(songId)
                                .font(.body)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Load

    private func loadSharedSetList() async {
        isLoading = true
        do {
            let response = try await APIClient().fetchSharedSetList(id: shareId)
            sharedSetList = response
        } catch {
            errorMessage = "Could not load this set list. It may have been removed."
        }
        isLoading = false
    }

    // MARK: - Import

    private func importSetList(_ shared: SharedSetListResponse) {
        let newSetList = songStore.createSetList(name: shared.name)
        for songId in shared.songs {
            songStore.addSongToSetList(songId, setListId: newSetList.id)
        }
        HapticManager.success()
    }
}
