// CreditPersonDetailView.swift
// IHFeatures
//
// ELI5: The "one writer/composer" screen — their name, a short bio and
// lifespan if we have one, links to find them elsewhere, and every song in
// the catalogue they're credited on, grouped by "As Writer," "As Composer,"
// and so on. This is where `/person/<slug>` links, and tapping a writer/
// composer name on a song, both land.
//
// DETAILED: THIS ONE SCREEN satisfies BOTH #1443 ("native detail screens
// for Work / credit-person / Live-join Universal Links" — the credit-person
// half) AND #1444 ("Apple: Writer/composer detail pages," #185's deferred
// sub-scope) — a writer/composer IS a `tblCreditPeople` credit-person; #1444
// itself says so explicitly ("Backend: a new lightweight public API
// endpoint... Apple: IHModels.CreditPerson DTO... CreditPersonDetailView").
// Building two near-identical screens for the same underlying entity would
// be exactly the "divergent copy" this repo's modularity rule bans.
//
// Replaces `CreditRelatedSongsSheet` (deleted alongside this file) — that
// sheet's own header was explicit it was a placeholder ("there is no
// dedicated credit-person detail screen/endpoint in the native app yet...
// that's a separate, unbuilt feature") standing in for exactly this screen.
// `SongMetadataView`'s tappable writer/composer names now push straight
// here via `.name(_:)` (the only identifier available from a plain credit
// string — see `CreditPersonLookup`'s own doc comment) instead of opening
// that substring-matched sheet.
import IHAPI
import IHAppSupport
import IHDesign
import IHModels
import SwiftUI

/// Displays one credit person's full record: bio/lifespan, external links,
/// and every song they're credited on, grouped by role.
public struct CreditPersonDetailView: View {
    @State private var viewModel: CreditPersonDetailViewModel
    private let rootViewModel: AppRootViewModel

    public init(lookup: CreditPersonLookup, rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
        _viewModel = State(initialValue: CreditPersonDetailViewModel(lookup: lookup, rootViewModel: rootViewModel))
    }

    public var body: some View {
        ScrollView {
            content
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle(navigationTitleText)
        .task { await viewModel.loadIfNeeded() }
        // `ShareLink` is UNAVAILABLE on tvOS (D-1, #1504's tvOS-build
        // gate) — no share-sheet destination on that platform, so the
        // whole toolbar is omitted there rather than forking a second one.
        #if !os(tvOS)
        .toolbar {
            if let shareURL {
                ToolbarItem(placement: .primaryAction) {
                    ShareLink(item: shareURL, subject: Text(navigationTitleText))
                }
            }
        }
        #endif
    }

    private var navigationTitleText: String {
        if case .loaded(let person) = viewModel.loadState {
            person.name
        } else {
            "Person"
        }
    }

    /// `nil` while loading/errored, AND when the loaded person has no
    /// `slug` yet (a name-only match with no `tblCreditPeople` registry
    /// row — there's no stable, shareable web URL for that case, per
    /// `CanonicalURL.person(slug:)`'s requirement of a real slug).
    private var shareURL: URL? {
        guard case .loaded(let person) = viewModel.loadState, let slug = person.slug, !slug.isEmpty else { return nil }
        return CanonicalURL.person(slug: slug)
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.loadState {
        case .idle, .loading:
            ProgressView("Loading…")
                .frame(maxWidth: .infinity, minHeight: 200)

        case .error(let message):
            IHLoadErrorView(title: "Couldn't Load Person", message: message) {
                await viewModel.load()
            }

        case .loaded(let person):
            loadedContent(person)
        }
    }

    @ViewBuilder
    private func loadedContent(_ person: CreditPerson) -> some View {
        VStack(alignment: .leading, spacing: 20) {
            header(for: person)
            ExternalLinksSection(links: person.links)

            ForEach(person.discography) { group in
                roleSection(group)
            }

            if person.discography.isEmpty {
                ContentUnavailableView(
                    "No Songs Yet",
                    systemImage: "person",
                    description: Text("We don't have any songs credited to \(person.name) yet.")
                )
            }
        }
    }

    private func header(for person: CreditPerson) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(person.name)
                .font(.largeTitle.bold())
                .italic(person.isSpecialCase)

            if let lifespan = Self.lifespanText(for: person) {
                Text(lifespan)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            if let place = person.birthPlace, !place.isEmpty {
                Label(
                    person.isGroup ? "Founded in \(place)" : "Born in \(place)",
                    systemImage: "mappin.and.ellipse"
                )
                .font(.caption)
                .foregroundStyle(.secondary)
            }

            if let notes = person.notes, !notes.isEmpty {
                Text(notes)
                    .font(.body)
                    .padding(.top, 4)
            }

            Text("\(person.totalSongs) song\(person.totalSongs == 1 ? "" : "s") in the catalogue")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    /// "1779–1847" / "b. 1779" / "d. 1847" for an individual, "Active
    /// 1971–1989" / "Active since 1971" / "Dissolved 1989" for a group —
    /// mirrors `includes/pages/person.php`'s `_personFormatLifespan()`
    /// wording exactly, computed client-side from the two raw date strings
    /// (`birthDate`/`deathDate`) rather than a server-sent precomputed
    /// field, since the server's own JSON contract (`SongData::
    /// getCreditPerson()`) doesn't send one.
    private static func lifespanText(for person: CreditPerson) -> String? {
        let birthYear = person.birthDate.flatMap { $0.count >= 4 ? String($0.prefix(4)) : nil }
        let deathYear = person.deathDate.flatMap { $0.count >= 4 ? String($0.prefix(4)) : nil }
        switch (birthYear, deathYear) {
        case (nil, nil):
            return nil
        case (let birth?, let death?):
            return person.isGroup ? "Active \(birth)–\(death)" : "\(birth)–\(death)"
        case (let birth?, nil):
            return person.isGroup ? "Active since \(birth)" : "b. \(birth)"
        case (nil, let death?):
            return person.isGroup ? "Dissolved \(death)" : "d. \(death)"
        }
    }

    /// One role's shelf of credited songs — pushes via
    /// `NavigationLink(destination:)` (a real `SongDetailView` embedded
    /// directly), the SAME "don't rely on an ancestor's inherited
    /// `.navigationDestination(for:)`" reasoning `WorkDetailView
    /// .memberSongsSection`'s own doc comment explains (this screen is
    /// equally reachable from a bare `DeepLinkDestinationView` stack).
    private func roleSection(_ group: CreditPersonRoleGroup) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("\(group.label) (\(group.songs.count))")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)

            ForEach(group.songs) { song in
                NavigationLink(destination: SongDetailView(songId: song.songId, rootViewModel: rootViewModel)) {
                    SongSummaryRow(SongSummary(
                        songId: song.songId,
                        title: song.title,
                        songbookAbbreviation: song.songbookAbbreviation,
                        number: song.number,
                        songbookName: song.songbookName
                    ))
                }
            }
        }
    }
}
