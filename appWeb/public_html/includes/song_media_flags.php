<?php

declare(strict_types=1);

/**
 * iHymns — HasAudio / HasSheetMusic derivation (#1862 sub-build D, epic #1863)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tblSongs.HasAudio` / `HasSheetMusic` used to be checkboxes a curator
 * ticked by hand. That is exactly the kind of "a source of truth already
 * answers this" field rule #44 bans — this file computes the two flags from
 * what is ACTUALLY there (a hosted media row, or a legacy static file) so
 * nobody has to remember to tick a box, and the box can never lie.
 *
 * DETAILED — WHY THIS IS A UNION, NOT JUST "does tblSongMedia have a row"
 * ----------------------------------------------------------------------------
 * The two flags predate `tblSongMedia` (#853) by years — they gate the
 * public STATIC-FILE affordances: the MIDI player
 * (`js/modules/audio.js`, `/data/audio/<SongId>.mid`), the offline
 * `bulk_audio` manifest (`api.php`'s `case 'bulk_audio'`,
 * `/data/audio/<SongId>.mp3`) and the sheet-music PDF viewer
 * (`js/modules/sheet-music.js`, `/data/music/<SongId>.pdf`). Most of the
 * corpus's audio/sheet-music came from the original MissionPraise scrape as
 * these static files, never as a `tblSongMedia` row. A recompute that only
 * checked `tblSongMedia` would zero the flag — and silently kill the public
 * Audio/Sheet buttons and the offline audio manifest — for most of the
 * catalogue. So the derivation is deliberately a UNION of both sources; see
 * `songMediaRecomputeFlags()` below.
 *
 * Kind -> flag map lives ONCE, in `songMediaFlagKinds()` — never re-typed at
 * a call site (rule #22). `midi` counts toward `HasAudio` because the public
 * player IS a MIDI player; `musicxml` counts toward neither flag (decision
 * D8 in the #1862 spec).
 *
 * WHO CALLS THIS
 * ----------------------------------------------------------------------------
 * Every `tblSongMedia` writer (api2.php `media_upload` / `media_delete`,
 * the legacy `manage/editor/api.php` `song_media_upload` /
 * `song_media_delete`), POST-COMMIT, own failure boundary — this file NEVER
 * throws (see `songMediaRecomputeFlags()`'s doc-block) because a denorm
 * hiccup must never cost a curator their media upload/delete. Guarded by
 * `tests/php/test-editor2-metadata-1862.php`'s tree-derived wiring scan: any
 * FUTURE `tblSongMedia` writer that forgets this hook fails CI, not just a
 * human review.
 *
 * Direct access is blocked (same guard as publisher_helpers.php /
 * musician_helpers.php) so this file can't be requested as an endpoint via
 * an open Apache config.
 *
 * @link appWeb/public_html/manage/editor/api2.php                 media_upload / media_delete hooks
 * @link appWeb/public_html/manage/editor/api.php                  legacy song_media_upload / song_media_delete hooks
 * @link appWeb/.sql/migrate-reconcile-media-flags.php              the one-time reconcile card (manual, docroot-sensitive)
 * @link tests/php/test-editor2-metadata-1862.php                  the tree-derived wiring guard
 * @see #1862, epic #1863
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('songMediaFlagKinds')) {
    /**
     * The ONE kind -> flag map (decision D8). `musicxml` is deliberately
     * absent from both lists — it counts toward neither flag.
     *
     * @return array{HasAudio: string[], HasSheetMusic: string[]}
     */
    function songMediaFlagKinds(): array
    {
        return [
            'HasAudio'      => ['audio', 'midi'],
            'HasSheetMusic' => ['sheet-music'],
        ];
    }
}

if (!function_exists('songMediaStaticPaths')) {
    /**
     * The legacy static-file paths a song's audio/sheet-music may live at,
     * predating `tblSongMedia` (#853). Resolved against this file's OWN
     * location (`appWeb/public_html/includes/`) — this file always lives
     * INSIDE the docroot, so `dirname(__DIR__)` is the live docroot on every
     * channel regardless of the per-channel rename (rule #41); that rule's
     * concern is a `.sql/` migration reaching BACK into the docroot by a
     * literal name, which is a different file (see
     * migrate-reconcile-media-flags.php for where that gate actually
     * applies).
     *
     * @param string $songId
     * @return array{audioMid: string, audioMp3: string, sheetPdf: string}
     *   Absolute filesystem paths — existence is NOT checked here.
     */
    function songMediaStaticPaths(string $songId): array
    {
        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        return [
            'audioMid' => $dataDir . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . $songId . '.mid',
            'audioMp3' => $dataDir . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . $songId . '.mp3',
            'sheetPdf' => $dataDir . DIRECTORY_SEPARATOR . 'music' . DIRECTORY_SEPARATOR . $songId . '.pdf',
        ];
    }
}

