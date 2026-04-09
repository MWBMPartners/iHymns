# User Accounts & Roles

> Role-based access control, authentication flows, and password reset

---

## Role Hierarchy

iHymns uses a four-tier role hierarchy. Each role inherits the capabilities of all roles below it.

| Role | Level | Label | Capabilities |
|---|---|---|---|
| `global_admin` | 4 | Global Admin | All powers. Auto-assigned to first registered user. Can assign any role to any user, including promoting others to Global Admin. |
| `admin` | 3 | Admin | Manage users (create, assign roles up to `admin`). Cannot assign or demote `global_admin`. Full access to admin panel. |
| `editor` | 2 | Curator / Editor | Edit songs via `/manage/editor/`. Can view admin panel but cannot manage users. |
| `user` | 1 | User | Save setlists centrally. Cross-device setlist sync. No admin panel access. |
| _(anonymous)_ | — | — | Local-only setlists (browser localStorage). No account required. |

### Hierarchy Rules

- **Cannot promote above your own level** — an `admin` cannot assign `global_admin`
- **Cannot demote at or above your level** — an `admin` cannot demote another `admin` (unless you are `global_admin`)
- **Only `global_admin` can assign `global_admin`**
- **First registered user** automatically gets `global_admin` role (both via `/manage/setup` and via the public `auth_register` API)

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

**Functions:**
- `initSession()` — Start PHP session
- `isAuthenticated()` — Check if logged in
- `getCurrentUser()` — Get user row from DB
- `requireAuth()` — Redirect to login if not authenticated
- `requireEditor()` — Require editor+ role (403 otherwise)
- `requireAdmin()` — Require admin+ role (403 otherwise)
- `requireGlobalAdmin()` — Require global_admin only
- `attemptLogin()` — Verify credentials, set session
- `logout()` — Destroy session and cookie

### 2. Public API (Bearer Token)

Used by: PWA frontend, native iOS/Android apps

| Property | Detail |
|---|---|
| Mechanism | Bearer tokens in `Authorization` header |
| Token format | 64-character lowercase hex (32 random bytes) |
| Token lifetime | 30 days |
| Storage (server) | `api_tokens` table |
| Storage (PWA) | `localStorage` (`ihymns_auth_token`) |
| Storage (iOS) | Keychain (recommended) |
| Storage (Android) | EncryptedSharedPreferences (recommended) |

**API Endpoints:** See [[API Reference]] for full details.

---

## Authentication Flows

### Registration Flow

```
User fills form → POST auth_register
    ├── Validate username (3+ chars, alphanumeric)
    ├── Validate password (8+ chars)
    ├── Check username uniqueness
    ├── Check if first user → assign global_admin, else user
    ├── Hash password (BCRYPT, cost 12)
    ├── Create user record
    ├── Generate bearer token (64 hex, 30-day expiry)
    └── Return { token, user: { id, username, display_name, role } }
```

### Login Flow

```
User enters credentials → POST auth_login
    ├── Look up user by username
    ├── Verify password hash
    ├── Check is_active flag
    ├── Generate new bearer token
    └── Return { token, user: { id, username, display_name, role } }
```

### Password Reset Flow

```
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
| Setlists (local) | Yes | Yes | Yes | Yes | Yes |
| Setlists (synced) | — | Yes | Yes | Yes | Yes |
| Share setlists | Yes | Yes | Yes | Yes | Yes |
| Song editor | — | — | Yes | Yes | Yes |
| User management | — | — | — | Yes | Yes |
| Assign global_admin | — | — | — | — | Yes |

---

## Header User Menu

The site header on all pages includes a user dropdown:

**Logged Out (Anonymous):**
- Sign In button → opens auth modal in login mode
- Create Account button → opens auth modal in register mode

**Logged In:**
- Display name (bold)
- Role label (e.g., "Curator / Editor")
- Divider
- My Set Lists → navigates to `/setlist`
- Sync Set Lists → triggers setlist sync
- Account Settings → navigates to `/settings`
- Divider
- Sign Out → calls logout API, clears credentials

The icon changes: `fa-user` (anonymous) → `fa-circle-user` (logged in).

---

## Future: SIGNula ID

A future integration with the SIGNula ID single sign-on service is planned for Phase 2. This will provide unified authentication across all MWBM Partners products.
