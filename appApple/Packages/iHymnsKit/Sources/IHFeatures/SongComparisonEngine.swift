// SongComparisonEngine.swift
// IHFeatures
//
// ELI5: The brains behind "compare two songs" — it lines up verse 1 with
// verse 1, chorus with chorus (even if the two hymnals put them in a
// different order), and works out which lines are actually WORDED
// differently versus just punctuated differently. It also decides whether
// the comparison screen should show both songs side by side or as two
// swipeable pages, depending on how wide the screen is.
//
// DETAILED: #1445 (Apple Universal app — song comparison view,
// `.claude/apple-comparison-usage-plan.md` §1.2). A PURE, `Sendable`,
// side-effect-free namespace — no view, no `@Observable`, no network —
// mirroring `SongNavigationContext.swift`'s own "pure shared shape, not a
// view" precedent in this same directory, so the pairing/normalisation/
// layout logic is trivially unit-testable (`SongComparisonEngineTests`)
// without spinning up any SwiftUI machinery.
//
// WHY THIS ISN'T A FORK OF `includes/song_similarity.php` (CLAUDE.md rule
// #22): that file is server-side PHP *similarity scoring* for duplicate
// DETECTION (levenshtein/Jaccard/blend, "is this probably the same hymn?")
// — a different job from rendering a per-line equal/not-equal flag once the
// user has ALREADY told us which two songs to compare, and it isn't
// callable per-line from an offline Swift client anyway. `normalise(_:)`
// below does no scoring, no distance metric, no blending — just an exact
// equality check on two already-folded strings — so rule #22 (never re-fork
// the similarity maths) doesn't apply here.
//
// Pairing algorithm (`pairs(primary:secondary:)`):
//   1. Iterate `primary.orderedComponents` — NEVER `primary.components`,
//      since `SongDetail.orderedComponents` is the arrangement-aware
//      property every renderer must use (see that property's own doc
//      comment on the #180 bug it fixed).
//   2. Key = `(type.lowercased(), number)`. Each primary component is
//      matched to the FIRST not-yet-consumed secondary component sharing
//      that key, so verse 1 lines up with verse 1 even when the two books
//      interleave/reorder their components differently.
//   3. An unmatched primary component pairs with `secondary: nil` ("only in
//      the primary song").
//   4. Any secondary components still unconsumed after that pass are
//      appended AFTER, in the secondary's own order, with `primary: nil`
//      ("only in the secondary song").
//   5. Lines within a both-sides pair are compared BY INDEX (hymn
//      counterpart verses almost always line up positionally; an LCS
//      alignment would be deliberate over-engineering for v1).
//
// #1454 UPDATE — word-level diff (`wordDiff(primaryLine:secondaryLine:)`).
// Once `pairs(primary:secondary:)` has already flagged a line pair as
// differing (`ComparisonComponentPair.differingLineIndices`), this answers
// the finer-grained "which WORDS actually changed?" question for that one
// line pair. Deliberately NOT folded into `pairs(...)` itself — computing a
// word diff for every line up front would waste work on the (common) case
// where the user has the line-level toggle on but never even looks closely
// enough to need word granularity, and `SongComparisonViewModel.pairs`'
// own header already establishes the "recompute on demand, it's cheap"
// posture this mirrors.
//
// Algorithm: tokenise both lines on whitespace (`tokenize(_:)`, real text —
// punctuation intact, so the rendered highlight shows exactly what the user
// typed), then align them with a common-prefix + common-suffix scan — NOT a
// full Myers/LCS diff, per this issue's own "a simple LCS or common-prefix/
// suffix + middle-diff is fine" allowance. Token EQUALITY during that scan
// reuses `normalise(_:)` (applied per-token) — the SAME fold the line-level
// comparison already uses — so "grace," vs "grace" is never flagged as a
// word-level change just because line-level already treats it as identical.
// Every token strictly between the matched prefix and matched suffix is
// marked `.changed` on ITS OWN side (the two sides can, and usually do, have
// different word counts there); every prefix/suffix token is `.unchanged`.
// This correctly localises a single swapped word in the middle of a line,
// and an inserted/deleted word at either end — see
// `SongComparisonEngineTests`' word-diff cases for both.
import Foundation
import IHModels
import SwiftUI

/// One aligned row in a comparison: the same logical component (e.g. "Verse
/// 1") from each song, or `nil` on whichever side doesn't have it.
///
/// ELI5: "Here's verse 1 from song A, and verse 1 from song B, side by
/// side — and here's which of their lines don't match."
public struct ComparisonComponentPair: Sendable, Hashable, Identifiable {
    /// A stable ordinal in the pair list — NOT a `tblLyricLines.Id` or any
    /// server identity, just this row's position, since a pair can exist
    /// with neither side sharing a real database key (e.g. secondary-only).
    public let id: Int

    /// `nil` when this logical component ("Verse 3", "Chorus") exists in
    /// the secondary song but not the primary one.
    public let primary: SongComponent?

