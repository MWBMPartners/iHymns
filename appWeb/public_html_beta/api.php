<?php

declare(strict_types=1);

/**
 * iHymns — AJAX API Endpoint
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Handles all AJAX requests from the iHymns SPA frontend.
 * Returns either HTML fragments (for page content) or JSON data
 * (for search results, song data, etc.) depending on the action.
 *
 * ENDPOINTS (via GET parameter 'action'):
 *   page=home          — Returns the home page HTML fragment
 *   page=songbooks     — Returns the songbook grid HTML
 *   page=songbook&id=X — Returns song list for songbook X
 *   page=song&id=X     — Returns song lyrics for song ID X
 *   page=search        — Returns the search page HTML
 *   page=favorites     — Returns the favorites page HTML
 *   page=settings      — Returns the settings page HTML
 *   page=help          — Returns the help page HTML
 *   action=search&q=X  — Returns JSON search results for query X
 *   action=search_num  — Returns JSON number search results
 *   action=random      — Returns JSON random song data
 *   action=song_data   — Returns JSON song data for a specific ID
 *   action=songbooks   — Returns JSON songbook list
 *   action=songs       — Returns JSON song list (with optional songbook filter)
 *
 * SECURITY:
 *   - Input sanitisation on all parameters
 *   - JSON responses include proper Content-Type headers
 *   - CORS headers not needed (same-origin requests only)
 */

/* =========================================================================
 * BOOTSTRAP — Load configuration and dependencies
 * ========================================================================= */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/infoAppVer.php';
require_once __DIR__ . '/includes/SongData.php';

/* =========================================================================
 * REQUEST HANDLING
 * ========================================================================= */

/* Determine request type: page (HTML) or action (JSON) */
$page   = isset($_GET['page'])   ? trim($_GET['page'])   : null;
$action = isset($_GET['action']) ? trim($_GET['action']) : null;

/* Initialise the song data handler */
try {
    $songData = new SongData();
} catch (\RuntimeException $e) {
    /* If song data can't be loaded, return an error */
    if ($page !== null) {
        http_response_code(500);
        echo '<div class="alert alert-danger" role="alert">';
        echo '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
        echo 'Unable to load song data. Please try again later.';
        echo '</div>';
    } else {
        sendJson(['error' => 'Unable to load song data.'], 500);
    }
    exit;
}

/* =========================================================================
 * PAGE REQUESTS — Return HTML fragments for AJAX page loading
 * ========================================================================= */

if ($page !== null) {
    /* Set content type for HTML fragments */
    header('Content-Type: text/html; charset=UTF-8');

    /* Route to the appropriate page template */
    switch ($page) {
        case 'home':
            require __DIR__ . '/includes/pages/home.php';
            break;

        case 'songbooks':
            require __DIR__ . '/includes/pages/songbooks.php';
            break;

        case 'songbook':
            /* Requires songbook ID parameter */
            $bookId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($bookId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Songbook ID is required.</div>';
                break;
            }
            require __DIR__ . '/includes/pages/songbook.php';
            break;

        case 'song':
            /* Requires song ID parameter */
            $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($songId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Song ID is required.</div>';
                break;
            }
            require __DIR__ . '/includes/pages/song.php';
            break;

        case 'search':
            require __DIR__ . '/includes/pages/search.php';
            break;

        case 'favorites':
            require __DIR__ . '/includes/pages/favorites.php';
            break;

        case 'setlist':
            require __DIR__ . '/includes/pages/setlist.php';
            break;

        case 'settings':
            require __DIR__ . '/includes/pages/settings.php';
            break;

        case 'help':
            require __DIR__ . '/includes/pages/help.php';
            break;

        case 'terms':
            require __DIR__ . '/includes/pages/terms.php';
            break;

        case 'privacy':
            require __DIR__ . '/includes/pages/privacy.php';
            break;

        default:
            http_response_code(404);
            require __DIR__ . '/includes/pages/not-found.php';
            break;
    }

    exit;
}

/* =========================================================================
 * ACTION REQUESTS — Return JSON data for dynamic operations
 * ========================================================================= */

