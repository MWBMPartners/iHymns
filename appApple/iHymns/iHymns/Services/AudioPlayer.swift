// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AudioPlayer.swift
//  iHymns
//
//  MIDI audio playback service using AVFoundation / AVMIDIPlayer.
//  Handles loading MIDI files from the data/audio directory,
//  playback controls (play, pause, stop), progress tracking,
//  and file caching for offline access.
//

import Foundation
import SwiftUI

#if canImport(AVFoundation)
import AVFoundation
#endif

// MARK: - AudioPlayerState

enum AudioPlayerState: Equatable {
    case idle
    case loading
    case ready
    case playing
    case paused
    case error(String)
}

// MARK: - AudioPlayerService

/// Manages MIDI playback for hymn audio files.
@MainActor
@Observable
final class AudioPlayerService {

    // MARK: - State

    var state: AudioPlayerState = .idle
    var progress: Double = 0  // 0.0 to 1.0
    var duration: TimeInterval = 0
    var currentTime: TimeInterval = 0

    // MARK: - Private

    #if canImport(AVFoundation) && !os(watchOS)
    private var midiPlayer: AVMIDIPlayer?
    #endif
    private var progressTimer: Task<Void, Never>?
    private let cacheDirectory: URL

    // MARK: - Init

    init() {
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
        cacheDirectory = caches.appendingPathComponent("ihymns_audio", isDirectory: true)
        try? FileManager.default.createDirectory(at: cacheDirectory, withIntermediateDirectories: true)
    }

    // MARK: - Load & Play

    /// Loads a MIDI file for the given song from the API or cache.
    func loadSong(_ song: Song) async {
        guard song.hasAudio else {
            state = .error("No audio available for this song.")
            return
        }

        stop()
        state = .loading

        let songId = song.id
        let cachedURL = cacheDirectory.appendingPathComponent("\(songId).mid")

        // Check cache first
        if FileManager.default.fileExists(atPath: cachedURL.path) {
            loadMIDI(from: cachedURL)
            return
        }

        // Download from API
        let remoteURL = URL(string: "https://ihymns.app/data/audio/\(songId).mid")!
        do {
            let (data, response) = try await URLSession.shared.data(from: remoteURL)
            guard let httpResponse = response as? HTTPURLResponse,
                  (200...299).contains(httpResponse.statusCode) else {
                state = .error("Failed to download audio file.")
                return
            }

            // Cache the file
            try data.write(to: cachedURL)

            loadMIDI(from: cachedURL)
        } catch {
            state = .error("Download failed: \(error.localizedDescription)")
        }
    }

    private func loadMIDI(from url: URL) {
        #if canImport(AVFoundation) && !os(watchOS)
        do {
            // Configure audio session for playback (iOS)
            #if os(iOS)
            try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .default)
            try? AVAudioSession.sharedInstance().setActive(true)
            #endif

            // Use bundled SoundFont if available, otherwise system default
            let soundBankURL = Bundle.main.url(forResource: "gs_instruments", withExtension: "dls")
                ?? Bundle.main.url(forResource: "GeneralUser", withExtension: "sf2")

            if let soundBank = soundBankURL {
                midiPlayer = try AVMIDIPlayer(contentsOf: url, soundBankURL: soundBank)
            } else {
                // No bundled SoundFont found — using system default synthesizer.
                // For best audio quality, add gs_instruments.dls or GeneralUser.sf2
                // to the Xcode project as a bundle resource.
                #if DEBUG
                print("[iHymns Audio] No bundled SoundFont found. Using system default MIDI synthesizer.")
                #endif
                midiPlayer = try AVMIDIPlayer(contentsOf: url, soundBankURL: nil)
            }

            midiPlayer?.prepareToPlay()
            duration = midiPlayer?.duration ?? 0
            state = .ready
        } catch {
            state = .error("Cannot play MIDI: \(error.localizedDescription)")
        }
        #else
        state = .error("Audio playback not supported on this platform.")
        #endif
    }

    // MARK: - Transport Controls

    func play() {
        #if canImport(AVFoundation) && !os(watchOS)
        guard let player = midiPlayer else { return }
        player.play { [weak self] in
            Task { @MainActor in
                self?.state = .ready
                self?.progress = 0
                self?.stopProgressTimer()
            }
        }
        state = .playing
        startProgressTimer()
        #endif
    }

    func pause() {
        #if canImport(AVFoundation) && !os(watchOS)
        midiPlayer?.stop()
        state = .paused
        stopProgressTimer()
        #endif
    }

    func stop() {
        #if canImport(AVFoundation) && !os(watchOS)
        midiPlayer?.stop()
        midiPlayer?.currentPosition = 0
        #endif
        state = .idle
        progress = 0
        currentTime = 0
        stopProgressTimer()
    }

    func seek(to position: Double) {
        #if canImport(AVFoundation) && !os(watchOS)
        let targetTime = position * duration
        midiPlayer?.currentPosition = targetTime
        currentTime = targetTime
        progress = position
        #endif
    }

    // MARK: - Progress Timer

    private func startProgressTimer() {
        stopProgressTimer()
        progressTimer = Task {
            while !Task.isCancelled && state == .playing {
                try? await Task.sleep(for: .milliseconds(100))
                guard !Task.isCancelled else { break }
                #if canImport(AVFoundation) && !os(watchOS)
                if let player = midiPlayer {
                    currentTime = player.currentPosition
                    progress = duration > 0 ? currentTime / duration : 0
                }
                #endif
            }
        }
    }

    private func stopProgressTimer() {
        progressTimer?.cancel()
        progressTimer = nil
    }
}

// MARK: - Transpose Engine

/// Transposes musical keys by semitone offsets.
enum TransposeEngine {

    /// Chromatic scale using sharps.
    static let sharpScale = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"]

    /// Chromatic scale using flats.
    static let flatScale = ["C", "Db", "D", "Eb", "E", "F", "Gb", "G", "Ab", "A", "Bb", "B"]

    /// Keys that conventionally use flats.
    static let flatKeys: Set<String> = ["F", "Bb", "Eb", "Ab", "Db", "Gb", "Dm", "Gm", "Cm", "Fm", "Bbm", "Ebm"]

    /// Transposes a key by the given number of semitones.
    /// - Parameters:
    ///   - key: The original key (e.g., "C", "F#", "Bb").
    ///   - semitones: Number of semitones to transpose (positive = up, negative = down).
    /// - Returns: The transposed key name.
    static func transpose(_ key: String, by semitones: Int) -> String {
        let useFlats = flatKeys.contains(key)
        let scale = useFlats ? flatScale : sharpScale

        guard let index = scale.firstIndex(of: key) ?? sharpScale.firstIndex(of: key) ?? flatScale.firstIndex(of: key) else {
            return key  // Unknown key — return unchanged
        }

        let newIndex = ((index + semitones) % 12 + 12) % 12
        return scale[newIndex]
    }
}
