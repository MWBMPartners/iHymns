# Apple Phase-2 PR-5 — tvOS ProjectionCanvasView + projection view-model + tvOS browse wiring (#1504)

> **STATUS: IMPLEMENTATION SPEC (Fable 5 deep-design pass, 2026-07-11).** Grounded in a code-level read of the merged PR-4 (`Sources/IHLive/LANRemote/*`, branch `feat/apple-p2-lanremote`), `apple-phase2-implementation-plan.md` §2 (PR-5 row) + §6.1, `apple-native-strategy.md` §2.2 (tvOS blueprint) + §2.4.1/§2.4.4, and the existing `IHFeatures`/`IHModels`/`IHAPI`/`IHDesign` code cited inline below. A Sonnet builder should be able to execute this file top-to-bottom with minimal further judgement. Target branch: a new `feat/apple-p2-pr5-projection` off the current Apple line; PR targets `alpha`.

---

## 1. The PR-5 / PR-6 boundary and the driver-agnostic seam

**The load-bearing constraint (plan §6.1, "the soft spot"):** the tvOS canvas is driven by ONE canonical `(songId, componentIndex, lineIndex, displayState)` with **no knowledge of WHO set them**, so both the LAN remote (PR-6, #1421) and the future server-following projector (PR-15, #1428) stay thin and never re-fork song-resolution logic.

**PR-5 builds** (this spec):
- `ProjectionResolver` — a PURE, deterministic resolution core (relative intents → new indices, with clamping) — the single place "what does *next* mean" is ever written.
- `ProjectionViewModel` — `@MainActor @Observable`, owns song fetching (`song_detail` under the TV's OWN auth), the canonical projection state, the frozen-snapshot rule, and a driver-facing `AsyncStream<ProjectionState>`.
- `ProjectionCanvasView` — the full-bleed 10-foot display surface (lyrics / blackout / logo / frozen).
- `ProjectionSceneView` + `TVRootView` — tvOS browse wiring + the FIRST driver of the view-model: the TV's own Siri Remote (`.onMoveCommand`/`.onPlayPauseCommand`) exercising the exact same intents PR-6 will feed remotely.
- CI: a tvOS Simulator build step in `.github/workflows/apple.yml` (currently macOS-only).

**PR-6 will wire** (NOT built here — but the seam is fixed by this spec):
```swift
// PR-6's whole bridge, conceptually — nothing else should be needed:
Task {                                             // intents IN
    for await event in tvListener.controlEvents {  // TVListenerActor.ControlEvent
        switch event.frame.message {
        case .prepare(let id):        _ = await projectionViewModel.prepare(songId: id)
        case .selectSong(let id, let c, let l):
            let result = await projectionViewModel.selectSong(songId: id, componentIndex: c, lineIndex: l)
            // result == .unavailable → tvListener sends .error(.contentUnavailable) to event.connectionId
        case .nextComponent:          projectionViewModel.nextComponent()
        case .prevComponent:          projectionViewModel.prevComponent()
        case .nextLine:               projectionViewModel.nextLine()
        case .prevLine:               projectionViewModel.prevLine()
        case .jumpLine(let index):    projectionViewModel.jumpLine(index: index)
        case .setDisplayState(let s): projectionViewModel.setDisplayState(s)
        case .scroll(let delta):      projectionViewModel.scroll(delta: delta)
        case .setAppearance(let t, let s): projectionViewModel.setAppearance(theme: t, textScale: s)
        default: break                // hello/ping/endControl = transport concerns, handled inside TVListenerActor
        }
    }
}
Task {                                             // canonical state OUT
    for await state in projectionViewModel.stateUpdates {
        await tvListener.updateState(
            songId: state.songId, componentIndex: state.componentIndex,
            lineIndex: state.lineIndex, displayState: state.displayState)
    }
}
```
Key facts about the already-merged PR-4 side of this seam (verified in code):
- `TVListenerActor.controlEvents: AsyncStream<ControlEvent>` is `nonisolated let`; `ControlEvent = {connectionId: UUID, frame: IHRPFrame}`; `IHRPFrame.message: IHRPMessage`.
- `TVListenerActor.updateState(songId:componentIndex:lineIndex:displayState:) async` bumps `revision` itself and broadcasts `.state` to every paired remote — the view-model NEVER manages `revision`.
- `TVListenerActor` deliberately does NOT resolve relative intents (its own header comment says resolution belongs to "the tvOS projection view-model, PR-5/PR-6's job").
- `IHRPDisplayState` (`.lyrics/.blackout/.logo/.frozen`, `Sources/IHLive/LANRemote/IHRPPayloads.swift`) **is reused as the view-model's display vocabulary** — see Decision D-4.

**Rules the seam encodes:**
1. `ProjectionViewModel` never imports `Network`, never references `TVListenerActor`, and never sees a connection id — it is equally drivable by the Siri Remote (this PR), the LAN bridge (PR-6), and a server-poll loop (PR-15).
2. Canonical state = **what is actually on screen** (matching `IHRPState`'s "Here's exactly what's on the TV screen right now"): `selectSong` publishes only AFTER a successful `song_detail` fetch; a failed/gated fetch leaves state untouched and returns a result the driver can map to `IHRPErrorCode.contentUnavailable`.
3. `stateUpdates` has exactly **ONE intended consumer** (the active driver bridge) — `AsyncStream` delivers each element to one iterator. The canvas/UI observes the `@Observable` properties instead, mirroring the `SessionController.stateUpdates` → `AppRootViewModel.sessionState` precedent (`IHAuth/SessionController.swift` / `IHFeatures/AppRootViewModel.swift`).
4. The LAN link never carries lyric text (PR-4's binding INVARIANT); the TV fetches `song_detail` itself via `APIClient.songDetail(id:)` — content gating (web rule #28) therefore applies on the TV under its own auth automatically.

---

## 2. Files — new and edited

All new Swift files: ≤400 raw lines (`appApple/Scripts/loc-budget.sh` counts raw `wc -l` including comments — budget for the two-register annotation style by splitting early, matching the `TVListenerActor`/`+Connections`/`+Messages` and `APIClient`/`+Networking` precedent). SwiftLint (`appApple/.swiftlint.yml`): keep function bodies ≤60 lines; `line_length` is disabled so long doc comments are fine. Every file carries the ELI5 + DETAILED two-register header referencing **#1504**, plan §2 PR-5, and strategy §2.2/§2.4.1 — match the PR-4 files' comment style exactly.

### New — module `IHFeatures` (flat directory, matching that target's existing convention)

| File | Purpose (one line) |
|---|---|
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionResolver.swift` | Pure resolution core: `ProjectionSongMap`, `ProjectionPosition`, and total (never-trapping) static functions for every relative intent. |
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionViewModel.swift` | `@MainActor @Observable` view-model: stored state, `ProjectionState`/`SongResult` types, `init`, fetch machinery, `prepare`/`selectSong`. |
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionViewModel+Intents.swift` | Same-type extension: relative intents, `jumpLine`, `scroll`, `setDisplayState` (frozen snapshot), `setAppearance`, the publish helper. |
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionCanvasView.swift` | The full-bleed display surface — a pure function of passed-in state; no view-model coupling, previewable. |
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/ProjectionSceneView.swift` | tvOS projection screen: canvas + Siri-Remote commands + translucent control strip + song-picker overlay. |
| `appApple/Packages/iHymnsKit/Sources/IHFeatures/TVRootView.swift` | The tvOS shell root: `TabView` — Project · Songbooks · Search (strategy §2.2's tvOS tab set, Home/Settings deferred). |

### New — tests (target `IHFeaturesTests`, Swift Testing `@Test`/`#expect`)

| File | Purpose |
|---|---|
| `appApple/Packages/iHymnsKit/Tests/IHFeaturesTests/ProjectionResolverTests.swift` | Pure resolution assertions (§5) — no network, no MainActor, no rendering. |
| `appApple/Packages/iHymnsKit/Tests/IHFeaturesTests/ProjectionViewModelTests.swift` | View-model behaviour with an injected fetcher closure — no network (§5). |

### Edited

| File | Edit |
|---|---|
| `appApple/Apps/iHymnsTV/Sources/IHymnsTVApp.swift` | Replace `PhaseZeroSkeletonView` with `TVRootView(viewModel:)`; build the one `AppRootViewModel` exactly as `IHymnsApp.swift` does (§4.4). |
| `.github/workflows/apple.yml` | Add the tvOS Simulator build step after the existing macOS build (§6). |

**No edits to:** `Package.swift` (IHFeatures already depends on `IHLive` + `IHAPI` + `IHModels` + `IHDesign`; `IHFeaturesTests` already depends on `IHTestFixtures` + `IHAPITestSupport`), `project.yml` (the `iHymnsTV` target already links `[IHFeatures, IHDesign]`; PR-5 needs NO new entitlements — `NSLocalNetworkUsageDescription`/`NSBonjourServices` are PR-6's), `IHLive` (nothing in PR-4 changes).

---

## 3. `ProjectionResolver` — the pure core

```swift
// ProjectionResolver.swift (IHFeatures) — imports: Foundation, IHModels

/// Line counts per component, in ARRANGEMENT order — the only song shape
/// resolution ever needs. Derived via SongDetail.orderedComponents (the ONE
/// arrangement-honouring accessor, per its own doc comment: "the ONE property
/// ... any future renderer should actually iterate, never `components` directly").
public struct ProjectionSongMap: Sendable, Equatable {
    public let componentLineCounts: [Int]
    public init(componentLineCounts: [Int])            // direct — what tests use
    public init(detail: SongDetail) {                  // = detail.orderedComponents.map(\.lines.count)
}

/// Where the projection currently is. componentIndex nil ⇔ no song / empty song.
/// lineIndex nil ⇔ "component mode" (whole component shown, no karaoke line highlight);
/// non-nil ⇔ "line mode" (karaoke current-line highlight, strategy §2.2).
public struct ProjectionPosition: Sendable, Equatable {
    public var componentIndex: Int?
    public var lineIndex: Int?
    public static let none = ProjectionPosition(componentIndex: nil, lineIndex: nil)
}

public enum ProjectionResolver {   // all static, all pure, all total (clamp — never trap, never throw)
    static func initialPosition(map: ProjectionSongMap) -> ProjectionPosition
    static func clamped(componentIndex: Int?, lineIndex: Int?, map: ProjectionSongMap) -> ProjectionPosition
    static func nextComponent(from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
    static func prevComponent(from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
    static func nextLine(from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
    static func prevLine(from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
    static func jumpLine(to index: Int, from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
    static func scroll(delta: Int, from: ProjectionPosition, map: ProjectionSongMap) -> ProjectionPosition
}
```

Exact algorithms (`count` = `map.componentLineCounts.count`, `lines(c)` = `map.componentLineCounts[c]`):

- **initialPosition** — `count == 0` → `.none`; else `(0, nil)` (song opens in component mode "from the top", matching `.selectSong`'s doc: "both nil = just show the song from the top").
- **clamped(componentIndex:lineIndex:map:)** — used by `selectSong` with explicit indices. `count == 0` → `.none`. `componentIndex` nil → `initialPosition`. Else `c = min(max(componentIndex, 0), count - 1)`. `lineIndex` nil → `(c, nil)`. Else if `lines(c) == 0` → `(c, nil)`; else `l = min(max(lineIndex, 0), lines(c) - 1)` → `(c, l)`.
- **nextComponent** — no `componentIndex` → unchanged. `c' = min(c + 1, count - 1)`; **if `c' == c` → unchanged** (a clamped component step is a FULL no-op — position, including `lineIndex`, untouched, so the view-model's dedupe publishes nothing). Otherwise apply the **mode-preserving line rule:** new `lineIndex` = `nil` if it was `nil`; else `0` if `lines(c') > 0`, else `nil`. (Same rules for prevComponent with `c' = max(c - 1, 0)`.)
- **nextLine** — no `componentIndex` → unchanged. If `lineIndex == nil`: enter line mode at `(c, 0)` if `lines(c) > 0`; if `lines(c) == 0`, treat as rollover-forward (below). If `lineIndex < lines(c) - 1` → `(c, lineIndex + 1)`. If at the LAST line: **roll over** to the next component that has ≥1 line — search `c+1 ..< count` for the first `lines(cʹ) > 0` → `(cʹ, 0)`; none found → unchanged (clamp at end of song).
- **prevLine** — `lineIndex == nil` or no song → unchanged (deliberate: prev from component mode is a no-op, not an entry into line mode). `lineIndex > 0` → `(c, lineIndex - 1)`. `lineIndex == 0` → roll back: search `c-1 ... 0` (descending) for the first `lines(cʹ) > 0` → `(cʹ, lines(cʹ) - 1)`; none → unchanged.
- **jumpLine** — per `IHRPMessage.jumpLine`'s doc ("within the current component"): no `componentIndex` or `lines(c) == 0` → unchanged; else `(c, min(max(index, 0), lines(c) - 1))`.
- **scroll** — `delta == 0` → unchanged. Clamp `|delta|` to 100 (defensive ceiling; a malicious/buggy remote can't spin the CPU). Apply `nextLine` (`delta > 0`) or `prevLine` (`delta < 0`) `|delta|` times, stopping early when a step returns the same position.

The rollover-skips-empty-components rule is the one non-obvious branch — annotate it (empty components shouldn't exist per the web assembler, but a total function must not assume that).

## 4. `ProjectionViewModel` — the driver-agnostic engine

### 4.1 Public API (this IS the PR-6 contract — do not deviate)

```swift
// ProjectionViewModel.swift (+Intents.swift) — imports: Foundation, IHAPI, IHLive, IHModels, IHLog, Observation

@MainActor @Observable
public final class ProjectionViewModel {

    /// The driver-facing canonical 4-tuple — EXACTLY the arguments of
    /// TVListenerActor.updateState(songId:componentIndex:lineIndex:displayState:).
    /// No `revision` — the listener owns that counter (its updateState bumps it).
    public struct ProjectionState: Sendable, Equatable {
        public let songId: SongID?
        public let componentIndex: Int?
        public let lineIndex: Int?
        public let displayState: IHRPDisplayState
    }

    /// Driver-agnostic outcome of prepare/selectSong. PR-6 maps
    /// .unavailable → IHRPErrorCode.contentUnavailable (strategy §2.4.3's exact handoff)
    /// and .failed → .internalError; the Siri-Remote driver shows it inline.
    public enum SongResult: Sendable, Equatable {
        case shown            // (for prepare: "cached")
        case unavailable      // the TV's own fetch was DENIED (APIError.unauthorized)
        case failed(String)   // offline / maintenance / decode / other — user-facing message
    }

    // Observed by the canvas (never by drivers):
    public private(set) var song: SongDetail?                 // the loaded song being projected
    public private(set) var position: ProjectionPosition = .none
    public private(set) var displayState: IHRPDisplayState = .lyrics
    public private(set) var isLoadingSong = false             // a selectSong fetch is in flight
    public private(set) var textScale: Double = 1.0           // setAppearance; clamped 0.5...3.0
    public private(set) var appearanceThemeHint: String?      // stored, NOT applied (Decision D-6)

    /// What the canvas should RENDER — equals (song, position) except while
    /// .frozen, when it is the snapshot captured at freeze time (§4.3).
    public var renderedContent: (song: SongDetail?, position: ProjectionPosition) { get }

    /// The current canonical 4-tuple (computed from the stored properties).
    public var projectionState: ProjectionState { get }

    /// Driver-facing feed — yields ONE ProjectionState per actual change
    /// (Equatable-deduped; a clamped no-op intent yields nothing, so PR-6
    /// never rebroadcasts an unchanged state / never burns a revision).
    /// ONE consumer only (see §1 rule 3). Built with AsyncStream.makeStream —
    /// the SessionController/TVListenerActor idiom, `nonisolated let`.
    nonisolated public let stateUpdates: AsyncStream<ProjectionState>

    /// fetchSongDetail is injected (the SongDetailViewModel.mediaFetcher precedent):
    /// production = { try await rootViewModel.songDetail(id: $0) }; tests = a canned closure.
    public init(fetchSongDetail: @escaping @Sendable (SongID) async throws -> SongDetail)

    // Intents (one per IHRPMessage control case that reaches the display layer):
    @discardableResult public func prepare(songId: SongID) async -> SongResult
    @discardableResult public func selectSong(songId: SongID, componentIndex: Int? = nil, lineIndex: Int? = nil) async -> SongResult
    public func nextComponent()
    public func prevComponent()
    public func nextLine()
    public func prevLine()
    public func jumpLine(index: Int)
    public func scroll(delta: Int)
    public func setDisplayState(_ newState: IHRPDisplayState)
    public func setAppearance(theme: String?, textScale: Double?)
}
```

`hello`/`ping`/`endControl` are transport concerns `TVListenerActor` already handles — they MUST NOT appear on this API.

Internal stored state (`internal`, not `private`, where `+Intents.swift` touches it — Swift `private` is file-scoped; same visibility note `APIClient.session` and `TVListenerActor.connections` already document):
- `let fetchSongDetail: @Sendable (SongID) async throws -> SongDetail`
- `let continuation: AsyncStream<ProjectionState>.Continuation`
- `var preparedSongs: [SongID: SongDetail]` + `var preparedOrder: [SongID]` — capacity **3**, evict oldest (a `prepare` cache, strategy §2.4.4: "a cold select adds one API RTT which prepare hides").
- `var fetchGeneration: UInt64` — latest-wins for overlapping `selectSong`s (§4.2).
- `var frozenSnapshot: (song: SongDetail?, position: ProjectionPosition)?`
- `var songMap: ProjectionSongMap` — recomputed on every successful song load; `ProjectionSongMap(componentLineCounts: [])` when `song == nil`.

### 4.2 `selectSong` / `prepare` algorithm

`selectSong(songId:componentIndex:lineIndex:)`:
1. If `song?.songId == songId` (already projecting this song): NO fetch — reposition only: `position = (componentIndex == nil && lineIndex == nil) ? ProjectionResolver.initialPosition(map: songMap) : ProjectionResolver.clamped(...)`; publish; return `.shown`.
2. Resolve a `SongDetail`: `preparedSongs[songId]` if present, else network — `fetchGeneration += 1; let generation = fetchGeneration; isLoadingSong = true; defer-equivalent reset`. After the `await`, **guard `generation == fetchGeneration` else return `.failed("superseded")`** (a newer selectSong won; do NOT touch state — the newer call owns it). This is the "song_detail fetch in flight" rule: canonical state and `song` change only on the winning, successful fetch.
3. On success: `song = detail; songMap = ProjectionSongMap(detail: detail); position = ` (rule from step 1); if `displayState == .frozen` leave it frozen (the snapshot keeps showing; the operator cued a new song behind the freeze — §4.3); publish; `IHLog.remote.notice("projection.select id=\(songId.rawValue, privacy: .public)")`; return `.shown`.
4. On error: state untouched. `catch let e as APIError where e == .unauthorized` → return `.unavailable` (this is web content-gating denying the TV — rule #28; PR-4's `IHRPErrorCode.contentUnavailable` doc names this exact handoff). Any other `APIError` → `.failed(e.userFacingMessage)` (the existing `APIError+UserFacing.swift` mapping). Non-APIError → `.failed(generic copy)`. Log `.error` with the result kind, never the raw response.

`prepare(songId:)`: if already loaded or cached → `.shown`. Else fetch (no generation interaction with selectSong — but if a selectSong for the SAME id is what raced, the cache insert is harmless); insert into `preparedSongs` with cap-3 eviction; **never** mutates `song`/`position`/`displayState`, **never** publishes. Same error mapping.

### 4.3 Display-state semantics (the part a builder must not guess)

- `displayState` affects **rendering only** — it never gates resolution. Navigation intents are accepted and canonical state updates in ALL four display states (an operator blacks out the screen, cues the next song/verse, then reveals — the real presenter workflow).
- **Entering `.frozen`** (from any other state): capture `frozenSnapshot = (song, position)`. While frozen, `renderedContent` returns the snapshot; canonical state keeps moving if intents arrive (remotes stay truthful: the `IHRPState` they see carries `displayState == .frozen`, which TELLS them the screen shows older content — that is the documented meaning of frozen).
- **Leaving `.frozen`**: clear the snapshot; `renderedContent` reverts to live `(song, position)`.
- `.blackout` / `.logo`: `renderedContent` is still the live values (the canvas simply doesn't draw lyrics); no snapshot involved.
- `setDisplayState` with the CURRENT value → no-op, nothing published.
- Every actual `displayState` change publishes one `ProjectionState`.
- `setAppearance(theme:textScale:)`: clamp `textScale` to `0.5...3.0` and store; store `theme` verbatim as `appearanceThemeHint` (Decision D-6). NEVER published on `stateUpdates` (appearance is TV-local, not canonical state — matches `IHRPMessage.setAppearance`'s "cosmetic, TV-local presentation hints").

### 4.4 Composition (who constructs what)

- `TVRootView` owns the ONE `ProjectionViewModel` for the app run (`@State private var projectionViewModel` built in `init`/default-value expression) with `fetchSongDetail: { [rootViewModel] id in try await rootViewModel.songDetail(id: id) }` — `AppRootViewModel.songDetail(id:)` (`AppRootViewModel+Catalog.swift:28`) is the established "one APIClient per app run, reached through the root view model" pass-through. (`AppRootViewModel` is `@MainActor`; the closure is `@Sendable async` and simply `await`s onto it — fine under strict concurrency.)
- `IHymnsTVApp.swift` mirrors `IHymnsApp.swift` byte-for-byte in spirit: keep `IHFonts.registerBundledFonts()` in `init()`; build `@State private var rootViewModel` via `AppRootViewModel.makeLive(environment:)` (`AppRootViewModel+Live.swift:52`) using the SAME environment expression `IHymnsApp` uses (`IHSettingsStore().apiEnvironmentOverride ?? APIEnvironment.defaultForBuild` — copy the exact expression from `IHymnsApp.swift`, do not re-derive); body = `TVRootView(viewModel: rootViewModel)`. Update the file's #1412-era header comment (it currently documents the PhaseZeroSkeletonView state).

---

## 5. Views

### 5.1 `ProjectionCanvasView` — pure display surface

Value-driven (previewable, no view-model import):
```swift
public struct ProjectionCanvasView: View {
    let song: SongDetail?               // = viewModel.renderedContent.song
    let componentIndex: Int?            // = renderedContent.position.componentIndex
    let lineIndex: Int?
    let displayState: IHRPDisplayState  // import IHLive
    let isLoading: Bool
    let textScale: Double
}
```
Structure:
- `ZStack { Color.black.ignoresSafeArea() ... }` — the projection surface is always a black full-bleed (Decision D-6); do NOT theme it.
- `switch displayState`:
  - `.blackout` → nothing over the black.
  - `.logo` → the "iHymns" type-mark: `Text("iHymns").font(.system(size: 120, weight: .bold)).foregroundStyle(IHColorTokens.accent)` (Decision D-5 — no shared logo image asset exists in `IHDesign`; do not invent one).
  - `.lyrics` and `.frozen` → the lyric layout below (frozen-ness is already baked into the values the caller passed from `renderedContent`; the canvas needs no frozen branch beyond an optional subtle "Frozen" badge on the strip — see SceneView).
- Lyric layout (`song != nil`, `componentIndex != nil`):
  - Header line (small, top-leading, secondary): `"\(songId.rawValue) · \(title)"`.
  - Current component ONLY (no scroll list — a projector shows one section): component label styled like `SongComponentView`'s (`.caption.smallCaps().bold()`, `IHColorTokens.accent`; reuse its `"type.capitalized [number if > 0]"` label rule — extract that 3-line label logic if convenient, otherwise mirror it with a comment cross-referencing `SongComponentView.label`).
  - Lines: `ForEach(component.lines.indices, id: \.self)` with per-line `Text(line).ihLyricLineStyle(componentType: component.type, textScale: projectionBaseScale * textScale)` — **reuse the ONE lyric styling seam** (`IHDesign/IHLyricTypography.swift`; its header explicitly reserves the "tvOS projection" typography ramp as work "built on top of this same seam"). `projectionBaseScale` = a file-level constant, **2.6** (10-foot legibility over the body-derived lyric font; tune in device testing, keep it one named constant).
  - Component resolution: `song.orderedComponents[componentIndex]` guarded by `indices.contains` (defensive — canonical state is resolver-clamped, but the canvas must not trap on a hypothetical mismatch).
  - **Karaoke highlight** (strategy §2.2): if `lineIndex != nil` — active line `.opacity(1.0)` + `.foregroundStyle(IHColorTokens.accent)` + a soft glow (`.shadow(color: IHColorTokens.accent.opacity(0.6), radius: 12)`); other lines `.opacity(0.35)`. If `lineIndex == nil` (component mode) — all lines full opacity, no tint. Animate with `.animation(.easeInOut(duration: 0.25), value: lineIndex)` (the strategy's `matchedGeometry` slide is polish for PR-15 — do not build it here).
  - Auto-fit: wrap the component `VStack` in a `ScrollView`-free fixed frame with `.minimumScaleFactor(0.4)` on the line `Text`s and `.lineLimit(nil)` — good enough for v1; a measured auto-fit pass is deliberately out of scope (note in code).
- Empty/edge states: `song == nil && isLoading` → `ProgressView` over black; `song == nil` otherwise → dim placeholder text "No song selected" (the SceneView's picker overlay is the real affordance).
- Accessibility: one combined element for the component (the `SongComponentView` `.accessibilityElement(children: .combine)` posture); `.accessibilityLabel` folds in display state ("Blackout", "Logo", "Frozen") when not showing lyrics.
- Chords/enrichment/diff affordances are deliberately ABSENT (a projector shows clean lyric text — cite `SongComponentView`'s chord `accessibilityHidden` reasoning in a comment). This is why the canvas does not reuse `SongComponentView` itself: that is a READING view (long-press enrichment, diff params, chords); the canvas reuses the lower building blocks instead — `ihLyricLineStyle`, `orderedComponents`, `IHColorTokens` (Decision D-8).

### 5.2 `ProjectionSceneView` — the tvOS screen + local driver

```swift
public struct ProjectionSceneView: View {
    let rootViewModel: AppRootViewModel        // catalogue for the picker
    let viewModel: ProjectionViewModel         // (Bindable not needed — methods only)
}
```
- Body: `ProjectionCanvasView(song: vm.renderedContent.song, componentIndex: vm.renderedContent.position.componentIndex, lineIndex: ..., displayState: vm.displayState, isLoading: vm.isLoadingSong, textScale: vm.textScale)`, full-bleed.
- **Siri-Remote driver** — `#if os(tvOS)` (these modifiers don't all exist elsewhere; the file must still compile for macOS `swift test`):
  - `.onMoveCommand { direction in ... }`: `.left → viewModel.prevComponent()`, `.right → nextComponent()`, `.up → prevLine()`, `.down → nextLine()`.
  - `.onPlayPauseCommand { viewModel.setDisplayState(viewModel.displayState == .blackout ? .lyrics : .blackout) }` (the presenter's panic-blackout toggle).
  - `.focusable()` on the canvas container so move commands are delivered while "focus is parked" (strategy §2.2).
- **Control strip** (the strategy's "Siri-Remote tap → translucent strip"): `@State private var isStripVisible`; toggled by `.onTapGesture`/select on tvOS, always-on for other platforms' previews. A bottom `HStack` of focusable `Button`s on `.ihGlassCard()` (`IHDesign/IHGlassCard.swift:63`): **Lyrics · Blackout · Logo · Freeze/Unfreeze · Choose Song** — each calling `setDisplayState(...)` (freeze button toggles `.frozen` ↔ `.lyrics`) or opening the picker. Show a small "Frozen" `IHBadge`-style indicator while `displayState == .frozen`.
- **Song picker overlay** (`@State private var isPickingSong`, also auto-shown when `viewModel.song == nil`): a focusable `List` of `rootViewModel.filteredSongs`-or-equivalent — reuse the EXISTING catalogue state: `.task { await rootViewModel.loadCatalogueIfNeeded() }` (`AppRootViewModel.swift:314`) and render rows with the existing `SongSummaryRow` (`IHFeatures/SongSummaryRow.swift`). Row select → `Task { let r = await viewModel.selectSong(songId: summary.songId); /* .failed/.unavailable → inline error text state */ }`; dismiss on `.shown`.
- Fetch-failure surface: a transient overlay `Text` for `.failed(message)` / "Sign in on this TV to show this song" for `.unavailable`.

### 5.3 `TVRootView`

```swift
public struct TVRootView: View {
    private let viewModel: AppRootViewModel
    @State private var projectionViewModel: ProjectionViewModel   // built from viewModel.songDetail(id:) — §4.4
    public init(viewModel: AppRootViewModel)
}
```
- `TabView`: **Project** → `ProjectionSceneView(rootViewModel:viewModel:)`; **Songbooks** → `NavigationStack { SongbooksView(rootViewModel: viewModel) }`; **Search** → `NavigationStack { CatalogueListView(viewModel: viewModel) }`. (Strategy §2.2 lists Home·Songbooks·Search·Project·Settings — Home and Settings are DEFERRED: Home needs tvOS-specific shelf work, Settings arrives with PR-6's trusted-remotes/pairing screens. Say so in the header comment.)
- `.task { await viewModel.restoreSessionIfNeeded(); await viewModel.loadFavoritesIfNeeded() }` — the same two one-shot-guarded calls `RootContainerView.restoreAndSync()` makes (`AppRootViewModel+Auth.swift:217`, `+Favorites.swift:58`); browse works signed-out either way.
- Compiles on all platforms (it will be built by `swift test` on macOS); only the tvOS shell instantiates it — same posture `RootContainerView`'s header documents for its own `#else` branch.
- Do NOT modify `RootContainerView`'s `#else` tvOS branch — it stays as-is (dead code for now; PR-6+ may consolidate).

---

## 6. CI — `.github/workflows/apple.yml`

Add ONE step after the existing "Build (macOS destination — proves the skeleton)" step:

```yaml
      - name: Build (tvOS Simulator destination — proves the projection shell, #1504)
        working-directory: appApple
        run: |
          xcodebuild \
            -project iHymns.xcodeproj \
            -scheme iHymnsTV \
            -destination 'generic/platform=tvOS Simulator' \
            CODE_SIGNING_ALLOWED=NO \
            build
```
- `generic/platform=tvOS Simulator` needs no named device/runtime on the runner (safer than `name=Apple TV`).
- Scheme note: no shared schemes exist in the repo (`xcshareddata/xcschemes` absent; no `scheme:` in `project.yml`) — the existing `-scheme iHymns` step already relies on xcodebuild's autocreated schemes on the runner, so `-scheme iHymnsTV` will resolve the same way. **Fallback if it doesn't:** add minimal `scheme: {}` blocks to BOTH targets in `project.yml` in the same commit.
- This step IS PR-5's "compile test" (plan §5: "PR-5/6/7 compile+snapshot; add tvOS Simulator build to apple.yml in PR-5"). Snapshot testing: deliberately NOT added (Decision D-9).

Local pre-PR verification (builder runs all): `swift test` in `appApple/Packages/iHymnsKit` (expect existing 522 + new tests green) · `swiftlint --config appApple/.swiftlint.yml appApple` (0 violations) · `bash appApple/Scripts/loc-budget.sh` · `xcodegen generate` + the tvOS xcodebuild above + the existing macOS build.

---

## 7. Test plan (Swift Testing; pure/deterministic; no network, no rendering, no sleeps)

### 7.1 `ProjectionResolverTests.swift`
Fixture maps (constructed directly — no SongDetail needed): `A = [4, 2, 4]`, `B = []`, `C = [3]`, `D = [2, 0, 3]` (defensive zero-line component).

| Assertion group | Cases |
|---|---|
| initialPosition | A → `(0, nil)`; B → `.none` |
| clamped | A `(7, 9)` → `(2, 3)`; A `(-2, -5)` → `(0, 0)`; A `(1, nil)` → `(1, nil)`; B anything → `.none` |
| nextComponent | A `(0,nil)` → `(1,nil)`; A `(2,nil)` → `(2,nil)` unchanged (clamp = full no-op); A `(2,3)` → `(2,3)` unchanged (clamp never resets `lineIndex`); mode-preserve: A `(0,2)` → `(1,0)`; D `(0,0)` → `(1,nil)` (empty target drops line mode) |
| prevComponent | A `(0,3)` → `(0,3)` unchanged (clamp = full no-op); A `(1,nil)` → `(0,nil)`; mode-preserve: A `(2,1)` → `(1,0)` |
| nextLine | A `(0,nil)` → `(0,0)` enter line mode; A `(0,2)` → `(0,3)`; A `(0,3)` → `(1,0)` rollover; A `(2,3)` → `(2,3)` end-of-song clamp; D `(0,1)` → `(2,0)` **skips the empty component**; B → unchanged |
| prevLine | A `(1,0)` → `(0,3)` rollback; A `(0,0)` → `(0,0)`; A `(0,nil)` → unchanged (no-op from component mode); D `(2,0)` → `(0,1)` skips empty |
| jumpLine | A `(0,0)` jump 99 → `(0,3)`; jump -5 → `(0,0)`; B → unchanged; D component 1 → unchanged (zero lines) |
| scroll | A `(0,nil)` +3 → `(0,2)` (enter at 0, then 2 steps); A `(1,0)` −1 → `(0,3)`; delta 0 → unchanged; A `(0,0)` +1000 → `(2,3)` (cap + early-stop, terminates) |
| map derivation | `ProjectionSongMap(detail:)` from (a) the REAL fixture: `ContractFixtures.songDetail()` + `APIClient.decodeSongDetail(from:)` (both already available to `IHFeaturesTests`) — counts equal `detail.orderedComponents.map(\.lines.count)`; (b) a synthetic `SongDetail` with a non-trivial `arrangement` (via `@testable import IHModels` memberwise init — the established idiom, e.g. `SongComparisonEngineTests.swift:30`) proving ARRANGEMENT order is honoured, not stored order |

(Fix the prevComponent row: A `(0,3)` → `(0,0)`? No — prevComponent from component 0 clamps: `(0,3)` → `(0,0)` is WRONG as written; correct expectations: A `(0,nil)` → `(0,nil)` clamp; A `(2,1)` → `(1,0)` mode-preserve. Builder: implement per §3, encode exactly those two.)

### 7.2 `ProjectionViewModelTests.swift` (`@MainActor` suite; fetcher = injected closure over fixture-decoded `SongDetail`s; count fetch calls with the existing `LockedCounter` from `IHAPITestSupport`)
- **selectSong success**: state → `(id, 0, nil, .lyrics)`; returns `.shown`; `song` set; exactly ONE element on `stateUpdates` (collect via a pre-started consumer task + the `waitFor`-style bounded await — mirror `LANRemoteTestSupport.waitFor`'s shape locally; do not import IHLiveTests).
- **gated**: fetcher throws `APIError.unauthorized` → `.unavailable`, state/`song` unchanged, NOTHING published.
- **offline**: fetcher throws `APIError.offline` → `.failed(_)`, state unchanged.
- **reposition without refetch**: second `selectSong(same id, componentIndex: 1)` → fetch count still 1, position `(1, nil)`, one more publish.
- **prepare**: fetch count 1, nothing published, state unchanged; subsequent `selectSong(same id)` → fetch count STILL 1 (cache hit) + publish. Cache eviction: prepare 4 distinct ids → first id evicted (5th fetch on selecting it).
- **latest-wins**: fetcher parks on an `AsyncStream`-gated continuation; fire `selectSong(A)` then `selectSong(B)`; release B then A → final state is B; A's completion returns `.failed` and publishes nothing.
- **relative intents**: after load, `nextLine()` → `(0,0)` published; repeated `nextLine()` to end-of-song → final call publishes NOTHING (dedupe on clamp).
- **displayState**: `.blackout` publish; same-value set → no publish; **frozen semantics** — freeze, then `nextLine()` twice: `projectionState.lineIndex` advanced (canonical moved, publishes happened with `.frozen`), `renderedContent.position` UNCHANGED (snapshot); `setDisplayState(.lyrics)` → `renderedContent` == live position again.
- **setAppearance**: `textScale: 9` → clamped 3.0; `textScale: nil` leaves it; never published.

---

## 8. Risks / decisions (do NOT re-litigate while building; escalate only if a listed assumption breaks)

- **D-1 — IHFeatures has never been CI-compiled for tvOS.** The package declares `.tvOS(.v26)` and `RootContainerView` documents tvOS compilability, but only macOS builds run in CI today. The new tvOS build step WILL surface stragglers (likely suspects: toolbar placements, `.searchable` styling, `onLongPressGesture` overloads in views the browse tabs reach). Fix with minimal `#if os(tvOS)` guards inside the existing shared files — never a forked tvOS copy of a view. If a fix would exceed ~30 lines in one file, stop and flag it in the PR body instead of improvising.
- **D-2 — Line-walk rollover.** `nextLine` at a component's last line rolls into the next component (and skips zero-line components); `prevLine` mirrors; end-of-song clamps; `prevLine` from component mode is a no-op. Chosen for presenter ergonomics ("next always advances"). PR-7's remote UX may prefer pure clamping — if so it changes in ONE place (`ProjectionResolver`), which is the point.
- **D-3 — `.frozen` = render snapshot, canonical keeps moving.** Remotes always see the truth (`displayState: .frozen` in the tuple tells them the screen shows older content); the operator can cue behind the freeze. Do not "reject intents while frozen."
- **D-4 — `IHRPDisplayState` is reused, not re-declared.** It lives in `IHLive/LANRemote` but is semantically "what the TV display shows"; IHFeatures already depends on IHLive; a parallel `ProjectionDisplayState` + mapping would be the exact vocabulary re-fork the plan forbids. The server driver (PR-15) maps the WEB vocabulary (`live/blackout/logo`) into this one at ITS edge — never the other way (per `IHRPPayloads.swift`'s own vocabulary note).
- **D-5 — `.logo` renders the "iHymns" type-mark** in `IHColorTokens.accent` — no shared logo image asset exists in `IHDesign/Resources`, and inventing one is out of scope. File a follow-up if the owner wants the real mark.
- **D-6 — `setAppearance.theme` is stored but unapplied.** The canvas is a fixed black full-bleed (projection surface); `textScale` IS applied. Recorded in the property's doc comment so PR-7 knows the hint lands here.
- **D-7 — canonical publishes only AFTER a successful fetch** (state = what's on screen). A remote that sends `selectSong` for a gated song gets `.unavailable` (→ PR-6 `.contentUnavailable`) and the display doesn't flicker. `.unauthorized` is the only error mapped to `.unavailable` today (content gating is dormant, web rule #28); PR-15 may refine.
- **D-8 — `SongComponentView` is NOT reused for the canvas** (it is a reading view: enrichment long-press, chords, diff params, combined-label a11y for scrolling). The canvas reuses the lower shared seams instead: `ihLyricLineStyle` (the one lyric typography rule), `SongDetail.orderedComponents` (the one arrangement read), `IHColorTokens`/`ihGlassCard`. Adding karaoke-dim/glow parameters to `SongComponentView` was considered and rejected (third orthogonal parameter family on one view; wrong affordance semantics).
- **D-9 — No snapshot-test dependency.** Adding `swift-snapshot-testing` = a new pinned external dep + per-OS reference images on a CI runner we don't control — out of PR-5 scope. The compile gate (tvOS build) + the deterministic resolver/view-model suites are the coverage. Log "snapshot testing for iHymnsKit views" as a `for consideration` issue at PR time.
- **D-10 — Scheme autocreation** (§6): if `-scheme iHymnsTV` fails on the runner, add `scheme: {}` to both app targets in `project.yml` — do not switch to `-target` builds.
- **D-11 — Scope tripwires:** no `TVListenerActor` construction, no Bonjour/local-network entitlements, no pairing UI, no `service_broadcast` mirror, no Top Shelf — all later PRs. Any `import Network` in a file this spec creates is a review-reject.
- **Security notes for the PR body:** no secrets/tokens touched; the LAN link is not touched at all; lyric content crosses only the TV's own authed `APIClient` (gating intact); logging uses `IHLog.remote` with song IDs only (public catalogue identifiers — fine under the §2.4/§2.5 contract: transitions-not-states, no tokens, no payloads).

## 9. Commit plan (one PR, atomic commits)

1. `feat(apple): ProjectionResolver + ProjectionViewModel — driver-agnostic projection engine (#1504)` — resolver + VM + both test files (package-only; `swift test` green).
2. `feat(apple): tvOS ProjectionCanvasView + ProjectionSceneView + TVRootView (#1504)` — views + `IHymnsTVApp.swift` wiring.
3. `ci(apple): add tvOS Simulator build step to apple.yml (#1504)` — plus any `#if os(tvOS)` compile fixes it forced (keep those in this commit so the WHY is visible).
