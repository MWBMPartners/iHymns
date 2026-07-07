// MediaDownloadViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the MIDI/MusicXML/sheet-music "download so I can share it"
// helper (`MediaDownloadViewModel`, #184) actually writes the right bytes
// to a real (but throwaway, test-only) temp file, gives that file a safe
// name even if the server-supplied name tries something sneaky like
// `"../../evil"`, and reports failures honestly — all against an injected
// fake fetcher, never a real network call.
import Foundation
import Testing
@testable import IHFeatures

@MainActor
@Suite("MediaDownloadViewModel (#184)")
struct MediaDownloadViewModelTests {

    private static let url = URL(string: "https://dev.ihymns.app/song-media/3")!

    /// A fresh, uniquely-named scratch directory under the REAL system temp
    /// root, used as the injected `temporaryDirectory` — keeps every test's
    /// files isolated from every other test's (and from a developer's real
    /// temp folder) without needing a full `FileManager` mock.
    private static func scratchDirectory() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(
            "MediaDownloadViewModelTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    @Test("Starts idle")
    func startsIdle() {
        let viewModel = MediaDownloadViewModel(fetcher: { _ in Data() }, temporaryDirectory: Self.scratchDirectory())
        #expect(viewModel.state == .idle)
    }

    @Test("A successful download writes the exact bytes to a local file and reports its URL")
    func successfulDownloadWritesBytes() async throws {
        let bytes = Data([0x4D, 0x54, 0x68, 0x64]) // "MThd" — a real MIDI file magic number
        let viewModel = MediaDownloadViewModel(fetcher: { _ in bytes }, temporaryDirectory: Self.scratchDirectory())

        await viewModel.download(url: Self.url, suggestedFileName: "amazing-grace.mid")

        guard case .loaded(let fileURL) = viewModel.state else {
            Issue.record("Expected .loaded, got \(viewModel.state)")
            return
        }
        #expect(fileURL.lastPathComponent == "amazing-grace.mid")
        #expect(try Data(contentsOf: fileURL) == bytes)
    }

    @Test("A path-traversal-shaped suggested file name is reduced to a safe leaf name")
    func sanitizesPathTraversalAttempts() async throws {
        let viewModel = MediaDownloadViewModel(fetcher: { _ in Data([1]) }, temporaryDirectory: Self.scratchDirectory())
        await viewModel.download(url: Self.url, suggestedFileName: "../../etc/evil.mid")

        guard case .loaded(let fileURL) = viewModel.state else {
            Issue.record("Expected .loaded, got \(viewModel.state)")
            return
        }
        // The written file must land as a plain leaf name — never escape
        // the fresh per-download temp subdirectory this call created.
        #expect(fileURL.lastPathComponent == "evil.mid")
        #expect(fileURL.deletingLastPathComponent().path.contains("..") == false)
    }

    @Test("A suggested file name that's ENTIRELY \"..\" (nothing left after sanitizing) falls back to a safe default")
    func blankAfterSanitizingFallsBackToDefault() async throws {
        let viewModel = MediaDownloadViewModel(fetcher: { _ in Data([1]) }, temporaryDirectory: Self.scratchDirectory())
        // No path separators, so `lastPathComponent` leaves it as `".."`
        // unchanged; stripping `".."` then leaves an EMPTY string, which
        // must fall back rather than trying to write a file with no name.
        await viewModel.download(url: Self.url, suggestedFileName: "..")
        guard case .loaded(let fileURL) = viewModel.state else {
            Issue.record("Expected .loaded, got \(viewModel.state)")
            return
        }
        #expect(fileURL.lastPathComponent == "download")
    }

    @Test("Two downloads with the SAME suggested file name land in different files, never colliding")
    func repeatedDownloadsDoNotCollide() async throws {
        let directory = Self.scratchDirectory()
        let viewModel = MediaDownloadViewModel(fetcher: { _ in Data([9]) }, temporaryDirectory: directory)

        await viewModel.download(url: Self.url, suggestedFileName: "sheet.pdf")
        guard case .loaded(let first) = viewModel.state else {
            Issue.record("Expected first .loaded")
            return
        }
        await viewModel.download(url: Self.url, suggestedFileName: "sheet.pdf")
        guard case .loaded(let second) = viewModel.state else {
            Issue.record("Expected second .loaded")
            return
        }
        #expect(first != second)
        // Both copies must still individually be readable — proves the
        // second download never overwrote/corrupted the first's file.
        #expect(try Data(contentsOf: first) == Data([9]))
        #expect(try Data(contentsOf: second) == Data([9]))
    }

    @Test("An empty response is treated as an error, not a valid (empty) file")
    func emptyResponseIsAnError() async {
        let viewModel = MediaDownloadViewModel(fetcher: { _ in Data() }, temporaryDirectory: Self.scratchDirectory())
        await viewModel.download(url: Self.url, suggestedFileName: "empty.mid")
        #expect(viewModel.state == .error("The file was empty. Please try again."))
    }

    @Test("An offline-shaped failure maps to the offline message")
    func offlineFailureMapsToOfflineMessage() async {
        let viewModel = MediaDownloadViewModel(
            fetcher: { _ in throw URLError(.notConnectedToInternet) },
            temporaryDirectory: Self.scratchDirectory()
        )
        await viewModel.download(url: Self.url, suggestedFileName: "file.mid")
        #expect(viewModel.state == .error("You're offline. Check your connection and try again."))
    }

    @Test("Any other failure maps to a generic message")
    func genericFailureMapsToGenericMessage() async {
        let viewModel = MediaDownloadViewModel(
            fetcher: { _ in throw URLError(.badServerResponse) },
            temporaryDirectory: Self.scratchDirectory()
        )
        await viewModel.download(url: Self.url, suggestedFileName: "file.mid")
        #expect(viewModel.state == .error("Couldn't download this file. Please try again."))
    }
}
