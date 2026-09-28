<?php

declare(strict_types=1);

/**
 * iHymns — a song's language defaults to "not known" (und), not English (#2132)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When a song was saved without a language, the database used to fill in
 * English (`tblSongs.Language ... DEFAULT 'en'`). That is a guess dressed up
 * as a fact: nobody can later tell it apart from a real English song, so a
 * Zulu hymn could sit under an English filter forever. This migration changes
 * ONLY the default, to `und` — BCP 47's own code for "undetermined" — which is
 * what the shared language policy requires (MWBM-MEDIA-LANG, rule LANG-003:
 * "a database column MAY allow no value instead of und, but MUST NOT default
 * to a real language").
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does NOT change any existing song. A song stored as `en` by the old
 * default looks exactly like a song a curator marked English on purpose, so
 * rewriting them would replace one guess with another (policy COMPAT-040:
 * "report doubt, do not resolve it by guessing"). Curators correct individual
 * songs as they find them. The application code already stopped writing `en`
 * for an unknown language in the same change as this migration; this only
 * brings the database's own fallback into line, so a future write that omits
 * the column cannot bring the guess back.
 *
 * HOW
 * ---
 * One `ALTER TABLE ... MODIFY COLUMN` restating the column exactly as
 * `appWeb/.sql/schema.sql` declares it (type, NOT NULL, DEFAULT and COMMENT,
 * byte-identical — rule #19), so a migrated install and a fresh one end up the
 * same. The type and width do not change, so MariaDB and MySQL change only the
 * table's description; no row is read or written.
 *
 * IDEMPOTENT: it first reads the column's current default from
 * INFORMATION_SCHEMA and does nothing when it is already `und`. MariaDB reports
 * a string default WITH quotes (`'en'`) and MySQL without (`en`); both are
 * handled.
 *
 * No shared include is required beyond the database connection, which is
 * loaded only when this script runs on its own (the setup dashboard already
 * has it), so rule #41's renamed-docroot trap does not apply.
 *
 * @migration-modifies tblSongs.Language
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-language-default-und.php
 *   Web:  /manage/setup-database → "Song language: default to not known (und)"
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/manage/includes/migration-registry.php  'song-language-default-und' entry
 * @see docs/standards/media-language-bcp47-policy.md  LANG-003, COMPAT-040
 * @see #2132 #2137
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

function _migSongLangUnd_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/**
 * The column's current default as a plain string (no quotes), or null when
 * the column does not exist. MariaDB 10.2.7+ returns a quoted literal
 * ('en'); MySQL returns it bare (en).
 * https://mariadb.com/kb/en/information-schema-columns-table/
 */
function _migSongLangUnd_default(\mysqli $db): ?string
{
    $stmt = $db->prepare(
        "SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'Language' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($row === null) {
        return null;
    }
    return trim((string)($row[0] ?? ''), "'");
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migSongLangUnd_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

/* mysqli under STRICT: a failing statement THROWS (CLAUDE.md red flags). */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migSongLangUnd_out('=== iHymns — song language defaults to und, not en (#2132) ===');

try {
    $current = _migSongLangUnd_default($db);
    if ($current === null) {
        _migSongLangUnd_out('  [SKIP] tblSongs.Language not found — run the earlier migrations first.');
        return;
    }
    if ($current === 'und') {
        _migSongLangUnd_out("  [SKIP] tblSongs.Language already defaults to 'und' — nothing to do.");
        _migSongLangUnd_out('Done (already applied).');
        return;
    }

    _migSongLangUnd_out("  Current default: '{$current}'. Changing it to 'und'. No song is changed.");
    $db->query(
        "ALTER TABLE tblSongs
    MODIFY COLUMN Language VARCHAR(35) NOT NULL DEFAULT 'und' COMMENT 'IETF BCP 47 tag in canonical form (language[-script][-region]…); und = not known. Never defaults to a real language: und replaced en as the default in #2132, and songs stored as en before then were left as they were (MWBM-MEDIA-LANG LANG-003, COMPAT-040). Widened from VARCHAR(10) to fit script + region subtags (#681)'"
    );

    $after = _migSongLangUnd_default($db);
    if ($after !== 'und') {
        _migSongLangUnd_out("  [ERROR] the default reads '{$after}' after the change — please investigate.");
        if ($isCli) { exit(1); }
        return;
    }
    _migSongLangUnd_out("  [OK] tblSongs.Language now defaults to 'und'.");
    _migSongLangUnd_out('Done.');
} catch (\Throwable $e) {
    _migSongLangUnd_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
