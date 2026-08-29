<?php

declare(strict_types=1);

/**
 * iHymns — Duplicate-songs admin core: merge / rebuild-suggestions /
 * auto-link (API-coverage Batch 5, A10, `.claude/api-coverage-2026-08-28.md`
 * §4.3/§9).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * This is the "actually do it" half of the Duplicate & Counterpart Review
 * page (`/manage/duplicate-songs`, #1215) for its three heaviest actions —
 * permanently combining two song records (merge), re-scoring every possible
 * duplicate pair (rebuild), and auto-linking songs that share a hard
 * identifier like an ISWC (auto-link). Pulling this logic out of the page
 * means the JSON API (`api.php`'s `admin_song_merge` /
 * `admin_song_suggestions_rebuild` / `admin_song_auto_link`) can offer the
 * SAME behaviour to a native app, without a second copy of the merge's
 * transaction to keep in sync (rule #22).
 *
 * DETAILED
 * --------
 * `duplicateSongMergeExecute()` is a byte-identical extraction of the page's
 * former `case 'merge'` body (commit history: manage/duplicate-songs.php,
 * #1064/#1218/#1343/#1749) — every FK-repoint table list, the #1218
 * same-official-songbook force-guard, the #1749 external-id move, and the
 * #1343 redirect chain are unchanged, just moved into one callable that
 * returns a verdict (`['ok'=>bool,'status'=>int,...]`, the `songRestore()`/
 * `songPurge()` shape from `song_soft_delete.php`) instead of writing
 * `http_response_code()`/`echo json_encode()` itself — so a caller (the page
 * OR the API) decides how to present the result, and `logActivity()` stays
 * at the CALL SITE (mirrors `musicianMergeExecute()`, #1785 C4, so each
 * caller can log its own action-key convention).
 *
 * `duplicateSongRebuildSuggestions()` / `duplicateSongAutoLink()` are thin
 * wrappers: the heavy lifting already lived in its own file
 * (`includes/tools/build-song-link-suggestions.php` /
 * `includes/tools/auto-link-hard-id-counterparts.php`) — these just give
 * both callers ONE place to invoke it and translate a thrown exception into
 * the same verdict shape, rather than each caller writing its own
 * try/catch/ob_start dance.
 *
 * Deliberately NOT extracted here (kept page-only, per the batch-5 task
 * scope): the page's `link` / `unlink` / `dismiss` actions. `unlink` is a
 * single-song operation whose algorithm is ALREADY identical to
 * `manage/editor/api2.php`'s `song_link_remove` (that page was out of
 * scope for this batch, see `includes/song_link_admin.php`'s own
 * doc-block) — the page's `unlink` case now delegates to the ONE
 * `songLinkRemove()` core in `includes/song_link_admin.php`, which the
 * new `admin_song_unlink` API action also calls (rule #22 — reuse the
 * per-song core, never duplicate the SQL a THIRD time within this
 * batch's own scope). The page's `link`/`dismiss` actions are whole-SET
 * (N-song, all-or-nothing) algorithms that pre-date this batch and are
 * functionally DIFFERENT from api2's pairwise
 * `song_link_add`/`song_link_suggestion_dismiss` (a sequential pairwise
 * replay is not behaviour-equivalent to the page's atomic whole-cluster
 * validate-then-insert), so touching them was out of scope for this pass
 * — the new `admin_song_link` action instead reuses the SAME per-song
 * `songLinkAdd()` core (`includes/song_link_admin.php`, a behavioural
 * MIRROR of api2.php's `song_link_add`, not an extraction of it — api2.php
 * itself was untouched this batch), applied pairwise, which is the
 * "cluster-batch" shape the API-coverage plan asked for without risking a
 * behaviour change to the page's own long-standing algorithm.
 *
 * Direct access is blocked (the same guard every other includes/*.php
 * helper carries).
 *
 * @see appWeb/public_html/manage/duplicate-songs.php   the page this was extracted from
 * @see appWeb/public_html/includes/song_link_admin.php the per-song link/unlink core (reused by admin_song_link/unlink)
 * @see appWeb/public_html/api.php                       admin_song_merge / admin_song_suggestions_rebuild / admin_song_auto_link
 * @see .claude/api-coverage-2026-08-28.md §4.3/§9 A10    the plan this implements
 * @see CLAUDE.md rule #22                                the ONE duplicate-detection scorer discipline this batch extends to the WRITE side
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* songVisibleSql() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_redirects.php';     /* #1343 — permalink forwarding on merge */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';  /* #1749 — move the store's rows, don't let the FK cascade eat them */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_public_id.php';     /* #1343-B — keep the /song/<PublicId> permalink alive too */

