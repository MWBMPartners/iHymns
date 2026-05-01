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
 * ENDPOINTS:
 *   GET  api.php?action=load  — Read all song data from MySQL, returns JSON
 *   POST api.php?action=save  — Write song data to MySQL from POST body
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
const _BULK_IMPORT_FOLDER_RE = '/^(?P<name>.+?)\s*\[(?P<abbr>[A-Za-z0-9_\-]+)\]$/u';

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
     * LOAD — Read all song data from MySQL and return as JSON
     * ----------------------------------------------------------------- */
    case 'load':
        try {
            $songData = new SongData();
            $fullData = $songData->exportAsJson();

            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            /* No JSON_PRETTY_PRINT — the editor parses the response
               with JSON.parse, nothing reads it by eye, and the extra
               whitespace inflates the 3,600-song payload by ~25% on
               the wire. */
            echo json_encode($fullData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            http_response_code(500);
            error_log('[iHymns Editor] Failed to load song data: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to load song data from database.']);
        }
        break;

    /* -----------------------------------------------------------------
     * SAVE — Write song data to MySQL from the POST body
     * ----------------------------------------------------------------- */
    case 'save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST method required.']);
            break;
        }

        /* Read and validate the POST body */
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty request body.']);
            break;
        }

        /* Validate JSON structure */
        $data = json_decode($rawBody, true);
        if ($data === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON format.']);
            break;
        }

        /* Validate required top-level keys */
        if (!isset($data['meta']) || !isset($data['songbooks']) || !isset($data['songs'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid songs data structure. Required keys: meta, songbooks, songs.']);
            break;
        }

        /* Save to MySQL using transaction */
        try {
            $db = getDbMysqli();
            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            $db->begin_transaction();

            /* Clear existing data. New credit tables (#497) are TRUNCATEd
               alongside the originals so a bulk import yields a clean
               slate; the migration must have been run first or the query
               will fail with a clear "Table … doesn't exist" error that
               points the admin at /manage/setup-database. */
            $db->query("TRUNCATE TABLE tblSongComponents");
            $db->query("TRUNCATE TABLE tblSongComposers");
            $db->query("TRUNCATE TABLE tblSongWriters");
            $db->query("TRUNCATE TABLE tblSongArrangers");
            $db->query("TRUNCATE TABLE tblSongAdaptors");
            $db->query("TRUNCATE TABLE tblSongTranslators");
            $db->query("TRUNCATE TABLE tblSongs");
            $db->query("TRUNCATE TABLE tblSongbooks");

            $db->query("SET FOREIGN_KEY_CHECKS = 1");

            /* Insert songbooks */
            $stmtSongbook = $db->prepare(
                "INSERT INTO tblSongbooks (Abbreviation, Name, SongCount) VALUES (?, ?, ?)"
            );
            /* Build an IsOfficial lookup keyed by abbreviation so the
               per-song INSERT below can null out Number for any song
               whose songbook is unofficial (Misc and any custom
               collection). #392. The payload's `isOfficial` is sourced
               from SongData::getSongbooks() and is always boolean. */
            $officialByAbbr = [];
            foreach ($data['songbooks'] as $book) {
                $abbr  = $book['id'];
                $name  = $book['name'];
                $count = (int)($book['songCount'] ?? 0);
                $officialByAbbr[$abbr] = !empty($book['isOfficial']);
                $stmtSongbook->bind_param('ssi', $abbr, $name, $count);
                $stmtSongbook->execute();
            }
            $stmtSongbook->close();

            /* Prepare song statements. tblSongs INSERT now carries the
               #497 TuneName + Iswc columns; both are nullable and bind
               as the string types below (mysqli emits NULL when the
               bound PHP value is null). */
            $stmtSong = $db->prepare(
                "INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, SongbookName,
                 Language, Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                 MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtWriter     = $db->prepare("INSERT INTO tblSongWriters     (SongId, Name) VALUES (?, ?)");
            $stmtComposer   = $db->prepare("INSERT INTO tblSongComposers   (SongId, Name) VALUES (?, ?)");
            $stmtArranger   = $db->prepare("INSERT INTO tblSongArrangers   (SongId, Name) VALUES (?, ?)");
            $stmtAdaptor    = $db->prepare("INSERT INTO tblSongAdaptors    (SongId, Name) VALUES (?, ?)");
            $stmtTranslator = $db->prepare("INSERT INTO tblSongTranslators (SongId, Name) VALUES (?, ?)");
            /* Silently keep the credit-people registry in sync with every
               name that lands in the five song-credit tables (#545).
               INSERT IGNORE on the unique Name index — adding a name
               that's already in the registry is a no-op. The registry
               row carries no metadata at this point; the People page
               (/manage/credit-people) is where that gets enriched. */
            $stmtRegistry = $db->prepare("INSERT IGNORE INTO tblCreditPeople (Name) VALUES (?)");
            $stmtComponent  = $db->prepare(
                "INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson)
                 VALUES (?, ?, ?, ?, ?)"
            );

            /* Insert songs */
            foreach ($data['songs'] as $song) {
                $songId       = $song['id'];
                $songbookAbbr = $song['songbook'];

                /* Songs in non-official songbooks (Misc, custom
                   collections) persist Number as NULL — and so does
                   anything missing/zero/negative. The internal SongId
                   ties the record together. #392. */
                $rawNumber  = $song['number'] ?? null;
                $isOfficial = !empty($officialByAbbr[$songbookAbbr]);
                $number     = (!$isOfficial || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
                    ? null
                    : (int)$rawNumber;

                $title        = $song['title'];
                $songbookName = $song['songbookName'] ?? '';
                /* Legacy bulk-save path. Soft-fallback on a malformed
                   IETF tag rather than aborting the whole corpus save —
                   the action 'save' rewrites every song and refusing
                   one malformed entry would lose the curator's other
                   work. The save_song single-song path 400s instead,
                   which is the right behaviour there since the user is
                   editing one row. (#681) */
                $rawLang  = $song['language'] ?? 'en';
                $validLang = _ietfBcp47Validate((string)$rawLang);
                $language  = $validLang ?? 'en';
                $copyright    = $song['copyright'] ?? '';
                /* TuneName / Iswc: empty strings normalise to NULL so
                   the indexed TuneName column groups "unknown" rows
                   together and doesn't fragment on empty values. */
                $tuneRaw      = trim((string)($song['tuneName'] ?? ''));
                $tuneName     = $tuneRaw === '' ? null : $tuneRaw;
                $ccli         = $song['ccli'] ?? '';
                $iswcRaw      = trim((string)($song['iswc'] ?? ''));
                $iswc         = $iswcRaw === '' ? null : $iswcRaw;
                $verified     = (int)($song['verified'] ?? false);
                $lyricsPD     = (int)($song['lyricsPublicDomain'] ?? false);
                $musicPD      = (int)($song['musicPublicDomain'] ?? false);
                $hasAudio     = (int)($song['hasAudio'] ?? false);
                $hasSheet     = (int)($song['hasSheetMusic'] ?? false);

                /* Build lyrics_text */
                $lyricsLines = [];
                foreach ($song['components'] ?? [] as $comp) {
                    foreach ($comp['lines'] ?? [] as $line) {
                        $lyricsLines[] = $line;
                    }
                }
                $lyricsText = implode("\n", $lyricsLines);

                $stmtSong->bind_param(
                    'sissssssssiiiiis',
                    $songId, $number, $title, $songbookAbbr, $songbookName,
                    $language, $copyright, $tuneName, $ccli, $iswc,
                    $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
                );
                $stmtSong->execute();

                /* Credit collections — one table each. The registry sync
                   (INSERT IGNORE into tblCreditPeople) runs once per
                   credited name across all five collections, deduped
                   per song so we don't fire identical INSERTs five
                   times for someone credited in multiple roles on the
                   same song. (#545) */
                $regNames = [];
                foreach ($song['writers']     ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtWriter->bind_param('ss', $songId, $v);     $stmtWriter->execute();     $regNames[$v] = true; } }
                foreach ($song['composers']   ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtComposer->bind_param('ss', $songId, $v);   $stmtComposer->execute();   $regNames[$v] = true; } }
                foreach ($song['arrangers']   ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtArranger->bind_param('ss', $songId, $v);   $stmtArranger->execute();   $regNames[$v] = true; } }
                foreach ($song['adaptors']    ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtAdaptor->bind_param('ss', $songId, $v);    $stmtAdaptor->execute();    $regNames[$v] = true; } }
                foreach ($song['translators'] ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtTranslator->bind_param('ss', $songId, $v); $stmtTranslator->execute(); $regNames[$v] = true; } }
                foreach (array_keys($regNames) as $regName) {
                    $stmtRegistry->bind_param('s', $regName);
                    $stmtRegistry->execute();
                }

                /* Components */
                $sortOrder = 0;
                foreach ($song['components'] ?? [] as $comp) {
                    $compType   = $comp['type'];
                    $compNumber = (int)$comp['number'];
                    $linesJson  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
                    $stmtComponent->bind_param('ssiis', $songId, $compType, $compNumber, $sortOrder, $linesJson);
                    $stmtComponent->execute();
                    $sortOrder++;
                }
            }

            $stmtSong->close();
            $stmtWriter->close();
            $stmtComposer->close();
            $stmtArranger->close();
            $stmtAdaptor->close();
            $stmtTranslator->close();
            $stmtComponent->close();
            $stmtRegistry->close();

            /*
             * Import translation links from songs.json (#352).
             * Each song may have a "translations" array with {songId, language} entries.
             * Clear and re-import so translations stay in sync with the data file.
             */
            $db->query("DELETE FROM tblSongTranslations");
            $stmtTrans = $db->prepare(
                "INSERT IGNORE INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage)
                 VALUES (?, ?, ?)"
            );
            foreach ($data['songs'] as $song) {
                if (!empty($song['translations']) && is_array($song['translations'])) {
                    $srcId = $song['id'];
                    foreach ($song['translations'] as $tr) {
                        $tgtId = $tr['songId'] ?? '';
                        $lang  = $tr['language'] ?? '';
                        if ($tgtId !== '' && $lang !== '') {
                            $stmtTrans->bind_param('sss', $srcId, $tgtId, $lang);
                            $stmtTrans->execute();
                        }
                    }
                }
            }
            $stmtTrans->close();

            $db->commit();

            echo json_encode([
                'success'   => true,
                'songs'     => count($data['songs']),
                'songbooks' => count($data['songbooks']),
            ]);

        } catch (\Exception $e) {
            $db->rollback();
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            http_response_code(500);
            error_log('[iHymns Editor] Save failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to save song data. Check server logs for details.']);
        }
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
        $songbookAbbr = (string)($song['songbook'] ?? '');

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

            /* UPSERT tblSongs — now carries TuneName + Iswc (#497). */
            $upsert = $db->prepare(
                'INSERT INTO tblSongs
                    (SongId, Number, Title, SongbookAbbr, SongbookName, Language,
                     Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                     MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    Number = VALUES(Number), Title = VALUES(Title),
                    SongbookAbbr = VALUES(SongbookAbbr), SongbookName = VALUES(SongbookName),
                    Language = VALUES(Language), Copyright = VALUES(Copyright),
                    TuneName = VALUES(TuneName),
                    Ccli = VALUES(Ccli), Iswc = VALUES(Iswc),
                    Verified = VALUES(Verified),
                    LyricsPublicDomain = VALUES(LyricsPublicDomain),
                    MusicPublicDomain = VALUES(MusicPublicDomain),
                    HasAudio = VALUES(HasAudio), HasSheetMusic = VALUES(HasSheetMusic),
                    LyricsText = VALUES(LyricsText)'
            );
            $upsert->bind_param(
                'sissssssssiiiiis',
                $songId, $number, $title, $songbookAbbr, $songbookName,
                $language, $copyright, $tuneName, $ccli, $iswc,
                $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
            );
            $upsert->execute();
            $upsert->close();

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
            $regNames = [];
            foreach ($creditInserts as $key => $sql) {
                $stmt = $db->prepare($sql);
                foreach ($song[$key] ?? [] as $name) {
                    if (!is_string($name) || $name === '') continue;
                    $stmt->bind_param('ss', $songId, $name);
                    $stmt->execute();
                    $regNames[$name] = true;
                }
                $stmt->close();
            }
            /* Silently keep the credit-people registry in sync (#545).
               INSERT IGNORE on the unique Name index — names already in
               the registry are no-ops. The registry row carries no
               metadata at this point; the People page
               (/manage/credit-people) is where that gets enriched.
               Deduped above so a name credited in multiple roles on the
               same song fires once, not five times. */
            if (!empty($regNames)) {
                $stmtRegistry = $db->prepare('INSERT IGNORE INTO tblCreditPeople (Name) VALUES (?)');
                foreach (array_keys($regNames) as $regName) {
                    $stmtRegistry->bind_param('s', $regName);
                    $stmtRegistry->execute();
                }
                $stmtRegistry->close();
            }

            $insComp = $db->prepare(
                'INSERT INTO tblSongComponents
                    (SongId, Type, Number, SortOrder, LinesJson)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $order = 0;
            foreach ($song['components'] ?? [] as $comp) {
                $type   = (string)($comp['type'] ?? 'verse');
                $cNum   = isset($comp['number']) ? (int)$comp['number'] : 0;
                $lines  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
                $insComp->bind_param('ssiis', $songId, $type, $cNum, $order, $lines);
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

            /* Activity log (#535) — high-level "song.create" / "song.edit"
               row with a cross-link to the revisions row above so a
               timeline reader can drill into the full before/after diff
               without bloating Details here. */
            if (function_exists('logActivity')) {
                logActivity(
                    $action === 'create' ? 'song.create' : 'song.edit',
                    'song',
                    $songId,
                    [
                        'title'         => $title,
                        'songbook'      => $songbookAbbr,
                        'number'        => $number,
                        'verified'      => (bool)$verified,
                        'revision_id'   => $revisionId,
                    ]
                );
            }

            $db->commit();
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
        $normaliseTag = function ($name) {
            $trimmed = trim((string)$name);
            return $trimmed === '' ? null : mb_substr($trimmed, 0, 50);
        };
        $addTags = array_values(array_filter(array_map($normaliseTag, $addTags)));
        $remTags = array_values(array_filter(array_map($normaliseTag, $remTags)));

        if (empty($songIds) || (empty($addTags) && empty($remTags))) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid songIds or tag changes supplied.']);
            break;
        }

        $totalAdded = 0;
        $totalRemoved = 0;
        try {
            $db = getDbMysqli();
            $db->begin_transaction();

            /* Resolve / create tag rows for each ADD name. Keep a map of
               Name -> Id so we can insert mapping rows. */
            $addIds = [];
            foreach ($addTags as $name) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-');
                if ($slug === '') { continue; }
                $stmt = $db->prepare(
                    'INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?) ' .
                    'ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id)'
                );
                $stmt->bind_param('ss', $name, $slug);
                $stmt->execute();
                $addIds[$name] = (int)$db->insert_id;
                $stmt->close();
            }

            /* Insert mapping rows (ignore duplicates). */
            if (!empty($addIds) && !empty($songIds)) {
                $userId = (int)($currentUser['id'] ?? 0);
                $stmt = $db->prepare(
                    'INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)'
                );
                foreach ($songIds as $sid) {
                    foreach ($addIds as $tagId) {
                        $stmt->bind_param('sii', $sid, $tagId, $userId);
                        $stmt->execute();
                        $totalAdded += $db->affected_rows > 0 ? 1 : 0;
                    }
                }
                $stmt->close();
            }

            /* Remove mapping rows. Resolve tag Ids by Name first (only
               delete if the tag exists — names that don't match anything
               are silently ignored). */
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
                $number = isset($restorePayload['Number']) && $restorePayload['Number'] !== null
                    ? (int)$restorePayload['Number'] : null;
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
            }
            /* `usage` is a MySQL reserved word, so backtick it explicitly
               to avoid any edge-case parser drift between server versions
               or strict-mode configurations. (#593) */
            $sql = "SELECT Name, GROUP_CONCAT(DISTINCT kindLabel) AS kinds, SUM(cnt) AS `usage`
                    FROM (" . implode(' UNION ALL ', $unionParts) . ") u
                    GROUP BY Name
                    ORDER BY `usage` DESC, Name ASC
                    LIMIT ?";
            $types   .= 'i';
            $params[] = $limit;

            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $suggestions = [];
            while ($row = $res->fetch_assoc()) {
                $suggestions[] = [
                    'name'  => $row['Name'],
                    'usage' => (int)$row['usage'],
                    'kinds' => $row['kinds'] !== null ? explode(',', $row['kinds']) : [],
                ];
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
            _bulkImport_jobMark($db, $jobId, 'completed', [
                'CompletedAt'           => 'NOW()',
                'SongbooksCreatedJson'  => json_encode($summary['songbooks_created']  ?? [], JSON_UNESCAPED_UNICODE),
                'SongbooksExistingJson' => json_encode($summary['songbooks_existing'] ?? [], JSON_UNESCAPED_UNICODE),
                'SongsCreated'          => (int)($summary['songs_created'] ?? 0),
                'SongsSkippedExisting'  => (int)($summary['songs_skipped_existing'] ?? 0),
                'SongsFailed'           => (int)($summary['songs_failed'] ?? 0),
                'ErrorsJson'            => json_encode($summary['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'TempPath'              => '',
            ]);
            @unlink($persistPath);

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
            @unlink($persistPath);
        }
        return; /* worker is done; no further switch processing needed */

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
            $stmt = $db->prepare(
                'SELECT Id, UserId, Filename, SizeBytes, Status,
                        TotalEntries, ProcessedEntries,
                        SongbooksCreatedJson, SongbooksExistingJson,
                        SongsCreated, SongsSkippedExisting, SongsFailed,
                        ErrorsJson,
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
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save, save_song, save_song_tags, tag_search, credit_search, bulk_tag, list_revisions, restore_revision, get_translations, add_translation, remove_translation, bulk_import_zip, bulk_import_status']);
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
    $number       = isset($song['number']) ? (int)$song['number'] : null;
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
        $insert = $db->prepare(
            'INSERT INTO tblSongs
                (SongId, Number, Title, SongbookAbbr, SongbookName, Language,
                 Copyright, TuneName, Ccli, Iswc, Verified, LyricsPublicDomain,
                 MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'sissssssssiiiiis',
            $songId, $number, $title, $songbookAbbr, $songbookName,
            $language, $copyright, $tuneName, $ccli, $iswc,
            $verified, $lyricsPD, $musicPD, $hasAudio, $hasSheet, $lyricsText
        );
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
function _bulkImport_upsertSongbook(\mysqli $db, string $abbr, string $name): string
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

    $ins = $db->prepare('INSERT INTO tblSongbooks (Abbreviation, Name, Colour, SongCount) VALUES (?, ?, ?, 0)');
    $ins->bind_param('sss', $abbr, $name, $colour);
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
    /* Build the SET fragment — status always, plus any extras. */
    $setParts = ['Status = ?'];
    $bindTypes  = 's';
    $bindValues = [$status];
    foreach ($extra as $col => $val) {
        /* Hard whitelist of column names we accept here so a future
           caller can't accidentally splice user data into the SQL.
           tblBulkImportJobs columns only. */
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $col)) continue;
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

    /* Initial progress write — set TotalEntries to the post-filter
       count so the polling endpoint's percentage uses the right
       denominator. We don't know it precisely until we've walked
       once, so send the raw entry count as a ceiling. (#676) */
    if ($jobDb !== null && $jobId !== null) {
        _bulkImport_jobMark($jobDb, $jobId, 'running', [
            'TotalEntries'     => (int)$entryCount,
            'ProcessedEntries' => 0,
        ]);
    }
    $progressFlushCounter = 0;

    /* Single pass over the archive: for each .txt entry, parse the
       enclosing folder name to learn (songbook name, abbrev), upsert
       the songbook on first encounter, then parse + save the song. */
    $songbookSeen = [];   // abbrev → (created|existing) — caches the songbook upsert per archive

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

        /* Skip directory entries and non-.txt files (a future revision
           might also accept .json metadata; for now .txt is the
           contract). Zip mac metadata stores ('__MACOSX/', '.DS_Store')
           are silently ignored. */
        if (str_ends_with($name, '/'))                continue;
        if (!preg_match('/\.txt$/i', $name))          continue;
        if (str_contains($name, '__MACOSX/'))         continue;
        if (str_contains($name, '/.'))                continue;

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
            continue;
        }
        if (!preg_match(_BULK_IMPORT_FILE_RE, $file, $fileMatch)) {
            $errors[] = ['entry' => $name, 'error' => 'filename does not match "<#> (<ABBR>) - <Title>.txt"'];
            continue;
        }

        $abbr     = strtoupper($folderMatch['abbr']);
        $bookName = trim($folderMatch['name']);
        $fileAbbr = strtoupper($fileMatch['abbr']);
        $songNum  = (int)$fileMatch['num'];

        /* Cross-check: the abbreviation in the filename must match
           the folder's abbreviation. Otherwise we'd silently put a
           song from book A into book B. */
        if ($fileAbbr !== $abbr) {
            $errors[] = [
                'entry' => $name,
                'error' => "filename abbrev '$fileAbbr' does not match folder abbrev '$abbr'",
            ];
            continue;
        }

        /* Songbook upsert — once per abbreviation per import. */
        if (!isset($songbookSeen[$abbr])) {
            $state = _bulkImport_upsertSongbook($db, $abbr, $bookName);
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

        [$song, $reason] = _bulkImport_parseTxt($body, $abbr, $bookName, $songNum);
        if ($song === null) {
            $errors[] = ['entry' => $name, 'error' => 'parse failed: ' . $reason];
            $songsFailed++;
            continue;
        }

        [$action, $err] = _bulkImport_saveSong($db, $song);
        if ($action === 'create') {
            $songsCreated++;
        } elseif ($action === 'skipped') {
            /* Existing row — left untouched per the no-overwrite
               contract. Counted separately from failures so a curator
               can see at a glance how many imports were no-ops because
               the songs were already in the database. */
            $songsSkippedExisting++;
        } else {
            $errors[] = ['entry' => $name, 'error' => 'save failed: ' . $err];
            $songsFailed++;
        }
    }

    $zip->close();

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

    return [
        'ok'                     => true,
        'songbooks_created'      => $songbooksCreated,
        'songbooks_existing'     => $songbooksExisting,
        'songs_created'          => $songsCreated,
        'songs_skipped_existing' => $songsSkippedExisting,
        'songs_failed'           => $songsFailed,
        'errors'                 => $errors,
    ];
}
