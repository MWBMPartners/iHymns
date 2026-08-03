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
 * Direct access is blocked (same guard as `media_identifiers.php` /
 * `tune_helpers.php` / `musician_helpers.php`) so this file can't be
 * requested as an endpoint via an open Apache config.
 *
 * @link .claude/catalogue-1741-P5-plan.md §4                                    the build spec this file implements
 * @link appWeb/public_html/manage/editor/api2.php                               the metadata_field_update ISRC branch + ed2_applySongSnapshot() call sites
 * @link appWeb/public_html/includes/media_identifiers.php                       the IdScope/IdType vocabulary this helper's literals are checked against (mediaIdentifierScopeValid()/mediaIdentifierIdTypeValid())
 * @link appWeb/public_html/includes/identifier_normalize.php                    ihymns_canonical_isrc() — callers canonicalise BEFORE calling this helper
 * @link appWeb/.sql/migrate-backfill-song-external-ids.php                      the one-time backfill whose SourceRef='tblSongs.Isrc' literal this mirror reuses as the ownership key
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
 * Deliberately `void` + UNSWALLOWED: a genuine `mysqli_sql_exception` here
 * must propagate to the caller's transaction `catch` block and roll BOTH
 * writes back — a half-mirrored pair (tblSongs.Isrc changed, store stale, or
 * vice versa) is worse than the whole edit failing outright.
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
 * @return void
 * @link .claude/catalogue-1741-P5-plan.md §4.2
 * @link .claude/catalogue-1741-followups-small-plan.md §2.1 the #1751 $source parameter this signature adds
 * @link appWeb/.sql/migrate-backfill-song-external-ids.php:167-174 the SourceRef literal + IdScope/IdType this mirrors
 * @see #1751
 */
function songExternalIdMirrorIsrc(\mysqli $db, string $songId, ?string $canonicalIsrc, string $source = 'ihymns-mirror'): void
{
    if (!songExternalIdsTableExists($db)) {
        return;   // un-migrated install — the backfill card is the catch-all once it lands
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
        return;   // cleared — the DELETE above is the whole job
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
}
