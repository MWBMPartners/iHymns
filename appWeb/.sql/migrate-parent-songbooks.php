<?php

declare(strict_types=1);

/**
 * iHymns — Parent Songbooks schema (#782 phase A)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Some songbooks aren't standalone collections — they're either:
 *
 *   (1) Translated derivatives of a canonical parent (e.g. the
 *       22 vernacular Christ in Song hymnals → English CIS), OR
 *
 *   (2) Editions in a chain (e.g. Mission Praise 1, 2, Combined),
 *       OR
 *
 *   (3) Volumes in a peer-to-peer series (e.g. Songs of Fellowship
 *       vols 1, 2, 3, … with no canonical root).
 *
 * Cases (1) and (2) are HIERARCHICAL — there's a real canonical
 * row to point upward to — so they fit a self-FK on tblSongbooks
 * (ParentSongbookId + ParentRelationship). Case (3) is PEER-TO-PEER
 * — no canonical root, just shared series identity — so it fits a
 * series-membership join table (tblSongbookSeries +
 * tblSongbookSeriesMembership).
 *
 * A songbook can carry BOTH: e.g. Mission Praise vol 2 has
 * ParentSongbookId = MP-vol-1 (it's the next edition) AND a row
 * in tblSongbookSeriesMembership linking to "Mission Praise series"
 * (so a consumer asking "what other Mission Praise volumes exist?"
 * gets the whole canon). The two relationships answer different
 * questions:
 *
 *   - Parent FK   → "Where's the canonical reference for this row?"
 *   - Series link → "What other songbooks share my collection
 *                    identity?"
 *
 * Phase A is schema only — phases B-E (separate PRs) wire the
 * admin picker, public-side display, helpers, and bulk-import
 * auto-link on top.
 *
 * Idempotent — INFORMATION_SCHEMA-probed; re-runs are no-ops.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-parent-songbooks.php
 *   Web: /manage/setup-database → "Parent Songbooks (#782 phase A)"
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

function _migParentSb_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migParentSb_tableExists(mysqli $db, string $table): bool
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

function _migParentSb_columnExists(mysqli $db, string $table, string $column): bool
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

_migParentSb_out('Parent Songbooks schema migration starting (#782 phase A)…');

$db = getDbMysqli();
if (!$db) {
    _migParentSb_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migParentSb_tableExists($db, 'tblSongbooks')) {
    _migParentSb_out('ERROR: tblSongbooks not found. Run install.php first.');
    exit(1);
}

/* =========================================================================
 * Step 1 — tblSongbooks.ParentSongbookId + ParentRelationship columns
 * ========================================================================= */
if (_migParentSb_columnExists($db, 'tblSongbooks', 'ParentSongbookId')) {
    _migParentSb_out('[skip] tblSongbooks.ParentSongbookId already present.');
} else {
    $sql = "ALTER TABLE tblSongbooks
            ADD COLUMN ParentSongbookId INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Canonical parent songbook for translations / editions; NULL = standalone or peer-grouped via tblSongbookSeriesMembership',
            ADD KEY idx_ParentSongbookId (ParentSongbookId)";
    if (!$db->query($sql)) {
        _migParentSb_out('ERROR: adding ParentSongbookId failed: ' . $db->error);
        exit(1);
    }
    _migParentSb_out('[add ] tblSongbooks.ParentSongbookId.');
}

if (_migParentSb_columnExists($db, 'tblSongbooks', 'ParentRelationship')) {
    _migParentSb_out('[skip] tblSongbooks.ParentRelationship already present.');
} else {
    /* Enum values:
         translation — vernacular derived from a canonical parent
                       (e.g. Spanish CIS → English CIS).
         edition     — a successor edition in a chain
                       (e.g. Mission Praise 2 → Mission Praise 1).
         abridgement — a curated subset of a canonical parent
                       (e.g. Children's Mission Praise → Mission Praise).
       Reserved for v2: peer relationships are modelled via the
       series tables below, NOT here. */
    $sql = "ALTER TABLE tblSongbooks
            ADD COLUMN ParentRelationship ENUM('translation','edition','abridgement') NULL DEFAULT NULL
                COMMENT 'How this row relates to its parent — drives display copy + linkage UI'
                AFTER ParentSongbookId";
    if (!$db->query($sql)) {
        _migParentSb_out('ERROR: adding ParentRelationship failed: ' . $db->error);
        exit(1);
    }
    _migParentSb_out('[add ] tblSongbooks.ParentRelationship.');
}

