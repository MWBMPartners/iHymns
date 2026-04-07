// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AudioPlayerView.swift
//  iHymns
//
//  Inline audio player with transport controls, progress bar, and
//  time display. Used in SongDetailView when a song has audio.
//

import SwiftUI

// MARK: - AudioPlayerView

struct AudioPlayerView: View {

    let song: Song
    @State private var player = AudioPlayerService()

    var body: some View {
        VStack(spacing: Spacing.sm) {
            // Progress bar
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Rectangle()
                        .fill(AmberTheme.wash)
                        .frame(height: 4)

                    Rectangle()
                        .fill(AmberTheme.accent)
                        .frame(width: geo.size.width * player.progress, height: 4)
                }
                .clipShape(Capsule())
                .gesture(
                    DragGesture(minimumDistance: 0)
                        .onChanged { value in
                            let position = min(max(value.location.x / geo.size.width, 0), 1)
                            player.seek(to: position)
                        }
                )
            }
            .frame(height: 4)

            HStack {
                // Time display
                Text(formatTime(player.currentTime))
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)

                Spacer()

                // Transport controls
                HStack(spacing: Spacing.lg) {
                    // Stop
                    Button { player.stop() } label: {
                        Image(systemName: "stop.fill")
                            .font(.body)
                            .foregroundStyle(.secondary)
                    }
                    .buttonStyle(.plain)
                    .disabled(player.state == .idle)

                    // Play/Pause
                    Button {
                        switch player.state {
                        case .ready, .paused:
                            player.play()
                        case .playing:
                            player.pause()
                        case .idle:
                            Task { await player.loadSong(song) }
                        default:
                            break
                        }
                    } label: {
                        Group {
                            switch player.state {
                            case .loading:
                                ProgressView()
                                    .controlSize(.small)
                            case .playing:
                                Image(systemName: "pause.fill")
                                    .font(.title2)
                            default:
                                Image(systemName: "play.fill")
                                    .font(.title2)
                            }
                        }
                        .foregroundStyle(AmberTheme.accent)
                        .frame(width: 44, height: 44)
                    }
                    .buttonStyle(.plain)
                }

                Spacer()

                // Duration
                Text(formatTime(player.duration))
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)
            }

            // Error message
            if case .error(let message) = player.state {
                Text(message)
                    .font(.caption)
                    .foregroundStyle(.red)
            }
        }
        .padding()
        .liquidGlass(.thin, tint: AmberTheme.accent)
    }

    private func formatTime(_ time: TimeInterval) -> String {
        let minutes = Int(time) / 60
        let seconds = Int(time) % 60
        return String(format: "%d:%02d", minutes, seconds)
    }
}

// MARK: - SheetMusicView

/// PDF sheet music viewer using PDFKit.
struct SheetMusicView: View {

    let song: Song
    @State private var isLoading = true
    @State private var pdfData: Data?
    @State private var errorMessage: String?

    var body: some View {
        Group {
            if isLoading {
                ProgressView("Loading sheet music...")
            } else if let error = errorMessage {
                ContentUnavailableView {
                    Label("Unable to Load", systemImage: "exclamationmark.triangle")
                } description: {
                    Text(error)
                }
            } else if pdfData != nil {
                #if canImport(PDFKit) && !os(watchOS) && !os(tvOS)
                PDFKitView(data: pdfData!)
                #else
                ContentUnavailableView {
                    Label("Not Available", systemImage: "doc.richtext")
                } description: {
                    Text("Sheet music viewing is not available on this platform.")
                }
                #endif
            }
        }
        .navigationTitle("Sheet Music — \(song.title)")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if let data = pdfData {
                ToolbarItem(placement: .primaryAction) {
                    ShareLink(
                        item: data,
                        preview: SharePreview("\(song.title) — Sheet Music")
                    ) {
                        Label("Share", systemImage: "square.and.arrow.up")
                    }
                }
            }
        }
        #endif
        .task {
            await loadSheetMusic()
        }
    }

    private func loadSheetMusic() async {
        isLoading = true
        let url = URL(string: "https://ihymns.app/data/music/\(song.id).pdf")!

        // Check cache
        let cacheDir = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
            .appendingPathComponent("ihymns_music", isDirectory: true)
        try? FileManager.default.createDirectory(at: cacheDir, withIntermediateDirectories: true)
        let cachedURL = cacheDir.appendingPathComponent("\(song.id).pdf")

        if let cached = try? Data(contentsOf: cachedURL) {
            pdfData = cached
            isLoading = false
            return
        }

        do {
            let (data, response) = try await URLSession.shared.data(from: url)
            guard let httpResponse = response as? HTTPURLResponse,
                  (200...299).contains(httpResponse.statusCode) else {
                errorMessage = "Sheet music not found."
                isLoading = false
                return
            }
            try data.write(to: cachedURL)
            pdfData = data
        } catch {
            errorMessage = error.localizedDescription
        }
        isLoading = false
    }
}

