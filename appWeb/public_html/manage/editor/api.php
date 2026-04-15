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
if (!$currentUser || !hasRole($currentUser['Role'], 'editor')) {
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
            echo json_encode($fullData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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

            /* Clear existing data */
            $db->query("TRUNCATE TABLE tblSongComponents");
            $db->query("TRUNCATE TABLE tblSongComposers");
            $db->query("TRUNCATE TABLE tblSongWriters");
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

            /* Prepare song statements */
            $stmtSong = $db->prepare(
                "INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, SongbookName,
                 Language, Copyright, Ccli, Verified, LyricsPublicDomain,
                 MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtWriter = $db->prepare(
                "INSERT INTO tblSongWriters (SongId, Name) VALUES (?, ?)"
            );
            $stmtComposer = $db->prepare(
                "INSERT INTO tblSongComposers (SongId, Name) VALUES (?, ?)"
            );
            $stmtComponent = $db->prepare(
                "INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson)
                 VALUES (?, ?, ?, ?, ?)"
            );

            /* Insert songs */
            foreach ($data['songs'] as $song) {
                $songId       = $song['id'];
                $number       = (int)$song['number'];
                $title        = $song['title'];
                $songbookAbbr = $song['songbook'];
                $songbookName = $song['songbookName'] ?? '';
                $language     = $song['language'] ?? 'en';
                $copyright    = $song['copyright'] ?? '';
                $ccli         = $song['ccli'] ?? '';
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
                    'sissssssiiiiis',
                    $songId, $number, $title, $songbookAbbr, $songbookName,
                    $language, $copyright, $ccli, $verified, $lyricsPD,
                    $musicPD, $hasAudio, $hasSheet, $lyricsText
                );
                $stmtSong->execute();

                /* Writers */
                foreach ($song['writers'] ?? [] as $writer) {
                    $stmtWriter->bind_param('ss', $songId, $writer);
                    $stmtWriter->execute();
                }

                /* Composers */
                foreach ($song['composers'] ?? [] as $composer) {
                    $stmtComposer->bind_param('ss', $songId, $composer);
                    $stmtComposer->execute();
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
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save, get_translations, add_translation, remove_translation']);
        break;
}
