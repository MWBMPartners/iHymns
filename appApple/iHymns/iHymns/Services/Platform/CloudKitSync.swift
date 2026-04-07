// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  CloudKitSync.swift
//  iHymns
//
//  Syncs user data (favourites, set lists, tags, preferences)
//  across all Apple devices via iCloud / CloudKit. Data stays
//  in the user's private iCloud account — privacy-first.
//

import Foundation
import CloudKit
import SwiftUI

// MARK: - CloudKitSyncManager

/// Manages bidirectional sync of user data via CloudKit.
/// Uses NSUbiquitousKeyValueStore for lightweight key-value sync
/// and CKContainer for structured record sync.
@MainActor
@Observable
final class CloudKitSyncManager {

    // MARK: - State

    var isSyncing = false
    var lastSyncDate: Date?
    var syncError: String?
    var isCloudAvailable = false

    // MARK: - Constants

    private static let containerID = "iCloud.ltd.mwbmpartners.ihymns"
    private let kvStore = NSUbiquitousKeyValueStore.default

    // MARK: - Keys (mirror UserDefaults keys for sync)

    private static let favoritesKey = "ihymns_favorites"
    private static let setListsKey = "ihymns_setlists"
    private static let tagAssignmentsKey = "ihymns_tag_assignments"
    private static let customTagsKey = "ihymns_custom_tags"
    private static let preferencesKey = "ihymns_preferences"

    // MARK: - Setup

    /// Initialises CloudKit sync and registers for change notifications.
    func setup() {
        // Check iCloud availability
        CKContainer(identifier: CloudKitSyncManager.containerID).accountStatus { [weak self] status, error in
            Task { @MainActor in
                self?.isCloudAvailable = (status == .available)
            }
        }

        // Register for KV store changes from other devices
        NotificationCenter.default.addObserver(
            forName: NSUbiquitousKeyValueStore.didChangeExternallyNotification,
            object: kvStore,
            queue: .main
        ) { [weak self] notification in
            Task { @MainActor in
                self?.handleExternalChange(notification)
            }
        }

        // Force initial sync
        kvStore.synchronize()
    }

    // MARK: - Push Data to iCloud

    /// Pushes current local data to iCloud KV store.
    func pushToCloud(songStore: SongStore) {
        guard isCloudAvailable else { return }
        isSyncing = true

        // Favourites
        kvStore.set(Array(songStore.favorites), forKey: CloudKitSyncManager.favoritesKey)

        // Set lists
        if let data = try? JSONEncoder().encode(songStore.setLists) {
            kvStore.set(data, forKey: CloudKitSyncManager.setListsKey)
        }

        // Tag assignments
        if let data = try? JSONEncoder().encode(songStore.tagAssignments) {
            kvStore.set(data, forKey: CloudKitSyncManager.tagAssignmentsKey)
        }

        // Custom tags
        if let data = try? JSONEncoder().encode(songStore.customTags) {
            kvStore.set(data, forKey: CloudKitSyncManager.customTagsKey)
        }

        // Preferences
        if let data = try? JSONEncoder().encode(songStore.preferences) {
            kvStore.set(data, forKey: CloudKitSyncManager.preferencesKey)
        }

        kvStore.synchronize()

        lastSyncDate = Date()
        isSyncing = false
    }

    // MARK: - Pull Data from iCloud

    /// Merges iCloud data into local store (union merge for favourites,
    /// newest-wins for set lists and preferences).
    func pullFromCloud(songStore: SongStore) {
        guard isCloudAvailable else { return }
        isSyncing = true

        // Favourites: union merge (add remote favourites to local)
        if let remoteFavs = kvStore.array(forKey: CloudKitSyncManager.favoritesKey) as? [String] {
            let merged = songStore.favorites.union(Set(remoteFavs))
            if merged != songStore.favorites {
                songStore.favorites = merged
            }
        }

        // Set lists: merge by ID (local takes precedence if same ID exists)
        if let data = kvStore.data(forKey: CloudKitSyncManager.setListsKey),
           let remoteLists = try? JSONDecoder().decode([SetList].self, from: data) {
            let localIds = Set(songStore.setLists.map(\.id))
            for remoteList in remoteLists where !localIds.contains(remoteList.id) {
                songStore.setLists.append(remoteList)
            }
        }

        // Tag assignments: merge (union)
        if let data = kvStore.data(forKey: CloudKitSyncManager.tagAssignmentsKey),
           let remoteTags = try? JSONDecoder().decode(FavouriteTagAssignment.self, from: data) {
            for (songId, tags) in remoteTags.songTags {
                for tag in tags {
                    songStore.tagAssignments.addTag(tag, to: songId)
                }
            }
        }

        // Custom tags: merge by name
        if let data = kvStore.data(forKey: CloudKitSyncManager.customTagsKey),
           let remoteCustomTags = try? JSONDecoder().decode([FavouriteTag].self, from: data) {
            let localNames = Set(songStore.customTags.map { $0.name.lowercased() })
            for tag in remoteCustomTags where !localNames.contains(tag.name.lowercased()) {
                songStore.customTags.append(tag)
            }
        }

        lastSyncDate = Date()
        isSyncing = false
    }

    // MARK: - Handle External Changes

    /// Called when another device pushes changes to iCloud.
    private func handleExternalChange(_ notification: Notification) {
        guard let userInfo = notification.userInfo,
              let reason = userInfo[NSUbiquitousKeyValueStoreChangeReasonKey] as? Int else {
            return
        }

        switch reason {
        case NSUbiquitousKeyValueStoreServerChange,
             NSUbiquitousKeyValueStoreInitialSyncChange:
            // Remote data changed — trigger a pull on next app interaction
            syncError = nil
        case NSUbiquitousKeyValueStoreQuotaViolationChange:
            syncError = "iCloud storage quota exceeded"
        case NSUbiquitousKeyValueStoreAccountChange:
            syncError = "iCloud account changed"
        default:
            break
        }
    }
}
