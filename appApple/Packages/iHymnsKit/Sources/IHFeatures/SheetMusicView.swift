// SheetMusicView.swift
// IHFeatures
//
// ELI5: The full-screen page that shows a song's sheet-music PDF, once you
// tap "View Sheet Music" — fetches the file, then lets you scroll/zoom
// through it with Apple's own PDF viewer control.
//
// DETAILED: #184's scope item 2 — "Sheet-music PDF: view the PDF (PDFKit
// `PDFView` wrapped for SwiftUI), shown only when `hasSheetMusic`." Presented
// as a `.sheet` from `SongMediaSection`, mirroring `AddToSetlistSheet`'s own
// `NavigationStack` + `.cancellationAction` "Close" button shape.
//
// `PDFKit` is unavailable on tvOS/watchOS (Apple's platform-availability
// tables: PDFKit ships for iOS/iPadOS/macOS/Mac Catalyst/visionOS only) —
// `#if canImport(PDFKit)` guards BOTH the actual `PDFView` wrapper AND its
// use in `content`, so this file (and the whole `IHFeatures` target) keeps
// compiling on every platform `Package.swift` targets; the `#else` branch
// gives tvOS/watchOS users an honest "not available here, go download it
// from the song page instead" message rather than a build failure or a
// silently blank screen.
import IHDesign
import IHModels
import SwiftUI
#if canImport(PDFKit)
import PDFKit
#endif

/// Fetches and displays one song's sheet-music PDF full-screen.
///
/// ELI5: "Show me the sheet music."
struct SheetMusicView: View {
    let asset: SongMediaAsset
    let url: URL

    @Environment(\.dismiss) private var dismiss
    @State private var viewModel = SheetMusicViewModel()

    var body: some View {
        NavigationStack {
            content
                .navigationTitle("Sheet Music")
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Close") { dismiss() }
                    }
                }
        }
        .task { await viewModel.loadIfNeeded(url: url) }
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.state {
        case .idle, .loading:
            ProgressView("Loading sheet music…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)

        case .error(let message):
            ContentUnavailableView(
                "Couldn't Load Sheet Music",
                systemImage: "doc.text.magnifyingglass",
                description: Text(message)
            )

        case .loaded(let data):
            pdfContent(data: data)
        }
    }

    @ViewBuilder
    private func pdfContent(data: Data) -> some View {
        #if canImport(PDFKit)
        if let document = PDFDocument(data: data) {
            PDFKitRepresentableView(document: document)
        } else {
            // `mimeType`/`kind == "sheet-music"` doesn't itself GUARANTEE
            // the bytes are a valid PDF (schema.sql's own comment lists a
            // broader future vocabulary, e.g. `"notation-source"`) — a
            // curator upload that isn't actually a PDF degrades to this
            // honest message rather than a blank/frozen `PDFView`.
            ContentUnavailableView(
                "Couldn't Open File",
                systemImage: "doc.questionmark",
                description: Text("This file isn't a viewable PDF. Download it instead from the song page.")
            )
        }
        #else
        ContentUnavailableView(
            "Not Available on This Device",
            systemImage: "doc.questionmark",
            description: Text("Sheet-music viewing isn't available on this device. Download it instead from the song page.")
        )
        #endif
    }
}

#if canImport(PDFKit)
#if os(macOS)
/// The macOS `PDFView` wrapper — `NSViewRepresentable`, since AppKit (not
/// UIKit) is macOS's native view-hosting bridge for SwiftUI.
private struct PDFKitRepresentableView: NSViewRepresentable {
    let document: PDFDocument

    func makeNSView(context: Context) -> PDFView {
        let view = PDFView()
        view.autoScales = true
        view.document = document
        return view
    }

    func updateNSView(_ nsView: PDFView, context: Context) {
        nsView.document = document
    }
}
#else
/// The iOS/iPadOS/visionOS `PDFView` wrapper — `UIViewRepresentable`.
private struct PDFKitRepresentableView: UIViewRepresentable {
    let document: PDFDocument

    func makeUIView(context: Context) -> PDFView {
        let view = PDFView()
        view.autoScales = true
        view.document = document
        return view
    }

    func updateUIView(_ uiView: PDFView, context: Context) {
        uiView.document = document
    }
}
#endif
#endif
