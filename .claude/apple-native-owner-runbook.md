# Apple Native App — OWNER Runbook (long-lead actions, start early)

> Step-by-step for the items that need **you** (Apple portals + GitHub) — the things a coding agent cannot do and that have multi-week external lead times. Do the **⏳ long-lead** ones FIRST. Companion to `.claude/apple-native-strategy.md`.
>
> Team ID lives in the `APPLE_TEAM_ID` GitHub org secret. Bundle id = **`app.ihymns`** everywhere (this is what makes the single universal purchase work).

---

## A. Apple Developer portal — App ID + capabilities (do in Phase 0)
**developer.apple.com → Certificates, Identifiers & Profiles → Identifiers → +**

1. **Register the primary App ID** `app.ihymns` (Explicit, not wildcard). Enable these capabilities (check the boxes):
   - **Associated Domains** (Universal Links + shared web credentials + Handoff)
   - **Sign in with Apple** (native login front door — App Review will require it)
   - **App Groups** — create `group.app.ihymns` (shared data for widgets/Live-Activity/watch)
   - **Push Notifications** (dormant until Live Activities; enabling now avoids a re-provision later)
   - **iCloud** (reserve `iCloud.app.ihymns` — Keychain sync is automatic, no container needed, but reserve it)
   - **CarPlay** — see **B** (separate application)
   - **In-App Purchase** — enable now so the future paid-tier layer (§ new-requirements) needs no re-provision
2. **Register the embedded child App IDs** (auto-suffixed, internal plumbing — not products):
   - `app.ihymns.watchkitapp` (watch app), `app.ihymns.widgets` (widgets/Live-Activity extension)
