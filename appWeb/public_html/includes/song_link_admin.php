<?php

declare(strict_types=1);

/**
 * iHymns — per-song counterpart-link write core (API-coverage Batch 5, A10,
 * `.claude/api-coverage-2026-08-28.md` §4.3/§9).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Two functions: "link song A to song B as the same hymn" and "take one
 * song out of its counterpart group". `manage/editor/api2.php`'s
 * `song_link_add`/`song_link_remove` cases already do exactly this, one
 * pair at a time — this file gives `api.php`'s new native-capable
 * `admin_song_link`/`admin_song_unlink` actions the SAME behaviour as ONE
 * shared, callable core, so a THIRD copy of this write logic is never
 * typed by hand for the API surface.
 *
 * DETAILED — WHY THIS IS A MIRROR, NOT AN EXTRACTION (read before touching)
 * ---------------------------------------------------------------------------
 * `manage/editor/api2.php` was explicitly OUT OF SCOPE for this batch (the
 * task's own constraint list: "Do NOT touch api2.php") — so unlike the
 * rest of Batch 5, this core was NOT produced by lifting api2.php's case
 * bodies out and re-pointing them here. `songLinkAdd()`/`songLinkRemove()`
 * are instead a faithful, independently-written MIRROR of api2.php's
 * `song_link_add`/`song_link_remove` behaviour as it stands today — same
 * group-merge semantics (neither grouped -> mint; one grouped -> extend;
 * same group -> no-op/note-refresh; different groups -> 409), same
 * existence+visibility probe, same transaction shape, same `'issiissi'`
 * bind-type-string fix api2.php's own comment documents (v1's
 * `'issiisis'` silently coerced a curator-typed note to `"0"`). api2.php
 * therefore still carries its OWN, textually-duplicate implementation of
 * this SQL — a KNOWN, deliberate exception to rule #22 forced by the
 * explicit scope constraint, not an oversight. A FUTURE batch that is
 * allowed to touch api2.php can re-point its two cases onto this file
 * with NO behaviour change (the shapes already match byte-for-byte at the
 * time this was written) — that re-pointing is the natural follow-up,
 * deliberately deferred here.
 *
 * `songLinkRemove()` is ALSO the exact algorithm
 * `manage/duplicate-songs.php`'s own `unlink` action always used (single
 * song, singleton-group cleanup) — that page IS in scope for this batch,
 * so its `unlink` case was re-pointed onto this function (never a fork),
 * and `api.php`'s new `admin_song_unlink` action calls the SAME function.
 *
 * NOT written here: api2.php's `song_link_suggestions` (read) and
 * `song_link_suggestion_dismiss` — neither is consumed by a new Batch-5
 * action (the plan deliberately left "dismiss" uncovered by a new
 * cluster-batch endpoint, since api2's existing per-pair dismiss already
 * serves a native caller one pair at a time), so a mirror here would add
 * surface area with no caller.
 *
 * Direct access is blocked (the same guard every other includes/*.php
 * helper carries).
 *
 * @see appWeb/public_html/manage/editor/api2.php        song_link_add / song_link_remove — the behaviour mirrored here (NOT touched/re-pointed this batch, see above)
 * @see appWeb/public_html/manage/duplicate-songs.php    unlink (re-pointed onto songLinkRemove() — this page IS in scope)
 * @see appWeb/public_html/api.php                        admin_song_link / admin_song_unlink
 * @see .claude/api-coverage-2026-08-28.md §4.3/§9 A10     the plan this implements
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php'; /* songVisibleSql() */

