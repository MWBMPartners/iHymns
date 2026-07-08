// KeyboardShortcutsOverlayView.swift
// IHFeatures
//
// ELI5: A plain, honest "here's every keyboard shortcut this app supports"
// list — press ⌘/ on a Mac, or find "Keyboard Shortcuts" in Settings, and
// this shows up.
//
// DETAILED: #185's "Keyboard shortcuts overlay (press ? on iPad/Mac —
// syntax fixed)" checklist item — ticked complete on the issue but not
// actually built (verified before starting: no `CommandGroup`, no
// `onKeyPress`, no "shortcuts" string anywhere in this package). Reachable
// two ways: `IHymnsApp.swift`'s `#if os(macOS)` `.commands` block (⌘/, the
// conventional "show me the shortcuts" combo many Mac apps use — Slack,
// Linear, GitHub Desktop — chosen over a literal bare "?" because a bare,
// unmodified `?` key would fire while the user is simply typing a question
// mark into `CatalogueListView`'s search field, which is exactly the kind
// of accidental-trigger bug a shortcuts-help feature must not itself
// cause), and a "Keyboard Shortcuts" row in `SettingsView` (reachable on
// EVERY platform, including iPhone/touch-only, since an external keyboard
// can be paired even there, and there's no harm in a sighted list of
// shortcuts on a device that happens not to have one attached).
//
// A plain static list (not derived from `Commands`/`ToolbarItem`
// introspection — SwiftUI has no API to enumerate those at runtime) kept
// hand-in-sync with the two real shortcut sources: `IHymnsApp.swift`'s Mac
// `CommandGroup` (section switching, Find) and `SongPagerView.swift`'s
// Previous/Next ← → buttons. If either of those changes, update this list
// alongside it — the same "one small, honest source of truth" posture
// `RootContainerView`'s own "coming soon" placeholder comment already
// takes toward not overstating what's actually built.
import SwiftUI

/// One row's worth of "keys → what it does."
struct KeyboardShortcutDescription: Identifiable {
    let id = UUID()
    let keys: String
    let action: String
}

/// The full, current list of shortcuts this app supports — see this file's
/// header for why this is a hand-maintained list rather than something
/// introspected at runtime.
enum KeyboardShortcutCatalog {
    static let shortcuts: [KeyboardShortcutDescription] = [
        KeyboardShortcutDescription(keys: "⌘1", action: "Go to Home"),
        KeyboardShortcutDescription(keys: "⌘2", action: "Go to Songbooks"),
        KeyboardShortcutDescription(keys: "⌘3", action: "Go to Search"),
        KeyboardShortcutDescription(keys: "⌘4", action: "Go to Favourites"),
        KeyboardShortcutDescription(keys: "⌘5", action: "Go to Setlists"),
        KeyboardShortcutDescription(keys: "⌘6", action: "Go to Live"),
        KeyboardShortcutDescription(keys: "⌘7", action: "Go to Settings"),
        KeyboardShortcutDescription(keys: "⌘F", action: "Find (jump to Search)"),
        KeyboardShortcutDescription(keys: "←  →", action: "Previous / Next song (when reading a songbook or setlist)"),
        KeyboardShortcutDescription(keys: "⌘/", action: "Show this Keyboard Shortcuts list")
    ]
}

/// A plain, dismissible list of every keyboard shortcut this app supports.
///
/// ELI5: "What can I press?"
struct KeyboardShortcutsOverlayView: View {
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List(KeyboardShortcutCatalog.shortcuts) { shortcut in
                HStack {
                    Text(shortcut.action)
                    Spacer(minLength: 12)
                    Text(shortcut.keys)
                        .font(.system(.body, design: .monospaced))
                        .foregroundStyle(.secondary)
                }
                .accessibilityElement(children: .combine)
            }
            .listStyle(.plain)
            .navigationTitle("Keyboard Shortcuts")
            #if os(iOS) || os(visionOS)
            .navigationBarTitleDisplayMode(.inline)
            #endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }
}
