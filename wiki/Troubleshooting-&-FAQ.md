# Troubleshooting & FAQ

> Common issues, solutions, and frequently asked questions

---

## Troubleshooting

### PWA / Web App

#### Songs not loading / "briefly unavailable" page
- **Cause:** The database is unreachable, or the site is in maintenance mode
- **Fix:** Admins should check the maintenance toggle at `/manage/configuration` and confirm the DB credentials are correct. This is a themed 503, not a data problem — songs you've already downloaded for offline use remain readable

#### A song/songbook link shows "Failed to load page. Please check your connection and try again."
- **Cause:** This message is now reserved for a genuine network failure. A song, songbook, writer, person, work, or tag that's been removed, merged, or never existed shows its own specific explanation instead (e.g. "This song has been removed — it may have been a duplicate that was merged" with a 410 status) — the SPA used to discard that explanation and show this generic message for every server-side error, not just real connectivity problems (#1705)
- **Fix:** If you see the generic message, it's worth a retry — the server likely never got the request. If you instead see a specific "not found" / "removed" card, that's the correct explanation, not an error to troubleshoot

#### Search returns no results
- **Cause:** Fuse.js's client-side search index may not have loaded
- **Fix:** Clear browser cache and reload. Check the browser console for errors on the search request

#### "Access denied" when accessing the Song Editor
- **Cause:** Your user account doesn't have the `editor` role or above
- **Fix:** Ask an admin or global admin to assign you the `editor` role via `/manage/users`

#### Cannot log in to admin panel
- **Cause:** Account may be disabled, or password incorrect
- **Fix:** Use the "Forgot password" feature, or ask a global admin to check your account status

#### Setlists not syncing
- **Cause:** Not logged in, or bearer token expired (30-day limit)
- **Fix:** Sign out and sign back in to get a fresh token. Check `/api.php?action=auth_me` for token validity

#### Dark mode not applying
- **Cause:** Theme setting may be stuck in localStorage
- **Fix:** Go to Settings, manually select a theme. If the issue persists, clear `ihymns_theme` from localStorage

#### PWA install banner not showing
- **Cause:** Already installed, or browser doesn't support PWA installation
- **Fix:** The banner is hidden if the app is detected as already installed (including cross-subdomain detection). Check the browser's "Install app" option in the address bar

#### PWA shows blank page when opened offline
- **Cause:** Service worker may not have cached all required JavaScript modules
- **Fix:** Open the app once while online to ensure all assets are precached. Go to Settings > Offline Songs and download at least one songbook. If the issue persists, clear the app cache (Settings > Clear Cache) and reload while online

#### Popular Songs or Browse by Theme shows "Loading..."
- **Cause:** These features require a live database connection; if MySQL is unreachable the API returns a 503 rather than data
- **Fix:** Popular Songs falls back to your local viewing history automatically. Browse by Theme has no offline fallback — check the database connection credentials and the maintenance toggle at `/manage/configuration`

#### Export ▾ / Present does nothing
- **Cause:** Usually an out-of-date cached service worker after a deploy
- **Fix:** Hard-reload the page once. If it still doesn't work, an admin can check `/manage/activity-log` for a `client.jserror` row from around that time — it will usually name the failing module

#### A live-session code is rejected
- **Cause:** The most common cause is the host and the joiner being on different sites (dev vs beta vs www) — Live Follow and Service Mode sessions never cross environments even though they share one database. The coloured badge by the logo identifies which site you're on
- **Fix:** Put both devices on the exact same site address. If they already are, the session may simply have expired — see [[Live Follow & Service Mode]] for the freshness/lifetime rules

#### MIDI audio not playing
- **Cause:** Browser may not support MIDI playback, or the MIDI file is missing
- **Fix:** MIDI playback requires Web MIDI API support. Not all songbooks have MIDI files (only CP, JP, MP)

### Live Follow / Service Mode

See [[Live Follow & Service Mode]] for how the two features differ. Common symptoms:

#### Tapping "Go Live" shows "Could not start the session: Unknown column 'Channel'…" (or another 500 error)
- **Cause:** The Service Mode schema hasn't been applied on this install yet — Live Follow needs it too, even though it uses no venue
- **Fix:** An admin runs the "Org Venues & Service Schedules" then "Service Mode sessions" cards on `/manage/setup-database`

#### No "Go Live" button appears on a song page
- **Cause:** The button only renders for signed-in users
- **Fix:** Sign in, then reload the page

