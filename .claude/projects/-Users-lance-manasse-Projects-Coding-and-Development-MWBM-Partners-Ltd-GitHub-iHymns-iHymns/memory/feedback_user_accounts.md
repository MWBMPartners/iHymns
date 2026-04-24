---
name: User Account System — roles, auth, email login, cross-device sync
description: User accounts with role hierarchy, password + email magic link auth, bearer tokens, password reset, version access groups, setlist/favorites sync.
type: feedback
---

User account system with four-tier role hierarchy, three auth methods, and full data sync:

**Role Hierarchy (ROLE_LEVELS in auth.php):**
- `global_admin` (4) — All powers, auto-assigned to first registered user
- `admin` (3) — Manage users, assign roles up to admin
- `editor` (2) — Curator/editor, can edit songs via /manage/editor/
- `user` (1) — Public user, can save setlists/favorites centrally and sync across devices
- Anonymous — No account, local-only setlists (localStorage)

**Three Authentication Methods:**
1. **Password login** — Username + password → bearer token (30-day)
2. **Email magic link / code** — Email → 48-char hex token link + 6-digit code (10-min expiry) → auto-creates account if new
3. **Admin session** — PHP session-based auth for /manage/ panel

**Public API Auth Endpoints:**
- `auth_register`, `auth_login`, `auth_logout`, `auth_me`
- `auth_email_login_request`, `auth_email_login_verify` (passwordless)
- `auth_update_profile`, `auth_change_password`
- `auth_forgot_password`, `auth_reset_password`
- `user_setlists`, `user_setlists_sync`, `favorites`, `favorites_sync`, `favorites_remove`
- `user_access` (group memberships + version channel access)

**User Groups & Version Access:**
- tblUserGroups with AccessAlpha/Beta/Rc/Rtw flags
- Default groups: Developers, Beta Testers, RC Testers, Public
- Users inherit access from primary group + additional memberships (union)

**Security:**
- Brute force protection: 10 failed attempts / 15 min IP lockout (tblLoginAttempts)
- Registration rate limit: 20 requests / hour / IP
- Password max length: 128 chars (bcrypt safety)
- Email login rate limit: 5 per email per hour
- Editor API requires editor+ role check
- getUserById doesn't return PasswordHash

**Key Files:**
- `auth.php` — Role hierarchy, email login, password reset, CSRF
- `api.php` — 30+ endpoints covering all features
- `schema.sql` — 25+ tables (tblUsers, tblEmailLoginTokens, tblLoginAttempts, etc.)

**How to apply:** Future auth changes should build on the ROLE_LEVELS constant. Email login follows the same token pattern as password reset. New endpoints follow the `case 'action_name':` pattern in api.php.
