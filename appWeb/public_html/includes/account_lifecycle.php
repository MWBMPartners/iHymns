<?php

declare(strict_types=1);

/**
 * account_lifecycle.php — the ONE account erasure, DERIVED from the FK graph (#1698)
 * =================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When somebody closes their account for good, this file is what actually
 * empties it: it deletes everything private, blanks every personal detail, and
 * leaves behind a numbered blank row so that things they had already published —
 * a shared service order somebody else bookmarked, a public template a church is
 * using — do not break for the people still looking at them.
 *
 * ---------------------------------------------------------------------------
 * THE SHAPE OF THE DECISION (#1698, owner-stated)
 * ---------------------------------------------------------------------------
 * Accounts are DISABLED by default, not deleted. Erasure is the other, explicit
 * option. Content owned by either becomes invalid for access and edit, but
 * anything the user had ALREADY SHARED stays VISIBLE and merely LOCKED, so third
 * parties are not punished for somebody else's account closing.
 *
 * Erasure here is an ANONYMISED TOMBSTONE, not a row removal:
 *
 *   - the `tblUsers` row SURVIVES, carrying an integer id, `Status = 'deleted'`,
 *     `IsActive = 0` and nothing else that identifies anyone;
 *   - every PII column is scrubbed (username → a reserved placeholder, email,
 *     display name, password hash, CCLI number, settings, language preferences,
 *     avatar service, last-login, login count, role → 'user');
 *   - every PRIVATE row is deleted — exactly the set the FK graph already
 *     declares `ON DELETE CASCADE`;
 *   - ownership/attribution columns keep resolving, to the tombstone, so nothing
 *     is orphaned and no reader has to grow a null branch.
 *
 * ⚠ WHETHER A TOMBSTONE SATISFIES GDPR ERASURE AND APPLE'S ACCOUNT-DELETION
 * GUIDELINE IS A LEGAL/PRODUCT CALL, NOT A TECHNICAL ONE. Industry practice
 * treats true anonymisation as sufficient, but that is a judgement the owner
 * makes, not this file. If the answer is "harder erasure", the KEEP list in
 * `accountEraseActionFor()` shrinks — the mechanism is unchanged. Recorded here
 * because a decision that lives only in a chat reply is a decision that is lost.
 *
 * ---------------------------------------------------------------------------
 * WHY THE WORK IS DERIVED FROM `INFORMATION_SCHEMA` RATHER THAN TYPED OUT
 * ---------------------------------------------------------------------------
 * `schema.sql` declares 56 foreign keys referencing `tblUsers(Id)`, each with an
 * explicit `ON DELETE` rule that already says what should happen to that table
 * when the account goes: CASCADE means "this row is the user's, erase it", SET
 * NULL means "this row outlives the user". Those declarations were previously a
 * dead letter — the only thing that consumed them was MySQL, during a
 * `DELETE FROM tblUsers` this design no longer performs.
 *
 * So the rule becomes the DATA. `accountEraseActionFor()` maps each live
 * (table, column, delete-rule) triple to one of four actions, and the loop
 * executes them. Three consequences worth stating:
 *
 *   1. A FUTURE table with a user FK is covered THE DAY IT IS ADDED, by its own
 *      declared rule. No list to update, nothing to forget — the mechanism rule
 *      #35 asks for rather than a comment saying "remember to add it here".
 *   2. `tests/php/test-account-lifecycle.php` can parse `schema.sql`, feed every
 *      FK through the pure classifier, and assert the partition is EXHAUSTIVE
 *      and contains no refusals. That is a totality proof, derived from the tree
 *      (rule #34), not a list somebody typed.
 *   3. A drifted install whose FK says something we have no policy for (RESTRICT
 *      / NO ACTION) makes the whole erasure REFUSE rather than guess. Refusing
 *      is recoverable and loud; a half-erased account is neither.
 *
 * ---------------------------------------------------------------------------
 * THE MIGRATION GATE IS A REFUSAL, NOT A FALLBACK
 * ---------------------------------------------------------------------------
 * Migrations here are WEB-RUN from /manage/setup-database and three docroots
 * share ONE MySQL, so `Status` may not exist yet. Every other optional-column
 * gate in this codebase degrades to the old behaviour — but the old behaviour
 * HERE is `DELETE FROM tblUsers`, an irreversible hard delete. Silently falling
 * back to it would mean the operator pressed a button labelled "anonymise" and
 * got a purge. So this refuses, naming the card to run. A refusal is a five-
 * second fix; the fallback is unrecoverable.
 *
 * ---------------------------------------------------------------------------
 * A GENUINE HARD PURGE IS DELIBERATELY NOT BUILT
 * ---------------------------------------------------------------------------
 * Every FK is left exactly as declared (no FK DDL in #1698), so a raw
 * `DELETE FROM tblUsers WHERE Id = ?` still cascades correctly if a legal demand
 * ever requires the row itself to go. That is one statement in a console. Adding
 * a fourth destructive surface to the app for a case that has never arisen is
 * how a dangerous button ends up next to a routine one.
 *
 * ---------------------------------------------------------------------------
 * TRANSACTION CONTRACT
 * ---------------------------------------------------------------------------
 * `accountErase()` runs INSIDE THE CALLER'S TRANSACTION and neither begins,
 * commits nor rolls back — the same contract as `songRelocate()`. It is called
 * from three funnels (`deleteUser()`, the self-service `account_delete`
 * endpoint, and `admin_user_delete` via `deleteUser()`), and each has its own
 * surrounding work (a FOR UPDATE lock, an idempotency check, an audit row) that
 * must commit or roll back atomically WITH the erasure. A helper that owned its
 * own transaction would silently commit the caller's half-finished work.
 *
 * @see appWeb/public_html/includes/user_status.php    the derive-at-read state
 * @see appWeb/.sql/migrate-user-account-status.php    the ONE migration
 * @see tests/php/test-account-lifecycle.php           the behavioural lock
 * @see tests/php/test-account-delete-fk-coverage.php  the CASCADE/SET NULL invariant
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-referential-constraints-table.html
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sql_identifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'user_status.php';
/* The re-freeze step reuses the share module's live-link column gate and its
   song-id allow-list rather than re-deriving either — two copies of "is this id
   safe to render into an attribute" is precisely the drift the modularity rule
   forbids, and the FIX-1 guard in `sharedSetlistSafeSongId()` is security code. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SharedSetlist.php';

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Thrown when an erasure cannot be performed SAFELY.
 *
 * ELI5: "I am not doing this, and here is exactly why."
 *
 * A distinct class rather than a bare `RuntimeException` so the three funnels can
 * tell "this install is not ready / its schema drifted" apart from "the database
 * fell over mid-statement". The first is an operator action; the second is a 500.
 */
