<?php

declare(strict_types=1);

/**
 * iHymns — Cross-book song links schema (#807)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Models "this hymn appears in multiple songbooks at unrelated
 * numbers" — e.g. Amazing Grace as MP-031 / CH-376 / SDAH-108 /
 * SoF-29 / JP-006. Distinct from:
 *
 *   - tblSongTranslations (#352)  — different-language version
 *                                   of the same hymn, with a
 *                                   Translator credit.
 *   - tblSongbooks.ParentSongbookId (#782) — songbook-level
 *                                   parent (translation /
 *                                   edition / abridgement);
 *                                   only deep-links to the
 *                                   same hymn number in the
 *                                   parent.
 *
 * Cross-book counterparts are SAME-HYMN, often SAME-LANGUAGE,
 * different-songbook, unrelated-number. Two or more rows share
 * a GroupId and the public song page surfaces them as an "Also
 * appears in" dropdown.
 *
 * Modelling as a shared-group join (rather than per-pair edges)
 * gives transitive closure for free — adding a fourth member is
 * one INSERT, not three pair rows. UNIQUE on SongId enforces
 * "one song belongs to at most one group".
 *
 * Idempotent — INFORMATION_SCHEMA-probed; re-runs are no-ops.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-song-links.php
 *   Web: /manage/setup-database → "Cross-book song links (#807)"
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

function _migSongLinks_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migSongLinks_tableExists(mysqli $db, string $table): bool
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

_migSongLinks_out('Cross-book song links migration starting (#807)…');

$db = getDbMysqli();
if (!$db) {
    _migSongLinks_out('ERROR: could not connect to database.');
    exit(1);
}

if (!_migSongLinks_tableExists($db, 'tblSongs')) {
    _migSongLinks_out('ERROR: tblSongs not found. Run install.php first.');
    exit(1);
}

/* =========================================================================
 * Step 1 — tblSongLinks (the cross-book counterpart join table)
 *
 * GroupId is the equivalence-class key. UNIQUE on SongId means a
 * song can only belong to one group at a time — preventing the
 * "MP-031 is in group 7 AND group 12" inconsistency. To merge two
 * groups, run a single UPDATE rewriting one GroupId to the other.
 * ========================================================================= */
if (_migSongLinks_tableExists($db, 'tblSongLinks')) {
    _migSongLinks_out('[skip] tblSongLinks already exists.');
} else {
    $sql = "CREATE TABLE tblSongLinks (
        Id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        GroupId      INT UNSIGNED  NOT NULL
                     COMMENT 'All songs sharing a GroupId are the same hymn (cross-book counterparts).',
        SongId       VARCHAR(20)   NOT NULL,
        Note         VARCHAR(255)  NOT NULL DEFAULT ''
                     COMMENT 'Optional curator-set annotation, e.g. \"uses 1990 Wesley revision text\"',
        Verified     TINYINT(1)    NOT NULL DEFAULT 0,
        CreatedBy    INT UNSIGNED  NULL DEFAULT NULL
                     COMMENT 'tblUsers.Id of the curator who linked this row, if signed in',
        CreatedAt    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_song (SongId),
        KEY idx_GroupId (GroupId),
        CONSTRAINT fk_SongLinks_Song FOREIGN KEY (SongId)
            REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migSongLinks_out('ERROR: create tblSongLinks failed: ' . $db->error);
        exit(1);
    }
    _migSongLinks_out('[add ] tblSongLinks.');
}

/* =========================================================================
 * Step 2 — tblSongLinkSuggestions (#808 — paired-with-this-issue
 *                                  candidate list)
 *
 * Pre-computed pairwise similarity scores for any two songs that
 * MIGHT be the same hymn. The /manage/song-link-suggestions admin
 * page reads from this table; the build job (tools/build-song-link-
 * suggestions.php) writes to it. Storing here rather than computing
 * on every page render is the only way the list stays interactive
 * for a 12k-row catalogue.
 *
 * Convention: SongIdA < SongIdB lexicographically — the build script
 * canonicalises so we never store both (A,B) and (B,A).
 * ========================================================================= */
if (_migSongLinks_tableExists($db, 'tblSongLinkSuggestions')) {
    _migSongLinks_out('[skip] tblSongLinkSuggestions already exists.');
} else {
    $sql = "CREATE TABLE tblSongLinkSuggestions (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongIdA         VARCHAR(20)  NOT NULL COMMENT 'Always lexicographically <= SongIdB',
        SongIdB         VARCHAR(20)  NOT NULL,
        Score           DECIMAL(4,3) NOT NULL COMMENT 'Composite similarity, 0.000–1.000',
        TitleScore      DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        LyricsScore     DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        AuthorsScore    DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        ComputedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pair (SongIdA, SongIdB),
        KEY idx_Score (Score),
        KEY idx_SongA (SongIdA),
        KEY idx_SongB (SongIdB),
        CONSTRAINT fk_SongLinkSugg_A FOREIGN KEY (SongIdA)
            REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_SongLinkSugg_B FOREIGN KEY (SongIdB)
            REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migSongLinks_out('ERROR: create tblSongLinkSuggestions failed: ' . $db->error);
        exit(1);
    }
    _migSongLinks_out('[add ] tblSongLinkSuggestions.');
}

/* =========================================================================
 * Step 3 — tblSongLinkSuggestionsDismissed
 *
 * Curator-rejected pairs. The build script consults this table and
 * skips any pair that's already been dismissed, so the suggestion
 * list doesn't keep proposing pairs the curator has already said no
 * to. Reasons are free-text and optional.
 * ========================================================================= */
if (_migSongLinks_tableExists($db, 'tblSongLinkSuggestionsDismissed')) {
    _migSongLinks_out('[skip] tblSongLinkSuggestionsDismissed already exists.');
} else {
    $sql = "CREATE TABLE tblSongLinkSuggestionsDismissed (
        Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        SongIdA         VARCHAR(20)  NOT NULL COMMENT 'Always lexicographically <= SongIdB',
        SongIdB         VARCHAR(20)  NOT NULL,
        DismissedBy     INT UNSIGNED NULL DEFAULT NULL,
        DismissedAt     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Reason          VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uk_pair (SongIdA, SongIdB),
        KEY idx_SongA (SongIdA),
        KEY idx_SongB (SongIdB)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        _migSongLinks_out('ERROR: create tblSongLinkSuggestionsDismissed failed: ' . $db->error);
        exit(1);
    }
    _migSongLinks_out('[add ] tblSongLinkSuggestionsDismissed.');
}

/* =========================================================================
 * Final probe
 * ========================================================================= */
$res = $db->query('SELECT COUNT(*) FROM tblSongLinks');
$linkCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(DISTINCT GroupId) FROM tblSongLinks');
$groupCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(*) FROM tblSongLinkSuggestions');
$suggCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();
$res = $db->query('SELECT COUNT(*) FROM tblSongLinkSuggestionsDismissed');
$dismCount = $res ? (int)$res->fetch_row()[0] : 0;
if ($res) $res->close();

_migSongLinks_out("Catalogue carries {$linkCount} cross-book link row(s) "
                . "in {$groupCount} group(s); "
                . "{$suggCount} pending suggestion(s); "
                . "{$dismCount} dismissed pair(s).");
_migSongLinks_out('Cross-book song links migration finished (#807 / #808).');
