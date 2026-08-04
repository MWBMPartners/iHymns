<?php

declare(strict_types=1);

/**
 * user_status.php — the ONE account-lifecycle state, DERIVED at read time (#1698)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * An account is in one of three states: working, switched off, or erased. This
 * file is the single place that answers "which one?" — and everything that owns
 * content asks it at the moment it renders, rather than anything going round
 * stamping "locked" onto rows. Switch the account back on and every screen is
 * correct on its next read, with nothing left half-done.
 *
 * ---------------------------------------------------------------------------
 * THE DESIGN, AND WHY IT IS ALMOST NO CODE
 * ---------------------------------------------------------------------------
 * The owner's decision (#1698): accounts are DISABLED by default rather than
 * deleted; both states exist and must be clearly distinguishable; content owned
 * by a disabled or deleted account becomes invalid for access and edit;
 * reactivation restores non-expired set lists; and content the user had already
 * SHARED stays VISIBLE but LOCKED, so third parties are not punished for
 * somebody else's account closing.
 *
 * The load-bearing fact that makes all of that cheap is that the lock ALREADY
 * EXISTS. `getAuthenticatedUser()` (api.php) filters `u.IsActive = 1` on every
 * bearer request, and `manage/includes/auth.php` carries thirteen more
 * `IsActive` enforcement points (token auth, session resolution, login,
 * password-reset, email-login and verification resolvers). Both inactive states
 * set `IsActive = 0`, so a disabled or deleted user is locked out of every
 * owner-authenticated path with NO new code and NO second gate. Adding one would
 * be strictly worse: two gates that must agree, with nothing making them
 * (rule #35).
 *
 * `Status` therefore exists for exactly one job — to DISTINGUISH disabled
 * (reversible) from deleted (an anonymised tombstone) — for the admin UI, the
 * reactivation guard, and the lock LABEL on shared surfaces. The invariant
 * `IsActive = (Status === 'active')` is enforced in the ONE writer,
 * `setUserActive()` in `manage/includes/auth.php`.
 *
 * ---------------------------------------------------------------------------
 * WHY DERIVE RATHER THAN STAMP — the argument, not the preference
 * ---------------------------------------------------------------------------
 * The alternative is a pass that writes `IsLocked = 1` onto every row a
 * disabled user owns, and an inverse pass on reactivation. That has three
 * failure modes this design does not have:
 *
 *   1. A partial pass leaves an account half-locked, and nothing can tell the
 *      difference between "not reached yet" and "deliberately unlocked".
 *   2. Every NEW table that grows a user FK has to remember to join the pass.
 *      Forgetting is silent — the classic absence-shaped bug this repo keeps
 *      re-shipping (rule #30, rule #33).
 *   3. Reactivation has to be exactly the inverse of disablement, forever,
 *      including for rows created WHILE disabled.
 *
 * Deriving has one owner-state row, read at the moment of decision. Reactivation
 * is one UPDATE and every surface is instantly consistent. There is no state to
 * get out of step because there is only one copy of it.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE COSTS ON THE HOT PATHS (measured against the call sites)
 * ---------------------------------------------------------------------------
 *  - `user_setlists_sync`  — ZERO. It is not touched. It is owner-bearer-
 *    authenticated, so `getAuthenticatedUser()` has already refused a non-active
 *    user before the handler runs. It is also the most damage-prone code in this
 *    codebase (#1649 destroyed users' 51st-and-later set lists; #1662 amputated
 *    lists past 200 songs) and `tests/php/test-user-sync-guard.php` is 175
 *    assertions holding it still. Adding an unnecessary branch there would be
 *    the worst trade available.
 *  - `setlist_get` / `sharedSetlistResolveWire()` — ZERO. A disabled owner
 *    cannot write, so the "live" row IS frozen; a deleted owner's shares are
 *    re-frozen at ERASE time by `accountErase()`, before the setlist rows go.
 *  - `setlist_collab_shared_with_me` — zero extra round-trips: the query already
 *    `JOIN tblUsers u`, so it selects two more columns and classifies with
 *    `userStateFromRow()`.
 *  - `setlistCollabResolveAccess()` — one memoised primary-key SELECT, and only
 *    on the collaborator branch. The owner branch is the authenticated caller,
 *    who is active by construction.
 *  - `setlist_templates` — zero extra round-trips (one added LEFT JOIN).
 *
 * ---------------------------------------------------------------------------
 * EVERYTHING THAT CAN BE PURE IS PURE
 * ---------------------------------------------------------------------------
 * `userStateFromRow()` and `userContentLocked()` take values and return values.
 * That is not tidiness — it is the only way `tests/php/test-account-lifecycle.php`
 * can CALL them instead of grepping this file for the right words. Four
 * consecutive rounds on this branch shipped a guard that was green while the
 * thing it guarded was broken, every one of them because source inspection was
 * used as evidence for a property that had a runtime handle
 * (`tests/php/test-transaction-fatal.php` documents the regress).
 *
 * @see appWeb/public_html/includes/account_lifecycle.php  the erasure core
 * @see appWeb/.sql/migrate-user-account-status.php        the ONE migration
 * @see appWeb/public_html/manage/includes/auth.php        setUserActive(), the ONE writer
 * @see tests/php/test-account-lifecycle.php               the behavioural lock
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema.html
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * The complete `tblUsers.Status` vocabulary — key => human label.
 *
 * ELI5: the three things an account can be, and what to call each on screen.
 *
 * VARCHAR + this map, never an ENUM (rule #20): adding `suspended` or
 * `pending_deletion` later is ONE line here and a value, whereas an ENUM
 * value-add is an `ALTER` — the second migration rule #20 exists to forbid.
 *
 * A FUNCTION rather than a `const` so this file stays double-include-safe: a
 * bare file-scope `const` fatals on a second `require`, and this module is
 * reached from api.php, several `/manage/*` pages and the CLI test.
 *
 * @return array<string,string>
 */
