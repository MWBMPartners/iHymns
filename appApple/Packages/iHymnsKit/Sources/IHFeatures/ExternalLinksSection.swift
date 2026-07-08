// ExternalLinksSection.swift
// IHFeatures
//
// ELI5: A row of "find this elsewhere" buttons — Wikipedia, Spotify, IMSLP,
// whatever a curator attached — shared by every screen that shows a list of
// `IHModels.ExternalLink`s, so each doesn't invent its own.
//
// DETAILED: #1443 — `WorkDetailView` and `CreditPersonDetailView` both
// render `[ExternalLink]` (`Work.links`/`CreditPerson.links`, the SAME
// shared shape `ExternalLink.swift`'s own header already documents as
// reused server-side across `tblSongExternalLinks`/
// `tblSongbookExternalLinks`/`tblCreditPersonExternalLinks`/
// `tblWorkExternalLinks`). Per the repo's modularity rule ("when a piece of
// UI exists in more than one place, extract it into a shared module"), this
// is that shared module for the Apple side — a plain wrapping row of
// `Link`s rather than a per-screen bespoke layout, kept deliberately simple
// (no icon-class → SF Symbol mapping yet, matching `ExternalLink.iconClass`'s
// own doc comment: "native UI maps a handful of known classes... Phase 1
// concern, not this DTO's job" — still true here, a generic glyph is used
// for every link).
import IHDesign
import IHModels
import SwiftUI

/// Renders a "Find this elsewhere" card of tappable external links —
/// nothing at all when `links` is empty, so callers can embed this
/// unconditionally.
struct ExternalLinksSection: View {
    let links: [ExternalLink]

    var body: some View {
        if !links.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("Find This Elsewhere")
                    .font(.headline)
                    .accessibilityAddTraits(.isHeader)

                // A simple wrapping flow of link buttons — a handful of
                // curated links per entity fits comfortably across a couple
                // of lines, the same posture `SongMetadataView.creditsSection`
                // already takes for its own tappable-name list.
                ForEach(links, id: \.slug) { link in
                    if let url = link.resolvedURL {
                        Link(destination: url) {
                            HStack(spacing: 6) {
                                Image(systemName: "arrow.up.right.square")
                                Text(link.note?.isEmpty == false ? "\(link.name) — \(link.note ?? "")" : link.name)
                                if link.verified {
                                    Image(systemName: "checkmark.seal.fill")
                                        .foregroundStyle(IHColorTokens.accent)
                                        .accessibilityLabel("Verified")
                                }
                            }
                            .font(.subheadline)
                        }
                    }
                }
            }
            .ihGlassCard()
        }
    }
}
