<?php

declare(strict_types=1);

/**
 * iHymns — Songbook Publisher as a first-class entity (#93 / epic #1765)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Promote the songbook PUBLISHER from the free-text tblSongbooks.Publisher
 * scalar to a registry of persons AND companies, with multi-publisher copyright
 * and imprint / catalogue grouping:
 *   - tblPublishers (Name, Slug, Kind, MusicianId, ParentId, Ipi/Isni, City) —
 *     the registry; a publisher is a company OR a person (linked to
 *     tblMusicians so a musician who also publishes is not duplicated);
 *     ParentId self-FK models imprint / catalogue grouping.
 *   - tblSongbookPublishers — the many-to-many (multi-publisher copyright),
 *     mirroring tblSongbookCompilers.
 *   - tblPublisherAliases / tblPublisherExternalLinks — forward-looking
 *     one-pass tables (rule #20) so the family never needs a second migration.
 * Backfills tblPublishers from DISTINCT non-empty tblSongbooks.Publisher and
 * links each book to its publisher (re-runnable).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * NOT DESTRUCTIVE. Nothing is dropped or overwritten: tblSongbooks.Publisher is
 * left in place as the JOIN-free denorm display mirror (so every existing reader
 * keeps working with no JOIN), and the backfill only ever INSERT IGNOREs — it
 * never updates an existing publisher or re-links a book that is already linked.
 * The "undo" is DROP the four tables; no songbook data is at risk.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — one guard per creatable object plus IGNORE-only
 * writes, because a re-run must resume from wherever the last one stopped:
 *   1. tblPublishers             — _migPub_tableExists()
 *   2. tblSongbookPublishers     — _migPub_tableExists()
 *   3. tblPublisherAliases       — _migPub_tableExists()
 *   4. tblPublisherExternalLinks — _migPub_tableExists()
 * The BACKFILL has no guard and does not need one: it is INSERT IGNORE against
 * `UNIQUE KEY uq_Slug` (registry) and `UNIQUE KEY uq_book_pub_role` (links).
 * Running it again creates 0 publishers and 0 links; running it after new books
 * have been imported picks up exactly the new ones. That is why it is labelled
 * "re-runnable" rather than "skipped" — re-running is the intended repair.
 * The registry probe is the multi-object OR form (all four tables), per
 * CLAUDE.md rule #19, so a partial apply never shows the card green.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Publishers registry (#93)"
 * → button "Run Publishers Migration". A first run ends with a line like
 * "Created 42 publishers; linked 118 songbooks"; a repeat run reports
 * "Created 0 publishers; linked 0 songbooks".
 *
 * SCHEMA MIRROR: tblPublishers / tblSongbookPublishers / tblPublisherAliases /
 * tblPublisherExternalLinks are all mirrored in appWeb/.sql/schema.sql, which is
 * what a FRESH install reads instead of running this script. The declarations
 * must stay byte-identical (COMMENT text included) or fresh and migrated
 * installs diverge and the Schema Audit page (#518) flags it — CLAUDE.md rule
 * #19. Here the ordering is natural (all FK-referenced tables — tblSongbooks,
 * tblMusicians, tblPlaces, tblExternalLinkTypes — already exist), so no FK needs
 * to be a trailing ALTER.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-publishers-entity.php
 *   Web:  /manage/setup-database → "Publishers registry" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migPub_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}
function _migPub_tableExists(\mysqli $db, string $t): bool {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    return (bool)($r && $r->num_rows > 0);
}
function _migPub_colExists(\mysqli $db, string $t, string $c): bool {
    $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'");
    return (bool)($r && $r->num_rows > 0);
}
/* Publisher name → URL handle. Same rationale + iconv fall-through as
   _migTunes_slugify: a name that cannot transliterate falls back to the raw
   name, the [^a-z0-9]+ strip removes what is left, and an all-non-Latin name
   collapses to '' → 'publisher', which the caller's collision loop turns into
   'publisher-2' etc. The 110-char cap leaves headroom inside Slug's
   VARCHAR(120) for the '-N' suffix. */
function _migPub_slugify(string $name): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $s = $s === false ? $name : $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'publisher' : substr($s, 0, 110);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPub_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPub_output("");
_migPub_output("=== iHymns — Publishers registry (#93 / epic #1765) ===");
_migPub_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migPub_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migPub_output("Connected to MySQL: " . DB_NAME);

