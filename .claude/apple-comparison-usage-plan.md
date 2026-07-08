# Apple Universal App — Deep Plan: #1445 Song Comparison + #1446 Usage Dashboard

> Deep-planning output (Fable 5, 2026-07-08) for the two deferred #185 sub-scopes.
> Ground truth surveyed: the live `appApple/Packages/iHymnsKit/` tree on `feat/apple-universal`
> (HEAD `e12cd134`), the #1445/#1446 issue bodies, and `.claude/apple-native-strategy.md`.
> NO implementation here — this is the build-ready spec a Sonnet implementer follows.
>
> Shared constraints both features obey:
> - LOC budget: every file ≤ 400 lines (`appApple/Scripts/loc-budget.sh`).
> - `@Observable @MainActor` VMs, injectable stores (`UserDefaults(suiteName:)` test seam),
>   Swift Testing (`@Suite`/`@Test`) in `Tests/IHFeaturesTests/`.
> - Views live in `Sources/IHFeatures/`; pure logic gets its own file + its own test file
>   (precedents: `SongNavigationContext`, `CatalogueNumberQuery`, `LineEnrichmentIndex`).
> - tvOS/watchOS: `IHFeatures` must keep COMPILING for them (it does today), but neither shell
>   surfaces these screens (both still render `PhaseZeroSkeletonView`) — no platform work needed
>   beyond not breaking the build (no iOS-only API outside `#if` guards).

---

# SECTION 1 — #1445 Song comparison view (split/tabbed)

## 1.1 Recommended approach (summary)

