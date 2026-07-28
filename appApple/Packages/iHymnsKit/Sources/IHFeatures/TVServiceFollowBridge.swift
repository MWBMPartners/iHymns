// TVServiceFollowBridge.swift
// IHFeatures
//
// ELI5: The tiny translator between "what the server's poll just said the
// venue should be showing" and "what the projection screen should actually
// do about it" — exactly the same shape `TVProjectionBridge` (PR-6) already
// established for the LAN remote, applied to the THIRD driver of
// `ProjectionViewModel` that file's own header predicted: "a server-poll
// loop in PR-15."
//
// DETAILED: Apple Phase-2 PR-15 (#1428; `.claude/apple-native-strategy.md`
// §2.4.7 — "(b) tvOS server-following projector... = server-mediated").
// PURE (no networking, no engine, no `ServiceModeEngine` import) — a
// `LiveBroadcastSnapshot` in, an optional `IHRPMessage`-shaped intent out,
// unit-testable with zero real sockets or HTTP (`TVServiceFollowBridgeTests.swift`).
//
// Content-gating-safe (CLAUDE.md rule #28 / this PR's own binding rule):
// `LiveBroadcastSnapshot` carries navigation intent ONLY (`songId`/
// `componentIndex`/a `state.lineIndex`) — never lyric text — so this bridge
// never sees anything content-gating would need to redact; the TV's OWN
// `song_detail` fetch (`ProjectionViewModel.selectSong`'s injected
// `fetchSongDetail`, ultimately `AppRootViewModel.songDetail(id:)`) is what
// applies gating, under the TV's own auth, exactly like `TVProjectionBridge`
// already does for the LAN remote.
//
// D-13 songId leniency (`IHModels/LiveSync.swift`'s header): the SAME ~10
// legacy catalogue ids that don't parse as `SongID(rawValue:)` for the
// phone/web congregant clients (`LiveFollowingView.load()`'s identical
// guard) can arrive here too — `plan(for:current:)` reports that as
// `unparsableSongId` rather than crashing or silently dropping the follow,
// and NEVER attempts a selection for it.
import IHLive
import IHModels

/// Maps ONE server poll snapshot onto the `ProjectionViewModel` mutations it
/// implies — the server-following analogue of `TVProjectionBridge`'s
/// LAN-remote mapping.
///
/// ELI5: "The service just said X is showing now — go make the projection
/// screen do X, and tell me if there's anything the operator should know
/// about (an unrecognised song, a fetch that got denied)."
enum TVServiceFollowBridge {

    /// A resolved, ready-to-apply `selectSong` call — a sibling of
    /// `FollowPlan` (not nested INSIDE it) purely to keep this file's
    /// nesting depth at SwiftLint's default 1-level max (`nesting`); it is
    /// otherwise conceptually "part of" `FollowPlan`, the same way
    /// `ProjectionViewModel.ProjectionState` is conceptually part of
    /// `ProjectionViewModel` despite that ONE level of nesting being fine.
    struct Selection: Equatable {
        let songId: SongID
        let componentIndex: Int?
        let lineIndex: Int?
    }

    /// What ONE snapshot implies should change — `nil` fields mean "no
    /// change," so a caller can tell "the service is idle/unchanged" apart
    /// from "the service genuinely wants nothing shown" without a third
    /// sentinel case.
    struct FollowPlan: Equatable {
        /// `nil` = leave `viewModel.displayState` exactly as it is.
        let displayState: IHRPDisplayState?
        /// `nil` = no (re)position — either nothing to reposition to, or
        /// the snapshot's target is IDENTICAL to where the projector
        /// already is (the dedupe rule below).
        let selection: Selection?
        /// D-13: the raw `songId` the snapshot carried when it did NOT
        /// parse as a `SongID` — the coordinator (`TVServiceFollowCoordinator`)
        /// turns this into the calm "can't be displayed" notice
        /// `LiveFollowingView.load()` already shows on the phone/web
        /// congregant clients, rather than a crash or a silent no-op.
        let unparsableSongId: String?
    }

