// WorkDetailView.swift
// IHFeatures
//
// ELI5: The "one composition" screen — a Work's title, its ISWC if it has
// one, notes, any parent/child groupings, every songbook's version of it,
// and links to find it elsewhere. This is where `/work/<slug>` links (and
// tapping "Part of: <title>" on a song) land.
//
// DETAILED: #1443 — closes the "no native Work-detail screen" gap
// `SongWorksSection.swift`'s own header used to describe (that file only
// ever showed a work's SIBLING songs inline; this is the standalone page).
// Fetched via `?action=work&slug=…` (new, `WorkAndCreditPersonEndpoints.swift`)
// rather than reused from any embedded `SongDetail.works[]` entry, even when
// arriving via a tap from `SongWorksSection` — a SINGLE fetch path (always
// by slug) is simpler than reconciling two different partial shapes (the
// embedded shape omits `notes`/`parent`/`children`/timestamps entirely; see
// `Work.swift`'s #1443 header comment), and this screen wants the FULL
// record either way. Mirrors `SongDetailView`'s `LoadState`-driven shape.
import IHAppSupport
import IHDesign
import IHModels
import SwiftUI

/// Displays one Work's full record: title, ISWC/notes, parent/child
/// groupings, every member song, and external links.
public struct WorkDetailView: View {
    @State private var viewModel: WorkDetailViewModel
    private let rootViewModel: AppRootViewModel
    private let slug: String

    public init(slug: String, rootViewModel: AppRootViewModel) {
        self.slug = slug
        self.rootViewModel = rootViewModel
        _viewModel = State(initialValue: WorkDetailViewModel(slug: slug, rootViewModel: rootViewModel))
    }

    public var body: some View {
        ScrollView {
            content
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle(navigationTitleText)
        .task { await viewModel.loadIfNeeded() }
        .toolbar {
            if let shareURL {
                ToolbarItem(placement: .primaryAction) {
                    ShareLink(item: shareURL, subject: Text(navigationTitleText))
                }
            }
        }
    }

    private var navigationTitleText: String {
        if case .loaded(let work) = viewModel.loadState {
            work.title
        } else {
            "Work"
        }
    }

    private var shareURL: URL? {
        guard case .loaded(let work) = viewModel.loadState else { return nil }
        return CanonicalURL.work(slug: work.slug)
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.loadState {
        case .idle, .loading:
            ProgressView("Loading work…")
                .frame(maxWidth: .infinity, minHeight: 200)

        case .error(let message):
            IHLoadErrorView(title: "Couldn't Load Work", message: message) {
                await viewModel.load()
            }

        case .loaded(let work):
            loadedContent(work)
        }
    }

    @ViewBuilder
    private func loadedContent(_ work: Work) -> some View {
        VStack(alignment: .leading, spacing: 20) {
            header(for: work)

            if let parent = work.parent {
                NavigationLink(destination: WorkDetailView(slug: parent.slug, rootViewModel: rootViewModel)) {
                    Label("Part of a larger work: \(parent.title)", systemImage: "arrow.up.forward.circle")
                }
                .ihGlassCard()
            }

            if !work.children.isEmpty {
                childWorksSection(work.children)
            }

            if !work.members.isEmpty {
                memberSongsSection(work.members)
            }

            ExternalLinksSection(links: work.links)
        }
    }

    private func header(for work: Work) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(work.title)
                .font(.largeTitle.bold())

            if let iswc = work.iswc, !iswc.isEmpty {
                Text("ISWC: \(iswc)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            if let notes = work.notes, !notes.isEmpty {
                Text(notes)
                    .font(.body)
                    .padding(.top, 4)
            }
        }
    }

    /// Direct child Works (one level — a deeper tree is walked by tapping
    /// through, matching `Work.children`'s own "not eagerly nested
    /// infinitely" doc comment).
    private func childWorksSection(_ children: [WorkSummary]) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Includes")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)

            ForEach(children) { child in
                NavigationLink(destination: WorkDetailView(slug: child.slug, rootViewModel: rootViewModel)) {
                    Text(child.title)
                }
            }
        }
        .ihGlassCard()
    }

    /// Every songbook's version of this Work — reuses `SongSummaryRow` via a
    /// synthesized `SongSummary` (the same number/title/songbook shape,
    /// just built from `WorkMember`'s slightly different field set — see
    /// `SongSummaryRow`'s own header on why every song list in this app
    /// shares this one row rather than each screen drawing its own).
    ///
    /// DETAILED: Pushes via `NavigationLink(destination:)` (embedding a real
    /// `SongDetailView` directly) rather than `NavigationLink(value:
    /// songID)` + relying on some ANCESTOR stack's `.navigationDestination(
    /// for: SongID.self)` — this screen can be reached from a
    /// `DeepLinkDestinationView`'s own fresh `NavigationStack` (a
    /// `/work/<slug>` Universal Link), which registers no such destination
    /// (its `.song` case already returns a `SongDetailView` directly, never
    /// via a value-based push), so relying on inherited registration would
    /// silently fail to navigate from exactly that entry point.
    private func memberSongsSection(_ members: [WorkMember]) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Songbook Versions")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)

            ForEach(members, id: \.songId) { member in
                if let songID = SongID(rawValue: member.songId) {
                    NavigationLink(destination: SongDetailView(songId: songID, rootViewModel: rootViewModel)) {
                        SongSummaryRow(SongSummary(
                            songId: songID,
                            title: member.title,
                            songbookAbbreviation: member.songbookAbbreviation,
                            number: member.number,
                            songbookName: member.songbookName
                        ))
                    }
                } else {
                    // A member whose id doesn't parse degrades to plain,
                    // non-tappable text — same defensive posture
                    // `SongWorksSection.memberRow` already takes for the
                    // identical never-live-verified `WorkMember.songId`
                    // corner (see `Work.swift`'s doc comment).
                    Text("\(member.songbookAbbreviation)\(member.number.map { " \($0)" } ?? "") — \(member.title)")
                }
            }
        }
    }
}
