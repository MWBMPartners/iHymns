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
require_once __DIR__ . '/includes/content_access.php';
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
         * Get missing song numbers within a songbook (#285)
         * Parameters: songbook (required)
         * Requires: editor+ role (via bearer token or session)
         * ----------------------------------------------------------------- */
        case 'missing_songs':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }
            $bookId = isset($_GET['songbook']) ? trim($_GET['songbook']) : '';
            if ($bookId === '') {
                sendJson(['error' => 'Songbook parameter is required.'], 400);
                break;
            }
            sendJson($songData->getMissingSongNumbers($bookId));
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

            /* Rate limit registrations: max 3 per IP per hour */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $db = getDb();
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblUsers
                 WHERE CreatedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 AND Id IN (SELECT DISTINCT UserId FROM tblLoginAttempts WHERE IpAddress = ? AND Success = 1)'
            );
            $stmt->execute([$clientIp]);
            /* Simpler fallback: count recent registrations by checking tblLoginAttempts */
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblLoginAttempts
                 WHERE IpAddress = ? AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $stmt->execute([$clientIp]);
            $recentAttempts = (int)$stmt->fetchColumn();
            if ($recentAttempts >= 20) {
                sendJson(['error' => 'Too many requests from this IP. Please try again later.'], 429);
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
            if (strlen($password) > 128) {
                sendJson(['error' => 'Password must not exceed 128 characters.'], 400);
                break;
            }
            if ($displayName === '') {
                $displayName = $username;
            }

            $db = getDb();

            /* Check if username already exists */
            $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                sendJson(['error' => 'Username already taken.'], 409);
                break;
            }

            /* Auto-assign 'global_admin' to the very first registered user;
             * all subsequent public registrations get 'user' role */
            $stmt = $db->query('SELECT COUNT(*) FROM tblUsers');
            $role = ((int)$stmt->fetchColumn() === 0) ? 'global_admin' : 'user';

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('INSERT INTO tblUsers (Username, PasswordHash, DisplayName, Role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $hash, mb_substr($displayName, 0, 100), $role]);
            $userId = (int)$db->lastInsertId();

            /* Generate API token (64-character hex string, 30-day expiry) */
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('c', time() + 30 * 86400);
            $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
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
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

            if ($username === '' || $password === '') {
                sendJson(['error' => 'Username and password required.'], 400);
                break;
            }

            $db = getDb();

            /* Brute force protection: check recent failed attempts from this IP (#290) */
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblLoginAttempts
                 WHERE IpAddress = ? AND Success = 0
                 AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
            );
            $stmt->execute([$clientIp]);
            $recentFailures = (int)$stmt->fetchColumn();

            if ($recentFailures >= 10) {
                sendJson(['error' => 'Too many failed login attempts. Please try again later.'], 429);
                break;
            }

            $stmt = $db->prepare('SELECT Id, Username, PasswordHash, DisplayName, Role, IsActive FROM tblUsers WHERE Username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['PasswordHash'])) {
                /* Log failed attempt */
                $stmt = $db->prepare(
                    'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 0)'
                );
                $stmt->execute([$clientIp, $username]);

                sendJson(['error' => 'Invalid username or password.'], 401);
                break;
            }

            if (!$user['IsActive']) {
                sendJson(['error' => 'Account is disabled.'], 403);
                break;
            }

            /* Log successful login attempt */
            $stmt = $db->prepare(
                'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 1)'
            );
            $stmt->execute([$clientIp, $username]);

            /* Update last login timestamp and count */
            $stmt = $db->prepare(
                'UPDATE tblUsers SET LastLoginAt = NOW(), LoginCount = LoginCount + 1 WHERE Id = ?'
            );
            $stmt->execute([(int)$user['Id']]);

            /* Generate API token */
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('c', time() + 30 * 86400);
            $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
            $stmt->execute([$token, (int)$user['Id'], $expiresAt]);

            sendJson([
                'token' => $token,
                'user'  => [
                    'id'           => (int)$user['Id'],
                    'username'     => $user['Username'],
                    'display_name' => $user['DisplayName'],
                    'role'         => $user['Role'],
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
                $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE Token = ?');
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
                    'id'           => $authUser['Id'],
                    'username'     => $authUser['Username'],
                    'display_name' => $authUser['DisplayName'],
                    'role'         => $authUser['Role'],
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
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ? ORDER BY UpdatedAt DESC');
            $stmt->execute([$authUser['Id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $setlists = array_map(function ($row) {
                return [
                    'id'        => $row['SetlistId'],
                    'name'      => $row['Name'],
                    'songs'     => json_decode($row['SongsJson'], true) ?: [],
                    'createdAt' => $row['CreatedAt'],
                    'updatedAt' => $row['UpdatedAt'],
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
            $userId = $authUser['Id'];

            /* Fetch all existing server-side setlists for this user */
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ?');
            $stmt->execute([$userId]);
            $serverRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $serverMap = [];
            foreach ($serverRows as $row) {
                $serverMap[$row['SetlistId']] = $row;
            }

            $now = gmdate('c');

            /* Upsert each local setlist */
            $upsert = $db->prepare(
                'INSERT INTO tblUserSetlists (UserId, SetlistId, Name, SongsJson, CreatedAt, UpdatedAt)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(UserId, SetlistId) DO UPDATE SET
                    Name = excluded.Name,
                    SongsJson = excluded.SongsJson,
                    UpdatedAt = excluded.UpdatedAt'
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
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ? ORDER BY UpdatedAt DESC');
            $stmt->execute([$userId]);
            $mergedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $mergedSetlists = array_map(function ($row) {
                return [
                    'id'        => $row['SetlistId'],
                    'name'      => $row['Name'],
                    'songs'     => json_decode($row['SongsJson'], true) ?: [],
                    'createdAt' => $row['CreatedAt'],
                    'updatedAt' => $row['UpdatedAt'],
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

            /* Always return success to prevent user enumeration.
             * The reset token is logged server-side only.
             * TODO: Deliver token via email in production. */
            if ($result) {
                error_log('[iHymns] Password reset token generated for user lookup: ' . $input);
            }
            sendJson([
                'ok'      => true,
                'message' => 'If an account exists with that username or email, a reset link has been generated.',
            ]);
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
            if (strlen($newPassword) > 128) {
                sendJson(['error' => 'Password must not exceed 128 characters.'], 400);
                break;
            }

            if (resetPassword($token, $newPassword)) {
                sendJson(['ok' => true, 'message' => 'Password reset successfully. Please sign in with your new password.']);
            } else {
                sendJson(['error' => 'Invalid or expired reset token.'], 400);
            }
            break;

        /* =================================================================
         * EMAIL LOGIN — Passwordless magic link / code authentication
         *
         * Two-step flow:
         *   1. POST auth_email_login_request — sends a magic link + 6-digit
         *      code to the user's email address (10-minute expiry)
         *   2. POST auth_email_login_verify — validates the token (from link)
         *      or code (manual entry) and returns a bearer token
         *
         * If the email doesn't match an existing account, a new user account
         * is auto-created on successful verification.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Request an email login link/code
         *
         * POST body (JSON): { "email": "user@example.com" }
         *
         * Returns 200 always (to prevent email enumeration).
         * The email contains both a clickable magic link and a 6-digit code.
         * ----------------------------------------------------------------- */
        case 'auth_email_login_request':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            require_once __DIR__ . '/manage/includes/auth.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $requestEmail = trim($body['email'] ?? '');

            if ($requestEmail === '' || !filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
                sendJson(['error' => 'A valid email address is required.'], 400);
                break;
            }

            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $result = generateEmailLoginToken($requestEmail, $clientIp);

            if ($result === null) {
                /* Rate limited — still return 200 to prevent enumeration */
                sendJson([
                    'ok'      => true,
                    'message' => 'If an account exists with that email, a login code has been sent.',
                ]);
                break;
            }

            /* TODO: Send the email with the magic link and code.
             * The magic link URL format: https://ihymns.app/login?token=<token>
             * The code: <6-digit code>
             * Until email delivery is implemented, log for development. */
            error_log(sprintf(
                '[iHymns] Email login requested for %s — Code: %s (expires in 10 min)',
                $requestEmail, $result['code']
            ));

            sendJson([
                'ok'      => true,
                'message' => 'A login code has been sent to your email address. It expires in 10 minutes.',
            ]);
            break;

        /* -----------------------------------------------------------------
         * Verify an email login token or code and return a bearer token
         *
         * POST body (JSON) — one of:
         *   { "token": "<48-char hex from magic link>" }
         *   { "email": "user@example.com", "code": "123456" }
         *
         * Returns: { token, user: { id, username, display_name, email, role } }
         * If the email doesn't have an account, one is auto-created.
         * ----------------------------------------------------------------- */
        case 'auth_email_login_verify':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            require_once __DIR__ . '/manage/includes/auth.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $verifyToken = trim($body['token'] ?? '');
            $verifyEmail = trim($body['email'] ?? '');
            $verifyCode  = trim($body['code'] ?? '');

            $verified = null;

            if ($verifyToken !== '') {
                /* Mode 1: Magic link — verify by token */
                $verified = verifyEmailLoginToken($verifyToken);
            } elseif ($verifyEmail !== '' && $verifyCode !== '') {
                /* Mode 2: Code entry — verify by email + code */
                $verified = verifyEmailLoginCode($verifyEmail, $verifyCode);
            } else {
                sendJson(['error' => 'Provide either a token (magic link) or email + code.'], 400);
                break;
            }

            if ($verified === null) {
                sendJson(['error' => 'Invalid or expired login code. Please request a new one.'], 401);
                break;
            }

            /* Complete the login: find/create user, generate bearer token */
            $loginResult = completeEmailLogin($verified['email'], $verified['userId']);

            /* Log the successful login */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $db = getDb();
            $stmt = $db->prepare(
                'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 1)'
            );
            $stmt->execute([$clientIp, $loginResult['user']['username']]);

            sendJson($loginResult);
            break;

        /* =================================================================
         * USER FAVORITES — Server-side sync (#284)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get all favorites for the authenticated user
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'favorites':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'SELECT SongId FROM tblUserFavorites WHERE UserId = ? ORDER BY CreatedAt DESC'
            );
            $stmt->execute([$authUser['Id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            sendJson(['favorites' => $rows]);
            break;

        /* -----------------------------------------------------------------
         * Sync favorites: merge local favorites with server storage
         *
         * POST body (JSON): { "favorites": ["CP-0001", "MP-0042", ...] }
         * Returns: { "favorites": [...merged...] }
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'favorites_sync':
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

            if (!is_array($body['favorites'] ?? null)) {
                sendJson(['error' => 'Invalid request. Required: favorites (array of song IDs).'], 400);
                break;
            }

            $db = getDb();
            $userId = $authUser['Id'];

            /* Sanitise incoming song IDs */
            $localFavs = array_values(array_filter(
                array_map('trim', $body['favorites']),
                fn($s) => preg_match('/^[A-Za-z]+-\d+$/', $s)
            ));

            /* Cap at 500 favorites */
            $localFavs = array_slice($localFavs, 0, 500);

            /* Get existing server favorites */
            $stmt = $db->prepare('SELECT SongId FROM tblUserFavorites WHERE UserId = ?');
            $stmt->execute([$userId]);
            $serverFavs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            /* Merge: union of local and server */
            $merged = array_unique(array_merge($serverFavs, $localFavs));

            /* Insert any new favorites (ignore duplicates) */
            $insert = $db->prepare(
                'INSERT IGNORE INTO tblUserFavorites (UserId, SongId) VALUES (?, ?)'
            );
            foreach ($localFavs as $songId) {
                $insert->execute([$userId, $songId]);
            }

            /* Return merged list */
            $stmt = $db->prepare(
                'SELECT SongId FROM tblUserFavorites WHERE UserId = ? ORDER BY CreatedAt DESC'
            );
            $stmt->execute([$userId]);
            $finalFavs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            sendJson(['favorites' => $finalFavs]);
            break;

        /* -----------------------------------------------------------------
         * Remove a song from favorites
         *
         * POST body (JSON): { "song_id": "CP-0001" }
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'favorites_remove':
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
            $removeSongId = trim($body['song_id'] ?? '');

            if (!preg_match('/^[A-Za-z]+-\d+$/', $removeSongId)) {
                sendJson(['error' => 'Invalid song ID.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare('DELETE FROM tblUserFavorites WHERE UserId = ? AND SongId = ?');
            $stmt->execute([$authUser['Id'], $removeSongId]);

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * SONG REQUESTS — Community submissions (#280)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Submit a song request (available to all users)
         *
         * POST body (JSON):
         *   { "title": "...", "songbook": "...", "song_number": "...",
         *     "language": "en", "details": "...", "contact_email": "..." }
         * ----------------------------------------------------------------- */
        case 'song_request':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $db = getDb();

            /* Check if song requests are enabled */
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $stmt->execute(['song_requests_enabled']);
            $enabled = $stmt->fetchColumn();
            if ($enabled === '0') {
                sendJson(['error' => 'Song requests are currently disabled.'], 403);
                break;
            }

            /* Rate limiting by IP */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $stmt->execute(['max_song_requests_per_day']);
            $maxPerDay = (int)($stmt->fetchColumn() ?: 5);

            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblSongRequests
                 WHERE IpAddress = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
            $stmt->execute([$clientIp]);
            if ((int)$stmt->fetchColumn() >= $maxPerDay) {
                sendJson(['error' => 'Rate limit exceeded. Maximum ' . $maxPerDay . ' requests per day.'], 429);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $reqTitle    = mb_substr(trim($body['title'] ?? ''), 0, 500);
            $reqSongbook = mb_substr(trim($body['songbook'] ?? ''), 0, 100);
            $reqNumber   = mb_substr(trim($body['song_number'] ?? ''), 0, 20);
            $reqLanguage = mb_substr(trim($body['language'] ?? 'en'), 0, 10);
            $reqDetails  = mb_substr(trim($body['details'] ?? ''), 0, 2000);
            $reqEmail    = mb_substr(trim($body['contact_email'] ?? ''), 0, 255);

            if ($reqTitle === '') {
                sendJson(['error' => 'Song title is required.'], 400);
                break;
            }

            /* Validate email format if provided */
            if ($reqEmail !== '' && !filter_var($reqEmail, FILTER_VALIDATE_EMAIL)) {
                sendJson(['error' => 'Invalid email address.'], 400);
                break;
            }

            /* Get authenticated user ID if available */
            $authUser = getAuthenticatedUser();
            $reqUserId = $authUser ? $authUser['Id'] : null;

            $stmt = $db->prepare(
                'INSERT INTO tblSongRequests
                 (Title, Songbook, SongNumber, Language, Details, ContactEmail, UserId, IpAddress, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $status = 'pending';
            $stmt->execute([
                $reqTitle, $reqSongbook, $reqNumber, $reqLanguage,
                $reqDetails, $reqEmail, $reqUserId, $clientIp, $status
            ]);
            $requestId = (int)$db->lastInsertId();

            sendJson(['ok' => true, 'id' => $requestId], 201);
            break;

        /* -----------------------------------------------------------------
         * Get the authenticated user's submitted song requests
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'my_song_requests':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'SELECT Id AS id, Title AS title, Songbook AS songbook,
                        SongNumber AS songNumber, Language AS language,
                        Details AS details, Status AS status,
                        ResolvedSongId AS resolvedSongId,
                        CreatedAt AS createdAt, UpdatedAt AS updatedAt
                 FROM tblSongRequests
                 WHERE UserId = ?
                 ORDER BY CreatedAt DESC'
            );
            $stmt->execute([$authUser['Id']]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson(['requests' => $requests]);
            break;

        /* =================================================================
         * LANGUAGES & TRANSLATIONS (#281)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get all available languages
         * ----------------------------------------------------------------- */
        case 'languages':
            $db = getDb();
            $stmt = $db->query(
                'SELECT Code AS code, Name AS name, NativeName AS nativeName,
                        TextDirection AS textDirection
                 FROM tblLanguages
                 WHERE IsActive = 1
                 ORDER BY Name ASC'
            );
            $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJson(['languages' => $languages]);
            break;

        /* -----------------------------------------------------------------
         * Get translations for a specific song
         * Parameters: id (required) — source song ID
         * ----------------------------------------------------------------- */
        case 'song_translations':
            $translationSongId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($translationSongId === '') {
                sendJson(['error' => 'Song ID is required.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'SELECT t.TranslatedSongId AS songId, t.TargetLanguage AS language,
                        t.Translator AS translator, t.Verified AS verified,
                        l.Name AS languageName, l.NativeName AS languageNativeName,
                        s.Title AS title, s.Number AS number
                 FROM tblSongTranslations t
                 JOIN tblLanguages l ON l.Code = t.TargetLanguage
                 JOIN tblSongs s ON s.SongId = t.TranslatedSongId
                 WHERE t.SourceSongId = ?
                 ORDER BY l.Name ASC'
            );
            $stmt->execute([$translationSongId]);
            $translations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($translations as &$tr) {
                $tr['verified'] = (bool)$tr['verified'];
                $tr['number'] = (int)$tr['number'];
            }
            unset($tr);

            sendJson(['translations' => $translations, 'sourceId' => $translationSongId]);
            break;

        /* =================================================================
         * USER GROUPS & VERSION ACCESS (#282)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get the authenticated user's group info and access level
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'user_access':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDb();

            /* Get all groups the user belongs to (primary + additional) */
            $stmt = $db->prepare(
                'SELECT g.Id AS id, g.Name AS name,
                        g.AccessAlpha AS accessAlpha, g.AccessBeta AS accessBeta,
                        g.AccessRc AS accessRc, g.AccessRtw AS accessRtw
                 FROM tblUserGroups g
                 WHERE g.Id = (SELECT GroupId FROM tblUsers WHERE Id = ?)
                 UNION
                 SELECT g.Id AS id, g.Name AS name,
                        g.AccessAlpha AS accessAlpha, g.AccessBeta AS accessBeta,
                        g.AccessRc AS accessRc, g.AccessRtw AS accessRtw
                 FROM tblUserGroups g
                 JOIN tblUserGroupMembers m ON m.GroupId = g.Id
                 WHERE m.UserId = ?'
            );
            $stmt->execute([$authUser['Id'], $authUser['Id']]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* Compute effective access (union of all group permissions) */
            $access = ['alpha' => false, 'beta' => false, 'rc' => false, 'rtw' => false];
            foreach ($groups as &$g) {
                $g['accessAlpha'] = (bool)$g['accessAlpha'];
                $g['accessBeta']  = (bool)$g['accessBeta'];
                $g['accessRc']    = (bool)$g['accessRc'];
                $g['accessRtw']   = (bool)$g['accessRtw'];
                if ($g['accessAlpha']) $access['alpha'] = true;
                if ($g['accessBeta'])  $access['beta']  = true;
                if ($g['accessRc'])    $access['rc']    = true;
                if ($g['accessRtw'])   $access['rtw']   = true;
            }
            unset($g);

            sendJson([
                'groups'         => $groups,
                'effectiveAccess' => $access,
                'role'           => $authUser['Role'],
            ]);
            break;

        /* =================================================================
         * APP STATUS & SETTINGS
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get public app status (maintenance mode, feature flags)
         * No authentication required — used by PWA on startup
         * ----------------------------------------------------------------- */
        case 'app_status':
            $db = getDb();
            /* Only fetch public-safe settings — never expose internal config */
            $publicKeys = ['maintenance_mode', 'song_requests_enabled', 'motd', 'registration_mode'];
            $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
            $stmt = $db->prepare(
                "SELECT SettingKey, SettingValue FROM tblAppSettings WHERE SettingKey IN ({$placeholders})"
            );
            $stmt->execute($publicKeys);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['SettingKey']] = $row['SettingValue'];
            }

            sendJson([
                'maintenance'         => ($settings['maintenance_mode'] ?? '0') === '1',
                'songRequestsEnabled' => ($settings['song_requests_enabled'] ?? '1') === '1',
                'registrationMode'    => $settings['registration_mode'] ?? 'open',
                'motd'                => $settings['motd'] ?? '',
            ]);
            break;

        /* =================================================================
         * USER PROFILE UPDATE
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Update authenticated user's profile
         *
         * POST body (JSON):
         *   { "display_name": "...", "email": "..." }
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'auth_update_profile':
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

            $newDisplayName = mb_substr(trim($body['display_name'] ?? ''), 0, 100);
            $newEmail       = mb_substr(trim($body['email'] ?? ''), 0, 255);

            if ($newDisplayName === '') {
                sendJson(['error' => 'Display name cannot be empty.'], 400);
                break;
            }
            if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                sendJson(['error' => 'Invalid email address.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'UPDATE tblUsers SET DisplayName = ?, Email = ?, UpdatedAt = NOW() WHERE Id = ?'
            );
            $stmt->execute([$newDisplayName, $newEmail, $authUser['Id']]);

            sendJson([
                'ok'   => true,
                'user' => [
                    'id'           => $authUser['Id'],
                    'username'     => $authUser['Username'],
                    'display_name' => $newDisplayName,
                    'email'        => $newEmail,
                    'role'         => $authUser['Role'],
                ],
            ]);
            break;

        /* -----------------------------------------------------------------
         * Change authenticated user's password
         *
         * POST body (JSON):
         *   { "current_password": "...", "new_password": "..." }
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'auth_change_password':
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

            $currentPw = $body['current_password'] ?? '';
            $newPw     = $body['new_password'] ?? '';

            if (strlen($newPw) < 8) {
                sendJson(['error' => 'New password must be at least 8 characters.'], 400);
                break;
            }
            if (strlen($newPw) > 128) {
                sendJson(['error' => 'Password must not exceed 128 characters.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare('SELECT PasswordHash FROM tblUsers WHERE Id = ?');
            $stmt->execute([$authUser['Id']]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($currentPw, $hash)) {
                sendJson(['error' => 'Current password is incorrect.'], 401);
                break;
            }

            $newHash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = NOW() WHERE Id = ?');
            $stmt->execute([$newHash, $authUser['Id']]);

            /* Invalidate all OTHER tokens (keep the current one) */
            $currentToken = getAuthBearerToken();
            $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND Token != ?');
            $stmt->execute([$authUser['Id'], $currentToken]);

            sendJson(['ok' => true, 'message' => 'Password changed successfully.']);
            break;

        /* =================================================================
         * ADMIN USER MANAGEMENT — API endpoints for /manage/ panel
         * Requires: admin+ role via Bearer token
         * ================================================================= */

        /* -----------------------------------------------------------------
         * List all users (admin+ only)
         * ----------------------------------------------------------------- */
        case 'admin_users':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDb();
            $stmt = $db->query(
                'SELECT u.Id AS id, u.Username AS username, u.Email AS email,
                        u.DisplayName AS display_name, u.Role AS role,
                        u.IsActive AS is_active, u.CreatedAt AS created_at,
                        g.Name AS group_name
                 FROM tblUsers u
                 LEFT JOIN tblUserGroups g ON g.Id = u.GroupId
                 ORDER BY u.CreatedAt ASC'
            );
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$u) {
                $u['id'] = (int)$u['id'];
                $u['is_active'] = (bool)$u['is_active'];
            }
            unset($u);

            sendJson(['users' => $users]);
            break;

        /* -----------------------------------------------------------------
         * List all user groups (admin+ only)
         * ----------------------------------------------------------------- */
        case 'admin_groups':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDb();
            $stmt = $db->query(
                'SELECT Id AS id, Name AS name, Description AS description,
                        AccessAlpha AS accessAlpha, AccessBeta AS accessBeta,
                        AccessRc AS accessRc, AccessRtw AS accessRtw
                 FROM tblUserGroups
                 ORDER BY Name ASC'
            );
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($groups as &$g) {
                $g['id'] = (int)$g['id'];
                $g['accessAlpha'] = (bool)$g['accessAlpha'];
                $g['accessBeta']  = (bool)$g['accessBeta'];
                $g['accessRc']    = (bool)$g['accessRc'];
                $g['accessRtw']   = (bool)$g['accessRtw'];
            }
            unset($g);

            sendJson(['groups' => $groups]);
            break;

        /* -----------------------------------------------------------------
         * Get activity log (admin+ only)
         * Parameters: limit (default 50), offset (default 0),
         *             action (optional filter), user_id (optional filter)
         * ----------------------------------------------------------------- */
        case 'admin_activity_log':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDb();
            $logLimit  = min((int)($_GET['limit'] ?? 50), 200);
            $logOffset = max((int)($_GET['offset'] ?? 0), 0);

            $where = [];
            $params = [];

            if (!empty($_GET['action_filter'])) {
                $where[] = 'a.Action = ?';
                $params[] = trim($_GET['action_filter']);
            }
            if (!empty($_GET['user_id'])) {
                $where[] = 'a.UserId = ?';
                $params[] = (int)$_GET['user_id'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $params[] = $logLimit;
            $params[] = $logOffset;

            $stmt = $db->prepare(
                "SELECT a.Id AS id, a.Action AS action, a.EntityType AS entityType,
                        a.EntityId AS entityId, a.Details AS details,
                        a.IpAddress AS ipAddress, a.CreatedAt AS createdAt,
                        u.Username AS username
                 FROM tblActivityLog a
                 LEFT JOIN tblUsers u ON u.Id = a.UserId
                 {$whereClause}
                 ORDER BY a.CreatedAt DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute($params);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($entries as &$e) {
                $e['id'] = (int)$e['id'];
                if ($e['details'] !== null) {
                    $e['details'] = json_decode($e['details'], true);
                }
            }
            unset($e);

            sendJson(['entries' => $entries, 'limit' => $logLimit, 'offset' => $logOffset]);
            break;

        /* -----------------------------------------------------------------
         * Get all song requests (admin/editor+ only)
         * Parameters: status (optional filter: pending/reviewed/added/declined)
         * ----------------------------------------------------------------- */
        case 'admin_song_requests':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }

            $db = getDb();
            $params = [];
            $where = '';

            if (!empty($_GET['status'])) {
                $where = 'WHERE r.Status = ?';
                $params[] = trim($_GET['status']);
            }

            $stmt = $db->prepare(
                "SELECT r.Id AS id, r.Title AS title, r.Songbook AS songbook,
                        r.SongNumber AS songNumber, r.Language AS language,
                        r.Details AS details, r.ContactEmail AS contactEmail,
                        r.Status AS status, r.AdminNotes AS adminNotes,
                        r.ResolvedSongId AS resolvedSongId,
                        r.CreatedAt AS createdAt, r.UpdatedAt AS updatedAt,
                        u.Username AS submittedBy
                 FROM tblSongRequests r
                 LEFT JOIN tblUsers u ON u.Id = r.UserId
                 {$where}
                 ORDER BY r.CreatedAt DESC
                 LIMIT 200"
            );
            $stmt->execute($params);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson(['requests' => $requests]);
            break;

        /* -----------------------------------------------------------------
         * Update a song request status (admin/editor+ only)
         *
         * POST body (JSON):
         *   { "id": 123, "status": "reviewed", "admin_notes": "..." }
         * ----------------------------------------------------------------- */
        case 'admin_song_request_update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $reqId     = (int)($body['id'] ?? 0);
            $newStatus = trim($body['status'] ?? '');
            $notes     = trim($body['admin_notes'] ?? '');
            $resolved  = trim($body['resolved_song_id'] ?? '');

            if ($reqId <= 0 || !in_array($newStatus, ['pending', 'reviewed', 'added', 'declined'])) {
                sendJson(['error' => 'Valid id and status (pending/reviewed/added/declined) required.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'UPDATE tblSongRequests
                 SET Status = ?, AdminNotes = ?, ResolvedSongId = ?, UpdatedAt = NOW()
                 WHERE Id = ?'
            );
            $stmt->execute([$newStatus, $notes, $resolved ?: null, $reqId]);

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * ORGANISATIONS & LICENSING (#326)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get the authenticated user's organisations
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'my_organisations':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'SELECT o.Id AS id, o.Name AS name, o.Slug AS slug,
                        o.ParentOrgId AS parentOrgId, o.Description AS description,
                        o.LicenceType AS licenceType, o.IsActive AS isActive,
                        m.Role AS memberRole
                 FROM tblOrganisations o
                 JOIN tblOrganisationMembers m ON m.OrgId = o.Id
                 WHERE m.UserId = ? AND o.IsActive = 1
                 ORDER BY o.Name ASC'
            );
            $stmt->execute([$authUser['Id']]);
            $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($orgs as &$org) {
                $org['id'] = (int)$org['id'];
                $org['parentOrgId'] = $org['parentOrgId'] ? (int)$org['parentOrgId'] : null;
                $org['isActive'] = (bool)$org['isActive'];
            }
            unset($org);

            sendJson(['organisations' => $orgs]);
            break;

        /* -----------------------------------------------------------------
         * Get organisation details (public if active)
         * Parameters: id or slug (required)
         * ----------------------------------------------------------------- */
        case 'organisation':
            $orgId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $orgSlug = trim($_GET['slug'] ?? '');

            $db = getDb();
            if ($orgId > 0) {
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            ParentOrgId AS parentOrgId, Description AS description,
                            LicenceType AS licenceType
                     FROM tblOrganisations WHERE Id = ? AND IsActive = 1'
                );
                $stmt->execute([$orgId]);
            } elseif ($orgSlug !== '') {
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            ParentOrgId AS parentOrgId, Description AS description,
                            LicenceType AS licenceType
                     FROM tblOrganisations WHERE Slug = ? AND IsActive = 1'
                );
                $stmt->execute([$orgSlug]);
            } else {
                sendJson(['error' => 'Organisation id or slug required.'], 400);
                break;
            }

            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$org) {
                sendJson(['error' => 'Organisation not found.'], 404);
                break;
            }
            $org['id'] = (int)$org['id'];
            $org['parentOrgId'] = $org['parentOrgId'] ? (int)$org['parentOrgId'] : null;

            /* Get child organisations */
            $stmt = $db->prepare(
                'SELECT Id AS id, Name AS name, Slug AS slug
                 FROM tblOrganisations WHERE ParentOrgId = ? AND IsActive = 1
                 ORDER BY Name ASC'
            );
            $stmt->execute([$org['id']]);
            $org['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* Get member count */
            $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = ?');
            $stmt->execute([$org['id']]);
            $org['memberCount'] = (int)$stmt->fetchColumn();

            sendJson(['organisation' => $org]);
            break;

        /* -----------------------------------------------------------------
         * Create an organisation
         * POST body: { "name": "...", "description": "...", "parent_org_id": null }
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'organisation_create':
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

            $orgName   = mb_substr(trim($body['name'] ?? ''), 0, 255);
            $orgDesc   = mb_substr(trim($body['description'] ?? ''), 0, 2000);
            $parentId  = !empty($body['parent_org_id']) ? (int)$body['parent_org_id'] : null;

            if ($orgName === '') {
                sendJson(['error' => 'Organisation name is required.'], 400);
                break;
            }

            /* Generate slug */
            $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($orgName));
            $slug = trim($slug, '-');
            if (strlen($slug) < 2) $slug = 'org-' . bin2hex(random_bytes(3));

            $db = getDb();

            /* Ensure unique slug */
            $baseSlug = $slug;
            $counter = 1;
            while (true) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisations WHERE Slug = ?');
                $stmt->execute([$slug]);
                if ((int)$stmt->fetchColumn() === 0) break;
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            $stmt = $db->prepare(
                'INSERT INTO tblOrganisations (Name, Slug, ParentOrgId, Description)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$orgName, $slug, $parentId, $orgDesc]);
            $newOrgId = (int)$db->lastInsertId();

            /* Creator becomes owner */
            $stmt = $db->prepare(
                'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, ?)'
            );
            $stmt->execute([$authUser['Id'], $newOrgId, 'owner']);

            sendJson(['ok' => true, 'id' => $newOrgId, 'slug' => $slug], 201);
            break;

        /* -----------------------------------------------------------------
         * Check content access for a specific entity
         * Parameters: entity_type (song/songbook/feature), entity_id, platform (optional)
         * Requires: Bearer token (optional — anonymous access checked too)
         * ----------------------------------------------------------------- */
        case 'content_access':
            $entityType = trim($_GET['entity_type'] ?? '');
            $entityId   = trim($_GET['entity_id'] ?? '');
            $platform   = trim($_GET['platform'] ?? 'PWA');

            if ($entityType === '' || $entityId === '') {
                sendJson(['error' => 'entity_type and entity_id are required.'], 400);
                break;
            }

            $authUser = getAuthenticatedUser();
            $userId = $authUser ? $authUser['Id'] : null;

            $result = checkContentAccess($entityType, $entityId, $userId, $platform);
            sendJson($result);
            break;

        /* -----------------------------------------------------------------
         * Admin: manage content restrictions
         * Requires: admin+ role
         * ----------------------------------------------------------------- */
        case 'admin_restrictions':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDb();
            $stmt = $db->query(
                'SELECT Id AS id, EntityType AS entityType, EntityId AS entityId,
                        RestrictionType AS restrictionType, TargetType AS targetType,
                        TargetId AS targetId, Effect AS effect, Priority AS priority,
                        Reason AS reason, CreatedAt AS createdAt
                 FROM tblContentRestrictions
                 ORDER BY Priority DESC, CreatedAt DESC
                 LIMIT 200'
            );
            $restrictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson(['restrictions' => $restrictions]);
            break;

        /* -----------------------------------------------------------------
         * Admin: create a content restriction
         * POST body: { entity_type, entity_id, restriction_type, target_type, target_id, effect, priority, reason }
         * ----------------------------------------------------------------- */
        case 'admin_restriction_create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);

            $db = getDb();
            $stmt = $db->prepare(
                'INSERT INTO tblContentRestrictions
                 (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($body['entity_type'] ?? ''),
                trim($body['entity_id'] ?? ''),
                trim($body['restriction_type'] ?? ''),
                trim($body['target_type'] ?? ''),
                trim($body['target_id'] ?? ''),
                trim($body['effect'] ?? 'deny'),
                (int)($body['priority'] ?? 0),
                trim($body['reason'] ?? ''),
            ]);

            sendJson(['ok' => true, 'id' => (int)$db->lastInsertId()], 201);
            break;

        /* -----------------------------------------------------------------
         * Admin: delete a content restriction
         * POST body: { "id": 123 }
         * ----------------------------------------------------------------- */
        case 'admin_restriction_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $delId = (int)($body['id'] ?? 0);

            if ($delId <= 0) {
                sendJson(['error' => 'Restriction ID required.'], 400);
                break;
            }

            $db = getDb();
            $stmt = $db->prepare('DELETE FROM tblContentRestrictions WHERE Id = ?');
            $stmt->execute([$delId]);

            sendJson(['ok' => true]);
            break;

        /* -----------------------------------------------------------------
         * Admin: list all organisations
         * Requires: admin+ role
         * ----------------------------------------------------------------- */
        case 'admin_organisations':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDb();
            $stmt = $db->query(
                'SELECT o.Id AS id, o.Name AS name, o.Slug AS slug,
                        o.ParentOrgId AS parentOrgId, o.LicenceType AS licenceType,
                        o.LicenceNumber AS licenceNumber, o.LicenceExpiresAt AS licenceExpiresAt,
                        o.IsActive AS isActive, o.CreatedAt AS createdAt,
                        (SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = o.Id) AS memberCount
                 FROM tblOrganisations o
                 ORDER BY o.Name ASC'
            );
            $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orgs as &$org) {
                $org['id'] = (int)$org['id'];
                $org['parentOrgId'] = $org['parentOrgId'] ? (int)$org['parentOrgId'] : null;
                $org['isActive'] = (bool)$org['isActive'];
                $org['memberCount'] = (int)$org['memberCount'];
            }
            unset($org);

            sendJson(['organisations' => $orgs]);
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
        'SELECT u.Id, u.Username, u.DisplayName, u.Role
         FROM tblApiTokens t
         JOIN tblUsers u ON u.Id = t.UserId
         WHERE t.Token = ? AND t.ExpiresAt > ? AND u.IsActive = 1'
    );
    $stmt->execute([$token, gmdate('c')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return null;

    $user['Id'] = (int)$user['Id'];
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
