<?php

declare(strict_types=1);

/**
 * iHymns — Post-write songbook maintenance helper.
 *
 * Single entry point every "I just wrote to the songbook catalogue"
 * code path can call to keep the public-facing reads consistent
 * without each writer having to remember the two follow-up steps:
 *
 *   1. Regenerate the precomputed songs.json cache that the public
 *      PWA + Song Editor consume (songsCacheRegenerateBestEffort).
 *      Already best-effort + logged; safe to call after every save.
 *
 *   2. Re-prefix any tblSongs row whose SongId prefix has drifted
 *      from its declared SongbookAbbr. Pre-#997 renames produced
 *      these "orphan-prefix" rows; PR #997 made the rename code
 *      self-consistent, but a bulk-import on a catalogue that
 *      pre-dates that fix can still leave the namespace blocked.
 *      Running the probe-and-rewrite as a post-write hook means
 *      a fresh ZIP import after a stale-prefix rename will
 *      unblock itself on the curator's next save instead of
 *      requiring a manual visit to /manage/setup-database.
 *
 * Direct access to this file is blocked so it can't be loaded as an
 * arbitrary endpoint.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'songs_cache.php';

/**
 * If we end up with more orphan-prefix rows than this on a single
 * post-write hook, refuse to run the rewrite inline (it'd block the
 * curator's save response) and tell the operator to run the
 * dedicated migration via /manage/setup-database instead. The
 * one-time HA→HAOLD + HASD→HASDOLD scenario the user hit produced
 * ~1,224 rows — well above the inline cap — which is why the
 * migration exists as a separate, observable entry point.
 */
const SONGBOOK_MAINT_INLINE_FIXUP_CAP = 100;

/**
 * Probe-and-rewrite for orphan SongId prefixes.
 *
 * Counts how many rows have a stale prefix; if at or below the
 * inline cap it does the rewrite right here (~1ms per row), and
 * if above the cap it logs + returns a "deferred" status so the
 * caller can surface a curator-facing nudge.
 *
 * Mirrors migrate-songid-prefix-fixup.php's algorithm exactly so
 * the two paths produce identical results — the migration stays
 * the authoritative entry point for bulk cleanups; this hook is
 * the convenience layer.
 *
 * @return array{
 *   stale_count: int,            // rows whose SongId prefix doesn't match SongbookAbbr
 *   rewritten:   int,            // rows we re-prefixed inline
 *   deferred:    bool,           // true when stale_count > cap and we didn't run inline
 *   conflicts:   list<string>,   // SongIds whose target was already taken (manual merge needed)
 *   error:       ?string,
 * }
 */