class AccountEraseException extends \RuntimeException
{
}

/**
 * Behavioural / analytics columns whose SET NULL means "null it", not "keep it".
 *
 * ELI5: what somebody searched for, which songs they opened, and when — that is
 * a record of a person's behaviour, so it is unlinked from them rather than
 * pointed at the blank tombstone row.
 *
 * OWNER DECISION, and a ONE-LINE CHANGE either way. Keying these rows to the
 * tombstone would be defensible (the tombstone identifies nobody), but NULLing
 * them reproduces EXACTLY the outcome today's hard delete produces via
 * `ON DELETE SET NULL` — so an operator who erases an account after this change
 * gets the same privacy result they got before it, which is the conservative
 * default when the alternative is a judgement call the owner has not made.
 * To keep them instead, delete a line from this list; to add another
 * behavioural table, add one.
 *
 * Keys are lower-cased `table.column` because MySQL folds identifier case
 * depending on the server and the host filesystem, and a live row spelled
 * `tblsearchqueries` must match an entry written `tblSearchQueries`.
 *
 * @return array<string,true>
 */
function accountEraseBehaviouralColumns(): array
{
    return [
        'tblsearchqueries.userid'   => true,   /* what they searched for */
        'tblsonghistory.userid'     => true,   /* which songs they opened */
        'tblsongusageevents.userid' => true,   /* presentation/usage telemetry */
    ];
}

