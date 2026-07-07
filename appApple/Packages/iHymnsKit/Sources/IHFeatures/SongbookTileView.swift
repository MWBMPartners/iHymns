// SongbookTileView.swift
// IHFeatures
//
// ELI5: One tile in the Songbooks grid — the book-code badge (when it's
// worth showing), the full name, how many songs it has, and an
// "Unofficial" pill when it isn't an official denominational hymnal.
//
// DETAILED: #1437 (Apple P1 Songbooks browse). The repo's modularity rule
// applied the same way `SongSummaryRow` already is for song rows: ONE
// shared songbook tile, reused by `SongbooksView`'s grid today and by any
// future screen that lists songbooks (a home "browse songbooks" shelf,
// say) tomorrow. Uses `Songbook.abbreviationLabel`/`showsAbbreviationLabel`
// (`IHModels/Songbook+Display.swift`, mirroring web rule #27) rather than
// reading `Songbook.id`/`displayAbbr` directly, and the shared
// `IHUnofficialBadge` (`IHDesign/IHBadge.swift`, mirroring web rule #24)
// rather than a hand-rolled "Unofficial" label — both regressions the
// project's own red-flag list explicitly calls out.
import IHDesign
import IHModels
import SwiftUI

/// One reusable songbook tile.
struct SongbookTileView: View {
    let songbook: Songbook

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                if songbook.showsAbbreviationLabel {
                    Text(songbook.abbreviationLabel)
                        .font(.headline)
                        .foregroundStyle(IHColorTokens.accent)
                }
                Spacer(minLength: 8)
                if !songbook.isOfficial {
                    IHUnofficialBadge()
                }
            }

            Text(songbook.name)
                .font(.subheadline.weight(.semibold))
                .lineLimit(2)

            Text(songCountText)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .ihGlassCard()
        // Folds the abbreviation badge/name/unofficial-pill/song-count into
        // ONE accessible name (mirrors `SongSummaryRow`'s own posture, and
        // the web's #680/#856 badge-in-accessible-name a11y pattern) rather
        // than four separate VoiceOver stops for what is conceptually one
        // tile.
        .accessibilityElement(children: .combine)
    }

    private var songCountText: String {
        songbook.songCount == 1 ? "1 song" : "\(songbook.songCount) songs"
    }
}

#Preview {
    // swiftlint:disable:next force_try
    let unofficial = try! JSONDecoder().decode(Songbook.self, from: Data("""
    {
        "id": "Psalty", "name": "Psalty", "songCount": 3, "colour": null, "isOfficial": false,
        "publisher": null, "publicationYear": null, "copyright": null, "affiliation": null,
        "displayAbbr": null, "language": "en", "websiteUrl": null, "internetArchiveUrl": null,
        "wikipediaUrl": null, "wikidataId": null, "oclcNumber": null, "ocnNumber": null,
        "lcpNumber": null, "isbn": null, "arkId": null, "isniId": null, "viafId": null,
        "lccn": null, "lcClass": null, "parent": null, "series": [], "compilers": [],
        "alternativeNames": [], "links": [], "languages": ["en"]
    }
    """.utf8))
    return SongbookTileView(songbook: unofficial)
        .padding()
        .frame(width: 200)
}
