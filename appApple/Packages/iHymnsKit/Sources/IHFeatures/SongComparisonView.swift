// SongComparisonView.swift
// IHFeatures
//
// ELI5: The actual "compare two songs" screen — on a wide screen (iPad/Mac)
// it shows both songs side by side with verse 1 lined up against verse 1,
// and lines worded differently get a little tint + asterisk. On an iPhone
// it's two swipeable pages with an A/B switch instead, since there isn't
// room for two columns.
//
// DETAILED: #1445. The key layout insight (see `SongComparisonEngine
// .ComparisonLayout`'s own header, and `.claude/apple-comparison-usage-plan.md`
// §1.1): regular width renders ONE `ScrollView` of PAIRED component rows in
// a two-column `Grid` — the paired-grid alignment IS the scroll-sync, so
// there is no shared-scroll-position `@State` anywhere in this file.
// Compact width renders the SAME `pairs` array as two independent pages
// (segmented A/B + `TabView(.page)`) instead. Chords are always hidden here
// (`showChords: false`) — a side-by-side wording comparison doesn't need
// the extra horizontal noise a chord layer adds. Text SIZE, though, reads
// the SAME `@AppStorage("ihLyricsTextScale")` key `SongDetailView` persists,
// so the user's chosen reading size carries straight into this screen.
//
// Diff rendering reuses `SongComponentView`'s two #1445 additive params
// (`highlightedLineIndices`/`accessibilityLabelPrefix`) rather than forking
// a second lyric renderer — see that file's own #1445 header note.
import IHDesign
import IHModels
import SwiftUI

/// The song-vs-song comparison screen, pushed from `SongDetailView`'s
/// "Compare With…" toolbar button (`SongCompareEntryModifier`).
public struct SongComparisonView: View {
    @State private var viewModel: SongComparisonViewModel

    @AppStorage("ihCompareHighlightDiffs") private var highlightDiffs: Bool = true
    // Deliberately the SAME key `SongDetailView` persists — a reader's
    // chosen text size should carry into the comparison screen unchanged.
    @AppStorage("ihLyricsTextScale") private var textScale: Double = 1.0

    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    #endif

    /// Which page is showing in the compact/paged layout — irrelevant, and
    /// simply unused, in the side-by-side layout.
    @State private var selectedSide: ComparisonSide = .primary

    private enum ComparisonSide: Hashable {
        case primary
        case secondary
    }

    public init(primaryId: SongID, secondaryId: SongID, rootViewModel: AppRootViewModel) {
        _viewModel = State(initialValue: SongComparisonViewModel(primaryId: primaryId, secondaryId: secondaryId, rootViewModel: rootViewModel))
    }

    public var body: some View {
        content
            .navigationTitle("Compare")
            .task { await viewModel.loadIfNeeded() }
            .onAppear { IHAnalyticsService().screenViewed("Comparison") }
            .toolbar { toolbarContent }
    }

    @ViewBuilder
    private var content: some View {
        if let message = viewModel.combinedErrorMessage {
            // #1445 — the "one failure UI rule": either side failing shows
            // ONE error card, never a half-rendered comparison.
            IHLoadErrorView(title: "Couldn't Load Comparison", message: message) {
                await viewModel.load()
            }
        } else if viewModel.isReady {
            comparisonBody
        } else {
            ProgressView("Loading comparison…")
                .frame(maxWidth: .infinity, minHeight: 200)
        }
    }

    @ViewBuilder
    private var comparisonBody: some View {
        switch layout {
        case .sideBySide:
            sideBySideContent
        case .paged:
            pagedContent
        }
    }

    /// Mirrors `RootContainerView.platformRoot`'s exact platform split: the
    /// `horizontalSizeClass` environment value is only read on iOS (see
    /// that file's own `#if os(iOS)` guard) — macOS/tvOS/watchOS/visionOS
    /// always resolve to `.sideBySide` via `nil`.
    private var layout: ComparisonLayout {
        #if os(iOS)
        ComparisonLayout.layout(horizontalSizeClass: horizontalSizeClass)
        #else
        ComparisonLayout.layout(horizontalSizeClass: nil)
        #endif
    }

    // MARK: - Side-by-side (regular width)

    private var sideBySideContent: some View {
        ScrollView {
            Grid(alignment: .topLeading, verticalSpacing: 16) {
                GridRow {
                    titleCard(for: viewModel.primaryDetail)
                    titleCard(for: viewModel.secondaryDetail)
                }
                Divider().gridCellColumns(2)
                ForEach(viewModel.pairs) { pair in
                    GridRow {
                        cell(for: pair, isPrimary: true)
                        cell(for: pair, isPrimary: false)
                    }
                }
            }
            .padding()
        }
    }

    // MARK: - Paged (compact width)

    private var pagedContent: some View {
        VStack(spacing: 0) {
            Picker("Side", selection: $selectedSide) {
                Text(sideLabel(isPrimary: true)).tag(ComparisonSide.primary)
                Text(sideLabel(isPrimary: false)).tag(ComparisonSide.secondary)
            }
            .pickerStyle(.segmented)
            .padding([.horizontal, .top])

            TabView(selection: $selectedSide) {
                pagedScrollView(isPrimary: true).tag(ComparisonSide.primary)
                pagedScrollView(isPrimary: false).tag(ComparisonSide.secondary)
            }
            .pagedTabViewStyle()
        }
    }

