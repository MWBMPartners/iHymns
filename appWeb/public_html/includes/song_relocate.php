<?php

declare(strict_types=1);

/**
 * iHymns — Songbook move: re-key a song into another songbook (#1679)
 * ===================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A song's id starts with its songbook's short code — `MP-1008` lives in `MP`.
 * So when a curator moves that song into a different book, keeping the old id
 * would leave it permanently mislabelled: an `MP-` id sitting in `CP`. This file
 * does the move properly — it gives the song a NEW id in the new book, and
 * leaves a forwarding note so every old link, bookmark and share still works.
 *
 * WHY THIS EXISTS (#1679)
 * -----------------------
 * `tblSongs.SongId` is not an opaque key — `Abbreviation` IS the id prefix
 * (CLAUDE.md rule #27), parsed by the PWA router, the OG-image endpoint and
 * several API validators. Before this file, changing `SongbookAbbr` on an
 * existing song left `SongId` alone, so the two disagreed forever: the song
 * claimed one book by id and another by column. The owner's decision was to
 * RE-KEY on move and write a `tblSongRedirects` row so old permalinks keep
 * resolving.
 *
 * HOW THE FAN-OUT WORKS (the "machinery already exists" point)
 * -----------------------------------------------------------
 * Every FK referencing `tblSongs(SongId)` is declared `ON UPDATE CASCADE` — 25+
 * constraints in `appWeb/.sql/schema.sql` covering writers, composers,
 * components, lyric lines, media, tags, links, favourites, history, revisions,
 * keys… INCLUDING `tblSongRedirects.NewSongId`, so pre-existing redirect chains
 * that pointed at the old id repoint themselves and `songRedirectRepoint()` is
 * NOT needed here. One `UPDATE tblSongs SET SongId = ?` therefore re-keys the
 * whole graph atomically. `appWeb/.sql/migrate-backfill-canonical-songids.php`
 * is the mass-rename precedent this follows, including its FK pre-check
 * reasoning; that migration REFUSES to run on an install whose cascades drifted.
 *
 *   https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 *
 * WHAT THE CASCADE CANNOT REACH
 * -----------------------------
 *  - `tblContentRestrictions.EntityId` — polymorphic `VARCHAR(50)` with NO FK
 *    (schema.sql), so a move would silently ORPHAN every restriction row
 *    attached to the song. `checkContentAccess()` looks the id up directly and
 *    never follows redirects, so an un-rewritten restriction is a restriction
 *    that stops applying. Rewritten here, exactly as the #1380 migration does.
 *  - Setlist / shared-setlist `SongsJson` blobs, native-app local caches, PWA
 *    offline stores, third-party bookmarks. Those are covered by the REDIRECT,
 *    which `?action=song_detail` and the `page=song` fragment both follow.
 *
 * WHAT DOES *NOT* CHANGE
 * ----------------------
 * `tblSongs.PublicId` — the opaque permalink (#1343-B) exists precisely to
 * survive a songbook move / renumber (schema.sql), so it is deliberately
 * untouched: a shared `/song/<PublicId>` link keeps working with no redirect
 * hop at all.
 *
 * CONSUMER SWEEP (rule #33 — grep who points at you BEFORE changing what you serve)
 * ----------------------------------------------------------------------------------
 * Every in-repo consumer that parses or looks up a SongId was checked against a
 * MOVED (now dead) id. Recorded here because "it degrades safely" is a finding
 * that is expensive to re-derive and invisible in a diff:
 *   - `router.js normalizeSongId()` — regex `^([A-Za-z]+)-0*(\d+)$`; an old id
 *     still parses, so the SPA requests `page=song&id=<old>` and the fragment
 *     FOLLOWS the redirect (#1343) and history-replaces the URL. Handled.
 *   - `?action=song_detail` / `song_data` — FOLLOWS, since #1679 (api.php),
 *     emitting `redirectedFrom`; a tombstone answers 410, not 404.
 *   - `og-image.php` — `getSongById()` returns null → the generic card. Degrades
 *     safely (a cosmetically poorer unfurl on a stale shared link, never an error).
 *   - `?action=related_songs` — 404 "Song not found"; the related rail just does
 *     not render. Degrades safely.
 *   - `?action=song_view` — the history INSERT is inside a try/catch, so an FK
 *     violation on a dead id logs and answers `{ok:false, fallback:true}`.
 *   - `?action=favorites_sync` — per-row try/catch, documented as "an orphan song
 *     id (FK violation) is skipped, not fatal". Both degrade safely.
 *   - `tblUserFavorites` / `tblSongHistory` / `tblSongLinks` / … — all FK'd with
 *     `ON UPDATE CASCADE`, so stored rows re-key themselves; only a client's LOCAL
 *     copy of an id goes stale, which is what the redirect exists for.
 * One caveat that predates this file: `getSongById()` falls back to
 * `getSongByNumber(prefix, number)` when the exact id misses, so a dead id could
 * in principle resolve to a DIFFERENT song that holds that number in the old
 * book. Clearing `Number` on move does not create this (the moved song has left
 * the book entirely); it is the pre-existing number-fallback, unchanged here.
 *
 * NUMBER POLICY
 * -------------
 * `Number` is CLEARED to NULL. This is the owner's stated default and matches
 * v1's own bulk-move behaviour; it also dodges a collision with whatever song
 * already holds that slot in the target book. The minted id tail carries the
 * chosen slot; the curator assigns a real number afterwards if the target book
 * is numbered.
 *
 * TRANSACTION CONTRACT
 * --------------------
 * `songRelocate()` performs several writes and does NOT open a transaction —
 * the CALLER must already be inside one, so a failure downstream of the move
 * (the rest of a whole-song save) rolls the re-key back with it. mysqli runs
 * under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` (includes/db_mysql.php),
 * so every statement here THROWS on failure rather than returning false
 * (CLAUDE.md rule: never write a `=== false` guard against mysqli).
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/remediation-plan-2026-07-30.md §4.4
 */

