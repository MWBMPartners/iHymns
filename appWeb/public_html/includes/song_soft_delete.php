<?php

declare(strict_types=1);

/**
 * iHymns — Song soft delete (#1694, stage 2 of epic #1692)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The ONE shared unit behind "a deleted song is HIDDEN, not gone". 38 of the 41
 * FKs referencing tblSongs(SongId) are ON DELETE CASCADE, so a hard delete
 * destroys components, lyric lines, credits, media links, tags, external
 * links, work membership and the entire revision history in one irreversible
 * stroke — restore must be PREVENTION, not repair. On the soft path the row
 * stays and every read filters it out through the fragments this file mints.
 *
 * Shaped like song_redirects.php: pure decision cores (state in, verdict out)
 * so tests CALL them, thin DB drivers around them, and every reader gated on
 * the migration having run (the #1228 STRICT-mode lesson — the three docroots
 * share ONE MySQL and migrations are web-run, never auto-applied).
 *
 * THE '1=1' DEGRADE IS THE WHOLE TRICK for hand-built SQL: call sites embed
 * songVisibleSql() unconditionally after WHERE/AND, so the statement stays
 * valid on an un-migrated install with zero per-site branching — and no leak
 * is possible there, because deleted songs cannot exist before the columns do
 * (the same argument userOwnerState() makes for account status).
 *
 * WRITES REFUSE ON AN UN-MIGRATED INSTALL — a refusal, NEVER a fallback to the
 * old hard delete, which would quietly defeat the entire feature (the same
 * stance accountErase() takes for #1698). HTTP status is the contract the
 * verdicts carry (rule #35): 409 un-migrated / wrong state, 422 bad
 * vocabulary, 404 absent — endpoints relay the status, never the prose.
 *
 * SOFT DELETE TOUCHES tblSongRedirects NOT AT ALL — load-bearing (#1694 plan
 * §4). If it wrote the rows today's hard delete writes, restore would have to
 * un-write them, and a songRedirectRepoint() CANNOT be un-written: after A→B
 * (move) then B deleted-with-relink-to-C, the repoint rewrites A→C before the
 * delete, and restoring B has no record that A ever pointed at it. Writing
 * nothing makes delete→restore a provable no-op on redirect state; the
 * existing, well-tested redirect dance runs at PURGE, unchanged.
 *
 * @see appWeb/.sql/migrate-song-soft-delete.php   the schema this gates on
 * @see appWeb/public_html/includes/song_redirects.php   the module shape copied
 * @see appWeb/public_html/includes/account_lifecycle.php  the refuse-not-fallback precedent
 * @see tests/php/test-song-soft-delete.php        the behavioural lock
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sql_identifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_public_id.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'webhooks.php';   /* #1909 — webhookEmitSongEvent(), fired post-commit from the core (dormant no-op) */

/**
 * The complete DeletedReason vocabulary — key => human label.
 *
 * ELI5: the reasons a curator can pick when deleting a song, and what to call
 * each on screen.
 *
 * VARCHAR + this map, never an ENUM (rule #20): adding a reason later is ONE
 * line here, whereas an ENUM value-add is an ALTER — the second migration
 * rule #20 exists to forbid. The stored value is the KEY (≤50 chars, fits
 * tblSongs.DeletedReason); NULL means "no reason given" and is always valid.
 *
 * A FUNCTION rather than a `const` so this file stays double-include-safe: a
 * bare file-scope `const` fatals on a second `require`, and this module is
 * reached from api.php, the editor APIs, /manage/* pages and the CLI test —
 * the userStatusVocabulary() precedent, for the same reason.
 *
 * @return array<string,string>
 */
function songDeleteReasons(): array
{
    return [
        'duplicate'  => 'Duplicate of another song',
        'copyright'  => 'Copyright / licensing concern',
        'data-error' => 'Bad or unusable data',
        'requested'  => 'Removal requested',
        'other'      => 'Other',
    ];
}

/**
 * Is this reason a value tblSongs.DeletedReason may hold? — PURE.
 *
 * ELI5: NULL or blank is fine (no reason given); anything else must be one of
 * the known reasons.
 *
 * App-level allow-list validation (rule #20's other half): the column is a
 * plain VARCHAR, so THIS is the only thing standing between the vocabulary
 * and free-text drift.
 */
function songDeleteReasonValid(?string $reason): bool
{
    if ($reason === null || trim($reason) === '') {
        return true;   /* stored as NULL — the reason is optional */
    }
    return array_key_exists(trim($reason), songDeleteReasons());
}

