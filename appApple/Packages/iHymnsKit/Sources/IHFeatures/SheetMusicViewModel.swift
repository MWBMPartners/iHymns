// SheetMusicViewModel.swift
// IHFeatures
//
// ELI5: Fetches a song's sheet-music file's raw bytes over the network and
// remembers whether that's still loading, done, or failed — the PDF-viewing
// itself is a separate concern (`SheetMusicView.swift`).
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music). Deliberately reuses
// `LoadState<Data>` (`LoadState.swift`) rather than a bespoke enum — unlike
// `SongAudioPlayerViewModel` (which genuinely needs a `.playing`/`.paused`
// split `LoadState` has no room for), a one-shot "fetch bytes, done or
// failed" operation is EXACTLY `LoadState`'s shape, so reusing it here is
// the repo's "if a shared module already exists, reuse it" rule applied
// correctly (as opposed to the player view model, where reuse would have
// been the wrong call — see that file's header for the contrast).
//
// Deliberately has ZERO `PDFKit` import — `PDFDocument(data:)` construction
// is left to the VIEW layer (`SheetMusicView.swift`), which is the one that
// actually needs the `#if canImport(PDFKit)` guard (PDFKit is unavailable on
// tvOS/watchOS, per Apple's platform availability tables). Keeping this
// fetch-only view model platform-agnostic means it needs no guard at all,
// and its tests never need to skip on a hypothetical PDFKit-less test host.
import Foundation

/// Fetches and holds one song's sheet-music file's raw bytes.
///
/// ELI5: "Go get the sheet-music file for this song."
@MainActor
@Observable
public final class SheetMusicViewModel {
    public private(set) var state: LoadState<Data> = .idle

    /// Fetches raw bytes for a URL — the real implementation is a plain
    /// `URLSession.shared.data(from:)` call; injectable so tests substitute
    /// a fake that never touches the network (this task's explicit "do not
    /// require real network... hardware" test requirement).
    private let fetcher: @Sendable (URL) async throws -> Data

    /// - Parameter fetcher: Defaults to a real `URLSession` fetch. Tests
    ///   inject a stub returning canned bytes or throwing, to drive
    ///   `state`'s loading/loaded/error transitions deterministically.
    public init(fetcher: @escaping @Sendable (URL) async throws -> Data = { url in
        try await URLSession.shared.data(from: url).0
    }) {
        self.fetcher = fetcher
    }

    /// Fetches `url`'s bytes, but only if nothing has been fetched yet (or a
    /// fetch isn't already in flight) — safe to call from `.task {}` on
    /// every re-appearance, mirroring `SongDetailViewModel.loadIfNeeded()`'s
    /// identical guard shape.
    ///
    /// ELI5: "Get the sheet music, but only if we don't already have it."
    public func loadIfNeeded(url: URL) async {
        guard case .idle = state else { return }
        await load(url: url)
    }

    /// Forces a (re)fetch regardless of current state — the hook a future
    /// manual retry action on the error state calls into.
    public func load(url: URL) async {
        state = .loading
        do {
            let data = try await fetcher(url)
            guard !data.isEmpty else {
                state = .error("The sheet music file was empty. Please try again.")
                return
            }
            state = .loaded(data)
        } catch {
            state = .error(Self.userFacingMessage(for: error))
        }
    }

    /// Same short, user-facing-sentence mapping `SongAudioPlayerViewModel`
    /// uses for its own plain (non-`APIError`) network failures — kept as an
    /// identical private copy rather than a shared helper because the two
    /// call sites' wording ("this recording" vs "the sheet music file")
    /// genuinely differs and a shared function would need a parameter for
    /// that anyway, at which point the "shared" version buys nothing over
    /// two short, independently-readable switch statements.
    private static func userFacingMessage(for error: Error) -> String {
        if let urlError = error as? URLError {
            switch urlError.code {
            case .notConnectedToInternet, .networkConnectionLost, .timedOut, .dataNotAllowed:
                return "You're offline. Check your connection and try again."
            default:
                return "Couldn't load the sheet music. Please try again."
            }
        }
        return "Couldn't load the sheet music. Please try again."
    }
}
