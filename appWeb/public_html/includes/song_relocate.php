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
 * WHAT THE CASCADE CANNOT REACH — TWO soft references, not one
 * ------------------------------------------------------------
 *  - `tblContentRestrictions.EntityId` — polymorphic `VARCHAR(50)` with NO FK
 *    (schema.sql), so a move would silently ORPHAN every restriction row
 *    attached to the song. `checkContentAccess()` looks the id up directly and
 *    never follows redirects, so an un-rewritten restriction is a restriction
 *    that stops applying. Rewritten here, exactly as the #1380 migration does,
 *    and rewritten FATALLY — see step 5.
 *  - `tblSongbookEntries` — its `SongId` DOES cascade, but `SongbookAbbr`,
 *    `SongNumber` and `IsHome` are not reachable from a `SongId` change, so an
 *    un-rewritten home row reads "(old book, NEW id, old number, IsHome=1)":
 *    the junction claims the song's home is the book it just left, and
 *    `uq_book_number` keeps the vacated slot occupied in the old book while
 *    `tblSongs.Number` was cleared. The table's own `IsHome` COMMENT states it
 *    is "kept in sync with tblSongs.SongbookAbbr" — step 5b is what keeps that
 *    stated invariant true. (An earlier revision of this doc-block called the
 *    content restrictions "the ONE soft reference the cascade cannot reach".
 *    That was wrong; there are two.)
 *  - Setlist / shared-setlist song blobs (`tblUserSetlists.SongsJson`,
 *    `tblSharedSetlists.Data`), native-app local caches, PWA offline stores,
 *    third-party bookmarks. Those are covered by the REDIRECT, which
 *    `?action=song_detail` and the `page=song` fragment both follow.
 *
 * WHAT THE CASCADE ASSUMES — AND WHY THAT IS CHECKED (H3)
 * ------------------------------------------------------
 * `schema.sql` declares every FK to `tblSongs(SongId)` `ON UPDATE CASCADE`, so
 * a FRESH install is fine. Four FKs created by MIGRATIONS were not
 * (`fk_link_song`, `fk_alt_song`, `fk_media_song`, `fk_work_song_song`), and
 * only `migrate-songid-prefix-fixup.php` retro-fits them — a card whose probe
 * looks for a PREFIX MISMATCH, so an install that never renamed an abbreviation
 * shows it as "not pending" forever and keeps four RESTRICT FKs. On such an
 * install the re-key throws `ER_ROW_IS_REFERENCED_2` mid-save, and the caller
 * rolls back the WHOLE save — the curator loses lyrics, components and credits
 * to a generic "Failed to save song". `songRelocateAssertCascades()` therefore
 * refuses BEFORE any write, naming the offending constraints and the migration
 * that fixes them, mirroring the two existing precedents
 * (`migrate-backfill-canonical-songids.php`, `includes/songbook_maintenance.php`).
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
 * Does an OPTIONAL table exist on THIS environment? Probed once per table, per
 * request (static).
 *
 * ELI5: migrations are run by hand from a web page, so a table that is in
 * `schema.sql` may simply not be here yet. Ask the database before touching it.
 *
 * Detail: mirrors `songRedirectsTableReady()` (song_redirects.php) — same
 * INFORMATION_SCHEMA question, same per-request memo — but DELIBERATELY does NOT
 * swallow a probe failure. That helper returns false on a throw, which is right
 * for a redirect (the worst case is a missing forwarding note). Here a
 * false-because-the-probe-broke would silently skip the content-restriction
 * rewrite, i.e. turn withheld content readable, which is the M3 finding this
 * pass exists to remove. An unreadable INFORMATION_SCHEMA aborts the move
 * instead; the caller's transaction rolls it back.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-tables-table.html
 */
function songRelocateTableExists(\mysqli $db, string $table): bool
{
    static $seen = [];
    if (isset($seen[$table])) { return $seen[$table]; }

    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $seen[$table] = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();

    return $seen[$table];
}

