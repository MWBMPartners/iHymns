<?php

declare(strict_types=1);

/**
 * iHymns — Tag display-form normalisation (#762)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * tblSongTags.Name is utf8mb4_unicode_ci with a UNIQUE index, so the
 * database already prevents "Worship" + "worship" co-existing as two
 * rows. But the row's stored Name is whatever the FIRST caller wrote
 * — and that's what every subsequent reader sees in dropdowns and
 * chips. Catalogues whose first writer typed lowercase end up with
 * lowercase tags forever (until #762's bulk_tag normalisation
 * canonicalises them on the next upsert).
 *
 * This one-shot migration walks tblSongTags and rewrites Name to
 * Title Case for any row that doesn't already match. Idempotent:
 * skip rows whose Name is already canonical.
 *
 * Title Case is computed via mb_convert_case(MB_CASE_TITLE_SIMPLE)
 * — same rule the bulk_tag normaliser applies. Whitespace runs are
 * collapsed to a single space first so a row stored as "Holy   Week"
 * lands as "Holy Week".
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-tag-titlecase.php
 *   Web: /manage/setup-database → "Tag Title-Case Backfill"
 *        (entry point requires global_admin)
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

function _migTagTitle_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migTagTitle_tableExists(mysqli $db, string $table): bool
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

_migTagTitle_out('Tag Title-Case backfill starting…');

$db = getDbMysqli();
if (!$db) {
    _migTagTitle_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migTagTitle_tableExists($db, 'tblSongTags')) {
    _migTagTitle_out('[skip] tblSongTags not found — nothing to normalise.');
    _migTagTitle_out('Tag Title-Case backfill finished.');
    exit(0);
}

/* Walk every row, compute canonical form, UPDATE only when different.
   ~thousands of rows max — single-pass is fine. */
$res = $db->query('SELECT Id, Name FROM tblSongTags ORDER BY Id ASC');
if (!$res) {
    _migTagTitle_out('ERROR: SELECT failed: ' . $db->error);
    exit(1);
}

$update = $db->prepare('UPDATE tblSongTags SET Name = ? WHERE Id = ?');
$updated = 0;
$skipped = 0;
$collisions = 0;

while ($row = $res->fetch_assoc()) {
    $id   = (int)$row['Id'];
    $name = (string)$row['Name'];
    /* Same normalisation as the bulk_tag handler: trim, collapse
       whitespace, Title Case. */
    $clean = trim($name);
    $clean = preg_replace('/\s+/u', ' ', (string)$clean);
    $titled = mb_convert_case((string)$clean, MB_CASE_TITLE_SIMPLE, 'UTF-8');
    $titled = mb_substr($titled, 0, 50);

    if ($titled === $name) {
        $skipped++;
        continue;
    }

    /* Catch the rare case where two rows would collide on the
       canonical form (e.g. "Worship" + " worship "). The unique-
       index would reject the second UPDATE; we log it and leave the
       data alone for the curator to resolve via the (forthcoming)
       /manage/tags merge UI. */
    try {
        $update->bind_param('si', $titled, $id);
        $update->execute();
        if ($db->affected_rows > 0) {
            $updated++;
        } else {
            $skipped++;
        }
    } catch (\Throwable $e) {
        $collisions++;
        _migTagTitle_out("[warn] Id={$id} '{$name}' → '{$titled}' collides with an existing row — left as-is. " .
                         'Resolve via /manage/tags merge once that ships.');
    }
}
$res->close();
$update->close();

_migTagTitle_out("[ok  ] {$updated} row(s) renamed to Title Case.");
_migTagTitle_out("[note] {$skipped} row(s) already canonical.");
if ($collisions > 0) {
    _migTagTitle_out("[note] {$collisions} row(s) collide with another row's canonical form — left untouched (resolve via merge UI).");
}

_migTagTitle_out('Tag Title-Case backfill finished.');
