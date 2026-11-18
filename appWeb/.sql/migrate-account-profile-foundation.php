<?php

declare(strict_types=1);

/**
 * iHymns - Account and profile foundation (account/profile rewrite, epic #2180, step B1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One migration that adds every new place the account/profile rewrite will
 * need, all at once, so nobody has to dribble out a second and third migration
 * as the feature is tweaked (rule #20). Everything here is EMPTY and DORMANT:
 * nothing reads or writes any of it until later steps of the rewrite use it, so
 * running this on alpha, beta or production changes nothing anyone can see.
 *
 * WHAT IT ADDS
 * ------------
 *   Tables (three, all linked to tblUsers with ON DELETE CASCADE, so account
 *   erasure removes their rows automatically):
 *     - tblUserProfileItems    one row per small per-person item, with a
 *                              per-person change counter and a delete marker
 *     - tblUserSyncTombstones  delete markers for favourites and custom tags
 *     - tblAccountEnrolKeys    keys a native app can swap ONLY for a device
 *                              sign-in (never a normal sign-in token)
 *   Columns (all NULL-able or defaulted, so existing rows and existing code are
 *   unaffected):
 *     - tblApiTokens.DeviceInstallId / IssuedVia, plus index idx_UserInstall
 *     - tblApnsTokens.ApiTokenHash, plus index idx_ApiToken
 *     - tblPushSubscriptions.Channel
 *     - tblSongHistory.Source / Channel / Platform
 *
 * Every growable vocabulary is VARCHAR, never ENUM (rule #20).
 *
 * ORDER, AND WHY tblSongHistory IS LAST
 * -------------------------------------
 * tblSongHistory holds one row per view, kept forever, so its size is unknown.
 * On MySQL 8.0.12 or later (and recent MariaDB) adding a column to the end of a
 * table is near-instant. On older versions the server copies the whole table,
 * which can take a long time on a big one. So the small objects are done first;
 * the history columns come last, one column per statement. If a run is
 * interrupted (a time-out, a restart) every object is created only if it is
 * missing, so running the card again simply carries on. The script prints the
 * server version at the start so the owner can see which case applies.
 * OWNER ACTION: check the MySQL version, take a backup, run this card once from
 * alpha (the database is shared by all three sites).
 *
 * IDEMPOTENT. Every table, column and index is checked against the live schema
 * before it is created; a second run prints a [SKIP] for each. The registry
 * probe is the multi-object OR form (pending while ANY object is missing), so a
 * partial apply never shows the card as done (rule #19).
 *
 * @migration-adds tblUserProfileItems.UserId
 * @migration-adds tblUserSyncTombstones.UserId
 * @migration-adds tblAccountEnrolKeys.KeyHash
 * @migration-adds tblApiTokens.DeviceInstallId
 * @migration-adds tblApiTokens.IssuedVia
 * @migration-adds tblApnsTokens.ApiTokenHash
 * @migration-adds tblPushSubscriptions.Channel
 * @migration-adds tblSongHistory.Source
 * @migration-adds tblSongHistory.Channel
 * @migration-adds tblSongHistory.Platform
 *
 * SCHEMA MIRROR: the three CREATE TABLE statements and every column and index
 * below are mirrored in appWeb/.sql/schema.sql (tables as full blocks, columns
 * and indexes inline in their table's block), byte for byte including COMMENT
 * text, so a fresh install equals a migrated one. The DDL below is held in
 * single-quoted heredocs so there is no escaping to differ by.
 * tests/php/test-account-profile-foundation-schema.php compares the two.
 *
 * NO SHARED INCLUDE. This is pure DDL: it requires no file under a docroot's
 * includes/ folder, so rule #41's IHYMNS_INCLUDES_DIR resolution is not needed
 * (and no literal /public_html/ path appears here).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-account-profile-foundation.php
 *   Web:  /manage/setup-database -> "Account and profile foundation" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see .claude/account-profile-rewrite-plan.md  Phase B, step B1
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migAcctProf_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

function _migAcctProf_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migAcctProf_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migAcctProf_indexExists(\mysqli $db, string $t, string $i): bool
{
    $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $t, $i);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migAcctProf_out('ERROR: MySQL credentials not found. Run install.php first.'); return; }
require_once $credFile;

_migAcctProf_out('');
_migAcctProf_out('=== iHymns - Account and profile foundation (epic #2180, B1) ===');
_migAcctProf_out('');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migAcctProf_out('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migAcctProf_out('Connected to MySQL: ' . DB_NAME);

/* Tell the owner which case applies for the big history table (see header). */
try {
    $ver = (string)($mysql->query('SELECT VERSION()')->fetch_row()[0] ?? '');
    _migAcctProf_out('Server version: ' . $ver);
    _migAcctProf_out('  (8.0.12 or later adds columns almost instantly; an older server copies tblSongHistory, which can be slow on a large table.)');
} catch (\mysqli_sql_exception $e) { /* informational only */ }

