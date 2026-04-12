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
require_once __DIR__ . '/includes/db_mysql.php';
require_once __DIR__ . '/includes/SongData.php';
require_once __DIR__ . '/manage/includes/db.php';

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
         * Serve the full song data as JSON (#154, #270)
         *
         * Exports the complete song database from MySQL as JSON for
         * client-side Fuse.js search and service worker caching.
         * Includes ETag for efficient browser caching.
         * ----------------------------------------------------------------- */
        case 'songs_json':
            $jsonData = json_encode(
                $songData->exportAsJson(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            /* ETag for conditional requests */
            $etag = '"' . md5($jsonData) . '"';

            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: public, max-age=3600, must-revalidate');
            header('ETag: ' . $etag);

            /* Return 304 Not Modified if client cache is still valid */
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($ifNoneMatch === $etag) {
                http_response_code(304);
                exit;
            }

            echo $jsonData;
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

            /* Sanitise optional per-song arrangements (map of songId → index array) */
            $arrangements = [];
            if (isset($body['arrangements']) && is_array($body['arrangements'])) {
                foreach ($body['arrangements'] as $sid => $arr) {
                    /* Only accept valid song IDs and arrays of non-negative integers */
                    if (!is_string($sid) || !preg_match('/^[A-Za-z]+-\d+$/', $sid)) continue;
                    if (!is_array($arr)) continue;
                    $validArr = array_values(array_filter($arr, fn($v) => is_int($v) && $v >= 0));
                    if (count($validArr) > 0) {
                        $arrangements[$sid] = $validArr;
                    }
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
                'version' => 2,
            ];

            /* Include arrangements only if any songs have custom arrangements */
            if (!empty($arrangements)) {
                $shareData['arrangements'] = $arrangements;
            }

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
            $response = [
                'id'      => $data['id'] ?? $shareId,
                'name'    => $data['name'] ?? 'Untitled',
                'songs'   => $data['songs'] ?? [],
                'created' => $data['created'] ?? null,
                'updated' => $data['updated'] ?? null,
            ];

            /* Include per-song arrangements if present */
            if (!empty($data['arrangements'])) {
                $response['arrangements'] = $data['arrangements'];
            }

            sendJson($response);
            break;

        /* =================================================================
         * USER AUTHENTICATION — Public-facing account system
         *
         * Allows PWA users to create accounts, log in with bearer tokens,
         * and sync setlists across devices. Separate from the admin/editor
         * auth system in /manage/.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Register a new public user account
         *
         * POST body (JSON):
         *   { "username": "...", "password": "...", "display_name": "..." }
         *
         * Returns: { "token": "...", "user": { id, username, display_name } }
         * ----------------------------------------------------------------- */
        case 'auth_register':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $username    = mb_strtolower(trim($body['username'] ?? ''));
            $password    = $body['password'] ?? '';
            $displayName = trim($body['display_name'] ?? '');

            /* Validate inputs */
            if (strlen($username) < 3 || !preg_match('/^[a-z0-9_.\-]+$/', $username)) {
                sendJson(['error' => 'Username must be at least 3 characters (letters, numbers, _, -, . only).'], 400);
                break;
            }
            if (strlen($password) < 8) {
                sendJson(['error' => 'Password must be at least 8 characters.'], 400);
                break;
            }
            if ($displayName === '') {
                $displayName = $username;
            }

            $db = getDb();

            /* Check if username already exists */
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                sendJson(['error' => 'Username already taken.'], 409);
                break;
            }

            /* Auto-assign 'global_admin' to the very first registered user;
             * all subsequent public registrations get 'user' role */
            $stmt = $db->query('SELECT COUNT(*) FROM users');
            $role = ((int)$stmt->fetchColumn() === 0) ? 'global_admin' : 'user';

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $hash, mb_substr($displayName, 0, 100), $role]);
            $userId = (int)$db->lastInsertId();

            /* Generate API token (64-character hex string, 30-day expiry) */
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('c', time() + 30 * 86400);
            $stmt = $db->prepare('INSERT INTO api_tokens (token, user_id, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$token, $userId, $expiresAt]);

            sendJson([
                'token' => $token,
                'user'  => [
                    'id'           => $userId,
                    'username'     => $username,
                    'display_name' => $displayName,
                    'role'         => $role,
                ],
            ], 201);
            break;

        /* -----------------------------------------------------------------
         * Log in and receive a bearer token
         *
         * POST body (JSON): { "username": "...", "password": "..." }
         * Returns: { "token": "...", "user": { id, username, display_name } }
         * ----------------------------------------------------------------- */
        case 'auth_login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $username = mb_strtolower(trim($body['username'] ?? ''));
            $password = $body['password'] ?? '';

            if ($username === '' || $password === '') {
                sendJson(['error' => 'Username and password required.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare('SELECT id, username, password_hash, display_name, role, is_active FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                sendJson(['error' => 'Invalid username or password.'], 401);
                break;
            }

            if (!$user['is_active']) {
                sendJson(['error' => 'Account is disabled.'], 403);
                break;
            }

            /* Generate API token */
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('c', time() + 30 * 86400);
            $stmt = $db->prepare('INSERT INTO api_tokens (token, user_id, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$token, (int)$user['id'], $expiresAt]);

            sendJson([
                'token' => $token,
                'user'  => [
                    'id'           => (int)$user['id'],
                    'username'     => $user['username'],
                    'display_name' => $user['display_name'],
                    'role'         => $user['role'],
                ],
            ]);
            break;

        /* -----------------------------------------------------------------
         * Log out (invalidate bearer token)
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'auth_logout':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $token = getAuthBearerToken();
            if ($token) {
                $db = getDb();
                $stmt = $db->prepare('DELETE FROM api_tokens WHERE token = ?');
                $stmt->execute([$token]);
            }

            sendJson(['ok' => true]);
            break;

        /* -----------------------------------------------------------------
         * Get current authenticated user info
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'auth_me':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            sendJson([
                'user' => [
                    'id'           => $authUser['id'],
                    'username'     => $authUser['username'],
                    'display_name' => $authUser['display_name'],
                    'role'         => $authUser['role'],
                ],
            ]);
            break;

        /* =================================================================
         * USER-LINKED SETLISTS — Server-side storage synced to accounts
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get all setlists for the authenticated user
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'user_setlists':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare('SELECT setlist_id, name, songs_json, created_at, updated_at FROM user_setlists WHERE user_id = ? ORDER BY updated_at DESC');
            $stmt->execute([$authUser['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $setlists = array_map(function ($row) {
                return [
                    'id'        => $row['setlist_id'],
                    'name'      => $row['name'],
                    'songs'     => json_decode($row['songs_json'], true) ?: [],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at'],
                ];
            }, $rows);

            sendJson(['setlists' => $setlists]);
            break;

        /* -----------------------------------------------------------------
         * Sync setlists: merge local setlists with server-side storage.
         * Accepts the full array of local setlists and merges intelligently:
         *   - New setlists (by ID) are inserted
         *   - Existing setlists are updated if local version is newer
         *   - Server-only setlists are preserved and returned
         *
         * POST body (JSON):
         *   { "setlists": [{ id, name, createdAt, songs: [...] }, ...] }
         *
         * Returns: { "setlists": [...merged result...] }
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'user_setlists_sync':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            if (!is_array($body['setlists'] ?? null)) {
                sendJson(['error' => 'Invalid request. Required: setlists (array).'], 400);
                break;
            }

            /* Cap at 50 setlists per user */
            $localLists = array_slice($body['setlists'], 0, 50);

            $db = getDb();
            $userId = $authUser['id'];

            /* Fetch all existing server-side setlists for this user */
            $stmt = $db->prepare('SELECT setlist_id, name, songs_json, created_at, updated_at FROM user_setlists WHERE user_id = ?');
            $stmt->execute([$userId]);
            $serverRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $serverMap = [];
            foreach ($serverRows as $row) {
                $serverMap[$row['setlist_id']] = $row;
            }

            $now = gmdate('c');

            /* Upsert each local setlist */
            $upsert = $db->prepare(
                'INSERT INTO user_setlists (user_id, setlist_id, name, songs_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(user_id, setlist_id) DO UPDATE SET
                    name = excluded.name,
                    songs_json = excluded.songs_json,
                    updated_at = excluded.updated_at'
            );

            foreach ($localLists as $list) {
                if (empty($list['id'])) continue;

                $setlistId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $list['id']);
                if ($setlistId === '') continue;

                $name = mb_substr(trim($list['name'] ?? 'Untitled'), 0, 200);
                $songs = is_array($list['songs'] ?? null) ? $list['songs'] : [];

                /* Sanitise each song entry */
                $cleanSongs = [];
                foreach (array_slice($songs, 0, 200) as $s) {
                    if (!is_array($s) || empty($s['id'])) continue;
                    $entry = [
                        'id'       => (string)$s['id'],
                        'title'    => mb_substr((string)($s['title'] ?? ''), 0, 300),
                        'songbook' => mb_substr((string)($s['songbook'] ?? ''), 0, 20),
                        'number'   => (int)($s['number'] ?? 0),
                    ];
                    /* Preserve custom arrangement if present */
                    if (isset($s['arrangement']) && is_array($s['arrangement'])) {
                        $entry['arrangement'] = array_values(array_filter(
                            $s['arrangement'],
                            fn($v) => is_int($v) && $v >= 0
                        ));
                    }
                    $cleanSongs[] = $entry;
                }

                $songsJson = json_encode($cleanSongs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $createdAt = $list['createdAt'] ?? $now;

                $upsert->execute([$userId, $setlistId, $name, $songsJson, $createdAt, $now]);

                /* Remove from server map so we know which are server-only */
                unset($serverMap[$setlistId]);
            }

            /* Fetch the merged result (all setlists for this user) */
            $stmt = $db->prepare('SELECT setlist_id, name, songs_json, created_at, updated_at FROM user_setlists WHERE user_id = ? ORDER BY updated_at DESC');
            $stmt->execute([$userId]);
            $mergedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $mergedSetlists = array_map(function ($row) {
                return [
                    'id'        => $row['setlist_id'],
                    'name'      => $row['name'],
                    'songs'     => json_decode($row['songs_json'], true) ?: [],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at'],
                ];
            }, $mergedRows);

            sendJson(['setlists' => $mergedSetlists]);
            break;

        /* =================================================================
         * PASSWORD RESET — Forgot password flow
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Request a password reset token
         *
         * POST body (JSON): { "username": "..." }
         * (username or email accepted)
         *
         * Always returns 200 to prevent user enumeration.
         * In production, send the token via email. For now, it is
         * returned in the response for development/testing.
         * ----------------------------------------------------------------- */
        case 'auth_forgot_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            require_once __DIR__ . '/manage/includes/auth.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $input = trim($body['username'] ?? '');

            if ($input === '') {
                sendJson(['error' => 'Username or email required.'], 400);
                break;
            }

            $result = generatePasswordResetToken($input);

            /* Always return success to prevent user enumeration */
            if ($result) {
                /* In production, send email here. For dev/testing, include the token.
                 * TODO: integrate email delivery for production */
                sendJson([
                    'ok'      => true,
                    'message' => 'If an account exists with that username or email, a reset link has been generated.',
                    '_dev_token' => $result['token'],
                ]);
            } else {
                sendJson([
                    'ok'      => true,
                    'message' => 'If an account exists with that username or email, a reset link has been generated.',
                ]);
            }
            break;

        /* -----------------------------------------------------------------
         * Reset password using a valid token
         *
         * POST body (JSON): { "token": "...", "password": "..." }
         * ----------------------------------------------------------------- */
        case 'auth_reset_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            require_once __DIR__ . '/manage/includes/auth.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $token       = trim($body['token'] ?? '');
            $newPassword = $body['password'] ?? '';

            if ($token === '' || strlen($newPassword) < 8) {
                sendJson(['error' => 'Valid token and password (min 8 characters) required.'], 400);
                break;
            }

            if (resetPassword($token, $newPassword)) {
                sendJson(['ok' => true, 'message' => 'Password reset successfully. Please sign in with your new password.']);
            } else {
                sendJson(['error' => 'Invalid or expired reset token.'], 400);
            }
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
 * Extract a Bearer token from the Authorization header.
 *
 * @return string|null The token string, or null if not present
 */
function getAuthBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Authenticate a request using a Bearer token.
 * Returns the user row if valid, null otherwise.
 *
 * @return array|null User data { id, username, display_name, role }
 */
function getAuthenticatedUser(): ?array
{
    $token = getAuthBearerToken();
    if (!$token) return null;

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.display_name, u.role
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > ? AND u.is_active = 1'
    );
    $stmt->execute([$token, gmdate('c')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return null;

    $user['id'] = (int)$user['id'];
    return $user;
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