/**
 * Every table that references tblSongs.SongId (authoritative, from
 * schema.sql). A merge re-points each to the survivor. Table + column names
 * are fixed constants from THIS source (never user input) — safe to
 * interpolate; the SongId VALUES are always bound (rule #5).
 */
const DUPLICATE_SONG_MERGE_FK_TABLES_SINGLE = [
    'tblSongbookEntries', 'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
    'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists', 'tblSongComponents',
    'tblLyrics', 'tblUserFavorites', 'tblSongKeys', 'tblSongHistory', 'tblSongTagMap',
    'tblSongLinks', 'tblCatalogueSongs', 'tblSongExternalLinks', 'tblSongAlternativeTitles',
    'tblSongLanguages', 'tblSongMedia', 'tblWorkSongs',
];
const DUPLICATE_SONG_MERGE_FK_TABLES_PAIR = [
    'tblSongTranslations'    => ['SourceSongId', 'TranslatedSongId'],
    'tblSongLinkSuggestions' => ['SongIdA', 'SongIdB'],
];
const DUPLICATE_SONG_MERGE_SOFT_REFS = [
    'tblSongRequests'                 => ['ResolvedSongId'],
    'tblSongRevisions'                => ['SongId'],
    'tblSongLinkSuggestionsDismissed' => ['SongIdA', 'SongIdB'],
];

/**
 * iHymns — merge $duplicate into $survivor: re-point every FK, migrate the
 * external-id store, forward permalinks, then delete the duplicate row.
 * Irreversible. Byte-identical extraction of manage/duplicate-songs.php's
 * former `case 'merge'` (#1064/#1218/#1343/#1749) — see the file doc-block.
 *
 * Every validation step returns the SAME status/error text the page always
 * returned, so a caller can relay `$verdict['status']`/`$verdict['error']`
 * verbatim (rule #35 — status is the contract).
 *
 * @return array{
 *   ok: bool, status?: int, error?: string,
 *   survivorId?: string, mergedFrom?: string, tables?: int
 * } `tables` (present only on success) is the FK-table count, carried so a
 *   caller's own logActivity() can report it exactly as the page always did.
 */
