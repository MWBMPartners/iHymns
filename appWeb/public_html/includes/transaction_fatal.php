<?php

declare(strict_types=1);

/**
 * iHymns — the ONE list of database errors that end a whole transaction
 * (#1679 A1 / #1688 N1; its own file since #2137 review round 7)
 * ======================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Some database errors do not just fail one statement — the database throws
 * away the whole transaction. Code that catches errors and carries on must let
 * those few through, or a save can report "saved" over work that no longer
 * exists. This file holds the one function that says which errors those are.
 *
 * WHY THIS IS ITS OWN FILE (#2137 review round 7)
 * ------------------------------------------------
 * The function used to live in includes/song_relocate.php, which also loads
 * the database layer (db_mysql.php) and the song-redirect helpers. Round 7 (the
 * sixth independent review, decision 1) made every catch block that a song
 * save, the v2 editor, the works and songbooks admin pages or an importer can
 * reach from inside its transaction start with this check — about two dozen
 * files, most of which need nothing else from song_relocate.php. Loading that
 * whole file just for this check pulled db_mysql.php into places that did not
 * load it before (and broke a test that stands in its own getDbMysqli()). This
 * file loads nothing at all, so any file can require it. The function keeps its
 * name, so every caller is unchanged; song_relocate.php requires this file, so
 * everything that loads song_relocate.php still has it.
 *
 * Tested by calling it, not by reading it: tests/php/test-transaction-fatal.php.
 *
 * ALSO HERE SINCE #2137 REVIEW ROUND 8: dbTransactionIsOpen()
 * ------------------------------------------------------------
 * The one way this code base asks "is a transaction open on this connection
 * right now?" — so a best-effort write can stay out of a transaction (the geo
 * cache), and the activity log can tell "a deadlock inside a save" (pass it
 * back) from "a deadlock on a log row written after the work committed" (log it
 * and carry on). It lives here for the same reason as the list: this file loads
 * nothing, so any file can use it. Tested against a real database in
 * tests/php/test-activity-log-outside-transaction.php.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Is this throwable one that has already rolled back the CALLER'S transaction?
 *
 * ELI5: some database errors do not just fail one statement — they throw away
 * everything you have done since the transaction started. If we swallow one of
 * those and carry on, we end up cheerfully reporting success for work that no
 * longer exists.
 *
 * WHY THIS IS A SHARED PREDICATE AND NOT AN INLINE `in_array` (#1679 F8/A1)
 * ------------------------------------------------------------------------
 * `songRelocate()` re-throws these from its own best-effort SongCount recompute,
 * but the re-throw only holds if NOTHING between the relocate and the caller's
 * `commit()` swallows them again — and both funnels have several deliberate
 * `catch (\Throwable) { error_log(...) }` blocks in exactly that span
 * (`ed2_touchRevision()` in api2.php; the probe / revision / SongCount /
 * external-link / works / translation-link blocks in save_song_core.php). Each of
 * those is CORRECT for what it was written for: a best-effort follow-up must not
 * cost the curator their edit. None of them was written with "the transaction I
 * am inside may already be gone" in mind. Copying a short error-code list
 * into ten catch blocks is precisely the "keep these in sync" comment rule #35
 * names as the failure rather than the fix — so the list lives here, once, and
 * every one of those catches opens with
 * `if (songRelocateIsTransactionFatal($e)) { throw $e; }`.
 *
 * THE THREE CODES, ACCURATELY
 * ---------------------------
 *  - 1213 `ER_LOCK_DEADLOCK` — InnoDB picked this transaction as the deadlock
 *    victim and rolled the WHOLE transaction back. Swallowing this is what makes
 *    a false success reachable: `commit()` then succeeds trivially (there is
 *    nothing left to commit) and the endpoint answers `{ok:true, songId:<new>}`
 *    for a song that does not exist under that id.
 *  - 1205 `ER_LOCK_WAIT_TIMEOUT` — `innodb_lock_wait_timeout` elapsed. MySQL's
 *    DEFAULT is `innodb_rollback_on_timeout = OFF`, which rolls back only the
 *    FAILING STATEMENT, so on a default server this one does NOT by itself
 *    produce the false success — the transaction is still alive and the commit is
 *    real. It is treated as fatal anyway because (a) with that variable ON it
 *    behaves exactly like 1213, and (b) a statement that timed out waiting for a
 *    row lock in the middle of a multi-statement move is not something to log and
 *    walk past.
 *  - 1020 `ER_CHECKREAD` ("Record has changed since last read in table …") —
 *    added in #2137 review round 6 (the fifth independent review's finding 6).
 *    MariaDB raises it when `innodb_snapshot_isolation` is ON (the default on
 *    MariaDB 11.8): this transaction read a row, another committed a change to
 *    it, and this one then tried to change it. Like 1213 it ends the WHOLE
 *    transaction — checked on MariaDB 11.8.9: after it, `@@in_transaction` is 0,
 *    the transaction's own earlier write is gone, and `ROLLBACK TO SAVEPOINT`
 *    answers "SAVEPOINT … does not exist". Swallowing it is the same false
 *    success: every later statement runs on its own, outside any transaction,
 *    and the final `commit()` succeeds with nothing to commit. MySQL does not
 *    raise it from InnoDB (its locking reads always see the latest row), so on
 *    MySQL this entry changes nothing.
 *
 * https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
 * https://dev.mysql.com/doc/refman/8.0/en/innodb-parameters.html#sysvar_innodb_rollback_on_timeout
 * https://mariadb.com/kb/en/innodb-system-variables/#innodb_snapshot_isolation
 *
 * @param  \Throwable $e Anything a `catch` block caught.
 * @return bool TRUE when the caller must re-throw rather than continue to commit.
 */
