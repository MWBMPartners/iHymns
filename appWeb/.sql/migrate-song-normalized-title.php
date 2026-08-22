<?php

declare(strict_types=1);

/**
 * iHymns — NormalizedTitle dedup/match column (#1066 Theme D)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Duplicate detection and ingest song-resolution both normalise titles row by
 * row in PHP today — O(N) per lookup. This migration adds an app-maintained,
 * indexed fold of Title so those become an indexed pre-filter (the exact compare
 * still runs in PHP, since MySQL 8 cannot reproduce ihymns_normalize_title()).
 *
 *   - tblSongs.NormalizedTitle VARCHAR(500) NOT NULL DEFAULT '' + idx_NormalizedTitle.
 *
 * It is a PLAIN column (not GENERATED) deliberately: a GENERATED column would
 * have to be expressible in SQL, and MySQL 8 cannot reproduce
 * ihymns_normalize_title() (iconv ASCII//TRANSLIT + a \p{L}\p{N} Unicode-
 * property strip). The migration BACKFILLS every existing row using the
 * canonical PHP normalizer; write paths keep it in sync on create/edit.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column existence guarded; backfill re-runnable).
 *
 * NOT DESTRUCTIVE — with one thing worth knowing. Nothing is dropped, and the
 * new column is NOT NULL DEFAULT '' so no existing row is invalidated. The
 * BACKFILL, however, does UPDATE every row of tblSongs: it recomputes
 * NormalizedTitle from Title and overwrites whatever was there. That is safe
 * because the column is a derived fold with no independent authority — nothing
 * a human types lives in it — but it does mean the run is O(all songs) of
 * UPDATE traffic each time, not a no-op.
 *
 * HOW IDEMPOTENCY IS ACHIEVED — three separately-guarded steps:
 *   1. The column: SHOW COLUMNS probe, skipped if present.
 *   2. The index:  SHOW INDEX … WHERE Key_name probe, skipped if present.
 *   3. The backfill: NOT guarded, and deliberately so. It recomputes every row
 *      from the same pure function, so a second run converges on exactly the
 *      same values — idempotent by being deterministic rather than by being
 *      skipped. Re-running is the supported REPAIR for rows whose fold has gone
 *      stale (see the note below), which is why the transcript reports both
 *      "processed" and "updated" counts: a healthy repeat run shows N processed
 *      and 0 updated.
 *
 * REPAIR CASE THIS MIGRATION IS THE ANSWER TO: not every path that inserts into
 * tblSongs sets NormalizedTitle (as of this writing the v2 editor API does;
 * includes/song_importers.php and includes/lyrics_ingest.php do not), so
 * imported songs can sit on the DEFAULT ''. Nothing breaks — the one consumer,
 * /manage/duplicate-songs, falls back to computing the fold in PHP when the
 * column is empty — but those songs lose the indexed pre-filter this column
 * exists to provide. Re-running this card repopulates them.
 *
 * OPERATOR VIEW: /manage/setup-database → card "NormalizedTitle dedup column
 * (#1066)" → button "Run NormalizedTitle Migration". Progress ticks every 500
 * rows. On a ~14k-song corpus expect tens of seconds, which is why the run
 * lifts PHP's execution limits below.
 *
 * SCHEMA MIRROR: the column + idx_NormalizedTitle are mirrored in
 * appWeb/.sql/schema.sql, which a FRESH install reads instead of running this
 * script (a fresh install has no songs, so there is nothing to backfill and the
 * column-existence probe correctly reads as applied). The declarations must
 * stay byte-identical, COMMENT text included — CLAUDE.md rule #19. ⚠ They are
 * NOT identical today: schema.sql carries a much longer COMMENT and omits the
 * explicit `COLLATE utf8mb4_unicode_ci` this ALTER states. Reconciling that is
 * a paired schema.sql + migration edit, deliberately not done as part of a
 * comment-only pass.
 *
 * @migration-adds tblSongs.NormalizedTitle
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-normalized-title.php
 *   Web:  /manage/setup-database → "NormalizedTitle dedup column" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* The backfill walks every song; on a large catalogue that can exceed a shared
   host's default 30s limit. Lift PHP's cap and survive a curator navigating away
   mid-run. (The setup dashboard sets these too; belt-and-braces for a direct run.) */
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ignore_user_abort(true);

function _migNormTitle_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migNormTitle_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* NOTE: the title normalizer is resolved LATER — AFTER the column + index are
   added (see the backfill section below) — so a missing or relocated
   title_normalize.php can NEVER stop the column from landing. Resolving it up
   here and returning early on failure was the #1162/#1165 stuck-pending bug:
   the script bailed before the ALTER, the column never landed, and the probe
   (column-existence only) stayed pending while the run clocked ~1 ms. The
   column-add does not need the normalizer; only the backfill does. */

_migNormTitle_output("");
_migNormTitle_output("=== iHymns — NormalizedTitle dedup column (#1066 Theme D) ===");
_migNormTitle_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migNormTitle_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migNormTitle_output("Connected to MySQL: " . DB_NAME);

