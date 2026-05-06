# iHymns — Claude Context

Auto-loaded by Claude Code on session start. This file codifies **how to work in this repo** — project rules, conventions, architecture guardrails. Paired with `.claude/ProjectBrief.md` (the current state snapshot) and `.claude/ProjectOverview.md` (the original scoping doc).

## 🧱 Modularity rule (non-negotiable)

**When a piece of UI, logic, or data exists in more than one place, extract it into a shared module. If a shared module already exists, reuse it — do not duplicate.**

Concretely, for every platform variant:

- **Web / PWA** (`appWeb/public_html/`) — every `/manage/*.php` page MUST use the shared partials in `appWeb/public_html/manage/includes/` (`admin-nav.php`, `admin-footer.php`, `head-favicon.php`, `auth.php`, `db.php`). Pages MUST NOT inline their own navbar, footer, favicon set, or Bootstrap / Bootstrap-Icons / admin-css loads. New cross-page concerns get a new partial or JS module — not copy-paste into each page.
- **Apple** (`appApple/`) — cross-platform (iOS / iPadOS / tvOS) code lives in shared Swift packages or frameworks. Per-target code lives under its target folder and may only import the shared code, not duplicate it.
- **Android** (`appAndroid/`) — shared UI + domain logic in shared modules (Kotlin Multiplatform where possible; shared Gradle modules otherwise). Per-target code consumes the shared module.
- **Amazon FireOS** — a target of the Android codebase; shares the Android sharedModules, differing only in the launcher / store metadata.

### Web/PWA specific checkpoints

Before adding code on `/manage/*` or `/` (main app), review this list:

