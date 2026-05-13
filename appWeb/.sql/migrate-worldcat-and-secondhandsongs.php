<?php

declare(strict_types=1);

/**
 * iHymns — WorldCat Widening + SecondHandSongs Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 *
 *   (a) Widens the existing `oclc-worldcat` link type's AppliesTo from
 *       'songbook' alone to 'song,songbook,person,work' so a curator can
 *       attach a WorldCat / WorldCat Identities URL to a credit-person
 *       (very common — every published author has one) or a song / work
 *       (rare but useful for printed-music holdings). Also flips
 *       AllowMultiple from 0 to 1 to match the other authority links.
 *
 *   (b) Adds SecondHandSongs (secondhandsongs.com) — the canonical
 *       database of song originals / cover versions / releases. Crops up
 *       repeatedly on MusicBrainz artist pages and is the cleanest way
 *       to link a credit-person to their performance catalogue alongside
 *       Discogs / MusicBrainz / AllMusic.
 *
 * The seed lists in `migrate-external-links.php` +
 * `migrate-external-link-patterns.php` already cover these on a fresh
 * install; this supplementary migration brings already-deployed installs
 * in line via the dashboard.
 *
 * Idempotent: link types upsert by Slug (so re-running refreshes the
 * widened AppliesTo / AllowMultiple values), patterns guard on
 * (LinkTypeId, Host, PathPrefix) before inserting.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-worldcat-and-secondhandsongs.php
 *   Web: /manage/setup-database → "WorldCat + SecondHandSongs Links"
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migWcShs_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migWcShs_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migWcShs_out('WorldCat + SecondHandSongs migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

foreach (['tblExternalLinkTypes', 'tblExternalLinkPatterns'] as $required) {
    if (!_migWcShs_tableExists($mysqli, $required)) {
        _migWcShs_out("ERROR: {$required} not found. Run migrate-external-links.php"
            . ' (#833) and migrate-external-link-patterns.php (#845) first.');
        return;
    }
}

/* ----------------------------------------------------------------------
 * Step 1 — Upsert / widen link types
 *
 * Reusing the standard upsert keyed on Slug so the existing
 * oclc-worldcat row's AppliesTo + AllowMultiple get refreshed without
 * disturbing curator-controlled IsActive.
 * ---------------------------------------------------------------------- */
$seedTypes = [
    /* slug, name, category, applies_to, allow_multiple, icon, order */
    ['oclc-worldcat',   'WorldCat / OCLC', 'authority',   'song,songbook,person,work', 1, 'bi-card-list',        40],
    ['secondhandsongs', 'SecondHandSongs', 'information', 'song,songbook,person,work', 1, 'bi-music-note-list',  93],
];

$upsert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkTypes
         (Slug, Name, Category, AppliesTo, AllowMultiple, IconClass, DisplayOrder)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         Name          = VALUES(Name),
         Category      = VALUES(Category),
         AppliesTo     = VALUES(AppliesTo),
         AllowMultiple = VALUES(AllowMultiple),
         IconClass     = VALUES(IconClass),
         DisplayOrder  = VALUES(DisplayOrder)'
);
$typesAdded   = 0;
$typesUpdated = 0;
foreach ($seedTypes as $t) {
    [$slug, $name, $cat, $appliesTo, $multi, $icon, $order] = $t;
    $upsert->bind_param('ssssisi', $slug, $name, $cat, $appliesTo, $multi, $icon, $order);
    @$upsert->execute();
    $ar = $mysqli->affected_rows;
    if ($ar === 1)      $typesAdded++;
    elseif ($ar === 2)  $typesUpdated++;
}
$upsert->close();
_migWcShs_out("[seed] {$typesAdded} link type" . ($typesAdded === 1 ? '' : 's')
    . " inserted, {$typesUpdated} updated.");

/* ----------------------------------------------------------------------
 * Step 2 — Seed URL pattern for SecondHandSongs
 *
 * (WorldCat already has its pattern on worldcat.org seeded by #845;
 * widening the type doesn't need a pattern change.)
 * ---------------------------------------------------------------------- */
$seedRows = [
    ['secondhandsongs', 'secondhandsongs.com', null, 1, 38, 'Database of cover versions, originals, releases'],
];

$slugToId = [];
$res = $mysqli->query('SELECT Id, Slug FROM tblExternalLinkTypes');
while ($row = $res->fetch_assoc()) {
    $slugToId[(string)$row['Slug']] = (int)$row['Id'];
}
$res->close();

$insert = $mysqli->prepare(
    'INSERT INTO tblExternalLinkPatterns
         (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, Note)
     SELECT ?, ?, ?, ?, ?, ?
       FROM DUAL
      WHERE NOT EXISTS (
            SELECT 1 FROM tblExternalLinkPatterns
             WHERE LinkTypeId = ?
               AND Host       = ?
               AND COALESCE(PathPrefix, "") = COALESCE(?, "")
      )'
);

$pInserted = 0;
$pSkipped  = 0;
$pMissing  = 0;
foreach ($seedRows as $r) {
    $slug    = (string)$r[0];
    $host    = (string)$r[1];
    $path    = $r[2] !== null ? (string)$r[2] : null;
    $matchSd = (int)$r[3];
    $prio    = (int)$r[4];
    $note    = isset($r[5]) ? (string)$r[5] : null;

    if (!isset($slugToId[$slug])) {
        $pMissing++;
        continue;
    }
    $typeId = $slugToId[$slug];

    $insert->bind_param(
        'issiisiss',
        $typeId, $host, $path, $matchSd, $prio, $note,
        $typeId, $host, $path
    );
    $insert->execute();
    if ($mysqli->affected_rows > 0) $pInserted++;
    else                            $pSkipped++;
}
$insert->close();

_migWcShs_out("[seed] {$pInserted} pattern" . ($pInserted === 1 ? '' : 's')
    . " inserted, {$pSkipped} already present"
    . ($pMissing > 0 ? ", {$pMissing} skipped (link type missing)" : '')
    . '.');

_migWcShs_out('WorldCat + SecondHandSongs migration finished.');
