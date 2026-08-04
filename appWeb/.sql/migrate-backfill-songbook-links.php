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
 * NOT DESTRUCTIVE. This is a pure DATA migration — it creates no schema and
 * deletes nothing. The legacy columns are NOT dropped: they stay as
 * read-fallbacks for one release cycle. A separate later migration retires
 * them once the public site has been on the new system long enough. The
 * "undo", if ever needed, is to delete the copied rows from
 * tblSongbookExternalLinks; the originals are still sitting in tblSongbooks.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — read this before changing the SQL.
 * There is NO unique key on tblSongbookExternalLinks (check schema.sql: it has
 * only idx_book / idx_type). So there is nothing for ON DUPLICATE KEY or
 * INSERT IGNORE to fire on, and a naive re-run would duplicate every link.
 * What actually makes a re-run a no-op is the correlated `NOT EXISTS` subquery
 * in the INSERT…SELECT below, which suppresses any row whose exact
 * (SongbookId, LinkTypeId, Url) triple is already present. The registry probe
 * (migration-registry.php) is the mirror image of that same subquery, so the
 * card flips to applied exactly when the INSERT would insert nothing.
 *
 * ⚠ The limit of that guarantee: idempotent means "running twice is the same
 * as running once", NOT "running later is harmless". The guard keys on Url, so
 * if a curator EDITS or DELETES a migrated link and the migration is then
 * re-run, the legacy URL from tblSongbooks is inserted again — the curator's
 * change looks undone. That is a consequence of keeping the legacy columns as
 * fallbacks; it disappears when the retire-columns migration lands.
 *
 * Skipped silently if the new tables aren't present yet (the schema
 * migration migrate-external-links.php has to land first) — a fresh install
 * gets the tables from schema.sql, but on a long-running install migrations
 * are applied by hand from the dashboard and may be applied out of order.
 *
 * OPERATOR VIEW: /manage/setup-database → "Backfill Songbook URL columns →
 * External Links (#833)" → button "Run Songbook Links Backfill". Each mapping
 * reports either "[seed] … backfilled N links" or "[skip] … already on the new
 * system"; a fully-migrated install shows three [skip] lines and "Total
 * inserted: 0".
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

/* Resolve the three target link-type ids up front — one query for all three,
   because the per-mapping loop below needs the Id, and tblSongbookExternalLinks
   stores LinkTypeId, not the slug.

   Slugs are seeded by migrate-external-links.php, so all three should be here.
   A slug that is MISSING is handled gracefully: the loop below logs a [skip]
   for that mapping and moves on, and the registry probe joins on the same
   tblExternalLinkTypes row, so a missing type makes probe and migration agree
   (nothing to do) rather than leaving the card stuck pending.

   Note this deliberately does NOT filter on IsActive: a curator hiding a link
   type from the pickers must not silently stop the historical URLs on that type
   from being migrated across. Only actually DELETING the type row skips it. */
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

    /* ── The idempotency mechanism ──────────────────────────────────────────
       ELI5: "copy every songbook's legacy URL into the links table, but skip
       any I've already copied."

       Detail: a set-based INSERT…SELECT (one round trip per mapping, not one
       per songbook) whose correlated NOT EXISTS suppresses rows already
       present with the same (SongbookId, LinkTypeId, Url). This subquery IS
       the idempotency — the table carries no unique key, so INSERT IGNORE /
       ON DUPLICATE KEY would have nothing to fire on and would happily
       duplicate on every run.

       The {$col} interpolation is safe under CLAUDE.md rule #5: $col is a KEY
       of the hardcoded $mappings array above (a PHP literal), never request
       data, and it has just been checked against INFORMATION_SCHEMA. The two
       runtime VALUES are bound. Column identifiers cannot be bound as
       parameters in any case (https://www.php.net/manual/en/mysqli-stmt.bind-param.php).

       LENGTH(TRIM(...)) > 0 rather than <> '': a legacy column holding only
       whitespace is a non-link and must not become a row in the new table. */
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