    /// The server's `live`/`blackout`/`logo` display intent
    /// (`ServiceDisplayState`, `IHModels/LiveSync.swift`), mapped onto the
    /// LAN-remote/projection vocabulary (`IHRPDisplayState`,
    /// `IHLive/LANRemote/IHRPPayloads.swift`) — the ONE place these two
    /// DELIBERATELY DISTINCT vocabularies (that file's own header: "never
    /// conflate the two") cross the boundary.
    ///
    /// `nil` state (the snapshot carried no `state` payload at all) maps the
    /// same way a `LiveBroadcastState` with every field absent already would
    /// (`resolvedDisplayState`'s own "nothing at all → `.live`" fallback) —
    /// `.lyrics` is the honest "showing content normally" default either way.
    ///
    /// NEVER returns `.frozen` — that's a LAN-remote-only concept
    /// (`IHRPDisplayState`'s own doc comment: "no web/Service-Mode
    /// equivalent at all"); a server broadcaster cannot ask for it, and this
    /// bridge must not invent one on its behalf.
    static func displayState(from state: LiveBroadcastState?) -> IHRPDisplayState {
        guard let state else { return .lyrics }
        switch state.resolvedDisplayState {
        case .live: return .lyrics
        case .blackout: return .blackout
        case .logo: return .logo
        }
    }

    /// Resolves `snapshot` against the projector's CURRENT canonical state —
    /// pure, no side effects, so `TVServiceFollowCoordinator`'s events loop
    /// and every test can reason about "what would happen" without actually
    /// touching a `ProjectionViewModel`.
    ///
    /// ELI5: "Compare what the service says now to what's already on
    /// screen, and work out the smallest set of changes that would make
    /// them match."
    static func plan(for snapshot: LiveBroadcastSnapshot, current: ProjectionViewModel.ProjectionState) -> FollowPlan {
        let target = displayState(from: snapshot.state)
        let displayStateChange: IHRPDisplayState? = target == current.displayState ? nil : target

        guard let rawSongId = snapshot.songId else {
            return FollowPlan(displayState: displayStateChange, selection: nil, unparsableSongId: nil)
        }
        guard let songId = SongID(rawValue: rawSongId) else {
            return FollowPlan(displayState: displayStateChange, selection: nil, unparsableSongId: rawSongId)
        }

        let lineIndex = snapshot.state?.lineIndex
        let alreadyThere = current.songId == songId && current.componentIndex == snapshot.componentIndex && current.lineIndex == lineIndex
        let selection = alreadyThere ? nil : Selection(songId: songId, componentIndex: snapshot.componentIndex, lineIndex: lineIndex)
        return FollowPlan(displayState: displayStateChange, selection: selection, unparsableSongId: nil)
    }

    /// Applies `snapshot` to `viewModel` — `displayState` FIRST (so a
    /// blackout lands immediately rather than waiting behind a slow
    /// `selectSong` network fetch), THEN the selection (which does its own
    /// latest-wins + gated-fetch handling — see `ProjectionViewModel
    /// .selectSong(songId:componentIndex:lineIndex:)`'s own doc comment).
    /// Deliberately does NOT pre-clamp `componentIndex`/`lineIndex` (that's
    /// `ProjectionResolver.clamped`'s job, already wired inside
    /// `selectSong`) and does NOT apply `scrollPct`/`transposeOffset` — the
    /// SAME "song + section is all that's applied" web-parity rule
    /// `LiveFollowingView.swift`'s header documents for the phone congregant
    /// client (D-4), extended to the projector.
    ///
    /// - Returns: The `SongResult` from a `selectSong` call this made, or
    ///   `nil` if `snapshot` implied no (re)selection at all. A server
    ///   `displayState` re-asserts over a local `.frozen` on the projector's
    ///   NEXT snapshot — there is no operator freeze control on this
    ///   surface yet, so this is an accepted, documented behaviour rather
    ///   than a bug.
    @MainActor
    static func apply(_ snapshot: LiveBroadcastSnapshot, to viewModel: ProjectionViewModel) async -> ProjectionViewModel.SongResult? {
        let plan = plan(for: snapshot, current: viewModel.projectionState)
        if let displayState = plan.displayState {
            viewModel.setDisplayState(displayState)
        }
        guard let selection = plan.selection else { return nil }
        return await viewModel.selectSong(songId: selection.songId, componentIndex: selection.componentIndex, lineIndex: selection.lineIndex)
    }
}
