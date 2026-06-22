<?php

declare(strict_types=1);

/**
 * ============================================================================
 * iHymns Song Editor — API Endpoint (#154, #227, #275)
 * ============================================================================
 *
 * Provides PHP-powered read/write access to song data via MySQL.
 * Protected by session-based authentication — only authenticated
 * admin users can access this endpoint.
 *
 * ENDPOINTS (all DB-direct — no songs.json file cache as of WS-J #1020):
 *   GET  ?action=load_index       — slim song index for the sidebar
 *   GET  ?action=load_song&id=…   — one full editable record
 *   GET  ?action=load_songs       — batch full records for bulk ops
 *   GET  ?action=songbook_export&abbr=…  — one songbook's songs as a bundle
 *   POST ?action=save_song        — write one song's edits to MySQL
 *
 * SECURITY:
 *   - Requires authenticated session (via /manage/ auth system)
 *   - All MySQL queries use MySQLi with prepared statements
 *   - Input validation on save: must be valid JSON with required structure
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * @license Proprietary — All rights reserved
 * @requires PHP 8.1+ with mysqli extension
 * ============================================================================
 */

/* =========================================================================
 * AUTHENTICATION
 * ========================================================================= */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

/* Verify authentication and editor+ role — return 401/403 JSON for AJAX */
if (!isAuthenticated()) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser || !hasRole($currentUser['role'], 'editor')) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['error' => 'Editor access required.']);
    exit;
}

/* =========================================================================
 * BOOTSTRAP — Load MySQL connection and SongData
 * ========================================================================= */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
/* Places adoption helper — exposes placeColumnExists() so the
   save_song path persists OriginCityId alongside the legacy
   OriginCity display string only when the places-adoption
   migration has landed. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';
/* logActivity / logActivityError — needed by every action that
   wants to write a tblActivityLog row (save_song, bulk_import_*,
   load failure path). Was previously imported transitively in
   some call sites and absent in others; pulling it here means
   `function_exists('logActivity')` is always true inside this
   endpoint and the helper is available unconditionally. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Mirror every uncaught \Throwable + PHP fatal in any editor-API
   case (save_song, bulk_import_*, typeaheads, load) into
   tblActivityLog. Per-case try/catches still write their own
   contextual rows — this is the safety net for failures that
   escape them entirely. */
installGlobalActivityLogHandlers('editor_api');

/**
 * Cached check for the tblSongArtists table (#587). The table arrives
 * via migrate-song-artists.php; until that migration has been
 * applied, the save_song path needs to skip both the DELETE and the
 * INSERT for artists rather than 500ing on a partly-migrated install.
 * Static cache so the INFORMATION_SCHEMA round-trip happens once per
 * request even when save_song runs in a loop.
 */
function _songArtistsTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongArtists' LIMIT 1"
    );
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $cached;
}


/* Bulk-import parsers + universal saver + the two shared validators
   (_ietfBcp47Validate / _sanitiseArrangement) + the BULK_IMPORT_* constants now
   live in includes/song_importers.php so the clean v2 editor API (api2.php)
   reuses the SAME parser code instead of forking it (#1200 Phase 4b). Required
   ABOVE the switch so the constants are defined before any handler runs. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_importers.php';
/* #1235 P3 / #1253 — the per-line-language column readiness probe
   (lyricLinesComponentsLangReady) + the shared LanguagesJson builder
   (lineEnrichmentBuildLanguagesJson), so save_song persists per-line language
   overrides and load_song surfaces them — the SAME shared layer api2.php uses. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'line_enrichment.php';

/* =========================================================================
 * REQUEST HANDLING
 * ========================================================================= */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = $_GET['action'] ?? '';

