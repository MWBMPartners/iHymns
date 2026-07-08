// OnboardingView.swift
// IHFeatures
//
// ELI5: The "welcome to iHymns" screen that shows itself exactly once,
// the very first time the app is opened — four quick glass panels
// introducing Browse, Search, Offline, and Setlists, with a "Skip" you can
// tap at any time to jump straight into the app.
//
// DETAILED: #190 (Apple Phase 1, "First-run onboarding"). `RootContainerView`
// presents this as a `.fullScreenCover`, gated on `IHSettingsStore
// .hasSeenOnboarding` being `false` — see that view's own header for the
// exact wiring. `onFinish` fires on EITHER "Skip" or the final page's "Get
// Started," both of which count as "seen" per this task's own brief
// ("shown once... skippable" — skipping must not re-show it next launch).
//
// WHY A HAND-ROLLED PAGER, NOT `TabView(.page)`/`PageTabViewStyle`:
// `IHFeatures` compiles for every platform `Package.swift` targets,
// including macOS (this app shell's OWN target) — Apple's own docs list
// `.page` tab-view style support for iOS/iPadOS/tvOS but NOT macOS, and
// betting the shared package's macOS build on undocumented OS-26 behaviour
// isn't worth it for a "few pages, don't over-build" intro. A plain
// `@State` page index + `Button`-driven Next/Back, with the panel wrapped
// in `.ihGlassCard()` for the Liquid Glass look this task asks for, is
// trivially cross-platform and exactly matches the brief's own "a small
// paged intro, not a tutorial engine" scope.
import IHAppSupport
import IHDesign
import SwiftUI

/// The first-launch onboarding flow — see this file's header for the full
/// "why hand-rolled paging" reasoning.
public struct OnboardingView: View {
    /// Which of `OnboardingPage.all` is currently showing.
    @State private var pageIndex = 0

    /// Called once, exactly when the user dismisses the flow (via "Skip" or
    /// the final page's "Get Started") — `RootContainerView` uses this to
    /// both persist `IHSettingsStore.hasSeenOnboarding = true` and dismiss
    /// the cover.
    private let onFinish: () -> Void

    public init(onFinish: @escaping () -> Void) {
        self.onFinish = onFinish
    }

    private var isLastPage: Bool { pageIndex == OnboardingPage.all.count - 1 }

    public var body: some View {
        VStack(spacing: 32) {
            Spacer()
            pagePanel
            pageIndicator
            Spacer()
            controls
        }
        .padding(32)
        .frame(maxWidth: 480)
        .animation(.easeInOut(duration: 0.25), value: pageIndex)
    }

    /// The current page's icon/title/message, wrapped in the shared glass
    /// treatment (`IHGlassCard`, strategy §1.4 — never a faked
    /// `.ultraThinMaterial` substitute).
    private var pagePanel: some View {
        let page = OnboardingPage.all[pageIndex]
        return VStack(spacing: 16) {
            Image(systemName: page.systemImage)
                .font(.system(size: 56))
                .foregroundStyle(IHColorTokens.accent)
                .accessibilityHidden(true)
            Text(page.title)
                .font(.title2.bold())
                .multilineTextAlignment(.center)
            Text(page.message)
                .font(.body)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
        .padding(32)
        .ihGlassCard()
        // One accessibility element per page, matching how a real "slide"
        // should read to VoiceOver — the icon is decorative (hidden above),
        // title + message are what actually needs announcing.
        .accessibilityElement(children: .combine)
    }

    /// A row of small dots — filled for the current page, dim otherwise.
    /// Purely decorative (VoiceOver users get the real page count from
    /// `controls`' button labels), so it's hidden from the accessibility
    /// tree rather than read aloud as four ambiguous "circle" elements.
    private var pageIndicator: some View {
        HStack(spacing: 8) {
            ForEach(OnboardingPage.all.indices, id: \.self) { index in
                Circle()
                    .fill(index == pageIndex ? IHColorTokens.accent : Color.secondary.opacity(0.3))
                    .frame(width: 8, height: 8)
            }
        }
        .accessibilityHidden(true)
    }

    /// "Skip" (hidden on the final page — "Get Started" already finishes
    /// the flow there) + "Next"/"Get Started".
    private var controls: some View {
        HStack {
            if !isLastPage {
                Button("Skip", action: onFinish)
                    .buttonStyle(.plain)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Button(isLastPage ? "Get Started" : "Next") {
                if isLastPage {
                    onFinish()
                } else {
                    pageIndex += 1
                }
            }
            .buttonStyle(.borderedProminent)
        }
    }
}