/**
 * Are ALL FIVE soft-delete columns present on THIS install?
 *
 * ELI5: check the new columns exist before anything selects or writes them.
 *
 * Written to read identically to `userStatusColumnReady()` /
 * `setlistSlotsColumnReady()` — the house pattern for an optional column —
 * because gates doing the same job should be recognisable as the same job.
 * The one necessary difference: this migration adds FIVE columns as five
 * separately-guarded ALTERs, so a dropped connection can leave a partial
 * apply; requiring the full set IN LOCKSTEP (COUNT over an IN-list = 5, the
 * lyricLinesMirrorPresent() shape) means a half-migrated install stays on
 * today's behaviour instead of throwing on the columns that never landed.
 *
 * mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
 * (`includes/db_mysql.php`), so selecting a column that is not there THROWS
 * rather than returning false. An ungated read would white-screen every song
 * surface on an un-migrated docroot — the #1228 class exactly.
 *
 * Memoised per request, SUCCESS ONLY: the answer cannot change mid-request
 * and many callers ask; a probe FAILURE throws out before the memo is
 * assigned, so a transient failure never pins the gate for the rest of the
 * request (the songRedirectsTableReady() reasoning). Readers that must not
 * throw wrap their whole consult — see songSoftDeletedHolds().
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool True when all five columns exist.
 */
function songSoftDeleteReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
            AND COLUMN_NAME IN ('IsDeleted', 'DeletedAt', 'DeletedBy', 'DeletedReason', 'DeleteNote')"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $ready = ($row !== null && (int)$row[0] === 5);
    return $ready;
}

/**
 * The visibility predicate a hand-built SELECT embeds — PURE.
 *
 * ELI5: the little "and it isn't deleted" clause, or a harmless "1=1" when the
 * columns don't exist yet.
 *
 * Call sites write `WHERE … AND ` . songVisibleSql($db) unconditionally: on a
 * migrated install that is `s.IsDeleted = 0`; on an un-migrated one it is
 * `1=1`, so the SQL stays valid with zero per-site branching and behaves
 * byte-identically to today (no deleted song can exist before the column
 * does).
 *
 * THE ALIAS IS VALIDATED, NEVER TRUSTED, because this string is interpolated
 * into SQL (rule #5 clause b). The shared identifier gate
 * (`ihymnsSqlIdentifierSafe()`, sql_identifier.php) supplies the "safe to
 * paste" property; on top of it this narrows to `[A-Za-z_][A-Za-z0-9_]*` —
 * no leading digit, no `$` — because every alias in this codebase is a plain
 * letter or word and refusing odd-but-legal shapes costs nothing. `\z`, not
 * `$`: PCRE's `$` also matches before a trailing newline, and "the value I
 * validated is the value I interpolated" is the entire property claimed
 * (the sql_identifier.php lesson, caught by its own first test run). A
 * hostile alias THROWS in BOTH branches — a caller bug must not be masked by
 * migration state.
 *
 * @param bool   $ready songSoftDeleteReady()'s answer (injected so tests can CALL both branches).
 * @param string $alias Table alias to prefix, or '' for the bare column.
 * @return string 's.IsDeleted = 0'-shaped predicate, or '1=1'.
 * @throws \InvalidArgumentException on an alias that fails validation.
 */
function songVisibleSqlFor(bool $ready, string $alias = 's'): string
{
    if ($alias !== ''
        && !(ihymnsSqlIdentifierSafe($alias) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) === 1)) {
        throw new \InvalidArgumentException(
            'songVisibleSqlFor(): refusing unsafe SQL alias ' . var_export($alias, true)
        );
    }
    if (!$ready) {
        return '1=1';
    }
    return $alias === '' ? 'IsDeleted = 0' : $alias . '.IsDeleted = 0';
}

/**
 * DB-bound driver for songVisibleSqlFor() — what call sites actually embed.
 *
 * ELI5: same as above, but it asks the database whether the columns exist.
 *
 * Deliberately does NOT swallow a probe failure: if INFORMATION_SCHEMA cannot
 * be read the song query two lines later fails identically, and the page's
 * existing error handling (themed card / 503) is the right responder — a
 * silent '1=1' on a half-broken connection would be a guess.
 */
function songVisibleSql(\mysqli $db, string $alias = 's'): string
{
    return songVisibleSqlFor(songSoftDeleteReady($db), $alias);
}

/**
 * Is $id (SongId OR PublicId) a soft-deleted song? — gated, FAILS OPEN.
 *
 * ELI5: "is this one of the hidden ones?" — and if we cannot find out, say no.
 *
 * Powers the two wrong-content traps in getSongById() (#1694 commit 3): it
 * suppresses the number fallback so a soft-deleted CP-0412 cannot resurface as
 * whatever now holds number 412 (the #1689 class), and it upgrades the public
 * 404 to an honest 410. Both callers reach here only AFTER a filtered read
 * already missed, so this costs nothing on the hot path.
 *
 * FAILS OPEN (false), deliberately — the songRedirectClaimsId() reasoning
 * verbatim: this answer only suppresses a heuristic and upgrades a 404 to a
 * 410, so an un-migrated install (where no soft-deleted song can exist) and a
 * transient probe failure must both degrade to today's behaviour rather than
 * turn working pages into 404s. Contrast the write cores below, which REFUSE
 * on the same uncertainty because they are about to change state.
 *
 * BOTH ID SHAPES are considered: a share link may carry the opaque PublicId
 * (#1343-B), so the id is first run through songPublicId_resolveToSongId() —
 * itself gated on ITS column existing, and deliberately unfiltered (a deleted
 * song's PublicId must still resolve, or this function could never see it).
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @param string  $id SongId ('MP-1008') or PublicId ('ABCDEFGHJK').
 * @return bool TRUE only when the row exists and is soft-deleted.
 */
