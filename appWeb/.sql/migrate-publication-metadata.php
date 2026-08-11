<?php

declare(strict_types=1);

/**
 * iHymns — Publication metadata: disable / public-domain / ARK / OpenLibrary
 * + Google Books provider seed + Internet Archive multiplicity (epic #1765)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (ELI5 first, then detail — see the epic plan for the full story):
 * ELI5: this file adds a handful of new, empty boxes to three tables that
 * already describe songbooks/series/collections, and teaches the app two new
 * facts about "Internet Archive" links. Nothing on the live site changes the
 * moment this runs — every new box starts empty (or 0), and nothing reads
 * them yet.
 *
 * Detail: Commit 2 of 7 in the Songbook/Catalogue Enhancements epic
 * (.claude/songbook-catalogue-enhancements-plan.md — the migration section
 * + its "Feature 7" section). Commit 1 (b0cdbd27) landed the pure, DB-free
 * foundations this schema batch is dormant groundwork for — nothing here is
 * read or written by application code until commit 3 (Feature 1 read-path
 * sweep) and commit 4 (admin surfaces) land. Applying this migration changes
 * ZERO observable behaviour on the running site: every new column is
 * additive + nullable-or-DEFAULT-0, and the two Feature 7 data steps only
 * ADD rows / widen a CSV allow-list, never remove or rewrite anything a
 * curator already set.
 *
 * SIX IDEMPOTENT, GUARDED STAGES (rule #19/#20 — mirrors
 * migrate-song-soft-delete.php's per-column guarded-ALTER shape exactly):
 *
 *   1. tblSongbooks        +4 columns (IsDisabled, IsPublicDomain,
 *                          OpenLibraryWorkId, OpenLibraryEditionId) —
 *                          Features 1-3.
 *   2. tblSongbookSeries   +5 columns (Isbn, Issn, ArkId,
 *                          OpenLibraryWorkId, OpenLibraryEditionId) —
 *                          Feature 3, series-level bibliographic ids.
 *   3. tblCatalogues       +3 columns (ArkId, OpenLibraryWorkId,
 *                          OpenLibraryEditionId) — Feature 3, collection-
 *                          level bibliographic ids. Deliberately NO
 *                          Isbn/Issn here (adversarial notes: a curated
 *                          collection is not itself a catalogued serial
 *                          publication) and NO identifier columns land on
 *                          tblSongs (Feature 3 is publication-entity only).
 *   4. tblExternalLinkTypes  seed  — ONE new provider row, slug
 *                          'google-books' (Feature 4). Upsert keyed on the
 *                          UNIQUE uq_slug, mirroring migrate-external-
 *                          links.php's Step 2 pattern exactly — but note
 *                          this upsert's UPDATE clause deliberately omits
 *                          AppliesTo/AllowMultiple/IsActive (curator-owned
 *                          fields a re-run must never stomp), narrower than
 *                          migrate-external-links.php's own upsert (which
 *                          does update AppliesTo — see the Stage 6a note
 *                          below for why that distinction matters).
 *   5. tblExternalLinkPatterns  seed — host/path rules that let a pasted
 *                          Google Books URL auto-detect to the new type,
 *                          mirroring migrate-external-link-patterns.php's
 *                          INSERT…WHERE NOT EXISTS guard exactly (that
 *                          table carries no UNIQUE key, so this guard IS
 *                          the idempotency mechanism, not a happy default).
 *   6. Feature 7 — Internet Archive multiplicity (added to the plan
 *                          2026-08-04, folded into this commit per its own
 *                          instruction "fold into commit 2"):
 *        6a. Widen the EXISTING 'internet-archive' type's AppliesTo to
 *            include 'song' (it already applies to 'songbook' only, from
 *            migrate-external-links.php's original seed). This is a
 *            SEPARATE guarded UPDATE, not a re-run of Stage 4's upsert,
 *            because Stage 4's upsert (like migrate-external-links.php's)
 *            DOES rewrite AppliesTo on every run — rewriting it here would
 *            stomp any AppliesTo value a curator has since hand-edited via
 *            /manage/external-link-types. The FIND_IN_SET-guarded
 *            CONCAT(...) shape below is a direct clone of
 *            migrate-musicians-rename.php Step 7 ("add 'musician' alongside
 *            the retained 'person' token"), the one other place this
 *            codebase widens this exact CSV column without stomping.
 *        6b. Backfill non-empty tblSongbooks.InternetArchiveUrl values into
 *            tblSongbookExternalLinks under the internet-archive type, via
 *            the same INSERT…SELECT…WHERE NOT EXISTS shape as
 *            migrate-backfill-songbook-links.php (that migration already
 *            does this exact mapping — WebsiteUrl/InternetArchiveUrl/
 *            WikipediaUrl → their three link types — this step is its
 *            InternetArchiveUrl-only twin, run again here so Feature 7 does
 *            not depend on operators having run that older card first).
 *            The InternetArchiveUrl COLUMN itself is deliberately NOT
 *            dropped — deprecate-in-place; per the plan a manual DROP is
 *            its own separate, later, explicitly-gated migration (rule #25
 *            C6 precedent).
 *
 * Every stage is individually guarded (existence probe before every ALTER /
 * data step), so a partial prior run — or an operator re-running the whole
 * card — resumes cleanly and a full re-run prints nothing but [SKIP] lines.
 *
 * @migration-adds tblSongbooks.IsDisabled
 * @migration-adds tblSongbooks.IsPublicDomain
 * @migration-adds tblSongbooks.OpenLibraryWorkId
 * @migration-adds tblSongbooks.OpenLibraryEditionId
 * @migration-adds tblSongbookSeries.Isbn
 * @migration-adds tblSongbookSeries.Issn
 * @migration-adds tblSongbookSeries.ArkId
 * @migration-adds tblSongbookSeries.OpenLibraryWorkId
 * @migration-adds tblSongbookSeries.OpenLibraryEditionId
 * @migration-adds tblCatalogues.ArkId
 * @migration-adds tblCatalogues.OpenLibraryWorkId
 * @migration-adds tblCatalogues.OpenLibraryEditionId
 *
 * SCHEMA MIRROR (rule #19): the 12 columns above are byte-identical twins
 * (including COMMENT text) of their appWeb/.sql/schema.sql declarations, at
 * the matching AFTER position. The Stage 4/5/6 seed + data steps have no
 * schema.sql mirror — link-type/pattern seed rows have never lived in
 * schema.sql (confirmed: grep finds no `INSERT INTO tblExternalLinkTypes` /
 * `tblExternalLinkPatterns` there); a fresh install gets them from THIS
 * migration (or a later one) being run against it, exactly like every other
 * seeded provider in the external-links family.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-publication-metadata.php
 *   Web:  /manage/setup-database → "Publication Metadata + Google Books +
 *         Internet Archive multiplicity (#1765)" button
 *
 * @see .claude/songbook-catalogue-enhancements-plan.md  the epic plan (migration section + "Feature 7")
 * @see appWeb/.sql/migrate-song-soft-delete.php          the structural clone target (CLI/dashboard gate,
 *                                                         per-column guarded ALTER stages, [SKIP]/[OK] transcript)
 * @see appWeb/.sql/migrate-external-links.php             Stage 4's upsert shape + AppliesTo-stomping caveat
 * @see appWeb/.sql/migrate-external-link-patterns.php     Stage 5's WHERE-NOT-EXISTS seed guard
 * @see appWeb/.sql/migrate-backfill-songbook-links.php     Stage 6b's INSERT…SELECT…WHERE NOT EXISTS shape
 * @see appWeb/.sql/migrate-musicians-rename.php            Stage 6a's FIND_IN_SET widen-without-stomping shape (Step 7)
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/** ELI5: prints a line of progress, plain text on the CLI, one <br>-terminated
 *  line on the web dashboard (flushed immediately so a slow migration still
 *  shows live progress rather than one blob at the end). */
