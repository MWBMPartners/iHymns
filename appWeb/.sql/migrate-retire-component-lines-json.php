<?php

declare(strict_types=1);

/**
 * iHymns — RETIRE the tblSongComponents JSON payload columns (#1235 P4 / C6 — the DROP)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * The final, IRREVERSIBLE step of the #1235 lyric-line cutover: drop the four
 * `tblSongComponents` JSON payload columns now that `tblLyricLines` is the authoritative
 * store (read switch C4, write inversion C5) and they are only a shadow:
 *   - LinesJson      (duplicates tblLyricLines.LineText)
 *   - ChordsJson     (mirrored per line into tblLyricLines.ChordsJson)
 *   - NotesJson      (mirrored per line into tblLyricLines.Note)
 *   - LanguagesJson  (folds into tblLyricLines.LanguageCode)
 *
 * ⚠ DESTRUCTIVE. Do NOT run from "Apply all pending". alpha / beta / production SHARE ONE MySQL
 * database, so this is a SINGLE in-place drop on that shared DB — NOT a per-environment operation
 * (a re-run is an idempotent no-op). Run it ONCE, BY HAND, only after ALL of: the drop-safe C4/C5
 * code is live on alpha AND beta AND production (every env reads/writes these columns, so a
 * lagging env breaks the instant they're gone); a ≥7-night soak is green; and all three UIs are
 * paused inside a #1234 maintenance freeze (each env has its OWN maintenance_mode_* flag — freeze
 * all three), with a fresh tested backup. Three reversibility layers protect it:
 *   1. Stage 0 here ABORTS rather than drop on any gate/parity mismatch (below);
 *   2. the Gate-C mysqldump restore (operator runbook);
 *   3. appWeb/.sql/regenerate-lines-json-from-lines.php rebuilds all four columns FROM
 *      tblLyricLines at any time (lines ⊇ the JSON content), so the drop is recoverable.
 *
 * Stage 0 — abort-don't-drop. The DROP only proceeds when ALL hold (else it REFUSES,
 * leaving every column in place — a clean no-op skip, exit 0, so a stray Apply-all run is
 * harmless):
 *   (a) the C3 verifier sentinel tblAppSettings['lyrics_cutover_gate'] says
 *       phase='pre-drop', result='green', written < 24h ago. That run is the
 *       NINE-gate byte-parity proof — G1, G2, G3, G5, G6, G7, G8, G9, G10, incl.
 *       ChordsJson (G2 subsumes the design doc's G13). It is NOT the "G1–G13"
 *       this comment used to claim: G11 is enforced below in (b) rather than by
 *       the verifier, while G4′-ordinals and G12 are genuinely unimplemented
 *       (#1618). See appWeb/.sql/verify-lyrics-cutover.php's header. Fixed #1615;
 *   (b) the sentinel's fingerprint counts {songs,components,lines} STILL match the live
 *       corpus (nothing drifted since the gate ran — the freeze should guarantee this);
 *   (c) an INDEPENDENT live structural re-check: every song's mirrored line count equals
 *       the sum of its components' LinesJson array lengths, and no mirrored line has a NULL
 *       ComponentId (the post-drop grouping anchor). Any mismatch ⇒ REFUSE.
 *
 * Stages 1–4 — each column dropped behind a columnExists guard (an unguarded DROP of an
 * already-missing column throws under MYSQLI_REPORT_STRICT), so the migration is idempotent
 * and re-runnable: a partial apply completes on re-run; a full apply reports "already retired".
 *
 * @migration-drops tblSongComponents.LinesJson
 * @migration-drops tblSongComponents.ChordsJson
 * @migration-drops tblSongComponents.NotesJson
 * @migration-drops tblSongComponents.LanguagesJson
 *
 * USAGE:
 *   Web:  /manage/setup-database → "RETIRE tblSongComponents JSON columns (#1235 P4/C6)"
 *   CLI:  php appWeb/.sql/migrate-retire-component-lines-json.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migRetire_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Column-existence probe (idempotency + the Stage 1–4 guards). */