try {
    _migPub_output("--- tblPublishers ---");
    if (_migPub_tableExists($mysql, 'tblPublishers')) {
        _migPub_output("  [SKIP] tblPublishers already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblPublishers (
                Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ParentId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-FK: imprint / catalogue grouping — an imprint points to its parent publisher (mirrors tblWorks.ParentWorkId / tblSongTags.ParentId). NULL = top-level. (#93)',
                Name           VARCHAR(255) NOT NULL COMMENT 'Publisher display name (company name, or the person\'s name for a person-publisher).',
                Slug           VARCHAR(120) NOT NULL COMMENT 'URL-safe handle, unique.',
                Kind           VARCHAR(20)  NOT NULL DEFAULT 'company' COMMENT 'Publisher kind — app-validated vocabulary, VARCHAR not ENUM (rule #20): company | person | imprint | society | other.',
                MusicianId     INT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional FK to tblMusicians for a person-publisher, so a musician who also publishes is not duplicated. NULL for a pure company. (#93)',
                Subtitle       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional publisher subtitle / tagline.',
                Disambiguation VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Short parenthetical to distinguish same-named publishers.',
                Ipi            VARCHAR(20)  NULL DEFAULT NULL COMMENT 'Interested-Parties Information number (publishers hold these too). NULL (not empty string) so absent values coexist under uq_Ipi.',
                Isni           VARCHAR(20)  NULL DEFAULT NULL COMMENT 'ISNI. NULL (not empty string) so absent values coexist under uq_Isni.',
                CityName       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Publisher city display string (denorm mirror of CityId, same pattern as tblWorks.OriginCity).',
                CityId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to tblPlaces for the publisher city (mirrors tblWorks.OriginCityId).',
                Notes          TEXT         NULL DEFAULT NULL,
                IsActive       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Soft-hide a defunct publisher from pickers without deleting its songbook links.',
                CreatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Slug     (Slug),
                UNIQUE KEY uq_Ipi      (Ipi),
                UNIQUE KEY uq_Isni     (Isni),
                INDEX      idx_Parent   (ParentId),
                INDEX      idx_Kind     (Kind),
                INDEX      idx_Musician (MusicianId),
                INDEX      idx_Name     (Name),
                INDEX      idx_Active   (IsActive),
                INDEX      idx_City     (CityId),
                CONSTRAINT fk_Publisher_Parent
                    FOREIGN KEY (ParentId)   REFERENCES tblPublishers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Publisher_Musician
                    FOREIGN KEY (MusicianId) REFERENCES tblMusicians(Id)  ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Publisher_City
                    FOREIGN KEY (CityId)     REFERENCES tblPlaces(Id)     ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Songbook publishers as first-class entities — persons + companies (#93 / epic #1765).'"
        );
        _migPub_output("  [OK] Created tblPublishers.");
    }

    _migPub_output("--- tblSongbookPublishers ---");
    if (_migPub_tableExists($mysql, 'tblSongbookPublishers')) {
        _migPub_output("  [SKIP] tblSongbookPublishers already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblSongbookPublishers (
                Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                SongbookId   INT UNSIGNED NOT NULL,
                PublisherId  INT UNSIGNED NOT NULL,
                Role         VARCHAR(30)  NOT NULL DEFAULT 'publisher' COMMENT 'Role in this songbook — app-validated, VARCHAR not ENUM: publisher | co-publisher | distributor | imprint | administrator | printer.',
                SortOrder    INT UNSIGNED NOT NULL DEFAULT 0,
                Note         VARCHAR(255) NULL DEFAULT NULL,
                ValidFrom    DATE         NULL DEFAULT NULL COMMENT 'Reserved (#93): start of this publisher\'s rights window for this book. Dormant until a rights-window feature lands.',
                ValidTo      DATE         NULL DEFAULT NULL COMMENT 'Reserved (#93): end of this publisher\'s rights window for this book. Dormant until a rights-window feature lands.',
                CreatedAt    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_book_pub_role (SongbookId, PublisherId, Role),
                INDEX      idx_book (SongbookId),
                INDEX      idx_pub  (PublisherId),
                CONSTRAINT fk_sbpub_book
                    FOREIGN KEY (SongbookId)  REFERENCES tblSongbooks(Id)  ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_sbpub_pub
                    FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Songbook<->publisher many-to-many for multi-publisher copyright (#93).'"
        );
        _migPub_output("  [OK] Created tblSongbookPublishers.");
    }

    _migPub_output("--- tblPublisherAliases ---");
    if (_migPub_tableExists($mysql, 'tblPublisherAliases')) {
        _migPub_output("  [SKIP] tblPublisherAliases already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblPublisherAliases (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                PublisherId INT UNSIGNED NOT NULL,
                Name        VARCHAR(255) NOT NULL,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_PubName (PublisherId, Name),
                INDEX      idx_Pub    (PublisherId),
                INDEX      idx_Name   (Name),
                CONSTRAINT fk_PubAlias_Pub
                    FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Alternate / former names for a publisher (#93).'"
        );
        _migPub_output("  [OK] Created tblPublisherAliases.");
    }

    _migPub_output("--- tblPublisherExternalLinks ---");
    if (_migPub_tableExists($mysql, 'tblPublisherExternalLinks')) {
        _migPub_output("  [SKIP] tblPublisherExternalLinks already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblPublisherExternalLinks (
                Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                PublisherId INT UNSIGNED NOT NULL,
                LinkTypeId  INT UNSIGNED NOT NULL,
                Url         VARCHAR(2048) NOT NULL,
                Note        VARCHAR(255) NULL,
                SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
                Verified    TINYINT(1)   NOT NULL DEFAULT 0,
                CreatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pub  (PublisherId),
                INDEX idx_type (LinkTypeId),
                CONSTRAINT fk_link_pub
                    FOREIGN KEY (PublisherId) REFERENCES tblPublishers(Id)      ON DELETE CASCADE,
                CONSTRAINT fk_link_type_pub
                    FOREIGN KEY (LinkTypeId)  REFERENCES tblExternalLinkTypes(Id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-publisher external-link rows (#93), mirroring tblTuneExternalLinks.'"
        );
        _migPub_output("  [OK] Created tblPublisherExternalLinks.");
    }

    _migPub_output("--- Backfill tblPublishers from DISTINCT tblSongbooks.Publisher (re-runnable) ---");
    if (!_migPub_colExists($mysql, 'tblSongbooks', 'Publisher')) {
        _migPub_output("  [SKIP] tblSongbooks.Publisher column not present — nothing to backfill.");
    } else {
        /* Step 1 — one registry row per DISTINCT non-empty Publisher string.
           tblPublishers.Name is utf8mb4_unicode_ci (case- + accent-insensitive)
           and the DISTINCT scan is likewise CI, so "Praise Trust" / "praise
           trust" fold to ONE registry row — the same de-dup the tune backfill
           relies on. Kind defaults to 'company' (the common case for a book
           publisher); a curator re-classifies persons afterward on
           /manage/publishers.

           IDEMPOTENCY IS BY NAME, NOT SLUG. Unlike tblTunes, tblPublishers has
           NO uq_Name (two publishers may legitimately share a name under
           different Disambiguation), so we CANNOT lean on INSERT IGNORE to fold
           a re-run: with only uq_Slug, the slug-collision loop below would just
           mint "name-2" and INSERT a DUPLICATE publisher every run (verified —
           this exact bug turned 2 rows into 4 on the second run). So we skip a
           name that already exists BEFORE minting a slug. The link step's own
           uq_book_pub_role then stays a clean 1:1 with the single row. */
        $sel = $mysql->query("SELECT DISTINCT Publisher FROM tblSongbooks WHERE Publisher IS NOT NULL AND Publisher <> ''");
        $nameExists = $mysql->prepare("SELECT 1 FROM tblPublishers WHERE Name = ? LIMIT 1");
        $insPub     = $mysql->prepare("INSERT INTO tblPublishers (Name, Slug, Kind) VALUES (?, ?, 'company')");
        $slugTaken  = $mysql->prepare("SELECT 1 FROM tblPublishers WHERE Slug = ? LIMIT 1");
        $created = 0;
        while ($row = $sel->fetch_assoc()) {
            $name = (string)$row['Publisher'];
            $nameExists->bind_param('s', $name); $nameExists->execute();
            $have = $nameExists->get_result();
            if ($have && $have->num_rows > 0) { continue; }   /* re-run: this publisher already exists */
            $base = _migPub_slugify($name);
            $slug = $base; $n = 1;
            while (true) {
                $slugTaken->bind_param('s', $slug); $slugTaken->execute();
                $taken = $slugTaken->get_result();
                if (!($taken && $taken->num_rows > 0)) break;
                $n++; $slug = substr($base, 0, 114) . '-' . $n;
            }
            $insPub->bind_param('ss', $name, $slug);
            $insPub->execute();
            if ($insPub->affected_rows > 0) $created++;
        }
        $nameExists->close(); $insPub->close(); $slugTaken->close();

        /* Step 2 — link each book to its publisher (M:N). INSERT IGNORE against
           uq_book_pub_role means a book already linked is skipped, and a book
           whose Publisher string a curator later edits gets a fresh link on the
           next run without disturbing the old one (which the curator can prune
           on the editor). Match is CI (both columns unicode_ci). */
        $mysql->query(
            "INSERT IGNORE INTO tblSongbookPublishers (SongbookId, PublisherId, Role, SortOrder)
             SELECT b.Id, p.Id, 'publisher', 0
               FROM tblSongbooks b
               JOIN tblPublishers p ON b.Publisher = p.Name
              WHERE b.Publisher IS NOT NULL AND b.Publisher <> ''"
        );
        $linked = $mysql->affected_rows;
        _migPub_output("  [OK] Created {$created} publishers; linked {$linked} songbooks.");
    }

    _migPub_output("");
    _migPub_output("Migration complete.");
} catch (\Throwable $e) {
    _migPub_output("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