function songSoftDeletedHolds(\mysqli $db, string $id): bool
{
    $id = trim($id);
    if ($id === '') {
        return false;   /* a caller bug; answering "deleted" would be un-debuggable */
    }
    try {
        if (!songSoftDeleteReady($db)) {
            return false;   /* un-migrated: no soft-deleted song can exist */
        }
        /* PublicId → SongId when it looks like one; any other id passes
           through unchanged (the resolver's contract).
           @disabled-visible: this reads ONLY the IsDeleted column of a
           specific, already-identified row to answer "is it soft-deleted?"
           (#1765) — a disabled songbook has no bearing on that question,
           and the row must be found regardless of its book's state or a
           soft-deleted-but-disabled-book song could never be recognised. */
        $songId = songPublicId_resolveToSongId($db, $id);
        $stmt = $db->prepare('SELECT IsDeleted FROM tblSongs WHERE SongId = ? LIMIT 1');
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null && (int)$row['IsDeleted'] === 1;
    } catch (\Throwable $e) {
        return false;   /* FAILS OPEN — see the doc-block */
    }
}

/**
 * The ONE lifecycle verdict — PURE (state in, verdict out).
 *
 * ELI5: given what we know (is the migration in? does the row exist? is it
 * already deleted?), decide whether this delete / restore / purge may go
 * ahead, and if not, why not.
 *
 * Every refusal all three write cores can issue is decided HERE, so the test
 * CALLS this across every state instead of grepping the drivers for the right
 * words (the userStateFromRow() lesson: a mutant that changed a driver's
 * refusal survived every DB-shaped assertion, because an unconnected mysqli
 * throws long before the decision line is reached).
 *
 * CHECKING ORDER IS PART OF THE CONTRACT — each check assumes everything
 * above it passed, which is what lets the drivers call this with honestly
 * partial state (a driver that stopped at the migration gate passes
 * $isDeleted = null, and the un-migrated clause fires before the absent-row
 * clause can misread that null as a 404):
 *   1. empty id            → 400  (caller bug)
 *   2. un-migrated         → 409  (REFUSE — never fall back to a hard delete)
 *   3. unknown reason      → 422  (vocabulary violation; delete only)
 *   4. row absent          → 404
 *   5. wrong current state → 409  (delete: already deleted; restore/purge: not deleted)
 *
 * The HTTP status IS the machine contract (rule #35): endpoints relay
 * `status` and clients branch on it — the prose is for humans only.
 *
 * @param string      $op        'delete' | 'restore' | 'purge'
 * @param string      $songId    Trimmed id the driver was asked about.
 * @param bool        $ready     songSoftDeleteReady()'s answer (false when not consulted).
 * @param int|null    $isDeleted Row state: null = absent / not fetched, 0 = live, 1 = deleted.
 * @param string|null $reason    Delete reason (delete only; ignored for restore/purge).
 * @return array{ok:bool, status:int, error:string}
 */
function songSoftDeleteVerdict(string $op, string $songId, bool $ready, ?int $isDeleted, ?string $reason = null): array
{
    if (!in_array($op, ['delete', 'restore', 'purge'], true)) {
        /* A driver bug, not a request state — refuse loudly rather than guess. */
        throw new \InvalidArgumentException("songSoftDeleteVerdict(): unknown op '{$op}'");
    }
    if ($songId === '') {
        return ['ok' => false, 'status' => 400, 'error' => 'A song id is required.'];
    }
    if (!$ready) {
        return ['ok' => false, 'status' => 409, 'error' =>
            'Song soft delete is unavailable until the "Song soft delete (#1694)" migration has been '
            . 'applied at /manage/setup-database. Refusing rather than falling back to a permanent '
            . 'hard delete.'];
    }
    if ($op === 'delete' && !songDeleteReasonValid($reason)) {
        return ['ok' => false, 'status' => 422, 'error' =>
            'Unknown delete reason ' . var_export((string)$reason, true)
            . ' — not in songDeleteReasons().'];
    }
    if ($isDeleted === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'Song not found.'];
    }
    if ($op === 'delete' && $isDeleted === 1) {
        return ['ok' => false, 'status' => 409, 'error' => 'Song is already deleted.'];
    }
    if ($op === 'restore' && $isDeleted === 0) {
        return ['ok' => false, 'status' => 409, 'error' => 'Song is not deleted — nothing to restore.'];
    }
    if ($op === 'purge' && $isDeleted === 0) {
        return ['ok' => false, 'status' => 409, 'error' =>
            'A song must be soft-deleted before it can be purged (purge is reachable only from the '
            . 'deleted state — two-step by construction).'];
    }
    return ['ok' => true, 'status' => 200, 'error' => ''];
}

