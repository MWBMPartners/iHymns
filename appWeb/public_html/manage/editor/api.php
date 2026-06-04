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

/**
 * Cached probe for tblSongMedia (#853). The table arrives via
 * migrate-song-media.php; until that migration has been applied, the
 * song_media_* endpoints return early with a friendly 503 / empty
 * list rather than 500ing on a partly-migrated install.
 */
function _songMedia_tableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
    );
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $cached;
}

/**
 * Validate + normalise an IETF BCP 47 language tag (#681).
 *
 * v1 grammar (matches the songbook editor's $validateBcp47): lowercase
 * 2-3 letter language, optional 4-letter Title Case script, optional
 * 2-letter UPPER region or 3-digit numeric area code. Variants /
 * extensions / private-use are out of scope and rejected so a tampered
 * POST can't smuggle exotic subtags into the column.
 *
 * Returns:
 *   - null  if `$tag` is empty (caller decides whether to default to
 *           'en' for a NOT NULL column or NULL for a nullable one).
 *   - the trimmed tag, capped to 35 chars (the new column width per
 *     #681), if it matches the v1 grammar.
 *   - false if the input is non-empty but malformed; caller should
 *     400 / refuse to save.
 *
 * @return string|null|false
 */
function _ietfBcp47Validate(string $raw)
{
    $tag = trim($raw);
    if ($tag === '') return null;
    if (strlen($tag) > 35) return false;
    /* Subtag breakdown:
       - language:  2-3 lowercase letters (ISO 639-1 / 639-3)
       - script:    optional 4-letter Title-case (ISO 15924)
       - region:    optional 2-letter UPPERCASE (ISO 3166-1) or 3-digit (UN M.49)
       - variant*:  zero or more — each is 5-8 alphanumeric, OR 4 chars
                    starting with a digit (the IANA grammar covers
                    ʻfonipaʼ, ʻvalenciaʼ, and digit-prefixed forms
                    like ʻ1996ʼ for German post-1996 orthography).
       Variants land last; extensions and private-use are still out
       of scope for the picker. */
    if (!preg_match(
        '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-([a-zA-Z0-9]{5,8}|[0-9][a-zA-Z0-9]{3}))*$/',
        $tag
    )) {
        return false;
    }
    return $tag;
}

/**
 * Sanitise an incoming `arrangement` payload from the Song Editor (#892).
 *
 * The editor sends an int[] of indices into `components[]` (with
 * repetition allowed — that is the entire reason this column exists,
 * so a refrain can play between every verse). Anything outside that
 * shape — null, empty array, non-integer entries, indices outside
 * the components range — collapses to NULL so the public render
 * falls back to plain stored SortOrder (the pre-#892 behaviour).
 *
 * Returning a JSON string (or null) so the caller can bind it
 * straight into mysqli without a second encode step.
 *
 * @param mixed $raw            The decoded `arrangement` field from the request body.
 * @param int   $componentCount Bound — every index must be 0 ≤ i < $componentCount.
 * @return string|null          JSON-encoded int array, or null when the input is
 *                              missing / empty / malformed / out-of-range.
 */
function _sanitiseArrangement($raw, int $componentCount): ?string
{
    if (!is_array($raw) || $componentCount <= 0) return null;
    $clean = [];
    foreach ($raw as $idx) {
        if (!is_int($idx) && !(is_string($idx) && ctype_digit($idx))) return null;
        $i = (int)$idx;
        if ($i < 0 || $i >= $componentCount) return null;
        $clean[] = $i;
    }
    if (empty($clean)) return null;
    return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
}

/* =========================================================================
 * BULK_IMPORT_ZIP constants (#664)
 *
 * Declared up here — ABOVE the switch — because PHP's top-level `const`
 * keyword defines the constant at the moment execution reaches the line,
 * not at compile time. The bulk-import helpers at the bottom of this
 * file reference these constants from inside the switch case, so the
 * declarations have to be evaluated before the switch runs or the
 * helpers blow up with "Undefined constant" the first time anyone
 * uploads a zip.
 * ========================================================================= */

/**
 * Folder name regex: matches "Christ in Song [CIS]" (the hymnal title
 * followed by a space, an opening square bracket, the abbreviation, and a
 * closing bracket). Anchored to the entire path-segment string so we
 * don't match accidental "[" inside titles.
 */
/* Folder name regex (#780): the original "<Title> [<ABBR>]" form
   is still accepted unchanged, plus an optional language suffix
   "_<LanguageName>-<ISO>" the scrapers append so a curator (and the
   bulk-import handler) can read the language straight off disk.
       e.g. "Himnario Adventista [HA]_Spanish-es"
            "Christ in Song [CIS]_English-en"
   The captured `lang` group is the BCP 47 primary subtag (2-3 lower-
   case letters or composite forms like sr-Latn). It's validated below
   before being stamped onto the songbook row. */
const _BULK_IMPORT_FOLDER_RE = '/^(?P<name>.+?)\s*\[(?P<abbr>[A-Za-z0-9_\-]+)\](?:_(?P<langname>[^-]+)-(?P<lang>[A-Za-z][A-Za-z0-9\-]{1,34}))?$/u';

/**
 * Filename regex: "001 (CIS) - Watchman Blow The Gospel Trumpet.txt".
 * Captures the (zero-padded) number, the abbreviation in parentheses,
 * and the title. Tolerant of variable padding widths (3- or 4-digit).
 */
const _BULK_IMPORT_FILE_RE = '/^(?P<num>\d{1,5})\s*\((?P<abbr>[A-Za-z0-9_\-]+)\)\s*-\s*(?P<title>.+)\.txt$/u';

/**
 * Maximum number of *real* zip entries to process in one request — the
 * count after we strip __MACOSX/, .DS_Store and bare directory entries
 * (see _bulkImport_processZip). The cap is a defensive guardrail
 * against a malformed/zip-bomb archive that's tiny on disk but expands
 * into a million entries; real auth + the 100 MB upload cap are the
 * actual access controls. 100,000 covers any realistic future bundle
 * (every published Adventist hymnal worldwide is well under 50,000)
 * while still tripping on a true zip bomb.
 */
const _BULK_IMPORT_MAX_ENTRIES = 100000;

/**
 * Decompression-bomb defenses (#682). A zip can declare a tiny
 * compressed size but a huge uncompressed payload — a 1 MB archive
 * advertising 1 TB of content is a classic DoS shape. We reject
 * anything where:
 *
 *   - any single entry's uncompressed size exceeds 5 MiB. A song
 *     text file is at most a few KB; 5 MiB is ~3 orders of magnitude
 *     above the realistic upper bound and still small enough that one
 *     read won't blow PHP's memory limit.
 *
 *   - the cumulative uncompressed size across the archive exceeds
 *     500 MiB. The biggest real bundle we know of (CIS, ~2,300 songs)
 *     is well under 30 MiB uncompressed; 500 MiB tolerates a 15× size
 *     jump while still tripping on a true bomb.
 *
 * Both caps run BEFORE any read — we use ZipArchive::statIndex to read
 * the central-directory size header, which is what `unzip -l` shows
 * and what an attacker would have to forge to bypass the check (the
 * server-side library would then catch the discrepancy on extract).
 */
const _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED = 5 * 1024 * 1024;       // 5 MiB
const _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED = 500 * 1024 * 1024;     // 500 MiB

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
     * /manage/song-link-suggestions admin page lists, scoped to a
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

            /* Child rows: DELETE then INSERT — simpler than diffing and
               the row counts per song are small (≈1–20 each). New credit
               tables from #497 are cleaned up here too. */
            foreach ([
                'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                'tblSongAdaptors', 'tblSongTranslators', 'tblSongArtists',
                'tblSongComponents',
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
                foreach ($song[$key] ?? [] as $raw) {
                    $entry = $normaliseCreditEntry($raw);
                    if ($entry === null) continue;
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
                $order++;
            }
            $insComp->close();

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
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save, save_song, save_song_tags, tag_search, credit_search, bulk_tag, list_revisions, restore_revision, get_translations, add_translation, remove_translation, get_song_links, add_song_link, remove_song_link, suggest_song_links, dismiss_song_link_suggestion, bulk_import_zip, bulk_import_status, song_media_list, song_media_upload, song_media_delete, song_media_reorder']);
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

/* The _BULK_IMPORT_FOLDER_RE / _BULK_IMPORT_FILE_RE / _BULK_IMPORT_MAX_ENTRIES
   constants are now declared above the switch (search this file for
   "BULK_IMPORT_ZIP constants"). Top-level `const` declarations are
   evaluated at runtime when the line is reached, not at compile time
   — leaving them down here meant they were undefined when the switch
   case called into the helper function above. */

/**
 * Section-marker → component-type map. Anything not in the map (e.g.
 * non-English refrain labels like "Coro", "Ciindululo", "Pripev") is
 * treated as a refrain section — refrain labels are language-specific
 * and the editor has a single 'refrain' / 'chorus' component type
 * regardless of the surface label.
 */
function _bulkImport_componentTypeFor(string $marker): string
{
    $m = strtolower(trim($marker));
    return [
        'verse'      => 'verse',
        'refrain'    => 'refrain',
        'chorus'     => 'chorus',
        'bridge'     => 'bridge',
        'pre-chorus' => 'pre-chorus',
        'prechorus'  => 'pre-chorus',
        'intro'      => 'intro',
        'outro'      => 'outro',
    ][$m] ?? 'refrain';
}

/**
 * Parse a single .txt file into the song-object shape that the
 * existing save_song path consumes. Returns null + a reason if the
 * body is too malformed to import (caller logs the reason in
 * errors[]).
 *
 * @param string $body       File contents (UTF-8)
 * @param string $abbrev     Songbook abbreviation parsed from the filename
 * @param string $songbook   Songbook display name parsed from the folder
 * @param int    $number     Song number parsed from the filename
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseTxt(string $body, string $abbrev, string $songbook, int $number): array
{
    /* Normalise line endings so a CRLF source from Windows reads the
       same as an LF source from macOS/Linux. */
    $body  = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    /* Find the title — first non-empty line. Anything before it
       (BOM left-overs, blank prefix) is skipped. */
    $title = '';
    $i     = 0;
    $n     = count($lines);
    while ($i < $n) {
        $l = trim($lines[$i]);
        if ($l !== '') {
            /* Strip leading UTF-8 BOM if it survived the upload. */
            $title = preg_replace('/^\xEF\xBB\xBF/', '', $l);
            $i++;
            break;
        }
        $i++;
    }
    if ($title === '') {
        return [null, 'no title line'];
    }

    /* Walk the remaining lines, alternating between section markers
       and lyric lines. A blank line ends a section's lyric block. */
    $components = [];
    $current    = null;
    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        if ($current === null) {
            /* Looking for the next section marker. Blank lines between
               sections are skipped. */
            if ($trim === '') { $i++; continue; }

            /* A bare integer is a verse with that number. Any other
               non-empty token is a labelled section (Refrain, Chorus,
               Bridge, or a non-English equivalent). */
            if (preg_match('/^\d{1,3}$/', $trim)) {
                $current = ['type' => 'verse', 'number' => (int)$trim, 'lines' => []];
            } else {
                $current = [
                    'type'   => _bulkImport_componentTypeFor($trim),
                    'number' => 0,
                    'lines'  => [],
                ];
            }
            $i++;
            continue;
        }

        /* Inside a section. Blank line → flush the section. Anything
           else → append to lyric lines (preserve internal whitespace
           but strip trailing spaces). */
        if ($trim === '') {
            if (!empty($current['lines'])) {
                $components[] = $current;
            }
            $current = null;
            $i++;
            continue;
        }
        $current['lines'][] = rtrim($line);
        $i++;
    }
    /* Final section if the file didn't end with a blank line. */
    if ($current !== null && !empty($current['lines'])) {
        $components[] = $current;
    }

    if (empty($components)) {
        return [null, 'no lyric components found'];
    }

    /* Canonical SongId format: <ABBREV>-<4-digit-padded-#>. Matches the
       /song/<id> route normalisation in router.js so URLs work straight
       away after import. */
    $songId = sprintf('%s-%04d', strtoupper($abbrev), $number);

    return [[
        'id'                 => $songId,
        'title'              => $title,
        'number'             => $number,
        'songbook'           => $abbrev,
        'songbookName'       => $songbook,
        'language'           => 'en',
        'ccli'               => '',
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => '',
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => [],
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => $components,
    ], null];
}

