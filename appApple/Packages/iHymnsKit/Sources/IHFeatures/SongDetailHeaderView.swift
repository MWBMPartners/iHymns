// SongDetailHeaderView.swift
// IHFeatures
//
// ELI5: The very top of a song's page — its big title, an optional
// "(which version)" line and subtitle underneath, then the small
// "number · songbook" line. Nothing you can tap; just what the song is.
//
// DETAILED: Extracted verbatim from `SongDetailView.header(for:)` for the
// LOC-budget tripwire (#1395, strategy §3.3.4 — `Scripts/loc-budget.sh`),
// once #1752 Slice A grew the header with the disambiguation + subtitle
// lines and pushed the parent file past its 400-line budget. This is the
// SAME "every section is its own file" split `SongDetailView`'s own header
// documents (`SongMetadataView`, `SongWorksSection`, the shelves) and the
// same purely-mechanical move `SongDetailView+Handoff.swift` already made —
// a pure function of `SongDetail` with no view-model/root dependencies, so
// the extraction is behaviour-preserving. Mirrors web `song.php` §2.2/§2.3.
import IHModels
import SwiftUI

/// One song's header block: title, then optional disambiguation (#1752 —
/// a short parenthetical distinguishing same-named songs, e.g. "(Christmas
/// version)") and subtitle, then the "number · songbook" line. A pure
/// function of `SongDetail`; `SongDetailView.loadedContent(_:)` composes it
/// in order alongside the other section views.
struct SongDetailHeaderView: View {
    let detail: SongDetail

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(detail.title)
                .font(.largeTitle.bold())

            // #1752 Slice A — disambiguation directly under the title (a
            // short parenthetical distinguishing same-named songs, e.g.
            // "(Christmas version)"), mirroring web `song.php` §2.2's
            // small-text-after-the-<h1> treatment; SwiftUI has no
            // `<small>`-inside-`<h1>` equivalent worth forcing, so this is
            // its own `Text` line instead.
            if let disambiguation = detail.disambiguation, !disambiguation.isEmpty {
                Text("(\(disambiguation))")
                    .font(.title3)
                    .foregroundStyle(.secondary)
            }

            // Subtitle — above the existing number/songbook line, mirroring
            // web `song.php` §2.3.
            if let subtitle = detail.subtitle, !subtitle.isEmpty {
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            HStack(spacing: 8) {
                if let number = detail.number {
                    Text(String(number))
                }
                Text(detail.songbookName.isEmpty ? detail.songbookAbbreviation : detail.songbookName)
            }
            .font(.subheadline)
            .foregroundStyle(.secondary)
        }
    }
}
