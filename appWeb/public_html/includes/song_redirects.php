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

/** Is tblSongRedirects migrated on THIS env? Probed once per request (static). */
function songRedirectsTableReady(\mysqli $db): bool
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
    } catch (\Throwable $e) { $ready = false; }
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

/** DB-backed transitive resolve for one old SongId. */
function songRedirectResolve(\mysqli $db, string $oldId): array
{
    if ($oldId === '' || !songRedirectsTableReady($db)) {
        return ['redirected' => false, 'target' => null, 'tombstone' => false];
    }
    $lookup = static function (string $id) use ($db) {
        $st = $db->prepare('SELECT NewSongId FROM tblSongRedirects WHERE OldSongId = ? LIMIT 1');
        $st->bind_param('s', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row === null) { return false; }       // no row
        return $row['NewSongId'];                    // null (tombstone) or string
    };
    return songRedirectFollow($lookup, $oldId);
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
