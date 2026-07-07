// LineEnrichmentIndex.swift
// IHFeatures
//
// ELI5: Given one song's full record, this quickly answers "does THIS
// specific lyric line have a translation or a footnote attached?" and "what
// are they?" — instead of every view that needs the answer re-scanning the
// whole song's enrichment arrays itself.
//
// DETAILED: A plain, `Sendable`, pure-computation struct (deliberately NOT
// an `@Observable`/actor type — it's built once from an already-loaded
// `SongDetail` and never mutates) — the per-line enrichment feature (#180,
// long-press a lyric line → `LyricLineEnrichmentSheet`) needs this lookup
// from TWO places (`SongComponentView`'s per-line long-press affordance
// AND `SongDetailView`'s sheet content), so it's extracted here rather than
// duplicated. Built from `SongDetail.translations`' `.perLine` case (see
// `SongDetailTranslationsField`'s doc comment for why that's not always the
// shape present) and `SongDetail.annotations`.
import IHModels

/// A one-shot lookup from `tblLyricLines.Id` to whatever translation/
/// romanization and annotation rows apply to that line.
///
/// ELI5: "For this exact line, here's its translation and its footnote (if
/// it has either)."
public struct LineEnrichmentIndex: Sendable {
    private let translationsByLine: [Int: [LyricLineTranslation]]
    private let annotationsByLine: [Int: [LyricLineAnnotation]]

    /// An empty index — every lookup below returns nothing. The default a
    /// screen renders with before its `SongDetail` has loaded.
    public static let empty = LineEnrichmentIndex(translationsByLine: [:], annotationsByLine: [:])

    private init(translationsByLine: [Int: [LyricLineTranslation]], annotationsByLine: [Int: [LyricLineAnnotation]]) {
        self.translationsByLine = translationsByLine
        self.annotationsByLine = annotationsByLine
    }

    /// Builds the index from one loaded song's full record.
    ///
    /// ELI5: "Read this whole song's translations and footnotes once, and
    /// sort them by which line they belong to."
    public init(detail: SongDetail) {
        var translationsByLine: [Int: [LyricLineTranslation]] = [:]
        if case .perLine(let rows)? = detail.translations {
            for row in rows {
                translationsByLine[row.lineId, default: []].append(row)
            }
        }

        var annotationsByLine: [Int: [LyricLineAnnotation]] = [:]
        for annotation in detail.annotations ?? [] {
            let endLineId = annotation.endLineId ?? annotation.startLineId
            guard endLineId >= annotation.startLineId else { continue }
            // Defensive cap: `tblLyricLineAnnotations`'s schema models a
            // genuine multi-line span (verse/chorus-sized, realistically a
            // handful of lines), never hundreds — guards against a
            // corrupt/adversarial `endLineId` turning this into an
            // unbounded loop.
            guard endLineId - annotation.startLineId < 500 else { continue }
            for lineId in annotation.startLineId...endLineId {
                annotationsByLine[lineId, default: []].append(annotation)
            }
        }

        self.init(translationsByLine: translationsByLine, annotationsByLine: annotationsByLine)
    }

    /// The translation/romanization rows attached to `lineId`, if any.
    public func translations(forLineId lineId: Int) -> [LyricLineTranslation] {
        translationsByLine[lineId] ?? []
    }

    /// The annotations covering `lineId` (including multi-line spans that
    /// merely pass through it), if any.
    public func annotations(forLineId lineId: Int) -> [LyricLineAnnotation] {
        annotationsByLine[lineId] ?? []
    }

    /// Whether `lineId` has ANY enrichment at all — the check
    /// `SongComponentView` uses to decide whether a line's long-press
    /// affordance should do anything.
    ///
    /// ELI5: "Is there anything to show if I long-press this line?"
    public func hasEnrichment(forLineId lineId: Int) -> Bool {
        !(translationsByLine[lineId] ?? []).isEmpty || !(annotationsByLine[lineId] ?? []).isEmpty
    }
}