if ($action !== null) {
    switch ($action) {

        /* -----------------------------------------------------------------
         * Text search — fuzzy/substring search across all fields
         * Parameters: q (query string), songbook (optional filter)
         * ----------------------------------------------------------------- */
        case 'search':
            $query    = isset($_GET['q']) ? trim($_GET['q']) : '';
            $bookId   = isset($_GET['songbook']) ? trim($_GET['songbook']) : null;
            $limit    = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            if ($query === '') {
                sendJson(['results' => [], 'total' => 0]);
                break;
            }

            $results = $songData->searchSongs($query, $bookId, $limit);
            sendJson([
                'results' => array_map('songToSummary', $results),
                'total'   => count($results),
                'query'   => $query,
            ]);
            break;

        /* -----------------------------------------------------------------
         * Number search — search by song number within a songbook
         * Parameters: songbook (required), number (required)
         * ----------------------------------------------------------------- */
        case 'search_num':
            $bookId = isset($_GET['songbook']) ? trim($_GET['songbook']) : '';
            $number = isset($_GET['number'])   ? trim($_GET['number'])   : '';

            if ($bookId === '' || $number === '') {
                sendJson(['results' => [], 'total' => 0]);
                break;
            }

            $results = $songData->searchByNumber($bookId, $number);
            sendJson([
                'results' => array_map('songToSummary', $results),
                'total'   => count($results),
            ]);
            break;

        /* -----------------------------------------------------------------
         * Random song — get a random song (optionally from a songbook)
         * Parameters: songbook (optional)
         * ----------------------------------------------------------------- */
        case 'random':
            $bookId = isset($_GET['songbook']) ? trim($_GET['songbook']) : null;
            if ($bookId === '') {
                $bookId = null;
            }

            $song = $songData->getRandomSong($bookId);
            if ($song === null) {
                sendJson(['error' => 'No songs available.'], 404);
            } else {
                sendJson(['song' => $song]);
            }
            break;

        /* -----------------------------------------------------------------
         * Get full song data by ID
         * Parameters: id (required)
         * ----------------------------------------------------------------- */
        case 'song_data':
            $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($songId === '') {
                sendJson(['error' => 'Song ID is required.'], 400);
                break;
            }

            $song = $songData->getSongById($songId);
            if ($song === null) {
                sendJson(['error' => 'Song not found.'], 404);
            } else {
                sendJson(['song' => $song]);
            }
            break;

        /* -----------------------------------------------------------------
         * Get all songbooks
         * ----------------------------------------------------------------- */
        case 'songbooks':
            sendJson(['songbooks' => $songData->getSongbooks()]);
            break;

        /* -----------------------------------------------------------------
         * Get songs list (with optional songbook filter)
         * Parameters: songbook (optional)
         * ----------------------------------------------------------------- */
        case 'songs':
            $bookId = isset($_GET['songbook']) ? trim($_GET['songbook']) : null;
            if ($bookId === '') {
                $bookId = null;
            }

            $songs = $songData->getSongs($bookId);
            sendJson([
                'songs' => array_map('songToSummary', $songs),
                'total' => count($songs),
            ]);
            break;

        /* -----------------------------------------------------------------
         * Get collection statistics
         * ----------------------------------------------------------------- */
        case 'stats':
            sendJson($songData->getStats());
            break;

        /* -----------------------------------------------------------------
         * Unknown action
         * ----------------------------------------------------------------- */
        default:
            sendJson(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
            break;
    }

    exit;
}

/* =========================================================================
 * NO VALID REQUEST — Return 400
 * ========================================================================= */

sendJson(['error' => 'Missing required parameter: page or action'], 400);

/* =========================================================================
 * HELPER FUNCTIONS
 * ========================================================================= */

/**
 * Send a JSON response with appropriate headers.
 *
 * @param array $data       Data to encode as JSON
 * @param int   $statusCode HTTP status code (default: 200)
 */
function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Convert a full song object to a lightweight summary for list views.
 * Excludes the full lyrics/components to reduce payload size.
 *
 * @param array $song Full song object
 * @return array Summary with id, number, title, songbook, etc.
 */
function songToSummary(array $song): array
{
    return [
        'id'           => $song['id'] ?? '',
        'number'       => $song['number'] ?? 0,
        'title'        => $song['title'] ?? '',
        'songbook'     => $song['songbook'] ?? '',
        'songbookName' => $song['songbookName'] ?? '',
        'writers'      => $song['writers'] ?? [],
        'hasAudio'     => $song['hasAudio'] ?? false,
        'hasSheetMusic' => $song['hasSheetMusic'] ?? false,
    ];
}