function _migPubMeta_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

/** ELI5: "does this column already exist?" — read from INFORMATION_SCHEMA,
 *  never assumed, so a stage that already ran is a clean [SKIP] not a fatal
 *  "column already exists" SQL error. Table/column names are bound (rule
 *  #5), never interpolated. */
function _migPubMeta_columnExists(\mysqli $db, string $t, string $c): bool
{
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->bind_param('ss', $t, $c); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}

/** ELI5: "does this table exist yet?" — gates the Stage 4-6 seed/data steps,
 *  which all depend on the external-links family (#833/#845) already being
 *  applied. On an install where those migrations haven't run yet, these
 *  stages log a friendly [SKIP] rather than a hard fatal error, exactly the
 *  same posture migrate-backfill-songbook-links.php takes. */
function _migPubMeta_tableExists(\mysqli $db, string $t): bool
{
    $st = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $st->bind_param('s', $t); $st->execute();
    $ok = $st->get_result()->fetch_row() !== null; $st->close(); return $ok;
}

/** ELI5: "what row-id does the 'google-books'/'internet-archive' provider
 *  have?" — every per-entity link row stores a LinkTypeId, not the slug, so
 *  Stages 5/6 both need to resolve slug -> Id before they can insert/update
 *  anything. Returns null when the slug is not (yet) in the registry, which
 *  callers treat as "nothing to do this run" rather than a fatal error —
 *  the type row is created by Stage 4 in the SAME run, so the common path
 *  (fresh apply) always finds it; a partial/out-of-order apply just skips
 *  cleanly and self-heals next run. */