/**
 * What must happen to this (table, column) when the account is erased? — PURE.
 *
 * ELI5: the database already says, for every table that points at a user,
 * whether those rows belong to the user or merely mention them. This turns that
 * into an instruction.
 *
 * THE POLICY, in full:
 *
 *   `CASCADE`  → 'delete'  The FK already declares these rows as the user's own.
 *                          Tokens, setlists, favourites, custom tags, push and
 *                          APNs subscriptions, notifications, org memberships,
 *                          schedules, per-user print templates, device codes,
 *                          auth providers. Deleting them reproduces exactly what
 *                          `DELETE FROM tblUsers` did, which is the point: the
 *                          erasure is REAL, only the row itself survives.
 *
 *   `SET NULL` → 'null'    …for the behavioural/analytics columns above only.
 *              → 'keep'    …for everything else. These are attribution and
 *                          ownership columns — who submitted a lyric, who
 *                          approved it, who created an API key, who authored a
 *                          revision. Under a tombstone the FK never fires, so
 *                          the design must CHOOSE, and it chooses to let them
 *                          resolve to the tombstone. That is the owner's
 *                          "nothing is orphaned": a revision keeps a valid
 *                          author id that renders as a blank placeholder, rather
 *                          than a NULL every reader has to special-case (which
 *                          is the state that produced the #1698 orphan template
 *                          in the first place).
 *
 *   anything   → 'refuse'  RESTRICT, NO ACTION, an empty rule, a value from some
 *                          future MySQL. We have no policy, so we do not guess.
 *                          `tests/php/test-account-delete-fk-coverage.php`
 *                          already forbids these in `schema.sql`, so reaching
 *                          this branch means the LIVE database has drifted from
 *                          the declarations — precisely when guessing is worst.
 *
 * Case- and whitespace-tolerant on the rule for the same reason the table keys
 * are folded: this value comes off the wire from `INFORMATION_SCHEMA`, and
 * `SET NULL` versus `SET  NULL` must not change an erasure into a refusal.
 *
 * @param string $table      Child table name, as `INFORMATION_SCHEMA` spells it.
 * @param string $column     Child column holding the user id.
 * @param string $deleteRule `REFERENTIAL_CONSTRAINTS.DELETE_RULE`.
 * @return string 'delete' | 'null' | 'keep' | 'refuse'
 */
function accountEraseActionFor(string $table, string $column, string $deleteRule): string
{
    $rule = strtoupper((string)preg_replace('/\s+/', ' ', trim($deleteRule)));

    if ($rule === 'CASCADE') {
        return 'delete';
    }
    if ($rule === 'SET NULL') {
        $key = strtolower($table) . '.' . strtolower($column);
        return isset(accountEraseBehaviouralColumns()[$key]) ? 'null' : 'keep';
    }
    return 'refuse';
}

/**
 * The reserved username a tombstone carries — PURE.
 *
 * ELI5: a name nobody can ever sign up as, so an erased account can never
 * collide with a real one.
 *
 * `#` IS THE LOAD-BEARING CHARACTER. Every username-minting path in this
 * codebase validates against `[a-z0-9_.\-]` or `[A-Za-z0-9_.\-]`
 * (`auth_register` api.php:2357, the username-change endpoint api.php:6620, the
 * admin rename `updateUsername()` auth.php:1299, and the SIWA local-part
 * derivation api.php:17360, which strips anything else). `#` is in none of them,
 * so `deleted#41` is unmintable by construction rather than by a reservation
 * somebody has to remember to enforce. That matters because `tblUsers.Username`
 * is `NOT NULL UNIQUE` — a placeholder a live user could hold would make the
 * second erasure on an install fail with a duplicate-key error.
 *
 * Including the id keeps it unique across erasures without a counter, and keeps
 * the row self-describing in a database console. It is not PII: it is the
 * primary key, which every attribution row already carries.
 */
function accountEraseUsernamePlaceholder(int $userId): string
{
    return 'deleted#' . $userId;
}