/**
 * Persist one parsed song — INSERT-ONLY. If a row with the same
 * SongId already exists, the existing row is left untouched and the
 * call returns 'skipped'. This is the explicit user requirement for
 * bulk import (#664): never overwrite curator-edited data with
 * scraped source data.
 *
 * @return array{0: string, 1: ?string}  ['create'|'skipped'|'fail', errorMessage|null]
 */
function _bulkImport_saveSong(\mysqli $db, array $song): array
{
    $songId       = (string)$song['id'];
    $title        = (string)$song['title'];
    /* Same NULL/''/'0'/0 normalisation as the editor save paths (#797).
       The TXT bulk-import parser usually produces a positive integer
       (the file's leading "## NN" marker drives the SongId format), but
       a future caller could legitimately pass a song with no songbook
       position. Fold any non-positive / empty value to NULL so the
       column carries the canonical sentinel. */
    $rawNumber    = $song['number'] ?? null;
    $number       = ($rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
        ? null
        : (int)$rawNumber;
    $songbookAbbr = (string)$song['songbook'];
    $songbookName = (string)$song['songbookName'];
    /* IETF BCP 47 sanitise (#681). Bulk-import builds the song dict
       in _bulkImport_parseTxt with 'language' => 'en' hard-coded
       today, but any future caller (a CSV bulk import, a different
       parser) can post a tag here. Soft-fallback to 'en' on a
       malformed value — the bulk import already counts skipped /
       failed entries and we'd rather not abort the whole archive
       on one bad row. */
    $validLang    = _ietfBcp47Validate((string)$song['language']);
    $language     = $validLang ?? 'en';
    $copyright    = '';
    $tuneName     = null;
    $ccli         = '';
    $iswc         = null;
    $verified     = 0;
    $lyricsPD     = 0;
    $musicPD      = 0;
    $hasAudio     = 0;
    $hasSheet     = 0;

    /* Build lyrics_text for the FULLTEXT index (matches save_song). */
    $lyricsLines = [];
    foreach ($song['components'] as $comp) {
        foreach ($comp['lines'] as $line) {
            $lyricsLines[] = $line;
        }
    }
    $lyricsText = implode("\n", $lyricsLines);

    try {
        /* Pre-flight existence check — INSERT-ONLY semantics. A row
           with this SongId already in tblSongs means a curator (or a
           prior import) has owned the data; we do not touch it.
           Cheap SELECT before opening a transaction so the no-op path
           doesn't churn the binlog. */
        $existsStmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
        $existsStmt->bind_param('s', $songId);
        $existsStmt->execute();
        $alreadyExists = $existsStmt->get_result()->fetch_row() !== null;
        $existsStmt->close();
        if ($alreadyExists) {
            return ['skipped', null];
        }

        /* #1051 — title-level dedupe. When the importer opted in, a
           normalised-title match within the same songbook counts as an
           existing song and is skipped — the SongId check above only
           catches the SAME numbering scheme, so a song imported under a
           different number would otherwise duplicate. Excludes the same
           SongId (already handled above). Centralised here so EVERY
           importer (OpenSong / VideoPsalm / OpenLP / …) inherits it. */
        if (_bulkImport_dedupeMode() === 'skip-title') {
            foreach (_bulkImport_findDuplicateCandidates($db, $songbookAbbr, $title) as $cand) {
                if ($cand['SongId'] !== $songId) {
                    return ['skipped', null];
                }
            }
        }

        $db->begin_transaction();

        /* Plain INSERT — no ON DUPLICATE KEY clause, because we
           verified above that the row doesn't exist. The unique
           index on SongId remains a hard safety net: if a concurrent
           writer inserts the same id between the check and this
           insert, we'll surface the duplicate-key error and the
           outer try/catch reports it as a per-row failure rather
           than half-succeeding. */
        $action = 'create';
        $previousData = null;

        /* #892 — schema-probe for tblSongs.ArrangementJson. The TXT /
           OpenSong parsers in this file don't produce arrangements
           today, so this is a no-op for current bulk imports — but
           future parsers (e.g. a richer XML format) may carry one,
           and the path needs to round-trip it through. */
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

        if ($hasArrangementCol) {
            $arrangementJson = _sanitiseArrangement(
                $song['arrangement'] ?? null,
                count($song['components'] ?? [])
            );
            $insert = $db->prepare(
                'INSERT INTO tblSongs
                    (SongId, Number, Title, SongbookAbbr, Language,
                     Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                     MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText,
                     ArrangementJson)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            /* SongbookName denorm column dropped (WS-E #1013 ph2). */
            $insert->bind_param(
                'sisssssssiiiiiss',
                $songId, $number, $title, $songbookAbbr,
                $language, $copyright, $tuneName, $ccli, $iswc,
                $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText,
                $arrangementJson
            );
        } else {
            $insert = $db->prepare(
                'INSERT INTO tblSongs
                    (SongId, Number, Title, SongbookAbbr, Language,
                     Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                     MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            /* SongbookName denorm column dropped (WS-E #1013 ph2). */
            $insert->bind_param(
                'sisssssssiiiiis',
                $songId, $number, $title, $songbookAbbr,
                $language, $copyright, $tuneName, $ccli, $iswc,
                $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
            );
        }
        $insert->execute();
        $insert->close();

        $insComp = $db->prepare(
            'INSERT INTO tblSongComponents
                (SongId, Type, Number, SortOrder, LinesJson)
             VALUES (?, ?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($song['components'] as $comp) {
            $type   = (string)($comp['type'] ?? 'verse');
            $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
            $lines  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
            $insComp->bind_param('ssiis', $songId, $type, $cNum, $order, $lines);
            $insComp->execute();
            $order++;
        }
        $insComp->close();

        /* Revision audit row (#400). Same shape as save_song writes. */
        try {
            $editor = function_exists('getCurrentUser') ? getCurrentUser() : null;
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
            $rev->close();
        } catch (\Throwable $_e) { /* revisions are best-effort */ }

        $db->commit();

        if (function_exists('logActivity')) {
            logActivity(
                $action === 'create' ? 'song.create' : 'song.edit',
                'song',
                $songId,
                ['title' => $title, 'songbook' => $songbookAbbr, 'source' => 'bulk_import_zip']
            );
        }

        return [$action, null];
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_e) {}
        return ['fail', $e->getMessage()];
    }
}

/**
 * Dedupe-on-import matcher (#1051) — shared by every importer.
 *
 * Today bulk-import dedupes only on SongId (<ABBR>-<NNNN>); a song imported
 * under a different numbering scheme slips past it and creates a true
 * duplicate. These two PURE helpers add matching on songbook + NORMALISED
 * title so importers can detect existing songs before insert and offer
 * skip / merge / replace. No DB writes; reused by the preview endpoint and
 * (next) the processZip main flow.
 */

/**
 * Normalise a title for fuzzy comparison: ASCII-fold accents, lowercase,
 * drop all punctuation, collapse whitespace. "O God, Our Help in Ages Past"
 * and "O God Our Help in Ages Past" normalise to the same string.
 */
function _bulkImport_normalizeTitle(string $title): string
{
    $t = trim($title);
    /* Fold accents to ASCII so "Niño" ~ "Nino" (best-effort; locale-dependent). */
    $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if (is_string($folded) && $folded !== '') {
        $t = $folded;
    }
    $t = mb_strtolower($t, 'UTF-8');
    /* Keep only letters / numbers / whitespace; drop apostrophes, hyphens,
       punctuation, smart quotes, etc. */
    $t = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $t);
    $t = (string)preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

/**
 * Find existing songs in the same songbook whose NORMALISED title matches
 * (exactly, or within `levThreshold` edits). Bound param on the songbook —
 * no string-interpolated SQL (per the repo SQL rules); fuzzy matching is
 * done in PHP via levenshtein() on the normalised strings.
 *
 * @return array<int,array{SongId:string,Title:string,Number:?int,matchType:string,distance:int}>
 */
function _bulkImport_findDuplicateCandidates(\mysqli $db, string $songbookAbbr, string $title, int $levThreshold = 2): array
{
    $norm = _bulkImport_normalizeTitle($title);
    if ($norm === '' || $songbookAbbr === '') {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT SongId, Title, Number FROM tblSongs WHERE SongbookAbbr = ? ORDER BY Number"
    );
    $stmt->bind_param('s', $songbookAbbr);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $matches = [];
    foreach ($rows as $r) {
        $cn = _bulkImport_normalizeTitle((string)$r['Title']);
        if ($cn === '') {
            continue;
        }
        if ($cn === $norm) {
            $matches[] = [
                'SongId'    => (string)$r['SongId'],
                'Title'     => (string)$r['Title'],
                'Number'    => $r['Number'] !== null ? (int)$r['Number'] : null,
                'matchType' => 'exact-normalized',
                'distance'  => 0,
            ];
            continue;
        }
        /* PHP levenshtein() caps inputs at 255 bytes — skip pathologically long titles. */
        if (strlen($cn) <= 255 && strlen($norm) <= 255) {
            $d = levenshtein($cn, $norm);
            if ($d <= $levThreshold) {
                $matches[] = [
                    'SongId'    => (string)$r['SongId'],
                    'Title'     => (string)$r['Title'],
                    'Number'    => $r['Number'] !== null ? (int)$r['Number'] : null,
                    'matchType' => 'fuzzy',
                    'distance'  => $d,
                ];
            }
        }
    }
    return $matches;
}

/**
 * Per-request dedupe mode for the current import (#1051). Set once by the
 * import action handler from the posted `dedupeMode`, read by
 * _bulkImport_saveSong() so every importer honours it without threading the
 * value through each parse loop. Values: 'off' (default — INSERT-only) |
 * 'skip-title' (skip a normalised-title match in the same songbook).
 * (Future: 'replace-title' / interactive merge.)
 */
function _bulkImport_dedupeMode(?string $set = null): string
{
    static $mode = 'off';
    if ($set !== null) {
        $mode = in_array($set, ['off', 'skip-title'], true) ? $set : 'off';
    }
    return $mode;
}

/**
 * INSERT-ONLY songbook helper. If a songbook with this Abbreviation
 * already exists, the row is left fully untouched — no rename, no
 * Name refresh — per the bulk-import contract: never overwrite
 * existing data (#664). New abbreviations get a fresh row with the
 * supplied Name; SongCount is recomputed at the end of the import
 * pass over the songs that successfully landed.
 *
 * Returns 'created' for a brand-new abbreviation, 'existing' if the
 * abbreviation was already in tblSongbooks.
 */
function _bulkImport_upsertSongbook(\mysqli $db, string $abbr, string $name, ?string $language = null): string
{
    $sel = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
    $sel->bind_param('s', $abbr);
    $sel->execute();
    $exists = $sel->get_result()->fetch_row() !== null;
    $sel->close();

    if ($exists) {
        return 'existing';
    }

    /* Auto-colour the new songbook so its badge is visually distinct
       from existing books on the home / songbooks tile grids (#677).
       The shared palette helper lives under /manage/includes/ — we
       require it lazily here so a deployment that hasn't run the
       migration to add the helper file (it's part of the same PR
       as this edit) doesn't 500 on existing imports. Best-effort —
       on a require failure we save with an empty Colour and let
       the theme default render. */
    $colour = '';
    $paletteFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook-palette.php';
    if (is_file($paletteFile)) {
        require_once $paletteFile;
        if (function_exists('pickAutoSongbookColour')) {
            $colour = pickAutoSongbookColour($db, $abbr);
        }
    }

    /* Language captured from the folder-name suffix (#780). Validated
       against the picker's BCP 47 grammar so a malformed suffix can't
       land bad data — falls through to NULL on rejection. */
    $langTag = null;
    if ($language !== null && $language !== '') {
        $langTrim = trim($language);
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i', $langTrim)) {
            $langTag = mb_substr($langTrim, 0, 35);
        }
    }

    if ($langTag !== null) {
        $ins = $db->prepare(
            'INSERT INTO tblSongbooks (Abbreviation, Name, Colour, SongCount, Language) VALUES (?, ?, ?, 0, ?)'
        );
        $ins->bind_param('ssss', $abbr, $name, $colour, $langTag);
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblSongbooks (Abbreviation, Name, Colour, SongCount) VALUES (?, ?, ?, 0)'
        );
        $ins->bind_param('sss', $abbr, $name, $colour);
    }
    $ins->execute();
    $ins->close();
    return 'created';
}

/**
 * Update tblBulkImportJobs row state for the async progress path
 * (#676). Called by the bulk_import_zip case to mark queued →
 * running → completed / failed transitions, and by
 * _bulkImport_processZip below to bump ProcessedEntries +
 * TotalEntries every ~50 rows so the polling endpoint can render
 * a percentage.
 *
 * Status is the new ENUM value; $extra carries column → value
 * pairs to set in the same UPDATE. NULL $jobId is a no-op so
 * the synchronous fallback path can call this without a job
 * record.
 *
 * Special-case: any value 'NOW()' is emitted as the SQL function
 * (not bound) so timestamp columns get the server clock.
 */
function _bulkImport_jobMark(\mysqli $db, ?int $jobId, string $status, array $extra = []): void
{
    if ($jobId === null || $jobId <= 0) return;

    /* Schema-probe the columns on tblBulkImportJobs once per request
       so newer columns (e.g. #906's PerSongbookJson) get dropped
       from the UPDATE on pre-migration deploys. Without the filter,
       a single unknown-column extra would 1054 the entire UPDATE
       and the rest of the legit columns wouldn't get updated either.
       Static-cached so the SELECT runs once even when this function
       is called dozens of times during a long import. */
    static $knownCols = null;
    if ($knownCols === null) {
        $knownCols = [];
        try {
            $r = $db->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblBulkImportJobs'"
            );
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $knownCols[$row['COLUMN_NAME']] = true;
                }
                $r->close();
            }
        } catch (\Throwable $_e) { /* best-effort; empty map = filter rejects everything */ }
    }

    /* Build the SET fragment — status always, plus any extras. */
    $setParts = ['Status = ?'];
    $bindTypes  = 's';
    $bindValues = [$status];
    foreach ($extra as $col => $val) {
        /* Hard whitelist of column names we accept here so a future
           caller can't accidentally splice user data into the SQL.
           tblBulkImportJobs columns only. */
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $col)) continue;
        /* Schema gate — skip columns not present on this deploy.
           Empty $knownCols (probe failed) falls through to the
           pre-#906 behaviour and tries every extra; the catch below
           still suppresses the resulting error. */
        if (!empty($knownCols) && !isset($knownCols[$col])) continue;
        if ($val === 'NOW()') {
            $setParts[] = "{$col} = NOW()";
            continue;
        }
        $setParts[] = "{$col} = ?";
        if (is_int($val)) {
            $bindTypes .= 'i';
        } else {
            $bindTypes .= 's';
            $val = (string)$val;
        }
        $bindValues[] = $val;
    }
    $sql = 'UPDATE tblBulkImportJobs SET ' . implode(', ', $setParts) . ' WHERE Id = ?';
    $bindTypes  .= 'i';
    $bindValues[] = $jobId;
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($bindTypes, ...$bindValues);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[_bulkImport_jobMark] ' . $e->getMessage());
    }
}

