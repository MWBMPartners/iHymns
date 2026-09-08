# Getting Started

> Quick guide for users and developers

---

## For Users

### Using the Web App

1. Visit [iHymns.app](https://ihymns.app) in any modern browser
2. Browse songbooks from the home page
3. Tap a songbook to see its songs, then tap a song for lyrics
4. Use the search icon to find songs by title, lyrics, or number

### Installing as a PWA

1. Visit [iHymns.app](https://ihymns.app) on your phone or computer
2. Look for the "Install" or "Add to Home Screen" prompt
3. Once installed, iHymns works like a native app — even offline

### Creating an Account

An account is optional but enables cross-device setlist sync:

1. Click the user icon in the header
2. Choose "Create Account"
3. Enter a username (3+ chars), display name, and password (8+ chars)
4. Your setlists will sync automatically across all your devices

### Using Setlists

1. Navigate to any song
2. Click "Add to Set List" and choose or create a setlist
3. Go to `/setlist` to manage your setlists
4. Click "Arrange" on a song to customise the performance order
5. Click "Share" to send a setlist to others

### Keyboard Shortcuts

**Navigation**

| Key | Action |
|---|---|
| `/` or `Ctrl`+`K` | Open search |
| `#` | Open the number pad |
| `←` | Previous song |
| `→` | Next song |

**Actions**

| Key | Action |
|---|---|
| `F` | Toggle favourite |
| `P` | Presentation mode (one section at a time) |
| `B` | Blank the screen while presenting |
| `L` | Open set lists |
| `S` | Start auto-scroll |
| `Space` | Pause auto-scroll |
| `PageDown` | Next section — for a foot pedal or MIDI controller |
| `PageUp` | Previous section — for a foot pedal or MIDI controller |
| `+` / `-` | Font size |
| `Esc` | Close the shortcuts overlay, or leave Presentation mode |
| `?` | Show this list on screen |

**Quick-jump to a song number**

| Key | Action |
|---|---|
| `0`–`9` | Type the song number |
| `Enter` | Go to that song |
| `Backspace` | Delete the last digit |
| `Esc` | Cancel the number you are typing |

> **Corrected 2026-09-08.** This table listed eight shortcuts and was missing more than half of the
> real set — `Ctrl`+`K`, `B`, `S`, `Space`, `PageUp`/`PageDown`, `+`/`-` and `Esc` were all absent.
> The list above is taken from the app's own on-screen overlay (`js/modules/shortcuts.js`), which is
> the authoritative one. Worth knowing that this exact drift has happened before: #1714 made the `B`
> key work and updated the `/help` table but left the on-screen overlay behind, so all three lists
> are downstream of the same code and go stale the same way.

---

## For Developers

### Prerequisites

- Node.js v22+ and npm v10+
- PHP 8.4 or 8.5 (for the local web server) — those are the two versions CI runs the PHP suite on; this line said "8.5+" until 2026-09-08
- Git

### Quick Start

```bash
# Clone
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Install dependencies
npm install

# Parse song data
npm run parse-songs

# Run tests
npm test

# Start local server
cd appWeb/public_html
php -S localhost:8080
```

### Key Files to Know

| File | What it does |
|---|---|
| `appWeb/public_html/index.php` | SPA shell |
| `appWeb/public_html/api.php` | All API endpoints |
| `appWeb/public_html/js/app.js` | JS app entry point |
| `appWeb/public_html/manage/includes/auth.php` | Auth & role system — requires `includes/db_mysql.php` directly (no separate `db.php` wrapper exists) |

### Further Reading

- [[Architecture]] — how the codebase is structured
- [[API Reference]] — all API endpoints
- [[Development Setup]] — coding standards, commit conventions
- [[Database & Migrations]] — schema and migration system
- [[Security]] — CSP, auth, input sanitisation
