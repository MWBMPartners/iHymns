<?php

declare(strict_types=1);

/**
 * iHymns — Backfill canonical SongIds for legacy draft ids (#1380)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Before #1380, the Song Editor minted a client-side DRAFT id ("song-" + base36
 * timestamp + random) for a new song and the save UPSERTed it verbatim — so some
 * saved songs carry a permanent, non-canonical `song-XXXX` SongId. From #1380 the
 * server assigns a canonical `<Abbr>-NNNN` id on first save, but rows already
 * persisted with a draft id need this one-shot data fix.
 *
 * WHAT IT REWRITES, per draft-id song:
 *   1. tblSongs.SongId  (the rename) — the 41 FK children referencing
 *      tblSongs(SongId) all declare ON UPDATE CASCADE, so the new id propagates to
 *      every FK child AUTOMATICALLY in the same UPDATE. We do NOT touch them.
 *   2. The NON-FK / JSON stores that hold a SongId but have no FK, so do NOT
 *      cascade and must be rewritten by hand:
 *        - tblContentRestrictions.EntityId  (where EntityType = 'song')
 *        - tblUserSetlists.SongsJson        (array of {id,…} objects)
 *        - tblSharedSetlists.Data           (snapshot songs[] + arrangements{} keys)
 *   3. tblSongRedirects(OldSongId, NewSongId) — a redirect row so any bookmarked
 *      /song/song-XXXX permalink keeps resolving to the new canonical id.
 *
 * DELIBERATELY LEFT UNTOUCHED (audit / historical record — rewriting them would
 * falsify the past):
 *   tblSongRevisions.PreviousData / NewData, tblActivityLog, tblBulkImportJobs.*,
 *   tblLyricsConflicts.*Data.
 *
 * SAFETY GATES (defense in depth):
 *   - Registry 'manual' => true: EXCLUDED from "Apply all" and the pending counter.
 *   - DRY-RUN by default. A web run without ?confirm=1 (or a CLI run without
 *     --confirm) only REPORTS — it never mutates. The mutating path REFUSES with a
 *     clean exit 0 unless explicitly confirmed, so a stray bulk fetch is harmless.
 *   - IDEMPOTENT: re-running after a partial/complete apply finds fewer / no draft
 *     ids and is a no-op. INSERT IGNORE on the redirect row tolerates re-runs.
 *   - PER-SONG TRANSACTIONAL: each song's rename + JSON rewrites + redirect commit
 *     together or roll back together; one bad song never half-applies.
 *   - CASCADE PRE-CHECK: REFUSES up-front if ANY FK referencing tblSongs(SongId)
 *     lacks ON UPDATE CASCADE (an older drifted install) — pointing the operator to
 *     run migrate-songid-prefix-fixup.php first, so the rename can never orphan a
 *     child row.
 *
 * USAGE:
 *   Web (dry-run):  /manage/setup-database → "Backfill canonical SongIds (#1380)"
 *   Web (apply):    add &confirm=1 to the run URL (type-to-confirm card)
 *   CLI (dry-run):  php appWeb/.sql/migrate-backfill-canonical-songids.php
 *   CLI (apply):    php appWeb/.sql/migrate-backfill-canonical-songids.php --confirm
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migBCS_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) { @flush(); } }

/* CONFIRM resolution: CLI uses --confirm; web uses ?confirm=1. Anything else is a
   DRY RUN (report only). The default-safe stance means an operator who just clicks
   the card sees the plan first and must opt in to mutate. */
$confirm = false;
if ($isCli) {
    $confirm = in_array('--confirm', $argv ?? [], true);
} else {
    $confirm = (($_GET['confirm'] ?? '') === '1');
}
$dryRun = !$confirm;

/* Load standalone DB credentials (the migration cannot assume the app bootstrap ran). */
require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
require_once dirname(__DIR__) . '/public_html/includes/song_importers.php';
/* #1679 A9 — songRelocateIdTaken(): the ONE definition of "this SongId is
   already claimed" (tblSongs OR a live tblSongRedirects.OldSongId). This
   migration mints ids too, and its own probe asked tblSongs only. */
require_once dirname(__DIR__) . '/public_html/includes/song_relocate.php';

