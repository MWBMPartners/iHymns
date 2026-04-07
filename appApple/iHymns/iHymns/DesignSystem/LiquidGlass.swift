// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  LiquidGlass.swift
//  iHymns
//
//  Apple's Liquid Glass design language implementation for iHymns.
//  Provides translucent, depth-aware, and vibrant glass-effect components
//  that adapt to platform capabilities and accessibility settings.
//
//  Liquid Glass creates a sense of depth and layering through:
//  - Ultra-thin material backgrounds with variable blur
//  - Subtle inner shadow and specular highlight edges
//  - Dynamic tinting that responds to content behind the glass
//  - Smooth spring-based animations for state transitions
//

import SwiftUI

// MARK: - LiquidGlassStyle

/// Defines the visual intensity of Liquid Glass effects.
/// Each style adjusts opacity, blur radius, and shadow depth
/// to create distinct visual hierarchy levels.
enum LiquidGlassStyle {
    /// Subtle background glass — minimal blur, very transparent.
    /// Used for large background areas and full-screen overlays.
    case thin

    /// Standard glass effect — balanced blur and opacity.
    /// Used for cards, panels, and navigation elements.
    case regular

    /// Prominent glass — stronger blur with more opacity.
    /// Used for modals, popovers, and floating action elements.
    case thick

    /// The material intensity corresponding to this glass style.
    var material: Material {
        switch self {
        case .thin:    return .ultraThinMaterial
        case .regular: return .regularMaterial
        case .thick:   return .thickMaterial
        }
    }

    /// Shadow radius for depth perception at this glass level.
    var shadowRadius: CGFloat {
        switch self {
        case .thin:    return 2
        case .regular: return 8
        case .thick:   return 16
        }
    }

    /// Shadow opacity for depth perception.
    var shadowOpacity: Double {
        switch self {
        case .thin:    return 0.08
        case .regular: return 0.12
        case .thick:   return 0.18
        }
    }

    /// Corner radius for glass containers at this level.
    var cornerRadius: CGFloat {
        switch self {
        case .thin:    return 12
        case .regular: return 16
        case .thick:   return 20
        }
    }
}

// MARK: - Liquid Glass View Modifier

/// Applies the Liquid Glass effect to any SwiftUI view.
/// The modifier layers a translucent material background with
/// configurable tinting, shadows, and border highlights to
/// create the characteristic glass appearance.
struct LiquidGlassModifier: ViewModifier {

    /// The visual intensity of the glass effect.
    let style: LiquidGlassStyle

    /// Optional tint colour overlaid on the glass surface.
    /// When nil, the glass takes on the natural colours of
    /// the content beneath it.
    let tint: Color?

    /// Whether to show a subtle specular highlight along the
    /// top edge of the glass, simulating light reflection.
    let showHighlight: Bool

    /// Whether the user has requested reduced transparency.
    @Environment(\.accessibilityReduceTransparency) private var reduceTransparency

    /// Whether the user has requested reduced motion.
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func body(content: Content) -> some View {
        if reduceTransparency {
            // Fallback: solid background when transparency is reduced
            // for accessibility. We still maintain the rounded shape
            // and shadow for visual consistency.
            content
                .background(
                    RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous)
                        .fill(.background)
                )
                .clipShape(RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous))
        } else {
            content
                .background(
                    ZStack {
                        // Base glass material layer
                        RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous)
                            .fill(style.material)

                        // Optional colour tint overlay
                        if let tintColor = tint {
                            RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous)
                                .fill(tintColor.opacity(0.08))
                        }

                        // Specular highlight along the top edge
                        if showHighlight {
                            RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous)
                                .strokeBorder(
                                    LinearGradient(
                                        colors: [
                                            .white.opacity(0.4),
                                            .white.opacity(0.1),
                                            .clear
                                        ],
                                        startPoint: .top,
                                        endPoint: .bottom
                                    ),
                                    lineWidth: 0.5
                                )
                        }
                    }
                )
                .clipShape(RoundedRectangle(cornerRadius: style.cornerRadius, style: .continuous))
                .shadow(
                    color: .black.opacity(style.shadowOpacity),
                    radius: style.shadowRadius,
                    x: 0,
                    y: style.shadowRadius / 3
                )
        }
    }
}

