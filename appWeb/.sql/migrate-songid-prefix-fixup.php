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
 * rename code has been patched alongside this migration to also
 * re-prefix the SongId, but rows that were renamed BEFORE the patch
 * shipped still carry the stale prefix and need a one-shot data fix.
 *
 * Algorithm:
 *   1. Find every (SongbookAbbr, oldPrefix) pair where:
 *        - SongbookAbbr <> SUBSTRING_INDEX(SongId, '-', 1)
 *        - i.e. the SongId's prefix (everything before the first `-`)
 *          doesn't match the row's declared SongbookAbbr.
 *   2. For each affected row, rewrite SongId = SongbookAbbr || '-' || <numeric tail>.
 *   3. tblSongs has FKs to itself from many child tables. Most carry
 *      ON UPDATE CASCADE so their SongId column refreshes automatically
 *      — but four don't: tblSongExternalLinks, tblSongAlternativeTitles,
 *      tblSongMedia, tblWorkSongs. We UPDATE those manually first,
 *      then update tblSongs.SongId.
 *
 * Conservative:
 *   - Only rewrites rows whose new SongId is FREE (no existing row with
 *     the target ID). If the target SongId is already taken, the row is
 *     left alone and reported in the output — a curator needs to merge
 *     manually.
 *   - Wrapped in a transaction; aborts cleanly on any error.
 *
 * Idempotent — re-running on an already-fixed catalogue is a no-op.
 *
 * @migration-updates tblSongs.SongId  (and child tables that lack
 *                                       ON UPDATE CASCADE)
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

_migSongIdPrefix_out('SongId prefix fixup starting…');

$db = getDbMysqli();
if (!$db) {
    _migSongIdPrefix_out('ERROR: could not connect to database.');
    if ($isCli) exit(1); else return;
}

/* Step 1 — find every row whose prefix is stale. */
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

/* Compute the proposed new SongId for each row and verify it's free
   (no PK collision). Rows whose target ID is already taken can't be
   fixed by a blind UPDATE — surface them for manual merge. */
$proposed   = []; /* SongId → newSongId */
$conflicts  = [];
$takenStmt  = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
foreach ($rows as $r) {
    $oldId    = (string)$r['SongId'];
    $bookAbbr = (string)$r['SongbookAbbr'];
    $dashPos  = strpos($oldId, '-');
    /* Defensive: rows with no `-` in their SongId aren't safe to
       reprefix automatically — they don't fit the abbr-number format.
       Skip and let a curator resolve. */
    if ($dashPos === false) {
        $conflicts[] = ['old' => $oldId, 'new' => null, 'reason' => 'no `-` in SongId — non-standard format'];
        continue;
    }
    $tail   = substr($oldId, $dashPos + 1);
    $newId  = $bookAbbr . '-' . $tail;
    if ($newId === $oldId) continue; /* shouldn't happen given the WHERE clause but safe */

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

/* Step 2 — do the updates inside a transaction. For each oldId →
   newId pair: update the four non-cascading children first, then
   tblSongs itself (the remaining ~14 children cascade via FK). */
$db->begin_transaction();
try {
    $nonCascadingChildren = [
        'tblSongExternalLinks',
        'tblSongAlternativeTitles',
        'tblSongMedia',
        'tblWorkSongs',
    ];
    /* tblSongLinkSuggestions has two SongId columns (SongIdA + SongIdB)
       and DOES carry ON UPDATE CASCADE, so it's handled automatically
       by the tblSongs.SongId update below. tblTranslationMaps has the
       same shape — also auto-cascades. */

    $childStmts = [];
    foreach ($nonCascadingChildren as $tbl) {
        $childStmts[$tbl] = $db->prepare("UPDATE {$tbl} SET SongId = ? WHERE SongId = ?");
    }
    $parentStmt = $db->prepare('UPDATE tblSongs SET SongId = ? WHERE SongId = ?');

    $applied = 0;
    foreach ($proposed as $oldId => $newId) {
        foreach ($childStmts as $tbl => $cs) {
            $cs->bind_param('ss', $newId, $oldId);
            $cs->execute();
        }
        $parentStmt->bind_param('ss', $newId, $oldId);
        $parentStmt->execute();
        if ($parentStmt->affected_rows > 0) {
            $applied++;
        }
    }
    foreach ($childStmts as $cs) { $cs->close(); }
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
