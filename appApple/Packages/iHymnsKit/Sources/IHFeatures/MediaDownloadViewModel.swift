// MediaDownloadViewModel.swift
// IHFeatures
//
// ELI5: Downloads a MIDI/MusicXML/sheet-music file to a temporary spot on
// the device so the "Share"/"Open in…" sheet can offer the REAL file (Save
// to Files, AirDrop, open in a dedicated MIDI/MusicXML app) instead of just
// forwarding a web link.
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music) — the brief's item 3:
// "provide at minimum download/share/'open in…' (a `ShareLink` or
// `.fileExporter`), since native rendering of MIDI/MusicXML is out of
// scope." `ShareLink(item: someRemoteHTTPURL)` shares the LINK (e.g. opens
// Safari, or sends a URL in Messages) — it does not hand the receiving
// share-sheet action the file's actual bytes, which is what "Save to Files"/
// "open in [a MIDI app]" need. Downloading to a local file first and sharing
// THAT `URL` is the standard SwiftUI pattern for a genuine file
// share/export (`ShareLink`'s own documentation:
// https://developer.apple.com/documentation/swiftui/sharelink — a local
// file URL is treated as a file transfer, not a web-link transfer).
//
// ONE reusable engine (not a MIDI-specific one and a separate MusicXML-
// specific one) — `SongMediaSection.swift` instantiates one PER attached
// asset (MIDI, MusicXML, or the sheet-music PDF's own "Save a Copy"
// fallback), all going through this same download/state-machine logic,
// per the repo's "if a piece of logic exists in more than one place,
// extract it into a shared module" rule.
//
// Reuses `LoadState<URL>` (`LoadState.swift`) for the identical
// "fetch-or-not" reasoning `SheetMusicViewModel.swift`'s header documents —
// a one-shot download either hasn't started, is in flight, succeeded (here:
// with the LOCAL file's `URL` as the payload), or failed.
//
// #1440 UPDATE (offline media caching) — `sanitizedFileName(_:)` moved OUT
// to `IHModels.MediaFileNaming` so the NEW permanent offline media cache
// (`IHPersistence.OfflineStore+MediaCache.swift`) can reuse the exact same
// sanitizer instead of forking a second copy; this file now just calls
// through to it. See that type's own header for the full layering
// rationale.
import Foundation
import IHModels

/// Downloads one media asset to a local temporary file, ready to hand to a
/// `ShareLink`.
///
/// ELI5: "Download this file so I can share/save/open it."
@MainActor
@Observable
public final class MediaDownloadViewModel {
    public private(set) var state: LoadState<URL> = .idle

    /// Fetches raw bytes for a URL — injectable for the same "no real
    /// network in tests" reason `SheetMusicViewModel.fetcher` documents.
    private let fetcher: @Sendable (URL) async throws -> Data

    /// Where downloaded bytes get written — injectable so tests can point
    /// this at a throwaway directory instead of the real
    /// `FileManager.default.temporaryDirectory`, keeping test runs isolated
    /// from one another and from the developer's real temp folder.
    private let temporaryDirectory: URL

    /// - Parameters:
    ///   - fetcher: Defaults to a real `URLSession` fetch.
    ///   - temporaryDirectory: Defaults to the real system temp directory.
    public init(
        fetcher: @escaping @Sendable (URL) async throws -> Data = { url in
            try await URLSession.shared.data(from: url).0
        },
        temporaryDirectory: URL = FileManager.default.temporaryDirectory
    ) {
        self.fetcher = fetcher
        self.temporaryDirectory = temporaryDirectory
    }

    /// Downloads `url` and writes it to a temp file named `suggestedFileName`
    /// (the asset's real `SongMediaAsset.fileName` — e.g. `"amazing-grace.mid"` —
    /// so the eventual share sheet/Files app shows a sensible name, not a
    /// UUID).
    ///
    /// ELI5: "Go get this file and save it somewhere I can hand it off from."
    public func download(url: URL, suggestedFileName: String) async {
        state = .loading
        do {
            let data = try await fetcher(url)
            guard !data.isEmpty else {
                state = .error("The file was empty. Please try again.")
                return
            }
            // A fresh, collision-proof subdirectory per download (rather
            // than writing `suggestedFileName` straight into the shared temp
            // root) — two different songs' media files could plausibly share
            // a generic name (e.g. two curators both naming their upload
            // `"sheet.pdf"`), and a stale leftover from an earlier download
            // in the SAME app run must never silently get reused/overwritten
            // by a differently-named-but-colliding file.
            let directory = temporaryDirectory.appendingPathComponent(UUID().uuidString, isDirectory: true)
            try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
            let fileURL = directory.appendingPathComponent(MediaFileNaming.sanitizedFileName(suggestedFileName))
            try data.write(to: fileURL, options: .atomic)
            state = .loaded(fileURL)
        } catch {
            state = .error(Self.userFacingMessage(for: error))
        }
    }

    private static func userFacingMessage(for error: Error) -> String {
        if let urlError = error as? URLError {
            switch urlError.code {
            case .notConnectedToInternet, .networkConnectionLost, .timedOut, .dataNotAllowed:
                return "You're offline. Check your connection and try again."
            default:
                return "Couldn't download this file. Please try again."
            }
        }
        return "Couldn't download this file. Please try again."
    }
}
