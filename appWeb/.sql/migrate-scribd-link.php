<?php

declare(strict_types=1);

/**
 * iHymns — Scribd External-Link Provider
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Scribd is a website where people upload documents — including scanned
 * sheet music and hymnal PDFs. This migration teaches iHymns about it: adds
 * "Scribd" to the list of outside places a song / songbook can link to
 * (`/manage/external-link-types`), and adds the web-address pattern that
 * makes pasting a Scribd link auto-pick "Scribd" in the picker instead of
 * defaulting to nothing.
 *
 * DETAILED / PURPOSE
 * ----------------------------------------------------------------------------
 * Owner directive (2026-08-25): add Scribd (scribd.com) as a recognised
 * external-link provider. It joins IMSLP / "Sheet music PDF" under the
 * `sheet-music` category (Scribd is where curators find scanned hymnal
 * pages and sheet-music PDFs that never made it to IMSLP) rather than
 * `read` (Internet Archive / Open Library, which are whole-book scans) or
 * `other` — its actual curator use in this codebase's context is documents
 * *of* a song or songbook, not a distinct new kind of resource.
 *
 * THIS IS A DATA MIGRATION, NOT DDL (rule #19's byte-identical schema.sql
 * mirror obligation does not apply here — there is no DDL to mirror). It
 * seeds one row into `tblExternalLinkTypes` and one row into
 * `tblExternalLinkPatterns`, both tables already created by
 * migrate-external-links.php (#833) and migrate-external-link-patterns.php
 * (#845). Same posture as the other supplementary provider migrations in
 * this family (migrate-musicbrainz-style-links.php,
 * migrate-worldcat-and-secondhandsongs.php, Stage 4/5 of
 * migrate-publication-metadata.php's Google Books addition) — this file
 * exists purely to bring already-deployed installs level; a fresh install
 * gets Scribd from THIS file too (it is not folded back into
 * migrate-external-links.php's own seed list — rule #20's "one-pass" is
 * about a feature's OWN schema family, not about every future vocabulary
 * addition being retrofitted into the original migration).
 *
 * URL SHAPES (rule #11/#12 — matched by HOST only, via the URL parser's
 * `hostname`, never by a substring test against the raw string, so a URL
 * that merely *mentions* "scribd" elsewhere — a query string, a redirect
 * target — can never trigger a false match):
 *   - https://www.scribd.com/document/<id>/<slug>   (current)
 *   - https://www.scribd.com/doc/<id>/<slug>        (legacy, still resolves)
 *   - https://www.scribd.com/presentation/<id>/...  (slide-deck content type)
 *   - https://scribd.com/... and https://<cc>.scribd.com/...  (bare / any
 *     localised subdomain, e.g. es.scribd.com, id.scribd.com)
 * All four shapes are the SAME provider, so — unlike the MusicBrainz
 * work/recording/artist rules, which discriminate providers by path — this
 * needs no `PathPrefix`: a single `scribd.com` host rule with
 * `MatchSubdomains = 1` (suffix match) covers every one of them, exactly
 * the "discogs" / "bandcamp" single-bare-domain-suffix pattern already in
 * migrate-external-link-patterns.php.
 *
 * NOT DESTRUCTIVE — no DELETE, no DROP, no ALTER.
 *
 * IDEMPOTENCY — two different mechanisms, matching
 * migrate-publication-metadata.php Stage 4/5 (the most recent precedent in
 * this family) rather than the older migrate-musicbrainz-style-links.php
 * shape:
 *   - The link TYPE upserts by the `Slug` unique key, but the ON DUPLICATE
 *     KEY UPDATE clause deliberately refreshes only Name / Category /
 *     IconClass / DisplayOrder. AppliesTo / AllowMultiple / IsActive are
 *     curator territory once the row exists (a curator may tick "also
 *     applies to musicians" or deactivate it via
 *     /manage/external-link-types) — re-running this migration must not
 *     silently stomp that edit back to the seed default.
 *   - The URL PATTERN uses INSERT … WHERE NOT EXISTS, because
 *     tblExternalLinkPatterns carries no UNIQUE key (curators may
 *     legitimately want two rows differing only by Note/Priority). See
 *     migrate-external-link-patterns.php's own doc-block for why
 *     `COALESCE(PathPrefix, "")` on both sides of the comparison is
 *     load-bearing rather than decorative (SQL's `NULL = NULL` is NULL,
 *     not TRUE — https://dev.mysql.com/doc/refman/8.0/en/working-with-null.html).
 *
 * Rule #41 note: this script needs NO shared `includes/` file — connects
 * to MySQL directly from the `.auth/db_credentials.php` file, the same
 * shape migrate-org-brand-colour.php (#1840) and
 * migrate-publication-metadata.php (#1765) use. There is nothing under
 * `/public_html/` this file reaches into, so the `IHYMNS_INCLUDES_DIR`
 * idiom does not arise here and there is no renamed-docroot hazard to
 * guard against.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-scribd-link.php
 *   Web: /manage/setup-database → "Scribd external-link provider"
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/.sql/migrate-external-links.php             Step 2's upsert shape (the model this narrows, per Stage 4's note)
 * @see appWeb/.sql/migrate-external-link-patterns.php     Step 2's INSERT…WHERE NOT EXISTS pattern-seed shape
 * @see appWeb/.sql/migrate-publication-metadata.php       Stage 4/5 — the closest sibling (single new provider + patterns)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* ELI5: prints one line of progress — plain text on the CLI, one
 * <br>-terminated line on the web dashboard (flushed immediately so a
 * slow migration still shows live progress rather than one blob at the
 * end). Mirrors every other migration's `_out()` helper in this family. */
