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

- Each user belongs to exactly **one** group, recorded in the single `tblUsers.GroupId` column
- That group's four channel flags are the whole answer — there is no second, per-user override
- The application checks group access to gate entry to non-RTW deployments:
  - `dev.ihymns.app` (Alpha) → requires `access_alpha = 1`
  - `beta.ihymns.app` (Beta) → requires `access_beta = 1`

> **Corrected 2026-09-08.** This section used to say a user could belong to several groups at once
> through a `user_group_members` join table, and that channel access was the union of them all.
> There is no such table, and the running app has never worked that way: group membership has
> always been the one `tblUsers.GroupId` column, which the `admin_group_member_add` action updates
> directly. The table was declared in `schema.sql`, nothing ever read it, and it was dropped on
> 2026-07-30 — see the headstone comment at `appWeb/.sql/schema.sql:1410`.

### Fine-grained permissions — the role-to-entitlement matrix

Anything finer-grained than the four roles above is expressed as an **entitlement**: a named
capability (`edit_songs`, `edit_users`, `manage_organisations`, `view_diagnostics`, and so on) that each role either
holds or does not. An admin edits the role → entitlement matrix at `/manage/entitlements`, and every
page and API action asks the same question through `userHasEntitlement()` in
`includes/entitlements.php`. That is the only mechanism — there is no way to grant or revoke a
capability for one individual person while leaving their role alone.

