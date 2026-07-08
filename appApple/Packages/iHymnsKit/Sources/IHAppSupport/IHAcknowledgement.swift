// IHAcknowledgement.swift
// IHAppSupport
//
// ELI5: The list behind the app's "Acknowledgements" screen — every
// third-party piece of code the Apple app actually ships, and which licence
// it comes under.
//
// DETAILED: #190 (Apple Phase 1 — help/legal/acknowledgements, its
// "Acknowledgements / open-source licenses screen" requirement). Kept as a
// pure `Sendable` data type + a plain static builder function — no SwiftUI
// import, no dependency beyond `Foundation` — so `IHAcknowledgementsTests`
// can assert on the exact list without touching a view at all (this task's
// own brief calls out "the acknowledgements list builder" by name as the
// kind of pure logic that needs test coverage). `AcknowledgementsView`
// (`IHFeatures`) is a thin renderer over `IHAcknowledgements.all()`, the
// same "logic here, rendering there" split this package uses throughout.
//
// WHY THESE TWO, AND ONLY THESE TWO: `Package.swift`'s `dependencies:`
// array names exactly one external package — GRDB.swift (pinned `7.11.1`,
// MIT, © Gwendal Roué — verified against the actual checked-out `LICENSE`
// file, not assumed). OpenDyslexic is NOT a Swift package dependency but IS
// a third-party asset the dyslexia-friendly reading mode (#1412) NAMES
// (`IHDesign/IHReadingMode.swift`'s `dyslexiaFontName`/
// `dyslexiaItalicFontName`) — its four OTFs + `OpenDyslexic-OFL.txt` now
// SHIP as an `IHDesign` package resource (`Package.swift`'s
// `.copy("Resources/Fonts")`) and are registered at launch
// (`IHDesign/IHFonts.swift`), so it's listed here as a shipped, bundled
// asset rather than the earlier "FONT-PENDING" placeholder status. Every
// OTHER piece of this app (SwiftUI, Observation, Foundation, GRDB's own
// further dependencies if any) is either an Apple system framework (no
// separate acknowledgement needed) or has no additional transitive
// third-party licence to surface.
import Foundation

/// One third-party component the app ships, for the Acknowledgements
/// screen.
///
/// ELI5: "This bit of the app came from someone else — here's their name
/// and their licence."
public struct IHAcknowledgement: Sendable, Equatable, Identifiable {
    public var id: String { name }

    /// The component's name, e.g. `"GRDB.swift"`.
    public let name: String

    /// The licence it ships under, e.g. `"MIT License"`.
    public let licenseName: String

    /// Short context — what it's used for, and (for OpenDyslexic) its
    /// bundling status. `nil` when the name + licence alone are
    /// self-explanatory.
    public let note: String?

    public init(name: String, licenseName: String, note: String? = nil) {
        self.name = name
        self.licenseName = licenseName
        self.note = note
    }
}

/// Builds the full, fixed list `AcknowledgementsView` renders.
public enum IHAcknowledgements {
    /// See this file's header for why exactly these two entries, and only
    /// these two, are listed today.
    public static func all() -> [IHAcknowledgement] {
        [
            IHAcknowledgement(
                name: "GRDB.swift",
                licenseName: "MIT License",
                note: "The offline cache engine — full-text search, on-disk migrations, and Swift 6 concurrency support for saved songs, setlists, and favourites."
            ),
            IHAcknowledgement(
                name: "OpenDyslexic",
                licenseName: "SIL Open Font License 1.1",
                note: "© 2019 Abbie Gonzalez, Reserved Font Name \"OpenDyslexic\" — bundled with the app and used by the dyslexia-friendly reading mode (Settings → Accessibility). Full licence text ships alongside the font files."
            )
        ]
    }
}