    /// `nil` when this logical component exists in the primary song but not
    /// the secondary one.
    public let secondary: SongComponent?

    /// Indices into `primary.lines` whose normalised text differs from the
    /// secondary's line at the SAME index — plus, when `primary` has MORE
    /// lines than `secondary`, every trailing primary-only index (there is
    /// no secondary counterpart to compare against, so it trivially
    /// "differs"). Empty when either side is `nil` (nothing to compare) or
    /// the two components' lines already match.
    public let differingLineIndices: Set<Int>

    /// Indices into `secondary.lines` that exist ONLY because the secondary
    /// component has MORE lines than the primary one — the secondary's own
    /// trailing overflow, distinct from `differingLineIndices` (which lives
    /// in the primary's index space). Empty whenever `secondary.lines.count
    /// <= primary.lines.count`.
    public let secondaryOnlyLineIndices: Set<Int>

    public init(
        id: Int,
        primary: SongComponent?,
        secondary: SongComponent?,
        differingLineIndices: Set<Int>,
        secondaryOnlyLineIndices: Set<Int>
    ) {
        self.id = id
        self.primary = primary
        self.secondary = secondary
        self.differingLineIndices = differingLineIndices
        self.secondaryOnlyLineIndices = secondaryOnlyLineIndices
    }
}

/// One token of a word-level line diff (#1454) — either it matches the
/// counterpart line's token at the aligned position (`.unchanged`) or it's
/// part of the span that differs (`.changed`). Carries the REAL token text
/// (not the normalised fold), so the rendered highlight shows the user's
/// actual wording, punctuation and all.
///
/// ELI5: "This word is the same in both versions" vs "this word is where
/// they differ."
public enum WordDiffSegment: Sendable, Hashable {
    case unchanged(String)
    case changed(String)
}

/// Whether `SongComparisonView` should render its two songs as one
/// side-by-side grid or as two swipeable/segmented pages.
///
/// ELI5: "Is the screen wide enough to show both songs at once?"
public enum ComparisonLayout: Sendable, Equatable {
    /// One `ScrollView` of paired component rows in a two-column grid —
    /// the paired-grid alignment itself IS the scroll-sync (see this file's
    /// header/the strategy doc's §1.1 "alignment IS the scroll-sync").
    case sideBySide

    /// A segmented A/B picker + `TabView(.page)`, one song per page.
    case paged

    /// Mirrors `RootContainerView.platformRoot`'s exact split: macOS/
    /// visionOS (which report no size class, `nil`) and iPad's REGULAR
    /// width are always side-by-side; a COMPACT iPhone width is paged.
    ///
    /// ELI5: "Compact width (a normal iPhone) → two pages you swipe
    /// between. Anything else → both songs on screen together."
    public static func layout(horizontalSizeClass: UserInterfaceSizeClass?) -> ComparisonLayout {
        horizontalSizeClass == .compact ? .paged : .sideBySide
    }
}

/// The pure pairing + normalisation engine behind the song comparison
/// screen — see this file's header for the full pairing algorithm.
public enum SongComparisonEngine {
    /// Builds the ordered list of aligned component pairs for comparing
    /// `primary` against `secondary`. See this file's header for the
    /// step-by-step algorithm.
    public static func pairs(primary: SongDetail, secondary: SongDetail) -> [ComparisonComponentPair] {
        var remainingSecondary: [SongComponent?] = secondary.orderedComponents
        var result: [ComparisonComponentPair] = []
        var nextId = 0

        for primaryComponent in primary.orderedComponents {
            let primaryKey = key(for: primaryComponent)
            if let matchIndex = remainingSecondary.firstIndex(where: { $0.map(key(for:)) == primaryKey }) {
                let matched = remainingSecondary[matchIndex]
                remainingSecondary[matchIndex] = nil
                result.append(makePair(id: nextId, primary: primaryComponent, secondary: matched))
            } else {
                result.append(makePair(id: nextId, primary: primaryComponent, secondary: nil))
            }
            nextId += 1
        }

        // Whatever secondary components never got consumed above are
        // "only in the secondary song" rows, appended in the secondary's
        // own original order.
        for leftover in remainingSecondary {
            guard let leftover else { continue }
            result.append(makePair(id: nextId, primary: nil, secondary: leftover))
            nextId += 1
        }

        return result
    }

    /// Folds a line down to its "meaningfully different text" shape:
    /// lowercased, diacritic-folded, every punctuation/symbol character
    /// stripped, runs of whitespace collapsed to one space, trimmed. So
    /// "Amazing grace! how sweet the sound," and "Amazing grace, how sweet
    /// the sound" normalise identically — a punctuation-only edit never
    /// lights up as a difference.
    ///
    /// ELI5: "Strip away punctuation/accents/extra spaces so we're only
    /// comparing the actual WORDS."
    public static func normalise(_ line: String) -> String {
        let folded = line.folding(options: [.caseInsensitive, .diacriticInsensitive], locale: nil)
        let keptScalars = folded.unicodeScalars.filter { scalar in
            !CharacterSet.punctuationCharacters.contains(scalar) && !CharacterSet.symbols.contains(scalar)
        }
        let stripped = String(String.UnicodeScalarView(keptScalars))
        let words = stripped.components(separatedBy: .whitespacesAndNewlines).filter { !$0.isEmpty }
        return words.joined(separator: " ")
    }

