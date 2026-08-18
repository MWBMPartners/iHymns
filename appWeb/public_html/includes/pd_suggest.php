<?php

declare(strict_types=1);

/**
 * iHymns — Public-domain suggestion (#1862 sub-build E, epic #1863)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * For a song's LYRICS and its MUSIC, this file works out the first calendar
 * year each part would become public domain under the "life of the last
 * surviving contributor plus 70 years" rule — and stores that year on the
 * song so the editor can show "hey, this looks public domain now, want to
 * tick the box?" without ever ticking it for the curator.
 *
 * DETAILED — THE MODEL (owner-ratified, #1862 spec §5)
 * ----------------------------------------------------------------------------
 * Per part, a denormalised "PD-from-year" integer:
 *   PdFromYear = MAX(YEAR(DeathDate) across the part's contributor set)
 *                + IHYMNS_PD_LIFE_PLUS_YEARS + 1
 * NULL means "cannot conclude" (no usable death-date evidence). The
 * suggestion at READ time is simply `PdFromYear !== null && PdFromYear <=
 * currentYear` — see `pdSuggestFold()`. This is deliberately a DENORM
 * RECOMPUTED ON CHANGE, COMPARED AT READ design: correct the instant a term
 * expires (no batch job needed to notice a year has turned over) and cheap
 * to store (one integer per part, not a recomputation on every page view).
 *
 * CONTRIBUTOR SETS (decision D1, ratified as recommended):
 *   Lyrics: tblSongWriters + tblSongAdaptors + tblSongTranslators
 *           (a translation/adaptation embodies fresh copyrightable work —
 *           including these roles is the CONSERVATIVE direction: more
 *           contributors -> a later max -> fewer false PD suggestions).
 *   Music:  tblSongComposers + tblSongArrangers.
 *   tblSongArtists (performers) NEVER count toward either part.
 *
 * RESOLUTION: each role-table `Name` string is matched against
 * `tblMusicians.Name` (`uk_Name`, collation-CI exact match — the same
 * name-string join the credit-registry promotion path already relies on).
 * Per part:
 *   - zero credit rows                                   -> NULL (no basis)
 *   - ANY credit with no registry row, or a registry row  -> NULL (decision
 *     with `DeathDate IS NULL` (this INCLUDES every         D2, conservative:
 *     `IsSpecialCase` row — Anonymous/Traditional/Unknown   an unresolvable
 *     never carry a death date)                             contributor blocks
 *                                                            the death-basis
 *                                                            suggestion; the
 *                                                            publication
 *                                                            fallback is the
 *                                                            only route left)
 *   - else                                                -> MAX(YEAR(DeathDate))
 *                                                             + IHYMNS_PD_LIFE_PLUS_YEARS + 1
 *
 * PUBLICATION FALLBACK (issue §7, decision D3): when a part's computed
 * PdFromYear is NULL and `tblSongs.FirstPublishedYear` is set and strictly
 * before the app setting `pd_publication_year_threshold` (default 1900),
 * `pdSuggestFold()` still suggests that part as PD, tagged `basis =
 * 'publication'` so the UI can carry an "assumed from publication age —
 * please verify" caveat the death-basis suggestion doesn't need.
 *
 * NEVER AUTO-SET (owner-stated, both the issue and its refinement comment).
 * Nothing in this file EVER writes `LyricsPublicDomain` / `MusicPublicDomain`
 * — those checkboxes stay the ONLY write path, always a curator's explicit
 * click (metadata-tab.js's "Use" adopt-hint, mirroring the rights-panel D4
 * precedent this phase's Editor2 UI reuses the shape of before deleting that
 * file). This file only ever writes the two DENORM integer columns.
 *
 * DORMANT UNTIL MIGRATED: every write here is gated on
 * `pdFromYearColsPresent()` — an un-migrated install (no
 * `LyricsPdFromYear`/`MusicPdFromYear` columns) makes every recompute a
 * silent no-op, never a thrown mysqli_sql_exception under STRICT (rule #9).
 *
 * FAIL-SAFE BY DESIGN: `pdRecomputeForSong()` / `pdRecomputeForMusicianName()`
 * never throw — every caller invokes them POST-COMMIT, in their own failure
 * boundary, so a denorm hiccup must never cost a curator their credit edit,
 * musician save, or song save (the same posture as `song_media_flags.php`'s
 * `songMediaRecomputeFlags()`, and the #1860 go-live wrappers before it).
 *
 * Direct access is blocked (same guard as publisher_helpers.php /
 * musician_helpers.php) so this file can't be requested as an endpoint via
 * an open Apache config.
 *
 * @link appWeb/.sql/migrate-song-pd-from-year.php    the additive schema batch + backfill
 * @link appWeb/public_html/manage/editor/api2.php     credit_upsert / credit_delete recompute hooks
 * @link appWeb/public_html/manage/editor/save_song_core.php  whole-song save recompute hook
 * @link appWeb/public_html/manage/musicians.php       create/update/rename recompute hooks
 * @link appWeb/public_html/includes/musician_helpers.php  musicianMergeExecute() recompute hook
 * @link tests/php/test-editor2-metadata-1862.php      the truth-table + tree-derived wiring guard
 * @see #1862, epic #1863
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** Life-plus term in years (decision D4 — a CODE CONSTANT, not an app
 *  setting: changing it is a one-line edit here + re-running the
 *  'song-pd-from-year' migration card's backfill, never a runtime knob
 *  (rule #44 — no "in case" configurability for a value that changes the
 *  legal basis of a suggestion). EU/UK/US-post-1978 norm; the editor hint
 *  text carries the jurisdiction-varies disclaimer regardless. */
