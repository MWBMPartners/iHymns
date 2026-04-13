<?php

declare(strict_types=1);

/**
 * iHymns — Generate Full SQL Export (Schema + Song Data)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Generates a single .sql file containing both the database schema
 * and all song data from data/songs.json as INSERT statements.
 * This allows instant database setup on first install.
 *
 * USAGE:
 *   php appWeb/.sql/.fulldata/generate-full-sql.php
 *
 * OUTPUT:
 *   appWeb/.sql/.fulldata/ihymns-full.sql
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    http_response_code(403);
    exit('CLI only.');
}

$projectRoot = dirname(__DIR__, 3);
$schemaFile  = dirname(__DIR__) . '/schema.sql';
$jsonFile    = $projectRoot . '/data/songs.json';
$outputFile  = __DIR__ . '/ihymns-full.sql';

if (!file_exists($schemaFile)) {
    echo "ERROR: schema.sql not found at {$schemaFile}\n";
    exit(1);
}
if (!file_exists($jsonFile)) {
    echo "ERROR: songs.json not found at {$jsonFile}\n";
    exit(1);
}

echo "=== iHymns Full SQL Generator ===\n\n";

/* Read schema */
$schema = file_get_contents($schemaFile);
echo "Schema loaded (" . strlen($schema) . " bytes)\n";

/* Read and parse songs.json */
echo "Loading songs.json...\n";
$data = json_decode(file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
$songCount = count($data['songs']);
echo "Loaded {$songCount} songs\n\n";

/* Build output */
$out = fopen($outputFile, 'w');

fwrite($out, "-- ============================================================================\n");
fwrite($out, "-- iHymns — Full Database Export (Schema + Song Data)\n");
fwrite($out, "-- Generated: " . date('c') . "\n");
fwrite($out, "-- Songs: {$songCount}\n");
fwrite($out, "-- ============================================================================\n\n");
fwrite($out, "SET NAMES utf8mb4;\n");
fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

/* Write schema */
fwrite($out, $schema);
fwrite($out, "\n\n");

/* Write songbook data */
fwrite($out, "-- ============================================================================\n");
fwrite($out, "-- SONG DATA\n");
fwrite($out, "-- ============================================================================\n\n");

foreach ($data['songbooks'] as $book) {
    $abbr  = addslashes($book['id']);
    $name  = addslashes($book['name']);
    $count = (int)$book['songCount'];
    fwrite($out, "INSERT IGNORE INTO tblSongbooks (Abbreviation, Name, SongCount) VALUES ('{$abbr}', '{$name}', {$count});\n");
}
fwrite($out, "\n");

echo "Writing songs...\n";
$writerCount = 0;
$composerCount = 0;
$componentCount = 0;

foreach ($data['songs'] as $i => $song) {
    $songId       = addslashes($song['id']);
    $number       = (int)$song['number'];
    $title        = addslashes($song['title']);
    $songbookAbbr = addslashes($song['songbook']);
    $songbookName = addslashes($song['songbookName'] ?? '');
    $language     = addslashes($song['language'] ?? 'en');
    $copyright    = addslashes($song['copyright'] ?? '');
    $ccli         = addslashes($song['ccli'] ?? '');
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
    $lyricsText = addslashes(implode("\n", $lyricsLines));

    fwrite($out, "INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, SongbookName, Language, Copyright, Ccli, Verified, LyricsPublicDomain, MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText) VALUES ('{$songId}', {$number}, '{$title}', '{$songbookAbbr}', '{$songbookName}', '{$language}', '{$copyright}', '{$ccli}', {$verified}, {$lyricsPD}, {$musicPD}, {$hasAudio}, {$hasSheet}, '{$lyricsText}');\n");

    /* Writers */
    foreach ($song['writers'] ?? [] as $writer) {
        $w = addslashes($writer);
        fwrite($out, "INSERT INTO tblSongWriters (SongId, Name) VALUES ('{$songId}', '{$w}');\n");
        $writerCount++;
    }

    /* Composers */
    foreach ($song['composers'] ?? [] as $composer) {
        $c = addslashes($composer);
        fwrite($out, "INSERT INTO tblSongComposers (SongId, Name) VALUES ('{$songId}', '{$c}');\n");
        $composerCount++;
    }

    /* Components */
    $sortOrder = 0;
    foreach ($song['components'] ?? [] as $comp) {
        $type   = addslashes($comp['type']);
        $num    = (int)$comp['number'];
        $lines  = addslashes(json_encode($comp['lines'] ?? [], JSON_UNESCAPED_UNICODE));
        fwrite($out, "INSERT INTO tblSongComponents (SongId, Type, Number, SortOrder, LinesJson) VALUES ('{$songId}', '{$type}', {$num}, {$sortOrder}, '{$lines}');\n");
        $componentCount++;
        $sortOrder++;
    }

    if (($i + 1) % 500 === 0) {
        echo "  ... " . ($i + 1) . "/{$songCount}\n";
    }
}

fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
fclose($out);

$fileSize = round(filesize($outputFile) / 1024 / 1024, 2);

echo "\n--- Complete ---\n";
echo "Songs:      {$songCount}\n";
echo "Writers:    {$writerCount}\n";
echo "Composers:  {$composerCount}\n";
echo "Components: {$componentCount}\n";
echo "Output:     {$outputFile}\n";
echo "Size:       {$fileSize} MB\n";