/**
 * Fetch one row's IsDeleted state (+ its audit snapshot), locked for this
 * transaction.
 *
 * ELI5: read the song's deleted-flag — plus its title and songbook, because
 * the caller is about to change or destroy the row and wants to write down
 * what it was — and hold onto the row so nobody else changes it under us.
 *
 * FOR UPDATE closes the check-then-act race: a plain SELECT inside REPEATABLE
 * READ reads a snapshot, so two concurrent deletes could both see "live" and
 * both believe they performed the delete. The lock makes the verdict's row
 * state the row's CURRENT state until commit. Caller must hold an open
 * transaction and have verified songSoftDeleteReady().
 *
 * WHY THREE COLUMNS, NOT ONE (#1694 commit 4): the endpoints' logActivity
 * rows record title + songbook (the hard-delete body always snapshotted them
 * before the row vanished), and the SongCount recompute needs the songbook.
 * Widening THIS read hands all three facts to every write core from the ONE
 * locked row, instead of a second unlocked SELECT that could disagree with
 * the row the lock is holding.
 *
 * @return array{isDeleted:int, songbookAbbr:string, title:string}|null
 *         null = no such row.
 */
function _songSoftDeleteRowState(\mysqli $db, string $songId): ?array
{
    /* @disabled-visible: admin write-path row lock (#1765) — delete/restore/
       purge must reach a song regardless of its songbook's disabled state
       (a curator can delete a song inside a disabled book, or restore one). */
    $stmt = $db->prepare('SELECT IsDeleted, SongbookAbbr, Title FROM tblSongs WHERE SongId = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row === null) {
        return null;
    }
    return [
        'isDeleted'    => (int)$row['IsDeleted'],
        'songbookAbbr' => (string)($row['SongbookAbbr'] ?? ''),
        'title'        => (string)($row['Title'] ?? ''),
    ];
}

/**
 * Recompute one songbook's cached SongCount under the D1 predicate —
 * best-effort, transaction-fatal-aware.
 *
 * ELI5: after hiding, un-hiding or destroying a song, refresh the number on
 * that songbook's home tile. If the refresh itself hiccups, the delete still
 * stands — unless the hiccup was the kind that already destroyed the whole
 * transaction, in which case we must NOT pretend anything succeeded.
 *
 * WHY THE WRITE CORES DO THIS AT ALL (#1694 commit 4): the live database has
 * NO SongCount triggers (owner-run probe, commit f92964e3) — the app-side
 * recomputes are not a fallback for trigger-denied hosts, they ARE the
 * mechanism. Every other funnel that changes a book's visible-song population
 * (save, import, relocate) recomputes; the soft-delete lifecycle changes that
 * population too, and without this the owner's D1 decision ("SongCount counts
 * visible songs") simply would not happen on a delete. On a host that DOES
 * have the predicate-aware triggers, the trigger and this recompute write the
 * same number — idempotent, never a conflict.
 *
 * BEST-EFFORT WITH THE ONE FATALITY LIST (the songRelocate() §7 pattern,
 * #1679 F8/A1): a cosmetic failure (an ancient install missing the SongCount
 * column, a permission oddity) must not roll back the lifecycle write — the
 * count self-heals on the next pass. But 1213/1205-class errors have already
 * rolled back the ENTIRE transaction, including the lifecycle write; swallow
 * one of those and the caller commits nothing while answering ok=true — the
 * false-success class. songRelocateIsTransactionFatal() is the ONE shared
 * list (rule #35), loaded lazily to keep this module light for read-only
 * consumers.
 *
 * @param \mysqli $db   Live connection; caller holds an open transaction.
 * @param string  $abbr The songbook whose count moved ('' = nothing to do).
 */
function _songSoftDeleteRecountSongbook(\mysqli $db, string $abbr): void
{
    if ($abbr === '') {
        return;
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_relocate.php';   /* songRelocateIsTransactionFatal() */
    try {
        /* @disabled-visible: SongCount recompute family (#1765) — the plan's
           adversarial notes are explicit that SongCount deliberately does
           NOT change when a book is disabled (disabling hides the book from
           listings; it does not shrink the number on the tile), so this
           recompute must count every non-soft-deleted song regardless of the
           book's OWN disabled state — folding songServableSql() in here
           would make SongCount and the disable toggle disagree the moment
           either one moves. */
        $stmt = $db->prepare(
            /* #1694 D1 — SongCount counts VISIBLE songs. songVisibleSql() is
               already settled 'IsDeleted = 0' here (the write cores gate on
               songSoftDeleteReady() before any of them reach this). */
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ? AND ' . songVisibleSql($db, '') . ')
              WHERE Abbreviation = ?'
        );
        $stmt->bind_param('ss', $abbr, $abbr);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        if (songRelocateIsTransactionFatal($e)) {
            throw $e;
        }
        error_log('[songSoftDelete] SongCount recompute failed for ' . $abbr . ': ' . $e->getMessage());
    }
}

