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
 * WHAT THE CASCADE ASSUMES — AND WHY THAT IS CHECKED (H3, then #1690)
 * ------------------------------------------------------------------
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
 * WHY THAT CHECK IS NOW PER SONG, AND SEES A *MISSING* FK (#1690)
 * --------------------------------------------------------------
 * The first version of that pre-check answered ONE schema-wide question and
 * memoised it: any non-CASCADE FK anywhere refused EVERY move. That is far
 * broader than the harm — `fk_media_song` being RESTRICT only endangers a song
 * that HAS a media row, and on a real catalogue most songs have none. So an
 * install with one stale constraint could not move a single song, and the only
 * remedy was a migration the operator might have deliberately not run. The
 * owner's decision (#1690, option A) is to refuse only when THIS song actually
 * has rows in the affected child table.
 *
 * It was also structurally BLIND to the opposite drift. A column with no FK at
 * all produces no `INFORMATION_SCHEMA` row, so "every row I found says CASCADE"
 * is trivially true on an install where `migrate-song-softref-fks.php` (#1064)
 * never ran — and that migration's columns (`tblSongRevisions.SongId`,
 * `tblSongRequests.ResolvedSongId`, `tblSongLinkSuggestionsDismissed.SongIdA/B`)
 * are exactly the ones a re-key silently STRANDS: no error, no rollback, just a
 * revision history that quietly stops belonging to its song. Absence of evidence
 * was read as evidence of absence. The fix needs a list of what SHOULD be there
 * — `SONG_RELOCATE_EXPECTED_SONGID_FKS`, derived from `schema.sql` and pinned to
 * it by `tests/php/test-song-relocate-cascade-verdict.php`.
 *
 * The check is therefore three layers, and only the middle one talks to MySQL:
 *   1. `songRelocateFkCatalogue()`  — the live FK + column catalogue, memoised
 *      once per request (a bulk move must not re-read `INFORMATION_SCHEMA` per
 *      song), fail-CLOSED on a probe error.
 *   2. `songRelocateCascadeGaps()`  — PURE: catalogue + expectations → the list
 *      of (table, column) pairs a re-key cannot carry.
 *   3. `songRelocateCascadeVerdict()` — PURE given an injected `$childHasRows`
 *      probe: gaps → `null` (move allowed) or the refusal sentence.
 * Layers 2 and 3 being pure is the point: the properties this pre-check exists
 * for can be CALLED in a test rather than read out of the source, which is how
 * the last four guards on this branch were defeated (`test-transaction-fatal.php`
 * documents the regress). On a healthy install the gap set is empty and the
 * per-move cost is ZERO extra queries.
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
 * "This environment cannot perform the move" — an ENVIRONMENT fault, never bad
 * user input (#1679 H3/A8).
 *
 * ELI5: a special kind of error meaning "the database here is missing a setting
 * a migration adds", so the editor can show the curator what to run instead of a
 * blank "Server error."
 *
 * Detail: it extends \RuntimeException on purpose, so the existing
 * RuntimeException-vs-InvalidArgumentException split is untouched — api2's 422
 * branch catches InvalidArgumentException specifically ("you named a book that
 * does not exist") and must still not report an un-migrated install as a typo.
 * The SUBCLASS is what lets the API layer recognise this refusal WITHOUT
 * regex-matching the sentence the helper wrote (rule #35: cross-file agreement
 * needs a mechanism; type / HTTP status is the contract, prose is not). That
 * matters here because the message IS the deliverable — it names the migration
 * card to run — and it has to survive being routed to a non-admin editor.
 */
class SongRelocateEnvironmentException extends \RuntimeException
{
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
 * am inside may already be gone" in mind. Copying a two-element error-code list
 * into ten catch blocks is precisely the "keep these in sync" comment rule #35
 * names as the failure rather than the fix — so the list lives here, once, and
 * every one of those catches opens with
 * `if (songRelocateIsTransactionFatal($e)) { throw $e; }`.
 *
 * THE TWO CODES, ACCURATELY
 * -------------------------
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
 *
 * https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
 * https://dev.mysql.com/doc/refman/8.0/en/innodb-parameters.html#sysvar_innodb_rollback_on_timeout
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
            && in_array((int)$e->getCode(), [1213, 1205], true)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Is this candidate SongId claimed by ANYTHING — the live corpus or a redirect?
 *
 * ELI5: before handing a song a new id, check that nobody is using it AND that
 * no old bookmark is still being forwarded away from it.
 *
 * WHY BOTH TABLES, AND WHY THIS IS SHARED (#1679 M1 / A9)
 * ------------------------------------------------------
 * `tblSongs` alone is not the claim set. `_bulkImport_nextSongNumberFor()` seeds
 * from `MAX(Number) + 1` and a move sets `Number = NULL`, so moving the
 * highest-numbered song OUT of a book frees its slot again — and the next mint in
 * that book can re-issue the exact id a live `tblSongRedirects` row still
 * forwards away from. `getSongById()` resolves an exact match BEFORE it ever
 * consults the redirect layer, so an old bookmark then gets 200 OK with a
 * COMPLETELY DIFFERENT SONG. Wrong content is worse than the 404 this feature set
 * out to remove.
 *
 * An earlier revision of this file put that reasoning in `songRelocateMintId()`'s
 * doc-block and called it "the ONE mint". It was not: `ed2_allocateSongId()`
 * (api2.php), `lyrics_ingest.php`'s create path, the `_bulkImport_*` importer
 * family and `migrate-backfill-canonical-songids.php` all issue ids as well, and
 * every one of them probed `tblSongs` only. The claim CHECK is therefore the
 * shared thing — the smallest unit that can drift (the rule #36 lesson) — rather
 * than the whole mint, which each site shapes differently (4-digit vs 6-digit
 * tails, per-file counters, dry-run ledgers).
 *
 * STRICT BY DESIGN: the redirect probe is `songRedirectsTableReady($db, true)`,
 * so a probe that FAILS throws instead of answering "no table" — a
 * false-because-the-probe-broke here silently switches the whole check off, which
 * is the exact failure shape this function exists to remove (#1679 A13b). Callers
 * are all about to issue a PERMANENT id, so refusing is recoverable and guessing
 * is not.
 *
 * Statements are prepared per call rather than hoisted out of a caller's loop:
 * those loops normally run once or twice, and a shared helper that hands prepared
 * statements back and forth would be a lifecycle bug waiting to happen.
 *
 * @param  \mysqli $db
 * @param  string  $songId Candidate id, e.g. `CP-0412`.
 * @return bool TRUE = taken; pick another.
 * @throws \RuntimeException when the redirect-table probe itself fails.
 */
function songRelocateIdTaken(\mysqli $db, string $songId): bool
{
    /* @deleted-visible: THE taken-probe (#1694) — a soft-deleted song's id IS
       taken: the row still exists and still holds the SongId UNIQUE key.
       "Helpfully" adding the visibility predicate here would make a mint
       believe the id free and then 500 on the duplicate key at INSERT time.
       test-song-soft-delete.php pins this with a recording double — the probe
       must recognise a soft-deleted row as taken.
       @disabled-visible: same reasoning, one predicate over (#1765) — a
       song's id stays taken regardless of whether its songbook has been
       disabled; this is a UNIQUE-key occupancy question, not a visibility
       one. */
    $live = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
    $live->bind_param('s', $songId);
    $live->execute();
    $held = $live->get_result()->fetch_row() !== null;
    $live->close();
    if ($held) { return true; }

    /* Strict: "I could not check" must never read as "nothing claims it". */
    if (!songRedirectsTableReady($db, true)) { return false; }

    $claim = $db->prepare('SELECT 1 FROM tblSongRedirects WHERE OldSongId = ? LIMIT 1');
    $claim->bind_param('s', $songId);
    $claim->execute();
    $claimed = $claim->get_result()->fetch_row() !== null;
    $claim->close();

    return $claimed;
}

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
 * Every foreign key `schema.sql` declares against `tblSongs(SongId)`, and the
 * migration that retro-fits it on an install that predates the declaration.
 *
 * ELI5: the list of everywhere in the database that points at a song's id. It is
 * what lets the move notice a link that is MISSING, not just one that is set up
 * wrongly — a missing one leaves no trace in the database's own catalogue, so
 * without this list there is nothing to compare against.
 *
 * SHAPE — one entry per FK, positional so 41 of them stay readable:
 *   [ child table, child column, constraint name, fix-migration slug | null ]
 *
 * The slug is the `manage/includes/migration-registry.php` key of the migration
 * that CREATES or CORRECTS that constraint, and `null` means "schema.sql is the
 * only place this is declared", i.e. an install missing it has drifted rather
 * than merely not run a card. Only two migrations touch this family:
 *   - `songid-prefix-fixup`  — retro-fits `ON UPDATE CASCADE` onto the four FKs
 *     that were created without it (#1679 H3).
 *   - `song-softref-fks`     — creates the four FKs that were soft references
 *     with no constraint at all (#1064).
 *
 * DELIBERATE ABSENCES, so a later reader does not "fix" them by adding a row:
 *  - `tblSongRedirects.OldSongId` is soft BY DESIGN. It is the address a moved
 *    song has LEFT, so an FK to the live corpus would be exactly wrong; the
 *    redirect layer plus `songRelocateIdTaken()` own that column's integrity.
 *  - `tblContentRestrictions.EntityId` is polymorphic `VARCHAR(50)` (it holds
 *    song ids, songbook abbreviations, …) and can never carry an FK. Step 5 of
 *    `songRelocate()` rewrites it by hand, fatally.
 *  - `tblSongbookEntries.SongbookAbbr` / `SongNumber` / `IsHome` are not
 *    references to `SongId` at all; step 5b rewrites them.
 * All three are covered elsewhere in this file — they are not gaps, so listing
 * them here would refuse moves for a hazard that is already handled.
 *
 * DERIVED, NOT TYPED (rule #34). `tests/php/test-song-relocate-cascade-verdict.php`
 * parses `schema.sql` and asserts this const equals what it finds, so a new FK
 * added to the schema without a line here fails CI rather than becoming a
 * silently un-checked stranding path.
 *
 * @see appWeb/.sql/schema.sql
 * @see https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 */
const SONG_RELOCATE_EXPECTED_SONGID_FKS = [
    ['tblSongbookEntries',             'SongId',          'fk_SongbookEntries_Song', null],
    ['tblSongWriters',                 'SongId',          'fk_Writers_Song',         null],
    ['tblSongComposers',               'SongId',          'fk_Composers_Song',       null],
    ['tblSongArrangers',               'SongId',          'fk_Arrangers_Song',       null],
    ['tblSongAdaptors',                'SongId',          'fk_Adaptors_Song',        null],
    ['tblSongTranslators',             'SongId',          'fk_Translators_Song',     null],
    ['tblSongArtists',                 'SongId',          'fk_Artists_Song',         null],
    ['tblSongComponents',              'SongId',          'fk_Components_Song',      null],
    ['tblLyrics',                      'SongId',          'fk_Lyrics_Song',          null],
    ['tblUserFavorites',               'SongId',          'fk_Favorites_Song',       null],
    ['tblSongTranslations',            'SourceSongId',    'fk_Trans_Source',         null],
    ['tblSongTranslations',            'TranslatedSongId','fk_Trans_Target',         null],
    ['tblSongRequests',                'ResolvedSongId',  'fk_Requests_ResolvedSong','song-softref-fks'],
    ['tblSongRequests',                'SongId',          'fk_Requests_TargetSong',  null],
    ['tblSongKeys',                    'SongId',          'fk_SongKeys_Song',        null],
    ['tblSongRevisions',               'SongId',          'fk_Revisions_Song',       'song-softref-fks'],
    ['tblSongHistory',                 'SongId',          'fk_History_Song',         null],
    ['tblSongTagMap',                  'SongId',          'fk_TagMap_Song',          null],
    ['tblSongLinks',                   'SongId',          'fk_SongLinks_Song',       null],
    ['tblSongRedirects',               'NewSongId',       'fk_SongRedirect_New',     null],
    ['tblSongLinkSuggestions',         'SongIdA',         'fk_SongLinkSugg_A',       null],
    ['tblSongLinkSuggestions',         'SongIdB',         'fk_SongLinkSugg_B',       null],
    ['tblSongLinkSuggestionsDismissed','SongIdA',         'fk_DismissedSugg_A',      'song-softref-fks'],
    ['tblSongLinkSuggestionsDismissed','SongIdB',         'fk_DismissedSugg_B',      'song-softref-fks'],
    ['tblCatalogueSongs',              'SongId',          'fk_CatalogueSongs_Song',  null],
    ['tblSongExternalLinks',           'SongId',          'fk_link_song',            'songid-prefix-fixup'],
    ['tblSongAlternativeTitles',       'SongId',          'fk_alt_song',             'songid-prefix-fixup'],
    ['tblSongLanguages',               'SongId',          'fk_slang_song',           null],
    ['tblSongMedia',                   'SongId',          'fk_media_song',           'songid-prefix-fixup'],
    ['tblWorkSongs',                   'SongId',          'fk_work_song_song',       'songid-prefix-fixup'],
    ['tblSongArrangements',            'SongId',          'fk_SongArrangements_Song',null],
    ['tblLyricsConflicts',             'SongId',          'fk_Conflict_Song',        null],
    ['tblLyricsReviewQueue',           'SongId',          'fk_LyricsQueue_Song',     null],
    ['tblSongIdentityMap',             'SongId',          'fk_IdentityMap_Song',     null],
    ['tblSongUsageEvents',             'SongId',          'fk_Usage_Song',           null],
    ['tblSongQualityFindings',         'SongId',          'fk_QualityFinding_Song',  null],
    ['tblSongEmbeddings',              'SongId',          'fk_Embedding_Song',       null],
    ['tblLiveFollowSessions',          'CurrentSongId',   'fk_LiveFollow_Song',      null],
    ['tblSongScriptureRefs',           'SongId',          'fk_ScriptureRefs_Song',   null],
    ['tblSongRoyaltyIds',              'SongId',          'fk_RoyaltyIds_Song',      null],
    ['tblPresentationThemeAssignments','SongId',          'fk_PresAssign_Song',      null],
    ['tblSongExternalIds',             'SongId',          'fk_SongExternalIds_Song', null],
    ['tblSongCopyrightHolders',        'SongId',          'fk_CopyHolders_Song',     null],
    ['tblSongPresentationCues',        'SongId',          'fk_pres_cues_song',       null],
    ['tblUserSongMarkup',              'SongId',          'fk_UserMarkup_Song',      null],
];

/**
 * The two migration cards a cascade refusal can point at.
 *
 * ELI5: the refusal's whole value is telling the curator which button to press,
 * so the button's exact wording and the file behind it live here once.
 *
 * Detail: the titles are the `card.title` strings in
 * `manage/includes/migration-registry.php`, and `tests/php/test-song-relocate-cascade-verdict.php`
 * loads that registry and asserts both halves still agree. That is the mechanism
 * rule #35 asks for — a comment reading "keep this in step with the registry"
 * would be the failure, not the fix, and a reworded card title would otherwise
 * send the curator hunting for a button that no longer exists.
 */
const SONG_RELOCATE_FIX_MIGRATIONS = [
    'songid-prefix-fixup' => [
        'card'   => 'Re-prefix SongIds whose SongbookAbbr no longer matches',
        'script' => 'appWeb/.sql/migrate-songid-prefix-fixup.php',
    ],
    'song-softref-fks' => [
        'card'   => 'Harden soft SongId references (FKs) (#1064)',
        'script' => 'appWeb/.sql/migrate-song-softref-fks.php',
    ],
];

/**
 * The expected FKs a given migration is responsible for — PURE.
 *
 * ELI5: "which of these links does THIS migration create or repair?" — so the
 * migration's own dashboard card can tell whether it still has work to do.
 *
 * WHY THIS IS SHARED RATHER THAN RE-TYPED IN THE PROBE (#1690, rule #35)
 * ---------------------------------------------------------------------
 * `manage/includes/migration-registry.php`'s pendency probes need exactly these
 * names, and the failure this fixes was caused by them being derived somewhere
 * else. `songid-prefix-fixup`'s probe asked only about a PREFIX MISMATCH, so an
 * install with clean prefixes but RESTRICT FKs showed the card as already
 * applied, "Apply all pending" skipped it, and the migration whose STEP 1 would
 * have fixed those very FKs was never offered — while the move refused. A probe,
 * a refusal and a migration that must agree about four constraint names need a
 * mechanism, not three copies and a comment.
 *
 * The migration SCRIPT keeps its own standalone list, deliberately: it must be
 * runnable from the CLI on an install where nothing else is loaded. That copy is
 * asserted to be a subset of this const by
 * `tests/php/test-song-relocate-cascade-verdict.php`.
 *
 * @param  string $slug A `migration-registry.php` key, e.g. 'song-softref-fks'.
 * @return list<array{0:string,1:string,2:string,3:?string}>
 */
function songRelocateFksFixedBy(string $slug): array
{
    $out = [];
    foreach (SONG_RELOCATE_EXPECTED_SONGID_FKS as $entry) {
        if ($entry[3] === $slug) { $out[] = $entry; }
    }
    return $out;
}

/**
 * The lookup key for a (table, column) pair — PURE.
 *
 * ELI5: one agreed way of writing "this column of this table down", so two
 * pieces of code comparing the same column always spell it the same.
 *
 * Detail: MySQL column names are case-insensitive and table names may or may not
 * be, depending on `lower_case_table_names` and the host filesystem. Folding
 * both to lower case here means an `INFORMATION_SCHEMA` row and a
 * `SONG_RELOCATE_EXPECTED_SONGID_FKS` entry that differ only in capitalisation
 * still MATCH — otherwise a case-folding server would report every expected FK
 * as missing and refuse every move on a perfectly healthy install. Extracted as
 * a function rather than written out at three call sites precisely so the two
 * sides cannot drift (rule #35).
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/identifier-case-sensitivity.html
 */
function songRelocateFkKey(string $table, string $column): string
{
    return strtolower($table) . '.' . strtolower($column);
}

/**
 * Is this identifier safe to interpolate between backticks? — PURE.
 *
 * ELI5: the child-row probe has to name a table in the query text (you cannot
 * bind a table name to a `?`), so first check the name is boring.
 *
 * Detail: CLAUDE.md rule #5 allows exactly two kinds of interpolation into SQL —
 * (a) hardcoded constants from PHP source, and (b) values validated against an
 * exact allow-list. Identifiers taken from `SONG_RELOCATE_EXPECTED_SONGID_FKS`
 * are clause (a): they are literals in this file, unreachable by any request.
 * Identifiers taken from `INFORMATION_SCHEMA` for an UNKNOWN live FK are clause
 * (b): they are server metadata rather than user input, but "not user input" is
 * not a licence to concatenate, so they are charset-validated to
 * `^[A-Za-z0-9_$]+$` (the unquoted-identifier set MySQL itself documents) and
 * then backticked. A name that fails is not passed through and not queried — the
 * caller fails CLOSED, which is the same posture as an unreadable catalogue.
 *
 * ⚠ THE REGEX ITSELF MOVED (#1698). The account-erasure core needs this exact
 * property for the exact same reason — it enumerates the FKs referencing
 * `tblUsers(Id)` out of `INFORMATION_SCHEMA` and then writes
 * `DELETE FROM \`<that table>\`` — so per the modularity rule the second
 * occurrence is an extraction, not a copy. The one implementation now lives in
 * `includes/sql_identifier.php` as `ihymnsSqlIdentifierSafe()`; this function
 * keeps its name and its behaviour because `test-song-relocate-cascade-verdict.php`
 * calls it by name and asserts its exact truth table (including the `\z` case
 * below). Behaviour is unchanged — the body is a delegation, nothing more.
 *
 * `\z`, NOT `$`. PCRE's `$` also matches immediately BEFORE a final newline, so
 * `"tblSongs\n"` passed the first version of this function — caught by
 * `tests/php/test-song-relocate-cascade-verdict.php` on its very first run. The
 * consequence there is a broken query rather than an injection, but "the value I
 * validated is not the value I interpolated" is the whole property this function
 * claims, and a validator that is not exact does not have it. `D` (PCRE_DOLLAR_ENDONLY)
 * would do the same job; `\z` says so at the point it matters.
 *
 * @see appWeb/public_html/includes/sql_identifier.php
 * @see https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
 * @see https://www.php.net/manual/en/regexp.reference.anchors.php
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sql_identifier.php';

function songRelocateIdentifierSafe(string $identifier): bool
{
    return ihymnsSqlIdentifierSafe($identifier);
}

/**
 * The distinct child tables the expectations name — PURE.
 *
 * ELI5: "which tables do I need to ask the database about?", with duplicates
 * removed (a table can hold two of these columns).
 *
 * Detail: extracted from `songRelocateFkCatalogue()` so it can be CALLED by a
 * test. Everything else in that function needs a live mysqli, which CI does not
 * have — so without this split the list-building, the de-duplication and the
 * `array_fill()` placeholder count would ship with no execution behind them at
 * all, and a typo would first be discovered on a real install mid-save.
 *
 * @return list<string>
 */
function songRelocateExpectedTables(): array
{
    $tables = [];
    foreach (SONG_RELOCATE_EXPECTED_SONGID_FKS as [$table, , , ]) { $tables[$table] = true; }
    return array_keys($tables);
}

/**
 * The live FK + column catalogue for `tblSongs(SongId)`, memoised per request.
 *
 * ELI5: ask the database once "what actually points at a song's id here, and
 * which of the columns I expect to exist really do?", and remember the answer
 * for the rest of this request.
 *
 * Detail: two `INFORMATION_SCHEMA` reads, both scoped to `DATABASE()`.
 *  - The FK read is the one `migrate-backfill-canonical-songids.php` performs
 *    before ITS mass re-key. `REFERENTIAL_CONSTRAINTS` carries `UPDATE_RULE` but
 *    not the referenced COLUMN, so `KEY_COLUMN_USAGE` is joined in to keep this
 *    to FKs that really point at `SongId`. `k.COLUMN_NAME` is selected as well as
 *    `k.TABLE_NAME` (#1690) — without the child COLUMN there is no way to ask
 *    "does THIS song have rows behind that constraint", and no way to line a live
 *    row up against an expectation.
 *  - The COLUMN read answers "does this expected (table, column) pair exist at
 *    all?". It is what stops a MISSING FK on a table this install has never
 *    created from reading as a gap: a table with no rows cannot strand any.
 *
 * MEMOISED INCLUDING THE FAILURE, deliberately, and this is the opposite choice
 * from `songRedirectsTableReady()` eight functions up — the reasoning is worth
 * spelling out because the two look inconsistent. There, a failed probe answers
 * "no redirect table", which silently switches a check OFF; memoising that would
 * disable the check for the whole request, so failures are re-probed. Here a
 * failed probe REFUSES the move, so memoising is fail-closed and merely makes a
 * bulk move refuse uniformly instead of retrying an `INFORMATION_SCHEMA` read
 * that just failed, once per song, inside the caller's transaction.
 *
 * @return array{error:?string, fks:list<array<string,mixed>>, columns:array<string,bool>}
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-referential-constraints-table.html
 * @see https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */
function songRelocateFkCatalogue(\mysqli $db): array
{
    static $catalogue = null;
    if ($catalogue !== null) { return $catalogue; }

    try {
        $stmt = $db->prepare(
            "SELECT rc.CONSTRAINT_NAME, rc.UPDATE_RULE, k.TABLE_NAME, k.COLUMN_NAME
               FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.REFERENCED_TABLE_NAME = 'tblSongs'
                AND k.REFERENCED_COLUMN_NAME = 'SongId'"
        );
        $stmt->execute();
        $fks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* The distinct tables the expectations name. Placeholders are built from
           a COUNT of PHP-source constants, never from anything a request can
           reach — rule #5's `array_fill(0, count($values), '?')` idiom. */
        $tables       = songRelocateExpectedTables();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $colStmt = $db->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})"
        );
        bindParamSafe(
            'songRelocateFkCatalogue COLUMNS',
            $colStmt,
            str_repeat('s', count($tables)),
            ...$tables
        );
        $colStmt->execute();
        $columns = [];
        foreach ($colStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            $columns[songRelocateFkKey((string)$c['TABLE_NAME'], (string)$c['COLUMN_NAME'])] = true;
        }
        $colStmt->close();

        $catalogue = ['error' => null, 'fks' => $fks, 'columns' => $columns];
    } catch (\Throwable $e) {
        /* Fail CLOSED, exactly as the migration does. A move we cannot verify is
           a move that may silently orphan children; refusing is recoverable, an
           orphaned graph is not. */
        $catalogue = ['error' => $e->getMessage(), 'fks' => [], 'columns' => []];
    }

    return $catalogue;
}

/**
 * Which (table, column) pairs can a re-key NOT carry on this install? — PURE.
 *
 * ELI5: compare "what the database really has" against "what it should have",
 * and hand back the places where a rename would break something. No database
 * access, so a test can hand it a made-up install and check the answer.
 *
 * TWO KINDS OF GAP, and the second one is why this function exists (#1690):
 *  - `no-cascade`  — a live FK whose `UPDATE_RULE` is not `CASCADE`. The re-key
 *    then throws `ER_ROW_IS_REFERENCED_2` (1451) mid-transaction on RESTRICT /
 *    NO ACTION, or — worse, because it SUCCEEDS — orphans the children on
 *    SET NULL. Reported whether or not the constraint is one we expected, since
 *    a constraint nobody listed still breaks the same statement.
 *  - `missing-fk`  — an EXPECTED constraint with no live FK row at all, on a
 *    table and column that DO exist here. The old check could not see this: a
 *    column with no FK produces no `INFORMATION_SCHEMA` row, so "everything I
 *    found cascades" was vacuously true and the re-key stranded every row in it
 *    silently. Gated on the pair existing so an install that simply never
 *    created the table is not accused of drift — a table that is not there holds
 *    no rows to strand.
 *
 * Matching is on (table, column), NOT on the constraint NAME. A constraint can be
 * dropped and re-added under a different name — MySQL auto-names one `<tbl>_ibfk_1`
 * if you do not — and what the re-key actually depends on is that the COLUMN has
 * a cascading FK, whatever it is called. Name-matching would report a correctly
 * cascading column as missing.
 *
 * @param  list<array<string,mixed>> $liveFkRows   `songRelocateFkCatalogue()['fks']`
 * @param  array<string,bool>        $liveColumns  `songRelocateFkCatalogue()['columns']`
 * @param  list<array{0:string,1:string,2:string,3:?string}> $expected
 * @return list<array{table:string, column:string, constraint:string, kind:string, rule:string, fix:?string, known:bool}>
 */
function songRelocateCascadeGaps(array $liveFkRows, array $liveColumns, array $expected): array
{
    /* Index the expectations by (table, column) so a live row can find its own
       entry — that is where the fix-migration slug and the CONSTANT spelling of
       the identifiers come from. */
    $expectedByKey = [];
    foreach ($expected as [$table, $column, $constraint, $fix]) {
        $expectedByKey[songRelocateFkKey($table, $column)] = [$table, $column, $constraint, $fix];
    }

    $gaps    = [];
    $seenKey = [];

    foreach ($liveFkRows as $row) {
        $liveTable  = (string)($row['TABLE_NAME'] ?? '');
        $liveColumn = (string)($row['COLUMN_NAME'] ?? '');
        if ($liveTable === '' || $liveColumn === '') { continue; }

        $key           = songRelocateFkKey($liveTable, $liveColumn);
        $seenKey[$key] = true;

        if (strtoupper((string)($row['UPDATE_RULE'] ?? '')) === 'CASCADE') { continue; }

        $known = isset($expectedByKey[$key]);
        $gaps[] = [
            /* IDENTIFIER PROVENANCE (rule #5): when the pair is one we expect,
               take the names from the CONST — clause (a), a literal in this
               file — rather than echoing back what the server said. Only a
               constraint nobody listed falls through to the I_S spelling, and
               `known => false` is what tells the probe to charset-validate it. */
            'table'      => $known ? $expectedByKey[$key][0] : $liveTable,
            'column'     => $known ? $expectedByKey[$key][1] : $liveColumn,
            'constraint' => (string)($row['CONSTRAINT_NAME'] ?? ''),
            'kind'       => 'no-cascade',
            'rule'       => strtoupper((string)($row['UPDATE_RULE'] ?? '')),
            'fix'        => $known ? $expectedByKey[$key][3] : null,
            'known'      => $known,
        ];
    }

    foreach ($expected as [$table, $column, $constraint, $fix]) {
        $key = songRelocateFkKey($table, $column);
        if (isset($seenKey[$key]))       { continue; }  // the FK is live — fine, or already reported
        if (!isset($liveColumns[$key]))  { continue; }  // no such table/column here — nothing to strand
        $gaps[] = [
            'table'      => $table,
            'column'     => $column,
            'constraint' => $constraint,
            'kind'       => 'missing-fk',
            'rule'       => '',
            'fix'        => $fix,
            'known'      => true,
        ];
    }

    return $gaps;
}

/**
 * Do any of those gaps actually block THIS song? — PURE, given the probe.
 *
 * ELI5: a broken link only matters if the song has something on the other end of
 * it. Ask, one gap at a time; if the song has nothing there, the move is fine.
 *
 * THIS IS #1690 OPTION A. The version it replaces refused EVERY move on an
 * install with one stale constraint, which is far broader than the harm: a
 * RESTRICT `fk_media_song` cannot break the re-key of a song with no media row.
 * The consequence of the old shape was that a single un-run migration froze the
 * whole feature, and the operator's only remedy was a migration they may have
 * had good reason not to run. Now the environment fault still refuses — but only
 * the songs it can actually damage.
 *
 * WITHIN A BULK MOVE, SOME SONGS MAY PROCEED WHILE OTHERS REFUSE. That is the
 * point, and it replaces the old note promising the opposite ("a refusal is
 * memoised too, so every song in the batch fails the same way"). The CATALOGUE
 * is still memoised, so the cost of the environment check is unchanged; only the
 * verdict is per song, and only when a gap exists at all.
 *
 * FAIL CLOSED, twice over:
 *  - a probe that THROWS counts as "the song has rows" — an unverifiable move
 *    must not proceed, which is the same posture as an unreadable catalogue;
 *  - EXCEPT for a transaction-fatal error (`songRelocateIsTransactionFatal()`),
 *    which is re-thrown. By this point InnoDB may have rolled the CALLER's
 *    transaction back, and swallowing that is how a save reports `ok:true` for
 *    work that no longer exists — the exact false success #1679 F8/A1 removed.
 *    Treating it as "fail closed" would be worse than useless: the caller would
 *    catch a refusal, roll back nothing, and carry on.
 *
 * @param  list<array<string,mixed>> $gaps         from `songRelocateCascadeGaps()`
 * @param  callable(string,string):bool $childHasRows  (table, column) → does this song have rows?
 * @return string|null NULL = the move may proceed; a string = the refusal message.
 * @throws \Throwable re-thrown when the probe failure is transaction-fatal.
 */
function songRelocateCascadeVerdict(array $gaps, callable $childHasRows): ?string
{
    if ($gaps === []) { return null; }

    $blocking = [];
    foreach ($gaps as $gap) {
        try {
            $hasRows = (bool)$childHasRows((string)$gap['table'], (string)$gap['column']);
        } catch (\Throwable $e) {
            if (songRelocateIsTransactionFatal($e)) { throw $e; }
            $hasRows = true;
        }
        if ($hasRows) { $blocking[] = $gap; }
    }

    if ($blocking === []) { return null; }

    $details = [];
    $slugs   = [];
    $drifted = false;
    foreach ($blocking as $gap) {
        $details[] = $gap['kind'] === 'missing-fk'
            ? $gap['table'] . '.' . $gap['column'] . ' (no foreign key at all — '
              . $gap['constraint'] . ' is missing)'
            : $gap['table'] . '.' . $gap['column'] . ' (' . $gap['constraint']
              . ' is ON UPDATE ' . $gap['rule'] . ')';

        if ($gap['fix'] !== null && isset(SONG_RELOCATE_FIX_MIGRATIONS[$gap['fix']])) {
            $slugs[$gap['fix']] = true;
        } else {
            $drifted = true;
        }
    }

    /* The MESSAGE is the deliverable — this refusal fires on real installs, and
       one that does not say what to run is no more actionable than the raw
       ER_ROW_IS_REFERENCED_2 it replaces (#1679 H3). It travels to every role in
       `error_hint`, so it has to be readable by a non-admin editor. */
    $actions = [];
    foreach (array_keys($slugs) as $slug) {
        $actions[] = 'Run "' . SONG_RELOCATE_FIX_MIGRATIONS[$slug]['card'] . '" ('
                   . SONG_RELOCATE_FIX_MIGRATIONS[$slug]['script'] . ') on /manage/setup-database';
    }
    if ($drifted) {
        $actions[] = 'Reconcile the remaining constraint(s) against appWeb/.sql/schema.sql '
                   . '(/manage/schema-audit lists the divergences)';
    }

    return 'songRelocate: this song has rows behind ' . count($blocking) . ' foreign key(s) to '
         . 'tblSongs(SongId) that cannot carry a re-key, so moving it would fail mid-save or '
         . 'silently orphan those rows — ' . implode('; ', array_slice($details, 0, 20)) . '. '
         . implode('. ', $actions) . ', then move the song again.';
}

/**
 * The real `$childHasRows` probe: does THIS song have rows in that child column?
 *
 * ELI5: one small "is there anything here?" query per broken link, asked only
 * about the song being moved.
 *
 * Detail: `SELECT 1 … LIMIT 1`, so MySQL stops at the first hit rather than
 * counting. Only ever called for a gap, so on a healthy install this closure is
 * built and never invoked — the per-move cost of the whole pre-check stays zero
 * extra queries.
 *
 * The identifiers cannot be bound (`?` binds VALUES, not table or column names),
 * so they are validated by `songRelocateIdentifierSafe()` and backticked. A name
 * that fails validation answers TRUE — "assume the song has rows" — because the
 * one thing we must not do is let an unverifiable pair look harmless. See that
 * function for which of rule #5's two interpolation clauses applies to which
 * kind of identifier.
 *
 * @param  string $songId The song's CURRENT id — children still reference it.
 * @return callable(string,string):bool
 */
function songRelocateChildRowProbe(\mysqli $db, string $songId): callable
{
    return static function (string $table, string $column) use ($db, $songId): bool {
        if (!songRelocateIdentifierSafe($table) || !songRelocateIdentifierSafe($column)) {
            return true;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` = ? LIMIT 1'
        );
        bindParamSafe('songRelocateChildRowProbe', $stmt, 's', $songId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    };
}

/**
 * REFUSE this song's move when a foreign key referencing `tblSongs(SongId)`
 * cannot carry the re-key AND this song has rows behind it (#1679 H3, #1690).
 *
 * ELI5: renaming the song's id only drags its verses, media and links along if
 * the database was told to follow. Some of those links are missing that setting
 * on older installs — and some are missing entirely — so check first, and say
 * exactly what to run, rather than letting the save blow up half-way and lose
 * the curator's work. Only the songs that really have something at risk are
 * stopped.
 *
 * Detail: the re-key is ONE `UPDATE tblSongs SET SongId = ?`; every child row
 * follows only because of `ON UPDATE CASCADE`. A `RESTRICT`/`NO ACTION` child FK
 * makes that statement throw `ER_ROW_IS_REFERENCED_2` (1451), and because
 * `songRelocate()` runs inside the CALLER's transaction the whole save is rolled
 * back — the curator sees "Failed to save song" and has no way to learn that the
 * cause is an un-applied migration. `SET NULL` would be worse: it succeeds and
 * orphans the children. A column with NO FK is worse again: it succeeds, orphans
 * the children, and leaves nothing in `INFORMATION_SCHEMA` to have warned you.
 *
 * This function is the thin wiring; the reasoning lives in the three layers it
 * composes (see the file doc-block). It keeps only two things of its own: the
 * probe-failure refusal, and the `throw` — both funnels recognise
 * `SongRelocateEnvironmentException` by TYPE to copy its message into an ungated
 * `error_hint`, so the throw must stay here where the caller can see it.
 *
 * @param  \mysqli $db
 * @param  string  $songId The song's CURRENT SongId (children reference it, not
 *                         the id it is about to be given).
 * @return void
 * @throws SongRelocateEnvironmentException naming the offending constraint(s)
 *         and the migration card that fixes them.
 */
function songRelocateAssertCascades(\mysqli $db, string $songId): void
{
    $catalogue = songRelocateFkCatalogue($db);

    if ($catalogue['error'] !== null) {
        throw new SongRelocateEnvironmentException(
            'songRelocate: could not verify the ON UPDATE CASCADE foreign keys on '
            . 'tblSongs(SongId) — ' . $catalogue['error']
            . '. Refusing to move the song; re-try once the database is reachable.'
        );
    }

    $gaps = songRelocateCascadeGaps(
        $catalogue['fks'],
        $catalogue['columns'],
        SONG_RELOCATE_EXPECTED_SONGID_FKS
    );
    /* The healthy path: no gaps, so no per-song probe is even built. */
    if ($gaps === []) { return; }

    $verdict = songRelocateCascadeVerdict($gaps, songRelocateChildRowProbe($db, $songId));
    if ($verdict !== null) {
        /* A RuntimeException SUBCLASS, not InvalidArgumentException: this is an
           ENVIRONMENT fault, not bad user input, so api2's 422 branch (which
           catches InvalidArgumentException specifically) must NOT claim the
           curator typed the wrong book.

           #1679 A8 — an earlier revision of this comment claimed the message
           "surfaces through the v2 API's top-level handler as a 500 whose
           `error_detail` — admin-only, and the editor is admin-only — carries
           this sentence verbatim". That was FALSE in both halves: api2.php gates
           entry on the `editor` role but computes $ed2IsAdmin from the `admin`
           role, and emits `error_detail` only for an admin — so the plain
           `editor` who is the likeliest person to hit this saw a bare "Server
           error.", exactly as uninformative as the raw ER_ROW_IS_REFERENCED_2
           the refusal replaced. The refusal now travels in its own `error_hint`
           key, emitted for EVERY role on both the v2 and the legacy funnel, and
           recognised by TYPE rather than by matching this prose. The message is
           still the deliverable — it names the migration card to run — so it has
           to reach the person who ran the save, not only an admin. */
        throw new SongRelocateEnvironmentException($verdict);
    }
}

/**
 * Mint a free canonical `<Abbr>-NNNN` SongId for a songbook.
 *
 * ELI5: "what's the next free slot in this book?" — and if something already
 * took it, keep counting until we find one that's free.
 *
 * Detail: this is the mint the EDITOR uses — for a #1380 draft-id promotion
 * (`manage/editor/save_song_core.php`) and for a #1679 move — so those two agree
 * by construction rather than by two copies of a loop happening to match (the
 * copy that was inlined in the save core is gone; CLAUDE.md modularity rule). The
 * seed comes from `_bulkImport_nextSongNumberFor()` so the importers agree on the
 * starting slot too.
 *
 * IT IS **NOT** THE ONLY MINT — that claim was here and was wrong (#1679 A9).
 * `ed2_allocateSongId()` (api2.php, 6-digit tail for numberless songs),
 * `lyrics_ingest.php`'s create path, the `_bulkImport_*` importer family and
 * `appWeb/.sql/migrate-backfill-canonical-songids.php` all issue ids as well,
 * each with a differently-shaped loop. What they now SHARE is the claim check,
 * `songRelocateIdTaken()` — which is the part that was actually wrong in all of
 * them (see that function for the "a redirect claims ids too" reasoning, and this
 * pass's report for which sites adopted it).
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

    /* The claim check is the SHARED one (#1679 A9) — live corpus OR redirect.
       It replaced a private pair of prepared statements here whose redirect half
       was gated on the SWALLOWING `songRedirectsTableReady($db)`: a probe that
       failed for any reason answered "no table", which silently disabled the M1
       check on the one path (a draft promotion via save_song_core.php) that does
       not even get the cascade pre-check first. The shared helper probes
       strictly, so an unanswerable probe now aborts the mint instead (A13b). */
    while (songRelocateIdTaken($db, $candidate)) {
        $n++;
        $candidate = sprintf('%s-%04d', $abbr, $n);
    }

    return [$candidate, $n];
}

/**
 * Step 5 of a move — carry the song's CONTENT RESTRICTIONS to its new id
 * (#1679 M3, extracted #1691 D3/D5).
 *
 * ELI5: rules like "only licensed members may read this song" are filed under
 * the song's id. The move gives the song a new id, so the filing must move too
 * — a rule left under the old id silently stops applying.
 *
 * WHY THIS IS ITS OWN FUNCTION — A RUNTIME HANDLE, NOT REUSE (#1691 D3/D5)
 * ------------------------------------------------------------------------
 * As an inline block inside songRelocate() the two properties that matter here
 * had only source-inspection guards, and an adversarial pass defeated both:
 * the "writes are inside the existence gate" check ignored the gate's POLARITY
 * (inverting the `if` so the block ran only when the table was ABSENT stayed
 * green), and nothing pinned WHICH probe the gate asks — swapping the strict
 * `songRelocateTableExists()` for the swallowing `songRedirectsTableReady()`
 * would "silently skip the content-restriction rewrite, i.e. turn withheld
 * content readable" (this file's own words) with the suite green. Giving the
 * gate a boolean return puts both properties where a test can CALL them:
 * `tests/php/test-song-relocate-gates.php` drives this function with a
 * recording mysqli double and asserts table-absent → FALSE with no UPDATE
 * issued, table-present → TRUE with exactly the expected UPDATE, and
 * probe-failure → the throw PROPAGATES (which the swallowing probe cannot
 * satisfy). It also removes the choice of probe from every call site — the
 * structural fix `test-optional-table-probes.php`'s tail note asked for:
 * "reach the gate through one named helper, so there is no call site left to
 * swap".
 *
 * DELIBERATELY FATAL (#1679 M3) — no try/catch on this path, here or around
 * the call. This used to be wrapped in `try { … } catch (\Throwable) {
 * error_log(…); }`, on the reasoning that a cache-ish follow-up must never
 * roll back a legitimate curation action. That reasoning does not survive
 * reading what the row IS: `checkContentAccess()` looks EntityId up directly
 * and never follows redirects, so a restriction left on a dead id stops
 * applying — withheld content silently becomes readable and the only trace is
 * error_log. A move that cannot carry its restrictions must not commit; the
 * caller's transaction rolls it back.
 *
 * @param  \mysqli $db
 * @param  string  $oldSongId The id the restriction rows still name.
 * @param  string  $newSongId The id they must name once the move commits.
 * @return bool TRUE = the rewrite ran; FALSE = tblContentRestrictions does not
 *              exist on this install (nothing to carry — a minimal install has
 *              no restrictions to lose).
 * @throws \mysqli_sql_exception when the probe or the UPDATE fails — the
 *         caller's transaction must roll the move back, never commit without
 *         the rewrite.
 */
function songRelocateRewriteRestrictions(\mysqli $db, string $oldSongId, string $newSongId): bool
{
    /* ELI5: if this install never created the restrictions table, there is
       nothing to carry — say so and stop.
       Detail: the table is optional (migrations are web-run) and mysqli STRICT
       throws on a missing table (the #1228 lesson) — so the write is gated on
       the STRICT probe, which re-throws a probe failure rather than answering
       "absent". A false-because-the-probe-broke would skip the one
       security-relevant step of the move. The early return IS the gate: the
       UPDATE below is unreachable unless the probe answered "present". */
    if (!songRelocateTableExists($db, 'tblContentRestrictions')) { return false; }

    /* ELI5: re-file every "who may see this song" rule under the new id.
       Detail: EntityId is polymorphic VARCHAR with no FK (schema.sql), so the
       step-4 cascade cannot reach it; parameter ORDER is load-bearing — SET to
       the NEW id, matched on the OLD one. The recording double in
       test-song-relocate-gates.php pins that order behaviourally. */
    $rs = $db->prepare(
        "UPDATE tblContentRestrictions SET EntityId = ? WHERE EntityType = 'song' AND EntityId = ?"
    );
    $rs->bind_param('ss', $newSongId, $oldSongId);
    $rs->execute();
    $rs->close();

    return true;
}

/**
 * Step 5b of a move — re-home the song's tblSongbookEntries junction row
 * (#1679 F3, extracted #1691 D3/D5 — same reasoning as
 * songRelocateRewriteRestrictions() above: the gate's polarity and probe
 * identity needed a runtime handle, so the gate became an early return whose
 * boolean a test can assert).
 *
 * ELI5: the membership table still says the song's home is the book it just
 * left. Point the home row at the new book — and if the song was already a
 * member of the new book, promote that row instead of colliding with it.
 *
 * Detail: `tblSongbookEntries.SongId` cascaded with everything else in step 4,
 * but `SongbookAbbr` / `SongNumber` / `IsHome` are not reachable from a SongId
 * change, so without this the home row reads "(old book, NEW id, old number,
 * IsHome=1)": the junction claims the song's home is the book it just left,
 * and `uq_book_number` keeps the vacated slot occupied in a book the song is
 * no longer in. The table is documented "not yet read by the app", which makes
 * the damage LATENT rather than harmless — once something does read it there
 * is no way to tell a stale home entry from a genuine multi-book membership.
 *
 * `SongNumber` follows `tblSongs.Number` to NULL (the move policy in the file
 * doc-block); multiple NULLs coexist happily under a MySQL UNIQUE index, so
 * `uq_book_number` cannot be tripped by the clear.
 * https://dev.mysql.com/doc/refman/8.0/en/create-index.html
 *
 * `uq_book_song (SongbookAbbr, SongId)` CAN be tripped, though: multi-book
 * membership is this table's whole point, so the song may already have a
 * (non-home) row in the TARGET book. Moving the old home row on top of it
 * would abort the whole save on a duplicate key. So that case is detected and
 * handled the other way round — delete the stale old-book home row and promote
 * the row that is already in the target book. Both branches leave exactly one
 * row in the target with IsHome=1 and none in the old book, which is what "the
 * song moved" means for a junction whose old-book row was, by uq_book_song,
 * necessarily the home row.
 *
 * Existence-gated (migrations are web-run; #1044 may not have been applied
 * here) but NOT try/catch'd — the invariant is the point, and a rewrite that
 * cannot run must roll the move back with it.
 *
 * @param  \mysqli $db
 * @param  string  $targetAbbr The destination book (already validated by the
 *                             caller against tblSongbooks).
 * @param  string  $newSongId  The song's NEW id — step 4 has already cascaded
 *                             the junction's SongId column to it.
 * @return bool TRUE = the rewrite ran; FALSE = tblSongbookEntries does not
 *              exist on this install.
 * @throws \mysqli_sql_exception when the probe or any statement fails — the
 *         caller's transaction rolls the move back.
 */
function songRelocateRewriteEntries(\mysqli $db, string $targetAbbr, string $newSongId): bool
{
    /* Same gate discipline as the restrictions rewrite: STRICT probe, early
       return, no statement reachable on an un-migrated install. */
    if (!songRelocateTableExists($db, 'tblSongbookEntries')) { return false; }

    /* ELI5: is the song already a member of the destination book?
       Detail: probed FIRST because the answer picks between two write shapes —
       moving the home row versus promoting an existing membership row. */
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

    return true;
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
 * THROWS `SongRelocateEnvironmentException` (a `\RuntimeException` subclass) when
 * this environment's FKs cannot cascade the re-key (`songRelocateAssertCascades()`,
 * H3). Deliberately the OTHER branch of the split, for the same reason: that is an
 * environment fault, not bad input, so api2's 422 branch must not claim the curator
 * typed the wrong book. Both funnels recognise the TYPE and copy its message into
 * an `error_hint` field EVERY role can see (#1679 A8) — the sentence names the
 * migration card to run, so hiding it behind an admin-only channel defeated it.
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

    /* 1 — where does the song live right now?

       @deleted-visible: write-path state read (#1694) — relocating a hidden
       song (e.g. via a repair funnel) must keep working; visibility is not
       this function's question.
       @disabled-visible: admin surface + write-path state read (#1765) —
       relocating a song out of a DISABLED songbook (e.g. into a live one,
       or vice versa) is exactly a curator operation this function must keep
       supporting; it is called only from /manage/* admin funnels. */
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
       exploding half-way through the caller's transaction. The schema catalogue
       is memoised, so a bulk move reads INFORMATION_SCHEMA once; the VERDICT is
       per song (#1690 option A), so a stale constraint stops only the songs that
       actually have rows behind it. $oldSongId, not the id about to be minted —
       the child rows still reference the CURRENT one. #1679 H3. */
    songRelocateAssertCascades($db, $oldSongId);

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
       cannot cover: content restrictions (#1679 M3). Deliberately FATAL and
       deliberately NOT try/catch'd here either — the gate, the write and the
       full reasoning live in songRelocateRewriteRestrictions() above, where
       tests/php/test-song-relocate-gates.php can CALL them (#1691 D3/D5).
       The return value ("did the table exist?") is not consulted: a minimal
       install with no restrictions table has nothing to carry, which is not a
       condition the move needs to react to. */
    songRelocateRewriteRestrictions($db, $oldSongId, $newSongId);

    /* 5b — the SECOND soft reference (#1679 F3): the tblSongbookEntries home
       row. Same extraction, same reasons — see
       songRelocateRewriteEntries() above. Runs AFTER step 4 so the junction's
       SongId column has already cascaded to $newSongId. */
    songRelocateRewriteEntries($db, $targetAbbr, $newSongId);

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
       itself — it self-heals on the next pass (#791). Delegates to the
       shared songbookRecomputeSongCount() helper (#1742,
       includes/songbook_count.php) — this used to be its own inline
       prepare-once/loop-twice UPDATE, one of three copies of the same SQL
       across the codebase (a modularity-rule violation); the helper is now
       the ONE place that SQL lives, and it applies the same trim/dedupe/
       empty-skip this loop always did. */
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_count.php';
        songbookRecomputeSongCount($db, $targetAbbr, $currentAbbr);
    } catch (\Throwable $e) {
        /* #1679 F8 / A1 — "best-effort" must not mean "swallow anything". Two
           MySQL errors do not fail just the STATEMENT, they can roll back the
           ENTIRE InnoDB transaction — the caller's transaction, containing the
           move — after which the caller's $db->commit() commits NOTHING and the
           endpoint answers {ok:true, songId:<new>} for a song that no longer
           exists under that id. The two codes and the precise conditions live in
           songRelocateIsTransactionFatal(): ONE list, because the same test has
           to hold in every catch between the relocate and the commit, and the two
           funnels have ten more. Anything else (a missing SongCount column on an
           old install, a permission oddity) really is cosmetic: log and let the
           move stand. */
        if (songRelocateIsTransactionFatal($e)) { throw $e; }
        error_log('[songRelocate] SongCount recompute failed: ' . $e->getMessage());
    }

    return [
        'songId'               => $newSongId,
        'previousId'           => $oldSongId,
        'renamed'              => true,
        'previousSongbookAbbr' => $currentAbbr,
    ];
}
