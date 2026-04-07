---
name: Phase 1 PWA — SEO, social sharing, PWA install banners, editor arrangement, Song of the Day
description: Major batch of enhancements on claude/rebuild-pwa-php-KjLJB including dynamic OG images, PWA install prompts, song editor arrangement, expanded Song of the Day, CI/CD fixes.
type: feedback
---

Phase 1 PWA received another major batch of enhancements (session April 2026):

**SEO & Social Sharing (#170, #172, #173):**
- `og-image.php` — Dynamic PHP/GD OG image generator producing 1200×630 PNG
- Centre-safe layout: critical content in 630×630 centre zone for iMessage square-crop
- Contextual song images: `og-image.php?song=CP-0001` shows title, songbook badge with accent colour, writer, first verse lyrics
- Per-songbook accent colours matching CSS variables (CP=indigo, JP=pink, MP=teal, SDAH=amber, CH=red, Misc=purple)
- Generic image: app icon, name, tagline, accent line, domain
- `index.php` OG tags: canonical URL, 1200×630, `summary_large_image` Twitter card
- Song pages pass song ID to OG image URL automatically

**PWA Install Banner (#174, #175):**
- Safari on iOS: "Tap Share → Add to Home Screen" instructions
- Safari on macOS: "File → Add to Dock" instructions
- iOS Chrome/Edge/Firefox/Opera: "Open in Safari" guidance + Copy Link button
- Platform detection: `detectPlatform()` returns ios-safari, ios-chrome, ios-edge, ios-firefox, ios-other, macos-safari, android, desktop
- CSS fix: `!important` on `.d-none` prevents blank strip visual leak
- iOS safe-area: `env(safe-area-inset-top)` for notch/Dynamic Island

**Editor Arrangement (#161):**
- Arrangement editor UI with coloured chips + text input
- Human-readable labels (V1, V2, C, B, etc.) instead of raw indexes
- Auto-generate arrangement (chorus after each verse)
- Sequential arrangement option
- Preview renders in arrangement order when set

**Song of the Day (#163):**
- Expanded from 7 to 16 calendar themes: Advent, Christmas, Epiphany, Palm Sunday, Holy Week, Good Friday, Easter, Ascension, Pentecost, Lent, New Year, Reformation, Harvest, Remembrance, Trinity Sunday
- Title + first-verse lyrics keyword matching (title weighted higher)
- Easter date calculation (Anonymous Gregorian algorithm)

**Dynamic Sitemap:**
- `sitemap.xml.php` — Dynamic XML sitemap from song database
- Includes all static pages, songbook pages, 3,612 song pages, writer pages
- `.htaccess` rewrite: sitemap.xml → sitemap.xml.php

**CI/CD Fixes:**
- lftp `--exclude` uses **regex patterns**, NOT shell globs — key lesson from CI failures
- Job-level `env.LFTP_EXCLUDES` with YAML `>-` folding
- Excludes: IDE files (.vscode, .idea, .xcodeproj, .sublime-*), repo files (.git, .github), OS files (.DS_Store, Thumbs.db)
- CHANGELOG.md allowed to deploy (removed from exclusions)
- Commit date format includes seconds for alpha build timestamps

**Version Management:**
- Version bumps only on `beta` branch (industry standard)
- Alpha gets build timestamp (yyyymmddhhmmss) after version in footer
- Timestamp injected at deploy time via commit date from CI

**How to apply:** These features extend existing architecture. OG images use PHP/GD (no external dependencies). PWA install uses `pwa.js` module with platform detection. lftp excludes MUST use regex syntax.