/**
 * Soft-delete one song: ONE UPDATE in a transaction. Never a DELETE, never a
 * redirect write.
 *
 * ELI5: mark the song hidden and note who/when/why. Everything about the song
 * stays exactly where it is.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO (each a load-bearing absence):
 *  - No `DELETE` of any kind — the CASCADE fan-out is the disaster this
 *    feature exists to prevent (file doc-block).
 *  - No tblSongRedirects write and no songRedirectRepoint() — a repoint
 *    cannot be un-written, so restore could not be a no-op on redirect state
 *    (file doc-block; the #1679 chain-strand class). Redirects are purge's
 *    business.
 *  - No fallback on an un-migrated install — the verdict REFUSES with 409.
 *
 * @param \mysqli     $db     Live connection from getDbMysqli().
 * @param string      $songId The song to hide.
 * @param int|null    $userId Acting user for DeletedBy (null = unattributed).
 * @param string|null $reason A songDeleteReasons() key, or null/'' for none.
 * @param string      $note   Free-text note (clamped to the column's 255).
 * @return array{ok:bool, status:int, error:string, title?:string, songbook?:string}
 *         the verdict (status 200 on success, with the audit snapshot the
 *         endpoints' logActivity rows record).
 */
function songSoftDelete(\mysqli $db, string $songId, ?int $userId, ?string $reason = null, string $note = ''): array
{
    $songId = trim($songId);
    /* Pre-row gates, cheapest first; each early return re-derives its refusal
       from the ONE pure verdict with exactly the state gathered so far (the
       ordering contract in its doc-block makes the partial state honest). The
       empty-id case never touches the DB at all — the songRedirectClaims()
       "costs no lookup" discipline. */
    if ($songId === '') {
        return songSoftDeleteVerdict('delete', '', false, null, $reason);
    }
    if (!songSoftDeleteReady($db)) {
        return songSoftDeleteVerdict('delete', $songId, false, null, $reason);
    }
    if (!songDeleteReasonValid($reason)) {
        return songSoftDeleteVerdict('delete', $songId, true, null, $reason);
    }

    $db->begin_transaction();
    try {
        $state   = _songSoftDeleteRowState($db, $songId);
        $verdict = songSoftDeleteVerdict('delete', $songId, true, $state['isDeleted'] ?? null, $reason);
        if (!$verdict['ok']) {
            $db->rollback();
            return $verdict;
        }
        /* UTC_TIMESTAMP(), not NOW(): DeletedAt is a DATETIME holding a UTC
           instant (rule #20), independent of the session time zone. The
           IsDeleted = 0 predicate is belt-and-braces under the FOR UPDATE
           lock. The stored reason is NULL for "none", never ''. */
        $storedReason = ($reason === null || trim($reason) === '') ? null : trim($reason);
        $storedNote   = mb_substr(trim($note), 0, 255);
        $stmt = $db->prepare(
            'UPDATE tblSongs
                SET IsDeleted = 1, DeletedAt = UTC_TIMESTAMP(), DeletedBy = ?,
                    DeletedReason = ?, DeleteNote = ?
              WHERE SongId = ? AND IsDeleted = 0'
        );
        $stmt->bind_param('isss', $userId, $storedReason, $storedNote, $songId);
        $stmt->execute();
        $stmt->close();
        /* The song just left its book's VISIBLE population (D1) — refresh the
           cached tile count. Inside the transaction so a rollback rolls it
           back too; see the helper for the trigger-less-host reasoning. */
        _songSoftDeleteRecountSongbook($db, (string)($state['songbookAbbr'] ?? ''));
        $db->commit();
        /* #1695 — AFTER the commit, never inside it. The notification is the
           safeguard that makes curator-level deletion defensible, but it must
           not be able to roll back or fail a delete that has already
           succeeded; songSoftDeleteNotify() swallows everything for that
           reason. Fired from the core so no present or future funnel can
           forget it. */
        songSoftDeleteNotify($db, 'deleted', $songId,
            (string)($state['title'] ?? ''), $userId);
        /* #1909 — partner webhook, post-commit like the notify above and for the
           same reason: it must never roll back or fail a delete that has already
           succeeded (webhookEmitSongEvent swallows everything, dormant no-op).
           Fired from the core so every delete funnel (editor api/api2, bulk) gets
           it (rule #22). */
        webhookEmitSongEvent($db, 'song.deleted', $songId,
            ['title' => (string)($state['title'] ?? ''), 'songbook_abbr' => (string)($state['songbookAbbr'] ?? '')],
            ['source' => 'soft_delete', 'actor_user_id' => $userId]);
        return $verdict + [
            'title'    => (string)($state['title'] ?? ''),
            'songbook' => (string)($state['songbookAbbr'] ?? ''),
        ];
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;   /* a failed write is FATAL to the caller, never swallowed */
    }
}

