// HelpView.swift
// IHFeatures
//
// ELI5: The in-app "how do I..." page — a few short sections covering the
// features this app actually has, plus a link to the fuller help guide on
// the iHymns website if the quick version here isn't enough.
//
// DETAILED: #190 (Apple Phase 1 — "In-app Help... concise how-to content +
// a link to the web help/support"). Content is a DELIBERATELY SHORT native
// summary, not a mirror of the web's much larger accordion-style help page
// (`appWeb/public_html/includes/pages/help.php`, 21 topics incl. web-only
// features like "Practice / Memorisation Mode," "Requesting a song," and
// the Admin Portal) — this task's own brief calls for "concise," and this
// screen only covers what the NATIVE app actually ships today (verified
// against `SongDetailToolbar.swift`'s real toolbar actions and this
// package's real screens, not the web feature list). Six sections today:
// Browse & Search, Reading a Song, Favourites & Setlists, Live Sessions,
// Offline Songs, and Accessibility. The "Live Sessions" section (#1577)
// covers BOTH Live Follow and Service Mode in one terse paragraph — the
// native `LiveHubView` already keeps the two visually distinct (a
// "Join with a Code…" entry anyone can use, a "Go Live" section gated on
// sign-in), so this screen only needs to point at the Live tab, not
// re-explain the split the web help now leads with. The "More on the
// web" link (`CanonicalURL.help`) is where the fuller guide lives — surveyed
// `appWeb/public_html` first (see that file's own doc comment) rather than
// inventing a `/support`/`/contact` URL that doesn't exist.
import IHAppSupport
import SwiftUI

/// The in-app Help screen, reachable from Settings.
public struct HelpView: View {
    public init() {}

    public var body: some View {
        List {
            Section("Browse & Search") {
                Text("Use the Songbooks tab to explore a hymnal, or the Search tab to find a song by title, number, or songbook. Tap the filter icon to narrow results by language.")
            }
            Section("Reading a Song") {
                Text("Open any song to read its lyrics. Use the toolbar to show chords, adjust text size, play the audio recording, or view sheet music when available.")
            }
            Section("Favourites & Setlists") {
                Text("Tap the heart icon on a song to favourite it. Build a setlist from the Setlists tab, add songs to it, then share it with your team.")
            }
            Section("Live Sessions") {
                Text("Open the Live tab to follow along in real time. Tap \"Join with a Code…\" and enter the code from the venue screen or your worship leader — no sign-in needed. If you're signed in, tap \"Go Live\" to broadcast the songs you open, then share the code shown with your group.")
            }
            Section("Offline Songs") {
                Text("Tap the download icon on a song to save it for offline reading. Manage everything you've saved from Settings → Storage & Offline.")
            }
            Section("Accessibility") {
                Text("Settings → Appearance lets you pick a colour-vision mode; Settings → Accessibility offers a dyslexia-friendly reading style with extra letter and line spacing.")
            }
            helpOnTheWebSection
        }
        .navigationTitle("Help")
    }

    /// A link out to the fuller web help guide — see this file's header for
    /// why `/help` (not an invented `/support` URL) is the right target.
    private var helpOnTheWebSection: some View {
        Section {
            if let url = CanonicalURL.help {
                Link(destination: url) {
                    Label("More Help on the Web", systemImage: "safari")
                }
            }
        } footer: {
            Text("The website's help guide covers every feature across all of iHymns' apps, including topics not yet in this Apple app.")
        }
    }
}