/**
 * Walk a ZIP archive and import every recognised hymnal folder + song
 * .txt file. Returns a JSON-serialisable summary.
 *
 * When $jobDb + $jobId are passed, the function updates
 * tblBulkImportJobs every PROGRESS_BATCH entries so the polling
 * endpoint can show the progress bar move. Synchronous callers
 * (the pre-#676 fallback path, future CLI tools) can omit both
 * args and the function behaves exactly as before.
 */
function _bulkImport_processZip(string $zipPath, ?\mysqli $jobDb = null, ?int $jobId = null): array
{
    /* How often to flush the progress counters back to the job row.
       Every 50 entries is ~1% of a typical 5000-song bundle — small
       enough to feel live, large enough that the per-update cost
       (one prepared UPDATE) stays under 0.5% of total runtime. */
    $PROGRESS_BATCH = 50;
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [
            'ok'    => false,
            'error' => 'Could not open uploaded file as a ZIP archive.',
        ];
    }

    $entryCount = $zip->numFiles;
    if ($entryCount > _BULK_IMPORT_MAX_ENTRIES) {
        $zip->close();
        return [
            'ok'    => false,
            'error' => sprintf(
                'ZIP has %d entries; the per-import cap is %d.',
                $entryCount, _BULK_IMPORT_MAX_ENTRIES
            ),
        ];
    }

    /* Decompression-bomb pre-flight (#682). Walk the central directory
       once and reject if any single entry — or the cumulative archive —
       declares an uncompressed size above the cap. statIndex() returns
       the size header, so we never read or decompress the entry just
       to find out it's too big. */
    $cumulativeUncompressed = 0;
    for ($k = 0; $k < $entryCount; $k++) {
        $stat = $zip->statIndex($k);
        if ($stat === false) continue;
        $size = (int)($stat['size'] ?? 0);
        if ($size > _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED) {
            $zip->close();
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Entry "%s" reports an uncompressed size of %d bytes; per-entry cap is %d bytes.',
                    (string)($stat['name'] ?? "(index $k)"),
                    $size,
                    _BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED
                ),
            ];
        }
        $cumulativeUncompressed += $size;
        if ($cumulativeUncompressed > _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED) {
            $zip->close();
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Cumulative uncompressed size exceeds the per-import cap of %d bytes.',
                    _BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED
                ),
            ];
        }
    }

    $db = getDbMysqli();

    /* Tally counters. Bulk import is INSERT-ONLY (#664) — existing
       songbook + song rows are skipped untouched, never overwritten,
       so the response reports skipped counts rather than updated. */
    $songbooksCreated         = [];
    $songbooksExisting        = [];
    $songsCreated             = 0;
    $songsSkippedExisting     = 0;
    $songsFailed              = 0;
    $errors                   = [];

    /* Skipped SongIds collector. Persisted as a JSON array on the job
       row so the "Download skipped SongIds" button on the completion
       notification can stream them as a CSV. Recording every skip
       costs ~10 bytes per entry — a 5,000-song re-import lands at
       ~50 KB of JSON, comfortably within the column. */
    $skippedSongIds           = [];

    /* Per-songbook breakdown (#906). Keyed by abbreviation; carries
       the songbook display name plus per-status counts so the import
       summary can render a "HA: 0 created, 527 skipped, 0 failed"
       row per book. Without this the curator sees only the aggregate
       totals and can't tell which songbook accounts for the skips. */
    $perSongbook              = [];   // abbr → ['name'=>str, 'created'=>int, 'skipped'=>int, 'failed'=>int, 'first_errors'=>[]]

    /* Per-failure activity-log rows (#908). Capped at 100 per import
       run to stay well under the per-request flood guard
       (IHYMNS_LOG_PER_REQUEST_CAP = 200). Overflow writes a single
       "and N more (truncated)" summary row at the end of the loop;
       the full failure detail still lives in tblBulkImportJobs.ErrorsJson
       for admins who need to drill into the omitted entries. */
    $loggedFailures           = 0;
    $loggedFailureCap         = 100;
    $logActivityAvailable     = function_exists('logActivity');

    $_perBookBump = static function (string $abbr, string $bookName, string $kind, ?string $errorMsg = null, ?string $entryName = null, ?int $songNum = null, ?string $phase = null) use (&$perSongbook, &$loggedFailures, $loggedFailureCap, $logActivityAvailable, $jobId): void {
        if ($abbr === '') $abbr = '_unknown';
        if (!isset($perSongbook[$abbr])) {
            $perSongbook[$abbr] = [
                'abbr'         => $abbr,
                'name'         => $bookName,
                'created'      => 0,
                'skipped'      => 0,
                'failed'       => 0,
                'first_errors' => [],
            ];
        }
        if ($bookName !== '' && $perSongbook[$abbr]['name'] === '') {
            $perSongbook[$abbr]['name'] = $bookName;
        }
        if (in_array($kind, ['created', 'skipped', 'failed'], true)) {
            $perSongbook[$abbr][$kind]++;
        }
        if ($kind === 'failed' && $errorMsg !== null
            && count($perSongbook[$abbr]['first_errors']) < 10
        ) {
            $perSongbook[$abbr]['first_errors'][] = [
                'entry' => $entryName ?? '',
                'error' => $errorMsg,
            ];
        }

        /* #908 — activity-log per-failure row. Only fires for the
           'failed' kind; under the cap; and when logActivity() is
           available (it's loaded by the editor bootstrap path but
           guarded here so a future call from a context that didn't
           load it doesn't 500). EntityId carries `<job_id>:<entry>`
           so the activity-log viewer can filter to "all failures
           from job N" via `EntityId LIKE '<job_id>:%'`. */
        if ($kind === 'failed'
            && $logActivityAvailable
            && $jobId !== null
            && $loggedFailures < $loggedFailureCap
        ) {
            $details = [
                'entry'         => $entryName ?? '',
                'error'         => $errorMsg ?? '',
                'songbook_abbr' => $abbr === '_unknown' ? null : $abbr,
                'phase'         => $phase ?? 'parse',
            ];
            if ($songNum !== null && $songNum > 0) {
                $details['song_number'] = $songNum;
            }
            try {
                logActivity(
                    'import.bulk_entry_failed',
                    'bulk_import_entry',
                    (string)$jobId . ':' . ($entryName ?? ''),
                    $details,
                    'failure'
                );
                $loggedFailures++;
            } catch (\Throwable $_e) {
                /* logActivity is documented as best-effort and never
                   throws — defensive catch in case a future change
                   surfaces an exception. Bulk import must keep going. */
            }
        }
    };

    /* Initial progress write — set TotalEntries to the post-filter
       count so the polling endpoint's percentage uses the right
       denominator. We don't know it precisely until we've walked
       once, so send the raw entry count as a ceiling. (#676)
       PhaseLabel (#907) gives the frontend a human-readable status
       above the progress bar even at 0% — the curator never sees a
       silent "0%" while the worker is doing real preflight work. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'TotalEntries'     => (int)$entryCount,
            'ProcessedEntries' => 0,
            'PhaseLabel'       => 'walking-zip',
        ]);
    }
    $progressFlushCounter = 0;

    /* #907 — phase transition: walking-zip → parsing-songs. The
       walk-and-classify above (entry count, format detection) was
       phase 'walking-zip'; we're about to start the per-entry parse +
       save loop, which is the bulk of the work and updates
       ProcessedEntries every $PROGRESS_BATCH iterations. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'PhaseLabel' => 'parsing-songs',
        ]);
    }

    /* Single pass over the archive: for each .txt entry, parse the
       enclosing folder name to learn (songbook name, abbrev), upsert
       the songbook on first encounter, then parse + save the song. */
    $songbookSeen      = [];   // abbrev → (created|existing) — caches the songbook upsert per archive
    $songbookCounters  = [];   // abbrev → next auto-assigned number (OpenSong fallback, #882)
    $songsParsedByKind = ['txt' => 0, 'opensong' => 0, 'videopsalm' => 0, 'openlyrics' => 0];

    for ($i = 0; $i < $entryCount; $i++) {
        /* Periodic progress flush every $PROGRESS_BATCH iterations so
           the polling endpoint shows a moving bar. Async path only —
           sync callers pay no per-iteration cost. (#676) */
        if ($jobDb !== null && $jobId !== null
            && $i > 0 && ($i % $PROGRESS_BATCH) === 0
        ) {
            _bulkImport_jobMark($jobDb, $jobId, 'running', [
                'ProcessedEntries'      => (int)$i,
                'SongsCreated'          => (int)$songsCreated,
                'SongsSkippedExisting'  => (int)$songsSkippedExisting,
                'SongsFailed'           => (int)$songsFailed,
            ]);
        }

        $name = $zip->getNameIndex($i);
        if ($name === false) continue;

        /* Reject path-traversal attempts before we even read. The zip
           tools we ship don't produce these, but a hand-crafted
           archive could. */
        if (strpos($name, '..') !== false || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name)) {
            $errors[] = ['entry' => $name, 'error' => 'unsafe path — skipped'];
            continue;
        }

        /* Skip directory entries, mac metadata, and dot-files. */
        if (str_ends_with($name, '/'))                continue;
        if (str_contains($name, '__MACOSX/'))         continue;
        if (str_contains($name, '/.'))                continue;

        /* Detect file format by extension. .txt → existing plain-text
           per-song parser; .xml / .opensong → OpenSong XML parser
           (#882); .json with a top-level "Songs" array → VideoPsalm
           songbook parser (#883). Anything else is silently skipped
           so a curator can drop a hymnal folder containing mixed
           assets (PDFs, cover art) without each non-song entry
           surfacing as a parse error. */
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = null;
        if ($ext === 'txt') {
            $kind = 'txt';
        } elseif ($ext === 'xml' || $ext === 'opensong') {
            $kind = 'opensong';
        } elseif ($ext === 'json') {
            $kind = 'videopsalm';
        } else {
            continue;
        }

        /* VideoPsalm files (#883) carry their own songbook metadata
           inside the JSON payload — songbook display name from the
           top-level "Text", abbreviation derived from the filename or
           from the title — so they don't need (and don't follow) the
           "<Title> [<ABBR>]/" folder convention the .txt / OpenSong
           paths require. Handle them inline and continue. */
        if ($kind === 'videopsalm') {
            $body = $zip->getFromIndex($i);
            if ($body === false) {
                $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
                $songsFailed++;
                continue;
            }
            [$vpBook, $vpSongs, $vpErr] = _bulkImport_parseVideoPsalmSongbook($body, $name);
            if ($vpBook === null) {
                /* Not a VideoPsalm document (or malformed JSON) — fall
                   through to the per-entry error log. The iHymns full-
                   corpus shape (lowercase songs[]) is intentionally not
                   accepted here; that path runs in-memory in the editor
                   client. */
                $errors[] = ['entry' => $name, 'error' => 'VideoPsalm parse failed: ' . ($vpErr ?? 'unknown')];
                $songsFailed++;
                continue;
            }
            $vpAbbr = (string)$vpBook['abbrev'];
            $vpName = (string)$vpBook['name'];
            if (!isset($songbookSeen[$vpAbbr])) {
                $state = _bulkImport_upsertSongbook($db, $vpAbbr, $vpName, $vpBook['language'] ?? null);
                $songbookSeen[$vpAbbr] = $state;
                if ($state === 'created') {
                    $songbooksCreated[] = $vpAbbr;
                } else {
                    $songbooksExisting[] = $vpAbbr;
                }
            }
            foreach ((array)($vpBook['parseErrors'] ?? []) as $pe) {
                $errors[] = [
                    'entry' => $name . ': ' . ($pe['entry'] ?? '?'),
                    'error' => (string)($pe['error'] ?? ''),
                ];
            }
            foreach ((array)$vpSongs as $song) {
                [$action, $saveErr] = _bulkImport_saveSong($db, $song);
                if ($action === 'create') {
                    $songsCreated++;
                    $songsParsedByKind['videopsalm']++;
                    $_perBookBump($vpAbbr, $vpName, 'created');
                } elseif ($action === 'skipped') {
                    $songsSkippedExisting++;
                    $_perBookBump($vpAbbr, $vpName, 'skipped');
                    if (isset($song['id']) && $song['id'] !== '') {
                        $skippedSongIds[] = (string)$song['id'];
                    }
                } else {
                    $errors[] = [
                        'entry' => $name . ': ' . ($song['id'] ?? '?'),
                        'error' => 'save failed: ' . $saveErr,
                    ];
                    $songsFailed++;
                    $_perBookBump($vpAbbr, $vpName, 'failed', 'save failed: ' . $saveErr, $name . ': ' . ($song['id'] ?? '?'), null, 'save');
                }
            }
            continue;
        }

        /* OpenLyrics / OpenLP (#1052): per-song .xml files that carry their
           own songbook metadata inside <songbooks><songbook name="…"
           entry="N"/></songbooks>, so — like VideoPsalm — they don't follow
           the "<Title> [<ABBR>]/" folder convention. Content-sniff the .xml
           (an OpenSong .xml has neither the namespace nor <verse name>) and,
           when it's OpenLyrics, handle it inline (songbook from the XML, or
           the file's own basename) and continue; otherwise fall through to
           the OpenSong path below. */
        if ($kind === 'opensong') {
            $peek = $zip->getFromIndex($i);
            if ($peek !== false && _bulkImport_looksLikeOpenLyrics($peek)) {
                [$olParsed, $olReason] = _bulkImport_parseOpenLyrics($peek);
                if ($olParsed === null) {
                    $errors[] = ['entry' => $name, 'error' => 'OpenLyrics parse failed: ' . $olReason];
                    $songsFailed++;
                    continue;
                }
                $olName = (string)$olParsed['songbookName'] !== ''
                    ? (string)$olParsed['songbookName']
                    : pathinfo($name, PATHINFO_FILENAME);
                $olAbbr = _bulkImport_videopsalmAbbrevFromHint($name, $olName);
                if (!isset($songbookSeen[$olAbbr])) {
                    $state = _bulkImport_upsertSongbook($db, $olAbbr, $olName, ($olParsed['language'] ?? '') ?: null);
                    $songbookSeen[$olAbbr] = $state;
                    if ($state === 'created') { $songbooksCreated[] = $olAbbr; }
                    else                       { $songbooksExisting[] = $olAbbr; }
                }
                if (!isset($songbookCounters[$olAbbr])) {
                    $songbookCounters[$olAbbr] = _bulkImport_nextSongNumberFor($db, $olAbbr);
                }
                $olNumber = (int)$olParsed['entry'] > 0 ? (int)$olParsed['entry'] : $songbookCounters[$olAbbr];
                $songbookCounters[$olAbbr] = max($songbookCounters[$olAbbr], $olNumber) + 1;
                $olSong = _bulkImport_assembleOpenLyricsSong($olParsed, $olAbbr, $olName, $olNumber);
                [$olAction, $olErr] = _bulkImport_saveSong($db, $olSong);
                if ($olAction === 'create') {
                    $songsCreated++;
                    $songsParsedByKind['openlyrics']++;
                    $_perBookBump($olAbbr, $olName, 'created');
                } elseif ($olAction === 'skipped') {
                    $songsSkippedExisting++;
                    $_perBookBump($olAbbr, $olName, 'skipped');
                    if (isset($olSong['id']) && $olSong['id'] !== '') {
                        $skippedSongIds[] = (string)$olSong['id'];
                    }
                } else {
                    $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $olErr];
                    $songsFailed++;
                    $_perBookBump($olAbbr, $olName, 'failed', 'save failed: ' . $olErr, $name, $olNumber ?: null, 'save');
                }
                continue;
            }
        }

        /* Pull out the folder + file segments. We expect exactly one
           level of nesting: "<Hymnal Name> [<ABBR>]/<filename>.txt". */
        $segments = explode('/', $name);
        if (count($segments) < 2) {
            $errors[] = ['entry' => $name, 'error' => 'file is not inside a hymnal folder'];
            continue;
        }
        $folder = $segments[count($segments) - 2];
        $file   = $segments[count($segments) - 1];

        if (!preg_match(_BULK_IMPORT_FOLDER_RE, $folder, $folderMatch)) {
            $errors[] = ['entry' => $name, 'error' => 'folder name does not match "<Title> [<ABBR>]"'];
            $_perBookBump('_unknown', $folder, 'failed', 'folder name does not match "<Title> [<ABBR>]"', $name, null, 'folder_regex');
            continue;
        }

        $abbr     = strtoupper($folderMatch['abbr']);
        $bookName = trim($folderMatch['name']);
        /* Optional `_<LangName>-<ISO>` suffix on the folder (#780). The
           ISO code becomes the songbook's Language at upsert time. */
        $folderLang = isset($folderMatch['lang']) ? trim((string)$folderMatch['lang']) : '';

        /* Filename → song-number extraction. The two formats use
           different conventions: */
        $songNum = 0;
        if ($kind === 'txt') {
            /* Strict: "<#> (<ABBR>) - <Title>.txt". The number is
               authoritative, the embedded abbreviation is cross-
               checked against the folder. */
            if (!preg_match(_BULK_IMPORT_FILE_RE, $file, $fileMatch)) {
                $errors[] = ['entry' => $name, 'error' => 'filename does not match "<#> (<ABBR>) - <Title>.txt"'];
                $_perBookBump($abbr, $bookName, 'failed', 'filename does not match "<#> (<ABBR>) - <Title>.txt"', $name, null, 'file_regex');
                continue;
            }
            $fileAbbr = strtoupper($fileMatch['abbr']);
            $songNum  = (int)$fileMatch['num'];
            if ($fileAbbr !== $abbr) {
                $errors[] = [
                    'entry' => $name,
                    'error' => "filename abbrev '$fileAbbr' does not match folder abbrev '$abbr'",
                ];
                $_perBookBump($abbr, $bookName, 'failed', "filename abbrev '$fileAbbr' does not match folder abbrev '$abbr'", $name, $songNum ?: null, 'file_regex');
                continue;
            }
        } else {
            /* OpenSong (#882): leading digits in the filename, if
               present, are a hint only. The XML's <hymn_number>
               element wins; the parser falls back to this hint, then
               to a per-songbook auto-increment. */
            if (preg_match('/^(\d{1,5})/', $file, $m)) {
                $songNum = (int)$m[1];
            }
        }

        /* Songbook upsert — once per abbreviation per import. */
        if (!isset($songbookSeen[$abbr])) {
            $state = _bulkImport_upsertSongbook($db, $abbr, $bookName, $folderLang ?: null);
            $songbookSeen[$abbr] = $state;
            if ($state === 'created') {
                $songbooksCreated[] = $abbr;
            } else {
                $songbooksExisting[] = $abbr;
            }
        }

        /* Read the file body. ZipArchive::getFromIndex returns false
           on read errors (corrupted entry, bad CRC). */
        $body = $zip->getFromIndex($i);
        if ($body === false) {
            $errors[] = ['entry' => $name, 'error' => 'could not read entry'];
            $songsFailed++;
            continue;
        }

        if ($kind === 'opensong') {
            /* OpenSong: derive the next per-songbook auto-increment
               so a parser fallback (no <hymn_number>, no leading
               digits in filename) still produces a unique SongId. */
            if (!isset($songbookCounters[$abbr])) {
                $songbookCounters[$abbr] = _bulkImport_nextSongNumberFor($db, $abbr);
            }
            [$song, $reason] = _bulkImport_parseOpenSong($body, $abbr, $bookName, $songNum, $songbookCounters[$abbr]);
            if ($song !== null) {
                /* Bump the auto-counter to whatever number landed so a
                   second OpenSong file in the same songbook doesn't
                   collide on SongId. */
                $songbookCounters[$abbr] = max($songbookCounters[$abbr], (int)$song['number']) + 1;
                $songsParsedByKind['opensong']++;
            }
        } else {
            [$song, $reason] = _bulkImport_parseTxt($body, $abbr, $bookName, $songNum);
            if ($song !== null) {
                $songsParsedByKind['txt']++;
            }
        }
        if ($song === null) {
            $errors[] = ['entry' => $name, 'error' => 'parse failed: ' . $reason];
            $songsFailed++;
            $_perBookBump($abbr, $bookName, 'failed', 'parse failed: ' . $reason, $name, $songNum ?: null, 'parse');
            continue;
        }

        [$action, $err] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $songsCreated++;
            $_perBookBump($abbr, $bookName, 'created');
        } elseif ($action === 'skipped') {
            /* Existing row — left untouched per the no-overwrite
               contract. Counted separately from failures so a curator
               can see at a glance how many imports were no-ops because
               the songs were already in the database. Record the SongId
               so the completion notification's "Download skipped SongIds"
               button can stream them as a CSV (audit which exact rows
               the import refused to overwrite). */
            $songsSkippedExisting++;
            $_perBookBump($abbr, $bookName, 'skipped');
            if (isset($song['id']) && $song['id'] !== '') {
                $skippedSongIds[] = (string)$song['id'];
            }
        } else {
            $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $err];
            $songsFailed++;
            $_perBookBump($abbr, $bookName, 'failed', 'save failed: ' . $err, $name, $songNum ?: null, 'save');
        }
    }

    $zip->close();

    /* #907 — phase transition: parsing-songs → flushing-songbooks.
       The per-entry loop is done; we're now refreshing SongCount on
       any newly-created songbooks. Brief phase but worth a label so
       the bar at 100% briefly shows "Finalising…" rather than
       lingering at "Parsing songs" with no progress. */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'PhaseLabel' => 'flushing-songbooks',
        ]);
    }

    /* Refresh SongCount only for songbooks we created in this run.
       Existing songbooks are off-limits per the no-overwrite contract,
       so we leave their SongCount alone — even though zero new songs
       landed inside them, the column was already correct from
       whoever populated those rows previously. */
    foreach ($songbooksCreated as $abbr) {
        $cnt = $db->prepare(
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    /* #908 — overflow row when more failures occurred than the
       per-failure activity-log cap allowed. The full failure list
       still lives in tblBulkImportJobs.ErrorsJson; this row points
       admins at the job for the omitted entries. */
    $omittedFailures = max(0, $songsFailed - $loggedFailures);
    if ($omittedFailures > 0 && $logActivityAvailable && $jobId !== null) {
        try {
            logActivity(
                'import.bulk_entries_truncated',
                'bulk_import_job',
                (string)$jobId,
                [
                    'logged_failures' => $loggedFailures,
                    'total_failures'  => $songsFailed,
                    'omitted'         => $omittedFailures,
                    'reason'          => 'per-failure activity-log cap reached; full list in tblBulkImportJobs.ErrorsJson',
                ],
                'failure'
            );
        } catch (\Throwable $_e) { /* best-effort */ }
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkippedExisting,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => $songsParsedByKind,
        'errors'                 => $errors,
        /* #906 — per-songbook breakdown so the import summary can
           render "HA: 0 created, 527 skipped, 0 failed" rows instead
           of just an aggregate. Re-keyed to a list (drop the abbr
           keys) so the JSON shape is a stable array, not an object
           whose keys depend on the input. */
        'per_songbook'           => array_values($perSongbook),
        /* Skipped SongIds — every SongId the worker left untouched
           because the row already existed in tblSongs. Persisted to
           tblBulkImportJobs.SkippedSongIdsJson and surfaced by the
           completion notification's "Download skipped SongIds" CSV. */
        'skipped_song_ids'       => $skippedSongIds,
        /* #908 — counts of activity-log per-failure rows actually
           written + how many were truncated. Lets the caller link
           "Activity log filter `EntityId LIKE '<jobId>:%'`" to the
           expected row count. */
        'logged_failures'        => $loggedFailures,
        'omitted_failure_rows'   => $omittedFailures,
    ];
}

/* ===========================================================================
 * OPENSONG PARSER (#882)
 *
 * OpenSong stores each song as a single XML document. Reference:
 *   https://opensong.org/documentation/importing/#songs
 *
 * Lyrics live in <lyrics> as plain text with bracketed section markers
 * ([V1], [C], [B], …), chord rows (lines starting with ".") and
 * comment rows (lines starting with ";"). The chord rows are dropped —
 * iHymns is a lyrics catalogue. Lyric lines conventionally begin with
 * a single space; that space is stripped to recover the real text.
 *
 * The output shape mirrors _bulkImport_parseTxt() so the same
 * _bulkImport_saveSong() write path consumes it without any branching.
 * =========================================================================== */

/**
 * Map OpenSong section letters to iHymns component types.
 *
 * Falls back to 'refrain' for anything we don't recognise (e.g. a
 * non-English label slipped into the section marker), matching the
 * fallback _bulkImport_componentTypeFor() uses for the TXT parser.
 */
function _bulkImport_openSongComponentTypeFor(string $letter): string
{
    return [
        'V' => 'verse',
        'C' => 'chorus',
        'B' => 'bridge',
        'P' => 'pre-chorus',
        'T' => 'outro',
        'E' => 'outro',
        'I' => 'intro',
    ][strtoupper($letter)] ?? 'refrain';
}

/**
 * Parse one OpenSong XML body into the song-object shape that
 * _bulkImport_saveSong() consumes.
 *
 * @param string $body         Raw XML (UTF-8; BOM tolerated).
 * @param string $abbrev       Songbook abbreviation from the folder.
 * @param string $songbook     Songbook display name from the folder.
 * @param int    $numberHint   Number derived from the filename's
 *                             leading digits (0 if none).
 * @param int    $autoNumber   Per-songbook auto-increment fallback when
 *                             neither the XML nor the filename supply
 *                             a number.
 * @return array{0: ?array, 1: ?string}  [songObject, errorReason]
 */
function _bulkImport_parseOpenSong(string $body, string $abbrev, string $songbook, int $numberHint, int $autoNumber): array
{
    /* Strip a UTF-8 BOM so SimpleXML doesn't choke. */
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

    /* Capture libxml errors locally so a malformed file produces a
       readable per-entry error rather than a global warning splatter. */
    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();
    /* LIBXML_NONET prevents the parser from following any external
       entity URI — DTD-based SSRF / billion-laughs hardening. */
    $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
        return [null, 'invalid XML' . ($err ? ': ' . trim($err->message) : '')];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if (strtolower($xml->getName()) !== 'song') {
        return [null, 'XML root is <' . $xml->getName() . '>, expected <song>'];
    }

    $title = trim((string)($xml->title ?? ''));
    if ($title === '') {
        return [null, 'no <title> element'];
    }

    /* Number resolution priority: <hymn_number> in XML → filename
       leading digits → per-songbook auto-increment. The auto-increment
       guarantees a unique SongId even for OpenSong corpora that don't
       carry numbers at all (which is most non-hymnal song libraries). */
    $hymnNumberRaw = trim((string)($xml->hymn_number ?? ''));
    $hymnNumber    = ($hymnNumberRaw !== '' && ctype_digit($hymnNumberRaw))
        ? (int)$hymnNumberRaw
        : 0;
    $number = $hymnNumber > 0
        ? $hymnNumber
        : ($numberHint > 0 ? $numberHint : $autoNumber);
    if ($number <= 0) {
        return [null, 'could not determine song number'];
    }

    $components = _bulkImport_parseOpenSongLyrics((string)($xml->lyrics ?? ''));
    if (empty($components)) {
        return [null, 'no lyric components found in <lyrics>'];
    }

    /* Author fields in OpenSong commonly stack multiple credits with
       slashes, ampersands or commas — split into individual writers
       to match the way the editor stores credit names. */
    $authors  = trim((string)($xml->author ?? ''));
    $writers  = [];
    if ($authors !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $authors) as $w) {
            $w = trim((string)$w);
            if ($w !== '') $writers[] = $w;
        }
    }

    $songId = sprintf('%s-%04d', strtoupper($abbrev), $number);

    return [[
        'id'                 => $songId,
        'title'              => $title,
        'number'             => $number,
        'songbook'           => strtoupper($abbrev),
        'songbookName'       => $songbook,
        'language'           => 'en',
        'ccli'               => trim((string)($xml->ccli ?? '')),
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => trim((string)($xml->copyright ?? '')),
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => $writers,
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => $components,
    ], null];
}