/**
 * iHymns — link two songs as cross-book counterparts. A faithful MIRROR of
 * `manage/editor/api2.php`'s `case 'song_link_add'` behaviour (itself a
 * port of v1 api.php's add_song_link, api.php:459) — NOT an extraction:
 * api2.php was out of scope for this batch and keeps its own copy of this
 * logic (see this file's doc-block for why).
 *
 * Group-merge semantics:
 *   - neither song grouped   -> mint a new GroupId, add both
 *   - exactly one grouped    -> add the other to it
 *   - both in the SAME group -> no-op (note refreshed if supplied)
 *   - both in DIFFERENT groups -> refuse (409 — the STATUS is the contract,
 *     rule #35, not the sentence)
 *
 * @return array{ok:bool,status?:int,error?:string,groupId?:int,noop?:bool,created?:bool,extended?:bool}
 *   On success the shape is exactly what a caller should echo back
 *   verbatim as the response body (mirrors api2's original ed2_respond()
 *   payload one-for-one).
 */
function songLinkAdd(\mysqli $db, string $srcId, string $tgtId, string $note, ?int $createdBy): array
{
    /* @disabled-visible: admin editor API (#1765) — cross-book link write;
       either song may live in a disabled songbook and still be fully
       linkable, mirroring api2.php's own case-site marker for the same
       behaviour. */
    $srcId = trim($srcId);
    $tgtId = trim($tgtId);
    $note  = trim($note);
    if ($srcId === '' || $tgtId === '') {
        return ['ok' => false, 'status' => 400, 'error' => 'sourceSongId and targetSongId are required.'];
    }
    if ($srcId === $tgtId) {
        return ['ok' => false, 'status' => 400, 'error' => 'A song cannot be linked to itself.'];
    }

    /* Validate both songs exist AND are visible before mutating anything —
       #1694-consistent: a hidden song cannot be linked to. */
    $probe = $db->prepare(
        'SELECT SongId FROM tblSongs WHERE SongId IN (?, ?) AND ' . songVisibleSql($db, '')
    );
    $probe->bind_param('ss', $srcId, $tgtId);
    $probe->execute();
    $found = [];
    $res = $probe->get_result();
    while ($r = $res->fetch_assoc()) { $found[] = $r['SongId']; }
    $probe->close();
    if (count($found) < 2) {
        return ['ok' => false, 'status' => 404, 'error' => 'One or both songs were not found.'];
    }

    $lookup = $db->prepare('SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN (?, ?)');
    $lookup->bind_param('ss', $srcId, $tgtId);
    $lookup->execute();
    $existing = [];
    $res = $lookup->get_result();
    while ($r = $res->fetch_assoc()) { $existing[$r['SongId']] = (int)$r['GroupId']; }
    $lookup->close();

    $srcGroup = $existing[$srcId] ?? 0;
    $tgtGroup = $existing[$tgtId] ?? 0;

    if ($srcGroup > 0 && $tgtGroup > 0 && $srcGroup === $tgtGroup) {
        if ($note !== '') {
            $db->begin_transaction();
            try {
                $upd = $db->prepare('UPDATE tblSongLinks SET Note = ? WHERE SongId = ?');
                $upd->bind_param('ss', $note, $tgtId);
                $upd->execute();
                $upd->close();
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }
        return ['ok' => true, 'groupId' => $srcGroup, 'noop' => true];
    }
    if ($srcGroup > 0 && $tgtGroup > 0) {
        /* Different groups. The 409 status IS the contract (rule #35). */
        return ['ok' => false, 'status' => 409, 'error' =>
            'Both songs are already in different counterpart groups. Unlink one before linking, or use the merge tool.'];
    }

    $db->begin_transaction();
    try {
        if ($srcGroup === 0 && $tgtGroup === 0) {
            /* Neither grouped — mint a new GroupId. Wrapped in the
               transaction so two concurrent first-links can't race onto the
               same minted id. */
            $r = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
            $newGroup = $r ? (int)$r->fetch_assoc()['NextId'] : 1;
            if ($r) { $r->close(); }

            $ins = $db->prepare(
                'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                 VALUES (?, ?, ?, ?), (?, ?, ?, ?)'
            );
            $emptyNote = '';
            /* 'issiissi' = i,s,s,i,i,s,s,i — note stays 's', createdBy stays
               'i' for BOTH value tuples. (v1's api.php:561 bound this same
               8-value pair as 'issiisis', a positional type-string
               transposition that silently coerced a curator-typed note to
               the string "0" on every brand-new counterpart group — fixed
               when this was first ported into api2.php, kept fixed here.) */
            $ins->bind_param(
                'issiissi',
                $newGroup, $srcId, $emptyNote, $createdBy,
                $newGroup, $tgtId, $note,      $createdBy
            );
            $ins->execute();
            $ins->close();
            $groupId = $newGroup;
            $extra   = ['created' => true];
        } else {
            /* Exactly one side already grouped — extend it. */
            $joinGroup = $srcGroup > 0 ? $srcGroup : $tgtGroup;
            $newSongId = $srcGroup > 0 ? $tgtId    : $srcId;
            $ins = $db->prepare(
                'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->bind_param('issi', $joinGroup, $newSongId, $note, $createdBy);
            $ins->execute();
            $ins->close();
            $groupId = $joinGroup;
            $extra   = ['extended' => true];
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return ['ok' => true, 'groupId' => $groupId] + $extra;
}

/**
 * iHymns — drop ONE song from its counterpart group. A faithful MIRROR of
 * `manage/editor/api2.php`'s `case 'song_link_remove'` behaviour (port of
 * v1's remove_song_link, api.php:599) — api2.php was out of scope for
 * this batch and keeps its own copy (see this file's doc-block). It IS,
 * however, an EXTRACTION of `manage/duplicate-songs.php`'s own `unlink`
 * action, which used the exact same algorithm and is in scope — that page
 * now calls this function rather than carrying its own copy.
 *
 * A resulting group of <2 members is meaningless, so it's cleaned up too.
 * Already-gone -> {ok:true, deleted:0} (a double-click on Unlink must not
 * surface a spurious error), NOT a 404.
 *
 * @param int|null    $id     tblSongLinks.Id — takes priority over $songId
 *                             when both are given (matches api2's `id?:
 *                             songId?` either-identifier contract).
 * @param string|null $songId tblSongLinks.SongId.
 * @return array{ok:bool,status?:int,error?:string,deleted?:int,groupId?:int}
 *   `groupId` (present only when a row was actually found, deleted or not)
 *   is carried so a caller's own logActivity() can report it exactly as
 *   both original call sites always did.
 */
function songLinkRemove(\mysqli $db, ?int $id, ?string $songId): array
{
    $songId = $songId !== null ? trim($songId) : null;
    $hasId     = $id !== null && $id > 0;
    $hasSongId = $songId !== null && $songId !== '';
    if (!$hasId && !$hasSongId) {
        return ['ok' => false, 'status' => 400, 'error' => 'id or songId is required.'];
    }

    if ($hasId) {
        $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE Id = ?');
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE SongId = ?');
        $stmt->bind_param('s', $songId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return ['ok' => true, 'deleted' => 0];
    }

    $groupId = (int)$row['GroupId'];
    $rowId   = (int)$row['Id'];

    $db->begin_transaction();
    try {
        $del = $db->prepare('DELETE FROM tblSongLinks WHERE Id = ?');
        $del->bind_param('i', $rowId);
        $del->execute();
        $deleted = $del->affected_rows;
        $del->close();

        /* Fewer than two members left in the group? Drop the remainder —
           a singleton group is meaningless. */
        $r = $db->prepare('SELECT COUNT(*) AS n FROM tblSongLinks WHERE GroupId = ?');
        $r->bind_param('i', $groupId);
        $r->execute();
        $remaining = (int)$r->get_result()->fetch_assoc()['n'];
        $r->close();
        if ($remaining < 2) {
            $cleanup = $db->prepare('DELETE FROM tblSongLinks WHERE GroupId = ?');
            $cleanup->bind_param('i', $groupId);
            $cleanup->execute();
            $cleanup->close();
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return ['ok' => true, 'deleted' => (int)$deleted, 'groupId' => $groupId];
}