/**
 * REFUSE the move unless every FK referencing `tblSongs(SongId)` cascades on
 * UPDATE (#1679 hardening, H3).
 *
 * ELI5: renaming the song's id only drags its verses, media and links along if
 * the database was told to follow. Four of those links are missing that setting
 * on older installs, so check first and say exactly what to run — rather than
 * letting the save blow up half-way and lose the curator's work.
 *
 * Detail: the re-key is ONE `UPDATE tblSongs SET SongId = ?`; every child row
 * follows only because of `ON UPDATE CASCADE`. A `RESTRICT`/`NO ACTION` child FK
 * makes that statement throw `ER_ROW_IS_REFERENCED_2` (1451), and because
 * `songRelocate()` runs inside the CALLER's transaction the whole save is rolled
 * back — the curator sees "Failed to save song" and has no way to learn that the
 * cause is an un-applied migration. `SET NULL` would be worse: it succeeds and
 * orphans the children. So this asks INFORMATION_SCHEMA the same question
 * `migrate-backfill-canonical-songids.php` asks before ITS mass re-key, and
 * points at the same fixup migration `includes/songbook_maintenance.php` points
 * at when its own inline rewrite trips the constraint.
 *
 * Memoised in a static for the whole request: a bulk move (the editor's
 * per-song save loop, an importer) must not re-query the catalogue per song.
 * A refusal is memoised too, so every song in the batch fails the same way with
 * the same message rather than the first one failing and the rest half-applying.
 *
 * @param  \mysqli $db
 * @return void
 * @throws \RuntimeException naming the offending constraint(s) + the migration.
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-referential-constraints-table.html
 */