// MARK: - PDFKit Wrapper

#if canImport(PDFKit) && !os(watchOS) && !os(tvOS)
import PDFKit

#if os(macOS)
struct PDFKitView: NSViewRepresentable {
    let data: Data

    func makeNSView(context: Context) -> PDFView {
        let pdfView = PDFView()
        pdfView.autoScales = true
        pdfView.displayMode = .singlePageContinuous
        pdfView.displayDirection = .vertical
        pdfView.document = PDFDocument(data: data)
        return pdfView
    }

    func updateNSView(_ nsView: PDFView, context: Context) {}
}
#else
struct PDFKitView: UIViewRepresentable {
    let data: Data

    func makeUIView(context: Context) -> PDFView {
        let pdfView = PDFView()
        pdfView.autoScales = true
        pdfView.displayMode = .singlePageContinuous
        pdfView.displayDirection = .vertical
        pdfView.document = PDFDocument(data: data)
        return pdfView
    }

    func updateUIView(_ uiView: PDFView, context: Context) {}
}
#endif
#endif

// MARK: - TransposeControlView

/// Inline capo/transpose controls for a song.
struct TransposeControlView: View {

    let songId: String
    @State private var transposeOffset: Int

    private static let storagePrefix = "ihymns_transpose_"

    init(songId: String) {
        self.songId = songId
        let saved = UserDefaults.standard.integer(forKey: "\(TransposeControlView.storagePrefix)\(songId)")
        _transposeOffset = State(initialValue: saved)
    }

    /// The base key (would come from song metadata — placeholder).
    let baseKey: String? = nil

    var body: some View {
        HStack(spacing: Spacing.md) {
            if let key = baseKey {
                Text("Key: \(TransposeEngine.transpose(key, by: transposeOffset))")
                    .font(.subheadline.bold())
                    .foregroundStyle(AmberTheme.accent)
            }

            Spacer()

            HStack(spacing: Spacing.sm) {
                Button {
                    transposeOffset -= 1
                    saveOffset()
                    HapticManager.selectionChanged()
                } label: {
                    Image(systemName: "minus")
                        .frame(width: 32, height: 32)
                        .background(.regularMaterial, in: Circle())
                }
                .buttonStyle(.plain)

                Text(transposeOffset == 0 ? "Original" : "\(transposeOffset > 0 ? "+" : "")\(transposeOffset)")
                    .font(.subheadline.monospacedDigit())
                    .frame(width: 60)

                Button {
                    transposeOffset += 1
                    saveOffset()
                    HapticManager.selectionChanged()
                } label: {
                    Image(systemName: "plus")
                        .frame(width: 32, height: 32)
                        .background(.regularMaterial, in: Circle())
                }
                .buttonStyle(.plain)

                if transposeOffset != 0 {
                    Button {
                        transposeOffset = 0
                        saveOffset()
                    } label: {
                        Text("Reset")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding()
        .liquidGlass(.thin)
    }

    private func saveOffset() {
        UserDefaults.standard.set(transposeOffset, forKey: "\(TransposeControlView.storagePrefix)\(songId)")
    }
}