const IHYMNS_PD_LIFE_PLUS_YEARS = 70;

/** Decision D1's role -> part map, as the two source-of-truth table lists —
 *  the ONE place these are named; pdComputeForSong() below builds its SQL
 *  from these constants only (never a re-typed literal list), and
 *  pdRecomputeForMusicianName() merges both to find every song a
 *  PD-relevant credit could touch. Table names are hardcoded hardened
 *  literals interpolated into SQL (rule #5's carve-out — never request
 *  input); every bound VALUE alongside them stays bound. */
const IHYMNS_PD_LYRICS_CREDIT_TABLES = ['tblSongWriters', 'tblSongAdaptors', 'tblSongTranslators'];
const IHYMNS_PD_MUSIC_CREDIT_TABLES  = ['tblSongComposers', 'tblSongArrangers'];

/** The tblAppSettings key for the publication-year fallback threshold
 *  (decision D3) — the ONE spelling, shared by editor2.php's
 *  window._iHymnsPdSuggest emit and /manage/configuration's admin control,
 *  so the two can never drift onto different key strings (rule #35). */
const IHYMNS_PD_PUBLICATION_THRESHOLD_SETTING_KEY = 'pd_publication_year_threshold';
/** Default publication-year threshold (decision D3) — pre-1900 is the
 *  owner's own "e.g. pre-1900" example; configurable at /manage/configuration. */
const IHYMNS_PD_PUBLICATION_THRESHOLD_DEFAULT = 1900;

if (!function_exists('pdFromYearColsPresent')) {
    /**
     * Memoised probe: do BOTH `tblSongs.LyricsPdFromYear` /
     * `MusicPdFromYear` exist on this install (the 'song-pd-from-year'
     * migration card)? Every WRITE in this file gates on this; the PURE
     * computation (`pdComputeForSong()`) does not need it — its source
     * tables (the five role tables + tblMusicians) predate this feature.
     */
    function pdFromYearColsPresent(\mysqli $db): bool
    {
        static $present = null;
        if ($present !== null) { return $present; }
        try {
            $r = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
                    AND COLUMN_NAME IN ('LyricsPdFromYear','MusicPdFromYear')"
            );
            $row = $r ? $r->fetch_row() : null;
            $present = $row !== null && ((int)$row[0]) === 2;
            if ($r) { $r->close(); }
        } catch (\Throwable $_e) {
            $present = false;   // degrade to "not migrated" — the safe direction (rule #9)
        }
        return $present;
    }
}

if (!function_exists('_pdMaxDeathYearForTables')) {
    /**
     * The resolution described in this file's header, for ONE song and ONE
     * part's contributor table set: MAX(YEAR(DeathDate)) + life-plus + 1,
     * or NULL when there are no credits, or ANY credit cannot be resolved
     * to a registry row with a known DeathDate.
     *
     * @param string[] $tables Hardcoded literal table names only (rule #5).
     */
    function _pdMaxDeathYearForTables(\mysqli $db, string $songId, array $tables): ?int
    {
        if ($songId === '' || !$tables) { return null; }

        $unionParts = [];
        $types  = '';
        $params = [];
        foreach ($tables as $t) {
            $unionParts[] = "SELECT Name FROM {$t} WHERE SongId = ?";
            $types  .= 's';
            $params[] = $songId;
        }
        $sql = 'SELECT COUNT(*) AS credits,
                       SUM(m.Id IS NULL OR m.DeathDate IS NULL) AS unknowns,
                       MAX(YEAR(m.DeathDate)) AS maxDeath
                  FROM (' . implode(' UNION ALL ', $unionParts) . ') c
                  LEFT JOIN tblMusicians m ON m.Name = c.Name';

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { return null; }

        $credits  = (int)$row['credits'];
        $unknowns = (int)($row['unknowns'] ?? 0);
        $maxDeath = $row['maxDeath'] !== null ? (int)$row['maxDeath'] : null;

        if ($credits === 0 || $unknowns > 0 || $maxDeath === null) { return null; }
        return $maxDeath + IHYMNS_PD_LIFE_PLUS_YEARS + 1;
    }
}