function userStatusVocabulary(): array
{
    return [
        'active'   => 'Active',
        'disabled' => 'Disabled',
        'deleted'  => 'Deleted',
    ];
}

/**
 * The state of a row that has no owner at all.
 *
 * ELI5: "there is nobody here" — a template whose author's row was hard-deleted
 * years ago, or a row that never had an owner.
 *
 * It is NOT in `userStatusVocabulary()` because it is not a value `Status` can
 * hold; it is what `userOwnerState()` answers for a NULL/0 owner id. Naming it
 * once stops three call sites spelling it three ways.
 */
function userStateAbsent(): string
{
    return 'absent';
}

/**
 * Classify an account from the two columns a reader already has — PURE.
 *
 * ELI5: given "is this account switched on?" and "what does it say it is?",
 * answer with one word.
 *
 * THE RESOLUTION ORDER IS THE WHOLE FUNCTION:
 *
 *   1. A RECOGNISED `Status` wins outright. It is the richer column — it is the
 *      only one that can say "deleted" — so where it speaks it decides.
 *   2. Otherwise fall back to `IsActive`. This is not a nicety: migrations in
 *      this project are WEB-RUN from /manage/setup-database and three docroots
 *      share ONE MySQL, so "it is in schema.sql" and "the column exists here"
 *      are different statements. On an un-migrated install every caller passes
 *      `null` for `$status` and this function keeps answering correctly from the
 *      column that has existed since #229.
 *   3. An UNRECOGNISED `Status` string also falls back rather than throwing.
 *      The column is a VARCHAR, so it can physically hold anything — a value
 *      written by a future release, a hand-edited row, a restored backup from a
 *      newer schema. Treating that as fatal would take an install down over a
 *      string; treating it as `active` would be a fail-OPEN guess. Falling back
 *      to `IsActive` uses the column that IS still authoritative for the lock.
 *
 * `$isActive` is nullable so a caller that could not select the column (or got
 * a NULL from an outer join) has something honest to pass. NULL `IsActive` with
 * no usable Status is treated as NOT active — fail closed.
 *
 * @param int|null    $isActive `tblUsers.IsActive` (1/0), or null if unknown.
 * @param string|null $status   `tblUsers.Status`, or null on an un-migrated install.
 * @return string 'active' | 'disabled' | 'deleted'
 */
function userStateFromRow(?int $isActive, ?string $status): string
{
    if ($status !== null) {
        $normalised = strtolower(trim($status));
        if (array_key_exists($normalised, userStatusVocabulary())) {
            return $normalised;
        }
    }
    return ((int)$isActive === 1) ? 'active' : 'disabled';
}

/**
 * Is content owned by an account in this state LOCKED? — PURE.
 *
 * ELI5: anything other than a working account means "you can look, you cannot
 * change".
 *
 * Written as `!== 'active'` deliberately, i.e. as an allow-list of one. The
 * negative-list form (`=== 'disabled' || === 'deleted'`) silently grants write
 * to every state added later — a new `suspended` value would unlock everything
 * it was invented to lock, with nothing anywhere going red. Same fail-closed
 * reasoning as `setlistCollabCanWrite()`.
 *
 * NOTE `'absent'` IS LOCKED, and that is the point. A `tblSetlistTemplates` row
 * whose `CreatedBy` went NULL under `ON DELETE SET NULL` is the #1698 orphan:
 * `setlistTemplateCanEdit()` has always (correctly) refused it, which left a
 * PUBLIC template that literally nobody could edit or remove without database
 * access. It is now the same predicate as every other locked state rather than
 * a documented special case, and the admin override (`manage_setlist_templates`)
 * is what makes it manageable again.
 */
