<?php

declare(strict_types=1);

/**
 * iHymns — Internet Archive OCR reconcile audit tables (#94 Phase 1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The #94 Phase 1 read-only archive.org OCR reconcile/audit tool
 * (`/manage/ia-reconcile`) needs two additive, dormant tables — pure audit
 * bookkeeping, never song content (see the CI guard,
 * tests/php/test-ia-reconcile-guards.php, which mechanically bans any write
 * statement against tblSongs/tblSongComponents/tblLyricLines/tblLyrics/
 * tblSongbooks from the new files):
 *   - tblIaFetchCache       — caches fetched IA artefacts (metadata JSON /
 *                             OCR full-text) so repeat audits are instant
 *                             and courteous to archive.org.
 *   - tblIaImportCandidates — one row per segmented OCR candidate per
 *                             (item x target songbook), with its reconcile
 *                             verdict + a DORMANT Phase-2 review-state
 *                             vocabulary (Phase 1 only ever writes
 *                             ReviewState='none').
 *
 * Every vocabulary column (Kind, Verdict, ReviewState) is VARCHAR, not
 * ENUM — a growable vocabulary needs an app-level allow-list add, never an
 * ALTER (CLAUDE.md rule #20). FetchedAt is DATETIME, not TIMESTAMP (TTL
 * semantics, same rule).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table existence guarded).
 *
 * NOT DESTRUCTIVE. Nothing here drops, renames, truncates or rewrites an
 * existing object — it only CREATEs two brand-new tables holding audit
 * bookkeeping. The "undo" is DROP TABLE; losing them loses only cached
 * fetches + segmentation results, never corpus data.
 *
 * HOW IDEMPOTENCY IS ACHIEVED: each CREATE sits behind its own
 * `_migIaRec_tableExists()` probe (deliberately NOT `CREATE TABLE IF NOT
 * EXISTS` — the explicit probe is what lets the script print [SKIP] vs
 * [OK], the only feedback an operator gets on the setup-database page; the
 * script never rethrows — see the catch at the bottom).
 *
 * ORDERING (load-bearing): `tblIaFetchCache` is created FIRST,
 * `tblIaImportCandidates` SECOND. The registry probe for this card
 * (manage/includes/migration-registry.php, slug 'ia-reconcile') is a
 * multi-object OR-probe over BOTH tables, but a single-table probe checking
 * only the SECOND table would already be safe for the identical reason
 * `migrate-ingest-review-queue.php`'s header documents: if the first CREATE
 * succeeds and the second throws, the second table is still absent, so the
 * card correctly stays pending and a re-run finishes the job. Reordering
 * the two CREATE blocks would silently let a half-applied run report done.
 *
 * SCHEMA MIRROR: both CREATE TABLE statements are mirrored BYTE-IDENTICALLY
 * (including every COMMENT '...' string) in appWeb/.sql/schema.sql, same
 * commit — CLAUDE.md rule #19; tests/php/test-schema-coverage.php enforces
 * the migrations-are-a-subset-of-schema.sql direction.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-ia-reconcile.php
 *   Web:  /manage/setup-database -> "Run IA Reconcile Migration" button
 *
 * @see .claude/ia-ocr-94-plan.md §3  the full design this migration implements
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migIaRec_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* The idempotency gate. Every table name here is camelCase with no
   underscore, so the LIKE pattern is effectively a literal — see
   migrate-ingest-review-queue.php's identical caveat for why a future
   underscored name should prefer the INFORMATION_SCHEMA form instead. */
function _migIaRec_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migIaRec_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migIaRec_output("");
_migIaRec_output("=== iHymns — Internet Archive OCR reconcile audit tables (#94 Phase 1) ===");
_migIaRec_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migIaRec_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migIaRec_output("Connected to MySQL: " . DB_NAME);

