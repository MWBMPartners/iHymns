// SongWorksSection.swift
// IHFeatures
//
// ELI5: If this song is one songbook's version of a bigger "Work" (the same
// underlying hymn tune/composition shared across several books), this panel
// lists the other songbooks' versions.
//
// DETAILED: `.claude/CLAUDE.md` rule #14: "Songs that are part of a work
// render the 'Part of work' panel via the shared partial — don't roll your
// own grouping UI," mirrored here for the Apple app (#180, #840). Renders
// from `SongDetail.works: [Work]` (already modelled — see `Work.swift`),
// which every song this task sampled on `dev` returned empty (no curated
// Work groupings exist yet there), so `SongDetailView` only shows this
// panel `if !works.isEmpty` and it is, in practice, never visible today —
// the honest "render gracefully-empty" outcome for this sub-feature.
//
// #1443 UPDATE — the "Part of: <title>" heading is now a real
// `NavigationLink` into the standalone `WorkDetailView` (title/ISWC/notes/
// parent/children/EVERY member/links — richer than this inline panel's own
// member-sibling list), closing the "no native Work-detail screen" gap this
// file used to describe. Always fetches by SLUG (never reuses this
// embedded `Work` value directly) — see `WorkDetailView.swift`'s header for
// why: the embedded shape here omits `notes`/`parent`/`children`/timestamps
// entirely (`Work.swift`'s own #1443 fix comment), so a single "always
// fetch the full record" path is simpler than reconciling two shapes.
import IHDesign
import IHModels
import SwiftUI

/// Lists every Work this song belongs to, and that Work's other member
/// songs (excluding this song itself).
struct SongWorksSection: View {
    let works: [Work]
    let currentSongId: SongID
    let rootViewModel: AppRootViewModel

    var body: some View {
        ForEach(works) { work in
            VStack(alignment: .leading, spacing: 8) {
                NavigationLink(destination: WorkDetailView(slug: work.slug, rootViewModel: rootViewModel)) {
                    Text("Part of: \(work.title)")
                        .font(.headline)
                        // #188 — a heading so VoiceOver's heading rotor can
                        // jump between "Part of …" work groups (WCAG 1.3.1 /
                        // 2.4.1).
                        .accessibilityAddTraits(.isHeader)
                }

                ForEach(work.members.filter { $0.songId != currentSongId.rawValue }, id: \.songId) { member in
                    memberRow(member)
                }
            }
            .ihGlassCard()
        }
    }

    @ViewBuilder
    private func memberRow(_ member: WorkMember) -> some View {
        // `WorkMember.songId` is deliberately a plain `String`, not `SongID`
        // (see `Work.swift`'s doc comment — this corner of the contract is
        // unverified against a live non-empty payload) — a member whose id
        // doesn't parse degrades to plain, non-tappable text rather than
        // crashing or being silently dropped.
        let label = "\(member.songbookAbbreviation)\(member.number.map { " \($0)" } ?? "") — \(member.title)"
        if let memberId = SongID(rawValue: member.songId) {
            NavigationLink(value: memberId) {
                Text(label)
            }
        } else {
            Text(label)
        }
    }
}