/**
 * Enumerate the LIVE foreign keys referencing `tblUsers(Id)`, memoised.
 *
 * ELI5: ask the database itself "what points at a user here?", once.
 *
 * `REFERENTIAL_CONSTRAINTS` carries `DELETE_RULE` but not the columns, so
 * `KEY_COLUMN_USAGE` is joined in to keep this to keys that really reference
 * `Id` — the same join `songRelocateFkCatalogue()` performs for the songbook
 * move, for the same reason.
 *
 * ⚠ This reads the LIVE schema, not `schema.sql`. That is the entire point: a
 * table this install has (from a migration, a restored backup, another tool) is
 * covered even if this checkout has never heard of it, and a table `schema.sql`
 * declares but this install has not migrated yet produces no row and is
 * correctly skipped.
 *
 * FAILS CLOSED by propagating: if the catalogue cannot be read there is no
 * honest way to erase, and an erasure that silently skipped tables would leave
 * private rows behind under a name nobody can trace.
 *
 * @param \mysqli $db Live connection.
 * @return list<array{table:string,column:string,constraint:string,rule:string}>
 */
function accountEraseFkGraph(\mysqli $db): array
{
    static $graph = null;
    if ($graph !== null) {
        return $graph;
    }

    $stmt = $db->prepare(
        "SELECT r.CONSTRAINT_NAME, r.DELETE_RULE, k.TABLE_NAME, k.COLUMN_NAME
           FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
           JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                 ON  k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
                 AND k.CONSTRAINT_NAME   = r.CONSTRAINT_NAME
          WHERE r.CONSTRAINT_SCHEMA        = DATABASE()
            AND r.REFERENCED_TABLE_NAME    = 'tblUsers'
            AND k.REFERENCED_TABLE_NAME    = 'tblUsers'
            AND k.REFERENCED_COLUMN_NAME   = 'Id'"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'table'      => (string)$r['TABLE_NAME'],
            'column'     => (string)$r['COLUMN_NAME'],
            'constraint' => (string)$r['CONSTRAINT_NAME'],
            'rule'       => (string)$r['DELETE_RULE'],
        ];
    }
    return $graph = $out;
}

/**
 * RE-FREEZE the user's live share links before their set lists are deleted.
 *
 * ELI5: somebody else has a link to this person's Sunday service order. Copy
 * what it currently says into the link itself, so the link keeps working after
 * the set list behind it is gone.
 *
 * WHY THIS EXISTS, AND WHY IT MUST RUN FIRST.
 * A "live" share (#1380) stores only a pointer — `OwnerUserId` +
 * `SourceSetlistId` — and `sharedSetlistResolveWire()` reads the owner's CURRENT
 * `tblUserSetlists` row at request time. Under the OLD hard delete, the FK's
 * `ON DELETE SET NULL` blanked `OwnerUserId`, and the share fell back to
 * whatever stale snapshot `Data` happened to hold from the day it was created —
 * possibly months of edits out of date, with no indication anything was wrong.
 *
 * Re-freezing copies the CURRENT state into `Data` and then drops the pointer,
 * so the link-holder keeps exactly what they could see a moment before the
 * account closed. That is strictly better than the old behaviour, and it is why
 * this runs BEFORE the derived loop deletes `tblUserSetlists`: afterwards there
 * is nothing left to copy.
 *
 * It also SCRUBS the payload's `owner` key — a client-minted UUID that is a
 * stable pseudonymous device identifier — and leaves it empty. A side effect
 * worth stating plainly: `setlist_share` refuses an empty `owner`, so a
 * re-frozen share is permanently un-updatable by anyone. Visible, and locked.
 *
 * The 410-"no longer shared" path is deliberately NOT taken here. That state is
 * reserved for a set list the owner DELIBERATELY deleted; an account closing is
 * not the same statement, and punishing the link-holder for it is exactly what
 * the owner's decision rules out.
 *
 * Column-existence gated: on an install where `migrate-shared-setlist-live-link.php`
 * has not run there are no live shares to re-freeze, only snapshots, which are
 * already frozen by construction.
 *
 * @param \mysqli $db     Live connection, inside the caller's transaction.
 * @param int     $userId The account being erased.
 * @return int Number of live shares re-frozen.
 */
