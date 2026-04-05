// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  WidgetViews.swift
//  iHymns
//
//  Provides the SwiftUI view components, data models, and timeline
//  provider logic for the iHymns home-screen widgets. Two widgets are
//  defined:
//
//    1. **SongOfTheDayWidget** — surfaces a single random song that
//       rotates every 6 hours, displayed across small / medium / large
//       widget families.
//
//    2. **RecentFavoritesWidget** — shows the 3 most recently
//       favourited songs in small and medium families.
//
//  This file contains the *shared* SwiftUI views and data structures.
//  The actual WidgetKit extension target must be added separately in
//  Xcode:
//
//    File > New > Target > Widget Extension
//
//  When creating that target, configure it with the App Group
//  identifier defined in `WidgetConstants.appGroupIdentifier` below,
//  and ensure both the main app target and the widget extension target
//  have the same App Group capability enabled. The widget extension's
//  entry point should instantiate `SongOfTheDayWidgetConfiguration`
//  and `RecentFavoritesWidgetConfiguration` (or a `WidgetBundle`)
//  which reference the provider and view types exported here.
//
//  Deep linking: tapping a widget opens the main app via a URL of the
//  form `ihymns://song/{songId}`. The main app's `onOpenURL` handler
//  should parse this and navigate to the corresponding `SongDetailView`.
//

import SwiftUI
import WidgetKit

// MARK: - Constants
// ──────────────────────────────────────────────────────────────────────
// Centralised constants used by both widget types to keep magic strings
// and values in one place.
// ──────────────────────────────────────────────────────────────────────

/// A namespace for widget-related constants shared across providers,
/// entries, and views.
enum WidgetConstants {

    /// The App Group identifier used to share a `UserDefaults` suite
    /// between the main iHymns app and its widget extension. Both
    /// targets must declare this group in their Signing & Capabilities.
    static let appGroupIdentifier = "group.com.mwbm.ihymns"

    /// The `UserDefaults` key under which the main app persists the
    /// ordered array of favourited song IDs. The widget reads from this
    /// key to display recent favourites and to pick the song of the day.
    static let favoritesKey = "ihymns_favorites"

    /// The `UserDefaults` key where the main app writes the full
    /// encoded song catalogue as JSON data. The widget extension cannot
    /// access the app bundle directly, so the main app serialises the
    /// catalogue here on every launch for the widget to consume.
    static let songCatalogueKey = "ihymns_widget_song_catalogue"

    /// The interval (in seconds) between automatic timeline refreshes
    /// for the Song of the Day widget. Set to 6 hours so the featured
    /// song changes four times per day.
    static let refreshIntervalSeconds: TimeInterval = 6 * 60 * 60

    /// The deep link URL scheme used by the app. Widget taps construct
    /// URLs of the form `ihymns://song/{songId}`.
    static let deepLinkScheme = "ihymns"

    /// The brand amber accent colour (hex #b45309) used throughout the
    /// iHymns app. Widgets use this for badges, titles, and decorative
    /// elements to maintain visual consistency.
    static let amberAccent = Color(
        red: 180.0 / 255.0,
        green: 83.0 / 255.0,
        blue: 9.0 / 255.0
    )
}

// MARK: - WidgetSongEntry
// ──────────────────────────────────────────────────────────────────────
// The timeline entry model consumed by both widget providers. Each
// entry carries enough data for every widget family size to render
// without additional lookups.
// ──────────────────────────────────────────────────────────────────────

/// A single timeline entry that WidgetKit uses to render a widget at a
/// specific point in time. Contains pre-resolved song data so the view
/// code does not need to perform any I/O.
struct WidgetSongEntry: TimelineEntry {

    /// The date at which this entry becomes the active content for the
    /// widget. WidgetKit uses this to schedule transitions between
    /// entries.
    let date: Date

    /// The unique song identifier (e.g. "MP-0742"). Used to construct
    /// the deep link URL when the user taps the widget.
    let songId: String

    /// The human-readable song title (e.g. "Amazing Grace").
    let songTitle: String

    /// The short songbook identifier (e.g. "MP", "CH"). Displayed as a
    /// coloured badge in the widget UI.
    let songbook: String

    /// A short preview of the song's lyrics — typically the first two
    /// lines of the first verse. Shown in the medium widget family.
    let lyricsPreview: String

    /// The full text of the first verse, with lines joined by newlines.
    /// Shown in the large widget family for a more immersive preview.
    let firstVerseLyrics: String

    /// A display-ready string of writer names separated by commas.
    /// Shown in the large widget family below the lyrics.
    let writers: String
}

