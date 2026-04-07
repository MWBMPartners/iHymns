// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SwiftDataModels.swift
//  iHymns
//
//  SwiftData model definitions for future migration from UserDefaults.
//  Provides structured, queryable, CloudKit-ready persistence.
//

import Foundation
import SwiftData

// MARK: - Favourite Model

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
@Model
final class FavouriteRecord {
    @Attribute(.unique) var songId: String
    var addedAt: Date
    var tags: [String]

    init(songId: String, addedAt: Date = Date(), tags: [String] = []) {
        self.songId = songId
        self.addedAt = addedAt
        self.tags = tags
    }
}

// MARK: - Set List Model

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
@Model
final class SetListRecord {
    @Attribute(.unique) var id: UUID
    var name: String
    var songIds: [String]
    var createdAt: Date
    var updatedAt: Date
    var shareId: String?

    init(id: UUID = UUID(), name: String, songIds: [String] = [], createdAt: Date = Date(), updatedAt: Date = Date(), shareId: String? = nil) {
        self.id = id
        self.name = name
        self.songIds = songIds
        self.createdAt = createdAt
        self.updatedAt = updatedAt
        self.shareId = shareId
    }
}

// MARK: - View History Model

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
@Model
final class ViewHistoryRecord {
    @Attribute(.unique) var songId: String
    var songTitle: String
    var songbook: String
    var lastViewedAt: Date
    var viewCount: Int

    init(songId: String, songTitle: String, songbook: String, lastViewedAt: Date = Date(), viewCount: Int = 1) {
        self.songId = songId
        self.songTitle = songTitle
        self.songbook = songbook
        self.lastViewedAt = lastViewedAt
        self.viewCount = viewCount
    }
}

// MARK: - Search History Model

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
@Model
final class SearchHistoryRecord {
    @Attribute(.unique) var query: String
    var searchedAt: Date
    var resultCount: Int

    init(query: String, searchedAt: Date = Date(), resultCount: Int = 0) {
        self.query = query
        self.searchedAt = searchedAt
        self.resultCount = resultCount
    }
}

// MARK: - Migration Helper

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
enum SwiftDataMigration {

    /// Migrates existing UserDefaults data to SwiftData on first launch.
    static func migrateFromUserDefaults(context: ModelContext) {
        let migrationKey = "ihymns_swiftdata_migrated"
        guard !UserDefaults.standard.bool(forKey: migrationKey) else { return }

        // Migrate favourites
        let savedFavorites = UserDefaults.standard.stringArray(forKey: "ihymns_favorites") ?? []
        for songId in savedFavorites {
            let record = FavouriteRecord(songId: songId)
            context.insert(record)
        }

        // Migrate set lists
        if let data = UserDefaults.standard.data(forKey: "ihymns_setlists"),
           let setLists = try? JSONDecoder().decode([SetList].self, from: data) {
            for list in setLists {
                let record = SetListRecord(
                    id: list.id, name: list.name, songIds: list.songIds,
                    createdAt: list.createdAt, updatedAt: list.updatedAt, shareId: list.shareId
                )
                context.insert(record)
            }
        }

        try? context.save()
        UserDefaults.standard.set(true, forKey: migrationKey)
    }
}