function accountEraseRefreezeShares(\mysqli $db, int $userId): int
{
    if (!_sharedSetlistLiveLinkColumns()) {
        return 0;
    }

    $stmt = $db->prepare(
        'SELECT s.ShareId, s.Data, s.SourceSetlistId, l.Name AS LiveName, l.SongsJson AS LiveSongs
           FROM tblSharedSetlists s
           LEFT JOIN tblUserSetlists l
                  ON l.UserId = s.OwnerUserId AND l.SetlistId = s.SourceSetlistId
          WHERE s.OwnerUserId = ? AND s.SourceSetlistId IS NOT NULL'
    );
    bindParamSafe('accountEraseRefreezeShares.select', $stmt, 'i', $userId);
    $stmt->execute();
    $shares = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($shares === []) {
        return 0;
    }

    $upd = $db->prepare(
        'UPDATE tblSharedSetlists
            SET Data = ?, OwnerUserId = NULL, SourceSetlistId = NULL
          WHERE ShareId = ?'
    );

    $frozen = 0;
    foreach ($shares as $share) {
        $data = json_decode((string)$share['Data'], true);
        if (!is_array($data)) {
            $data = [];
        }

        /* The live row may already be gone (the owner deleted that set list
           before closing the account). Then there is nothing newer to copy and
           the existing snapshot IS the honest answer — but the pointer must
           still be dropped, or the share would resolve "unavailable" forever on
           the strength of a row that can never come back. */
        if ($share['LiveName'] !== null) {
            $data['name'] = (string)$share['LiveName'];

            /* Project the stored song OBJECTS to the share wire's id STRINGS +
               arrangements map — the same projection `sharedSetlistResolveWire()`
               performs on a live read, including its id charset guard, so the
               frozen payload is byte-for-byte what a viewer would have got. */
            $songs        = [];
            $arrangements = [];
            $decoded      = json_decode((string)$share['LiveSongs'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $s) {
                    if (!is_array($s) || !isset($s['id'])) { continue; }
                    $sid = sharedSetlistSafeSongId((string)$s['id']);
                    if ($sid === '') { continue; }
                    $songs[] = $sid;
                    if (isset($s['arrangement']) && is_array($s['arrangement'])) {
                        $arr = array_values(array_filter(
                            $s['arrangement'],
                            static fn($v) => is_int($v) && $v >= 0
                        ));
                        if ($arr !== []) { $arrangements[$sid] = $arr; }
                    }
                }
            }
            $data['songs'] = $songs;
            if ($arrangements !== []) {
                $data['arrangements'] = $arrangements;
            } else {
                unset($data['arrangements']);
            }
        }

        /* PII: the anonymous owner UUID goes with everything else. */
        $data['owner'] = '';

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            /* Unencodable payload (invalid UTF-8 from some older writer). Store
               the minimum honest document rather than binding `false`, which
               mysqli would write as an empty string and MySQL would reject on a
               JSON column — turning a courtesy into a failed erasure. */
            $json = '{"name":"","songs":[],"owner":""}';
        }

        $shareId = (string)$share['ShareId'];
        bindParamSafe('accountEraseRefreezeShares.update', $upd, 'ss', $json, $shareId);
        $upd->execute();
        $frozen++;
    }
    $upd->close();

    return $frozen;
}

/**
 * ERASE an account: anonymised tombstone + real deletion of everything private.
 *
 * ELI5: empties the account and blanks the person, keeping only a numbered blank
 * row so that things they published still work for everyone else.
 *
 * RUNS INSIDE THE CALLER'S TRANSACTION — it does not begin, commit or roll back.
 * See the file header for why.
 *
 * THE ORDER IS LOAD-BEARING, so it is numbered:
 *   1. Refuse unless the `Status` column exists. Never fall back to a hard
 *      delete (file header).
 *   2. Read the username — needed by step 4 and gone after step 6.
 *   3. Re-freeze live shares. MUST precede step 5, which deletes the set lists
 *      the freeze copies from.
 *   4. The two PII scrubs the FK graph does NOT reach: `tblSongRequests`'s
 *      contact email + IP (the row itself is attribution and is kept), and
 *      `tblLoginAttempts`, which is keyed by username STRING with no FK at all.
 *      MUST precede step 6, which destroys the username it matches on.
 *   5. The derived loop — delete / null / keep, per the live FK graph.
 *   6. The tombstone UPDATE.
 *
 * WHAT IS DELIBERATELY *NOT* A STEP:
 *  - Ending the user's hosted Live Follow sessions. `setUserActive(false)` does
 *    that, because a disabled host's session would otherwise sit marked active
 *    forever, appearing to congregants as a live service that never advances.
 *    Here it would be dead code: `fk_LiveFollow_Host` is CASCADE, so step 5
 *    DELETES those rows outright. An UPDATE of rows about to be deleted in the
 *    same transaction is noise that reads like a safeguard.
 *  - An explicit `DELETE FROM tblApiTokens`. Same reason — the FK is CASCADE, so
 *    step 5 covers it. The COUNT is still reported separately, because token
 *    revocation is a stated security requirement rather than an FK side effect
 *    and the number feeds every funnel's audit row.
 *
 * @param \mysqli $db     Live connection, inside an open transaction.
 * @param int     $userId The account to erase.
 * @return array{tokensRevoked:int,sharesRefrozen:int,deleted:array<string,int>,nulled:array<string,int>,kept:int}
 * @throws AccountEraseException when the migration has not run, or the live FK
 *         graph contains a rule this design has no policy for.
 */