// MARK: - RecentFavoritesEntry
// ──────────────────────────────────────────────────────────────────────
// A dedicated entry for the Recent Favourites widget, carrying data
// for up to 3 songs instead of a single featured song.
// ──────────────────────────────────────────────────────────────────────

/// A timeline entry for the Recent Favourites widget. Carries an array
/// of lightweight song summaries (up to 3) so the widget can display
/// multiple items without querying the data store at render time.
struct RecentFavoritesEntry: TimelineEntry {

    /// The date at which this entry becomes active.
    let date: Date

    /// Up to 3 recently favourited songs, ordered from most recent to
    /// least recent. Each tuple carries the song ID, title, and
    /// songbook identifier.
    let songs: [FavoriteSongSummary]
}

/// A lightweight value type that carries just enough data about a
/// favourited song for the Recent Favourites widget to render a row.
struct FavoriteSongSummary: Identifiable {

    /// The unique song identifier, also used as the `Identifiable` id.
    let id: String

    /// The human-readable song title.
    let title: String

    /// The short songbook identifier for the badge.
    let songbook: String
}

// MARK: - WidgetDataLoader
// ──────────────────────────────────────────────────────────────────────
// A helper that reads song data from the shared App Group container.
// Encapsulated here so both providers share the same loading logic.
// ──────────────────────────────────────────────────────────────────────

/// Loads song and favourites data from the shared `UserDefaults` suite
/// that the main app writes to. This is the only data access mechanism
/// available to widget extensions — they cannot read the app bundle.
struct WidgetDataLoader {

    // MARK: Shared UserDefaults

    /// The shared `UserDefaults` suite backed by the App Group
    /// container. Returns `UserDefaults.standard` as a fallback if the
    /// suite cannot be created (should not happen in production).
    private static var sharedDefaults: UserDefaults {
        // Attempt to create a suite backed by the App Group identifier.
        // If the App Group has not been configured in both targets,
        // this will return nil and we fall back to standard defaults.
        return UserDefaults(suiteName: WidgetConstants.appGroupIdentifier)
            ?? .standard
    }

    // MARK: Song Catalogue

    /// Decodes the full song catalogue from the shared `UserDefaults`.
    /// The main app is responsible for writing this data on every
    /// launch using `WidgetDataLoader.writeSongCatalogue(_:)`.
    ///
    /// - Returns: An array of `Song` values, or an empty array if the
    ///   data is missing or corrupt.
    static func loadSongCatalogue() -> [Song] {
        // Retrieve the raw JSON data stored by the main app.
        guard let data = sharedDefaults.data(
            forKey: WidgetConstants.songCatalogueKey
        ) else {
            // No data has been written yet — return empty.
            return []
        }

        // Attempt to decode the JSON into an array of Song values.
        do {
            let songs = try JSONDecoder().decode([Song].self, from: data)
            return songs
        } catch {
            // Decoding failed — the data may be from an older app
            // version with an incompatible schema. Return empty rather
            // than crashing the widget.
            return []
        }
    }

    /// Encodes the provided song catalogue as JSON and writes it to
    /// the shared `UserDefaults` so the widget extension can access it.
    /// Call this from the main app (e.g. in `SongStore.loadSongs()`)
    /// every time data is refreshed.
    ///
    /// - Parameter songs: The complete array of `Song` values to share.
    static func writeSongCatalogue(_ songs: [Song]) {
        // Encode the songs array to JSON data.
        guard let data = try? JSONEncoder().encode(songs) else {
            // Encoding should never fail for valid Song values, but
            // guard defensively.
            return
        }

        // Write the encoded data to the shared suite.
        sharedDefaults.set(data, forKey: WidgetConstants.songCatalogueKey)
    }

    // MARK: Favourites

    /// Reads the ordered list of favourited song IDs from the shared
    /// `UserDefaults`. The order reflects the sequence in which songs
    /// were added, with the most recently added ID last.
    ///
    /// - Returns: An array of song ID strings, or an empty array if
    ///   no favourites have been saved.
    static func loadFavoriteIds() -> [String] {
        // Retrieve the string array stored under the favourites key.
        return sharedDefaults.stringArray(
            forKey: WidgetConstants.favoritesKey
        ) ?? []
    }

    // MARK: Deep Link Construction

    /// Constructs a deep link URL that, when opened, navigates the
    /// main app to the specified song's detail view.
    ///
    /// - Parameter songId: The unique song identifier (e.g. "MP-0742").
    /// - Returns: A URL of the form `ihymns://song/MP-0742`.
    static func deepLink(for songId: String) -> URL {
        // Build the URL from the scheme, host, and song ID path.
        // Force-unwrap is safe here because the format is deterministic.
        return URL(string: "\(WidgetConstants.deepLinkScheme)://song/\(songId)")!
    }
}

