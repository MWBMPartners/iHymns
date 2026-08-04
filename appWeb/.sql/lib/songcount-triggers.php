<?php

declare(strict_types=1);

/**
 * iHymns — the ONE source of the SongCount trigger DDL + recompute (#793 / #1694)
 * ===============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Two different migration cards need to (re)install the same three database
 * triggers that keep each songbook's cached song count honest. The trigger text
 * lives HERE, once, so the two cards can never drift into installing different
 * triggers.
 *
 * WHY THIS FILE EXISTS (#1694 D1a)
 * --------------------------------
 * `migrate-songcount-triggers.php` (#793) installed triggers that recompute an
 * UNFILTERED `COUNT(*)`. Song soft delete (#1694) changes what "SongCount"
 * means — the owner resolved D1 as "count VISIBLE songs" — so
 * `migrate-song-soft-delete.php` must redefine the same three triggers with an
 * `IsDeleted = 0` predicate. That is the second occurrence of the trigger DDL,
 * and CLAUDE.md's modularity rule is explicit: the second occurrence is an
 * extraction, not a copy. Both migrations `require_once` this file and call
 * `ihymnsSongCountTriggersInstall()` / `ihymnsSongCountRecompute()`.
 *
 * THE PREDICATE IS DECIDED FROM THE LIVE SCHEMA, PER CALL
 * -------------------------------------------------------
 * `ihymnsSongCountSoftDeleteAware()` probes whether `tblSongs.IsDeleted` exists
 * RIGHT NOW, deliberately un-memoised: a fresh install runs the cards in
 * historical order, so the #793 card runs BEFORE the soft-delete card and must
 * emit the unfiltered triggers (a trigger referencing a column that is not
 * there yet fails to fire on every subsequent write); the soft-delete card adds
 * the column and then re-runs the install in the SAME request, and the probe
 * must see the new column. Either card, run at any time, therefore leaves the
 * triggers matching the live schema — re-running #793 after soft delete keeps
 * the filtered form.
 *
 * TRIGGER-DENIED HOSTS (#815) are tolerated exactly as #793 tolerated them:
 * a denial is a friendly skip (the app-side recompute from PR #792 remains the
 * safety net), any other failure throws so the bulk runner records a real error.
 *
 * @see appWeb/.sql/migrate-songcount-triggers.php   the #793 card (delegates here)
 * @see appWeb/.sql/migrate-song-soft-delete.php     the #1694 card (delegates here)
 * @link https://dev.mysql.com/doc/refman/8.0/en/create-trigger.html
 * @link https://dev.mysql.com/doc/refman/8.0/en/information-schema-columns-table.html
 */

/* Direct HTTP access is denied — this file is only meaningful when required
   from a migration script (which carries its own gate). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Does `tblSongs.IsDeleted` exist on the LIVE schema right now?
 *
 * ELI5: "has the soft-delete column landed yet?" — asked fresh every time.
 *
 * Deliberately NOT memoised (unlike the request-scoped app gates such as
 * `userStatusColumnReady()`): `migrate-song-soft-delete.php` ADDS the column and
 * then asks this question in the same PHP request, so a memo settled before the
 * ALTER would install unfiltered triggers right after migrating — the exact
 * wrong-count drift the D1a decision exists to remove. Migrations run once, so
 * the extra INFORMATION_SCHEMA round trip costs nothing. Bound, never
 * interpolated (rule #5).
 */
function ihymnsSongCountSoftDeleteAware(\mysqli $db): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
            AND COLUMN_NAME = 'IsDeleted' LIMIT 1"
    );
    $stmt->execute();
    $aware = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $aware;
}

/**
 * The visibility predicate the recompute embeds — PURE.
 *
 * ELI5: the little "and it isn't deleted" clause, or nothing at all.
 *
 * Returned WITH its leading ` AND ` so call sites compose
 * `WHERE SongbookAbbr = …{$p}` without per-site spacing decisions. A hardcoded
 * constant from PHP source (rule #5 clause a) — no runtime value ever enters it.
 */
function ihymnsSongCountPredicate(bool $softDeleteAware): string
{
    return $softDeleteAware ? ' AND IsDeleted = 0' : '';
}