/* Direct-hit guard: this is a library, never a page. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_redirects.php';

/**
 * Mint a free canonical `<Abbr>-NNNN` SongId for a songbook.
 *
 * ELI5: "what's the next free slot in this book?" — and if something already
 * took it, keep counting until we find one that's free.
 *
 * Detail: this is the ONE mint. It was previously inlined in
 * `manage/editor/save_song_core.php` (the #1380 draft-id promotion) and copied
 * again in `appWeb/.sql/migrate-backfill-canonical-songids.php`; the save core
 * now calls this, so the editor's "new song" id and a moved song's id are chosen
 * by the same rule and cannot drift (CLAUDE.md modularity rule). The seed comes
 * from `_bulkImport_nextSongNumberFor()` so the importers agree too.
 *
 * The loop is a read-then-write window (TOCTOU): two concurrent mints in one
 * book can both pass the existence check. The UNIQUE index on
 * `tblSongs.SongId` is the real backstop — callers that INSERT a freshly minted
 * id do so WITHOUT `ON DUPLICATE KEY UPDATE`, so a collision throws
 * ER_DUP_ENTRY and rolls back rather than silently overwriting the other song
 * (save_song_core's #1380 FIX 3). A RELOCATE is immune to that race by
 * construction: it UPDATEs an existing row, so a colliding id fails the UNIQUE
 * index and rolls the whole move back.
 *
 * @param  \mysqli $db
 * @param  string  $abbr Target songbook abbreviation (the SongId prefix, rule #27).
 * @return array{0:string,1:int} [the minted SongId, the slot number used]
 */
