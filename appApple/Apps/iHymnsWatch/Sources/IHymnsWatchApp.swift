// IHymnsWatchApp.swift
// iHymnsWatch (watchOS shell — embedded companion app)
//
// ELI5: What the Apple Watch app shows when you open it.
//
// DETAILED: Bundle id `app.ihymns.watchkitapp` (the one Apple-forced
// derivative bundle id besides the widgets extension — strategy §1.7) and
// EMBEDDED into the `iHymns` app target (`project.yml`'s
// `iHymns.dependencies` embeds this target with `destinationFilters: [iOS]`
// — a watch companion embeds into the iOS binary specifically, not the
// macOS/visionOS variants of the multiplatform target). Runs standalone at
// runtime once installed (modern watchOS "single target app" model — no
// separate WatchKit Extension target needed, unlike pre-watchOS-9 apps).
// Strategy §2.2's real watch UI is a glance/remote companion (`TabView`
// with Now/Remote/Favourites/Setlist) — Phase 1 work; Phase 0 needs only
// the one shared placeholder screen to prove the shell boots.
//
// Named `IHymnsWatchApp` (capital "I") to satisfy SwiftLint's `type_name`
// rule and stay consistent with the other shells — see `IHymnsApp.swift`'s
// header comment for the full rationale.
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsWatchApp: App {
    var body: some Scene {
        WindowGroup {
            NavigationStack {
                PhaseZeroSkeletonView(shellName: "watchOS")
            }
        }
    }
}