// MARK: - WidgetSongProvider (Song of the Day)
// ──────────────────────────────────────────────────────────────────────
// The timeline provider for the Song of the Day widget. Conforms to
// `TimelineProvider` and supplies placeholder, snapshot, and periodic
// timeline entries. The "random" song is derived deterministically
// from the current date so every user sees a consistent pick per
// 6-hour window, and the song changes at predictable intervals.
// ──────────────────────────────────────────────────────────────────────

/// Provides timeline entries for the Song of the Day widget. Loads
/// songs from the shared App Group container and selects one
/// deterministically based on the current date.
struct WidgetSongProvider: TimelineProvider {

    // MARK: Placeholder

    /// Returns a static placeholder entry used by WidgetKit when the
    /// widget is first added to the home screen and real data has not
    /// yet been loaded. The content is redacted (blurred) by the system
    /// so the actual text is not important — only the layout matters.
    func placeholder(in context: Context) -> WidgetSongEntry {
        // Return a representative entry with sample data. WidgetKit
        // will apply a redaction effect over the entire view.
        WidgetSongEntry(
            date: Date(),
            songId: "MP-0001",
            songTitle: "A New Commandment",
            songbook: "MP",
            lyricsPreview: "A new commandment I give unto you...",
            firstVerseLyrics: "A new commandment I give unto you\nThat you love one another\nAs I have loved you",
            writers: "Unknown"
        )
    }

    // MARK: Snapshot

    /// Returns a single entry for the widget gallery preview and
    /// transient displays. This should return quickly — ideally from
    /// cached or sample data rather than performing heavy I/O.
    ///
    /// - Parameters:
    ///   - context: Metadata about the widget (family size, etc.).
    ///   - completion: A closure to call with the snapshot entry.
    func getSnapshot(in context: Context, completion: @escaping (WidgetSongEntry) -> Void) {
        // For gallery previews, return the same placeholder data so
        // the user sees a realistic preview without waiting for I/O.
        let entry = placeholder(in: context)
        completion(entry)
    }

    // MARK: Timeline

    /// Builds a timeline of entries for the Song of the Day widget.
    /// Each timeline contains a single entry (the current song) and
    /// instructs WidgetKit to request a new timeline after the refresh
    /// interval (6 hours) has elapsed.
    ///
    /// - Parameters:
    ///   - context: Metadata about the widget (family size, etc.).
    ///   - completion: A closure to call with the constructed timeline.
    func getTimeline(in context: Context, completion: @escaping (Timeline<WidgetSongEntry>) -> Void) {
        // Load the full song catalogue from the shared App Group.
        let songs = WidgetDataLoader.loadSongCatalogue()

        // Determine the current date and the next refresh date.
        let currentDate = Date()
        let nextRefresh = currentDate.addingTimeInterval(
            WidgetConstants.refreshIntervalSeconds
        )

        // Select a song deterministically based on the date. If the
        // catalogue is empty, fall back to the placeholder entry.
        let entry: WidgetSongEntry
        if songs.isEmpty {
            // No songs available — use placeholder content.
            entry = placeholder(in: context)
        } else {
            // Derive a deterministic index from the calendar day and
            // the current 6-hour window so the song changes predictably
            // but stays the same within each window.
            let selectedSong = selectSongOfTheDay(from: songs, at: currentDate)
            entry = makeEntry(from: selectedSong, date: currentDate)
        }

        // Create a timeline with the single entry and an "after" reload
        // policy so WidgetKit will call getTimeline again after the
        // next refresh date.
        let timeline = Timeline(entries: [entry], policy: .after(nextRefresh))
        completion(timeline)
    }

    // MARK: Song Selection

    /// Selects a "song of the day" deterministically from the catalogue.
    /// The selection is based on the day of the year combined with a
    /// 6-hour window index, ensuring the song changes four times daily
    /// and every user sees the same pick during the same window.
    ///
    /// - Parameters:
    ///   - songs: The full catalogue of songs to choose from.
    ///   - date: The current date used to derive the selection index.
    /// - Returns: The selected `Song` value.
    private func selectSongOfTheDay(from songs: [Song], at date: Date) -> Song {
        // Extract the day-of-year (1...366) and the hour (0...23) from
        // the current date using the Gregorian calendar.
        let calendar = Calendar.current
        let dayOfYear = calendar.ordinality(of: .day, in: .year, for: date) ?? 1
        let hour = calendar.component(.hour, from: date)

        // Divide the day into four 6-hour windows (0, 1, 2, 3) so the
        // song rotates at midnight, 6 AM, noon, and 6 PM.
        let windowIndex = hour / 6

        // Combine day and window into a single integer, then modulo by
        // the catalogue size to get a valid index.
        let combinedIndex = (dayOfYear * 4 + windowIndex) % songs.count

        return songs[combinedIndex]
    }