function songRelocateIsTransactionFatal(\Throwable $e): bool
{
    /* WALK THE CAUSE CHAIN — do not just type-check the outermost exception.
       This function's first version tested `$e instanceof \mysqli_sql_exception`
       and stopped, and the very same pass that wrote it also added
       `songRedirectsTableReady($db, true)`, whose strict branch WRAPS whatever
       it caught in a plain `\RuntimeException` with the original as `previous`.
       So a deadlock raised inside that probe arrived here as a RuntimeException,
       this predicate answered "not fatal", the catch swallowed it and the caller
       committed — reinstating, through a brand-new code path, the exact false
       success the predicate exists to prevent. Any wrapper anywhere in either
       funnel would do the same.

       Depth-bounded because `getPrevious()` chains are attacker-free but not
       guaranteed acyclic in userland, and a guard must not be the thing that
       hangs a save. Ten is far beyond any real chain in this codebase.
       https://www.php.net/manual/en/exception.getprevious.php */
    for ($depth = 0; $e !== null && $depth < 10; $depth++, $e = $e->getPrevious()) {
        if ($e instanceof \mysqli_sql_exception
            && in_array((int)$e->getCode(), [1213, 1205, 1020], true)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Is a transaction open on this connection right now? (#2137 review round 8)
 *
 * ELI5: some writes must not happen inside somebody else's transaction (a
 * shared cache row that another request may be writing at the same moment),
 * and some errors mean "the whole save is gone" only when a save was running.
 * This asks the database server, which is the only thing that really knows.
 *
 * @return bool|null TRUE: a transaction is open. FALSE: none is open (each
 *         statement saves itself as it runs). NULL: the server's answer could
 *         not be read — treat that as "maybe open", never as "not open".
 *
 * HOW IT ASKS, AND WHY THIS WAY
 * -----------------------------
 * It reads the session's own access mode (`@@SESSION.transaction_read_only`,
 * or the older name `tx_read_only` on a server that does not know that one —
 * MariaDB before 11.1 and MySQL before 5.7.20, by their documentation; checked
 * on MariaDB 10.11.19), then runs `SET TRANSACTION READ ONLY` or
 * `SET TRANSACTION READ WRITE` — whichever the session already has. Without
 * GLOBAL or SESSION that statement sets only the NEXT transaction's access
 * mode, and both MySQL and MariaDB refuse it with error 1568 ("Transaction
 * characteristics can't be changed while a transaction is in progress")
 * while one is open — from the moment `begin_transaction()` returns, before
 * any other statement, and in a connection with autocommit switched off once
 * a statement has read or written a table. (With autocommit off, a statement
 * that touches no table, such as `SELECT 1` or the read above, opens nothing,
 * and the answer is "not open" — rightly: nothing could be lost yet.) That
 * refusal is documented by both servers and was checked on MariaDB 11.8.9,
 * MySQL 8.4.11 and MySQL 5.7.44; a refused SET leaves the open transaction
 * exactly as it was (its earlier writes still commit, and a read-only one
 * stays read-only).
 *
 * WHAT ASKING CHANGES, EXACTLY (#2137 review round 9 — the eighth review's
 * L2, the lead's decision 2; checked on MariaDB 11.8.9, MySQL 8.4.11 and
 * MariaDB 10.11.19 by tests/php/test-activity-log-outside-transaction.php,
 * A8–A12, and on MySQL 5.7.44 with the eighth review's own probe). Round 8 asked
 * with `SET TRANSACTION READ WRITE` whatever the session's own mode, and so
 * made the next statement read-write in a session set to read-only — the
 * opposite of what this comment then said. Now, when no transaction is open:
 *   - KEPT: the session's own access mode and isolation level — the next
 *     autocommit statement and the next transaction behave exactly as they
 *     would have without the question (a read-only session still refuses a
 *     write; a session at READ UNCOMMITTED still reads another request's
 *     uncommitted change); and a one-off isolation level set with
 *     `SET TRANSACTION ISOLATION LEVEL …` just before asking (the question
 *     names only the access mode, and reading the session's mode does not use
 *     a one-off up).
 *   - NOT KEPT: a one-off ACCESS MODE set with `SET TRANSACTION READ ONLY`
 *     (or READ WRITE) just before asking, where it differs from the session's
 *     own: the next transaction gets the session's mode instead. The server
 *     shows a pending one-off nowhere it could be read back from (the
 *     variables show the session's value), so it cannot be put back. Nothing
 *     in iHymns sets one.
 *   - Like any statement, it resets the connection's insert_id and
 *     affected_rows.
 *
 * Rejected:
 *   - MariaDB's `@@in_transaction` — MySQL does not have it (error 1193).
 *   - information_schema.INNODB_TRX — MySQL needs the PROCESS privilege to
 *     read it, which a shared host's account usually lacks, and it lists a
 *     transaction only after its first statement.
 *   - Counting begin_transaction() / commit() in PHP — every one of the 140-odd
 *     places that open a transaction would have to take part, and a single one
 *     that did not would make this answer "not open" inside a transaction,
 *     which is exactly the wrong way to be wrong.
 *   - Passing "a transaction is open" in from the callers — the code that needs
 *     to know (the activity log, the geo cache) is called from hundreds of
 *     places, most of which have no idea whether their caller opened one.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * Ask it BEFORE the statement that might fail, never after. A deadlock (1213)
 * or MariaDB's 1020 has already ended the whole transaction by the time PHP
 * sees it, so asking afterwards answers "not open" about a save that WAS
 * running a moment ago. It costs two round trips to the server each time
 * (one since round 8, a second since round 9 to read the session's mode).
 */
function dbTransactionIsOpen(\mysqli $db): ?bool
{
    /* The name the server uses for the session's access mode, once found. */
    static $modeVariable = null;
    try {
        $readOnly = null;
        foreach ($modeVariable !== null ? [$modeVariable] : ['transaction_read_only', 'tx_read_only'] as $variable) {
            try {
                $res = $db->query('SELECT @@SESSION.' . $variable);
            } catch (\mysqli_sql_exception $e) {
                if (songRelocateIsTransactionFatal($e)) { throw $e; }   /* the shared rule, as in every catch a transaction can reach */
                if ((int)$e->getCode() === 1193) { continue; }   /* "unknown system variable": an older server's name is next */
                return null;
            }
            if (!$res instanceof \mysqli_result) {
                /* A connection with error reporting switched off answers false and sets errno instead of throwing. */
                if ((int)$db->errno === 1193) { continue; }
                return null;
            }
            $readOnly = (int)($res->fetch_row()[0] ?? 0) === 1;
            $res->free();
            $modeVariable = $variable;
            break;
        }
        if ($readOnly === null) {
            return null;
        }
        $ok = $db->query($readOnly ? 'SET TRANSACTION READ ONLY' : 'SET TRANSACTION READ WRITE');
    } catch (\Throwable $e) {
        if (songRelocateIsTransactionFatal($e)) { throw $e; }   /* the shared rule, as in every catch a transaction can reach */
        return ($e instanceof \mysqli_sql_exception && (int)$e->getCode() === 1568) ? true : null;
    }
    if ($ok === true) {
        return false;
    }
    /* A connection with error reporting switched off answers false and sets errno instead of throwing. */
    return (int)$db->errno === 1568 ? true : null;
}
