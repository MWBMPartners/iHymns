<?php

declare(strict_types=1);

/**
 * iHymns — Credit People slug column migration (#588)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a `Slug` column to tblCreditPeople and backfills it from each
 * row's Name. The slug is the URL component for the new public
 * /people/<slug> page (#588): a lower-case, hyphen-separated form of
 * the name with non-letter/digit characters stripped.
 *
 * Schema:
 *   tblCreditPeople.Slug VARCHAR(255) NOT NULL DEFAULT ''
 *   UNIQUE KEY uk_Slug (Slug)
 *
 * @migration-adds tblCreditPeople.Slug
 *
 * Idempotent — re-running is safe; the column-exists check skips the
 * ALTER, the backfill only touches rows whose Slug is empty, and
 * collisions get a numeric suffix so the unique key holds.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-credit-people-slug.php
 */

if (PHP_SAPI === 'cli') {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
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
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    $isCli = false;
}

function _migCpSlug_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    @ob_flush();
    @flush();
}

function _migCpSlug_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Slugify a person's name for the URL component. Mirrors the same
 * function shipped in the public page renderer so a name like "Cecil
 * Frances Humphreys Alexander" becomes "cecil-frances-humphreys-alexander".
 * Non-letter/digit characters become hyphens; consecutive hyphens
 * collapse; leading/trailing hyphens are trimmed.
 */
function _migCpSlug_slugify(string $name): string
{
    $name = mb_strtolower(trim($name));
    /* Strip Unicode combining marks via Normalizer if available. */
    if (class_exists('Normalizer')) {
        $name = Normalizer::normalize($name, Normalizer::FORM_KD);
        $name = preg_replace('/\p{M}+/u', '', $name);
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name);
    return trim($slug, '-');
}

_migCpSlug_out('Credit People slug migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migCpSlug_out('ERROR: could not connect to database.');
    exit(1);
}

/* Step 1: parent table must exist. */
$stmt = $mysqli->prepare(
    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
);
$tableName = 'tblCreditPeople';
$stmt->bind_param('s', $tableName);
$stmt->execute();
$baseExists = $stmt->get_result()->fetch_row() !== null;
$stmt->close();
if (!$baseExists) {
    _migCpSlug_out('ERROR: tblCreditPeople not found. Run migrate-credit-people first.');
    exit(1);
}

/* Step 2: add Slug column. */
if (_migCpSlug_columnExists($mysqli, 'tblCreditPeople', 'Slug')) {
    _migCpSlug_out('[skip] tblCreditPeople.Slug already present.');
} else {
    $sql = "ALTER TABLE tblCreditPeople
            ADD COLUMN Slug VARCHAR(255) NOT NULL DEFAULT ''
            AFTER Name";
    if (!$mysqli->query($sql)) {
        _migCpSlug_out('ERROR: adding Slug failed: ' . $mysqli->error);
        exit(1);
    }
    _migCpSlug_out('[add ] tblCreditPeople.Slug.');
}

/* Step 3: backfill. Iterate every row whose Slug is empty, compute
   a slug from Name, append -2 / -3 etc. on collision so the column
   can later carry a UNIQUE constraint. */
$res = $mysqli->query("SELECT Id, Name FROM tblCreditPeople WHERE Slug = '' OR Slug IS NULL");
$toUpdate = [];
while ($row = $res->fetch_assoc()) {
    $toUpdate[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
}
$res->close();

if (empty($toUpdate)) {
    _migCpSlug_out('[seed] no rows needed slug backfill.');
} else {
    /* Pre-load existing slugs so we can avoid collisions. */
    $taken = [];
    $res2 = $mysqli->query("SELECT Slug FROM tblCreditPeople WHERE Slug <> ''");
    while ($row2 = $res2->fetch_assoc()) {
        $taken[(string)$row2['Slug']] = true;
    }
    $res2->close();

    $update = $mysqli->prepare('UPDATE tblCreditPeople SET Slug = ? WHERE Id = ?');
    $filled = 0;
    foreach ($toUpdate as $entry) {
        $base = _migCpSlug_slugify($entry['name']);
        if ($base === '') $base = 'person';
        $candidate = $base;
        $suffix = 1;
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
    _migCpSlug_out("[seed] backfilled slug on {$filled} row" . ($filled === 1 ? '' : 's') . '.');
}

/* Step 4: enforce UNIQUE on Slug. Done after the backfill so we don't
   trip over duplicate empty strings. */
$idxRes = $mysqli->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblCreditPeople'
        AND INDEX_NAME   = 'uk_Slug' LIMIT 1"
);
$idxExists = $idxRes && $idxRes->fetch_row() !== null;
if ($idxRes) $idxRes->close();
if ($idxExists) {
    _migCpSlug_out('[skip] uk_Slug index already present.');
} else {
    if (!$mysqli->query('ALTER TABLE tblCreditPeople ADD UNIQUE KEY uk_Slug (Slug)')) {
        _migCpSlug_out('WARN: could not add uk_Slug unique key (likely a duplicate slipped through): ' . $mysqli->error);
    } else {
        _migCpSlug_out('[add ] tblCreditPeople uk_Slug.');
    }
}

_migCpSlug_out('Credit People slug migration finished.');
$mysqli->close();