    /// Splits `line` into its real, whitespace-separated words — punctuation
    /// stays attached to whichever word it was next to (this is a display
    /// tokeniser, not a normalising one; `normalise(_:)` is applied
    /// SEPARATELY, per-token, wherever `wordDiff` needs to compare two
    /// tokens for equality).
    ///
    /// ELI5: "Break this lyric line into its individual words."
    public static func tokenize(_ line: String) -> [String] {
        line.split(whereSeparator: \.isWhitespace).map(String.init)
    }

    /// Word-level diff between two lines ALREADY known to differ at the
    /// line level (`ComparisonComponentPair.differingLineIndices`) — see
    /// this file's header for the common-prefix/common-suffix algorithm.
    /// Returns one `[WordDiffSegment]` per side, in that side's own
    /// original token order.
    ///
    /// ELI5: "Here are the two versions of this line — tell me exactly
    /// which words differ, on each side."
    public static func wordDiff(primaryLine: String, secondaryLine: String) -> (primary: [WordDiffSegment], secondary: [WordDiffSegment]) {
        let primaryTokens = tokenize(primaryLine)
        let secondaryTokens = tokenize(secondaryLine)
        let sharedCount = min(primaryTokens.count, secondaryTokens.count)

        var prefixLength = 0
        while prefixLength < sharedCount, normalise(primaryTokens[prefixLength]) == normalise(secondaryTokens[prefixLength]) {
            prefixLength += 1
        }

        // Bounded by `sharedCount - prefixLength` so the prefix and suffix
        // scans can never overlap the same token twice.
        var suffixLength = 0
        while suffixLength < (sharedCount - prefixLength),
              normalise(primaryTokens[primaryTokens.count - 1 - suffixLength]) == normalise(secondaryTokens[secondaryTokens.count - 1 - suffixLength]) {
            suffixLength += 1
        }

        return (
            primary: wordDiffSegments(for: primaryTokens, prefixLength: prefixLength, suffixLength: suffixLength),
            secondary: wordDiffSegments(for: secondaryTokens, prefixLength: prefixLength, suffixLength: suffixLength)
        )
    }

    /// Marks every token of `tokens` outside the matched `[0..<prefixLength)`
    /// / `[tokens.count - suffixLength..<tokens.count)` bookends as
    /// `.changed` — the shared middle-marking step `wordDiff(primaryLine:
    /// secondaryLine:)` applies identically to each side.
    private static func wordDiffSegments(for tokens: [String], prefixLength: Int, suffixLength: Int) -> [WordDiffSegment] {
        let changedUpperBound = tokens.count - suffixLength
        return tokens.enumerated().map { index, token in
            (index < prefixLength || index >= changedUpperBound) ? .unchanged(token) : .changed(token)
        }
    }

    /// The `(type, number)` match key two components must share to be
    /// considered "the same logical part" — case-insensitive since
    /// `SongComponent.type` is an open, free-text vocabulary (e.g. "Chorus"
    /// vs "chorus" must still match).
    private static func key(for component: SongComponent) -> String {
        "\(component.type.lowercased())#\(component.number)"
    }

    /// Builds one pair, computing its line-level diff sets when both sides
    /// are present. See `ComparisonComponentPair`'s own doc comments for
    /// exactly what each set means.
    private static func makePair(id: Int, primary: SongComponent?, secondary: SongComponent?) -> ComparisonComponentPair {
        guard let primary, let secondary else {
            return ComparisonComponentPair(
                id: id,
                primary: primary,
                secondary: secondary,
                differingLineIndices: [],
                secondaryOnlyLineIndices: []
            )
        }

        let sharedCount = min(primary.lines.count, secondary.lines.count)
        var differing: Set<Int> = []
        for index in 0..<sharedCount where normalise(primary.lines[index]) != normalise(secondary.lines[index]) {
            differing.insert(index)
        }
        // Primary lines beyond the secondary's count have no counterpart to
        // compare against at all — they trivially "differ."
        if primary.lines.count > secondary.lines.count {
            differing.formUnion(secondary.lines.count..<primary.lines.count)
        }

        var secondaryOnly: Set<Int> = []
        if secondary.lines.count > primary.lines.count {
            secondaryOnly.formUnion(primary.lines.count..<secondary.lines.count)
        }

        return ComparisonComponentPair(
            id: id,
            primary: primary,
            secondary: secondary,
            differingLineIndices: differing,
            secondaryOnlyLineIndices: secondaryOnly
        )
    }
}
