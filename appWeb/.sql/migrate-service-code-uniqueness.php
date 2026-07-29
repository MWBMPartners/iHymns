<?php

declare(strict_types=1);

/**
 * iHymns — Globally-unique LIVE service join codes (#1621, epic #1323)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Make a LIVE Service-Mode join code unique across the WHOLE install, enforced
 * by the database, so two concurrent services can never hold the same code.
 *
 * ELI5: congregants join a service by typing the short code shown on the venue
 * screen. Until now two different churches' services could, in principle, be
 * showing the SAME code at the same time, and the server just handed the
 * congregant whichever service had checked in most recently — possibly the
 * wrong church's. This teaches the database to refuse to store that situation
 * at all.
 *
 * DETAILED — WHY THE OLD CONSTRAINT DID NOTHING USEFUL. tblLiveFollowJoinCodes
 * shipped with `UNIQUE KEY uq_Session_Code (SessionId, Code)`, which is
 * SESSION-scoped: it only prevents ONE session reusing its OWN past code. The
 * risk worth constraining — two DIFFERENT sessions holding one code — was
 * unconstrained, and serviceMode_resolveJoin() resolved the clash with
 * `ORDER BY s.LastHeartbeatAt DESC LIMIT 1`, i.e. it silently picked a winner.
 * The blast radius is bigger than a wrong song list: the presence token minted
 * on join is the Phase-3 CCLI key (serviceMode_presenceCcliNumber()), so a
 * mis-resolved join hands a congregant another organisation's licence unlock.
 *
 * THE IDIOM. MySQL has no filtered/partial indexes, so "unique among live rows
 * only" is expressed as a STORED generated column that equals `Code` while the
 * row is live and is NULL otherwise, plus a UNIQUE key on it — MySQL treats
 * multiple NULLs as distinct, so any number of retired rows coexist while live
 * ones cannot collide:
 *
 *     ActiveCode = CASE WHEN Status IN ('current','previous') THEN Code ELSE NULL END
 *
 * The status list is POSITIVE on purpose: any future retired-state value is
 * excluded automatically, without amending the expression.
 *
 * SCOPE = GLOBAL, NOT PER-CHANNEL. `Channel` (the 3-docroot alpha/beta/prod
 * discriminator) lives on tblLiveFollowSessions, not here, and a generated
 * column may only reference columns of its OWN row — so per-channel uniqueness
 * would mean denormalising Channel onto this table and keeping two copies in
 * step. Global is simpler, has no denormalisation to drift, and is strictly
 * stronger. It costs nothing: occupancy is ~2 live codes per CONCURRENTLY
 * running service against a 30^6 ≈ 7.29e8 space, so even 100,000 simultaneous
 * services worldwide would occupy 0.03% of it.
 *
 * EXPIRY IS NOT — AND CANNOT BE — PART OF THE EXPRESSION. A generated column
 * must be deterministic; `UTC_TIMESTAMP()` raises
 * ER_GENERATED_COLUMN_FUNCTION_IS_NOT_ALLOWED. So a row past its `ExpiresAt` is
 * already un-joinable yet still holds its unique slot until its Status moves.
 * That is why this migration ships alongside code changes that RETIRE codes
 * (session end, session supersede, and an opportunistic expiry pass inside
 * serviceMode_mintCode()) — without them, occupancy would track cumulative
 * history instead of concurrency and the index would grow without bound. To be
 * clear about proportion: that is a correctness and index-growth problem, never
 * an exhaustion one.
 *
 * NOTHING HERE DELETES A ROW. Retiring a code is a Status transition only; the
 * row and the Code it carried are kept for audit. `Status` also grows a fourth
 * value in this migration — 'expired' (session ended / ExpiresAt passed) as
 * distinct from 'superseded' (rotated out by the next code) — which is a
 * one-line vocabulary change precisely because rule #20 made the column a
 * VARCHAR rather than an ENUM.
 *
 * PRE-EXISTING DUPLICATES. This runs against a live shared database that may
 * already hold two live rows with one code; the ALTER would then die with a raw
 * 1062. Step 2 below retires expired live rows, step 3 resolves anything still
 * duplicated by keeping the OLDEST claimant (lowest Id = earliest issued) and
 * retiring the rest. Oldest-wins is chosen over freshest-heartbeat because it is
 * deterministic and stable across a re-run (heartbeats move between the SELECT
 * and the UPDATE), and because the older row is the one whose congregants are
 * most likely mid-join; a retired loser's session simply mints a fresh, unique
 * code at its next ~30 s rotate.
 *
 * Additive, idempotent (column/index/comment guarded) and safe to re-run.
 * Runs AFTER migrate-service-mode-sessions.php (which creates the table).
 *
 * @migration-adds tblLiveFollowJoinCodes.ActiveCode
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-service-code-uniqueness.php
 *   Web:  /manage/setup-database → "Globally-unique service join codes" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html
 * @see https://dev.mysql.com/doc/refman/8.0/en/create-index.html (NULLs are distinct in a UNIQUE index)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSCU_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSCU_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}
function _migSCU_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}
function _migSCU_indexExists(\mysqli $db, string $t, string $i): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $i);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}
/** Current COLUMN_COMMENT for $t.$c ('' when the column or comment is absent). */
function _migSCU_columnComment(\mysqli $db, string $t, string $c): string
{
    $stmt = $db->prepare(
        "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($row['COLUMN_COMMENT'] ?? '');
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSCU_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSCU_output("");
_migSCU_output("=== iHymns — Globally-unique LIVE service join codes (#1621) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSCU_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSCU_output("Connected to MySQL: " . DB_NAME);

/* The Status column comment, kept byte-identical with schema.sql and with
   migrate-service-mode-sessions.php's CREATE TABLE (rule #19) so a fresh
   install and a migrated install are structurally the same. */
$statusComment = 'current | previous | superseded | expired — app-validated, VARCHAR not ENUM (rule #20). superseded = rotated out by the next code; expired = the session ended or ExpiresAt passed. Retired rows are KEPT, never deleted (#1621)';

try {
    /* ------------------------------------------------------------------ *
     * (0) Guard — the table must already exist. Migrations are web-run,
     *     not auto-applied on deploy, so "it's in schema.sql" is never
     *     proof it exists on this install (rule #19 / project-rules §9).
     * ------------------------------------------------------------------ */
    if (!_migSCU_tableExists($mysql, 'tblLiveFollowJoinCodes')) {
        _migSCU_output("  [SKIP] tblLiveFollowJoinCodes not present — run the 'Service Mode sessions (#1335)' migration first.");
        _migSCU_output("Migration complete (nothing to do).");
        return;
    }

    $hasColumn = _migSCU_columnExists($mysql, 'tblLiveFollowJoinCodes', 'ActiveCode');
    $hasUnique = _migSCU_indexExists($mysql, 'tblLiveFollowJoinCodes', 'uq_ActiveCode');
    $hasLiveIx = _migSCU_indexExists($mysql, 'tblLiveFollowJoinCodes', 'idx_Live_Expiry');

    /* ------------------------------------------------------------------ *
     * (1) Status vocabulary — document 'expired' at the DB level. Done
     *     BEFORE the generated column is added: ActiveCode depends on
     *     Status, and modifying a depended-upon column is simplest while
     *     nothing depends on it. Comment-only change → ALGORITHM=INPLACE.
     * ------------------------------------------------------------------ */
    if (_migSCU_columnComment($mysql, 'tblLiveFollowJoinCodes', 'Status') === $statusComment) {
        _migSCU_output("  [SKIP] Status vocabulary comment already current.");
    } else {
        $mysql->query(
            "ALTER TABLE tblLiveFollowJoinCodes
                MODIFY Status VARCHAR(20) NOT NULL DEFAULT 'current' COMMENT '" . $mysql->real_escape_string($statusComment) . "'"
        );
        _migSCU_output("  [OK] Status vocabulary documented (adds 'expired' alongside 'superseded').");
    }

    /* ------------------------------------------------------------------ *
     * (2) Retire live rows whose ExpiresAt has already passed. These are
     *     un-joinable already (every join predicate compares ExpiresAt),
     *     so this changes no behaviour — it just stops dead rows claiming
     *     a unique slot, and removes most of the duplicate risk in (3)
     *     before it can fail the ALTER.
     *
     *     CHANNEL-AGNOSTIC on purpose, and ONLY here: the runtime
     *     retirement paths are strictly channel-filtered (rule #26)
     *     because a request handler must never touch another env's rows,
     *     but a DDL migration operates on the whole shared database by
     *     definition — a global UNIQUE index cannot be made to land while
     *     one channel's stale duplicates are excluded. It exposes nothing:
     *     the rows are already expired and the change is a status flag.
     * ------------------------------------------------------------------ */
    if ($hasColumn && $hasUnique) {
        _migSCU_output("  [SKIP] Live-code uniqueness already enforced — no pre-flight needed.");
    } else {
        $mysql->query(
            "UPDATE tblLiveFollowJoinCodes
                SET Status = 'expired'
              WHERE Status IN ('current', 'previous')
                AND ExpiresAt < UTC_TIMESTAMP()"
        );
        _migSCU_output("  [OK] Retired " . $mysql->affected_rows . " expired live code(s) (status only — no row deleted).");

        /* -------------------------------------------------------------- *
         * (3) Resolve any remaining duplicate live codes: oldest claimant
         *     (lowest Id = earliest issued) keeps the code, the rest are
         *     retired as 'superseded' (they WERE superseded — by another
         *     holder of the same code — rather than timed out).
         *
         *     Deliberately a SELECT-then-UPDATE per duplicate rather than
         *     one self-joined UPDATE: MySQL refuses to update a table that
         *     a subquery also selects FROM (ER_UPDATE_TABLE_USED), and
         *     derived-table workarounds behave differently between 5.7
         *     (materialised) and 8.0 (merged). Volumes here are tiny —
         *     duplicates are expected to be zero.
         * -------------------------------------------------------------- */
        $dupRes = $mysql->query(
            "SELECT Code FROM tblLiveFollowJoinCodes
              WHERE Status IN ('current', 'previous')
              GROUP BY Code HAVING COUNT(*) > 1"
        );
        $dupCodes = [];
        while ($dupRes && ($r = $dupRes->fetch_assoc())) { $dupCodes[] = (string)$r['Code']; }
        if ($dupRes) { $dupRes->free(); }

        if (!$dupCodes) {
            _migSCU_output("  [OK] No duplicate live codes to resolve.");
        } else {
            $keepStmt   = $mysql->prepare("SELECT MIN(Id) AS KeepId FROM tblLiveFollowJoinCodes WHERE Code = ? AND Status IN ('current', 'previous')");
            $retireStmt = $mysql->prepare("UPDATE tblLiveFollowJoinCodes SET Status = 'superseded' WHERE Code = ? AND Status IN ('current', 'previous') AND Id <> ?");
            $retired    = 0;
            foreach ($dupCodes as $dupCode) {
                $keepStmt->bind_param('s', $dupCode);
                $keepStmt->execute();
                $keepId = (int)($keepStmt->get_result()->fetch_assoc()['KeepId'] ?? 0);
                if ($keepId <= 0) { continue; }
                $retireStmt->bind_param('si', $dupCode, $keepId);
                $retireStmt->execute();
                $retired += max(0, $retireStmt->affected_rows);
            }
            $keepStmt->close();
            $retireStmt->close();
            _migSCU_output("  [OK] Resolved " . count($dupCodes) . " duplicated code(s); retired " . $retired . " losing claim(s), oldest kept.");
        }
    }

    /* ------------------------------------------------------------------ *
     * (4) The generated column + its UNIQUE key. Split from the index in
     *     (5) so a partially-applied install (column landed, key didn't)
     *     converges on a re-run instead of erroring.
     * ------------------------------------------------------------------ */
    if ($hasColumn && $hasUnique) {
        _migSCU_output("  [SKIP] tblLiveFollowJoinCodes.ActiveCode + uq_ActiveCode already present.");
    } elseif (!$hasColumn) {
        $mysql->query(
            "ALTER TABLE tblLiveFollowJoinCodes
                ADD COLUMN ActiveCode VARCHAR(12) GENERATED ALWAYS AS (CASE WHEN Status IN ('current','previous') THEN Code ELSE NULL END) STORED COMMENT 'Partial-unique idiom (#1621): mirrors Code while the row is LIVE, else NULL, so uq_ActiveCode makes live codes GLOBALLY unique while retired rows coexist (multiple NULLs are distinct). MySQL has no filtered index, and a generated column must be deterministic (UTC_TIMESTAMP is rejected), so liveness is expressed by Status alone. POSITIVE status list, so any future retired state is excluded automatically' AFTER Status,
                ADD UNIQUE KEY uq_ActiveCode (ActiveCode)"
        );
        _migSCU_output("  [OK] Added ActiveCode (STORED generated) + UNIQUE uq_ActiveCode — live codes are now globally unique.");
    } else {
        $mysql->query("ALTER TABLE tblLiveFollowJoinCodes ADD UNIQUE KEY uq_ActiveCode (ActiveCode)");
        _migSCU_output("  [OK] Added UNIQUE uq_ActiveCode (column was already present).");
    }

    /* ------------------------------------------------------------------ *
     * (5) idx_Live_Expiry — what makes the opportunistic expiry retirement
     *     in serviceMode_mintCode() cheap. Leading on Status turns it into
     *     two small ranges over exactly the live rows; without it the pass
     *     would walk long-retired rows in idx_Expiry order on a hot path
     *     that runs every ~30 s per live session.
     * ------------------------------------------------------------------ */
    if ($hasLiveIx) {
        _migSCU_output("  [SKIP] idx_Live_Expiry already present.");
    } else {
        $mysql->query("ALTER TABLE tblLiveFollowJoinCodes ADD INDEX idx_Live_Expiry (Status, ExpiresAt)");
        _migSCU_output("  [OK] Added idx_Live_Expiry (Status, ExpiresAt).");
    }

    /* ------------------------------------------------------------------ *
     * (6) Table comment — the shipped text said "Session-scoped
     *     uniqueness", which is now actively misleading. Kept byte-identical
     *     with schema.sql (rule #19).
     * ------------------------------------------------------------------ */
    $mysql->query(
        "ALTER TABLE tblLiveFollowJoinCodes
            COMMENT='Rotating venue join codes for Service Mode (#1335). LIVE codes are GLOBALLY unique via the ActiveCode generated column (#1621); current+previous window so a just-before-rotation scan still validates. Retired rows are kept for audit, never deleted.'"
    );
    _migSCU_output("  [OK] Table comment refreshed.");

    _migSCU_output("  Two concurrent services can no longer hold one live code; serviceMode_resolveJoin() additionally refuses an ambiguous typed code rather than guessing a winner.");
    _migSCU_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    /* 1062 here means a duplicate appeared between the pre-flight and the
       ALTER — i.e. a service minted a colliding code mid-migration. Re-running
       resolves it; the pre-flight is idempotent. */
    _migSCU_output("ERROR: " . $e->getMessage());
    if ((int)$e->getCode() === 1062) {
        _migSCU_output("  A duplicate live code appeared while the migration ran. Re-run this migration — the pre-flight steps are idempotent and will retire the newer claimant.");
    }
}
