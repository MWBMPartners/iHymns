<?php

declare(strict_types=1);

/**
 * iHymns — Lyrics review queue + conflicts (#1066 Theme B)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The write path into the canonical corpus (the timed-lyrics ingest endpoint
 * #1064 that MeedyaDL pushes to) has no moderation gate and no conflict capture:
 * an incoming version whose ISRC matches but whose lyrics/title differ would
 * silently clobber hand-curated data. This migration adds:
 *   - tblLyricsConflicts   — detected conflicts captured for moderator review.
 *   - tblLyricsReviewQueue  — moderation gate between ingest and the read path.
 *
 * Moderation-vocabulary columns are VARCHAR (not ENUM) so new resolution /
 * conflict / decision kinds need no future ALTER. ConflictGroupId is a SOFT
 * link to tblLyricsConflicts.GroupId (GroupId is non-unique → not a hard FK).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (table existence guarded).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-ingest-review-queue.php
 *   Web:  /manage/setup-database → "Lyrics review queue + conflicts" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migReviewQ_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migReviewQ_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migReviewQ_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migReviewQ_output("");
_migReviewQ_output("=== iHymns — Lyrics review queue + conflicts (#1066 Theme B) ===");
_migReviewQ_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migReviewQ_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migReviewQ_output("Connected to MySQL: " . DB_NAME);

try {
    _migReviewQ_output("");
    _migReviewQ_output("--- tblLyricsConflicts ---");
    if (_migReviewQ_tableExists($mysql, 'tblLyricsConflicts')) {
        _migReviewQ_output("  [SKIP] tblLyricsConflicts already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricsConflicts (
                Id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                GroupId          INT UNSIGNED NOT NULL COMMENT 'Conflict group; rows sharing GroupId form one detected conflict',
                SongId           VARCHAR(20)  NOT NULL COMMENT 'FK to tblSongs.SongId — the existing/curator version',
                IncomingLyricsId INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblLyrics.Id of the ingest source; NULL if curator-curator',
                IncomingSource   VARCHAR(100) NOT NULL COMMENT 'Source of incoming data, e.g. applemusic-ttml, user-submission',
                ConflictType     VARCHAR(30)  NOT NULL COMMENT 'lyrics_mismatch | isrc_mismatch | title_mismatch | artist_mismatch | partial_overlap (app-validated; VARCHAR so new kinds need no ALTER)',
                DescriptionText  TEXT         NOT NULL COMMENT 'Human-readable conflict summary',
                ExistingData     JSON         NOT NULL COMMENT 'Snapshot of current tblLyrics/tblSongs data for the diff UI',
                IncomingData     JSON         NOT NULL COMMENT 'Snapshot of the incoming ingest data',
                ResolutionAction VARCHAR(30)  NOT NULL DEFAULT 'unresolved' COMMENT 'unresolved | accept_incoming | keep_existing | manual_merge | deduplicate | escalate | split | defer (app-validated)',
                ResolvedBy       INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id of the resolver',
                ResolvedAt       DATETIME     NULL DEFAULT NULL,
                ResolveNote      TEXT         NULL DEFAULT NULL,
                CreatedAt        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_GroupId          (GroupId),
                INDEX idx_SongId           (SongId),
                INDEX idx_IncomingLyricsId (IncomingLyricsId),
                INDEX idx_ConflictType     (ConflictType),
                INDEX idx_ResolutionAction (ResolutionAction),

                CONSTRAINT fk_Conflict_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_Conflict_IncomingLyrics
                    FOREIGN KEY (IncomingLyricsId) REFERENCES tblLyrics(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_Conflict_Resolver
                    FOREIGN KEY (ResolvedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Ingest/curation conflicts queued for moderator resolution (#1066 Theme B).'"
        );
        _migReviewQ_output("  [OK] Created tblLyricsConflicts.");
    }

    _migReviewQ_output("");
    _migReviewQ_output("--- tblLyricsReviewQueue ---");
    if (_migReviewQ_tableExists($mysql, 'tblLyricsReviewQueue')) {
        _migReviewQ_output("  [SKIP] tblLyricsReviewQueue already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblLyricsReviewQueue (
                Id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                LyricsId        INT UNSIGNED  NOT NULL COMMENT 'FK to tblLyrics; cascade-deleted with the lyrics',
                SongId          VARCHAR(20)   NOT NULL COMMENT 'Denorm of tblLyrics.SongId for direct queue filtering',
                Source          VARCHAR(100)  NOT NULL COMMENT 'Denorm of tblLyrics.Source',
                SourceUrl       VARCHAR(1000) NULL DEFAULT NULL,
                Priority        INT           NOT NULL DEFAULT 0 COMMENT '-1 low / 0 normal / +1 high; queue sorts Priority DESC, CreatedAt ASC',
                ModerationNote  TEXT          NULL DEFAULT NULL,
                QueuedReason    VARCHAR(30)   NOT NULL DEFAULT 'curator_submitted' COMMENT 'curator_submitted | conflict_detected | data_quality_flag | manual_review (app-validated)',
                ConflictGroupId INT UNSIGNED  NULL DEFAULT NULL COMMENT 'Soft link to tblLyricsConflicts.GroupId (not a hard FK — GroupId is non-unique)',
                AssignedTo      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id who claimed this row (multi-curator concurrency)',
                AssignedAt      DATETIME      NULL DEFAULT NULL,
                ReviewedBy      INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id of reviewer',
                ReviewedAt      DATETIME      NULL DEFAULT NULL,
                ReviewDecision  VARCHAR(20)   NULL DEFAULT NULL COMMENT 'approved | rejected | needs_edits | deferred (app-validated)',
                ReviewNote      TEXT          NULL DEFAULT NULL,
                CreatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_LyricsId      (LyricsId),
                INDEX idx_SongId           (SongId),
                INDEX idx_ReviewDecision   (ReviewDecision),
                INDEX idx_Priority         (Priority, CreatedAt),
                INDEX idx_QueuedReason     (QueuedReason),
                INDEX idx_ConflictGroupId  (ConflictGroupId),
                INDEX idx_AssignedTo       (AssignedTo),

                CONSTRAINT fk_LyricsQueue_Lyrics
                    FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LyricsQueue_Song
                    FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_LyricsQueue_Assignee
                    FOREIGN KEY (AssignedTo) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_LyricsQueue_Reviewer
                    FOREIGN KEY (ReviewedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Moderation queue for ingested/submitted lyrics (#1066 Theme B).'"
        );
        _migReviewQ_output("  [OK] Created tblLyricsReviewQueue.");
    }

    _migReviewQ_output("");
    _migReviewQ_output("--- Summary ---");
    _migReviewQ_output("  Ingest can now route conflicting/low-trust lyrics to a moderation queue");
    _migReviewQ_output("  instead of silently overwriting curated data. UI lands in a follow-up.");
    _migReviewQ_output("");
    _migReviewQ_output("Migration complete.");
} catch (\Throwable $e) {
    _migReviewQ_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