if (!function_exists('_songMediaFlagsTableExists')) {
    /**
     * Memoised existence probe for tblSongMedia — mirrors
     * `ed2_songMediaTableExists()` in api2.php (same shape, independent copy
     * so this file has no dependency on the editor API module). Degrades to
     * "table absent" on any probe failure (rule #9's safe direction).
     */
    function _songMediaFlagsTableExists(\mysqli $db): bool
    {
        static $exists = null;
        if ($exists !== null) { return $exists; }
        try {
            $r = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
            );
            $exists = $r && $r->fetch_row() !== null;
            if ($r) { $r->close(); }
        } catch (\Throwable $_e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('songMediaRecomputeFlags')) {
    /**
     * Recompute + write `tblSongs.HasAudio` / `HasSheetMusic` for ONE song as
     * the UNION described in this file's header — a hosted `tblSongMedia`
     * row of the right kind, OR the legacy static file.
     *
     * ELI5: after a media file is added or removed, this looks at everything
     * that could make "yes, this song has audio/sheet music" true and writes
     * the honest answer.
     *
     * FAIL-SAFE BY DESIGN: every caller invokes this POST-COMMIT, in its own
     * failure boundary — a flag-recompute hiccup (a transient DB blip, a
     * filesystem permission error) must NEVER roll back or fail the media
     * upload/delete that triggered it (the `ilidStampNewRow()` / #1843
     * janitor precedent). This function therefore never throws: every
     * failure is caught and logged, not propagated. It also NO-OPS
     * gracefully when `tblSongMedia` is absent (pre-#853 install) — the
     * static-file half of the union still runs.
     *
     * Write-if-changed: the UPDATE carries a `WHERE … AND (HasAudio<>? OR
     * HasSheetMusic<>?)` guard so a no-op recompute never touches
     * `UpdatedAt` or fires an unnecessary write.
     *
     * @param \mysqli $db
     * @param string  $songId
     */
    function songMediaRecomputeFlags(\mysqli $db, string $songId): void
    {
        if ($songId === '') { return; }
        try {
            $kinds = songMediaFlagKinds();

            $hasAudioMedia = false;
            $hasSheetMedia = false;
            if (_songMediaFlagsTableExists($db)) {
                /* #1968 P4 — an `admin`-only (unpublished) audio row must NOT
                   flip HasAudio=1 and advertise audio the public page won't
                   show. The public-visibility filter (probe-gated → '' when the
                   column is absent) restricts the recompute to PUBLIC rows +
                   legacy static files, keeping the denorm flags honest. */
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_media_visibility.php';
                $visFilter = songMediaVisibilityPublicFilterSql($db);
                $allMediaKinds = array_values(array_unique(array_merge($kinds['HasAudio'], $kinds['HasSheetMusic'])));
                if ($allMediaKinds) {
                    $placeholders = implode(',', array_fill(0, count($allMediaKinds), '?'));
                    $stmt = $db->prepare(
                        "SELECT DISTINCT Kind FROM tblSongMedia WHERE SongId = ? AND Kind IN ({$placeholders}){$visFilter}"
                    );
                    $types  = 's' . str_repeat('s', count($allMediaKinds));
                    $params = array_merge([$songId], $allMediaKinds);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $foundKinds = [];
                    while ($row = $res->fetch_row()) { $foundKinds[] = (string)$row[0]; }
                    $stmt->close();
                    $hasAudioMedia = (bool)array_intersect($foundKinds, $kinds['HasAudio']);
                    $hasSheetMedia = (bool)array_intersect($foundKinds, $kinds['HasSheetMusic']);
                }
            }

            $paths = songMediaStaticPaths($songId);
            $hasAudioStatic = is_file($paths['audioMid']) || is_file($paths['audioMp3']);
            $hasSheetStatic = is_file($paths['sheetPdf']);

            $hasAudio = ($hasAudioMedia || $hasAudioStatic) ? 1 : 0;
            $hasSheet = ($hasSheetMedia || $hasSheetStatic) ? 1 : 0;

            $u = $db->prepare(
                'UPDATE tblSongs SET HasAudio = ?, HasSheetMusic = ?
                  WHERE SongId = ? AND (HasAudio <> ? OR HasSheetMusic <> ?)'
            );
            $u->bind_param('iisii', $hasAudio, $hasSheet, $songId, $hasAudio, $hasSheet);
            $u->execute();
            $u->close();
        } catch (\Throwable $e) {
            /* Never propagate — see the doc-block above. A recompute failure
               leaves the DENORM stale (self-healing on the next media
               touch), never the media write itself. */
            error_log('[songMediaRecomputeFlags] ' . $e->getMessage());
        }
    }
}
