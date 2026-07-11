// ProjectionResolver.swift
// IHFeatures
//
// ELI5: The rulebook for "what does *next*/*previous* actually mean" on the
// tvOS projection screen — given where we are in a song right now and how
// many lines each verse/chorus/bridge has, work out where we land after a
// "next line," "previous component," "jump to line 5," or "scroll" request.
// It never looks at a real song, a network call, or the screen — just plain
// numbers in, plain numbers out — so it's trivial to unit-test and safe for
// BOTH the Siri Remote (this PR) and the future LAN remote (PR-6) to share
// without ever disagreeing about what "next" means.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §3, the load-bearing
// "PR-5/PR-6 boundary" from that file's §1: "the tvOS canvas is driven by
// ONE canonical `(songId, componentIndex, lineIndex, displayState)` with no
// knowledge of WHO set them"). This is THE one place "what does next mean"
// is ever written — `.claude/apple-native-strategy.md` §2.4.1's projection
// blueprint and §2.2's karaoke-highlight/mode-preserve behaviour are both
// implemented here, nowhere else, so a future UX change (e.g. PR-7 preferring
// pure clamping over rollover, spec Decision D-2) is a ONE-FILE edit.
//
// Every function below is `static`, PURE (no stored state, no side effects),
// and TOTAL — it always returns a value, never traps (no force-unwrap, no
// array out-of-bounds), and never throws. A malformed/adversarial input
// (e.g. a `componentIndex` far outside the song, a `scroll(delta:)` of
// 100,000) degrades to a safe clamp rather than a crash — the same defensive
// posture `SongID.init?(rawValue:)`'s "parse, don't validate" doc comment
// argues for at the API boundary, applied here to relative navigation maths
// instead of string parsing.
//
// `ProjectionSongMap`/`ProjectionPosition` are the ONLY two value types this
// file needs — deliberately NOT `SongDetail`/`SongComponent` themselves, so
// every test in `ProjectionResolverTests.swift` can construct a fixture
// map/position directly (`ProjectionSongMap(componentLineCounts: [4, 2, 4])`)
// without decoding a real song at all; `ProjectionSongMap(detail:)` is the
// ONE bridge from a real `SongDetail` into this pure world, and it reads
// `SongDetail.orderedComponents` — the arrangement-honouring accessor that
// type's own doc comment calls "the ONE property... any future renderer
// should actually iterate, never `components` directly" — never the raw,
// possibly-unordered `components` array.
import Foundation
import IHModels

/// Line counts per component, in ARRANGEMENT order — the only shape of a
/// song this file's resolution maths ever needs.
///
/// ELI5: "This song has a 4-line verse, then a 2-line chorus, then a 4-line
/// verse again" — nothing else about the song matters for figuring out
/// where "next" or "previous" lands.
public struct ProjectionSongMap: Sendable, Equatable {
    /// `componentLineCounts[i]` = how many lyric lines
    /// `SongDetail.orderedComponents[i]` has. A `0` at some index is a
    /// legitimate (if unusual) defensive case — an empty component the web
    /// assembler shouldn't produce, but this resolver must still handle
    /// without assuming it can't happen (see `nextLine`/`prevLine`'s
    /// rollover-skips-empty-components rule below).
    public let componentLineCounts: [Int]

    /// Direct constructor — what every `ProjectionResolverTests` fixture
    /// uses, so a test can express "a song shaped like [4, 2, 4]" without
    /// building a real `SongDetail` at all.
    public init(componentLineCounts: [Int]) {
        self.componentLineCounts = componentLineCounts
    }

    /// The REAL bridge from a decoded song into this pure world.
    ///
    /// ELI5: "Look at the actual song, in the order the curator arranged
    /// it, and just remember how long each part is."
    ///
    /// DETAILED: `detail.orderedComponents` — never `detail.components` —
    /// per that property's own doc comment: it's "the ONE property...
    /// any future renderer should actually iterate," reproducing the exact
    /// web rendering behaviour (a curator's custom `arrangement` is never
    /// silently ignored). `ProjectionViewModel` recomputes this on every
    /// successful song load (§4.1's `songMap` stored property).
    public init(detail: SongDetail) {
        self.componentLineCounts = detail.orderedComponents.map(\.lines.count)
    }
}