1. **Navigation chrome** → `manage/includes/admin-nav.php`. The page supplies `$activePage`; the nav does the rest.
2. **Footer / copyright / version** → `manage/includes/admin-footer.php`. Do not render your own; do not re-load Bootstrap JS anywhere else.
3. **Favicon / app icons** → `manage/includes/head-favicon.php` (admin) / the `<link>` block in `index.php` (main site).
4. **Auth + CSRF + role + entitlement checks** → `manage/includes/auth.php` + `includes/entitlements.php`. Pages MUST call `isAuthenticated()`, `requireAdmin()`, or `userHasEntitlement()` — never reinvent the check.
5. **DB connection** → `includes/db_mysql.php::getDbMysqli()`. Never instantiate PDO / mysqli directly. (PDO has been fully removed — see #554 / #555.)
6. **Card-layout reorder + hide** → `includes/card_layout.php` (server) + `js/modules/card-layout.js` (client). Any new card grid that should support reorder uses `data-layout-surface` + the shared helpers.
7. **Offline-download UI** → `js/modules/offline-ui.js`. Any new "save for offline" button uses `data-song-download` or `data-songbook-download` and relies on the shared feature detection + state machine.
8. **Content access / gating** → `includes/content_access.php::checkContentAccess()`. Never query `tblContentRestrictions` directly from a page or an API handler.
9. **Licence type picker** → `$LICENCE_TYPES` map in `organisations.php` today, will migrate to `tblLicenceTypes` (#459). Never hard-code licence keys inline elsewhere.
10. **Entitlement labels** → `$ENTITLEMENT_LABELS` map in `manage/entitlements.php`; friendly labels only, tech detail behind `global_admin` gate.
11. **External-link URL → provider detection** → `js/modules/external-link-detect.js` (exposes `window.iHymnsLinkDetect`). Patterns are loaded into `window._iHymnsLinkTypes[].patterns` from `tblExternalLinkPatterns` (#845); the module falls back to its bundled `RULES` constant when patterns haven't been migrated yet. Any new card-list / chip-list editor that pastes a URL and wants to pre-select a provider MUST consume this module — never re-inline a regex list.
12. **External-link type registry** → `manage/external-link-types.php` (CRUD for `tblExternalLinkTypes` + `tblExternalLinkPatterns`). Curators add new providers + their URL patterns here; nothing in `/manage/*` or `js/modules/*` should hard-code a provider key list.
13. **Responsive admin tables** → opt in via `<table class="admin-table-responsive">` and tag each `<th>`/`<td>` with `data-col-priority="primary|secondary|tertiary"` (#842). Stacks columns at progressive breakpoints; pair with the sortable-headers convention from #844 — both shipped on Credit People, Songbooks, Songbook Series, Works, and the eight other admin lists.
14. **Works composition grouping** → `tblWorks` (self-FK nesting) + `tblWorkSongs` + `tblWorkExternalLinks` (#840). Public page `/work/<slug>` via `?page=work&slug=…`; admin CRUD at `/manage/works`. Songs that are part of a work render the "Part of work" panel via the shared partial — don't roll your own grouping UI.
15. **External-links registry** (#833) → `tblExternalLinkTypes` + per-entity tables `tblSongExternalLinks` / `tblSongbookExternalLinks` / `tblCreditPersonExternalLinks` / `tblWorkExternalLinks`. Editors are card-lists that share validation + the URL → provider auto-detect helper. Any new entity that grows external links gets its own `tbl<Entity>ExternalLinks` table, not a generic FK column.

### Red flags during review

Reject any change that introduces:

- A duplicate `<nav>` on an admin page.
- A duplicate `<link rel="stylesheet" href="/css/app.css">` + `/css/admin.css` block when `admin-footer.php` or another shared include could host it.
- A hard-coded list of roles, entitlements, licence types, tier names, or card IDs that already exists in a central map.
- A PDO / mysqli instantiation outside `getDbMysqli()`. (PDO is no longer used at all — any `new PDO(...)` is a regression.)
- A `<script>` loading Bootstrap or Bootstrap-Icons on a page that also includes `admin-footer.php` (double-load).
- An inline click handler that re-implements behaviour the corresponding shared JS module already offers.
- A hand-rolled regex / `URL.hostname.endsWith(...)` ladder for "what kind of link is this" — `external-link-detect.js` already exists and reads its rules from `tblExternalLinkPatterns`.
- A new admin list page that doesn't opt into `.admin-table-responsive` + sortable headers (#842 / #844) when surrounding pages already do.
- A duplicate songbook/song/credit-person/work "external links" editor — reuse the shared chip-list module, don't fork it per entity.

When in doubt: extract first, use second. A 30-line partial is cheaper than debugging five divergent copies.

## 🗂 Project layout

```
appWeb/          — Web / PWA (PHP + vanilla JS modules + Bootstrap 5)
appApple/        — Apple: iOS / iPadOS / tvOS targets + shared Swift code
appAndroid/      — Android (incl. Amazon FireOS) + shared Kotlin code
data/            — Source song JSON + seed data
tests/           — Cross-platform test harnesses
tools/           — Build + data-prep scripts
.claude/         — This file + ProjectBrief / ProjectOverview / project-rules
```

## 🛠 Commit / PR expectations

- **One PR per piece of work, multiple commits inside it.** Group related work into a single PR with logical, well-scoped commits rather than splitting across several smaller PRs. One review session, one deploy to alpha, one verify pass. Each commit stays atomic and individually revertable (`git revert <sha>` works per-commit). Avoids the inter-PR race conditions and multi-deploy churn that bit the 2026-04-25 audit-cleanup work, where a chain of small PRs each triggered its own deploy + verify cycle and one mis-diagnosis cascaded through all of them. Multiple PRs only for genuinely independent pieces of work that happen to be in flight at the same time (e.g. unrelated bugfix + unrelated feature).
- Commits have descriptive first-line summaries; wrapped body explaining the WHY, not just the WHAT.
- Every user-reported bug or feature gets a tracking GitHub issue **before** the commit that closes it, so the timeline reads sensibly.
- PRs target `alpha`. Stacked PRs (PR-B depends on PR-A landing first) are an exception, reserved for genuinely sequential dependencies — most work should land as a single PR per the rule above. Note the base branch in the description.
- Never skip pre-commit hooks (`--no-verify`), never force-push main/alpha, never amend merged commits.
- Audit before opening a PR: PHP syntax (`find appWeb -name '*.php' -exec php -l {} \;`), JS syntax (`find appWeb -name '*.js' -exec node --check {} \;`), security + accessibility + structure per the pattern established on PR #445.

## 📎 Other references in this directory

- `.claude/ProjectBrief.md` — current project state, versions, phase, database schema summary.
- `.claude/ProjectOverview.md` — original multi-platform scoping.
- `.claude/project-rules.md` — this file's detailed expansion (naming, data-access layers, error handling, i18n, test discipline).

## 💾 Session continuity across devices

Raw session transcripts live at `~/.claude/projects/<project-hash>/*.jsonl` on whichever device Claude Code ran — they contain the full tool-call log (every file read, every command run, every response). The repo carries **scrubbed copies** in `.claude/sessions/`, synced via:

```
tools/sync-claude-session.sh
git diff .claude/sessions/    # REVIEW — scrubber is best-effort
git add .claude/sessions/ && git commit -m "chore(sessions): sync"
```

The scrubber redacts known token shapes (Anthropic, GitHub, AWS, Google, `Bearer`, private keys). It does **not** catch a password typed into a prompt or customer data in a test fixture. Always review the diff. See `.claude/sessions/README.md` for the full policy.

Per-user global memory (`~/.claude/CLAUDE.md`) stays on the user's machine — it's not project policy. If guidance from a session turns out to be permanent, copy it into `.claude/project-rules.md` here so future sessions pick it up automatically.