try {
    _migIaRec_output("");
    _migIaRec_output("--- tblIaFetchCache ---");
    if (_migIaRec_tableExists($mysql, 'tblIaFetchCache')) {
        _migIaRec_output("  [SKIP] tblIaFetchCache already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblIaFetchCache (
                Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                Identifier  VARCHAR(120)    NOT NULL COMMENT 'archive.org item identifier (their rules: 1-100 chars [A-Za-z0-9._-], starts alphanumeric; validated by iaIdentifierValid())',
                Kind        VARCHAR(20)     NOT NULL COMMENT 'metadata | fulltext (app-validated VARCHAR vocabulary, never ENUM — rule #20)',
                FileName    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Source file within the item for fulltext rows (e.g. <id>_djvu.txt); empty string (not NULL) for metadata rows so uq_Item_Kind_File stays a real uniqueness',
                HttpStatus  SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last HTTP status observed for this artefact (diagnostic only)',
                ByteSize    INT UNSIGNED    NOT NULL DEFAULT 0,
                Sha256      CHAR(64)        NULL DEFAULT NULL COMMENT 'sha256 of Payload — the reconcile RunSha for fulltext rows',
                Payload     MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'The cached artefact bytes (UTF-8-sanitised before insert). NULL = a fetch was attempted but never succeeded; a failed refetch NEVER overwrites a previous good payload',
                FetchedAt   DATETIME        NULL DEFAULT NULL COMMENT 'UTC time of the last SUCCESSFUL fetch; NULL = never succeeded. DATETIME not TIMESTAMP (rule #20 TTL semantics)',
                MetaJson    JSON            NULL DEFAULT NULL COMMENT 'Forward-looking per-row facts (rule #20, the #1590 Capabilities-JSON precedent) so an unforeseen bookkeeping need lands without ALTER',
                CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Item_Kind_File (Identifier, Kind, FileName),
                INDEX idx_FetchedAt (FetchedAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Cached archive.org fetch artefacts for the #94 OCR reconcile audit.'"
        );
        _migIaRec_output("  [OK] Created tblIaFetchCache.");
    }

    _migIaRec_output("");
    _migIaRec_output("--- tblIaImportCandidates ---");
    if (_migIaRec_tableExists($mysql, 'tblIaImportCandidates')) {
        _migIaRec_output("  [SKIP] tblIaImportCandidates already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblIaImportCandidates (
                Id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                Identifier         VARCHAR(120)  NOT NULL COMMENT 'archive.org item identifier this candidate was segmented from',
                SongbookAbbr       VARCHAR(10)   NOT NULL COMMENT 'Target songbook (tblSongbooks.Abbreviation charset) the audit compared against — soft reference, no FK',
                RunSha             CHAR(64)      NOT NULL COMMENT 'sha256 of the fulltext payload this run segmented (= tblIaFetchCache.Sha256); rows with a stale RunSha and ReviewState=none are pruned on the next run',
                SegmentIndex       INT UNSIGNED  NOT NULL COMMENT 'Position of this candidate within its run (display order only — NOT part of the upsert key)',
                SegmentFingerprint CHAR(64)      NOT NULL COMMENT 'sha256(NormTitle + \\n + NormFirstLine) — the stable identity of a candidate across re-segmentations; the upsert key',
                HeadingNumber      VARCHAR(20)   NULL DEFAULT NULL COMMENT 'Hymn number as printed in the scan (VARCHAR: 123, 123a, App.7)',
                PageGuess          INT UNSIGNED  NULL DEFAULT NULL COMMENT '1-based page ordinal when the OCR carried form-feed page breaks; NULL otherwise',
                RawTitle           VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Title line as OCRed (untrusted text — HTML-escape on render)',
                NormTitle          VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'ihymns_normalize_title() fold of RawTitle (the EXACT dedup fold — same fold as tblSongs.NormalizedTitle)',
                FirstLine          VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'First lyric-looking line of the candidate body',
                BodyExcerpt        TEXT          NULL DEFAULT NULL COMMENT 'First ~1000 chars of the candidate body for curator eyeballing. NEVER imported in Phase 1',
                LineStart          INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '0-based line offsets of the segment within the (normalised) fulltext',
                LineEnd            INT UNSIGNED  NOT NULL DEFAULT 0,
                BestSongId         VARCHAR(20)   NULL DEFAULT NULL COMMENT 'tblSongs.SongId of the best-scoring match; NULL when the verdict is gap/unscorable. SOFT reference, no FK',
                BestScore          DECIMAL(4,3)  NULL DEFAULT NULL COMMENT 'Adjusted composite in [0,1] (ihymns_sim_score() blend rescaled for the absent-authors signal — see includes/ia_reconcile.php iaRecAdjustedScore())',
                Verdict            VARCHAR(20)   NOT NULL COMMENT 'exact | strong | review | gap | unscorable (app-validated VARCHAR vocabulary, never ENUM — rule #20)',
                ReviewState        VARCHAR(20)   NOT NULL DEFAULT 'none' COMMENT 'DORMANT Phase-2 vocabulary: none | approved_for_import | dismissed | imported (app-validated; Phase 1 only ever writes none)',
                ReviewedBy         INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id (dormant until Phase 2)',
                ReviewedAt         DATETIME      NULL DEFAULT NULL,
                ReviewNote         TEXT          NULL DEFAULT NULL,
                MetaJson           JSON          NULL DEFAULT NULL COMMENT 'Forward-looking per-row facts (rule #20) — e.g. a later per-line OCR-confidence map — so no second ALTER',
                CreatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_Item_Book_Fingerprint (Identifier, SongbookAbbr, SegmentFingerprint),
                INDEX idx_Book_Verdict (SongbookAbbr, Verdict),
                INDEX idx_Item_Run (Identifier, RunSha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Segmented OCR candidates + reconcile verdicts for the #94 audit; Phase-2 import queue (dormant).'"
        );
        _migIaRec_output("  [OK] Created tblIaImportCandidates.");
    }

    _migIaRec_output("");
    _migIaRec_output("--- Summary ---");
    _migIaRec_output("  /manage/ia-reconcile can now cache archive.org fetches and persist");
    _migIaRec_output("  segmented OCR candidates + verdicts across runs. Read-only audit —");
    _migIaRec_output("  no song-content table is touched by this migration or its consumers.");
    _migIaRec_output("");
    _migIaRec_output("Migration complete.");
} catch (\Throwable $e) {
    _migIaRec_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
