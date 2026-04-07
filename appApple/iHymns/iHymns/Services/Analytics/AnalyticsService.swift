// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AnalyticsService.swift
//  iHymns
//
//  Multi-provider analytics service dispatching events to:
//  - Supabase Analytics (replaces Firebase GA4 for privacy/control)
//  - Plausible Analytics (privacy-focused, cookieless)
//
//  All tracking gated behind ATT consent and GDPR user toggle.
//

import Foundation
import SwiftUI

#if canImport(AppTrackingTransparency)
import AppTrackingTransparency
#endif

// MARK: - AnalyticsEvent

struct AnalyticsEvent {
    let name: String
    let properties: [String: String]
    let timestamp: Date

    init(name: String, properties: [String: String] = [:]) {
        self.name = name
        self.properties = properties
        self.timestamp = Date()
    }

    static func screenView(_ screen: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "screen_view", properties: ["screen": screen])
    }

    static func songView(_ song: Song) -> AnalyticsEvent {
        AnalyticsEvent(name: "song_view", properties: [
            "song_id": song.id, "songbook": song.songbook, "number": String(song.number)
        ])
    }

    static func search(query: String, resultCount: Int) -> AnalyticsEvent {
        AnalyticsEvent(name: "search", properties: [
            "query": query, "result_count": String(resultCount)
        ])
    }

    static func favoriteToggle(songId: String, isFavorite: Bool) -> AnalyticsEvent {
        AnalyticsEvent(name: isFavorite ? "favorite_add" : "favorite_remove", properties: ["song_id": songId])
    }

    static func setListCreate(name: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "setlist_create", properties: ["name": name])
    }

    static func setListShare(id: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "setlist_share", properties: ["id": id])
    }

    static func scrollDepth(songId: String, percent: Int) -> AnalyticsEvent {
        AnalyticsEvent(name: "scroll_depth", properties: ["song_id": songId, "percent": String(percent)])
    }

    static func sessionHeartbeat(duration: TimeInterval) -> AnalyticsEvent {
        AnalyticsEvent(name: "session_heartbeat", properties: ["duration_seconds": String(Int(duration))])
    }
}

// MARK: - AnalyticsConsent

enum AnalyticsConsent: String, Codable {
    case notAsked = "not_asked"
    case granted = "granted"
    case denied = "denied"
}

// MARK: - AnalyticsConfiguration

/// Build-time analytics configuration. Set these values in your
/// Xcode scheme environment variables or via a configuration file.
enum AnalyticsConfiguration {
    /// Supabase project URL (e.g., "https://xxxxx.supabase.co")
    static var supabaseURL: String? {
        ProcessInfo.processInfo.environment["IHYMNS_SUPABASE_URL"]
        ?? Bundle.main.infoDictionary?["IHYMNS_SUPABASE_URL"] as? String
    }

    /// Supabase anonymous API key
    static var supabaseAnonKey: String? {
        ProcessInfo.processInfo.environment["IHYMNS_SUPABASE_ANON_KEY"]
        ?? Bundle.main.infoDictionary?["IHYMNS_SUPABASE_ANON_KEY"] as? String
    }

    /// Plausible domain (default: ihymns.app)
    static let plausibleDomain = "ihymns.app"

    /// Plausible API endpoint
    static let plausibleAPIURL = URL(string: "https://plausible.io/api/event")!
}

// MARK: - AnalyticsService

@MainActor
final class AnalyticsService: ObservableObject {

    static let shared = AnalyticsService()

    @Published var consent: AnalyticsConsent
    @Published var attStatus: String = "unknown"

    private static let consentKey = "ihymns_analytics_consent"

    private var supabaseProvider: SupabaseAnalyticsProvider?
    private var plausibleProvider: PlausibleAnalyticsProvider?
    private var heartbeatTask: Task<Void, Never>?
    private var sessionStartTime: Date = Date()

    init() {
        let raw = UserDefaults.standard.string(forKey: AnalyticsService.consentKey) ?? "not_asked"
        self.consent = AnalyticsConsent(rawValue: raw) ?? .notAsked
    }

    // MARK: - ATT Prompt

    func requestTrackingPermission() async {
        #if canImport(AppTrackingTransparency) && os(iOS)
        let status = await ATTrackingManager.requestTrackingAuthorization()
        switch status {
        case .authorized:  attStatus = "authorized"
        case .denied:      attStatus = "denied"
        case .restricted:  attStatus = "restricted"
        case .notDetermined: attStatus = "not_determined"
        @unknown default:  attStatus = "unknown"
        }
        #endif
    }

