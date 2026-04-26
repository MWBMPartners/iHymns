# iHymns — Project Rules (detailed)

Expanded rules for contributors (human or AI). The short version lives in `.claude/CLAUDE.md`; this file is the long version, safe to link in code review comments when a rule needs citing.

## 1. Modularity (the master rule)

See `.claude/CLAUDE.md` for the full policy. Summary: don't duplicate — extract, then reuse.

### 1.1 Shared components — WEB / PWA

| Concern | Shared module | Consumer pattern |
|---|---|---|
| Admin top-nav (brand + theme + avatar + hamburger offcanvas) | `manage/includes/admin-nav.php` | `<?php require __DIR__ . '/includes/admin-nav.php'; ?>` with `$activePage` set |
| Admin footer (copyright / version / Terms / Privacy + Bootstrap bundle JS) | `manage/includes/admin-footer.php` | Include once, immediately before `</body>` |
| Favicon + app icons | `manage/includes/head-favicon.php` | Include in `<head>` |
| Session / auth bootstrap | `manage/includes/auth.php` | `require_once` first, then call `isAuthenticated()` / `requireAuth()` / `requireAdmin()` |
| DB handle (PDO, admin side) | `manage/includes/db.php::getDb()` | Never `new PDO(...)` directly |
| DB handle (mysqli, main app song data) | `includes/db_mysql.php::getDbMysqli()` | Never `new mysqli(...)` directly |
| Entitlement check | `includes/entitlements.php::userHasEntitlement()` | Never check role strings directly for authorisation — always through this |
| Entitlement labels | `$ENTITLEMENT_LABELS` in `manage/entitlements.php` | Extend the map; never hand-craft a string at render time |
| Licence type labels | `$LICENCE_TYPES` in `manage/organisations.php` (migrating to `tblLicenceTypes`, #459) | Consumers iterate the map; never hard-code licence keys |
| Card-layout reorder/hide (server) | `includes/card_layout.php` | `cardLayoutResolve($baseline, $surface, $user)` to render order; `cardLayoutSave*` to persist |
| Card-layout reorder/hide (client) | `js/modules/card-layout.js` | `initCardLayout(gridEl)` — grid must carry `data-layout-surface`, cards `data-card-id` |
| Offline download UI | `js/modules/offline-ui.js` | Cards use `data-songbook-download` / `data-song-download`; feature detection handled centrally |
| Content access evaluation | `includes/content_access.php::checkContentAccess()` | API + page gates use this; never query `tblContentRestrictions` directly |
| SPA router | `js/modules/router.js` | New routes register via `parseRoute()`; after-load hooks go in `afterPageLoad()` |
| Main-site home / song / songbook templates | `includes/pages/*.php` | Rendered by `api.php` via `?page=...` |

### 1.2 Shared components — APPLE

- Cross-target Swift code in a `Shared` package imported by iOS / iPadOS / tvOS targets.
- Design tokens + colours match the web CSS variables (`--accent-*`, `--surface-*`, `--text-*`). Keep the palette in one shared `Theme.swift`.
- Network layer talks to the same `/api?...` endpoints the web uses. No separate schema.

### 1.3 Shared components — ANDROID + FireOS

- Kotlin Multiplatform where feasible; shared Gradle modules otherwise.
- FireOS is a variant of the Android target — differs only in launcher icon, store metadata, and any device-specific capability checks. No parallel implementation.

## 2. Naming conventions

- **Database:** `tblCamelCase` tables, `CamelCase` columns, `utf8mb4_unicode_ci` collation (case-insensitive uniqueness on usernames + slugs).
- **PHP:** `snake_case` for functions + local vars; `PascalCase` for classes; `UPPER_SNAKE` for constants. Match existing code in the file you're editing.
- **JS:** `camelCase` for functions + variables; `PascalCase` for classes; `UPPER_SNAKE` for module-level constants.
- **CSS custom properties:** `--accent-*`, `--surface-*`, `--text-*`, `--card-*`, `--footer-*`, `--header-*` — see `css/app.css:1`.
- **URLs:**
  - Main app uses clean, hyphenated paths (`/songbook/CP`, `/song/CP-0001`). The `.htaccess` rewrites to `index.php`, then the SPA router parses.
  - `/manage/*` uses clean URLs too — every `<name>` resolves to `<name>.php` via the generic rule in `manage/.htaccess`. No per-page rewrite lines (#443).
- **Entitlement keys:** `snake_case`, verb-forward (`edit_songs`, `manage_user_groups`). Always accompanied by a human label in `$ENTITLEMENT_LABELS`.
- **Card IDs** (for reorder surfaces): lowercase, alnum + hyphen, ≤ 64 chars, validated by `cardLayoutSanitiseIds()`.

## 3. Auth / security

1. **Every page** gates via `isAuthenticated()` → `userHasEntitlement()` pair, OR the `requireAuth()` / `requireAdmin()` / `requireGlobalAdmin()` helpers. No role-string comparisons in business logic.
2. **Every POST form** includes a `csrf_token` hidden input; every POST handler calls `validateCsrf()` before dispatch.
3. **Every SQL statement** uses prepared statements with placeholders. No string concatenation of user-supplied data into SQL, ever.
4. **Every echoed variable** uses `htmlspecialchars()` (with `ENT_QUOTES` when inside attribute context). JSON embedded in attributes uses `JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP`.
5. **Tokens (API, password-reset, magic-link)** stored hashed at rest, compared via constant-time hash check.
6. **Usernames** stored case-preserved (users pick the case shown); uniqueness + login lookups rely on the DB's case-insensitive collation — never normalise to lower-case on insert.
7. **Session cookies** are `HttpOnly`, `Secure`, `SameSite=Lax`. The session name is `ihymns_manage_session` for the admin panel.

## 4. Error handling

- **System boundaries only.** Internal code trusts internal code; no defensive `isset()` on values you just assigned.
- **User input** gets validated at the boundary (form handler, API action). Once validated, downstream code uses it directly.
- **DB errors** are caught at the top of a page (see `manage/data-health.php` for the pattern) so the surrounding layout still renders with a visible error banner rather than a blank page.
- **Client errors** surface via `console.error()` + a visible alert. No silent `.catch(() => {})` for anything that affects UX.

## 5. Accessibility / W3C compliance

- Every `<input>` has a matching `<label>` or `aria-label`.
- Every icon-only button has `aria-label` + `title`; the `<i>` inside gets `aria-hidden="true"`.
- Every modal has `aria-labelledby` pointing at its `.modal-title`'s `id`.
- Every table uses proper `<thead>` / `<tbody>`.
- Keyboard navigation tested on every interactive surface (Tab reaches controls, Enter/Space activates).
- No duplicate IDs across a rendered page — especially watch for IDs inside loops; suffix with the row key.
- Colour is never the only signal of state; always pair with an icon or text change.

## 6. Performance

- **ETag + short `Cache-Control`** on idempotent API page fragments (`api.php` page branch). User-specific pages skip the cache path.
- **N+1 DB queries are a bug.** Every loop that calls `getSongById()` / `getUserById()` etc. per-row must be refactored to preload via a single query + in-memory map.
- **Service worker** caches CSS / JS / HTML page fragments; bumped cache version on deploy so changes take effect.
- **No render-blocking synchronous `<script>` tags** in the main-site head — defer or `type="module"`.

## 7. Test discipline

- `npm test` runs the song-parser harness at minimum.
- `npm run test:php` + `npm run test:js` sweep syntax across the tree.
- Manual test plan lives in every PR description — explicit checklist, each item a smoke test.

## 8. GitHub workflow

- Issue BEFORE commit when possible: `feat(x): … (#NNN)`. Every PR lists the issues it closes.
- Retrospective issues for work that shipped without one are OK — see #438-442 as precedent.
- Every PR description explains WHY the change exists (not just WHAT) and carries a Test Plan checklist.
- **Never open a PR unless the user explicitly asks for one.** Commit + push to the working branch and stop. If the previous PR from that branch has merged, further commits on the same branch do NOT land anywhere until a new PR opens — flag that to the user and wait for them to decide, don't auto-open a follow-up PR.

## 9. What NOT to do (recent anti-patterns to avoid)

- **Don't invent SRI hashes.** A wrong `integrity="…"` silently blocks the script. Either compute the real hash or omit the attribute.
- **Don't render `d-none` on controls and rely on JS to reveal.** The JS may not run on first paint. Render visible; hide via a body-class feature-flag.
- **Don't put Bootstrap `<script>` tags on individual pages.** The shared footer owns JS inclusion for `/manage/*`.
- **Don't inline a navbar on a `/manage/*` page** when `admin-nav.php` is right there.
- **Don't expose backend keys to end users.** `ihymns_pro` is a DB value, not a label; surface the label via the central map.
- **Don't scatter auth checks.** One helper call; never `$u['role'] === 'admin'` in business logic.
- **Don't commit stacked PRs that re-implement work already in a parallel branch.** Rebase and reuse.

## 10. Activity logging — what NEVER goes in `tblActivityLog.Details` (#535)

Every meaningful action writes a row to `tblActivityLog` via
`includes/activity_log.php::logActivity()`. The `Details` JSON column
is free-form, which makes it tempting to dump request bodies wholesale.
**Don't.**

**NEVER log:**
- Password hashes (bcrypt/argon2 strings)
- Plaintext passwords in any form, even temporarily
- Bearer tokens, magic-link tokens, password-reset tokens, CSRF tokens
- Email subject lines or bodies for magic-link emails (log only `sent: true|false`)
- Plaintext personal details that aren't already in the entity's row

**OK to log:**
- User ID + username (already on `tblUsers`)
- Email address on auth events (already on `tblUsers`)
- IP address + truncated User-Agent (already columns on `tblActivityLog`)
- For edits: the list of fields that changed + before/after values for those fields specifically
- Error messages and class names for `Result='error'` rows — these aid debugging and don't leak user data

When in doubt, log the field NAME but not the field VALUE. A row that
says `{ "fields": ["PasswordHash"] }` is fine; one that includes the
hash itself is a bug.
