<?php

declare(strict_types=1);

/**
 * iHymns — User preferred-languages column migration (#736)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds an optional PreferredLanguagesJson TEXT NULL column to
 * tblUsers so a signed-in user can save their language-filter
 * choice to their account and have it sync across devices via the
 * existing user_preferences flow.
 *
 * Column shape: a JSON array of IETF BCP 47 PRIMARY subtags
 * (lowercase 2–3 letters). e.g.:
 *   ["en"]              — show only English-tagged + untagged content
 *   ["en", "es"]        — show English + Spanish + untagged
 *   ["en", "es", "pt"]  — show English + Spanish + Portuguese + untagged
 *   NULL or "[]"        — no filter; show every language
 *
 * Why JSON-array: the old user_preferences key/value table could
 * have stored the same data as a comma-list, but a typed JSON
 * array is more explicit AND we can extend it later (e.g. add
 * { "show_only": [...], "hide": [...] }) without a column rename.
 *
 * Why "primary subtag only": per spec the filter operates on the
 * first component of the BCP 47 tag (`en` matches `en`, `en-GB`,
 * `en-US`). Storing only primary subtags here keeps the saved
 * data canonical and the comparison cheap.
 *
 * @migration-adds tblUsers.PreferredLanguagesJson
 *
 * Idempotent — re-running is safe.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-user-preferred-languages.php
 *   Web: /manage/setup-database → "User Preferred Languages Migration"
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

function _migUserPrefLang_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migUserPrefLang_columnExists(mysqli $db, string $table, string $column): bool
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

function _migUserPrefLang_tableExists(mysqli $db, string $table): bool
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

_migUserPrefLang_out('User PreferredLanguagesJson column migration starting…');

$db = getDbMysqli();
if (!$db) {
    _migUserPrefLang_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migUserPrefLang_tableExists($db, 'tblUsers')) {
    _migUserPrefLang_out('ERROR: tblUsers not found. Run install.php first.');
    exit(1);
}

if (_migUserPrefLang_columnExists($db, 'tblUsers', 'PreferredLanguagesJson')) {
    _migUserPrefLang_out('[skip] tblUsers.PreferredLanguagesJson already present.');
} else {
    $sql = "ALTER TABLE tblUsers
            ADD COLUMN PreferredLanguagesJson TEXT NULL DEFAULT NULL
            COMMENT 'JSON array of IETF BCP 47 primary subtags the user wants to see (e.g. [\"en\",\"es\"]). NULL or [] = all languages.'";
    if (!$db->query($sql)) {
        _migUserPrefLang_out('ERROR: adding PreferredLanguagesJson failed: ' . $db->error);
        exit(1);
    }
    _migUserPrefLang_out('[add ] tblUsers.PreferredLanguagesJson.');
}

_migUserPrefLang_out('User PreferredLanguagesJson column migration finished.');