> **Corrected 2026-09-08.** This page previously described a "Per-User Permission Overrides"
> feature: a `user_permissions` table holding five named flags (`can_edit_songs`,
> `can_manage_users`, `can_view_admin`, `can_share_setlists`, `can_access_api`) with
> `NULL`/`1`/`0` meaning inherit/grant/deny. **That feature never shipped.** The table existed only
> as a declaration in `schema.sql`; no migration ever created it on a real install and no line of
> application code ever read or wrote it. It was dropped on 2026-07-30, and `schema.sql`'s own
> headstone comment (line 1420) says so in as many words. Role-based gating through
> `tblUsers.Role` plus the entitlements matrix above is, and always was, the live mechanism.

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
| Database | MySQL via `getDbMysqli()` (mysqli — PDO was fully removed, #554/#555) |

### 2. Public API (Bearer Token)

Used by: PWA frontend, native iOS/Android apps

| Property | Detail |
|---|---|
| Mechanism | Bearer tokens in `Authorization` header |
| Token format | 64-character lowercase hex (32 random bytes) |
| Token lifetime | 30 days |
| Storage (server) | `tblApiTokens` table in MySQL (**corrected 2026-09-08** — this said `api_tokens`; there are no snake_case tables in this codebase) |
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
    ├── Send email with magic link + code via EmailService
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
    ├── Store new token in the tblPasswordResetTokens table
    ├── Send the reset email via EmailService (template `password-reset`)
    └── Return the same success body either way, so the response can't be used
        to find out whether an account exists

2. User enters token + new password → POST auth_reset_password
    ├── Validate token (exists, not expired, not used)
    ├── Hash new password (BCRYPT, cost 12)
    ├── Update user's password_hash
    ├── Mark token as used
    ├── Delete ALL API tokens for this user (force re-login everywhere)
    └── Return success
```

> **Corrected 2026-09-08.** This spot used to carry a note saying the reset token was returned in
> the API response as `_dev_token` "for development/testing", with email delivery still to come.
> **That is not true and has not been for some time.** `auth_forgot_password` sends a real email
> through `EmailService::sendTemplate('password-reset', …)` (`api.php:4995`), and `_dev_token`
> appears nowhere in `api.php` or anywhere under `manage/`. The token is never shown on screen. The
> passwordless magic-link flow above sends for real in the same way (`api.php:5249`). The note is
> worth recording rather than deleting silently, because it told people not to check their inbox.

---

## Content Tier System

In addition to role-based access, iHymns uses a **content tier** system to gate premium features.

### Tier Levels

| Level | Tier name | What it opens up |
|---|---|---|
| 0 | `public` — Public | Public-domain songs only. No sign-in needed. |
| 10 | `free` — Free | All song lyrics, including copyrighted ones. Sign-in needed. |
| 20 | `ccli` — CCLI Licensed | Full lyrics plus audio playback, on a verified live CCLI licence. |
| 30 | `premium` — Premium | Audio playback, MIDI and PDF sheet-music downloads, offline saving. |
| 40 | `pro` — Professional | Everything, including API access and bulk export. |

These are the five tiers seeded into `tblAccessTiers` by `appWeb/.sql/schema.sql:2134`. The `Level`
number is what every comparison is made against, and an admin can edit it per install on
`/manage/tiers` — so treat the numbers above as the shipped starting point, not constants. What each
tier can actually *do* comes from the `TIER_CAPS` registry in
`includes/access_tier_validation.php`, never from the tier's name.

> **Corrected 2026-09-08.** This table used to list five tiers named Free (0), Basic (1), Standard
> (2), Premium (3) and Ultimate (4). Every column was wrong: "Basic", "Standard" and "Ultimate" do
> not exist anywhere in the codebase, `public` and `ccli` were missing, and the levels run in tens
> rather than ones. Anyone writing a gate against the old table would have got every level
> comparison wrong.

### Tier Resolution (Personal vs Organisation)

Each user may have:

- A **personal tier**, stored on `tblUsers.AccessTier`
- An **organisation tier**, worked out from the organisations they belong to and the licences those
  organisations hold

The effective tier is always the **higher** of the two, compared by the live `tblAccessTiers.Level`
value. So a member of an organisation holding a Premium licence gets Premium access whatever their
personal tier says.

The organisation half is resolved by `resolveEffectiveTier()` in `includes/ccli_validator.php`. It
starts from `tblOrganisationMembers`, walks up the `tblOrganisations.ParentOrgId` chain (so
membership of a branch church also inherits the parent body's licences), collects every live licence
on that chain — both the legacy one recorded directly on the organisation row and the several that
can sit in `tblOrganisationLicences` — and maps each licence to the tier it confers via
`tblLicenceTypes.ConfersTier`. Expired licences confer nothing.

> **Corrected 2026-09-08.** This section used to say the organisation tier was "inherited from their
> user group membership". It is not, and never was: the strings `tblUserGroups` and `GroupId` do not
> appear once in `ccli_validator.php`. **User groups and content tiers are two completely separate
> things** — groups control which release channel (Alpha / Beta / RC / RTW) a person may open, and
> have nothing to do with content access. Conflating the two is what produced the error, so it is
> worth saying plainly.

### CCLI Licence Validation

Users can associate a **CCLI licence number** with their account. The system validates the CCLI number format before saving and records the verification status:

- `tblUsers.CcliNumber` — the user's CCLI licence number
- `tblUsers.CcliVerified` — whether the number has been verified (0 = unverified, 1 = verified)
- Format validation: numeric string, typically 5-8 digits
- Validated via the `ccli_validate` API endpoint

A valid CCLI licence may unlock additional content usage rights depending on the deployment's licensing agreements.

---

## Organisations (multi-tenancy)

`tblOrganisations` models churches / worship teams / denominations, with an optional nested hierarchy (`ParentOrgId`). A user's relationship to an organisation is **per-organisation data**, not a global role: `tblOrganisationMembers.Role` is `owner` / `admin` / `member` for that one org, and pages that admit an "org admin" check `userIsOrgAdminOf()` rather than a fixed role name — a global `user` can be an admin of their own church's organisation without holding any elevated site-wide role. `/manage/my-organisations` is the member self-service surface; `/manage/organisations` is the global-admin equivalent (`manage_organisations` entitlement) — its **New organisation (guided)** button steps through details → licences → members in one flow rather than a single long form (see [[Architecture]] § Guided-wizard framework); the classic form is unchanged alongside it.

**Multiple licences per organisation (#1969).** A church routinely holds several licences at once — e.g. CCLI for the lyrics and MRL for the music — stored one row per type in `tblOrganisationLicences` (type, number, expiry, active flag, notes), alongside the legacy "primary" licence still recorded directly on `tblOrganisations`. The shared core `includes/org_licence_admin.php` validates types against the `tblLicenceTypes` registry (never a hard-coded list) and reconciles a submitted set **non-destructively** — an edit to one licence's expiry no longer wipes every other licence's metadata, a bug the #1969 audit fixed. Both the global-admin and member self-service editors, and the CCLI/tier resolvers, delegate to this one core — see [[Architecture]] § Shared cores and [[Database & Migrations]].

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
| MIDI file | — | `premium`+ | `premium`+ | `premium`+ | `premium`+ |
| PDF sheet music | — | `premium`+ | `premium`+ | `premium`+ | `premium`+ |
| Recorded audio playback | — | `ccli`+ | `ccli`+ | `ccli`+ | `ccli`+ |
| Song editor | — | — | Yes | Yes | Yes |
| Delete songs (recoverable — see [[Database & Migrations]]) | — | — | Yes | Yes | Yes |
| Purge songs (permanent, irreversible) | — | — | — | Yes | Yes |
| User management | — | — | — | Yes | Yes |
| Activity log | — | — | — | Yes | Yes |
| CCLI Usage Report (system-wide, `/manage/ccli-report`) | — | — | — | Yes | Yes |
| My CCLI Report (own org only, `/manage/my-ccli-report`, #1861) | — | Yes¹ | Yes¹ | Yes¹ | Yes¹ |
| App settings | — | — | — | — | Yes |
| Assign global_admin | — | — | — | — | Yes |

> **Corrected 2026-09-08.** The three content rows above used to read "Tier 2+" and "Tier 3+",
> naming tier levels that do not exist (see the corrected tier table earlier on this page), and gave
> Global Admin a blanket "Yes". A role does not confer a tier: `resolveEffectiveTier()` reads the
> person's own `tblUsers.AccessTier` and their organisations' licences, and neither it nor
> `includes/access_resolver.php` contains a single mention of `global_admin`. Note too that this
> whole tier column is **dormant** unless an operator has switched content gating on
> (`tblAppSettings.content_gating_enabled = '1'`); with the switch off — the shipped default —
> nothing here is enforced at all.

¹ `My CCLI Report`'s entitlement (`view_org_ccli_report`) is open to every signed-in role by default —
it's a role-level kill-switch, not the real access control. The REAL scoping is structural: the page's
only row source refuses to run without a non-empty `tblOrganisationMembers`-derived org-id list, so a
user with no admin/owner role on any organisation sees a friendly "not an organisation admin" message
rather than an empty report, regardless of the entitlement. See [[Security]] and [[API Reference]].

### Nav↔gate parity for organisation admins (#1667)

Organisation admins now **see** the Service Mode links (Projector Screen, Lead a Service) in the admin
menu. They were always allowed to *use* those pages, but the menu only showed the links to holders of
the broader "manage organisations" permission, so an org admin could reach the pages by URL yet never
discover them. The menu visibility now matches the pages' own gate — the standing rule that an admin
page's menu entry and its access check must be the same test (see [[Security]]).

---

## Account Lifecycle — disabled vs deleted (#1698)

An account is in exactly one of three states. The state is **derived at read time** from
`tblUsers`; it is never stamped onto the rows a user owns.

| State | `IsActive` | `Status` | Reversible? | Meaning |
|---|---|---|---|---|
| Active | `1` | `active` | — | Normal account |
| Disabled | `0` | `disabled` | **Yes** | Switched off. Data intact; re-enabling restores everything on the next read |
| Deleted | `0` | `deleted` | **No** | Anonymised tombstone — personal data erased, row retained so ownership links still resolve |

The invariant `IsActive = (Status === 'active')` is enforced in the **one writer**,
`setUserActive()` in `manage/includes/auth.php`. `setUserActive()` refuses to reactivate a tombstone.

### Why there is no second gate

`getAuthenticatedUser()` already filters `u.IsActive = 1` on every bearer request, and
`manage/includes/auth.php` carries thirteen more `IsActive` enforcement points (token auth, session
resolution, login, password reset, email login and verification resolvers). **Both** inactive states
set `IsActive = 0`, so a disabled or deleted user is locked out of every owner-authenticated path
with no new code. `Status` exists solely to *distinguish* the two — for the admin UI, the
reactivation guard, and the lock label on shared surfaces.

### Why derive rather than stamp

The alternative — writing `IsLocked = 1` onto every row a disabled user owns, and an inverse pass on
reactivation — has three failure modes the derived design does not:

1. A partial pass leaves an account half-locked, and nothing distinguishes "not reached yet" from
   "deliberately unlocked".
2. Every new table that grows a user FK must remember to join the pass; forgetting is silent.
3. Reactivation must be exactly the inverse of disablement, forever — including for rows created
   *while* disabled.

Deriving reads one owner-state row at the moment of decision. Reactivation is one `UPDATE`.

### Visible but locked

Content owned by a non-active account **stays visible and becomes read-only**. A collaborator on a
disabled owner's set list keeps `canRead`, loses `canWrite`, and receives a `lockReason` sentence
that deliberately names no username, email or admin action. A published set-list template survives
its author; `manage_setlist_templates` (admin+) is the override that keeps such a template
manageable. Third parties are not punished for somebody else's account closing.

### Erasure

`accountErase()` (`includes/account_lifecycle.php`) derives what to do with every referencing row
from that foreign key's own `ON DELETE` rule rather than a hardcoded table list:

| FK rule | Action |
|---|---|
| `CASCADE` | Delete the row (it is the user's own) |
| `SET NULL` on a *behavioural* column (search queries, song history, usage telemetry) | NULL it |
| `SET NULL` elsewhere | **Keep** — attribution resolves to the tombstone |
| `RESTRICT` / `NO ACTION` / empty / unrecognised | **Refuse** — we have no policy, so we do not guess |

Every bearer token and the stored Apple refresh token go with the account. Three funnels reach the
same core: self-service `account_delete`, admin `admin_user_delete`, and `deleteUser()`.

A refusal surfaces as **HTTP 503**, not 500 — the account is untouched and the condition is an
operator action (an un-run migration, or schema drift). It never falls back to a hard row delete.

### Key files

| File | Role |
|---|---|
| `includes/user_status.php` | The three states, derived — all pure except one memoised lookup |
| `includes/account_lifecycle.php` | The erasure core |
| `manage/includes/auth.php` → `setUserActive()` | The ONE state writer |
| `appWeb/.sql/migrate-user-account-status.php` | The ONE migration |
| `tests/php/test-account-lifecycle.php` | 111 behavioural assertions (the number the run itself prints) |

---

## Database Tables

| Table | Purpose |
|---|---|
| `tblUsers` | Account records (username, email, password hash, role, group). `Status` + `StatusChangedAt` carry the lifecycle state above (#1698) |
| `tblUserGroups` | Group definitions with the four release-channel access flags |
| `tblApiTokens` | Bearer tokens (64-char hex, 30-day expiry) |
| `tblPasswordResetTokens` | Reset tokens (48-char hex, 1-hour expiry, single-use) |
| `tblUserSetlists` | Server-side setlist sync |
| `tblUserFavorites` | Server-side favourites sync |

See [[Database & Migrations]] for full schema details.

> **Corrected 2026-09-08.** Every name in this table was previously written in `snake_case`
> (`users`, `user_groups`, `api_tokens`, …). This codebase has no `snake_case` tables at all — they
> are all `tblCamelCase`, and a query written from the old list would simply have failed. Worse,
> three of the rows named tables that **no longer exist under any name**: `user_group_members`,
> `user_permissions` and `sessions` were all dropped from `schema.sql` on 2026-07-30 in the
> orphan-inventory sweep, because each was a `schema.sql`-only declaration that no migration created
> and no application code ever touched. The headstone comments explaining why sit at
> `appWeb/.sql/schema.sql:980-998` and `:1410-1427`.

---

## Future: SIGNula ID

A future integration with the SIGNula ID single sign-on service is planned for Phase 2. This will provide unified authentication across all MWBM Partners products.
