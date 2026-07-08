// SongComparisonEngineWordDiffTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "which WORDS actually changed?" fine-grained diff (#1454)
// gets it right — identical lines light up nothing, a single swapped word in
// the middle is pinpointed exactly, a whole changed phrase is marked as one
// span, and a word only present on one side (an insertion/deletion at either
// end of the line) is flagged on the correct side only.
//
// DETAILED: Split out of `SongComparisonEngineTests.swift` (rather than
// grown inside it) purely to stay under the repo's SwiftLint
// `type_body_length` ceiling now that the pairing/normalisation/layout
// suite plus a full word-diff suite would otherwise land in one `@Suite`
// struct — the same "one focused file per concern" reasoning
// `OfflineStore+MediaCache.swift`'s own header already documents for a
// production file, applied here to a test file. `SongComparisonEngine`
// itself is untouched by this split; both suites still exercise the SAME
// pure, `Sendable`, side-effect-free type.
import Testing
@testable import IHFeatures

@Suite("SongComparisonEngine word diff (#1454)")
struct SongComparisonEngineWordDiffTests {

    // MARK: - tokenize(_:)

    @Test("tokenize splits on whitespace and keeps punctuation attached to each word")
    func tokenizeSplitsOnWhitespace() {
        #expect(SongComparisonEngine.tokenize("Amazing grace, how sweet") == ["Amazing", "grace,", "how", "sweet"])
    }

    @Test("tokenize collapses runs of whitespace and trims the ends")
    func tokenizeCollapsesWhitespace() {
        #expect(SongComparisonEngine.tokenize("  Amazing   grace  ") == ["Amazing", "grace"])
    }

    @Test("tokenize on an empty line returns no tokens")
    func tokenizeEmptyLine() {
        #expect(SongComparisonEngine.tokenize("").isEmpty)
    }

    // MARK: - wordDiff(primaryLine:secondaryLine:)

    @Test("wordDiff on identical lines highlights nothing")
    func wordDiffIdenticalLinesHaveNoHighlights() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "Amazing grace how sweet", secondaryLine: "Amazing grace how sweet")
        #expect(diff.primary.allSatisfy { if case .unchanged = $0 { return true }; return false })
        #expect(diff.secondary.allSatisfy { if case .unchanged = $0 { return true }; return false })
    }

    @Test("wordDiff on a punctuation-only difference highlights nothing — matches the line-level normalise fold")
    func wordDiffPunctuationOnlyHasNoHighlights() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "Amazing grace, how sweet", secondaryLine: "Amazing grace how sweet")
        #expect(diff.primary == [.unchanged("Amazing"), .unchanged("grace,"), .unchanged("how"), .unchanged("sweet")])
        #expect(diff.secondary == [.unchanged("Amazing"), .unchanged("grace"), .unchanged("how"), .unchanged("sweet")])
    }

    @Test("wordDiff on one changed word in the middle flags only that word, on each side")
    func wordDiffSingleMiddleWordChange() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "Amazing grace how sweet", secondaryLine: "Amazing love how sweet")
        #expect(diff.primary == [.unchanged("Amazing"), .changed("grace"), .unchanged("how"), .unchanged("sweet")])
        #expect(diff.secondary == [.unchanged("Amazing"), .changed("love"), .unchanged("how"), .unchanged("sweet")])
    }

    @Test("wordDiff flags a whole span of changed words in the middle, not just one")
    func wordDiffMultiWordMiddleSpan() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "the quick brown fox jumps", secondaryLine: "the slow lazy fox jumps")
        #expect(diff.primary == [.unchanged("the"), .changed("quick"), .changed("brown"), .unchanged("fox"), .unchanged("jumps")])
        #expect(diff.secondary == [.unchanged("the"), .changed("slow"), .changed("lazy"), .unchanged("fox"), .unchanged("jumps")])
    }

    @Test("wordDiff flags a trailing word only present on the secondary side (insertion at the end)")
    func wordDiffInsertionAtEnd() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "the quick brown fox", secondaryLine: "the quick brown fox jumps")
        #expect(diff.primary == [.unchanged("the"), .unchanged("quick"), .unchanged("brown"), .unchanged("fox")])
        #expect(diff.secondary == [.unchanged("the"), .unchanged("quick"), .unchanged("brown"), .unchanged("fox"), .changed("jumps")])
    }

    @Test("wordDiff flags a leading word only present on the primary side (deletion at the start)")
    func wordDiffDeletionAtStart() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "oh the quick brown fox", secondaryLine: "the quick brown fox")
        #expect(diff.primary == [.changed("oh"), .unchanged("the"), .unchanged("quick"), .unchanged("brown"), .unchanged("fox")])
        #expect(diff.secondary == [.unchanged("the"), .unchanged("quick"), .unchanged("brown"), .unchanged("fox")])
    }

    @Test("wordDiff on two completely different lines flags every word on both sides")
    func wordDiffCompletelyDifferentLines() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "Amazing grace", secondaryLine: "Holy holy holy")
        #expect(diff.primary == [.changed("Amazing"), .changed("grace")])
        #expect(diff.secondary == [.changed("Holy"), .changed("holy"), .changed("holy")])
    }

    @Test("wordDiff against an empty line flags every word on the non-empty side, nothing on the empty side")
    func wordDiffAgainstEmptyLine() {
        let diff = SongComparisonEngine.wordDiff(primaryLine: "Amazing grace", secondaryLine: "")
        #expect(diff.primary == [.changed("Amazing"), .changed("grace")])
        #expect(diff.secondary.isEmpty)
    }
}
