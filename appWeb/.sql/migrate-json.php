<?php

declare(strict_types=1);

/**
 * iHymns — JSON-to-MySQL Data Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * LEGACY pre-#1010 bootstrap importer. Reads songs.json and inserts all
 * songbooks, songs, writers, composers, and components into the MySQL
 * database. Uses MySQLi with prepared statements and wraps the entire
 * import in a transaction.
 *
 * ⚠ DESTRUCTIVE — TRUNCATEs tblSongComponents/tblSongComposers/
 * tblSongWriters/tblSongs/tblSongbooks before re-importing. Song reads have
 * been DB-direct since the WS-J #1020 cutover (rule #17 of .claude/CLAUDE.md)
 * — this repo's data/songs.json is now a stale historical snapshot, not a
 * live source, so running this against a database with any post-#1010
 * curator edits DESTROYS them and orphans tblLyricLines. Only ever intended
 * for bootstrapping a brand-new, empty install. Requires --confirm (CLI) or
 * ?confirm=1 (web) — without it, the script only prints its pre-flight
 * summary (file found, songbook/song counts, DB connectivity) and exits,
 * same convention as this folder's other destructive scripts (restore.php,
 * drop-legacy-tables.php).
 *
 * USAGE:
 *   CLI dry-run: php appWeb/.sql/migrate-json.php
 *   CLI execute: php appWeb/.sql/migrate-json.php --confirm
 *   CLI:         php appWeb/.sql/migrate-json.php --json=/path/to/songs.json --confirm
 *   Web:         /manage/setup-database.php?action=migrate&confirm=1
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

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    /* Only set a plaintext Content-Type when invoked standalone. When the
     * Setup dashboard includes this script, it renders the captured <br>
     * output inside its HTML page, so text/plain would corrupt the outer
     * response on strict browsers (iOS Safari/Edge with nosniff). */
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

$credentialsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';

if (!file_exists($credentialsFile)) {
    output("ERROR: Database credentials file not found.");
    output("Run: cp appWeb/.auth/db_credentials.example.php appWeb/.auth/db_credentials.php");
    return;
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
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'song_data' . DIRECTORY_SEPARATOR . 'songs.json',   /* Deployed runtime */
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'songs.json',                  /* Project root data/ */
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
    return;
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
    return;
}

$data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data) || !isset($data['songbooks'], $data['songs'])) {
    output("ERROR: Invalid songs.json structure (missing 'songbooks' or 'songs')");
    return;
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
    return;
}

$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$mysqli->set_charset($charset);

output("Connected.");
output("");

/* #1235 P4/C6 retired-era guard. This is the LEGACY early-JSON bootstrap importer: it
   TRUNCATEs tblSongComponents and re-INSERTs LinesJson straight from data/songs.json. After
   the cutover, tblLyricLines is the authoritative store and the JSON payload columns are
   retired — so the INSERT would throw under STRICT AND a re-import would DISCARD the
   normalized lyrics/enrichment. Refuse to run once the thin schema is in place. */
$_mjLinesCol = $mysqli->query("SHOW COLUMNS FROM tblSongComponents LIKE 'LinesJson'");
$_mjLinesPresent = ($_mjLinesCol && $_mjLinesCol->num_rows > 0);
if ($_mjLinesCol) { $_mjLinesCol->close(); }
if (!$_mjLinesPresent) {
    output("ABORT: tblSongComponents.LinesJson is gone (#1235 P4/C6 retired era).");
    output("This legacy JSON bootstrap import is incompatible with the post-cutover thin schema");
    output("and would discard the authoritative tblLyricLines. Use the editor / importers instead.");
    return;
}