    private func pagedScrollView(isPrimary: Bool) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                titleCard(for: isPrimary ? viewModel.primaryDetail : viewModel.secondaryDetail)
                ForEach(viewModel.pairs) { pair in
                    cell(for: pair, isPrimary: isPrimary)
                }
            }
            .padding()
        }
    }

    // MARK: - Shared cell rendering

    /// One pair's cell for ONE side — the loaded component (with diff
    /// highlighting) when this pair has one on this side, otherwise an
    /// honest "only in the other song" placeholder (`.secondary` text, not
    /// an error).
    @ViewBuilder
    private func cell(for pair: ComparisonComponentPair, isPrimary: Bool) -> some View {
        let component = isPrimary ? pair.primary : pair.secondary
        if let component {
            SongComponentView(
                component: component,
                textScale: CGFloat(textScale),
                showChords: false,
                enrichmentIndex: .empty,
                onSelectLine: { _ in },
                highlightedLineIndices: highlightDiffs ? highlightIndices(for: pair, isPrimary: isPrimary) : [],
                accessibilityLabelPrefix: sideLabel(isPrimary: isPrimary)
            )
        } else {
            Text("Only in \(sideLabel(isPrimary: !isPrimary))")
                .font(.footnote)
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity, alignment: .leading)
                .accessibilityLabel("Only in \(sideLabel(isPrimary: !isPrimary))")
        }
    }

    /// Resolves which of `ComparisonComponentPair`'s two diff sets applies
    /// to THIS side's rendering. `differingLineIndices` lives in the
    /// PRIMARY's own index space (per that type's doc comment) — since
    /// lines are paired by index, the SAME indices (up to the secondary's
    /// own line count) apply symmetrically to the secondary's rendering
    /// too, so both panes mark a mismatched line pair, not just one side of
    /// it. `secondaryOnlyLineIndices` is added on top for the secondary's
    /// own trailing overflow lines, which have no primary counterpart at
    /// all.
    private func highlightIndices(for pair: ComparisonComponentPair, isPrimary: Bool) -> Set<Int> {
        if isPrimary {
            return pair.differingLineIndices
        }
        guard let secondaryLineCount = pair.secondary?.lines.count else { return [] }
        let sharedMismatches = pair.differingLineIndices.filter { $0 < secondaryLineCount }
        return sharedMismatches.union(pair.secondaryOnlyLineIndices)
    }

    private func titleCard(for detail: SongDetail?) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(detail?.title ?? "—")
                .font(.headline)
                .lineLimit(2)
            if let detail {
                Text(songbookLabel(for: detail))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .ihGlassCard()
        .accessibilityElement(children: .combine)
        // #1445 a11y — marks each title card as a heading so the VoiceOver
        // rotor can jump straight between the two song titles.
        .accessibilityAddTraits(.isHeader)
    }

    private func songbookLabel(for detail: SongDetail) -> String {
        let name = detail.songbookName.isEmpty ? detail.songbookAbbreviation : detail.songbookName
        guard let number = detail.number else { return name }
        return "\(name) #\(number)"
    }

    /// A short label for one side, e.g. `"MP"` — used both for the segmented
    /// picker text and the `accessibilityLabelPrefix`/"Only in …" placeholder
    /// copy. Falls back to a neutral "Song A"/"Song B" while that side is
    /// still loading.
    private func sideLabel(isPrimary: Bool) -> String {
        let detail = isPrimary ? viewModel.primaryDetail : viewModel.secondaryDetail
        guard let detail else { return isPrimary ? "Song A" : "Song B" }
        return detail.songbookAbbreviation.isEmpty ? detail.title : detail.songbookAbbreviation
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItemGroup(placement: toolbarPlacement) {
            Toggle(isOn: $highlightDiffs) {
                Label("Highlight Differences", systemImage: "asterisk.circle")
            }
            Button {
                viewModel.swapSides()
            } label: {
                Label("Swap Sides", systemImage: "arrow.left.arrow.right")
            }
        }
    }

    /// Mirrors `SongDetailToolbarContent`'s exact `#if os(macOS) .primaryAction
    /// #else .bottomBar` split — the safe, definitely-supported placement on
    /// every platform this package targets.
    private var toolbarPlacement: ToolbarItemPlacement {
        #if os(macOS)
        .primaryAction
        #else
        .bottomBar
        #endif
    }
}

/// `.page(indexDisplayMode:)` (`PageTabViewStyle`) is unavailable on macOS
/// — `pagedContent` above is never actually REACHED on macOS at runtime
/// (`SongComparisonView.layout` always resolves `.sideBySide` there, since
/// macOS reports no horizontal size class), but the file must still
/// COMPILE for that platform (`IHFeatures` targets macOS too). Isolated in
/// its own extension purely to keep the `#if os(macOS)` guard out of
/// `pagedContent`'s own body.
private extension View {
    @ViewBuilder
    func pagedTabViewStyle() -> some View {
        #if os(macOS)
        self.tabViewStyle(.automatic)
        #else
        self.tabViewStyle(.page(indexDisplayMode: .never))
        #endif
    }
}
