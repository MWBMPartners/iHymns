// WatchRootView.swift
// IHFeatures/WatchRelay
//
// ELI5: The very first thing the watch app shows — it wakes up the
// watch's half of the phone link, asks for the latest status right away,
// and then re-asks every time you raise your wrist and the app comes back
// to the front.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §5/§6.1 —
// BINDING; strategy §2.4.2's "Watch → relays through the paired iPhone
// via WatchConnectivity"). `#if os(watchOS) && canImport(WatchConnectivity)`
// — MATCHES `WatchRemoteController`'s own gate exactly (this module's
// sibling file) so the two types can never exist independently of one
// another. `@State private var controller` (not a `let`) is deliberate:
// `WatchRootView` OWNS the controller for the lifetime of the watch app's
// one window (D-12, "the watch app IS the remote screen for now") —
// `WatchRemoteView` merely borrows it to render from.
import SwiftUI
#if os(watchOS) && canImport(WatchConnectivity)

/// The watch app's root scene content — see this file's header for the
/// full picture.
///
/// ELI5: "Open the watch app → wake the link → show the remote."
public struct WatchRootView: View {
    @State private var controller = WatchRemoteController()
    @Environment(\.scenePhase) private var scenePhase

    public init() {}

    public var body: some View {
        NavigationStack {
            WatchRemoteView(controller: controller)
        }
        .task {
            // `.task` runs once per view identity, before the first
            // render — `activate()` wires up the WCSession delegate +
            // consumer loop (idempotent, safe to call again), and the
            // immediate `refresh()` is the cold-launch pull. `refresh()`
            // is silently swallowed if `.activated` hasn't arrived yet
            // (`WatchRemoteController.send(_:)`'s fix for the false
            // "iPhone not reachable" flash) — the very next `.activated`
            // event re-triggers its own `refresh()`.
            controller.activate()
            controller.refresh()
        }
        .onChange(of: scenePhase) { _, newPhase in
            // A watch app resuming from always-on/background must
            // re-pull live truth — `updateApplicationContext`'s catch-up
            // delivery is opportunistic (best-effort, not guaranteed to
            // have landed while this scene was suspended), so an explicit
            // `refresh()` on every `.active` transition is the honest
            // belt-and-braces read.
            if newPhase == .active {
                controller.refresh()
            }
        }
    }
}

#Preview {
    WatchRootView()
}
#endif
