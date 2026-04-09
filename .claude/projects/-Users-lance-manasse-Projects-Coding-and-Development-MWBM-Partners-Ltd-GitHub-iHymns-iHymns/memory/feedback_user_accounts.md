---
name: User Account System — roles, auth, password reset, cross-device sync
description: Enhanced user account system with role hierarchy, bearer token auth, password reset, and cross-device setlist sync.
type: feedback
---

User account system enhanced with a four-tier role hierarchy and full auth flow:

**Role Hierarchy (ROLE_LEVELS in auth.php):**
- `global_admin` (4) — All powers, auto-assigned to first registered user
- `admin` (3) — Manage users, assign roles up to admin
- `editor` (2) — Curator/editor, can edit songs via /manage/editor/
- `user` (1) — Public user, can save setlists centrally and sync across devices
- Anonymous — No account, local-only setlists (localStorage)

**Authentication Architecture:**
- Public PWA: Bearer token auth (64-char hex, 30-day expiry) via `api.php` endpoints
  - `auth_register`, `auth_login`, `auth_logout`, `auth_me`
  - `auth_forgot_password`, `auth_reset_password`
  - `user_setlists`, `user_setlists_sync`
- Admin panel: PHP session-based auth via `/manage/includes/auth.php`

**Key Files:**
- `appWeb/public_html/manage/includes/auth.php` — Role hierarchy, requireEditor(), requireGlobalAdmin(), password reset functions
- `appWeb/public_html/manage/includes/db.php` — Migrations: users, api_tokens, user_setlists, password_reset_tokens
- `appWeb/public_html/api.php` — Public API endpoints for auth and setlist sync
- `appWeb/public_html/js/modules/user-auth.js` — UserAuth class with initUserMenu(), showAuthModal(), forgotPassword(), resetPasswordWithToken()
- `appWeb/public_html/index.php` — Header user dropdown (sign in/out, sync, role display)

**Access Control:**
- `/manage/editor/` uses `requireEditor()` — requires editor role or above
- `/manage/users.php` uses `requireAdmin()` — requires admin or global_admin
- `/manage/setup.php` creates `global_admin` (first user only)
- Users.php role dropdown respects hierarchy (can't assign above your own level)

**Custom Song Arrangements:**
- Per-song arrangement in setlists (ProPresenter 7-style pool + strip UI)
- 12 component types in `js/utils/components.js`: V, C, R, PC, B, T, CD, I, O, IL, VP, AL
- Drag-and-drop reordering with live lyrics preview
- Persisted in setlist data and shared setlist links (backward-compatible)

**How to apply:** Future auth changes should build on the ROLE_LEVELS constant in auth.php. New roles require updating the constant, allRoles(), roleLabel(), and the users.php dropdown.