function duplicateSongMergeExecute(\mysqli $db, string $survivor, string $duplicate, bool $force, ?int $userId): array
{
    /* @disabled-visible: admin surface (#1765) — this whole curator merge
       workflow (the page it was extracted from, manage/duplicate-songs.php,
       and this core alike) stays fully visible/editable for a song in a
       disabled songbook; a curator must be able to merge duplicates inside
       a disabled book exactly as freely as any other. Every tblSongs/
       tblSongbooks read in this function is covered by this ONE marker. */
    $survivor  = trim($survivor);
    $duplicate = trim($duplicate);
    if ($survivor === '' || $duplicate === '') {
        return ['ok' => false, 'status' => 400, 'error' => 'survivor_id and duplicate_id are required.'];
    }
    if ($survivor === $duplicate) {
        return ['ok' => false, 'status' => 400, 'error' => 'A song cannot be merged into itself.'];
    }

    /* Both must exist AND be visible (#1694) — a soft-deleted song is never
       OFFERED for merge, so a merge naming one is a stale request. */
    $chk = $db->prepare('SELECT SongId FROM tblSongs WHERE SongId IN (?, ?) AND ' . songVisibleSql($db, ''));
    $chk->bind_param('ss', $survivor, $duplicate);
    $chk->execute();
    $found = [];
    $r = $chk->get_result();
    while ($row = $r->fetch_assoc()) { $found[(string)$row['SongId']] = true; }
    $chk->close();
    if (!isset($found[$survivor]) || !isset($found[$duplicate])) {
        return ['ok' => false, 'status' => 400, 'error' => 'Both songs must exist.'];
    }

    /* #1218 guard — two songs in the SAME official songbook with the same
       title are almost always DIFFERENT hymns. Refuse unless force=1. */
    if (!$force) {
        $bstmt = $db->prepare(
            'SELECT s.SongId, s.SongbookAbbr, COALESCE(sb.IsOfficial, 0) AS IsOfficial
               FROM tblSongs s
               LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE s.SongId IN (?, ?)'
        );
        $bstmt->bind_param('ss', $survivor, $duplicate);
        $bstmt->execute();
        $books = [];
        $br = $bstmt->get_result();
        while ($row = $br->fetch_assoc()) { $books[(string)$row['SongId']] = $row; }
        $bstmt->close();
        $sa = $books[$survivor]  ?? null;
        $sd = $books[$duplicate] ?? null;
        if ($sa && $sd
            && (string)$sa['SongbookAbbr'] === (string)$sd['SongbookAbbr']
            && (int)$sa['IsOfficial'] === 1) {
            return ['ok' => false, 'status' => 409, 'error' =>
                'Both songs are in the same official songbook (' . (string)$sa['SongbookAbbr']
                . ') — almost certainly different hymns that share a title. Merge is blocked here; '
                . 'use the type-to-confirm control if you are certain.'];
        }
    }

    $db->begin_transaction();
    try {
        /* Single-column FK tables. */
        foreach (DUPLICATE_SONG_MERGE_FK_TABLES_SINGLE as $t) {
            $u = $db->prepare("UPDATE IGNORE `{$t}` SET SongId = ? WHERE SongId = ?");
            $u->bind_param('ss', $survivor, $duplicate);
            $u->execute();
            $u->close();
            /* Any rows that couldn't move (UNIQUE collision with a survivor
               row) are leftover duplicates — drop them. */
            $d = $db->prepare("DELETE FROM `{$t}` WHERE SongId = ?");
            $d->bind_param('s', $duplicate);
            $d->execute();
            $d->close();
        }

        /* #1749 — the store is the recording-ID authority; move the
           duplicate's tblSongExternalIds rows to the survivor rather than
           letting fk_SongExternalIds_Song's ON DELETE CASCADE silently eat
           them the moment the duplicate row is deleted below (#1755). The
           duplicate's own ISRC-mirror MARKER row, if it has one, is demoted
           to a plain second-recording row (SourceRef -> NULL) as part of the
           SAME UPDATE, so the survivor never ends up with TWO marker rows. */
        if (songExternalIdsTableExists($db)) {
            $isrcMirrorSourceRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;
            $extIdMove = $db->prepare(
                "UPDATE IGNORE tblSongExternalIds
                    SET SongId = ?,
                        SourceRef = IF(IdType = 'isrc' AND SourceRef <=> ?, NULL, SourceRef)
                  WHERE SongId = ?"
            );
            $extIdMove->bind_param('sss', $survivor, $isrcMirrorSourceRef, $duplicate);
            $extIdMove->execute();
            $extIdMove->close();
            $extIdLeftover = $db->prepare('DELETE FROM tblSongExternalIds WHERE SongId = ?');
            $extIdLeftover->bind_param('s', $duplicate);
            $extIdLeftover->execute();
            $extIdLeftover->close();
            /* D-1: re-project the survivor's now-possibly-changed set of
               isrc rows into its tblSongs.Isrc column. */
            songExternalIdSyncIsrcDenorm($db, $survivor);
        }

        /* Two-column relationship tables. */
        foreach (DUPLICATE_SONG_MERGE_FK_TABLES_PAIR as $t => $cols) {
            foreach ($cols as $c) {
                $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                $u->bind_param('ss', $survivor, $duplicate);
                $u->execute();
                $u->close();
                $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                $d->bind_param('s', $duplicate);
                $d->execute();
                $d->close();
            }
        }
        /* Soft references (no FK constraint → repoint explicitly or they
           dangle after the delete). Same UPDATE IGNORE + DELETE-leftover. */
        foreach (DUPLICATE_SONG_MERGE_SOFT_REFS as $t => $cols) {
            foreach ($cols as $c) {
                $u = $db->prepare("UPDATE IGNORE `{$t}` SET `{$c}` = ? WHERE `{$c}` = ?");
                $u->bind_param('ss', $survivor, $duplicate);
                $u->execute();
                $u->close();
                $d = $db->prepare("DELETE FROM `{$t}` WHERE `{$c}` = ?");
                $d->bind_param('s', $duplicate);
                $d->execute();
                $d->close();
            }
        }
        /* #1343 — keep the duplicate's permalink alive: forward any
           redirects that already pointed AT the duplicate to the survivor,
           then add the duplicate -> survivor redirect itself. Done before
           the delete so the cascade can't strand a chain. */
        songRedirectRepoint($db, $duplicate, $survivor);
        songRedirectWrite($db, $duplicate, $survivor, 'merge', $userId);
        /* #1343-B — also keep the duplicate's opaque PublicId permalink
           alive (a shared /song/<PublicId> must survive the merge). */
        if (songPublicId_columnReady($db)) {
            $mp = $db->prepare('SELECT PublicId FROM tblSongs WHERE SongId = ? LIMIT 1');
            $mp->bind_param('s', $duplicate);
            $mp->execute();
            $dupPubId = (string)($mp->get_result()->fetch_assoc()['PublicId'] ?? '');
            $mp->close();
            if ($dupPubId !== '') { songRedirectWrite($db, $dupPubId, $survivor, 'merge', $userId); }
        }

        /* Finally remove the duplicate song. */
        $del = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
        $del->bind_param('s', $duplicate);
        $del->execute();
        $del->close();

        $db->commit();

        return [
            'ok'         => true,
            'survivorId' => $survivor,
            'mergedFrom' => $duplicate,
            'tables'     => count(DUPLICATE_SONG_MERGE_FK_TABLES_SINGLE)
                          + count(DUPLICATE_SONG_MERGE_FK_TABLES_PAIR)
                          + count(DUPLICATE_SONG_MERGE_SOFT_REFS),
        ];
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        return ['ok' => false, 'status' => 500, 'error' => 'Merge failed (rolled back): ' . $e->getMessage()];
    }
}

