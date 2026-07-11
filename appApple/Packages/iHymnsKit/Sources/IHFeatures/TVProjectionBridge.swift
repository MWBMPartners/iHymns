// TVProjectionBridge.swift
// IHFeatures
//
// ELI5: The tiny translator between "what a remote just asked for" and
// "what the projection screen should actually do about it" — one small
// function, no networking, no listener, so it's trivial to test on its own.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.2 — made real from
// PR-5's own spec §1 sketch, `.claude/apple-phase2-pr5-spec.md`). This is
// the PURE half of the LAN-remote ↔ projection seam; `TVRemoteControlCoordinator`
// (this same target) is the ONLY place that touches BOTH `TVListenerActor`
// (the LAN transport, `IHLive`) and `ProjectionViewModel` (the display
// engine, this same file's sibling) — everything ELSE about "what does
// `nextComponent` mean" lives here, unit-testable with a fake
// `fetchSongDetail` closure and zero real sockets (`TVProjectionBridgeTests.swift`).
//
// **`default: return nil`** covers `hello`/`ping`/`endControl`/every
// pairing-sub-protocol case (`pairChallenge`/`pairConfirm`/`pairSuccess`) —
// all transport/pairing concerns `TVListenerActor` (`IHLive`) already
// resolved BEFORE a frame ever reaches `controlEvents`; this bridge only
// ever sees `ControlEvent`s built from ALREADY-PAIRED, ALREADY-legitimate
// control intents, but the exhaustive `switch` still enumerates every
// `IHRPMessage` case (no `@unknown default`, so the compiler forces this
// file to be updated the day a new control intent is added).
import IHLive

/// Maps ONE remote control intent onto the `ProjectionViewModel` it should
/// drive — the exact "make it real" of PR-5 spec §1's ~30-line bridge
/// sketch.
///
/// ELI5: "The remote just said X — go make the projection screen do X, and
/// tell me if I need to reply with an error."
enum TVProjectionBridge {
    /// Applies `message` to `viewModel`, returning the `IHRPMessage.error`
    /// reply the listener should send back to the ORIGINATING connection —
    /// `nil` means "nothing to report back" (either the intent succeeded
    /// silently, or it wasn't a control intent this bridge owns at all).
    ///
    /// ELI5: "Do the thing the remote asked for; here's what to tell it, if
    /// anything went wrong."
    ///
    /// `@MainActor` — `ProjectionViewModel` is itself `@MainActor` (its own
    /// header comment), so every synchronous mutation below
    /// (`nextComponent()`/`setDisplayState(_:)`/etc.) needs to run on that
    /// same actor; `TVRemoteControlCoordinator` (also `@MainActor`) calls
    /// this directly from its bridge loop with no extra hop.
    ///
    /// An exhaustive switch over every `IHRPMessage` case is high
    /// CYCLOMATIC complexity by the metric's definition, but each branch is
    /// an independent one-liner — the identical reasoning `IHRPFrame.swift`'s
    /// own `swiftlint:disable` comment gives for its Codable switch.
    @MainActor
    // swiftlint:disable:next cyclomatic_complexity
    static func apply(_ message: IHRPMessage, to viewModel: ProjectionViewModel) async -> IHRPMessage? {
        switch message {
        case .prepare(let songId):
            _ = await viewModel.prepare(songId: songId)
            return nil

        case .selectSong(let songId, let componentIndex, let lineIndex):
            switch await viewModel.selectSong(songId: songId, componentIndex: componentIndex, lineIndex: lineIndex) {
            case .shown:
                return nil
            case .unavailable:
                // The EXACT handoff strategy §2.4.3 names: a gated song's
                // fetch failure becomes `.contentUnavailable` on the wire,
                // never a leaked user-facing message string.
                return .error(.contentUnavailable, message: nil)
            case .failed:
                // Any other failure (offline, decode, superseded) — never
                // echo the underlying message back over the LAN link.
                return .error(.internalError, message: nil)
            }

        case .nextComponent:
            viewModel.nextComponent()
            return nil
        case .prevComponent:
            viewModel.prevComponent()
            return nil
        case .nextLine:
            viewModel.nextLine()
            return nil
        case .prevLine:
            viewModel.prevLine()
            return nil
        case .jumpLine(let index):
            viewModel.jumpLine(index: index)
            return nil
        case .setDisplayState(let displayState):
            viewModel.setDisplayState(displayState)
            return nil
        case .scroll(let delta):
            viewModel.scroll(delta: delta)
            return nil
        case .setAppearance(let theme, let textScale):
            viewModel.setAppearance(theme: theme, textScale: textScale)
            return nil

        case .hello, .endControl, .ping, .state, .ack, .error, .pong, .capabilities,
             .pairChallenge, .pairConfirm, .pairSuccess:
            // Transport/pairing concerns `TVListenerActor` (`IHLive`)
            // already resolved before this bridge ever sees a
            // `ControlEvent` — this file's header comment. Named
            // exhaustively (no `@unknown default`) so the compiler forces
            // this file to be revisited the day a new `IHRPMessage` case
            // is added, rather than silently falling through a catch-all.
            return nil
        }
    }
}