// MARK: - View Extension

extension View {

    /// Applies Apple's Liquid Glass visual effect to this view.
    ///
    /// - Parameters:
    ///   - style: The intensity of the glass effect (default: `.regular`).
    ///   - tint: An optional colour to tint the glass surface.
    ///   - showHighlight: Whether to show a top-edge specular highlight (default: `true`).
    /// - Returns: A view wrapped in a Liquid Glass container.
    func liquidGlass(
        _ style: LiquidGlassStyle = .regular,
        tint: Color? = nil,
        showHighlight: Bool = true
    ) -> some View {
        modifier(LiquidGlassModifier(
            style: style,
            tint: tint,
            showHighlight: showHighlight
        ))
    }
}

// MARK: - LiquidGlassCard

/// A pre-built card component using the Liquid Glass design language.
/// Wraps content in a glass container with consistent padding and
/// optional amber tinting to match the iHymns brand.
struct LiquidGlassCard<Content: View>: View {

    let style: LiquidGlassStyle
    let tint: Color?
    @ViewBuilder let content: () -> Content

    init(
        style: LiquidGlassStyle = .regular,
        tint: Color? = nil,
        @ViewBuilder content: @escaping () -> Content
    ) {
        self.style = style
        self.tint = tint
        self.content = content
    }

    var body: some View {
        content()
            .padding()
            .liquidGlass(style, tint: tint)
    }
}

// MARK: - LiquidGlassButton

/// A button styled with the Liquid Glass effect.
/// Provides visual feedback through scale and opacity animations
/// on press, maintaining the glassy appearance throughout.
struct LiquidGlassButton: View {

    let title: String
    let systemImage: String?
    let tint: Color
    let action: () -> Void

    @State private var isPressed = false

    init(
        _ title: String,
        systemImage: String? = nil,
        tint: Color = AmberTheme.accent,
        action: @escaping () -> Void
    ) {
        self.title = title
        self.systemImage = systemImage
        self.tint = tint
        self.action = action
    }

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                if let image = systemImage {
                    Image(systemName: image)
                        .font(.body.weight(.semibold))
                }
                Text(title)
                    .font(.body.weight(.semibold))
            }
            .foregroundStyle(tint)
            .padding(.horizontal, 20)
            .padding(.vertical, 12)
            .liquidGlass(.regular, tint: tint)
        }
        .buttonStyle(.plain)
        .scaleEffect(isPressed ? 0.96 : 1.0)
        .animation(.spring(response: 0.3, dampingFraction: 0.7), value: isPressed)
        .simultaneousGesture(
            DragGesture(minimumDistance: 0)
                .onChanged { _ in isPressed = true }
                .onEnded { _ in isPressed = false }
        )
    }
}

// MARK: - LiquidGlassNavigationBar

/// A navigation bar replacement using Liquid Glass styling.
/// Provides a translucent header area that blurs the content
/// scrolling beneath it, matching the Liquid Glass design language.
struct LiquidGlassNavigationBar<Content: View>: View {

    let title: String
    @ViewBuilder let trailing: () -> Content

    init(title: String, @ViewBuilder trailing: @escaping () -> Content = { EmptyView() }) {
        self.title = title
        self.trailing = trailing
    }

    var body: some View {
        HStack {
            Text(title)
                .font(.largeTitle.weight(.bold))

            Spacer()

            trailing()
        }
        .padding(.horizontal)
        .padding(.vertical, 12)
        .liquidGlass(.thin, tint: AmberTheme.accent)
    }
}

// MARK: - Liquid Glass Animations

/// Spring animation presets for Liquid Glass interactions.
extension Animation {

    /// Standard Liquid Glass spring — used for most interactive transitions.
    static let liquidGlassSpring = Animation.spring(
        response: 0.4,
        dampingFraction: 0.75,
        blendDuration: 0
    )

    /// Quick Liquid Glass spring — used for button presses and toggles.
    static let liquidGlassQuick = Animation.spring(
        response: 0.25,
        dampingFraction: 0.8,
        blendDuration: 0
    )

    /// Gentle Liquid Glass spring — used for modal presentations and large transitions.
    static let liquidGlassGentle = Animation.spring(
        response: 0.5,
        dampingFraction: 0.7,
        blendDuration: 0
    )
}
