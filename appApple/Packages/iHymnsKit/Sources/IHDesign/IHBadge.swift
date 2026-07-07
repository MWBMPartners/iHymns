// IHBadge.swift
// IHDesign
//
// ELI5: A small, tinted "pill" label — like the little coloured stickers
// you see next to a book's name saying "New" or "Unofficial." Any screen
// that needs one of these reuses THIS shape instead of drawing its own.
//
// DETAILED: The shared badge wrapper strategy §1.4 names alongside
// `IHGlassCard`/`IHLyricCanvas` ("IHDesign owns tokens + wrappers
// (IHGlassCard, IHLyricCanvas, IHBadge incl. the ONE shared
// unofficial-songbook badge, mirroring web rule #24)"). `IHUnofficialBadge`
// below is that ONE shared unofficial-songbook badge (#1437, Apple P1
// Songbooks browse) — the native mirror of the web's
// `.songbook-unofficial-badge` CSS class (`.claude/CLAUDE.md` rule #24):
// official + unofficial songbooks are presented TOGETHER as one family,
// distinguished only by this badge, never by filtering unofficial books
// out of a list. `IHBadge` itself is deliberately generic (label + tint),
// not hard-coded to "Unofficial," so a later badge kind (e.g. a future
// "New" or "Beta" pill) reuses the same visual primitive rather than a
// second hand-rolled `Text().padding().background(Capsule())` — the repo's
// modularity rule applied to this one small shape.
import SwiftUI

/// A small, tinted capsule-shaped status label.
///
/// ELI5: `IHBadge("New", tint: .blue)` draws a little blue "New" pill.
public struct IHBadge: View {
    private let text: String
    private let tint: Color

    /// - Parameters:
    ///   - text: The badge's label, e.g. `"Unofficial"`.
    ///   - tint: The badge's colour — BOTH its foreground text and its
    ///     translucent background derive from this ONE colour, so adding a
    ///     new badge kind never requires choosing two separate colours that
    ///     could drift apart from each other.
    public init(_ text: String, tint: Color) {
        self.text = text
        self.tint = tint
    }

    public var body: some View {
        Text(text)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .foregroundStyle(tint)
            .background(tint.opacity(0.16), in: .capsule)
            .overlay {
                Capsule().strokeBorder(tint.opacity(0.4), lineWidth: 0.75)
            }
    }
}

/// The ONE shared "Unofficial" badge for a curator-made songbook grouping
/// that isn't an official denominational hymnal (`Songbook.isOfficial ==
/// false`) — the native mirror of the web's `.songbook-unofficial-badge`
/// (`.claude/CLAUDE.md` rule #24).
///
/// ELI5: The little orange "Unofficial" pill next to a songbook's name.
///
/// DETAILED: Every screen that lists songbooks (`SongbookTileView` today; a
/// future home/favourites tile reusing the same `Songbook` model tomorrow)
/// reuses THIS type rather than each re-deriving its own "Unofficial"
/// pill — re-forking it would be exactly the regression rule #24's red-flag
/// list calls out ("a hand-rolled 'unofficial' label instead of the shared
/// badge class"). Callers are expected to fold this into the surrounding
/// tile's combined accessibility label (e.g. via
/// `.accessibilityElement(children: .combine)`, matching the web's own
/// #680/#856 badge-in-accessible-name pattern) rather than presenting it as
/// a separate, unlabelled VoiceOver stop.
public struct IHUnofficialBadge: View {
    public init() {}

    public var body: some View {
        IHBadge("Unofficial", tint: .orange)
    }
}

#Preview {
    VStack(spacing: 12) {
        IHUnofficialBadge()
        IHBadge("New", tint: .blue)
    }
    .padding()
}
