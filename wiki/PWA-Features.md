# PWA Features

> Complete feature list for the iHymns Progressive Web App

---

## Core Features

### Song Browsing
- **Songbook grid** — visual cards for each songbook with song count badges
- **Song list** — sorted by number by default; a **Sort ▾** control (#1786 Option B) lets you build up to 3 sort levels (e.g. "Title, then Number") on every catalogue list — songbooks, a songbook's songs, themes, musicians, tunes, publishers, works, identifier lookups, favourites, and search results. Your choice is remembered on this device and, when signed in, synced across your devices.
- **Song detail** — formatted lyrics with verse/chorus/bridge labels; where a curator has recorded chords, an **inline chord chart with transpose** raises/lowers the key in place and can be toggled off (#299); a song's **themes/tags show as tappable chips** linking to the theme page (#288)
- **Article-blind alphabetising** (#150) — songbook and song lists that sort by title ignore a leading "The"/"A"/"An", so *The Church's One Foundation* files under **C**, not **T**
- **Writer/composer pages** — all songs by a given writer, grouped by songbook

### Search
- **Full-text search** — fuzzy search across titles, lyrics, writers, composers (Fuse.js)
- **Number search** — jump to a song by number within a songbook
- **Numeric keypad** — modal number pad for quick song lookup (`#` keyboard shortcut)
- **Search history** — recent search terms with one-click re-search
- **TF-IDF related songs** — content-based similarity for "Related Songs" on song pages

### Favourites
- **Save/unsave** songs with star button or `F` keyboard shortcut
- **Favourites page** — browse all favourited songs
- **Persistent** — synced to your account when signed in (cross-device); stored locally when signed out

### Setlists
- **Create setlists** — named playlists for worship services
- **Add/remove/reorder** songs within a setlist
- **Custom arrangements** — per-song arrangement editor (ProPresenter 7-style)
  - Component pool: click to add components (V1, C, B, PC1, etc.)
  - Arrangement strip: drag-and-drop to reorder, click to remove
  - Live lyrics preview of the custom arrangement
  - 11 component types with colour-coded short tags (plus `refrain` as a display alias of Chorus)
- **Share setlists** — generate shareable links with optional arrangements
- **Import shared setlists** — import from shared links
- **Cross-device sync** — merge local and server setlists when logged in
- **Playback / navigation mode** (#1533) — tap any song in a setlist (own or shared) to arm a fixed
  bottom bar: Prev/Next, position ("3 of 12"), next song's title, arrow-key navigation, exit control.
  Survives a page reload (`sessionStorage`), ends on tab close. See [[Setlists & Arrangements]].

### Live Follow & Service Mode
- **Host broadcast** — any signed-in user taps **Go Live** on a song to broadcast the songs they open to anyone who joins with their code (Live Follow, #1268)
- **Anonymous join** — following either kind of session needs no account, just the code
- **Near-instant follow** — ~2.5s song-level follow for Live Follow; Service Mode additionally syncs which section (verse/chorus) is showing
- **Rotating venue codes** — a church's Service Mode code rotates roughly every 30 seconds, with the previous code still accepted for a short grace period
- **Colour-coded banners** — a blue "Following … live" bar for Live Follow, a green "Following the service live" bar for Service Mode, so the two are never confused at a glance

See [[Live Follow & Service Mode]] for the full comparison and setup requirements.

### Export & Present
- **Export ▾** — on any song page or songbook page, download the words in the format your projection software uses: OpenSong, OpenLyrics/OpenLP, ProPresenter 6, ProPresenter 7+, VideoPsalm, FreeShow, Proclaim, or ChordPro (a plain chord-sheet file) — 8 formats, offered on both surfaces. Exporting a large songbook (500+ songs, e.g. Mission Praise) asks for confirmation first and shows progress while the ProPresenter bundle builds (#1571). When a single song has a **published background video or image**, its ProPresenter 7+ export is delivered as a `.probundle` that **embeds that media** (referenced from a "Lyrics Background" cue, resolved next to the bundle) so the background travels with the song; songs without a background export as a plain `.pro` (#1979).
- **Present** — opens a full-screen, one-stanza-at-a-time view for projection, no export needed
- **Print & Download PDF** — Print a song or set list through a curator-designed **print template**; signed-in users also get **Download PDF** (a server-rendered PDF — a whole set list becomes one file, #1767 remainder) and CCLI copy reporting where the org holds a licence. See [[Setlists & Arrangements]] § Printing & PDF
- If the Export menu opens but nothing downloads, a hard-reload once usually fixes it (an older cached service worker) — see [[Troubleshooting & FAQ]]

### Audio & Sheet Music
- **MIDI playback** — play MIDI audio files where available (CP, JP, MP)
- **PDF sheet music** — view sheet music PDFs where available (CP, JP, MP)

---

## User Interface

### Theme & Display
- **Light mode** — default, clean neutral slate/grey
- **Dark mode** — charcoal blue `#0f172a`
- **High contrast mode** — for visual accessibility
- **System mode** — follows OS preference
- **Colourblind mode** — CVD-safe palette (Wong 2011)
- **Adjustable font size** — lyrics font size slider in settings
- **Presentation mode** — fullscreen lyrics display for projection

### Navigation
- **Clean URLs** — `/song/MP-0001`, `/songbook/CP`, `/setlist`
- **History API routing** — SPA with pushState navigation
- **Page transitions** — smooth animated transitions between pages
- **Touch gestures** — swipe left/right for next/previous song
- **Keyboard shortcuts** — `?` help overlay, `/` search, `#` numpad, `F` favourite, `P` present
- **Quick-jump** — type a number to jump to that song in the current songbook
- **Reading progress** — scroll-linked progress bar on song pages
- **Alphabetical index** — quick letter-jump on songbook song lists
- **What's New** — the version number in the footer links to `/whats-new`, which shows what changed in recent releases (#1583)

### Responsive Design
- **Mobile-first** — optimised for phones
- **Tablet layout** — wider content area, sidebar navigation
- **Desktop layout** — full-width with hover effects
- **Print stylesheet** — clean lyrics-only print output

---

## PWA Capabilities

### Offline Support
- **Service worker** — caches app assets for offline use, plus whichever songbooks you've explicitly downloaded
- **Bulk download** — download entire songbooks in seconds via an optimised bulk API (a handful of requests per songbook instead of one request per song)
- **Per-songbook downloads** — download individual songbooks from Settings with estimated storage sizes
- **Background downloads** — downloads continue when navigating away from Settings
- **Offline indicator** — shows connection status in UI
- **Auto-update** — detects new service worker versions and prompts to refresh; optional auto-update for offline songs
- **Downloaded songbooks** — songbooks you explicitly save are stored on-device; a slim id/number/title index enables offline search of what you've saved. The **saved-for-offline count** now also includes songs you deliberately downloaded, not just auto-cached views (#112)
- **Popular songs offline** — falls back to localStorage view history when server unavailable
- **Graceful degradation** — if the database is unreachable the app shows a friendly maintenance page; songbooks you previously downloaded stay readable
- **Conditional catalogue refresh (#1921)** — the offline-search index (the slim id/number/title list above) is refetched via a conditional request: when the catalogue hasn't changed since your last visit, the server answers with an empty "not modified" reply instead of resending the whole index, so a routine app open costs far less data than it used to.

### Installation
- **Install banner** — dismissible prompt for PWA installation
- **Cross-subdomain detection** — hides banner if already installed on another subdomain
- **Manifest** — full PWA manifest with icons (48px–512px), theme colours, display mode
- **Safe areas** — respects device safe areas (notch, home indicator) on all screens including presentation mode

### Number Search & Default Songbook
- **Default songbook** — pre-selects in number search, quick-jump, and shuffle
- **Live search toggle** — configurable in Settings (off by default to avoid disruption)
- **Numeric keypad** — touch-friendly with Go button for explicit navigation

### Song Translations
- **Translation linking** — songs linked to equivalent translations in other languages
- **Bidirectional lookup** — viewing any translation shows all related language versions
- **Editor support** — manage translation links in the song editor

---

## User Accounts

See [[User Accounts & Roles]] for full details.

- **Sign In / Register** — modal in the header, available on all pages
- **Role-based access** — Global Admin, Admin, Curator/Editor, User
- **Setlist sync** — cross-device setlist synchronisation for logged-in users
- **Password reset** — forgot password flow with secure token
- **Header dropdown** — shows user info, role, sync, and sign out when logged in

---

## Analytics & Privacy

### Analytics Providers
- Google Analytics 4 (GA4)
- Plausible Analytics
- Microsoft Clarity
- Matomo
- Fathom Analytics

### Privacy
- **GDPR consent banner** — opt-in analytics consent with localStorage persistence
- **Do Not Track (DNT)** — respects browser DNT header, anonymises IP addresses
- **No cookies** (for analytics) — consent tracked in localStorage
- **Privacy policy** — comprehensive 12-section policy at `/privacy`
- **Terms of use** — 12-section terms at `/terms`

---

## Social Sharing

### Open Graph Meta Tags
- **Dynamic per-page** — customised title, description, and image for each song, songbook, and page
- **Rich previews** — Facebook, Twitter, Slack, WhatsApp show song title, songbook, first lyrics lines
- **Dynamic OG images** — generated via `og-image.php` (1200x630, contextual)

### JSON-LD Structured Data
- **WebSite schema** with SearchAction (home page)
- **MusicComposition** schema for song pages (title, composers, lyricists)
- **BreadcrumbList** for navigation context

### SEO
- **Canonical URLs** — prevents duplicate content
- **Dynamic XML sitemap** — `/sitemap.xml` is a sitemap INDEX, auto-generated from the live database (`sitemap.xml.php`); its children (`?section=…&page=…`, served as `/sitemap-<section>[-<page>].xml`) cover songbooks, songs (paginated at 10,000/page), musicians, themes, works, publishers and tunes, each with an honest `<lastmod>` sourced from that row's own last-updated timestamp (never a placeholder "today"). Supports conditional GET (ETag / Last-Modified / 304) so a repeat crawler hit costs almost nothing when nothing changed, and degrades to a still-valid, DB-free body + `503 Retry-After` on a database outage rather than a broken response (2026-08-30 hardening, #2023).
- **Flexible permalinks** — `MP-1` normalises to `MP-0001`

---

## Accessibility

- **WCAG 2.1 AA** compliant
- **Skip-to-content** link for keyboard navigation
- **Focus indicators** — visible focus outlines on all interactive elements
- **Reduced motion** — respects `prefers-reduced-motion`, disables animations
- **Reduced transparency** — respects `prefers-reduced-transparency`
- **Screen reader** — ARIA labels, roles, and live regions
- **Semantic HTML** — proper heading hierarchy, landmarks, lists
- **Colour contrast** — automated badge contrast via relative luminance calculation
- **Colourblind-safe palette** — Wong 2011 CVD-safe colours
- **Keyboard shortcuts** — full keyboard navigation without mouse
- **Card reorder without dragging** — Move up / Move down buttons alongside drag-and-drop for the admin dashboard and home page card layout, meeting WCAG 2.2 SC 2.5.7 (#1151)
- **Emphasise Links** (#1984) — an opt-in Settings toggle that gives in-text links an accent colour plus an underline (the underline was added 2026-08-30 so the mode clears WCAG AA text contrast in both themes); off by default, since the site's normal link styling is a deliberately understated hover-only cue
- **2026-08-30 WCAG 2.1 AA audit** (epic #2027) — a full pass across the public/PWA surface, the admin area and both editors found and fixed 20 findings (2 High, 8 Medium, 10 Low): dynamic, per-record browser-tab titles for every song/songbook/tag/musician/publisher/tune/work page; a proper keyboard focus trap on four more pop-up panels (keyboard shortcuts, a second presentation overlay, song comparison, the quick song-number picker); `prefers-reduced-motion` honoured consistently by every JS-driven smooth-scroll site; and dozens of smaller accessible-name gaps closed across the admin area and the legacy song editor