function userContentLocked(string $state): bool
{
    return $state !== 'active';
}

/**
 * Classify an owner from the row a lookup returned, or from its ABSENCE — PURE.
 *
 * ELI5: "no row at all" is an answer too, and the answer is "gone".
 *
 * Split out of `userOwnerState()` so the no-row decision has a runtime handle.
 * It is the single most fail-open-able line in this file: answering `'active'`
 * for a missing row would UNLOCK everything a hard-deleted user (the pre-#1698
 * behaviour, or a future legal purge) ever owned, and a mutation run proved the
 * database-shaped assertions could not reach it — an unconnected mysqli throws
 * long before the null branch is evaluated. Extracting it is the difference
 * between a property that is argued and a property that is checked.
 *
 * `'deleted'` rather than `'absent'`, deliberately: `'absent'` means "this
 * content names no owner", which is a statement about the CONTENT. A user id
 * that resolves to nothing is a statement about the ACCOUNT — it existed and is
 * gone — and the two read differently to a viewer (`userStateLockReason()`).
 * Both lock.
 *
 * @param array{IsActive?:mixed,Status?:mixed}|null $row One row, or null.
 * @return string 'active' | 'disabled' | 'deleted'
 */
function userStateFromOwnerRow(?array $row): string
{
    if ($row === null) {
        return 'deleted';
    }
    return userStateFromRow(
        isset($row['IsActive']) && $row['IsActive'] !== null ? (int)$row['IsActive'] : null,
        isset($row['Status']) && $row['Status'] !== null ? (string)$row['Status'] : null
    );
}

/**
 * The presentation of one account state on an admin surface — PURE.
 *
 * ELI5: what colour and what word to show for "on", "switched off" and "erased".
 *
 * The owner's decision names this explicitly: both states must be offered AND
 * CLEARLY DISTINGUISHED. Before #1698 anything not active rendered as
 * "Disabled", so an admin could not tell a reversible switch-off from an
 * irreversible erasure — precisely the distinction being asked for. Living here
 * rather than inline in `/manage/users` means (a) a test can CALL it, and (b) the
 * next admin surface that shows account state cannot invent a fourth vocabulary.
 *
 * An unrecognised state degrades to the DISABLED presentation, never the active
 * one: showing a state we do not understand as "Active" is the fail-open answer.
 *
 * @return array{0:string,1:string,2:string} [css class, label, tooltip]
 */
function userStateBadge(string $state): array
{
    switch ($state) {
        case 'active':
            return ['text-success', 'Active', 'Signed-in access allowed'];
        case 'deleted':
            return ['text-danger', 'Erased',
                    'Anonymised tombstone — personal data removed, cannot be re-enabled'];
        case 'absent':
            return ['text-muted', 'No owner', 'This row names no account'];
        default:
            return ['text-warning', 'Disabled', 'Switched off — can be re-enabled at any time'];
    }
}

/**
 * Does the typed confirmation match the account being erased? — PURE.
 *
 * ELI5: you have to type the username before the erasure will run.
 *
 * The #1218 §2 type-to-confirm pattern. An erasure is irreversible and the row
 * it acts on is chosen by an id in a hidden field, so a mis-clicked row and the
 * right one produce an identical "are you sure?" — typing the name is the one
 * confirmation that cannot be given by reflex.
 *
 * CASE-INSENSITIVE, because `tblUsers.Username` is `utf8mb4_unicode_ci`: the
 * account really is reachable by any casing, so a case-SENSITIVE gate here would
 * refuse a correctly-typed name and teach the admin that the field is broken.
 * `mb_strtolower`, not `strtolower`, for the same reason `setlistCollab-
 * NormalisePermission()` uses it — a byte-wise fold gets a Turkish 'İ' wrong.
 *
 * An EMPTY expected username can never be confirmed. That is not a theoretical
 * guard: an erased account's placeholder is unmintable, but a caller passing ''
 * for a row it failed to load would otherwise be confirmed by an empty box.
 */
