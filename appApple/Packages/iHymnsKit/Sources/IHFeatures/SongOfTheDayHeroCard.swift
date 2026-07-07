// SongOfTheDayHeroCard.swift
// IHFeatures
//
// ELI5: The big glass card at the top of Home showing today's featured
// song — its theme heading ("Christmas Song of the Day"), title, songbook,
// and opening line as a teaser.
//
// DETAILED: Split out of `HomeView.swift` (mirrors `SongbookTileView`/
// `SongSummaryRow`'s own precedent — a reusable per-row/per-card view gets
// its own file) so `HomeView` itself stays a thin composition of sections
// rather than growing this card's layout inline. `IHGlassCard`'s larger
// `cornerRadius: 28` (matching `PhaseZeroSkeletonView`'s own hero-card
// styling) marks this as Home's headline element, visually distinct from
// the smaller default-radius cards `RecentlyViewedSong`/"Browse Songbooks"
// use below it.
import IHDesign
import IHModels
import SwiftUI

/// The Song-of-the-Day hero card — tap to open the song.
///
/// ELI5: "Today's featured hymn," drawn as one big glass card.
struct SongOfTheDayHeroCard: View {
    let sotd: SongOfTheDay
    let song: SongOfTheDayCard

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(sotd.themeLabel.isEmpty ? "Song of the Day" : sotd.themeLabel)
                .font(.caption.weight(.semibold))
                .foregroundStyle(IHColorTokens.accent)
                .textCase(.uppercase)

            Text(song.title)
                .font(.title2.bold())

            Text(subtitleText)
                .font(.subheadline)
                .foregroundStyle(.secondary)

            if !sotd.firstLine.isEmpty {
                Text(sotd.firstLine)
                    .font(.body)
                    .italic()
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
                    .padding(.top, 4)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .ihGlassCard(cornerRadius: 28)
        // Folds the theme label/title/subtitle/teaser line into ONE
        // accessible name — mirrors `SongSummaryRow`/`SongbookTileView`'s
        // own "one VoiceOver stop per conceptual row/card" posture, rather
        // than four separate stops for what is visually one card.
        .accessibilityElement(children: .combine)
    }

    private var subtitleText: String {
        let numberText = song.number.map(String.init) ?? ""
        let bookName = song.songbookName.isEmpty ? song.songbookAbbreviation : song.songbookName
        return numberText.isEmpty ? bookName : "\(numberText) — \(bookName)"
    }
}

#Preview {
    let card = SongOfTheDayCard(
        songId: SongID(rawValue: "CH-0100") ?? SongID(songbookAbbreviation: "CH", number: 100),
        number: 100,
        title: "To Us a Child of Hope Is Born",
        songbookAbbreviation: "CH",
        songbookName: "The Church Hymnal",
        verified: false
    )
    return SongOfTheDayHeroCard(
        sotd: SongOfTheDay(song: card, themeLabel: "Christmas Song of the Day", firstLine: "Hark! the herald angels sing"),
        song: card
    )
    .padding()
}
