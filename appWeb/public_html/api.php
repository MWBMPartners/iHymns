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
 *   action=songs_json  — Serves the full songs.json file (for client-side search)
 *   action=setlist_share (POST) — Create or update a shared setlist
 *   action=setlist_get&id=X    — Retrieve a shared setlist by short ID
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

        case 'setlist-shared':
            require __DIR__ . '/includes/pages/setlist-shared.php';
            break;

        case 'settings':
            require __DIR__ . '/includes/pages/settings.php';
            break;

        case 'stats':
            require __DIR__ . '/includes/pages/stats.php';
            break;

        case 'writer':
            /* Requires writer slug parameter */
            $writerId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($writerId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Writer ID is required.</div>';
                break;
            }
            require __DIR__ . '/includes/pages/writer.php';
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
         * Serve the full songs.json file (#154)
         *
         * Streams the canonical songs.json from the private data_share
         * directory. Used by client-side Fuse.js search and service
         * worker caching. Includes ETag / Last-Modified for efficient
         * browser caching.
         * ----------------------------------------------------------------- */
        case 'songs_json':
            $songsFile = APP_DATA_FILE;
            if (!file_exists($songsFile)) {
                sendJson(['error' => 'Song data file not found.'], 500);
                break;
            }

            /* ETag and Last-Modified for conditional requests */
            $lastModified = filemtime($songsFile);
            $etag = '"' . md5_file($songsFile) . '"';

            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: public, max-age=3600, must-revalidate');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            header('ETag: ' . $etag);

            /* Return 304 Not Modified if client cache is still valid */
            $ifNoneMatch   = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            $ifModifiedStr = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
            if ($ifNoneMatch === $etag
                || ($ifModifiedStr !== '' && strtotime($ifModifiedStr) >= $lastModified)) {
                http_response_code(304);
                exit;
            }

            /* Stream the file directly (no JSON decode/encode overhead) */
            readfile($songsFile);
            break;

        /* -----------------------------------------------------------------
         * Create or update a shared setlist (#155)
         *
         * POST body (JSON):
         *   { "name": "...", "songs": ["MP-1342", ...], "owner": "uuid" }
         * Optional: { "id": "abc123" } to update an existing share
         *
         * Returns: { "id": "abc123", "url": "/setlist/shared/abc123" }
         * ----------------------------------------------------------------- */
        case 'setlist_share':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            /* Parse JSON request body */
            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            if (!is_array($body) || empty($body['name']) || !is_array($body['songs'] ?? null) || empty($body['owner'])) {
                sendJson(['error' => 'Invalid request. Required: name, songs (array), owner.'], 400);
                break;
            }

            /* Sanitise inputs */
            $setlistName  = mb_substr(trim($body['name']), 0, 200);
            $setlistSongs = array_values(array_filter(
                array_map('trim', $body['songs']),
                fn($s) => preg_match('/^[A-Za-z]+-\d+$/', $s)
            ));
            $ownerId      = preg_replace('/[^a-f0-9\-]/', '', strtolower(trim($body['owner'])));

            if ($setlistName === '' || $ownerId === '') {
                sendJson(['error' => 'Invalid name or owner ID.'], 400);
                break;
            }

            if (count($setlistSongs) === 0) {
                sendJson(['error' => 'Set list must contain at least one song.'], 400);
                break;
            }

            /* Cap at 200 songs per shared setlist */
            if (count($setlistSongs) > 200) {
                sendJson(['error' => 'Set list cannot exceed 200 songs.'], 400);
                break;
            }

            $shareDir = APP_SETLIST_SHARE_DIR;

            /* Determine ID: use existing if updating, generate new otherwise */
            $shareId = null;
            if (!empty($body['id'])) {
                /* Updating an existing shared setlist */
                $candidateId = preg_replace('/[^a-f0-9]/', '', strtolower(trim($body['id'])));
                $existingFile = $shareDir . '/' . $candidateId . '.json';

                if ($candidateId !== '') {
                    if (!file_exists($existingFile)) {
                        sendJson(['error' => 'Shared set list not found.'], 404);
                        break;
                    }
                    /* Verify ownership */
                    $existing = json_decode(file_get_contents($existingFile), true);
                    if (!is_array($existing) || ($existing['owner'] ?? '') !== $ownerId) {
                        sendJson(['error' => 'You do not own this shared set list.'], 403);
                        break;
                    }
                    $shareId = $candidateId;
                }
            }

            /* Build the shared setlist object */
            $now = gmdate('c');
            $isUpdate = ($shareId !== null);
            $shareData = [
                'name'    => $setlistName,
                'songs'   => $setlistSongs,
                'owner'   => $ownerId,
                'created' => $isUpdate ? ($existing['created'] ?? $now) : $now,
                'updated' => $now,
                'version' => 1,
            ];

            if ($isUpdate) {
                /* Updating existing — write directly */
                $shareData['id'] = $shareId;
                $filePath = $shareDir . '/' . $shareId . '.json';
                $written = file_put_contents(
                    $filePath,
                    json_encode($shareData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                    LOCK_EX
                );
            } else {
                /* Generate new ID with atomic file creation to prevent TOCTOU race */
                $written = false;
                $attempts = 0;
                do {
                    $shareId = bin2hex(random_bytes(4)); /* 8 hex chars */
                    $filePath = $shareDir . '/' . $shareId . '.json';
                    $shareData['id'] = $shareId;
                    $jsonEncoded = json_encode($shareData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    $attempts++;

                    /* fopen 'x' mode fails if file already exists — atomic create */
                    $fp = @fopen($filePath, 'x');
                    if ($fp !== false) {
                        $written = fwrite($fp, $jsonEncoded);
                        fclose($fp);
                        break;
                    }
                } while ($attempts < 10);

                if ($written === false) {
                    sendJson(['error' => 'Unable to generate unique ID. Try again.'], 500);
                    break;
                }
            }

            if ($written === false) {
                sendJson(['error' => 'Failed to save shared set list.'], 500);
                break;
            }

            sendJson([
                'id'  => $shareId,
                'url' => '/setlist/shared/' . $shareId,
            ]);
            break;

        /* -----------------------------------------------------------------
         * Retrieve a shared setlist by short ID (#155)
         * Parameters: id (required) — 8-character hex ID
         * ----------------------------------------------------------------- */
        case 'setlist_get':
            $shareId = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower(trim($_GET['id']))) : '';
            if ($shareId === '' || strlen($shareId) > 16) {
                sendJson(['error' => 'Invalid or missing set list ID.'], 400);
                break;
            }

            $filePath = APP_SETLIST_SHARE_DIR . '/' . $shareId . '.json';
            if (!file_exists($filePath)) {
                sendJson(['error' => 'Shared set list not found.'], 404);
                break;
            }

            $data = json_decode(file_get_contents($filePath), true);
            if (!is_array($data)) {
                sendJson(['error' => 'Invalid set list data.'], 500);
                break;
            }

            /* Return public-safe fields only (exclude owner UUID) */
            sendJson([
                'id'      => $data['id'] ?? $shareId,
                'name'    => $data['name'] ?? 'Untitled',
                'songs'   => $data['songs'] ?? [],
                'created' => $data['created'] ?? null,
                'updated' => $data['updated'] ?? null,
            ]);
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
    header('X-Content-Type-Options: nosniff');
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
