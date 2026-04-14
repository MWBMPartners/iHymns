---
name: User Account System — roles, auth, tiers, orgs, CCLI
description: User accounts with role hierarchy, 3 auth methods, content tiers, CCLI validation, org-level access, SHA-256 hashed tokens.
type: feedback
---

User account system with four-tier role hierarchy, three auth methods, content access tiers, and organisation support:

**Role Hierarchy (ROLE_LEVELS in auth.php):**

- `global_admin` (4) — All powers, auto-assigned to first registered user
- `admin` (3) — Manage users, assign roles up to admin
- `editor` (2) — Curator/editor, can edit songs via /manage/editor/
- `user` (1) — Public user, can save setlists/favorites, submit song requests
- Anonymous — No account, local-only setlists (localStorage)

**Three Authentication Methods:**

1. **Password login** — Username + password → bearer token (SHA-256 hashed in DB, 30-day)
2. **Email magic link / code** — Email → 48-char hex token + 6-digit code (10-min expiry) → disabled when email_service=none
3. **Admin session** — PHP session-based auth for /manage/ panel

**Content Access Tiers (5 levels):**

- public (0) → free (10) → ccli (20) → premium (30) → pro (40)
- Effective tier = MAX(personal, org-level) — highest wins
- tblAccessTiers defines capabilities per tier
- CCLI validation: 5-8 digit numeric, auto-upgrades free→ccli
- Content gating OFF by default (tblAppSettings)

**Organisations:**

- tblOrganisations with nested hierarchy (ParentOrgId)
- Org licence type maps to access tier for all members
- Users can belong to multiple orgs; highest tier wins

**Security:**

- All tokens (API + reset) stored as SHA-256 hashes
- Brute force: 10 failed / 15 min IP lockout
- Registration mode: open / admin_only
- Password max: 128 chars
- Email login rate: 5 per email per hour

**How to apply:** Future auth changes build on ROLE_LEVELS constant. Content tier checks use resolveEffectiveTier(). New endpoints follow case pattern in api.php. All tokens must be hashed before DB storage.