/* =========================================================================
 * CONFIRM GATE — this is a DESTRUCTIVE, irreversible legacy bootstrap.
 *
 * ELI5: this script wipes the live songs table and rebuilds it from a JSON
 * file that hasn't been updated in months — so it needs the operator to
 * explicitly say "yes, do that" before it touches anything, exactly like the
 * other two data-destroying scripts in this folder (restore.php,
 * drop-legacy-tables.php) already require.
 *
 * DETAILED — before this gate, `?action=migrate` on setup-database.php ran
 * this script (TRUNCATE tblSongComponents/tblSongComposers/tblSongWriters/
 * tblSongs/tblSongbooks, then a full re-import) from a single unconfirmed
 * GET request, with only a client-side `confirm()` dialog standing between a
 * click (or a followed link, or a bookmarked/replayed URL, none of which run
 * JavaScript) and destroying the live corpus. Since the DB-direct rewrite
 * (epic #1010, rule #17 of .claude/CLAUDE.md) MySQL — not this repo's
 * data/songs.json — is the ONLY source of truth for songs; that JSON
 * snapshot is a pre-#1010 bootstrap artifact that predates months of
 * curator edits, so re-importing it is almost certainly never what anyone
 * actually wants outside a brand-new empty install. Mirrors restore.php /
 * drop-legacy-tables.php's own confirm=1 gate exactly (CLI --confirm / web
 * ?confirm=1); the informational summary above (file found, songbook/song
 * counts, DB connection, the #1235 P4/C6 guard) still runs unconditionally
 * as a safe preview — only the destructive section below is gated.
 * ========================================================================= */
$_mjConfirmed = false;
if ($isCli) {
    foreach ($argv as $arg) {
        if ($arg === '--confirm') { $_mjConfirmed = true; }
    }
} else {
    $_mjConfirmed = (($_GET['confirm'] ?? '') === '1');
}

if (!$_mjConfirmed) {
    output("⚠ DESTRUCTIVE — this is the LEGACY pre-#1010 bootstrap importer.");
    output("It will TRUNCATE tblSongComponents, tblSongComposers, tblSongWriters,");
    output("tblSongs and tblSongbooks, then re-import EVERYTHING from the JSON file above.");
    output("Song reads have been DB-direct since #1010 (rule #17) — this repo's songs.json");
    output("snapshot is now months stale, so this will DESTROY newer curator edits and");
    output("orphan tblLyricLines. This is almost certainly not what you want.");
    output("");
    output("DRY RUN — nothing has been changed.");
    output("Re-run with --confirm (CLI) or ?confirm=1 (web) to proceed.");
    return;
}

/* =========================================================================
 * MIGRATION — Transaction-wrapped
 * ========================================================================= */

try {
    /* Disable FK checks for clean truncation, then re-enable */
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

    $mysqli->begin_transaction();

    /* --- Clear existing data --- */
    output("Clearing existing data...");
    $mysqli->query("TRUNCATE TABLE tblSongComponents");
    $mysqli->query("TRUNCATE TABLE tblSongComposers");
    $mysqli->query("TRUNCATE TABLE tblSongWriters");
    $mysqli->query("TRUNCATE TABLE tblSongs");
    $mysqli->query("TRUNCATE TABLE tblSongbooks");

    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

    /* --- Insert songbooks --- */
    output("Inserting songbooks...");
    $stmtSongbook = $mysqli->prepare(
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
    output("  Inserted {$songbookCount} songbooks.");

    /* --- Prepare song-related statements --- */
    $stmtSong = $mysqli->prepare(
        "INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr,
         Language, Copyright, Ccli, Verified, LyricsPublicDomain,
         MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmtWriter = $mysqli->prepare(
        "INSERT INTO tblSongWriters (SongId, Name) VALUES (?, ?)"
    );

    $stmtComposer = $mysqli->prepare(
        "INSERT INTO tblSongComposers (SongId, Name) VALUES (?, ?)"
    );

    $stmtComponent = $mysqli->prepare(
        "INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson)
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
        $songbookAbbr = $song['songbook'];

        /* Number is NULL for Misc (unstructured collection) or when the
           source simply doesn't have one (#392). Anything else is stored
           as its integer value. */
        $rawNumber = $song['number'] ?? null;
        $number    = ($songbookAbbr === 'Misc' || $rawNumber === null || $rawNumber === '' || (int)$rawNumber <= 0)
            ? null
            : (int)$rawNumber;

        $title        = $song['title'];
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

        /* SongbookName denorm column dropped (WS-E #1013 ph2) —
           13 cols / 13 placeholders / 13 bind types. */
        $stmtSong->bind_param(
            'sisssssiiiiis',
            $songId, $number, $title, $songbookAbbr,
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
    return;
}

$mysqli->close();
return;
