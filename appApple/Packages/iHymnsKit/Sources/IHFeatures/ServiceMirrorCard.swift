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
                    .textFieldStyle(.roundedBorder)
                    .frame(maxWidth: 120)
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
            Circle()
                .fill(isDegraded ? Color.orange : Color.green)
                .frame(width: 10, height: 10)
                .accessibilityHidden(true)
            Text(isDegraded ? "Reconnecting to iHymns — your projection is unaffected." : "Mirroring to session #\(sessionId)")
                .font(.subheadline)
            Spacer(minLength: 12)
            Button("Stop Mirroring", role: .destructive) {
                controller.disarm()
            }
        }
        .accessibilityElement(children: .combine)
    }
}