**Primary use case: comparing two hymnals' renderings of "the same" hymn** — the counterpart
songs `?action=song_links` (#807) already surfaces on `SongDetailView`'s "Also Appears As" shelf
(`SongLinkGroup`/`SongLinkedSong`, `Sources/IHModels/SongRelations.swift`). That is what the data
genuinely supports: two full `SongDetail` payloads, each with `orderedComponents: [SongComponent]`
(typed verse/chorus/bridge units with `lines: [String]`), metadata (writers/composers/copyright/
tuneName/ccli), and media availability flags. Any-two-songs comparison falls out for free via the
picker (a worship leader may compare two DIFFERENT hymns' wording too) — but counterparts are the
suggested, zero-typing path.

**v1 = parallel presentation with automatic section alignment + a lightweight line-level
"differs" highlight** (toggleable). NOT a word-level inline diff (deferred — see §1.8).

**The key layout insight — alignment IS the scroll-sync.** Instead of two independent
ScrollViews needing a shared scroll-position hack (the issue's flagged "real complexity"),
regular width renders ONE ScrollView of *paired component rows* (verse 1 ↔ verse 1 side by
side, `.top`-aligned). Scrolling is synchronized *by construction*, and section alignment is
automatic. Compact width renders the same pair list one side at a time (segmented A/B +
swipeable pages). No shared scroll state anywhere.

## 1.2 Data & pairing (the pure logic)

New pure type `SongComparisonEngine` (a `Sendable` enum-namespace + value structs, no I/O —
same shape as `SongNavigationContext`):

```swift
public struct ComparisonComponentPair: Sendable, Hashable, Identifiable {
    public let id: Int                     // stable ordinal in the pair list
    public let primary: SongComponent?     // nil => "only in the compared song"
    public let secondary: SongComponent?   // nil => "only in this song"
    public let differingLineIndices: Set<Int>  // line indices (within the PRIMARY component's lines) whose normalised text differs
    public let secondaryOnlyLineIndices: Set<Int> // trailing lines the secondary has beyond the primary's count
}
```

Pairing algorithm (`SongComparisonEngine.pairs(primary:secondary:) -> [ComparisonComponentPair]`):
1. Iterate `primary.orderedComponents` (NEVER `components` — `SongDetail.orderedComponents`
   is the arrangement-aware property every renderer must use, per its own doc comment).
2. Key = `(type.lowercased(), number)`. Match each primary component to the FIRST not-yet-consumed
   secondary component with the same key (consume it). This aligns verse 1 ↔ verse 1, chorus ↔
   chorus, even when the two books order/interleave them differently.
3. Unmatched primary components → pair with `secondary: nil`.
4. Remaining unmatched secondary components are appended AFTER, in the secondary's own order,
   with `primary: nil` ("Only in <B abbr>").
5. Within a both-sides pair, lines are paired **by index** (hymn counterpart verses almost always
   line up positionally; an LCS alignment is deliberate over-engineering for v1). A line differs
   when `normalise(a) != normalise(b)`; primary lines beyond the secondary's count are "differs";
   secondary lines beyond the primary's count go into `secondaryOnlyLineIndices`.

Normalisation (`SongComparisonEngine.normalise(_ line: String) -> String`), pure + unit-tested:
lowercase + diacritic-fold (`folding(options: [.caseInsensitive, .diacriticInsensitive],
locale: nil)`), strip all `CharacterSet.punctuationCharacters ∪ .symbols`, collapse runs of
whitespace to one space, trim. So "Amazing grace! how sweet the sound," ≡ "Amazing grace, how
sweet the sound" — punctuation-only edits don't light up as differences.

**Why the client gets its own compare logic:** the backend's `includes/song_similarity.php`
(CLAUDE.md rule #22) is server-side PHP *similarity scoring* for duplicate DETECTION (levenshtein/
Jaccard/blend) — a different job from rendering a per-line equal/not-equal flag, and not callable
per-line from the client. We are NOT re-forking the similarity maths (no levenshtein, no scoring
— just normalised equality), so rule #22 is not violated.

**Layout selection**, also pure + testable:

```swift
enum ComparisonLayout: Equatable { case sideBySide, paged }
static func layout(horizontalSizeClass: UserInterfaceSizeClass?) -> ComparisonLayout
// .compact => .paged; .regular / nil (macOS has no size class) => .sideBySide
```

(Mirrors `RootContainerView.platformRoot`'s exact `#if os(iOS)` + `horizontalSizeClass` split:
macOS/visionOS are unconditionally side-by-side; iOS switches on the environment value.)

## 1.3 Entry points

**Primary (v1): a "Compare With…" affordance on the song screen**, implemented as a self-contained
`ViewModifier` so `SongDetailView.swift` (378/400 lines — nearly at the LOC tripwire) gains only
~3 lines:

- New `SongCompareEntryModifier` (own file) owns:
  - a `.toolbar { }` block contributing ONE button ("Compare With…", systemImage
    `"rectangle.split.2x1"`) — toolbar contents COMPOSE across modifiers, so
    `SongDetailToolbarContent` is untouched; placement mirrors its `#if os(macOS)
    .primaryAction #else .bottomBar` split. Button enabled only once the primary detail is
    `.loaded` (same posture as `shareURL`'s nil-gating).
  - `.sheet(isPresented:)` presenting `SongComparisonPickerView`.
  - `.navigationDestination(item: $comparisonTarget)` pushing `SongComparisonView` — a real
    push in whatever stack the song screen lives in (works identically in each iPhone tab's
    `NavigationStack` and in the split view's detail column; same reasoning
    `HomeView`/`SongbooksView` use for registering their own `SongID` destinations).
- `SongDetailView` applies it: `.modifier(SongCompareEntryModifier(songId:, counterpartsProvider:,
  rootViewModel:))` where `counterpartsProvider` closes over `viewModel.songLinksState` so the
  picker can show suggestions without a second fetch.

**Suggested candidates inside the picker (reuses existing related/linked data):**
`SongComparisonPickerView` shows, above the search results:
1. "Also Appears As" counterparts (`SongLinkGroup.songs` — the natural "same hymn" set, #807).
2. "Related Songs" (`relatedSongsState` — same-writer matches are plausible compare targets).
Then a searchable full-catalogue list (same local-filter idiom as
`SetlistCatalogueBrowserView.matchingSongs(in:)` — title/abbr/number
`localizedCaseInsensitiveContains` over `rootViewModel.catalogueLoadState`'s loaded
`[SongSummary]`, own local `@State searchText`, NEVER `rootViewModel.searchText` which belongs to
the Search tab). Rows reuse `SongSummaryRow`. Tapping a row sets the comparison target and
dismisses. Do NOT reuse `SetlistCatalogueBrowserView` itself — its `.draggable` payload and
"checkmark for already-added" semantics are setlist-specific; forcing it to host a suggestions
header would contort both callers. (Optional later: extract a shared
`CatalogueQuickFilter` helper if a third local-filter copy ever appears.)

Deferred entry points (§1.8): context-menu "Compare" on the Also-Appears-As shelf rows (the
shelf is shared with Home's Recently Viewed — an optional-callback change touching 3 callers),
and a `compare` deep-link route.

## 1.4 The comparison screen

`SongComparisonView(primaryId: SongID, secondaryId: SongID, rootViewModel: AppRootViewModel)`
— pushed, so it gets a free back button; `.navigationTitle("Compare")`.

**View model** `SongComparisonViewModel` (`@Observable @MainActor`, constructed in the view's
`init` via `@State`, exactly like `SongDetailViewModel`):
- `primaryState: LoadState<SongDetail>`, `secondaryState: LoadState<SongDetail>`.
- `load()` fetches both concurrently (`async let`) through the existing
  `rootViewModel.songDetail(id:)` pass-through (`AppRootViewModel+Catalog.swift:28`).
- Offline fallback per side: on `.offline`/`.maintenance` only, try
  `rootViewModel.savedSongDetail(id)` — copy `SongDetailViewModel.isOfflineLikeFailure`'s
  exact case list and reasoning; track `isServingCachedCopy` per side for the same capsule
  indicator `SongDetailView.offlineCopyBanner` uses.
- `pairs: [ComparisonComponentPair]` — computed from the two `.loaded` details via
  `SongComparisonEngine` (recompute-on-demand like `SongDetailView.enrichmentIndex`; a song is
  a few dozen lines, this is cheap).
- `swapSides()` — flips primary/secondary (pure state swap, no refetch).
- One failure UI rule: if EITHER side errors (after fallback), show one `IHLoadErrorView`
  ("Couldn't Load Comparison") whose retry calls `load()` — never a half-rendered comparison.

**Rendering (both layouts render the SAME `pairs` array):**
- Header row: two title cards (title, songbook name/abbr + number — reuse the
  `Songbook+Display` label conventions and `ihGlassCard()`), each `.accessibilityAddTraits(.isHeader)`.
- **Side-by-side (regular):** one `ScrollView` → `Grid(alignment: .topLeading)` (or
  `LazyVGrid` with two flexible columns); one `GridRow` per `ComparisonComponentPair`; each cell
  renders `SongComponentView` for its side, or an "Only in <other book>" placeholder capsule
  (`.secondary` text, not an error) when that side is `nil`.
- **Paged (compact):** `Picker(.segmented)` bound to `@State selectedSide` + a
  `TabView(selection:)` with `.tabViewStyle(.page(indexDisplayMode: .never))` — tap OR swipe
  switches sides; both panes stay alive so scroll positions survive switching. Each page = one
  `ScrollView` of that side's components in pair order, with "Only in the other version"
  placeholders where its side is `nil` (keeps the two pages positionally comparable).
- Toolbar: "Highlight Differences" toggle (`@AppStorage("ihCompareHighlightDiffs")`, default
  ON — highlighting is the point of the screen; a reader wanting clean parallel text flips it
  off once and it sticks) + "Swap Sides" (`arrow.left.arrow.right`) calling `swapSides()`.
- Text size: read the SAME `@AppStorage("ihLyricsTextScale")` key `SongDetailView` persists, so
  the user's reading-size choice carries into the comparison automatically. Chords: always
  hidden in v1 (`showChords: false`) — chord layers add horizontal noise that fights a
  side-by-side wording comparison (state this in the file header).

**Diff rendering — extend `SongComponentView`, don't fork it** (repo modularity rule; the file
is 108 lines, with room). Two ADDITIVE, defaulted parameters so all existing call sites compile
unchanged:
- `highlightedLineIndices: Set<Int> = []` — a highlighted line gets (a) a background tint
  `IHColorTokens.accent.opacity(0.12)` behind the line (rounded rect), (b) a leading
  `Image(systemName: "asterisk")`-style marker (small, `.accent`), and (c) an appended
  VoiceOver suffix. NOT colour-only: marker glyph + a11y text satisfy WCAG 1.4.1.
- `accessibilityLabelPrefix: String? = nil` — the comparison passes e.g. `"Mission Praise
  version"` so each combined component element announces WHICH side it is ("Mission Praise
  version. Verse 1. Amazing grace…"). Existing callers pass nothing → behaviour identical.
The per-line diff suffix folds into the component's combined accessibility element as
"…, N lines differ from the compared version" (the view already uses
`.accessibilityElement(children: .combine)` — keep that; per-line VO stops would be noisy).

## 1.5 New files / changed files (#1445)

New (all in `appApple/Packages/iHymnsKit/`):
| File | Contents | Est. lines |
|---|---|---|
| `Sources/IHFeatures/SongComparisonEngine.swift` | `ComparisonComponentPair`, `pairs(primary:secondary:)`, `normalise(_:)`, `ComparisonLayout.layout(horizontalSizeClass:)` | ~180 |
| `Sources/IHFeatures/SongComparisonViewModel.swift` | dual LoadStates, concurrent load + per-side offline fallback, `pairs`, `swapSides()` | ~160 |
| `Sources/IHFeatures/SongComparisonView.swift` | adaptive side-by-side/paged rendering, header cards, toolbar toggles | ~280 |
| `Sources/IHFeatures/SongComparisonPickerView.swift` | suggestions (counterparts/related) + searchable catalogue list | ~170 |
| `Sources/IHFeatures/SongCompareEntryModifier.swift` | toolbar button + picker sheet + `navigationDestination(item:)` | ~110 |
| `Tests/IHFeaturesTests/SongComparisonEngineTests.swift` | see §1.6 | ~220 |
| `Tests/IHFeaturesTests/SongComparisonViewModelTests.swift` | see §1.6 | ~150 |

Modified:
- `Sources/IHFeatures/SongComponentView.swift` — the two defaulted params above (+~30 lines →
  ~140, well under budget).
- `Sources/IHFeatures/SongDetailView.swift` — apply the modifier (+~5 lines; 378→~383, still
  under 400 — if annotation pushes it over, extract the `private extension View` Handoff helper
  at the bottom of that file into `SongDetailView+Handoff.swift` first, a pure move).
- OPTIONAL, one line each: a `compareOpened` factory on `IHAnalyticsEvent` + a
  `IHAnalyticsService().screenViewed("Comparison")` in `SongComparisonView.onAppear` —
  consent-gated automatically by the existing choke point. (Keep the event vocabulary closed:
  a new event = a new static factory in `IHAnalyticsEvent.swift`, never a free-form name.)

## 1.6 Test plan (#1445)

`SongComparisonEngineTests` (pure, fixture-friendly — build `SongComponent`s inline; optionally
decode `Tests/Fixtures/song_detail.json` via the existing `ContractFixtures` helper for a
realistic primary):
- pairing: identical structures pair 1:1 in order; verse-count mismatch (A has v1–v4, B has
  v1–v3 + v5) → v4 pairs `secondary: nil`, v5 appended `primary: nil`; chorus matched by
  `(type, number)` even when B stores it at a different position; `arrangement` respected
  (feed a `SongDetail` whose `arrangement` reorders components → pairs follow
  `orderedComponents`, not `components`); case-insensitive type match ("Chorus" vs "chorus").
- normalisation: punctuation-only difference → equal; diacritic difference → equal; real word
  change → differs; whitespace collapse.
- line diff: index-paired differing lines land in `differingLineIndices`; longer secondary →
  `secondaryOnlyLineIndices`; empty `lines` arrays don't crash.
- layout: `.compact → .paged`, `.regular → .sideBySide`, `nil → .sideBySide`.

`SongComparisonViewModelTests` (same seams `SongDetailViewModelOfflineTests` already uses —
an `AppRootViewModel` built with a stubbed `APIClient`/in-memory `OfflineStore`):
- both loads succeed → both `.loaded`, `pairs` non-empty;
- one side `.offline` with a saved copy → served from cache, `isServingCachedCopy` true for
  that side only; one side `.offline` with NO saved copy → single error state;
- `.decoding`/`.unauthorized` never falls back to cache (mirror the SongDetailViewModel case list);
- `swapSides()` flips pairs' orientation without refetching (assert stub call count).

## 1.7 Accessibility plan (#1445)

- **VoiceOver navigation of two panes:** each component cell is ONE combined element whose label
  leads with the side ("Hymns of Praise version. Verse 1. …") via `accessibilityLabelPrefix` —
  in the side-by-side grid the reading order alternates A/B per section (A-v1, B-v1, A-v2 …),
  which IS the comparison order a VO user wants. Header cards are `.isHeader` so the rotor jumps
  between the two song titles. In paged mode the segmented control announces the selected side
  normally.
- **Differences are never colour-only:** tint + marker glyph + spoken "…lines differ…" suffix.
- **Dynamic Type / text scale:** all lyric text goes through `ihLyricLineStyle` (which already
  honours `\.ihReadingMode` dyslexia mode and `textScale`); the side-by-side grid must survive
  accessibility sizes — cells are `.topLeading`-aligned and wrap naturally; on iPhone the layout
  is paged anyway, so extreme sizes never squeeze two columns onto a compact screen.
- Reduce Motion: the `TabView` page switch is system behaviour; no custom animation added.
- The "Only in <book>" placeholder is plain text, announced as such.

## 1.8 Deferred / owner decisions (#1445) — defaults chosen, proceed unless owner objects

1. **Diff highlight default ON** (toggleable, persisted). Default unless owner objects.
2. **Free-scrolling synchronized panes**: NOT in v1 — the paired-grid design makes it moot for
   the aligned reading; an unpaired "independent panes + scroll sync" mode is a separate issue
   if ever requested (the original issue itself scoped sync out of v1).
3. **Word-level inline diff**: deferred — high effort, questionable a11y, low marginal value over
   line-level. Would be a new engine mode, not a rewrite.
4. **Context-menu "Compare" on shelf rows / a `/compare` deep link**: deferred follow-ups.
5. **Chords hidden in comparison**: default unless owner objects.

---

# SECTION 2 — #1446 Usage statistics dashboard

## 2.1 The pivotal decision: LOCAL-ONLY. Recommended firmly.

- **#189's analytics pipeline transmits nothing**: `IHAnalyticsSink`'s only conformer is
  `IHAnalyticsLogSink` (a `#if DEBUG` `os.Logger` line; a true no-op in Release). The backend
  ingestion endpoint is #1448, still pending. There is literally no server data to build on.
- **The privacy posture forbids pretending otherwise**: `Apps/iHymns/Sources/PrivacyInfo.xcprivacy`
  (#1449) declares `NSPrivacyCollectedDataTypes = []` ("genuinely empty, not an oversight") and
  `NSPrivacyTracking = false`. A local-only dashboard keeps that TRUE.
- **Consent is not required to show a user their own on-device data**: the
  `analyticsConsentEnabled` toggle gates *telemetry* (data leaving the device). The dashboard
  must therefore NOT read `IHAnalyticsService.recordedEvents` (consent-gated, process-local,
  capped at 500, wrong semantics) and must NOT be gated on the consent toggle. It reads its own
  dedicated local store.
- Works offline, zero backend dependency, honest limitation (stated in-UI): resets if you
  delete the app; not synced across devices.
- **Phase 2 (explicitly #1448-dependent, not planned here):** cross-device/all-time stats would
  require the first-party ingestion endpoint + an authed "my stats" read API + a privacy-label
  update (`NSPrivacyCollectedDataTypeProductInteraction`, not-linked, consent-gated) — its own
  epic, exactly as the issue's "v2" warns.

## 2.2 What stats (honest set the data actually supports)

Existing substrate: `RecentlyViewedStore` (last 12 songs + `viewedAt` — too small a window for
weekly/monthly counts, per the issue's own note), `RecentSearchesStore` (last 10 queries),
`AppRootViewModel.favorites`/`.setlists` (offline-first mirrors), `OfflineStore`
(`savedSongCount()`, `totalSavedSongBytes()`, `totalCachedMediaBytes()`).

One NEW lightweight local counter (§2.3) unlocks the time-based stats. The v1 stat set:

| Stat | Source |
|---|---|
| Songs read this week / this month (opens, deduped per song per day) | `UsageActivityStore.dailyOpens` |
| Reading streak (consecutive days incl. today or ending yesterday) | derived from `dailyOpens` day keys |
| Most-viewed songs (top 5, tappable → `SongDetailView`) | `UsageActivityStore.songCounters` |
| Songbooks explored (distinct count) | distinct `songbookAbbreviation` over `songCounters` |
| Favourites count | `rootViewModel.favorites.count` |
| Setlists count | `rootViewModel.setlists.count` |
| Saved offline (count + total size incl. media) | `OfflineStore` aggregates via existing pass-throughs |
| Recent searches (list, with the existing Clear) | `rootViewModel.recentSearches` |

Do NOT invent: "minutes read" (no dwell tracking exists), "most-viewed songbook by time",
per-week charts of historical months (the store starts empty at feature launch — the UI copy
must not imply back-history).

## 2.3 Storage: a `UserDefaults`-backed `UsageActivityStore` (NOT a GRDB table)

Recommended the lighter option, deliberately:
- Exact `RecentlyViewedStore` idiom — injectable `UserDefaults`, JSON-`Data` payload,
  `@unchecked Sendable`, defensive decode-to-empty. Zero migration machinery, zero actor hops,
  an established test seam, and **no PrivacyInfo change** (the manifest already declares
  `NSPrivacyAccessedAPICategoryUserDefaults` reason CA92.1 citing exactly these stores — add
  this store to that manifest comment's call-site list in the same commit).
- A GRDB table (additive `v7` migration + `OfflineStore` actor methods + pass-throughs) buys
  nothing at this size: the whole payload is a few KB of counters. If a Phase-2 sync ever needs
  relational history, THAT migration designs the final DDL then (one-pass rule #20 applies to
  the shared MySQL, not this client cache).

Shape (one struct, one key `"ihUsageActivity"`):

```swift
public struct UsageActivityStore: @unchecked Sendable {
    init(defaults: UserDefaults = .standard, key: String = "ihUsageActivity",
         maxDays: Int = 366, maxSongCounters: Int = 300)
    // payload (private Codable):
    //   dailyOpens: [String: Int]        // "YYYY-MM-DD" (UTC-stable key via a fixed formatter) → distinct songs opened
    //   songCounters: [SongOpenCounter]  // songId, title, songbookAbbreviation, number, openCount, lastOpenedAt
    public func recordSongOpen(songId:title:songbookAbbreviation:number:date:) // date injectable for tests
    public func snapshot() -> UsageActivitySnapshot   // the read model the calculator consumes
    public func clear()
}
```

Pruning on every write: day entries older than `maxDays` dropped; `songCounters` capped at
`maxSongCounters` by evicting the lowest-`openCount` (oldest `lastOpenedAt` tie-break) — the
"most viewed" list is honestly documented as an approximation over the retained set (top-5 is
unaffected in practice). Per-day dedup: `recordSongOpen` counts a song at most once per day key
toward `dailyOpens` (re-opening the same hymn five times in one sitting isn't "5 songs read"),
while `songCounters.openCount` increments every open (that's what "most viewed" means).

**The ONE write hook:** inside `AppRootViewModel.recordRecentlyViewed(_ detail: SongDetail)` —
already the single choke point invoked ONLY on a successful primary load (network AND
offline-fallback paths, see `SongDetailViewModel.loadPrimaryDetail()`). No view wiring, and the
stats agree with Recently Viewed semantics by construction.

**LOC-budget manoeuvre (required):** `AppRootViewModel.swift` is at 397/400. Task order:
first MOVE `recordRecentlyViewed(_:)` (~15 lines at line 343) into a new
`Sources/IHFeatures/AppRootViewModel+Activity.swift` extension (pure move — precedent:
`OfflineStore+Favorites.swift`'s "#1450 moved out purely for the LOC tripwire"), then add the
two stored bits to the main file: `let usageActivityStore: UsageActivityStore` (internal, for
the extension file) + an `usageActivityStore: UsageActivityStore = UsageActivityStore()`
init parameter (defaulted — every existing call site, incl. `makeLive`, compiles unchanged).
Net main-file delta ≈ −5 lines.

## 2.4 Where it lives

**Settings → a "Your Activity" row** pushing `UsageStatsView` — the exact `storageSection`
"one row, real content on the pushed screen" idiom in `SettingsView.swift`, placed between
Storage & Offline and Privacy. Rationale over a sidebar/tab section: `RootContainerView`
deliberately caps the compact `TabView` at 7 tabs ("rather than overflowing into a system
'More' tab" — its own #182 header); an 8th `RootSection` would trigger exactly that overflow.
It's the user's own data about their own account/device — Settings is where iOS users look for
that. A Home stat-strip card is a cheap later addition (§2.8) once the store has data.

`UsageStatsView` registers its own `.navigationDestination(for: SongID.self)` (HomeView
precedent — every hosting stack registers its own) so most-viewed rows push `SongDetailView`.

## 2.5 New files / changed files (#1446)

New:
| File | Contents | Est. lines |
|---|---|---|
| `Sources/IHFeatures/UsageActivityStore.swift` | store + `SongOpenCounter` + `UsageActivitySnapshot` + pruning | ~180 |
| `Sources/IHFeatures/UsageStatsCalculator.swift` | PURE aggregation: `songsRead(inLastDays:from:now:calendar:)`, `currentStreak(dayKeys:today:)`, `topSongs(_:limit:)`, `songbooksExplored(_:)` — injectable `Calendar`/today | ~130 |
| `Sources/IHFeatures/UsageStatsViewModel.swift` | `@Observable @MainActor`; snapshots the store on `load()`, pulls offline counts via existing `rootViewModel` pass-throughs (`savedSongCount` etc. — add thin pass-throughs in `AppRootViewModel+Offline.swift` if any are missing), reads `favorites`/`setlists`/`recentSearches` live | ~150 |
| `Sources/IHFeatures/UsageStatsView.swift` | 2-column `LazyVGrid` of stat tiles (`ihGlassCard()`), streak tile, most-viewed list rows, recent-searches section, "Reset Activity Data" (confirmationDialog) + the honesty footer ("Stored only on this device. Cleared if you delete the app. Never sent anywhere.") | ~280 |
| `Sources/IHFeatures/AppRootViewModel+Activity.swift` | moved `recordRecentlyViewed(_:)` + the one new `usageActivityStore.recordSongOpen(...)` line | ~60 |
| `Tests/IHFeaturesTests/UsageActivityStoreTests.swift` | see §2.6 | ~180 |
| `Tests/IHFeaturesTests/UsageStatsCalculatorTests.swift` | see §2.6 | ~160 |

Modified:
- `Sources/IHFeatures/AppRootViewModel.swift` — remove `recordRecentlyViewed`, add store
  property + defaulted init param (net ≈ −5 lines; MUST stay ≤ 400).
- `Sources/IHFeatures/SettingsView.swift` — `yourActivitySection` row (+~12 lines → ~277).
- `Apps/iHymns/Sources/PrivacyInfo.xcprivacy` — comment-only: add `UsageActivityStore.swift`
  to the CA92.1 call-site list (no key/value changes — see §2.7).
- OPTIONAL: `IHAnalyticsService().screenViewed("YourActivity")` on appear (consent-gated,
  consistent with #189's screen-view convention).

## 2.6 Test plan (#1446)

`UsageActivityStoreTests` (throwaway `UserDefaults(suiteName:)`, injected dates —
`RecentlyViewedStoreTests`' exact shape):
- starts empty; recording increments `openCount` and dedupes `dailyOpens` per song per day;
  two songs same day → `dailyOpens[day] == 2`; same song twice → `== 1` but `openCount == 2`;
- pruning: entries older than `maxDays` dropped on write; counter cap evicts lowest-count;
- malformed stored blob decodes to empty (corrupt-data posture);
- `clear()` empties everything;
- day-key stability: the key formatter is fixed-locale (`en_US_POSIX`) so a device-locale
  change never splits one day into two keys.

`UsageStatsCalculatorTests` (pure, injected `Calendar`/today):
- streak: empty → 0; today only → 1; today+yesterday → 2; gap breaks it; streak that ended
  YESTERDAY (nothing yet today) still reports (grace rule: count as current — the "don't punish
  the user at 7 a.m." convention; assert it explicitly);
- window counts: exactly-7-days boundary in/out; month window;
- topSongs ordering + tie-break; songbooksExplored distinct count.

`UsageStatsViewModel` gets a thin test only if the calculator/store tests leave gaps (it's
mostly glue; don't test SwiftUI).

## 2.7 Privacy confirmation (#1449 consistency)

Local-only display of the user's own on-device data is NOT "collection" under Apple's privacy
definitions — data is "collected" when transmitted off-device
(`NSPrivacyCollectedDataTypes` documents *transmitted* data). `UsageActivityStore` writes to
`UserDefaults` on-device, is never read by any sink/network path, and the app's manifest keeps
`NSPrivacyTracking=false`, `NSPrivacyCollectedDataTypes=[]` — both remain accurate with zero
edits. The manifest's OWN maintenance rule (its header: reflect reality, update comments when
call sites change) requires only adding the new store to the CA92.1 call-site *comment*. The
"Reset Activity Data" affordance + the honesty footer keep the feature self-evidently
first-party. If Phase 2 (#1448 sync) ever ships, THAT work adds the
`ProductInteraction` collected-data entry — not this one.

## 2.8 Deferred / owner decisions (#1446) — defaults chosen, proceed unless owner objects

1. **Local-only v1** (the issue's own "Decision pending"): recommended YES as specced; phase-2
   server sync stays gated on #1448. Default unless owner objects.
2. **Home surface**: no Home card in v1 (Home is already SOTD+resume+shelf; a stats card with
   week-one zeros is noise). Revisit after the store has real data. Default: Settings-only.
3. **Retention/caps**: 366 days / 300 song counters. Defaults unless owner objects.
4. **Streak grace rule** (streak ending yesterday still shows): default YES.
5. **No Swift Charts in v1** (plain stat tiles; a 7-day bar chart is a P2 nicety).

---

# Build order (one issue, one commit each — repo convention)

#1445: (1) `SongComparisonEngine` + tests → (2) `SongComponentView` additive params →
(3) `SongComparisonViewModel` + tests → (4) `SongComparisonView` → (5) picker →
(6) entry modifier + `SongDetailView` wiring → (7) a11y audit pass (VoiceOver labels, Dynamic
Type at accessibility sizes, diff-not-colour-only) + `loc-budget.sh` + SwiftLint.

#1446: (1) `UsageActivityStore` + tests → (2) `UsageStatsCalculator` + tests →
(3) `AppRootViewModel+Activity.swift` move + hook (budget check!) → (4) VM → (5) view +
Settings row → (6) PrivacyInfo comment + honesty copy review → (7) a11y + gates.

Both features are pure-client, no backend asks, no new entitlements, no new dependencies.
