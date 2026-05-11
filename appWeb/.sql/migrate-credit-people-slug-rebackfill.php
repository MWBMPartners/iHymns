<?php

declare(strict_types=1);

/**
 * iHymns — Credit People slug RE-backfill (audit follow-up)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The original migrate-credit-people-slug.php backfilled Slug at the
 * time it ran, but several INSERT INTO tblCreditPeople call sites
 * (Add Person, Edit, the editor save_song registry upsert, etc.)
 * have continued to omit Slug. Combined with the column's
 * `NOT NULL DEFAULT ''` declaration that means any new row was
 * inserted with Slug=''. The UNIQUE constraint on uk_Slug allows
 * at most ONE such row, after which every subsequent INSERT trips
 * `Duplicate entry '' for key 'tblCreditPeople.uk_Slug'`.
 *
 * This migration is the data-fix for that drift: it finds every row
 * whose Slug is '' or NULL and assigns a collision-safe slug computed
 * from Name. The application-side fix that keeps every NEW insert
 * carrying a slug ships alongside this migration.
 *
 * @migration-rebackfills tblCreditPeople.Slug
 *
 * Idempotent — re-running is safe; only rows whose Slug is currently
 * empty get touched.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-credit-people-slug-rebackfill.php
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('getDbMysqli')) {
            require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
        }
    }
    $isCli = false;
}

function _migCpSlugRebackfill_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . "\n";
    } else {
        echo htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    }
}

function _migCpSlugRebackfill_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ok;
}

function _migCpSlugRebackfill_slugify(string $name): string
{
    $s = mb_strtolower(trim($name));
    if (class_exists('Normalizer')) {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $s = preg_replace('/\p{M}+/u', '', $s) ?? '';
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    return trim($slug, '-');
}

_migCpSlugRebackfill_out('Credit People slug RE-backfill starting…');

$db = getDbMysqli();
if (!$db) {
    _migCpSlugRebackfill_out('ERROR: could not connect to database.');
    if ($isCli) exit(1); else return;
}

/* Bail early if the Slug column was never added — earlier migration
   not yet applied. The original migrate-credit-people-slug.php is the
   right tool for that scenario; this one is purely a re-backfill. */
if (!_migCpSlugRebackfill_columnExists($db, 'tblCreditPeople', 'Slug')) {
    _migCpSlugRebackfill_out('[skip] tblCreditPeople.Slug column missing — run migrate-credit-people-slug.php first.');
    if ($isCli) exit(0); else return;
}

/* Step 1: load every row that needs a slug. */
$res = $db->query("SELECT Id, Name FROM tblCreditPeople WHERE Slug = '' OR Slug IS NULL");
$toUpdate = [];
while ($row = $res->fetch_assoc()) {
    $toUpdate[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
}
$res->close();

if (empty($toUpdate)) {
    _migCpSlugRebackfill_out('[ok  ] no rows needed re-backfill — every row already has a slug.');
    return;
}

_migCpSlugRebackfill_out('[scan] ' . count($toUpdate) . ' row' . (count($toUpdate) === 1 ? '' : 's') . ' need a slug.');

/* Step 2: pre-load existing non-empty slugs so we can resolve
   collisions without round-tripping per row. */
$taken = [];
$res2  = $db->query("SELECT Slug FROM tblCreditPeople WHERE Slug <> '' AND Slug IS NOT NULL");
while ($row2 = $res2->fetch_assoc()) {
    $taken[(string)$row2['Slug']] = true;
}
$res2->close();

/* Step 3: assign + UPDATE. */
$update = $db->prepare('UPDATE tblCreditPeople SET Slug = ? WHERE Id = ?');
$filled = 0;
foreach ($toUpdate as $entry) {
    $base = _migCpSlugRebackfill_slugify($entry['name']);
    if ($base === '') $base = 'person';
    $candidate = $base;
    $suffix    = 1;
    while (isset($taken[$candidate])) {
        $suffix++;
        $candidate = $base . '-' . $suffix;
    }
    $taken[$candidate] = true;
    $update->bind_param('si', $candidate, $entry['id']);
    $update->execute();
    $filled++;
}
$update->close();
_migCpSlugRebackfill_out("[fill] re-backfilled slug on {$filled} row" . ($filled === 1 ? '' : 's') . '.');
_migCpSlugRebackfill_out('Credit People slug RE-backfill complete.');
