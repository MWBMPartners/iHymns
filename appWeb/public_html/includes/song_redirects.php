<?php

declare(strict_types=1);

/**
 * iHymns — Song permalink redirects (#1343)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Resolve + write tblSongRedirects so a shared permalink (/song/<SongId>) keeps
 * working after a song is merged, deleted or renamed — a 301 to the replacement,
 * or a friendly "removed" tombstone, instead of a dead 404.
 *
 * Resolution is TRANSITIVE (A->B, B->C ⇒ A resolves to C) and CYCLE-GUARDED.
 * The follow logic is a PURE function (songRedirectFollow) so it is unit-testable
 * without a DB; the DB driver (songRedirectResolve) just supplies the lookup.
 * Every reader is gated on the table existing (songRedirectsTableReady), so the
 * app works whether or not the migration has run (the #1228 STRICT-mode lesson).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Is tblSongRedirects migrated on THIS env? Probed once per request (static).
 *
 * ELI5: ask the database whether the forwarding-notes table exists yet. `$strict`
 * is for the one caller that must NOT be told "no" when the honest answer is
 * "I could not find out".
 *
 * Detail (#1679 A13b): the default (`$strict = false`) is right for a READER — a
 * redirect that cannot be looked up costs a visitor a 404, which is bad but not
 * corrupting, so the reader degrades quietly. It is WRONG for a WRITER that is
 * about to issue a permanent id: `songRelocateIdTaken()` treats "no redirect
 * table" as "no redirect claims this id", so a probe that failed for an unrelated
 * reason (permissions, a dropped connection) would silently switch the M1 claim
 * check off and let a mint re-issue an id a live redirect still forwards away
 * from — 200 OK with the WRONG SONG on an old bookmark. This is the same
 * reasoning that made `songRelocateTableExists()` (song_relocate.php) a separate,
 * non-swallowing probe rather than a reuse of this one; `$strict` closes the gap
 * for the one helper that genuinely needs both behaviours from one memo.
 *
 * A FAILED probe is deliberately NOT memoised (only a successful one is): a
 * transient failure must not disable the redirect layer for the rest of the
 * request, and a strict caller must be able to fail loudly even if a lenient
 * caller ran first.
 *
 * @param bool $strict TRUE = re-throw the probe failure instead of answering false.
 * @throws \RuntimeException only when $strict and the probe itself failed.
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html
 */
function songRedirectsTableReady(\mysqli $db, bool $strict = false): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongRedirects' LIMIT 1"
        );
        $stmt->execute();
        $ready = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $e) {
        if ($strict) {
            throw new \RuntimeException(
                'songRedirectsTableReady: could not determine whether tblSongRedirects exists — '
                . $e->getMessage(),
                0,
                $e
            );
        }
        return false;   /* NOT memoised — see the doc-block. */
    }
    return $ready;
}

/**
 * PURE transitive-follow over a redirect map (no DB) — unit-testable.
 *
 * @param callable $lookup  fn(string $id): false|null|string —
 *                          FALSE = no redirect row for $id (a live id / dead end);
 *                          NULL  = a tombstone row (removed, no replacement);
 *                          string = the NewSongId to follow.
 * @return array{redirected:bool, target:?string, tombstone:bool, cycle?:bool, maxed?:bool}
 *         redirected=false → $oldId has no redirect at all (caller treats as live/404).
 *         target=<id> → the final live SongId to 301 to. tombstone=true → render "removed".
 */
function songRedirectFollow(callable $lookup, string $oldId, int $maxHops = 16): array
{
    $cur  = $oldId;
    $seen = [];
    for ($hop = 0; $hop < $maxHops; $hop++) {
        $next = $lookup($cur);
        if ($next === false) {
            /* No redirect for $cur. If we've already followed ≥1 hop, $cur is the
               resolved live target; at hop 0 the original id simply isn't redirected. */
            return $hop === 0
                ? ['redirected' => false, 'target' => null, 'tombstone' => false]
                : ['redirected' => true,  'target' => $cur, 'tombstone' => false];
        }
        if ($next === null) {
            return ['redirected' => true, 'target' => null, 'tombstone' => true];
        }
        if (isset($seen[$next])) {
            return ['redirected' => true, 'target' => null, 'tombstone' => true, 'cycle' => true];
        }
        $seen[$cur] = true;
        $cur = (string)$next;
    }
    return ['redirected' => true, 'target' => null, 'tombstone' => true, 'maxed' => true];
}

/**
 * The one-hop lookup `songRedirectFollow()` needs, bound to a live connection.
 *
 * ELI5: "does the forwarding-notes table say anything about this id?" — hands
 * back a little function that answers that question one id at a time.
 *
 * Extracted (#1689) so the DB-backed callers share ONE spelling of the query.
 * It was previously a closure inside `songRedirectResolve()`, which was fine
 * while that was the only caller; a second caller would have meant two copies of
 * a query whose result shape is load-bearing — `false` and `null` mean genuinely
 * different things here and a divergent copy would be a silent behaviour change
 * (rule #35: cross-file agreement needs a mechanism, not a comment).
 *
 * THE THREE-VALUED RETURN IS THE CONTRACT, and it is why this cannot be
 * simplified to a nullable string:
 *   - `false`  — no row at all; this id has never been redirected.
 *   - `null`   — a TOMBSTONE row: the song was removed with no replacement.
 *   - string   — the id it forwards to.
 *
 * @param  \mysqli $db Live connection from getDbMysqli().
 * @return callable(string):(string|null|false)
 */