if (!function_exists('pdComputeForSong')) {
    /**
     * Compute (without writing) both parts' PD-from-year for one song.
     *
     * @return array{lyrics: ?int, music: ?int}
     */
    function pdComputeForSong(\mysqli $db, string $songId): array
    {
        return [
            'lyrics' => _pdMaxDeathYearForTables($db, $songId, IHYMNS_PD_LYRICS_CREDIT_TABLES),
            'music'  => _pdMaxDeathYearForTables($db, $songId, IHYMNS_PD_MUSIC_CREDIT_TABLES),
        ];
    }
}

if (!function_exists('pdRecomputeForSong')) {
    /**
     * Recompute + write both PD-from-year denorm columns for ONE song.
     * Write-if-changed (a NULL-safe `<=>` compare so "still both NULL"
     * never fires an update); dormant (silent no-op) until
     * `pdFromYearColsPresent()`; NEVER throws (see this file's header).
     */
    function pdRecomputeForSong(\mysqli $db, string $songId): void
    {
        if ($songId === '' || !pdFromYearColsPresent($db)) { return; }
        try {
            $computed = pdComputeForSong($db, $songId);
            $lyrics = $computed['lyrics'];
            $music  = $computed['music'];
            $u = $db->prepare(
                'UPDATE tblSongs SET LyricsPdFromYear = ?, MusicPdFromYear = ?
                  WHERE SongId = ? AND NOT (LyricsPdFromYear <=> ? AND MusicPdFromYear <=> ?)'
            );
            $u->bind_param('iisii', $lyrics, $music, $songId, $lyrics, $music);
            $u->execute();
            $u->close();
        } catch (\Throwable $e) {
            error_log('[pdRecomputeForSong] ' . $e->getMessage());
        }
    }
}

if (!function_exists('pdRecomputeForMusicianName')) {
    /**
     * Recompute every song a musician NAME could contribute a PD-relevant
     * credit to (the union of both role-table sets — decision D1's whole
     * map, never just one part) — called after a `tblMusicians.DeathDate`
     * change (create/update/rename/merge). Bounded: a prolific author
     * touches at most a few hundred songs, acceptable post-commit.
     */
    function pdRecomputeForMusicianName(\mysqli $db, string $name): void
    {
        if ($name === '' || !pdFromYearColsPresent($db)) { return; }
        try {
            $tables = array_merge(IHYMNS_PD_LYRICS_CREDIT_TABLES, IHYMNS_PD_MUSIC_CREDIT_TABLES);
            $unionParts = [];
            $types  = '';
            $params = [];
            foreach ($tables as $t) {
                $unionParts[] = "SELECT SongId FROM {$t} WHERE Name = ?";
                $types  .= 's';
                $params[] = $name;
            }
            $sql = 'SELECT DISTINCT SongId FROM (' . implode(' UNION ALL ', $unionParts) . ') s';
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $songIds = [];
            while ($row = $res->fetch_row()) { $songIds[] = (string)$row[0]; }
            $stmt->close();

            foreach ($songIds as $songId) {
                pdRecomputeForSong($db, $songId);
            }
        } catch (\Throwable $e) {
            error_log('[pdRecomputeForMusicianName] ' . $e->getMessage());
        }
    }
}

if (!function_exists('pdSuggestFold')) {
    /**
     * The PURE read fold: given a part's stored PdFromYear denorm, the
     * song's FirstPublishedYear, the app's publication-year threshold and
     * the current year, decide whether to show a PD suggestion — and on
     * what basis. Shared (via fixtures) by the PHP truth-table test and the
     * JS hint renderer so the two can never silently disagree (rule #35).
     *
     * Publication fallback applies ONLY when $pdFromYear is NULL — a KNOWN
     * (even if not-yet-expired) death-basis year is never overridden by a
     * publication-age guess; a not-yet-PD death year correctly yields
     * `suggested:false` rather than falling through to the fallback.
     *
     * @param ?int $pdFromYear         The stored denorm (tblSongs.LyricsPdFromYear or …MusicPdFromYear).
     * @param ?int $firstPublishedYear tblSongs.FirstPublishedYear.
     * @param int  $threshold          app setting 'pd_publication_year_threshold' (default 1900).
     * @param int  $currentYear        (int)date('Y') at call time.
     * @return array{suggested: bool, basis: ?string, fromYear: ?int} basis is 'death'|'publication'|null.
     */
    function pdSuggestFold(?int $pdFromYear, ?int $firstPublishedYear, int $threshold, int $currentYear): array
    {
        if ($pdFromYear !== null && $pdFromYear <= $currentYear) {
            return ['suggested' => true, 'basis' => 'death', 'fromYear' => $pdFromYear];
        }
        if ($pdFromYear === null && $firstPublishedYear !== null && $firstPublishedYear < $threshold) {
            return ['suggested' => true, 'basis' => 'publication', 'fromYear' => $firstPublishedYear];
        }
        return ['suggested' => false, 'basis' => null, 'fromYear' => null];
    }
}
