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

/* On-demand debug mode (#TBD) — must come first so it catches errors
   anywhere downstream. Honoured only on Alpha/Beta when the request
   carries both `?_debug=1` and `?_dev=1` (or the cookie set by either
   from a recent index.php hit); production ignores. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'debug_mode.php';
enableDebugModeIfRequested();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'infoAppVer.php';

/* =========================================================================
 * GLOBAL JSON ERROR HANDLER (#803)
 *
 * Without this, an uncaught \Throwable anywhere in the dispatch below
 * produced PHP's default HTML error page — every JSON client hit it
 * and `res.json()` blew up, surfacing the misleading "Network error.
 * Please try again." instead of the real cause. Registered as early as
 * possible so even a fatal in the rest of the bootstrap (require_once
 * etc.) gets a JSON response on action requests.
 *
 * Two registrations:
 *   - set_exception_handler   — catches uncaught \Throwable
 *   - register_shutdown_function — catches fatal errors (parse errors,
 *                                    out-of-memory, …) that don't
 *                                    surface as throwables
 *
 * Page (?page=…) requests still get an HTML fragment — the SPA injects
 * the response into a <div>, so a JSON body would be useless. Action
 * requests get JSON. Everything is logged to error_log with a request
 * id correlation token.
 *
 * Body shape on Alpha/Beta — message + class to speed up debugging:
 *   { "error": "Database error: ...", "request_id": "abc1234567",
 *     "exception_class": "mysqli_sql_exception" }
 * Body shape on production — generic message + request id only:
 *   { "error": "Internal server error.", "request_id": "abc1234567" }
 * ========================================================================= */
(function () {
    /* Generate a short request id once. Re-used by both handlers + by
       any inner handler that wants to thread the same correlation id
       through to its log lines. Stashed on $GLOBALS so the rest of the
       request can read it without reaching back through this closure. */
    $rid = bin2hex(random_bytes(5));
    $GLOBALS['_ihymnsApiRequestId'] = $rid;

    $isAction = isset($_GET['action']) && trim((string)$_GET['action']) !== '';
    $devStatus = $GLOBALS['app']['Application']['Version']['Development']['Status'] ?? null;
    $verbose = ($devStatus === 'Alpha' || $devStatus === 'Beta');

    $emit = function (string $msg, string $class, ?string $where = null) use ($isAction, $verbose, $rid) {
        /* Always log the full detail to error_log so production admins
           can correlate via the request id even when the response is
           generic. */
        error_log(sprintf(
            '[api uncaught rid=%s] %s: %s%s',
            $rid, $class, $msg, $where ? " @ {$where}" : ''
        ));
        if (headers_sent()) return; /* nothing we can do — response already started */
        http_response_code(500);
        if ($isAction) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache, must-revalidate');
            $body = ['error' => 'Internal server error.', 'request_id' => $rid];
            if ($verbose) {
                $body['error']           = $msg;
                $body['exception_class'] = $class;
                if ($where !== null) $body['where'] = $where;
            }
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            /* Page requests stay HTML so the SPA can render the chunk
               into the page <div>. Same alpha/beta gating on what we
               disclose. */
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="alert alert-danger" role="alert">';
            echo '<strong>Internal server error.</strong> ';
            echo 'Reference: <code>' . htmlspecialchars($rid, ENT_QUOTES) . '</code>';
            if ($verbose) {
                echo '<br><small>' . htmlspecialchars($class . ': ' . $msg, ENT_QUOTES) . '</small>';
            }
            echo '</div>';
        }
    };

    set_exception_handler(function (\Throwable $e) use ($emit) {
        $emit($e->getMessage(), get_class($e),
              basename($e->getFile()) . ':' . $e->getLine());
    });

    register_shutdown_function(function () use ($emit) {
        $err = error_get_last();
        /* Only trip on fatal-class errors — non-fatal warnings/notices
           shouldn't write a 500. PHP fatals are E_ERROR, E_PARSE,
           E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR. */
        $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!$err || !($err['type'] & $fatalMask)) return;
        $emit($err['message'], 'PHP fatal',
              basename($err['file'] ?? '?') . ':' . ($err['line'] ?? 0));
    });
})();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'content_access.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'card_layout.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Shared songbook validators (#719 PR 2a). Same rules used by
   /manage/songbooks.php so a tweak to abbrev / colour / IETF-tag
   grammar lands on the web admin and the API surface in one go. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_validation.php';
/* Shared organisation helpers (#719 PR 2c). ORG_MEMBER_ROLES +
   slugifyOrganisationName() + userCanActOnOrg() row-level gate. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'organisation_validation.php';
/* Shared credit-people helpers (#719 PR 2d). Link-type catalogue +
   normalisers + flag-columns probe. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'credit_people_helpers.php';
/* Shared schema-audit helpers (#719 PR 2d). Parser + migration
   scanner + comparer used by admin_schema_audit and
   admin_migrations_status read endpoints. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'schema_audit.php';
/* Language filter helper (#736). Resolves the active preferred-
   language subtag list for the current request (via ?lang= query
   param, X-Preferred-Languages header, or tblUsers.PreferredLanguagesJson)
   and provides applyLanguageFilterSql() / makeLanguageFilterPredicate()
   helpers for endpoints to filter song / songbook results. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'language_filter.php';

/* =========================================================================
 * CSRF DEFENCE POLICY (#293 / B15)
 *
 * The iHymns API is a same-origin AJAX surface — every legitimate
 * caller is the SPA running on the same domain, talking to /api via
 * `fetch()` with `credentials: 'same-origin'`. There are no third-
 * party clients, no embedded forms posting cross-site, no public
 * API consumers.
 *
 * The defence stack is therefore:
 *   1. SameSite=Strict on the auth cookie — browsers refuse to send
 *      it on cross-site POST requests, so a CSRF attempt has no
 *      authenticated identity. (Set in the auth-cookie issuance.)
 *   2. Bearer token in `Authorization: Bearer <token>` header —
 *      cross-origin attackers can't read it (it's in localStorage of
 *      the legitimate origin, blocked from cross-origin reads by the
 *      Same-Origin Policy).
 *   3. THIS guard: every POST must carry `X-Requested-With:
 *      XMLHttpRequest`. The header is a "forbidden header name" that
 *      cross-origin POST forms cannot set without triggering a CORS
 *      preflight (which we don't honour). Blocks classic <form>-based
 *      CSRF as a belt-and-braces measure.
 *
 * Per-form CSRF tokens are deliberately NOT used. They'd be a
 * heavier solution than this codebase's same-origin SPA needs, and
 * the three layers above already eliminate the CSRF attack surface.
 *
 * If a future external integration (webhook, third-party caller)
 * ever needs to POST to /api, give it its own endpoint outside this
 * guard rather than weakening the policy.
 * ========================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if ($xrw !== 'XMLHttpRequest') {
        sendJson([
            'error' => 'Cross-site POST blocked: missing or invalid X-Requested-With header.',
        ], 403);
        exit;
    }
}

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

    /* Cache SPA fragments so bouncing between pages doesn't re-hit the
       server for content that hasn't changed. Uses ETag + 304 so the
       body isn't re-sent when the content is unchanged — the service
       worker can treat fragment responses as cacheable. Logged-in
       content would need a per-user ETag; this currently covers the
       same-for-everyone pages (home, songbooks, song, songbook,
       writer, help, terms, privacy) which make up most navigation.
       Content-sensitive pages (favorites, setlist, settings, stats)
       still skip this path because they include user-specific data. */
    $_cacheablePages = [
        'home', 'songbooks', 'songbook', 'song', 'search',
        'writer', 'person', 'help', 'terms', 'privacy', 'request', 'request-a-song',
    ];
    $_shouldCachePage = in_array($page, $_cacheablePages, true);
    if ($_shouldCachePage) {
        ob_start();
    }

    /* Route to the appropriate page template */
    switch ($page) {
        case 'home':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'home.php';
            break;

        case 'songbooks':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'songbooks.php';
            break;

        case 'songbook':
            /* Requires songbook ID parameter */
            $bookId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($bookId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Songbook ID is required.</div>';
                break;
            }
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'songbook.php';
            break;

        case 'song':
            /* Requires song ID parameter */
            $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($songId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Song ID is required.</div>';
                break;
            }
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'song.php';
            break;

        case 'search':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'search.php';
            break;

        case 'favorites':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'favorites.php';
            break;

        case 'setlist':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'setlist.php';
            break;

        case 'setlist-shared':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'setlist-shared.php';
            break;

        case 'settings':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'settings.php';
            break;

        case 'stats':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'stats.php';
            break;

        case 'writer':
            /* Requires writer slug parameter */
            $writerId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($writerId === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Writer ID is required.</div>';
                break;
            }
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'writer.php';
            break;

        case 'person':
            /* Credit Person public page (#588). Slug column on
               tblCreditPeople is the canonical lookup; the page
               renderer falls back to a name-based search if the
               migration hasn't been applied yet. */
            $personSlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
            if ($personSlug === '') {
                http_response_code(400);
                echo '<div class="alert alert-warning" role="alert">Person slug is required.</div>';
                break;
            }
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'person.php';
            break;

        case 'help':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'help.php';
            break;

        case 'terms':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'terms.php';
            break;

        case 'privacy':
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'privacy.php';
            break;

        case 'request':
        case 'request-a-song':
            /* /request is canonical (#658). request-a-song retained as
               an alias for older bookmarks / shared links / offline-queue
               replays. Both render the same page partial. */
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'request-a-song.php';
            break;

        default:
            http_response_code(404);
            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'not-found.php';
            break;
    }

    if ($_shouldCachePage) {
        $body = (string)ob_get_clean();
        /* Include the query string so /api?page=song&id=CP-0001 and
           /api?page=song&id=CP-0002 hash to different ETags. */
        $etag = '"' . hash('xxh64', $page . '|' . ($_SERVER['QUERY_STRING'] ?? '') . '|' . $body) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=300, must-revalidate');
        header('Vary: Cookie, Authorization');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        echo $body;
    }

    exit;
}

/* =========================================================================
 * ACTION REQUESTS — Return JSON data for dynamic operations
 * ========================================================================= */