/* The FK lives at the end so the columns are guaranteed to exist
   first. ON DELETE SET NULL keeps a child row alive when the parent
   is removed (the relationship is informational; orphan rows aren't
   broken data). ON UPDATE CASCADE so a future Id reassignment (rare)
   doesn't strand children. */
$stmt = $db->prepare(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblSongbooks'
        AND CONSTRAINT_NAME = 'fk_songbooks_parent'
      LIMIT 1"
);
$stmt->execute();
$fkExists = $stmt->get_result()->fetch_row() !== null;
$stmt->close();
if ($fkExists) {
    _migParentSb_out('[skip] FK fk_songbooks_parent already present.');
} else {
    $sql = "ALTER TABLE tblSongbooks
            ADD CONSTRAINT fk_songbooks_parent FOREIGN KEY (ParentSongbookId)
                REFERENCES tblSongbooks(Id)
                ON DELETE SET NULL ON UPDATE CASCADE";
    if (!$db->query($sql)) {
        _migParentSb_out('WARN: FK fk_songbooks_parent could not be added: ' . $db->error);
        _migParentSb_out('       The column exists; the FK is enforcement-only and the migration continues.');
    } else {
        _migParentSb_out('[add ] FK fk_songbooks_parent.');
    }
}

/* =========================================================================
 * Step 2 — tblSongbookSeries
 * ========================================================================= */
if (_migParentSb_tableExists($db, 'tblSongbookSeries')) {
    _migParentSb_out('[skip] tblSongbookSeries already exists.');
} else {
    $sql = "CREATE TABLE tblSongbookSeries (
        Id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        Name         VARCHAR(120)  NOT NULL,
        Description  VARCHAR(255)  NOT NULL DEFAULT '',
        Slug         VARCHAR(120)  NOT NULL UNIQUE
                     COMMENT 'URL-safe lowercase form for /series/<slug> public listing pages',
        CreatedAt    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_Name (Name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migParentSb_out('ERROR: create tblSongbookSeries failed: ' . $db->error);
        exit(1);
    }
    _migParentSb_out('[add ] tblSongbookSeries.');
}

/* =========================================================================
 * Step 3 — tblSongbookSeriesMembership
 * ========================================================================= */
if (_migParentSb_tableExists($db, 'tblSongbookSeriesMembership')) {
    _migParentSb_out('[skip] tblSongbookSeriesMembership already exists.');
} else {
    $sql = "CREATE TABLE tblSongbookSeriesMembership (
        SeriesId     INT UNSIGNED NOT NULL,
        SongbookId   INT UNSIGNED NOT NULL,
        SortOrder    SMALLINT     NOT NULL DEFAULT 0
                     COMMENT 'Display order within the series (e.g. volume 1 → 10, volume 2 → 20, …)',
        Note         VARCHAR(120) NOT NULL DEFAULT ''
                     COMMENT 'Optional free-text annotation, e.g. \"published 1998\" or \"combined edition\"',
        CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (SeriesId, SongbookId),
        KEY idx_member (SongbookId),
        CONSTRAINT fk_sbsm_series   FOREIGN KEY (SeriesId)
            REFERENCES tblSongbookSeries(Id) ON DELETE CASCADE,
        CONSTRAINT fk_sbsm_songbook FOREIGN KEY (SongbookId)
            REFERENCES tblSongbooks(Id)      ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migParentSb_out('ERROR: create tblSongbookSeriesMembership failed: ' . $db->error);
        exit(1);
    }
    _migParentSb_out('[add ] tblSongbookSeriesMembership.');
}

/* =========================================================================
 * Final probe
 * ========================================================================= */
$res = $db->query('SELECT COUNT(*) FROM tblSongbookSeries');
$seriesCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(*) FROM tblSongbookSeriesMembership');
$membershipCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(*) FROM tblSongbooks WHERE ParentSongbookId IS NOT NULL');
$linkedCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
_migParentSb_out("Catalogue currently has {$linkedCount} songbook(s) with a parent link, "
               . "{$seriesCount} series defined, {$membershipCount} membership row(s).");
_migParentSb_out('Phases B-E (admin picker, public surfaces, helpers, bulk-import auto-link) are tracked in #782.');

_migParentSb_out('Parent Songbooks schema migration finished (#782 phase A).');