function songRelocateMintId(\mysqli $db, string $abbr): array
{
    /* Lazy require: song_importers.php is large and most requests never mint. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_importers.php';

    $n         = _bulkImport_nextSongNumberFor($db, $abbr);
    $candidate = sprintf('%s-%04d', $abbr, $n);

    $existsStmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
    $existsStmt->bind_param('s', $candidate);
    $existsStmt->execute();
    while ($existsStmt->get_result()->fetch_row() !== null) {
        $n++;
        $candidate = sprintf('%s-%04d', $abbr, $n);
        $existsStmt->bind_param('s', $candidate);
        $existsStmt->execute();
    }
    $existsStmt->close();

    return [$candidate, $n];
}

/**
 * Move a song into another songbook, re-keying its SongId and leaving a
 * permalink redirect behind (#1679).
 *
 * ELI5: give the song a new id that matches its new book, drag everything
 * attached to it along, and leave a "it moved over here" note at the old
 * address.
 *
 * MUST be called inside the caller's transaction (see the file doc-block).
 *
 * NO-OP CASES (all return `renamed => false` with the id unchanged, never
 * throw — a save that merely re-sends the song's current book is the normal
 * case, not an error):
 *   - blank id or blank target abbreviation;
 *   - the song does not exist (the caller owns its own 404 path);
 *   - the song is already in the target songbook.
 *
 * THROWS `\InvalidArgumentException` when the target songbook does not exist.
 * That is a caller bug or bad input, and letting it through would either violate
 * `fk_Songs_Songbook` anyway or (worse) mint an id with a prefix no book claims.
 * `InvalidArgumentException` specifically — NOT `RuntimeException` — because
 * `mysqli_sql_exception` IS a `RuntimeException` (verified, PHP 8), so a caller
 * catching that to answer 422 "you named a book that doesn't exist" would also
 * swallow every genuine database failure and report it as bad user input.
 *
 * @param  \mysqli  $db
 * @param  string   $oldSongId  The song's CURRENT SongId.
 * @param  string   $targetAbbr The destination `tblSongbooks.Abbreviation`.
 * @param  int|null $userId     Curator id for the redirect audit row, if signed in.
 * @return array{songId:string, previousId:string, renamed:bool, previousSongbookAbbr:string}
 */
function songRelocate(\mysqli $db, string $oldSongId, string $targetAbbr, ?int $userId): array
{
    $oldSongId  = trim($oldSongId);
    $targetAbbr = trim($targetAbbr);

    $noop = [
        'songId'               => $oldSongId,
        'previousId'           => $oldSongId,
        'renamed'              => false,
        'previousSongbookAbbr' => '',
    ];
    if ($oldSongId === '' || $targetAbbr === '') { return $noop; }

    /* 1 — where does the song live right now? */
    $cur = $db->prepare('SELECT SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1');
    $cur->bind_param('s', $oldSongId);
    $cur->execute();
    $curRow = $cur->get_result()->fetch_assoc();
    $cur->close();
    if ($curRow === null) { return $noop; }

    $currentAbbr = (string)($curRow['SongbookAbbr'] ?? '');
    $noop['previousSongbookAbbr'] = $currentAbbr;
    if ($currentAbbr === $targetAbbr) { return $noop; }

    /* 2 — the destination must be a real songbook. Checked explicitly rather
       than left to fk_Songs_Songbook so the failure names the actual problem
       instead of surfacing as a raw FK violation three frames up. */
    $bk = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
    $bk->bind_param('s', $targetAbbr);
    $bk->execute();
    $bkFound = $bk->get_result()->fetch_row() !== null;
    $bk->close();
    if (!$bkFound) {
        throw new \InvalidArgumentException('Unknown target songbook "' . $targetAbbr . '".');
    }

    /* 3 — mint the new canonical id in the destination book. */
    [$newSongId] = songRelocateMintId($db, $targetAbbr);

    /* 4 — ONE statement does the whole fan-out. Every FK to tblSongs(SongId) is
       ON UPDATE CASCADE, so all child rows (and any redirect row already
       pointing HERE, via tblSongRedirects.NewSongId) re-key atomically.
       Number is cleared per the move policy in the file doc-block. */
    $mv = $db->prepare('UPDATE tblSongs SET SongId = ?, SongbookAbbr = ?, Number = NULL WHERE SongId = ?');
    $mv->bind_param('sss', $newSongId, $targetAbbr, $oldSongId);
    $mv->execute();
    $mv->close();

    /* 5 — the ONE soft reference the cascade cannot reach and the redirect
       cannot cover: content restrictions are looked up by EntityId directly
       (includes/content_access.php), so leaving them on the dead id silently
       DROPS the restriction. Existence-probed because the table is optional on
       a minimal install and mysqli STRICT would throw (the #1228 lesson). */
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblContentRestrictions' LIMIT 1"
        );
        $probe->execute();
        $hasRestrictions = $probe->get_result()->fetch_row() !== null;
        $probe->close();
        if ($hasRestrictions) {
            $rs = $db->prepare(
                "UPDATE tblContentRestrictions SET EntityId = ? WHERE EntityType = 'song' AND EntityId = ?"
            );
            $rs->bind_param('ss', $newSongId, $oldSongId);
            $rs->execute();
            $rs->close();
        }
    } catch (\Throwable $e) {
        /* Deliberately non-fatal: the move itself has already succeeded and is
           the user's intent. A failure here is logged loudly rather than
           rolling back a legitimate curation action. */
        error_log('[songRelocate] content-restriction rewrite failed for ' . $oldSongId . ': ' . $e->getMessage());
    }

    /* 6 — the forwarding note. 'move' is new vocabulary for the VARCHAR Reason
       column (rule #20: a growable vocabulary is VARCHAR, so adding a value
       needs no ALTER). songRedirectWrite() returns false — WITHOUT writing — on
       an install where the #1343 migration has not run; never block a legitimate
       move on that, just make the gap audible. */
    if (!songRedirectWrite($db, $oldSongId, $newSongId, 'move', $userId)) {
        $warning = 'songRelocate: tblSongRedirects unavailable — ' . $oldSongId
                 . ' → ' . $newSongId . ' moved WITHOUT a permalink redirect.';
        error_log('[songRelocate] ' . $warning);
        if (function_exists('logActivity')) {
            logActivity('song.move', 'song', $newSongId, [
                'previous_id' => $oldSongId,
                'warning'     => 'redirect table not migrated on this environment',
            ], 'failure');
        }
    }

    /* 7 — both books' cached SongCount moved. Recomputed HERE so every funnel
       gets it (api2's granular field update has no other place doing this);
       best-effort, because a cache recompute must never roll back the move
       itself — it self-heals on the next pass (#791). */
    try {
        $cnt = $db->prepare(
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
              WHERE Abbreviation = ?'
        );
        foreach ([$targetAbbr, $currentAbbr] as $abbr) {
            if ($abbr === '') { continue; }
            $cnt->bind_param('ss', $abbr, $abbr);
            $cnt->execute();
        }
        $cnt->close();
    } catch (\Throwable $e) {
        error_log('[songRelocate] SongCount recompute failed: ' . $e->getMessage());
    }

    return [
        'songId'               => $newSongId,
        'previousId'           => $oldSongId,
        'renamed'              => true,
        'previousSongbookAbbr' => $currentAbbr,
    ];
}
