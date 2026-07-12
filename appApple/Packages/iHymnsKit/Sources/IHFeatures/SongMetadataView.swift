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
// that file's own body short (this repo's LOC-budget discipline). The
// authority-ids `DisclosureGroup` only renders when there's genuinely at
// least one id to show, so a song with no ccli/iswc/royaltyIds shows
// nothing rather than an empty, pointless disclosure triangle.
//
// #1443/#1444 UPDATE — credit "tappability" now pushes straight to the real
// `CreditPersonDetailView` (bio + full discography, looked up by NAME —
// `CreditPersonLookup.name(_:)`, since a plain credit string has no id)
// instead of opening `CreditRelatedSongsSheet` (deleted alongside this
// change — that sheet's own header called itself a stand-in for exactly
// this screen: "there is no dedicated credit-person detail screen/endpoint
// in the native app yet... that's a separate, unbuilt feature"). Dropped
// the `relatedSongs`/`.sheet` machinery that existed purely to feed that
// sheet — `CreditPersonDetailView` does its own fetch, no longer riding
// along on `SongDetailViewModel.relatedSongsState`.
import IHAPI
import IHDesign
import IHModels
import SwiftUI

/// The songbook/number/language + credits + authority-ids block.
struct SongMetadataView: View {
    let detail: SongDetail
    let rootViewModel: AppRootViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            languageBadges
            creditsSection
            authorityIdsDisclosure
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
                        // A flow of tappable names, each pushing straight to
                        // that person's real detail screen — kept as simple
                        // wrapping `NavigationLink`s rather than a custom
                        // flow layout, since a handful of names per role
                        // fits comfortably on one or two lines already.
                        ForEach(role.names, id: \.self) { name in
                            NavigationLink(
                                destination: CreditPersonDetailView(lookup: .name(name), rootViewModel: rootViewModel)
                            ) {
                                Text(name)
                                    .font(.caption.bold())
                                    .foregroundStyle(IHColorTokens.accent)
                            }
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
            // `DisclosureGroup` is UNAVAILABLE on tvOS (D-1, #1504's
            // tvOS-build gate) — shown always-expanded there instead of
            // collapsible (a metadata footnote, not worth a bespoke
            // expand/collapse affordance on a remote-driven focus UI). Also
            // UNAVAILABLE on watchOS (#1549) — same always-expanded
            // fallback applies there too.
            #if os(tvOS) || os(watchOS)
            VStack(alignment: .leading, spacing: 4) {
                Text("Song Identifiers").font(.headline)
                authorityIdsContent(royaltyIds: royaltyIds)
            }
            #else
            DisclosureGroup("Song Identifiers") {
                authorityIdsContent(royaltyIds: royaltyIds)
            }
            #endif
        }
    }

    @ViewBuilder
    private func authorityIdsContent(royaltyIds: [SongRoyaltyId]) -> some View {
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