/**
 * Restore one soft-deleted song: ONE UPDATE clearing all five columns.
 *
 * ELI5: un-hide the song and wipe the who/when/why bookkeeping.
 *
 * Because songSoftDelete() wrote nothing anywhere else, this UPDATE is the
 * ENTIRE restore — favourites, work membership, revisions and redirect chains
 * were never touched, so they need no repair (the plan §4 argument). DeleteNote
 * clears to '' (the column is NOT NULL), the other three to NULL.
 *
 * @param \mysqli  $db     Live connection from getDbMysqli().
 * @param string   $songId The song to bring back.
 * @param int|null $userId Acting user (endpoint layers log it; the row keeps no restorer column — WHERE IsDeleted = 1 is the queue, and a restored row leaves it).
 * @return array{ok:bool, status:int, error:string, title?:string, songbook?:string}
 *         the verdict (status 200 on success, with the audit snapshot).
 */
function songRestore(\mysqli $db, string $songId, ?int $userId): array
{
    $songId = trim($songId);
    if ($songId === '') {
        return songSoftDeleteVerdict('restore', '', false, null);
    }
    if (!songSoftDeleteReady($db)) {
        return songSoftDeleteVerdict('restore', $songId, false, null);
    }

    $db->begin_transaction();
    try {
        $state   = _songSoftDeleteRowState($db, $songId);
        $verdict = songSoftDeleteVerdict('restore', $songId, true, $state['isDeleted'] ?? null);
        if (!$verdict['ok']) {
            $db->rollback();
            return $verdict;
        }
        $stmt = $db->prepare(
            "UPDATE tblSongs
                SET IsDeleted = 0, DeletedAt = NULL, DeletedBy = NULL,
                    DeletedReason = NULL, DeleteNote = ''
              WHERE SongId = ? AND IsDeleted = 1"
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $stmt->close();
        /* The song just re-joined its book's visible population — the
           mirror-image of the delete's recompute (D1). */
        _songSoftDeleteRecountSongbook($db, (string)($state['songbookAbbr'] ?? ''));
        $db->commit();
        /* #1695 — a restore is notified too. A curator quietly undoing a
           colleague's deletion (a copyright takedown, say) is exactly as
           worth knowing about as the deletion was; notifying only one
           direction would make the queue look self-clearing. */
        songSoftDeleteNotify($db, 'restored', $songId,
            (string)($state['title'] ?? ''), $userId);
        /* #1909 — partner webhook (post-commit, dormant no-op; see the delete
           twin above for the post-commit / core-fired rationale). */
        webhookEmitSongEvent($db, 'song.restored', $songId,
            ['title' => (string)($state['title'] ?? ''), 'songbook_abbr' => (string)($state['songbookAbbr'] ?? '')],
            ['source' => 'restore', 'actor_user_id' => $userId]);
        return $verdict + [
            'title'    => (string)($state['title'] ?? ''),
            'songbook' => (string)($state['songbookAbbr'] ?? ''),
        ];
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Purge one soft-deleted song — the destructive cascade delete, moved here
 * from the two near-identical editor delete_song bodies (#1694 commit 4).
 *
 * ELI5: the real "gone forever" delete. Only a song already in Deleted songs
 * can be purged; the row, its components, credits, media links, tags and its
 * entire revision history go in one cascade, and the song's old link is left
 * pointing somewhere sensible (another song, or a friendly "removed" page).
 *
 * THIS IS THE ONE COPY of the delete dance. It previously existed TWICE,
 * near-identically (manage/editor/api.php delete_song + api2.php delete_song
 * — the modularity rule's worst case for an irreversible operation); both
 * endpoints now soft-delete, and this body is the verbatim-in-behaviour move
 * of what they used to do:
 *   1. row state + audit snapshot under FOR UPDATE (title/songbook must be
 *      captured BEFORE the row vanishes);
 *   2. resolve the optional relink target — a DIFFERENT, EXISTING song, else
 *      tombstone (the same "valid other id or null" rule as before; the
 *      existence probe is deliberately visibility-blind: relinking to a
 *      currently-hidden song is honest — it answers 410 while hidden and
 *      self-heals if that song is restored);
 *   3. capture the PublicId, GATED on songPublicId_columnReady(), so a shared
 *      /song/<PublicId> keeps resolving too (#1343-B);
 *   4. when relinking, songRedirectRepoint() FORWARDS every redirect already
 *      pointing AT this song BEFORE the delete, so the FK ON DELETE SET NULL
 *      cascade cannot strand a chain (mirrors the merge path; a tombstone
 *      deliberately lets inbound chains fall to "removed");
 *   5. ONE cascade DELETE — every inbound FK to tblSongs(SongId) is ON DELETE
 *      CASCADE (38) or SET NULL (3), verified against
 *      INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS (#1200), so no
 *      hand-ordering and no orphans;
 *   6. songRedirectWrite() for the SongId AND the PublicId (reason 'delete',
 *      unchanged — resolver behaviour and existing rows depend on it);
 *   7. the D1 SongCount recompute (the purged song was already hidden, so
 *      the visible count normally does not move — but the recompute is cheap
 *      insurance against a count that drifted while the row sat deleted).
 *
 * REQUIRES IsDeleted = 1 FIRST — purge is reachable only from the deleted
 * state (two-step by construction; the verdict's purge clause enforces it).
 * There is deliberately NO "skip the queue" flag: a caller wanting a
 * one-click hard delete must soft-delete first, which is the entire point.
 *
 * @param \mysqli     $db         Live connection from getDbMysqli().
 * @param string      $songId     The soft-deleted song to purge.
 * @param int|null    $userId     Acting user (recorded on the redirect rows;
 *                                endpoint layers write the logActivity row).
 * @param string|null $redirectTo Optional relink target for the permalink
 *                                dance; blank/self/unknown → tombstone.
 * @return array{ok:bool, status:int, error:string, deleted?:int,
 *               redirectTo?:?string, title?:string, songbook?:string}
 *         the verdict; on success `deleted` = tblSongs rows removed (1),
 *         `redirectTo` = the resolved relink target or null (tombstone).
 */
function songPurge(\mysqli $db, string $songId, ?int $userId, ?string $redirectTo = null): array
{
    $songId = trim($songId);
    if ($songId === '') {
        return songSoftDeleteVerdict('purge', '', false, null);
    }
    if (!songSoftDeleteReady($db)) {
        /* Un-migrated there IS no deleted state to purge from — and answering
           anything else would offer a hard delete on an install that cannot
           soft-delete, i.e. exactly the fallback this module refuses. */
        return songSoftDeleteVerdict('purge', $songId, false, null);
    }

    /* The redirect layer (repoint + tombstone writes) — lazy, matching how the
       old endpoint bodies loaded it, so read-only consumers of this module
       never pay for it. song_public_id.php is already a top-of-file require. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_redirects.php';

    $db->begin_transaction();
    try {
        $state   = _songSoftDeleteRowState($db, $songId);   /* FOR UPDATE — snapshot + lock */
        $verdict = songSoftDeleteVerdict('purge', $songId, true, $state['isDeleted'] ?? null);
        if (!$verdict['ok']) {
            $db->rollback();
            return $verdict;
        }

        /* Resolve the relink target: a DIFFERENT, EXISTING song, else null =
           tombstone (verbatim rule from both old bodies). */
        $target = null;
        $redirectTo = trim((string)$redirectTo);
        if ($redirectTo !== '' && $redirectTo !== $songId) {
            /* @disabled-visible: relink-target existence probe (#1765, one
               predicate over the #1694 "deliberately visibility-blind"
               reasoning in this function's own doc-block above) — relinking
               to a song in a currently-disabled songbook is equally honest:
               it answers 410 while disabled and self-heals if that book is
               re-enabled. */
            $chk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
            $chk->bind_param('s', $redirectTo);
            $chk->execute();
            if ($chk->get_result()->fetch_row() !== null) {
                $target = $redirectTo;
            }
            $chk->close();
        }

        /* Capture the PublicId BEFORE the delete so a shared /song/<PublicId>
           resolves afterward (#1343-B). Gated — an un-migrated PublicId env
           simply has no opaque permalink to preserve. */
        $pubId = '';
        if (songPublicId_columnReady($db)) {
            $pp = $db->prepare('SELECT PublicId FROM tblSongs WHERE SongId = ? LIMIT 1');
            $pp->bind_param('s', $songId);
            $pp->execute();
            $pubId = (string)($pp->get_result()->fetch_assoc()['PublicId'] ?? '');
            $pp->close();
        }

        /* Keep permalinks alive (#1343): when relinking, forward inbound
           redirects to the new target BEFORE the delete — the FK ON DELETE
           SET NULL cascade would otherwise strand the chain. */
        if ($target !== null) {
            songRedirectRepoint($db, $songId, $target);
        }

        /* The single cascade delete — see the doc-block. */
        $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
        $del->bind_param('s', $songId);
        $del->execute();
        $deleted = (int)$del->affected_rows;
        $del->close();

        /* Reason stays 'delete' (never 'purge'): the resolver and every row
           the old endpoints already wrote use it, and renaming the vocabulary
           mid-stream would split one meaning across two spellings. */
        songRedirectWrite($db, $songId, $target, 'delete', $userId);
        if ($pubId !== '') {
            songRedirectWrite($db, $pubId, $target, 'delete', $userId);
        }

        /* D1 recompute — normally a no-op (the row was hidden, so the visible
           count already excluded it), kept for drift insurance. */
        _songSoftDeleteRecountSongbook($db, (string)($state['songbookAbbr'] ?? ''));

        $db->commit();
        return $verdict + [
            'deleted'    => $deleted,
            'redirectTo' => $target,
            'title'      => (string)($state['title'] ?? ''),
            'songbook'   => (string)($state['songbookAbbr'] ?? ''),
        ];
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;   /* a failed purge is FATAL to the caller, never a fake success */
    }
}

/**
 * Who should be told that a song was deleted or restored?
 *
 * ELI5: the people who can permanently destroy a song are the people who need
 * to know one is sitting in the bin.
 *
 * PURE — takes the roles that hold the entitlement and the candidate users, and
 * returns the ids to notify. No database, no globals, so the rule that decides
 * who gets told is testable on its own.
 *
 * The actor is EXCLUDED: telling somebody about their own action is noise, and
 * noise is how a safety notification gets muted. If the actor is the only
 * eligible recipient the result is empty, which is correct — a lone
 * administrator deleting a song does not need to be informed of it.
 *
 * @param string[]                                  $reviewerRoles roles holding purge_songs
 * @param list<array{id:int, role:string}>          $users         candidate users
 * @param int|null                                  $actorId       whoever acted
 * @return list<int> user ids to notify, in input order, no duplicates
 */
function songSoftDeleteNotifyTargets(array $reviewerRoles, array $users, ?int $actorId): array
{
    $roles = array_flip(array_values(array_filter($reviewerRoles, 'is_string')));
    $out   = [];
    foreach ($users as $u) {
        $id   = (int)($u['id'] ?? 0);
        $role = (string)($u['role'] ?? '');
        if ($id <= 0 || !isset($roles[$role])) { continue; }
        if ($actorId !== null && $id === $actorId) { continue; }
        $out[$id] = true;
    }
    return array_map('intval', array_keys($out));
}

/**
 * Tell every `purge_songs` holder that a song changed deleted-state.
 *
 * ELI5: send the "a song was deleted" message to the people who review deletions.
 *
 * WHY THIS LIVES IN THE WRITE CORE, NOT IN THE ENDPOINTS (#1695)
 * --------------------------------------------------------------
 * This notification is not a nicety. It is the safeguard that makes handing
 * recoverable deletion to every curator defensible — the reviewer's only signal
 * that something is waiting. A safeguard invoked by CALLERS is a safeguard that
 * a future caller forgets: #1694 found the delete cascade duplicated across two
 * endpoints, and the same shape would recur here the moment a third funnel
 * (an importer, a bulk action, an API key) grows the ability to delete.
 * Calling it from inside songSoftDelete()/songRestore() means every present and
 * future funnel gets it for free.
 *
 * BEST-EFFORT, POST-COMMIT, AND NEVER FATAL. It runs after the transaction has
 * committed and swallows everything: a notification failure must not roll back
 * or fail a delete that already succeeded, and must not turn a working editor
 * into a 500 because the notifications table is missing on an un-migrated
 * install. A missed notification is a degraded safeguard; a failed delete with
 * a confusing error is a broken feature.
 *
 * @param \mysqli  $db
 * @param string   $event  'deleted' | 'restored'
 * @param string   $songId
 * @param string   $title
 * @param int|null $actorId
 * @return int how many notifications were written (0 is a normal outcome)
 */
function songSoftDeleteNotify(\mysqli $db, string $event, string $songId, string $title, ?int $actorId): int
{
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'entitlements.php';
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'notifications.php';
        if (!function_exists('notifyUser') || !function_exists('effectiveEntitlements')) {
            return 0;
        }

        /* Roles come from the LIVE map, so an operator who re-aims purge_songs
           at /manage/entitlements changes the audience with no second list. */
        $map           = effectiveEntitlements();
        $reviewerRoles = $map['purge_songs'] ?? ['admin', 'global_admin'];

        $res = $db->query('SELECT Id, Role FROM tblUsers WHERE IsActive = 1');
        $users = [];
        while ($row = $res->fetch_assoc()) {
            $users[] = ['id' => (int)$row['Id'], 'role' => (string)$row['Role']];
        }

        $targets = songSoftDeleteNotifyTargets($reviewerRoles, $users, $actorId);
        if ($targets === []) { return 0; }

        $label   = $title !== '' ? $title : $songId;
        $isDel   = $event === 'deleted';
        $subject = $isDel ? 'Song deleted: ' . $label : 'Song restored: ' . $label;
        $body    = $isDel
            ? 'It is hidden from the app but fully recoverable. Review it on Deleted songs.'
            : 'It is visible again in the catalogue.';

        $sent = 0;
        foreach ($targets as $uid) {
            /* ONE spelling of the event, shared with the push kind in
               webPushKinds() — the cross-file agreement rule #35 is about. */
            if (notifyUser($db, $uid, 'song_' . $event, $subject, $body, '/manage/deleted-songs')) {
                $sent++;
            }
        }
        return $sent;
    } catch (\Throwable $_e) {
        /* Deliberately swallowed — see the doc block. */
        return 0;
    }
}