    /// Creates a `WidgetSongEntry` from a `Song` model, extracting the
    /// fields needed for every widget family size.
    ///
    /// - Parameters:
    ///   - song: The source `Song` to extract data from.
    ///   - date: The timeline date for this entry.
    /// - Returns: A fully populated `WidgetSongEntry`.
    private func makeEntry(from song: Song, date: Date) -> WidgetSongEntry {
        // Build the first-verse lyrics by taking all lines from the
        // first component and joining them with newline characters.
        let firstVerse = song.components.first?.lines.joined(separator: "\n") ?? ""

        return WidgetSongEntry(
            date: date,
            songId: song.id,
            songTitle: song.title,
            songbook: song.songbook,
            lyricsPreview: song.lyricsPreview,
            firstVerseLyrics: firstVerse,
            writers: song.writersDisplay
        )
    }
}

// MARK: - RecentFavoritesProvider
// ──────────────────────────────────────────────────────────────────────
// The timeline provider for the Recent Favourites widget. Reads the
// ordered favourites list and resolves the 3 most recently added song
// IDs into full song data for display.
// ──────────────────────────────────────────────────────────────────────

/// Provides timeline entries for the Recent Favourites widget. Loads
/// the favourites list and song catalogue from the shared App Group
/// container and builds entries containing the 3 most recent picks.
struct RecentFavoritesProvider: TimelineProvider {

    // MARK: Placeholder

    /// Returns a static placeholder entry with sample favourite songs.
    /// WidgetKit applies a redaction effect so the text is blurred.
    func placeholder(in context: Context) -> RecentFavoritesEntry {
        // Provide 3 sample songs to define the placeholder layout.
        RecentFavoritesEntry(
            date: Date(),
            songs: [
                FavoriteSongSummary(id: "MP-0001", title: "A New Commandment", songbook: "MP"),
                FavoriteSongSummary(id: "CH-0100", title: "Amazing Grace", songbook: "CH"),
                FavoriteSongSummary(id: "CP-0050", title: "O Come All Ye Faithful", songbook: "CP"),
            ]
        )
    }

    // MARK: Snapshot

    /// Returns a snapshot entry for the widget gallery. Uses placeholder
    /// data for speed.
    ///
    /// - Parameters:
    ///   - context: Widget metadata.
    ///   - completion: Closure to call with the snapshot entry.
    func getSnapshot(in context: Context, completion: @escaping (RecentFavoritesEntry) -> Void) {
        let entry = placeholder(in: context)
        completion(entry)
    }

    // MARK: Timeline

    /// Builds a timeline for the Recent Favourites widget. Since
    /// favourites can change at any time (via the main app), the
    /// timeline requests a refresh after 1 hour to stay reasonably
    /// up-to-date without being too aggressive on battery.
    ///
    /// - Parameters:
    ///   - context: Widget metadata.
    ///   - completion: Closure to call with the constructed timeline.
    func getTimeline(in context: Context, completion: @escaping (Timeline<RecentFavoritesEntry>) -> Void) {
        // Load the ordered favourites and full catalogue.
        let favoriteIds = WidgetDataLoader.loadFavoriteIds()
        let songs = WidgetDataLoader.loadSongCatalogue()

        // Build a dictionary for O(1) lookup of songs by ID.
        let songLookup = Dictionary(uniqueKeysWithValues: songs.map { ($0.id, $0) })

        // Take the 3 most recently added favourites. The array is
        // ordered chronologically with the newest entry last, so we
        // reverse and take the prefix.
        let recentIds = Array(favoriteIds.suffix(3).reversed())

        // Resolve each ID to a FavoriteSongSummary, skipping any that
        // no longer exist in the catalogue (e.g. after a data update).
        let summaries: [FavoriteSongSummary] = recentIds.compactMap { id in
            guard let song = songLookup[id] else {
                // Song was removed from the catalogue — skip it.
                return nil
            }
            return FavoriteSongSummary(
                id: song.id,
                title: song.title,
                songbook: song.songbook
            )
        }

        // Build the entry and a timeline that refreshes in 1 hour.
        let entry = RecentFavoritesEntry(date: Date(), songs: summaries)
        let nextRefresh = Date().addingTimeInterval(60 * 60) // 1 hour
        let timeline = Timeline(entries: [entry], policy: .after(nextRefresh))
        completion(timeline)
    }
}

// MARK: - Widget Style Modifiers
// ──────────────────────────────────────────────────────────────────────
// Reusable view modifiers that apply the iHymns brand styling to
// widget content. These ensure visual consistency across both widgets
// and all family sizes.
// ──────────────────────────────────────────────────────────────────────

