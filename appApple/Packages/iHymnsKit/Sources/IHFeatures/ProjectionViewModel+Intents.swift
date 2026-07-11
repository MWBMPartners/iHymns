// ProjectionViewModel+Intents.swift
// IHFeatures
//
// ELI5: Every "move the highlight," "change what's showing," or "make the
// text bigger" command the projection screen understands — one small
// function per `IHRPMessage` control case that actually reaches the
// display layer (`hello`/`ping`/`endControl` are transport concerns
// `TVListenerActor` already handles and MUST NOT appear here).
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §4.1/§4.3). Split out
// of `ProjectionViewModel.swift` purely to keep that file under the repo's
// LOC-budget tripwire (`Scripts/loc-budget.sh`) — the identical
// same-type-extension split `TVListenerActor`/`TVListenerActor+Messages.swift`
// already establishes for the sibling PR-4 actor. Every property this file
// touches (`position`/`displayState`/`textScale`/`appearanceThemeHint`/
// `frozenSnapshot`/`songMap`/`continuation`) is declared `internal`(-set) or
// plain `internal` in `ProjectionViewModel.swift` specifically so THIS file
// can mutate it — Swift's `private`/`fileprivate` are FILE-scoped even for a
// type's own extensions, the same cross-file-visibility note
// `AppRootViewModel.recentSearches`/`TVListenerActor.connections` already
// document in this package.
import IHLive

extension ProjectionViewModel {

    /// "Show the next component" (verse/chorus/bridge) — Siri Remote right
    /// swipe (`ProjectionSceneView`'s `.onMoveCommand`), PR-6's
    /// `.nextComponent` control message.
    public func nextComponent() {
        move { ProjectionResolver.nextComponent(from: $0, map: $1) }
    }

    /// "Show the previous component" — Siri Remote left swipe.
    public func prevComponent() {
        move { ProjectionResolver.prevComponent(from: $0, map: $1) }
    }

    /// "Advance the karaoke line highlight by one" — Siri Remote down swipe.
    public func nextLine() {
        move { ProjectionResolver.nextLine(from: $0, map: $1) }
    }

    /// "Step the karaoke line highlight back by one" — Siri Remote up swipe.
    public func prevLine() {
        move { ProjectionResolver.prevLine(from: $0, map: $1) }
    }

    /// Jump straight to `index` within the CURRENT component
    /// (`IHRPMessage.jumpLine`'s doc: "within the current component").
    public func jumpLine(index: Int) {
        move { ProjectionResolver.jumpLine(to: index, from: $0, map: $1) }
    }

    /// A relative scroll nudge — e.g. a scroll-wheel tick relayed from a
    /// future trackpad-capable remote (PR-6/PR-7); `delta`'s sign/magnitude
    /// meaning is entirely `ProjectionResolver.scroll(delta:from:map:)`'s call.
    public func scroll(delta: Int) {
        move { ProjectionResolver.scroll(delta: delta, from: $0, map: $1) }
    }

    /// Shared engine for every relative navigation intent above: resolves a
    /// new position via `resolve`, and — per `ProjectionResolver`'s
    /// clamp-as-no-op contract — mutates + publishes ONLY when the position
    /// genuinely changed. This is what makes "pressed left at the very
    /// first verse" a true no-op all the way out to `stateUpdates`: a
    /// driver never sees a repeated/duplicate state for an intent that
    /// didn't actually move anything (spec §4.1: "a clamped no-op intent
    /// yields nothing, so PR-6 never rebroadcasts an unchanged state /
    /// never burns a revision").
    private func move(_ resolve: (ProjectionPosition, ProjectionSongMap) -> ProjectionPosition) {
        let newPosition = resolve(position, songMap)
        guard newPosition != position else { return }
        position = newPosition
        publish()
    }

    /// Change what the projection surface is drawing — `IHRPDisplayState`
    /// (§4.3's "the part a builder must not guess"):
    ///   - Setting the SAME value is a no-op — nothing published.
    ///   - Entering `.frozen` (from any other state): capture
    ///     `frozenSnapshot = (song, position)`. Navigation intents keep
    ///     working while frozen (`move(_:)` above still mutates `position`
    ///     and still publishes) — only `renderedContent`'s PICTURE stays
    ///     pinned to the snapshot; the canonical `projectionState` a driver
    ///     sees keeps reporting `displayState == .frozen`, which IS the
    ///     truthful signal ("the screen shows OLDER content than this")
    ///     per `IHRPDisplayState`'s own doc comment.
    ///   - Leaving `.frozen`: clear the snapshot — `renderedContent`
    ///     immediately reverts to the live `(song, position)`.
    ///   - `.blackout`/`.logo`: no snapshot involved at all —
    ///     `renderedContent` stays live; the CANVAS is what decides not to
    ///     draw lyrics for those two states (`ProjectionCanvasView`'s
    ///     `switch displayState`), not this view-model.
    public func setDisplayState(_ newState: IHRPDisplayState) {
        guard newState != displayState else { return }
        if newState == .frozen {
            frozenSnapshot = (song, position)
        } else if displayState == .frozen {
            frozenSnapshot = nil
        }
        displayState = newState
        publish()
    }

    /// Cosmetic, TV-local presentation hints (`IHRPMessage.setAppearance`'s
    /// doc: "never content") — `theme`/`textScale` are independently
    /// optional so a caller can adjust just one. NEVER published on
    /// `stateUpdates` — appearance is local to this TV, not part of the
    /// canonical navigation state a remote needs to know about.
    ///
    /// - Parameters:
    ///   - theme: `nil` leaves `appearanceThemeHint` untouched; a non-nil
    ///     value REPLACES it verbatim (Decision D-6: stored, never applied
    ///     — the projection surface is a fixed black full-bleed).
    ///   - textScale: `nil` leaves `textScale` untouched; a non-nil value is
    ///     clamped to `0.5...3.0` before storing (a wildly out-of-range
    ///     remote-supplied value degrades to the nearest sane bound rather
    ///     than distorting the canvas unreadably).
    public func setAppearance(theme: String?, textScale newTextScale: Double?) {
        if let theme {
            appearanceThemeHint = theme
        }
        if let newTextScale {
            textScale = min(max(newTextScale, 0.5), 3.0)
        }
    }

    /// Yields the current `projectionState` to `stateUpdates`'s single
    /// consumer — the one place every mutating intent funnels through, so
    /// "publish after every real change" is enforced in ONE spot rather
    /// than repeated at each call site.
    func publish() {
        continuation.yield(projectionState)
    }
}
