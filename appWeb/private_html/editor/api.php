<?php

declare(strict_types=1);

/**
 * ============================================================================
 * iHymns Song Editor — API Endpoint (#154)
 * ============================================================================
 *
 * Provides PHP-powered read/write access to the canonical songs.json file
 * stored in appWeb/data_share/song_data/songs.json. This eliminates the
 * need for CI/CD to copy songs.json into private_html/data/ and allows
 * the editor to save changes directly back to the canonical location.
 *
 * ENDPOINTS:
 *   GET  api.php?action=load  — Read songs.json, returns JSON content
 *   POST api.php?action=save  — Write songs.json from POST body
 *
 * SECURITY:
 *   - This file is inside private_html/ (restricted at server level)
 *   - No public access — protected by directory-level auth (.htpasswd)
 *   - Input validation on save: must be valid JSON with required structure
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * @license Proprietary — All rights reserved
 * @requires PHP 8.5+
 * ============================================================================
 */

/* =========================================================================
 * PATH CONFIGURATION
 *
 * The canonical songs.json lives in data_share/song_data/ which is a
 * sibling directory to both public_html/ and private_html/.
 * From private_html/editor/, the relative path is ../../data_share/.
 * ========================================================================= */

/** Resolve the absolute path to the data_share directory */
$dataShareDir = realpath(__DIR__ . '/../../data_share/song_data');

/* Fallback paths for different server configurations and local development */
$candidatePaths = [
    __DIR__ . '/../../data_share/song_data/songs.json',   /* Deployed: standard location */
    __DIR__ . '/../data/songs.json',                       /* Legacy: private_html/data/ */
    __DIR__ . '/../../../data/songs.json',                 /* Local dev: project root data/ */
];

/**
 * Find the songs.json file from candidate paths.
 * Returns the first path that exists, or null.
 */
function findSongsFile(array $candidates): ?string
{
    foreach ($candidates as $path) {
        $resolved = realpath($path);
        if ($resolved !== false && file_exists($resolved)) {
            return $resolved;
        }
    }
    return null;
}

/**
 * Find the writable songs.json path for saving.
 * Prefers the data_share location; creates directory if needed.
 */
function getWritableSongsPath(array $candidates): ?string
{
    /* Prefer the primary (data_share) location */
    $primary = $candidates[0];
    $dir = dirname($primary);

    /* Create directory if it doesn't exist */
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    /* If the primary location's directory is writable, use it */
    if (is_dir($dir) && is_writable($dir)) {
        return $primary;
    }

    /* Fall back to any existing writable path */
    foreach ($candidates as $path) {
        $resolved = realpath($path);
        if ($resolved !== false && is_writable($resolved)) {
            return $resolved;
        }
    }

    return null;
}

/* =========================================================================
 * REQUEST HANDLING
 * ========================================================================= */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = $_GET['action'] ?? '';

switch ($action) {

    /* -----------------------------------------------------------------
     * LOAD — Read songs.json and return its contents
     * ----------------------------------------------------------------- */
    case 'load':
        $filePath = findSongsFile($candidatePaths);

        if ($filePath === null) {
            http_response_code(404);
            error_log('[iHymns Editor] songs.json not found. Checked: ' . implode(', ', $candidatePaths));
            echo json_encode([
                'error' => 'songs.json not found.',
            ]);
            break;
        }

        /* Stream the file contents directly (efficient for large files) */
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        break;

    /* -----------------------------------------------------------------
     * SAVE — Write songs.json from the POST body
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
            echo json_encode(['error' => 'Invalid songs.json structure. Required keys: meta, songbooks, songs.']);
            break;
        }

        /* Find a writable path */
        $writePath = getWritableSongsPath($candidatePaths);
        if ($writePath === null) {
            http_response_code(500);
            error_log('[iHymns Editor] No writable path found. Checked: ' . implode(', ', $candidatePaths));
            echo json_encode([
                'error' => 'No writable path found for songs.json.',
            ]);
            break;
        }

        /* Create a backup before overwriting */
        $backupPath = $writePath . '.backup';
        if (file_exists($writePath)) {
            copy($writePath, $backupPath);
        }

        /* Write the file with pretty-print for human readability */
        $jsonOutput = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        $written = file_put_contents($writePath, $jsonOutput, LOCK_EX);

        if ($written === false) {
            /* Restore backup on failure */
            if (file_exists($backupPath)) {
                copy($backupPath, $writePath);
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write songs.json.']);
            break;
        }

        echo json_encode([
            'success'  => true,
            'bytes'    => $written,
            'songs'    => count($data['songs']),
        ]);
        break;

    /* -----------------------------------------------------------------
     * Unknown action
     * ----------------------------------------------------------------- */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Use: load, save']);
        break;
}
