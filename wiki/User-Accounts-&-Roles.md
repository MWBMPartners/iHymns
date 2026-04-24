# User Accounts & Roles

> Role-based access control, user groups, version gating, and authentication flows

---

## Role Hierarchy

iHymns uses a four-tier role hierarchy. Each role inherits the capabilities of all roles below it.

| Role | Level | Label | Capabilities |
|---|---|---|---|
| `global_admin` | 4 | Global Admin | All powers. Auto-assigned to first registered user. Can assign any role to any user, including promoting others to Global Admin. |
| `admin` | 3 | Admin | Manage users (create, assign roles up to `admin`). Cannot assign or demote `global_admin`. Full access to admin panel. |
| `editor` | 2 | Curator / Editor | Edit songs via `/manage/editor/`. Can view admin panel but cannot manage users. |
| `user` | 1 | User | Save setlists centrally. Cross-device setlist sync. Submit song requests. No admin panel access. |
| _(anonymous)_ | — | — | Local-only setlists (browser localStorage). Can submit song requests. No account required. |

### Hierarchy Rules

- **Cannot promote above your own level** — an `admin` cannot assign `global_admin`
- **Cannot demote at or above your level** — an `admin` cannot demote another `admin` (unless you are `global_admin`)
- **Only `global_admin` can assign `global_admin`**
- **First registered user** automatically gets `global_admin` role (both via `/manage/setup` and via the public `auth_register` API)

---

## User Groups & Version Access

Users are assigned to groups that control access to release channels. This enables gating non-production deployments:

| Group | Alpha | Beta | RC | RTW | Use Case |
|---|---|---|---|---|---|
| Developers | Yes | Yes | Yes | Yes | Internal team, full access |
| Beta Testers | No | Yes | Yes | Yes | External beta testers |
| RC Testers | No | No | Yes | Yes | Pre-release validation |
| Public | No | No | No | Yes | General public, production only |

### How It Works

- Each user has a **primary group** (`users.group_id`)
- Users can belong to **additional groups** via `user_group_members` (many-to-many)
- Access is the **union** of all group permissions — if any group grants a channel, the user has it
- The application checks group access to gate entry to non-RTW deployments:
  - `dev.ihymns.app` (Alpha) → requires `access_alpha = 1`
  - `beta.ihymns.app` (Beta) → requires `access_beta = 1`

### Per-User Permission Overrides

The `user_permissions` table allows fine-grained overrides per user:

| Permission | Default (from role) | Override |
|---|---|---|
| `can_edit_songs` | editor+ | Grant to `user`, or revoke from `editor` |
| `can_manage_users` | admin+ | Grant to `editor`, or revoke from `admin` |
| `can_view_admin` | editor+ | Grant or revoke individually |
| `can_share_setlists` | all | Revoke for specific users |
| `can_access_api` | all | Revoke for specific users |

`NULL` means inherit from role. `1` = explicitly granted. `0` = explicitly denied.

---

## Three Authentication Methods

iHymns has two separate authentication systems for different use cases:

### 1. Admin Panel (Session-Based)

Used by: `/manage/` area (editor, user management, setup)

| Property | Detail |
|---|---|
| Mechanism | PHP sessions with secure cookies |
| Cookie name | `ihymns_manage_session` |
| Cookie path | `/manage/` |
| Lifetime | 24 hours |
| Cookie flags | `httponly`, `samesite=Strict`, `secure` (when HTTPS) |
| CSRF | Per-session CSRF tokens |
| Database | MySQL via PDO |

### 2. Public API (Bearer Token)

Used by: PWA frontend, native iOS/Android apps

| Property | Detail |
|---|---|
| Mechanism | Bearer tokens in `Authorization` header |
| Token format | 64-character lowercase hex (32 random bytes) |
| Token lifetime | 30 days |
| Storage (server) | `api_tokens` table in MySQL |
| Storage (PWA) | `localStorage` (`ihymns_auth_token`) |
| Storage (iOS) | Keychain (recommended) |
| Storage (Android) | EncryptedSharedPreferences (recommended) |

**API Endpoints:** See [[API Reference]] for full details.

### 3. Email Login (Passwordless Magic Link / Code)

Used by: PWA and native apps as an alternative to password login

| Property | Detail |
| --- | --- |
| Mechanism | Time-limited token (magic link) or 6-digit code sent via email |
| Token format | 48-character hex (24 random bytes) for links |
| Code format | 6-digit zero-padded numeric code for manual entry |
| Expiry | 10 minutes |
| Single-use | Yes (marked `Used` after verification) |
| Rate limit | 5 requests per email per hour |
| Auto-create | New account auto-created if email not found |

**API Endpoints:** See [[API Reference]] for full details.

---

## Authentication Flows

### Registration Flow

```text
User fills form → POST auth_register
    ├── Validate username (3+ chars, alphanumeric)
    ├── Validate password (8+ chars)
    ├── Check username uniqueness
    ├── Check if first user → assign global_admin, else user
    ├── Hash password (BCRYPT, cost 12)
    ├── Create user record (assigned to 'Public' group by default)
    ├── Generate bearer token (64 hex, 30-day expiry)
    └── Return { token, user: { id, username, display_name, role } }
```

### Login Flow

```text
User enters credentials → POST auth_login
    ├── Look up user by username
    ├── Verify password hash
    ├── Check is_active flag
    ├── Generate new bearer token
    └── Return { token, user: { id, username, display_name, role } }
```

