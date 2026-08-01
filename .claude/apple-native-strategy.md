# iHymns — Apple Native Universal App: Strategy & Build Plan

> Deep-planning output for the from-scratch native Apple Universal app (iOS · iPadOS · macOS · tvOS · watchOS · visionOS). Planned with **Fable 5** (sequential passes) + Opus 4.8 consolidation. Epic: **#895**. Branch: dedicated Apple branch, **NO PRs yet**. All code under `appApple/`.
>
> **Status:** PLANNING COMPLETE (awaiting owner approval to begin execution). Passes: [1] Foundation & Architecture ✅ · [2] Features/UX/Native-enhanced/Remote ✅ · [3] Distribution/Security/Roadmap/dev-team process ✅. Planned 2026-07-04 with Fable 5 (3 sequential passes).
>
> Companion docs: `.claude/apple-native-status.md` (the Apple programme's state record — NOT the session handoff, which is the single `.claude/sessions/<date>-HANDOFF.md`), context brief (scratchpad). Paired with `.claude/CLAUDE.md` (repo rules), `api-docs.yaml` (the backend contract).

## 0. Ground rules (owner-set)
- **One Apple Universal app**, all platforms, single App Store purchase/download.
- **Swift 6.3.3+, SwiftUI, Apple Liquid Glass** (OS 26 design language).
- **API-driven** — everything via the iHymns `?action=` API; offline cache allowed; NO bundled corpus.
- Feature floor = **web/PWA parity** (native layout); PLUS **native-enhanced extras** as an incentive over the PWA.
- **Global login** (one Apple ID → all devices; share PWA login where possible), **deep linking** (shared link → app if installed else PWA), **Handoff** + all Apple-unique features, **in-app docs**.
- **TV + Watch remote control** of the tvOS lyrics, over any network incl. VPN.
- **App Store + TestFlight** ready; universal purchase; **versioning** = shared MAJOR with web, unique auto-increment minor/build.
- Clean slate: existing `appApple/` Swift files + stale Apple issues are scrapped.
- Build with **Sonnet/Haiku** (Opus only if strictly needed); each task = its own GitHub issue; commit individually; **make full use of the dev-team plugin suite**; NO PRs yet.

---

## 1. Foundation & Architecture

*(Fable 5 pass 1)*

### 1.1 Xcode project topology
**One Xcode project (`appApple/iHymns.xcodeproj`), generated from a checked-in XcodeGen `project.yml`, with three thin app shells + extensions, and ~90% of code in one local Swift package `iHymnsKit`.**

Targets:
- **iHymns** (multiplatform: iOS, iPadOS, macOS native SwiftUI [not Catalyst], visionOS) — bundle id `app.ihymns`.
- **iHymns TV** (tvOS) — same bundle id `app.ihymns` (deliberate: attaches to the same App Store record → same purchase). Kept a separate shell for the focus-first scene + TV asset catalog + purge-eligible storage posture.
- **iHymns Watch** (watchOS) — `app.ihymns.watchkitapp`, **embedded in the iOS app** (runs standalone at runtime); embedded = automatically part of the same purchase.
- **iHymnsWidgets** — `app.ihymns.widgets` (WidgetKit + ActivityKit / Live Activities / Dynamic Island).
- **iHymnsTopShelf** (later) — tvOS Top Shelf.

**Universal purchase = same bundle id across iOS/macOS/tvOS/visionOS binaries** (automatic grouping for post-2020 apps). watchOS ships embedded (no second record). One listing, one price (free), one download that fans out per device family. "One app" is physically 3 binaries under one record — invisible to users.

**Project generation:** XcodeGen (declarative `project.yml`, agent-diffable, `xcodegen generate`); **gitignore the generated `.xcodeproj`**. SwiftPM is the library layer only (can't own entitlements/asset-catalogs/archives). Tuist rejected as heavier; hand-maintained pbxproj rejected (merge-hostile to a coding agent). `Scripts/bootstrap.sh` regenerates; tools pinned via Brewfile/mise.

Directory layout under `appApple/`: `project.yml`, `Brewfile`/`.mise.toml`, `Scripts/{bootstrap,sync-version}.sh`, `Config/*.xcconfig`, `Apps/{iHymns,iHymnsTV,iHymnsWatch,iHymnsWidgets}/`, `Packages/iHymnsKit/`, `fastlane/`.

### 1.2 Shared package `iHymnsKit` — one package, strictly-layered targets
- **IHModels** — Sendable Codable DTOs + domain types, zero deps. `SongID` wraps the `<letters>-<digits>` prefix rule (#27). Types: SongSummary (from `songs_index`), SongDetail, Songbook, Setlist, CreditPerson, Work, LiveSession, ServiceSession, Tier/Capabilities, DeepLink.
- **IHAPI** — `actor APIClient`, `APIEnvironment` (dev/beta/prod), typed endpoint catalogue over `?action=`, `APIError` taxonomy, retry policy, request signing (`Authorization: Bearer <64-hex>` + `X-Requested-With`). Deps: IHModels.
- **IHAuth** — Keychain `TokenStore` (synchronizable), `SessionController` actor (state as AsyncStream), SIWA coordinator, `ASWebAuthenticationSession` handoff, tvOS device-code pairing, WatchConnectivity token mirror. Deps: IHAPI, IHModels.
- **IHPersistence** — GRDB DB (catalogue index + FTS5, saved songs, favourites/setlist mirrors, pending-sync queue), DownloadManager (audio/PDF/MusicXML + signed-URL refresh), eviction. Deps: IHModels.
- **IHLive** — `LiveFollowEngine` + `ServiceModeEngine` actors (polling loops, 30 s heartbeat, 180 s freshness, presence-token lifecycle) **for the server-mediated Live Follow / Service Mode congregant feature** — Deps: IHAPI. **PLUS `IHLive/LANRemote`** (§2.4 revised): `TVListenerActor` + `RemoteSessionActor` + the IHRP/1 message enum for the **LAN-DIRECT native TV remote control** (Bonjour + Network.framework TLS, peer-to-peer, no backend). These are TWO distinct subsystems in one module.
- **IHDesign** — Liquid Glass design system: tokens, glass wrappers, theme engine (light/dark/high-contrast/CVD), typography ramp, LyricTypography (chorus/refrain italic per web), adaptive layout. Deps: SwiftUI only.
- **IHFeatures** — shared SwiftUI screens + `@Observable` VMs for every surface (Catalogue/Search, SongDetail, Songbooks, SOTD, Favourites, Setlists, LiveFollow, ServiceMode, Settings, Help). Deps: all above.
- **IHAppSupport** — DeepLinkRouter (URL→DeepLink→nav), Handoff activity builders, version info, feature flags, os.Logger wrappers. Deps: IHModels.

**Modularity boundary:** shells may compose/configure iHymnsKit, never reimplement it; shared code needed by two shells moves into the Kit in the same commit. Per-target = `@main` App, scene topology, platform-unique surfaces (macOS menus + Touch Bar, Live Activity UI, Top Shelf, complications, CarPlay), entitlements/Info.plist. CI enforces via LOC budget + SwiftLint custom rules banning `URLSession`/`Keychain` outside IHAPI/IHAuth.

### 1.3 Swift 6 strict concurrency
Swift 6 mode, `StrictConcurrency=complete` from day one. UI targets (IHFeatures/IHDesign/shells) use MainActor default isolation; IHAPI/IHPersistence/IHLive/IHModels use nonisolated default (the concurrent substrate). `actor APIClient` (token snapshot + in-flight de-dup; requests are nonisolated async). `actor OfflineStore` over GRDB `DatabasePool`. All DTOs struct+Sendable. `@Observable` MainActor VMs, no Combine. Live loops = structured `Task` + `Task.sleep`, cancelled on scene-phase `.background` (best-effort leave), restarted `.active`; loop-iteration errors classified, never kill the loop (only `.unauthorized`/end terminates).

### 1.4 Liquid Glass adoption
**Min deployment = OS 26 across the board; no pre-26 fallback (that audience uses the PWA)** — biggest scope-saver, safe because the PWA exists. Standard components (built vs 26 SDKs) render Liquid Glass automatically. Custom glass only for bespoke chrome via real APIs (`glassEffect(_:in:)`, `GlassEffectContainer`, `.buttonStyle(.glass)`, `.scrollEdgeEffectStyle`, `.tabBarMinimizeBehavior`, `backgroundExtensionEffect()`) — candidates: lyric transport HUD, live "now singing" pill, service operator controls. **Never fake glass with `.ultraThinMaterial`.** IHDesign owns tokens + wrappers (IHGlassCard, IHLyricCanvas, IHBadge incl. the ONE shared unofficial-songbook badge, mirroring web rule #24). Theming parity via native idioms: follow system appearance; respect Increase Contrast + Reduce Transparency; CVD = in-app palette override at the token layer (`Environment(\.ihPalette)`). Per-platform composition of the same shared content views (iPhone glass TabView; iPad/Mac/Vision NavigationSplitView; tvOS focus-first full-bleed; watch NavigationStack). IHLyricCanvas = one shared view, platform/size-class typography ramp (watch-glanceable → tvOS projection).

### 1.5 API client layer
Environments dev/beta/prod (Debug→dev, TestFlight→beta, App Store→prod; internal-only switcher, never shipped enabled). **`Authorization: Bearer <64-hex>`** (spec-confirmed first-class; better than replaying the cookie — per-token rate-limit keying matters behind congregation NAT); every POST adds `X-Requested-With` (rule #29). Hand-written Codable DTOs (not swift-openapi-generator — `?action=` query-in-path is awkward for it; ~60 ops hand-mappable; YAML stays the reference + feeds contract tests decoding live fixtures in CI). Typed endpoint catalogue grouped Catalog/User/Auth/Live/Bundle.

`APIError`: `.offline` | `.maintenance(retryAfter)` (the 503 path — a designed state; surface "unavailable" + serve offline cache for reads) | `.unauthorized` (401→sign-out) | `.rateLimited(retryAfter)` | `.server(status,message)` | `.decoding` (contract drift, log loud). Retry idempotent GETs (expo backoff+jitter, max 3, honour Retry-After); **never auto-retry non-idempotent POSTs** (they go through the persistence sync queue with server dedupe); live polls don't backoff (skip a tick; 180 s freshness is the arbiter).

**NO whole-corpus, natively enforced:** only `songs_index` (cached + FTS5), `song_detail` per song, `songs?abbr=` per book, `bulk_songs`/`bulk_audio` in the explicit offline-save flow. No client API can materialise the corpus.

**Offline cache = GRDB (pinned).** FTS5 = instant offline search (the killer requirement); real migrations; Swift 6 Sendable; all six platforms; relational sync queue. (SwiftData/Core Data rejected: no FTS, weak concurrency, uneven platform support; flat files only for media blobs.) Schema: `song_index`(+FTS5), `song_detail_cache`(LRU), `offline_song`(pinned), `media_asset`(path+expiry), `pending_op`(sync queue). **tvOS:** DB in Caches, fully reconstructible, nothing "offline-saved"; token in tvOS Keychain (survives purge). iOS/macOS/vision: DB in Application Support; media blobs `isExcludedFromBackup`.

### 1.6 Auth & GLOBAL login
Token in **Keychain only** (`kSecClassGenericPassword`, service `app.ihymns.token`, access group `TEAMID.app.ihymns.shared` for extensions, `AccessibleAfterFirstUnlock`); never UserDefaults, never logged. **Global login = iCloud Keychain sync** (`kSecAttrSynchronizable=true`): sign in on one device → token appears on all under one Apple ID → flip signed-in (SecItem foreground poll + validating `favorites` call). Zero backend work; E2E-encrypted by Apple; revocable server-side (delete `tblApiTokens` hash → all 401). Trade-off: needs iCloud Keychain (default-on); all devices share one token (per-device sign-out needs optional backend token metadata). **watchOS:** also mirror token via WatchConnectivity into watch Keychain. **tvOS (the gap — no iCloud Keychain):** **device-code pairing** (reuse Service Mode's transactional Crockford-base32 minting) — TV shows 6-char code + QR; signed-in phone/iPad/PWA confirms; TV polls → own 30-day token. **REQUIRED backend work.** **Interim (zero backend):** tvOS ships sign-in via the existing `auth_email_login_request/verify` 6-digit code (typeable on Siri Remote).

**Sign in with Apple** (owner wants it; not strictly forced by 4.8): **ONE new endpoint `?action=auth_apple`** — verify identity-token JWT (JWKS, iss/aud=`app.ihymns`/exp), map `sub`→user via new one-pass **`tblUserAuthProviders`** (`Provider` VARCHAR not ENUM #20; UNIQUE(Provider,ProviderSub); email-link + private-relay), mint a standard 30-day `tblApiTokens` token (response identical to `auth_login`) → native treats SIWA as just another bearer-token source; whole global-login stack applies unchanged.

**Share PWA/browser login:** (1) day-one zero-backend — Associated Domains `webcredentials:ihymns.app` + AASA → Password AutoFill offers saved ihymns.app creds; (2) small backend — `ASWebAuthenticationSession` → new `/auth/app-handoff` page that, if the browser has a valid `ihymns_auth` cookie, mints a **fresh** app token (user-gesture-gated; never reflect the existing cookie token); (3) not possible — reading the PWA cookie/IndexedDB directly (sandbox forbids, correctly).

### 1.7 Deep linking & bundle-ID
**Bundle id = `app.ihymns` on every Apple platform** (universal purchase REQUIRES the same id; Associated Domains + Handoff are per-appID+Team). `app.ihymns.pwa` = the PWA's own id; **`app.ihymns.apple` NOT needed**. Only Apple-forced derivatives: `app.ihymns.watchkitapp`, `app.ihymns.widgets`. Register the App ID once with: Associated Domains, Sign in with Apple, App Groups (`group.app.ihymns`), Push (future), iCloud (reserved).

**Universal Links:** serve `https://ihymns.app/.well-known/apple-app-site-association` (application/json, HTTPS, no redirect) on **all three docroots** — **REQUIRED backend work** (trivial). `applinks` for `/song/*`, `/songbook/*`, `/songbooks`, `/person/*`, `/work/*`, `/setlist/*`, `/live/*` + the `/?page=…&id=…` PWA route shapes; exclude `/manage/*`,`/api*`. Plus `webcredentials` + `activitycontinuation` (Handoff). **DeepLinkRouter** (IHAppSupport): URL→DeepLink enum→per-shell navigation (song ids parsed by rule #27, `Abbreviation` not `DisplayAbbr`). **App-absent fallback is free** (universal links are plain HTTPS → Safari/PWA opens); therefore every share surface must emit the **canonical web URL**, never a custom scheme. **Handoff:** every screen publishes `NSUserActivity` with `webpageURL`=canonical URL + `targetContentIdentifier`=deep-link → native↔native Handoff, and native→any-device degrades to Safari/PWA.

### 1.8 Versioning + build
`MARKETING_VERSION = <sharedMajor>.<appleMinor>.<patch>` — sharedMajor parsed from web `infoAppVer.php` (currently `0`); appleMinor auto-increments per Apple release independently of web's minor (same major, unsynchronised minors). `CURRENT_PROJECT_VERSION` = UTC timestamp `YYYYMMDDHHmm` (monotonic, no ASC round-trip, collision-free). One `Config/Versioning.xcconfig` for ALL targets. `Scripts/sync-version.sh` (CI fails on major drift; `--bump-minor`; stamps build timestamp). Fastlane lanes: `test` (Kit tests on macOS — fast loop), `alpha`/`beta` (TestFlight internal/external), `release` (App Store); signing via ASC API key + `match`. CI on `macos-26` runner (Xcode 26.x pinned); Apple CI under `appApple/` + `.github/workflows/apple.yml`; dedicated Apple branch, NO PRs.

### 1.9 REQUIRED / recommended backend changes (each → its own issue)
**Required v1:** (a) **AASA file** on all 3 docroots (`applinks`+`webcredentials`+`activitycontinuation` for `TEAMID.app.ihymns`); (b) **`auth_apple`** SIWA endpoint + `tblUserAuthProviders` migration (schema.sql + registry probe, rules #19/#20); (c) **tvOS device-code pairing** (`auth_device_code_request/poll/confirm`; reuse Service-Mode minting) — interim email-code unblocks tvOS.
**Recommended (non-blocking):** token/device metadata on `tblApiTokens` + "manage devices / sign out a device" API+UI; **Safari-session handoff** page + mint-scoped endpoint.
**Later (flag for lead time):** APNs channel for Live-Activity push (backgrounded now-singing updates); CarPlay entitlement (weeks of Apple approval).

---

## 2. Features, Per-Platform UX & Native-Enhanced Edge

*(Fable 5 pass 2)*

### 2.0 Scope stance
- **Native = catalogue consumer + live-worship operator. Curation stays web** (`/manage/*` out of native scope).
- **iPhone/iPad/Mac/visionOS = full editors** of user content (favourites, setlists, requests, live control). **tvOS = display/projector + browse. watchOS = glance + remote.** Neither TV nor Watch presents a full text-entry editor (HIG-correct, keeps thin shells thin).

### 2.1 Feature-parity matrix (● Full · ◐ Reduced · — N/A)
| Capability | iPhone | iPad | Mac | tvOS | watch | vision | Native treatment |
|---|:-:|:-:|:-:|:-:|:-:|:-:|---|
| Search text / number | ● | ● | ● | ◐ | ◐ | ● | `.searchable`/⌘K/Siri dictation; watch dictation+scribble |
| Song display (lyrics/arrangement/verse-chorus-bridge, chorus italic #1337) | ● | ● | ● | ● | ◐ | ● | `SongScreen`; tvOS projection variant; watch lyrics-only Crown-scroll |
| Chords toggle | ● | ● | ● | ● | — | ● | Chord layer; too dense for watch |
| Per-line translations/annotations/romanization | ● | ● | ● | ◐ | — | ● | Long-press line → glass sheet; tvOS shows 2nd projected line |
| Metadata/credits/authority-ids · Part-of-work · Related/counterpart | ● | ● | ● | ◐ | — | ● | Credits→people; counterpart shelf; tvOS condensed |
| Songbooks browse (official+unofficial badge/series/Collections) | ● | ● | ● | ● | ◐ | ● | Native capsule badge (same semantics as web #24) |
| Song of the Day (hemisphere+country) | ● | ● | ● | ● | ● | ● | Home hero + widget/complication/Top Shelf |
| Favourites (synced) | ● | ● | ● | ◐ | ◐ | ● | `favorites_sync` + offline queue |
| Setlists create/reorder/tag | ● | ● | ● | — | ◐ | ● | Drag-reorder (iPad drag-drop, Mac reorder); watch=viewer/tick-off |
| Setlists LIVE-share/schedule/templates | ● | ● | ● | ◐ | ◐ | ● | Share sheet w/ canonical URL; schedule→Smart Stack relevance |
| Offline save (songs+audio) | ● | ● | ● | ◐ | ◐ | ● | GRDB+FTS5 + audio cache; tvOS cache-only (purgeable); watch=fav lyrics |
| Audio MP3 · MIDI | ● | ● | ● | ●/◐ | ◐/— | ● | AVPlayer+NowPlaying/AirPlay; AVMIDIPlayer |
| PDF sheet · MusicXML | ● | ● | ● | — | — | ●/◐ | PDFKit+QuickLook (no PDFKit on tvOS/watch); MusicXML=download/share |
| Themes light/dark/high-contrast/CVD | ● | ● | ● | ● | ◐ | ● | System appearance + Increase Contrast; CVD accent = in-app setting |
| i18n language/script/region | ● | ● | ● | ◐ | ◐ | ● | Native pickers; tvOS/watch inherit account pref |
| Live Follow #1268 (host+join) | ● | ● | ● | ◐ | ◐ | ● | `LiveFollowEngine`; tvOS joins as display; watch=glance+remote |
| Service Mode #1335 (operator/congregant/projector) | ● | ● | ● | ● | ◐ | ● | tvOS=projector; phone/iPad/Mac/vision=operator/congregant; watch=remote |
| Content gating/tiers (dormant) | ● | ● | ● | ● | ● | ● | Render locked states from `access_tiers` caps; **no client enforcement**; no-op like web |
| Song requests | ● | ● | ● | — | — | ● | Native form + "My requests" |
| Share cards / deep links | ● | ● | ● | ◐ | ◐ | ● | Universal Links on canonical URLs |
| Help / legal / first-run | ● | ● | ● | ◐ | ◐ | ● | TipKit coach marks + in-app manual (#9) |

**Takeaway:** iPhone/iPad/Mac/visionOS = 100% parity floor. tvOS drops editing/requests/PDF/full-i18n, gains the projector role. watchOS = companion (glance/favourites/setlist tick-off/live remote).

### 2.2 Per-platform UX blueprint (signature surfaces on top of the shared IHFeatures)
- **iPhone** — Liquid Glass `TabView` (Home·Songbooks·Setlists·Live·Search). Home=SOTD hero + resume + live banner + favourites shelf. Song=full-bleed lyrics + floating glass toolbar. Swipe adjacent songs; pinch lyric type; long-press line→enrichment; haptics on join/broadcast song-change. **Dynamic Island + Live Activity** (now-singing; host gets Next/Prev as App-Intent buttons). StandBy/Lock widgets. Action Button/Control Center = "Join live service".
- **iPad** — `NavigationSplitView` 3-column (collapses to iPhone tabs in compact — one codepath). Setlist workbench (drag songs catalogue→setlist). Hardware-keyboard map (⌘K/⌘D/←→/space/B-blackout). Apple Pencil (Scribble/hover; later PencilKit on PDF). Stage Manager multi-window. **External-display projection** (2nd `UIWindowScene` = clean feed while iPad = operator console) — the "projector but no Apple TV" story, extends #1104.
- **macOS** (native SwiftUI) — same split view; multi-window `WindowGroup` per song; **full-screen projection on a 2nd display** (Mac = operator + projector). **Menu-bar `Commands`**: Song (Next/Prev song ⌘←→, Next/Prev section, Toggle Chords ⇧⌘C, text ⌘±), Live (Go Live, Start Service, Blackout B, Rotate Code, End). **Touch Bar** via `.touchBar` (transport/section/blackout/search — courtesy layer, hardware discontinued). MenuBarExtra (P2). Handoff both ways.
- **tvOS** — tabs (Home·Songbooks·Search·Project·Settings). **Projector (#1115):** giant auto-fit lyric type, **karaoke current-line/section highlight** (active full-opacity+accent glow, neighbours dim, `matchedGeometry` slide), blackout/logo, focus parked while projecting, Siri-Remote tap→translucent strip. **Every local remote input round-trips through `service_broadcast`** (TV never diverges from server). Top Shelf (SOTD/continue/scheduled setlists; ambient during session).
- **watchOS** (embedded) — vertical `TabView` (Now·Remote·Favourites·Setlist). **Remote screen:** big Prev/Next section, Crown scrubs sections, long-press→jump-to-song, blackout, follower count. **Double Tap = Next section.** Complications (SOTD; "LIVE" when active); **Smart Stack RelevanceKit** surfaces the setlist before a scheduled service.
- **visionOS** — glass windows (home turf); song transport as bottom **ornament**; multi-window shared space. Flagship P2: **Lyric Space `ImmersiveSpace`** (large lyric lines in depth, current-line nearest/brightest, spatial audio; congregant joins by code, space advances with broadcast).
- **CarPlay** (P2, not a v1 shell) — `CPListTemplate` favourites/setlist audio + `CPNowPlayingTemplate`. **⚠️ Apply for `com.apple.developer.carplay-audio` entitlement at project START (weeks of Apple lead time).**

### 2.3 Native-enhanced "edge" features (Priority = Value × (6−Effort), max 25)
| Feature | Prio | Phase | Reuses |
|---|:-:|:--|---|
| **Now Playing / lock-screen / AirPlay 2** (audio) | 25 | MVP | media/audio |
| **Handoff** (song↔song, console↔console, app↔PWA via `webpageURL`) | 20 | MVP | DeepLinkRouter |
| **Widgets** SOTD/favourites/resume — Home/Lock/StandBy/interactive | 20 | MVP | IHAPI/Persistence/Design |
| **App Intents / Siri / Shortcuts / Spotlight** (OpenSong/PlaySOTD/StartService/JoinByCode; `CSSearchableItem`) | 20 | MVP | DeepLinkRouter/IHAPI/IHLive |
| **External-display projection** from iPhone/iPad/Mac | 20 | P1.5 | tvOS projection view/IHLive |
| **Watch complications + Smart Stack relevance** | 16 | MVP(w/watch) | Widgets/IHLive |
| **Control Center control + Action Button** (Join service/SOTD) | 15 | P2 | App Intents |
| **Quick Look + drag-drop PDF sheet music** | 15 | MVP | media |
| **Mac MenuBarExtra** | 15 | P2 | IHLive/Design |
| **Live Activities + Dynamic Island** (now-singing; host controls) | 12 | P1.5 | IHLive/Widgets — ⚠️ needs APNs |
| **Translation framework** (on-device translate uncurated lines; visually distinct, never write back) | 12 | P2 | Song screen |
| **CarPlay audio** | 12 | P2 | audio/NowPlaying |
| **Focus filters** ("Worship" Focus) | 10 | P3 | Settings |
| **SharePlay sing-along** (FaceTime GroupActivities; separate from Live Follow) | 6 | P3 | Song screen |
| Journal suggestions / Wallet / Screen Time | — | Rejected→`for consideration` | — |

**MVP native-edge set (v1.0):** Now Playing/AirPlay, Handoff, Widgets, App Intents/Siri/Spotlight, complications, Quick Look/drag-drop — high-value, low-effort, mostly thin layers over Pass-1 engines. **Fast-follow (P1.5):** external-display projection, Live Activities/Dynamic Island.

### 2.4 (REVISED) TV Remote Control (LAN-direct) + Live Follow (server) — TWO DISTINCT FEATURES
> **Owner correction 2026-07-06 (supersedes the earlier server-mediated remote):** the native **TV remote control is LAN-DIRECT, v1, Phase 2** (remote device + tvOS on the same LAN; a device VPN'd INTO that LAN counts as local). It is a **different feature** from the server-mediated Live Follow / Service Mode (which the native app also supports, unchanged, for remote congregants). Full design: Fable pass 4.

**2.4.1 The two features (hard separation):**
| | **LAN Remote Control** (native, local) | **Live Follow / Service Mode** (server) |
|---|---|---|
| Drives | the tvOS lyric display directly | congregants' own devices anywhere |
| Topology | peer-to-peer TLS, remote(s)→TV | client→PHP API→polling clients |
| Network | same LAN (VPN-into-LAN counts) | anywhere |
| Latency | **sub-100ms** | seconds (poll/180s freshness) |
| Trust boundary | **the LAN pairing ceremony** | server session + rotating code/presence token |
| Backend | **NONE** | server-mediated (existing) |

**Coexistence:** the tvOS app is the single display authority owning one canonical `(songId, componentIndex, lineIndex, displayState)`. A LAN command changes it; if the venue also runs a Service session for remote congregants, the **operator's remote device mirrors state to the server via the EXISTING `service_broadcast` (mirror-on-ack, one writer)** so congregants sync. LAN link never carries congregant traffic; server never carries TV control. **Native-only win: an internet outage doesn't kill the local projection** (LAN control + TV offline cache); only the congregant mirror degrades.

**2.4.2 Discovery + transport — Bonjour `_ihymns-remote._tcp` + Network.framework (client/server), TLS 1.3.** `NetworkListener` on TV, `NetworkBrowser`+`NetworkConnection` on remotes (OS-26 API; classic `NWListener`/`NWBrowser`/`NWProtocolTLS`/`NWProtocolFramer` fallback). New **IHLive/LANRemote** sub-module, actor-isolated: `TVListenerActor` (tvOS) + `RemoteSessionActor` (all remotes). **MultipeerConnectivity REJECTED:** no watchOS; can't reach a VPN'd-in device (link-local AWDL, not routed); wrong shape (1 TV/N remotes); opaque security; unreliable. **Watch → relays through the paired iPhone via WatchConnectivity `sendMessage`** (watchOS Network.framework/Bonjour is restricted) → iPhone's `RemoteSessionActor` forwards; ~100–300ms extra; reduced Watch controls (Next/Prev/Blackout/Logo/status). Requires phone reachable from watch (stated limitation).

**2.4.3 Pairing + security (the boundary is the pairing, NOT the network):** being on the LAN grants only *discovery*. Control requires pairing: TV generates a persistent P-256 identity+self-signed cert (tvOS Keychain) terminating every TLS session; an unpaired connect is confined to the pairing sub-protocol. TV shows a **6-digit code + QR** (QR encodes `{name,ip,port,certFingerprint,pairingCode}` → out-of-band cert pinning defeats MITM; manual code = HMAC over the cert fingerprint keyed by the code = channel binding). Code TTL 2min, 5 fails→rotate; pairing screen operator-initiated. Success mints a per-remote 32-byte token (TV stores `sha256`; remote stores raw + pinned cert). **This LAN pairing trust is per-device and NOT iCloud-synced** (physical-proximity ceremony; the account token stays synced — different credential). Reconnect: TLS→verify pinned fingerprint→token in `hello`→controlling <1s. Revoke per-remote in TV Settings. **Accounts orthogonal:** control needs neither device signed-in nor a shared account; a shared account is NEVER a substitute for pairing (the synced account token is phishable — writing to the sanctuary screen requires having stood in front of it). Content entitlements ride the TV's OWN auth (remote sends intent, TV fetches; gated song → `error(.contentUnavailable)`, answer = sign the TV in).

**2.4.4 Protocol IHRP/1 (versioned Sendable enum, TLV-framed over TLS):** Remote→TV: `hello`(token/kind), `prepare`(songId prefetch hint), `selectSong`(songId,componentIndex?,lineIndex?), `next/prevComponent` (TV resolves arrangement order), `next/prev/jumpLine`, `setDisplayState`(.lyrics/.blackout/.logo/.frozen), `scroll`, `setAppearance`(theme/textScale), `endControl`, `ping`(5s). TV→remotes (broadcast to all connected): `state`, `ack`, `error`, `pong`, `capabilities`. **INVARIANT: content stays API-driven** — the LAN carries ~200-byte navigation intent + display state ONLY, never rendered lyrics; the TV fetches `song_detail` (or serves offline cache) itself. Latency: <100ms cached; a cold select adds one API RTT which `prepare` hides. Multiple remotes = **last-writer-wins** (all get `state`; commands by seq+timestamp); primary-operator soft-lock deferred. TV fully operable by Siri Remote alone (LAN is an overlay). Backgrounded remote = detached, TV holds state, <1s reconnect.

**2.4.5 VPN + network realities (owner-named, handled honestly):** VPN'd-in = *routable* (unicast works) but mDNS multicast (224.0.0.251) usually does NOT traverse a routed VPN → **expect connectivity without discovery**. So **manual "Connect by address" (IP/host + port, default TCP 7269) is a first-class v1 path** (full pairing/pinning applies; TV Settings shows its IP/port + standing QR to photograph). Remote caches last-good address per paired TV. **AP client-isolation** named honestly (blocks peer traffic even same-SSID → venue must use a non-isolated SSID/VLAN; in-app troubleshooter + deploy doc; final rung = server-projector fallback). Local Network permission prompt explained pre-fire.

**2.4.6 Live Follow / Service Mode (server, for congregants — UNCHANGED):** native implements the client sides — Live Follow join (#1268), Service Mode congregant `service_join`→`service_poll`→`service_leave` (presence token, CCLI unlock), unified 30s heartbeat / 180s freshness. Congregant poll cadence ~4s active / 15–30s idle. Optional **cheap-poll `service_poll?since=<stateVersion>`→204/304** + projector-role budget (server-declared `pollIntervalMs`) remains a nice-to-have backend ask for the server-projector/congregant path (NOT the LAN remote).

**2.4.7 Entitlements / backend / issues:** LAN remote needs `NSLocalNetworkUsageDescription` + `NSBonjourServices=["_ihymns-remote._tcp"]` + macOS sandbox `network.client` — **and DELIBERATELY AVOIDS the approval-gated `com.apple.developer.networking.multicast`** (declaring the specific service type + using NetworkBrowser/Listener stays in the un-gated path → removes a 2–6 week Apple dependency). **Backend: NONE** for the LAN remote (peer-to-peer); the optional congregant mirror reuses existing `service_broadcast`. **#1104** (cast-from-phone) same-room case = fulfilled by the LAN remote; what remains under #1104 = driving *server* sessions as a broadcaster (native `service-lead`). **#1115** splits: (a) tvOS LAN-controlled display = v1 here; (b) tvOS server-following projector (multi-site/overflow + the LAN-fail degradation rung) = server-mediated, stays #1115 P2–3. **Phasing: LAN remote is now Phase 2 v1** (ships with the tvOS shell): tvOS display→TVListener+pairing→iPhone/iPad remote→Mac/Vision→Watch relay→manual-connect/VPN→optional mirror. New issues: IHLive/LANRemote module; tvOS listener+pairing UI+trusted-remotes settings; remote UI (discovery/connect ladder/control surface); Watch relay; manual-connect + VPN troubleshooter + venue network doc; optional service_broadcast mirror; (parked) primary-operator lock; scope updates to #1104/#1115.

### 2.5 Backend/API asks surfaced by §2 (→ issues)
① broadcast payload adds `lineIndex`(nullable), `displayState`∈`live|blackout|logo` (VARCHAR #20), `stateVersion`(monotonic); ② cheap-poll `since`/304 + projector poll budget + server-declared `pollIntervalMs`; ③ session-scoped write-capable **control token** (delegated non-admin remotes, near #1339); ④ **APNs** (Live-Activity push first, live-state push later); ⑤ SIWA `auth_apple` (from §1); ⑥ AASA file (from §1); ⑦ CarPlay entitlement (Apple-side, file at start).

---

## 3. Distribution, Security, Quality & Delivery Plan

*(Fable 5 pass 3)*

### 3.1 App Store & TestFlight
- **ONE ASC record**: name iHymns, bundle `app.ihymns`, **Free**, no IAP. Universal Purchase automatic — attach iOS(+iPad+vision path)/macOS/tvOS/visionOS binaries; watch embedded in iOS. tvOS/watch = target display names, not listings.
- **App ID capabilities (register in P0):** Associated Domains (`applinks:{ihymns.app,www,beta,dev}` + `webcredentials:ihymns.app`); Sign in with Apple (+ reserve Services ID `app.ihymns.web`); App Groups `group.app.ihymns`; Keychain Sharing `app.ihymns.shared` (token `Synchronizable=true` = the global-login transport, no CloudKit); Push (`.p8`, dormant→P2); **CarPlay audio — apply NOW (weeks lead)**; Background Modes (audio, BGTask refresh); macOS App Sandbox (`network.client` only, hardened runtime). **Local Network** — `NSLocalNetworkUsageDescription` + `NSBonjourServices=["_ihymns-remote._tcp"]` for the LAN-direct TV remote (§2.4); **deliberately NOT** the approval-gated `com.apple.developer.networking.multicast` (architected around it). tvOS: GRDB = cache-only (purgeable).
- **Privacy/ATT — DECISION: no cross-app tracking, no IDFA, NO ATT prompt** ("Data Not Used to Track You"). Re-scopes #189: NOT GA4/Clarity SDKs — instead a **first-party consent-gated event ping**, default OFF, Settings toggle mirroring web consent, synced with account. Label: email/userID/user-content = Linked (functionality); usage = Not-linked aggregate (only if consent on); no third-party crash SDK. Anon congregant presence token = not collected (no identity, no location).
- **TestFlight/env mapping** (3 docroots share ONE MySQL → env selects API *code version*): Debug→dev; Internal TF→beta (+ in-app env picker, TestFlight-only, compiled out of App Store); External TF (per-platform groups)→prod; App Store→prod. **Delivery gate: any backend feature the build needs must be promoted alpha→beta before internal TF, beta→prod before external TF/App Store** — the web promotion pipeline is now on the Apple critical path. External groups need Beta App Review (+1–2 days); `pilot` lane refreshes internal builds ≥monthly (90-day expiry).
- **App Review risk register (decided mitigations):** SIWA REQUIRED (4.8, we offer email/pw) → SIWA is a P1 blocker, equal prominence; 4.2 min-functionality → zero WKWebView in core, native help, edge features in the reviewed binary; 5.2 lyrics IP → rights statement in review notes (majority PD; copyrighted behind dormant tier/CCLI; takedown contact); **5.1.1(v) account deletion REQUIRED → new `account_delete` backend endpoint (P1 blocker — was missing from earlier asks)**; private-relay email → backend accepts `@privaterelay.appleid.com` + register SIWA email domain SPF/DKIM; Service Mode not demo-able → demo account + long-lived demo service session + screen recording in notes; free/no-IAP → no purchase links. Metadata/screenshots in `appApple/fastlane/metadata|screenshots/` via `deliver`; sets for iPhone 6.9″/iPad 13″/Mac/TV 4K/Vision/Watch.

### 3.2 Security & Privacy
- **Token custody:** Keychain only (`AccessibleAfterFirstUnlock`, `Synchronizable=true`, group `app.ihymns.shared`); sent as **Bearer header** (never cookie/URL from native → stays out of access logs); IHAPI redaction middleware strips `Authorization`/`token`/`code`/`sig`; **sign-out = server-revoke THEN delete synchronizable item** (else iCloud resurrects a revoked token).
- **Deep-link validation:** host allowlist; typed route enum only; SongId shape-checked (#27) before fetch; the one auth-bearing link (Safari-handoff code) is exchanged, never stored.
- **ATS = default, NO cert pinning** (shared DreamHost auto-rotating LE certs; a pin would brick an offline fleet with no fast remediation). Compensate: ATS strict + HSTS + Bearer-assumes-TLS.
- **New-endpoint security (→ acceptance criteria on each backend issue):** `auth_apple` — verify JWT (JWKS RS256 + kid-refetch, `iss`/`aud=app.ihymns`/`exp`) **+ nonce** (client sends `sha256(nonce)` to Apple, raw to us, we check) to kill replay; `sub`→`tblUserAuthProviders`; link-to-existing needs current bearer; SIWA client is PUBLIC (no secret in app); the `.p8` client-secret-JWT only for server→Apple token/revocation (account delete). **Device-code pairing** = RFC 8628 (opaque hashed device_code, 8-char Crockford user_code, 5-min single-use, `/link` approval CSRF-gated showing device model+geo, poll `interval`≥5s + `slow_down`, rate-limit **per code/client not per IP** #26). **Safari handoff** = mint NEW one-time code (60s, single-use, hashed, user-bound); NEVER reflect the existing `ihymns_auth` cookie. **Control token** = scope one SessionId, cap `broadcast`, expire at session end, channel-filtered. **APNs `.p8` outside docroot**, PHP signs ES256, push tokens deleted on sign-out/410. **`account_delete`** = re-auth + cascade/anonymise + Apple SIWA revocation + revoke all tokens.
- **No secrets in bundle (CI grep gate).** ASC key/match passphrase = GitHub secrets via **web UI** (owner sets `ASC_KEY_ID`/`ASC_ISSUER_ID`/`ASC_KEY_P8`/`MATCH_PASSWORD`/`MATCH_GIT_URL`); Claude never runs `gh secret set`. **No location permission anywhere** (presence = rotating code, #26).
- **dev-team-security: two SCOPED audits** (exclude the done web #1386 surface): **Audit A** (post-P1) = Swift client (Keychain, deep-link parse, offline-cache poisoning, token/iCloud-revocation ordering, redaction) + new PHP (`auth_apple`, `account_delete`, AASA). **Audit B** (post-P2) = device-code brute/replay/slow_down, handoff mint reflection/TTL, control-token confinement, broadcast/poll NAT limits, APNs key. Findings→issues→fix→re-attack before the gate.

### 3.3 Quality, Testing, Accessibility, i18n
- **Test pyramid:** iHymnsKit unit tests (Swift Testing, macOS host, per-commit; test target per module at scaffold); **contract tests** decoding committed live fixtures (`appApple/Tests/Fixtures/`, recorded from dev by `tools/apple-refresh-fixtures.sh`, tokens scrubbed — the drift alarm, refresh per backend-issue landing + phase gate); **snapshot tests** (`swift-snapshot-testing` pinned) over IHDesign × theme(light/dark/HC/CVD) × Dynamic Type × size class × platform; **UI tests** on 4 journeys (SIWA+email sign-in, search→song, project-a-service, join-a-service) against a stub local server; **concurrency** = Swift 6 strict (compile-time) + a TSan CI run + injected-clock tests for IHLive cadence/freshness (no wall-clock sleeps).
- **A11y (WCAG parity):** VoiceOver (lyric verse/chorus as rotor headings; badges in accessible names #680/#856); Dynamic Type to accessibility sizes (intrinsic layout); Reduce Motion→crossfades, Reduce Transparency→opaque glass, Increase Contrast honoured; CVD palettes as IHDesign token sets synced to account; `performAccessibilityAudit()` in UI tests + Accessibility Inspector per gate.
- **i18n:** String Catalogs per module (UI chrome only — content stays source-language with native language badges; P3 Translation feature is user-invoked, labelled machine-translation); v1 English-only but catalog-clean (SwiftLint bans hardcoded literals), pseudo-loc+RTL smoke at P1 gate; `FormatStyle` everywhere.
- **Static gates (all land P0):** SwiftLint + swift-format + the **LOC-budget guard** (~400 lines/file = the native "extract first" modularity tripwire) + no-secrets grep.

### 3.4 Phased Roadmap + GitHub Issue Map
Conventions: branch **`feat/apple-universal`** (from `alpha`), **NO PRs**; **one issue per task** (#180–#190 UPDATED in place, keep numbers/history); **one commit per task** → its issue; epic **#895** gains a checklist; #1104/#1115 re-scoped into P2. Milestone **"Apple v1.0"**; labels `platform:apple`, `apple-phase-0..4`, `backend-for-apple`. Tier: **H**=Haiku, **S**=Sonnet (default), **O**=Opus/deep-architect (only where flagged).

**Phase 0 — Skeleton (everything builds, one real screen):** (1) XcodeGen project — 3 shells+widgets, OS26, Swift6 strict [S]; (2) iHymnsKit scaffold — 8 module+test targets [S]; (3) quality gates — SwiftLint/format/LOC/no-secrets [H]; (4) IHModels + first contract fixtures (songs_index/song_detail/songbooks) [S]; (5) IHAPI actor — Bearer/X-Requested-With/ETag/retry/env + redaction [S]; (6) IHAuth — Keychain vault + auth_login + revocation ordering [S]; (7) **E2E slice — song list→detail (iOS+macOS)** [S]; (8) Fastlane — match/ASC-key/versioning/TF-internal + secrets runbook [H]; (9) Ops runbook — App ID capabilities + **CarPlay application submitted** [H]; (10) **Backend: AASA** on all 3 docroots [H].

**Phase 1 — Parity core** (gate: Audit A + a11y + fixtures refresh): #180 song display (lyrics/arrangement/chorus-italic/chords/per-line enrichment/credits/works) [S]; search text+number [S]; songbooks browse (badge/DisplayAbbr/series/Collections) [S]; #183 SOTD hemisphere/country [H]; #181 favourites+setlists (sync/reorder/tags/LIVE-share/schedule/templates) [S]; #182 settings (themes/CVD/account/consent/env-picker) [S]; **#187 offline — GRDB+FTS5 + bulk download + staleness** [S build via orchestrator; O reviews the sync-merge design]; #184 audio+PDF/MusicXML (player/NowPlaying/AirPlay/signed-URL) [S]; #185 nav+UX+keyboard [S]; **Backend: auth_apple SIWA + tblUserAuthProviders** [S]; SIWA client (linking/private-relay) [S]; **Backend: account_delete** [S]; account-deletion flow [H]; #186 sharing+deep links [S]; #188 accessibility pass [S]; #189 analytics (first-party consent, NO ATT) [H]; #190 help/legal/first-run (no WKWebView) [H]; **gate: dev-team-security Audit A**.

**Phase 1.5 — Native-edge MVP:** Widgets (SOTD/favourites/setlist) [S]; App Intents/Siri/Spotlight [S]; Handoff (incl. web↔app URL map) [S]; **external-display projection** iOS/iPad/Mac [S]; Watch app (browse/favourites/complications/offline) [S]; macOS niceties (menu bar/Touch Bar/Quick Look/drag-drop) [H]; **Backend: Safari-session handoff mint+exchange** [S]; handoff client [S]; **Process: dev-team-featurefind → FEATURES.md triage**.

**Phase 2 — Live + LAN remote** (gate: Audit B + multi-device verify): **Backends first (congregant path only)** — broadcast payload v2 (lineIndex/displayState/stateVersion) [S]; cheap-poll since/304 + projector budget [S]; tvOS device-code endpoints + /link page [S]; session control token [S]; token/device metadata [S]. **LAN-direct TV remote (NO backend, §2.4 revised):** IHLive/LANRemote module — IHRP/1 enum + framing + TVListenerActor + RemoteSessionActor [S, **O reviews the actor/protocol design once**]; tvOS listener + pairing ceremony (code+QR, cert-pin) + trusted-remotes settings + Settings→Remote-Control info (IP/port/QR) [S]; remote UI (discovery list + connect/reconnect ladder + control surface, iPhone/iPad/Mac/vision) [S]; Watch relay (WatchConnectivity → iPhone RemoteSessionActor) + reduced control set [S]; manual connect-by-address + VPN/AP-isolation troubleshooter + venue network doc [S]; optional Service-Mode mirror (mirror-on-ack via existing `service_broadcast`) [H]. **Server-congregant clients:** Live Follow host+join (native `service-lead` half of #1104) [S]; Service Mode congregant [S]; **tvOS server-following projector (#1115b)** [S via orchestrator]. **Backend: APNs bridge** [S]; Live Activities + Dynamic Island [S]; **gate: dev-team-security Audit B (adds: LAN pairing brute/MITM, IHRP/1 fuzz, VPN reachability) + multi-device live-verify matrix**.

**Phase 3 — Differentiators:** visionOS Lyric Space [S via orchestrator]; CarPlay audio [S]; SharePlay group-sing [S]; Translation overlay [H]; perf/polish (Instruments) [S]; **Process: featurefind re-run + loop-closure check**.

**Phase 4 — Submission:** privacy labels+metadata+screenshots [H]; external TestFlight per-platform + Beta Review + demo account/session [H]; App Review dossier (rights/moderation/notes/video) [H]; staged release (iOS→macOS→tvOS→vision, phased rollout) [H]; **docs sweep — Wiki + DEV_NOTES/LICENSING/Project_plan/PROJECT_STATUS/README/SECURITY + .claude/ + handoff** [H].

### 3.5 dev-team plugin execution process
Reconcile "full use of dev-team" with "Sonnet/Haiku-first": **suite = where multi-agent adversarial value justifies Opus** (research/attack/verify + 3 cross-cutting builds); **mechanical majority = direct Sonnet/Haiku agents** (Agent tool `model:` override), same one-issue/one-commit discipline, session as team lead.
- **dev-team-featurefind** — P1.5 exit + P3 exit → scored `FEATURES.md` gap ledger (owner req #12 discovery).
- **dev-team-orchestrator** — exactly 3 invocations: #187 offline sync, tvOS projector, visionOS Lyric Space (multi-module/correctness-critical). Everything else = direct Sonnet/Haiku.
- **dev-team-security** — Audit A (post-P1), Audit B (post-P2), scoped.
- **dev-team-review** — every phase gate (0→4): verify done-criteria/claims before closing.
- **dev-team-autopilot — RESERVED, not conducting the epic** (conflicts with Sonnet-first + per-task issue/commit + owner decision gates). Optional later: one bounded "P3 polish only" mission.
- **The loop (req #12):** featurefind → triage gaps→issues → build backlog (Sonnet/Haiku; orchestrator for the 3) → security (audit points) → review (gate) → fix → next phase … **until featurefind yields no new important gaps AND review passes a gate with zero unverified claims + zero open findings.** Suite artifacts under `appApple/.dev-team/`; HANDOFF after every session; ProjectBrief/memory/#895-checklist after every gate.

### 3.6 Definition of Done (v1.0) + First 5 actions
**DoD:** one ASC record (`app.ihymns`) released for iOS/iPadOS/macOS/tvOS/visionOS + embedded watch; web-parity floor green (§2 matrix); native edge shipped (widgets/intents/Handoff/NowPlaying/watch+complications+remote/Live-Activities/external+tvOS projection/visionOS space); **live E2E across ≥3 real devices** (host→congregant→tvOS projector→phone/watch remote); **global login proven** (iCloud-Keychain propagation + Safari-handoff + sign-out-revokes-everywhere); SIWA + in-app account deletion live on prod; all backend-for-apple endpoints promoted to prod (schema.sql + migration cards run); Audits A+B closed; contract fixtures current + snapshot/UI/TSan green; a11y audits + String-Catalog-clean; privacy label accurate (no ATT); every §3.4 issue closed w/ SHA+evidence; #895 checklist complete; P4 docs sweep done; final HANDOFF written.

**First 5 actions when execution starts:** (1) cut `feat/apple-universal` from alpha + "Apple v1.0" milestone/labels + create P0 issues + update #895/#180–#190/#1104/#1115 (Haiku); (2) owner portal runbook FIRST (capabilities checklist + CarPlay application + GitHub-secrets web-UI list) so long-lead deps start ticking; (3) build P0-1+P0-2 (XcodeGen + iHymnsKit scaffold, Sonnet) to green multi-platform CI; (4) land quality gates P0-3, then P0-4/P0-5 with first dev-API contract fixtures; (5) ship E2E slice P0-7 (list→detail, iOS+macOS, live alpha API) → dev-team-review as P0 gate → write HANDOFF → open P1 with #180.

---

## 4. Additional requirements (owner, 2026-07-04) — additive to the above

### 4.1 Paid tiers (once-off + subscription) + Apple Family Sharing — FUTURE layer, architected-for NOW
Monetisation is a **later** layer, but the v1 architecture must not preclude it. Decisions:
- Reserve an **`IHStore`** iHymnsKit module (StoreKit 2: `Product`, `Transaction.currentEntitlements`, `Transaction.updates` listener, `AppTransaction` for the free-tier baseline). Empty/dormant in v1 — one place to add products later, mirroring how the web tier system (`tblAccessTiers`/`TIER_CAPS`) is a registry.
- **Entitlement mapping:** a StoreKit purchase → a **server-side account entitlement** (the app POSTs the signed transaction/`appAccountToken` to a FUTURE `?action=iap_verify` endpoint that verifies with Apple's App Store Server API and grants the tier on the account; the account's tier then flows through the EXISTING `access_tiers` caps + `contentGatingApply` the web already has). **StoreKit is the purchase rail; the server tier system is the source of truth** — never gate content purely client-side (mirrors web rule #28). Keep the free public-domain catalogue free; paid = premium features/licensed content, gated by the same dormant `content_gating` machinery.
- **Family Sharing: ON** (App Store Connect → Pricing → Family Sharing) for both non-consumable once-off unlocks and subscriptions — enable at record-creation so it's never a migration. StoreKit surfaces `Transaction.ownershipType == .familyShared`; entitlement grant honours it.
- Types: **once-off = non-consumable**; **subscription = auto-renewable** (a subscription group). Both family-shareable. No consumables planned.
- Backend future-ask (not v1): `iap_verify` + `iap_notifications` (App Store Server Notifications v2 webhook) — file when the paid layer is scheduled; not on the v1 critical path.

### 4.2 Accessibility modes — light / dark / colour-blind / dyslexia-friendly (toggle)
Extends strategy §3.3.2. IHDesign carries these as token sets selected in **Settings → Accessibility** (synced to the account preference like the web):
- **Light / Dark** — follow system by default + manual override (parity with web).
- **Colour-blind modes** — the web's CVD palettes as IHDesign token sets (protan/deutan/tritan-safe accents), already planned.
- **Dyslexia-friendly reading mode (explicit toggle, default off):** swaps `IHDesign.LyricTypography` + reading tokens to: a **dyslexia-oriented typeface** (bundle **OpenDyslexic**, SIL OFL — licence-clean, note in LICENSING.md; offer the system option too), **increased letter + word + line spacing**, **left-aligned never justified**, a **warm off-white / low-blue background** option, slightly heavier weight, and generous paragraph gaps. Scope: primarily the lyric/reading surfaces (the reading task); UI chrome stays SF. One `Environment(\.ihReadingMode)` value drives it so every reading view responds. Verify it composes with Dynamic Type + high-contrast in the snapshot matrix. This is a **native-edge differentiator** (the PWA doesn't offer a bundled dyslexia font toggle) — add to the §2.3 edge list.

### 4.3 CarPlay as a "TV" screen (owner insight) — stretch use
CarPlay now permits video while the vehicle is **stationary**. The tvOS **projector view** (§2.2, the auto-fitting karaoke lyric canvas) is already a reusable SwiftUI surface — it could render onto a CarPlay display so a car screen acts like the projected 'TV' (e.g. a small group singing in a vehicle). This needs the **CarPlay video/entertainment entitlement** (more restricted than CarPlay-audio; stationary-gated) — noted on the CarPlay application (runbook §B) as a stretch use. Phase 3+, gated on the grant; the projector view being platform-agnostic means near-zero incremental UI.

### 4.4 Deployment automation — branch → destination (build in P0)
`.github/workflows/apple-deploy.yml` (uses the existing org Apple secrets — **direct cert import via `APPLE_CERTIFICATE`/`APPLE_CERTIFICATE_PASSWORD`/`APPLE_SIGNING_IDENTITY`, not `match`**): push to **`alpha`** → Fastlane `alpha` → TestFlight Internal; push to **`beta`** → `beta` → TestFlight External (per-platform groups); push to **`main`** → `release` → App Store (staged rollout). PRs → build+test only. Lands with the P0 Fastlane lanes (won't be green until the project exists). ASC upload via `ASC_API_KEY`/`ASC_ISSUER_ID`/`ASC_KEY_ID` (confirm the `.p8` body is a secret — owner adds via web UI if missing). NOTE: this is the **native-app** pipeline, separate from the existing web SFTP deploy; the Apple branch `feat/apple-universal` still has NO PRs until owner says — these branch triggers activate once the Apple work merges toward alpha/beta/main later.
