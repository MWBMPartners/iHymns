# Implementation plan — account lifecycle (#1698) + per-song relocate cascade check (#1690)

> Produced by a deep-analysis pass (Fable 5) on 2026-07-31, from the owner's decisions of the same
> day. **Verified against the codebase, not against the issue text** — it corrects the brief it was
> given in two places, and self-corrected a parsing error mid-analysis (its first FK scan read
> multiline `ON DELETE` clauses as RESTRICT; the rule sits on the following line).
>
> I independently re-verified its load-bearing claim before accepting the plan:
> `getAuthenticatedUser()` really does filter `u.IsActive = 1` (`api.php:18123`), so a disabled or
> deleted user is already locked out of every authenticated path with **no new code**. That is what
> makes this design cheap. `auth.php` carries 13 `IsActive` enforcement points.

---

# PLAN — Account lifecycle (#1697) + per-song relocate cascade check (#1690)

## Verified facts, with two corrections to the brief

All the caller's stated facts check out, with these refinements:

- `tblUsers.IsActive` exists (schema.sql:724) and is enforced in **more** than three places: `manage/includes/auth.php` :209 (token auth), :304 (session `getCurrentUser`), :553 (login), plus :947, :996, :1513, :1564, :1927–:1932, :1982 (password-reset / email-login / verification resolvers), **and** `api.php:18123` — `getAuthenticatedUser()` filters `u.IsActive = 1` on every bearer request, and `api.php:2768` rejects login with "Account is disabled." (403). **Consequence that shapes the whole design: a disabled/deleted user is already locked out of every owner-authenticated path with zero new code.** `setUserActive()` (auth.php:1349) already revokes all bearer tokens on deactivate.
- Hard delete: `deleteUser()` at auth.php:1398 (called from `manage/users.php:284` and `api.php:12866` `admin_user_delete`) and the self-service `account_delete` transaction at api.php:6842–6899. That is **three** call sites, not two.
- `tblUserSetlists.UserId` is `ON DELETE CASCADE` (schema.sql:1133); `tblSetlistTemplates.CreatedBy` is `ON DELETE SET NULL` (schema.sql:1567) — and `setlistTemplateCanEdit()`'s doc-block (includes/setlist_templates.php:464) already documents the #1697 orphan as a "KNOWN, DOCUMENTED GAP", deferring the admin override "rather than built speculatively". The owner has now asked for exactly that build.
- `tests/php/test-account-delete-fk-coverage.php` asserts every tblUsers FK is CASCADE-or-SET-NULL (source scan of schema.sql) + that `account_delete` names the two PII-scrub tables. It must be reworked, not merely preserved (§1.10).
- Full tblUsers FK inventory (57 constraints, parsed from schema.sql with multiline rules — my first single-line awk mis-read them all as RESTRICT; the ON DELETE clause is conventionally on the next line): CASCADE set = tokens/device-codes/reset-tokens/email-tokens/org-members/content-licences/**setlists**/setlist-tombstones/favorites/custom-tags/**schedule**/**collaborators (both FKs)**/push/notifications/annotation-votes/live-follow-host/apns/presentation-assignments/print-templates(OwnerId)/auth-providers/api-key-requests(RequesterId). SET NULL set = lyrics Submitted/Approved, apikeys CreatedBy, **sharedsetlists CreatedBy + OwnerUserId**, songrequests UserId, activitylog, **templates CreatedBy**, revisions UserId/ReviewedBy, songhistory, tagmap, searchqueries, conflicts/queue/identity-map/line-translations/line-annotations audit cols, usage-events, quality-findings, external-refs, gating tables.

---

# PIECE 1 — Disable vs Delete

## 1.1 The two states and their storage

**Add ONE additive migration** (`appWeb/.sql/migrate-user-account-status.php`):

```sql
ALTER TABLE tblUsers
  ADD COLUMN Status VARCHAR(20) NOT NULL DEFAULT 'active'
    COMMENT 'active | disabled | deleted — app-validated, VARCHAR not ENUM (rule #20). deleted = anonymised tombstone (#1697)',
  ADD COLUMN StatusChangedAt DATETIME NULL DEFAULT NULL
    COMMENT 'UTC when Status last changed; DATETIME not TIMESTAMP (rule #20 TTL convention)',
  ADD INDEX idx_Status (Status);
UPDATE tblUsers SET Status = 'disabled' WHERE IsActive = 0;  -- backfill
```