/**
 * iHymns — re-run the fuzzy suggestion builder (#1219). Byte-identical
 * extraction of manage/duplicate-songs.php's former `case 'rebuild'`: same
 * output-buffer capture + HTML-to-plain-text fold of the builder script's
 * progress lines, just returned instead of echoed directly.
 *
 * @return array{ok: bool, status?: int, error?: string, message?: string}
 */
function duplicateSongRebuildSuggestions(): array
{
    ob_start();
    try {
        require __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'build-song-link-suggestions.php';
        $out = (string)ob_get_clean();
        return ['ok' => true, 'message' => trim(strip_tags(str_replace('<br>', ' · ', $out)))];
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) { ob_end_clean(); }
        return ['ok' => false, 'status' => 500, 'error' => 'Rebuild failed: ' . $e->getMessage()];
    }
}

/**
 * iHymns — bulk-link cross-book songs sharing a hard id (ISWC/CCLI/ISRC,
 * #1125). Byte-identical extraction of manage/duplicate-songs.php's former
 * `case 'auto_link'`: delegates to the pre-existing
 * `autoLinkHardIdCounterparts()` core (includes/tools/
 * auto-link-hard-id-counterparts.php, unchanged, rule #22 — never re-wrap a
 * writer that already exists further than this thin try/catch).
 *
 * @return array{ok: bool, status?: int, error?: string, linked?: int, ...}
 *   the success shape spreads whatever autoLinkHardIdCounterparts() reports.
 */
function duplicateSongAutoLink(\mysqli $db, ?int $createdBy): array
{
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'auto-link-hard-id-counterparts.php';
        $r = autoLinkHardIdCounterparts($db, $createdBy);
        return ['ok' => true] + $r;
    } catch (\Throwable $e) {
        return ['ok' => false, 'status' => 500, 'error' => 'Auto-link failed: ' . $e->getMessage()];
    }
}
