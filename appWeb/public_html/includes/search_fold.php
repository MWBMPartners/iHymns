<?php
/**
 * search_fold.php — the ONE write path for the diacritic-folded search mirror (#1039 Part A)
 * ==========================================================================================
 *
 * ELI5
 * ----
 * Someone searching for a song types "milosc" on a plain keyboard, but the
 * song's real title is "Miłość" (with the accented ł and ś). A database
 * search for the exact text "milosc" would never find it — the characters
 * genuinely differ. So every time a song's title or lyrics are saved, this
 * file ALSO saves a second, "flattened" copy with the accents stripped off
 * (Miłość → milosc), and the search feature matches against THAT copy
 * instead. Whenever you see "folded" in this codebase, it means "accents
 * removed for matching purposes" — the display copy the reader sees always
 * keeps its real accents; only this hidden search mirror is flattened.
 *
 * Maintains the two app-owned search-fold columns on tblSongs so a reader typing
 * plain ASCII ("milosc", "arent") still matches an accented / apostrophised song
 * ("Miłość", "aren’t"):
 *
 *   - tblSongs.LyricsTextFolded  — ihymns_search_fold(LyricsText)   (FULLTEXT'd)
 *   - tblSongs.NormalizedTitle   — ihymns_search_fold(Title)        (FULLTEXT'd)
 *
 * This helper is called from EVERY funnel that writes tblSongs.LyricsText
 * (save_song_core, editor api2's ed2_rebuildLyricsText, editor api's
 * revision-restore, song_importers, lyrics_ingest — the set the CI guard
 * tests/php/test-search-fold.php derives from the tree). Repairing NormalizedTitle
 * here closes the long-standing maintenance gap where ONLY api2's field-level
 * paths kept it in sync (#1039 / the lyrics_ingest INSERTs omitted it entirely).
 *
 * DORMANT + FAIL-OPEN (rule #28 A/C): the whole thing is column+index gated. On an
 * un-migrated install searchFoldReady() is false, searchFoldSyncSong() is a verified
 * no-op, and search behaviour is byte-identical — the 3 docroots share ONE MySQL and
 * migrations are web-run, not auto-applied, so any un-migrated read/write degrades to
 * the song unchanged. STRICT mysqli would THROW on an UPDATE of a not-yet-added
 * column, which is exactly what the gate prevents (rule #19/#20).
 *
 * Framework-free (no $_SERVER / session reads) so it is safe to require from the
 * migration runner, the editor and any /manage page.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * @see ihymns_search_fold()          includes/title_normalize.php — the ONE fold point
 * @see SongData::_runFulltextSearch  the READ side that consumes the folded columns
 * @link https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html
 */

declare(strict_types=1);

if (!function_exists('searchFoldReady')) {
    /**
     * True when this install has the FULL search-fold schema live: the
     * LyricsTextFolded column AND both new FULLTEXT indexes the read path's
     * dual-arm MATCH() needs. Gating on the INDEXES (not merely the column)
     * is deliberate — a half-migrated install (column added, indexes not) must
     * NOT start writing a mirror the reader would then MATCH() against a
     * non-existent index and throw. Memoised per connection (one
     * INFORMATION_SCHEMA round-trip); fail-open to false on any probe error.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema.html
     */
    function searchFoldReady(\mysqli $db): bool
    {
        /** @var array<int,bool> $cache keyed by connection object id */
        static $cache = [];
        $key = spl_object_id($db);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $ready = false;
        try {
            /* Column present? (LyricsTextFolded is the mirror we write.) */
            $c = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'LyricsTextFolded' LIMIT 1"
            );
            $c->execute();
            $hasCol = $c->get_result()->fetch_row() !== null;
            $c->close();

            /* Both new FULLTEXT indexes present? INDEX_NAME is bound (rule #5). */
            $hasIdx = $hasCol;
            if ($hasCol) {
                $i = $db->prepare(
                    "SELECT COUNT(DISTINCT INDEX_NAME) AS n
                       FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongs'
                        AND INDEX_NAME IN ('ft_NormalizedTitle', 'ft_NormTitleLyricsFolded')"
                );
                $i->execute();
                $row = $i->get_result()->fetch_assoc();
                $i->close();
                $hasIdx = ((int)($row['n'] ?? 0)) === 2;
            }
            $ready = $hasCol && $hasIdx;
        } catch (\Throwable $_e) {
            /* Probe failure ⇒ treat as un-migrated (dormant, byte-identical). */
            $ready = false;
        }
        return $cache[$key] = $ready;
    }
}

if (!function_exists('searchFoldSyncSong')) {
    /**
     * Maintain the folded search mirror + repair NormalizedTitle for ONE song, in
     * a single small gated UPDATE — the established "separate small UPDATE after the
     * tuned UPSERT" pattern (save_song_core.php's OriginCity / TuneId blocks).
     *
     * ELI5: called right after a song's title or lyrics are saved — "now go
     * refresh that song's flattened search copy so it matches what was just
     * saved." Every save funnel in the app (the editor, the importers, the
     * lyrics pipeline) calls this ONE function rather than each doing its
     * own flattening, so the flattened copy can never fall out of step with
     * what a reader is actually searching for.
     *
     * A no-op (returns immediately) when the fold schema isn't live, so an
     * un-migrated install is byte-identical and STRICT mysqli never throws on an
     * absent column. Both columns are folded with the SAME ihymns_search_fold()
     * the query side uses, so a stored value and a live query can never skew.
     *
     * Runs UNGUARDED inside the caller's transaction (no try/catch of its own) so a
     * genuine DB fault rolls the caller's write back honestly — matching the posture
     * of the sibling OriginCity / TuneId UPDATEs it sits beside.
     *
     * @param string $songId      the tblSongs.SongId to update
     * @param string $title       the song's CURRENT Title (folds to NormalizedTitle)
     * @param string $lyricsText  the song's CURRENT LyricsText (folds to LyricsTextFolded)
     */
    function searchFoldSyncSong(\mysqli $db, string $songId, string $title, string $lyricsText): void
    {
        if ($songId === '' || !searchFoldReady($db)) {
            return;
        }
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'title_normalize.php';
        /* #1908 D6 — cap at the column width (VARCHAR(500)). NFKD can EXPAND a
           title (Hangul syllables decompose to 2-3 jamo each), so an uncapped
           write of a long non-Latin title can exceed 500 chars and throw under
           STRICT mysqli. $foldedText stays uncapped: it feeds LyricsTextFolded
           (MEDIUMTEXT), which must not be truncated. */
        $normTitle  = mb_substr(ihymns_search_fold($title), 0, 500);
        $foldedText = ihymns_search_fold($lyricsText);
        $u = $db->prepare(
            'UPDATE tblSongs SET NormalizedTitle = ?, LyricsTextFolded = ? WHERE SongId = ?'
        );
        $u->bind_param('sss', $normTitle, $foldedText, $songId);
        $u->execute();
        $u->close();
    }
}
