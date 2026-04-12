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

## Two Authentication Systems

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