function userEraseConfirmMatches(string $typed, string $username): bool
{
    $username = trim($username);
    if ($username === '') {
        return false;
    }
    return mb_strtolower(trim($typed)) === mb_strtolower($username);
}

/**
 * Is the `tblUsers.Status` column present on THIS install?
 *
 * ELI5: check the new column exists before anything selects it.
 *
 * Written to read identically to `setlistSlotsColumnReady()` and
 * `userSyncExpiryReady()` — the house pattern for an optional column — because
 * three gates that do the same job should be recognisable as the same job.
 *
 * mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
 * (`includes/db_mysql.php`), so selecting a column that is not there THROWS
 * rather than returning false. An ungated read would white-screen every page
 * that lists users on an un-migrated docroot — the #1228 class exactly.
 *
 * Memoised per request: the answer cannot change mid-request, and several
 * callers ask.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool True when tblUsers.Status exists.
 */
function userStatusColumnReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers'
            AND COLUMN_NAME = 'Status' LIMIT 1"
    );
    $stmt->execute();
    $ready = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ready;
}

/**
 * What state is the owner of this content in?
 *
 * ELI5: look up the one person who owns this thing and say what state their
 * account is in — or say "nobody" if there isn't one.
 *
 * This is the DB-touching sibling of `userStateFromRow()`, for the callers that
 * hold an owner id but not the owner's row (chiefly the collaborator branch of
 * `setlistCollabResolveAccess()`). Callers that ALREADY join `tblUsers` — the
 * shared-with-me list, the templates list — must use `userStateFromRow()` on the
 * columns they already fetched instead, because a second query for a value
 * already on the wire is pure waste.
 *
 * One indexed primary-key SELECT, memoised per request per user id: a list
 * endpoint asking about the same owner twenty times pays for one round trip.
 *
 * FAILS CLOSED, TWICE OVER:
 *  - a NULL / non-positive owner id is `'absent'` (locked);
 *  - a user id with no row is `'deleted'`, not `'absent'` and not `'active'`.
 *    A missing row means the account was HARD-deleted at some point (the
 *    pre-#1698 behaviour, or a future legal purge), and the honest reading of
 *    that is "this owner is gone", which is what `'deleted'` says.
 *
 * @param \mysqli  $db      Live connection from getDbMysqli().
 * @param int|null $ownerId `tblUsers.Id` of the owner, or null.
 * @return string 'active' | 'disabled' | 'deleted' | 'absent'
 */
function userOwnerState(\mysqli $db, ?int $ownerId): string
{
    if ($ownerId === null || $ownerId <= 0) {
        return userStateAbsent();
    }

    static $memo = [];
    if (isset($memo[$ownerId])) {
        return $memo[$ownerId];
    }

    /* The select list is COLUMN-GATED, not the whole query: `IsActive` has
       existed since #229 and is the authoritative lock on every install, so an
       un-migrated docroot still gets a correct active/disabled answer — it
       simply cannot distinguish "deleted", which on such an install cannot
       exist yet anyway (nothing has written the tombstone). */
    $sql = userStatusColumnReady($db)
        ? 'SELECT IsActive, Status FROM tblUsers WHERE Id = ? LIMIT 1'
        : 'SELECT IsActive, NULL AS Status FROM tblUsers WHERE Id = ? LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* The row-or-nothing decision is a PURE function so a test can call it —
       see userStateFromOwnerRow(). A mutant that changed the no-row answer to
       'active' survived every database-shaped assertion in
       tests/php/test-account-lifecycle.php, because an unconnected mysqli throws
       long before this line is reached. */
    return $memo[$ownerId] = userStateFromOwnerRow($row);
}

/**
 * A short, user-facing reason a surface is locked, or '' when it is not.
 *
 * ELI5: the sentence a viewer sees on a shared set list whose owner's account
 * has closed.
 *
 * PURE, and centralised so the wording cannot drift between the collaborator
 * list, the template list and any future surface. It deliberately does NOT name
 * the owner or say WHY the account is in that state — "who closed whose account
 * and when" is nobody else's business, and a shared link is readable by anyone
 * who has the URL.
 */
function userStateLockReason(string $state): string
{
    switch ($state) {
        case 'active':
            return '';
        case 'deleted':
            return 'The owner’s account has been closed. This is read-only.';
        case 'absent':
            return 'This item has no owner. This is read-only.';
        default:
            /* 'disabled' and any future locked state. Says "unavailable" rather
               than "disabled" on purpose: a viewer does not need to know the
               difference between an owner who was suspended and one who left. */
            return 'The owner’s account is unavailable. This is read-only.';
    }
}
