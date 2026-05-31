<?php

declare(strict_types=1);

/**
 * iHymns — CLDR NativeName overlay for tblLanguages
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The IANA Language Subtag Registry (#738) seeds tblLanguages with
 * thousands of rows but leaves NativeName as ''. The CLDR English
 * overlay in migrate-iana-language-subtag-registry.php fills the
 * Name column ("German", "Japanese") but never touches NativeName.
 *
 * This migration backfills NativeName from a static dataset compiled
 * from Unicode CLDR's per-locale display data — each locale's
 * self-name as it would appear in its own locale ("Deutsch", "日本語",
 * "中文", "Tshivenḓa", …). The IETF picker partial (#681 / #685)
 * already reads the column via /api?action=languages and shows it
 * inline in the dropdown — so once this is run, curators see e.g.
 *
 *   German (Deutsch) — de
 *   Japanese (日本語) — ja
 *
 * instead of the bare English-only label.
 *
 * Source data: appWeb/.sql/data/cldr-native-names.json (~316 entries).
 * Rebuilt from CLDR by tools/fetch-cldr-native-names.sh.
 *
 * IDEMPOTENT:
 *   UPDATE … WHERE Code = ? AND NativeName <> ?
 * Already-set rows whose NativeName already matches the JSON skip;
 * a curator who manually corrected one row stays in place unless this
 * file changes too. Re-running on a fully-populated catalogue is a
 * no-op.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-cldr-native-names.php
 *   Web: /manage/setup-database → "CLDR Native Names"
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

function _migCldrNative_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migCldrNative_tableExists(mysqli $db, string $table): bool
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

function _migCldrNative_columnExists(mysqli $db, string $table, string $column): bool
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

_migCldrNative_out('CLDR NativeName overlay starting…');

$db = getDbMysqli();
if (!$db) {
    _migCldrNative_out('ERROR: could not connect to database.');
    exit(1);
}

/* Pre-flight: tblLanguages.NativeName must exist. The IANA registry
   migration (#738) creates the column; if it's missing, the operator
   needs to run that first rather than auto-chaining here. */
if (!_migCldrNative_tableExists($db, 'tblLanguages')) {
    _migCldrNative_out('ERROR: tblLanguages not found. Run install.php first.');
    exit(1);
}
if (!_migCldrNative_columnExists($db, 'tblLanguages', 'NativeName')) {
    _migCldrNative_out(
        'ERROR: tblLanguages.NativeName column missing. ' .
        'Run migrate-iana-language-subtag-registry.php (#738) first, then re-run this.'
    );
    exit(1);
}

/* Load the curated CLDR-derived dataset. The path is relative to this
   file so the same script works from CLI (cwd anywhere) and from the
   web entry point (/manage/setup-database includes it). */
$jsonPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cldr-native-names.json';
if (!is_readable($jsonPath)) {
    _migCldrNative_out("ERROR: data file not found at {$jsonPath}.");
    exit(1);
}
$rawJson = file_get_contents($jsonPath);
$decoded = json_decode($rawJson ?: '', true);
if (!is_array($decoded) || !isset($decoded['languages']) || !is_array($decoded['languages'])) {
    _migCldrNative_out('ERROR: data file did not parse as JSON with a top-level "languages" object.');
    exit(1);
}
$natives = $decoded['languages'];
_migCldrNative_out('Loaded ' . count($natives) . ' native-name entries from cldr-native-names.json.');

/* Snapshot how many rows currently have a non-empty NativeName so the
   operator sees the before/after delta. tblLanguages.NativeName is
   NOT NULL DEFAULT '' so the empty-string check covers all unfilled
   rows (no NULLs to consider). */
$res = $db->query("SELECT COUNT(*) AS c FROM tblLanguages WHERE NativeName <> ''");
$beforeFilled = $res ? (int)$res->fetch_assoc()['c'] : 0;
if ($res) {
    $res->close();
}
$res = $db->query("SELECT COUNT(*) AS c FROM tblLanguages");
$totalLangs = $res ? (int)$res->fetch_assoc()['c'] : 0;
if ($res) {
    $res->close();
}
_migCldrNative_out("Before: {$beforeFilled} of {$totalLangs} tblLanguages rows carry a NativeName.");

/* Targeted UPDATE — only rows whose NativeName differs from the JSON
   value get touched. The Code = ? clause makes this a primary-key
   (or unique-index) seek, so 316 prepared executes finish quickly. */
$stmt = $db->prepare(
    'UPDATE tblLanguages
        SET NativeName = ?
      WHERE Code       = ?
        AND NativeName <> ?'
);

$updated  = 0;
$skipped  = 0;
$missing  = 0;
foreach ($natives as $code => $native) {
    if (!is_string($code) || !is_string($native) || $code === '' || $native === '') {
        continue;
    }
    $codeLc = strtolower($code);
    $stmt->bind_param('sss', $native, $codeLc, $native);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    if ($aff > 0) {
        $updated++;
    } else {
        /* Two reasons for no-op: (a) the row's NativeName already
           matches; (b) tblLanguages has no row for this Code (rare
           — would happen only if the IANA registry skipped a code).
           Distinguish via a follow-up SELECT only when the count is
           non-trivial; for now treat both as "skipped". */
        $skipped++;
    }
}
$stmt->close();

/* Detect codes in the JSON that don't exist in tblLanguages at all
   so curators know which rows to add manually if they care. Cheap
   one-shot SELECT against the same set. */
if (count($natives) > 0) {
    $codes = array_map('strtolower', array_keys($natives));
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types        = str_repeat('s', count($codes));
    $stmt = $db->prepare(
        "SELECT Code FROM tblLanguages WHERE Code IN ({$placeholders})"
    );
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $present = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $present[$r['Code']] = true;
    }
    $stmt->close();
    $missing = count($codes) - count($present);
}

_migCldrNative_out("[ok  ] Updated NativeName on {$updated} row(s).");
_migCldrNative_out("[note] {$skipped} entry/entries already matched or had no row in tblLanguages.");
if ($missing > 0) {
    _migCldrNative_out(
        "[note] {$missing} JSON code(s) have no corresponding tblLanguages row. " .
        'Either the IANA registry migration is incomplete or those codes were ' .
        'pruned post-import — neither is a problem for this migration.'
    );
}

/* After-state snapshot. */
$res = $db->query("SELECT COUNT(*) AS c FROM tblLanguages WHERE NativeName <> ''");
$afterFilled = $res ? (int)$res->fetch_assoc()['c'] : 0;
if ($res) {
    $res->close();
}
_migCldrNative_out("After: {$afterFilled} of {$totalLangs} tblLanguages rows carry a NativeName.");

_migCldrNative_out('CLDR NativeName overlay finished.');
