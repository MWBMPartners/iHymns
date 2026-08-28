<?php

declare(strict_types=1);

/**
 * pp7_timeline.php — ProPresenter auto-advance timeline capture: DORMANT groundwork (#1968)
 * ================================================================================================
 *
 * ELI5
 * ----
 * When a `.pro`/`.probundle` gets imported, ProPresenter's file can carry a "play the slides
 * along with this backing video/track, automatically" schedule — decoded by
 * `pp7DecodeTimeline()` (`includes/propresenter7_decode.php`). This file is the small,
 * gated bridge that takes that decoded schedule and, ONLY when a curator has explicitly turned
 * the feature on, writes it into `tblSongPresentationCues` so it exists for a LATER,
 * not-yet-built playback feature to read. While the toggle is off (its shipped default), every
 * function here is a no-op — nothing is stored, nothing changes about how import behaves.
 *
 * DETAILED — the dormancy contract (owner steer, 2026-08-28)
 * -------------------------------------------------------------
 * "Build the groundwork/schema now, but auto-advance usage is OFF by default with a toggle,
 * fully fleshed out later." Concretely:
 *
 *   1. `pp7TimelineImportEnabled()` reads the `pp7_timeline_import_enabled` app setting
 *      (seeded '0' by `appWeb/.sql/migrate-pp7-timeline-groundwork.php`) — mirrors the SAME
 *      shape `_bulkImport_pp7MediaIngestActive()` uses for `pp7_media_ingest_enabled`
 *      (`includes/song_importers.php`), so a reader already familiar with that #1968 P4 gate
 *      recognises this one immediately.
 *   2. `pp7TimelineTableExists()` is a memoised INFORMATION_SCHEMA probe (the
 *      `songMediaVisibilityColumnExists()` shape, `includes/song_media_visibility.php`) —
 *      STRICT-mysqli safe on an un-migrated install (rule #9: a raw SELECT against a table that
 *      doesn't exist yet THROWS under this codebase's `MYSQLI_REPORT_STRICT`; a probe must never
 *      be the thing that throws).
 *   3. `pp7TimelineStore()` is gated on BOTH of the above — table absent OR toggle off both
 *      degrade it to a same-shaped 0-row no-op, never a throw, never a partial write.
 *
 * This file does NOT decide *whether* to capture a timeline during import — that call is made by
 * `includes/song_importers.php`'s ingest step (mirroring where `_bulkImport_pp7IngestMedia()` is
 * invoked), which wraps its `pp7TimelineStore()` call in its OWN try/catch so a timeline hiccup
 * can never fail the surrounding song import (non-blocking, same posture as media ingest).
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 * -------------------------------------------
 * No playback, no auto-advance UI, no mapping of a captured cue onto a `tblSongComponents` row
 * (`ComponentId` is written NULL and stays NULL here) — those are explicitly OUT of scope for
 * this task; see `tblSongPresentationCues`'s own doc-comment in the migration/schema.sql for the
 * full column rationale.
 *
 * Direct access prevention (the convention every includes/ library in this repo carries — see
 * e.g. `includes/song_media_visibility.php`). This file is a pure library; it is never meant to
 * be requested directly by a browser.
 *
 * @see appWeb/public_html/includes/propresenter7_decode.php   pp7DecodeTimeline() — the decode side
 * @see appWeb/public_html/includes/song_importers.php          the (also-gated) caller, ingest step
 * @see appWeb/.sql/migrate-pp7-timeline-groundwork.php          creates the table + seeds the toggle
 * @see tests/php/test-pp7-timeline.php                          the decode + capture guard
 * @see .claude/CLAUDE.md rule #19/#20 (dormant schema), rule #28 A/B/C (dormancy contract shape)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('pp7TimelineImportEnabled')) {
    /**
     * The usage toggle: does the owner want imported ProPresenter auto-advance timelines
     * captured on this env? Defaults false (the shipped, verified-no-op state).
     *
     * ELI5: reads one on/off switch from the settings table. Off means "behave exactly like
     * before this feature existed."
     *
     * Mirrors `_bulkImport_pp7MediaIngestActive()`'s use of `getAppSetting()` for the sibling
     * `pp7_media_ingest_enabled` flag (`includes/song_importers.php`) — same read shape, same
     * safe-default-false-on-any-failure posture (`getAppSetting()` itself degrades to its
     * `$default` argument on a DB-down / missing-row condition, never throws).
     *
     * @param \mysqli $db accepted for signature symmetry with the other two helpers here and
     *                    with `_bulkImport_pp7MediaIngestActive(\mysqli $db)`; the underlying
     *                    `getAppSetting()` call opens its own connection via `getDbMysqli()`
     *                    (the existing helper's own established shape — not this file's choice
     *                    to introduce).
     */
    function pp7TimelineImportEnabled(\mysqli $db): bool
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';
        return getAppSetting('pp7_timeline_import_enabled', '0') === '1';
    }
}

