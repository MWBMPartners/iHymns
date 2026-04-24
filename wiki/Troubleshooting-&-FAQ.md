# Troubleshooting & FAQ

> Common issues, solutions, and frequently asked questions

---

## Troubleshooting

### PWA / Web App

#### Songs not loading or showing "Unable to load song data"
- **Cause:** The `songs.json` file is missing or inaccessible
- **Fix:** Ensure `appWeb/data_share/song_data/songs.json` exists. Run `npm run parse-songs` to regenerate it

#### Search returns no results
- **Cause:** Fuse.js search index may not have loaded
- **Fix:** Clear browser cache and reload. Check browser console for errors loading `songs.json`

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
- **Cause:** These features require a database connection. In JSON fallback mode (no MySQL), the API returns empty data
- **Fix:** This is expected in JSON-only mode. Popular Songs will fall back to your local viewing history. Browse by Theme requires the database with configured tags. If you're running with MySQL, check your database connection credentials

#### MIDI audio not playing
- **Cause:** Browser may not support MIDI playback, or the MIDI file is missing
- **Fix:** MIDI playback requires Web MIDI API support. Not all songbooks have MIDI files (only CP, JP, MP)

### Database

#### SQLite database gets wiped on deployment
- **Cause:** The `data_share/` directory is being deleted during SFTP sync
- **Fix:** Ensure the deploy script uses `--delete` only for `public_html/`, NOT for `data_share/`. The database path should be `dirname(__DIR__, 3) . '/data_share/SQLite/ihymns.db'`

#### "Table not found" errors
- **Cause:** Migrations haven't run yet
- **Fix:** Migrations run automatically on first database connection. If the issue persists, check that the SQLite file path is correct and the directory is writable

### Native Apps (iOS / Android)

#### App shows no songs
- **Cause:** The `songs.json` file may not be bundled correctly
- **Fix:** Ensure `songs.json` is included in the app bundle (iOS: Copy Bundle Resources, Android: assets folder)

---

## FAQ

### General

**Q: Is iHymns free?**
A: The web PWA is freely accessible at [iHymns.app](https://ihymns.app). Native apps may be distributed via app stores.

**Q: Can I use iHymns offline?**
A: Yes. Go to Settings > Offline Songs to download songbooks for offline use. You can download individual songbooks or all at once (~14 MB total). The bulk download uses an optimised API and completes in seconds. Songs you view are also automatically cached. The Popular Songs section works offline using your local viewing history.

**Q: Why is the offline download slow?**
A: If you're on an older version, update to the latest. The bulk download API (introduced in v0.10.x) reduces 3,612 individual HTTP requests to ~6, making downloads near-instant on any connection.

**Q: Does offline data sync across subdomains (dev/beta/live)?**
A: No. Browser cache storage is origin-scoped — each subdomain has its own cache. However, with the fast bulk download, re-downloading on each subdomain takes only seconds.

**Q: What songbooks are included?**
A: Carol Praise (CP), Junior Praise (JP), Mission Praise (MP), SDA Hymnal (SDAH), The Church Hymnal (CH), and Miscellaneous.

**Q: Do I need an account to use iHymns?**
A: No. You can browse, search, and save favourites without an account. An account is only needed for cross-device setlist sync.

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
A: Up to 50 setlists per user account, with up to 200 songs per setlist.

**Q: Can I share a setlist without an account?**
A: Yes. The "Share" feature generates a public link that anyone can import, no account needed.

**Q: What are custom arrangements?**
A: They let you reorder song components (verses, choruses, bridges, etc.) for a specific performance. See [[Setlists & Arrangements]].

**Q: Do shared setlists include custom arrangements?**
A: Yes. Custom arrangements are included in shared setlist links and preserved when imported.

### Technical

**Q: What browsers are supported?**
A: Any modern browser with ES module support: Chrome 80+, Firefox 78+, Safari 14+, Edge 80+.

**Q: What PHP version is required?**
A: PHP 8.5+ with `pdo_sqlite` extension (default). `pdo_mysql` or `pdo_sqlsrv` if using MySQL or SQL Server.

**Q: Can I use MySQL instead of SQLite?**
A: Yes. Change `'driver' => 'mysql'` in `db.php` and fill in the connection details. Migrations will run automatically. See [[Database & Migrations]].

**Q: How do I run the song parser?**
A: `npm run parse-songs` or `node tools/parse-songs.js`. This regenerates `data/songs.json` from the source files in `.SourceSongData/`.