/// Where the projection currently is within the loaded song.
///
/// ELI5: "Which verse/chorus/bridge are we on, and — if we're highlighting
/// one specific line karaoke-style — which line."
///
/// DETAILED: `componentIndex == nil` ⇔ no song loaded at all (or a song with
/// zero components) — `.none` below. `lineIndex == nil` while
/// `componentIndex != nil` ⇔ "component mode" (strategy §2.2: the whole
/// component is shown, no per-line highlight); `lineIndex != nil` ⇔ "line
/// mode" (the karaoke current-line highlight). Moving between components
/// PRESERVES which of these two modes you were in — see `nextComponent`/
/// `prevComponent`'s "mode-preserving line rule" below — which is why this
/// is a `struct` with two independently-nil-able fields rather than a
/// three-case enum: "which mode" and "which component" vary independently
/// as navigation happens.
public struct ProjectionPosition: Sendable, Equatable {
    public var componentIndex: Int?
    public var lineIndex: Int?

    public init(componentIndex: Int?, lineIndex: Int?) {
        self.componentIndex = componentIndex
        self.lineIndex = lineIndex
    }

    /// No song loaded (or a song with zero components) — what
    /// `initialPosition(map:)` returns for an empty map, and the everyday
    /// "nothing selected yet" value `ProjectionViewModel.position` starts at.
    public static let none = ProjectionPosition(componentIndex: nil, lineIndex: nil)
}

/// The pure resolution core — every "what does this relative intent mean"
/// question the projection view-model asks, answered without touching any
/// mutable state.
///
/// ELI5: Feed it "where we are" + "how big the song is" + "what button was
/// pressed," and it tells you "where we now are" — the same answer every
/// time for the same inputs, which is exactly why this is trivial to test
/// exhaustively.
///
/// DETAILED: Every function is a total, clamping transform — spec §3's
/// "clamp — never trap, never throw." A handful of functions share the
/// SAME two non-obvious rules, documented once here rather than repeated at
/// every call site:
///   1. **Clamp-as-no-op**: when a relative step would clamp to the SAME
///      component/line it started at, the ENTIRE `ProjectionPosition` is
///      returned UNCHANGED — including any co-ordinate that wasn't even
///      part of the clamp (e.g. `prevComponent` clamping at component 0
///      leaves `lineIndex` exactly as it was, never resetting it). This is
///      what lets `ProjectionViewModel`'s `Equatable`-dedupe on
///      `stateUpdates` correctly treat "pressed left at the very first
///      verse" as "nothing happened" (spec §4's "a clamped no-op intent
///      yields nothing, so PR-6 never rebroadcasts an unchanged state").
///   2. **Mode-preserving line rule** (`nextComponent`/`prevComponent`
///      only): moving to a DIFFERENT component keeps you in the same mode
///      you were in — component mode stays component mode (`lineIndex`
///      stays `nil`); line mode re-enters the new component at line 0,
///      UNLESS that component has zero lines, in which case it degrades to
///      component mode (there is no line 0 to highlight).
public enum ProjectionResolver {

    /// Where a freshly-loaded song opens — spec: "`.selectSong`'s doc: both
    /// nil = just show the song from the top."
    ///
    /// ELI5: "Start at the very beginning, showing the whole first part
    /// (not one highlighted line yet)."
    static func initialPosition(map: ProjectionSongMap) -> ProjectionPosition {
        guard !map.componentLineCounts.isEmpty else { return .none }
        return ProjectionPosition(componentIndex: 0, lineIndex: nil)
    }

