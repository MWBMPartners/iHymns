<?php

declare(strict_types=1);

/**
 * iHymns — Song PublicId ("IHUID") helpers (#1343-B)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * An opaque, location-INDEPENDENT permalink id for a song. The SongId
 * (<Abbreviation>-<number>) is the PRIMARY KEY and stays the internal id (rule
 * #27), but it is coupled to songbook + number, so a shared /song/<SongId> link
 * breaks if the song later moves songbook or is renumbered. tblSongs.PublicId is
 * the stable, canonical permalink that survives those changes.
 *
 *  - Crockford-ish base32, length 10, UPPERCASE — charset mirrors
 *    SERVICE_MODE_CODE_ALPHABET (no I/L/O/U/0/1: read-aloud + transcription safe).
 *  - 30^10 ≈ 5.9e14 space → negligible collision risk over ~16k rows; minting
 *    retries against the UNIQUE.
 *  - UPPERCASE + NO HYPHEN: it survives SongData::getSongById()'s strtoupper fold
 *    and can never be confused with the hyphenated <letters>-<digits> SongId
 *    (the hyphen's presence/absence is the structural disambiguator).
 *
 * Every read is gated on the column existing (songPublicId_columnReady) so the
 * app works whether or not the migration has run (the #1228 STRICT-mode lesson;
 * the 3 docroots share one MySQL and migrations are NOT auto-applied).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/** Same glyph set as SERVICE_MODE_CODE_ALPHABET (service_mode.php) — kept local to
 *  avoid pulling the service-mode stack into the song read path. */
const SONG_PUBLIC_ID_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
const SONG_PUBLIC_ID_LENGTH   = 10;

/** Is tblSongs.PublicId migrated on THIS env? Probed once per request (static). */
function songPublicId_columnReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
                AND COLUMN_NAME = 'PublicId' LIMIT 1"
        );
        $stmt->execute();
        $ready = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $e) { $ready = false; }
    return $ready;
}

/** Generate one candidate PublicId (CSPRNG; not yet checked for uniqueness). */
function songPublicId_generate(int $len = SONG_PUBLIC_ID_LENGTH): string
{
    $a = SONG_PUBLIC_ID_ALPHABET;
    $max = strlen($a) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) { $out .= $a[random_int(0, $max)]; }
    return $out;
}

/**
 * Mint a fresh, GLOBALLY-UNIQUE PublicId (retries against the live UNIQUE).
 * Caller must have verified songPublicId_columnReady($db). Throws after a bounded
 * number of collisions rather than risk inserting a duplicate.
 */
function songPublicId_mintUnique(\mysqli $db, int $maxTries = 12): string
{
    /* @deleted-visible: uniqueness probe (#1694) — a soft-deleted song's
       PublicId stays RESERVED (the row exists); filtering would let the mint
       hand out a colliding permalink id. */
    $stmt = $db->prepare('SELECT 1 FROM tblSongs WHERE PublicId = ? LIMIT 1');
    for ($i = 0; $i < $maxTries; $i++) {
        $cand = songPublicId_generate();
        $stmt->bind_param('s', $cand);
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_row() !== null;
        if (!$taken) { $stmt->close(); return $cand; }
    }
    $stmt->close();
    throw new \RuntimeException('Could not allocate a unique song PublicId after ' . $maxTries . ' attempts.');
}

/** True if $id has the opaque PublicId shape (uppercase base32, no hyphen). */
function songPublicId_looksLikePublicId(string $id): bool
{
    return $id !== '' && preg_match('/^[0-9A-Z]{6,32}$/', $id) === 1 && strpos($id, '-') === false;
}

/**
 * Resolve a possible PublicId to its underlying SongId (the stable PK), for the
 * write/validator boundaries (favourites, history, related_songs, …) that key on
 * SongId. Returns the SongId if $id is a known PublicId; otherwise returns $id
 * UNCHANGED (so a real SongId, a padding alias, or an unknown id passes straight
 * through to the existing SongId-keyed logic). Gated + bound.
 */
function songPublicId_resolveToSongId(\mysqli $db, string $id): string
{
    $id = trim($id);
    if ($id === '' || !songPublicId_looksLikePublicId($id) || !songPublicId_columnReady($db)) {
        return $id;
    }
    /* @deleted-visible: pure RESOLVER (#1694) — a deleted song's PublicId
       must still resolve to its SongId, or songSoftDeletedHolds() could never
       recognise a PublicId-shaped request and the 410 would decay to 404.
       Visibility is applied by the FOLLOW-UP read, never the resolver. */
    $stmt = $db->prepare('SELECT SongId FROM tblSongs WHERE PublicId = ? LIMIT 1');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null ? (string)$row['SongId'] : $id;
}