function _migScribd_out(string $m): void
{
    global $isCli;
    echo $m . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) {
        flush();
    }
}

/* ELI5: "does this table already exist?" — read from INFORMATION_SCHEMA,
 * never assumed, so a run against an install that hasn't applied #833/#845
 * yet is a friendly [SKIP] rather than a fatal "table doesn't exist" SQL
 * error. Table names are bound (rule #5), never interpolated. */
function _migScribd_tableExists(\mysqli $db, string $t): bool
{
    $st = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $st->bind_param('s', $t);
    $st->execute();
    $ok = $st->get_result()->fetch_row() !== null;
    $st->close();
    return $ok;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migScribd_out('ERROR: MySQL credentials not found. Run install.php first.');
    return;
}
require_once $credFile;

_migScribd_out('');
_migScribd_out('=== iHymns — Scribd external-link provider ===');
_migScribd_out('');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migScribd_out('ERROR: MySQL connection failed: ' . $e->getMessage());
    return;
}
_migScribd_out('Connected to MySQL: ' . DB_NAME);

try {
    /* ====================================================================
     * Step 1 — Scribd link-type seed
     *
     * Table-existence gated: on an install where migrate-external-links.php
     * (#833) hasn't run yet, this logs a friendly [SKIP] rather than a
     * fatal error — the same posture every other seed-only migration in
     * this family takes.
     * ==================================================================== */
    _migScribd_out('--- Step 1: Scribd link-type seed ---');
    if (!_migScribd_tableExists($mysql, 'tblExternalLinkTypes')) {
        _migScribd_out('  [SKIP] tblExternalLinkTypes not present — run migrate-external-links.php first (#833).');
    } else {
        $slug    = 'scribd';
        $name    = 'Scribd';
        /* 'sheet-music' — same shelf as IMSLP + "Sheet music PDF": what a
           curator is actually looking for on Scribd, for iHymns purposes,
           is a scanned hymnal page or sheet-music PDF, not a generic
           document. */
        $cat     = 'sheet-music';
        /* AppliesTo is VARCHAR(255) (rule #20 — widened away from its
           original SET by migrate-musicians-rename.php / #1741), so this
           default is not a "second migration" risk: a curator can tick
           more entity types later via /manage/external-link-types' AppliesTo
           UI with no ALTER involved. 'song,songbook' mirrors the closest
           existing sibling with the same real-world usage shape
           (Internet Archive: whole-songbook scans as well as individual
           song pages). */
        $applies = 'song,songbook';
        $multi   = 1; /* a song can reasonably have more than one Scribd upload (different scans/arrangements) */
        /* Bootstrap Icons 'file-earmark-text' — a plain document glyph,
           deliberately distinct from imslp/sheet-music-pdf's 'bi-file-music'
           note glyph, since Scribd is a general document host rather than a
           music-notation-specific one. https://icons.getbootstrap.com/icons/file-earmark-text/ */
        $icon    = 'bi-file-earmark-text';
        /* DisplayOrder within the 'sheet-music' category: imslp=60,
           sheet-music-pdf=61, so 62 is the next open slot in that band. */
        $order   = 62;

        /* Narrower ON DUPLICATE KEY UPDATE than migrate-external-links.php's
           own upsert (Stage 4 precedent in migrate-publication-metadata.php):
           AppliesTo / AllowMultiple / IsActive are curator-owned once the
           row exists, so a re-run of THIS migration must not silently undo
           a curator's "also allow on works" or "deactivate" edit. */
        $stmt = $mysql->prepare(
            'INSERT INTO tblExternalLinkTypes
                 (Slug, Name, Category, AppliesTo, AllowMultiple, IconClass, DisplayOrder)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 Name         = VALUES(Name),
                 Category     = VALUES(Category),
                 IconClass    = VALUES(IconClass),
                 DisplayOrder = VALUES(DisplayOrder)'
        );
        $stmt->bind_param('ssssisi', $slug, $name, $cat, $applies, $multi, $icon, $order);
        /* The `@` is vestigial, same note as every sibling migration: the DB
           layer runs MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so a
           genuinely failing execute() still THROWS regardless of the `@` —
           error suppression cannot stop an exception. Kept only for parity
           with the model files. */
        @$stmt->execute();
        $ar = $mysql->affected_rows;
        $stmt->close();
        if ($ar === 1) {
            _migScribd_out("  [OK] Inserted link type 'scribd'.");
        } elseif ($ar === 2) {
            _migScribd_out("  [OK] Updated link type 'scribd' (Name/Category/IconClass/DisplayOrder refreshed).");
        } else {
            _migScribd_out("  [SKIP] Link type 'scribd' already matches the seed.");
        }
    }

    /* ====================================================================
     * Step 2 — Scribd URL pattern seed
     *
     * One host-only rule with MatchSubdomains = 1 (suffix match) covers
     * every real Scribd URL shape (/document/, /doc/, /presentation/, any
     * localised subdomain) — see the doc-block above for why no
     * PathPrefix discrimination is needed here, unlike MusicBrainz.
     * ==================================================================== */
    _migScribd_out('');
    _migScribd_out('--- Step 2: Scribd URL pattern seed ---');
    if (!_migScribd_tableExists($mysql, 'tblExternalLinkPatterns')) {
        _migScribd_out('  [SKIP] tblExternalLinkPatterns not present — run migrate-external-link-patterns.php first (#845).');
    } else {
        $typeId = null;
        $st = $mysql->prepare('SELECT Id FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1');
        $slugLookup = 'scribd';
        $st->bind_param('s', $slugLookup);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        if ($row) {
            $typeId = (int)$row[0];
        }

        if ($typeId === null) {
            _migScribd_out("  [SKIP] link type 'scribd' not in registry — re-run this migration after Step 1 has applied.");
        } else {
            $host    = 'scribd.com';
            $path    = null; /* every content-type path (/document/, /doc/, /presentation/) is the same provider */
            $matchSd = 1;    /* suffix match — covers www.scribd.com, es.scribd.com, id.scribd.com, … */
            $prio    = 91;   /* next open slot after imslp's 90 in the sheet-music priority band */
            $note    = 'Scribd document/sheet-music host — matches /document/, /doc/ and /presentation/ URLs on any subdomain';

            /* INSERT … WHERE NOT EXISTS, same idempotency guard as
               migrate-external-link-patterns.php's own seed (that table has
               no UNIQUE key to upsert on). COALESCE folds NULL to '' on both
               sides because SQL's `NULL = NULL` is NULL, not TRUE — without
               it, this NULL PathPrefix row would never recognise its own
               existing copy and every re-run would insert a duplicate. */
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
            $insert->bind_param(
                'issiisiss',
                $typeId, $host, $path, $matchSd, $prio, $note,
                $typeId, $host, $path
            );
            $insert->execute();
            if ($mysql->affected_rows > 0) {
                _migScribd_out('  [OK] 1 pattern inserted.');
            } else {
                _migScribd_out('  [SKIP] Pattern already present.');
            }
            $insert->close();
        }
    }

    _migScribd_out('');
    _migScribd_out('=== Done. Scribd is registered as an external-link provider. ===');
} catch (\mysqli_sql_exception $e) {
    _migScribd_out('ERROR: migration failed: ' . $e->getMessage());
    return;
}