    /// Clamps an EXPLICIT `(componentIndex, lineIndex)` pair (e.g. from a
    /// remote's `selectSong(songId:componentIndex:lineIndex:)`) into the
    /// song's actual bounds.
    ///
    /// ELI5: "You asked for this exact part/line — here's the closest real
    /// spot in this song to what you asked for."
    static func clamped(componentIndex: Int?, lineIndex: Int?, map: ProjectionSongMap) -> ProjectionPosition {
        guard !map.componentLineCounts.isEmpty else { return .none }
        guard let componentIndex else { return initialPosition(map: map) }
        let idx = clampIndex(componentIndex, count: map.componentLineCounts.count)
        guard let lineIndex else { return ProjectionPosition(componentIndex: idx, lineIndex: nil) }
        let count = lineCount(at: idx, map: map)
        guard count > 0 else { return ProjectionPosition(componentIndex: idx, lineIndex: nil) }
        return ProjectionPosition(componentIndex: idx, lineIndex: clampIndex(lineIndex, count: count))
    }

    /// "Show the next component" — presenter's forward button.
    static func nextComponent(from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        stepComponent(from: position, map: map, delta: 1)
    }

    /// "Show the previous component" — presenter's back button.
    static func prevComponent(from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        stepComponent(from: position, map: map, delta: -1)
    }

    /// Shared component-step engine for `nextComponent`/`prevComponent` —
    /// implements both of this type's header rules (clamp-as-no-op,
    /// mode-preserving line rule) in exactly one place.
    private static func stepComponent(from position: ProjectionPosition, map: ProjectionSongMap, delta: Int) -> ProjectionPosition {
        let count = map.componentLineCounts.count
        guard let idx = position.componentIndex, count > 0 else { return position }
        let newC = clampIndex(idx + delta, count: count)
        guard newC != idx else { return position }  // clamp-as-no-op — the FULL position, unchanged
        guard position.lineIndex != nil else {
            return ProjectionPosition(componentIndex: newC, lineIndex: nil)  // component mode stays component mode
        }
        let newLineCount = lineCount(at: newC, map: map)
        return ProjectionPosition(componentIndex: newC, lineIndex: newLineCount > 0 ? 0 : nil)
    }

    /// "Advance the karaoke highlight by one line" — rolls into the next
    /// non-empty component at the end of the current one (spec Decision
    /// D-2: "next always advances," a presenter-ergonomics choice), and
    /// clamps at the very end of the song.
    static func nextLine(from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        let count = map.componentLineCounts.count
        guard let idx = position.componentIndex, count > 0 else { return position }
        guard let lineIndex = position.lineIndex else {
            // Entering line mode from component mode: line 0 of THIS
            // component if it has any; otherwise treat exactly like
            // reaching the end of an (empty) component — roll forward.
            let thisCount = lineCount(at: idx, map: map)
            if thisCount > 0 { return ProjectionPosition(componentIndex: idx, lineIndex: 0) }
            return rolloverForward(from: idx, count: count, map: map) ?? position
        }
        let thisCount = lineCount(at: idx, map: map)
        if lineIndex < thisCount - 1 {
            return ProjectionPosition(componentIndex: idx, lineIndex: lineIndex + 1)
        }
        return rolloverForward(from: idx, count: count, map: map) ?? position
    }

    /// "Step the karaoke highlight back one line" — rolls BACK into the
    /// previous non-empty component's LAST line at the start of the current
    /// one; a component-mode call is a deliberate no-op (spec: "prev from
    /// component mode is a no-op, not an entry into line mode" — there is no
    /// well-defined "last line of the CURRENT component" to jump to when no
    /// line was highlighted in the first place).
    static func prevLine(from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        guard let idx = position.componentIndex, let lineIndex = position.lineIndex else { return position }
        if lineIndex > 0 {
            return ProjectionPosition(componentIndex: idx, lineIndex: lineIndex - 1)
        }
        return rolloverBackward(from: idx, map: map) ?? position
    }

