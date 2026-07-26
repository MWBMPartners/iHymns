// ServiceMirrorCard.swift
// IHFeatures
//
// ELI5: The little card on the remote-control screen that lets someone say
// "also send whatever's on this TV to the people following our live
// Service session online." Off by default; type in the session number the
// web console is showing, tap Start, and it quietly keeps that web session
// in sync with the TV until you tap Stop.
//
// DETAILED: Apple Phase-2 PR-14 (#1425, `.claude/apple-native-strategy.md`
// §2.4.1's "optional Service-Mode mirror"). Purely a thin render of
// `ServiceMirrorController.phase`/`.notice` — every tap here calls INTO the
// controller (`arm(sessionId:currentState:)`/`disarm()`), never mutates
// local UI state that could drift from it, mirroring
// `RemoteControlSurfaceView`'s own "stateless/reflective" convention (that
// file's header, spec §6.3 D-7) even though this card's own state machine
// (`ServiceMirrorController.Phase`) is local to the phone, not TV-echoed.
import IHLive
import IHDesign
import SwiftUI

/// See this file's header — the "mirror this TV to a Service session" card.
struct ServiceMirrorCard: View {
    let controller: ServiceMirrorController
    let currentState: IHRPState

    /// Pre-filled from `IHSettingsStore().lastMirrorSessionId` (#1425) so a
    /// venue that reuses the same projection-laptop session across a
    /// service doesn't need retyping every time.
    @State private var sessionIdText = IHSettingsStore().lastMirrorSessionId.map(String.init) ?? ""

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Congregant Mirror").font(.subheadline).foregroundStyle(.secondary)
            switch controller.phase {
            case .off:
                offContent
            case .active(let sessionId):
                armedRow(sessionId: sessionId, isDegraded: false)
            case .degraded(let sessionId):
                armedRow(sessionId: sessionId, isDegraded: true)
            }
            if let notice = controller.notice {
                Text(notice).font(.caption).foregroundStyle(.secondary)
            }
        }
        .ihGlassCard()
        // #1562 A-3: the ONLY signal a VoiceOver user gets that mirroring
        // just changed state — the status dot is `.accessibilityHidden`
        // (A-1 below) and everything else here just silently re-renders.
        // ELI5: "Say out loud what just changed, since nobody's looking at
        // the screen while running a service." Both `phase` and `notice`
        // are separately observed because a phase change doesn't always
        // carry a fresh notice (e.g. `.degraded` -> `.active` on recovery
        // clears `notice` to `nil`) and a notice can occasionally repeat
        // across the SAME phase (e.g. two consecutive F-3 skips).
        .onChange(of: controller.phase) { _, newPhase in
            AccessibilityNotification.Announcement(Self.phaseAnnouncement(for: newPhase)).post()
        }
        .onChange(of: controller.notice) { _, newNotice in
            guard let newNotice else { return }
            AccessibilityNotification.Announcement(newNotice).post()
        }
    }

    /// #1562 A-3: one short, phase-specific line per `ServiceMirrorController
    /// .Phase` transition — see this file's `body` `.onChange` above.
    private static func phaseAnnouncement(for phase: ServiceMirrorController.Phase) -> String {
        switch phase {
        case .off: return "Mirroring stopped."
        case .active: return "Mirroring active."
        case .degraded: return "Mirroring reconnecting."
        }
    }

    // MARK: - .off

    private var offContent: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Also running a Service session for remote congregants? Enter its session number from the web console to mirror this TV to them.")
                .font(.caption)
                .foregroundStyle(.secondary)
            HStack {
                TextField("Session #", text: $sessionIdText)
                    #if os(iOS) || os(visionOS)
                    .keyboardType(.numberPad)
                    #endif
                    #if !os(watchOS) && !os(tvOS)
                    // `.roundedBorder` is unavailable on BOTH watchOS and tvOS,
                    // but IHFeatures must still COMPILE for every platform
                    // (#1549) even though this operator card is only ever SHOWN
                    // on the phone/iPad/Mac LAN-remote screen. Both of those
                    // platforms fall back to the plain, unstyled field.
                    //
                    // The tvOS half of this guard was missing until PR #1578:
                    // PR-14 (#1425) was verified with iOS + watchOS package
                    // cross-compiles only, so nothing ever compiled this file
                    // for tvOS and `error: 'roundedBorder' is unavailable in
                    // tvOS` did not surface until the consolidated branch hit
                    // apple.yml's tvOS app-scheme build.
                    // https://developer.apple.com/documentation/swiftui/roundedbordertextfieldstyle
                    .textFieldStyle(.roundedBorder)
                    #endif
                    // #1562 A-7: NO `maxWidth` cap — a hard 120pt cap was
                    // fine at the default text size but clips the digits at
                    // larger Dynamic Type accessibility sizes; `minWidth`
                    // alone still keeps the field from collapsing to
                    // nothing next to "Start Mirroring" at the default size.
                    .frame(minWidth: 60)
                    // #1562 A-4: bare placeholder text ("Session #") is all
                    // VoiceOver would otherwise read for this field — name
                    // what it actually is.
                    .accessibilityLabel("Service session number")
                Button("Start Mirroring") {
                    guard let sessionId = parsedSessionId else { return }
                    IHSettingsStore().lastMirrorSessionId = sessionId
                    controller.arm(sessionId: sessionId, currentState: currentState)
                }
                .buttonStyle(.borderedProminent)
                .disabled(parsedSessionId == nil)
            }
        }
    }

    private var parsedSessionId: Int? {
        guard let value = Int(sessionIdText), value > 0 else { return nil }
        return value
    }

    // MARK: - .active / .degraded

    private func armedRow(sessionId: Int, isDegraded: Bool) -> some View {
        HStack {
            // #1562 A-1: `.combine` scoped to ONLY the status dot + label —
            // combining the whole row (as before) swallowed the "Stop
            // Mirroring" `Button` into the SAME element, losing its button
            // trait/`.destructive` role and making it untappable by
            // VoiceOver. The Button stays a SIBLING below, outside this
            // nested group.
            HStack {
                Circle()
                    .fill(isDegraded ? Color.orange : Color.green)
                    .frame(width: 10, height: 10)
                    .accessibilityHidden(true)
                Text(isDegraded ? "Reconnecting to iHymns — your projection is unaffected." : "Mirroring to session #\(sessionId)")
                    .font(.subheadline)
                    // #1562 A-3: this label is the live "what's happening
                    // right now" status text — flags it as continuously
                    // updating for assistive tech that debounces re-reads.
                    .accessibilityAddTraits(.updatesFrequently)
            }
            .accessibilityElement(children: .combine)
            Spacer(minLength: 12)
            Button("Stop Mirroring", role: .destructive) {
                controller.disarm()
            }
        }
    }
}
