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

require_once __DIR__ . '/../includes/auth.php';

/* Verify authentication — return 401 JSON for AJAX requests */
if (!isAuthenticated()) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

/* =========================================================================
 * BOOTSTRAP — Load MySQL connection and SongData
 * ========================================================================= */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db_mysql.php';
require_once __DIR__ . '/../../includes/SongData.php';

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
            echo json_encode(['error' => 'Failed to save song data: ' . $e->getMessage()]);
        }
        break;

    /* -----------------------------------------------------------------
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save']);
        break;
}