#### "That doesn't look like a valid session code."
- **Cause:** The code isn't 4–12 letters/digits once spaces/dashes are stripped
- **Fix:** Re-type it — real codes never contain `0`, `1`, `O`, `I`, `L`, or `U`

#### "That code isn't active. Check the screen and try again."
- **Cause:** The code has rotated past its ~75s window, or the wrong site/venue was targeted
- **Fix:** Read the current code straight off the screen and retry

#### "Session not found or ended." even though the leader's device shows LIVE
- **Cause:** The two devices are on different environments (e.g. one on `dev`, one on `www`) — sessions never cross environments even though they share one database
- **Fix:** Put both devices on the exact same site address

#### Same message, but both devices are genuinely on the same address
- **Cause:** The leader's screen slept (heartbeat lapsed past the 180s freshness window), the code was mistyped, or the 4-hour hard ceiling was reached
- **Fix:** Wake the leader's screen (it self-heals on `focus`/`visibilitychange`) and re-join

#### "The live session has ended." / "The service has ended." mid-follow
- **Cause:** The host ended it, signed out, started a new session (which supersedes the old one), their screen slept past the freshness window, or it hit its lifetime cap
- **Fix:** Get the new code and re-join

#### "iHymns is down for maintenance — try the code again in a minute. The leader's code is fine."
- **Cause:** Maintenance mode is on for that site; the admin host bypasses it, anonymous joiners don't
- **Fix:** Wait a minute and retry — the code itself isn't the problem

#### "Too many requests. Please try again later."
- **Cause:** Rate limits (20 session-creates/hour/user, 120 joins/minute) were hit
- **Fix:** Wait a minute and try again

#### "Leave the session you're following before hosting your own."
- **Cause:** One device can't simultaneously host a Live Follow session and follow another
- **Fix:** Leave (or End) the existing session first

### Database

iHymns is **MySQL-only** — there is no SQLite fallback and no runtime JSON copy of the song corpus. All three environments (dev/beta/live) share one MySQL database.

#### "Table not found" / a page white-screens with a DB error
- **Cause:** Migrations are **web-run**, not auto-applied on deploy — a table or column from a recent migration may not exist yet on this environment
- **Fix:** An admin applies the pending card(s) from `/manage/setup-database`. The dashboard's pending counter reflects what's actually missing on this install

#### Media files (audio/sheet music) disappeared after a deploy
- **Cause:** Historically, deploys could wipe `data/audio/` and `data/music/` — fixed in #1584, which excludes both from the docroot mirror
- **Fix:** Confirm you're on a build after #1584. If media is still missing, it's a data problem, not a deploy-exclusion problem

### Native Apps (iOS / Android)

#### App shows no songs
- **Cause:** No network connection and nothing has been downloaded for offline use yet
- **Fix:** Connect once to let the app read from the live API; downloaded content is then cached locally (Apple: GRDB-backed offline store) for later offline use

---

## FAQ

### General

