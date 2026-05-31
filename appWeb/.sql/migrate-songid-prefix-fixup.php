<?php

declare(strict_types=1);

/**
 * iHymns — Re-prefix SongIds whose SongbookAbbr no longer matches
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The /manage/songbooks rename flow used to update tblSongbooks.Abbreviation
 * and (optionally) tblSongs.SongbookAbbr — but NOT the SongId itself. So
 * after renaming a songbook (e.g. HA → HAOLD to free `HA` for a fresh
 * scrape), the surviving rows looked like:
 *
 *     SongId = "HA-0001"          (untouched — still old prefix)
 *     SongbookAbbr = "HAOLD"      (updated by the rename)
 *
 * A subsequent bulk-import of the new HA songbook would then collide on
 * the PK when it tried to INSERT SongId='HA-0001'. The application-side
 * rename code has been patched alongside this migration, but rows that
 * were renamed BEFORE the patch shipped still carry the stale prefix
 * and need a one-shot data fix.
 *
 * Algorithm:
 *   STEP 1 — Add `ON UPDATE CASCADE` to the four child FKs that lack it
 *            (`fk_link_song`, `fk_alt_song`, `fk_media_song`,
 *            `fk_work_song_song`). Without this, updating tblSongs.SongId
 *            either fails with a FK constraint violation (children point
 *            at a parent SongId that no longer exists post-update) or,
 *            if we try child-first, fails because the new parent SongId
 *            doesn't yet exist when the child is repointed. The only
 *            atomic, FK-safe shape is "parent UPDATE with cascading
 *            children". Idempotent via INFORMATION_SCHEMA probe.
 *
 *   STEP 2 — Find every row whose SongId's prefix (everything before the
 *            first `-`) doesn't match its declared SongbookAbbr.
 *
 *   STEP 3 — Per-row, compute the target SongId = SongbookAbbr ‖ '-' ‖
 *            <numeric tail>. If the target is FREE (no existing row), UPDATE
 *            tblSongs.SongId — the FK cascade propagates the new value to
 *            every child table (18 of them). If the target is taken, the
 *            row is reported for manual merge and skipped.
 *
 * Conservative:
 *   - Only rewrites rows whose new SongId is FREE.
 *   - Whole rewrite wrapped in a transaction; cleanly rolls back on error.
 *
 * Idempotent — re-running on an already-fixed catalogue is a no-op.
 *
 * @migration-updates tblSongs.SongId  (via FK cascade)
 * @migration-alters  fk_link_song, fk_alt_song, fk_media_song, fk_work_song_song
 *                     to add ON UPDATE CASCADE
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Re-prefix SongIds"
 *   CLI:  php appWeb/.sql/migrate-songid-prefix-fixup.php
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('getDbMysqli')) {
            require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
        }
    }
    $isCli = false;
}

function _migSongIdPrefix_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . "\n";
    } else {
        echo htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    }
}

/**
 * Check the UPDATE_RULE on a single FK constraint. Returns one of
 * 'CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', or '' when the
 * constraint isn't found.
 */
function _migSongIdPrefix_fkUpdateRule(\mysqli $db, string $table, string $constraint): string
{
    $stmt = $db->prepare(
        'SELECT UPDATE_RULE
           FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME        = ?
            AND CONSTRAINT_NAME   = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $r ? (string)$r[0] : '';
}

_migSongIdPrefix_out('SongId prefix fixup starting…');

$db = getDbMysqli();
if (!$db) {
    _migSongIdPrefix_out('ERROR: could not connect to database.');
    if ($isCli) exit(1); else return;
}

/* ----------------------------------------------------------------
 * STEP 1 — Add ON UPDATE CASCADE to the four child FKs that lack it.
 *
 * Each entry: [child_table, fk_name, child_column].
 * The 14 other FKs to tblSongs.SongId already declare ON UPDATE
 * CASCADE in schema.sql, so they don't need a re-alter.
 * ---------------------------------------------------------------- */
$cascadeTargets = [
    ['tblSongExternalLinks',     'fk_link_song',       'SongId'],
    ['tblSongAlternativeTitles', 'fk_alt_song',        'SongId'],
    ['tblSongMedia',             'fk_media_song',      'SongId'],
    ['tblWorkSongs',             'fk_work_song_song',  'SongId'],
];

$altered = 0;
$skipped = 0;
foreach ($cascadeTargets as [$tbl, $fk, $col]) {
    $rule = _migSongIdPrefix_fkUpdateRule($db, $tbl, $fk);
    if ($rule === '') {
        _migSongIdPrefix_out("[skip] {$tbl}.{$fk} — constraint not found (schema not migrated to expected shape?).");
        $skipped++;
        continue;
    }
    if ($rule === 'CASCADE') {
        $skipped++;
        continue; /* already cascading — nothing to do */
    }

    /* MySQL has no in-place "ALTER FK". The path is DROP then ADD
       — and these MUST be two SEPARATE ALTER TABLE statements,
       not a single multi-action ALTER. MySQL/MariaDB validates the
       new constraint name against the schema state at the START of
       the statement, BEFORE the in-statement DROP registers, so a
       combined `DROP FOREIGN KEY x, ADD CONSTRAINT x …` trips
       "Duplicate foreign key constraint name 'x'". Two statements
       avoid that — the brief window between them, where the FK is
       absent, is safe for a maintenance migration (no concurrent
       writes are expected). ON DELETE CASCADE is preserved (every
       one of these started with DELETE CASCADE already). */
    $sqlDrop = "ALTER TABLE {$tbl} DROP FOREIGN KEY {$fk}";
    if (!$db->query($sqlDrop)) {
        _migSongIdPrefix_out("ERROR: DROP {$tbl}.{$fk} failed: " . $db->error);
        if ($isCli) exit(1); else return;
    }
    $sqlAdd = "ALTER TABLE {$tbl}
                  ADD CONSTRAINT {$fk}
                      FOREIGN KEY ({$col}) REFERENCES tblSongs(SongId)
                      ON DELETE CASCADE ON UPDATE CASCADE";
    if (!$db->query($sqlAdd)) {
        _migSongIdPrefix_out("ERROR: ADD {$tbl}.{$fk} failed (FK left dropped — re-run the migration to retry): " . $db->error);
        if ($isCli) exit(1); else return;
    }
    _migSongIdPrefix_out("[alt ] {$tbl}.{$fk} now ON UPDATE CASCADE.");
    $altered++;
}
if ($altered === 0) {
    _migSongIdPrefix_out('[ok  ] all four child FKs already ON UPDATE CASCADE.');
}

/* ----------------------------------------------------------------
 * STEP 2 — Identify stale-prefix rows.
 * ---------------------------------------------------------------- */
$rows = [];
$res  = $db->query(
    "SELECT SongId, SongbookAbbr
       FROM tblSongs
      WHERE SongbookAbbr <> ''
        AND SongbookAbbr IS NOT NULL
        AND SongbookAbbr <> SUBSTRING_INDEX(SongId, '-', 1)"
);
if (!$res) {
    _migSongIdPrefix_out('ERROR: scan query failed: ' . $db->error);
    if ($isCli) exit(1); else return;
}
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$res->close();

if (empty($rows)) {
    _migSongIdPrefix_out('[ok  ] no rows need re-prefixing — every SongId matches its SongbookAbbr.');
    return;
}

_migSongIdPrefix_out('[scan] ' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's') . ' have a stale SongId prefix.');