/// A view modifier that applies the standard iHymns widget card
/// styling: a subtle background with rounded corners and the brand
/// amber accent colour for key elements.
struct WidgetCardStyle: ViewModifier {

    /// Applies a rounded-rectangle background with a faint amber tint,
    /// padding, and a thin amber border to the wrapped content.
    func body(content: Content) -> some View {
        content
            // Apply internal padding so text does not touch the edges.
            .padding(12)
            // Layer a rounded-rectangle background behind the content.
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    // Use a very light amber fill to hint at the brand
                    // colour without overwhelming the text.
                    .fill(WidgetConstants.amberAccent.opacity(0.08))
            )
            // Add a thin amber border for definition against the widget
            // background.
            .overlay(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .strokeBorder(WidgetConstants.amberAccent.opacity(0.25), lineWidth: 1)
            )
    }
}

/// A view modifier that styles a text label as a songbook badge — a
/// small rounded capsule with the amber accent colour.
struct SongbookBadgeStyle: ViewModifier {

    /// Wraps the text in a capsule-shaped background tinted with the
    /// brand amber colour.
    func body(content: Content) -> some View {
        content
            // Use a small, bold font so the badge is compact.
            .font(.caption2.weight(.bold))
            // White text on the amber background for contrast.
            .foregroundStyle(.white)
            // Horizontal and vertical padding inside the capsule.
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            // The amber capsule background.
            .background(
                Capsule(style: .continuous)
                    .fill(WidgetConstants.amberAccent)
            )
    }
}

// MARK: View Extension for Modifiers

/// Convenience extensions on `View` so callers can apply widget styles
/// with a simple dot-syntax method call rather than wrapping in
/// `.modifier(...)`.
extension View {

    /// Applies the standard iHymns widget card styling (amber-tinted
    /// rounded rectangle with border) to this view.
    ///
    /// - Returns: The styled view.
    func widgetCardStyle() -> some View {
        modifier(WidgetCardStyle())
    }

    /// Styles this view as a songbook badge (amber capsule with white
    /// bold text).
    ///
    /// - Returns: The styled view.
    func songbookBadgeStyle() -> some View {
        modifier(SongbookBadgeStyle())
    }
}

// MARK: - SongOfTheDayWidget View
// ──────────────────────────────────────────────────────────────────────
// The SwiftUI view rendered by the Song of the Day widget. It adapts
// its layout based on the widget family (small, medium, large) to make
// optimal use of the available space.
// ──────────────────────────────────────────────────────────────────────

/// The main view for the Song of the Day widget. Reads the current
/// `WidgetFamily` from the environment and switches between three
/// layout variants: small (title + badge), medium (title + preview +
/// badge), and large (title + full first verse + writers).
struct SongOfTheDayWidgetView: View {

    // MARK: Properties

    /// The timeline entry containing the pre-resolved song data to
    /// display. Provided by WidgetKit via the widget configuration.
    let entry: WidgetSongEntry

    /// The current widget family (small, medium, large) read from the
    /// SwiftUI environment. Determines which layout variant to render.
    @Environment(\.widgetFamily) var widgetFamily

    // MARK: Body

    /// Renders the appropriate layout for the current widget family,
    /// wrapped in a deep link that opens the song in the main app.
    var body: some View {
        // Wrap the entire widget content in a Link so tapping anywhere
        // opens the main app and navigates to this song.
        Link(destination: WidgetDataLoader.deepLink(for: entry.songId)) {
            // Choose the layout based on the widget family size.
            switch widgetFamily {
            case .systemSmall:
                // Small: compact layout with just the title and badge.
                smallLayout
            case .systemMedium:
                // Medium: title, lyrics preview, and badge.
                mediumLayout
            case .systemLarge:
                // Large: title, full first verse, and writer credits.
                largeLayout
            default:
                // Fallback for any future widget families — use medium.
                mediumLayout
            }
        }
        // Apply the containerBackground modifier introduced in iOS 17
        // for widget backgrounds. On older systems this is ignored.
        .containerBackground(for: .widget) {
            // A solid white background for light mode, system
            // background for dark mode.
            Color(.systemBackground)
        }
    }

    // MARK: - Small Layout