**Why `IsActive` is not enough but stays load-bearing:** the ~13 existing `IsActive = 1` filters are the lock. Both new inactive states set `IsActive = 0`, so every existing auth/login/token/reset query locks them out **without being touched**. `Status` exists only to *distinguish* disabled (reversible) from deleted (tombstone) — for the admin UI, the reactivation guard, and derive-at-read lock labelling. Invariant, enforced in the ONE writer (§1.7): `IsActive = (Status === 'active')`. Rule #20 adversarial check: a future `suspended`/`pending_verification` state is a new VARCHAR value + one map line, no ALTER; "when was it deleted" (retention windows, scheduled purge) is `StatusChangedAt`; "who did it" is already `tblActivityLog` (`user.deactivate`/`user.activate` exist since #535).

**Disabled** = `Status='disabled'`, `IsActive=0`, row byte-identical otherwise. Fully reversible.

**Deleted** = **anonymised tombstone**: the row survives with `Status='deleted'`, `IsActive=0`, and every PII column scrubbed (§1.4), plus the user's *private* data rows erased. Ownership/attribution FKs stay resolvable — nothing is orphaned — while the erasure is real.

### Data-protection reasoning (state explicitly, per the brief)

- What is erased: identity (Username→placeholder, Email, DisplayName, PasswordHash, CcliNumber, Settings, PreferredLanguagesJson, AvatarService, LastLoginAt, LoginCount, Role→'user'), all credentials/tokens/providers (incl. the existing best-effort Apple revoke), and all private per-user rows (favorites, setlists, custom tags, push/APNs subscriptions, notifications, org memberships, schedules, per-user print templates, device codes — i.e. exactly what the CASCADE graph deletes today).
- What is retained: (a) the anonymised tombstone row (an integer id + 'deleted' + timestamps — no personal data); (b) rows *the user chose to publish* (public templates, shared-setlist snapshots) with authorship resolving to the tombstone; (c) audit/curatorial attribution rows (`tblActivityLog`, `tblSongRevisions`, etc.) — today these keep their rows with `UserId` SET-NULLed; under the tombstone the FK never fires, so the design must *choose* per column (§1.4, the KEEP vs NULL split).
- **Product/legal calls, not technical ones — flag to the owner:** (i) whether a tombstone + full PII scrub satisfies Apple's account-deletion guideline and GDPR erasure (industry practice says yes for anonymisation, but that is a judgement); (ii) whether behavioural rows (`tblSearchQueries`, `tblSongHistory`, `tblSongUsageEvents`) keyed to the tombstone count as anonymised or should be nulled/deleted — my recommendation below nulls them, reproducing today's behaviour exactly; (iii) whether the erasure audit row may record the pre-scrub username (legacy `deleteUser()` logs it; `account_delete` deliberately does not — recommend adopting the stricter convention for all three funnels, and note `tests/php/test-activity-log-scrub.php` exists).

## 1.2 The derive-at-read mechanism — ONE helper

New `appWeb/public_html/includes/user_status.php`:

- `userStatusColumnReady(\\mysqli $db): bool` — INFORMATION_SCHEMA probe, static-memoised, written to read identically to `setlistSlotsColumnReady()` / `userSyncExpiryReady()` (the house pattern).
- **`userStateFromRow(?int $isActive, ?string $status): string`** — **PURE**, the core classifier. Valid `Status` wins; absent/unknown Status falls back to `IsActive` (1→'active', 0→'disabled'). This is what JOIN-based readers call on columns they already fetched — the "one mechanism" answer *and* the runtime-testable handle.
- `userOwnerState(\\mysqli $db, ?int $ownerId): string` — returns `'active'|'disabled'|'deleted'|'absent'` (`absent` for NULL/0 — historical SET-NULL orphans, anonymous rows). One indexed PK SELECT with a `Status`-column-gated select list; per-request static memo `[id => state]`.
- **`userContentLocked(string $state): bool`** — PURE: `$state !== 'active'`. Note `'absent'` is locked too — that is precisely the #1697 orphan template, now handled by the same predicate instead of being a special case.

