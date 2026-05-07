# Development Setup

> Prerequisites, local development, coding standards, and commit conventions

---

## Prerequisites

| Tool | Version | Purpose |
|---|---|---|
| Node.js | v22+ (LTS) | Song parser, build tools, tests |
| npm | v10+ | Package management |
| PHP | 8.5+ | Web server (local or shared hosting) |
| Git | Latest | Version control |
| VS Code | Latest | Recommended editor |
| Xcode | 16+ | Apple app development (macOS only) |
| Android Studio | Latest | Android app development |

---

## Getting Started

```bash
# Clone the repository
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Install Node.js dependencies
npm install

# Parse song data (generates data/songs.json)
npm run parse-songs

# Run unit tests
npm test
```

### Running the Web PWA Locally

The PWA requires PHP 8.5+. Options for local development:

```bash
# Option 1: PHP built-in server
cd appWeb/public_html
php -S localhost:8080

# Option 2: MAMP/XAMPP/Laragon
# Point document root to appWeb/public_html/

# Option 3: Docker (if configured)
# docker-compose up
```

Ensure `appWeb/data_share/` exists alongside `public_html/` with a copy of `data/songs.json` at `data_share/song_data/songs.json`.

---

## Application IDs

| Platform | Application ID |
|---|---|
| Web/PWA | `Ltd.MWBMPartners.iHymns.PWA` |
| Apple | `Ltd.MWBMPartners.iHymns.Apple` |
| Android | `Ltd.MWBMPartners.iHymns.Android` |

---

## Coding Standards

### PHP

- PHP 8.5+ with `declare(strict_types=1)` in every file
- Modern syntax: `str_contains()`, `match` expressions, named arguments
- Modular architecture: components in `includes/components/`, pages in `includes/pages/`
- Direct-access prevention at top of every include file
- Content Security Policy with per-request nonces

### JavaScript

- ES modules architecture (25+ modules in `js/modules/`, utilities in `js/utils/`)
- No build step required — native ES module loading
- `import`/`export` syntax, no CommonJS
- All state in the central `iHymnsApp` class
- Use `escapeHtml()` from `js/utils/html.js` for all dynamic content

### CSS

- Bootstrap 5.3.6 as the framework
- Custom properties (CSS variables) for theming
- Colour scheme: clean neutral slate/grey (see [[Design]])
- Accent: muted teal `#0d9488`
- Dark mode: charcoal blue `#0f172a`

### General

- **Detailed code annotations** — comments on every code block (ideally every line)
- **Automated copyright year** — `2026-<current year>` resolved at runtime
- **Accessibility** — WCAG 2.1 AA, skip-to-content, focus indicators, reduced motion
- **Security** — CSP nonces, SRI hashes, CSRF tokens, input sanitisation
- **Clean code** — all linting/security checks must pass with zero issues

---

## Commit Message Conventions

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer(s)]
```

### Types

| Type | Use |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `refactor` | Code restructuring (no behaviour change) |
| `docs` | Documentation only |
| `style` | Formatting, whitespace (no code change) |
| `test` | Tests |
| `chore` | Maintenance, dependencies |
| `ci` | CI/CD changes |

### Scopes

Common scopes: `pwa`, `api`, `editor`, `parser`, `apple`, `android`, `ci`, `docs`

### Examples

```
feat(api): add password reset endpoints
fix(pwa): correct setlist sync merge logic
refactor(editor): use requireEditor() for access control
docs: update wiki with API reference
```

### Special Commit Flags

| Flag | Effect |
|---|---|
| `[deploy all]` | Forces full SFTP upload (ignores change detection) |
| `[skip ci]` | Skips all GitHub Actions workflows |

---

## Project File Reference

| File | Purpose |
|---|---|
| `data/songs.json` | Canonical song database (single source of truth) |
| `data/songs.schema.json` | JSON Schema (draft 2020-12) for validation |
| `tools/parse-songs.js` | Parses `.SourceSongData/` into songs.json |
| `tools/build-web.js` | Web build/packaging script |
| `appWeb/public_html/includes/infoAppVer.php` | App version metadata |
| `appWeb/public_html/includes/config.php` | App configuration |
| `appWeb/public_html/api.php` | Server-side API |
| `appWeb/public_html/index.php` | SPA shell |
| `appWeb/public_html/manage/includes/auth.php` | Auth middleware + roles |
| `appWeb/public_html/manage/includes/db.php` | Database + migrations |
| `appWeb/public_html/js/app.js` | Main app entry point |
| `appWeb/public_html/js/constants.js` | localStorage key constants |
| `tests/test-song-parser.js` | 33 unit tests |