function _migRetire_colExists(\mysqli $db, string $table, string $col): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migRetire_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migRetire_out('=== iHymns — RETIRE tblSongComponents JSON payload columns (#1235 P4 / C6) ===');

/* Columns to retire, dropped LinesJson-FIRST: its absence is the canonical "C6 has begun ⇒
   retired era" signal that the ADD-column migrations' resurrection guards key on (so even a
   PARTIAL drop can't let "Apply all pending" re-add ChordsJson/NotesJson/LanguagesJson). Data
   safety is independent of order — tblLyricLines is authoritative; these are only a shadow. */
const MIG_RETIRE_COLS = ['LinesJson', 'ChordsJson', 'NotesJson', 'LanguagesJson'];

try {
    /* --- Idempotency: nothing left to drop? --- */
    $present = array_values(array_filter(
        MIG_RETIRE_COLS,
        static fn(string $c): bool => _migRetire_colExists($db, 'tblSongComponents', $c)
    ));
    if (empty($present)) {
        _migRetire_out('  [SKIP] All four JSON payload columns already retired — nothing to do.');
        _migRetire_out('Done (already retired).');
        return;
    }

    /* ================= STAGE 0 — abort-don't-drop gate ================= */
    _migRetire_out('--- Stage 0: cutover-gate + live-parity verification (abort-don\'t-drop) ---');

    /* (0) Explicit confirm gate (defense-in-depth, like drop-legacy). A WEB run MUST carry
       ?confirm=1 — so the irreversible drop can NEVER be triggered by an "Apply all" bulk
       fetch (which sends no confirm), independent of the registry 'manual' exclusion. A CLI
       run is already a deliberate operator action. */
    if (!$isCli && (($_GET['confirm'] ?? '') !== '1')) {
        _migRetire_out('  [REFUSE] this destructive drop needs explicit confirmation — re-run with &confirm=1 (it is intentionally excluded from "Apply all").');
        return;
    }

    /* (a) The C3 verifier sentinel: phase=pre-drop, green, < 24h old. */
    $sres = $db->query(
        "SELECT SettingValue, (UpdatedAt > (NOW() - INTERVAL 24 HOUR)) AS fresh
           FROM tblAppSettings WHERE SettingKey = 'lyrics_cutover_gate' LIMIT 1"
    );
    $srow = $sres ? $sres->fetch_assoc() : null;
    if ($sres) { $sres->close(); }
    if ($srow === null) {
        _migRetire_out('  [REFUSE] No lyrics_cutover_gate sentinel. Run inside the freeze first:');
        _migRetire_out('           Setup-Database → "Verify Lyrics-Cutover Gate" card → Run --phase=pre-drop');
        _migRetire_out('           (CLI alt: php appWeb/.sql/verify-lyrics-cutover.php --phase=pre-drop)');
        return;
    }
    $gate = json_decode((string)$srow['SettingValue'], true);
    if (!is_array($gate)) {
        _migRetire_out('  [REFUSE] lyrics_cutover_gate sentinel is unreadable JSON.');
        return;
    }
    if (($gate['phase'] ?? null) !== 'pre-drop' || ($gate['result'] ?? null) !== 'green') {
        _migRetire_out('  [REFUSE] sentinel is not phase=pre-drop / result=green (got phase='
            . json_encode($gate['phase'] ?? null) . ', result=' . json_encode($gate['result'] ?? null) . ').');
        _migRetire_out('           Re-run the "Verify Lyrics-Cutover Gate" card → --phase=pre-drop (CLI alt: php appWeb/.sql/verify-lyrics-cutover.php --phase=pre-drop)');
        return;
    }
    if ((int)$srow['fresh'] !== 1) {
        _migRetire_out('  [REFUSE] sentinel is older than 24h — re-run the pre-drop verify inside the freeze.');
        return;
    }
    $fp = is_array($gate['fingerprint'] ?? null) ? $gate['fingerprint'] : [];
    _migRetire_out('  [OK] sentinel: phase=pre-drop, green, < 24h, fingerprint '
        . json_encode($fp, JSON_UNESCAPED_UNICODE));

    /* (b) Fingerprint counts STILL match the live corpus (no drift since the gate ran). */
    $liveSongs = (int)$db->query("SELECT COUNT(*) FROM tblSongs")->fetch_row()[0];
    $liveComps = (int)$db->query("SELECT COUNT(*) FROM tblSongComponents")->fetch_row()[0];
    $liveLines = (int)$db->query(
        "SELECT COUNT(*) FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId WHERE ly.Source = 'ihymns'"
    )->fetch_row()[0];
    foreach (['songs' => $liveSongs, 'components' => $liveComps, 'lines' => $liveLines] as $k => $live) {
        if (!isset($fp[$k]) || (int)$fp[$k] !== $live) {
            _migRetire_out("  [REFUSE] corpus drifted since the gate: {$k} sentinel="
                . json_encode($fp[$k] ?? null) . " live={$live}. Re-run the pre-drop verify.");
            return;
        }
    }
    _migRetire_out("  [OK] live counts match the sentinel (songs={$liveSongs}, components={$liveComps}, lines={$liveLines}).");

    /* (c) Independent live structural parity — per-song mirrored-line count == sum of
       LinesJson array lengths. Guard LinesJson presence (it is still here at this point). */
    if (_migRetire_colExists($db, 'tblSongComponents', 'LinesJson')) {
        $pr = $db->query(
            "SELECT j.SongId
               FROM (SELECT SongId, COALESCE(SUM(JSON_LENGTH(LinesJson)), 0) AS jsonLines
                       FROM tblSongComponents GROUP BY SongId) j
               LEFT JOIN (SELECT ly.SongId, COUNT(*) AS mirrorLines
                            FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId
                           WHERE ly.Source = 'ihymns' GROUP BY ly.SongId) m
                 ON m.SongId = j.SongId
              WHERE COALESCE(m.mirrorLines, 0) <> j.jsonLines
              LIMIT 50"
        );
        $drift = [];
        while ($row = $pr->fetch_row()) { $drift[] = (string)$row[0]; }
        $pr->close();
        if (!empty($drift)) {
            _migRetire_out('  [REFUSE] live line-count parity FAILED for ' . count($drift)
                . '+ song(s) (LinesJson vs tblLyricLines), e.g. ' . implode(', ', array_slice($drift, 0, 10)) . '.');
            _migRetire_out('           The mirror is NOT a complete superset — do NOT drop. Re-project + re-verify.');
            return;
        }
        _migRetire_out('  [OK] live line-count parity: every song\'s tblLyricLines == its LinesJson length.');
    }

    /* (c2) No mirrored line may have a NULL ComponentId — it is the post-drop grouping anchor. */
    $nc = (int)$db->query(
        "SELECT COUNT(*) FROM tblLyricLines ll JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.Source = 'ihymns' AND ll.ComponentId IS NULL"
    )->fetch_row()[0];
    if ($nc > 0) {
        _migRetire_out("  [REFUSE] {$nc} mirrored line(s) have a NULL ComponentId (the post-drop grouping anchor). Do NOT drop.");
        return;
    }
    _migRetire_out('  [OK] 0 NULL ComponentId on mirrored lines.');

    _migRetire_out('  ✔ Gate satisfied — proceeding to drop.');

    /* ================= STAGES 1–4 — guarded DROP COLUMN ================= */
    foreach (MIG_RETIRE_COLS as $i => $col) {
        $stage = $i + 1;
        if (_migRetire_colExists($db, 'tblSongComponents', $col)) {
            $db->query("ALTER TABLE tblSongComponents DROP COLUMN `{$col}`");
            _migRetire_out("  [OK] Stage {$stage}: dropped tblSongComponents.{$col}.");
        } else {
            _migRetire_out("  [SKIP] Stage {$stage}: tblSongComponents.{$col} already gone.");
        }
    }

    _migRetire_out('Done. tblSongComponents JSON payload columns retired — tblLyricLines is now the sole store.');
    _migRetire_out('Recovery (if ever needed): php appWeb/.sql/regenerate-lines-json-from-lines.php');
} catch (\Throwable $e) {
    _migRetire_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;
