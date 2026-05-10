<?php

declare(strict_types=1);

/**
 * iHymns — Catalogues migration (#941)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds the Catalogue concept — a free-form many-to-many grouping of
 * songs that doesn't fit the Songbook / Songbook Series hierarchy.
 *
 * Use cases:
 *   - Thematic collections ("Christmas / Advent", "Easter morning")
 *   - Worship-leader curations ("Modern", "Traditional")
 *   - Trans-denominational groupings ("SDA-affiliated",
 *     "Methodist heritage")
 *   - Public-Domain-only views
 *   - Future: per-user personal catalogues (admin-only at v1)
 *
 * Schema:
 *   tblCatalogues       — one row per catalogue (slug + title + meta)
 *   tblCatalogueSongs   — many-to-many join (catalogue ↔ song)
 *
 * @migration-adds tblCatalogues
 * @migration-adds tblCatalogueSongs
 *
 * Idempotent — re-running is safe; tables that exist are skipped.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-catalogues.php
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

function _migCat_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migCat_tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migCat_out('Catalogues migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migCat_out('ERROR: could not connect to database.');
    exit(1);
}

/* tblSongs must exist — Catalogue rows reference SongIds. */
if (!_migCat_tableExists($mysqli, 'tblSongs')) {
    _migCat_out('ERROR: tblSongs is missing — run install.php before this migration.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: tblCatalogues — the catalogue registry
 * ---------------------------------------------------------------------- */
if (_migCat_tableExists($mysqli, 'tblCatalogues')) {
    _migCat_out('[skip] tblCatalogues already present.');
} else {
    $sql = "CREATE TABLE tblCatalogues (
        Id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
        Slug         VARCHAR(255)      NOT NULL,
        Title        VARCHAR(255)      NOT NULL,
        Description  TEXT              NULL,
        SortOrder    SMALLINT          NOT NULL DEFAULT 0,
        Visibility   ENUM('public','curated','admin_only')
                                        NOT NULL DEFAULT 'public',
        CreatedAt    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_Slug (Slug),
        INDEX idx_Visibility (Visibility),
        INDEX idx_SortOrder  (SortOrder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migCat_out('ERROR: creating tblCatalogues failed: ' . $mysqli->error);
        exit(1);
    }
    _migCat_out('[add ] tblCatalogues.');
}

/* ----------------------------------------------------------------------
 * Step 2: tblCatalogueSongs — the many-to-many join
 *
 * Composite PK on (CatalogueId, SongId) prevents duplicate links.
 * SortOrder lets curators arrange the listing order within a catalogue.
 * AddedBy + AddedAt give a basic audit trail without a full revisions
 * table — adequate for a v1 admin surface.
 * ---------------------------------------------------------------------- */
if (_migCat_tableExists($mysqli, 'tblCatalogueSongs')) {
    _migCat_out('[skip] tblCatalogueSongs already present.');
} else {
    $sql = "CREATE TABLE tblCatalogueSongs (
        CatalogueId  INT UNSIGNED      NOT NULL,
        SongId       VARCHAR(20)       NOT NULL,
        SortOrder    INT               NOT NULL DEFAULT 0,
        AddedBy      INT UNSIGNED      NULL,
        AddedAt      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (CatalogueId, SongId),
        INDEX idx_SongId (SongId),
        INDEX idx_SortOrder (CatalogueId, SortOrder),
        CONSTRAINT fk_CatalogueSongs_Catalogue
            FOREIGN KEY (CatalogueId) REFERENCES tblCatalogues(Id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_CatalogueSongs_Song
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migCat_out('ERROR: creating tblCatalogueSongs failed: ' . $mysqli->error);
        exit(1);
    }
    _migCat_out('[add ] tblCatalogueSongs.');
}

_migCat_out('Catalogues migration finished.');
/* Don't close $mysqli — see migrate-credit-people-slug.php for the
   shared-singleton rationale. */
