// RemoteControlView.swift
// IHFeatures
//
// ELI5: The "TV Remote" screen itself — shows every TV you already trust
// plus any nearby ones you don't, lets you pair a new one (by scanning or
// pasting), and swaps to the actual remote control the moment you're
// connected. It also politely explains WHY it's about to ask for local
// -network permission before the system prompt just appears out of nowhere.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.3). Owns the ONE
// `RemoteControlCoordinator` for this screen (the `TVRootView.swift:54-61`
// "build the composition root in `init`" pattern) and renders entirely from
// its `uiPhase` — this view never reaches into `RemoteControlSession`/
// `RemoteSessionActor` itself. `scenePhase` drives `setScenePhaseActive(_:)`
// (strategy §2.4.4's "backgrounded remote = detached, TV holds state, <1s
// reconnect").
import IHLive
import SwiftUI

/// The remote-control screen scaffold — see this file's header.
public struct RemoteControlView: View {
    private let rootViewModel: AppRootViewModel
    @State private var coordinator = RemoteControlCoordinator()
    @State private var isPresentingPairingSheet = false
    @State private var hasSeenPrimer = IHSettingsStore().hasSeenLocalNetworkPrimer
    @Environment(\.scenePhase) private var scenePhase

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle("TV Remote")
            .task { await coordinator.start() }
            .onDisappear { Task { await coordinator.stop() } }
            .onChange(of: scenePhase) { _, newPhase in
                Task { await coordinator.setScenePhaseActive(newPhase == .active) }
            }
            .sheet(isPresented: $isPresentingPairingSheet) {
                PairingPayloadEntrySheet(
                    onResolved: { string in
                        isPresentingPairingSheet = false
                        coordinator.handleScannedOrPasted(string)
                    },
                    onCancel: { isPresentingPairingSheet = false }
                )
            }
    }

    @ViewBuilder
    private var content: some View {
        switch coordinator.uiPhase {
        case .browsing:
            browsingView
        case .connecting(let tvName, _), .reconnecting(let tvName, _):
            browsingView.overlay(alignment: .bottom) { connectingBanner(tvName: tvName) }
        case .codeEntry(let tvName, let failedAttempts):
            PairingCodeEntrySheet(
                tvName: tvName, failedAttempts: failedAttempts,
                onSubmit: { code in Task { await coordinator.submitCode(code) } },
                onCancel: { Task { await coordinator.cancelPairing() } }
            )
        case .controlling(let tvName, let state):
            RemoteControlSurfaceView(
                tvName: tvName, state: state, rootViewModel: rootViewModel,
                onIntent: { message in Task { await coordinator.sendIntent(message) } },
                onDisconnect: { Task { await coordinator.disconnect() } }
            )
        case .suspended(let tvName):
            ContentUnavailableView("Reconnecting to \(tvName)…", systemImage: "antenna.radiowaves.left.and.right")
        }
    }

    // MARK: - Browsing (list / primer / empty state)

    @ViewBuilder
    private var browsingView: some View {
        if !hasSeenPrimer {
            localNetworkPrimerCard
        } else if let notice = coordinator.notice, coordinator.rows.isEmpty {
            ContentUnavailableView("Couldn't Connect", systemImage: "wifi.exclamationmark", description: Text(notice))
                .toolbar { pairingToolbar }
        } else if coordinator.rows.isEmpty {
            emptyStateView
                .toolbar { pairingToolbar }
        } else {
            List {
                if let notice = coordinator.notice {
                    Section {
                        Text(notice).foregroundStyle(.secondary)
                    }
                }
                ForEach(coordinator.rows) { row in
                    rowView(row)
                }
            }
            .toolbar { pairingToolbar }
        }
    }

    private var localNetworkPrimerCard: some View {
        ContentUnavailableView {
            Label("Find Your TV", systemImage: "wifi")
        } description: {
            Text("iHymns will ask to find devices on your local network — that's how it finds your Apple TV.")
        } actions: {
            Button("Continue") {
                hasSeenPrimer = true
                Task { await coordinator.acknowledgeLocalNetworkPrimer() }
            }
        }
    }

    private var emptyStateView: some View {
        ContentUnavailableView {
            Label("No TVs Yet", systemImage: "appletv")
        } description: {
            #if os(iOS)
            Text("Open iHymns on your Apple TV, then scan the QR code in its Settings → Remote Control.")
            #else
            Text(
                "Open iHymns on your Apple TV, then scan the QR code in its Settings → Remote Control… "
                    + "or paste the pairing code from the TV screen."
            )
            #endif
        } actions: {
            Button("Enter Code") { isPresentingPairingSheet = true }
        }
    }

    private func connectingBanner(tvName: String) -> some View {
        HStack(spacing: 8) {
            ProgressView()
            Text("Connecting to \(tvName)…")
            Spacer()
            Button("Cancel") { Task { await coordinator.disconnect() } }
        }
        .padding()
        .background(.thinMaterial)
    }

    @ToolbarContentBuilder
    private var pairingToolbar: some ToolbarContent {
        #if os(iOS)
        ToolbarItem(placement: .primaryAction) {
            Button {
                isPresentingPairingSheet = true
            } label: {
                Label("Scan", systemImage: "qrcode.viewfinder")
            }
        }
        #endif
        ToolbarItem(placement: .primaryAction) {
            Button {
                isPresentingPairingSheet = true
            } label: {
                Label("Enter Code", systemImage: "keyboard")
            }
        }
    }

    // MARK: - Rows

    @ViewBuilder
    private func rowView(_ row: RemoteTVListRow) -> some View {
        switch row.kind {
        case .paired(let record):
            Button {
                coordinator.connect(row: row)
            } label: {
                pairedRowLabel(record, isNearby: row.isNearby)
            }
            .buttonStyle(.plain)
            // `.swipeActions` is UNAVAILABLE on tvOS (the `SetlistsView.swift`
            // precedent this mirrors) — moot here in practice (this screen is
            // only ever reached from the `iHymns` app shell's `.live` tab,
            // never tvOS's `TVRootView`), but `IHFeatures` still compiles for
            // every platform (`Package.swift`), so the guard is required for
            // this FILE to build there at all.
            #if os(tvOS)
            .contextMenu {
                Button("Forget", role: .destructive) { Task { await coordinator.forget(record) } }
            }
            #else
            .swipeActions(edge: .trailing) {
                Button("Forget", role: .destructive) { Task { await coordinator.forget(record) } }
            }
            #endif
        case .nearbyUnpaired(let service):
            nearbyRowLabel(service)
        }
    }

    /// A saved, connectable TV — spec §4 path C.
    private func pairedRowLabel(_ record: PairedTVRecord, isNearby: Bool) -> some View {
        HStack {
            Image(systemName: "appletv")
            VStack(alignment: .leading, spacing: 2) {
                Text(record.name)
                if let lastConnectedAt = record.lastConnectedAt {
                    Text(lastConnectedAt, style: .relative).font(.caption).foregroundStyle(.secondary)
                }
            }
            Spacer(minLength: 12)
            if isNearby {
                Text("Nearby").font(.caption).foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(isNearby ? "\(record.name), nearby" : record.name)
    }

    /// An UNPAIRED nearby TV — informational only (spec §4 row C′): tapping
    /// this row itself does nothing (`RemoteControlCoordinator.connect(row:)`
    /// no-ops for `.nearbyUnpaired`); the ONLY affordance is the button
    /// below, which opens the SAME scan/paste sheet the toolbar does.
    private func nearbyRowLabel(_ service: LANRemoteDiscoveredService) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Image(systemName: "appletv")
                Text(service.name)
                Spacer(minLength: 12)
                Text("Nearby").font(.caption).foregroundStyle(.secondary)
            }
            Text("To pair, scan the QR code shown on this TV.")
                .font(.caption)
                .foregroundStyle(.secondary)
            Button("Scan or Enter Code") { isPresentingPairingSheet = true }
                .font(.caption)
        }
        .accessibilityElement(children: .combine)
    }
}