_migBCS_out("");
_migBCS_out("=== iHymns — Backfill canonical SongIds for draft ids (#1380) ===");
_migBCS_out($dryRun
    ? "MODE: DRY RUN (report only). Add " . ($isCli ? '--confirm' : '&confirm=1') . " to APPLY."
    : "MODE: APPLY (mutating). Per-song transactional.");

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migBCS_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* -------------------------------------------------------------------------
 * Cascade pre-check — REFUSE if any FK to tblSongs(SongId) is not ON UPDATE
 * CASCADE. Without it, UPDATE tblSongs SET SongId would either fail (RESTRICT)
 * or orphan children (SET NULL / NO ACTION). A drifted older install must run
 * migrate-songid-prefix-fixup.php first (which adds the missing cascades).
 * ------------------------------------------------------------------------- */
try {
    $stmt = $db->prepare(
        "SELECT rc.CONSTRAINT_NAME, rc.UPDATE_RULE, k.TABLE_NAME
           FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
           JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
          WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
            AND rc.REFERENCED_TABLE_NAME = 'tblSongs'
            AND k.REFERENCED_COLUMN_NAME = 'SongId'"
    );
    $stmt->execute();
    $fkRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $nonCascade = array_values(array_filter(
        $fkRows,
        static fn(array $r): bool => strtoupper((string)$r['UPDATE_RULE']) !== 'CASCADE'
    ));
    if (count($nonCascade) > 0) {
        _migBCS_out('[REFUSE] ' . count($nonCascade) . ' FK(s) referencing tblSongs(SongId) are NOT ON UPDATE CASCADE:');
        foreach (array_slice($nonCascade, 0, 20) as $r) {
            _migBCS_out('         ' . $r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME'] . ' = ' . $r['UPDATE_RULE']);
        }
        _migBCS_out('         Run migrate-songid-prefix-fixup.php first (it adds the cascades), then re-run this.');
        if ($isCli) { exit(1); }
        return;
    }
    _migBCS_out('[ok] all ' . count($fkRows) . ' FKs referencing tblSongs(SongId) are ON UPDATE CASCADE.');
} catch (\Throwable $e) {
    _migBCS_out('ERROR: FK cascade pre-check failed: ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

/* -------------------------------------------------------------------------
 * Collect every draft-id song.
 * ------------------------------------------------------------------------- */
$drafts = [];
try {
    $res = $db->query(
        "SELECT SongId, SongbookAbbr, Number,
                (SELECT IsOfficial FROM tblSongbooks b WHERE b.Abbreviation = s.SongbookAbbr LIMIT 1) AS IsOfficial
           FROM tblSongs s
          WHERE SongId LIKE 'song-%'"
    );
    while ($r = $res->fetch_assoc()) { $drafts[] = $r; }
    $res->close();
} catch (\Throwable $e) {
    _migBCS_out('ERROR: draft scan failed: ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

if (empty($drafts)) {
    _migBCS_out('[ok] no draft-id songs found — nothing to backfill.');
    return;
}
_migBCS_out('[scan] ' . count($drafts) . ' draft-id song' . (count($drafts) === 1 ? '' : 's') . ' to process.');

/* Prepared statements reused across songs. (The old $existsStmt "is this id
   free?" probe is gone — that question is now songRelocateIdTaken(), which also
   asks tblSongRedirects; see $mintCanonicalId below. #1679 A9.) */
$renameStmt    = $db->prepare('UPDATE tblSongs SET SongId = ?, Number = COALESCE(Number, ?) WHERE SongId = ?');
$restrictStmt  = $db->prepare("UPDATE tblContentRestrictions SET EntityId = ? WHERE EntityType = 'song' AND EntityId = ?");
/* LIKE candidate-filter narrows the JSON rewrites to rows that actually embed the
   old id (most rows don't), so we json_decode only the candidates. The exact `===`
   compare inside guards against a substring false-positive. */
$setlistScanStmt = $db->prepare("SELECT UserId, SetlistId, SongsJson FROM tblUserSetlists WHERE SongsJson LIKE CONCAT('%', ?, '%')");
$setlistUpdStmt  = $db->prepare('UPDATE tblUserSetlists SET SongsJson = ? WHERE UserId = ? AND SetlistId = ?');
$shareScanStmt   = $db->prepare("SELECT ShareId, Data FROM tblSharedSetlists WHERE Data LIKE CONCAT('%', ?, '%')");
$shareUpdStmt    = $db->prepare('UPDATE tblSharedSetlists SET Data = ? WHERE ShareId = ?');
$redirectStmt    = $db->prepare("INSERT IGNORE INTO tblSongRedirects (OldSongId, NewSongId, Reason, Note) VALUES (?, ?, 'rename', '#1380 canonical SongId backfill')");

/* Reserved-id ledger so a DRY RUN report is accurate when several drafts share a
   songbook: in dry-run the DB is never mutated, so _bulkImport_nextSongNumberFor
   would return the SAME next slot each time — the ledger makes each subsequent mint
   skip the one we already proposed. In APPLY mode the earlier rename has already
   landed before the next mint, so the ledger simply confirms (no double-count). */
$reservedIds = [];

/* Mint a free canonical id for a draft, walking past ids already CLAIMED (UNIQUE
   backstop) OR already reserved earlier in this run.
   #1679 A9 — "claimed" is the shared songRelocateIdTaken(): tblSongs OR a live
   tblSongRedirects row. The local probe it replaced asked tblSongs only, so this
   backfill could hand a draft the exact id an existing redirect still forwards
   away from — and getSongById() matches exactly before consulting the redirect
   layer, so the old permalink would then resolve to the WRONG SONG with 200 OK.
*/
$mintCanonicalId = static function (string $abbr) use ($db, &$reservedIds): array {
    $n = _bulkImport_nextSongNumberFor($db, $abbr);
    $candidate = sprintf('%s-%04d', $abbr, $n);
    while (isset($reservedIds[$candidate]) || songRelocateIdTaken($db, $candidate)) {
        $n++;
        $candidate = sprintf('%s-%04d', $abbr, $n);
    }
    $reservedIds[$candidate] = true;
    return [$candidate, $n];
};

/* Rewrite every exact-`===` occurrence of $oldId inside a setlist SongsJson object
   array. Returns [newJson|null, changed]. */
$rewriteSetlistJson = static function (string $rawJson, string $oldId, string $newId): array {
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) { return [null, false]; }
    $changed = false;
    foreach ($decoded as &$entry) {
        if (is_array($entry) && isset($entry['id']) && (string)$entry['id'] === $oldId) {
            $entry['id'] = $newId;
            $changed = true;
        }
    }
    unset($entry);
    if (!$changed) { return [null, false]; }
    return [json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true];
};

/* Rewrite a shared-setlist Data payload: songs[] id strings + arrangements{} keys. */
$rewriteShareData = static function (string $rawJson, string $oldId, string $newId): array {
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) { return [null, false]; }
    $changed = false;
    if (isset($decoded['songs']) && is_array($decoded['songs'])) {
        foreach ($decoded['songs'] as &$sid) {
            if ((string)$sid === $oldId) { $sid = $newId; $changed = true; }
        }
        unset($sid);
    }
    if (isset($decoded['arrangements']) && is_array($decoded['arrangements']) && array_key_exists($oldId, $decoded['arrangements'])) {
        $arrVal = $decoded['arrangements'][$oldId];
        unset($decoded['arrangements'][$oldId]);
        /* Preserve any existing value at the new key (extremely unlikely); the
           moved arrangement wins since it belongs to the renamed song. */
        $decoded['arrangements'][$newId] = $arrVal;
        $changed = true;
    }
    if (!$changed) { return [null, false]; }
    return [json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true];
};

$applied = 0;
$skipped = 0;
$errors  = 0;

foreach ($drafts as $d) {
    $oldId      = (string)$d['SongId'];
    $abbr       = (string)$d['SongbookAbbr'];
    if ($abbr === '') { $abbr = 'Misc'; }
    $isOfficial = (bool)($d['IsOfficial'] ?? false);
    $curNumber  = $d['Number'];

    [$newId, $slot] = $mintCanonicalId($abbr);
    $adoptNumber = ($isOfficial && ($curNumber === null)) ? $slot : null;

    /* --- count affected non-FK rows (report + sanity) --- */
    $restrictCount = 0; $setlistCount = 0; $shareCount = 0;
    try {
        $cStmt = $db->prepare("SELECT COUNT(*) FROM tblContentRestrictions WHERE EntityType = 'song' AND EntityId = ?");
        $cStmt->bind_param('s', $oldId); $cStmt->execute();
        $restrictCount = (int)($cStmt->get_result()->fetch_row()[0] ?? 0); $cStmt->close();
    } catch (\Throwable $_e) { /* table may be absent on a minimal install */ }

    /* --- DRY RUN: report and continue, no mutation --- */
    if ($dryRun) {
        /* candidate-scan the JSON stores for the affected-count report */
        try {
            $setlistScanStmt->bind_param('s', $oldId); $setlistScanStmt->execute();
            foreach ($setlistScanStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                [, $ch] = $rewriteSetlistJson((string)$row['SongsJson'], $oldId, $newId);
                if ($ch) { $setlistCount++; }
            }
        } catch (\Throwable $_e) {}
        try {
            $shareScanStmt->bind_param('s', $oldId); $shareScanStmt->execute();
            foreach ($shareScanStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                [, $ch] = $rewriteShareData((string)$row['Data'], $oldId, $newId);
                if ($ch) { $shareCount++; }
            }
        } catch (\Throwable $_e) {}

        $numNote = $adoptNumber !== null ? (' (+ adopt Number ' . $adoptNumber . ')') : '';
        _migBCS_out(sprintf(
            '  [plan] %s → %s%s  | restrictions:%d setlists:%d shares:%d',
            $oldId, $newId, $numNote, $restrictCount, $setlistCount, $shareCount
        ));
        $applied++; /* count of would-apply */
        continue;
    }

    /* --- APPLY: per-song transaction --- */
    try {
        $db->begin_transaction();

        /* 1. rename tblSongs (FK children cascade automatically) */
        $renameStmt->bind_param('sis', $newId, $adoptNumber, $oldId);
        $renameStmt->execute();

        /* 2a. non-FK content restrictions */
        $restrictStmt->bind_param('ss', $newId, $oldId);
        $restrictStmt->execute();

        /* 2b. user setlists (JSON object array) */
        $setlistScanStmt->bind_param('s', $oldId); $setlistScanStmt->execute();
        $setlistRows = $setlistScanStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($setlistRows as $row) {
            [$newJson, $ch] = $rewriteSetlistJson((string)$row['SongsJson'], $oldId, $newId);
            if ($ch) {
                $uid = (int)$row['UserId']; $sid = (string)$row['SetlistId'];
                $setlistUpdStmt->bind_param('sis', $newJson, $uid, $sid);
                $setlistUpdStmt->execute();
                $setlistCount++;
            }
        }

        /* 2c. shared setlists (snapshot songs[] + arrangements{}) */
        $shareScanStmt->bind_param('s', $oldId); $shareScanStmt->execute();
        $shareRows = $shareScanStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($shareRows as $row) {
            [$newData, $ch] = $rewriteShareData((string)$row['Data'], $oldId, $newId);
            if ($ch) {
                $shid = (string)$row['ShareId'];
                $shareUpdStmt->bind_param('ss', $newData, $shid);
                $shareUpdStmt->execute();
                $shareCount++;
            }
        }

        /* 3. permalink redirect (idempotent — INSERT IGNORE on PK collision) */
        $redirectStmt->bind_param('ss', $oldId, $newId);
        $redirectStmt->execute();

        $db->commit();
        $numNote = $adoptNumber !== null ? (' (Number=' . $adoptNumber . ')') : '';
        _migBCS_out(sprintf(
            '  [done] %s → %s%s  | restrictions:%d setlists:%d shares:%d',
            $oldId, $newId, $numNote, $restrictCount, $setlistCount, $shareCount
        ));
        $applied++;
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        _migBCS_out('  [ERROR] ' . $oldId . ' → ' . $newId . ' rolled back: ' . $e->getMessage());
        $errors++;
    }
}

$renameStmt->close();    $restrictStmt->close();
$setlistScanStmt->close(); $setlistUpdStmt->close();
$shareScanStmt->close();   $shareUpdStmt->close(); $redirectStmt->close();

_migBCS_out('');
if ($dryRun) {
    _migBCS_out("[summary] DRY RUN — {$applied} song(s) would be renamed. Re-run with "
        . ($isCli ? '--confirm' : '&confirm=1') . ' to APPLY.');
} else {
    _migBCS_out("[summary] APPLIED {$applied} rename(s), {$errors} error(s).");
    if ($errors > 0) {
        _migBCS_out('[note] errored songs were rolled back individually and left as draft ids — re-run to retry.');
    }
}