    /// A compact layout for the systemSmall family. Displays the song
    /// title prominently with the songbook badge in the bottom corner.
    private var smallLayout: some View {
        VStack(alignment: .leading, spacing: 8) {
            // ── Header Icon ────────────────────────────────────────
            // A small music note icon tinted with the brand colour to
            // identify this as the "Song of the Day" widget.
            Image(systemName: "music.note")
                .font(.title3)
                .foregroundStyle(WidgetConstants.amberAccent)

            Spacer()

            // ── Song Title ─────────────────────────────────────────
            // The title is limited to 2 lines to prevent overflow on
            // the small widget canvas.
            Text(entry.songTitle)
                .font(.headline)
                .fontWeight(.bold)
                .foregroundStyle(.primary)
                .lineLimit(2)
                .minimumScaleFactor(0.8)

            // ── Songbook Badge ─────────────────────────────────────
            // A small capsule showing the songbook abbreviation.
            Text(entry.songbook)
                .songbookBadgeStyle()
        }
        // Apply standard internal padding for the widget.
        .padding()
        // Align everything to the leading edge.
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }

    // MARK: - Medium Layout

    /// A wider layout for the systemMedium family. Shows the song title
    /// alongside the first two lines of lyrics and a songbook badge.
    private var mediumLayout: some View {
        HStack(spacing: 12) {
            // ── Left Column: Title & Badge ─────────────────────────
            // The left side carries the song title and songbook badge,
            // stacked vertically.
            VStack(alignment: .leading, spacing: 6) {
                // Music note icon for visual identity.
                Image(systemName: "music.note")
                    .font(.title3)
                    .foregroundStyle(WidgetConstants.amberAccent)

                // Song title, limited to 2 lines.
                Text(entry.songTitle)
                    .font(.headline)
                    .fontWeight(.bold)
                    .foregroundStyle(.primary)
                    .lineLimit(2)

                Spacer()

                // Songbook badge at the bottom of the left column.
                Text(entry.songbook)
                    .songbookBadgeStyle()
            }

            // ── Divider ────────────────────────────────────────────
            // A thin vertical amber line separating the title section
            // from the lyrics preview section.
            Rectangle()
                .fill(WidgetConstants.amberAccent.opacity(0.3))
                .frame(width: 1.5)
                .padding(.vertical, 4)

            // ── Right Column: Lyrics Preview ───────────────────────
            // The first two lines of lyrics give the user a taste of
            // the song before they open the app.
            VStack(alignment: .leading, spacing: 4) {
                // "Song of the Day" label to identify the widget type.
                Text("Song of the Day")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)

                // The lyrics preview, limited to 3 lines for space.
                Text(entry.lyricsPreview)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(3)
                    .fixedSize(horizontal: false, vertical: true)

                Spacer()
            }
        }
        // Standard widget padding.
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }

    // MARK: - Large Layout

    /// A tall layout for the systemLarge family. Displays the song
    /// title, the complete first verse lyrics, and writer credits.
    private var largeLayout: some View {
        VStack(alignment: .leading, spacing: 10) {
            // ── Header Row ─────────────────────────────────────────
            // Music note icon and "Song of the Day" label on one line.
            HStack(spacing: 6) {
                Image(systemName: "music.note")
                    .font(.title3)
                    .foregroundStyle(WidgetConstants.amberAccent)

                Text("Song of the Day")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)

                Spacer()

                // Songbook badge in the top-right corner.
                Text(entry.songbook)
                    .songbookBadgeStyle()
            }

            // ── Song Title ─────────────────────────────────────────
            // Displayed prominently with up to 2 lines.
            Text(entry.songTitle)
                .font(.title2)
                .fontWeight(.bold)
                .foregroundStyle(.primary)
                .lineLimit(2)

            // ── Amber Divider ──────────────────────────────────────
            // A horizontal amber line separating the header from the
            // lyrics body.
            Rectangle()
                .fill(WidgetConstants.amberAccent.opacity(0.3))
                .frame(height: 1.5)

            // ── First Verse Lyrics ─────────────────────────────────
            // The full first verse gives the user a meaningful preview
            // of the song. Limited to 8 lines to prevent overflow.
            Text(entry.firstVerseLyrics)
                .font(.body)
                .foregroundStyle(.primary)
                .lineLimit(8)
                .fixedSize(horizontal: false, vertical: true)

            Spacer()

            // ── Writers Credit ─────────────────────────────────────
            // The writer names are shown at the bottom in a subtle
            // secondary style, but only if the song has known writers.
            if !entry.writers.isEmpty {
                HStack(spacing: 4) {
                    // A small pencil icon to identify this as the
                    // credits line.
                    Image(systemName: "pencil.line")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)

                    // The writer names, truncated if too long.
                    Text(entry.writers)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
        }
        // Standard widget padding.
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }
}

// MARK: - RecentFavoritesWidget View
// ──────────────────────────────────────────────────────────────────────
// The SwiftUI view rendered by the Recent Favourites widget. Shows up
// to 3 recently favourited songs in either a compact (small) or
// expanded (medium) layout.
// ──────────────────────────────────────────────────────────────────────

/// The main view for the Recent Favourites widget. Adapts between
/// small (titles only) and medium (titles + songbook badges) families.
struct RecentFavoritesWidgetView: View {

