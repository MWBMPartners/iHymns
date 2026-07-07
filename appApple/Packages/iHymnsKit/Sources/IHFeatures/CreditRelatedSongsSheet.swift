// CreditRelatedSongsSheet.swift
// IHFeatures
//
// ELI5: Tap a writer/composer's name on the song screen and this shows
// "other songs we have by that person."
//
// DETAILED: #180's "Clickable writer/composer names" — there is no
// dedicated credit-person detail screen/endpoint in the native app yet
// (that's a separate, unbuilt feature), so rather than a dead tap target or
// a fabricated navigation destination, this reuses the SAME
// `related_songs` fetch `SongDetailViewModel` already makes for the
// "Related Songs" shelf: `related_songs`' `reason` field literally reads
// `"Same writer: <name>"` / `"Same composer: <name>"` (verified live, see
// `Tests/Fixtures/related_songs.json`), so filtering that already-fetched
// list by a case-insensitive substring match on the tapped name is a real,
// working "more by this person" view built entirely from data the screen
// already has in memory — no new network call, no fictional screen.
import IHModels
import SwiftUI

/// Shows every `related_songs` match whose `reason` mentions `name`.
struct CreditRelatedSongsSheet: View {
    @Environment(\.dismiss) private var dismiss

    let name: String
    let allRelatedSongs: [RelatedSongSummary]

    /// Needed so tapping a match can push a REAL `SongDetailView` — a sheet
    /// gets its own fresh `NavigationStack` (SwiftUI navigation destinations
    /// don't cross a `.sheet` boundary), so this file registers its own
    /// `.navigationDestination(for: SongID.self)` rather than relying on the
    /// presenting screen's.
    let rootViewModel: AppRootViewModel

    private var matches: [RelatedSongSummary] {
        allRelatedSongs.filter { ($0.reason ?? "").localizedCaseInsensitiveContains(name) }
    }

    var body: some View {
        NavigationStack {
            Group {
                if matches.isEmpty {
                    ContentUnavailableView(
                        "No Other Songs Yet",
                        systemImage: "person",
                        description: Text("We don't have other songs linked to \(name) yet.")
                    )
                } else {
                    List(matches) { song in
                        NavigationLink(value: song.id) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(song.title)
                                Text(song.songbookAbbreviation)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                    .listStyle(.plain)
                }
            }
            .navigationTitle(name)
            #if os(iOS) || os(visionOS)
            .navigationBarTitleDisplayMode(.inline)
            #endif
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: rootViewModel)
            }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }
}
