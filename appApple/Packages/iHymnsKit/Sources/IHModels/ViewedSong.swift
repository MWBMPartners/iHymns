// ViewedSong.swift
// IHModels
//
// ELI5: The two lists the server keeps of what a signed-in person has been
// reading: "recently viewed" (newest first) and "most viewed" (with a count).
//
// DETAILED: PR 3b step I3 (#2180). Verified against the live `api.php` cases
// `song_history` (rows `songId, title, number, songbook, songbookName,
// language, viewedAt, viewedAtEpoch`) and `my_most_viewed` (rows `songId,
// title, number, songbook, songbookName, language, views`, plus the `period`
// it used), read 2026-11-24. Both lists are the person's OWN history across
// every device and the website, kept on the server; the app only reads them.
// Two decoding choices follow the rest of this package:
//   - a row whose song id is not the `<letters>-<digits>` shape `SongID`
//     accepts (the website also has synthetic "song-<time>-<random>" ids) is
//     SKIPPED, never a failure of the whole list: the same lossy-list stance
//     `decodeSongsIndex` takes;
//   - every field except the id and title is optional, so a server that omits
//     one still decodes.
import Foundation

/// One row of "recently viewed".
///
/// ELI5: A song you opened, and when you last opened it.
public struct ViewedSong: Sendable, Hashable, Decodable, Identifiable {
    public var id: SongID { songId }
    public let songId: SongID
    public let title: String
    public let number: Int?
    /// The songbook abbreviation (wire key `songbook`).
    public let songbookAbbreviation: String?
    public let songbookName: String?
    public let language: String?
    /// The server's clock text for the last view, untouched (see `Setlist.swift` on why).
    public let viewedAt: String?

    public init(
        songId: SongID,
        title: String,
        number: Int? = nil,
        songbookAbbreviation: String? = nil,
        songbookName: String? = nil,
        language: String? = nil,
        viewedAt: String? = nil
    ) {
        self.songId = songId
        self.title = title
        self.number = number
        self.songbookAbbreviation = songbookAbbreviation
        self.songbookName = songbookName
        self.language = language
        self.viewedAt = viewedAt
    }

    private enum CodingKeys: String, CodingKey {
        case songId
        case title
        case number
        case songbookAbbreviation = "songbook"
        case songbookName
        case language
        case viewedAt
    }
}

/// One row of "your most viewed".
///
/// ELI5: A song you open a lot, and how many times.
public struct MostViewedSong: Sendable, Hashable, Decodable, Identifiable {
    public var id: SongID { songId }
    public let songId: SongID
    public let title: String
    public let number: Int?
    public let songbookAbbreviation: String?
    public let songbookName: String?
    public let language: String?
    /// How many times it was opened in the period.
    public let views: Int

    public init(
        songId: SongID,
        title: String,
        number: Int? = nil,
        songbookAbbreviation: String? = nil,
        songbookName: String? = nil,
        language: String? = nil,
        views: Int
    ) {
        self.songId = songId
        self.title = title
        self.number = number
        self.songbookAbbreviation = songbookAbbreviation
        self.songbookName = songbookName
        self.language = language
        self.views = views
    }

    private enum CodingKeys: String, CodingKey {
        case songId
        case title
        case number
        case songbookAbbreviation = "songbook"
        case songbookName
        case language
        case views
    }
}

/// The periods `my_most_viewed` accepts. The raw values must equal the
/// server's `MY_MOST_VIEWED_PERIODS` keys (`tests/test-apple-view-history.js`
/// compares them from the source).
///
/// ELI5: How far back "most viewed" looks.
public enum MostViewedPeriod: String, Sendable, CaseIterable {
    case thirtyDays = "30d"
    case ninetyDays = "90d"
    case twelveMonths = "12m"
    case allTime = "all"
}

/// The whole `my_most_viewed` answer.
///
/// ELI5: The list, and which period the server used (it picks the person's
/// saved choice, or 12 months, when none is asked for).
public struct MostViewedResult: Sendable, Equatable {
    public let songs: [MostViewedSong]
    public let period: String

    public init(songs: [MostViewedSong], period: String) {
        self.songs = songs
        self.period = period
    }
}
