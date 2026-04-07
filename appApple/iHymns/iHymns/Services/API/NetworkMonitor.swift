// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  NetworkMonitor.swift
//  iHymns
//
//  Monitors network connectivity using NWPathMonitor and publishes
//  connection status changes to the SwiftUI view hierarchy. Views
//  can observe `isConnected` to show offline banners, switch between
//  API and bundled data, or queue operations for later.
//

import Foundation
import Network
import SwiftUI

// MARK: - NetworkMonitor

/// An observable network connectivity monitor.
/// Create a single instance and inject into the environment to allow
/// any view to react to connectivity changes.
@Observable
final class NetworkMonitor {

    // MARK: Published State

    /// Whether the device currently has a viable network path.
    /// `true` when connected via Wi-Fi, cellular, or ethernet.
    private(set) var isConnected: Bool = true

    /// The type of the current network connection.
    private(set) var connectionType: ConnectionType = .unknown

    // MARK: Connection Type

    /// Describes the type of network connection available.
    enum ConnectionType: String {
        case wifi = "Wi-Fi"
        case cellular = "Cellular"
        case ethernet = "Ethernet"
        case unknown = "Unknown"
    }

    // MARK: Private

    /// The underlying NWPathMonitor that observes network changes.
    private let monitor = NWPathMonitor()

    /// A dedicated dispatch queue for network monitoring callbacks.
    private let monitorQueue = DispatchQueue(label: "ltd.mwbmpartners.ihymns.networkmonitor")

    // MARK: Lifecycle

    /// Starts monitoring network connectivity.
    /// Call this once at app launch (e.g., from the App struct init).
    func start() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor [weak self] in
                guard let self else { return }
                self.isConnected = path.status == .satisfied

                if path.usesInterfaceType(.wifi) {
                    self.connectionType = .wifi
                } else if path.usesInterfaceType(.cellular) {
                    self.connectionType = .cellular
                } else if path.usesInterfaceType(.wiredEthernet) {
                    self.connectionType = .ethernet
                } else {
                    self.connectionType = .unknown
                }
            }
        }
        monitor.start(queue: monitorQueue)
    }

    /// Stops the network monitor.
    func stop() {
        monitor.cancel()
    }

    deinit {
        monitor.cancel()
    }
}
