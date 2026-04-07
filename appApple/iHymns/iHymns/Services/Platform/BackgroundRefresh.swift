// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  BackgroundRefresh.swift
//  iHymns
//
//  Manages background data refresh using BGTaskScheduler.
//  Periodically checks for updated songs.json from the API
//  and optionally prompts the user to update.
//

import Foundation
import SwiftUI

#if canImport(BackgroundTasks) && os(iOS)
import BackgroundTasks
#endif

// MARK: - BackgroundRefreshManager

@MainActor
final class BackgroundRefreshManager {

    static let shared = BackgroundRefreshManager()

    /// Task identifier registered in Info.plist.
    static let taskIdentifier = "ltd.mwbmpartners.ihymns.refresh"

    // MARK: - Registration

    /// Registers the background refresh task with the system.
    /// Must be called from application(_:didFinishLaunchingWithOptions:)
    /// or the App struct init.
    func registerBackgroundTask() {
        #if canImport(BackgroundTasks) && os(iOS)
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: BackgroundRefreshManager.taskIdentifier,
            using: nil
        ) { task in
            Task { @MainActor in
                await self.handleBackgroundRefresh(task: task as! BGAppRefreshTask)
            }
        }
        #endif
    }

    /// Schedules the next background refresh.
    /// Called after each successful refresh and on app launch.
    func scheduleBackgroundRefresh() {
        #if canImport(BackgroundTasks) && os(iOS)
        let request = BGAppRefreshTaskRequest(identifier: BackgroundRefreshManager.taskIdentifier)
        // Request no earlier than 6 hours from now
        request.earliestBeginDate = Date(timeIntervalSinceNow: 6 * 60 * 60)

        do {
            try BGTaskScheduler.shared.submit(request)
        } catch {
            // Background refresh scheduling failed — non-critical
        }
        #endif
    }

    // MARK: - Task Handling

    #if canImport(BackgroundTasks) && os(iOS)
    private func handleBackgroundRefresh(task: BGAppRefreshTask) async {
        // Schedule the next refresh before starting
        scheduleBackgroundRefresh()

        // Set expiration handler
        task.expirationHandler = {
            task.setTaskCompleted(success: false)
        }

        // Attempt API sync
        let apiClient = APIClient()
        do {
            let updatedData = try await apiClient.fetchSongsJSON()
            if updatedData != nil {
                // Data was updated — notify the user on next launch
                UserDefaults.standard.set(true, forKey: "ihymns_update_available")
                UserDefaults.standard.set(Date(), forKey: "ihymns_last_bg_sync")
            }
            task.setTaskCompleted(success: true)
        } catch {
            task.setTaskCompleted(success: false)
        }
    }
    #endif
}

// MARK: - Update Prompt Modifier

/// A view modifier that shows a prompt when new song data is available
/// from a background refresh.
struct UpdateAvailableModifier: ViewModifier {

    @EnvironmentObject var songStore: SongStore
    @State private var showingUpdateAlert = false

    func body(content: Content) -> some View {
        content
            .onAppear {
                if UserDefaults.standard.bool(forKey: "ihymns_update_available") {
                    if songStore.preferences.autoUpdateSongs {
                        // Silent update
                        Task { await songStore.syncFromAPI() }
                        UserDefaults.standard.set(false, forKey: "ihymns_update_available")
                    } else {
                        // Prompted update
                        showingUpdateAlert = true
                    }
                }
            }
            .alert("Song Data Update", isPresented: $showingUpdateAlert) {
                Button("Update Now") {
                    Task { await songStore.syncFromAPI() }
                    UserDefaults.standard.set(false, forKey: "ihymns_update_available")
                }
                Button("Later", role: .cancel) {
                    UserDefaults.standard.set(false, forKey: "ihymns_update_available")
                }
            } message: {
                Text("New song data is available. Would you like to update now?")
            }
    }
}

extension View {
    func checkForSongUpdates() -> some View {
        modifier(UpdateAvailableModifier())
    }
}