function _migPubMeta_linkTypeId(\mysqli $db, string $slug): ?int
{
    $st = $db->prepare('SELECT Id FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1');
    $st->bind_param('s', $slug); $st->execute();
    $row = $st->get_result()->fetch_row(); $st->close();
    return $row ? (int)$row[0] : null;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migPubMeta_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migPubMeta_out("");
_migPubMeta_out("=== iHymns — Publication metadata + Google Books + Internet Archive multiplicity (#1765) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migPubMeta_out("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migPubMeta_out("Connected to MySQL: " . DB_NAME);

try {
    /* ====================================================================
     * Stage 1 — tblSongbooks +4 (Features 1-3)
     *
     * IsDisabled / IsPublicDomain are booleans, so NOT NULL DEFAULT 0 — every
     * existing songbook is neither disabled nor flagged PD until a curator
     * says otherwise (fail-safe: DEFAULT 0 means "today's behaviour",
     * exactly like tblSongs.IsDeleted's precedent). OpenLibraryWorkId /
     * OpenLibraryEditionId are NULLable identifiers, mirroring the existing
     * #672 ArkId/Isbn columns on this same table — NULL, not empty string,
     * means "not recorded", the same trichotomy identifier_normalize.php's
     * doc-block establishes (commit 1).
     * ==================================================================== */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 1: tblSongbooks (Features 1-3) ---");
    $stage1 = [
        'IsDisabled' => "ALTER TABLE tblSongbooks
                ADD COLUMN IsDisabled TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Disable-a-songbook flag (Feature 1, epic #1765): 1 = hidden from every public read, still visible and editable under /manage; reversible, nothing deleted. Read-path enforcement lands in commit 3 (songbookVisibleSql()); this column is dormant until then; DEFAULT 0 keeps every existing songbook visible'
                AFTER IsOfficial",
        'IsPublicDomain' => "ALTER TABLE tblSongbooks
                ADD COLUMN IsPublicDomain TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Public-domain flag for the songbook as a published work (Feature 2, epic #1765) - informational only, never a content gate; mirrors the tblSongs LyricsPublicDomain / MusicPublicDomain precedent. DEFAULT 0 so no existing songbook is retroactively marked PD'
                AFTER IsDisabled",
        'OpenLibraryWorkId' => "ALTER TABLE tblSongbooks
                ADD COLUMN OpenLibraryWorkId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Work id, e.g. OL102749W (Feature 3, epic #1765; mirrors the #672 identifier-column pattern). Bare id form; validated by ihymns_canonical_openlibrary() in includes/identifier_normalize.php, kind=work'
                AFTER ArkId",
        'OpenLibraryEditionId' => "ALTER TABLE tblSongbooks
                ADD COLUMN OpenLibraryEditionId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Edition id, e.g. OL7357422M (Feature 3, epic #1765; mirrors the #672 identifier-column pattern). Bare id form; validated by ihymns_canonical_openlibrary() in includes/identifier_normalize.php, kind=edition'
                AFTER OpenLibraryWorkId",
    ];
    foreach ($stage1 as $col => $ddl) {
        if (_migPubMeta_columnExists($mysql, 'tblSongbooks', $col)) {
            _migPubMeta_out("  [SKIP] tblSongbooks.$col already present.");
        } else {
            $mysql->query($ddl);
            _migPubMeta_out("  [OK] Added tblSongbooks.$col.");
        }
    }

    /* ====================================================================
     * Stage 2 — tblSongbookSeries +5, chained AFTER Colour (Feature 3)
     *
     * Isbn/Issn appear on the SERIES but deliberately NOT on tblCatalogues
     * (Stage 3) — a series (Songs of Fellowship vol. 1/2/3) is commonly
     * itself catalogued with its own serial number; a curated collection is
     * not. See the plan's adversarial notes: "series 260$b / catalogue ISBN
     * deliberately absent... model as songbook" is the reasoning that
     * shaped this asymmetry, and it is why no second migration is expected
     * here (rule #20 — the shape was stress-tested up front).
     * ==================================================================== */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 2: tblSongbookSeries (Feature 3) ---");
    $stage2 = [
        'Isbn' => "ALTER TABLE tblSongbookSeries
                ADD COLUMN Isbn VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'ISBN for the series as a whole (Feature 3, epic #1765); mirrors tblSongbooks.Isbn (#672). Deliberately absent from tblCatalogues - see the plan adversarial notes section'
                AFTER Colour",
        'Issn' => "ALTER TABLE tblSongbookSeries
                ADD COLUMN Issn VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'ISSN for the series as a whole (Feature 3, epic #1765); a series is the one publication entity of the three that commonly carries a serial number. Deliberately absent from tblSongbooks and tblCatalogues'
                AFTER Isbn",
        'ArkId' => "ALTER TABLE tblSongbookSeries
                ADD COLUMN ArkId VARCHAR(80) NULL DEFAULT NULL
                COMMENT 'Archival Resource Key for the series (Feature 3, epic #1765); mirrors tblSongbooks.ArkId (#672)'
                AFTER Issn",
        'OpenLibraryWorkId' => "ALTER TABLE tblSongbookSeries
                ADD COLUMN OpenLibraryWorkId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Work id for the series, e.g. OL102749W (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryWorkId'
                AFTER ArkId",
        'OpenLibraryEditionId' => "ALTER TABLE tblSongbookSeries
                ADD COLUMN OpenLibraryEditionId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Edition id for the series, e.g. OL7357422M (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryEditionId'
                AFTER OpenLibraryWorkId",
    ];
    foreach ($stage2 as $col => $ddl) {
        if (_migPubMeta_columnExists($mysql, 'tblSongbookSeries', $col)) {
            _migPubMeta_out("  [SKIP] tblSongbookSeries.$col already present.");
        } else {
            $mysql->query($ddl);
            _migPubMeta_out("  [OK] Added tblSongbookSeries.$col.");
        }
    }

    /* ====================================================================
     * Stage 3 — tblCatalogues +3, chained AFTER Colour (Feature 3)
     *
     * No Isbn/Issn here — see the Stage 2 note above and the ArkId column
     * comment below for why.
     * ==================================================================== */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 3: tblCatalogues (Feature 3) ---");
    $stage3 = [
        'ArkId' => "ALTER TABLE tblCatalogues
                ADD COLUMN ArkId VARCHAR(80) NULL DEFAULT NULL
                COMMENT 'Archival Resource Key for the collection (Feature 3, epic #1765); mirrors tblSongbooks.ArkId (#672). No Isbn/Issn on this table - a curated collection (rule #24: catalogue internally, Collection in UI) is not itself a catalogued serial publication, see the plan adversarial notes section'
                AFTER Colour",
        'OpenLibraryWorkId' => "ALTER TABLE tblCatalogues
                ADD COLUMN OpenLibraryWorkId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Work id for the collection, e.g. OL102749W (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryWorkId'
                AFTER ArkId",
        'OpenLibraryEditionId' => "ALTER TABLE tblCatalogues
                ADD COLUMN OpenLibraryEditionId VARCHAR(20) NULL DEFAULT NULL
                COMMENT 'Open Library Edition id for the collection, e.g. OL7357422M (Feature 3, epic #1765); mirrors tblSongbooks.OpenLibraryEditionId'
                AFTER OpenLibraryWorkId",
    ];
    foreach ($stage3 as $col => $ddl) {
        if (_migPubMeta_columnExists($mysql, 'tblCatalogues', $col)) {
            _migPubMeta_out("  [SKIP] tblCatalogues.$col already present.");
        } else {
            $mysql->query($ddl);
            _migPubMeta_out("  [OK] Added tblCatalogues.$col.");
        }
    }

    /* ====================================================================
     * Stage 4 — Google Books external-link TYPE seed (Feature 4)
     *
     * Table-existence gated: on an install where migrate-external-links.php
     * (#833) hasn't run yet, this stage logs a friendly [SKIP] rather than a
     * fatal error — the same posture every other seed-only migration in
     * this family takes (migrate-extra-streaming-platforms.php,
     * migrate-media-database-providers.php, migrate-musicbrainz-style-
     * links.php, …).
     *
     * ON DUPLICATE KEY UPDATE deliberately narrower than
     * migrate-external-links.php's own upsert: only Name/Category/
     * IconClass/DisplayOrder refresh on a re-run. AppliesTo, AllowMultiple
     * and IsActive are curator-owned once the row exists — a re-run of THIS
     * migration must not silently undo a curator's "disable Google Books"
     * or "also allow it on works" edit via /manage/external-link-types. */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 4: Google Books link-type seed (Feature 4) ---");
    if (!_migPubMeta_tableExists($mysql, 'tblExternalLinkTypes')) {
        _migPubMeta_out("  [SKIP] tblExternalLinkTypes not present - run migrate-external-links.php first (#833).");
    } else {
        $slug = 'google-books';
        $name = 'Google Books';
        $cat  = 'read';
        $applies = 'songbook';
        $multi = 1;
        $icon = 'bi-google';
        $order = 32;
        /* One prepare, one bind_param call — 7 placeholders, types
           'ssssisi' (Slug s, Name s, Category s, AppliesTo s,
           AllowMultiple i, IconClass s, DisplayOrder i) — byte-identical to
           migrate-external-links.php's Step 2 upsert bind_param() call. The
           `@` mirrors that same model: it suppresses nothing real (a
           failing execute() still THROWS under the DB layer's
           MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT — error suppression
           cannot stop an exception), it is vestigial rather than
           load-bearing, kept only for parity with the model file. */
        $stmt = $mysql->prepare(
            "INSERT INTO tblExternalLinkTypes
                 (Slug, Name, Category, AppliesTo, AllowMultiple, IconClass, DisplayOrder)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 Name         = VALUES(Name),
                 Category     = VALUES(Category),
                 IconClass    = VALUES(IconClass),
                 DisplayOrder = VALUES(DisplayOrder)"
        );
        $stmt->bind_param('ssssisi', $slug, $name, $cat, $applies, $multi, $icon, $order);
        @$stmt->execute();
        $ar = $mysql->affected_rows;
        $stmt->close();
        if ($ar === 1) {
            _migPubMeta_out("  [OK] Inserted link type 'google-books'.");
        } elseif ($ar === 2) {
            _migPubMeta_out("  [OK] Updated link type 'google-books' (Name/Category/IconClass/DisplayOrder refreshed).");
        } else {
            _migPubMeta_out("  [SKIP] Link type 'google-books' already matches the seed.");
        }
    }

    /* ====================================================================
     * Stage 5 — Google Books URL pattern seed (Feature 4)
     *
     * Same INSERT…WHERE NOT EXISTS idempotency guard as
     * migrate-external-link-patterns.php (that table carries no UNIQUE key
     * — see that migration's own doc-block for why COALESCE(PathPrefix,'')
     * on both sides of the comparison is load-bearing, not decorative).
     * Two shapes seeded per Google's real per-country domain scheme,
     * mirroring the amazon-music / yandex-music multi-TLD precedent in that
     * same seed file:
     *   - books.google.<tld>  — exact host, the classic "books.google.com/
     *     books?id=…" URL. Priority 62.
     *   - google.<tld> + PathPrefix '/books/edition/', MatchSubdomains=1 —
     *     the newer "google.com/books/edition/…" URL shape (subdomain match
     *     so www.google.<tld> is covered by one row). Priority 63 (loses to
     *     the more specific books.google.<tld> rule on any URL that could
     *     match both, though in practice the two URL shapes do not
     *     overlap). */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 5: Google Books URL pattern seed (Feature 4) ---");
    if (!_migPubMeta_tableExists($mysql, 'tblExternalLinkPatterns')) {
        _migPubMeta_out("  [SKIP] tblExternalLinkPatterns not present - run migrate-external-link-patterns.php first (#845).");
    } else {
        $gbTypeId = _migPubMeta_linkTypeId($mysql, 'google-books');
        if ($gbTypeId === null) {
            _migPubMeta_out("  [SKIP] link type 'google-books' not in registry - re-run this migration after Stage 4 has applied.");
        } else {
            /* [host, path-prefix-or-null, match-subdomains, priority, note] */
            $patternRows = [
                ['books.google.com',    null, 0, 62, 'Google Books - US (primary)'],
                ['books.google.co.uk',  null, 0, 62, 'Google Books - UK'],
                ['books.google.de',     null, 0, 62, 'Google Books - Germany'],
                ['books.google.fr',     null, 0, 62, 'Google Books - France'],
                ['books.google.ca',     null, 0, 62, 'Google Books - Canada'],
                ['books.google.com.au', null, 0, 62, 'Google Books - Australia'],
                ['google.com',    '/books/edition/', 1, 63, 'Google Books "edition" URL shape (www.google.com/books/edition/...)'],
                ['google.co.uk',  '/books/edition/', 1, 63, 'Google Books "edition" URL shape - UK'],
                ['google.de',     '/books/edition/', 1, 63, 'Google Books "edition" URL shape - Germany'],
                ['google.fr',     '/books/edition/', 1, 63, 'Google Books "edition" URL shape - France'],
                ['google.ca',     '/books/edition/', 1, 63, 'Google Books "edition" URL shape - Canada'],
                ['google.com.au', '/books/edition/', 1, 63, 'Google Books "edition" URL shape - Australia'],
            ];
            $insert = $mysql->prepare(
                'INSERT INTO tblExternalLinkPatterns
                     (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, Note)
                 SELECT ?, ?, ?, ?, ?, ?
                   FROM DUAL
                  WHERE NOT EXISTS (
                        SELECT 1 FROM tblExternalLinkPatterns
                         WHERE LinkTypeId = ?
                           AND Host       = ?
                           AND COALESCE(PathPrefix, "") = COALESCE(?, "")
                  )'
            );
            $inserted = 0;
            $skipped  = 0;
            foreach ($patternRows as $r) {
                [$host, $path, $matchSd, $prio, $note] = $r;
                $insert->bind_param(
                    'issiisiss',
                    $gbTypeId, $host, $path, $matchSd, $prio, $note,
                    $gbTypeId, $host, $path
                );
                $insert->execute();
                if ($mysql->affected_rows > 0) { $inserted++; } else { $skipped++; }
            }
            $insert->close();
            _migPubMeta_out("  [OK] {$inserted} pattern" . ($inserted === 1 ? '' : 's') . " inserted, {$skipped} already present.");
        }
    }

    /* ====================================================================
     * Stage 6 — Feature 7: Internet Archive multiplicity
     * ==================================================================== */
    _migPubMeta_out("");
    _migPubMeta_out("--- Stage 6: Internet Archive multiplicity (Feature 7) ---");

    /* --- 6a. Widen 'internet-archive'.AppliesTo to include 'song' ---
       FIND_IN_SET-guarded so a re-run (or a curator who already added other
       tokens by hand) is a clean [SKIP], never a duplicate ",song,song".
       Works whether AppliesTo is still the original SET('song','songbook',
       'person') shape or the later VARCHAR CSV widening (#1741 P1/P2,
       migrate-musicians-rename.php) — FIND_IN_SET/CONCAT behave identically
       on both column types, so this needs no data-type probe of its own. */
    if (!_migPubMeta_tableExists($mysql, 'tblExternalLinkTypes')) {
        _migPubMeta_out("  [SKIP] tblExternalLinkTypes not present - nothing to widen.");
    } else {
        $iaSlug = 'internet-archive';
        $stmt = $mysql->prepare(
            "UPDATE tblExternalLinkTypes
                SET AppliesTo = CONCAT(AppliesTo, ',song')
              WHERE Slug = ?
                AND FIND_IN_SET('song', AppliesTo) = 0"
        );
        $stmt->bind_param('s', $iaSlug);
        $stmt->execute();
        $widened = $stmt->affected_rows;
        $stmt->close();
        if ($widened > 0) {
            _migPubMeta_out("  [OK] Widened 'internet-archive'.AppliesTo to include 'song'.");
        } else {
            _migPubMeta_out("  [SKIP] 'internet-archive'.AppliesTo already includes 'song' (or the type row does not exist yet).");
        }
    }

    /* --- 6b. Backfill tblSongbooks.InternetArchiveUrl -> tblSongbookExternalLinks ---
       Same INSERT…SELECT…WHERE NOT EXISTS shape as
       migrate-backfill-songbook-links.php's InternetArchiveUrl mapping —
       deliberately re-run here (rather than assumed-already-applied) so
       Feature 7 does not silently depend on operators having run that older
       card first. The column itself is NOT dropped (deprecate-in-place; a
       manual DROP is a separate, later, explicitly-gated migration). */
    if (!_migPubMeta_columnExists($mysql, 'tblSongbooks', 'InternetArchiveUrl')) {
        _migPubMeta_out("  [SKIP] tblSongbooks.InternetArchiveUrl not present - nothing to backfill.");
    } elseif (!_migPubMeta_tableExists($mysql, 'tblSongbookExternalLinks')) {
        _migPubMeta_out("  [SKIP] tblSongbookExternalLinks not present - run migrate-external-links.php first (#833).");
    } else {
        $iaTypeId = _migPubMeta_linkTypeId($mysql, 'internet-archive');
        if ($iaTypeId === null) {
            _migPubMeta_out("  [SKIP] link type 'internet-archive' not in registry - nothing to backfill against.");
        } else {
            $stmt = $mysql->prepare(
                "INSERT INTO tblSongbookExternalLinks
                     (SongbookId, LinkTypeId, Url)
                 SELECT b.Id, ?, b.InternetArchiveUrl
                   FROM tblSongbooks b
                  WHERE b.InternetArchiveUrl IS NOT NULL
                    AND LENGTH(TRIM(b.InternetArchiveUrl)) > 0
                    AND NOT EXISTS (
                          SELECT 1 FROM tblSongbookExternalLinks x
                           WHERE x.SongbookId = b.Id
                             AND x.LinkTypeId = ?
                             AND x.Url        = b.InternetArchiveUrl
                        )"
            );
            $stmt->bind_param('ii', $iaTypeId, $iaTypeId);
            $stmt->execute();
            $rows = $stmt->affected_rows;
            $stmt->close();
            if ($rows > 0) {
                _migPubMeta_out("  [OK] Backfilled {$rows} InternetArchiveUrl value" . ($rows === 1 ? '' : 's') . " into tblSongbookExternalLinks.");
            } else {
                _migPubMeta_out("  [SKIP] No InternetArchiveUrl values left to backfill (already on the new system).");
            }
        }
    }

    _migPubMeta_out("");
    _migPubMeta_out("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migPubMeta_out("ERROR: " . $e->getMessage());
}