**Q: Is iHymns free?**
A: The web PWA is freely accessible at [iHymns.app](https://ihymns.app). Native apps may be distributed via app stores.

**Q: Can I use iHymns offline?**
A: Yes. Go to Settings > Offline Songs to download songbooks for offline use. You can download individual songbooks or all at once (~14 MB total). The bulk download uses an optimised API and completes in seconds. Songs you view are also automatically cached. The Popular Songs section works offline using your local viewing history.

**Q: Why is the offline download slow?**
A: If you're on an older version, update to the latest. The bulk download API reduces what would otherwise be thousands of individual HTTP requests down to a handful per songbook, making downloads near-instant on any connection.

**Q: Does offline data sync across subdomains (dev/beta/live)?**
A: No. Browser cache storage is origin-scoped — each subdomain has its own cache. However, with the fast bulk download, re-downloading on each subdomain takes only seconds.

**Q: What songbooks are included?**
A: ~30+ songbooks in ~20 languages — the list keeps growing, so see the live, always-accurate list at [/songbooks](https://ihymns.app/songbooks).

**Q: Do I need an account to use iHymns?**
A: No. You can browse, search, and save favourites without an account. An account is only needed for cross-device setlist sync.

**Q: Do I need an account to follow along live?**
A: No, to join either kind of session — Live Follow or Service Mode. You need any free account to lead your own session with Go Live (Live Follow), and church-admin rights to run Service Mode. See [[Live Follow & Service Mode]].

### User Accounts

**Q: What's the difference between the account roles?**
A: See [[User Accounts & Roles]]. In short: User = setlist sync, Editor = edit songs, Admin = manage users, Global Admin = full access.

**Q: How do I become an editor?**
A: Ask an Admin or Global Admin to assign you the `editor` role via `/manage/users`.

**Q: I forgot my password. What do I do?**
A: Click "Forgot password?" on the sign-in modal. Enter your username or email to receive a reset token. In the current version, the token is displayed directly (email delivery coming soon).

**Q: Can I change my username?**
A: Not currently. Usernames are permanent and lowercase.

**Q: Who is the Global Admin?**
A: The first person to create an account (either via `/manage/setup` or the public registration API) automatically becomes the Global Admin.

### Setlists

**Q: How many setlists can I have?**
A: As many as you like — the owner deliberately removed the old 50-setlist ceiling (#1661), so a setlist only goes away when you delete it or an expiry date you set passes. The one real limit is 200 songs *within* a single setlist; pushing more than that is refused outright (HTTP 413) rather than silently dropping the extra songs. See [[Setlists & Arrangements]] for detail.

**Q: Can I share a setlist without an account?**
A: Yes. The "Share" feature generates a public link that anyone can import, no account needed.

**Q: What are custom arrangements?**
A: They let you reorder song components (verses, choruses, bridges, etc.) for a specific performance. See [[Setlists & Arrangements]].

**Q: Do shared setlists include custom arrangements?**
A: Yes. Custom arrangements are included in shared setlist links and preserved when imported.

**Q: A shared live set-list link says it's "no longer shared" — why?**
A: A shared **live** set-list link stops serving once the set-list's own expiry passes (#1699). Previously a live share honoured only the link's own expiry and ignored the underlying set-list's, so an expired set-list could keep serving on the anonymous share/social surfaces; now an expired one resolves to "no longer shared" (empty/410) — no data is deleted, the owner still has it.

### Technical

**Q: What browsers are supported?**
A: Any modern browser with ES module support: Chrome 80+, Firefox 78+, Safari 14+, Edge 80+.

**Q: What PHP version is required?**
A: PHP 8.5+ with the `mysqli` extension. There is no PDO or SQLite dependency — PDO was fully removed from the codebase.

**Q: Can I use SQLite or SQL Server instead of MySQL?**
A: No. MySQL 5.7+ / MariaDB 10.3+ is the only supported database, via `getDbMysqli()`. See [[Database & Migrations]].

**Q: How do I run the song parser?**
A: `npm run parse-songs` or `node tools/parse-songs.js`. This reads the source files in `.SourceSongData/` and writes a gitignored `tmp/songs.json` local build artefact — not something the running app reads, and not part of setting up a fresh install any more (the one-time bootstrap script that used to consume it, `appWeb/.sql/migrate-json.php`, was retired in #1614; new song content goes in through the Song Editor's bulk importers instead).

**Q: Why does a made-up URL like `/wp-admin` return 404 now?**
A: That's deliberate (#1905). A path the app doesn't own — a `/wp-admin/` scanner probe, or any unknown URL — returns a real HTTP 404 instead of the old soft HTTP 200 with the app shell. Obvious scanner-bait is 404'd at the web-server edge; everything else is checked by the front controller against a valid-route list **derived from the app's own pages**, so a genuine new page is recognised automatically (a CI guard keeps that list in lockstep with the client router). A real iHymns page will not 404.

**Q: What is the `X-Powered-By: iHymns/<version>` response header?**
A: Part of a defensive hardening pass (#1906). The header now advertises our own `iHymns/<version>` identity, and the PHP runtime version is suppressed at source (`expose_php=Off`) so a scanner can't read the exact PHP build off the response. The same pass added security headers/CSP to the admin area and the social-card endpoint, per-email brute-force buckets, a session-fixation fix, and rate limits on several heavy public endpoints — all entirely behind the scenes, with no user-visible behaviour change.

**Q: Why isn't beta/dev showing up on Google? / Why did pages drop out of search?**
A: Almost certainly the new "Search engine visibility" card on `/manage/configuration` (#2024/#2025) — beta and dev are hidden from search engines by default (only production is listed out of the box), and an admin can also switch production off deliberately. Check that card first; the change takes days to weeks to be reflected in search results either way, since search engines only update on their next crawl of a page. See [[Deployment & CI-CD]]'s "Per-channel search-engine visibility" section for the full mechanics.
