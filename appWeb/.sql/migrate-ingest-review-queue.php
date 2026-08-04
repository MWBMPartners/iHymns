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
 * NOT DESTRUCTIVE. Nothing here drops, renames, truncates or rewrites an
 * existing object — it only CREATEs two brand-new tables. There is therefore no
 * recovery path to document: the "undo" is DROP TABLE, and losing the tables
 * loses only queue state, never corpus data.
 *
 * HOW IDEMPOTENCY IS ACHIEVED (the case that matters — a re-run is normal):
 * each CREATE sits behind its own _migReviewQ_tableExists() probe, so a second
 * run is two SHOW TABLES queries and two [SKIP] lines. There is deliberately NO
 * `CREATE TABLE IF NOT EXISTS` here: the explicit probe is what lets the script
 * print [SKIP] vs [OK], which is the only feedback an operator gets on the
 * setup-database page (the script never rethrows — see the catch at the bottom).
 *
 * INTERACTION WITH THE SETUP-DASHBOARD PROBE: the registry probe for this card
 * is single-table — `!tableExists('tblLyricsReviewQueue')` (migration-registry
 * .php). That is safe ONLY because the queue is created SECOND: if the first
 * CREATE succeeds and the second throws, the queue is still absent, so the card
 * correctly stays pending and a re-run finishes the job. Reordering the two
 * CREATE blocks would silently turn the card green on a half-applied schema.
 *
 * OPERATOR VIEW: /manage/setup-database → card "Lyrics review queue + conflicts
 * (#1066)" → button "Run Lyrics Review Queue Migration". Output is the [OK] /
 * [SKIP] transcript below; a failure appears as an "[ERROR] …" line in that
 * transcript rather than an HTTP error, because the catch swallows the throw.
 * The card is the real status signal, not the transcript.
 *
 * SCHEMA MIRROR: both CREATE TABLE statements are mirrored verbatim in
 * appWeb/.sql/schema.sql (which a FRESH install reads instead of running
 * migrations). They must stay byte-identical — including every COMMENT '…'
 * string — or a fresh install lands structurally different from a migrated one
 * and the Schema Audit page (#518) reports the difference as drift nobody
 * introduced on purpose. See CLAUDE.md rule #19.
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

/* The idempotency gate. ELI5: "does this table already exist?" — if yes, the
   CREATE below is skipped and the whole run is a no-op.

   Caveat worth knowing before reusing this helper for a new table: the argument
   is a LIKE *pattern*, not a literal, so `_` matches any single character and
   `%` matches any run (https://dev.mysql.com/doc/refman/8.0/en/show-tables.html).
   Every table name in this migration is camelCase with no underscore, so the
   pattern is effectively a literal here — but a future `tbl_Foo` would match a
   sibling and produce a false [SKIP], i.e. a silently un-created table. Prefer
   the INFORMATION_SCHEMA form (see migrate-backfill-songbook-links.php) if a
   name ever contains a wildcard character. */
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
        /* Two data-shaping choices here that the DDL alone does not explain:

           1. ExistingData / IncomingData are JSON SNAPSHOTS, not FKs to the
              live rows. A moderator reviewing a conflict weeks later must see
              what the two sides looked like AT DETECTION TIME; re-reading the
              live tblSongs/tblLyrics would show whatever has since been edited,
              which makes the diff UI lie about what was actually in conflict.
              This is why the pair is NOT NULL — a conflict with no evidence is
              not reviewable.

           2. The three FKs deliberately use DIFFERENT delete actions. The song
              CASCADEs (a conflict about a deleted song is meaningless), the
              incoming lyrics SET NULL (the conflict record survives its source
              being purged, since the snapshot above still carries the evidence),
              and the resolver SET NULL (deleting a curator account must not
              erase the moderation history they produced). */
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
        /* `UNIQUE KEY uq_LyricsId (LyricsId)` is the load-bearing constraint of
           this table: ONE queue row per lyrics version. It makes the ingest
           write an upsert rather than an append, so a source that retries (or a
           curator who re-submits) cannot fan one item out into a queue full of
           duplicates a moderator then has to reject one at a time. It is also
           what lets the queue be re-driven safely from a batch job.

           SongId and Source are DENORMS of tblLyrics — carried here so the queue
           list view filters and sorts without joining tblLyrics for every row.
           They are written by the app, never by a trigger; the FK on SongId
           keeps the denorm honest against deletion, not against edits.

           Priority is a signed INT (not TINYINT) purely so the -1/0/+1 vocab in
           its COMMENT can widen later without an ALTER — the same growable-
           vocabulary instinct that makes QueuedReason / ReviewDecision VARCHAR
           rather than ENUM (CLAUDE.md rule #20).

           Note on idx_Priority (Priority, CreatedAt): it is ASC/ASC, while the
           documented queue order is `Priority DESC, CreatedAt ASC`. A mixed-
           direction sort cannot be satisfied by a forward or backward scan of a
           same-direction composite index, so this index prunes and groups but
           does not remove the filesort. MySQL 8.0 supports descending index
           parts (https://dev.mysql.com/doc/refman/8.0/en/descending-indexes.html)
           if that ever shows up in a slow query; changing it is a paired
           schema.sql + migration edit, not a local tweak. */
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