function songRedirectLookup(\mysqli $db): callable
{
    return static function (string $id) use ($db) {
        $st = $db->prepare('SELECT NewSongId FROM tblSongRedirects WHERE OldSongId = ? LIMIT 1');
        $st->bind_param('s', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row === null) { return false; }       // no row
        return $row['NewSongId'];                    // null (tombstone) or string
    };
}

/**
 * Does a redirect CLAIM this id, whatever it forwards to? — PURE.
 *
 * ELI5: "has this id been spoken for?" — true whether it points somewhere new or
 * just says the song is gone.
 *
 * Pure (the lookup is injected) so `tests/php/test-song-redirect-claim.php` can
 * CALL it across all four shapes instead of grepping a file for the right words.
 * That is not tidiness: four consecutive rounds on this branch shipped a guard
 * that was green while the thing it guarded was broken, every one of them because
 * source inspection was used as evidence for a property that had a runtime handle
 * (`tests/php/test-transaction-fatal.php` documents the regress).
 *
 * A TOMBSTONE COUNTS AS A CLAIM, and that is the whole subtlety. "This song was
 * removed" is every bit as definite a statement as "it moved to X" — arguably
 * more so — and the caller this exists for (#1689) uses it to suppress a
 * heuristic. Answering `false` for a tombstone would let the heuristic resurrect
 * a DIFFERENT song in the removed one's place, which is the exact outcome the
 * check is there to prevent. A cycle and a hop-limit exhaustion are reported by
 * `songRedirectFollow()` as `redirected` too, and are likewise claims: a
 * malformed redirect chain is still a statement that this id is not free.
 *
 * @param callable(string):(string|null|false) $lookup One-hop lookup.
 * @param string                               $songId The id being asked about.
 * @return bool TRUE when the redirect layer has something to say about $songId.
 */
function songRedirectClaims(callable $lookup, string $songId): bool
{
    if ($songId === '') { return false; }
    return (bool)(songRedirectFollow($lookup, $songId)['redirected'] ?? false);
}

/**
 * DB-backed "is this id spoken for?" — the driver for `songRedirectClaims()`.
 *
 * ELI5: same question as above, but it goes and asks the database.
 *
 * FAILS OPEN, DELIBERATELY, and this is the opposite call from the one
 * `songRelocateIdTaken()` makes eight functions up — so the reasoning is worth
 * stating rather than leaving to be re-derived. That helper is about to MINT a
 * permanent id, so "I could not check" must never read as "nothing claims it"
 * and it uses the strict probe. This one merely decides whether a READ may fall
 * back to a number match, so an un-migrated install (where no redirect can
 * exist) and a transient probe failure both correctly degrade to the behaviour
 * that shipped before #1689 rather than turning a working page into a 404.
 *
 * @param \mysqli $db     Live connection from getDbMysqli().
 * @param string  $songId The id being asked about.
 * @return bool TRUE when a redirect row claims $songId.
 */
function songRedirectClaimsId(\mysqli $db, string $songId): bool
{
    if ($songId === '' || !songRedirectsTableReady($db)) { return false; }
    return songRedirectClaims(songRedirectLookup($db), $songId);
}

/** DB-backed transitive resolve for one old SongId. */
function songRedirectResolve(\mysqli $db, string $oldId): array
{
    if ($oldId === '' || !songRedirectsTableReady($db)) {
        return ['redirected' => false, 'target' => null, 'tombstone' => false];
    }
    return songRedirectFollow(songRedirectLookup($db), $oldId);
}

/**
 * Upsert a redirect: OldSongId -> NewSongId (or NULL for a tombstone). Caller MUST
 * ensure $newId exists in tblSongs or is null (NewSongId is an FK). No-ops if the
 * table isn't migrated, or for an empty / self-referential redirect.
 */
function songRedirectWrite(\mysqli $db, string $oldId, ?string $newId, string $reason, ?int $userId, string $note = ''): bool
{
    if ($oldId === '' || $oldId === $newId || !songRedirectsTableReady($db)) { return false; }
    $stmt = $db->prepare(
        'INSERT INTO tblSongRedirects (OldSongId, NewSongId, Reason, Note, CreatedBy)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE NewSongId = VALUES(NewSongId), Reason = VALUES(Reason),
                                 Note = VALUES(Note), CreatedBy = VALUES(CreatedBy)'
    );
    $stmt->bind_param('ssssi', $oldId, $newId, $reason, $note, $userId);
    $stmt->execute();
    $stmt->close();
    return true;
}

/**
 * Repoint every redirect that currently targets $from so it targets $to instead
 * (used by merge: before deleting the duplicate, forward its inbound redirects to
 * the survivor so chains survive). No-op if the table isn't migrated.
 */
function songRedirectRepoint(\mysqli $db, string $from, string $to): void
{
    if ($from === '' || $to === '' || $from === $to || !songRedirectsTableReady($db)) { return; }
    $stmt = $db->prepare('UPDATE tblSongRedirects SET NewSongId = ? WHERE NewSongId = ?');
    $stmt->bind_param('ss', $to, $from);
    $stmt->execute();
    $stmt->close();
}
