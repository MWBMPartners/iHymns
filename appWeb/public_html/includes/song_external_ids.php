<?php

declare(strict_types=1);

/**
 * iHymns — tblSongs.Isrc -> tblSongExternalIds dual-write mirror (#1749, epic #1741 P5d)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tblSongs.Isrc` (a single column, #1064) and `tblSongExternalIds` (the new
 * key/value external-ID store, #1741 D5) both know a song's ISRC — but only
 * ONE of them updates when a curator edits it. The #1747 D5 backfill copied
 * every existing `tblSongs.Isrc` value into the store ONCE, at a point in
 * time; the moment a curator changes an ISRC through the editor after that,
 * the store's copy goes stale unless something keeps the two in sync. This
 * file is that something: ONE function, called every time `tblSongs.Isrc` is
 * written through the editor, that mirrors the SAME change into the store.
 *
 * DETAILED / WHY A SEPARATE FILE FROM media_identifiers.php
 * ----------------------------------------------------------------------------
 * `includes/media_identifiers.php`'s own doc-block scopes it to VOCABULARY
 * only — allow-listed IdType/IdScope maps + pure validators, no `\mysqli`
 * parameter anywhere in that file, so it stays safe to `require_once` from
 * contexts that never open a DB connection (framework-free, mirroring
 * `identifier_normalize.php`'s doc-block). A live DB WRITE needs its own
 * sibling file — the same split `includes/tune_helpers.php` (vocabulary-free
 * find-or-create funnel) already established for `tblTunes`. This file is
 * that split applied to `tblSongExternalIds`.
 *
 * THE OWNERSHIP MODEL (why a DELETE-then-INSERT, not an UPSERT-by-value)
 * ----------------------------------------------------------------------------
 * `tblSongExternalIds` is a key/VALUE store where more than one row can
 * legitimately carry `IdType='isrc'` for the same song (a hymn arrangement
 * can have more than one recording, each with its own ISRC — the #1747 D5
 * backfill's own doc-block already anticipates a curator-entered "manual"
 * second-recording row coexisting with a mirrored one). So this mirror does
 * NOT own "the isrc row for this song" — it owns exactly the ONE row it
 * itself is responsible for keeping in sync with `tblSongs.Isrc`, identified
 * by `SourceRef = 'tblSongs.Isrc'`. That literal is NOT invented here — it is
 * the EXACT string `migrate-backfill-song-external-ids.php` already writes
 * (its Source-1 block, `SourceRef='tblSongs.Isrc'`), so a row the one-time
 * backfill created and a row THIS live mirror creates are the same ownership
 * class: either one is safe for a later mirror call to replace. A manual
 * curator entry (`Source='manual'`, `SourceRef IS NULL` — see
 * `manage/editor/api2.php`'s planned `song_external_id_add`, #1741 P5b, not
 * yet built) is NEVER touched by this mirror, because it can never match
 * `SourceRef = 'tblSongs.Isrc'` — NULL never equals a string in SQL, and this
 * helper never writes NULL into that predicate's comparison value.
 *
 * Every call is DELETE-then-conditionally-INSERT, not an UPDATE, because the
 * mirrored VALUE is part of the row's identity (the UNIQUE key is
 * `(SongId, IdType, IdValue)` — schema.sql `uq_Song_Type_Value`): an UPDATE
 * would need to already know the OLD value to target the right row, which
 * this helper's single-argument (new value only) contract does not carry.
 * DELETE-by-ownership-predicate then conditionally INSERT is idempotent
 * either way and needs no prior read.
 *
 * #1749 FULL UNIFICATION — MARKER SEMANTICS INVERTED, ZERO ROW REWRITES
 * ----------------------------------------------------------------------------
 * `tblSongExternalIds` is now the AUTHORITY for recording IDs; `tblSongs.Isrc`
 * is a DERIVED single-value projection of it (the "primary ISRC" denorm),
 * kept in lockstep by `songExternalIdSyncIsrcDenorm()` below, which has the
 * LAST WORD inside every transaction that mutates isrc store rows (see that
 * function's own doc-block for the full algorithm and rationale). The
 * `SourceRef = 'tblSongs.Isrc'` marker literal keeps its EXACT bytes (no data
 * migration, the byte-equality guard below keeps passing unchanged) but its
 * DOCUMENTED MEANING inverts: pre-#1749 it said "this row was copied FROM the
 * column"; post-#1749 it says "this is the row the column is now PROJECTED
 * FROM" — the primary marker `songExternalIdIsrcProjectionSql()`'s `ORDER BY`
 * ranks first. The rename to something like `'primary'` was deliberately NOT
 * done — it would need a data migration + guard churn for zero behavioural
 * gain, since the literal's ROLE (an ownership/rank key) is identical either
 * way, only its narrative direction changed.
 *
 * Direct access is blocked (same guard as `media_identifiers.php` /
 * `tune_helpers.php` / `musician_helpers.php`) so this file can't be
 * requested as an endpoint via an open Apache config.
 *
 * @link .claude/catalogue-1741-P5-plan.md §4                                    the P5d dual-write mirror build spec
 * @link .claude/catalogue-1741-1749-unification-plan.md §1/§2                    the #1749 full-unification design (D-1 write-path shape, D-2 primary-ID rule)
 * @link appWeb/public_html/manage/editor/api2.php                               the metadata_field_update ISRC branch + ed2_applySongSnapshot() call sites + song_external_id_add/_delete
 * @link appWeb/public_html/manage/duplicate-songs.php                           the merge repoint+demote+sync (#1755)
 * @link appWeb/public_html/includes/media_identifiers.php                       the IdScope/IdType vocabulary this helper's literals are checked against (mediaIdentifierScopeValid()/mediaIdentifierIdTypeValid())
 * @link appWeb/public_html/includes/identifier_normalize.php                    ihymns_canonical_isrc() — callers canonicalise BEFORE calling this helper
 * @link appWeb/.sql/migrate-backfill-song-external-ids.php                      the one-time backfill whose SourceRef='tblSongs.Isrc' literal this mirror reuses as the ownership key
 * @link appWeb/.sql/migrate-reconcile-isrc-denorm.php                           the #1749 one-shot cutover reconciliation card
 * @link appWeb/.sql/schema.sql                                                  tblSongExternalIds CREATE TABLE block (uq_Song_Type_Value)
 * @see #1741
 * @see #1749
 * @see #1751 the optional $source parameter that lets lyrics_ingest.php's two write sites
 *            call this same mirror under their own 'ihymns-ingest' provenance label
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Cached, per-request probe: does `tblSongExternalIds` exist on this install?
 * The exact idiom `tune_helpers.php`'s `tuneTunesTableExists()` uses (a
 * `static` local — this file has no class).
 *
 * ELI5: "does the external-IDs table even exist yet on this database?" —
 * asked once per page load, remembered for every subsequent call.
 *
 * DETAILED / WHY THIS DOESN'T JUST DELEGATE TO api2.php's OWN PROBE: the
 * #1741 P5 build spec (§4.2) notes api2.php's planned
 * `ed2_songExternalIdsTableExists()` (P5b, not yet built) and this function
 * as "ONE probe, not two" — whichever lands first, the other becomes a thin
 * caller of it. This file lands first (P5a→P5d build order), so THIS is the
 * canonical probe; a future P5b either calls this directly or is a one-line
 * alias to it — never a second independent INFORMATION_SCHEMA query.
 *
 * @param \mysqli $db
 * @return bool
 */
function songExternalIdsTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongExternalIds' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * The mirror's OWN ownership literal — the string `migrate-backfill-song-
 * external-ids.php` already writes into `SourceRef` for every row it copies
 * from `tblSongs.Isrc`. Exposed as a constant (rather than inlined twice)
 * so the CI guard (`tests/php/test-song-external-id-mirror.php`) can parse
 * ONE declaration and byte-compare it against the migration's literal,
 * instead of two independently-typed occurrences silently drifting (rule
 * #35 — "a comment saying keep these in sync is the failure, not the fix").
 */
const SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF = 'tblSongs.Isrc';

/**
 * ELI5: when a song has several ISRC rows in the store, this is the ONE rule
 * for which of them shows up in the single tblSongs.Isrc box.
 * DETAILED (#1749 full unification, D-2): marker row first via NULL-safe <=>
 * (a manual row's SourceRef is NULL; (NULL='x') is NULL and would sort
 * unpredictably —
 * https://dev.mysql.com/doc/refman/8.0/en/comparison-operators.html#operator_equal-to),
 * then lowest Id so a later-added second recording never hijacks an
 * established denorm. Declared ONCE; the reconcile migration and its registry
 * probe consume it via songExternalIdIsrcProjectionSql() below — the guard
 * (tests/php/test-song-external-id-mirror.php §7.2) bans any re-forked
 * "ORDER BY (e.SourceRef" literal elsewhere in the tree (rule #22/#35).
 *
 * @link .claude/catalogue-1741-1749-unification-plan.md §2.1
 * @see #1749
 */
const SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER =
    "(e.SourceRef <=> 'tblSongs.Isrc') DESC, e.Id ASC";

/**
 * PURE SQL builder for the §2.1 primary-ISRC projection — the testable seam
 * (rule #34; mirrors identifier_resolve.php's _ihymns_resolve_songs_sql()
 * precedent: text-only, no \mysqli, so a test can call it directly).
 *
 * ELI5: "give me the one SELECT statement that answers 'what is this song's
 * PRIMARY isrc, according to the store?'" — as plain text, so both the live
 * sync function below AND the one-shot reconcile migration/probe can ask the
 * exact same question without a second, independently-typed copy.
 *
 * @param string $songIdExpr A PHP-SOURCE CONSTANT expression for the song id
 *                           position: '?' (bound single-song form, the sync
 *                           function's use) or 's.SongId' (correlated form,
 *                           the reconcile migration/probe's use). NEVER user
 *                           input (rule #5 carve-out — both call-site
 *                           spellings below are hardcoded literals, never a
 *                           request value).
 * @return string
 * @see #1749
 */
function songExternalIdIsrcProjectionSql(string $songIdExpr = '?'): string
{
    return 'SELECT e.IdValue FROM tblSongExternalIds e'
         . " WHERE e.SongId = {$songIdExpr} AND e.IdType = 'isrc'"
         . ' AND CHAR_LENGTH(e.IdValue) <= 15'          /* VARCHAR(15) target under STRICT — schema.sql:284 */
         . ' ORDER BY ' . SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER
         . ' LIMIT 1';
}

/**
 * PURE SQL builder for the store union arm shared by every "which song has
 * this ISRC?" reader (#1749 §1.2): /isrc/ (via identifier_resolve.php's
 * _ihymns_resolve_songs_sql()), api.php's song_by_identifier, and
 * lyricsIngest_resolveSong(). ONE predicate, three consumers — never
 * re-inline it (rule #22). Emits 2 placeholders (IdType, IdValue); the
 * caller binds both, in that order.
 *
 * ELI5: "is this song's id one of the ones the external-IDs store has under
 * this id-type/value pair?" — as a reusable fragment of SQL text, so three
 * different files can drop the identical question into their own WHERE
 * clause instead of three slightly-different hand-typed copies.
 *
 * @param string $songIdExpr PHP-source constant, e.g. 's.SongId' — the column
 *                           expression tested for store membership. NEVER
 *                           user input (same carve-out as the projection
 *                           builder above).
 * @return string
 * @see #1749
 */
function songExternalIdUnionArmSql(string $songIdExpr): string
{
    return "{$songIdExpr} IN (SELECT e.SongId FROM tblSongExternalIds e"
         . ' WHERE e.IdType = ? AND e.IdValue = ?)';
}

/**
 * THE store->column projection — the write that makes the store authoritative
 * (#1749 full unification, D-1).
 *
 * ELI5: look at ALL the ISRC rows the store has for this song, pick the
 * primary one by the ONE rule (songExternalIdIsrcProjectionSql() above), and
 * make the tblSongs.Isrc box say exactly that (or go empty when the store
 * has none).
 *
 * DETAILED / WHY THIS RUNS LAST (D-1 §1.1)
 * ----------------------------------------------------------------------------
 * This is the LAST write of every transaction that mutates isrc store rows —
 * embedded in songExternalIdMirrorIsrc() below (both its return paths), and
 * called directly by the non-mirror store mutations that don't go through
 * that helper: `song_external_id_add`/`song_external_id_delete`
 * (manage/editor/api2.php), the duplicate-songs merge, and the one-shot
 * reconcile migration. The store therefore always has "the last word" inside
 * any transaction that touched isrc data, which is what "the store is the
 * authority" operationally means.
 *
 * Table-absent ⇒ untouched column ⇒ whichever caller's own direct write
 * already ran stands unchanged — an un-migrated install's behaviour is
 * byte-identical to pre-#1749 column-authoritative behaviour (rule #9).
 *
 * The `NOT (Isrc <=> ?)` predicate makes the UPDATE a true no-op (0 affected
 * rows, no `UpdatedAt` churn) when the column is already in lockstep with the
 * projection — https://dev.mysql.com/doc/refman/8.0/en/comparison-operators.html#operator_equal-to
 * (NULL-safe equals) is what lets a single expression cover both the
 * "already this value" and "already NULL" cases without a separate branch.
 *
 * Deliberately UNSWALLOWED, like the mirror it is embedded in: a genuine
 * `mysqli_sql_exception` here must propagate to the caller's own transaction
 * `catch` block — a half-projected pair (store changed, column stale, or
 * vice versa) is worse than the whole edit failing outright.
 *
 * @param \mysqli $db
 * @param string  $songId tblSongs.SongId (already validated to exist by the caller).
 * @return string|null The projected value now in the column (null = cleared
 *                      to NULL), or null on an un-migrated install — a caller
 *                      that wants to echo a final value falls back to
 *                      whatever it itself already wrote in that case.
 * @link .claude/catalogue-1741-1749-unification-plan.md §1.1 D-1 (the write-path shape this implements)
 * @see #1749
 */
function songExternalIdSyncIsrcDenorm(\mysqli $db, string $songId): ?string
{
    if (!songExternalIdsTableExists($db)) {
        return null;                         /* un-migrated — column stays whatever the funnel wrote */
    }
    $sel = $db->prepare(songExternalIdIsrcProjectionSql('?'));
    $sel->bind_param('s', $songId);
    $sel->execute();
    $row = $sel->get_result()->fetch_row();
    $sel->close();
    $projected = ($row === null || (string)$row[0] === '') ? null : (string)$row[0];

    $u = $db->prepare('UPDATE tblSongs SET Isrc = ? WHERE SongId = ? AND NOT (Isrc <=> ?)');
    $u->bind_param('sss', $projected, $songId, $projected);
    $u->execute();
    $u->close();
    return $projected;
}

/**
 * Mirror `tblSongs.Isrc` into `tblSongExternalIds` — the #1749 dual-write.
 * Call this every time (and ONLY when) `tblSongs.Isrc` is written through a
 * live edit path (currently: `manage/editor/api2.php`'s
 * `metadata_field_update` ISRC branch, and `ed2_applySongSnapshot()`'s
 * revision-restore path), inside the SAME transaction as that write.
 *
 * ELI5: "the song's ISRC just changed to THIS value (or was cleared) — make
 * sure the external-IDs store's copy of it says the same thing, without
 * touching any OTHER ISRC row a curator entered by hand for this song."
 *
 * DETAILED / THE ALGORITHM
 * ----------------------------------------------------------------------------
 *   1. No-op (return immediately) when `tblSongExternalIds` doesn't exist —
 *      the table-absent case is already handled by the one-time backfill
 *      card being the catch-all once the migration lands; this mirror must
 *      never THROW just because a caller (which has no reason to check the
 *      probe itself) invoked it on an un-migrated install.
 *   2. DELETE the mirror's OWN row for this song — `SongId = ? AND
 *      IdType = 'isrc' AND SourceRef = ?` (the ownership predicate; see the
 *      file doc-block for why this is safe: a manual row's `SourceRef` is
 *      NULL and can never match this string comparison). This step alone
 *      is what a CLEARED ISRC (`$canonicalIsrc === null`) needs — the
 *      function then returns without inserting anything.
 *   3. When `$canonicalIsrc` is non-empty, INSERT IGNORE a fresh row with
 *      `Source='ihymns-mirror'` (distinguishes a LIVE-edit-mirrored row from
 *      the one-time `Source='ihymns-backfill'` copy in provenance queries —
 *      the OWNERSHIP key is `SourceRef` alone, never `Source`) and the SAME
 *      `SourceRef='tblSongs.Isrc'` literal. `INSERT IGNORE` means a manual
 *      row already holding the identical value collides harmlessly on
 *      `uq_Song_Type_Value (SongId, IdType, IdValue)` — the value is present
 *      in the store either way, which is the point; this mirror never
 *      needs to inspect what else is already there.
 *
 * `$canonicalIsrc` MUST already be canonical (uppercase, no separators) —
 * this function does NOT call `ihymns_canonical_isrc()` itself, so the SAME
 * value ends up in both `tblSongs.Isrc` and this store's `IdValue`, never a
 * re-derived copy that could silently diverge from a future change to the
 * canonicaliser's rules. Pass `null` (or `''`) to mean "cleared".
 *
 * Deliberately UNSWALLOWED: a genuine `mysqli_sql_exception` here must
 * propagate to the caller's transaction `catch` block and roll BOTH writes
 * back — a half-mirrored pair (tblSongs.Isrc changed, store stale, or vice
 * versa) is worse than the whole edit failing outright.
 *
 * #1749 full unification (D-1) — this function now RETURNS `?string`, not
 * `void`: its final statement calls songExternalIdSyncIsrcDenorm() (above),
 * the store->column projection that gives the store "the last word" — on
 * BOTH return paths (the cleared-value early return, and the normal
 * insert-then-return path), because a CLEARED tblSongs.Isrc can still
 * PROMOTE a pre-existing manual second-recording row into the column
 * (§2.1's documented, deliberate consequence — clearing the editor's ISRC
 * field does not necessarily mean "no ISRC" when the store still knows one).
 * The four existing callers (both api2.php sites, and both lyrics_ingest.php
 * sites) acquire the store-is-authoritative invariant with ZERO call-site
 * changes; they simply gained a return value they may optionally consume.
 *
 * @param \mysqli     $db
 * @param string      $songId        tblSongs.SongId (already validated to exist by the caller).
 * @param string|null $canonicalIsrc Already-canonical (ihymns_canonical_isrc()) value, or null/'' to clear.
 * @param string      $source        #1751 — provenance label only, written into the INSERT's `Source`
 *                                     column (`'ihymns-mirror'` for a live editor edit, `'ihymns-ingest'`
 *                                     for a lyrics-ingest write). NEVER part of the DELETE's ownership
 *                                     predicate, which stays `SongId + IdType + SourceRef` regardless of
 *                                     which caller minted the row — SourceRef is the ownership key and is
 *                                     provenance-independent; Source is provenance only.
 * @return string|null #1749 — the PROJECTED value now in tblSongs.Isrc (per
 *                      the §2.1 primary-ISRC rule), or null when the column
 *                      was cleared/never set, or null on an un-migrated
 *                      install (the table-absent early return below echoes
 *                      $canonicalIsrc itself in that case, since no
 *                      projection ran — a caller wanting a value still gets
 *                      a sensible one rather than a bare null meaning two
 *                      different things).
 * @link .claude/catalogue-1741-P5-plan.md §4.2
 * @link .claude/catalogue-1741-followups-small-plan.md §2.1 the #1751 $source parameter this signature adds
 * @link .claude/catalogue-1741-1749-unification-plan.md §1.1/§5.1 the D-1 return-value change this adds
 * @link appWeb/.sql/migrate-backfill-song-external-ids.php:167-174 the SourceRef literal + IdScope/IdType this mirrors
 * @see #1751
 * @see #1749
 */
function songExternalIdMirrorIsrc(\mysqli $db, string $songId, ?string $canonicalIsrc, string $source = 'ihymns-mirror'): ?string
{
    if (!songExternalIdsTableExists($db)) {
        /* un-migrated install — the backfill card is the catch-all once it
           lands; no projection ran, so echo the caller's own intended value
           rather than a bare null (which would otherwise ambiguously mean
           either "cleared" or "table absent"). */
        return $canonicalIsrc === null || $canonicalIsrc === '' ? null : $canonicalIsrc;
    }

    /* bind_param() binds BY REFERENCE, so the class constant is copied into a
       local variable first — passing SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF
       directly would be a "cannot pass a constant expression by reference"
       fatal error, not a SQL bug. */
    $sourceRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;

    $del = $db->prepare(
        'DELETE FROM tblSongExternalIds WHERE SongId = ? AND IdType = ? AND SourceRef = ?'
    );
    $idType = 'isrc';
    $del->bind_param('sss', $songId, $idType, $sourceRef);
    $del->execute();
    $del->close();

    $value = $canonicalIsrc === null ? '' : trim($canonicalIsrc);
    if ($value === '') {
        /* #1749 — cleared: the DELETE above already dropped the marker row,
           but the store may still hold a MANUAL second-recording row for
           this song. Re-project (the promotion case, §2.1) instead of just
           returning null here — a bare early-return would leave the column
           at whatever the caller's OWN write left it, which is exactly the
           class of drift this whole helper exists to prevent. */
        return songExternalIdSyncIsrcDenorm($db, $songId);
    }

    $idScope = 'recording';
    /* #1751 — $source is now the caller-supplied parameter (default
       'ihymns-mirror', unchanged for the two existing api2.php call sites
       that pass no 4th argument); the local hardcode this replaced is gone. */
    $ins = $db->prepare(
        'INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param(
        'ssssss',
        $songId,
        $idScope,
        $idType,
        $value,
        $source,
        $sourceRef
    );
    $ins->execute();
    $ins->close();

    /* #1749 — the store has the last word: project it back into the column.
       Normally a no-op (this mirror's own marker row IS the projection's
       rank-1 pick per SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER), but it is what
       HEALS a pre-#1749 mismatch (0.9/0.10 in the build spec) the moment
       this song's ISRC is next touched, without needing the one-shot
       reconcile card to have run first. */
    return songExternalIdSyncIsrcDenorm($db, $songId);
}