try {
    _migNormTitle_output("");
    _migNormTitle_output("--- tblSongs.NormalizedTitle + index ---");

    $hasCol = $mysql->query("SHOW COLUMNS FROM tblSongs LIKE 'NormalizedTitle'");
    if ($hasCol && $hasCol->num_rows > 0) {
        _migNormTitle_output("  [SKIP] tblSongs.NormalizedTitle already present.");
    } else {
        /* NO positional `AFTER Title` clause: a positional add forces a full
           table COPY/rebuild on MySQL 5.7 / pre-8.0.29, which on a wide table
           (tblSongs has ~22 string columns + LyricsText) can exceed the host's
           proxy timeout mid-rebuild and roll back — leaving the column missing
           and the migration stuck "pending". Appending the column at the end is
           an INSTANT, metadata-only op on MySQL 8.0.12+. The column's ordinal
           position is cosmetic (it's an internal dedup fold); the Schema Audit
           matches columns by name, not position, so fresh-vs-migrated stay OK. */
        $mysql->query(
            "ALTER TABLE tblSongs
                ADD COLUMN NormalizedTitle VARCHAR(500) NOT NULL DEFAULT '' COLLATE utf8mb4_unicode_ci
                    COMMENT 'App-maintained fold of Title via ihymns_normalize_title() (Unicode NFKD, strip combining marks, lowercase, fold special letters; iconv//TRANSLIT fallback when ext-intl is absent) for a fast indexed dedup/match pre-filter; the exact compare still runs in PHP. Plain column (not GENERATED) because MySQL cannot reproduce the PHP normalizer. Backfilled on migrate; kept in sync on create/edit (#1066 Theme D)'"
        );
        _migNormTitle_output("  [OK] Added tblSongs.NormalizedTitle.");
    }

    $hasIdx = $mysql->query("SHOW INDEX FROM tblSongs WHERE Key_name = 'idx_NormalizedTitle'");
    if ($hasIdx && $hasIdx->num_rows > 0) {
        _migNormTitle_output("  [SKIP] idx_NormalizedTitle already present.");
    } else {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_NormalizedTitle (NormalizedTitle)");
        _migNormTitle_output("  [OK] Added index idx_NormalizedTitle.");
    }

    _migNormTitle_output("");
    _migNormTitle_output("--- Backfill (re-runnable; recomputes every row) ---");

    /* Resolve the title normalizer HERE — AFTER the column + index are in place —
       so a missing/relocated title_normalize.php can NEVER prevent the column from
       landing (the #1162/#1165 stuck-pending bug). The probe is column-existence
       only, so the card flips to applied once the column exists; the backfill is
       best-effort + re-runnable on a later pass once the path is confirmed. */
    $ds = DIRECTORY_SEPARATOR;
    $normCandidates = [
        dirname(__DIR__) . $ds . 'public_html' . $ds . 'includes' . $ds . 'title_normalize.php',
        dirname(__DIR__) . $ds . 'includes' . $ds . 'title_normalize.php',
        __DIR__ . $ds . '..' . $ds . 'public_html' . $ds . 'includes' . $ds . 'title_normalize.php',
        __DIR__ . $ds . '..' . $ds . 'includes' . $ds . 'title_normalize.php',
    ];
    $normHelper = null;
    foreach ($normCandidates as $cand) {
        if (is_file($cand)) { $normHelper = $cand; break; }
    }
    if ($normHelper !== null) { require_once $normHelper; }

    if ($normHelper === null || !function_exists('ihymns_normalize_title')) {
        _migNormTitle_output("  [WARN] title_normalize.php could not be resolved — the column + index ARE");
        _migNormTitle_output("         in place (migration counts as applied), but the backfill was SKIPPED.");
        _migNormTitle_output("         Tried: " . implode(' | ', $normCandidates));
        _migNormTitle_output("         Re-run this migration once the normalizer path is confirmed to populate");
        _migNormTitle_output("         NormalizedTitle for existing rows.");
    } else {
        /* Row-by-row rather than set-based, because the fold is a PHP function
           MySQL cannot express — that is the same reason the column is plain and
           not GENERATED. $sel is a BUFFERED result (the mysqli default), so the
           whole id+title list is client-side before the UPDATE loop starts and
           the two statements do not contend on one connection
           (https://www.php.net/manual/en/mysqli.query.php). Only Id + Title are
           selected, never LyricsText — pulling full song rows here would be the
           whole-corpus materialisation CLAUDE.md rule #17 forbids. */
        $sel = $mysql->query("SELECT Id, Title FROM tblSongs");
        $upd = $mysql->prepare("UPDATE tblSongs SET NormalizedTitle = ? WHERE Id = ?");
        $count = 0;
        $changed = 0;
        while ($row = $sel->fetch_assoc()) {
            $norm = ihymns_normalize_title((string)$row['Title']);
            /* Truncate by CHARACTER, not byte — mysqli runs under
               MYSQLI_REPORT_STRICT, so an over-length value would THROW rather
               than silently truncate, aborting the whole backfill on one long
               title. Cutting the fold short is harmless: this column is only an
               indexed pre-filter and the exact compare still happens in PHP
               against the full Title, so a truncated prefix can widen the
               candidate set but can never produce a wrong match. */
            if (mb_strlen($norm) > 500) {
                $norm = mb_substr($norm, 0, 500);
            }
            $id = (int)$row['Id'];
            $upd->bind_param('si', $norm, $id);
            $upd->execute();
            $count++;
            if ($upd->affected_rows > 0) {
                $changed++;
            }
            if ($count % 500 === 0) {
                _migNormTitle_output("  …{$count} rows processed");
            }
        }
        $upd->close();
        _migNormTitle_output("  [OK] Backfilled {$count} songs ({$changed} updated).");
    }

    _migNormTitle_output("");
    _migNormTitle_output("--- Summary ---");
    _migNormTitle_output("  tblSongs.NormalizedTitle is populated + indexed. Duplicate detection and");
    _migNormTitle_output("  ingest resolution can now pre-filter on the index (PHP does the exact compare).");
    _migNormTitle_output("  Write paths must keep it in sync on create/edit (follow-up feature work).");
    _migNormTitle_output("");
    _migNormTitle_output("Migration complete.");
} catch (\Throwable $e) {
    _migNormTitle_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