/**
 * Parse the body of an OpenSong <lyrics> block into the same
 * components[] shape produced by _bulkImport_parseTxt().
 */
function _bulkImport_parseOpenSongLyrics(string $lyrics): array
{
    $lyrics = str_replace(["\r\n", "\r"], "\n", $lyrics);
    $lines  = explode("\n", $lyrics);
    $components = [];
    $current    = null;

    foreach ($lines as $rawLine) {
        $trim = trim($rawLine);

        /* Comment row → drop. OpenSong reserves leading ";" for
           in-corpus notes the projector should never display. */
        if ($trim !== '' && $trim[0] === ';') {
            continue;
        }
        /* Chord row → drop. OpenSong puts chord names on a row that
           starts with "." so the projector can suppress them; iHymns
           is lyrics-only, so we strip them entirely rather than try
           to interleave them with the lyric line. */
        if ($rawLine !== '' && $rawLine[0] === '.') {
            continue;
        }

        /* Section marker, e.g. "[V1]", "[C]", "[B]". The optional
           trailing digits become the verse number; bare "[V]" implies
           the next sequential verse. */
        if (preg_match('/^\[([A-Za-z]+)(\d*)\]$/', $trim, $m)) {
            if ($current !== null && !empty($current['lines'])) {
                $components[] = $current;
            }
            $current = [
                'type'   => _bulkImport_openSongComponentTypeFor($m[1]),
                'number' => $m[2] !== '' ? (int)$m[2] : 0,
                'lines'  => [],
            ];
            continue;
        }

        /* Blank line ends the current section so consecutive section
           markers don't accidentally merge. */
        if ($trim === '') {
            if ($current !== null && !empty($current['lines'])) {
                $components[] = $current;
                $current = null;
            }
            continue;
        }

        if ($current === null) {
            /* Lyrics before any section marker — assume verse 1 so
               files without explicit markers (rare but legal) still
               produce a usable song object. */
            $current = ['type' => 'verse', 'number' => 1, 'lines' => []];
        }

        /* Strip a single leading space — OpenSong convention for lyric
           rows; preserving it would ladder the text in the editor. */
        $line = $rawLine;
        if (isset($line[0]) && $line[0] === ' ') {
            $line = substr($line, 1);
        }
        $current['lines'][] = rtrim($line);
    }

    if ($current !== null && !empty($current['lines'])) {
        $components[] = $current;
    }

    return $components;
}

