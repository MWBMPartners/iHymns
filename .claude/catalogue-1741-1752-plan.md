# #1752 — Native apps (Apple + Android) surface the #1741 catalogue expansion — build spec

**Status:** ready to implement (Sonnet-executable, no re-deciding needed) — EXCEPT the three
§9 owner flags, all non-blocking, defaults taken.
**Branch:** `claude/wave3-fixes`. **Parent epic:** #1741 (dev-complete on web, `b57fe87c`).
**Depends on:** **#1750** — `.claude/catalogue-1741-1750-plan.md` **§4 IS the payload contract
this spec codes against** (the five always-present camelCase keys `subtitle` /
`disambiguation` / `firstPublishedYear` / `copyrightYears` / `copyrightHolder` on
`song_detail`/`song_data`/`songbook_export`, plus the opt-in `include=externalIds` block).
Do not rename any of those keys there without re-opening this issue. **Land order is
flexible**: every native decode below is absent-tolerant (`decodeIfPresent` / Kotlin
defaults), so these slices compile and ship safely against a pre-#1750 server — but the
on-device render verification (§8.4) requires #1750 live on dev first.

---

## §0 Verified current state (every claim re-checked 2026-08-03 against the tree; the issue's "web-only program gap" framing is PARTLY STALE — corrections marked ⚠️)

### §0.1 Apple (`appApple/Packages/iHymnsKit` — the shared SwiftPM package; per-target shells only re-export it, modularity rule honoured)

