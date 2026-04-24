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
            foreach ($data['songbooks'] as $book) {
                $abbr  = $book['id'];
                $name  = $book['name'];
                $count = (int)($book['songCount'] ?? 0);
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
            $stmtComponent  = $db->prepare(
                "INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson)
                 VALUES (?, ?, ?, ?, ?)"
            );

            /* Insert songs */
            foreach ($data['songs'] as $song) {
                $songId       = $song['id'];
                $songbookAbbr = $song['songbook'];

                /* Misc songs (and anything without a meaningful number)
                   persist Number as NULL (#392). */
                $rawNumber = $song['number'] ?? null;
                $number    = ($songbookAbbr === 'Misc' || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
                    ? null
                    : (int)$rawNumber;

                $title        = $song['title'];
                $songbookName = $song['songbookName'] ?? '';
                $language     = $song['language'] ?? 'en';
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

                /* Credit collections — one table each. */
                foreach ($song['writers']     ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtWriter->bind_param('ss', $songId, $v);     $stmtWriter->execute(); } }
                foreach ($song['composers']   ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtComposer->bind_param('ss', $songId, $v);   $stmtComposer->execute(); } }
                foreach ($song['arrangers']   ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtArranger->bind_param('ss', $songId, $v);   $stmtArranger->execute(); } }
                foreach ($song['adaptors']    ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtAdaptor->bind_param('ss', $songId, $v);    $stmtAdaptor->execute(); } }
                foreach ($song['translators'] ?? [] as $v) { if (is_string($v) && $v !== '') { $stmtTranslator->bind_param('ss', $songId, $v); $stmtTranslator->execute(); } }

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
            $db = getDb();
            $stmt = $db->prepare(
                'SELECT t.Id AS id, t.TranslatedSongId AS songId,
                        t.TargetLanguage AS language, t.Translator AS translator,
                        t.Verified AS verified, s.Title AS title, s.Number AS number
                 FROM tblSongTranslations t
                 JOIN tblSongs s ON s.SongId = t.TranslatedSongId
                 WHERE t.SourceSongId = ?
                 ORDER BY t.TargetLanguage ASC'
            );
            $stmt->execute([$songId]);
            $translations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($translations as &$tr) {
                $tr['id'] = (int)$tr['id'];
                $tr['verified'] = (bool)$tr['verified'];
                $tr['number'] = (int)$tr['number'];
            }
            unset($tr);
            echo json_encode(['translations' => $translations]);
        } catch (Exception $e) {
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
            $db = getDb();
            $stmt = $db->prepare(
                'INSERT INTO tblSongTranslations (SourceSongId, TranslatedSongId, TargetLanguage, Translator)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE TranslatedSongId = VALUES(TranslatedSongId),
                                         Translator = VALUES(Translator)'
            );
            $stmt->execute([$srcId, $tgtId, $lang, $translator]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
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
            $db = getDb();
            $stmt = $db->prepare('DELETE FROM tblSongTranslations WHERE Id = ?');
            $stmt->execute([$removeId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Exception $e) {
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

        /* Misc (or any missing value) persists Number as NULL (#392). */
        $rawNumber = $song['number'] ?? null;
        $number    = ($songbookAbbr === 'Misc' || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
            ? null
            : (int)$rawNumber;

        $title        = (string)$song['title'];
        $songbookName = (string)($song['songbookName'] ?? '');
        $language     = (string)($song['language']    ?? 'en');
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
                'tblSongAdaptors', 'tblSongTranslators', 'tblSongComponents',
            ] as $childTable) {
                $del = $db->prepare("DELETE FROM {$childTable} WHERE SongId = ?");
                $del->bind_param('s', $songId);
                $del->execute();
                $del->close();
            }

            /* Insert credit collections. Each collection is a separate
               prepared statement to keep the field name explicit at each
               call site. */
            $creditInserts = [
                'writers'     => 'INSERT INTO tblSongWriters     (SongId, Name) VALUES (?, ?)',
                'composers'   => 'INSERT INTO tblSongComposers   (SongId, Name) VALUES (?, ?)',
                'arrangers'   => 'INSERT INTO tblSongArrangers   (SongId, Name) VALUES (?, ?)',
                'adaptors'    => 'INSERT INTO tblSongAdaptors    (SongId, Name) VALUES (?, ?)',
                'translators' => 'INSERT INTO tblSongTranslators (SongId, Name) VALUES (?, ?)',
            ];
            foreach ($creditInserts as $key => $sql) {
                $stmt = $db->prepare($sql);
                foreach ($song[$key] ?? [] as $name) {
                    if (!is_string($name) || $name === '') continue;
                    $stmt->bind_param('ss', $songId, $name);
                    $stmt->execute();
                }
                $stmt->close();
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
                $rev->close();
            } catch (\Throwable $_e) { /* revisions are best-effort */ }

            $db->commit();
            echo json_encode(['ok' => true, 'songId' => $songId, 'action' => $action]);
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (\Throwable $_) {}
            }
            http_response_code(500);
            error_log('[editor save_song] ' . $e->getMessage());
            /* Do not expose DB internals to the client — the details are
               in the server error log for admins to inspect. */
            echo json_encode(['error' => 'Failed to save song. Check server logs for details.']);
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
            $sql = "SELECT Name, GROUP_CONCAT(DISTINCT kindLabel) AS kinds, SUM(cnt) AS usage
                    FROM (" . implode(' UNION ALL ', $unionParts) . ") u
                    GROUP BY Name
                    ORDER BY usage DESC, Name ASC
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
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save, save_song, save_song_tags, tag_search, credit_search, bulk_tag, list_revisions, restore_revision, get_translations, add_translation, remove_translation']);
        break;
}