/**
 * Return one greater than the maximum existing song number for a
 * songbook, used as the OpenSong auto-increment seed when neither
 * <hymn_number> nor the filename supply a number (#882).
 */
function _bulkImport_nextSongNumberFor(\mysqli $db, string $abbr): int
{
    try {
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(Number), 0) + 1 FROM tblSongs WHERE SongbookAbbr = ?'
        );
        $stmt->bind_param('s', $abbr);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int)($row[0] ?? 1);
    } catch (\Throwable $e) {
        error_log('[_bulkImport_nextSongNumberFor] ' . $e->getMessage());
        return 1;
    }
}

/* ===========================================================================
 * VIDEOPSALM PARSER (#883)
 *
 * VideoPsalm distributes whole songbooks as a single .json document with
 * top-level metadata and a `Songs[]` array — one drop = one whole hymnal.
 *
 *   https://myvideopsalm.weebly.com/songbooks-and-bibles.html
 *   https://myvideopsalm.weebly.com/free-songbook-collection.html
 *
 * Section tags inside `Verses[].Tag` use the same letter convention as
 * OpenSong (V1, V2, C, B, P, T, E, I) so the type map is shared in
 * spirit with the OpenSong parser.
 *
 * The parser is deliberately decoupled from the database — it returns a
 * songbook descriptor + parsed song-objects in the same shape that
 * _bulkImport_saveSong() consumes. The dispatcher (single-file handler
 * and the .json branch inside _bulkImport_processZip) is what actually
 * upserts rows.
 * =========================================================================== */

