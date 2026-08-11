# Set-list sharing as a playlist (#1790) + collab-by-link (#1791) — deep analysis & GIRFT implementation plan

**Status:** PLAN ONLY — no code changes yet. Written 2026-08-09 from a full read of the live code.
**Issues:** #1790 (share = playlist: open link → tap song → Prev/Next, no Import, no "Use"),
#1791 (collab-enabled share link: edit without an email invite or an account).
**Designed together** because they are one architecture: **capability URLs for a set list**, with a
SCOPE (`view` = #1790, `edit` = #1791). The rule-#26 presence-token discipline is the house precedent.

---

## §0 Executive summary (read this first)

1. **#1790 is ~70 % already built — and the issue's 3-step description is partially stale.**
   `tblSharedSetlists` already IS a view-scope capability URL: `setlist_share` mints
   `/setlist/shared/<8-hex>` (api.php:2146-2394), `setlist_get` serves it **unauthenticated**
   (api.php:2400-2500), the #1380 live-link re-resolves the owner's CURRENT list at read time
   (SharedSetlist.php:280-375), and — since #1533, on `alpha` since 2026-07-29 (commit reachable from
   `origin/alpha`; verified `git show origin/alpha:…/setlist.js` contains the arm-on-tap block) —
   **tapping any song on the shared page ALREADY arms the Prev/Next bar with no Import and no "Use"**
   (setlist.js:2817-2849 → getNavigation() ctx-first at setlist.js:2071-2095). What actually still
   produces the owner's complaint: the page's **copy and IA actively instruct the wrong flow**
   ("Someone shared this set list with you. **Import it to use it.**" — setlist-shared.php:68, plus
   two primary Import buttons at :97-100 and :113-117 and **no visible "start/play" affordance at
   all**), and the owner most likely tested a docroot that pre-dates the 2026-07-29 alpha merge.
   The #1790 delta is therefore **client presentation + an explicit playable affordance + one
   deploy-channel verification** — no schema.