    // MARK: Properties

    /// The timeline entry containing up to 3 recent favourite songs.
    let entry: RecentFavoritesEntry

    /// The current widget family read from the environment.
    @Environment(\.widgetFamily) var widgetFamily

    // MARK: Body

    /// Renders the appropriate layout, or an empty-state message if
    /// the user has no favourites.
    var body: some View {
        Group {
            if entry.songs.isEmpty {
                // ── Empty State ────────────────────────────────────
                // Shown when the user has not favourited any songs.
                emptyState
            } else {
                // ── Populated State ────────────────────────────────
                // Show the list of favourite songs.
                switch widgetFamily {
                case .systemSmall:
                    smallLayout
                case .systemMedium:
                    mediumLayout
                default:
                    mediumLayout
                }
            }
        }
        // Apply the containerBackground for iOS 17+ widget rendering.
        .containerBackground(for: .widget) {
            Color(.systemBackground)
        }
    }

    // MARK: - Empty State

    /// A placeholder shown when the favourites list is empty. Prompts
    /// the user to open the app and favourite some songs.
    private var emptyState: some View {
        VStack(spacing: 8) {
            // A star icon matching the favourites concept.
            Image(systemName: "star")
                .font(.title2)
                .foregroundStyle(WidgetConstants.amberAccent)

            // Instructional text.
            Text("No Favourites Yet")
                .font(.headline)
                .foregroundStyle(.primary)

            // A subtitle guiding the user.
            Text("Open iHymns and tap the star on any song.")
                .font(.caption)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Small Layout

    /// A compact layout for the systemSmall family. Lists up to 3 song
    /// titles vertically, each tappable via a deep link.
    private var smallLayout: some View {
        VStack(alignment: .leading, spacing: 6) {
            // ── Header ─────────────────────────────────────────────
            // A star icon and "Favourites" label identify the widget.
            HStack(spacing: 4) {
                Image(systemName: "star.fill")
                    .font(.caption)
                    .foregroundStyle(WidgetConstants.amberAccent)

                Text("Favourites")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
            }

            // ── Song List ──────────────────────────────────────────
            // Each song title is a deep link. Titles are limited to 1
            // line to fit all 3 in the small widget.
            ForEach(entry.songs) { song in
                Link(destination: WidgetDataLoader.deepLink(for: song.id)) {
                    Text(song.title)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(.primary)
                        .lineLimit(1)
                        .truncationMode(.tail)
                }
            }

            Spacer()
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }

    // MARK: - Medium Layout

    /// A wider layout for the systemMedium family. Each favourite song
    /// is shown as a row with the title on the left and the songbook
    /// badge on the right, separated by thin dividers.
    private var mediumLayout: some View {
        VStack(alignment: .leading, spacing: 8) {
            // ── Header ─────────────────────────────────────────────
            // Identifies this as the Recent Favourites widget.
            HStack(spacing: 4) {
                Image(systemName: "star.fill")
                    .font(.caption)
                    .foregroundStyle(WidgetConstants.amberAccent)

                Text("Recent Favourites")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
            }

            // ── Song Rows ──────────────────────────────────────────
            // Each row is a deep link containing the title and badge.
            ForEach(Array(entry.songs.enumerated()), id: \.element.id) { index, song in
                // Draw a thin divider above each row except the first.
                if index > 0 {
                    Divider()
                        .background(WidgetConstants.amberAccent.opacity(0.2))
                }

                // The row itself: tappable link to open the song.
                Link(destination: WidgetDataLoader.deepLink(for: song.id)) {
                    HStack {
                        // Song title on the leading edge.
                        Text(song.title)
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.primary)
                            .lineLimit(1)

                        Spacer()

                        // Songbook badge on the trailing edge.
                        Text(song.songbook)
                            .songbookBadgeStyle()
                    }
                }
            }

            Spacer()
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }
}

// MARK: - Widget Configurations
// ──────────────────────────────────────────────────────────────────────
// The Widget conformances that tie together the provider, entry, and
// view for each widget. In the actual WidgetKit extension target, these
// would be registered via a `@main WidgetBundle`.
//
// NOTE: These structs conform to `Widget` and are intended to be
// instantiated from the Widget Extension target's entry point:
//
//     @main
//     struct iHymnsWidgets: WidgetBundle {
//         var body: some Widget {
//             SongOfTheDayWidget()
//             RecentFavoritesWidget()
//         }
//     }
//
// That entry-point file lives in the Widget Extension target, not in
// the main app target. This file (WidgetViews.swift) should be added
// to BOTH targets so the shared types are available everywhere.
// ──────────────────────────────────────────────────────────────────────

/// The Song of the Day widget configuration. Declares the widget's
/// kind identifier, display name, description, and supported families.
struct SongOfTheDayWidget: Widget {

    /// A unique string identifier for this widget. WidgetKit uses this
    /// internally to distinguish between different widget types from
    /// the same extension.
    let kind: String = "SongOfTheDayWidget"

    /// The widget's configuration, specifying the provider, content
    /// view, display name, description, and supported family sizes.
    var body: some WidgetConfiguration {
        // Use `StaticConfiguration` because this widget does not
        // require user-configurable intents — it always shows the
        // algorithmically chosen song of the day.
        StaticConfiguration(kind: kind, provider: WidgetSongProvider()) { entry in
            // Render the Song of the Day view with the provided entry.
            SongOfTheDayWidgetView(entry: entry)
        }
        // The display name shown in the widget gallery when the user
        // long-presses the home screen and taps "+".
        .configurationDisplayName("Song of the Day")
        // A brief description shown below the display name in the
        // widget gallery.
        .description("Discover a hymn that refreshes every 6 hours.")
        // Supported widget family sizes. The view adapts its layout
        // for each of these three sizes.
        .supportedFamilies([
            .systemSmall,
            .systemMedium,
            .systemLarge,
        ])
        // Apply a content margins behaviour for iOS 17+ that ensures
        // the widget's padding is consistent across devices.
        .contentMarginsDisabled()
    }
}

/// The Recent Favourites widget configuration. Shows the 3 most
/// recently favourited songs in a compact or expanded layout.
struct RecentFavoritesWidget: Widget {

    /// A unique string identifier for this widget.
    let kind: String = "RecentFavoritesWidget"

    /// The widget's configuration, supporting small and medium families.
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: RecentFavoritesProvider()) { entry in
            // Render the Recent Favourites view with the provided entry.
            RecentFavoritesWidgetView(entry: entry)
        }
        // Gallery display name.
        .configurationDisplayName("Recent Favourites")
        // Gallery description.
        .description("Quick access to your most recently favourited hymns.")
        // Only small and medium are supported — large would have too
        // much empty space for just 3 items.
        .supportedFamilies([
            .systemSmall,
            .systemMedium,
        ])
        // Disable automatic content margins for manual padding control.
        .contentMarginsDisabled()
    }
}

// MARK: - Previews
// ──────────────────────────────────────────────────────────────────────
// SwiftUI previews for both widgets in all supported family sizes.
// These previews use sample data and render in the Xcode canvas.
// ──────────────────────────────────────────────────────────────────────

#if DEBUG

/// A sample entry used by all Song of the Day previews so the preview
/// code stays DRY.
private let sampleSongEntry = WidgetSongEntry(
    date: Date(),
    songId: "MP-0742",
    songTitle: "Amazing Grace",
    songbook: "MP",
    lyricsPreview: "Amazing grace, how sweet the sound That saved a wretch like me",
    firstVerseLyrics: "Amazing grace, how sweet the sound\nThat saved a wretch like me\nI once was lost, but now am found\nWas blind, but now I see",
    writers: "John Newton"
)

/// A sample entry used by all Recent Favourites previews.
private let sampleFavoritesEntry = RecentFavoritesEntry(
    date: Date(),
    songs: [
        FavoriteSongSummary(id: "MP-0742", title: "Amazing Grace", songbook: "MP"),
        FavoriteSongSummary(id: "CH-0256", title: "How Great Thou Art", songbook: "CH"),
        FavoriteSongSummary(id: "CP-0031", title: "O Holy Night", songbook: "CP"),
    ]
)

// ── Song of the Day Previews ────────────────────────────────────────

#Preview("Song of the Day — Small", as: .systemSmall) {
    SongOfTheDayWidget()
} timeline: {
    sampleSongEntry
}

#Preview("Song of the Day — Medium", as: .systemMedium) {
    SongOfTheDayWidget()
} timeline: {
    sampleSongEntry
}

#Preview("Song of the Day — Large", as: .systemLarge) {
    SongOfTheDayWidget()
} timeline: {
    sampleSongEntry
}

// ── Recent Favourites Previews ──────────────────────────────────────

#Preview("Recent Favourites — Small", as: .systemSmall) {
    RecentFavoritesWidget()
} timeline: {
    sampleFavoritesEntry
}

#Preview("Recent Favourites — Medium", as: .systemMedium) {
    RecentFavoritesWidget()
} timeline: {
    sampleFavoritesEntry
}

// ── Empty State Preview ─────────────────────────────────────────────

#Preview("Recent Favourites — Empty", as: .systemSmall) {
    RecentFavoritesWidget()
} timeline: {
    RecentFavoritesEntry(date: Date(), songs: [])
}

#endif
