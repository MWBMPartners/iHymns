# Development Setup

> Prerequisites, local development, coding standards, and commit conventions

---

## Prerequisites

| Tool | Version | Purpose |
|---|---|---|
| Node.js | v22+ (LTS) | Song parser, build tools, tests |
| npm | v10+ | Package management |
| PHP | 8.4 or 8.5 | Web server (local or shared hosting) |
| Git | Latest | Version control |
| VS Code | Latest | Recommended editor |
| Xcode | 26+ | Apple app development (macOS only) |
| Android Studio | Latest | Android app development |

> **Corrected 2026-09-08.** Two rows were wrong. **Xcode 16+** would not open the project at all —
> CI selects Xcode 26 (`.github/workflows/apple.yml` picks the newest `Xcode_26*.app`), and the
> deployment targets in `appApple/Config/Shared.xcconfig` are all 26.0, which Xcode 16 cannot build.
> **PHP 8.5+** overstated the floor: CI runs the whole PHP suite on a matrix of 8.4 and 8.5
> (`.github/workflows/test.yml`), so 8.4 is proven green and telling a shared-hosting operator they
> need 8.5 would block a working setup. Worth flagging that `README.md` states a third figure, PHP
> 8.1+, which nothing in CI exercises — one of those numbers is a guess and it would be worth
> settling which, rather than leaving three floors in three documents.

---

## Getting Started

```bash
# Clone the repository
git clone https://github.com/MWBMPartners/iHymns.git
cd iHymns

# Install Node.js dependencies
npm install

# Point git at the repo's own hooks (not automatic — .git/hooks isn't
# tracked). Currently installs a pre-push guard that blocks pushing a
# branch other than the one you have checked out, and re-creating a
# deliberately-deleted branch.
git config core.hooksPath tools/githooks

# Parse song data (writes a gitignored tmp/songs.json local build artefact
# only — not a runtime file, and not something a fresh install needs; the
# app itself reads live MySQL, and new song content goes in through the
# Song Editor's bulk importers instead, #1617)
npm run parse-songs

# Run unit tests
npm test
```

### Running the Web PWA Locally

The PWA needs PHP 8.4 or 8.5. Options for local development:

```bash
# Option 1: PHP built-in server
cd appWeb/public_html
php -S localhost:8080

# Option 2: MAMP/XAMPP/Laragon
# Point document root to appWeb/public_html/

# Option 3: Docker (if configured)
# docker-compose up
```

Ensure `appWeb/.auth/db_credentials.php` is configured (see [[Database & Migrations]]) — the app reads live MySQL at runtime, so a working DB connection is required even for local development, not a `data_share/` JSON copy.

---

## Application IDs

| Platform | Application ID |
|---|---|
| Web/PWA | `Ltd.MWBMPartners.iHymns.PWA` (`appWeb/public_html/includes/infoAppVer.php`) |
| Apple | `app.ihymns` — plus `app.ihymns.watchkitapp` and `app.ihymns.widgets` (`appApple/project.yml`) |
| Android | `ltd.mwbmpartners.ihymns`, with `.debug` appended on debug builds (`appAndroid/app/build.gradle.kts`) |

The three deliberately do **not** share a naming scheme, so please don't "tidy" them into one — each
is baked into store listings, signing and installed apps.

> **Corrected 2026-09-08.** The Apple and Android rows previously read
> `Ltd.MWBMPartners.iHymns.Apple` and `Ltd.MWBMPartners.iHymns.Android`. Neither string exists
> anywhere in the repo. Only the PWA row was right.

---

## Coding Standards

### PHP

- PHP 8.4 or newer, with `declare(strict_types=1)` in every file
- Modern syntax: `str_contains()`, `match` expressions, named arguments
- Modular architecture: page fragments in `includes/pages/`, small reusable chunks of markup in `includes/partials/`, and shared PHP logic (data access, validators, admin cores, …) as individual files directly under `includes/` — there is no separate `includes/components/` directory
- Direct-access prevention at top of every include file
- Content Security Policy with per-request nonces

### JavaScript