function songIdPrefixProbeAndFixup(\mysqli $db): array
{
    $result = [
        'stale_count' => 0,
        'rewritten'   => 0,
        'deferred'    => false,
        'conflicts'   => [],
        'error'       => null,
    ];

    try {
        /* Single indexed SELECT — same shape the migration probe uses.
           On a clean catalogue this returns 0 rows in ~5ms. */
        $stale = [];
        $res   = $db->query(
            "SELECT SongId, SongbookAbbr
               FROM tblSongs
              WHERE SongbookAbbr <> ''
                AND SongbookAbbr IS NOT NULL
                AND SongbookAbbr <> SUBSTRING_INDEX(SongId, '-', 1)"
        );
        if (!$res) {
            $result['error'] = 'probe query failed: ' . $db->error;
            return $result;
        }
        while ($r = $res->fetch_assoc()) {
            $stale[] = $r;
        }
        $res->close();

        $result['stale_count'] = count($stale);
        if (empty($stale)) return $result;

        /* Defer to the migration if there's more than the inline cap.
           The bulk case (e.g. the one-time HA → HAOLD scenario) is
           NOT something we want to drag into every curator save —
           it has its own audit trail via /manage/setup-database. */
        if (count($stale) > SONGBOOK_MAINT_INLINE_FIXUP_CAP) {
            $result['deferred'] = true;
            error_log(sprintf(
                '[songbook_maint] %d stale-prefix rows exceeds inline cap (%d) — run migrate-songid-prefix-fixup.php',
                count($stale),
                SONGBOOK_MAINT_INLINE_FIXUP_CAP
            ));
            return $result;
        }

        /* Inline rewrite path — small N. Same multi-step UPDATE the
           migration uses: four non-cascading child tables first, then
           tblSongs (FK cascade handles the rest). */
        $takenStmt = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
        $proposed  = [];
        foreach ($stale as $row) {
            $oldId   = (string)$row['SongId'];
            $abbr    = (string)$row['SongbookAbbr'];
            $dashPos = strpos($oldId, '-');
            if ($dashPos === false) {
                $result['conflicts'][] = $oldId . '  (no `-` in SongId)';
                continue;
            }
            $tail  = substr($oldId, $dashPos + 1);
            $newId = $abbr . '-' . $tail;
            if ($newId === $oldId) continue;
            $takenStmt->bind_param('s', $newId);
            $takenStmt->execute();
            $clash = $takenStmt->get_result()->fetch_row() !== null;
            if ($clash) {
                $result['conflicts'][] = $oldId . ' → ' . $newId . '  (target already exists)';
                continue;
            }
            $proposed[$oldId] = $newId;
        }
        $takenStmt->close();

        if (empty($proposed)) return $result;

        /* All 18 child tables that FK to tblSongs.SongId now carry
           ON UPDATE CASCADE (retro-fitted via migrate-songid-prefix-
           fixup.php), so a single UPDATE on the parent propagates
           atomically to every child. If the migration HASN'T run yet
           (curator on an older deploy) the per-row UPDATE will trip
           the FK constraint — caught by the catch block below and
           reported as a deferred fixup, pointing the curator at the
           migration. */
        $db->begin_transaction();
        try {
            $parentStmt = $db->prepare('UPDATE tblSongs SET SongId = ? WHERE SongId = ?');
            foreach ($proposed as $oldId => $newId) {
                $parentStmt->bind_param('ss', $newId, $oldId);
                $parentStmt->execute();
                if ($parentStmt->affected_rows > 0) {
                    $result['rewritten']++;
                }
            }
            $parentStmt->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            $result['error']    = 'rewrite failed (FK cascade likely not yet applied) — run migrate-songid-prefix-fixup.php: ' . $e->getMessage();
            $result['deferred'] = true;
            error_log('[songbook_maint] rewrite failed — rolled back: ' . $e->getMessage()
                    . ' — run /manage/setup-database → Re-prefix SongIds.');
        }
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        error_log('[songbook_maint] probe failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Single entry point for "I just wrote to the songbook catalogue —
 * keep public reads consistent without making the curator click
 * Regenerate buttons."
 *
 * Runs the two follow-ups (cache regen + stale-prefix fixup) in
 * sequence; both are best-effort + their own try/catch — neither
 * will throw out of this function. Returns a structured summary
 * the caller can surface in admin telemetry if it wants to.
 *
 * Timings are logged via error_log when EITHER step takes longer
 * than a soft threshold (200ms cache, 50ms probe) so a curator can
 * spot when the post-write hook is becoming a bottleneck.
 *
 * @return array{
 *   context:        string,      // caller-supplied tag (e.g. 'songbooks.update')
 *   cache_built:    bool,
 *   cache_ms:       int,
 *   stale_count:    int,
 *   rewritten:      int,
 *   deferred:       bool,
 *   conflicts:      list<string>,
 *   fixup_ms:       int,
 * }
 */
function songbookMaintenanceRun(\mysqli $db, string $context): array
{
    $summary = [
        'context'     => $context,
        'cache_built' => false,
        'cache_ms'    => 0,
        'stale_count' => 0,
        'rewritten'   => 0,
        'deferred'    => false,
        'conflicts'   => [],
        'fixup_ms'    => 0,
    ];

    /* Step 1 — cache regen. Already best-effort + logs its own
       failures. We just time it. */
    $t0 = microtime(true);
    $cacheResult = songsCacheRegenerateBestEffort($context);
    $summary['cache_ms']    = (int) ((microtime(true) - $t0) * 1000);
    $summary['cache_built'] = $cacheResult !== null;
    if ($summary['cache_ms'] > 200) {
        error_log(sprintf('[songbook_maint] cache regen slow: %dms (context=%s)', $summary['cache_ms'], $context));
    }

    /* Step 2 — stale-prefix probe-and-fixup. Cheap on a clean DB. */
    $t1                       = microtime(true);
    $fixup                    = songIdPrefixProbeAndFixup($db);
    $summary['fixup_ms']      = (int) ((microtime(true) - $t1) * 1000);
    $summary['stale_count']   = $fixup['stale_count'];
    $summary['rewritten']     = $fixup['rewritten'];
    $summary['deferred']      = $fixup['deferred'];
    $summary['conflicts']     = $fixup['conflicts'];
    if ($summary['fixup_ms'] > 50 || $summary['rewritten'] > 0 || $summary['deferred']) {
        error_log(sprintf(
            '[songbook_maint] prefix-fixup (%s): %d stale, %d rewritten, %d conflicts, deferred=%s, %dms',
            $context,
            $summary['stale_count'],
            $summary['rewritten'],
            count($summary['conflicts']),
            $summary['deferred'] ? 'yes' : 'no',
            $summary['fixup_ms']
        ));
    }

    return $summary;
}
