<?php

declare(strict_types=1);

/**
 * iHymns — let whole-song translations use regional and script languages (#2131)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A whole-song translation link ("song A is the Brazilian Portuguese version of
 * song B") could not be saved when its language carried a region or a script:
 * `pt-BR`, `zh-Hans`, `sr-Latn`. The reason was one database rule,
 * `fk_Trans_Lang`, which insisted that `tblSongTranslations.TargetLanguage` be
 * a row in `tblLanguages` — and that table holds only bare language codes
 * (`pt`, `zh`), one per language in the IANA registry. This migration removes
 * that one rule. Nothing else changes, and no row is touched.
 *
 * WHY THIS IS THE RIGHT FIX
 * -------------------------
 * - Every other language column in the schema (`tblSongs.Language`,
 *   `tblLyricLines.LanguageCode`, the per-line translation tables) is already
 *   free text with no such link, precisely so a regional or script tag can
 *   never make a save fail (rule #21). This was the one exception, and the
 *   comment beside it in schema.sql already called removing it "the change
 *   that would need discussing".
 * - The shared language policy settles that discussion (MWBM-MEDIA-LANG,
 *   TEXT-050: storage must keep `pt-BR` and `pt-PT` apart, and "a data model
 *   that can only hold bare languages is non-conforming"; COMPAT-050: a schema
 *   change is justified exactly when the model cannot represent what the
 *   policy requires).
 * - Widening `tblLanguages` to hold full tags instead was rejected: it would
 *   turn a registry of language CODES into a table of every combination
 *   anybody might use.
 * - The one-translation-per-language rule (`uq_Translation`) is KEPT.
 * - The application now checks a translation's language with the shared
 *   policy rule before saving it (manage/editor/save_song_core.php), so
 *   dropping the database rule does not let a malformed value in.
 *
 * WHAT IT DOES
 * ------------
 * 1. If `fk_Trans_Lang` exists: `ALTER TABLE tblSongTranslations DROP FOREIGN
 *    KEY fk_Trans_Lang`.
 * 2. If an index named `fk_Trans_Lang` is left behind, drop it too. When the
 *    table was created with that foreign key and no other index starting with
 *    `TargetLanguage`, MariaDB/MySQL created that index automatically for the
 *    key (https://mariadb.com/kb/en/foreign-keys/ — "an index is created
 *    automatically"), and dropping a foreign key leaves its index in place. A
 *    fresh install from schema.sql never has it, so removing it keeps a
 *    migrated database the same shape as a fresh one (rule #19). No query
 *    relies on it: every read of this table filters by `SourceSongId` or
 *    `TranslatedSongId`, which have their own indexes.
 *
 * IDEMPOTENT: each step first checks INFORMATION_SCHEMA and skips when there
 * is nothing to do, so running it twice does the same as running it once.
 * NOT DESTRUCTIVE: a constraint and an index are removed; no data is.
 *
 * No shared include is required beyond the database connection (loaded only
 * when run on its own), so rule #41's renamed-docroot trap does not apply.
 *
 * No `@migration-adds` / `@migration-drops` doc-tag applies: those name a
 * table or a table.column (includes/schema_audit.php reads them that way),
 * and this removes only a constraint and its index — no table or column.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-drop-song-translations-language-fk.php
 *   Web:  /manage/setup-database → "Translations: allow regional and script languages"
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/manage/includes/migration-registry.php  'drop-song-translations-language-fk' entry
 * @see appWeb/public_html/includes/song_translations_schema.php  songTranslationsLanguageFkPresent()
 * @see docs/standards/media-language-bcp47-policy.md  TEXT-050, COMPAT-050
 * @see #2131 #2137
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migDropTransLangFk_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Does the named foreign key exist on tblSongTranslations? */
function _migDropTransLangFk_fkExists(\mysqli $db): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongTranslations'
            AND CONSTRAINT_NAME = 'fk_Trans_Lang' AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1"
    );
    $stmt->execute();
    $hit = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $hit;
}

/** Does an index named fk_Trans_Lang exist on tblSongTranslations? */
function _migDropTransLangFk_indexExists(\mysqli $db): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongTranslations'
            AND INDEX_NAME = 'fk_Trans_Lang' LIMIT 1"
    );
    $stmt->execute();
    $hit = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $hit;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migDropTransLangFk_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migDropTransLangFk_out('=== iHymns — translations may use regional and script languages (#2131) ===');

try {
    $tableStmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongTranslations' LIMIT 1"
    );
    $tableStmt->execute();
    $hasTable = $tableStmt->get_result()->fetch_row() !== null;
    $tableStmt->close();
    if (!$hasTable) {
        _migDropTransLangFk_out('  [SKIP] tblSongTranslations does not exist yet — nothing to do.');
        return;
    }

    if (_migDropTransLangFk_fkExists($db)) {
        $db->query('ALTER TABLE tblSongTranslations DROP FOREIGN KEY fk_Trans_Lang');
        _migDropTransLangFk_out('  [OK] Removed the fk_Trans_Lang link to tblLanguages.');
    } else {
        _migDropTransLangFk_out('  [SKIP] fk_Trans_Lang is already gone.');
    }

    if (_migDropTransLangFk_indexExists($db)) {
        $db->query('ALTER TABLE tblSongTranslations DROP INDEX fk_Trans_Lang');
        _migDropTransLangFk_out('  [OK] Removed the index MariaDB/MySQL had created for that link.');
    } else {
        _migDropTransLangFk_out('  [SKIP] no leftover fk_Trans_Lang index.');
    }

    if (_migDropTransLangFk_fkExists($db) || _migDropTransLangFk_indexExists($db)) {
        _migDropTransLangFk_out('  [ERROR] the link or its index is still present — please investigate.');
        if ($isCli) { exit(1); }
        return;
    }
    _migDropTransLangFk_out('Done.');
} catch (\Throwable $e) {
    _migDropTransLangFk_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