if (!function_exists('pp7TimelineTableExists')) {
    /**
     * Memoised INFORMATION_SCHEMA probe for `tblSongPresentationCues` (STRICT-safe on an
     * un-migrated install — the `songMediaVisibilityColumnExists()` shape). Degrades to
     * "absent" on any probe failure (rule #9's safe direction: never let a probe itself throw).
     */
    function pp7TimelineTableExists(\mysqli $db): bool
    {
        static $exists = null;
        if ($exists !== null) { return $exists; }
        try {
            $r = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongPresentationCues'
                  LIMIT 1"
            );
            $exists = $r && $r->fetch_row() !== null;
            if ($r) { $r->close(); }
        } catch (\Throwable $_e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('pp7TimelineStore')) {
    /**
     * Delete-then-insert the auto-advance cues for one (song, arrangement) timeline —
     * idempotent: re-storing the same import (or a corrected re-import) replaces the prior rows
     * for that exact (SongId, ArrangementName) rather than accumulating duplicates.
     *
     * GATED, NON-THROWING, NO-OP BY DEFAULT: returns 0 immediately — writing NOTHING — when
     * `$songId` is empty, when `pp7TimelineImportEnabled()` is false (the shipped default), or
     * when `pp7TimelineTableExists()` is false (an un-migrated install). Every DB statement is
     * additionally wrapped in try/catch so a transient failure degrades to 0 rather than
     * propagating — this function is safe to call standalone (not only from inside the
     * importer's own try/catch, which wraps this AGAIN as defence in depth per this task's
     * "never fail the surrounding song import" contract).
     *
     * STRICT-mysqli safe (rule #5/#9): every value is bound via `bind_param()`, never
     * interpolated; `$arrangementName` and each cue's `name` are defensively truncated to their
     * column widths (VARCHAR(100)) rather than left to a STRICT-mode data-truncation throw.
     *
     * @param \mysqli $db
     * @param string  $songId          the already-created/updated song's SongId (tblSongs.SongId)
     * @param string  $arrangementName the multiplicity discriminator (rule #20); '' = the
     *                                 default/primary timeline
     * @param array{duration:?float,loop:bool,cues:array<int,array{triggerSeconds:float,cueUuid:string,name:string}>} $timeline
     *        the `pp7DecodeTimeline()` return shape
     * @return int rows WRITTEN (inserted) — 0 when gated off, when `$timeline['cues']` is empty
     *         (even though a prior import's stale rows for this arrangement are still cleared),
     *         or on any storage failure
     */
    function pp7TimelineStore(\mysqli $db, string $songId, string $arrangementName, array $timeline): int
    {
        if ($songId === '') {
            return 0;
        }
        if (!pp7TimelineImportEnabled($db) || !pp7TimelineTableExists($db)) {
            return 0;
        }

        // Defensive width cap (VARCHAR(100)) — a curator-facing display/grouping label, not
        // authoritative for anything; truncating is safe, a STRICT-mode throw here is not.
        $arrangementName = mb_substr($arrangementName, 0, 100);

        $cues = $timeline['cues'] ?? [];
        if (!is_array($cues)) {
            $cues = [];
        }

        try {
            $del = $db->prepare('DELETE FROM tblSongPresentationCues WHERE SongId = ? AND ArrangementName = ?');
            $del->bind_param('ss', $songId, $arrangementName);
            $del->execute();
            $del->close();

            if (empty($cues)) {
                return 0; // nothing to (re-)insert; the stale-row delete above already ran
            }

            $ins = $db->prepare(
                'INSERT INTO tblSongPresentationCues
                    (SongId, ArrangementName, SortOrder, TriggerSeconds, SourceCueUuid, CueName, Source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $written = 0;
            $source = 'propresenter';
            $sortOrder = 0;
            foreach ($cues as $cue) {
                if (!is_array($cue)) {
                    continue;
                }
                $triggerSeconds = isset($cue['triggerSeconds']) ? (float)$cue['triggerSeconds'] : 0.0;

                // '' (no cue_id — the timeline entry's oneof took the `action` branch instead,
                // e.g. a media-triggering cue) is stored as NULL, never as an empty CHAR(36).
                $cueUuidRaw = isset($cue['cueUuid']) ? (string)$cue['cueUuid'] : '';
                $sourceCueUuid = $cueUuidRaw !== '' ? $cueUuidRaw : null;

                $nameRaw = isset($cue['name']) ? (string)$cue['name'] : '';
                $cueName = $nameRaw !== '' ? mb_substr($nameRaw, 0, 100) : null;

                $ins->bind_param(
                    'ssidsss',
                    $songId,
                    $arrangementName,
                    $sortOrder,
                    $triggerSeconds,
                    $sourceCueUuid,
                    $cueName,
                    $source
                );
                $ins->execute();
                $written++;
                $sortOrder++;
            }
            $ins->close();
            return $written;
        } catch (\Throwable $_e) {
            return 0;
        }
    }
}
