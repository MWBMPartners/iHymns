---
name: Hymn scraper/importer scripts
description: 10 scraper files in .importers/scrapers/ — 5 platforms (Python/PHP/PS1/BAT/AppleScript) × 2 sources (MissionPraise, SDAHymnals)
type: project
---
The `.importers/scrapers/` directory contains 10 scraper scripts for populating `.SourceSongData/` with hymn lyrics from two sources:

- **MissionPraise.com** — MP, CP, JP songbooks (requires paid subscription + login)
- **SDAHymnals** — sdahymnal.org (SDAH) and hymnal.xyz (CH) (no login required)

Each scraper has 5 platform variants: Python (.py), PHP (.php), PowerShell (.ps1), BAT/JScript (.bat), AppleScript (.applescript).

**Why:** Platform-specific copies let users run scrapers natively on Windows (BAT/PS1) or macOS (AppleScript) without needing Python installed. PHP version added for server-side use.

**How to apply:** When modifying scraper logic, the same fix must be applied to all 5 variants for each scraper.

Key technical patterns:
- MissionPraise site uses inconsistent HTML: `<section>` vs `<div>`, unclosed `<P>` separators, double `<BR><br />`
- Windows-1252 entity mapping (&#145;-&#151;) needed for both scrapers
- MissionPraise requires WordPress authentication with CSRF nonces + Sucuri WAF handling
- Attribution detection requires colon/separator after keyword
- Subscription paywall detection for MissionPraise ("not part of your subscription")
- Title fallback chain: entry-title class → `<title>` tag → index page title
- Empty lyrics pages: skip .txt file, keep debug HTML dump
- Error page detection for file downloads (PHP stack traces up to 500KB)
- `--song` / `-Song` / `/song:NNN` parameter for single-song debugging
- All variants support `--force` / `-Force` / `/force` to bypass skip logic
- Bug fixes tracked in iLyricsDB GitHub Issues #140, #143

Moved from iLyricsDB/importers/ on 2026-04-13.