function songRelocateAssertCascades(\mysqli $db): void
{
    /* null = not probed yet; '' = all good; a string = the refusal message. */
    static $verdict = null;

    if ($verdict === null) {
        try {
            /* REFERENTIAL_CONSTRAINTS carries UPDATE_RULE but not the referenced
               COLUMN, so join KEY_COLUMN_USAGE to keep this to FKs that actually
               point at SongId (tblSongs is also referenced by … nothing else
               today, but a future FK on tblSongs.Id must not be mistaken for one
               the re-key depends on). Same query shape as the migration's. */
            $stmt = $db->prepare(
                "SELECT rc.CONSTRAINT_NAME, rc.UPDATE_RULE, k.TABLE_NAME
                   FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                   JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                     ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                    AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
                  WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                    AND rc.REFERENCED_TABLE_NAME = 'tblSongs'
                    AND k.REFERENCED_COLUMN_NAME = 'SongId'"
            );
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (\Throwable $e) {
            /* Fail CLOSED, exactly as the migration does. A move we cannot
               verify is a move that may silently orphan children; refusing is
               recoverable, an orphaned graph is not. */
            $verdict = 'songRelocate: could not verify the ON UPDATE CASCADE foreign keys on '
                     . 'tblSongs(SongId) — ' . $e->getMessage()
                     . '. Refusing to move the song; re-try once the database is reachable.';
        }

        if ($verdict === null) {
            $bad = [];
            foreach ($rows as $r) {
                if (strtoupper((string)$r['UPDATE_RULE']) === 'CASCADE') { continue; }
                $bad[] = (string)$r['TABLE_NAME'] . '.' . (string)$r['CONSTRAINT_NAME']
                       . ' = ' . (string)$r['UPDATE_RULE'];
            }
            $verdict = $bad === []
                ? ''
                : 'songRelocate: ' . count($bad) . ' foreign key(s) referencing tblSongs(SongId) are NOT '
                . 'ON UPDATE CASCADE, so re-keying this song would fail or orphan its child rows — '
                . implode('; ', array_slice($bad, 0, 20))
                . '. Run "Re-prefix SongIds whose SongbookAbbr no longer matches" '
                . '(appWeb/.sql/migrate-songid-prefix-fixup.php) on /manage/setup-database first — '
                . 'it adds the missing cascades — then move the song again.';
        }
    }

    if ($verdict !== '') {
        /* RuntimeException, not InvalidArgumentException: this is an
           ENVIRONMENT fault, not bad user input, so api2's 422 branch (which
           catches InvalidArgumentException specifically) must NOT claim the
           curator typed the wrong book. It surfaces through the v2 API's
           top-level handler as a 500 whose `error_detail` — admin-only, and the
           editor is admin-only — carries this sentence verbatim. The MESSAGE is
           the deliverable here; a bare refusal would be no better than the
           ER_ROW_IS_REFERENCED_2 it replaces. */
        throw new \RuntimeException($verdict);
    }
}

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
 * A FREE SLOT IS NOT ENOUGH — THE REDIRECT LAYER ALSO CLAIMS IDS (M1, #1679)
 * -------------------------------------------------------------------------
 * The seed is `MAX(Number) + 1` and `songRelocate()` sets `Number = NULL`, so
 * moving the highest-numbered song OUT of a book frees its slot again. A later
 * mint in that book would then re-issue the exact id a live `tblSongRedirects`
 * row still forwards away from — and `getSongById()` resolves an exact match
 * before it ever consults the redirect layer, so an old bookmark would get
 * **200 OK with a completely different song**. Wrong content is worse than the
 * 404 this feature set out to remove, so a candidate is "taken" if EITHER
 * `tblSongs` holds it OR `tblSongRedirects.OldSongId` claims it.
 * That probe is gated on `songRedirectsTableReady()` — the SAME helper every
 * other redirect reader uses, not a second copy — because the #1343 table is
 * optional and an ungated read of a missing table THROWS under mysqli STRICT.
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
    /* Prepared once, outside the loop, and only where the table exists. */
    $claimStmt  = songRedirectsTableReady($db)
        ? $db->prepare('SELECT 1 FROM tblSongRedirects WHERE OldSongId = ? LIMIT 1')
        : null;

    /** A candidate is free only if NEITHER the live corpus nor a redirect holds it. */
    $taken = static function (string $id) use ($existsStmt, $claimStmt): bool {
        $existsStmt->bind_param('s', $id);
        $existsStmt->execute();
        if ($existsStmt->get_result()->fetch_row() !== null) { return true; }
        if ($claimStmt === null) { return false; }
        $claimStmt->bind_param('s', $id);
        $claimStmt->execute();
        return $claimStmt->get_result()->fetch_row() !== null;
    };

    while ($taken($candidate)) {
        $n++;
        $candidate = sprintf('%s-%04d', $abbr, $n);
    }

    $existsStmt->close();
    if ($claimStmt !== null) { $claimStmt->close(); }

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
 * THROWS `\RuntimeException` when this environment's FKs cannot cascade the
 * re-key (`songRelocateAssertCascades()`, H3). Deliberately the OTHER class, for
 * the same reason: that is an environment fault, not bad input, so api2's 422
 * branch must not claim the curator typed the wrong book. It reaches the curator
 * through the v2 API's admin-only `error_detail`, message intact.
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

    /* 2b — the environment must actually be able to cascade the re-key. Checked
       AFTER the no-op short-circuits (a save that merely re-sends the current
       book must not pay for an INFORMATION_SCHEMA read) and BEFORE the first
       write, so a drifted install is refused with a fixable message instead of
       exploding half-way through the caller's transaction. Memoised, so a bulk
       move asks once. #1679 H3. */
    songRelocateAssertCascades($db);

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

    /* 5 — the first soft reference the cascade cannot reach and the redirect
       cannot cover: content restrictions are looked up by EntityId directly
       (includes/content_access.php), so leaving them on the dead id silently
       DROPS the restriction. Existence-probed because the table is optional on
       a minimal install and mysqli STRICT would throw (the #1228 lesson).

       DELIBERATELY FATAL (#1679 M3). This used to be wrapped in
       `try { … } catch (\Throwable) { error_log(…); }`, on the reasoning that a
       cache-ish follow-up must never roll back a legitimate curation action.
       That reasoning does not survive reading what the row IS: a restriction
       left on a dead id stops applying, so the ONE security-relevant step in
       this function was the one made non-fatal — withheld content silently
       becomes readable and the only trace is error_log. A move that cannot
       carry its restrictions must not commit. */
    if (songRelocateTableExists($db, 'tblContentRestrictions')) {
        $rs = $db->prepare(
            "UPDATE tblContentRestrictions SET EntityId = ? WHERE EntityType = 'song' AND EntityId = ?"
        );
        $rs->bind_param('ss', $newSongId, $oldSongId);
        $rs->execute();
        $rs->close();
    }

    /* 5b — the SECOND soft reference (#1679 F3). `tblSongbookEntries.SongId`
       cascaded with everything else in step 4, but `SongbookAbbr` / `SongNumber`
       / `IsHome` did not, so without this the home row reads "(old book, NEW id,
       old number, IsHome=1)": the junction says the song's home is the book it
       just left, and `uq_book_number` keeps the vacated slot occupied in a book
       the song is no longer in. The table is documented "not yet read by the
       app", which makes the damage LATENT rather than harmless — once something
       does read it there is no way to tell a stale home entry from a genuine
       multi-book membership.

       `SongNumber` follows `tblSongs.Number` to NULL (the move policy above);
       multiple NULLs coexist happily under a MySQL UNIQUE index, so
       `uq_book_number` cannot be tripped by the clear.
       https://dev.mysql.com/doc/refman/8.0/en/create-index.html

       `uq_book_song (SongbookAbbr, SongId)` CAN be tripped, though: multi-book
       membership is this table's whole point, so the song may already have a
       (non-home) row in the TARGET book. Moving the old home row on top of it
       would abort the whole save on a duplicate key. So that case is detected
       and handled the other way round — delete the stale old-book home row and
       promote the row that is already in the target book. Both branches leave
       exactly one row in the target with IsHome=1 and none in the old book,
       which is what "the song moved" means for a junction whose old-book row
       was, by uq_book_song, necessarily the home row.

       Existence-gated (migrations are web-run; #1044 may not have been applied
       here) but NOT try/catch'd — the invariant is the point. */
    if (songRelocateTableExists($db, 'tblSongbookEntries')) {
        $tgt = $db->prepare('SELECT Id FROM tblSongbookEntries WHERE SongbookAbbr = ? AND SongId = ? LIMIT 1');
        $tgt->bind_param('ss', $targetAbbr, $newSongId);
        $tgt->execute();
        $tgtRow = $tgt->get_result()->fetch_assoc();
        $tgt->close();

        if ($tgtRow === null) {
            /* No membership row in the target yet — the home row simply moves. */
            $se = $db->prepare(
                'UPDATE tblSongbookEntries SET SongbookAbbr = ?, SongNumber = NULL
                  WHERE SongId = ? AND IsHome = 1'
            );
            $se->bind_param('ss', $targetAbbr, $newSongId);
            $se->execute();
            $se->close();
        } else {
            /* The song is already a member of the target book. Drop the row that
               made the OLD book its home first (so uq_book_song is free), then
               promote the existing target row. Order matters: promoting first
               would leave two IsHome=1 rows in flight. */
            $del = $db->prepare(
                'DELETE FROM tblSongbookEntries WHERE SongId = ? AND IsHome = 1 AND SongbookAbbr <> ?'
            );
            $del->bind_param('ss', $newSongId, $targetAbbr);
            $del->execute();
            $del->close();

            $promoteId = (int)$tgtRow['Id'];
            $pr = $db->prepare('UPDATE tblSongbookEntries SET IsHome = 1, SongNumber = NULL WHERE Id = ?');
            $pr->bind_param('i', $promoteId);
            $pr->execute();
            $pr->close();
        }
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
    } catch (\mysqli_sql_exception $e) {
        /* #1679 F8 — "best-effort" must not mean "swallow anything". Two MySQL
           errors do not fail just the STATEMENT, they roll back the ENTIRE
           InnoDB transaction — the caller's transaction, containing the move:
             1213  ER_LOCK_DEADLOCK      — deadlock found; the transaction was
                                           chosen as the victim and rolled back.
             1205  ER_LOCK_WAIT_TIMEOUT  — innodb_lock_wait_timeout elapsed;
                                           with innodb_rollback_on_timeout the
                                           whole transaction is rolled back.
           Catching those and continuing lets execution reach the caller's
           $db->commit(), which commits NOTHING and answers {ok:true,
           songId:<new>} for a song that no longer exists under that id. Re-throw
           so the caller rolls back and reports the failure it actually had.
           https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
           Anything else (a missing SongCount column on an old install, a
           permission oddity) really is cosmetic: log and let the move stand. */
        if (in_array((int)$e->getCode(), [1213, 1205], true)) { throw $e; }
        error_log('[songRelocate] SongCount recompute failed: ' . $e->getMessage());
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