**Why deriving cannot get out of step** (the argument the owner accepted): the owner's state lives in exactly one row; every consumer computes lock-ness from that row at read time; reactivation is one UPDATE and every surface is instantly consistent. No un-stamping pass, no half-finished state.

### Cost on the hot paths — be specific

| Path | Added cost |
|---|---|
| `user_setlists_sync` (#1649/#1662 danger zone) | **Zero. Untouched.** It is owner-bearer-authenticated and `getAuthenticatedUser()` already refuses non-active users. Do not edit this handler at all. |
| `setlist_get` / `sharedSetlistResolveWire()` | **Zero** (see §1.3 — deleted owners are handled at erase time by re-freezing; disabled owners need nothing because they cannot write, so the live row is de-facto frozen). |
| `setlist_collab_shared_with_me` | Zero extra queries — it already `JOIN tblUsers u`; add `u.IsActive` (+ gated `u.Status`) to the SELECT and classify via `userStateFromRow()`. |
| `setlistCollabResolveAccess()` | +1 memoised PK SELECT, only on the collaborator branch (the owner branch is the authed caller, who is active by construction). |
| `setlist_templates` list | Zero extra round-trips — add `LEFT JOIN tblUsers` to the existing query. |

## 1.3 "Visible but locked", per owned-content surface (enumerated from schema, §0 inventory)

1. **`tblSharedSetlists`** (public share pages; wire shape single-sourced in `sharedSetlistResolveWire()`, consumed by `setlist_get`, og-image.php, index.php meta).
   - *Disabled owner:* no change needed. Viewers only ever read; the owner cannot write (auth lock) and collaborators are now blocked (item 3), so the live resolution serves genuinely frozen content. Reactivation resumes live edits seamlessly. Visible ✓, locked ✓, zero hot-path cost.
   - *Deleted owner:* handled **at erase time**, not read time — the erasure core re-freezes each owned live share (copy the current `tblUserSetlists` state into `Data`, then NULL `OwnerUserId`/`SourceSetlistId`) *before* the setlist rows are deleted. `sharedSetlistResolveWire()` then takes its existing snapshot branch untouched. Innocent link-holders keep the content; the 410-unavailable path stays reserved for a setlist the owner deliberately deleted. (Under today's hard delete the SET NULL FKs produce a *stale* snapshot; the re-freeze is strictly better.)
2. **`tblSetlistTemplates`** (public + org templates). Author-only edit (`setlistTemplateCanEdit`) means a locked owner already cannot edit — the *gap* is manageability. Add entitlement **`manage_setlist_templates` => ['admin','global_admin']**: in `setlist_template_update` / `setlist_template_delete`, callers with it may edit/delete **any** template (closing the #1697 orphan for both NULL-CreatedBy and tombstone-CreatedBy cases). The `setlist_templates` list emits `ownerState`/`ownerLocked` (via the JOIN) and extends `canEdit` to admins server-side — the response already states `canEdit` precisely so clients don't re-derive policy (rule #35).
3. **`tblSetlistCollaborators`** — the one true derive-at-read write gate. In `setlistCollabResolveAccess()`, when the resolved role is `collaborator`, fetch the owner state and pass the access array through a new **PURE** `setlistCollabApplyOwnerLock(array $access, string $ownerState): array` → `canWrite=false` (keep `canRead=true` — visible-but-locked), add `'ownerState'` key. `setlist_collab_update` then refuses automatically via its existing `canWrite` gate. `setlist_collab_shared_with_me` emits `locked: true` per row; the client module renders a badge and suppresses edit affordances (find the consumer of `shared` in `js/modules/` and wire DOM-side; no new fetch).
4. **`tblLiveFollowSessions.HostUserId`** — a locked host cannot broadcast (auth), but end sessions eagerly anyway: `setUserActive(false)` and the erasure core both run `UPDATE tblLiveFollowSessions SET IsActive=0 WHERE HostUserId=?` (table-existence-gated), mirroring the existing token revoke.
5. **`tblSetlistSchedule`** — owner-scoped endpoints only (api.php:10280–10380, all `WHERE UserId = ?`); auth lock covers it. No change.
6. **`tblPrintTemplates.OwnerId`** — per-user templates are reserved-but-unshipped (NULL = global). Note in the helper doc-block that any future owner-scoped read consults `userOwnerState()`; no code now.
7. **Attribution columns** (revisions, song requests, lyrics Submitted/Approved, tag map, etc.) — admin surfaces render whatever the join returns; a tombstone renders its scrubbed placeholder name. No lock semantics needed (these aren't "content the user owns" in the access sense).

## 1.4 The erasure core — derive the actions from the FK graph, not a typed list

New `appWeb/public_html/includes/account_lifecycle.php`:

- **`accountEraseActionFor(string $table, string $column, string $deleteRule): string`** — **PURE**; returns `'delete' | 'null' | 'keep' | 'refuse'`. Policy: `CASCADE` → `'delete'` (erase the private rows — exactly what the FK graph already declares); `SET NULL` → `'null'` for the behavioural/analytics columns (`tblSearchQueries.UserId`, `tblSongHistory.UserId`, `tblSongUsageEvents.UserId` — reproduces today's privacy outcome), `'keep'` for everything else (attribution/ownership resolves to the tombstone — the owner's "nothing is orphaned"); anything else → `'refuse'` (fail closed; CI already forbids RESTRICT). The behavioural NULL-list is one small const beside the function.
- **`accountErase(\\mysqli $db, int $userId): array`** — runs **inside the caller's transaction** (same contract as `songRelocate()`, documented). Steps, order load-bearing:
  1. Guard: `userStatusColumnReady()` — if the migration hasn't run, **refuse** with a message naming the setup-database card (never silently fall back to hard delete; refusing is recoverable).
  2. Re-freeze owned live shares (§1.3.1) — must precede setlist deletion.
  3. The two PII scrubs `account_delete` already does (`tblSongRequests.ContactEmail/IpAddress`, `tblLoginAttempts` by username) — must precede the username scrub.
  4. End hosted live-follow sessions; count + delete `tblApiTokens` (explicit, for the audit count, as today).
  5. Enumerate live FKs referencing `tblUsers(Id)` from INFORMATION_SCHEMA (`REFERENTIAL_CONSTRAINTS` ⋈ `KEY_COLUMN_USAGE`, one memoised query), map each through `accountEraseActionFor()`, and execute `DELETE FROM \\`<t>\\` WHERE \\`<col>\\` = ?` / `UPDATE … SET \\`<col>\\` = NULL …` accordingly. Identifiers come from I_S: charset-validate `^[A-Za-z0-9_$]+$` + backtick (rule #5's allow-list clause; document that I_S is server metadata). Deriving from the live schema means **a future user-FK table is covered automatically by its own declared rule** — the mechanism, not a comment (rule #35), and the FK graph's declarations stop being dead letters (§1.5).
  6. Tombstone UPDATE: scrub PII (Username → a placeholder that cannot collide with a mintable username — implementer must check the username validation rules in `createUser()` and pick a reserved shape, e.g. `deleted#<id>` if `#` is invalid, else reserve the `deleted-` prefix at registration), `Status='deleted'`, `IsActive=0`, `StatusChangedAt=UTC_TIMESTAMP()`.
- Wire the three funnels to it:
  - `deleteUser()` (auth.php:1384) — body becomes: begin transaction, `accountErase()`, commit, audit. Keep name (callers unchanged) but fix its doc-comment and adopt the numeric-only audit convention (flag to owner, §1.1).
  - `account_delete` (api.php ~6842) — keep its re-auth, rate-limit, last-global-admin guard, Apple revoke, FOR-UPDATE lock and idempotency exactly as they are; replace T3+T4's inline scrub/delete with `accountErase()` inside the same transaction. Idempotency tweak: "already erased" = `Status='deleted'` → same idempotent 200.
  - `admin_user_delete` (api.php:12818) — unchanged (calls `deleteUser()`).
- **A genuine hard purge is not built now.** The FK graph is intact, so a raw `DELETE FROM tblUsers` still cascades correctly if a legal demand ever requires row removal; say so in the doc-block rather than building a fourth surface.

## 1.5 The FK question (answered explicitly)

**Keep every FK exactly as declared. No FK DDL.** Three reasons: (a) safety net — any residual/manual hard delete still behaves; (b) the declarations become the *data* driving the derived erasure (§1.4.5), so they are now load-bearing, not vestigial; (c) `test-account-delete-fk-coverage.php`'s CASCADE-or-SET-NULL invariant remains the property both the legacy path and the new core depend on. The only DDL in this piece is the additive `tblUsers` migration (§1.1), with full rule #19 obligations: byte-identical schema.sql mirror in the same commit, `@migration-adds tblUsers.Status` + `@migration-adds tblUsers.StatusChangedAt` doctags (one per column — the scanner catches only the first otherwise), ONE `migration-registry.php` entry whose probe is a multi-object OR (`!columnExists(tblUsers,'Status') || !columnExists(tblUsers,'StatusChangedAt')`) — never `=> true`.

## 1.6 Reactivation

Under derive-at-read it is one UPDATE (`Status='active'`, `IsActive=1`) and everything owned unlocks instantly: setlists were never touched; templates/collab shares un-lock on the next read; live shares resume live resolution. **Confirmed automatic.** What does **not** come back, deliberately:
- Bearer tokens (revoked at disable) — the user signs in again. Correct.
- Expired setlists: while disabled, the owner never syncs, so `userSyncExpireSetlists()` (user_sync.php:932, lazy at owner read) never ran — the rows still exist. On the first post-reactivation sync, past-`ExpiresAt` rows are tombstoned (`Reason='expired'`) and non-expired ones survive. **Exactly the owner's stated rule, for free.**
- Hosted live-follow sessions ended at disable — start a new one. Correct.
- Guard: `setUserActive(true)` must **refuse** when `Status='deleted'` (a tombstone is not reactivatable — its identity is gone). Enforce in the writer, not just the UI.

**Pre-existing gap worth filing (#1661 adjacent):** `sharedSetlistResolveWire()` never checks `ExpiresAt`, so a live-shared setlist past its expiry keeps serving to link-holders until the *owner* next syncs — and a disabled owner never syncs. Cheap fix (gated `ExpiresAt` check in the live SELECT); file as its own issue rather than smuggling it in.

## 1.7 Admin surface + entitlements

`/manage/users` already has `toggle_active` (gated `edit_users`) and `delete` (gated `delete_users`); API twins `admin_user_toggle_active` / `admin_user_delete` exist with the same gates. Changes:
- **Disable/Enable** stays `edit_users`; make `setUserActive()` the ONE state writer: it now also writes `Status`/`StatusChangedAt` (column-gated) and ends hosted sessions.
- **Erase** stays `delete_users`; relabel the button/copy ("Erase account — anonymises the account and deletes its private data; published content stays visible but locked; cannot be undone") and add a type-the-username confirm (the #1218 §2 worked pattern). Plain form POST → `validateCsrf()` is fine (rule #29 targets long-lived AJAX); if any of it becomes AJAX, use `validateCsrfRequest()`.
- Status badge in the user list (Active / Disabled / Deleted) from the columns the list query already selects (+gated Status).
- New entitlements: `manage_setlist_templates` (§1.3.2) and `publish_public_templates` (§1.8) — added to `ENTITLEMENTS` in `includes/entitlements.php` **and** mirrored in `js/modules/entitlements.js` (`test-entitlement-parity.php` enforces the pair). Check `manage/includes/admin-links.php` gates stay consistent (`test-admin-gate-parity.php`).

## 1.8 Interaction with #1692/#1694 and #1697's original subject

- **Song soft delete (#1692 stage 2): do NOT share machinery, say so plainly.** Songs are single-table *content* — stamping `IsDeleted/DeletedAt/DeletedBy` on `tblSongs` is correct there (one table, no reactivation-consistency problem, and the read paths filter one flag). Accounts are *principals* whose ownership fans out across 30+ tables — that is why they derive. What they should share is **convention**: VARCHAR status vocabularies, `DATETIME` timestamps, "restore is one write", and the entitlement split (soft action at a lower tier than purge). Cross-reference the two issues; nothing more.
- **#1697's original `is_public` gate: include it here** (same PR — it is the same lifecycle story: unowned/locked public content exists *because* anyone could publish). In `setlist_template_save` and `setlist_template_update`, honour `is_public=1` only when the caller has `publish_public_templates`; otherwise **reject with a clear 403-style error** (never silently demote — the silent-no-op lesson). Recommended default `['admin','global_admin']`; an operator can widen it at /manage/entitlements. Flag the default to the owner as trivially changeable.

## 1.9 DDL summary (rule #19 checklist)

One migration, `migrate-user-account-status.php`: additive, idempotent (column-existence-gated ALTERs — mysqli STRICT), CLI+web dual-mode like its siblings; backfills `Status='disabled'` from `IsActive=0`; schema.sql mirror byte-identical incl. COMMENT text; one registry entry (script + card + multi-object OR probe reading live INFORMATION_SCHEMA); doctags per column. No ENUM anywhere.

## 1.10 Guards (the runtime-handle requirement)

- **New `tests/php/test-account-lifecycle.php`** — CALLS the pure functions, no DB:
  - `userStateFromRow()` truth table (valid Status wins; NULL Status falls back to IsActive; unknown Status value → fallback, not fatal).
  - `userContentLocked()` for all four states, including `'absent'` → locked (the #1697 orphan case pinned by execution, not prose).
  - `setlistCollabApplyOwnerLock()`: edit-collaborator + disabled owner → `canWrite=false, canRead=true`; active owner → untouched array.
  - **Totality**: parse schema.sql for every FK referencing `tblUsers(Id)` (tree-derived per rule #34, reusing the fk-coverage regex), feed each `(table, column, deleteRule)` through `accountEraseActionFor()`, assert none returns `'refuse'` and that the delete/null/keep partition is exhaustive. A future FK is auto-covered; a RESTRICT FK goes red here *and* in fk-coverage.
- **Rework `test-account-delete-fk-coverage.php`**: keep assertion 1 (CASCADE-or-SET-NULL, still load-bearing for §1.5); repoint the PII-scrub source assertions from the api.php case body to `account_lifecycle.php` (the scrubs move); add an assertion that all three funnels reference `accountErase(` (reach property — source scan is correct here, there is no runtime handle for "every call site delegates").
- **Mutation-test before first commit of each guard** (rule #34): flip `userContentLocked` to `return false`, gut `accountEraseActionFor` to `return 'keep'`, drop a fixture FK — watch each go red, restore, note it in the test header (the `test-transaction-fatal.php` worked example is the template).
- Existing suites that must stay green and be re-run: `test-entitlement-parity.php`, `test-admin-gate-parity.php`, `test-setlist-collab.php`, `test-setlist-templates.php`, `test-user-sync-guard.php`, `test-migration-registry.php`, `test-schema-coverage.php`.

---

# PIECE 2 — #1690 option A: per-song cascade refusal + missing-FK detection

## 2.1 Design

`songRelocateAssertCascades()` (includes/song_relocate.php:370) currently memoises one schema-wide string verdict: any non-CASCADE FK to `tblSongs(SongId)` refuses **every** move, and a column with **no** FK produces no I_S row at all, so `migrate-song-softref-fks.php`'s three columns (`tblSongRequests.ResolvedSongId`, `tblSongRevisions.SongId`, `tblSongLinkSuggestionsDismissed.SongIdA/B` — verified in the migration) are invisible: on an un-migrated install a move strands every revision silently. Restructure into three layers:

1. **Memoised schema catalogue** (unchanged cost, once per request): the existing I_S query, **plus `k.COLUMN_NAME` in the SELECT** (it currently fetches only TABLE_NAME), and one additional memoised I_S.COLUMNS query resolving existence of the expected `(table, column)` pairs. Keep fail-CLOSED on probe error, exactly as now.
2. **The expected-FK allow-list**: a new const in song_relocate.php, `SONG_RELOCATE_EXPECTED_SONGID_FKS` — every `(table, column, constraintName, fixMigration)` for the 41 FKs schema.sql declares against `tblSongs(SongId)` (constraint names verified present in schema.sql, e.g. `fk_link_song`:2202, `fk_Revisions_Song`:1626). `fixMigration` is `'songid-prefix-fixup'` for the four retro-cascade constraints, `'song-softref-fks'` for the four #1064 columns, `null` (generic "schema drift — see /manage/schema-audit") otherwise. Deliberate exclusions, commented: `tblSongRedirects.OldSongId` (soft by design — the redirect layer + `songRelocateIdTaken()` own it), `tblContentRestrictions.EntityId` and `tblSongbookEntries`'s extra columns (steps 5/5b rewrite them).
3. **Pure gap/verdict core** — the runtime handle the guard will CALL:
   - `songRelocateCascadeGaps(array $liveFkRows, array $liveColumnPairs, array $expected): array` — PURE. Non-CASCADE gap = live FK with `UPDATE_RULE ≠ 'CASCADE'` (known or unknown constraint). Missing-FK gap = expected pair whose **table AND column exist live** but which matches no live FK row (a table/column the install never migrated has no rows to strand — not a gap).
   - `songRelocateCascadeVerdict(array $gaps, callable $childHasRows): ?string` — PURE given an injected probe; returns `null` (move allowed) or the refusal message naming each blocking table and its `fixMigration` card. Only gaps whose `$childHasRows($table, $column)` answers true block — **owner's option A**.
   - The real `$childHasRows` closure runs `SELECT 1 FROM \\`<t>\\` WHERE \\`<col>\\` = ? LIMIT 1` per offending pair, per move. Identifiers: expected-list entries are PHP-source constants (rule #5 clause a); unknown live FKs' names are I_S metadata — charset-validate `^[A-Za-z0-9_$]+$` + backtick, with a comment (clause b).
4. `songRelocateAssertCascades(\\mysqli $db, string $songId)` — signature gains the song id (only caller is `songRelocate()` step 2b, verified by grep; pass `$oldSongId` — children reference the *current* id). Update the memo comment: the catalogue is memoised, the verdict is now per-song, and **within a bulk move some songs may proceed while others refuse — that is the point**, replacing the old "the whole batch fails identically" note. On a fully-migrated install the gap set is empty and the per-move cost is **zero extra queries**.
5. Keep throwing `SongRelocateEnvironmentException`; the `error_hint` funnel plumbing (#1679 A8) is untouched.

## 2.2 The probe fix (required regardless)

`migration-registry.php` :473 (`songid-prefix-fixup`): the probe tests only the prefix-mismatch, so on an install with clean prefixes but RESTRICT FKs the card sits in the **collapsed "already applied" expander** (setup-database.php ~2823) and "Apply all pending" skips it — while the migration's STEP 1 would in fact fix the FKs (it runs unconditionally; verified). Fix: pending when **(a)** prefix-mismatch rows exist (current query) **OR (b)** any of the four cascade-target constraints exists live with `UPDATE_RULE ≠ 'CASCADE'`. Source the four names from `SONG_RELOCATE_EXPECTED_SONGID_FKS` (require song_relocate.php from the registry — `dirname(__DIR__, 2) . '/includes/song_relocate.php'`) so the probe, the refusal and the migration cannot drift (rule #35 mechanism; the migration script keeps its own standalone copy, with a CI assertion that its four names ⊆ the const). Keep the `catch → return false` fail-shape (registry convention). This stays a Closure and is not the always-true literal, so `test-migration-registry.php` stays green.

**Bonus fix, file an issue + do it in the same pass (cheap, same shape):** `song-softref-fks`'s probe (:1608) checks only the "representative" `fk_Revisions_Song` — a partial apply (revisions FK added, dismissed-suggestion FKs failed) shows green, violating rule #19's multi-object OR-probe rule. Extend to all four constraint names, each gated on its table+column existing.

## 2.3 Guards

- **New `tests/php/test-song-relocate-cascade-verdict.php`** — no DB; CALLS the pure core with synthetic I_S rows and closure probes:
  - all-CASCADE catalogue → verdict null; `$childHasRows` never invoked (assert via a closure that records calls).
  - RESTRICT FK + no child rows → **null** (the owner's option A, pinned by execution).
  - RESTRICT FK + child rows → refusal naming `migrate-songid-prefix-fixup.php`.
  - expected FK absent, table+column exist, rows exist → refusal naming `migrate-song-softref-fks.php` (the `tblSongRevisions` case that motivated #1690).
  - expected FK absent but table (or column) absent live → not a gap.
  - unknown live non-CASCADE FK + rows → refusal naming the constraint.
  - `SONG_RELOCATE_EXPECTED_SONGID_FKS` equals the set parsed from schema.sql (`grep -r`-equivalent file read — remember **rg/dot-directory blindness**: the test must `file_get_contents` the schema path directly, as the existing tests do) minus the commented exclusions — tree-derived, then **mutation-tested**: delete a const entry, watch red.
- `tests/php/test-song-relocate-hardening.php` (:445–:469) asserts the assert-function exists, is called from `songRelocate()`, and reads `REFERENTIAL_CONSTRAINTS ⋈ KEY_COLUMN_USAGE` — all survive; re-run and adjust any wording-window assertions that the refactor shifts (its own header warns its matchers are position-sensitive). `test-transaction-fatal.php` and `test-song-relocate-funnels.php` must stay green.

---

# Order of implementation, and what verifies without a database

1. **Piece 2** (isolated, pure-function-heavy): const + pure core + assert restructure → new verdict test (mutation-tested) → probe fixes → re-run the four relocate/transaction suites. *Everything here verifies without a DB.*
2. **Piece 1a — mechanism**: `user_status.php` + pure classifiers + `account_lifecycle.php` pure partition → `test-account-lifecycle.php` (mutation-tested). *No DB.*
3. **Piece 1b — DDL**: migration + schema.sql mirror + registry entry/probe → `test-schema-coverage.php` + `test-migration-registry.php`. *No DB (both are source scans).*
4. **Piece 1c — erasure**: `accountErase()` + re-freeze + wire the three funnels + rework fk-coverage test.
5. **Piece 1d — locks**: collab lock + shared_with_me flag + templates JOIN/`ownerState` + admin template override + `is_public` publish gate + new entitlements (PHP+JS) → parity suites.
6. **Piece 1e — admin UI**: users.php wording/badges/confirm, `setUserActive()` as the one state writer + session-ending + deleted-reactivation refusal.
7. **Standing tasks**: issues (this epic; the resolveWire-ExpiresAt gap; the softref probe fix; retrospective refs), `php -l` / `node --check` sweep, docs/Wiki, handoff.

**Cannot be verified in this container** (no MySQL, no browser — say so in the PR): the migration actually applying; the erasure transaction end-to-end; the live-share re-freeze; probe pendency flips on a drifted install; collab-lock UX. These need the alpha deploy + a scripted verify pass (a drifted-install rehearsal: drop `fk_Revisions_Song` on a scratch DB, confirm the card goes pending and a move of a revision-bearing song refuses while a bare song moves).

# Things the owner has not considered / collisions (question 6)

1. **"Deleted" vs Apple/GDPR** — the tombstone design is my recommendation, but whether anonymisation satisfies the erasure obligations is a legal/product call. Does not block implementation; the mechanism is the same either way (the KEEP-list just shrinks to empty if the owner wants harder erasure).
2. **Behavioural data at erasure** (search/view/usage rows) — recommend NULLing as declared (today's exact outcome); keeping them keyed to the tombstone is defensible but is the owner's call. One-line change in the partition map either way.
3. **Erasure audit content** — legacy `deleteUser()` logs the username into `tblActivityLog`, which partially defeats a scrub; `account_delete` already refuses to. Recommend the strict convention everywhere; owner may prefer forensics. Flagged, trivially changeable.
4. **Live-share expiry gap** (§1.6) — a disabled owner's expired-but-shared setlist keeps serving until reactivation+sync. Collides mildly with the spirit of #1661; separate cheap fix, filed.
5. **`is_public` default entitlement tier** (§1.8) — defensible default chosen (admin+), explicitly flagged as changeable in one map line.
6. **The instruction "content becomes invalid for access" vs "shared items stay visible"** collide for *shared* setlists of a *disabled* user: I resolved it as shared-stays-readable / all writes locked / private-stays-private (auth lock), which I believe is the owner's intent — but it means a disabled user's shared link keeps serving their content while disabled. If the owner instead wants disabled shares withheld, that is one branch in `sharedSetlistResolveWire()` — but it would punish the innocent third parties the owner explicitly protected, so I recommend against it.
