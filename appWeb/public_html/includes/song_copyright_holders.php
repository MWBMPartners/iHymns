<?php

declare(strict_types=1);

/**
 * iHymns — Multi-holder song copyright: shared read/write core (#1900, Wave 4 Commit C7)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A song's copyright can belong to more than one holder — words by one
 * publisher, music by another, or a holder plus an administering agency.
 * This file is the ONE place that reads the ordered list of holders for a
 * song and the ONE place that writes a new list (replacing the old one). No
 * page or API handler talks to `tblSongCopyrightHolders` directly — they all
 * come through here, the same discipline the songbook-publisher family
 * already follows (rule #22 / #37).
 *
 * DETAILED / WHY A SIBLING FILE, NOT AN EXTENSION OF publisher_admin.php
 * ------------------------------------------------------------------------
 * `includes/publisher_admin.php` owns the PUBLISHER REGISTRY itself
 * (validate/create/rename/merge/delete a `tblPublishers` row). This file
 * owns a DIFFERENT concern — which publishers a given SONG cites as
 * copyright holders, and in what order — so it is a sibling that DELEGATES
 * to `publisher_admin`'s neighbour `publisher_helpers.php` for publisher
 * resolution (`publisherResolvePickedOrCreate()`) rather than re-implementing
 * find-or-create here (rule #22). This mirrors the shape of
 * `includes/work_admin.php`'s `workMedley*()` functions, which likewise own
 * a M:N (`tblWorkComponents`) that references but does not duplicate a
 * neighbouring entity's CRUD.
 *
 * DORMANT IN THIS COMMIT. C7 (this commit) lands the table + this core with
 * NO caller anywhere in the app — `api2.php`, `metadata-tab.js` and
 * `api-docs.yaml` are all Wave 4 Commit C8's job. Every function here is
 * therefore exercised only by `tests/php/test-song-copyright-holders.php`
 * (its pure parts) until C8 wires a real caller. Every table/column touch is
 * existence-gated so an un-migrated install never throws under mysqli STRICT
 * mode (rule #9) even once C8 starts calling in.
 *
 * THE DENORM RE-SYNC (single-writer discipline, mirrors `tblSongbookPublishers`
 * rule #37 exactly, and `ed2_songCopyrightHolderApply()` in
 * `manage/editor/api2.php` byte-for-byte in UPDATE shape): after every
 * `songCopyrightHoldersReplace()` write, `tblSongs.CopyrightHolder` /
 * `CopyrightHolderId` are re-synced to the FIRST-listed holder (or cleared
 * when the list is empty) so every existing single-holder reader —
 * including `includes/pages/song.php`'s call into
 * `ihymns_copyright_statement()` — keeps working unchanged even before C8
 * teaches that call site to also pass the full ordered list. There is
 * deliberately only ONE writer of that denorm pair: this function and
 * `ed2_songCopyrightHolderApply()` are the two legitimate paths (single-pick
 * editor field vs. multi-pick registry), and both must resolve through
 * `publisherResolvePickedOrCreate()` so neither can silently diverge from
 * the registry (rule #35).
 *
 * Direct access is blocked (same guard as `publisher_helpers.php` /
 * `copyright_display.php`) so this file can't be requested as an endpoint
 * via an open Apache config.
 *
 * @link appWeb/public_html/includes/publisher_helpers.php   IHYMNS_COPYRIGHT_HOLDER_ROLES + publisherResolvePickedOrCreate() (delegated to, never forked)
 * @link appWeb/public_html/includes/copyright_display.php   ihymns_copyright_statement()'s $holders param — the consumer of songCopyrightHoldersList()'s output (wired in C8)
 * @link appWeb/public_html/includes/work_admin.php           workMedley*() — the sibling-M:N-core shape this mirrors
 * @link appWeb/public_html/manage/editor/api2.php            ed2_songCopyrightHolderApply() — the single-holder write this denorm-resync mirrors
 * @link appWeb/.sql/migrate-song-copyright-holders.php        the migration that creates tblSongCopyrightHolders
 * @link tests/php/test-song-copyright-holders.php             the guard covering this file's pure parts
 * @see #1900, #93, epic #1863 (rule #43 — every registry-backed field earns a picker, this is that picker's future data layer)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * True when `tblSongCopyrightHolders` exists in the current database —
 * memoised per-request (mirrors `ed2_copyrightHolderIdColPresent()` in
 * `manage/editor/api2.php`, same INFORMATION_SCHEMA shape, table instead of
 * column). STRICT-safe: any query failure degrades to `false` rather than
 * throwing, so a caller can probe this before touching the table on an
 * un-migrated install (rule #9).
 *
 * @see appWeb/.sql/migrate-song-copyright-holders.php
 */
function songCopyrightHoldersTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongCopyrightHolders' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) {
            $r->close();
        }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * True when `tblSongs.CopyrightHolderId` exists — this file's OWN memoised
 * probe (deliberately NOT a call into `manage/editor/api2.php`'s
 * `ed2_copyrightHolderIdColPresent()`, which is editor-internal and this
 * core must stay callable from any surface, public or admin). Same
 * INFORMATION_SCHEMA.COLUMNS shape, same STRICT-safe false-on-throw
 * fallback.
 */
function _songCopyHolders_holderIdColPresent(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'CopyrightHolderId' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) {
            $r->close();
        }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * True when a `tblPublishers.Id` row exists — a small existence check, NOT a
 * fork of `publisherFindOrCreateByName()`/`publisherResolvePickedOrCreate()`
 * (rule #22): those two both require a non-empty NAME to do anything useful
 * (an empty name short-circuits to `null` before ever touching the DB), so
 * they cannot verify a bare `publisherId` with no accompanying name — the
 * shape `songCopyrightHoldersReplace()` must support per the #1900 spec
 * (a row may carry only a previously-resolved `publisherId`). This is the
 * one extra SELECT that shape needs; it still delegates the "does
 * tblPublishers even exist" half to `publisherTableExists()` from
 * `publisher_helpers.php` rather than re-probing that itself.
 */
function _songCopyHolders_publisherIdExists(\mysqli $db, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    try {
        if (!publisherTableExists($db)) {
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM tblPublishers WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row !== null;
    } catch (\Throwable $e) {
        error_log('[_songCopyHolders_publisherIdExists] ' . $e->getMessage());
        return false;
    }
}

/**
 * The ordered list of copyright holders for one song (#1900).
 *
 * ELI5: "who are all the copyright holders on this song, and in what
 * order?" — joins the registry so the caller gets a display Name for free,
 * not just a bare publisher id.
 *
 * Gated + fail-soft: an absent table (un-migrated install) or a song with no
 * holder rows both return `[]` — never an exception, so a caller (a public
 * page render, a C8 API GET) can call this unconditionally.
 *
 * @param string $songId
 * @return array<int, array{publisherId:int, name:string, role:string, sortOrder:int}>
 *         ordered by SortOrder then Id (insertion order within a tie).
 */
function songCopyrightHoldersList(\mysqli $db, string $songId): array
{
    if (!songCopyrightHoldersTableExists($db)) {
        return [];
    }
    try {
        $stmt = $db->prepare(
            'SELECT h.PublisherId, p.Name, h.Role, h.SortOrder
               FROM tblSongCopyrightHolders h
               JOIN tblPublishers p ON p.Id = h.PublisherId
              WHERE h.SongId = ?
              ORDER BY h.SortOrder ASC, h.Id ASC'
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'publisherId' => (int)$row['PublisherId'],
                'name'        => (string)$row['Name'],
                'role'        => (string)$row['Role'],
                'sortOrder'   => (int)$row['SortOrder'],
            ];
        }
        $stmt->close();
        return $out;
    } catch (\Throwable $e) {
        error_log('[songCopyrightHoldersList] ' . $e->getMessage());
        return [];
    }
}

/**
 * PURE de-dup + default + drop-empty fold over an ALREADY publisher-resolved
 * row list (rule #34 — pure so a CI guard can exercise the decision with no
 * DB). Does NOT touch the database and does NOT resolve names — it only
 * decides, given `[['publisherId'=>int, 'role'=>string], …]`, which rows
 * survive and in what order:
 *
 *   - a row whose `publisherId` is missing or `<= 0` is DROPPED (nothing to
 *     link);
 *   - a missing/blank `role` defaults to `'holder'`;
 *   - the FIRST occurrence of a given `(publisherId, role)` pair wins — a
 *     later duplicate is dropped rather than reordered to the end, which is
 *     what keeps a curator's original ordering intent even when the same
 *     publisher+role was accidentally submitted twice (the
 *     `uq_song_pub_role` UNIQUE key would otherwise reject the INSERT
 *     outright rather than silently keeping "the second one").
 *
 * @param array<int, array{publisherId?:int|string|null, role?:string|null}> $rows
 * @return array<int, array{publisherId:int, role:string}>
 */
function _songCopyHolders_normalizeRows(array $rows): array
{
    $out  = [];
    $seen = [];
    foreach ($rows as $row) {
        $publisherId = isset($row['publisherId']) ? (int)$row['publisherId'] : 0;
        if ($publisherId <= 0) {
            continue;   // nothing resolved to link — drop.
        }
        $role = isset($row['role']) ? trim((string)$row['role']) : '';
        if ($role === '') {
            $role = 'holder';
        }
        $key = $publisherId . '|' . $role;
        if (isset($seen[$key])) {
            continue;   // first occurrence of this (publisher, role) pair wins.
        }
        $seen[$key] = true;
        $out[] = ['publisherId' => $publisherId, 'role' => $role];
    }
    return $out;
}

/**
 * Replace the FULL ordered holder list for one song (#1900) — the ONE write
 * path into `tblSongCopyrightHolders`, plus the `tblSongs.CopyrightHolder` /
 * `CopyrightHolderId` denorm re-sync (rule #37's single-writer discipline).
 *
 * ELI5: hand this the new complete list of holders (names and/or picked
 * publisher ids, in the order they should display) and it replaces
 * whatever the song had before — resolving each name to a registry row
 * (creating one if it's genuinely new, exactly like every other
 * find-or-create field in this app, rule #43), then re-pointing the song's
 * single-holder display fields at whichever holder is now first.
 *
 * GATED: on an un-migrated install (`tblSongCopyrightHolders` absent) this
 * returns `['ok'=>false,'reason'=>'unmigrated','holders'=>[]]` WITHOUT
 * throwing and WITHOUT touching `tblSongs` — C7 has no caller to turn that
 * into an HTTP response yet; C8's endpoint is expected to map it to a 409,
 * the same convention `component_upsert`/`components_replace` use for an
 * un-migrated `tblLyricLines` shadow (rule #35 — branch on the machine-
 * readable `reason`, never on prose).
 *
 * VALIDATED: an unknown `role` (checked against
 * `IHYMNS_COPYRIGHT_HOLDER_ROLES`, after defaulting an empty role to
 * `'holder'`) rejects the WHOLE call before anything is written —
 * `['ok'=>false,'reason'=>'bad_role','role'=>$role,'holders'=>…]` (the
 * current, unchanged, holder list is still returned so a caller can re-
 * render without a second round-trip).
 *
 * TRANSACTIONAL: the M:N replace (DELETE all rows for this song, INSERT the
 * deduped/ordered survivors with a fresh 0-based SortOrder) and the
 * `tblSongs` denorm re-sync happen inside ONE `begin_transaction()` /
 * `commit()` — a failure anywhere rolls back the whole thing rather than
 * leaving the M:N rows and the denorm mirror disagreeing. This function
 * OWNS its transaction (unlike e.g. `lyricLinesWriteComponents()`, which
 * expects to run inside a caller's already-open transaction) because it is
 * the top-level entry point for this concern — there is no larger save
 * operation it needs to nest inside today.
 *
 * @param \mysqli    $db
 * @param string     $songId
 * @param array<int, array{publisherId?:int|string|null, name?:string|null, role?:string|null}> $rows
 *        Each row is a curator-supplied holder: `name` (typed or picked
 *        display text), an optional `publisherId` (a picker's claimed id,
 *        verified via `publisherResolvePickedOrCreate()`'s trust-but-verify
 *        rule, or — when `name` is blank — verified directly against
 *        `tblPublishers`), and an optional `role` (defaults to `'holder'`).
 *        A row that resolves to no publisher at all (blank name AND no
 *        valid id) is silently skipped, mirroring
 *        `publisherResolvePickedOrCreate()`'s own "empty name -> null, no
 *        create" contract.
 * @param int|null   $userId  Reserved for C8's audit-log wiring (who changed
 *        the holder list) — accepted now so the signature does not need to
 *        change again when that lands; UNUSED by this commit (no audit-log
 *        call site exists yet — see the "What C8 must produce" plan
 *        section). Not a dormant/vanity field per rule #44: it is read by
 *        nothing today because nothing calls this function yet, not because
 *        the app collects it "in case".
 * @return array{ok:bool, reason?:string, role?:string, holders:array}
 *         `holders` is always the FRESH read-back via `songCopyrightHoldersList()`
 *         (rule #35 — the stored truth, not an echo of the input) except on
 *         the `'unmigrated'` path, where it is `[]` because there is nothing
 *         to read.
 */
function songCopyrightHoldersReplace(\mysqli $db, string $songId, array $rows, ?int $userId = null): array
{
    if (!songCopyrightHoldersTableExists($db)) {
        return ['ok' => false, 'reason' => 'unmigrated', 'holders' => []];
    }

    /* Guarded require (rule #41 shape) — this file physically lives in the
       real docroot's includes/ alongside publisher_helpers.php, so __DIR__
       is safe here (unlike a .sql/ migration, which must never hardcode a
       /public_html/ sibling path). */
    if (!function_exists('publisherResolvePickedOrCreate')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'publisher_helpers.php';
    }

    /* Step 1a — validate EVERY row's role FIRST (pure, no DB), so a bad role
       anywhere in the list rejects the whole call BEFORE any publisher is
       minted for an earlier row. find-or-create is idempotent (a re-submit
       reuses the same registry row, no duplicate), but a request that is
       going nowhere should mint nothing at all — which a row-by-row
       validate-then-resolve loop cannot guarantee (row 1 mints, row 2's bad
       role then aborts, leaving row 1's orphan). Each surviving row is
       normalised to {name, claimedId, role} for the resolution pass. */
    $prelim = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $role = isset($row['role']) ? trim((string)$row['role']) : '';
        if ($role === '') {
            $role = 'holder';
        }
        if (!array_key_exists($role, IHYMNS_COPYRIGHT_HOLDER_ROLES)) {
            return [
                'ok'      => false,
                'reason'  => 'bad_role',
                'role'    => $role,
                'holders' => songCopyrightHoldersList($db, $songId),
            ];
        }
        $prelim[] = [
            'name'      => isset($row['name']) ? trim((string)$row['name']) : '',
            'claimedId' => (isset($row['publisherId']) && $row['publisherId'] !== null && $row['publisherId'] !== '')
                         ? (int)$row['publisherId']
                         : null,
            'role'      => $role,
        ];
    }

    /* Step 1b — now that the whole request is known role-valid, resolve each
       row to a publisher id (which MAY mint a new registry row via
       find-or-create, rule #43). A row that resolves to no publisher at all
       (blank name AND no verifiable id) is silently skipped. */
    $resolved = [];
    foreach ($prelim as $p) {
        $publisherId = null;
        if ($p['name'] !== '') {
            $publisherId = publisherResolvePickedOrCreate($db, $p['name'], $p['claimedId']);
        } elseif ($p['claimedId'] !== null && $p['claimedId'] > 0 && _songCopyHolders_publisherIdExists($db, $p['claimedId'])) {
            $publisherId = $p['claimedId'];
        }

        if ($publisherId === null || $publisherId <= 0) {
            continue;   // blank name + no verifiable id — nothing to link.
        }

        $resolved[] = ['publisherId' => $publisherId, 'role' => $p['role']];
    }

    /* Step 2 — de-dup on (publisherId, role), first occurrence wins, order
       preserved. Pure, no DB (rule #34 — this is the function the guard test
       mutation-proves). */
    $toWrite = _songCopyHolders_normalizeRows($resolved);

    /* Step 3 — write the M:N + re-sync the tblSongs denorm mirror, both
       inside one transaction. */
    try {
        $db->begin_transaction();

        $del = $db->prepare('DELETE FROM tblSongCopyrightHolders WHERE SongId = ?');
        $del->bind_param('s', $songId);
        $del->execute();
        $del->close();

        if ($toWrite !== []) {
            $ins = $db->prepare(
                'INSERT INTO tblSongCopyrightHolders (SongId, PublisherId, Role, SortOrder) VALUES (?, ?, ?, ?)'
            );
            $sortOrder = 0;
            foreach ($toWrite as $row) {
                $pubId   = $row['publisherId'];
                $roleVal = $row['role'];
                $ins->bind_param('sisi', $songId, $pubId, $roleVal, $sortOrder);
                $ins->execute();
                $sortOrder++;
            }
            $ins->close();
        }

        /* Denorm re-sync — first-listed holder wins (rule #37), resolved
           back through the registry (not the raw input text) so
           CopyrightHolder always mirrors what the FK actually points at. */
        $firstName = '';
        $firstId   = null;
        if ($toWrite !== []) {
            $firstPublisherId = $toWrite[0]['publisherId'];
            $nameStmt = $db->prepare('SELECT Name FROM tblPublishers WHERE Id = ? LIMIT 1');
            $nameStmt->bind_param('i', $firstPublisherId);
            $nameStmt->execute();
            $nameRow = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();
            if ($nameRow) {
                $firstName = (string)$nameRow['Name'];
                $firstId   = $firstPublisherId;
            }
        }

        /* Exact UPDATE shape mirrored from ed2_songCopyrightHolderApply()
           (manage/editor/api2.php) so the two writers of this denorm pair
           can never diverge (rule #37). */
        if (_songCopyHolders_holderIdColPresent($db)) {
            $u = $db->prepare('UPDATE tblSongs SET CopyrightHolder = ?, CopyrightHolderId = ? WHERE SongId = ?');
            $u->bind_param('sis', $firstName, $firstId, $songId);
            $u->execute();
            $u->close();
        } else {
            $u = $db->prepare('UPDATE tblSongs SET CopyrightHolder = ? WHERE SongId = ?');
            $u->bind_param('ss', $firstName, $songId);
            $u->execute();
            $u->close();
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        error_log('[songCopyrightHoldersReplace] ' . $e->getMessage());
        return [
            'ok'      => false,
            'reason'  => 'write_failed',
            'holders' => songCopyrightHoldersList($db, $songId),
        ];
    }

    return ['ok' => true, 'holders' => songCopyrightHoldersList($db, $songId)];
}
