// LyricLineEnrichmentSheet.swift
// IHFeatures
//
// ELI5: The little glass card that pops up when you long-press a lyric
// line — shows any translation/pronunciation-guide and any footnote for
// that exact line.
//
// DETAILED: #180's "long-press a lyric line → a glass sheet showing
// translation/romanization/annotation for that line." Presented via
// `SongDetailView`'s `.sheet(item:)` (see `SelectedLine` there) — a plain,
// stateless `View` given already-resolved content (the line's text plus its
// `LineEnrichmentIndex` lookups) rather than re-fetching or re-deriving
// anything itself. As of this task, no song on `dev` has either kind of
// enrichment populated yet (see `LineEnrichmentIndex.swift`'s header), so
// this view's non-empty-content branches are exercised by
// `LyricLineEnrichmentSheetTests` with hand-built data, not a live pull —
// the empty-content branch (`ContentUnavailableView`) is what every real
// song currently shows.
import IHDesign
import IHModels
import SwiftUI

/// Shows one lyric line's translations/romanizations and annotations.
public struct LyricLineEnrichmentSheet: View {
    @Environment(\.dismiss) private var dismiss

    let lineText: String
    let translations: [LyricLineTranslation]
    let annotations: [LyricLineAnnotation]

    public init(lineText: String, translations: [LyricLineTranslation], annotations: [LyricLineAnnotation]) {
        self.lineText = lineText
        self.translations = translations
        self.annotations = annotations
    }

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text(lineText)
                        .font(.title3.bold())
                        .fixedSize(horizontal: false, vertical: true)

                    if translations.isEmpty && annotations.isEmpty {
                        ContentUnavailableView(
                            "No Extra Details",
                            systemImage: "text.bubble",
                            description: Text("This line has no translation or annotation yet.")
                        )
                    } else {
                        translationsSection
                        annotationsSection
                    }
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .navigationTitle("Line Details")
            #if os(iOS) || os(visionOS)
            .navigationBarTitleDisplayMode(.inline)
            #endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }

    @ViewBuilder
    private var translationsSection: some View {
        if !translations.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("Translations & Romanizations")
                    .font(.caption.smallCaps().bold())
                    .foregroundStyle(IHColorTokens.accent)

                ForEach(Array(translations.enumerated()), id: \.offset) { _, translation in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(translation.text)
                        Text("\(translation.kind.capitalized) · \(translation.targetLanguage)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .ihGlassCard()
        }
    }

    @ViewBuilder
    private var annotationsSection: some View {
        if !annotations.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("Annotations")
                    .font(.caption.smallCaps().bold())
                    .foregroundStyle(IHColorTokens.accent)

                ForEach(Array(annotations.enumerated()), id: \.offset) { _, annotation in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(annotation.body)
                        HStack(spacing: 4) {
                            Text(annotation.annotationType.capitalized)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            if annotation.isVerified {
                                Image(systemName: "checkmark.seal.fill")
                                    .font(.caption)
                                    .foregroundStyle(IHColorTokens.accent)
                                    .accessibilityLabel("Verified")
                            }
                        }
                    }
                }
            }
            .ihGlassCard()
        }
    }
}