switch ($action) {

    /* -----------------------------------------------------------------
     * SONGBOOK_EXPORT — full song bundle for ONE songbook (WS-J #1020)
     *
     * Replaces the old whole-corpus 'load' case that /manage/songbooks.php
     * downloaded and filtered client-side (~140 MB via the songs.json file
     * cache). Now a DB-direct per-songbook query: SongData::getSongs($abbr)
     * returns exactly the same rich per-song shape exportAsJson() produced,
     * scoped to one songbook, so the export bundle is byte-equivalent without
     * materialising the corpus. The songbook record (for the canonical export
     * filename) is returned alongside.
     *
     * GET ?action=songbook_export&abbr=CP
     * ----------------------------------------------------------------- */
    case 'songbook_export':
        $abbr = isset($_GET['abbr']) ? strtoupper(trim((string)$_GET['abbr'])) : '';
        if ($abbr === '' || !preg_match('/^[A-Z0-9]{1,20}$/', $abbr)) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid songbook abbreviation is required.']);
            break;
        }
        try {
            header('X-Content-Type-Options: nosniff');
            $songData = new SongData();
            $songs = $songData->getSongs($abbr);
            $songbook = null;
            foreach ($songData->getSongbooks() as $b) {
                $bid = strtoupper((string)($b['id'] ?? $b['abbreviation'] ?? ''));
                if ($bid === $abbr) { $songbook = $b; break; }
            }
            echo json_encode(['songs' => $songs, 'songbook' => $songbook]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[iHymns Editor] songbook_export failed: ' . $e->getMessage());
            logActivityError('editor.songbook_export_failed', 'songbook', $abbr, $e, [
                'trace' => mb_substr($e->getTraceAsString(), 0, 4096),
            ]);
            echo json_encode([
                'error'  => 'Failed to export songbook.',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }
        break;

    /* -----------------------------------------------------------------
     * LOAD_INDEX — lightweight slim index for the editor sidebar (#1016)
     *
     * id/number/title/songbook/songbookName per song, NO lyrics or
     * components. Replaces the whole-corpus 'load' on editor open so the
     * editor stops downloading the ~140 MB corpus; the full per-song
     * record is fetched on demand via 'load_song'. Returns the same
     * { meta, songbooks, songs } envelope the client already consumes,
     * just with lightweight song rows.
     * ----------------------------------------------------------------- */
    case 'load_index':
        try {
            $songData = new SongData();
            echo json_encode([
                'meta'      => $songData->getMeta(),
                'songbooks' => $songData->getSongbooks(),
                'songs'     => $songData->getSongsSlimIndex(),
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[iHymns Editor] load_index failed: ' . $e->getMessage());
            echo json_encode([
                'error'  => 'Failed to load song index.',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }
        break;

    /* -----------------------------------------------------------------
     * LOAD_SONG — full editable record for ONE song (#1016)
     *
     * Per-record live fetch used when the curator opens a song from the
     * sidebar. Returns the same rich shape the corpus used to carry
     * (components + every credit type + tags + translations + alt titles
     * + external links + works + media), via SongData::getSongById().
     * ----------------------------------------------------------------- */
    case 'load_song':
        $songId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Song id is required.']);
            break;
        }
        try {
            $songData = new SongData();
            $song = $songData->getSongById($songId);
            if ($song === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Song not found: ' . $songId]);
            } else {
                /* #1235 P3 — attach the RAW per-line language overrides
                   (tblSongComponents.LanguagesJson) so the editor edits overrides
                   directly; getSongById only emits the EFFECTIVE per-line language
                   for rendering. Zipped by component order — both getSongById's
                   _getComponents and this read order by SortOrder. Guarded for
                   un-migrated installs. */
                if (is_array($song['components'] ?? null)) {
                    $db = getDbMysqli();
                    if (lyricLinesComponentsLangReady($db)) {
                        $langsByIdx = [];
                        $lr = $db->prepare(
                            "SELECT LanguagesJson FROM tblSongComponents
                              WHERE SongId = ? ORDER BY SortOrder, Id"
                        );
                        $lr->bind_param('s', $songId);
                        $lr->execute();
                        $lres = $lr->get_result();
                        while ($lrow = $lres->fetch_assoc()) {
                            $langsByIdx[] = $lrow['LanguagesJson'] !== null
                                ? json_decode((string)$lrow['LanguagesJson'], true) : null;
                        }
                        $lr->close();
                        foreach ($song['components'] as $ci => &$cref) {
                            $cref['languages'] = $langsByIdx[$ci] ?? null;
                        }
                        unset($cref);
                    } else {
                        /* #1235 P4/C6 — post-drop: tblSongComponents.LanguagesJson is gone,
                           so DERIVE the per-line override array from the EFFECTIVE per-line
                           language getSongById already assembled from the authoritative
                           tblLyricLines (comp.lineLanguages). effective ≡ override under the
                           inherit rule (a line whose effective language differs from the
                           component default WAS an override; equal = inherit = null). Keeps
                           the legacy editor's per-line language editing working after the
                           drop instead of silently dropping the overrides. */
                        foreach ($song['components'] as &$cref) {
                            $compLang = (isset($cref['language']) && $cref['language'] !== '') ? $cref['language'] : null;
                            $eff = is_array($cref['lineLanguages'] ?? null) ? $cref['lineLanguages'] : null;
                            if ($eff === null) { $cref['languages'] = null; continue; }
                            $override = [];
                            $any = false;
                            foreach ($eff as $lv) {
                                $ov = ($lv !== null && $lv !== $compLang) ? $lv : null;
                                $override[] = $ov;
                                if ($ov !== null) { $any = true; }
                            }
                            $cref['languages'] = $any ? $override : null;
                        }
                        unset($cref);
                    }
                    /* #1235 P3 / #1088 — attach per-line translations + annotations
                       (the editor edits them by tblLyricLines.Id, which the
                       components now expose as lineIds). Nested INTO the song so
                       _loadSongFull (which returns d.song) carries them. Empty
                       arrays on an un-migrated install. */
                    $enr = lineEnrichmentForSong(getDbMysqli(), $songId);
                    $song['lineTranslations'] = $enr['translations'];
                    $song['lineAnnotations']  = $enr['annotations'];
                }
                echo json_encode(['song' => $song]);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[iHymns Editor] load_song failed: ' . $e->getMessage());
            echo json_encode([
                'error'  => 'Failed to load song.',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }
        break;

    /* -----------------------------------------------------------------
     * LOAD_SONGS — batch full-record fetch (#1016)
     *
     * POST { ids: [...] }. Returns the full editable record for each id,
     * so the multi-select bulk operations (verify / move / export) work
     * on COMPLETE songs. Without this, a bulk edit would mutate a slim
     * sidebar stub and save_song would then DELETE-then-INSERT empty
     * credits/components — wiping the song's lyrics + credits. Bounded so
     * a pathological request can't fan out unboundedly.
     * ----------------------------------------------------------------- */
    case 'load_songs':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $ids  = (is_array($body) && isset($body['ids']) && is_array($body['ids'])) ? $body['ids'] : [];
        if (empty($ids)) {
            echo json_encode(['songs' => []]);
            break;
        }
        if (count($ids) > 2000) {
            $ids = array_slice($ids, 0, 2000);
        }
        try {
            $songData = new SongData();
            $out = [];
            foreach ($ids as $rawId) {
                $id = trim((string)$rawId);
                if ($id === '') {
                    continue;
                }
                $song = $songData->getSongById($id);
                if ($song !== null) {
                    $out[] = $song;
                }
            }
            echo json_encode(['songs' => $out]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[iHymns Editor] load_songs failed: ' . $e->getMessage());
            echo json_encode([
                'error'  => 'Failed to load songs.',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }
        break;

    /* -----------------------------------------------------------------
     * SAVE — RETIRED (#1016)
     *
     * The whole-corpus save (TRUNCATE-all + re-INSERT-all) was dead code
     * — the editor saves per record via save_song — and a data-loss
     * footgun: a malformed or partial POST body would wipe every song,
     * songbook, credit, and component row. Per-record save_song is the
     * only write path now. Kept as a guarded 410 so any stray caller
     * gets a clear, safe error instead of silently destroying data.
     * ----------------------------------------------------------------- */
    case 'save':
        http_response_code(410);
        echo json_encode([
            'error'  => 'The whole-corpus save endpoint has been retired (#1016). '
                      . 'Use save_song for per-record writes.',
            'action' => 'save_song',
        ]);
        break;

    /* -----------------------------------------------------------------
     * TRANSLATIONS — Manage song translation links (#352)
     * ----------------------------------------------------------------- */

    /* Get translations for a song */
    case 'get_translations':
        $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Song ID is required.']);
            break;
        }
        try {
            $db   = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT t.Id AS id, t.TranslatedSongId AS songId,
                        t.TargetLanguage AS language, t.Translator AS translator,
                        t.Verified AS verified, s.Title AS title, s.Number AS number
                 FROM tblSongTranslations t
                 JOIN tblSongs s ON s.SongId = t.TranslatedSongId
                 WHERE t.SourceSongId = ?
                 ORDER BY t.TargetLanguage ASC'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $translations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($translations as &$tr) {
                $tr['id'] = (int)$tr['id'];
                $tr['verified'] = (bool)$tr['verified'];
                $tr['number'] = (int)$tr['number'];
            }
            unset($tr);
            echo json_encode(['translations' => $translations]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load translations.']);
        }
        break;

    /* Add a translation link */
    case 'add_translation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $srcId = trim($body['sourceSongId'] ?? '');
        $tgtId = trim($body['translatedSongId'] ?? '');
        $lang  = trim($body['language'] ?? '');
        $translator = trim($body['translator'] ?? '');

        if ($srcId === '' || $tgtId === '' || $lang === '') {
            http_response_code(400);
            echo json_encode(['error' => 'sourceSongId, translatedSongId, and language are required.']);
            break;
        }
        if ($srcId === $tgtId) {
            http_response_code(400);
            echo json_encode(['error' => 'A song cannot be a translation of itself.']);
            break;
        }

        try {
            $db   = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage, Translator)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE TranslatedSongId = VALUES(TranslatedSongId),
                                         Translator = VALUES(Translator)'
            );
            $stmt->bind_param('ssss', $srcId, $tgtId, $lang, $translator);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[iHymns Editor] add_translation failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to add translation link.']);
        }
        break;

    /* Remove a translation link */
    case 'remove_translation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $removeId = (int)($body['id'] ?? 0);
        if ($removeId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Translation link ID is required.']);
            break;
        }

        try {
            $db   = getDbMysqli();
            $stmt = $db->prepare('DELETE FROM tblSongTranslations WHERE Id = ?');
            $stmt->bind_param('i', $removeId);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            echo json_encode(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove translation link.']);
        }
        break;

    /* -----------------------------------------------------------------
     * GET_SONG_LINKS — Cross-book counterparts for one song (#807)
     *
     * Returns every other tblSongs row that shares this song's
     * tblSongLinks.GroupId — i.e. every counterpart appearance of
     * the same hymn in a different songbook. Distinct from
     * get_translations (different-language same hymn) and from the
     * songbook-level parent link (#782 phase D).
     * ----------------------------------------------------------------- */
    case 'get_song_links':
        $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Song ID is required.']);
            break;
        }
        try {
            $db = getDbMysqli();
            /* Two-step: find this song's GroupId (if any), then list
               every OTHER member of that group. Returning the GroupId
               itself lets the editor UI distinguish "no group yet" from
               "in a group but I'm the only member" — both render as an
               empty list, but the second case shouldn't happen and is
               worth surfacing if it does. */
            $stmt = $db->prepare('SELECT GroupId FROM tblSongLinks WHERE SongId = ? LIMIT 1');
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $groupId = $row ? (int)$row['GroupId'] : 0;

            $links = [];
            if ($groupId > 0) {
                $stmt = $db->prepare(
                    'SELECT l.Id          AS id,
                            l.SongId      AS songId,
                            l.Note        AS note,
                            l.Verified    AS verified,
                            s.Title       AS title,
                            s.Number      AS number,
                            s.SongbookAbbr AS songbook,
                            sb.Name       AS songbookName,
                            s.Language    AS language
                       FROM tblSongLinks l
                       JOIN tblSongs s      ON s.SongId = l.SongId
                       JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                      WHERE l.GroupId = ?
                        AND l.SongId  <> ?
                      ORDER BY s.SongbookAbbr ASC, s.Number ASC'
                );
                $stmt->bind_param('is', $groupId, $songId);
                $stmt->execute();
                $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($links as &$ln) {
                    $ln['id']       = (int)$ln['id'];
                    $ln['verified'] = (bool)$ln['verified'];
                    $ln['number']   = ($ln['number'] === null) ? null : (int)$ln['number'];
                }
                unset($ln);
            }
            echo json_encode([
                'groupId' => $groupId,
                'links'   => $links,
            ]);
        } catch (\Throwable $e) {
            error_log('[iHymns Editor] get_song_links failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load song links.']);
        }
        break;

    /* -----------------------------------------------------------------
     * ADD_SONG_LINK — Link two songs as cross-book counterparts (#807)
     *
     * Body: { sourceSongId, targetSongId, note? }
     *
     * Behaviour:
     *   - If neither song is in a group, mint a new GroupId and add
     *     both rows under it.
     *   - If exactly one song is in a group, add the other to it.
     *   - If both songs are already in the SAME group, no-op.
     *   - If the two songs are in DIFFERENT groups, refuse — curator
     *     must explicitly merge or unlink first (prevents accidental
     *     loss of a multi-member group).
     * ----------------------------------------------------------------- */
    case 'add_song_link':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $srcId = trim((string)($body['sourceSongId'] ?? ''));
        $tgtId = trim((string)($body['targetSongId'] ?? ''));
        $note  = trim((string)($body['note'] ?? ''));
        if ($srcId === '' || $tgtId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'sourceSongId and targetSongId are required.']);
            break;
        }
        if ($srcId === $tgtId) {
            http_response_code(400);
            echo json_encode(['error' => 'A song cannot be linked to itself.']);
            break;
        }

        try {
            $db = getDbMysqli();

            /* Validate both songs exist before we mutate anything —
               cheaper than catching an FK violation after a partial
               INSERT. */
            $probe = $db->prepare(
                'SELECT SongId FROM tblSongs WHERE SongId IN (?, ?)'
            );
            $probe->bind_param('ss', $srcId, $tgtId);
            $probe->execute();
            $found = [];
            $res = $probe->get_result();
            while ($r = $res->fetch_assoc()) $found[] = $r['SongId'];
            $probe->close();
            if (count($found) < 2) {
                http_response_code(404);
                echo json_encode(['error' => 'One or both songs were not found.']);
                break;
            }

            /* Look up each side's existing group, if any. */
            $lookup = $db->prepare(
                'SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN (?, ?)'
            );
            $lookup->bind_param('ss', $srcId, $tgtId);
            $lookup->execute();
            $existing = [];
            $res = $lookup->get_result();
            while ($r = $res->fetch_assoc()) $existing[$r['SongId']] = (int)$r['GroupId'];
            $lookup->close();

            $srcGroup = $existing[$srcId] ?? 0;
            $tgtGroup = $existing[$tgtId] ?? 0;

            $createdBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;

            if ($srcGroup > 0 && $tgtGroup > 0) {
                if ($srcGroup === $tgtGroup) {
                    /* Already linked — refresh the note on the target row
                       so the curator sees their annotation persisted, but
                       don't error. */
                    if ($note !== '') {
                        $upd = $db->prepare(
                            'UPDATE tblSongLinks SET Note = ? WHERE SongId = ?'
                        );
                        $upd->bind_param('ss', $note, $tgtId);
                        $upd->execute();
                        $upd->close();
                    }
                    echo json_encode(['success' => true, 'groupId' => $srcGroup, 'noop' => true]);
                    break;
                }
                /* Two different groups — refuse. The curator can unlink
                   one side first, or we can add a separate "merge groups"
                   admin action later. */
                http_response_code(409);
                echo json_encode([
                    'error' => 'Both songs are already in different counterpart groups. Unlink one before linking, or use the merge tool.',
                ]);
                break;
            }

            if ($srcGroup === 0 && $tgtGroup === 0) {
                /* Neither in a group — mint a new GroupId.
                   MAX(GroupId)+1 is fine here: tblSongLinks is small,
                   curator-edited, and AUTO_INCREMENT on GroupId would
                   complicate the merge-groups operation. */
                $r = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                $newGroup = $r ? (int)$r->fetch_assoc()['NextId'] : 1;
                if ($r) $r->close();

                $ins = $db->prepare(
                    'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                     VALUES (?, ?, ?, ?), (?, ?, ?, ?)'
                );
                $emptyNote = '';
                $ins->bind_param(
                    'issiisis',
                    $newGroup, $srcId, $emptyNote, $createdBy,
                    $newGroup, $tgtId, $note,      $createdBy
                );
                $ins->execute();
                $ins->close();
                echo json_encode(['success' => true, 'groupId' => $newGroup, 'created' => true]);
                break;
            }

            /* Exactly one side already in a group — extend it. */
            $joinGroup = $srcGroup > 0 ? $srcGroup : $tgtGroup;
            $newSongId = $srcGroup > 0 ? $tgtId    : $srcId;
            $ins = $db->prepare(
                'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->bind_param('issi', $joinGroup, $newSongId, $note, $createdBy);
            $ins->execute();
            $ins->close();
            echo json_encode(['success' => true, 'groupId' => $joinGroup, 'extended' => true]);
        } catch (\Throwable $e) {
            error_log('[iHymns Editor] add_song_link failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add song link.']);
        }
        break;

    /* -----------------------------------------------------------------
     * REMOVE_SONG_LINK — Drop one song from its group (#807)
     *
     * Body: { id?: int, songId?: string }  (either identifier works)
     *
     * If removing leaves the group with only one remaining member, the
     * remaining row is also dropped — a singleton group is meaningless
     * and would otherwise show up as "no counterparts" forever while
     * still occupying a UNIQUE slot.
     * ----------------------------------------------------------------- */
    case 'remove_song_link':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body     = json_decode(file_get_contents('php://input'), true);
        $removeId = (int)($body['id'] ?? 0);
        $songId   = trim((string)($body['songId'] ?? ''));
        if ($removeId <= 0 && $songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id or songId is required.']);
            break;
        }

        try {
            $db = getDbMysqli();

            /* Resolve the row + its GroupId before deleting so we can
               clean up an orphaned singleton in the same transaction. */
            if ($removeId > 0) {
                $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE Id = ?');
                $stmt->bind_param('i', $removeId);
            } else {
                $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE SongId = ?');
                $stmt->bind_param('s', $songId);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                /* Already gone — return success rather than 404 so a
                   double-click on the remove button doesn't surface a
                   spurious error. */
                echo json_encode(['success' => true, 'deleted' => 0]);
                break;
            }

            $groupId = (int)$row['GroupId'];

            $del = $db->prepare('DELETE FROM tblSongLinks WHERE Id = ?');
            $del->bind_param('i', $row['Id']);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            /* If the group now has fewer than two members, drop the
               remainder. A singleton group is meaningless. */
            $r = $db->prepare('SELECT COUNT(*) AS n FROM tblSongLinks WHERE GroupId = ?');
            $r->bind_param('i', $groupId);
            $r->execute();
            $remaining = (int)$r->get_result()->fetch_assoc()['n'];
            $r->close();
            if ($remaining < 2) {
                $cleanup = $db->prepare('DELETE FROM tblSongLinks WHERE GroupId = ?');
                $cleanup->bind_param('i', $groupId);
                $cleanup->execute();
                $cleanup->close();
            }

            echo json_encode(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $e) {
            error_log('[iHymns Editor] remove_song_link failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove song link.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SUGGEST_SONG_LINKS — Top similar-title pairs for one song (#808)
     *
     * Returns up to 5 highest-scoring pending suggestions involving
     * the open song, used by the editor sidebar's inline
     * "Suggested counterparts" panel. Same data the
     * /manage/duplicate-songs review page lists, scoped to a
     * single song.
     * ----------------------------------------------------------------- */
    case 'suggest_song_links':
        $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Song ID is required.']);
            break;
        }
        try {
            $db = getDbMysqli();

            /* Probe the suggestion table — it might not exist yet on
               deployments that haven't run the migration. Returning an
               empty list (rather than 500ing) keeps the editor working
               while the migration's still pending. */
            $probe = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongLinkSuggestions' LIMIT 1"
            );
            $hasTable = $probe && $probe->fetch_row() !== null;
            if ($probe) $probe->close();
            if (!$hasTable) {
                echo json_encode(['suggestions' => [], 'tableMissing' => true]);
                break;
            }

            $stmt = $db->prepare(
                'SELECT s.Id        AS id,
                        s.SongIdA   AS songIdA,
                        s.SongIdB   AS songIdB,
                        s.Score     AS score,
                        s.TitleScore AS titleScore,
                        s.LyricsScore AS lyricsScore,
                        a.Title     AS titleA,
                        a.Number    AS numberA,
                        a.SongbookAbbr AS songbookA,
                        b.Title     AS titleB,
                        b.Number    AS numberB,
                        b.SongbookAbbr AS songbookB
                   FROM tblSongLinkSuggestions s
                   JOIN tblSongs a ON a.SongId = s.SongIdA
                   JOIN tblSongs b ON b.SongId = s.SongIdB
                  WHERE (s.SongIdA = ? OR s.SongIdB = ?)
                    AND NOT EXISTS (
                        SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                         WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB
                    )
                    /* Skip pairs where both songs are already in the
                       same counterpart group — they\'re already linked. */
                    AND NOT EXISTS (
                        SELECT 1
                          FROM tblSongLinks la
                          JOIN tblSongLinks lb ON la.GroupId = lb.GroupId
                         WHERE la.SongId = s.SongIdA AND lb.SongId = s.SongIdB
                    )
                  ORDER BY s.Score DESC
                  LIMIT 5'
            );
            $stmt->bind_param('ss', $songId, $songId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            /* Normalise the response so the "other side" is always in
               a single `other` field, regardless of which slot the
               current song was found in. */
            $suggestions = [];
            foreach ($rows as $r) {
                $isA = ($r['songIdA'] === $songId);
                $suggestions[] = [
                    'id'          => (int)$r['id'],
                    'score'       => (float)$r['score'],
                    'titleScore'  => (float)$r['titleScore'],
                    'lyricsScore' => (float)$r['lyricsScore'],
                    'other' => [
                        'songId'   => $isA ? $r['songIdB']  : $r['songIdA'],
                        'title'    => $isA ? $r['titleB']   : $r['titleA'],
                        'number'   => $isA ? $r['numberB']  : $r['numberA'],
                        'songbook' => $isA ? $r['songbookB']: $r['songbookA'],
                    ],
                ];
            }
            echo json_encode(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            error_log('[iHymns Editor] suggest_song_links failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load suggestions.']);
        }
        break;

    /* -----------------------------------------------------------------
     * DISMISS_SONG_LINK_SUGGESTION — Curator says "no, different hymns" (#808)
     *
     * Body: { songIdA, songIdB, reason? }  (canonical order enforced
     *       server-side so callers needn't pre-sort)
     * ----------------------------------------------------------------- */
    case 'dismiss_song_link_suggestion':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $a    = trim((string)($body['songIdA'] ?? ''));
        $b    = trim((string)($body['songIdB'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        if ($a === '' || $b === '' || $a === $b) {
            http_response_code(400);
            echo json_encode(['error' => 'songIdA and songIdB are required and must differ.']);
            break;
        }
        /* Canonicalise: SongIdA < SongIdB lexicographically, matching
           the build-script invariant. */
        if ($a > $b) { [$a, $b] = [$b, $a]; }

        try {
            $db = getDbMysqli();
            $dismissedBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
            $stmt = $db->prepare(
                'INSERT INTO tblSongLinkSuggestionsDismissed (SongIdA, SongIdB, DismissedBy, Reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE Reason = VALUES(Reason),
                                         DismissedBy = VALUES(DismissedBy),
                                         DismissedAt = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('ssis', $a, $b, $dismissedBy, $reason);
            $stmt->execute();
            $stmt->close();

            /* Drop the matching pending suggestion so it disappears
               from every consumer immediately. */
            $del = $db->prepare(
                'DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?'
            );
            $del->bind_param('ss', $a, $b);
            $del->execute();
            $del->close();

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            error_log('[iHymns Editor] dismiss_song_link_suggestion failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to dismiss suggestion.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SAVE_SONG — Write a single song's data (#394)
     *
     * UPSERT of one song + its child rows (writers/composers/components)
     * plus an audit row in tblSongRevisions (#400). Much cheaper than
     * the full-corpus `save` action and safe to call from the editor's
     * debounced auto-save every few seconds.
     *
     * Body: a single song object matching the data/songs.json shape.
     * ----------------------------------------------------------------- */
    case 'save_song':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }

        $rawBody = file_get_contents('php://input');
        $song    = json_decode($rawBody ?: '', true);
        if (!is_array($song) || empty($song['id']) || empty($song['title'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields: id, title.']);
            break;
        }

        $songId       = (string)$song['id'];
        $songbookAbbr = trim((string)($song['songbook'] ?? ''));
        /* Every song MUST belong to a songbook — tblSongs.SongbookAbbr is
           NOT NULL with an FK to tblSongbooks. When a save arrives with no
           songbook, default to the canonical generic "Misc" collection
           rather than failing the INSERT on the constraint (or, on a
           pre-FK install, silently creating an orphan with a blank
           songbook). Misc is a seeded songbook whose Number is nullable,
           so an unnumbered Misc song is valid. */
        if ($songbookAbbr === '') {
            $songbookAbbr = 'Misc';
        }

        /* Probe tblSongbooks for IsOfficial so songs in unofficial
           songbooks (Misc, custom collections) can persist Number as
           NULL while songs in official songbooks keep the per-songbook
           number. The probe happens here (outside the transaction
           below) because the songbook row is read-only context. #392. */
        $isOfficialSongbook = false;
        if ($songbookAbbr !== '') {
            $probe = getDbMysqli()->prepare(
                'SELECT IsOfficial FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1'
            );
            $probe->bind_param('s', $songbookAbbr);
            $probe->execute();
            $row = $probe->get_result()->fetch_assoc();
            $probe->close();
            $isOfficialSongbook = (bool)($row['IsOfficial'] ?? false);
        }

        /* Songs in non-official songbooks (Misc, custom collections)
           persist Number as NULL — and so does anything missing or
           non-positive. The internal SongId is the cross-record link
           in that case. #392. */
        $rawNumber = $song['number'] ?? null;
        $number    = (!$isOfficialSongbook || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
            ? null
            : (int)$rawNumber;

        $title        = (string)$song['title'];
        $songbookName = (string)($song['songbookName'] ?? '');
        /* IETF BCP 47 validation (#681). Empty string normalises to
           'en' for tblSongs.Language NOT NULL DEFAULT 'en'; a malformed
           tag (variants, extensions, anything past the v1 grammar) is
           rejected up-front so the rest of the save runs against a
           well-formed value. */
        $rawLang = (string)($song['language'] ?? 'en');
        $valid   = _ietfBcp47Validate($rawLang);
        if ($valid === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid IETF BCP 47 language tag: ' . $rawLang]);
            break;
        }
        $language     = $valid ?? 'en';
        $copyright    = (string)($song['copyright']   ?? '');
        /* Places adoption — composition / first-performance origin.
           VARCHAR mirror persists either way; the FK is set only
           when the curator picked a candidate from the live
           autocomplete (the editor.js wiring keeps both halves in
           sync). Schema-tolerant: the per-place UPDATE below
           skips itself on pre-adoption-migration installs. */
        $originCityRaw = trim((string)($song['originCity'] ?? ''));
        $originCity    = $originCityRaw === '' ? null : $originCityRaw;
        $originCityId  = (int)($song['originCityId'] ?? 0) ?: null;
        /* TuneName + Iswc are nullable (#497). Empty/whitespace-only
           input normalises to NULL so the indexed TuneName column
           groups "unknown" rows together. */
        $tuneRaw      = trim((string)($song['tuneName'] ?? ''));
        $tuneName     = $tuneRaw === '' ? null : $tuneRaw;
        $ccli         = (string)($song['ccli']        ?? '');
        $iswcRaw      = trim((string)($song['iswc']   ?? ''));
        $iswc         = $iswcRaw === '' ? null : $iswcRaw;
        $verified     = (int)($song['verified']           ?? 0);
        $lyricsPD     = (int)($song['lyricsPublicDomain'] ?? 0);
        $musicPD      = (int)($song['musicPublicDomain']  ?? 0);
        $hasAudio     = (int)($song['hasAudio']           ?? 0);
        $hasSheet     = (int)($song['hasSheetMusic']      ?? 0);

        /* Build lyrics_text for FULLTEXT index */
        $lyricsLines = [];
        foreach ($song['components'] ?? [] as $comp) {
            foreach ($comp['lines'] ?? [] as $line) {
                $lyricsLines[] = $line;
            }
        }
        $lyricsText = implode("\n", $lyricsLines);

        /* #892 — sanitise the arrangement payload before the txn so any
           validation rejection 400s the caller cleanly. The editor sends
           an int[] of indices into components[] (repetition allowed —
           the entire reason this column exists). NULL / empty / invalid
           → store NULL so the public render falls back to plain
           SortOrder (current pre-#892 behaviour). */
        /* Data-integrity guard (#1178): collapse EXACT-duplicate components
           (same type + number + lines) to a single instance, keeping the first.
           A client-side accumulation bug could otherwise persist the same blank
           "Verse 1" many times (observed on a new Misc song). Only EXACT repeats
           collapse, so a single in-progress blank component is preserved; the
           arrangement (int[] indices into components) is remapped through the
           dedup so its integrity survives. */
        if (is_array($song['components'] ?? null) && count($song['components']) > 1) {
            $seenComp = [];
            $keptComp = [];
            $idxRemap = [];   /* old component index → new index */
            foreach ($song['components'] as $oldIdx => $comp) {
                $cType  = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $cLines = is_array($comp['lines'] ?? null) ? $comp['lines'] : [];
                $sig    = $cType . '|' . $cNum . '|' . json_encode($cLines, JSON_UNESCAPED_UNICODE);
                if (isset($seenComp[$sig])) {
                    $idxRemap[$oldIdx] = $seenComp[$sig];   /* point dup refs at the kept copy */
                    continue;
                }
                $seenComp[$sig]    = count($keptComp);
                $idxRemap[$oldIdx] = count($keptComp);
                $keptComp[]        = $comp;
            }
            if (count($keptComp) !== count($song['components'])) {
                $song['components'] = $keptComp;
                if (is_array($song['arrangement'] ?? null)) {
                    $remappedArr = [];
                    foreach ($song['arrangement'] as $ai) {
                        if (isset($idxRemap[$ai])) { $remappedArr[] = $idxRemap[$ai]; }
                    }
                    $song['arrangement'] = $remappedArr;
                }
            }
        }

        /* #1178 — strip all-BLANK components when the song has real lyrics. A
           blank "Verse 1" (no non-empty line) left over from the accumulation
           bug is junk once any filled component exists; never persist it. If
           EVERY component is blank (a genuinely empty in-progress draft) they're
           kept as-is so the Structure tab isn't emptied. Arrangement indices are
           remapped through the same old→new map so they stay valid (mirrors the
           exact-dup collapse above). */
        if (is_array($song['components'] ?? null) && count($song['components']) > 0) {
            $hasContent = false;
            foreach ($song['components'] as $comp) {
                foreach (($comp['lines'] ?? []) as $ln) {
                    if (trim((string)$ln) !== '') { $hasContent = true; break 2; }
                }
            }
            if ($hasContent) {
                $keptComp = [];
                $idxRemap = [];
                foreach ($song['components'] as $oldIdx => $comp) {
                    $blank = true;
                    foreach (($comp['lines'] ?? []) as $ln) {
                        if (trim((string)$ln) !== '') { $blank = false; break; }
                    }
                    if ($blank) { continue; }   /* drop the empty component */
                    $idxRemap[$oldIdx] = count($keptComp);
                    $keptComp[]        = $comp;
                }
                if (count($keptComp) !== count($song['components'])) {
                    $song['components'] = $keptComp;
                    if (is_array($song['arrangement'] ?? null)) {
                        $remappedArr = [];
                        foreach ($song['arrangement'] as $ai) {
                            if (isset($idxRemap[$ai])) { $remappedArr[] = $idxRemap[$ai]; }
                        }
                        $song['arrangement'] = $remappedArr;
                    }
                }
            }
        }

        $componentCount = is_array($song['components'] ?? null) ? count($song['components']) : 0;
        $arrangementJson = _sanitiseArrangement($song['arrangement'] ?? null, $componentCount);

        try {
            $db = getDbMysqli();
            $db->begin_transaction();

            /* Capture previous state for the revision row (#400) */
            $previousData = null;
            $prevStmt = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
            $prevStmt->bind_param('s', $songId);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();
            if ($prevRow !== null) {
                $previousData = json_encode($prevRow, JSON_UNESCAPED_UNICODE);
            }
            $action = $prevRow === null ? 'create' : 'edit';

            /* #892 — schema-probe for the optional ArrangementJson column.
               Pre-migration deploys keep the legacy 16-column UPSERT; once
               the column exists we add a 17th bound parameter so the
               curator's chosen verse/refrain/verse… order finally
               round-trips through save → reload. */
            $hasArrangementCol = false;
            try {
                $arrProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongs'
                        AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
                );
                $arrProbe->execute();
                $hasArrangementCol = $arrProbe->get_result()->fetch_row() !== null;
                $arrProbe->close();
            } catch (\Throwable $_e) { /* default false */ }

            /* UPSERT tblSongs — now carries TuneName + Iswc (#497) and
               ArrangementJson (#892) when the column exists. */
            if ($hasArrangementCol) {
                $upsert = $db->prepare(
                    'INSERT INTO tblSongs
                        (SongId, Number, Title, SongbookAbbr, Language,
                         Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                         MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText,
                         ArrangementJson)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        Number = VALUES(Number), Title = VALUES(Title),
                        SongbookAbbr = VALUES(SongbookAbbr),
                        Language = VALUES(Language), Copyright = VALUES(Copyright),
                        TuneName = VALUES(TuneName),
                        Ccli = VALUES(Ccli), Iswc = VALUES(Iswc),
                        Verified = VALUES(Verified),
                        LyricsPublicDomain = VALUES(LyricsPublicDomain),
                        MusicPublicDomain = VALUES(MusicPublicDomain),
                        HasAudio = VALUES(HasAudio), HasSheetMusic = VALUES(HasSheetMusic),
                        LyricsText = VALUES(LyricsText),
                        ArrangementJson = VALUES(ArrangementJson)'
                );
                /* SongbookName denorm column dropped (WS-E #1013 ph2) —
                   16 cols / 16 placeholders / 16 bind types. */
                $upsert->bind_param(
                    'sisssssssiiiiiss',
                    $songId, $number, $title, $songbookAbbr,
                    $language, $copyright, $tuneName, $ccli, $iswc,
                    $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText,
                    $arrangementJson
                );
            } else {
                $upsert = $db->prepare(
                    'INSERT INTO tblSongs
                        (SongId, Number, Title, SongbookAbbr, Language,
                         Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                         MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        Number = VALUES(Number), Title = VALUES(Title),
                        SongbookAbbr = VALUES(SongbookAbbr),
                        Language = VALUES(Language), Copyright = VALUES(Copyright),
                        TuneName = VALUES(TuneName),
                        Ccli = VALUES(Ccli), Iswc = VALUES(Iswc),
                        Verified = VALUES(Verified),
                        LyricsPublicDomain = VALUES(LyricsPublicDomain),
                        MusicPublicDomain = VALUES(MusicPublicDomain),
                        HasAudio = VALUES(HasAudio), HasSheetMusic = VALUES(HasSheetMusic),
                        LyricsText = VALUES(LyricsText)'
                );
                /* SongbookName denorm column dropped (WS-E #1013 ph2) —
                   15 cols / 15 placeholders / 15 bind types. */
                $upsert->bind_param(
                    'sisssssssiiiiis',
                    $songId, $number, $title, $songbookAbbr,
                    $language, $copyright, $tuneName, $ccli, $iswc,
                    $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
                );
            }
            $upsert->execute();
            $upsert->close();

            /* Places adoption — write the composition-origin
               columns in a separate small UPDATE so the carefully-
               tuned 16/17-param UPSERT above stays untouched.
               Skipped silently on pre-adoption installs. */
            if (placeColumnExists($db, 'tblSongs', 'OriginCityId')) {
                $placeStmt = $db->prepare(
                    'UPDATE tblSongs
                        SET OriginCity = ?, OriginCityId = ?
                      WHERE SongId = ?'
                );
                $placeStmt->bind_param('sis', $originCity, $originCityId, $songId);
                $placeStmt->execute();
                $placeStmt->close();
            }

            /* #1235 PF1 / R1 — carry-forward (data-loss guard) + write-path selection.
               A STALE client (a Service-Worker-cached pre-#1094 / pre-P3 editor.js)
               POSTs components WITHOUT `chords` / `languages`, so a naive recreate
               would NULL them song-wide. BEFORE the write we snapshot the existing
               per-component arrays keyed by Type | Number | line-count as a FIFO queue
               (repeated identical parts — a refrain reprised after each verse — pair
               1:1); a component whose POST OMITS the key reclaims its carried value, a
               component that SENDS the key (even empty) stays authoritative.

               #1235 P4/C5 — on a MIRRORED install the carry SOURCE is the AUTHORITATIVE
               tblLyricLines (assembled editor shape), NOT the doomed LinesJson/
               ChordsJson/LanguagesJson columns, and the save uses the inverted write
               path below (lines authoritative; JSON shadow) — so it survives the C6
               drop. On an un-migrated install (no mirror) the carry is the pre-delete
               JSON-column snapshot, reattached as raw JSON in the legacy path. */
            $ll_syncReady = lyricLinesSyncReady($db);
            $carryChords  = [];   // "type\x1fnumber\x1flineCount" => FIFO list (arrays when mirrored; JSON strings legacy)
            $carryLangs   = [];
            $pf1HasChords = false;
            $pf1HasLangs  = false;
            /* Clean a component's per-line `chords` (array OR space-separated string per
               line) into a null-padded parallel array (or null). Editor-input
               normalisation kept at the funnel boundary; the shared write path stores it
               verbatim. */
            $saveSongCleanChords = static function ($chords, int $lineCount): ?array {
                if (!is_array($chords) || $lineCount <= 0) { return null; }
                $out = []; $any = false;
                for ($ci = 0; $ci < $lineCount; $ci++) {
                    $cell = $chords[$ci] ?? null;
                    if (is_array($cell)) {
                        $clean = array_values(array_filter(array_map(static fn($c) => trim((string)$c), $cell), static fn($c) => $c !== ''));
                    } elseif (is_string($cell) && trim($cell) !== '') {
                        $clean = array_values(array_filter(preg_split('/\s+/', trim($cell)) ?: [], static fn($c) => $c !== ''));
                    } else {
                        $clean = null;
                    }
                    if ($clean !== null && $clean !== []) { $out[] = $clean; $any = true; }
                    else { $out[] = null; }
                }
                return $any ? $out : null;
            };
            if ($ll_syncReady) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
                foreach (lyricLinesEditableComponents($db, $songId) as $pc) {
                    $key = (string)$pc['type'] . "\x1f" . (string)(int)$pc['number'] . "\x1f" . count($pc['lines']);
                    $carryChords[$key][] = $pc['chords'];      // prior parallel chords array, or null
                    $carryLangs[$key][]  = $pc['languages'];   // prior per-line override array, or null
                }
            } else {
                /* lines-json-fallback (#1235 P4): un-migrated install (no mirror).
                   Column names are hardcoded constants (rule #5). */
                try {
                    $pf1Probe = $db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongComponents'
                            AND COLUMN_NAME  = 'ChordsJson' LIMIT 1"
                    );
                    $pf1Probe->execute();
                    $pf1HasChords = $pf1Probe->get_result()->fetch_row() !== null;
                    $pf1Probe->close();
                } catch (\Throwable $_e) { /* default false */ }
                $pf1HasLangs = lyricLinesComponentsLangReady($db);
                if ($pf1HasChords || $pf1HasLangs) {
                    $snapCols = 'Type, Number, LinesJson';
                    if ($pf1HasChords) { $snapCols .= ', ChordsJson'; }
                    if ($pf1HasLangs)  { $snapCols .= ', LanguagesJson'; }
                    $snap = $db->prepare(
                        "SELECT {$snapCols} FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id"
                    );
                    $snap->bind_param('s', $songId);
                    $snap->execute();
                    $snapRes = $snap->get_result();
                    while ($snapRow = $snapRes->fetch_assoc()) {
                        $decoded = json_decode((string)$snapRow['LinesJson'], true);
                        $lc      = is_array($decoded) ? count($decoded) : 0;
                        $key     = (string)$snapRow['Type'] . "\x1f" . (string)(int)$snapRow['Number'] . "\x1f" . $lc;
                        if ($pf1HasChords) { $carryChords[$key][] = $snapRow['ChordsJson']; }
                        if ($pf1HasLangs)  { $carryLangs[$key][]  = $snapRow['LanguagesJson']; }
                    }
                    $snap->close();
                }
            }

            /* Child rows: DELETE then INSERT — simpler than diffing and
               the row counts per song are small (≈1–20 each). New credit
               tables from #497 are cleaned up here too. NOTE (#1235 P4/C5):
               tblSongComponents is NO LONGER in this blanket DELETE — the
               component+line write below is an Id-STABLE upsert
               (lyricLinesWriteComponents) so ComponentId no longer churns on
               every save. The legacy (un-migrated) branch DELETEs it itself. */
            foreach ([
                'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists',
            ] as $childTable) {
                /* tblSongArtists (#587) only exists once
                   migrate-song-artists.php has been applied. Skip the
                   DELETE on a partly-migrated install rather than 500ing
                   the save path; the schema-audit page (#518) flags the
                   missing table separately. */
                if ($childTable === 'tblSongArtists' && !_songArtistsTableExists($db)) {
                    continue;
                }
                $del = $db->prepare("DELETE FROM {$childTable} WHERE SongId = ?");
                $del->bind_param('s', $songId);
                $del->execute();
                $del->close();
            }

            /* Insert credit collections. Each collection is a separate
               prepared statement to keep the field name explicit at each
               call site. The artists insert (#587) is gated on the
               table existing — same partly-migrated tolerance as the
               DELETE above. */
            $creditInserts = [
                'writers'     => 'INSERT INTO tblSongWriters     (SongId, Name) VALUES (?, ?)',
                'composers'   => 'INSERT INTO tblSongComposers   (SongId, Name) VALUES (?, ?)',
                'arrangers'   => 'INSERT INTO tblSongArrangers   (SongId, Name) VALUES (?, ?)',
                'adaptors'    => 'INSERT INTO tblSongAdaptors    (SongId, Name) VALUES (?, ?)',
                'translators' => 'INSERT INTO tblSongTranslators (SongId, Name) VALUES (?, ?)',
            ];
            if (_songArtistsTableExists($db)) {
                /* SortOrder defaults to 0 — display order falls back to
                   Id (insertion order) which matches how the editor
                   sends the artists array. Same 2-param shape as the
                   other credit inserts so the existing loop below works
                   without a special case. */
                $creditInserts['artists'] = 'INSERT INTO tblSongArtists (SongId, Name) VALUES (?, ?)';
            }
            /* #960 — Each credit can arrive as either a legacy
               string ("John Newton") OR a structured-parts object
               ({name, first, surname, suffix}). The new chip-list
               editor sends the latter so the registry write below
               can populate FirstNames / Surname / Suffix on
               auto-promote (PR #935 columns). Normalise into a
               uniform array shape up front. */
            require_once dirname(dirname(__DIR__)) . '/includes/credit_people_helpers.php';
            $regParts = []; /* keyed by composed Name → ['first'=>…,'surname'=>…,'suffix'=>…] */
            $normaliseCreditEntry = static function ($v) {
                if (is_string($v)) {
                    $name = trim($v);
                    if ($name === '') return null;
                    [$first, $surname, $suffix] = decomposePersonName($name);
                    return ['name' => $name, 'first' => $first, 'surname' => $surname, 'suffix' => $suffix];
                }
                if (!is_array($v)) return null;
                $first   = trim((string)($v['first']   ?? ''));
                $surname = trim((string)($v['surname'] ?? ''));
                $suffix  = trim((string)($v['suffix']  ?? ''));
                /* Prefer a client-composed `name` for byte-equal
                   round-tripping; otherwise compose from parts. If
                   parts are empty and the only thing the client
                   sent is a `name` string, decompose it. */
                $name = trim((string)($v['name'] ?? ''));
                if ($name === '') {
                    $name = composePersonName($first, $surname, $suffix);
                } elseif ($first === '' && $surname === '' && $suffix === '') {
                    [$first, $surname, $suffix] = decomposePersonName($name);
                }
                if ($name === '') return null;
                return ['name' => $name, 'first' => $first, 'surname' => $surname, 'suffix' => $suffix];
            };

            foreach ($creditInserts as $key => $sql) {
                $stmt = $db->prepare($sql);
                /* Dedup (#1178): a song must never list the same person twice in
                   the same role — a client accumulation bug duplicated the whole
                   credit list many times over. Case-insensitive, first wins. */
                $seenCredit = [];
                foreach ($song[$key] ?? [] as $raw) {
                    $entry = $normaliseCreditEntry($raw);
                    if ($entry === null) continue;
                    $dedupKey = function_exists('mb_strtolower') ? mb_strtolower($entry['name']) : strtolower($entry['name']);
                    if (isset($seenCredit[$dedupKey])) continue;
                    $seenCredit[$dedupKey] = true;
                    $stmt->bind_param('ss', $songId, $entry['name']);
                    $stmt->execute();
                    /* Keep the richest parts seen for this name across
                       all five role lists — if "J. Newton" was typed
                       in Writers and "John Newton" in Composers, the
                       structured-parts version wins for the registry
                       upsert. (Both arrived as separate junction rows
                       above; this dedup only affects the registry.) */
                    if (!isset($regParts[$entry['name']])
                        || ($regParts[$entry['name']]['first'] === '' && $regParts[$entry['name']]['surname'] === '' && $regParts[$entry['name']]['suffix'] === '')
                    ) {
                        $regParts[$entry['name']] = [
                            'first'   => $entry['first'],
                            'surname' => $entry['surname'],
                            'suffix'  => $entry['suffix'],
                        ];
                    }
                }
                $stmt->close();
            }
            /* Silently keep the credit-people registry in sync
               (#545 / #960). When the FirstNames / Surname / Suffix
               columns exist (PR #935 migration applied), upsert the
               structured parts too — but only fill them in when the
               existing row's parts are NULL/empty, so a curated
               edit on the /manage/credit-people page never gets
               overwritten by an auto-promote from the editor.
               Pre-migration installs fall back to the legacy
               Name-only INSERT IGNORE path. */
            if (!empty($regParts)) {
                $partsCols = creditPeopleNamePartsColumnsExist($db);
                /* Route every auto-promote through the shared registry
                   helper. It computes a collision-safe Slug for the
                   new row and idempotently no-ops when Name already
                   exists — which means the orphan empty-Slug row
                   that the IGNORE+UPDATE hotfix had to dodge can't
                   block legit promotes anymore once
                   migrate-credit-people-slug-rebackfill.php has run. */
                foreach ($regParts as $regName => $p) {
                    registerCreditPersonByName($db, $regName, $partsCols ? $p : null);
                }

                if ($partsCols) {
                    /* Existing registry rows may already exist
                       without FirstNames/Surname/Suffix populated;
                       backfill those (only when currently empty)
                       so a song-save also enriches pre-existing
                       Name-only registry rows. The helper above
                       only sets parts for BRAND NEW inserts; this
                       handles the existing-row case. Never
                       overwrites a curated value. */
                    $stmtParts = $db->prepare(
                        'UPDATE tblCreditPeople
                            SET FirstNames = COALESCE(NULLIF(FirstNames, ""), ?),
                                Surname    = COALESCE(NULLIF(Surname,    ""), ?),
                                Suffix     = COALESCE(NULLIF(Suffix,     ""), ?)
                          WHERE Name = ?'
                    );
                    foreach ($regParts as $regName => $p) {
                        $first   = $p['first']   !== '' ? $p['first']   : null;
                        $surname = $p['surname'] !== '' ? $p['surname'] : null;
                        $suffix  = $p['suffix']  !== '' ? $p['suffix']  : null;
                        $stmtParts->bind_param('ssss', $first, $surname, $suffix, $regName);
                        $stmtParts->execute();
                    }
                    $stmtParts->close();
                }
            }

            if ($ll_syncReady) {
                /* #1235 P4/C5 — inverted write path. Build the components payload from
                   the POST (clean per-line chords; reattach the PF1-carried
                   chords/languages a stale client omitted), then hand it to the shared
                   writer: tblLyricLines becomes the source of truth and the JSON columns
                   are shadow-written from the SAME payload while they exist. Id-stable —
                   no component DELETE (so ComponentId no longer churns every save). */
                $writeComps = [];
                foreach ($song['components'] ?? [] as $comp) {
                    /* Normalise type/number EXACTLY as lyricLinesWriteComponents stores them
                       (trim + 20-char cap; non-negative int) so the PF1 carry key matches the
                       snapshot key (built above from the STORED, normalised values via
                       lyricLinesEditableComponents) — a raw-vs-normalised mismatch would
                       silently fail the chords/languages carry-forward (the C5 review finding). */
                    $cType  = function_exists('mb_substr')
                        ? (mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse')
                        : (substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse');
                    $cNum   = max(0, (int)($comp['number'] ?? 0));
                    $cLines = is_array($comp['lines'] ?? null) ? array_values($comp['lines']) : [];
                    $pf1Key = $cType . "\x1f" . (string)$cNum . "\x1f" . count($cLines);
                    /* chords: explicit (even empty) wins; omitted reclaims the carried prior array. */
                    if (array_key_exists('chords', $comp)) {
                        $cChords = $saveSongCleanChords($comp['chords'] ?? null, count($cLines));
                    } else {
                        $carried = !empty($carryChords[$pf1Key]) ? array_shift($carryChords[$pf1Key]) : null;
                        $cChords = is_array($carried) ? $carried : null;
                    }
                    /* languages (per-line override): same explicit-vs-carry rule. */
                    if (array_key_exists('languages', $comp)) {
                        $cLangs = is_array($comp['languages'] ?? null) ? $comp['languages'] : null;
                    } else {
                        $carriedL = !empty($carryLangs[$pf1Key]) ? array_shift($carryLangs[$pf1Key]) : null;
                        $cLangs = is_array($carriedL) ? $carriedL : null;
                    }
                    $writeComps[] = [
                        'type'      => $cType,
                        'number'    => $cNum,
                        'language'  => (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null,
                        'lines'     => $cLines,
                        'chords'    => $cChords,
                        'languages' => $cLangs,
                    ];
                }
                lyricLinesWriteComponents($db, $songId, $writeComps);
            } else {
            /* lines-json-fallback (#1235 P4): un-migrated install (no tblLyricLines
               mirror) — the legacy component write. tblSongComponents was removed from
               the shared child-table DELETE loop above, so DELETE it here first, then
               re-INSERT LinesJson [+ shadow ChordsJson / LanguagesJson]. LinesJson
               provably still exists (the C6 drop only runs once the mirror is present). */
            $delLegacyComp = $db->prepare("DELETE FROM tblSongComponents WHERE SongId = ?");
            $delLegacyComp->bind_param('s', $songId);
            $delLegacyComp->execute();
            $delLegacyComp->close();
            /* #858 — schema-probe for the optional Language column.
               When present, the INSERT carries a per-component
               override; pre-migration deploys keep the legacy
               5-column INSERT shape. */
            $hasComponentLanguage = false;
            try {
                $colProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongComponents'
                        AND COLUMN_NAME  = 'Language' LIMIT 1"
                );
                $colProbe->execute();
                $hasComponentLanguage = $colProbe->get_result()->fetch_row() !== null;
                $colProbe->close();
            } catch (\Throwable $_e) { /* default false */ }

            /* #1094 — schema-probe for the optional ChordsJson column (#1066).
               When present, manual per-line chords are persisted via a guarded
               UPDATE after each component INSERT (leaving the proven INSERT shape
               untouched); pre-migration deploys simply skip it. */
            $hasComponentChords = false;
            try {
                $chProbe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongComponents'
                        AND COLUMN_NAME  = 'ChordsJson' LIMIT 1"
                );
                $chProbe->execute();
                $hasComponentChords = $chProbe->get_result()->fetch_row() !== null;
                $chProbe->close();
            } catch (\Throwable $_e) { /* default false */ }
            $updChords = $hasComponentChords
                ? $db->prepare('UPDATE tblSongComponents SET ChordsJson = ? WHERE Id = ?')
                : null;
            /* #1235 P3 — per-line language overrides (LanguagesJson), written like
               chords: one UPDATE per component by its insert_id when present.
               No-op (null) on an un-migrated install. */
            $updLangs = lyricLinesComponentsLangReady($db)
                ? $db->prepare('UPDATE tblSongComponents SET LanguagesJson = ? WHERE Id = ?')
                : null;

            if ($hasComponentLanguage) {
                $insComp = $db->prepare(
                    'INSERT INTO tblSongComponents
                        (SongId, Type, Number, SortOrder, LinesJson, Language)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
            } else {
                $insComp = $db->prepare(
                    'INSERT INTO tblSongComponents
                        (SongId, Type, Number, SortOrder, LinesJson)
                     VALUES (?, ?, ?, ?, ?)'
                );
            }
            $order = 0;
            foreach ($song['components'] ?? [] as $comp) {
                $type   = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $lines  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
                if ($hasComponentLanguage) {
                    /* Trim + cap to 35 chars (the BCP 47 column width).
                       Empty / null = inherit from the song; only persist
                       a value that's plausibly a tag. */
                    $lang = trim((string)($comp['language'] ?? ''));
                    if ($lang === '' || !preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/', $lang)) {
                        $lang = null;
                    } else {
                        $lang = function_exists('mb_substr') ? mb_substr($lang, 0, 35) : substr($lang, 0, 35);
                    }
                    $insComp->bind_param('ssiiss', $songId, $type, $cNum, $order, $lines, $lang);
                } else {
                    $insComp->bind_param('ssiis', $songId, $type, $cNum, $order, $lines);
                }
                $insComp->execute();
                /* Capture the new component Id ONCE — the chords UPDATE below
                   would zero $db->insert_id, so the per-line-language write that
                   follows it must reuse this captured value. */
                $newCompId = (int)$db->insert_id;

                /* #1094 — persist manual per-line chords (parallel to LinesJson).
                   comp['chords'][i] may be a space-separated string or an array
                   of chord symbols; null-padded to the line count. Stored only
                   when at least one line has chords. */
                if ($updChords !== null && isset($comp['chords']) && is_array($comp['chords'])) {
                    $lineCount = is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0;
                    $chordsArr = [];
                    $anyChords = false;
                    for ($ci = 0; $ci < $lineCount; $ci++) {
                        $cell = $comp['chords'][$ci] ?? null;
                        if (is_array($cell)) {
                            $clean = array_values(array_filter(array_map(static fn($c) => trim((string)$c), $cell), static fn($c) => $c !== ''));
                        } elseif (is_string($cell) && trim($cell) !== '') {
                            $clean = array_values(array_filter(preg_split('/\s+/', trim($cell)) ?: [], static fn($c) => $c !== ''));
                        } else {
                            $clean = null;
                        }
                        if ($clean !== null && $clean !== []) { $chordsArr[] = $clean; $anyChords = true; }
                        else { $chordsArr[] = null; }
                    }
                    if ($anyChords) {
                        $chordsJson = json_encode($chordsArr, JSON_UNESCAPED_UNICODE);
                        $updChords->bind_param('si', $chordsJson, $newCompId);
                        $updChords->execute();
                    }
                } elseif ($updChords !== null && !isset($comp['chords'])) {
                    /* PF1 / R1 carry-forward — the client did NOT send `chords` for
                       this component, so don't let the reinsert leave ChordsJson NULL:
                       reclaim the pre-delete array for the position-matched part
                       (same Type | Number | line-count), FIFO so duplicate parts pair
                       1:1. An explicit (even empty) `chords` above stays authoritative. */
                    $pf1Key = $type . "\x1f" . (string)$cNum . "\x1f"
                            . (is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0);
                    if (!empty($carryChords[$pf1Key])) {
                        $pf1Carried = array_shift($carryChords[$pf1Key]);
                        if ($pf1Carried !== null) {
                            $updChords->bind_param('si', $pf1Carried, $newCompId);
                            $updChords->execute();
                        }
                    }
                }

                /* #1235 P3 — persist per-line language overrides (parallel to
                   LinesJson; null-padded BCP47 tags). Stored only when at least
                   one line carries a real override; a null/absent entry inherits
                   the component Language. Same shared builder as api2.php. */
                if ($updLangs !== null) {
                    if (isset($comp['languages'])) {
                        $lineCount = is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0;
                        $langsJson = lineEnrichmentBuildLanguagesJson($comp['languages'], $lineCount);
                        if ($langsJson !== null) {
                            $updLangs->bind_param('si', $langsJson, $newCompId);
                            $updLangs->execute();
                        }
                    } else {
                        /* PF1 / R1 carry-forward — client OMITTED `languages`; preserve
                           the existing per-line language array (FIFO by Type | Number |
                           line-count) instead of letting the reinsert NULL it. */
                        $pf1KeyL = $type . "\x1f" . (string)$cNum . "\x1f"
                                 . (is_array($comp['lines'] ?? null) ? count($comp['lines']) : 0);
                        if (!empty($carryLangs[$pf1KeyL])) {
                            $pf1CarriedL = array_shift($carryLangs[$pf1KeyL]);
                            if ($pf1CarriedL !== null) {
                                $updLangs->bind_param('si', $pf1CarriedL, $newCompId);
                                $updLangs->execute();
                            }
                        }
                    }
                }
                $order++;
            }
            $insComp->close();
            if ($updChords !== null) { $updChords->close(); }
            if ($updLangs  !== null) { $updLangs->close(); }
            } /* end lines-json-fallback (legacy un-migrated component write) */

            /* Revision audit log (#400) — authenticated editors only.
               Silent no-op if the user isn't authenticated via the /manage
               session or if the revisions table is missing. */
            $revisionId = null;
            try {
                require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
                $editor = getCurrentUser();
                $userId = $editor['id'] ?? null;
                $newData = json_encode($song, JSON_UNESCAPED_UNICODE);
                $rev = $db->prepare(
                    'INSERT INTO tblSongRevisions
                        (SongId, UserId, Action, PreviousData, NewData, Status)
                     VALUES (?, ?, ?, ?, ?, "approved")'
                );
                $userIdParam = $userId !== null ? (int)$userId : null;
                $rev->bind_param('sisss', $songId, $userIdParam, $action, $previousData, $newData);
                $rev->execute();
                $revisionId = (int)$db->insert_id;
                $rev->close();
            } catch (\Throwable $_e) { /* revisions are best-effort */ }

            /* Refresh tblSongbooks.SongCount for every songbook this
               save touched (#791). The home + /songbooks tiles gate
               render on `songCount > 0`, and SongCount is a cached
               column — without this recompute, a brand-new song
               saved to Misc / any not-yet-populated songbook lands
               in tblSongs but the songbook tile never appears
               because the cache stays at 0.

               Two paths recompute:
                 - The current row's SongbookAbbr (always).
                 - The PREVIOUS row's SongbookAbbr if it differs
                   (song moved between books — old book shrinks,
                   new book grows). previousData is the JSON dump
                   of the row before this save.

               Wrapped in try/catch so a SongCount recompute failure
               (e.g. transient deadlock) doesn't roll back the song
               save itself — the cache will self-heal on the next
               recompute pass. */
            try {
                $cnt = $db->prepare(
                    'UPDATE tblSongbooks
                        SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
                      WHERE Abbreviation = ?'
                );
                $cnt->bind_param('ss', $songbookAbbr, $songbookAbbr);
                $cnt->execute();
                $cnt->close();

                /* If the row moved songbooks, the OLD book also needs
                   its count refreshed so its tile shrinks (or hides
                   entirely if this was its last song). */
                if ($previousData !== null) {
                    $prev = json_decode($previousData, true);
                    $prevAbbr = is_array($prev) ? (string)($prev['SongbookAbbr'] ?? '') : '';
                    if ($prevAbbr !== '' && $prevAbbr !== $songbookAbbr) {
                        $cnt = $db->prepare(
                            'UPDATE tblSongbooks
                                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
                              WHERE Abbreviation = ?'
                        );
                        $cnt->bind_param('ss', $prevAbbr, $prevAbbr);
                        $cnt->execute();
                        $cnt->close();
                    }
                }
            } catch (\Throwable $_e) {
                error_log('[editor save_song] SongCount recompute failed: ' . $_e->getMessage());
            }

            /* #833 — persist song-level external links when the client
               opted into the new editor section by sending the
               `externalLinks` key. The legacy / pre-#833 clients
               (bulk-import path, older editor snapshots) omit the key
               entirely and the existing rows stay untouched. The shared
               saver expects four parallel arrays in the canonical
               ext_link_*[] shape, so we unpack the JSON list of
               {typeId, url, note, verified} into that shape inline.
               Runs before the song.edit / song.create logActivity row
               below so the inserted-link count can be folded into the
               audit payload (mirroring the songbook / work surfaces'
               external_link_count audit field). */
            $externalLinkCount = null;
            if (array_key_exists('externalLinks', $song)) {
                try {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                        . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
                    $extProbe = $db->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongExternalLinks' LIMIT 1"
                    );
                    $hasExtTable = $extProbe && $extProbe->fetch_row() !== null;
                    if ($extProbe) $extProbe->close();

                    if ($hasExtTable) {
                        $linkRows  = is_array($song['externalLinks']) ? $song['externalLinks'] : [];
                        $typeIds   = [];
                        $urls      = [];
                        $notes     = [];
                        $verified  = [];
                        foreach ($linkRows as $row) {
                            if (!is_array($row)) continue;
                            $typeIds[]  = (int)($row['typeId'] ?? 0);
                            $urls[]     = (string)($row['url'] ?? '');
                            $notes[]    = (string)($row['note'] ?? '');
                            $verified[] = !empty($row['verified']) ? '1' : '';
                        }
                        $externalLinkCount = saveExternalLinksForRow(
                            $db,
                            'tblSongExternalLinks',
                            'SongId',
                            $songId,
                            $typeIds,
                            $urls,
                            $notes,
                            $verified
                        );
                    }
                } catch (\Throwable $_e) {
                    /* Match the Works-auto-link pattern — link
                       reconciliation must not block the core save.
                       The user's metadata edit is already committed
                       below; a link write failure surfaces in
                       error_log + audit but doesn't 500 the request. */
                    error_log('[editor save_song] external-links reconcile failed: ' . $_e->getMessage());
                }
            }

            /* Activity log (#535) — high-level "song.create" / "song.edit"
               row with a cross-link to the revisions row above so a
               timeline reader can drill into the full before/after diff
               without bloating Details here. external_link_count is
               null when the client didn't opt into the externalLinks
               key (e.g. bulk-import path); otherwise it carries the
               number of rows inserted by the reconcile above. */
            if (function_exists('logActivity')) {
                $logDetails = [
                    'title'         => $title,
                    'songbook'      => $songbookAbbr,
                    'number'        => $number,
                    'verified'      => (bool)$verified,
                    'revision_id'   => $revisionId,
                ];
                if ($externalLinkCount !== null) {
                    $logDetails['external_link_count'] = $externalLinkCount;
                }
                logActivity(
                    $action === 'create' ? 'song.create' : 'song.edit',
                    'song',
                    $songId,
                    $logDetails
                );
            }

            /* ISWC auto-link to tblWorks (admin audit follow-up).
               When this song carries an ISWC the editor expects a
               matching Work row to exist so the public /song page
               can show the "Part of work X" panel and so the Works
               admin reflects every catalogued composition. The
               batch backfill-works-from-iswc migration handles
               existing rows; this block keeps the invariant on
               every NEW save. Schema-tolerant: silently skips when
               tblWorks isn't present yet (pre-migration installs). */
            if ($iswc !== null && $iswc !== '') {
                try {
                    /* Probe schema once per request. */
                    static $hasWorksSchema = null;
                    if ($hasWorksSchema === null) {
                        $probe = $db->prepare(
                            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME   IN ('tblWorks', 'tblWorkSongs')"
                        );
                        $probe->execute();
                        $hasWorksSchema = count($probe->get_result()->fetch_all()) === 2;
                        $probe->close();
                    }
                    if ($hasWorksSchema) {
                        /* Look for an existing Work keyed on this ISWC. */
                        $wStmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ? LIMIT 1');
                        $wStmt->bind_param('s', $iswc);
                        $wStmt->execute();
                        $wRow = $wStmt->get_result()->fetch_row();
                        $wStmt->close();

                        if ($wRow) {
                            $workId = (int)$wRow[0];
                        } else {
                            /* Create a new Work — Title mirrors this song's
                               title; Slug derived from Title with
                               collision suffix to satisfy uq_slug. */
                            $slugBase = mb_strtolower(trim((string)$title));
                            if (class_exists('Normalizer')) {
                                $slugBase = \Normalizer::normalize($slugBase, \Normalizer::FORM_KD);
                                $slugBase = preg_replace('/\p{M}+/u', '', $slugBase) ?? '';
                            }
                            $slugBase = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slugBase) ?? '';
                            $slugBase = trim((string)$slugBase, '-');
                            if ($slugBase === '') $slugBase = 'work';
                            if (mb_strlen($slugBase) > 76) $slugBase = mb_substr($slugBase, 0, 76);

                            $slug      = $slugBase;
                            $slugCheck = $db->prepare('SELECT 1 FROM tblWorks WHERE Slug = ? LIMIT 1');
                            $suffix    = 1;
                            while (true) {
                                $slugCheck->bind_param('s', $slug);
                                $slugCheck->execute();
                                $taken = $slugCheck->get_result()->fetch_row() !== null;
                                if (!$taken) break;
                                $suffix++;
                                $slug = $slugBase . '-' . $suffix;
                            }
                            $slugCheck->close();

                            $notes = sprintf('Auto-created from ISWC %s when song %s was saved.', $iswc, $songId);
                            $wIns  = $db->prepare(
                                'INSERT INTO tblWorks (Iswc, Title, Slug, Notes) VALUES (?, ?, ?, ?)'
                            );
                            $wIns->bind_param('ssss', $iswc, $title, $slug, $notes);
                            $wIns->execute();
                            $workId = (int)$db->insert_id;
                            $wIns->close();

                            if (function_exists('logActivity')) {
                                logActivity('work.auto_create', 'work', (string)$workId, [
                                    'iswc'      => $iswc,
                                    'title'     => $title,
                                    'slug'      => $slug,
                                    'source'    => 'song_editor.save_song',
                                    'song_id'   => $songId,
                                ]);
                            }
                        }

                        /* Idempotent membership. IsCanonical defaults to 0
                           — the first member of a brand-new Work is
                           promoted to canonical inline via a follow-up
                           UPDATE only when no canonical member exists. */
                        $linkStmt = $db->prepare(
                            'INSERT IGNORE INTO tblWorkSongs (WorkId, SongId, IsCanonical, SortOrder)
                             VALUES (?, ?, 0, 0)'
                        );
                        $linkStmt->bind_param('is', $workId, $songId);
                        $linkStmt->execute();
                        $linkStmt->close();

                        $canonStmt = $db->prepare(
                            'SELECT 1 FROM tblWorkSongs WHERE WorkId = ? AND IsCanonical = 1 LIMIT 1'
                        );
                        $canonStmt->bind_param('i', $workId);
                        $canonStmt->execute();
                        $hasCanon = $canonStmt->get_result()->fetch_row() !== null;
                        $canonStmt->close();
                        if (!$hasCanon) {
                            $upd = $db->prepare(
                                'UPDATE tblWorkSongs SET IsCanonical = 1 WHERE WorkId = ? AND SongId = ?'
                            );
                            $upd->bind_param('is', $workId, $songId);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                } catch (\Throwable $_e) {
                    /* Best-effort — Works linkage must never block the
                       core song save. The user's edit is already
                       captured in tblSongs + tblSongRevisions. */
                    error_log('[editor save_song] Works auto-link failed: ' . $_e->getMessage());
                }
            }

            /* #1235 P4/C5 — the tblLyricLines mirror was already written, in-place and
               Id-stably, by lyricLinesWriteComponents() above (mirrored install) — so
               there is NO separate projector call here anymore (the old
               lyricLinesProjectSong() RE-READ LinesJson, which is not drop-safe). The
               un-migrated (no-mirror) branch has no mirror to sync. */

            $db->commit();

            /* WS-J #1020: no songs.json cache to refresh — all reads are now
               live MySQL (editor sidebar via load_index, songbook export via
               songbook_export, the PWA via the slim songs_index). The corpus
               file cache + its regeneration hooks were removed. */

            echo json_encode(['ok' => true, 'songId' => $songId, 'action' => $action]);
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }
            http_response_code(500);
            error_log('[editor save_song] ' . $e->getMessage());

            /* Capture the failure in tblActivityLog as a Result='error'
               row so curators reviewing the audit log see a record of
               the failed save alongside successful ones (#759). The
               helper is best-effort — a logging failure must not
               compound the original error. */
            if (function_exists('logActivityError')) {
                $mysqliCode = ($e instanceof \mysqli_sql_exception) ? $e->getCode() : null;
                logActivityError(
                    'song.save_failed',
                    'song',
                    $songId,
                    $e,
                    [
                        'action'        => $action,
                        'songbook'      => $songbookAbbr,
                        'mysqli_code'   => $mysqliCode,
                    ]
                );
            }

            /* Surface the underlying error to admin / global_admin
               users so they can self-diagnose without server-shell
               access. Lower-privilege users still see the generic
               message — DB internals are not for general consumption.
               (#759) */
            $payload = ['error' => 'Failed to save song. Check server logs for details.'];
            $role    = $currentUser['role'] ?? null;
            if (in_array($role, ['admin', 'global_admin'], true)) {
                $payload['error_detail'] = $e->getMessage();
                $payload['error_class']  = get_class($e);
                if ($e instanceof \mysqli_sql_exception) {
                    $payload['mysqli_code'] = $e->getCode();
                }
            }
            echo json_encode($payload);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_TAGS (#496) — return the tags currently assigned to a song
     *
     * GET parameters:
     *   id — song id (e.g. CP-0001)
     *
     * Response: { tags: [{id, name, slug, description}, ...] }
     *
     * Used by the editor's Tags tab to render the per-song chip list
     * when a song is selected.
     * ----------------------------------------------------------------- */
    case 'song_tags':
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Song id is required.']);
            break;
        }
        try {
            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT t.Id AS id, t.Name AS name, t.Slug AS slug,
                        t.Description AS description
                 FROM tblSongTagMap m
                 JOIN tblSongTags t ON t.Id = m.TagId
                 WHERE m.SongId = ?
                 ORDER BY t.Name ASC'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tags = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $tags[] = $row;
            }
            $stmt->close();
            echo json_encode(['tags' => $tags]);
        } catch (\Throwable $e) {
            error_log('[editor song_tags] ' . $e->getMessage());
            echo json_encode(['tags' => []]);
        }
        break;

    /* -----------------------------------------------------------------
     * TAG_SEARCH (#496) — autocomplete for existing tag names
     *
     * GET parameters:
     *   q — partial name, case-insensitive substring match (optional;
     *       if empty, returns the most-used tags — useful for the
     *       "start typing" empty-state list)
     *   limit — max 20 suggestions (default 10)
     *
     * Response: { suggestions: [{id, name, slug, usage}, ...] }
     *   usage — number of songs currently carrying this tag, so popular
     *           tags sort first and admins don't accidentally coin a
     *           near-duplicate of an existing one.
     * ----------------------------------------------------------------- */
    case 'tag_search':
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
        try {
            $db = getDbMysqli();
            if ($q === '') {
                $sql = 'SELECT t.Id AS id, t.Name AS name, t.Slug AS slug,
                               COUNT(m.TagId) AS usage
                        FROM tblSongTags t
                        LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                        GROUP BY t.Id
                        ORDER BY usage DESC, t.Name ASC
                        LIMIT ?';
                $stmt = $db->prepare($sql);
                $stmt->bind_param('i', $limit);
            } else {
                $like = '%' . $q . '%';
                $sql = 'SELECT t.Id AS id, t.Name AS name, t.Slug AS slug,
                               COUNT(m.TagId) AS usage
                        FROM tblSongTags t
                        LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                        WHERE t.Name LIKE ?
                        GROUP BY t.Id
                        ORDER BY usage DESC, t.Name ASC
                        LIMIT ?';
                $stmt = $db->prepare($sql);
                $stmt->bind_param('si', $like, $limit);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $suggestions = [];
            while ($row = $result->fetch_assoc()) {
                $suggestions[] = [
                    'id'    => (int)$row['id'],
                    'name'  => $row['name'],
                    'slug'  => $row['slug'],
                    'usage' => (int)$row['usage'],
                ];
            }
            $stmt->close();
            echo json_encode(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            error_log('[editor tag_search] ' . $e->getMessage());
            echo json_encode(['suggestions' => []]);
        }
        break;

    /* -----------------------------------------------------------------
     * POST api.php?action=bulk_tag   (#399)
     * Add and/or remove a set of tag names across a list of songs.
     * Body: { songIds: [...], add: [tagNames], remove: [tagNames] }
     * Response: { songsAffected, added, removed }
     * ----------------------------------------------------------------- */
    case 'bulk_tag':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            break;
        }
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);
        if (!is_array($payload) || !isset($payload['songIds']) || !is_array($payload['songIds'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing songIds array.']);
            break;
        }
        $songIds = array_values(array_filter(array_map('strval', $payload['songIds']), function ($id) {
            return $id !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id);
        }));
        $addTags = is_array($payload['add'] ?? null) ? $payload['add'] : [];
        $remTags = is_array($payload['remove'] ?? null) ? $payload['remove'] : [];
        /* Tag normalisation (#762):
           - trim
           - collapse internal whitespace runs to single spaces
           - cap at 50 chars (matches tblSongTags.Name VARCHAR(50))
           - Title-Case so 'worship' / 'WORSHIP' / 'Worship' all resolve
             to the same canonical 'Worship' row, both for matching
             (case-insensitive in DB collation, but defensive on PHP
             side too) and for storage / display. */
        $normaliseTag = function ($name) {
            $trimmed = trim((string)$name);
            $trimmed = preg_replace('/\s+/u', ' ', $trimmed);
            if ($trimmed === null || $trimmed === '') return null;
            $titled = mb_convert_case($trimmed, MB_CASE_TITLE_SIMPLE, 'UTF-8');
            return mb_substr($titled, 0, 50);
        };
        $addTags = array_values(array_unique(array_filter(array_map($normaliseTag, $addTags))));
        $remTags = array_values(array_unique(array_filter(array_map($normaliseTag, $remTags))));

        if (empty($songIds) || (empty($addTags) && empty($remTags))) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid songIds or tag changes supplied.']);
            break;
        }

        $totalAdded = 0;
        $totalRemoved = 0;
        $missingSongs   = []; /* IDs the client sent that aren't in tblSongs (FK would fail) */
        $alreadyTagged  = []; /* (songId, tagName) pairs the request was a no-op for (duplicate PK) */
        try {
            $db = getDbMysqli();
            $db->begin_transaction();

            /* Pre-validate songIds against tblSongs so a missing row
               surfaces as a real diagnostic instead of being
               silently swallowed by INSERT IGNORE downstream
               (which would just bump affected_rows=0 and trigger the
               misleading "Save the song first" toast even when the
               song WAS saved but its persisted SongId differs from
               the editor's local copy). #960-follow-up */
            if (!empty($songIds)) {
                $place      = implode(',', array_fill(0, count($songIds), '?'));
                $checkStmt  = $db->prepare("SELECT SongId FROM tblSongs WHERE SongId IN ($place)");
                $checkStmt->bind_param(str_repeat('s', count($songIds)), ...$songIds);
                $checkStmt->execute();
                $found = [];
                $res   = $checkStmt->get_result();
                while ($r = $res->fetch_assoc()) { $found[$r['SongId']] = true; }
                $checkStmt->close();
                foreach ($songIds as $sid) {
                    if (!isset($found[$sid])) { $missingSongs[] = $sid; }
                }
            }

            /* Resolve / create tag rows for each ADD name. Keep a map of
               Name -> Id so we can insert mapping rows. The
               ON DUPLICATE KEY clause now also pulls the existing
               row's Name up to the new (Title-Cased) form via
               VALUES(Name) — so a curator who lands on an existing
               row whose stored Name is non-canonical (e.g. "worship"
               from before #762) re-canonicalises it on the next
               upsert without a separate backfill round-trip. */
            $addIds = [];
            foreach ($addTags as $name) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-');
                if ($slug === '') { continue; }
                $stmt = $db->prepare(
                    'INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?) ' .
                    'ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = VALUES(Name)'
                );
                $stmt->bind_param('ss', $name, $slug);
                $stmt->execute();
                $addIds[$name] = (int)$db->insert_id;
                $stmt->close();
            }

            /* Insert mapping rows (ignore duplicates). Skip songIds
               we already know are missing from tblSongs — INSERT
               IGNORE would just no-op on them and inflate the false
               "tag couldn't be applied" hit. TaggedBy is bound as a
               nullable INT (not 0) so a request from a session with
               no resolved user id doesn't trip fk_TagMap_User on
               servers where Id=0 doesn't exist. */
            if (!empty($addIds) && !empty($songIds)) {
                $userId    = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
                $stmt      = $db->prepare(
                    'INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)'
                );
                /* Reverse-lookup so a 0-affected-rows result can be
                   reported back to the client as "this song already
                   has this tag" instead of the generic save-first
                   toast. */
                $tagNameById = array_flip($addIds);
                foreach ($songIds as $sid) {
                    if (in_array($sid, $missingSongs, true)) continue;
                    foreach ($addIds as $tagId) {
                        $stmt->bind_param('sii', $sid, $tagId, $userId);
                        $stmt->execute();
                        if ($db->affected_rows > 0) {
                            $totalAdded++;
                        } else {
                            $alreadyTagged[] = [
                                'songId'  => $sid,
                                'tagName' => $tagNameById[$tagId] ?? (string)$tagId,
                            ];
                        }
                    }
                }
                $stmt->close();
            }

            /* Remove mapping rows. Resolve tag Ids by Name first (only
               delete if the tag exists — names that don't match anything
               are silently ignored). The Name comparison runs through
               the column's utf8mb4_unicode_ci collation so a curator
               removing 'worship' still hits the canonical 'Worship'
               row (the input here is already Title-Cased by
               $normaliseTag, but case-folded matching is the right
               default for any future caller). */
            if (!empty($remTags) && !empty($songIds)) {
                $remIds = [];
                $nameStmt = $db->prepare('SELECT Id FROM tblSongTags WHERE Name = ? LIMIT 1');
                foreach ($remTags as $name) {
                    $nameStmt->bind_param('s', $name);
                    $nameStmt->execute();
                    $row = $nameStmt->get_result()->fetch_assoc();
                    if ($row) { $remIds[] = (int)$row['Id']; }
                }
                $nameStmt->close();
                if (!empty($remIds)) {
                    $delStmt = $db->prepare(
                        'DELETE FROM tblSongTagMap WHERE SongId = ? AND TagId = ?'
                    );
                    foreach ($songIds as $sid) {
                        foreach ($remIds as $tagId) {
                            $delStmt->bind_param('si', $sid, $tagId);
                            $delStmt->execute();
                            $totalRemoved += $db->affected_rows > 0 ? 1 : 0;
                        }
                    }
                    $delStmt->close();
                }
            }

            $db->commit();
            echo json_encode([
                'songsAffected' => count($songIds),
                'added'         => $totalAdded,
                'removed'       => $totalRemoved,
                /* #960-follow-up — diagnostics so the client can
                   tell apart the three "0 added" failure modes
                   (song missing in DB / already tagged / nothing
                   asked for) instead of falling back to a generic
                   "Save the song first" toast. Empty arrays when
                   nothing went wrong. */
                'missingSongs'  => $missingSongs,
                'alreadyTagged' => $alreadyTagged,
            ]);
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }
            http_response_code(500);
            error_log('[editor bulk_tag] ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to apply bulk tag changes. Check server logs.']);
        }
        break;

    /* -----------------------------------------------------------------
     * GET api.php?action=list_revisions&songId=X   (#400)
     * Returns revision rows for a single song, newest first.
     * Response: { revisions: [{id, action, createdAt, userId, username, previousData, newData}, ...] }
     * ----------------------------------------------------------------- */
    case 'list_revisions':
        $songId = (string)($_GET['songId'] ?? '');
        if ($songId === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $songId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid songId.']);
            break;
        }
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) { $limit = 50; }
        try {
            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT r.Id, r.Action, r.CreatedAt, r.UserId, u.Username,
                        r.PreviousData, r.NewData
                   FROM tblSongRevisions r
                   LEFT JOIN tblUsers u ON u.Id = r.UserId
                  WHERE r.SongId = ?
                  ORDER BY r.CreatedAt DESC, r.Id DESC
                  LIMIT ?'
            );
            $stmt->bind_param('si', $songId, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $rows[] = [
                    'id'           => (int)$r['Id'],
                    'action'       => $r['Action'],
                    'createdAt'    => $r['CreatedAt'],
                    'userId'       => $r['UserId'] !== null ? (int)$r['UserId'] : null,
                    'username'     => $r['Username'],
                    'previousData' => $r['PreviousData'] !== null ? json_decode($r['PreviousData'], true) : null,
                    'newData'      => $r['NewData']      !== null ? json_decode($r['NewData'],      true) : null,
                ];
            }
            $stmt->close();
            echo json_encode(['revisions' => $rows]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[editor list_revisions] ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to load revisions. Check server logs.']);
        }
        break;

    /* -----------------------------------------------------------------
     * POST api.php?action=restore_revision   (#400)
     * Restore a song to the PreviousData snapshot of the given revision.
     * Body: { revisionId: N }
     * Writes a NEW revision row with Action='restore' capturing the
     * before/after pair so the audit log stays linear. The tblSongs
     * row and its dependent rows (tblSongComponents, tblSongTagMap is
     * untouched — tags are not serialised in the revision JSON) are
     * replaced via the same code path save_song uses.
     * ----------------------------------------------------------------- */
    case 'restore_revision':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            break;
        }
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);
        $revisionId = (int)($payload['revisionId'] ?? 0);
        if ($revisionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing revisionId.']);
            break;
        }
        try {
            $db = getDbMysqli();
            $sel = $db->prepare('SELECT SongId, PreviousData, NewData FROM tblSongRevisions WHERE Id = ? LIMIT 1');
            $sel->bind_param('i', $revisionId);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Revision not found.']);
                break;
            }
            $songId = (string)$row['SongId'];
            /* We restore to PreviousData — the state the song was in
               BEFORE this revision was created. That's what a user
               means when they click "Restore this version" on a row
               that represents a change they want to undo. */
            $restorePayload = $row['PreviousData'] !== null
                ? json_decode($row['PreviousData'], true)
                : null;
            if (!is_array($restorePayload)) {
                http_response_code(409);
                echo json_encode(['error' => 'This revision has no prior state to restore (likely the initial create).']);
                break;
            }

            /* Capture the current state so the new revision row's
               PreviousData matches reality (not the stale PreviousData
               from the chosen row). */
            $cur = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
            $cur->bind_param('s', $songId);
            $cur->execute();
            $currentRow = $cur->get_result()->fetch_assoc();
            $cur->close();

            $db->begin_transaction();

            /* Minimal rewrite: update tblSongs fields directly from the
               restore payload. The payload was serialised from tblSongs
               by save_song, so column names match 1:1 for the core row.
               Components table is replaced from the lyrics text when
               the restore payload carries one; otherwise we leave
               components alone (safer than wiping them on a partial). */
            if (isset($restorePayload['Title'])) {
                $title = (string)$restorePayload['Title'];
                /* Same NULL/''/'0'/0 normalisation as save_song (#797).
                   A revision row whose stored Number is 0 (legacy data
                   from before the convention was enforced) restores to
                   NULL — round-tripping the 0 would defeat the fix. */
                $rawRestoreNumber = $restorePayload['Number'] ?? null;
                $number = ($rawRestoreNumber === null || $rawRestoreNumber === '' || (int)$rawRestoreNumber <= 0)
                    ? null
                    : (int)$rawRestoreNumber;
                $verified = (int)($restorePayload['Verified']           ?? 0);
                $lyricsPD = (int)($restorePayload['LyricsPublicDomain'] ?? 0);
                $musicPD  = (int)($restorePayload['MusicPublicDomain']  ?? 0);
                $hasAudio = (int)($restorePayload['HasAudio']           ?? 0);
                $hasSheet = (int)($restorePayload['HasSheetMusic']      ?? 0);
                $lyrics   = (string)($restorePayload['LyricsText']      ?? '');
                $copyr    = (string)($restorePayload['Copyright']       ?? '');
                $ccli     = (string)($restorePayload['CCLI']            ?? '');
                $sbAbbr   = (string)($restorePayload['SongbookAbbr']    ?? '');
                $upd = $db->prepare(
                    'UPDATE tblSongs SET Title=?, Number=?, Verified=?,
                        LyricsPublicDomain=?, MusicPublicDomain=?, HasAudio=?,
                        HasSheetMusic=?, LyricsText=?, Copyright=?, CCLI=?,
                        SongbookAbbr=?
                     WHERE SongId=?'
                );
                $upd->bind_param(
                    'siiiiiisssss',
                    $title, $number, $verified, $lyricsPD, $musicPD, $hasAudio,
                    $hasSheet, $lyrics, $copyr, $ccli, $sbAbbr, $songId
                );
                $upd->execute();
                $upd->close();
            }

            /* Log the restore as its own revision row so the audit
               trail stays linear. */
            $editor = getCurrentUser();
            $userId = $editor['id'] ?? null;
            $userIdParam = $userId !== null ? (int)$userId : null;
            $prevJson = $currentRow ? json_encode($currentRow, JSON_UNESCAPED_UNICODE) : null;
            $newJson = json_encode($restorePayload, JSON_UNESCAPED_UNICODE);
            $action = 'restore';
            $rev = $db->prepare(
                'INSERT INTO tblSongRevisions
                    (SongId, UserId, Action, PreviousData, NewData, Status, ReviewNote)
                 VALUES (?, ?, ?, ?, ?, "approved", ?)'
            );
            $note = 'Restored from revision #' . $revisionId;
            $rev->bind_param('sissss', $songId, $userIdParam, $action, $prevJson, $newJson, $note);
            $rev->execute();
            $newRevId = (int)$db->insert_id;
            $rev->close();

            $db->commit();
            echo json_encode([
                'ok'            => true,
                'songId'        => $songId,
                'newRevisionId' => $newRevId,
            ]);
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }
            http_response_code(500);
            error_log('[editor restore_revision] ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to restore revision. Check server logs.']);
        }
        break;

    /* -----------------------------------------------------------------
     * CREDIT_SEARCH (#495) — live-search distinct credit names
     *
     * GET parameters:
     *   q    — partial name, case-insensitive substring match
     *   kind — writer | composer | arranger | adaptor | translator | any
     *          (default: any; "any" unions all five tables so the same
     *          canonical spelling is surfaced regardless of which role
     *          the user is typing into)
     *   limit — max 50 suggestions (default 20)
     *
     * Returns: { suggestions: [{name, usage, kinds:["writer",...]}, ...] }
     *   * usage — total song-count across the chosen kind(s), so popular
     *             spellings sort first.
     *   * kinds — which tables the name appears in; useful for the UI
     *             to signal "this name is already used as an arranger".
     * ----------------------------------------------------------------- */
    case 'credit_search':
        $q     = trim((string)($_GET['q'] ?? ''));
        $kind  = strtolower(trim((string)($_GET['kind'] ?? 'any')));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

        if ($q === '' || strlen($q) < 1) {
            echo json_encode(['suggestions' => []]);
            break;
        }

        $kindToTable = [
            'writer'     => 'tblSongWriters',
            'composer'   => 'tblSongComposers',
            'arranger'   => 'tblSongArrangers',
            'adaptor'    => 'tblSongAdaptors',
            'translator' => 'tblSongTranslators',
        ];

        $tablesToSearch = $kind === 'any'
            ? $kindToTable
            : (isset($kindToTable[$kind]) ? [$kind => $kindToTable[$kind]] : []);

        if (empty($tablesToSearch)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown kind. Use writer|composer|arranger|adaptor|translator|any.']);
            break;
        }

        try {
            $db   = getDbMysqli();
            $like = '%' . $q . '%';

            /* Build a UNION ALL across the selected tables, grouping by
               name so the same "Fanny Crosby" from three different
               tables collapses to a single suggestion with a combined
               song count and the list of kinds it appears in. */
            $unionParts = [];
            $params     = [];
            $types      = '';
            foreach ($tablesToSearch as $kindLabel => $table) {
                $unionParts[] = "SELECT Name, '{$kindLabel}' AS kindLabel, COUNT(*) AS cnt
                                 FROM {$table}
                                 WHERE Name LIKE ?
                                 GROUP BY Name";
                $params[] = $like;
                $types   .= 's';
            }
            /* When the caller searches "any" role (the default for the
               editor's chip autocomplete), also surface registry rows
               from tblCreditPeople — pre-registered names that no song
               currently cites still need to be selectable. The
               synthesized 'registry' kindLabel collapses via the outer
               GROUP BY, so a registry-only name lands as a single
               suggestion with usage=0. The role-specific searches
               (kind=writer / composer / etc.) intentionally exclude
               the registry — those callers want to know how this
               person is currently credited, not whether their name
               exists in the catalogue. (#545) */
            if ($kind === 'any') {
                $unionParts[] = "SELECT Name, 'registry' AS kindLabel, 0 AS cnt
                                 FROM tblCreditPeople
                                 WHERE Name LIKE ?";
                $params[] = $like;
                $types   .= 's';

                /* AKA / aliases — when the typed query matches an alias,
                   surface the CANONICAL parent name (not the alias) so
                   clicking the suggestion still chips in the canonical
                   form. The 'alias' kindLabel signals to the client UI
                   that this row was matched via an alternative name —
                   the chip can render a small "via AKA: <alias>" hint
                   if it wants. Schema-tolerant: silently skipped on
                   installs where tblCreditPersonAliases isn't present. */
                require_once dirname(dirname(__DIR__)) . '/includes/credit_people_helpers.php';
                if (creditPeopleAliasesTableExists($db)) {
                    $unionParts[] = "SELECT cp.Name, 'alias' AS kindLabel, 0 AS cnt
                                     FROM tblCreditPersonAliases a
                                     JOIN tblCreditPeople cp ON cp.Id = a.CreditPersonId
                                     WHERE a.Name LIKE ?";
                    $params[] = $like;
                    $types   .= 's';
                }
            }
            /* `usage` is a MySQL reserved word, so backtick it explicitly
               to avoid any edge-case parser drift between server versions
               or strict-mode configurations. (#593)

               #960 — when the FirstNames/Surname/Suffix columns from
               PR #935 exist, LEFT JOIN tblCreditPeople so a
               registry-matched name surfaces its structured parts in
               the response. The chip-list editor uses these to
               populate all three inputs on suggestion click,
               preferring curated parts over a client-side decompose
               of the composed Name. Pre-migration installs return
               NULLs and the client falls back to decomposing. */
            require_once dirname(dirname(__DIR__)) . '/includes/credit_people_helpers.php';
            $partsCols = creditPeopleNamePartsColumnsExist($db);
            $partsSelect  = $partsCols
                ? ', cp.FirstNames AS first_names, cp.Surname AS surname, cp.Suffix AS suffix'
                : '';
            $partsJoin    = $partsCols
                ? 'LEFT JOIN tblCreditPeople cp ON cp.Name = u.Name'
                : '';
            /* ONLY_FULL_GROUP_BY requires every non-aggregated select
               column to appear in GROUP BY. Group on the raw column
               refs (not the aliased forms). */
            $partsGroupBy = $partsCols
                ? ', cp.FirstNames, cp.Surname, cp.Suffix'
                : '';
            $sql = "SELECT u.Name, GROUP_CONCAT(DISTINCT u.kindLabel) AS kinds, SUM(u.cnt) AS `usage`{$partsSelect}
                    FROM (" . implode(' UNION ALL ', $unionParts) . ") u
                    {$partsJoin}
                    GROUP BY u.Name{$partsGroupBy}
                    ORDER BY `usage` DESC, u.Name ASC
                    LIMIT ?";
            $types   .= 'i';
            $params[] = $limit;

            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $suggestions = [];
            while ($row = $res->fetch_assoc()) {
                $entry = [
                    'name'  => $row['Name'],
                    'usage' => (int)$row['usage'],
                    'kinds' => $row['kinds'] !== null ? explode(',', $row['kinds']) : [],
                ];
                if ($partsCols) {
                    /* Only emit parts keys when the registry row actually
                       had any — keeps the payload light and lets the
                       client choose between server parts and a
                       client-side decompose. */
                    if (($row['first_names'] ?? '') !== '' || ($row['surname'] ?? '') !== '' || ($row['suffix'] ?? '') !== '') {
                        $entry['first']   = (string)($row['first_names'] ?? '');
                        $entry['surname'] = (string)($row['surname']     ?? '');
                        $entry['suffix']  = (string)($row['suffix']      ?? '');
                    }
                }
                $suggestions[] = $entry;
            }
            $stmt->close();
            echo json_encode(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[editor credit_search] ' . $e->getMessage());
            echo json_encode(['error' => 'Credit search failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * USER_SEARCH (#498) — live-search users by display name / username
     *
     * Used by the /manage/restrictions name-first picker to resolve a
     * human-friendly user label ("Lance Manasse · @admin") to the
     * canonical tblUsers.Id on save. Admin-gated like every endpoint
     * in this file.
     *
     * GET parameters:
     *   q     — partial match against DisplayName OR Username (LIKE %q%)
     *   limit — max 20 suggestions (default 10)
     *
     * Response: { suggestions: [{id, label, hint}, ...] }
     * ----------------------------------------------------------------- */
    case 'user_search':
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
        if ($q === '') {
            echo json_encode(['suggestions' => []]);
            break;
        }
        try {
            $db = getDbMysqli();
            $like = '%' . $q . '%';
            $stmt = $db->prepare(
                'SELECT Id, DisplayName, Username, Role
                 FROM tblUsers
                 WHERE DisplayName LIKE ? OR Username LIKE ?
                 ORDER BY DisplayName ASC
                 LIMIT ?'
            );
            $stmt->bind_param('ssi', $like, $like, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            $suggestions = [];
            while ($row = $res->fetch_assoc()) {
                $suggestions[] = [
                    'id'    => (int)$row['Id'],
                    'label' => $row['DisplayName'] ?: $row['Username'],
                    'hint'  => '@' . $row['Username'] . ' · ' . $row['Role'],
                ];
            }
            $stmt->close();
            echo json_encode(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            error_log('[editor user_search] ' . $e->getMessage());
            echo json_encode(['suggestions' => []]);
        }
        break;

    /* -----------------------------------------------------------------
     * ORG_SEARCH (#498) — live-search organisations by name
     * ----------------------------------------------------------------- */
    case 'org_search':
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        try {
            $db = getDbMysqli();
            if ($q === '') {
                $stmt = $db->prepare(
                    'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                     WHERE IsActive = 1
                     ORDER BY Name ASC
                     LIMIT ?'
                );
                $stmt->bind_param('i', $limit);
            } else {
                $like = '%' . $q . '%';
                $stmt = $db->prepare(
                    'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                     WHERE IsActive = 1 AND (Name LIKE ? OR Slug LIKE ?)
                     ORDER BY Name ASC
                     LIMIT ?'
                );
                $stmt->bind_param('ssi', $like, $like, $limit);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $suggestions = [];
            while ($row = $res->fetch_assoc()) {
                $suggestions[] = [
                    'id'    => (int)$row['Id'],
                    'label' => $row['Name'],
                    'hint'  => 'licence: ' . ($row['LicenceType'] ?: 'none') . ' · slug: ' . $row['Slug'],
                ];
            }
            $stmt->close();
            echo json_encode(['suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            error_log('[editor org_search] ' . $e->getMessage());
            echo json_encode(['suggestions' => []]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_ZIP — bulk-load songs and songbooks from a ZIP that
     * mirrors the .SourceSongData/ folder layout (#664).
     *
     * Multipart upload, single field `zip`. The archive is expected to
     * contain one folder per songbook named "<Hymnal Name> [<ABBREV>]/"
     * holding one .txt file per song named
     * "<#> (<ABBREV>) - <Title>.txt" in the established source format
     * (title line, blank, alternating section markers + lyric blocks).
     *
     * Each parsed song is UPSERTed via _bulkImport_saveSong() — the
     * same write path used by the save_song action (tblSongs + child
     * tables + revision audit + activity log) so an imported row is
     * indistinguishable from a hand-edited one.
     *
     * Returns a JSON summary; never aborts the batch on a single bad
     * file (errors are collected and reported per-entry).
     * ----------------------------------------------------------------- */
    case 'bulk_import_zip':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        /* #1051 — opt-in title dedupe (default off = INSERT-only, backward
           compatible for direct API callers that don't send the field). */
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo json_encode(['error' => 'Server is missing the PHP zip extension.']);
            break;
        }
        if (!isset($_FILES['zip']) || ($_FILES['zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['zip']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "zip" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        /* Hard size cap as a second line of defence in case php.ini is
           generous. 100 MB covers a full multi-hymnal CIS bundle (~1.3
           MB compressed) with three orders of magnitude of headroom. */
        $sizeBytes = (int)($_FILES['zip']['size'] ?? 0);
        if ($sizeBytes > 100 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded zip exceeds the 100 MB import limit.']);
            break;
        }

        /* Async path (#676). Move the upload out of php's tmp dir so
           it survives the request close, create a job row, return
           {job_id} to the browser immediately, then call
           fastcgi_finish_request() to release the HTTP connection
           and continue processing in the freed worker. The browser
           polls bulk_import_status?job_id=N for live progress.

           If fastcgi_finish_request() isn't available (CLI, non-FPM
           SAPI), fall back to the synchronous path — the polling
           UI just sees status='running' flip straight to 'completed'
           in one tick. */
        $tmpPath = (string)$_FILES['zip']['tmp_name'];
        $origName = (string)($_FILES['zip']['name'] ?? 'upload.zip');
        $userId   = isset($currentUser['id']) ? (int)$currentUser['id'] : null;

        /* EasyWorship (#1058): an EW export zip carries a Songs.db (+ maybe
           SongWords.db) rather than the .SourceSongData / OpenSong / OpenLyrics
           song-file layout. Detect it and hand the whole archive to the
           EasyWorship SQLite reader instead of the per-entry loop, so an EW
           two-file export "just works" through this same Import button. */
        try {
            $ewProbe = new \ZipArchive();
            if ($ewProbe->open($tmpPath) === true) {
                $hasSongsDb = false;
                for ($zi = 0; $zi < $ewProbe->numFiles; $zi++) {
                    if (strtolower(basename((string)$ewProbe->getNameIndex($zi))) === 'songs.db') {
                        $hasSongsDb = true;
                        break;
                    }
                }
                $ewProbe->close();
                if ($hasSongsDb) {
                    $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
                    if (!($summary['ok'] ?? false)) {
                        http_response_code(400);
                    } elseif (($summary['songs_created'] ?? 0) > 0) {
                        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                            . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                        $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_zip_easyworship');
                        if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                            $summary['maintenance'] = $_maint;
                        }
                    }
                    echo json_encode($summary, JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
        } catch (\Throwable $ewE) {
            error_log('[bulk_import_zip] EasyWorship probe failed: ' . $ewE->getMessage());
        }

        /* Pre-flight: tblBulkImportJobs must exist. If migrate-bulk-
           import-jobs.php hasn't run yet we fall back to the
           synchronous behaviour and the response shape stays the
           old-style summary so the existing client keeps working. */
        $jobsTableReady = false;
        try {
            $db = getDbMysqli();
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblBulkImportJobs' LIMIT 1"
            );
            $probe->execute();
            $jobsTableReady = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $e) {
            error_log('[bulk_import_zip] tblBulkImportJobs probe failed: ' . $e->getMessage());
        }

        if (!$jobsTableReady) {
            /* Synchronous fallback — keeps the old contract for any
               deployment that hasn't run card 3p. Mirrors the
               pre-#676 flow exactly. */
            try {
                $summary = _bulkImport_processZip($tmpPath);
                if ((int)($summary['songs_created'] ?? 0) > 0) {
                    /* Auto-maintenance: cache regen + stale-prefix
                       probe-and-fixup. The probe is cheap on a clean
                       catalogue; if it finds rows that need re-prefixing
                       (e.g. import after a pre-#997 rename), it fixes
                       them inline up to the cap, otherwise defers to
                       the explicit migration. */
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                        . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                    $_maint = songbookMaintenanceRun($db, 'bulk_import_zip.sync');
                    if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                        $summary['maintenance'] = $_maint;
                    }
                }
                echo json_encode($summary, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                http_response_code(500);
                error_log('[bulk_import_zip] ' . $e->getMessage());
                echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
            }
            break;
        }

        /* Move the upload to a known durable spot so the temp file
           survives the request close. PHP's default upload_tmp_dir
           is supposed to survive, but `move_uploaded_file` to a
           known path inside our app dir is safer + makes cleanup
           predictable. The dir lives outside public_html so a curious
           HTTP request can't enumerate / fetch zips uploaded by
           other users. */
        $persistDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.bulk_import_uploads';
        if (!is_dir($persistDir)) {
            @mkdir($persistDir, 0700, true);
        }
        $persistPath = $persistDir . DIRECTORY_SEPARATOR
                     . 'job-' . bin2hex(random_bytes(8))
                     . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
        if (!@move_uploaded_file($tmpPath, $persistPath)) {
            /* move_uploaded_file fails if the upload was already
               moved or if persistDir is unwritable. Fall back to
               the sync path so the import still succeeds. */
            error_log('[bulk_import_zip] move_uploaded_file failed; falling back to sync path');
            try {
                $summary = _bulkImport_processZip($tmpPath);
                if ((int)($summary['songs_created'] ?? 0) > 0) {
                    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                        . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                    $_maint = songbookMaintenanceRun($db, 'bulk_import_zip.sync_fallback');
                    if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                        $summary['maintenance'] = $_maint;
                    }
                }
                echo json_encode($summary, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                http_response_code(500);
                error_log('[bulk_import_zip] sync fallback failed: ' . $e->getMessage());
                echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
            }
            break;
        }
        @chmod($persistPath, 0600);

        /* Create the job row. */
        $jobId = null;
        try {
            $stmt = $db->prepare(
                'INSERT INTO tblBulkImportJobs
                    (UserId, Filename, TempPath, SizeBytes, Status)
                 VALUES (?, ?, ?, ?, "queued")'
            );
            $stmt->bind_param('issi', $userId, $origName, $persistPath, $sizeBytes);
            $stmt->execute();
            $jobId = (int)$db->insert_id;
            $stmt->close();
        } catch (\Throwable $e) {
            @unlink($persistPath);
            http_response_code(500);
            error_log('[bulk_import_zip] could not create job row: ' . $e->getMessage());
            echo json_encode(['error' => 'Could not start import job.']);
            break;
        }

        /* Hand the browser its tracking handle. The frontend's
           progress widget reads `job_id` and starts polling. */
        echo json_encode([
            'ok'         => true,
            'async'      => true,
            'job_id'     => $jobId,
            'status'     => 'queued',
            'poll_url'   => '/manage/editor/api?action=bulk_import_status&job_id=' . $jobId,
        ], JSON_UNESCAPED_UNICODE);

        /* Release the HTTP connection so the browser can fire its
           first poll. session_write_close so a parallel poll request
           can read the session lock; ignore_user_abort so the worker
           keeps running even if the curator closes the tab. */
        @session_write_close();
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            /* Plain CGI / mod_php — best we can do is flush the
               output and hope the worker continues. The job table
               update path still runs; the frontend just sees the
               status flip from queued → completed in one tick. */
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        /* Worker section — runs after the HTTP connection has been
           released to the client. Bumps Status to 'running', calls
           the existing _bulkImport_processZip on the persisted file,
           writes the summary back to the job row, deletes the temp
           file, fires a notification. Wrapped in try/catch so a
           crash leaves the row in 'failed' (with the error message)
           rather than stuck in 'running' forever. */
        try {
            /* Re-grab the DB connection — fastcgi_finish_request can
               occasionally invalidate the prior handle on some FPM
               builds. Cheap to redo. */
            $db = getDbMysqli();
            _bulkImport_jobMark($db, $jobId, 'running', ['StartedAt' => 'NOW()']);
            $summary = _bulkImport_processZip($persistPath, $db, $jobId);
            /* #906 — persist the per-songbook breakdown alongside the
               aggregate counts when the schema carries the column.
               _bulkImport_jobMark()'s schema-probe + extras-merge
               handles a pre-migration deploy gracefully (the unknown
               column is dropped from the UPDATE). */
            $completedExtras = [
                'CompletedAt'           => 'NOW()',
                'SongbooksCreatedJson'  => json_encode($summary['songbooks_created']  ?? [], JSON_UNESCAPED_UNICODE),
                'SongbooksExistingJson' => json_encode($summary['songbooks_existing'] ?? [], JSON_UNESCAPED_UNICODE),
                'SongsCreated'          => (int)($summary['songs_created'] ?? 0),
                'SongsSkippedExisting'  => (int)($summary['songs_skipped_existing'] ?? 0),
                'SongsFailed'           => (int)($summary['songs_failed'] ?? 0),
                'ErrorsJson'            => json_encode($summary['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'SkippedSongIdsJson'    => json_encode($summary['skipped_song_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                'PerSongbookJson'       => json_encode($summary['per_songbook'] ?? [], JSON_UNESCAPED_UNICODE),
                'PhaseLabel'            => 'completed',
                'TempPath'              => '',
            ];
            _bulkImport_jobMark($db, $jobId, 'completed', $completedExtras);
            @unlink($persistPath);

            /* Regenerate the on-disk songs cache (#932) when the worker
               actually wrote new songs. Skipped if the import was
               all-existing or all-failed — nothing to refresh. */
            if ((int)($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun($db, 'bulk_import_zip.async');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }

            /* Notify the curator so they find the result on their
               next page-load even if they walked away. Best-effort —
               a tblNotifications failure must not poison the import
               result. */
            if ($userId !== null) {
                try {
                    $created  = (int)($summary['songs_created'] ?? 0);
                    $skipped  = (int)($summary['songs_skipped_existing'] ?? 0);
                    $failed   = (int)($summary['songs_failed'] ?? 0);
                    $title    = "Import finished: {$created} new, {$skipped} skipped"
                              . ($failed > 0 ? ", {$failed} failed" : '');
                    $body     = "Bulk import of \"{$origName}\" completed.";
                    $url      = '/manage/editor/';
                    $type     = 'bulk_import_complete';
                    $stmt = $db->prepare(
                        'INSERT INTO tblNotifications (UserId, Type, Title, Body, ActionUrl)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('issss', $userId, $type, $title, $body, $url);
                    $stmt->execute();
                    $stmt->close();
                } catch (\Throwable $_e) {
                    error_log('[bulk_import_zip] notification insert skipped: ' . $_e->getMessage());
                }
            }

            /* #908 — top-level summary row in tblActivityLog so the
               activity-log viewer surfaces "Bulk import N: 3,321 new,
               1,137 skipped, 4 failed" entries by default. Per-failure
               rows (`import.bulk_entry_failed`) are written inside
               _bulkImport_processZip; this is the "header" admins drill
               from. Best-effort — a logActivity failure must not
               poison the import result. */
            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'import.bulk_complete',
                        'bulk_import_job',
                        (string)$jobId,
                        [
                            'filename'             => $origName,
                            'songs_created'        => (int)($summary['songs_created'] ?? 0),
                            'songs_skipped'        => (int)($summary['songs_skipped_existing'] ?? 0),
                            'songs_failed'         => (int)($summary['songs_failed'] ?? 0),
                            'songbooks_created'    => $summary['songbooks_created'] ?? [],
                            'songbooks_existing'   => $summary['songbooks_existing'] ?? [],
                            'logged_failures'      => (int)($summary['logged_failures'] ?? 0),
                            'omitted_failure_rows' => (int)($summary['omitted_failure_rows'] ?? 0),
                        ],
                        ((int)($summary['songs_failed'] ?? 0) > 0) ? 'failure' : 'success'
                    );
                } catch (\Throwable $_e) {
                    error_log('[bulk_import_zip] activity log skipped: ' . $_e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[bulk_import_zip async worker] ' . $e->getMessage());
            try {
                _bulkImport_jobMark($db, $jobId, 'failed', [
                    'CompletedAt' => 'NOW()',
                    'ErrorsJson'  => json_encode(
                        [['entry' => '', 'error' => $e->getMessage()]],
                        JSON_UNESCAPED_UNICODE
                    ),
                ]);
            } catch (\Throwable $_) { /* DB itself is unreachable */ }
            /* #908 — activity-log row for the worker-fatal case so
               admins see the failure in the audit trail. */
            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'import.bulk_worker_failed',
                        'bulk_import_job',
                        (string)$jobId,
                        ['filename' => $origName, 'error' => $e->getMessage()],
                        'error'
                    );
                } catch (\Throwable $_) { /* best-effort */ }
            }
            @unlink($persistPath);
        }
        return; /* worker is done; no further switch processing needed */

    /* -----------------------------------------------------------------
     * BULK_IMPORT_VIDEOPSALM — single-file VideoPsalm songbook import
     * (#883).
     *
     * VideoPsalm distributes whole hymnals as one .json document with
     * a top-level `Songs[]` array. The editor frontend detects that
     * shape on file pick (importJsonCorpus → VideoPsalm branch) and
     * posts the file as multipart/form-data with the `videopsalm`
     * field name. Synchronous: a whole hymnal parses in well under
     * a second, no need for the async / progress-widget machinery
     * that bulk_import_zip uses for multi-MB archives.
     *
     * Returns the same summary shape as bulk_import_zip's sync
     * fallback so the frontend's success / error handlers stay
     * format-agnostic.
     * ----------------------------------------------------------------- */
    case 'bulk_import_videopsalm':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['videopsalm']) || ($_FILES['videopsalm']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['videopsalm']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "videopsalm" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        /* Hard size cap mirrors the ZIP path's per-entry limit (5 MiB)
           — no real VideoPsalm songbook approaches this. The full
           bundle of free songbooks on the publisher's site is well
           under this size in total. */
        $sizeBytes = (int)($_FILES['videopsalm']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded VideoPsalm file exceeds the 5 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['videopsalm']['tmp_name'];
            $origName = (string)($_FILES['videopsalm']['name'] ?? 'songbook.json');
            $body     = (string)file_get_contents($tmpPath);
            if ($body === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processVideoPsalm($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                /* Songs were actually written — run the stale-prefix probe as a
                   belt-and-braces sweep (the songs.json cache was removed in
                   WS-J #1020, so there's nothing to regenerate). Best-effort.
                   FIX: this case never assigned $db, so the previous
                   songbookMaintenanceRun($db, …) threw a TypeError on a
                   successful import (undefined → non-nullable \mysqli param). */
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_videopsalm');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_videopsalm] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_PPTX — PowerPoint worship deck import (#1095).
     *   multipart field "pptx" = one .pptx deck.
     * Parses slide text + segments into songs (PptxImporter); resolves each
     * song's songbook from its "# <num>-<Songbook>" ref (existing songs dedup
     * automatically). Decks not using that layout return ok:false + a warning
     * so the frontend can offer submit-for-analysis (#1109). Same summary shape
     * as the other importers. Legacy binary .ppt is rejected with guidance.
     * ----------------------------------------------------------------- */
    case 'bulk_import_pptx':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));
        if (!isset($_FILES['pptx']) || ($_FILES['pptx']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['pptx']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
                 ? 'Uploaded file is larger than the server limit.'
                 : ($err === UPLOAD_ERR_NO_FILE ? 'No file received — expected a multipart upload with a "pptx" field.' : 'Upload failed.');
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }
        if ((int)($_FILES['pptx']['size'] ?? 0) > 20 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded PowerPoint file exceeds the 20 MiB import limit.']);
            break;
        }
        try {
            $tmpPath  = (string)$_FILES['pptx']['tmp_name'];
            $origName = (string)($_FILES['pptx']['name'] ?? 'deck.pptx');
            /* Legacy binary .ppt is a different (OLE) format — guide the user. */
            if (preg_match('/\.ppt$/i', $origName)) {
                http_response_code(400);
                echo json_encode(['error' => 'Legacy .ppt is not supported. Please re-save the deck as .pptx (PowerPoint / Keynote / Google Slides → export as .pptx) and upload again.']);
                break;
            }
            $summary = _bulkImport_processPptx($tmpPath, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_pptx] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_OPENLP — single OpenLyrics (.xml) song import (#1052).
     *
     * POST /manage/editor/api?action=bulk_import_openlp
     *   multipart field "openlp" = one OpenLyrics .xml document.
     *
     * OpenLP "Export songs" writes one OpenLyrics .xml per song, each
     * carrying its own <songbook name="…" entry="N"/> — so the songbook
     * is derived from the file, not a folder convention. A whole FOLDER
     * of OpenLyrics files exported as a .zip goes through the normal
     * bulk_import_zip endpoint, which now content-sniffs .xml entries and
     * routes OpenLyrics ones to the same parser (no folder convention).
     *
     * Insert-only — existing rows report as "skipped (existing)". Honours
     * the #1051 dedupeMode flag. Returns the same summary shape as
     * bulk_import_videopsalm so the frontend stays format-agnostic.
     * ----------------------------------------------------------------- */
    case 'bulk_import_openlp':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['openlp']) || ($_FILES['openlp']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['openlp']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with an "openlp" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        /* A single OpenLyrics song is a few KiB; cap at the same 5 MiB
           ceiling the other single-file path uses. */
        $sizeBytes = (int)($_FILES['openlp']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded OpenLyrics file exceeds the 5 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['openlp']['tmp_name'];
            $origName = (string)($_FILES['openlp']['name'] ?? 'song.xml');
            $body     = (string)file_get_contents($tmpPath);
            if ($body === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processOpenLp($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_openlp');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_openlp] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_PRO6 — single ProPresenter 6 (.pro6) song import (#1057).
     *
     * POST /manage/editor/api?action=bulk_import_pro6
     *   multipart field "pro6" = one .pro6 XML document.
     *
     * A .pro6 presentation is one song; it carries no songbook, so the
     * song is filed under a single "ProPresenter Import" (PP6) songbook.
     * Slide text is base64 RTF — decoded + flattened by the parser. A ZIP
     * of .pro6 files goes through bulk_import_zip (handled inline there).
     *
     * Insert-only; honours the #1051 dedupeMode flag. Same summary shape
     * as the other single-file import endpoints.
     * ----------------------------------------------------------------- */
    case 'bulk_import_pro6':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['pro6']) || ($_FILES['pro6']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['pro6']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "pro6" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        /* A .pro6 can embed media references but the text doc itself is
           small; cap at the same 5 MiB ceiling as the other single-file
           paths. */
        $sizeBytes = (int)($_FILES['pro6']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded .pro6 file exceeds the 5 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['pro6']['tmp_name'];
            $origName = (string)($_FILES['pro6']['name'] ?? 'song.pro6');
            $body     = (string)file_get_contents($tmpPath);
            if ($body === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processPro6($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_pro6');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_pro6] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_EASYWORSHIP — EasyWorship 6/7 SQLite import (#1058).
     *
     * POST /manage/editor/api?action=bulk_import_easyworship
     *   multipart field "easyworship" = a Songs.db, OR a .zip containing
     *   Songs.db (+ optional SongWords.db).
     *
     * Lyrics are RTF inside a SQLite `word` table; songs file under an
     * "EasyWorship Import" (EW) songbook. Insert-only; honours #1051
     * dedupeMode. Unlike the XML/JSON paths this passes the temp-file PATH
     * to the processor (SQLite3 opens a file, not a string).
     * ----------------------------------------------------------------- */
    case 'bulk_import_easyworship':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['easyworship']) || ($_FILES['easyworship']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['easyworship']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with an "easyworship" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        /* A full EasyWorship library can be larger than a single song file;
           allow up to 64 MiB for the SQLite db / zip. */
        $sizeBytes = (int)($_FILES['easyworship']['size'] ?? 0);
        if ($sizeBytes > 64 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded EasyWorship file exceeds the 64 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['easyworship']['tmp_name'];
            $origName = (string)($_FILES['easyworship']['name'] ?? 'Songs.db');
            if (!is_uploaded_file($tmpPath)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid upload.']);
                break;
            }
            $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_easyworship');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_easyworship] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_PROCLAIM — single Proclaim text/RTF song import (#1062).
     *
     * POST /manage/editor/api?action=bulk_import_proclaim
     *   multipart field "proclaim" = one .txt or .rtf song export.
     *
     * Proclaim has no rich structured export, so one file = one song. Files
     * under a "Proclaim Import" (PC) songbook. Insert-only; honours #1051.
     * ----------------------------------------------------------------- */
    /* -----------------------------------------------------------------
     * BULK_IMPORT_CHORDPRO — single ChordPro (.cho/.pro/.chopro/.crd/.chord)
     * song import (#1264). multipart field "chordpro" = one ChordPro document
     * (covers WorshipTools' hand-copied-from-Chords-tab export shape, OnSong /
     * OpenSong / SongBeamer / Planning Center interchange). Lyrics + section
     * structure + header metadata; inline [chord] markers are parsed out (per-
     * line chord STORAGE deferred to #299/#1094 — see
     * _bulkImport_chordProStripChords()). One file = one song, filed under a
     * "ChordPro Import" (CHORDPRO) book unless the filename uses the
     * "<#> (<ABBR>) - <Title>" convention. Insert-only; honours #1051. Round-
     * trips with the lyrics-only ChordPro exporter (PR #1277).
     * ----------------------------------------------------------------- */
    case 'bulk_import_chordpro':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['chordpro']) || ($_FILES['chordpro']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['chordpro']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "chordpro" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }
        $sizeBytes = (int)($_FILES['chordpro']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded ChordPro file exceeds the 5 MiB import limit.']);
            break;
        }
        try {
            $tmpPath  = (string)$_FILES['chordpro']['tmp_name'];
            $origName = (string)($_FILES['chordpro']['name'] ?? 'song.cho');
            $body     = (string)file_get_contents($tmpPath);
            if (trim($body) === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processChordPro($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_chordpro');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_chordpro] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    case 'bulk_import_proclaim':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['proclaim']) || ($_FILES['proclaim']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['proclaim']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "proclaim" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        $sizeBytes = (int)($_FILES['proclaim']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded Proclaim file exceeds the 5 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['proclaim']['tmp_name'];
            $origName = (string)($_FILES['proclaim']['name'] ?? 'song.txt');
            $body     = (string)file_get_contents($tmpPath);
            if (trim($body) === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processProclaim($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_proclaim');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_proclaim] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_FREESHOW — single FreeShow (.show) song import (#884).
     *
     * POST /manage/editor/api?action=bulk_import_freeshow
     *   multipart field "freeshow" = one .show JSON document.
     *
     * One .show is one song; it carries no songbook, so it files under a
     * "FreeShow Import" (FS) songbook. Round-trips with the FreeShow exporter
     * (#1056). A ZIP of .show files goes through bulk_import_zip (inline).
     * Insert-only; honours #1051.
     * ----------------------------------------------------------------- */
    case 'bulk_import_freeshow':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        _bulkImport_dedupeMode((string)($_POST['dedupeMode'] ?? 'off'));  /* #1051 */
        if (!isset($_FILES['freeshow']) || ($_FILES['freeshow']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['freeshow']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = 'Upload failed.';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'Uploaded file is larger than the server limit.';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file received — expected a multipart upload with a "freeshow" field.';
            }
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        $sizeBytes = (int)($_FILES['freeshow']['size'] ?? 0);
        if ($sizeBytes > 5 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Uploaded .show file exceeds the 5 MiB import limit.']);
            break;
        }

        try {
            $tmpPath  = (string)$_FILES['freeshow']['tmp_name'];
            $origName = (string)($_FILES['freeshow']['name'] ?? 'song.show');
            $body     = (string)file_get_contents($tmpPath);
            if (trim($body) === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Uploaded file is empty.']);
                break;
            }
            $summary = _bulkImport_processFreeShow($body, $origName);
            if (!($summary['ok'] ?? false)) {
                http_response_code(400);
            } elseif (($summary['songs_created'] ?? 0) > 0) {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
                $_maint = songbookMaintenanceRun(getDbMysqli(), 'bulk_import_freeshow');
                if ($_maint['rewritten'] > 0 || $_maint['deferred']) {
                    $summary['maintenance'] = $_maint;
                }
            }
            echo json_encode($summary, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[bulk_import_freeshow] ' . $e->getMessage());
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * EASYWORSHIP_EXPORT — build + stream an EasyWorship Songs.db (#1059).
     *
     * GET /manage/editor/api?action=easyworship_export&abbr=XX[&maxLinesPerSlide=N]
     *   or &id=<SongId> for a single song. Streams a SQLite Songs.db
     *   (song + word[RTF] tables) as a download.
     *
     * BETA / unverified: produces the core two-table schema the iHymns
     * EasyWorship importer (#1058) round-trips; whether a live EasyWorship
     * install reads it has not been confirmed.
     * ----------------------------------------------------------------- */
    case 'easyworship_export':
        $abbr     = strtoupper(trim((string)($_GET['abbr'] ?? '')));
        $oneId    = trim((string)($_GET['id'] ?? ''));
        $maxLines = max(0, (int)($_GET['maxLinesPerSlide'] ?? 0));
        try {
            $songData = new SongData();
            $songs    = [];
            $stem     = 'EasyWorship';
            if ($oneId !== '') {
                $one = $songData->getSongById($oneId);
                if ($one !== null) { $songs = [$one]; $stem = (string)($one['title'] ?? $oneId); }
            } elseif ($abbr !== '') {
                $songs = $songData->getSongs($abbr);
                $stem  = $abbr;
            }
            if (empty($songs)) {
                http_response_code(404);
                echo json_encode(['error' => 'No songs found to export (pass ?abbr=<songbook> or ?id=<SongId>).']);
                break;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ewexp_');
            $n   = _ewExport_writeDb($tmp, $songs, $maxLines);
            $fname = trim((string)preg_replace('/[^A-Za-z0-9 _\-]/', '', $stem));
            if ($fname === '') { $fname = 'EasyWorship'; }
            $fname .= ' Songs.db';
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Song-Count: ' . $n);
            readfile($tmp);
            @unlink($tmp);
            /* Streamed a binary file — don't fall through to the JSON default. */
            return;
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[easyworship_export] ' . $e->getMessage());
            echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_STATUS — poll one async-import job (#676).
     *
     * GET /manage/editor/api?action=bulk_import_status&job_id=N
     *   → { ok: true, job: {
     *         id, status, filename, size_bytes,
     *         total_entries, processed_entries, percent,
     *         songs_created, songs_skipped_existing, songs_failed,
     *         songbooks_created, songbooks_existing,
     *         errors,
     *         started_at, completed_at, created_at, updated_at,
     *       } }
     *
     * Auth: editor+ (matches the rest of this file). The query also
     * gates WHERE UserId = ? so a curator can only poll their OWN
     * jobs — the row is invisible to anyone else even though
     * /manage/editor/api.php is shared.
     *
     * Pre-migration deployments (no tblBulkImportJobs) return a 404
     * with `migration_needed: true` so the frontend can fall back
     * to the synchronous flow without surprising the user.
     * ----------------------------------------------------------------- */
    case 'bulk_import_status':
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'job_id required.']);
            break;
        }

        try {
            $db = getDbMysqli();
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblBulkImportJobs' LIMIT 1"
            );
            $probe->execute();
            $jobsTableReady = $probe->get_result()->fetch_row() !== null;
            $probe->close();

            if (!$jobsTableReady) {
                http_response_code(404);
                echo json_encode([
                    'error'             => 'Bulk-import job tracking is not enabled on this deployment.',
                    'migration_needed'  => true,
                ]);
                break;
            }

            $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
            /* #906 / #907 — schema-probe for newer columns so a pre-
               migration deploy returns a clean response without 1054.
               Both probes are request-cached via static so a polling
               client doesn't fire fresh probes per request. */
            static $hasPerSongbookCol = null;
            static $hasPhaseLabelCol  = null;
            if ($hasPerSongbookCol === null) {
                try {
                    $probeCol = $db->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblBulkImportJobs'
                            AND COLUMN_NAME  = 'PerSongbookJson' LIMIT 1"
                    );
                    $hasPerSongbookCol = $probeCol && $probeCol->fetch_row() !== null;
                    if ($probeCol) $probeCol->close();
                } catch (\Throwable $_e) { $hasPerSongbookCol = false; }
            }
            if ($hasPhaseLabelCol === null) {
                try {
                    $probeCol = $db->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblBulkImportJobs'
                            AND COLUMN_NAME  = 'PhaseLabel' LIMIT 1"
                    );
                    $hasPhaseLabelCol = $probeCol && $probeCol->fetch_row() !== null;
                    if ($probeCol) $probeCol->close();
                } catch (\Throwable $_e) { $hasPhaseLabelCol = false; }
            }
            $perSongbookSelect = $hasPerSongbookCol ? ', PerSongbookJson' : ', NULL AS PerSongbookJson';
            $phaseLabelSelect  = $hasPhaseLabelCol  ? ', PhaseLabel'      : ', NULL AS PhaseLabel';
            $stmt = $db->prepare(
                'SELECT Id, UserId, Filename, SizeBytes, Status,
                        TotalEntries, ProcessedEntries,
                        SongbooksCreatedJson, SongbooksExistingJson,
                        SongsCreated, SongsSkippedExisting, SongsFailed,
                        ErrorsJson' . $perSongbookSelect . $phaseLabelSelect . ',
                        StartedAt, CompletedAt, CreatedAt, UpdatedAt
                   FROM tblBulkImportJobs
                  WHERE Id = ? AND UserId = ?
                  LIMIT 1'
            );
            $stmt->bind_param('ii', $jobId, $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Job not found.']);
                break;
            }

            /* Decode the JSON columns lazily so the frontend gets
               structured arrays instead of JSON strings. NULL on
               either side stays NULL on the wire. */
            $decode = static fn($s) => $s === null ? null : json_decode($s, true);

            $total      = (int)$row['TotalEntries'];
            $processed  = (int)$row['ProcessedEntries'];
            $percent    = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

            echo json_encode([
                'ok'  => true,
                'job' => [
                    'id'                      => (int)$row['Id'],
                    'status'                  => (string)$row['Status'],
                    'filename'                => (string)$row['Filename'],
                    'size_bytes'              => (int)$row['SizeBytes'],
                    'total_entries'           => $total,
                    'processed_entries'       => $processed,
                    'percent'                 => $percent,
                    'songs_created'           => (int)$row['SongsCreated'],
                    'songs_skipped_existing'  => (int)$row['SongsSkippedExisting'],
                    'songs_failed'            => (int)$row['SongsFailed'],
                    'songbooks_created'       => $decode($row['SongbooksCreatedJson'])  ?? [],
                    'songbooks_existing'      => $decode($row['SongbooksExistingJson']) ?? [],
                    'errors'                  => $decode($row['ErrorsJson'])            ?? [],
                    /* #906 — per-songbook breakdown of created / skipped /
                       failed counts so the frontend can render a per-book
                       table without re-deriving from `errors[]` heuristics.
                       Pre-migration deploys return null (column read as
                       NULL via `, NULL AS PerSongbookJson` above). */
                    'per_songbook'            => $decode($row['PerSongbookJson'])       ?? null,
                    /* Skip reason is uniform today — every skip means the
                       SongId already existed in tblSongs (INSERT-only
                       contract). Future skip classes (parse-fail, invalid-
                       songbook) would surface as 'failed' under the
                       current pipeline, not 'skipped', so this string is
                       safe as a static label for now. The completion
                       notification renders it inline so the curator never
                       has to guess what the aggregate "X skipped" count
                       means. */
                    'skip_reason'             => 'existing-in-db',
                    /* URL the frontend's "Download skipped SongIds"
                       button POSTs to. Resolves on the server side to
                       SkippedSongIdsJson + a lookup against tblSongs
                       to add the canonical Title and Songbook columns.
                       Empty string when the import had zero skips. */
                    'skipped_csv_url'         => (int)$row['SongsSkippedExisting'] > 0
                        ? '/manage/editor/api?action=bulk_import_skipped_csv&job_id=' . (int)$row['Id']
                        : '',
                    /* #907 — current worker phase: walking-zip / parsing-songs /
                       flushing-songbooks / completed. Pre-migration deploys
                       return null and the frontend hides the phase label. */
                    'phase_label'             => $row['PhaseLabel']                     ?? null,
                    'started_at'              => $row['StartedAt'],
                    'completed_at'            => $row['CompletedAt'],
                    'created_at'              => $row['CreatedAt'],
                    'updated_at'              => $row['UpdatedAt'],
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('[bulk_import_status] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Status query failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * BULK_IMPORT_SKIPPED_CSV — stream a CSV of every SongId the worker
     * skipped on a completed bulk-import job, joined to tblSongs for the
     * canonical Title + Songbook columns. Powers the "Download skipped
     * SongIds" button on the completion notification so a curator can
     * audit exactly which rows the import refused to overwrite.
     *
     * Every skip in today's pipeline is "SongId already exists in DB"
     * (INSERT-only contract on _bulkImport_saveSong) — reflected in the
     * static "existing-in-db" string in the Reason column. If future
     * skip classes are introduced, expand SkippedSongIdsJson to a
     * keyed-object shape and surface the per-row reason here.
     *
     * GET /manage/editor/api?action=bulk_import_skipped_csv&job_id=N
     *
     * 403 if the calling user doesn't own the job (matches
     * bulk_import_status's ownership check).
     * 404 if the job doesn't exist, hasn't completed, or its
     *      SkippedSongIdsJson column is empty/null.
     * 410 if the deployment hasn't run the
     *      migrate-bulk-import-skipped-songids.php migration yet.
     * ----------------------------------------------------------------- */
    case 'bulk_import_skipped_csv':
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'job_id required.']);
            break;
        }
        try {
            $db = getDbMysqli();

            /* Schema-probe — pre-migration deploys return a clear 410
               instead of a 500 with a missing-column error. */
            $probe = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblBulkImportJobs'
                    AND COLUMN_NAME  = 'SkippedSongIdsJson' LIMIT 1"
            );
            $hasCol = $probe && $probe->fetch_row() !== null;
            if ($probe) $probe->close();
            if (!$hasCol) {
                http_response_code(410);
                echo json_encode([
                    'error'            => 'Skipped-CSV download requires migrate-bulk-import-skipped-songids.php.',
                    'migration_needed' => true,
                ]);
                break;
            }

            $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
            $stmt = $db->prepare(
                'SELECT Filename, Status, SkippedSongIdsJson
                   FROM tblBulkImportJobs
                  WHERE Id = ? AND UserId = ? LIMIT 1'
            );
            $stmt->bind_param('ii', $jobId, $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Job not found.']);
                break;
            }
            if ((string)$row['Status'] !== 'completed') {
                http_response_code(409);
                echo json_encode(['error' => 'Job is not yet completed.']);
                break;
            }
            $skipped = json_decode((string)($row['SkippedSongIdsJson'] ?? '[]'), true);
            if (!is_array($skipped) || !$skipped) {
                http_response_code(404);
                echo json_encode(['error' => 'No skipped SongIds recorded for this job.']);
                break;
            }

            /* Look up Title + Songbook for each SongId in one bound IN()
               query. Preserve the original skip-order in the CSV so the
               curator sees rows in the same order the worker processed
               them. */
            $placeholders = implode(',', array_fill(0, count($skipped), '?'));
            $types        = str_repeat('s', count($skipped));
            $look = $db->prepare(
                "SELECT s.SongId, s.Title, s.SongbookAbbr, sb.Name AS SongbookName
                   FROM tblSongs s
                   LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE s.SongId IN ({$placeholders})"
            );
            $look->bind_param($types, ...$skipped);
            $look->execute();
            $rowsBySongId = [];
            $res = $look->get_result();
            while ($r = $res->fetch_assoc()) {
                $rowsBySongId[(string)$r['SongId']] = $r;
            }
            $look->close();

            /* CSV streaming. fputcsv on php://output handles RFC 4180
               quoting + UTF-8. The BOM prefix gets Excel to treat the
               file as UTF-8 instead of mojibake-decoding it as
               Windows-1252. */
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_',
                          'skipped-songids-job-' . $jobId . '-' . pathinfo((string)$row['Filename'], PATHINFO_FILENAME) . '.csv'
                        ) ?? 'skipped-songids.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo "\xEF\xBB\xBF"; /* UTF-8 BOM */
            $out = fopen('php://output', 'w');
            fputcsv($out, ['SongId', 'Title', 'SongbookAbbr', 'SongbookName', 'Reason']);
            foreach ($skipped as $sid) {
                $sid = (string)$sid;
                $r   = $rowsBySongId[$sid] ?? null;
                fputcsv($out, [
                    $sid,
                    $r ? (string)$r['Title'] : '',
                    $r ? (string)$r['SongbookAbbr'] : '',
                    $r ? (string)$r['SongbookName'] : '',
                    'existing-in-db',
                ]);
            }
            fclose($out);
            /* Don't fall through to the JSON-encoding default at the
               bottom of the switch — we've already streamed a CSV. */
            return;
        } catch (\Throwable $e) {
            error_log('[bulk_import_skipped_csv] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Skipped-CSV download failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_MEDIA_LIST — return the media rows attached to a song so the
     * Song Editor's chip-list editor (#853 phase C) can render existing
     * uploads on modal-open. Schema-probed: a pre-migration deploy
     * returns an empty list rather than 500ing. Bytes are NEVER
     * included in this payload — the editor surfaces filename,
     * kind, mime, size, annotation; the bytes only travel via the
     * /song-media/<id> streaming endpoint (phase E).
     * ----------------------------------------------------------------- */
    case 'song_media_list':
        $songId = trim((string)($_GET['song_id'] ?? ''));
        if ($songId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'song_id required.']);
            break;
        }
        try {
            $db = getDbMysqli();
            if (!_songMedia_tableExists($db)) {
                /* Pre-migration deploy → empty list. */
                echo json_encode(['ok' => true, 'media' => []]);
                break;
            }
            $stmt = $db->prepare(
                'SELECT Id, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                        Annotation, SortOrder, UploadedBy, UploadedAt
                   FROM tblSongMedia
                  WHERE SongId = ?
                  ORDER BY Kind ASC, SortOrder ASC, Id ASC'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $media = array_map(
                static fn(array $r): array => [
                    'id'             => (int)$r['Id'],
                    'kind'           => (string)$r['Kind'],
                    'storage_backend'=> (string)$r['StorageBackend'],
                    'file_name'      => (string)$r['FileName'],
                    'mime_type'      => (string)$r['MimeType'],
                    'size_bytes'     => (int)$r['SizeBytes'],
                    'annotation'     => (string)($r['Annotation'] ?? ''),
                    'sort_order'     => (int)$r['SortOrder'],
                    'uploaded_by'    => isset($r['UploadedBy']) ? (int)$r['UploadedBy'] : null,
                    'uploaded_at'    => (string)$r['UploadedAt'],
                    'stream_url'     => '/song-media/' . (int)$r['Id'],
                ],
                $rows
            );
            echo json_encode(['ok' => true, 'media' => $media], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[song_media_list] ' . $e->getMessage());
            echo json_encode(['error' => 'Could not fetch media.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_MEDIA_UPLOAD — accept a single uploaded file for a song.
     *
     * Multipart fields:
     *   song_id     — target SongId (must exist in tblSongs)
     *   kind        — one of audio | sheet-music | midi | musicxml
     *   annotation  — optional curator note (≤255 chars)
     *   file        — the upload itself
     *
     * Pipeline:
     *   1. requireEditor (already enforced globally at the top of the
     *      file); upload-rights = editor / admin / global_admin.
     *   2. Validate kind, song existence, $_FILES error code.
     *   3. SongMediaStorage::validateUpload() — sniffs the MIME on
     *      bytes (not the upload's declared content-type) + caps size
     *      against per-kind SIZE_CAPS.
     *   4. SongMediaStorage::stage() — writes to disk for FS kinds,
     *      returns bytes for DB kinds. Handles dir mkdir + chmod.
     *   5. INSERT tblSongMedia row, with SortOrder = (max+1) for the
     *      same (song, kind) so new uploads land at the bottom.
     *   6. On INSERT failure for an FS kind: unlink the staged file
     *      so it doesn't orphan.
     *   7. logActivity('song-media.upload', 'song', $songId, ...).
     * ----------------------------------------------------------------- */
    case 'song_media_upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';

        $songId     = trim((string)($_POST['song_id'] ?? ''));
        $kind       = trim((string)($_POST['kind'] ?? ''));
        $annotation = trim((string)($_POST['annotation'] ?? ''));

        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid song_id / kind.']);
            break;
        }
        if ($annotation !== '' && function_exists('mb_substr')) {
            $annotation = mb_substr($annotation, 0, 255);
        }

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected multipart with a "file" field.',
                UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
                default                                   => 'Upload failed.',
            };
            http_response_code(400);
            echo json_encode(['error' => $msg, 'phpError' => $err]);
            break;
        }

        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload');
        $size     = (int)($_FILES['file']['size'] ?? 0);

        try {
            $db = getDbMysqli();
            if (!_songMedia_tableExists($db)) {
                http_response_code(503);
                echo json_encode(['error' => 'Song Media migration has not been run.']);
                break;
            }

            /* Song must exist — gives a clean 404 instead of an FK violation. */
            $stmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$exists) {
                http_response_code(404);
                echo json_encode(['error' => 'Song not found.']);
                break;
            }

            /* MIME sniff + size cap. Returns canonical extension/mime. */
            $meta = SongMediaStorage::validateUpload($tmpPath, $kind, $size);

            /* Read bytes once + stage. FS kinds get a path back; DB
               kinds get the bytes for binding inline. */
            $bytes = file_get_contents($tmpPath);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read upload tempfile.');
            }
            $staged = SongMediaStorage::stage($bytes, $kind, $meta['extension']);

            /* Sanitise the filename we record — keep just the basename
               + clamp length so a curator-pasted weirdness doesn't end
               up in our Content-Disposition header later. */
            $cleanName = basename($origName);
            $cleanName = preg_replace('/[\x00-\x1f\x7f]/', '', $cleanName) ?? 'upload';
            $cleanName = function_exists('mb_substr')
                ? mb_substr($cleanName, 0, 255)
                : substr($cleanName, 0, 255);

            /* SortOrder = (current max + 1) for this (song, kind) so
               new uploads append; the curator can drag-reorder later. */
            $stmt = $db->prepare(
                'SELECT COALESCE(MAX(SortOrder), -1) AS m FROM tblSongMedia WHERE SongId = ? AND Kind = ?'
            );
            $stmt->bind_param('ss', $songId, $kind);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $nextOrder = (int)($row['m'] ?? -1) + 1;

            $uploadedBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
            $annotationOrNull = ($annotation !== '') ? $annotation : null;

            /* Insert. Note: bind_param 's' for the BLOB content works
               for our sub-16MB cap — mysqli is binary-safe; send_long_data
               isn't required here. */
            try {
                $stmt = $db->prepare(
                    'INSERT INTO tblSongMedia
                         (SongId, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                          Sha256, Content, StoragePath, Annotation, SortOrder, UploadedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'sssssissssii',
                    $songId,
                    $kind,
                    $staged['backend'],
                    $cleanName,
                    $meta['mime'],
                    $size,
                    $staged['sha256'],
                    $staged['content'],
                    $staged['path'],
                    $annotationOrNull,
                    $nextOrder,
                    $uploadedBy
                );
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();
            } catch (\Throwable $insertErr) {
                /* Roll back the staged file so it doesn't orphan. */
                if ($staged['backend'] === 'filesystem' && $staged['path'] !== null) {
                    SongMediaStorage::deleteStorage([
                        'StorageBackend' => 'filesystem',
                        'StoragePath'    => $staged['path'],
                    ]);
                }
                throw $insertErr;
            }

            if (function_exists('logActivity')) {
                logActivity(
                    'song-media.upload',
                    'song',
                    $songId,
                    [
                        'media_id'   => $newId,
                        'kind'       => $kind,
                        'backend'    => $staged['backend'],
                        'file_name'  => $cleanName,
                        'mime'       => $meta['mime'],
                        'size_bytes' => $size,
                        'sha256'     => $staged['sha256'],
                    ]
                );
            }

            echo json_encode([
                'ok'    => true,
                'media' => [
                    'id'              => $newId,
                    'kind'            => $kind,
                    'storage_backend' => $staged['backend'],
                    'file_name'       => $cleanName,
                    'mime_type'       => $meta['mime'],
                    'size_bytes'      => $size,
                    'annotation'      => $annotation,
                    'sort_order'      => $nextOrder,
                    'uploaded_by'     => $uploadedBy,
                    'uploaded_at'     => date('Y-m-d H:i:s'),
                    'stream_url'      => '/song-media/' . $newId,
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            /* SongMediaStorage throws \RuntimeException for user-fixable
               problems (wrong mime, oversize, empty); 400 those.
               Anything else is server-side → 500. */
            $userFacing = $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
            http_response_code($userFacing ? 400 : 500);
            error_log('[song_media_upload] ' . $msg);
            echo json_encode(['error' => $userFacing ? $msg : 'Upload failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_MEDIA_UPDATE — patch the annotation on an existing row.
     * Only Annotation is mutable post-upload — kind / file / mime /
     * size are immutable (delete + re-upload to change them).
     *
     * POST: media_id, annotation
     * ----------------------------------------------------------------- */
    case 'song_media_update':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        $mediaId    = (int)($_POST['media_id'] ?? 0);
        $annotation = trim((string)($_POST['annotation'] ?? ''));
        if ($mediaId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'media_id required.']);
            break;
        }
        if ($annotation !== '' && function_exists('mb_substr')) {
            $annotation = mb_substr($annotation, 0, 255);
        }
        $annotationOrNull = ($annotation !== '') ? $annotation : null;
        try {
            $db = getDbMysqli();
            if (!_songMedia_tableExists($db)) {
                http_response_code(404);
                echo json_encode(['error' => 'Media not found.']);
                break;
            }
            $stmt = $db->prepare(
                'UPDATE tblSongMedia SET Annotation = ? WHERE Id = ?'
            );
            $stmt->bind_param('si', $annotationOrNull, $mediaId);
            $stmt->execute();
            $touched = $stmt->affected_rows;
            $stmt->close();
            if ($touched === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Media not found.']);
                break;
            }
            if (function_exists('logActivity')) {
                logActivity(
                    'song-media.update',
                    'song-media',
                    (string)$mediaId,
                    ['annotation' => $annotation]
                );
            }
            echo json_encode(['ok' => true, 'media_id' => $mediaId]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[song_media_update] ' . $e->getMessage());
            echo json_encode(['error' => 'Update failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_MEDIA_DELETE — remove a single media row (and its underlying
     * storage). FS-backed: unlinks the file too. DB-backed: blob goes
     * with the row.
     *
     * POST: media_id
     * ----------------------------------------------------------------- */
    case 'song_media_delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';

        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'media_id required.']);
            break;
        }

        try {
            $db = getDbMysqli();
            if (!_songMedia_tableExists($db)) {
                http_response_code(404);
                echo json_encode(['error' => 'Media not found.']);
                break;
            }
            $stmt = $db->prepare(
                'SELECT Id, SongId, Kind, StorageBackend, StoragePath, FileName
                   FROM tblSongMedia WHERE Id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $mediaId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Media not found.']);
                break;
            }

            /* Unlink first, then delete the row. If the unlink fails
               we still proceed — orphan files on disk are recoverable;
               leaving the row alive after a "delete me" is worse. */
            SongMediaStorage::deleteStorage([
                'StorageBackend' => (string)$row['StorageBackend'],
                'StoragePath'    => (string)($row['StoragePath'] ?? ''),
            ]);

            $stmt = $db->prepare('DELETE FROM tblSongMedia WHERE Id = ?');
            $stmt->bind_param('i', $mediaId);
            $stmt->execute();
            $stmt->close();

            if (function_exists('logActivity')) {
                logActivity(
                    'song-media.delete',
                    'song',
                    (string)$row['SongId'],
                    [
                        'media_id'  => $mediaId,
                        'kind'      => (string)$row['Kind'],
                        'backend'   => (string)$row['StorageBackend'],
                        'file_name' => (string)$row['FileName'],
                    ]
                );
            }

            echo json_encode(['ok' => true, 'deleted' => $mediaId]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[song_media_delete] ' . $e->getMessage());
            echo json_encode(['error' => 'Delete failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SONG_MEDIA_REORDER — rewrite SortOrder for one (song, kind)
     * group. The UI posts the new ordered list; we treat array index
     * as the new SortOrder and write each row in a single transaction.
     *
     * POST:
     *   song_id
     *   kind
     *   ids[] — list of media row Ids in the new order
     * ----------------------------------------------------------------- */
    case 'song_media_reorder':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';

        $songId = trim((string)($_POST['song_id'] ?? ''));
        $kind   = trim((string)($_POST['kind'] ?? ''));
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) $rawIds = [];

        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid song_id / kind.']);
            break;
        }

        $orderedIds = [];
        foreach ($rawIds as $raw) {
            $id = (int)$raw;
            if ($id > 0 && !in_array($id, $orderedIds, true)) $orderedIds[] = $id;
        }
        if (empty($orderedIds)) {
            echo json_encode(['ok' => true, 'reordered' => 0]);
            break;
        }

        try {
            $db = getDbMysqli();
            if (!_songMedia_tableExists($db)) {
                http_response_code(503);
                echo json_encode(['error' => 'Song Media migration has not been run.']);
                break;
            }
            $db->begin_transaction();
            try {
                /* UPDATE one row at a time, scoped to (song, kind, id)
                   so a curator can't accidentally reorder rows from
                   another song or another kind by passing the wrong
                   ids — the WHERE clause is the safety net. */
                $stmt = $db->prepare(
                    'UPDATE tblSongMedia SET SortOrder = ?
                      WHERE Id = ? AND SongId = ? AND Kind = ?'
                );
                $written = 0;
                foreach ($orderedIds as $i => $id) {
                    $stmt->bind_param('iiss', $i, $id, $songId, $kind);
                    $stmt->execute();
                    $written += $stmt->affected_rows;
                }
                $stmt->close();
                $db->commit();
            } catch (\Throwable $txErr) {
                try { $db->rollback(); } catch (\Throwable $_) {}
                throw $txErr;
            }

            if (function_exists('logActivity')) {
                logActivity(
                    'song-media.reorder',
                    'song',
                    $songId,
                    [
                        'kind'         => $kind,
                        'ordered_ids'  => $orderedIds,
                    ]
                );
            }

            echo json_encode(['ok' => true, 'reordered' => $written]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('[song_media_reorder] ' . $e->getMessage());
            echo json_encode(['error' => 'Reorder failed.']);
        }
        break;

    /* -----------------------------------------------------------------
     * delete_song (#1200 Phase 0) — REMOVE a song + its ENTIRE dependent
     * subtree. The first real server-side delete: the legacy editor's
     * deleteSong() was CLIENT-ONLY (no endpoint existed), so its toast lied
     * and the song came back on refresh (a #1010 regression). Every inbound FK
     * to tblSongs(SongId) is ON DELETE CASCADE (37) or SET NULL (3) — VERIFIED
     * against INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS — so ONE cascade
     * DELETE atomically removes components → lyrics → words → syllables,
     * credits, revisions, media, tags, links, presentation rows, etc. with no
     * orphans and no RESTRICT failure (no hand-ordering, no FK-checks-off).
     * Guarded by the #1200 CSRF gate (the editor API had none). Returns the
     * TRUE affected_rows — never a false success. Dormant until the rewrite UI
     * calls it (per-phase hard cutover).
     * ----------------------------------------------------------------- */
    case 'delete_song':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST method required.']);
            break;
        }
        /* CSRF — the editor posts JSON, so the token rides an explicit
           X-CSRF-Token header (emitted as a <meta> by the rewrite editor head). */
        if (!validateCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
            break;
        }
        $delBody   = json_decode(file_get_contents('php://input') ?: '', true);
        $delSongId = trim((string)(is_array($delBody) ? ($delBody['songId'] ?? $delBody['id'] ?? '') : ($_GET['id'] ?? '')));
        /* #1343 — optional relink target for the deleted song's permalink (blank =
           "removed" tombstone). Either way the old /song/<id> resolves, not 404s. */
        $delRedirectTo = trim((string)(is_array($delBody) ? ($delBody['redirectTo'] ?? '') : ''));
        if ($delSongId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'songId is required.']);
            break;
        }
        try {
            $db = getDbMysqli();
            $db->begin_transaction();
            /* Snapshot for the audit trail before the row vanishes. */
            $delPrev = $db->prepare('SELECT Title, SongbookAbbr FROM tblSongs WHERE SongId = ? LIMIT 1');
            $delPrev->bind_param('s', $delSongId);
            $delPrev->execute();
            $delPrevRow = $delPrev->get_result()->fetch_assoc();
            $delPrev->close();
            if ($delPrevRow === null) {
                $db->rollback();
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Song not found.', 'deleted' => 0]);
                break;
            }
            /* Resolve the relink target (a different, existing song) else tombstone. */
            $delTarget = null;
            if ($delRedirectTo !== '' && $delRedirectTo !== $delSongId) {
                $delChk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
                $delChk->bind_param('s', $delRedirectTo);
                $delChk->execute();
                if ($delChk->get_result()->fetch_row() !== null) { $delTarget = $delRedirectTo; }
                $delChk->close();
            }
            /* Keep permalinks alive (#1343) — gated. When relinking, FORWARD any
               redirects already pointing AT this song to the new target BEFORE the
               delete, so the FK ON DELETE SET NULL cascade can't strand a chain
               (mirrors the merge path). Tombstone (null) lets inbound fall to "removed". */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_redirects.php';
            if ($delTarget !== null) { songRedirectRepoint($db, $delSongId, $delTarget); }
            /* Single cascade delete — see the block comment above. */
            $delStmt = $db->prepare('DELETE FROM tblSongs WHERE SongId = ?');
            $delStmt->bind_param('s', $delSongId);
            $delStmt->execute();
            $delCount = $delStmt->affected_rows;
            $delStmt->close();
            songRedirectWrite($db, $delSongId, $delTarget, 'delete', (int)($currentUser['id'] ?? 0) ?: null);
            $db->commit();
            logActivity('song.delete', 'song', $delSongId, [
                'title'       => (string)($delPrevRow['Title'] ?? ''),
                'songbook'    => (string)($delPrevRow['SongbookAbbr'] ?? ''),
                'redirect_to' => $delTarget ?? '(tombstone)',
            ]);
            echo json_encode(['ok' => true, 'deleted' => (int)$delCount, 'songId' => $delSongId, 'redirectTo' => $delTarget]);
        } catch (\Throwable $e) {
            if (isset($db)) { try { $db->rollback(); } catch (\Throwable $_e) {} }
            error_log('[delete_song] ' . $e->getMessage());
            http_response_code(500);
            $delIsAdmin = isset($currentUser['role']) && hasRole($currentUser['role'], 'admin');
            echo json_encode([
                'ok'           => false,
                'error'        => 'Delete failed.',
                'error_detail' => $delIsAdmin ? $e->getMessage() : null,
            ]);
        }
        break;

    /* -----------------------------------------------------------------
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save, save_song, save_song_tags, tag_search, credit_search, bulk_tag, list_revisions, restore_revision, get_translations, add_translation, remove_translation, get_song_links, add_song_link, remove_song_link, suggest_song_links, dismiss_song_link_suggestion, bulk_import_zip, bulk_import_status, song_media_list, song_media_upload, song_media_delete, song_media_reorder, delete_song']);
        break;
}


/* ===========================================================================
 * BULK_IMPORT_ZIP helpers (#664)
 *
 * Kept at the bottom of this file rather than in a new module: every other
 * editor write path lives in this same file (save / save_song / restore
 * etc.) and the helpers here are not used outside of `bulk_import_zip`.
 *
 * The folder + filename layout mirrors what the .importers/scrapers/* tools
 * emit into .SourceSongData/, so this is also the format curators see when
 * comparing source-of-truth lyrics to the live database row.
 * =========================================================================== */

/* ===========================================================================
 *  EasyWorship export (#1059)
 * ---------------------------------------------------------------------------
 * Builds an EasyWorship-style SQLite Songs.db: a `song` table (title /
 * author / copyright / reference_number) + a `word` table (song_id, words)
 * whose `words` column is RTF (slides separated by \par\par, lines by \par).
 * Written with the SQLite3 class (not PDO). NOTE: this produces the core
 * two-table schema the iHymns EasyWorship importer (#1058) round-trips; real
 * EasyWorship may expect additional index/FTS tables, so "does EW itself read
 * it" should be confirmed against a live EasyWorship install.
 * =========================================================================== */

/**
 * Escape one string for RTF: \ { } literals, non-ASCII → \uN (signed 16-bit,
 * with a '?' fallback char). Mirrors the JS exporter's rtfEscape.
 */
function _ewExport_rtfEscape(string $text): string
{
    $out = '';
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch   = mb_substr($text, $i, 1, 'UTF-8');
        $code = mb_ord($ch, 'UTF-8');
        if ($ch === '\\')                  { $out .= '\\\\'; }
        elseif ($ch === '{')               { $out .= '\\{'; }
        elseif ($ch === '}')               { $out .= '\\}'; }
        elseif ($code !== false && $code < 128) { $out .= $ch; }
        else {
            $rtfCode = ($code !== false && $code > 32767) ? $code - 65536 : (int)$code;
            $out    .= '\\u' . $rtfCode . '?';
        }
    }
    return $out;
}

/**
 * Build the EasyWorship `words` RTF for one song. Slides (chunks of <=
 * $maxLines lines, or whole components when $maxLines <= 0) are separated by
 * \par\par; lines within a slide by \par.
 */
function _ewExport_buildRtf(array $components, int $maxLines): string
{
    $slides = [];
    foreach ($components as $comp) {
        $lines  = array_map('strval', (array)($comp['lines'] ?? []));
        $chunks = ($maxLines > 0) ? array_chunk($lines, $maxLines) : [$lines];
        foreach ($chunks as $chunk) {
            $esc = array_map('_ewExport_rtfEscape', $chunk);
            $slides[] = implode('\\par ', $esc);
        }
    }
    if (empty($slides)) { $slides[] = ''; }
    $body = implode('\\par\\par ', $slides);
    return '{\\rtf1\\ansi\\ansicpg1252{\\fonttbl\\f0\\fswiss Arial;}\\pard\\f0\\fs40 ' . $body . '}';
}

/**
 * Write an EasyWorship Songs.db (song + word tables) at $path for the given
 * iHymns song records (the SongData::getSongs() shape). Returns the song
 * count written.
 */
function _ewExport_writeDb(string $path, array $songs, int $maxLines): int
{
    if (!class_exists('SQLite3')) {
        throw new \RuntimeException('the SQLite3 PHP extension is not available');
    }
    @unlink($path);
    $db = new \SQLite3($path);
    $db->exec('CREATE TABLE song (rowid INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'title TEXT, author TEXT, copyright TEXT, reference_number TEXT)');
    $db->exec('CREATE TABLE word (rowid INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'song_id INTEGER, words TEXT)');

    $songStmt = $db->prepare('INSERT INTO song (title, author, copyright, reference_number) VALUES (?,?,?,?)');
    $wordStmt = $db->prepare('INSERT INTO word (song_id, words) VALUES (?,?)');

    $count = 0;
    $db->exec('BEGIN');
    foreach ($songs as $song) {
        $title = trim((string)($song['title'] ?? ''));
        if ($title === '') { continue; }
        $author = trim(implode(', ', array_filter(array_merge(
            (array)($song['writers'] ?? []),
            (array)($song['composers'] ?? [])
        ))));
        $copyright = (string)($song['copyright'] ?? '');
        $refnum    = (string)($song['number'] ?? '');
        $rtf       = _ewExport_buildRtf((array)($song['components'] ?? []), $maxLines);

        $songStmt->bindValue(1, $title, SQLITE3_TEXT);
        $songStmt->bindValue(2, $author, SQLITE3_TEXT);
        $songStmt->bindValue(3, $copyright, SQLITE3_TEXT);
        $songStmt->bindValue(4, $refnum, SQLITE3_TEXT);
        $songStmt->execute();
        $songStmt->reset();
        $songId = $db->lastInsertRowID();

        $wordStmt->bindValue(1, $songId, SQLITE3_INTEGER);
        $wordStmt->bindValue(2, $rtf, SQLITE3_TEXT);
        $wordStmt->execute();
        $wordStmt->reset();
        $count++;
    }
    $db->exec('COMMIT');
    $db->close();
    return $count;
}
