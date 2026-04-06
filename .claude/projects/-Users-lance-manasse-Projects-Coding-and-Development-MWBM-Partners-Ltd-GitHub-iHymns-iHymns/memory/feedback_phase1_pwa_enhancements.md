---
name: Phase 1 PWA enhancements — analytics, gestures, writer pages, TF-IDF, WCAG contrast
description: Major batch of web PWA enhancements completed in session on claude/rebuild-pwa-php-KjLJB branch, merged via PR #153.
type: feedback
---

Phase 1 PWA received a large batch of enhancements (PR #153 → beta):

**New JS Modules:**
- `js/modules/analytics.js` — Unified Analytics class dispatching to GA4, Plausible, Clarity, Matomo, Fathom
- `js/modules/gestures.js` — Touch swipe navigation (left/right for next/prev song) with edge peek indicators
- `js/utils/text.js` — toTitleCase() utility

**New PHP Pages:**
- `includes/pages/writer.php` — Writer/composer page showing all songs by a given writer, grouped by songbook
- Route added in `api.php` as `case 'writer':`

**Key Implementations:**
- TF-IDF cosine similarity for content-based related songs (client-side, IDF cached)
- WCAG relative luminance calculation for automated songbook badge text contrast
- Flexible song permalinks (MP-1 → MP-0001 normalization in router.js and SongData.php)
- Alphabetical songbook ordering (ignoring leading articles) in SongData.php
- GDPR-compliant analytics consent banner with localStorage persistence
- Shareable set lists via Base64-encoded URLs
- Page transition animations with loading progress bar
- PWA icons generated from favicon.svg (48-512px PNGs)

**Legal Pages Updated:**
- Privacy Policy expanded to 12 sections (analytics consent, cookies, sharing, security, third-party services)
- Terms of Use expanded to 12 sections (offline use, sharing/setlists, third-party libraries, analytics)

**Settings Updated:**
- New Privacy card with analytics consent toggle and DNT status display

**Config Changes:**
- `includes/config.php` — Added matomo_url, matomo_site_id, fathom_site_id
- `index.php` — Conditional Matomo/Fathom script loading, dynamic CSP entries
- `manifest.json` — All icon PNGs now referenced

**How to apply:** These features are all implemented. Future work should build on existing module architecture (analytics.js for tracking, gestures.js for touch, router.js for navigation).
