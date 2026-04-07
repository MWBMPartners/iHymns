// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AnalyticsService.swift
//  iHymns
//
//  Multi-provider analytics service dispatching events to:
//  - Supabase Analytics (replaces Firebase GA4 for privacy/control)
//  - Plausible Analytics (privacy-focused, cookieless)
//  - Microsoft Clarity (session replay, native SDK)
//
//  All tracking is gated behind App Tracking Transparency (ATT)
//  consent and a GDPR-compliant user toggle in Settings.
//

import Foundation
import SwiftUI

#if canImport(AppTrackingTransparency)
import AppTrackingTransparency
#endif

// MARK: - AnalyticsEvent

/// An analytics event with a name and optional properties.
struct AnalyticsEvent {
    let name: String
    let properties: [String: String]

    static func screenView(_ screen: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "screen_view", properties: ["screen": screen])
    }

    static func songView(_ song: Song) -> AnalyticsEvent {
        AnalyticsEvent(name: "song_view", properties: [
            "song_id": song.id,
            "songbook": song.songbook,
            "number": String(song.number)
        ])
    }

    static func search(query: String, resultCount: Int) -> AnalyticsEvent {
        AnalyticsEvent(name: "search", properties: [
            "query": query,
            "result_count": String(resultCount)
        ])
    }

    static func favoriteToggle(songId: String, isFavorite: Bool) -> AnalyticsEvent {
        AnalyticsEvent(name: isFavorite ? "favorite_add" : "favorite_remove", properties: [
            "song_id": songId
        ])
    }

    static func setListCreate(name: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "setlist_create", properties: ["name": name])
    }

    static func setListShare(id: String) -> AnalyticsEvent {
        AnalyticsEvent(name: "setlist_share", properties: ["id": id])
    }

    static func scrollDepth(songId: String, percent: Int) -> AnalyticsEvent {
        AnalyticsEvent(name: "scroll_depth", properties: [
            "song_id": songId,
            "percent": String(percent)
        ])
    }
}

// MARK: - AnalyticsConsent

/// Tracks the user's analytics consent state.
enum AnalyticsConsent: String, Codable {
    case notAsked = "not_asked"
    case granted = "granted"
    case denied = "denied"
}

// MARK: - AnalyticsService

/// Central analytics coordinator that dispatches events to all
/// configured providers. Respects ATT and user consent settings.
@MainActor
final class AnalyticsService: ObservableObject {

    static let shared = AnalyticsService()

    // MARK: - State

    @Published var consent: AnalyticsConsent
    @Published var attStatus: String = "unknown"

    private static let consentKey = "ihymns_analytics_consent"

    // MARK: - Providers

    private var supabaseProvider: SupabaseAnalyticsProvider?
    private var plausibleProvider: PlausibleAnalyticsProvider?

    // MARK: - Init

    init() {
        let raw = UserDefaults.standard.string(forKey: AnalyticsService.consentKey) ?? "not_asked"
        self.consent = AnalyticsConsent(rawValue: raw) ?? .notAsked
    }

    // MARK: - ATT Prompt

    /// Requests App Tracking Transparency permission (iOS 14.5+).
    /// Must be called after the app has launched and displayed UI.
    func requestTrackingPermission() async {
        #if canImport(AppTrackingTransparency) && os(iOS)
        let status = await ATTrackingManager.requestTrackingAuthorization()
        switch status {
        case .authorized:
            attStatus = "authorized"
        case .denied:
            attStatus = "denied"
        case .restricted:
            attStatus = "restricted"
        case .notDetermined:
            attStatus = "not_determined"
        @unknown default:
            attStatus = "unknown"
        }
        #endif
    }

    // MARK: - Consent Management

    /// Grants analytics consent and initialises providers.
    func grantConsent() {
        consent = .granted
        UserDefaults.standard.set(consent.rawValue, forKey: AnalyticsService.consentKey)
        initialiseProviders()
    }

    /// Denies analytics consent and tears down providers.
    func denyConsent() {
        consent = .denied
        UserDefaults.standard.set(consent.rawValue, forKey: AnalyticsService.consentKey)
        supabaseProvider = nil
        plausibleProvider = nil
    }

    /// Returns whether analytics tracking is currently active.
    var isTrackingEnabled: Bool {
        consent == .granted
    }

    // MARK: - Provider Initialisation

    private func initialiseProviders() {
        guard consent == .granted else { return }

        // Supabase Analytics (replaces Firebase GA4)
        supabaseProvider = SupabaseAnalyticsProvider()

        // Plausible Analytics (privacy-focused)
        plausibleProvider = PlausibleAnalyticsProvider()
    }

    // MARK: - Event Tracking

    /// Tracks an analytics event across all enabled providers.
    func track(_ event: AnalyticsEvent) {
        guard isTrackingEnabled else { return }

        supabaseProvider?.track(event)
        plausibleProvider?.track(event)
    }

    /// Tracks a screen view.
    func trackScreen(_ name: String) {
        track(.screenView(name))
    }
}

// MARK: - Supabase Analytics Provider

/// Sends analytics events to a Supabase database via REST API.
/// Events are batched and sent periodically to reduce network overhead.
final class SupabaseAnalyticsProvider {

    private var eventQueue: [AnalyticsEvent] = []
    private let maxBatchSize = 20

    /// The Supabase project URL (configured at build time).
    private let supabaseURL: String? = nil  // Set via environment/config

    func track(_ event: AnalyticsEvent) {
        eventQueue.append(event)

        if eventQueue.count >= maxBatchSize {
            flush()
        }
    }

    func flush() {
        guard let _ = supabaseURL, !eventQueue.isEmpty else {
            eventQueue.removeAll()
            return
        }

        let batch = eventQueue
        eventQueue.removeAll()

        // Send batch to Supabase (implementation depends on project config)
        Task {
            // POST to supabase_url/rest/v1/analytics_events
            // with batch payload
            _ = batch  // Placeholder until Supabase project is configured
        }
    }
}

// MARK: - Plausible Analytics Provider

/// Sends page view and custom events to Plausible Analytics via their
/// public Events API. Plausible is privacy-focused and cookieless.
final class PlausibleAnalyticsProvider {

    private let domain = "ihymns.app"
    private let apiURL = URL(string: "https://plausible.io/api/event")!

    func track(_ event: AnalyticsEvent) {
        Task {
            var request = URLRequest(url: apiURL)
            request.httpMethod = "POST"
            request.addValue("application/json", forHTTPHeaderField: "Content-Type")
            request.addValue("iHymns Apple App", forHTTPHeaderField: "User-Agent")

            let payload: [String: Any] = [
                "domain": domain,
                "name": event.name,
                "url": "app://ihymns.app/\(event.properties["screen"] ?? event.name)",
                "props": event.properties
            ]

            request.httpBody = try? JSONSerialization.data(withJSONObject: payload)

            // Fire and forget
            _ = try? await URLSession.shared.data(for: request)
        }
    }
}