/* Compute the proposed new SongId for each row and verify it's free. */
$proposed  = [];
$conflicts = [];
$takenStmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
foreach ($rows as $r) {
    $oldId    = (string)$r['SongId'];
    $bookAbbr = (string)$r['SongbookAbbr'];
    $dashPos  = strpos($oldId, '-');
    if ($dashPos === false) {
        $conflicts[] = ['old' => $oldId, 'new' => null, 'reason' => 'no `-` in SongId — non-standard format'];
        continue;
    }
    $tail   = substr($oldId, $dashPos + 1);
    $newId  = $bookAbbr . '-' . $tail;
    if ($newId === $oldId) continue;

    $takenStmt->bind_param('s', $newId);
    $takenStmt->execute();
    $clash = $takenStmt->get_result()->fetch_row() !== null;
    if ($clash) {
        $conflicts[] = ['old' => $oldId, 'new' => $newId, 'reason' => 'target SongId already exists — manual merge needed'];
        continue;
    }
    $proposed[$oldId] = $newId;
}
$takenStmt->close();

if (!empty($conflicts)) {
    _migSongIdPrefix_out('[warn] ' . count($conflicts) . ' row' . (count($conflicts) === 1 ? '' : 's')
        . ' can\'t be re-prefixed automatically:');
    foreach (array_slice($conflicts, 0, 20) as $c) {
        $arrow = $c['new'] === null ? '' : ' → ' . $c['new'];
        _migSongIdPrefix_out('       ' . $c['old'] . $arrow . '  (' . $c['reason'] . ')');
    }
    if (count($conflicts) > 20) {
        _migSongIdPrefix_out('       … (' . (count($conflicts) - 20) . ' more)');
    }
}

if (empty($proposed)) {
    _migSongIdPrefix_out('[ok  ] no actionable rows after conflict filter.');
    return;
}

_migSongIdPrefix_out('[fix ] re-prefixing ' . count($proposed) . ' row' . (count($proposed) === 1 ? '' : 's') . '…');

/* ----------------------------------------------------------------
 * STEP 3 — UPDATE tblSongs.SongId. With all 4 FKs now ON UPDATE
 *          CASCADE, every child table (18 of them) refreshes
 *          atomically — no separate child-update step needed.
 * ---------------------------------------------------------------- */
$db->begin_transaction();
try {
    $parentStmt = $db->prepare('UPDATE tblSongs SET SongId = ? WHERE SongId = ?');
    $applied = 0;
    foreach ($proposed as $oldId => $newId) {
        $parentStmt->bind_param('ss', $newId, $oldId);
        $parentStmt->execute();
        if ($parentStmt->affected_rows > 0) {
            $applied++;
        }
    }
    $parentStmt->close();
    $db->commit();

    _migSongIdPrefix_out("[done] re-prefixed {$applied} SongId" . ($applied === 1 ? '' : 's') . '.');
    if (!empty($conflicts)) {
        _migSongIdPrefix_out('[note] ' . count($conflicts) . ' unresolved conflict' . (count($conflicts) === 1 ? '' : 's')
            . ' — see list above; resolve manually (merge / delete / rename).');
    }
    _migSongIdPrefix_out('[note] regenerate the songs cache via /manage/data-health to refresh public reads.');
} catch (\Throwable $e) {
    $db->rollback();
    _migSongIdPrefix_out('ERROR: rollback — ' . $e->getMessage());
    if ($isCli) exit(1); else return;
}
