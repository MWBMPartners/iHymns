// IHGlassCard.swift
// IHDesign
//
// ELI5: A reusable "glass panel" background — the frosted, Apple Liquid
// Glass look — that any screen can wrap its content in with one modifier,
// instead of every screen inventing its own glass styling.
//
// DETAILED: The `IHGlassCard` wrapper strategy §1.4 names as one of
// IHDesign's shared glass wrappers ("IHDesign owns tokens + wrappers
// (IHGlassCard, IHLyricCanvas, IHBadge..."), backed by the REAL
// `glassEffect(_:in:)` API (verified compiling against the OS 26 SDK for
// this build — see this task's verification notes) rather than a faked
// substitute. Strategy §1.4 is explicit: **"Never fake glass with
// `.ultraThinMaterial`."** Even though this package's minimum deployment
// target is OS 26 everywhere (so `glassEffect` is technically always
// available at the declared platform floor), this wrapper still guards
// with `if #available` as defensive practice — matching the task brief's
// instruction to guard Liquid-Glass usage for availability — and falls back
// to a plain, honest solid background (NOT a faked translucency effect) if
// that guard is ever false on some future build configuration.
//
// See Apple's Liquid Glass documentation:
// https://developer.apple.com/documentation/swiftui/applying-liquid-glass-to-custom-views.
import SwiftUI

/// A `ViewModifier` that wraps its content in the shared iHymns "glass
/// card" treatment.
///
/// ELI5: Put this on any view to give it the frosted-glass card look.
public struct IHGlassCard: ViewModifier {
    /// The corner radius of the glass shape — a single tunable so every
    /// card in the app shares one rounding, rather than each screen picking
    /// its own.
    private let cornerRadius: CGFloat

    public init(cornerRadius: CGFloat = 20) {
        self.cornerRadius = cornerRadius
    }

    public func body(content: Content) -> some View {
        if #available(iOS 26, macOS 26, tvOS 26, watchOS 26, visionOS 26, *) {
            content
                .padding()
                .glassEffect(.regular, in: .rect(cornerRadius: cornerRadius))
        } else {
            // Defensive fallback only — this package's declared platform
            // floor is already OS 26 everywhere, so this branch should be
            // unreachable in practice. It intentionally does NOT fake glass
            // with `.ultraThinMaterial` (strategy §1.4's explicit ban);
            // a plain, honest background is the correct degrade instead.
            content
                .padding()
                .background(Color.primary.opacity(0.06), in: .rect(cornerRadius: cornerRadius))
        }
    }
}

public extension View {
    /// Wraps this view in the shared iHymns glass-card treatment.
    ///
    /// ELI5: `.ihGlassCard()` — call this instead of writing your own
    /// glass/background styling.
    func ihGlassCard(cornerRadius: CGFloat = 20) -> some View {
        modifier(IHGlassCard(cornerRadius: cornerRadius))
    }
}
