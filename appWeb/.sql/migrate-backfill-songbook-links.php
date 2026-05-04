<?php

declare(strict_types=1);

/**
 * iHymns — Backfill songbook URL columns into tblSongbookExternalLinks (#833)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Copies non-empty values from the legacy three URL columns on
 * tblSongbooks into the new external-links system:
 *
 *   tblSongbooks.WebsiteUrl         → link type 'official-website'
 *   tblSongbooks.InternetArchiveUrl → link type 'internet-archive'
 *   tblSongbooks.WikipediaUrl       → link type 'wikipedia'
 *
 * The legacy columns are NOT dropped — they stay as read-fallbacks for
 * one release cycle. A separate later migration retires them once the
 * public site has been on the new system long enough.
 *
 * Idempotent — uses INSERT…ON DUPLICATE KEY UPDATE keyed on
 * (SongbookId, LinkTypeId, Url) so re-runs upsert without duplicating.
 * Skipped silently if the new tables aren't present yet (the schema
 * migration migrate-external-links.php has to land first).
 *
 * @migration-modifies tblSongbookExternalLinks
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

function _migBfBookLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migBfBookLinks_tableExists(\mysqli $db, string $table): bool
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

function _migBfBookLinks_columnExists(\mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migBfBookLinks_out('Backfill songbook external links migration starting (#833)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (!_migBfBookLinks_tableExists($mysqli, 'tblSongbookExternalLinks')
    || !_migBfBookLinks_tableExists($mysqli, 'tblExternalLinkTypes')) {
    _migBfBookLinks_out('[skip] external-links schema not yet present — run migrate-external-links.php first.');
    return;
}

/* Resolve the three target link-type ids. Slugs are seeded by the
   schema migration, so they should exist; fall through gracefully
   if a curator deactivated one. */
$slugToId = [];
$res = $mysqli->query("SELECT Id, Slug FROM tblExternalLinkTypes
                        WHERE Slug IN ('official-website', 'internet-archive', 'wikipedia')");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $slugToId[(string)$row['Slug']] = (int)$row['Id'];
    }
    $res->close();
}

$mappings = [
    'WebsiteUrl'         => 'official-website',
    'InternetArchiveUrl' => 'internet-archive',
    'WikipediaUrl'       => 'wikipedia',
];

$inserted = 0;
$skipped  = 0;
foreach ($mappings as $col => $slug) {
    if (!_migBfBookLinks_columnExists($mysqli, 'tblSongbooks', $col)) {
        _migBfBookLinks_out("[skip] tblSongbooks.{$col} not present — nothing to backfill.");
        continue;
    }
    if (!isset($slugToId[$slug])) {
        _migBfBookLinks_out("[skip] link type '{$slug}' not in registry — nothing to backfill for {$col}.");
        continue;
    }
    $linkTypeId = $slugToId[$slug];

    /* SELECT the populated values, INSERT IGNORE into the new table.
       UNIQUE constraint on the new table is by (SongbookId,
       LinkTypeId, Url) — we don't have one, but INSERT IGNORE based
       on the primary key is enough; combined with the WHERE-NOT-EXISTS
       pattern below it's idempotent. Use plain INSERT…SELECT plus a
       NOT EXISTS guard to skip rows that already match. */
    $sql = "INSERT INTO tblSongbookExternalLinks
                (SongbookId, LinkTypeId, Url)
            SELECT b.Id, ?, b.{$col}
              FROM tblSongbooks b
             WHERE b.{$col} IS NOT NULL
               AND LENGTH(TRIM(b.{$col})) > 0
               AND NOT EXISTS (
                     SELECT 1 FROM tblSongbookExternalLinks x
                      WHERE x.SongbookId = b.Id
                        AND x.LinkTypeId = ?
                        AND x.Url        = b.{$col}
                   )";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $linkTypeId, $linkTypeId);
    if (!$stmt->execute()) {
        _migBfBookLinks_out("WARN: backfill of {$col} → {$slug} failed: " . $mysqli->error);
        $stmt->close();
        continue;
    }
    $rows = $stmt->affected_rows;
    $stmt->close();
    if ($rows > 0) {
        $inserted += $rows;
        _migBfBookLinks_out("[seed] {$col} → {$slug}: backfilled {$rows} link" . ($rows === 1 ? '' : 's') . '.');
    } else {
        $skipped++;
        _migBfBookLinks_out("[skip] {$col} → {$slug}: no rows to backfill (already on the new system).");
    }
}

_migBfBookLinks_out("Backfill complete. Total inserted: {$inserted}.");
_migBfBookLinks_out('The legacy columns (WebsiteUrl / InternetArchiveUrl / WikipediaUrl)');
_migBfBookLinks_out('are NOT dropped by this migration — they remain as read-fallbacks for');
_migBfBookLinks_out('one release cycle. A later migration retires them once the public site');
_migBfBookLinks_out('has been on the new external-links system.');
