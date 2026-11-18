# Plan: rebuilding the cross-site settings sync properly (#2143, part 3)

> **Read this first.** I changed nothing in the repository. The working tree was **in the middle of a merge** while I read it (branch `claude/consolidate-polish-and-language`, `git status` shows "You have unmerged paths"). Line numbers in `js/app.js` moved by about 20 lines between two of my reads. So every citation below also quotes the **text to search for**. Implementers should find code by that text, not by the line number alone.

---

## 0. Summary

**What is broken.** The "storage bridge" is a hidden page (`bridge.html`) that every iHymns page was meant to load inside an invisible frame. A frame is a page shown inside another page. iHymns pages would then send it notes through the browser's `postMessage` feature, which lets two pages at different web addresses talk to each other, so saved data could be shared. It has never connected. Even if it had, it would have been unsafe in two ways:

- **It would lose data.** It copies every saved item, including favourites, set lists, history and sign-in data, over the local copy, and the last site to write wins.
- **It leaks.** It would hand that data to `http://` (unencrypted) addresses, to any `*.ihymns.net` address and to programs running on the visitor's own computer (`localhost` on any port).

**The main finding (it changes my earlier advice in #2143, option C).** Every real iHymns site (`ihymns.app`, `www.`, `beta.`, `dev.`) and the planned `sync.ihymns.app` count as **the same site** in browser terms. They share the registrable domain `ihymns.app`, meaning the part of the address you actually buy. That has two results:

1. A **first-party cookie** set for `.ihymns.app` (a cookie that belongs to our own domain, not an outside one) already reaches all of them. It needs no frame, no extra download and no security-header exception. The repository **already has this**: `js/modules/subdomain-sync.js` and its `ihymns_sync` cookie, running on every page today (`js/app.js`, text `this.subdomainSync = new SubdomainSync();`).
2. The only thing a frame can do that a cookie cannot is reach a **different** site (`ihymns.net`). That is exactly where modern browsers now cut the frame off from shared storage (details in §2.2), so the frame's one unique ability does not work for real visitors.

Signed-in users also **already** have their display settings, favourites, set lists, tags and song history synced through the shared database (§1.4).

**Recommendation.** "Rebuild properly" should mean rebuilding **the sync**, not reviving the frame:

- one shared list of the settings allowed to cross sites, checked by tests;
- a safe merge rule: for each setting, the most recent change wins (per-key timestamps), with "reset" also passed on;
- the transport is the first-party cookie that already exists, rebuilt properly;
- user content never crosses through the browser (signed-in users already get it from the server);
- an off switch per channel, default off.

The frame (`bridge.html` and `storage-bridge.js`) is retired. The owner said "rebuild, not remove", and this retires the frame, so **Decision D1 (§7) asks the owner to confirm the delivery method**. I have also fully specified the frame version (§4, Phase I) in case the owner prefers it. Steps 1–4 are identical either way, and nothing is blocked while D1 waits.

---

## 1. Current-state analysis

### 1.1 The pieces and how they connect

| Piece | Evidence | What it does |
|---|---|---|
| Bridge URL config | `includes/config.php:481-484` (`'storage_bridge' => ['url' => 'https://sync.ihymns.app/bridge.html', 'origin' => 'https://sync.ihymns.app']`) | The only definition of the bridge address |
| Sent to the browser | `index.php` ~2221, text `'storageBridgeUrl'=> APP_CONFIG['storage_bridge']['url'],` inside `$iHymnsConfig` | Always sent, with no switch |
| Allowed as a frame by the page's security policy (CSP) | `index.php:323` `"frame-src 'self' " . APP_CONFIG['storage_bridge']['origin'] . " https://*.ihymns.app{$cspCaptchaFrame}"` | Lets the page load the bridge, and **any** `*.ihymns.app` address, in a frame. A search for `<iframe`/`createElement('iframe')` found no other public-site frame, so `*.ihymns.app` exists only for the bridge |
| Page's own framing rule | `index.php:328` `"frame-ancestors 'self'"`; admin: `manage/includes/auth.php:128` | Only the page's own address may show it in a frame |
| Site-wide clickjacking header | `.htaccess:572` `Header always set X-Frame-Options "SAMEORIGIN"` | Applies to static files too, including `bridge.html`. This is what blocks the bridge |
| Reverted exception | commit `e2ae6e8` removed a `<Files "bridge.html">` block that unset that header and set `frame-ancestors https://ihymns.app https://*.ihymns.app https://ihymns.net https://*.ihymns.net` (added in `609e065`) | Reverted after review because switching it on would cause data loss |
| Bridge page | `bridge.html:19-104` | One inline `<script>`. Allowed senders are the pattern `^https?:\/\/([\w-]+\.)?ihymns\.(app|net)(:\d+)?$`, plus `localhost` and `127.0.0.1` on any port (lines 27-31). Actions: `get`, `set`, `remove`, `keys`, `getAll`, `clear`, `ping` (45-90) |
| Client | `js/modules/storage-bridge.js` (314 lines) | Creates the frame (79-84), checks that `pong` comes back (91-106), times out after 2000 ms, or 2500 ms overall (116-121) |
| Start-up overwrite (the data-loss trap) | `js/app.js:213-239`, text `this.storageBridge.getAll().then((data) => {` | `for … of Object.entries(data)` followed by `localStorage.setItem(key, value)` for **every** key that differs: a whole-store copy where the last writer wins. Afterwards it re-applies only theme, motion and font size |
| First-connect push | `storage-bridge.js:217-238` `_syncLocalToBridge()` | Pushes **every** local `ihymns_*` key the bridge lacks, including sign-in tokens (see §1.3) |
| Per-write push | `js/app.js` ~1331, text `syncStorage(key) {` | Layer 1 writes the cookie. Layer 2 calls `this.storageBridge.set(key, value)` for any key whose value is not empty |
| Callers of `syncStorage` | `settings.js:593` (analytics consent), `:702` (every `Settings.set`), `:856`, `:965`, `:977`, `:1004`, `:1129`, `:1275-1277` (favourites, set lists, history after an import); `display.js:145`; `search.js:230`; `setlist.js:637`; `favorites.js:79`; `search-history.js:64,82`; `history.js:118` | So favourites, set lists, history, search history **and analytics consent** would all go through the bridge |
| Offline cache (service worker) | `service-worker.js.php:859-860` pre-caches `/js/modules/storage-bridge.js` and `/js/modules/subdomain-sync.js` | Must be updated if either file is deleted |
| robots | `robots.txt.php:98` `"Disallow: /bridge.html\n"`; guarded by `tests/php/test-search-visibility.php:349` (assertion 5.5) | — |
| Docs | `wiki/Architecture.md:94-95`; privacy page `includes/pages/privacy.php:186-209` (says a hidden-frame bridge "may be used… transfers only your app settings", which is untrue as built); `api-docs.yaml:332-336` (keeps `beta.ihymns.net` listed only because the bridge accepts `ihymns.net`); `.claude/ProjectBrief.md:1622` and `.claude/project-rules.md:262` (both say the `ihymns_sync` cookie is "the ONLY lightweight-settings sharing mechanism") | — |
| Tests | Only `test-search-visibility.php` mentions `bridge.html`. **No test covers the bridge or the cookie sync at all.** | — |

### 1.2 Does it run today?

- The frame is created on **every page load** whenever `storageBridgeUrl` is set, which is always.
- Either the address does not resolve, or the `SAMEORIGIN` header blocks it. Either way the `ping` times out, and the console shows `[StorageBridge] Bridge ping failed…` or `Connection timed out…`. The code then falls back to local storage.
- **Whether `sync.ihymns.app` exists in DNS or on the host cannot be told from the repository.** The evidence suggests it was never set up:
  - `deploy.yml` deploys only to `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` and `SFTP_DEV_PATH` (lines 14-16).
  - `sync.ihymns.app` is missing from both host allow-lists: `appCanonicalHost()` (`includes/config.php:602`) and `WEBHOOK_CANONICAL_HOSTS` (`includes/webhooks.php:94`).
  - Its only mentions are in `config.php`, `bridge.html` and a comment in `storage-bridge.js`.
- The **cookie layer works today** on all `*.ihymns.app` hosts, but with a weak rule. It fills a setting in only if the local value is empty (`subdomain-sync.js:94-98`). So once a host has a theme, changing the theme on another host **never** reaches it. And a reset deletes the whole cookie (`subdomain-sync.js:205-208`, called from `settings.js` ~1166), so other hosts push their old values straight back.

### 1.3 Defects in the current bridge (all would go live the moment it connected)

1. **Allowed senders are far too wide** (`bridge.html:27-31`):
   - `http` is allowed, not only `https`;
   - any port is allowed;
   - any `*.ihymns.net` subdomain is allowed, including a legacy domain whose DNS we may no longer watch. A forgotten DNS record that an attacker takes over (a "subdomain takeover") would get full read and write access;
   - `localhost` and `127.0.0.1` on any port are allowed, so any program or development server on the visitor's machine could read everything.
2. **There is no list of allowed keys.** `get`, `set` and `remove` accept *any* key (`bridge.html:46-58`). Only `keys`, `getAll` and `clear` filter by the `ihymns_` prefix.
3. **`clear` wipes everything** for any allowed sender (`bridge.html:74-83`).
4. **No check on value type or size.** `setItem(key, value)` blindly turns objects into the text `"[object Object]"`.
5. **Secrets would cross sites.** `_syncLocalToBridge()` (`storage-bridge.js:217-238`) pushes every `ihymns_*` key, including:
   - `ihymns_auth_token` (`constants.js:84`), the sign-in token;
   - `ihymns_auth_user` (`:85`);
   - `ihymns_owner_id` (`:19`). Per `setlist.js` ~2984-2989 this is "Used to identify who created a shared setlist so only they can update it", so it works like a password for editing shared set lists;
   - `ihymns_sf_device` (`js/utils/presence-identity.js:40`).

   `getAll()` then copies them all to every other host, and any allowed sender (see 1) can read them.
6. **Sync record-keeping would be corrupted.** The keys `ihymns_setlists_synced_at`, `ihymns_favorites_synced_at`, `ihymns_setlists_deleted`, `ihymns_history_backfilled` and `ihymns_settings_ns_migrated` (`constants.js:94-106,24`; `settings.js:117`) are per-site notes the *server* sync uses to keep track. Copying them between hosts makes host B think it has already synced or deleted things it has not.
7. **The whole store is copied and the last writer wins** (`app.js:218-231`). This is the data loss the review found.
8. **Removals and resets never reach the bridge** (`syncStorage` skips empty values; the reset does not touch the bridge). The next start-up's `getAll()` puts the old values back.
9. **Inline script, and no security policy on `bridge.html`.** It is a static file, so none of the PHP security policy applies to it.
10. **It costs every page**: an extra download, a 2.5-second timer and console warnings.

### 1.4 Sync layers that already exist (why the frame has no job left)

| Layer | Who | What | Scope | Evidence |
|---|---|---|---|---|
| `ihymns_sync` cookie | Everyone | 9 display keys | All `*.ihymns.app` hosts, **including across alpha, beta and live** | `subdomain-sync.js:55-65,184-200` |
| Server settings (`user_settings`, the `ihymns.web` section of the user's saved settings) | Signed in | 14 keys (`SYNC_PREF_KEYS`) | Across devices and hosts (one database, so across channels too) | `settings.js:60-77,101,275-397`; `api.php:4677` `case 'user_settings'`; `includes/user_settings.php` |
| Server content sync | Signed in | Favourites, set lists, custom tags, song history | Across devices and hosts | `user-auth.js:754` (`user_setlists_sync`), `:894` (`favorites_sync`), `:952` (`custom_tags_sync`); `history.js:163,219` (`song_history`, `song_history_backfill`); `api.php:4051,6654,6975,10544,10801` |
| Search history, transpose/capo per song, recent songbooks | Everyone | — | Stays on each site | No server sync found |

The two key lists, the cookie's 9 and the server's 14, **already disagree** with nothing checking them. That is a rule #35 failure ("two files must agree, and nothing enforces it") that the rebuild should fix.

### 1.5 Every saved key, classified

**A — Display/behaviour preferences (allowed to cross sites).** The 14 keys in `SYNC_PREF_KEYS` (`settings.js:60-77`):

| Key | Defined at | Allowed values (for the validator) |
|---|---|---|
| `ihymns_theme` | `constants.js:27` | `system`, `light`, `dark`, `high-contrast` (`settings.js:710-726`) |
| `ihymns_fontSize` | `:28` | Whole number 14–28 in steps of 2 (`includes/pages/settings.php:661-670`) |
| `ihymns_reduceMotion` | `:29` | `true` or `false` |
| `ihymns_reduceTransparency` | `:30` | `true` or `false` |
| `ihymns_transition` | `:31` | The `<option value>`s of `#setting-transition` (`settings.php` ~748-760: `none`, `fade`, `crossfade`, `slide`, `scale`, `lift`, `depth`, `blur`, plus any others in that select) |
| `ihymns_default_songbook` | `:32` | Empty, or a songbook abbreviation `^[A-Za-z0-9]{1,10}$` (rule #27) |
| `ihymns_auto_update_songs` | `:33` | `true` or `false` |
| `ihymns_numpad_live_search` | `:34` | `true` or `false` (`settings.js:973-978`) |
| `ihymns_search_lyrics` | `:35` | `true` or `false` (`search.js:224-230`) |
| `ihymns_display` | `:36` | Saved as text (JSON) holding an object with only these keys: `fontSize` (number 0.5–5), `lineSpacing` (`compact`, `normal` or `spacious`), `showVerseNumbers` (true/false), `highlightChorus` (true/false), `autoScrollSpeed` (number, any value from 0 to 1000) (`display.js:109-115`) |
| `ihymns_cvd_mode` (colour-blindness filter) | `:109` | Empty, or the `<option>` values of `#setting-cvd-mode` (`settings.php:555-560`) |
| `ihymns_link_emphasis` | `:121` | `on` or absent (`settings.js:1037`) |
| `ihymns_keyboardShortcuts` | `settings.js:73` | `true` or `false` |
| `liveIdleTimeoutMins` (no `ihymns_` prefix, on purpose) | `constants.js:183` | Whole number 5–240 (`settings.js:1000`) |

These are display preferences too, but are **not** in v1: `ihymns_chord_notation` (`:167`), `ihymns_chord_columns` (`:148`), `songbook-language-filter` (`:42`, which has its own server path), `ihymns_list_sorts` (`:81`, which has its own server section). See D5.

**B — User content (must NEVER cross sites through the browser).** For signed-in users the server already handles it.

`ihymns_favorites` (`:14`), `ihymns_setlists` (`:15`), `ihymns_history` (`:16`), `ihymns_search_history` (`:17`), `ihymns_custom_tags` (`:18`), `ihymns_recent_songbooks` (`:74`), the per-song `ihymns_transpose_*`, `ihymns_capo_*`, `ihymns_octave_*` (`:141,156,157`), `ihymns_playlist_context` (`:49`), and `ihymns_export_lines_per_slide` (`manage/editor/v2/export.js:122`).

**C — This device only, and sync record-keeping (must never cross).**

- Choices and dismissals: `ihymns_analytics_consent` (`:69`; the server-sync note at `settings.js:50-53` already excludes it on purpose), `ihymns_analytics_debug` (`:70`), `ihymns_disclaimer_accepted` (`:71`), `ihymns_pwa_banner_dismissed` (`:72`).
- Offline-download choices: `ihymns_offline_include_audio` plus its old name (`:125,138`), and `ihymns_midiPedal` (`settings.js:148`; the foot pedal is tied to one machine).
- Notes on what the user has already seen: `ihymns_extlink_disclaimer_seen` (`external-link-interstitial.js:25`), `ihymns_error_monitor_seen` (`error-monitor.js:107`), `ihymns_sync_app_settings` (`settings.js:80`).
- Sync record-keeping: `ihymns_settings_ns_migrated` (`:117`), the `_synced_at` and `_deleted` keys, and `ihymns_history_backfilled`.
- Service-follow state: `ihymns_round_voice` and `ihymns_sf_pending_scroll` (`service-follow.js:39,55`).

**D — Security-sensitive (must NEVER cross under any setting).**

- In local storage: `ihymns_auth_token`, `ihymns_auth_user`, `ihymns_owner_id`, `ihymns_sf_device`.
- In session storage (cleared when the tab closes; outside the bridge's reach, but listed for completeness): `ihymns_sf_presence` (`service-follow.js:49`), `ihymns_lf_host`, `ihymns_lf_follow` (`live-follow.js:44-45`).
- Cookies, not local storage: `ihymns_auth` (server-only and hidden from page scripts, the `HttpOnly` setting), `ihymns_sf_presence_token` (`presence-identity.js:42`; read by the server at `includes/pages/song.php:274-276`), `ihymns_pwa_installed` (`pwa.js:346`).

### 1.6 Hosts

- **Live:** `ihymns.app` and `www.ihymns.app`. Both are on the canonical list (`config.php:602`). There is **no** redirect between them in `.htaccess`. `infoAppVer.php:79` uses `https://ihymns.app`; `manifest.json:215` uses `https://www.ihymns.app`.
  - So the bare domain and `www` may be two separate addresses for the *same* channel, each with its own local storage. That is the only real same-channel, cross-address case. Whether both actually serve the site **cannot be told from the repo**.
- **Testing:** `beta.ihymns.app` (beta) and `dev.ihymns.app` (alpha).
- **Legacy:** `beta.ihymns.net` (`api-docs.yaml:337`); whether it is still live is unknown.
- **Channel gate is off:** `includes/channel_gate.php` `enforceChannelGate()` has a "TEMPORARILY DISABLED" early return. So anonymous visitors can use dev and beta today.

---

## 2. Options, consequences, and the recommended design

### 2.1 How the sync could travel

| Option | How | Works in Chrome/Firefox/Safari today? | Cost | Verdict |
|---|---|---|---|---|
| **T1. Hidden frame on `sync.ihymns.app` (rebuilt)** | Frame plus `postMessage`, strict sender list and key list | **For `*.ihymns.app`, yes** (same-site; §2.2). **For `ihymns.net`, no**, or only with a permission prompt that a hidden frame cannot show | Extra download per page; arrives late, so the theme can visibly flip on first paint; needs DNS, a host setup and a certificate; a scoped header exception; a sender list kept in agreement across 3 files | Works, but reaches only the same places T2 reaches, at more cost and risk |
| **T2. First-party cookie on `.ihymns.app` (rebuilt `ihymns_sync`)** | One small cookie, read without waiting at start-up | Yes, on every `*.ihymns.app` host. Safari limits cookies written by page script to **7 days** (its "Intelligent Tracking Prevention"); because we rewrite the cookie on each visit, it renews whenever the visitor comes back | About 1–2 KB sent with each request to our own hosts (largely shrunk by HTTP/2's header compression); 4 KB cap | **Recommended**: already in place, no frame, no header exception, available before first paint |
| T3. Server only (signed-in settings) | Already exists | Yes | Anonymous visitors get nothing across sites | Keep it as is for signed-in users; it does not replace T2 for anonymous visitors |
| T4. Hand-off in the web address (`#prefs=…` on links between our sites) | Settings packed into cross-site links | Yes | Only works when the visitor follows our own link; values end up in history and logs | Not recommended |

### 2.2 Will a frame work for real visitors? (third-party storage partitioning)

Modern browsers now keep **separate storage for a frame depending on which site it is shown inside**. This is called partitioning. It stops trackers recognising you across sites:

- Chrome keys a frame's storage by the top-level site plus whether any cross-site page sits in between;
- Firefox's "Total Cookie Protection" (state partitioning) keys it by the top-level site;
- Safari's tracking prevention partitions storage in third-party frames.

A **site** here means the registrable domain, such as `ihymns.app`.

**What that means for us:**

- `www.`, `beta.`, `dev.ihymns.app` and `sync.ihymns.app` are all the site `ihymns.app`. So the bridge frame gets the **same** storage under each of them. **T1 can work for `*.ihymns.app`** in all three browsers, going by their published rules.
- Under `ihymns.net` the frame would be **cross-site**. It would get its own separate storage, which is useless for syncing. The only way out is the browser's Storage Access feature, which needs a click inside the frame (impossible in a hidden frame), and in Safari also an earlier visit to `sync.ihymns.app` as a normal page. **So `ihymns.net` cannot be served by a frame in practice.**
- Everything T1 can reach, T2 already reaches. **The frame has no unique job.**

This must be confirmed on real devices whichever option is chosen (§6).

### 2.3 How changes are merged

| Rule | Consequence |
|---|---|
| Copy the whole store, last writer wins (current bridge) | Visible data loss; rejected |
| Fill only empty keys (current cookie) | Changes never spread after first use; a reset is undone |
| **Most recent change wins, key by key, with tombstones** (a tombstone is a small "this was deleted" marker) | Correct for single values. All writers are **the same browser on the same machine**, so they share one clock. That makes "most recent" reliable, unlike between devices. Protect against the clock being set backwards with `stamp = max(Date.now(), highestStampSeen + 1)`. **Recommended.** |
| Union-merge (combine both lists) for favourites and set lists | Needs deletion markers per item and identity rules; mixes channels' content; the server already does this properly for signed-in users. Rejected; content stays local (D3) |

### 2.4 Channel walls

- Today, display settings **already cross** alpha, beta and live: through the `ihymns_sync` cookie (domain `.ihymns.app`), and through the signed-in server sync (one shared database, one `ihymns.web` section).
- Content must not cross channels through the browser. The rebuild guarantees this structurally.
- "Same channel only" would leave almost nothing to sync. Each channel has one address, apart from live's bare domain and `www`.
- The real risk of crossing channels is a **new value that only alpha understands** (say a new theme) reaching live. Checking every value against a per-key validator removes that risk: live simply ignores a value it does not recognise.
- **Recommended:** display settings cross all channels, each value checked against the validators; content never crosses (D2).

### 2.5 The recommended design ("R")

1. **One registry** `js/modules/settings-sync-registry.js`: allowed key → validator. This is the *only* list, and it is used by the cookie sync, by the server settings sync (`SYNC_PREF_KEYS` becomes an import from it) and by the tests.
2. **One pure merge core** `js/modules/settings-sync-core.js`. "Pure" means it has no browser dependencies, so it is easy to test. It provides the timestamped entry format, safe parsing, the merge and tombstones.
3. **Cookie transport** `js/modules/settings-sync.js` (class `CrossSiteSync`):
   - cookie `ihymns_prefs`, set for `.ihymns.app`, with `Secure` (https only), `SameSite=Lax` and a lifetime (`Max-Age`) of one year;
   - format `{"v":2,"k":{"<key>":[<value|null>,<stamp>]}}`;
   - read without waiting **before** `Settings.init()`, so the theme never flashes;
   - read again when the tab becomes visible.
4. **A per-site timestamp record**, `ihymns_xsite_stamps`, in local storage. It belongs to category C and is never synced.
5. **Off switch:** the `tblAppSettings` row `cross_site_sync_channels`, a comma-separated list of channels (same shape as search visibility, rule #50). It is read through `ihymns_parse_channels_csv()` plus `ihymns_environment()`. When the row is missing it means `'none'`. When a channel is off, the old `SubdomainSync` runs exactly as it does today.
6. **The frame is retired.** `bridge.html`, `storage-bridge.js`, the `storage_bridge` config and the two `frame-src` entries are removed. `X-Frame-Options: SAMEORIGIN` stays site-wide with **no** exception.
7. **Content stays put.** Signed-in users already have server sync. Anonymous content stays on each site.

---

## 3. Security analysis

### 3.1 For the recommended cookie design

- **Who can read or write the cookie:** any `*.ihymns.app` subdomain, including one taken over through a forgotten DNS record.
  - The impact is limited because only display values are in it, and every value is checked per key against its validator.
  - The `ihymns_auth` cookie already has the same domain scope, so a subdomain takeover is already a far worse problem. This adds nothing meaningful.
  - "Cookie tossing" (a hostile subdomain planting a same-name cookie with a narrower path) is limited by the same validators.
- **No secrets or content:** structurally, only registry keys are ever read or written. A test (G1) proves that no category C or D key can enter the registry.
- **Safe parsing:**
  - Use `JSON.parse` inside `try`, then require a plain object (not an array, not null).
  - Walk **the registry's keys** and look each one up with `Object.hasOwn(parsed.k, key)`. Never loop over the parsed object, and never use `Object.assign` or spread (`...`) on it.
  - Collect results in `Object.create(null)` or a `Map`. This blocks "prototype pollution", where a key named `__proto__` in the data would change built-in object behaviour.
  - Stamps must be whole numbers from 0 to `Date.now() + 1 day`. Values must be text of 512 characters or fewer, or `null`.
- **Size:** refuse to write if the encoded cookie is over 3500 bytes (the existing limit, `subdomain-sync.js:136`). Ignore a received cookie over 4096 bytes.
- **The server must never trust it:** no PHP may read `$_COOKIE['ihymns_prefs']`. Guard G3 checks this.
- **Framing and CSP:**
  - Header changes: none, apart from tightening. `frame-src` loses the bridge address and `https://*.ihymns.app`.
  - G4 proves that `X-Frame-Options` is set exactly once, site-wide, and **never** unset anywhere, and that `frame-ancestors` stays `'self'` in `index.php` and `manage/includes/auth.php`.
  - No new inline script (rule #30). All new code is ES modules imported by `app.js`.
- **Privacy:**
  - `privacy.php` §4 already names `ihymns_sync`. Rewrite it to name `ihymns_prefs`, list the settings, say plainly that it is shared between iHymns's own sites **including its test sites**, say how to clear it (Settings → Reset), and delete the inaccurate frame paragraph (`privacy.php:197-202`).
  - Only values the user actively chose are written; defaults are never written. That supports treating it as a "user-interface customisation" cookie that needs no consent banner under ePrivacy/PECR. This is not legal advice (D7).

### 3.2 If the frame version is chosen (Phase I)

- **Each direction, every message:**
  - The bridge page accepts only an **exact-match** list of origins (an origin is scheme + host + port), `https` only: `https://ihymns.app`, `https://www.ihymns.app`, `https://beta.ihymns.app`, `https://dev.ihymns.app`. No patterns, no `http`, no `localhost`, no `.net`.
  - The client accepts only `event.origin === bridgeOrigin && event.source === iframe.contentWindow` (already true at `storage-bridge.js:303-304`).
  - Neither side ever posts with `'*'` as the target. Replies go to `event.origin`, which has already been checked.
- **Message format:** `{ns:'ihymns-xsite', v:1, id:<32 hex chars from crypto.getRandomValues>, op:'read'|'merge', entries?}`. Everything else is dropped. The old open-ended actions (`get`, `set`, `remove`, `keys`, `getAll`, `clear`) are **deleted**. `merge` uses the same core as the cookie, so most-recent-wins also applies inside the bridge's own storage, held as one key, `ihymns_xsite_v1`.
- **Key list on both sides:** the bridge script and the client both **import the same registry module** from the same path. G7 checks this.
- **Headers scoped to one file:**

  ```
  <Files "bridge.html">
    Header always unset X-Frame-Options
    Header always set Content-Security-Policy "default-src 'none'; script-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors <exact list>"
  </Files>
  ```

  This uses the same Apache merge order as the reverted block; `<Files>` sections are applied after the directory-level `Header always set`. G4 proves this is the only unset anywhere and that only one file named `bridge.html` exists.
- **Other headers:**
  - `Cross-Origin-Opener-Policy` is ignored inside frames.
  - `Cross-Origin-Resource-Policy: same-origin` only blocks a frame when the embedding page sends `Cross-Origin-Embedder-Policy`. A search found no such header, but set `Cross-Origin-Resource-Policy: same-site` inside the `<Files>` block anyway, to be safe.
- **Inline script:** the bridge page carries none; it loads `/js/bridge/bridge-host.js` (rule #30 style).

---

## 4. Implementation steps

**General rules:**

- Work on the single working branch (standing directives §2).
- Run `node tools/run-node-tests.js` and `php tools/run-php-tests.php` before every commit; both must end `TEST RESULT: PASS`.
- Put a doc-block and two-level comments on new code (standing task 1).
- End every commit with the attribution lines.

### Phase A: needed for both versions

**Step 1. Add the off switch and remove the frame that never worked**

- **New file `includes/cross_site_sync.php`**, copying the shape of `includes/search_visibility.php`:
  - `const CROSS_SITE_SYNC_DEFAULT = 'none';`
  - `crossSiteSyncAllows(string $csv, string $channel): bool`. Pure: `in_array($channel, ihymns_parse_channels_csv($csv === 'none' ? '' : $csv), true)`.
  - `crossSiteSyncEnabledHere(): bool`. Remembered for the request; reads `getAppSetting('cross_site_sync_channels', CROSS_SITE_SYNC_DEFAULT)` inside `try`; returns `false` on any error, so it fails closed.
- **`index.php`:**
  - `require_once` the new file next to `search_visibility.php`.
  - Replace the line with `'storageBridgeUrl'=> …` by `'crossSiteSync' => ['enabled' => crossSiteSyncEnabledHere(), 'channel' => ihymns_environment()],`.
- **`js/app.js`:**
  - Delete the whole `if (this.config.storageBridgeUrl) { … }` block (text from `/* Cross-domain storage bridge (#133)` to its closing `}`).
  - Delete the `StorageBridge` import and the `this.storageBridge` field.
  - In `syncStorage()`, delete the "Layer 2" lines.
- **Leave** `bridge.html`, `storage-bridge.js` and `config.php` alone for now; they are removed in Step 8, after D1.
- **Acceptance:**
  - A search for `storageBridge` in `js/` finds only `storage-bridge.js` itself.
  - Loading any page creates no `#ihymns-storage-bridge` element.
  - The old cookie sync is unchanged (a manual test on two hosts behaves as before).
- **Commit:** `fix(sync): stop loading the settings bridge frame that never connected (#2143)`

**Step 2. One registry of shareable settings**

- **New file `js/modules/settings-sync-registry.js`:**
  - Exports `CROSS_SITE_SETTINGS`: a frozen `Map` from key to `{validate(value: string|null): boolean}`, for exactly the 14 keys in §1.5 A, using the allowed values listed there.
  - Validators for `<select>`-based keys hold their allowed values as an exported array (e.g. `TRANSITION_VALUES`), so G1 can compare them with `settings.php`.
  - `ihymns_display` is validated by `JSON.parse`, plain-object check, own keys within the listed five, and per-field types and ranges.
  - Also exports `CROSS_SITE_KEYS = Object.freeze([...CROSS_SITE_SETTINGS.keys()])` and `isShareableSetting(key, value)`.
  - Keys come from the `constants.js` exports, never typed as text, except `ihymns_keyboardShortcuts`. Add it to `constants.js` as `STORAGE_KEYBOARD_SHORTCUTS` first.
- **`js/modules/settings.js`:** replace the `SYNC_PREF_KEYS` array literal (`:60-77`) with `const SYNC_PREF_KEYS = CROSS_SITE_KEYS;` imported from the registry. The server sync keeps exactly the same 14 keys.
- **Acceptance:**
  - `node --check` passes on both files.
  - The list is identical to before (G1 checks this).
- **Commit:** `refactor(settings): one registry for the settings that may follow you between sites (#2143)`

**Step 3. Pure merge core, with its test**

- **New file `js/modules/settings-sync-core.js`.** No DOM, no storage access. It provides:
  - `nextStamp(highestSeen)`, which returns `Math.max(Date.now(), highestSeen + 1)`;
  - `decodeEnvelope(text)`, which returns a `Map` of key → `[value|null, stamp]` and **never throws**. It walks the registry keys only, uses `Object.hasOwn`, applies the §3.1 limits, and drops invalid entries;
  - `encodeEnvelope(map)`, which returns text, or `null` if longer than 3500 characters after `encodeURIComponent`;
  - `mergeEntries(local, remote)`. For each registry key:

    ```
    if remote.stamp > local.stamp and isShareableSetting(key, remote.value) → applyLocal[key] = remote
    else if local.stamp > remote.stamp → writeRemote[key] = local
    if equal → keep local
    ```

    It returns `{applyLocal, writeRemote}`.
- **Add test G2** (§5).
- **Commit:** `feat(sync): per-setting newest-wins merge core for cross-site settings (#2143)`

**Step 4. Per-site timestamps and resets that are passed on**

- **In `settings-sync-core.js`**, add the local-storage helpers `readStamps()` and `writeStamp(key, stamp)` for the key `ihymns_xsite_stamps`, wrapped in `try`. Add that key to `constants.js` as `STORAGE_XSITE_STAMPS` with a comment saying it is never synced.
- **`settings.js` reset handler** (text `Reset all settings to defaults?`): after the removals, call `this.app.crossSiteSync?.recordRemoval(CROSS_SITE_KEYS)`. That writes tombstones with fresh stamps. Keep the existing `subdomainSync?.clear()` for the old path.
- **`settings.js` `_pullSyncedSettings()`:** after each `localStorage.setItem` or `removeItem` of a registry key, call `this.app.crossSiteSync?.recordLocalChange(k)`. When the user signs in, the account's value then counts as the newest; this matches today's "the server overwrites local" behaviour (D8).
- **Commit:** `feat(sync): record when each shared setting last changed, so resets travel too (#2143)`

**Step 5. Cookie transport** (only if D1 = cookie, the recommended choice)

- **New file `js/modules/settings-sync.js`**, `export class CrossSiteSync`:
  - The constructor takes `({channel})`. The cookie domain is `.ihymns.app` if the hostname is `ihymns.app` or ends with `.ihymns.app`; otherwise the class is inactive.
  - The cookie name is `CROSS_SITE_COOKIE = 'ihymns_prefs'`, exported. If D2 = same channel, use `` `ihymns_prefs_${channel}` `` instead; it is one constant.
  - `init()` reads `document.cookie`, decodes, builds the local map from local storage plus stamps, merges, applies `applyLocal` to local storage (writing `null` removes the key), and writes the cookie if `writeRemote` is not empty. It returns `true` if anything local changed.
  - **First run only:** if the old `ihymns_sync` cookie exists and the local value is empty, import any validated registry keys with stamp 0, then mark this done with `ihymns_xsite_legacy_imported = '1'` (category C).
  - `recordLocalChange(key)`: if the key is in the registry, stamp it, then rewrite the cookie from local storage plus stamps. Write only keys that have an explicit local value or a tombstone; never defaults.
  - `recordRemoval(keys)` writes tombstones.
  - Cookie attributes: `Domain=.ihymns.app; Path=/; Max-Age=31536000; SameSite=Lax; Secure`.
  - On `document.visibilitychange` (when it becomes visible), run `init()`. If it returns `true`, dispatch a new event `EVT_XSITE_SETTINGS_APPLIED`, declared in `constants.js` (the `test-event-names.js` rule).
- **Add test G3.**
- **Commit:** `feat(sync): rebuild the shared settings cookie with per-setting merge (#2143)`

**Step 6. Connect it in `app.js` and `settings.js`**

- **`app.js` `init()`, where `new SubdomainSync()` is created:**

  ```
  if (this.config.crossSiteSync?.enabled) {
      this.crossSiteSync = new CrossSiteSync({ channel: this.config.crossSiteSync.channel });
      this.crossSiteSync.init();
  } else {
      (existing SubdomainSync lines, unchanged)
  }
  ```

  This must stay **before** `this.settings = new Settings(this)`.
- **`syncStorage(key)`:** if `this.crossSiteSync`, call `this.crossSiteSync.recordLocalChange(key)`; otherwise use the existing `subdomainSync?.sync(key)`. Content keys are ignored by the registry check.
- **`settings.js`:**
  - Add `reapplyAll()`, which calls `applyTheme` (this also re-applies the colour filter and link emphasis), `applyReduceMotion`, `applyReduceTransparency` and `applyFontSize`, and the display-module re-render if one exists.
  - Listen for `EVT_XSITE_SETTINGS_APPLIED` and call `reapplyAll()`.
- **Fix in the same commit:** `settings.js` ~856, ~965, ~1129 and `:1016-1022` write some registry keys with direct `localStorage.setItem` and do not always call `syncStorage`. In particular the colour-filter change calls only `_maybePushSync`. Make every registry-key write call `this.app.syncStorage(key)`. G3 checks this against the tree.
- **Acceptance:**
  - With the switch off, behaviour is byte-identical to Step 1.
  - With it on (set locally with `getAppSetting` returning `'alpha'`), changing the theme on host A and then opening host B shows the new theme on first paint.
- **Commit:** `feat(sync): use the rebuilt settings sync when it is switched on for this channel (#2143)`

**Step 7. Admin switch**

- **`manage/configuration.php`:** add a card "Settings that follow visitors between iHymns sites" with three channel checkboxes. Copy the `save_search_visibility` action (around `:1150-1200`) and its form (around `:1590`):
  - the action is `save_cross_site_sync`;
  - same `validateCsrfRequest`, same `manage_configuration` permission check, same `respond=json` reply format;
  - store `'none'` when nothing is ticked.
- **`tests/php/test-manage-action-api-coverage.php`:** map `save_cross_site_sync` to `web_only:configuration-secrets`, the same reason search visibility uses (rule #48).
- **Commit:** `feat(manage): per-channel switch for cross-site settings sync, off by default (#2143)`

**Step 8. Retire the frame** (only if D1 = cookie)

- Delete `appWeb/public_html/bridge.html` and `js/modules/storage-bridge.js`.
- Remove the `/js/modules/storage-bridge.js` line from `service-worker.js.php` (~859).
- `includes/config.php`: delete the `storage_bridge` block (`:466-484`).
- `index.php:323`: change to `"frame-src 'self'{$cspCaptchaFrame}"`. Keep the `{$cspCaptchaFrame}` token; `tests/php/test-captcha-gate.php` 5.2 checks for it.
- `robots.txt.php:98`: remove `"Disallow: /bridge.html\n"`, and in `tests/php/test-search-visibility.php:349` change 5.5 to assert that the line is **absent**.
- `api-docs.yaml:332-336`: drop the bridge reason. Whether to keep the `beta.ihymns.net` server entry is D4.
- `wiki/Architecture.md:94`: remove the StorageBridge line; describe CrossSiteSync.
- **Acceptance:**
  - A search for `bridge.html`, `storage-bridge` or `storage_bridge` across `appWeb/` and `tests/` finds nothing outside CHANGELOG history.
  - G4 is green.
- **Commit:** `chore(sync): remove the unused settings bridge frame and its frame permissions (#2143)`

**Step 9. Privacy page.** Rewrite `includes/pages/privacy.php` §4 as described in §3.1 and update its "Last updated" date. G3 checks that the cookie name matches.

- **Commit:** `docs(privacy): describe the shared settings cookie accurately (#2143)`

**Step 10. Project docs.**

- Update `CHANGELOG.md`.
- Update `.claude/ProjectBrief.md:1622` (its table) and `.claude/project-rules.md:262` (§14.3).
- Add a new numbered rule in `.claude/CLAUDE.md`: "one shared-settings registry; no browser-side content sync; no frame exception".
- Update `.OpenAI/CONTEXT.md` and `.OpenAI/MEMORY.md`, plus the handoff.
- Leave `WHATS-NEW.md` until production is switched on (Step 11), because nothing is visible to users before then.
- **Commit:** `docs: record the rebuilt cross-site settings sync (#2143)`

**Step 11. Clean-up, after production has been on and verified for a release cycle**

- Delete `subdomain-sync.js` and its service-worker line, and the `else` branch in `app.js`.
- Add a one-time deletion of the old `ihymns_sync` cookie to `CrossSiteSync.init()`.
- **Keep the switch** as an emergency off switch.
- Add the plain-language `WHATS-NEW.md` bullet: "Your display settings, such as theme and text size, now follow you between iHymns sites."
- **Commit:** `feat(sync): settings follow you between iHymns sites; retire the old cookie (#2143)`

### Phase I: the frame version (only if D1 = frame; this replaces Steps 5, 8 and 9)

- **I1.** New `js/bridge/bridge-host.js` (ES module). It imports the registry and core. It has an exact-match origin list `BRIDGE_ALLOWED_ORIGINS` (frozen array). The message handler follows §3.2: check the origin exactly, then the format, then run `read` or `merge` against one storage key `ihymns_xsite_v1` through `mergeEntries`, then reply to `event.origin`. *Commit: `feat(sync): strict bridge host script with an exact sender list (#2143)`*
- **I2.** Rewrite `bridge.html` with no inline script: `<script type="module" src="/js/bridge/bridge-host.js"></script>`. *Commit: `fix(sync): bridge page loads its script from a file, not inline (#2143)`*
- **I3.** `.htaccess`: add the `<Files "bridge.html">` block from §3.2 **after** the main security-headers block. *Commit: `fix(headers): let only iHymns's own sites frame the bridge page, nothing else (#2143)`*
- **I4.** Rewrite `storage-bridge.js` around the core:
  - `init()` only when `config.crossSiteSync.enabled && transport==='frame'`;
  - make the frame lazily, at most once per tab session;
  - random IDs;
  - `merge` on change, `read` at start-up, then `reapplyAll()`;
  - log at debug level only, never warn.

  In `index.php`, send `bridgeUrl` only when enabled. In `frame-src`, list exactly the sync origin, never `*.ihymns.app`. *Commit: `feat(sync): rebuilt bridge client using the shared merge core (#2143)`*
- **I5.** Owner work on the host (§6): DNS, the virtual host (the server's per-domain setup) pointing at a docroot, TLS certificate. Add `sync.ihymns.app` to `appCanonicalHost()`'s list **only if** needed. G4 and G7 are added in I3 and I4.

---

## 5. Tests and guards

All Node tests are `tests/*.js`, picked up automatically by `tools/run-node-tests.js`. All PHP guards are `tests/php/test-*.php`, picked up by `tools/run-php-tests.php`. Each one below gets its list from the tree, not from a typed list.

| ID | File | What it asserts (derived from the tree) | How it is mutation-proven (break it, watch it fail, restore) |
|---|---|---|---|
| **G1** | `tests/test-settings-sync-registry.js` | (a) `settings.js` has no array literal of `'ihymns_…'` text for `SYNC_PREF_KEYS` and imports `CROSS_SITE_KEYS`: one list (rule #35). (b) **Banned list derived from the tree:** scan every `js/**` and `manage/editor/v2/**` file for `localStorage.setItem(`, `getItem(` and `STORAGE_*` exports whose key matches `/auth|token|owner_id|device|presence|consent|synced_at|deleted|backfilled|migrated|stamps|favorites|setlists|history|custom_tags/`; none may be in the registry. (c) For each `<select>`-based setting, every `<option value>` in `includes/pages/settings.php` (`#setting-transition`, `#setting-cvd-mode`) passes its validator, and the `range` min/max/step for `#setting-font-size` matches the validator. (d) Every registry key is exported from `constants.js`. | Add `STORAGE_AUTH_TOKEN` to the registry: (b) fails. Add `<option value="sepia">` to the transition select: (c) fails. Inline a typed list in `settings.js`: (a) fails. |
| **G2** | `tests/test-settings-sync-core.js` | Truth table for `mergeEntries`, `decodeEnvelope`, `encodeEnvelope` and `nextStamp`: newer remote is adopted; older remote is pushed back; a tie keeps local; a tombstone removes; an invalid value is dropped; an unknown key is ignored; `{"k":{"__proto__":…,"constructor":…}}` leaves `({}).polluted === undefined`; malformed JSON gives an empty result and no throw; an oversize encode returns `null`; the stamp never goes backwards when `Date.now` is stubbed earlier. | Flip `>` to `>=`: the tie row fails. Replace the registry walk with `for…in parsed`: the pollution row fails. Remove the `Math.max`: the clock row fails. |
| **G3** | `tests/test-settings-sync-cookie.js` (uses jsdom, a simulated browser page) | (a) Every key passed to `app.syncStorage(` anywhere in `js/` (collected by regex from the tree) is fed through `CrossSiteSync.recordLocalChange`; afterwards the cookie holds **only** registry keys, so favourites, set lists, history and consent never appear. (b) Two simulated hosts sharing one cookie jar: a theme change on A appears on B after `init()`; a reset on A clears B. (c) Every direct `localStorage.setItem(<registry key>` in `settings.js` is followed within the same block by `syncStorage(`. (d) `privacy.php` contains the exported `CROSS_SITE_COOKIE` name and does **not** contain "hidden iframe". (e) No PHP file reads `$_COOKIE['ihymns_prefs']`. (f) `app.js` contains no `Object.entries(data)` loop followed by `localStorage.setItem` (the old overwrite). | Remove the registry check in `recordLocalChange`: (a) fails. Remove tombstones: (b) fails. Delete the `syncStorage` call after the colour-filter write: (c) fails. Rename the cookie: (d) fails. |
| **G4** | `tests/php/test-frame-headers.php` | Scan **every** `.htaccess` under `appWeb/` and every `.php` that calls `header(` (found with a glob). (a) Exactly one `X-Frame-Options` *set*, at the top level of `appWeb/public_html/.htaccess`, not inside any `<Files>`, `<FilesMatch>`, `<If>` or `<Location>`. (b) Cookie version: **zero** `unset X-Frame-Options` anywhere, and `index.php` `frame-src` contains no `ihymns.app` origin. Frame version: exactly one unset, inside `<Files "bridge.html">`, with exactly one `bridge.html` in the tree, and that block's `frame-ancestors` contains only `https://` origins from `appCanonicalHost()`'s `$allowed` list (read by parsing `config.php`), with no wildcard and no `.net`. (c) `frame-ancestors 'self'` is still present in `index.php` and `manage/includes/auth.php`. | Add a top-level `Header always unset X-Frame-Options`: (b) fails. Add `https://*.ihymns.app` back to `frame-src`: (b) fails. Change `index.php` to `frame-ancestors *`: (c) fails. Frame version: wrap the unset in `<FilesMatch "\.html$">`: (b) fails. |
| **G5** | `tests/php/test-cross-site-sync-switch.php` | Truth table for `crossSiteSyncAllows()` (`none`, empty, `alpha`, `Alpha, BETA`, junk words). `CROSS_SITE_SYNC_DEFAULT === 'none'`. `index.php` sends `crossSiteSync.enabled` only from `crossSiteSyncEnabledHere()`. The `save_cross_site_sync` handler calls `validateCsrfRequest` and checks `manage_configuration`. The API-coverage guard maps the action. | Default set to `'production'`: fails. Remove the CSRF call: fails. |
| **G6** | Added to G3 | Every `/js/...` path in the `service-worker.js.php` pre-cache list exists on disk. Generic, derived from the list itself. | Delete `subdomain-sync.js` without removing its line: fails. |
| **G7** (frame version only) | `tests/test-bridge-agreement.js` | (a) `bridge-host.js` and `storage-bridge.js` both `import … from` the same registry module path, and neither contains a `'ihymns_` text literal: one key list on both sides. (b) `BRIDGE_ALLOWED_ORIGINS` in `bridge-host.js` equals, as a set, the `frame-ancestors` list in the `.htaccess` `<Files "bridge.html">` block, and is a subset of `appCanonicalHost()` hosts with `https://` in front. (c) `bridge.html` has no `<script>` without `src=` (the `test-fragment-inline-scripts.php` method, with comments stripped). (d) No `postMessage(…, '*')` in either file. | Inline a typed key list in `bridge-host.js`: (a) fails. Add `https://ihymns.net` to one list: (b) fails. Put an inline script back: (c) fails. Change the reply target to `'*'`: (d) fails. |

**Existing tests touched:**

- `tests/php/test-search-visibility.php` 5.5 (Step 8).
- `tests/php/test-captcha-gate.php` 5.2 must stay green; the `{$cspCaptchaFrame}` token stays.
- `tests/test-event-names.js` needs the new event in `constants.js`, with one sender and one listener.

---

## 6. Rollout and risk

1. **alpha:** Steps 1–7 land with everything switched off. Step 1 alone stops the console warnings and the extra frame download on every page. Run `/manage/setup-database`: nothing is needed there, because the setting row is created on first save and its absence means `'none'`.
2. **beta:** promote. Still off.
3. **Switch on `alpha,beta`** on `/manage/configuration`. Each channel has one address, so testing between two hosts needs both on.
4. **Check on real devices** (Safari on iOS and macOS, Chrome on Android and desktop, Firefox desktop, plus one private window):
   - Theme, font size and colour filter changed on `dev.` appear on `beta.` at first paint, with no flash.
   - Reset on one clears the other.
   - Favourites and set lists stay different on each.
   - The cookie (in DevTools → Application) holds only the listed keys and is under 2 KB.
   - Signed-in users: sign in on one host, and the account settings win.
   - Safari: still works after 8 days away if the visitor has been back in between. A cookie that expires after 7 days with no visit is expected and loses nothing.
5. **main:** promote, then add `production`. If D2 = cross-channel, testers' display settings reach live, matching today's `ihymns_sync` behaviour.
6. **Step 11** after one release cycle.

**Rollback:**

- Untick the channels to go back to the old `SubdomainSync` immediately, with no deploy.
- Every step is a separate commit, so `git revert <sha>` undoes any one of them.
- Step 1 can be reverted on its own, but there is nothing to go back *to*, because the frame never worked.

**Owner configuration:**

- Cookie version: **none**. If `sync.ihymns.app` has a DNS record, **delete it** (D6): a forgotten DNS record is a takeover risk.
- Frame version: create the `sync.ihymns.app` DNS record, a virtual host whose docroot is the **production** folder (so the bridge follows main's deploys), and a TLS certificate. None of this can be confirmed from the repository.

**Risks:**

- **Settings briefly disagreeing between the three ways they can change** (same device, account, cookie). Accepted; a recent change always wins in the end.
- **Cookie size.** Guarded by the 3500-byte check.
- **Safari's 7-day limit.** Soft; it only means a fresh pull on the next visit.

---

## 7. Open decisions for the owner

**D1. How settings travel: rebuilt cookie (frame retired) or rebuilt frame?**

- **Why it needs deciding:** you said "rebuild, not remove". Investigation shows the frame can only reach places the existing cookie already reaches, at extra cost and risk. That is a change of direction you should approve.
- **Options:**
  - **(a) Cookie, frame deleted.** No host setup, no header exception, settings applied before first paint, fewer moving parts.
  - **(b) Frame, rebuilt strictly.** Needs DNS, a host setup and a certificate; a scoped header exception; an extra download per page; theme flips after the page appears; still cannot reach `ihymns.net`.
  - **(c) Both.** Double the upkeep for no gain.
  - **Do nothing.** Warnings continue and the data-loss trap stays.
- **Recommendation:** (a). It delivers what you asked for, settings that follow visitors safely, with the least risk.
- **What I need back:** "a", "b" or "c".
- **Blocks:** Steps 5, 8 and 9 only. Steps 1–4 and 7 can proceed now.

**D2. Should display settings cross between alpha, beta and live?**

- **Why it needs deciding:** there is a standing wish to keep channels apart, but crossing already happens today, through the cookie and the shared database.
- **Options:**
  - **Cross all channels, with per-value checks.** Consistent with today; a value only alpha understands is ignored by live.
  - **Same channel only.** In practice syncs almost nothing (only the bare domain and `www`, if both are served).
  - **Live plus beta only.**
- **Recommendation:** cross all, because only checked display values travel, never content.
- **What I need back:** "all", "same" or "live+beta". The default is "all", and it is one constant to change.
- **Blocks:** nothing.

**D3. Should anonymous visitors' favourites and set lists follow them between sites?**

- **Why it needs deciding:** it is a product choice, and doing it through the browser mixes channels and needs complex merge rules.
- **Options:**
  - **No.** Signing in already syncs them through the server.
  - **Browser union-merge.** High risk, and it breaks the channel walls.
- **Recommendation:** no; point anonymous users to sign-in.
- **What I need back:** "no" or "yes".
- **Blocks:** nothing.

**D4. Drop `ihymns.net` support?**

- **Why it needs deciding:** the bridge was its only reason to stay listed, and the browser rules in §2.2 mean `ihymns.net` cannot be synced anyway.
- **Options:**
  - **Drop it** from the sync and the `api-docs.yaml` comment.
  - **Keep it.** It stays an un-syncable legacy host.
- **Recommendation:** drop it from the sync. Remove the `api-docs.yaml` server entry only if `beta.ihymns.net` is no longer live; you will know, the repo does not.
- **What I need back:** "drop" or "keep", and whether `beta.ihymns.net` is still live.
- **Blocks:** nothing.

**D5. Which settings travel in version 1?**

- **Options:**
  - **The 14 keys signed-in sync already uses.**
  - **Those 14 plus chord notation, chord columns and language filter.**
- **Recommendation:** the 14 now. Adding a key later is one registry line plus its validator, and G1 checks the validator against the page.
- **What I need back:** "14" or "14+3".
- **Blocks:** nothing.

**D6. Does `sync.ihymns.app` exist in DNS?**

- **Why it needs deciding:** if it does and the frame is retired, a forgotten record can be taken over by someone else.
- **Options:** delete the record, or keep it.
- **Recommendation:** delete it if D1 = a.
- **What I need back:** whether it exists. Only your DNS panel can show this.
- **Blocks:** nothing.

**D7. Privacy wording and consent.**

- **Why it needs deciding:** the cookie is persistent and shared with the test sites.
- **Options:**
  - **Clear disclosure, no consent banner.** Treated as a user-chosen preference cookie.
  - **Ask for consent before writing it.**
- **Recommendation:** disclosure only. It is written only after the visitor actively chooses a setting. This is not legal advice.
- **What I need back:** "disclose" or "ask".
- **Blocks:** switching on production only.

**D8. When a signed-in user signs in, do account settings beat a newer change made on another site in the same browser?**

- **Options:**
  - **The account wins**, as it does today.
  - **The newest wins.** Needs timestamps per key on the server, which is a larger change.
- **Recommendation:** the account wins for now. That default is already built into Step 4 and is easy to change.
- **What I need back:** "account" or "newest".
- **Blocks:** nothing.

---

## 8. Effort

**Cookie version: 11 commits (Steps 1–11), with tests G1–G6.**

| Step | Model | Why |
|---|---|---|
| 1 | Sonnet | PHP helper copying an existing one, plus careful deletions |
| 2 | Sonnet | Validators and wiring into `settings.js` |
| 3 | Sonnet | Pure logic plus truth table; precise but self-contained |
| 4, 5, 6 | Sonnet | Wiring across `settings.js` and `app.js`, plus the order of start-up steps |
| 7 | Sonnet | Copy `save_search_visibility` and its form |
| 8 | Haiku | The exact delete/edit list above, plus one test assertion flipped |
| 9 | Haiku | Wording |
| 10 | Haiku | Documentation |
| 11 | Sonnet | Removing the old path after it has been verified |

**Guards:** G1, G3 and G4 need Sonnet, because they get their lists from the tree and must be shown to fail when broken. G2, G5 and G6 can be done by Haiku given the truth tables above.

**Frame version:** add about 5 commits (I1–I5) and G7, all Sonnet. Also the owner's DNS, host and certificate work, which cannot be done from the repository.

**Files most relevant to this plan:**

- `/home/user/iHymns/appWeb/public_html/bridge.html`
- `/home/user/iHymns/appWeb/public_html/js/modules/storage-bridge.js`
- `/home/user/iHymns/appWeb/public_html/js/modules/subdomain-sync.js`
- `/home/user/iHymns/appWeb/public_html/js/app.js`
- `/home/user/iHymns/appWeb/public_html/js/modules/settings.js`
- `/home/user/iHymns/appWeb/public_html/js/constants.js`
- `/home/user/iHymns/appWeb/public_html/includes/config.php`
- `/home/user/iHymns/appWeb/public_html/index.php`
- `/home/user/iHymns/appWeb/public_html/.htaccess`
- `/home/user/iHymns/appWeb/public_html/includes/pages/privacy.php`
- `/home/user/iHymns/appWeb/public_html/includes/search_visibility.php` (the shape to copy for the switch)
- `/home/user/iHymns/appWeb/public_html/manage/configuration.php`
- `/home/user/iHymns/appWeb/public_html/service-worker.js.php`
- `/home/user/iHymns/appWeb/public_html/robots.txt.php`
- `/home/user/iHymns/tests/php/test-search-visibility.php`
- `/home/user/iHymns/tests/php/test-captcha-gate.php`
