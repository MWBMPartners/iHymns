// LanguageDisplay.swift
// IHFeatures
//
// ELI5: Songs are tagged with short language codes such as "pt-BR" or
// "zh-Hant". A person should see "Portuguese (Brazil)" or "Chinese
// (Traditional)" instead, in their own device language, and lists of
// languages should read A to Z by that name. This file is the one place the
// app turns a code into a name and puts a list of codes in order.
//
// DETAILED: #2136, part of #2137 — the shared MWBM language policy
// (MWBM-MEDIA-LANG, docs/standards/media-language-bcp47-policy.md in the
// repository root). Its rules used here:
//   - UI-010: names come from the platform's own locale data, in the
//     interface language, with the script or region as a qualifier —
//     `Locale.localizedString(forIdentifier:)` does exactly that.
//   - UI-040: other languages follow alphabetically by that name, compared the
//     way the reader's language sorts; the special codes (several languages,
//     uncoded, not known, no language) come after every real language.
// What this deliberately does NOT do: it never changes a tag (matching a
// filter to a song still compares the tags exactly, as before), and it does
// not know the reader's language preferences (the app has no such setting
// yet), so UI-020's "your languages first" does not apply here.
import Foundation

/// How the app names and orders language tags for a person (#2136).
public enum LanguageDisplay {
    /// The special codes that go after every real language, in the policy's
    /// fixed order (LANG-025): several languages, a language with no code,
    /// not known, no language.
    static let trailingCodes: [String] = ["mul", "mis", "und", "zxx"]

    /// The name of a language tag in the reader's interface language, e.g.
    /// "pt-BR" → "Portuguese (Brazil)". Falls back to the tag itself when the
    /// platform has no name for it, so nothing is ever shown blank.
    ///
    /// - Parameters:
    ///   - tag: A BCP 47 language tag.
    ///   - locale: The interface language (the device's, unless a test says otherwise).
    public static func name(for tag: String, locale: Locale = .current) -> String {
        let trimmed = tag.trimmingCharacters(in: .whitespaces)
        guard !trimmed.isEmpty else { return "" }
        return locale.localizedString(forIdentifier: trimmed) ?? trimmed
    }

    /// Language tags in the order a person should see them: alphabetically by
    /// name in the interface language ("Afrikaans, Chinese, Chinese
    /// (Traditional), English…"), then the special codes in the policy's
    /// fixed order. Ties (two tags with the same name) fall back to the tag,
    /// so the order is the same on every run.
    public static func sortedForDisplay(_ tags: [String], locale: Locale = .current) -> [String] {
        tags.sorted { lhs, rhs in
            let lhsRank = trailingRank(lhs)
            let rhsRank = trailingRank(rhs)
            if lhsRank != rhsRank { return lhsRank < rhsRank }
            let order = name(for: lhs, locale: locale).compare(
                name(for: rhs, locale: locale),
                options: [.caseInsensitive, .diacriticInsensitive],
                range: nil,
                locale: locale
            )
            if order != .orderedSame { return order == .orderedAscending }
            return lhs < rhs
        }
    }

    /// 0 for an ordinary language; 1… for the special codes, in their fixed order.
    static func trailingRank(_ tag: String) -> Int {
        let primary = tag.split(separator: "-", maxSplits: 1).first.map { $0.lowercased() } ?? ""
        if let index = trailingCodes.firstIndex(of: primary) { return index + 1 }
        return 0
    }
}