/**
 * Map a VideoPsalm verse Tag (e.g. "V1", "C", "B2") to an iHymns
 * component type. Trailing digits are stripped before the lookup; an
 * unknown letter falls through to 'refrain' to mirror the OpenSong
 * fallback behaviour.
 */
function _bulkImport_videopsalmComponentTypeFor(string $tag): string
{
    $letter = strtoupper((string)preg_replace('/\d+$/', '', trim($tag)));
    return [
        'V' => 'verse',
        'C' => 'chorus',
        'B' => 'bridge',
        'P' => 'pre-chorus',
        'T' => 'outro',
        'E' => 'outro',
        'I' => 'intro',
    ][$letter] ?? 'refrain';
}

/**
 * Derive a songbook abbreviation given a (possibly null) hint and the
 * resolved songbook display name.
 *
 * Hint precedence:
 *   1. "<Title> [<ABBR>].json" — the bracketed token wins.
 *   2. A bare alphanum filename (without extension) is taken verbatim.
 *   3. Otherwise: initials of the songbook name, uppercased; falls back
 *      to "VP" if the name yields no usable initials.
 */
function _bulkImport_videopsalmAbbrevFromHint(?string $abbrevHint, string $songbookName): string
{
    if ($abbrevHint !== null) {
        $hint = trim($abbrevHint);
        /* Strip any leading folder segments — we only care about the
           leaf filename when looking for the bracket pattern. */
        $hint = (string)preg_replace('#^.*/#', '', $hint);
        if (preg_match('/\[([A-Za-z0-9_\-]+)\]/', $hint, $m)) {
            return strtoupper($m[1]);
        }
        $hint = (string)preg_replace('/\.json$/i', '', $hint);
        $hint = trim($hint);
        if ($hint !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $hint)) {
            return strtoupper($hint);
        }
    }
    $words = preg_split('/\s+/u', trim($songbookName)) ?: [];
    $initials = '';
    foreach ($words as $w) {
        if ($w === '') continue;
        $initials .= mb_substr($w, 0, 1, 'UTF-8');
    }
    $initials = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $initials));
    return $initials !== '' ? $initials : 'VP';
}