- ES modules architecture (well over 70 modules in `js/modules/`, plus more in `js/utils/` — the count grows with every feature, so run `find appWeb/public_html/js/modules -maxdepth 1 -name '*.js' | wc -l` for the live number rather than trusting a written-down one)
- No build step required — native ES module loading
- `import`/`export` syntax, no CommonJS
- All state in the central `iHymnsApp` class
- Use `escapeHtml()` from `js/utils/html.js` for all dynamic content
- Linted by ESLint (flat config, `eslint.config.js` at the repo root; `eslint` is a declared devDependency). Run locally with `npm run lint:js`. This is a real, enforced check (#1711) — CI's "Lint JavaScript (ESLint)" step used to find no config, fall through to a config-less `eslint@latest` that crashes rather than lints, and swallow that crash with `|| exit 0`, so the step stayed green while linting nothing. It now runs for real and fails the build on error.

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

## Versioning

Versioning is **tag-free and Conventional-Commit-driven** (#1963 → #1965, superseding the earlier tag-derived `v1.0.0` scheme from #1899). iHymns deploys direct via SFTP — there are no git tags and no GitHub Releases in the live pipeline:

| Part | Source |
|---|---|
| `MAJOR.MINOR.PATCH` | The committed `Version.Number` in `appWeb/public_html/includes/infoAppVer.php` — the authoritative anchor, a full three-part version. `MAJOR` is hand-edited and rare (a deliberate product-identity bump). The other two digits are bumped **in place** by `deploy.yml` when the Conventional-Commit classifier (`.github/workflows/scripts/classify-bump.sh`) finds a clear signal among the commits since the anchor last changed: `feat:` → **minor** (and the patch digit resets to 0); `feat!:` / `fix!:` / any `!` / a line-anchored `BREAKING CHANGE:` → **major**; a merge-message body line reading exactly `Release: patch` (case-insensitive, whole line) → **patch**, moving the third digit and nothing else. Everything else — `fix`, `chore`, `docs`, `refactor`, `perf`, `ci`, or an unlabelled subject with no `Release: patch` footer — is build-only and leaves the version untouched (a safe under-bump, never an over-bump) |
| `BUILD` | `git rev-list --count HEAD` — a monotonic per-commit id injected at deploy time, shown as its own row in Settings → About |

> **Corrected 2026-09-08.** The row above used to describe the anchor as `MAJOR.MINOR` and list only
> two bump levels plus "everything else". Since the marketing-version / build-number split the
> committed value is a full three-part version, and there is a third level the description never
> mentioned: the whole-line `Release: patch` footer (implemented as `re_patch` in
> `classify-bump.sh`, and documented at length in `infoAppVer.php`'s own doc-block). Somebody
> following the old wording would not have known a deliberate patch release was possible, or how to
> ask for one. [[Deployment & CI-CD]] already described all three levels correctly.

The version bump is committed back to the branch as a normal push (`[skip ci]`), **never as a git tag** — the retired tag-based scheme (`version-bump.yml`, then the #1963 dynamic-tag minter) is gone; `release.yml` still exists but is **dormant / manual-only** (fires only on a hand-pushed `v*` tag or a manual `workflow_dispatch`, never from the automated pipeline). **The load-bearing convention:** PR / squash-merge titles must carry a Conventional-Commit prefix — a feature merged without `feat:` simply doesn't bump the minor (safe but silent), while a non-feature titled `feat:` would wrongly bump it. Every user-visible `feat:` push also needs a plain-language bullet in `WHATS-NEW.md` (the in-app `/whats-new` source, never `CHANGELOG.md`). There are **15** GitHub Actions workflows under `.github/workflows/` (see [[Deployment & CI/CD]]).

---

## Project File Reference

| File | Purpose |
|---|---|
| `tests/fixtures/songs.schema.json` | JSON Schema (draft 2020-12) the interchange shape is validated against (moved out of `data/` in #1617) |
| `tools/parse-songs.js` | Parses `.SourceSongData/` into a gitignored `tmp/songs.json` local build artefact — not a runtime file, and `data/songs.json` itself was retired (#1617) |
| `tools/build-web.js` | Web build/packaging script |
| `appWeb/public_html/includes/infoAppVer.php` | App version metadata |
| `appWeb/public_html/includes/config.php` | App configuration |
| `appWeb/public_html/api.php` | Server-side API |
| `appWeb/public_html/index.php` | SPA shell |
| `appWeb/public_html/manage/includes/auth.php` | Auth middleware + roles (requires `includes/db_mysql.php` directly — there is no separate `manage/includes/db.php`) |
| `appWeb/public_html/js/app.js` | Main app entry point |
| `appWeb/public_html/js/constants.js` | localStorage key constants |
| `tests/test-song-fixture-shape.js` | 25 unit tests validating the interchange-format *shape* against a synthetic fixture (renamed from `test-song-parser.js` in #1617, once the real `data/songs.json` corpus it used to check against was retired) |
