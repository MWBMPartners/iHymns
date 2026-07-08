// UsageStatsView.swift
// IHFeatures
//
// ELI5: The "Your Activity" screen — a grid of stat tiles (this week/month,
// your reading streak, songbooks explored, favourites, setlists, offline
// storage), your top 5 most-read songs (tap one to open it), your recent
// searches, and a button to wipe it all if you want a fresh start. A
// footer says plainly: this never leaves your device.
//
// DETAILED: #1446. Reached from Settings → "Your Activity" (`SettingsView
// .activitySection`) — NOT a new tab/sidebar section: `RootContainerView`
// deliberately caps the compact `TabView` at 7 tabs (its own #182 header:
// "rather than overflowing into a system 'More' tab"), and this is data
// about the user's OWN device, which is exactly what Settings is for.
// Registers its OWN `.navigationDestination(for: SongID.self)` — the SAME
// "every hosting stack needs its own copy of the modifier" reasoning
// `HomeView`'s header documents — so tapping a "Most Viewed" row pushes
// straight to `SongDetailView` in whatever stack this screen lives in.
//
// Every stat here reads from `UsageStatsViewModel`, which reads ONLY
// `UsageActivityStore` (a local `UserDefaults` payload) plus a handful of
// existing `AppRootViewModel` pass-throughs — nothing here is gated on, or
// even reads, `IHSettingsStore.analyticsConsentEnabled`: showing a user
// their own on-device data is not "collection," so this screen does not
// respect (and must not be made to respect) that unrelated consent toggle.
// See `PrivacyInfo.xcprivacy`'s comment for the full reasoning.
import IHDesign
import IHModels
import SwiftUI

/// The "Your Activity" local usage-stats dashboard.
public struct UsageStatsView: View {
    @State private var viewModel: UsageStatsViewModel
    /// Kept alongside `viewModel` (mirrors `SongDetailView`/`HomeView`'s
    /// identical shape) purely so the "Most Viewed" navigation destination
    /// below can construct a `SongDetailView` — `UsageStatsViewModel` itself
    /// only needs `AppRootViewModel` for its own local pass-throughs.
    private let rootViewModel: AppRootViewModel
    @State private var isPresentingResetConfirmation = false

    public init(rootViewModel: AppRootViewModel, store: UsageActivityStore = UsageActivityStore()) {
        _viewModel = State(initialValue: UsageStatsViewModel(rootViewModel: rootViewModel, store: store))
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                statsGrid
                topSongsSection
                recentSearchesSection
                resetButton
                footerText
            }
            .padding()
        }
        .navigationTitle("Your Activity")
        .navigationDestination(for: SongID.self) { songId in
            SongDetailView(songId: songId, rootViewModel: rootViewModel)
        }
        .task { await viewModel.loadIfNeeded() }
        .onAppear { IHAnalyticsService().screenViewed("YourActivity") }
        .confirmationDialog(
            "Reset Activity Data?",
            isPresented: $isPresentingResetConfirmation,
            titleVisibility: .visible
        ) {
            Button("Reset Activity Data", role: .destructive) {
                Task { await viewModel.resetActivityData() }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This clears your reading streak, most-viewed songs, and weekly/monthly counts on this device. It can't be undone.")
        }
    }

    // MARK: - Stat tiles

    private var statsGrid: some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
            statTile(title: "This Week", value: "\(viewModel.songsReadThisWeek)", systemImage: "calendar")
            statTile(title: "This Month", value: "\(viewModel.songsReadThisMonth)", systemImage: "calendar.badge.clock")
            statTile(title: "Reading Streak", value: streakDisplay, systemImage: "flame.fill")
            statTile(title: "Songbooks Explored", value: "\(viewModel.songbooksExplored)", systemImage: "books.vertical")
            statTile(title: "Favourites", value: "\(viewModel.favoritesCount)", systemImage: "heart.fill")
            statTile(title: "Setlists", value: "\(viewModel.setlistsCount)", systemImage: "text.badge.plus")
            statTile(title: "Saved Offline", value: "\(viewModel.savedSongCount)", systemImage: "arrow.down.circle")
            statTile(title: "Offline Storage", value: viewModel.totalOfflineSizeDisplay, systemImage: "internaldrive")
        }
    }

    private var streakDisplay: String {
        let streak = viewModel.currentStreak
        return streak == 1 ? "1 day" : "\(streak) days"
    }

    private func statTile(title: String, value: String, systemImage: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Image(systemName: systemImage)
                .foregroundStyle(IHColorTokens.accent)
            Text(value)
                .font(.title2.bold())
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .ihGlassCard()
        // One accessible unit per tile ("This Week, 5") rather than three
        // separate VoiceOver stops — mirrors `SongSummaryRow`'s identical
        // "fold structural context into one accessible name" posture.
        .accessibilityElement(children: .combine)
    }

    // MARK: - Most Viewed

    @ViewBuilder
    private var topSongsSection: some View {
        if !viewModel.topSongs.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("Most Viewed")
                    .font(.headline)
                    .accessibilityAddTraits(.isHeader)
                ForEach(viewModel.topSongs) { counter in
                    NavigationLink(value: counter.songId) {
                        topSongRow(counter)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private func topSongRow(_ counter: SongOpenCounter) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(counter.title)
                Text(subtitle(for: counter))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer(minLength: 0)
            Text(counter.openCount == 1 ? "1 read" : "\(counter.openCount) reads")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .ihGlassCard()
        .accessibilityElement(children: .combine)
    }

    private func subtitle(for counter: SongOpenCounter) -> String {
        let numberText = counter.number.map(String.init) ?? ""
        return numberText.isEmpty ? counter.songbookAbbreviation : "\(counter.songbookAbbreviation) \(numberText)"
    }

    // MARK: - Recent Searches

    @ViewBuilder
    private var recentSearchesSection: some View {
        if !viewModel.recentSearches.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("Recent Searches")
                    .font(.headline)
                    .accessibilityAddTraits(.isHeader)
                ForEach(viewModel.recentSearches, id: \.self) { query in
                    Text(query)
                        .font(.subheadline)
                }
            }
        }
    }

    // MARK: - Reset + honesty footer

    private var resetButton: some View {
        Button(role: .destructive) {
            isPresentingResetConfirmation = true
        } label: {
            Label("Reset Activity Data", systemImage: "trash")
        }
    }

    /// The self-evidently-first-party statement this feature's privacy
    /// stance rests on — see this file's header and `PrivacyInfo.xcprivacy`.
    private var footerText: some View {
        Text("Stored only on this device. Cleared if you delete the app. Never sent anywhere.")
            .font(.footnote)
            .foregroundStyle(.secondary)
    }
}