/**
 * Parse a VideoPsalm songbook JSON document into:
 *   [songbookMeta, songs[], errReason]
 *
 * - songbookMeta is null only on a fatal parse error (invalid JSON or
 *   missing top-level Songs[]). On success it carries:
 *     - 'abbrev'      : derived from the filename hint or the title.
 *     - 'name'        : top-level "Text" field, or a generic fallback.
 *     - 'language'    : null (VideoPsalm does not encode IETF tags).
 *     - 'parseErrors' : per-song reasons for any songs that were
 *                       skipped (no title / no usable Verses / etc.) —
 *                       caller surfaces these via the import summary's
 *                       errors[] list.
 * - songs[] uses the exact shape that _bulkImport_saveSong() consumes,
 *   matching _bulkImport_parseTxt() / _bulkImport_parseOpenSong().
 *
 * @param string      $body         Raw JSON (UTF-8; BOM tolerated).
 * @param string|null $abbrevHint   Filename or "<Title> [<ABBR>]" hint.
 * @return array{0: ?array, 1: ?array, 2: ?string}
 */
function _bulkImport_parseVideoPsalmSongbook(string $body, ?string $abbrevHint = null): array
{
    /* Strip a UTF-8 BOM so json_decode doesn't trip. */
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $data = json_decode($body, true);
    if ($data === null) {
        return [null, null, 'invalid JSON: ' . json_last_error_msg()];
    }
    if (!is_array($data) || !isset($data['Songs']) || !is_array($data['Songs'])) {
        return [null, null, 'JSON has no top-level "Songs" array'];
    }

    $bookName = trim((string)($data['Text'] ?? ''));
    if ($bookName === '') {
        $bookName = 'VideoPsalm Songbook';
    }
    $abbr = _bulkImport_videopsalmAbbrevFromHint($abbrevHint, $bookName);

    $songs         = [];
    $perSongErrors = [];

    foreach ($data['Songs'] as $idx => $sRaw) {
        $entryTag = 'song #' . ((int)$idx + 1);
        if (!is_array($sRaw)) {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'song entry is not an object'];
            continue;
        }

        $title = trim((string)($sRaw['Text'] ?? ''));
        if ($title === '') {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'song has no Text/title'];
            continue;
        }
        $entryTag = 'song #' . ((int)$idx + 1) . ' "' . $title . '"';

        /* Number resolution: VideoPsalm "Number" is authoritative when
           present and positive; otherwise fall back to the array index
           (1-based) so each song still gets a unique SongId. */
        $rawNumber = $sRaw['Number'] ?? null;
        if (is_int($rawNumber) || (is_string($rawNumber) && ctype_digit(trim($rawNumber)))) {
            $number = (int)$rawNumber;
        } else {
            $number = 0;
        }
        if ($number <= 0) {
            $number = (int)$idx + 1;
        }

        /* Verses → components. Empty Verses arrays / verses with empty
           Text are skipped silently; if no usable verses survive, the
           whole song is dropped with a perSongErrors note. */
        $components = [];
        $verses     = is_array($sRaw['Verses'] ?? null) ? $sRaw['Verses'] : [];
        foreach ($verses as $v) {
            if (!is_array($v)) continue;
            $tag     = (string)($v['Tag'] ?? '');
            $vNum    = 0;
            if (preg_match('/(\d+)$/', $tag, $vm)) {
                $vNum = (int)$vm[1];
            }
            $type    = $tag !== '' ? _bulkImport_videopsalmComponentTypeFor($tag) : 'verse';
            $rawText = (string)($v['Text'] ?? '');
            if (trim($rawText) === '') continue;
            $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
            $lines   = array_map('rtrim', explode("\n", $rawText));
            /* Trim trailing empties so a verse that ends with a stray
               newline doesn't render as a blank-line tail in the editor. */
            while (!empty($lines) && end($lines) === '') {
                array_pop($lines);
            }
            if (empty($lines)) continue;
            $components[] = [
                'type'   => $type,
                'number' => $vNum,
                'lines'  => $lines,
            ];
        }
        if (empty($components)) {
            $perSongErrors[] = ['entry' => $entryTag, 'error' => 'no usable Verses[] entries'];
            continue;
        }

        /* Author splits: same delimiter set as the OpenSong parser
           (slash, ampersand, comma, semicolon) so a credit string like
           "Mary E. Byrne / Eleanor H. Hull" yields two writers. */
        $authorRaw = trim((string)($sRaw['Author'] ?? ''));
        $writers   = [];
        if ($authorRaw !== '') {
            foreach ((array)preg_split('/\s*[\/&,;]\s*/u', $authorRaw) as $w) {
                $w = trim((string)$w);
                if ($w !== '') $writers[] = $w;
            }
        }

        $songs[] = [
            'id'                 => sprintf('%s-%04d', $abbr, $number),
            'title'              => $title,
            'number'             => $number,
            'songbook'           => $abbr,
            'songbookName'       => $bookName,
            'language'           => 'en',
            'ccli'               => trim((string)($sRaw['CCLI'] ?? '')),
            'iswc'               => '',
            'tuneName'           => '',
            'copyright'          => trim((string)($sRaw['Copyright'] ?? '')),
            'verified'           => 0,
            'lyricsPublicDomain' => 0,
            'musicPublicDomain'  => 0,
            'hasAudio'           => 0,
            'hasSheetMusic'      => 0,
            'writers'            => $writers,
            'composers'          => [],
            'arrangers'          => [],
            'adaptors'           => [],
            'translators'        => [],
            'components'         => $components,
        ];
    }

    $songbook = [
        'abbrev'      => $abbr,
        'name'        => $bookName,
        'language'    => null,
        'parseErrors' => $perSongErrors,
    ];
    return [$songbook, $songs, null];
}

/**
 * Synchronous single-file VideoPsalm import — invoked from the
 * bulk_import_videopsalm dispatcher case. Returns a summary in the
 * same shape that _bulkImport_processZip() emits so the editor's
 * progress / toast handlers can stay format-agnostic.
 *
 * @param string      $body          Raw JSON document.
 * @param string|null $filenameHint  Original upload filename (used to
 *                                   derive the songbook abbreviation).
 * @return array
 */
function _bulkImport_processVideoPsalm(string $body, ?string $filenameHint = null): array
{
    [$songbook, $parsedSongs, $err] = _bulkImport_parseVideoPsalmSongbook($body, $filenameHint);
    if ($songbook === null) {
        return [
            'ok'                     => false,
            'error'                  => $err ?: 'VideoPsalm parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['videopsalm' => 0],
            'errors'                 => [],
        ];
    }

    $db = getDbMysqli();
    $abbr     = (string)$songbook['abbrev'];
    $bookName = (string)$songbook['name'];

    $errors = [];
    foreach ((array)($songbook['parseErrors'] ?? []) as $pe) {
        $errors[] = [
            'entry' => ($filenameHint ?? 'videopsalm') . ': ' . ($pe['entry'] ?? '?'),
            'error' => (string)($pe['error'] ?? ''),
        ];
    }

    $state = _bulkImport_upsertSongbook($db, $abbr, $bookName, $songbook['language'] ?? null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $songsCreated         = 0;
    $songsSkippedExisting = 0;
    $songsFailed          = 0;
    foreach ((array)$parsedSongs as $song) {
        [$action, $saveErr] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $songsCreated++;
        } elseif ($action === 'skipped') {
            $songsSkippedExisting++;
        } else {
            $errors[] = [
                'entry' => ($filenameHint ?? 'videopsalm') . ': ' . ($song['id'] ?? '?'),
                'error' => 'save failed: ' . $saveErr,
            ];
            $songsFailed++;
        }
    }

    /* Refresh SongCount only if we created the songbook in this run —
       same no-overwrite contract as the ZIP path (#664). */
    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkippedExisting,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['videopsalm' => $songsCreated + $songsSkippedExisting],
        'errors'                 => $errors,
    ];
}

/* ===========================================================================
 *  OpenLP / OpenLyrics import (#1052)
 * ---------------------------------------------------------------------------
 * OpenLP exports each song as a standalone OpenLyrics XML file
 * (http://openlyrics.info). Unlike the .SourceSongData / OpenSong ZIP
 * layout, OpenLyrics carries its OWN songbook metadata inside
 * <properties><songbooks><songbook name="…" entry="N"/> — so, like the
 * VideoPsalm path, these files do NOT follow the "<Title> [<ABBR>]/" folder
 * convention. The functions below parse one OpenLyrics document into the
 * shared song shape that _bulkImport_saveSong() consumes (inheriting the
 * #1051 title-dedupe), and a single-file processor mirrors
 * _bulkImport_processVideoPsalm() for the bulk_import_openlp action.
 *
 * A ZIP of OpenLyrics files is handled inline by _bulkImport_processZip()
 * (it content-sniffs .xml entries via _bulkImport_looksLikeOpenLyrics() and
 * routes OpenLyrics ones here instead of the OpenSong parser).
 * =========================================================================== */

/**
 * Cheap content sniff: is this XML body an OpenLyrics document?
 * Avoids a full parse — used to disambiguate OpenLyrics .xml from OpenSong
 * .xml inside the generic ZIP import loop.
 */
