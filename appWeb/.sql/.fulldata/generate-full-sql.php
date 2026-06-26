<?php

declare(strict_types=1);

/**
 * iHymns — Generate Full SQL Export (Schema + Song Data)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (DEPRECATED — see the #1235 P4/C6 note below):
 * Generated a single .sql file (schema + all song data from data/songs.json as INSERTs)
 * for instant database setup on first install.
 *
 * ⚠ DEPRECATED / DISABLED (#1235 P4/C6). This generator emits song lyrics ONLY as
 * `INSERT INTO tblSongComponents (... LinesJson ...)`. After the cutover, `tblLyricLines`
 * is the authoritative store and the JSON payload columns are RETIRED — so (a) the INSERT
 * names a dropped column (throws under STRICT against the post-C6 thin schema.sql it copies),
 * and (b) it never emits tblLyrics / tblLyricLines, so even a loadable dump would show no
 * lyrics. The committed ihymns-full.sql is also long-stale (predates dozens of tables incl.
 * tblLyricLines). Producing a correct full dump now means re-projecting lines with explicit
 * Ids — out of scope. The SUPPORTED first-install path is: load appWeb/.sql/schema.sql, then
 * /manage/setup-database → "Apply all pending migrations". This script now refuses to run.
 *
 * USAGE:
 *   (disabled) — see above.
 *
 * OUTPUT:
 *   appWeb/.sql/.fulldata/ihymns-full.sql
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    http_response_code(403);
    exit('CLI only.');
}

/* #1235 P4/C6 — refuse to regenerate a now-incompatible dump (see the deprecation note
   above). Producing a broken dump silently would be worse than this hard stop. */
fwrite(STDERR, "DEPRECATED (#1235 P4/C6): this full-SQL generator emits the retired tblSongComponents.LinesJson\n");
fwrite(STDERR, "column and no tblLyricLines, so a regenerated dump is incompatible with the post-cutover schema.\n");
fwrite(STDERR, "Supported first install: load appWeb/.sql/schema.sql, then /manage/setup-database -> Apply all pending.\n");
exit(1);

$projectRoot = dirname(__DIR__, 3);
$schemaFile  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'schema.sql';
$jsonFile    = $projectRoot . '/data/songs.json';
$outputFile  = __DIR__ . DIRECTORY_SEPARATOR . 'ihymns-full.sql';

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

    fwrite($out, "INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, Copyright, Ccli, Verified, LyricsPublicDomain, MusicPublicDomain, HasAudio, HasSheetMusic, LyricsText) VALUES ('{$songId}', {$number}, '{$title}', '{$songbookAbbr}', '{$language}', '{$copyright}', '{$ccli}', {$verified}, {$lyricsPD}, {$musicPD}, {$hasAudio}, {$hasSheet}, '{$lyricsText}');\n");

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