### Email Login Flow (Passwordless)

```text
1. User enters email → POST auth_email_login_request
    ├── Validate email format
    ├── Rate limit check (5 per email per hour)
    ├── Look up user by email (may not exist yet)
    ├── Generate 48-char hex token + 6-digit code (10-min expiry)
    ├── Invalidate any previous unused tokens for this email
    ├── Send email with magic link + code (TODO: email delivery)
    └── Return 200 (always, to prevent email enumeration)

2a. User clicks magic link → POST auth_email_login_verify { token }
    ├── Validate token (exists, not expired, not used)
    ├── Mark token as used
    └── → Complete login (step 3)

2b. User enters code → POST auth_email_login_verify { email, code }
    ├── Validate code (matches email, not expired, not used)
    ├── Mark token as used
    └── → Complete login (step 3)

3. Complete login:
    ├── If email has existing account → use that account
    ├── If email is new → auto-create account (username from email prefix)
    ├── Mark email as verified (EmailVerified = 1)
    ├── Update LastLoginAt + LoginCount
    ├── Generate bearer token (64 hex, 30-day expiry)
    └── Return { token, user: { id, username, display_name, email, role } }
```

### Password Reset Flow

```text
1. User clicks "Forgot password?" → POST auth_forgot_password
    ├── Look up user by username or email
    ├── Generate reset token (48 hex chars, 1-hour expiry)
    ├── Delete any existing tokens for this user
    ├── Store new token in password_reset_tokens table
    └── Return success (+ dev token in non-production)

2. User enters token + new password → POST auth_reset_password
    ├── Validate token (exists, not expired, not used)
    ├── Hash new password (BCRYPT, cost 12)
    ├── Update user's password_hash
    ├── Mark token as used
    ├── Delete ALL API tokens for this user (force re-login everywhere)
    └── Return success
```

**Note:** In production, the reset token should be delivered via email. Currently, the token is returned in the API response (`_dev_token`) for development/testing purposes.

---

## Content Tier System

In addition to role-based access, iHymns uses a **content tier** system to gate premium features.

### Tier Levels

| Level | Tier | Gated Features |
|---|---|---|
| 0 | Free | Lyrics only |
| 1 | Basic | Lyrics + song metadata extras |
| 2 | Standard | Basic + MIDI audio playback |
| 3 | Premium | Standard + PDF sheet music downloads |
| 4 | Ultimate | All content |

### Tier Resolution (Personal vs Organisation)

Each user may have:

- A **personal tier** stored on `tblUsers.AccessTier` (set by purchase, admin override, or default)
- An **organisation tier** inherited from their user group membership

The effective tier is always the **higher** of the two: `MAX(personal_tier, org_tier)`. This means a user in a Premium organisation automatically gets Premium access regardless of their personal tier setting.

### CCLI Licence Validation

Users can associate a **CCLI licence number** with their account. The system validates the CCLI number format before saving and records the verification status:

- `tblUsers.CcliNumber` — the user's CCLI licence number
- `tblUsers.CcliVerified` — whether the number has been verified (0 = unverified, 1 = verified)
- Format validation: numeric string, typically 5-8 digits
- Validated via the `ccli_validate` API endpoint

A valid CCLI licence may unlock additional content usage rights depending on the deployment's licensing agreements.

---

## Access Control Matrix

| Resource | Anonymous | User | Editor | Admin | Global Admin |
|---|---|---|---|---|---|
| Browse songs | Yes | Yes | Yes | Yes | Yes |
| Search | Yes | Yes | Yes | Yes | Yes |
| Favourites (local) | Yes | Yes | Yes | Yes | Yes |
| Favourites (synced) | — | Yes | Yes | Yes | Yes |
| Setlists (local) | Yes | Yes | Yes | Yes | Yes |
| Setlists (synced) | — | Yes | Yes | Yes | Yes |
| Share setlists | Yes | Yes | Yes | Yes | Yes |
| Song requests | Yes | Yes | Yes | Yes | Yes |
| MIDI audio playback | — | Tier 2+ | Tier 2+ | Tier 2+ | Yes |
| PDF sheet music | — | Tier 3+ | Tier 3+ | Tier 3+ | Yes |
| Song editor | — | — | Yes | Yes | Yes |
| User management | — | — | — | Yes | Yes |
| Activity log | — | — | — | Yes | Yes |
| App settings | — | — | — | — | Yes |
| Assign global_admin | — | — | — | — | Yes |

---

## Database Tables

| Table | Purpose |
|---|---|
| `users` | Account records (username, email, password hash, role, group) |
| `user_groups` | Group definitions with version access flags |
| `user_group_members` | Many-to-many group membership |
| `user_permissions` | Per-user permission overrides |
| `sessions` | Admin panel sessions |
| `api_tokens` | Bearer tokens (64-char hex, 30-day expiry) |
| `password_reset_tokens` | Reset tokens (48-char hex, 1-hour expiry, single-use) |
| `user_setlists` | Server-side setlist sync |
| `user_favorites` | Server-side favorites sync |

See [[Database & Migrations]] for full schema details.

---

## Future: SIGNula ID

A future integration with the SIGNula ID single sign-on service is planned for Phase 2. This will provide unified authentication across all MWBM Partners products.