function _bulkImport_looksLikeOpenLyrics(string $body): bool
{
    $head = substr($body, 0, 4096);
    if (stripos($head, 'openlyrics.info') !== false) {
        return true;
    }
    /* Namespace may be absent on some exports; fall back to the structural
       fingerprint OpenSong never has: a <verse name="…"> with a <lines>
       child. */
    return (bool)preg_match('/<verse\b[^>]*\bname=/i', $body)
        && stripos($body, '<lines') !== false;
}

/**
 * Map an OpenLyrics verse `name` (v1, c, c2, b, p, e, i, o, …) to an
 * [iHymns component type, number] pair.
 */
function _bulkImport_openLyricsVerseType(string $name): array
{
    $letter = 'v';
    $num    = 0;
    if (preg_match('/^([A-Za-z]+)\s*(\d*)$/', trim($name), $m)) {
        $letter = strtolower($m[1]);
        $num    = $m[2] !== '' ? (int)$m[2] : 0;
    }
    $map = [
        'v' => 'verse', 'c' => 'chorus', 'b' => 'bridge', 'p' => 'pre-chorus',
        'r' => 'refrain', 'e' => 'outro', 'i' => 'intro', 'o' => 'outro',
        't' => 'outro',
    ];
    return [$map[$letter] ?? 'refrain', $num];
}

/**
 * Flatten one OpenLyrics <lines> element into an array of plain-text lines.
 * <br/> separates lines; <comment>/<chord>/<tag> and any other inline markup
 * is stripped (iHymns is lyrics-only).
 */
function _bulkImport_openLyricsLinesToArray(\SimpleXMLElement $linesNode): array
{
    $inner = (string)$linesNode->asXML();
    $inner = (string)preg_replace('#^<lines\b[^>]*>#i', '', $inner);
    $inner = (string)preg_replace('#</lines>\s*$#i', '', $inner);
    /* Self-closing or paired <br> → newline. */
    $inner = (string)preg_replace('#<br\s*/?>#i', "\n", $inner);
    /* Drop comments wholesale (including their text), then any remaining
       inline markup (chords, tags) leaving the bare lyric text. */
    $inner = (string)preg_replace('#<comment\b[^>]*>.*?</comment>#is', '', $inner);
    $inner = (string)preg_replace('#<[^>]+>#', '', $inner);
    $text  = html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $lines = array_map('rtrim', explode("\n", $text));
    /* Trim leading / trailing blank lines but preserve internal spacing. */
    while (!empty($lines) && trim($lines[0]) === '')          array_shift($lines);
    while (!empty($lines) && trim((string)end($lines)) === '') array_pop($lines);
    return $lines;
}

/**
 * Parse one OpenLyrics XML document into a neutral structure (no songbook
 * abbreviation / number / SongId resolution — the caller does that, since it
 * depends on the live DB auto-increment).
 *
 * @return array{0: ?array, 1: ?string}  [parsed, errorReason]
 *   parsed = { title, songbookName, entry, language, ccli, copyright,
 *              writers[], components[] }
 */
function _bulkImport_parseOpenLyrics(string $body): array
{
    $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

    /* Strip namespace declarations so plain SimpleXML traversal works
       regardless of the OpenLyrics namespace year (2009/…). We only read
       local element/attribute names, so dropping namespaces is safe. */
    $clean = (string)preg_replace('/\sxmlns(:\w+)?\s*=\s*("|\').*?\2/i', '', $body);

    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($clean, \SimpleXMLElement::class, LIBXML_NONET);
    if ($xml === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
        return [null, 'invalid XML' . ($err ? ': ' . trim($err->message) : '')];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if (strtolower($xml->getName()) !== 'song') {
        return [null, 'XML root is <' . $xml->getName() . '>, expected <song>'];
    }

    $props = $xml->properties ?? null;
    $title = $props ? trim((string)($props->titles->title ?? '')) : '';
    if ($title === '') {
        return [null, 'no <title> element'];
    }

    /* Authors — OpenLyrics lists one <author> per credit. */
    $writers = [];
    if ($props && isset($props->authors->author)) {
        foreach ($props->authors->author as $a) {
            $w = trim((string)$a);
            if ($w !== '') {
                $writers[] = $w;
            }
        }
    }

    $copyright = $props ? trim((string)($props->copyright ?? '')) : '';
    $ccli      = $props ? trim((string)($props->ccliNo ?? '')) : '';

    /* Songbook name + entry number (the first <songbook> wins). */
    $songbookName = '';
    $entry        = 0;
    if ($props && isset($props->songbooks->songbook)) {
        $sb           = $props->songbooks->songbook[0];
        $songbookName = trim((string)($sb['name'] ?? ''));
        $entryRaw     = trim((string)($sb['entry'] ?? ''));
        if ($entryRaw !== '' && ctype_digit($entryRaw)) {
            $entry = (int)$entryRaw;
        }
    }

    /* Song-level language: OpenLyrics may set xml:lang on <verse> or on a
       <title lang>; we read the first verse's lang as a best-effort hint. */
    $language = '';

    $components = [];
    if (isset($xml->lyrics->verse)) {
        foreach ($xml->lyrics->verse as $verse) {
            [$type, $num] = _bulkImport_openLyricsVerseType((string)($verse['name'] ?? 'v'));
            if ($language === '' && isset($verse['lang'])) {
                $language = trim((string)$verse['lang']);
            }
            $lines = [];
            foreach (($verse->lines ?? []) as $linesNode) {
                foreach (_bulkImport_openLyricsLinesToArray($linesNode) as $ln) {
                    $lines[] = $ln;
                }
            }
            if (!empty($lines)) {
                $components[] = ['type' => $type, 'number' => $num, 'lines' => $lines];
            }
        }
    }
    if (empty($components)) {
        return [null, 'no <verse>/<lines> content found'];
    }

    return [[
        'title'        => $title,
        'songbookName' => $songbookName,
        'entry'        => $entry,
        'language'     => $language,
        'ccli'         => $ccli,
        'copyright'    => $copyright,
        'writers'      => $writers,
        'components'   => $components,
    ], null];
}

/**
 * Assemble the parsed OpenLyrics structure into the song shape that
 * _bulkImport_saveSong() consumes (mirrors _bulkImport_parseOpenSong()).
 */
function _bulkImport_assembleOpenLyricsSong(array $parsed, string $abbr, string $songbookName, int $number): array
{
    return [
        'id'                 => sprintf('%s-%04d', strtoupper($abbr), $number),
        'title'              => (string)$parsed['title'],
        'number'             => $number,
        'songbook'           => strtoupper($abbr),
        'songbookName'       => $songbookName,
        'language'           => ($parsed['language'] ?? '') !== '' ? (string)$parsed['language'] : 'en',
        'ccli'               => (string)($parsed['ccli'] ?? ''),
        'iswc'               => '',
        'tuneName'           => '',
        'copyright'          => (string)($parsed['copyright'] ?? ''),
        'verified'           => 0,
        'lyricsPublicDomain' => 0,
        'musicPublicDomain'  => 0,
        'hasAudio'           => 0,
        'hasSheetMusic'      => 0,
        'writers'            => (array)($parsed['writers'] ?? []),
        'composers'          => [],
        'arrangers'          => [],
        'adaptors'           => [],
        'translators'        => [],
        'components'         => (array)($parsed['components'] ?? []),
    ];
}

/**
 * Synchronous single-file OpenLyrics import — invoked from the
 * bulk_import_openlp dispatcher case. Returns the same summary shape as
 * _bulkImport_processVideoPsalm() / _bulkImport_processZip().
 */
function _bulkImport_processOpenLp(string $body, ?string $filenameHint = null): array
{
    [$parsed, $reason] = _bulkImport_parseOpenLyrics($body);
    if ($parsed === null) {
        return [
            'ok'                     => false,
            'error'                  => $reason ?: 'OpenLyrics parse failed',
            'songbooks_created'      => [],
            'songbooks_existing'     => [],
            'songs_created'          => 0,
            'songs_skipped_existing' => 0,
            'songs_failed'           => 0,
            'parsed_by_format'       => ['openlyrics' => 0],
            'errors'                 => [],
        ];
    }

    $db       = getDbMysqli();
    $bookName = (string)$parsed['songbookName'] !== ''
        ? (string)$parsed['songbookName']
        : (pathinfo((string)$filenameHint, PATHINFO_FILENAME) ?: 'OpenLP Import');
    $abbr  = _bulkImport_videopsalmAbbrevFromHint($filenameHint, $bookName);

    $state             = _bulkImport_upsertSongbook($db, $abbr, $bookName, ($parsed['language'] ?? '') ?: null);
    $songbooksCreated  = ($state === 'created')  ? [$abbr] : [];
    $songbooksExisting = ($state === 'existing') ? [$abbr] : [];

    $number = (int)$parsed['entry'] > 0
        ? (int)$parsed['entry']
        : _bulkImport_nextSongNumberFor($db, $abbr);
    $song = _bulkImport_assembleOpenLyricsSong($parsed, $abbr, $bookName, $number);

    $songsCreated = 0;
    $songsSkipped = 0;
    $songsFailed  = 0;
    $errors       = [];
    [$action, $saveErr] = _bulkImport_saveSong($db, $song);
    if ($action === 'create') {
        $songsCreated = 1;
    } elseif ($action === 'skipped') {
        $songsSkipped = 1;
    } else {
        $songsFailed = 1;
        $errors[]    = [
            'entry' => ($filenameHint ?? 'openlp') . ': ' . ($song['id'] ?? '?'),
            'error' => 'save failed: ' . $saveErr,
        ];
    }

    if (!empty($songbooksCreated)) {
        $cnt = $db->prepare(
            'UPDATE tblSongbooks
                SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?)
              WHERE Abbreviation = ?'
        );
        $cnt->bind_param('ss', $abbr, $abbr);
        $cnt->execute();
        $cnt->close();
    }

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkipped,
        'songs_failed'           => $songsFailed,
        'parsed_by_format'       => ['openlyrics' => $songsCreated + $songsSkipped],
        'errors'                 => $errors,
    ];
}
