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

---

# DETAILED STEP-BY-STEP (the long-lead items, click-by-click)

> Sign in to **developer.apple.com** and **appstoreconnect.apple.com** with the **MWBM Partners** Apple Developer account (must have Admin/Account-Holder role to register identifiers + request entitlements).

## ① CarPlay entitlement application — DO FIRST (longest Apple turnaround)
1. Go to **developer.apple.com** → sign in → top menu **Support** → search **"CarPlay"**, or go directly to **developer.apple.com/carplay/** → scroll to **"Request CarPlay entitlements"** (or **Account → Contact Us → Development and Technical → CarPlay**).
2. Choose entitlement type: **CarPlay Audio App** (primary — hymn audio playback). *(If the form lets you note additional intended capabilities, add a sentence about a possible future stationary lyric/video display so the video/entertainment use is on record — but do NOT block the audio request on it.)*
3. Fill the form:
   - **App name:** iHymns
   - **Bundle ID:** `app.ihymns`
   - **Team:** MWBM Partners (Team ID = value in the `APPLE_TEAM_ID` GitHub secret)
   - **Category:** Audio
   - **Description:** "iHymns is a worship-song / hymn catalogue. The CarPlay audio app lets a driver browse their favourites and setlists and play hymn audio hands-free via the car display, using standard CPListTemplate + CPNowPlayingTemplate."
4. Submit. **Expected turnaround: 2–6 weeks (sometimes longer), not guaranteed.** You'll get an email; the entitlement then appears to add in the App ID (step ②-6). **Nothing else waits on this** — the app ships without CarPlay and adds it in Phase 3 once granted.

## ② Register the App ID `app.ihymns` + capabilities
1. **developer.apple.com** → **Account** → **Certificates, Identifiers & Profiles** → left sidebar **Identifiers** → blue **⊕** (add) button.
2. Select **App IDs** → **Continue** → type **App** → **Continue**.
3. Fill:
   - **Description:** `iHymns`
   - **Bundle ID:** select **Explicit** → enter **`app.ihymns`** (exactly — lowercase, no spaces).
4. Scroll the **Capabilities** list and TICK the checkboxes for:
   - ☑ **Associated Domains**
   - ☑ **Sign In with Apple** (click **Edit/Configure** → leave as primary App ID)
   - ☑ **App Groups** (you'll assign the group in step ③)
   - ☑ **Push Notifications**
   - ☑ **iCloud** (click Configure → **Include CloudKit support** is optional; we only need Keychain — ticking iCloud is enough)
   - ☑ **In-App Purchase** (for the future paid tiers)
   - ☑ **CarPlay** *(only appears/selectable once the step-① entitlement is granted — leave unticked until then; re-edit this App ID later to add it)*
5. Click **Continue** → **Register**.
6. **After CarPlay is granted (step ①):** come back to **Identifiers → app.ihymns → Capabilities → tick CarPlay → Save**.

## ③ Create the App Group + child App IDs
1. Still in **Identifiers** → **⊕** → select **App Groups** → **Continue**.
2. **Description:** `iHymns Shared`; **Identifier:** **`group.app.ihymns`** → **Continue** → **Register**.
3. Go back to **Identifiers → app.ihymns → App Groups (Configure) → tick `group.app.ihymns` → Save**.
4. Register the two **child App IDs** (Explicit App IDs, same steps as ②): **`app.ihymns.watchkitapp`** (description "iHymns Watch") and **`app.ihymns.widgets`** (description "iHymns Widgets"). Give the widgets one **App Groups** = `group.app.ihymns`. (These are internal plumbing, not store products.)
5. **Reserve a Services ID** for future web Sign-in-with-Apple: **Identifiers → ⊕ → Services IDs → Continue** → Description `iHymns Web`, Identifier **`app.ihymns.web`** → **Register**. (Leave it unconfigured for now.)

## ④ Create the App Store Connect record + Family Sharing
1. **appstoreconnect.apple.com** → **Apps** → blue **⊕** → **New App**.
2. Tick platforms: **iOS**, **macOS**, **tvOS**, **visionOS** (do **NOT** tick watchOS — it ships embedded in iOS).
3. **Name:** `iHymns` · **Primary Language:** English (U.K.) · **Bundle ID:** select **app.ihymns** from the dropdown · **SKU:** `app.ihymns` · **User Access:** Full → **Create**.
4. Left sidebar → **Pricing and Availability** → **Price:** Free (choose the Free price tier) → **Save**.
5. Same page → **Family Sharing** → **Set Up Family Sharing** → toggle **ON** (so the future paid unlocks + subscriptions are family-shareable) → **Save**.
6. **App Privacy** (left sidebar) → **Get Started** → answer per strategy §3.1.3: **"Data Not Used to Track You"**; declare Email + User Content as **Linked to user, App Functionality**; Usage Data as **Not linked, Analytics** (consent-gated). → **Publish**.
7. **TestFlight** tab → **⊕ next to "Internal Testing"** → create group **"iHymns Team"** (add your own devices) → and under **External** create groups **iOS Beta / macOS Beta / tvOS Beta / visionOS Beta** (external groups get Beta App Review on the first build of each version).

## ⑤ Sign in with Apple — email relay (before EXTERNAL TestFlight)
1. **developer.apple.com → Account → Certificates, IDs & Profiles → (left) More → Configure "Sign in with Apple for Email Communication"**.
2. **Register a Domain** = the domain the iHymns backend sends email from (e.g. `mwmail.me` or the ihymns sending domain) → Apple shows **SPF** + **DKIM** DNS records.
3. Add those DNS records at your domain's DNS host → back in Apple, click **Verify**. (This lets `@privaterelay.appleid.com` users receive our mail.)

## ⑥ GitHub Actions secrets — verify (mostly present already)
1. **github.com/MWBMPartners/iHymns → Settings → Secrets and variables → Actions.** Confirm the org secrets you already have: `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_ID`, `APPLE_PASSWORD`, `APPLE_SIGNING_IDENTITY`, `APPLE_TEAM_ID`, `ASC_API_KEY`, `ASC_ISSUER_ID`, `ASC_KEY_ID`. ✅
2. **The one to confirm:** `ASC_API_KEY` — does it hold the **`.p8` file *contents*** (the private key body starting `-----BEGIN PRIVATE KEY-----`), or just the key **ID**? If it's just the ID, add a **New repository/org secret** named **`ASC_KEY_P8`** and paste the **entire `.p8` file contents including the BEGIN/END lines**. *(I'll confirm exactly which during Phase 0 and tell you the precise name — I won't run `gh secret set` myself; you add it via this web UI.)*
