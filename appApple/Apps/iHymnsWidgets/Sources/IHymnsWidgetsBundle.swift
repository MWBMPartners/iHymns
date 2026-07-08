// IHymnsWidgetsBundle.swift
// iHymnsWidgets (WidgetKit extension shell)
//
// ELI5: The Home Screen / Lock Screen widget(s) — right now just one
// placeholder that says "iHymns," proving the extension itself boots. Real
// widgets (Song of the Day, favourites, resume-last-song — strategy §2.3's
// MVP native-edge set) replace this in Phase 1.5.
//
// DETAILED: Unlike the three `App`-based shells (iHymns/iHymnsTV/
// iHymnsWatch), a widget extension's entry point is a `WidgetBundle`, not
// an `App` — WidgetKit renders widget views on a fixed timeline outside the
// normal app lifecycle (see Apple's WidgetKit overview:
// https://developer.apple.com/documentation/widgetkit). Bundle id
// `app.ihymns.widgets` (the second Apple-forced derivative id, strategy
// §1.7), embedded into `iHymns` via `project.yml`'s dependency list with
// `destinationFilters: [iOS]` — this Phase-0 skeleton widget only declares
// iOS as its `supportedDestinations`, so it's only meaningful embedded in
// the iOS variant of the multiplatform host app for now; Phase 1.5 widens
// this alongside the real widget set strategy §2.3 describes for macOS/
// visionOS too.
//
// #1412 NOTE — `IHFonts.registerBundledFonts()` (`IHDesign`) is
// deliberately NOT called from this shell yet: this Phase-0 placeholder
// renders only the literal string "iHymns" in the system `.headline` font,
// no lyric/song text (`PhaseZeroWidgetView` below), so there is nothing
// here for the bundled OpenDyslexic font to apply to. Wire it in
// alongside the real Song-of-the-Day/favourites/resume widgets (Phase
// 1.5, strategy §2.3) if/when they render lyric snippets — this extension
// is its own separate process, so it needs its own registration call at
// that point, exactly like the three `App` shells' `init()`.
import IHDesign
import SwiftUI
import WidgetKit

@main
struct IHymnsWidgetsBundle: WidgetBundle {
    var body: some Widget {
        PhaseZeroWidget()
    }
}

/// The timeline entry for `PhaseZeroWidget` — just "now," since this
/// placeholder never changes.
///
/// ELI5: "What time is this snapshot of the widget for?" — always "right
/// now," because there's nothing dynamic to show yet.
struct PhaseZeroEntry: TimelineEntry {
    let date: Date
}

/// Supplies `PhaseZeroWidget` with its (single, unchanging) timeline entry.
///
/// ELI5: WidgetKit asks this "what should the widget show, and when should
/// it show something else?" — we answer "just this one thing, forever"
/// (`.never` reload policy) since this is a placeholder.
struct PhaseZeroProvider: TimelineProvider {
    func placeholder(in context: Context) -> PhaseZeroEntry {
        PhaseZeroEntry(date: .now)
    }

    func getSnapshot(in context: Context, completion: @escaping (PhaseZeroEntry) -> Void) {
        completion(PhaseZeroEntry(date: .now))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<PhaseZeroEntry>) -> Void) {
        completion(Timeline(entries: [PhaseZeroEntry(date: .now)], policy: .never))
    }
}

/// The widget's rendered content — reuses `IHColorTokens` so even this
/// placeholder matches the rest of the app's accent colour.
struct PhaseZeroWidgetView: View {
    let entry: PhaseZeroProvider.Entry

    var body: some View {
        Text("iHymns")
            .font(.headline)
            .foregroundStyle(IHColorTokens.accent)
    }
}

/// The Phase-0 placeholder widget itself.
///
/// ELI5: A tiny "iHymns" card you could (in theory) add to your Home
/// Screen today — it just doesn't do anything real yet.
struct PhaseZeroWidget: Widget {
    let kind: String = "IHymnsPhaseZeroWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: PhaseZeroProvider()) { entry in
            PhaseZeroWidgetView(entry: entry)
        }
        .configurationDisplayName("iHymns")
        .description("Phase‑0 skeleton widget — replaced by Song of the Day / favourites in Phase 1.5.")
    }
}