    /// Searches `idx+1 ..< count` (ascending) for the first component with at
    /// least one line — the rollover-skips-empty-components rule this
    /// type's header promises. `nil` when none is found (end of song).
    ///
    /// ELI5: "Keep walking forward through the parts until you find one
    /// that actually has words in it."
    ///
    /// DETAILED: Empty components "shouldn't exist" per the web assembler
    /// (`includes/lyric_lines_read.php`'s output always carries ≥1 line per
    /// component in practice) — but this resolver is a TOTAL function and
    /// must not assume that invariant holds; the `D = [2, 0, 3]` fixture in
    /// `ProjectionResolverTests.swift` exercises exactly this defensive
    /// branch.
    private static func rolloverForward(from idx: Int, count: Int, map: ProjectionSongMap) -> ProjectionPosition? {
        var next = idx + 1
        while next < count {
            if lineCount(at: next, map: map) > 0 { return ProjectionPosition(componentIndex: next, lineIndex: 0) }
            next += 1
        }
        return nil
    }

    /// The mirror-image search for `prevLine` — walks `idx-1 ... 0`
    /// (descending) for the first non-empty component, landing on ITS last
    /// line. `nil` when none is found (start of song).
    private static func rolloverBackward(from idx: Int, map: ProjectionSongMap) -> ProjectionPosition? {
        var prev = idx - 1
        while prev >= 0 {
            let count = lineCount(at: prev, map: map)
            if count > 0 { return ProjectionPosition(componentIndex: prev, lineIndex: count - 1) }
            prev -= 1
        }
        return nil
    }

    /// Jump straight to a specific line WITHIN the current component —
    /// `IHRPMessage.jumpLine`'s doc: "within the current component." A
    /// component with zero lines (or no component selected at all) has
    /// nowhere valid to jump to, so it's a no-op.
    static func jumpLine(to index: Int, from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        guard let idx = position.componentIndex else { return position }
        let count = lineCount(at: idx, map: map)
        guard count > 0 else { return position }
        return ProjectionPosition(componentIndex: idx, lineIndex: clampIndex(index, count: count))
    }

    /// A relative scroll nudge (e.g. a Siri Remote swipe) — applies
    /// `nextLine`/`prevLine` `|delta|` times, stopping the moment a step
    /// stops changing anything (both a performance guard against a huge
    /// `|delta|` walking the whole song line-by-line for nothing further,
    /// and what makes this function provably TERMINATE for any input).
    ///
    /// ELI5: "Nudge the highlight this many lines forward or back — but
    /// stop early the instant nudging further wouldn't move it anyway."
    ///
    /// DETAILED: `|delta|` is capped at 100 BEFORE stepping — spec: "a
    /// malicious/buggy remote can't spin the CPU" — a defensive ceiling
    /// independent of the early-stop above (the early-stop alone would
    /// still be O(song length) in the worst case for a huge `delta` on a
    /// short song; the cap bounds the absolute worst case too).
    static func scroll(delta: Int, from position: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition {
        guard delta != 0 else { return position }
        let steps = min(abs(delta), 100)
        var current = position
        for _ in 0..<steps {
            let next = delta > 0 ? nextLine(from: current, map: map) : prevLine(from: current, map: map)
            if next == current { break }
            current = next
        }
        return current
    }

    // MARK: - Shared safe accessors (never trap on an out-of-range index)

    /// Clamps `value` into `0..<count` — the one clamping rule every
    /// function above shares. `count` is assumed `> 0` by every call site
    /// (each guards that separately, since "no valid index at all" and
    /// "clamp within valid indices" are different questions).
    private static func clampIndex(_ value: Int, count: Int) -> Int {
        min(max(value, 0), count - 1)
    }

    /// `map.componentLineCounts[index]`, or `0` if `index` is out of range —
    /// defensive against a hypothetically stale `ProjectionPosition` being
    /// asked about after the song map changed underneath it (should never
    /// happen given `ProjectionViewModel` always resolves position and map
    /// together, but this file's whole contract is "never trap," so an
    /// out-of-range read degrades to "treat it as empty" rather than
    /// crashing).
    private static func lineCount(at index: Int, map: ProjectionSongMap) -> Int {
        map.componentLineCounts.indices.contains(index) ? map.componentLineCounts[index] : 0
    }
}