function accountErase(\mysqli $db, int $userId): array
{
    if ($userId <= 0) {
        throw new AccountEraseException('accountErase() needs a positive user id.');
    }

    /* --- 1. The migration gate. A REFUSAL, never a fallback. -------------- */
    if (!userStatusColumnReady($db)) {
        throw new AccountEraseException(
            'Account erasure is unavailable until the "Account lifecycle status (#1698)" '
            . 'migration has been applied at /manage/setup-database. Refusing rather than '
            . 'falling back to a permanent hard delete.'
        );
    }

    /* --- 2. The username, captured before step 6 destroys it. ------------- */
    $stmt = $db->prepare('SELECT Username FROM tblUsers WHERE Id = ? LIMIT 1');
    bindParamSafe('accountErase.username', $stmt, 'i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($row === null) {
        throw new AccountEraseException('No such user: ' . $userId);
    }
    $username = (string)$row[0];

    /* --- 3. Re-freeze live shares BEFORE their set lists are deleted. ----- */
    $sharesRefrozen = accountEraseRefreezeShares($db, $userId);

    /* --- 4. The two PII stragglers with no FK to follow. ------------------ */
    $blank = '';
    $stmt = $db->prepare('UPDATE tblSongRequests SET ContactEmail = ?, IpAddress = ? WHERE UserId = ?');
    bindParamSafe('accountErase.scrubRequests', $stmt, 'ssi', $blank, $blank, $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM tblLoginAttempts WHERE Username = ?');
    bindParamSafe('accountErase.scrubAttempts', $stmt, 's', $username);
    $stmt->execute();
    $stmt->close();

    /* --- 5. The derived loop. --------------------------------------------
     * Classify EVERYTHING first, then execute. A refusal found half way
     * through a loop that had already deleted six tables would leave the
     * caller's rollback as the only thing standing between the user and a
     * half-erased account — and rollback is a promise about the connection,
     * not about a bug in this function. Deciding before acting means a
     * drifted schema costs nothing at all.
     */
    $plan = [];
    foreach (accountEraseFkGraph($db) as $fk) {
        $action = accountEraseActionFor($fk['table'], $fk['column'], $fk['rule']);
        if ($action === 'refuse') {
            throw new AccountEraseException(sprintf(
                'Refusing to erase account %d: foreign key `%s` on %s.%s declares '
                . 'ON DELETE %s, which this design has no policy for. Every key '
                . 'referencing tblUsers(Id) must be CASCADE (the row is the '
                . "user's) or SET NULL (the row outlives them). Check "
                . '/manage/schema-audit — the live schema has drifted from schema.sql.',
                $userId,
                $fk['constraint'],
                $fk['table'],
                $fk['column'],
                strtoupper(trim($fk['rule']))
            ));
        }
        /* Rule #5 clause (b): these identifiers are INFORMATION_SCHEMA server
           metadata, not user input — but "not user input" is not a licence to
           concatenate (a table could arrive from a restored backup or another
           tool), so each is charset-validated against the exact unquoted-
           identifier allow-list and only then backticked. A name that fails
           validation is a REFUSAL, not a skip: skipping would leave the user's
           rows in a table nobody can name, which is the silent-partial-erasure
           this whole function is shaped to avoid. */
        if (!ihymnsSqlIdentifierSafe($fk['table']) || !ihymnsSqlIdentifierSafe($fk['column'])) {
            throw new AccountEraseException(sprintf(
                'Refusing to erase account %d: foreign key `%s` names an identifier '
                . 'this code will not interpolate (%s.%s).',
                $userId,
                $fk['constraint'],
                $fk['table'],
                $fk['column']
            ));
        }
        $plan[] = $fk + ['action' => $action];
    }

    $deleted = [];
    $nulled  = [];
    $kept    = 0;

    foreach ($plan as $step) {
        $key = $step['table'] . '.' . $step['column'];

        if ($step['action'] === 'keep') {
            $kept++;
            continue;
        }

        if ($step['action'] === 'delete') {
            /* Backticked, charset-validated identifiers (above); the VALUE is
               always bound. */
            $sql = 'DELETE FROM `' . $step['table'] . '` WHERE `' . $step['column'] . '` = ?';
        } else {
            $sql = 'UPDATE `' . $step['table'] . '` SET `' . $step['column'] . '` = NULL '
                 . 'WHERE `' . $step['column'] . '` = ?';
        }

        $stmt = $db->prepare($sql);
        bindParamSafe('accountErase.' . $step['action'] . '.' . $key, $stmt, 'i', $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            if ($step['action'] === 'delete') {
                $deleted[$key] = ($deleted[$key] ?? 0) + $affected;
            } else {
                $nulled[$key] = ($nulled[$key] ?? 0) + $affected;
            }
        }
    }

    /* --- 6. The tombstone. ------------------------------------------------
     * Every identifying column blanked in ONE statement, so there is no window
     * in which the row is half-anonymous. `Role` drops to 'user' because a
     * tombstone must not retain a privileged role — nothing can authenticate as
     * it (IsActive = 0), but a `global_admin` tombstone would still be counted
     * by the "is this the last global admin?" guard, which would then refuse a
     * REAL admin's deletion on the strength of an erased account.
     *
     * `StatusChangedAt` is written from `UTC_TIMESTAMP()` — a DATETIME holding
     * UTC, matching the convention rule #20 sets for every new timestamp in this
     * schema family (a TIMESTAMP column would re-interpret against the session
     * zone, and these rows outlive the session that wrote them).
     */
    $placeholder = accountEraseUsernamePlaceholder($userId);
    $stmt = $db->prepare(
        "UPDATE tblUsers
            SET Username               = ?,
                Email                  = '',
                EmailVerified          = 0,
                PasswordHash           = '',
                DisplayName            = '',
                Role                   = 'user',
                GroupId                = NULL,
                IsActive               = 0,
                Status                 = 'deleted',
                StatusChangedAt        = UTC_TIMESTAMP(),
                CcliNumber             = '',
                CcliVerified           = 0,
                LastLoginAt            = NULL,
                LoginCount             = 0,
                Settings               = NULL,
                AvatarService          = NULL,
                PreferredLanguagesJson = NULL,
                UpdatedAt              = CURRENT_TIMESTAMP
          WHERE Id = ?"
    );
    bindParamSafe('accountErase.tombstone', $stmt, 'si', $placeholder, $userId);
    $stmt->execute();
    $stmt->close();

    /* tblApiTokens is in the CASCADE set, so step 5 already deleted the rows.
       Reported separately because token revocation is a STATED security
       requirement rather than an FK side effect, and the count feeds the audit
       row every funnel writes. The lookup is case-INSENSITIVE because the keys
       above are spelled the way the live server spells them, and
       `lower_case_table_names` decides that — a case-sensitive lookup would
       silently report "0 tokens revoked" on a folding server. */
    $tokensRevoked = 0;
    foreach ($deleted as $k => $n) {
        if (strtolower($k) === 'tblapitokens.userid') { $tokensRevoked = $n; }
    }

    return [
        'tokensRevoked'  => $tokensRevoked,
        'sharesRefrozen' => $sharesRefrozen,
        'deleted'        => $deleted,
        'nulled'         => $nulled,
        'kept'           => $kept,
    ];
}
