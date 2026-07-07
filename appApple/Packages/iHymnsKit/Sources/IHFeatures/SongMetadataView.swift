// SongMetadataView.swift
// IHFeatures
//
// ELI5: The "about this song" block under the title — which songbook and
// number, what language(s) it's in, who wrote/arranged it (tap a name to
// see other songs by them), and — tucked away, since most people don't need
// it — its CCLI/ISWC/royalty-society ids.
//
// DETAILED: #180's "Metadata + credits section: songbook + number +
// language badge(s); credit people (writer/composer/etc.) tappable;
// authority IDs behind a disclosure." Split out of `SongDetailView` to keep
// that file's own body short (this repo's LOC-budget discipline). Credit
// "tappability" is `CreditRelatedSongsSheet` (see that file's header for
// why it reuses `related_songs` rather than a fictional credit-person
// screen); the authority-ids `DisclosureGroup` only renders when there's
// genuinely at least one id to show, so a song with no ccli/iswc/royaltyIds
// shows nothing rather than an empty, pointless disclosure triangle.
import IHDesign
import IHModels
import SwiftUI

/// The songbook/number/language + credits + authority-ids block.
struct SongMetadataView: View {
    let detail: SongDetail
    let relatedSongs: [RelatedSongSummary]
    let rootViewModel: AppRootViewModel

    /// The credit name currently being looked up in the "more by X" sheet —
    /// `nil` when no sheet is presented.
    @State private var creditQuery: CreditQuery?

    /// A tiny `Identifiable` wrapper around a credit name, purely so
    /// `.sheet(item:)` (which requires `Identifiable`) can present one —
    /// kept file-private and wrapper-only rather than retroactively
    /// conforming `String` itself to `Identifiable`, which would be a
    /// public, cross-module-visible conformance a shared library target
    /// shouldn't add (risk of colliding with another dependency doing the
    /// same thing).
    private struct CreditQuery: Identifiable {
        let id: String
        var name: String { id }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            languageBadges
            creditsSection
            authorityIdsDisclosure
        }
        .sheet(item: $creditQuery) { query in
            CreditRelatedSongsSheet(name: query.name, allRelatedSongs: relatedSongs, rootViewModel: rootViewModel)
        }
    }

    /// One capsule badge per DISTINCT language actually present across the
    /// song's own `language` field and every component/line-level override
    /// — a song is usually single-language, but the schema allows a mixed
    /// component (e.g. a chorus repeated in a second language), and this
    /// surfaces that honestly rather than only ever showing `detail.language`.
    private var languageBadges: some View {
        let languages = Self.distinctLanguages(in: detail)
        return HStack(spacing: 6) {
            ForEach(languages, id: \.self) { language in
                Text(language.uppercased())
                    .font(.caption2.bold())
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(IHColorTokens.accent.opacity(0.15), in: .capsule)
                    .foregroundStyle(IHColorTokens.accent)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Language\(languages.count == 1 ? "" : "s"): \(languages.joined(separator: ", "))")
    }

    static func distinctLanguages(in detail: SongDetail) -> [String] {
        var seen = Set<String>()
        var ordered: [String] = []
        func add(_ language: String?) {
            guard let language, !language.isEmpty, seen.insert(language).inserted else { return }
            ordered.append(language)
        }
        add(detail.language)
        for component in detail.components {
            add(component.language)
            for lineLanguage in component.lineLanguages ?? [] {
                add(lineLanguage)
            }
        }
        return ordered
    }

    @ViewBuilder
    private var creditsSection: some View {
        let roles = Self.creditRoles(in: detail)
        if !roles.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                ForEach(roles, id: \.role) { role in
                    HStack(alignment: .top, spacing: 4) {
                        Text("\(role.role):")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        // A `Menu`-free flow layout of tappable names — kept
                        // as simple wrapping `Button`s rather than a custom
                        // flow layout, since a handful of names per role
                        // fits comfortably on one or two lines already.
                        ForEach(role.names, id: \.self) { name in
                            Button(name) { creditQuery = CreditQuery(id: name) }
                                .font(.caption.bold())
                                .buttonStyle(.plain)
                                .foregroundStyle(IHColorTokens.accent)
                        }
                    }
                }
            }
        }
    }

    /// `(role label, names)` pairs for every non-empty credit list —
    /// ordered writer/composer first since those are what `related_songs`'
    /// `reason` field actually reasons about (arranger/adaptor/translator/
    /// artist names are still shown and still tappable, they simply tend to
    /// produce an empty "no other songs yet" sheet today, since
    /// `related_songs` doesn't reason about those roles server-side).
    static func creditRoles(in detail: SongDetail) -> [(role: String, names: [String])] {
        [
            ("Writers", detail.writers),
            ("Composers", detail.composers),
            ("Arrangers", detail.arrangers),
            ("Adaptors", detail.adaptors),
            ("Translators", detail.translators),
            ("Artists", detail.artists)
        ].filter { !$0.names.isEmpty }
    }

    @ViewBuilder
    private var authorityIdsDisclosure: some View {
        let royaltyIds = detail.royaltyIds ?? []
        if !detail.ccli.isEmpty || !detail.iswc.isEmpty || !royaltyIds.isEmpty {
            DisclosureGroup("Song Identifiers") {
                VStack(alignment: .leading, spacing: 4) {
                    if !detail.ccli.isEmpty {
                        Text("CCLI SongSelect #: \(detail.ccli)")
                    }
                    if !detail.iswc.isEmpty {
                        Text("ISWC: \(detail.iswc)")
                    }
                    ForEach(Array(royaltyIds.enumerated()), id: \.offset) { _, royaltyId in
                        Text("\(royaltyId.authority): \(royaltyId.authorityId)")
                    }
                }
                .font(.caption)
                .foregroundStyle(.secondary)
            }
        }
    }
}