/**
 * The three CREATE TRIGGER statements, name => SQL — PURE.
 *
 * ELI5: the exact text of the three triggers, in the flavour the live schema
 * can support.
 *
 * With `$softDeleteAware = false` this is byte-for-byte the #793 DDL: the
 * recompute counts every row and the AFTER UPDATE trigger fires only when a
 * song changes songbook. With `true`, every recompute counts `IsDeleted = 0`
 * rows only, and the AFTER UPDATE condition gains
 * `OR NEW.IsDeleted <> OLD.IsDeleted` so a soft delete / restore refreshes the
 * count too (both UPDATEs then hit the same book — harmless, and simpler than a
 * third branch). The BEGIN/END body needs no DELIMITER games: mysqli passes the
 * whole CREATE TRIGGER as one statement (#793's original note).
 *
 * @return array<string,string> trigger name => CREATE TRIGGER statement
 */
function ihymnsSongCountTriggerDefs(bool $softDeleteAware): array
{
    $p    = ihymnsSongCountPredicate($softDeleteAware);
    $flip = $softDeleteAware ? ' OR NEW.IsDeleted <> OLD.IsDeleted' : '';

    return [
        'trg_songs_songcount_ai' => "
            CREATE TRIGGER trg_songs_songcount_ai AFTER INSERT ON tblSongs
            FOR EACH ROW
                UPDATE tblSongbooks
                   SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = NEW.SongbookAbbr{$p})
                 WHERE Abbreviation = NEW.SongbookAbbr",

        'trg_songs_songcount_ad' => "
            CREATE TRIGGER trg_songs_songcount_ad AFTER DELETE ON tblSongs
            FOR EACH ROW
                UPDATE tblSongbooks
                   SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = OLD.SongbookAbbr{$p})
                 WHERE Abbreviation = OLD.SongbookAbbr",

        'trg_songs_songcount_au' => "
            CREATE TRIGGER trg_songs_songcount_au AFTER UPDATE ON tblSongs
            FOR EACH ROW
            BEGIN
                IF OLD.SongbookAbbr <> NEW.SongbookAbbr{$flip} THEN
                    UPDATE tblSongbooks
                       SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = OLD.SongbookAbbr{$p})
                     WHERE Abbreviation = OLD.SongbookAbbr;
                    UPDATE tblSongbooks
                       SET SongCount = (SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = NEW.SongbookAbbr{$p})
                     WHERE Abbreviation = NEW.SongbookAbbr;
                END IF;
            END",
    ];
}

/**
 * Does this mysqli error message mean the host DENIED trigger DDL? — PURE.
 *
 * ELI5: "did it fail because the hosting plan forbids triggers, rather than
 * because we wrote bad SQL?"
 *
 * The three signals cover the common shared-host shapes (#793/#815):
 * "you do not have the SUPER privilege", "TRIGGER command denied to user …",
 * and explicit "privilege" / "binary logging" hints.
 */
function ihymnsSongCountTriggerDenied(string $err): bool
{
    return stripos($err, 'super') !== false
        || stripos($err, 'denied') !== false
        || stripos($err, 'privilege') !== false
        || stripos($err, 'binary logging') !== false;
}

/**
 * Run one DDL statement and CLASSIFY the failure instead of throwing.
 *
 * ELI5: try it, and report "worked", "the host said no", or "broke" — never
 * blow up.
 *
 * PHP 8.1+ defaults mysqli_report to throw `mysqli_sql_exception`, so
 * `$db->query()` never returns false on error — it throws. This wrapper
 * restores the run-and-classify pattern (#815) by catching the exception and
 * surfacing the original message + the denied flag; the CALLER decides whether
 * a failure is a friendly skip (denied) or a real error (anything else).
 *
 * @return array{ok:bool, denied:bool, err:string}
 */
function ihymnsSongCountRunQuery(\mysqli $db, string $sql): array
{
    try {
        $ok = $db->query($sql);
        if ($ok === false) {
            return ['ok' => false, 'denied' => ihymnsSongCountTriggerDenied($db->error), 'err' => $db->error];
        }
        return ['ok' => true, 'denied' => false, 'err' => ''];
    } catch (\mysqli_sql_exception $e) {
        return ['ok' => false, 'denied' => ihymnsSongCountTriggerDenied($e->getMessage()), 'err' => $e->getMessage()];
    }
}

/**
 * DROP + CREATE the three SongCount triggers to match the LIVE schema.
 *
 * ELI5: throw away the old triggers and install fresh ones, in whichever
 * flavour this database can support today. If the host forbids triggers, say
 * so nicely and move on.
 *
 * Idempotent — `DROP TRIGGER IF EXISTS` before each CREATE, so either
 * migration card can re-run this at any time. A DENIED drop skips straight to
 * the report (a host that denies DROP TRIGGER will also deny CREATE — one
 * clean message instead of six, #793's original behaviour). A NON-denial
 * CREATE failure throws: that would be a bug in the DDL, not host policy, and
 * the bulk runner must record it as a real failure.
 *
 * @param \mysqli  $db  Live connection.
 * @param callable $out fn(string): void — the calling migration's output line.
 * @return array{created:int, denied:bool}
 * @throws \RuntimeException on a CREATE failure that is not a host denial.
 */