2. **#1791 is the real build.** Collaboration today is strictly account+email:
   `setlist_collab_invite` 401s without a bearer token (api.php:10953-10954) and 404s unless the
   email resolves to a **verified** existing account (api.php:11007-11013, "No user with that email
   yet — ask them to sign in first"). The fix is an **edit-scope capability token** stored in the
   SAME `tblSharedSetlists` table (one additive, dormant column batch — no parallel table), writing
   through the SAME single write core the email-collab path uses (extracted from
   `setlist_collab_update`, api.php:11313-11399), so the two grant models cannot drift (rule #35).
3. **One dormant schema batch** (rule #20): `Scope`/`RevokedAt`/`ExpiresAt`/`Label`/`LastUsedAt`/
   `EditCount` columns + `ShareId VARCHAR(16)→VARCHAR(64)` widening on `tblSharedSetlists`.
   All vocab VARCHAR, never ENUM. Everything is a verified no-op until a client mints an edit link.
4. **The share-id format is a 5-consumer contract** (rule #33): setlist.js:2735 (`^[a-f0-9]{6,16}$`),
   api.php:2426 (`[^a-f0-9]` strip, ≤16), og-image.php:107 (same strip), index.php:718
   (`#^/setlist/shared/([a-f0-9]+)$#`), router.js:300-301 (charset-agnostic pass-through). A longer
   token charset must land in ALL of them, held together by one shared helper/constant per language
   + a tree-derived, mutation-proven CI guard (rule #34/#35).

---

## §0.5 OWNER DECISIONS — RESOLVED 2026-08-11 (authoritative; overrides §5 recommendations)

The owner answered all four §5 gates. **These bind the build. Where they change §3b/§3c, this block wins.**

- **G1 (edit-link expiry) → A+C:** edit links **never expire by default**; owner may set a per-link
  optional expiry in the dialog; when the set list ITSELF has an `ExpiresAt`, the link auto-caps to
  it. (`ExpiresAt` column already in §3b — mint logic: `min(requestedExpiry ?? NULL, listExpiry ?? ∞)`.)
- **G2 (new view-link tokens) → A:** NEW view links mint as **22-char / 128-bit** base64url tokens
  (`rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')`); legacy 8-hex links stay valid
  forever (rule #33). Edit links stay 43-char / 256-bit.
- **G3 (show who shared it) → B (opt-in per link):** a mint-time **"include my name"** toggle. When
  ON, the shared page shows a "Shared by <display name>" byline; when OFF (default), the page stays
  anonymous exactly as today. Requires a new per-link column (below) + the owner's display name
  echoed by `setlist_get` **only when the flag is set** (the strict allow-list at api.php:2481-2497
  gains ONE conditional key — never unconditionally).
- **G4 (anonymous edit) → COMPROMISE (owner picks per link):** at edit-link creation the owner
  chooses the link's **edit audience** — "anyone with the link can edit (no account)" **or** "must
  be signed in to edit" — mirroring the G3 toggle. This becomes a per-link VARCHAR column (below),
  NOT an app-wide policy. Both branches ship in C4.

### Schema delta forced by G3 + G4 (fold into the §3b one-pass batch — rule #20, do NOT dribble a 2nd ALTER)

Add these TWO columns to the same `migrate-setlist-share-scope.php` ALTER batch (each `columnExists`-gated,
each with a `@migration-adds` doctag, byte-identical schema.sql mirror, folded into the ONE registry
OR-probe):

```sql
    ADD COLUMN ShowSharerName TINYINT(1)  NOT NULL DEFAULT 0  COMMENT 'G3 #1791: 1 = shared page shows "Shared by <owner display name>"; 0 (default) = anonymous, current posture. Owner-set per link at mint.',
    ADD COLUMN EditAudience   VARCHAR(20)  NOT NULL DEFAULT 'anyone' COMMENT 'G4 #1791: who may edit via an edit-scope link — anyone | authenticated. App-validated VARCHAR vocab, never ENUM (rule #20). Ignored for view-scope links.',
```

`EditAudience` is VARCHAR (not a bool) deliberately: the audience concept is growable (`anyone` →
later `authenticated` → plausibly `org-members`/`domain`), so a new value is an app-map line, never
an ALTER (rule #20). Central map: add `SETLIST_EDIT_AUDIENCES = ['anyone','authenticated']` beside
the scope vocab, fail-closed normaliser to `'anyone'` on unknown (the safest? — NO: unknown must
fail-**closed to the MORE restrictive** `'authenticated'`? See note). **Fail-closed decision:** an
unknown/absent `EditAudience` on a resolvable EDIT token normalises to **`'authenticated'`** (the
safer grant — never silently widen an anonymous-write door on data drift); `'anyone'` is only ever
honoured when explicitly stored. The OR-probe includes `!columnExists(ShowSharerName) || !columnExists(EditAudience)`.

### Server delta forced by G3 + G4

- **Mint (`setlist_share`)**: accept optional `showSharerName` (bool) and, for `scope='edit'`,
  `editAudience` (validated against the map → `'anyone'|'authenticated'`, default `'anyone'`). Persist
  both. `showSharerName` is accepted for BOTH scopes (a view link can carry a byline too).
- **Read (`setlist_get`)**: (a) when the resolved row has `ShowSharerName=1`, add `sharedByName`
  (owner display name via a bound lookup) to the wire — the ONE conditional allow-list key; never
  echo it otherwise, never echo the user id/email (gate G3 privacy invariant holds). (b) `canWrite`
  now also requires: if `EditAudience='authenticated'` (or normalised so), the requester MUST be a
  signed-in user (bearer resolves to a real account) — else `canWrite=false` +
  `lockReason='signin_required'` so the client shows "Sign in to edit this list" rather than the
  editor. `EditAudience='anyone'` keeps the fully-anonymous branch.
- **Write (`setlist_token_update`)**: add a gate **between §3c steps (4) and (5)** — resolve
  `EditAudience`; if `'authenticated'` and no valid signed-in user on the request → **401**
  `signin_required` (distinct status, rule #35: the client branches on 401 to prompt sign-in, not a
  generic toast). `'anyone'` proceeds anonymously as designed. The audit-log entry records
  `editAudience` + whether the writer was authenticated.

### Client delta forced by G3 + G4 (C4 share dialog)

The owner share modal (§3g.1) gains, on the **edit-link** row: a "Who can edit" choice
(radio/segmented: *Anyone with the link* | *People signed in to iHymns*) and a "Show my name on the
shared page" checkbox (the checkbox also offered on the view-link row). The shared edit surface
(§3g.2), when `setlist_get` returns `lockReason='signin_required'`, renders a sign-in prompt instead
of the editor (reusing the existing auth entry points) — a clean, non-anonymous branch of the same
page.

---

## §1 Current state (everything cited)

### 1a. The five tables and what each is authoritative for

| Table | schema.sql | Stores | Authoritative for |
|---|---|---|---|
| `tblUserSetlists` | :1369-1391 | `(UserId, SetlistId)` UNIQUE, `Name`, `SongsJson` (object array `{id,title,songbook,number,arrangement?}`), `ExpiresAt`, `SlotsJson` | **The live set list** of a signed-in owner. The one row every live share and every collab/token edit resolves to. |
| `tblUserSetlistTombstones` | :1406-1418 | `(UserId, SetlistId)`, `Reason` VARCHAR (`user|expired|admin`) | Deletion propagation (#1661) — absence never implies deletion. |
| `tblSharedSetlists` | :1428-1454 | `ShareId VARCHAR(16)` PK (8 hex = 32 bits, `bin2hex(random_bytes(4))` api.php:2363), `Data` JSON snapshot, `OwnerUserId`+`SourceSetlistId` (live link, #1380), `CreatedBy`, `ViewCount` | **The view-scope capability URL.** Anyone with the id can read (`setlist_get`, no auth). Live-linked shares re-read the owner's current `tblUserSetlists` row at serve time (SharedSetlist.php:322-368); a deleted source ⇒ 410 "no longer shared" (api.php:2449-2455). Both user FKs `ON DELETE SET NULL` — a share must not 404 because the sharer deleted their account (schema.sql:1438-1445). |
| `tblSetlistSchedule` | :1846-1865 | `(SetlistId, UserId, OrgId, ScheduledDate, Notes)` | Calendar scheduling (#300). Peripheral here; untouched by this plan. |
| `tblSetlistCollaborators` | :1898-1914 | `(SetlistOwnerId, SetlistId, CollaboratorId)` UNIQUE, `Permission` VARCHAR (`view|edit`) | **The account-based edit grant** (#312/#398/#1638). Requires the collaborator to have a tblUsers row. |

`SetlistId` everywhere is a client-generated string unique **only per user** — `(UserId, SetlistId)`
is the real key, so no FK cascades and ambiguity is a refusal (schema.sql:1837-1844,
setlist_collab.php:166-170, 237-254).

### 1b. The view-share flow (#147/#155/#1380) — what a recipient gets today

- Owner taps Share (setlist.js:1129-1131 → `shareSetlist()` :2518) → signed-in owners pre-sync so
  the live-link row exists (:2530-2546) → `generateShareLink()` POSTs `setlist_share`
  (:2438-2510) with `{name, songs, owner:<anon localStorage UUID>, setlistId}`.
- Server (api.php:2146-2394): sanitises ids through the XSS-safe charset (:2183-2198), caps 200
  songs (:2227), **live-links only when a REAL authenticated user provably owns the named setlist**
  (IDOR probe :2241-2257), IDOR-guards updates (:2270-2293), mints `bin2hex(random_bytes(4))`
  (:2363), returns `/setlist/shared/<id>`. **No mint rate limit exists on this case** (verified —
  nothing between :2146-2394; `setlist_get` got 120/min keyed at :2425 in #1648).
- Recipient opens the link → router.js:300-301 routes `setlist-shared` → router.js:1067-1069 calls
  `initSharedSetListPage(params.data)` (setlist.js:2721) → `fetchSharedSetlist()` GETs `setlist_get`
  (:2620-2661; **anonymous works** — the bearer header is only for the #1535 isOwner check) →
  server replies via the single projection `sharedSetlistResolveWire()` (SharedSetlist.php:280-375)
  under a strict allow-list that NEVER echoes owner identity (api.php:2481-2497).
- The page then: shows the banner **"Someone shared this set list with you. Import it to use it."**
  (setlist-shared.php:64-77), a primary **"Import to My Set Lists"** top + bottom
  (:97-100, :113-117) — and, invisibly, **arms playback on tap** (setlist.js:2817-2849:
  a delegated click listener on `#shared-setlist-songs` writes the #1533 sessionStorage
  `STORAGE_PLAYLIST_CONTEXT` (constants.js:49) with `source:'shared'` and the link's own song order,
  touching nothing in the recipient's localStorage set lists).

### 1c. How Prev/Next arms (#1533) — the exact path

- `renderSongNavigation()` (setlist.js:2207-2288) runs on **every** navigation (router.js:696-702,
  rule #32: unconditional teardown first, setlist.js:2214-2215).
- It asks `getNavigation(songId)` (setlist.js:2071-2116), which reads the **playlist context
  first** — the context carries its own ordered `songIds`, "so it serves a SHARED setlist too …
  a shared list is not in getAll()" (:2072-2076). The pre-#1533 fallback (`activeSetListId` +
  `getById()`, :2101-2116) is what the "Use" button feeds (`_armFromOwnList`, :2178-2191 and the
  Use handlers :1178-1181, :895-898).
- So the three ways to arm today: **(a)** tap a song on your own list detail (:1187-1196),
  **(b)** press "Use" (own :1178 / collab-shared :895 / — there is NO Use button on the public
  shared page), **(c)** tap a song on the public shared page (:2826-2849) or the collab shared
  detail (:905-910). (c) is the #1790 behaviour and it exists — it is just **unsignposted and
  contradicted by the page's own copy**.

### 1d. The collab flow (#312/#398/#1638/#1698) — why it needs an account + email

- Invite UI is two `prompt()`s on the owner's detail view (setlist.js:3217-3238): email +
  view/edit.
- `setlist_collab_invite` (api.php:10948-11105): 401 without auth (:10953-10954); owner check
  against `tblUserSetlists` (:10984-10989); the email must resolve through
  `resolveVerifiedAccountByEmail()` — **one verified match or nothing** (:11005-11013; #1635) —
  else 404 "No user with that email yet — ask them to sign in first" (:11012). Upsert into
  `tblSetlistCollaborators` (:11030-11037); 20/hr per-user rate limit (:10962); notification
  deep-links `/setlist?shared=<ownerId>:<setlistId>` (:11076-11082).
- Recipient (signed-in only): `setlist_collab_shared_with_me` (api.php:11195-11280) is the sole
  source of "Shared with me" (setlist.js:621-670, in-memory only — the SYNC BOUNDARY,
  setlist.js:597-613 / setlist_collab.php:45-72). The server states `canWrite` (api.php:11268-11271;
  client `sharedCanWrite()` setlist.js:49-53, rule #35).
- Writes go through `setlist_collab_update` (api.php:11313-11399): gate =
  `setlistCollabResolveAccess()` (setlist_collab.php:179-294, fail-closed VARCHAR vocab :91-147,
  owner-account lock #1698 :277-333), sanitiser = `setlistCollabSanitiseSongs()`
  (setlist_collab.php:355-384 — extracted precisely so two paths writing `SongsJson` can't drift),
  write = a **targeted UPDATE that structurally cannot INSERT or DELETE** (:11374-11395; the
  design argument for never touching `user_setlists_sync` is spelled out at :11293-11308).
- The `?shared=<ownerId>:<setlistId>` deep link is read by `initSetListPage()`
  (setlist.js:424-437 → `_openSharedByKey()` :740-763) — a live contract (rule #33) that must
  keep working unchanged.

### 1e. The house token precedent (rule #26)

`tblServicePresence.PresenceToken`: opaque 43-char base64url (`^[A-Za-z0-9_\-]{43}$`,
api.php:17681), stored plaintext and looked up by unique key (service_mode.php:758), hard-revocable,
rate-limited **per token, never per IP** (`'tok:' . substr(hash('sha256',$token),0,24)`,
api.php:17692-17718). This plan copies that discipline verbatim.

---

## §2 Root causes of the two complaints (specific code)

### #1790 — "Import → Use → tap"

1. **The page tells the user to Import.** setlist-shared.php:68 — "Import it to use it." — is a
   direct instruction to take the step the owner wants removed. The only visible primary actions
   are two Import buttons (:97-100, :113-117). There is no Play/Start affordance, no "tap any song
   to begin" hint; the playlist behaviour that already exists (setlist.js:2817-2849) is
   undiscoverable.
2. **The "Use"-then-tap flow the owner describes is the OWN-LIST flow** the recipient is funnelled
   into *by importing*: after Import the copy lives in localStorage, opens via
   `renderSetListDetail()`, and playback arms via "Use" (:1178-1181) or tap (:1187-1196). I.e. the
   three steps are real, but they are the consequence of the page steering the user into Import —
   not of any technical gap in the shared page.
3. **Deploy-channel skew is the likely third factor.** The arm-on-tap shipped to `alpha`
   2026-07-29 (in `origin/alpha`'s setlist.js; commit 6b7b2c03 was folded in via the #1651 merge
   train). The owner reported 2026-08-05, plausibly from the production docroot which may still
   pre-date it. **First verification task: reproduce on the exact channel the owner used** (§6).
4. Small real gaps: `sourceId` for a shared context is set from `sharedData.shareId || sharedData.id`
   (setlist.js:2847) but `fetchSharedSetlist()` never returns either key (:2647-2657) → `''`
   (informational-only today, but the token work makes it load-bearing for re-arming); and nothing
   arms on a **cold load of a song URL** from a share (context only arms via a tap on the list page
   — acceptable; the link lands on the list page).

### #1791 — collab requires account + email

1. The **only** write grant to somebody else's list is a `tblSetlistCollaborators` row keyed on
   `CollaboratorId INT → tblUsers.Id` (schema.sql:1902) — an account is structural, not incidental.
2. The **only** mint path is `setlist_collab_invite`, which refuses any email that is not a
   verified existing account (api.php:11007-11013).
3. `tblSharedSetlists` — the thing that already behaves like a capability URL — has **no scope
   column**: every ShareId is implicitly view-only, and its 32-bit id space is deliberately treated
   as "content designed to be shared by link" (api.php:2401-2424) — acceptable for view, **not**
   for a write grant.
4. No revocation primitive exists short of deleting the set list (the 410 path,
   SharedSetlist.php:333-337). An "anyone with the link can edit" model needs per-link revoke.

---

## §3 Target design — capability URLs with scope

### 3a. Principles

- **One table.** `tblSharedSetlists` already models "a capability pointing at a live set list";
  edit tokens are rows with `Scope='edit'` and a longer ShareId. No parallel table (rule #20
  reuse-first; the brief's explicit instruction).
- **One write core.** Email-collab and token edits both call one extracted helper; the sanitiser
  stays `setlistCollabSanitiseSongs()`; the write stays the structurally-can't-delete UPDATE.
- **One id-charset fold per language**, consumed by every emitter/reader of a share id, guarded by
  tree-derived CI (rules #33/#34/#35).
- **Server states policy** (`scope`, `canWrite`, `lockReason`) — the client never re-derives
  (rule #35, the `sharedCanWrite()` precedent).
- **Everything dormant until minted**; every server change verified byte-identical for existing
  view links and on an un-migrated DB (rule #28-style A/C discipline; the SharedSetlist.php
  column-gate pattern :35-58 is extended, never bypassed).

### 3b. Schema (final DDL, one pass — rule #20 stress-tested)

`appWeb/.sql/migrate-setlist-share-scope.php`, mirrored byte-identically into schema.sql, one
registry entry (registry shape per manage/includes/migration-registry.php:1-30; widening probe via
`_migProbe_columnDataType`, the #1741 P1 precedent):

```sql
ALTER TABLE tblSharedSetlists
    MODIFY ShareId VARCHAR(64) NOT NULL COMMENT '8 hex chars (legacy view links, 32-bit) or 22/43-char base64url capability token (128/256-bit). Widened #1791.';
ALTER TABLE tblSharedSetlists
    ADD COLUMN Scope      VARCHAR(10)  NOT NULL DEFAULT 'view' COMMENT 'view | edit — app-validated VARCHAR vocab, never ENUM (rule #20). edit implies view.',
    ADD COLUMN Label      VARCHAR(100)     NULL DEFAULT NULL   COMMENT 'Owner-facing name for this link ("worship team"). Display only.',
    ADD COLUMN RevokedAt  DATETIME         NULL DEFAULT NULL   COMMENT 'Hard revocation instant (UTC). Non-NULL = link refuses at every scope.',
    ADD COLUMN ExpiresAt  DATETIME         NULL DEFAULT NULL   COMMENT 'Optional expiry (UTC), NULL = never. DATETIME not TIMESTAMP (rule #20 TTL convention).',
    ADD COLUMN LastUsedAt DATETIME         NULL DEFAULT NULL   COMMENT 'Last successful token READ or WRITE (edit links) — owner-facing "in use?" signal.',
    ADD COLUMN EditCount  INT UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Successful token WRITES through this link (mirrors ViewCount).',
    ADD KEY idx_Expiry (ExpiresAt);
```

Adversarial "what forces a second migration?" pass:
- New scope value (`comment`? `suggest`?) → VARCHAR + app map, no ALTER. ✓
- Per-link naming, expiry, revoke, usage analytics → already columns. ✓
- Sign-in-to-claim-authorship on an anonymous edit → attribution lives in the audit log
  (`logActivity`), not schema; a future `tblSetlistEditHistory` would be a NEW dormant table, not
  an ALTER here. ✓
- Password-protected links (cloud-storage parity) → would be one nullable `SecretHash` column; I
  considered including it dormant and **rejected** it — no owner signal, and "flexible but simple"
  argues against a password on a capability URL (the URL is the secret). Recorded so the
  reopening cost is understood: it WOULD be a second (still additive, still safe) migration.
- Multiple links per scope per list (revoke one team's link, keep another's) → already works:
  `idx_LiveSource (OwnerUserId, SourceSetlistId)` is non-unique (schema.sql:1448). ✓

No `Channel` column, deliberately: shares are cross-docroot like `tblUserSetlists`
(api.php:11058-11062 states that rationale for collab); the rule-#26 channel wall applies to LIVE
sessions, not durable capability rows.

### 3c. Token lifecycle

- **Mint** (`setlist_share`, extended): request gains optional `scope` (`view` default) and
  `label`/`expiresAt`. `scope='edit'` **requires** an authenticated owner whose live-link probe
  passes (the existing :2241-2257 probe — an edit link to a snapshot is meaningless because edits
  target `tblUserSetlists`); refused 403 otherwise, and 409 on an un-migrated DB (status-code
  contract, rule #35). Edit ShareId = `rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')`
  (43 chars, 256-bit — the presence-token shape). New VIEW links move to 22-char/128-bit tokens
  (gate G2), legacy 8-hex links stay valid forever. **New: a mint rate limit** (`checkRateLimit
  ('setlist_share', …, 30, 3600, …)` per user/IP) — closing the gap found in §1b.
- **Read** (`setlist_get`): unchanged for legacy links (byte-identical). For any resolvable id it
  additionally returns `scope` and `canWrite` (server-stated; `canWrite` =
  `scope==='edit' && !revoked && !expired && liveLink && !ownerLocked`, the owner lock reusing
  `userOwnerState()` + `setlistCollabApplyOwnerLock()` semantics from #1698), plus `shareId` echoed
  (fixing the §2 sourceId gap) and, when `canWrite`, `songsDetailed` (the object shape the editor
  needs — projected through `setlistCollabSanitiseSongs()`, saving N per-song fetches). Revoked or
  expired ⇒ the existing 410 `unavailable` shape with `reason:'revoked'|'expired'`.
- **Write** (`setlist_token_update`, NEW): POST `{shareId, songs, name?}`. Order of gates:
  (1) shape/size caps (JSON body ≤ 256 KiB explicit check; songs capped at 200 by the sanitiser);
  (2) **per-token rate limit** `'tok:'+substr(hash('sha256',$shareId),0,24)`, 30/min,
  `checkRateLimit` + `recordRateLimitHit` (the api.php:17716-17718 pattern — NEVER per-IP);
  (3) same-origin discipline: require `X-Requested-With: XMLHttpRequest` AND any present
  `Origin`/`Referer` host to match `HTTP_HOST` (rule #29's `validateCsrfRequest()` logic; the
  public api can't call the manage-side helper, so the check is extracted/mirrored — see the CI
  guard in §4-C6 for the anti-drift mechanism);
  (4) resolve the token row: `Scope='edit'`, `RevokedAt IS NULL`,
  `(ExpiresAt IS NULL OR ExpiresAt > UTC_TIMESTAMP())`, live-link columns non-null;
  (5) owner-account lock (#1698);
  (6) sanitise via `setlistCollabSanitiseSongs()`;
  (7) write via the ONE extracted core `setlistCollabPerformUpdate()` (see 3e);
  (8) `logActivity('setlist.token_update', 'setlist', <setlistId>, {shareId, song_count,
  authUserId?})` — anonymous edits attributed to the token, signed-in edits additionally to the
  user (the issue's "sign-in optional to claim authorship"); bump `EditCount`/`LastUsedAt`.
- **Manage** (NEW, owner-auth'd): `setlist_share_list` (GET, all links for one owned SetlistId —
  full ShareId returned so the owner can re-copy; scope/label/created/lastUsed/counts) and
  `setlist_share_revoke` (POST, sets `RevokedAt = UTC_TIMESTAMP()`; both endpoints prove ownership
  with the same `(UserId, SetlistId)` probe as :2241-2257). Revocation is *hard*: the read path
  refuses before any live resolution.
- **Death**: owner deletes the set list → existing 410 live-link path already covers every token
  pointing at it (SharedSetlist.php:333-337). Owner deletes account → FK `ON DELETE SET NULL`
  degrades view links to snapshot (existing design, schema.sql:1438-1445) and **kills edit ability**
  (live-link gone ⇒ `canWrite` false) — correct by construction.

### 3d. The share-id contract (rule #33) — every consumer updated through ONE fold

New id grammar: `^[A-Za-z0-9_-]{6,64}$` (superset of the hex legacy; base64url for tokens — the
same alphabet as presence tokens).
- **PHP fold**: `sharedSetlistSafeShareId()` added beside `sharedSetlistSafeSongId()`
  (SharedSetlist.php:249-254) — used by api.php `setlist_get`:2426 (replacing the hex strip),
  og-image.php:107, and index.php:718's route regex (rewritten to the same class).
- **JS fold**: `SHARE_ID_RE` exported from constants.js — used by setlist.js:2735 (`isShortId`)
  and anywhere else that classifies a share id. Legacy base64-blob fallback (:2740-2743) keeps its
  branch: ids that fail the new RE still try `parseLegacySharedSetlist()`.
- **Mechanism, not a comment**: CI guard (§4-C6) derives the consumer list from the tree
  (`grep -l 'setlist/shared\|og-image?setlist\|action=setlist_get'`) and fails if any consumer
  carries a private `[a-f0-9]`-class share-id pattern instead of the shared fold.
- `?shared=<ownerId>:<setlistId>` (the collab deep link) is untouched — different param, different
  page, still read at setlist.js:424-437.

### 3e. One write core (rule #35)

Extract from `setlist_collab_update` (api.php:11353-11395) into
`setlistCollabPerformUpdate(\mysqli $db, int $ownerUserId, string $setlistId, array $cleanSongs,
string $newName = ''): array{changed:int, songs:array}` in includes/setlist_collab.php —
the sanitise → encode-refuse-on-false → targeted-UPDATE sequence, byte-for-byte behaviour.
`setlist_collab_update` becomes a thin caller (auth + `setlistCollabResolveAccess()` gate + core);
`setlist_token_update` is the second caller (token gate + core). Neither can INSERT or DELETE —
the safety argument at api.php:11293-11308 is inherited, not re-implemented.

### 3f. Client — the playlist view (#1790)

All changes on the existing `setlist-shared` fragment + `initSharedSetListPage()` (no new route;
no inline scripts — the page is already router-wired per rule #30):
1. **Copy inversion** (setlist-shared.php): banner becomes playlist-framing — "**Shared set list —
   tap any song to start.** Prev/Next will follow this list." Import demotes to ONE secondary
   outline button, relabelled **"Save a copy to My Set Lists"** (keeps `importSharedSetlist()`
   setlist.js:2671-2712 untouched; the #1535 owner-note logic keeps its ids).
2. **Explicit Start affordance**: a primary "▶ Start set list" button that arms the context
   (the existing :2842-2848 write, `sourceId` now fed by the echoed `shareId`) and navigates to
   song 1 — for users who don't discover tap-to-play. Arm-on-tap stays as-is.
3. **Anonymous unchanged** — the fetch already works signed-out (§1b).
4. Nothing is ever written to `localStorage['ihymns_setlists']` from this page except via the
   explicit Save-a-copy action (the SYNC BOUNDARY, setlist.js:597-613, preserved by construction).
5. The playback bar itself needs zero changes: teardown-every-navigation is already the rule-#32
   shape (setlist.js:2207-2215, router.js:696-702).

### 3g. Client — edit links (#1791)

1. **Share dialog** replacing the bare `shareSetlist()` toast flow on the owner's detail view: a
   small modal — "Anyone with the link can **view**" [Copy link] / "Anyone with the link can
   **edit**" [Create & copy] (mints scope=edit lazily on first request; signed-in owners only —
   the button explains why when signed out) / an "Active links" list (from `setlist_share_list`)
   with per-link Revoke. Existing `list.shareId` keeps meaning "my view link"; the edit link id is
   stored alongside (`list.editShareId`) — both are client-local convenience fields (they are not
   in the sync payload's server-kept columns; re-minting on another device just creates a second
   live-linked row, which the multiple-links model explicitly supports).
2. **Edit surface on the shared page**: when `setlist_get` says `canWrite:true`, the shared page
   renders the staged-copy editing pattern **lifted from `renderSharedSetListDetail()`**
   (setlist.js:782-992): per-row up/down/remove, optimistic local mutation, explicit push (to
   `setlist_token_update`, with `X-Requested-With`), re-render from the server's answer on
   refusal, `aria-live` save status. The row template + move/remove wiring is extracted into a
   shared helper both renderers consume (modularity rule — no third fork of the row markup).
3. **Add-song**: a lightweight search-and-add box on the edit surface (queries the existing
   `search`/`songs_index` API, adds `{id,title,songbook,number}` to the staged copy) — built once
   and mounted on BOTH the token-edit surface and the collab `renderSharedSetListDetail()` (which
   today can only reorder/remove — #1791's "add" applies to both).
4. **Email-invite collabs unchanged** — the Collaborators card (setlist.js:3150-3241) and its
   endpoints stay; the share dialog links to it ("…or invite a specific person by email").

### 3h. Security posture (the anonymous-write grant, addressed head-on)

| Concern | Answer |
|---|---|
| Token entropy | edit = 256-bit (43-char base64url); guessing is not a factor. View legacy 32-bit ids **never** gain write: `Scope` defaults `'view'` and the write path requires `Scope='edit'`, which only the mint path (authenticated owner) can set. |
| Storage | Plaintext PK, matching the presence-token precedent (service_mode; api.php:17724-17733). Hash-at-rest was considered and rejected: it would fork the house pattern, and the DB-compromise scenario it defends already exposes `tblUserSetlists` itself. |
| Rate limiting | Per **token** (30 writes/min), per the rule-#26 NAT argument; reads keep the keyed 120/min (api.php:2425). Mint gets 30/hr per user/IP (new). |
| Revocation | Hard, per-link (`RevokedAt`), owner-only; plus the existing 410 on set-list deletion; plus owner-account lock (#1698) freezing writes. |
| Scope of damage | One set list, by construction (the row names one `(OwnerUserId, SourceSetlistId)`); the write core cannot INSERT/DELETE rows; songs ≤ 200; body ≤ 256 KiB; the sanitiser strips everything but the four fields + arrangement (setlist_collab.php:355-384). Vandalism (clearing the list) is recoverable in principle from the mint-time `Data` snapshot (kept, not overwritten by token edits) and visible in the audit log — a restore UI is deliberately out of scope. |
| CSRF | Token in the request **body** (not ambient), plus the same-origin `X-Requested-With` + Origin/Referer host check (rule #29). |
| No geolocation, ever | Rule #26. The link IS the proof of authorisation. |
| Enumeration | Long tokens end it for new links; legacy view ids keep the #1648 throttle. |
| What this widens | An edit-link holder can leak the link onward (cloud-storage parity — inherent to the model; the owner's revoke + Label are the controls). The `setlist_get` response for an edit token includes `songsDetailed` — same data the view already exposes, no new disclosure. Owner identity stays un-echoed (gate G3). |

---

## §4 Phased commit plan (ONE PR to `alpha`; each commit atomic + revertable)

**C1 — schema: dormant capability columns (migration + registry + schema.sql).**
`migrate-setlist-share-scope.php` (idempotent, columnExists-gated per column, widening gated on
`_migProbe_columnDataType`), `@migration-adds` doctag per column (multi-column ALTER, rule #19),
byte-identical schema.sql mirror, ONE registry entry with a multi-object OR-probe
(`!columnExists(Scope) || !columnExists(RevokedAt) || … || ShareId-width≠64`). No behaviour change
anywhere. Revert = drop the entry (columns are inert).

**C2 — server: token model + endpoints (dormant).**
`_sharedSetlistTokenColumns()` gate + `sharedSetlistSafeShareId()` in SharedSetlist.php;
scope/revoked/expiry awareness in `sharedSetlistGet()`/`sharedSetlistResolveWire()` (additive
output keys); extract `setlistCollabPerformUpdate()` (behaviour-preserving refactor of
`setlist_collab_update`); new `setlist_token_update`, `setlist_share_list`,
`setlist_share_revoke`; `setlist_share` gains `scope`/`label`/`expiresAt` + the mint rate limit +
long-token minting; `setlist_get` gains `scope`/`canWrite`/`shareId`/`songsDetailed`; og-image.php
+ index.php switch to the shared id fold. **Verification inside the commit:** existing view-link
responses byte-identical (fixture diff), all new paths 409/no-op on an un-migrated DB.

**C3 — client: #1790 playlist-first shared page.**
Fragment copy inversion + Start button + Save-a-copy demotion; `SHARE_ID_RE` in constants.js;
setlist.js consumes it + threads the echoed `shareId` into `sourceId`. Independently shippable —
delivers the whole #1790 user story even if C4+ slip.

**C4 — client: #1791 share dialog + revoke + edit surface (reorder/remove).**
Owner share modal (mint/copy/revoke/label); shared-page edit mode pushing to
`setlist_token_update`; row-template extraction shared with `renderSharedSetListDetail()`.

**C5 — client: add-song affordance** on both edit surfaces (token + collab). Last because it is
the largest new UI and the rest lands full value without it.

**C6 — CI guards + tests (each mutation-proven before commit, rule #34).**
- `tests/php/test-setlist-share-tokens.php`: (a) share-id-fold guard — derives the consumer list
  from the tree and fails on any private hex-class share-id regex; (b) scope vocabulary is
  consumed via the app map / fail-closed normaliser, no inline `['view','edit']` forks;
  (c) `setlist_token_update` and `setlist_collab_update` both reach
  `setlistCollabPerformUpdate()` (no second write path to `SongsJson` outside
  `user_setlists_sync`); (d) the same-origin check on `setlist_token_update` matches the
  rule-#29 semantics (header + host comparison present).
- Registry/schema coverage ride the existing `test-migration-registry.php` +
  `test-schema-coverage.php` automatically.
- Prove each new guard can fail: break the thing, watch red, restore (documented in the commit
  message per the rule-#34 convention).

**C7 — docs + consistency (standing-tasks).**
api-docs.yaml (new endpoints + the extended `setlist_share`/`setlist_get` contracts —
documentation is not the mechanism, but it must not contradict it); help topic + wiki
(Set-list sharing: view links, edit links, email invites — three clearly-separated models,
the #1577 Live-Follow-vs-Service-Mode precedent); CHANGELOG; `.claude/ProjectBrief.md`; close-out
comments on #1790/#1791 with commit SHAs; file the G-gates as issue comments in the §5 shape.

---

## §5 Owner-decision gates (none block C1–C3; defensible defaults picked for all)

**G1 — Should edit links expire by default?**
*Why it's a judgement call:* product stance on stale write-grants, not derivable from code.
| Option | Consequence |
|---|---|
| A. Never expire (owner revokes manually) | Matches the set-list expiry default (#1661 "never expires"); simplest mental model; a forgotten link stays writable until revoked. |
| B. Default 30/90 days | Safer hygiene; but a worship team's link dying mid-season is exactly the "why did it stop working" support cost iHymns avoids. |
| C. Expiry mirrors the set list's own `ExpiresAt` when set | Zero new concepts; only helps lists that already have an expiry. |
**Recommendation: A + C** — never by default, per-link optional expiry in the dialog, and auto-cap
to the list's own expiry when one exists. Smallest reply: "A", "B-30", "B-90", or "A+C". **Does not
block** — the `ExpiresAt` column ships either way; this only sets the dialog's default.

**G2 — Mint NEW view links as long tokens too?**
*Why:* #1648 called the 32-bit id "the stronger fix … tracked separately"; this is the natural
moment. | A. Yes — new view links 22-char/128-bit, legacy hex stays valid | kills enumeration
class entirely; slightly longer URLs |
| B. No — view links stay 8-hex | shorter URLs; keeps the (rate-limited) enumeration residual |
**Recommendation: A.** Old links never break (rule #33). Smallest reply: "A" or "B".
**Does not block** (C2 implements whichever).

**G3 — Should the shared view page show WHO shared it ("Shared by Lance")?**
*Why:* today `setlist_get` **never** echoes owner identity (strict allow-list, api.php:2481-2497);
a playlist framing arguably wants a byline; that is a privacy-posture change.
| A. Keep anonymous (status quo) | zero new disclosure; sharer can put their name in the list title |
| B. Opt-in per link ("include my name") | parity with cloud-storage share sheets; one more toggle |
| C. Always show display-name for live-linked shares | friendliest; leaks account display-names to anyone holding any link |
**Recommendation: A now, B later if asked** — privacy-safe default, trivially changeable (one
allow-list key + one line of fragment). Smallest reply: "A", "B" or "C". **Does not block.**

**G4 — Is "anyone with the link can edit" acceptable with NO account at all (the full ask), or
should edit links require the recipient to be signed in?**
*Why:* this is the product's actual risk appetite for anonymous writes; everything in §3h assumes
the full ask.
| A. Fully anonymous edit (recommended design) | the owner's stated model; guarded per §3h |
| B. Link + any signed-in account | halves the ask's value (account wall returns) but gives per-user attribution |
**Recommendation: A** — it is what was asked for, the grant is per-list, revocable, rate-limited,
and audit-logged; sign-in stays optional for authorship. Smallest reply: "A" or "B".
**Blocks C4 only** (and only the anonymous branch of it).

*Sub-question answered by default (flagged as trivially changeable):* an edit-link holder can NOT
mint links, see other links, invite collaborators, rename the link, or delete the list — owner-only,
always. No gate needed unless the owner disagrees.

---

## §6 Test & verification strategy

1. **Reproduce first (before any code):** on the exact docroot the owner used, open a fresh share
   link in a private window → tap a song. If Prev/Next appears, #1790's remaining scope is
   confirmed as presentation + copy (and the finding goes on the issue); if not, diagnose the
   deploy-channel skew before touching the client.
2. **Per-commit CI:** PHP + JS syntax sweeps (repo standard), `test-schema-coverage`,
   `test-migration-registry`, `test-fragment-inline-scripts` (the reworked fragment must stay
   script-free), `test-event-names`, plus the new C6 guards — each mutation-proven.
3. **Byte-identical dormancy proofs (C2):** fixture-diff `setlist_get` for a legacy hex link
   pre/post; `setlist_share` legacy request pre/post; both on a migrated AND an un-migrated DB
   (the three-docroot/one-MySQL reality, rule C).
4. **End-to-end on alpha (manual, scripted steps in the PR):**
   - *View:* mint → private window → open → Start button → bar appears; tap-path likewise; Save a
     copy imports; owner opening own link still sees the #1535 owner note; revoked link → "no
     longer shared"; `?shared=` collab deep link unchanged.
   - *Edit:* mint edit link signed-in → private window reorder/remove/add → owner's device Sync Now
     shows the change (the `user_setlists_sync` absorb path, no new work — same as collab #1638);
     view-scope link POST to `setlist_token_update` → 403; revoke → immediate refusal; 31st write
     in a minute → 429; oversized body → 413/400; owner-locked account (#1698 fixture) → canWrite
     false + banner.
   - *Contract sweep (rule #33):* every emitter of `/setlist/shared/…` (share flow, og-image meta,
     index.php OG block, notification links) opened against the new destination; long-token link
     cold-loaded directly (index.php regex + router + client RE all accept it).
5. **Security spot-checks:** grep-proof that no response echoes `_ownerUserId`/`owner`/email
   (extend the existing allow-list comment into the C6 guard); confirm per-token (not per-IP)
   limiter keys by inspection + a two-token parallel test.
6. **Docs-consistency pass** (standing-tasks): issues updated with SHAs + evidence; wiki/help;
   the G-gate answers recorded on the issues.
