<?php

declare(strict_types=1);

/**
 * iHymns — JSON-to-MySQL Data Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Reads songs.json and inserts all songbooks, songs, writers, composers,
 * and components into the MySQL database. Uses MySQLi with prepared
 * statements and wraps the entire import in a transaction.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-json.php
 *   CLI:  php appWeb/.sql/migrate-json.php --json=/path/to/songs.json
 *
 * PREREQUISITES:
 *   1. Run install.php first to create the tables
 *   2. appWeb/.auth/db_credentials.php must be configured
 *
 * BEHAVIOR:
 *   - Clears existing data and re-imports (full refresh)
 *   - Transaction-wrapped — rolls back on any failure
 *   - Builds lyrics_text from component lines for full-text search
 *
 * @requires PHP 8.1+ with mysqli extension
 */

/* =========================================================================
 * ENVIRONMENT DETECTION
 * ========================================================================= */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}

function output(string $message): void
{
    global $isCli;
    echo $message . ($isCli ? "\n" : "<br>\n");
}

/* =========================================================================
 * LOAD CREDENTIALS
 * ========================================================================= */

$credentialsFile = __DIR__ . '/../.auth/db_credentials.php';

if (!file_exists($credentialsFile)) {
    output("ERROR: Database credentials file not found.");
    output("Run: cp appWeb/.auth/db_credentials.example.php appWeb/.auth/db_credentials.php");
    exit(1);
}

require_once $credentialsFile;

/* =========================================================================
 * FIND songs.json
 * ========================================================================= */

$jsonPath = null;

/* Check CLI argument */
if ($isCli) {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--json=')) {
            $jsonPath = substr($arg, 7);
        }
    }
}

/* Default candidate paths */
if ($jsonPath === null) {
    $candidates = [
        __DIR__ . '/../data_share/song_data/songs.json',   /* Deployed runtime */
        __DIR__ . '/../../data/songs.json',                  /* Project root data/ */
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            $jsonPath = realpath($path);
            break;
        }
    }
}

if ($jsonPath === null || !file_exists($jsonPath)) {
    output("ERROR: songs.json not found.");
    output("Checked:");
    foreach ($candidates ?? [] as $p) {
        output("  - " . $p);
    }
    output("");
    output("Use: php migrate-json.php --json=/path/to/songs.json");
    exit(1);
}

/* =========================================================================
 * LOAD AND PARSE JSON
 * ========================================================================= */

output("=== iHymns JSON-to-MySQL Migration ===");
output("");
output("Loading: " . $jsonPath);

$jsonContent = file_get_contents($jsonPath);
if ($jsonContent === false) {
    output("ERROR: Failed to read songs.json");
    exit(1);
}

$data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data) || !isset($data['songbooks'], $data['songs'])) {
    output("ERROR: Invalid songs.json structure (missing 'songbooks' or 'songs')");
    exit(1);
}

$songbookCount = count($data['songbooks']);
$songCount     = count($data['songs']);
output("Found: {$songbookCount} songbooks, {$songCount} songs");
output("");

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

output("Connecting to MySQL...");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
} catch (\mysqli_sql_exception $e) {
    output("ERROR: MySQL connection failed: " . $e->getMessage());
    exit(1);
}

$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$mysqli->set_charset($charset);

output("Connected.");
output("");

/* =========================================================================
 * MIGRATION — Transaction-wrapped
 * ========================================================================= */

try {
    /* Disable FK checks for clean truncation, then re-enable */
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

    $mysqli->begin_transaction();

    /* --- Clear existing data --- */
    output("Clearing existing data...");
    $mysqli->query("TRUNCATE TABLE song_components");
    $mysqli->query("TRUNCATE TABLE song_composers");
    $mysqli->query("TRUNCATE TABLE song_writers");
    $mysqli->query("TRUNCATE TABLE songs");
    $mysqli->query("TRUNCATE TABLE songbooks");

    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

    /* --- Insert songbooks --- */
    output("Inserting songbooks...");
    $stmtSongbook = $mysqli->prepare(
        "INSERT INTO songbooks (abbreviation, name, song_count) VALUES (?, ?, ?)"
    );

    foreach ($data['songbooks'] as $book) {
        $abbr  = $book['id'];
        $name  = $book['name'];
        $count = (int)($book['songCount'] ?? 0);
        $stmtSongbook->bind_param('ssi', $abbr, $name, $count);
        $stmtSongbook->execute();
    }
    $stmtSongbook->close();
    output("  Inserted {$songbookCount} songbooks.");

    /* --- Prepare song-related statements --- */
    $stmtSong = $mysqli->prepare(
        "INSERT INTO songs (song_id, number, title, songbook_abbr, songbook_name,
         language, copyright, ccli, verified, lyrics_public_domain,
         music_public_domain, has_audio, has_sheet_music, lyrics_text)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmtWriter = $mysqli->prepare(
        "INSERT INTO song_writers (song_id, name) VALUES (?, ?)"
    );

    $stmtComposer = $mysqli->prepare(
        "INSERT INTO song_composers (song_id, name) VALUES (?, ?)"
    );

    $stmtComponent = $mysqli->prepare(
        "INSERT INTO song_components (song_id, type, number, sort_order, lines_json)
         VALUES (?, ?, ?, ?, ?)"
    );

    /* --- Insert songs --- */
    output("Inserting songs...");
    $writerCount    = 0;
    $composerCount  = 0;
    $componentCount = 0;
    $progressStep   = max(1, (int)($songCount / 10));

    foreach ($data['songs'] as $i => $song) {
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

        /* Build lyrics_text from all component lines */
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

        /* Insert writers */
        foreach ($song['writers'] ?? [] as $writer) {
            $stmtWriter->bind_param('ss', $songId, $writer);
            $stmtWriter->execute();
            $writerCount++;
        }

        /* Insert composers */
        foreach ($song['composers'] ?? [] as $composer) {
            $stmtComposer->bind_param('ss', $songId, $composer);
            $stmtComposer->execute();
            $composerCount++;
        }

        /* Insert components */
        $sortOrder = 0;
        foreach ($song['components'] ?? [] as $comp) {
            $compType   = $comp['type'];
            $compNumber = (int)$comp['number'];
            $linesJson  = json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmtComponent->bind_param('ssiis', $songId, $compType, $compNumber, $sortOrder, $linesJson);
            $stmtComponent->execute();
            $componentCount++;
            $sortOrder++;
        }

        /* Progress reporting */
        if ($isCli && ($i + 1) % $progressStep === 0) {
            $pct = round(($i + 1) / $songCount * 100);
            output("  ... {$pct}% ({$i}/{$songCount} songs)");
        }
    }

    $stmtSong->close();
    $stmtWriter->close();
    $stmtComposer->close();
    $stmtComponent->close();

    /* --- Commit transaction --- */
    $mysqli->commit();

    output("");
    output("--- Migration Complete ---");
    output("  Songs:      {$songCount}");
    output("  Songbooks:  {$songbookCount}");
    output("  Writers:    {$writerCount}");
    output("  Composers:  {$composerCount}");
    output("  Components: {$componentCount}");
    output("");
    output("Migration successful.");

} catch (\Exception $e) {
    /* Roll back on any failure */
    $mysqli->rollback();
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    output("");
    output("ERROR: Migration failed — all changes rolled back.");
    output("Reason: " . $e->getMessage());
    $mysqli->close();
    exit(1);
}

$mysqli->close();
exit(0);
