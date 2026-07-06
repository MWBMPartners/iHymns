# Apple Native Universal App — Crash-Safe HANDOFF

> Resume pointer so a fresh session can pick up WITHOUT re-planning. Last updated 2026-07-04.

## Where we are
**PLANNING COMPLETE. Execution NOT started.** Awaiting owner approval to begin Phase 0.

- Full plan: **`.claude/apple-native-strategy.md`** (§1 Foundation/Architecture · §2 Features/UX/Native-edge/Remote · §3 Distribution/Security/Quality/Roadmap/dev-team). Planned with **Fable 5**, 3 sequential passes (2026-07-04).
- Context brief used for planning (scratchpad, may be gone after session): the distilled product/API/requirements. Regenerable from `.claude/ProjectBrief.md` + `api-docs.yaml` + this doc.
- Epic = **#895** (Apple rebuild-from-scratch). Sub-issues **#180–#190** = original Phase-1 spec (to be UPDATED in place, not recreated). #1104 (cast-from-phone) + #1115 (tvOS projection) re-scope into Phase 2.
- The existing `appApple/` (18 loose pre-reset Swift files, no xcodeproj) is **to be scrapped** (owner-approved clean slate).

## The load-bearing decisions (don't relitigate)
- **One XcodeGen project** under `appApple/`; 3 app shells — **iHymns** (iOS/iPadOS/macOS-native/visionOS), **iHymns TV** (tvOS), **iHymns Watch** (watchOS, embedded in iOS) — + Widgets/Live-Activity extension. **Bundle id `app.ihymns` on every platform** (that's what makes universal purchase work). `app.ihymns.pwa` = PWA; `app.ihymns.apple` NOT needed.
- **~90% of code in one SwiftPM package `iHymnsKit`**: IHModels · IHAPI (actor, `Authorization: Bearer` + `X-Requested-With`) · IHAuth (Keychain token, **iCloud-Keychain global login**, SIWA, tvOS device-code pairing, Watch mirror) · IHPersistence (**GRDB + FTS5**) · IHLive (LiveFollow/ServiceMode actors) · IHDesign (Liquid Glass) · IHFeatures (shared SwiftUI + `@Observable` VMs) · IHAppSupport (DeepLinkRouter + Handoff). Shells only compose, never reimplement (LOC-budget CI guard).
- **Swift 6 strict concurrency; min OS = 26** (Liquid Glass native; pre-26 → the PWA).
- **API-native, NO whole-corpus**: `songs_index` + per-song `song_detail` + per-book `songs?abbr=`; offline via `bulk_songs`/`bulk_audio` only.
- **TWO DISTINCT remote features (owner correction 2026-07-06):** (a) **Native TV remote control = LAN-DIRECT, v1 Phase 2** — phone/iPad/Mac/Watch ↔ tvOS on the same LAN (VPN-into-LAN counts), peer-to-peer Bonjour `_ihymns-remote._tcp` + Network.framework TLS, sub-100ms, **NO backend**, security = an on-TV pairing ceremony (code+QR, cert-pinned, per-device trust NOT iCloud-synced). Watch relays via the paired iPhone (WatchConnectivity). Protocol IHRP/1 = navigation intent only; content stays API-driven (TV fetches `song_detail`). MultipeerConnectivity rejected (no watchOS; can't reach a VPN'd-in device). VPN mDNS-may-not-traverse → manual "connect by IP" is a first-class path. **Avoids the approval-gated multicast entitlement.** (b) **Live Follow #1268 / Service Mode #1335 = SERVER-mediated, for remote congregants** — native implements the client (join/poll/presence), unchanged. Optional: the operator's remote mirrors LAN-driven TV state to the server via existing `service_broadcast` (mirror-on-ack). Full design = strategy §2.4 (REVISED).
- **Global login = iCloud-Keychain synchronizable token**; tvOS = device-code pairing (interim = existing email 6-digit code); Watch = WatchConnectivity token mirror. Sign-out revokes server-side THEN deletes the sync'd item.
- **Deep links = Universal Links** on canonical web URLs (needs AASA on ihymns.app); Handoff via `NSUserActivity(webpageURL:)` (degrades to PWA when app absent).
- **Native-edge MVP**: Now Playing/AirPlay, Handoff, Widgets, App Intents/Siri/Spotlight, Watch complications, Quick Look/drag-drop. Fast-follow: external-display projection, Live Activities/Dynamic Island.
- **Versioning**: `MARKETING_VERSION = <webMajor>.<appleMinor>.<patch>` (webMajor from `infoAppVer.php`), build = UTC timestamp `YYYYMMDDHHmm`. Fastlane + ASC API key + `match`.
- **No ATT / no cross-app tracking**; first-party consent-gated analytics (re-scopes #189). **No cert pinning** (shared-host LE certs).

## Backend/API changes this epic needs (each = its own `backend-for-apple` issue; must be promoted alpha→beta→prod on the Apple critical path)
1. **AASA** file `/.well-known/apple-app-site-association` on all 3 docroots (P0).
2. **`auth_apple`** SIWA (JWKS+nonce+aud verify) + **`tblUserAuthProviders`** (VARCHAR Provider #20, schema.sql + migration card #19) (P1).
3. **`account_delete`** (re-auth + cascade/anonymise + Apple SIWA revocation) — App Review 5.1.1 blocker (P1).
4. **Safari-session handoff** — mint NEW one-time code, never reflect the cookie (P1.5).
5. Broadcast payload v2: `lineIndex`/`displayState`(VARCHAR)/`stateVersion` (P2).
6. Cheap-poll `service_poll?since=<stateVersion>`→204/304 + projector poll budget + server-declared `pollIntervalMs` (P2).
7. tvOS **device-code pairing** endpoints + `/link` approval page (RFC 8628, per-code rate-limit #26) (P2).
8. Session **control token** (scoped `broadcast`, near #1339) (P2).
9. Token/**device metadata** on `tblApiTokens` + "manage devices" (P2).
10. **APNs bridge** (`.p8` outside docroot, ES256, HTTP/2) for Live Activities (P2).
11. CarPlay entitlement application — **Apple-side, file at project START** (weeks lead).

## Execution process (owner constraints)
- Branch **`feat/apple-universal`** off `alpha`; **NO PRs** until owner says. One issue per task; one commit per task→its issue; close with SHA+evidence.
- Build with **Sonnet/Haiku** (Opus/dev-team only where flagged); **dev-team suite**: featurefind (P1.5+P3 exit), orchestrator (ONLY #187 offline / tvOS projector / visionOS space), security (Audit A post-P1, Audit B post-P2), review (every phase gate). **autopilot reserved** (not conducting the epic).
- Loop until: featurefind yields no new important gaps AND a review gate passes with zero unverified claims + zero open findings.
- Docs sweep (Wiki + DEV_NOTES/LICENSING/Project_plan/PROJECT_STATUS/README/SECURITY + .claude/) = P4 final task.

## NEXT ACTION (when owner approves)
Execute **First 5 actions** from strategy §3.6:
1. Cut `feat/apple-universal`; "Apple v1.0" milestone + labels; create Phase-0 issues; update #895/#180–#190/#1104/#1115.
2. Owner portal runbook FIRST (App ID capabilities §3.1.2 + CarPlay application + GitHub-secrets web-UI names).
3. Build P0-1 (XcodeGen project) + P0-2 (iHymnsKit scaffold) → green multi-platform CI.
4. P0-3 quality gates, then P0-4/P0-5 (IHModels + contract fixtures + IHAPI actor).
5. P0-7 E2E slice (song list→detail, iOS+macOS, live alpha API) → dev-team-review P0 gate → HANDOFF → open P1 with #180.

## 2026-07-04 additions (owner) + status
- **New requirements folded into strategy §4** (additive, no re-plan): (4.1) **paid tiers once-off+subscription via StoreKit 2 + Apple Family Sharing** — FUTURE layer, reserve an `IHStore` module, purchases→server entitlement via a future `iap_verify` endpoint→existing `access_tiers` caps; Family Sharing ON at record creation. (4.2) **Accessibility modes: light/dark/CVD + a DYSLEXIA-FRIENDLY reading toggle** (bundle OpenDyslexic SIL-OFL → note in LICENSING.md; increased spacing, left-align, off-white; `Environment(\.ihReadingMode)`; a native-edge differentiator). (4.3) **CarPlay-as-TV** (stationary video) — the tvOS projector view could render on a CarPlay screen; needs the CarPlay video entitlement (stretch on the CarPlay application). (4.4) **Deploy automation** `apple-deploy.yml`: alpha/beta→TestFlight, main→App Store, using existing org secrets (direct cert import, NOT match).
- **CodeQL config error FIXED** (2026-07-04): repo used default setup with deprecated standalone `javascript`+`typescript` identifiers; reconfigured → the real languages `javascript-typescript`+`python`+`actions` all analyze clean; a triggered re-run succeeded. If the banner recurs on a scheduled run, switch to an advanced `.github/workflows/codeql.yml` pinning those 3 languages.
- **Owner runbook written**: `.claude/apple-native-owner-runbook.md` — the long-lead OWNER actions (App ID capabilities, ⏳ CarPlay entitlement, ASC record + Family Sharing, SIWA email domain, secrets mapping, backend-promotion note, deploy-automation design). GitHub org already has the Apple secrets (APPLE_CERTIFICATE/…/ASC_API_KEY/ISSUER/KEY_ID) → use direct-cert-import Fastlane, not match.
- **Planning docs committed** to branch `feat/apple-universal` (see git log). Execution (the Swift build) is the NEXT step — deferred here only because the session hit its usage cap mid-work; resume with strategy §3.6 First-5-actions.

## Fable usage note
Planning used 4 Fable-5 agents sequentially (~75k+73k+79k+67k subagent tokens; ~31% weekly Fable used as of 2026-07-04). If continuing planning/design, try Fable first, fall back to Opus 4.8 if capped. Implementation must use Sonnet/Haiku (Opus only where flagged in §3.4/§3.5). **Watch the 5-hour session cap** — the 2026-07-04 session hit 93%.
