# PWA Features

> Complete feature list for the iHymns Progressive Web App

---

## Core Features

### Song Browsing
- **Songbook grid** — visual cards for each songbook with song count badges
- **Song list** — sortable by title (default), number, or songbook
- **Song detail** — formatted lyrics with verse/chorus/bridge labels
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
- **Persistent** — stored in localStorage

### Setlists
- **Create setlists** — named playlists for worship services
- **Add/remove/reorder** songs within a setlist
- **Custom arrangements** — per-song arrangement editor (ProPresenter 7-style)
  - Component pool: click to add components (V1, C, B, PC1, etc.)
  - Arrangement strip: drag-and-drop to reorder, click to remove
  - Live lyrics preview of the custom arrangement
  - 12 component types with colour-coded short tags
- **Share setlists** — generate shareable links with optional arrangements
- **Import shared setlists** — import from shared links
- **Cross-device sync** — merge local and server setlists when logged in

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

### Responsive Design
- **Mobile-first** — optimised for phones
- **Tablet layout** — wider content area, sidebar navigation
- **Desktop layout** — full-width with hover effects
- **Print stylesheet** — clean lyrics-only print output

---

## PWA Capabilities

### Offline Support
- **Service worker** — caches all assets and song data for offline use
- **Offline indicator** — shows connection status in UI
- **Auto-update** — detects new service worker versions and prompts to refresh
- **Songs cached** — full `songs.json` available offline via service worker

### Installation
- **Install banner** — dismissible prompt for PWA installation
- **Cross-subdomain detection** — hides banner if already installed on another subdomain
- **Manifest** — full PWA manifest with icons (48px–512px), theme colours, display mode

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
- **Dynamic XML sitemap** — auto-generated from song database (`sitemap.xml.php`)
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