| Surface | State | Evidence |
|---|---|---|
| ⚠️ Musician profile screen | **EXISTS** (issue implies missing) | `Sources/IHFeatures/CreditPersonDetailView.swift` + `CreditPersonDetailViewModel.swift`; model `IHModels/CreditPerson.swift` (#1443/#1444). Fetches via the **legacy** `action=credit_person` (envelope `person`) — `IHAPI/WorkAndCreditPersonEndpoints.swift:73-77`. That action is frozen-byte-identical forever server-side (`api.php:1511-1512` comment), so nothing is broken — but the client never sees the new-envelope `musician` action and the JSON it consumes carries **no identifiers** (§0.3.6). |
| ⚠️ Work screen | **EXISTS** | `IHFeatures/WorkDetailView.swift` (+ViewModel), model `IHModels/Work.swift`, endpoint `?action=work` (`WorkAndCreditPersonEndpoints.swift:29`). Decodes the pre-P4b shape only — the nine #1741 P4b keys `getWork()` now always emits (`includes/SongData.php:5044-5052`: `ccli`/`bowi`/`subtitle`/`disambiguation`/`tuneName`/`tuneId`/`firstPublishedYear`/`copyrightYears`/`copyrightHolder`) are silently ignored (unknown JSON keys don't throw), so they decode to nothing and render nowhere. |
| Song identity fields | **MISSING** (matches issue) | `IHModels/SongDetail.swift` decodes ~28 props incl. `tuneName`/`iswc`/`ccli`/`copyright` (lines 69-80) but none of the five P1 keys and no `externalIds`. |
| ⚠️ Identifier display on song | **PARTIAL, better than issue implies** | `IHFeatures/SongMetadataView.swift:131-168` already renders CCLI + ISWC + `royaltyIds` behind a "Song Identifiers" disclosure. **But `copyright` and `tuneName` are decoded and rendered NOWHERE** — `grep -rn copyright Sources/IHFeatures/` hits no view; `tuneName` appears only in the model. The native song page shows no copyright line at all today. |
| Tune screen | **MISSING** | No file references a tune beyond `SongDetail.tuneName`. Also blocked server-side: §0.3.5. |
| Deep links — `/work/`, `/person/` | **EXIST** | `IHAppSupport/DeepLink.swift:114-120` (`.work(slug:)`, `.person(slug:)`), destinations wired in `IHFeatures/DeepLinkDestinationView.swift` (#1443). |
| ⚠️ Deep link — `/musician/` (the NEW canonical path, #1741 P2-B) | **MISSING and live-reachable** | AASA claims `/musician/*` (`appWeb/public_html/.well-known/apple-app-site-association.php:137`) → iOS hands the app the tap → `DeepLinkRouter.resolveTwoSegments()` (`DeepLink.swift:208-229`) has no `"musician"` case → `nil` → the shell's unresolved-fallback bounces the user OUT to Safari. Every share/sitemap link the web now mints uses `/musician/` (`index.php:493,513,522`), so this is the one deep-link gap users actually hit. |
| Deep links — `/tune/`, `/isrc/ /iswc/ /ccli/ /ipi/ /isni/ /bowi/` | **MISSING but UNREACHABLE — issue framing stale** ⚠️ | The AASA `components` list (`apple-app-site-association.php:122-140`) claims **neither** `/tune/*` nor any of the six alias routes. Apple's OS never hands an unclaimed path to the app, so native handling would be dead code today (the exact situation `DeepLink.swift`'s header documents for `/live/*`). Adding them needs a WEB change (AASA) + a native destination — deferred, §5.2/§5.3. |

### §0.2 Android (`appAndroid/` — single `app` module, Phase-1 offline skeleton)

| Surface | State | Evidence |
|---|---|---|
| Everything in this issue's scope | **MISSING — and mostly BLOCKED** | 18 files total. Model `models/Song.kt:146-159` = 12 fields (no tuneName/iswc/tags/links/works/media, let alone the P1 five). Data source = a **bundled `songs.json` asset** (`viewmodel/SongViewModel.kt:66`) that is not even in the repo (`app/src/main/assets/` absent — supplied at build time). **No network client exists at all** (manifest comment: "Phase 1 operates entirely offline", `AndroidManifest.xml:24-26`). |
| Deep links | **MISSING + externally blocked** | No `VIEW` intent-filter anywhere in `AndroidManifest.xml` (only MAIN/LAUNCHER + LEANBACK, lines 102-111); the served `assetlinks.json` fingerprint is the literal placeholder `TODO:REPLACE_WITH_ACTUAL_SHA256_FINGERPRINT_WHEN_APP_IS_PUBLISHED` (`appWeb/public_html/.well-known/assetlinks.json`) — App Links cannot verify until the app has a signing cert. |
| ⚠️ The "just regenerate the bundled JSON with new fields" idea | **DEAD END — do not spec it** | The generator `tools/parse-songs.js` parses raw **text files** from `.SourceSongData/` (lines 10-34) — a deprecated local artefact path (#1617) whose source files never contained the #1741 identity fields; those exist only in MySQL, entered by curators through the editor (P5a). No bundled-JSON regeneration can ever carry this data. The only honest Android data path is the Phase-2 API client, which is its own program (§9.1). |

### §0.3 Web/API facts the native work keys off (all verified live-source)

1. **The #1750 §4 contract** (five always-present keys + display-precedence rule + opt-in `include=externalIds` with rows `{idScope, idType, idValue, source}`) — see that file; spellings are byte-identical to the editor's `ED2_META_FIELDS` (`manage/editor/api2.php:410-414`).
2. `?action=work` emits the nine P4b identity keys **today, always-present** (empty-string/null defaults on un-migrated installs) — `SongData.php:5040-5052`. Native can decode them NOW, independent of #1750.
3. `?action=musician` (canonical, envelope `musician`) and `?action=credit_person` (legacy, envelope `person`, frozen) both serve `SongData::getMusician()` — `api.php:1511-1553`.
4. `getMusician()` JSON does **NOT** emit the P4a musician enrichment: no `tblMusicianIdentifiers` rows (IPI/ISNI), no type/flags — verified by reading the full function (`SongData.php:5294-5495`; only id/slug/name/notes/lifespan/isSpecialCase/isGroup/discography/links/totalSongs). The web musician **page** renders richer data than the JSON action. Native "musician identifier display" therefore needs the small additive web change in §4.
5. There is **NO JSON tune action** — `case 'tune'` exists only in the fragment-page router (`api.php:736`), not the JSON action switch. A native Tune screen is blocked on adding one (§5.2).
6. JSON identifier lookups DO exist for a future alias-route/native search feature: `song_by_identifier` (iswc|isrc|upc|ccli → songs, `api.php:1290`) and `musician_by_identifier`/legacy `person_by_identifier` (ipi|isni → musicians, `api.php:1334-1335`).
7. Toolchain reality **in this sandbox**: `which swift` → nothing; `ANDROID_HOME` unset, no SDK dir (gradle + java alone are not enough). **Neither native platform can compile here.** §8 states what that means honestly.

---

## §1 Slice A — Apple: decode + render the five song identity keys + `externalIds` (THE smallest high-value slice; do this first)

All new/changed code carries the two-register annotation standard (ELI5 + detailed why + `#1752`, linking #1750 §4 as the contract source).

### §1.1 `Sources/IHModels/SongDetail.swift` — model additions

Append after `royaltyIds` (line 180), before `CodingKeys`:

- `public let subtitle: String?`
- `public let disambiguation: String?`
- `public let firstPublishedYear: Int?`
- `public let copyrightYears: String?`
- `public let copyrightHolder: String?`
- `public let externalIds: [SongExternalId]?`

Add all six to `CodingKeys` (plain names — wire keys are identical). **They MUST be
`Optional`** (decision taken — do not "improve" to non-optional to match the server's
always-present contract): synthesized `Decodable` uses `decodeIfPresent` for Optionals, and
two real decoders will see the keys ABSENT — (a) any pre-#1750 docroot (three docroots, one
DB, staggered deploys), and (b) **every already-persisted offline copy**:
`IHPersistence/CachedSongDetail.swift:86` re-decodes stored `detailData` blobs with
`try? JSONDecoder().decode(SongDetail.self, ...)` — a required key would silently nuke every
user's saved-for-offline library on first launch after update. Note that in the doc comment.

Add the ONE display-precedence fold (rule #22 — web and native must render identically;
contract text in #1750 §4.1), as a computed property on `SongDetail`:

```swift
/// The copyright line to display: the #1741 split (years + holder) when either
/// half is present, else the legacy free-text `copyright`. Never both. (#1750 §4 contract.)
public var copyrightDisplay: String {
    let split = [copyrightYears ?? "", copyrightHolder ?? ""]
        .map { $0.trimmingCharacters(in: .whitespaces) }
        .filter { !$0.isEmpty }
        .joined(separator: " ")
    return split.isEmpty ? copyright.trimmingCharacters(in: .whitespaces) : split
}
```

New type in the same file (below `SongTranslationRef`, mirroring its sibling-DTO placement):

```swift
/// One recording/release external id (#1741 D5, `tblSongExternalIds`) — opt-in via
/// `include=externalIds` (#1750 §4.3). `idScope`/`idType` are open vocabularies
/// (same non-enum reasoning as `SongMediaAsset.kind`); `SourceRef` is deliberately
/// never on the wire.
public struct SongExternalId: Sendable, Hashable, Codable {
    public let idScope: String
    public let idType: String
    public let idValue: String
    public let source: String
}
```

### §1.2 `Sources/IHAPI/APIClient.swift:298` — request the block

`static let songDetailIncludeBlocks = ["translations", "annotations", "royaltyIds", "externalIds"]`
(one-line change; the doc comment above it already explains the harmless-when-absent semantics).

### §1.3 `Sources/IHFeatures/SongDetailView.swift` — header render

In `header(for:)` (line 277), mirror web `song.php` §2.2/§2.3 of the #1750 plan:

- Disambiguation: appended to the title line as secondary text —
  after the `Text(detail.title)` line add
  `if let d = detail.disambiguation, !d.isEmpty { Text("(\(d))").font(.title3).foregroundStyle(.secondary) }`
  (own line under the large title is fine; SwiftUI has no `<small>`-in-`<h1>` equivalent worth forcing).
- Subtitle: directly below, `if let s = detail.subtitle, !s.isEmpty { Text(s).font(.subheadline).foregroundStyle(.secondary) }`
  — ABOVE the existing number/songbook `HStack`.

### §1.4 `Sources/IHFeatures/SongMetadataView.swift` — rights line + identifiers

- New `rightsSection` inserted in `body` between `creditsSection` and
  `authorityIdsDisclosure` (line 40-41): renders, each gated on non-empty —
  `"First published \(year)"` (`detail.firstPublishedYear`), then
  `"© \(detail.copyrightDisplay)"` when `copyrightDisplay` non-empty **unless** both
  `lyricsPublicDomain && musicPublicDomain` (mirror web's PD-wins-over-© branch,
  `song.php` footer logic) — in that case render `"Public Domain"`. `.font(.caption)
  .foregroundStyle(.secondary)`. This is ALSO the first time native shows copyright at all
  (§0.1) — say so in the commit body.
- In `authorityIdsDisclosure`'s presence check (line 133) add `|| !(detail.externalIds ?? []).isEmpty`;
  in `authorityIdsContent` (line 154) append after the royaltyIds `ForEach`:
  `ForEach` over `detail.externalIds ?? []` rendering
  `Text("\(id.idType.uppercased()): \(id.idValue)")` — ISRC and friends land here, matching
  the "behind a disclosure" treatment CCLI/ISWC already get.

### §1.5 Tests (`Tests/IHModelsTests/SongDetailTests.swift` + `Tests/Fixtures/song_detail.json`)

- Hand-edit the fixture: add the five keys + an `externalIds` array with one ISRC row to the
  song object (the refresh script `tools/apple-refresh-fixtures.sh` needs network + a
  post-#1750 dev — note in the PR that a real refresh should follow).
- New assertions: the five decode with correct values/types; `copyrightDisplay` precedence
  (three cases: split-only, legacy-only, both→split); **absent-tolerance** — decode a copy of
  the fixture with all six keys stripped → all `nil`, no throw (this is the CachedSongDetail
  back-compat guarantee, name it in the test comment).

**Effort: ~0.5-1 day.**

---

## §2 Slice B — Apple: `/musician/` deep link + canonical share URL (~1-2 h)

### §2.1 `Sources/IHAppSupport/DeepLink.swift:222` — accept the canonical path

Change `case "person":` in `resolveTwoSegments` to `case "person", "musician":` (same
`.person(slug:)` result — the web serves ONE page for both, `api.php:689-698` normalises;
a new enum case would just double-wire `DeepLinkDestinationView` for zero behaviour). Update
the file-header `/person/*` note: the 2026-08 P2-B rename made `/musician/` canonical; both
resolve here; slug charset unchanged (`personSlugPattern` reused).

### §2.2 `Sources/IHAppSupport/CanonicalURL.swift:103` — emit the canonical path

`person(slug:)` → `URL(string: "\(host)/musician/\(slug)")`. Default taken (trivially
reversible): the web 301s `/person/`→`/musician/` anyway (`index.php:512-513`), AASA claims
both, and share links should mint the canonical form. Update its doc comment (it currently
argues FOR `/person/` — that argument predates the P2-B rename).

### §2.3 Tests

`Tests/IHAppSupportTests/DeepLinkRouterTests.swift`: `/musician/<slug>` → `.person(slug:)`;
`/musician/` bad-charset → nil. `CanonicalURLTests.swift`: round-trip
`CanonicalURL.person(slug:)` → resolver → `.person(slug:)` (the emitted path is now
`/musician/` — assert the string too, so a silent revert goes red).

---

## §3 Slice C — Apple: Work identity fields (~0.5 day)

### §3.1 `Sources/IHModels/Work.swift`

`Work` already has a hand-written `init(from:)` (lines 153-167) — extend it (and the property
list + `CodingKeys`) with the nine P4b keys, all via `decodeIfPresent` with the server's own
defaults (`?? ""` strings / `Int?` nil): `ccli`, `bowi`, `subtitle`, `disambiguation`,
`tuneName`, `tuneId: Int?`, `firstPublishedYear: Int?`, `copyrightYears`, `copyrightHolder`.
Absent-tolerance matters here too: the **embedded** `song_detail.works[]` shape
(`_worksMap()`) does not emit these keys — only the standalone `getWork()` does — the exact
split the file's #1443 header already documents for `children`.

Do NOT duplicate the copyright fold: add `public var copyrightDisplay: String` on `Work`
delegating to a small shared free function — extract the §1.1 body into
`Sources/IHModels/CopyrightDisplay.swift` (`func ihCopyrightDisplay(years:holder:legacy:) -> String`)
and have BOTH `SongDetail.copyrightDisplay` and `Work.copyrightDisplay` call it (rule #22:
one fold; `Work.notes` is not a legacy-copyright source — pass `legacy: ""` there).

### §3.2 `Sources/IHFeatures/WorkDetailView.swift`

In `header(for:)` (line 113): disambiguation + subtitle exactly as §1.3's pattern. Below the
existing ISWC line region, add gated rows: `First published <year>`, `© <copyrightDisplay>`,
and fold `ccli`/`bowi` into an identifiers disclosure matching `SongMetadataView`'s
(`DisclosureGroup` + tvOS/watchOS always-expanded fallback — copy the `#if os(tvOS) ||
os(watchOS)` shape from `SongMetadataView.swift:140-149`, do not re-invent). `tuneName`
renders as a plain caption row ("Tune: <name>") — NO native tune destination exists (§5.2),
and a dead NavigationLink is worse than text.

### §3.3 Tests

`Tests/IHAPITests/WorkAndCreditPersonAPITests.swift`: extend the work fixture with the nine
keys; assert decode + both absent-tolerance directions (standalone-shape vs embedded-shape).

---

## §4 Slice D — Musician identifiers: additive web key + Apple render (~0.5 day; the only genuinely NEW web code in this issue)

### §4.1 Web — `includes/SongData.php::getMusician()` (after the links block, ~line 5492)

Add an always-present `'identifiers' => []` default to the `$person` array (line 5350 block),
then, inside the existing `if ($row && (int)$row['Id'] > 0)` region, a
`tblMusicianIdentifiers` existence-probe (byte-pattern of the `tblMusicianExternalLinks`
probe directly above it, lines 5454-5463) + prepared read:

```php
'SELECT IdentifierType AS type, IdentifierValue AS value
   FROM tblMusicianIdentifiers WHERE MusicianId = ? ORDER BY IdentifierType, IdentifierValue'
```

`bind_param('i', …)` (rule #5), whole block `try/catch → keep []` (rule #9 STRICT-safety;
migrations are web-run — an un-migrated docroot must degrade, never 500). **Emit on the
canonical `musician` envelope only? NO — decision taken: emit on BOTH.** The P2-B freeze
promises the legacy `person` envelope stays byte-identical *for the fields it has*; an
additive key does not break a tolerant decoder, and the shipped Apple binary decodes with
`Codable` ignoring unknown keys — verified harmless. (If the implementer finds an explicit
byte-identical CI guard on the legacy envelope — check `tests/` for a musician-contract test
before committing — then scope the key to the `musician` envelope instead and say so in the
PR; that fallback keeps §4.2 working since the client switches actions anyway.)

`php -l`, and extend `api-docs.yaml`'s musician schema if it models the envelope (check first
— mirror whatever the P2-B docs did).

### §4.2 Apple — switch to the canonical action + render

- `IHAPI/WorkAndCreditPersonEndpoints.swift:73-77`: `action: "credit_person"` → `"musician"`
  (all three lookup arms). The envelope key changes `person`→`musician`
  (`api.php:1548-1551`): update the decode in `WorkAndCreditPersonDecoding.swift`
  accordingly (find the envelope struct there; rename its key — this is why the freeze
  exists: OLD binaries keep `credit_person`, NEW binaries use the canonical action).
- `IHModels/CreditPerson.swift`: add `public let identifiers: [MusicianIdentifier]?`
  (`struct MusicianIdentifier: … { let type: String; let value: String }`) — Optional for
  the same staggered-deploy tolerance as §1.1.
- `IHFeatures/CreditPersonDetailView.swift`: render as an "Identifiers" disclosure
  (IPI/ISNI rows, `type.uppercased(): value`) using the same tvOS/watchOS-gated
  DisclosureGroup shape as §3.2.
- Tests: fixture + decode + absent-tolerance in `WorkAndCreditPersonAPITests.swift`;
  assert the endpoint's action string is `"musician"` (there are existing endpoint-shape
  tests in that file to mirror).

---

## §5 Deliberately DEFERRED (each gets its own follow-up issue filed per standing-tasks §2 — file at implementation time, referencing this spec)

1. **Android Musician/Work/Tune screens + deep links** — blocked on the Android Phase-2
   network client (no API layer exists at all, §0.2). Building the client is a multi-week
   program (auth, caching, offline reconciliation — the Apple package took months). NOT
   pretendable as part of this issue. Owner flag §9.1.
2. **Native Tune screen (both platforms)** — blocked on a web `?action=tune` JSON action
   that does not exist (§0.3.5). Estimate if greenlit: ~0.5 day web (JSON twin of
   `includes/pages/tune.php`'s resolve + song list, mirroring how `?action=work` wraps
   `getWork()`) + ~1-2 days Apple (model/VM/View/deep-link/tests). Until then `tuneName`
   renders as text (§3.2). Owner flag §9.2.
3. **Alias-route deep links** (`/tune/*`, `/isrc/* /iswc/* /ccli/* /ipi/* /isni/* /bowi/*`)
   — unreachable dead code until the web AASA claims them (§0.1); and the six identifier
   routes would ALSO need a native destination screen that doesn't exist. The JSON lookups
   they'd need are already live (§0.3.6). Owner flag §9.3.
4. **Refreshing `Tests/Fixtures/song_detail.json` from live dev** post-#1750
   (`tools/apple-refresh-fixtures.sh`) — needs network + deployed #1750; hand-edited
   fixture (§1.5) is correct in the interim.

---

## §6 Slice E — Android: forward-compatible model + gated render (~2 h; honest value statement below)

`ignoreUnknownKeys` is already on (`SongViewModel.kt:91`), and Kotlin default values make
absent keys safe against the existing bundled asset.

- `models/Song.kt` (`Song`, line 146): append
  `val subtitle: String = ""`, `val disambiguation: String = ""`,
  `val firstPublishedYear: Int? = null`, `val copyrightYears: String = ""`,
  `val copyrightHolder: String = ""` (+ KDoc `@property` lines matching the file's style,
  tagged `#1752`).
- `ui/screens/SongDetailScreen.kt`: ONE top-level fold
  `fun copyrightDisplay(song: Song): String` (same precedence contract, annotated as the
  Kotlin twin of `SongDetail.copyrightDisplay` — the §7 guard is the mechanism holding the
  three implementations together, rule #35). Use it at the existing copyright render
  (lines 183-190) AND in `shareSong()` (line 362). Subtitle `Text` gated non-blank under the
  title (line ~107 region); disambiguation appended to the title string; "First published"
  row in `SongMetadataSection` (line 219) after the CCLI row.

**Honesty clause (put this in the commit body):** no Android data source populates these
fields today (§0.2 — the bundled JSON's generator cannot carry them, and there is no network
client). The render is empty-gated, so the visible app is pixel-identical. The value is
(a) the wire contract is implemented once, now, while it's fresh, on both platforms, and
(b) the §7 guard can then hold **web ↔ Apple ↔ Android** in provable lockstep instead of
two-of-three. If the owner rejects that reasoning (§9.1), drop this slice — nothing else
depends on it (the §7 guard's Android assertions are conditional on the slice landing;
build the guard to derive its target-file list accordingly and say so in its header).

---

## §7 CI guard — `tests/test-native-identity-contract.js` (tree-derived + mutation-proven, rule #34; THE rule-#35 mechanism for this contract)

**Model:** `tests/test-identifier-routes.js` (read it first — same structural read-the-
shipped-source style, same PASS/FAIL harness shape; auto-discovered by
`tools/run-node-tests.js`' glob — verify that by running the suite and seeing the count go
49 → 50).

**Derivation (never a typed list):** parse `appWeb/.sql/schema.sql`, slice the `tblSongs`
CREATE TABLE block, extract every column whose COMMENT contains the literal `#1741 P1`
(same derivation as #1750 §6's PHP guard — reimplement in JS; do NOT shell out to PHP).
Expect exactly {Subtitle, Disambiguation, FirstPublishedYear, CopyrightYears,
CopyrightHolder} today; a sixth P1 column is covered automatically. Key mapping
`camel = colName[0].toLowerCase() + colName.slice(1)` — assert the mapping itself in a
self-test.

**Comment stripping (a comment must never satisfy an assertion):** implement
`stripSlashComments(src)` handling `//` line comments and `/* … */` block comments
**with nesting** (Swift block comments nest; a naive stripper un-strips early — put a
fixture proving nested-block handling in the self-tests). Apply to every Swift/Kotlin file
before any substring assertion.

**Assertions (each key, against comment-stripped source):**

1. Appears in `appApple/…/IHModels/SongDetail.swift` (property + CodingKeys region).
2. Appears in a render site: `SongMetadataView.swift` OR `SongDetailView.swift`
   (`firstPublishedYear`/`copyrightYears`/`copyrightHolder` are reached via
   `copyrightDisplay`/`rightsSection` — assert the literal `copyrightDisplay` in
   `SongMetadataView.swift` and the two split keys in `SongDetail.swift`'s
   `copyrightDisplay` body instead of pretending every key is rendered verbatim; a guard
   that under-reports is worse than none, but so is one that fails on correct code —
   rule #34's both edges).
3. `externalIds` present in `APIClient.swift`'s `songDetailIncludeBlocks` literal.
4. IF `appAndroid/…/models/Song.kt` contains ANY of the derived keys (i.e. Slice E landed),
   then ALL of them must be present there AND `copyrightDisplay` must appear in
   `SongDetailScreen.kt` — the conditional keeps the guard green if the owner drops §6,
   while making a HALF-landed Android slice red.
5. Work parity: the nine P4b keys (derive from `getWork()`'s own literal array in
   `SongData.php:4993-4996` — slice the `$extraColNames = [` … `]` region, parse the quoted
   names, lcfirst them) each appear in `Work.swift`.

**Mutation self-tests (in-memory, run first, both directions):** slicer finds/stops on
fixture DDL; tag-extractor fails-high and fails-low; nested-comment stripper (needle only in
comment → reported missing; real needle survives); the conditional-Android logic (fixture
with one key → red, zero keys → skipped, all keys → green).

**One-time tree mutation proof (do, record in PR, restore):** delete `subtitle` from
`SongDetail.swift`'s CodingKeys → red; comment-out (not delete) the `copyrightDisplay`
usage in `SongMetadataView.swift` → red (proves comment-stripping bites); restore → green.

---

## §8 Verification plan (what can and CANNOT be verified here — stated per standing-tasks "say so explicitly, never silently skip")

1. **This sandbox has NO Swift toolchain and NO Android SDK** (verified: `which swift` empty;
   `ANDROID_HOME` unset). **Neither app can compile or run tests here.** Verification in
   this session = source review + the §7 guard + the JS/PHP suites. This is a structural
   review, not a build — the PR description MUST say so and leave a tracked task:
   - macOS/CI: `cd appApple/Packages/iHymnsKit && swift test` (the package tests cover every
     §1-§4 model/router change), then an Xcode build via `project.yml`/`Scripts/bootstrap.sh`.
   - Android/CI: `gradle :app:assembleDebug` with a real SDK (Slice E is model+Compose only;
     low risk, still must compile).
   - A real-device Universal-Link tap on a `/musician/<slug>` link (§2) — simulator AASA
     behaviour is not proof.
2. **Syntax here:** `node --check tests/test-native-identity-contract.js`;
   `php -l appWeb/public_html/includes/SongData.php` (§4.1 is the only PHP edit).
3. **Suites here:** `php tools/run-php-tests.php` (101 — or 102 with #1750's guard — all
   green, count unchanged by this issue) and `node tools/run-node-tests.js` (49 → **50**).
   Mutation proofs per §7.
4. **Live behavioural probe (dev, requires #1750 deployed; run from a networked env):**
   seed the #1750 §7.4 fixture row, then
   `curl 'https://<dev>/api?action=song_detail&id=CP-0001&include=externalIds'` → five keys
   + block present; `curl 'https://<dev>/api?action=musician&slug=<slug-with-ipi-row>'` →
   `identifiers` array present; `curl 'https://<dev>/api?action=credit_person&slug=…'` →
   legacy envelope still decodes (spot-diff against a pre-change capture: additive key only).
   Then the on-device render pass of §1/§3/§4 screens against that song. Revert the fixture.
5. **Docs/standing tasks:** update `.claude/apple-native-status.md` (new decoded fields +
   the credit_person→musician client switch), `CHANGELOG.md`, wiki API-Reference (musician
   `identifiers` key), and file the §5 follow-up issues + close #1752 with SHAs + evidence.

---

## §9 Owner decision points (all non-blocking; defensible defaults taken)

**9.1 Android scope.** The decision: build the Android network client now so Android can
genuinely surface #1741, or land only the forward-compatible Slice E and track the client
as its own program. Why it's an owner call: multi-week product/sequencing commitment, not
derivable from code. Options: (a) client now — weeks of work inside a catalogue issue,
blocks #1752 indefinitely; (b) Slice E + follow-up issue "Android Phase-2 API client"
(cost of nothing further: Android users see none of #1741 until that lands); (c) drop
Android from #1752 entirely — same user outcome as (b), minus the contract lockstep.
**Recommendation: (b)** — it keeps this issue closable, the guard three-way, and the real
dependency named in the tracker. Needed back: "a, b or c".

**9.2 Native Tune screen.** Defer (with the §5.2 estimate + a `for consideration` issue) vs
build now including the new web `?action=tune`. **Recommendation: defer** — tune pages are
a browse nicety; the identity fields are the catalogue payload users asked for. Trivially
reversible (the §5.2 estimate is the whole plan). Needed back: nothing unless you want it
now.

**9.3 Alias-route Universal Links.** Should the AASA ever claim `/tune/*` + the six
identifier routes so those links open the app? **Recommendation: no for now** — no native
destination exists (§5.3), and an unclaimed path cleanly falls through to the PWA, which
renders all seven routes today. Revisit only after 9.2 lands a native destination. Needed
back: nothing.

---

## §10 Files touched (complete) + effort

| Slice | Files | Effort |
|---|---|---|
| A | `appApple/…/IHModels/SongDetail.swift`, `IHAPI/APIClient.swift` (1 line), `IHFeatures/SongDetailView.swift`, `IHFeatures/SongMetadataView.swift`, `Tests/IHModelsTests/SongDetailTests.swift`, `Tests/Fixtures/song_detail.json` | 0.5-1 d |
| B | `IHAppSupport/DeepLink.swift`, `IHAppSupport/CanonicalURL.swift`, `Tests/IHAppSupportTests/{DeepLinkRouterTests,CanonicalURLTests}.swift` | 1-2 h |
| C | `IHModels/Work.swift`, new `IHModels/CopyrightDisplay.swift`, `IHFeatures/WorkDetailView.swift`, `Tests/IHAPITests/WorkAndCreditPersonAPITests.swift` | 0.5 d |
| D | `appWeb/public_html/includes/SongData.php` (+`api-docs.yaml` if applicable), `IHAPI/WorkAndCreditPersonEndpoints.swift`, `IHAPI/WorkAndCreditPersonDecoding.swift`, `IHModels/CreditPerson.swift`, `IHFeatures/CreditPersonDetailView.swift`, tests | 0.5 d |
| E | `appAndroid/…/models/Song.kt`, `ui/screens/SongDetailScreen.kt` | 2 h |
| Guard | `tests/test-native-identity-contract.js` (new) | 0.5 d |
| **Total** | | **~3-4 days** (one PR, one commit per slice, per repo PR policy) |

No schema change, no migration, no new endpoint, no new URL param emitted (rule #33 surface
unchanged: §2 only widens what an existing emitter's links RESOLVE to). The only web edits
are §4.1's additive, existence-gated key and (conditionally) `api-docs.yaml`.
