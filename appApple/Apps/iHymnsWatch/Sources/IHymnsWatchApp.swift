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
//
// #1412 UPDATE — this shell still renders only `PhaseZeroSkeletonView` (no
// lyric text yet), but it already depends on `IHDesign` here, so
// `IHFonts.registerBundledFonts()` is wired in now (a trivial `init()`
// call) rather than left as a follow-up someone has to remember when the
// real watch glance/remote UI (strategy §2.2) eventually lands — see
// `IHymnsApp.swift`'s matching `init()` for the full rationale.
//
// #1423 UPDATE — the real watch UI has landed: `PhaseZeroSkeletonView` is
// now historical (Phase-0's placeholder proved the shell boots; that job
// is done). This shell now renders `WatchRootView` (`IHFeatures/WatchRelay`)
// directly — the pocket-control remote (`.claude/apple-phase2-pr11-pocket-
// spec.md` §5/§6.1). `WatchRootView` owns its OWN `NavigationStack`
// internally (it needs to, since it also owns the `@State` controller
// scoped to that one screen's lifetime) — this scene composes it bare,
// with NO second `NavigationStack` wrapped around it (a nested
// `NavigationStack` is a SwiftUI anti-pattern: the inner one's navigation
// bar/back-gesture machinery fights the outer one's).
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsWatchApp: App {
    init() {
        IHFonts.registerBundledFonts()
    }

    var body: some Scene {
        WindowGroup {
            WatchRootView()
        }
    }
}