/* Every object, in the order they are created. [kind, table, name, SQL].
   Written ONCE here; schema.sql carries the same text (see the header). */
$steps = [
    ['table', 'tblUserProfileItems', null, <<<'SQL'
CREATE TABLE tblUserProfileItems (
    UserId         INT UNSIGNED    NOT NULL COMMENT 'FK tblUsers.Id - whose item this is',
    Section        VARCHAR(40)     NOT NULL COMMENT 'Registry section, app-validated against includes/profile_registry.php - VARCHAR not ENUM (rule #20)',
    ItemKey        VARCHAR(191)    NOT NULL COMMENT 'Item identity inside the section (a setting key, a SongId, a songbook abbreviation). Identity, never position (rule #51)',
    ValueJson      JSON            NULL DEFAULT NULL COMMENT 'The item value. NULL on a delete marker',
    Version        BIGINT UNSIGNED NOT NULL COMMENT 'Per-person change counter, assigned under the person row lock. A pull asks for rows above its last cursor',
    UpdatedAt      DATETIME(3)     NOT NULL COMMENT 'Server UTC time the change was accepted. Diagnosis only: ordering uses Version, never device clocks',
    DeletedAt      DATETIME(3)     NULL DEFAULT NULL COMMENT 'Non-NULL = delete marker (the item was removed at this time)',
    WriterChannel  VARCHAR(16)     NULL DEFAULT NULL COMMENT 'production | beta | alpha - which site accepted the write. Diagnosis only',
    WriterPlatform VARCHAR(20)     NULL DEFAULT NULL COMMENT 'web | apple | android - which kind of app wrote it. Diagnosis only',

    PRIMARY KEY (UserId, Section, ItemKey),
    INDEX idx_UserVersion (UserId, Version),

    CONSTRAINT fk_ProfileItems_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per small per-person item (settings, chord choices, recent songbooks, per-song key and capo). One sync method for all of them (account/profile rewrite, epic #2180). Dormant until the profile sync code uses it.'
SQL],
    ['table', 'tblUserSyncTombstones', null, <<<'SQL'
CREATE TABLE tblUserSyncTombstones (
    UserId    INT UNSIGNED NOT NULL COMMENT 'FK tblUsers.Id',
    Kind      VARCHAR(30)  NOT NULL COMMENT 'favorite | custom_tag - what was deleted. App-validated, VARCHAR not ENUM (rule #20)',
    ItemId    VARCHAR(191) NOT NULL COMMENT 'The deleted item identity (a SongId for a favourite, the tag text for a custom tag)',
    DeletedAt DATETIME     NOT NULL COMMENT 'Database clock (userSyncNow frame) at deletion',
    Reason    VARCHAR(20)  NOT NULL DEFAULT 'user' COMMENT 'user | admin - VARCHAR not ENUM (rule #20)',

    PRIMARY KEY (UserId, Kind, ItemId),

    CONSTRAINT fk_SyncTomb_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit delete markers for favourites and custom tags, so a removed item stays removed on other devices (account/profile rewrite, epic #2180). Set lists keep tblUserSetlistTombstones. Dormant until the favourites sync code uses it.'
SQL],
    ['table', 'tblAccountEnrolKeys', null, <<<'SQL'
CREATE TABLE tblAccountEnrolKeys (
    KeyHash    VARCHAR(64)  NOT NULL PRIMARY KEY COMMENT 'sha256 of the enrol key. The key itself is never stored',
    UserId     INT UNSIGNED NOT NULL COMMENT 'FK tblUsers.Id - whose key this is',
    Platform   VARCHAR(20)  NOT NULL COMMENT 'apple | android | web - which kind of app holds the key',
    CreatedAt  DATETIME     NOT NULL,
    LastUsedAt DATETIME     NULL DEFAULT NULL COMMENT 'Last time the key was swapped for a device sign-in',
    ExpiresAt  DATETIME     NOT NULL,
    RevokedAt  DATETIME     NULL DEFAULT NULL COMMENT 'Non-NULL = revoked (sign out everywhere)',

    INDEX idx_User (UserId),

    CONSTRAINT fk_EnrolKeys_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Long-lived keys a native app can swap ONLY for a new device sign-in (one sign-in per device, account/profile rewrite, epic #2180). Lives in its own table so a key can never work as a normal sign-in token. Dormant until the device-enrolment code uses it.'
SQL],
    ['column', 'tblApiTokens', 'DeviceInstallId', <<<'SQL'
ALTER TABLE tblApiTokens ADD COLUMN DeviceInstallId VARCHAR(64) NULL DEFAULT NULL COMMENT 'Random id the app makes once per install, so a reinstall replaces its old sign-in instead of piling up. Never trusted across users'
SQL],
    ['column', 'tblApiTokens', 'IssuedVia', <<<'SQL'
ALTER TABLE tblApiTokens ADD COLUMN IssuedVia VARCHAR(20) NULL DEFAULT NULL COMMENT 'How this sign-in was created: password | email | apple | device_code | cross_surface | enrol. VARCHAR not ENUM (rule #20)'
SQL],
    ['column', 'tblApnsTokens', 'ApiTokenHash', <<<'SQL'
ALTER TABLE tblApnsTokens ADD COLUMN ApiTokenHash VARCHAR(64) NULL DEFAULT NULL COMMENT 'Hash of the sign-in token this push registration belongs to, so signing that session out removes only its own registration'
SQL],
    ['column', 'tblPushSubscriptions', 'Channel', <<<'SQL'
ALTER TABLE tblPushSubscriptions ADD COLUMN Channel VARCHAR(16) NULL DEFAULT NULL COMMENT 'production | beta | alpha - which site the browser subscribed from (diagnosis only)'
SQL],
    ['index', 'tblApiTokens', 'idx_UserInstall', <<<'SQL'
ALTER TABLE tblApiTokens ADD KEY idx_UserInstall (UserId, DeviceInstallId)
SQL],
    ['index', 'tblApnsTokens', 'idx_ApiToken', <<<'SQL'
ALTER TABLE tblApnsTokens ADD KEY idx_ApiToken (ApiTokenHash)
SQL],
    ['column', 'tblSongHistory', 'Source', <<<'SQL'
ALTER TABLE tblSongHistory ADD COLUMN Source VARCHAR(20) NOT NULL DEFAULT 'view' COMMENT 'view | backfill - a real view, or one uploaded later from a device list. Only view counts as popularity. VARCHAR not ENUM (rule #20)'
SQL],
    ['column', 'tblSongHistory', 'Channel', <<<'SQL'
ALTER TABLE tblSongHistory ADD COLUMN Channel VARCHAR(16) NULL DEFAULT NULL COMMENT 'production | beta | alpha - which site recorded the view, so test sites can be left out of live statistics'
SQL],
    ['column', 'tblSongHistory', 'Platform', <<<'SQL'
ALTER TABLE tblSongHistory ADD COLUMN Platform VARCHAR(20) NULL DEFAULT NULL COMMENT 'web | apple | android - which kind of app recorded the view'
SQL],
];

try {
    foreach ($steps as [$kind, $table, $name, $sql]) {
        if (!_migAcctProf_tableExists($mysql, $table) && $kind !== 'table') {
            _migAcctProf_out("ERROR: table {$table} not found. Run the earlier migrations / install.php first.");
            return;
        }
        $label = $kind === 'table' ? $table : "{$table}.{$name}";
        $present = match ($kind) {
            'table'  => _migAcctProf_tableExists($mysql, $table),
            'column' => _migAcctProf_columnExists($mysql, $table, (string)$name),
            'index'  => _migAcctProf_indexExists($mysql, $table, (string)$name),
        };
        if ($present) {
            _migAcctProf_out("  [SKIP] {$label} already present.");
            continue;
        }
        $mysql->query($sql);
        _migAcctProf_out("  [OK]   Added {$label}.");
    }
    _migAcctProf_out('');
    _migAcctProf_out('=== Done. Account and profile foundation is in place (dormant). ===');
} catch (\mysqli_sql_exception $e) {
    _migAcctProf_out('ERROR: migration failed: ' . $e->getMessage());
    _migAcctProf_out('Run the card again to carry on: every object is created only if it is missing.');
    return;
}
