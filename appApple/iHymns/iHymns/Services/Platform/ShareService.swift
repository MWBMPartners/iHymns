// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  ShareService.swift
//  iHymns
//
//  Provides rich sharing capabilities including:
//  - Formatted share text with metadata
//  - Deep link URL generation
//  - Rich link preview metadata (LPLinkMetadata) for iMessage/social
//

import Foundation
import SwiftUI

#if canImport(LinkPresentation) && !os(watchOS) && !os(tvOS)
import LinkPresentation
#endif

// MARK: - ShareService

enum ShareService {

    /// Generates a permalink URL for a song.
    static func songURL(_ song: Song) -> URL {
        URL(string: "https://ihymns.app/song/\(song.id)")!
    }

    /// Generates formatted share text for a song.
    static func songShareText(_ song: Song) -> String {
        var lines: [String] = []
        lines.append("\(song.title) (#\(song.number))")
        lines.append(song.songbookName)
        if !song.writers.isEmpty {
            lines.append("Words: \(song.writersDisplay)")
        }
        lines.append("")
        lines.append(song.lyricsPreview)
        lines.append("")
        lines.append("Listen & read more: \(songURL(song).absoluteString)")
        lines.append("Shared from iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }

    /// Generates a permalink URL for a shared set list.
    static func setListURL(shareId: String) -> URL {
        URL(string: "https://ihymns.app/setlist/shared/\(shareId)")!
    }

    /// Generates formatted share text for a set list.
    static func setListShareText(_ setList: SetList, songs: [Song]) -> String {
        var lines = ["\(setList.name) — Worship Set List", ""]
        for (index, songId) in setList.songIds.enumerated() {
            if let song = songs.first(where: { $0.id == songId }) {
                lines.append("\(index + 1). \(song.title) (\(song.songbook) #\(song.number))")
            }
        }
        lines.append("")
        if let shareId = setList.shareId {
            lines.append("View online: \(setListURL(shareId: shareId).absoluteString)")
        }
        lines.append("Created with iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }

    #if canImport(LinkPresentation) && os(iOS)
    /// Creates rich link metadata for sharing a song via iMessage, social media, etc.
    /// This provides a preview card with title, subtitle, and icon.
    static func richLinkMetadata(for song: Song) -> LPLinkMetadata {
        let metadata = LPLinkMetadata()
        metadata.originalURL = songURL(song)
        metadata.url = songURL(song)
        metadata.title = "\(song.title) — \(song.songbookName)"

        // Create a simple text-based icon
        let renderer = UIGraphicsImageRenderer(size: CGSize(width: 60, height: 60))
        let image = renderer.image { ctx in
            // Background
            UIColor.systemOrange.setFill()
            UIBezierPath(roundedRect: CGRect(x: 0, y: 0, width: 60, height: 60), cornerRadius: 12).fill()

            // Text
            let attrs: [NSAttributedString.Key: Any] = [
                .font: UIFont.boldSystemFont(ofSize: 20),
                .foregroundColor: UIColor.white
            ]
            let text = song.songbook as NSString
            let size = text.size(withAttributes: attrs)
            text.draw(at: CGPoint(x: (60 - size.width) / 2, y: (60 - size.height) / 2), withAttributes: attrs)
        }
        metadata.iconProvider = NSItemProvider(object: image)

        return metadata
    }
    #endif
}
