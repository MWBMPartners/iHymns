// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SharePlayManager.swift
//  iHymns
//
//  SharePlay group activity for synchronised worship sessions.
//  Multiple users see the same song/lyrics in real-time.
//

import Foundation
import GroupActivities
import SwiftUI

// MARK: - Worship Session Activity

/// Defines a GroupActivity for shared worship/song viewing.
struct WorshipSessionActivity: GroupActivity {

    static let activityIdentifier = "ltd.mwbmpartners.ihymns.worship"

    var metadata: GroupActivityMetadata {
        var meta = GroupActivityMetadata()
        meta.title = "iHymns Worship Session"
        meta.subtitle = "Sing along together"
        meta.type = .generic
        return meta
    }
}

// MARK: - Session Message

/// Messages exchanged between participants to sync state.
struct WorshipSessionMessage: Codable {
    enum MessageType: String, Codable {
        case songChanged
        case componentChanged
        case fontSizeChanged
        case autoScrollToggled
    }

    let type: MessageType
    let songId: String?
    let componentIndex: Int?
    let fontSize: Double?
    let isAutoScrolling: Bool?
    let timestamp: Date
}

// MARK: - SharePlay Manager

@MainActor
@Observable
final class SharePlayManager {

    var isSessionActive = false
    var participantCount = 0
    var isLeader = false
    var currentSharedSongId: String?

    private var groupSession: GroupSession<WorshipSessionActivity>?
    private var messenger: GroupSessionMessenger?
    private var tasks = Set<Task<Void, Never>>()

    // MARK: - Start Session

    /// Initiates a SharePlay worship session.
    func startSession() async {
        let activity = WorshipSessionActivity()

        switch await activity.prepareForActivation() {
        case .activationPreferred:
            do {
                _ = try await activity.activate()
            } catch {
                // Activation failed
            }
        case .activationDisabled:
            break
        default:
            break
        }
    }

    /// Configures the group session once joined.
    func configureSession(_ session: GroupSession<WorshipSessionActivity>) {
        groupSession = session
        messenger = GroupSessionMessenger(session: session)
        isSessionActive = true

        let participantTask = Task {
            for await session in WorshipSessionActivity.sessions() {
                self.participantCount = session.activeParticipants.count
                self.isLeader = session.activeParticipants.first?.id == session.localParticipant.id
            }
        }
        tasks.insert(participantTask)

        // Listen for messages
        if let messenger = messenger {
            let messageTask = Task {
                for await (message, _) in messenger.messages(of: WorshipSessionMessage.self) {
                    await handleMessage(message)
                }
            }
            tasks.insert(messageTask)
        }

        session.join()
    }

    // MARK: - Send Updates

    func sendSongChange(songId: String) {
        guard let messenger else { return }
        let msg = WorshipSessionMessage(
            type: .songChanged, songId: songId, componentIndex: nil,
            fontSize: nil, isAutoScrolling: nil, timestamp: Date()
        )
        Task { try? await messenger.send(msg) }
    }

    func sendComponentChange(index: Int) {
        guard let messenger else { return }
        let msg = WorshipSessionMessage(
            type: .componentChanged, songId: nil, componentIndex: index,
            fontSize: nil, isAutoScrolling: nil, timestamp: Date()
        )
        Task { try? await messenger.send(msg) }
    }

    // MARK: - Handle Messages

    private func handleMessage(_ message: WorshipSessionMessage) async {
        switch message.type {
        case .songChanged:
            currentSharedSongId = message.songId
        case .componentChanged:
            // Followers update their component index
            break
        case .fontSizeChanged, .autoScrollToggled:
            break
        }
    }

    // MARK: - End Session

    func endSession() {
        groupSession?.end()
        groupSession = nil
        messenger = nil
        isSessionActive = false
        participantCount = 0
        for task in tasks { task.cancel() }
        tasks.removeAll()
    }
}
