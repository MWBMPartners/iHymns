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

| Key | Action |
|---|---|
| `/` | Open search |
| `#` | Open numeric keypad |
| `?` | Show keyboard shortcuts help |
| `F` | Toggle favourite |
| `P` | Toggle presentation mode |
| `L` | Go to setlists |
| `Left/Right` | Previous/next song |
| `0-9` | Quick-jump to song number |

---

## For Developers

### Prerequisites

- Node.js v22+ and npm v10+
- PHP 8.5+ (for local web server)
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
| `data/songs.json` | All song data (generated, don't edit manually) |
| `appWeb/public_html/index.php` | SPA shell |
| `appWeb/public_html/api.php` | All API endpoints |
| `appWeb/public_html/js/app.js` | JS app entry point |
| `appWeb/public_html/manage/includes/auth.php` | Auth & role system |
| `appWeb/public_html/manage/includes/db.php` | Database & migrations |

### Further Reading

- [[Architecture]] — how the codebase is structured
- [[API Reference]] — all API endpoints
- [[Development Setup]] — coding standards, commit conventions
- [[Database & Migrations]] — schema and migration system
- [[Security]] — CSP, auth, input sanitisation