function ihymnsSongCountTriggersInstall(\mysqli $db, callable $out): array
{
    $aware = ihymnsSongCountSoftDeleteAware($db);
    $out($aware
        ? 'tblSongs.IsDeleted present — installing soft-delete-aware triggers (count IsDeleted = 0).'
        : 'tblSongs.IsDeleted not present — installing the original unfiltered triggers (#793 flavour).');

    $defs = ihymnsSongCountTriggerDefs($aware);

    /* Drop any existing same-name triggers first (idempotency). */
    foreach (array_keys($defs) as $name) {
        $r = ihymnsSongCountRunQuery($db, "DROP TRIGGER IF EXISTS {$name}");
        if (!$r['ok']) {
            $out("WARN: DROP TRIGGER IF EXISTS {$name} failed: {$r['err']}");
            if ($r['denied']) {
                /* A host that denies DROP TRIGGER will also deny CREATE. Report
                   the denial once and let the caller fall back to the recompute. */
                return ['created' => 0, 'denied' => true];
            }
        }
    }

    $created = 0;
    foreach ($defs as $name => $sql) {
        $r = ihymnsSongCountRunQuery($db, $sql);
        if ($r['ok']) {
            $out("[add ] {$name}.");
            $created++;
            continue;
        }
        $out("WARN: CREATE TRIGGER {$name} failed: {$r['err']}");
        if ($r['denied']) {
            /* Shared-host policy (SUPER pre-MySQL-8.0, or outright denial).
               Friendly skip — the application-side recompute from PR #792 stays
               the safety net; failing the migration would block "Apply all"
               for no benefit (#815). */
            $out('       Hint: this MySQL host disallows CREATE TRIGGER (typically: SUPER privilege, or shared-host policy).');
            $out('       The application-side recompute (PR #792) remains the cache safety net; the triggers are an optimisation, not a requirement.');
            return ['created' => $created, 'denied' => true];
        }
        /* Non-denial failure (syntax / server error) — a genuine bug in this
           lib's DDL. Re-throw so the bulk runner records it and stops. */
        throw new \RuntimeException("CREATE TRIGGER {$name} failed: {$r['err']}");
    }

    return ['created' => $created, 'denied' => false];
}

/**
 * One full SongCount recompute against the LIVE schema — the trigger-denied
 * hosts' safety net, and every card's "leave the cache clean" closing step.
 *
 * ELI5: recount every songbook the way the triggers would, and fix any number
 * that drifted.
 *
 * The counting subquery carries the SAME predicate the triggers do (probed
 * fresh, same reasoning as the install), so a denied host that only ever runs
 * this path still shows the D1a-correct number once soft delete is live. Only
 * drifted rows are written — a clean cache costs zero UPDATEs. Mirrors the
 * shape of migrate-recompute-songbook-songcount.php (#791).
 */
function ihymnsSongCountRecompute(\mysqli $db, callable $out): void
{
    $p = ihymnsSongCountPredicate(ihymnsSongCountSoftDeleteAware($db));
    /* `WHERE 1=1{$p}` keeps the statement valid in both flavours without a
       second spelling of the subquery — `$p` is a hardcoded PHP constant
       (rule #5 clause a), never runtime data. */
    $res = $db->query(
        "SELECT b.Id, b.Abbreviation, b.SongCount AS cached,
                COALESCE(s.live_count, 0) AS live
           FROM tblSongbooks b
           LEFT JOIN (
                SELECT SongbookAbbr, COUNT(*) AS live_count
                  FROM tblSongs
                 WHERE 1=1{$p}
                 GROUP BY SongbookAbbr
           ) s ON s.SongbookAbbr = b.Abbreviation
          ORDER BY b.Abbreviation"
    );
    if (!$res) {
        $out('WARN: recompute SELECT failed: ' . $db->error);
        return;
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->close();
    $upStmt  = $db->prepare('UPDATE tblSongbooks SET SongCount = ? WHERE Id = ?');
    $drifted = 0;
    foreach ($rows as $r) {
        $cached = (int)$r['cached'];
        $live   = (int)$r['live'];
        if ($cached !== $live) {
            $id = (int)$r['Id'];
            $upStmt->bind_param('ii', $live, $id);
            $upStmt->execute();
            $drifted++;
        }
    }
    $upStmt->close();
    $out("Recompute: {$drifted} songbook(s) corrected.");
}