if ($action !== null) {
    /* Top-level try/catch wraps the whole switch (#535 Phase 4) so
       any uncaught Throwable from a case clause writes ONE
       activity-log row tagged Result='error' and the exception's
       message/class/file:line in Details. The current behaviour
       (sendJson 500 + error_log) is preserved — the activity-log
       row is purely additive. Specific case clauses that catch
       their own exceptions still do, so we don't double-log; this
       net only catches what isn't already caught. */
    $_apiSwitchStart = microtime(true);
    try {
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

            /* Language filter (#736). Apply the active preferred-subtag
               set in-memory so search results respect the user's
               saved preference / current dropdown selection. Untagged
               songs always pass. */
            $_langPred = makeLanguageFilterPredicate(
                resolvePreferredLanguagesForRequest(getAuthenticatedUser())
            );
            $results = array_values(array_filter($results, $_langPred));

            /* Fire-and-forget search-query logging (#404). Silently no-ops
               if the table is missing (fresh installs before the schema
               ALTER has been applied). */
            try {
                $logDb = getDbMysqli();
                $uid   = null;
                $logAuth = getAuthenticatedUser();
                if ($logAuth) $uid = (int)$logAuth['Id'];
                $logStmt = $logDb->prepare(
                    'INSERT INTO tblSearchQueries (Query, ResultCount, UserId) VALUES (?, ?, ?)'
                );
                $resultCount = count($results);
                $logStmt->bind_param('sii', $query, $resultCount, $uid);
                $logStmt->execute();
                $logStmt->close();
            } catch (\Throwable $_e) {
                /* Best-effort — search-query analytics is non-critical.
                   Logged so admins notice if the INSERT is failing
                   systematically (e.g., tblSearchQueries DDL drift). */
                error_log('[api/search log] ' . $_e->getMessage());
            }

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
         * Cross-book counterparts for one song (#807)
         * Returns every other tblSongs row that shares this song's
         * tblSongLinks.GroupId — same hymn in a different songbook.
         * Distinct from translations (different language) and from the
         * songbook-level parent link (#782 phase D).
         * Parameters: id (required) — canonical SongId, e.g. CP-0001
         * ----------------------------------------------------------------- */
        case 'song_links':
            $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($songId === '') {
                sendJson(['error' => 'Song ID is required.'], 400);
                break;
            }
            try {
                $db = getDbMysqli();

                /* Probe for the table — deployments that haven't run the
                   migration get a clean empty list rather than a 500. */
                $probe = $db->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongLinks' LIMIT 1"
                );
                $hasTable = $probe && $probe->fetch_row() !== null;
                if ($probe) $probe->close();
                if (!$hasTable) {
                    sendJson(['groupId' => 0, 'songs' => []]);
                    break;
                }

                $stmt = $db->prepare(
                    'SELECT GroupId FROM tblSongLinks WHERE SongId = ? LIMIT 1'
                );
                $stmt->bind_param('s', $songId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $groupId = $row ? (int)$row['GroupId'] : 0;

                $songs = [];
                if ($groupId > 0) {
                    $stmt = $db->prepare(
                        'SELECT s.SongId       AS id,
                                s.Title        AS title,
                                s.Number       AS number,
                                s.SongbookAbbr AS songbook,
                                sb.Name        AS songbookName,
                                s.Language     AS language,
                                l.Note         AS note,
                                l.Verified     AS verified
                           FROM tblSongLinks l
                           JOIN tblSongs s      ON s.SongId = l.SongId
                           JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                          WHERE l.GroupId = ?
                            AND l.SongId  <> ?
                          ORDER BY s.SongbookAbbr ASC, s.Number ASC'
                    );
                    $stmt->bind_param('is', $groupId, $songId);
                    $stmt->execute();
                    $songs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($songs as &$s) {
                        $s['number']   = ($s['number'] === null) ? null : (int)$s['number'];
                        $s['verified'] = (bool)$s['verified'];
                    }
                    unset($s);
                }
                sendJson(['groupId' => $groupId, 'songs' => $songs]);
            } catch (\Throwable $e) {
                error_log('[api] song_links failed: ' . $e->getMessage());
                sendJson(['error' => 'Failed to load cross-book counterparts.'], 500);
            }
            break;

        /* -----------------------------------------------------------------
         * Get all songbooks (filtered by user's preferred languages, #736)
         * ----------------------------------------------------------------- */
        case 'songbooks':
            $_books = $songData->getSongbooks();
            $_langPred = makeLanguageFilterPredicate(
                resolvePreferredLanguagesForRequest(getAuthenticatedUser())
            );
            sendJson(['songbooks' => array_values(array_filter($_books, $_langPred))]);
            break;

        /* -----------------------------------------------------------------
         * Get songs list (with optional songbook filter, language filter #736)
         * Parameters: songbook (optional)
         * ----------------------------------------------------------------- */
        case 'songs':
            $bookId = isset($_GET['songbook']) ? trim($_GET['songbook']) : null;
            if ($bookId === '') {
                $bookId = null;
            }

            $songs = $songData->getSongs($bookId);
            $_langPred = makeLanguageFilterPredicate(
                resolvePreferredLanguagesForRequest(getAuthenticatedUser())
            );
            $songs = array_values(array_filter($songs, $_langPred));
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
         * Bulk download rendered song pages for offline caching (#359)
         *
         * Returns all songs for a songbook (or all songbooks) as a JSON
         * object mapping song ID → rendered HTML. The service worker can
         * split this into individual cache entries, turning 3,612
         * requests into ~6 (one per songbook).
         *
         * Parameters:
         *   songbook (optional) — filter by songbook abbreviation
         *
         * Response: { "songs": { "CP-0001": "<html>...", ... } }
         * ----------------------------------------------------------------- */
        case 'bulk_songs':
            $bulkSongbook = isset($_GET['songbook']) ? trim($_GET['songbook']) : '';
            $bulkSongs = $bulkSongbook !== ''
                ? $songData->getSongs($bulkSongbook)
                : $songData->getSongs();

            if (empty($bulkSongs)) {
                sendJson(['songs' => new \stdClass()]);
                break;
            }

            $rendered = [];
            /* getSongs() already returns every field song.php reads from
               $song at bulk-cache time (id, title, number, songbook,
               songbookName, copyright, ccli, verified, has*, writers,
               composers, components). The only fields it leaves off —
               arrangement / capo / key — are optional and rendered with
               `!empty($song[...])`, so a missing value degrades
               gracefully. Skipping getSongById() inside this loop drops
               the per-songbook work from O(2N) to O(N) and eliminates
               the 800+ extra queries per Church Hymnal bulk fetch. */
            foreach ($bulkSongs as $bulkSong) {
                $songId = $bulkSong['id'];
                $song = $bulkSong;

                ob_start();
                require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'song.php';
                $rendered[$songId] = ob_get_clean();
            }

            /* Use streaming-friendly output with gzip */
            $json = json_encode(
                ['songs' => $rendered, 'count' => count($rendered)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: public, max-age=3600, must-revalidate');
            echo $json;
            break;

        /* -----------------------------------------------------------------
         * Bulk audio manifest (#401) — returns the list of audio URLs
         * for a songbook so the service worker can pre-cache audio
         * separately from song HTML. Only songs whose `hasAudio` flag
         * is set are returned.
         *
         * Parameters: songbook (optional; all if omitted)
         * ----------------------------------------------------------------- */
        case 'bulk_audio':
            $audioBook  = isset($_GET['songbook']) ? trim($_GET['songbook']) : '';
            $audioSongs = $audioBook !== ''
                ? $songData->getSongs($audioBook)
                : $songData->getSongs();

            $manifest = [];
            foreach ($audioSongs as $s) {
                if (empty($s['hasAudio'])) continue;
                $sid = $s['id'] ?? '';
                if ($sid === '') continue;
                $manifest[] = [
                    'songId' => $sid,
                    'url'    => '/data/audio/' . rawurlencode($sid) . '.mp3',
                ];
            }

            sendJson([
                'songbook' => $audioBook ?: 'all',
                'count'    => count($manifest),
                'audio'    => $manifest,
            ]);
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

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SharedSetlist.php';

            /* Determine ID: use existing if updating, generate new otherwise */
            $shareId  = null;
            $existing = null;
            if (!empty($body['id'])) {
                $candidateId = preg_replace('/[^a-f0-9]/', '', strtolower(trim($body['id'])));
                if ($candidateId !== '') {
                    $existing = sharedSetlistGet($candidateId);
                    if ($existing === null) {
                        sendJson(['error' => 'Shared set list not found.'], 404);
                        break;
                    }
                    if (($existing['owner'] ?? '') !== $ownerId) {
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
                    if (!is_string($sid) || !preg_match('/^[A-Za-z]+-\d+$/', $sid)) continue;
                    if (!is_array($arr)) continue;
                    $validArr = array_values(array_filter($arr, fn($v) => is_int($v) && $v >= 0));
                    if (count($validArr) > 0) {
                        $arrangements[$sid] = $validArr;
                    }
                }
            }

            /* Build the shared setlist object */
            $now      = gmdate('c');
            $isUpdate = ($shareId !== null);
            $shareData = [
                'name'    => $setlistName,
                'songs'   => $setlistSongs,
                'owner'   => $ownerId,
                'created' => $isUpdate ? ($existing['created'] ?? $now) : $now,
                'updated' => $now,
                'version' => 2,
            ];
            if (!empty($arrangements)) {
                $shareData['arrangements'] = $arrangements;
            }

            if ($isUpdate) {
                $shareData['id'] = $shareId;
                if (!sharedSetlistUpdate($shareId, $shareData)) {
                    sendJson(['error' => 'Failed to save shared set list.'], 500);
                    break;
                }
            } else {
                /* Generate a fresh 8-char hex ID with retry on collision */
                $created = false;
                for ($i = 0; $i < 10; $i++) {
                    $shareId         = bin2hex(random_bytes(4));
                    $shareData['id'] = $shareId;
                    $result = sharedSetlistInsert($shareId, $shareData);
                    if ($result === true)  { $created = true;  break; }
                    if ($result === null)  { /* hard failure */ break; }
                    /* false = collision; try a new ID */
                }
                if (!$created) {
                    sendJson(['error' => 'Unable to save shared set list. Try again.'], 500);
                    break;
                }
            }

            /* Audit (#535) — distinct rows for create vs update so the
               timeline reads correctly. song_count helps spot the
               "shared an empty list by accident" cases. */
            logActivity(
                $isUpdate ? 'setlist.share_update' : 'setlist.share_create',
                'setlist',
                $shareId,
                [
                    'name'        => $setlistName,
                    'song_count'  => count($setlistSongs),
                    'has_arrangements' => !empty($arrangements),
                ]
            );

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

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SharedSetlist.php';

            $data = sharedSetlistGet($shareId);
            if ($data === null) {
                sendJson(['error' => 'Shared set list not found.'], 404);
                break;
            }
            sharedSetlistMarkViewed($shareId);

            /* Return public-safe fields only (exclude owner UUID) */
            $response = [
                'id'      => $data['id'] ?? $shareId,
                'name'    => $data['name'] ?? 'Untitled',
                'songs'   => $data['songs'] ?? [],
                'created' => $data['created'] ?? null,
                'updated' => $data['updated'] ?? null,
            ];
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

            /* Check registration mode (#236) */
            $db = getDbMysqli();
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $regModeKey = 'registration_mode';
            $stmt->bind_param('s', $regModeKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $regMode = (string)($row[0] ?? '') ?: 'open';

            /* Check if any users exist (first user always allowed for initial setup) */
            $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers');
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $userCount = (int)($row[0] ?? 0);

            if ($userCount > 0 && $regMode === 'admin_only') {
                /* Only admins can create accounts — check if requester is admin */
                $authUser = getAuthenticatedUser();
                if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                    sendJson(['error' => 'Registration is restricted to administrators.'], 403);
                    break;
                }
            }

            /* Rate limit registrations: count recent attempts (any
               outcome) from this IP. tblLoginAttempts is the rate-limit
               source of truth.
               Removed: a leftover SELECT that joined `tblUsers` to
               `tblLoginAttempts.UserId` — `tblLoginAttempts` has no
               `UserId` column (the table tracks attempts by IP, not by
               user; see the schema). The query was documented as
               "intentionally left un-fetched" but PHP 8.1+ throws on
               execute() when columns are missing, so every
               registration POST 500'd with `Unknown column 'UserId' in
               'field list'`. The fallback below was already doing the
               rate-limit work correctly. */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblLoginAttempts
                 WHERE IpAddress = ? AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $stmt->bind_param('s', $clientIp);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $recentAttempts = (int)($row[0] ?? 0);
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

            /* $db is the same mysqli connection as above (getDbMysqli
               is a singleton). Re-fetch for clarity rather than reuse. */
            $db = getDbMysqli();

            /* Check if username already exists */
            $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Username = ?');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($exists) {
                sendJson(['error' => 'Username already taken.'], 409);
                break;
            }

            /* Auto-assign 'global_admin' to the very first registered user;
             * all subsequent public registrations get 'user' role */
            $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers');
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $role = ((int)($row[0] ?? 0) === 0) ? 'global_admin' : 'user';

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $displayTrimmed = mb_substr($displayName, 0, 100);
            $stmt = $db->prepare('INSERT INTO tblUsers (Username, PasswordHash, DisplayName, Role) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $username, $hash, $displayTrimmed, $role);
            $stmt->execute();
            $userId = (int)$db->insert_id;
            $stmt->close();

            /* Generate API token (64-character hex string, 30-day expiry) */
            $token = bin2hex(random_bytes(32));
            $expiresAtTs = time() + 30 * 86400;
            $expiresAt   = gmdate('c', $expiresAtTs);
            $tokenHash = hash('sha256', $token);
            $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
            $stmt->bind_param('sis', $tokenHash, $userId, $expiresAt);
            $stmt->execute();
            $stmt->close();

            /* Cross-subdomain cookie — keeps sign-in state on every
               *.ihymns.app subdomain and survives iOS ITP longer than
               script-accessible storage (#390). */
            setAuthTokenCookie($token, $expiresAtTs);

            /* Audit (#535). The bearer token is NOT logged per the
               privacy policy; only its sha256 prefix as a debugging
               handle. PasswordHash is also kept out of Details. */
            logActivity(
                'auth.register',
                'user',
                (string)$userId,
                [
                    'username'     => $username,
                    'display_name' => $displayName,
                    'role'         => $role,
                    'token_prefix' => substr(hash('sha256', $token), 0, 12),
                ],
                'success',
                $userId
            );

            sendJson([
                'token' => $token,
                'user'  => [
                    'id'             => $userId,
                    'username'       => $username,
                    'display_name'   => $displayName,
                    'role'           => $role,
                    'avatar_service' => null,   /* #616 — fresh registration inherits project default */
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

            $db = getDbMysqli();

            /* Brute force protection: check recent failed attempts from this IP (#290) */
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblLoginAttempts
                 WHERE IpAddress = ? AND Success = 0
                 AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
            );
            $stmt->bind_param('s', $clientIp);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $recentFailures = (int)($row[0] ?? 0);

            if ($recentFailures >= 10) {
                logActivity(
                    'auth.login',
                    'user',
                    $username,
                    ['reason' => 'rate_limited', 'recent_failures' => $recentFailures],
                    'failure'
                );
                sendJson(['error' => 'Too many failed login attempts. Please try again later.'], 429);
                break;
            }

            /* AvatarService (#616) only included when the column exists
               so a partly-migrated install can still log users in. */
            $hasAvatarSvcCol = false;
            $colCheck = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblUsers'
                    AND COLUMN_NAME  = 'AvatarService' LIMIT 1"
            );
            if ($colCheck && $colCheck->fetch_row() !== null) { $hasAvatarSvcCol = true; }
            if ($colCheck) { $colCheck->close(); }
            $avatarSvcSel = $hasAvatarSvcCol ? ', AvatarService' : ', NULL AS AvatarService';
            $stmt = $db->prepare("SELECT Id, Username, PasswordHash, DisplayName, Role, IsActive {$avatarSvcSel} FROM tblUsers WHERE Username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($password, $user['PasswordHash'])) {
                /* Log failed attempt to tblLoginAttempts (used by the
                   brute-force counter above) AND to tblActivityLog
                   (#535). The two stay in sync because they record
                   slightly different things — tblLoginAttempts is
                   tight, indexed for fast brute-force lookup;
                   tblActivityLog carries richer context (RequestId,
                   UA, reason). */
                $stmt = $db->prepare(
                    'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 0)'
                );
                $stmt->bind_param('ss', $clientIp, $username);
                $stmt->execute();
                $stmt->close();

                logActivity(
                    'auth.login',
                    'user',
                    $username,
                    [
                        'reason'   => $user ? 'wrong_password' : 'unknown_user',
                        'username' => $username,
                    ],
                    'failure',
                    $user ? (int)$user['Id'] : null
                );

                sendJson(['error' => 'Invalid username or password.'], 401);
                break;
            }

            if (!$user['IsActive']) {
                logActivity(
                    'auth.login',
                    'user',
                    (string)$user['Id'],
                    ['reason' => 'account_disabled', 'username' => $user['Username']],
                    'failure',
                    (int)$user['Id']
                );
                sendJson(['error' => 'Account is disabled.'], 403);
                break;
            }

            /* Log successful login attempt */
            $stmt = $db->prepare(
                'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 1)'
            );
            $stmt->bind_param('ss', $clientIp, $username);
            $stmt->execute();
            $stmt->close();

            /* Update last login timestamp and count */
            $userIdInt = (int)$user['Id'];
            $stmt = $db->prepare(
                'UPDATE tblUsers SET LastLoginAt = NOW(), LoginCount = LoginCount + 1 WHERE Id = ?'
            );
            $stmt->bind_param('i', $userIdInt);
            $stmt->execute();
            $stmt->close();

            /* Generate API token */
            $token = bin2hex(random_bytes(32));
            $expiresAtTs = time() + 30 * 86400;
            $expiresAt   = gmdate('c', $expiresAtTs);
            $tokenHash = hash('sha256', $token);
            $stmt = $db->prepare('INSERT INTO tblApiTokens (Token, UserId, ExpiresAt) VALUES (?, ?, ?)');
            $stmt->bind_param('sis', $tokenHash, $userIdInt, $expiresAt);
            $stmt->execute();
            $stmt->close();

            /* Cross-subdomain cookie (#390) */
            setAuthTokenCookie($token, $expiresAtTs);

            /* Audit (#535) — bearer token never enters Details, only
               its sha256 prefix as a debug handle. */
            logActivity(
                'auth.login',
                'user',
                (string)$user['Id'],
                [
                    'username'     => $user['Username'],
                    'role'         => $user['Role'],
                    'token_prefix' => substr(hash('sha256', $token), 0, 12),
                ],
                'success',
                (int)$user['Id']
            );

            sendJson([
                'token' => $token,
                'user'  => [
                    'id'             => (int)$user['Id'],
                    'username'       => $user['Username'],
                    'display_name'   => $user['DisplayName'],
                    'role'           => $user['Role'],
                    'avatar_service' => $user['AvatarService'] ?? null,   /* #616 */
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
            $authedUser = $token ? getAuthenticatedUser() : null;
            if ($token) {
                $db = getDbMysqli();
                $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE Token = ?');
                $tokenHash = hash('sha256', $token);
                $stmt->bind_param('s', $tokenHash);
                $stmt->execute();
                $stmt->close();
            }

            /* Also clear the auth cookie (#390) so a subsequent page load
               on any iHymns subdomain is properly signed-out. */
            clearAuthTokenCookie();

            /* Audit (#535) — only when we actually had a token; an
               unauthenticated logout is a no-op and not worth a row. */
            if ($authedUser) {
                logActivity(
                    'auth.logout',
                    'user',
                    (string)$authedUser['Id'],
                    [
                        'username'     => $authedUser['Username'] ?? null,
                        'token_prefix' => substr(hash('sha256', $token), 0, 12),
                    ],
                    'success',
                    (int)$authedUser['Id']
                );
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
                    'id'             => $authUser['Id'],
                    'username'       => $authUser['Username'],
                    'display_name'   => $authUser['DisplayName'],
                    'role'           => $authUser['Role'],
                    'avatar_service' => $authUser['AvatarService'] ?? null,   /* #616 */
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

            $db = getDbMysqli();
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ? ORDER BY UpdatedAt DESC');
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            $db = getDbMysqli();
            $userId = (int)$authUser['Id'];

            /* Fetch all existing server-side setlists for this user */
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $serverRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $serverMap = [];
            foreach ($serverRows as $row) {
                $serverMap[$row['SetlistId']] = $row;
            }

            $now = gmdate('c');

            /* Upsert each local setlist. The (UserId, SetlistId) pair
               has a UNIQUE constraint on tblUserSetlists, so
               ON DUPLICATE KEY UPDATE fires on collision and rewrites
               the user-editable columns. Fixes #568 — prior to this the
               SQL used PostgreSQL/SQLite ON CONFLICT syntax which MySQL
               doesn't recognise, silently breaking multi-device sync. */
            $upsert = $db->prepare(
                'INSERT INTO tblUserSetlists (UserId, SetlistId, Name, SongsJson, CreatedAt, UpdatedAt)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    Name      = VALUES(Name),
                    SongsJson = VALUES(SongsJson),
                    UpdatedAt = VALUES(UpdatedAt)'
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
                $createdAt = (string)($list['createdAt'] ?? $now);

                $upsert->bind_param('isssss',
                    $userId, $setlistId, $name, $songsJson, $createdAt, $now);
                $upsert->execute();

                /* Remove from server map so we know which are server-only */
                unset($serverMap[$setlistId]);
            }
            $upsert->close();

            /* Fetch the merged result (all setlists for this user) */
            $stmt = $db->prepare('SELECT SetlistId, Name, SongsJson, CreatedAt, UpdatedAt FROM tblUserSetlists WHERE UserId = ? ORDER BY UpdatedAt DESC');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $mergedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

        /* -----------------------------------------------------------------
         * User app settings — synced across the user's signed-in devices
         *
         * GET   /api?action=user_settings
         *   → { ok: true, settings: { … }, updated_at: '…' }
         * POST  /api?action=user_settings
         *   body: { settings: { theme: 'dark', fontSize: 18, … } }
         *   → { ok: true }
         *
         * Stored as a JSON blob in tblUsers.Settings; the client decides
         * which keys are syncable (a strict whitelist in settings.js) so
         * we never mirror device-local prefs (analytics consent, install
         * banner state, etc.) onto the server.
         * ----------------------------------------------------------------- */
        case 'user_settings':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }
            $db = getDbMysqli();

            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $db->prepare('SELECT Settings, UpdatedAt FROM tblUsers WHERE Id = ?');
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $settingsRaw = $row['Settings'] ?? null;
                $settings = is_string($settingsRaw) && $settingsRaw !== ''
                    ? (json_decode($settingsRaw, true) ?: new \stdClass())
                    : new \stdClass();
                sendJson([
                    'ok'         => true,
                    'settings'   => $settings,
                    'updated_at' => $row['UpdatedAt'] ?? null,
                ]);
                break;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $rawBody = file_get_contents('php://input');
                $body = json_decode($rawBody, true);
                $settings = $body['settings'] ?? null;
                if (!is_array($settings)) {
                    sendJson(['error' => 'Settings object required.'], 400);
                    break;
                }
                /* Cap payload size — prefs are small; this guards against abuse. */
                $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (strlen($json) > 16384) {
                    sendJson(['error' => 'Settings payload too large.'], 413);
                    break;
                }
                $stmt = $db->prepare('UPDATE tblUsers SET Settings = ?, UpdatedAt = NOW() WHERE Id = ?');
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('si', $json, $authUserId);
                $stmt->execute();
                $stmt->close();
                sendJson(['ok' => true]);
                break;
            }

            sendJson(['error' => 'GET or POST method required.'], 405);
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

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

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

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

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

            /* Check if email service is configured (#339) */
            $db = getDbMysqli();
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $key = 'email_service';
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $emailService = (string)($row[0] ?? '') ?: 'none';
            if ($emailService === 'none') {
                sendJson(['error' => 'Email login is not available. No email service is configured.'], 503);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

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
                /* Rate limited or no-such-account — still return 200 to
                   prevent enumeration. The audit row records the
                   rate-limit reason WITHOUT leaking which case it
                   actually was; the resolver inside
                   generateEmailLoginToken() already differentiates
                   in its own log row. (#535) */
                logActivity(
                    'auth.login_email_request',
                    '',
                    '',
                    ['email' => $requestEmail, 'reason' => 'rate_limited_or_no_account'],
                    'failure'
                );
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

            /* Audit (#535). Token + code are NOT logged per privacy
               policy; only their hash prefix as a debug handle and
               the email so admins can correlate "did the email
               actually go out". */
            logActivity(
                'auth.login_email_request',
                'user',
                (string)($result['userId'] ?? ''),
                [
                    'email'        => $requestEmail,
                    'token_prefix' => isset($result['token']) ? substr(hash('sha256', (string)$result['token']), 0, 12) : null,
                ],
                'success',
                isset($result['userId']) ? (int)$result['userId'] : null
            );

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

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

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
                logActivity(
                    'auth.login_email_verify',
                    '',
                    '',
                    [
                        'mode'   => $verifyToken !== '' ? 'magic_link' : 'code',
                        'email'  => $verifyEmail,
                        'reason' => 'invalid_or_expired',
                    ],
                    'failure'
                );
                sendJson(['error' => 'Invalid or expired login code. Please request a new one.'], 401);
                break;
            }

            /* Complete the login: find/create user, generate bearer token */
            $loginResult = completeEmailLogin($verified['email'], $verified['userId']);

            /* Cross-subdomain auth cookie (#390) — completeEmailLogin()
               issues a 30-day token in tblApiTokens; mirror that lifetime
               on the browser cookie so the two stay in sync. */
            if (!empty($loginResult['token'])) {
                setAuthTokenCookie($loginResult['token'], time() + 30 * 86400);
            }

            /* Log the successful login */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 1)'
            );
            $loginUsername = $loginResult['user']['username'];
            $stmt->bind_param('ss', $clientIp, $loginUsername);
            $stmt->execute();
            $stmt->close();

            /* Audit (#535). Mode + email captured so admins can answer
               "is the magic-link path being used at all?" Token never
               leaves the response. */
            $loginUserId = isset($loginResult['user']['id']) ? (int)$loginResult['user']['id'] : null;
            logActivity(
                'auth.login_email_verify',
                'user',
                (string)($loginUserId ?? ''),
                [
                    'mode'         => $verifyToken !== '' ? 'magic_link' : 'code',
                    'email'        => $verified['email'],
                    'username'     => $loginResult['user']['username'] ?? null,
                    'token_prefix' => isset($loginResult['token']) ? substr(hash('sha256', (string)$loginResult['token']), 0, 12) : null,
                ],
                'success',
                $loginUserId
            );

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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT SongId FROM tblUserFavorites WHERE UserId = ? ORDER BY CreatedAt DESC'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $rows = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'SongId');
            $stmt->close();

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

            $db = getDbMysqli();
            $userId = (int)$authUser['Id'];

            /* Sanitise incoming song IDs */
            $localFavs = array_values(array_filter(
                array_map('trim', $body['favorites']),
                fn($s) => preg_match('/^[A-Za-z]+-\d+$/', $s)
            ));

            /* Cap at 500 favorites */
            $localFavs = array_slice($localFavs, 0, 500);

            /* Get existing server favorites */
            $stmt = $db->prepare('SELECT SongId FROM tblUserFavorites WHERE UserId = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $serverFavs = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'SongId');
            $stmt->close();

            /* Merge: union of local and server */
            $merged = array_unique(array_merge($serverFavs, $localFavs));

            /* Insert any new favorites (ignore duplicates). Reused
               prepared statement with $songId as a bound-by-reference
               variable that we update each iteration. */
            $insert = $db->prepare(
                'INSERT IGNORE INTO tblUserFavorites (UserId, SongId) VALUES (?, ?)'
            );
            $songIdBound = '';
            $insert->bind_param('is', $userId, $songIdBound);
            $newlyAdded = 0;
            foreach ($localFavs as $songId) {
                $songIdBound = $songId;
                $insert->execute();
                $newlyAdded += $insert->affected_rows;
            }
            $insert->close();

            /* Return merged list */
            $stmt = $db->prepare(
                'SELECT SongId FROM tblUserFavorites WHERE UserId = ? ORDER BY CreatedAt DESC'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $finalFavs = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'SongId');
            $stmt->close();

            /* Audit (#535) — only one row per sync, not per favourite,
               since the sync is the meaningful user action. */
            if ($newlyAdded > 0) {
                logActivity('favorites.sync', 'user', (string)$userId, [
                    'newly_added' => $newlyAdded,
                    'total'       => count($finalFavs),
                ]);
            }

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

            $db = getDbMysqli();
            $stmt = $db->prepare('DELETE FROM tblUserFavorites WHERE UserId = ? AND SongId = ?');
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('is', $authUserId, $removeSongId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                logActivity('favorites.remove', 'song', $removeSongId, [], 'success', $authUserId);
            }

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

            $db = getDbMysqli();

            /* Check if song requests are enabled */
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $key = 'song_requests_enabled';
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $enabled = (string)($row[0] ?? '');
            if ($enabled === '0') {
                sendJson(['error' => 'Song requests are currently disabled.'], 403);
                break;
            }

            /* Rate limiting by IP */
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
            $key = 'max_song_requests_per_day';
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $maxPerDay = (int)((string)($row[0] ?? '') ?: '5');

            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM tblSongRequests
                 WHERE IpAddress = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
            $stmt->bind_param('s', $clientIp);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ((int)($row[0] ?? 0) >= $maxPerDay) {
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
            $reqUserId = $authUser ? (int)$authUser['Id'] : null;

            $stmt = $db->prepare(
                'INSERT INTO tblSongRequests
                 (Title, Songbook, SongNumber, Language, Details, ContactEmail, UserId, IpAddress, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $status = 'pending';
            /* Types: 6 strings, UserId(i nullable — mysqli passes NULL
               correctly when bound var is null), IpAddress(s), Status(s). */
            $stmt->bind_param('ssssssiss',
                $reqTitle, $reqSongbook, $reqNumber, $reqLanguage,
                $reqDetails, $reqEmail, $reqUserId, $clientIp, $status);
            $stmt->execute();
            $requestId = (int)$db->insert_id;
            $stmt->close();

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

            $db = getDbMysqli();
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
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            sendJson(['requests' => $requests]);
            break;

        /* =================================================================
         * LANGUAGES & TRANSLATIONS (#281)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get all available languages
         *
         * After #738 the catalogue contains every IANA language subtag
         * (~8,000 rows). The picker still needs the most-common ones
         * to surface first, so we sort by:
         *   1. Scope: macrolanguages (zh, ar, fa, ms, …) before
         *      individual / collection / private-use / special.
         *   2. Name length: short names ("Spanish") before long ones
         *      ("Spanish Sign Language") so the common form wins.
         *   3. Name alphabetic for everything else.
         *
         * The Scope column was added by #738; older deployments may
         * not have it yet, so we probe and fall back to plain
         * alphabetic sort if absent.
         * ----------------------------------------------------------------- */
        case 'languages':
            $db = getDbMysqli();
            /* Probe Scope column once — pre-#738 deployments fall
               back to a plain alphabetic ORDER BY. */
            $hasScope = false;
            try {
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'tblLanguages'
                        AND COLUMN_NAME = 'Scope' LIMIT 1"
                );
                $probe->execute();
                $hasScope = $probe->get_result()->fetch_row() !== null;
                $probe->close();
            } catch (\Throwable $_e) { /* probe failed → no Scope */ }

            if ($hasScope) {
                $sql = "SELECT Code AS code, Name AS name, NativeName AS nativeName,
                               TextDirection AS textDirection, Scope AS scope
                          FROM tblLanguages
                         WHERE IsActive = 1
                         ORDER BY (Scope = 'macrolanguage') DESC,
                                  CHAR_LENGTH(Name) ASC,
                                  Name ASC";
            } else {
                $sql = "SELECT Code AS code, Name AS name, NativeName AS nativeName,
                               TextDirection AS textDirection
                          FROM tblLanguages
                         WHERE IsActive = 1
                         ORDER BY Name ASC";
            }
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $languages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            sendJson(['languages' => $languages]);
            break;

        /* -----------------------------------------------------------------
         * Get all available IETF BCP 47 scripts (#681 / #682, renamed
         * source table in #738)
         *
         * Mirrors `action=languages`: returns the full active list of
         * ISO 15924 script codes from tblLanguageScripts so native
         * clients (Apple / Android / FireOS) can render the same
         * composite IETF picker the web admin already uses. The
         * admin-only `action=script_search` typeahead on
         * /manage/songbooks? is the prefix-search variant; this
         * endpoint is a one-shot dump suitable for caching client-side.
         * Probes BOTH the new and legacy table names so a deployment
         * that hasn't applied #738 yet still serves results.
         * Pre-migration: empty list with a `note` rather than a 500.
         * ----------------------------------------------------------------- */
        case 'scripts':
            $db = getDbMysqli();
            $tableName = '';
            try {
                foreach (['tblLanguageScripts', 'tblScripts'] as $candidate) {
                    $probe = $db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
                    );
                    $probe->bind_param('s', $candidate);
                    $probe->execute();
                    $found = $probe->get_result()->fetch_row() !== null;
                    $probe->close();
                    if ($found) { $tableName = $candidate; break; }
                }
            } catch (\Throwable $_e) { /* probe failure → empty list */ }

            if ($tableName === '') {
                sendJson([
                    'scripts' => [],
                    'note'    => 'tblLanguageScripts not yet created — run /manage/setup-database',
                ]);
                break;
            }

            /* Identifier from the allowlisted probe — never user input. */
            $stmt = $db->prepare(
                "SELECT Code AS code, Name AS name, NativeName AS nativeName
                 FROM {$tableName}
                 WHERE IsActive = 1
                 ORDER BY Name ASC"
            );
            $stmt->execute();
            $scripts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            sendJson(['scripts' => $scripts]);
            break;

        /* -----------------------------------------------------------------
         * Get all available IETF BCP 47 regions (#681 / #682)
         *
         * Same shape as `action=scripts` for tblRegions — ISO 3166-1
         * alpha-2 codes plus M.49 numeric area codes (e.g. 419 = Latin
         * America & Caribbean). The list is bigger (~255 entries) so
         * native clients usually fetch it once at first launch and
         * cache. Pre-migration: empty list with a `note`.
         * ----------------------------------------------------------------- */
        case 'regions':
            $db = getDbMysqli();
            $hasTable = false;
            try {
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblRegions' LIMIT 1"
                );
                $probe->execute();
                $hasTable = $probe->get_result()->fetch_row() !== null;
                $probe->close();
            } catch (\Throwable $_e) { /* probe failure → empty list */ }

            if (!$hasTable) {
                sendJson([
                    'regions' => [],
                    'note'    => 'tblRegions not yet created — run /manage/setup-database',
                ]);
                break;
            }

            $stmt = $db->prepare(
                'SELECT Code AS code, Name AS name
                 FROM tblRegions
                 WHERE IsActive = 1
                 ORDER BY Name ASC'
            );
            $stmt->execute();
            $regions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            sendJson(['regions' => $regions]);
            break;

        /* -----------------------------------------------------------------
         * Get all available IETF BCP 47 language variants (#738)
         *
         * Same shape as `action=scripts` / `action=regions` for
         * tblLanguageVariants — IANA variant subtags (5-8 chars,
         * e.g. `1996` for German post-1996 orthography, `fonipa` for
         * IPA phonetics, `valencia` for Valencian). Used as the
         * optional fourth subtag in an IETF BCP 47 language tag.
         * Pre-migration: empty list with a `note`.
         * ----------------------------------------------------------------- */
        case 'variants':
            $db = getDbMysqli();
            $hasTable = false;
            try {
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLanguageVariants' LIMIT 1"
                );
                $probe->execute();
                $hasTable = $probe->get_result()->fetch_row() !== null;
                $probe->close();
            } catch (\Throwable $_e) { /* probe failure → empty list */ }

            if (!$hasTable) {
                sendJson([
                    'variants' => [],
                    'note'     => 'tblLanguageVariants not yet created — run /manage/setup-database',
                ]);
                break;
            }

            $stmt = $db->prepare(
                'SELECT Code AS code, Name AS name
                 FROM tblLanguageVariants
                 WHERE IsActive = 1
                 ORDER BY Name ASC'
            );
            $stmt->execute();
            $variants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            sendJson(['variants' => $variants]);
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

            $db = getDbMysqli();

            /*
             * Bidirectional lookup (#352):
             * 1. Forward: this song is the source → find its translations
             * 2. Reverse: this song is a translation → find the source + siblings
             */
            $translations = [];
            $seen = [$translationSongId => true]; /* avoid duplicates */

            /* Forward: translations OF this song. Statement is reused
               for the sibling-translations lookup further down via
               $sourceId — bind by reference so $forwardId can be
               re-assigned for the second execute. */
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
            $forwardId = $translationSongId;
            $stmt->bind_param('s', $forwardId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tr) {
                $tr['verified'] = (bool)$tr['verified'];
                $tr['number'] = (int)$tr['number'];
                $seen[$tr['songId']] = true;
                $translations[] = $tr;
            }

            /* Reverse: find the source song this is a translation of */
            $stmt2 = $db->prepare(
                'SELECT t.SourceSongId FROM tblSongTranslations t WHERE t.TranslatedSongId = ?'
            );
            $stmt2->bind_param('s', $translationSongId);
            $stmt2->execute();
            $sourceRow = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            if ($sourceRow) {
                $sourceId = (string)$sourceRow['SourceSongId'];

                /* Add the source song itself (if not already listed) */
                if (empty($seen[$sourceId])) {
                    $stmtSrc = $db->prepare(
                        'SELECT s.SongId AS songId, s.Language AS language,
                                s.Title AS title, s.Number AS number,
                                l.Name AS languageName, l.NativeName AS languageNativeName
                         FROM tblSongs s
                         LEFT JOIN tblLanguages l ON l.Code = s.Language
                         WHERE s.SongId = ?'
                    );
                    $stmtSrc->bind_param('s', $sourceId);
                    $stmtSrc->execute();
                    $src = $stmtSrc->get_result()->fetch_assoc();
                    $stmtSrc->close();
                    if ($src) {
                        $src['translator'] = '';
                        $src['verified'] = false;
                        $src['number'] = (int)$src['number'];
                        $seen[$sourceId] = true;
                        $translations[] = $src;
                    }
                }

                /* Add sibling translations (other translations of the same source).
                   Reuse $stmt (Forward statement) — re-assign the bound
                   variable, then re-execute. */
                $forwardId = $sourceId;
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tr) {
                    if (!empty($seen[$tr['songId']])) continue;
                    $tr['verified'] = (bool)$tr['verified'];
                    $tr['number'] = (int)$tr['number'];
                    $seen[$tr['songId']] = true;
                    $translations[] = $tr;
                }
            }
            $stmt->close();

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

            $db = getDbMysqli();

            /* Get all groups the user belongs to (primary + additional).
               $authUserId bound twice — once per `?` position. */
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
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('ii', $authUserId, $authUserId);
            $stmt->execute();
            $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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
            $db = getDbMysqli();
            /* Only fetch public-safe settings — never expose internal config.
               Dynamic IN-list with str_repeat for the bind type string. */
            $publicKeys = ['maintenance_mode', 'song_requests_enabled', 'motd', 'registration_mode', 'email_service', 'captcha_provider', 'ads_enabled'];
            $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
            $stmt = $db->prepare(
                "SELECT SettingKey, SettingValue FROM tblAppSettings WHERE SettingKey IN ({$placeholders})"
            );
            $stmt->bind_param(str_repeat('s', count($publicKeys)), ...$publicKeys);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['SettingKey']] = $row['SettingValue'];
            }

            sendJson([
                'maintenance'         => ($settings['maintenance_mode'] ?? '0') === '1',
                'songRequestsEnabled' => ($settings['song_requests_enabled'] ?? '1') === '1',
                'registrationMode'    => $settings['registration_mode'] ?? 'open',
                'motd'                => $settings['motd'] ?? '',
                'emailLoginEnabled'   => ($settings['email_service'] ?? 'none') !== 'none',
                'captchaProvider'     => $settings['captcha_provider'] ?? 'none',
                'adsEnabled'          => ($settings['ads_enabled'] ?? '0') === '1',
            ]);
            break;

        /* -----------------------------------------------------------------
         * Distinct primary language subtags actually present in the
         * catalogue (#776). Returns the union of:
         *   - tblSongbooks.Language primary subtag
         *   - tblSongs.Language primary subtag
         * The two-source union mirrors the home-page filter partial
         * (includes/partials/songbook-language-filter.php) so the
         * settings widget and the home filter expose the same chip set.
         *
         * Lightweight by design — the settings filter previously hit
         * /api?action=songbooks and got back the full row payload
         * (KB to MB depending on catalogue size). This response is
         * a few dozen bytes and is cacheable for 5 minutes since
         * subtags rarely change.
         *
         * Response: { subtags: ['en', 'es', 'fr', ...] }
         *           (lowercase, sorted, de-duplicated)
         * ----------------------------------------------------------------- */
        case 'catalogue_language_subtags':
            $db = getDbMysqli();
            $subtags = [];

            /* Source 1 — tblSongbooks.Language. Primary subtag only. */
            try {
                $res = $db->query(
                    "SELECT DISTINCT LOWER(SUBSTRING_INDEX(Language, '-', 1)) AS sub
                       FROM tblSongbooks
                      WHERE Language IS NOT NULL AND Language <> ''"
                );
                if ($res) {
                    while ($r = $res->fetch_row()) {
                        $sub = (string)$r[0];
                        if (preg_match('/^[a-z]{2,3}$/', $sub)) {
                            $subtags[$sub] = true;
                        }
                    }
                    $res->close();
                }
            } catch (\Throwable $_e) { /* best-effort */ }

            /* Source 2 — tblSongs.Language. Probe column existence so
               a pre-#681 deployment doesn't 500. */
            try {
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblSongs'
                        AND COLUMN_NAME  = 'Language' LIMIT 1"
                );
                $probe->execute();
                $hasLangCol = $probe->get_result()->fetch_row() !== null;
                $probe->close();
                if ($hasLangCol) {
                    $res = $db->query(
                        "SELECT DISTINCT LOWER(SUBSTRING_INDEX(Language, '-', 1)) AS sub
                           FROM tblSongs
                          WHERE Language IS NOT NULL AND Language <> ''"
                    );
                    if ($res) {
                        while ($r = $res->fetch_row()) {
                            $sub = (string)$r[0];
                            if (preg_match('/^[a-z]{2,3}$/', $sub)) {
                                $subtags[$sub] = true;
                            }
                        }
                        $res->close();
                    }
                }
            } catch (\Throwable $_e) { /* best-effort */ }

            $list = array_keys($subtags);
            sort($list);

            /* Cache for 5 min (subtag set rarely changes). The Vary
               header is conservative — same result for every visitor
               regardless of auth state. */
            header('Cache-Control: public, max-age=300');
            sendJson(['subtags' => $list]);
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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'UPDATE tblUsers SET DisplayName = ?, Email = ?, UpdatedAt = NOW() WHERE Id = ?'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('ssi', $newDisplayName, $newEmail, $authUserId);
            $stmt->execute();
            $stmt->close();

            sendJson([
                'ok'   => true,
                'user' => [
                    'id'             => $authUser['Id'],
                    'username'       => $authUser['Username'],
                    'display_name'   => $newDisplayName,
                    'email'          => $newEmail,
                    'role'           => $authUser['Role'],
                    'avatar_service' => $authUser['AvatarService'] ?? null,   /* #616 */
                ],
            ]);
            break;

        /* -----------------------------------------------------------------
         * Update the authenticated user's avatar-service preference (#616).
         *
         * POST body (JSON): { "avatar_service": "gravatar"|"libravatar"
         *                                     |"dicebear"|"none"|null }
         * NULL clears the override (= inherit project default).
         * Requires: Authorization: Bearer <token>
         * ----------------------------------------------------------------- */
        case 'auth_update_avatar_service':
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
            $svc  = $body['avatar_service'] ?? null;

            /* Allowed: NULL ('inherit project default') or one of the
               recognised resolver names. Mirrors USER_AVATAR_SERVICES
               in includes/avatar.php — kept hand-typed here so the API
               doesn't require the helper to be loaded. */
            $allowed = ['gravatar', 'libravatar', 'dicebear', 'none'];
            if ($svc !== null) {
                if (!is_string($svc)) {
                    sendJson(['error' => 'avatar_service must be a string or null.'], 400);
                    break;
                }
                $svc = strtolower(trim($svc));
                if ($svc === '') {
                    $svc = null;
                } elseif (!in_array($svc, $allowed, true)) {
                    sendJson(['error' => 'avatar_service must be one of: ' . implode(', ', $allowed) . ', or null.'], 400);
                    break;
                }
            }

            $db = getDbMysqli();
            $colCheck = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblUsers'
                    AND COLUMN_NAME  = 'AvatarService' LIMIT 1"
            );
            $hasCol = ($colCheck && $colCheck->fetch_row() !== null);
            if ($colCheck) { $colCheck->close(); }
            if (!$hasCol) {
                sendJson([
                    'error' => 'Avatar-service preference not yet enabled — administrator must apply migrate-user-avatar-service first.',
                ], 503);
                break;
            }

            $stmt = $db->prepare(
                'UPDATE tblUsers SET AvatarService = ?, UpdatedAt = NOW() WHERE Id = ?'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('si', $svc, $authUserId);
            $stmt->execute();
            $stmt->close();

            sendJson([
                'ok'             => true,
                'avatar_service' => $svc,
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

            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];
            $stmt = $db->prepare('SELECT PasswordHash FROM tblUsers WHERE Id = ?');
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $hash = (string)($row[0] ?? '');

            if (!password_verify($currentPw, $hash)) {
                sendJson(['error' => 'Current password is incorrect.'], 401);
                break;
            }

            $newHash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('UPDATE tblUsers SET PasswordHash = ?, UpdatedAt = NOW() WHERE Id = ?');
            $stmt->bind_param('si', $newHash, $authUserId);
            $stmt->execute();
            $stmt->close();

            /* Invalidate all OTHER tokens (keep the current one) */
            $currentToken = getAuthBearerToken();
            $currentTokenHash = hash('sha256', $currentToken);
            $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE UserId = ? AND Token != ?');
            $stmt->bind_param('is', $authUserId, $currentTokenHash);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true, 'message' => 'Password changed successfully.']);
            break;

        /* -----------------------------------------------------------------
         * Change authenticated user's username (self-service)
         *
         * POST body (JSON):
         *   { "new_username": "...", "current_password": "..." }
         * Requires: Authorization: Bearer <token> + correct current password.
         * Validation mirrors auth_register: lowercase, [a-z0-9_.\-], 3–100 chars.
         * Username is UNIQUE; a 409 is returned if taken.
         * ----------------------------------------------------------------- */
        case 'auth_change_username':
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

            $newUsername = mb_strtolower(trim($body['new_username'] ?? ''));
            $currentPw   = $body['current_password'] ?? '';

            if (strlen($newUsername) < 3
                || strlen($newUsername) > 100
                || !preg_match('/^[a-z0-9_.\-]+$/', $newUsername)) {
                logActivity(
                    'auth.username_change',
                    'user',
                    (string)$authUser['Id'],
                    ['reason' => 'invalid_format', 'attempted' => $newUsername],
                    'failure',
                    (int)$authUser['Id']
                );
                sendJson(['error' => 'Username must be 3–100 characters (letters, numbers, _, -, . only).'], 400);
                break;
            }
            if ($newUsername === mb_strtolower($authUser['Username'])) {
                sendJson(['error' => 'New username matches your current username.'], 400);
                break;
            }

            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];

            /* Verify current password before allowing rename */
            $stmt = $db->prepare('SELECT PasswordHash, Email, DisplayName FROM tblUsers WHERE Id = ?');
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || !password_verify($currentPw, $row['PasswordHash'])) {
                logActivity(
                    'auth.username_change',
                    'user',
                    (string)$authUserId,
                    ['reason' => 'wrong_password'],
                    'failure',
                    $authUserId
                );
                sendJson(['error' => 'Current password is incorrect.'], 401);
                break;
            }

            /* Uniqueness check (case-insensitive via lowercased compare) */
            $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Username = ? AND Id <> ?');
            $stmt->bind_param('si', $newUsername, $authUserId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($exists) {
                logActivity(
                    'auth.username_change',
                    'user',
                    (string)$authUserId,
                    ['reason' => 'taken', 'attempted' => $newUsername],
                    'failure',
                    $authUserId
                );
                sendJson(['error' => 'Username is already taken.'], 409);
                break;
            }

            $stmt = $db->prepare('UPDATE tblUsers SET Username = ?, UpdatedAt = NOW() WHERE Id = ?');
            $stmt->bind_param('si', $newUsername, $authUserId);
            $stmt->execute();
            $stmt->close();

            /* Audit (#535). before/after gives the timeline both halves
               of the rename without joining anything. */
            logActivity(
                'auth.username_change',
                'user',
                (string)$authUser['Id'],
                [
                    'before' => ['username' => $authUser['Username']],
                    'after'  => ['username' => $newUsername],
                ],
                'success',
                (int)$authUser['Id']
            );

            sendJson([
                'ok'   => true,
                'user' => [
                    'id'           => $authUser['Id'],
                    'username'     => $newUsername,
                    'display_name' => $row['DisplayName'],
                    'email'        => $row['Email'] ?? '',
                    'role'         => $authUser['Role'],
                ],
            ]);
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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT u.Id AS id, u.Username AS username, u.Email AS email,
                        u.DisplayName AS display_name, u.Role AS role,
                        u.IsActive AS is_active, u.CreatedAt AS created_at,
                        g.Name AS group_name
                 FROM tblUsers u
                 LEFT JOIN tblUserGroups g ON g.Id = u.GroupId
                 ORDER BY u.CreatedAt ASC'
            );
            $stmt->execute();
            $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT Id AS id, Name AS name, Description AS description,
                        AccessAlpha AS accessAlpha, AccessBeta AS accessBeta,
                        AccessRc AS accessRc, AccessRtw AS accessRtw
                 FROM tblUserGroups
                 ORDER BY Name ASC'
            );
            $stmt->execute();
            $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
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

            $db = getDbMysqli();
            $logLimit  = min((int)($_GET['limit'] ?? 50), 200);
            $logOffset = max((int)($_GET['offset'] ?? 0), 0);

            /* mysqli needs $types in lock-step with $params; track
               both as the WHERE clause is built. */
            $where  = [];
            $params = [];
            $types  = '';

            /* Filters (#535) — extended to cover the new columns:
                 action_filter   exact-match Action
                 user_id         numeric UserId
                 result          'success' / 'failure' / 'error'
                 entity_type     'song' / 'user' / 'songbook' / etc
                 entity_id       exact entity primary key
                 request_id      pull every row from one HTTP request
                 since           UTC ISO-8601 — rows newer than this
                 q               substring match against Action OR EntityId
            */
            if (!empty($_GET['action_filter'])) {
                $where[]  = 'a.Action = ?';
                $params[] = trim($_GET['action_filter']);
                $types   .= 's';
            }
            if (!empty($_GET['user_id'])) {
                $where[]  = 'a.UserId = ?';
                $params[] = (int)$_GET['user_id'];
                $types   .= 'i';
            }
            if (!empty($_GET['result'])
                && in_array($_GET['result'], ['success', 'failure', 'error'], true)) {
                $where[]  = 'a.Result = ?';
                $params[] = $_GET['result'];
                $types   .= 's';
            }
            if (!empty($_GET['entity_type'])) {
                $where[]  = 'a.EntityType = ?';
                $params[] = trim($_GET['entity_type']);
                $types   .= 's';
            }
            if (!empty($_GET['entity_id'])) {
                $where[]  = 'a.EntityId = ?';
                $params[] = trim($_GET['entity_id']);
                $types   .= 's';
            }
            if (!empty($_GET['request_id'])) {
                $where[]  = 'a.RequestId = ?';
                $params[] = trim($_GET['request_id']);
                $types   .= 's';
            }
            if (!empty($_GET['since'])) {
                $where[]  = 'a.CreatedAt >= ?';
                $params[] = trim($_GET['since']);
                $types   .= 's';
            }
            if (!empty($_GET['q'])) {
                $like = '%' . trim($_GET['q']) . '%';
                $where[]  = '(a.Action LIKE ? OR a.EntityId LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $types   .= 'ss';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $params[] = $logLimit;
            $params[] = $logOffset;
            $types   .= 'ii';

            $stmt = $db->prepare(
                "SELECT a.Id AS id, a.Action AS action, a.EntityType AS entityType,
                        a.EntityId AS entityId, a.Result AS result, a.Details AS details,
                        a.IpAddress AS ipAddress, a.UserAgent AS userAgent,
                        a.RequestId AS requestId, a.Method AS method,
                        a.DurationMs AS durationMs, a.CreatedAt AS createdAt,
                        u.Username AS username
                 FROM tblActivityLog a
                 LEFT JOIN tblUsers u ON u.Id = a.UserId
                 {$whereClause}
                 ORDER BY a.CreatedAt DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($entries as &$e) {
                $e['id'] = (int)$e['id'];
                $e['durationMs'] = $e['durationMs'] !== null ? (int)$e['durationMs'] : null;
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

            $db = getDbMysqli();
            $params = [];
            $types  = '';
            $where  = '';

            if (!empty($_GET['status'])) {
                $where    = 'WHERE r.Status = ?';
                $params[] = trim($_GET['status']);
                $types   .= 's';
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
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'UPDATE tblSongRequests
                 SET Status = ?, AdminNotes = ?, ResolvedSongId = ?, UpdatedAt = NOW()
                 WHERE Id = ?'
            );
            $resolvedOrNull = $resolved !== '' ? $resolved : null;
            $stmt->bind_param('sssi', $newStatus, $notes, $resolvedOrNull, $reqId);
            $stmt->execute();
            $stmt->close();

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

            $db = getDbMysqli();
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
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            $db = getDbMysqli();
            if ($orgId > 0) {
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            ParentOrgId AS parentOrgId, Description AS description,
                            LicenceType AS licenceType
                     FROM tblOrganisations WHERE Id = ? AND IsActive = 1'
                );
                $stmt->bind_param('i', $orgId);
                $stmt->execute();
            } elseif ($orgSlug !== '') {
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            ParentOrgId AS parentOrgId, Description AS description,
                            LicenceType AS licenceType
                     FROM tblOrganisations WHERE Slug = ? AND IsActive = 1'
                );
                $stmt->bind_param('s', $orgSlug);
                $stmt->execute();
            } else {
                sendJson(['error' => 'Organisation id or slug required.'], 400);
                break;
            }

            $org = $stmt->get_result()->fetch_assoc();
            $stmt->close();
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
            $stmt->bind_param('i', $org['id']);
            $stmt->execute();
            $org['children'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            /* Get member count */
            $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = ?');
            $stmt->bind_param('i', $org['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $org['memberCount'] = (int)($row[0] ?? 0);

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

            $db = getDbMysqli();

            /* Ensure unique slug — reused prepared statement with $slug
               bound by reference; re-assign per iteration. */
            $baseSlug = $slug;
            $counter = 1;
            $slugStmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisations WHERE Slug = ?');
            $slugStmt->bind_param('s', $slug);
            while (true) {
                $slugStmt->execute();
                $row = $slugStmt->get_result()->fetch_row();
                if ((int)($row[0] ?? 0) === 0) break;
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $slugStmt->close();

            $stmt = $db->prepare(
                'INSERT INTO tblOrganisations (Name, Slug, ParentOrgId, Description)
                 VALUES (?, ?, ?, ?)'
            );
            /* ParentOrgId(i nullable) — mysqli passes NULL with type 'i' when var is null. */
            $stmt->bind_param('ssis', $orgName, $slug, $parentId, $orgDesc);
            $stmt->execute();
            $newOrgId = (int)$db->insert_id;
            $stmt->close();

            /* Creator becomes owner */
            $stmt = $db->prepare(
                'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role) VALUES (?, ?, ?)'
            );
            $authUserId = (int)$authUser['Id'];
            $ownerRole  = 'owner';
            $stmt->bind_param('iis', $authUserId, $newOrgId, $ownerRole);
            $stmt->execute();
            $stmt->close();

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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT Id AS id, EntityType AS entityType, EntityId AS entityId,
                        RestrictionType AS restrictionType, TargetType AS targetType,
                        TargetId AS targetId, Effect AS effect, Priority AS priority,
                        Reason AS reason, CreatedAt AS createdAt
                 FROM tblContentRestrictions
                 ORDER BY Priority DESC, CreatedAt DESC
                 LIMIT 200'
            );
            $stmt->execute();
            $restrictions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblContentRestrictions
                 (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            /* Six string columns + Priority(int) + Reason(string). */
            $entityType  = trim($body['entity_type']      ?? '');
            $entityIdStr = trim($body['entity_id']        ?? '');
            $restrType   = trim($body['restriction_type'] ?? '');
            $targetType  = trim($body['target_type']      ?? '');
            $targetIdStr = trim($body['target_id']        ?? '');
            $effect      = trim($body['effect']           ?? 'deny');
            $priority    = (int)($body['priority']        ?? 0);
            $reason      = trim($body['reason']           ?? '');
            $stmt->bind_param('ssssssis',
                $entityType, $entityIdStr, $restrType, $targetType,
                $targetIdStr, $effect, $priority, $reason);
            $stmt->execute();
            $newId = (int)$db->insert_id;
            $stmt->close();

            sendJson(['ok' => true, 'id' => $newId], 201);
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

            $db = getDbMysqli();
            $stmt = $db->prepare('DELETE FROM tblContentRestrictions WHERE Id = ?');
            $stmt->bind_param('i', $delId);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * CARD LAYOUT PERSONALISATION (#448)
         *
         * Surfaces covered: "dashboard" (= /manage/) and "home" (= /).
         * Read is public; save endpoints require auth + the appropriate
         * entitlement. Group-level veto (tblUserGroups.AllowCardReorder)
         * is enforced inside cardLayoutUserCanCustomise().
         * ================================================================= */

        case 'card_layout_get': {
            $surface = (string)($_GET['surface'] ?? '');
            if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
                sendJson(['error' => 'Invalid surface.'], 400);
                break;
            }
            $authUser = getAuthenticatedUser();
            $user = $authUser ? [
                'id'       => $authUser['Id'] ?? null,
                'role'     => $authUser['Role'] ?? null,
                'group_id' => $authUser['GroupId'] ?? null,
            ] : null;
            $default = cardLayoutDefault($surface);
            $override = $user ? cardLayoutUserOverride((int)($user['id'] ?? 0), $surface) : ['order' => [], 'hidden' => []];
            sendJson([
                'surface'   => $surface,
                'default'   => $default,
                'override'  => $override,
                'canCustomiseOwn' => cardLayoutUserCanCustomise($user),
                'canSetDefault'   => $user && userHasEntitlement('manage_default_card_layout', $user['role'] ?? null),
            ]);
            break;
        }

        case 'card_layout_save_user': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Auth required.'], 401); break; }
            $user = [
                'id'       => $authUser['Id'] ?? null,
                'role'     => $authUser['Role'] ?? null,
                'group_id' => $authUser['GroupId'] ?? null,
            ];
            if (!cardLayoutUserCanCustomise($user)) {
                sendJson(['error' => 'Customisation not permitted for this account.'], 403);
                break;
            }
            $body    = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $surface = (string)($body['surface'] ?? '');
            if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
                sendJson(['error' => 'Invalid surface.'], 400);
                break;
            }
            $ok = cardLayoutSaveUserOverride((int)$user['id'], $surface, [
                'order'  => $body['order']  ?? [],
                'hidden' => $body['hidden'] ?? [],
            ]);
            sendJson(['ok' => $ok]);
            break;
        }

        case 'card_layout_reset_user': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Auth required.'], 401); break; }
            $body    = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $surface = (string)($body['surface'] ?? '');
            if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
                sendJson(['error' => 'Invalid surface.'], 400);
                break;
            }
            $ok = cardLayoutClearUserOverride((int)$authUser['Id'], $surface);
            sendJson(['ok' => $ok]);
            break;
        }

        case 'card_layout_save_default': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !userHasEntitlement('manage_default_card_layout', $authUser['Role'] ?? null)) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }
            $body    = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $surface = (string)($body['surface'] ?? '');
            if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
                sendJson(['error' => 'Invalid surface.'], 400);
                break;
            }
            $ok = cardLayoutSaveDefault($surface, [
                'order'  => $body['order']  ?? [],
                'hidden' => $body['hidden'] ?? [],
            ]);
            sendJson(['ok' => $ok]);
            break;
        }

        /* =================================================================
         * SONG KEY & TRANSPOSITION (#298)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get the musical key info for a song
         * Parameters: id (required) — Song ID e.g. CP-0001
         * ----------------------------------------------------------------- */
        case 'song_key':
            $songKeyId = isset($_GET['id']) ? trim($_GET['id']) : '';
            if ($songKeyId === '') {
                sendJson(['error' => 'Song ID is required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT OriginalKey AS originalKey, Tempo AS tempo,
                        TimeSignature AS timeSignature
                 FROM tblSongKeys
                 WHERE SongId = ?'
            );
            $stmt->bind_param('s', $songKeyId);
            $stmt->execute();
            $keyData = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$keyData) {
                sendJson(['error' => 'No key data found for this song.'], 404);
                break;
            }

            $keyData['tempo'] = $keyData['tempo'] !== null ? (int)$keyData['tempo'] : null;

            sendJson(['key' => $keyData]);
            break;

        /* -----------------------------------------------------------------
         * Save/update the musical key info for a song (editor+ only)
         *
         * POST body (JSON):
         *   { "song_id": "CP-0001", "original_key": "G",
         *     "tempo": 120, "time_signature": "4/4" }
         * ----------------------------------------------------------------- */
        case 'song_key_save':
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

            $keySongId      = trim($body['song_id'] ?? '');
            $originalKey    = mb_substr(trim($body['original_key'] ?? ''), 0, 10);
            $tempo          = isset($body['tempo']) ? (int)$body['tempo'] : null;
            $timeSignature  = mb_substr(trim($body['time_signature'] ?? ''), 0, 10);

            if ($keySongId === '') {
                sendJson(['error' => 'song_id is required.'], 400);
                break;
            }
            if ($originalKey === '') {
                sendJson(['error' => 'original_key is required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblSongKeys (SongId, OriginalKey, Tempo, TimeSignature)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    OriginalKey = VALUES(OriginalKey),
                    Tempo = VALUES(Tempo),
                    TimeSignature = VALUES(TimeSignature)'
            );
            $tsOrNull = $timeSignature !== '' ? $timeSignature : null;
            $stmt->bind_param('ssis', $keySongId, $originalKey, $tempo, $tsOrNull);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * SETLIST SCHEDULING (#300)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get scheduled setlists within a date range
         * Parameters: from (date), to (date)
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'setlist_schedule':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $schedFrom = trim($_GET['from'] ?? '');
            $schedTo   = trim($_GET['to'] ?? '');

            if ($schedFrom === '' || $schedTo === '') {
                sendJson(['error' => 'Both from and to date parameters are required.'], 400);
                break;
            }

            /* Validate date format (YYYY-MM-DD) */
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedFrom) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedTo)) {
                sendJson(['error' => 'Dates must be in YYYY-MM-DD format.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT s.Id AS id, s.SetlistId AS setlistId,
                        s.ScheduledDate AS scheduledDate, s.Notes AS notes,
                        s.OrgId AS orgId, s.CreatedBy AS createdBy,
                        s.CreatedAt AS createdAt
                 FROM tblSetlistSchedule s
                 LEFT JOIN tblOrganisationMembers m ON m.OrgId = s.OrgId AND m.UserId = ?
                 WHERE s.ScheduledDate BETWEEN ? AND ?
                   AND (s.CreatedBy = ? OR m.UserId IS NOT NULL)
                 ORDER BY s.ScheduledDate ASC'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('issi', $authUserId, $schedFrom, $schedTo, $authUserId);
            $stmt->execute();
            $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($schedules as &$sched) {
                $sched['id'] = (int)$sched['id'];
                $sched['orgId'] = $sched['orgId'] ? (int)$sched['orgId'] : null;
                $sched['createdBy'] = (int)$sched['createdBy'];
            }
            unset($sched);

            sendJson(['schedules' => $schedules]);
            break;

        /* -----------------------------------------------------------------
         * Schedule a setlist for a specific date
         *
         * POST body (JSON):
         *   { "setlist_id": "...", "scheduled_date": "2026-04-20",
         *     "notes": "Sunday morning", "org_id": 1 }
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'setlist_schedule_save':
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

            $schedSetlistId = trim($body['setlist_id'] ?? '');
            $schedDate      = trim($body['scheduled_date'] ?? '');
            $schedNotes     = mb_substr(trim($body['notes'] ?? ''), 0, 1000);
            $schedOrgId     = !empty($body['org_id']) ? (int)$body['org_id'] : null;

            if ($schedSetlistId === '') {
                sendJson(['error' => 'setlist_id is required.'], 400);
                break;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) {
                sendJson(['error' => 'scheduled_date must be in YYYY-MM-DD format.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblSetlistSchedule (SetlistId, ScheduledDate, Notes, OrgId, CreatedBy)
                 VALUES (?, ?, ?, ?, ?)'
            );
            /* OrgId(i nullable). */
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('sssii',
                $schedSetlistId, $schedDate, $schedNotes, $schedOrgId, $authUserId);
            $stmt->execute();
            $newId = (int)$db->insert_id;
            $stmt->close();

            sendJson(['ok' => true, 'id' => $newId], 201);
            break;

        /* =================================================================
         * SETLIST TEMPLATES (#301)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get available setlist templates
         * Returns public templates + user's own + user's org templates
         * ----------------------------------------------------------------- */
        case 'setlist_templates':
            $db = getDbMysqli();
            $authUser = getAuthenticatedUser();

            if ($authUser) {
                $stmt = $db->prepare(
                    'SELECT t.Id AS id, t.Name AS name, t.Description AS description,
                            t.SlotsJson AS slotsJson, t.IsPublic AS isPublic,
                            t.OrgId AS orgId, t.CreatedBy AS createdBy,
                            t.CreatedAt AS createdAt
                     FROM tblSetlistTemplates t
                     LEFT JOIN tblOrganisationMembers m ON m.OrgId = t.OrgId AND m.UserId = ?
                     WHERE t.IsPublic = 1
                        OR t.CreatedBy = ?
                        OR m.UserId IS NOT NULL
                     ORDER BY t.Name ASC'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('ii', $authUserId, $authUserId);
                $stmt->execute();
            } else {
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Description AS description,
                            SlotsJson AS slotsJson, IsPublic AS isPublic,
                            OrgId AS orgId, CreatedBy AS createdBy,
                            CreatedAt AS createdAt
                     FROM tblSetlistTemplates
                     WHERE IsPublic = 1
                     ORDER BY Name ASC'
                );
                $stmt->execute();
            }

            $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($templates as &$tpl) {
                $tpl['id'] = (int)$tpl['id'];
                $tpl['isPublic'] = (bool)$tpl['isPublic'];
                $tpl['orgId'] = $tpl['orgId'] ? (int)$tpl['orgId'] : null;
                $tpl['createdBy'] = $tpl['createdBy'] ? (int)$tpl['createdBy'] : null;
                $tpl['slotsJson'] = json_decode($tpl['slotsJson'], true) ?: [];
            }
            unset($tpl);

            sendJson(['templates' => $templates]);
            break;

        /* -----------------------------------------------------------------
         * Create a setlist template
         *
         * POST body (JSON):
         *   { "name": "Sunday Service", "description": "...",
         *     "slots": [{"label": "Opening", "type": "song"}, ...],
         *     "org_id": 1, "is_public": false }
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'setlist_template_save':
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

            $tplName     = mb_substr(trim($body['name'] ?? ''), 0, 200);
            $tplDesc     = mb_substr(trim($body['description'] ?? ''), 0, 2000);
            $tplSlots    = is_array($body['slots'] ?? null) ? $body['slots'] : [];
            $tplOrgId    = !empty($body['org_id']) ? (int)$body['org_id'] : null;
            $tplIsPublic = !empty($body['is_public']) ? 1 : 0;

            if ($tplName === '') {
                sendJson(['error' => 'Template name is required.'], 400);
                break;
            }

            /* Sanitise slots */
            $cleanSlots = [];
            foreach (array_slice($tplSlots, 0, 50) as $slot) {
                if (!is_array($slot) || empty($slot['label'])) continue;
                $cleanSlots[] = [
                    'label' => mb_substr(trim($slot['label']), 0, 100),
                    'type'  => mb_substr(trim($slot['type'] ?? 'song'), 0, 50),
                ];
            }

            $slotsJson = json_encode($cleanSlots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblSetlistTemplates (Name, Description, SlotsJson, OrgId, IsPublic, CreatedBy)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            /* OrgId(i nullable), IsPublic(i bool-as-int), CreatedBy(i). */
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('sssiii',
                $tplName, $tplDesc, $slotsJson, $tplOrgId, $tplIsPublic, $authUserId);
            $stmt->execute();
            $newId = (int)$db->insert_id;
            $stmt->close();

            sendJson(['ok' => true, 'id' => $newId], 201);
            break;

        /* =================================================================
         * POPULAR SONGS (#303)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get popular songs by view count
         * Parameters: period (week|month|year|all), limit (default 20)
         * ----------------------------------------------------------------- */
        case 'popular_songs':
            $period = trim($_GET['period'] ?? 'week');
            $popLimit = min(max((int)($_GET['limit'] ?? 20), 1), 100);

            $periodMap = [
                'week'  => 7,
                'month' => 30,
                'year'  => 365,
                'all'   => 99999,
            ];
            $days = $periodMap[$period] ?? 7;

            try {
                $db = getDbMysqli();
                /* INNER JOIN tblSongs so we return the human-readable
                   title + songbook metadata the home-page renderer needs.
                   Songs that have been deleted since they were viewed
                   drop out of the list naturally (#546). */
                /* Language filter (#736). s.Language pulled into the
                   SELECT so the in-memory predicate can read it
                   without a second round-trip. The filter is
                   applied AFTER the LIMIT here, so the visible
                   set may be < limit when the catalogue spans
                   languages the user has filtered out — that's an
                   acceptable trade-off for a "popular" list. */
                $stmt = $db->prepare(
                    'SELECT h.SongId        AS songId,
                            s.Title         AS title,
                            s.Number        AS number,
                            s.SongbookAbbr  AS songbook,
                            s.SongbookName  AS songbookName,
                            s.Language      AS language,
                            COUNT(*)        AS views
                     FROM tblSongHistory h
                     JOIN tblSongs s ON s.SongId = h.SongId
                     WHERE h.ViewedAt > DATE_SUB(NOW(), INTERVAL ? DAY)
                     GROUP BY h.SongId, s.Title, s.Number, s.SongbookAbbr, s.SongbookName, s.Language
                     ORDER BY views DESC
                     LIMIT ?'
                );
                $stmt->bind_param('ii', $days, $popLimit);
                $stmt->execute();
                $songs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($songs as &$ps) {
                    $ps['views'] = (int)$ps['views'];
                }
                unset($ps);

                $_langPred = makeLanguageFilterPredicate(
                    resolvePreferredLanguagesForRequest(getAuthenticatedUser())
                );
                $songs = array_values(array_filter($songs, $_langPred));

                sendJson(['songs' => $songs, 'period' => $period]);
            } catch (\Throwable $e) {
                /* DB unavailable (JSON fallback mode) — return empty
                   gracefully. Logged so admins notice if this is hit
                   for non-DB-down reasons (DDL drift, query syntax). */
                error_log('[api/popular_songs] ' . $e->getMessage());
                sendJson(['songs' => [], 'period' => $period, 'fallback' => true]);
            }
            break;

        /* =================================================================
         * RECENTLY VIEWED (#304)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get the authenticated user's recently viewed songs
         * Returns up to 50 most recent views
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'song_history':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            try {
                $db = getDbMysqli();
                /* INNER JOIN tblSongs so the recently-viewed list can show
                   the song title + songbook badge instead of the bare ID
                   (#546). A history row whose song has since been deleted
                   drops out — preferable to rendering an unresolvable ID.

                   GROUP BY h.SongId collapses repeat views of the same
                   song into a single row, anchored to its most recent
                   view, so the list never displays duplicates (#549). */
                $stmt = $db->prepare(
                    'SELECT h.SongId        AS songId,
                            s.Title         AS title,
                            s.Number        AS number,
                            s.SongbookAbbr  AS songbook,
                            s.SongbookName  AS songbookName,
                            s.Language      AS language,
                            MAX(h.ViewedAt) AS viewedAt
                     FROM tblSongHistory h
                     JOIN tblSongs s ON s.SongId = h.SongId
                     WHERE h.UserId = ?
                     GROUP BY h.SongId, s.Title, s.Number, s.SongbookAbbr, s.SongbookName, s.Language
                     ORDER BY MAX(h.ViewedAt) DESC
                     LIMIT 50'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                /* Language filter (#736) — apply in-memory after the
                   LIMIT, same trade-off as popular_songs. */
                $_langPred = makeLanguageFilterPredicate(
                    resolvePreferredLanguagesForRequest($authUser)
                );
                $history = array_values(array_filter($history, $_langPred));
                sendJson(['history' => $history]);
            } catch (\Throwable $e) {
                error_log('[api/song_history] ' . $e->getMessage());
                sendJson(['history' => [], 'fallback' => true]);
            }
            break;

        /* -----------------------------------------------------------------
         * Record a song view
         *
         * POST body (JSON): { "song_id": "CP-0001" }
         * Anonymous views are allowed (UserId will be NULL)
         * ----------------------------------------------------------------- */
        case 'song_view':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $viewSongId = trim($body['song_id'] ?? '');

            if (!preg_match('/^[A-Za-z]+-\d+$/', $viewSongId)) {
                sendJson(['error' => 'Invalid song ID.'], 400);
                break;
            }

            $authUser = getAuthenticatedUser();
            $viewUserId = $authUser ? $authUser['Id'] : null;

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'INSERT INTO tblSongHistory (SongId, UserId) VALUES (?, ?)'
                );
                /* UserId(i nullable) — anonymous views OK. */
                $stmt->bind_param('si', $viewSongId, $viewUserId);
                $stmt->execute();
                $stmt->close();
                sendJson(['ok' => true]);
            } catch (\Throwable $e) {
                /* DB unavailable — skip view tracking. Logged so
                   admins notice if this is hit for non-DB-down reasons. */
                error_log('[api/song_view] ' . $e->getMessage());
                sendJson(['ok' => false, 'fallback' => true]);
            }
            break;

        /* =================================================================
         * BROWSE BY TAG (#305)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get all song tags
         * ----------------------------------------------------------------- */
        case 'tags':
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            Description AS description
                     FROM tblSongTags
                     ORDER BY Name ASC'
                );
                $stmt->execute();
                $tags = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($tags as &$tag) {
                    $tag['id'] = (int)$tag['id'];
                }
                unset($tag);

                sendJson(['tags' => $tags]);
            } catch (\Throwable $e) {
                error_log('[api/tags] ' . $e->getMessage());
                sendJson(['tags' => [], 'fallback' => true]);
            }
            break;

        /* -----------------------------------------------------------------
         * Get songs by tag slug
         * Parameters: tag (required) — tag slug e.g. "easter"
         * ----------------------------------------------------------------- */
        case 'songs_by_tag':
            $tagSlug = trim($_GET['tag'] ?? '');
            if ($tagSlug === '') {
                sendJson(['error' => 'Tag slug is required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();

                /* Get the tag info */
                $stmt = $db->prepare(
                    'SELECT Id AS id, Name AS name, Slug AS slug,
                            Description AS description
                     FROM tblSongTags
                     WHERE Slug = ?'
                );
                $stmt->bind_param('s', $tagSlug);
                $stmt->execute();
                $tagInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$tagInfo) {
                    sendJson(['error' => 'Tag not found.'], 404);
                    break;
                }
                $tagInfo['id'] = (int)$tagInfo['id'];

                /* Get songs linked to this tag */
                $stmt = $db->prepare(
                    'SELECT s.SongId AS id, s.Title AS title,
                            s.SongbookAbbr AS songbook, s.Number AS number
                     FROM tblSongTagMap tm
                     JOIN tblSongs s ON s.SongId = tm.SongId
                     WHERE tm.TagId = ?
                     ORDER BY s.Title ASC'
                );
                $stmt->bind_param('i', $tagInfo['id']);
                $stmt->execute();
                $tagSongs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($tagSongs as &$ts) {
                    $ts['number'] = (int)$ts['number'];
                }
                unset($ts);

                sendJson(['songs' => $tagSongs, 'tag' => $tagInfo]);
            } catch (\Throwable $e) {
                error_log('[api/songs_by_tag] ' . $e->getMessage());
                sendJson(['songs' => [], 'tag' => null, 'fallback' => true]);
            }
            break;

        /* =================================================================
         * SEARCH AUTOCOMPLETE (#307)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get search suggestions (autocomplete)
         * Parameters: q (required, min 2 chars)
         * ----------------------------------------------------------------- */
        case 'suggest':
            $suggestQ = trim($_GET['q'] ?? '');

            if (mb_strlen($suggestQ) < 2) {
                sendJson(['suggestions' => []]);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT SongId AS id, Title AS title,
                        SongbookAbbr AS songbook, Number AS number
                 FROM tblSongs
                 WHERE Title LIKE ?
                 LIMIT 8'
            );
            $like = '%' . $suggestQ . '%';
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $suggestions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($suggestions as &$sg) {
                $sg['number'] = (int)$sg['number'];
            }
            unset($sg);

            sendJson(['suggestions' => $suggestions]);
            break;

        /* =================================================================
         * USER PREFERENCES (#310)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get user preferences
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'user_preferences':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT PreferencesJson FROM tblUserPreferences WHERE UserId = ?'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $prefsJson = (string)($row[0] ?? '');

            $preferences = $prefsJson !== '' ? (json_decode($prefsJson, true) ?: []) : [];

            sendJson(['preferences' => $preferences]);
            break;

        /* -----------------------------------------------------------------
         * Save/sync user preferences
         *
         * POST body (JSON):
         *   { "preferences": { "theme": "dark", "fontSize": 16, ... } }
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'user_preferences_sync':
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

            if (!is_array($body['preferences'] ?? null)) {
                sendJson(['error' => 'Invalid request. Required: preferences (object).'], 400);
                break;
            }

            $prefsJson = json_encode($body['preferences'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            /* Cap JSON size at 64 KB */
            if (strlen($prefsJson) > 65536) {
                sendJson(['error' => 'Preferences data too large (max 64 KB).'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'INSERT INTO tblUserPreferences (UserId, PreferencesJson)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE
                    PreferencesJson = VALUES(PreferencesJson),
                    UpdatedAt = NOW()'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('is', $authUserId, $prefsJson);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * USER PREFERRED LANGUAGES (#736)
         *
         * Per-account persistence of the language-filter selection so a
         * signed-in user's choice syncs across devices. Anonymous users
         * keep using localStorage on the SPA side; the SPA can also pass
         * the choice via the X-Preferred-Languages header on every fetch
         * to get the same server-side filter as authenticated users
         * (see resolvePreferredLanguagesForRequest() in language_filter.php).
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get the authenticated user's saved preferred-language subtags
         * Requires: Bearer token
         *
         * Response: { subtags: ["en","es"] }   (empty array = no filter)
         * ----------------------------------------------------------------- */
        case 'user_preferred_languages':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            try {
                $db = getDbMysqli();
                /* Probe the column so a pre-#736 deployment returns
                   an empty list rather than 500ing the request. */
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblUsers'
                        AND COLUMN_NAME  = 'PreferredLanguagesJson' LIMIT 1"
                );
                $probe->execute();
                $hasCol = $probe->get_result()->fetch_row() !== null;
                $probe->close();
                if (!$hasCol) {
                    sendJson([
                        'subtags'          => [],
                        'migration_needed' => true,
                    ]);
                    break;
                }

                $stmt = $db->prepare(
                    'SELECT PreferredLanguagesJson FROM tblUsers WHERE Id = ?'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $raw = $row[0] ?? null;
                $subtags = [];
                if ($raw) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $subtags = parsePreferredLanguageSubtags(
                            implode(',', array_map('strval', $decoded))
                        );
                    }
                }
                sendJson(['subtags' => $subtags]);
            } catch (\Throwable $e) {
                error_log('[user_preferred_languages] ' . $e->getMessage());
                sendJson(['error' => 'Could not load preferred languages.'], 500);
            }
            break;

        /* -----------------------------------------------------------------
         * Save the authenticated user's preferred-language subtags
         * POST body: { "subtags": ["en","es"] }   (empty array = no filter)
         * Requires: Bearer token
         *
         * Server-side normalisation: invalid subtags are dropped, the
         * list is lowercased, primary-subtag-only, and deduplicated
         * (parsePreferredLanguageSubtags). Empty array clears the
         * filter (returns the saved value as []).
         * ----------------------------------------------------------------- */
        case 'user_preferred_languages_save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $rawList = $body['subtags'] ?? [];
            if (!is_array($rawList)) {
                sendJson(['error' => 'subtags must be an array.'], 400);
                break;
            }

            /* Run through the canonical parser so the saved value is
               always a clean primary-subtag list. Invalid entries
               are dropped silently. */
            $clean = parsePreferredLanguageSubtags(
                implode(',', array_map('strval', $rawList))
            );

            try {
                $db = getDbMysqli();
                $probe = $db->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = 'tblUsers'
                        AND COLUMN_NAME  = 'PreferredLanguagesJson' LIMIT 1"
                );
                $probe->execute();
                $hasCol = $probe->get_result()->fetch_row() !== null;
                $probe->close();
                if (!$hasCol) {
                    sendJson([
                        'error'            => 'Migration not yet applied on this deployment.',
                        'migration_needed' => true,
                    ], 503);
                    break;
                }

                /* Empty array → store NULL so the column reads as
                   "no filter set" rather than "filter set to empty
                   set" (which would mean "show nothing"). */
                $store = empty($clean) ? null : json_encode(array_values($clean));
                $stmt = $db->prepare(
                    'UPDATE tblUsers SET PreferredLanguagesJson = ?, UpdatedAt = NOW() WHERE Id = ?'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('si', $store, $authUserId);
                $stmt->execute();
                $stmt->close();
                sendJson(['ok' => true, 'subtags' => $clean]);
            } catch (\Throwable $e) {
                error_log('[user_preferred_languages_save] ' . $e->getMessage());
                sendJson(['error' => 'Could not save preferred languages.'], 500);
            }
            break;

        /* =================================================================
         * COLLABORATIVE SETLISTS (#312)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get collaborators for a setlist
         * Parameters: setlist_id (required)
         * Requires: Bearer token — only the setlist owner can view
         * ----------------------------------------------------------------- */
        case 'setlist_collaborators':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $collabSetlistId = trim($_GET['setlist_id'] ?? '');
            if ($collabSetlistId === '') {
                sendJson(['error' => 'setlist_id is required.'], 400);
                break;
            }

            $db = getDbMysqli();

            /* Verify the authenticated user owns this setlist */
            $stmt = $db->prepare(
                'SELECT SetlistId FROM tblUserSetlists WHERE SetlistId = ? AND UserId = ?'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('si', $collabSetlistId, $authUserId);
            $stmt->execute();
            $owns = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$owns) {
                sendJson(['error' => 'You do not own this setlist or it does not exist.'], 403);
                break;
            }

            $stmt = $db->prepare(
                'SELECT c.Id AS id, c.UserId AS userId,
                        u.Username AS username, u.DisplayName AS displayName,
                        c.Permission AS permission, c.CreatedAt AS createdAt
                 FROM tblSetlistCollaborators c
                 JOIN tblUsers u ON u.Id = c.UserId
                 WHERE c.SetlistId = ?
                 ORDER BY c.CreatedAt ASC'
            );
            $stmt->bind_param('s', $collabSetlistId);
            $stmt->execute();
            $collaborators = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($collaborators as &$collab) {
                $collab['id'] = (int)$collab['id'];
                $collab['userId'] = (int)$collab['userId'];
            }
            unset($collab);

            sendJson(['collaborators' => $collaborators]);
            break;

        /* -----------------------------------------------------------------
         * Add a collaborator to a setlist
         *
         * POST body (JSON):
         *   { "setlist_id": "...", "username": "...",
         *     "permission": "edit"|"view" }
         * Requires: Bearer token — owner only
         * ----------------------------------------------------------------- */
        case 'setlist_collaborator_add':
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

            $addSetlistId  = trim($body['setlist_id'] ?? '');
            $addUsername    = mb_strtolower(trim($body['username'] ?? ''));
            $addPermission = trim($body['permission'] ?? 'view');

            if ($addSetlistId === '' || $addUsername === '') {
                sendJson(['error' => 'setlist_id and username are required.'], 400);
                break;
            }
            if (!in_array($addPermission, ['edit', 'view'])) {
                sendJson(['error' => 'Permission must be "edit" or "view".'], 400);
                break;
            }

            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];

            /* Verify ownership */
            $stmt = $db->prepare(
                'SELECT SetlistId FROM tblUserSetlists WHERE SetlistId = ? AND UserId = ?'
            );
            $stmt->bind_param('si', $addSetlistId, $authUserId);
            $stmt->execute();
            $owns = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$owns) {
                sendJson(['error' => 'You do not own this setlist or it does not exist.'], 403);
                break;
            }

            /* Find the user to add */
            $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Username = ? AND IsActive = 1');
            $stmt->bind_param('s', $addUsername);
            $stmt->execute();
            $targetUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$targetUser) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }

            $targetUserId = (int)$targetUser['Id'];

            /* Cannot add yourself */
            if ($targetUserId === $authUserId) {
                sendJson(['error' => 'You cannot add yourself as a collaborator.'], 400);
                break;
            }

            /* Upsert collaborator */
            $stmt = $db->prepare(
                'INSERT INTO tblSetlistCollaborators (SetlistId, UserId, Permission)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE Permission = VALUES(Permission)'
            );
            $stmt->bind_param('sis', $addSetlistId, $targetUserId, $addPermission);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true], 201);
            break;

        /* -----------------------------------------------------------------
         * Remove a collaborator from a setlist
         *
         * POST body (JSON):
         *   { "setlist_id": "...", "collaborator_id": 123 }
         * Requires: Bearer token — owner only
         * ----------------------------------------------------------------- */
        case 'setlist_collaborator_remove':
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

            $rmSetlistId    = trim($body['setlist_id'] ?? '');
            $rmCollabId     = (int)($body['collaborator_id'] ?? 0);

            if ($rmSetlistId === '' || $rmCollabId <= 0) {
                sendJson(['error' => 'setlist_id and collaborator_id are required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];

            /* Verify ownership */
            $stmt = $db->prepare(
                'SELECT SetlistId FROM tblUserSetlists WHERE SetlistId = ? AND UserId = ?'
            );
            $stmt->bind_param('si', $rmSetlistId, $authUserId);
            $stmt->execute();
            $owns = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$owns) {
                sendJson(['error' => 'You do not own this setlist or it does not exist.'], 403);
                break;
            }

            $stmt = $db->prepare(
                'DELETE FROM tblSetlistCollaborators WHERE Id = ? AND SetlistId = ?'
            );
            $stmt->bind_param('is', $rmCollabId, $rmSetlistId);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true]);
            break;

        /* =================================================================
         * SONG REVISIONS (#313)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Get revision history for a song (editor+ only)
         * Parameters: song_id (required)
         * ----------------------------------------------------------------- */
        case 'song_revisions':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }

            $revSongId = trim($_GET['song_id'] ?? '');
            if ($revSongId === '') {
                sendJson(['error' => 'song_id is required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT r.Id AS id, r.SongId AS songId, r.Action AS action,
                        r.Status AS status, r.ChangesJson AS changesJson,
                        r.Notes AS notes, r.CreatedAt AS createdAt,
                        r.ReviewedAt AS reviewedAt,
                        u.Username AS username,
                        rv.Username AS reviewedBy
                 FROM tblSongRevisions r
                 JOIN tblUsers u ON u.Id = r.CreatedBy
                 LEFT JOIN tblUsers rv ON rv.Id = r.ReviewedBy
                 WHERE r.SongId = ?
                 ORDER BY r.CreatedAt DESC
                 LIMIT 100'
            );
            $stmt->bind_param('s', $revSongId);
            $stmt->execute();
            $revisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($revisions as &$rev) {
                $rev['id'] = (int)$rev['id'];
                if ($rev['changesJson'] !== null) {
                    $rev['changesJson'] = json_decode($rev['changesJson'], true);
                }
            }
            unset($rev);

            sendJson(['revisions' => $revisions]);
            break;

        /* -----------------------------------------------------------------
         * Get all pending song revisions (admin+ only)
         * ----------------------------------------------------------------- */
        case 'admin_pending_revisions':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT r.Id AS id, r.SongId AS songId, r.Action AS action,
                        r.Status AS status, r.ChangesJson AS changesJson,
                        r.Notes AS notes, r.CreatedAt AS createdAt,
                        u.Username AS username
                 FROM tblSongRevisions r
                 JOIN tblUsers u ON u.Id = r.CreatedBy
                 WHERE r.Status = \'pending\'
                 ORDER BY r.CreatedAt ASC
                 LIMIT 200'
            );
            $stmt->execute();
            $revisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($revisions as &$rev) {
                $rev['id'] = (int)$rev['id'];
                if ($rev['changesJson'] !== null) {
                    $rev['changesJson'] = json_decode($rev['changesJson'], true);
                }
            }
            unset($rev);

            sendJson(['revisions' => $revisions]);
            break;

        /* -----------------------------------------------------------------
         * Related songs — server-side recommendations (#308)
         * Parameters: id (required), limit (optional, default 10)
         * No auth required.
         * Finds related songs by: shared writer/composer, shared tags,
         * same songbook.
         * ----------------------------------------------------------------- */
        case 'related_songs':
            $songId = isset($_GET['id']) ? trim($_GET['id']) : '';
            $relLimit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;

            if ($songId === '' || !preg_match('/^[A-Za-z]+-\d+$/', $songId)) {
                sendJson(['error' => 'Valid song ID is required.'], 400);
                break;
            }

            $db = getDbMysqli();

            /* Verify the source song exists and get its songbook */
            $stmt = $db->prepare('SELECT SongId, SongbookAbbr FROM tblSongs WHERE SongId = ?');
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            $sourceSong = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$sourceSong) {
                sendJson(['error' => 'Song not found.'], 404);
                break;
            }

            $related = [];
            $seenIds = [$songId => true];

            /* 1. Songs by the same writer(s) */
            $stmt = $db->prepare(
                'SELECT DISTINCT s.SongId AS id, s.Title AS title, s.SongbookAbbr AS songbook,
                        s.Number AS number, w2.Name AS reason
                 FROM tblSongWriters w1
                 JOIN tblSongWriters w2 ON w2.Name = w1.Name AND w2.SongId != w1.SongId
                 JOIN tblSongs s ON s.SongId = w2.SongId
                 WHERE w1.SongId = ?
                 LIMIT 20'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (isset($seenIds[$row['id']])) continue;
                $seenIds[$row['id']] = true;
                $row['number'] = (int)$row['number'];
                $row['reason'] = 'Same writer: ' . $row['reason'];
                $related[] = $row;
            }
            $stmt->close();

            /* 2. Songs by the same composer(s) */
            $stmt = $db->prepare(
                'SELECT DISTINCT s.SongId AS id, s.Title AS title, s.SongbookAbbr AS songbook,
                        s.Number AS number, c2.Name AS reason
                 FROM tblSongComposers c1
                 JOIN tblSongComposers c2 ON c2.Name = c1.Name AND c2.SongId != c1.SongId
                 JOIN tblSongs s ON s.SongId = c2.SongId
                 WHERE c1.SongId = ?
                 LIMIT 20'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (isset($seenIds[$row['id']])) continue;
                $seenIds[$row['id']] = true;
                $row['number'] = (int)$row['number'];
                $row['reason'] = 'Same composer: ' . $row['reason'];
                $related[] = $row;
            }
            $stmt->close();

            /* 3. Songs with shared tags */
            $stmt = $db->prepare(
                'SELECT DISTINCT s.SongId AS id, s.Title AS title, s.SongbookAbbr AS songbook,
                        s.Number AS number, t.Name AS reason
                 FROM tblSongTagMap tm1
                 JOIN tblSongTagMap tm2 ON tm2.TagId = tm1.TagId AND tm2.SongId != tm1.SongId
                 JOIN tblSongs s ON s.SongId = tm2.SongId
                 JOIN tblSongTags t ON t.Id = tm1.TagId
                 WHERE tm1.SongId = ?
                 LIMIT 20'
            );
            $stmt->bind_param('s', $songId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (isset($seenIds[$row['id']])) continue;
                $seenIds[$row['id']] = true;
                $row['number'] = (int)$row['number'];
                $row['reason'] = 'Shared tag: ' . $row['reason'];
                $related[] = $row;
            }
            $stmt->close();

            /* 4. Same songbook (fill remaining slots).
               Dynamic IN-list of song IDs to exclude (all strings),
               with the songbook code as the first bind. */
            if (count($related) < $relLimit) {
                $remaining = $relLimit - count($related);
                $excludeIds = array_keys($seenIds);
                $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                $stmt = $db->prepare(
                    "SELECT s.SongId AS id, s.Title AS title, s.SongbookAbbr AS songbook,
                            s.Number AS number
                     FROM tblSongs s
                     WHERE s.SongbookAbbr = ? AND s.SongId NOT IN ($placeholders)
                     ORDER BY RAND()
                     LIMIT " . (int)$remaining
                );
                $params = array_merge([$sourceSong['SongbookAbbr']], $excludeIds);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $row['number'] = (int)$row['number'];
                    $row['reason'] = 'Same songbook';
                    $related[] = $row;
                }
                $stmt->close();
            }

            /* Trim to requested limit */
            $related = array_slice($related, 0, $relLimit);

            sendJson(['related' => $related]);
            break;

        /* -----------------------------------------------------------------
         * Admin: bulk export (#314)
         * Parameters: format (json|csv|xml|opensong|videopsalm)
         * Requires: editor+ role
         * Streams download with Content-Disposition header.
         * ----------------------------------------------------------------- */
        case 'admin_export':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }

            $format = mb_strtolower(trim($_GET['format'] ?? 'json'));
            $validFormats = ['json', 'csv', 'xml', 'opensong', 'videopsalm'];
            if (!in_array($format, $validFormats)) {
                sendJson(['error' => 'Invalid format. Supported: ' . implode(', ', $validFormats)], 400);
                break;
            }

            $db = getDbMysqli();

            /* Fetch all songs with writers and composers */
            $stmt = $db->prepare(
                'SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, s.SongbookName,
                        s.Language, s.Copyright, s.Ccli, s.Verified, s.HasAudio, s.HasSheetMusic,
                        s.LyricsText
                 FROM tblSongs s
                 ORDER BY s.SongbookAbbr, s.Number'
            );
            $stmt->execute();
            $songs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            /* Pre-fetch writers and composers keyed by SongId */
            $writerMap = [];
            $composerMap = [];
            $wStmt = $db->prepare('SELECT SongId, Name FROM tblSongWriters ORDER BY SongId, Id');
            $wStmt->execute();
            foreach ($wStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $writerMap[$row['SongId']][] = $row['Name'];
            }
            $wStmt->close();
            $cStmt = $db->prepare('SELECT SongId, Name FROM tblSongComposers ORDER BY SongId, Id');
            $cStmt->execute();
            foreach ($cStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $composerMap[$row['SongId']][] = $row['Name'];
            }
            $cStmt->close();

            switch ($format) {
                case 'json':
                    $exportData = [];
                    foreach ($songs as $s) {
                        $exportData[] = [
                            'id'        => $s['SongId'],
                            'number'    => (int)$s['Number'],
                            'title'     => $s['Title'],
                            'songbook'  => $s['SongbookAbbr'],
                            'songbookName' => $s['SongbookName'],
                            'writers'   => $writerMap[$s['SongId']] ?? [],
                            'composers' => $composerMap[$s['SongId']] ?? [],
                            'copyright' => $s['Copyright'],
                            'language'  => $s['Language'],
                            'ccli'      => $s['Ccli'],
                            'verified'  => (bool)$s['Verified'],
                            'hasAudio'  => (bool)$s['HasAudio'],
                            'hasSheetMusic' => (bool)$s['HasSheetMusic'],
                        ];
                    }
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="ihymns-export.json"');
                    echo json_encode(['songs' => $exportData], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    break;

                case 'csv':
                    header('Content-Type: text/csv; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="ihymns-export.csv"');
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['id', 'number', 'title', 'songbook', 'writers', 'composers', 'copyright', 'language']);
                    foreach ($songs as $s) {
                        fputcsv($out, [
                            $s['SongId'],
                            $s['Number'],
                            $s['Title'],
                            $s['SongbookAbbr'],
                            implode('; ', $writerMap[$s['SongId']] ?? []),
                            implode('; ', $composerMap[$s['SongId']] ?? []),
                            $s['Copyright'],
                            $s['Language'],
                        ]);
                    }
                    fclose($out);
                    break;

                case 'xml':
                    header('Content-Type: application/xml; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="ihymns-export.xml"');
                    $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><songs/>');
                    foreach ($songs as $s) {
                        $node = $xml->addChild('song');
                        $node->addAttribute('id', $s['SongId']);
                        $node->addChild('number', (string)$s['Number']);
                        $node->addChild('title', htmlspecialchars($s['Title'], ENT_XML1));
                        $node->addChild('songbook', $s['SongbookAbbr']);
                        $node->addChild('language', $s['Language']);
                        $node->addChild('copyright', htmlspecialchars($s['Copyright'], ENT_XML1));
                        $writersNode = $node->addChild('writers');
                        foreach ($writerMap[$s['SongId']] ?? [] as $w) {
                            $writersNode->addChild('writer', htmlspecialchars($w, ENT_XML1));
                        }
                        $composersNode = $node->addChild('composers');
                        foreach ($composerMap[$s['SongId']] ?? [] as $c) {
                            $composersNode->addChild('composer', htmlspecialchars($c, ENT_XML1));
                        }
                    }
                    echo $xml->asXML();
                    break;

                case 'opensong':
                    /* OpenSong format: one XML file per song, zipped */
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="ihymns-opensong.zip"');
                    $zipPath = tempnam(sys_get_temp_dir(), 'ihymns_opensong_');
                    $zip = new \ZipArchive();
                    $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                    foreach ($songs as $s) {
                        $osXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<song>\n";
                        $osXml .= "  <title>" . htmlspecialchars($s['Title'], ENT_XML1) . "</title>\n";
                        $osXml .= "  <author>" . htmlspecialchars(implode(', ', $writerMap[$s['SongId']] ?? []), ENT_XML1) . "</author>\n";
                        $osXml .= "  <copyright>" . htmlspecialchars($s['Copyright'], ENT_XML1) . "</copyright>\n";
                        $osXml .= "  <ccli>" . htmlspecialchars($s['Ccli'], ENT_XML1) . "</ccli>\n";
                        /* Convert lyrics: OpenSong uses [V1], [C], etc. — simplify to plain text */
                        $osXml .= "  <lyrics>" . htmlspecialchars($s['LyricsText'], ENT_XML1) . "</lyrics>\n";
                        $osXml .= "</song>\n";
                        $zip->addFromString($s['SongId'] . '.xml', $osXml);
                    }
                    $zip->close();
                    readfile($zipPath);
                    unlink($zipPath);
                    break;

                case 'videopsalm':
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="ihymns-videopsalm.json"');
                    $vpSongs = [];
                    foreach ($songs as $s) {
                        $vpSongs[] = [
                            'Text'      => $s['Title'],
                            'Author'    => implode(', ', $writerMap[$s['SongId']] ?? []),
                            'Copyright' => $s['Copyright'],
                            'CCLI'      => $s['Ccli'],
                            'Verses'    => array_map(
                                fn($line) => ['Text' => $line],
                                array_filter(explode("\n\n", $s['LyricsText']))
                            ),
                        ];
                    }
                    echo json_encode(
                        ['Songs' => $vpSongs],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                    );
                    break;
            }
            break;

        /* -----------------------------------------------------------------
         * Admin: songbook health / completion dashboard (#315)
         * Parameters: songbook (optional — filter to one songbook)
         * Requires: editor+ role
         * ----------------------------------------------------------------- */
        case 'admin_songbook_health':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['editor', 'admin', 'global_admin'])) {
                sendJson(['error' => 'Editor access required.'], 403);
                break;
            }

            $filterBook = isset($_GET['songbook']) ? trim($_GET['songbook']) : null;
            if ($filterBook === '') $filterBook = null;

            $db = getDbMysqli();

            /* Get songbooks to report on */
            if ($filterBook) {
                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Abbreviation = ?');
                $stmt->bind_param('s', $filterBook);
                $stmt->execute();
            } else {
                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks ORDER BY Abbreviation');
                $stmt->execute();
            }
            $bookAbbrs = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Abbreviation');
            $stmt->close();

            if (empty($bookAbbrs)) {
                sendJson(['error' => 'Songbook not found.'], 404);
                break;
            }

            $health = [];
            foreach ($bookAbbrs as $abbr) {
                /* Core counts */
                $stmt = $db->prepare(
                    'SELECT
                        COUNT(*) AS totalSongs,
                        SUM(Verified = 1) AS verified,
                        SUM(Verified = 0) AS unverified,
                        SUM(HasAudio = 1) AS withAudio,
                        SUM(HasSheetMusic = 1) AS withSheetMusic,
                        SUM(Copyright != \'\') AS withCopyright
                     FROM tblSongs WHERE SongbookAbbr = ?'
                );
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $counts = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $totalSongs = (int)$counts['totalSongs'];

                /* Writers count */
                $stmt = $db->prepare(
                    'SELECT COUNT(DISTINCT s.SongId)
                     FROM tblSongs s
                     JOIN tblSongWriters w ON w.SongId = s.SongId
                     WHERE s.SongbookAbbr = ?'
                );
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $withWriters = (int)($row[0] ?? 0);

                /* Composers count */
                $stmt = $db->prepare(
                    'SELECT COUNT(DISTINCT s.SongId)
                     FROM tblSongs s
                     JOIN tblSongComposers c ON c.SongId = s.SongId
                     WHERE s.SongbookAbbr = ?'
                );
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $withComposers = (int)($row[0] ?? 0);

                /* Missing numbers: find gaps in the number sequence */
                $stmt = $db->prepare(
                    'SELECT Number FROM tblSongs WHERE SongbookAbbr = ? ORDER BY Number'
                );
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $existingNumbers = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Number');
                $stmt->close();
                $existingNumbers = array_map('intval', $existingNumbers);

                $missingNumbers = [];
                if (!empty($existingNumbers)) {
                    $maxNum = max($existingNumbers);
                    $existingSet = array_flip($existingNumbers);
                    for ($i = 1; $i <= $maxNum; $i++) {
                        if (!isset($existingSet[$i])) {
                            $missingNumbers[] = $i;
                        }
                    }
                }

                /* Completion: count of fields filled out of total possible */
                $filledFields = (int)$counts['verified'] + (int)$counts['withAudio']
                    + (int)$counts['withSheetMusic'] + $withWriters + $withComposers
                    + (int)$counts['withCopyright'];
                $totalFields = $totalSongs * 6; /* 6 tracked fields */
                $completionPercent = $totalFields > 0
                    ? round(($filledFields / $totalFields) * 100, 1)
                    : 0;

                $health[] = [
                    'songbook'          => $abbr,
                    'totalSongs'        => $totalSongs,
                    'verified'          => (int)$counts['verified'],
                    'unverified'        => (int)$counts['unverified'],
                    'withAudio'         => (int)$counts['withAudio'],
                    'withSheetMusic'    => (int)$counts['withSheetMusic'],
                    'withWriters'       => $withWriters,
                    'withComposers'     => $withComposers,
                    'withCopyright'     => (int)$counts['withCopyright'],
                    'missingNumbers'    => $missingNumbers,
                    'completionPercent' => $completionPercent,
                ];
            }

            sendJson(['health' => $health]);
            break;

        /* -----------------------------------------------------------------
         * Admin: review pending revisions (#316)
         * POST body: { "id": 123, "action": "approve"|"reject", "note": "..." }
         * Requires: admin+ role
         * ----------------------------------------------------------------- */
        case 'admin_revision_review':
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

            $revisionId   = (int)($body['id'] ?? 0);
            $reviewAction = mb_strtolower(trim($body['action'] ?? ''));
            $reviewNote   = mb_substr(trim($body['note'] ?? ''), 0, 2000);

            if ($revisionId <= 0) {
                sendJson(['error' => 'Revision ID is required.'], 400);
                break;
            }
            if (!in_array($reviewAction, ['approve', 'reject'])) {
                sendJson(['error' => 'Action must be "approve" or "reject".'], 400);
                break;
            }

            $db = getDbMysqli();

            /* Verify revision exists and is pending */
            $stmt = $db->prepare('SELECT Id, Status FROM tblSongRevisions WHERE Id = ?');
            $stmt->bind_param('i', $revisionId);
            $stmt->execute();
            $revision = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$revision) {
                sendJson(['error' => 'Revision not found.'], 404);
                break;
            }
            if ($revision['Status'] !== 'pending') {
                sendJson(['error' => 'Revision has already been reviewed (status: ' . $revision['Status'] . ').'], 409);
                break;
            }

            $newStatus = ($reviewAction === 'approve') ? 'approved' : 'rejected';
            $stmt = $db->prepare(
                'UPDATE tblSongRevisions SET Status = ?, ReviewedBy = ?, ReviewNote = ? WHERE Id = ?'
            );
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('sisi', $newStatus, $authUserId, $reviewNote, $revisionId);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true, 'status' => $newStatus]);
            break;

        /* -----------------------------------------------------------------
         * Push notification: subscribe (#311)
         * POST body: { "endpoint": "...", "p256dh_key": "...", "auth_key": "..." }
         * Requires: authenticated user
         * ----------------------------------------------------------------- */
        case 'push_subscribe':
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

            $pushEndpoint = trim($body['endpoint'] ?? '');
            $pushP256dh   = trim($body['p256dh_key'] ?? '');
            $pushAuth     = trim($body['auth_key'] ?? '');

            if ($pushEndpoint === '' || $pushP256dh === '' || $pushAuth === '') {
                sendJson(['error' => 'Required: endpoint, p256dh_key, auth_key.'], 400);
                break;
            }

            /* Validate endpoint is a URL */
            if (!filter_var($pushEndpoint, FILTER_VALIDATE_URL)) {
                sendJson(['error' => 'Invalid endpoint URL.'], 400);
                break;
            }

            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];

            /* Upsert: delete any existing subscription with the same endpoint for this user,
             * then insert the new one */
            $stmt = $db->prepare('DELETE FROM tblPushSubscriptions WHERE UserId = ? AND Endpoint = ?');
            $stmt->bind_param('is', $authUserId, $pushEndpoint);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                'INSERT INTO tblPushSubscriptions (UserId, Endpoint, P256dhKey, AuthKey)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('isss', $authUserId, $pushEndpoint, $pushP256dh, $pushAuth);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true]);
            break;

        /* -----------------------------------------------------------------
         * Push notification: unsubscribe (#311)
         * POST body: { "endpoint": "..." }
         * Requires: authenticated user
         * ----------------------------------------------------------------- */
        case 'push_unsubscribe':
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
            $pushEndpoint = trim($body['endpoint'] ?? '');

            if ($pushEndpoint === '') {
                sendJson(['error' => 'Endpoint is required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare('DELETE FROM tblPushSubscriptions WHERE Endpoint = ? AND UserId = ?');
            $authUserId = (int)$authUser['Id'];
            $stmt->bind_param('si', $pushEndpoint, $authUserId);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            sendJson(['ok' => true, 'deleted' => $deleted]);
            break;

        /* -----------------------------------------------------------------
         * Admin: token cleanup (#323)
         * POST, global_admin only
         * Deletes expired tokens from tblApiTokens, tblEmailLoginTokens,
         * tblPasswordResetTokens, and old tblLoginAttempts (30+ days).
         * ----------------------------------------------------------------- */
        case 'admin_cleanup':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser || $authUser['Role'] !== 'global_admin') {
                sendJson(['error' => 'Global admin access required.'], 403);
                break;
            }

            $db = getDbMysqli();
            $now = gmdate('c');

            /* Expired API tokens */
            $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE ExpiresAt < ?');
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $deletedApiTokens = $stmt->affected_rows;
            $stmt->close();

            /* Expired or used email login tokens */
            $stmt = $db->prepare('DELETE FROM tblEmailLoginTokens WHERE ExpiresAt < ? OR Used = 1');
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $deletedEmailTokens = $stmt->affected_rows;
            $stmt->close();

            /* Expired or used password reset tokens */
            $stmt = $db->prepare('DELETE FROM tblPasswordResetTokens WHERE ExpiresAt < ? OR Used = 1');
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $deletedResetTokens = $stmt->affected_rows;
            $stmt->close();

            /* Old login attempts (30+ days) */
            $stmt = $db->prepare('DELETE FROM tblLoginAttempts WHERE AttemptedAt < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $stmt->execute();
            $deletedLoginAttempts = $stmt->affected_rows;
            $stmt->close();

            sendJson([
                'ok' => true,
                'deleted' => [
                    'apiTokens'     => $deletedApiTokens,
                    'emailTokens'   => $deletedEmailTokens,
                    'resetTokens'   => $deletedResetTokens,
                    'loginAttempts' => $deletedLoginAttempts,
                ],
            ]);
            break;

        /* -----------------------------------------------------------------
         * Admin: list all organisations
         * Requires: admin+ role
         * ----------------------------------------------------------------- */

        /* =================================================================
         * CCLI & ACCESS TIERS (#346)
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Validate and save a CCLI licence number
         * POST body: { "ccli_number": "1234567" }
         * Requires: Bearer token
         * ----------------------------------------------------------------- */
        case 'ccli_validate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli_validator.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $ccliInput = trim($body['ccli_number'] ?? '');

            $validation = validateCcliNumber($ccliInput);

            if (!$validation['valid']) {
                sendJson(['error' => $validation['error'], 'valid' => false], 400);
                break;
            }

            /* Save to user profile */
            $db = getDbMysqli();
            $authUserId = (int)$authUser['Id'];
            $normalized = $validation['normalized'];
            $stmt = $db->prepare(
                'UPDATE tblUsers SET CcliNumber = ?, CcliVerified = 1, UpdatedAt = NOW() WHERE Id = ?'
            );
            $stmt->bind_param('si', $normalized, $authUserId);
            $stmt->execute();
            $stmt->close();

            /* If user is on 'free' tier, auto-upgrade to 'ccli' */
            $stmt = $db->prepare('SELECT AccessTier FROM tblUsers WHERE Id = ?');
            $stmt->bind_param('i', $authUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $currentTier = (string)($row[0] ?? '');
            if ($currentTier === 'free' || $currentTier === 'public') {
                $stmt = $db->prepare('UPDATE tblUsers SET AccessTier = ? WHERE Id = ?');
                $ccliTier = 'ccli';
                $stmt->bind_param('si', $ccliTier, $authUserId);
                $stmt->execute();
                $stmt->close();
            }

            sendJson([
                'valid'      => true,
                'normalized' => $validation['normalized'],
                'type'       => $validation['type'],
                'tier'       => ($currentTier === 'free' || $currentTier === 'public') ? 'ccli' : $currentTier,
            ]);
            break;

        /* -----------------------------------------------------------------
         * Check content tier access for an action
         * GET ?action=tier_check&check=play_audio
         * Requires: Bearer token (optional — anonymous = public tier)
         * ----------------------------------------------------------------- */
        case 'tier_check':
            $checkAction = trim($_GET['check'] ?? '');
            if ($checkAction === '') {
                sendJson(['error' => 'check parameter required.'], 400);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli_validator.php';

            $authUser = getAuthenticatedUser();
            $userTier = 'public';
            $hasCcli = false;

            if ($authUser) {
                /* Resolve effective tier: highest of personal + org tiers */
                $userTier = resolveEffectiveTier($authUser['Id']);
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT CcliNumber, CcliVerified FROM tblUsers WHERE Id = ?');
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $userData = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $hasCcli = !empty($userData['CcliNumber']) && $userData['CcliVerified'];
            }

            $result = checkTierAccess($userTier, $checkAction, $hasCcli);
            $result['tier'] = $userTier;
            sendJson($result);
            break;

        /* -----------------------------------------------------------------
         * Get available access tiers (public)
         * ----------------------------------------------------------------- */
        case 'access_tiers':
            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT Name AS name, DisplayName AS displayName, Level AS level,
                        Description AS description, CanViewLyrics AS canViewLyrics,
                        CanViewCopyrighted AS canViewCopyrighted, CanPlayAudio AS canPlayAudio,
                        CanDownloadMidi AS canDownloadMidi, CanDownloadPdf AS canDownloadPdf,
                        CanOfflineSave AS canOfflineSave, RequiresCcli AS requiresCcli
                 FROM tblAccessTiers ORDER BY Level ASC'
            );
            $stmt->execute();
            $tiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($tiers as &$t) {
                $t['level'] = (int)$t['level'];
                foreach (['canViewLyrics','canViewCopyrighted','canPlayAudio','canDownloadMidi','canDownloadPdf','canOfflineSave','requiresCcli'] as $k) {
                    $t[$k] = (bool)$t[$k];
                }
            }
            unset($t);
            sendJson(['tiers' => $tiers]);
            break;

        /* -----------------------------------------------------------------
         * Admin: set a user's access tier
         * POST body: { "user_id": 123, "tier": "premium" }
         * Requires: admin/global_admin role
         * ----------------------------------------------------------------- */
        case 'admin_set_user_tier':
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
            $targetUserId = (int)($body['user_id'] ?? 0);
            $newTier = trim($body['tier'] ?? '');
            $validTiers = ['public', 'free', 'ccli', 'premium', 'pro'];

            if ($targetUserId <= 0 || !in_array($newTier, $validTiers)) {
                sendJson(['error' => 'Valid user_id and tier (public/free/ccli/premium/pro) required.'], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare('UPDATE tblUsers SET AccessTier = ?, UpdatedAt = NOW() WHERE Id = ?');
            $stmt->bind_param('si', $newTier, $targetUserId);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true, 'tier' => $newTier]);
            break;

        /* -----------------------------------------------------------------
         * Admin: validate/set a user's CCLI number
         * POST body: { "user_id": 123, "ccli_number": "1234567" }
         * Requires: admin/global_admin role
         * ----------------------------------------------------------------- */
        case 'admin_set_user_ccli':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli_validator.php';

            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $targetUserId = (int)($body['user_id'] ?? 0);
            $ccliInput = trim($body['ccli_number'] ?? '');

            if ($targetUserId <= 0) {
                sendJson(['error' => 'Valid user_id required.'], 400);
                break;
            }

            if ($ccliInput === '') {
                /* Clear CCLI number */
                $db = getDbMysqli();
                $stmt = $db->prepare('UPDATE tblUsers SET CcliNumber = ?, CcliVerified = 0, UpdatedAt = NOW() WHERE Id = ?');
                $emptyStr = '';
                $stmt->bind_param('si', $emptyStr, $targetUserId);
                $stmt->execute();
                $stmt->close();
                sendJson(['ok' => true, 'cleared' => true]);
                break;
            }

            $validation = validateCcliNumber($ccliInput);
            if (!$validation['valid']) {
                sendJson(['error' => $validation['error'], 'valid' => false], 400);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare('UPDATE tblUsers SET CcliNumber = ?, CcliVerified = 1, UpdatedAt = NOW() WHERE Id = ?');
            $normalized = $validation['normalized'];
            $stmt->bind_param('si', $normalized, $targetUserId);
            $stmt->execute();
            $stmt->close();

            sendJson(['ok' => true, 'normalized' => $normalized]);
            break;

        case 'admin_organisations':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $db = getDbMysqli();
            $stmt = $db->prepare(
                'SELECT o.Id AS id, o.Name AS name, o.Slug AS slug,
                        o.ParentOrgId AS parentOrgId, o.LicenceType AS licenceType,
                        o.LicenceNumber AS licenceNumber, o.LicenceExpiresAt AS licenceExpiresAt,
                        o.IsActive AS isActive, o.CreatedAt AS createdAt,
                        (SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = o.Id) AS memberCount
                 FROM tblOrganisations o
                 ORDER BY o.Name ASC'
            );
            $stmt->execute();
            $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
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
         * Submit a song request (#403). Public endpoint; rate-limited by
         * IP to 5 submissions per 24 h. Honeypot field rejects bots.
         * ----------------------------------------------------------------- */
        /* -----------------------------------------------------------------
         * Setlist scheduling (#398) — ties a setlist to a date.
         * Auth required. Operates on the signed-in user's own setlists
         * via the SetlistId string (same ID used by tblUserSetlists).
         * ----------------------------------------------------------------- */
        case 'setlist_schedule_set':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }

            $body      = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $setlistId = trim((string)($body['setlistId'] ?? ''));
            $dateStr   = trim((string)($body['date']      ?? ''));
            $notes     = trim((string)($body['notes']     ?? ''));

            if ($setlistId === '' || $dateStr === '') {
                sendJson(['error' => 'setlistId and date are required.'], 400);
                break;
            }
            /* Validate YYYY-MM-DD */
            $d = DateTime::createFromFormat('Y-m-d', $dateStr);
            if (!$d || $d->format('Y-m-d') !== $dateStr) {
                sendJson(['error' => 'Invalid date (expected YYYY-MM-DD).'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $authUserId = (int)$authUser['Id'];
                /* Verify the caller owns the setlist */
                $own = $db->prepare('SELECT 1 FROM tblUserSetlists WHERE UserId = ? AND SetlistId = ? LIMIT 1');
                $own->bind_param('is', $authUserId, $setlistId);
                $own->execute();
                $owns = $own->get_result()->fetch_row() !== null;
                $own->close();
                if (!$owns) {
                    sendJson(['error' => 'Setlist not found.'], 404);
                    break;
                }
                /* Replace any existing schedule for this (user, setlist) pair. */
                $del = $db->prepare('DELETE FROM tblSetlistSchedule WHERE UserId = ? AND SetlistId = ?');
                $del->bind_param('is', $authUserId, $setlistId);
                $del->execute();
                $del->close();
                $ins = $db->prepare(
                    'INSERT INTO tblSetlistSchedule (SetlistId, UserId, ScheduledDate, Notes)
                     VALUES (?, ?, ?, ?)'
                );
                $ins->bind_param('siss', $setlistId, $authUserId, $dateStr, $notes);
                $ins->execute();
                $ins->close();
                logActivity('setlist.schedule_set', 'setlist', $setlistId, [
                    'date'      => $dateStr,
                    'has_notes' => $notes !== '',
                ]);
                sendJson(['ok' => true]);
            } catch (\Throwable $e) {
                error_log('[setlist_schedule_set] ' . $e->getMessage());
                logActivityError('setlist.schedule_set', 'setlist', $setlistId, $e);
                sendJson(['error' => 'Could not schedule the setlist.'], 500);
            }
            break;

        case 'setlist_schedule_clear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }
            $body = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $setlistId = trim((string)($body['setlistId'] ?? ''));
            if ($setlistId === '') { sendJson(['error' => 'setlistId required.'], 400); break; }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('DELETE FROM tblSetlistSchedule WHERE UserId = ? AND SetlistId = ?');
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('is', $authUserId, $setlistId);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    logActivity('setlist.schedule_clear', 'setlist', $setlistId);
                }
                sendJson(['ok' => true]);
            } catch (\Throwable $e) {
                error_log('[setlist_schedule_clear] ' . $e->getMessage());
                logActivityError('setlist.schedule_clear', 'setlist', $setlistId, $e);
                sendJson(['error' => 'Could not clear schedule.'], 500);
            }
            break;

        case 'setlist_schedule_upcoming':
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT s.SetlistId, s.ScheduledDate, s.Notes, l.Name AS name
                       FROM tblSetlistSchedule s
                       LEFT JOIN tblUserSetlists l
                              ON l.UserId = s.UserId AND l.SetlistId = s.SetlistId
                      WHERE s.UserId = ? AND s.ScheduledDate >= CURRENT_DATE()
                      ORDER BY s.ScheduledDate ASC
                      LIMIT 25'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                sendJson(['upcoming' => $upcoming]);
            } catch (\Throwable $e) {
                error_log('[setlist_schedule_upcoming] ' . $e->getMessage());
                sendJson(['error' => 'Could not load schedule.'], 500);
            }
            break;

        /* -------------------------------------------------------------
         * SETLIST COLLABORATION (#398)
         * Four endpoints manage tblSetlistCollaborators. Owner of the
         * setlist can invite by email, list existing collaborators,
         * and revoke. A separate endpoint returns the setlists the
         * current user has been invited to.
         * ----------------------------------------------------------- */

        /* GET ?action=setlist_schedule_current&setlistId=X — fetch the
         * existing schedule (if any) for a setlist the caller owns. */
        case 'setlist_schedule_current':
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }
            $setlistId = trim((string)($_GET['setlistId'] ?? ''));
            if ($setlistId === '') { sendJson(['error' => 'setlistId required.'], 400); break; }
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT ScheduledDate, Notes
                       FROM tblSetlistSchedule
                      WHERE UserId = ? AND SetlistId = ?
                      LIMIT 1'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('is', $authUserId, $setlistId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                sendJson(['schedule' => $row ?: null]);
            } catch (\Throwable $e) {
                error_log('[setlist_schedule_current] ' . $e->getMessage());
                sendJson(['error' => 'Could not load schedule.'], 500);
            }
            break;

        case 'setlist_collab_invite':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }

            /* Per-user rate limit (#321) — caps invite-spam at 20 per
               hour per signed-in user. checkRateLimit() returns true
               on DB error so a counter-table blip doesn't stop a
               legitimate inviter dead. */
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'rate_limit.php';
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            checkRateLimit('setlist_collab_invite', $clientIp, 20, 3600, true, (int)$authUser['Id']);

            $body = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $setlistId  = trim((string)($body['setlistId']        ?? ''));
            $collabEml  = trim((string)($body['collaboratorEmail'] ?? ''));
            $permission = strtolower(trim((string)($body['permission'] ?? 'edit')));
            if (!in_array($permission, ['view', 'edit'], true)) { $permission = 'edit'; }
            if ($setlistId === '' || $collabEml === '') {
                sendJson(['error' => 'setlistId and collaboratorEmail required.'], 400);
                break;
            }
            if (!filter_var($collabEml, FILTER_VALIDATE_EMAIL)) {
                sendJson(['error' => 'Invalid email address.'], 400);
                break;
            }
            try {
                $db = getDbMysqli();
                $authUserId = (int)$authUser['Id'];
                /* Owner check */
                $own = $db->prepare('SELECT 1 FROM tblUserSetlists WHERE UserId = ? AND SetlistId = ? LIMIT 1');
                $own->bind_param('is', $authUserId, $setlistId);
                $own->execute();
                $owns = $own->get_result()->fetch_row() !== null;
                $own->close();
                if (!$owns) { sendJson(['error' => 'Setlist not found.'], 404); break; }

                /* Resolve collaborator by email */
                $usr = $db->prepare('SELECT Id, Username FROM tblUsers WHERE Email = ? LIMIT 1');
                $usr->bind_param('s', $collabEml);
                $usr->execute();
                $collab = $usr->get_result()->fetch_assoc();
                $usr->close();
                if (!$collab) {
                    sendJson(['error' => 'No user with that email yet — ask them to sign in first.'], 404);
                    break;
                }
                $collabId = (int)$collab['Id'];
                if ($collabId === $authUserId) {
                    sendJson(['error' => 'You cannot invite yourself.'], 400);
                    break;
                }

                $ins = $db->prepare(
                    'INSERT INTO tblSetlistCollaborators (SetlistOwnerId, SetlistId, CollaboratorId, Permission)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE Permission = VALUES(Permission)'
                );
                $ins->bind_param('isis', $authUserId, $setlistId, $collabId, $permission);
                $ins->execute();
                $ins->close();

                /* Record the invite into the rate-limit counter so the
                   per-user/hour cap can actually trip. Bucket key
                   matches the checkRateLimit() call above. */
                recordRateLimitHit('setlist_collab_invite', 'user:' . (int)$authUser['Id']);

                /* Audit (#535) — collaborator id + permission lets
                   admins see who invited whom on what setlist. */
                logActivity('setlist.collab_invite', 'setlist', $setlistId, [
                    'collaborator_id'       => (int)$collab['Id'],
                    'collaborator_username' => $collab['Username'],
                    'permission'            => $permission,
                ]);

                sendJson([
                    'ok' => true,
                    'collaborator' => [
                        'id'         => (int)$collab['Id'],
                        'username'   => $collab['Username'],
                        'permission' => $permission,
                    ],
                ]);
            } catch (\Throwable $e) {
                error_log('[setlist_collab_invite] ' . $e->getMessage());
                logActivityError('setlist.collab_invite', 'setlist', $setlistId, $e);
                sendJson(['error' => 'Could not invite collaborator.'], 500);
            }
            break;

        case 'setlist_collab_list':
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }
            $setlistId = trim((string)($_GET['setlistId'] ?? ''));
            if ($setlistId === '') { sendJson(['error' => 'setlistId required.'], 400); break; }
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT c.CollaboratorId AS id, u.Username AS username, u.Email AS email,
                            c.Permission AS permission, c.InvitedAt AS invitedAt
                       FROM tblSetlistCollaborators c
                       JOIN tblUsers u ON u.Id = c.CollaboratorId
                      WHERE c.SetlistOwnerId = ? AND c.SetlistId = ?
                      ORDER BY c.InvitedAt DESC'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('is', $authUserId, $setlistId);
                $stmt->execute();
                $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                sendJson(['collaborators' => $list]);
            } catch (\Throwable $e) {
                error_log('[setlist_collab_list] ' . $e->getMessage());
                sendJson(['error' => 'Could not load collaborators.'], 500);
            }
            break;

        case 'setlist_collab_remove':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }
            $body = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $setlistId = trim((string)($body['setlistId'] ?? ''));
            $collabId  = (int)($body['collaboratorId'] ?? 0);
            if ($setlistId === '' || $collabId <= 0) {
                sendJson(['error' => 'setlistId and collaboratorId required.'], 400);
                break;
            }
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'DELETE FROM tblSetlistCollaborators
                      WHERE SetlistOwnerId = ? AND SetlistId = ? AND CollaboratorId = ?'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('isi', $authUserId, $setlistId, $collabId);
                $stmt->execute();
                $removed = $stmt->affected_rows;
                $stmt->close();
                if ($removed > 0) {
                    logActivity('setlist.collab_remove', 'setlist', $setlistId, [
                        'collaborator_id' => $collabId,
                    ]);
                }
                sendJson(['ok' => true, 'removed' => $removed]);
            } catch (\Throwable $e) {
                error_log('[setlist_collab_remove] ' . $e->getMessage());
                logActivityError('setlist.collab_remove', 'setlist', $setlistId, $e);
                sendJson(['error' => 'Could not remove collaborator.'], 500);
            }
            break;

        case 'setlist_collab_shared_with_me':
            $authUser = getAuthenticatedUser();
            if (!$authUser) { sendJson(['error' => 'Unauthorized'], 401); break; }
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT c.SetlistOwnerId AS ownerId, u.Username AS ownerName,
                            c.SetlistId AS setlistId, c.Permission AS permission,
                            l.Name AS name
                       FROM tblSetlistCollaborators c
                       JOIN tblUsers u ON u.Id = c.SetlistOwnerId
                       LEFT JOIN tblUserSetlists l
                              ON l.UserId = c.SetlistOwnerId AND l.SetlistId = c.SetlistId
                      WHERE c.CollaboratorId = ?
                      ORDER BY c.InvitedAt DESC'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $shared = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                sendJson(['shared' => $shared]);
            } catch (\Throwable $e) {
                error_log('[setlist_collab_shared_with_me] ' . $e->getMessage());
                sendJson(['error' => 'Could not load shared setlists.'], 500);
            }
            break;

        case 'song_request_submit':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }

            /* Accept BOTH application/json AND application/x-www-form-urlencoded.
               JS path uses JSON; the no-JS fallback (form action= attribute on
               the /request page) submits form-encoded so it still works when
               the page's <script type="module"> fails to load (#711). */
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isFormPost  = stripos($contentType, 'application/x-www-form-urlencoded') !== false
                        || stripos($contentType, 'multipart/form-data')              !== false;
            if ($isFormPost) {
                $body = $_POST;
            } else {
                $body = json_decode((string)file_get_contents('php://input'), true) ?? [];
            }
            $title   = trim((string)($body['title']    ?? ''));
            $book    = trim((string)($body['songbook'] ?? ''));
            $details = trim((string)($body['details']  ?? ''));
            $email   = trim((string)($body['email']    ?? ''));
            $honey   = (string)($body['website']       ?? '');
            $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

            /* Helper that returns either JSON (JS path) or a 302 redirect to
               /request?submitted=… (no-JS fallback). The redirect target shows
               a server-rendered success banner so the user gets the same
               feedback either way. (#711) */
            $respondOk = function (int $trackingId) use ($isFormPost) {
                if ($isFormPost) {
                    header('Location: /request?submitted=1&id=' . $trackingId, true, 302);
                    exit;
                }
                sendJson(['ok' => true, 'trackingId' => $trackingId]);
            };
            $respondErr = function (string $msg, int $code) use ($isFormPost) {
                if ($isFormPost) {
                    header('Location: /request?error=' . urlencode($msg), true, 302);
                    exit;
                }
                sendJson(['error' => $msg], $code);
            };

            /* Honeypot: real users leave this blank; bots fill every field. */
            if ($honey !== '') {
                $respondOk(0); /* Silent success to not tip off the bot. */
                break;
            }

            if ($title === '') {
                $respondErr('A song title is required.', 400);
                break;
            }
            if (mb_strlen($title)   > 500)  { $respondErr('Title too long.',    400); break; }
            if (mb_strlen($book)    > 100)  { $respondErr('Songbook too long.', 400); break; }
            if (mb_strlen($details) > 2000) { $respondErr('Details too long.',  400); break; }
            if (mb_strlen($email)   > 255)  { $respondErr('Email too long.',    400); break; }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $respondErr('Email address is not valid.', 400);
                break;
            }

            try {
                $db = getDbMysqli();

                /* Rate-limit: reject if this IP has ≥5 submissions in the last 24 h. */
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM tblSongRequests WHERE IpAddress = ? AND CreatedAt > (NOW() - INTERVAL 1 DAY)'
                );
                $stmt->bind_param('s', $ip);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ((int)($row[0] ?? 0) >= 5) {
                    $respondErr('You have submitted several requests recently. Please try again tomorrow.', 429);
                    break;
                }

                /* Link to a signed-in user if the caller sent a bearer token. */
                $authUser = getAuthenticatedUser();
                $userId   = $authUser ? (int)$authUser['Id'] : null;

                /* Status literal in single quotes — double quotes were
                   used here originally and break under sql_mode='ANSI_QUOTES'
                   where MySQL parses "pending" as a column reference rather
                   than a string literal. Single quotes are the unambiguous
                   form per SQL standard. (#711) */
                $stmt = $db->prepare(
                    "INSERT INTO tblSongRequests
                        (Title, Songbook, Details, ContactEmail, UserId, IpAddress, Status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );
                /* UserId(i nullable) — anonymous submissions OK. */
                $stmt->bind_param('ssssis', $title, $book, $details, $email, $userId, $ip);
                $stmt->execute();
                $trackingId = (int)$db->insert_id;
                $stmt->close();

                /* Audit (#535). Public endpoint — userId may be null
                   for anonymous submissions; logActivityResolveUserId()
                   will then leave UserId NULL on the row. */
                logActivity('song.request_submit', 'song_request', (string)$trackingId, [
                    'title'        => $title,
                    'songbook'     => $book,
                    'has_email'    => $email !== '',
                    'has_details'  => $details !== '',
                    'authenticated'=> $userId !== null,
                ]);

                $respondOk($trackingId);
            } catch (\Throwable $e) {
                error_log('[song_request_submit] ' . $e->getMessage());
                logActivityError('song.request_submit', 'song_request', '', $e);
                $respondErr('Could not save your request. Please try again.', 500);
            }
            break;

        /* -----------------------------------------------------------------
         * #462 — Effective licence set for a given user (admin-only).
         * Returns the resolved inheritance-aware list for debugging +
         * future admin UI. Shape matches licences.php::getUserEffectiveLicences.
         *
         * Restored after a Friday-night merge accidentally replaced
         * this case with notifications_list (#291 follow-up).
         *
         *   GET /api?action=user_effective_licences&user_id=<int>
         * ----------------------------------------------------------------- */
        case 'user_effective_licences':
            $authUser = getAuthenticatedUser();
            if (!$authUser || !userHasEntitlement('view_licence_audit', $authUser['Role'] ?? null)) {
                sendJson(['error' => 'Not authorised.'], 403);
                break;
            }
            $targetId = (int)($_GET['user_id'] ?? 0);
            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }
            require_once __DIR__ . '/includes/licences.php';
            sendJson([
                'user_id'  => $targetId,
                'licences' => getUserEffectiveLicences($targetId),
            ]);
            break;

        /* -----------------------------------------------------------------
         * #462 — Current user's licence check. Returns { has: bool } for
         * a given licence type, walking the org hierarchy. Any
         * authenticated caller may ask about their own set so the
         * frontend can show/hide features.
         *   GET /api?action=licence_check&type=ccli
         * ----------------------------------------------------------------- */
        case 'licence_check':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Authentication required.'], 401);
                break;
            }
            $type = trim((string)($_GET['type'] ?? ''));
            if ($type === '') {
                sendJson(['error' => 'type required.'], 400);
                break;
            }
            require_once __DIR__ . '/includes/licences.php';
            sendJson([
                'type' => $type,
                'has'  => userHasEffectiveLicence((int)$authUser['Id'], $type),
            ]);
            break;

        /* -----------------------------------------------------------------
         * #289 — List in-app notifications for the current user.
         *   GET /api?action=notifications_list
         *
         * Unread rows first, then a trailing window of the most recent
         * read rows (capped at 50), so the header dropdown has recent
         * context without a "show all" follow-up round trip.
         * ----------------------------------------------------------------- */
        case 'notifications_list':
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }
            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT Id AS id,
                            Type AS type,
                            Title AS title,
                            Body AS body,
                            ActionUrl AS action_url,
                            IsRead AS is_read,
                            CreatedAt AS created_at
                       FROM tblNotifications
                      WHERE UserId = ?
                      ORDER BY IsRead ASC, CreatedAt DESC
                      LIMIT 50'
                );
                $authUserId = (int)$authUser['Id'];
                $stmt->bind_param('i', $authUserId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as &$r) {
                    $r['id']      = (int)$r['id'];
                    $r['is_read'] = (bool)$r['is_read'];
                }
                unset($r);
                sendJson(['items' => $rows]);
            } catch (\Throwable $e) {
                error_log('[notifications_list] ' . $e->getMessage());
                sendJson(['items' => []]);
            }
            break;

        /* -----------------------------------------------------------------
         * #289 — Mark one or more notifications as read.
         *   POST { ids: [1,2,3] }   — mark specific rows
         *   POST { all: true }      — mark every unread row for this user
         * ----------------------------------------------------------------- */
        case 'notifications_mark_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }
            $body   = json_decode(file_get_contents('php://input'), true) ?: [];
            $userId = (int)$authUser['Id'];
            try {
                $db = getDbMysqli();
                if (!empty($body['all'])) {
                    $stmt = $db->prepare(
                        'UPDATE tblNotifications SET IsRead = 1
                          WHERE UserId = ? AND IsRead = 0'
                    );
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    sendJson(['ok' => true, 'affected' => $affected]);
                    break;
                }
                $ids = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? [])), fn($i) => $i > 0));
                if (empty($ids)) {
                    sendJson(['error' => 'ids or all=true required.'], 400);
                    break;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare(
                    "UPDATE tblNotifications SET IsRead = 1
                      WHERE UserId = ? AND Id IN ($placeholders)"
                );
                /* Dynamic IN-list: UserId(i) + ids…(all i). */
                $params = array_merge([$userId], $ids);
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                sendJson(['ok' => true, 'affected' => $affected]);
            } catch (\Throwable $e) {
                error_log('[notifications_mark_read] ' . $e->getMessage());
                sendJson(['error' => 'Could not mark as read.'], 500);
            }
            break;

        /* =================================================================
         * SONGBOOKS — admin CRUD parity (#719 PR 2a)
         *
         * Mirrors the web-admin POST handlers in /manage/songbooks.php so
         * native clients (Apple, Android, FireOS) can perform songbook
         * curation without a webview. Field names are JSON-body snake_case
         * to match the rest of /api.php; the underlying SQL writes the
         * same column set as the web admin.
         *
         * Auth: admin / global_admin role only — same gate that grants the
         * `manage_songbooks` entitlement (see includes/entitlements.php).
         *
         * Activity-log verb prefix is `api.admin.songbook.*` so a curator
         * scanning /manage/activity-log can tell API-driven changes apart
         * from web-UI changes (which still log under `songbook.*`).
         *
         * Validation lives in includes/songbook_validation.php — the same
         * file the web admin uses. One source of truth for abbreviation,
         * colour, and IETF BCP 47 language-tag rules.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: create a songbook
         * POST body: {
         *   abbreviation, name, display_order?, colour?,
         *   is_official?, publisher?, publication_year?,
         *   copyright?, affiliation?, language?,
         *   website_url?, internet_archive_url?, wikipedia_url?,
         *   wikidata_id?, oclc_number?, ocn_number?, lcp_number?,
         *   isbn?, ark_id?, isni_id?, viaf_id?, lccn?, lc_class?
         * }
         * Empty colour triggers the auto-pick palette (#677). Conflict on
         * duplicate abbreviation returns 409. Returns 201 + new id +
         * resolved colour so the caller can render the badge immediately.
         * ----------------------------------------------------------------- */
        case 'admin_songbook_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];

            $abbr     = trim((string)($body['abbreviation']  ?? ''));
            $name     = trim((string)($body['name']          ?? ''));
            $colour   = trim((string)($body['colour']        ?? ''));
            $order    = (int)($body['display_order']         ?? 0);
            $isOfficial = !empty($body['is_official']) ? 1 : 0;
            $publisher  = trim((string)($body['publisher']        ?? '')) ?: null;
            $pubYear    = trim((string)($body['publication_year'] ?? '')) ?: null;
            $copyright  = trim((string)($body['copyright']        ?? '')) ?: null;
            $affiliation= trim((string)($body['affiliation']      ?? '')) ?: null;

            /* Optional language tag (#673 / #681). Cap to column width
               (35 chars) before validating so a crafted-long tag can't
               smuggle past the regex via mid-string truncation. */
            $language   = trim((string)($body['language']         ?? '')) ?: null;
            if ($language !== null) {
                $language = mb_substr($language, 0, 35);
                if ($e = validateSongbookBcp47($language)) {
                    sendJson(['error' => $e], 400);
                    break;
                }
            }

            $websiteUrl   = trim((string)($body['website_url']         ?? '')) ?: null;
            $iaUrl        = trim((string)($body['internet_archive_url']?? '')) ?: null;
            $wikipediaUrl = trim((string)($body['wikipedia_url']       ?? '')) ?: null;
            $wikidataId   = trim((string)($body['wikidata_id']         ?? '')) ?: null;
            $oclcNumber   = trim((string)($body['oclc_number']         ?? '')) ?: null;
            $ocnNumber    = trim((string)($body['ocn_number']          ?? '')) ?: null;
            $lcpNumber    = trim((string)($body['lcp_number']          ?? '')) ?: null;
            $isbn         = trim((string)($body['isbn']                ?? '')) ?: null;
            $arkId        = trim((string)($body['ark_id']              ?? '')) ?: null;
            $isniId       = trim((string)($body['isni_id']             ?? '')) ?: null;
            $viafId       = trim((string)($body['viaf_id']             ?? '')) ?: null;
            $lccn         = trim((string)($body['lccn']                ?? '')) ?: null;
            $lcClass      = trim((string)($body['lc_class']            ?? '')) ?: null;

            if ($e = validateSongbookAbbr($abbr)) {
                sendJson(['error' => $e], 400);
                break;
            }
            if ($name === '') {
                sendJson(['error' => 'Name is required.'], 400);
                break;
            }
            if ($e = validateSongbookColour($colour)) {
                sendJson(['error' => $e], 400);
                break;
            }

            try {
                $db = getDbMysqli();

                /* Auto-colour fallback (#677) — palette helper lives in
                   the manage tree because that's where the seed colours
                   are documented. Lazy-loaded so the read paths above
                   don't pay the include cost. */
                if ($colour === '') {
                    require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                               . 'includes'  . DIRECTORY_SEPARATOR . 'songbook-palette.php';
                    $colour = pickAutoSongbookColour($db, $abbr);
                }

                $stmt = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) {
                    sendJson(['error' => 'Abbreviation already exists.'], 409);
                    break;
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblSongbooks
                        (Abbreviation, Name, DisplayOrder, Colour,
                         IsOfficial, Publisher, PublicationYear, Copyright, Affiliation,
                         Language,
                         WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
                         OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
                         ViafId, Lccn, LcClass)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                             ?,
                             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                /* 23-char type string mirrors the web admin save (#694
                   regression check): ssis (Abbr,Name,Order,Colour) +
                   isssss (IsOfficial,Publisher,Year,Copyright,Affiliation)
                   + s (Language) + 13s (bibliographic identifiers). */
                $orderInt = (int)($order ?: 0);
                $stmt->bind_param(
                    'ssisissssssssssssssssss',
                    $abbr, $name, $orderInt, $colour,
                    $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
                    $language,
                    $websiteUrl, $iaUrl, $wikipediaUrl, $wikidataId,
                    $oclcNumber, $ocnNumber, $lcpNumber, $isbn, $arkId, $isniId,
                    $viafId, $lccn, $lcClass
                );
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                logActivity('api.admin.songbook.create', 'songbook', (string)$newId, [
                    'abbreviation'    => $abbr,
                    'name'            => $name,
                    'display_order'   => $orderInt,
                    'colour'          => $colour,
                    'is_official'     => (bool)$isOfficial,
                    'publisher'       => $publisher,
                    'publication_year'=> $pubYear,
                    'copyright'       => $copyright,
                    'affiliation'     => $affiliation,
                    'language'        => $language,
                    'bibliographic'   => array_filter([
                        'website_url'           => $websiteUrl,
                        'internet_archive_url'  => $iaUrl,
                        'wikipedia_url'         => $wikipediaUrl,
                        'wikidata_id'           => $wikidataId,
                        'oclc_number'           => $oclcNumber,
                        'ocn_number'            => $ocnNumber,
                        'lcp_number'            => $lcpNumber,
                        'isbn'                  => $isbn,
                        'ark_id'                => $arkId,
                        'isni_id'               => $isniId,
                        'viaf_id'               => $viafId,
                        'lccn'                  => $lccn,
                        'lc_class'              => $lcClass,
                    ], fn($v) => $v !== null && $v !== ''),
                ]);

                /* Self-populate affiliation registry (#670). */
                registerSongbookAffiliation($db, $affiliation);

                sendJson([
                    'ok'           => true,
                    'id'           => $newId,
                    'abbreviation' => $abbr,
                    'colour'       => $colour,
                ], 201);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.create', 'songbook', '', $e, [
                    'abbreviation' => $abbr,
                ]);
                error_log('[admin_songbook_create] ' . $e->getMessage());
                sendJson(['error' => 'Could not create songbook.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: update a songbook
         * POST body: {
         *   id (required),
         *   name, colour, display_order,
         *   new_abbreviation? (rename — opt-in),
         *   rename_song_refs? (cascade abbr to tblSongs.SongbookAbbr),
         *   …same metadata fields as create
         * }
         * Returns 200 + ok + list of changed fields. The before/after rows
         * are written to the activity log so the timeline reader sees
         * exactly what the call mutated.
         * ----------------------------------------------------------------- */
        case 'admin_songbook_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];

            $id          = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Songbook id required.'], 400);
                break;
            }
            $name        = trim((string)($body['name']         ?? ''));
            $colour      = trim((string)($body['colour']       ?? ''));
            $order       = (int)($body['display_order']        ?? 0);
            $newAbbr     = trim((string)($body['new_abbreviation'] ?? ''));
            $alsoRename  = !empty($body['rename_song_refs']);
            $isOfficial  = !empty($body['is_official']) ? 1 : 0;
            $publisher   = trim((string)($body['publisher']        ?? '')) ?: null;
            $pubYear     = trim((string)($body['publication_year'] ?? '')) ?: null;
            $copyright   = trim((string)($body['copyright']        ?? '')) ?: null;
            $affiliation = trim((string)($body['affiliation']      ?? '')) ?: null;

            $language    = trim((string)($body['language']         ?? '')) ?: null;
            if ($language !== null) {
                $language = mb_substr($language, 0, 35);
                if ($e = validateSongbookBcp47($language)) {
                    sendJson(['error' => $e], 400);
                    break;
                }
            }

            $websiteUrl   = trim((string)($body['website_url']         ?? '')) ?: null;
            $iaUrl        = trim((string)($body['internet_archive_url']?? '')) ?: null;
            $wikipediaUrl = trim((string)($body['wikipedia_url']       ?? '')) ?: null;
            $wikidataId   = trim((string)($body['wikidata_id']         ?? '')) ?: null;
            $oclcNumber   = trim((string)($body['oclc_number']         ?? '')) ?: null;
            $ocnNumber    = trim((string)($body['ocn_number']          ?? '')) ?: null;
            $lcpNumber    = trim((string)($body['lcp_number']          ?? '')) ?: null;
            $isbn         = trim((string)($body['isbn']                ?? '')) ?: null;
            $arkId        = trim((string)($body['ark_id']              ?? '')) ?: null;
            $isniId       = trim((string)($body['isni_id']             ?? '')) ?: null;
            $viafId       = trim((string)($body['viaf_id']             ?? '')) ?: null;
            $lccn         = trim((string)($body['lccn']                ?? '')) ?: null;
            $lcClass      = trim((string)($body['lc_class']            ?? '')) ?: null;

            if ($name === '') {
                sendJson(['error' => 'Name is required.'], 400);
                break;
            }
            if ($e = validateSongbookColour($colour)) {
                sendJson(['error' => $e], 400);
                break;
            }

            try {
                $db = getDbMysqli();

                /* Capture the full before-row for diff-aware audit logs (#535).
                   Match the web admin's SELECT shape exactly so the diff key set
                   stays consistent across both surfaces. */
                $existing = $db->prepare(
                    'SELECT Abbreviation, Name, DisplayOrder, Colour, IsOfficial,
                            Publisher, PublicationYear, Copyright, Affiliation,
                            Language,
                            WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
                            OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
                            ViafId, Lccn, LcClass
                       FROM tblSongbooks WHERE Id = ?'
                );
                $existing->bind_param('i', $id);
                $existing->execute();
                $beforeRow = $existing->get_result()->fetch_assoc() ?: null;
                $existing->close();
                $oldAbbr = $beforeRow ? (string)$beforeRow['Abbreviation'] : '';
                if ($oldAbbr === '') {
                    sendJson(['error' => 'Songbook not found.'], 404);
                    break;
                }

                $abbrChanged = $newAbbr !== '' && $newAbbr !== $oldAbbr;
                if ($abbrChanged) {
                    if ($e = validateSongbookAbbr($newAbbr)) {
                        sendJson(['error' => $e], 400);
                        break;
                    }
                    $dup = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ? AND Id <> ?');
                    $dup->bind_param('si', $newAbbr, $id);
                    $dup->execute();
                    $dupExists = $dup->get_result()->fetch_row() !== null;
                    $dup->close();
                    if ($dupExists) {
                        sendJson(['error' => 'That abbreviation is already taken.'], 409);
                        break;
                    }
                }

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblSongbooks
                            SET Name = ?, Colour = ?, DisplayOrder = ?,
                                IsOfficial = ?, Publisher = ?,
                                PublicationYear = ?, Copyright = ?, Affiliation = ?,
                                Language = ?,
                                WebsiteUrl = ?, InternetArchiveUrl = ?,
                                WikipediaUrl = ?, WikidataId = ?,
                                OclcNumber = ?, OcnNumber = ?, LcpNumber = ?,
                                Isbn = ?, ArkId = ?, IsniId = ?,
                                ViafId = ?, Lccn = ?, LcClass = ?
                          WHERE Id = ?'
                    );
                    /* 23-char type string: ssi (Name,Colour,Order)
                       + issss (IsOfficial,Publisher,Year,Copyright,Affiliation)
                       + s (Language) + 13s (bibliographic) + i (Id). */
                    $orderInt = (int)($order ?: 0);
                    $stmt->bind_param(
                        'ssiissssssssssssssssssi',
                        $name, $colour, $orderInt,
                        $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
                        $language,
                        $websiteUrl, $iaUrl, $wikipediaUrl, $wikidataId,
                        $oclcNumber, $ocnNumber, $lcpNumber, $isbn, $arkId, $isniId,
                        $viafId, $lccn, $lcClass,
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();

                    if ($abbrChanged) {
                        $stmt = $db->prepare('UPDATE tblSongbooks SET Abbreviation = ? WHERE Id = ?');
                        $stmt->bind_param('si', $newAbbr, $id);
                        $stmt->execute();
                        $stmt->close();
                        if ($alsoRename) {
                            $stmt = $db->prepare('UPDATE tblSongs SET SongbookAbbr = ? WHERE SongbookAbbr = ?');
                            $stmt->bind_param('ss', $newAbbr, $oldAbbr);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                /* Audit (#535) — narrow the row to the actually-changed
                   fields so the timeline reader doesn't have to skim
                   identical before/after pairs. */
                $afterRow = [
                    'Abbreviation'      => $abbrChanged ? $newAbbr : $oldAbbr,
                    'Name'              => $name,
                    'DisplayOrder'      => (int)($order ?: 0),
                    'Colour'            => $colour,
                    'IsOfficial'        => $isOfficial,
                    'Publisher'         => $publisher,
                    'PublicationYear'   => $pubYear,
                    'Copyright'         => $copyright,
                    'Affiliation'       => $affiliation,
                    'Language'          => $language,
                    'WebsiteUrl'        => $websiteUrl,
                    'InternetArchiveUrl'=> $iaUrl,
                    'WikipediaUrl'      => $wikipediaUrl,
                    'WikidataId'        => $wikidataId,
                    'OclcNumber'        => $oclcNumber,
                    'OcnNumber'         => $ocnNumber,
                    'LcpNumber'         => $lcpNumber,
                    'Isbn'              => $isbn,
                    'ArkId'             => $arkId,
                    'IsniId'            => $isniId,
                    'ViafId'            => $viafId,
                    'Lccn'              => $lccn,
                    'LcClass'           => $lcClass,
                ];
                $changed = [];
                foreach ($afterRow as $k => $v) {
                    if (!array_key_exists($k, $beforeRow ?? [])) continue;
                    if ((string)$beforeRow[$k] !== (string)$v) $changed[] = $k;
                }
                logActivity('api.admin.songbook.edit', 'songbook', (string)$id, [
                    'fields'             => $changed,
                    'before'             => array_intersect_key($beforeRow, array_flip($changed)),
                    'after'              => array_intersect_key($afterRow,  array_flip($changed)),
                    'songs_renamed_too'  => $alsoRename && $abbrChanged,
                ]);

                if (in_array('Affiliation', $changed, true)) {
                    registerSongbookAffiliation($db, $affiliation);
                }

                sendJson([
                    'ok'             => true,
                    'id'             => $id,
                    'abbreviation'   => $abbrChanged ? $newAbbr : $oldAbbr,
                    'fields_changed' => $changed,
                    'songs_renamed'  => $alsoRename && $abbrChanged,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.edit', 'songbook', (string)$id, $e);
                error_log('[admin_songbook_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update songbook.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete an empty songbook
         * POST body: { id }
         * Refuses if any song still references the abbreviation — the
         * caller must reassign or use admin_songbook_delete_cascade.
         * Returns 422 (cannot delete) when the dependency check fails so
         * native UIs can distinguish "wrong id" (404) from "not safe yet".
         * ----------------------------------------------------------------- */
        case 'admin_songbook_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Songbook id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();

                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $abbr = (string)($row[0] ?? '');
                if ($abbr === '') {
                    sendJson(['error' => 'Songbook not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $songCount = (int)($row[0] ?? 0);
                if ($songCount > 0) {
                    sendJson([
                        'error'        => "Cannot delete '{$abbr}': {$songCount} song(s) still reference it.",
                        'song_count'   => $songCount,
                        'abbreviation' => $abbr,
                        'hint'         => 'Reassign the songs OR call admin_songbook_delete_cascade.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.songbook.delete', 'songbook', (string)$id, [
                    'abbreviation' => $abbr,
                ]);

                sendJson([
                    'ok'           => true,
                    'id'           => $id,
                    'abbreviation' => $abbr,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.delete', 'songbook', (string)$id, $e);
                error_log('[admin_songbook_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete songbook.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: cascade delete (songbook + every song + every credit /
         * tag / chord / translation that referenced those songs)
         * POST body: { id, confirm_abbreviation }
         * Server-side typed-confirmation gate mirrors the web admin
         * defence-in-depth check (#706). Admin / global_admin only.
         * Returns 200 + song_count so the caller can render an honest
         * "deleted N songs" toast.
         * ----------------------------------------------------------------- */
        case 'admin_songbook_delete_cascade': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Songbook id required.'], 400);
                break;
            }
            $typed = trim((string)($body['confirm_abbreviation'] ?? ''));

            try {
                $db = getDbMysqli();

                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $abbr = (string)($row[0] ?? '');
                if ($abbr === '') {
                    sendJson(['error' => 'Songbook not found.'], 404);
                    break;
                }
                if ($typed !== $abbr) {
                    sendJson([
                        'error' => "Cascade delete cancelled — confirm_abbreviation must equal the songbook abbreviation '{$abbr}' exactly.",
                    ], 400);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $songCount = (int)($row[0] ?? 0);

                $db->begin_transaction();
                try {
                    /* DELETE the songs — cascades to every credit / tag /
                       chord / translation / artist / request-resolved
                       reference / etc. via the FK ON DELETE CASCADE rules.
                       The FK on SongbookAbbr is ON DELETE RESTRICT so we
                       MUST delete the songs first; then the songbook row
                       goes cleanly. */
                    $stmt = $db->prepare('DELETE FROM tblSongs WHERE SongbookAbbr = ?');
                    $stmt->bind_param('s', $abbr);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $db->prepare('DELETE FROM tblSongbooks WHERE Id = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                logActivity('api.admin.songbook.delete_cascade', 'songbook', (string)$id, [
                    'abbreviation' => $abbr,
                    'song_count'   => $songCount,
                ]);

                sendJson([
                    'ok'           => true,
                    'id'           => $id,
                    'abbreviation' => $abbr,
                    'song_count'   => $songCount,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.delete_cascade', 'songbook', (string)$id, $e);
                error_log('[admin_songbook_delete_cascade] ' . $e->getMessage());
                sendJson(['error' => 'Could not cascade-delete songbook.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: bulk reorder
         * POST body: { display_order: { "<id>": <int>, ... } }
         * Single transaction; one activity-log row for the bulk op.
         * ----------------------------------------------------------------- */
        case 'admin_songbooks_reorder': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orders = $body['display_order'] ?? null;
            if (!is_array($orders) || empty($orders)) {
                sendJson(['error' => 'display_order map (id => order) required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $db->begin_transaction();
                $count = 0;
                try {
                    $stmt = $db->prepare('UPDATE tblSongbooks SET DisplayOrder = ? WHERE Id = ?');
                    foreach ($orders as $rowId => $value) {
                        $valueInt = (int)$value;
                        $idInt    = (int)$rowId;
                        if ($idInt <= 0) continue;
                        $stmt->bind_param('ii', $valueInt, $idInt);
                        $stmt->execute();
                        $count++;
                    }
                    $stmt->close();
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                logActivity('api.admin.songbook.reorder', 'songbook', '', [
                    'count' => $count,
                    'order' => array_map(fn($v) => (int)$v, (array)$orders),
                ]);

                sendJson(['ok' => true, 'count' => $count]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.reorder', 'songbook', '', $e);
                error_log('[admin_songbooks_reorder] ' . $e->getMessage());
                sendJson(['error' => 'Could not reorder songbooks.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: auto-colour fill (NULL/blank colours only)
         * POST body: {}
         * Walks tblSongbooks; rows without a valid #RRGGBB get a
         * freshly-picked palette colour. Existing values left alone.
         * Admin / global_admin only.
         * ----------------------------------------------------------------- */
        case 'admin_songbooks_auto_colour_fill': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            try {
                $db = getDbMysqli();
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                           . 'includes'  . DIRECTORY_SEPARATOR . 'songbook-palette.php';

                $stmt = $db->prepare('SELECT Id, Abbreviation, Colour FROM tblSongbooks ORDER BY Id');
                $stmt->execute();
                $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $db->begin_transaction();
                $changed = 0;
                try {
                    $up = $db->prepare('UPDATE tblSongbooks SET Colour = ? WHERE Id = ?');
                    foreach ($books as $b) {
                        $existing = trim((string)($b['Colour'] ?? ''));
                        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $existing)) continue;
                        $newColour = pickAutoSongbookColour($db, (string)$b['Abbreviation']);
                        $bookId    = (int)$b['Id'];
                        $up->bind_param('si', $newColour, $bookId);
                        $up->execute();
                        $changed++;
                    }
                    $up->close();
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                logActivity('api.admin.songbook.auto_colour_fill', 'songbook', '', [
                    'count' => $changed,
                    'mode'  => 'fill',
                ]);

                sendJson(['ok' => true, 'changed' => $changed, 'mode' => 'fill']);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.auto_colour_fill', 'songbook', '', $e);
                error_log('[admin_songbooks_auto_colour_fill] ' . $e->getMessage());
                sendJson(['error' => 'Could not auto-fill colours.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: auto-colour reassign (every row gets a fresh colour)
         * POST body: { confirm_phrase: "REASSIGN ALL" }
         * Destructive — overwrites every Colour. Server-side phrase gate
         * mirrors the web admin's defence-in-depth check. Admin /
         * global_admin only.
         * ----------------------------------------------------------------- */
        case 'admin_songbooks_auto_colour_reassign': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body  = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $typed = trim((string)($body['confirm_phrase'] ?? ''));
            if ($typed !== 'REASSIGN ALL') {
                sendJson(['error' => 'Reassign-all needs confirm_phrase="REASSIGN ALL".'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                           . 'includes'  . DIRECTORY_SEPARATOR . 'songbook-palette.php';

                $stmt = $db->prepare('SELECT Id, Abbreviation FROM tblSongbooks ORDER BY Id');
                $stmt->execute();
                $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $db->begin_transaction();
                $changed = 0;
                try {
                    $up = $db->prepare('UPDATE tblSongbooks SET Colour = ? WHERE Id = ?');
                    foreach ($books as $b) {
                        $newColour = pickAutoSongbookColour($db, (string)$b['Abbreviation']);
                        $bookId    = (int)$b['Id'];
                        $up->bind_param('si', $newColour, $bookId);
                        $up->execute();
                        $changed++;
                    }
                    $up->close();
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                logActivity('api.admin.songbook.auto_colour_reassign', 'songbook', '', [
                    'count' => $changed,
                    'mode'  => 'reassign',
                ]);

                sendJson(['ok' => true, 'changed' => $changed, 'mode' => 'reassign']);
            } catch (\Throwable $e) {
                logActivityError('api.admin.songbook.auto_colour_reassign', 'songbook', '', $e);
                error_log('[admin_songbooks_auto_colour_reassign] ' . $e->getMessage());
                sendJson(['error' => 'Could not reassign colours.'], 500);
            }
            break;
        }

        /* =================================================================
         * USERS — admin CRUD parity (#719 PR 2b)
         *
         * Mirrors the web-admin POST handlers in /manage/users.php so
         * native clients can perform user curation without a webview.
         * The actual mutation goes through the helpers in
         * manage/includes/auth.php (createUser / updateUserRole / etc.)
         * — those helpers already write the canonical activity-log
         * row (`user.create`, `auth.role_change`, ...). The endpoints
         * here only add a parallel `api.admin.user.*` row on the catch
         * path so failures surface under the API surface prefix in
         * /manage/activity-log.
         *
         * Hierarchy gates mirror users.php exactly: an admin can't
         * promote above their own level, can't act on a peer or
         * superior unless they are global_admin, and can't act on
         * themselves for the destructive verbs.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: create a user
         * POST body: { username, password, display_name?, role?, email? }
         * Defaults: role=editor, display_name=username, email=''.
         * Returns 201 + new user id.
         * ----------------------------------------------------------------- */
        case 'admin_user_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $username    = trim((string)($body['username']     ?? ''));
            $password    = (string)($body['password']          ?? '');
            $displayName = trim((string)($body['display_name'] ?? '')) ?: $username;
            $role        = trim((string)($body['role']         ?? 'editor'));
            $email       = trim((string)($body['email']        ?? ''));

            if (mb_strlen($username) < 3) {
                sendJson(['error' => 'Username must be at least 3 characters.'], 400);
                break;
            }
            if (strlen($password) < 8) {
                sendJson(['error' => 'Password must be at least 8 characters.'], 400);
                break;
            }
            if (!in_array($role, allRoles(), true)) {
                sendJson(['error' => 'Invalid role.'], 400);
                break;
            }
            /* Hierarchy gate (mirrors users.php). Only global_admin can
               assign global_admin; nobody can promote above their own
               level. */
            $actingRole = (string)$authUser['Role'];
            if ($role === 'global_admin' && $actingRole !== 'global_admin') {
                sendJson(['error' => 'Only Global Admin can assign the Global Admin role.'], 403);
                break;
            }
            if (roleLevel($role) > roleLevel($actingRole)) {
                sendJson(['error' => 'Cannot assign a role higher than your own.'], 403);
                break;
            }

            try {
                $newUserId = createUser($username, $password, $displayName, $role, $email);
                sendJson([
                    'ok'       => true,
                    'id'       => (int)$newUserId,
                    'username' => $username,
                    'role'     => $role,
                ], 201);
            } catch (\RuntimeException $e) {
                /* createUser throws on duplicate username — surface as
                   409 Conflict so native UIs can render a sensible
                   "username already taken" prompt. */
                $status = stripos($e->getMessage(), 'already exists') !== false ? 409 : 400;
                sendJson(['error' => $e->getMessage()], $status);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.create', 'user', '', $e, ['username' => $username]);
                error_log('[admin_user_create] ' . $e->getMessage());
                sendJson(['error' => 'Could not create user.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: update a user's profile (display name + email)
         * POST body: { user_id, display_name, email? }
         * Hierarchy: an admin can edit themselves; otherwise the target
         * must be strictly below the acting user's role level (or the
         * acting user is global_admin).
         * ----------------------------------------------------------------- */
        case 'admin_user_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId    = (int)($body['user_id']      ?? 0);
            $displayName = trim((string)($body['display_name'] ?? ''));
            $email       = trim((string)($body['email']        ?? ''));

            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }
            if ($displayName === '') {
                sendJson(['error' => 'Display name cannot be empty.'], 400);
                break;
            }

            $target = getUserById($targetId);
            if (!$target) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }
            $actingId   = (int)$authUser['Id'];
            $actingRole = (string)$authUser['Role'];
            if (roleLevel((string)$target['role']) >= roleLevel($actingRole)
                && $actingRole !== 'global_admin'
                && $targetId !== $actingId) {
                sendJson(['error' => 'Cannot edit a user at or above your role level.'], 403);
                break;
            }

            try {
                updateUserProfile($targetId, $displayName, $email);
                sendJson(['ok' => true, 'id' => $targetId, 'username' => (string)$target['username']]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.update', 'user', (string)$targetId, $e);
                error_log('[admin_user_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update profile.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: rename a user (username change)
         * POST body: { user_id, new_username }
         * renameUser() returns false + error string on shape / dup
         * conflicts; the API turns those into 400/409.
         * ----------------------------------------------------------------- */
        case 'admin_user_rename': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId    = (int)($body['user_id']      ?? 0);
            $newUsername = trim((string)($body['new_username'] ?? ''));

            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }

            $target = getUserById($targetId);
            if (!$target) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }
            $actingId   = (int)$authUser['Id'];
            $actingRole = (string)$authUser['Role'];
            if (roleLevel((string)$target['role']) >= roleLevel($actingRole)
                && $actingRole !== 'global_admin'
                && $targetId !== $actingId) {
                sendJson(['error' => 'Cannot rename a user at or above your role level.'], 403);
                break;
            }

            try {
                $renameError = null;
                $ok = renameUser($targetId, $newUsername, $renameError);
                if (!$ok) {
                    /* renameUser sets $renameError to the user-friendly
                       reason. "already" → conflict, anything else → 400. */
                    $msg    = $renameError ?? 'Could not rename user.';
                    $status = stripos($msg, 'already') !== false ? 409 : 400;
                    sendJson(['error' => $msg], $status);
                    break;
                }
                sendJson([
                    'ok'           => true,
                    'id'           => $targetId,
                    'old_username' => (string)$target['username'],
                    'new_username' => mb_strtolower(trim($newUsername)),
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.rename', 'user', (string)$targetId, $e);
                error_log('[admin_user_rename] ' . $e->getMessage());
                sendJson(['error' => 'Could not rename user.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: change a user's role
         * POST body: { user_id, new_role }
         * updateUserRole() throws on hierarchy violations — turned
         * into 403 (when the message is about the role gate) or 400.
         * ----------------------------------------------------------------- */
        case 'admin_user_role_change': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body     = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId = (int)($body['user_id']  ?? 0);
            $newRole  = trim((string)($body['new_role'] ?? ''));

            if ($targetId <= 0 || $newRole === '') {
                sendJson(['error' => 'user_id and new_role required.'], 400);
                break;
            }

            try {
                /* The helper takes the same shape getCurrentUser()
                   produces — lowercase keys. Build that from the
                   bearer-token user. */
                $actingShape = [
                    'id'   => (int)$authUser['Id'],
                    'role' => (string)$authUser['Role'],
                ];
                updateUserRole($targetId, $newRole, $actingShape);
                sendJson(['ok' => true, 'id' => $targetId, 'new_role' => $newRole]);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                $isHierarchy = (stripos($msg, 'cannot')      !== false)
                            || (stripos($msg, 'only global') !== false);
                $status = $isHierarchy ? 403 : (stripos($msg, 'not found') !== false ? 404 : 400);
                sendJson(['error' => $msg], $status);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.role_change', 'user', (string)$targetId, $e);
                error_log('[admin_user_role_change] ' . $e->getMessage());
                sendJson(['error' => 'Could not change role.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: toggle active state
         * POST body: { user_id }
         * Cannot deactivate self. Cannot act on a peer or superior
         * unless global_admin.
         * Returns 200 + ok + new is_active so the caller can render
         * the toggled state without re-reading.
         * ----------------------------------------------------------------- */
        case 'admin_user_toggle_active': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body     = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId = (int)($body['user_id'] ?? 0);
            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }

            $target = getUserById($targetId);
            if (!$target) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }
            $actingId   = (int)$authUser['Id'];
            $actingRole = (string)$authUser['Role'];
            if ($targetId === $actingId) {
                sendJson(['error' => 'Cannot deactivate your own account.'], 403);
                break;
            }
            if (roleLevel((string)$target['role']) >= roleLevel($actingRole)
                && $actingRole !== 'global_admin') {
                sendJson(['error' => 'Cannot modify a user at or above your role level.'], 403);
                break;
            }

            try {
                $newState = !((bool)$target['is_active']);
                setUserActive($targetId, $newState);
                sendJson([
                    'ok'        => true,
                    'id'        => $targetId,
                    'is_active' => $newState,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.toggle_active', 'user', (string)$targetId, $e);
                error_log('[admin_user_toggle_active] ' . $e->getMessage());
                sendJson(['error' => 'Could not update active state.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: reset a user's password
         * POST body: { user_id, new_password }
         * Self-reset is permitted (mirrors users.php). Otherwise the
         * target must be below the acting user's role level (or the
         * acting user is global_admin).
         * ----------------------------------------------------------------- */
        case 'admin_user_password_reset': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId    = (int)($body['user_id']      ?? 0);
            $newPassword = (string)($body['new_password'] ?? '');

            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }
            if (strlen($newPassword) < 8) {
                sendJson(['error' => 'Password must be at least 8 characters.'], 400);
                break;
            }

            $target = getUserById($targetId);
            if (!$target) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }
            $actingId   = (int)$authUser['Id'];
            $actingRole = (string)$authUser['Role'];
            if (roleLevel((string)$target['role']) >= roleLevel($actingRole)
                && $actingRole !== 'global_admin'
                && $targetId !== $actingId) {
                sendJson(['error' => 'Cannot reset password for a user at or above your role level.'], 403);
                break;
            }

            try {
                changeUserPassword($targetId, $newPassword);
                sendJson(['ok' => true, 'id' => $targetId, 'username' => (string)$target['username']]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.password_reset', 'user', (string)$targetId, $e);
                error_log('[admin_user_password_reset] ' . $e->getMessage());
                sendJson(['error' => 'Could not reset password.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete a user
         * POST body: { user_id }
         * Cannot delete self. Cannot delete a peer or superior unless
         * global_admin. Hard delete via the helper — the helper handles
         * audit + cascading FK semantics.
         * ----------------------------------------------------------------- */
        case 'admin_user_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                       . 'includes'  . DIRECTORY_SEPARATOR . 'auth.php';

            $body     = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $targetId = (int)($body['user_id'] ?? 0);
            if ($targetId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }

            $target = getUserById($targetId);
            if (!$target) {
                sendJson(['error' => 'User not found.'], 404);
                break;
            }
            $actingId   = (int)$authUser['Id'];
            $actingRole = (string)$authUser['Role'];
            if ($targetId === $actingId) {
                sendJson(['error' => 'Cannot delete your own account.'], 403);
                break;
            }
            if (roleLevel((string)$target['role']) >= roleLevel($actingRole)
                && $actingRole !== 'global_admin') {
                sendJson(['error' => 'Cannot delete a user at or above your role level.'], 403);
                break;
            }

            try {
                deleteUser($targetId);
                sendJson(['ok' => true, 'id' => $targetId, 'username' => (string)$target['username']]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.user.delete', 'user', (string)$targetId, $e);
                error_log('[admin_user_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete user.'], 500);
            }
            break;
        }

        /* =================================================================
         * USER GROUPS — admin CRUD parity (#719 PR 2b)
         *
         * Mirrors /manage/groups.php. Group rows live in tblUserGroups;
         * membership lives in tblUsers.GroupId (one group per user).
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: create a user group
         * POST body: {
         *   name, description?,
         *   access_alpha?, access_beta?, access_rc?, access_rtw?
         * }
         * Returns 201 + new group id.
         * ----------------------------------------------------------------- */
        case 'admin_group_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $name = trim((string)($body['name']        ?? ''));
            $desc = trim((string)($body['description'] ?? ''));
            $aA   = !empty($body['access_alpha']) ? 1 : 0;
            $aB   = !empty($body['access_beta'])  ? 1 : 0;
            $aR   = !empty($body['access_rc'])    ? 1 : 0;
            $aW   = !empty($body['access_rtw'])   ? 1 : 0;

            if ($name === '') {
                sendJson(['error' => 'Name is required.'], 400);
                break;
            }
            if (strlen($name) > 100) {
                sendJson(['error' => 'Name must be 100 characters or fewer.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) {
                    sendJson(['error' => 'A group with that name already exists.'], 409);
                    break;
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblUserGroups (Name, Description, AccessAlpha, AccessBeta, AccessRc, AccessRtw)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssiiii', $name, $desc, $aA, $aB, $aR, $aW);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                logActivity('api.admin.group.create', 'group', (string)$newId, [
                    'name'          => $name,
                    'description'   => $desc,
                    'access_alpha'  => (bool)$aA,
                    'access_beta'   => (bool)$aB,
                    'access_rc'     => (bool)$aR,
                    'access_rtw'    => (bool)$aW,
                ]);

                sendJson(['ok' => true, 'id' => $newId, 'name' => $name], 201);
            } catch (\Throwable $e) {
                logActivityError('api.admin.group.create', 'group', '', $e, ['name' => $name]);
                error_log('[admin_group_create] ' . $e->getMessage());
                sendJson(['error' => 'Could not create group.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: update a user group
         * POST body: {
         *   id, name, description?,
         *   access_alpha?, access_beta?, access_rc?, access_rtw?
         * }
         * ----------------------------------------------------------------- */
        case 'admin_group_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id']   ?? 0);
            $name = trim((string)($body['name']        ?? ''));
            $desc = trim((string)($body['description'] ?? ''));
            $aA   = !empty($body['access_alpha']) ? 1 : 0;
            $aB   = !empty($body['access_beta'])  ? 1 : 0;
            $aR   = !empty($body['access_rc'])    ? 1 : 0;
            $aW   = !empty($body['access_rtw'])   ? 1 : 0;

            if ($id <= 0) {
                sendJson(['error' => 'Group id required.'], 400);
                break;
            }
            if ($name === '') {
                sendJson(['error' => 'Name is required.'], 400);
                break;
            }
            if (strlen($name) > 100) {
                sendJson(['error' => 'Name must be 100 characters or fewer.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                /* Confirm the row exists so we can return 404 cleanly
                   instead of a successful no-op. */
                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $found = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$found) {
                    sendJson(['error' => 'Group not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Name = ? AND Id <> ?');
                $stmt->bind_param('si', $name, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($dup) {
                    sendJson(['error' => 'Another group already uses that name.'], 409);
                    break;
                }

                $stmt = $db->prepare(
                    'UPDATE tblUserGroups
                        SET Name = ?, Description = ?,
                            AccessAlpha = ?, AccessBeta = ?, AccessRc = ?, AccessRtw = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('ssiiiii', $name, $desc, $aA, $aB, $aR, $aW, $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.group.update', 'group', (string)$id, [
                    'name'          => $name,
                    'description'   => $desc,
                    'access_alpha'  => (bool)$aA,
                    'access_beta'   => (bool)$aB,
                    'access_rc'     => (bool)$aR,
                    'access_rtw'    => (bool)$aW,
                ]);

                sendJson(['ok' => true, 'id' => $id, 'name' => $name]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.group.update', 'group', (string)$id, $e);
                error_log('[admin_group_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update group.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete a user group
         * POST body: { id }
         * Refuses with 422 if any user is still a member.
         * ----------------------------------------------------------------- */
        case 'admin_group_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Group id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    sendJson(['error' => 'Group not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE GroupId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $members = (int)($row[0] ?? 0);
                if ($members > 0) {
                    sendJson([
                        'error'        => "Cannot delete '{$name}': {$members} user(s) still belong to it.",
                        'member_count' => $members,
                        'name'         => $name,
                        'hint'         => 'Move the members to another group (or remove them) first.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.group.delete', 'group', (string)$id, ['name' => $name]);
                sendJson(['ok' => true, 'id' => $id, 'name' => $name]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.group.delete', 'group', (string)$id, $e);
                error_log('[admin_group_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete group.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: add a user to a group (sets tblUsers.GroupId)
         * POST body: { group_id, user_id }
         * No-op-friendly: re-adding a user already in the same group
         * is a 200 (UPDATE just touches UpdatedAt).
         * ----------------------------------------------------------------- */
        case 'admin_group_member_add': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body    = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $groupId = (int)($body['group_id'] ?? 0);
            $userId  = (int)($body['user_id']  ?? 0);
            if ($groupId <= 0 || $userId <= 0) {
                sendJson(['error' => 'group_id and user_id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();

                /* Probe both rows so we can return the right 404. The
                   web admin doesn't bother (typo → silent UPDATE 0
                   rows), but a JSON API caller wants a definite
                   answer. */
                $stmt = $db->prepare('SELECT Id FROM tblUserGroups WHERE Id = ?');
                $stmt->bind_param('i', $groupId);
                $stmt->execute();
                $hasGroup = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$hasGroup) {
                    sendJson(['error' => 'Group not found.'], 404);
                    break;
                }
                $stmt = $db->prepare('SELECT Id FROM tblUsers WHERE Id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $hasUser = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$hasUser) {
                    sendJson(['error' => 'User not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->bind_param('ii', $groupId, $userId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.group.member_add', 'group', (string)$groupId, [
                    'user_id' => $userId,
                ]);

                sendJson(['ok' => true, 'group_id' => $groupId, 'user_id' => $userId]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.group.member_add', 'group', (string)$groupId, $e, ['user_id' => $userId]);
                error_log('[admin_group_member_add] ' . $e->getMessage());
                sendJson(['error' => 'Could not add member to group.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: remove a user from their group (sets GroupId = NULL)
         * POST body: { user_id }
         * group_id is intentionally NOT required — a user belongs to at
         * most one group via tblUsers.GroupId, so dropping the FK is
         * sufficient regardless of which group they were in.
         * ----------------------------------------------------------------- */
        case 'admin_group_member_remove': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $userId = (int)($body['user_id'] ?? 0);
            if ($userId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT GroupId FROM tblUsers WHERE Id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($row === null) {
                    sendJson(['error' => 'User not found.'], 404);
                    break;
                }
                $oldGroupId = $row[0] !== null ? (int)$row[0] : null;

                $stmt = $db->prepare('UPDATE tblUsers SET GroupId = NULL, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.group.member_remove', 'group',
                    $oldGroupId !== null ? (string)$oldGroupId : '', [
                        'user_id'  => $userId,
                    ]
                );

                sendJson([
                    'ok'              => true,
                    'user_id'         => $userId,
                    'previous_group'  => $oldGroupId,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.group.member_remove', 'group', '', $e, ['user_id' => $userId]);
                error_log('[admin_group_member_remove] ' . $e->getMessage());
                sendJson(['error' => 'Could not remove member from group.'], 500);
            }
            break;
        }

        /* =================================================================
         * ACCESS TIERS — admin CRUD parity (#719 PR 2b)
         *
         * Mirrors /manage/tiers.php. Capability-column list lives in
         * includes/access_tier_validation.php (TIER_CAPS const) so a
         * new capability is a one-line schema + const change — no
         * surface-specific bind_param edits needed.
         *
         * Input shape: `caps` is a JSON object keyed by exact tblAccessTiers
         * column name, value bool/int. Unknown keys are ignored; missing
         * keys default to 0.
         *
         * Reserved tier names (public / free / ccli / premium / pro) cannot
         * be deleted via the API — same hard refusal as /manage/tiers.php's
         * implicit gate (the row lookup blocks delete on rows still
         * referenced by users, but the API also rejects up-front).
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: create an access tier
         * POST body: {
         *   name, display_name, level, description?, caps?: { ... }
         * }
         * ----------------------------------------------------------------- */
        case 'admin_tier_create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $name        = trim((string)($body['name']         ?? ''));
            $displayName = trim((string)($body['display_name'] ?? ''));
            $level       = (int)($body['level']                ?? 0);
            $description = trim((string)($body['description']  ?? ''));
            $capsInput   = is_array($body['caps'] ?? null) ? $body['caps'] : [];

            if ($e = validateTierName($name))   { sendJson(['error' => $e], 400); break; }
            if ($displayName === '')            { sendJson(['error' => 'display_name is required.'], 400); break; }
            if ($e = validateTierLevel($level)) { sendJson(['error' => $e], 400); break; }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Id FROM tblAccessTiers WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) {
                    sendJson(['error' => 'A tier with that name already exists.'], 409);
                    break;
                }

                $caps = [];
                foreach (array_keys(TIER_CAPS) as $col) {
                    /* Accept the camelCase or PascalCase form clients
                       might use; the canonical key is the exact column
                       name. Default to 0 when unspecified. */
                    $caps[$col] = !empty($capsInput[$col]) ? 1 : 0;
                }

                $cols         = array_merge(['Name','DisplayName','Level','Description'], array_keys(TIER_CAPS));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql          = 'INSERT INTO tblAccessTiers (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
                /* Type string: Name(s) DisplayName(s) Level(i) Description(s) +
                   each TIER_CAPS column as int. Built dynamically so a new
                   capability column auto-extends the bind without an edit. */
                $types  = 'ssis' . str_repeat('i', count(TIER_CAPS));
                $values = array_merge([$name, $displayName, $level, $description], array_values($caps));
                $stmt   = $db->prepare($sql);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                logActivity('api.admin.tier.create', 'access_tier', (string)$newId, [
                    'name'         => $name,
                    'display_name' => $displayName,
                    'level'        => $level,
                    'description'  => $description,
                    'caps'         => $caps,
                ]);

                sendJson(['ok' => true, 'id' => $newId, 'name' => $name], 201);
            } catch (\Throwable $e) {
                logActivityError('api.admin.tier.create', 'access_tier', '', $e, ['name' => $name]);
                error_log('[admin_tier_create] ' . $e->getMessage());
                sendJson(['error' => 'Could not create tier.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: update an access tier
         * POST body: {
         *   id, display_name, level, description?, caps?: { ... }
         * }
         * `name` (the machine key) is intentionally immutable — too
         * many tblUsers.AccessTier rows reference it for a casual
         * rename. Use a manual SQL migration if a rename is genuinely
         * needed.
         * ----------------------------------------------------------------- */
        case 'admin_tier_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'access_tier_validation.php';

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id          = (int)($body['id']                   ?? 0);
            $displayName = trim((string)($body['display_name'] ?? ''));
            $level       = (int)($body['level']                ?? 0);
            $description = trim((string)($body['description']  ?? ''));
            $capsInput   = is_array($body['caps'] ?? null) ? $body['caps'] : [];

            if ($id <= 0)                       { sendJson(['error' => 'Tier id required.'], 400); break; }
            if ($displayName === '')            { sendJson(['error' => 'display_name is required.'], 400); break; }
            if ($e = validateTierLevel($level)) { sendJson(['error' => $e], 400); break; }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblAccessTiers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    sendJson(['error' => 'Tier not found.'], 404);
                    break;
                }

                $caps = [];
                foreach (array_keys(TIER_CAPS) as $col) {
                    $caps[$col] = !empty($capsInput[$col]) ? 1 : 0;
                }

                $sets = ['DisplayName = ?', 'Level = ?', 'Description = ?'];
                $args = [$displayName, $level, $description];
                foreach ($caps as $col => $val) {
                    $sets[] = "$col = ?";
                    $args[] = $val;
                }
                $args[] = $id;
                /* Type string: DisplayName(s), Level(i), Description(s),
                   each TIER_CAPS column as int, then Id(i). */
                $types = 'sis' . str_repeat('i', count(TIER_CAPS)) . 'i';
                $stmt  = $db->prepare(
                    'UPDATE tblAccessTiers SET ' . implode(', ', $sets) . ' WHERE Id = ?'
                );
                $stmt->bind_param($types, ...$args);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.tier.update', 'access_tier', (string)$id, [
                    'name'         => $name,
                    'display_name' => $displayName,
                    'level'        => $level,
                    'description'  => $description,
                    'caps'         => $caps,
                ]);

                sendJson(['ok' => true, 'id' => $id, 'name' => $name]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.tier.update', 'access_tier', (string)$id, $e);
                error_log('[admin_tier_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update tier.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete an access tier
         * POST body: { id }
         * Refuses with 422 if any user is still on this tier.
         * ----------------------------------------------------------------- */
        case 'admin_tier_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Tier id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblAccessTiers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    sendJson(['error' => 'Tier not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblUsers WHERE AccessTier = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $inUse = (int)($row[0] ?? 0);
                if ($inUse > 0) {
                    sendJson([
                        'error'      => "Cannot delete '{$name}': {$inUse} user(s) are currently on this tier.",
                        'user_count' => $inUse,
                        'name'       => $name,
                        'hint'       => 'Reassign affected users to another tier first.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblAccessTiers WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.tier.delete', 'access_tier', (string)$id, ['name' => $name]);
                sendJson(['ok' => true, 'id' => $id, 'name' => $name]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.tier.delete', 'access_tier', (string)$id, $e);
                error_log('[admin_tier_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete tier.'], 500);
            }
            break;
        }

        /* =================================================================
         * ORGANISATIONS — system-admin CRUD parity (#719 PR 2c)
         *
         * Mirrors /manage/organisations.php POST handlers. The existing
         * `organisation_create` endpoint (any authenticated user, creator
         * becomes owner) covers the create case from a different angle —
         * the system-admin equivalent there is just "create then assign
         * the owner externally" so it's not duplicated here.
         *
         * All endpoints below require admin / global_admin role.
         * Activity-log verb prefix is `api.admin.organisation.*`.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: update an organisation (system-admin)
         * POST body: {
         *   id, name, slug?, parent_org_id?, description?,
         *   licence_type, licence_number?, is_active?,
         *   additional_licences?: [keys]
         * }
         * Mirrors the multi-licence sync from /manage/organisations.php:
         * the submitted set replaces tblOrganisationLicences for the
         * org, with the primary licence_type folded in to keep the two
         * surfaces coherent.
         * ----------------------------------------------------------------- */
        case 'admin_organisation_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body        = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id          = (int)($body['id'] ?? 0);
            $name        = trim((string)($body['name']           ?? ''));
            $slugInput   = trim((string)($body['slug']           ?? ''));
            $parent      = (int)($body['parent_org_id']          ?? 0);
            $desc        = trim((string)($body['description']    ?? ''));
            $licenceType = (string)($body['licence_type']        ?? 'none');
            $licenceNum  = trim((string)($body['licence_number'] ?? ''));
            $active      = !empty($body['is_active']) ? 1 : 0;

            /* Same primary-licence allowlist organisations.php uses
               (4 keys with `none` as the "no primary" sentinel). The
               additional_licences set is validated against this same
               keylist before INSERT. */
            $licenceKeys = ['none', 'ihymns_basic', 'ihymns_pro', 'ccli'];

            if ($id <= 0)                                  { sendJson(['error' => 'Organisation id required.'], 400); break; }
            if ($name === '')                              { sendJson(['error' => 'Name is required.'], 400); break; }
            $slug = $slugInput !== '' ? slugifyOrganisationName($slugInput) : slugifyOrganisationName($name);
            if ($slug === '')                              { sendJson(['error' => 'Slug could not be derived — supply one explicitly.'], 400); break; }
            if (!in_array($licenceType, $licenceKeys, true)) { sendJson(['error' => 'Unknown licence type.'], 400); break; }
            if ($parent === $id)                           { sendJson(['error' => 'An organisation cannot be its own parent.'], 400); break; }

            try {
                $db = getDbMysqli();

                $stmt = $db->prepare('SELECT Id FROM tblOrganisations WHERE Slug = ? AND Id <> ?');
                $stmt->bind_param('si', $slug, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($dup) {
                    sendJson(['error' => 'That slug is already in use.'], 409);
                    break;
                }

                $beforeStmt = $db->prepare(
                    'SELECT Name, Slug, ParentOrgId, Description, LicenceType, LicenceNumber, IsActive
                       FROM tblOrganisations WHERE Id = ?'
                );
                $beforeStmt->bind_param('i', $id);
                $beforeStmt->execute();
                $beforeOrg = $beforeStmt->get_result()->fetch_assoc() ?: null;
                $beforeStmt->close();
                if ($beforeOrg === null) {
                    sendJson(['error' => 'Organisation not found.'], 404);
                    break;
                }

                $parentOrNull = $parent ?: null;
                $stmt = $db->prepare(
                    'UPDATE tblOrganisations
                        SET Name = ?, Slug = ?, ParentOrgId = ?, Description = ?,
                            LicenceType = ?, LicenceNumber = ?, IsActive = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param(
                    'ssisssii',
                    $name, $slug, $parentOrNull, $desc, $licenceType, $licenceNum, $active, $id
                );
                $stmt->execute();
                $stmt->close();

                /* Multi-licence sync (#640) — the submitted additional
                   set REPLACES tblOrganisationLicences for the org; the
                   primary licence_type is also folded in so the two
                   surfaces stay coherent. Wrapped in a try/catch so a
                   pre-migration deployment (no tblOrganisationLicences)
                   silently no-ops the join writes. */
                try {
                    $picked = (array)($body['additional_licences'] ?? []);
                    $picked = array_values(array_unique(array_filter(
                        array_map('strval', $picked),
                        static fn($k) => $k !== '' && $k !== 'none'
                    )));
                    if ($licenceType !== '' && $licenceType !== 'none' && !in_array($licenceType, $picked, true)) {
                        $picked[] = $licenceType;
                    }
                    $picked = array_values(array_intersect($picked, $licenceKeys));

                    $del = $db->prepare('DELETE FROM tblOrganisationLicences WHERE OrganisationId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();

                    if (!empty($picked)) {
                        $ins = $db->prepare(
                            'INSERT INTO tblOrganisationLicences
                                (OrganisationId, LicenceType, LicenceNumber)
                             VALUES (?, ?, ?)'
                        );
                        foreach ($picked as $key) {
                            $num = ($key === $licenceType && $licenceNum !== '') ? $licenceNum : null;
                            $ins->bind_param('iss', $id, $key, $num);
                            $ins->execute();
                        }
                        $ins->close();
                    }
                } catch (\Throwable $_e) {
                    /* tblOrganisationLicences not yet created — silent no-op. */
                }

                $afterOrg = [
                    'Name' => $name, 'Slug' => $slug,
                    'ParentOrgId' => $parent ?: null, 'Description' => $desc,
                    'LicenceType' => $licenceType, 'LicenceNumber' => $licenceNum,
                    'IsActive' => $active,
                ];
                $changed = [];
                foreach ($afterOrg as $k => $v) {
                    if ((string)($beforeOrg[$k] ?? '') !== (string)$v) $changed[] = $k;
                }
                logActivity('api.admin.organisation.edit', 'organisation', (string)$id, [
                    'fields' => $changed,
                    'before' => array_intersect_key($beforeOrg, array_flip($changed)),
                    'after'  => array_intersect_key($afterOrg,  array_flip($changed)),
                ]);

                sendJson([
                    'ok'             => true,
                    'id'             => $id,
                    'slug'           => $slug,
                    'fields_changed' => $changed,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.organisation.edit', 'organisation', (string)$id, $e);
                error_log('[admin_organisation_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update organisation.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete an organisation
         * POST body: { id }
         * Refuses with 422 if any member is still listed OR any sub-org
         * still references this row as ParentOrgId.
         * ----------------------------------------------------------------- */
        case 'admin_organisation_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id   = (int)($body['id'] ?? 0);
            if ($id <= 0) {
                sendJson(['error' => 'Organisation id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblOrganisations WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    sendJson(['error' => 'Organisation not found.'], 404);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisationMembers WHERE OrgId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $members = (int)($row[0] ?? 0);
                if ($members > 0) {
                    sendJson([
                        'error'        => "Cannot delete '{$name}': {$members} member(s) still listed.",
                        'member_count' => $members,
                        'name'         => $name,
                        'hint'         => 'Remove every member first.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblOrganisations WHERE ParentOrgId = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $children = (int)($row[0] ?? 0);
                if ($children > 0) {
                    sendJson([
                        'error'         => "Cannot delete '{$name}': {$children} sub-organisation(s) still reference it as parent.",
                        'child_count'   => $children,
                        'name'          => $name,
                        'hint'          => 'Reparent or delete the sub-organisations first.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblOrganisations WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.organisation.delete', 'organisation', (string)$id, ['name' => $name]);
                sendJson(['ok' => true, 'id' => $id, 'name' => $name]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.organisation.delete', 'organisation', (string)$id, $e);
                error_log('[admin_organisation_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete organisation.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: add a member to an organisation
         * POST body: { org_id, user_id, member_role? }
         * INSERT … ON DUPLICATE KEY UPDATE so a re-add is a role change.
         * ----------------------------------------------------------------- */
        case 'admin_organisation_member_add': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId  = (int)($body['org_id']  ?? 0);
            $userId = (int)($body['user_id'] ?? 0);
            $role   = (string)($body['member_role'] ?? 'member');

            if ($orgId <= 0 || $userId <= 0) {
                sendJson(['error' => 'org_id and user_id required.'], 400);
                break;
            }
            if (!in_array($role, ORG_MEMBER_ROLES, true)) {
                sendJson(['error' => 'Unknown member role.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
                );
                $stmt->bind_param('iis', $userId, $orgId, $role);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.organisation.member_add', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                    'role'    => $role,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'user_id' => $userId, 'role' => $role]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.organisation.member_add', 'organisation', (string)$orgId, $e, [
                    'user_id' => $userId,
                ]);
                error_log('[admin_organisation_member_add] ' . $e->getMessage());
                sendJson(['error' => 'Could not add member.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: change a member's role within an organisation
         * POST body: { org_id, user_id, member_role }
         * ----------------------------------------------------------------- */
        case 'admin_organisation_member_role_change': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId  = (int)($body['org_id']  ?? 0);
            $userId = (int)($body['user_id'] ?? 0);
            $role   = (string)($body['member_role'] ?? 'member');

            if ($orgId <= 0 || $userId <= 0) {
                sendJson(['error' => 'org_id and user_id required.'], 400);
                break;
            }
            if (!in_array($role, ORG_MEMBER_ROLES, true)) {
                sendJson(['error' => 'Unknown member role.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?'
                );
                $stmt->bind_param('sii', $role, $orgId, $userId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed === 0) {
                    sendJson(['error' => 'Member not found in this organisation.'], 404);
                    break;
                }

                logActivity('api.admin.organisation.member_role_change', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                    'role'    => $role,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'user_id' => $userId, 'role' => $role]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.organisation.member_role_change', 'organisation', (string)$orgId, $e, [
                    'user_id' => $userId,
                ]);
                error_log('[admin_organisation_member_role_change] ' . $e->getMessage());
                sendJson(['error' => 'Could not change member role.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: remove a member from an organisation
         * POST body: { org_id, user_id }
         * ----------------------------------------------------------------- */
        case 'admin_organisation_member_remove': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId  = (int)($body['org_id']  ?? 0);
            $userId = (int)($body['user_id'] ?? 0);

            if ($orgId <= 0 || $userId <= 0) {
                sendJson(['error' => 'org_id and user_id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('ii', $orgId, $userId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.organisation.member_remove', 'organisation', (string)$orgId, [
                    'user_id' => $userId,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'user_id' => $userId]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.organisation.member_remove', 'organisation', (string)$orgId, $e, [
                    'user_id' => $userId,
                ]);
                error_log('[admin_organisation_member_remove] ' . $e->getMessage());
                sendJson(['error' => 'Could not remove member.'], 500);
            }
            break;
        }

        /* =================================================================
         * MY ORGANISATIONS — org-admin CRUD parity (#719 PR 2c)
         *
         * Mirrors /manage/my-organisations.php POST handlers (PR #726).
         * Auth model:
         *   1. Bearer-token authenticated.
         *   2. Row-level gate: caller must be system admin OR hold an
         *      admin/owner row on tblOrganisationMembers for the target
         *      org. The userCanActOnOrg() helper enforces this — fail
         *      returns 403 with the exact same wording as the web admin.
         *
         * Activity-log verb prefix: `api.org_admin.*`. Mirrors the
         * `org_admin.*` prefix the web admin writes so the timeline
         * reads as a unified surface.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Org-admin: add a member by username or email
         * POST body: { org_id, user_identifier, member_role? }
         * Free-text identifier (username or email) — the API resolves it
         * to a tblUsers.Id so curators can paste either form.
         * ----------------------------------------------------------------- */
        case 'org_admin_member_add': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body       = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId      = (int)($body['org_id'] ?? 0);
            $identifier = trim((string)($body['user_identifier'] ?? ''));
            $role       = (string)($body['member_role'] ?? 'member');

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if ($identifier === '') {
                sendJson(['error' => 'user_identifier (username or email) required.'], 400);
                break;
            }
            if (!in_array($role, ORG_MEMBER_ROLES, true)) {
                sendJson(['error' => 'Unknown member role.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT Id FROM tblUsers WHERE Username = ? OR Email = ? LIMIT 1'
                );
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    sendJson(['error' => "User '{$identifier}' not found."], 404);
                    break;
                }
                $targetUserId = (int)$row['Id'];

                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationMembers (UserId, OrgId, Role)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE Role = VALUES(Role)'
                );
                $stmt->bind_param('iis', $targetUserId, $orgId, $role);
                $stmt->execute();
                $stmt->close();

                logActivity('api.org_admin.member_add', 'organisation', (string)$orgId, [
                    'user_id'    => $targetUserId,
                    'identifier' => $identifier,
                    'role'       => $role,
                ]);

                sendJson([
                    'ok'      => true,
                    'org_id'  => $orgId,
                    'user_id' => $targetUserId,
                    'role'    => $role,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.member_add', 'organisation', (string)$orgId, $e, [
                    'identifier' => $identifier,
                ]);
                error_log('[org_admin_member_add] ' . $e->getMessage());
                sendJson(['error' => 'Could not add member.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Org-admin: change a member's role
         * POST body: { org_id, user_id, member_role }
         * ----------------------------------------------------------------- */
        case 'org_admin_member_role_change': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body         = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId        = (int)($body['org_id']  ?? 0);
            $targetUserId = (int)($body['user_id'] ?? 0);
            $role         = (string)($body['member_role'] ?? 'member');

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if ($targetUserId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }
            if (!in_array($role, ORG_MEMBER_ROLES, true)) {
                sendJson(['error' => 'Unknown member role.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'UPDATE tblOrganisationMembers SET Role = ? WHERE OrgId = ? AND UserId = ?'
                );
                $stmt->bind_param('sii', $role, $orgId, $targetUserId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed === 0) {
                    sendJson(['error' => 'Member not found in this organisation.'], 404);
                    break;
                }

                logActivity('api.org_admin.member_role_change', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                    'role'    => $role,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'user_id' => $targetUserId, 'role' => $role]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.member_role_change', 'organisation', (string)$orgId, $e, [
                    'user_id' => $targetUserId,
                ]);
                error_log('[org_admin_member_role_change] ' . $e->getMessage());
                sendJson(['error' => 'Could not change member role.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Org-admin: remove a member
         * POST body: { org_id, user_id }
         * Self-removal guard mirrors the web admin: an org admin cannot
         * remove themselves (have to ask a sibling admin / owner /
         * system admin) — prevents accidental lockout.
         * ----------------------------------------------------------------- */
        case 'org_admin_member_remove': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body         = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId        = (int)($body['org_id']  ?? 0);
            $targetUserId = (int)($body['user_id'] ?? 0);

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if ($targetUserId <= 0) {
                sendJson(['error' => 'user_id required.'], 400);
                break;
            }

            $actingId    = (int)($authUser['Id']   ?? 0);
            $isSysAdmin  = in_array((string)($authUser['Role'] ?? ''), ['admin', 'global_admin'], true);
            if ($targetUserId === $actingId && !$isSysAdmin) {
                sendJson([
                    'error' => 'You cannot remove yourself from an organisation. Ask a co-admin or system admin to remove you.',
                ], 403);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('DELETE FROM tblOrganisationMembers WHERE OrgId = ? AND UserId = ?');
                $stmt->bind_param('ii', $orgId, $targetUserId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.org_admin.member_remove', 'organisation', (string)$orgId, [
                    'user_id' => $targetUserId,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'user_id' => $targetUserId]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.member_remove', 'organisation', (string)$orgId, $e, [
                    'user_id' => $targetUserId,
                ]);
                error_log('[org_admin_member_remove] ' . $e->getMessage());
                sendJson(['error' => 'Could not remove member.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Org-admin: add (or upsert) a licence row
         * POST body: {
         *   org_id, licence_type, licence_number?,
         *   expires_at?, is_active?, notes?
         * }
         * INSERT … ON DUPLICATE KEY UPDATE — the unique key is
         * (OrganisationId, LicenceType) so re-adding the same type for
         * the same org is an update of number/expiry/notes.
         * ----------------------------------------------------------------- */
        case 'org_admin_licence_add': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body          = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId         = (int)($body['org_id'] ?? 0);
            $licenceType   = (string)($body['licence_type']   ?? '');
            $licenceNumber = trim((string)($body['licence_number'] ?? ''));
            $expiresAt     = trim((string)($body['expires_at']    ?? '')) ?: null;
            $isActive      = !empty($body['is_active']) ? 1 : 0;
            $notes         = trim((string)($body['notes']         ?? '')) ?: null;

            /* Same per-row licence-type allowlist /manage/my-organisations
               uses (5 keys). Distinct from the system-admin update path
               which uses the 4-key primary set with `none` sentinel. */
            $licenceKeys = ['ccli', 'mrl', 'ihymns_basic', 'ihymns_pro', 'custom'];

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if (!in_array($licenceType, $licenceKeys, true)) {
                sendJson(['error' => 'Unknown licence type.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'INSERT INTO tblOrganisationLicences
                        (OrganisationId, LicenceType, LicenceNumber, IsActive, ExpiresAt, Notes)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        LicenceNumber = VALUES(LicenceNumber),
                        IsActive      = VALUES(IsActive),
                        ExpiresAt     = VALUES(ExpiresAt),
                        Notes         = VALUES(Notes)'
                );
                $stmt->bind_param('ississ',
                    $orgId, $licenceType, $licenceNumber, $isActive, $expiresAt, $notes);
                $stmt->execute();
                $stmt->close();

                logActivity('api.org_admin.licence_add', 'organisation', (string)$orgId, [
                    'licence_type'   => $licenceType,
                    'licence_number' => $licenceNumber,
                    'is_active'      => (bool)$isActive,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'licence_type' => $licenceType]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.licence_add', 'organisation', (string)$orgId, $e, [
                    'licence_type' => $licenceType,
                ]);
                error_log('[org_admin_licence_add] ' . $e->getMessage());
                sendJson(['error' => 'Could not save licence.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Org-admin: change an existing licence row
         * POST body: {
         *   org_id, licence_id,
         *   licence_number?, expires_at?, is_active?, notes?
         * }
         * Belt-and-braces: confirms the licence row actually belongs to
         * the org we already authorised on. Stops a crafted POST that
         * mixes a licence_id from one org with an org_id the user CAN
         * admin.
         * ----------------------------------------------------------------- */
        case 'org_admin_licence_change': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body          = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId         = (int)($body['org_id']     ?? 0);
            $licenceId     = (int)($body['licence_id'] ?? 0);
            $licenceNumber = trim((string)($body['licence_number'] ?? ''));
            $expiresAt     = trim((string)($body['expires_at']    ?? '')) ?: null;
            $isActive      = !empty($body['is_active']) ? 1 : 0;
            $notes         = trim((string)($body['notes']         ?? '')) ?: null;

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if ($licenceId <= 0) {
                sendJson(['error' => 'licence_id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
                );
                $stmt->bind_param('ii', $licenceId, $orgId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$owns) {
                    sendJson(['error' => 'Licence row does not belong to that organisation.'], 404);
                    break;
                }

                $stmt = $db->prepare(
                    'UPDATE tblOrganisationLicences
                        SET LicenceNumber = ?, IsActive = ?, ExpiresAt = ?, Notes = ?
                      WHERE Id = ?'
                );
                $stmt->bind_param('sissi', $licenceNumber, $isActive, $expiresAt, $notes, $licenceId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.org_admin.licence_change', 'organisation', (string)$orgId, [
                    'licence_id'     => $licenceId,
                    'licence_number' => $licenceNumber,
                    'is_active'      => (bool)$isActive,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'licence_id' => $licenceId]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.licence_change', 'organisation', (string)$orgId, $e, [
                    'licence_id' => $licenceId,
                ]);
                error_log('[org_admin_licence_change] ' . $e->getMessage());
                sendJson(['error' => 'Could not change licence.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Org-admin: remove a licence row
         * POST body: { org_id, licence_id }
         * Same belt-and-braces ownership check as licence_change.
         * ----------------------------------------------------------------- */
        case 'org_admin_licence_remove': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser) {
                sendJson(['error' => 'Not authenticated.'], 401);
                break;
            }

            $body      = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $orgId     = (int)($body['org_id']     ?? 0);
            $licenceId = (int)($body['licence_id'] ?? 0);

            if (!userCanActOnOrg($authUser, $orgId)) {
                sendJson(['error' => 'Not authorised on this organisation.'], 403);
                break;
            }
            if ($licenceId <= 0) {
                sendJson(['error' => 'licence_id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT 1 FROM tblOrganisationLicences WHERE Id = ? AND OrganisationId = ?'
                );
                $stmt->bind_param('ii', $licenceId, $orgId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if (!$owns) {
                    sendJson(['error' => 'Licence row does not belong to that organisation.'], 404);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblOrganisationLicences WHERE Id = ?');
                $stmt->bind_param('i', $licenceId);
                $stmt->execute();
                $stmt->close();

                logActivity('api.org_admin.licence_remove', 'organisation', (string)$orgId, [
                    'licence_id' => $licenceId,
                ]);

                sendJson(['ok' => true, 'org_id' => $orgId, 'licence_id' => $licenceId]);
            } catch (\Throwable $e) {
                logActivityError('api.org_admin.licence_remove', 'organisation', (string)$orgId, $e, [
                    'licence_id' => $licenceId,
                ]);
                error_log('[org_admin_licence_remove] ' . $e->getMessage());
                sendJson(['error' => 'Could not remove licence.'], 500);
            }
            break;
        }

        /* =================================================================
         * CREDIT PEOPLE — admin CRUD parity (#719 PR 2d)
         *
         * Mirrors /manage/credit-people.php POST handlers (#545). Every
         * mutating endpoint runs inside $db->begin_transaction() so a
         * partial failure (e.g. a child-row INSERT failing after the
         * parent UPDATE landed) rolls back cleanly.
         *
         * Activity-log verb prefix is `api.admin.credit_person.*` —
         * distinguishes API-driven changes from web-UI changes (which
         * write `credit_person.*`) so /manage/activity-log shows both
         * sides clearly.
         *
         * Validation rules (link-type allowlist, IPI shape) live in
         * includes/credit_people_helpers.php — the same file
         * /manage/credit-people uses.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: add a new credit-person registry row
         * POST body: {
         *   name (required), notes?, birth_place?, birth_date?,
         *   death_place?, death_date?, is_special_case?, is_group?,
         *   links?: [{type,url,label,sort_order}], ipi?: [{number,name_used,notes}]
         * }
         * Birth/death dates must be YYYY-MM-DD if supplied.
         * ----------------------------------------------------------------- */
        case 'admin_credit_person_add': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $name        = trim((string)($body['name']         ?? ''));
            $notesRaw    = trim((string)($body['notes']        ?? ''));
            $birthPlace  = trim((string)($body['birth_place']  ?? '')) ?: null;
            $birthDate   = trim((string)($body['birth_date']   ?? '')) ?: null;
            $deathPlace  = trim((string)($body['death_place']  ?? '')) ?: null;
            $deathDate   = trim((string)($body['death_date']   ?? '')) ?: null;
            $notes       = $notesRaw !== '' ? $notesRaw : null;
            $links       = normaliseCreditPersonLinks($body['links'] ?? null);
            $ipi         = normaliseCreditPersonIpi($body['ipi']     ?? null);
            $isSpecialCase = !empty($body['is_special_case']) ? 1 : 0;
            $isGroup       = !empty($body['is_group'])        ? 1 : 0;
            /* Mutually exclusive in the UI; if both arrive we prefer
               special-case (more constraining). */
            if ($isSpecialCase && $isGroup) { $isGroup = 0; }

            if ($name === '')           { sendJson(['error' => 'Name is required.'], 400); break; }
            if (mb_strlen($name) > 255) { sendJson(['error' => 'Name must be 255 characters or fewer.'], 400); break; }
            if ($birthDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
                sendJson(['error' => 'birth_date must be YYYY-MM-DD.'], 400); break;
            }
            if ($deathDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deathDate)) {
                sendJson(['error' => 'death_date must be YYYY-MM-DD.'], 400); break;
            }

            try {
                $db = getDbMysqli();

                $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) {
                    sendJson(['error' => "A person with the name '{$name}' is already registered."], 409);
                    break;
                }

                $db->begin_transaction();
                try {
                    /* #630 — flag columns may not exist on a partly-
                       migrated install. Skip them when absent. */
                    $hasFlagsCols = creditPeopleFlagsColumnsExist($db);
                    if ($hasFlagsCols) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblCreditPeople
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate, IsSpecialCase, IsGroup)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssssii',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $isSpecialCase, $isGroup
                        );
                    } else {
                        $stmt = $db->prepare(
                            'INSERT INTO tblCreditPeople
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssss',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate
                        );
                    }
                    $stmt->execute();
                    $newId = (int)$db->insert_id;
                    $stmt->close();

                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonLinks
                                (CreditPersonId, LinkType, Url, Label, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('isssi',
                                $newId, $l['type'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }
                    if ($ipi) {
                        $ipiStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonIPI
                                (CreditPersonId, IPINumber, NameUsed, Notes)
                             VALUES (?, ?, ?, ?)'
                        );
                        foreach ($ipi as $r) {
                            $ipiStmt->bind_param('isss',
                                $newId, $r['number'], $r['name_used'], $r['notes']);
                            $ipiStmt->execute();
                        }
                        $ipiStmt->close();
                    }
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                logActivity('api.admin.credit_person.add', 'credit_person', (string)$newId, [
                    'name'       => $name,
                    'fields'     => array_filter([
                        'birth_place' => $birthPlace,
                        'birth_date'  => $birthDate,
                        'death_place' => $deathPlace,
                        'death_date'  => $deathDate,
                        'notes'       => $notes,
                    ], static fn($v) => $v !== null),
                    'link_count' => count($links),
                    'ipi_count'  => count($ipi),
                ]);

                sendJson([
                    'ok'   => true,
                    'id'   => $newId,
                    'name' => $name,
                ], 201);
            } catch (\Throwable $e) {
                logActivityError('api.admin.credit_person.add', 'credit_person', '', $e, ['name' => $name]);
                error_log('[admin_credit_person_add] ' . $e->getMessage());
                sendJson(['error' => 'Could not add person.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: update an existing credit-person registry row
         * POST body: { id (required), name, notes?, biographical
         *              fields, is_special_case?, is_group?,
         *              links?, ipi? }
         * The Name column is NOT changed here — renames have their own
         * endpoint because their blast radius is cross-table. If the
         * body's name field differs from the stored name we reject
         * with 400 and direct the caller to admin_credit_person_rename.
         * ----------------------------------------------------------------- */
        case 'admin_credit_person_update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id          = (int)($body['id']           ?? 0);
            $name        = trim((string)($body['name']         ?? ''));
            $notesRaw    = trim((string)($body['notes']        ?? ''));
            $birthPlace  = trim((string)($body['birth_place']  ?? '')) ?: null;
            $birthDate   = trim((string)($body['birth_date']   ?? '')) ?: null;
            $deathPlace  = trim((string)($body['death_place']  ?? '')) ?: null;
            $deathDate   = trim((string)($body['death_date']   ?? '')) ?: null;
            $notes       = $notesRaw !== '' ? $notesRaw : null;
            $links       = normaliseCreditPersonLinks($body['links'] ?? null);
            $ipi         = normaliseCreditPersonIpi($body['ipi']     ?? null);
            $isSpecialCase = !empty($body['is_special_case']) ? 1 : 0;
            $isGroup       = !empty($body['is_group'])        ? 1 : 0;
            if ($isSpecialCase && $isGroup) { $isGroup = 0; }

            if ($id <= 0)               { sendJson(['error' => 'Person id required.'], 400); break; }
            if ($name === '')           { sendJson(['error' => 'Name is required.'], 400); break; }
            if ($birthDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
                sendJson(['error' => 'birth_date must be YYYY-MM-DD.'], 400); break;
            }
            if ($deathDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deathDate)) {
                sendJson(['error' => 'death_date must be YYYY-MM-DD.'], 400); break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare(
                    'SELECT Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate
                       FROM tblCreditPeople WHERE Id = ?'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $beforeRow = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if ($beforeRow === null) {
                    sendJson(['error' => 'Person not found.'], 404);
                    break;
                }
                if ((string)$beforeRow['Name'] !== $name) {
                    sendJson([
                        'error' => 'Use admin_credit_person_rename to change a person\'s name — the change cascades to every song that cites them.',
                    ], 400);
                    break;
                }

                $db->begin_transaction();
                try {
                    if (creditPeopleFlagsColumnsExist($db)) {
                        $stmt = $db->prepare(
                            'UPDATE tblCreditPeople
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?,
                                    IsSpecialCase = ?, IsGroup = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sssssiii',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $isSpecialCase, $isGroup, $id);
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE tblCreditPeople
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sssssi',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate, $id);
                    }
                    $stmt->execute();
                    $stmt->close();

                    /* Child rows: DELETE then INSERT — simpler than
                       diffing and the per-person row counts are small
                       (typically < 10 each). The child Ids change as a
                       side effect, but no other table references them. */
                    $del = $db->prepare('DELETE FROM tblCreditPersonLinks WHERE CreditPersonId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonLinks
                                (CreditPersonId, LinkType, Url, Label, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('isssi',
                                $id, $l['type'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }

                    $del = $db->prepare('DELETE FROM tblCreditPersonIPI WHERE CreditPersonId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($ipi) {
                        $ipiStmt = $db->prepare(
                            'INSERT INTO tblCreditPersonIPI
                                (CreditPersonId, IPINumber, NameUsed, Notes)
                             VALUES (?, ?, ?, ?)'
                        );
                        foreach ($ipi as $r) {
                            $ipiStmt->bind_param('isss',
                                $id, $r['number'], $r['name_used'], $r['notes']);
                            $ipiStmt->execute();
                        }
                        $ipiStmt->close();
                    }
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                $afterRow = [
                    'Notes'      => $notes,
                    'BirthPlace' => $birthPlace,
                    'BirthDate'  => $birthDate,
                    'DeathPlace' => $deathPlace,
                    'DeathDate'  => $deathDate,
                ];
                $changed = [];
                foreach ($afterRow as $k => $v) {
                    if ((string)($beforeRow[$k] ?? '') !== (string)($v ?? '')) {
                        $changed[] = $k;
                    }
                }

                logActivity('api.admin.credit_person.update', 'credit_person', (string)$id, [
                    'name'       => $name,
                    'fields'     => $changed,
                    'before'     => array_intersect_key($beforeRow, array_flip($changed)),
                    'after'      => array_intersect_key($afterRow,  array_flip($changed)),
                    'link_count' => count($links),
                    'ipi_count'  => count($ipi),
                ]);

                sendJson([
                    'ok'             => true,
                    'id'             => $id,
                    'name'           => $name,
                    'fields_changed' => $changed,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.credit_person.update', 'credit_person', (string)$id, $e);
                error_log('[admin_credit_person_update] ' . $e->getMessage());
                sendJson(['error' => 'Could not update person.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: rename a credit-person — cascades across the five
         * song-credit tables AND the registry row inside one transaction.
         * POST body: { id, new_name }
         * Refuses with 409 if the new name already belongs to a different
         * registry row (caller should use admin_credit_person_merge).
         * ----------------------------------------------------------------- */
        case 'admin_credit_person_rename': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body    = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id      = (int)($body['id']       ?? 0);
            $newName = trim((string)($body['new_name'] ?? ''));

            if ($id <= 0)                  { sendJson(['error' => 'Person id required.'], 400); break; }
            if ($newName === '')           { sendJson(['error' => 'new_name is required.'], 400); break; }
            if (mb_strlen($newName) > 255) { sendJson(['error' => 'new_name must be 255 characters or fewer.'], 400); break; }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $oldName = (string)($row[0] ?? '');
                if ($oldName === '')        { sendJson(['error' => 'Person not found.'], 404); break; }
                if ($oldName === $newName)  { sendJson(['error' => 'new_name is the same as the current name.'], 400); break; }

                $stmt = $db->prepare('SELECT Id FROM tblCreditPeople WHERE Name = ? AND Id <> ?');
                $stmt->bind_param('si', $newName, $id);
                $stmt->execute();
                $clash = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($clash) {
                    sendJson([
                        'error' => "Another registry row already uses '{$newName}'. Use admin_credit_person_merge to combine them.",
                    ], 409);
                    break;
                }

                $db->begin_transaction();
                $affected = [];
                try {
                    /* Cascade across the five song-credit tables. */
                    $tables = [
                        'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                        'tblSongAdaptors', 'tblSongTranslators',
                    ];
                    foreach ($tables as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $newName, $oldName);
                        $stmt->execute();
                        $affected[$tbl] = $stmt->affected_rows;
                        $stmt->close();
                    }
                    $stmt = $db->prepare('UPDATE tblCreditPeople SET Name = ? WHERE Id = ?');
                    $stmt->bind_param('si', $newName, $id);
                    $stmt->execute();
                    $stmt->close();
                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                $totalRenamed = array_sum($affected);
                logActivity('api.admin.credit_person.rename', 'credit_person', (string)$id, [
                    'before'   => ['name' => $oldName],
                    'after'    => ['name' => $newName],
                    'affected' => [
                        'writers'     => $affected['tblSongWriters'],
                        'composers'   => $affected['tblSongComposers'],
                        'arrangers'   => $affected['tblSongArrangers'],
                        'adaptors'    => $affected['tblSongAdaptors'],
                        'translators' => $affected['tblSongTranslators'],
                    ],
                ]);

                sendJson([
                    'ok'                  => true,
                    'id'                  => $id,
                    'old_name'            => $oldName,
                    'new_name'            => $newName,
                    'song_credit_renames' => $totalRenamed,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.credit_person.rename', 'credit_person', (string)$id, $e);
                error_log('[admin_credit_person_rename] ' . $e->getMessage());
                sendJson(['error' => 'Could not rename person.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: merge two registry entries
         * POST body: {
         *   source_id, target_id,
         *   keep_link_ids?: [int], keep_ipi_ids?: [int]
         * }
         * Re-points every song-credit row from source name → target name,
         * migrates the chosen child rows from source → target, then
         * deletes the source registry row (FK cascade drops any child
         * rows the caller chose not to migrate).
         * ----------------------------------------------------------------- */
        case 'admin_credit_person_merge': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body      = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $sourceId  = (int)($body['source_id'] ?? 0);
            $targetId  = (int)($body['target_id'] ?? 0);
            $keepLinks = array_map('intval', (array)($body['keep_link_ids'] ?? []));
            $keepIpi   = array_map('intval', (array)($body['keep_ipi_ids']  ?? []));

            if ($sourceId <= 0 || $targetId <= 0) {
                sendJson(['error' => 'source_id and target_id required.'], 400);
                break;
            }
            if ($sourceId === $targetId) {
                sendJson(['error' => 'Source and target must be different people.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Id, Name FROM tblCreditPeople WHERE Id IN (?, ?)');
                $stmt->bind_param('ii', $sourceId, $targetId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $byId = [];
                foreach ($rows as $r) { $byId[(int)$r['Id']] = (string)$r['Name']; }
                if (!isset($byId[$sourceId])) { sendJson(['error' => 'Source person not found.'], 404); break; }
                if (!isset($byId[$targetId])) { sendJson(['error' => 'Target person not found.'], 404); break; }
                $sourceName = $byId[$sourceId];
                $targetName = $byId[$targetId];

                $db->begin_transaction();
                $affected     = [];
                $linksKept    = 0;
                $linksDropped = 0;
                $ipiKept      = 0;
                $ipiDropped   = 0;
                try {
                    /* Re-point song-credit rows: source → target across
                       all five tables. */
                    $tables = [
                        'tblSongWriters', 'tblSongComposers', 'tblSongArrangers',
                        'tblSongAdaptors', 'tblSongTranslators',
                    ];
                    foreach ($tables as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $targetName, $sourceName);
                        $stmt->execute();
                        $affected[$tbl] = $stmt->affected_rows;
                        $stmt->close();
                    }

                    /* Migrate the chosen child rows from source → target.
                       Anything not in keep_link_ids / keep_ipi_ids gets
                       dropped via the cascade when the source row is
                       deleted below. */
                    $stmt = $db->prepare('SELECT Id FROM tblCreditPersonLinks WHERE CreditPersonId = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $sourceLinkIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Id');
                    $stmt->close();

                    $stmt = $db->prepare('SELECT Id FROM tblCreditPersonIPI WHERE CreditPersonId = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $sourceIpiIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Id');
                    $stmt->close();

                    if ($keepLinks && $sourceLinkIds) {
                        $toMove = array_intersect($keepLinks, array_map('intval', $sourceLinkIds));
                        if ($toMove) {
                            $upd = $db->prepare(
                                'UPDATE tblCreditPersonLinks SET CreditPersonId = ? WHERE Id = ? AND CreditPersonId = ?'
                            );
                            foreach ($toMove as $lid) {
                                $upd->bind_param('iii', $targetId, $lid, $sourceId);
                                $upd->execute();
                                $linksKept += $upd->affected_rows;
                            }
                            $upd->close();
                        }
                    }
                    $linksDropped = max(0, count($sourceLinkIds) - $linksKept);

                    if ($keepIpi && $sourceIpiIds) {
                        $toMove = array_intersect($keepIpi, array_map('intval', $sourceIpiIds));
                        if ($toMove) {
                            $upd = $db->prepare(
                                'UPDATE tblCreditPersonIPI SET CreditPersonId = ? WHERE Id = ? AND CreditPersonId = ?'
                            );
                            foreach ($toMove as $iid) {
                                $upd->bind_param('iii', $targetId, $iid, $sourceId);
                                $upd->execute();
                                $ipiKept += $upd->affected_rows;
                            }
                            $upd->close();
                        }
                    }
                    $ipiDropped = max(0, count($sourceIpiIds) - $ipiKept);

                    /* Drop the source registry row. Cascade removes
                       any child rows we chose not to migrate. */
                    $stmt = $db->prepare('DELETE FROM tblCreditPeople WHERE Id = ?');
                    $stmt->bind_param('i', $sourceId);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();
                } catch (\Throwable $txErr) {
                    $db->rollback();
                    throw $txErr;
                }

                $totalRenamed = array_sum($affected);
                logActivity('api.admin.credit_person.merge', 'credit_person', (string)$targetId, [
                    'source'     => ['id' => $sourceId, 'name' => $sourceName],
                    'target'     => ['id' => $targetId, 'name' => $targetName],
                    'affected'   => [
                        'writers'     => $affected['tblSongWriters'],
                        'composers'   => $affected['tblSongComposers'],
                        'arrangers'   => $affected['tblSongArrangers'],
                        'adaptors'    => $affected['tblSongAdaptors'],
                        'translators' => $affected['tblSongTranslators'],
                    ],
                    'child_rows' => [
                        'links_kept'    => $linksKept,
                        'links_dropped' => $linksDropped,
                        'ipi_kept'      => $ipiKept,
                        'ipi_dropped'   => $ipiDropped,
                    ],
                ]);

                sendJson([
                    'ok'                  => true,
                    'source_id'           => $sourceId,
                    'target_id'           => $targetId,
                    'source_name'         => $sourceName,
                    'target_name'         => $targetName,
                    'song_credit_renames' => $totalRenamed,
                    'links_kept'          => $linksKept,
                    'links_dropped'       => $linksDropped,
                    'ipi_kept'            => $ipiKept,
                    'ipi_dropped'         => $ipiDropped,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.credit_person.merge', 'credit_person', (string)$targetId, $e, [
                    'source_id' => $sourceId,
                ]);
                error_log('[admin_credit_person_merge] ' . $e->getMessage());
                sendJson(['error' => 'Could not merge people.'], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: delete a registry row
         * POST body: { id, force? }
         * Refuses with 422 + usage_count if any song-credit table still
         * cites the name AND force is not set. With force=true the
         * registry row is removed even if song credits still reference
         * the name (the credits stay — only the registry row goes).
         * ----------------------------------------------------------------- */
        case 'admin_credit_person_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $body  = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $id    = (int)($body['id'] ?? 0);
            $force = !empty($body['force']);
            if ($id <= 0) {
                sendJson(['error' => 'Person id required.'], 400);
                break;
            }

            try {
                $db = getDbMysqli();
                $stmt = $db->prepare('SELECT Name FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    sendJson(['error' => 'Person not found.'], 404);
                    break;
                }

                /* Single round-trip count across all five song-credit
                   tables. */
                $stmt = $db->prepare(
                    "SELECT (
                        (SELECT COUNT(*) FROM tblSongWriters     WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongComposers   WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongArrangers   WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongAdaptors    WHERE Name = ?) +
                        (SELECT COUNT(*) FROM tblSongTranslators WHERE Name = ?)
                     ) AS total"
                );
                $stmt->bind_param('sssss', $name, $name, $name, $name, $name);
                $stmt->execute();
                $usage = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();

                if ($usage > 0 && !$force) {
                    sendJson([
                        'error'        => "Cannot delete '{$name}': {$usage} song-credit row(s) still cite this name.",
                        'usage_count'  => $usage,
                        'name'         => $name,
                        'hint'         => 'Pass force=true to delete the registry row anyway — the song credits stay.',
                    ], 422);
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblCreditPeople WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                logActivity('api.admin.credit_person.delete', 'credit_person', (string)$id, [
                    'name'        => $name,
                    'had_credits' => $usage > 0,
                    'force'       => $force,
                ]);

                sendJson([
                    'ok'           => true,
                    'id'           => $id,
                    'name'         => $name,
                    'usage_count'  => $usage,
                    'forced'       => $force,
                ]);
            } catch (\Throwable $e) {
                logActivityError('api.admin.credit_person.delete', 'credit_person', (string)$id, $e);
                error_log('[admin_credit_person_delete] ' . $e->getMessage());
                sendJson(['error' => 'Could not delete person.'], 500);
            }
            break;
        }

        /* =================================================================
         * ADMIN ANALYTICS — search-query reporting (#719 PR 2d)
         *
         * Mirrors the Top search queries / Zero-result panels on
         * /manage/analytics. The other two analytics verbs
         * (`top_songs` / `top_books`) are already covered by the
         * existing `popular_songs` endpoint; this fills the search-side
         * gap.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: top + zero-result search queries
         * GET ?action=admin_analytics_searches&range=7|30|90&limit=15
         * Default range = 30 days, limit = 15.
         * ----------------------------------------------------------------- */
        case 'admin_analytics_searches': {
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $range = (int)($_GET['range'] ?? 30);
            if (!in_array($range, [7, 30, 90], true)) { $range = 30; }
            $limit = (int)($_GET['limit'] ?? 15);
            if ($limit < 1 || $limit > 100)           { $limit = 15; }
            $since = (new DateTime("-{$range} days"))->format('Y-m-d H:i:s');

            $top  = [];
            $zero = [];
            try {
                $db = getDbMysqli();

                $stmt = $db->prepare(
                    'SELECT Query AS query, COUNT(*) AS hits, MAX(ResultCount) AS top_count
                       FROM tblSearchQueries
                      WHERE SearchedAt >= ? AND Query <> ""
                      GROUP BY Query
                      ORDER BY hits DESC
                      LIMIT ?'
                );
                $stmt->bind_param('si', $since, $limit);
                $stmt->execute();
                $top = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $stmt = $db->prepare(
                    'SELECT Query AS query, COUNT(*) AS hits
                       FROM tblSearchQueries
                      WHERE SearchedAt >= ? AND ResultCount = 0 AND Query <> ""
                      GROUP BY Query
                      ORDER BY hits DESC
                      LIMIT ?'
                );
                $stmt->bind_param('si', $since, $limit);
                $stmt->execute();
                $zero = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                /* Coerce numeric fields from string (mysqli_result default)
                   so JSON consumers don't have to parseInt. */
                foreach ($top as &$r) {
                    $r['hits']      = (int)$r['hits'];
                    $r['top_count'] = $r['top_count'] !== null ? (int)$r['top_count'] : 0;
                }
                unset($r);
                foreach ($zero as &$r) { $r['hits'] = (int)$r['hits']; }
                unset($r);
            } catch (\Throwable $e) {
                /* tblSearchQueries may not exist on a pre-#404 deploy.
                   Surface as 503 so the caller knows it's a setup issue
                   rather than malformed input. */
                error_log('[admin_analytics_searches] ' . $e->getMessage());
                sendJson([
                    'error' => 'Search analytics not yet available — tblSearchQueries may not be created.',
                    'note'  => 'Run /manage/setup-database to apply the search-query migration.',
                ], 503);
                break;
            }

            sendJson([
                'range_days' => $range,
                'since'      => $since,
                'top'        => $top,
                'zero'       => $zero,
            ]);
            break;
        }

        /* =================================================================
         * READ-SIDE DIAGNOSTICS (#719 PR 2d)
         *
         * Three operational endpoints natives + tooling clients (CI,
         * monitoring) probably want. All admin / global_admin gated.
         * Pure read paths; no mutations.
         * ================================================================= */

        /* -----------------------------------------------------------------
         * Admin: data-health snapshot
         * GET ?action=admin_data_health
         * Returns table-row counts for the canonical tables, plus the
         * legacy-fallback state (songs.json fallback active? share JSON
         * file count vs DB row count? legacy SQLite present?). Mirrors
         * /manage/data-health's panel.
         * ----------------------------------------------------------------- */
        case 'admin_data_health': {
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            /* Same allowlist /manage/data-health uses. Hard-coded so a
               future tweak to the canonical-table list lands once and
               both surfaces pick it up by reading this constant. */
            $healthTables = [
                'tblSongs', 'tblSongbooks', 'tblUsers', 'tblUserSetlists',
                'tblSharedSetlists', 'tblSongRequests', 'tblSongRevisions',
                'tblUserGroups', 'tblOrganisations',
            ];

            try {
                $db = getDbMysqli();
            } catch (\Throwable $dbErr) {
                sendJson([
                    'error'         => 'Database unreachable.',
                    'database_up'   => false,
                    'detail'        => $dbErr->getMessage(),
                ], 503);
                break;
            }

            $tableCounts = [];
            foreach ($healthTables as $tbl) {
                try {
                    /* Allowlist guard: $tbl came from the literal array
                       above, but a belt-and-braces in_array check makes
                       the safety provable at the query site without
                       tracing control flow. */
                    if (!in_array($tbl, $healthTables, true)) { continue; }
                    $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $tbl . '`');
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_row();
                    $tableCounts[$tbl] = (int)($row[0] ?? 0);
                    $stmt->close();
                } catch (\Throwable $_e) {
                    /* Table missing on a fresh deploy is expected;
                       represent as null. */
                    $tableCounts[$tbl] = null;
                }
            }

            /* SongData JSON fallback probe — non-fatal on partly-loaded
               configs. The probe runs the SongData constructor which
               may throw when songs.json is corrupt; we surface the
               exception text in `note` rather than failing the whole
               response. */
            $songDataJsonFallback = null;
            $songDataNote         = null;
            try {
                if (class_exists('\\SongData')) {
                    $probe = new \SongData();
                    $songDataJsonFallback = $probe->isJsonFallback();
                }
            } catch (\Throwable $sdErr) {
                $songDataNote = 'SongData probe failed: ' . $sdErr->getMessage();
            }

            /* Legacy share-directory + SQLite presence checks. Defined
               constants come from config.php; treat absence as "not
               configured" rather than a 500. */
            $songsJsonPath = defined('APP_DATA_FILE')          ? APP_DATA_FILE          : '';
            $shareDirPath  = defined('APP_SETLIST_SHARE_DIR')  ? APP_SETLIST_SHARE_DIR  : '';
            $sqliteDbPath  = defined('APP_ROOT')
                ? dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'SQLite'
                  . DIRECTORY_SEPARATOR . 'ihymns.db'
                : '';

            $shareFileCount      = null;
            $unimportedShareIds  = [];
            if ($shareDirPath && is_dir($shareDirPath)) {
                $files = glob($shareDirPath . DIRECTORY_SEPARATOR . '*.json') ?: [];
                $shareFileCount = count($files);
                if ($shareFileCount > 0 && isset($tableCounts['tblSharedSetlists'])) {
                    $idsOnDisk = array_filter(array_map(
                        fn($f) => preg_match('/^[a-f0-9]{6,32}$/i', basename($f, '.json'))
                                    ? basename($f, '.json') : null,
                        $files
                    ));
                    try {
                        $stmt = $db->prepare('SELECT ShareId FROM tblSharedSetlists');
                        $stmt->execute();
                        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        $inDb = array_fill_keys(array_column($rows, 'ShareId'), true);
                        foreach ($idsOnDisk as $id) {
                            if (!isset($inDb[$id])) $unimportedShareIds[] = $id;
                        }
                    } catch (\Throwable $_e) {
                        /* tblSharedSetlists missing — leave unimported list empty. */
                    }
                }
            }
            $sqliteExists = $sqliteDbPath && file_exists($sqliteDbPath);
            $sqliteSize   = $sqliteExists ? (int)@filesize($sqliteDbPath) : 0;

            sendJson([
                'database_up'           => true,
                'table_counts'          => $tableCounts,
                'songs_json_fallback'   => $songDataJsonFallback,
                'songs_json_note'       => $songDataNote,
                'share_dir' => [
                    'configured'        => $shareDirPath !== '',
                    'present'           => $shareDirPath !== '' && is_dir($shareDirPath),
                    'file_count'        => $shareFileCount,
                    'unimported_count'  => count($unimportedShareIds),
                    'unimported_ids'    => $unimportedShareIds,
                ],
                'legacy_sqlite' => [
                    'present'   => $sqliteExists,
                    'size_bytes'=> $sqliteSize,
                ],
            ]);
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: schema-audit JSON
         * GET ?action=admin_schema_audit
         * Mirrors /manage/schema-audit's diff payload — schema.sql vs
         * live DB vs migration coverage. Returns the same {byTable, summary}
         * shape the page consumes.
         * ----------------------------------------------------------------- */
        case 'admin_schema_audit': {
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $schemaSrc = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'schema.sql';
            $sqlDir    = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.sql';

            try {
                $db = getDbMysqli();
                if (!is_readable($schemaSrc)) {
                    throw new \RuntimeException('schema.sql not readable at ' . $schemaSrc);
                }
                $schemaSql  = (string)file_get_contents($schemaSrc);
                $schemaCols = schemaAuditParseSchema($schemaSql);
                if (!$schemaCols) {
                    throw new \RuntimeException('Parsed zero tables from schema.sql — parser broken or file shape changed.');
                }
                $migrations = schemaAuditScanMigrations($sqlDir);
                $dbCols     = schemaAuditReadDb($db);
                $audit      = schemaAuditCompare($schemaCols, $dbCols, $migrations);

                sendJson([
                    'summary'  => $audit['summary'],
                    'by_table' => $audit['byTable'],
                ]);
            } catch (\Throwable $e) {
                error_log('[admin_schema_audit] ' . $e->getMessage());
                sendJson(['error' => 'Schema audit failed: ' . $e->getMessage()], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: migrations status
         * GET ?action=admin_migrations_status
         * Walks every migrate-*.php under appWeb/.sql/, matches each
         * declared (table, column) against the live DB, and reports
         * an applied|partial|pending status per migration. Designed
         * for tooling clients (CI, monitoring) — derives state from
         * the schema rather than maintaining a separate registry.
         * ----------------------------------------------------------------- */
        case 'admin_migrations_status': {
            $authUser = getAuthenticatedUser();
            if (!$authUser || !in_array($authUser['Role'], ['admin', 'global_admin'])) {
                sendJson(['error' => 'Admin access required.'], 403);
                break;
            }

            $sqlDir = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.sql';

            try {
                $db = getDbMysqli();
                $migrations = schemaAuditScanMigrations($sqlDir);
                $dbCols     = schemaAuditReadDb($db);

                /* Invert: [tbl.col => [files...]] becomes [file => [(tbl,col), ...]]. */
                $byFile = [];
                foreach ($migrations as $key => $files) {
                    [$tbl, $col] = explode('.', $key, 2);
                    foreach ($files as $f) {
                        $byFile[$f][] = ['table' => $tbl, 'column' => $col];
                    }
                }

                $report = [];
                foreach ($byFile as $file => $declared) {
                    $applied = 0;
                    foreach ($declared as $d) {
                        if (in_array($d['column'], $dbCols[$d['table']] ?? [], true)) {
                            $applied++;
                        }
                    }
                    $total  = count($declared);
                    $status = $applied === 0       ? 'pending'
                            : ($applied === $total ? 'applied' : 'partial');
                    $report[] = [
                        'file'             => $file,
                        'declared_columns' => $total,
                        'applied_columns'  => $applied,
                        'status'           => $status,
                    ];
                }

                /* Stable sort: pending first, then partial, then applied;
                   alphabetic within each group so the report reads as a
                   "what to run next" punchlist. */
                usort($report, function (array $a, array $b): int {
                    $rank = ['pending' => 0, 'partial' => 1, 'applied' => 2];
                    return $rank[$a['status']] <=> $rank[$b['status']]
                         ?: strcmp($a['file'], $b['file']);
                });

                $summary = ['pending' => 0, 'partial' => 0, 'applied' => 0];
                foreach ($report as $r) { $summary[$r['status']]++; }

                sendJson([
                    'summary'    => $summary,
                    'migrations' => $report,
                ]);
            } catch (\Throwable $e) {
                error_log('[admin_migrations_status] ' . $e->getMessage());
                sendJson(['error' => 'Could not derive migrations status: ' . $e->getMessage()], 500);
            }
            break;
        }

        /* -----------------------------------------------------------------
         * Admin: refresh IANA Language Subtag Registry + CLDR English (#738)
         *
         * Fetches the live IANA registry + CLDR English JSON files,
         * overwrites the bundled snapshots in appWeb/.sql/data/, then
         * re-runs migrate-iana-language-subtag-registry.php so the
         * picker tables pick up new rows. Reports per-table counts in
         * the response.
         *
         * Global-admin only. POST + X-Requested-With per the standard
         * CSRF guard.
         * ----------------------------------------------------------------- */
        case 'admin_refresh_iana_cldr': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendJson(['error' => 'POST method required.'], 405);
                break;
            }
            $authUser = getAuthenticatedUser();
            if (!$authUser || ($authUser['Role'] ?? '') !== 'global_admin') {
                sendJson(['error' => 'Global admin access required.'], 403);
                break;
            }

            $dataDir = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'data';
            if (!is_dir($dataDir) || !is_writable($dataDir)) {
                sendJson([
                    'error' => "Snapshot directory not writable: {$dataDir}",
                ], 500);
                break;
            }

            /* Live URLs. The CLDR base hits the unicode-org/cldr-json
               GitHub mirror raw content; IANA serves the registry as
               a stable URL. Both are publicly fetchable, no auth. */
            $sources = [
                'iana-language-subtag-registry.txt'
                    => 'https://www.iana.org/assignments/language-subtag-registry',
                'cldr-en-languages.json'
                    => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/languages.json',
                'cldr-en-scripts.json'
                    => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/scripts.json',
                'cldr-en-territories.json'
                    => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/territories.json',
                'cldr-en-variants.json'
                    => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/variants.json',
            ];

            $fetched = [];
            $failed  = [];
            foreach ($sources as $filename => $url) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'        => 30,
                        'follow_location' => 1,
                        'header'         => "User-Agent: iHymns-IANA-Refresh/1.0\r\n",
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false || strlen($body) < 100) {
                    $failed[] = $filename;
                    continue;
                }
                $target = $dataDir . DIRECTORY_SEPARATOR . $filename;
                if (@file_put_contents($target, $body) === false) {
                    $failed[] = $filename . ' (write failed)';
                    continue;
                }
                $fetched[] = $filename . ' (' . number_format(strlen($body)) . ' bytes)';
            }

            if (!empty($failed)) {
                sendJson([
                    'error'   => 'One or more source fetches failed.',
                    'fetched' => $fetched,
                    'failed'  => $failed,
                    'hint'    => 'Check server outbound HTTPS connectivity. The bundled snapshots remain in place; pre-existing data is untouched.',
                ], 502);
                break;
            }

            /* Snapshots refreshed. Now re-run the migration so the DB
               picks up new rows. Run as a sub-process include so the
               migration's "echo" output is captured in our response
               instead of streaming through. */
            ob_start();
            try {
                define('IHYMNS_SETUP_DASHBOARD', true);
                require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'migrate-iana-language-subtag-registry.php';
                $migOutput = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                sendJson([
                    'error'   => 'Snapshots refreshed but migration re-run failed: ' . $e->getMessage(),
                    'fetched' => $fetched,
                ], 500);
                break;
            }

            sendJson([
                'ok'             => true,
                'fetched'        => $fetched,
                'migrationLog'   => $migOutput,
            ]);
            break;
        }

        /* -----------------------------------------------------------------
         * Unknown action
         * ----------------------------------------------------------------- */
        default:
            /* (#535) Record unknown-action attempts so admins can
               spot mis-typed action names from clients (often a
               sign of a forgotten case clause regressing). */
            logActivity(
                'api.unknown_action',
                'api',
                substr((string)$action, 0, 50),
                ['method' => $_SERVER['REQUEST_METHOD'] ?? ''],
                'failure'
            );
            sendJson(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
            break;
    }
    } catch (\Throwable $_apiSwitchErr) {
        /* Catch-all for exceptions that escaped a case clause.
           One activity-log row tagged Result='error'; the existing
           500-response behaviour is preserved by re-throwing. */
        logActivityError(
            'api.' . substr((string)$action, 0, 40),
            'api',
            substr((string)$action, 0, 50),
            $_apiSwitchErr,
            ['duration_ms' => (int)((microtime(true) - $_apiSwitchStart) * 1000)]
        );
        /* Re-throw so the existing fatal-handler / 500 response path
           runs as it always has. We are observing, not changing the
           failure semantics. */
        throw $_apiSwitchErr;
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

    /* Cookie fallback (#390) — enables cross-subdomain sign-in and plain
       page loads without JS having to attach the Authorization header. */
    if (!empty($_COOKIE['ihymns_auth']) && preg_match('/^[a-f0-9]{64}$/i', $_COOKIE['ihymns_auth'])) {
        return $_COOKIE['ihymns_auth'];
    }

    return null;
}

/**
 * Return the standard cookie options used for the auth cookie (#390).
 *
 * The `Domain` attribute is only set when the current host is under
 * `ihymns.app`, so in local/dev environments (e.g. `localhost`) the
 * cookie stays host-only. `Secure` is set whenever the request arrived
 * over HTTPS (including via a reverse proxy that set X-Forwarded-Proto).
 */
function _authCookieOpts(int $expiresAtTimestamp): array
{
    $host  = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $https = !empty($_SERVER['HTTPS'])
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    $opts = [
        'expires'  => $expiresAtTimestamp,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ];

    /* Scope the cookie to the parent domain so every iHymns subdomain
       (alpha/beta/dev/production/sync) sees the same sign-in. */
    if (preg_match('/(?:^|\.)ihymns\.app$/i', $host)) {
        $opts['domain'] = '.ihymns.app';
    }

    return $opts;
}

/**
 * Set the `ihymns_auth` cookie used for cross-subdomain sign-in and
 * ITP-resilient persistence (#390). Safe to call before any output.
 */
function setAuthTokenCookie(string $token, int $expiresAtTimestamp): void
{
    if (headers_sent()) return;
    setcookie('ihymns_auth', $token, _authCookieOpts($expiresAtTimestamp));
}

/**
 * Clear the `ihymns_auth` cookie (logout + 401 paths).
 */
function clearAuthTokenCookie(): void
{
    if (headers_sent()) return;
    setcookie('ihymns_auth', '', _authCookieOpts(time() - 3600));
}

/**
 * Slide the token's ExpiresAt forward by 30 days so that any active use
 * resets the clock (#390). To avoid a DB write on every authenticated
 * request, we only bump when the current ExpiresAt is less than
 * (30 days - 1 day) from now — i.e. at most once per day per token.
 * Also refreshes the browser-side cookie lifetime so the two stay in sync.
 */
function slideAuthTokenExpiry(string $rawToken): void
{
    try {
        $hashedToken  = hash('sha256', $rawToken);
        $newExpiresTs = time() + 30 * 86400;
        $newExpiresAt = gmdate('c', $newExpiresTs);
        $threshold    = gmdate('c', time() + 29 * 86400);

        $db = getDbMysqli();
        $stmt = $db->prepare(
            'UPDATE tblApiTokens SET ExpiresAt = ? WHERE Token = ? AND ExpiresAt < ?'
        );
        $stmt->bind_param('sss', $newExpiresAt, $hashedToken, $threshold);
        $stmt->execute();
        $bumped = $stmt->affected_rows > 0;
        $stmt->close();

        /* If the DB row was bumped, push the cookie forward as well. */
        if ($bumped) {
            setAuthTokenCookie($rawToken, $newExpiresTs);
        }
    } catch (\Throwable $e) {
        /* Non-fatal: sliding expiry is best-effort. Logged so admins
           notice if the UPDATE is failing systematically (e.g.,
           tblApiTokens DDL drift). */
        error_log('[api/slideAuthTokenExpiry] ' . $e->getMessage());
    }
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

    $db = getDbMysqli();
    $hashedToken = hash('sha256', $token);
    $now = gmdate('c');

    /* AvatarService (#616) is selected only when the column exists, so
       a partly-migrated install keeps working. The check is cached for
       the lifetime of the request via a static. */
    static $hasAvatarSvcCol = null;
    if ($hasAvatarSvcCol === null) {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblUsers'
                AND COLUMN_NAME  = 'AvatarService' LIMIT 1"
        );
        $hasAvatarSvcCol = ($r && $r->fetch_row() !== null);
        if ($r) $r->close();
    }
    $avatarSvcCol = $hasAvatarSvcCol ? ', u.AvatarService' : ', NULL AS AvatarService';
    $emailCol     = ', u.Email';

    $stmt = $db->prepare(
        "SELECT u.Id, u.Username, u.DisplayName, u.Role
                {$emailCol}
                {$avatarSvcCol}
         FROM tblApiTokens t
         JOIN tblUsers u ON u.Id = t.UserId
         WHERE t.Token = ? AND t.ExpiresAt > ? AND u.IsActive = 1"
    );
    $stmt->bind_param('ss', $hashedToken, $now);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) return null;

    /* Sliding expiry (#390) — any active use extends the token 30 days,
       at most once per day per token. Keeps long-term users signed in
       without daily DB writes. */
    slideAuthTokenExpiry($token);

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