    // MARK: - Consent Management

    func grantConsent() {
        consent = .granted
        UserDefaults.standard.set(consent.rawValue, forKey: AnalyticsService.consentKey)
        initialiseProviders()
        startSessionHeartbeat()
    }

    func denyConsent() {
        consent = .denied
        UserDefaults.standard.set(consent.rawValue, forKey: AnalyticsService.consentKey)
        stopSessionHeartbeat()
        supabaseProvider?.flush()
        supabaseProvider = nil
        plausibleProvider = nil
    }

    var isTrackingEnabled: Bool { consent == .granted }

    // MARK: - Provider Initialisation

    private func initialiseProviders() {
        guard consent == .granted else { return }
        supabaseProvider = SupabaseAnalyticsProvider()
        plausibleProvider = PlausibleAnalyticsProvider()
    }

    // MARK: - Event Tracking

    func track(_ event: AnalyticsEvent) {
        guard isTrackingEnabled else { return }
        supabaseProvider?.track(event)
        plausibleProvider?.track(event)
    }

    func trackScreen(_ name: String) {
        track(.screenView(name))
    }

    // MARK: - Session Heartbeat

    /// Sends a heartbeat event every 30 seconds to track engagement.
    func startSessionHeartbeat() {
        sessionStartTime = Date()
        stopSessionHeartbeat()
        heartbeatTask = Task {
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(30))
                guard !Task.isCancelled, isTrackingEnabled else { break }
                let duration = Date().timeIntervalSince(sessionStartTime)
                track(.sessionHeartbeat(duration: duration))
            }
        }
    }

    func stopSessionHeartbeat() {
        heartbeatTask?.cancel()
        heartbeatTask = nil
    }

    /// Flush pending events — call on app backgrounding.
    func flushPendingEvents() {
        supabaseProvider?.flush()
    }
}

// MARK: - Supabase Analytics Provider

final class SupabaseAnalyticsProvider {

    private var eventQueue: [AnalyticsEvent] = []
    private let maxBatchSize = 20

    func track(_ event: AnalyticsEvent) {
        eventQueue.append(event)
        if eventQueue.count >= maxBatchSize {
            flush()
        }
    }

    func flush() {
        guard let supabaseURL = AnalyticsConfiguration.supabaseURL,
              let anonKey = AnalyticsConfiguration.supabaseAnonKey,
              !eventQueue.isEmpty else {
            eventQueue.removeAll()
            return
        }

        let batch = eventQueue
        eventQueue.removeAll()

        Task {
            guard let url = URL(string: "\(supabaseURL)/rest/v1/analytics_events") else { return }
            var request = URLRequest(url: url)
            request.httpMethod = "POST"
            request.addValue("application/json", forHTTPHeaderField: "Content-Type")
            request.addValue("Bearer \(anonKey)", forHTTPHeaderField: "Authorization")
            request.addValue(anonKey, forHTTPHeaderField: "apikey")

            let payload = batch.map { event in
                [
                    "event_name": event.name,
                    "properties": event.properties,
                    "timestamp": ISO8601DateFormatter().string(from: event.timestamp),
                    "platform": "apple",
                    "app_version": AppInfo.Application.Version.bundleVersion
                ] as [String: Any]
            }

            request.httpBody = try? JSONSerialization.data(withJSONObject: payload)
            _ = try? await URLSession.shared.data(for: request)
        }
    }
}

// MARK: - Plausible Analytics Provider

final class PlausibleAnalyticsProvider {

    func track(_ event: AnalyticsEvent) {
        Task {
            var request = URLRequest(url: AnalyticsConfiguration.plausibleAPIURL)
            request.httpMethod = "POST"
            request.addValue("application/json", forHTTPHeaderField: "Content-Type")
            request.addValue("iHymns Apple/\(AppInfo.Application.Version.bundleVersion)", forHTTPHeaderField: "User-Agent")

            let payload: [String: Any] = [
                "domain": AnalyticsConfiguration.plausibleDomain,
                "name": event.name,
                "url": "app://ihymns.app/\(event.properties["screen"] ?? event.name)",
                "props": event.properties
            ]

            request.httpBody = try? JSONSerialization.data(withJSONObject: payload)
            _ = try? await URLSession.shared.data(for: request)
        }
    }
}