3. **Reserve a Services ID** `app.ihymns.web` (for a FUTURE "Sign in with Apple" button on the PWA — reserve only, don't configure yet).
4. **Keychain Sharing access group** is derived from the App ID + Team prefix — nothing to register; the app declares it in entitlements.

## B. ⏳ CarPlay entitlement — APPLY NOW (weeks-to-months Apple approval)
CarPlay entitlements are **request-gated by Apple** (a form, not a checkbox) and are the single longest external dependency. Two uses for iHymns:
1. **CarPlay Audio** (`com.apple.developer.carplay-audio`) — browse favourites/setlists + play hymn audio in the car. This is the v1 CarPlay target.
2. **CarPlay video-as-a-TV-screen** (your insight — CarPlay now allows video while the vehicle is stationary): the tvOS *projector* pattern could render lyrics onto a CarPlay display. This needs the (even more restricted) CarPlay **video/entertainment** entitlement — note it as a *stretch* use on the same application so it's on record; expect it to be harder to get and gated to stationary-only.

**Steps:** developer.apple.com → **Contact → CarPlay** (or the "Request CarPlay Entitlements" form) → select CarPlay Audio (primary) → describe iHymns (a worship-song catalogue; audio playback of hymns; optional stationary lyric display). Submit under the `MWBMPartners` team. **Do this on day one of Phase 0** — Phase 3 CarPlay work must not wait on the grant.

## C. Apple Developer portal — Sign in with Apple email relay (before external TestFlight)
For private-relay (`@privaterelay.appleid.com`) users to receive our magic-link/notification email:
1. **Certificates, IDs & Profiles → More → Sign in with Apple for Email Communication** → register the sending domain (the domain the iHymns backend sends mail from) → add the **SPF** + **DKIM** DNS records Apple gives you to that domain's DNS.

## D. App Store Connect — the app record (before first TestFlight)
**appstoreconnect.apple.com → Apps → +**
1. **New App**: Platforms = iOS, macOS, tvOS, visionOS (watchOS ships embedded — don't add separately); Name **iHymns**; Primary language English; Bundle ID `app.ihymns`; SKU `app.ihymns`.
2. **Pricing**: Free (Universal Purchase is automatic across the platforms once they share the bundle id).
3. **Family Sharing**: **turn ON** (App Store Connect → App → Pricing and Availability → Family Sharing) so the future paid tiers/subscriptions are family-shareable (§ new-requirements).
4. **App Privacy**: "Data Not Used to Track You" (no ATT); declare email/user-content as *linked, functionality*; usage as *not-linked, analytics* (consent-gated). Full label content is in strategy §3.1.3.
5. **TestFlight groups**: create an **Internal** group (your devices) + **External** groups per platform (iOS, macOS, tvOS, visionOS). External groups need Beta App Review on the first build of each version (+1–2 days).

## E. GitHub Actions secrets — MOSTLY ALREADY PRESENT ✅
Your org already has the Apple deployment secrets (verified in the org secrets screenshot). Mapping to the deploy pipeline:

| Secret (exists) | Used for |
|---|---|
| `APPLE_ID`, `APPLE_PASSWORD` | Apple ID + app-specific password (altool/notarization fallback) |
| `APPLE_TEAM_ID` | Team identifier for signing |
| `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD` | Distribution cert (.p12) imported into the CI keychain — **so we use direct-cert-import in Fastlane, NOT `match`** (simpler; no separate certs repo needed) |
| `APPLE_SIGNING_IDENTITY` | The signing identity name |
| `ASC_API_KEY`, `ASC_ISSUER_ID`, `ASC_KEY_ID` | App Store Connect API key → headless TestFlight/App Store upload (`pilot`/`deliver`) |

**Likely still needed (add via the GitHub web UI → Settings → Secrets and variables → Actions → New secret only if a build reports them missing):**
- `APPLE_ASC_KEY_P8` (the actual `.p8` key CONTENTS) — if `ASC_API_KEY` holds the key id rather than the file body, add the `.p8` contents as its own secret. *(I'll confirm which during Phase 0 and tell you the exact name + value to paste — per your preference I won't run `gh secret set` myself.)*
- Provisioning profiles are generated at build time via the ASC API key, so no profile secret is typically needed.

## F. ⚠️ Backend work is on the Apple critical path (I build it; you run + promote)
These native features depend on new backend endpoints. I implement them on the Apple branch, but **you** run the migration cards on `/manage/setup-database` and promote alpha→beta→prod (a native build can't use a feature until its backend is on that build's docroot). Order of need: **AASA file** (P0), **`auth_apple` SIWA + `tblUserAuthProviders`** + **`account_delete`** (P1 — both App-Review blockers), Safari-handoff mint (P1.5), broadcast-payload v2 + cheap-poll + device-code + control-token + token/device-metadata + APNs bridge (P2). Full list + security specs in strategy §1.9/§2.5/§3.2.4. **The LAN-direct TV remote needs NO backend.**

## G. Deployment automation (I build the workflow in Phase 0; branches → destinations)
Per your instruction: **alpha + beta branches → TestFlight, main → App Store.** The `.github/workflows/apple-deploy.yml` I create in Phase 0 will (using the E secrets):
- push to **`alpha`** → Fastlane `alpha` lane → build + upload to **TestFlight Internal**.
- push to **`beta`** → Fastlane `beta` lane → **TestFlight External** (per-platform groups).
- push to **`main`** → Fastlane `release` lane → **App Store** submission (staged rollout).
- PRs → build + test only (no upload).
It won't run green until the Phase-0 XcodeGen project + Fastlane lanes exist; that's expected — the workflow lands with them.

---

### Your immediate long-lead checklist (do these first)
- [ ] **B — Apply for CarPlay entitlement** (longest lead; do today)
- [ ] **A — Register App ID `app.ihymns` + capabilities** (unblocks all provisioning)
- [ ] **D — Create the App Store Connect record** + turn on **Family Sharing**
- [ ] **C — Sign in with Apple email domain** SPF/DKIM (before external TestFlight)
- [ ] **E — Confirm the ASC `.p8` key is available as a secret** (I'll tell you the exact name during Phase 0)
